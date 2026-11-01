<?php
declare(strict_types=1);
require_once __DIR__.'/TicketNotifications.php';
require_once __DIR__.'/TicketViewerService.php';

/**
 * Team Requests leads (Head Office Manager; Retail ADL, Sales Captain, OIC) are read-only viewers. They are told about a request by
 * TicketNotifications::enqueue(); here they may ask HR to follow up (once per 24h per request) and the list shows a "New" badge until opened.
 * Notifications carry the ticket number only, never the concern text.
 */
final class TicketLeadFollowup
{
    private const OPEN=['SUBMITTED','PENDING_APPROVAL','PENDING_HR','IN_PROGRESS'];

    /** User ids of the leads who may view this subject employee's requests (active account, permitted). */
    public static function leadUserIds(int $subjectEmployeeId): array
    {
        $v=TicketViewerService::viewersFor($subjectEmployeeId);$ids=[];
        $people=array_merge($v['manager']?[$v['manager']]:[],$v['sc'],$v['oic'],$v['adls']);
        foreach($people as $p)if(!empty($p['can_view'])&&!empty($p['user_id']))$ids[]=(int)$p['user_id'];
        return array_values(array_unique($ids));
    }

    public static function followUp(int $ticketId): void
    {
        $t=TicketRepository::find($ticketId);$uid=TicketRepository::uid();
        if(empty($t['viewer_only'])||$t['type_code']==='INCIDENT_REPORT')throw new RuntimeException('Ticket not found or access denied.');
        if(!in_array($t['status'],self::OPEN,true))throw new RuntimeException('This request is no longer open.');
        db()->beginTransaction();
        try{
            $q=db()->prepare('INSERT INTO ticket_lead_followups(ticket_id,user_id) SELECT ?,? FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM ticket_lead_followups WHERE ticket_id=? AND user_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 24 HOUR))');
            $q->execute([$ticketId,$uid,$ticketId,$uid]);
            if($q->rowCount()<1)throw new RuntimeException('You already followed up on this request in the last 24 hours.');
            $id=(int)db()->lastInsertId();
            foreach(TicketNotifications::hrReviewerIds() as $hr)NotificationService::create(['recipient_user_id'=>$hr,'kind'=>'HR_REQUEST','title'=>'Follow up requested: '.$t['ticket_no'],
                'body'=>'A team lead asked HR to follow up on this request. Open the request to review it.','link'=>url('hr-request',['id'=>$ticketId]),'dedupe_key'=>'lead-followup:'.$id.':'.$hr]);
            db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    }

    /** When this user last followed up on the ticket, or null. */
    public static function lastFollowUp(int $ticketId,int $userId): ?string
    {
        $q=db()->prepare('SELECT MAX(created_at) FROM ticket_lead_followups WHERE ticket_id=? AND user_id=?');$q->execute([$ticketId,$userId]);$v=$q->fetchColumn();return $v?:null;
    }

    public static function canFollowUp(int $ticketId,int $userId): bool
    {
        $q=db()->prepare('SELECT 1 FROM ticket_lead_followups WHERE ticket_id=? AND user_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 24 HOUR) LIMIT 1');$q->execute([$ticketId,$userId]);return !$q->fetchColumn();
    }

    /** Which of these tickets still have an unread notification for the user (the "New" badge). */
    public static function unreadTicketIds(int $userId,array $ticketIds): array
    {
        if(!$ticketIds||!FoundationRepository::tableExists('notifications'))return [];
        $map=[];foreach($ticketIds as $id)$map[url('hr-request',['id'=>(int)$id])]=(int)$id;
        $q=db()->prepare('SELECT DISTINCT link FROM notifications WHERE recipient_user_id=? AND read_at IS NULL AND link IN ('.implode(',',array_fill(0,count($map),'?')).')');
        $q->execute(array_merge([$userId],array_keys($map)));
        return array_map(fn($l)=>$map[$l],$q->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Opening the request clears its unread notifications for that user. */
    public static function markSeen(int $ticketId,int $userId): void
    {
        db()->prepare('UPDATE notifications SET read_at=NOW() WHERE recipient_user_id=? AND read_at IS NULL AND link=?')->execute([$userId,url('hr-request',['id'=>$ticketId])]);
    }
}
