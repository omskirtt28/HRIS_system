<?php
declare(strict_types=1);

/**
 * Accounting Phase 1 (migration 039) against a SCRATCH copy of the database:
 *   HRIS_TEST_DB=hris_slice15_test_... C:\xampp\php\php.exe tests\accounting_db_test.php
 * Refuses the live database. The service commits its own transactions, so every row this test creates is deleted at the end.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if($dbName==='' || $dbName==='pmbsi_hris') { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/Accounting.php';
$GLOBALS['pdo']=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(db()->query('SELECT DATABASE()')->fetchColumn()!==$dbName) exit(2);
if(!Accounting::ready()) { fwrite(STDERR,"Run migration 039 on the scratch copy first.\n"); exit(2); }

$fail=0;
function check(string $label,bool $ok,string $why=''): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label.($ok||$why===''?'':'  ['.$why.']')."\n"; if(!$ok) $fail++; }
function err(callable $f): ?string { try { $f(); return null; } catch(RuntimeException $e) { return $e->getMessage(); } }

$bk=(int)db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
// Second person for segregation of duties; removed in finally.
db()->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT role_id,?,?,? FROM users WHERE id=?')->execute(['Accounting Test Approver','acct-test-approver@example.invalid','x',$bk]);
$fh=(int)db()->lastInsertId();
$_SESSION['user']=['id'=>$bk,'role_code'=>'SUPER_ADMIN','role_portal'=>'admin'];
$acc=fn(string $code)=>(int)db()->query('SELECT id FROM acct_accounts WHERE code='.db()->quote($code))->fetchColumn();
$startId=(int)db()->query('SELECT COALESCE(MAX(id),0) FROM acct_journal_entries')->fetchColumn();
$month=date('Y-m-01',strtotime('first day of -2 month')); $day=substr($month,0,8).'15';
$tbBefore=Accounting::trialBalance('2999-12-31');

try {
    check('seeded chart has 27 accounts',(int)db()->query('SELECT COUNT(*) FROM acct_accounts')->fetchColumn()>=27 && $acc('1020')>0 && $acc('5100')>0);
    check('permissions exist and Admin has them',(int)db()->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.code='HRIS_ADMIN' AND p.code LIKE 'accounting.%'")->fetchColumn()===3);

    $rent=[['account_id'=>$acc('5100'),'debit'=>'20,000'],['account_id'=>$acc('1020'),'credit'=>'15000']];
    $id=Accounting::saveDraft(0,$day,'Rent',$rent,$bk);
    check('unbalanced draft can be saved',$id>0 && Accounting::entry($id)['status']==='DRAFT');
    check('entry number JE-YYYY-NNNN',(bool)preg_match('/^JE-\d{4}-\d{4}$/',Accounting::entry($id)['entry_no']));
    check('submit refuses unbalanced',str_contains((string)err(fn()=>Accounting::submit($id)),'must be equal'));
    check('line with both debit and credit refused',str_contains((string)err(fn()=>Accounting::saveDraft($id,$day,'Rent',[['account_id'=>$acc('5100'),'debit'=>'1','credit'=>'1']],$bk)),'either a debit or a credit'));
    check('unknown account refused',str_contains((string)err(fn()=>Accounting::saveDraft($id,$day,'Rent',[['account_id'=>999999,'debit'=>'1']],$bk)),'active account'));
    check('bad date refused',str_contains((string)err(fn()=>Accounting::saveDraft($id,'2026-02-30','Rent',$rent,$bk)),'valid entry date'));
    $rent[1]['credit']='20000.00';
    Accounting::saveDraft($id,$day,'Rent',$rent,$bk);
    check('draft edit replaces lines',count(Accounting::entry($id)['lines'])===2 && Accounting::entry($id)['credit']===2000000);
    Accounting::submit($id);
    check('balanced draft submits',Accounting::entry($id)['status']==='FOR_APPROVAL');
    check('submitted entry cannot be edited',str_contains((string)err(fn()=>Accounting::saveDraft($id,$day,'Rent',$rent,$bk)),'Only draft'));
    check('preparer cannot approve own entry',str_contains((string)err(fn()=>Accounting::approve($id,$bk)),'another approver'));
    check('return needs a reason',str_contains((string)err(fn()=>Accounting::returnToDraft($id,' ')),'reason'));
    Accounting::returnToDraft($id,'Wrong bank');
    check('returned entry is a draft with the note',Accounting::entry($id)['status']==='DRAFT' && Accounting::entry($id)['return_note']==='Wrong bank');
    Accounting::submit($id);
    check('resubmit clears the note',Accounting::entry($id)['return_note']===null);
    Accounting::approve($id,$fh);
    $e=Accounting::entry($id);
    check('other approver posts',$e['status']==='POSTED' && (int)$e['approved_by_user_id']===$fh && $e['posted_at']!==null);
    check('posted entry cannot be approved twice',str_contains((string)err(fn()=>Accounting::approve($id,$fh)),'already posted'));

    $tb=Accounting::trialBalance('2999-12-31');
    check('trial balance balanced and moved by 20,000',$tb['debit']===$tb['credit'] && $tb['debit']-$tbBefore['debit']>=2000000);
    $is=Accounting::incomeStatement($month,date('Y-m-t',strtotime($month)));
    check('income statement shows the rent expense',in_array('5100',array_column($is['expense'],'code'),true) && $is['total_expense']>=2000000);
    $bs=Accounting::balanceSheet('2999-12-31');
    check('balance sheet balances (current earnings)',$bs['total_assets']===$bs['total_liabilities']+$bs['total_equity']);
    check('drafts are not in reports',(function()use($bk,$day,$acc){ $before=Accounting::trialBalance('2999-12-31')['debit']; Accounting::saveDraft(0,$day,'Pending',[['account_id'=>$acc('5110'),'debit'=>'5'],['account_id'=>$acc('1010'),'credit'=>'5']],$bk); return Accounting::trialBalance('2999-12-31')['debit']===$before; })());

    check('cannot close a month with pending entries',str_contains((string)err(fn()=>Accounting::closeMonth($month,$fh)),'before closing'));
    db()->exec('DELETE l FROM acct_journal_lines l JOIN acct_journal_entries e ON e.id=l.entry_id WHERE e.id>'.$startId." AND e.status='DRAFT'");
    db()->exec('DELETE FROM acct_journal_entries WHERE id>'.$startId." AND status='DRAFT'");
    check('future month cannot be closed',str_contains((string)err(fn()=>Accounting::closeMonth(date('Y-m-01',strtotime('first day of +1 month')),$fh)),'future'));
    Accounting::closeMonth($month,$fh);
    check('closed month blocks new drafts',str_contains((string)err(fn()=>Accounting::saveDraft(0,$day,'Late',$rent,$bk)),'is closed'));
    check('closed month blocks reversal dated in it',str_contains((string)err(fn()=>Accounting::reverse($id,$day,$fh)),'is closed'));
    Accounting::reopenMonth($month);
    check('reopen twice refused',str_contains((string)err(fn()=>Accounting::reopenMonth($month)),'not closed'));

    check('reversal before original date refused',str_contains((string)err(fn()=>Accounting::reverse($id,date('Y-m-d',strtotime($day.' -1 day')),$fh)),'cannot be before'));
    $rev=Accounting::reverse($id,date('Y-m-d'),$fh);
    $r=Accounting::entry($rev);
    check('reversal is a draft with swapped lines',$r['status']==='DRAFT' && (int)$r['reverses_entry_id']===$id && (float)$r['lines'][0]['credit']===20000.0 && (float)$r['lines'][1]['debit']===20000.0);
    check('second reversal refused',str_contains((string)err(fn()=>Accounting::reverse($id,date('Y-m-d'),$fh)),'already has a reversing'));

    // ---- Void (migration 040) ----
    check('void needs a reason',str_contains((string)err(fn()=>Accounting::void($rev,' ',$bk)),'reason'));
    check('posted entry cannot be voided',str_contains((string)err(fn()=>Accounting::void($id,'x',$bk)),'already posted'));
    Accounting::void($rev,'Wrong date',$bk);
    $r=Accounting::entry($rev);
    check('voided draft keeps its number and leaves the reversal link',$r['status']==='VOID' && $r['entry_no']!=='' && $r['void_reason']==='Wrong date' && $r['reverses_entry_id']===null);
    check('voided entry cannot be submitted',str_contains((string)err(fn()=>Accounting::submit($rev)),'already voided'));
    check('voided reversal frees the original to be reversed again',Accounting::reverse($id,date('Y-m-d'),$fh)>0);
    check('voided entries are not pending (month can close)',(function()use($month,$fh){ Accounting::closeMonth($month,$fh); Accounting::reopenMonth($month); return true; })());

    // ---- Year-end closing (migration 040), in an empty past year ----
    $y=2001; $post=function(string $date,array $lines)use($bk,$fh){ $j=Accounting::saveDraft(0,$date,'Close test',$lines,$bk); Accounting::submit($j); Accounting::approve($j,$fh); return $j; };
    check('year with nothing posted refused',str_contains((string)err(fn()=>Accounting::closeYear($y,$acc('3020'),$fh)),'No posted income'));
    check('non-equity close-to account refused',str_contains((string)err(fn()=>Accounting::closeYear($y,$acc('1020'),$fh)),'equity account'));
    $post("$y-06-01",[['account_id'=>$acc('1020'),'debit'=>'1000'],['account_id'=>$acc('4010'),'credit'=>'1000']]);
    $post("$y-06-02",[['account_id'=>$acc('5100'),'debit'=>'300'],['account_id'=>$acc('1020'),'credit'=>'300']]);
    $pending=Accounting::saveDraft(0,"$y-07-01",'Pending',[['account_id'=>$acc('5110'),'debit'=>'5'],['account_id'=>$acc('1010'),'credit'=>'5']],$bk);
    check('year with pending entries refused',str_contains((string)err(fn()=>Accounting::closeYear($y,$acc('3020'),$fh)),'first'));
    Accounting::void($pending,'Not needed',$bk);
    $ce=Accounting::closeYear($y,$acc('3020'),$fh); $c=Accounting::entry($ce);
    $line=fn(string $code)=>array_values(array_filter($c['lines'],fn($l)=>$l['code']===$code))[0]??null;
    check('closing draft zeroes income and expense into equity',$c['status']==='DRAFT' && $c['entry_kind']==='CLOSING' && $c['entry_date']==="$y-12-31"
        && (float)$line('4010')['debit']===1000.0 && (float)$line('5100')['credit']===300.0 && (float)$line('3020')['credit']===700.0 && $c['debit']===$c['credit']);
    check('closing draft cannot be hand-edited',str_contains((string)err(fn()=>Accounting::saveDraft($ce,"$y-12-31",'x',[['account_id'=>$acc('1010'),'debit'=>'1']],$bk)),'closing entry'));
    check('second closing for the same year refused',str_contains((string)err(fn()=>Accounting::closeYear($y,$acc('3020'),$fh)),'already has a closing'));
    Accounting::submit($ce); Accounting::approve($ce,$bk);
    $is=Accounting::incomeStatement("$y-01-01","$y-12-31");
    check('income statement ignores the closing entry',$is['net']===70000 && $is['total_income']===100000);
    $tb=Accounting::trialBalance("$y-12-31"); $left=array_filter($tb['rows'],fn($r)=>in_array($r['type'],['I','X'],true) && ($r['dr'] || $r['cr']));
    check('post-closing trial balance: no income/expense left, balanced',!$left && $tb['debit']===$tb['credit']);
    $bs=Accounting::balanceSheet("$y-12-31");
    check('balance sheet: earnings moved to retained earnings',$bs['earnings']===0 && $bs['total_assets']===$bs['total_liabilities']+$bs['total_equity'] && $bs['total_equity']===70000);
    $rc=Accounting::reverse($ce,"$y-12-31",$fh);
    check('reversal of a closing entry is also a closing entry',Accounting::entry($rc)['entry_kind']==='CLOSING');

    check('account code duplicate refused',str_contains((string)err(fn()=>Accounting::saveAccount(0,'1020','Dup','A',true)),'already used'));
    check('used account type cannot change',str_contains((string)err(fn()=>Accounting::saveAccount($acc('5100'),'5100','Rent Expense','A',true)),'can no longer change'));
    $new=Accounting::saveAccount(0,'9999-T','Test account','X',true);
    check('new account added and renamed',$new>0 && Accounting::saveAccount($new,'9999-T','Test account 2','X',false)===$new);
    db()->prepare('DELETE FROM acct_accounts WHERE id=?')->execute([$new]);
} finally {
    db()->exec('DELETE FROM acct_closed_periods WHERE period_month='.db()->quote($month));
    db()->exec('UPDATE acct_journal_entries SET reverses_entry_id=NULL WHERE id>'.$startId);
    db()->exec('DELETE l FROM acct_journal_lines l WHERE l.entry_id>'.$startId);
    db()->exec('DELETE FROM acct_journal_entries WHERE id>'.$startId);
    db()->exec("DELETE FROM audit_logs WHERE module='accounting'");
    db()->prepare('DELETE FROM users WHERE id=?')->execute([$fh]);
}
echo $fail?"\n$fail FAIL\n":"\nALL PASS\n"; exit($fail?1:0);
