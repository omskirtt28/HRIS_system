<?php
declare(strict_types=1);

/** Separated employees: Set inactive / Terminated move an employee to the Employee Archive; Restore brings them back. */
final class EmployeeArchiveService
{
    private const EVENTS=['INACTIVE'=>'INACTIVATION','TERMINATED'=>'TERMINATION','RESIGNED'=>'RESIGNATION'];
    private const LABELS=['INACTIVE'=>'Inactive','RESIGNED'=>'Resigned','TERMINATED'=>'Terminated'];
    private const RETURN_STATUSES=['ACTIVE','PROBATIONARY','ON_LEAVE'];

    public static function statusLabel(string $status): string { return self::LABELS[$status] ?? ucwords(strtolower(str_replace('_',' ',$status))); }

    private static function archivedSql(): string { return '"'.implode('","',EmployeeRepository::ARCHIVED_STATUSES).'"'; }

    private static function effectiveDate(string $value): string
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',trim($value));
        if(!$date || $date->format('Y-m-d')!==trim($value)) throw new RuntimeException('Enter a valid effective date.');
        if($date->format('Y-m-d')>date('Y-m-d')) throw new RuntimeException('The effective date cannot be in the future.');
        return $date->format('Y-m-d');
    }

    private static function remarks(string $value): string
    {
        $value=trim($value);
        if($value==='') throw new RuntimeException('Enter the remarks for this change.');
        if(mb_strlen($value)>480) throw new RuntimeException('Remarks must be 480 characters or fewer.');
        return $value;
    }

    /** Reasons that stop an employee from leaving the active list, and things HR should know about first. */
    public static function checks(int $id): array
    {
        $blockers=[]; $warnings=[];
        $q=db()->prepare('SELECT first_name,last_name,employee_no FROM employees WHERE manager_employee_id=? AND status NOT IN ('.self::archivedSql().') ORDER BY last_name,first_name LIMIT 6');
        $q->execute([$id]); $reports=$q->fetchAll();
        if($reports) {
            $q=db()->prepare('SELECT COUNT(*) FROM employees WHERE manager_employee_id=? AND status NOT IN ('.self::archivedSql().')'); $q->execute([$id]); $total=(int)$q->fetchColumn();
            $names=implode(', ',array_map(static fn($r)=>trim($r['first_name'].' '.$r['last_name']),array_slice($reports,0,5)));
            $blockers[]=$total.' active employee'.($total===1?'':'s').' report to this person ('.$names.($total>5?', …':'').'). Assign a new Reporting To in their Employee 201 first.';
        }
        $q=db()->prepare('SELECT COUNT(*) FROM payroll_requests WHERE employee_id=? AND status LIKE "FOR\_%"'); $q->execute([$id]); $open=(int)$q->fetchColumn();
        if($open) $warnings[]=$open.' payroll ticket'.($open===1?' is':'s are').' still waiting for approval.';
        $q=db()->prepare('SELECT COUNT(*) FROM leave_requests WHERE employee_id=? AND status NOT IN ("APPROVED","REJECTED","CANCELLED","DRAFT")'); $q->execute([$id]); $open=(int)$q->fetchColumn();
        if($open) $warnings[]=$open.' leave request'.($open===1?' is':'s are').' still pending.';
        $q=db()->prepare('SELECT COUNT(*) FROM payroll_request_approvals a JOIN employees e ON e.user_id=a.approver_user_id WHERE e.id=? AND a.status="PENDING"'); $q->execute([$id]); $open=(int)$q->fetchColumn();
        if($open) $warnings[]=$open.' payroll ticket'.($open===1?' is':'s are').' waiting for this person\'s approval. Reassign them after the change.';
        return ['blockers'=>$blockers,'warnings'=>$warnings];
    }

    public static function archive(int $id,string $target,string $date,string $remarks): array
    {
        Auth::requirePermission('employees.manage');
        if(!isset(self::EVENTS[$target])) throw new RuntimeException('Choose Set inactive, Terminated or Resign.');
        $date=self::effectiveDate($date); $remarks=self::remarks($remarks);
        $owned=!db()->inTransaction(); if($owned) db()->beginTransaction();
        try {
            $q=db()->prepare('SELECT * FROM employees WHERE id=? FOR UPDATE'); $q->execute([$id]); $e=$q->fetch();
            if(!$e) throw new RuntimeException('Employee record not found.');
            if(in_array($e['status'],EmployeeRepository::ARCHIVED_STATUSES,true)) throw new RuntimeException('This employee is already in the Employee Archive.');
            if(!empty($e['user_id']) && (int)$e['user_id']===(int)(Auth::user()['id']??0)) throw new RuntimeException('You cannot archive the employee record linked to your own account.');
            $checks=self::checks($id);
            if($checks['blockers']) throw new RuntimeException($checks['blockers'][0]);
            $accountOff=false;
            if(!empty($e['user_id'])) {
                $q=db()->prepare('SELECT u.id,u.status,r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? FOR UPDATE'); $q->execute([(int)$e['user_id']]); $user=$q->fetch();
                if($user && $user['code']==='SUPER_ADMIN') throw new RuntimeException('This employee is linked to a Super Admin account. Change that account\'s role in Users & Access first.');
                if($user && $user['status']==='ACTIVE') {
                    db()->prepare('UPDATE users SET status="INACTIVE" WHERE id=?')->execute([(int)$e['user_id']]);
                    if(FoundationRepository::tableExists('user_sessions')) db()->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL')->execute([(int)$e['user_id']]);
                    $accountOff=true;
                }
            }
            // Resignation is also an Employee Status (Resign); other separations keep the status they had.
            $toTypeId=$e['employment_type_id']!==null?(int)$e['employment_type_id']:null;
            if($target==='RESIGNED') $toTypeId=EmployeeStatusPolicy::idForCode('RESIGN')?:$toTypeId;
            db()->prepare('UPDATE employees SET status=?,employment_type_id=?,updated_by=? WHERE id=?')->execute([$target,$toTypeId,(int)(Auth::user()['id']??0)?:null,$id]);
            $assignment=static fn(string $status,?int $typeId)=>['department_id'=>$e['department_id'],'position_id'=>$e['position_id'],'branch_id'=>$e['branch_id'],'employment_type_id'=>$typeId,'status'=>$status];
            EmployeeRepository::addHistory($id,self::EVENTS[$target],$date,$assignment((string)$e['status'],$e['employment_type_id']!==null?(int)$e['employment_type_id']:null),$assignment($target,$toTypeId),$remarks);
            audit('Employees',['TERMINATED'=>'EMPLOYEE_TERMINATED','RESIGNED'=>'EMPLOYEE_RESIGNED'][$target]??'EMPLOYEE_SET_INACTIVE','employee',$id,['from_status'=>$e['status'],'to_status'=>$target,'effective_date'=>$date,'remarks'=>$remarks,'login_account_deactivated'=>$accountOff]);
            if($owned) db()->commit();
            return ['name'=>EmployeeRepository::fullName($e),'status'=>$target,'account_deactivated'=>$accountOff];
        } catch(Throwable $error) { if($owned && db()->inTransaction()) db()->rollBack(); throw $error; }
    }

    public static function restore(int $id,string $date,string $remarks,bool $reactivateAccount): array
    {
        Auth::requirePermission('employees.manage');
        $date=self::effectiveDate($date); $remarks=self::remarks($remarks);
        $owned=!db()->inTransaction(); if($owned) db()->beginTransaction();
        try {
            $q=db()->prepare('SELECT * FROM employees WHERE id=? FOR UPDATE'); $q->execute([$id]); $e=$q->fetch();
            if(!$e) throw new RuntimeException('Employee record not found.');
            if(!in_array($e['status'],EmployeeRepository::ARCHIVED_STATUSES,true)) throw new RuntimeException('This employee is not in the Employee Archive.');
            $q=db()->prepare('SELECT from_status,from_employment_type_id FROM employee_employment_history WHERE employee_id=? AND to_status IN ('.self::archivedSql().') ORDER BY id DESC LIMIT 1'); $q->execute([$id]);
            $last=$q->fetch()?:['from_status'=>null,'from_employment_type_id'=>null];
            // An employee who resigned returns to the Employee Status they had before (not "Resign").
            $typeId=$e['employment_type_id']!==null?(int)$e['employment_type_id']:null;
            if(EmployeeStatusPolicy::code($typeId)==='RESIGN') $typeId=$last['from_employment_type_id']!==null?(int)$last['from_employment_type_id']:null;
            $status=EmployeeStatusPolicy::internalStatus($typeId,(string)$last['from_status']);
            if(!in_array($status,self::RETURN_STATUSES,true)) $status='ACTIVE';
            db()->prepare('UPDATE employees SET status=?,employment_type_id=?,updated_by=? WHERE id=?')->execute([$status,$typeId,(int)(Auth::user()['id']??0)?:null,$id]);
            $accountOn=false;
            if($reactivateAccount && !empty($e['user_id'])) {
                $q=db()->prepare('UPDATE users SET status="ACTIVE" WHERE id=? AND status<>"ACTIVE"'); $q->execute([(int)$e['user_id']]); $accountOn=$q->rowCount()>0;
            }
            $assignment=static fn(string $s,?int $t)=>['department_id'=>$e['department_id'],'position_id'=>$e['position_id'],'branch_id'=>$e['branch_id'],'employment_type_id'=>$t,'status'=>$s];
            EmployeeRepository::addHistory($id,'REACTIVATION',$date,$assignment((string)$e['status'],$e['employment_type_id']!==null?(int)$e['employment_type_id']:null),$assignment($status,$typeId),$remarks);
            audit('Employees','EMPLOYEE_RESTORED','employee',$id,['from_status'=>$e['status'],'to_status'=>$status,'employment_type_id'=>$typeId,'effective_date'=>$date,'remarks'=>$remarks,'login_account_reactivated'=>$accountOn]);
            if($owned) db()->commit();
            return ['name'=>EmployeeRepository::fullName($e),'status'=>$status];
        } catch(Throwable $error) { if($owned && db()->inTransaction()) db()->rollBack(); throw $error; }
    }

    public static function counts(): array
    {
        $counts=array_fill_keys(EmployeeRepository::ARCHIVED_STATUSES,0);
        foreach(db()->query('SELECT status,COUNT(*) total FROM employees WHERE status IN ('.self::archivedSql().') GROUP BY status')->fetchAll() as $r) $counts[$r['status']]=(int)$r['total'];
        return $counts;
    }

    public static function archived(array $filters): array
    {
        $where=['e.status IN ('.self::archivedSql().')']; $params=[];
        $status=strtoupper(trim((string)($filters['status']??'')));
        if(in_array($status,EmployeeRepository::ARCHIVED_STATUSES,true)) { $where[]='e.status=?'; $params[]=$status; }
        $term=trim((string)($filters['q']??''));
        if($term!=='') {
            $like='%'.$term.'%'; $where[]='(e.employee_no LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR CONCAT_WS(" ",e.first_name,e.last_name) LIKE ?)';
            array_push($params,$like,$like,$like,$like);
        }
        $q=db()->prepare('SELECT e.*,d.name department_name,p.name position_name,b.name branch_name,b.code branch_code,u.email user_email,u.status user_status,
                h.effective_date archive_date,h.remarks archive_remarks
            FROM employees e LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN positions p ON p.id=e.position_id LEFT JOIN branches b ON b.id=e.branch_id LEFT JOIN users u ON u.id=e.user_id
            LEFT JOIN employee_employment_history h ON h.id=(SELECT MAX(h2.id) FROM employee_employment_history h2 WHERE h2.employee_id=e.id AND h2.to_status IN ('.self::archivedSql().'))
            WHERE '.implode(' AND ',$where).' ORDER BY COALESCE(h.effective_date,DATE(e.updated_at)) DESC,e.last_name,e.first_name LIMIT 500');
        $q->execute($params); return $q->fetchAll();
    }

    /** Latest archive event for the Employee 201 banner. */
    public static function latestEvent(int $id): ?array
    {
        $q=db()->prepare('SELECT effective_date,remarks,event_type,to_status FROM employee_employment_history WHERE employee_id=? AND to_status IN ('.self::archivedSql().') ORDER BY id DESC LIMIT 1');
        $q->execute([$id]); return $q->fetch()?:null;
    }
}
