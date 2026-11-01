<?php
declare(strict_types=1);
// Team Requests leads (Head Office Manager; Retail ADL, Sales Captain, OIC) are notified about a request and can follow up with HR once a day.
// Synthetic fixtures only; run with HRIS_TEST_DB pointing at an isolated copy with migration 019 applied.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/TicketViewerService.php';
require_once dirname(__DIR__).'/app/TicketLeadFollowup.php';
require_once dirname(__DIR__).'/app/EmployeeDirectoryPlacement.php';
$f=fixtures();$tag=$f['tag'];

function perm(int $role,string $code):void {
    $p=row('SELECT id FROM permissions WHERE code=?',[$code]);
    db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);Auth::clearPermissionCache();
}
function role(string $tag,string $key,array $perms):int { $id=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,"employee")',[$tag.'_'.$key,'Slice15 '.$key]);foreach($perms as $c)perm($id,$c);return $id; }
function person(string $tag,string $key,int $roleId,int $branch,array $f,?int $position=null,?int $manager=null,bool $account=true):array {
    $uid=null;$user=null;$email=strtolower($tag.'_lf_'.$key).'@example.test';
    if($account){
        $r=row('SELECT code,portal FROM roles WHERE id=?',[$roleId]);
        $uid=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$roleId,'Slice15 Test '.$key,$email,password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
        $user=['id'=>$uid,'role_id'=>$roleId,'role_code'=>$r['code'],'role_portal'=>$r['portal'],'name'=>'Slice15 Test '.$key,'role_name'=>'Test '.$key,'email'=>$email,'client_id'=>null];
    }
    $emp=insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,branch_id,position_id,manager_employee_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,?,?,?,"ACTIVE",?)',[$tag.'_lf_'.$key,'Slice15','Test '.$key,$uid,$branch,$position,$manager,$f['legal'],$f['company'],date('Y-m-d',strtotime('-2 years'))]);
    return ['user'=>$user,'employee'=>$emp];
}
function typeId(string $code):int { return (int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id']; }
function atm(array $user,bool $submit=true):int { act($user);return TicketService::create(array_replace(incident_fields('ATM_REQUEST'),['ticket_type_id'=>typeId('ATM_REQUEST'),'submission_key'=>bin2hex(random_bytes(32)),'reason'=>'Synthetic lead follow-up test']),$submit); }
function notes(array $p,int $ticket):int { $q=db()->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_user_id=? AND link=?');$q->execute([$p['user']['id'],url('hr-request',['id'=>$ticket])]);return (int)$q->fetchColumn(); }
function sees(array $user,int $ticket):bool { act($user);try{TicketRepository::find($ticket);return true;}catch(RuntimeException){return false;} }
function followUpBy(array $p,int $ticket):bool { act($p['user']);return !rejects(fn()=>TicketLeadFollowup::followUp($ticket)); }

// ---- Fixtures
check('migration 019 table exists',FoundationRepository::tableExists('ticket_lead_followups'));
$ho=insert('INSERT INTO branches(code,name,site_type) VALUES(NULL,?,"HEAD_OFFICE")',['T15 Head Office '.$tag]);
$area=insert('INSERT INTO areas(code,name) VALUES(?,?)',[$tag.'_LA','T15 Lead Area']);
$br=insert('INSERT INTO branches(code,name,site_type,area_id) VALUES(?,?,"BRANCH",?)',[$tag.'_LB','T15 Lead Branch',$area]);
$viewRole=role($tag,'view',['ticket.view_team','tickets.request_self','ticket.file.atm','ticket.file.incident']);
$staffRole=role($tag,'staff',['tickets.request_self','ticket.file.atm','ticket.file.incident']);
$mgrRole=(int)row('SELECT id FROM roles WHERE code="MANAGER"')['id'];
$scPos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$tag.'_SC','Sales Captain']);
$adlPos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$tag.'_ADL','Area Development Leader']);
$baPos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$tag.'_BA','Beauty Advisor']);
$mgr=person($tag,'mgr',$mgrRole,$ho,$f);
$mgrOther=person($tag,'mgro',$mgrRole,$ho,$f);
$hoStaff=person($tag,'hostaff',$staffRole,$ho,$f,null,$mgr['employee']);
$retail=person($tag,'retail',$staffRole,$br,$f,$baPos);
$sc=person($tag,'sc',$viewRole,$br,$f,$scPos);
$adl=person($tag,'adl',$viewRole,$ho,$f,$adlPos);
db()->prepare('UPDATE employees SET directory_role="ADL",directory_area_ids=? WHERE id=?')->execute([','.$area.',',$adl['employee']]);
$other=person($tag,'other',$viewRole,$br,$f,$baPos);   // permitted but not a lead of this branch
$hr=$f['hr'];

// ---- Head Office: the Reporting To Manager hears about it
$tHo=atm($hoStaff['user']);$no=(string)row('SELECT ticket_no FROM tickets WHERE id=?',[$tHo])['ticket_no'];
check('HO: the Manager is notified when the request is filed',notes($mgr,$tHo)===1);
check('HO: another Manager is not notified',notes($mgrOther,$tHo)===0);
check('HO: the notification carries the ticket number only',(bool)row('SELECT 1 FROM notifications WHERE recipient_user_id=? AND title LIKE ? AND body NOT LIKE "%Synthetic%"',[$mgr['user']['id'],'%'.$no.'%']));
$tDraft=atm($hoStaff['user'],false);
check('HO: a draft notifies nobody',notes($mgr,$tDraft)===0);
// An Incident Report has its own filing path (needs a full employee profile); retype an ATM ticket to prove the lead rules refuse it.
$tInc=atm($hoStaff['user']);db()->prepare('UPDATE tickets SET ticket_type_id=? WHERE id=?')->execute([typeId('INCIDENT_REPORT'),$tInc]);

// ---- Every status change notifies again; comments do not
$ver=(int)row('SELECT version FROM tickets WHERE id=?',[$tHo])['version'];act($hr);TicketService::act($tHo,$ver,'START','Working on it');
check('HO: a status change notifies the Manager again',notes($mgr,$tHo)===2);
act($hoStaff['user']);try{TicketService::comment($tHo,'Synthetic comment','ALL');}catch(Throwable){}
check('a comment does not notify the lead',notes($mgr,$tHo)===2);

// ---- Retail: ADL and Sales Captain hear about it, a bystander does not
$tR=atm($retail['user']);
check('Retail: the ADL is notified',notes($adl,$tR)===1);
check('Retail: the Sales Captain is notified',notes($sc,$tR)===1);
check('Retail: a permitted non-lead is not notified',notes($other,$tR)===0);

// ---- A lead with no account is skipped; filing still works
$noAcct=person($tag,'noacct',$mgrRole,$ho,$f,null,null,false);
$staff2=person($tag,'staff2',$staffRole,$ho,$f,null,$noAcct['employee']);
$t2=0;try{$t2=atm($staff2['user']);}catch(Throwable){}
check('a lead without an account does not block the filing',$t2>0);

// ---- Follow up
check('lead sees the request read-only',sees($mgr['user'],$tHo)&&TicketRepository::find($tHo)['viewer_only']===true);
$before=(int)row('SELECT COUNT(*) c FROM notifications WHERE kind="HR_REQUEST" AND title LIKE "Follow up%"')['c'];
check('follow up is accepted from the lead',followUpBy($mgr,$tHo));
check('follow up is recorded',(int)row('SELECT COUNT(*) c FROM ticket_lead_followups WHERE ticket_id=? AND user_id=?',[$tHo,$mgr['user']['id']])['c']===1);
check('follow up reaches HR reviewers',(int)row('SELECT COUNT(*) c FROM notifications WHERE kind="HR_REQUEST" AND title LIKE "Follow up%"')['c']>$before&&TicketNotifications::hrReviewerIds()!==[]);
check('follow up text has no concern content',!row('SELECT 1 FROM notifications WHERE title LIKE "Follow up%" AND body LIKE "%Synthetic%"'));
check('a second follow up within 24h is refused',!followUpBy($mgr,$tHo));
db()->prepare('UPDATE ticket_lead_followups SET created_at=DATE_SUB(NOW(),INTERVAL 25 HOUR) WHERE ticket_id=?')->execute([$tHo]);
check('after 24h a follow up is allowed again',followUpBy($mgr,$tHo));
check('the requester cannot follow up',!followUpBy($hoStaff,$tHo));
check('a non-lead cannot follow up',!followUpBy($other,$tHo));
check('a lead cannot follow up an Incident Report',!followUpBy($mgr,$tInc));
$ver=(int)row('SELECT version FROM tickets WHERE id=?',[$tHo])['version'];act($hr);TicketService::act($tHo,$ver,'COMPLETE','Done');
check('no follow up on a closed request',!followUpBy($mgr,$tHo));

// ---- "New" badge
$adlId=(int)$adl['user']['id'];$scId=(int)$sc['user']['id'];
check('badge: unread request is flagged New for the ADL',TicketLeadFollowup::unreadTicketIds($adlId,[$tR])===[$tR]);
TicketLeadFollowup::markSeen($tR,$adlId);
check('badge: cleared once the lead opens it',TicketLeadFollowup::unreadTicketIds($adlId,[$tR])===[]);
check('badge: other leads are not affected',TicketLeadFollowup::unreadTicketIds($scId,[$tR])===[$tR]);
// ---- Sidebar badge and rendering
require_once dirname(__DIR__).'/app/View.php';require_once dirname(__DIR__).'/app/TicketViews.php';
$n=['kind'=>'HR_REQUEST','type_code'=>'ATM_REQUEST','requester_user_id'=>1,'subject_user_id'=>1,'assigned_approval'=>0,'ticket_status'=>'PENDING_HR','incident_readable'=>0,'incident_mine'=>0];
$acc=['incidents'=>true,'self'=>false,'hr'=>false,'approve'=>false,'accounting'=>false,'reminders'=>false,'team'=>true];
check('sidebar: a lead notification counts on Team Requests',NotificationService::sidebarPage($n,$acc,99)==='hr-request-team');
check('sidebar: without view_team it counts nowhere',NotificationService::sidebarPage($n,array_replace($acc,['team'=>false]),99)===null);
act($mgr['user']);$tf=$tHo;db()->prepare('UPDATE tickets SET status="PENDING_HR" WHERE id=?')->execute([$tf]);db()->prepare('DELETE FROM ticket_lead_followups WHERE ticket_id=?')->execute([$tf]);
ob_start();ticket_followup_bar(TicketRepository::find($tf));$html=ob_get_clean();
check('bar: a lead sees the Follow up button and a CSRF form',str_contains($html,'Follow up with HR')&&str_contains($html,'core_ticket_followup'));
TicketLeadFollowup::followUp($tf);ob_start();ticket_followup_bar(TicketRepository::find($tf));$html=ob_get_clean();
check('bar: after following up the button is disabled',str_contains($html,'disabled')&&!str_contains($html,'core_ticket_followup'));
act($hoStaff['user']);ob_start();ticket_followup_bar(TicketRepository::find($tf));$html=ob_get_clean();
check('bar: the requester never sees it',$html==='');
finish();
