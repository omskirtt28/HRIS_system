<?php
declare(strict_types=1);
/**
 * Accounting Phase 3 B1: dashboard (no migration). Live KPIs as of today + an "Income and expenses" card with its own
 * Monthly / Quarterly / Yearly period picker (All/Clear, default Monthly/All), reusing HrDashboard::filters/options.
 * Posted entries only; closing entries are excluded like the Income Statement.
 */
final class AccountingDashboard
{
    /** Live figures as of $today, in centavos. */
    public static function kpis(string $today): array
    {
        $bankGl=array_map('intval',array_column(CashBank::banks(true),'account_id'));
        $cash=0; foreach(Accounting::balances(null,$today) as $b) if(in_array($b['id'],$bankGl,true)) $cash+=$b['balance'];
        return ['cash'=>$cash,'banks'=>count($bankGl),'ar'=>AccountsReceivable::aging($today)['total']['total'],'ap'=>AccountsPayable::aging($today)['total']['total'],
            'pending'=>(int)(Accounting::statusCounts()['FOR_APPROVAL']??0)];
    }

    /**
     * One row per selected period that has started: label, inclusive from/to (to = today for the running period), income, expense, net.
     * @param array{mode:string,year:int,periods:list<int>} $p
     */
    public static function series(array $p,string $today): array
    {
        $out=[];
        foreach($p['periods'] as $n) {
            $year=$p['mode']==='yearly'?$n:$p['year']; $month=$p['mode']==='monthly'?$n:($p['mode']==='quarterly'?($n-1)*3+1:1);
            $months=$p['mode']==='monthly'?1:($p['mode']==='quarterly'?3:12);
            $from=sprintf('%04d-%02d-01',$year,$month); if($from>$today) continue; // future periods have no figures
            $to=min(date('Y-m-d',strtotime($from.' +'.$months.' months -1 day')),$today);
            $label=$p['mode']==='monthly'?date('F',strtotime($from)):($p['mode']==='quarterly'?'Q'.$n:(string)$n);
            $is=Accounting::incomeStatement($from,$to);
            $out[]=['label'=>$label,'from'=>$from,'to'=>$to,'income'=>$is['total_income'],'expense'=>$is['total_expense'],'net'=>$is['net']];
        }
        return $out;
    }
}

/** Short peso label for bar tops: 1.2M, 85K, 950. */
function accounting_short(int $cents): string
{
    $v=$cents/100; $a=abs($v);
    return ($v<0?'-':'').($a>=1e6?rtrim(rtrim(number_format($a/1e6,1),'0'),'.').'M':($a>=1e3?rtrim(rtrim(number_format($a/1e3,1),'0'),'.').'K':number_format($a,0)));
}

/** Grouped bars (income, expenses) per period as inline SVG: 3D extruded faces by default; Bar style switches faces in CSS. */
function accounting_bar_svg(array $rows): string
{
    $series=[['income','Income','ac-s-inc'],['expense','Expenses','ac-s-exp']];
    $max=1; foreach($rows as $r) foreach($series as [$k]) $max=max($max,$r[$k]);
    $W=max(560,count($rows)*110); $H=260; $top=28; $base=$H-34; $d=8; $slot=($W-20)/max(1,count($rows)); $bw=min(34,$slot/3.2);
    $s='<svg class="ac-chart" viewBox="0 0 '.$W.' '.$H.'" role="img" aria-label="Income and expenses by period" preserveAspectRatio="xMinYMid meet">';
    $s.='<line class="ac-axis" x1="10" x2="'.($W-10).'" y1="'.$base.'" y2="'.$base.'"/>';
    foreach($rows as $i=>$r) {
        $cx=10+$slot*$i+$slot/2;
        foreach($series as $j=>[$k,$name,$cls]) {
            $h=$r[$k]>0?max(2,($base-$top)*$r[$k]/$max):0; $x=$cx+($j===0?-$bw-3:3); $y=$base-$h;
            $s.='<g class="ac-bar '.$cls.'"><title>'.e($r['label'].' '.$name.': '.Accounting::money($r[$k])).'</title>';
            if($h>0) {
                $s.='<ellipse class="ac-floor" cx="'.round($x+$bw/2+$d/2,1).'" cy="'.($base+2).'" rx="'.round($bw/2+$d,1).'" ry="3"/>';
                $s.=sprintf('<polygon class="ac-side" points="%1$.1f,%2$.1f %3$.1f,%4$.1f %3$.1f,%5$.1f %1$.1f,%6$.1f"/>',$x+$bw,$y,$x+$bw+$d,$y-$d,$base-$d,$base);
                $s.=sprintf('<polygon class="ac-top" points="%1$.1f,%2$.1f %3$.1f,%4$.1f %5$.1f,%4$.1f %6$.1f,%2$.1f"/>',$x,$y,$x+$d,$y-$d,$x+$bw+$d,$x+$bw);
                $s.=sprintf('<rect class="ac-front" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="2"/>',$x,$y,$bw,$h);
            }
            $s.=sprintf('<text class="ac-val" x="%.1f" y="%.1f" text-anchor="middle">%s</text>',$x+$bw/2+$d/2,$y-$d-5,e(accounting_short($r[$k])));
            $s.='</g>';
        }
        $s.=sprintf('<text class="ac-xlab" x="%.1f" y="%d" text-anchor="middle">%s</text>',$cx,$H-12,e($r['label']));
    }
    return $s.'</svg>';
}

function accounting_dashboard_page(array $k,array $p,array $rows): void
{
    $options=HrDashboard::options($p['mode'],$p['mode']==='yearly'?(int)date('Y'):$p['year']);
    $q=['mode'=>$p['mode'],'year'=>$p['year'],'selection'=>1,'selection_mode'=>$p['mode'],'periods'=>$p['periods']];
    $ti=array_sum(array_column($rows,'income')); $tx=array_sum(array_column($rows,'expense'));
    accounting_open('accounting-dashboard','Accounting Dashboard'); ?>
    <section class="ac-kpis" aria-label="Live figures as of today">
      <a class="ac-kpi" href="<?=url('accounting-banks')?>"><span>Bank balances (books)</span><strong><?=Accounting::money($k['cash'])?></strong><small><?=$k['banks']?> active bank account<?=$k['banks']===1?'':'s'?></small></a>
      <a class="ac-kpi" href="<?=url('accounting-invoices',['view'=>'aging'])?>"><span>Open receivables</span><strong><?=Accounting::money($k['ar'])?></strong><small>Posted invoices not yet collected</small></a>
      <a class="ac-kpi" href="<?=url('accounting-bills',['view'=>'aging'])?>"><span>Open payables</span><strong><?=Accounting::money($k['ap'])?></strong><small>Posted bills not yet paid</small></a>
      <a class="ac-kpi" href="<?=url('accounting-journal',['status'=>'FOR_APPROVAL'])?>"><span>Waiting for approval</span><strong><?=$k['pending']?></strong><small>Journal entries</small></a>
    </section>
    <section class="panel ac-card"><div class="panel-head"><div><h2>Income and expenses</h2><p>Posted entries · <?=e(HrDashboard::MODES[$p['mode']])?><?=$p['mode']==='yearly'?'':' '.$p['year']?></p></div>
      <a class="btn" href="<?=url('accounting-dashboard',$q+['csv'=>1])?>"><?=icon_svg('download')?> Download report</a></div>
      <details class="ac-options" <?=isset($_GET['selection'])?'open':''?>><summary>Graph options</summary>
      <form method="get" action="index.php" class="ac-options-body" data-ac-period>
        <input type="hidden" name="page" value="accounting-dashboard"><input type="hidden" name="selection" value="1"><input type="hidden" name="selection_mode" value="<?=e($p['mode'])?>">
        <div class="ac-row"><label class="ac-field">Period<select name="mode" data-ac-mode><?php foreach(HrDashboard::MODES as $m=>$l):?><option value="<?=$m?>" <?=$p['mode']===$m?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></label>
          <?php if($p['mode']!=='yearly'):?><label class="ac-field">Year<select name="year"><?php for($y=(int)date('Y');$y>=(int)date('Y')-5;$y--):?><option value="<?=$y?>" <?=$y===$p['year']?'selected':''?>><?=$y?></option><?php endfor;?></select></label><?php endif;?>
          <label class="ac-field">Bar style<select data-ac-barstyle><option value="extruded">Extruded</option><option value="deep">Deep</option><option value="floor">Floor</option><option value="flat">Flat</option></select></label></div>
        <fieldset class="ac-periods"><legend>Periods <span aria-live="polite">(<?=count($p['periods'])?> selected)</span></legend>
          <div class="ac-row"><button type="button" class="btn sm" data-ac-select="all">All</button><button type="button" class="btn sm" data-ac-select="clear">Clear</button></div>
          <div class="ac-period-options"><?php foreach($options as $n=>$l):?><label><input type="checkbox" name="periods[]" value="<?=$n?>" <?=in_array($n,$p['periods'],true)?'checked':''?>> <?=e((string)$l)?></label><?php endforeach;?></div></fieldset>
        <button class="btn primary" type="submit">Apply</button>
      </form></details>
      <div class="panel-body">
        <p class="ac-metric"><span>Income <strong class="ac-good"><?=Accounting::money($ti)?></strong></span><span>Expenses <strong><?=Accounting::money($tx)?></strong></span><span>Net income <strong class="<?=$ti-$tx<0?'ac-bad':'ac-good'?>"><?=Accounting::money($ti-$tx)?></strong></span></p>
        <ul class="ac-legend"><li><i class="ac-s-inc"></i>Income</li><li><i class="ac-s-exp"></i>Expenses</li></ul>
        <?php if(!$rows):?><div class="empty">No periods selected, or the selected periods have not started yet.</div>
        <?php else:?><div class="ac-scroll ac-chart-wrap" data-ac-chart data-style="extruded"><?=accounting_bar_svg($rows)?></div>
        <details class="ac-values"><summary>Period values (<?=count($rows)?>)</summary><div class="ac-scroll"><table class="tbl"><thead><tr><th>Period</th><th class="ac-num">Income</th><th class="ac-num">Expenses</th><th class="ac-num">Net income</th><th><span class="sr-only">Report</span></th></tr></thead><tbody>
          <?php foreach($rows as $r):?><tr><td><?=e($r['label'])?> <small class="ac-muted"><?=e(date('M j',strtotime($r['from'])).' to '.date('M j, Y',strtotime($r['to'])))?></small></td><td class="ac-num"><?=Accounting::money($r['income'])?></td><td class="ac-num"><?=Accounting::money($r['expense'])?></td><td class="ac-num <?=$r['net']<0?'ac-bad':''?>"><?=Accounting::money($r['net'])?></td>
            <td><a href="<?=url('accounting-reports',['report'=>'is','from'=>$r['from'],'to'=>$r['to']])?>">Income statement</a></td></tr><?php endforeach;?>
        </tbody></table></div></details><?php endif;?>
      </div></section>
    <?php accounting_close(true);
}

function accounting_dashboard_csv(array $k,array $p,array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="accounting-dashboard-'.$p['mode'].'-'.date('Y-m-d').'.csv"');
    $out=fopen('php://output','w'); $n=fn(int $c)=>number_format($c/100,2,'.','');
    fputcsv($out,['Accounting dashboard','Value']); fputcsv($out,['Mode',HrDashboard::MODES[$p['mode']]]); if($p['mode']!=='yearly') fputcsv($out,['Year',$p['year']]);
    fputcsv($out,['Live figures as of',date('Y-m-d H:i:s')]);
    foreach(['cash'=>'Bank balances (books)','ar'=>'Open receivables','ap'=>'Open payables'] as $key=>$l) fputcsv($out,[$l,$n($k[$key])]);
    fputcsv($out,['Waiting for approval',$k['pending']]); fputcsv($out,[]);
    fputcsv($out,['Period','From','To','Income','Expenses','Net income']);
    foreach($rows as $r) fputcsv($out,[$r['label'],$r['from'],$r['to'],$n($r['income']),$n($r['expense']),$n($r['net'])]);
    fclose($out);
}
