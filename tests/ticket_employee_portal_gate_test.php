<?php
declare(strict_types=1);
// Employee-portal gate: index.php `employee-*` pages follow roles.portal, not a hard-coded role list. Synthetic fixtures only.
require __DIR__.'/ticket_test_support.php';
$f=fixtures();
$tag=$f['tag'];

function create_user(string $roleCode,string $tag,int $roleId,array $f,bool $linkEmployee):int {
    $email=strtolower($tag.'_gate_'.$roleCode).'@example.test';
    $id=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$roleId,'Slice15 Test gate '.$roleCode,$email,password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
    if($linkEmployee)insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,branch_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,?,"ACTIVE",?)',[$tag.'_gate_'.$roleCode,'Slice15','Test gate '.$roleCode,$id,$f['branch'],$f['legal'],$f['company'],'2020-01-01']);
    return $id;
}
function child(int $userId,string $mode,string $page):array {
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/ticket_employee_portal_gate_run.php').' '.$userId.' '.$mode.' '.escapeshellarg($page);
    $out=[];exec($cmd.' 2>&1',$out);$text=implode("\n",$out);
    preg_match('/HTTP=(\d+)/',$text,$m);
    return ['passed'=>str_contains($text,'GATE_PASSED'),'http'=>(int)($m[1]??0),'text'=>$text];
}
$roleId=fn(string $code):int=>(int)row('SELECT id FROM roles WHERE code=?',[$code])['id'];

// A role HR could create tomorrow: employee portal, SC's permissions, a code no PHP file has ever heard of.
$newCode=$tag.'_NEWEMP';
$newRole=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,"employee")',[$newCode,'Slice15 brand-new employee role']);
db()->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,permission_id FROM role_permissions WHERE role_id=?')->execute([$newRole,$roleId('SC')]);

$users=[];
foreach(['ADL','OIC','SC','TC','EMPLOYEE','MANAGER','COORDINATOR','HR_USER','CLIENT_USER','HRIS_ADMIN','SUPER_ADMIN'] as $code)$users[$code]=create_user($code,$tag,$roleId($code),$f,true);
$users[$newCode]=create_user($newCode,$tag,$newRole,$f,true);

// 1. The gate itself, read from index.php and run per account.
$allowed=['ADL','OIC','SC','TC',$newCode,'EMPLOYEE','MANAGER','HRIS_ADMIN','SUPER_ADMIN'];
foreach($allowed as $code)check('Gate lets '.$code.' reach employee-dashboard',child($users[$code],'gate','employee-dashboard')['passed']);
foreach(['COORDINATOR','HR_USER','CLIENT_USER'] as $code){
    $r=child($users[$code],'gate','employee-dashboard');
    check('Gate stops '.$code.' with 403',!$r['passed']&&$r['http']===403);
}
check('Gate also covers other employee-* pages (employee-requests) for ADL',child($users['ADL'],'gate','employee-requests')['passed']);

// 2. The ticket pages (permission-based, no role list) for the same accounts.
foreach(['ADL','OIC','SC','TC',$newCode] as $code){
    foreach(['hr-requests-my','hr-request-new'] as $page){
        $r=child($users[$code],'page',$page);
        check($code.' opens '.$page,$r['http']===200&&!str_contains($r['text'],'Filing permission is required')&&!str_contains($r['text'],'link your active Employee'));
    }
}
foreach(['ADL','TC','OIC'] as $code){
    $r=child($users[$code],'page','hr-request-inbox');
    check($code.' opens the manager/ADL inbox (hr-request-inbox)',$r['http']===200);
}
foreach(['SC',$newCode] as $code){
    $r=child($users[$code],'page','hr-request-inbox');
    check($code.' has no approver permission, so the inbox says so (403 by permission, not by role code)',$r['http']===403&&str_contains($r['text'],'Manager or ADL approval access is required'));
}
foreach(['COORDINATOR','CLIENT_USER'] as $code){
    foreach(['hr-requests-my','hr-request-new'] as $page){
        $r=child($users[$code],'page',$page);
        check($code.' is refused '.$page,$r['http']===403&&str_contains($r['text'],'Filing permission is required'));
    }
}
finish();
