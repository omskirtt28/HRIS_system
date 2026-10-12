<?php
declare(strict_types=1);
require_once __DIR__.'/EmployeeStatusPolicy.php';

/**
 * Maternity leave: Live birth 105 days, Early termination of pregnancy 60 days (calendar days from the start date).
 * HR records it from Employee 201; the employee returns to the Employee Status they had after HR confirms the medical certificate.
 */
final class MaternityService
{
    public const KINDS=['LIVE_BIRTH'=>['label'=>'Live birth','days'=>105],'EARLY_TERMINATION'=>['label'=>'Early termination of pregnancy','days'=>60]];

    public static function ready(): bool { return FoundationRepository::tableExists('employee_maternity_leaves'); }

    public static function kindLabel(string $kind): string { return self::KINDS[$kind]['label'] ?? $kind; }

    /** End = start + days - 1; the employee returns the day after. */
    public static function dates(string $kind,string $start): array
    {
        if(!isset(self::KINDS[$kind])) throw new RuntimeException('Choose the maternity leave type.');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$start);
        if(!$d || $d->format('Y-m-d')!==$start) throw new RuntimeException('Enter a valid maternity leave start date.');
        $days=self::KINDS[$kind]['days'];
        return ['days'=>$days,'start'=>$start,'end'=>$d->modify('+'.($days-1).' days')->format('Y-m-d'),'return'=>$d->modify('+'.$days.' days')->format('Y-m-d')];
    }

    public static function active(int $employeeId): ?array
    {
        if(!self::ready()) return null;
        $q=db()->prepare('SELECT * FROM employee_maternity_leaves WHERE employee_id=? AND status="ACTIVE" ORDER BY id DESC LIMIT 1'); $q->execute([$employeeId]);
        return $q->fetch()?:null;
    }

    private static function uid(): ?int { return (int)(Auth::user()['id'] ?? 0) ?: null; }

    private static function employee(int $id): array
    {
        $e=EmployeeRepository::find($id);
        if(!$e) throw new RuntimeException('Employee record not found.');
        if(in_array($e['status'],EmployeeRepository::ARCHIVED_STATUSES,true)) throw new RuntimeException('This employee is in the Employee Archive. Restore the employee first.');
        return $e;
    }

    /** Records the leave, sets Employee Status = Maternity Leave and makes sure payroll sees approved leave days. */
    public static function start(int $employeeId,string $kind,string $startDate,string $remarks=''): int
    {
        Auth::requirePermission('employees.manage');
        $remarks=trim($remarks); if(mb_strlen($remarks)>400) throw new RuntimeException('Remarks must be 400 characters or fewer.');
        $range=self::dates($kind,trim($startDate));
        $owned=!db()->inTransaction(); if($owned) db()->beginTransaction();
        try {
            $q=db()->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE'); $q->execute([$employeeId]);
            $e=self::employee($employeeId);
            if(self::active($employeeId)) throw new RuntimeException('This employee already has an active maternity leave. Use Return to work first.');
            $typeId=EmployeeStatusPolicy::idForCode('MATERNITY_LEAVE'); if(!$typeId) throw new RuntimeException('Maternity Leave is not in the Employee Status list. Run the Employee Status migration.');
            $previous=$e['employment_type_id']!==null?(int)$e['employment_type_id']:null;
            if($previous===$typeId) $previous=null; // already marked Maternity Leave before the dates were recorded
            $leaveId=self::payrollLeave($employeeId,$range,$kind);
            db()->prepare('INSERT INTO employee_maternity_leaves(employee_id,leave_kind,leave_days,start_date,end_date,return_date,previous_employment_type_id,leave_request_id,leave_request_created,remarks,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$employeeId,$kind,$range['days'],$range['start'],$range['end'],$range['return'],$previous,$leaveId['id'],$leaveId['created']?1:0,$remarks?:null,self::uid()]);
            $id=(int)db()->lastInsertId();
            EmployeeRepository::updateEmployment($employeeId,['employment_type_id'=>$typeId,'effective_date'=>$range['start']<=date('Y-m-d')?$range['start']:date('Y-m-d'),
                'remarks'=>'Maternity leave ('.self::kindLabel($kind).', '.$range['days'].' days): '.$range['start'].' to '.$range['end'].'. Returns '.$range['return'].'.','_maternity_internal'=>true]);
            audit('Employees','MATERNITY_LEAVE_START','employee',$employeeId,['leave_id'=>$id,'kind'=>$kind,'start'=>$range['start'],'end'=>$range['end'],'return'=>$range['return'],'leave_request_id'=>$leaveId['id']]);
            if($owned) db()->commit();
            return $id;
        } catch(Throwable $error) { if($owned&&db()->inTransaction()) db()->rollBack(); throw $error; }
    }

    /** Links an existing system filing, or creates an approved MATERNITY leave recorded by HR (no leave credit is deducted). */
    private static function payrollLeave(int $employeeId,array $range,string $kind): array
    {
        $q=db()->prepare('SELECT id FROM leave_types WHERE code="MATERNITY" AND active=1'); $q->execute(); $typeId=(int)$q->fetchColumn();
        if(!$typeId) return ['id'=>null,'created'=>false];
        $q=db()->prepare('SELECT lr.id,lt.code,lr.status FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.employee_id=? AND lr.status NOT IN ("REJECTED","CANCELLED") AND lr.date_from<=? AND lr.date_to>=?');
        $q->execute([$employeeId,$range['end'],$range['start']]); $rows=$q->fetchAll();
        foreach($rows as $r) if($r['code']!=='MATERNITY') throw new RuntimeException('The employee already has another leave request overlapping these dates. Cancel or change it first.');
        if($rows) return ['id'=>(int)$rows[0]['id'],'created'=>false];
        $no=PayrollRepository::nextRequestNo('LVR','leave_requests'); $uid=self::uid()?:0;
        db()->prepare('INSERT INTO leave_requests(request_no,employee_id,leave_type_id,date_from,date_to,days,reason,status,current_step,created_by) VALUES(?,?,?,?,?,?,?,"APPROVED",1,?)')
            ->execute([$no,$employeeId,$typeId,$range['start'],$range['end'],$range['days'],'Maternity leave ('.self::kindLabel($kind).') recorded by HR from Employee 201.',$uid]);
        $id=(int)db()->lastInsertId();
        db()->prepare('INSERT INTO leave_request_approvals(request_id,step_no,approver_type,approver_user_id,status,remarks,acted_by,acted_at) VALUES(?,1,"HR_LEAVE",?,"APPROVED","Recorded by HR from Employee 201",?,NOW())')->execute([$id,$uid?:null,$uid?:null]);
        return ['id'=>$id,'created'=>true];
    }

    /** HR confirms the actual medical certificate was handed in (optionally attaching the file as a MEDICAL document). */
    public static function receiveCertificate(int $employeeId,string $date,array $file=[]): void
    {
        Auth::requirePermission('employees.manage');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',trim($date));
        if(!$d || $d->format('Y-m-d')!==trim($date) || $d->format('Y-m-d')>date('Y-m-d')) throw new RuntimeException('Enter the date the medical certificate was received (not in the future).');
        $owned=!db()->inTransaction(); if($owned) db()->beginTransaction();
        try {
            $m=self::active($employeeId); if(!$m) throw new RuntimeException('This employee has no active maternity leave.');
            $docId=null;
            if(!empty($file) && ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) $docId=EmployeeRepository::uploadDocument($employeeId,$file,['document_type'=>'MEDICAL','title'=>'Medical certificate (maternity leave)']);
            db()->prepare('UPDATE employee_maternity_leaves SET med_cert_received_on=?,med_cert_received_by=?,med_cert_document_id=COALESCE(?,med_cert_document_id) WHERE id=?')->execute([$d->format('Y-m-d'),self::uid(),$docId,(int)$m['id']]);
            audit('Employees','MATERNITY_CERT_RECEIVED','employee',$employeeId,['leave_id'=>(int)$m['id'],'received_on'=>$d->format('Y-m-d'),'document_id'=>$docId]);
            if($owned) db()->commit();
        } catch(Throwable $error) { if($owned&&db()->inTransaction()) db()->rollBack(); throw $error; }
    }

    /** Back to the Employee Status the employee had before the leave. Needs the medical certificate. */
    public static function returnToWork(int $employeeId,string $effectiveDate,string $remarks=''): void
    {
        Auth::requirePermission('employees.manage');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',trim($effectiveDate));
        if(!$d || $d->format('Y-m-d')!==trim($effectiveDate) || $d->format('Y-m-d')>date('Y-m-d')) throw new RuntimeException('Enter a valid return date (not in the future).');
        $owned=!db()->inTransaction(); if($owned) db()->beginTransaction();
        try {
            $q=db()->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE'); $q->execute([$employeeId]);
            $m=self::active($employeeId); if(!$m) throw new RuntimeException('This employee has no active maternity leave.');
            if(empty($m['med_cert_received_on'])) throw new RuntimeException('Record the medical certificate first. The employee must submit the actual medical certificate to HR before returning.');
            $typeId=$m['previous_employment_type_id']!==null?(int)$m['previous_employment_type_id']:EmployeeStatusPolicy::idForCode('REGULAR');
            EmployeeRepository::updateEmployment($employeeId,['employment_type_id'=>$typeId,'effective_date'=>$d->format('Y-m-d'),'remarks'=>trim($remarks)?:'Returned from maternity leave (medical certificate received '.$m['med_cert_received_on'].').','_maternity_internal'=>true]);
            db()->prepare('UPDATE employee_maternity_leaves SET status="COMPLETED",completed_at=NOW() WHERE id=?')->execute([(int)$m['id']]);
            audit('Employees','MATERNITY_RETURN_TO_WORK','employee',$employeeId,['leave_id'=>(int)$m['id'],'effective_date'=>$d->format('Y-m-d')]);
            if($owned) db()->commit();
        } catch(Throwable $error) { if($owned&&db()->inTransaction()) db()->rollBack(); throw $error; }
    }

    /** A mistaken entry: restores the previous status and cancels the leave record this feature created. */
    public static function cancel(int $employeeId,string $remarks): void
    {
        Auth::requirePermission('employees.manage');
        $remarks=trim($remarks); if($remarks==='') throw new RuntimeException('Enter the reason for cancelling the maternity leave.');
        $owned=!db()->inTransaction(); if($owned) db()->beginTransaction();
        try {
            $q=db()->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE'); $q->execute([$employeeId]);
            $m=self::active($employeeId); if(!$m) throw new RuntimeException('This employee has no active maternity leave.');
            $typeId=$m['previous_employment_type_id']!==null?(int)$m['previous_employment_type_id']:EmployeeStatusPolicy::idForCode('REGULAR');
            EmployeeRepository::updateEmployment($employeeId,['employment_type_id'=>$typeId,'effective_date'=>date('Y-m-d'),'remarks'=>'Maternity leave cancelled: '.mb_substr($remarks,0,300),'_maternity_internal'=>true]);
            if((int)$m['leave_request_created']===1 && $m['leave_request_id']) db()->prepare('UPDATE leave_requests SET status="CANCELLED" WHERE id=? AND status="APPROVED"')->execute([(int)$m['leave_request_id']]);
            db()->prepare('UPDATE employee_maternity_leaves SET status="CANCELLED",completed_at=NOW(),remarks=? WHERE id=?')->execute([mb_substr($remarks,0,500),(int)$m['id']]);
            audit('Employees','MATERNITY_LEAVE_CANCEL','employee',$employeeId,['leave_id'=>(int)$m['id'],'remarks'=>$remarks]);
            if($owned) db()->commit();
        } catch(Throwable $error) { if($owned&&db()->inTransaction()) db()->rollBack(); throw $error; }
    }

    /** Active maternity leaves for the watch page. */
    public static function watch(): array
    {
        if(!self::ready()) return [];
        $today=new DateTimeImmutable('today');
        $rows=db()->query('SELECT m.*,e.first_name,e.middle_name,e.last_name,e.suffix,e.roster_full_name,e.employee_no,e.id emp_id FROM employee_maternity_leaves m JOIN employees e ON e.id=m.employee_id WHERE m.status="ACTIVE" ORDER BY m.return_date')->fetchAll();
        foreach($rows as &$r) $r['days_left']=(int)$today->diff(new DateTimeImmutable($r['return_date']))->format('%r%a');
        return $rows;
    }
}
