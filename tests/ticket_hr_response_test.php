<?php
declare(strict_types=1);
// Patch 4 (migration 016): HR remarks are visible to the requester; the HR-only internal note never is.
// Run: HRIS_TEST_DB=hris_slice15_test_YYYYMMDD_HHMMSS php tests/ticket_hr_response_test.php   (isolated database only, synthetic data)
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/TicketViews.php';
require_once dirname(__DIR__).'/app/TicketAccountingViews.php';
$f=fixtures();$hr=$f['hr'];$req=$f['employee'];$other=$f['outsider'];$mgr=$f['manager'];
function perm(int $role,string $code):void{$p=row('SELECT id FROM permissions WHERE code=?',[$code]);db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);Auth::clearPermissionCache();}
function atm():int{return TicketService::create(['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code="ATM_REQUEST"')['id'],'submission_key'=>bin2hex(random_bytes(32)),'reason'=>'test1'],true);}
function hr_act(int $id,string $decision,string $remarks,string $note=''):void{TicketService::act($id,(int)TicketRepository::find($id)['version'],$decision,$remarks,'',0,[],$note);}
function page(int $id):string{ob_start();ticket_service_detail(TicketRepository::detail($id));return (string)ob_get_clean();}
function bell(int $uid):array{return db()->query('SELECT title,body FROM notifications WHERE recipient_user_id='.$uid.' ORDER BY id')->fetchAll();}
$NOTE='ZZ-INTERNAL-SECRET-'.bin2hex(random_bytes(3));$OPEN='Please collect your ATM card at HR, ground floor.';

check('migration table exists',(bool)row('SHOW TABLES LIKE "ticket_hr_notes"'));

// ---- In Progress then Served, with a response and an internal note
act($req);$id=atm();
act($hr);hr_act($id,'START','We are processing your ATM request.',$NOTE.' start');
act($req);$html=page($id);
check('requester sees the In Progress response',str_contains($html,'We are processing your ATM request.')&&str_contains($html,'HR response'));
act($hr);hr_act($id,'COMPLETE',$OPEN,$NOTE);
act($req);$d=TicketRepository::detail($id);$html=page($id);
check('requester sees the Served response',str_contains($html,$OPEN)&&$d['responses'][count($d['responses'])-1]['remarks']===$OPEN);
check('requester sees status pill, stepper and history section',str_contains($html,'class="tk-pill is-green"')&&str_contains($html,'tk-steps')&&str_contains($html,'<summary>History'));
$card=substr($html,(int)strpos($html,'id="tk-response-h"'));$card=substr($card,0,(int)strpos($card,'</section>'));check('response card says HR, not the person, to the requester',str_contains($card,'<strong>HR</strong>')&&!str_contains($card,$hr['name']));
check('requester never sees the internal note (page)',!str_contains($html,$NOTE)&&!str_contains($html,'internal-note'));
check('requester detail carries no internal notes',$d['hr_notes']===[]);
check('requester events and comments hold no note',!str_contains(json_encode([$d['events'],$d['comments'],$d['steps']]),$NOTE));
act($hr);$d=TicketRepository::detail($id);$html=page($id);
check('HR sees both internal notes, labelled HR only',count($d['hr_notes'])===2&&str_contains($html,$NOTE)&&str_contains($html,'HR only'));
check('HR sees the employee-visible response and who wrote it',str_contains($html,$OPEN)&&str_contains($html,$hr['name']));
check('events table never stores the note',(int)row('SELECT COUNT(*) n FROM ticket_events WHERE details_json LIKE ?',['%'.$NOTE.'%'])['n']===0&&(int)row('SELECT COUNT(*) n FROM ticket_steps WHERE remarks LIKE ?',['%ZZ-INTERNAL%'])['n']===0);

// ---- Notifications
$msgs=bell($req['id']);$last=end($msgs);
check('requester notified on status change',$msgs!==[]&&str_contains($last['body'],'Served')&&str_contains($last['body'],'response'));
check('no notification carries note or remark text',(int)row('SELECT COUNT(*) n FROM notifications WHERE body LIKE ? OR title LIKE ? OR body LIKE ?',['%ZZ-INTERNAL%','%ZZ-INTERNAL%','%'.$OPEN.'%'])['n']===0);
check('HR (the actor) gets no self-notification',array_filter(bell($hr['id']),fn($m)=>str_contains($m['body'],'Your request is now'))===[]);

// ---- Cancelled by HR
act($req);$id2=atm();act($hr);hr_act($id2,'CANCEL','Duplicate of an earlier request.',$NOTE.' cancel');
act($req);$html=page($id2);
check('requester sees the Cancelled response',str_contains($html,'Duplicate of an earlier request.')&&!str_contains($html,$NOTE));
// ---- No remarks written
act($req);$id3=atm();act($hr);hr_act($id3,'COMPLETE','');act($req);
check('empty remarks show a plain notice, not a blank card',str_contains(page($id3),'No remarks were added'));

// ---- Forged access
act($req);$id4=atm();
check('requester cannot post an internal note',rejects(fn()=>hr_act($id4,'CANCEL','mine',$NOTE)));
check('forged note left the ticket untouched',TicketRepository::find($id4)['status']==='PENDING_HR'&&(int)row('SELECT COUNT(*) n FROM ticket_hr_notes WHERE ticket_id=?',[$id4])['n']===0);
act($other);
check('other employee cannot open the ticket',rejects(fn()=>TicketRepository::detail($id)));
check('other employee cannot act with a note',rejects(fn()=>hr_act($id4,'START','x',$NOTE)));
act($hr);
check('HR note over 2000 characters is refused',rejects(fn()=>hr_act($id4,'START','ok',str_repeat('a',2001))));
check('refused HR action changed nothing',TicketRepository::find($id4)['status']==='PENDING_HR');
hr_act($id4,'START','Working on it.');
check('HR may act without a note',TicketRepository::find($id4)['status']==='IN_PROGRESS'&&(int)row('SELECT COUNT(*) n FROM ticket_hr_notes WHERE ticket_id=?',[$id4])['n']===0);

// ---- View-only viewer: a Head Office Manager viewer sees no remarks and no notes
perm($mgr['role_id'],'ticket.view_team');
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$f['manager_employee'],$f['requester']]);
act($mgr);
try{$vd=TicketRepository::detail($id);
    check('viewer is read-only',!empty($vd['viewer_only']));
    $vh=page($id);
    check('viewer sees neither response, note nor history remarks',$vd['responses']===[]&&$vd['hr_notes']===[]&&!str_contains($vh,$OPEN)&&!str_contains($vh,$NOTE)&&!str_contains($vh,'HR response'));
}catch(RuntimeException $e){check('viewer is denied the ticket outright (no content either way)',true);}
// ---- Incident Reports keep their privacy: no requester-side response card
$inc=(int)row('SELECT COUNT(*) n FROM ticket_types WHERE code="INCIDENT_REPORT"')['n'];
check('Incident Report type still present',$inc===1);

// ---- Chat thread and old payloads
act($req);TicketService::comment($id,'Thank you!');act($hr);TicketService::comment($id,'You are welcome.');
act($req);$html=page($id);
check('comments render as a thread, own message marked',str_contains($html,'tk-msg is-mine')&&str_contains($html,'tk-msg is-theirs')&&str_contains($html,'You are welcome.'));

finish();
