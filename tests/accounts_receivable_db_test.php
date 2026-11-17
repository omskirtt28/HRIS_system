<?php
declare(strict_types=1);

/**
 * Accounting slice A2 (migration 042: customers, invoices, AR aging) against a SCRATCH copy of the database:
 *   HRIS_TEST_DB=hris_slice15_test_... C:\xampp\php\php.exe tests\accounts_receivable_db_test.php
 * Refuses the live database. Every row this test creates is deleted at the end.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if($dbName==='' || in_array($dbName,['pmbsi_hris','hris_clean'],true)) { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy.\n"); exit(2); }
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/AccountsReceivable.php';
$GLOBALS['pdo']=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(db()->query('SELECT DATABASE()')->fetchColumn()!==$dbName) exit(2);
if(!Accounting::ready()) { fwrite(STDERR,"Run migrations 039 to 042 on the scratch copy first.\n"); exit(2); }

$fail=0;
function check(string $label,bool $ok,string $why=''): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label.($ok||$why===''?'':'  ['.$why.']')."\n"; if(!$ok) $fail++; }
function err(callable $f): ?string { try { $f(); return null; } catch(RuntimeException $e) { return $e->getMessage(); } }

$bk=(int)db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
db()->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT role_id,?,?,? FROM users WHERE id=?')->execute(['AR Test Approver','ar-test-approver@example.invalid','x',$bk]);
$fh=(int)db()->lastInsertId();
$_SESSION['user']=['id'=>$bk,'role_code'=>'SUPER_ADMIN','role_portal'=>'admin'];
$acc=fn(string $code)=>(int)db()->query('SELECT id FROM acct_accounts WHERE code='.db()->quote($code))->fetchColumn();
$startId=(int)db()->query('SELECT COALESCE(MAX(id),0) FROM acct_journal_entries')->fetchColumn();
$sfx=bin2hex(random_bytes(3)); $cn="zz-ar-test-$sfx";
$line=fn(string $code,string $amt,string $d='')=>['account_id'=>$acc($code),'description'=>$d,'amount'=>$amt];
$agingOf=function(string $asOf,string $name){ foreach(AccountsReceivable::aging($asOf)['rows'] as $r) if($r['customer']===$name) return $r; return null; };

try {
    // ---- Customers (shared party rules with vendors) ----
    check('customer name required',str_contains((string)err(fn()=>AccountsReceivable::saveCustomer(0,' ','','',30,true)),'Customer name is required'));
    check('TIN letters refused',str_contains((string)err(fn()=>AccountsReceivable::saveCustomer(0,$cn,'12A','',30,true)),'TIN'));
    $c=AccountsReceivable::saveCustomer(0,$cn,'123-456-789-000','Accounts, 0917',15,true);
    check('customer created',$c>0);
    check('duplicate customer name refused',str_contains((string)err(fn()=>AccountsReceivable::saveCustomer(0,$cn,'','',30,true)),'A customer named'));
    check('unknown customer id on update refused',str_contains((string)err(fn()=>AccountsReceivable::saveCustomer(999999999,$cn.'-x','','',1,true)),'Customer not found'));
    $c2=AccountsReceivable::saveCustomer(0,$cn.'-old','','',0,false);

    // ---- Invoice validation ----
    $d='2001-03-10';
    check('inactive customer refused',str_contains((string)err(fn()=>AccountsReceivable::saveInvoice(0,$c2,'',$d,'','',[$line('4010','10')],$bk)),'active customer'));
    check('Accounts Receivable as an invoice line refused',str_contains((string)err(fn()=>AccountsReceivable::saveInvoice(0,$c,'',$d,'','',[$line('1100','10')],$bk)),'not Accounts Receivable'));
    check('account without amount refused',str_contains((string)err(fn()=>AccountsReceivable::saveInvoice(0,$c,'',$d,'','',[$line('4010','')],$bk)),'enter the amount'));
    check('no lines refused',str_contains((string)err(fn()=>AccountsReceivable::saveInvoice(0,$c,'',$d,'','',[[]],$bk)),'at least one line'));
    check('due before invoice date refused',str_contains((string)err(fn()=>AccountsReceivable::saveInvoice(0,$c,'',$d,'2001-03-01','',[$line('4010','10')],$bk)),'cannot be before'));

    // ---- Create: one balanced draft JE, Dr AR / Cr income lines ----
    $id=AccountsReceivable::saveInvoice(0,$c,'PO-9',$d,'','March services',[$line('4010','3,000','Store services'),$line('4020','99.50')],$bk);
    $i=AccountsReceivable::invoice($id); $e=Accounting::entry((int)$i['entry_id']);
    check('invoice number INV-YYYY-NNNN',(bool)preg_match('/^INV-2001-\d{4}$/',$i['invoice_no']));
    check('blank due date = invoice date + customer terms',$i['due_date']==='2001-03-25');
    check('invoice total and draft status',(float)$i['total']===3099.5 && $i['status']==='DRAFT');
    $ar=array_values(array_filter($e['lines'],fn($l)=>$l['code']==='1100'));
    check('entry: one AR debit plus two income credits, balanced',count($e['lines'])===3 && count($ar)===1 && (float)$ar[0]['debit']===3099.5 && $e['debit']===$e['credit'] && $e['credit']===309950);
    check('entry links back to the invoice',$e['invoice_no']===$i['invoice_no'] && str_contains($e['memo'],$cn) && str_contains($e['memo'],'PO PO-9'));
    check('invoice lines are the credit lines',count($i['lines'])===2);
    check('journal edit of an invoice entry refused',str_contains((string)err(fn()=>Accounting::saveDraft((int)$i['entry_id'],$d,'x',[['account_id'=>$acc('1010'),'debit'=>'1']],$bk)),'belongs to invoice'));

    // ---- Edit while draft, then post ----
    AccountsReceivable::saveInvoice($id,$c,'',$d,'2001-03-20','',[$line('4010','800')],$bk);
    $i=AccountsReceivable::invoice($id); $e=Accounting::entry((int)$i['entry_id']);
    check('edit replaces lines, total and due date',(float)$i['total']===800.0 && $i['due_date']==='2001-03-20' && count($e['lines'])===2 && $e['debit']===80000 && $i['customer_ref']===null);
    Accounting::submit((int)$i['entry_id']);
    check('submitted invoice cannot be edited',str_contains((string)err(fn()=>AccountsReceivable::saveInvoice($id,$c,'',$d,'','',[$line('4010','1')],$bk)),'Only draft invoices'));
    check('not in aging before posting',$agingOf('2001-12-31',$cn)===null);
    Accounting::approve((int)$i['entry_id'],$fh);
    check('approved invoice is posted',AccountsReceivable::invoice($id)['status']==='POSTED');

    // ---- Aging with fixed dates (due 2001-03-20) ----
    $bucketOn=fn(string $asOf)=>array_search(80000,array_intersect_key($agingOf($asOf,$cn)??[],AccountsPayable::BUCKETS),true);
    check('aging: before invoice date not listed',$agingOf('2001-03-09',$cn)===null);
    check('aging: on due date = current',$bucketOn('2001-03-20')==='current');
    check('aging: 31 days late = 31-60',$bucketOn('2001-04-20')==='d60');
    check('aging: 91 days late = over 90',$bucketOn('2001-06-19')==='over90');
    check('trial balance: AR debited, income credited',(function(){ $dr=$cr=false; foreach(Accounting::trialBalance('2001-12-31')['rows'] as $r){ if($r['code']==='1100') $dr=$r['dr']>=80000; if($r['code']==='4010') $cr=$r['cr']>=80000; } return $dr && $cr; })());

    // ---- Reversal cancels the invoice from the reversal date ----
    $rev=Accounting::reverse((int)$i['entry_id'],'2001-05-01',$fh); Accounting::submit($rev); Accounting::approve($rev,$bk);
    check('reversed invoice still open before the reversal date',$bucketOn('2001-04-30')==='d60');
    check('reversed invoice gone from aging after reversal',$agingOf('2001-05-01',$cn)===null && AccountsReceivable::invoice($id)['reversed_by_no']!==null);

    // ---- Void, numbering, closed month, missing AR account ----
    $id2=AccountsReceivable::saveInvoice(0,$c,'',$d,'','',[$line('4010','5')],$bk);
    check('second invoice numbered next',AccountsReceivable::invoice($id2)['invoice_no']>AccountsReceivable::invoice($id)['invoice_no']);
    Accounting::void((int)AccountsReceivable::invoice($id2)['entry_id'],'Wrong customer',$bk);
    check('voided invoice shows VOID',AccountsReceivable::invoice($id2)['status']==='VOID');
    Accounting::closeMonth('2001-08-01',$fh);
    check('closed month refuses an invoice',str_contains((string)err(fn()=>AccountsReceivable::saveInvoice(0,$c,'','2001-08-15','','',[$line('4010','5')],$bk)),'is closed'));
    Accounting::reopenMonth('2001-08-01');
    db()->exec("UPDATE acct_accounts SET is_active=0 WHERE code='1100'");
    check('inactive AR account refused with a clear message',str_contains((string)err(fn()=>AccountsReceivable::saveInvoice(0,$c,'',$d,'','',[$line('4010','5')],$bk)),'Accounts Receivable) in the chart'));
} finally {
    db()->exec("UPDATE acct_accounts SET is_active=1 WHERE code='1100'");
    db()->exec("DELETE FROM acct_closed_periods WHERE period_month='2001-08-01'");
    db()->exec('DELETE FROM acct_invoices WHERE entry_id>'.$startId);
    db()->exec('UPDATE acct_journal_entries SET reverses_entry_id=NULL WHERE id>'.$startId);
    db()->exec('DELETE FROM acct_journal_lines WHERE entry_id>'.$startId);
    db()->exec('DELETE FROM acct_journal_entries WHERE id>'.$startId);
    db()->exec("DELETE FROM acct_customers WHERE name LIKE 'zz-ar-test-%'");
    db()->exec("DELETE FROM audit_logs WHERE module='accounting'");
    db()->prepare('DELETE FROM users WHERE id=?')->execute([$fh]);
}
echo $fail?"\n$fail FAIL\n":"\nALL PASS\n"; exit($fail?1:0);
