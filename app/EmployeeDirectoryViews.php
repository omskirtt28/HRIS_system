<?php
declare(strict_types=1);

function employee_directory_grouped(array $data): void
{
    $state=$data['state']; $canManage=Auth::can('employees.manage');
    $isHo=EmployeeDirectoryPlacement::isHeadOffice($state['group']);
    $isRetail=EmployeeDirectoryPlacement::isRetail($state['group']);
    $isLeadership=in_array($state['group'],['MANAGERS','ADL'],true);
    $context=['group'=>$state['group']];
    foreach(['area_id','branch_id','main_department','department','department_id','status','needs_details','view','company_group','assigned_area_id'] as $key) if(!empty($state[$key])) $context[$key]=$state[$key];
    $keep=$context; if($state['q']!=='') $keep['q']=$state['q'];
    $isRoot=!$state['area_id'] && !$state['branch_id'] && $state['department']==='' && $state['main_department']==='' && !$data['list'];
    $isAll=$state['group']==='ALL' && !$state['branch_id'];
    $searchLabel=$data['list']?($state['group']==='ADL'?'Search ADLs':($state['group']==='MANAGERS'?'Search managers':'Search employees')):($isHo?($state['main_department']!==''?'Search teams':'Search departments'):($state['area_id'] || $state['group']==='OC_RETAIL'?'Search branches':'Search areas'));
    $emptyMessage=$isHo && $state['main_department']!=='' ? 'No teams found. Try resetting the filters. Teams appear here when their department is saved in Employee 201.' : 'No '.$data['grid_label'].' found. Try another search or reset the filters.';
    $actions='';
    if($canManage && Auth::can('employees.import')) $actions.='<a class="btn" href="'.url('hr-employee-import').'">'.icon_svg('file').' Import roster</a>';
    if($canManage) $actions.='<a class="btn primary" href="'.url('hr-employee-new').'">'.icon_svg('plus').' Add employee</a>';
    render_portal_header('hr','hr-employees','Employee Directory');
    page_head('HR Portal / People','Employees',$actions);
    ?>
    <div class="employee-directory-grouped">
    <?php if(!$data['ready']):?><div class="alert error">Employee records are not ready. Import the Employee database migration first.</div><?php endif;?>
    <?php if($data['ready'] && !$data['grouping_ready']):?><div class="alert error">Import <strong>20261010_employee_directory_groups.sql</strong> to load areas and branches. You can still open All employees below.</div><?php endif;?>
    <?php if($data['ready'] && !$data['placement_ready']):?><div class="alert">Import <strong>20261010_employee_company_directory.sql</strong> to save company groups and ADL areas.</div><?php endif;?>
    <form method="get" action="<?=url('hr-employees')?>" class="directory-global-search" role="search" aria-label="Search all employees">
      <input type="hidden" name="page" value="hr-employees">
      <input type="hidden" name="group" value="ALL">
      <div class="directory-global-search-field"><label for="directory-all-employee-search">Search all employees</label><div class="employee-search"><?=icon_svg('search')?><input id="directory-all-employee-search" type="search" name="q" value="<?=e($isAll?$state['q']:'')?>" maxlength="150" placeholder="Name, employee code, email or position" aria-describedby="directory-global-search-help"></div><p id="directory-global-search-help">Find anyone in Retail or Head Office.</p></div>
      <div class="directory-global-search-actions"><button class="btn primary" type="submit"><?=icon_svg('search')?> Search</button><?php if($isAll && $state['q']!==''):?><a class="btn ghost" href="<?=url('hr-employees',['group'=>'ALL'])?>">Clear</a><?php endif;?></div>
    </form>
    <nav class="employee-tabs employee-directory-tabs directory-workplace-tabs" aria-label="Employee groups">
      <?php foreach(array_diff_key(EmployeeDirectoryPlacement::GROUPS,['UNASSIGNED'=>true,'ALL'=>true]) as $code=>$label):?>
      <a class="<?=$state['group']===$code?'active':''?>" href="<?=url('hr-employees',['group'=>$code])?>" <?=$state['group']===$code?'aria-current="page"':''?>><?=e($label)?><span class="directory-tab-count"><?=(int)$data['counts'][$code]?></span></a>
      <?php endforeach;?>
      <?php if($data['counts']['UNASSIGNED'] || $state['group']==='UNASSIGNED'):?><a class="<?=$state['group']==='UNASSIGNED'?'active':''?>" href="<?=url('hr-employees',['group'=>'UNASSIGNED'])?>" <?=$state['group']==='UNASSIGNED'?'aria-current="page"':''?>>To assign <span class="directory-tab-count"><?=(int)$data['counts']['UNASSIGNED']?></span></a><?php endif;?>
      <a class="directory-all-link <?=$state['group']==='ALL'?'active':''?>" href="<?=url('hr-employees',['group'=>'ALL'])?>" <?=$state['group']==='ALL'?'aria-current="page"':''?>>All employees <span class="directory-tab-count"><?=(int)$data['counts']['ALL']?></span></a>
    </nav>
    <?php if(!$isRoot):?>
      <nav class="directory-breadcrumbs" aria-label="Directory location"><a href="<?=url('hr-employees')?>">Employees</a><?php foreach($data['crumbs'] as $index=>$crumb):?><span aria-hidden="true">/</span><?php if($crumb['params'] && $index<count($data['crumbs'])-1):?><a href="<?=url('hr-employees',$crumb['params'])?>"><?=e($crumb['label'])?></a><?php else:?><span aria-current="page"><?=e($crumb['label'])?></span><?php endif;?><?php endforeach;?></nav>
      <?php if($state['branch_id'] || $state['department']!=='' || $state['area_id']!==0 || $state['main_department']!==''):?><a class="directory-back-link" href="<?=url('hr-employees',$data['parent'])?>"><?=icon_svg('arrow')?> Back to <?=e($data['back_label'])?></a><?php endif;?>
    <?php endif;?>
    <section class="panel employee-directory-panel employee-directory-refresh <?=$data['list']?'directory-group-list':'directory-group-browser'?>">
      <div class="panel-head"><div><h2><?=e($data['title'])?></h2><p><?=e($data['subtitle'])?></p><?php if($isHo && $isRoot):?><p>Employees are grouped automatically. Department Managers and ADLs have their own tabs.</p><?php endif;?><?php if($isAll && $state['q']!==''):?><p class="directory-search-term">Results for <strong><?=e($state['q'])?></strong></p><?php endif;?></div><span class="badge gray"><?=$data['list']?(int)$data['total'].' employees':e(count($data['cards']).' '.$data['grid_label'])?></span></div>
      <form method="get" class="employee-filterbar directory-group-filter <?=$isAll?'directory-global-result-filter':''?> <?=$isLeadership?'directory-leadership-filter':''?>">
        <input type="hidden" name="page" value="hr-employees">
        <?php foreach($context as $key=>$value):if(in_array($key,['status','needs_details','company_group','assigned_area_id'],true))continue;?><input type="hidden" name="<?=e($key)?>" value="<?=e($value)?>"><?php endforeach;?>
        <?php if($isAll):?><input type="hidden" name="q" value="<?=e($state['q'])?>"><?php else:?><div class="directory-filter-field directory-search-field"><label for="group-directory-search"><?=e($searchLabel)?></label><div class="employee-search"><?=icon_svg('search')?><input id="group-directory-search" name="q" value="<?=e($state['q'])?>" placeholder="<?=$data['list']?'Employee code, name or position':($isHo?'Department or team name':($state['area_id'] || $state['group']==='OC_RETAIL'?'Branch code or name':'Area name'))?>"></div></div><?php endif;?>
        <?php if($isLeadership):?><div class="directory-filter-field"><label for="group-directory-company">Company</label><select id="group-directory-company" name="company_group"><option value="">All companies</option><?php foreach(['SW'=>'Sw','OC'=>"Ocampo's"] as $code=>$label):?><option value="<?=e($code)?>" <?=$state['company_group']===$code?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div><?php endif;?>
        <?php if($state['group']==='ADL'):?><div class="directory-filter-field"><label for="group-directory-area">Assigned area</label><select id="group-directory-area" name="assigned_area_id"><option value="0">All areas</option><?php foreach($data['areas'] as $area):?><option value="<?=(int)$area['id']?>" <?=$state['assigned_area_id']===(int)$area['id']?'selected':''?>><?=e($area['name'])?></option><?php endforeach;?></select></div><?php endif;?>
        <div class="directory-filter-field"><label for="group-directory-status">Status</label><select id="group-directory-status" name="status"><option value="">All statuses</option><?php foreach(['ACTIVE'=>'Active','PROBATIONARY'=>'Probationary','ON_LEAVE'=>'On leave','INACTIVE'=>'Inactive','RESIGNED'=>'Resigned','TERMINATED'=>'Terminated'] as $code=>$label):?><option value="<?=e($code)?>" <?=$state['status']===$code?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
        <div class="directory-filter-field"><label for="group-directory-details">Details</label><select id="group-directory-details" name="needs_details"><option value="0">All employees</option><option value="1" <?=$state['needs_details']?'selected':''?>>Needs details</option></select></div>
        <div class="directory-filter-actions"><button class="btn sm" type="submit"><?=$isAll?'Apply filters':'Search'?></button><a class="btn sm ghost" href="<?=url('hr-employees',array_diff_key($context,['status'=>true,'needs_details'=>true,'company_group'=>true,'assigned_area_id'=>true])+($isAll && $state['q']!==''?['q'=>$state['q']]:[]))?>"><?=$isAll?'Reset filters':'Reset'?></a></div>
      </form>
      <?php if($data['list']):?>
        <?php if(!$data['rows']):?><div class="empty employee-empty">No employees found. Try another search or reset the filters.</div><?php else:?>
        <div class="employee-table-wrap" role="region" aria-label="Employees in this group" tabindex="0"><table class="tbl employee-table directory-group-table"><caption class="directory-sr-only">Employees in this group. Open Employee 201 to update details.</caption><thead><tr><th scope="col"><?=$state['group']==='ADL'?'ADL':($state['group']==='MANAGERS'?'Manager':'Employee')?></th><th scope="col"><?=$state['group']==='ADL'?'Assigned area':($state['group']==='MANAGERS'?'Department':'Position')?></th><th scope="col">Company</th><th scope="col">Status</th><th scope="col">Details</th><th scope="col"><span class="directory-sr-only">Actions</span></th></tr></thead><tbody>
        <?php foreach($data['rows'] as $employee):
          $name=EmployeeRepository::fullName($employee); $pending=EmployeeRepository::rosterIssues($employee); $needsDetails=!empty($employee['roster_needs_details']);
          $departmentName=!empty($employee['department_name']) ? EmployeeDirectoryHierarchy::department(EmployeeDirectoryService::departmentLabel((string)$employee['department_name']))['name'] : 'Department to follow';
          $labels=employee_roster_detail_labels($pending); $profile=['id'=>$employee['id']];
          if($needsDetails) $profile['tab']=isset($pending['name'])?'personal':'employment';
          $action=$needsDetails?($canManage?'Update details':'View details'):'Open 201';
          if($state['group']==='UNASSIGNED') { $profile['tab']='employment'; $action=$canManage?'Set assignment':'View details'; }
          $company=$employee['workplace']==='HO' ? ($employee['legal_entity_name'] ?? $employee['business_unit_name'] ?? '') : ($employee['business_unit_name'] ?? $employee['legal_entity_name'] ?? '');
          $tone=in_array($employee['status'],['ACTIVE','PROBATIONARY'],true)?'green':'gray';
        ?>
        <tr>
          <td class="directory-identity-cell" data-label="Employee"><a class="employee-cell" href="<?=url('hr-employee',['id'=>$employee['id']])?>"><span class="employee-avatar"><?=e(initials($name))?></span><span><strong><?=e($name)?></strong><small><?=e($employee['employee_no'] ?: 'Code to follow')?></small></span></a></td>
          <td data-label="<?=$state['group']==='ADL'?'Assigned area':($state['group']==='MANAGERS'?'Department':'Position')?>"><strong><?=e($state['group']==='ADL'?EmployeeDirectoryPlacement::areaNames($employee['directory_area_ids']??null,$data['areas']):($state['group']==='MANAGERS'?$departmentName:($employee['position_name'] ?: 'Position to follow')))?></strong><span class="directory-secondary"><?=e($isLeadership?($employee['position_name'] ?: 'Position to follow'):$departmentName)?><?php if($state['group']==='ALL' || $state['group']==='UNASSIGNED'):?> · <?=e($employee['branch_code'] ?: ($employee['branch_name'] ?: 'Branch to follow'))?><?php endif;?></span></td>
          <td data-label="Company"><strong><?=e(EmployeeDirectoryPlacement::companyLabel((string)$employee['directory_company']))?></strong><span class="directory-secondary"><?=e(EmployeeDirectoryService::companyLabel($company) ?: 'Brand / employer to follow')?></span></td>
          <td class="directory-status-cell" data-label="Status"><span class="badge <?=$tone?>"><?=e(stage_label($employee['status']))?></span></td>
          <td class="directory-details-cell" data-label="Details"><?php if($needsDetails):?><span class="employee-followup-badge">Needs details</span><span class="directory-secondary" title="<?=e(implode(', ',$labels))?>"><?=count($pending)?count($pending).' '.(count($pending)===1?'item':'items').' to finish':'Open 201 to check'?></span><?php else:?><span class="directory-secondary">No pending items</span><?php endif;?></td>
          <td class="directory-action-cell" data-label="Actions"><a class="btn sm directory-profile-link <?=$needsDetails?'directory-update-link':''?>" href="<?=url('hr-employee',$profile)?>" aria-label="<?=e($action.' for '.$name)?>"><?=e($action)?><?=icon_svg('arrow')?></a></td>
        </tr>
        <?php endforeach;?></tbody></table></div>
        <nav class="directory-pagination" aria-label="Employee list pages"><span><?=($state['p']-1)*25+1?>–<?=min($state['p']*25,$data['total'])?> of <?=(int)$data['total']?> employees</span><div><?php if($state['p']>1):?><a class="btn sm" href="<?=url('hr-employees',$keep+['p'=>$state['p']-1])?>">Previous</a><?php endif;?><span>Page <?=(int)$state['p']?> of <?=(int)$data['pages']?></span><?php if($state['p']<$data['pages']):?><a class="btn sm" href="<?=url('hr-employees',$keep+['p'=>$state['p']+1])?>">Next</a><?php endif;?></div></nav>
        <?php endif;?>
      <?php else:?>
        <?php if(!$data['cards']):?><div class="empty employee-empty"><?=e($emptyMessage)?></div><?php else:?>
        <div class="directory-card-grid <?=$isHo && $state['main_department']===''?'directory-head-office-main-grid':''?>">
          <?php $lastSection=''; foreach($data['cards'] as $card): $section=$card['section'] ?? ''; if($section!=='' && $section!==$lastSection): $lastSection=$section; ?><h3 class="directory-card-section"><?=e($section)?></h3><?php endif;?>
          <a class="directory-group-card <?=$card['notice']?'directory-card-followup':''?>" href="<?=url('hr-employees',$card['params'])?>"><span class="directory-card-icon" aria-hidden="true"><?=icon_svg($card['icon'])?></span><span class="directory-card-content"><strong><?=e($card['title'])?></strong><?php if($card['detail']!=='' && $card['detail']!==$card['title']):?><span class="directory-card-detail"><?=e($card['detail'])?></span><?php endif;?><span class="directory-card-count"><?=(int)$card['count']?> <?=(int)$card['count']===1?'employee':'employees'?></span><?php if($card['notice']):?><span class="employee-followup-badge"><?=e($card['notice'])?></span><?php endif;?><span class="directory-card-action"><?=e($card['action'])?><?=icon_svg('arrow')?></span></span></a><?php endforeach;?>
        </div>
        <?php endif;?>
      <?php endif;?>
    </section>
    <?php if($data['note']!==''):?><p class="directory-help"><?=e($data['note'])?></p><?php endif;?>
    <?php if($isRetail && $state['area_id']===-1):?><p class="directory-help">For NOM2, confirm its area with HR. Missing assignments can be updated in Employee 201.</p><?php endif;?>
    <?php if($state['group']==='UNASSIGNED'):?><p class="directory-help">Open Employee 201 → Employment to set the company group and branch. Employee accounts and records stay available in All employees.</p><?php endif;?>
    </div>
    <?php render_portal_footer();
}
