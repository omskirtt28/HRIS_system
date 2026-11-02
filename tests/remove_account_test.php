<?php
declare(strict_types=1);
// Remove portal account (SPEC-remove-portal-account.md). Synthetic data only; run with HRIS_TEST_DB on a copy that has migration 021.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/EmployeeAccountService.php';
$f=fixtures();
$GLOBALS['config']['security']['credential_key']=base64_encode(random_bytes(32));
$root=dirname(__DIR__);

$sfx=strtr(bin2hex(random_bytes(3)),'0123456789','ghijklmnop');     // letters only: names must survive the email local-part cleaning
function pe(string $first,string $last,array $extra=[]): int {
    static $n=0;$n++;
    return EmployeeRepository::create(array_replace(['employee_no'=>'R-'.bin2hex(random_bytes(3)).$n,'first_name'=>$first,'last_name'=>$last,'work_company'=>'PMBSI','require_work_company'=>true,
        'employment_type_id'=>1,'require_employee_status'=>true,'hire_date'=>'2026-11-02','department_id'=>0,'position_id'=>0,'branch_id'=>0,'user_id'=>0],$extra));
}
function emp(int $id): array { return row('SELECT employee_no,first_name,last_name,status,hire_date,work_company,user_id,company_email FROM employees WHERE id=?',[$id]); }
function usr(int $id): array { return row('SELECT * FROM users WHERE id=?',[$id]); }
function counts(): array { return [(int)row('SELECT COUNT(*) c FROM users')['c'],(int)row('SELECT COUNT(*) c FROM employees')['c']]; }
function listed(int $id): bool { return in_array($id,array_map('intval',array_column(FoundationRepository::users(),'id')),true); }

db()->beginTransaction();   // everything below is rolled back at the end
$hr=$f['hr'];                                                    // HR actor (portal hr)
$superAdmin=['id'=>$f['hr']['id'],'role_id'=>$f['hr']['role_id'],'role_code'=>'SUPER_ADMIN','role_portal'=>'admin','name'=>'t','role_name'=>'t','email'=>'t@example.test','client_id'=>null];
act($hr);

// --- removing an employee's account ------------------------------------------------------------------
$e=pe('Zzr'.$sfx,'Removable');
$made=EmployeeAccountService::issueForExisting($e);
$uid=(int)$made['user_id'];$email=$made['email'];
$plain=EmployeeAccountService::decrypt(usr($uid)['password_enc']);
$beforeEmp=emp($e);$total=counts();
db()->prepare('INSERT INTO audit_logs(user_id,module,action,record_type,record_id) VALUES(?,?,?,?,?)')->execute([$uid,'Test','HISTORY','user',$uid]); // history that points at the account
FoundationRepository::removeUser($uid);
$u=usr($uid);$after=emp($e);
check('remove: account is marked removed and inactive',$u['removed_at']!==null&&$u['status']!=='ACTIVE');
check('remove: original email kept aside, login email freed',$u['removed_email']===$email&&$u['email']!==$email&&(int)row('SELECT COUNT(*) c FROM users WHERE email=?',[$email])['c']===0);
check('remove: employee unlinked and company email cleared, nothing else on the 201 changed',$after['user_id']===null&&$after['company_email']===null
    &&array_diff_assoc($beforeEmp,$after)===['user_id'=>$beforeEmp['user_id'],'company_email'=>$beforeEmp['company_email']]);
check('remove: no row deleted (users and employees counts unchanged)',counts()===$total);
check('remove: history row still points at the account',(int)row('SELECT COUNT(*) c FROM audit_logs WHERE user_id=? AND action="HISTORY"',[$uid])['c']===1);
check('remove: audit row written without the password',(int)row('SELECT COUNT(*) c FROM audit_logs WHERE action="REMOVE_USER" AND record_id=? AND details_json NOT LIKE ?',[$uid,'%'.$plain.'%'])['c']===1);
check('remove: gone from the user list, present in the removed list',!listed($uid)&&in_array($uid,array_map('intval',array_column(FoundationRepository::removedUsers(),'id')),true));
$_SESSION['user']=null;unset($_SESSION['user']);
check('remove: the old login no longer works',!Auth::login($email,$plain));
check('remove: double removal is refused',rejects(fn()=>FoundationRepository::removeUser($uid)));
check('remove: removed account cannot be re-activated, reset or re-roled',rejects(fn()=>FoundationRepository::toggleUserStatus($uid))&&usr($uid)['status']!=='ACTIVE'
    &&rejects(fn()=>FoundationRepository::resetUserPassword($uid,'Temp-Password1'))&&rejects(fn()=>FoundationRepository::changeUserRole($uid,(int)$u['role_id'])));
act($hr);

// --- the point of the feature: fix the 201, generate again -----------------------------------------------
$again=EmployeeAccountService::issueForExisting($e);
check('regenerate: same employee gets the same email again and a new account',$again['email']===$email&&(int)$again['user_id']!==$uid&&(int)emp($e)['user_id']===(int)$again['user_id']);

// --- guards --------------------------------------------------------------------------------------------
$total=counts();
check('guard: own account cannot be removed',rejects(fn()=>FoundationRepository::removeUser((int)$hr['id'])));
check('guard: unknown account is refused',rejects(fn()=>FoundationRepository::removeUser(999999999)));
$hrUser=(int)insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="HR_USER"),?,?,?,"ACTIVE")',['Zz Other HR','zz_hr_'.$sfx.'@example.test',password_hash('x',PASSWORD_DEFAULT)]);
$adminUser=(int)insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="HRIS_ADMIN"),?,?,?,"ACTIVE")',['Zz Admin','zz_admin_'.$sfx.'@example.test',password_hash('x',PASSWORD_DEFAULT)]);
$sa=(int)insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="SUPER_ADMIN"),?,?,?,"ACTIVE")',['Zz Super','zz_sa_'.$sfx.'@example.test',password_hash('x',PASSWORD_DEFAULT)]);
check('guard: HR cannot remove an Admin-portal account',rejects(fn()=>FoundationRepository::removeUser($adminUser))&&usr($adminUser)['removed_at']===null);
check('guard: a Super Admin account can never be removed (HR)',rejects(fn()=>FoundationRepository::removeUser($sa))&&usr($sa)['removed_at']===null);
FoundationRepository::removeUser($hrUser);
check('guard: HR can remove an HR-portal account (as approved)',usr($hrUser)['removed_at']!==null);
act($superAdmin);
FoundationRepository::removeUser($adminUser);
check('guard: Super Admin can remove an Admin-portal account',usr($adminUser)['removed_at']!==null);
check('guard: Super Admin still cannot remove a Super Admin account',rejects(fn()=>FoundationRepository::removeUser($sa))&&usr($sa)['removed_at']===null);
act($hr);

// --- account with no employee (the "Not linked" rows) -----------------------------------------------------
$orphan=(int)insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="EMPLOYEE"),?,?,?,"ACTIVE")',['Zz Orphan','zz_orphan_'.$sfx.'@example.test',password_hash('x',PASSWORD_DEFAULT)]);
FoundationRepository::removeUser($orphan);
check('orphan: account without an employee removes cleanly',usr($orphan)['removed_at']!==null&&usr($orphan)['removed_employee_id']===null);

// --- restore -----------------------------------------------------------------------------------------------
$e2=pe('Zzs'.$sfx,'Restorable');$m2=EmployeeAccountService::issueForExisting($e2);$u2=(int)$m2['user_id'];
FoundationRepository::removeUser($u2);
check('restore: refused for an account that is not removed',rejects(fn()=>FoundationRepository::restoreUser((int)$again['user_id'])));
$relinked=FoundationRepository::restoreUser($u2);
check('restore: email back, active, listed again, employee relinked',$relinked===true&&listed($u2)&&usr($u2)['email']===$m2['email']&&usr($u2)['status']==='ACTIVE'&&usr($u2)['removed_at']===null
    &&(int)emp($e2)['user_id']===$u2&&emp($e2)['company_email']===$m2['email']);
FoundationRepository::removeUser($u2);
$third=EmployeeAccountService::issueForExisting($e2);   // a new account took the email
check('restore: refused while another account uses the email',rejects(fn()=>FoundationRepository::restoreUser($u2))&&usr($u2)['removed_at']!==null);
FoundationRepository::removeUser((int)$third['user_id']);
$other=(int)insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="EMPLOYEE"),?,?,?,"ACTIVE")',['Zz Other','zz_other_'.$sfx.'@example.test',password_hash('x',PASSWORD_DEFAULT)]);
db()->prepare('UPDATE employees SET user_id=? WHERE id=?')->execute([$other,$e2]);   // employee now has a different account
check('restore: account comes back unlinked when the employee already has another account',FoundationRepository::restoreUser($u2)===false&&(int)emp($e2)['user_id']===$other);

// --- restore must not re-activate what was deactivated before removal ----------------------------------------
$e3=pe('Zzt'.$sfx,'Deactivated');$u3=(int)EmployeeAccountService::issueForExisting($e3)['user_id'];
FoundationRepository::toggleUserStatus($u3);   // Super Admin or HR with users.manage deactivated it first
check('restore status: account is inactive before removal',usr($u3)['status']==='INACTIVE');
FoundationRepository::removeUser($u3);FoundationRepository::restoreUser($u3);
check('restore status: a deactivated account stays INACTIVE after remove and restore (no re-activation bypass)',usr($u3)['status']==='INACTIVE'&&usr($u3)['removed_status']===null&&usr($u3)['removed_at']===null);

// --- every other list that shows accounts hides removed ones -------------------------------------------------
check('sweep: recruitment user list hides removed accounts',!in_array($uid,array_map('intval',array_column(RecruitmentRepository::users(),'id')),true));
check('sweep: role user_count ignores removed accounts',(int)array_column(FoundationRepository::roles(),'user_count','id')[(int)usr($uid)['role_id']]===(int)row('SELECT COUNT(*) c FROM users WHERE role_id=? AND removed_at IS NULL',[(int)usr($uid)['role_id']])['c']);

// --- wiring ----------------------------------------------------------------------------------------------------
$idx=file_get_contents($root.'/index.php');
check('wiring: both actions exist, are permission-gated and return to Users & Access',str_contains($idx,"'remove_user'")&&str_contains($idx,"'restore_user'")&&substr_count($idx,"Auth::requirePermission('users.remove')")>=2
    &&preg_match("/'toggle_user_status','reset_user_password','change_user_role'[^=]*'remove_user'[^=]*=> 'admin-users'/",$idx)===1);
check('wiring: menu has Remove account with a confirm, and "No available actions" only remains as the fallback',str_contains($idx,'Remove account')&&str_contains($idx,'confirm('));
check('permission users.remove is granted to HR_USER',(int)row('SELECT COUNT(*) c FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id JOIN roles r ON r.id=rp.role_id WHERE p.code="users.remove" AND r.code="HR_USER"')['c']===1);

db()->rollBack();
finish();
