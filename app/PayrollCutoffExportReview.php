<?php
declare(strict_types=1);

/** Read-only export readiness and employee/date exceptions from the real cutoff. */
final class PayrollCutoffExportReview
{
    public const CLOCK_LABELS = ['time_in'=>'Time In','lunch_out'=>'Break Out','lunch_in'=>'Break In','time_out'=>'Time Out'];

    public static function missing(array $day): array
    {
        if(($day['state']??'')!=='ISSUES') return [];
        if(!empty($day['_employee']['hire_date'])&&$day['work_date']<$day['_employee']['hire_date']) return [];
        $issues=json_decode($day['issues_json']??'[]',true)?:[];
        // An unassigned biometric sequence is a review issue, not proof no punch exists.
        if(array_intersect($issues,['AMBIGUOUS_PUNCHES','EXTRA_PUNCHES_REVIEW','INVALID_PUNCH_SEQUENCE','SCHEDULE_NOT_CONFIGURED'])) return [];
        $missing=[];
        foreach(self::CLOCK_LABELS as $field=>$label) {
            if(empty($day[$field])&&!in_array('CORRECTION_CONFLICT_'.strtoupper($field),$issues,true)) $missing[$field]=$label;
        }
        return $missing;
    }

    public static function issueLabels(array $day): array
    {
        $labels=[];
        $known=['AMBIGUOUS_PUNCHES'=>'Check biometric logs','EXTRA_PUNCHES_REVIEW'=>'Check extra biometric logs',
            'INVALID_PUNCH_SEQUENCE'=>'Check the order of clock times','OT_ATTENDANCE_MISMATCH'=>'Check OT against saved logs',
            'OB_LEAVE_OVERLAP'=>'OB and leave cover the same date','BEFORE_HIRE_DATE'=>'Check employee Start Date',
            'OT_TIMES_MISSING'=>'OT start or end is missing','OVERLAPPING_OT_REQUESTS'=>'OT requests overlap',
            'SCHEDULE_NOT_CONFIGURED'=>'Check the employee work schedule'];
        foreach(json_decode($day['issues_json']??'[]',true)?:[] as $issue) {
            if(in_array($issue,['LATE','UNDERTIME','EXCESS_BREAK','AWAITING_LOGS'],true)) continue;
            if(str_starts_with($issue,'MISSING_')) continue;
            if(str_starts_with($issue,'CORRECTION_CONFLICT_')) {
                $field=strtolower(substr($issue,strlen('CORRECTION_CONFLICT_')));
                $labels[]='Choose the correct '.(self::CLOCK_LABELS[$field]??'clock time');
            } else $labels[]=$known[$issue]??stage_label($issue);
        }
        if(!empty($day['_employee']['hire_date'])&&$day['work_date']<$day['_employee']['hire_date']) $labels[]='Check employee Start Date';
        return array_values(array_unique($labels));
    }

    public static function stage(array $request): string
    {
        if(in_array($request['status']??'',['APPROVED','COMPLETED'],true)) return 'Approved · Attendance needs matching';
        if(($request['status']??'')==='RETURNED') return 'Returned to employee';
        return match($request['approver_type']??'') {
            'HR_TIMEKEEPING'=>'Waiting for HR review', 'MANAGER'=>'Waiting for Manager approval',
            'ADL'=>'Waiting for ADL approval', 'HR_LEAVE'=>'Waiting for leave approval',
            'PAYROLL'=>'Waiting for Payroll', default=>'Waiting for review / approval'
        };
    }

    public static function build(array $model,array $sources): array
    {
        $run=$model['run'];$batch=$model['batch'];$today=date('Y-m-d');$items=[];$days=[];
        foreach($model['rows'] as $day) $days[$day['employee_id'].'|'.$day['work_date']]=$day;
        $make=static function(int $employeeId,string $date)use($model,$days):array {
            $key=$employeeId.'|'.$date;$day=$days[$key]??null;
            return ['key'=>$key,'employee_id'=>$employeeId,'date'=>$date,'date_end'=>null,
                'employee'=>$day['_employee']??$model['employees'][$employeeId]??[],
                'day'=>$day,'missing'=>$day?self::missing($day):[],
                'issues'=>$day?self::issueLabels($day):[],'calendar'=>false,'requests'=>[],'leave'=>[]];
        };
        if(!$batch) {
            foreach($model['problems'] as $day) {
                if($day['work_date']>=$today) continue;
                $key=$day['employee_id'].'|'.$day['work_date'];
                $items[$key]=$make((int)$day['employee_id'],$day['work_date']);
            }
            foreach($model['calendar'] as $day) {
                if($day['work_date']>=$today||empty($run['covered_through'])||$day['work_date']>$run['covered_through']) continue;
                $key=$day['employee_id'].'|'.$day['work_date'];
                $items[$key]=$make((int)$day['employee_id'],$day['work_date']);$items[$key]['calendar']=true;
            }
            foreach($model['review_requests']??[] as $request) {
                $key=$request['employee_id'].'|'.$request['affected_date'];
                $items[$key]??=$make((int)$request['employee_id'],$request['affected_date']);
                if(!$items[$key]['employee']) $items[$key]['employee']=self::fallbackEmployee($request);
                $items[$key]['requests'][]=$request;
                // Today and future work dates are still in progress, not missing-clock errors.
                if($request['affected_date']>=$today) $items[$key]['missing']=[];
            }
            foreach($model['review_leave']??[] as $request) {
                $date=max($run['period_start'],$request['date_from']);$key='leave|'.$request['id'];
                $items[$key]=$make((int)$request['employee_id'],$date);
                $items[$key]['key']=$key;$items[$key]['day']=null;$items[$key]['missing']=[];$items[$key]['issues']=[];
                $items[$key]['date_end']=min($run['period_end'],$request['date_to']);$items[$key]['leave']=[$request];
                if(!$items[$key]['employee']) $items[$key]['employee']=self::fallbackEmployee($request);
            }
        }
        $items=array_values($items);
        usort($items,static fn($a,$b)=>strcasecmp($a['employee']['export_section']??'',$b['employee']['export_section']??'')
            ?:strcasecmp($a['employee']['employee_name']??'',$b['employee']['employee_name']??'')?:strcmp($a['date'],$b['date']));
        $employeeIds=[];$missingDates=0;$calendarDates=0;
        foreach($items as $item) {
            $employeeIds[$item['employee_id']]=true;
            if($item['missing']) $missingDates++;
            if($item['calendar']) $calendarDates++;
        }
        $open=$run['period_end']>=$today;
        $deadlineOpen=!empty($run['ticket_deadline'])&&$run['ticket_deadline']>date('Y-m-d H:i:s');
        $coverage=!empty($run['covered_through'])&&$run['covered_through']>=$run['period_end'];
        $pending=(int)$model['pending']+(int)$model['pending_leave'];
        $legacy=$run['run_state']==='FINALIZED'&&!$batch;
        $blocked=$open||$deadlineOpen||!$coverage||$pending||$model['unmapped']||$model['unapplied']
            ||$model['problems']||$model['calendar']||!$model['employees'];
        $state=$batch?'ready':($legacy?'legacy':(!$sources?'empty':($open||$deadlineOpen?'open':($blocked?'checking':'ready'))));
        return ['state'=>$state,'blocked'=>(bool)$blocked,'open'=>$open,'deadline_open'=>$deadlineOpen,
            'coverage_done'=>$coverage,'items'=>$items,'affected_employees'=>count($employeeIds),
            'missing_dates'=>$missingDates,'calendar_dates'=>$calendarDates,'pending'=>$pending,
            'no_log_employees'=>count($model['source_gaps']['no_log_employees']??[]),'no_log_sections'=>(int)($model['source_gaps']['no_log_sections']??0)];
    }

    private static function fallbackEmployee(array $request): array
    {
        return ['id'=>$request['employee_id'],'employee_name'=>$request['employee_name'],'employee_no'=>$request['employee_no'],
            'export_group'=>'To assign','export_section'=>$request['department_name'].' / '.$request['branch_name'],
            'export_department'=>$request['department_name'],'export_main_department'=>$request['department_name'],
            'export_workplace'=>'UNASSIGNED','company_name'=>'','branch_name'=>$request['branch_name'],'branch_code'=>'','area_name'=>''];
    }
}
