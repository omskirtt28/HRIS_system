<?php
declare(strict_types=1);

function ticket_viewers_page(array $filter,array $rows,array $report,array $overrides,array $candidates): void
{
    $areas=FoundationRepository::areas();$c=$report['counts'];
    render_portal_header((Auth::user()['role_portal']??'')==='admin'?'admin':'hr','hr-ticket-viewers','Ticket Viewers'); ?>
    <style>.tv-panel{margin-bottom:20px}.tv-scroll{overflow-x:auto;max-width:100%}.tv-table{min-width:760px}.tv-stats{display:flex;flex-wrap:wrap;gap:12px}.tv-stats div{min-width:150px}</style>
    <div class="page-head"><div><h1>Ticket Viewers</h1><p>Who may read an employee's HR requests without approving them. Viewers cannot approve, return, reject, cancel, complete, comment or attach files, and never see Incident Reports.</p></div></div>
    <div class="alert">Retail: the branch leads set here (several allowed) and the ADL whose assigned areas include the branch (set in Employee 201, Assigned areas for ADL). Head Office: the employee's Manager from Ticket Approvers. Each person needs a linked, active account on a role with <em>View team HR requests</em>.</div>

    <section class="panel tv-panel"><div class="panel-head"><div><h2>Unresolved viewers</h2><p>Names are for HR only.</p></div></div><div class="panel-body">
      <div class="tv-stats"><div><strong><?=(int)$c['employees']?></strong><br>active employees</div><div><strong><?=(int)$c['with_viewer']?></strong><br>have a viewer</div><div><strong><?=(int)$c['retail_missing']?></strong><br>retail without a viewer</div><div><strong><?=(int)$c['ho_missing']?></strong><br>Head Office without a viewer</div></div>
      <details><summary><?=count($report['branches_without_lead'])?> branch(es) with employees but no branch lead who can view</summary><ul><?php foreach($report['branches_without_lead'] as $b):?><li><?=e($b['name'])?> · <?=(int)$b['emps']?> employee(s) · <?=$b['leads']?'lead has no usable account':'no lead'?> · <?=$b['adl_covered']?'ADL covers it':'no ADL coverage'?></li><?php endforeach;?></ul></details>
      <details><summary><?=count($report['adls_unready'])?> ADL(s) without a usable account or assigned area</summary><ul><?php foreach($report['adls_unready'] as $a):?><li><?=e($a['first_name'].' '.$a['last_name'].' · '.$a['employee_no'])?> · <?=$a['user_id']?'account ok':'no active account'?> · <?=$a['areas']?'areas set':'no area'?><?=($a['user_id']&&!$a['can_view'])?' · role lacks View team HR requests':''?></li><?php endforeach;?></ul></details>
      <details><summary><?=count($report['adls_in_head_office'])?> ADL(s) whose branch is Head Office</summary><ul><?php foreach($report['adls_in_head_office'] as $a):?><li><?=e($a['first_name'].' '.$a['last_name'].' · '.$a['employee_no'])?> · <?=e($a['branch_name']?:'No branch')?></li><?php endforeach;?></ul><small>Their coverage comes from Assigned areas, not from this branch.</small></details>
      <details><summary><?=count($report['branches_without_area'])?> retail branch(es) without an area</summary><ul><?php foreach($report['branches_without_area'] as $b):?><li><?=e($b['name'])?> · <?=(int)$b['emps']?> employee(s)</li><?php endforeach;?></ul><small>An ADL cannot cover a branch until its area is set.</small></details>
      <details <?=$report['missing_total']?'open':''?>><summary><?=(int)$report['missing_total']?> employee(s) with no resolvable viewer</summary>
        <ul><?php foreach($report['missing'] as $m):?><li><?=e($m['first_name'].' '.$m['last_name'].' · '.$m['employee_no'])?> · <?=e($m['branch_name']?:'No branch')?> · <?=$m['is_ho']?'Head Office':'Retail'?></li><?php endforeach;?></ul>
        <p>Page <?=(int)$report['page']?> of <?=(int)$report['pages']?> <?php if($report['page']>1):?><a class="btn" href="<?=e(url('hr-ticket-viewers',array_replace($filter,['p'=>$report['page']-1])))?>">Previous</a><?php endif;if($report['page']<$report['pages']):?> <a class="btn" href="<?=e(url('hr-ticket-viewers',array_replace($filter,['p'=>$report['page']+1])))?>">Next</a><?php endif;?></p></details>
    </div></section>

    <form method="get" class="panel tv-panel"><input type="hidden" name="page" value="hr-ticket-viewers"><div class="panel-body split">
      <div class="field"><label for="tv-area">Area</label><select id="tv-area" name="area_id"><option value="0">All areas</option><option value="-1" <?=$filter['area_id']===-1?'selected':''?>>No area</option><?php foreach($areas as $a):?><option value="<?=(int)$a['id']?>" <?=$filter['area_id']===(int)$a['id']?'selected':''?>><?=e($a['name'])?></option><?php endforeach;?></select></div>
      <div class="field"><label for="tv-branch">Branch</label><select id="tv-branch" name="branch_id"><option value="0">All branches</option><?php foreach(FoundationRepository::branches() as $b):if(($b['site_type']??'')==='HEAD_OFFICE')continue;?><option value="<?=(int)$b['id']?>" <?=$filter['branch_id']===(int)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></div>
      <div><button class="btn" type="submit">Apply filters</button></div>
    </div></form>

    <form method="post" class="panel tv-panel"><?=csrf_field()?><input type="hidden" name="action" value="ticket_viewers_assign"><input type="hidden" name="return_area_id" value="<?=(int)$filter['area_id']?>"><input type="hidden" name="return_branch_id" value="<?=(int)$filter['branch_id']?>">
      <div class="panel-head"><div><h2>Branch leads (Sales Captain / Team Coordinator)</h2><p><?=count($rows)?> branch(es) shown. Tick branches, enter the lead's employee number, and save. A branch may have several leads.</p></div></div>
      <div class="panel-body"><div class="split">
        <div class="field"><label for="tv-lead">Lead employee no.</label><input id="tv-lead" name="lead_employee_no" list="tv-candidates" autocomplete="off"><datalist id="tv-candidates"><?php foreach($candidates as $p):?><option value="<?=e($p['employee_no'])?>"><?=e($p['first_name'].' '.$p['last_name'].' · '.($p['branch_name']??''))?></option><?php endforeach;?></datalist></div>
        <div class="field"><label for="tv-from">Effective from</label><input type="date" id="tv-from" name="effective_from" min="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>" required></div>
        <div class="field"><label for="tv-to">Effective to (optional)</label><input type="date" id="tv-to" name="effective_to"></div></div>
        <button class="btn primary">Assign lead to selected branches</button></div>
      <div class="tv-scroll" tabindex="0" role="region" aria-label="Branches, scroll horizontally on small screens"><table class="tbl tv-table"><thead><tr><th><input type="checkbox" id="tv-all" aria-label="Select all branches"></th><th>Branch</th><th>Area</th><th>Employees</th><th>Branch leads</th><th>ADL coverage</th></tr></thead><tbody>
      <?php foreach($rows as $r):?><tr><td><input type="checkbox" name="branch_ids[]" value="<?=(int)$r['id']?>" aria-label="<?=e('Select '.$r['name'])?>"></td>
        <td><strong><?=e($r['name'])?></strong></td><td><?=e($r['area_name']?:'No area')?></td><td><?=(int)$r['emps']?></td>
        <td><?php foreach($r['leads'] as $l):?><div><?=e($l['first_name'].' '.$l['last_name'].' · '.$l['employee_no'])?> <span class="badge <?=$l['can_view']?'gray':'amber'?>"><?=$l['can_view']?'can view':'no usable account'?></span><?=$l['effective_from']>date('Y-m-d')?' <small>from '.e($l['effective_from']).'</small>':''?><?=$l['effective_to']?' <small>until '.e($l['effective_to']).'</small>':''?>
          <button class="btn" type="submit" name="lead_id" value="<?=(int)$l['id']?>" data-end-lead>End</button></div><?php endforeach;if(!$r['leads']):?><span class="badge amber">No lead</span><?php endif;?></td>
        <td><?php foreach($r['adls'] as $a):?><div><?=e($a['first_name'].' '.$a['last_name'])?></div><?php endforeach;if(!$r['adls']):?><span class="badge amber"><?=$r['area_id']?'No ADL':'No area'?></span><?php endif;?></td></tr>
      <?php endforeach;if(!$rows):?><tr><td colspan="6">No branches match these filters.</td></tr><?php endif;?></tbody></table></div>
    </form>

    <section class="panel tv-panel"><div class="panel-head"><div><h2>Extra viewer for one employee</h2><p>Adds one more person who can read that employee's requests (for example someone covering another branch). It never removes the branch lead, ADL or Manager.</p></div></div><div class="panel-body">
      <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="ticket_viewers_add_override"><div class="split">
        <div class="field"><label for="ov-emp">Employee no.</label><input id="ov-emp" name="employee_no" required></div><div class="field"><label for="ov-viewer">Viewer employee no.</label><input id="ov-viewer" name="viewer_employee_no" list="tv-candidates" required></div>
        <div class="field"><label for="ov-from">Effective from</label><input type="date" id="ov-from" name="effective_from" min="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>" required></div><div class="field"><label for="ov-to">Effective to (optional)</label><input type="date" id="ov-to" name="effective_to"></div></div>
        <button class="btn">Save extra viewer</button></form>
      <?php if($overrides):?><div class="tv-scroll"><table class="tbl"><thead><tr><th>Employee</th><th>Viewer</th><th>From</th><th>To</th><th></th></tr></thead><tbody><?php foreach($overrides as $o):?><tr><td><?=e($o['s_first'].' '.$o['s_last'].' · '.$o['s_no'])?></td><td><?=e($o['v_first'].' '.$o['v_last'].' · '.$o['v_no'])?></td><td><?=e($o['effective_from'])?></td><td><?=e($o['effective_to']?:'Open')?></td>
        <td><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="ticket_viewers_end_override"><input type="hidden" name="override_id" value="<?=(int)$o['id']?>"><button class="btn">End</button></form></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    </div></section>
    <script>
      document.getElementById('tv-all')?.addEventListener('change',e=>document.querySelectorAll('input[name="branch_ids[]"]').forEach(i=>i.checked=e.target.checked));
      // The row End buttons live inside the assign form: switch the action only for them.
      document.querySelectorAll('[data-end-lead]').forEach(b=>b.addEventListener('click',e=>{if(!confirm('End this branch lead?')){e.preventDefault();return;}const f=b.form;f.querySelector('[name=action]').value='ticket_viewers_end_lead';f.querySelector('#tv-lead').required=false;}));
    </script>
    <?php render_portal_footer();
}

/** Read-only line for the Employee 201 Employment tab. Never edits; the setup lives on the Ticket Viewers screen (HR only). */
final class TicketViewerEmploymentLine
{
    public static function html(int $employeeId): string
    {
        try {$v=TicketViewerService::viewersFor($employeeId);} catch (Throwable) {return '';}
        if (!$v['ready']) return '';
        $today=date('Y-m-d');$parts=[];
        $name=static fn(array $p):string=>e(trim(($p['first_name']??'').' '.($p['last_name']??'')).(!empty($p['employee_no'])?' · '.$p['employee_no']:''));
        $badge=static fn(bool $ok):string=>$ok?'':' <span class="badge amber">no usable account</span>';
        foreach ($v['leads'] as $l) $parts[]='<span>Branch lead: '.$name($l).$badge((bool)$l['can_view']).($l['effective_from']>$today?' <span class="badge gray">from '.e($l['effective_from']).'</span>':'').'</span>';
        foreach ($v['adls'] as $a) $parts[]='<span>ADL: '.$name($a).'</span>';
        if ($v['manager']) $parts[]='<span>Manager: '.e((string)$v['manager']['name']).(!empty($v['manager']['employee_no'])?' · '.e((string)$v['manager']['employee_no']):'').$badge((bool)$v['manager']['can_view']).'</span>';
        foreach ($v['extra'] as $x) $parts[]='<span>Extra viewer: '.$name($x).$badge((bool)$x['can_view']).'</span>';
        $link=Auth::can('tickets.approvers.manage')?' <a href="'.e(url('hr-ticket-viewers')).'">Ticket Viewers screen</a>':'';
        return '<div class="panel-body" data-ticket-viewers-line><div class="field full"><label>Ticket viewers (view only)</label>'
            .($parts?'<div>'.implode('<br>',$parts).'</div>':'<div><span class="badge amber">No viewer set</span></div>')
            .'<small>Can read this employee\'s HR requests, not act on them. Set by HR, not edited here.'.$link.'</small></div></div>';
    }
}