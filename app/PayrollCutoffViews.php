<?php
declare(strict_types=1);

function payroll_cutoff_export_panel(int $cutoffId,?array $model=null):void
{
    if(!PayrollCutoffExportService::ready()) { ?><div class="alert amber">Install <strong>20261011_payroll_cutoff_exports.sql</strong> to enable Generate cutoff attendance, Excel/PDF and Paid marks.</div><?php return;}
    try {$model??=PayrollCutoffExportService::model($cutoffId);}catch(RuntimeException $error){?><div class="alert error"><?=e($error->getMessage())?></div><?php return;}
    $run=$model['run'];$batch=$model['batch'];$groups=PayrollCutoffDocuments::sections($model);$blocked=[];
    if(!$batch) {
        if($run['period_end']>=date('Y-m-d'))$blocked[]='The cutoff is still running.';
        if(!empty($run['ticket_deadline'])&&$run['ticket_deadline']>date('Y-m-d H:i:s'))$blocked[]='The filing deadline is still open.';
        if(empty($run['covered_through'])||$run['covered_through']<$run['period_end'])$blocked[]='Confirm all received biometric files below.';
        foreach(['pending'=>'payroll ticket(s) waiting for review or approval','pending_leave'=>'leave request(s) waiting for approval','unmapped'=>'unknown employee number(s)','unapplied'=>'approved ticket(s) not yet applied'] as $key=>$label)if($model[$key])$blocked[]=$model[$key].' '.$label.'.';
        if($model['problems'])$blocked[]=count($model['problems']).' date(s) with missing or conflicting times.';
        if($model['calendar'])$blocked[]=count($model['calendar']).' no-log date(s) to check.';
    }
    $legacy=$run['run_state']==='FINALIZED'&&!$batch;
    ?>
    <section class="panel cutoff-export-panel payroll-section">
      <div class="panel-head"><div><p class="cutoff-eyebrow">CUTOFF ATTENDANCE</p><h2><?=$batch?'Complete attendance saved':'Gather everyone in one click'?></h2><p>All employees for this cutoff, grouped by Head Office department or Retail area and branch.</p></div><span class="badge <?=$batch?'green':'amber'?>"><?=$batch?'Generated & locked':($legacy?'Older locked cutoff':'Preparing')?></span></div>
      <div class="panel-body">
        <div class="cutoff-counts"><div><strong><?=count($model['employees'])?></strong><span>Employees</span></div><div><strong><?=count($groups)?></strong><span>Export groups</span></div><div><strong><?=count($model['rows'])?></strong><span>Employee dates</span></div><div><strong><?=$batch?'Ready':count($model['problems'])+count($model['calendar'])?></strong><span><?=$batch?'Excel & PDF':'Dates to check'?></span></div></div>
        <?php if($batch):?>
          <p class="small muted">Saved <?=e($batch['generated_at'])?>. Excel and PDF use the same saved attendance. Payment marks do not change this copy.</p>
          <div class="payroll-toolbar"><?php if(Auth::can('payroll.export')):?><a class="btn primary" href="<?=url('payroll-export',['cutoff_id'=>$cutoffId,'format'=>'xlsx'])?>"><?=icon_svg('file')?> Download Excel</a><a class="btn" href="<?=url('payroll-export',['cutoff_id'=>$cutoffId,'format'=>'pdf'])?>"><?=icon_svg('file')?> Download PDF</a><?php endif;?><a class="btn" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId,'details'=>'1'])?>">View attendance details</a></div>
        <?php elseif($legacy):?><p class="small muted">This cutoff was locked before the grouped export update. Its original CSV is available below. Historical attendance stays locked.</p>
        <?php else:?>
          <?php if($blocked):?><div class="cutoff-preparation"><strong>Before generating</strong><ul><?php foreach($blocked as $message):?><li><?=e($message)?></li><?php endforeach;?></ul></div><?php else:?><p class="cutoff-ready"><?=icon_svg('check-square')?> Attendance is ready. Confirm filing is finished to save the entire cutoff.</p><?php endif;?>
          <?php if(Auth::can('payroll.finalize')&&Auth::can('payroll.export')):?>
          <form method="post" class="cutoff-generate" data-payroll-confirm="Save and lock the complete cutoff? New filing, approval and imports for this cutoff will close."><?php payroll_hidden_action('generate_cutoff');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><label class="cutoff-check"><input type="checkbox" name="filing_closed" value="1" required <?=$blocked?'disabled':''?>> Employees have finished filing and all approvals are done.</label><button class="btn primary" <?=$blocked?'disabled':''?>><?=icon_svg('file')?> Generate cutoff attendance</button></form>
          <?php endif;?>
          <p class="small muted">Generation includes everyone. Missing times must be fixed; rest days and other no-log dates need HR review. It does not calculate salary or mark anyone Paid.</p>
        <?php endif;?>
        <details class="cutoff-details"><summary>Groups included in the export <span><?=count($groups)?></span></summary><div class="cutoff-group-grid"><?php foreach($groups as $name=>$employees):?><div><strong><?=e($name)?></strong><span><?=count($employees)?> employees</span></div><?php endforeach;?></div></details>
      </div>
    </section>
    <?php if(!$batch&&!$legacy):
        $calendar=array_values(array_filter($model['rows'],static fn($d)=>in_array($d['state'],['AWAITING_LOGS','REST_DAY','NO_WORK','ABSENT'],true)));
        if($model['problems']):?>
        <details class="panel cutoff-details payroll-section" open><summary>Missing or conflicting times <span><?=count($model['problems'])?></span></summary><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Group</th><th>Date</th><th>What to check</th><th>Action</th></tr></thead><tbody><?php foreach(array_slice($model['problems'],0,100) as $day):?><tr><td><?=e($day['_employee']['employee_name'])?><small><?=e($day['_employee']['employee_no']?:'Code to follow')?></small></td><td><?=e($day['_employee']['export_section'])?></td><td><?=e($day['work_date'])?></td><td><?=e(implode(', ',array_map('stage_label',json_decode($day['issues_json'],true)?:[]))?:'Check all four clock times and the employee start date.')?></td><td><?php if($day['id']):?><a class="btn sm" href="<?=url('payroll-attendance-day',['id'=>$day['id']])?>">Review day</a><?php else:?>Refresh attendance<?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php if(count($model['problems'])>100):?><p class="small muted panel-body">Showing the first 100 dates. Fix these, then refresh to see the remaining dates. Everyone remains included in generation.</p><?php endif;?></details>
        <?php endif;if($calendar) payroll_cutoff_calendar($cutoffId,$calendar);
    endif;if($batch) payroll_cutoff_payments($cutoffId,$model);
}

function payroll_cutoff_calendar(int $cutoffId,array $days):void
{
    $filter=trim((string)($_GET['calendar_search']??''));$state=(string)($_GET['calendar_state']??'open');
    $filtered=array_values(array_filter($days,static fn($d)=>($state==='all'||$d['state']==='AWAITING_LOGS')&&($filter===''||mb_stripos($d['_employee']['employee_name'].' '.$d['_employee']['employee_no'].' '.$d['_employee']['export_section'].' '.$d['work_date'],$filter)!==false)));
    $pages=max(1,(int)ceil(count($filtered)/100));$page=max(1,min($pages,(int)($_GET['calendar_page']??1)));$shown=array_slice($filtered,($page-1)*100,100); ?>
    <details class="panel cutoff-details payroll-section" <?=$state==='open'?'open':''?>><summary>Check no-log dates <span><?=count(array_filter($days,static fn($d)=>$d['state']==='AWAITING_LOGS'))?> to review</span></summary>
      <div class="panel-body"><p class="small muted">Use the work calendar to choose Rest day, No work or Absent. Select only dates with the same day type and reason. Approved leave is already included.</p>
        <form method="get" class="payroll-toolbar"><input type="hidden" name="page" value="payroll-cutoff"><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><div class="field"><label for="calendar-search">Find employee, group or date</label><input id="calendar-search" name="calendar_search" value="<?=e($filter)?>" placeholder="Name, NAGA, Accounting or 2026-09-20"></div><div class="field"><label for="calendar-state">Show</label><select id="calendar-state" name="calendar_state"><option value="open">Needs review</option><option value="all" <?=$state==='all'?'selected':''?>>All no-log dates</option></select></div><button class="btn">Search</button></form>
      </div>
      <form method="post" data-cutoff-select data-payroll-confirm="Save the selected no-log dates with this day type and reason? Original logs will not change."><?php payroll_hidden_action('calendar_review');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>">
        <div class="cutoff-bulk-bar"><label class="cutoff-check"><input type="checkbox" data-cutoff-select-all> Select all on this page</label><span data-cutoff-selection-count aria-live="polite">0 selected</span></div>
        <div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Select</th><th>Employee</th><th>Group</th><th>Date</th><th>Day type</th></tr></thead><tbody>
        <?php if(!$shown):?><tr><td colspan="5" class="empty">No dates for this search.</td></tr><?php endif;foreach($shown as $day):$id=(int)$day['id'];?><tr><td><?php if($id):?><input type="checkbox" name="day_ids[]" value="<?=$id?>" data-cutoff-item aria-label="Select <?=e($day['_employee']['employee_name'].' '.$day['work_date'])?>"><input type="hidden" name="source_hashes[<?=$id?>]" value="<?=e($day['export_source_hash'])?>"><?php else:?>Refresh first<?php endif;?></td><td><strong><?=e($day['_employee']['employee_name'])?></strong><small><?=e($day['_employee']['employee_no']?:'Code to follow')?></small></td><td><?=e($day['_employee']['export_section'])?></td><td><?=e(date('D, M j',strtotime($day['work_date'])))?></td><td><?=e($day['state']==='AWAITING_LOGS'?'Needs review':PayrollCutoffDocuments::stateLabel($day['state']))?><small><?=e($day['export_note']??'')?></small></td></tr><?php endforeach;?></tbody></table></div>
        <?php if(Auth::can('payroll.process')):?><div class="panel-body payroll-toolbar"><div class="field"><label for="day-classification">Day type for selected dates</label><select id="day-classification" name="classification" required><option value="">Choose day type</option><option value="REST_DAY">Rest day</option><option value="NO_WORK">No work</option><option value="ABSENT">Absent</option></select></div><div class="field"><label for="calendar-reason">Reason</label><input id="calendar-reason" name="reason" maxlength="255" placeholder="Example: approved weekly rest day" required></div><button class="btn" data-cutoff-submit>Save selected dates</button></div><?php endif;?>
      </form>
      <div class="cutoff-pagination"><span>Page <?=$page?> of <?=$pages?> · <?=count($filtered)?> dates</span><?php if($page>1):?><a class="btn sm" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId,'calendar_search'=>$filter,'calendar_state'=>$state,'calendar_page'=>$page-1])?>">Previous</a><?php endif;if($page<$pages):?><a class="btn sm" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId,'calendar_search'=>$filter,'calendar_state'=>$state,'calendar_page'=>$page+1])?>">Next</a><?php endif;?></div>
    </details>
<?php }

function payroll_cutoff_payments(int $cutoffId,array $model):void
{
    $paid=PayrollCutoffExportService::payments($cutoffId);$ot=[];foreach($model['rows'] as $d)$ot[$d['employee_id']]=($ot[$d['employee_id']]??0)+PayrollCutoffExportService::otMinutes($d);
    $kind=($_GET['payment_kind']??'SALARY')==='OT'?'OT':'SALARY';$search=trim((string)($_GET['payment_search']??''));
    $employees=array_values(array_filter($model['employees'],static fn($e)=>($kind!=='OT'||!empty($ot[$e['id']]))&&($search===''||mb_stripos($e['employee_name'].' '.$e['employee_no'].' '.$e['export_section'],$search)!==false)));
    $pages=max(1,(int)ceil(count($employees)/100));$page=max(1,min($pages,(int)($_GET['payment_page']??1)));$shown=array_slice($employees,($page-1)*100,100); ?>
    <section class="panel payroll-section cutoff-payments"><div class="panel-head"><div><h2>Payment tracking</h2><p>Payroll records payment after it has been made. Salary and approved OT have separate Paid marks.</p></div></div>
      <form method="get" class="panel-body payroll-toolbar"><input type="hidden" name="page" value="payroll-cutoff"><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><div class="field"><label for="paid-kind">Payment</label><select id="paid-kind" name="payment_kind"><option value="SALARY">Salary</option><option value="OT" <?=$kind==='OT'?'selected':''?>>Approved OT</option></select></div><div class="field"><label for="paid-search">Search employees</label><input id="paid-search" name="payment_search" value="<?=e($search)?>" placeholder="Employee, code or group"></div><button class="btn">Show</button></form>
      <form method="post" data-cutoff-select data-payroll-confirm="Record the selected employees as Paid? Only confirm payments already made."><?php payroll_hidden_action('mark_paid');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><input type="hidden" name="payment_kind" value="<?=e($kind)?>">
        <?php if(Auth::can('payroll.mark_paid')):?><div class="cutoff-bulk-bar"><label class="cutoff-check"><input type="checkbox" data-cutoff-select-all> Select unpaid employees on this page</label><span data-cutoff-selection-count aria-live="polite">0 selected</span></div><?php endif;?>
        <div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Select</th><th>Employee</th><th>Group</th><th>Approved OT</th><th><?=e($kind==='OT'?'OT':'Salary')?> payment</th><th>Payment details</th></tr></thead><tbody><?php if(!$shown):?><tr><td colspan="6" class="empty">No employees for this search or payment type.</td></tr><?php endif;foreach($shown as $employee):$id=(int)$employee['id'];$payment=$paid[$id.'|'.$kind]??null;?><tr><td><?php if(!$payment&&Auth::can('payroll.mark_paid')):?><input type="checkbox" name="employee_ids[]" value="<?=$id?>" data-cutoff-item aria-label="Mark <?=e($employee['employee_name'])?> paid"><?php else:?>—<?php endif;?></td><td><strong><?=e($employee['employee_name'])?></strong><small><?=e($employee['employee_no']?:'Code to follow')?></small></td><td><?=e($employee['export_section'])?></td><td><?=payroll_hours($ot[$id]??0)?> h</td><td><span class="badge <?=$payment?'green':'gray'?>"><?=$payment?'Paid':'Not marked paid'?></span></td><td><?php if($payment):?><?=e($payment['payment_date'].' / '.$payment['reference_no'])?><small><?=e($payment['recorded_name'].' · '.$payment['recorded_at'])?></small><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
        <?php if(Auth::can('payroll.mark_paid')):?><div class="panel-body payroll-toolbar"><div class="field"><label for="paid-date">Payment date</label><input id="paid-date" type="date" name="payment_date" max="<?=date('Y-m-d')?>" required></div><div class="field"><label for="paid-reference">Payment reference</label><input id="paid-reference" name="reference_no" maxlength="100" placeholder="Transfer, batch or voucher reference" required></div><button class="btn primary" data-cutoff-submit>Mark selected as Paid</button></div><?php else:?><p class="panel-body small muted">Payroll records Paid marks. HR can view payment progress here.</p><?php endif;?>
      </form>
      <div class="cutoff-pagination"><span>Page <?=$page?> of <?=$pages?> · <?=count($employees)?> employees</span><?php foreach([-1=>'Previous',1=>'Next'] as $change=>$label)if($page+$change>=1&&$page+$change<=$pages):?><a class="btn sm" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId,'payment_kind'=>$kind,'payment_search'=>$search,'payment_page'=>$page+$change])?>"><?=$label?></a><?php endif;?></div>
    </section>
<?php }
