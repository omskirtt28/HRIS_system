<?php
declare(strict_types=1);

/**
 * Accounting Phase 3 (B1 dashboard, B2 ledger, B6 fixed assets / migration 048) against a SCRATCH copy:
 *   HRIS_TEST_DB=hris_slice15_test_... C:\xampp\php\php.exe tests\accounting_phase3_db_test.php
 * Refuses the live database. Every row this test creates is deleted at the end.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if(!preg_match('/^hris_slice15_test_\d{8}_\d{6}$/',$dbName)) { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy (hris_slice15_test_...).\n"); exit(2); }
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
foreach(['Accounting','AccountsPayable','AccountsReceivable','CashBank','Remittances','HrDashboard','AccountingDashboard','FixedAssets'] as $c) require_once dirname(__DIR__)."/app/$c.php";
$GLOBALS['pdo']=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(db()->query('SELECT DATABASE()')->fetchColumn()!==$dbName) exit(2);
if(!Accounting::ready() || !FixedAssets::ready()) { fwrite(STDERR,"Run migrations 039 to 048 on the scratch copy first.\n"); exit(2); }

$fail=0; $pass=0;
function check(string $label,bool $ok,string $why=''): void { global $fail,$pass; echo ($ok?'PASS ':'FAIL ').$label.($ok||$why===''?'':'  ['.$why.']')."\n"; $ok?$pass++:$fail++; }
function err(callable $f): ?string { try { $f(); return null; } catch(RuntimeException $e) { return $e->getMessage(); } }

$bk=(int)db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
db()->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT role_id,?,?,? FROM users WHERE id=?')->execute(['Phase3 Test Approver','acct-p3-approver@example.invalid','x',$bk]);
$fh=(int)db()->lastInsertId();
$_SESSION['user']=['id'=>$bk,'role_code'=>'SUPER_ADMIN','role_portal'=>'admin'];
$acc=fn(string $code)=>(int)db()->query('SELECT id FROM acct_accounts WHERE code='.db()->quote($code))->fetchColumn();
$startId=(int)db()->query('SELECT COALESCE(MAX(id),0) FROM acct_journal_entries')->fetchColumn();
$post=function(int $id) use($bk,$fh) { Accounting::submit($id); $_SESSION['user']['id']=$fh; Accounting::approve($id,$fh); $_SESSION['user']['id']=$bk; };
// Use a past year far from real data so the ledger and dashboard figures are exact.
$y=(int)date('Y')-3; $d=fn(int $m,int $day)=>sprintf('%04d-%02d-%02d',$y,$m,$day);

try {
    // B2 ledger: opening + running balance on 1020 (debit-normal) and 4010 (credit-normal).
    $e1=Accounting::saveDraft(0,$d(1,10),'P3 sale',[['account_id'=>$acc('1020'),'debit'=>'1000'],['account_id'=>$acc('4010'),'credit'=>'1000']],$bk); $post($e1);
    $e2=Accounting::saveDraft(0,$d(2,5),'P3 rent',[['account_id'=>$acc('5100'),'debit'=>'300'],['account_id'=>$acc('1020'),'credit'=>'300']],$bk); $post($e2);
    $e3=Accounting::saveDraft(0,$d(2,20),'P3 draft only',[['account_id'=>$acc('5100'),'debit'=>'50'],['account_id'=>$acc('1020'),'credit'=>'50']],$bk);
    $before=Accounting::ledger($acc('1020'),'1000-01-01',$d(1,9))['closing'];
    $l=Accounting::ledger($acc('1020'),$d(2,1),$d(2,28));
    check('ledger opening = balance before the range',$l['opening']===$before+100000,(string)$l['opening']);
    check('ledger lists posted lines only (draft excluded)',count($l['rows'])===1 && (int)$l['rows'][0]['entry_id']===$e2);
    check('ledger running balance and closing',$l['rows'][0]['balance']===$l['opening']-30000 && $l['closing']===$l['opening']-30000);
    $li=Accounting::ledger($acc('4010'),$d(1,1),$d(1,31));
    check('credit-normal account shows a positive balance',$li['rows'] && end($li['rows'])['balance']-$li['opening']===100000);
    check('ledger of a missing account is null',Accounting::ledger(999999999,$d(1,1),$d(1,31))===null);

    // B1 dashboard series.
    $p=['mode'=>'monthly','year'=>$y,'periods'=>[1,2]];
    $s=AccountingDashboard::series($p,date('Y-m-d'));
    check('dashboard monthly labels are full month names',array_column($s,'label')===['January','February']);
    $is1=Accounting::incomeStatement($d(1,1),$d(1,31));
    check('dashboard period figures equal the income statement',$s[0]['income']===$is1['total_income'] && $s[0]['net']===$is1['net']);
    $q=AccountingDashboard::series(['mode'=>'quarterly','year'=>$y,'periods'=>[1]],date('Y-m-d'));
    check('quarterly label Q1 spans three months',$q[0]['label']==='Q1' && $q[0]['from']===$d(1,1) && $q[0]['to']===$d(3,31));
    $f=AccountingDashboard::series(['mode'=>'yearly','year'=>(int)date('Y'),'periods'=>[(int)date('Y')+1]],date('Y-m-d'));
    check('future periods are left out',$f===[]);
    $cur=AccountingDashboard::series(['mode'=>'monthly','year'=>(int)date('Y'),'periods'=>[(int)date('n')]],date('Y-m-d'));
    check('running period ends today',$cur[0]['to']===date('Y-m-d'));
    $k=AccountingDashboard::kpis(date('Y-m-d'));
    check('kpis return cash, AR, AP and pending',isset($k['cash'],$k['ar'],$k['ap'],$k['pending']) && $k['pending']>=0);
    check('bar svg has a labelled role=img and both series',str_contains($svg=accounting_bar_svg($s),'role="img"') && substr_count($svg,'ac-s-inc')===2 && str_contains($svg,'January'));

    // B6 fixed assets: pure amount rule.
    check('straight line monthly amount',FixedAssets::amountFor(100000,3,0,0)===33333);
    check('last month takes the remainder',FixedAssets::amountFor(100000,3,66666,2)===33334);
    check('nothing after fully depreciated',FixedAssets::amountFor(100000,3,100000,3)===0);

    $base=['name'=>'P3 Laptop','acquired_on'=>$d(3,15),'cost'=>'1,000.00','salvage'=>'0','life_months'=>'3',
        'asset_account_id'=>$acc('1500'),'accum_account_id'=>$acc('1510'),'expense_account_id'=>$acc('5130')];
    check('salvage must be below cost',str_contains((string)err(fn()=>FixedAssets::save(0,['salvage'=>'1000']+$base,$bk)),'salvage'));
    check('life must be 1 to 600',str_contains((string)err(fn()=>FixedAssets::save(0,['life_months'=>'0']+$base,$bk)),'1 to 600'));
    check('expense account must be Expense type',str_contains((string)err(fn()=>FixedAssets::save(0,['expense_account_id'=>$acc('1020')]+$base,$bk)),'Choose active accounts'));
    check('asset and accumulated accounts differ',str_contains((string)err(fn()=>FixedAssets::save(0,['accum_account_id'=>$acc('1500')]+$base,$bk)),'must be different'));
    $a=FixedAssets::save(0,$base,$bk);
    check('asset number FA-YYYY-NNNN',(bool)preg_match('/^FA-'.$y.'-\d{4}$/',FixedAssets::asset($a)['asset_no']));
    $m=fn(int $mm)=>sprintf('%04d-%02d',$y,$mm);
    check('no depreciation in the acquisition month',str_contains((string)err(fn()=>FixedAssets::run($m(3),$bk)),'Nothing to depreciate'));
    $r1=FixedAssets::run($m(4),$bk); $e=Accounting::entry($r1);
    check('run creates a balanced draft dated month end',$e['status']==='DRAFT' && $e['entry_date']===$d(4,30) && $e['debit']===33333 && $e['credit']===33333);
    check('run lines: Dr expense / Cr accumulated',(int)$e['lines'][0]['account_id']===$acc('5130') && (int)$e['lines'][1]['account_id']===$acc('1510'));
    check('same month cannot run twice',str_contains((string)err(fn()=>FixedAssets::run($m(4),$bk)),'Nothing to depreciate'));
    check('depreciation entry cannot be edited as a journal',str_contains((string)err(fn()=>Accounting::saveDraft($r1,$d(4,30),'x',[['account_id'=>$acc('5130'),'debit'=>'1'],['account_id'=>$acc('1510'),'credit'=>'1']],$bk)),'depreciation run'));
    check('entryMonth links the entry to its month',FixedAssets::entryMonth($r1)===$m(4).'-01' && FixedAssets::entryMonth($e1)===null);
    check('cost locked once depreciation exists',str_contains((string)err(fn()=>FixedAssets::save($a,['cost'=>'2000']+$base,$bk)),'only the name'));
    check('name still editable',FixedAssets::save($a,['name'=>'P3 Laptop renamed']+$base,$bk)===$a && FixedAssets::asset($a)['name']==='P3 Laptop renamed');
    Accounting::void($r1,'test void',$bk);
    check('voided run frees the month',($r1b=FixedAssets::run($m(4),$bk))>0 && (int)FixedAssets::asset($a)['months_run']===1);
    $post($r1b);
    $r2=FixedAssets::run($m(5),$bk); $post($r2);
    check('disposal before recorded depreciation refused',str_contains((string)err(fn()=>FixedAssets::save($a,['name'=>'P3 Laptop renamed','disposed_on'=>$d(4,10)]+$base,$bk)),'after the disposal month'));
    $r3=FixedAssets::run($m(6),$bk);
    check('last month amount makes the total exact',Accounting::entry($r3)['debit']===33334 && (int)round(FixedAssets::asset($a)['depreciated']*100)===100000);
    check('fully depreciated asset is skipped',str_contains((string)err(fn()=>FixedAssets::run($m(7),$bk)),'Nothing to depreciate'));
    check('future month refused',str_contains((string)err(fn()=>FixedAssets::run(date('Y-m',strtotime('first day of +1 month')),$bk)),'future'));
    check('runs list shows the entries',count(array_filter(FixedAssets::runs(),fn($r)=>in_array((int)$r['entry_id'],[$r1,$r1b,$r2,$r3],true)))===4);
    $b2=FixedAssets::save(0,['name'=>'P3 Printer','disposed_on'=>$d(4,20)]+$base,$bk);
    $r4=FixedAssets::run($m(4),$bk);
    check('a new asset runs for a month the other asset already has',array_map('intval',db()->query("SELECT asset_id FROM acct_depreciation WHERE entry_id=$r4")->fetchAll(PDO::FETCH_COLUMN))===[$b2]);
    check('disposed asset stops after its disposal month',str_contains((string)err(fn()=>FixedAssets::run($m(5),$bk)),'Nothing to depreciate'));
} catch(Throwable $x) { check('no unexpected error',false,$x->getMessage()); }
finally {
    db()->exec("DELETE FROM acct_depreciation WHERE asset_id IN (SELECT id FROM acct_assets WHERE name LIKE 'P3 %')");
    db()->exec("DELETE FROM acct_assets WHERE name LIKE 'P3 %'");
    db()->exec("DELETE l FROM acct_journal_lines l JOIN acct_journal_entries e ON e.id=l.entry_id WHERE e.id>$startId");
    db()->exec("UPDATE acct_journal_entries SET reverses_entry_id=NULL WHERE id>$startId");
    db()->exec("DELETE FROM acct_journal_entries WHERE id>$startId");
    db()->prepare("DELETE FROM audit_logs WHERE user_id=?")->execute([$fh]);
    db()->prepare('DELETE FROM users WHERE id=?')->execute([$fh]);
}
echo "RESULT $pass passed, $fail failed\n";
exit($fail?1:0);
