<?php
declare(strict_types=1);
// hr-user-role-change (SPEC-hr-user-role-change.md). Synthetic data only; run with HRIS_TEST_DB on a scratch copy. Grants the permission itself, mirroring migration 037.
require __DIR__.'/ticket_test_support.php';
$f=fixtures();
db()->prepare("INSERT IGNORE INTO permissions(code,name,module,description,sort_order) VALUES('users.assign_employee_role','Assign Employee Portal Roles','Users','test',36)")->execute();
db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code="users.assign_employee_role"')->execute([$f['hr']['role_id']]);
$role=fn(string $c)=>(int)row('SELECT id FROM roles WHERE code=?',[$c])['id'];
$roleOf=fn(int $uid)=>(string)row('SELECT r.code c FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$uid])['c'];
$audits=fn(int $uid)=>(int)row('SELECT COUNT(*) c FROM audit_logs WHERE action="CHANGE_USER_ROLE" AND record_type="user" AND record_id=?',[$uid])['c'];
$target=$f['employee']['id']; $manager=$role('MANAGER'); $hrRole=$role('HR_USER');

act($f['hr']);
$options=array_column(FoundationRepository::employeeAssignableRoles(),'code');
check('options: only Employee portal roles, from the roles table',$options!==[]&&in_array('MANAGER',$options,true)&&!in_array('HR_USER',$options,true)&&!in_array('SUPER_ADMIN',$options,true)&&!in_array('ADMIN',$options,true));
$before=$audits($target);
FoundationRepository::changeEmployeeUserRole($target,$manager);
check('HR sets an employee account to Manager (permissions HR lacks)',$roleOf($target)==='MANAGER'&&$audits($target)===$before+1);
FoundationRepository::changeEmployeeUserRole($target,$manager);
check('same role is a no-op (no second audit)',$audits($target)===$before+1);
check('general path unchanged: users.manage-less HR still cannot use changeUserRole for Manager',rejects(fn()=>FoundationRepository::changeUserRole($f['adl']['id'],$manager)));
check('HR cannot assign an HR portal role',rejects(fn()=>FoundationRepository::changeEmployeeUserRole($f['adl']['id'],$hrRole)));
check('HR cannot assign Super Admin',rejects(fn()=>FoundationRepository::changeEmployeeUserRole($f['adl']['id'],$role('SUPER_ADMIN'))));
check('HR cannot change own account',rejects(fn()=>FoundationRepository::changeEmployeeUserRole($f['hr']['id'],$manager)));
check('HR cannot change an HR-portal target',rejects(fn()=>FoundationRepository::changeEmployeeUserRole((int)insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$hrRole,'Zz hr2','zz_hr2_'.bin2hex(random_bytes(3)).'@example.test',password_hash('x-Test-Only!9',PASSWORD_DEFAULT)]),$manager)));
check('invalid ids are refused',rejects(fn()=>FoundationRepository::changeEmployeeUserRole(0,$manager))&&rejects(fn()=>FoundationRepository::changeEmployeeUserRole($f['adl']['id'],0)));
check('canAssignEmployeeRole: employee target yes, self no',FoundationRepository::canAssignEmployeeRole(['id'=>$f['adl']['id'],'portal'=>'employee','role_code'=>'EMPLOYEE'])&&!FoundationRepository::canAssignEmployeeRole(['id'=>$f['hr']['id'],'portal'=>'employee','role_code'=>'EMPLOYEE']));

act($f['outsider']);
check('actor without the permission: no options, change refused',FoundationRepository::employeeAssignableRoles()===[]&&rejects(fn()=>FoundationRepository::changeEmployeeUserRole($f['adl']['id'],$manager)));
finish();
