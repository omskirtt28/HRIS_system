<?php
declare(strict_types=1);
require_once __DIR__.'/HrDashboard.php';
require_once __DIR__.'/TicketQueueViews.php';

function hd_greeting():string { $h=(int)date('G');return $h<12?'Good morning':($h<18?'Good afternoon':'Good evening'); }

function hd_kpi(string $label,int $value,string $href,string $icon,string $foot,?array $delta):void
{ ?>
    <a class="metric-card hd-kpi" href="<?=e($href)?>"><div class="metric-top"><div class="metric-label"><?=e($label)?></div><div class="metric-icon"><?=icon_svg($icon)?></div></div>
      <div class="metric-value"><?=number_format($value)?></div><?php if($foot!==''):?><div class="metric-foot"><span><?=e($foot)?></span></div><?php endif;?>
      <?php if($delta):?><div class="hd-delta"><?=$delta['value']>0?'▲':($delta['value']<0?'▼':'●')?> <?=e($delta['text'])?></div><?php endif;?></a>
<?php }

function hd_strip_card(string $label,string $value,string $sub,string $href,string $icon,string $tone=''):void
{ ?><a class="hd-card <?=e($tone)?>" href="<?=e($href)?>"><span class="attention-icon <?=$tone==='is-hot'?'danger':($tone==='is-warm'?'warn':'info')?>"><?=icon_svg($icon)?></span><span><strong><?=e($value)?></strong><span class="t"><?=e($label)?><?=$sub!==''?' · '.e($sub):''?></span></span></a><?php }

function hr_dashboard_page():void
{
    $p=HrDashboard::params($_GET);$d=HrDashboard::data($p);$t=$d['tickets'];$w=$d['window'];$group=$p['company']==='ALL'?'':$p['company'];
    $period=strtolower(HrDashboard::PERIODS[$p['period']]);
    $queue=static fn(array $x=[])=>url('hr-service-requests',$x+($group!==''?['company_group'=>$group]:[]));
    $incOpen=$d['incident']?url($d['incident']['open_link']['page'],$d['incident']['open_link']['params']):'';// Incident Cases, Open tab: the same list the count comes from
    $incAll=url(TicketIncident::QUEUE_PAGE,['status'=>'all']);// every status, for the "latest reports" feed
    $counts=RecruitmentRepository::stageCounts();$maxStage=max(1,...array_map(fn($x)=>(int)$x['total'],$counts?:[['total'=>0]]));
    $interviews=db()->query('SELECT i.*, CONCAT(ap.first_name," ",ap.last_name) applicant_name,j.title job_title FROM interviews i JOIN applications a ON a.id=i.application_id JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id WHERE DATE(i.scheduled_at)=CURDATE() ORDER BY i.scheduled_at LIMIT 6')->fetchAll();
    $first=explode(' ',trim((string)(Auth::user()['name']??'HR Team')))[0];
    render_portal_header('hr','hr-dashboard','HR Dashboard');
    echo '<link rel="stylesheet" href="public/assets/hr-ops.css?v=20261025-1">';
    dashboard_hero('Human Resources',hd_greeting().', '.$first.'.','Here is the workforce, request and recruitment activity that needs your attention.','<a href="'.url('hr-employees').'" class="btn">'.icon_svg('users').' Employee directory</a>'.($d['is_hr']?'<a href="'.e($queue(['tab'=>'pending'])).'" class="btn primary">Open HR Request Queue '.icon_svg('arrow').'</a>':'<a href="'.url('hr-pipeline').'" class="btn primary">Open recruitment pipeline '.icon_svg('arrow').'</a>'));

    if($d['is_hr']):?>
    <section class="hd-strip" aria-labelledby="hd-strip-title"><h2 id="hd-strip-title">Needs your action today</h2><div class="hd-strip-grid">
      <?php hd_strip_card('Pending HR requests',(string)$t['pending'],'',$queue(['tab'=>'pending']),'clipboard',$t['pending']>0?'is-warm':'');
      if($d['incident'])hd_strip_card('Open Incident Cases',(string)$d['incident']['open'],'',$incOpen,'alert',$d['incident']['open']>0?'is-hot':'');
      hd_strip_card('Oldest pending',$t['pending']?$t['oldest_days'].($t['oldest_days']===1?' day':' days'):'—',$t['pending']?'':'none waiting',$queue(['tab'=>'pending','sort'=>'oldest']),'clock',$t['oldest_days']>5?'is-hot':($t['oldest_days']>2?'is-warm':''));
      if($d['setup'])hd_strip_card('Setup problems',(string)$d['setup']['total'],$d['setup']['branches_without_sc'].' branches without SC',url('hr-ticket-viewers'),'settings',$d['setup']['total']>0?'is-warm':'');?>
    </div></section>
    <?php endif;?>

    <form method="get" class="hd-filterbar" action="index.php"><input type="hidden" name="page" value="hr-dashboard">
      <div class="field"><label for="hd-company">Company</label><select id="hd-company" name="company" onchange="this.form.submit()"><?php foreach(HrDashboard::COMPANIES as $k=>$l):?><option value="<?=e($k)?>" <?=$p['company']===$k?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div>
      <div class="field"><label for="hd-period">Period</label><select id="hd-period" name="period" onchange="this.form.submit()"><?php foreach(HrDashboard::PERIODS as $k=>$l):?><option value="<?=e($k)?>" <?=$p['period']===$k?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div>
      <noscript><button class="btn">Apply</button></noscript>
      <span class="hq-muted" style="font-size:11.5px"><?=e($w['start'])?> to <?=e($w['last_day'])?> · changes compare with the same stretch before it<?=$group!==''?' · request and employee figures follow the company filter; recruitment is not split by company':''?></span>
    </form>

    <div class="hd-kpis">
      <?php if($d['is_hr']){
        hd_kpi('Pending requests',$t['pending'],$queue(['tab'=>'pending']),'clipboard','Waiting for HR',HrDashboard::delta($t['pending'],$t['pending_before']));
        hd_kpi('In progress',$t['progress'],$queue(['tab'=>'progress']),'clock','Being handled by HR',HrDashboard::delta($t['progress'],$t['progress_before']));
        hd_kpi('Served · '.$period,$t['served'],$queue(['tab'=>'served','closed_from'=>$w['start'],'closed_to'=>$w['last_day']]),'check-square',$t['filed'].' filed in the period',HrDashboard::delta($t['served'],$t['served_before']));
      }
      if($d['incident'])hd_kpi('Incident Reports',$d['incident']['open'],$incOpen,'alert',$d['incident']['new'].' new · '.$period,HrDashboard::delta($d['incident']['new'],$d['incident']['new_before']));
      $r=$d['recruitment'];hd_kpi('Recruitment',(int)$r['active'],url('hr-pipeline'),'user-plus',(int)$r['openReq'].' open headcount',HrDashboard::delta($r['applied'],$r['applied_before']));
      $em=$d['employees'];hd_kpi('Active employees',$em['active'],url('hr-employees'),'users',$em['probation_due'].' probation due in '.$em['due_days'].' days',HrDashboard::delta($em['hired'],$em['hired_before']));?>
    </div>

    <?php if($d['is_hr']):$maxType=max(1,...array_values(array_map(fn($x)=>$x["total"],$t["types"])));$ageMax=max(1,array_sum($t['age']));
      $segs=['pending'=>'Pending','progress'=>'In progress','served'=>'Served','cancelled'=>'Cancelled','other'=>'Other (awaiting approver, returned, rejected)'];?>
    <div class="dashboard-grid">
      <div>
        <section class="panel"><div class="panel-head"><div><h2>Requests by type</h2><p>Filed <?=e($period)?>, by current status</p></div><a class="panel-link" href="<?=e($queue())?>">Open queue</a></div><div class="panel-body">
          <?php if(!array_sum(array_column($t['types'],'total'))):?><div class="empty">No requests were filed in this period.</div><?php else:?>
          <?php foreach($t['types'] as $code=>$ty):$label=$ty['name'].': '.$ty['total'].' filed';foreach($segs as $k=>$sl)if($ty[$k])$label.=', '.$ty[$k].' '.strtolower(explode(' (',$sl)[0]);?>
          <a class="hd-bar-row" href="<?=e($queue(['type_code'=>$code]))?>" aria-label="<?=e($label)?>"><span class="lbl"><?=icon_svg(TicketQueue::icon($code))?><span><?=e($ty['name'])?></span></span>
            <span class="hd-track"><?php foreach($segs as $k=>$sl)if($ty[$k]):?><i class="hd-seg seg-<?=$k?>" style="width:<?=round($ty[$k]/$maxType*100,2)?>%" title="<?=e(explode(' (',$sl)[0].': '.$ty[$k])?>"></i><?php endif;?></span><span class="n"><?=$ty['total']?></span></a>
          <?php endforeach;?>
          <div class="hd-legend"><?php foreach($segs as $k=>$sl):?><span><i class="seg-<?=$k?>"></i><?=e($sl)?></span><?php endforeach;?></div><?php endif;?></div></section>
        <section class="panel"><div class="panel-head"><div><h2>How long requests have waited</h2><p>Pending requests by days since filed</p></div><a class="panel-link" href="<?=e($queue(['tab'=>'pending']))?>">View pending</a></div><div class="panel-body">
          <?php if(!array_sum($t['age'])):?><div class="empty">Nothing is waiting for HR.</div><?php else:foreach([['0-2 days','ok',0],['3-5 days','warn',1],['Over 5 days','late',2]] as [$lbl,$tone,$i]):?>
          <a class="hd-bar-row" href="<?=e($queue(['tab'=>'pending','age'=>(string)$i]))?>" aria-label="<?=e($lbl.': '.$t['age'][$i].' pending')?>"><span class="lbl"><span><?=e($lbl)?></span></span><span class="hd-track"><i class="hd-seg hd-age-<?=$tone?>" style="width:<?=round($t['age'][$i]/$ageMax*100,2)?>%"></i></span><span class="n"><?=$t['age'][$i]?></span></a>
          <?php endforeach;endif;?></div></section>
      </div>
      <section class="panel"><div class="panel-head"><div><h2>Requires attention</h2><p>Oldest first</p></div></div><div class="panel-body">
        <div class="hd-sub" style="margin-top:0">Oldest pending requests</div>
        <?php if(!$t['oldest']):?><div class="empty" style="padding:18px">No pending requests.</div><?php else:?><div class="hd-list"><?php foreach($t['oldest'] as $o):?>
          <a class="hd-item" href="<?=e(url('hr-request',['id'=>$o['id']]))?>"><span><strong><?=e($o['type_name'])?></strong> · <?=e(trim($o['first_name'].' '.$o['last_name']))?><small><?=e($o['branch_name'])?> · <?=e($o['ticket_no'])?></small></span><?=hq_age($o['age_days'],$o['age_tone'])?></a><?php endforeach;?></div><?php endif;?>
        <?php if($d['incident']):$latest=$d['incident']['latest'][0]??null;?><div class="hd-sub">Incident Reports</div>
          <a class="hd-item" href="<?=e($incOpen)?>"><span><strong><?=$d['incident']['new']?> new · <?=$d['incident']['open']?> open</strong><small><?=$latest?'Latest: '.e($latest['title']):'No Incident Reports yet'?></small></span><span class="badge <?=$d['incident']['open']?'red':'gray'?>"><?=$d['incident']['open']?'Open':'None'?></span></a><?php endif;?>
        <?php if($d['setup']):$s=$d['setup'];?><div class="hd-sub">Setup warnings</div><div class="attention-list">
          <?php attention_item('Branches without a Sales Captain','Only the ADL can see their requests',$s['branches_without_sc'],$s['branches_without_sc']?'warn':'success','store');
          attention_item('Retail employees without a viewer','No ADL, Sales Captain or OIC can view their requests',$s['retail_missing'],$s['retail_missing']?'warn':'success','users');
          attention_item('Head Office employees without a Manager viewer','Reporting To missing or no usable account',$s['ho_missing'],$s['ho_missing']?'warn':'success','user');?></div>
          <p style="margin:10px 0 0"><a class="panel-link" href="<?=e(url('hr-ticket-viewers'))?>">Review in Ticket Viewers</a></p><?php endif;?>
      </div></section>
    </div>
    <?php endif;?>

    <div class="dashboard-grid equal">
      <section class="panel"><div class="panel-head"><div><h2>Recruitment pulse</h2><p>Applicants by stage</p></div><a class="panel-link" href="<?=url('hr-pipeline')?>">View pipeline</a></div><div class="panel-body"><div class="pulse-bars compact"><?php foreach($counts as $c):$h=max(8,round(((int)$c['total']/$maxStage)*85));?><div class="col" title="<?=e($c['name'].' · '.$c['total'])?>"><div class="bar" style="height:<?=$h?>px"></div><div class="label"><?=e(mb_substr($c['name'],0,8))?></div></div><?php endforeach;?></div><div class="legend-row"><span><i class="legend-dot" style="background:var(--hris-brand)"></i>Applicant volume by stage</span><span><?=(int)$r['interviews']?> interviews · <?=count($interviews)?> today</span></div></div></section>
      <?php if($d['incident']):?><section class="panel"><div class="panel-head"><div><h2>Latest Incident Feed</h2><p>Most recent reports</p></div><a class="panel-link" href="<?=e($incAll)?>">Open feed</a></div><div class="panel-body">
        <?php if(!$d['incident']['latest']):?><div class="empty">No Incident Reports have been filed.</div><?php else:?><div class="hd-list"><?php foreach($d['incident']['latest'] as $i):?>
          <a class="hd-item" href="<?=e(url('hr-request',['id'=>$i['id']]))?>"><span><strong><?=e($i['title'])?></strong><small><?=e($i['ticket_no'])?> · <?=e(substr((string)$i['filed'],0,10))?></small></span><?=hq_pill($i['status'],'INCIDENT_REPORT')?></a><?php endforeach;?></div><?php endif;?></div></section><?php endif;?>
    </div>
    <div class="dashboard-grid equal">
      <section class="panel"><div class="panel-head"><div><h2>Today’s interviews</h2><p>Scheduled candidate conversations</p></div><a class="panel-link" href="<?=url('hr-applicants')?>">All applicants</a></div><div class="panel-body"><?php if(!$interviews):?><div class="empty">No interviews are scheduled today.</div><?php else:?><div class="timeline-list"><?php foreach($interviews as $i):?><div class="timeline-row"><div class="time"><?=e(date('g:i A',strtotime($i['scheduled_at'])))?></div><span class="timeline-dot"></span><div class="detail"><strong><?=e($i['applicant_name'])?></strong><span><?=e($i['job_title'])?> · <?=e(stage_label($i['interview_type']))?></span></div><span class="badge amber"><?=e(stage_label($i['interview_type']))?></span></div><?php endforeach;?></div><?php endif;?></div></section>
      <section class="panel"><div class="panel-head"><div><h2>Quick actions</h2><p>Start common HR workflows</p></div></div><div class="panel-body"><div class="quick-actions">
        <?php if($d['is_hr']):?><a class="quick-action" href="<?=e($queue(['tab'=>'pending']))?>"><span class="qa-icon"><?=icon_svg('clipboard')?></span><span><strong>HR Request Queue</strong><span>Process pending requests</span></span></a><?php endif;?>
        <a class="quick-action" href="<?=url('hr-employees')?>"><span class="qa-icon"><?=icon_svg('users')?></span><span><strong>Employee directory</strong><span>Open employee master records</span></span></a><a class="quick-action" href="<?=url('hr-manpower')?>"><span class="qa-icon"><?=icon_svg('briefcase')?></span><span><strong>Manpower request</strong><span>Create or review hiring demand</span></span></a><a class="quick-action" href="<?=url('hr-applicants')?>"><span class="qa-icon"><?=icon_svg('user-plus')?></span><span><strong>Applicant database</strong><span>Search candidate profiles</span></span></a><a class="quick-action" href="<?=url('hr-reports')?>"><span class="qa-icon"><?=icon_svg('chart')?></span><span><strong>Reports</strong><span>Review HR and recruitment activity</span></span></a></div></div></section>
    </div>
    <?php render_portal_footer();
}
