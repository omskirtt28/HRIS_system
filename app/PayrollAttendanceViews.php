<?php
declare(strict_types=1);

function payroll_review_ot_minutes(array $row): int
{
    return (int)$row['regular_ot_minutes']+(int)$row['night_ot_minutes']+(int)$row['holiday_ot_minutes']+(int)$row['holiday_night_ot_minutes'];
}

function payroll_review_page(string $page,string $kind,array $cutoffs,int $cutoffId,array $run,array $rows,bool $employeePage,?array $employee,string $filter): void
{
    $employeeId=$employeePage?(int)$employee['id']:null;
    $tickets=PayrollAttendanceService::cutoffTickets($cutoffId,$employeeId);
    $claims=PayrollAttendanceService::backpayRows($cutoffId);
    if($employeePage) $claims=array_values(array_filter($claims,static fn($c)=>(int)$c['employee_id']===$employeeId));
    $totals=['issues'=>0,'no_logs'=>0,'ot'=>0]; $summary=[]; $needsRefresh=false;
    foreach($rows as $r) {
        if($r['state']==='ISSUES') $totals['issues']++;
        if($r['state']==='AWAITING_LOGS') $totals['no_logs']++;
        $snapshot=json_decode($r['rate_snapshot_json'],true)?:[];
        if(($snapshot['workflow']??'')!=='ATTENDANCE_REVIEW_ONLY' && $run['run_state']!=='FINALIZED') $needsRefresh=true;
        $ot=payroll_review_ot_minutes($r); $totals['ot']+=$ot;
        $key=(int)$r['employee_id'];
        if(!isset($summary[$key])) $summary[$key]=['name'=>$r['employee_name'],'no'=>$r['employee_no'],'worked'=>0,'ot'=>0,'issues'=>0,'no_logs'=>0];
        $summary[$key]['worked']+=(int)$r['regular_minutes']; $summary[$key]['ot']+=$ot;
        if($r['state']==='ISSUES') $summary[$key]['issues']++;
        if($r['state']==='AWAITING_LOGS') $summary[$key]['no_logs']++;
    }
    render_portal_header($kind,$page,$employeePage?'My Attendance':'Payroll Cutoff');
    $actions=!$employeePage&&Auth::can('payroll.biometric_import')?'<a class="btn" href="'.url('payroll-import',['cutoff_id'=>$cutoffId]).'">Import biometrics</a>':'';
    page_head($employeePage?'Employee Self-Service / Attendance':'HR Payroll / Attendance Review',$employeePage?'My Attendance':'Payroll Cutoff Review',$actions);
    payroll_cutoff_selector($cutoffs,$cutoffId,$page); ?>
    <div class="alert info"><?php if($employeePage):?>Approved ticket times appear in your matching attendance day. Use View day to see the original logs and applied corrections.<?php else:?>3. Review daily attendance and approved tickets here. Manager/ADL-approved corrections appear in the matching day, even when the original biometric file has no row for that date. Salary and OT amounts are computed manually.<?php endif;?></div>
    <?php if($run['run_state']==='FINALIZED'):?><p class="small muted">This attendance cutoff is locked. Historical hours retain their saved calculation; no monetary amounts are shown.</p><?php endif;?>
    <?php if($needsRefresh):?><div class="alert amber">This cutoff has records from the previous calculation. Click <strong>Refresh attendance</strong> to use the simplified flow.</div><?php endif;?>
    <div class="payroll-metrics"><?php metric_card('Attendance records',count($rows),'This cutoff','clock'); metric_card('Attendance issues',$totals['issues'],'Missing punches or conflicts','alert'); metric_card('Approved tickets',count($tickets),'Final Manager / ADL approval','check-square'); metric_card('Matched OT hours',$needsRefresh?'Refresh required':payroll_hours($totals['ot']),'Approved windows matched to logs','briefcase'); ?></div>
    <div class="payroll-banner"><strong><?=e($run['run_state']??'DRAFT')?></strong><span>All required files confirmed through: <?=e($run['covered_through']??'Awaiting complete uploads / confirmation')?> · Ticket/approval deadline: <?=e($run['ticket_deadline']??'Not set')?></span></div>
    <?php if($totals['no_logs']):?><p class="small muted"><?=$totals['no_logs']?> date(s) have no logs or approved attendance tickets. HR checks the work calendar manually; these dates are not automatically declared absent or paid.</p><?php endif;?>
    <?php if(!$employeePage):
        $logSources=PayrollAttendanceService::sourceCoverage($cutoffId);
        $coverageOld=$_SESSION['payroll_form_old']??[];
        $coverageOld=($coverageOld['action']??'')==='payroll_v2_coverage' && (int)($coverageOld['cutoff_id']??0)===$cutoffId?$coverageOld:[]; ?>
    <section class="panel payroll-section">
      <div class="panel-head"><div><h2>Received biometric exports</h2><p>Imported logs are already available to matched employees. Confirm that all required MIS / Sales Captain files are received before finalizing this cutoff. Employee 201 determines each employee's workplace and approver.</p></div></div>
      <div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Source file / known device location</th><th>Imported exports</th><th>Payroll confirmed through</th></tr></thead><tbody>
      <?php if(!$logSources):?><tr><td colspan="3" class="empty">No biometric exports imported yet.</td></tr><?php endif; foreach($logSources as $source):?><tr><td><?=e($source['source_location'])?></td><td><?=(int)$source['export_count']?></td><td><?=e($source['covered_through']??'Awaiting coverage')?></td></tr><?php endforeach;?></tbody></table></div>
      <?php if($logSources && $run['run_state']!=='FINALIZED' && Auth::can('payroll.process')):?>
      <form method="post" class="panel-body payroll-form" data-payroll-confirm="Confirm that all required MIS / Sales Captain files are received and imported through this date? Dates without logs remain for manual work-calendar review."><?php payroll_hidden_action('coverage');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>">
        <div class="field"><label>All required files received through</label><input type="date" name="covered_through" min="<?=e($run['period_start'])?>" max="<?=e(min($run['period_end'],date('Y-m-d',strtotime('-1 day'))))?>" value="<?=e($coverageOld['covered_through']??$run['covered_through']??'')?>" required><small>This confirmation is for finalizing the cutoff. Employee log visibility is automatic after each import. New imports require confirmation again.</small></div><button class="btn">Confirm all received files</button>
      </form><?php endif; if($coverageOld) unset($_SESSION['payroll_form_old']);?>
    </section>
    <div class="payroll-toolbar">
      <a class="btn" href="<?=url($page,['cutoff_id'=>$cutoffId,'filter'=>$filter==='ISSUES'?'':'ISSUES'])?>"><?=$filter==='ISSUES'?'Show all days':'Show dates needing review'?></a>
      <?php if(Auth::can('payroll.export')):?><a class="btn" href="<?=url('payroll-export',['cutoff_id'=>$cutoffId])?>">Export attendance CSV</a><?php endif;?>
      <?php if($run['run_state']!=='FINALIZED'):?><form method="post"><?php payroll_hidden_action('rebuild');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><button class="btn">Refresh attendance</button></form>
      <?php if(Auth::can('payroll.finalize')):?><form method="post" data-payroll-confirm="Finalize and lock this attendance review? Dates without logs remain unclassified. Salary and OT amounts are reviewed manually outside this attendance export."><?php payroll_hidden_action('finalize');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><button class="btn primary">Finalize attendance</button></form><?php endif; endif;?>
    </div>
    <?php if($run['run_state']!=='FINALIZED'):?><section class="panel payroll-section"><div class="panel-head"><div><h2>Ticket and approval deadline</h2><p>HR Payroll sets the cutoff deadline. Late filing or approval requires an extension before finalization.</p></div></div><form method="post" class="panel-body payroll-inline-form"><?php payroll_hidden_action('deadline');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><div class="field"><label>Deadline (Asia/Manila)</label><input type="datetime-local" name="ticket_deadline" value="<?=e(!empty($run['ticket_deadline'])?date('Y-m-d\TH:i',strtotime($run['ticket_deadline'])):'')?>"></div><button class="btn">Save deadline</button></form></section><?php endif;?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Employee attendance totals</h2><p>Work hours exclude the actual lunch interval. Matched OT is included in work hours; it is shown separately for manual Payroll review.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Recorded work hours</th><th>Matched OT hours</th><th>Issue dates</th><th>No-log dates</th></tr></thead><tbody><?php if(!$summary):?><tr><td colspan="5" class="empty">Import biometric logs to generate attendance records. Approved tickets are listed below.</td></tr><?php endif; foreach($summary as $s):?><tr><td><strong><?=e($s['name'])?></strong><small><?=e($s['no'])?></small></td><td><?=$needsRefresh?'Refresh required':payroll_hours($s['worked'])?></td><td><?=$needsRefresh?'Refresh required':payroll_hours($s['ot'])?></td><td><?=$s['issues']?></td><td><?=$s['no_logs']?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php endif;?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2><?=$employeePage?'My approved tickets':'Approved tickets for HR Payroll'?></h2><p>Manager/ADL approval applies matching corrections automatically. Existing biometric values remain protected; conflicts require verification.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><?php if(!$employeePage):?><th>Employee</th><?php endif;?><th>Ticket</th><th>Affected date</th><th>Attendance matching</th><th>Review note</th></tr></thead><tbody>
    <?php if(!$tickets):?><tr><td colspan="<?=$employeePage?4:5?>" class="empty">No final Manager/ADL-approved tickets for this cutoff yet.</td></tr><?php endif; foreach($tickets as $ticket):?><tr><?php if(!$employeePage):?><td><?=e($ticket['employee_name'])?><small><?=e($ticket['employee_no'])?></small></td><?php endif;?><td><a href="<?=url('payroll-request',['id'=>$ticket['id']])?>"><?=e($ticket['request_no'].' · '.$ticket['type_code'])?></a></td><td><?=e($ticket['affected_date'])?></td><td><span class="badge <?=$ticket['application_state']==='APPLIED'?'green':'amber'?>"><?=e(stage_label($ticket['application_state']))?></span></td><td><?=e($ticket['application_note']??'Approved; waiting for attendance matching.')?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php if($claims):?><section class="panel payroll-section"><div class="panel-head"><div><h2>Previous unpaid claims</h2><p>Approved claims enter this processing cutoff for manual HR Payroll amount review. Original finalized attendance stays locked.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Ticket</th><th>Affected date</th><th>Approval status</th></tr></thead><tbody><?php foreach($claims as $claim):?><tr><td><?=e($claim['employee_name'])?></td><td><a href="<?=url('payroll-request',['id'=>$claim['request_id']])?>"><?=e($claim['request_no'].' · '.$claim['type_code'])?></a></td><td><?=e($claim['affected_date'])?></td><td><?=e(stage_label($claim['request_status']))?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Daily attendance</h2><p>Four punches can come from different locations. Lunch timing is flexible; both Lunch Out and Lunch In are required. Head Office uses 8 AM–5 PM and a 60-minute break.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><?php if(!$employeePage):?><th>Employee</th><?php endif;?><th>Date</th><th>Time In</th><th>Lunch Out</th><th>Lunch In</th><th>Time Out</th><th>Status / issues</th><th>Recorded work hours</th><th>Matched OT hours</th><th></th></tr></thead><tbody>
    <?php if(!$rows):?><tr><td colspan="<?=$employeePage?9:10?>" class="empty">No attendance records for this selection.</td></tr><?php endif; foreach($rows as $r): $noLogs=$r['state']==='AWAITING_LOGS'; $issue=$r['state']==='ISSUES'; ?>
    <tr class="<?=$issue?'payroll-issue-row':''?>"><?php if(!$employeePage):?><td><strong><?=e($r['employee_name'])?></strong><small><?=e($r['employee_no'].' · '.$r['branch_name'])?></small></td><?php endif;?><td><?=e($r['work_date'])?></td><?php foreach(PayrollCalculator::FIELDS as $field):?><td class="<?=$r[$field]===null?'payroll-missing':''?>"><?=payroll_clock($r[$field])?><?php if($r[$field]!==$r['original_'.$field]):?><small>Adjusted</small><?php endif;?></td><?php endforeach;?><td><span class="badge <?=$noLogs?'gray':($issue?'amber':'green')?>"><?=e($noLogs?'No logs / calendar review':stage_label($r['state']))?></span><small><?=e(implode(', ',array_map('stage_label',json_decode($r['issues_json'],true)?:[])))?></small></td><td><?=$needsRefresh?'Refresh required':($noLogs?'—':payroll_hours($r['regular_minutes']))?></td><td><?=$needsRefresh?'Refresh required':($noLogs?'—':payroll_hours(payroll_review_ot_minutes($r)))?></td><td><a class="btn sm" href="<?=url('payroll-attendance-day',['id'=>$r['id']])?>">View day</a><?php if($employeePage && $run['run_state']!=='FINALIZED'):?><a class="btn primary sm" href="<?=url('employee-request-new',['date'=>$r['work_date'],'type'=>'TA'])?>">File TA</a><?php endif;?></td></tr>
    <?php endforeach;?></tbody></table></div></section>
    <?php render_portal_footer();
}

function payroll_identity_mapping(): void
{
    Auth::requirePermission('payroll.configure');
    $employees=db()->query('SELECT id,employee_no,first_name,last_name FROM employees ORDER BY last_name,first_name')->fetchAll();
    $maps=db()->query('SELECT m.*,e.employee_no,CONCAT_WS(" ",e.first_name,e.last_name) employee_name FROM payroll_biometric_mappings m JOIN employees e ON e.id=m.employee_id ORDER BY e.last_name,e.first_name')->fetchAll();
    $unknown=PayrollAttendanceService::unmappedBiometricIds(); $old=$_SESSION['payroll_form_old']??[];
    $old=($old['action']??'')==='payroll_v2_mapping'?$old:[];
    render_portal_header('hr','payroll-import','Biometric ID Matching');
    page_head('HR Payroll / Biometric Import','Match Biometric IDs','<a class="btn" href="'.url('payroll-import').'">Back to imports</a>'); ?>
    <p class="small muted">Exact employee numbers match automatically. Use this form only when the biometric ID differs from Employee 201. Schedule, salary and OT rates are not required.</p>
    <?php if($unknown):?><div class="alert amber">Unknown biometric IDs: <?=e(implode(', ',$unknown))?></div><?php endif;?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Match an ID to the correct employee</h2><p>Imported unknown rows are linked after saving. Employee names are never used to guess ownership.</p></div></div><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('mapping');?><div class="field"><label>Employee</label><select name="employee_id" required><option value="">Select employee</option><?php payroll_employee_options($employees,(int)($old['employee_id']??0));?></select></div><div class="field"><label>Biometric ID</label><input name="biometric_id" maxlength="50" value="<?=e($old['biometric_id']??'')?>" required></div><button class="btn primary">Save ID match</button></form></section>
    <section class="panel payroll-section"><div class="panel-head"><h2>Saved biometric ID matches</h2></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Employee no.</th><th>Biometric ID</th></tr></thead><tbody><?php if(!$maps):?><tr><td colspan="3" class="empty">No saved matches yet.</td></tr><?php endif; foreach($maps as $map):?><tr><td><?=e($map['employee_name'])?></td><td><?=e($map['employee_no'])?></td><td><?=e($map['biometric_id'])?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php if($old) unset($_SESSION['payroll_form_old']); render_portal_footer();
}

function payroll_export(int $cutoffId): never
{
    Auth::requirePermission('payroll.export'); PayrollAttendanceService::requireReady();
    $run=PayrollAttendanceService::run($cutoffId);
    $rows=PayrollAttendanceService::dailyRows($cutoffId);
    foreach($rows as $row) {
        $snapshot=json_decode($row['rate_snapshot_json'],true)?:[];
        if($run['run_state']!=='FINALIZED' && ($snapshot['workflow']??'')!=='ATTENDANCE_REVIEW_ONLY') {
            flash('error','Refresh attendance before exporting this cutoff in the simplified format.'); redirect('payroll-cutoff',['cutoff_id'=>$cutoffId]);
        }
    }
    audit('Payroll','EXPORT_ATTENDANCE','payroll_cutoff',$cutoffId,['state'=>$run['run_state']??'DRAFT','monetary_computation'=>'DEFERRED']);
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="attendance-'.$run['period_start'].'-'.$run['period_end'].'.csv"'); header('Cache-Control: no-store');
    $f=fopen('php://output','wb'); fwrite($f,"\xEF\xBB\xBF");
    $columns=['Review mode','Cutoff state','Employee no.','Employee','Assigned site','Date','Attendance status','Original Time In','Original Lunch Out','Original Lunch In','Original Time Out','Effective Time In','Effective Lunch Out','Effective Lunch In','Effective Time Out','Recorded work hours (includes matched OT)','Late minutes','Undertime minutes','Matched approved OT hours','Issues','Approved ticket(s)','Review note'];
    fputcsv($f,$columns,',','"','');
    $write=static function(array $cells) use($f): void {
        foreach($cells as &$value) if(is_string($value)&&preg_match('/^[=+@\-\t\r]/',$value)) $value="'".$value; unset($value);
        fputcsv($f,$cells,',','"','');
    };
    foreach($rows as $row) {
        $snapshot=json_decode($row['rate_snapshot_json'],true)?:[];
        $historical=($snapshot['workflow']??'')!=='ATTENDANCE_REVIEW_ONLY';
        $noLogs=$row['state']==='AWAITING_LOGS';
        $hasSchedule=(bool)($snapshot['has_schedule']??!empty($snapshot['site']['shift_start']));
        $note=$historical?'Historical locked snapshot; hours retain the previous calculation. Amounts are excluded.':($noLogs?'No logs; HR checks the work calendar manually. No automatic absence or pay decision.':'Actual attendance and approved corrections. Monetary computation deferred.');
        $write(['ATTENDANCE_REVIEW_ONLY',$run['run_state']??'DRAFT',$row['employee_no'],$row['employee_name'],$row['branch_name'],$row['work_date'],$row['state'],
            $row['original_time_in'],$row['original_lunch_out'],$row['original_lunch_in'],$row['original_time_out'],$row['time_in'],$row['lunch_out'],$row['lunch_in'],$row['time_out'],
            $noLogs?'':payroll_hours($row['regular_minutes']),$hasSchedule&&!$noLogs?$row['late_minutes']:'',$hasSchedule&&!$noLogs?$row['undertime_minutes']:'',$noLogs?'':payroll_hours(payroll_review_ot_minutes($row)),
            implode('; ',json_decode($row['issues_json'],true)?:[]),implode('; ',array_filter(array_column(json_decode($row['sources_json'],true)?:[],'no'))),$note]);
    }
    // Include every approved ticket, even when a matching attendance day is not available yet.
    foreach(PayrollAttendanceService::cutoffTickets($cutoffId) as $ticket) {
        $cells=array_fill(0,count($columns),'');
        $cells[0]='ATTENDANCE_REVIEW_ONLY'; $cells[1]=$run['run_state']??'DRAFT'; $cells[2]=$ticket['employee_no']; $cells[3]=$ticket['employee_name']; $cells[5]=$ticket['affected_date'];
        $cells[6]=$ticket['processing_cutoff_id']?'HISTORICAL_CLAIM_FOR_MANUAL_REVIEW':'APPROVED_TICKET_'.$ticket['application_state']; $cells[20]=$ticket['request_no'].' / '.$ticket['type_code']; $cells[21]=$ticket['application_note'];
        $write($cells);
    }
    fclose($f); exit;
}
