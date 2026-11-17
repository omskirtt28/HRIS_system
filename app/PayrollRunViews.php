<?php
declare(strict_types=1);

/** Payroll engine P2-P5 screens (migration 046): runs list, run register, payslips. */
function payroll_peso(mixed $v): string { $f=round((float)$v,2); return ($f<0?'-':'').'₱'.number_format(abs($f),2); }

function payroll_runs_page(): void
{
    render_portal_header('hr','payroll-runs','Payroll Runs'); page_head('HR Payroll / Pay','Payroll Runs');
    if(!PayrollRun::ready()) { echo '<div class="alert error">Apply database/migrations/20261117_046_payroll_runs.sql first.</div>'; render_portal_footer(); return; }
    $cutoffs=PayrollRun::cutoffs(); $runs=PayrollRun::runs(); $open=array_filter($cutoffs,fn($c)=>$c['run_status']!=='RELEASED'); ?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Compute payroll</h2><p>Uses the finalized cutoff attendance, employee rates and OT rules (Payroll Setup) and verified statutory tables in force on the pay date. Computing again replaces the unreleased figures.</p></div></div>
      <?php if(!$open):?><div class="empty">No finalized cutoff is waiting for payroll. Finalize a cutoff in Attendance Export first.</div><?php else:?>
      <form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('run_compute');?><div class="payroll-form-grid">
        <div class="field"><label for="run-cutoff">Finalized cutoff</label><select id="run-cutoff" name="cutoff_id" required><?php foreach($open as $c):?><option value="<?=$c['id']?>"><?=e($c['code'].' · '.$c['period_start'].' to '.$c['period_end'].($c['run_status']?' (recompute)':''))?></option><?php endforeach;?></select></div>
        <div class="field"><label for="run-pay">Pay date</label><input id="run-pay" type="date" name="pay_date" required></div>
      </div><button class="btn primary">Compute payroll</button></form><?php endif;?>
      <p class="small muted">Setup: <a href="<?=url('payroll-setup',['tab'=>'salary'])?>">Employee rates</a> · <a href="<?=url('payroll-setup',['tab'=>'rules'])?>">OT & holidays</a> · <a href="<?=url('payroll-statutory')?>">Statutory Tables</a></p></section>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Runs</h2></div></div>
      <?php if(!$runs):?><div class="empty">No payroll runs yet.</div><?php else:?>
      <div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Cutoff</th><th>Pay date</th><th>Employees</th><th>Gross</th><th>Net</th><th>Status</th><th>Journal</th><th></th></tr></thead><tbody>
      <?php foreach($runs as $r):?><tr><td><?=e($r['cutoff_code'])?><div class="small muted"><?=e($r['period_start'].' to '.$r['period_end'])?></div></td><td><?=e($r['pay_date'])?></td><td><?=(int)$r['employee_count']?><?=$r['issue_count']?' <span class="badge">'.(int)$r['issue_count'].' with issues</span>':''?></td>
        <td><?=payroll_peso($r['total_gross'])?></td><td><?=payroll_peso($r['total_net'])?></td><td><?=payroll_run_badge($r['status'])?></td><td><?=$r['entry_no']?'<a href="'.e(url('accounting-entry',['id'=>$r['entry_id']])).'">'.e($r['entry_no']).'</a>':'—'?></td>
        <td><a class="btn sm" href="<?=url('payroll-run',['id'=>$r['id']])?>">Open</a></td></tr><?php endforeach;?>
      </tbody></table></div><?php endif;?></section>
    <?php render_portal_footer();
}

function payroll_run_badge(string $s): string { return ['COMPUTED'=>'<span class="badge blue">Computed</span>','RELEASED'=>'<span class="badge green">Released</span>','VOIDED'=>'<span class="badge gray">Voided</span>'][$s]??e($s); }

/** Register columns: per-code amounts from an employee's lines. */
function payroll_run_cols(array $lines): array
{
    $c=['EARN'=>0.0,'SSS'=>0.0,'PHILHEALTH'=>0.0,'PAGIBIG'=>0.0,'WHT'=>0.0,'ADJ'=>0.0];
    foreach($lines as $l) { if($l['kind']==='EARNING') $c['EARN']+=(float)$l['amount']; elseif(isset($c[$l['code']])) $c[$l['code']]+=(float)$l['amount']; elseif($l['code']==='CARRY') $c['ADJ']+=(float)$l['amount']; }
    return $c;
}

function payroll_run_csv(array $r): never
{
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="payroll-register-'.preg_replace('/[^A-Za-z0-9_-]/','',$r['cutoff_code']).'.csv"'); header('Cache-Control: no-store, private');
    $out=fopen('php://output','w'); fputcsv($out,['Employee no.','Employee','Gross','SSS','PhilHealth','Pag-IBIG','Withholding tax','Other deductions','Net','Employer share','Issues']);
    $cell=fn($v)=>preg_match('/^[=+\-@\t\r]/',(string)$v)?"'".$v:$v; // spreadsheet formula injection guard
    foreach($r['employees'] as $e) { $c=payroll_run_cols($e['lines']); fputcsv($out,[$cell($e['employee_no']),$cell($e['employee_name']),number_format((float)$e['gross'],2,'.',''),number_format($c['SSS'],2,'.',''),number_format($c['PHILHEALTH'],2,'.',''),number_format($c['PAGIBIG'],2,'.',''),number_format($c['WHT'],2,'.',''),number_format($c['ADJ'],2,'.',''),number_format((float)$e['net'],2,'.',''),number_format((float)$e['employer_share'],2,'.',''),$cell(implode(' | ',json_decode($e['issues_json'],true)?:[]))]); }
    fclose($out); exit;
}

function payroll_run_page(int $id): void
{
    try { $r=PayrollRun::run($id); } catch(RuntimeException) { flash('error','Payroll run not found.'); redirect('payroll-runs'); }
    if(($_GET['format']??'')==='csv') payroll_run_csv($r);
    $me=(int)Auth::user()['id']; $editable=$r['status']==='COMPUTED';
    render_portal_header('hr','payroll-runs','Payroll Run'); page_head('HR Payroll / Payroll Runs','Payroll '.$r['cutoff_code'],'<a class="btn" href="'.e(url('payroll-runs')).'">Back to runs</a> <a class="btn" href="'.e(url('payroll-run',['id'=>$id,'format'=>'csv'])).'">Download register (CSV)</a>'); ?>
    <section class="panel payroll-section"><div class="panel-body">
      <p><?=payroll_run_badge($r['status'])?> Cutoff <?=e($r['period_start'].' to '.$r['period_end'])?> · pay date <?=e($r['pay_date'])?> · computed by <?=e($r['computed_name'])?><?=$r['released_name']?' · released by '.e($r['released_name']):''?><?=$r['entry_no']?' · journal <a href="'.e(url('accounting-entry',['id'=>$r['entry_id']])).'">'.e($r['entry_no']).'</a> (draft until Accounting approves)':''?></p>
      <p>Gross <strong><?=payroll_peso($r['total_gross'])?></strong> · Deductions <strong><?=payroll_peso($r['total_deductions'])?></strong> · Net <strong><?=payroll_peso($r['total_net'])?></strong> · Employer share <strong><?=payroll_peso($r['total_employer'])?></strong></p>
      <?php if($editable):?><div class="actions">
        <form method="post" class="payroll-inline-form"><?php payroll_hidden_action('run_compute');?><input type="hidden" name="cutoff_id" value="<?=$r['cutoff_id']?>"><input type="hidden" name="pay_date" value="<?=e($r['pay_date'])?>"><input type="hidden" name="back_run" value="1"><button class="btn">Recompute</button></form>
        <?php if(Auth::can('payroll.mark_paid') && (int)$r['computed_by']!==$me):?><form method="post" class="payroll-inline-form" onsubmit="return confirm('Release this payroll? It locks the figures, shows payslips to employees and sends a draft journal entry to Accounting.')"><?php payroll_hidden_action('run_release');?><input type="hidden" name="run_id" value="<?=$id?>"><button class="btn primary" <?=$r['issue_count']?'disabled title="Resolve issues first"':''?>>Release payroll</button></form>
        <?php elseif((int)$r['computed_by']===$me):?><span class="small muted">A different person with release access must release this payroll.</span><?php endif;?>
        <form method="post" class="payroll-inline-form" onsubmit="return confirm('Void this run? You can compute the cutoff again afterwards.')"><?php payroll_hidden_action('run_void');?><input type="hidden" name="run_id" value="<?=$id?>"><button class="btn">Void run</button></form>
      </div><?php endif;?>
    </div></section>
    <?php if($editable):?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Adjustments</h2><p>Manual earnings are taxable; manual deductions (loans, advances) come off after tax. The run recomputes after each change.</p></div></div>
      <form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('run_adjust');?><input type="hidden" name="run_id" value="<?=$id?>"><div class="payroll-form-grid">
        <div class="field"><label for="adj-emp">Employee</label><select id="adj-emp" name="employee_id" required><option value="">Select employee</option><?php foreach($r['employees'] as $e):?><option value="<?=$e['employee_id']?>"><?=e($e['employee_no'].' · '.$e['employee_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label for="adj-kind">Type</label><select id="adj-kind" name="kind"><option value="EARNING">Earning</option><option value="DEDUCTION">Deduction</option></select></div>
        <div class="field"><label for="adj-label">Description</label><input id="adj-label" name="label" maxlength="120" required></div>
        <div class="field"><label for="adj-amount">Amount</label><input id="adj-amount" name="amount" inputmode="decimal" required></div>
      </div><button class="btn primary">Add adjustment</button></form>
      <?php if($r['adjustments']):?><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Type</th><th>Description</th><th>Amount</th><th></th></tr></thead><tbody>
      <?php foreach($r['adjustments'] as $a):?><tr><td><?=e($a['employee_name'])?></td><td><?=e(ucfirst(strtolower($a['kind'])))?></td><td><?=e($a['label'])?></td><td><?=payroll_peso($a['amount'])?></td><td><form method="post" class="payroll-inline-form"><?php payroll_hidden_action('run_adjust_remove');?><input type="hidden" name="run_id" value="<?=$id?>"><input type="hidden" name="adjustment_id" value="<?=$a['id']?>"><button class="btn sm">Remove</button></form></td></tr><?php endforeach;?>
      </tbody></table></div><?php endif;?></section>
    <?php endif;?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Payroll register</h2></div></div>
      <?php if(!$r['employees']):?><div class="empty">No employees in this cutoff snapshot.</div><?php else:?>
      <div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Gross</th><th>SSS</th><th>PhilHealth</th><th>Pag-IBIG</th><th>Tax</th><th>Other</th><th>Net</th><th>Issues</th></tr></thead><tbody>
      <?php foreach($r['employees'] as $e): $c=payroll_run_cols($e['lines']); $issues=json_decode($e['issues_json'],true)?:[];?><tr>
        <td><?=e($e['employee_name'])?><div class="small muted"><?=e($e['employee_no'])?></div></td><td><?=payroll_peso($e['gross'])?></td><td><?=payroll_peso($c['SSS'])?></td><td><?=payroll_peso($c['PHILHEALTH'])?></td><td><?=payroll_peso($c['PAGIBIG'])?></td><td><?=payroll_peso($c['WHT'])?></td><td><?=payroll_peso($c['ADJ'])?></td><td><strong><?=payroll_peso($e['net'])?></strong><?php if((float)($e['carry_out']??0)>0):?><div class="small muted">Carried to next payroll: <?=payroll_peso($e['carry_out'])?></div><?php endif;?></td>
        <td><?=$issues?'<ul class="small">'.implode('',array_map(fn($i)=>'<li>'.e($i).'</li>',$issues)).'</ul>':'<span class="small muted">None</span>'?></td></tr><?php endforeach;?>
      </tbody></table></div><?php endif;?></section>
    <?php render_portal_footer();
}

/** Employee portal Payslip page: the signed-in employee's own released payslips only. */
function payroll_payslip_html(): string
{
    $emp=PayrollRepository::currentEmployee();
    if(!$emp) return '<div class="empty">HR must link your account to your employee record before payslips can appear.</div>';
    ob_start();
    $slip=isset($_GET['slip'])?PayrollRun::payslip((int)$_GET['slip'],(int)$emp['id']):null;
    if($slip): ?>
      <section class="panel payroll-section"><div class="panel-head"><div><h2>Payslip · <?=e($slip['cutoff_code'])?></h2><p>Period <?=e($slip['period_start'].' to '.$slip['period_end'])?> · pay date <?=e($slip['pay_date'])?></p></div><div><a class="btn sm" href="<?=url('profile-payslip')?>">All payslips</a> <button type="button" class="btn sm" onclick="window.print()">Print</button></div></div>
        <div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Item</th><th>Earnings</th><th>Deductions</th></tr></thead><tbody>
        <?php foreach($slip['lines'] as $l):?><tr><td><?=e($l['label'])?></td><td><?=$l['kind']==='EARNING'?payroll_peso($l['amount']):''?></td><td><?=$l['kind']==='DEDUCTION'?payroll_peso($l['amount']):''?></td></tr><?php endforeach;?>
        <tr><th>Total</th><th><?=payroll_peso($slip['gross'])?></th><th><?=payroll_peso($slip['deductions'])?></th></tr>
        <tr><th>Net pay</th><th colspan="2"><?=payroll_peso($slip['net'])?></th></tr>
        <?php if((float)($slip['carry_out']??0)>0):?><tr><td>Balance deducted in your next payroll</td><td colspan="2"><?=payroll_peso($slip['carry_out'])?></td></tr><?php endif;?></tbody></table></div></section>
    <?php else:
      $list=PayrollRun::payslips((int)$emp['id']);
      if(!$list): ?><div class="empty">Your payslips will appear here once payroll is released.</div>
      <?php else: ?><section class="panel payroll-section"><div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Pay date</th><th>Period</th><th>Gross</th><th>Deductions</th><th>Net</th><th></th></tr></thead><tbody>
        <?php foreach($list as $p):?><tr><td><?=e($p['pay_date'])?></td><td><?=e($p['period_start'].' to '.$p['period_end'])?></td><td><?=payroll_peso($p['gross'])?></td><td><?=payroll_peso($p['deductions'])?></td><td><strong><?=payroll_peso($p['net'])?></strong></td><td><a class="btn sm" href="<?=url('profile-payslip',['slip'=>$p['id']])?>">View</a></td></tr><?php endforeach;?>
      </tbody></table></div></section><?php endif;
    endif;
    return (string)ob_get_clean();
}
