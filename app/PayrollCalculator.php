<?php
declare(strict_types=1);

/** Attendance earnings only. Statutory deductions/net payslips are outside this module. */
final class PayrollCalculator
{
    public const FIELDS = ['time_in','lunch_out','lunch_in','time_out'];

    public static function window(string $date, array $site): array
    {
        $start = new DateTimeImmutable($date.' '.$site['shift_start']);
        $end = new DateTimeImmutable($date.' '.$site['shift_end']);
        if ($end <= $start) $end = $end->modify('+1 day');
        return [$start, $end];
    }

    public static function correctionTime(string $date, string $value, array $site): string
    {
        $stamp = new DateTimeImmutable($date.' '.$value);
        [$start,$end] = self::window($date,$site);
        if ($end->format('Y-m-d') !== $date && $stamp->format('H:i:s') <= $end->format('H:i:s')) $stamp = $stamp->modify('+1 day');
        return $stamp->format('Y-m-d H:i:s');
    }

    public static function assign(array $punches, array $corrections): array
    {
        $empty = array_fill_keys(self::FIELDS, null);
        if (count($punches) > 4) return ['original'=>$empty,'effective'=>array_replace($empty,$corrections),'issues'=>['EXTRA_PUNCHES_REVIEW']];
        $candidates = [];
        $walk = static function(int $n, int $slot, array $map) use (&$walk, &$candidates, $punches, $corrections): void {
            if ($n === count($punches)) {
                $merged = array_replace($map,$corrections);
                $values = array_values(array_filter($merged, static fn($v) => $v !== null));
                for ($i=1; $i<count($values); $i++) if ($values[$i] < $values[$i-1]) return;
                $candidates[] = $map; return;
            }
            $event = $punches[$n]['punch_status'];
            $allowed = match($event) { 'IN'=>[0,2], 'OUT'=>[1,3], 'TIME_IN'=>[0], 'LUNCH_OUT'=>[1], 'LUNCH_IN'=>[2], 'TIME_OUT'=>[3], default=>[] };
            foreach ($allowed as $i) {
                if ($i < $slot) continue;
                $next=$map; $next[self::FIELDS[$i]]=$punches[$n]['punched_at']; $walk($n+1,$i+1,$next);
            }
        };
        $walk(0,0,$empty);
        if (!$candidates) return ['original'=>$empty,'effective'=>array_replace($empty,$corrections),'issues'=>['INVALID_PUNCH_SEQUENCE']];
        if ($corrections && count($candidates)>1) {
            // An approved TA for missing slots should retain the actual IN/OUT events
            // in the remaining slots, rather than treating those events as replaced.
            // Equally plausible mappings still require review; no punch time is invented.
            $fewestReplacements=PHP_INT_MAX; $preserved=[];
            foreach ($candidates as $candidate) {
                $replacements=0;
                foreach ($corrections as $field=>$value) {
                    if ($candidate[$field]!==null && $candidate[$field]!==$value) $replacements++;
                }
                if ($replacements<$fewestReplacements) { $fewestReplacements=$replacements; $preserved=[]; }
                if ($replacements===$fewestReplacements) $preserved[]=$candidate;
            }
            $candidates=$preserved;
        }
        $original=$candidates[0];
        foreach (self::FIELDS as $f) foreach ($candidates as $candidate) if ($candidate[$f] !== $original[$f]) { $original[$f]=null; break; }
        return ['original'=>$original,'effective'=>array_replace($original,$corrections),'issues'=>count($candidates)>1?['AMBIGUOUS_PUNCHES']:[]];
    }

    public static function calculate(string $date, array $site, array $punches, array $requests, ?array $rate, array $rules, array $settings, array $holidays, array $resolutions, bool $covered, bool $leave): array
    {
        $issues=[]; $sources=[]; $proposals=[]; $conflicts=[];
        $zero = ['regular_minutes'=>0,'late_minutes'=>0,'undertime_minutes'=>0,'regular_ot_minutes'=>0,'night_ot_minutes'=>0,'holiday_ot_minutes'=>0,'holiday_night_ot_minutes'=>0,'unpaid_ot_minutes'=>0,'regular_amount'=>null,'ot_amount'=>null,'night_amount'=>null];
        if (empty($site['shift_start']) || empty($site['shift_end']) || $site['break_minutes']===null) {
            return $zero+array_fill_keys(array_merge(self::FIELDS,array_map(static fn($f)=>'original_'.$f,self::FIELDS)),null)+['state'=>'ISSUES','issues'=>['SCHEDULE_NOT_CONFIGURED'],'sources'=>[],'conflicts'=>[],'day_type'=>'REGULAR','rate_snapshot'=>[]];
        }
        [$shiftStart,$shiftEnd]=self::window($date,$site);
        $off=!in_array((string)(new DateTimeImmutable($date))->format('N'),explode(',',(string)$site['workdays']),true);
        $holidayFor=static function(string $day) use ($holidays,$site): string {
            $isOff=!in_array((string)(new DateTimeImmutable($day))->format('N'),explode(',',(string)$site['workdays']),true);
            $holiday=$holidays[$day]??null;
            if ($holiday) return $isOff ? $holiday.'_REST_DAY' : $holiday;
            return $isOff ? 'REST_DAY' : 'REGULAR';
        };
        $dayType=$holidayFor($date);
        $ob=false; $obUnpaid=false; $payReview=false; $ots=[];
        foreach($requests as $r) {
            $code=(string)$r['type_code']; $sources[]=['id'=>(int)$r['id'],'no'=>$r['request_no'],'type'=>$code];
            if(in_array($code,['POB','POT'],true) && ($r['pay_treatment']??'REVIEW')==='REVIEW') { $issues[]='PAY_TREATMENT_NOT_CONFIGURED_'.$code; $payReview=true; }
            if(in_array($code,['TA','PTA'],true)) foreach(self::FIELDS as $f) if(!empty($r[$f])) $proposals[$f][]=['request_id'=>(int)$r['id'],'value'=>self::correctionTime($date,(string)$r[$f],$site)];
            if(in_array($code,['OB','POB'],true)) { $ob=true; if(($r['pay_treatment']??'PAYABLE')==='NO_PAY') $obUnpaid=true; }
            if(in_array($code,['OT','POT'],true)) $ots[]=$r;
        }
        $preferred=[];
        foreach($proposals as $f=>$list) $preferred[$f]=$list[0]['value'];
        // Corrections disambiguate missing slots, while raw values remain separately visible.
        $rawAssignment=self::assign($punches,[]);
        $assignment=self::assign($punches,$preferred);
        // A bad requested correction must not erase an otherwise unambiguous raw record.
        $original=$assignment['original'];
        foreach(self::FIELDS as $field) if($rawAssignment['original'][$field]!==null) $original[$field]=$rawAssignment['original'][$field];
        $effective=array_replace($original,$preferred);
        $issues=array_merge($issues,$assignment['issues']);
        foreach($proposals as $f=>$list) {
            $values=array_unique(array_column($list,'value'));
            $raw=$original[$f];
            if(count($values)>1 || ($raw!==null && $raw!==$preferred[$f])) {
                $hash=hash('sha256',json_encode([$raw,$list],JSON_THROW_ON_ERROR));
                $resolution=$resolutions[$f.'|'.$hash]??null;
                if($resolution) {
                    if(empty($resolution['request_id'])) $effective[$f]=$raw;
                    else foreach($list as $proposal) if((int)$proposal['request_id']===(int)$resolution['request_id']) $effective[$f]=$proposal['value'];
                } else {
                    $issues[]='CORRECTION_CONFLICT_'.strtoupper($f);
                    $conflicts[$f]=['hash'=>$hash,'raw'=>$raw,'proposals'=>$list];
                }
            }
        }
        $regular=0; $late=0; $under=0; $intervals=[];
        $complete=!in_array(null,$effective,true);
        if(!$covered && !$punches && !$requests) $issues[]='AWAITING_LOGS';
        elseif(!$ob && !$leave && !$off) foreach(self::FIELDS as $f) if($effective[$f]===null) $issues[]='MISSING_'.strtoupper($f);
        if($complete) {
            $t=array_map(static fn($v)=>new DateTimeImmutable($v),$effective);
            if(!($t['time_in']<$t['lunch_out'] && $t['lunch_out']<=$t['lunch_in'] && $t['lunch_in']<$t['time_out'])) $issues[]='INVALID_PUNCH_SEQUENCE';
            else {
                $intervals=[[$t['time_in'],$t['lunch_out']],[$t['lunch_in'],$t['time_out']]];
                foreach($intervals as [$a,$b]) $regular+=self::minutes(max($a,$shiftStart),min($b,$shiftEnd));
                $expected=max(0,self::minutes($shiftStart,$shiftEnd)-(int)$site['break_minutes']);
                $regular=min($regular,$expected);
                $late=self::minutes($shiftStart,min($t['time_in'],$shiftEnd));
                $under=self::minutes(max($t['time_out'],$shiftStart),$shiftEnd);
                if(self::minutes($t['lunch_out'],$t['lunch_in'])>(int)$site['break_minutes']) $issues[]='EXCESS_BREAK';
                if($late>0) $issues[]='LATE'; if($under>0) $issues[]='UNDERTIME';
            }
        }
        if($ob) {
            if($leave) $issues[]='OB_LEAVE_OVERLAP';
            $regular=max(0,self::minutes($shiftStart,$shiftEnd)-(int)$site['break_minutes']); $late=0; $under=0;
            // Approved OB authorizes regular coverage, never invented biometric punches/OT evidence.
            if($obUnpaid) $regular=0;
            if(!$ots) $issues=array_values(array_diff($issues,['AMBIGUOUS_PUNCHES','INVALID_PUNCH_SEQUENCE','EXTRA_PUNCHES_REVIEW']));
        }
        if($leave) { $regular=0; $late=0; $under=0; }
        if($off && !$ob) $regular=0;
        $result=$zero; $result['regular_minutes']=$regular; $result['late_minutes']=$late; $result['undertime_minutes']=$under;
        $hourly=$rate ? (float)$rate['salary_amount']/(float)$rate['hourly_divisor'] : null;
        $regularRule=$rules[$dayType]??[];
        if($hourly===null) $issues[]='SALARY_RATE_NOT_CONFIGURED';
        if($regular>0 && ($regularRule['regular_multiplier']??null)===null) $issues[]='REGULAR_RATE_NOT_CONFIGURED';
        $result['regular_amount']=$regular===0?0:($hourly!==null && ($regularRule['regular_multiplier']??null)!==null ? round($regular/60*$hourly*(float)$regularRule['regular_multiplier'],2) : null);
        $otPay=0; $nightPay=0; $otValid=true; $nightValid=true; $paidMinutes=[];
        foreach($ots as $ot) {
            if(empty($ot['ot_start'])||empty($ot['ot_end'])) { $issues[]='OT_TIMES_MISSING'; $otValid=false; continue; }
            $a=new DateTimeImmutable($ot['started_at']??self::correctionTime($date,(string)$ot['ot_start'],$site));
            $b=new DateTimeImmutable($ot['ended_at']??self::correctionTime($date,(string)$ot['ot_end'],$site));
            if($b<=$a) $b=$b->modify('+1 day');
            if(self::minutes($a,$b)>1440) { $issues[]='OT_INTERVAL_TOO_LONG'; $otValid=false; continue; }
            $approvedMinutes=0; $workedMinutes=0;
            for($cursor=$a;$cursor<$b;$cursor=$cursor->modify('+1 minute')) {
                $next=min($cursor->modify('+1 minute'),$b);
                if(!$off && $cursor>=$shiftStart && $cursor<$shiftEnd) { $issues[]='OT_OVERLAPS_REGULAR_SHIFT'; continue; }
                $approvedMinutes++;
                $worked=false;
                foreach($intervals as [$ia,$ib]) if($cursor>=$ia && $next<=$ib) { $worked=true; break; }
                if(!$worked) continue;
                $workedMinutes++;
                $alreadyCredited=false;
                foreach($ot['credited_windows']??[] as $prior) if($cursor>=new DateTimeImmutable($prior[0]) && $next<=new DateTimeImmutable($prior[1])) { $alreadyCredited=true; break; }
                if($alreadyCredited) continue;
                $minuteKey=$cursor->format('Y-m-d H:i');
                if(isset($paidMinutes[$minuteKey])) { $issues[]='OVERLAPPING_OT_REQUESTS'; continue; }
                $paidMinutes[$minuteKey]=true;
                if(($ot['pay_treatment']??'PAYABLE')==='NO_PAY') { $result['unpaid_ot_minutes']++; continue; }
                $category=$holidayFor($cursor->format('Y-m-d')); $rule=$rules[$category]??[];
                $night=self::isNight($cursor,$settings);
                $holiday=!in_array($category,['REGULAR','REST_DAY'],true);
                $bucket=$holiday?($night?'holiday_night_ot_minutes':'holiday_ot_minutes'):($night?'night_ot_minutes':'regular_ot_minutes');
                $result[$bucket]++;
                if($hourly===null || ($rule['ot_multiplier']??null)===null) { $issues[]='OT_RATE_NOT_CONFIGURED_'.$category; $otValid=false; }
                else {
                    $amount=$hourly/60*(float)$rule['ot_multiplier']; $otPay+=$amount;
                    if($night) {
                        if(($rule['night_percent']??null)===null) { $issues[]='NIGHT_RATE_NOT_CONFIGURED_'.$category; $nightValid=false; }
                        else $nightPay+=$amount*(float)$rule['night_percent']/100;
                    }
                }
            }
            if($workedMinutes<$approvedMinutes) $issues[]='OT_ATTENDANCE_MISMATCH';
        }
        $totalOt=array_sum(array_intersect_key($result,array_flip(['regular_ot_minutes','night_ot_minutes','holiday_ot_minutes','holiday_night_ot_minutes'])));
        if($totalOt && (empty($settings['night_start'])||empty($settings['night_end']))) { $issues[]='NIGHT_PERIOD_NOT_CONFIGURED'; $nightValid=false; }
        $result['ot_amount']=$otValid?round($otPay,2):null;
        $result['night_amount']=$nightValid?round($nightPay,2):null;
        if($payReview) { $result['regular_amount']=null; $result['ot_amount']=null; $result['night_amount']=null; }
        $issues=array_values(array_unique($issues));
        $blocking=array_filter($issues,static fn($i)=>!in_array($i,['LATE','UNDERTIME','EXCESS_BREAK'],true));
        $state=$blocking?'ISSUES':($leave?'APPROVED_LEAVE':($ob?'APPROVED_OB':($off?'REST_DAY':($proposals?'CORRECTED':'COMPLETE'))));
        if($issues===['AWAITING_LOGS'] || (!$covered&&!$punches&&!$requests)) $state='AWAITING_LOGS';
        foreach(self::FIELDS as $f) { $result[$f]=$effective[$f]; $result['original_'.$f]=$original[$f]; }
        $siteSnapshot=array_intersect_key($site,array_flip(['branch_id','workplace','shift_start','shift_end','break_minutes','workdays']));
        return $result+['state'=>$state,'issues'=>$issues,'sources'=>$sources,'conflicts'=>$conflicts,'day_type'=>$dayType,'rate_snapshot'=>['rate'=>$rate,'hourly_rate'=>$hourly,'rules'=>$rules,'night_period'=>$settings,'site'=>$siteSnapshot]];
    }

    private static function isNight(DateTimeImmutable $t,array $settings): bool
    {
        if(empty($settings['night_start'])||empty($settings['night_end'])) return false;
        $clock=$t->format('H:i:s'); $a=$settings['night_start']; $b=$settings['night_end'];
        return $a>$b ? ($clock>=$a||$clock<$b) : ($clock>=$a&&$clock<$b);
    }
    private static function minutes(DateTimeImmutable $a,DateTimeImmutable $b): int { return max(0,(int)floor(($b->getTimestamp()-$a->getTimestamp())/60)); }
}
