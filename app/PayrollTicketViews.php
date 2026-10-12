<?php
declare(strict_types=1);

function payroll_ticket_review_queue(array $queue, array $sent=[]): void
{
    $sentTab=($_GET['review_tab']??'')==='sent';
    $source=$sentTab?$sent:$queue;
    $search=trim((string)($_GET['review_search']??''));
    if($search!=='')$source=array_values(array_filter($source,static fn($r)=>mb_stripos($r['first_name'].' '.$r['last_name'].' '.$r['employee_no'].' '.$r['request_no'].' '.$r['type_name'],$search)!==false));
    $pages=max(1,(int)ceil(count($source)/100));$page=max(1,min($pages,(int)($_GET['review_page']??1)));$shown=array_slice($source,($page-1)*100,100);
    $params=['review_search'=>$search,'review_tab'=>$sentTab?'sent':'']; ?>
    <div class="hr-review-workspace" data-hr-review-workspace>
      <p class="hr-payroll-intro">Check requests, then send them for approval.</p>
      <nav class="hr-review-tabs" aria-label="Request status">
        <?php if($sentTab):?><a class="btn" href="<?=url('hr-timekeeping')?>">To review <span class="hr-review-count"><?=count($queue)?></span></a><?php else:?>
        <button type="button" class="btn is-active" data-hr-tab="new" aria-pressed="true">To review <span class="hr-review-count" data-hr-new-count><?=count($shown)?></span></button>
        <button type="button" class="btn" data-hr-tab="reviewed" aria-pressed="false">Reviewed <span class="hr-review-count" data-hr-reviewed-count>0</span></button><?php endif;?>
        <a class="btn <?=$sentTab?'is-active':''?>" href="<?=url('hr-timekeeping',['review_tab'=>'sent'])?>" <?=$sentTab?'aria-current="page"':''?>>Recent sent <span class="hr-review-count"><?=count($sent)?></span></a>
      </nav>
      <div class="hr-review-layout">
        <section class="panel hr-review-list">
          <div class="hr-review-tools"><form method="get" class="hr-review-search-form"><input type="hidden" name="page" value="hr-timekeeping"><input type="hidden" name="review_tab" value="<?=$sentTab?'sent':''?>"><div class="field"><label for="hr-review-search" class="hr-review-sr-only">Search requests</label><div class="hr-review-search-input"><?=icon_svg('search')?><input id="hr-review-search" type="search" name="review_search" value="<?=e($search)?>" placeholder="Name, code or request no."></div></div><button class="btn sm">Search</button><?php if($search!==''):?><a class="btn sm" href="<?=url('hr-timekeeping',['review_tab'=>$sentTab?'sent':''])?>">Reset</a><?php endif;?></form><?php if(!$sentTab):?><label class="cutoff-check" data-hr-selection-control hidden><input type="checkbox" form="hr-review-batch" data-hr-select-all> Select all reviewed</label><?php endif;?><span class="small muted" data-hr-waiting><?=count($source)?> <?=count($source)===1?'request':'requests'?></span></div>
          <form id="hr-review-batch" method="post" data-hr-bulk-review data-hr-friendly-review data-payroll-confirm="Send the selected reviewed requests to their Manager/ADL? Attendance updates only after final approval.">
            <?php payroll_hidden_action('bulk_verify');?>
            <div data-hr-rows>
              <?php foreach($shown as $r):$id=(int)$r['id'];$name=trim($r['first_name'].' '.$r['last_name']);?>
              <div class="hr-review-row" data-hr-row="<?=$id?>" data-review-state="<?=$sentTab?'sent':'new'?>">
                <div class="hr-review-check"><?php if(!$sentTab):?><label data-hr-row-selection hidden><input type="checkbox" name="ticket_ids[]" value="<?=$id?>" data-cutoff-item disabled aria-label="Select reviewed request from <?=e($name)?>"></label><input type="hidden" name="reviewed[<?=$id?>]" value="1" data-ticket-reviewed disabled><input type="hidden" name="hr_steps[<?=$id?>]" value="<?=(int)$r['step_no']?>"><?php endif;?><a class="hr-review-start" data-hr-row-start data-hr-open="<?=$id?>" href="<?=url('payroll-request',['id'=>$id])?>" aria-label="Review request from <?=e($name)?>"><?=icon_svg('clipboard')?></a></div>
                <div class="hr-review-person"><strong><?=e($name)?></strong><small><?=e($r['employee_no'])?></small></div>
                <div class="hr-review-request"><strong><?=e($r['type_name'])?></strong><small><?=e(date('M j, Y',strtotime($r['affected_date'])))?></small><small><?=e($r['request_no'])?></small></div>
                <span class="badge <?=$sentTab?'blue':'amber'?> hr-review-state" data-hr-row-status><?=$sentTab?'HR verified':'To review'?></span>
                <a class="btn sm hr-review-open" data-hr-open="<?=$id?>" href="<?=url('payroll-request',['id'=>$id])?>"><span data-hr-open-label><?=$sentTab?'Open':'Review'?></span><?=icon_svg('arrow')?></a>
              </div>
              <?php endforeach;?>
            </div>
            <div class="empty" data-hr-empty <?=$shown?'hidden':''?>><?=$search!==''?'No matching requests. Try another name or code.':($sentTab?'No recently verified requests.':'No requests waiting for HR review.')?></div>
            <?php if(!$sentTab):?><div class="hr-review-bulk" data-hr-bulk-bar hidden><strong class="small" data-cutoff-selection-count aria-live="polite">0 selected</strong><button class="btn primary sm" data-cutoff-submit disabled><?=icon_svg('send')?><span data-cutoff-submit-label>Send for approval</span></button></div><p class="hr-review-help" data-hr-review-help aria-live="polite">Click Review, check the details, then Mark reviewed. Selection appears in Reviewed.</p><?php else:?><p class="hr-review-help">Latest 100 HR-verified requests. Attendance updates after final approval.</p><?php endif;?>
          </form>
          <div class="cutoff-pagination"><span>Page <?=$page?> of <?=$pages?> · <?=count($source)?> requests</span><?php if($page>1):?><a class="btn sm" href="<?=url('hr-timekeeping',$params+['review_page'=>$page-1])?>">Previous</a><?php endif;if($page<$pages):?><a class="btn sm" href="<?=url('hr-timekeeping',$params+['review_page'=>$page+1])?>">Next</a><?php endif;?></div>
        </section>
        <aside class="panel hr-review-detail" data-hr-detail hidden aria-label="Review request"><div class="panel-head"><h2>Review request</h2><button type="button" class="btn sm" data-hr-close aria-label="Close request"><?=icon_svg('x')?></button></div><div data-hr-detail-body aria-live="polite"></div></aside>
      </div>
    </div>
<?php }

/** Called only after the shared request route applies ownership and permission checks. */
function payroll_ticket_review_detail(array $r, bool $canAct, ?array $approval): void
{
    $id=(int)$r['id'];$saved=[];$times=$r['time'];$automatic=PayrollAttendanceService::ready()&&PayrollAttendanceService::automaticType($r['type_code']);
    if($automatic&&!empty($r['cutoff_id'])){$q=db()->prepare('SELECT time_in,lunch_out,lunch_in,time_out FROM attendance_daily WHERE employee_id=? AND work_date=? AND cutoff_id=? LIMIT 1');$q->execute([(int)$r['employee_id'],$r['affected_date'],(int)$r['cutoff_id']]);$saved=$q->fetch()?:[];}
    $hrStep=$canAct&&($approval['approver_type']??'')==='HR_TIMEKEEPING';
    $manager='Not assigned';foreach($r['approvals'] as $a)if(in_array($a['approver_type'],['MANAGER','ADL'],true)&&(int)$a['step_no']>($hrStep?(int)$approval['step_no']:0)){$manager=($a['assigned_name']?:$r['manager_name']?:'Not assigned').' · '.($a['approver_type']==='ADL'?'ADL':'Manager');break;} ?>
    <div class="hr-review-detail-body" data-hr-loaded-request="<?=$id?>" data-hr-loaded-step="<?=(int)($approval['step_no']??0)?>">
      <span class="badge amber"><?=e($r['type_name'])?></span><h3 class="hr-review-name"><?=e(trim($r['first_name'].' '.$r['last_name']))?></h3><p class="small muted"><?=e($r['employee_no'].' · '.$r['request_no'])?></p>
      <dl class="hr-review-facts"><div><dt>Affected date</dt><dd><?=e(date('M j, Y',strtotime($r['affected_date'])))?></dd></div><div><dt>Filed</dt><dd><?=e(date('M j, g:i A',strtotime($r['created_at'])))?></dd></div><div><dt>Branch / department</dt><dd><?=e(($r['branch_name']?:'Not assigned').' · '.($r['department_name']?:'Not assigned'))?></dd></div><div><dt>Approver</dt><dd><?=e($manager)?></dd></div></dl>
      <?php if($automatic):?><section><h3>Attendance for this day</h3><div class="hr-review-times"><?php foreach(['time_in'=>'Time In','lunch_out'=>'Break Out','lunch_in'=>'Break In','time_out'=>'Time Out'] as $field=>$label):$proposed=empty($saved[$field])&&!empty($times[$field])&&!in_array($r['type_code'],['OT','POT'],true);?><div class="<?=$proposed?'is-proposed':''?>"><span><?=e($label)?><?=$proposed?' · requested':''?></span><strong><?=payroll_clock($saved[$field]??($proposed?$times[$field]:null))?></strong><?php if($proposed):?><small><?=e($r['type_code'])?></small><?php endif;?></div><?php endforeach;?></div><p class="small muted">Approved TA / OB adds missing times only. Saved times stay the same.</p></section><?php endif;?>
      <section><h3>Employee’s reason</h3><p class="small"><?=nl2br(e($r['reason']))?></p></section>
      <?php $details=['ot_start_date'=>'OT start date','ot_end_date'=>'OT end date','ot_start'=>'OT starts','ot_end'=>'OT ends','destination'=>'Destination','purpose'=>'Purpose','original_rest_day'=>'Original rest day','new_rest_day'=>'New rest day'];foreach($details as $key=>$label)if(!empty($times[$key])):?>
      <div class="hr-review-extra"><span><?=e($label)?></span><strong><?=e(in_array($key,['ot_start','ot_end'],true)?payroll_clock($times[$key]):$times[$key])?></strong></div><?php endif;?>
      <?php if(!empty($r['remarks'])):?><section><h3>Remarks</h3><p class="small"><?=nl2br(e($r['remarks']))?></p></section><?php endif;?>
      <section><h3>Attachments <span class="muted">(<?=count($r['attachments'])?>)</span></h3><?php if(!$r['attachments']):?><p class="small muted">No attachment submitted.</p><?php endif;foreach($r['attachments'] as $file):?><a class="hr-review-file" href="<?=url('payroll-attachment',['id'=>$file['id']])?>" target="_blank" rel="noopener"><?=icon_svg('file')?><span><?=e($file['original_name'])?><small>Open document</small></span></a><?php endforeach;?></section>
      <p class="hr-review-flow-note"><?=icon_svg('info')?><span>HR checks the request. The Manager / ADL gives final approval.</span></p>
      <a class="small hr-review-full" href="<?=url('payroll-request',['id'=>$id])?>">Open full request and history</a>
      <?php if($hrStep):?><div class="hr-review-detail-actions"><button type="button" class="btn primary" data-hr-mark-reviewed="<?=$id?>"><?=icon_svg('check')?>Mark reviewed</button><span class="badge green" data-hr-reviewed-note hidden>Reviewed · ready to select</span><button type="button" class="btn" data-hr-select-request="<?=$id?>" hidden><?=icon_svg('check-square')?>Select this request</button><button type="button" class="btn" data-hr-return-toggle>Needs changes</button></div>
      <form method="post" class="hr-review-return" data-hr-return-form hidden data-payroll-confirm="Return this request to the employee with your note?">
        <?=csrf_field()?><input type="hidden" name="action" value="payroll_request_decision"><input type="hidden" name="request_id" value="<?=$id?>"><input type="hidden" name="return_page" value="hr-timekeeping"><input type="hidden" name="decision" value="RETURN">
        <div class="field"><label for="hr-return-remarks">What needs to change?</label><textarea id="hr-return-remarks" name="remarks" rows="3" maxlength="2000" required placeholder="Write a short note for the employee."></textarea></div><button class="btn">Return to employee</button>
      </form><?php else:?><p class="small muted">Status: <?=e(stage_label($r['status']))?>. This request is not waiting for your HR review.</p><?php endif;?>
    </div>
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
