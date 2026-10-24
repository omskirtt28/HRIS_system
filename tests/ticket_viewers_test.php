<?php
declare(strict_types=1);
// Migration 012: read-only ticket viewers (branch leads, ADL areas, Head Office Manager, HR override). Synthetic fixtures only.
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
/** @return array{user:array,employee:int} an account plus a linked active employee at $branch */
function person(string $tag,string $key,int $roleId,int $branch,array $f,?int $positionId=null):array {
    $r=row('SELECT code,portal FROM roles WHERE id=?',[$roleId]);$email=strtolower($tag.'_v_'.$key).'@example.test';
    $uid=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$roleId,'Slice15 Test '.$key,$email,password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
    $emp=insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,branch_id,position_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,?,?,"ACTIVE",?)',[$tag.'_v_'.$key,'Slice15','Test '.$key,$uid,$branch,$positionId,$f['legal'],$f['company'],date('Y-m-d',strtotime('-1 year'))]);
    return ['user'=>['id'=>$uid,'role_id'=>$roleId,'role_code'=>$r['code'],'role_portal'=>$r['portal'],'name'=>'Slice15 Test '.$key,'role_name'=>'Test '.$key,'email'=>$email,'client_id'=>null],'employee'=>$emp,'no'=>$tag.'_v_'.$key];
}
function sees(array $user,int $ticket):bool { act($user);try{TicketRepository::find($ticket);return true;}catch(RuntimeException){return false;} }
function listed(array $user,int $ticket,array $filters=[]):bool { act($user);foreach(TicketRepository::listing('team',$filters,100)['rows'] as $r)if((int)$r['id']===$ticket)return true;return false; }
function file_ticket(array $user,string $code,array $extra,bool $submit=true):int { act($user);return TicketService::create(form($code,$extra),$submit); }
function atm(array $user,bool $submit=true):int { return file_ticket($user,'ATM_REQUEST',['reason'=>'Synthetic viewer test'],$submit); }
function steps(int $ticket):int { return (int)row('SELECT COUNT(*) c FROM ticket_steps WHERE ticket_id=?',[$ticket])['c']; }

// ---- Migration 012 shape
check('ticket.view_team exists',row('SELECT id FROM permissions WHERE code="ticket.view_team"')!==[]);
$granted=array_column(db()->query('SELECT r.code FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.code="ticket.view_team" AND r.code NOT LIKE "T15\_%" ORDER BY r.code')->fetchAll(),'code');
check('seeded to ADL, SC, OIC, TC, MANAGER only (deny by default)',$granted===['ADL','MANAGER','OIC','SC','TC']);
check('EMPLOYEE and HR roles do not hold it',!array_intersect(['EMPLOYEE','HR_USER','HRIS_ADMIN'],$granted));

// ---- Fixtures: two areas, branches in them, an area-less branch.
$areaA=insert('INSERT INTO areas(code,name) VALUES(?,?)',[$tag.'_A','T15 Area A']);$areaB=insert('INSERT INTO areas(code,name) VALUES(?,?)',[$tag.'_B','T15 Area B']);
db()->prepare('UPDATE branches SET area_id=? WHERE id=?')->execute([$areaA,$f['branch']]);
$brB=insert('INSERT INTO branches(code,name,site_type,area_id) VALUES(?,?,"BRANCH",?)',[$tag.'_BB','T15 Branch B',$areaB]);
$brNone=insert('INSERT INTO branches(code,name,site_type) VALUES(?,?,"BRANCH")',[$tag.'_BN','T15 Branch no area']);
$viewRole=role($tag,'viewrole',['ticket.view_team','tickets.request_self','ticket.file.atm','ticket.file.incident']);
$plainRole=role($tag,'plainrole',['tickets.request_self','ticket.file.atm','ticket.file.incident']);
$adlPos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$tag.'_ADL','Area Development Leader']);

$staff=person($tag,'staff',$plainRole,$f['branch'],$f);          // subject at the area A branch
$staffB=person($tag,'staffb',$plainRole,$brB,$f);                // subject at the area B branch
$staffN=person($tag,'staffn',$plainRole,$brNone,$f);             // subject at the branch with no area / no lead
$lead=person($tag,'lead',$viewRole,$f['branch'],$f);             // SC / TC at the area A branch
$lead2=person($tag,'lead2',$viewRole,$f['branch'],$f);           // second lead
$leadNoPerm=person($tag,'leadnoperm',$plainRole,$f['branch'],$f);// assigned lead but role lacks the permission
$stranger=person($tag,'stranger',$viewRole,$brB,$f);             // has the permission, not in scope of the area A branch
$adl=person($tag,'adl',$viewRole,$f['ho'],$f,$adlPos);           // ADL whose own branch is Head Office
$hr=$f['hr'];$hrAdminEmployee=null;

// ---- Tickets
$t1=atm($staff['user']);$t2=atm($staff['user'],false);$t3=file_ticket($staff['user'],'INCIDENT_REPORT',['title'=>'T15 incident','explanation'=>'Synthetic'],true);
$tB=atm($staffB['user']);$tN=atm($staffN['user']);$tHo=atm($f['employee']);
check('ATM filed and routed HR only',(string)row('SELECT status FROM tickets WHERE id=?',[$t1])['status']==='PENDING_HR'&&(string)row('SELECT status FROM tickets WHERE id=?',[$t2])['status']==='DRAFT');
$stepsBefore=steps($t1);

// ---- Nobody is a viewer until HR sets it up
check('no lead yet: the permitted lead cannot see the ticket',!sees($lead['user'],$t1)&&!listed($lead['user'],$t1));
act($lead['user']);check('own team list renders empty, not an error',TicketRepository::listing('team')['rows']===[]);

// ---- HR assigns leads (bulk, several leads per branch)
act($f['employee']);check('non-HR cannot assign leads',rejects(fn()=>TicketViewerService::assignLeads(['branch_ids'=>[$f['branch']],'lead_employee_no'=>$lead['no'],'effective_from'=>date('Y-m-d')])));
act($hr);
check('Head Office has no branch lead',rejects(fn()=>TicketViewerService::assignLeads(['branch_ids'=>[$f['ho']],'lead_employee_no'=>$lead['no'],'effective_from'=>date('Y-m-d')])));
check('unknown employee number rejected',rejects(fn()=>TicketViewerService::assignLeads(['branch_ids'=>[$f['branch']],'lead_employee_no'=>'NOPE','effective_from'=>date('Y-m-d')])));
check('past effective date rejected',rejects(fn()=>TicketViewerService::assignLeads(['branch_ids'=>[$f['branch']],'lead_employee_no'=>$lead['no'],'effective_from'=>date('Y-m-d',strtotime('-1 day'))])));
[$added,$skipped]=TicketViewerService::assignLeads(['branch_ids'=>[$f['branch'],$brB],'lead_employee_no'=>$lead['no'],'effective_from'=>date('Y-m-d')]);
check('bulk assign saves one lead per selected branch',$added===2&&$skipped===0);
[$added,$skipped]=TicketViewerService::assignLeads(['branch_ids'=>[$f['branch']],'lead_employee_no'=>$lead['no'],'effective_from'=>date('Y-m-d')]);
check('same lead twice is skipped, not duplicated',$added===0&&$skipped===1);
[$added]=TicketViewerService::assignLeads(['branch_ids'=>[$f['branch']],'lead_employee_no'=>$lead2['no'],'effective_from'=>date('Y-m-d')]);
check('a branch may have several leads',$added===1&&(int)row('SELECT COUNT(*) c FROM branch_leads WHERE branch_id=?',[$f['branch']])['c']===2);
TicketViewerService::assignLeads(['branch_ids'=>[$f['branch']],'lead_employee_no'=>$leadNoPerm['no'],'effective_from'=>date('Y-m-d')]);
check('assignment is audited',row('SELECT id FROM audit_logs WHERE action="ASSIGN_BRANCH_LEAD" AND record_id=?',[$f['branch']])!==[]);

// ---- Viewer sees, read-only
check('lead sees the branch ticket by detail URL',sees($lead['user'],$t1));
check('second lead sees it too',sees($lead2['user'],$t1)&&listed($lead2['user'],$t1));
check('lead sees it in the team list',listed($lead['user'],$t1));
act($lead['user']);$tk=TicketRepository::find($t1);check('ticket is flagged viewer_only',$tk['viewer_only']===true);
$p=TicketService::permissions($tk);check('every action permission is false for a viewer',!array_filter($p));
check('draft is hidden from viewers (list and URL)',!sees($lead['user'],$t2)&&!listed($lead['user'],$t2));
check('Incident Report hidden from viewers (list and URL)',!sees($lead['user'],$t3)&&!listed($lead['user'],$t3));
check('other branch tickets stay hidden from the lead',!sees($lead['user'],$tN)&&!sees($lead['user'],$tHo));
check('role without ticket.view_team is denied even when assigned lead',!sees($leadNoPerm['user'],$t1)&&rejects(fn()=>TicketRepository::listing('team')));
act($leadNoPerm['user']);check('no-permission team list throws',rejects(fn()=>TicketRepository::listing('team')));
check('permitted person outside scope is denied by guessed URL',!sees($stranger['user'],$t1));
check('plain staff of another branch denied by guessed URL',!sees($staffB['user'],$t1)&&!sees($f['outsider'],$t1));

// Forged POSTs (service layer is what the POST handler calls)
act($lead['user']);$ver=(int)TicketRepository::find($t1)['version'];
foreach(['APPROVE','START','COMPLETE','RETURN','REJECT','CANCEL'] as $action)check('forged '.$action.' by viewer denied',rejects(fn()=>TicketService::act($t1,$ver,$action,'forged remarks','NO_CHANGE')));
check('forged comment by viewer denied',rejects(fn()=>TicketService::comment($t1,'forged comment')));
check('forged staff-only comment by viewer denied',rejects(fn()=>TicketService::comment($t1,'forged','STAFF_ONLY')));
check('forged attachment upload by viewer denied',rejects(fn()=>TicketService::attach($t1,['name'=>['x.pdf'],'tmp_name'=>['x'],'error'=>[0],'size'=>[1],'type'=>['application/pdf']])));
check('forged edit by viewer denied',rejects(fn()=>TicketService::edit($t1,$ver,form('ATM_REQUEST',['reason'=>'x']),true)));
check('forged NetSuite remark by viewer denied',rejects(fn()=>TicketAccounting::remark($t1,'ACCOUNTING','forged')));
check('nothing changed after the forged attempts',(int)row('SELECT version v FROM tickets WHERE id=?',[$t1])['v']===$ver&&(int)row('SELECT COUNT(*) c FROM ticket_comments WHERE ticket_id=?',[$t1])['c']===0&&steps($t1)===$stepsBefore&&(string)row('SELECT status FROM tickets WHERE id=?',[$t1])['status']==='PENDING_HR');
// Attachments: not listed, not downloadable
$att=insert('INSERT INTO ticket_attachments(ticket_id,original_name,stored_name,mime_type,file_size,uploaded_by) VALUES(?,?,?,?,?,?)',[$t1,'evidence.pdf',bin2hex(random_bytes(24)).'.pdf','application/pdf',10,$staff['user']['id']]);
act($lead['user']);check('viewer detail lists no attachments',TicketRepository::detail($t1)['attachments']===[]);
check('viewer cannot download an attachment',rejects(fn()=>TicketService::attachment($att)));
act($staff['user']);check('requester still lists their attachment',count(TicketRepository::detail($t1)['attachments'])===1);
// Staff-only comments by HR on the ticket stay hidden from the viewer; ALL comments are visible.
act($hr);TicketService::comment($t1,'T15 visible comment');
act($lead['user']);$d=TicketRepository::detail($t1);check('viewer reads comments and events',count($d['comments'])===1&&count($d['events'])>=2);
// Page output carries no action forms
foreach([[$t1,'ticket_detail_page']] as [$id]){ob_start();ticket_detail_page(TicketRepository::detail($id));$html=ob_get_clean();
    check('viewer detail page has no decision, comment or upload form',!str_contains($html,'core_ticket_comment')&&!str_contains($html,'core_ticket_act')&&!str_contains($html,'core_ticket_attach')&&str_contains($html,'View-only'));}
ob_start();ticket_list_page('team',TicketRepository::listing('team'),[]);$html=ob_get_clean();check('team list page renders the ticket',str_contains($html,TicketRepository::find($t1)['ticket_no'])&&str_contains($html,'view only'));
// Notifications: viewers receive nothing
$q=db()->prepare('SELECT COUNT(*) c FROM ticket_notification_outbox o JOIN ticket_events e ON e.id=o.event_id WHERE e.ticket_id=? AND o.recipient_user_id IN (?,?)');$q->execute([$t1,$lead['user']['id'],$lead2['user']['id']]);
check('viewers are never queued for notifications',(int)$q->fetchColumn()===0);
// Own tickets are not duplicated into the team list; HR path is unchanged
$own=atm($lead['user']);act($lead['user']);check('lead sees own ticket directly, not in team list',!TicketRepository::find($own)['viewer_only']&&!listed($lead['user'],$own));
act($hr);check('HR still sees the ticket directly (not viewer_only)',TicketRepository::find($t1)['viewer_only']===false&&TicketRepository::find($t3)['viewer_only']===false);

// ---- Frozen branch_id: a transfer does not move old tickets
db()->prepare('UPDATE employees SET branch_id=? WHERE id=?')->execute([$brB,$staff['employee']]);
check('after the employee moves, the old branch lead keeps the old ticket',sees($lead['user'],$t1));
check('new branch lead does not get the old ticket (frozen branch_id)',!sees($stranger['user'],$t1));
db()->prepare('UPDATE employees SET branch_id=? WHERE id=?')->execute([$f['branch'],$staff['employee']]);

// ---- Lead changes: access follows the dates, routing steps untouched
act($hr);$leadRow=(int)row('SELECT id FROM branch_leads WHERE branch_id=? AND employee_id=?',[$f['branch'],$lead['employee']])['id'];
TicketViewerService::endLead($leadRow);
check('ended lead loses the open ticket at once, second lead keeps it',!sees($lead['user'],$t1)&&sees($lead2['user'],$t1));
check('ending a lead does not touch ticket steps or status',steps($t1)===$stepsBefore&&(string)row('SELECT status FROM tickets WHERE id=?',[$t1])['status']==='PENDING_HR');
act($hr);check('ending twice is refused',rejects(fn()=>TicketViewerService::endLead($leadRow)));
act($hr);TicketViewerService::assignLeads(['branch_ids'=>[$f['branch']],'lead_employee_no'=>$lead['no'],'effective_from'=>date('Y-m-d',strtotime('+3 days'))]);
check('a future-dated lead sees nothing yet',!sees($lead['user'],$t1));
db()->prepare('UPDATE branch_leads SET effective_from=CURDATE() WHERE branch_id=? AND employee_id=? AND effective_from>CURDATE()')->execute([$f['branch'],$lead['employee']]);
check('the same lead sees the full history once effective',sees($lead['user'],$t1));
act($hr);TicketViewerService::assignLeads(['branch_ids'=>[$f['branch']],'lead_employee_no'=>$lead['no'],'effective_from'=>date('Y-m-d',strtotime('+30 days'))]);
db()->prepare('UPDATE employees SET status="INACTIVE" WHERE id=?')->execute([$lead2['employee']]);
check('an inactive lead employee loses access',!sees($lead2['user'],$t1));
db()->prepare('UPDATE employees SET status="ACTIVE" WHERE id=?')->execute([$lead2['employee']]);
db()->prepare('UPDATE users SET status="INACTIVE" WHERE id=?')->execute([$lead2['user']['id']]);
check('a lead with a disabled account loses access',!sees($lead2['user'],$t1));
db()->prepare('UPDATE users SET status="ACTIVE" WHERE id=?')->execute([$lead2['user']['id']]);

// ---- ADL coverage from assigned areas
$areaIds=static fn(array $ids)=>$ids?','.implode(',',$ids).',':null;
$setAreas=function(array $ids)use($adl,$f){db()->prepare('UPDATE employees SET directory_role="ADL",directory_area_ids=? WHERE id=?')->execute([$ids?','.implode(',',$ids).',':null,$adl['employee']]);};
$setAreas([]);check('ADL with no assigned area sees nothing',!sees($adl['user'],$t1)&&!sees($adl['user'],$tB));
$setAreas([$areaA]);check('ADL sees the branches of an assigned area',sees($adl['user'],$t1)&&listed($adl['user'],$t1));
check('ADL does not see another area, Head Office or no-area branches',!sees($adl['user'],$tB)&&!sees($adl['user'],$tN)&&!sees($adl['user'],$tHo));
act($adl['user']);check('ADL is read-only',TicketRepository::find($t1)['viewer_only']===true&&rejects(fn()=>TicketService::act($t1,(int)TicketRepository::find($t1)['version'],'COMPLETE','x','NO_CHANGE'))&&rejects(fn()=>TicketService::comment($t1,'x')));
$setAreas([$areaB]);check('changing the area moves coverage at once',!sees($adl['user'],$t1)&&sees($adl['user'],$tB));
$setAreas([$areaA,$areaB]);check('several areas cover all of them',sees($adl['user'],$t1)&&sees($adl['user'],$tB));
$brNew=insert('INSERT INTO branches(code,name,site_type,area_id) VALUES(?,?,"BRANCH",?)',[$tag.'_NEW','T15 New branch',$areaA]);
$staffNew=person($tag,'staffnew',$plainRole,$brNew,$f);$tNew=atm($staffNew['user']);
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
perm($viewRole,'ticket.view_team',false);check('removing ticket.view_team ends all viewer access',!sees($adl['user'],$t1)&&!sees($lead2['user'],$t1));
perm($viewRole,'ticket.view_team',true);check('and granting it restores access',sees($adl['user'],$t1));
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
check('branch lead rule never reaches Head Office tickets',!sees($lead2['user'],$tHo));

// ---- Per-employee override (extra viewer)
act($hr);check('override cannot make someone their own viewer',rejects(fn()=>TicketViewerService::addOverride(['employee_no'=>$staffN['no'],'viewer_employee_no'=>$staffN['no'],'effective_from'=>date('Y-m-d')])));
check('stranger cannot see the no-area branch ticket yet',!sees($stranger['user'],$tN));
act($hr);$ov=TicketViewerService::addOverride(['employee_no'=>$staffN['no'],'viewer_employee_no'=>$stranger['no'],'effective_from'=>date('Y-m-d')]);
check('override gives that one employee\'s tickets to the extra viewer',sees($stranger['user'],$tN)&&listed($stranger['user'],$tN));
check('override covers only that employee',!sees($stranger['user'],$t1));
check('override viewer is read-only too',rejects(fn()=>TicketService::comment($tN,'x')));
act($hr);check('duplicate override rejected',rejects(fn()=>TicketViewerService::addOverride(['employee_no'=>$staffN['no'],'viewer_employee_no'=>$stranger['no'],'effective_from'=>date('Y-m-d')])));
TicketViewerService::endOverride($ov);check('ending the override removes access at once',!sees($stranger['user'],$tN));
check('override still never exposes an Incident Report',(function()use($hr,$staffN,$stranger,$tag){act($hr);TicketViewerService::addOverride(['employee_no'=>$staffN['no'],'viewer_employee_no'=>$stranger['no'],'effective_from'=>date('Y-m-d')]);
    $inc=file_ticket($staffN['user'],'INCIDENT_REPORT',['title'=>'T15 incident n','explanation'=>'Synthetic'],true);return !sees($stranger['user'],$inc);})());

// ---- Branch with no lead: HR only, and the report names it
act($hr);$rep=TicketViewerService::report();
$noLead=array_column($rep['branches_without_lead'],'id');
check('report lists the branch with employees and no lead',in_array($brNone,$noLead,true)&&!in_array($f['branch'],$noLead,true));
$missing=[];for($pg=1;$pg<=$rep['pages'];$pg++)foreach(TicketViewerService::report($pg)['missing'] as $m)$missing[]=(int)$m['id'];
check('report lists employees with no resolvable viewer (and not covered ones)',in_array($staffNew['employee'],$missing,true)===false&&in_array($staff['employee'],$missing,true)===false);
db()->prepare('UPDATE branch_leads SET superseded_at=NOW() WHERE branch_id=?')->execute([$brB]);db()->prepare('UPDATE ticket_viewer_overrides SET superseded_at=NOW()')->execute();
db()->prepare('UPDATE employees SET directory_area_ids=NULL WHERE id=?')->execute([$adl['employee']]);
$rep=TicketViewerService::report();$missing=[];for($pg=1;$pg<=$rep['pages'];$pg++)foreach(TicketViewerService::report($pg)['missing'] as $m)$missing[]=(int)$m['id'];
check('uncovered employees appear in the missing list',in_array($staffN['employee'],$missing,true)&&in_array($staffB['employee'],$missing,true));
check('report counts add up',$rep['counts']['with_viewer']+$rep['missing_total']===$rep['counts']['employees']);
check('report flags the ADL without areas',(bool)array_filter($rep['adls_unready'],fn($a)=>(int)$a['id']===$adl['employee']));
check('report flags the ADL whose branch is Head Office',(bool)array_filter($rep['adls_in_head_office'],fn($a)=>(int)$a['id']===$adl['employee']));
db()->prepare('UPDATE branches SET area_id=NULL WHERE id=?')->execute([$brB]);
check('report lists retail branches without an area',in_array($brB,array_column(TicketViewerService::report()['branches_without_area'],'id'),true));
db()->prepare('UPDATE branches SET area_id=? WHERE id=?')->execute([$areaB,$brB]);
act($f['employee']);check('the report is HR only',rejects(fn()=>TicketViewerService::report()));
ob_start();act($hr);ticket_viewers_page(['area_id'=>0,'branch_id'=>0,'p'=>1],TicketViewerService::branchRows(0,0),TicketViewerService::report(),TicketViewerService::overrides(),TicketViewerService::leadCandidates());$html=ob_get_clean();
check('HR Ticket Viewers page renders',str_contains($html,'Unresolved viewers')&&str_contains($html,'Branch leads'));

// ---- Existing paths unchanged
act($hr);check('HR service queue still lists the branch ticket',(function()use($t1){foreach(TicketRepository::listing('services',[],100)['rows'] as $r)if((int)$r['id']===$t1)return true;return false;})());
act($staff['user']);check('requester still sees and edits own ticket path',TicketRepository::find($t1)['viewer_only']===false);
finish();
