<?php
declare(strict_types=1);
require_once __DIR__.'/TicketQueue.php';

/**
 * Numbers for the HR dashboard. Read-only; every figure comes from live tickets, employees and recruitment rows.
 * Request figures use the same definitions as the HR Request Queue (Pending = PENDING_HR, In progress = IN_PROGRESS,
 * Served = COMPLETED, Incident Reports excluded) so a KPI and the queue it opens agree.
 */
final class HrDashboard
{
    public const PERIODS=['this_week'=>'This week','last_week'=>'Last week','this_month'=>'This month','last_30'=>'Last 30 days'];
    public const COMPANIES=['ALL'=>'All companies','OC'=>'Ocampo\'s','SW'=>'SW'];
    /** Chart order and the statuses each colour stands for. */
    public const TYPE_ORDER=['ATM_REQUEST','ID_REQUEST','UNIFORM_REQUEST','PAYROLL_CONCERN','MANPOWER_REQUEST','NETSUITE_CONCERN','BRANCH_TRANSFER','OIC_AUTHORIZATION','COE_REQUEST'];
    private const OPEN_DUE_DAYS=30;
    private const EXCL='ty.code<>"INCIDENT_REPORT" AND t.status<>"DRAFT"';
    private const FILED='COALESCE(t.filed_at,t.created_at)';

    public static function params(array $in):array
    {
        return ['company'=>array_key_exists((string)($in['company']??''),self::COMPANIES)?(string)$in['company']:'ALL',
            'period'=>array_key_exists((string)($in['period']??''),self::PERIODS)?(string)$in['period']:'this_week'];
    }

    /** Dates are inclusive start, exclusive end (the day after the last day). The previous window has the same length. */
    public static function window(string $period,?DateTimeImmutable $today=null):array
    {
        $today=($today??new DateTimeImmutable('today'))->setTime(0,0);$tomorrow=$today->modify('+1 day');
        switch($period){
            case 'last_week':$start=$today->modify('monday this week')->modify('-7 days');$end=$start->modify('+7 days');$prevStart=$start->modify('-7 days');$prevEnd=$start;break;
            case 'this_month':$start=$today->modify('first day of this month');$end=$tomorrow;$prevStart=$start->modify('first day of previous month');
                $span=(int)$start->diff($end)->days;$prevEnd=min($prevStart->modify('+'.$span.' days'),$start);break;
            case 'last_30':$start=$today->modify('-29 days');$end=$tomorrow;$prevStart=$start->modify('-30 days');$prevEnd=$start;break;
            default:$start=$today->modify('monday this week');$end=$tomorrow;$prevStart=$start->modify('-7 days');$prevEnd=min($prevStart->modify('+'.(int)$start->diff($end)->days.' days'),$start);
        }
        return ['start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'last_day'=>$end->modify('-1 day')->format('Y-m-d'),'prev_start'=>$prevStart->format('Y-m-d'),'prev_end'=>$prevEnd->format('Y-m-d'),'live'=>$end>$today];
    }

    /** Percent-free change text: "+3 vs previous", "same as previous". */
    public static function delta(int $now,int $before):array
    {
        $d=$now-$before;
        return ['value'=>$d,'text'=>$d===0?'Same as previous period':($d>0?'+':'−').abs($d).' vs previous period'];
    }

    private static function one(string $sql,array $args=[]):int { $q=db()->prepare($sql);$q->execute($args);return (int)$q->fetchColumn(); }
    private static function rows(string $sql,array $args=[]):array { $q=db()->prepare($sql);$q->execute($args);return $q->fetchAll(); }

    public static function data(array $p,?DateTimeImmutable $today=null):array
    {
        $isHr=TicketRepository::isHr();
        $canIncident=$isHr;// ticket.sensitive.view no longer reaches incident cases (plan 2026-10-25-incident-report-module)
        $w=self::window($p['period'],$today);$group=$p['company']==='ALL'?'':$p['company'];
        $cw=$group!==''?' AND t.company_group_snapshot=?':'';$cp=$group!==''?[$group]:[];
        $from=' FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ';
        $d=['window'=>$w,'is_hr'=>$isHr,'can_incident'=>$canIncident,'params'=>$p,'tickets'=>null,'incident'=>null,'setup'=>null];
        $d['employees']=self::employees($group,$w);
        $d['recruitment']=self::recruitment($w);
        if($isHr){
            $by=self::rows('SELECT t.status,COUNT(*) n'.$from.self::EXCL.$cw.' GROUP BY t.status',$cp);$status=[];foreach($by as $r)$status[$r['status']]=(int)$r['n'];
            $snap=static function(string $when)use($from,$cw,$cp):array{
                $q=db()->prepare('SELECT s.st,COUNT(*) n FROM (SELECT (SELECT ev.to_status FROM ticket_events ev WHERE ev.ticket_id=t.id AND ev.created_at<? AND ev.to_status IS NOT NULL ORDER BY ev.id DESC LIMIT 1) st'.$from.self::EXCL.$cw.') s WHERE s.st IN ("PENDING_HR","IN_PROGRESS") GROUP BY s.st');
                $q->execute(array_merge([$when.' 00:00:00'],$cp));return array_map('intval',$q->fetchAll(PDO::FETCH_KEY_PAIR));
            };
            $zero=['PENDING_HR'=>0,'IN_PROGRESS'=>0];$then=array_replace($zero,$snap($w['prev_end']));$now=array_replace($zero,$w['live']?['PENDING_HR'=>(int)($status['PENDING_HR']??0),'IN_PROGRESS'=>(int)($status['IN_PROGRESS']??0)]:$snap($w['end']));
            $served=static fn(string $a,string $b)=>self::one('SELECT COUNT(*)'.$from.self::EXCL.' AND t.status="COMPLETED" AND t.closed_at>=? AND t.closed_at<?'.$cw,array_merge([$a.' 00:00:00',$b.' 00:00:00'],$cp));
            $age=array_fill(0,3,0);$today_s=($today??new DateTimeImmutable('today'))->format('Y-m-d');
            foreach(self::rows('SELECT CASE WHEN a<=2 THEN 0 WHEN a<=5 THEN 1 ELSE 2 END b,COUNT(*) n FROM (SELECT DATEDIFF(?,DATE('.self::FILED.')) a'.$from.self::EXCL.' AND t.status="PENDING_HR"'.$cw.') x GROUP BY b',array_merge([$today_s],$cp)) as $r)$age[(int)$r['b']]=(int)$r['n'];
            $oldest=self::rows('SELECT t.id,t.ticket_no,ty.name type_name,e.first_name,e.last_name,b.name branch_name,t.filed_at,t.created_at FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id JOIN employees e ON e.id=t.subject_employee_id JOIN branches b ON b.id=t.branch_id WHERE '.self::EXCL.' AND t.status="PENDING_HR"'.$cw.' ORDER BY '.self::FILED.' ASC,t.id ASC LIMIT 5',$cp);
            foreach($oldest as &$o){$o['age_days']=TicketQueue::ageDays($o+['status'=>'PENDING_HR'])??0;$o['age_tone']=TicketQueue::ageTone($o['age_days']);}unset($o);
            $names=db()->query('SELECT code,name FROM ticket_types WHERE active=1')->fetchAll(PDO::FETCH_KEY_PAIR);$types=[];
            foreach(self::TYPE_ORDER as $code)if(isset($names[$code]))$types[$code]=['name'=>$names[$code],'pending'=>0,'progress'=>0,'served'=>0,'cancelled'=>0,'other'=>0,'total'=>0];
            foreach(self::rows('SELECT ty.code,t.status,COUNT(*) n'.$from.self::EXCL.$cw.' AND '.self::FILED.'>=? AND '.self::FILED.'<? GROUP BY ty.code,t.status',array_merge($cp,[$w['start'].' 00:00:00',$w['end'].' 00:00:00'])) as $r){
                if(!isset($types[$r['code']]))continue;$bucket=match($r['status']){'PENDING_HR'=>'pending','IN_PROGRESS'=>'progress','COMPLETED'=>'served','CANCELLED'=>'cancelled',default=>'other'};
                $types[$r['code']][$bucket]+=(int)$r['n'];$types[$r['code']]['total']+=(int)$r['n'];
            }
            $d['tickets']=['pending'=>$now['PENDING_HR'],'pending_before'=>(int)($then['PENDING_HR']??0),'progress'=>$now['IN_PROGRESS'],'progress_before'=>(int)($then['IN_PROGRESS']??0),
                'served'=>$served($w['start'],$w['end']),'served_before'=>$served($w['prev_start'],$w['prev_end']),'age'=>$age,'oldest'=>$oldest,'oldest_days'=>$oldest?$oldest[0]['age_days']:0,'types'=>$types,
                'filed'=>self::one('SELECT COUNT(*)'.$from.self::EXCL.$cw.' AND '.self::FILED.'>=? AND '.self::FILED.'<?',array_merge($cp,[$w['start'].' 00:00:00',$w['end'].' 00:00:00']))];
            try{EmployeeApproverAssignmentService::requireHr();$d['setup']=self::setup();}catch(RuntimeException){$d['setup']=null;}
        }
        if($canIncident){
            $readable=TicketIncident::readClause(TicketRepository::uid(),true);// titles only for cases this reviewer may open; the counts stay counts (BR-744)
            $inc=' FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code="INCIDENT_REPORT" AND t.status<>"DRAFT"'.$cw;
            // "Open" is TicketIncident::OPEN_QUEUE and the same readClause as the queue, so this number equals the Incident Cases Open tab (all companies).
            $d['incident']=['open'=>self::one('SELECT COUNT(*)'.$inc.' AND t.status '.TicketIncident::openSql().' AND '.$readable[0],array_merge($cp,$readable[1])),
                'open_link'=>['page'=>TicketIncident::QUEUE_PAGE,'params'=>['status'=>TicketIncident::QUEUE_DEFAULT_TAB]],// views: url($l['page'],$l['params'])
                'new'=>self::one('SELECT COUNT(*)'.$inc.' AND '.self::FILED.'>=? AND '.self::FILED.'<?',array_merge($cp,[$w['start'].' 00:00:00',$w['end'].' 00:00:00'])),
                'new_before'=>self::one('SELECT COUNT(*)'.$inc.' AND '.self::FILED.'>=? AND '.self::FILED.'<?',array_merge($cp,[$w['prev_start'].' 00:00:00',$w['prev_end'].' 00:00:00'])),
                'latest'=>self::rows('SELECT t.id,t.ticket_no,t.status,'.self::FILED.' filed,JSON_UNQUOTE(JSON_EXTRACT(t.payload_json,"$.title")) title'.$inc.' AND '.$readable[0].' ORDER BY '.self::FILED.' DESC,t.id DESC LIMIT 5',array_merge($cp,$readable[1]))];
        }
        return $d;
    }

    private static function employees(string $group,array $w):array
    {
        $join=' FROM employees e LEFT JOIN business_units bu ON bu.id=e.business_unit_id WHERE e.status IN ("ACTIVE","PROBATIONARY","ON_LEAVE")'.($group!==''?' AND bu.company_group=?':'');$cp=$group!==''?[$group]:[];
        $hires=static fn(string $a,string $b)=>self::one('SELECT COUNT(*)'.$join.' AND e.hire_date>=? AND e.hire_date<?',array_merge($cp,[$a,$b]));
        $due=0;
        if(FoundationRepository::columnExists('employees','probation_end_date')){
            $today=date('Y-m-d');
            $due=self::one('SELECT COUNT(*)'.$join.' AND e.status IN ("PROBATIONARY","ACTIVE") AND e.probation_end_date IS NOT NULL AND e.probation_start_date IS NOT NULL AND e.probation_end_date BETWEEN ? AND DATE_ADD(?,INTERVAL '.self::OPEN_DUE_DAYS.' DAY)
                AND e.employment_type_id IN (SELECT id FROM employment_types WHERE code IN ("PROBATIONARY","RETURNEE_PROBATIONARY","SEASONAL"))',array_merge($cp,[$today,$today]));
        }
        return ['active'=>self::one('SELECT COUNT(*)'.$join,$cp),'hired'=>$hires($w['start'],$w['end']),'hired_before'=>$hires($w['prev_start'],$w['prev_end']),'probation_due'=>$due,'due_days'=>self::OPEN_DUE_DAYS];
    }
    private static function recruitment(array $w):array
    {
        $r=RecruitmentRepository::dashboard();
        $applied=static fn(string $a,string $b)=>self::one('SELECT COUNT(*) FROM applications WHERE applied_at>=? AND applied_at<?',[$a.' 00:00:00',$b.' 00:00:00']);
        return $r+['applied'=>$applied($w['start'],$w['end']),'applied_before'=>$applied($w['prev_start'],$w['prev_end'])];
    }
    /** The existing unresolved-viewers report, reduced to the three numbers the dashboard shows. */
    private static function setup():array
    {
        $r=TicketViewerService::report(1);
        return ['branches_without_sc'=>count($r['branches_without_sc']),'retail_missing'=>(int)$r['counts']['retail_missing'],'ho_missing'=>(int)$r['counts']['ho_missing'],
            'total'=>count($r['branches_without_sc'])+(int)$r['counts']['retail_missing']+(int)$r['counts']['ho_missing']];
    }
}
