<?php
declare(strict_types=1);

function employee_archive_page(array $data): void
{
    $canManage=Auth::can('employees.manage'); $counts=$data['counts']; $total=array_sum($counts);
    render_portal_header('hr','hr-employee-archive','Employee Archive');
    page_head('HR Portal / People','Employee Archive','<a class="btn" href="'.url('hr-employees').'">Back to employees</a>'); ?>
    <div class="employee-archive">
    <section class="panel">
      <div class="panel-head"><div><h2>Separated employees</h2><p>Inactive, resigned and terminated employees. Their records, history and payroll data are kept.</p></div><span class="badge gray"><?=$total?> <?=$total===1?'employee':'employees'?></span></div>
      <form method="get" class="employee-filterbar archive-filter">
        <input type="hidden" name="page" value="hr-employee-archive">
        <div class="directory-filter-field directory-search-field"><label for="archive-search">Search</label><div class="employee-search"><?=icon_svg('search')?><input id="archive-search" type="search" name="q" value="<?=e($data['q'])?>" placeholder="Name or employee code"></div></div>
        <div class="directory-filter-field"><label for="archive-status">Status</label><select id="archive-status" name="status"><option value="">All (<?=$total?>)</option><?php foreach(EmployeeRepository::ARCHIVED_STATUSES as $code):?><option value="<?=e($code)?>" <?=$data['status']===$code?'selected':''?>><?=e(EmployeeArchiveService::statusLabel($code))?> (<?=(int)$counts[$code]?>)</option><?php endforeach;?></select></div>
        <div class="directory-filter-actions"><button class="btn sm" type="submit">Search</button><a class="btn sm ghost" href="<?=url('hr-employee-archive')?>">Reset</a></div>
      </form>
      <?php if(!$data['rows']):?><div class="empty employee-empty"><?=$total?'No archived employees match this search.':'No one is in the archive yet. Employees you set to inactive or terminate will appear here.'?></div><?php else:?>
      <div class="employee-table-wrap" role="region" aria-label="Archived employees" tabindex="0"><table class="tbl employee-table"><thead><tr><th>Employee</th><th>Department / Branch</th><th>Status</th><th>Effective</th><th>Remarks</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($data['rows'] as $r): $name=EmployeeRepository::fullName($r); ?>
        <tr>
          <td data-label="Employee"><a class="employee-cell" href="<?=url('hr-employee',['id'=>$r['id']])?>"><span class="employee-avatar"><?=e(initials($name))?></span><span><strong><?=e($name)?></strong><small><?=e($r['employee_no']?:'Code to follow')?></small></span></a></td>
          <td data-label="Department / Branch"><?=e($r['department_name']?:'Not assigned')?><small class="archive-sub"><?=e($r['branch_name']?:'Not assigned')?></small></td>
          <td data-label="Status"><span class="badge <?=$r['status']==='TERMINATED'?'red':'gray'?>"><?=e(EmployeeArchiveService::statusLabel($r['status']))?></span><?php if(!empty($r['user_email'])):?><small class="archive-sub">Login: <?=e(strtolower((string)$r['user_status']))?></small><?php endif;?></td>
          <td data-label="Effective"><?=!empty($r['archive_date'])?e(date('M j, Y',strtotime($r['archive_date']))):'<span class="muted">Not recorded</span>'?></td>
          <td data-label="Remarks" class="archive-remarks"><?=!empty($r['archive_remarks'])?e($r['archive_remarks']):'<span class="muted">—</span>'?></td>
          <td data-label="Actions"><a class="btn sm" href="<?=url('hr-employee',['id'=>$r['id']])?>">Open 201</a><?php if($canManage):?> <a class="btn sm" href="<?=url('hr-employee',['id'=>$r['id'],'tab'=>'employment'])?>#employee-status-panel">Restore</a><?php endif;?></td>
        </tr>
      <?php endforeach;?>
      </tbody></table></div>
      <?php endif;?>
    </section>
    </div>
    <?php render_portal_footer();
}

/** Employee 201 panel: Set inactive / Terminated for current staff, Restore for archived staff. */
function employee_archive_profile_panel(array $e): void
{
    if(!Auth::can('employees.manage')) return;
    $id=(int)$e['id']; $today=date('Y-m-d'); $archived=in_array($e['status'],EmployeeRepository::ARCHIVED_STATUSES,true);
    $hasAccount=!empty($e['user_id']); ?>
    <section class="panel employee-archive-panel" id="employee-status-panel">
    <?php if($archived): $event=EmployeeArchiveService::latestEvent($id); ?>
      <div class="panel-head"><div><h2>Employee Archive</h2><p><?=e(EmployeeArchiveService::statusLabel((string)$e['status']))?><?=$event?' since '.e(date('M j, Y',strtotime($event['effective_date']))):''?>. This employee is outside the main directory and payroll cutoffs.</p></div><span class="badge <?=$e['status']==='TERMINATED'?'red':'gray'?>"><?=e(EmployeeArchiveService::statusLabel((string)$e['status']))?></span></div>
      <div class="panel-body">
        <?php if($event && $event['remarks']):?><p class="small muted">Remarks: <?=e($event['remarks'])?></p><?php endif;?>
        <form method="post" action="<?=url('hr-employee-archive')?>" class="archive-form"><?=csrf_field()?><input type="hidden" name="action" value="employee_restore"><input type="hidden" name="employee_id" value="<?=$id?>">
          <div class="field"><label>Effective date</label><input type="date" name="effective_date" value="<?=e($today)?>" max="<?=e($today)?>" required></div>
          <div class="field full"><label>Remarks</label><textarea name="remarks" rows="2" maxlength="480" required placeholder="Why is this employee returning?"></textarea></div>
          <?php if($hasAccount):?><label class="archive-check"><input type="checkbox" name="reactivate_account" value="1" checked> Reactivate their login account (<?=e($e['user_email']??'linked account')?>)</label><?php endif;?>
          <div class="archive-actions"><button class="btn primary" type="submit">Restore employee</button></div>
        </form>
      </div>
    <?php else: $checks=EmployeeArchiveService::checks($id); ?>
      <div class="panel-head"><div><h2>Employment status</h2><p>Set inactive or terminate to move this employee to the Employee Archive. History and payroll records are kept.</p></div></div>
      <div class="panel-body">
        <?php foreach($checks['blockers'] as $note):?><div class="alert error"><?=e($note)?></div><?php endforeach;?>
        <?php foreach($checks['warnings'] as $note):?><div class="alert amber"><?=e($note)?></div><?php endforeach;?>
        <?php foreach(['employee_set_inactive'=>['Set inactive','Use when the employee is no longer working (long absence, deactivated, etc.).',false],'employee_terminate'=>['Terminated','Use when employment has ended. The employee is moved to the Employee Archive.',true]] as $action=>[$label,$hint,$confirm]):?>
        <details class="archive-action"><summary><strong><?=e($label)?></strong><span><?=e($hint)?></span></summary>
          <form method="post" action="<?=url('hr-employee-archive')?>" class="archive-form"><?=csrf_field()?><input type="hidden" name="action" value="<?=e($action)?>"><input type="hidden" name="employee_id" value="<?=$id?>">
            <div class="field"><label>Effective date</label><input type="date" name="effective_date" value="<?=e($today)?>" max="<?=e($today)?>" required></div>
            <div class="field full"><label>Remarks</label><textarea name="remarks" rows="2" maxlength="480" required placeholder="Reason / notes for the 201 file"></textarea></div>
            <?php if($hasAccount):?><p class="small muted">Their login account (<?=e($e['user_email']??'linked account')?>) will be deactivated.</p><?php endif;?>
            <?php if($confirm):?><label class="archive-check"><input type="checkbox" name="confirm" value="1" required> I confirm this employee is terminated.</label><?php endif;?>
            <div class="archive-actions"><button class="btn <?=$confirm?'danger-outline':'primary'?>" type="submit" <?=$checks['blockers']?'disabled':''?>><?=e($label)?></button></div>
          </form>
        </details>
        <?php endforeach;?>
      </div>
    <?php endif;?>
    </section>
<?php }
