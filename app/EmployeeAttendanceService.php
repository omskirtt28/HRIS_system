<?php
declare(strict_types=1);

/** Read-only, account-scoped cutoff attendance. Employee IDs never come from the browser. */
final class EmployeeAttendanceService
{
    public const LABELS=['time_in'=>'Time In','lunch_out'=>'Break Out','lunch_in'=>'Break In','time_out'=>'Time Out'];

    public static function defaultCutoff(array $cutoffs): int
    {
        $today=date('Y-m-d');
        foreach($cutoffs as $cutoff) if($cutoff['period_start']<=$today && $cutoff['period_end']>=$today) return (int)$cutoff['id'];
        foreach($cutoffs as $cutoff) if($cutoff['period_start']<=$today) return (int)$cutoff['id'];
        return (int)($cutoffs[0]['id']??0);
    }

    public static function current(): ?array
    {
        Auth::requirePermission('attendance.view_self');
        if(!PayrollAttendanceService::ready() || !PayrollRepository::currentEmployee()) return null;
        $q=db()->prepare('SELECT * FROM payroll_cutoffs WHERE period_start<=? ORDER BY period_start DESC LIMIT 1');
        $q->execute([date('Y-m-d')]); $cutoff=$q->fetch();
        return $cutoff?self::cutoff((int)$cutoff['id']):null;
    }

    public static function cutoff(int $cutoffId): array
    {
        Auth::requirePermission('attendance.view_self'); PayrollAttendanceService::requireReady();
        $employee=PayrollRepository::currentEmployee();
        if(!$employee) throw new RuntimeException('HR must link your account to your Employee 201 record first.');
        $employeeId=(int)$employee['id']; $run=PayrollAttendanceService::run($cutoffId);
        $site=PayrollAttendanceService::attendancePolicy((int)($employee['branch_id']??0));
        $daily=PayrollAttendanceService::dailyRows($cutoffId,$employeeId); $byDate=[]; $punchDates=[];
        foreach($daily as $row) {
            $byDate[$row['work_date']]=$row;
            $snapshot=json_decode($row['rate_snapshot_json']??'{}',true)?:[];
            foreach($snapshot['raw_punch_ids']??[] as $id) $punchDates[(int)$id]=$row['work_date'];
        }
        $q=db()->prepare('SELECT pr.id,pr.request_no,pr.affected_date,pr.status,t.code type_code,t.name type_name,ow.started_at,ow.ended_at,te.time_in,te.lunch_out,te.lunch_in,te.time_out,te.destination,te.purpose
            FROM payroll_requests pr JOIN payroll_request_types t ON t.id=pr.request_type_id
            LEFT JOIN payroll_request_overtime_windows ow ON ow.request_id=pr.id
            LEFT JOIN payroll_request_time_entries te ON te.request_id=pr.id
            WHERE pr.employee_id=? AND pr.affected_date BETWEEN ? AND ? ORDER BY pr.affected_date,pr.id DESC');
        $q->execute([$employeeId,$run['period_start'],$run['period_end']]); $tickets=$q->fetchAll();
        $approved=[]; foreach(PayrollAttendanceService::cutoffTickets($cutoffId,$employeeId) as $ticket) $approved[(int)$ticket['id']]=$ticket;
        $ticketDays=[]; $obDays=[];
        foreach($tickets as $ticket) {
            $application=$approved[(int)$ticket['id']]??[];
            $ticket['application_state']=$application['application_state']??null;
            $ticket['application_note']=$application['application_note']??null;
            $ticketDays[$ticket['affected_date']][]=$ticket;
            if(isset($approved[(int)$ticket['id']]) && in_array($ticket['type_code'],['OB','POB'],true)) $obDays[$ticket['affected_date']][]=$ticket;
        }
        // Actual leave requests are separate from Leave Credit Correction tickets.
        // Read final approvals directly so existing leave appears without a re-import.
        $q=db()->prepare('SELECT lr.id,lr.request_no,lr.date_from,lr.date_to,lt.name leave_type_name
            FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id
            WHERE lr.employee_id=? AND lr.status="APPROVED" AND lr.date_from<=? AND lr.date_to>=?
            ORDER BY lr.date_from,lr.id');
        $q->execute([$employeeId,$run['period_end'],$run['period_start']]); $leaveDays=[];
        foreach($q->fetchAll() as $leave) {
            $start=max($leave['date_from'],$run['period_start']); $end=min($leave['date_to'],$run['period_end']);
            for($date=new DateTimeImmutable($start);$date<=new DateTimeImmutable($end);$date=$date->modify('+1 day')) {
                $leaveDays[$date->format('Y-m-d')][]=$leave;
            }
        }
        $q=db()->prepare('SELECT p.id,p.punched_at,p.punch_status,i.branch_id source_branch_id,i.original_name source_file,COALESCE(b.name,"Not supplied in export") source_location
            FROM attendance_punches p JOIN attendance_import_rows r ON r.id=p.import_row_id
            JOIN attendance_imports i ON i.id=r.import_id LEFT JOIN branches b ON b.id=i.branch_id
            WHERE p.employee_id=? AND i.state="IMPORTED" AND p.punched_at>=? AND p.punched_at<? ORDER BY p.punched_at,p.id');
        $q->execute([$employeeId,$run['period_start'].' 00:00:00',(new DateTimeImmutable($run['period_end']))->modify('+2 days')->format('Y-m-d').' 00:00:00']);
        $rawDays=[]; $logs=[];
        foreach($q->fetchAll() as $punch) {
            // Saved duty-day ownership is authoritative for overnight work and verified punches.
            $day=$punchDates[(int)$punch['id']]??self::punchDay($punch,$site,$ticketDays);
            if($day<$run['period_start'] || $day>$run['period_end']) continue;
            $rawDays[$day][]=$punch;
            // The simple logs tab shows actual device records, independently of
            // daily review / hire-date / arrived-day presentation restrictions.
            $logs[]=$punch;
        }
        $types=array_column(PayrollRepository::requestTypes(),'code');
        $closed=($run['run_state']??'')==='FINALIZED';
        $late=!empty($run['ticket_deadline']) && new DateTimeImmutable('now')>new DateTimeImmutable($run['ticket_deadline']);
        $canFile=Auth::can('payroll.request_self') && !$closed && !$late;
        // Older open days may contain conflicts from TA values entered for known slots.
        // Apply the missing-only rule to the display without writing attendance or changing a closed snapshot.
        if(!$closed) foreach($byDate as $date=>$row) {
            if(!empty($employee['hire_date']) && $date<$employee['hire_date']) continue;
            $byDate[$date]=self::missingOnlyDisplayRow($row,$ticketDays[$date]??[],$approved,$leaveDays[$date]??[],$obDays[$date]??[]);
        }
        // Display entries never become device punches or change a payroll snapshot.
        $entries=[]; $adjustments=[]; $obOutDays=[];
        foreach($logs as $punch) $entries[]=['kind'=>'BIOMETRIC','date'=>substr($punch['punched_at'],0,10),'punch'=>$punch];
        foreach($leaveDays as $date=>$leaves) $entries[]=['kind'=>'LEAVE','date'=>$date,'leaves'=>$leaves,
            'before_hire'=>!empty($employee['hire_date']) && $date<$employee['hire_date'],
            'closed_unapplied'=>$closed && ($byDate[$date]['state']??'')!=='APPROVED_LEAVE'];
        foreach($byDate as $date=>$row) {
            if(!empty($employee['hire_date']) && $date<$employee['hire_date']) continue;
            $sourceIds=array_map('intval',array_column(json_decode($row['sources_json']??'[]',true)?:[],'id'));
            $issues=json_decode($row['issues_json']??'[]',true)?:[];
            if(in_array('INVALID_PUNCH_SEQUENCE',$issues,true) || !self::validTimeOrder($row)) continue;
            $snapshot=json_decode($row['rate_snapshot_json']??'{}',true)?:[]; $daySite=$snapshot['site']??$site;
            foreach(self::LABELS as $field=>$label) {
                if(!empty($row['original_'.$field]) || empty($row[$field]) || in_array('CORRECTION_CONFLICT_'.strtoupper($field),$issues,true)) continue;
                $timeSources=[];
                foreach($ticketDays[$date]??[] as $ticket) {
                    $allowed=in_array($ticket['type_code'],['TA','PTA'],true)
                        || ($field==='time_out' && in_array($ticket['type_code'],['OB','POB'],true));
                    if(!$allowed || !isset($approved[(int)$ticket['id']]) || !in_array((int)$ticket['id'],$sourceIds,true) || empty($ticket[$field])) continue;
                    if(PayrollAttendanceReview::correctionTime($date,$ticket[$field],$daySite)===$row[$field]) $timeSources[]=$ticket;
                }
                if(!$timeSources) continue;
                $event=in_array($field,['time_in','lunch_in'],true)?'IN':'OUT'; $alreadyLogged=false;
                foreach($rawDays[$date]??[] as $punch) if($punch['punched_at']===$row[$field] && in_array($punch['punch_status'],[$event,strtoupper($field)],true)) { $alreadyLogged=true; break; }
                if($alreadyLogged) continue;
                $obs=$field==='time_out'?($obDays[$date]??[]):[];
                $entry=['kind'=>'ADJUSTMENT','date'=>substr($row[$field],0,10),'work_date'=>$date,
                    'punched_at'=>$row[$field],'field'=>$field,'event'=>$event,'time_sources'=>$timeSources,'obs'=>$obs,
                    'needs_review'=>$row['state']==='ISSUES',
                    'closed_unapplied'=>$closed && (bool)array_diff(array_map('intval',array_column($obs,'id')),$sourceIds)];
                $entries[]=$entry; $adjustments[]=$entry;
                if($obs) $obOutDays[$date]=true;
            }
        }
        foreach($obDays as $date=>$obs) {
            if(isset($obOutDays[$date])) continue;
            $row=$byDate[$date]??null; $sourceIds=array_map('intval',array_column(json_decode($row['sources_json']??'[]',true)?:[],'id'));
            $entries[]=['kind'=>'OB','date'=>$date,'work_date'=>$date,
                'time_out'=>null,'obs'=>$obs,'time_sources'=>[],
                'before_hire'=>!empty($employee['hire_date']) && $date<$employee['hire_date'],
                'needs_review'=>($row['state']??'')==='ISSUES',
                'closed_unapplied'=>$closed && (bool)array_diff(array_map('intval',array_column($obs,'id')),$sourceIds)];
        }
        usort($entries,static fn($a,$b)=>strcmp($a['date'],$b['date'])
            ?: strcmp($a['punch']['punched_at']??$a['punched_at']??'',$b['punch']['punched_at']??$b['punched_at']??'')
            ?: (($a['punch']['id']??0)<=>($b['punch']['id']??0)));
        $days=[]; $totals=['with_logs'=>0,'incomplete'=>0,'ot_review'=>0,'pending'=>0,'no_logs'=>0];
        foreach($tickets as $ticket) if(!in_array($ticket['status'],['APPROVED','COMPLETED','REJECTED','CANCELLED'],true)) $totals['pending']++;
        for($date=new DateTimeImmutable($run['period_start']);$date<=new DateTimeImmutable(min($run['period_end'],date('Y-m-d')));$date=$date->modify('+1 day')) {
            $key=$date->format('Y-m-d'); $row=$byDate[$key]??null; $raw=$rawDays[$key]??[]; $dayTickets=$ticketDays[$key]??[]; $dayLeaves=$leaveDays[$key]??[];
            $hasEvidence=$raw || $dayTickets || $dayLeaves || ($row && $row['state']!=='AWAITING_LOGS');
            $beforeHire=!empty($employee['hire_date']) && $key<$employee['hire_date'];
            // Preserve visible pre-hire evidence for HR review without creating paid attendance.
            if($beforeHire && !$hasEvidence) continue;
            $assignment=$row?null:PayrollCalculator::assign($raw,[]);
            $values=$row?array_intersect_key($row,self::LABELS):$assignment['original'];
            $issues=$row?(json_decode($row['issues_json']??'[]',true)?:[]):$assignment['issues'];
            $ambiguous=(bool)array_intersect($issues,['AMBIGUOUS_PUNCHES','EXTRA_PUNCHES_REVIEW','INVALID_PUNCH_SEQUENCE']);
            $missing=[]; foreach(self::LABELS as $field=>$label) if(empty($values[$field])) $missing[]=$label;
            $state=$row['state']??($raw?'ISSUES':'AWAITING_LOGS');
            $workTickets=array_filter($dayTickets,static fn($ticket)=>in_array($ticket['type_code'],['TA','PTA','OB','POB','OT','POT'],true) && in_array($ticket['status'],['APPROVED','COMPLETED'],true));
            $otherIssues=array_filter($issues,static fn($issue)=>!str_starts_with($issue,'MISSING_') && $issue!=='AWAITING_LOGS');
            // A leave-only day does not need four IN/OUT punches. Keep conflicts,
            // pre-hire evidence and finalized attendance under their existing review rules.
            if($dayLeaves && !$beforeHire && !$closed && !$raw && !$workTickets && !array_filter($values) && !$otherIssues) {
                $state='APPROVED_LEAVE'; $issues=[]; $missing=[]; $ambiguous=false;
            }
            $coveredByTicket=in_array($state,['APPROVED_OB','APPROVED_LEAVE','ZERO_CREDIT'],true);
            $incomplete=$hasEvidence && !$coveredByTicket && ($missing || $state==='ISSUES');
            $locations=[]; foreach($raw as $punch) $locations[(int)$punch['source_branch_id']]=$punch['source_location']??'Unknown location';
            $otherSite=(bool)array_filter(array_keys($locations),static fn($id)=>$id>0 && $id!==(int)($employee['branch_id']??0));
            $snapshot=$row?(json_decode($row['rate_snapshot_json']??'{}',true)?:[]):[];
            $daySite=$snapshot['site']??$site;
            $otMinutes=(!$missing && !$incomplete && !$coveredByTicket && !$beforeHire && $row)?self::outsideSchedule($key,$values,$daySite):0;
            $active=[];
            foreach($dayTickets as $ticket) if(!in_array($ticket['status'],['REJECTED','CANCELLED'],true)) {
                $family=match($ticket['type_code']) {'TA','PTA'=>'TA','OB','POB'=>'OB','OT','POT'=>'OT',default=>$ticket['type_code']};
                $active[$family]??=$ticket;
            }
            $otReview=$otMinutes>0 && !isset($active['OT']);
            $day=['date'=>$key,'row'=>$row,'values'=>$values,'issues'=>$issues,'raw'=>$raw,'tickets'=>$dayTickets,'leaves'=>$dayLeaves,
                'locations'=>$locations,'other_site'=>$otherSite,'missing'=>$missing,'ambiguous'=>$ambiguous,'incomplete'=>(bool)$incomplete,
                'before_hire'=>$beforeHire,'has_evidence'=>(bool)$hasEvidence,'state'=>$state,'ot_minutes'=>$otMinutes,'ot_review'=>$otReview,
                'can_file'=>$canFile&&!$beforeHire,'active'=>$active];
            $days[]=$day;
            if($raw) $totals['with_logs']++;
            if($incomplete) $totals['incomplete']++;
            if($otReview) $totals['ot_review']++;
            if(!$hasEvidence) $totals['no_logs']++;
        }
        $q=db()->prepare('SELECT c.id,c.period_start,c.period_end FROM payroll_cutoffs c WHERE
            EXISTS (SELECT 1 FROM attendance_daily d WHERE d.cutoff_id=c.id AND d.employee_id=? AND d.state<>"AWAITING_LOGS")
            OR EXISTS (SELECT 1 FROM attendance_punches p JOIN attendance_import_rows r ON r.id=p.import_row_id JOIN attendance_imports i ON i.id=r.import_id WHERE i.cutoff_id=c.id AND i.state="IMPORTED" AND p.employee_id=?)
            OR EXISTS (SELECT 1 FROM leave_requests lr WHERE lr.employee_id=? AND lr.status="APPROVED" AND lr.date_from<=c.period_end AND lr.date_to>=c.period_start)
            OR EXISTS (SELECT 1 FROM payroll_requests pr JOIN payroll_request_types t ON t.id=pr.request_type_id WHERE pr.employee_id=? AND pr.cutoff_id=c.id AND pr.affected_date BETWEEN c.period_start AND c.period_end AND t.code IN ("TA","PTA","OB","POB") AND pr.status IN ("APPROVED","COMPLETED") AND NOT EXISTS (SELECT 1 FROM payroll_backpay_claims bc WHERE bc.request_id=pr.id) AND EXISTS (SELECT 1 FROM payroll_request_approvals a WHERE a.request_id=pr.id AND a.approver_type IN ("MANAGER","ADL") AND a.status="APPROVED"))
            ORDER BY c.period_start DESC LIMIT 1');
        $q->execute([$employeeId,$employeeId,$employeeId,$employeeId]); $latest=$q->fetch()?:null;
        $q=db()->prepare('SELECT c.id,c.period_start,c.period_end FROM payroll_cutoffs c WHERE
            EXISTS (SELECT 1 FROM attendance_punches p JOIN attendance_import_rows r ON r.id=p.import_row_id JOIN attendance_imports i ON i.id=r.import_id WHERE i.cutoff_id=c.id AND i.state="IMPORTED" AND p.employee_id=?)
            OR EXISTS (SELECT 1 FROM leave_requests lr WHERE lr.employee_id=? AND lr.status="APPROVED" AND lr.date_from<=c.period_end AND lr.date_to>=c.period_start)
            OR EXISTS (SELECT 1 FROM payroll_requests pr JOIN payroll_request_types t ON t.id=pr.request_type_id WHERE pr.employee_id=? AND pr.cutoff_id=c.id AND pr.affected_date BETWEEN c.period_start AND c.period_end AND t.code IN ("TA","PTA","OB","POB") AND pr.status IN ("APPROVED","COMPLETED") AND NOT EXISTS (SELECT 1 FROM payroll_backpay_claims bc WHERE bc.request_id=pr.id) AND EXISTS (SELECT 1 FROM payroll_request_approvals a WHERE a.request_id=pr.id AND a.approver_type IN ("MANAGER","ADL") AND a.status="APPROVED"))
            ORDER BY c.period_start DESC LIMIT 1');
        $q->execute([$employeeId,$employeeId,$employeeId]); $latestEntries=$q->fetch()?:null;
        return ['employee'=>$employee,'run'=>$run,'days'=>$days,'logs'=>$logs,'adjustments'=>$adjustments,'leave_days'=>$leaveDays,'ob_days'=>$obDays,'entries'=>$entries,'totals'=>$totals,'types'=>$types,'closed'=>$closed,'deadline_passed'=>$late,'latest_cutoff'=>$latest,'latest_entry_cutoff'=>$latestEntries];
    }

    /** Read-only compatibility for TA approvals saved before the missing-only rule. */
    private static function missingOnlyDisplayRow(array $row,array $tickets,array $approved,array $leaves,array $obs): array
    {
        $snapshot=json_decode($row['rate_snapshot_json']??'{}',true)?:[];
        if(!empty($snapshot['missing_punches_only'])) return $row;
        $sourceIds=array_map('intval',array_column(json_decode($row['sources_json']??'[]',true)?:[],'id'));
        $hasTa=false;
        foreach($tickets as $ticket) if(isset($approved[(int)$ticket['id']]) && in_array((int)$ticket['id'],$sourceIds,true) && in_array($ticket['type_code'],['TA','PTA'],true)) { $hasTa=true; break; }
        if(!$hasTa) return $row;
        $issues=json_decode($row['issues_json']??'[]',true)?:[]; $ignoredConflict=false;
        foreach(PayrollCalculator::FIELDS as $field) if(!empty($row['original_'.$field])) {
            $row[$field]=$row['original_'.$field];
            $issue='CORRECTION_CONFLICT_'.strtoupper($field);
            if(in_array($issue,$issues,true)) { $issues=array_values(array_diff($issues,[$issue])); $ignoredConflict=true; }
        }
        if($ignoredConflict && self::validTimeOrder($row)) $issues=array_values(array_diff($issues,['INVALID_PUNCH_SEQUENCE']));
        $row['issues_json']=json_encode($issues,JSON_THROW_ON_ERROR);
        $blocking=array_diff($issues,['LATE','UNDERTIME','EXCESS_BREAK']);
        if($ignoredConflict && $row['state']==='ISSUES' && !$blocking) {
            $added=false; foreach(PayrollCalculator::FIELDS as $field) if(empty($row['original_'.$field]) && !empty($row[$field])) $added=true;
            $row['state']=$leaves?'APPROVED_LEAVE':($obs?'APPROVED_OB':($added?'CORRECTED':'COMPLETE'));
        }
        return $row;
    }

    private static function validTimeOrder(array $row): bool
    {
        $previous=null; $previousField=null;
        foreach(PayrollCalculator::FIELDS as $field) if(!empty($row[$field])) {
            if($previous!==null && ($row[$field]<$previous || ($row[$field]===$previous && !($previousField==='lunch_out' && $field==='lunch_in')))) return false;
            $previous=$row[$field]; $previousField=$field;
        }
        return true;
    }

    private static function punchDay(array $punch,array $site,array $tickets): string
    {
        $day=substr($punch['punched_at'],0,10); $clock=substr($punch['punched_at'],11);
        if(!empty($site['shift_start']) && !empty($site['shift_end']) && $site['shift_end']<$site['shift_start'] && $clock<$site['shift_start']) return (new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
        $previous=(new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
        foreach($tickets[$previous]??[] as $ticket) if(in_array($ticket['type_code'],['OT','POT'],true) && in_array($ticket['status'],['APPROVED','COMPLETED'],true)
            && !empty($ticket['started_at']) && !empty($ticket['ended_at']) && substr($ticket['ended_at'],0,10)===$day
            && $punch['punched_at']>=$ticket['started_at'] && $punch['punched_at']<=$ticket['ended_at']) return $previous;
        return $day;
    }

    /** Advisory only: time outside a known schedule is not an approved/payable OT claim. */
    private static function outsideSchedule(string $day,array $values,array $site): int
    {
        if(empty($site['shift_start']) || empty($site['shift_end'])) return 0;
        [$start,$end]=PayrollCalculator::window($day,$site); $minutes=0;
        $in=new DateTimeImmutable($values['time_in']); $out=new DateTimeImmutable($values['time_out']);
        $lunchOut=new DateTimeImmutable($values['lunch_out']); $lunchIn=new DateTimeImmutable($values['lunch_in']);
        if(!($in<$lunchOut && $lunchOut<=$lunchIn && $lunchIn<$out)) return 0;
        foreach([[$in,$lunchOut],[$lunchIn,$out]] as [$a,$b]) {
            if($a<$start) $minutes+=max(0,(int)floor((min($b,$start)->getTimestamp()-$a->getTimestamp())/60));
            if($b>$end) $minutes+=max(0,(int)floor(($b->getTimestamp()-max($a,$end)->getTimestamp())/60));
        }
        return $minutes;
    }
}
