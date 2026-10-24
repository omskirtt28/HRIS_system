<?php
declare(strict_types=1);
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/TicketService.php';
$f=fixtures();$type=(int)row('SELECT id FROM ticket_types WHERE code="PAYROLL_CONCERN"')['id'];
function cutoff(string $start,string $end,string $status='OPEN'):int{
    $old=row('SELECT id FROM payroll_cutoffs WHERE period_start=? AND period_end=?',[$start,$end]);
    if($old){$id=(int)$old['id'];db()->prepare('UPDATE payroll_cutoffs SET status=? WHERE id=?')->execute([$status,$id]);}
    else $id=insert('INSERT INTO payroll_cutoffs(code,period_start,period_end,status) VALUES(?,?,?,?)',['T15-'.$start.'-'.$end,$start,$end,$status]);
    db()->prepare('INSERT INTO payroll_cutoff_runs(cutoff_id,state,ticket_deadline) VALUES(?,"OPEN",NULL) ON DUPLICATE KEY UPDATE state="OPEN",ticket_deadline=NULL')->execute([$id]);return $id;
}
$source=cutoff('2020-01-04','2020-01-18');$target=cutoff('2020-01-19','2020-02-03');
db()->prepare('INSERT IGNORE INTO payroll_cutoff_snapshots(cutoff_id,employee_id,work_date,snapshot_json) VALUES(?,?,?,?)')->execute([$source,$f['requester'],'2020-01-10','{"sentinel":"Synthetic finalized payroll snapshot"}']);
function lockedHash(int $source):string{
    $q=db()->prepare('SELECT * FROM payroll_cutoff_snapshots WHERE cutoff_id=? ORDER BY employee_id,work_date');$q->execute([$source]);
    $snapshots=$q->fetchAll();
    $q=db()->prepare('SELECT * FROM attendance_daily WHERE cutoff_id=? ORDER BY employee_id,work_date');$q->execute([$source]);
    return hash('sha256',json_encode([row('SELECT * FROM payroll_cutoffs WHERE id=?',[$source]),row('SELECT * FROM payroll_cutoff_runs WHERE cutoff_id=?',[$source]),$snapshots,$q->fetchAll()]));
}
function formConcern(string $date='2020-01-10',string $explanation='Synthetic payroll concern'):array{global $type;return ['ticket_type_id'=>$type,'concern_date'=>$date,'explanation'=>$explanation,'submission_key'=>bin2hex(random_bytes(32))];}
function ticket(int $id):array{return TicketRepository::find($id);}
function action(int $id,string $action,string $remarks='',string $outcome='',int $target=0):int{return TicketService::act($id,(int)ticket($id)['version'],$action,$remarks,$outcome,$target);}
function stageToHr(int $id,array $f):void{act($f['manager']);action($id,'APPROVE','Synthetic manager review');act($f['hr']);}
act($f['employee']);
$before=(int)db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
check('open filing without valid approver blocks',rejects(fn()=>TicketService::create(formConcern(),true)));
check('blocked filing is atomic',(int)db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn()===$before);
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$f['manager_employee'],$f['requester']]);
$data=formConcern();$data['subject_employee_id']=$f['branch_requester'];$data['employee_name']='Forged';$id=TicketService::create($data,true);$t=ticket($id);
check('open cutoff accepted',$t['status']==='PENDING_APPROVAL' && !$t['is_late']);
check('requester/subject derived server-side',(int)$t['subject_employee_id']===$f['requester'] && $t['payload']['employee_name']!=='Forged');
check('company and branch snapshots stored',$t['company_group_snapshot']==='SW' && $t['company_name_snapshot']==='Slice 15 Test Company' && $t['payload']['branch_name']==='Slice15 Test Head Office');
check('duplicate submission returns same ticket',TicketService::create($data,true)===$id);
$detail=TicketRepository::detail($id);
check('HO chain freezes Manager then HR',array_column($detail['steps'],'approver_type')===['MANAGER','HR']);
check('named manager assignment frozen',(int)$detail['steps'][0]['approver_user_id']===$f['manager']['id']);
check('submitted audit includes transient state',in_array('SUBMITTED',array_column($detail['events'],'to_status'),true));
check('self approval blocked',rejects(fn()=>action($id,'APPROVE')));
act($f['outsider']);check('IDOR detail denied',rejects(fn()=>TicketRepository::detail($id)));check('IDOR comment denied',rejects(fn()=>TicketService::comment($id,'Forged comment')));
check('IDOR action denied',rejects(fn()=>TicketService::act($id,(int)$t['version'],'APPROVE')));
act($f['hr']);check('HR cannot skip Manager approval',rejects(fn()=>action($id,'APPROVE')));
act($f['manager']);check('named approver can read',ticket($id)['id']===$id);
check('named approver inbox includes ticket',in_array($id,array_map('intval',array_column(TicketRepository::listing('inbox')['rows'],'id')),true));
check('return requires remarks',rejects(fn()=>action($id,'RETURN')));
TicketService::comment($id,'Review note');check('comments write event',in_array('COMMENT_ADDED',array_column(TicketRepository::detail($id)['events'],'event_type'),true));
$stale=(int)ticket($id)['version'];action($id,'APPROVE','Verified by assigned Manager');
check('manager advances to pending HR',ticket($id)['status']==='PENDING_HR');
check('old approver cannot act on HR step',rejects(fn()=>TicketService::act($id,$stale,'REJECT','Stale decision')));
act($f['hr']);check('HR queue includes Payroll Concern',in_array($id,array_map('intval',array_column(TicketRepository::listing('hr',['business_unit_id'=>$f['company']])['rows'],'id')),true));
action($id,'START','Reviewing');check('HR can start work',ticket($id)['status']==='IN_PROGRESS');
check('completion needs explanation',rejects(fn()=>action($id,'COMPLETE','','NO_CHANGE')));
action($id,'COMPLETE','Checked: no payroll change needed','NO_CHANGE');check('no-change completes chain',ticket($id)['status']==='COMPLETED' && ticket($id)['closed_at']!==null);
check('no-change creates no effect',!row('SELECT id FROM ticket_effects WHERE ticket_id=?',[$id]));
check('completed tickets cannot return',rejects(fn()=>action($id,'RETURN','Too late')));
act($f['employee']);check('completed requester cannot edit',rejects(fn()=>TicketService::edit($id,(int)ticket($id)['version'],formConcern())));
$draft=TicketService::create(formConcern(),false);check('draft saved',ticket($draft)['status']==='DRAFT');
TicketService::edit($draft,(int)ticket($draft)['version'],formConcern('2020-01-10','Edited explanation'));check('draft edit audited',in_array('EDITED',array_column(TicketRepository::detail($draft)['events'],'event_type'),true));
action($draft,'CANCEL');check('requester can cancel draft',ticket($draft)['status']==='CANCELLED');
$returned=TicketService::create(formConcern(),true);act($f['manager']);action($returned,'RETURN','Please clarify the concern');
act($f['employee']);check('returned status recorded',ticket($returned)['status']==='RETURNED');
check('returned cutoff/date cannot change',rejects(fn()=>TicketService::edit($returned,(int)ticket($returned)['version'],formConcern('2020-01-11'))));
TicketService::edit($returned,(int)ticket($returned)['version'],formConcern('2020-01-10','Clarified concern'),true);
check('returned concern resubmits',ticket($returned)['status']==='PENDING_APPROVAL');
check('resubmission retains original chain history',count(TicketRepository::detail($returned)['steps'])===4);
act($f['manager']);action($returned,'REJECT','Not supported by review');check('rejection closes ticket',ticket($returned)['status']==='REJECTED');
check('rejected chain leaves no pending steps',!row('SELECT id FROM ticket_steps WHERE ticket_id=? AND status="PENDING"',[$returned]));
act($f['employee']);check('future date blocked',rejects(fn()=>TicketService::create(formConcern('2099-01-10'),true)));
check('invalid date blocked',rejects(fn()=>TicketService::create(formConcern('2020-02-30'),true)));
check('missing explanation blocked',rejects(fn()=>TicketService::create(formConcern('2020-01-10',''),true)));
check('missing cutoff blocked',rejects(fn()=>TicketService::create(formConcern('1999-01-10'),true)));
// Branch routing exercises the validated legacy fallback, not directory-name guesses.
db()->prepare('UPDATE payroll_site_settings SET approver_user_id=? WHERE branch_id=?')->execute([$f['adl']['id'],$f['branch']]);
act($f['outsider']);$branch=TicketService::create(formConcern(),true);check('branch chain routes to ADL',TicketRepository::detail($branch)['steps'][0]['approver_type']==='ADL');
act($f['adl']);action($branch,'APPROVE','Branch review');act($f['hr']);action($branch,'COMPLETE','Reviewed, no change','NO_CHANGE');check('branch concern completes chain',ticket($branch)['status']==='COMPLETED');
// Late concerns require no Manager/ADL, including employees without any assignment.
db()->prepare('UPDATE employees SET manager_employee_id=NULL WHERE id=?')->execute([$f['requester']]);
foreach(['FINALIZED','DEADLINE_EXPIRED','CALENDAR_CLOSED'] as $reason){
    db()->prepare('UPDATE payroll_cutoffs SET status=? WHERE id=?')->execute([$reason==='CALENDAR_CLOSED'?'CLOSED':'OPEN',$source]);
    db()->prepare('UPDATE payroll_cutoff_runs SET state=?,ticket_deadline=? WHERE cutoff_id=?')->execute([$reason==='FINALIZED'?'FINALIZED':'OPEN',$reason==='DEADLINE_EXPIRED'?'2000-01-01 00:00:00':null,$source]);
    $lockedBefore=lockedHash($source);
    act($f['employee']);$late=TicketService::create(formConcern(),true);$t=ticket($late);
    check($reason.' goes directly to HR without approver',$t['status']==='PENDING_HR' && $t['is_late'] && $t['late_reason']===$reason && array_column(TicketRepository::detail($late)['steps'],'approver_type')===['HR']);
    act($f['hr']);
    check($reason.' source cutoff cannot be adjustment target',rejects(fn()=>action($late,'COMPLETE','Manual review instruction','MANUAL_ADJUSTMENT',$source)));
    action($late,'COMPLETE','Review manual next-period adjustment','MANUAL_ADJUSTMENT',$target);
    $eventCount=count(TicketRepository::detail($late)['events']);$version=(int)ticket($late)['version'];
    TicketService::act($late,$version-1,'COMPLETE','Review manual next-period adjustment','MANUAL_ADJUSTMENT',$target);
    check($reason.' replay produces exactly one effect',(int)row('SELECT COUNT(*) n FROM ticket_effects WHERE ticket_id=?',[$late])['n']===1 && count(TicketRepository::detail($late)['events'])===$eventCount);
    check($reason.' source cutoff/attendance/snapshots untouched',$lockedBefore===lockedHash($source));
    check($reason.' manual instruction has no computed pay',json_decode(row('SELECT instruction_json FROM ticket_effects WHERE ticket_id=?',[$late])['instruction_json'],true)['manual_processing']===true);
}
// Revalidate the target after the UI selected it.
act($f['employee']);$late=TicketService::create(formConcern(),true);act($f['hr']);db()->prepare('UPDATE payroll_cutoff_runs SET state="FINALIZED" WHERE cutoff_id=?')->execute([$target]);
check('target locked after selection rejects completion',rejects(fn()=>action($late,'COMPLETE','Manual instruction','MANUAL_ADJUSTMENT',$target)));
check('failed completion leaves ticket pending',ticket($late)['status']==='PENDING_HR' && !row('SELECT id FROM ticket_effects WHERE ticket_id=?',[$late]));
db()->prepare('UPDATE payroll_cutoff_runs SET state="OPEN" WHERE cutoff_id=?')->execute([$target]);
check('audit events append-only update blocked',rejects(fn()=>db()->exec('UPDATE ticket_events SET event_type="BAD" WHERE ticket_id='.$late)));
check('audit events append-only delete blocked',rejects(fn()=>db()->exec('DELETE FROM ticket_events WHERE ticket_id='.$late)));
$delivery=TicketNotifications::flush(500);$count=(int)db()->query('SELECT COUNT(*) FROM notifications WHERE kind="HR_REQUEST"')->fetchColumn();TicketNotifications::flush(500);
check('notification replay deduplicates',(int)db()->query('SELECT COUNT(*) FROM notifications WHERE kind="HR_REQUEST"')->fetchColumn()===$count);
check('notifications exclude concern contents',!row('SELECT id FROM notifications WHERE kind="HR_REQUEST" AND body LIKE "%Synthetic payroll concern%"'));
check('ticket notifications never enqueue email',!row('SELECT id FROM notifications WHERE kind="HR_REQUEST" AND email_to IS NOT NULL'));
// A synthetic database fault proves notification failure cannot undo a committed comment.
$trigger='t15_notify_'.$f['tag'];
db()->exec('CREATE TRIGGER `'.$trigger.'` BEFORE INSERT ON notifications FOR EACH ROW BEGIN IF NEW.kind="HR_REQUEST" THEN SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT="Synthetic notification fault"; END IF; END');
try{
    act($f['hr']);TicketService::comment($id,'Synthetic retryable comment');
    check('notification failure preserves committed comment',(bool)row('SELECT id FROM ticket_comments WHERE ticket_id=? AND body="Synthetic retryable comment"',[$id]));
    check('failed notification remains queued',(bool)row('SELECT o.id FROM ticket_notification_outbox o JOIN ticket_events e ON e.id=o.event_id WHERE e.ticket_id=? AND o.delivered_at IS NULL AND o.attempts>0',[$id]));
}finally{db()->exec('DROP TRIGGER `'.$trigger.'`');}
$retry=TicketNotifications::flush(500,$id);check('notification worker retries pending delivery',$retry['delivered']>0);
db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code IN ("tickets.request_self","ticket.file.payroll_concern")')->execute([$f['hr']['role_id']]);
insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,branch_id,legal_entity_id,business_unit_id,status) VALUES(?,"Slice15","HR Self",?,?,?,?,"ACTIVE")',[$f['tag'].'_hr',$f['hr']['id'],$f['ho'],$f['legal'],$f['company']]);
act($f['hr']);$hrOwn=TicketService::create(formConcern(),true);
check('HR cannot complete own concern',rejects(fn()=>action($hrOwn,'COMPLETE','Self approval','NO_CHANGE')));
act($f['employee']);check('employee cannot read HR queue',rejects(fn()=>TicketRepository::listing('hr')));
check('employee cannot read approver inbox',rejects(fn()=>TicketRepository::listing('inbox')));
db()->prepare('UPDATE users SET status="INACTIVE" WHERE id=?')->execute([$f['employee']['id']]);
check('inactive account session cannot read its own ticket',rejects(fn()=>TicketRepository::find($id)));
db()->prepare('UPDATE users SET status="ACTIVE" WHERE id=?')->execute([$f['employee']['id']]);
// Persist only synthetic browser-safe identifiers for this test; no live records are exported.
finish();
