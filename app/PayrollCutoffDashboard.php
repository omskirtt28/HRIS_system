<?php
declare(strict_types=1);

function payroll_cutoff_export_panel(int $cutoffId, ?array $model=null): void
{
    if(!PayrollCutoffExportService::ready()) { ?><div class="alert amber">Install <strong>20261011_payroll_cutoff_exports.sql</strong> to enable attendance generation and Excel / PDF downloads.</div><?php return; }
    try {$model??=PayrollCutoffExportService::model($cutoffId);}catch(RuntimeException $error){?><div class="alert error"><?=e($error->getMessage())?></div><?php return;}
    $run=$model['run'];$batch=$model['batch'];$groups=PayrollCutoffDocuments::sections($model);
    $legacy=$run['run_state']==='FINALIZED'&&!$batch;
    $open=$run['period_end']>=date('Y-m-d');
    $deadlineOpen=!empty($run['ticket_deadline'])&&$run['ticket_deadline']>date('Y-m-d H:i:s');
    $coverageDone=!empty($run['covered_through'])&&$run['covered_through']>=$run['period_end'];
    $pending=(int)$model['pending']+(int)$model['pending_leave'];
    // Presentation only: future / unconfirmed no-log dates remain generation blockers.
    // They are waiting for files, not automatic employee mistakes or absences.
    $calendarReady=static fn($day)=>$day['work_date']<date('Y-m-d')&&!empty($run['covered_through'])&&$day['work_date']<=$run['covered_through'];
    $calendar=array_values(array_filter($model['rows'],static fn($day)=>in_array($day['state'],['AWAITING_LOGS','REST_DAY','NO_WORK','ABSENT'],true)&&$calendarReady($day)));
    $toReview=count(array_filter($calendar,static fn($day)=>$day['state']==='AWAITING_LOGS'));
    $waiting=count($model['calendar'])-$toReview;
    $problems=count($model['problems']);
    $blocked=$open||$deadlineOpen||!$coverageDone||$pending||$model['unmapped']||$model['unapplied']||$problems||count($model['calendar'])||!$model['employees'];
    $canGenerate=Auth::can('payroll.finalize')&&Auth::can('payroll.export');
    $canProcess=Auth::can('payroll.process');
    $groupStatus=$batch?'Exported':($legacy?'Locked':($blocked?'In progress':'Ready'));
    $title=$batch?'Attendance is ready to download':($legacy?'This cutoff is already locked':($open?'Waiting for the cutoff to end':($deadlineOpen?'Employee filing is still open':'Finish the final check')));
    $label=$batch?'Generated':($legacy?'Locked':($open||$deadlineOpen?'In progress':($blocked?'Final check':'Ready to generate'))); ?>
    <div class="cutoff-friendly-dashboard">
      <p class="hr-payroll-intro">Check attendance, then download everyone’s records.</p>
      <nav class="cutoff-work-steps" aria-label="Cutoff progress">
        <span class="<?=$coverageDone||$batch?'is-done':'is-current'?>"><b><?=$coverageDone||$batch?icon_svg('check'):'1'?></b>Receive logs</span>
        <span class="<?=!$open&&!$batch&&!$legacy?'is-current':''?>"><b><?=$batch?icon_svg('check'):'2'?></b>Check attendance</span>
        <span class="<?=$batch?'is-current':''?>"><b>3</b>Download</span>
      </nav>
      <section class="panel cutoff-friendly-panel">
        <div class="panel-head"><div><h2><?=e($title)?></h2><p><?=$batch?'All employees included, grouped by department and branch.':($open?'The cutoff ends '.e(date('M j, Y',strtotime($run['period_end']))).'. Employees can still file requests.':($deadlineOpen?'Filing closes '.e(date('M j, g:i A',strtotime($run['ticket_deadline']))).'.':'Check the items below before generating attendance.'))?></p></div><span class="badge <?=$batch||!$blocked?'green':'amber'?>"><?=e($label)?></span></div>
        <div class="cutoff-friendly-stats"><div><strong><?=count($model['employees'])?></strong><span>Employees included</span></div><?php if(!$open&&!$batch&&!$legacy):?><div><strong><?=$problems?></strong><span>Dates with time issues</span></div><div><strong><?=$pending?></strong><span>Waiting for approval</span></div><?php endif;?></div>
        <?php if(!$model['employees']):?><p class="panel-body small muted">No employees are included in this cutoff yet. Add or import employee records before generating attendance.</p><?php endif;?>
        <?php if($batch):?>
        <div class="cutoff-download-actions"><div><strong>Download attendance</strong><p class="small muted">Saved <?=e(date('M j, Y · g:i A',strtotime($batch['generated_at'])))?></p></div><div class="cutoff-action-links"><?php if(Auth::can('payroll.export')):?><a class="btn primary" href="<?=url('payroll-export',['cutoff_id'=>$cutoffId,'format'=>'xlsx'])?>"><?=icon_svg('table')?>Download Excel</a><a class="btn" href="<?=url('payroll-export',['cutoff_id'=>$cutoffId,'format'=>'pdf'])?>"><?=icon_svg('file')?>Download PDF</a><?php endif;?><a class="btn" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId,'details'=>'1'])?>">View attendance</a></div></div>
        <p class="cutoff-friendly-foot">Yellow shows approved TA / OB / OT changes. Record payment separately below.</p>
        <?php elseif($legacy):?>
        <div class="panel-body"><p class="small muted">This cutoff was locked before the grouped Excel / PDF update. Its original CSV remains available.</p><?php if(Auth::can('payroll.export')):?><a class="btn" href="<?=url('payroll-export',['cutoff_id'=>$cutoffId])?>">Download older CSV</a><?php endif;?></div>
        <?php else:?>
        <div class="cutoff-friendly-subhead"><h3><?=$open?'What to do next':'Before you generate'?></h3></div>
        <div class="cutoff-friendly-checklist">
          <div class="cutoff-friendly-check-row"><div><?=icon_svg($open||$deadlineOpen?'clock':'circle-check',$open||$deadlineOpen?'is-waiting':'is-done')?><span><strong><?=$open?'Cutoff is still open':($deadlineOpen?'Filing is still open':'Cutoff has ended')?></strong><small><?=$open?'Employees can still file their requests.':($deadlineOpen?'Wait until the filing deadline ends.':'Review the remaining items before downloading.')?></small></span></div><span class="badge <?=$open||$deadlineOpen?'amber':'green'?>"><?=$open?'Until '.e(date('M j',strtotime($run['period_end']))):($deadlineOpen?'Waiting':'Done')?></span></div>
          <div class="cutoff-friendly-check-row"><div><?=icon_svg($coverageDone?'circle-check':'files',$coverageDone?'is-done':'is-waiting')?><span><strong>Biometric files</strong><small><?=$coverageDone?'All required files confirmed through '.e(date('M j',strtotime($run['covered_through']))).'.':'Receive and confirm all MIS / Sales Captain files.'?></small></span></div><div class="cutoff-action-links"><?php if($coverageDone):?><span class="badge green">Done</span><?php else:if(Auth::can('payroll.biometric_import')):?><a class="btn sm" href="<?=url('payroll-import',['cutoff_id'=>$cutoffId])?>"><?=icon_svg('files')?>Import logs</a><?php endif;if($canProcess):?><a class="btn sm" href="#cutoff-files" data-open-cutoff-details>Check files</a><?php endif;endif;?></div></div>
          <div class="cutoff-friendly-check-row"><div><?=icon_svg($pending||$problems||!$coverageDone?'clipboard':'circle-check',$pending||$problems||!$coverageDone?'is-waiting':'is-done')?><span><strong>Requests and missing times</strong><small><?php if($pending||$problems):?><?=$pending?> requests waiting for approval · <?=$problems?> dates with time issues.<?php else:?><?=$coverageDone?'All approvals are done. No missing or conflicting times.':'Check requests as files arrive. Time checks continue after upload.'?><?php endif;?></small></span></div><div class="cutoff-action-links"><?php if($pending&&Auth::can('payroll.approve_hr')):?><a class="btn sm" href="<?=url('hr-timekeeping')?>">Review requests</a><?php endif;if($model['pending_leave']&&Auth::can('leave.approve_hr')):?><a class="btn sm" href="<?=url('hr-leave')?>">Review leave</a><?php endif;if($problems):?><a class="btn sm" href="#cutoff-time-issues" data-open-cutoff-details>Check times</a><?php endif;if(!$pending&&!$problems):?><?php if($open&&Auth::can('payroll.approve_hr')):?><a class="btn sm" href="<?=url('hr-timekeeping')?>">Review requests</a><?php else:?><span class="badge <?=$coverageDone?'green':'gray'?>"><?=$coverageDone?'Done':'Waiting for logs'?></span><?php endif;?><?php elseif($pending&&!Auth::can('payroll.approve_hr')&&!$problems):?><span class="badge amber">Waiting for approval</span><?php endif;?></div></div>
          <?php if($toReview):?><div class="cutoff-friendly-check-row"><div><?=icon_svg('calendar','is-waiting')?><span><strong>Dates without logs</strong><small><?=$toReview?> confirmed dates need a work-calendar check.</small></span></div><a class="btn sm" href="#cutoff-calendar" data-open-cutoff-details>Review dates</a></div><?php endif;?>
          <?php if($model['unmapped']):?><div class="cutoff-friendly-check-row"><div><?=icon_svg('user')?><span><strong>Employee numbers</strong><small><?=(int)$model['unmapped']?> imported rows need an employee match.</small></span></div><?php if(Auth::can('payroll.configure')):?><a class="btn sm" href="<?=url('payroll-id-mapping')?>">Match numbers</a><?php else:?><span class="badge amber">Needs matching</span><?php endif;?></div><?php endif;?>
          <?php if($model['unapplied']):?><div class="cutoff-friendly-check-row"><div><?=icon_svg('clipboard')?><span><strong>Approved changes</strong><small><?=(int)$model['unapplied']?> approved requests still need attendance matching.</small></span></div><a class="btn sm" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId,'details'=>'1'])?>">View requests</a></div><?php endif;?>
        </div>
        <?php if($open||$deadlineOpen):?><div class="cutoff-friendly-note"><?=icon_svg('info')?><span>Download opens after the cutoff and filing deadline end, and attendance is checked.</span></div><?php elseif($blocked):?><div class="cutoff-friendly-note"><?=icon_svg('info')?><span>Finish the checklist above to enable Generate attendance.</span></div><?php endif;?>
        <?php if($canGenerate&&!$blocked):?><form method="post" class="cutoff-friendly-generate" data-cutoff-generate-ready="1" data-payroll-confirm="Generate and save all attendance? This locks the cutoff against new imports, filing and approvals."><?php payroll_hidden_action('generate_cutoff');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><label class="cutoff-check"><input type="checkbox" name="filing_closed" value="1" required> I checked the attendance. Filing and approvals are finished.</label><button class="btn primary" data-cutoff-generate-button disabled><?=icon_svg('file')?>Generate attendance</button></form><?php endif;?>
        <?php if($waiting):?><p class="cutoff-friendly-waiting">Future dates and logs not yet uploaded are waiting. No absence is assumed.</p><?php endif;?>
        <p class="cutoff-friendly-foot">Everyone is included. Rest day, No work, Absent and approved leave keep their day labels.</p>
        <?php endif;?>
      </section>
      <section class="panel cutoff-friendly-groups"><div class="panel-head"><div><h2>Attendance by group</h2><p>Head Office departments and Retail area / branches.</p></div><span class="badge gray"><?=count($groups)?> groups</span></div>
        <div class="cutoff-friendly-group-list"><?php foreach(array_slice($groups,0,6,true) as $name=>$employees)payroll_cutoff_group_link($cutoffId,(string)$name,count($employees),$groupStatus);?></div>
        <?php if(count($groups)>6):?><details class="cutoff-details"><summary>Show <?=count($groups)-6?> more groups</summary><div class="cutoff-friendly-group-list"><?php foreach(array_slice($groups,6,null,true) as $name=>$employees)payroll_cutoff_group_link($cutoffId,(string)$name,count($employees),$groupStatus);?></div></details><?php endif;?>
      </section>
      <?php if(!$batch&&!$legacy): payroll_cutoff_file_controls($cutoffId,$run);
        if($model['problems']):?><details id="cutoff-time-issues" class="panel cutoff-details payroll-section"><summary>Check missing / conflicting times <span><?=$problems?> dates</span></summary><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Group</th><th>Date</th><th>What to check</th><th>Action</th></tr></thead><tbody><?php foreach(array_slice($model['problems'],0,100) as $day):?><tr><td><?=e($day['_employee']['employee_name'])?><small><?=e($day['_employee']['employee_no']?:'Code to follow')?></small></td><td><?=e($day['_employee']['export_section'])?></td><td><?=e($day['work_date'])?></td><td><?=e(implode(', ',array_map('stage_label',json_decode($day['issues_json'],true)?:[]))?:'Check clock times and the employee start date.')?></td><td><?php if($day['id']):?><a class="btn sm" href="<?=url('payroll-attendance-day',['id'=>$day['id']])?>">Review day</a><?php else:?>Refresh attendance first<?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php if($problems>100):?><p class="panel-body small muted">First 100 dates shown. Fix these, then refresh to see the next dates.</p><?php endif;?></details><?php endif;
        if($calendar)payroll_cutoff_calendar($cutoffId,$calendar);
      endif;
      if($batch)payroll_cutoff_payments($cutoffId,$model); ?>
    </div>
<?php }

function payroll_cutoff_group_link(int $cutoffId,string $name,int $count,string $status): void
{
    $parts=explode(' / ',$name);$title=array_pop($parts);$parent=implode(' · ',$parts); ?>
    <a class="cutoff-friendly-group" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId,'details'=>'1','group'=>$name])?>"><div><strong><?=e($title)?></strong><small><?=e($parent?$parent.' · ':'')?><?=$count?> employees</small></div><span class="cutoff-group-actions"><span class="badge <?=in_array($status,['In progress','Locked'],true)?'gray':'green'?>"><?=e($status)?></span><span class="btn sm">View <?=icon_svg('arrow')?></span></span></a>
<?php }

function payroll_cutoff_file_controls(int $cutoffId,array $run): void
{
    $sources=PayrollAttendanceService::sourceCoverage($cutoffId);
    $old=$_SESSION['payroll_form_old']??[];
    $old=($old['action']??'')==='payroll_v2_coverage'&&(int)($old['cutoff_id']??0)===$cutoffId?$old:[]; ?>
    <details class="panel cutoff-details payroll-section" id="cutoff-files" <?=$old?'open':''?>><summary>Received files and confirmation</summary>
      <div class="panel-body"><p class="small muted">Matched employees see their logs after import. Confirm that all required files have been received.</p></div>
      <div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Source</th><th>Imported files</th><th>Confirmed through</th></tr></thead><tbody><?php if(!$sources):?><tr><td colspan="3" class="empty">No biometric files imported for this cutoff.</td></tr><?php endif;foreach($sources as $source):?><tr><td><?=e($source['source_location'])?></td><td><?=(int)$source['export_count']?></td><td><?=e($source['covered_through']??'Not confirmed')?></td></tr><?php endforeach;?></tbody></table></div>
      <?php if($sources&&Auth::can('payroll.process')):?><form method="post" class="panel-body payroll-inline-form" data-payroll-confirm="Confirm that all required files are imported through this date? No-log dates still need a work-calendar review."><?php payroll_hidden_action('coverage');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><div class="field"><label for="friendly-covered-through">All files received through</label><input id="friendly-covered-through" type="date" name="covered_through" min="<?=e($run['period_start'])?>" max="<?=e(min($run['period_end'],date('Y-m-d',strtotime('-1 day'))))?>" value="<?=e($old['covered_through']??$run['covered_through']??'')?>" required><small>New imports require confirmation again.</small></div><button class="btn">Confirm files</button></form><?php endif;?>
      <?php if(Auth::can('payroll.process')):?><form method="post" class="panel-body"><?php payroll_hidden_action('rebuild');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><button class="btn">Refresh attendance</button></form><?php endif;?>
    </details>
    <?php if(Auth::can('payroll.process')):?><details class="panel cutoff-details payroll-section"><summary>Filing deadline <span><?=!empty($run['ticket_deadline'])?e(date('M j, g:i A',strtotime($run['ticket_deadline']))):'Not set'?></span></summary><form method="post" class="panel-body payroll-inline-form"><?php payroll_hidden_action('deadline');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><div class="field"><label for="friendly-ticket-deadline">Deadline (Philippine time)</label><input id="friendly-ticket-deadline" type="datetime-local" name="ticket_deadline" value="<?=e(!empty($run['ticket_deadline'])?date('Y-m-d\TH:i',strtotime($run['ticket_deadline'])):'')?>"></div><button class="btn">Save deadline</button></form></details><?php endif;?>
    <?php if($old)unset($_SESSION['payroll_form_old']);
}
