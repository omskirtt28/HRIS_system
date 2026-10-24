<?php
declare(strict_types=1);
// Migration 010: per-type filing permissions and role/user admin escalation guards. Synthetic fixtures only.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/TicketService.php';
$f=fixtures();
function form(string $code,array $extra=[]):array {
    return array_replace(['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'],'submission_key'=>bin2hex(random_bytes(32))],$extra);
}
function perm(int $role,string $code,bool $on):void {
    $p=row('SELECT id FROM permissions WHERE code=?',[$code]);
    if($on)db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);
    else db()->prepare('DELETE FROM role_permissions WHERE role_id=? AND permission_id=?')->execute([$role,$p['id']]);
    Auth::clearPermissionCache();
}
function codes():array { $c=array_column(TicketRepository::types(),'code');sort($c);return $c; }
function audited(string $action,string $type,int $id):array { return row('SELECT details_json FROM audit_logs WHERE action=? AND record_type=? AND record_id=? ORDER BY id DESC LIMIT 1',[$action,$type,$id]); }
$staff=['ATM_REQUEST','ID_REQUEST','INCIDENT_REPORT','PAYROLL_CONCERN','UNIFORM_REQUEST'];
check('migration 010 permissions exist',count(db()->query('SELECT id FROM permissions WHERE code LIKE "ticket.file.%"')->fetchAll())===7);

// Access matrix. The outsider fixture becomes Staff by dropping the lead-only types.
foreach(['ticket.file.manpower','ticket.file.netsuite'] as $code)perm($f['outsider']['role_id'],$code,false);
act($f['outsider']);check('Staff sees exactly the five staff types',codes()===$staff);
foreach(['MANPOWER_REQUEST','NETSUITE_CONCERN','BRANCH_TRANSFER'] as $code)check('Staff server-side create denied: '.$code,rejects(fn()=>TicketService::create(form($code,['concern'=>'x','reason'=>'x']),true)));
$atm=TicketService::create(form('ATM_REQUEST',['reason'=>'Synthetic staff request']),false);check('Staff can file ATM',$atm>0);
perm($f['outsider']['role_id'],'ticket.file.atm',false);act($f['outsider']);
check('Edit hidden after type access removed',!TicketService::permissions(TicketRepository::find($atm))['edit']);
check('Edit/submit denied server-side after access removed',rejects(fn()=>TicketService::edit($atm,(int)TicketRepository::find($atm)['version'],form('ATM_REQUEST',['reason'=>'Changed']),true)));
perm($f['outsider']['role_id'],'ticket.file.payroll_concern',false);act($f['outsider']);
check('Payroll Concern create denied without its permission',rejects(fn()=>TicketService::create(form('PAYROLL_CONCERN',['concern_date'=>date('Y-m-d'),'explanation'=>'x']),true))&&!in_array('PAYROLL_CONCERN',codes(),true));
act($f['employee']);check('Lead sees staff types plus Manpower and NetSuite',codes()===['ATM_REQUEST','ID_REQUEST','INCIDENT_REPORT','MANPOWER_REQUEST','NETSUITE_CONCERN','PAYROLL_CONCERN','UNIFORM_REQUEST']);
perm($f['employee']['role_id'],'tickets.request_self',false);act($f['employee']);
check('Type permission alone is not enough without filing access',!TicketTypes::canFile('ATM_REQUEST')&&codes()===[]);
perm($f['employee']['role_id'],'tickets.request_self',true);
act($f['hr']);check('Branch Transfer needs ticket.file_for_others',!TicketTypes::canFile('BRANCH_TRANSFER'));
perm($f['hr']['role_id'],'ticket.file_for_others',true);act($f['hr']);check('HR with file_for_others may file Branch Transfer',TicketTypes::canFile('BRANCH_TRANSFER'));
perm($f['hr']['role_id'],'ticket.file_for_others',false);

// Role and user administration guards.
$super=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="SUPER_ADMIN"),?,?,?,"ACTIVE")',['Slice15 Test super',strtolower($f['tag']).'_super@example.test',password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
$superUser=['id'=>$super,'role_id'=>(int)row('SELECT id FROM roles WHERE code="SUPER_ADMIN"')['id'],'role_code'=>'SUPER_ADMIN','role_portal'=>'admin','name'=>'Slice15 Test super','role_name'=>'Super Admin','email'=>'x@example.test','client_id'=>null];
$adminRole=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,"admin")',[$f['tag'].'_ADMIN','Slice15 admin']);
foreach(['users.manage','users.view','roles.manage','roles.view','tickets.request_self','ticket.file.atm'] as $code)perm($adminRole,$code,true);
$admin=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$adminRole,'Slice15 Test admin',strtolower($f['tag']).'_admin@example.test',password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
$adminUser=['id'=>$admin,'role_id'=>$adminRole,'role_code'=>$f['tag'].'_ADMIN','role_portal'=>'admin','name'=>'Slice15 Test admin','role_name'=>'Slice15 admin','email'=>'y@example.test','client_id'=>null];
$superRole=(int)row('SELECT id FROM roles WHERE code="SUPER_ADMIN"')['id'];

act($superUser);
$adlCode=$f['tag'].'_NEWADL';FoundationRepository::createRole($adlCode,'Area Development Leader (test)','employee');$adl=(int)row('SELECT id FROM roles WHERE code=?',[$adlCode])['id'];
check('Role created from the screen',$adl>0&&audited('CREATE_ROLE','role',$adl)!==[]);
check('Duplicate role code rejected',rejects(fn()=>FoundationRepository::createRole($adlCode,'Again','employee')));
check('Invalid role code rejected',rejects(fn()=>FoundationRepository::createRole('bad code!','Bad','employee')));
FoundationRepository::saveRolePermissions($adl,array_column(db()->query('SELECT id FROM permissions WHERE code IN ("tickets.request_self","payroll.approve_adl","ticket.file.atm")')->fetchAll(),'id'));
$details=json_decode(audited('UPDATE_ROLE_PERMISSIONS','role',$adl)['details_json'],true);
check('Permission audit lists added codes',in_array('payroll.approve_adl',$details['added'],true));
$adlUser=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$adl,'Slice15 Test ADL user',strtolower($f['tag']).'_adluser@example.test',password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
act($f['hr']);check('New ADL role usable as Ticket Approver',in_array($adlUser,array_map('intval',array_column(EmployeeApproverAssignmentService::candidates('ADL'),'id')),true));
act($superUser);
check('Super Admin role cannot be assigned, even by Super Admin',rejects(fn()=>FoundationRepository::createUser('X','sa_'.strtolower($f['tag']).'@example.test','Slice15-Test-Only!9',$superRole)));
check('Super Admin role cannot be edited',rejects(fn()=>FoundationRepository::saveRolePermissions($superRole,[])));
$hrRole=(int)row('SELECT id FROM roles WHERE code=?',[$f['tag'].'_hr'])['id'];
FoundationRepository::createUser('Slice15 Test hr user',strtolower($f['tag']).'_hruser@example.test','Slice15-Test-Only!9',$hrRole);
$hrUser=(int)row('SELECT id FROM users WHERE email=?',[strtolower($f['tag']).'_hruser@example.test'])['id'];check('Super Admin may create HR portal users',$hrUser>0);

act($adminUser);
check('Admin cannot create HR portal users',rejects(fn()=>FoundationRepository::createUser('X','h2_'.strtolower($f['tag']).'@example.test','Slice15-Test-Only!9',$hrRole)));
check('Admin cannot assign a role holding permissions they lack',rejects(fn()=>FoundationRepository::createUser('X','a1_'.strtolower($f['tag']).'@example.test','Slice15-Test-Only!9',$adl)));
$scCode=$f['tag'].'_NEWSC';FoundationRepository::createRole($scCode,'Sales Captain (test)','employee');$sc=(int)row('SELECT id FROM roles WHERE code=?',[$scCode])['id'];
check('Admin cannot grant a permission they lack',rejects(fn()=>FoundationRepository::saveRolePermissions($sc,array_column(db()->query('SELECT id FROM permissions WHERE code IN ("ticket.file.atm","ticket.file.manpower")')->fetchAll(),'id'))));
check('Admin cannot edit own role',rejects(fn()=>FoundationRepository::saveRolePermissions($adminRole,[])));
FoundationRepository::saveRolePermissions($sc,array_column(db()->query('SELECT id FROM permissions WHERE code IN ("tickets.request_self","ticket.file.atm")')->fetchAll(),'id'));
check('Admin may grant permissions they hold',count(FoundationRepository::permissionsForRole($sc))===2);
act($superUser);FoundationRepository::saveRolePermissions($sc,array_column(db()->query('SELECT id FROM permissions WHERE code IN ("tickets.request_self","ticket.file.atm","ticket.file.manpower")')->fetchAll(),'id'));
act($adminUser);FoundationRepository::saveRolePermissions($sc,[]);
check('Permissions the admin lacks survive their save',FoundationRepository::permissionsForRole($sc)===['ticket.file.manpower']);
act($superUser);FoundationRepository::saveRolePermissions($sc,array_column(db()->query('SELECT id FROM permissions WHERE code IN ("tickets.request_self","ticket.file.atm")')->fetchAll(),'id'));
act($adminUser);FoundationRepository::createUser('Slice15 Test sc user',strtolower($f['tag']).'_scuser@example.test','Slice15-Test-Only!9',$sc);
$scUser=(int)row('SELECT id FROM users WHERE email=?',[strtolower($f['tag']).'_scuser@example.test'])['id'];check('Admin may create a user for a role within their permissions',$scUser>0);
check('Duplicate user email rejected',rejects(fn()=>FoundationRepository::createUser('Dup',strtolower($f['tag']).'_scuser@example.test','Slice15-Test-Only!9',$sc)));
check('Admin cannot change an HR portal account',rejects(fn()=>FoundationRepository::changeUserRole($hrUser,$sc)));
check('Admin cannot change own account',rejects(fn()=>FoundationRepository::changeUserRole($admin,$sc)));
check('Admin cannot promote to Super Admin',rejects(fn()=>FoundationRepository::changeUserRole($scUser,$superRole)));
check('Admin cannot move a user into an HR portal role',rejects(fn()=>FoundationRepository::changeUserRole($scUser,$hrRole)));
$otherSuper=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$superRole,'Slice15 Test super 2',strtolower($f['tag']).'_super2@example.test',password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
act($superUser);check('Super Admin accounts cannot be changed here',rejects(fn()=>FoundationRepository::changeUserRole($otherSuper,$sc)));
FoundationRepository::changeUserRole($scUser,$adl);
$details=json_decode(audited('CHANGE_USER_ROLE','user',$scUser)['details_json'],true);
check('Role change applied and audited',(int)row('SELECT role_id FROM users WHERE id=?',[$scUser])['role_id']===$adl&&$details['to_role']===$adlCode&&$details['from_role']===$scCode);

// Employee link from the user screen.
$emp=fn(string $key,string $status='ACTIVE')=>insert('INSERT INTO employees(employee_no,first_name,last_name,branch_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,?,?)',[$f['tag'].'_'.$key,'Slice15','Test '.$key,$f['branch'],$f['legal'],$f['company'],$status,'2020-01-01']);
$e1=$emp('link1');$e2=$emp('link2');$gone=$emp('gone','RESIGNED');
act($adminUser);
check('Link rejects an employee linked to someone else',rejects(fn()=>FoundationRepository::linkUserEmployee($scUser,$f['requester'])));
check('Link rejects an archived employee',rejects(fn()=>FoundationRepository::linkUserEmployee($scUser,$gone)));
check('Admin cannot link own account',rejects(fn()=>FoundationRepository::linkUserEmployee($admin,$e1)));
FoundationRepository::linkUserEmployee($scUser,$e1);check('Link saved and audited',(int)row('SELECT user_id FROM employees WHERE id=?',[$e1])['user_id']===$scUser&&audited('LINK_USER_EMPLOYEE','user',$scUser)!==[]);
FoundationRepository::linkUserEmployee($scUser,$e2);check('Relink moves the account',row('SELECT user_id FROM employees WHERE id=?',[$e1])['user_id']===null&&(int)row('SELECT user_id FROM employees WHERE id=?',[$e2])['user_id']===$scUser);
FoundationRepository::linkUserEmployee($scUser,0);check('Unlink clears and is audited',row('SELECT user_id FROM employees WHERE id=?',[$e2])['user_id']===null&&audited('UNLINK_USER_EMPLOYEE','user',$scUser)!==[]);
$listed=array_values(array_filter(FoundationRepository::users(),fn($u)=>(int)$u['id']===$scUser))[0];check('User list reports the missing employee link',$listed['employee_id']===null);
finish();
