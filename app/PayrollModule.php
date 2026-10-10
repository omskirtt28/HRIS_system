<?php
declare(strict_types=1);

require_once __DIR__ . '/PayrollAttendanceViews.php';
require_once __DIR__ . '/EmployeeAttendanceViews.php';
require_once __DIR__ . '/PayrollCutoffExportService.php';
require_once __DIR__ . '/PayrollCutoffDocuments.php';
require_once __DIR__ . '/PayrollCutoffViews.php';

function payroll_post_action(): void
{
    // PHP drops both form fields and files when the whole request is too large.
    // Report that upload limit without attempting a write or bypassing CSRF checks.
    if($_SERVER['REQUEST_METHOD']==='POST' && ($_GET['page']??'')==='payroll-import' && !$_POST) {
        $limits=PayrollAttendanceService::uploadLimits();
        if($limits['post_bytes']>0 && (int)($_SERVER['CONTENT_LENGTH']??0)>$limits['post_bytes']) {
            need_db(); Auth::requirePermission('payroll.biometric_import');
            flash('error','The files are too large together. Upload fewer files at a time (total below '.PayrollAttendanceService::uploadSizeLabel($limits['total_bytes']).'). No files were imported.');
            $uploadCutoff=(int)($_GET['cutoff_id']??0);
            redirect('payroll-import',$uploadCutoff>0?['cutoff_id'=>$uploadCutoff]:[]);
        }
    }
    $action=(string)($_POST['action']??'');
    if($_SERVER['REQUEST_METHOD']!=='POST' || !str_starts_with($action,'payroll_v2_')) return;
    need_db(); verify_csrf();
    $back='payroll-cutoff'; $params=[];
    try {
        PayrollAttendanceService::requireReady();
        if(PayrollAttendanceService::reviewOnly() && in_array($action,['payroll_v2_site','payroll_v2_salary','payroll_v2_end_rate','payroll_v2_rules','payroll_v2_holiday'],true)) {
            Auth::requirePermission('payroll.configure'); $back='payroll-import';
            throw new RuntimeException('Payroll Setup is deferred. Use biometric import, employee tickets and HR Payroll attendance review.');
        }
        switch($action) {
            case 'payroll_v2_bulk_verify':
                $back='hr-timekeeping';Auth::requirePermission('payroll.approve_hr');
                $selected=$_POST['ticket_ids']??[];$reviewed=$_POST['reviewed']??[];$steps=$_POST['hr_steps']??[];
                if(!is_array($selected)||!is_array($reviewed)||!is_array($steps)||!$selected||count($selected)>100)throw new RuntimeException('Select up to 100 tickets that you have reviewed.');
                $selected=array_values(array_unique($selected));
                foreach($selected as $id)if(!is_scalar($id)||!ctype_digit((string)$id)||(int)$id<1||($reviewed[$id]??'')!=='1'||!is_scalar($steps[$id]??null)||!ctype_digit((string)$steps[$id])||(int)$steps[$id]<1)throw new RuntimeException('Mark each selected ticket as reviewed first. No tickets were changed.');
                $sent=0;$failed=[];
                foreach($selected as $id)try{PayrollRepository::decidePayroll((int)$id,'APPROVE','Reviewed by HR; verified in the selected ticket batch.',(int)$steps[$id]);$sent++;}catch(RuntimeException $error){$failed[]='#'.(int)$id.': '.$error->getMessage();}catch(Throwable $error){error_log('HR bulk verification ticket '.(int)$id.': '.$error->getMessage());$failed[]='#'.(int)$id.': Could not finish. Refresh this ticket before retrying.';}
                flash($failed?'error':'success',$sent.' ticket(s) verified and sent to the Manager/ADL. '.($failed?count($failed).' not changed. '.implode(' ',array_slice($failed,0,5)):''));
                redirect($back);
            case 'payroll_v2_generate_cutoff':
                $params=['cutoff_id'=>(int)($_POST['cutoff_id']??0)];PayrollCutoffExportService::generate($params['cutoff_id'],($_POST['filing_closed']??'')==='1');
                flash('success','Complete cutoff saved and locked. Download Excel or PDF below.');redirect($back,$params);
            case 'payroll_v2_calendar_review':
                $params=['cutoff_id'=>(int)($_POST['cutoff_id']??0)];
                PayrollCutoffExportService::reviewCalendar($params['cutoff_id'],(array)($_POST['day_ids']??[]),(array)($_POST['source_hashes']??[]),(string)($_POST['classification']??''),(string)($_POST['reason']??''));break;
            case 'payroll_v2_mark_paid':
                $params=['cutoff_id'=>(int)($_POST['cutoff_id']??0),'payment_kind'=>(string)($_POST['payment_kind']??'SALARY')];
                PayrollCutoffExportService::markPaid($params['cutoff_id'],(array)($_POST['employee_ids']??[]),(string)($_POST['payment_kind']??''),(string)($_POST['payment_date']??''),(string)($_POST['reference_no']??''));break;
            case 'payroll_v2_auto_import':
            case 'payroll_v2_preview':
                $back='payroll-import'; $params=['cutoff_id'=>(int)($_POST['cutoff_id']??0)];
                Auth::requirePermission('payroll.biometric_import');
                $files=PayrollAttendanceService::importFiles($_FILES['biometric_files']??$_FILES['biometric_file']??[],(int)($_POST['selected_file_count']??0));
                PayrollAttendanceService::run($params['cutoff_id']);
                $publish=$action==='payroll_v2_auto_import';
                $_SESSION['payroll_upload_results']=['cutoff_id'=>$params['cutoff_id'],'files'=>[]];
                foreach($files as $file) $_SESSION['payroll_upload_results']['files'][]=PayrollAttendanceService::importFileResult($params['cutoff_id'],$file,$publish);
                $results=$_SESSION['payroll_upload_results']['files'];
                if(!$publish && count($results)===1 && $results[0]['state']==='PREVIEW') {
                    unset($_SESSION['payroll_upload_results']);
                    flash('success','File ready. Review the logs, then choose Import & show to employees.');
                    redirect('payroll-import-preview',['id'=>$results[0]['id']]);
                }
                $counts=array_count_values(array_column($results,'state'));
                $message=($counts['IMPORTED']??0).' imported, '.($counts['PREVIEW']??0).' ready to review, '.($counts['DUPLICATE']??0).' already imported, '.($counts['ERROR']??0).' need attention.';
                flash(($counts['ERROR']??0)?'error':(($counts['IMPORTED']??0)||($counts['PREVIEW']??0)?'success':'info'),$message.' See the result for each file below.');
                redirect($back,$params);
            case 'payroll_v2_commit':
                $back='payroll-import-preview'; $params=['id'=>(int)($_POST['import_id']??0)];
                PayrollAttendanceService::commitImport($params['id'],(string)($_POST['covered_through']??''),($_POST['all_locations_complete']??'')==='1');
                $import=PayrollAttendanceService::import($params['id']); flash('success','Biometric logs saved. Matched employees can now view their own cutoff attendance. Continue importing the remaining MIS / Sales Captain exports before finalizing attendance.'); redirect('payroll-cutoff',['cutoff_id'=>$import['cutoff_id']]);
            case 'payroll_v2_mapping':
                $back='payroll-id-mapping'; $params=[]; PayrollAttendanceService::saveMapping((int)($_POST['employee_id']??0),(string)($_POST['biometric_id']??'')); break;
            case 'payroll_v2_site':
                $back='payroll-setup'; $params=['tab'=>'sites']; PayrollAttendanceService::saveSite($_POST); break;
            case 'payroll_v2_salary':
                $back='payroll-setup'; $params=['tab'=>'salary']; PayrollAttendanceService::saveRate($_POST); break;
            case 'payroll_v2_end_rate':
                $back='payroll-setup'; $params=['tab'=>'salary']; PayrollAttendanceService::endRate((int)($_POST['rate_id']??0),(string)($_POST['effective_to']??'')); break;
            case 'payroll_v2_rules':
                $back='payroll-setup'; $params=['tab'=>'rules']; PayrollAttendanceService::saveRules($_POST); break;
            case 'payroll_v2_holiday':
                $back='payroll-setup'; $params=['tab'=>'rules']; PayrollAttendanceService::saveHoliday($_POST); break;
            case 'payroll_v2_deadline':
                $params=['cutoff_id'=>(int)($_POST['cutoff_id']??0)]; PayrollAttendanceService::saveDeadline($params['cutoff_id'],(string)($_POST['ticket_deadline']??'')); break;
            case 'payroll_v2_rebuild':
                Auth::requirePermission('payroll.process'); $params=['cutoff_id'=>(int)($_POST['cutoff_id']??0)]; PayrollAttendanceService::rebuild($params['cutoff_id']); break;
            case 'payroll_v2_coverage':
                $params=['cutoff_id'=>(int)($_POST['cutoff_id']??0)]; PayrollAttendanceService::confirmCoverage($params['cutoff_id'],(string)($_POST['covered_through']??'')); break;
            case 'payroll_v2_finalize':
                $params=['cutoff_id'=>(int)($_POST['cutoff_id']??0)]; PayrollAttendanceService::finalize($params['cutoff_id']); break;
            case 'payroll_v2_resolve':
                $back='payroll-attendance-day'; $params=['id'=>(int)($_POST['daily_id']??0)]; PayrollAttendanceService::resolve($params['id'],(string)($_POST['punch_field']??''),(string)($_POST['source_hash']??''),(int)($_POST['request_id']??0),(string)($_POST['remarks']??'')); break;
            case 'payroll_v2_select_punches':
                $back='payroll-attendance-day'; $params=['id'=>(int)($_POST['daily_id']??0)]; PayrollAttendanceService::selectPunches($params['id'],$_POST); break;
            case 'payroll_v2_disposition':
                $back='payroll-attendance-day'; $params=['id'=>(int)($_POST['daily_id']??0)]; PayrollAttendanceService::zeroCredit($params['id'],(string)($_POST['remarks']??''),(string)($_POST['source_hash']??'')); break;
            case 'payroll_v2_clear_disposition':
                $back='payroll-attendance-day'; $params=['id'=>(int)($_POST['daily_id']??0)]; PayrollAttendanceService::clearZeroCredit($params['id'],(string)($_POST['remarks']??''),(string)($_POST['source_hash']??'')); break;
            default: throw new RuntimeException('Unknown payroll action.');
        }
        flash('success','Payroll records updated.');
    } catch(Throwable $e) { flash('error',$e->getMessage()); $_SESSION['payroll_form_old']=$_POST; }
    redirect($back,$params);
}

function payroll_cutoff_selector(array $cutoffs,int $selected,string $page): void { ?>
    <form method="get" class="payroll-toolbar"><input type="hidden" name="page" value="<?=e($page)?>"><div class="field"><label for="payroll-cutoff-picker">Payroll cutoff</label><select id="payroll-cutoff-picker" name="cutoff_id" required><?php foreach($cutoffs as $c):?><option value="<?=$c['id']?>" <?=(int)$c['id']===$selected?'selected':''?>><?=e($c['period_start'].' to '.$c['period_end'])?></option><?php endforeach;?></select></div><button class="btn" type="submit">View cutoff</button></form>
<?php }
function payroll_amount(mixed $value): string { return $value===null?'Pending computation':'₱'.number_format((float)$value,2); }
function payroll_hours(mixed $minutes): string { return number_format((float)$minutes/60,2); }
function payroll_clock(mixed $stamp): string { return $stamp?date('g:i A',strtotime((string)$stamp)):'—'; }
function payroll_hidden_action(string $action): void { echo csrf_field().'<input type="hidden" name="action" value="'.e('payroll_v2_'.$action).'">'; }
function payroll_old(string $name,mixed $default=''): string { return e((string)($_SESSION['payroll_form_old'][$name]??$default)); }
function payroll_employee_options(array $employees,int $selected=0): void { foreach($employees as $e) echo '<option value="'.(int)$e['id'].'"'.((int)$e['id']===$selected?' selected':'').'>'.e($e['employee_no'].' · '.trim($e['first_name'].' '.$e['last_name'])).'</option>'; }

function payroll_render(string $page): void
{
    if($page==='employee-ta-context') {
        need_db(); Auth::requirePermission('payroll.request_self');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        try {
            echo json_encode(EmployeeAttendanceService::requestTimes((string)($_GET['date']??''),(int)($_GET['cutoff_id']??0)),JSON_THROW_ON_ERROR);
        } catch(PDOException $error) {
            http_response_code(500); echo json_encode(['error'=>'Could not load your logs. Reload this page and try again.']);
        } catch(RuntimeException $error) {
            http_response_code(422); echo json_encode(['error'=>$error->getMessage()]);
        } catch(Throwable $error) {
            http_response_code(500); echo json_encode(['error'=>'Could not load your logs. Reload this page and try again.']);
        }
        exit;
    }
    $pages=['employee-attendance','payroll-import','payroll-import-preview','payroll-cutoff','payroll-attendance-day','payroll-setup','payroll-id-mapping','payroll-export'];
    if(!in_array($page,$pages,true)) return;
    need_db();
    $employeePage=$page==='employee-attendance';
    if($employeePage) Auth::requirePermission('attendance.view_self');
    elseif(in_array($page,['payroll-import','payroll-import-preview'],true)) Auth::requirePermission('payroll.biometric_import');
    elseif(in_array($page,['payroll-setup','payroll-id-mapping'],true)) Auth::requirePermission('payroll.configure');
    elseif($page==='payroll-export') Auth::requirePermission('payroll.export');
    elseif($page==='payroll-attendance-day') { if(!Auth::check()) redirect('login'); }
    else Auth::requirePermission('payroll.process');
    $kind=$employeePage?'employee':'hr';
    if(!PayrollAttendanceService::ready()) { render_portal_header($kind,$page,'Attendance database update required'); page_head('Payroll & Timekeeping','Attendance database update required'); echo '<div class="alert error">For the multi-location update, apply database/migrations/20261003_payroll_log_source_coverage.sql to your existing HRIS database. Initial payroll installs also require database/migrations/20261002_payroll_biometric_workflow.sql. Do not re-import schema.sql.</div>'; render_portal_footer(); exit; }
    PayrollAttendanceService::purgeExpired();
    if($page==='payroll-export') {
        $downloadCutoff=(int)($_GET['cutoff_id']??0);
        try {
            $format=(string)($_GET['format']??'csv');
            if($format==='csv') payroll_export($downloadCutoff);
            PayrollCutoffDocuments::download($downloadCutoff,$format);
        } catch(Throwable $error) {flash('error',$error instanceof RuntimeException?$error->getMessage():'The download could not finish. Check server storage and refresh the cutoff.');redirect('payroll-cutoff',['cutoff_id'=>$downloadCutoff]);}
    }
    if($page==='payroll-setup') {
        if(PayrollAttendanceService::reviewOnly()) { flash('info','Payroll Setup is deferred. Continue with biometric import and attendance review.'); redirect('payroll-import'); }
        payroll_setup(); exit;
    }
    if($page==='payroll-id-mapping') { payroll_identity_mapping(); exit; }
    if($page==='payroll-attendance-day') { payroll_day(); exit; }
    if($page==='payroll-import-preview') { payroll_preview(); exit; }
    $cutoffs=PayrollRepository::requestCutoffs(); $cutoffId=(int)($_GET['cutoff_id']??EmployeeAttendanceService::defaultCutoff($cutoffs));
    if(!$cutoffId) { render_portal_header($kind,$page,'Payroll cutoffs'); echo '<div class="empty">No cutoff calendar available.</div>'; render_portal_footer(); exit; }
    $run=PayrollAttendanceService::run($cutoffId);
    if($page==='payroll-import') { payroll_import_page($cutoffs,$cutoffId); exit; }
    $employee=$employeePage?PayrollRepository::currentEmployee():null;
    if($employeePage && !$employee) { render_portal_header('employee',$page,'My Attendance'); echo '<div class="alert error">HR must link your account to your employee record first.</div>'; render_portal_footer(); exit; }
    $filter=(string)($_GET['filter']??'');
    if($employeePage) {
        $model=EmployeeAttendanceService::cutoff($cutoffId);
        // An explicit cutoff always wins. On initial entry, prefer the employee's
        // latest logs or approved leave when the current cutoff has no entries yet.
        if(!isset($_GET['cutoff_id']) && !$model['entries'] && $model['latest_entry_cutoff'] && (int)$model['latest_entry_cutoff']['id']!==$cutoffId) {
            $cutoffId=(int)$model['latest_entry_cutoff']['id'];
            $model=EmployeeAttendanceService::cutoff($cutoffId);
        }
        employee_attendance_page($cutoffs,$cutoffId,$model,$filter); exit;
    }
    $rows=PayrollAttendanceService::dailyRows($cutoffId,$employeePage?(int)$employee['id']:null,$filter);
    payroll_review_page($page,$kind,$cutoffs,$cutoffId,$run,$rows,$employeePage,$employee,$filter);
    exit;
}


function payroll_import_page(array $cutoffs,int $cutoffId): void
{
    render_portal_header('hr','payroll-import','Biometric Import'); page_head('HR Payroll / Attendance','Import Biometric Logs');
    $q=db()->query('SELECT i.*,c.period_start,c.period_end,COALESCE(b.name,"Automatic · all assigned sites") source_location FROM attendance_imports i JOIN payroll_cutoffs c ON c.id=i.cutoff_id LEFT JOIN branches b ON b.id=i.branch_id ORDER BY i.id DESC LIMIT 50'); $imports=$q->fetchAll();
    $automaticReady=PayrollAttendanceService::automaticImportReady();
    $old=$_SESSION['payroll_form_old']??[];
    $old=in_array($old['action']??'',['payroll_v2_preview','payroll_v2_auto_import'],true)?$old:[];
    $cutoffId=(int)($old['cutoff_id']??$cutoffId);
    $limits=PayrollAttendanceService::uploadLimits();
    $results=$_SESSION['payroll_upload_results']??[];
    unset($_SESSION['payroll_upload_results']);
    ?>
    <?php if(!$automaticReady):?><div class="alert amber">Automatic import needs the SQL update <strong>20261004_payroll_automatic_import_fix.sql</strong>. Import it into the existing HRIS database, then refresh. Existing attendance and tickets remain available.</div><?php endif;?>
    <?php if(!$limits['enabled'] || !$limits['files']):?><div class="alert error">File uploads are disabled on this server. Ask your system administrator to enable them.</div><?php endif;?>
    <?php if(!empty($results['files'])):?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>File results</h2><p>Each file is handled separately. Retry only the files that need attention. <?php foreach($cutoffs as $resultCutoff) if((int)$resultCutoff['id']===(int)$results['cutoff_id']) echo e('Cutoff: '.$resultCutoff['period_start'].' to '.$resultCutoff['period_end']);?></p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>File</th><th>Result</th><th>Details</th><th></th></tr></thead><tbody>
    <?php foreach($results['files'] as $result): $state=$result['state']; $label=['IMPORTED'=>'Imported','PREVIEW'=>'Ready to review','DUPLICATE'=>'Already imported','ERROR'=>'Needs attention'][$state]??'Needs attention';?><tr><td><?=e($result['name'])?></td><td><span class="badge <?=$state==='IMPORTED'?'green':($state==='ERROR'?'amber':'gray')?>"><?=e($label)?></span></td><td><?=e($result['message'])?></td><td><?php if((int)$result['id']>0):?><a class="btn sm" href="<?=url('payroll-import-preview',['id'=>(int)$result['id']])?>"><?=$state==='PREVIEW'?'Review file':($state==='ERROR'?'Open saved file':'View logs')?></a><?php endif;?></td></tr><?php endforeach;?>
    </tbody></table></div></section>
    <?php endif;?>
    <section class="panel payroll-section">
      <div class="panel-head"><div><h2>Import & show to employees</h2><p>Choose the cutoff and one or more PDF, CSV or XLSX files, then import.</p></div></div>
      <p class="employee-attendance-import-flow">Import the Head Office or branch exports emailed by MIS / Sales Captains. Employee Numbers match automatically, and each matched employee immediately sees their own logs. Head Office / branch assignment and the approver come from Employee 201. No location selection or separate Send action is needed.</p>
      <form method="post" enctype="multipart/form-data" class="panel-body payroll-form" data-payroll-upload data-max-files="<?=(int)$limits['files']?>" data-max-file-bytes="<?=(int)$limits['file_bytes']?>" data-max-total-bytes="<?=(int)$limits['total_bytes']?>" data-max-file-label="<?=e(PayrollAttendanceService::uploadSizeLabel($limits['file_bytes']))?>" data-max-total-label="<?=e(PayrollAttendanceService::uploadSizeLabel($limits['total_bytes']))?>">
        <?=csrf_field()?>
        <input type="hidden" name="selected_file_count" value="0">
        <div class="field"><label for="payroll-import-cutoff">Cutoff</label><select id="payroll-import-cutoff" name="cutoff_id" required><?php foreach($cutoffs as $c):?><option value="<?=$c['id']?>" <?=(int)$c['id']===$cutoffId?'selected':''?>><?=e($c['period_start'].' to '.$c['period_end'])?></option><?php endforeach;?></select></div>
        <p class="small muted">Example: Employee Number 17845 goes to that employee's account, together with logs from other imported files for the same day. Unknown numbers remain for Payroll review. A file without device-location details is not used to guess where someone worked.</p>
        <?php if(Auth::can('payroll.configure')):?><p class="small"><a href="<?=url('payroll-id-mapping')?>">Match an unknown Employee Number / biometric ID</a></p><?php endif;?>
        <div class="field"><label for="payroll-import-file">Biometric files</label><input id="payroll-import-file" type="file" name="biometric_files[]" accept=".pdf,.csv,.xlsx" multiple required aria-describedby="payroll-upload-help payroll-upload-selection payroll-upload-error"><small id="payroll-upload-help">Select up to <?=(int)$limits['files']?> files for the same cutoff. Hold Ctrl to select several files. Maximum <?=e(PayrollAttendanceService::uploadSizeLabel($limits['file_bytes']))?> per file and <?=e(PayrollAttendanceService::uploadSizeLabel($limits['total_bytes']))?> total, based on this server's limits. Each file can contain up to 100,000 rows.</small><small>Text PDFs in the OGAL format are supported. CSV/XLSX headers: No., Date/Time, Status.</small><small id="payroll-upload-selection" data-upload-selection aria-live="polite">No files selected.</small><small id="payroll-upload-error" data-upload-error role="alert" hidden></small></div>
        <p class="small muted">Original files are deleted after 48 hours. Saved logs and history stay available. Files saved for preview only expire after two hours.</p>
        <div class="actions-inline"><button class="btn primary" type="submit" name="action" value="payroll_v2_auto_import" <?=$automaticReady && $limits['enabled'] && $limits['files']?'':'disabled'?>>Import & show to employees</button><button class="btn" type="submit" name="action" value="payroll_v2_preview" <?=$automaticReady && $limits['enabled'] && $limits['files']?'':'disabled'?>>Preview only (optional)</button></div>
      </form>
    </section>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Import history</h2><p>Duplicate punches are ignored; unknown IDs remain available for mapping.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Upload</th><th>Source location</th><th>Cutoff</th><th>Rows</th><th>Status</th><th>Original file expiry</th><th></th></tr></thead><tbody><?php foreach($imports as $i):?><tr><td><?=e($i['original_name'])?></td><td><?=e($i['source_location'])?></td><td><?=e($i['period_start'].' / '.$i['period_end'])?></td><td><?=$i['row_count']?></td><td><?=e($i['state']==='PREVIEW'?'Pending — not imported':$i['state'])?></td><td><?=e($i['deleted_at']?'Deleted '.$i['deleted_at']:$i['expires_at'])?></td><td><a class="btn sm" href="<?=url('payroll-import-preview',['id'=>$i['id']])?>"><?=$i['state']==='PREVIEW'?'Finish import':'View source rows'?></a></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php if($old) unset($_SESSION['payroll_form_old']); render_portal_footer();
}

function payroll_preview(): void
{
    $id=(int)($_GET['id']??0); $import=PayrollAttendanceService::import($id); $run=PayrollAttendanceService::run((int)$import['cutoff_id']); $rows=PayrollAttendanceService::previewRows($id);
    $counts=PayrollAttendanceService::previewCounts($id);
    $old=$_SESSION['payroll_form_old']??[];
    $old=($old['action']??'')==='payroll_v2_commit' && (int)($old['import_id']??0)===$id?$old:[];
    render_portal_header('hr','payroll-import','Import Preview'); page_head('HR Payroll / Import','Import #'.$id,'<a class="btn" href="'.url('payroll-import').'">Back to imports</a>'); ?>
    <?php if($import['state']==='PREVIEW'):?><div class="payroll-banner"><strong>Preview only — not imported</strong><span>“Matched” means the employee was identified. Click “Import & show to employees” below to save this file and make the matched logs available.</span></div><?php elseif($import['state']==='IMPORTED'):?><div class="alert success">This export has been imported. Matched logs are already available in each employee's own attendance. No Send action is needed.</div><?php endif;?>
    <?php if(Auth::can('payroll.process')):?><div class="payroll-toolbar"><a class="btn primary" href="<?=url('payroll-cutoff',['cutoff_id'=>$import['cutoff_id']])?>">View attendance + approved tickets</a></div><?php endif;?>
    <div class="payroll-banner"><strong><?=e($import['original_name'])?></strong><span><?=e($import['branch_name'])?> · <?=e($import['state'])?> · <?=$import['row_count']?> rows · <?=e($run['period_start'].' to '.$run['period_end'])?></span></div>
    <div class="payroll-banner"><span>Biometric source: <?=e($import['branch_name'])?>. Employees retain their 201 assignment, configured schedule and approver. Their logs from all imported locations combine into daily attendance.</span></div>
    <div class="payroll-toolbar"><?php foreach($counts as $state=>$count):?><span class="badge <?=$state==='UNMAPPED'?'amber':'gray'?>"><?=e($import['state']==='PREVIEW' && $state==='READY'?'Matched — not saved':stage_label($state))?>: <?=(int)$count?></span><?php endforeach;?><?php if(Auth::can('payroll.configure')):?><a class="btn sm" href="<?=url('payroll-id-mapping')?>">Map biometric IDs</a><?php endif;?></div>
    <?php if($import['state']==='PREVIEW'):?><p class="small muted">Saved biometric IDs identify employees first. IDs that exactly match an employee number are detected automatically when that employee has no saved biometric ID. These matches are saved when you confirm the import.</p><?php endif;?>
    <?php if($import['state']==='PREVIEW'):?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Save this preview to employee attendance</h2><p>Matched logs appear in employee accounts immediately after import. Payroll can confirm all required files later in Payroll Cutoffs.</p></div></div>
      <form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('commit');?><input type="hidden" name="import_id" value="<?=$id?>">
        <div class="field"><label>Export complete through <?=empty($import['branch_id'])?'(optional until confirming all files)':''?></label><input type="date" name="covered_through" min="<?=e($run['period_start'])?>" max="<?=e(min($run['period_end'],date('Y-m-d',strtotime('-1 day'))))?>" value="<?=e($old['covered_through']??'')?>" <?=empty($import['branch_id'])?'':'required'?>><small>Unknown IDs can be matched later without re-uploading. Fill this date if confirming that all required files have been received.</small></div>
        <?php if(Auth::can('payroll.process')):?><label class="small"><input type="checkbox" name="all_locations_complete" value="1" <?=($old['all_locations_complete']??'')==='1'?'checked':''?>> This is the last required export: all biometric locations have been uploaded through this date.</label><p class="small muted">Leave unchecked while other locations are still pending. You can confirm all uploads later in Payroll Cutoffs.</p><?php endif;?>
        <button class="btn primary">Import & show to employees</button>
      </form>
    </section>
    <?php endif;?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Original biometric file rows</h2><p>First 250 rows from this uploaded file. Approved TA/OB/OT tickets appear in Daily Attendance and do not add rows to the original file.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Row</th><th>Biometric ID</th><th>Employee / 201 assignment</th><th>Date/time</th><th>Status</th><th>Match</th></tr></thead><tbody><?php foreach($rows as $r):?><tr class="<?=$r['row_state']==='UNMAPPED'?'payroll-issue-row':''?>"><td><?=$r['row_no']?></td><td><?=e($r['biometric_id'])?></td><td><?=e($r['employee_name']?:'Unknown ID')?><?php if($r['employee_name']):?><small><?=e($r['employee_branch_name']?:'Assignment not set')?></small><?php endif;?></td><td><?=e($r['punched_at'])?></td><td><?=e($r['punch_status'])?></td><td><?=e($import['state']==='PREVIEW' && $r['row_state']==='READY'?'Matched — not saved':stage_label($r['row_state']))?><?php if(($r['match_source']??'')==='EMPLOYEE_NUMBER'):?><small>Matched employee number</small><?php endif;?><?php if(!empty($r['other_log_location'])):?><small>Other biometric location · accepted</small><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php if($old) unset($_SESSION['payroll_form_old']); render_portal_footer();
}

function payroll_day(): void
{
    $d=PayrollAttendanceService::daily((int)($_GET['id']??0)); $emp=PayrollRepository::currentEmployee(); $own=$emp&&(int)$emp['id']===(int)$d['employee_id']&&Auth::can('attendance.view_self');
    if(!$own&&!Auth::can('attendance.view_all')&&!Auth::can('payroll.process')) { http_response_code(403); exit('Access denied'); }
    $d=PayrollAttendanceService::detail((int)$d['id']);
    $kind=$own?'employee':'hr'; $run=PayrollAttendanceService::run((int)$d['cutoff_id']);
    $selfModel=$own?EmployeeAttendanceService::cutoff((int)$d['cutoff_id']):null; $selfDay=null;
    if($selfModel) foreach($selfModel['days'] as $day) if($day['date']===$d['work_date']) { $selfDay=$day; break; }
    $backParams=$own?['cutoff_id'=>$d['cutoff_id'],'tab'=>'review','day'=>$d['work_date']]:['cutoff_id'=>$d['cutoff_id']];
    render_portal_header($kind,$own?'employee-attendance':'payroll-cutoff','Attendance Day'); page_head('Attendance / Daily Review',$d['employee_name'].' · '.$d['work_date'],'<a class="btn" href="'.url($own?'employee-attendance':'payroll-cutoff',$backParams).'">'.($own?'Back to attendance':'Back to cutoff').'</a>'); ?>
    <div class="payroll-banner"><strong><?=e(stage_label($d['state']))?></strong><span><?=e(implode(' · ',array_map('stage_label',json_decode($d['issues_json'],true)?:[])))?></span></div>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Original and effective attendance</h2><p>Corrections are separate from biometric source records.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Punch</th><th>Original</th><th>Effective</th></tr></thead><tbody><?php foreach(PayrollCalculator::FIELDS as $f):?><tr><td><?=e(stage_label($f))?></td><td><?=e($d['original_'.$f]??'Missing / ambiguous')?></td><td><?=e($d[$f]??'Missing')?></td></tr><?php endforeach;?></tbody></table></div></section>
    <div class="payroll-toolbar"><?php if($selfDay) employee_attendance_actions($selfDay,$selfModel);?></div>
    <?php if(Auth::can('payroll.process') && $run['run_state']!=='FINALIZED'):?>
    <?php $snapshot=json_decode($d['rate_snapshot_json'],true)?:[]; $rawIds=array_map('intval',$snapshot['raw_punch_ids']??[]); $sourceTypes=array_column(json_decode($d['sources_json'],true)?:[],'type');
    if($rawIds && (in_array($d['state'],['ISSUES','AWAITING_LOGS'],true)||in_array('VERIFIED_PUNCH_SELECTION',$sourceTypes,true))):?><details class="panel payroll-section"><summary class="panel-head">Verify ambiguous or extra biometric punches</summary><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('select_punches');?><input type="hidden" name="source_hash" value="<?=e($snapshot['punch_source_hash']??'')?>"><input type="hidden" name="daily_id" value="<?=$d['id']?>"><p class="small muted">Choose actual biometric events for each slot. Missing times require an approved TA. Unselected raw events stay preserved.</p><div class="payroll-form-grid"><?php foreach(PayrollCalculator::FIELDS as $field):?><div class="field"><label><?=e(stage_label($field))?></label><select name="<?=$field?>"><option value="0">Missing / no selected raw punch</option><?php foreach($d['punches'] as $p): if(!in_array((int)$p['id'],$rawIds,true))continue;?><option value="<?=$p['id']?>" <?=$p['punched_at']===$d['original_'.$field]?'selected':''?>><?=e($p['punched_at'].' · '.$p['punch_status'])?></option><?php endforeach;?></select></div><?php endforeach;?></div><div class="field"><label>Verification reason</label><textarea name="remarks" required maxlength="255"></textarea></div><button class="btn primary">Save verified selection</button></form></details><?php endif;?>
    <?php foreach($d['conflicts'] as $field=>$c):?><section class="panel payroll-section"><div class="panel-head"><div><h2><?=e(stage_label($field))?> conflict</h2><p>Select the verified value. This records a resolution, not another ticket approval.</p></div></div><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('resolve');?><input type="hidden" name="daily_id" value="<?=$d['id']?>"><input type="hidden" name="punch_field" value="<?=e($field)?>"><input type="hidden" name="source_hash" value="<?=e($c['hash'])?>"><div class="field"><label>Value to retain</label><select name="request_id"><option value="0">Original: <?=e($c['raw']??'Missing')?></option><?php foreach($c['proposals'] as $p):?><option value="<?=$p['request_id']?>">Approved ticket #<?=$p['request_id']?>: <?=e($p['value'])?></option><?php endforeach;?></select></div><div class="field"><label>Resolution reason</label><textarea name="remarks" required maxlength="255"></textarea></div><button class="btn primary">Record resolution</button></form></section><?php endforeach;?>
    <?php if($d['state']==='ISSUES' && $d['state']!=='AWAITING_LOGS'):?><details class="panel payroll-section"><summary class="panel-head">Record zero attendance credit</summary><form method="post" class="panel-body payroll-form" data-payroll-confirm="Record zero attendance credit for this day? Approved OT and attendance conflicts still require review."><?php payroll_hidden_action('disposition');?><input type="hidden" name="source_hash" value="<?=e($snapshot['attendance_source_hash']??'')?>"><input type="hidden" name="daily_id" value="<?=$d['id']?>"><p class="small muted">Use after HR verifies the day and checks approved tickets. Original logs remain unchanged; this does not calculate a salary deduction.</p><div class="field"><label>Payroll disposition reason</label><textarea name="remarks" required maxlength="255"></textarea></div><button class="btn">Record zero credit</button></form></details><?php endif; endif;?>
    <?php if(Auth::can('payroll.process') && $run['run_state']!=='FINALIZED' && in_array('ZERO_CREDIT',array_column(json_decode($d['sources_json'],true)?:[],'type'),true)):?><details class="panel payroll-section"><summary class="panel-head">Withdraw recorded zero attendance credit</summary><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('clear_disposition');?><input type="hidden" name="daily_id" value="<?=$d['id']?>"><input type="hidden" name="source_hash" value="<?=e((json_decode($d['rate_snapshot_json'],true)?:[])['attendance_source_hash']??'')?>"><p class="small muted">Return this draft day to normal attendance review. The earlier zero-credit decision remains in the audit history.</p><div class="field"><label>Withdrawal reason</label><textarea name="remarks" required maxlength="255"></textarea></div><button class="btn">Withdraw and refresh</button></form></details><?php endif;?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Linked approved tickets</h2><p>Employee, affected date and punch field determine matching.</p></div></div><div class="panel-body"><?php if(!$d['requests']):?><div class="empty">No approved tickets for this day.</div><?php endif; foreach($d['requests'] as $r):?><a class="btn" href="<?=url('payroll-request',['id'=>$r['id']])?>"><?=e($r['request_no'].' · '.$r['type_code'])?></a><?php endforeach;?></div></section>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Raw biometric punches</h2><p>Original punches for this duty day, including its overnight logs. The actual device location is shown when supplied; Employee 201 supplies the assigned workplace.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Date/time</th><th>Event</th><th>Biometric source location</th></tr></thead><tbody><?php foreach($d['punches'] as $p):?><tr><td><?=e($p['punched_at'])?></td><td><?=e($p['punch_status'])?></td><td><?=e($p['source_location']??'Not supplied in export')?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php render_portal_footer();
}

function payroll_setup(): void
{
    render_portal_header('hr','payroll-setup','Payroll Setup'); page_head('HR Payroll / Configuration','Payroll Setup');
    $tab=(string)($_GET['tab']??'sites'); $employees=PayrollRepository::allActiveEmployees(); $branches=db()->query('SELECT id,name FROM branches WHERE active=1 ORDER BY name')->fetchAll();
    $old=$_SESSION['payroll_form_old']??[]; ?>
    <nav class="payroll-toolbar"><?php foreach(['sites'=>'Sites & approvers','mapping'=>'Biometric IDs','salary'=>'Employee rates','rules'=>'OT & holidays'] as $key=>$label):?><a class="btn <?=$tab===$key?'primary':''?>" href="<?=url('payroll-setup',['tab'=>$key])?>"><?=e($label)?></a><?php endforeach;?></nav>
    <?php if($tab==='sites'):
        $siteId=(int)($_GET['branch_id']??($old['branch_id']??0)); $site=$siteId?PayrollAttendanceService::site($siteId):null;
        if($siteId && !$site) {
            $q=db()->prepare('SELECT * FROM branches WHERE id=? AND active=1'); $q->execute([$siteId]); $branch=$q->fetch();
            if($branch) $site=['workplace'=>($branch['site_type']??'')==='HEAD_OFFICE'?'HEAD_OFFICE':'BRANCH'];
        }
        if(($old['action']??'')==='payroll_v2_site') { $site=array_replace($site??[],$old); $site['workdays']=implode(',',array_map('intval',(array)($old['workdays']??[]))); }
        $isHeadOffice=($site['workplace']??'BRANCH')==='HEAD_OFFICE';
        $approvers=db()->query('SELECT u.id,u.full_name,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status="ACTIVE" AND (r.code="SUPER_ADMIN" OR EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=u.role_id AND p.code="payroll.approve_adl")) ORDER BY u.full_name')->fetchAll();
        $sites=db()->query('SELECT s.*,b.name branch_name,u.full_name approver_name FROM payroll_site_settings s JOIN branches b ON b.id=s.branch_id LEFT JOIN users u ON u.id=s.approver_user_id ORDER BY b.name')->fetchAll(); ?>
        <section class="panel payroll-section">
          <div class="panel-head"><div><h2>Schedule and approval routing</h2><p>Save one schedule per site for its assigned employees. Head Office uses each employee's Reporting To Manager; a branch uses its assigned ADL.</p></div></div>
          <form method="post" class="panel-body payroll-form">
            <?php payroll_hidden_action('site');?>
            <div class="payroll-form-grid">
              <div class="field"><label for="payroll-setup-site">Branch / site</label><select id="payroll-setup-site" name="branch_id" required><option value="">Choose site</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=(int)$b['id']===$siteId?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select><small>Changing the site loads its saved setup. Enter the schedule after choosing the site.</small></div>
              <div class="field"><label for="payroll-setup-workplace">Workplace</label><select id="payroll-setup-workplace" name="workplace"><option value="HEAD_OFFICE" <?=$isHeadOffice?'selected':''?>>Head Office</option><option value="BRANCH" <?=$isHeadOffice?'':'selected'?>>Branch</option></select></div>
              <div id="payroll-setup-adl-field" class="field" <?=$isHeadOffice?'style="display:none"':''?>><label for="payroll-setup-adl">Branch ADL approver</label><select id="payroll-setup-adl" name="approver_user_id" <?=$isHeadOffice?'disabled':'required'?>><option value="">Select assigned ADL account</option><?php foreach($approvers as $a):?><option value="<?=$a['id']?>" <?=(int)($site['approver_user_id']??0)===(int)$a['id']?'selected':''?>><?=e($a['full_name'].' · '.$a['role_name'])?></option><?php endforeach;?></select><small>Select the branch's approver, not an employee to assign this schedule to. Only active accounts with branch payroll approval access are listed.</small></div>
              <div id="payroll-setup-manager-note" class="field" <?=$isHeadOffice?'':'style="display:none"'?>><label>Head Office approver</label><p class="small muted">Each employee's Manager comes from Employee 201 → Reporting To. This schedule applies to all employees assigned to this Head Office site.</p></div>
              <div class="field"><label>Shift start</label><input type="time" name="shift_start" value="<?=e(substr($site['shift_start']??'08:00',0,5))?>" required></div>
              <div class="field"><label>Shift end</label><input type="time" name="shift_end" value="<?=e(substr($site['shift_end']??'17:00',0,5))?>" required></div>
              <div class="field"><label>Allowed break minutes</label><input type="number" name="break_minutes" value="<?=e((string)($site['break_minutes']??''))?>" min="0" max="240" step="1" required><small>Flexible break timing; set the confirmed company duration.</small></div>
            </div>
            <fieldset class="payroll-checks"><legend>Scheduled weekdays</legend><?php foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $n=>$label):?><label><input type="checkbox" name="workdays[]" value="<?=$n?>" <?=in_array((string)$n,explode(',',$site['workdays']??'1,2,3,4,5'),true)?'checked':''?>><?=$label?></label><?php endforeach;?></fieldset>
            <button class="btn primary">Save site configuration</button>
          </form>
        </section>
        <script>(()=>{
          const site=document.getElementById('payroll-setup-site');
          const workplace=document.getElementById('payroll-setup-workplace');
          const adlField=document.getElementById('payroll-setup-adl-field');
          const adl=document.getElementById('payroll-setup-adl');
          const managerNote=document.getElementById('payroll-setup-manager-note');
          const syncApprover=()=>{
            const headOffice=workplace.value==='HEAD_OFFICE';
            adlField.style.display=headOffice?'none':'';
            adl.disabled=headOffice;
            adl.required=!headOffice;
            managerNote.style.display=headOffice?'':'none';
          };
          workplace.addEventListener('change',syncApprover);
          syncApprover();
          site.addEventListener('change',()=>{
            if(!site.value)return;
            const destination=new URL(window.location.href);
            destination.searchParams.set('page','payroll-setup');
            destination.searchParams.set('tab','sites');
            destination.searchParams.set('branch_id',site.value);
            window.location.assign(destination.href);
          });
        })();</script>
        <section class="panel payroll-section"><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Site</th><th>Route</th><th>Schedule</th><th>Break</th><th></th></tr></thead><tbody><?php foreach($sites as $s):?><tr><td><?=e($s['branch_name'])?></td><td><?=e($s['workplace']==='HEAD_OFFICE'?'Reporting Manager':($s['approver_name']??'ADL not set'))?></td><td><?=e($s['shift_start'].' – '.$s['shift_end'])?></td><td><?=$s['break_minutes']?> min</td><td><a class="btn sm" href="<?=url('payroll-setup',['tab'=>'sites','branch_id'=>$s['branch_id']])?>">Edit</a></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php elseif($tab==='mapping'):
        $maps=db()->query('SELECT m.*,e.employee_no,CONCAT_WS(" ",e.first_name,e.last_name) employee_name FROM payroll_biometric_mappings m JOIN employees e ON e.id=m.employee_id ORDER BY e.last_name')->fetchAll();
        $unknown=PayrollAttendanceService::unmappedBiometricIds(); ?>
        <?php if($unknown):?><div class="alert error">Unknown biometric IDs: <?=e(implode(', ',$unknown))?></div><?php endif;?>
        <section class="panel payroll-section"><div class="panel-head"><div><h2>Match biometric ID to employee</h2><p>Explicit identity mapping. Names are never used to guess ownership. Imported unknown rows match automatically when configured.</p></div></div><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('mapping');?><div class="field"><label>Employee</label><select name="employee_id" required><option value="">Select employee</option><?php payroll_employee_options($employees,(int)($old['employee_id']??0));?></select></div><div class="field"><label>Biometric ID</label><input name="biometric_id" maxlength="50" value="<?=payroll_old('biometric_id')?>" required></div><button class="btn primary">Save mapping</button></form></section>
        <section class="panel payroll-section"><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Employee no.</th><th>Biometric ID</th></tr></thead><tbody><?php foreach($maps as $m):?><tr><td><?=e($m['employee_name'])?></td><td><?=e($m['employee_no'])?></td><td><?=e($m['biometric_id'])?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php elseif($tab==='salary'):
        $rates=db()->query('SELECT r.*,CONCAT_WS(" ",e.first_name,e.last_name) employee_name,e.employee_no FROM payroll_employee_rates r JOIN employees e ON e.id=r.employee_id ORDER BY e.last_name,r.effective_from DESC LIMIT 1000')->fetchAll(); ?>
        <section class="panel payroll-section"><div class="panel-head"><div><h2>Effective-dated salary rates</h2><p>Hourly rate = salary amount ÷ hourly divisor. Enter the company's actual divisor; historical finalized earnings remain locked.</p></div></div><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('salary');?><div class="payroll-form-grid"><div class="field"><label>Employee</label><select name="employee_id" required><option value="">Select employee</option><?php payroll_employee_options($employees,(int)($old['employee_id']??0));?></select></div><div class="field"><label>Salary basis</label><select name="salary_basis"><option value="MONTHLY" <?=($old['salary_basis']??'MONTHLY')==='MONTHLY'?'selected':''?>>Monthly</option><option value="DAILY" <?=($old['salary_basis']??'')==='DAILY'?'selected':''?>>Daily</option><option value="HOURLY" <?=($old['salary_basis']??'')==='HOURLY'?'selected':''?>>Hourly (divisor 1)</option></select></div><div class="field"><label>Salary amount</label><input type="number" name="salary_amount" min="0.0001" step="0.0001" value="<?=payroll_old('salary_amount')?>" required></div><div class="field"><label>Hourly divisor</label><input type="number" name="hourly_divisor" min="0.0001" step="0.0001" value="<?=payroll_old('hourly_divisor')?>" required></div><div class="field"><label>Effective from</label><input type="date" name="effective_from" value="<?=payroll_old('effective_from')?>" required></div><div class="field"><label>Effective to (optional)</label><input type="date" name="effective_to" value="<?=payroll_old('effective_to')?>"></div></div><button class="btn primary">Add salary rate</button></form></section>
        <section class="panel payroll-section"><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Effective period</th><th>Basis</th><th>Amount / divisor</th><th>Hourly rate</th><th>End previous rate</th></tr></thead><tbody><?php foreach($rates as $r):?><tr><td><?=e($r['employee_name'].' · '.$r['employee_no'])?></td><td><?=e($r['effective_from'].' to '.($r['effective_to']??'ongoing'))?></td><td><?=e($r['salary_basis'])?></td><td><?=payroll_amount($r['salary_amount'])?> / <?=e($r['hourly_divisor'])?></td><td><?=payroll_amount((float)$r['salary_amount']/(float)$r['hourly_divisor'])?></td><td><form method="post" class="payroll-inline-form"><?php payroll_hidden_action('end_rate');?><input type="hidden" name="rate_id" value="<?=$r['id']?>"><input type="date" name="effective_to" min="<?=e($r['effective_from'])?>" required aria-label="Rate end date"><button class="btn sm">Set end</button></form></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php elseif($tab==='rules'):
        $rules=PayrollAttendanceService::rules(); $settings=PayrollAttendanceService::settings(); $paytypes=db()->query('SELECT p.*,t.name,t.code FROM payroll_request_pay_rules p JOIN payroll_request_types t ON t.id=p.request_type_id')->fetchAll(); $holidays=db()->query('SELECT h.*,b.name branch_name FROM payroll_holidays h LEFT JOIN branches b ON b.id=h.branch_id ORDER BY h.holiday_date DESC LIMIT 250')->fetchAll(); ?>
        <?php if(($old['action']??'')==='payroll_v2_rules') { $settings=array_replace($settings,array_intersect_key($old,array_flip(['night_start','night_end']))); foreach($rules as $type=>&$rule) foreach(['regular_multiplier','ot_multiplier','night_percent'] as $field) if(isset($old[$field][$type])) $rule[$field]=$old[$field][$type]; unset($rule); foreach($paytypes as &$p) if(isset($old['pay_treatment'][$p['request_type_id']])) $p['pay_treatment']=$old['pay_treatment'][$p['request_type_id']]; unset($p); } ?>
        <section class="panel payroll-section"><div class="panel-head"><div><h2>Company OT and night rules</h2><p>Use HR-approved formulas. Blank rates remain unconfigured and block finalization when needed.</p></div></div><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('rules');?><div class="payroll-form-grid"><div class="field"><label>Night period start</label><input type="time" name="night_start" value="<?=e(substr($settings['night_start']??'',0,5))?>" required></div><div class="field"><label>Night period end</label><input type="time" name="night_end" value="<?=e(substr($settings['night_end']??'',0,5))?>" required></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Day type</th><th>Regular multiplier</th><th>Total OT multiplier</th><th>Night premium % of OT pay</th></tr></thead><tbody><?php foreach($rules as $type=>$r):?><tr><td><?=e(stage_label($type))?></td><?php foreach(['regular_multiplier','ot_multiplier','night_percent'] as $field):?><td><input aria-label="<?=e(stage_label($type).' '.stage_label($field))?>" type="number" name="<?=$field?>[<?=e($type)?>]" min="0" max="<?=$field==='night_percent'?500:20?>" step="0.0001" value="<?=e((string)($r[$field]??''))?>"></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><p class="small muted">OT amount uses the total OT multiplier against base hourly pay. Night premium is added once, as a percentage of that OT amount. Regular, night, holiday and holiday-night OT hours are mutually exclusive.</p><fieldset class="payroll-checks"><legend>Unpaid request treatment</legend><?php foreach($paytypes as $p):?><div class="field"><label><?=e($p['name'])?></label><select name="pay_treatment[<?=$p['request_type_id']?>]"><?php foreach(['REVIEW'=>'Unconfirmed — block payroll','PAYABLE'=>'Back-pay claim — payable','NO_PAY'=>'Intentionally unpaid'] as $value=>$label):?><option value="<?=$value?>" <?=$p['pay_treatment']===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div><?php endforeach;?></fieldset><button class="btn primary">Save company formulas</button></form></section>
        <section class="panel payroll-section"><div class="panel-head"><div><h2>Holiday calendar</h2><p>Branch-specific dates override the company-wide calendar. Holidays are not guessed.</p></div></div><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('holiday');?><div class="payroll-form-grid"><div class="field"><label>Holiday date</label><input type="date" name="holiday_date" required></div><div class="field"><label>Name</label><input name="name" maxlength="160" required></div><div class="field"><label>Classification</label><select name="day_type"><option value="REGULAR_HOLIDAY">Regular holiday</option><option value="SPECIAL_HOLIDAY">Special holiday</option><option value="DOUBLE_HOLIDAY">Double holiday</option></select></div><div class="field"><label>Scope</label><select name="branch_id"><option value="0">Company-wide</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>"><?=e($b['name'])?></option><?php endforeach;?></select></div></div><button class="btn primary">Save holiday</button></form><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Date</th><th>Holiday</th><th>Type</th><th>Scope</th></tr></thead><tbody><?php foreach($holidays as $h):?><tr><td><?=e($h['holiday_date'])?></td><td><?=e($h['name'])?></td><td><?=e(stage_label($h['day_type']))?></td><td><?=e($h['branch_name']??'Company-wide')?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php endif; unset($_SESSION['payroll_form_old']); render_portal_footer();
}
