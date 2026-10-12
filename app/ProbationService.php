<?php
declare(strict_types=1);
require_once __DIR__.'/EmployeeStatusPolicy.php';
require_once __DIR__.'/NotificationService.php';

/**
 * Fixed-term reminders: Probationary (6 months) and Seasonal (3 months), counted from the hire date.
 * Stage 1 (one month before the end): the ADL (retail) / Manager (Head Office) the employee is under, HR, and a heads-up to the employee.
 * Stage 2 (end date reached): HR, the employee and the supervisor.
 */
final class ProbationService
{
    private const ACTIVE='"ACTIVE","PROBATIONARY","ON_LEAVE"';
    private const ADL_TITLES='"ADL","SR ADL","JR ADL","SR. ADL","JR. ADL","AREA DEVELOPMENT LEADER","SENIOR AREA DEVELOPMENT LEADER","JUNIOR AREA DEVELOPMENT LEADER"';
    private const SELECT='SELECT e.*,t.code type_code,p.name position_name,d.name department_name,b.name branch_name FROM employees e
        JOIN employment_types t ON t.id=e.employment_type_id AND t.code IN ("PROBATIONARY","RETURNEE_PROBATIONARY","SEASONAL")
        LEFT JOIN positions p ON p.id=e.position_id LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN branches b ON b.id=e.branch_id
        WHERE e.status IN ("PROBATIONARY","ACTIVE") AND e.probation_end_date IS NOT NULL AND e.probation_start_date IS NOT NULL';

    public static function ready(): bool
    {
        return NotificationService::ready() && FoundationRepository::columnExists('employees','probation_end_date');
    }

    /** Supervisors for an employee: Reporting To, else the area ADL (retail), else the department Manager (Head Office). */
    public static function supervisors(array $e): array
    {
        $id=(int)$e['id'];
        if(!empty($e['manager_employee_id'])) {
            $q=db()->prepare('SELECT * FROM employees WHERE id=? AND status IN ('.self::ACTIVE.')'); $q->execute([(int)$e['manager_employee_id']]);
            if($row=$q->fetch()) return ['label'=>'Reporting To','people'=>[$row]];
        }
        $b=null;
        if(!empty($e['branch_id'])) { $q=db()->prepare('SELECT id,area_id,site_type FROM branches WHERE id=?'); $q->execute([(int)$e['branch_id']]); $b=$q->fetch()?:null; }
        if($b && $b['site_type']!=='HEAD_OFFICE' && $b['area_id']) {
            // Assigned areas (Employee 201) win; an ADL with none set covers the area of their own branch.
            $q=db()->prepare('SELECT e.* FROM employees e JOIN positions p ON p.id=e.position_id LEFT JOIN branches eb ON eb.id=e.branch_id
                WHERE e.status IN ('.self::ACTIVE.') AND e.id<>? AND (e.directory_role="ADL" OR UPPER(p.name) IN ('.self::ADL_TITLES.'))
                AND (LOCATE(CONCAT(",",?,","),COALESCE(e.directory_area_ids,""))>0 OR (COALESCE(e.directory_area_ids,"")="" AND eb.area_id=?)) ORDER BY e.last_name');
            $q->execute([$id,(int)$b['area_id'],(int)$b['area_id']]);
            if($rows=$q->fetchAll()) return ['label'=>'ADL','people'=>$rows];
        } elseif(!empty($e['department_id'])) {
            $q=db()->prepare('SELECT e.* FROM employees e LEFT JOIN positions p ON p.id=e.position_id
                WHERE e.status IN ('.self::ACTIVE.') AND e.id<>? AND e.department_id=? AND (e.directory_role="DEPARTMENT_MANAGER" OR UPPER(COALESCE(p.name,"")) LIKE "%MANAGER%") ORDER BY e.last_name');
            $q->execute([$id,(int)$e['department_id']]);
            if($rows=$q->fetchAll()) return ['label'=>'Manager','people'=>$rows];
        }
        return ['label'=>'ADL / Manager','people'=>[]];
    }

    private static function hrRecipients(): array
    {
        return db()->query('SELECT u.id user_id,u.email,u.full_name FROM users u JOIN roles r ON r.id=u.role_id
            WHERE u.status="ACTIVE" AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.code="employees.manage")')->fetchAll();
    }

    private static function emailOf(array $e): ?string
    {
        foreach(['company_email','personal_email'] as $f) if(!empty($e[$f]) && filter_var($e[$f],FILTER_VALIDATE_EMAIL)) return (string)$e[$f];
        return null;
    }

    private static function typeCode(array $e): ?string
    {
        return $e['type_code'] ?? EmployeeStatusPolicy::code(isset($e['employment_type_id'])?(int)$e['employment_type_id']:null);
    }

    /** 0 = nothing due, 1 = one month before the end, 2 = end date reached. */
    public static function stage(array $e,DateTimeImmutable $today): int
    {
        if(empty($e['probation_start_date']) || empty($e['probation_end_date'])) return 0;
        $day=$today->format('Y-m-d');
        if($day>=$e['probation_end_date']) return 2;
        $months=EmployeeStatusPolicy::termMonths(self::typeCode($e));
        return $day>=EmployeeStatusPolicy::addMonths((string)$e['probation_start_date'],$months-1) ? 1 : 0;
    }

    /** Creates the due reminders (once each), then emails them. Returns counts. */
    public static function run(?DateTimeImmutable $today=null,bool $deliver=true): array
    {
        $today??=new DateTimeImmutable('today');
        $result=['checked'=>0,'created'=>0,'emailed'=>0,'email_failed'=>0];
        if(!self::ready()) return $result;
        $rows=db()->query(self::SELECT)->fetchAll();
        $hr=null;
        foreach($rows as $e) {
            $result['checked']++;
            $stage=self::stage($e,$today); if($stage===0) continue;
            $hr??=self::hrRecipients();
            $code=self::typeCode($e); $seasonal=EmployeeStatusPolicy::isSeasonal($code); $label=EmployeeStatusPolicy::termLabel($code); $lower=strtolower($label);
            $sup=self::supervisors($e); $name=EmployeeRepository::fullName($e);
            $end=date('M j, Y',strtotime((string)$e['probation_end_date'])); $start=date('M j, Y',strtotime((string)$e['probation_start_date']));
            $where=trim(($e['position_name']?:'No position').' · '.($e['branch_name']?:'No branch').($e['department_name']?' · '.$e['department_name']:''));
            $days=(int)$today->diff(new DateTimeImmutable((string)$e['probation_end_date']))->format('%r%a');
            $supNames=$sup['people']?implode(', ',array_map(static fn($p)=>EmployeeRepository::fullName($p),$sup['people'])):'no '.$sup['label'].' found — please assign one';
            $kind=$seasonal?($stage===1?'SEASONAL_2M':'SEASONAL_END'):($stage===1?'PROBATION_5M':'PROBATION_END'); $key=$kind.':'.(int)$e['id'].':'.$e['probation_end_date'].':';
            $make=static function(string $recipientKey,array $to,string $title,string $body,?string $link) use ($e,$kind,$key,&$result): void {
                if(NotificationService::create($to+['subject_employee_id'=>(int)$e['id'],'kind'=>$kind,'title'=>$title,'body'=>$body,'link'=>$link,'due_date'=>$e['probation_end_date'],'dedupe_key'=>$key.$recipientKey])) $result['created']++;
            };
            if($stage===1) {
                $hrTitle=$label.' ends '.$end.': '.$name; $hrBody=$name.' ('.$where.') started '.$lower.' on '.$start.'. It ends on '.$end.' ('.max(0,$days).' days left). '.$sup['label'].': '.$supNames.'.';
                $supTitle=$label.' ending soon: '.$name; $supBody=$name.' ('.$where.') started '.$lower.' on '.$start.'. It ends on '.$end.' ('.max(0,$days).' days left). Please evaluate and send your recommendation to HR.';
                $selfTitle='Your '.$lower.' ends on '.$end; $selfBody='Your '.$lower.' (started '.$start.') ends on '.$end.'. Your '.$sup['label'].' and HR will review it with you.';
            } else {
                $hrTitle=$label.' ended: '.$name; $hrBody=$name.' ('.$where.') — '.$lower.' ended on '.$end.'. Please follow up with the '.$sup['label'].' ('.$supNames.') and update the Employee Status ('.($seasonal?'extend or separate':'Regular, extend or separate').').';
                $supTitle=$label.' ended: '.$name; $supBody='The '.$lower.' of '.$name.' ('.$where.') ended on '.$end.'. Please send your evaluation to HR.';
                $selfTitle='Your '.$lower.' ended on '.$end; $selfBody='Your '.$lower.' ended on '.$end.'. HR will update you about your employment status.';
            }
            $sent=[];
            foreach($hr as $h) { $sent['u'.$h['user_id']]=1; $make('u'.$h['user_id'],['recipient_user_id'=>(int)$h['user_id'],'email_to'=>filter_var($h['email'],FILTER_VALIDATE_EMAIL)?$h['email']:null],$hrTitle,$hrBody,url('hr-probation')); }
            foreach($sup['people'] as $p) {
                $rk=!empty($p['user_id'])?'u'.$p['user_id']:'e'.$p['id']; if(isset($sent[$rk])) continue; $sent[$rk]=1;
                $make($rk,['recipient_user_id'=>!empty($p['user_id'])?(int)$p['user_id']:null,'recipient_employee_id'=>(int)$p['id'],'email_to'=>self::emailOf($p)],$supTitle,$supBody,null);
            }
            $rk=!empty($e['user_id'])?'u'.$e['user_id']:'e'.$e['id'];
            if(!isset($sent[$rk])) $make($rk,['recipient_user_id'=>!empty($e['user_id'])?(int)$e['user_id']:null,'recipient_employee_id'=>(int)$e['id'],'email_to'=>self::emailOf($e)],$selfTitle,$selfBody,null);
        }
        if($deliver) [$result['emailed'],$result['email_failed']]=NotificationService::deliverPending();
        return $result;
    }

    /** Runs at most once a day (called lazily from page loads and by scripts/run_notifications.php). */
    public static function runDaily(): ?array
    {
        if(!self::ready() || !FoundationRepository::tableExists('job_runs')) return null;
        $today=date('Y-m-d');
        $q=db()->prepare('SELECT last_run_on FROM job_runs WHERE job="probation"'); $q->execute();
        if($q->fetchColumn()===$today) return null;
        // Claim the day first so concurrent requests do not repeat the work.
        db()->prepare('INSERT INTO job_runs(job,last_run_on) VALUES("probation",?) ON DUPLICATE KEY UPDATE last_run_on=VALUES(last_run_on)')->execute([$today]);
        return self::run();
    }

    /** Probation Watch rows (probation and Seasonal) with their supervisor and reminder status. */
    public static function watch(string $filter=''): array
    {
        if(!self::ready()) return [];
        $today=new DateTimeImmutable('today');
        $rows=db()->query(self::SELECT.' ORDER BY e.probation_end_date,e.last_name')->fetchAll();
        $out=[];
        foreach($rows as $e) {
            $days=(int)$today->diff(new DateTimeImmutable((string)$e['probation_end_date']))->format('%r%a');
            if($filter==='overdue' && $days>=0) continue;
            if($filter==='soon' && ($days<0 || $days>30)) continue;
            $sup=self::supervisors($e);
            $q=db()->prepare('SELECT kind,COUNT(*) total,SUM(emailed_at IS NOT NULL) emailed,SUM(email_error IS NOT NULL AND emailed_at IS NULL) failed FROM notifications WHERE subject_employee_id=? AND due_date=? GROUP BY kind');
            $q->execute([(int)$e['id'],$e['probation_end_date']]); $notes=[]; foreach($q->fetchAll() as $n) $notes[$n['kind']]=$n;
            $code=self::typeCode($e);
            $out[]=$e+['days_left'=>$days,'supervisor'=>$sup,'notices'=>$notes,'stage'=>self::stage($e,$today),'term_label'=>EmployeeStatusPolicy::termLabel($code),'seasonal'=>EmployeeStatusPolicy::isSeasonal($code)];
        }
        return $out;
    }
}
