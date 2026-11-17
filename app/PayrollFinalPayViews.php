<?php
declare(strict_types=1);

/** Back pay screens (migration 051): archived employees list with compute form, and one back pay record. */
function payroll_final_badge(?string $s): string { return $s===null ? '<span class="small muted">Not computed</span>' : payroll_run_badge($s); }

function payroll_final_pay_page(int $id): void
{
    render_portal_header('hr','payroll-final-pay','Back Pay');
    if(!PayrollFinalPay::ready()) { page_head('HR Payroll / Pay','Back Pay'); echo '<div class="alert error">Apply database/migrations/20261117_051_payroll_final_pay.sql first.</div>'; render_portal_footer(); return; }
    if($id) { payroll_final_pay_detail($id); return; }
    page_head('HR Payroll / Pay','Back Pay');
    $list=PayrollFinalPay::candidates(); $open=array_filter($list,fn($c)=>$c['final_status']!=='RELEASED'); ?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Compute back pay</h2><p>For employees in the Employee Archive. Back pay = pro-rated 13th month (basic pay in the separation year's released payroll runs / 12) + unused <?=e(implode(', ',PayrollFinalPay::CONVERT_LEAVE))?> leave x daily rate + manual items, less accountabilities and tax adjustments. The last salary is paid in the regular payroll run: release it first.</p></div></div>
      <?php if(!$list):?><div class="empty">No employee is in the Employee Archive.</div><?php else:?>
      <?php if($open):?><form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('final_compute');?><div class="payroll-form-grid">
        <div class="field"><label for="fp-emp">Employee</label><select id="fp-emp" name="employee_id" required><option value="">Select employee</option><?php foreach($open as $c):?><option value="<?=(int)$c['id']?>"><?=e($c['employee_no'].' · '.trim($c['first_name'].' '.$c['last_name']).($c['final_status']?' (recompute)':''))?></option><?php endforeach;?></select></div>
        <div class="field"><label for="fp-pay">Pay date</label><input id="fp-pay" type="date" name="pay_date" value="<?=payroll_old('pay_date')?>" required></div>
        <div class="field"><label for="fp-ceiling">Tax-exempt ceiling (₱)</label><input id="fp-ceiling" name="exempt_ceiling" inputmode="decimal" value="<?=payroll_old('exempt_ceiling',PayrollThirteenth::lastCeiling()??'')?>" required aria-describedby="fp-ceiling-help"><div id="fp-ceiling-help" class="small muted">Confirm the current BIR ceiling for 13th month and other benefits.</div></div>
      </div><button class="btn primary">Compute back pay</button></form><?php endif;?>
      <div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Employee</th><th>Archive status</th><th>Back pay</th><th>Net</th><th>Pay date</th><th></th></tr></thead><tbody>
      <?php foreach($list as $c):?><tr><td><?=e(trim($c['first_name'].' '.$c['last_name']))?><div class="small muted"><?=e($c['employee_no'])?></div></td><td><?=e(EmployeeArchiveService::statusLabel($c['status']))?></td><td><?=payroll_final_badge($c['final_status'])?></td>
        <td><?=$c['final_pay_id']?payroll_peso($c['net']):'—'?></td><td><?=e($c['pay_date']??'—')?></td><td><?=$c['final_pay_id']?'<a class="btn sm" href="'.e(url('payroll-final-pay',['id'=>$c['final_pay_id']])).'">Open</a>':''?></td></tr><?php endforeach;?>
      </tbody></table></div><?php endif;?></section>
    <?php render_portal_footer();
}

function payroll_final_pay_detail(int $id): void
{
    try { $f=PayrollFinalPay::get($id); } catch(RuntimeException) { flash('error','Back pay record not found.'); redirect('payroll-final-pay'); }
    $me=(int)Auth::user()['id']; $editable=$f['status']==='COMPUTED'; $issues=json_decode($f['issues_json'],true)?:[]; $year=substr($f['separation_date'],0,4);
    page_head('HR Payroll / Back Pay','Back pay · '.$f['employee_name'],'<a class="btn" href="'.e(url('payroll-final-pay')).'">Back to list</a> <button type="button" class="btn" onclick="window.print()">Print</button>'); ?>
    <section class="panel payroll-section"><div class="panel-body">
      <p><?=payroll_run_badge($f['status'])?> <?=e($f['employee_no'])?> · <?=e(EmployeeArchiveService::statusLabel($f['employee_status']))?> on <?=e($f['separation_date'])?> · pay date <?=e($f['pay_date'])?> · computed by <?=e($f['computed_name'])?><?=$f['released_name']?' · released by '.e($f['released_name']).' (clearance confirmed '.e((string)$f['clearance_confirmed_at']).')':''?><?=$f['entry_no']?' · journal <a href="'.e(url('accounting-entry',['id'=>$f['entry_id']])).'">'.e($f['entry_no']).'</a> (draft until Accounting approves)':''?></p>
      <?php if($issues):?><div class="alert error"><ul><?php foreach($issues as $i):?><li><?=e($i)?></li><?php endforeach;?></ul></div><?php endif;?>
      <?php if((float)$f['thirteenth_taxable']>0):?><div class="alert">Part of the 13th month (<?=payroll_peso($f['thirteenth_taxable'])?>) is above the tax-exempt ceiling. Add a withholding tax adjustment if tax is due.</div><?php endif;?>
      <div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Item</th><th>Basis</th><th>Earnings</th><th>Deductions</th></tr></thead><tbody>
        <tr><td>Pro-rated 13th month</td><td class="small muted"><?=(float)$f['basic_ytd']>0 ? payroll_peso($f['basic_ytd']).' basic pay in '.e($year).' / 12'.((float)$f['thirteenth']==0.0?' (already paid in the 13th month run)':'') : 'No released basic pay in '.e($year)?></td><td><?=payroll_peso($f['thirteenth'])?></td><td></td></tr>
        <tr><td>Leave conversion (<?=e(implode(', ',PayrollFinalPay::CONVERT_LEAVE))?>)</td><td class="small muted"><?=e(rtrim(rtrim(number_format((float)$f['leave_days'],2),'0'),'.'))?> day(s) x <?=payroll_peso($f['daily_rate'])?></td><td><?=payroll_peso($f['leave_amount'])?></td><td></td></tr>
        <?php foreach($f['items'] as $i):?><tr><td><?=e($i['label'])?></td><td class="small muted"><?=e(PayrollFinalPay::KINDS[$i['kind']]??$i['kind'])?></td><td><?=$i['kind']==='EARNING'?payroll_peso($i['amount']):''?></td><td><?=$i['kind']!=='EARNING'?payroll_peso($i['amount']):''?>
          <?php if($editable):?><form method="post" class="payroll-inline-form"><?php payroll_hidden_action('final_item_remove');?><input type="hidden" name="final_pay_id" value="<?=$id?>"><input type="hidden" name="item_id" value="<?=(int)$i['id']?>"><button class="btn sm" aria-label="Remove <?=e($i['label'])?>">Remove</button></form><?php endif;?></td></tr><?php endforeach;?>
        <tr><th colspan="2">Total</th><th><?=payroll_peso($f['earnings_total'])?></th><th><?=payroll_peso($f['deductions_total'])?></th></tr>
        <tr><th colspan="2">Net back pay</th><th colspan="2"><?=payroll_peso($f['net'])?></th></tr>
      </tbody></table></div>
      <?php if($editable):?><div class="actions">
        <form method="post" class="payroll-inline-form"><?php payroll_hidden_action('final_compute');?><input type="hidden" name="employee_id" value="<?=(int)$f['employee_id']?>"><input type="hidden" name="pay_date" value="<?=e($f['pay_date'])?>"><input type="hidden" name="exempt_ceiling" value="<?=e($f['exempt_ceiling'])?>"><button class="btn">Recompute</button></form>
        <form method="post" class="payroll-inline-form" onsubmit="return confirm('Void this back pay? You can compute it again afterwards.')"><?php payroll_hidden_action('final_void');?><input type="hidden" name="final_pay_id" value="<?=$id?>"><button class="btn">Void</button></form>
      </div><?php endif;?>
    </div></section>
    <?php if($editable):?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Add item</h2><p>Accountabilities (unreturned items, cash advances) and tax adjustments are deductions; other pay owed is an earning. Back pay recomputes after each change.</p></div></div>
      <form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('final_item_add');?><input type="hidden" name="final_pay_id" value="<?=$id?>"><div class="payroll-form-grid">
        <div class="field"><label for="fpi-kind">Type</label><select id="fpi-kind" name="kind"><?php foreach(PayrollFinalPay::KINDS as $k=>$label):?><option value="<?=$k?>"><?=e($label)?></option><?php endforeach;?></select></div>
        <div class="field"><label for="fpi-label">Description</label><input id="fpi-label" name="label" maxlength="120" required></div>
        <div class="field"><label for="fpi-amount">Amount</label><input id="fpi-amount" name="amount" inputmode="decimal" required></div>
      </div><button class="btn primary">Add item</button></form></section>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Release</h2></div></div><div class="panel-body">
      <?php if(!Auth::can('payroll.mark_paid')):?><p class="small muted">A person with payroll release access releases back pay.</p>
      <?php elseif((int)$f['computed_by']===$me):?><p class="small muted">A different person with release access must release the back pay that you computed.</p>
      <?php else:?><form method="post" class="payroll-form" onsubmit="return confirm('Release this back pay? It locks the figures and sends a draft journal entry to Accounting.')"><?php payroll_hidden_action('final_release');?><input type="hidden" name="final_pay_id" value="<?=$id?>">
        <label class="small"><input type="checkbox" name="clearance" value="1" required> Clearance is complete: every department has signed off and accountabilities are listed above.</label>
        <div><button class="btn primary" <?=$issues?'disabled title="Resolve issues first"':''?>>Release back pay</button></div></form><?php endif;?>
    </div></section>
    <?php endif;
    render_portal_footer();
}
