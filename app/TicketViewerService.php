<?php
declare(strict_types=1);
require_once __DIR__.'/EmployeeApproverAssignmentService.php';
require_once __DIR__.'/EmployeeDirectoryPlacement.php';

/**
 * Read-only ticket viewers: the people above an employee see that employee's HR requests and can do nothing else.
 * Every viewer is DERIVED from facts already on file; nobody is assigned by hand.
 *  - Retail branch: (1) the ADL whose assigned areas (employees.directory_area_ids) contain the branch's area,
 *    (2) the Sales Captain of the branch, (3) the OIC of the branch. Sales Captain / OIC are employees of the SAME
 *    branch whose position is Sales Captain / Officer In Charge (an OIC may also be an approved, dated OIC
 *    Authorization held by an account on the OIC role, see TicketOicAuthorization) with an active linked account.
 *  - Head Office: the employee's Manager as resolved for routing (EmployeeApproverAssignmentService::resolve).
 * Everything needs ticket.view_team and an active linked account, is evaluated when a ticket is read (never
 * snapshotted), and never covers Incident Reports or drafts. Approval stays in TicketService::permissions().
 *
 * DEPRECATED: branch_leads and ticket_viewer_overrides (migration 012) are kept but never read as access input.
 */
final class TicketViewerService
{
    private const ACTIVE='("ACTIVE","PROBATIONARY","ON_LEAVE")';
    /** Upper-cased trimmed position names. */
    public const SC_POSITIONS=['SALES CAPTAIN'];
    public const OIC_POSITIONS=['OFFICER IN CHARGE','OFFICER-IN-CHARGE','OIC'];

    public static function ready(): bool
    {
        return FoundationRepository::tableExists('ticket_viewer_seed');
    }

    /** Active account whose role holds ticket.view_team. Deny by default. */
    public static function permitted(int $uid): bool
    {
        if ($uid<1 || !self::ready()) return false;
        $q=db()->prepare('SELECT 1 FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status="ACTIVE"
            AND (r.code="SUPER_ADMIN" OR EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.code="ticket.view_team"))');
        $q->execute([$uid]);return (bool)$q->fetchColumn();
    }

    /** NONE = no account, DISABLED = account not active, NO_PERMISSION = role lacks ticket.view_team, ACTIVE = can view. */
    public static function accountState(?int $uid): string
    {
        if (!$uid) return 'NONE';
        $q=db()->prepare('SELECT status FROM users WHERE id=?');$q->execute([$uid]);$status=$q->fetchColumn();
        if ($status===false) return 'NONE';
        if ($status!=='ACTIVE') return 'DISABLED';
        return self::permitted($uid)?'ACTIVE':'NO_PERMISSION';
    }

    /** Employee rows for the lead rules, one SELECT shared by scope() and branchLeads() so they cannot disagree. */
    private static function leadSql(string $today): string
    {
        // $today comes from date(), never from input.
        $auth=FoundationRepository::tableExists('oic_authorizations')
            ?'(SELECT COUNT(*) FROM oic_authorizations a WHERE a.employee_id=e.id AND a.branch_id=e.branch_id AND a.status="APPROVED" AND a.start_date<="'.$today.'" AND a.end_date>="'.$today.'")':'0';
        return 'SELECT e.id,e.employee_no,e.first_name,e.last_name,e.user_id,e.branch_id,e.directory_area_ids,UPPER(TRIM(COALESCE(p.name,""))) pos,r.code role_code,'.$auth.' oic_auth,'
            .EmployeeDirectoryPlacement::isAdlSql('e','p').' is_adl
            FROM employees e LEFT JOIN positions p ON p.id=e.position_id LEFT JOIN users u ON u.id=e.user_id LEFT JOIN roles r ON r.id=u.role_id
            WHERE e.status IN '.self::ACTIVE;
    }

    /** 'SC', 'OIC' or null for an employee row from leadSql(). */
    private static function leadKind(array $row): ?string
    {
        if (in_array($row['pos'],self::SC_POSITIONS,true)) return 'SC';
        if (in_array($row['pos'],self::OIC_POSITIONS,true)) return 'OIC';
        return ($row['role_code']??'')==='OIC' && (int)$row['oic_auth']>0 ? 'OIC' : null;
    }

    /** @return array{branch_ids:int[],area_ids:int[],manager_employee_ids:int[]}|null null = no viewer access */
    public static function scope(int $uid): ?array
    {
        if (!self::permitted($uid)) return null;
        $today=date('Y-m-d');$scope=['branch_ids'=>[],'area_ids'=>[],'manager_employee_ids'=>[]];
        $q=db()->prepare(self::leadSql($today).' AND e.user_id=?');$q->execute([$uid]);$mine=$q->fetchAll();
        $mineIds=array_map(static fn($r)=>(int)$r['id'],$mine);
        foreach ($mine as $e) {
            if (!empty($e['is_adl'])) $scope['area_ids']=array_merge($scope['area_ids'],EmployeeDirectoryPlacement::areaIds($e['directory_area_ids']));
            if ($e['branch_id'] && self::leadKind($e)!==null) $scope['branch_ids'][]=(int)$e['branch_id'];
        }
        $scope['manager_employee_ids']=self::managedHeadOffice($uid,$mineIds,$today);
        $scope['area_ids']=array_values(array_unique($scope['area_ids']));
        $scope['branch_ids']=array_values(array_unique($scope['branch_ids']));
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
        // A Sales Captain, OIC or ADL never sees Head Office tickets through the branch rule.
        if ($branchParts) $parts[]='(b.site_type<>"HEAD_OFFICE" AND ('.implode(' OR ',$branchParts).'))';
        if ($s['manager_employee_ids']) {$parts[]='(b.site_type="HEAD_OFFICE" AND t.subject_employee_id IN ('.implode(',',array_fill(0,count($s['manager_employee_ids']),'?')).'))';$params=array_merge($params,$s['manager_employee_ids']);}
        return [$parts?'('.implode(' OR ',$parts).')':'0=1',$params];
    }

    // ---------------------------------------------------------------- derived people per branch

    /** Sales Captains and OICs of one branch with their account state. @return array{SC:array,OIC:array} */
    public static function branchLeads(int $branchId,?string $today=null): array
    {
        $today??=date('Y-m-d');$out=['SC'=>[],'OIC'=>[]];
        $q=db()->prepare(self::leadSql($today).' AND e.branch_id=? ORDER BY e.last_name,e.first_name,e.id');$q->execute([$branchId]);
        foreach ($q->fetchAll() as $e) {
            $kind=self::leadKind($e);if ($kind===null) continue;
            $e['account']=self::accountState($e['user_id']?(int)$e['user_id']:null);$e['can_view']=$e['account']==='ACTIVE';
            $e['via']=in_array($e['pos'],self::SC_POSITIONS,true)||in_array($e['pos'],self::OIC_POSITIONS,true)?'position':'authorization';
            $out[$kind][]=$e;
        }
        return $out;
    }

    /** All ADLs (from adlsWithCoverage) whose assigned areas contain this area; usableOnly keeps those who can view. */
    private static function adlsCovering(?int $areaId,array $adls,bool $usableOnly=true): array
    {
        return array_values(array_filter($adls,static fn($a)=>$areaId && in_array($areaId,$a['areas'],true) && (!$usableOnly || $a['can_view'])));
    }

    private static function personName(array $p): string
    {
        return trim(($p['first_name']??'').' '.($p['last_name']??'')).(!empty($p['employee_no'])?' ('.$p['employee_no'].')':'');
    }

    private const ACCOUNT_TEXT=['NONE'=>'has no linked account','DISABLED'=>'has a disabled account','NO_PERMISSION'=>'has a role that cannot view team requests'];

    /**
     * Who can view a retail branch's tickets and what is wrong with it. One answer for the Employee 201 panel and the
     * HR report. problems[] = [code,text]; all are PROBLEMs (shown in red).
     * @return array{sc:array,oic:array,adls:array,problems:array,has_viewer:bool}
     */
    private static function branchSet(array $branch,array $adls,string $today): array
    {
        $leads=self::branchLeads((int)$branch['id'],$today);$areaId=!empty($branch['area_id'])?(int)$branch['area_id']:null;
        $covering=self::adlsCovering($areaId,$adls,false);$problems=[];
        if (!$leads['SC']) $problems[]=['NO_SC','This branch has no Sales Captain on record. Only the ADL can view its requests.'];
        foreach ($leads['SC'] as $sc) if (!$sc['can_view']) $problems[]=['SC_NO_ACCOUNT','Sales Captain '.self::personName($sc).' '.self::ACCOUNT_TEXT[$sc['account']].'.'];
        if (count($leads['SC'])>1) $problems[]=['MULTI_SC','This branch has '.count($leads['SC']).' Sales Captains; there must be one.'];
        if (!$areaId) $problems[]=['NO_ADL_AREA','This branch has no area, so no ADL can cover it.'];
        elseif (!$covering) $problems[]=['NO_ADL','No ADL has this branch\'s area in Assigned areas.'];
        foreach ($covering as $a) {
            if (!$a['can_view']) $problems[]=['ADL_NO_ACCOUNT','ADL '.self::personName($a).' '.self::ACCOUNT_TEXT[$a['account']].'.'];
            if (($a['site_type']??'')==='HEAD_OFFICE' || !$a['branch_name']) $problems[]=['ADL_IN_HEAD_OFFICE','ADL '.self::personName($a).' is recorded under Head Office, not a retail branch. Coverage comes from Assigned areas; check the record.'];
        }
        $usable=static fn(array $rows)=>(bool)array_filter($rows,static fn($r)=>$r['can_view']);
        return ['sc'=>$leads['SC'],'oic'=>$leads['OIC'],'adls'=>$covering,'problems'=>$problems,'has_viewer'=>$usable($leads['SC'])||$usable($leads['OIC'])||$usable($covering)];
    }

    /**
     * Read-only answer for the Employee 201 Employment tab: who can read this employee's HR requests today.
     * Same rules as scope()/clause(). Nothing is written; HR-only screens are not required to look at it.
     * @return array{ready:bool,head_office:bool,branch:?array,sc:array,oic:array,adls:array,manager:?array,problems:array,has_viewer:bool}
     */
    public static function viewersFor(int $employeeId): array
    {
        $out=['ready'=>self::ready(),'head_office'=>false,'branch'=>null,'sc'=>[],'oic'=>[],'adls'=>[],'manager'=>null,'problems'=>[],'has_viewer'=>false];
        if (!$out['ready']) return $out;
        $today=date('Y-m-d');$employee=EmployeeApproverAssignmentService::employee($employeeId);
        $out['head_office']=EmployeeApproverAssignmentService::kind($employee)==='MANAGER';
        if ($out['head_office']) {
            $route=EmployeeApproverAssignmentService::resolve($employee,$today);
            if ($route['type']==='MANAGER' && $route['user_id']) {
                $uid=(int)$route['user_id'];
                $q=db()->prepare('SELECT COALESCE(NULLIF(TRIM(CONCAT(e.first_name," ",e.last_name)),""),u.full_name) name,e.employee_no FROM users u LEFT JOIN employees e ON e.user_id=u.id WHERE u.id=? ORDER BY e.id LIMIT 1');
                $q->execute([$uid]);$m=$q->fetch()?:['name'=>'Manager','employee_no'=>null];
                $out['manager']=$m+['account'=>self::accountState($uid),'can_view'=>self::permitted($uid)];
                if (!$out['manager']['can_view']) $out['problems'][]=['MANAGER_CANNOT_VIEW','The Manager\'s role cannot view team requests.'];
            } else $out['problems'][]=['NO_MANAGER','No Manager is set for this Head Office employee, so nobody can view their requests.'];
            $out['has_viewer']=$out['manager'] && $out['manager']['can_view'];
        } elseif (!empty($employee['branch_id'])) {
            $q=db()->prepare('SELECT b.id,b.name,b.area_id,a.name area_name FROM branches b LEFT JOIN areas a ON a.id=b.area_id WHERE b.id=?');$q->execute([(int)$employee['branch_id']]);$branch=$q->fetch();
            if ($branch) {
                $set=self::branchSet($branch,self::adlsWithCoverage(),$today);
                $out=array_replace($out,['branch'=>$branch,'sc'=>$set['sc'],'oic'=>$set['oic'],'adls'=>$set['adls'],'problems'=>$set['problems'],'has_viewer'=>$set['has_viewer']]);
            }
        } else $out['problems'][]=['NO_BRANCH','This employee has no branch on record, so no Sales Captain or ADL can be found.'];
        return $out;
    }

    /** ADL employees (directory rule) with their assigned area ids and account state. */
    public static function adlsWithCoverage(): array
    {
        $q=db()->query('SELECT e.id,e.employee_no,e.first_name,e.last_name,e.user_id,e.directory_area_ids,b.name branch_name,b.site_type
            FROM employees e LEFT JOIN positions p ON p.id=e.position_id LEFT JOIN branches b ON b.id=e.branch_id
            WHERE e.status IN '.self::ACTIVE.' AND '.EmployeeDirectoryPlacement::isAdlSql('e','p').' ORDER BY e.last_name,e.first_name');
        $out=[];
        foreach ($q->fetchAll() as $e) {
            $e['areas']=EmployeeDirectoryPlacement::areaIds($e['directory_area_ids']);
            $e['account']=self::accountState($e['user_id']?(int)$e['user_id']:null);
            $e['user_id']=in_array($e['account'],['ACTIVE','NO_PERMISSION'],true)?(int)$e['user_id']:0;
            $e['can_view']=$e['account']==='ACTIVE';
            $out[]=$e;
        }
        return $out;
    }

    // ---------------------------------------------------------------- HR problem report (tickets.approvers.manage)

    /** Active retail branches with their derived viewers and problems, for the report table. */
    public static function branchRows(int $areaId,int $branchId,bool $problemsOnly=false): array
    {
        EmployeeApproverAssignmentService::requireHr();$today=date('Y-m-d');
        $where=['b.active=1','b.site_type<>"HEAD_OFFICE"','COALESCE(b.code,"")<>"HEAD_OFFICE"'];$params=[];
        if ($areaId===-1) $where[]='b.area_id IS NULL'; elseif ($areaId>0) {$where[]='b.area_id=?';$params[]=$areaId;}
        if ($branchId>0) {$where[]='b.id=?';$params[]=$branchId;}
        $q=db()->prepare('SELECT b.id,b.code,b.name,b.area_id,a.name area_name,(SELECT COUNT(*) FROM employees e WHERE e.branch_id=b.id AND e.status IN '.self::ACTIVE.') emps
            FROM branches b LEFT JOIN areas a ON a.id=b.area_id WHERE '.implode(' AND ',$where).' ORDER BY a.name IS NULL,a.name,b.name LIMIT 300');
        $q->execute($params);$adls=self::adlsWithCoverage();$out=[];
        foreach ($q->fetchAll() as $r) {
            $r+=self::branchSet($r,$adls,$today);
            if ($problemsOnly && (!$r['problems'] || !$r['emps'])) continue;
            $out[]=$r;
        }
        return $out;
    }

    /**
     * Unresolved-viewers report. An employee is resolvable when any account that holds ticket.view_team has them in
     * scope(); this reuses scope() so the report cannot disagree with the access rule.
     */
    public static function report(int $page=1): array
    {
        EmployeeApproverAssignmentService::requireHr();
        $branchCovered=[];$areaCovered=[];$employeeCovered=[];
        $users=db()->query('SELECT DISTINCT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status="ACTIVE" AND r.code<>"SUPER_ADMIN"
            AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.code="ticket.view_team")')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($users as $uid) {
            $s=self::scope((int)$uid);if (!$s) continue;
            foreach ($s['branch_ids'] as $id) $branchCovered[$id]=true;
            foreach ($s['area_ids'] as $id) $areaCovered[$id]=true;
            foreach ($s['manager_employee_ids'] as $id) $employeeCovered[$id]=true;
        }
        $q=db()->query('SELECT e.id,e.employee_no,e.first_name,e.last_name,e.user_id,b.id branch_id,b.name branch_name,b.area_id,(b.site_type="HEAD_OFFICE" OR COALESCE(b.code,"")="HEAD_OFFICE") is_ho,bu.company_group
            FROM employees e LEFT JOIN branches b ON b.id=e.branch_id LEFT JOIN business_units bu ON bu.id=e.business_unit_id WHERE e.status IN '.self::ACTIVE.' ORDER BY e.last_name,e.first_name,e.id');
        $employees=$q->fetchAll();$missing=[];$count=['employees'=>count($employees),'with_viewer'=>0,'retail_missing'=>0,'ho_missing'=>0];
        foreach ($employees as $e) {
            $ok=isset($employeeCovered[(int)$e['id']]) || (!$e['is_ho'] && $e['branch_id'] && (isset($branchCovered[(int)$e['branch_id']]) || ($e['area_id'] && isset($areaCovered[(int)$e['area_id']]))));
            if ($ok) {$count['with_viewer']++;continue;}
            $e['is_ho']?$count['ho_missing']++:$count['retail_missing']++;$missing[]=$e;
        }
        $problemBranches=self::branchRows(0,0,true);
        $adls=self::adlsWithCoverage();
        $noSetup=array_values(array_filter($adls,static fn($a)=>!$a['user_id'] || !$a['areas'] || !$a['can_view']));
        $adlInHo=array_values(array_filter($adls,static fn($a)=>($a['site_type']??'')==='HEAD_OFFICE' || !$a['branch_name']));
        $noArea=db()->query('SELECT b.id,b.code,b.name,(SELECT COUNT(*) FROM employees e WHERE e.branch_id=b.id AND e.status IN '.self::ACTIVE.') emps FROM branches b
            WHERE b.active=1 AND b.site_type<>"HEAD_OFFICE" AND COALESCE(b.code,"")<>"HEAD_OFFICE" AND b.area_id IS NULL ORDER BY b.name')->fetchAll();
        $page=max(1,$page);
        $withoutSc=array_values(array_filter($problemBranches,static fn($b)=>in_array('NO_SC',array_column($b['problems'],0),true)));
        $scNoAccount=array_values(array_filter($problemBranches,static fn($b)=>in_array('SC_NO_ACCOUNT',array_column($b['problems'],0),true)));
        $multiSc=array_values(array_filter($problemBranches,static fn($b)=>in_array('MULTI_SC',array_column($b['problems'],0),true)));
        return ['counts'=>$count,'missing'=>array_slice($missing,($page-1)*50,50),'missing_total'=>count($missing),'page'=>$page,'pages'=>max(1,(int)ceil(count($missing)/50)),
            'branches_with_problems'=>$problemBranches,'branches_without_sc'=>$withoutSc,'branches_sc_no_account'=>$scNoAccount,'branches_multi_sc'=>$multiSc,
            'adls'=>$adls,'adls_unready'=>$noSetup,'adls_in_head_office'=>$adlInHo,'branches_without_area'=>$noArea];
    }
}
