<?php
declare(strict_types=1);

function employee_attendance_field_labels(): array
{
    return ['time_in'=>'Time In','lunch_out'=>'Break Out','lunch_in'=>'Break In','time_out'=>'Time Out'];
}

function employee_attendance_missing_names(array $day): array
{
    $missing=[];
    foreach(employee_attendance_field_labels() as $field=>$label) if(empty($day['values'][$field])) $missing[]=$label;
    return $missing;
}

function employee_attendance_cutoff_label(array $cutoff): string
{
    $start=new DateTimeImmutable($cutoff['period_start']); $end=new DateTimeImmutable($cutoff['period_end']);
    return $start->format($start->format('Y')===$end->format('Y')?'M j':'M j, Y').' – '.$end->format('M j, Y');
}

function employee_attendance_ticket_label(string $status): string
{
    return match($status) {
        'APPROVED','COMPLETED'=>'Approved', 'FOR_MANAGER_APPROVAL'=>'Waiting for Manager',
        'FOR_ADL_APPROVAL'=>'Waiting for ADL', 'RETURNED_FOR_REVISION'=>'Update request',
        'REJECTED'=>'Not approved', 'CANCELLED'=>'Cancelled', default=>'Waiting for approval',
    };
}

function employee_attendance_day_status(array $day): array
{
    if($day['before_hire']) return ['Check with HR','amber'];
    if(!$day['has_evidence']) return ['No logs yet','gray'];
    if($day['ambiguous']) return ['Check logs','amber'];
    if($day['incomplete']) return [$day['missing']?'Missing logs':'Check logs','amber'];
    if($day['ot_review']) return ['Check OT','amber'];
    return match($day['state']) {
        'COMPLETE'=>['Complete','green'], 'CORRECTED'=>['Updated','green'],
        'APPROVED_OB'=>['Approved OB','green'], 'APPROVED_LEAVE'=>['Approved leave','green'],
        'REST_DAY'=>['Rest day','gray'], 'ZERO_CREDIT'=>['Checked by Payroll','gray'],
        default=>['Check logs','amber'],
    };
}

function employee_attendance_application_note(array $ticket,array $day): string
{
    if(!$ticket['application_state']) return '';
    if($day['before_hire']) return 'Ask HR to check the request date.';
    return match($ticket['application_state']) {
        'APPLIED'=>'Attendance updated.',
        'WAITING_FOR_IMPORT'=>'Waiting for logs from Payroll.',
        'NEEDS_REVIEW'=>'Payroll needs to check this day.',
        default=>'Ask Payroll to check this request.',
    };
}

function employee_attendance_raw_table(array $logs): void
{
    employee_attendance_log_table(array_map(static fn($punch)=>['kind'=>'BIOMETRIC','date'=>substr($punch['punched_at'],0,10),'punch'=>$punch],$logs));
}

function employee_attendance_log_count(array $model): string
{
    $logs=count($model['logs']); $added=count($model['adjustments']??[]); $leaveDays=count($model['leave_days']); $obDays=count($model['ob_days']); $parts=[];
    if($logs) $parts[]=$logs.($logs===1?' log':' logs');
    if($added) $parts[]=$added.($added===1?' added log':' added logs');
    if($leaveDays) $parts[]=$leaveDays.($leaveDays===1?' leave day':' leave days');
    if($obDays) $parts[]=$obDays.($obDays===1?' OB day':' OB days');
    return implode(' · ',$parts);
}

function employee_attendance_log_table(array $entries): void
{ ?>
    <div class="payroll-table-scroll employee-biometric-scroll"><table class="phase3a-table employee-biometric-table">
      <thead><tr><th scope="col">Date</th><th scope="col">Time</th><th scope="col">Log</th><th scope="col"><span class="employee-attendance-sr-only">Log details</span></th></tr></thead>
      <tbody><?php foreach($entries as $entry):
        if($entry['kind']==='LEAVE'): ?>
        <tr><td><time datetime="<?=e($entry['date'])?>"><?=e(date('M j, Y',strtotime($entry['date'])))?></time></td>
          <td><span aria-label="No biometric time">—</span></td><td><span class="badge green">Leave</span></td>
          <td><details class="employee-biometric-source"><summary>Details<span class="employee-attendance-sr-only">: <?=e(date('M j, Y',strtotime($entry['date'])))?> approved leave</span></summary>
            <div><?php foreach($entry['leaves'] as $leave):?><span><?=e($leave['leave_type_name'])?> · Approved</span><small><?=e($leave['request_no'])?></small><?php endforeach;?>
              <?php if($entry['before_hire']):?><small>This date is before your hire date. Ask HR to check it.</small><?php elseif($entry['closed_unapplied']):?><small>This cutoff is closed. Ask Payroll to check this leave against the saved attendance.</small><?php endif;?>
            </div>
          </details></td>
        </tr>
        <?php elseif($entry['kind']==='ADJUSTMENT'):
          $hasTa=(bool)array_filter($entry['time_sources'],static fn($source)=>in_array($source['type_code'],['TA','PTA'],true));
          $hasOb=$entry['obs'] || (bool)array_filter($entry['time_sources'],static fn($source)=>in_array($source['type_code'],['OB','POB'],true));
          $timeSourceIds=array_map('intval',array_column($entry['time_sources'],'id')); ?>
        <tr><td><time datetime="<?=e($entry['date'])?>"><?=e(date('M j, Y',strtotime($entry['date'])))?></time></td>
          <td><time datetime="<?=e(str_replace(' ','T',$entry['punched_at']))?>"><?=e(date('g:i A',strtotime($entry['punched_at'])))?></time></td>
          <td><span class="employee-biometric-label"><?=e($entry['event'])?></span> <?php if($hasTa):?><span class="badge green">TA</span><?php endif;?> <?php if($hasOb):?><span class="badge green">OB</span><?php endif;?></td>
          <td><details class="employee-biometric-source"><summary>Details<span class="employee-attendance-sr-only">: <?=e(date('M j, Y g:i A',strtotime($entry['punched_at'])))?> approved <?=e(employee_attendance_field_labels()[$entry['field']])?></span></summary>
            <div><?php foreach($entry['time_sources'] as $source):?><span><?=e(employee_attendance_field_labels()[$entry['field']])?> from approved <?=e($source['type_code'])?></span><small><?=e($source['request_no'])?></small><?php endforeach;?>
              <?php if($entry['date']!==$entry['work_date']):?><small>Work date: <?=e(date('M j, Y',strtotime($entry['work_date'])))?></small><?php endif;?>
              <?php foreach($entry['obs'] as $ob):?><?php if(!in_array((int)$ob['id'],$timeSourceIds,true)):?><span>Approved <?=e($ob['type_code'])?></span><small><?=e($ob['request_no'])?></small><?php endif;?><?php if(!empty($ob['destination'])):?><small><?=e($ob['destination'])?></small><?php endif;?><?php endforeach;?>
              <small>This approved time fills a missing log. Your biometric logs are kept.</small>
              <?php if($entry['closed_unapplied']):?><small>This cutoff is closed. Ask Payroll to check the OB request against the saved attendance.</small>
              <?php elseif($entry['needs_review']):?><small>Payroll needs to check the other logs or requests for this day.</small><?php endif;?>
              <?php if(Auth::can('payroll.request_self')):?><small><a href="<?=url('payroll-request',['id'=>$entry['time_sources'][0]['id']])?>">View request</a></small><?php endif;?>
            </div>
          </details></td>
        </tr>
        <?php elseif($entry['kind']==='OB'): ?>
        <tr><td><time datetime="<?=e($entry['date'])?>"><?=e(date('M j, Y',strtotime($entry['date'])))?></time></td>
          <td><?php if($entry['time_out']):?><time datetime="<?=e(str_replace(' ','T',$entry['time_out']))?>"><?=e(date('g:i A',strtotime($entry['time_out'])))?></time><?php else:?><span aria-label="No approved Time Out added">—</span><?php endif;?></td>
          <td><?php if($entry['time_out']):?><span class="employee-biometric-label">OUT</span> <?php endif;?><span class="badge green">OB</span></td>
          <td><details class="employee-biometric-source"><summary>Details<span class="employee-attendance-sr-only">: <?=e(date('M j, Y',strtotime($entry['date'])))?> approved OB</span></summary>
            <div><?php foreach($entry['time_sources'] as $source):?><span>Time Out from approved <?=e($source['type_code'])?></span><small><?=e($source['request_no'])?></small><?php endforeach;?>
              <?php if($entry['date']!==$entry['work_date']):?><small>Work date: <?=e(date('M j, Y',strtotime($entry['work_date'])))?></small><?php endif;?>
              <?php foreach($entry['obs'] as $ob):?><span>Approved <?=e($ob['type_code'])?></span><small><?=e($ob['request_no'])?></small><?php if(!empty($ob['destination'])):?><small><?=e($ob['destination'])?></small><?php endif;?><?php endforeach;?>
              <?php if($entry['before_hire']):?><small>This date is before your hire date. Ask HR to check it.</small>
              <?php elseif($entry['closed_unapplied']):?><small>This cutoff is closed. Ask Payroll to check this OB against the saved attendance.</small>
              <?php elseif($entry['needs_review']):?><small>Payroll needs to check the other logs or requests for this day.</small>
              <?php elseif(!$entry['time_out']):?><small>Your original logs are kept. An OUT is added only when a missing Time Out has an approved time.</small><?php endif;?>
              <?php if(Auth::can('payroll.request_self')):?><small><a href="<?=url('payroll-request',['id'=>$entry['obs'][0]['id']])?>">View OB request</a></small><?php endif;?>
            </div>
          </details></td>
        </tr>
        <?php else: $punch=$entry['punch']; $knownLocation=!empty($punch['source_branch_id']); ?>
        <tr><td><time datetime="<?=e(substr($punch['punched_at'],0,10))?>"><?=e(date('M j, Y',strtotime($punch['punched_at'])))?></time></td>
          <td><time datetime="<?=e(str_replace(' ','T',$punch['punched_at']))?>"><?=e(date('g:i A',strtotime($punch['punched_at'])))?></time></td>
          <td><span class="employee-biometric-label"><?=e($punch['punch_status'])?></span></td>
          <td><details class="employee-biometric-source"><summary>Details<span class="employee-attendance-sr-only">: <?=e(date('M j, Y g:i A',strtotime($punch['punched_at'])))?> <?=e($punch['punch_status'])?></span></summary>
            <div><span><?=e($knownLocation?($punch['source_location']??'Location not in file'):'Location not in file')?></span><?php if(!empty($punch['source_file'])):?><small><?=e($punch['source_file'])?></small><?php endif;?></div>
          </details></td>
        </tr>
      <?php endif; endforeach;?></tbody>
    </table></div>
<?php }

function employee_attendance_actions(array $day,array $model): void
{
    if(!Auth::can('payroll.request_self')) return;
    if($day['state']==='APPROVED_LEAVE' && !$day['raw']) return;
    foreach(['TA'=>'File TA','OB'=>'File OB','OT'=>'File OT'] as $code=>$label) {
        if(!in_array($code,$model['types'],true)) continue;
        $ticket=$day['active'][$code]??null;
        if($ticket) {
            $returned=$ticket['status']==='RETURNED_FOR_REVISION';
            $target=$returned && $day['can_file']?url('employee-request-new',['revision'=>$ticket['id']]):url('payroll-request',['id'=>$ticket['id']]);
            echo '<a class="btn sm" href="'.$target.'">'.e(($returned && $day['can_file']?'Edit ':'View ').$ticket['type_code']).'</a>';
        } elseif($day['can_file']) {
            $primary=($code==='TA' && $day['incomplete']) || ($code==='OT' && $day['ot_review']);
            if($code==='TA' && $day['has_evidence'] && !$day['incomplete']) $label='Fix log (TA)';
            echo '<a class="btn sm'.($primary?' primary':'').'" href="'.url('employee-request-new',['date'=>$day['date'],'type'=>$code,'cutoff_id'=>$model['run']['id']]).'">'.e($label).'</a>';
        }
    }
}

function employee_attendance_times(array $day): void
{ ?>
    <div class="employee-attendance-times">
    <?php foreach(employee_attendance_field_labels() as $field=>$label):
        $value=$day['values'][$field]??null; $original=$day['row']['original_'.$field]??null;
        $adjusted=$value && $day['row'] && $value!==$original;
        $requiresPunches=!in_array($day['state'],['APPROVED_OB','APPROVED_LEAVE','ZERO_CREDIT'],true);
        $emptyLabel=$day['ambiguous']?'Check':($day['has_evidence']?($requiresPunches?'Missing':'No log'):'No log'); ?>
      <div class="employee-attendance-time <?=!$value && $day['has_evidence'] && $requiresPunches?'is-missing':''?>">
        <span><?=e($label)?></span><strong><?=$value?e(payroll_clock($value)):e($emptyLabel)?></strong>
        <?php if($value && substr($value,0,10)!==$day['date']):?><small><?=e(date('M j',strtotime($value)))?> · next day</small><?php endif;?>
        <?php if($adjusted):?><small class="employee-attendance-adjusted">Updated</small><?php endif;?>
      </div>
    <?php endforeach;?>
    </div>
<?php }

function employee_attendance_review_day(array $day,array $model): void
{
    $missing=employee_attendance_missing_names($day); ?>
    <div class="employee-attendance-review-body">
      <?php if($day['leaves']):?><div class="employee-attendance-tickets"><strong>Approved leave</strong>
        <?php foreach($day['leaves'] as $leave):?><div><span><?=e($leave['leave_type_name'])?></span><span class="badge green">Approved</span><small><?=e($leave['request_no'])?></small></div><?php endforeach;?>
        <?php if($model['closed'] && $day['state']!=='APPROVED_LEAVE'):?><p class="small muted">This cutoff is closed. Ask Payroll to check this leave against the saved attendance.</p><?php endif;?>
      </div><?php endif;?>
      <?php if($day['has_evidence'] && (!$day['leaves'] || $day['raw'] || array_filter($day['values']))):?><p class="employee-attendance-detail-heading">Attendance for this day</p><?php employee_attendance_times($day); endif;?>
      <?php if($day['before_hire']):?><p class="employee-attendance-note">This date is before your hire date. Ask HR to check the log or request date.</p>
      <?php elseif(!$day['has_evidence']):?><p class="employee-attendance-note">No logs yet. If you worked on this day, file TA or OB. Ask Payroll if the file has been uploaded.</p>
      <?php elseif($day['ambiguous']):?><p class="employee-attendance-note">Check the IN/OUT logs below before choosing which time to fix. For TA, enter only the times that need fixing.</p>
      <?php elseif($day['incomplete'] && $missing):?><p class="employee-attendance-note">Missing: <strong><?=e(implode(', ',$missing))?></strong>. File TA with the actual time.</p>
      <?php elseif($day['incomplete']):?><p class="employee-attendance-note">Payroll needs to check the logs and requests for this day.</p>
      <?php endif;?>
      <?php if($day['row'] && array_filter($day['issues'],static fn($issue)=>str_starts_with($issue,'CORRECTION_CONFLICT_'))):?><p class="employee-attendance-note">A time in your request differs from the biometric log. Ask Payroll to check it.</p><?php endif;?>
      <?php $scheduleNotes=[]; foreach(['LATE'=>'Your Time In is late.','UNDERTIME'=>'Your Time Out is before your shift ends.','EXCESS_BREAK'=>'Your break is longer than the allowed break time.'] as $issue=>$note) if(in_array($issue,$day['issues'],true)) $scheduleNotes[]=$note; if($scheduleNotes):?><p class="employee-attendance-note"><?=e(implode(' ',$scheduleNotes))?></p><?php endif;?>
      <?php if($day['other_site']):?><p class="employee-attendance-note">You have a log at another location. This alone does not need an OB request.</p><?php endif;?>
      <?php if($day['ot_review']):?><p class="employee-attendance-ot">You have <strong><?=(int)$day['ot_minutes']?> minutes</strong> outside your schedule. File OT if you were allowed to work overtime. Your OT request still needs approval.</p><?php endif;?>
      <?php if($day['tickets']):?><div class="employee-attendance-tickets"><strong>Requests for this day</strong>
        <?php foreach($day['tickets'] as $ticket): $note=employee_attendance_application_note($ticket,$day); ?>
          <div><a href="<?=url('payroll-request',['id'=>$ticket['id']])?>"><?=e($ticket['type_code'].' · '.$ticket['request_no'])?></a><span class="badge <?=in_array($ticket['status'],['APPROVED','COMPLETED'],true)?'green':'gray'?>"><?=e(employee_attendance_ticket_label($ticket['status']))?></span><?php if($note):?><small><?=e($note)?></small><?php endif;?></div>
        <?php endforeach;?></div>
      <?php endif;?>
      <?php if(Auth::can('payroll.request_self') && $day['can_file'] && !($day['state']==='APPROVED_LEAVE' && !$day['raw'])):?><p class="employee-attendance-action-help">TA: missing or wrong log · OB: official business · OT: overtime</p><?php endif;?>
      <div class="employee-attendance-actions"><?php employee_attendance_actions($day,$model);?></div>
      <?php if($day['raw']):?><details class="employee-attendance-raw"><summary>Biometric Logs (<?=count($day['raw'])?>)</summary><?php employee_attendance_raw_table($day['raw']);?></details><?php endif;?>
    </div>
<?php }

function employee_attendance_page(array $cutoffs,int $cutoffId,array $model,string $filter): void
{
    $run=$model['run']; $filters=[''=>'All days','INCOMPLETE'=>'Logs to check','OT'=>'Check OT','TICKETS'=>'With requests','NO_LOGS'=>'No logs yet'];
    if(!array_key_exists($filter,$filters)) $filter='';
    $tab=(string)($_GET['tab']??($filter!==''?'review':'logs'));
    if(!in_array($tab,['logs','review'],true)) $tab='logs';
    $selectedDate=(string)($_GET['day']??'');
    $days=array_values(array_filter($model['days'],static fn($day)=>match($filter) {
        'INCOMPLETE'=>$day['incomplete'] || $day['before_hire'], 'OT'=>$day['ot_review'], 'TICKETS'=>!empty($day['tickets']) || !empty($day['leaves']), 'NO_LOGS'=>!$day['has_evidence'], default=>true,
    }));
    render_portal_header('employee','employee-attendance','My Attendance');
    page_head('Employee / Attendance','My Attendance',Auth::can('payroll.request_self')?'<a class="btn" href="'.url('employee-requests').'">My Requests</a>':''); ?>
    <form method="get" class="payroll-toolbar employee-attendance-cutoff">
      <input type="hidden" name="page" value="employee-attendance"><input type="hidden" name="tab" value="<?=e($tab)?>">
      <?php if($tab==='review' && $filter!==''):?><input type="hidden" name="filter" value="<?=e($filter)?>"><?php endif;?>
      <div class="field"><label for="employee-attendance-cutoff">Cutoff</label><select id="employee-attendance-cutoff" name="cutoff_id">
        <?php foreach($cutoffs as $cutoff):?><option value="<?=(int)$cutoff['id']?>" <?=(int)$cutoff['id']===$cutoffId?'selected':''?>><?=e(employee_attendance_cutoff_label($cutoff))?></option><?php endforeach;?>
      </select></div><button class="btn" type="submit">View</button>
    </form>
    <nav class="employee-attendance-tabs" aria-label="Attendance views">
      <a href="<?=url('employee-attendance',['cutoff_id'=>$cutoffId,'tab'=>'logs'])?>" class="<?=$tab==='logs'?'is-active':''?>" <?=$tab==='logs'?'aria-current="page"':''?>>My Logs</a>
      <a href="<?=url('employee-attendance',['cutoff_id'=>$cutoffId,'tab'=>'review'])?>" class="<?=$tab==='review'?'is-active':''?>" <?=$tab==='review'?'aria-current="page"':''?>>Check Attendance</a>
    </nav>
    <?php if($tab==='logs'):?>
      <section class="panel employee-biometric-panel"><div class="panel-head"><div><h2>My Logs</h2><p>Your biometric logs and approved TA, OB and leave for this cutoff.</p></div><?php if($model['entries']):?><span class="badge gray"><?=e(employee_attendance_log_count($model))?></span><?php endif;?></div>
        <?php if($model['entries']): employee_attendance_log_table($model['entries']); else:?>
          <div class="empty"><p>No logs or approved TA, OB or leave for this cutoff yet.</p><p>Choose another cutoff or ask Payroll if the file has been uploaded.</p>
          <?php if($model['latest_entry_cutoff'] && (int)$model['latest_entry_cutoff']['id']!==$cutoffId):?><a class="btn sm" href="<?=url('employee-attendance',['cutoff_id'=>$model['latest_entry_cutoff']['id'],'tab'=>'logs'])?>">View my logs: <?=e(employee_attendance_cutoff_label($model['latest_entry_cutoff']))?></a><?php endif;?></div>
        <?php endif;?>
      </section>
      <p class="employee-attendance-simple-help">Missing a log or need to file a request? Open <a href="<?=url('employee-attendance',['cutoff_id'=>$cutoffId,'tab'=>'review'])?>">Check Attendance</a>.</p>
    <?php else:?>
      <p class="employee-attendance-simple-help">Choose a day to view logs and file TA, OB or OT.</p>
      <?php if($model['closed']):?><div class="alert amber">This cutoff is closed. You can view logs and requests, but cannot file or edit a request.</div>
      <?php elseif($model['deadline_passed']):?><div class="alert amber">The request deadline has passed. Ask Payroll if you still need to file.</div>
      <?php elseif($run['ticket_deadline']):?><p class="employee-attendance-simple-help">Request deadline: <strong><?=e(date('M j, Y · g:i A',strtotime($run['ticket_deadline'])))?></strong></p><?php endif;?>
      <form method="get" class="employee-attendance-review-filter">
        <input type="hidden" name="page" value="employee-attendance"><input type="hidden" name="cutoff_id" value="<?=$cutoffId?>"><input type="hidden" name="tab" value="review">
        <div class="field"><label for="employee-attendance-filter">Show</label><select id="employee-attendance-filter" name="filter"><?php foreach($filters as $key=>$label):?><option value="<?=e($key)?>" <?=$filter===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div><button class="btn" type="submit">View</button>
      </form>
      <?php if(!$days):?><section class="panel"><div class="empty">No days found. Choose All days or another cutoff.</div></section><?php endif;?>
      <div class="employee-attendance-review-list">
      <?php foreach($days as $day): [$badge,$tone]=employee_attendance_day_status($day); $requestCount=count($day['tickets'])+count($day['leaves']); ?>
        <details class="panel employee-attendance-review-day" id="attendance-<?=e($day['date'])?>" <?=$selectedDate===$day['date']?'open':''?>>
          <summary><span class="employee-attendance-review-date"><time datetime="<?=e($day['date'])?>"><?=e(date('D, M j',strtotime($day['date'])))?></time><small><?=count($day['raw'])?> logs<?=$requestCount?' · '.$requestCount.($requestCount===1?' request':' requests'):''?></small></span><span class="badge <?=$tone?>"><?=e($badge)?></span><span class="employee-attendance-review-toggle"><span class="when-closed">View</span><span class="when-open">Close</span><span class="employee-attendance-sr-only"> attendance</span></span></summary>
          <?php employee_attendance_review_day($day,$model);?>
        </details>
      <?php endforeach;?></div>
    <?php endif;?>
    <?php render_portal_footer();
}

function employee_attendance_home(?array $model): void
{
    $target=$model && !$model['entries'] && $model['latest_entry_cutoff']?$model['latest_entry_cutoff']:($model['run']??null); ?>
    <section class="panel today-card"><div class="panel-head"><div><h2>My Logs</h2><p><?=$model?e(employee_attendance_cutoff_label($model['run'])):'Logs and approved requests'?></p></div><a class="panel-link" href="<?=url('employee-attendance',$target?['cutoff_id'=>$target['id'],'tab'=>'logs']:[])?>">View logs</a></div><div class="panel-body">
    <?php if(!$model):?><p class="small muted">HR must link your account to your employee record before you can see your logs and approved leave.</p><?php else:?>
      <p class="employee-attendance-note"><?=$model['entries']?e(employee_attendance_log_count($model)).' in this cutoff.':'No logs or approved TA, OB or leave for this cutoff yet.'?></p>
      <?php if(!$model['entries'] && $model['latest_entry_cutoff']):?><p class="small muted">You have logs or approved requests for <?=e(employee_attendance_cutoff_label($model['latest_entry_cutoff']))?>. Open View logs.</p><?php endif;?>
      <p class="small muted">Open Check Attendance if you need to file a request.</p>
    <?php endif;?></div></section>
<?php }

function employee_attendance_request_context(array $model,string $date): void
{
    $day=null; foreach($model['days'] as $item) if($item['date']===$date) { $day=$item; break; }
    if(!$day) return; ?>
    <section class="panel payroll-section" id="employee-attendance-request-context" data-date="<?=e($date)?>">
      <div class="panel-head"><div><h2>Your attendance: <?=e(date('M j, Y',strtotime($date)))?></h2><p>Fill only missing logs. Your existing biometric times are kept.</p></div><a class="panel-link" href="<?=url('employee-attendance',['cutoff_id'=>$model['run']['id'],'tab'=>'review','day'=>$date]).'#attendance-'.e($date)?>">Back to this day</a></div>
      <div class="panel-body"><?php employee_attendance_times($day);?>
        <?php if($day['leaves']):?><p class="small muted">Approved leave: <?=e(implode(', ',array_unique(array_column($day['leaves'],'leave_type_name'))))?>.</p><?php endif;?>
        <?php if($day['ambiguous']):?><p class="small muted">Check the actual IN/OUT logs before choosing which time to fix.</p><?php elseif($day['missing'] && $day['has_evidence'] && !in_array($day['state'],['APPROVED_LEAVE','APPROVED_OB','ZERO_CREDIT'],true)):?><p class="small muted">Missing: <?=e(implode(', ',employee_attendance_missing_names($day)))?>.</p><?php endif;?>
        <?php if($day['other_site']):?><p class="small muted">You have a log at another location. This alone does not need an OB request.</p><?php endif;?>
        <?php if($day['tickets']):?><p class="small muted">Requests: <?php foreach($day['tickets'] as $ticket):?><a href="<?=url('payroll-request',['id'=>$ticket['id']])?>"><?=e($ticket['type_code'].' · '.employee_attendance_ticket_label($ticket['status']))?></a> <?php endforeach;?></p><?php endif;?>
      </div>
    </section>
<?php }
