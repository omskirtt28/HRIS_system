<?php
declare(strict_types=1);
// Automatic read-only ticket viewers (migrations 012 + 013): ADL by area, Sales Captain and OIC by branch, Head Office Manager.
// Nobody is assigned by hand; branch_leads / ticket_viewer_overrides are deprecated and must never grant access. Synthetic fixtures only.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/TicketViewerService.php';
require_once dirname(__DIR__).'/app/EmployeeDirectoryPlacement.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketViews.php';
require_once dirname(__DIR__).'/app/TicketServiceViews.php';
require_once dirname(__DIR__).'/app/TicketAccountingViews.php';
require_once dirname(__DIR__).'/app/TicketViewerViews.php';
$f=fixtures();$tag=$f['tag'];

function form(string $code,array $extra=[]):array {
    return array_replace(['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'],'submission_key'=>bin2hex(random_bytes(32))],$extra);
}
function perm(int $role,string $code,bool $on):void {
    $p=row('SELECT id FROM permissions WHERE code=?',[$code]);
    if($on)db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);
    else db()->prepare('DELETE FROM role_permissions WHERE role_id=? AND permission_id=?')->execute([$role,$p['id']]);
    Auth::clearPermissionCache();
}
function role(string $tag,string $key,array $perms,string $portal='employee'):int {
    $id=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,?)',[$tag.'_'.$key,'Slice15 '.$key,$portal]);foreach($perms as $c)perm($id,$c,true);return $id;
}
/** @return array{user:?array,employee:int,no:string} a linked active employee at $branch, with an account unless $account is false */
function person(string $tag,string $key,int $roleId,int $branch,array $f,?int $positionId=null,bool $account=true):array {
    $r=row('SELECT code,portal FROM roles WHERE id=?',[$roleId]);$email=strtolower($tag.'_v_'.$key).'@example.test';$uid=null;$user=null;
    if($account){
        $uid=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$roleId,'Slice15 Test '.$key,$email,password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
        $user=['id'=>$uid,'role_id'=>$roleId,'role_code'=>$r['code'],'role_portal'=>$r['portal'],'name'=>'Slice15 Test '.$key,'role_name'=>'Test '.$key,'email'=>$email,'client_id'=>null];
    }
    $emp=insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,branch_id,position_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,?,?,"ACTIVE",?)',[$tag.'_v_'.$key,'Slice15','Test '.$key,$uid,$branch,$positionId,$f['legal'],$f['company'],date('Y-m-d',strtotime('-2 years'))]);
    return ['user'=>$user,'employee'=>$emp,'no'=>$tag.'_v_'.$key];
}
function sees(array $user,int $ticket):bool { act($user);try{TicketRepository::find($ticket);return true;}catch(RuntimeException){return false;} }
function listed(array $user,int $ticket,array $filters=[]):bool { act($user);foreach(TicketRepository::listing('team',$filters,100)['rows'] as $r)if((int)$r['id']===$ticket)return true;return false; }
function file_ticket(array $user,string $code,array $extra,bool $submit=true):int { act($user);return TicketService::create(form($code,$extra),$submit); }
function atm(array $user,bool $submit=true):int { return file_ticket($user,'ATM_REQUEST',['reason'=>'Synthetic viewer test'],$submit); }
function steps(int $ticket):int { return (int)row('SELECT COUNT(*) c FROM ticket_steps WHERE ticket_id=?',[$ticket])['c']; }
function problems(array $v):array { return array_column($v['problems'],0); }

// ---- Migration shapes
check('ticket.view_team exists',row('SELECT id FROM permissions WHERE code="ticket.view_team"')!==[]);
$granted=array_column(db()->query('SELECT r.code FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.code="ticket.view_team" AND r.code NOT LIKE "T15\_%" ORDER BY r.code')->fetchAll(),'code');
check('seeded to ADL, SC, OIC, TC, MANAGER only (deny by default)',$granted===['ADL','MANAGER','OIC','SC','TC']);
check('EMPLOYEE and HR roles do not hold it',!array_intersect(['EMPLOYEE','HR_USER','HRIS_ADMIN'],$granted));
check('the manual-assignment API is gone',!method_exists('TicketViewerService','assignLeads')&&!method_exists('TicketViewerService','addOverride')&&!method_exists('TicketViewerService','endLead')&&!method_exists('TicketViewerService','endOverride')&&!method_exists('TicketViewerService','overrides')&&!method_exists('TicketViewerService','leadCandidates'));
$dep=db()->query('SELECT table_name,table_comment FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ("branch_leads","ticket_viewer_overrides")')->fetchAll(PDO::FETCH_KEY_PAIR);
check('branch_leads and ticket_viewer_overrides are marked DEPRECATED in the schema',count($dep)===2&&!array_filter($dep,fn($c)=>!str_starts_with($c,'DEPRECATED')));

// ---- Fixtures: two areas, branches in them, an area-less branch.
$areaA=insert('INSERT INTO areas(code,name) VALUES(?,?)',[$tag.'_A','T15 Area A']);$areaB=insert('INSERT INTO areas(code,name) VALUES(?,?)',[$tag.'_B','T15 Area B']);
db()->prepare('UPDATE branches SET area_id=? WHERE id=?')->execute([$areaA,$f['branch']]);
$brB=insert('INSERT INTO branches(code,name,site_type,area_id) VALUES(?,?,"BRANCH",?)',[$tag.'_BB','T15 Branch B',$areaB]);
$brNone=insert('INSERT INTO branches(code,name,site_type) VALUES(?,?,"BRANCH")',[$tag.'_BN','T15 Branch no area']);
$viewRole=role($tag,'viewrole',['ticket.view_team','tickets.request_self','ticket.file.atm','ticket.file.incident']);
$plainRole=role($tag,'plainrole',['tickets.request_self','ticket.file.atm','ticket.file.incident']);
$scPos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$tag.'_SC','Sales Captain']);
$oicPos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$tag.'_OIC','Officer In Charge']);
$adlPos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$tag.'_ADL','Area Development Leader']);
$baPos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$tag.'_BA','Beauty Advisor']);

$staff=person($tag,'staff',$plainRole,$f['branch'],$f,$baPos);        // subject at the area A branch
$staffB=person($tag,'staffb',$plainRole,$brB,$f,$baPos);              // subject at the area B branch
$staffN=person($tag,'staffn',$plainRole,$brNone,$f,$baPos);           // subject at the branch with no area / no SC
$sc=person($tag,'sc',$viewRole,$f['branch'],$f,$scPos);               // the Sales Captain of the area A branch
$notSc=person($tag,'notsc',$viewRole,$f['branch'],$f,$baPos);         // same branch, holds the permission, but not a Sales Captain
$scOther=person($tag,'scother',$viewRole,$brB,$f,$scPos);             // Sales Captain of the area B branch
$stranger=person($tag,'stranger',$viewRole,$brNone,$f,$baPos);        // permitted, in scope of nothing
$adl=person($tag,'adl',$viewRole,$f['ho'],$f,$adlPos);                // ADL whose own branch is Head Office
$hr=$f['hr'];

// ---- Tickets
$t1=atm($staff['user']);$t2=atm($staff['user'],false);$t3=file_ticket($staff['user'],'INCIDENT_REPORT',['title'=>'T15 incident','explanation'=>'Synthetic'],true);
$tB=atm($staffB['user']);$tN=atm($staffN['user']);$tHo=atm($f['employee']);
check('ATM filed and routed HR only',(string)row('SELECT status FROM tickets WHERE id=?',[$t1])['status']==='PENDING_HR'&&(string)row('SELECT status FROM tickets WHERE id=?',[$t2])['status']==='DRAFT');
$stepsBefore=steps($t1);

// ---- Sales Captain: automatic, by position + same branch + account + permission. No HR assignment exists.
check('the Sales Captain sees the branch ticket with no setup at all',sees($sc['user'],$t1)&&listed($sc['user'],$t1));
act($sc['user']);$tk=TicketRepository::find($t1);check('ticket is flagged viewer_only',$tk['viewer_only']===true);
check('every action permission is false for a viewer',!array_filter(TicketService::permissions($tk)));
check('draft is hidden from viewers (list and URL)',!sees($sc['user'],$t2)&&!listed($sc['user'],$t2));
check('Incident Report hidden from viewers (list and URL)',!sees($sc['user'],$t3)&&!listed($sc['user'],$t3));
check('Sales Captain of another branch is denied by guessed URL',!sees($scOther['user'],$t1)&&!listed($scOther['user'],$t1)&&sees($scOther['user'],$tB));
check('same branch + permission but another position is denied',!sees($notSc['user'],$t1));
check('Sales Captain does not see other branches or Head Office',!sees($sc['user'],$tB)&&!sees($sc['user'],$tN)&&!sees($sc['user'],$tHo));
check('a permitted person in scope of nothing sees nothing',!sees($stranger['user'],$t1)&&!sees($stranger['user'],$tB)&&!sees($stranger['user'],$tN));
check('plain staff of another branch denied by guessed URL',!sees($staffB['user'],$t1)&&!sees($f['outsider'],$t1));
act($sc['user']);$own=atm($sc['user']);check('the Sales Captain\'s own ticket is theirs, not a team row',!TicketRepository::find($own)['viewer_only']&&!listed($sc['user'],$own));

// Forged POSTs (service layer is what the POST handler calls)
act($sc['user']);$ver=(int)TicketRepository::find($t1)['version'];
foreach(['APPROVE','START','COMPLETE','RETURN','REJECT','CANCEL'] as $action)check('forged '.$action.' by viewer denied',rejects(fn()=>TicketService::act($t1,$ver,$action,'forged remarks','NO_CHANGE')));
check('forged comment by viewer denied',rejects(fn()=>TicketService::comment($t1,'forged comment')));
check('forged staff-only comment by viewer denied',rejects(fn()=>TicketService::comment($t1,'forged','STAFF_ONLY')));
check('forged attachment upload by viewer denied',rejects(fn()=>TicketService::attach($t1,['name'=>['x.pdf'],'tmp_name'=>['x'],'error'=>[0],'size'=>[1],'type'=>['application/pdf']])));
check('forged edit by viewer denied',rejects(fn()=>TicketService::edit($t1,$ver,form('ATM_REQUEST',['reason'=>'x']),true)));
check('forged NetSuite remark by viewer denied',rejects(fn()=>TicketAccounting::remark($t1,'ACCOUNTING','forged')));
check('nothing changed after the forged attempts',(int)row('SELECT version v FROM tickets WHERE id=?',[$t1])['v']===$ver&&(int)row('SELECT COUNT(*) c FROM ticket_comments WHERE ticket_id=?',[$t1])['c']===0&&steps($t1)===$stepsBefore&&(string)row('SELECT status FROM tickets WHERE id=?',[$t1])['status']==='PENDING_HR');
$att=insert('INSERT INTO ticket_attachments(ticket_id,original_name,stored_name,mime_type,file_size,uploaded_by) VALUES(?,?,?,?,?,?)',[$t1,'evidence.pdf',bin2hex(random_bytes(24)).'.pdf','application/pdf',10,$staff['user']['id']]);
act($sc['user']);check('viewer detail lists no attachments',TicketRepository::detail($t1)['attachments']===[]);
check('viewer cannot download an attachment',rejects(fn()=>TicketService::attachment($att)));
act($staff['user']);check('requester still lists their attachment',count(TicketRepository::detail($t1)['attachments'])===1);
act($hr);TicketService::comment($t1,'T15 visible comment');
act($sc['user']);$d=TicketRepository::detail($t1);check('viewer reads comments and events',count($d['comments'])===1&&count($d['events'])>=2);
ob_start();ticket_detail_page(TicketRepository::detail($t1));$html=ob_get_clean();
check('viewer detail page has no decision, comment or upload form',!str_contains($html,'core_ticket_comment')&&!str_contains($html,'core_ticket_act')&&!str_contains($html,'core_ticket_attach')&&str_contains($html,'View-only'));
ob_start();ticket_list_page('team',TicketRepository::listing('team'),[]);$html=ob_get_clean();check('team list page renders the ticket',str_contains($html,TicketRepository::find($t1)['ticket_no'])&&str_contains($html,'view only'));
$q=db()->prepare('SELECT COUNT(*) c FROM ticket_notification_outbox o JOIN ticket_events e ON e.id=o.event_id WHERE e.ticket_id=? AND o.recipient_user_id=?');$q->execute([$t1,$sc['user']['id']]);
check('viewers are never queued for notifications',(int)$q->fetchColumn()===0);
act($hr);check('HR still sees the ticket directly (not viewer_only)',TicketRepository::find($t1)['viewer_only']===false&&TicketRepository::find($t3)['viewer_only']===false);

// ---- Deprecated tables cannot grant anything, even with rows in them.
db()->prepare('INSERT INTO branch_leads(branch_id,employee_id,effective_from) VALUES(?,?,CURDATE())')->execute([$f['branch'],$stranger['employee']]);
db()->prepare('INSERT INTO ticket_viewer_overrides(employee_id,viewer_employee_id,effective_from) VALUES(?,?,CURDATE())')->execute([$staffN['employee'],$stranger['employee']]);
check('a branch_leads row grants no access',!sees($stranger['user'],$t1)&&!listed($stranger['user'],$t1));
check('a ticket_viewer_overrides row grants no access',!sees($stranger['user'],$tN)&&!listed($stranger['user'],$tN));
act($stranger['user']);check('and the scope stays empty',TicketViewerService::scope($stranger['user']['id'])===['branch_ids'=>[],'area_ids'=>[],'manager_employee_ids'=>[]]);
$v=TicketViewerService::viewersFor($staff['employee']);check('the 201 answer does not list a deprecated lead',!array_filter($v['sc'],fn($p)=>(int)$p['id']===$stranger['employee'])&&!array_filter($v['oic'],fn($p)=>(int)$p['id']===$stranger['employee']));

// ---- Access follows position, employment, account and permission at once
db()->prepare('UPDATE employees SET position_id=? WHERE id=?')->execute([$baPos,$sc['employee']]);
check('Sales Captain whose position changes loses access at once',!sees($sc['user'],$t1));
db()->prepare('UPDATE employees SET position_id=? WHERE id=?')->execute([$scPos,$sc['employee']]);
check('and gets it back when the position is Sales Captain again',sees($sc['user'],$t1));
db()->prepare('UPDATE employees SET status="INACTIVE" WHERE id=?')->execute([$sc['employee']]);
check('an inactive Sales Captain employee loses access',!sees($sc['user'],$t1));
db()->prepare('UPDATE employees SET status="ACTIVE" WHERE id=?')->execute([$sc['employee']]);
db()->prepare('UPDATE users SET status="INACTIVE" WHERE id=?')->execute([$sc['user']['id']]);
check('a Sales Captain with a disabled account loses access',!sees($sc['user'],$t1));
db()->prepare('UPDATE users SET status="ACTIVE" WHERE id=?')->execute([$sc['user']['id']]);
db()->prepare('UPDATE employees SET user_id=NULL WHERE id=?')->execute([$sc['employee']]);
check('a Sales Captain with no linked account has no access',!sees($sc['user'],$t1));
db()->prepare('UPDATE employees SET user_id=? WHERE id=?')->execute([$sc['user']['id'],$sc['employee']]);
perm($viewRole,'ticket.view_team',false);check('removing ticket.view_team ends all viewer access',!sees($sc['user'],$t1)&&!sees($scOther['user'],$tB));
perm($viewRole,'ticket.view_team',true);check('and granting it restores access',sees($sc['user'],$t1));
// Frozen branch_id: a transfer does not move old tickets
db()->prepare('UPDATE employees SET branch_id=? WHERE id=?')->execute([$brB,$staff['employee']]);
check('after the employee moves, the old branch Sales Captain keeps the old ticket',sees($sc['user'],$t1));
check('the new branch Sales Captain does not get the old ticket (frozen branch_id)',!sees($scOther['user'],$t1));
db()->prepare('UPDATE employees SET branch_id=? WHERE id=?')->execute([$f['branch'],$staff['employee']]);
// A Sales Captain moved to another branch follows the new branch
db()->prepare('UPDATE employees SET branch_id=? WHERE id=?')->execute([$brNone,$sc['employee']]);
check('a Sales Captain who moves branch loses the old branch and gains the new one',!sees($sc['user'],$t1)&&sees($sc['user'],$tN));
db()->prepare('UPDATE employees SET branch_id=? WHERE id=?')->execute([$f['branch'],$sc['employee']]);

// ---- Two Sales Captains in one branch: both can view, and it is a PROBLEM
$sc2=person($tag,'sc2',$viewRole,$f['branch'],$f,$scPos);
check('a second Sales Captain can view too',sees($sc2['user'],$t1)&&sees($sc['user'],$t1));
act($hr);$v=TicketViewerService::viewersFor($staff['employee']);
check('201 answer lists both and flags MULTI_SC',count($v['sc'])===2&&in_array('MULTI_SC',problems($v),true));
db()->prepare('UPDATE employees SET status="INACTIVE" WHERE id=?')->execute([$sc2['employee']]);
check('a single Sales Captain has no MULTI_SC problem',!in_array('MULTI_SC',problems(TicketViewerService::viewersFor($staff['employee'])),true));

// ---- OIC by position (permanent) and by dated authorization
$oic=person($tag,'oic',$viewRole,$f['branch'],$f,$oicPos);
check('an OIC by position sees the branch tickets, read-only',sees($oic['user'],$t1)&&listed($oic['user'],$t1)&&TicketRepository::find($t1)['viewer_only']===true);
check('the OIC does not see other branches',!sees($oic['user'],$tB)&&!sees($oic['user'],$tN));
$oicRole=(int)row('SELECT id FROM roles WHERE code="OIC"')['id'];
$temp=person($tag,'temp',$oicRole,$f['branch'],$f,$baPos);
check('an OIC-role account with no authorization sees nothing',!sees($temp['user'],$t1));
$auth=function(int $ticket,string $from,string $to,string $status='APPROVED')use($temp,$f){
    db()->prepare('DELETE FROM oic_authorizations WHERE employee_id=?')->execute([$temp['employee']]);
    db()->prepare('INSERT INTO oic_authorizations(ticket_id,employee_id,branch_id,start_date,end_date,status) VALUES(?,?,?,?,?,?)')->execute([$ticket,$temp['employee'],$f['branch'],$from,$to,$status]);
};
$day=fn(int $n)=>date('Y-m-d',strtotime(($n>=0?'+':'').$n.' days'));
$auth($tB,$day(-1),$day(3));check('an approved authorization covering today gives OIC viewer access',sees($temp['user'],$t1));
$auth($tB,$day(-5),$day(-1));check('an expired authorization removes access at once, before any role change',!sees($temp['user'],$t1));
$auth($tB,$day(2),$day(5));check('a future authorization gives nothing yet',!sees($temp['user'],$t1));
$auth($tB,$day(0),$day(0));check('the end date itself is still covered',sees($temp['user'],$t1));
$auth($tB,$day(-1),$day(3),'REQUESTED');check('a requested (not served) authorization gives nothing',!sees($temp['user'],$t1));
$auth($tB,$day(-1),$day(3));db()->prepare('UPDATE oic_authorizations SET branch_id=? WHERE employee_id=?')->execute([$brB,$temp['employee']]);
check('an authorization for another branch than the employee\'s gives nothing',!sees($temp['user'],$t1)&&!sees($temp['user'],$tB));
$auth($tB,$day(-1),$day(3));
db()->prepare('UPDATE employees SET branch_id=? WHERE id=?')->execute([$brB,$temp['employee']]);
check('an OIC who moves branch loses the authorization (branch must match)',!sees($temp['user'],$t1)&&!sees($temp['user'],$tB));
db()->prepare('UPDATE employees SET branch_id=? WHERE id=?')->execute([$f['branch'],$temp['employee']]);

db()->prepare('DELETE FROM oic_authorizations WHERE employee_id=?')->execute([$temp['employee']]);
// ---- ADL coverage from assigned areas
$setAreas=function(array $ids)use($adl){db()->prepare('UPDATE employees SET directory_role="ADL",directory_area_ids=? WHERE id=?')->execute([$ids?','.implode(',',$ids).',':null,$adl['employee']]);};
$setAreas([]);check('ADL with no assigned area sees nothing',!sees($adl['user'],$t1)&&!sees($adl['user'],$tB));
$setAreas([$areaA]);check('ADL sees the branches of an assigned area',sees($adl['user'],$t1)&&listed($adl['user'],$t1));
check('ADL does not see another area, Head Office or no-area branches',!sees($adl['user'],$tB)&&!sees($adl['user'],$tN)&&!sees($adl['user'],$tHo));
act($adl['user']);check('ADL is read-only',TicketRepository::find($t1)['viewer_only']===true&&rejects(fn()=>TicketService::act($t1,(int)TicketRepository::find($t1)['version'],'COMPLETE','x','NO_CHANGE'))&&rejects(fn()=>TicketService::comment($t1,'x')));
$setAreas([$areaB]);check('changing the area moves coverage at once',!sees($adl['user'],$t1)&&sees($adl['user'],$tB));
$setAreas([$areaA,$areaB]);check('several areas cover all of them',sees($adl['user'],$t1)&&sees($adl['user'],$tB));
$brNew=insert('INSERT INTO branches(code,name,site_type,area_id) VALUES(?,?,"BRANCH",?)',[$tag.'_NEW','T15 New branch',$areaA]);
$staffNew=person($tag,'staffnew',$plainRole,$brNew,$f,$baPos);$tNew=atm($staffNew['user']);
check('a branch added later to the area is covered with no reassignment',sees($adl['user'],$tNew));
db()->prepare('UPDATE branches SET area_id=NULL WHERE id=?')->execute([$brNew]);
check('removing a branch from the area removes coverage',!sees($adl['user'],$tNew));
db()->prepare('UPDATE branches SET area_id=? WHERE id=?')->execute([$areaA,$brNew]);
db()->prepare('UPDATE users SET status="INACTIVE" WHERE id=?')->execute([$adl['user']['id']]);
check('ADL with a disabled account has no coverage',!sees($adl['user'],$t1));
db()->prepare('UPDATE users SET status="ACTIVE" WHERE id=?')->execute([$adl['user']['id']]);
db()->prepare('UPDATE employees SET user_id=NULL WHERE id=?')->execute([$adl['employee']]);
check('ADL without a linked account has no coverage',!sees($adl['user'],$t1));
db()->prepare('UPDATE employees SET user_id=? WHERE id=?')->execute([$adl['user']['id'],$adl['employee']]);
// Audit on directory_area_ids
act($hr);db()->beginTransaction();$old=row('SELECT * FROM employees WHERE id=?',[$adl['employee']]);
$data=['directory_fields_present'=>1,'directory_company_group'=>'SW','directory_role'=>'ADL','directory_area_ids'=>[$areaB]];
EmployeeDirectoryPlacement::save($adl['employee'],$data,$old);db()->commit();
$a=row('SELECT details_json FROM audit_logs WHERE action="CHANGE_ADL_AREAS" AND record_id=? ORDER BY id DESC LIMIT 1',[$adl['employee']]);
check('changing assigned areas writes an audit entry',$a!==[]&&json_decode($a['details_json'],true)['areas_after']===[$areaB]);
$before=(int)row('SELECT COUNT(*) c FROM audit_logs WHERE action="CHANGE_ADL_AREAS" AND record_id=?',[$adl['employee']])['c'];
db()->beginTransaction();$old=row('SELECT * FROM employees WHERE id=?',[$adl['employee']]);EmployeeDirectoryPlacement::save($adl['employee'],$data,$old);db()->commit();
check('saving the same areas does not add an audit entry',(int)row('SELECT COUNT(*) c FROM audit_logs WHERE action="CHANGE_ADL_AREAS" AND record_id=?',[$adl['employee']])['c']===$before);
$setAreas([$areaA]);

// ---- Head Office: the existing resolve() Manager
$mgr=$f['manager'];perm($mgr['role_id'],'ticket.view_team',true);
db()->prepare('UPDATE employees SET manager_employee_id=NULL WHERE id=?')->execute([$f['requester']]);
check('HO manager with no reporting link sees nothing',!sees($mgr,$tHo));
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$f['manager_employee'],$f['requester']]);
act($mgr);$route=EmployeeApproverAssignmentService::resolve(EmployeeApproverAssignmentService::employee($f['requester']),date('Y-m-d'));
check('resolve() names this account as the HO Manager',$route['type']==='MANAGER'&&(int)$route['user_id']===(int)$mgr['id']);
check('HO Manager sees the report\'s ticket read-only',sees($mgr,$tHo)&&listed($mgr,$tHo)&&TicketRepository::find($tHo)['viewer_only']===true);
act($mgr);check('HO Manager forged actions denied',rejects(fn()=>TicketService::act($tHo,(int)TicketRepository::find($tHo)['version'],'COMPLETE','x','NO_CHANGE'))&&rejects(fn()=>TicketService::comment($tHo,'x')));
check('HO Manager does not see branch tickets',!sees($mgr,$t1)&&!sees($mgr,$tN));
perm($mgr['role_id'],'ticket.view_team',false);check('HO Manager without the permission sees nothing',!sees($mgr,$tHo));
perm($mgr['role_id'],'ticket.view_team',true);
check('branch rules never reach Head Office tickets',!sees($sc['user'],$tHo)&&!sees($oic['user'],$tHo));

// ---- Employee 201 panel (viewersFor + TicketViewerEmploymentLine)
act($hr);
$line=fn(int $employee)=>TicketViewerEmploymentLine::html($employee);
$v=TicketViewerService::viewersFor($staffN['employee']);
check('201: a branch with no area, no Sales Captain and no ADL resolves to nobody',!$v['has_viewer']&&!$v['sc']&&!$v['adls']&&$v['manager']===null&&!$v['head_office']);
check('201: it lists NO_SC and NO_ADL_AREA as PROBLEMs',in_array('NO_SC',problems($v),true)&&in_array('NO_ADL_AREA',problems($v),true));
$html=$line($staffN['employee']);
check('201: the panel is titled and shows the problems in red',str_contains($html,'Who can view this employee')&&str_contains($html,'data-viewer-problem="NO_SC"')&&str_contains($html,'alert error')&&str_contains($html,'PROBLEM'));
check('201: the panel has no assign button or form',!str_contains($html,'<form')&&!str_contains($html,'<button')&&!str_contains($html,'ticket_viewers_'));
$v=TicketViewerService::viewersFor($staff['employee']);
check('201: ADL, Sales Captain and OIC are all shown, the Sales Captain with its account',$v['has_viewer']&&count($v['adls'])===1&&count($v['sc'])===1&&(int)$v['sc'][0]['id']===$sc['employee']&&$v['sc'][0]['account']==='ACTIVE'&&count($v['oic'])===1);
$html=$line($staff['employee']);
check('201: rendered rows are labelled ADL, Sales Captain, OIC with employee numbers',str_contains($html,'>ADL<')&&str_contains($html,'>Sales Captain<')&&str_contains($html,'>OIC<')&&str_contains($html,$sc['no'])&&str_contains($html,'Account active'));
check('201: the ADL recorded under Head Office is flagged',in_array('ADL_IN_HEAD_OFFICE',problems($v),true)&&str_contains($html,'data-viewer-problem="ADL_IN_HEAD_OFFICE"'));
check('201: a healthy branch row set has no NO_SC / SC_NO_ACCOUNT problem',!array_intersect(['NO_SC','SC_NO_ACCOUNT','MULTI_SC'],problems($v)));
// Sales Captain without an account
db()->prepare('UPDATE employees SET user_id=NULL WHERE id=?')->execute([$scOther['employee']]);
$v=TicketViewerService::viewersFor($staffB['employee']);
check('201: a Sales Captain with no account is shown and flagged SC_NO_ACCOUNT, ADL only can view',count($v['sc'])===1&&$v['sc'][0]['account']==='NONE'&&in_array('SC_NO_ACCOUNT',problems($v),true)&&!array_filter($v['sc'],fn($p)=>$p['can_view']));
check('201: the no-account Sales Captain is named in the panel',str_contains($line($staffB['employee']),'No account')&&str_contains($line($staffB['employee']),'has no linked account'));
db()->prepare('UPDATE employees SET position_id=? WHERE id=?')->execute([$baPos,$scOther['employee']]);
$v=TicketViewerService::viewersFor($staffB['employee']);check('201: with no Sales Captain on record the problem is NO_SC',in_array('NO_SC',problems($v),true));
db()->prepare('UPDATE employees SET position_id=? WHERE id=?')->execute([$scPos,$scOther['employee']]);
perm($viewRole,'ticket.view_team',false);
$v=TicketViewerService::viewersFor($staff['employee']);
check('201: a Sales Captain whose role lacks the permission is flagged, not counted',!$v['has_viewer']&&$v['sc'][0]['account']==='NO_PERMISSION'&&in_array('SC_NO_ACCOUNT',problems($v),true));
perm($viewRole,'ticket.view_team',true);
// Head Office
$v=TicketViewerService::viewersFor($f['requester']);
check('201: Head Office shows the routing Manager and no Sales Captain, OIC or ADL',$v['head_office']&&$v['manager']!==null&&$v['manager']['can_view']===true&&!$v['sc']&&!$v['oic']&&!$v['adls']&&$v['has_viewer']&&str_contains($line($f['requester']),'>Manager<'));
db()->prepare('UPDATE employees SET manager_employee_id=NULL WHERE id=?')->execute([$f['requester']]);
$v=TicketViewerService::viewersFor($f['requester']);check('201: Head Office without a Manager shows nobody and a PROBLEM',$v['head_office']&&!$v['has_viewer']&&in_array('NO_MANAGER',problems($v),true));
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$f['manager_employee'],$f['requester']]);
// The panel must agree with what people can really read.
$tNv=atm($staffN['user']);check('201 agrees with the access rule: nobody listed, stranger cannot open the ticket',!sees($stranger['user'],$tNv));
act($f['employee']);check('201 shows no problem-report link to a non-HR reader',!str_contains($line($staffN['employee']),'hr-ticket-viewers'));
act($hr);check('201 shows the problem-report link to HR',str_contains($line($staffN['employee']),'hr-ticket-viewers'));

// ---- Problem report and screen (read-only)
act($hr);$rep=TicketViewerService::report();
$ids=fn(string $key)=>array_column($rep[$key],'id');
check('report lists the branch with employees and no Sales Captain',in_array($brNone,$ids('branches_without_sc'),true)&&!in_array($f['branch'],$ids('branches_without_sc'),true));
check('report lists the branch whose Sales Captain has no usable account',in_array($brB,$ids('branches_sc_no_account'),true));
$sc3=person($tag,'sc3',$viewRole,$f['branch'],$f,$scPos);$rep=TicketViewerService::report();$ids=fn(string $key)=>array_column($rep[$key],'id');
check('report lists the branch with two Sales Captains',in_array($f['branch'],$ids('branches_multi_sc'),true));
db()->prepare('UPDATE employees SET status="INACTIVE" WHERE id=?')->execute([$sc3['employee']]);
$missing=[];$rep=TicketViewerService::report();for($pg=1;$pg<=$rep['pages'];$pg++)foreach(TicketViewerService::report($pg)['missing'] as $m)$missing[]=(int)$m['id'];
check('report lists uncovered employees and not covered ones',in_array($staffN['employee'],$missing,true)&&!in_array($staff['employee'],$missing,true)&&!in_array($staffNew['employee'],$missing,true));
check('report counts add up',$rep['counts']['with_viewer']+$rep['missing_total']===$rep['counts']['employees']);
db()->prepare('UPDATE employees SET directory_area_ids=NULL WHERE id=?')->execute([$adl['employee']]);
$rep=TicketViewerService::report();
check('report flags the ADL without areas',(bool)array_filter($rep['adls_unready'],fn($a)=>(int)$a['id']===$adl['employee']));
check('report flags the ADL whose branch is Head Office',(bool)array_filter($rep['adls_in_head_office'],fn($a)=>(int)$a['id']===$adl['employee']));
$setAreas([$areaA]);
db()->prepare('UPDATE branches SET area_id=NULL WHERE id=?')->execute([$brB]);
check('report lists retail branches without an area',in_array($brB,array_column(TicketViewerService::report()['branches_without_area'],'id'),true));
db()->prepare('UPDATE branches SET area_id=? WHERE id=?')->execute([$areaB,$brB]);
act($f['employee']);check('the report is HR only',rejects(fn()=>TicketViewerService::report()));
ob_start();act($hr);ticket_viewers_page(['area_id'=>0,'branch_id'=>0,'problems'=>false,'p'=>1],TicketViewerService::branchRows(0,0),TicketViewerService::report());$html=ob_get_clean();
check('Ticket Viewers page renders as a read-only problem report',str_contains($html,'Unresolved viewers')&&str_contains($html,'Problem report only')&&str_contains($html,'PROBLEM')&&!str_contains($html,'ticket_viewers_assign')&&!str_contains($html,'Assign lead')&&!str_contains($html,'type="submit">Assign')&&!str_contains($html,'ticket_viewers_add_override'));
check('the page has no POST form at all (filters are GET)',substr_count($html,'method="post"')===0);
// The old POST actions are refused by the module (no handler left)
check('the module source has no assign/override handlers',!str_contains((string)file_get_contents(dirname(__DIR__).'/app/TicketViewerModule.php'),'assignLeads')&&!str_contains((string)file_get_contents(dirname(__DIR__).'/app/TicketViewerModule.php'),'addOverride'));

// ---- Sidebar: HR Request Setup near the top for HR; Ticket Viewers needs tickets.approvers.manage; Printable HR Forms and Accounting Members stay
$navVisible=function(string $kind):array{$out=[];foreach(nav_config($kind)['groups'] as [$g,$items]){foreach($items as $i){[$l,,,,$perm]=array_pad($i,5,null);if($perm===null||Auth::can($perm))$out[$g][]=$l;}}return $out;};
act($hr);$nav=$navVisible('hr');$groups=array_column(nav_config('hr')['groups'],0);
check('HR sidebar keeps Ticket Viewers, Accounting Members and Printable HR Forms under HR Request Setup',!array_diff(['Ticket Viewers','Accounting Members','Printable HR Forms'],$nav['HR Request Setup']??[]));
check('HR Request Setup is the third sidebar group, above Payroll & Timekeeping',array_search('HR Request Setup',$groups,true)===2&&array_search('HR Request Setup',$groups,true)<array_search('Payroll & Timekeeping',$groups,true));
$navOnly=person($tag,'navonly',role($tag,'navonly',['tickets.review_hr'],'hr'),$f['branch'],$f,$baPos);act($navOnly['user']);$nav=$navVisible('hr');
check('an HR role without tickets.approvers.manage gets no Ticket Viewers or Ticket Approvers entry',!in_array('Ticket Viewers',$nav['HR Request Setup']??[],true)&&!in_array('Ticket Approvers',$nav['HR Request Setup']??[],true));
act($f['employee']);check('the employee portal sidebar has no HR Request Setup group',!isset($navVisible('employee')['HR Request Setup']));
// ---- Existing paths unchanged
act($hr);check('HR service queue still lists the branch ticket',(function()use($t1){foreach(TicketRepository::listing('services',[],100)['rows'] as $r)if((int)$r['id']===$t1)return true;return false;})());
act($staff['user']);check('requester still sees and edits own ticket path',TicketRepository::find($t1)['viewer_only']===false);
finish();
