<?php
declare(strict_types=1);

const TICKET_VIEWER_ACCOUNT_LABEL=['ACTIVE'=>'Account active','NONE'=>'No account','DISABLED'=>'Account disabled','NO_PERMISSION'=>'Role cannot view'];

function ticket_viewer_person(array $p): string
{
    return e(trim(($p['first_name']??'').' '.($p['last_name']??'')).(!empty($p['employee_no'])?' · '.$p['employee_no']:''));
}

function ticket_viewer_account_badge(string $state): string
{
    return '<span class="badge '.($state==='ACTIVE'?'green':'red').'">'.e(TICKET_VIEWER_ACCOUNT_LABEL[$state]??$state).'</span>';
}

function ticket_viewers_page(array $filter,array $rows,array $report): void
{
    $areas=FoundationRepository::areas();$c=$report['counts'];
    render_portal_header((Auth::user()['role_portal']??'')==='admin'?'admin':'hr','hr-ticket-viewers','Ticket Viewers'); ?>
    <style>.tv-panel{margin-bottom:20px}.tv-scroll{overflow-x:auto;max-width:100%}.tv-table{min-width:860px}.tv-stats{display:flex;flex-wrap:wrap;gap:12px}.tv-stats div{min-width:150px}.tv-problem{margin:4px 0}</style>
    <div class="page-head"><div><h1>Ticket Viewers</h1><p>Problem report only. Who may read an employee's HR requests is worked out automatically; viewers cannot approve, return, reject, cancel, complete, comment or attach files, and never see Incident Reports.</p></div></div>
    <div class="alert">Retail: the ADL whose assigned areas include the branch (Employee 201, Assigned areas for ADL), the Sales Captain of the branch and the OIC of the branch (same-branch employees by position). Head Office: the employee's Manager from Ticket Approvers. Each person needs a linked, active account on a role with <em>View team HR requests</em>. A branch must always have a Sales Captain with an account. Nothing is assigned on this page; fix the employee, position, account or area instead.</div>

    <section class="panel tv-panel"><div class="panel-head"><div><h2>Unresolved viewers</h2><p>Names are for HR only.</p></div></div><div class="panel-body">
      <div class="tv-stats"><div><strong><?=(int)$c['employees']?></strong><br>active employees</div><div><strong><?=(int)$c['with_viewer']?></strong><br>have a viewer</div><div><strong><?=(int)$c['retail_missing']?></strong><br>retail without a viewer</div><div><strong><?=(int)$c['ho_missing']?></strong><br>Head Office without a viewer</div></div>
      <details><summary><span class="badge red">PROBLEM</span> <?=count($report['branches_without_sc'])?> branch(es) with employees and no Sales Captain</summary><ul><?php foreach($report['branches_without_sc'] as $b):?><li><?=e($b['name'])?> · <?=(int)$b['emps']?> employee(s) · <?=$b['adls']&&array_filter($b['adls'],fn($a)=>$a['can_view'])?'ADL can view':'no ADL can view either'?></li><?php endforeach;?></ul></details>
      <details><summary><span class="badge red">PROBLEM</span> <?=count($report['branches_sc_no_account'])?> branch(es) whose Sales Captain has no usable account</summary><ul><?php foreach($report['branches_sc_no_account'] as $b):?><li><?=e($b['name'])?> · <?=e(implode(', ',array_map(fn($s)=>trim($s['first_name'].' '.$s['last_name']).' ('.$s['employee_no'].'): '.(TICKET_VIEWER_ACCOUNT_LABEL[$s['account']]??''),array_filter($b['sc'],fn($s)=>!$s['can_view']))))?></li><?php endforeach;?></ul><small>Create or link the account in Users, on a role that holds View team HR requests.</small></details>
      <details><summary><span class="badge red">PROBLEM</span> <?=count($report['branches_multi_sc'])?> branch(es) with more than one Sales Captain</summary><ul><?php foreach($report['branches_multi_sc'] as $b):?><li><?=e($b['name'])?> · <?=count($b['sc'])?> Sales Captains</li><?php endforeach;?></ul></details>
      <details><summary><?=count($report['adls_unready'])?> ADL(s) without a usable account or assigned area</summary><ul><?php foreach($report['adls_unready'] as $a):?><li><?=e($a['first_name'].' '.$a['last_name'].' · '.$a['employee_no'])?> · <?=$a['user_id']?'account ok':'no active account'?> · <?=$a['areas']?'areas set':'no area'?><?=($a['user_id']&&!$a['can_view'])?' · role lacks View team HR requests':''?></li><?php endforeach;?></ul></details>
      <details><summary><span class="badge red">PROBLEM</span> <?=count($report['adls_in_head_office'])?> ADL(s) whose branch is Head Office</summary><ul><?php foreach($report['adls_in_head_office'] as $a):?><li><?=e($a['first_name'].' '.$a['last_name'].' · '.$a['employee_no'])?> · <?=e($a['branch_name']?:'No branch')?></li><?php endforeach;?></ul><small>Their coverage comes from Assigned areas, not from this branch.</small></details>
      <details><summary><?=count($report['branches_without_area'])?> retail branch(es) without an area</summary><ul><?php foreach($report['branches_without_area'] as $b):?><li><?=e($b['name'])?> · <?=(int)$b['emps']?> employee(s)</li><?php endforeach;?></ul><small>An ADL cannot cover a branch until its area is set.</small></details>
      <details <?=$report['missing_total']?'open':''?>><summary><?=(int)$report['missing_total']?> employee(s) with no resolvable viewer</summary>
        <ul><?php foreach($report['missing'] as $m):?><li><?=e($m['first_name'].' '.$m['last_name'].' · '.$m['employee_no'])?> · <?=e($m['branch_name']?:'No branch')?> · <?=$m['is_ho']?'Head Office':'Retail'?></li><?php endforeach;?></ul>
        <p>Page <?=(int)$report['page']?> of <?=(int)$report['pages']?> <?php if($report['page']>1):?><a class="btn" href="<?=e(url('hr-ticket-viewers',array_replace($filter,['p'=>$report['page']-1])))?>">Previous</a><?php endif;if($report['page']<$report['pages']):?> <a class="btn" href="<?=e(url('hr-ticket-viewers',array_replace($filter,['p'=>$report['page']+1])))?>">Next</a><?php endif;?></p></details>
    </div></section>

    <form method="get" class="panel tv-panel"><input type="hidden" name="page" value="hr-ticket-viewers"><div class="panel-body split">
      <div class="field"><label for="tv-area">Area</label><select id="tv-area" name="area_id"><option value="0">All areas</option><option value="-1" <?=$filter['area_id']===-1?'selected':''?>>No area</option><?php foreach($areas as $a):?><option value="<?=(int)$a['id']?>" <?=$filter['area_id']===(int)$a['id']?'selected':''?>><?=e($a['name'])?></option><?php endforeach;?></select></div>
      <div class="field"><label for="tv-branch">Branch</label><select id="tv-branch" name="branch_id"><option value="0">All branches</option><?php foreach(FoundationRepository::branches() as $b):if(($b['site_type']??'')==='HEAD_OFFICE')continue;?><option value="<?=(int)$b['id']?>" <?=$filter['branch_id']===(int)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></div>
      <div class="field"><label for="tv-all">Show</label><select id="tv-all" name="all"><option value="0" <?=$filter['problems']?'selected':''?>>Branches with a problem</option><option value="1" <?=$filter['problems']?'':'selected'?>>All branches</option></select></div>
      <div><button class="btn" type="submit">Apply filters</button></div>
    </div></form>

    <section class="panel tv-panel"><div class="panel-head"><div><h2>Branches</h2><p><?=count($rows)?> branch(es) shown. Read-only.</p></div></div>
      <div class="tv-scroll" tabindex="0" role="region" aria-label="Branches, scroll horizontally on small screens"><table class="tbl tv-table"><thead><tr><th>Branch</th><th>Area</th><th>Employees</th><th>Sales Captain</th><th>OIC</th><th>ADL</th><th>Problems</th></tr></thead><tbody>
      <?php foreach($rows as $r):?><tr><td><strong><?=e($r['name'])?></strong></td><td><?=e($r['area_name']?:'No area')?></td><td><?=(int)$r['emps']?></td>
        <td><?php foreach($r['sc'] as $s):?><div><?=ticket_viewer_person($s)?> <?=ticket_viewer_account_badge($s['account'])?></div><?php endforeach;if(!$r['sc']):?><span class="badge red">None</span><?php endif;?></td>
        <td><?php foreach($r['oic'] as $s):?><div><?=ticket_viewer_person($s)?> <?=ticket_viewer_account_badge($s['account'])?></div><?php endforeach;if(!$r['oic']):?><span class="badge gray">None</span><?php endif;?></td>
        <td><?php foreach($r['adls'] as $a):?><div><?=ticket_viewer_person($a)?> <?=ticket_viewer_account_badge($a['account'])?></div><?php endforeach;if(!$r['adls']):?><span class="badge red"><?=$r['area_id']?'No ADL':'No area'?></span><?php endif;?></td>
        <td><?php foreach($r['problems'] as [$code,$text]):?><div class="tv-problem"><span class="badge red">PROBLEM</span> <?=e($text)?></div><?php endforeach;if(!$r['problems']):?><span class="badge green">OK</span><?php endif;?></td></tr>
      <?php endforeach;if(!$rows):?><tr><td colspan="7">No branches match these filters.</td></tr><?php endif;?></tbody></table></div>
    </section>
    <?php render_portal_footer();
}

/** Read-only panel for the Employee 201 Employment tab. Never edits; nothing to assign. */
final class TicketViewerEmploymentLine
{
    public static function html(int $employeeId): string
    {
        try {$v=TicketViewerService::viewersFor($employeeId);} catch (Throwable) {return '';}
        if (!$v['ready']) return '';
        $rows=[];
        $row=static function(string $role,array $p)use(&$rows):void{
            $name=!empty($p['first_name'])||!empty($p['last_name'])?trim(($p['first_name']??'').' '.($p['last_name']??'')):(string)($p['name']??'');
            $rows[]='<tr><td>'.e($role).'</td><td>'.e($name).'</td><td>'.e((string)($p['employee_no']??'')).'</td><td>'.ticket_viewer_account_badge((string)($p['account']??'NONE')).'</td></tr>';
        };
        $none=static fn(string $role,bool $problem):string=>'<tr><td>'.e($role).'</td><td colspan="3"><span class="badge '.($problem?'red':'gray').'">'.($problem?'None, see problem below':'None').'</span></td></tr>';
        if ($v['head_office']) {
            if (!empty($v['manager_filer'])) $rows[]='<tr><td>Manager</td><td colspan="3"><span class="badge gray">Not applicable</span> This employee is a Manager: requests go straight to HR and nobody above them views.</td></tr>';
            elseif ($v['manager']) $row('Manager',$v['manager']); else $rows[]=$none('Manager',true);
        } elseif ($v['branch']) {
            foreach ($v['adls'] as $a) $row('ADL',$a);
            if (!$v['adls']) $rows[]=$none('ADL',true);
            foreach ($v['sc'] as $s) $row('Sales Captain',$s);
            if (!$v['sc']) $rows[]=$none('Sales Captain',true);
            foreach ($v['oic'] as $s) $row('OIC',$s);
            if (!$v['oic']) $rows[]=$none('OIC',false);
        }
        $problems='';
        foreach ($v['problems'] as [$code,$text]) $problems.='<div class="alert error" role="alert" data-viewer-problem="'.e($code).'"><strong>PROBLEM</strong> '.e($text).'</div>';
        $link=Auth::can('tickets.approvers.manage')?' <a href="'.e(url('hr-ticket-viewers')).'">Ticket Viewers problem report</a>':'';
        return '<div class="panel-body" data-ticket-viewers-line><div class="field full"><label>Who can view this employee\'s tickets</label>'
            .($rows?'<div class="tv-scroll" style="overflow-x:auto;max-width:100%"><table class="tbl"><thead><tr><th>Role</th><th>Name</th><th>Employee no.</th><th>Account</th></tr></thead><tbody>'.implode('',$rows).'</tbody></table></div>':'<div><span class="badge red">No viewer</span></div>')
            .$problems
            .'<small>Read-only. They can read this employee\'s HR requests, not act on them, and never see Incident Reports. Worked out automatically from branch, position and area; nothing is assigned here.'.$link.'</small></div></div>';
    }
}
