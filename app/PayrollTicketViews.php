<?php
declare(strict_types=1);

function payroll_ticket_review_queue(array $queue): void
{
    $pages=max(1,(int)ceil(count($queue)/100));$page=max(1,min($pages,(int)($_GET['review_page']??1)));$shown=array_slice($queue,($page-1)*100,100); ?>
    <section class="panel payroll-ticket-review-panel">
      <div class="panel-head"><div><h2>For HR Review</h2><p>Check the ticket and attachments, then send it to the assigned Manager/ADL.</p></div><span class="badge amber"><?=count($queue)?> pending</span></div>
      <?php if(!$queue):?><div class="empty">No tickets waiting for HR review. Verified tickets move to the Manager/ADL queue.</div><?php else:?>
      <form method="post" data-hr-bulk-review data-payroll-confirm="Verify selected reviewed tickets and send them to their Manager/ADL? Attendance changes only after final approval."><?php payroll_hidden_action('bulk_verify');?>
      <div class="cutoff-bulk-bar"><button type="button" class="btn sm" data-select-reviewed>Select all reviewed on this page</button><span data-cutoff-selection-count aria-live="polite">0 selected</span><button class="btn primary" data-cutoff-submit>Verify selected & send</button></div>
      <p class="small muted panel-body">Open the details and attachments first. Tick Reviewed for each ticket you have checked, then select it. Return tickets needing changes from Review ticket.</p>
      <table class="payroll-ticket-review-table">
        <thead><tr><th scope="col">Select / Reviewed</th><th scope="col">Employee</th><th scope="col">Ticket</th><th scope="col">Affected date</th><th scope="col">Site / approver</th><th scope="col">Filed</th><th scope="col">Action</th></tr></thead>
        <tbody><?php foreach($shown as $request):$rid=(int)$request['id'];?>
          <tr><td data-label="Select / Reviewed"><label class="cutoff-check"><input type="checkbox" name="ticket_ids[]" value="<?=$rid?>" data-cutoff-item>Select</label><label class="cutoff-check"><input type="checkbox" name="reviewed[<?=$rid?>]" value="1" data-ticket-reviewed>Reviewed</label><input type="hidden" name="hr_steps[<?=$rid?>]" value="<?=(int)$request['step_no']?>"></td><td data-label="Employee"><strong><?=e(trim($request['first_name'].' '.$request['last_name']))?></strong><small><?=e($request['employee_no'])?></small></td>
            <td data-label="Ticket"><strong><?=e($request['type_name'])?></strong><small><?=e($request['request_no'])?></small></td>
            <td data-label="Affected date"><?=e(date('M j, Y',strtotime($request['affected_date'])))?></td>
            <td data-label="Site / approver"><?=e($request['branch_name']?:'Not assigned')?><small><?=e(($request['assigned_approver_type']==='ADL'?'ADL':'Manager').' · '.($request['manager_approver_name']?:'Not assigned'))?></small></td>
            <td data-label="Filed"><?=e(date('M j, g:i A',strtotime($request['created_at'])))?></td>
            <td data-label="Action"><a class="btn primary sm" href="<?=url('payroll-request',['id'=>$request['id']])?>" target="_blank" rel="noopener">Review ticket <?=icon_svg('arrow')?></a><small>Opens a new tab</small></td>
          </tr>
        <?php endforeach;?></tbody>
      </table></form><div class="cutoff-pagination"><span>Page <?=$page?> of <?=$pages?></span><?php if($page>1):?><a class="btn sm" href="<?=url('hr-timekeeping',['review_page'=>$page-1])?>">Previous</a><?php endif;if($page<$pages):?><a class="btn sm" href="<?=url('hr-timekeeping',['review_page'=>$page+1])?>">Next</a><?php endif;?></div><?php endif;?>
    </section>
<?php }

function payroll_ticket_attendance_summary(array $request): void
{
    if(!PayrollAttendanceService::ready() || !$request['cutoff_id'] || !PayrollAttendanceService::automaticType($request['type_code'])) return;
    // The caller has already checked request ownership/reviewer access. Use the
    // request's stored employee/date/cutoff, never a browser-supplied employee ID.
    $query=db()->prepare('SELECT time_in,lunch_out,lunch_in,time_out FROM attendance_daily WHERE employee_id=? AND work_date=? AND cutoff_id=? LIMIT 1');
    $query->execute([(int)$request['employee_id'],$request['affected_date'],(int)$request['cutoff_id']]);
    $saved=$query->fetch()?:[]; ?>
    <section class="panel payroll-ticket-attendance-summary">
      <div class="panel-head"><div><h2>Saved attendance</h2><p><?=e(date('M j, Y',strtotime($request['affected_date'])))?> · Approved TA/OB updates fill missing logs only.</p></div></div>
      <div class="panel-body"><div class="employee-attendance-times">
        <?php foreach(employee_attendance_field_labels() as $field=>$label):?><div class="employee-attendance-time"><span><?=e($label)?></span><strong><?=!empty($saved[$field])?e(payroll_clock($saved[$field])):'Missing'?></strong></div><?php endforeach;?>
      </div><?php if(!$saved):?><p class="small muted">No daily attendance record is saved for this date yet.</p><?php endif;?></div>
    </section>
<?php }
