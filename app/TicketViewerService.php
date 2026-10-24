<?php
declare(strict_types=1);
require_once __DIR__.'/EmployeeApproverAssignmentService.php';
require_once __DIR__.'/EmployeeDirectoryPlacement.php';

/**
 * Read-only ticket viewers: the people above an employee see that employee's HR requests and can do nothing else.
 *  - Retail branch: the branch leads (branch_leads, effective-dated, several allowed) and the ADL whose assigned areas
 *    (employees.directory_area_ids) contain the ticket's frozen branch area.
 *  - Head Office: the employee's Manager as resolved for routing (EmployeeApproverAssignmentService::resolve).
 *  - HR override: ticket_viewer_overrides adds one extra viewer for one employee.
 * Everything needs ticket.view_team and an active linked account, is evaluated when a ticket is read (never
 * snapshotted), and never covers Incident Reports or drafts. Approval stays in TicketService::permissions().
 */
final class TicketViewerService
{
    private const ACTIVE='("ACTIVE","PROBATIONARY","ON_LEAVE")';

    public static function ready(): bool
    {
        return FoundationRepository::tableExists('branch_leads') && FoundationRepository::tableExists('ticket_viewer_overrides');
    }

    /** Active account whose role holds ticket.view_team. Deny by default. */
    public static function permitted(int $uid): bool
    {
        if ($uid<1 || !self::ready()) return false;
        $q=db()->prepare('SELECT 1 FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status="ACTIVE"
            AND (r.code="SUPER_ADMIN" OR EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.code="ticket.view_team"))');
        $q->execute([$uid]);return (bool)$q->fetchColumn();
    }

    /** @return array{branch_ids:int[],area_ids:int[],manager_employee_ids:int[],employee_ids:int[]}|null null = no viewer access */
    public static function scope(int $uid): ?array
    {
        if (!self::permitted($uid)) return null;
        $today=date('Y-m-d');$scope=['branch_ids'=>[],'area_ids'=>[],'manager_employee_ids'=>[],'employee_ids'=>[]];
        $q=db()->prepare('SELECT e.id,e.directory_area_ids,'.EmployeeDirectoryPlacement::isAdlSql('e','p').' is_adl FROM employees e LEFT JOIN positions p ON p.id=e.position_id WHERE e.user_id=? AND e.status IN '.self::ACTIVE);
        $q->execute([$uid]);$mine=$q->fetchAll();$mineIds=array_map(static fn($r)=>(int)$r['id'],$mine);
        foreach ($mine as $e) if (!empty($e['is_adl'])) $scope['area_ids']=array_merge($scope['area_ids'],EmployeeDirectoryPlacement::areaIds($e['directory_area_ids']));
        if ($mineIds) {
            $in=implode(',',array_fill(0,count($mineIds),'?'));
            $q=db()->prepare('SELECT DISTINCT bl.branch_id FROM branch_leads bl WHERE bl.employee_id IN ('.$in.') AND bl.superseded_at IS NULL AND bl.effective_from<=? AND (bl.effective_to IS NULL OR bl.effective_to>=?)');
            $q->execute(array_merge($mineIds,[$today,$today]));$scope['branch_ids']=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
            $q=db()->prepare('SELECT DISTINCT o.employee_id FROM ticket_viewer_overrides o WHERE o.viewer_employee_id IN ('.$in.') AND o.superseded_at IS NULL AND o.effective_from<=? AND (o.effective_to IS NULL OR o.effective_to>=?)');
            $q->execute(array_merge($mineIds,[$today,$today]));$scope['employee_ids']=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        }
        $scope['manager_employee_ids']=self::managedHeadOffice($uid,$mineIds,$today);
        $scope['area_ids']=array_values(array_unique($scope['area_ids']));
        return $scope;
    }

    /** Head Office employees whose routing Manager is this account; resolve() is the single source of truth. */
    private static function managedHeadOffice(int $uid,array $mineIds,string $today): array
    {
        $or=[];$params=[];
        if ($mineIds) {$or[]='e.manager_employee_id IN ('.implode(',',array_fill(0,count($mineIds),'?')).')';$params=array_merge($params,$mineIds);}
        if (EmployeeApproverAssignmentService::ready()) {
            $or[]='EXISTS(SELECT 1 FROM employee_approver_assignments a WHERE a.employee_id=e.id AND a.approver_type="MANAGER" AND a.approver_user_id=? AND a.superseded_at IS NULL AND a.effective_from<=? AND (a.effective_to IS NULL OR a.effective_to>=?))';
            array_push($params,$uid,$today,$today);
        }
        if (!$or) return [];
        $q=db()->prepare('SELECT e.id FROM employees e WHERE e.status IN '.self::ACTIVE.' AND ('.implode(' OR ',$or).')');$q->execute($params);
        $ids=[];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $route=EmployeeApproverAssignmentService::resolve(EmployeeApproverAssignmentService::employee((int)$id),$today);
            if ($route['type']==='MANAGER' && (int)$route['user_id']===$uid) $ids[]=(int)$id;
        }
        return $ids;
    }

    /**
     * SQL condition over the TicketRepository select (t = tickets, b = the ticket's frozen branch), or null when the
     * account has no viewer access at all. The caller still adds the type / draft exclusions.
     * @return array{0:string,1:array}|null
     */
    public static function clause(int $uid): ?array
    {
        $s=self::scope($uid);if ($s===null) return null;
        $parts=[];$params=[];
        $branchParts=[];
        if ($s['branch_ids']) {$branchParts[]='t.branch_id IN ('.implode(',',array_fill(0,count($s['branch_ids']),'?')).')';$params=array_merge($params,$s['branch_ids']);}
        if ($s['area_ids']) {$branchParts[]='b.area_id IN ('.implode(',',array_fill(0,count($s['area_ids']),'?')).')';$params=array_merge($params,$s['area_ids']);}
        // A lead or ADL never sees Head Office tickets through the branch rule.
        if ($branchParts) $parts[]='(b.site_type<>"HEAD_OFFICE" AND ('.implode(' OR ',$branchParts).'))';
        if ($s['manager_employee_ids']) {$parts[]='(b.site_type="HEAD_OFFICE" AND t.subject_employee_id IN ('.implode(',',array_fill(0,count($s['manager_employee_ids']),'?')).'))';$params=array_merge($params,$s['manager_employee_ids']);}
        if ($s['employee_ids']) {$parts[]='t.subject_employee_id IN ('.implode(',',array_fill(0,count($s['employee_ids']),'?')).')';$params=array_merge($params,$s['employee_ids']);}
        return [$parts?'('.implode(' OR ',$parts).')':'0=1',$params];
    }

    // ---------------------------------------------------------------- HR setup and report (tickets.approvers.manage)

    private static function ymd(string $value,string $label): string
    {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$d || $d->format('Y-m-d')!==$value) throw new RuntimeException('Enter a valid '.$label.'.');
        return $value;
    }

    private static function window(array $data): array
    {
        $from=self::ymd((string)($data['effective_from']??''),'effective date');$to=trim((string)($data['effective_to']??''));
        if ($from<date('Y-m-d')) throw new RuntimeException('Effective date must be today or later. Past assignments are kept as history.');
        if ($to!=='') {$to=self::ymd($to,'end date');if ($to<$from) throw new RuntimeException('End date must be on or after the effective date.');}
        return [$from,$to?:null];
    }

    private static function employeeByNo(string $no,string $label): array
    {
        $no=trim($no);
        $q=db()->prepare('SELECT id,employee_no,first_name,last_name,user_id FROM employees WHERE employee_no=? AND status IN '.self::ACTIVE);$q->execute([$no]);
        $e=$q->fetch();if (!$e) throw new RuntimeException('No active employee has the '.$label.' number you entered.');
        return $e;
    }

    /** Bulk: one lead (several allowed per branch) for many branches. Returns [added, skippedAlreadyLeading]. */
    public static function assignLeads(array $data): array
    {
        EmployeeApproverAssignmentService::requireHr();
        $lead=self::employeeByNo((string)($data['lead_employee_no']??''),'lead employee');[$from,$to]=self::window($data);
        $raw=$data['branch_ids']??[];
        if (!is_array($raw) || count($raw)<1 || count($raw)>200) throw new RuntimeException('Select 1 to 200 branches.');
        $ids=[];foreach ($raw as $id) {if (!is_scalar($id)||!ctype_digit((string)$id)||(int)$id<1) throw new RuntimeException('Invalid branch selection.');$ids[]=(int)$id;}
        $ids=array_values(array_unique($ids));$added=0;$skipped=0;$uid=(int)Auth::user()['id'];db()->beginTransaction();
        try {
            foreach ($ids as $branchId) {
                $q=db()->prepare('SELECT id,name,site_type,code,active FROM branches WHERE id=? FOR UPDATE');$q->execute([$branchId]);$b=$q->fetch();
                if (!$b || !(int)$b['active']) throw new RuntimeException('One selected branch is not active. Refresh the page.');
                if ($b['site_type']==='HEAD_OFFICE' || $b['code']==='HEAD_OFFICE') throw new RuntimeException('Head Office has no branch lead; its viewer is the Manager.');
                $q=db()->prepare('SELECT COUNT(*) FROM branch_leads WHERE branch_id=? AND employee_id=? AND superseded_at IS NULL AND effective_from<=COALESCE(?,"9999-12-31") AND (effective_to IS NULL OR effective_to>=?)');
                $q->execute([$branchId,$lead['id'],$to,$from]);
                if ((int)$q->fetchColumn()) {$skipped++;continue;}
                db()->prepare('INSERT INTO branch_leads(branch_id,employee_id,effective_from,effective_to,assigned_by) VALUES(?,?,?,?,?)')->execute([$branchId,$lead['id'],$from,$to,$uid]);
                audit('HR Requests','ASSIGN_BRANCH_LEAD','branch',$branchId,['branch_lead_id'=>(int)db()->lastInsertId(),'employee_id'=>(int)$lead['id'],'effective_from'=>$from,'effective_to'=>$to]);
                $added++;
            }
            db()->commit();return [$added,$skipped];
        } catch (Throwable $e) {if (db()->inTransaction()) db()->rollBack();throw $e;}
    }

    /** Ends a lead today (or cancels one that has not started). History stays. */
    public static function endLead(int $id): void
    {
        EmployeeApproverAssignmentService::requireHr();self::end('branch_leads',$id,'END_BRANCH_LEAD','branch');
    }

    public static function addOverride(array $data): int
    {
        EmployeeApproverAssignmentService::requireHr();
        $subject=self::employeeByNo((string)($data['employee_no']??''),'employee');$viewer=self::employeeByNo((string)($data['viewer_employee_no']??''),'viewer');
        if ((int)$subject['id']===(int)$viewer['id']) throw new RuntimeException('An employee cannot be their own viewer.');
        [$from,$to]=self::window($data);
        $q=db()->prepare('SELECT COUNT(*) FROM ticket_viewer_overrides WHERE employee_id=? AND viewer_employee_id=? AND superseded_at IS NULL AND effective_from<=COALESCE(?,"9999-12-31") AND (effective_to IS NULL OR effective_to>=?)');
        $q->execute([$subject['id'],$viewer['id'],$to,$from]);
        if ((int)$q->fetchColumn()) throw new RuntimeException('That viewer already covers this employee in this date range.');
        db()->prepare('INSERT INTO ticket_viewer_overrides(employee_id,viewer_employee_id,effective_from,effective_to,assigned_by) VALUES(?,?,?,?,?)')->execute([$subject['id'],$viewer['id'],$from,$to,(int)Auth::user()['id']]);
        $id=(int)db()->lastInsertId();
        audit('HR Requests','ADD_VIEWER_OVERRIDE','employee',(int)$subject['id'],['override_id'=>$id,'viewer_employee_id'=>(int)$viewer['id'],'effective_from'=>$from,'effective_to'=>$to]);
        return $id;
    }

    public static function endOverride(int $id): void
    {
        EmployeeApproverAssignmentService::requireHr();self::end('ticket_viewer_overrides',$id,'END_VIEWER_OVERRIDE','employee');
    }

    private static function end(string $table,int $id,string $action,string $record): void
    {
        db()->beginTransaction();
        try {
            $q=db()->prepare('SELECT * FROM '.$table.' WHERE id=? AND superseded_at IS NULL FOR UPDATE');$q->execute([$id]);$row=$q->fetch();
            if (!$row) throw new RuntimeException('That assignment is already ended.');
            $today=date('Y-m-d');
            if ($row['effective_to']!==null && $row['effective_to']<$today) throw new RuntimeException('That assignment is already ended.');
            if ($row['effective_from']>=$today) db()->prepare('UPDATE '.$table.' SET superseded_at=NOW() WHERE id=?')->execute([$id]);
            else db()->prepare('UPDATE '.$table.' SET effective_to=?, superseded_at=NULL WHERE id=?')->execute([date('Y-m-d',strtotime('-1 day')),$id]);
            audit('HR Requests',$action,$record,(int)($table==='branch_leads'?$row['branch_id']:$row['employee_id']),['id'=>$id,'was_effective_from'=>$row['effective_from'],'was_effective_to'=>$row['effective_to']]);
            db()->commit();
        } catch (Throwable $e) {if (db()->inTransaction()) db()->rollBack();throw $e;}
    }

    /** Active branch rows for the setup page: lead names, ADL coverage, employee count. */
    public static function branchRows(int $areaId,int $branchId): array
    {
        EmployeeApproverAssignmentService::requireHr();$today=date('Y-m-d');
        $where=['b.active=1','b.site_type<>"HEAD_OFFICE"','COALESCE(b.code,"")<>"HEAD_OFFICE"'];$params=[];
        if ($areaId===-1) $where[]='b.area_id IS NULL'; elseif ($areaId>0) {$where[]='b.area_id=?';$params[]=$areaId;}
        if ($branchId>0) {$where[]='b.id=?';$params[]=$branchId;}
        $q=db()->prepare('SELECT b.id,b.code,b.name,b.area_id,a.name area_name,(SELECT COUNT(*) FROM employees e WHERE e.branch_id=b.id AND e.status IN '.self::ACTIVE.') emps
            FROM branches b LEFT JOIN areas a ON a.id=b.area_id WHERE '.implode(' AND ',$where).' ORDER BY a.name IS NULL,a.name,b.name LIMIT 300');
        $q->execute($params);$rows=$q->fetchAll();
        $adls=self::adlsWithCoverage();
        foreach ($rows as &$r) {
            $r['leads']=self::leadsForBranch((int)$r['id'],$today);
            $r['lead_ready']=(bool)array_filter($r['leads'],static fn($l)=>$l['can_view']);
            $r['adls']=self::adlsCovering($r['area_id']?(int)$r['area_id']:null,$adls);
        }
        return $rows;
    }

    /** Current and future leads of one branch with whether each can actually view (account + ticket.view_team). */
    private static function leadsForBranch(int $branchId,string $today): array
    {
        $q=db()->prepare('SELECT bl.id,bl.effective_from,bl.effective_to,e.employee_no,e.first_name,e.last_name,e.user_id FROM branch_leads bl JOIN employees e ON e.id=bl.employee_id
            WHERE bl.branch_id=? AND bl.superseded_at IS NULL AND (bl.effective_to IS NULL OR bl.effective_to>=?) ORDER BY bl.effective_from,bl.id');
        $q->execute([$branchId,$today]);$leads=$q->fetchAll();
        foreach ($leads as &$l) $l['can_view']=$l['user_id'] && self::permitted((int)$l['user_id']);
        unset($l);return $leads;
    }

    /** The ADLs (from adlsWithCoverage) whose assigned areas contain this area and who can actually view. */
    private static function adlsCovering(?int $areaId,array $adls): array
    {
        return array_values(array_filter($adls,static fn($a)=>$areaId && in_array($areaId,$a['areas'],true) && $a['can_view']));
    }

    /**
     * Read-only answer for the Employee 201 Employment tab: who can read this employee's HR requests today.
     * Same rules as scope()/clause(): retail = branch leads + covering ADL, Head Office = the routing Manager,
     * plus HR extra viewers. Nothing is written; HR-only screens are not required to look at it.
     * @return array{ready:bool,head_office:bool,leads:array,adls:array,manager:?array,extra:array,has_viewer:bool}
     */
    public static function viewersFor(int $employeeId): array
    {
        $out=['ready'=>self::ready(),'head_office'=>false,'leads'=>[],'adls'=>[],'manager'=>null,'extra'=>[],'has_viewer'=>false];
        if (!$out['ready']) return $out;
        $today=date('Y-m-d');$employee=EmployeeApproverAssignmentService::employee($employeeId);
        $out['head_office']=EmployeeApproverAssignmentService::kind($employee)==='MANAGER';
        if ($out['head_office']) {
            $route=EmployeeApproverAssignmentService::resolve($employee,$today);
            if ($route['type']==='MANAGER' && $route['user_id']) {
                $uid=(int)$route['user_id'];
                $q=db()->prepare('SELECT COALESCE(NULLIF(TRIM(CONCAT(e.first_name," ",e.last_name)),""),u.full_name) name,e.employee_no FROM users u LEFT JOIN employees e ON e.user_id=u.id WHERE u.id=? ORDER BY e.id LIMIT 1');
                $q->execute([$uid]);$m=$q->fetch()?:['name'=>'Manager','employee_no'=>null];
                $out['manager']=$m+['can_view'=>self::permitted($uid)];
            }
        } elseif (!empty($employee['branch_id'])) {
            $q=db()->prepare('SELECT area_id FROM branches WHERE id=?');$q->execute([(int)$employee['branch_id']]);$area=(int)$q->fetchColumn();
            $out['leads']=self::leadsForBranch((int)$employee['branch_id'],$today);
            $out['adls']=self::adlsCovering($area?:null,self::adlsWithCoverage());
        }
        $q=db()->prepare('SELECT v.employee_no,v.first_name,v.last_name,v.user_id FROM ticket_viewer_overrides o JOIN employees v ON v.id=o.viewer_employee_id
            WHERE o.employee_id=? AND o.superseded_at IS NULL AND o.effective_from<=? AND (o.effective_to IS NULL OR o.effective_to>=?) ORDER BY o.id');
        $q->execute([$employeeId,$today,$today]);$out['extra']=$q->fetchAll();
        foreach ($out['extra'] as &$x) $x['can_view']=$x['user_id'] && self::permitted((int)$x['user_id']);
        unset($x);
        $out['has_viewer']=(bool)array_filter($out['leads'],static fn($l)=>$l['can_view'] && $l['effective_from']<=$today)
            || $out['adls'] || ($out['manager'] && $out['manager']['can_view']) || (bool)array_filter($out['extra'],static fn($x)=>$x['can_view']);
        return $out;
    }

    /** ADL employees (directory rule) with their assigned area ids and account status. */
    public static function adlsWithCoverage(): array
    {
        $q=db()->query('SELECT e.id,e.employee_no,e.first_name,e.last_name,e.user_id,e.directory_area_ids,b.name branch_name,b.site_type
            FROM employees e LEFT JOIN positions p ON p.id=e.position_id LEFT JOIN branches b ON b.id=e.branch_id
            WHERE e.status IN '.self::ACTIVE.' AND '.EmployeeDirectoryPlacement::isAdlSql('e','p').' ORDER BY e.last_name,e.first_name');
        $out=[];
        foreach ($q->fetchAll() as $e) {
            $e['areas']=EmployeeDirectoryPlacement::areaIds($e['directory_area_ids']);
            $q=db()->prepare('SELECT COUNT(*) FROM users WHERE id=? AND status="ACTIVE"');$q->execute([(int)$e['user_id']]);
            $e['user_id']=(int)$q->fetchColumn()?(int)$e['user_id']:0;
            $e['can_view']=$e['user_id'] && self::permitted($e['user_id']);
            $out[]=$e;
        }
        return $out;
    }

    public static function overrides(): array
    {
        EmployeeApproverAssignmentService::requireHr();$today=date('Y-m-d');
        $q=db()->prepare('SELECT o.id,o.effective_from,o.effective_to,s.employee_no s_no,s.first_name s_first,s.last_name s_last,v.employee_no v_no,v.first_name v_first,v.last_name v_last
            FROM ticket_viewer_overrides o JOIN employees s ON s.id=o.employee_id JOIN employees v ON v.id=o.viewer_employee_id
            WHERE o.superseded_at IS NULL AND (o.effective_to IS NULL OR o.effective_to>=?) ORDER BY o.id DESC LIMIT 100');
        $q->execute([$today]);return $q->fetchAll();
    }

    /** Datalist for the lead field: Sales Captains / Team Coordinators and anyone on an SC, TC, OIC or ADL account. */
    public static function leadCandidates(): array
    {
        EmployeeApproverAssignmentService::requireHr();
        return db()->query('SELECT e.employee_no,e.first_name,e.last_name,b.name branch_name FROM employees e LEFT JOIN branches b ON b.id=e.branch_id LEFT JOIN positions p ON p.id=e.position_id
            LEFT JOIN users u ON u.id=e.user_id LEFT JOIN roles r ON r.id=u.role_id WHERE e.status IN '.self::ACTIVE.'
            AND (UPPER(COALESCE(p.name,"")) LIKE "%SALES CAPTAIN%" OR UPPER(COALESCE(p.name,"")) LIKE "%TEAM COORDINATOR%" OR r.code IN ("SC","TC","OIC")) ORDER BY e.last_name,e.first_name LIMIT 400')->fetchAll();
    }

    /**
     * Unresolved-viewers report. An employee is resolvable when any account that holds ticket.view_team has them in
     * scope(); this reuses scope() so the report cannot disagree with the access rule.
     */
    public static function report(int $page=1): array
    {
        EmployeeApproverAssignmentService::requireHr();$today=date('Y-m-d');
        $branchCovered=[];$areaCovered=[];$employeeCovered=[];
        $users=db()->query('SELECT DISTINCT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status="ACTIVE" AND r.code<>"SUPER_ADMIN"
            AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.code="ticket.view_team")')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($users as $uid) {
            $s=self::scope((int)$uid);if (!$s) continue;
            foreach ($s['branch_ids'] as $id) $branchCovered[$id]=true;
            foreach ($s['area_ids'] as $id) $areaCovered[$id]=true;
            foreach (array_merge($s['manager_employee_ids'],$s['employee_ids']) as $id) $employeeCovered[$id]=true;
        }
        $q=db()->query('SELECT e.id,e.employee_no,e.first_name,e.last_name,e.user_id,b.id branch_id,b.name branch_name,b.area_id,(b.site_type="HEAD_OFFICE" OR COALESCE(b.code,"")="HEAD_OFFICE") is_ho,bu.company_group
            FROM employees e LEFT JOIN branches b ON b.id=e.branch_id LEFT JOIN business_units bu ON bu.id=e.business_unit_id WHERE e.status IN '.self::ACTIVE.' ORDER BY e.last_name,e.first_name,e.id');
        $employees=$q->fetchAll();$missing=[];$count=['employees'=>count($employees),'with_viewer'=>0,'retail_missing'=>0,'ho_missing'=>0];
        foreach ($employees as $e) {
            $ok=isset($employeeCovered[(int)$e['id']]) || (!$e['is_ho'] && $e['branch_id'] && (isset($branchCovered[(int)$e['branch_id']]) || ($e['area_id'] && isset($areaCovered[(int)$e['area_id']]))));
            if ($ok) {$count['with_viewer']++;continue;}
            $e['is_ho']?$count['ho_missing']++:$count['retail_missing']++;$missing[]=$e;
        }
        $branches=[];
        foreach (self::branchRows(0,0) as $b) {
            if ($b['lead_ready'] || !$b['emps']) continue;
            $b['adl_covered']=!empty($b['adls']);$branches[]=$b;
        }
        $adls=self::adlsWithCoverage();
        $noSetup=array_values(array_filter($adls,static fn($a)=>!$a['user_id'] || !$a['areas'] || !$a['can_view']));
        $adlInHo=array_values(array_filter($adls,static fn($a)=>($a['site_type']??'')==='HEAD_OFFICE' || !$a['branch_name']));
        $noArea=db()->query('SELECT b.id,b.code,b.name,(SELECT COUNT(*) FROM employees e WHERE e.branch_id=b.id AND e.status IN '.self::ACTIVE.') emps FROM branches b
            WHERE b.active=1 AND b.site_type<>"HEAD_OFFICE" AND COALESCE(b.code,"")<>"HEAD_OFFICE" AND b.area_id IS NULL ORDER BY b.name')->fetchAll();
        $page=max(1,$page);
        return ['counts'=>$count,'missing'=>array_slice($missing,($page-1)*50,50),'missing_total'=>count($missing),'page'=>$page,'pages'=>max(1,(int)ceil(count($missing)/50)),
            'branches_without_lead'=>$branches,'adls'=>$adls,'adls_unready'=>$noSetup,'adls_in_head_office'=>$adlInHo,'branches_without_area'=>$noArea];
    }
}
