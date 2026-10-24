<?php
declare(strict_types=1);
require_once __DIR__.'/TicketCoeViews.php';
/** Readable value for the detail page: employee number and name, branch name, or the stored text. */
function ticket_service_value(array $field,mixed $value):string {
 if($value===''||$value===null)return '';
 if(($field['kind']??'')==='employee'){$q=db()->prepare('SELECT employee_no,first_name,last_name FROM employees WHERE id=?');$q->execute([(int)$value]);$e=$q->fetch();return $e?$e['employee_no'].' · '.$e['first_name'].' '.$e['last_name']:'Employee reference '.(int)$value;}
 if(($field['kind']??'')==='branch'){$q=db()->prepare('SELECT name FROM branches WHERE id=?');$q->execute([(int)$value]);return (string)($q->fetchColumn()?:'Branch reference '.(int)$value);}
 return (string)$value;
}
function ticket_service_select(array $types,int $selected):void { ?>
 <div class="field"><label for="ticket-type">Request type</label><select name="ticket_type_id" id="ticket-type" required onchange="location.href='index.php?page=hr-request-new&type='+this.value"><option value="">Choose a type</option><?php foreach($types as $ty):?><option value="<?=(int)$ty['id']?>" <?=(int)$ty['id']===$selected?'selected':''?>><?=e($ty['name'])?></option><?php endforeach;?></select></div>
<?php }
function ticket_service_fields(string $code,array $old):void {
 foreach(TicketTypes::definition($code)['fields'] as $key=>$field){$value=$old[$key]??''; ?>
 <div class="field"><label for="field-<?=e($key)?>"><?=e($field['label'])?></label>
 <?php if(($field['kind']??'')==='employee'):?><select id="field-<?=e($key)?>" name="<?=e($key)?>" <?=empty($field['optional'])?'required':''?>><option value="">Choose employee</option><?php foreach(TicketTypes::employeeChoices($code) as $eid=>$name):?><option value="<?=(int)$eid?>" <?=(int)$value===$eid?'selected':''?>><?=e($name)?></option><?php endforeach;?></select>
 <?php elseif(($field['kind']??'')==='branch'):?><select id="field-<?=e($key)?>" name="<?=e($key)?>" required><option value="">Choose branch</option><?php foreach(FoundationRepository::branches() as $branch):?><option value="<?=(int)$branch['id']?>" <?=(int)$value===(int)$branch['id']?'selected':''?>><?=e($branch['name'])?></option><?php endforeach;?></select>
 <?php elseif(isset($field['options'])):?><select id="field-<?=e($key)?>" name="<?=e($key)?>" required><option value="">Choose</option><?php foreach($field['options'] as $option):?><option value="<?=e($option)?>" <?=(string)$value===(string)$option?'selected':''?>><?=e($option)?></option><?php endforeach;?></select>
 <?php elseif(($field['kind']??'')==='text'):?><textarea id="field-<?=e($key)?>" name="<?=e($key)?>" maxlength="<?=(int)($field['max']??5000)?>" rows="<?=(int)($field['rows']??4)?>" <?=empty($field['optional'])?'required':''?>><?=e((string)$value)?></textarea>
 <?php else:?><input id="field-<?=e($key)?>" name="<?=e($key)?>" type="<?=e($field['kind']??'text')?>" value="<?=e((string)$value)?>" <?=isset($field['min'])?'min="'.(int)$field['min'].'"':''?> <?=isset($field['max_value'])?'max="'.(int)$field['max_value'].'"':''?> <?=empty($field['optional'])?'required':''?>><?php endif;?></div>
 <?php }
}
function ticket_service_new(array $employee,array $types,array $type,string $key,array $old):void {
 ticket_header('hr-requests-my','File an HR Request');page_head('HR Requests',$type['name']);
 ?>
 <form method="post" enctype="multipart/form-data" class="panel ticket-panel"><?=csrf_field()?><input type="hidden" name="action" value="core_ticket_create"><input type="hidden" name="submission_key" value="<?=e($key)?>"><div class="panel-body">
 <?php ticket_service_select($types,(int)$type['id']);?><p>Requested by: <strong><?=e(Auth::user()['name'])?></strong> · <?=e($employee['branch_name'])?></p>
 <?php if(TicketTypes::openDuplicates($type['code']))echo '<div class="alert">Another open '.e($type['name']).' exists. You can still submit this request.</div>';ticket_service_fields($type['code'],$old);ticket_upload_input('new-attachments');?><div class="ticket-actions"><button class="btn" name="intent" value="DRAFT">Save draft</button><button class="btn primary" name="intent" value="SUBMIT">Submit HR request</button></div></div></form>
 <?php render_portal_footer();
}
function ticket_service_list(array $result,array $filters,string $mode='services'):void {
 ticket_header($mode==='feed'?'hr-incidents':'hr-service-requests',$mode==='accounting'?'Accounting Follow-up':($mode==='feed'?'Incident Cases':'HR Request Queue'));page_head($mode==='feed'?'Incident Reports':'HR Requests',$mode==='accounting'?'Accounting Follow-up':($mode==='feed'?'Incident Cases':'HR Request Queue')); ?>
 <?php if($mode==='services'):?><form method="get" class="panel ticket-panel"><input type="hidden" name="page" value="hr-service-requests"><div class="panel-body ticket-filters">
 <div class="field"><label for="queue-type">Type</label><select name="type_code" id="queue-type"><option value="">All service types</option><?php foreach(TicketRepository::types() as $ty):if($ty['code']==='INCIDENT_REPORT')continue;?><option value="<?=e($ty['code'])?>" <?=($filters['type_code']??'')===$ty['code']?'selected':''?>><?=e($ty['name'])?></option><?php endforeach;?></select></div>
 <div class="field"><label for="queue-status">Status</label><select name="status" id="queue-status"><option value="">All statuses</option><?php foreach(array_keys(TicketPolicy::TRANSITIONS) as $status):if($status==='DRAFT')continue;?><option value="<?=e($status)?>" <?=($filters['status']??'')===$status?'selected':''?>><?=e(TicketTypes::label($status))?></option><?php endforeach;?></select></div>
 <div class="field"><label for="queue-label">Company label</label><select id="queue-label" name="company_label"><option value="">All labels</option><?php foreach(['SW','OFJI'] as $label):?><option <?=($filters['company_label']??'')===$label?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
 <div class="field"><label for="queue-company">Company</label><select id="queue-company" name="business_unit_id"><option value="">All companies</option><?php foreach(FoundationRepository::businessUnits() as $row):?><option value="<?=(int)$row['id']?>" <?=(int)($filters['business_unit_id']??0)===(int)$row['id']?'selected':''?>><?=e($row['name'])?></option><?php endforeach;?></select></div>
 <div class="field"><label for="queue-branch">Branch</label><select id="queue-branch" name="branch_id"><option value="">All branches</option><?php foreach(FoundationRepository::branches() as $row):?><option value="<?=(int)$row['id']?>" <?=(int)($filters['branch_id']??0)===(int)$row['id']?'selected':''?>><?=e($row['name'])?></option><?php endforeach;?></select></div>
 <?php if(($filters['type_code']??'')==='PAYROLL_CONCERN'):?><div class="field"><label for="queue-period">Cutoff</label><select name="payroll_period_id" id="queue-period"><option value="">All periods</option><?php foreach(db()->query('SELECT id,code FROM payroll_cutoffs ORDER BY period_start DESC') as $row):?><option value="<?=(int)$row['id']?>" <?=(int)($filters['payroll_period_id']??0)===(int)$row['id']?'selected':''?>><?=e($row['code'])?></option><?php endforeach;?></select></div><?php endif;?><button class="btn">Filter queue</button></div></form>
 <?php endif;?><section class="panel ticket-scroll"><table class="tbl"><thead><tr><th>Request</th><th>Employee</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($result['rows'] as $t):?><tr><td><?=e($t['ticket_no'].' · '.$t['type_name'])?><?php if($mode==='feed'):$payload=json_decode($t['payload_json'],true);?><p><?=e($payload['title']??'')?></p><?php endif;?></td><td><?=e($t['first_name'].' '.$t['last_name'])?></td><td><?=e(TicketTypes::label($t['status'],$t['type_code']))?></td><td><a class="btn" href="<?=url('hr-request',['id'=>$t['id']])?>">Open</a></td></tr><?php endforeach;if(!$result['rows']):?><tr><td colspan="4">No requests found.</td></tr><?php endif;?></tbody></table></section>
 <nav>Page <?=(int)$result['page']?> <?php foreach(['Previous'=>-1,'Next'=>1] as $label=>$delta):if(($delta<0&&$result['page']>1)||($delta>0&&$result['has_next'])):?><a class="btn" href="<?=e(url($mode==='accounting'?'hr-accounting-requests':($mode==='feed'?'hr-incidents':'hr-service-requests'),array_replace($filters,['p'=>$result['page']+$delta])))?>"><?=e($label)?></a><?php endif;endforeach;?></nav>
 <?php render_portal_footer();
}
/** "Oct 25, 2026 3:04 PM" for the detail page; empty text when the value is missing. */
function ticket_when(?string $value):string { return $value ? date('M j, Y g:i A',strtotime($value)) : ''; }
/** Status pill tone. The label always carries the meaning; colour only reinforces it. */
function ticket_tone(string $status):string { return match($status){'COMPLETED','RESOLVED'=>'green','REJECTED','CANCELLED','DISMISSED'=>'red','RETURNED'=>'amber','IN_PROGRESS','UNDER_REVIEW'=>'blue',default=>'gray'}; }
/** Relative time for a tooltip ("5 minutes ago"); empty when the value is missing. */
function ticket_ago(?string $value):string {
 if(!$value)return '';$diff=time()-(int)strtotime($value);if($diff<0)return 'just now';
 foreach([['year',31536000],['month',2592000],['day',86400],['hour',3600],['minute',60]] as [$unit,$secs])if($diff>=$secs){$n=intdiv($diff,$secs);return $n.' '.$unit.($n>1?'s':'').' ago';}
 return 'just now';
}
/** A readable date in a <time> element with the relative time as tooltip. Escaped HTML, safe to echo. */
function ticket_time(?string $value):string {
 if(!$value)return '';return '<time datetime="'.e(date('c',(int)strtotime($value))).'" title="'.e(ticket_ago($value)).'">'.e(ticket_when($value)).'</time>';
}
/** Status chip with the human label ("Pending", "Served"); never the raw status code. Escaped HTML. */
function ticket_status_chip(string $status,string $code=''):string {
 return '<span class="tk-chip is-'.e(ticket_tone($status)).'">'.e(TicketTypes::label($status,$code)).'</span>';
}
/** Label and tone for an approval step status (PENDING/APPROVED/REJECTED/RETURNED/SKIPPED). */
function ticket_step_label(string $status):string { return match($status){'PENDING'=>'Waiting','APPROVED'=>'Approved','REJECTED'=>'Rejected','RETURNED'=>'Returned','SKIPPED'=>'Skipped',default=>stage_label($status)}; }
function ticket_step_tone(string $status):string { return match($status){'APPROVED'=>'green','REJECTED'=>'red','RETURNED'=>'amber','PENDING'=>'blue',default=>'gray'}; }
/** Role shown on an approval step ("HR review", "Accounting follow-up"). */
function ticket_step_role(string $type):string { return match($type){'HR'=>'HR review','ACCOUNTING'=>'Accounting follow-up','MANAGER'=>'Manager','ADL'=>'ADL',default=>stage_label($type)}; }
/** Round avatar with up to two initials; the name is read from the surrounding text. Escaped HTML. */
function ticket_avatar(string $name):string { return '<span class="tk-avatar" aria-hidden="true">'.e(initials($name)?:'?').'</span>'; }
/** Shared empty state: one line and an optional hint. Escaped HTML. */
function ticket_empty(string $text,string $hint=''):string { return '<div class="tk-empty"><p>'.e($text).'</p>'.($hint!==''?'<small>'.e($hint).'</small>':'').'</div>'; }
/** Label/value grid. Plain values are escaped here; pass ['html'=>...] only for output of the helpers above. */
function ticket_kv(array $pairs):string {
 $o='<dl class="tk-kv">';foreach($pairs as $label=>$value)$o.='<div><dt>'.e((string)$label).'</dt><dd>'.(is_array($value)?(string)($value['html']??''):e((string)$value)).'</dd></div>';
 return $o.'</dl>';
}
/** Vertical approval timeline: avatar, role, name, status chip and date per step. Escaped HTML.
 *  Step remarks and the acting HR user's name are the HR/approver response, which view-only users must not see, so $detail must be true only where the caller already shows them. */
function ticket_timeline(array $steps,bool $detail=false):string {
 if(!$steps)return ticket_empty('The approval chain is set when the request is submitted.');
 $o='<ol class="tk-timeline">';
 foreach($steps as $s){
  $type=(string)$s['approver_type'];$name=(string)($s['approver_name']?:(($detail&&($s['actor_name']??''))?:($type==='ACCOUNTING'?'Accounting follow-up':'Authorized HR reviewer')));$st=(string)$s['status'];
  $o.='<li class="tk-tl-item is-'.e(ticket_step_tone($st)).'">'.ticket_avatar($name).'<div class="tk-tl-body"><p class="tk-tl-role">'.e(ticket_step_role($type)).'</p><p class="tk-tl-name">'.e($name).' <span class="tk-chip is-'.e(ticket_step_tone($st)).'">'.e(ticket_step_label($st)).'</span></p>';
  if(!empty($s['acted_at']))$o.='<p class="tk-tl-when">'.ticket_time((string)$s['acted_at']).'</p>';
  if($detail&&!empty($s['remarks']))$o.='<p class="ticket-text tk-tl-remarks">'.e((string)$s['remarks']).'</p>';
  $o.='</div></li>';
 }
 return $o.'</ol>';
}
/** Filed -> Pending -> In Progress -> Served/Cancelled. Done steps show a tick, a step a Served request never went through shows a dash, the current one is marked for screen readers. */
function ticket_stepper(array $t):void {
 $status=$t['status'];$code=$t['type_code'];$reached=['PENDING_HR'=>false,'IN_PROGRESS'=>false];
 foreach($t['events'] as $ev)if(isset($reached[$ev['to_status']??'']))$reached[$ev['to_status']]=true;
 $terminal=in_array($status,['COMPLETED','CANCELLED','REJECTED'],true);
 $labels=['Filed'];$keys=['FILED'];
 $labels[]=TicketTypes::label('PENDING_HR',$code);$keys[]='PENDING_HR';
 if($code!=='NETSUITE_CONCERN'){$labels[]='In Progress';$keys[]='IN_PROGRESS';}
 $labels[]=match($status){'CANCELLED'=>'Cancelled','REJECTED'=>'Rejected',default=>TicketTypes::label('COMPLETED',$code)};$keys[]='END';
 $last=count($keys)-1;$current=0;
 if($status==='PENDING_HR')$current=array_search('PENDING_HR',$keys,true);
 if($status==='IN_PROGRESS')$current=array_search('IN_PROGRESS',$keys,true);
 echo '<nav class="tk-steps-wrap" aria-label="Request progress"><ol class="tk-steps">';
 foreach($keys as $i=>$key){
  if($status==='DRAFT')$state='todo';
  elseif($i===$last)$state=$terminal?($status==='COMPLETED'?'done':'stop'):'todo';
  elseif($terminal)$state=($key==='FILED'||!empty($reached[$key]))?'done':($status==='COMPLETED'?'skipped':'todo');
  else $state=$i<$current?'done':($i===$current?($status==='RETURNED'?'warn':'current'):'todo');
  $mark=match($state){'done'=>'✓','stop'=>'✕','warn'=>'!','skipped'=>'–',default=>(string)($i+1)};
  $text=match($state){'done'=>'completed','stop'=>'ended here','warn'=>'returned for changes','skipped'=>'skipped, not needed','current'=>'current step',default=>'not reached yet'};
  echo '<li class="tk-step is-'.$state.'"'.($state==='current'||$state==='warn'||$state==='stop'?' aria-current="step"':'').'><span class="tk-dot" aria-hidden="true">'.$mark.'</span><span class="tk-step-label">'.e($labels[$i]).'</span><span class="tk-sr"> ('.$text.')</span></li>';
 }
 echo '</ol></nav>';
}
/** The remarks HR (or an approver) wrote on a status change. Requester and HR only; the query path already enforces that. */
function ticket_response_card(array $t):void {
 $hr=TicketRepository::isHr();$own=(int)$t['requester_user_id']===TicketRepository::uid();
 if(!empty($t['viewer_only']) || ($t['type_code']!=='INCIDENT_REPORT' && !$hr && !$own))return;
 $items=$t['responses'];$latest=$items?end($items):null;$title=$latest&&$latest['who']==='Approver'?'Approver response':'HR response';
 echo '<section class="tk-response" aria-labelledby="tk-response-h"><h2 id="tk-response-h">'.e($title).'</h2>';
 if($latest){
  echo '<p class="tk-response-by"><strong>'.e($latest['who']).'</strong>'.($hr?' · '.e($latest['actor_name']):'').' · '.e(ticket_when($latest['at'])).' · <span class="tk-chip is-'.e(ticket_tone((string)$latest['to_status'])).'">'.e(TicketTypes::label((string)$latest['to_status'],$t['type_code'])).'</span></p><p class="ticket-text tk-response-text">'.e($latest['remarks']).'</p>';
  if($hr)echo '<p class="tk-hint">The employee can read this response.</p>';
  if(count($items)>1){echo '<details class="tk-earlier"><summary>Earlier responses ('.(count($items)-1).')</summary>';
   foreach(array_reverse(array_slice($items,0,-1)) as $r)echo '<div class="tk-earlier-item"><p class="tk-response-by"><strong>'.e($r['who']).'</strong>'.($hr?' · '.e($r['actor_name']):'').' · '.e(ticket_when($r['at'])).' · '.e(TicketTypes::label((string)$r['to_status'],$t['type_code'])).'</p><p class="ticket-text">'.e($r['remarks']).'</p></div>';
   echo '</details>';}
 }elseif(in_array($t['status'],['IN_PROGRESS','COMPLETED','CANCELLED','REJECTED','RETURNED'],true)){
  echo '<p class="tk-hint">No remarks were added with this update.</p>';
 }else echo '<p class="tk-hint">No response yet. Remarks from HR appear here as soon as the request is updated.</p>';
 echo '</section>';
}
/** HR only: notes that never leave HR. Loaded by TicketRepository::detail() for HR reviewers alone. */
function ticket_internal_notes(array $t):void {
 if(!TicketRepository::isHr() || !empty($t['viewer_only']) || !$t['hr_notes'])return;
 echo '<section class="tk-internal" aria-labelledby="tk-internal-h"><h2 id="tk-internal-h">Internal notes <span class="badge amber">HR only</span></h2><p class="tk-hint">Never shown to the employee, viewers, approvers or Accounting.</p>';
 foreach($t['hr_notes'] as $n)echo '<article class="tk-note"><p class="tk-response-by"><strong>'.e($n['author_name']).'</strong> · '.e(ticket_when($n['created_at'])).'</p><p class="ticket-text">'.e($n['note']).'</p></article>';
 echo '</section>';
}
function ticket_service_detail(array $t):void {
 $p=TicketService::permissions($t);if($t['type_code']==='NETSUITE_CONCERN'&&$t['filed_at']!==null)$p['cancel']=false;ticket_header('hr-requests-my',$t['ticket_no']);page_head('HR Requests / '.$t['type_name'],$t['ticket_no']);
 $definition=TicketTypes::definition($t['type_code']);$hrActor=TicketRepository::isHr() && (int)$t['requester_user_id']!==TicketRepository::uid() && empty($t['viewer_only']);
 $label=TicketTypes::label($t['status'],$t['type_code']);
 ?>
 <section class="tk-head" aria-label="Request summary"><div class="tk-head-main"><span class="tk-eyebrow"><?=e($t['type_name'])?></span><p class="tk-no"><?=e($t['ticket_no'])?></p>
  <dl class="tk-dates"><div><dt>Filed</dt><dd><?=e($t['filed_at']?ticket_when($t['filed_at']):'Not filed yet (draft)')?></dd></div><div><dt>Last update</dt><dd><?=e(ticket_when($t['updated_at']??$t['created_at']))?></dd></div><div><dt>Requested by</dt><dd><?=e($t['payload']['employee_name'])?></dd></div></dl></div>
  <span class="tk-pill is-<?=e(ticket_tone($t['status']))?>"><span class="tk-sr">Status: </span><?=e($label)?></span></section>
 <?php ticket_stepper($t);
 if($t['type_code']==='MANPOWER_REQUEST'){$q=db()->prepare('SELECT date_served FROM ticket_manpower_requests WHERE ticket_id=?');$q->execute([$t['id']]);$served=$q->fetchColumn();if($served)echo '<p>Date served: '.e($served).'</p>';}
 ticket_response_card($t);ticket_internal_notes($t);
 if(in_array($t['type_code'],['ATM_REQUEST','ID_REQUEST'],true)):
 $q=db()->prepare('SELECT COUNT(*) FROM tickets WHERE requester_user_id=? AND ticket_type_id=? AND id<>? AND status IN ("SUBMITTED","PENDING_APPROVAL","PENDING_HR","IN_PROGRESS","RETURNED")');$q->execute([$t['requester_user_id'],$t['ticket_type_id'],$t['id']]);if($q->fetchColumn()):?><div class="alert">Another open <?=e($t['type_name'])?> exists. This request is allowed; please check whether both are needed.</div><?php endif;endif;?>
 <section class="panel ticket-panel"><div class="panel-body"><h2>Request details</h2><dl class="tk-fields"><?php foreach($definition['fields'] as $key=>$field):?><div><dt><?=e($field['label'])?></dt><dd class="ticket-text"><?=e(ticket_service_value($field,$t['payload'][$key]??''))?></dd></div><?php endforeach;?></dl></div></section>
 <?php if($p['edit']):$old=array_replace($t['payload'],$_SESSION['core_ticket_edit'][$t['id']]??[]);?>
 <form method="post" enctype="multipart/form-data" class="panel ticket-panel"><?=csrf_field()?><input type="hidden" name="action" value="core_ticket_edit"><input type="hidden" name="ticket_id" value="<?=(int)$t['id']?>"><input type="hidden" name="version" value="<?=(int)$t['version']?>"><input type="hidden" name="ticket_type_id" value="<?=(int)$t['ticket_type_id']?>"><div class="panel-body"><?php ticket_service_fields($t['type_code'],$old);ticket_upload_input('edit-attachments');?><button class="btn" name="intent" value="DRAFT">Save changes</button><button class="btn primary" name="intent" value="SUBMIT">Submit for review</button></div></form><?php endif;?>
 <?php if($p['approve']||$p['hr']||$p['return_reject']||$p['cancel']):$old=$_SESSION['core_ticket_action'][$t['id']]??[];?>
 <form method="post" class="panel ticket-panel tk-decision"><?=csrf_field()?><input type="hidden" name="action" value="core_ticket_act"><input type="hidden" name="ticket_id" value="<?=(int)$t['id']?>"><input type="hidden" name="version" value="<?=(int)$t['version']?>"><div class="panel-body"><h2>Update this request</h2>
 <div class="field tk-field-public"><label for="decision-remarks"><?=$hrActor?'Response to the employee':'Review remarks'?> <span class="badge blue"><?=$hrActor?'Visible to the employee':'Employee can read this'?></span></label><textarea id="decision-remarks" name="remarks" rows="3" maxlength="5000"><?=e($old['remarks']??'')?></textarea><small>Remarks are required for return or rejection.<?=$hrActor?' Whatever you write here is shown to the employee on their request.':''?></small></div>
 <?php if($hrActor):?><div class="field tk-field-internal"><label for="internal-note"><span aria-hidden="true">🔒</span> Internal note <span class="badge amber">HR only</span></label><textarea id="internal-note" name="internal_note" rows="2" maxlength="2000"><?=e($old['internal_note']??'')?></textarea><small>Never shown to the employee, viewers, approvers or Accounting. Optional.</small></div><?php endif;?>
 <?php if($t['type_code']==='BRANCH_TRANSFER'&&$p['hr']):
 $q=db()->prepare('SELECT b.code branch_code,b.site_type,s.workplace FROM branches b LEFT JOIN payroll_site_settings s ON s.branch_id=b.id WHERE b.id=?');$q->execute([$t['payload']['to_branch']]);$destination=$q->fetch()?:[];$kind=EmployeeApproverAssignmentService::kind($destination+['site_type'=>null,'branch_code'=>'','workplace'=>null]);
 ?><p>Company label only: <?=e($t['payload']['company'])?>. OFJI means Ocampo's. Legal entity remains PMBSI.</p><p>Effective: <?=e($t['payload']['effective_date'])?>. Future transfers remain In Progress.</p><div class="field"><label for="new-approver">Destination approver (manual HR assignment)</label><select name="new_approver_user_id" id="new-approver"><option value="">Choose <?=e($kind)?></option><?php if(Auth::can('tickets.approvers.manage'))foreach(EmployeeApproverAssignmentService::candidates($kind) as $a):?><option value="<?=(int)$a['id']?>"><?=e($a['full_name'])?></option><?php endforeach;?></select><small>Select the approver before completing the transfer on its effective date.</small></div><?php endif;?><div class="ticket-actions">
 <?php if($p['approve']):?><button class="btn primary" name="decision" value="APPROVE">Approve and send to HR</button><?php endif;if($p['hr']):if($t['status']==='PENDING_HR' && $t['type_code']!=='NETSUITE_CONCERN'):?><button class="btn" name="decision" value="START">In Progress</button><?php endif;?><?php if($t['type_code']==='COE_REQUEST'):?><a class="btn primary" href="<?=e(url('hr-coe-preview',['id'=>$t['id']]))?>">Preview &amp; Served</a><?php else:?><button class="btn primary" name="decision" value="COMPLETE"><?=$t['type_code']==='NETSUITE_CONCERN'?'Complete':'Served'?></button><?php endif;?><?php endif;if($p['return_reject']):?><button class="btn" name="decision" value="RETURN">Return</button><button class="btn" name="decision" value="REJECT">Reject</button><?php endif;if($p['cancel']):?><button class="btn" name="decision" value="CANCEL" onclick="return confirm('Cancel this HR request?')">Cancel</button><?php endif;?></div></div></form>
 <?php endif;ticket_netsuite_panel($t);if($t['type_code']==='OIC_AUTHORIZATION')TicketOicAuthorization::panel($t);if($t['type_code']==='COE_REQUEST')ticket_coe_panel($t);
 if($t['type_code']!=='INCIDENT_REPORT'):?><details class="panel ticket-panel tk-collapse"><summary>Approval chain</summary><div class="panel-body"><?=ticket_timeline($t['steps'])?></div></details>
 <?php endif;ticket_service_evidence($t,$p);render_portal_footer();
}

function ticket_service_evidence(array $t,array $p):void { $uid=TicketRepository::uid(); ?>
    <section class="panel ticket-panel"><div class="panel-body"><h2>Attachments</h2><?php foreach($t['attachments'] as $file):?><p><a href="<?=url('hr-request-attachment',['id'=>$file['id']])?>"><?=e($file['original_name'])?></a> · <?=number_format($file['file_size']/1024,1)?> KB</p><?php endforeach;if(!empty($t['viewer_only'])):?><p>Attachments are not shown with view-only access.</p><?php elseif(!$t['attachments']):?><p>No attachments.</p><?php endif;
      if($p['attach'] && !$p['edit']):?><form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="core_ticket_attach"><input type="hidden" name="ticket_id" value="<?=(int)$t['id']?>"><?php ticket_upload_input('detail-attachments');?><button class="btn">Add attachments</button></form><?php endif;?></div></section>
    <section class="panel ticket-panel"><div class="panel-body"><h2>Comments</h2><div class="tk-chat" role="log" aria-label="Comments"><?php foreach($t['comments'] as $c):$mine=(int)$c['author_user_id']===$uid;?><article class="tk-msg <?=$mine?'is-mine':'is-theirs'?>"><div class="tk-bubble"><p class="tk-msg-by"><strong><?=e($mine?'You':$c['author_name'])?></strong> <small><?=ticket_time($c['created_at'])?><?=($c['visibility']??'ALL')==='STAFF_ONLY'?' · Staff only':''?></small></p><p class="ticket-text"><?=e($c['body'])?></p></div></article><?php endforeach;if(!$t['comments'])echo ticket_empty('No comments yet.');?></div><?php if(!empty($t['viewer_only'])):?><p class="ticket-note">View-only access: you can read this request but not act on it, comment or attach files.</p><?php else:?><form method="post" class="tk-compose tk-composer"><?=csrf_field()?><input type="hidden" name="action" value="core_ticket_comment"><input type="hidden" name="ticket_id" value="<?=(int)$t['id']?>"><div class="field"><label for="comment-body">Add a comment</label><textarea name="body" id="comment-body" maxlength="3000" rows="3" required><?=e($_SESSION['core_ticket_comment'][$t['id']]??'')?></textarea></div><?php if($t['type_code']==='INCIDENT_REPORT'&&TicketRepository::isStaff($t)):?><div class="field"><label for="comment-visibility">Comment visibility</label><select id="comment-visibility" name="visibility"><option value="ALL">All ticket participants</option><option value="STAFF_ONLY">Staff only</option></select></div><?php endif;?><button class="btn">Post comment</button></form><?php endif;?></div></section>
    <details class="panel ticket-panel tk-collapse"><summary>History (<?=count($t['events'])?>)</summary><div class="panel-body"><ol class="ticket-history"><?php foreach($t['events'] as $ev):?><li><strong><?=e(stage_label($ev['event_type']))?></strong> · <?=e($ev['actor_name'])?> · <?=ticket_time($ev['created_at'])?><?php if($ev['to_status']):?> · <?=e(TicketTypes::label($ev['to_status'],$t['type_code']))?><?php endif;?></li><?php endforeach;?></ol></div></details>

<?php }
