<?php
declare(strict_types=1);

/** Slice-15 assignments only. Never writes the legacy Reporting To or site settings. */
final class EmployeeApproverAssignmentService
{
    public static function requireHr(): void
    {
        if (!Auth::check() || !in_array(Auth::user()['role_portal'] ?? '', ['hr','admin'], true)
            || !Auth::can('tickets.approvers.manage')) {
            throw new RuntimeException('HR approver-assignment access is required.');
        }
    }

    public static function ready(): bool
    {
        return FoundationRepository::tableExists('employee_approver_assignments');
    }

    public static function kind(array $employee): string
    {
        // Match the existing payroll routing classifier, not directory display labels.
        return ($employee['site_type'] ?: (($employee['branch_code'] === 'HEAD_OFFICE' || $employee['workplace'] === 'HEAD_OFFICE') ? 'HEAD_OFFICE' : 'BRANCH')) === 'HEAD_OFFICE' ? 'MANAGER' : 'ADL';
    }

    /** A Manager files straight to HR and has no viewer above them: the employee's own account holds the MANAGER role. */
    public static function isManagerFiler(array $employee): bool
    {
        if (empty($employee['user_id'])) return false;
        $q=db()->prepare('SELECT 1 FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND r.code="MANAGER"');
        $q->execute([(int)$employee['user_id']]);return (bool)$q->fetchColumn();
    }

    public static function employee(int $id, bool $lock = false): array
    {
        $q=db()->prepare('SELECT e.id,e.employee_no,e.first_name,e.last_name,e.status,e.user_id,e.branch_id,e.manager_employee_id,
            e.updated_by,e.legal_entity_id,e.business_unit_id,b.code branch_code,b.name branch_name,b.site_type,
            s.workplace,s.approver_user_id site_approver_user_id,s.updated_by site_updated_by,
            bu.company_group,bu.name company_name
            FROM employees e LEFT JOIN branches b ON b.id=e.branch_id
            LEFT JOIN payroll_site_settings s ON s.branch_id=e.branch_id
            LEFT JOIN business_units bu ON bu.id=e.business_unit_id WHERE e.id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$id]);$e=$q->fetch();
        if (!$e) throw new RuntimeException('Employee not found.');
        return $e;
    }

    public static function validApprover(int $uid, string $kind, array $employee): bool
    {
        if (!in_array($kind,['MANAGER','ADL'],true) || $uid<1 || $uid===(int)($employee['user_id']??0)) return false;
        $permission=$kind==='MANAGER'?'payroll.approve_manager':'payroll.approve_adl';
        $q=db()->prepare('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
            WHERE u.id=? AND u.status="ACTIVE"
            AND (r.code="SUPER_ADMIN" OR EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.code=?))
            AND NOT EXISTS(SELECT 1 FROM employees a WHERE a.user_id=u.id AND a.status NOT IN ("ACTIVE","PROBATIONARY","ON_LEAVE"))');
        $q->execute([$uid,$permission]);return (bool)$q->fetchColumn();
    }

    /** Internal routing resolver. Callers must establish employee/ticket authorization before exposing it. */
    public static function resolve(array $employee, string $date, bool $useAssignments = true): array
    {
        self::date($date);$kind=self::kind($employee);
        $none=['type'=>$kind,'user_id'=>null,'assignment_id'=>null,'provenance'=>null,'source_reference'=>null,'source_actor_user_id'=>null,
            'reason'=>'Please ask HR to assign your '.($kind==='ADL'?'ADL':'Manager').' with an active approval account.'];
        // Head Office is stored with an empty branch code (roster import), so only the branch itself is required.
        if (empty($employee['branch_id'])) return $none;
        if ($useAssignments && self::ready()) {
            $q=db()->prepare('SELECT * FROM employee_approver_assignments WHERE employee_id=? AND approver_type=? AND superseded_at IS NULL AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC,id DESC LIMIT 2');
            $q->execute([$employee['id'],$kind,$date,$date]);$rows=$q->fetchAll();
            if ($rows) {
                if(count($rows)!==1 || !self::validApprover((int)$rows[0]['approver_user_id'],$kind,$employee))return $none;
                return array_replace($none,['user_id'=>(int)$rows[0]['approver_user_id'],'assignment_id'=>(int)$rows[0]['id'],'provenance'=>$rows[0]['provenance'],'source_reference'=>$rows[0]['source_reference'],'reason'=>null]);
            }
        }
        if (!empty($employee['manager_employee_id'])) {
            $q=db()->prepare('SELECT user_id FROM employees WHERE id=? AND status IN ("ACTIVE","PROBATIONARY","ON_LEAVE")');
            $q->execute([$employee['manager_employee_id']]);$uid=(int)$q->fetchColumn();
            if(self::validApprover($uid,$kind,$employee))return array_replace($none,['user_id'=>$uid,'provenance'=>'LEGACY_EMPLOYEE','source_reference'=>'employees:'.$employee['id'].':manager_employee_id','reason'=>null]);
        }
        // This matches the old payroll fallback: only branch/retail ADLs use site settings.
        $uid=(int)($employee['site_approver_user_id']??0);
        if($kind==='ADL' && self::validApprover($uid,$kind,$employee))return array_replace($none,['user_id'=>$uid,'provenance'=>'LEGACY_SITE','source_reference'=>'payroll_site_settings:'.$employee['branch_id'],'source_actor_user_id'=>$employee['site_updated_by']?:null,'reason'=>null]);
        return $none;
    }

    public static function date(string $value): string
    {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$d || $d->format('Y-m-d')!==$value)throw new RuntimeException('Enter a valid effective date.');
        return $value;
    }

    /** CLI-only migration observation: no invented historical assignment date or HR author. */
    public static function backfill(): array
    {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Backfill is CLI-only.');
        if(!self::ready())throw new RuntimeException('Apply migration 002 first.');
        $result=['inserted'=>0,'resolved'=>0,'unresolved'=>0];db()->beginTransaction();
        try {
            foreach(db()->query('SELECT id FROM employees WHERE status IN ("ACTIVE","PROBATIONARY","ON_LEAVE") ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $e=self::employee((int)$id,true);$route=self::resolve($e,date('Y-m-d'));
                if(!$route['user_id']){$result['unresolved']++;continue;}
                $result['resolved']++;
                if($route['assignment_id'])continue;
                $q=db()->prepare('INSERT IGNORE INTO employee_approver_assignments(employee_id,approver_user_id,approver_type,effective_from,assigned_by,provenance,source_reference,source_actor_user_id) VALUES(?,?,?, ?,NULL,?,?,?)');
                $q->execute([$id,$route['user_id'],$route['type'],date('Y-m-d'),$route['provenance'],$route['source_reference'],$route['source_actor_user_id']]);
                if($q->rowCount()) {
                    $result['inserted']++;
                    audit('HR Requests','BACKFILL_APPROVER','employee',(int)$id,['assignment_id'=>(int)db()->lastInsertId(),'provenance'=>$route['provenance'],'source_reference'=>$route['source_reference'],'observed_on'=>date('Y-m-d')]);
                }
            }
            db()->commit();return $result;
        }catch(Throwable $e){db()->rollBack();throw $e;}
    }

    public static function candidates(string $kind): array
    {
        self::requireHr();
        if(!in_array($kind,['MANAGER','ADL'],true))throw new RuntimeException('Choose Manager or ADL.');
        $q=db()->prepare('SELECT u.id,u.full_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status="ACTIVE"
            AND (r.code="SUPER_ADMIN" OR EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.code=?))
            AND NOT EXISTS(SELECT 1 FROM employees e WHERE e.user_id=u.id AND e.status NOT IN ("ACTIVE","PROBATIONARY","ON_LEAVE")) ORDER BY u.full_name');
        $q->execute([$kind==='MANAGER'?'payroll.approve_manager':'payroll.approve_adl']);return $q->fetchAll();
    }

    /** HR report is generated on demand; no employee export is written into the repo. */
    public static function report(array $filter): array
    {
        self::requireHr();$items=[];$unresolved=0;$total=0;
        $q=db()->prepare('SELECT e.id FROM employees e LEFT JOIN business_units bu ON bu.id=e.business_unit_id
            WHERE e.status IN ("ACTIVE","PROBATIONARY","ON_LEAVE") AND (?=0 OR e.branch_id=?) AND (?=0 OR e.business_unit_id=?)
            AND (?="" OR bu.company_group=?) ORDER BY e.last_name,e.first_name,e.id');
        $branch=(int)($filter['branch_id']??0);$company=(int)($filter['business_unit_id']??0);$group=(string)($filter['company_group']??'');
        $q->execute([$branch,$branch,$company,$company,$group,$group]);
        $offset=(max(1,(int)($filter['p']??1))-1)*50;
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
            $e=self::employee((int)$id);$route=self::resolve($e,date('Y-m-d'));
            if(!$route['user_id'])$unresolved++;
            if(!empty($filter['unresolved']) && $route['user_id'])continue;
            if(!empty($filter['kind']) && $route['type']!==$filter['kind'])continue;
            if($total >= $offset && count($items)<50)$items[]=['employee'=>$e,'route'=>$route];
            $total++;
        }
        return ['rows'=>$items,'total'=>$total,'unresolved'=>$unresolved,'pages'=>max(1,(int)ceil($total/50))];
    }

    private static function input(array $data): array
    {
        $raw=$data['employee_ids']??[];
        if(!is_array($raw) || count($raw)<1 || count($raw)>100)throw new RuntimeException('Select 1 to 100 employees.');
        $ids=[];foreach($raw as $id){if(!is_scalar($id)||!ctype_digit((string)$id)||(int)$id<1)throw new RuntimeException('Invalid employee selection.');$ids[]=(int)$id;}
        $ids=array_values(array_unique($ids));sort($ids);
        $kind=(string)($data['approver_type']??'');if(!in_array($kind,['MANAGER','ADL'],true))throw new RuntimeException('Choose Manager or ADL.');
        $from=self::date((string)($data['effective_from']??''));$to=trim((string)($data['effective_to']??''));
        if($from<date('Y-m-d'))throw new RuntimeException('Effective date must be today or later. Historical assignments are preserved.');
        if($to!==''){$to=self::date($to);if($to<$from)throw new RuntimeException('End date must be on or after the effective date.');}
        $uid=filter_var($data['approver_user_id']??null,FILTER_VALIDATE_INT);
        if(!$uid || $uid<1)throw new RuntimeException('Choose an active approver.');
        return ['employee_ids'=>$ids,'approver_type'=>$kind,'approver_user_id'=>$uid,'effective_from'=>$from,'effective_to'=>$to?:null];
    }

    private static function inspect(array $data,bool $lock): array
    {
        $out=[];
        foreach($data['employee_ids'] as $id){
            $e=self::employee($id,$lock);
            if(!in_array($e['status'],['ACTIVE','PROBATIONARY','ON_LEAVE'],true))throw new RuntimeException('One selected employee is inactive. Refresh the employee list.');
            if(empty($e['branch_code']))throw new RuntimeException('Assign a branch to every selected employee first.');
            if(self::kind($e)!==$data['approver_type'])throw new RuntimeException('The selected employees need a different approver type. Filter by Manager or ADL first.');
            if(!self::validApprover($data['approver_user_id'],$data['approver_type'],$e))throw new RuntimeException('Approver must have an active matching approval account and cannot approve their own request.');
            $q=db()->prepare('SELECT * FROM employee_approver_assignments WHERE employee_id=? AND approver_type=? AND superseded_at IS NULL ORDER BY id'.($lock?' FOR UPDATE':''));
            $q->execute([$id,$data['approver_type']]);$rows=$q->fetchAll();
            foreach($rows as $row){
                if($row['effective_from']>$data['effective_from'] && ($data['effective_to']===null || $row['effective_from']<=$data['effective_to']))throw new RuntimeException('A selected employee already has a future assignment in this date range. Choose a non-overlapping range.');
            }
            $out[]=['employee'=>$e,'assignments'=>$rows];
        }
        return $out;
    }

    public static function preview(array $raw): array
    {
        self::requireHr();$data=self::input($raw);$rows=self::inspect($data,false);
        return ['data'=>$data,'rows'=>$rows,'fingerprint'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
    }

    public static function save(array $preview): int
    {
        self::requireHr();$data=self::input($preview['data']??[]);$uid=(int)Auth::user()['id'];db()->beginTransaction();
        try {
            $rows=self::inspect($data,true);
            if(!hash_equals((string)($preview['fingerprint']??''),hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))))throw new RuntimeException('An employee or assignment changed after preview. Preview the selection again.');
            foreach($rows as $item){
                $id=(int)$item['employee']['id'];
                foreach($item['assignments'] as $old){
                    if($old['effective_to']!==null && $old['effective_to']<$data['effective_from'])continue;
                    if($old['effective_from']>$data['effective_from'])continue;
                    if($old['effective_from']===$data['effective_from']){
                        db()->prepare('UPDATE employee_approver_assignments SET superseded_at=NOW(),superseded_by=? WHERE id=?')->execute([$uid,$old['id']]);
                    }else{
                        $end=(new DateTimeImmutable($data['effective_from']))->modify('-1 day')->format('Y-m-d');
                        db()->prepare('UPDATE employee_approver_assignments SET effective_to=? WHERE id=?')->execute([$end,$old['id']]);
                    }
                    audit('HR Requests','CLOSE_APPROVER_ASSIGNMENT','employee',$id,['assignment_id'=>$old['id'],'previous_effective_to'=>$old['effective_to'],'replaced_from'=>$data['effective_from']]);
                }
                db()->prepare('INSERT INTO employee_approver_assignments(employee_id,approver_user_id,approver_type,effective_from,effective_to,assigned_by,provenance) VALUES(?,?,?,?,?,?,"HR_BULK")')->execute([$id,$data['approver_user_id'],$data['approver_type'],$data['effective_from'],$data['effective_to'],$uid]);
                audit('HR Requests','ASSIGN_TICKET_APPROVER','employee',$id,['assignment_id'=>(int)db()->lastInsertId(),'approver_user_id'=>$data['approver_user_id'],'approver_type'=>$data['approver_type'],'effective_from'=>$data['effective_from'],'effective_to'=>$data['effective_to']]);
            }
            db()->commit();return count($rows);
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    }
}
