<?php
declare(strict_types=1);

require_once __DIR__.'/PayrollStatutory.php';

/**
 * Payroll engine P2-P5 (migration 046; HRIS-Vault/02-Modules/Payroll.md, plan Plans/2026-11-17-payroll-engine.md).
 * A run reads only the FINALIZED cutoff snapshot (BR-501) and the employee rates and OT rules from Payroll Setup.
 * Monthly SSS/PhilHealth/Pag-IBIG are split across the month's cutoffs (John 2026-11-17): half in an earlier cutoff,
 * the rest in the month's last cutoff, so per-month totals equal the monthly contribution (BR-509, BR-515).
 * Release needs a second person, locks the run (BR-508) and creates one DRAFT journal entry for the normal approval.
 */
final class PayrollRun
{
    public const OT_BUCKETS = ['regular_ot_minutes','night_ot_minutes','holiday_ot_minutes','holiday_night_ot_minutes'];
    public const NIGHT_BUCKETS = ['night_ot_minutes','holiday_night_ot_minutes'];
    public const STAT = ['SSS','PHILHEALTH','PAGIBIG'];
    public const LABELS = ['REGULAR'=>'Regular pay','OT'=>'Overtime','NIGHT'=>'Night premium','SSS'=>'SSS','PHILHEALTH'=>'PhilHealth','PAGIBIG'=>'Pag-IBIG','WHT'=>'Withholding tax','ADJ'=>'Adjustment',
        'BASIC'=>'Basic pay (half month)','LEAVE'=>'Paid leave','PREMIUM'=>'Rest day / holiday premium','ABSENT'=>'Absences','LATE'=>'Late and undertime','CARRY'=>'Balance from previous payroll'];
    /** Journal accounts by Chart of Accounts code (sample COA from migration 039). */
    public const ACCOUNTS = ['salaries'=>'5010','employer'=>'5020','SSS'=>'2100','PHILHEALTH'=>'2110','PAGIBIG'=>'2120','WHT'=>'2130','advances'=>'1200','carry'=>'1200','bank'=>'1030','thirteenth'=>'5030'];

    public static function ready(): bool
    {
        static $ready=null;
        return $ready ??= PayrollStatutory::ready() && (bool)db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='payroll_run_adjustments'")->fetchColumn()
            && FoundationRepository::columnExists('payroll_run_employees','carry_out'); // migration 047
    }

    /**
     * One snapshot day to pesos, the same formula as PayrollCalculator: regular x regular multiplier, OT x OT multiplier,
     * night premium = night % of the night OT pay. shortcut: every OT minute uses the work date's day type (an OT that
     * crosses midnight into a holiday is paid at the start date's rule); upgrade when snapshots keep per-minute categories.
     */
    public static function dayPay(array $day, ?array $rate, array $rules): array
    {
        $reg=(int)($day['regular_minutes']??0);
        $ot=0; foreach(self::OT_BUCKETS as $k) $ot+=(int)($day[$k]??0);
        $night=0; foreach(self::NIGHT_BUCKETS as $k) $night+=(int)($day[$k]??0);
        $out=['REGULAR'=>0.0,'OT'=>0.0,'NIGHT'=>0.0,'issues'=>[]];
        if($reg+$ot===0) return $out;
        $date=(string)($day['work_date']??'');
        if(!$rate) { $out['issues'][]="No salary rate covers $date."; return $out; }
        $hourly=(float)$rate['salary_amount']/(float)$rate['hourly_divisor'];
        $type=(string)($day['day_type']??'REGULAR'); $rule=$rules[$type]??[]; $name=ucwords(strtolower(str_replace('_',' ',$type)));
        if($reg>0) {
            if(($rule['regular_multiplier']??null)===null) $out['issues'][]="Regular multiplier for $name is not set.";
            else $out['REGULAR']=$reg/60*$hourly*(float)$rule['regular_multiplier'];
        }
        if($ot>0) {
            if(($rule['ot_multiplier']??null)===null) $out['issues'][]="OT multiplier for $name is not set.";
            else {
                $out['OT']=$ot/60*$hourly*(float)$rule['ot_multiplier'];
                if($night>0) {
                    if(($rule['night_percent']??null)===null) $out['issues'][]="Night premium for $name is not set.";
                    else $out['NIGHT']=$night/60*$hourly*(float)$rule['ot_multiplier']*(float)$rule['night_percent']/100;
                }
            }
        }
        return $out;
    }

    /**
     * Day type for a date. Review-only snapshots carry day_type ATTENDANCE_REVIEW, so the run classifies from the holiday
     * calendar and the site's workdays (ISO 1=Mon..7=Sun), like PayrollCalculator. No workdays known = no rest days.
     */
    public static function dayType(string $date, ?string $workdays, ?string $holiday, array $rules): string
    {
        $rest=$workdays!==null && !in_array((new DateTimeImmutable($date))->format('N'),explode(',',$workdays),true);
        if($holiday!==null) return $rest && isset($rules[$holiday.'_REST_DAY']) ? $holiday.'_REST_DAY' : $holiday;
        return $rest ? 'REST_DAY' : 'REGULAR';
    }

    /**
     * Attendance earnings of one employee for one cutoff, as [code,label,amount] lines plus issues.
     * $c: period_start, period_end, rate (in force at period end), rateOn(date) closure, rules, workdays (CSV|null),
     * holidays [date=>type], schedule_minutes (paid minutes per workday|null), leave [date=>paid fraction 0..1].
     * PER_HOUR: hours worked x hourly x multiplier, plus approved paid leave on workdays.
     * FIXED (monthly-paid): half the monthly salary, less absent workdays and late/undertime, plus OT and the premium part
     * of work on rest days/holidays. shortcut: a FIXED employee working a rest day gets only (multiplier - 1) on top, as if
     * rest days were inside the monthly salary; confirm against the annual factor in use (NEEDS VERIFICATION, Q-P8).
     */
    public static function earnings(array $days, array $c): array
    {
        $rate=$c['rate']; $fixed=($rate['pay_method']??'PER_HOUR')==='FIXED'; $rules=$c['rules'];
        $sum=['BASIC'=>0.0,'REGULAR'=>0.0,'PREMIUM'=>0.0,'LEAVE'=>0.0,'OT'=>0.0,'NIGHT'=>0.0,'ABSENT'=>0.0,'LATE'=>0.0]; $issues=[];
        $sched=$c['schedule_minutes']; $byDate=[]; foreach($days as $d) $byDate[(string)$d['work_date']]=$d;
        $noSchedule=false;
        if($fixed) {
            $sum['BASIC']=(float)$rate['salary_amount']/2;
            if($sched===null || $c['workdays']===null) $noSchedule=true;
        }
        for($d=new DateTimeImmutable($c['period_start']);$d<=new DateTimeImmutable($c['period_end']);$d=$d->modify('+1 day')) {
            $date=$d->format('Y-m-d'); $day=$byDate[$date]??null;
            $type=$day && isset($rules[$day['day_type']??'']) ? (string)$day['day_type'] : self::dayType($date,$c['workdays'],$c['holidays'][$date]??null,$rules);
            $workday=$type==='REGULAR';
            $r=$fixed?$rate:($c['rateOn'])($date);
            $hourly=$r?(float)$r['salary_amount']/(float)$r['hourly_divisor']:null;
            $leave=(float)($c['leave'][$date]??0);
            $reg=(int)($day['regular_minutes']??0);
            if($day) {
                $p=self::dayPay(['day_type'=>$type]+$day,$r,$rules);
                array_push($issues,...$p['issues']);
                $sum['OT']+=$p['OT']; $sum['NIGHT']+=$p['NIGHT'];
                if(!$fixed) { // rest day / holiday multiplier part goes to PREMIUM so REGULAR stays basic pay (13th month base); gross unchanged
                    $base=$p['REGULAR']>0 ? $reg/60*$hourly : 0.0;
                    $sum['REGULAR']+=$base; $sum['PREMIUM']+=$p['REGULAR']-$base;
                }
                elseif(!$workday && $reg>0) {
                    $m=$rules[$type]['regular_multiplier']??null;
                    if($m!==null) $sum['PREMIUM']+=$reg/60*$hourly*((float)$m-1);
                }
            }
            if(!$workday || $hourly===null) continue;
            $absent=$reg===0 && ($day['state']??'')!=='APPROVED_OB';
            if($fixed) {
                if($sched===null) continue;
                if($absent) $sum['ABSENT']-=(1-$leave)*$sched/60*$hourly;
                else $sum['LATE']-=((int)($day['late_minutes']??0)+(int)($day['undertime_minutes']??0))/60*$hourly;
            } elseif($absent && $leave>0) {
                if($sched===null) { $noSchedule=true; continue; }
                $sum['LEAVE']+=$leave*$sched/60*$hourly;
            }
        }
        if($noSchedule) $issues[]='Work schedule (shift and workdays) is not set for the employee\x27s site.';
        $lines=[]; foreach($sum as $code=>$v) if(round($v,4)!=0.0) $lines[]=[$code,self::LABELS[$code],round($v,4)];
        return ['lines'=>$lines,'issues'=>array_values(array_unique($issues))];
    }

    /**
     * Pure settlement of one employee for one run.
     * $in: earnings (list of [code,label,amount] from earnings(), or [CODE=>amount]), adjustments [[kind,label,amount]],
     * basis/salary (rate in force at cutoff end), tables [SSS,PHILHEALTH,PAGIBIG,BIR => version with rows or null],
     * last (bool: last cutoff of the month), prev [gross, SSS=>[employee,employer], ...] from the month's earlier released runs,
     * carry_in (amount carried from the previous payroll, BR-507).
     */
    public static function settle(array $in): array
    {
        $lines=[]; $issues=[];
        $earn=$in['earnings']??[];
        if(!array_is_list($earn)) $earn=array_map(fn($c,$v)=>[$c,self::LABELS[$c]??$c,(float)$v],array_keys($earn),$earn);
        $gross=0.0;
        foreach($earn as [$c,$label,$amount]) { if(round((float)$amount,4)==0.0) continue; $gross+=(float)$amount; $lines[]=['EARNING',$c,$label,round((float)$amount,4),0.0,null]; }
        $gross=round($gross,4);
        $prev=$in['prev']??[];
        // BR-515: contributions use monthly compensation. Monthly-paid = monthly salary; others = this month's attendance pay
        // (projected as this cutoff x 2 until the month's last cutoff, where the actual total is known).
        $base=($in['basis']??null)==='MONTHLY' ? (float)$in['salary'] : ($in['last'] ? (float)($prev['gross']??0)+$gross : $gross*2);
        $adjEarn=0.0; $adjDed=0.0;
        foreach($in['adjustments']??[] as [$kind,$label,$amount]) {
            if($kind==='EARNING') { $adjEarn+=(float)$amount; $lines[]=['EARNING','ADJ',$label,(float)$amount,0.0,null]; }
        }
        $ee=0.0; $er=0.0;
        foreach($base>0 ? self::STAT : [] as $code) { // no compensation in the month = no contribution (e.g. only a carried balance)
            $t=$in['tables'][$code]??null;
            if(!$t) { $issues[]='No verified '.PayrollStatutory::CODES[$code].' table for the pay date.'; continue; }
            try { $full=PayrollStatutory::contribution($code,$t['rows'],$base); } catch(RuntimeException $e) { $issues[]=$e->getMessage(); continue; }
            if($in['last']) { $e=$full['employee']-(float)($prev[$code]['employee']??0); $r=$full['employer']-(float)($prev[$code]['employer']??0); $label=self::LABELS[$code].' (month balance)'; }
            else { $e=$full['employee']/2; $r=$full['employer']/2; $label=self::LABELS[$code].' (half of month)'; }
            $e=round($e,4); $r=round($r,4); $ee+=$e; $er+=$r;
            $lines[]=['DEDUCTION',$code,$label,$e,$r,(int)$t['id']];
        }
        // Taxable: gross and taxable adjustments less employee statutory shares (BR-506; no de minimis types yet).
        $taxable=max(0.0,round($gross+$adjEarn-$ee,4)); $tax=0.0;
        $t=$in['tables']['BIR']??null;
        if(!$t) $issues[]='No verified BIR withholding table (semi-monthly) for the pay date.';
        else { try { $tax=PayrollStatutory::contribution('BIR_WITHHOLDING',$t['rows'],$taxable)['employee']; $lines[]=['DEDUCTION','WHT',self::LABELS['WHT'],$tax,0.0,(int)$t['id']]; } catch(RuntimeException $e) { $issues[]=$e->getMessage(); } }
        foreach($in['adjustments']??[] as [$kind,$label,$amount]) if($kind==='DEDUCTION') { $adjDed+=(float)$amount; $lines[]=['DEDUCTION','ADJ',$label,(float)$amount,0.0,null]; }
        $carryIn=round((float)($in['carry_in']??0),4);
        if($carryIn>0) { $adjDed+=$carryIn; $lines[]=['DEDUCTION','CARRY',self::LABELS['CARRY'],$carryIn,0.0,null]; }
        $deductions=round($ee+$tax+$adjDed,4); $net=round($gross+$adjEarn-$deductions,4);
        // BR-507: never a negative payslip; the excess is owed by the employee and deducted in the next payroll.
        $carryOut=0.0; if($net<0) { $carryOut=-$net; $net=0.0; }
        return ['lines'=>$lines,'issues'=>$issues,'gross'=>round($gross+$adjEarn,4),'taxable'=>$taxable,'deductions'=>$deductions,'employer'=>round($er,4),'net'=>$net,'carry_out'=>$carryOut,'base'=>round($base,4)];
    }

    /** Finalized cutoffs, newest first, with their active run if any. */
    public static function cutoffs(): array
    {
        return db()->query("SELECT c.id,c.code,c.period_start,c.period_end,cr.state,r.id run_id,r.status run_status FROM payroll_cutoffs c
            JOIN payroll_cutoff_runs cr ON cr.cutoff_id=c.id LEFT JOIN payroll_runs r ON r.active_cutoff_id=c.id
            WHERE cr.state='FINALIZED' ORDER BY c.period_end DESC LIMIT 48")->fetchAll();
    }

    public static function runs(): array
    {
        return db()->query('SELECT r.*,c.code cutoff_code,c.period_start,c.period_end,uc.full_name computed_name,ur.full_name released_name,e.entry_no
            FROM payroll_runs r JOIN payroll_cutoffs c ON c.id=r.cutoff_id JOIN users uc ON uc.id=r.computed_by LEFT JOIN users ur ON ur.id=r.released_by
            LEFT JOIN acct_journal_entries e ON e.id=r.entry_id ORDER BY c.period_end DESC,r.id DESC LIMIT 100')->fetchAll();
    }

    public static function run(int $id): array
    {
        $q=db()->prepare('SELECT r.*,c.code cutoff_code,c.period_start,c.period_end,uc.full_name computed_name,ur.full_name released_name,e.entry_no
            FROM payroll_runs r JOIN payroll_cutoffs c ON c.id=r.cutoff_id JOIN users uc ON uc.id=r.computed_by LEFT JOIN users ur ON ur.id=r.released_by
            LEFT JOIN acct_journal_entries e ON e.id=r.entry_id WHERE r.id=?');
        $q->execute([$id]); $r=$q->fetch() ?: throw new RuntimeException('Payroll run not found.');
        $q=db()->prepare('SELECT pre.*,e.employee_no,CONCAT_WS(" ",e.first_name,e.last_name) employee_name FROM payroll_run_employees pre JOIN employees e ON e.id=pre.employee_id WHERE pre.run_id=? ORDER BY e.last_name,e.first_name');
        $q->execute([$id]); $r['employees']=$q->fetchAll();
        $q=db()->prepare('SELECT l.* FROM payroll_run_lines l JOIN payroll_run_employees pre ON pre.id=l.run_employee_id WHERE pre.run_id=? ORDER BY l.id');
        $q->execute([$id]); $byEmp=[]; foreach($q->fetchAll() as $l) $byEmp[(int)$l['run_employee_id']][]=$l;
        foreach($r['employees'] as &$e) $e['lines']=$byEmp[(int)$e['id']]??[]; unset($e);
        $q=db()->prepare('SELECT a.*,CONCAT_WS(" ",e.first_name,e.last_name) employee_name FROM payroll_run_adjustments a JOIN employees e ON e.id=a.employee_id WHERE a.run_id=? ORDER BY a.id');
        $q->execute([$id]); $r['adjustments']=$q->fetchAll();
        return $r;
    }

    /** Computes (or recomputes while COMPUTED) the run of a finalized cutoff. */
    public static function compute(int $cutoffId, string $payDate, int $actor): int
    {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$payDate);
        if(!$d || $d->format('Y-m-d')!==$payDate) throw new RuntimeException('Enter a valid pay date.');
        $q=db()->prepare('SELECT c.*,cr.state FROM payroll_cutoffs c LEFT JOIN payroll_cutoff_runs cr ON cr.cutoff_id=c.id WHERE c.id=?'); $q->execute([$cutoffId]);
        $cut=$q->fetch() ?: throw new RuntimeException('Cutoff not found.');
        if($cut['state']!=='FINALIZED') throw new RuntimeException('Finalize the cutoff attendance before computing payroll.');
        if($payDate<$cut['period_end']) throw new RuntimeException('The pay date cannot be before the cutoff ends.');
        $month=substr($cut['period_end'],0,7);
        $q=db()->prepare('SELECT COUNT(*) FROM payroll_cutoffs WHERE DATE_FORMAT(period_end,"%Y-%m")=? AND period_end>?'); $q->execute([$month,$cut['period_end']]);
        $last=!(int)$q->fetchColumn();
        $pdo=db(); $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT id,status FROM payroll_runs WHERE active_cutoff_id=? FOR UPDATE'); $q->execute([$cutoffId]); $run=$q->fetch();
            if($run && $run['status']==='RELEASED') throw new RuntimeException('This cutoff\'s payroll is already released and locked.');
            $prevRuns=[];
            if($last) {
                // The month balance needs the earlier cutoffs' final figures.
                $q=$pdo->prepare('SELECT r.id,r.status FROM payroll_runs r JOIN payroll_cutoffs c ON c.id=r.cutoff_id WHERE r.status<>"VOIDED" AND DATE_FORMAT(c.period_end,"%Y-%m")=? AND c.period_end<?');
                $q->execute([$month,$cut['period_end']]);
                foreach($q->fetchAll() as $p) { if($p['status']!=='RELEASED') throw new RuntimeException('Release the earlier payroll of '.$month.' first; this cutoff deducts the month balance.'); $prevRuns[]=(int)$p['id']; }
            }
            if($run) {
                $id=(int)$run['id'];
                $pdo->prepare('DELETE FROM payroll_run_employees WHERE run_id=?')->execute([$id]);
                $pdo->prepare('UPDATE payroll_runs SET pay_date=?,computed_by=?,computed_at=NOW() WHERE id=?')->execute([$payDate,$actor,$id]);
            } else {
                $pdo->prepare('INSERT INTO payroll_runs(cutoff_id,pay_date,computed_by) VALUES(?,?,?)')->execute([$cutoffId,$payDate,$actor]);
                $id=(int)$pdo->lastInsertId();
            }
            $days=[]; $q=$pdo->prepare('SELECT employee_id,snapshot_json FROM payroll_cutoff_snapshots WHERE cutoff_id=? ORDER BY employee_id,work_date'); $q->execute([$cutoffId]);
            foreach($q->fetchAll() as $s) $days[(int)$s['employee_id']][]=json_decode($s['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $adj=[]; $q=$pdo->prepare('SELECT employee_id,kind,label,amount FROM payroll_run_adjustments WHERE run_id=? ORDER BY id'); $q->execute([$id]);
            foreach($q->fetchAll() as $a) $adj[(int)$a['employee_id']][]=[$a['kind'],$a['label'],$a['amount']];
            // BR-507: a balance owed from the employee's latest earlier released payroll is deducted here, so earlier runs must be final.
            $q=$pdo->prepare('SELECT r.status FROM payroll_runs r JOIN payroll_cutoffs c ON c.id=r.cutoff_id WHERE r.status="COMPUTED" AND c.period_end<? LIMIT 1'); $q->execute([$cut['period_end']]);
            if($q->fetchColumn()) throw new RuntimeException('Release or void the earlier computed payroll first; balances carry forward in order.');
            $carry=[]; $q=$pdo->prepare('SELECT pre.employee_id,pre.carry_out FROM payroll_run_employees pre JOIN payroll_runs r ON r.id=pre.run_id JOIN payroll_cutoffs c ON c.id=r.cutoff_id
                WHERE r.status="RELEASED" AND c.period_end<? AND c.period_end=(SELECT MAX(c2.period_end) FROM payroll_run_employees p2 JOIN payroll_runs r2 ON r2.id=p2.run_id JOIN payroll_cutoffs c2 ON c2.id=r2.cutoff_id WHERE p2.employee_id=pre.employee_id AND r2.status="RELEASED" AND c2.period_end<?)');
            $q->execute([$cut['period_end'],$cut['period_end']]); foreach($q->fetchAll() as $c) if((float)$c['carry_out']>0) $carry[(int)$c['employee_id']]=(float)$c['carry_out'];
            $employees=array_unique([...array_keys($days),...array_keys($adj),...array_keys($carry)]);
            $rates=[]; foreach(db()->query('SELECT * FROM payroll_employee_rates ORDER BY effective_from DESC')->fetchAll() as $r) $rates[(int)$r['employee_id']][]=$r;
            $rateOn=function(int $emp,string $date) use($rates): ?array { foreach($rates[$emp]??[] as $r) if($r['effective_from']<=$date && ($r['effective_to']===null || $r['effective_to']>=$date)) return $r; return null; };
            $rules=db()->query('SELECT * FROM payroll_rate_rules')->fetchAll(PDO::FETCH_UNIQUE);
            $tables=['BIR'=>PayrollStatutory::forDate('BIR_WITHHOLDING','SEMI_MONTHLY',$payDate)]; // shortcut: cutoffs are semi-monthly; pick the frequency per cutoff if weekly/monthly payrolls are added.
            foreach(self::STAT as $c) $tables[$c]=PayrollStatutory::forDate($c,'MONTHLY',$payDate);
            $prev=[];
            if($prevRuns) {
                $in=implode(',',$prevRuns);
                foreach($pdo->query("SELECT employee_id,SUM(gross-COALESCE((SELECT SUM(l.amount) FROM payroll_run_lines l WHERE l.run_employee_id=pre.id AND l.kind='EARNING' AND l.code='ADJ'),0)) g FROM payroll_run_employees pre WHERE run_id IN ($in) GROUP BY employee_id") as $p) $prev[(int)$p['employee_id']]['gross']=(float)$p['g'];
                foreach($pdo->query("SELECT pre.employee_id,l.code,SUM(l.amount) ee,SUM(l.employer_amount) er FROM payroll_run_lines l JOIN payroll_run_employees pre ON pre.id=l.run_employee_id WHERE pre.run_id IN ($in) AND l.code IN ('SSS','PHILHEALTH','PAGIBIG') GROUP BY pre.employee_id,l.code") as $p)
                    $prev[(int)$p['employee_id']][$p['code']]=['employee'=>(float)$p['ee'],'employer'=>(float)$p['er']];
            }
            $insE=$pdo->prepare('INSERT INTO payroll_run_employees(run_id,employee_id,salary_basis,monthly_base,gross,taxable,deductions,employer_share,net,carry_out,issues_json) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $branch=[]; if($employees) foreach($pdo->query('SELECT id,branch_id FROM employees WHERE id IN ('.implode(',',array_map('intval',$employees)).')') as $e) $branch[(int)$e['id']]=(int)$e['branch_id'];
            $sites=[]; $hol=$pdo->prepare('SELECT holiday_date,day_type FROM payroll_holidays WHERE holiday_date BETWEEN ? AND ? AND (branch_id IS NULL OR branch_id=?) ORDER BY branch_id IS NULL DESC');
            $lv=$pdo->prepare('SELECT lr.date_from,lr.date_to,lr.days FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.employee_id=? AND lr.status="APPROVED" AND lt.paid=1 AND lr.date_from<=? AND lr.date_to>=?');
            $insL=$pdo->prepare('INSERT INTO payroll_run_lines(run_employee_id,kind,code,label,amount,employer_amount,stat_table_id) VALUES(?,?,?,?,?,?,?)');
            $tot=['gross'=>0.0,'deductions'=>0.0,'employer'=>0.0,'net'=>0.0]; $issueCount=0;
            foreach($employees as $emp) {
                $b=$branch[$emp]??0; $site=$sites[$b]??=PayrollAttendanceService::attendancePolicy($b);
                $sched=null;
                if(!empty($site['shift_start']) && !empty($site['shift_end']) && ($site['break_minutes']??null)!==null) {
                    $m=(strtotime('2000-01-01 '.$site['shift_end'])-strtotime('2000-01-01 '.$site['shift_start']))/60; if($m<=0) $m+=1440; // overnight shift
                    $sched=max(0,(int)$m-(int)$site['break_minutes']);
                }
                $holidays=[]; $hol->execute([$cut['period_start'],$cut['period_end'],$b]); foreach($hol->fetchAll() as $h) $holidays[$h['holiday_date']]??=$h['day_type'];
                $leave=[]; $lv->execute([$emp,$cut['period_end'],$cut['period_start']]);
                // shortcut: a half day is read only from a one-day filing (days < 1); upgrade if multi-day filings carry per-day parts.
                foreach($lv->fetchAll() as $l) for($d=new DateTimeImmutable(max($l['date_from'],$cut['period_start']));$d->format('Y-m-d')<=min($l['date_to'],$cut['period_end']);$d=$d->modify('+1 day'))
                    $leave[$d->format('Y-m-d')]=$l['date_from']===$l['date_to'] ? min(1.0,(float)$l['days']) : 1.0;
                $rate=$rateOn($emp,$cut['period_end']);
                $e=self::earnings($days[$emp]??[],['period_start'=>$cut['period_start'],'period_end'=>$cut['period_end'],'rate'=>$rate??[],'rateOn'=>fn(string $d)=>$rateOn($emp,$d),'rules'=>$rules,
                    'workdays'=>($site['workdays']??'')!=='' ? (string)$site['workdays'] : null,'holidays'=>$holidays,'schedule_minutes'=>$sched,'leave'=>$leave]);
                $s=self::settle(['earnings'=>$e['lines'],'adjustments'=>$adj[$emp]??[],'basis'=>$rate['salary_basis']??null,'salary'=>$rate['salary_amount']??0,'tables'=>$tables,'last'=>$last,'prev'=>$prev[$emp]??[],'carry_in'=>$carry[$emp]??0]);
                $issues=array_values(array_unique([...$e['issues'],...$s['issues']]));
                $insE->execute([$id,$emp,$rate['salary_basis']??null,$s['base'],$s['gross'],$s['taxable'],$s['deductions'],$s['employer'],$s['net'],$s['carry_out'],json_encode($issues,JSON_THROW_ON_ERROR)]);
                $eid=(int)$pdo->lastInsertId();
                foreach($s['lines'] as [$kind,$code,$label,$amount,$er,$tid]) $insL->execute([$eid,$kind,$code,mb_substr($label,0,120),$amount,$er,$tid]);
                foreach(['gross','deductions','employer','net'] as $k) $tot[$k]+=$s[$k];
                if($issues) $issueCount++;
            }
            $pdo->prepare('UPDATE payroll_runs SET employee_count=?,issue_count=?,total_gross=?,total_deductions=?,total_employer=?,total_net=? WHERE id=?')
                ->execute([count($employees),$issueCount,$tot['gross'],$tot['deductions'],$tot['employer'],$tot['net'],$id]);
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('payroll','run_computed','payroll_runs',$id,['cutoff_id'=>$cutoffId,'employees'=>count($employees),'issues'=>$issueCount]);
        return $id;
    }

    private static function computedRun(int $id): array
    {
        $q=db()->prepare('SELECT * FROM payroll_runs WHERE id=?'); $q->execute([$id]);
        $r=$q->fetch() ?: throw new RuntimeException('Payroll run not found.');
        if($r['status']!=='COMPUTED') throw new RuntimeException('Only a computed (unreleased) run can be changed.');
        return $r;
    }

    public static function addAdjustment(int $runId, int $employeeId, string $kind, string $label, string $amount, int $actor): void
    {
        $r=self::computedRun($runId);
        if(!in_array($kind,['EARNING','DEDUCTION'],true)) throw new RuntimeException('Choose earning or deduction.');
        $label=trim($label); if($label==='' || mb_strlen($label)>120) throw new RuntimeException('Describe the adjustment (up to 120 characters).');
        $clean=str_replace(',','',trim($amount));
        if(!preg_match('/^\d{1,11}(\.\d{1,2})?$/',$clean) || (float)$clean<=0) throw new RuntimeException('Enter an amount above zero with up to 2 decimals.');
        $q=db()->prepare('SELECT COUNT(*) FROM payroll_run_employees WHERE run_id=? AND employee_id=?'); $q->execute([$runId,$employeeId]);
        if(!(int)$q->fetchColumn()) throw new RuntimeException('Choose an employee in this run.');
        db()->prepare('INSERT INTO payroll_run_adjustments(run_id,employee_id,kind,label,amount,created_by) VALUES(?,?,?,?,?,?)')->execute([$runId,$employeeId,$kind,$label,$clean,$actor]);
        audit('payroll','run_adjustment_added','payroll_runs',$runId,['employee_id'=>$employeeId,'kind'=>$kind,'amount'=>$clean]);
        self::compute((int)$r['cutoff_id'],$r['pay_date'],$actor);
    }

    public static function removeAdjustment(int $runId, int $adjustmentId, int $actor): void
    {
        $r=self::computedRun($runId);
        $q=db()->prepare('DELETE FROM payroll_run_adjustments WHERE id=? AND run_id=?'); $q->execute([$adjustmentId,$runId]);
        if($q->rowCount()!==1) throw new RuntimeException('Adjustment not found.');
        audit('payroll','run_adjustment_removed','payroll_runs',$runId,['adjustment_id'=>$adjustmentId]);
        self::compute((int)$r['cutoff_id'],$r['pay_date'],$actor);
    }

    public static function void(int $runId, int $actor): void
    {
        self::computedRun($runId);
        db()->prepare('UPDATE payroll_runs SET status="VOIDED",voided_by=?,voided_at=NOW() WHERE id=? AND status="COMPUTED"')->execute([$actor,$runId]);
        audit('payroll','run_voided','payroll_runs',$runId);
    }

    /** Journal lines in cents [account code, description, debit, credit]; the bank (net pay) line absorbs 4dp rounding (BR-512). */
    public static function journalLines(array $sum, string $memo, array $accounts=self::ACCOUNTS): array
    {
        $c=fn(float $v)=>(int)round($v*100);
        $dr=['salaries'=>$c($sum['earnings']),'employer'=>$c($sum['employer']),'carry'=>$c($sum['carry_out']??0)];
        $cr=[]; foreach(self::STAT as $s) $cr[$s]=$c($sum[$s]['employee']+$sum[$s]['employer']);
        $cr['WHT']=$c($sum['WHT']); $cr['advances']=$c($sum['adj_deductions']);
        $cr['bank']=array_sum($dr)-array_sum($cr);
        $lines=[];
        $names=['salaries'=>'Gross pay','employer'=>'Employer contributions','SSS'=>'SSS','PHILHEALTH'=>'PhilHealth','PAGIBIG'=>'Pag-IBIG','WHT'=>'Withholding tax','advances'=>'Payroll deductions','carry'=>'Balance owed by employees','bank'=>'Net pay'];
        // A negative amount (a month-balance refund) flips to the other side so every line stays positive.
        foreach($dr as $k=>$v) if($v!==0) $lines[]=[$accounts[$k],"$memo ".$names[$k],max($v,0),max(-$v,0)];
        foreach($cr as $k=>$v) if($v!==0) $lines[]=[$accounts[$k],"$memo ".$names[$k],max(-$v,0),max($v,0)];
        return $lines;
    }

    /** Second person releases: the run locks, payslips appear, and one DRAFT journal entry goes to Accounting. */
    public static function release(int $runId, int $actor): void
    {
        $pdo=db(); $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT r.*,c.code cutoff_code FROM payroll_runs r JOIN payroll_cutoffs c ON c.id=r.cutoff_id WHERE r.id=? FOR UPDATE'); $q->execute([$runId]);
            $r=$q->fetch() ?: throw new RuntimeException('Payroll run not found.');
            if($r['status']!=='COMPUTED') throw new RuntimeException('Only a computed run can be released.');
            if((int)$r['computed_by']===$actor) throw new RuntimeException('A different person must release the payroll that you computed.');
            if((int)$r['issue_count']>0) throw new RuntimeException('Resolve the '.(int)$r['issue_count'].' employee(s) with issues, then recompute.');
            if((int)$r['employee_count']===0) throw new RuntimeException('This run has no employees.');
            $entry=null;
            if(class_exists('Accounting') && Accounting::ready()) {
                Accounting::assertOpen($r['pay_date']);
                $q=$pdo->prepare("SELECT l.kind,l.code,SUM(l.amount) a,SUM(l.employer_amount) er FROM payroll_run_lines l JOIN payroll_run_employees pre ON pre.id=l.run_employee_id WHERE pre.run_id=? GROUP BY l.kind,l.code");
                $q->execute([$runId]);
                $sum=['earnings'=>0.0,'employer'=>0.0,'WHT'=>0.0,'adj_deductions'=>0.0]; foreach(self::STAT as $s) $sum[$s]=['employee'=>0.0,'employer'=>0.0];
                foreach($q->fetchAll() as $l) {
                    if($l['kind']==='EARNING') $sum['earnings']+=(float)$l['a'];
                    elseif(in_array($l['code'],self::STAT,true)) { $sum[$l['code']]=['employee'=>(float)$l['a'],'employer'=>(float)$l['er']]; $sum['employer']+=(float)$l['er']; }
                    elseif($l['code']==='WHT') $sum['WHT']+=(float)$l['a'];
                    else $sum['adj_deductions']+=(float)$l['a'];
                }
                $q=$pdo->prepare('SELECT COALESCE(SUM(carry_out),0) FROM payroll_run_employees WHERE run_id=?'); $q->execute([$runId]); $sum['carry_out']=(float)$q->fetchColumn();
                $memo='Payroll '.$r['cutoff_code'];
                require_once __DIR__.'/AccountingSetup.php'; // posting accounts from Accounting Settings (Phase 3 B3, Q-P7)
                $lines=self::journalLines($sum,$memo,AccountingSetup::payrollAccounts(self::ACCOUNTS));
                $ids=$pdo->prepare('SELECT id FROM acct_accounts WHERE code=? AND is_active=1');
                foreach($lines as &$line) { $ids->execute([$line[0]]); $line[0]=(int)$ids->fetchColumn() ?: throw new RuntimeException('Chart of Accounts needs an active account '.$line[0].' for payroll posting.'); } unset($line);
                $entry=Accounting::insertHeader($r['pay_date'],"$memo, pay date ".$r['pay_date'],$actor,null);
                Accounting::insertLines($entry,$lines);
            }
            $pdo->prepare('UPDATE payroll_runs SET status="RELEASED",released_by=?,released_at=NOW(),entry_id=? WHERE id=?')->execute([$actor,$entry,$runId]);
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('payroll','run_released','payroll_runs',$runId,['entry_id'=>$entry]);
    }

    /** Released payslips of one employee, newest first. */
    public static function payslips(int $employeeId): array
    {
        $q=db()->prepare('SELECT pre.id,pre.gross,pre.deductions,pre.net,r.pay_date,c.code cutoff_code,c.period_start,c.period_end FROM payroll_run_employees pre
            JOIN payroll_runs r ON r.id=pre.run_id JOIN payroll_cutoffs c ON c.id=r.cutoff_id WHERE pre.employee_id=? AND r.status="RELEASED" ORDER BY r.pay_date DESC LIMIT 48');
        $q->execute([$employeeId]); return $q->fetchAll();
    }

    /** One released payslip, only for its own employee. */
    public static function payslip(int $runEmployeeId, int $employeeId): ?array
    {
        $q=db()->prepare('SELECT pre.*,r.pay_date,c.code cutoff_code,c.period_start,c.period_end FROM payroll_run_employees pre JOIN payroll_runs r ON r.id=pre.run_id
            JOIN payroll_cutoffs c ON c.id=r.cutoff_id WHERE pre.id=? AND pre.employee_id=? AND r.status="RELEASED"');
        $q->execute([$runEmployeeId,$employeeId]); $p=$q->fetch();
        if(!$p) return null;
        $q=db()->prepare('SELECT * FROM payroll_run_lines WHERE run_employee_id=? ORDER BY id'); $q->execute([$runEmployeeId]); $p['lines']=$q->fetchAll();
        return $p;
    }
}
