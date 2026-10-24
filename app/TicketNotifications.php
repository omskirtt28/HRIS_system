<?php
declare(strict_types=1);
require_once __DIR__.'/NotificationService.php';
require_once __DIR__.'/TicketTypes.php';

final class TicketNotifications
{
    /**
     * Incident cases: the reporter and people with access hear about updates; HR reviewers hear about a newly published case and about messages
     * from others. A newly included person hears only that they were added. Nobody learns the case text, the role or who else is on it.
     */
    private static function enqueueIncident(int $eventId,array $ticket):void
    {
        $q=db()->prepare('SELECT event_type,actor_user_id,details_json FROM ticket_events WHERE id=?');$q->execute([$eventId]);$ev=$q->fetch();if(!$ev)return;
        $recipients=[];
        if($ev['event_type']==='PARTICIPANT_ADDED'){
            $recipients[]=(int)(json_decode($ev['details_json']?:'{}',true)['user_id']??0);
        }elseif($ev['event_type']!=='PARTICIPANT_REMOVED'){
            $recipients[]=(int)$ticket['requester_user_id'];
            if(TicketIncident::ready()){$q=db()->prepare('SELECT user_id FROM incident_participants WHERE ticket_id=? AND has_access=1');$q->execute([$ticket['id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$recipients[]=(int)$id;}
            $actorIsHr=false;$q=db()->prepare('SELECT r.portal FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?');$q->execute([$ev['actor_user_id']]);$actorIsHr=in_array($q->fetchColumn(),['hr','admin'],true);
            if($ev['event_type']==='PUBLISHED'||($ev['event_type']==='COMMENT_ADDED'&&!$actorIsHr)){
                $conflicts=[];if(TicketIncident::ready()){$q=db()->prepare('SELECT user_id FROM incident_participants WHERE ticket_id=?');$q->execute([$ticket['id']]);$conflicts=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));}
                foreach(self::hrReviewerIds() as $id)if(!in_array($id,$conflicts,true)&&$id!==(int)$ticket['requester_user_id'])$recipients[]=$id;
            }
        }
        $q=db()->prepare('INSERT IGNORE INTO ticket_notification_outbox(event_id,recipient_user_id) VALUES(?,?)');
        foreach(array_unique(array_filter($recipients)) as $uid)if($uid!==(int)Auth::user()['id'])$q->execute([$eventId,$uid]);
    }
    private static function hrReviewerIds():array
    {
        return array_map('intval',db()->query('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status="ACTIVE" AND r.portal IN ("hr","admin") AND (r.code="SUPER_ADMIN" OR EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.code="tickets.review_hr"))')->fetchAll(PDO::FETCH_COLUMN));
    }
    public static function enqueue(int $eventId,array $ticket):void
    {
        if(($ticket['type_code']??'')==='INCIDENT_REPORT'){self::enqueueIncident($eventId,$ticket);return;}
        $recipients=[(int)$ticket['requester_user_id']];
        $q=db()->prepare('SELECT approver_type,approver_user_id FROM ticket_steps WHERE ticket_id=? AND step_no=? AND status="PENDING"');$q->execute([$ticket['id'],$ticket['current_step_no']]);$step=$q->fetch();
        if($step && $step['approver_user_id'])$recipients[]=(int)$step['approver_user_id'];
        if(($step && $step['approver_type']==='HR') || (($ticket['type_code']??'')==='INCIDENT_REPORT'&&$ticket['status']==='SUBMITTED')){
            foreach(db()->query('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status="ACTIVE" AND r.portal IN ("hr","admin") AND (r.code="SUPER_ADMIN" OR EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.code="tickets.review_hr"))')->fetchAll(PDO::FETCH_COLUMN) as $id)$recipients[]=(int)$id;
        }
        if(($ticket['type_code']??'')==='NETSUITE_CONCERN' && $ticket['status']==='COMPLETED'){
            foreach(db()->query('SELECT m.user_id FROM ticket_accounting_memberships m JOIN users u ON u.id=m.user_id WHERE m.active=1 AND u.status="ACTIVE"')->fetchAll(PDO::FETCH_COLUMN) as $uid)$recipients[]=(int)$uid;
        }
        $q=db()->prepare('INSERT IGNORE INTO ticket_notification_outbox(event_id,recipient_user_id) VALUES(?,?)');
        foreach(array_unique($recipients) as $uid)if($uid!==(int)Auth::user()['id'])$q->execute([$eventId,$uid]);
    }
    /**
     * The requester hears the new status and whether a response is waiting; never the response text itself, and nothing from HR internal notes
     * (they are not stored on the event). Everyone else, and Incident Reports, keep the generic line.
     */
    private static function body(array $row):string
    {
        $generic='An HR request was updated. Open the request to review it.';
        if($row['event_type']!=='TRANSITION' || (int)$row['recipient_user_id']!==(int)$row['requester_user_id'] || $row['type_code']==='INCIDENT_REPORT' || !$row['to_status'])return $row['type_code']==='INCIDENT_REPORT'?'An incident case you are part of was updated. Open the case to read the update.':$generic;
        $remarks=trim((string)(json_decode($row['details_json']?:'{}',true)['remarks']??''));
        return 'Your request is now '.TicketTypes::label((string)$row['to_status'],(string)$row['type_code']).'.'.($remarks!==''?' A response is waiting for you. Open the request to read it.':' Open the request to see the details.');
    }
    /** Post-commit/CLI retry. In-app only; never sends email or concern contents. */
    public static function flush(int $limit=50,?int $ticketId=null):array
    {
        if(db()->inTransaction() || !FoundationRepository::tableExists('ticket_notification_outbox'))return ['delivered'=>0,'pending'=>0];
        $sql='SELECT o.*,e.ticket_id,e.event_type,e.to_status,e.details_json,t.ticket_no,t.requester_user_id,ty.code type_code FROM ticket_notification_outbox o JOIN ticket_events e ON e.id=o.event_id JOIN tickets t ON t.id=e.ticket_id JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE o.delivered_at IS NULL';$params=[];
        if($ticketId!==null){$sql.=' AND t.id=?';$params[]=$ticketId;}
        $q=db()->prepare($sql.' ORDER BY o.id LIMIT '.max(1,min(500,$limit)));$q->execute($params);$delivered=0;$failed=0;
        foreach($q->fetchAll() as $row){try{
            NotificationService::create(['recipient_user_id'=>$row['recipient_user_id'],'kind'=>'HR_REQUEST','title'=>($row['type_code']==='INCIDENT_REPORT'?'Incident case update: ':'HR request update: ').$row['ticket_no'],
                'body'=>self::body($row),'link'=>url('hr-request',['id'=>$row['ticket_id']]),'dedupe_key'=>'ticket-event:'.$row['event_id'].':'.$row['recipient_user_id']]);
            db()->prepare('UPDATE ticket_notification_outbox SET delivered_at=NOW(),attempts=attempts+1 WHERE id=?')->execute([$row['id']]);$delivered++;
        }catch(Throwable){db()->prepare('UPDATE ticket_notification_outbox SET attempts=attempts+1 WHERE id=?')->execute([$row['id']]);$failed++;}}
        return ['delivered'=>$delivered,'pending'=>$failed];
    }
}
