<?php
declare(strict_types=1);
// Synthetic fixtures only; never run against the configured laptop database.
$dbName=getenv('HRIS_TEST_DB')?:'';
if(!preg_match('/^hris_slice15_test_\d{8}_\d{6}$/',$dbName)){fwrite(STDERR,"Set HRIS_TEST_DB to the isolated Slice-15 database.\n");exit(2);}
$_SERVER['REMOTE_ADDR']='127.0.0.1';
$sessionDir=dirname(__DIR__).'/storage/slice15-private/sessions';
if(!is_dir($sessionDir))mkdir($sessionDir,0700,true);
session_save_path($sessionDir);
require_once dirname(__DIR__).'/app/bootstrap.php';
$connection=cfg('db');if($dbName===$connection['database'])exit(2);$connection['database']=$dbName;
$GLOBALS['pdo']=Database::connect($connection);$GLOBALS['config']['db']['database']=$dbName;
$GLOBALS['config']['mail']=[]; // All isolated tests keep external email delivery disabled.
require_once dirname(__DIR__).'/app/EmployeeApproverAssignmentService.php';
require_once dirname(__DIR__).'/app/TicketPolicy.php';
$pass=0;$fail=0;
function check(string $name,bool $ok): void{global $pass,$fail;$ok?$pass++:$fail++;echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;}
function rejects(callable $fn): bool{try{$fn();return false;}catch(Throwable){return true;}}
function finish(): never{global $pass,$fail;echo "RESULT $pass passed, $fail failed\n";exit($fail?1:0);}
function row(string $sql,array $args=[]): array{$q=db()->prepare($sql);$q->execute($args);return $q->fetch()?:[];}
function insert(string $sql,array $args=[]): int{db()->prepare($sql)->execute($args);return (int)db()->lastInsertId();}
function act(array $user): void{$_SESSION['user']=$user;unset($_SESSION['auth_session_token']);Auth::clearPermissionCache();}
function fixtures(): array{
    $tag='T15_'.strtoupper(bin2hex(random_bytes(4)));$out=['tag'=>$tag];
    $out['company']=insert('INSERT INTO business_units(code,name,company_group) VALUES(?,?,"SW")',[$tag,'Slice 15 Test Company']);
    $out['legal']=(int)db()->query('SELECT id FROM legal_entities ORDER BY id LIMIT 1')->fetchColumn();
    foreach(['hr'=>'hr','manager'=>'employee','adl'=>'employee','employee'=>'employee','outsider'=>'employee'] as $key=>$portal){
        $role=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,?)',[$tag.'_'.$key,'Slice15 '.$key,$portal]);
        $permissions=match($key){'hr'=>['tickets.approvers.manage','tickets.review_hr','employees.manage','payroll.approve_hr'],'manager'=>['payroll.approve_manager'],'adl'=>['payroll.approve_adl'],default=>['payroll.request_self','tickets.request_self']};
        foreach($permissions as $code){$p=row('SELECT id FROM permissions WHERE code=?',[$code]);if($p)db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);}
        $email=strtolower($tag.'_'.$key).'@example.test';
        $id=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$role,'Slice15 Test '.$key,$email,password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
        $out[$key]=['id'=>$id,'role_id'=>$role,'role_code'=>$tag.'_'.$key,'role_portal'=>$portal,'name'=>'Slice15 Test '.$key,'role_name'=>'Test '.$key,'email'=>$email,'client_id'=>null];
    }
    $out['ho']=insert('INSERT INTO branches(code,name,site_type) VALUES(?,?,"HEAD_OFFICE")',[$tag.'_HO','Slice15 Test Head Office']);
    $out['branch']=insert('INSERT INTO branches(code,name,site_type) VALUES(?,?,"BRANCH")',[$tag.'_BR','Slice15 Test Branch']);
    foreach(['ho','branch'] as $site)db()->prepare('INSERT INTO payroll_site_settings(branch_id,workplace) VALUES(?,?)')->execute([$out[$site],$site==='ho'?'HEAD_OFFICE':'BRANCH']);
    foreach(['manager_employee'=>['manager','ho'],'adl_employee'=>['adl','branch'],'requester'=>['employee','ho'],'branch_requester'=>['outsider','branch']] as $key=>[$account,$site]){
        $out[$key]=insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,branch_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,?,"ACTIVE",?)',[$tag.'_'.$key,'Slice15','Test '.$key,$out[$account]['id'],$out[$site],$out['legal'],$out['company'],'2020-01-01']);
    }
    return $out;
}
