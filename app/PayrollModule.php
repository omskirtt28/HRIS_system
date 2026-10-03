<?php
declare(strict_types=1);

function payroll_post_action(): void
{
    $action=(string)($_POST['action']??'');
    if($_SERVER['REQUEST_METHOD']!=='POST' || !str_starts_with($action,'payroll_v2_')) return;
    need_db(); verify_csrf();
    $back='payroll-cutoff'; $params=[];
    try {
        PayrollAttendanceService::requireReady();
        switch($action) {
            case 'payroll_v2_preview':
                $back='payroll-import';
                $id=PayrollAttendanceService::prepareImport((int)($_POST['cutoff_id']??0),$_FILES['biometric_file']??[],(int)($_POST['branch_id']??0));
                flash('success','Upload parsed. Review employee matching and coverage before importing.'); redirect('payroll-import-preview',['id'=>$id]);
            case 'payroll_v2_commit':
                $back='payroll-import-preview'; $params=['id'=>(int)($_POST['import_id']??0)];
                PayrollAttendanceService::commitImport($params['id'],(string)($_POST['covered_through']??''));
                $import=PayrollAttendanceService::import($params['id']); flash('success','Biometric rows imported. Review employee mapping, site setup and attendance before finalizing payroll.'); redirect('payroll-cutoff',['cutoff_id'=>$import['cutoff_id']]);
            case 'payroll_v2_mapping':
                $back='payroll-setup'; $params=['tab'=>'mapping']; PayrollAttendanceService::saveMapping((int)($_POST['employee_id']??0),(string)($_POST['biometric_id']??'')); break;
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

function payroll_export(int $cutoffId): never
{
    Auth::requirePermission('payroll.export'); PayrollAttendanceService::requireReady();
    $run=PayrollAttendanceService::run($cutoffId);
    audit('Payroll','EXPORT_CUTOFF','payroll_cutoff',$cutoffId,['state'=>$run['run_state']??'DRAFT']);
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="payroll-'.$run['period_start'].'-'.$run['period_end'].'.csv"'); header('Cache-Control: no-store');
    $f=fopen('php://output','wb'); fwrite($f,"\xEF\xBB\xBF");
    fputcsv($f,['Cutoff state','Employee no.','Employee','Branch','Date','Attendance status','Time In','Lunch Out','Lunch In','Time Out','Regular hours','Late minutes','Undertime minutes','Regular OT hours','Night OT hours','Holiday OT hours','Holiday Night OT hours','Intentionally unpaid OT hours','Attendance earnings','OT amount','Night premium','Attendance and OT total','Issues','Approved tickets','Back-pay amount'],',','"','');
    $q=db()->prepare('SELECT d.*,e.employee_no,CONCAT_WS(" ",e.first_name,e.last_name) employee_name,b.name branch_name FROM attendance_daily d JOIN employees e ON e.id=d.employee_id LEFT JOIN branches b ON b.id=e.branch_id WHERE d.cutoff_id=? ORDER BY e.last_name,e.first_name,d.work_date'); $q->execute([$cutoffId]);
    while($r=$q->fetch()) {
        $total=$r['regular_amount']!==null&&$r['ot_amount']!==null&&$r['night_amount']!==null?(float)$r['regular_amount']+(float)$r['ot_amount']+(float)$r['night_amount']:null;
        $cells=[$run['run_state']??'DRAFT',$r['employee_no'],$r['employee_name'],$r['branch_name'],$r['work_date'],$r['state'],$r['time_in'],$r['lunch_out'],$r['lunch_in'],$r['time_out'],payroll_hours($r['regular_minutes']),$r['late_minutes'],$r['undertime_minutes'],payroll_hours($r['regular_ot_minutes']),payroll_hours($r['night_ot_minutes']),payroll_hours($r['holiday_ot_minutes']),payroll_hours($r['holiday_night_ot_minutes']),payroll_hours($r['unpaid_ot_minutes']),$r['regular_amount'],$r['ot_amount'],$r['night_amount'],$total,implode('; ',json_decode($r['issues_json'],true)?:[]),implode('; ',array_column(json_decode($r['sources_json'],true)?:[],'no')),0];
        foreach($cells as &$value) if(is_string($value)&&preg_match('/^[=+@\-\t\r]/',$value)) $value="'".$value; unset($value);
        fputcsv($f,$cells,',','"','');
    }
    foreach(PayrollAttendanceService::backpayRows($cutoffId) as $claim) {
        $cells=array_fill(0,25,'');
        $cells[0]=$run['run_state']??'DRAFT'; $cells[1]=$claim['employee_no']; $cells[2]=$claim['employee_name']; $cells[4]=$claim['affected_date']; $cells[5]='BACKPAY_'.$claim['state'];
        $cells[21]=$claim['amount']; $cells[22]=$claim['note']; $cells[23]=$claim['request_no']; $cells[24]=$claim['amount'];
        foreach($cells as &$value) if(is_string($value)&&preg_match('/^[=+@\-\t\r]/',$value)) $value="'".$value; unset($value);
        fputcsv($f,$cells,',','"','');
    }
    fclose($f); exit;
}

function payroll_render(string $page): void
{
    $pages=['employee-attendance','payroll-import','payroll-import-preview','payroll-cutoff','payroll-attendance-day','payroll-setup','payroll-export'];
    if(!in_array($page,$pages,true)) return;
    need_db();
    $employeePage=$page==='employee-attendance';
    if($employeePage) Auth::requirePermission('attendance.view_self');
    elseif(in_array($page,['payroll-import','payroll-import-preview'],true)) Auth::requirePermission('payroll.biometric_import');
    elseif($page==='payroll-setup') Auth::requirePermission('payroll.configure');
    elseif($page==='payroll-export') Auth::requirePermission('payroll.export');
    elseif($page==='payroll-attendance-day') { if(!Auth::check()) redirect('login'); }
    else Auth::requirePermission('payroll.process');
    $kind=$employeePage?'employee':'hr';
    if(!PayrollAttendanceService::ready()) { render_portal_header($kind,$page,'Payroll setup required'); page_head('Payroll & Timekeeping','Payroll setup required'); echo '<div class="alert error">Import database/migrations/20261002_payroll_biometric_workflow.sql into your existing Phase 3A database. Do not re-import schema.sql.</div>'; render_portal_footer(); exit; }
    PayrollAttendanceService::purgeExpired();
    if($page==='payroll-export') payroll_export((int)($_GET['cutoff_id']??0));
    if($page==='payroll-setup') { payroll_setup(); exit; }
    if($page==='payroll-attendance-day') { payroll_day(); exit; }
    if($page==='payroll-import-preview') { payroll_preview(); exit; }
    $cutoffs=PayrollRepository::requestCutoffs(); $cutoffId=(int)($_GET['cutoff_id']??($cutoffs[0]['id']??0));
    if(!$cutoffId) { render_portal_header($kind,$page,'Payroll cutoffs'); echo '<div class="empty">No cutoff calendar available.</div>'; render_portal_footer(); exit; }
    $run=PayrollAttendanceService::run($cutoffId);
    if($page==='payroll-import') { payroll_import_page($cutoffs,$cutoffId); exit; }
    $employee=$employeePage?PayrollRepository::currentEmployee():null;
    if($employeePage && !$employee) { render_portal_header('employee',$page,'My Attendance'); echo '<div class="alert error">HR must link your account to your employee record first.</div>'; render_portal_footer(); exit; }
    $filter=(string)($_GET['filter']??'');
    $rows=PayrollAttendanceService::dailyRows($cutoffId,$employeePage?(int)$employee['id']:null,$filter);
    $claims=PayrollAttendanceService::backpayRows($cutoffId);
    if($employeePage) $claims=array_values(array_filter($claims,static fn($c)=>(int)$c['employee_id']===(int)$employee['id']));
    render_portal_header($kind,$page,$employeePage?'My Attendance':'Payroll Cutoff');
    $actions=$employeePage?'':'<a class="btn" href="'.url('payroll-import',['cutoff_id'=>$cutoffId]).'">Import biometrics</a><a class="btn" href="'.url('payroll-setup').'">Payroll setup</a>';
    page_head($employeePage?'Employee Self-Service / Attendance':'HR Payroll / Cutoff',$employeePage?'My Attendance':'Payroll Cutoff Review',$actions);
    payroll_cutoff_selector($cutoffs,$cutoffId,$page);
    $totals=['issues'=>0,'regular'=>0,'ot'=>0,'earnings'=>0,'pending_rates'=>0]; $summary=[];
    foreach($rows as $r) {
        if(in_array($r['state'],['ISSUES','AWAITING_LOGS'],true)) $totals['issues']++;
        $totals['regular']+=(int)$r['regular_minutes']; $ot=(int)$r['regular_ot_minutes']+(int)$r['night_ot_minutes']+(int)$r['holiday_ot_minutes']+(int)$r['holiday_night_ot_minutes']; $totals['ot']+=$ot;
        $amount=$r['regular_amount']!==null&&$r['ot_amount']!==null&&$r['night_amount']!==null?(float)$r['regular_amount']+(float)$r['ot_amount']+(float)$r['night_amount']:null;
        if($amount===null) $totals['pending_rates']++; else $totals['earnings']+=$amount;
        $k=(int)$r['employee_id']; if(!isset($summary[$k])) $summary[$k]=['name'=>$r['employee_name'],'no'=>$r['employee_no'],'regular'=>0,'ot'=>0,'earnings'=>0,'pending'=>0];
        $summary[$k]['regular']+=(int)$r['regular_minutes']; $summary[$k]['ot']+=$ot; $summary[$k]['earnings']+=$amount??0; if($amount===null) $summary[$k]['pending']++;
    }
    foreach($claims as $c) {
        $k=(int)$c['employee_id']; if(!isset($summary[$k])) $summary[$k]=['name'=>$c['employee_name'],'no'=>$c['employee_no'],'regular'=>0,'ot'=>0,'earnings'=>0,'pending'=>0];
        if($c['amount']===null) { $totals['pending_rates']++; $summary[$k]['pending']++; } else { $totals['earnings']+=(float)$c['amount']; $summary[$k]['earnings']+=(float)$c['amount']; }
        if(in_array($c['state'],['NEEDS_REVIEW','AWAITING_APPROVAL'],true)) $totals['issues']++;
    }
    ?>
    <div class="payroll-metrics"><?php metric_card('Attendance records',count($rows),'This cutoff','clock'); metric_card('Needs attention',$totals['issues'],'Missing logs, configuration or conflicts','alert'); metric_card('Verified OT hours',payroll_hours($totals['ot']),'Approved and matched to punches','briefcase'); metric_card('Attendance + OT',$totals['pending_rates']?'Pending computation':payroll_amount($totals['earnings']),'Attendance earnings before payroll deductions','chart'); ?></div>
    <div class="payroll-banner"><strong><?=e($run['run_state']??'DRAFT')?></strong><span>Complete log coverage: <?=e($run['covered_through']??'Awaiting import')?> · Ticket/approval deadline: <?=e($run['ticket_deadline']??'Not set')?></span></div>
    <?php if(!$employeePage): ?>
    <div class="payroll-toolbar">
      <a class="btn" href="<?=url($page,['cutoff_id'=>$cutoffId,'filter'=>$filter==='ISSUES'?'':'ISSUES'])?>"><?=$filter==='ISSUES'?'Show all days':'Show issues only'?></a>
      <?php if(Auth::can('payroll.export')):?><a class="btn" href="<?=url('payroll-export',['cutoff_id'=>$cutoffId])?>">Export CSV for Excel</a><?php endif;?>
      <?php if($run['run_state']!=='FINALIZED'):?><form method="post"><?php payroll_hidden_action('rebuild');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><button class="btn">Recalculate cutoff</button></form>
      <?php if(Auth::can('payroll.finalize')):?><form method="post" data-payroll-confirm="Finalize and lock this cutoff? Attendance and earnings will become read-only."><?php payroll_hidden_action('finalize');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><button class="btn primary">Finalize cutoff</button></form><?php endif; endif;?>
    </div>
    <?php if($run['run_state']!=='FINALIZED'):?><section class="panel payroll-section"><div class="panel-head"><div><h2>Ticket and approval deadline</h2><p>Late filing/approval requires an explicit deadline extension. Finalized cutoffs stay locked.</p></div></div><form method="post" class="panel-body payroll-inline-form"><?php payroll_hidden_action('deadline');?><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><div class="field"><label>Deadline (Asia/Manila)</label><input type="datetime-local" name="ticket_deadline" value="<?=e(!empty($run['ticket_deadline'])?date('Y-m-d\TH:i',strtotime($run['ticket_deadline'])):'')?>"></div><button class="btn">Save deadline</button></form></section><?php endif;?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Employee cutoff totals</h2><p>These are attendance and OT earnings; statutory deductions, paid-leave valuation and net payslips are separate.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Regular hours</th><th>OT hours</th><th>Attendance + OT</th></tr></thead><tbody><?php foreach($summary as $s):?><tr><td><strong><?=e($s['name'])?></strong><small><?=e($s['no'])?></small></td><td><?=payroll_hours($s['regular'])?></td><td><?=payroll_hours($s['ot'])?></td><td><?=$s['pending']?'Pending computation':payroll_amount($s['earnings'])?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php endif;?>
    <?php if($claims):?><section class="panel payroll-section"><div class="panel-head"><div><h2>Previous unpaid claims</h2><p>Additional amounts enter this payout cutoff. Original finalized attendance remains unchanged.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Ticket</th><th>Affected date</th><th>Status</th><th>Additional amount</th><th>Review note</th></tr></thead><tbody><?php foreach($claims as $c):?><tr><td><?=e($c['employee_name'])?></td><td><a href="<?=url('payroll-request',['id'=>$c['request_id']])?>"><?=e($c['request_no'].' · '.$c['type_code'])?></a></td><td><?=e($c['affected_date'])?></td><td><?=e(stage_label($c['state']))?></td><td><?=payroll_amount($c['amount'])?></td><td><?=e($c['note']??'')?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Daily attendance</h2><p>Original logs remain preserved. Approved TA/OB/OT is linked by employee and date.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table payroll-attendance-table"><thead><tr><?php if(!$employeePage):?><th>Employee</th><?php endif;?><th>Date</th><th>In</th><th>Lunch Out</th><th>Lunch In</th><th>Out</th><th>Status / issues</th><th>Hours</th><th>OT categories</th><th></th></tr></thead><tbody>
    <?php if(!$rows):?><tr><td colspan="10" class="empty">No attendance records yet. HR Payroll needs to map biometric IDs and import this cutoff.</td></tr><?php endif; foreach($rows as $r):?>
    <tr class="<?=in_array($r['state'],['ISSUES','AWAITING_LOGS'],true)?'payroll-issue-row':''?>"><?php if(!$employeePage):?><td><strong><?=e($r['employee_name'])?></strong><small><?=e($r['employee_no'].' · '.$r['branch_name'])?></small></td><?php endif;?><td><?=e($r['work_date'])?></td><?php foreach(PayrollCalculator::FIELDS as $field):?><td class="<?=$r[$field]===null?'payroll-missing':''?>"><?=payroll_clock($r[$field])?><?php if($r[$field]!==$r['original_'.$field]):?><small>Adjusted</small><?php endif;?></td><?php endforeach;?><td><span class="badge <?=in_array($r['state'],['ISSUES','AWAITING_LOGS'],true)?'amber':'green'?>"><?=e(stage_label($r['state']))?></span><small><?=e(implode(', ',array_map('stage_label',json_decode($r['issues_json'],true)?:[])))?></small></td><td><?=payroll_hours($r['regular_minutes'])?></td><td><small>Regular <?=payroll_hours($r['regular_ot_minutes'])?> · Night <?=payroll_hours($r['night_ot_minutes'])?></small><small>Holiday <?=payroll_hours($r['holiday_ot_minutes'])?> · Holiday night <?=payroll_hours($r['holiday_night_ot_minutes'])?> · Unpaid <?=payroll_hours($r['unpaid_ot_minutes'])?></small></td><td><a class="btn sm" href="<?=url('payroll-attendance-day',['id'=>$r['id']])?>">View day</a><?php if($employeePage && $run['run_state']!=='FINALIZED'):?><a class="btn primary sm" href="<?=url('employee-request-new',['date'=>$r['work_date'],'type'=>'TA'])?>">File TA</a><?php endif;?></td></tr>
    <?php endforeach;?></tbody></table></div></section>
    <?php render_portal_footer(); exit;
}

function payroll_import_page(array $cutoffs,int $cutoffId): void
{
    render_portal_header('hr','payroll-import','Biometric Import'); page_head('HR Payroll / Attendance','Import Biometric Logs');
    $q=db()->query('SELECT i.*,c.period_start,c.period_end FROM attendance_imports i JOIN payroll_cutoffs c ON c.id=i.cutoff_id ORDER BY i.id DESC LIMIT 50'); $imports=$q->fetchAll();
    // Organization sites remain selectable even before their payroll schedule is configured.
    $sites=db()->query('SELECT b.id branch_id,b.name,b.code,CASE WHEN s.shift_start IS NOT NULL AND s.shift_end IS NOT NULL AND s.break_minutes IS NOT NULL THEN 1 ELSE 0 END schedule_ready FROM branches b LEFT JOIN payroll_site_settings s ON s.branch_id=b.id WHERE b.active=1 ORDER BY b.name,b.code,b.id')->fetchAll();
    $old=$_SESSION['payroll_form_old']??[];
    $old=($old['action']??'')==='payroll_v2_preview'?$old:[];
    $cutoffId=(int)($old['cutoff_id']??$cutoffId); $siteId=(int)($old['branch_id']??($_GET['branch_id']??0));
    $setupUrl=url('payroll-setup',['tab'=>'sites']); ?>
    <?php if(!$sites):?><div class="alert error">No active branches or Head Office are available. <?php if(Auth::can('organization.view')):?><a href="<?=url('admin-organization')?>">Open Organization Setup</a> to add or activate the correct site.<?php else:?>Ask your administrator to add or activate the correct site in Organization Setup.<?php endif;?></div><?php endif;?>
    <section class="panel payroll-section">
      <div class="panel-head"><div><h2>Upload cutoff export</h2><p>PDF, CSV or XLSX · up to 20 MB / 100,000 rows. Review before committing.</p></div></div>
      <form method="post" enctype="multipart/form-data" class="panel-body payroll-form">
        <?php payroll_hidden_action('preview');?>
        <div class="field"><label for="payroll-import-cutoff">Cutoff</label><select id="payroll-import-cutoff" name="cutoff_id" required><?php foreach($cutoffs as $c):?><option value="<?=$c['id']?>" <?=(int)$c['id']===$cutoffId?'selected':''?>><?=e($c['period_start'].' to '.$c['period_end'])?></option><?php endforeach;?></select></div>
        <div class="field">
          <label for="payroll-import-site">Site covered by this export</label>
          <select id="payroll-import-site" name="branch_id" required <?=$sites?'':'disabled'?>>
            <option value="">Choose branch / Head Office</option>
            <?php foreach($sites as $s):?><option value="<?=$s['branch_id']?>" data-schedule-ready="<?=(int)$s['schedule_ready']?>" <?=(int)$s['branch_id']===$siteId?'selected':''?>><?=e($s['name'].(!empty($s['code'])?' · '.$s['code']:'').(!$s['schedule_ready']?' · Payroll setup pending':''))?></option><?php endforeach;?>
          </select>
          <small>Import each site separately so other branches remain awaiting logs.</small>
          <small id="payroll-import-site-note">Choose the site that owns these logs. You can import before completing its payroll schedule setup.</small>
          <?php if(Auth::can('payroll.configure')):?><small><a id="payroll-import-site-setup" data-setup-url="<?=e($setupUrl)?>" href="<?=e($siteId?url('payroll-setup',['tab'=>'sites','branch_id'=>$siteId]):$setupUrl)?>">Payroll site setup</a></small><?php endif;?>
        </div>
        <div class="field"><label for="payroll-import-file">Biometric export</label><input id="payroll-import-file" type="file" name="biometric_file" accept=".pdf,.csv,.xlsx" required><small>Text PDFs in the OGAL format are supported. CSV/XLSX headers: No., Date/Time, Status.</small></div>
        <p class="small muted">Uploaded files expire 48 hours after a successful import. Parsed attendance and payroll history are retained. Uncommitted previews expire after two hours.</p>
        <button class="btn primary" type="submit" <?=$sites?'':'disabled'?>>Preview import</button>
      </form>
    </section>
    <script>
    (() => {
        const site = document.getElementById('payroll-import-site');
        const note = document.getElementById('payroll-import-site-note');
        const setup = document.getElementById('payroll-import-site-setup');
        const updateSite = () => {
            const selected = site.selectedOptions[0];
            note.textContent = !site.value
                ? 'Choose the site that owns these logs. You can import before completing its payroll schedule setup.'
                : selected.dataset.scheduleReady === '1'
                    ? 'Site schedule configured. Review employee ID mapping before importing.'
                    : 'You can preview and import these logs now. Complete this site\'s payroll schedule before calculating earnings.';
            if (setup) {
                const target = new URL(setup.dataset.setupUrl, window.location.href);
                if (site.value) target.searchParams.set('branch_id', site.value);
                setup.href = target.href;
                setup.textContent = site.value ? 'Configure selected site' : 'Payroll site setup';
            }
        };
        site.addEventListener('change', updateSite);
        updateSite();
    })();
    </script>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Import history</h2><p>Duplicate punches are ignored; unknown IDs remain available for mapping.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Upload</th><th>Cutoff</th><th>Rows</th><th>Status</th><th>Original file expiry</th><th></th></tr></thead><tbody><?php foreach($imports as $i):?><tr><td><?=e($i['original_name'])?></td><td><?=e($i['period_start'].' / '.$i['period_end'])?></td><td><?=$i['row_count']?></td><td><?=e($i['state'])?></td><td><?=e($i['deleted_at']?'Deleted '.$i['deleted_at']:$i['expires_at'])?></td><td><a class="btn sm" href="<?=url('payroll-import-preview',['id'=>$i['id']])?>">View</a></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php if($old) unset($_SESSION['payroll_form_old']); render_portal_footer();
}

function payroll_preview(): void
{
    $id=(int)($_GET['id']??0); $import=PayrollAttendanceService::import($id); $run=PayrollAttendanceService::run((int)$import['cutoff_id']); $rows=PayrollAttendanceService::previewRows($id);
    $site=PayrollAttendanceService::site((int)$import['branch_id']);
    $scheduleReady=!empty($site['shift_start'])&&!empty($site['shift_end'])&&isset($site['break_minutes']);
    $counts=PayrollAttendanceService::previewCounts($id);
    render_portal_header('hr','payroll-import','Import Preview'); page_head('HR Payroll / Import','Import #'.$id,'<a class="btn" href="'.url('payroll-import').'">Back to imports</a>'); ?>
    <div class="payroll-banner"><strong><?=e($import['original_name'])?></strong><span><?=e($import['branch_name'])?> · <?=e($import['state'])?> · <?=$import['row_count']?> rows · <?=e($run['period_start'].' to '.$run['period_end'])?></span></div>
    <?php if(!$scheduleReady):?><div class="payroll-banner"><span>Payroll schedule setup is pending for <?=e($import['branch_name'])?>. You can import these logs now; earnings remain pending until the site is configured and the cutoff is recalculated. <?php if(Auth::can('payroll.configure')):?><a href="<?=url('payroll-setup',['tab'=>'sites','branch_id'=>$import['branch_id']])?>">Configure this site</a><?php else:?>Ask your payroll administrator to configure this site.<?php endif;?></span></div><?php endif;?>
    <div class="payroll-toolbar"><?php foreach($counts as $state=>$count):?><span class="badge <?=in_array($state,['UNMAPPED','SITE_MISMATCH'],true)?'amber':'gray'?>"><?=e(stage_label($state))?>: <?=(int)$count?></span><?php endforeach;?><?php if(Auth::can('payroll.configure')):?><a class="btn sm" href="<?=url('payroll-setup',['tab'=>'mapping'])?>">Map biometric IDs</a><?php endif;?></div>
    <?php if($import['state']==='PREVIEW'):?><p class="small muted">Saved biometric IDs identify employees first. IDs that exactly match an employee number are detected automatically when that employee has no saved biometric ID. These matches are saved when you confirm the import.</p><?php endif;?>
    <?php if(!empty($counts['SITE_MISMATCH'])):?><div class="alert error"><?=(int)$counts['SITE_MISMATCH']?> punch rows match employees assigned to a different site from <?=e($import['branch_name'])?>. Check the employee sites shown below, then <a href="<?=url('payroll-import',['cutoff_id'=>$import['cutoff_id']])?>">upload for the correct site</a> or split the export by site.</div><?php endif;?>
    <?php if($import['state']==='PREVIEW'):?><section class="panel payroll-section"><div class="panel-head"><div><h2>Confirm complete log coverage</h2><p>Only mark dates with a complete export for all mapped employees. Later days stay awaiting logs.</p></div></div><form method="post" class="panel-body payroll-inline-form"><?php payroll_hidden_action('commit');?><input type="hidden" name="import_id" value="<?=$id?>"><div class="field"><label>Complete through date</label><input type="date" name="covered_through" min="<?=e($run['period_start'])?>" max="<?=e(min($run['period_end'],date('Y-m-d',strtotime('-1 day'))))?>" required><small>Choose a completed day. Unknown IDs can be matched later without re-uploading.</small></div><button class="btn primary">Import and match approved tickets</button></form></section><?php endif;?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Punch preview</h2><p>First 250 rows. Full row count and matching totals are shown above.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Row</th><th>Biometric ID</th><th>Employee</th><th>Date/time</th><th>Status</th><th>Match</th></tr></thead><tbody><?php foreach($rows as $r):?><tr class="<?=in_array($r['row_state'],['UNMAPPED','SITE_MISMATCH'],true)?'payroll-issue-row':''?>"><td><?=$r['row_no']?></td><td><?=e($r['biometric_id'])?></td><td><?=e($r['employee_name']?:'Unknown ID')?><?php if($r['employee_name']):?><small><?=e($r['employee_branch_name']?:'Site not assigned')?></small><?php endif;?></td><td><?=e($r['punched_at'])?></td><td><?=e($r['punch_status'])?></td><td><?=e(stage_label($r['row_state']))?><?php if(($r['match_source']??'')==='EMPLOYEE_NUMBER'):?><small>Matched employee number</small><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php render_portal_footer();
}

function payroll_day(): void
{
    $d=PayrollAttendanceService::daily((int)($_GET['id']??0)); $emp=PayrollRepository::currentEmployee(); $own=$emp&&(int)$emp['id']===(int)$d['employee_id']&&Auth::can('attendance.view_self');
    if(!$own&&!Auth::can('attendance.view_all')&&!Auth::can('payroll.process')) { http_response_code(403); exit('Access denied'); }
    $d=PayrollAttendanceService::detail((int)$d['id']);
    $kind=$own?'employee':'hr'; $run=PayrollAttendanceService::run((int)$d['cutoff_id']);
    render_portal_header($kind,$own?'employee-attendance':'payroll-cutoff','Attendance Day'); page_head('Attendance / Daily Review',$d['employee_name'].' · '.$d['work_date'],'<a class="btn" href="'.url($own?'employee-attendance':'payroll-cutoff',['cutoff_id'=>$d['cutoff_id']]).'">Back to cutoff</a>'); ?>
    <div class="payroll-banner"><strong><?=e(stage_label($d['state']))?></strong><span><?=e(implode(' · ',array_map('stage_label',json_decode($d['issues_json'],true)?:[])))?></span></div>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Original and effective attendance</h2><p>Corrections are separate from biometric source records.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Punch</th><th>Original</th><th>Effective</th></tr></thead><tbody><?php foreach(PayrollCalculator::FIELDS as $f):?><tr><td><?=e(stage_label($f))?></td><td><?=e($d['original_'.$f]??'Missing / ambiguous')?></td><td><?=e($d[$f]??'Missing')?></td></tr><?php endforeach;?></tbody></table></div></section>
    <div class="payroll-toolbar"><?php if($own && $run['run_state']!=='FINALIZED'):?><?php foreach(['TA'=>'File Time Adjustment','OB'=>'File Official Business','OT'=>'File Overtime'] as $type=>$label):?><a class="btn <?=$type==='TA'?'primary':''?>" href="<?=url('employee-request-new',['date'=>$d['work_date'],'type'=>$type])?>"><?=e($label)?></a><?php endforeach;?><?php endif;?></div>
    <?php if(Auth::can('payroll.process') && $run['run_state']!=='FINALIZED'):?>
    <?php $snapshot=json_decode($d['rate_snapshot_json'],true)?:[]; $rawIds=array_map('intval',$snapshot['raw_punch_ids']??[]); $sourceTypes=array_column(json_decode($d['sources_json'],true)?:[],'type');
    if($rawIds && (in_array($d['state'],['ISSUES','AWAITING_LOGS'],true)||in_array('VERIFIED_PUNCH_SELECTION',$sourceTypes,true))):?><details class="panel payroll-section"><summary class="panel-head">Verify ambiguous or extra biometric punches</summary><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('select_punches');?><input type="hidden" name="source_hash" value="<?=e($snapshot['punch_source_hash']??'')?>"><input type="hidden" name="daily_id" value="<?=$d['id']?>"><p class="small muted">Choose actual biometric events for each slot. Missing times require an approved TA. Unselected raw events stay preserved.</p><div class="payroll-form-grid"><?php foreach(PayrollCalculator::FIELDS as $field):?><div class="field"><label><?=e(stage_label($field))?></label><select name="<?=$field?>"><option value="0">Missing / no selected raw punch</option><?php foreach($d['punches'] as $p): if(!in_array((int)$p['id'],$rawIds,true))continue;?><option value="<?=$p['id']?>" <?=$p['punched_at']===$d['original_'.$field]?'selected':''?>><?=e($p['punched_at'].' · '.$p['punch_status'])?></option><?php endforeach;?></select></div><?php endforeach;?></div><div class="field"><label>Verification reason</label><textarea name="remarks" required maxlength="255"></textarea></div><button class="btn primary">Save verified selection</button></form></details><?php endif;?>
    <?php foreach($d['conflicts'] as $field=>$c):?><section class="panel payroll-section"><div class="panel-head"><div><h2><?=e(stage_label($field))?> conflict</h2><p>Select the verified value. This records a resolution, not another ticket approval.</p></div></div><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('resolve');?><input type="hidden" name="daily_id" value="<?=$d['id']?>"><input type="hidden" name="punch_field" value="<?=e($field)?>"><input type="hidden" name="source_hash" value="<?=e($c['hash'])?>"><div class="field"><label>Value to retain</label><select name="request_id"><option value="0">Original: <?=e($c['raw']??'Missing')?></option><?php foreach($c['proposals'] as $p):?><option value="<?=$p['request_id']?>">Approved ticket #<?=$p['request_id']?>: <?=e($p['value'])?></option><?php endforeach;?></select></div><div class="field"><label>Resolution reason</label><textarea name="remarks" required maxlength="255"></textarea></div><button class="btn primary">Record resolution</button></form></section><?php endforeach;?>
    <?php if($d['state']==='ISSUES' && $d['state']!=='AWAITING_LOGS'):?><details class="panel payroll-section"><summary class="panel-head">Record zero attendance credit</summary><form method="post" class="panel-body payroll-form" data-payroll-confirm="Record zero attendance credit for this day? Approved OT and configuration issues still require resolution."><?php payroll_hidden_action('disposition');?><input type="hidden" name="source_hash" value="<?=e($snapshot['attendance_source_hash']??'')?>"><input type="hidden" name="daily_id" value="<?=$d['id']?>"><p class="small muted">Use for verified absence/unpaid incomplete attendance after checking tickets. Original logs remain unchanged.</p><div class="field"><label>Payroll disposition reason</label><textarea name="remarks" required maxlength="255"></textarea></div><button class="btn">Record zero credit</button></form></details><?php endif; endif;?>
    <?php if(Auth::can('payroll.process') && $run['run_state']!=='FINALIZED' && in_array('ZERO_CREDIT',array_column(json_decode($d['sources_json'],true)?:[],'type'),true)):?><details class="panel payroll-section"><summary class="panel-head">Withdraw recorded zero attendance credit</summary><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('clear_disposition');?><input type="hidden" name="daily_id" value="<?=$d['id']?>"><input type="hidden" name="source_hash" value="<?=e((json_decode($d['rate_snapshot_json'],true)?:[])['attendance_source_hash']??'')?>"><p class="small muted">Return this draft day to normal attendance computation. The earlier zero-credit decision remains in the audit history.</p><div class="field"><label>Withdrawal reason</label><textarea name="remarks" required maxlength="255"></textarea></div><button class="btn">Withdraw and recalculate</button></form></details><?php endif;?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Linked approved tickets</h2><p>Employee, affected date and punch field determine matching.</p></div></div><div class="panel-body"><?php if(!$d['requests']):?><div class="empty">No approved tickets for this day.</div><?php endif; foreach($d['requests'] as $r):?><a class="btn" href="<?=url('payroll-request',['id'=>$r['id']])?>"><?=e($r['request_no'].' · '.$r['type_code'])?></a><?php endforeach;?></div></section>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Raw biometric punches</h2><p>Includes the next day for overnight attendance review.</p></div></div><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Date/time</th><th>Event</th></tr></thead><tbody><?php foreach($d['punches'] as $p):?><tr><td><?=e($p['punched_at'])?></td><td><?=e($p['punch_status'])?></td></tr><?php endforeach;?></tbody></table></div></section>
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
        $approvers=db()->query('SELECT u.id,u.full_name,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status="ACTIVE" ORDER BY u.full_name')->fetchAll();
        $sites=db()->query('SELECT s.*,b.name branch_name,u.full_name approver_name FROM payroll_site_settings s JOIN branches b ON b.id=s.branch_id LEFT JOIN users u ON u.id=s.approver_user_id ORDER BY b.name')->fetchAll(); ?>
        <section class="panel payroll-section"><div class="panel-head"><div><h2>Schedule and approval routing</h2><p>Head Office uses the employee's reporting Manager; Branch uses its assigned ADL. Give ADLs Payroll → Approve branch payroll tickets permission.</p></div></div><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('site');?><div class="payroll-form-grid"><div class="field"><label>Branch / site</label><select name="branch_id" required><option value="">Choose site</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=(int)$b['id']===$siteId?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Workplace</label><select name="workplace"><option value="HEAD_OFFICE" <?=($site['workplace']??'')==='HEAD_OFFICE'?'selected':''?>>Head Office</option><option value="BRANCH" <?=($site['workplace']??'BRANCH')==='BRANCH'?'selected':''?>>Branch</option></select></div><div class="field"><label>Branch ADL (Head Office uses Reporting To)</label><select name="approver_user_id"><option value="">Not applicable to Head Office</option><?php foreach($approvers as $a):?><option value="<?=$a['id']?>" <?=(int)($site['approver_user_id']??0)===(int)$a['id']?'selected':''?>><?=e($a['full_name'].' · '.$a['role_name'])?></option><?php endforeach;?></select></div><div class="field"><label>Shift start</label><input type="time" name="shift_start" value="<?=e(substr($site['shift_start']??'08:00',0,5))?>" required></div><div class="field"><label>Shift end</label><input type="time" name="shift_end" value="<?=e(substr($site['shift_end']??'17:00',0,5))?>" required></div><div class="field"><label>Allowed break minutes</label><input type="number" name="break_minutes" value="<?=e((string)($site['break_minutes']??''))?>" min="0" max="240" step="1" required><small>Flexible break timing; set the confirmed company duration.</small></div></div><fieldset class="payroll-checks"><legend>Scheduled weekdays</legend><?php foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $n=>$label):?><label><input type="checkbox" name="workdays[]" value="<?=$n?>" <?=in_array((string)$n,explode(',',$site['workdays']??'1,2,3,4,5'),true)?'checked':''?>><?=$label?></label><?php endforeach;?></fieldset><button class="btn primary">Save site configuration</button></form></section>
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
