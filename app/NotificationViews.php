<?php
declare(strict_types=1);

function probation_watch_page(array $rows,string $filter,bool $ready,bool $mailReady): void
{
    render_portal_header('hr','hr-probation','Probation Watch');
    page_head('HR Portal / People','Probation Watch','<a class="btn" href="'.url('hr-employees').'">Back to employees</a>'); ?>
    <div class="probation-watch">
    <?php if(!$ready):?><div class="alert error">Import <strong>20261012_probation_notifications.sql</strong> to turn on probation tracking.</div><?php endif;?>
    <?php if(!$mailReady):?><div class="alert amber">Email is not set up yet, so reminders appear in the app only. Add the Gmail settings to <code>config/config.local.php</code> (see README).</div><?php endif;?>
    <section class="panel">
      <div class="panel-head"><div><h2>Probationary and Seasonal employees</h2><p>Probation is 6 months and a Seasonal contract is 3 months from the hire date, counted automatically. The ADL (retail) or Manager (Head Office) is reminded one month before the end (5 months / 2 months); HR and the employee at the end.</p></div><span class="badge gray"><?=count($rows)?> employees</span></div>
      <nav class="employee-tabs probation-filters" aria-label="Filter">
        <?php foreach([''=>'All','soon'=>'Ending in 30 days','overdue'=>'Overdue'] as $key=>$label):?><a class="<?=$filter===$key?'active':''?>" href="<?=url('hr-probation',$key?['filter'=>$key]:[])?>"><?=e($label)?></a><?php endforeach;?>
      </nav>
      <?php if(!$rows):?><div class="empty employee-empty">No probationary or Seasonal employees <?=$filter?'in this view':'with an end date yet'?>. Set Employee Status to Probationary or Seasonal and enter the hire date in Employee 201.</div><?php else:?>
      <div class="employee-table-wrap" role="region" aria-label="Probationary and Seasonal employees" tabindex="0"><table class="tbl employee-table"><thead><tr><th>Employee</th><th>Where</th><th>Term</th><th>Under (ADL / Manager)</th><th>Reminders</th></tr></thead><tbody>
      <?php foreach($rows as $r): $name=EmployeeRepository::fullName($r); $days=(int)$r['days_left']; $sup=$r['supervisor'];
        $tone=$days<0?'red':($days<=30?'amber':'gray'); $label=$days<0?abs($days).' days overdue':($days===0?'Ends today':$days.' days left'); ?>
        <tr>
          <td data-label="Employee"><a class="employee-cell" href="<?=url('hr-employee',['id'=>$r['id'],'tab'=>'employment'])?>"><span class="employee-avatar"><?=e(initials($name))?></span><span><strong><?=e($name)?></strong><small><?=e($r['employee_no']?:'Code to follow')?></small></span></a></td>
          <td data-label="Where"><?=e($r['position_name']?:'No position')?><small class="archive-sub"><?=e($r['branch_name']?:'No branch')?><?=$r['department_name']?' · '.e($r['department_name']):''?></small></td>
          <td data-label="Term"><small class="archive-sub"><?=e($r['term_label'])?></small><?=e(date('M j, Y',strtotime($r['probation_start_date'])))?> → <strong><?=e(date('M j, Y',strtotime($r['probation_end_date'])))?></strong><small class="archive-sub"><span class="badge <?=$tone?>"><?=e($label)?></span></small></td>
          <td data-label="Under (ADL / Manager)"><?php if($sup['people']):?><?php foreach($sup['people'] as $p):?><div><strong><?=e(EmployeeRepository::fullName($p))?></strong><small class="archive-sub"><?=e($sup['label'])?> · <?=!empty($p['user_id'])?'has login':'no login'?> · <?=(!empty($p['company_email'])||!empty($p['personal_email']))?'has email':'no email'?></small></div><?php endforeach;?><?php else:?><span class="badge amber">No <?=e($sup['label'])?> found</span><small class="archive-sub">Set Reporting To or the ADL's assigned areas in Employee 201.</small><?php endif;?></td>
          <td data-label="Reminders"><?php foreach(($r['seasonal']?['SEASONAL_2M'=>'2-month','SEASONAL_END'=>'End']:['PROBATION_5M'=>'5-month','PROBATION_END'=>'End']) as $kind=>$text): $n=$r['notices'][$kind]??null;?><div><?=e($text)?>: <?php if(!$n):?><span class="muted"><?=$r['stage']>=(in_array($kind,['PROBATION_5M','SEASONAL_2M'],true)?1:2)?'pending':'not yet due'?></span><?php else:?><?=(int)$n['total']?> sent in-app<?=(int)$n['emailed']?' · '.(int)$n['emailed'].' emailed':''?><?=(int)$n['failed']?' · <span class="badge red">'.(int)$n['failed'].' email failed</span>':''?><?php endif;?></div><?php endforeach;?></td>
        </tr>
      <?php endforeach;?>
      </tbody></table></div>
      <?php endif;?>
    </section>
    </div>
    <?php render_portal_footer();
}
