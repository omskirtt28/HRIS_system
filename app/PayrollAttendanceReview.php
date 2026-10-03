<?php
declare(strict_types=1);

/** Attendance and approved-ticket review. Monetary payroll calculation is deferred. */
final class PayrollAttendanceReview
{
    public static function correctionTime(string $date,string $value,array $site): string
    {
        if(!empty($site['shift_start'])&&!empty($site['shift_end'])) return PayrollCalculator::correctionTime($date,$value,$site);
        return (new DateTimeImmutable($date.' '.$value))->format('Y-m-d H:i:s');
    }

    public static function calculate(string $date,array $site,array $punches,array $requests,array $resolutions,bool $covered,bool $leave): array
    {
        $issues=[]; $sources=[]; $proposals=[]; $conflicts=[]; $ots=[]; $ob=false;
        foreach($requests as $r) {
            $code=(string)$r['type_code']; $sources[]=['id'=>(int)$r['id'],'no'=>$r['request_no'],'type'=>$code];
            if(in_array($code,['TA','PTA'],true)) foreach(PayrollCalculator::FIELDS as $f) if(!empty($r[$f])) $proposals[$f][]=['request_id'=>(int)$r['id'],'value'=>self::correctionTime($date,(string)$r[$f],$site)];
            if(in_array($code,['OB','POB'],true)) $ob=true;
            if(in_array($code,['OT','POT'],true)) $ots[]=$r;
        }
        $preferred=[]; foreach($proposals as $f=>$list) $preferred[$f]=$list[0]['value'];
        $raw=PayrollCalculator::assign($punches,[]);
        $assigned=PayrollCalculator::assign($punches,$preferred);
        $original=$assigned['original'];
        foreach(PayrollCalculator::FIELDS as $f) if($raw['original'][$f]!==null) $original[$f]=$raw['original'][$f];
        $effective=array_replace($original,$preferred); $issues=$assigned['issues'];
        foreach($proposals as $f=>$list) {
            if(count(array_unique(array_column($list,'value')))>1 || ($original[$f]!==null && $original[$f]!==$preferred[$f])) {
                $hash=hash('sha256',json_encode([$original[$f],$list],JSON_THROW_ON_ERROR));
                $resolution=$resolutions[$f.'|'.$hash]??null;
                if($resolution) {
                    if(empty($resolution['request_id'])) $effective[$f]=$original[$f];
                    else foreach($list as $proposal) if((int)$proposal['request_id']===(int)$resolution['request_id']) $effective[$f]=$proposal['value'];
                } else {
                    // Keep the biometric value until HR verifies a conflicting approved correction.
                    $effective[$f]=$original[$f];
                    $issues[]='CORRECTION_CONFLICT_'.strtoupper($f); $conflicts[$f]=['hash'=>$hash,'raw'=>$original[$f],'proposals'=>$list];
                }
            }
        }
        $noSources=!$punches&&!$requests&&!$leave;
        if($noSources) $issues[]='AWAITING_LOGS';
        elseif(!$ob&&!$leave) foreach(PayrollCalculator::FIELDS as $f) if($effective[$f]===null) $issues[]='MISSING_'.strtoupper($f);
        $complete=!in_array(null,$effective,true); $intervals=[]; $worked=0; $late=0; $under=0;
        $hasSchedule=!empty($site['shift_start'])&&!empty($site['shift_end']);
        if($hasSchedule) [$shiftStart,$shiftEnd]=PayrollCalculator::window($date,$site);
        if($complete) {
            $t=array_map(static fn($v)=>new DateTimeImmutable($v),$effective);
            if(!($t['time_in']<$t['lunch_out'] && $t['lunch_out']<=$t['lunch_in'] && $t['lunch_in']<$t['time_out'])) $issues[]='INVALID_PUNCH_SEQUENCE';
            else {
                $intervals=[[$t['time_in'],$t['lunch_out']],[$t['lunch_in'],$t['time_out']]];
                foreach($intervals as [$a,$b]) $worked+=self::minutes($a,$b);
                if($hasSchedule) {
                    $late=self::minutes($shiftStart,min($t['time_in'],$shiftEnd));
                    $under=self::minutes(max($t['time_out'],$shiftStart),$shiftEnd);
                    if($late) $issues[]='LATE'; if($under) $issues[]='UNDERTIME';
                }
                if(isset($site['break_minutes']) && self::minutes($t['lunch_out'],$t['lunch_in'])>(int)$site['break_minutes']) $issues[]='EXCESS_BREAK';
            }
        }
        if($ob) {
            if($leave) $issues[]='OB_LEAVE_OVERLAP';
            $late=0; $under=0;
            $issues=array_values(array_diff($issues,['LATE','UNDERTIME','EXCESS_BREAK']));
            if(!$ots) $issues=array_values(array_diff($issues,['AMBIGUOUS_PUNCHES','INVALID_PUNCH_SEQUENCE','EXTRA_PUNCHES_REVIEW']));
        }
        if($leave) { $worked=0; $late=0; $under=0; $issues=array_values(array_diff($issues,['LATE','UNDERTIME','EXCESS_BREAK'])); }
        $verifiedOt=0; $otReview=[]; $used=[];
        foreach($ots as $ot) {
            if(empty($ot['ot_start'])||empty($ot['ot_end'])) { $issues[]='OT_TIMES_MISSING'; continue; }
            $a=new DateTimeImmutable($ot['started_at']??self::correctionTime($date,(string)$ot['ot_start'],$site));
            $b=new DateTimeImmutable($ot['ended_at']??self::correctionTime($date,(string)$ot['ot_end'],$site));
            if($b<=$a) $b=$b->modify('+1 day');
            $claimed=self::minutes($a,$b); $matched=0;
            if($claimed>1440) { $issues[]='OT_INTERVAL_TOO_LONG'; continue; }
            for($cursor=$a;$cursor<$b;$cursor=$cursor->modify('+1 minute')) {
                $next=$cursor->modify('+1 minute'); if($next>$b) break; $actual=false;
                foreach($intervals as [$in,$out]) if($cursor>=$in&&$next<=$out) { $actual=true; break; }
                if(!$actual) continue;
                $matched++; $key=$cursor->format('Y-m-d H:i');
                if(isset($used[$key])) { $issues[]='OVERLAPPING_OT_REQUESTS'; continue; }
                $used[$key]=true; $verifiedOt++;
            }
            if($matched<$claimed) $issues[]='OT_ATTENDANCE_MISMATCH';
            $otReview[]=['request_id'=>(int)$ot['id'],'started_at'=>$a->format('Y-m-d H:i:s'),'ended_at'=>$b->format('Y-m-d H:i:s'),'approved_minutes'=>$claimed,'matched_minutes'=>$matched];
        }
        $issues=array_values(array_unique($issues));
        $blocking=array_filter($issues,static fn($i)=>!in_array($i,['LATE','UNDERTIME','EXCESS_BREAK'],true));
        $state=$noSources?'AWAITING_LOGS':($blocking?'ISSUES':($leave?'APPROVED_LEAVE':($ob?'APPROVED_OB':($proposals?'CORRECTED':'COMPLETE'))));
        // Missing punches carry no verified attendance hours. Amounts stay NULL, never guessed or zeroed.
        $result=['regular_minutes'=>$complete&&!in_array('INVALID_PUNCH_SEQUENCE',$issues,true)?$worked:0,'late_minutes'=>$late,'undertime_minutes'=>$under,
            'regular_ot_minutes'=>$verifiedOt,'night_ot_minutes'=>0,'holiday_ot_minutes'=>0,'holiday_night_ot_minutes'=>0,'unpaid_ot_minutes'=>0,
            'regular_amount'=>null,'ot_amount'=>null,'night_amount'=>null];
        foreach(PayrollCalculator::FIELDS as $f) { $result[$f]=$effective[$f]; $result['original_'.$f]=$original[$f]; }
        return $result+['state'=>$state,'issues'=>$issues,'sources'=>$sources,'conflicts'=>$conflicts,'day_type'=>'ATTENDANCE_REVIEW',
            'rate_snapshot'=>['workflow'=>'ATTENDANCE_REVIEW_ONLY','site'=>array_intersect_key($site,array_flip(['branch_id','workplace','shift_start','shift_end','break_minutes'])),'has_schedule'=>$hasSchedule,'ot_review'=>$otReview,'coverage_confirmed'=>$covered]];
    }

    private static function minutes(DateTimeImmutable $a,DateTimeImmutable $b): int { return max(0,(int)floor(($b->getTimestamp()-$a->getTimestamp())/60)); }
}
