<?php
declare(strict_types=1);
final class TicketAccounting
{
    public static function canView():bool {
        $uid=TicketRepository::uid();if(!FoundationRepository::tableExists('ticket_accounting_memberships'))return false;
        $q=db()->prepare('SELECT 1 FROM ticket_accounting_memberships m JOIN permissions p ON p.id=m.permission_id WHERE m.user_id=? AND m.active=1 AND p.code="ticket.accounting.view"');$q->execute([$uid]);return (bool)$q->fetchColumn();
    }
    public static function manage(int $uid,bool $active):void {
        if(!TicketRepository::isHr())throw new RuntimeException('Only HR can assign Accounting members.');
        $q=db()->prepare('SELECT id FROM users WHERE id=? AND status="ACTIVE"');$q->execute([$uid]);if(!$q->fetchColumn())throw new RuntimeException('Choose an active account.');
        db()->beginTransaction();try{
            db()->prepare('INSERT INTO ticket_accounting_memberships(user_id,permission_id,active,assigned_by,provenance) SELECT ?,id,?,?,"HR" FROM permissions WHERE code="ticket.accounting.view" ON DUPLICATE KEY UPDATE active=VALUES(active),assigned_by=VALUES(assigned_by),provenance="HR"')->execute([$uid,$active?1:0,TicketRepository::uid()]);
            audit('HR Requests','ACCOUNTING_MEMBERSHIP','user',$uid,['active'=>$active]);db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    }
    public static function remark(int $id,string $lane,string $body):void {
        $body=trim($body);if($body===''||mb_strlen($body)>5000)throw new RuntimeException('Remarks must contain 1 to 5000 characters.');
        db()->beginTransaction();try{
            $t=TicketRepository::find($id,true);
            if($t['type_code']!=='NETSUITE_CONCERN'||!in_array($lane,['HR','ACCOUNTING'],true))throw new RuntimeException('Invalid remarks lane.');
            if($lane==='HR'&&!TicketRepository::isHr())throw new RuntimeException('Only HR can edit HR remarks.');
            if($lane==='ACCOUNTING'&&(!self::canView()||$t['status']!=='COMPLETED'))throw new RuntimeException('Accounting follow-up is available after HR completion.');
            db()->prepare('INSERT INTO ticket_netsuite_remarks(ticket_id,lane,body,author_user_id) VALUES(?,?,?,?)')->execute([$id,$lane,$body,TicketRepository::uid()]);
            $remarkId=(int)db()->lastInsertId();
            db()->prepare('INSERT INTO ticket_events(ticket_id,actor_user_id,event_type,from_status,to_status,details_json) VALUES(?,?,"REMARK_ADDED",?,?,?)')->execute([$id,TicketRepository::uid(),$t['status'],$t['status'],json_encode(['lane'=>$lane,'remark_id'=>$remarkId])]);
            // Accounting is a follow-up, never another final approval or ticket transition.
            if($lane==='ACCOUNTING')db()->prepare('UPDATE ticket_steps SET status="APPROVED",remarks="Accounting follow-up recorded",acted_by=?,acted_at=NOW() WHERE ticket_id=? AND approver_type="ACCOUNTING" AND status="PENDING"')->execute([TicketRepository::uid(),$id]);
            db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    }
    public static function remarks(int $id):array {
        $t=TicketRepository::find($id);if($t['type_code']!=='NETSUITE_CONCERN')return [];
        $q=db()->prepare('SELECT r.*,u.full_name FROM ticket_netsuite_remarks r JOIN users u ON u.id=r.author_user_id WHERE ticket_id=? ORDER BY r.id');$q->execute([$id]);return $q->fetchAll();
    }
}
