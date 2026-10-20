<?php
declare(strict_types=1);

function ticket_assignment_page(array $report,array $filter,?array $preview): void
{
    $old=$_SESSION['ticket_approvers_old']??[];
    $kind=(string)($old['approver_type']??($filter['kind']?:'MANAGER'));
    if(!in_array($kind,['MANAGER','ADL'],true))$kind='MANAGER';
    $candidates=['MANAGER'=>EmployeeApproverAssignmentService::candidates('MANAGER'),'ADL'=>EmployeeApproverAssignmentService::candidates('ADL')];
    $candidateNames=[];foreach($candidates as $list)foreach($list as $person)$candidateNames[$person['id']]=$person['full_name'];
    render_portal_header((Auth::user()['role_portal']??'')==='admin'?'admin':'hr','hr-ticket-approvers','Ticket Approver Assignments'); ?>
    <style>.ticket-assignment-panel{margin-bottom:20px}.ticket-assignment-table{min-width:850px}.ticket-assignment-scroll{overflow-x:auto;max-width:100%}</style>
    <div class="page-head"><div><h1>Ticket Approver Assignments</h1><p>Assign Managers for Head Office and ADLs for branches. These assignments apply to new HR requests.</p></div></div>
    <div class="alert">Existing Payroll Ticket Review and TA / OB / OT / leave keep their current routing. Unknown historical assignment dates are shown as observations from the migration date.</div>
    <form method="get" class="panel ticket-assignment-panel"><input type="hidden" name="page" value="hr-ticket-approvers"><div class="panel-body">
      <div class="split"><div class="field"><label for="assignment-group">Company Group</label><select id="assignment-group" name="company_group"><option value="">All groups</option><?php foreach(['SW'=>'SW','OC'=>"Ocampo's / OFJI",'UF'=>'Urban Farm'] as $code=>$label):?><option value="<?=e($code)?>" <?=$filter['company_group']===$code?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
      <div class="field"><label for="assignment-company">Company Name</label><select id="assignment-company" name="business_unit_id"><option value="0">All companies</option><?php foreach(FoundationRepository::businessUnits() as $company):?><option value="<?=(int)$company['id']?>" <?=$filter['business_unit_id']===(int)$company['id']?'selected':''?>><?=e($company['name'])?></option><?php endforeach;?></select></div></div>
      <div class="split"><div class="field"><label for="assignment-branch">Branch / Site</label><select id="assignment-branch" name="branch_id"><option value="0">All branches</option><?php foreach(FoundationRepository::branches() as $branch):?><option value="<?=(int)$branch['id']?>" <?=$filter['branch_id']===(int)$branch['id']?'selected':''?>><?=e($branch['name'])?></option><?php endforeach;?></select></div>
      <div class="field"><label for="assignment-kind">Needs approval from</label><select id="assignment-kind" name="kind"><option value="">Manager or ADL</option><option value="MANAGER" <?=$filter['kind']==='MANAGER'?'selected':''?>>Manager (Head Office)</option><option value="ADL" <?=$filter['kind']==='ADL'?'selected':''?>>ADL (Branch / Retail)</option></select></div></div>
      <label><input type="checkbox" name="unresolved" value="1" <?=$filter['unresolved']?'checked':''?>> Show only employees who need an approver</label>
      <button class="btn" type="submit">Apply filters</button> <a class="btn" href="<?=url('hr-ticket-approvers',['unresolved'=>1])?>">Unresolved report</a>
    </div></form>
    <?php if($preview): ?>
    <section class="panel ticket-assignment-panel"><div class="panel-head"><div><h2>Review <?=count($preview['rows'])?> assignment(s)</h2><p>No changes have been saved. Confirm the employees and dates below.</p></div></div><div class="panel-body">
      <p><strong><?=e($preview['data']['approver_type'])?>:</strong> <?=e($candidateNames[$preview['data']['approver_user_id']]??'Approver no longer available')?> · <?=e($preview['data']['effective_from'])?> to <?=e($preview['data']['effective_to']??'No end date')?></p>
      <ul><?php foreach($preview['rows'] as $item):$emp=$item['employee'];?><li><?=e($emp['employee_no'].' · '.$emp['first_name'].' '.$emp['last_name'])?> · <?=e($emp['company_name'])?> · <?=e($emp['branch_name'])?></li><?php endforeach;?></ul>
      <p>Overlapping current assignments will end the day before the new start. Same-day assignments are retained as superseded history. Future overlapping assignments are rejected.</p>
      <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="ticket_approvers_save"><input type="hidden" name="preview_token" value="<?=e($preview['token'])?>"><button class="btn primary">Confirm assignments</button></form>
      <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="ticket_approvers_cancel"><button class="btn">Back to selection</button></form>
    </div></section>
    <?php endif;?>
    <form method="post" class="panel"><?=csrf_field()?><input type="hidden" name="action" value="ticket_approvers_preview">
      <div class="panel-head"><div><h2>Employees and routing</h2><p><?=(int)$report['total']?> matching employees · <?=(int)$report['unresolved']?> unresolved in the selected company / branch</p></div></div>
      <div class="panel-body"><div class="split"><div class="field"><label for="approver-type">Approver type</label><select name="approver_type" id="approver-type"><option value="MANAGER" <?=$kind==='MANAGER'?'selected':''?>>Manager</option><option value="ADL" <?=$kind==='ADL'?'selected':''?>>ADL</option></select></div>
      <div class="field"><label for="approver-user">Active approval account</label><select name="approver_user_id" id="approver-user" required><option value="">Choose an approver</option><?php foreach($candidates as $type=>$list):foreach($list as $person):?><option data-kind="<?=e($type)?>" value="<?=(int)$person['id']?>" <?=((int)($old['approver_user_id']??0)===(int)$person['id'] && $kind===$type)?'selected':''?>><?=e($person['full_name'].' · '.$type)?></option><?php endforeach;endforeach;?></select></div></div>
      <div class="split"><div class="field"><label for="effective-from">Effective from</label><input type="date" id="effective-from" name="effective_from" min="<?=date('Y-m-d')?>" value="<?=e($old['effective_from']??date('Y-m-d'))?>" required></div>
      <div class="field"><label for="effective-to">Effective to (optional)</label><input type="date" id="effective-to" name="effective_to" value="<?=e($old['effective_to']??'')?>"></div></div>
      <button class="btn primary">Preview selected assignments</button></div>
      <div class="ticket-assignment-scroll" tabindex="0" role="region" aria-label="Employee assignments, scroll horizontally on small screens"><table class="tbl ticket-assignment-table"><thead><tr><th><input type="checkbox" id="select-page" aria-label="Select all employees on this page"></th><th>Employee</th><th>Company / Branch</th><th>Required</th><th>Current route / Reason</th></tr></thead><tbody>
      <?php foreach($report['rows'] as $item):$emp=$item['employee'];$route=$item['route']; ?>
      <tr><td><input type="checkbox" name="employee_ids[]" value="<?=(int)$emp['id']?>" aria-label="<?=e('Select '.$emp['employee_no'])?>" <?=in_array((string)$emp['id'],array_map('strval',(array)($old['employee_ids']??[])),true)?'checked':''?>></td>
      <td><strong><?=e($emp['first_name'].' '.$emp['last_name'])?></strong><div><?=e($emp['employee_no'])?></div></td><td><?=e($emp['company_group'].' · '.$emp['company_name'])?><div><?=e($emp['branch_name']?:'Branch not assigned')?></div></td><td><?=e($route['type'])?></td>
      <td><?php if($route['user_id']):?><?=e($candidateNames[$route['user_id']]??'Assigned account')?> <span class="badge gray"><?=e($route['provenance'])?></span><?php else:?><span class="badge amber">Needs HR assignment</span><div><?=e($route['reason'])?></div><?php endif;?></td></tr>
      <?php endforeach;if(!$report['rows']):?><tr><td colspan="5">No employees match these filters.</td></tr><?php endif;?></tbody></table></div>
    </form>
    <nav aria-label="Employee report pages"><p>Page <?=(int)$filter['p']?> of <?=(int)$report['pages']?><?php if($filter['p']>1):?> <a class="btn" href="<?=e(url('hr-ticket-approvers',array_replace($filter,['p'=>$filter['p']-1])))?>">Previous</a><?php endif;if($filter['p']<$report['pages']):?> <a class="btn" href="<?=e(url('hr-ticket-approvers',array_replace($filter,['p'=>$filter['p']+1])))?>">Next</a><?php endif;?></p></nav>
    <script>
      (()=>{const kind=document.getElementById('approver-type'),user=document.getElementById('approver-user');
        function update(){for(const option of user.options){if(!option.value)continue;option.hidden=option.dataset.kind!==kind.value;option.disabled=option.hidden;}if(user.selectedOptions[0]?.disabled)user.value='';}
        kind.addEventListener('change',update);update();document.getElementById('select-page').addEventListener('change',event=>{document.querySelectorAll('input[name="employee_ids[]"]').forEach(input=>input.checked=event.target.checked);});})();
    </script>
    <?php render_portal_footer();
}
