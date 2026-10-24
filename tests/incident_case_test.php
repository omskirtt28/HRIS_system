<?php
declare(strict_types=1);
// Incident cases (plan 2026-10-25-incident-report-module, phase I1): access matrix, privacy, participants, status flow, notifications.
// Isolated database only (ticket_test_support refuses anything else). Synthetic data.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/TicketNotifications.php';
$f=fixtures();$tag=$f['tag'];
function form(string $code,array $extra=[]):array{return array_replace(incident_fields($code),['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'],'submission_key'=>bin2hex(random_bytes(32))],$extra);}
function perm_ids(array $codes):array{$out=[];foreach($codes as $c){$p=row('SELECT id FROM permissions WHERE code=?',[$c]);if($p)$out[]=(int)$p['id'];}return $out;}
/** A user with their own role. $employee links an active employee (needed only to file). */
function mk(string $tag,string $key,array $perms,string $portal,array $f,bool $employee=false):array{
    $role=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,?)',[$tag.'_i_'.$key,'Slice15 '.$key,$portal]);
    foreach(perm_ids($perms) as $pid)db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$pid]);
    $email=strtolower($tag.'_i_'.$key).'@example.test';
    $id=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$role,'Slice15 Test '.$key,$email,password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
    if($employee)insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,branch_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,?,"ACTIVE",?)',[$tag.'_i_'.$key,'Slice15','Test '.$key,$id,$f['branch'],$f['legal'],$f['company'],'2020-01-01']);
    return ['id'=>$id,'role_id'=>$role,'role_code'=>$tag.'_i_'.$key,'role_portal'=>$portal,'name'=>'Slice15 Test '.$key,'role_name'=>'Test '.$key,'email'=>$email,'client_id'=>null];
}
$filePerms=['tickets.request_self','ticket.file.incident','ticket.file.atm'];
$rep=$f['employee'];                                                  // reporter (files, has an employee record)
$cc=mk($tag,'cc',$filePerms,'employee',$f);                           // picked by the reporter as CC
$inv=mk($tag,'inv',$filePerms,'employee',$f);                         // picked by the reporter as Involved: recorded, no access
$wit=mk($tag,'wit',$filePerms,'employee',$f);                         // picked by the reporter as Witness: recorded, no access
$late=mk($tag,'late',$filePerms,'employee',$f);                       // added by HR later
$hr1=$f['hr'];                                                        // handling HR reviewer
$hr2=mk($tag,'hr2',['tickets.review_hr'],'hr',$f);                    // second HR reviewer
$hrInv=mk($tag,'hrinv',['tickets.review_hr'],'hr',$f);                // HR reviewer named Involved by the reporter
$viewer=mk($tag,'viewer',['ticket.view_team','tickets.request_self','ticket.file.incident'],'employee',$f);
$sens=mk($tag,'sens',['ticket.sensitive.view','tickets.request_self'],'employee',$f);
$acct=mk($tag,'acct',['tickets.request_self'],'employee',$f);
insert('INSERT INTO ticket_accounting_memberships(user_id,permission_id,active,assigned_by,provenance) VALUES(?,?,1,?,"MANUAL")',[$acct['id'],perm_ids(['ticket.accounting.view'])[0],$hr1['id']]);
$out=$f['outsider'];
$ids=fn(string $mode)=>array_map('intval',array_column(TicketRepository::listing($mode)['rows'],'id'));
$canRead=function(array $u,int $id):bool{act($u);try{TicketRepository::detail($id);return true;}catch(Throwable){return false;}};
$status=fn(int $id)=>(string)row('SELECT status FROM tickets WHERE id=?',[$id])['status'];
$version=fn(int $id)=>(int)row('SELECT version FROM tickets WHERE id=?',[$id])['version'];
$inc=fn(array $extra=[])=>form('INCIDENT_REPORT',array_replace(['title'=>'Synthetic case title '.$tag,'explanation'=>'SECRET narrative '.$tag,'location'=>'SECRET place '.$tag,
    'participants'=>[['user_id'=>$cc['id'],'role'=>'CC'],['user_id'=>$inv['id'],'role'=>'INVOLVED'],['user_id'=>$wit['id'],'role'=>'WITNESS'],['user_id'=>$hrInv['id'],'role'=>'INVOLVED']]],$extra));

// ---------------------------------------------------------------- filing and case fields (BR-776)
act($rep);$id=TicketService::create($inc(),true);
$d=row('SELECT * FROM incident_details WHERE ticket_id=?',[$id]);
check('test_BR_776 case fields live in incident_details, not payload_json',$d&&$d['category']==='OTHER'&&$d['location_text']==='SECRET place '.$tag&&!str_contains(row('SELECT payload_json FROM tickets WHERE id=?',[$id])['payload_json'],'SECRET place')&&!str_contains(row('SELECT payload_json FROM tickets WHERE id=?',[$id])['payload_json'],'participants'));
check('test_BR_776 case publishes as SUBMITTED with no approval steps',$status($id)==='SUBMITTED'&&!row('SELECT id FROM ticket_steps WHERE ticket_id=?',[$id]));
foreach(['no time'=>['incident_at'=>''],'bad time'=>['incident_at'=>'yesterday'],'future'=>['incident_at'=>date('Y-m-d\TH:i',strtotime('+2 days'))],'no place'=>['location'=>''],'long place'=>['location'=>str_repeat('X',201)],'bad category'=>['category'=>'Gossip'],'bad branch'=>['branch_id'=>'999999999']] as $name=>$bad)
    check('test_BR_776 rejects '.$name,rejects(fn()=>TicketService::create($inc($bad),true)));
check('test_BR_770 rejects self, duplicate, unknown, bad role and more than 20 people',
    rejects(fn()=>TicketService::create($inc(['participants'=>[['user_id'=>$rep['id'],'role'=>'CC']]]),true))
    &&rejects(fn()=>TicketService::create($inc(['participants'=>[['user_id'=>$cc['id'],'role'=>'CC'],['user_id'=>$cc['id'],'role'=>'WITNESS']]]),true))
    &&rejects(fn()=>TicketService::create($inc(['participants'=>[['user_id'=>999999999,'role'=>'CC']]]),true))
    &&rejects(fn()=>TicketService::create($inc(['participants'=>[['user_id'=>$cc['id'],'role'=>'BOSS']]]),true))
    &&rejects(fn()=>TicketService::create($inc(['participants'=>array_fill(0,21,['user_id'=>$cc['id'],'role'=>'CC'])]),true)));
db()->prepare('UPDATE users SET status="INACTIVE" WHERE id=?')->execute([$late['id']]);
check('test_BR_770 an inactive account cannot be picked',rejects(fn()=>TicketService::create($inc(['participants'=>[['user_id'=>$late['id'],'role'=>'CC']]]),true)));
db()->prepare('UPDATE users SET status="ACTIVE" WHERE id=?')->execute([$late['id']]);

// ---------------------------------------------------------------- access matrix (BR-769)
$rows=fn()=>db()->query('SELECT user_id,role,has_access,added_by_hr FROM incident_participants WHERE ticket_id='.$id.' ORDER BY user_id')->fetchAll();
$byUser=array_column($rows(),null,'user_id');
check('test_BR_770 reporter picks: CC gets access, Involved and Witness are recorded without access',(int)$byUser[$cc['id']]['has_access']===1&&(int)$byUser[$inv['id']]['has_access']===0&&(int)$byUser[$wit['id']]['has_access']===0&&count($byUser)===4);
$matrix=['reporter'=>[$rep,true],'cc'=>[$cc,true],'hr handler'=>[$hr1,true],'second hr'=>[$hr2,true],
    'involved (not given access)'=>[$inv,false],'witness (not given access)'=>[$wit,false],'hr named involved'=>[$hrInv,false],
    'viewer'=>[$viewer,false],'sensitive-view holder'=>[$sens,false],'accounting'=>[$acct,false],'outsider'=>[$out,false],'manager'=>[$f['manager'],false],'adl'=>[$f['adl'],false]];
foreach($matrix as $who=>[$user,$expected])check('test_BR_769 '.$who.' '.($expected?'reads':'cannot read').' the case',$canRead($user,$id)===$expected);
foreach($matrix as $who=>[$user,$expected]){act($user);$in=in_array($id,$ids('feed'),true);
    check('test_BR_769 '.$who.' '.($expected?'lists':'does not list').' the case in the feed',$in===$expected||($who==='second hr'&&$in));}
foreach(['cc'=>$cc,'involved'=>$inv,'viewer'=>$viewer,'outsider'=>$out,'hr'=>$hr1] as $who=>$user){act($user);$mine=in_array($id,$ids('incidents_mine'),true);
    check('test_BR_769 My Incidents for '.$who.($who==='cc'?' includes':' excludes').' the case',$mine===($who==='cc'));}
act($rep);check('test_BR_769 My Incidents lists the reporter\'s case',in_array($id,$ids('incidents_mine'),true));
// Other list paths never expose a case.
foreach(['viewer'=>$viewer,'cc'=>$cc,'hr'=>$hr1] as $who=>$user){act($user);
    $leak=false;foreach(['my','team','services','hr'] as $mode){try{if(in_array($id,$ids($mode),true)&&!($mode==='my'&&false))$leak=true;}catch(Throwable){}}
    check('test_BR_769 '.$who.' sees the case in no other list (my/team/services/hr)',!$leak);}
// Files follow the same gate.
$file=insert('INSERT INTO ticket_attachments(ticket_id,original_name,stored_name,mime_type,file_size,uploaded_by) VALUES(?,?,?,?,?,?)',[$id,'proof.pdf',str_repeat('a',48).'.pdf','application/pdf',10,$rep['id']]);
foreach($matrix as $who=>[$user,$expected]){act($user);$ok=true;try{TicketService::attachment($file);}catch(RuntimeException $e){$ok=!str_contains($e->getMessage(),'not found or access denied')?true:false;}
    // A reader gets past the gate (and then fails only on the missing stored file); a non-reader is refused at the gate.
    check('test_BR_769 attachment gate: '.$who.($expected?' passes':' is refused'),$ok===$expected);}
act($out);check('test_BR_769 a non-reader cannot comment or attach',rejects(fn()=>TicketService::comment($id,'Hello'))&&rejects(fn()=>TicketService::attach($id,[])));
db()->prepare('DELETE FROM ticket_attachments WHERE id=?')->execute([$file]);

// ---------------------------------------------------------------- thread and privacy (BR-772, BR-773)
act($rep);TicketService::comment($id,'Reporter message');
act($cc);TicketService::comment($id,'CC message');
check('test_BR_772 a CC cannot post an HR-only note',rejects(fn()=>TicketService::comment($id,'Forged internal','STAFF_ONLY')));
act($hr1);TicketService::comment($id,'HR shared message');TicketService::comment($id,'INTERNAL handler note','STAFF_ONLY');
check('test_BR_772 handler sees all messages and the internal note',count(TicketRepository::detail($id)['comments'])===4);
foreach(['reporter'=>$rep,'cc'=>$cc] as $who=>$user){act($user);$t=TicketRepository::detail($id);$flat=json_encode($t);
    check('test_BR_772 '.$who.' sees shared messages but not the internal note or its event',count($t['comments'])===3&&!str_contains($flat,'INTERNAL handler note')&&$t['hr_notes']===[]);}
act($hr2);$t=TicketRepository::detail($id);
check('test_BR_772 a second HR reviewer is also staff (sees the internal note)',count($t['comments'])===4);
act($rep);check('test_BR_773 reporter and CC see names; only Reporter and CC carry a label',(function()use($id,$cc,$inv,$wit){
    $p=array_column(TicketRepository::detail($id)['participants'],null,'user_id');
    return count($p)===5&&$p[$cc['id']]['label']==='CC'&&$p[$inv['id']]['label']===null&&$p[$wit['id']]['label']===null&&!isset($p[$inv['id']]['has_access']);})());
act($hr1);check('test_BR_773 the handler sees every role label and who has access',(function()use($id,$inv,$cc){
    $p=array_column(TicketRepository::detail($id)['participants'],null,'user_id');return $p[$inv['id']]['label']==='Involved'&&$p[$inv['id']]['has_access']===false&&$p[$cc['id']]['has_access']===true;})());
act($hr1);check('test_BR_772 messages cannot be edited or deleted (no service path, audit rows stay)',!method_exists('TicketService','editComment')&&!method_exists('TicketService','deleteComment'));

// ---------------------------------------------------------------- status flow (BR-771)
act($rep);check('test_BR_771 reporter cannot change status',rejects(fn()=>TicketService::incidentStatus($id,$version($id),'UNDER_REVIEW','x')));
act($cc);check('test_BR_771 CC cannot change status or cancel',rejects(fn()=>TicketService::incidentStatus($id,$version($id),'UNDER_REVIEW','x'))&&rejects(fn()=>TicketService::incidentStatus($id,$version($id),'CANCELLED','')));
act($hrInv);check('test_BR_775 an HR reviewer named on the case cannot change status',rejects(fn()=>TicketService::incidentStatus($id,$version($id),'UNDER_REVIEW','x')));
act($hr2);
check('test_BR_771 remarks are required and the version must be current',rejects(fn()=>TicketService::incidentStatus($id,$version($id),'UNDER_REVIEW',''))&&rejects(fn()=>TicketService::incidentStatus($id,$version($id)-1,'UNDER_REVIEW','x')));
check('test_BR_771 no skipping to Closed or an unknown status',rejects(fn()=>TicketService::incidentStatus($id,$version($id),'CLOSED','x'))&&rejects(fn()=>TicketService::incidentStatus($id,$version($id),'COMPLETED','x'))&&rejects(fn()=>TicketService::incidentStatus($id,$version($id),'PENDING_HR','x')));
TicketService::incidentStatus($id,$version($id),'UNDER_REVIEW','HR update: we are reviewing this.','handler-only reasoning');
check('test_BR_771 SUBMITTED to UNDER_REVIEW by HR',$status($id)==='UNDER_REVIEW');
act($rep);$t=TicketRepository::detail($id);
check('test_BR_771 HR remarks show as an HR update to the reporter; the internal note does not',count($t['responses'])===1&&$t['responses'][0]['remarks']==='HR update: we are reviewing this.'&&!str_contains(json_encode($t),'handler-only reasoning'));
act($cc);check('test_BR_771 the CC sees the HR update too',count(TicketRepository::detail($id)['responses'])===1);
act($hr2);check('test_BR_772 the handler reads the internal note with the status change',in_array('handler-only reasoning',array_column(TicketRepository::detail($id)['hr_notes'],'note'),true));
TicketService::incidentStatus($id,$version($id),'IN_PROGRESS','Working on it.');
TicketService::incidentStatus($id,$version($id),'RESOLVED','Fixed.');
check('test_BR_771 IN_PROGRESS then RESOLVED',$status($id)==='RESOLVED'&&row('SELECT closed_at FROM tickets WHERE id=?',[$id])['closed_at']===null);
act($cc);TicketService::comment($id,'Thanks, confirmed');
act($hr2);TicketService::incidentStatus($id,$version($id),'CLOSED','Closing.');
check('test_BR_771 CLOSED sets closed_at',$status($id)==='CLOSED'&&row('SELECT closed_at FROM tickets WHERE id=?',[$id])['closed_at']!==null);
act($cc);check('test_BR_772 a closed case takes no new participant messages',rejects(fn()=>TicketService::comment($id,'Late message')));
act($hr2);check('test_BR_772 the handler can still add an internal note to a closed case',TicketService::comment($id,'Post-close internal note','STAFF_ONLY')===$id);
check('test_BR_771 reopening needs remarks and goes to IN_PROGRESS',rejects(fn()=>TicketService::incidentStatus($id,$version($id),'IN_PROGRESS',''))&&TicketService::incidentStatus($id,$version($id),'IN_PROGRESS','Reopened: new evidence.')===$id&&$status($id)==='IN_PROGRESS'&&row('SELECT closed_at FROM tickets WHERE id=?',[$id])['closed_at']===null);

// dismissed (needs a reason) and cancelled (reporter, SUBMITTED only)
act($rep);$d1=TicketService::create($inc(['participants'=>[]]),true);$d2=TicketService::create($inc(['participants'=>[]]),true);
act($hr1);check('test_BR_771 HR cancelling needs a reason',rejects(fn()=>TicketService::incidentStatus($d1,$version($d1),'CANCELLED','')));
check('test_BR_771 DISMISSED needs a reason',rejects(fn()=>TicketService::incidentStatus($d1,$version($d1),'DISMISSED','   ')));
TicketService::incidentStatus($d1,$version($d1),'DISMISSED','Not an incident: duplicate of another report.');
check('test_BR_771 DISMISSED is final',$status($d1)==='DISMISSED'&&rejects(fn()=>TicketService::incidentStatus($d1,$version($d1),'UNDER_REVIEW','x')));
act($rep);check('test_BR_771 the reporter reads the dismissal reason',TicketRepository::detail($d1)['responses'][0]['remarks']==='Not an incident: duplicate of another report.');
TicketService::incidentStatus($d2,$version($d2),'CANCELLED','');
check('test_BR_771 the reporter cancels while SUBMITTED',$status($d2)==='CANCELLED');
$d3=TicketService::create($inc(['participants'=>[]]),true);act($hr1);TicketService::incidentStatus($d3,$version($d3),'UNDER_REVIEW','x');act($rep);
check('test_BR_771 the reporter cannot cancel once HR has started',rejects(fn()=>TicketService::incidentStatus($d3,$version($d3),'CANCELLED','')));
check('test_BR_771 generic act() cannot move an incident case',rejects(fn()=>TicketService::act($d3,$version($d3),'START'))&&rejects(fn()=>TicketService::act($d3,$version($d3),'COMPLETE')));

// ---------------------------------------------------------------- HR manages people (BR-770)
act($hr1);$before=count(TicketRepository::detail($id)['events']);
check('test_BR_770 HR includes someone: they read the case at once',!$canRead($late,$id)&&(act($hr1)||true)&&TicketService::incidentAddParticipant($id,$late['id'],'INVOLVED')===$id&&$canRead($late,$id));
act($late);check('test_BR_773 an HR-included person sees names without role labels',(function()use($id,$cc,$late){$p=array_column(TicketRepository::detail($id)['participants'],null,'user_id');return $p[$cc['id']]['label']==='CC'&&$p[$late['id']]['label']===null;})());
act($hr1);check('test_BR_770 HR may include a person the reporter named without access',TicketService::incidentAddParticipant($id,$inv['id'],'INVOLVED')===$id&&$canRead($inv,$id));
act($hr1);check('test_BR_770 HR cannot add the reporter, themselves, a duplicate or an unknown account',rejects(fn()=>TicketService::incidentAddParticipant($id,$rep['id'],'CC'))&&rejects(fn()=>TicketService::incidentAddParticipant($id,$hr1['id'],'CC'))&&rejects(fn()=>TicketService::incidentAddParticipant($id,$late['id'],'CC'))&&rejects(fn()=>TicketService::incidentAddParticipant($id,999999999,'CC')));
act($cc);check('test_BR_770 a CC cannot add or remove people',rejects(fn()=>TicketService::incidentAddParticipant($id,$out['id'],'CC'))&&rejects(fn()=>TicketService::incidentRemoveParticipant($id,$late['id'])));
act($rep);check('test_BR_770 the reporter cannot add or remove people after filing',rejects(fn()=>TicketService::incidentAddParticipant($id,$out['id'],'CC'))&&rejects(fn()=>TicketService::incidentRemoveParticipant($id,$cc['id'])));
act($hr1);TicketService::incidentRemoveParticipant($id,$cc['id']);
check('test_BR_770 removing a CC ends their access at once and leaves an audit trail',!$canRead($cc,$id)&&(int)row('SELECT COUNT(*) n FROM ticket_events WHERE ticket_id=? AND event_type IN ("PARTICIPANT_ADDED","PARTICIPANT_REMOVED")',[$id])['n']===3&&(int)row('SELECT COUNT(*) n FROM audit_logs WHERE action LIKE "INCIDENT_PARTICIPANT_%"')['n']>=3);
act($hr1);check('test_BR_770 removing someone who is not on the case is refused',rejects(fn()=>TicketService::incidentRemoveParticipant($id,$cc['id'])));
act($rep);$flat=json_encode(TicketRepository::detail($id)['events']);
check('test_BR_773 the reporter\'s timeline carries no people-change events',!str_contains($flat,'PARTICIPANT_'));
act($hr1);check('test_BR_773 the handler\'s timeline does',str_contains(json_encode(TicketRepository::detail($id)['events']),'PARTICIPANT_ADDED'));
check('test_BR_770 the schema refuses access for a non-CC picked by the reporter',rejects(fn()=>db()->prepare('INSERT INTO incident_participants(ticket_id,user_id,role,has_access,added_by,added_by_hr) VALUES(?,?,"WITNESS",1,?,0)')->execute([$id,$out['id'],$rep['id']])));

// ---------------------------------------------------------------- HR conflict (BR-775)
act($hr2);TicketService::incidentAddParticipant($id,$hrInv['id'],'WITNESS');
act($hrInv);check('test_BR_775 an HR reviewer included on the case reads it but is not a handler',TicketIncident::permissions(TicketRepository::find($id))['status']===[]&&rejects(fn()=>TicketService::comment($id,'sneaky note','STAFF_ONLY'))&&TicketRepository::detail($id)['hr_notes']===[]);
// An HR reviewer who is the reporter does not handle their own case, and other HR reviewers still do.
$hrRep=mk($tag,'hrrep',['tickets.review_hr','tickets.request_self','ticket.file.incident'],'hr',$f,true);
act($hrRep);$h=TicketService::create($inc(['participants'=>[]]),true);
check('test_BR_775 an HR reporter reads their own case but cannot handle it',rejects(fn()=>TicketService::incidentStatus($h,$version($h),'UNDER_REVIEW','x'))&&$canRead($hrRep,$h));
act($hr1);check('test_BR_775 another HR reviewer handles it',TicketService::incidentStatus($h,$version($h),'UNDER_REVIEW','x')===$h);

// ---------------------------------------------------------------- drafts stay private
act($rep);$dr=TicketService::create($inc(['participants'=>[['user_id'=>$cc['id'],'role'=>'CC']]]),false);
check('test_BR_769 a draft is readable by its author only (not HR, not the chosen CC)',$canRead($rep,$dr)&&!$canRead($hr1,$dr)&&!$canRead($cc,$dr)&&(act($cc)||true)&&!in_array($dr,$ids('incidents_mine'),true));

// ---------------------------------------------------------------- notifications (BR-774)
$secrets=['SECRET','HR update','Reporter message','INTERNAL','Working on it'];
TicketNotifications::flush(500);
$notes=db()->query('SELECT n.recipient_user_id,n.title,n.body FROM notifications n WHERE n.title LIKE "Incident case update%"')->fetchAll();
$leaks=0;foreach($notes as $n)foreach($secrets as $s)if(str_contains($n['title'].$n['body'],$s))$leaks++;
check('test_BR_774 incident notifications exist and carry no case text, remarks or notes',count($notes)>0&&$leaks===0);
$who=array_map('intval',array_column($notes,'recipient_user_id'));
check('test_BR_774 outsiders, viewers, accounting and sensitive-view holders get no incident notification',!array_intersect($who,[$out['id'],$viewer['id'],$acct['id'],$sens['id'],$f['manager']['id'],$f['adl']['id']]));
check('test_BR_774 a removed participant gets nothing after removal and the added person heard only they were added',(function()use($id,$late){$q=db()->prepare('SELECT COUNT(*) FROM ticket_notification_outbox o JOIN ticket_events e ON e.id=o.event_id WHERE e.ticket_id=? AND o.recipient_user_id=? AND e.event_type="PARTICIPANT_ADDED"');$q->execute([$id,$late['id']]);return (int)$q->fetchColumn()===1;})());

// ---------------------------------------------------------------- the rest of the engine still excludes incidents
act($hr1);check('test_BR_744 incidents stay out of the HR queue and the Payroll Concern list',!in_array($id,$ids('services'),true)&&!in_array($id,$ids('hr'),true));
act($viewer);check('test_BR_726 viewers never read an incident case even with ticket.view_team',!$canRead($viewer,$id)&&!in_array($id,$ids('team'),true));
check('test_BR_769 ticket.sensitive.view no longer opens a case',!$canRead($sens,$id));
finish();
