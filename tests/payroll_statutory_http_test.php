<?php
declare(strict_types=1);
/**
 * Payroll engine P1 over HTTP: Statutory Tables sidebar link, permission gate, CSRF, draft by one person, verify by another.
 * Scratch DB with migration 045: HRIS_TEST_DB=<scratch> php tests/payroll_statutory_http_test.php . App copied to a temp folder, php -S port 8134.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if($dbName==='' || in_array($dbName,['pmbsi_hris','hris_clean'],true)) { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy.\n"); exit(2); }
$root=dirname(__DIR__); $port=8134;
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_stat_http_'.bin2hex(random_bytes(4));
$pdo=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='payroll_stat_tables'")->fetchColumn()) { fwrite(STDERR,"Run migration 045 on the scratch copy first.\n"); exit(2); }
function copyTree(string $from,string $to): void { @mkdir($to,0777,true); foreach(new DirectoryIterator($from) as $f){ if($f->isDot()) continue; $f->isDir()?copyTree($f->getPathname(),$to.'/'.$f->getFilename()):copy($f->getPathname(),$to.'/'.$f->getFilename()); } }
function rmTree(string $dir): void { if(!is_dir($dir)) return; foreach(new DirectoryIterator($dir) as $f){ if($f->isDot()) continue; $f->isDir()?rmTree($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir); }
foreach(['app','config','public','vendor'] as $d) copyTree($root.'/'.$d,$tmp.'/'.$d);
copy($root.'/index.php',$tmp.'/index.php'); copy($root.'/router.php',$tmp.'/router.php');
file_put_contents($tmp.'/config/config.local.php',"<?php\nreturn ['app'=>['debug'=>true],'mail'=>[],'security'=>['credential_key'=>".var_export(base64_encode(random_bytes(32)),true)."],'db'=>['host'=>'127.0.0.1','port'=>'3306','database'=>".var_export($dbName,true).",'username'=>'root','password'=>'','charset'=>'utf8mb4']];\n");
$proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$tmp,$tmp.'/router.php'],[['pipe','r'],['file',$tmp.'/server.log','w'],['file',$tmp.'/server.log','a']],$pipes,$tmp);
$users=[];
register_shutdown_function(function() use($proc,$tmp,$pdo,&$users){
    if($users){ $in=implode(',',array_map('intval',$users)); $pdo->exec("DELETE FROM payroll_stat_tables WHERE entered_by IN ($in)"); $pdo->exec("DELETE FROM audit_logs WHERE user_id IN ($in)"); foreach(['user_sessions','login_attempts'] as $t){ try{$pdo->exec("DELETE FROM $t WHERE user_id IN ($in)");}catch(Throwable){} } $pdo->exec("DELETE FROM users WHERE id IN ($in)"); }
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
    return ['code'=>$code,'body'=>substr($raw,$size)];
}
function csrf(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/',$html,$m)?html_entity_decode($m[1]):''; }
function login(string $email): string {
    $jar=tempnam(sys_get_temp_dir(),'jar'); $p=req($jar,'GET','/index.php?page=login');
    req($jar,'POST','/index.php?page=login',['csrf'=>csrf($p['body']),'action'=>'login','email'=>$email,'password'=>'Stat-Test-2026!']);
    return $jar;
}
$sfx=bin2hex(random_bytes(3)); $hash=password_hash('Stat-Test-2026!',PASSWORD_DEFAULT);
$mk=function(string $role,string $who) use($pdo,$sfx,$hash,&$users): string {
    $email="zz-stat-$who-$sfx@example.invalid";
    $pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT id,?,?,? FROM roles WHERE code=?')->execute(["Stat $who",$email,$hash,$role]);
    $users[]=(int)$pdo->lastInsertId(); return $email;
};
$a=login($mk('PAYROLL','prep')); $b=login($mk('HRIS_ADMIN','verify')); $hr=login($mk('HR_USER','hr'));
$prepId=$users[0];

$page=req($a,'GET','/index.php?page=payroll-statutory');
check('payroll user opens Statutory Tables',$page['code']===200 && str_contains($page['body'],'New table version'));
check('sidebar links Statutory Tables for payroll.configure',str_contains($page['body'],'page=payroll-statutory'));
check('empty state explains nothing is computed yet',str_contains($page['body'],'No statutory tables yet'));
$hrPage=req($hr,'GET','/index.php?page=payroll-statutory');
check('HR without payroll.configure is refused',$hrPage['code']!==200 || !str_contains($hrPage['body'],'New table version'));
check('HR sidebar has no Statutory Tables link',!str_contains(req($hr,'GET','/index.php?page=hr-dashboard')['body'],'page=payroll-statutory'));

$post=['action'=>'payroll_v2_stat_save','table_id'=>0,'table_code'=>'SSS','pay_frequency'=>'MONTHLY','version_label'=>'<b>zz</b>','effective_from'=>'2093-01-01','source_reference'=>'ZZ circular','rows'=>"0\t999.99\t10\t20\n1000\t\t30\t60"];
req($a,'POST','/index.php?page=payroll-statutory',$post);
$count=fn()=>(int)$pdo->query("SELECT COUNT(*) FROM payroll_stat_tables WHERE entered_by=$prepId")->fetchColumn();
check('save without CSRF is refused',$count()===0);
req($a,'POST','/index.php?page=payroll-statutory',$post+['csrf'=>csrf($page['body'])]);
$id=(int)$pdo->query("SELECT MAX(id) FROM payroll_stat_tables WHERE entered_by=$prepId")->fetchColumn();
check('save creates a draft with 2 rows',$id>0 && (int)$pdo->query("SELECT COUNT(*) FROM payroll_stat_table_rows WHERE table_id=$id")->fetchColumn()===2);
$bad=req($a,'POST','/index.php?page=payroll-statutory',array_merge($post,['csrf'=>csrf($page['body']),'effective_from'=>'2093-02-01','rows'=>"0 100 1 2\n50 200 1 2"]));
$after=req($a,'GET','/index.php?page=payroll-statutory')['body'];
check('overlapping rows refused with a message and the form kept',$count()===1 && str_contains($after,'overlaps') && str_contains($after,'50 200 1 2'));
$list=req($a,'GET','/index.php?page=payroll-statutory')['body'];
check('label is escaped',str_contains($list,'&lt;b&gt;zz') && !str_contains($list,'<b>zz</b>'));
check('enterer sees no Verify button',str_contains($list,'Needs a second person to verify') && !str_contains($list,'payroll_v2_stat_verify'));
$vPage=req($b,'GET','/index.php?page=payroll-statutory')['body'];
check('second person sees Verify',str_contains($vPage,'payroll_v2_stat_verify'));
req($b,'POST','/index.php?page=payroll-statutory',['csrf'=>csrf($vPage),'action'=>'payroll_v2_stat_verify','table_id'=>$id]);
check('second person verifies',$pdo->query("SELECT verified_by FROM payroll_stat_tables WHERE id=$id")->fetchColumn()==$users[1]);
$view=req($a,'GET',"/index.php?page=payroll-statutory&view=$id")['body'];
check('view shows rows and Verified badge',str_contains($view,'999.99') && str_contains($view,'Verified'));
$edit=req($a,'GET',"/index.php?page=payroll-statutory&edit=$id")['body'];
check('verified version does not open for editing',str_contains($edit,'New table version'));

echo $fail?"\n$fail FAILED\n":"\nALL PASSED\n";
exit($fail?1:0);
