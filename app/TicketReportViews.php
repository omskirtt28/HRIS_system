<?php
declare(strict_types=1);
require_once __DIR__.'/TicketReport.php';
require_once __DIR__.'/TicketQueueViews.php';

function tr_n(int|float|null $v,string $dash='—'): string { return $v===null?$dash:(is_float($v)?rtrim(rtrim(number_format($v,1),'0'),'.'):number_format($v)); }
function tr_days(?float $d): string { return $d===null?'—':tr_n($d).' d'; }
/** One horizontal bar row; $max keeps the rows of one chart on the same scale. */
function tr_bar(string $label,int $n,int $max,string $tone='pending',string $href=''): string
{
    $w=$max>0?round($n/$max*100,2):0;$inner='<span class="lbl"><span>'.e($label).'</span></span><span class="hd-track"><i class="hd-seg seg-'.e($tone).'" style="width:'.$w.'%"></i></span><span class="n">'.number_format($n).'</span>';
    return $href!==''?'<a class="hd-bar-row" href="'.e($href).'">'.$inner.'</a>':'<div class="hd-bar-row">'.$inner.'</div>';
}
function tr_panel(string $title,string $sub,callable $body): void
{ ?><section class="panel tr-panel"><div class="panel-head"><div><h2><?=e($title)?></h2><?php if($sub!==''):?><p><?=e($sub)?></p><?php endif;?></div></div><div class="panel-body"><?php $body();?></div></section><?php }
function tr_empty(string $text='Nothing in this period.'): void { echo '<div class="tr-empty">'.e($text).'</div>'; }
function tr_table(array $head,array $rows,array $numCols=[],?array $foot=null): void
{ ?><div class="tr-scroll"><table class="tbl tr-table"><thead><tr><?php foreach($head as $i=>$h):?><th class="<?=in_array($i,$numCols,true)?'hq-num':''?>"><?=e($h)?></th><?php endforeach;?></tr></thead><tbody>
  <?php foreach($rows as $r):?><tr><?php foreach($r as $i=>$c):?><td class="<?=in_array($i,$numCols,true)?'hq-num':''?>"><?=e((string)$c)?></td><?php endforeach;?></tr><?php endforeach;?></tbody>
  <?php if($foot):?><tfoot><tr class="hq-total"><?php foreach($foot as $i=>$c):?><td class="<?=in_array($i,$numCols,true)?'hq-num':''?>"><?=e((string)$c)?></td><?php endforeach;?></tr></tfoot><?php endif;?></table></div><?php }

function tr_filters(array $p): void
{
    $types=db()->query('SELECT code,name FROM ticket_types WHERE active=1 AND code<>"INCIDENT_REPORT" ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR); ?>
    <form method="get" class="hd-filterbar tr-filters"><input type="hidden" name="page" value="hr-ticket-report">
      <div class="field"><label for="tr-period">Period</label><select id="tr-period" name="period"><?php foreach(TicketReport::PERIODS as $k=>$l):?><option value="<?=e($k)?>" <?=$p['period']===$k?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div>
      <div class="field"><label for="tr-from">From</label><input id="tr-from" type="date" name="from" value="<?=e($p['from'])?>"></div>
      <div class="field"><label for="tr-to">To</label><input id="tr-to" type="date" name="to" value="<?=e($p['to'])?>"></div>
      <div class="field"><label for="tr-company">Company</label><select id="tr-company" name="company"><?php foreach(TicketReport::COMPANIES as $k=>$l):?><option value="<?=e($k)?>" <?=$p['company']===$k?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div>
      <div class="field"><label for="tr-branch">Branch</label><select id="tr-branch" name="branch_id"><option value="0">All branches</option><?php foreach(FoundationRepository::branches() as $b):?><option value="<?=(int)$b['id']?>" <?=$p['branch_id']===(int)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></div>
      <div class="field"><label for="tr-type">Request type</label><select id="tr-type" name="type_code"><option value="">All types</option><?php foreach($types as $c=>$n):?><option value="<?=e($c)?>" <?=$p['type_code']===$c?'selected':''?>><?=e($n)?></option><?php endforeach;?></select></div>
      <button class="btn primary">Apply</button><a class="btn" href="<?=e(url('hr-ticket-report'))?>">Reset</a>
    </form>
<?php }

function tr_describe(array $p,array $w): string
{
    $bits=[TicketReport::PERIODS[$p['period']].' ('.$w['start'].' to '.$w['last_day'].')',TicketReport::COMPANIES[$p['company']]];
    if($p['branch_id']){$q=db()->prepare('SELECT name FROM branches WHERE id=?');$q->execute([$p['branch_id']]);$bits[]=(string)($q->fetchColumn()?:'Branch');}
    if($p['type_code']!==''){$q=db()->prepare('SELECT name FROM ticket_types WHERE code=?');$q->execute([$p['type_code']]);$bits[]=(string)($q->fetchColumn()?:$p['type_code']);}
    return implode(' · ',$bits);
}

function ticket_report_page(array $p,array $d): void
{
    $w=$d['window'];$s=$d['summary'];$a=$d['aging'];$o=$d['origin'];$t=$d['throughput'];$link=TicketReport::linkParams($p);
    render_portal_header((Auth::user()['role_portal']??'')==='admin'?'admin':'hr','hr-ticket-report','Ticketing Report');
    echo '<link rel="stylesheet" href="public/assets/hr-ops.css?v=20261025-1"><link rel="stylesheet" href="public/assets/ticket-report.css?v=20261025-1">';
    page_head('Insights','Ticketing Report','<a class="btn" href="'.e(url('hr-ticket-report-export',$link)).'">'.icon_svg('file').' Export CSV</a><a class="btn" href="'.e(url('hr-ticket-report-print',$link)).'" target="_blank" rel="noopener">'.icon_svg('clipboard').' Print summary</a>');
    tr_filters($p); ?>
    <p class="tr-scope"><?=e(tr_describe($p,$w))?> · generated <?=e($d['generated'])?></p>

    <div class="hd-kpis tr-kpis">
      <?=tr_kpi('Requests filed',$s['status']['total'],'in this period')?>
      <?=tr_kpi('New this week',$s['new_this_week'],'since Monday')?>
      <?=tr_kpi('Pending now',$a['pending'],'waiting for HR')?>
      <?=tr_kpi('Pending over '.TicketReport::AGING_DAYS.' days',$a['over'],'needs attention',$a['over']>0?'is-hot':'')?>
      <?=tr_kpi('Avg time to Served',$s['avg_days']===null?'—':tr_n($s['avg_days']).' d','from '.$s['served_n'].' served in this period')?>
      <?=tr_kpi('Incident Reports',$d['incident']===null?'—':$d['incident'],'count only, no details')?>
    </div>

    <?php tr_panel('Summary','Requests filed in the period, by type and status. Pending = waiting for HR; Other = still with an approver, returned or rejected.',function()use($s){
        if(!$s['status']['total']){tr_empty('No requests were filed in this period.');return;}
        $rows=[];foreach($s['types'] as $ty)$rows[]=[$ty['name'],$ty['total'],$ty['pending'],$ty['progress'],$ty['served'],$ty['cancelled'],$ty['other'],tr_days($ty['avg_days'])];
        $st=$s['status'];tr_table(['Request type','Filed','Pending','In progress','Served','Cancelled','Other','Avg to Served'],$rows,[1,2,3,4,5,6,7],['All types',$st['total'],$st['pending'],$st['progress'],$st['served'],$st['cancelled'],$st['other'],tr_days($s['avg_days'])]);
    });?>

    <?php tr_panel('Aging','Live view of requests waiting for HR. It ignores the period and follows the company, branch and type filters.',function()use($a){
        if(!$a['pending']){tr_empty('Nothing is pending. Nice.');return;}
        $max=max(1,...$a['buckets']);foreach(['0 to 2 days'=>['ok',0],'3 to 5 days'=>['warn',1],'Over 5 days'=>['late',2]] as $label=>[$tone,$i])echo '<div class="hd-bar-row"><span class="lbl"><span>'.e($label).'</span></span><span class="hd-track"><i class="hd-seg hd-age-'.$tone.'" style="width:'.round($a['buckets'][$i]/$max*100,2).'%"></i></span><span class="n">'.$a['buckets'][$i].'</span></div>';
        if($a['over_by_type']){echo '<h3 class="hd-sub">Over '.TicketReport::AGING_DAYS.' days, by type</h3>';$m=max(1,...array_column($a['over_by_type'],'n'));foreach($a['over_by_type'] as $r)echo tr_bar($r['name'],$r['n'],$m,'cancelled');}
        echo '<h3 class="hd-sub">Oldest pending</h3>';
        tr_table(['Request','Type','Employee','Branch','Waiting'],array_map(fn($r)=>[$r['ticket_no'],$r['type_name'],trim($r['first_name'].' '.$r['last_name']),$r['branch_name'],$r['age_days'].' d'],$a['oldest']),[4]);
    });?>

    <?php tr_panel('Where requests come from','By company, area and branch (the requester\'s branch when filed; Uniform requests use the branch the uniform is for when you filter by branch).',function()use($o,$p){
        if(!$o['branch']){tr_empty();}else{
            echo '<div class="tr-cols"><div><h3 class="hd-sub">Company</h3>';$m=max(1,...array_column($o['company'],'n'));foreach($o['company'] as $r)echo tr_bar($r['name'],$r['n'],$m,'progress');
            echo '</div><div><h3 class="hd-sub">Area</h3>';if($o['area']){$m=max(1,...array_column($o['area'],'n'));foreach($o['area'] as $r)echo tr_bar($r['name'],$r['n'],$m,'served');}else tr_empty('No areas set up.');
            echo '</div></div><h3 class="hd-sub">Branches</h3>';
            $m=max(1,...array_column($o['branch'],'n'));foreach(array_slice($o['branch'],0,12) as $r)echo tr_bar($r['name'],$r['n'],$m,'pending',url('hr-service-requests',['branch_id'=>$r['id']]));
            if(count($o['branch'])>12){echo '<details class="tr-more"><summary>'.(count($o['branch'])-12).' more branches</summary>';foreach(array_slice($o['branch'],12) as $r)echo tr_bar($r['name'],$r['n'],$m,'pending',url('hr-service-requests',['branch_id'=>$r['id']]));echo '</details>';}
        }
        echo '<h3 class="hd-sub">Branches with no request filed</h3>';
        if($o['no_filing']===null)echo '<p class="tr-note">Hidden while a branch filter is on.</p>';
        elseif(!$o['no_filing'])echo '<p class="tr-note">Every branch with staff filed at least one request.</p>';
        else{echo '<p class="tr-note"><strong>'.count($o['no_filing']).'</strong> branches with active staff filed nothing in this period.</p><details class="tr-more"><summary>Show the branches</summary><ul class="tr-list">';foreach($o['no_filing'] as $b)echo '<li>'.e($b['name']).' <small>'.$b['employees'].' staff</small></li>';echo '</ul></details>';}
    });?>

    <?php foreach($d['detail'] as $code=>$x):tr_detail_panel($code,$x);endforeach;?>

    <?php tr_panel('HR throughput','Requests filed and Served per '.($t['weekly']?'week':'day').' for the whole HR team. Not broken down by person.',function()use($t){
        if(!$t['filed']&&!$t['served']){tr_empty();return;}
        echo '<div class="tr-spark" role="img" aria-label="Filed and served per '.($t['weekly']?'week':'day').'">';
        foreach($t['series'] as $s){$h=fn($n)=>round($n/$t['max']*100,2);echo '<div class="tr-col" title="'.e($s['label'].': '.$s['filed'].' filed, '.$s['served'].' served').'"><div class="tr-bars"><i class="seg-pending" style="height:'.$h($s['filed']).'%"></i><i class="seg-served" style="height:'.$h($s['served']).'%"></i></div><span>'.e(count($t['series'])>31?substr($s['label'],-2):$s['label']).'</span></div>';}
        echo '</div><div class="hd-legend"><span><i class="seg-pending"></i>Filed ('.$t['filed'].')</span><span><i class="seg-served"></i>Served ('.$t['served'].')</span></div>';
        $rows=array_values(array_filter($t['series'],fn($s)=>$s['filed']||$s['served']));
        echo '<details class="tr-more"><summary>Show the numbers</summary>';tr_table([$t['weekly']?'Week of':'Day','Filed','Served'],array_map(fn($s)=>[$s['label'],$s['filed'],$s['served']],$rows),[1,2]);echo '</details>';
    });?>

    <?php tr_setup_panel($d['setup']);?>
    <?php render_portal_footer();
}
function tr_kpi(string $label,int|string $value,string $foot,string $tone=''): string
{
    return '<div class="metric-card hd-kpi '.e($tone).'"><div class="metric-top"><div class="metric-label">'.e($label).'</div></div><div class="metric-value">'.e(is_int($value)?number_format($value):$value).'</div>'.($foot!==''?'<div class="metric-foot"><span>'.e($foot).'</span></div>':'').'</div>';
}
function tr_setup_panel(?array $s): void
{
    tr_panel('Setup health','Whole company, today. Employee names are on the Ticket Viewers screen, not here.',function()use($s){
        if($s===null){tr_empty('Setup health needs access to Ticket Approvers / Viewers.');return;}
        echo '<div class="tr-stats"><div><strong>'.number_format($s['without_viewer']).'</strong><span>of '.number_format($s['employees']).' employees have no viewer or Manager<br><small>'.$s['retail_missing'].' retail · '.$s['ho_missing'].' Head Office</small></span></div>'
            .'<div><strong>'.count($s['branches_without_sc']).'</strong><span>branches with staff and no Sales Captain</span></div><div><strong>'.$s['branches_sc_no_account'].'</strong><span>branches whose Sales Captain has no usable account</span></div></div>';
        if($s['branches_without_sc'])echo '<details class="tr-more"><summary>Branches without a Sales Captain</summary><ul class="tr-list">'.implode('',array_map(fn($n)=>'<li>'.e($n).'</li>',$s['branches_without_sc'])).'</ul></details>';
        if(Auth::can('tickets.approvers.manage'))echo '<p><a href="'.e(url('hr-ticket-viewers')).'">Open Ticket Viewers</a></p>';
    });
}

function tr_detail_panel(string $code,array $x): void
{
    $title=db()->prepare('SELECT name FROM ticket_types WHERE code=?');$title->execute([$code]);$title=(string)($title->fetchColumn()?:$code);
    switch($x['kind']){
        case 'basic':
            tr_panel($title,'Top branches by number of requests in the period.',function()use($x){if(!$x['branches']){tr_empty();return;}$m=max(1,...array_column($x['branches'],'n'));foreach($x['branches'] as $r)echo tr_bar($r['name'],$r['n'],$m,'pending');});break;
        case 'uniform':
            tr_panel('Uniform Request','Pieces requested per size and branch (the branch the uniform is for). Cancelled and rejected requests are left out.',function()use($x){
                if(!$x['rows']){tr_empty();return;}
                echo '<p class="tr-note"><strong>'.number_format($x['pieces']).'</strong> pieces in <strong>'.number_format($x['requests']).'</strong> requests.</p>';
                $rows=[];foreach($x['rows'] as $r){$line=[$r['name']];foreach($x['sizes'] as $s)$line[]=$r['sizes'][$s]??0;$line[]=$r['total'];$rows[]=$line;}
                $foot=['All branches'];foreach($x['sizes'] as $s)$foot[]=$x['totals'][$s];$foot[]=$x['pieces'];
                tr_table(array_merge(['Branch'],$x['sizes'],['Total']),$rows,range(1,count($x['sizes'])+1),$foot);
            });break;
        case 'payroll':
            tr_panel('Payroll Concern','By cutoff (latest 12 with requests).',function()use($x){if(!$x['rows']){tr_empty();return;}tr_table(['Cutoff','Filed','Open','Served','Filed late'],array_map(fn($r)=>[$r['cutoff'],$r['total'],$r['open'],$r['served'],$r['late']],$x['rows']),[1,2,3,4]);});break;
        case 'manpower':
            tr_panel('Manpower Request','Positions requested (quantity), cancelled and rejected left out.',function()use($x){if(!$x['rows']){tr_empty();return;}tr_table(['Position','Replacement','Additional','Total','Requests'],array_map(fn($r)=>[$r['position'],$r['Replacement'],$r['Additional'],$r['total'],$r['requests']],$x['rows']),[1,2,3,4]);});break;
        case 'transfer':
            tr_panel('Branch Transfer','Most common moves.',function()use($x){if(!$x['rows']){tr_empty();return;}tr_table(['From','To','Requests','Served'],array_map(fn($r)=>[$r['from'],$r['to'],$r['n'],$r['served']],$x['rows']),[2,3]);});break;
        case 'coe':
            tr_panel('Certificate of Employment','By purpose.',function()use($x){if(!$x['rows']){tr_empty();return;}tr_table(['Purpose','Requests','Served'],array_map(fn($r)=>[$r['purpose'],$r['n'],$r['served']],$x['rows']),[1,2]);});break;
        case 'oic':
            tr_panel('OIC Authorization','By branch. '.$x['active_now'].' authorization(s) are active today.',function()use($x){if(!$x['rows']){tr_empty();return;}tr_table(['Branch','Requests','Approved'],array_map(fn($r)=>[$r['branch'],$r['n'],$r['approved']],$x['rows']),[1,2]);});break;
    }
}

/** One-page printable summary for the CEO: standalone page, no sidebar. */
function ticket_report_print_page(array $p,array $d): void
{
    $w=$d['window'];$s=$d['summary'];$a=$d['aging'];$o=$d['origin'];$t=$d['throughput'];$u=$d['detail']['UNIFORM_REQUEST']??null; ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HR Ticketing Summary</title>
<link rel="stylesheet" href="public/assets/ticket-report.css?v=20261025-1"></head><body class="tr-print">
<main class="tr-sheet">
  <header><div><h1>HR Ticketing Summary</h1><p><?=e(tr_describe($p,$w))?></p></div><div class="tr-actions"><button type="button" onclick="window.print()">Print</button></div></header>
  <section class="tr-kpi-row"><div><strong><?=number_format($s['status']['total'])?></strong><span>requests filed</span></div><div><strong><?=number_format($s['status']['served'])?></strong><span>served</span></div><div><strong><?=number_format($a['pending'])?></strong><span>pending now</span></div><div><strong><?=number_format($a['over'])?></strong><span>pending over <?=TicketReport::AGING_DAYS?> days</span></div><div><strong><?=$s['avg_days']===null?'—':e(tr_n($s['avg_days'])).' d'?></strong><span>average time to Served</span></div></section>
  <h2>By request type</h2>
  <?php if(!$s['status']['total']):?><p class="tr-none">No requests were filed in this period.</p><?php else:
    $rows=[];foreach($s['types'] as $ty)if($ty['total'])$rows[]=[$ty['name'],$ty['total'],$ty['pending'],$ty['progress'],$ty['served'],$ty['cancelled'],$ty['other'],tr_days($ty['avg_days'])];
    tr_table(['Type','Filed','Pending','In progress','Served','Cancelled','Other','Avg to Served'],$rows,[1,2,3,4,5,6,7]);endif;?>
  <div class="tr-two">
    <div><h2>Where from</h2><?php if($o['company']||$o['branch']):?><ul class="tr-list"><?php foreach($o['company'] as $r):?><li><strong><?=e($r['name'])?></strong> <?=number_format($r['n'])?></li><?php endforeach;?></ul>
      <h3>Top branches</h3><ul class="tr-list"><?php foreach(array_slice($o['branch'],0,5) as $r):?><li><?=e($r['name'])?> <strong><?=number_format($r['n'])?></strong></li><?php endforeach;?></ul>
      <?php if($o['no_filing']!==null):?><p class="tr-note"><?=count($o['no_filing'])?> branches filed nothing.</p><?php endif;?><?php else:?><p class="tr-none">No requests.</p><?php endif;?></div>
    <div><h2>Oldest waiting</h2><?php if($a['oldest']):?><ul class="tr-list"><?php foreach(array_slice($a['oldest'],0,5) as $r):?><li><?=e($r['type_name'])?> · <?=e(trim($r['first_name'].' '.$r['last_name']))?> · <?=e($r['branch_name'])?> <strong><?=$r['age_days']?> d</strong></li><?php endforeach;?></ul><?php else:?><p class="tr-none">Nothing pending.</p><?php endif;?>
      <h2>Team throughput</h2><p><?=number_format($t['filed'])?> filed, <?=number_format($t['served'])?> served in the period.</p>
      <?php if($u):?><h2>Uniforms</h2><p><?=number_format($u['pieces'])?> pieces in <?=number_format($u['requests'])?> requests.</p><?php endif;?>
      <?php if($d['incident']!==null):?><h2>Incident Reports</h2><p><?=number_format($d['incident'])?> filed (count only).</p><?php endif;?></div>
  </div>
  <?php if($d['setup']):?><h2>Setup health</h2><p><?=number_format($d['setup']['without_viewer'])?> of <?=number_format($d['setup']['employees'])?> employees have no viewer or Manager · <?=count($d['setup']['branches_without_sc'])?> branches without a Sales Captain.</p><?php endif;?>
  <footer>Generated <?=e($d['generated'])?> from live HRIS data. Confidential: for management use.</footer>
</main></body></html>
<?php }
