<?php
declare(strict_types=1);

/**
 * Accounting slice A4 (migration 044: SSS / PhilHealth / Pag-IBIG / BIR remittances) against a SCRATCH copy of the database:
 *   HRIS_TEST_DB=hris_slice15_test_... C:\xampp\php\php.exe tests\remittances_db_test.php
 * Refuses the live database. Every row this test creates is deleted at the end.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if($dbName==='' || in_array($dbName,['pmbsi_hris','hris_clean'],true)) { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy.\n"); exit(2); }
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/Remittances.php';
$GLOBALS['pdo']=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(db()->query('SELECT DATABASE()')->fetchColumn()!==$dbName) exit(2);
if(!Accounting::ready()) { fwrite(STDERR,"Run migrations 039 to 044 on the scratch copy first.\n"); exit(2); }

$fail=0;
function check(string $label,bool $ok,string $why=''): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label.($ok||$why===''?'':'  ['.$why.']')."\n"; if(!$ok) $fail++; }
function err(callable $f): ?string { try { $f(); return null; } catch(RuntimeException $e) { return $e->getMessage(); } }

$bk=(int)db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
db()->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT role_id,?,?,? FROM users WHERE id=?')->execute(['Remit Test Approver','remit-test-approver@example.invalid','x',$bk]);
$fh=(int)db()->lastInsertId();
$_SESSION['user']=['id'=>$bk,'role_code'=>'SUPER_ADMIN','role_portal'=>'admin'];
$acc=fn(string $code)=>(int)db()->query('SELECT id FROM acct_accounts WHERE code='.db()->quote($code))->fetchColumn();
$startId=(int)db()->query('SELECT COALESCE(MAX(id),0) FROM acct_journal_entries')->fetchColumn();
$sfx=bin2hex(random_bytes(3)); $n="zz-remit-test-$sfx";
$post=function(int $entry) use($fh){ Accounting::submit($entry); Accounting::approve($entry,$fh); };

try {
    $gl=Accounting::saveAccount(0,"ZZ1-$sfx","$n cash",'A',true);
    $bank=CashBank::saveBank(0,$n,'','',$gl,true);
    $off=CashBank::saveBank(0,"$n-off",'','',Accounting::saveAccount(0,"ZZ2-$sfx","$n cash 2",'A',true),false);
    $sss=$acc('2100'); $ph=$acc('2110');
    $save=fn(array $o=[])=>Remittances::save($o['id']??0,$o['agency']??'SSS',$o['period']??'2001-03',$o['bank']??$bank,$o['date']??'2001-04-10',$o['ref']??"PRN-$sfx",$o['memo']??'',$o['rows']??[['account_id'=>$sss,'amount'=>'1,500.50']],$bk);

    // ---- Validation ----
    check('default account for SSS is 2100',Remittances::defaultAccount('SSS')===$sss);
    check('unknown agency refused',str_contains((string)err(fn()=>$save(['agency'=>'GSIS'])),'agency'));
    check('bad period refused',str_contains((string)err(fn()=>$save(['period'=>'2001-13'])),'period'));
    check('period after the payment month refused',str_contains((string)err(fn()=>$save(['period'=>'2001-05'])),'cannot be after'));
    check('reference is required',str_contains((string)err(fn()=>$save(['ref'=>' '])),'reference'));
    check('inactive bank refused',str_contains((string)err(fn()=>$save(['bank'=>$off])),'active bank'));
    check('Accounts Payable line refused',str_contains((string)err(fn()=>$save(['rows'=>[['account_id'=>$acc('2010'),'amount'=>'5']]])),'payable'));
    check('expense line refused',str_contains((string)err(fn()=>$save(['rows'=>[['account_id'=>$acc('5010'),'amount'=>'5']]])),'payable'));
    check('no lines refused',str_contains((string)err(fn()=>$save(['rows'=>[]])),'at least one line'));

    // ---- Save: one balanced draft JE, Dr payable / Cr bank ----
    $id=$save(['rows'=>[['account_id'=>$sss,'amount'=>'1,000','description'=>'EE'],['account_id'=>$sss,'amount'=>'500.50','description'=>'ER']]]);
    $r=Remittances::remittance($id); $e=Accounting::entry((int)$r['entry_id']);
    check('numbered RMT-2001-0001 and DRAFT',$r['remittance_no']==='RMT-2001-0001' && $r['status']==='DRAFT' && $r['period_month']==='2001-03-01');
    check('entry: 2 payable debits and one bank credit, balanced',count($e['lines'])===3 && $e['debit']===150050 && $e['credit']===150050 && (int)end($e['lines'])['account_id']===$gl);
    check('entry links back to the remittance',(int)$e['remittance_id']===$id);
    check('raw journal edit of its entry refused',str_contains((string)err(fn()=>Accounting::saveDraft((int)$r['entry_id'],'2001-04-10','x',[['account_id'=>$sss,'debit'=>'1'],['account_id'=>$gl,'credit'=>'1']],$bk)),'remittance'));
    check('same agency + reference refused',str_contains((string)err(fn()=>$save()),'already recorded'));
    $ph1=$save(['agency'=>'PHILHEALTH','rows'=>[['account_id'=>$ph,'amount'=>'200']]]);
    check('same reference for another agency allowed, next number',Remittances::remittance($ph1)['remittance_no']==='RMT-2001-0002');

    // ---- Edit draft ----
    $save(['id'=>$id,'rows'=>[['account_id'=>$sss,'amount'=>'1,200']]]);
    $r=Remittances::remittance($id);
    check('edit replaces lines and total',(float)$r['total']===1200.0 && count($r['lines'])===1 && Accounting::entry((int)$r['entry_id'])['credit']===120000);

    // ---- Grid counts posted only, not reversed ----
    check('draft not in the grid',Remittances::yearGrid(2001)[3]['SSS']===0);
    $post((int)$r['entry_id']);
    $grid=Remittances::yearGrid(2001);
    check('posted SSS shows in March',$grid[3]['SSS']===120000 && $grid[3]['PHILHEALTH']===0);
    $march=fn()=>array_values(array_filter(Accounting::periods(),fn($p)=>$p['month']==='2001-03-01'))[0] ?? [];
    check('close checklist counts 1 of 4 agencies for March',($march()['remit_done']??-1)===1 && ($march()['remit_total']??0)===4);
    check('posted remittance cannot be edited',str_contains((string)err(fn()=>$save(['id'=>$id])),'Only draft'));
    $rev=Accounting::reverse((int)$r['entry_id'],'2001-04-20',$bk); $post($rev);
    check('reversed remittance leaves the grid',Remittances::yearGrid(2001)[3]['SSS']===0);
    check('reversed remittance leaves the close checklist',($march()['remit_done']??0)===0);

    // ---- Void frees the reference ----
    Accounting::void((int)Remittances::remittance($ph1)['entry_id'],'test',$bk);
    check('voided remittance frees its reference',$save(['agency'=>'PHILHEALTH','rows'=>[['account_id'=>$ph,'amount'=>'200']]])>0);
    check('status counts per remittance',Remittances::statusCounts()['VOID']>=1 && Remittances::statusCounts()['POSTED']>=1);
} finally {
    db()->exec('DELETE FROM acct_remittances WHERE entry_id>'.$startId);
    db()->exec('UPDATE acct_journal_entries SET reverses_entry_id=NULL WHERE id>'.$startId);
    db()->exec('DELETE FROM acct_journal_lines WHERE entry_id>'.$startId);
    db()->exec('DELETE FROM acct_journal_entries WHERE id>'.$startId);
    db()->exec("DELETE FROM acct_bank_accounts WHERE name LIKE 'zz-remit-test-%'");
    db()->exec("DELETE FROM acct_accounts WHERE code LIKE 'ZZ_-$sfx'");
    db()->exec("DELETE FROM audit_logs WHERE module='accounting'");
    db()->prepare('DELETE FROM users WHERE id=?')->execute([$fh]);
}
echo $fail?"\n$fail FAIL\n":"\nALL PASS\n"; exit($fail?1:0);
