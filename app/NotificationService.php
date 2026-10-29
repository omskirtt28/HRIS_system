<?php
declare(strict_types=1);
require_once __DIR__.'/Mailer.php';

/** In-app notifications (bell) with optional email delivery. One row per recipient; dedupe_key keeps each reminder to once. */
final class NotificationService
{
    public static function ready(): bool { return FoundationRepository::tableExists('notifications'); }

    /** $n: recipient_user_id, recipient_employee_id, subject_employee_id, kind, title, body, link, due_date, email_to, dedupe_key. Returns true when newly created. */
    public static function create(array $n): bool
    {
        $q=db()->prepare('INSERT IGNORE INTO notifications(recipient_employee_id,recipient_user_id,subject_employee_id,kind,title,body,link,due_date,email_to,dedupe_key) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$n['recipient_employee_id'] ?? null,$n['recipient_user_id'] ?? null,$n['subject_employee_id'] ?? null,$n['kind'],mb_substr((string)$n['title'],0,190),(string)$n['body'],
            isset($n['link'])?mb_substr((string)$n['link'],0,255):null,$n['due_date'] ?? null,$n['email_to'] ?? null,mb_substr((string)$n['dedupe_key'],0,190)]);
        return $q->rowCount()>0;
    }

    public static function recent(int $userId,int $limit=10): array
    {
        $q=db()->prepare('SELECT id,kind,title,body,link,due_date,read_at,created_at FROM notifications WHERE recipient_user_id=? ORDER BY id DESC LIMIT '.max(1,min(50,$limit)));
        $q->execute([$userId]); return $q->fetchAll();
    }

    public static function unreadCount(int $userId): int
    {
        $q=db()->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_user_id=? AND read_at IS NULL'); $q->execute([$userId]); return (int)$q->fetchColumn();
    }

    /** One destination per unread notification; access flags come from existing menu/list rules. */
    public static function sidebarPage(array $n,array $access,int $userId): ?string
    {
        if(in_array($n['kind'],['PROBATION_5M','PROBATION_END','SEASONAL_2M','SEASONAL_END','MATERNITY_14D','MATERNITY_RETURN'],true))
            return $access['reminders']?'hr-probation':null;
        if($n['kind']!=='HR_REQUEST' || empty($n['type_code']))return null;
        $own=(int)$n['requester_user_id']===$userId;
        if($n['type_code']==='INCIDENT_REPORT'){
            if(!$access['incidents'] || !$n['incident_readable'])return null;
            if($own)return 'incident-mine';
            if($access['hr'])return 'hr-incidents';
            return $n['incident_mine']?'incident-mine':null;
        }
        if($own)return $access['self']?'hr-requests-my':null;
        if($n['type_code']==='NETSUITE_CONCERN' && $n['ticket_status']==='COMPLETED' && $access['accounting'])return 'hr-accounting-requests';
        if($access['approve'] && $n['assigned_approval'] && (int)$n['subject_user_id']!==$userId)return 'hr-request-inbox';
        if($access['hr'])return $n['type_code']==='PAYROLL_CONCERN'?'hr-payroll-concerns':'hr-service-requests';
        return null;
    }

    /** Counts all unread rows, independently of the bell's latest-ten limit. */
    public static function unreadSummary(int $userId): array
    {
        $summary=['unread'=>0,'sidebar_counts'=>[]];
        if(!Auth::check() || (int)Auth::user()['id']!==$userId || !self::ready())return $summary;
        $tickets=TicketRepository::ready();
        $portal=(string)(Auth::user()['role_portal']??'');
        $workspace=in_array($portal,['employee','hr','admin'],true);
        $access=['incidents'=>$workspace,'self'=>$workspace&&(Auth::can('tickets.request_self')||Auth::can('ticket.file_for_others')),
            'hr'=>$tickets&&TicketRepository::isHr(),'approve'=>$workspace&&TicketRepository::canApprove(),
            'accounting'=>$tickets&&$workspace&&TicketAccounting::canView(),
            'reminders'=>in_array($portal,['hr','admin'],true)&&Auth::can('employees.view_all')];
        $sql='SELECT n.kind';$params=[];
        if($tickets){
            [$read,$readArgs]=TicketIncident::readClause($userId,$access['hr']);
            [$mine,$mineArgs]=TicketIncident::mineClause($userId);
            $sql.=',ty.code type_code,t.requester_user_id,t.status ticket_status,employee.user_id subject_user_id,
                ('.$read.') incident_readable,('.$mine.') incident_mine,
                EXISTS(SELECT 1 FROM ticket_steps s WHERE s.ticket_id=t.id AND s.approver_user_id=? AND
                    ((s.approver_type="MANAGER" AND ?=1) OR (s.approver_type="ADL" AND ?=1))) assigned_approval';
            $params=array_merge($readArgs,$mineArgs,[$userId,Auth::can('payroll.approve_manager')?1:0,Auth::can('payroll.approve_adl')?1:0]);
        }
        $sql.=' FROM notifications n';
        if($tickets){
            // Existing ticket-event dedupe keys link to metadata without parsing notification titles or URLs.
            $sql.=' LEFT JOIN ticket_events ev ON n.kind="HR_REQUEST" AND ev.id=CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(n.dedupe_key,":",2),":",-1) AS UNSIGNED)
                AND n.dedupe_key=CONCAT("ticket-event:",ev.id,":",n.recipient_user_id)
                LEFT JOIN tickets t ON t.id=ev.ticket_id LEFT JOIN ticket_types ty ON ty.id=t.ticket_type_id
                LEFT JOIN employees employee ON employee.id=t.subject_employee_id';
        }
        $sql.=' WHERE n.recipient_user_id=? AND n.read_at IS NULL';$params[]=$userId;
        $q=db()->prepare($sql);$q->execute($params);
        while($n=$q->fetch()){
            $summary['unread']++;
            $page=self::sidebarPage($n,$access,$userId);
            if($page!==null)$summary['sidebar_counts'][$page]=($summary['sidebar_counts'][$page]??0)+1;
        }
        return $summary;
    }

    /** Marks one (or all, when $id is 0) of the user's own notifications as read. */
    public static function markRead(int $userId,int $id=0): void
    {
        db()->prepare('UPDATE notifications SET read_at=NOW() WHERE recipient_user_id=? AND read_at IS NULL'.($id>0?' AND id=?':''))->execute($id>0?[$userId,$id]:[$userId]);
    }

    /** Sends pending emails (3 tries each). Returns [sent, failed]. */
    public static function deliverPending(int $limit=25): array
    {
        if(!Mailer::configured()) return [0,0];
        $q=db()->query('SELECT id,email_to,title,body FROM notifications WHERE email_to IS NOT NULL AND emailed_at IS NULL AND email_attempts<3 ORDER BY id LIMIT '.max(1,$limit));
        $sent=0;$failed=0;
        foreach($q->fetchAll() as $n) {
            try {
                Mailer::send((string)$n['email_to'],'[PMBSI HRIS] '.$n['title'],$n['body']."\n\n— PMBSI HRIS (automatic message)");
                db()->prepare('UPDATE notifications SET emailed_at=NOW(),email_error=NULL,email_attempts=email_attempts+1 WHERE id=?')->execute([$n['id']]); $sent++;
            } catch(Throwable $e) {
                db()->prepare('UPDATE notifications SET email_error=?,email_attempts=email_attempts+1 WHERE id=?')->execute([mb_substr($e->getMessage(),0,250),$n['id']]); $failed++;
            }
        }
        return [$sent,$failed];
    }
}
