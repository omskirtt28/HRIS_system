<?php
declare(strict_types=1);
/**
 * Accounting Phase 1 over HTTP: sidebar, permission gates, CSRF, draft -> submit -> approve by another person, reports and CSV.
 * Scratch DB with migration 039: HRIS_TEST_DB=<scratch> php tests/accounting_http_test.php . App copied to a temp folder, php -S port 8121.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if($dbName==='' || $dbName==='pmbsi_hris') { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root=dirname(__DIR__); $port=8121;
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_acct_http_'.bin2hex(random_bytes(4));
$pdo=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_closed_periods'")->fetchColumn()) { fwrite(STDERR,"Run migration 039 on the scratch copy first.
"); exit(2); }
function copyTree(string $from,string $to): void { @mkdir($to,0777,true); foreach(new DirectoryIterator($from) as $f){ if($f->isDot()) continue; $f->isDir()?copyTree($f->getPathname(),$to.'/'.$f->getFilename()):copy($f->getPathname(),$to.'/'.$f->getFilename()); } }
function rmTree(string $dir): void { if(!is_dir($dir)) return; foreach(new DirectoryIterator($dir) as $f){ if($f->isDot()) continue; $f->isDir()?rmTree($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir); }
foreach(['app','config','public','vendor'] as $d) copyTree($root.'/'.$d,$tmp.'/'.$d);
copy($root.'/index.php',$tmp.'/index.php'); copy($root.'/router.php',$tmp.'/router.php');
file_put_contents($tmp.'/config/config.local.php',"<?php\nreturn ['app'=>['debug'=>true],'mail'=>[],'security'=>['credential_key'=>".var_export(base64_encode(random_bytes(32)),true)."],'db'=>['host'=>'127.0.0.1','port'=>'3306','database'=>".var_export($dbName,true).",'username'=>'root','password'=>'','charset'=>'utf8mb4']];\n");
$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$tmp,$tmp.'/router.php'],[['pipe','r'],['file',$tmp.'/server.log','w'],['file',$tmp.'/server.log','a']],$pipes,$tmp);
$startId=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM acct_journal_entries')->fetchColumn();
$users=[];
register_shutdown_function(function() use($proc,$tmp,$pdo,&$users,$startId){
    $pdo->exec("DELETE r FROM acct_bank_recons r JOIN acct_bank_accounts b ON b.id=r.bank_account_id WHERE b.name LIKE 'zz-acct-bank-%'"); $pdo->exec('DELETE al FROM acct_payment_allocations al JOIN acct_payments p ON p.id=al.payment_id WHERE p.entry_id>'.$startId); $pdo->exec('DELETE FROM acct_payments WHERE entry_id>'.$startId);
    $pdo->exec('DELETE FROM acct_remittances WHERE entry_id>'.$startId);
    $pdo->exec('DELETE FROM acct_bills WHERE entry_id>'.$startId); $pdo->exec("DELETE FROM acct_vendors WHERE name LIKE 'zz-acct-vendor-%'");
    $pdo->exec('DELETE FROM acct_invoices WHERE entry_id>'.$startId); $pdo->exec("DELETE FROM acct_customers WHERE name LIKE 'zz-acct-customer-%'");
    $pdo->exec('DELETE FROM acct_journal_lines WHERE entry_id>'.$startId); $pdo->exec('DELETE FROM acct_journal_entries WHERE id>'.$startId); $pdo->exec("DELETE FROM acct_bank_accounts WHERE name LIKE 'zz-acct-bank-%'");
    if($users){ $in=implode(',',array_map('intval',$users)); $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($in)"); foreach(['user_sessions','login_attempts'] as $t){ try{$pdo->exec("DELETE FROM $t WHERE user_id IN ($in)");}catch(Throwable){} } $pdo->exec("DELETE FROM users WHERE id IN ($in)"); }
    if(is_resource($proc)){ proc_terminate($proc); proc_close($proc); } usleep(300000); rmTree($tmp);
});
for($i=0;$i<50;$i++){ $s=@fsockopen('127.0.0.1',$port,$e1,$e2,0.2); if($s){ fclose($s); break; } usleep(100000); }
$fail=0;
function check(string $label,bool $ok): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label."\n"; if(!$ok) $fail++; }
function req(string $jar,string $method,string $path,array $post=[]): array {
    global $port; $ch=curl_init('http://127.0.0.1:'.$port.$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>30]);
    if($method==='POST') curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));
    $raw=(string)curl_exec($ch); $size=curl_getinfo($ch,CURLINFO_HEADER_SIZE); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    $head=substr($raw,0,$size); return ['code'=>$code,'head'=>$head,'body'=>substr($raw,$size),'loc'=>preg_match('/^Location:\s*(.+)$/mi',$head,$m)?trim($m[1]):''];
}
function e_test(string $s): string { return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }
function csrf(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/',$html,$m)?html_entity_decode($m[1]):''; }
function login(string $email): string {
    $jar=tempnam(sys_get_temp_dir(),'jar'); $p=req($jar,'GET','/index.php?page=login');
    req($jar,'POST','/index.php?page=login',['csrf'=>csrf($p['body']),'action'=>'login','email'=>$email,'password'=>'Acct-Test-2026!']);
    return $jar;
}
$sfx=bin2hex(random_bytes(3)); $hash=password_hash('Acct-Test-2026!',PASSWORD_DEFAULT);
$mk=function(string $role,string $who) use($pdo,$sfx,$hash,&$users): string {
    $email="zz-acct-$who-$sfx@example.invalid";
    $pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT id,?,?,? FROM roles WHERE code=?')->execute(["Acct $who",$email,$hash,$role]);
    $users[]=(int)$pdo->lastInsertId(); return $email;
};
$a=login($mk('HRIS_ADMIN','prep')); $b=login($mk('HRIS_ADMIN','appr')); $hr=login($mk('HR_USER','hr'));

$j=req($a,'GET','/index.php?page=accounting-journal');
check('admin opens Journal Entries',$j['code']===200 && str_contains($j['body'],'New journal entry'));
check('sidebar lists the four real screens only',str_contains($j['body'],'page=accounting-coa') && str_contains($j['body'],'page=accounting-reports') && !str_contains($j['body'],'page=accounting-ap') && !str_contains($j['body'],'Sample data'));
$hrPage=req($hr,'GET','/index.php?page=accounting-journal');
check('HR without accounting.view is refused',$hrPage['code']!==200 || !str_contains($hrPage['body'],'New journal entry'));
check('HR sidebar has no Accounting group',!str_contains(req($hr,'GET','/index.php?page=hr-dashboard')['body'],'page=accounting-journal'));

$form=req($a,'GET','/index.php?page=accounting-entry&new=1')['body'];
$acc=fn($c)=>(int)$pdo->query('SELECT id FROM acct_accounts WHERE code='.$pdo->quote($c))->fetchColumn();
$post=['action'=>'acct_journal_save','id'=>0,'entry_date'=>date('Y-m-d'),'memo'=>'<b>Office rent</b>','submit_after'=>1,
    'lines'=>[['account_id'=>$acc('5100'),'debit'=>'20,000.00'],['account_id'=>$acc('1020'),'credit'=>'20000']]];
$r=req($a,'POST','/index.php',$post);
check('save without CSRF is refused',$r['code']===403 || (int)$pdo->query('SELECT COUNT(*) FROM acct_journal_entries WHERE id>'.$startId)->fetchColumn()===0);
$r=req($a,'POST','/index.php',$post+['csrf'=>csrf($form)]);
$id=(int)$pdo->query('SELECT MAX(id) FROM acct_journal_entries')->fetchColumn();
check('save and submit creates a submitted entry',$id>$startId && $pdo->query("SELECT status FROM acct_journal_entries WHERE id=$id")->fetchColumn()==='FOR_APPROVAL');
$view=req($a,'GET',"/index.php?page=accounting-entry&id=$id")['body'];
check('memo is escaped',str_contains($view,'&lt;b&gt;Office rent') && !str_contains($view,'<b>Office rent'));
check('preparer sees no Approve button',!str_contains($view,'Approve and post') && str_contains($view,'another approver must post it'));
$r=req($a,'POST','/index.php',['csrf'=>csrf($view),'action'=>'acct_journal_approve','id'=>$id]);
check('forged approve by the preparer is refused',$pdo->query("SELECT status FROM acct_journal_entries WHERE id=$id")->fetchColumn()==='FOR_APPROVAL');
$r=req($hr,'POST','/index.php',['csrf'=>csrf(req($hr,'GET','/index.php?page=hr-dashboard')['body']),'action'=>'acct_journal_approve','id'=>$id]);
check('HR without permission cannot approve',$pdo->query("SELECT status FROM acct_journal_entries WHERE id=$id")->fetchColumn()==='FOR_APPROVAL');
$bv=req($b,'GET',"/index.php?page=accounting-entry&id=$id")['body'];
check('second admin sees Approve and post',str_contains($bv,'Approve and post'));
req($b,'POST','/index.php',['csrf'=>csrf($bv),'action'=>'acct_journal_approve','id'=>$id]);
check('second admin posts the entry',$pdo->query("SELECT status FROM acct_journal_entries WHERE id=$id")->fetchColumn()==='POSTED');

$tb=req($a,'GET','/index.php?page=accounting-reports&report=tb')['body'];
check('trial balance shows Balanced',str_contains($tb,'Balanced') && str_contains($tb,'5100 Rent Expense'));
check('balance sheet balances',str_contains(req($a,'GET','/index.php?page=accounting-reports&report=bs')['body'],'Balanced'));
$csv=req($a,'GET','/index.php?page=accounting-reports&report=tb&csv=1');
check('CSV download',str_contains($csv['head'],'text/csv') && str_contains($csv['body'],'5100,"Rent Expense",20000.00,0.00'));
$per=req($a,'GET','/index.php?page=accounting-periods');
check('periods page shows the close checklist',$per['code']===200 && str_contains($per['body'],'Close checklist') && str_contains($per['body'],'Remittances recorded'));
check('periods and chart pages open',$per['code']===200 &&str_contains(req($a,'GET','/index.php?page=accounting-coa')['body'],'Add account'));
// ---- Slice A1: vendors and bills ----
$vp=req($a,'GET','/index.php?page=accounting-vendors')['body'];
check('sidebar lists Bills and Vendors',str_contains($vp,'page=accounting-bills') && str_contains($vp,'page=accounting-vendors'));
$vname="zz-acct-vendor-$sfx";
req($a,'POST','/index.php',['csrf'=>csrf($vp),'action'=>'acct_vendor_save','name'=>$vname,'tin'=>'123-456-789','terms_days'=>15,'is_active'=>1]);
$vid=(int)$pdo->query('SELECT id FROM acct_vendors WHERE name='.$pdo->quote($vname))->fetchColumn();
check('admin adds a vendor',$vid>0);
req($hr,'POST','/index.php',['csrf'=>csrf(req($hr,'GET','/index.php?page=hr-dashboard')['body']),'action'=>'acct_vendor_save','name'=>$vname.'-hr','terms_days'=>1]);
check('HR without permission cannot add a vendor',!(int)$pdo->query('SELECT COUNT(*) FROM acct_vendors WHERE name='.$pdo->quote($vname.'-hr'))->fetchColumn());
$bf=req($a,'GET','/index.php?page=accounting-bill&new=1')['body'];
req($a,'POST','/index.php',['csrf'=>csrf($bf),'action'=>'acct_bill_save','id'=>0,'vendor_id'=>$vid,'vendor_ref'=>'SI-1','bill_date'=>date('Y-m-d'),'due_date'=>'','memo'=>'<i>Paper</i>','submit_after'=>1,'lines'=>[['account_id'=>$acc('5120'),'amount'=>'1,500']]]);
$bill=$pdo->query('SELECT b.*,e.status FROM acct_bills b JOIN acct_journal_entries e ON e.id=b.entry_id WHERE b.vendor_id='.$vid)->fetch();
check('save and submit creates a bill with a submitted entry and terms due date',$bill && $bill['status']==='FOR_APPROVAL' && $bill['due_date']===date('Y-m-d',strtotime('+15 days')) && (float)$bill['total']===1500.0);
$bv=req($a,'GET','/index.php?page=accounting-bill&id='.(int)($bill['id']??0))['body'];
check('bill page escapes memo and links its entry',str_contains($bv,'&lt;i&gt;Paper') && !str_contains($bv,'<i>Paper') && str_contains($bv,'page=accounting-entry&id='.(int)($bill['entry_id']??0)));
$ev=req($b,'GET','/index.php?page=accounting-entry&id='.(int)($bill['entry_id']??0))['body'];
check('entry page says it came from the bill',str_contains($ev,'Created from bill'));
req($b,'POST','/index.php',['csrf'=>csrf($ev),'action'=>'acct_journal_approve','id'=>(int)($bill['entry_id']??0)]);
$ag=req($a,'GET','/index.php?page=accounting-bills&view=aging')['body'];
check('posted bill shows in AP aging as current',str_contains($ag,e_test($vname)) && str_contains($ag,'1,500.00'));
// ---- Slice A2: customers and invoices ----
$cp=req($a,'GET','/index.php?page=accounting-customers')['body'];
check('sidebar lists Invoices and Customers',str_contains($cp,'page=accounting-invoices') && str_contains($cp,'page=accounting-customers'));
$cname="zz-acct-customer-$sfx";
req($a,'POST','/index.php',['csrf'=>csrf($cp),'action'=>'acct_customer_save','name'=>$cname,'terms_days'=>10,'is_active'=>1]);
$cid=(int)$pdo->query('SELECT id FROM acct_customers WHERE name='.$pdo->quote($cname))->fetchColumn();
check('admin adds a customer',$cid>0);
req($hr,'POST','/index.php',['csrf'=>csrf(req($hr,'GET','/index.php?page=hr-dashboard')['body']),'action'=>'acct_customer_save','name'=>$cname.'-hr','terms_days'=>1]);
check('HR without permission cannot add a customer',!(int)$pdo->query('SELECT COUNT(*) FROM acct_customers WHERE name='.$pdo->quote($cname.'-hr'))->fetchColumn());
$if=req($a,'GET','/index.php?page=accounting-invoice&new=1')['body'];
check('invoice form leaves Accounts Receivable out of the line accounts',str_contains($if,'id="acInvoiceForm"') && !str_contains($if,'>1100 Accounts Receivable<'));
req($a,'POST','/index.php',['csrf'=>csrf($if),'action'=>'acct_invoice_save','id'=>0,'customer_id'=>$cid,'customer_ref'=>'PO-1','invoice_date'=>date('Y-m-d'),'due_date'=>'','memo'=>'<b>Services</b>','submit_after'=>1,'lines'=>[['account_id'=>$acc('4010'),'amount'=>'2,000']]]);
$inv=$pdo->query('SELECT i.*,e.status FROM acct_invoices i JOIN acct_journal_entries e ON e.id=i.entry_id WHERE i.customer_id='.$cid)->fetch();
check('save and submit creates an invoice with a submitted entry and terms due date',$inv && $inv['status']==='FOR_APPROVAL' && $inv['due_date']===date('Y-m-d',strtotime('+10 days')) && (float)$inv['total']===2000.0);
$iv=req($a,'GET','/index.php?page=accounting-invoice&id='.(int)($inv['id']??0))['body'];
check('invoice page escapes memo and links its entry',str_contains($iv,'&lt;b&gt;Services') && !str_contains($iv,'<b>Services') && str_contains($iv,'page=accounting-entry&id='.(int)($inv['entry_id']??0)));
$ev=req($b,'GET','/index.php?page=accounting-entry&id='.(int)($inv['entry_id']??0))['body'];
check('entry page says it came from the invoice',str_contains($ev,'Created from invoice'));
req($b,'POST','/index.php',['csrf'=>csrf($ev),'action'=>'acct_journal_approve','id'=>(int)($inv['entry_id']??0)]);
$ag=req($a,'GET','/index.php?page=accounting-invoices&view=aging')['body'];
check('posted invoice shows in AR aging as current',str_contains($ag,'AR Aging') && str_contains($ag,e_test($cname)) && str_contains($ag,'2,000.00'));
// ---- Slice A3: bank accounts, collection receipt, reconciliation ----
$bp=req($a,'GET','/index.php?page=accounting-banks')['body'];
check('sidebar lists Payments, Bank Accounts and Bank Reconciliation',str_contains($bp,'page=accounting-payments') && str_contains($bp,'page=accounting-banks') && str_contains($bp,'page=accounting-recon'));
$bname="zz-acct-bank-$sfx";
req($a,'POST','/index.php',['csrf'=>csrf($bp),'action'=>'acct_bank_save','name'=>$bname,'account_id'=>$acc('1030'),'is_active'=>1]);
$bid=(int)$pdo->query('SELECT id FROM acct_bank_accounts WHERE name='.$pdo->quote($bname))->fetchColumn();
check('approver adds a bank account',$bid>0);
req($hr,'POST','/index.php',['csrf'=>csrf(req($hr,'GET','/index.php?page=hr-dashboard')['body']),'action'=>'acct_bank_save','name'=>$bname.'-hr','account_id'=>$acc('1010'),'is_active'=>1]);
check('HR without permission cannot add a bank account',!(int)$pdo->query('SELECT COUNT(*) FROM acct_bank_accounts WHERE name='.$pdo->quote($bname.'-hr'))->fetchColumn());
$s1=req($a,'GET','/index.php?page=accounting-payment&new=COLLECTION')['body'];
check('new collection asks for the customer first',str_contains($s1,'Who paid you?') && str_contains($s1,e_test($cname)));
$pf=req($a,'GET','/index.php?page=accounting-payment&new=COLLECTION&party='.$cid)['body'];
check('collection form lists the open posted invoice',str_contains($pf,'id="acPayForm"') && str_contains($pf,'amounts['.(int)$inv['id'].']') && str_contains($pf,e_test($inv['invoice_no'])));
req($a,'POST','/index.php',['csrf'=>csrf($pf),'action'=>'acct_payment_save','id'=>0,'kind'=>'COLLECTION','party_id'=>$cid,'bank_account_id'=>$bid,'pay_date'=>date('Y-m-d'),'reference'=>'<i>OR-1</i>','memo'=>'','submit_after'=>1,'amounts'=>[(int)$inv['id']=>'500']]);
$pay=$pdo->query('SELECT p.*,e.status FROM acct_payments p JOIN acct_journal_entries e ON e.id=p.entry_id WHERE p.customer_id='.$cid)->fetch();
check('save and submit creates a submitted collection receipt',$pay && $pay['status']==='FOR_APPROVAL' && (float)$pay['total']===500.0 && str_starts_with($pay['payment_no'],'CR-'));
$pv=req($a,'GET','/index.php?page=accounting-payment&id='.(int)($pay['id']??0))['body'];
check('payment page escapes the reference and links the invoice',str_contains($pv,'&lt;i&gt;OR-1') && !str_contains($pv,'<i>OR-1') && str_contains($pv,'page=accounting-invoice&id='.(int)$inv['id']));
$ev=req($b,'GET','/index.php?page=accounting-entry&id='.(int)($pay['entry_id']??0))['body'];
check('entry page says it came from the payment',str_contains($ev,'Created from payment'));
req($b,'POST','/index.php',['csrf'=>csrf($ev),'action'=>'acct_journal_approve','id'=>(int)($pay['entry_id']??0)]);
check('invoice page shows the open balance after the collection',str_contains(req($a,'GET','/index.php?page=accounting-invoice&id='.(int)$inv['id'])['body'],'Open balance <strong>1,500.00'));
check('AR aging subtracts the collection',str_contains(req($a,'GET','/index.php?page=accounting-invoices&view=aging')['body'],'1,500.00'));
$rp=req($a,'GET','/index.php?page=accounting-recon&bank='.$bid.'&month='.date('Y-m'))['body'];
$line=(int)$pdo->query("SELECT l.id FROM acct_journal_lines l WHERE l.entry_id=".(int)$pay['entry_id']." AND l.account_id=".$acc('1030'))->fetchColumn();
check('reconciliation lists the posted bank line',str_contains($rp,'id="acReconForm"') && str_contains($rp,'name="cleared[]" value="'.$line.'"'));
req($a,'POST','/index.php',['csrf'=>csrf($rp),'action'=>'acct_recon_finish','bank_account_id'=>$bid,'month'=>date('Y-m'),'statement_balance'=>'500','cleared'=>[$line]]);
check('finished reconciliation clears the line',(int)$pdo->query('SELECT COUNT(*) FROM acct_recon_lines WHERE line_id='.$line)->fetchColumn()===1 && str_contains(req($a,'GET','/index.php?page=accounting-recon&bank='.$bid.'&month='.date('Y-m'))['body'],'is reconciled'));
// ---- Slice A4: remittance ----
$rl=req($a,'GET','/index.php?page=accounting-remittances')['body'];
check('remittances page lists the month grid and To confirm',str_contains($rl,'Recorded by month') && str_contains($rl,'To confirm') && str_contains($rl,'page=accounting-remittances'));
$rf=req($a,'GET','/index.php?page=accounting-remittance&new=1')['body'];
check('new remittance form starts on SSS Payable',str_contains($rf,'id="acRemitForm"') && str_contains($rf,'value="'.$acc('2100').'" selected'));
req($a,'POST','/index.php',['csrf'=>csrf($rf),'action'=>'acct_remittance_save','id'=>0,'agency'=>'SSS','period'=>date('Y-m'),'bank_account_id'=>$bid,'pay_date'=>date('Y-m-d'),'reference'=>"<b>PRN-$sfx</b>",'memo'=>'','submit_after'=>1,'lines'=>[['account_id'=>$acc('2100'),'amount'=>'300']]]);
$rm=$pdo->query("SELECT r.*,e.status FROM acct_remittances r JOIN acct_journal_entries e ON e.id=r.entry_id WHERE r.reference=".$pdo->quote("<b>PRN-$sfx</b>"))->fetch();
check('save and submit creates a submitted remittance',$rm && $rm['status']==='FOR_APPROVAL' && (float)$rm['total']===300.0 && str_starts_with($rm['remittance_no'],'RMT-'));
$rv=req($a,'GET','/index.php?page=accounting-remittance&id='.(int)($rm['id']??0))['body'];
check('remittance page escapes the reference',str_contains($rv,'&lt;b&gt;PRN-') && !str_contains($rv,'<b>PRN-'));
check('entry page says it came from the remittance',str_contains(req($b,'GET','/index.php?page=accounting-entry&id='.(int)($rm['entry_id']??0))['body'],'Created from remittance'));
check('unknown screen redirects to the journal',str_contains(req($a,'GET','/index.php?page=accounting-nope')['loc'],'accounting-journal'));
$dash=req($a,'GET','/index.php?page=accounting-dashboard')['body'];
check('dashboard (Phase 3 B1) shows the income and expenses card with a period picker',str_contains($dash,'Income and expenses') && str_contains($dash,'name="periods[]"') && str_contains($dash,'data-ac-select="all"'));
echo $fail?"\n$fail FAIL\n":"\nALL PASS\n"; exit($fail?1:0);
