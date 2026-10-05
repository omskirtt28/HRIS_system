<?php
declare(strict_types=1);
require_once __DIR__.'/AdminDataResetService.php';

function admin_data_reset_handle(string $page): void
{
    $action=(string)($_POST['action']??'');
    if(!in_array($page,['admin-clear-data','admin-clear-data-backup'],true) && !in_array($action,['admin_preview_test_data','admin_clear_test_data'],true)) return;
    need_db(); AdminDataResetService::requireAccess();
    try {
        if($_SERVER['REQUEST_METHOD']==='POST') {
            verify_csrf();
            if($action==='admin_preview_test_data') {
                AdminDataResetService::preview(); redirect('admin-clear-data');
            }
            if($action==='admin_clear_test_data') {
                $result=AdminDataResetService::clear($_POST);
                flash('success',number_format($result['records']).' test transaction records cleared. All user accounts and Employee 201 records were kept. Download your backup below.');
                if($result['files_pending']) flash('error',$result['files_pending'].' old attachment files could not be removed from storage. The database cleanup is complete and the backup is available. Ask the server administrator to finish file cleanup.');
                redirect('admin-clear-data');
            }
            throw new RuntimeException('Choose Preview data or Clear test data.');
        }
        if($page==='admin-clear-data-backup') AdminDataResetService::download((int)($_GET['id']??0));
    } catch(Throwable $e) {
        // Never show a database query, filesystem path or password in a form error.
        $message=$e instanceof PDOException?'The database operation could not finish. Check the cleanup history before trying again.':($e instanceof RuntimeException?$e->getMessage():'Cleanup could not finish. Ask your administrator to check server storage and database access.');
        flash('error',$message); redirect('admin-clear-data');
    }
    admin_data_reset_page(); exit;
}

function admin_data_reset_page(): void
{
    AdminDataResetService::requireAccess();
    $preview=AdminDataResetService::currentPreview(); $backups=AdminDataResetService::backups();
    render_portal_header('admin','admin-clear-data','Clear test data');
    page_head('System Admin / Maintenance','Clear Test Data'); ?>
    <div class="alert error"><strong>Deletes all transactions in the listed modules.</strong> This includes approved requests and finalized attendance in every cutoff. The system cannot tell test records apart from real records. Use this only when everything listed is test or demo data.</div>
    <div class="dashboard-grid equal">
      <section class="panel"><div class="panel-head"><div><h2>Data to clear</h2><p>All statuses and all dates in these modules.</p></div></div><div class="panel-body">
        <p><strong>Payroll:</strong> TA, OB, OT and other tickets, approvals, history, attachments and previous unpaid claims.</p>
        <p><strong>Attendance:</strong> Biometric imports, logs, daily attendance, cutoff results, deadlines and saved final attendance.</p>
        <p><strong>Leave:</strong> Leave requests, approvals and test credit transactions. Credit balances return to their value before those transactions.</p>
        <p><strong>Recruitment:</strong> Applicants, applications, resumes, manpower requests, job openings, screening, interviews, endorsements, client reviews, offers and deployments.</p>
      </div></section>
      <section class="panel"><div class="panel-head"><div><h2>Data to keep</h2><p>These records stay available for your presentation.</p></div><span class="badge green">Kept</span></div><div class="panel-body">
        <p><strong>All user accounts and passwords</strong>, including Employee, Manager, Payroll, HR and System Admin accounts.</p>
        <p>Employee 201 records, linked accounts, reporting managers, employee documents and photos.</p>
        <p>Departments, positions, branches, clients, business units, roles and permissions.</p>
        <p>Biometric ID mappings, schedules, salary/rate settings, leave types and cutoff calendar dates.</p>
        <p>Audit logs and security history. The cleanup itself is recorded here.</p>
      </div></section>
    </div>
    <section class="panel" style="margin-top:20px"><div class="panel-head"><div><h2>1. Preview the records</h2><p>Check the counts before clearing. Stop other testing while you do this cleanup.</p></div></div><div class="panel-body">
      <form method="post" action="<?=url('admin-clear-data')?>"><?=csrf_field()?><input type="hidden" name="action" value="admin_preview_test_data"><button class="btn primary" type="submit"><?=$preview?'Refresh preview':'Preview data'?></button></form>
      <?php if($preview):?><p class="small muted" style="margin-top:12px">Preview valid until <?=e(date('g:i A',$preview['expires']))?>, Philippine time. If a record changes, the system asks for a new preview.</p><?php endif;?>
    </div></section>
    <?php if($preview): ?>
    <div class="payroll-metrics" style="margin-top:20px"><?php metric_card('Records to clear',$preview['total'],'Across the modules below','file'); metric_card('Accounts kept',$preview['kept']['accounts'],'Passwords and access stay the same','users'); metric_card('Employees kept',$preview['kept']['employees'],'Employee 201 records','users'); ?></div>
    <section class="panel"><div class="panel-head"><div><h2>Cleanup preview</h2><p>Each count is a stored record. Linked approvals, history and import rows are included.</p></div></div><div class="panel-body">
      <?php foreach($preview['groups'] as $group=>$tables): $count=0; foreach($tables as $table) $count+=(int)($preview['counts'][$table]??0); ?>
      <details style="padding:12px 0;border-bottom:1px solid var(--line,#e5e7eb)"><summary style="cursor:pointer"><strong><?=e($group)?></strong> · <?=number_format($count)?> records</summary><div class="payroll-table-scroll" style="margin-top:12px"><table class="phase3a-table"><thead><tr><th>Record type</th><th>Count</th></tr></thead><tbody>
        <?php foreach($tables as $table): if(!array_key_exists($table,$preview['counts'])) continue; ?><tr><td><?=e(ucwords(str_replace('_',' ',$table)))?></td><td><?=number_format($preview['counts'][$table])?></td></tr><?php endforeach;?>
      </tbody></table></div></details><?php endforeach;?>
      <?php if($preview['credits']):?><h3 style="margin-top:20px">Leave credits after cleanup</h3><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee number</th><th>Leave type</th><th>Current balance</th><th>After cleanup</th></tr></thead><tbody><?php foreach($preview['credits'] as $credit):?><tr><td><?=e($credit['employee_no'])?></td><td><?=e($credit['leave_type'])?></td><td><?=number_format((float)$credit['current_balance'],2)?></td><td><?=number_format((float)$credit['opening_balance'],2)?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    </div></section>
    <?php if($preview['total']):?><section class="panel" style="margin-top:20px"><div class="panel-head"><div><h2>2. Confirm cleanup</h2><p>A backup of the transaction records and available attachments is created before deletion.</p></div></div>
      <form method="post" action="<?=url('admin-clear-data')?>" class="panel-body" id="clearTestDataForm" style="max-width:780px">
        <?=csrf_field()?><input type="hidden" name="action" value="admin_clear_test_data"><input type="hidden" name="preview_token" value="<?=e($preview['token'])?>">
        <label class="small" style="display:flex;gap:10px;align-items:flex-start;margin-bottom:20px"><input type="checkbox" name="acknowledge" value="1" required> I confirm every record listed above is test/demo data and may be deleted, including approved and finalized records.</label>
        <div class="field"><label for="resetCurrentPassword">Your current System Admin password</label><input id="resetCurrentPassword" name="current_password" type="password" required autocomplete="current-password"></div>
        <div class="field"><label for="resetConfirmation">Type CLEAR TEST DATA</label><input id="resetConfirmation" name="confirmation" required autocomplete="off" spellcheck="false" pattern="CLEAR TEST DATA" placeholder="CLEAR TEST DATA"></div>
        <button class="btn primary" type="submit" id="clearTestDataButton" style="background:#b42318;color:#fff">Create backup &amp; clear test data</button>
        <p class="small muted" style="margin-top:12px">Your user accounts and Employee 201 records stay available. Download the backup after cleanup.</p>
      </form>
    </section><?php else:?><div class="alert success" style="margin-top:20px">No transactions to clear. Your accounts and employee records are ready for a fresh demo.</div><?php endif;?>
    <?php endif;?>
    <section class="panel" style="margin-top:20px"><div class="panel-head"><div><h2>Cleanup backups</h2><p>Download and keep your backup. Private server copies expire after 48 hours.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Cleanup date</th><th>Records cleared</th><th>Backup</th></tr></thead><tbody>
      <?php if(!$backups):?><tr><td colspan="3" class="empty">No cleanup has been run.</td></tr><?php endif; foreach($backups as $backup):?><tr><td><?=e(date('M j, Y g:i A',strtotime($backup['created_at'])))?></td><td><?=number_format($backup['records'])?></td><td><?php if($backup['expired']):?><span class="badge gray">Expired</span><?php else:?><a class="btn sm" href="<?=url('admin-clear-data-backup',['id'=>$backup['id']])?>">Download backup</a><?php endif;?></td></tr><?php endforeach;?>
    </tbody></table></div></section>
    <script>
    (()=>{const form=document.getElementById('clearTestDataForm');if(!form)return;const button=document.getElementById('clearTestDataButton');form.addEventListener('submit',()=>{button.disabled=true;button.textContent='Creating backup and clearing data...';});})();
    </script>
    <?php render_portal_footer();
}
