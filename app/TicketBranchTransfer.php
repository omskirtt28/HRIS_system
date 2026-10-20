<?php
declare(strict_types=1);
final class TicketBranchTransfer
{
    public static function current(array $employee,bool $lock=false):array {
        $q=db()->prepare('SELECT * FROM employee_placements WHERE employee_id=? AND (effective_from IS NULL OR effective_from<=CURDATE()) AND (effective_to IS NULL OR effective_to>=CURDATE()) ORDER BY effective_from'.($lock?' FOR UPDATE':''));$q->execute([$employee['id']]);$rows=$q->fetchAll();
        if(!$rows&&$lock){
            $q=db()->prepare('SELECT COUNT(*) FROM employee_placements WHERE employee_id=?');$q->execute([$employee['id']]);
            if(!(int)$q->fetchColumn()){
                db()->prepare('INSERT INTO employee_placements(employee_id,branch_id,effective_from,provenance) VALUES(?,?,NULL,"OBSERVED_CURRENT")')->execute([$employee['id'],$employee['branch_id']]);
                return self::current($employee,true);
            }
        }
        if(count($rows)!==1||(int)$rows[0]['branch_id']!==(int)$employee['branch_id'])throw new RuntimeException('Please ask HR to reconcile this employee’s current placement before transferring.');
        return $rows[0];
    }
    public static function checkDate(string $date,bool $lock=false):void {
        EmployeeApproverAssignmentService::date($date);
        // Lock every overlapping cutoff/run using the same parent-first order as finalization.
        $q=db()->prepare('SELECT id FROM payroll_cutoffs WHERE period_start<=? AND period_end>=? ORDER BY id'.($lock?' FOR UPDATE':''));$q->execute([$date,$date]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$p=TicketRepository::period((int)$id,$lock);if($p['state']==='FINALIZED')throw new RuntimeException('The transfer effective date falls in a locked cutoff. Choose another date.');}
    }
    public static function validate(array $payload,array $employee):array {
        if((int)$payload['employee']!==(int)$employee['id'])throw new RuntimeException('Choose the authorized subject employee.');
        $current=self::current($employee,true);
        if((int)$payload['from_branch']!==(int)$current['branch_id'])throw new RuntimeException('From branch must match the current placement.');
        if((int)$payload['to_branch']===(int)$current['branch_id'])throw new RuntimeException('Choose a different destination branch.');
        self::checkDate($payload['effective_date'],true);
        if($current['effective_from']!==null&&$payload['effective_date']<=$current['effective_from'])throw new RuntimeException('The effective date must follow the current placement start date.');
        if($current['effective_from']===null&&$payload['effective_date']<substr($current['observed_at'],0,10))throw new RuntimeException('The effective date cannot predate the observed placement.');
        return $payload;
    }
    public static function complete(array $ticket,array $data):bool {
        $p=$ticket['payload'];$employee=EmployeeApproverAssignmentService::employee((int)$ticket['subject_employee_id'],true);
        self::validate($p,$employee);
        if($p['effective_date']>date('Y-m-d'))return false;
        if(!Auth::can('employees.manage'))throw new RuntimeException('Employee management access is required to complete a transfer.');
        $current=self::current($employee,true);$end=(new DateTimeImmutable($p['effective_date']))->modify('-1 day')->format('Y-m-d');
        $q=db()->prepare('SELECT id FROM employee_placements WHERE employee_id=? AND id<>? AND (effective_to IS NULL OR effective_to>=?) FOR UPDATE');$q->execute([$employee['id'],$current['id'],$p['effective_date']]);
        if($q->fetchColumn())throw new RuntimeException('Another placement overlaps this transfer.');
        $q=db()->prepare('SELECT id FROM ticket_effects WHERE ticket_id=? AND effect_type="BRANCH_TRANSFER"');$q->execute([$ticket['id']]);if($q->fetchColumn())throw new RuntimeException('Transfer effect already exists; ask HR to review the ticket.');
        db()->prepare('UPDATE employee_placements SET effective_to=? WHERE id=?')->execute([$end,$current['id']]);
        db()->prepare('INSERT INTO employee_placements(employee_id,branch_id,effective_from,source_ticket_id,provenance) VALUES(?,?,?,?,"TICKET_TRANSFER")')->execute([$employee['id'],$p['to_branch'],$p['effective_date'],$ticket['id']]);
        // Company is a label only. PMBSI legal entity and employee company fields stay intact.
        db()->prepare('UPDATE employees SET branch_id=?,updated_by=? WHERE id=?')->execute([$p['to_branch'],TicketRepository::uid(),$employee['id']]);
        $new=EmployeeApproverAssignmentService::employee((int)$employee['id'],true);
        self::assignApprover($new,(int)($data['new_approver_user_id']??0),$p['effective_date']);
        $instruction=['employee_id'=>(int)$employee['id'],'from_placement_id'=>(int)$current['id'],'from_branch'=>(int)$p['from_branch'],'to_branch'=>(int)$p['to_branch'],'effective_date'=>$p['effective_date'],'company_label'=>$p['company']];
        db()->prepare('INSERT INTO ticket_effects(ticket_id,effect_type,instruction_json,created_by) VALUES(?,"BRANCH_TRANSFER",?,?)')->execute([$ticket['id'],json_encode($instruction,JSON_THROW_ON_ERROR),TicketRepository::uid()]);
        db()->prepare('INSERT INTO ticket_events(ticket_id,actor_user_id,event_type,details_json) VALUES(?,?,"EFFECT_CREATED",?)')->execute([$ticket['id'],TicketRepository::uid(),json_encode(['effect_type'=>'BRANCH_TRANSFER'])]);
        return true;
    }
    private static function assignApprover(array $employee,int $uid,string $from):void {
        EmployeeApproverAssignmentService::requireHr();$kind=EmployeeApproverAssignmentService::kind($employee);
        if(!EmployeeApproverAssignmentService::validApprover($uid,$kind,$employee))throw new RuntimeException('Select an active '.$kind.' for the destination branch before completing.');
        $q=db()->prepare('SELECT * FROM employee_approver_assignments WHERE employee_id=? AND superseded_at IS NULL AND (effective_to IS NULL OR effective_to>=?) FOR UPDATE');$q->execute([$employee['id'],$from]);
        foreach($q->fetchAll() as $old){
            if($old['effective_from']>=$from)db()->prepare('UPDATE employee_approver_assignments SET superseded_at=NOW(),superseded_by=? WHERE id=?')->execute([TicketRepository::uid(),$old['id']]);
            else db()->prepare('UPDATE employee_approver_assignments SET effective_to=? WHERE id=?')->execute([(new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'),$old['id']]);
        }
        db()->prepare('INSERT INTO employee_approver_assignments(employee_id,approver_user_id,approver_type,effective_from,assigned_by,provenance) VALUES(?,?,?,?,?,"HR_TRANSFER")')->execute([$employee['id'],$uid,$kind,$from,TicketRepository::uid()]);
        audit('HR Requests','TRANSFER_APPROVER_ASSIGNMENT','employee',(int)$employee['id'],['approver_user_id'=>$uid,'approver_type'=>$kind,'effective_from'=>$from]);
    }
}
