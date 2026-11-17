<?php
declare(strict_types=1);
/**
 * Payroll engine P2-P5 over HTTP: Payroll Runs sidebar and gates, CSRF, compute -> release by another person -> journal entry,
 * register CSV, employee payslip (own only), Payroll Setup rates/rules tabs open in review-only mode.
 * Scratch DB with migrations 039-046: HRIS_TEST_DB=<scratch> php tests/payroll_run_http_test.php . App copied to a temp folder, php -S port 8135.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if($dbName==='' || in_array($dbName,['pmbsi_hris','hris_clean'],true)) { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy.\n"); exit(2); }
$root=dirname(__DIR__); $port=8135;
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_run_http_'.bin2hex(random_bytes(4));
$pdo=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='payroll_run_adjustments'")->fetchColumn()) { fwrite(STDERR,"Run migration 046 on the scratch copy first.\n"); exit(2); }
function copyTree(string $from,string $to): void { @mkdir($to,0777,true); foreach(new DirectoryIterator($from) as $f){ if($f->isDot()) continue; $f->isDir()?copyTree($f->getPathname(),$to.'/'.$f->getFilename()):copy($f->getPathname(),$to.'/'.$f->getFilename()); } }
function rmTree(string $dir): void { if(!is_dir($dir)) return; foreach(new DirectoryIterator($dir) as $f){ if($f->isDot()) continue; $f->isDir()?rmTree($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir); }
foreach(['app','config','public','vendor'] as $d) copyTree($root.'/'.$d,$tmp.'/'.$d);
copy($root.'/index.php',$tmp.'/index.php'); copy($root.'/router.php',$tmp.'/router.php');
file_put_contents($tmp.'/config/config.local.php',"<?php\nreturn ['app'=>['debug'=>true],'mail'=>[],'security'=>['credential_key'=>".var_export(base64_encode(random_bytes(32)),true)."],'db'=>['host'=>'127.0.0.1','port'=>'3306','database'=>".var_export($dbName,true).",'username'=>'root','password'=>'','charset'=>'utf8mb4']];\n");
$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$tmp,$tmp.'/router.php'],[['pipe','r'],['file',$tmp.'/server.log','w'],['file',$tmp.'/server.log','a']],$pipes,$tmp);
$sfx=bin2hex(random_bytes(3)); $users=[]; $emp=0; $cut=0;
$startEntry=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM acct_journal_entries')->fetchColumn();
$rulesBefore=$pdo->query('SELECT day_type,regular_multiplier,ot_multiplier,night_percent FROM payroll_rate_rules')->fetchAll();
register_shutdown_function(function() use($proc,$tmp,$pdo,&$users,&$emp,&$cut,$startEntry,$rulesBefore){
    if($cut){ $pdo->exec("UPDATE payroll_runs SET entry_id=NULL WHERE cutoff_id=$cut"); $pdo->exec("DELETE FROM payroll_runs WHERE cutoff_id=$cut"); $pdo->exec("DELETE FROM payroll_cutoff_snapshots WHERE cutoff_id=$cut"); $pdo->exec("DELETE FROM payroll_cutoff_runs WHERE cutoff_id=$cut"); $pdo->exec("DELETE FROM payroll_cutoffs WHERE id=$cut"); }
    $pdo->exec("DELETE FROM acct_journal_lines WHERE entry_id>$startEntry"); $pdo->exec("DELETE FROM acct_journal_entries WHERE id>$startEntry");
    $pdo->exec("DELETE FROM payroll_stat_tables WHERE version_label='ZZ http run'");
    if($emp){ $pdo->exec("DELETE FROM payroll_employee_rates WHERE employee_id=$emp"); $pdo->exec("DELETE FROM employees WHERE id=$emp"); }
    $q=$pdo->prepare('UPDATE payroll_rate_rules SET regular_multiplier=?,ot_multiplier=?,night_percent=? WHERE day_type=?'); foreach($rulesBefore as $r) $q->execute([$r['regular_multiplier'],$r['ot_multiplier'],$r['night_percent'],$r['day_type']]);
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
    return ['code'=>$code,'head'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
}
function csrf(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/',$html,$m)?html_entity_decode($m[1]):''; }
function login(string $email): string {
    $jar=tempnam(sys_get_temp_dir(),'jar'); $p=req($jar,'GET','/index.php?page=login');
    req($jar,'POST','/index.php?page=login',['csrf'=>csrf($p['body']),'action'=>'login','email'=>$email,'password'=>'Run-Test-2026!']);
    return $jar;
}
$hash=password_hash('Run-Test-2026!',PASSWORD_DEFAULT);
$mk=function(string $role,string $who) use($pdo,$sfx,$hash,&$users): string {
    $email="zz-run-$who-$sfx@example.invalid";
    $pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT id,?,?,? FROM roles WHERE code=?')->execute(["Run $who",$email,$hash,$role]);
    $users[]=(int)$pdo->lastInsertId(); return $email;
};
$hrJar=login($mk('HR_USER','hr')); $payJar=login($mk('PAYROLL','pay')); $empJar=login($mk('EMPLOYEE','emp')); $otherJar=login($mk('EMPLOYEE','other'));
[$hrId,$payId,$empUser]=$users;

// Fixture: one employee linked to the EMPLOYEE user, one finalized cutoff, rate, rule, verified tables (all made up).
$pdo->prepare('INSERT INTO employees(employee_no,first_name,last_name,status,hire_date,user_id) VALUES(?,?,?,"ACTIVE","2020-01-01",?)')->execute(["ZZRUNH-$sfx",'Http','<i>Run</i>',$empUser]); $emp=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO payroll_employee_rates(employee_id,effective_from,salary_basis,salary_amount,hourly_divisor) VALUES(?,?,?,?,?)')->execute([$emp,'2090-01-01','DAILY',800,8]);
$pdo->exec("UPDATE payroll_rate_rules SET regular_multiplier=1,ot_multiplier=1.25,night_percent=10 WHERE day_type='REGULAR'");
$pdo->prepare('INSERT INTO payroll_cutoffs(code,period_start,period_end) VALUES(?,?,?)')->execute(["ZZRUNH-$sfx",'2092-05-16','2092-05-31']); $cut=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO payroll_cutoff_runs(cutoff_id,state) VALUES(?,"FINALIZED")')->execute([$cut]);
for($i=16;$i<=20;$i++) $pdo->prepare('INSERT INTO payroll_cutoff_snapshots(cutoff_id,employee_id,work_date,snapshot_json) VALUES(?,?,?,?)')->execute([$cut,$emp,"2092-05-$i",json_encode(['work_date'=>"2092-05-$i",'day_type'=>'REGULAR','regular_minutes'=>480])]);
$admin=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
foreach([['SSS','MONTHLY',"0 - 100 200"],['PHILHEALTH','MONTHLY',"0 - 2 2"],['PAGIBIG','MONTHLY',"0 - 2 2 5000"],['BIR_WITHHOLDING','SEMI_MONTHLY',"0 - 0 0"]] as [$c,$f,$rows]) {
    $pdo->prepare('INSERT INTO payroll_stat_tables(table_code,pay_frequency,version_label,effective_from,source_reference,entered_by,verified_by,verified_at) VALUES(?,?,?,?,?,?,?,NOW())')->execute([$c,$f,'ZZ http run','2092-01-01','ZZ',$admin,$payId]);
    $tid=(int)$pdo->lastInsertId(); $r=preg_split('/\s+/',$rows);
    if($c==='SSS') $pdo->prepare('INSERT INTO payroll_stat_table_rows(table_id,bracket_from,employee_amount,employer_amount) VALUES(?,?,?,?)')->execute([$tid,0,$r[2],$r[3]]);
    elseif($c==='BIR_WITHHOLDING') $pdo->prepare('INSERT INTO payroll_stat_table_rows(table_id,bracket_from,fixed_amount,rate_percent) VALUES(?,?,?,?)')->execute([$tid,0,0,0]);
    else $pdo->prepare('INSERT INTO payroll_stat_table_rows(table_id,bracket_from,employee_rate,employer_rate,base_ceiling) VALUES(?,?,?,?,?)')->execute([$tid,0,$r[2],$r[3],$c==='PAGIBIG'?5000:null]);
}

$page=req($hrJar,'GET','/index.php?page=payroll-runs');
check('HR with payroll.process opens Payroll Runs',$page['code']===200 && str_contains($page['body'],'Compute payroll') && str_contains($page['body'],"ZZRUNH-$sfx"));
check('sidebar links Payroll Runs',str_contains($page['body'],'page=payroll-runs'));
$denied=req($empJar,'GET','/index.php?page=payroll-runs');
check('employee without payroll.process is refused',$denied['code']!==200 || !str_contains($denied['body'],'Compute payroll'));
$post=['action'=>'payroll_v2_run_compute','cutoff_id'=>$cut,'pay_date'=>'2092-06-05'];
req($hrJar,'POST','/index.php?page=payroll-runs',$post);
$runId=fn()=>(int)$pdo->query("SELECT id FROM payroll_runs WHERE cutoff_id=$cut AND status<>'VOIDED'")->fetchColumn();
check('compute without CSRF is refused',$runId()===0);
$r=req($hrJar,'POST','/index.php?page=payroll-runs',$post+['csrf'=>csrf($page['body'])]);
$id=$runId();
check('compute creates a run and redirects to it',$id>0 && str_contains($r['head'],'page=payroll-run'));
$view=req($hrJar,'GET',"/index.php?page=payroll-run&id=$id")['body'];
check('register shows the employee escaped with net pay',str_contains($view,'&lt;i&gt;Run&lt;/i&gt;') && !str_contains($view,'<i>Run</i>') && str_contains($view,'₱4,000.00'));
check('the person who computed sees no Release button',!str_contains($view,'payroll_v2_run_release') && str_contains($view,'different person'));
$csv=req($hrJar,'GET',"/index.php?page=payroll-run&id=$id&format=csv");
check('register CSV downloads',str_contains($csv['head'],'text/csv') && str_contains($csv['body'],"ZZRUNH-$sfx") && str_contains($csv['body'],'Withholding tax'));
req($hrJar,'POST','/index.php?page=payroll-run',['csrf'=>csrf($view),'action'=>'payroll_v2_run_release','run_id'=>$id]);
check('HR without payroll.mark_paid cannot release',$pdo->query("SELECT status FROM payroll_runs WHERE id=$id")->fetchColumn()==='COMPUTED');
$slipBefore=req($empJar,'GET','/index.php?page=profile-payslip')['body'];
check('unreleased payroll is not on the payslip page',str_contains($slipBefore,'once payroll is released'));
$pv=req($payJar,'GET',"/index.php?page=payroll-run&id=$id")['body'];
check('payroll user sees Release',str_contains($pv,'payroll_v2_run_release'));
req($payJar,'POST','/index.php?page=payroll-run',['csrf'=>csrf($pv),'action'=>'payroll_v2_run_release','run_id'=>$id]);
$run=$pdo->query("SELECT status,entry_id FROM payroll_runs WHERE id=$id")->fetch();
check('second person releases and a draft JE is linked',$run['status']==='RELEASED' && (int)$run['entry_id']>$startEntry && $pdo->query('SELECT status FROM acct_journal_entries WHERE id='.(int)$run['entry_id'])->fetchColumn()==='DRAFT');
$slips=req($empJar,'GET','/index.php?page=profile-payslip')['body'];
$slipId=(int)$pdo->query("SELECT id FROM payroll_run_employees WHERE run_id=$id AND employee_id=$emp")->fetchColumn();
check('employee sees the released payslip',str_contains($slips,"slip=$slipId"));
$one=req($empJar,'GET',"/index.php?page=profile-payslip&slip=$slipId")['body'];
check('payslip detail shows lines and net',str_contains($one,'Regular pay') && str_contains($one,'Net pay'));
$other=req($otherJar,'GET',"/index.php?page=profile-payslip&slip=$slipId")['body'];
check('another employee cannot open it',!str_contains($other,'Regular pay'));
$setup=req($payJar,'GET','/index.php?page=payroll-setup');
check('Payroll Setup opens on Employee rates in review-only mode',str_contains($setup['head'],'tab=salary') || str_contains($setup['body'],'Effective-dated salary rates'));
$rules=req($payJar,'GET','/index.php?page=payroll-setup&tab=rules')['body'];
check('OT & holidays tab opens, sites tab hidden',str_contains($rules,'Company OT and night rules') && !str_contains($rules,'Sites &amp; approvers'));

echo $fail?"\n$fail FAILED\n":"\nALL PASSED\n";
exit($fail?1:0);
