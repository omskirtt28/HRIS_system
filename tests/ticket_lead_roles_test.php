<?php
declare(strict_types=1);
// Migration 011: lead roles ADL, SC, OIC and TC. Synthetic fixtures only.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketAssignmentViews.php';
$f=fixtures();
// HR may edit these roles on the Roles screen, so a copy of live data can differ from what migration 011 seeded.
// Put the four roles back to exactly the migration's own grant ledger (isolated database only) so the test checks the seed, not live edits.
db()->exec('DELETE rp FROM role_permissions rp JOIN lead_role_seed s ON s.role_id=rp.role_id AND s.owned=1');
db()->exec('INSERT INTO role_permissions(role_id,permission_id) SELECT role_id,permission_id FROM lead_role_grant_seed');
// Later migrations that grant these roles something add their own ledger rows (012: ticket.view_team).
if(FoundationRepository::tableExists('ticket_viewer_seed'))db()->exec('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT v.role_id,v.permission_id FROM ticket_viewer_seed v JOIN lead_role_seed s ON s.role_id=v.role_id AND s.owned=1 WHERE v.role_id<>0');
db()->exec('UPDATE roles r JOIN lead_role_seed s ON s.role_id=r.id AND s.owned=1 SET r.portal="employee"');
Auth::clearPermissionCache();
function role_codes(string $role):array { $q=db()->prepare('SELECT p.code FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.code=? ORDER BY p.code');$q->execute([$role]);return $q->fetchAll(PDO::FETCH_COLUMN); }
function as_user(int $id):array { $u=row('SELECT u.id,u.role_id,u.full_name name,u.email,r.code role_code,r.name role_name,r.portal role_portal FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$id]);$u['id']=(int)$u['id'];$u['role_id']=(int)$u['role_id'];$u['client_id']=null;return $u; }
$approvers=['ADL'=>['payroll.approve_adl'],'SC'=>[],'OIC'=>['payroll.approve_manager'],'TC'=>['payroll.approve_adl']];
$names=['ADL'=>'Area Development Leader','SC'=>'Sales Captain','OIC'=>'OIC','TC'=>'Team Coordinator'];
// The shared base is the seeded ADL role minus its approver permission; every other lead role must equal it (HR may later edit the live MANAGER role).
$base=array_values(array_filter(role_codes('ADL'),fn($c)=>!str_starts_with($c,'payroll.approve_')));
$staff=['ticket.file.atm','ticket.file.id','ticket.file.incident','ticket.file.payroll_concern','ticket.file.uniform'];
$hrOrAdmin=db()->query('SELECT code FROM permissions WHERE module IN ("Administration","Security","Recruitment","Reports") OR code LIKE "%.manage" OR code LIKE "%.view_all" OR code LIKE "%_hr" OR code IN ("tickets.review_hr","tickets.approvers.manage","ticket.file_for_others","ticket.sensitive.view","ticket.accounting.view","payroll.process","payroll.configure","payroll.finalize","payroll.export","payroll.mark_paid","payroll.biometric_import","employees.create","employees.import","employees.delete")')->fetchAll(PDO::FETCH_COLUMN);

foreach($approvers as $code=>$extra){
    $r=row('SELECT name,portal FROM roles WHERE code=?',[$code]);$have=role_codes($code);
    check($code.' exists on the employee portal as '.$names[$code],$r!==[]&&$r['portal']==='employee'&&$r['name']===$names[$code]);
    check($code.' has the five staff types, Manpower, NetSuite and tickets.request_self',!array_diff([...$staff,'ticket.file.manpower','ticket.file.netsuite','tickets.request_self'],$have));
    $expected=array_merge($base,$extra);sort($expected);
    check($code.' = shared lead base (MANAGER-style portal access) + '.($extra?implode(',',$extra):'no approver permission'),$have===$expected);
    check($code.' holds no HR or admin permission',!array_intersect($have,$hrOrAdmin));
}
check('COORDINATOR untouched (HR portal, recruitment only)',row('SELECT portal FROM roles WHERE code="COORDINATOR"')['portal']==='hr'&&role_codes('COORDINATOR')===['dashboard.hr.view','recruitment.manage','recruitment.view','reports.view']);

// Users screen role dropdown (index.php renders FoundationRepository::assignableRoles()).
$superRole=(int)row('SELECT id FROM roles WHERE code="SUPER_ADMIN"')['id'];
$super=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$superRole,'Slice15 Test super',strtolower($f['tag']).'_super@example.test',password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
act(as_user($super));
$dropdown=array_column(FoundationRepository::assignableRoles(),'code');
check('Users role dropdown lists ADL, SC, OIC and TC',!array_diff(array_keys($approvers),$dropdown));

// One user per role, then the Ticket Approvers lists.
$users=[];
foreach(array_keys($approvers) as $code){
    $email=strtolower($f['tag'].'_lead_'.$code).'@example.test';
    FoundationRepository::createUser('Slice15 Test '.$code.' lead',$email,'Slice15-Test-Only!9',(int)row('SELECT id FROM roles WHERE code=?',[$code])['id']);
    $users[$code]=(int)row('SELECT id FROM users WHERE email=?',[$email])['id'];
}
check('Users created for all four lead roles',count(array_filter($users))===4);
act($f['hr']);
$adlList=array_map('intval',array_column(EmployeeApproverAssignmentService::candidates('ADL'),'id'));
$managerList=array_map('intval',array_column(EmployeeApproverAssignmentService::candidates('MANAGER'),'id'));
check('Ticket Approvers ADL list has ADL and TC',in_array($users['ADL'],$adlList,true)&&in_array($users['TC'],$adlList,true));
check('Ticket Approvers Manager list has OIC',in_array($users['OIC'],$managerList,true));
check('OIC is not an ADL approver; ADL/TC are not Manager approvers',!in_array($users['OIC'],$adlList,true)&&!in_array($users['ADL'],$managerList,true)&&!in_array($users['TC'],$managerList,true));
check('SC is in neither approver list',!in_array($users['SC'],$adlList,true)&&!in_array($users['SC'],$managerList,true));
$filter=['branch_id'=>$f['branch'],'business_unit_id'=>0,'company_group'=>'','kind'=>'','unresolved'=>false,'p'=>1];
ob_start();ticket_assignment_page(EmployeeApproverAssignmentService::report($filter),$filter,null);$html=ob_get_clean();
check('Ticket Approvers page renders ADL/TC/OIC options and no SC',str_contains($html,'Slice15 Test ADL lead · ADL')&&str_contains($html,'Slice15 Test TC lead · ADL')&&str_contains($html,'Slice15 Test OIC lead · MANAGER')&&!str_contains($html,'Slice15 Test SC lead'));

// End to end: ADL user linked to an employee files Manpower; it reaches the HR queue.
act(as_user($super));
$emp=insert('INSERT INTO employees(employee_no,first_name,last_name,branch_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,"ACTIVE",?)',[$f['tag'].'_adl_lead','Slice15','Test ADL lead',$f['branch'],$f['legal'],$f['company'],'2020-01-01']);
FoundationRepository::linkUserEmployee($users['ADL'],$emp);
check('ADL user linked to the employee',(int)row('SELECT user_id FROM employees WHERE id=?',[$emp])['user_id']===$users['ADL']);
act(as_user($users['ADL']));
check('ADL may file Manpower and NetSuite but not Branch Transfer',TicketTypes::canFile('MANPOWER_REQUEST')&&TicketTypes::canFile('NETSUITE_CONCERN')&&!TicketTypes::canFile('BRANCH_TRANSFER'));
$manpower=['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code="MANPOWER_REQUEST"')['id'],'submission_key'=>bin2hex(random_bytes(32)),'section_branch'=>'Synthetic section','position_code'=>'SC','employment_type'=>'Probationary','request_type'=>'Additional','quantity'=>'1','job_description_qualifications'=>'Synthetic qualifications','area_department'=>'Synthetic department'];
$id=TicketService::create($manpower,true);$t=TicketRepository::detail($id);
check('ADL Manpower is PENDING_HR with a single HR step',$t['status']==='PENDING_HR'&&array_column($t['steps'],'approver_type')===['HR']);
check('ADL Manpower subject is the linked employee',(int)$t['subject_employee_id']===$emp&&(int)$t['requester_user_id']===$users['ADL']);
check('ADL sees it in My HR Requests',in_array($id,array_map('intval',array_column(TicketRepository::listing('my')['rows'],'id')),true));
act($f['hr']);
check('Manpower reaches the HR service queue',in_array($id,array_map('intval',array_column(TicketRepository::listing('services',['type_code'=>'MANPOWER_REQUEST'])['rows'],'id')),true));
act($f['outsider']);check('Unrelated staff cannot open the HR queue',rejects(fn()=>TicketRepository::listing('services')));
finish();
