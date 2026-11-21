<?php
declare(strict_types=1);

/**
 * Accounting slice A3 (migration 043: bank accounts, payments, bank reconciliation) against a SCRATCH copy of the database:
 *   HRIS_TEST_DB=hris_slice15_test_... C:\xampp\php\php.exe tests\cash_bank_db_test.php
 * Refuses the live database. Every row this test creates is deleted at the end.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if($dbName==='' || in_array($dbName,['pmbsi_hris','hris_clean'],true)) { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy.\n"); exit(2); }
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/CashBank.php';
$GLOBALS['pdo']=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(db()->query('SELECT DATABASE()')->fetchColumn()!==$dbName) exit(2);
if(!Accounting::ready()) { fwrite(STDERR,"Run migrations 039 to 043 on the scratch copy first.\n"); exit(2); }

$fail=0;
function check(string $label,bool $ok,string $why=''): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label.($ok||$why===''?'':'  ['.$why.']')."\n"; if(!$ok) $fail++; }
function err(callable $f): ?string { try { $f(); return null; } catch(RuntimeException $e) { return $e->getMessage(); } }

$bk=(int)db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
db()->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT role_id,?,?,? FROM users WHERE id=?')->execute(['Cash Test Approver','cash-test-approver@example.invalid','x',$bk]);
$fh=(int)db()->lastInsertId();
$_SESSION['user']=['id'=>$bk,'role_code'=>'SUPER_ADMIN','role_portal'=>'admin'];
$acc=fn(string $code)=>(int)db()->query('SELECT id FROM acct_accounts WHERE code='.db()->quote($code))->fetchColumn();
$startId=(int)db()->query('SELECT COALESCE(MAX(id),0) FROM acct_journal_entries')->fetchColumn();
$sfx=bin2hex(random_bytes(3)); $n="zz-cash-test-$sfx";
$post=function(int $entry) use($fh){ Accounting::submit($entry); Accounting::approve($entry,$fh); };
$open=fn(string $kind,int $party,int $pid=0)=>array_column(CashBank::openDocs($kind,$party,$pid),'open','id');

try {
    // ---- Pure helpers ----
    check('signed amount: parentheses and minus are negative',CashBank::signedCents('(1,000.50)')===-100050 && CashBank::signedCents('-5')===-500 && CashBank::signedCents('12.3')===1230);
    check('future month refused',str_contains((string)err(fn()=>CashBank::validMonth(date('Y-m',strtotime('first day of +1 month')))),'future'));

    // ---- Bank accounts ----
    $gl=Accounting::saveAccount(0,"ZZ1-$sfx","$n cash",'A',true); $gl2=Accounting::saveAccount(0,"ZZ2-$sfx","$n cash 2",'A',true);
    check('AR cannot be a bank ledger account',str_contains((string)err(fn()=>CashBank::saveBank(0,$n,'','',$acc('1100'),true)),'asset account'));
    check('bank account no. letters refused',str_contains((string)err(fn()=>CashBank::saveBank(0,$n,'','12AB',$gl,true)),'Bank account no.'));
    $bank=CashBank::saveBank(0,$n,'Test Bank','001-234 5',$gl,true);
    check('bank account created',$bank>0 && CashBank::bank($bank)['account_id']==$gl);
    check('duplicate bank name refused',str_contains((string)err(fn()=>CashBank::saveBank(0,$n,'','',$gl2,true)),'already exists'));
    check('ledger account already used by a bank refused',str_contains((string)err(fn()=>CashBank::saveBank(0,"$n-b",'','',$gl,true)),'no other bank'));
    $bank2=CashBank::saveBank(0,"$n-off",'','',$gl2,false);

    // ---- Posted bills and invoice to pay ----
    $v=AccountsPayable::saveVendor(0,"$n-vendor",'','',30,true); $v2=AccountsPayable::saveVendor(0,"$n-vendor2",'','',30,true);
    $c=AccountsReceivable::saveCustomer(0,"$n-customer",'','',30,true);
    $expense=(int)db()->query("SELECT id FROM acct_accounts WHERE type='X' AND is_active=1 ORDER BY code LIMIT 1")->fetchColumn();
    $income=(int)db()->query("SELECT id FROM acct_accounts WHERE type='I' AND is_active=1 ORDER BY code LIMIT 1")->fetchColumn();
    $b1=AccountsPayable::saveBill(0,$v,'',"2001-03-01",'','',[['account_id'=>$expense,'amount'=>'1,000']],$bk);
    $b2=AccountsPayable::saveBill(0,$v,'',"2001-03-02",'','',[['account_id'=>$expense,'amount'=>'500']],$bk);
    $b3=AccountsPayable::saveBill(0,$v,'',"2001-03-03",'','',[['account_id'=>$expense,'amount'=>'50']],$bk); // stays draft
    $bOther=AccountsPayable::saveBill(0,$v2,'',"2001-03-01",'','',[['account_id'=>$expense,'amount'=>'70']],$bk);
    foreach([$b1,$b2,$bOther] as $b) $post((int)AccountsPayable::bill($b)['entry_id']);
    $i1=AccountsReceivable::saveInvoice(0,$c,'','2001-03-05','','',[['account_id'=>$income,'amount'=>'2,000']],$bk);
    $post((int)AccountsReceivable::invoice($i1)['entry_id']);
    check('open bills: only posted bills of the vendor',array_keys($open('DISBURSEMENT',$v))==[$b1,$b2]);

    // ---- Payment validation ----
    $D='DISBURSEMENT'; $C='COLLECTION';
    check('no amounts refused',str_contains((string)err(fn()=>CashBank::savePayment($D,0,$bank,$v,'2001-04-05','','',[$b1=>''],$bk)),'at least one bill'));
    check('inactive bank refused',str_contains((string)err(fn()=>CashBank::savePayment($D,0,$bank2,$v,'2001-04-05','','',[$b1=>'1'],$bk)),'active bank'));
    check('more than the open balance refused',str_contains((string)err(fn()=>CashBank::savePayment($D,0,$bank,$v,'2001-04-05','','',[$b1=>'1,000.01'],$bk)),'more than the open balance'));
    check('draft bill refused',str_contains((string)err(fn()=>CashBank::savePayment($D,0,$bank,$v,'2001-04-05','','',[$b3=>'1'],$bk)),'not an open posted bill'));
    check('another vendor\'s bill refused',str_contains((string)err(fn()=>CashBank::savePayment($D,0,$bank,$v,'2001-04-05','','',[$bOther=>'1'],$bk)),'not an open posted bill'));
    check('payment before the bill date refused',str_contains((string)err(fn()=>CashBank::savePayment($D,0,$bank,$v,'2001-02-28','','',[$b1=>'1'],$bk)),'cannot be before'));
    check('invalid kind refused',str_contains((string)err(fn()=>CashBank::savePayment('X',0,$bank,$v,'2001-04-05','','',[$b1=>'1'],$bk)),'disbursement or collection'));

    // ---- Disbursement: one balanced draft JE (Dr AP per bill / Cr bank) ----
    $p1=CashBank::savePayment($D,0,$bank,$v,'2001-04-05','CHK-1','April run',[$b1=>'600',$b2=>'500'],$bk);
    $p=CashBank::payment($p1); $e=Accounting::entry((int)$p['entry_id']);
    check('payment number DV-YYYY-NNNN and draft',(bool)preg_match('/^DV-2001-\d{4}$/',$p['payment_no']) && $p['status']==='DRAFT' && (float)$p['total']===1100.0);
    $ap=array_filter($e['lines'],fn($l)=>(int)$l['account_id']===$acc('2010') && (float)$l['debit']>0); $cr=array_filter($e['lines'],fn($l)=>(int)$l['account_id']===$gl && (float)$l['credit']===1100.0);
    check('entry: AP debit per bill, one bank credit, balanced',count($e['lines'])===3 && count($ap)===2 && count($cr)===1 && $e['debit']===$e['credit'] && $e['payment_no']===$p['payment_no']);
    check('draft payment already holds the amount',$open($D,$v)==[$b1=>40000]);
    check('second payment above what is left refused',str_contains((string)err(fn()=>CashBank::savePayment($D,0,$bank,$v,'2001-04-06','','',[$b1=>'400.01'],$bk)),'more than the open balance'));
    check('journal edit of a payment entry refused',str_contains((string)err(fn()=>Accounting::saveDraft((int)$p['entry_id'],'2001-04-05','x',[['account_id'=>$gl,'debit'=>'1']],$bk)),'belongs to payment'));
    check('bill with a live payment cannot be reversed',str_contains((string)err(fn()=>Accounting::reverse((int)AccountsPayable::bill($b1)['entry_id'],'2001-04-10',$fh)),'Void or reverse the payment first'));

    // ---- Edit the draft: amounts and lines replaced ----
    CashBank::savePayment($D,$p1,$bank,$v,'2001-04-05','CHK-1','',[$b1=>'300',$b2=>''],$bk);
    $p=CashBank::payment($p1); $e=Accounting::entry((int)$p['entry_id']);
    check('edit replaces allocations and lines',(float)$p['total']===300.0 && count($p['allocations'])===1 && count($e['lines'])===2 && $e['credit']===30000);
    check('edit: own amount shown, others see the rest',CashBank::openDocs($D,$v,$p1)[$b1]['this']===30000 && $open($D,$v)==[$b1=>70000,$b2=>50000]);
    check('party of a saved payment cannot change',str_contains((string)err(fn()=>CashBank::savePayment($D,$p1,$bank,$v2,'2001-04-05','','',[$bOther=>'1'],$bk)),'cannot change'));

    // ---- Post, aging, void frees, reversal frees ----
    $post((int)$p['entry_id']);
    check('posted payment cannot be edited',str_contains((string)err(fn()=>CashBank::savePayment($D,$p1,$bank,$v,'2001-04-05','','',[$b1=>'1'],$bk)),'Only draft payments'));
    $ag=fn(string $asOf,string $name)=>array_values(array_filter(AccountsPayable::aging($asOf)['rows'],fn($r)=>$r['vendor']===$name))[0]['total']??0;
    check('AP aging before the payment date: full',$ag('2001-04-04',"$n-vendor")===150000);
    check('AP aging after posting: payment subtracted',$ag('2001-04-05',"$n-vendor")===120000);
    $p2=CashBank::savePayment($D,0,$bank,$v,'2001-04-06','','',[$b1=>'700'],$bk);
    check('bill fully held by drafts leaves the open list',!isset($open($D,$v)[$b1]));
    Accounting::void((int)CashBank::payment($p2)['entry_id'],'Duplicate',$bk);
    check('voided payment frees the bill',$open($D,$v)[$b1]===70000);
    $rev=Accounting::reverse((int)$p['entry_id'],'2001-05-10',$fh); Accounting::submit($rev); Accounting::approve($rev,$bk);
    check('reversed payment frees the bill',$open($D,$v)[$b1]===100000 && CashBank::payment($p1)['reversed_by_no']!==null);
    check('AP aging after the reversal date: full again',$ag('2001-05-10',"$n-vendor")===150000 && $ag('2001-05-09',"$n-vendor")===120000);
    check('bill can be reversed once its payments are reversed',err(function() use($b2,$fh){ Accounting::void(Accounting::reverse((int)AccountsPayable::bill($b2)['entry_id'],'2001-05-11',$fh),'test',$fh); })===null);

    // ---- Collection: Dr bank / Cr AR ----
    $p3=CashBank::savePayment($C,0,$bank,$c,'2001-04-20','OR 77','',[$i1=>'1,500'],$bk);
    $q=CashBank::payment($p3); $e=Accounting::entry((int)$q['entry_id']);
    check('collection number CR-YYYY-NNNN, bank debit, AR credit',(bool)preg_match('/^CR-2001-\d{4}$/',$q['payment_no']) && (int)$e['lines'][0]['account_id']===$gl && (float)$e['lines'][0]['debit']===1500.0 && (int)$e['lines'][1]['account_id']===$acc('1100'));
    $post((int)$q['entry_id']);
    $ar=array_values(array_filter(AccountsReceivable::aging('2001-04-30')['rows'],fn($r)=>$r['customer']==="$n-customer"))[0]['total']??0;
    check('AR aging: collection subtracted',$ar===50000);

    // ---- Bank reconciliation: GL lines Apr: -300 (DV), +1500 (CR); May: +300 (reversal) ----
    $d=CashBank::reconData(CashBank::bank($bank),'2001-04-01');
    check('April: book balance and two uncleared lines',$d['book']===120000 && count($d['lines'])===2 && $d['cleared']===0 && !$d['done']);
    $ids=array_column($d['lines'],'id');
    check('not balanced refused with the difference',str_contains((string)err(fn()=>CashBank::finishRecon($bank,'2001-04','1,000',$ids,$bk)),'difference'));
    check('a line of another account refused',str_contains((string)err(fn()=>CashBank::finishRecon($bank,'2001-04','1,200',[...$ids,(int)$e['lines'][1]['id']],$bk)),'no longer open'));
    $r1=CashBank::finishRecon($bank,'2001-04','1,200',$ids,$bk);
    $apr=array_values(array_filter(Accounting::periods(),fn($p)=>$p['month']==='2001-04-01'))[0] ?? [];
    check('close checklist counts the reconciled bank for April',($apr['banks_done']??0)===1 && ($apr['banks_total']??0)>=1);
    check('April reconciled',$r1>0 && CashBank::reconData(CashBank::bank($bank),'2001-04-01')['done']!==null);
    check('same month again refused',str_contains((string)err(fn()=>CashBank::finishRecon($bank,'2001-04','1,200',[],$bk)),'already reconciled'));
    check('earlier month refused',str_contains((string)err(fn()=>CashBank::finishRecon($bank,'2001-03','0',[],$bk)),'already reconciled'));
    $m=CashBank::reconData(CashBank::bank($bank),'2001-05-01');
    check('May: only the reversal line is uncleared, cleared carries over',count($m['lines'])===1 && $m['cleared']===120000 && $m['book']===150000);
    $r2=CashBank::finishRecon($bank,'2001-05','1,200',[],$bk); // reversal not on the May statement yet
    check('May reconciled with an outstanding line',CashBank::reconData(CashBank::bank($bank),'2001-06-01')['lines'][0]['id']==$m['lines'][0]['id']);
    check('undo of an older month refused',str_contains((string)err(fn()=>CashBank::undoRecon($r1)),'Only the latest'));
    CashBank::undoRecon($r2);
    check('undo latest month: it can be redone',CashBank::reconData(CashBank::bank($bank),'2001-05-01')['done']===null && CashBank::finishRecon($bank,'2001-05','1500',array_column($m['lines'],'id'),$bk)>0);
    check('bank with payments cannot move to another ledger account',str_contains((string)err(fn()=>CashBank::saveBank($bank,$n,'','',Accounting::saveAccount(0,"ZZ3-$sfx","$n cash 3",'A',true),true)),'cannot change'));

    // ---- Migration 052: account kind, petty cash, cheque register ----
    if(!CashBank::ready()) { echo "SKIP migration 052 checks (acct_cheques missing on the scratch copy)\n"; }
    else {
        check('unknown account type refused',str_contains((string)err(fn()=>CashBank::saveBank(0,"$n-x",'','',Accounting::saveAccount(0,"ZZ4-$sfx","$n cash 4",'A',true),true,'SAFE')),'petty cash'));
        $tot=fn()=>(array_values(array_filter(Accounting::periods(),fn($p)=>$p['month']===date('Y-m-01')))[0]['banks_total']??-1);
        $before=$tot();
        $petty=CashBank::saveBank(0,"$n-petty",'','',Accounting::saveAccount(0,"ZZ5-$sfx","$n petty",'A',true),true,'PETTY');
        $ew=CashBank::saveBank(0,"$n-ewallet",'','',Accounting::saveAccount(0,"ZZ6-$sfx","$n ewallet",'A',true),true,'EWALLET');
        check('petty cash and e-wallet saved with their type',CashBank::bank($petty)['kind']==='PETTY' && CashBank::bank($ew)['kind']==='EWALLET' && CashBank::bank($bank)['kind']==='BANK');
        check('close checklist skips petty cash, counts e-wallet',$tot()===$before+1);
        check('petty cash cannot be reconciled',str_contains((string)err(fn()=>CashBank::finishRecon($petty,'2001-04','0',[],$bk)),'Petty cash'));
        check('banks list carries the book balance',(float)array_values(array_filter(CashBank::banks(),fn($b)=>(int)$b['id']===$bank))[0]['book_balance']===1500.0);
        check('cheque on an e-wallet refused',str_contains((string)err(fn()=>CashBank::saveCheque($ew,'100','X','2001-06-01','10',0,'',$bk)),'not an e-wallet'));
        check('cheque no. symbols refused',str_contains((string)err(fn()=>CashBank::saveCheque($bank,'1/2','X','2001-06-01','10',0,'',$bk)),'Cheque no.'));
        check('zero cheque amount refused',str_contains((string)err(fn()=>CashBank::saveCheque($bank,'100','X','2001-06-01','0',0,'',$bk)),'amount'));
        check('standalone cheque needs a payee',str_contains((string)err(fn()=>CashBank::saveCheque($bank,'100','','2001-06-01','10',0,'',$bk)),'payee'));
        $ch1=CashBank::saveCheque($bank,'100','Juan Supplier','2001-06-02','250',0,'Rent',$bk);
        check('standalone cheque recorded as issued',$ch1>0 && (int)db()->query("SELECT COUNT(*) FROM acct_cheques WHERE id=$ch1 AND status='ISSUED' AND payment_id IS NULL")->fetchColumn()===1);
        check('duplicate cheque no. on the same bank refused',str_contains((string)err(fn()=>CashBank::saveCheque($bank,'100','Y','2001-06-02','5',0,'',$bk)),'already recorded'));
        check('cheque linked to a collection refused',str_contains((string)err(fn()=>CashBank::saveCheque($bank,'101','','2001-06-02','1500',$p3,'',$bk)),'disbursement'));
        check('cheque linked to a voided disbursement refused',str_contains((string)err(fn()=>CashBank::saveCheque($bank,'101','','2001-06-02','700',$p2,'',$bk)),'not voided'));
        $p4=CashBank::savePayment($D,0,$bank,$v,'2001-06-05','','',[$b1=>'300'],$bk); $post((int)CashBank::payment($p4)['entry_id']);
        check('linked cheque amount must equal the disbursement',str_contains((string)err(fn()=>CashBank::saveCheque($bank,'101','','2001-06-05','299',$p4,'',$bk)),'must equal'));
        $bank3=CashBank::saveBank(0,"$n-b3",'','',Accounting::saveAccount(0,"ZZ7-$sfx","$n cash 7",'A',true),true);
        check('linked disbursement from another bank refused',str_contains((string)err(fn()=>CashBank::saveCheque($bank3,'101','','2001-06-05','300',$p4,'',$bk)),'another bank'));
        $ch2=CashBank::saveCheque($bank,'101','','2001-06-05','300',$p4,'',$bk);
        check('linked cheque takes the vendor as payee',db()->query("SELECT payee FROM acct_cheques WHERE id=$ch2")->fetchColumn()==="$n-vendor");
        check('second cheque on the same disbursement refused',str_contains((string)err(fn()=>CashBank::saveCheque($bank,'102','','2001-06-05','300',$p4,'',$bk)),'already has cheque'));
        check('linked disbursement leaves the cheque picker',!in_array($p4,array_map('intval',array_column(CashBank::chequePayments(),'id')),true));
        $ch3=CashBank::saveCheque($bank,'103','Void Me','2001-06-06','10',0,'',$bk);
        $ch4=CashBank::saveCheque($bank,'104','Later','2001-07-01','10',0,'',$bk);
        check('cleared cannot be set by hand',str_contains((string)err(fn()=>CashBank::setChequeStatus($ch3,'CLEARED')),'reconciliation'));
        CashBank::setChequeStatus($ch3,'VOID');
        check('voided cheque cannot change again',str_contains((string)err(fn()=>CashBank::setChequeStatus($ch3,'STALE')),'Only an issued'));
        $jd=CashBank::reconData(CashBank::bank($bank),'2001-06-01');
        check('June recon offers only issued standalone cheques up to month end',array_map('intval',array_column($jd['cheques'],'id'))===[$ch1]);
        $p4line=array_values(array_filter($jd['lines'],fn($l)=>(int)$l['entry_id']===(int)CashBank::payment($p4)['entry_id']))[0]['id'];
        check('a cheque dated after the month refused',str_contains((string)err(fn()=>CashBank::finishRecon($bank,'2001-06','1,200',[$p4line],$bk,[$ch4])),'cheque is no longer open'));
        $r3=CashBank::finishRecon($bank,'2001-06','1,200',[$p4line],$bk,[$ch1]);
        $stOf=fn(int $id)=>db()->query("SELECT CONCAT(status,'/',COALESCE(recon_id,0)) FROM acct_cheques WHERE id=$id")->fetchColumn();
        check('reconciliation clears the ticked and the linked cheque',$stOf($ch1)==="CLEARED/$r3" && $stOf($ch2)==="CLEARED/$r3" && $stOf($ch4)==='ISSUED/0');
        CashBank::undoRecon($r3);
        check('undo puts the cheques back to issued',$stOf($ch1)==='ISSUED/0' && $stOf($ch2)==='ISSUED/0');
        check('bank with cheques cannot become petty cash',str_contains((string)err(fn()=>CashBank::saveBank($bank,$n,'','',$gl,true,'PETTY')),'stays a bank'));
    }
} finally {
    if(CashBank::ready()) db()->exec("DELETE ch FROM acct_cheques ch JOIN acct_bank_accounts b ON b.id=ch.bank_account_id WHERE b.name LIKE 'zz-cash-test-%'");
    $banks=implode(',',array_map('intval',db()->query("SELECT id FROM acct_bank_accounts WHERE name LIKE 'zz-cash-test-%'")->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
    db()->exec("DELETE FROM acct_bank_recons WHERE bank_account_id IN ($banks)");
    db()->exec('DELETE al FROM acct_payment_allocations al JOIN acct_payments p ON p.id=al.payment_id WHERE p.entry_id>'.$startId);
    db()->exec('DELETE FROM acct_payments WHERE entry_id>'.$startId);
    db()->exec('DELETE FROM acct_bills WHERE entry_id>'.$startId);
    db()->exec('DELETE FROM acct_invoices WHERE entry_id>'.$startId);
    db()->exec('UPDATE acct_journal_entries SET reverses_entry_id=NULL WHERE id>'.$startId);
    db()->exec('DELETE FROM acct_journal_lines WHERE entry_id>'.$startId);
    db()->exec('DELETE FROM acct_journal_entries WHERE id>'.$startId);
    db()->exec("DELETE FROM acct_bank_accounts WHERE id IN ($banks)");
    db()->exec("DELETE FROM acct_accounts WHERE code LIKE 'ZZ_-$sfx'");
    db()->exec("DELETE FROM acct_vendors WHERE name LIKE 'zz-cash-test-%'");
    db()->exec("DELETE FROM acct_customers WHERE name LIKE 'zz-cash-test-%'");
    db()->exec("DELETE FROM audit_logs WHERE module='accounting'");
    db()->prepare('DELETE FROM users WHERE id=?')->execute([$fh]);
}
echo $fail?"\n$fail FAIL\n":"\nALL PASS\n"; exit($fail?1:0);
