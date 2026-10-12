<?php
declare(strict_types=1);

/** Maternity leave box inside the Employment form: appears when Employee Status = Maternity Leave is chosen. */
function employee_maternity_fields(array $e): void
{
    if(!MaternityService::ready() || !Auth::can('employees.manage') || MaternityService::active((int)$e['id'])) return; ?>
    <div class="field full maternity-box" data-maternity-box hidden>
      <h3>Maternity leave</h3>
      <div class="maternity-grid">
        <div class="field"><label for="maternity_kind">Type</label><select id="maternity_kind" name="maternity_kind" data-maternity-kind><option value="" data-days="0">Choose type</option><?php foreach(MaternityService::KINDS as $code=>$k):?><option value="<?=e($code)?>" data-days="<?=(int)$k['days']?>"><?=e($k['label'])?> — <?=(int)$k['days']?> days</option><?php endforeach;?></select></div>
        <div class="field"><label for="maternity_start">Start date</label><input id="maternity_start" type="date" name="maternity_start" data-maternity-start></div>
        <div class="field"><label>End date</label><input type="date" readonly aria-readonly="true" data-maternity-end></div>
        <div class="field"><label>Return to work</label><input type="date" readonly aria-readonly="true" data-maternity-return></div>
      </div>
      <div class="maternity-preview" data-maternity-preview><?php foreach(MaternityService::KINDS as $code=>$k):?><div data-preview-kind="<?=e($code)?>" data-days="<?=(int)$k['days']?>"><strong><?=e($k['label'])?> (<?=(int)$k['days']?> days)</strong><span data-preview-text>Enter the start date.</span></div><?php endforeach;?></div>
      <small>Live birth is 105 days and early termination of pregnancy is 60 days, counted from the start date. The end and return dates appear by themselves. The employee must give HR her actual medical certificate before returning.</small>
      <?php if(($e['gender']??'')==='Male'):?><small class="maternity-warn">This employee's gender is recorded as Male. Check the Personal tab if this is not intended.</small><?php endif;?>
    </div>
    <script>(function(){var t=document.querySelector('select[name="employment_type_id"]'),box=document.querySelector('[data-maternity-box]');if(!t||!box)return;var kind=box.querySelector('[data-maternity-kind]'),start=box.querySelector('[data-maternity-start]'),end=box.querySelector('[data-maternity-end]'),ret=box.querySelector('[data-maternity-return]'),rows=box.querySelectorAll('[data-preview-kind]');function code(){var o=t.options[t.selectedIndex];return o?o.getAttribute('data-code')||'':''}function pad(n){return String(n).padStart(2,'0')}function fmt(d){return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())}function pretty(d){return d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}function plus(s,n){var p=s.split('-'),d=new Date(+p[0],+p[1]-1,+p[2]);d.setDate(d.getDate()+n);return d}function calc(){var days=+(kind.options[kind.selectedIndex].getAttribute('data-days')||0);Array.prototype.forEach.call(rows,function(r){var n=+r.getAttribute('data-days'),el=r.querySelector('[data-preview-text]');if(!start.value){el.textContent='Enter the start date.';return;}el.textContent='ends '+pretty(plus(start.value,n-1))+' · returns '+pretty(plus(start.value,n));r.classList.toggle('is-selected',n===days);});if(!days||!start.value){end.value='';ret.value='';return;}end.value=fmt(plus(start.value,days-1));ret.value=fmt(plus(start.value,days));}function sync(){var on=code()==='MATERNITY_LEAVE';box.hidden=!on;kind.required=on;start.required=on;if(on&&!start.value)start.value=fmt(new Date());calc();}t.addEventListener('change',sync);kind.addEventListener('change',calc);start.addEventListener('change',calc);start.addEventListener('input',calc);sync();})();</script>
<?php }

/** Active maternity leave: certificate, return to work, cancel. */
function employee_maternity_panel(array $e): void
{
    if(!MaternityService::ready() || !Auth::can('employees.manage')) return;
    $id=(int)$e['id']; $m=MaternityService::active($id); $today=date('Y-m-d');
    $hidden='<input type="hidden" name="employee_id" value="'.$id.'"><input type="hidden" name="return_page" value="hr-employee"><input type="hidden" name="return_id" value="'.$id.'"><input type="hidden" name="return_tab" value="employment">';
    if(!$m) {
        if(EmployeeStatusPolicy::code(isset($e['employment_type_id'])?(int)$e['employment_type_id']:null)!=='MATERNITY_LEAVE') return; ?>
        <section class="panel maternity-panel"><div class="panel-body"><div class="alert amber">This employee is marked Maternity Leave but has no leave dates yet. Choose Maternity Leave below and fill in the type and start date, then save.</div></div></section>
        <?php return;
    }
    $days=(int)(new DateTimeImmutable('today'))->diff(new DateTimeImmutable($m['return_date']))->format('%r%a');
    $cert=!empty($m['med_cert_received_on']);
    $doc=$cert&&$m['med_cert_document_id']?EmployeeRepository::document($id,(int)$m['med_cert_document_id']):null; ?>
    <section class="panel maternity-panel" id="maternity-panel">
      <div class="panel-head"><div><h2>Maternity leave</h2><p><?=e(MaternityService::kindLabel($m['leave_kind']))?> · <?=(int)$m['leave_days']?> days</p></div><span class="badge <?=$days<=14?'amber':'gray'?>"><?=$days<0?'Return date passed '.abs($days).' days ago':($days===0?'Returns today':$days.' days to return')?></span></div>
      <div class="panel-body">
        <div class="maternity-summary"><div><span>Start</span><strong><?=e(date('M j, Y',strtotime($m['start_date'])))?></strong></div><div><span>End</span><strong><?=e(date('M j, Y',strtotime($m['end_date'])))?></strong></div><div><span>Return to work</span><strong><?=e(date('M j, Y',strtotime($m['return_date'])))?></strong></div><div><span>Medical certificate</span><strong><?=$cert?'Received '.e(date('M j, Y',strtotime($m['med_cert_received_on']))):'Not yet received'?></strong></div></div>
        <?php if($doc):?><p class="small muted">Attached: <?=e($doc['title'])?> (Documents tab)</p><?php endif;?>
        <?php if($m['leave_request_id']):?><p class="small muted">Payroll leave record: <?=$m['leave_request_created']?'created by HR from this box':'linked to the leave the employee filed'?>. The leave days show as Leave in the attendance export.</p><?php endif;?>
        <?php if(!$cert):?><div class="alert amber">Before returning, the employee must give HR her actual medical certificate. Record it here when received.</div>
        <form method="post" action="<?=url('home')?>" enctype="multipart/form-data" class="maternity-form"><?=csrf_field()?><input type="hidden" name="action" value="maternity_receive_certificate"><?=$hidden?>
          <div class="field"><label>Date received</label><input type="date" name="received_on" value="<?=e($today)?>" max="<?=e($today)?>" required></div>
          <div class="field"><label>Scan / photo (optional)</label><input type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
          <div class="maternity-actions"><button class="btn primary" type="submit">Medical certificate received</button></div></form>
        <?php else:?>
        <form method="post" action="<?=url('home')?>" class="maternity-form"><?=csrf_field()?><input type="hidden" name="action" value="maternity_return_to_work"><?=$hidden?>
          <div class="field"><label>Return date</label><input type="date" name="effective_date" value="<?=e($today)?>" max="<?=e($today)?>" required></div>
          <div class="field full"><label>Remarks (optional)</label><input name="remarks" maxlength="300" placeholder="Fit to work, returns to the same branch…"></div>
          <div class="maternity-actions"><button class="btn primary" type="submit">Return to work</button></div></form>
        <?php endif;?>
        <details class="archive-action"><summary><strong>Cancel this maternity leave</strong><span>Only for a mistaken entry. The employee goes back to the previous Employee Status.</span></summary>
          <form method="post" action="<?=url('home')?>" class="maternity-form"><?=csrf_field()?><input type="hidden" name="action" value="maternity_cancel"><?=$hidden?>
            <div class="field full"><label>Reason</label><input name="remarks" maxlength="300" required></div>
            <div class="maternity-actions"><button class="btn danger-outline" type="submit">Cancel maternity leave</button></div></form></details>
      </div>
    </section>
<?php }
