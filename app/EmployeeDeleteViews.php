<?php
declare(strict_types=1);

function employee_delete_setup_page(int $id): void
{
    render_portal_header('hr','hr-employees','Delete Employee');
    page_head('Employees / Employee 201','Delete Employee','<a class="btn" href="'.url('hr-employee',['id'=>$id]).'">Back to Employee 201</a>'); ?>
    <section class="panel employee-delete-screen"><div class="panel-body"><h2>Finish database setup</h2><p>Import <strong>20261010_employee_delete.sql</strong> in your HRIS database, then refresh this page.</p><p class="muted small">The setup file adds the delete permission and file cleanup tracking. It does not delete employee records.</p></div></section>
    <?php render_portal_footer();
}

function employee_delete_review_page(array $data): void
{
    $employee=$data['details']; $id=(int)$employee['id'];
    $form=$_SESSION['employee_delete_form'][$id] ?? []; unset($_SESSION['employee_delete_form'][$id]);
    $blocked=!empty($data['blockers']) || !empty($data['issues']);
    $company=class_exists('EmployeeRosterImportService') ? EmployeeRosterImportService::companyDetails($employee) : ['brand'=>'To follow','employer'=>'To follow'];
    $blockers=[]; foreach($data['blockers'] as $record) $blockers[$record['label']]=($blockers[$record['label']] ?? 0)+(int)$record['count'];
    render_portal_header('hr','hr-employees','Delete Employee');
    page_head('Employees / Employee 201','Delete Employee','<a class="btn" href="'.url('hr-employee',['id'=>$id]).'">Back to Employee 201</a>'); ?>
    <div class="employee-delete-screen">
    <section class="panel employee-delete-identity"><div class="panel-body">
      <span class="employee-delete-avatar" aria-hidden="true"><?=e(initials($data['name']))?></span>
      <div><h2><?=e($data['name'])?></h2><p>Employee code: <strong><?=e(($employee['employee_no'] ?? '') ?: 'Not set')?></strong></p><p class="muted small"><?=e(($employee['branch_name'] ?? '') ?: 'Branch not set')?> · <?=e(($employee['department_name'] ?? '') ?: 'Department not set')?> · <?=e(($employee['position_name'] ?? '') ?: 'Position not set')?></p><p class="muted small">Brand / Client: <?=e($company['brand'])?> · Employer: <?=e($company['employer'])?></p></div>
      <span class="badge <?=$blocked?'amber':'gray'?>"><?=$blocked?'Has linked records':'Ready to review'?></span>
    </div></section>
    <?php if($blocked):?>
    <section class="panel employee-delete-blocked"><div class="panel-head"><div><h2>Employee cannot be deleted yet</h2><p>These records still use this employee. You can keep the profile and set it to Inactive in Employee 201.</p></div></div><div class="panel-body">
      <?php if($blockers):?><dl class="employee-delete-counts"><?php foreach($blockers as $label=>$count):?><div><dt><?=e($label)?></dt><dd><?=number_format($count)?></dd></div><?php endforeach;?></dl><?php endif;?>
      <?php foreach($data['issues'] as $issue):?><p class="employee-delete-issue"><?=e($issue)?></p><?php endforeach;?>
      <a class="btn" href="<?=url('hr-employee',['id'=>$id,'tab'=>'employment'])?>">Open employment details <?=icon_svg('arrow')?></a>
    </div></section>
    <?php endif;?>
    <div class="employee-delete-grid">
      <section class="panel"><div class="panel-head"><div><h2>Records to remove</h2><p><?=$blocked?'Deletion is blocked. Nothing will be removed.':'Only this employee and their own 201 records.'?></p></div></div><div class="panel-body"><dl class="employee-delete-counts"><div><dt>Employee profile</dt><dd>1</dd></div>
        <?php foreach($data['owned'] as $record):?><div><dt><?=e($record['label'])?></dt><dd><?=number_format($record['count'])?></dd></div><?php endforeach;?>
        <div><dt>Profile photo</dt><dd><?=empty($employee['profile_photo_stored_name'])?'0':'1'?></dd></div>
      </dl></div></section>
      <section class="panel"><div class="panel-head"><div><h2>Records kept</h2><p>Accounts and shared system data stay available.</p></div><span class="badge green">Kept</span></div><div class="panel-body employee-delete-kept">
        <p><strong>All user accounts and passwords.</strong><?php if(!empty($employee['user_id'])):?> The linked account stays active or inactive as already set. It will no longer have this Employee 201 profile.<?php endif;?></p>
        <p>Departments, branches, companies, positions and other employees.</p>
        <p>Audit logs and the record of this deletion.</p>
        <?php if($data['roster_links']):?><p><?=number_format($data['roster_links'])?> roster import records. The link to this employee will be removed.</p><?php endif;?>
        <a class="employee-delete-cleanup-link" href="<?=url('hr-employee-delete-files')?>">View pending file cleanup <?=icon_svg('arrow')?></a>
      </div></section>
    </div>
    <?php if(!$blocked):?>
    <section class="panel employee-delete-confirm"><div class="panel-head"><div><h2>Confirm deletion</h2><p>This permanently removes the listed records and uploaded files. A database and file backup is needed to restore them.</p></div></div>
      <form method="post" action="<?=url('hr-employee-delete')?>" class="panel-body employee-delete-form" id="employeeDeleteForm">
        <?=csrf_field()?><input type="hidden" name="action" value="employee_delete"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="preview_token" value="<?=e($data['token'])?>">
        <div class="field"><label for="employeeDeleteReason">Reason for deletion <span class="req">*</span></label><textarea id="employeeDeleteReason" name="reason" required minlength="5" maxlength="500" rows="3" placeholder="For example: Duplicate employee profile."><?=e($form['reason'] ?? '')?></textarea></div>
        <div class="field"><label for="employeeDeleteCode">Type <?=e($data['confirmation'])?> to confirm <span class="req">*</span></label><input id="employeeDeleteCode" name="confirmation" required maxlength="500" autocomplete="off" spellcheck="false" value="<?=e($form['confirmation'] ?? '')?>" aria-describedby="employeeDeleteHelp"><p id="employeeDeleteHelp" class="muted small">Enter the exact code or name shown in this label.</p></div>
        <label class="employee-delete-check"><input type="checkbox" name="confirmed" value="1" required><span>I checked this employee and the records to remove.</span></label>
        <div class="employee-delete-actions"><button type="submit" class="btn employee-delete-danger" id="employeeDeleteButton"><?=icon_svg('trash')?> Delete employee permanently</button><a class="btn" href="<?=url('hr-employee',['id'=>$id])?>">Cancel</a></div>
        <p class="small muted employee-delete-expiry">Review valid until <?=e(date('g:i A',$data['expires']))?>. The system checks linked records again when you confirm.</p>
      </form>
    </section>
    <?php endif;?>
    </div>
    <script>(()=>{const form=document.getElementById('employeeDeleteForm');if(!form)return;form.addEventListener('submit',()=>{const button=document.getElementById('employeeDeleteButton');button.disabled=true;button.textContent='Deleting employee...';});})();</script>
    <?php render_portal_footer();
}

function employee_delete_files_page(): void
{
    $ready=EmployeeDeleteService::ready(); $pending=EmployeeDeleteService::pendingFiles();
    render_portal_header('hr','hr-employees','Employee File Cleanup');
    page_head('Employees / Maintenance','Employee File Cleanup','<a class="btn" href="'.url('hr-employees',['group'=>'ALL']).'">Back to employees</a>'); ?>
    <section class="panel employee-delete-screen"><div class="panel-head"><div><h2>Pending uploaded files</h2><p>Employee database records have already been deleted. These files still need removal from server storage.</p></div><span class="badge <?=$pending['count']?'amber':'green'?>"><?=number_format($pending['count'])?> pending</span></div><div class="panel-body">
      <?php if(!$ready):?><div class="alert error">Import 20261010_employee_delete.sql first, then refresh.</div>
      <?php elseif(!$pending['count']):?><div class="employee-delete-empty"><?=icon_svg('check-square')?><strong>No files waiting for cleanup.</strong><p>Nothing to retry.</p></div>
      <?php else:?><p>Retry removes up to 100 files at a time. Files still used by another employee are kept for administrator review.</p>
      <form method="post" action="<?=url('hr-employee-delete-files')?>"><?=csrf_field()?><input type="hidden" name="action" value="employee_delete_retry_files"><button class="btn primary" type="submit">Retry file cleanup</button></form>
      <div class="employee-delete-table-wrap"><table class="tbl"><caption class="employee-delete-table-caption">Showing the first <?=count($pending['rows'])?> pending files.</caption><thead><tr><th>Deleted employee ID</th><th>File type</th><th>Attempts</th><th>Queued</th></tr></thead><tbody><?php foreach($pending['rows'] as $row):?><tr><td><?=e($row['employee_record_id'])?></td><td><?=e($row['storage_kind']==='employee_photos'?'Profile photo':'201 document')?></td><td><?=e($row['attempts'])?></td><td><?=e($row['created_at'])?></td></tr><?php endforeach;?></tbody></table></div>
      <p class="muted small">If files stay pending, ask the server administrator to check storage access and file links.</p>
      <?php endif;?>
    </div></section>
    <?php render_portal_footer();
}
