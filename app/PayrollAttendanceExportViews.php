<?php
declare(strict_types=1);
require_once __DIR__.'/PayrollCutoffExportReview.php';

function payroll_attendance_export_page(array $cutoffs,int $cutoffId): void
{
    Auth::requirePermission('payroll.process');
    render_portal_header('hr','payroll-cutoff','Attendance Export');
    echo '<div class="attendance-export" data-attendance-export>';
    $actions=Auth::can('payroll.biometric_import')?'<a class="btn" href="'.url('payroll-import',['cutoff_id'=>$cutoffId]).'">'.icon_svg('files').'Import logs</a>':'';
    if(Auth::can('payroll.export'))$actions.='<a class="btn primary" href="#export-all-logs" data-export-launch aria-controls="export-all-logs" aria-expanded="true">'.icon_svg('download').'Export all logs</a>';
    page_head('HR / Payroll / Attendance','Attendance Export',$actions);
    echo '<p class="export-intro">Everyone\'s cutoff logs for PMBSI HR.</p>';
    ?>
    <form method="get" class="export-cutoff" data-export-cutoff>
      <input type="hidden" name="page" value="payroll-cutoff">
      <label for="export-cutoff">Cutoff</label>
      <select id="export-cutoff" name="cutoff_id">
      <?php foreach($cutoffs as $cutoff):?><option value="<?=(int)$cutoff['id']?>" <?=(int)$cutoff['id']===$cutoffId?'selected':''?>><?=e(date('M j',strtotime($cutoff['period_start'])).' – '.date('M j, Y',strtotime($cutoff['period_end'])))?></option><?php endforeach;?>
      </select><noscript><button class="btn">View cutoff</button></noscript>
    </form>
    <?php
    if(!PayrollCutoffExportService::ready()) {
        echo '<div class="alert amber" id="export-all-logs">Install <strong>20261011_payroll_cutoff_exports.sql</strong> into the existing database to enable attendance exports.</div></div>';
        render_portal_footer();return;
    }
    try {
        $model=PayrollCutoffExportService::model($cutoffId);
        $sources=PayrollAttendanceService::sourceCoverage($cutoffId);
        $review=PayrollCutoffExportReview::build($model,$sources);
    } catch(RuntimeException $error) {
        echo '<div class="alert error" id="export-all-logs">'.e($error->getMessage()).'</div></div>';render_portal_footer();return;
    }
    $run=$model['run'];$batch=$model['batch'];$state=$review['state'];$ready=$state==='ready';
    $finalDownload=(bool)$batch||($ready&&Auth::can('payroll.finalize'));
    $titles=['ready'=>'Final attendance is ready','checking'=>$review['affected_employees']?$review['affected_employees'].' employees need checking':'Finish the final check',
        'open'=>'Employees are still filing','empty'=>'Import the biometric logs','legacy'=>'This cutoff is already locked'];
    $descriptions=['ready'=>'Full cutoff logs, including approved TA, OB, leave and OT.',
        'checking'=>$review['missing_dates'].' dates with missing logs · '.$review['pending'].' pending requests.',
        'open'=>'Imported logs are visible to employees. Filing is still open.',
        'empty'=>'Upload the MIS and branch files. Logs go to each employee automatically.',
        'legacy'=>'The original CSV is available for this older locked cutoff.'];
    $labels=['ready'=>'Ready to download','checking'=>'Needs checking','open'=>'Filing open','empty'=>'No files yet','legacy'=>'Locked'];
    ?>
    <section class="panel export-status" aria-label="Attendance export status">
      <div class="export-status-top">
        <span class="export-state-icon <?=$ready?'is-ready':'is-waiting'?>"><?=icon_svg($ready?'circle-check':($state==='open'?'clock':($state==='empty'?'files':'clipboard')))?></span>
        <div class="export-status-copy"><h2><?=e($titles[$state])?></h2><p><?=e($descriptions[$state])?></p></div>
        <span class="badge <?=$ready?'green':($state==='legacy'?'gray':'amber')?>"><?=e($labels[$state])?></span>
      </div>
      <div class="export-meta"><?=icon_svg('users')?><strong><?=count($model['employees'])?> employees</strong><span>·</span><span><?=e(date('M j',strtotime($run['period_start'])).' – '.date('M j, Y',strtotime($run['period_end'])))?></span></div>
      <div class="export-actions">
        <?php if(in_array($state,['checking','open'],true)):?><a class="btn sm" href="#cutoff-remaining" data-export-open><?=icon_svg('clipboard')?>Review missing logs & requests</a><?php endif;?>
        <span class="export-inline-note"><?=Auth::can('payroll.export')?($batch?'Saved final attendance is ready to download.':($finalDownload?'Ready for final download.':'You can export now, even with missing logs.')):'Ask an authorized HR / Payroll user to download.'?></span>
      </div>
    </section>
    <?php if(Auth::can('payroll.export')):?>
    <section class="panel export-options" id="export-all-logs" data-export-panel aria-labelledby="export-all-title">
      <div class="export-options-head"><h2 id="export-all-title"><?=icon_svg('download')?>Export all logs</h2><button type="button" class="btn sm export-close" data-export-close hidden aria-label="Close export options"><?=icon_svg('x')?></button></div>
      <div class="export-options-copy"><h3>All <?=count($model['employees'])?> employees · <?=e(date('M j',strtotime($run['period_start'])).' – '.date('M j, Y',strtotime($run['period_end'])))?></h3><p>Every employee and date in this cutoff, across all groups.</p>
      <?php if(!$finalDownload):?><div class="export-review-notice"><?=icon_svg('info')?><div><strong>For review</strong><span>Export now, even with missing logs. Missing times stay blank. Pending requests are listed separately.</span><small>This download keeps the cutoff status the same.</small></div></div><?php else:?><p>Choose a file for PMBSI HR.</p><?php endif;?></div>
      <div class="export-format-grid">
        <?php foreach(['xlsx'=>'Excel','pdf'=>'PDF'] as $format=>$label):$content=icon_svg($format==='xlsx'?'table':'file').'<span><strong>Download '.$label.'</strong><small>'.($format==='xlsx'?'Full attendance · tabs by department or area / branch':'Full logs · grouped by department or area / branch').'</small></span>'.icon_svg('arrow');
          if($finalDownload&&!$batch):?><button type="button" class="export-format" data-export-download="<?=$format?>" hidden><?=$content?></button>
          <?php else:?><a class="export-format" href="<?=url('payroll-export',['cutoff_id'=>$cutoffId,'format'=>$format,'mode'=>$finalDownload?'final':'review'])?>"><?=$content?></a><?php endif;endforeach;?>
      </div>
      <?php if($state==='legacy'):?><p class="export-row-note"><a href="<?=url('payroll-export',['cutoff_id'=>$cutoffId])?>">Download older CSV</a></p><?php endif;?>
      <div class="export-foot">Yellow marks show approved TA / OB / OT changes. Existing clock times are kept.</div>
    </section>
    <?php endif;?>
    <?php if($ready&&!$batch&&Auth::can('payroll.finalize')&&Auth::can('payroll.export')):?>
    <section class="panel export-confirm" data-export-confirm hidden aria-label="Confirm final attendance download">
      <h2>Download all final logs as <span data-export-format-label>Excel</span>?</h2>
      <p><?=e($run['period_start'].' to '.$run['period_end'])?> · all <?=count($model['employees'])?> employees<br>This saves the final attendance and closes the cutoff to new changes.</p>
      <form method="post" data-export-download-form>
        <?php payroll_hidden_action('download_cutoff');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><input type="hidden" name="filing_closed" value="1"><input type="hidden" name="format" value="xlsx" data-export-format>
        <button class="btn primary" data-export-confirm-download><?=icon_svg('download')?>Download <span data-export-format-label>Excel</span></button><button type="button" class="btn" data-export-cancel>Cancel</button>
      </form>
    </section>
    <noscript><form method="post" class="panel export-confirm"><?php payroll_hidden_action('download_cutoff');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><label><input type="checkbox" name="filing_closed" value="1" required> Save all final attendance and close this cutoff to new changes.</label><button class="btn primary" name="format" value="xlsx">Download Excel</button><button class="btn" name="format" value="pdf">Download PDF</button></form></noscript>
    <?php endif;
    if(!$batch&&$state!=='legacy') payroll_attendance_export_remaining($cutoffId,$model,$review);
    payroll_attendance_export_details($cutoffId,$model,$sources);
    if(isset($_GET['group'])) payroll_attendance_export_group($cutoffId,$model,(string)$_GET['group']);
    echo '</div>';render_portal_footer();
}

function payroll_attendance_export_assignment(array $employee): void
{
    $group=$employee['export_group']??'To assign';
    $location=($employee['export_workplace']??'')==='RETAIL'
        ?($employee['area_name']??'Area to assign').' / '.(($employee['branch_code']??'')?:($employee['branch_name']??'Branch to assign'))
        :(($employee['export_main_department']??'To assign').(($employee['export_department']??'')!==($employee['export_main_department']??'')?' / '.($employee['export_department']??''):''));
    ?><strong><?=e($group)?></strong><span><?=e($location)?></span><?php if(($employee['export_workplace']??'')==='RETAIL'&&!empty($employee['company_name'])):?><small><?=e($employee['company_name'])?></small><?php endif;
}

function payroll_attendance_export_remaining(int $cutoffId,array $model,array $review): void
{
    $items=$review['items'];$pages=max(1,(int)ceil(count($items)/50));$page=max(1,min($pages,(int)($_GET['review_page']??1)));$shown=array_slice($items,($page-1)*50,50);
    $run=$model['run'];$open=isset($_GET['review_page'])||($_GET['review']??'')==='1'; ?>
    <details id="cutoff-remaining" class="panel export-remaining" <?=$open?'open':''?>>
      <summary><?=icon_svg('clipboard')?>Who needs checking?<span><?=count($items)?> items <?=icon_svg('arrow')?></span></summary>
      <div class="export-remaining-summary"><?=$review['missing_dates']?> dates with missing logs · <?=$review['pending']?> pending requests<?php if($review['calendar_dates']):?> · <?=$review['calendar_dates']?> dates need a work-calendar check<?php endif;?></div>
      <?php if(!$review['coverage_done']):?><div class="export-blocker"><?=icon_svg('files')?><span><?=$run['period_end']>=date('Y-m-d')?'Continue importing the remaining MIS / Sales Captain files.':'Confirm all MIS / Sales Captain files through the cutoff end.'?></span><a class="btn sm" href="#cutoff-file-settings" data-export-open>Check files</a></div><?php endif;?>
      <?php if($model['unmapped']):?><div class="export-blocker"><?=icon_svg('user')?><span><?=(int)$model['unmapped']?> imported rows need an employee match.</span><?php if(Auth::can('payroll.configure')):?><a class="btn sm" href="<?=url('payroll-id-mapping')?>">Match numbers</a><?php elseif(Auth::can('payroll.biometric_import')):?><a class="btn sm" href="<?=url('payroll-import',['cutoff_id'=>$cutoffId])?>">View imports</a><?php endif;?></div><?php endif;?>
      <?php if(!empty($review['no_log_employees'])):?><div class="export-blocker"><?=icon_svg('files')?><span><?=(int)$review['no_log_employees']?> employees have no biometric logs<?php if($review['no_log_sections']):?> (<?=(int)$review['no_log_sections']?> groups with none, e.g. a department file not yet received)<?php endif;?>. The Excel export lists them on the “Source Summary” tab.</span><?php if(Auth::can('payroll.biometric_import')):?><a class="btn sm" href="<?=url('payroll-import',['cutoff_id'=>$cutoffId])?>">Import logs</a><?php endif;?></div><?php endif;?>
      <?php if(!$model['employees']):?><p class="export-row-note">No employees are included yet. Add or import employee records.</p><?php endif;?>
      <div class="export-issue-row export-issue-labels" aria-hidden="true"><div>Employee / date</div><div>Where</div><div>What needs checking</div><div></div></div>
      <?php if(!$shown):?><p class="export-row-note muted">No completed dates with missing times or pending requests to list yet. Future dates and files still waiting for upload are not treated as employee mistakes.</p><?php endif;
      foreach($shown as $index=>$item):$day=$item['day'];$employee=$item['employee'];$detailId='export-issue-'.$page.'-'.$index; ?>
      <details class="export-issue" id="<?=$detailId?>">
        <summary class="export-issue-row">
          <div><strong><?=e($employee['employee_name']??'Employee')?></strong><small><?=e(($employee['employee_no']??'')?:'Code to follow')?></small><small><?=e(date('M j, Y',strtotime($item['date'])))?><?php if($item['date_end']&&$item['date_end']!==$item['date']):?> – <?=e(date('M j, Y',strtotime($item['date_end'])))?><?php endif;?></small></div>
          <div class="export-location"><?php payroll_attendance_export_assignment($employee);?></div>
          <div class="export-issue-copy">
            <?php if($item['missing']):foreach($item['missing'] as $label):?><span class="badge amber">Missing <?=e($label)?></span><?php endforeach;
            elseif($item['calendar']):?><span class="badge amber">Check work calendar</span>
            <?php elseif($item['issues']):?><span class="badge amber">Check clock times</span>
            <?php elseif($item['leave']):?><span class="badge amber">Pending leave</span>
            <?php elseif($day&&count(array_filter(array_intersect_key($day,PayrollCutoffExportReview::CLOCK_LABELS)))===count(PayrollCutoffExportReview::CLOCK_LABELS)):?><span class="badge green">All logs present</span>
            <?php else:?><span class="badge gray">Pending request</span><?php endif;?>
            <?php if($item['calendar']):?><small>No logs · Review the work calendar.</small><?php endif;?>
            <?php foreach(array_slice($item['issues'],0,2) as $label):?><small><?=e($label)?></small><?php endforeach;?>
            <?php foreach($item['requests'] as $request):?><small><?=e($request['type_code'].' · '.PayrollCutoffExportReview::stage($request))?></small><?php endforeach;?>
            <?php if($item['missing']&&!$item['requests']):?><small>No pending TA / OB for this date.</small><?php endif;?>
          </div><span class="btn sm export-view"><span data-export-view-label>View</span> <?=icon_svg('arrow')?></span>
        </summary>
        <div class="export-issue-detail">
          <?php if($day):?><dl class="export-clocks" aria-label="Saved clock times">
            <?php foreach(PayrollCutoffExportReview::CLOCK_LABELS as $field=>$label):?><div class="<?=isset($item['missing'][$field])?'is-missing':''?>"><dt><?=e($label)?></dt><dd><?=isset($item['missing'][$field])?'Missing':payroll_clock($day[$field]??null)?></dd></div><?php endforeach;?>
          </dl><?php else:?><p class="small muted">No daily attendance record for this request date in the selected cutoff.</p><?php endif;?>
          <?php if($item['missing']):?><p class="small">Existing clock times stay the same. Only an approved TA / OB fills a missing time.</p><?php endif;?>
          <?php foreach($item['requests'] as $request):?><div class="export-request-note"><?=icon_svg('clipboard')?><div><strong><?=e($request['type_code'].' · '.$request['request_no'])?></strong><small><?=e(PayrollCutoffExportReview::stage($request))?><?=!empty($request['approver_name'])?' · '.e($request['approver_name']):''?></small>
            <?php foreach(PayrollCutoffExportReview::CLOCK_LABELS as $field=>$label) if(!empty($request[$field])&&empty($day[$field])):?><small>Requested <?=e($label)?>: <?=payroll_clock($request[$field])?></small><?php endif;?>
            <?php if(!empty($request['ot_start'])&&!empty($request['ot_end'])):?><small>Requested OT: <?=payroll_clock($request['ot_start'])?> – <?=payroll_clock($request['ot_end'])?> · OT is separate from daily clocks.</small><?php endif;?>
            <small>Reason: <?=e($request['reason'])?></small><a href="<?=url('payroll-request',['id'=>$request['id']])?>">Open request</a></div></div><?php endforeach;?>
          <?php foreach($item['leave'] as $request):?><div class="export-request-note"><?=icon_svg('calendar')?><div><strong><?=e($request['type_name'].' · '.$request['request_no'])?></strong><small><?=e(PayrollCutoffExportReview::stage($request))?></small><?php if(Auth::can('leave.approve_hr')):?><a href="<?=url('hr-leave')?>">Review leave</a><?php endif;?></div></div><?php endforeach;?>
          <div class="export-day-actions"><?php if(!empty($day['id'])):?><a class="btn sm" href="<?=url('payroll-attendance-day',['id'=>$day['id']])?>">Review day <?=icon_svg('arrow')?></a><?php endif;if($item['calendar']):?><a class="btn sm" href="#cutoff-calendar" data-export-open>Check work calendar</a><?php endif;?></div>
        </div>
      </details>
      <?php endforeach;?>
      <div class="export-pagination"><span>Page <?=$page?> of <?=$pages?> · <?=count($items)?> employee/date items</span><?php foreach([-1=>'Previous',1=>'Next'] as $change=>$label)if($page+$change>=1&&$page+$change<=$pages):?><a class="btn sm" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId,'review_page'=>$page+$change])?>#cutoff-remaining"><?=$label?></a><?php endif;?></div>
      <div class="export-foot">HR verifies → Manager / ADL approves → approved changes appear in attendance.</div>
    </details>
<?php }

function payroll_attendance_export_details(int $cutoffId,array $model,array $sources): void
{
    $groups=PayrollCutoffDocuments::sections($model);$run=$model['run'];$old=$_SESSION['payroll_form_old']??[];
    $tab=(($_GET['export_tab']??'')==='files'||isset($_GET['calendar_page'])||isset($_GET['calendar_search'])||isset($_GET['calendar_state'])||in_array($old['action']??'',['payroll_v2_coverage','payroll_v2_deadline'],true))?'files':'groups';
    $calendar=array_values(array_filter($model['rows'],static fn($day)=>in_array($day['state'],['AWAITING_LOGS','REST_DAY','NO_WORK','ABSENT'],true)&&$day['work_date']<date('Y-m-d')&&!empty($run['covered_through'])&&$day['work_date']<=$run['covered_through'])); ?>
    <details class="panel export-details" id="export-details" <?=$tab==='files'||isset($_GET['payment_kind'])||isset($_GET['group'])?'open':''?>>
      <summary><?=icon_svg('settings')?>Details<span>Groups and imported files</span></summary>
      <div class="export-tabs" role="tablist" aria-label="Attendance details">
        <button type="button" class="btn sm <?=$tab==='groups'?'is-active':''?>" data-export-tab="groups" role="tab" id="export-groups-tab" aria-selected="<?=$tab==='groups'?'true':'false'?>" aria-controls="export-groups-panel" tabindex="<?=$tab==='groups'?'0':'-1'?>"><?=icon_svg('building')?>By group</button>
        <button type="button" class="btn sm <?=$tab==='files'?'is-active':''?>" data-export-tab="files" role="tab" id="export-files-tab" aria-selected="<?=$tab==='files'?'true':'false'?>" aria-controls="export-files-panel" tabindex="<?=$tab==='files'?'0':'-1'?>"><?=icon_svg('files')?>Imported files</button>
      </div>
      <noscript><div class="export-tabs"><a class="btn sm" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId,'export_tab'=>'groups'])?>#export-details">By group</a><a class="btn sm" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId,'export_tab'=>'files'])?>#export-details">Imported files</a></div></noscript>
      <div id="export-groups-panel" data-export-tab-panel="groups" role="tabpanel" aria-labelledby="export-groups-tab" <?=$tab==='groups'?'':'hidden'?>>
        <?php if(!$groups):?><p class="export-row-note muted">No employee groups for this cutoff yet.</p><?php endif;foreach($groups as $name=>$employees):$parts=explode(' / ',$name);$title=array_pop($parts); ?>
        <div class="export-group-row"><div><strong><?=e($title)?></strong><small><?=e(implode(' · ',$parts))?> · <?=count($employees)?> employees</small></div><a class="btn sm" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId,'group'=>$name])?>#export-group-preview">View <?=icon_svg('arrow')?></a></div><?php endforeach;?>
        <div class="export-foot">Excel / PDF includes all employees and all groups, even when some logs are missing.</div>
      </div>
      <div id="export-files-panel" data-export-tab-panel="files" role="tabpanel" aria-labelledby="export-files-tab" <?=$tab==='files'?'':'hidden'?>>
        <div id="cutoff-files"><?php if(!$sources):?><p class="export-row-note muted">No files imported for this cutoff.</p><?php endif;foreach($sources as $source):?>
          <div class="export-group-row"><div><strong><?=e($source['source_location'])?></strong><small><?=(int)$source['export_count']?> imported file(s) · Saved logs stay available.</small></div><span class="badge green">Imported</span></div>
        <?php endforeach;?></div>
        <?php if(!$model['batch']&&$run['run_state']!=='FINALIZED'&&Auth::can('payroll.process')):?>
        <details class="export-settings" id="cutoff-file-settings" <?=in_array($old['action']??'',['payroll_v2_coverage','payroll_v2_deadline'],true)?'open':''?>>
          <summary>File confirmation and filing deadline <?=icon_svg('settings')?></summary>
          <?php if($sources):?><form method="post" class="export-setting-form" data-payroll-confirm="Confirm all required MIS / Sales Captain files have been received through this date?">
            <?php payroll_hidden_action('coverage');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><div class="field"><label for="export-covered">All files received through</label><input type="date" id="export-covered" name="covered_through" min="<?=e($run['period_start'])?>" max="<?=e(min($run['period_end'],date('Y-m-d',strtotime('-1 day'))))?>" value="<?=e(($old['action']??'')==='payroll_v2_coverage'?($old['covered_through']??''):($run['covered_through']??''))?>" required><small>Confirm only after all required files are imported. New imports need confirmation again.</small></div><button class="btn">Confirm files</button>
          </form><?php endif;?>
          <form method="post" class="export-setting-form"><?php payroll_hidden_action('deadline');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><div class="field"><label for="export-deadline">Filing deadline (Philippine time)</label><input type="datetime-local" id="export-deadline" name="ticket_deadline" value="<?=e(($old['action']??'')==='payroll_v2_deadline'?($old['ticket_deadline']??''):(!empty($run['ticket_deadline'])?date('Y-m-d\TH:i',strtotime($run['ticket_deadline'])):''))?>"></div><button class="btn">Save deadline</button></form>
          <form method="post" class="export-setting-form"><?php payroll_hidden_action('rebuild');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><p class="small muted">Recheck the saved logs and approved corrections.</p><button class="btn">Refresh attendance</button></form>
        </details>
        <?php endif;if($calendar&&!$model['batch']) payroll_cutoff_calendar($cutoffId,$calendar);?>
        <div class="export-foot">Original biometric times are kept. Employee 201 supplies each employee's assignment.</div>
      </div>
      <?php if($model['batch']&&Auth::can('payroll.mark_paid')):?><details class="export-settings" id="cutoff-payments" <?=isset($_GET['payment_kind'])?'open':''?>><summary>Payment records <?=icon_svg('briefcase')?></summary><?php payroll_cutoff_payments($cutoffId,$model);?></details><?php endif;?>
    </details>
    <?php if(in_array($old['action']??'',['payroll_v2_coverage','payroll_v2_deadline'],true)) unset($_SESSION['payroll_form_old']);
}

function payroll_attendance_export_group(int $cutoffId,array $model,string $group): void
{
    $groups=PayrollCutoffDocuments::sections($model);$employees=$groups[$group]??[];$rows=[];
    foreach($employees as $days) foreach($days as $day) $rows[]=$day;
    $pages=max(1,(int)ceil(count($rows)/100));$page=max(1,min($pages,(int)($_GET['group_page']??1))); ?>
    <section class="panel export-group-preview" id="export-group-preview">
      <div class="panel-head"><div><h2><?=e($group)?></h2><p><?=$model['batch']?'Saved final attendance':'Current attendance · checks are still in progress'?></p></div><a class="btn sm" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId])?>">Close <?=icon_svg('x')?></a></div>
      <div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Date</th><?php foreach(PayrollCutoffExportReview::CLOCK_LABELS as $label):?><th><?=e($label)?></th><?php endforeach;?><th>Approved OT</th><th>Status</th></tr></thead><tbody>
      <?php if(!$rows):?><tr><td colspan="8" class="empty">No records for this group.</td></tr><?php endif;foreach(array_slice($rows,($page-1)*100,100) as $day):?>
      <tr><td><strong><?=e($day['_employee']['employee_name'])?></strong><small><?=e($day['_employee']['employee_no']?:'Code to follow')?></small></td><td><?=e(date('M j, Y',strtotime($day['work_date'])))?></td>
        <?php foreach(PayrollCutoffExportReview::CLOCK_LABELS as $field=>$label):$mark=$day['export_marks'][$field]??null;?><td class="<?=$mark?'export-changed':''?>"><?=payroll_clock($day[$field])?><?php if($mark):?><span class="export-time-mark"><?=e($mark)?></span><?php endif;?></td><?php endforeach;?>
        <td class="<?=!empty($day['export_marks']['ot'])?'export-changed':''?>"><?=PayrollCutoffExportService::otMinutes($day)>0?payroll_hours(PayrollCutoffExportService::otMinutes($day)).' h':'—'?></td><td><?=e(PayrollCutoffDocuments::stateLabel($day['state']))?></td>
      </tr><?php endforeach;?></tbody></table></div>
      <div class="export-pagination"><span>Page <?=$page?> of <?=$pages?> · <?=count($rows)?> employee dates</span><?php foreach([-1=>'Previous',1=>'Next'] as $change=>$label)if($page+$change>=1&&$page+$change<=$pages):?><a class="btn sm" href="<?=url('payroll-cutoff',['cutoff_id'=>$cutoffId,'group'=>$group,'group_page'=>$page+$change])?>#export-group-preview"><?=$label?></a><?php endif;?></div>
      <div class="export-foot">A group view does not limit the download. Excel / PDF includes everyone in the cutoff.</div>
    </section>
<?php }
