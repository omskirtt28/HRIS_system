<?php
declare(strict_types=1);
require_once __DIR__.'/TicketAccounting.php';

final class TicketRepository
{
    public static function ready():bool
    {
        return FoundationRepository::tableExists('payroll_concerns') && FoundationRepository::tableExists('ticket_notification_outbox');
    }
    public static function uid():int
    {
        if(!Auth::check())throw new RuntimeException('Please sign in.');
        $uid=(int)Auth::user()['id'];$q=db()->prepare('SELECT id FROM users WHERE id=? AND status="ACTIVE"');$q->execute([$uid]);
        if(!$q->fetchColumn())throw new RuntimeException('This account is no longer active. Please ask HR for assistance.');
        return $uid;
    }
    public static function isHr():bool
    {
        return Auth::check() && in_array(Auth::user()['role_portal']??'',['hr','admin'],true) && Auth::can('tickets.review_hr');
    }
    public static function canApprove():bool
    {
        return Auth::can('payroll.approve_manager') || Auth::can('payroll.approve_adl');
    }
    public static function ownEmployee():array
    {
        $q=db()->prepare('SELECT id FROM employees WHERE user_id=? AND status IN ("ACTIVE","PROBATIONARY","ON_LEAVE")');$q->execute([self::uid()]);
        $ids=$q->fetchAll(PDO::FETCH_COLUMN);
        if(count($ids)!==1)throw new RuntimeException('Please ask HR to link your active Employee 201 record to your account.');
        return EmployeeApproverAssignmentService::employee((int)$ids[0]);
    }
    private static function selectSql():string
    {
        return 'SELECT t.*,ty.code type_code,ty.name type_name,c.concern_date,c.is_late,c.late_reason,c.outcome,c.outcome_note,
            pc.period_start,pc.period_end,e.employee_no,e.first_name,e.last_name,e.user_id subject_user_id,b.name branch_name
            FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id
            LEFT JOIN payroll_concerns c ON c.ticket_id=t.id
            JOIN employees e ON e.id=t.subject_employee_id JOIN branches b ON b.id=t.branch_id
            LEFT JOIN payroll_cutoffs pc ON pc.id=t.payroll_period_id';
    }
    public static function find(int $id,bool $lock=false):array
    {
        $uid=self::uid();$q=db()->prepare(self::selectSql().' WHERE t.id=? AND (t.requester_user_id=? OR ?=1 OR EXISTS(SELECT 1 FROM ticket_steps s WHERE s.ticket_id=t.id AND s.approver_user_id=?) OR (ty.code="NETSUITE_CONCERN" AND t.status="COMPLETED" AND ?=1))'.($lock?' FOR UPDATE':''));
        $q->execute([$id,$uid,self::isHr()?1:0,$uid,TicketAccounting::canView()?1:0]);$t=$q->fetch();
        if(!$t)throw new RuntimeException('Ticket not found or access denied.');
        $t['payload']=json_decode($t['payload_json'],true,512,JSON_THROW_ON_ERROR);
        return $t;
    }
    public static function detail(int $id):array
    {
        $t=self::find($id);
        foreach(['steps'=>'SELECT s.*,u.full_name approver_name,a.full_name actor_name FROM ticket_steps s LEFT JOIN users u ON u.id=s.approver_user_id LEFT JOIN users a ON a.id=s.acted_by WHERE s.ticket_id=? ORDER BY s.step_no',
            'events'=>'SELECT e.*,u.full_name actor_name FROM ticket_events e JOIN users u ON u.id=e.actor_user_id WHERE e.ticket_id=? ORDER BY e.id',
            'comments'=>'SELECT c.*,u.full_name author_name FROM ticket_comments c JOIN users u ON u.id=c.author_user_id WHERE c.ticket_id=? ORDER BY c.id',
            'attachments'=>'SELECT id,original_name,file_size,created_at FROM ticket_attachments WHERE ticket_id=? ORDER BY id',
            'effects'=>'SELECT * FROM ticket_effects WHERE ticket_id=? ORDER BY id'] as $key=>$sql){$q=db()->prepare($sql);$q->execute([$id]);$t[$key]=$q->fetchAll();}
        return $t;
    }
    public static function types():array
    {
        self::uid();return array_values(array_filter(db()->query('SELECT id,code,name FROM ticket_types WHERE active=1 ORDER BY name')->fetchAll(),fn($type)=>$type['code']==='PAYROLL_CONCERN'||isset(TicketTypes::definitions()[$type['code']])));
    }
    public static function listing(string $mode,array $filters=[],int $limit=50):array
    {
        $uid=self::uid();$params=[];$where=[];
        if(in_array($mode,['hr','services'],true)){
            if(!self::isHr())throw new RuntimeException('HR ticket review access is required.');
            $where[]=$mode==='hr'?'ty.code="PAYROLL_CONCERN" AND t.status<>"DRAFT"':'ty.code<>"INCIDENT_REPORT" AND t.status<>"DRAFT"';
            if(!empty($filters['type_code'])){$where[]='ty.code=?';$params[]=$filters['type_code'];}
            if(!empty($filters['company_label'])){$where[]='JSON_UNQUOTE(JSON_EXTRACT(t.payload_json,"$.company"))=?';$params[]=$filters['company_label'];}
            if($mode==='services'&&!empty($filters['branch_id'])){$where[]='CASE WHEN ty.code="UNIFORM_REQUEST" THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(t.payload_json,"$.branch")) AS UNSIGNED) ELSE t.branch_id END=?';$params[]=(int)$filters['branch_id'];unset($filters['branch_id']);}
            foreach(['status'=>'t.status','branch_id'=>'t.branch_id','company_id'=>'t.company_id','business_unit_id'=>'t.business_unit_id_snapshot','payroll_period_id'=>'t.payroll_period_id','company_group'=>'t.company_group_snapshot'] as $key=>$column){if(isset($filters[$key]) && (string)$filters[$key]!=='' && (string)$filters[$key]!=='0'){$where[]=$column.'=?';$params[]=$filters[$key];}}
        }elseif($mode==='accounting'){
            if(!TicketAccounting::canView())throw new RuntimeException('Accounting membership is required.');
            $where[]='ty.code="NETSUITE_CONCERN" AND t.status="COMPLETED"';
        }elseif($mode==='inbox'){
            if(!self::canApprove())throw new RuntimeException('Manager or ADL approval access is required.');
            $where[]='t.status="PENDING_APPROVAL" AND t.requester_user_id<>? AND COALESCE(e.user_id,0)<>? AND EXISTS(SELECT 1 FROM ticket_steps s WHERE s.ticket_id=t.id AND s.step_no=t.current_step_no AND s.status="PENDING" AND s.approver_user_id=? AND ((s.approver_type="MANAGER" AND ?=1) OR (s.approver_type="ADL" AND ?=1)))';
            $params=[$uid,$uid,$uid,Auth::can('payroll.approve_manager')?1:0,Auth::can('payroll.approve_adl')?1:0];
        }elseif($mode==='my'){$where[]='t.requester_user_id=?';$params[]=$uid;}
        else throw new RuntimeException('Invalid ticket list.');
        $page=max(1,min(10000,(int)($filters['p']??1)));$limit=max(1,min(100,$limit));$offset=($page-1)*$limit;
        $q=db()->prepare(self::selectSql().' WHERE '.implode(' AND ',$where).' ORDER BY t.created_at DESC,t.id DESC LIMIT '.($limit+1).' OFFSET '.$offset);$q->execute($params);$rows=$q->fetchAll();
        return ['rows'=>array_slice($rows,0,$limit),'has_next'=>count($rows)>$limit,'page'=>$page];
    }
    public static function period(int $id,bool $lock=false):array
    {
        // Shared cutoff/run locks serialize filing and manual instructions with finalization.
        $q=db()->prepare('SELECT * FROM payroll_cutoffs WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);$p=$q->fetch();
        if(!$p)throw new RuntimeException('Cutoff not found. Please ask HR to configure it.');
        $q=db()->prepare('SELECT state,ticket_deadline FROM payroll_cutoff_runs WHERE cutoff_id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);
        return $p+($q->fetch()?:['state'=>'OPEN','ticket_deadline'=>null]);
    }
    public static function periodForDate(string $date,bool $lock=false):array
    {
        $q=db()->prepare('SELECT id FROM payroll_cutoffs WHERE period_start<=? AND period_end>=? ORDER BY period_start');$q->execute([$date,$date]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        if(count($ids)!==1)throw new RuntimeException('This date needs one matching cutoff. Please ask HR to configure the cutoff calendar.');
        return self::period((int)$ids[0],$lock);
    }
    public static function lateReason(array $p):?string
    {
        if($p['state']==='FINALIZED')return 'FINALIZED';
        if(!empty($p['ticket_deadline']) && $p['ticket_deadline']<date('Y-m-d H:i:s'))return 'DEADLINE_EXPIRED';
        if($p['status']==='CLOSED')return 'CALENDAR_CLOSED';
        return null;
    }
    public static function targetPeriods(array $ticket):array
    {
        if(!self::isHr())return [];
        $q=db()->prepare('SELECT pc.id,pc.code,pc.period_start,pc.period_end FROM payroll_cutoffs pc LEFT JOIN payroll_cutoff_runs r ON r.cutoff_id=pc.id
            WHERE pc.period_start>? AND pc.status="OPEN" AND COALESCE(r.state,"OPEN")<>"FINALIZED" AND (r.ticket_deadline IS NULL OR r.ticket_deadline>=?) ORDER BY pc.period_start LIMIT 100');
        $q->execute([$ticket['period_end'],date('Y-m-d H:i:s')]);return $q->fetchAll();
    }
}
