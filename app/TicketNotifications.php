<?php
declare(strict_types=1);
require_once __DIR__.'/NotificationService.php';

final class TicketNotifications
{
    public static function enqueue(int $eventId,array $ticket):void
    {
        $recipients=[(int)$ticket['requester_user_id']];
        $q=db()->prepare('SELECT approver_type,approver_user_id FROM ticket_steps WHERE ticket_id=? AND step_no=? AND status="PENDING"');$q->execute([$ticket['id'],$ticket['current_step_no']]);$step=$q->fetch();
        if($step && $step['approver_user_id'])$recipients[]=(int)$step['approver_user_id'];
        if($step && $step['approver_type']==='HR'){
            foreach(db()->query('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status="ACTIVE" AND r.portal IN ("hr","admin") AND (r.code="SUPER_ADMIN" OR EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.code="tickets.review_hr"))')->fetchAll(PDO::FETCH_COLUMN) as $id)$recipients[]=(int)$id;
        }
        $q=db()->prepare('INSERT IGNORE INTO ticket_notification_outbox(event_id,recipient_user_id) VALUES(?,?)');
        foreach(array_unique($recipients) as $uid)if($uid!==(int)Auth::user()['id'])$q->execute([$eventId,$uid]);
    }
    /** Post-commit/CLI retry. In-app only; never sends email or concern contents. */
    public static function flush(int $limit=50,?int $ticketId=null):array
    {
        if(db()->inTransaction() || !FoundationRepository::tableExists('ticket_notification_outbox'))return ['delivered'=>0,'pending'=>0];
        $sql='SELECT o.*,e.ticket_id,t.ticket_no FROM ticket_notification_outbox o JOIN ticket_events e ON e.id=o.event_id JOIN tickets t ON t.id=e.ticket_id WHERE o.delivered_at IS NULL';$params=[];
        if($ticketId!==null){$sql.=' AND t.id=?';$params[]=$ticketId;}
        $q=db()->prepare($sql.' ORDER BY o.id LIMIT '.max(1,min(500,$limit)));$q->execute($params);$delivered=0;$failed=0;
        foreach($q->fetchAll() as $row){try{
            NotificationService::create(['recipient_user_id'=>$row['recipient_user_id'],'kind'=>'HR_REQUEST','title'=>'HR request update: '.$row['ticket_no'],
                'body'=>'An HR request was updated. Open the request to review it.','link'=>url('hr-request',['id'=>$row['ticket_id']]),'dedupe_key'=>'ticket-event:'.$row['event_id'].':'.$row['recipient_user_id']]);
            db()->prepare('UPDATE ticket_notification_outbox SET delivered_at=NOW(),attempts=attempts+1 WHERE id=?')->execute([$row['id']]);$delivered++;
        }catch(Throwable){db()->prepare('UPDATE ticket_notification_outbox SET attempts=attempts+1 WHERE id=?')->execute([$row['id']]);$failed++;}}
        return ['delivered'=>$delivered,'pending'=>$failed];
    }
}
