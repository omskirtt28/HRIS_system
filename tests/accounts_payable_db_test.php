<?php
declare(strict_types=1);

/**
 * Accounting slice A1 (migration 041: vendors, bills, AP aging) against a SCRATCH copy of the database:
 *   HRIS_TEST_DB=hris_slice15_test_... C:\xampp\php\php.exe tests\accounts_payable_db_test.php
 * Refuses the live database. Every row this test creates is deleted at the end.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if($dbName==='' || in_array($dbName,['pmbsi_hris','hris_clean'],true)) { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy.\n"); exit(2); }
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/AccountsPayable.php';
$GLOBALS['pdo']=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(db()->query('SELECT DATABASE()')->fetchColumn()!==$dbName) exit(2);
if(!Accounting::ready()) { fwrite(STDERR,"Run migrations 039 to 041 on the scratch copy first.\n"); exit(2); }

$fail=0;
function check(string $label,bool $ok,string $why=''): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label.($ok||$why===''?'':'  ['.$why.']')."\n"; if(!$ok) $fail++; }
function err(callable $f): ?string { try { $f(); return null; } catch(RuntimeException $e) { return $e->getMessage(); } }

$bk=(int)db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
db()->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT role_id,?,?,? FROM users WHERE id=?')->execute(['AP Test Approver','ap-test-approver@example.invalid','x',$bk]);
$fh=(int)db()->lastInsertId();
$_SESSION['user']=['id'=>$bk,'role_code'=>'SUPER_ADMIN','role_portal'=>'admin'];
$acc=fn(string $code)=>(int)db()->query('SELECT id FROM acct_accounts WHERE code='.db()->quote($code))->fetchColumn();
$startId=(int)db()->query('SELECT COALESCE(MAX(id),0) FROM acct_journal_entries')->fetchColumn();
$sfx=bin2hex(random_bytes(3)); $vn="zz-ap-test-$sfx";
$line=fn(string $code,string $amt,string $d='')=>['account_id'=>$acc($code),'description'=>$d,'amount'=>$amt];
$agingOf=function(string $asOf,string $name){ foreach(AccountsPayable::aging($asOf)['rows'] as $r) if($r['vendor']===$name) return $r; return null; };

try {
    // ---- Vendors ----
    check('vendor name required',str_contains((string)err(fn()=>AccountsPayable::saveVendor(0,' ','','',30,true)),'name is required'));
    check('TIN letters refused',str_contains((string)err(fn()=>AccountsPayable::saveVendor(0,$vn,'12A','',30,true)),'TIN'));
    check('terms over 365 refused',str_contains((string)err(fn()=>AccountsPayable::saveVendor(0,$vn,'','',400,true)),'0 to 365'));
    $v=AccountsPayable::saveVendor(0,$vn,'123-456-789-000','Ana, 0917',30,true);
    check('vendor created',$v>0);
    check('duplicate vendor name refused',str_contains((string)err(fn()=>AccountsPayable::saveVendor(0,$vn,'','',30,true)),'already exists'));
    $v2=AccountsPayable::saveVendor(0,$vn.'-old','','',0,false);
    check('unknown vendor id on update refused',str_contains((string)err(fn()=>AccountsPayable::saveVendor(999999999,$vn.'-x','','',1,true)),'not found'));

    // ---- Bill validation ----
    $d='2001-03-10';
    check('inactive vendor refused',str_contains((string)err(fn()=>AccountsPayable::saveBill(0,$v2,'',$d,'','',[$line('5120','10')],$bk)),'active vendor'));
    check('Accounts Payable as a bill line refused',str_contains((string)err(fn()=>AccountsPayable::saveBill(0,$v,'',$d,'','',[$line('2010','10')],$bk)),'not Accounts Payable'));
    check('account without amount refused',str_contains((string)err(fn()=>AccountsPayable::saveBill(0,$v,'',$d,'','',[$line('5120','')],$bk)),'enter the amount'));
    check('no lines refused',str_contains((string)err(fn()=>AccountsPayable::saveBill(0,$v,'',$d,'','',[[]],$bk)),'at least one line'));
    check('due before bill date refused',str_contains((string)err(fn()=>AccountsPayable::saveBill(0,$v,'',$d,'2001-03-01','',[$line('5120','10')],$bk)),'cannot be before'));
    check('bad bill date refused',str_contains((string)err(fn()=>AccountsPayable::saveBill(0,$v,'','2001-02-30','','',[$line('5120','10')],$bk)),'valid entry date'));

    // ---- Create: one balanced draft JE, Dr lines / Cr AP ----
    $id=AccountsPayable::saveBill(0,$v,'SI-100',$d,'','Paper',[$line('5120','1,000','Bond paper'),$line('5140','250.50')],$bk);
    $b=AccountsPayable::bill($id); $e=Accounting::entry((int)$b['entry_id']);
    check('bill number BILL-YYYY-NNNN',(bool)preg_match('/^BILL-2001-\d{4}$/',$b['bill_no']));
    check('blank due date = bill date + vendor terms',$b['due_date']==='2001-04-09');
    check('bill total and draft status',(float)$b['total']===1250.5 && $b['status']==='DRAFT' && $b['memo']==='Paper');
    $ap=array_values(array_filter($e['lines'],fn($l)=>$l['code']==='2010'));
    check('entry: two debits plus one AP credit, balanced',count($e['lines'])===3 && count($ap)===1 && (float)$ap[0]['credit']===1250.5 && $e['debit']===$e['credit'] && $e['debit']===125050);
    check('entry memo names the bill and vendor',str_contains($e['memo'],$b['bill_no']) && str_contains($e['memo'],$vn) && $e['bill_no']===$b['bill_no']);
    check('bill lines are the debit lines',count($b['lines'])===2);
    check('journal edit of a bill entry refused',str_contains((string)err(fn()=>Accounting::saveDraft((int)$b['entry_id'],$d,'x',[['account_id'=>$acc('1010'),'debit'=>'1']],$bk)),'belongs to bill'));
    check('same supplier invoice twice refused',str_contains((string)err(fn()=>AccountsPayable::saveBill(0,$v,'SI-100',$d,'','',[$line('5120','5')],$bk)),'already recorded'));

    // ---- Edit while draft ----
    AccountsPayable::saveBill($id,$v,'SI-100',$d,'2001-03-20','',[$line('5120','800')],$bk);
    $b=AccountsPayable::bill($id); $e=Accounting::entry((int)$b['entry_id']);
    check('edit replaces lines, total and due date',(float)$b['total']===800.0 && $b['due_date']==='2001-03-20' && count($e['lines'])===2 && $e['credit']===80000 && $b['memo']===null);
    Accounting::submit((int)$b['entry_id']);
    check('submitted bill cannot be edited',str_contains((string)err(fn()=>AccountsPayable::saveBill($id,$v,'SI-100',$d,'','',[$line('5120','1')],$bk)),'Only draft bills'));
    check('not in aging before posting',$agingOf('2001-12-31',$vn)===null);
    Accounting::approve((int)$b['entry_id'],$fh);
    check('approved bill is posted',AccountsPayable::bill($id)['status']==='POSTED');

    // ---- Aging with fixed dates (due 2001-03-20) ----
    $bk2=fn(string $asOf)=>array_search(80000,array_intersect_key($agingOf($asOf,$vn)??[],AccountsPayable::BUCKETS),true);
    check('aging: before bill date not listed',$agingOf('2001-03-09',$vn)===null);
    check('aging: on due date = current',$bk2('2001-03-20')==='current');
    check('aging: 30 days late = 1-30',$bk2('2001-04-19')==='d30');
    check('aging: 31 days late = 31-60',$bk2('2001-04-20')==='d60');
    check('aging: 91 days late = over 90',$bk2('2001-06-19')==='over90');
    check('aging total row adds up',AccountsPayable::aging('2001-03-20')['total']['total']>=80000);
    check('statement: AP credited in trial balance',(function()use($acc){ foreach(Accounting::trialBalance('2001-12-31')['rows'] as $r) if($r['code']==='2010') return $r['cr']>=80000; return false; })());

    // ---- Reversal cancels the bill from the reversal date ----
    $rev=Accounting::reverse((int)$b['entry_id'],'2001-05-01',$fh); Accounting::submit($rev); Accounting::approve($rev,$bk);
    check('reversed bill still open before the reversal date',$bk2('2001-04-30')==='d60');
    check('reversed bill gone from aging after reversal',$agingOf('2001-05-01',$vn)===null && AccountsPayable::bill($id)['reversed_by_no']!==null);

    // ---- Void frees the supplier invoice number ----
    $id2=AccountsPayable::saveBill(0,$v,'SI-200',$d,'','',[$line('5120','5')],$bk);
    check('second bill numbered next',AccountsPayable::bill($id2)['bill_no']>AccountsPayable::bill($id)['bill_no']);
    Accounting::void((int)AccountsPayable::bill($id2)['entry_id'],'Duplicate',$bk);
    check('voided bill shows VOID',AccountsPayable::bill($id2)['status']==='VOID');
    check('voided bill frees its supplier invoice no.',AccountsPayable::saveBill(0,$v,'SI-200',$d,'','',[$line('5120','5')],$bk)>0);

    // ---- Closed month and missing AP account ----
    Accounting::closeMonth('2001-08-01',$fh);
    check('closed month refuses a bill',str_contains((string)err(fn()=>AccountsPayable::saveBill(0,$v,'','2001-08-15','','',[$line('5120','5')],$bk)),'is closed'));
    Accounting::reopenMonth('2001-08-01');
    db()->exec("UPDATE acct_accounts SET is_active=0 WHERE code='2010'");
    check('inactive AP account refused with a clear message',str_contains((string)err(fn()=>AccountsPayable::saveBill(0,$v,'',$d,'','',[$line('5120','5')],$bk)),'Accounts Payable) in the chart'));
    db()->exec("UPDATE acct_accounts SET is_active=1 WHERE code='2010'");
} finally {
    db()->exec("UPDATE acct_accounts SET is_active=1 WHERE code='2010'");
    db()->exec("DELETE FROM acct_closed_periods WHERE period_month='2001-08-01'");
    db()->exec('DELETE FROM acct_bills WHERE entry_id>'.$startId);
    db()->exec('UPDATE acct_journal_entries SET reverses_entry_id=NULL WHERE id>'.$startId);
    db()->exec('DELETE FROM acct_journal_lines WHERE entry_id>'.$startId);
    db()->exec('DELETE FROM acct_journal_entries WHERE id>'.$startId);
    db()->exec("DELETE FROM acct_vendors WHERE name LIKE 'zz-ap-test-%'");
    db()->exec("DELETE FROM audit_logs WHERE module='accounting'");
    db()->prepare('DELETE FROM users WHERE id=?')->execute([$fh]);
}
echo $fail?"\n$fail FAIL\n":"\nALL PASS\n"; exit($fail?1:0);
