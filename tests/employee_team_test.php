<?php
declare(strict_types=1);
// My Team roster (plan 2026-11-05-hr-role-change-retail-parity). Synthetic data; run on a scratch copy that has migration 037.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/EmployeeTeamService.php';
$f=fixtures();
function team_user(string $roleCode,string $label): array {
    $r=row('SELECT id,code,portal FROM roles WHERE code=?',[$roleCode]);
    $email='zz_'.$label.'_'.bin2hex(random_bytes(3)).'@example.test';
    $id=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$r['id'],'Zz '.$label,$email,password_hash('Team-Test-Only!9',PASSWORD_DEFAULT)]);
    return ['id'=>$id,'role_id'=>(int)$r['id'],'role_code'=>$r['code'],'role_portal'=>$r['portal'],'name'=>'Zz '.$label,'role_name'=>$r['code'],'email'=>$email,'client_id'=>null];
}
function emp(string $label,?int $userId,?int $managerEmp=null,string $status='ACTIVE'): int {
    return insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,manager_employee_id,status,hire_date) VALUES(?,?,?,?,?,?,"2020-01-01")',['ZT'.bin2hex(random_bytes(4)),'Zz',$label,$userId,$managerEmp,$status]);
}
$rmA=team_user('RETAIL_MANAGER','rmA'); $rmB=team_user('RETAIL_MANAGER','rmB'); $plain=team_user('EMPLOYEE','plain'); $lonely=team_user('RETAIL_MANAGER','lonely');
$eA=emp('rmA',$rmA['id']); $eB=emp('rmB',$rmB['id']); emp('plain',$plain['id']); emp('lonely',$lonely['id']);
$adl1=emp('adl1',null,$eA); $adl2=emp('adl2',null,$eA); $adlB=emp('adlB',null,$eB); emp('gone',null,$eA,'RESIGNED');
$names=fn(array $r)=>array_column($r['rows'],'last_name'); $sorted=function(array $a){sort($a);return $a;};

act($rmA); $a=EmployeeTeamService::roster($rmA['id']);
check('Retail Manager A sees only own ADLs (no requests, no accounts needed)',$sorted($names($a))===['adl1','adl2']&&$a['total']===2);
act($rmB); check('Retail Manager B sees only B\'s ADL',$names(EmployeeTeamService::roster($rmB['id']))===['adlB']);
act($rmA); check('viewer is excluded and inactive people are hidden',!in_array('rmA',$names($a),true)&&!in_array('gone',$names($a),true));
check('search narrows within scope only',$names(EmployeeTeamService::roster($rmA['id'],'adl1'))===['adl1']&&EmployeeTeamService::roster($rmA['id'],'adlB')['total']===0);
check('LIKE wildcards in search are literal',EmployeeTeamService::roster($rmA['id'],'%')['total']===0);
check('paging: per-page 1 returns one row, total unchanged',count(EmployeeTeamService::roster($rmA['id'],'',2,1)['rows'])===1&&EmployeeTeamService::roster($rmA['id'],'',1,1)['total']===2);
act($lonely); check('no assignments = empty roster, never everyone',EmployeeTeamService::roster($lonely['id'])['total']===0);
act($plain); check('ordinary employee has no roster authority',!EmployeeTeamService::canView()&&EmployeeTeamService::roster($plain['id'])['total']===0);
finish();
