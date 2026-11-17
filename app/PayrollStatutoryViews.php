<?php
declare(strict_types=1);

/** Payroll engine P1: statutory tables page (migration 045). Lives outside the deferred Payroll Setup tabs. */
function payroll_statutory(): void
{
    render_portal_header('hr','payroll-statutory','Statutory Tables'); page_head('HR Payroll / Configuration','Statutory Tables');
    if(!PayrollStatutory::ready()) { echo '<div class="alert error">Apply database/migrations/20261117_045_payroll_statutory_tables.sql first.</div>'; render_portal_footer(); return; }
    $me=(int)Auth::user()['id']; $old=$_SESSION['payroll_form_old']??[]; unset($_SESSION['payroll_form_old']);
    $editId=(int)($_GET['edit']??0); $viewId=(int)($_GET['view']??0);
    $form=['table_code'=>'SSS','pay_frequency'=>'SEMI_MONTHLY','version_label'=>'','effective_from'=>'','source_reference'=>'','rows'=>''];
    $view=null;
    try {
        if($editId) { $t=PayrollStatutory::version($editId); if($t['verified_at']===null) $form=array_merge($form,array_intersect_key($t,$form),['rows'=>PayrollStatutory::rowsText($t['table_code'],$t['rows'])]); else $editId=0; }
        if($viewId) $view=PayrollStatutory::version($viewId);
    } catch(RuntimeException) { $editId=0; }
    if(($old['action']??'')==='payroll_v2_stat_save') { $form=array_merge($form,array_intersect_key($old,$form)); $editId=(int)($old['table_id']??0); }
    $versions=PayrollStatutory::versions(); ?>
    <p class="small muted">Enter every bracket and rate from the current agency circular; a second person then verifies it. The system ships with no rates. Payroll uses only verified versions in force on the pay date.</p>
    <section class="panel payroll-section"><div class="panel-head"><div><h2><?=$editId?'Edit draft':'New table version'?></h2><p>Paste rows from a spreadsheet (one row per bracket), or type them separated by spaces with "-" for a blank cell.</p></div></div>
      <form method="post" class="panel-body payroll-form"><?php payroll_hidden_action('stat_save');?><input type="hidden" name="table_id" value="<?=$editId?>">
        <div class="payroll-form-grid">
          <div class="field"><label for="pst-code">Table</label><select id="pst-code" name="table_code"><?php foreach(PayrollStatutory::CODES as $c=>$l):?><option value="<?=$c?>" <?=$form['table_code']===$c?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div>
          <div class="field" id="pst-freq-field"><label for="pst-freq">BIR table for</label><select id="pst-freq" name="pay_frequency"><?php foreach(PayrollStatutory::FREQUENCIES as $c=>$l):?><option value="<?=$c?>" <?=$form['pay_frequency']===$c?'selected':''?>><?=e($l)?> payroll</option><?php endforeach;?></select></div>
          <div class="field"><label for="pst-label">Version label</label><input id="pst-label" name="version_label" maxlength="60" required value="<?=e($form['version_label'])?>" placeholder="e.g. 2027 schedule"></div>
          <div class="field"><label for="pst-from">Effective from</label><input id="pst-from" type="date" name="effective_from" required value="<?=e($form['effective_from'])?>"></div>
          <div class="field"><label for="pst-source">Circular / source reference</label><input id="pst-source" name="source_reference" maxlength="150" required value="<?=e($form['source_reference'])?>" placeholder="Circular number and date"></div>
        </div>
        <div class="field"><label for="pst-rows">Rows</label>
          <?php foreach(PayrollStatutory::COLUMNS as $c=>$cols):?><p class="small muted pst-cols" data-code="<?=$c?>" <?=$form['table_code']===$c?'':'hidden'?>>Columns: <?=e(implode(' · ',$cols))?>. <?=$c==='PHILHEALTH'?'One row only.':'Only the last row may leave the "to" column blank.'?></p><?php endforeach;?>
          <textarea id="pst-rows" name="rows" rows="10" required spellcheck="false" style="font-family:ui-monospace,monospace"><?=e($form['rows'])?></textarea></div>
        <div class="actions"><button class="btn primary">Save draft</button><?php if($editId):?> <a class="btn" href="<?=url('payroll-statutory')?>">Cancel</a><?php endif;?></div>
      </form></section>
    <script>(()=>{const s=document.getElementById('pst-code'),f=document.getElementById('pst-freq-field');const sync=()=>{f.hidden=s.value!=='BIR_WITHHOLDING';document.querySelectorAll('.pst-cols').forEach(p=>p.hidden=p.dataset.code!==s.value);};s.addEventListener('change',sync);sync();})();</script>
    <?php if($view): $cols=PayrollStatutory::COLUMNS[$view['table_code']];?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2><?=e(PayrollStatutory::CODES[$view['table_code']].' · '.$view['version_label'])?></h2><p><?=e($view['source_reference'].' · from '.$view['effective_from'].($view['effective_to']?' to '.$view['effective_to']:''))?></p></div></div>
      <div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><?php foreach($cols as $l):?><th><?=e($l)?></th><?php endforeach;?></tr></thead><tbody>
      <?php foreach($view['rows'] as $r):?><tr><?php foreach(array_keys($cols) as $k):?><td><?=$r[$k]===null?'—':e(number_format((float)$r[$k],in_array($k,['employee_rate','employer_rate','rate_percent'],true)?4:2))?></td><?php endforeach;?></tr><?php endforeach;?>
      </tbody></table></div></section>
    <?php endif;?>
    <section class="panel payroll-section"><div class="panel-head"><div><h2>Versions</h2><p>A verified version is locked. To correct it, enter a new version with a later effective date.</p></div></div>
      <?php if(!$versions):?><div class="empty">No statutory tables yet. Payroll cannot compute contributions or tax until each table is entered and verified.</div><?php else:?>
      <div class="payroll-table-scroll"><table class="phase3a-table"><thead><tr><th>Table</th><th>Version</th><th>Effective</th><th>Rows</th><th>Entered by</th><th>Status</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($versions as $v):?><tr>
        <td><?=e(PayrollStatutory::CODES[$v['table_code']].($v['table_code']==='BIR_WITHHOLDING'?' ('.PayrollStatutory::FREQUENCIES[$v['pay_frequency']].')':''))?></td>
        <td><?=e($v['version_label'])?><div class="small muted"><?=e($v['source_reference'])?></div></td>
        <td><?=e($v['effective_from'].' to '.($v['effective_to']??'ongoing'))?></td><td><?=(int)$v['row_count']?></td><td><?=e($v['entered_name'])?></td>
        <td><?=$v['verified_at']?'<span class="badge green">Verified</span><div class="small muted">'.e((string)$v['verified_name']).'</div>':'<span class="badge gray">Draft</span>'?></td>
        <td><div class="actions"><a class="btn sm" href="<?=url('payroll-statutory',['view'=>$v['id']])?>">View</a>
          <?php if(!$v['verified_at']):?><a class="btn sm" href="<?=url('payroll-statutory',['edit'=>$v['id']])?>">Edit</a>
            <?php if((int)$v['entered_by']!==$me):?><form method="post" class="payroll-inline-form"><?php payroll_hidden_action('stat_verify');?><input type="hidden" name="table_id" value="<?=$v['id']?>"><button class="btn sm primary">Verify</button></form>
            <?php else:?><span class="small muted">Needs a second person to verify</span><?php endif;?>
            <form method="post" class="payroll-inline-form" onsubmit="return confirm('Discard this draft?')"><?php payroll_hidden_action('stat_discard');?><input type="hidden" name="table_id" value="<?=$v['id']?>"><button class="btn sm">Discard</button></form>
          <?php endif;?></div></td>
      </tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
    <?php render_portal_footer();
}
