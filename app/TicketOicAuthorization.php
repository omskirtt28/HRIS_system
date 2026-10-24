<?php
declare(strict_types=1);
require_once __DIR__.'/EmployeeApproverAssignmentService.php';
require_once __DIR__.'/EmployeeDirectoryPlacement.php';
require_once __DIR__.'/TicketViewerService.php';

/**
 * OIC Authorization: an ADL or Sales Captain asks HR to let one employee of their branch/area act as OIC for a date range.
 * HR_ONLY routing; HR marks it Served and then creates or links the OIC user by hand in Users (role OIC). Nothing here
 * grants a role. What this class does:
 *  - validates who may nominate whom (own branch for a Sales Captain, own areas for an ADL, anyone for HR) and blocks
 *    a nominee who already holds a lead or higher role;
 *  - records the nominee's role at the moment HR serves the request (oic_authorizations.previous_role_id);
 *  - the OIC viewer rule in TicketViewerService reads this table by date, so an expired OIC loses viewer access at once;
 *  - expireDue() returns the account to its previous role after the end date. Idempotent, audited, never touches a
 *    Super Admin, HR, admin or client account.
 */
final class TicketOicAuthorization
{
    private const ACTIVE='("ACTIVE","PROBATIONARY","ON_LEAVE")';
    public const TYPE='OIC_AUTHORIZATION';

    public static function ready(): bool
    {
        return FoundationRepository::tableExists('oic_authorizations');
    }

    // ---------------------------------------------------------------- who may nominate whom

    /** HR/Admin portal accounts that may file for others. An ADL role that also holds ticket.file_for_others stays scoped to its own areas. */
    public static function filesForAll(): bool
    {
        return Auth::check() && in_array(Auth::user()['role_portal']??'',['hr','admin'],true) && Auth::can('ticket.file_for_others');
    }

    public static function mayFile(): bool
    {
        return self::ready() && (Auth::can('ticket.file.oic_authorization') || self::filesForAll());
    }

    /** The filer's own active employee rows with the two facts that grant the right to nominate. */
    private static function filerRows(int $uid): array
    {
        $q=db()->prepare('SELECT e.id,e.branch_id,e.directory_area_ids,UPPER(TRIM(COALESCE(p.name,""))) pos,'.EmployeeDirectoryPlacement::isAdlSql('e','p').' is_adl
            FROM employees e LEFT JOIN positions p ON p.id=e.position_id WHERE e.user_id=? AND e.status IN '.self::ACTIVE);
        $q->execute([$uid]);return $q->fetchAll();
    }

    /** @return array{all:bool,branch_ids:int[],area_ids:int[],own:int[]} what the signed-in filer may nominate from */
    public static function filerScope(): array
    {
        $uid=TicketRepository::uid();$rows=self::filerRows($uid);
        $scope=['all'=>self::filesForAll(),'branch_ids'=>[],'area_ids'=>[],'own'=>array_map(static fn($r)=>(int)$r['id'],$rows)];
        foreach ($rows as $r) {
            if (in_array($r['pos'],TicketViewerService::SC_POSITIONS,true) && $r['branch_id']) $scope['branch_ids'][]=(int)$r['branch_id'];
            if (!empty($r['is_adl'])) $scope['area_ids']=array_merge($scope['area_ids'],EmployeeDirectoryPlacement::areaIds($r['directory_area_ids']));
        }
        $scope['area_ids']=array_values(array_unique($scope['area_ids']));$scope['branch_ids']=array_values(array_unique($scope['branch_ids']));
        return $scope;
    }

    private static function retailSql(): string
    {
        return 'b.active=1 AND b.site_type<>"HEAD_OFFICE" AND COALESCE(b.code,"")<>"HEAD_OFFICE"';
    }

    /** id => "no · name" for the nominee list. HR: every retail employee; Sales Captain: own branch; ADL: own areas. */
    public static function employeeChoices(): array
    {
        $s=self::filerScope();$where=[];$params=[];
        if (!$s['all']) {
            if ($s['branch_ids']) {$where[]='e.branch_id IN ('.implode(',',array_fill(0,count($s['branch_ids']),'?')).')';$params=array_merge($params,$s['branch_ids']);}
            if ($s['area_ids']) {$where[]='b.area_id IN ('.implode(',',array_fill(0,count($s['area_ids']),'?')).')';$params=array_merge($params,$s['area_ids']);}
            if (!$where) return [];
        }
        $sql='SELECT e.id,e.employee_no,e.first_name,e.last_name FROM employees e JOIN branches b ON b.id=e.branch_id WHERE e.status IN '.self::ACTIVE.' AND '.self::retailSql()
            .($s['own']?' AND e.id NOT IN ('.implode(',',array_fill(0,count($s['own']),'?')).')':'').($where?' AND ('.implode(' OR ',$where).')':'').' ORDER BY e.last_name,e.first_name LIMIT 2000';
        $q=db()->prepare($sql);$q->execute(array_merge($s['own'],$params));$out=[];
        foreach ($q->fetchAll() as $e) $out[(int)$e['id']]=$e['employee_no'].' · '.$e['first_name'].' '.$e['last_name'];
        return $out;
    }

    /** Why this employee cannot be nominated, or null. A nominee must be a plain Employee (or have no account yet). */
    public static function ineligibleReason(int $employeeId): ?string
    {
        $q=db()->prepare('SELECT UPPER(TRIM(COALESCE(p.name,""))) pos,r.code role_code,r.name role_name,'.EmployeeDirectoryPlacement::isAdlSql('e','p').' is_adl
            FROM employees e LEFT JOIN positions p ON p.id=e.position_id LEFT JOIN users u ON u.id=e.user_id LEFT JOIN roles r ON r.id=u.role_id WHERE e.id=?');
        $q->execute([$employeeId]);$e=$q->fetch();if (!$e) return 'Employee not found.';
        if (in_array($e['pos'],TicketViewerService::SC_POSITIONS,true)) return 'This employee is a Sales Captain; they already hold a higher role.';
        if (in_array($e['pos'],TicketViewerService::OIC_POSITIONS,true)) return 'This employee is already an OIC by position.';
        if (!empty($e['is_adl'])) return 'This employee is an ADL; they already hold a higher role.';
        if ($e['role_code']!==null && $e['role_code']!=='EMPLOYEE') return 'This employee\'s account already has the "'.$e['role_name'].'" role. Only a plain Employee account can be authorized as OIC.';
        return null;
    }

    private static function ymd(mixed $value,string $label): string
    {
        $d=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
        if (!$d || $d->format('Y-m-d')!==$value) throw new RuntimeException('Enter a valid '.$label.'.');
        return $value;
    }

    /** Called from TicketTypes::validate with the nominee as $employee (TicketService::requireFiler returns them). */
    public static function validate(array $out,array $employee): array
    {
        if (!self::ready()) throw new RuntimeException('OIC Authorization needs Slice-15 migration 013.');
        if ((int)$out['employee']!==(int)$employee['id']) throw new RuntimeException('Choose the authorized nominee.');
        $scope=self::filerScope();
        if (in_array((int)$employee['id'],$scope['own'],true)) throw new RuntimeException('You cannot authorize yourself as OIC.');
        if (!in_array($employee['status'],['ACTIVE','PROBATIONARY','ON_LEAVE'],true)) throw new RuntimeException('Choose an active employee.');
        $q=db()->prepare('SELECT b.id,b.area_id FROM branches b WHERE b.id=? AND '.self::retailSql());$q->execute([(int)($employee['branch_id']??0)]);$branch=$q->fetch();
        if (!$branch) throw new RuntimeException('OIC Authorization applies to retail branches only. Head Office employees are not covered.');
        $inScope=$scope['all'] || in_array((int)$branch['id'],$scope['branch_ids'],true) || ($branch['area_id'] && in_array((int)$branch['area_id'],$scope['area_ids'],true));
        if (!$inScope) throw new RuntimeException('Choose an employee in your own branch or area.');
        if ((int)$out['branch']!==(int)$branch['id']) throw new RuntimeException('The branch must be the employee\'s current branch.');
        $start=self::ymd($out['start_date']??'','start date');$end=self::ymd($out['end_date']??'','end date');
        if ($end<$start) throw new RuntimeException('The end date must be on or after the start date.');
        if ($end<date('Y-m-d')) throw new RuntimeException('The end date cannot be in the past.');
        if ($reason=self::ineligibleReason((int)$employee['id'])) throw new RuntimeException($reason);
        return $out;
    }

    public static function save(int $ticketId,array $p): void
    {
        $q=db()->prepare('SELECT a.ticket_id FROM oic_authorizations a JOIN tickets t ON t.id=a.ticket_id
            WHERE a.ticket_id<>? AND a.employee_id=? AND a.start_date<=? AND a.end_date>=?
            AND ((a.status="REQUESTED" AND t.status IN ("SUBMITTED","PENDING_APPROVAL","PENDING_HR","IN_PROGRESS","RETURNED")) OR (a.status="APPROVED" AND t.status="COMPLETED")) LIMIT 1');
        $q->execute([$ticketId,(int)$p['employee'],$p['end_date'],$p['start_date']]);
        if ($q->fetchColumn()) throw new RuntimeException('This employee already has an OIC authorization for overlapping dates.');
        db()->prepare('INSERT INTO oic_authorizations(ticket_id,employee_id,branch_id,start_date,end_date) VALUES(?,?,?,?,?)
            ON DUPLICATE KEY UPDATE branch_id=VALUES(branch_id),start_date=VALUES(start_date),end_date=VALUES(end_date)')
            ->execute([$ticketId,(int)$p['employee'],(int)$p['branch'],$p['start_date'],$p['end_date']]);
    }

    // ---------------------------------------------------------------- HR serves the request

    /** HR marked the request Served: re-check, then remember the nominee's role so expiry can restore it. */
    public static function complete(array $ticket,array $data=[]): bool
    {
        $q=db()->prepare('SELECT * FROM oic_authorizations WHERE ticket_id=? FOR UPDATE');$q->execute([$ticket['id']]);$a=$q->fetch();
        if (!$a) throw new RuntimeException('This OIC Authorization has no saved dates. Return it to the filer.');
        if ($a['status']==='APPROVED') return true;
        if ($a['status']!=='REQUESTED') throw new RuntimeException('This OIC Authorization can no longer be served.');
        if ($a['end_date']<date('Y-m-d')) throw new RuntimeException('The authorization period has already ended. Return or reject this request.');
        $q=db()->prepare('SELECT id,branch_id,status,user_id FROM employees WHERE id=? FOR UPDATE');$q->execute([$a['employee_id']]);$e=$q->fetch();
        if (!$e || !in_array($e['status'],['ACTIVE','PROBATIONARY','ON_LEAVE'],true)) throw new RuntimeException('The nominated employee is no longer active.');
        if ((int)$e['branch_id']!==(int)$a['branch_id']) throw new RuntimeException('The nominee moved branches. Return this request to the filer.');
        if ($reason=self::ineligibleReason((int)$e['id'])) throw new RuntimeException($reason);
        $previousUser=null;$previousRole=null;
        if ($e['user_id']) {
            $q=db()->prepare('SELECT u.role_id FROM users u WHERE u.id=? AND u.status="ACTIVE"');$q->execute([(int)$e['user_id']]);$role=$q->fetchColumn();
            if ($role!==false) {$previousUser=(int)$e['user_id'];$previousRole=(int)$role;}
        }
        $uid=TicketRepository::uid();
        db()->prepare('UPDATE oic_authorizations SET status="APPROVED",approved_by=?,approved_at=NOW(),previous_user_id=?,previous_role_id=? WHERE ticket_id=?')->execute([$uid,$previousUser,$previousRole,$ticket['id']]);
        $instruction=['employee_id'=>(int)$a['employee_id'],'branch_id'=>(int)$a['branch_id'],'start_date'=>$a['start_date'],'end_date'=>$a['end_date'],'previous_role_id'=>$previousRole,'manual_step'=>'HR creates or links the OIC user in Users (role OIC)'];
        db()->prepare('INSERT INTO ticket_effects(ticket_id,effect_type,instruction_json,created_by) VALUES(?,"OIC_AUTHORIZATION",?,?) ON DUPLICATE KEY UPDATE ticket_id=ticket_id')->execute([$ticket['id'],json_encode($instruction,JSON_THROW_ON_ERROR),$uid]);
        audit('HR Requests','OIC_AUTHORIZATION_SERVED','employee',(int)$a['employee_id'],$instruction+['ticket_id'=>(int)$ticket['id']]);
        return true;
    }

    /** Small panel on the ticket detail page: dates, state, previous role and the manual next step for HR. */
    public static function panel(array $t): void
    {
        if (!self::ready()) return;
        $q=db()->prepare('SELECT a.*,r.name previous_role_name,e.user_id FROM oic_authorizations a JOIN employees e ON e.id=a.employee_id LEFT JOIN roles r ON r.id=a.previous_role_id WHERE a.ticket_id=?');$q->execute([$t['id']]);$a=$q->fetch();
        if (!$a) return;
        $label=['REQUESTED'=>'Requested','APPROVED'=>'Served','EXPIRED'=>'Expired'][$a['status']]??$a['status'];
        echo '<section class="panel ticket-panel" data-oic-panel><div class="panel-body"><h2>OIC Authorization</h2><p><strong>'.e($label).'</strong> · '.e($a['start_date']).' to '.e($a['end_date']).'</p>';
        if ($a['status']==='APPROVED') {
            echo '<p>Role before this authorization: '.e($a['previous_role_name']?:'none recorded (a new account returns to Employee)').'. After '.e($a['end_date']).' the account returns to that role automatically.</p>';
            if (TicketRepository::isHr()) echo '<p>HR next step: '.($a['user_id']?'give the linked user the OIC role':'create or link the OIC user').' in <a href="'.e(url('admin-users')).'">Users</a>. Until you do, the employee has no OIC access.</p>';
        } elseif ($a['status']==='EXPIRED') echo '<p>Expired '.e((string)$a['expired_at']).'. OIC access has ended.</p>';
        echo '</div></section>';
    }

    // ---------------------------------------------------------------- expiry

    /** Cheap guard so the page-load hook costs one indexed lookup when nothing is due. */
    public static function dueExists(): bool
    {
        return self::ready() && (bool)db()->query('SELECT 1 FROM oic_authorizations WHERE status="APPROVED" AND end_date<CURDATE() LIMIT 1')->fetchColumn();
    }

    /** Page-load / scheduled-task trigger. Never throws into the page. */
    public static function runDue(): ?array
    {
        try {return self::dueExists()?self::expireDue():null;}
        catch (Throwable $e) {error_log('OIC expiry failed: '.$e->getMessage());return null;}
    }

    /**
     * Ends every APPROVED authorization whose end date has passed and returns its account to the role it had when HR
     * served the request. Safe to run any number of times: each row is claimed under a lock and flips APPROVED -> EXPIRED once.
     * @return array{expired:int,restored:int}
     */
    public static function expireDue(): array
    {
        if (!self::ready()) return ['expired'=>0,'restored'=>0];
        $ids=db()->query('SELECT ticket_id FROM oic_authorizations WHERE status="APPROVED" AND end_date<CURDATE() ORDER BY ticket_id LIMIT 200')->fetchAll(PDO::FETCH_COLUMN);
        $done=['expired'=>0,'restored'=>0];
        foreach ($ids as $id) {
            db()->beginTransaction();
            try {
                $q=db()->prepare('SELECT * FROM oic_authorizations WHERE ticket_id=? AND status="APPROVED" AND end_date<CURDATE() FOR UPDATE');$q->execute([$id]);$a=$q->fetch();
                if (!$a) {db()->commit();continue;}
                $restored=self::restoreRole($a);
                db()->prepare('UPDATE oic_authorizations SET status="EXPIRED",expired_at=NOW(),restored_role_id=? WHERE ticket_id=?')->execute([$restored['role_id'],$id]);
                audit('HR Requests','OIC_AUTHORIZATION_EXPIRED','employee',(int)$a['employee_id'],['ticket_id'=>(int)$id,'end_date'=>$a['end_date'],'user_id'=>$restored['user_id'],'from_role_id'=>$restored['from'],'restored_role_id'=>$restored['role_id'],'note'=>$restored['note']]);
                db()->commit();$done['expired']++;if ($restored['role_id']) $done['restored']++;
            } catch (Throwable $e) {if (db()->inTransaction()) db()->rollBack();error_log('OIC expiry skipped one row: '.$e->getMessage());}
        }
        return $done;
    }

    /** @return array{role_id:?int,user_id:?int,from:?int,note:string} changes the account only if it is still on the OIC role */
    private static function restoreRole(array $a): array
    {
        $none=static fn(string $note,?int $user=null)=>['role_id'=>null,'user_id'=>$user,'from'=>null,'note'=>$note];
        $q=db()->prepare('SELECT e.user_id,UPPER(TRIM(COALESCE(p.name,""))) pos FROM employees e LEFT JOIN positions p ON p.id=e.position_id WHERE e.id=?');$q->execute([$a['employee_id']]);$e=$q->fetch();
        if (!$e || !$e['user_id']) return $none('no linked account');
        if (in_array($e['pos'],TicketViewerService::OIC_POSITIONS,true)) return $none('permanent OIC by position; role kept',(int)$e['user_id']);
        // Another authorization still running for the same employee keeps the OIC role.
        $q=db()->prepare('SELECT 1 FROM oic_authorizations WHERE employee_id=? AND ticket_id<>? AND status="APPROVED" AND end_date>=CURDATE() LIMIT 1');$q->execute([$a['employee_id'],$a['ticket_id']]);
        if ($q->fetchColumn()) return $none('another OIC authorization is still running; role kept',(int)$e['user_id']);
        $q=db()->prepare('SELECT u.id,u.role_id,r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? FOR UPDATE');$q->execute([(int)$e['user_id']]);$u=$q->fetch();
        if (!$u) return $none('account missing');
        if ($u['code']!=='OIC') return $none('account is not on the OIC role; left unchanged',(int)$u['id']);
        // Restore the recorded role only for the same account and only if it is a plain employee-portal role; else Employee.
        $target=null;
        if ($a['previous_role_id'] && (int)$a['previous_user_id']===(int)$u['id']) {
            $q=db()->prepare('SELECT id FROM roles WHERE id=? AND portal="employee" AND code NOT IN ("SUPER_ADMIN","OIC")');$q->execute([$a['previous_role_id']]);$target=$q->fetchColumn()?:null;
        }
        $note=$target?'previous role restored':'no usable previous role; returned to Employee';
        if (!$target) {$q=db()->query('SELECT id FROM roles WHERE code="EMPLOYEE" AND portal="employee"');$target=$q->fetchColumn()?:null;}
        if (!$target) throw new RuntimeException('Employee role not found; OIC role not removed from user '.$u['id'].'.');
        db()->prepare('UPDATE users SET role_id=? WHERE id=?')->execute([(int)$target,(int)$u['id']]);
        // The role lives in the session, so sign the account out everywhere (same as Users > change role).
        if (FoundationRepository::tableExists('user_sessions')) db()->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL')->execute([(int)$u['id']]);
        return ['role_id'=>(int)$target,'user_id'=>(int)$u['id'],'from'=>(int)$u['role_id'],'note'=>$note];
    }
}
