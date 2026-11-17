<?php
declare(strict_types=1);

/** VAT / EWT pickers for the bill and invoice forms; nothing is shown until tax codes exist (049, set up in Accounting Settings). */
function accounting_tax_fields(bool $bill,array $form,?array $doc): string
{
    $vat=AccountingSetup::codes('VAT',true); $ewt=$bill?AccountingSetup::codes('EWT',true):[];
    if(!$vat && !$ewt) return '';
    $sel=fn(string $k)=>(int)($form[$k] ?? $doc[$k] ?? 0);
    $h='';
    foreach([['vat_code_id','VAT',$vat],['ewt_code_id','Withholding (EWT)',$ewt]] as [$name,$label,$list]) {
        if(!$list) continue;
        $h.='<label class="ac-field">'.e($label).'<select name="'.$name.'"><option value="0">None</option>';
        foreach($list as $c) $h.='<option value="'.(int)$c['id'].'"'.($sel($name)===(int)$c['id']?' selected':'').'>'.e($c['code'].' · '.$c['description'].' · '.accounting_rate($c['rate'])).'</option>';
        $h.='</select></label>';
    }
    return $h.'<p class="ac-muted ac-wide">Line amounts are before VAT. '.($bill?'VAT is added and withholding is deducted from the amount payable to the vendor.':'VAT is added to the amount receivable.').'</p>';
}

function accounting_rate(mixed $r): string { return rtrim(rtrim(number_format((float)$r,4,'.',''),'0'),'.').'%'; }

/** Net / VAT / EWT rows under a saved bill or invoice. */
function accounting_tax_rows(array $d,bool $bill): string
{
    $vat=(int)round((float)($d['vat_amount']??0)*100); $ewt=(int)round((float)($d['ewt_amount']??0)*100);
    if(!$vat && !$ewt) return '';
    $net=(int)round($d['total']*100)-$vat+$ewt;
    $h='<tr><td colspan="2" class="ac-indent">Amount before tax</td><td class="ac-num">'.Accounting::money($net).'</td></tr>';
    if($vat) $h.='<tr><td colspan="2" class="ac-indent">'.($bill?'Input':'Output').' VAT ('.e(accounting_rate($d['vat_rate'])).')</td><td class="ac-num">'.Accounting::money($vat).'</td></tr>';
    if($ewt) $h.='<tr><td colspan="2" class="ac-indent">Less withholding tax ('.e(accounting_rate($d['ewt_rate'])).')</td><td class="ac-num">('.Accounting::money($ewt).')</td></tr>';
    return $h;
}

/** Phase 3 B3-B5: posting accounts, tax accounts and tax codes. Saving needs accounting.approve. */
function accounting_settings_page(array $settings,array $accounts,array $codes,array $payrollDefaults): void
{
    $can=Auth::can('accounting.approve');
    $opt=function(string $name,string $cur,string $type,string $placeholder) use($accounts,$can): string {
        $h='<select name="'.e($name).'"'.($can?'':' disabled').'><option value="">'.e($placeholder).'</option>';
        foreach($accounts as $a) if($a['is_active'] && ($type==='' || $a['type']===$type)) $h.='<option value="'.e($a['code']).'"'.($a['code']===$cur?' selected':'').'>'.e($a['code'].' '.$a['name']).'</option>';
        return $h.'</select>';
    };
    $net=$settings['payroll.net_to']??'bank';
    accounting_open('accounting-settings','Accounting Settings'); ?>
    <?php if(!$can):?><div class="ac-msg err">View only: changing settings needs the Accounting approve permission.</div><?php endif;?>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="acct_settings_save">
    <section class="panel"><div class="panel-head"><div><h2>Payroll posting</h2><p>Accounts used when a payroll run is released to Accounting. Blank uses the sample chart code shown.</p></div></div>
      <div class="panel-body ac-form-grid">
        <?php foreach(AccountingSetup::PAYROLL as $k=>$label):?><label class="ac-field"><?=e($label)?><?=$opt("payroll.$k",$settings["payroll.$k"]??'','','Default '.$payrollDefaults[$k])?></label><?php endforeach;?>
      </div>
      <div class="panel-body"><fieldset class="ac-periods"><legend>Net pay goes to</legend>
        <label class="ac-check"><input type="radio" name="payroll.net_to" value="bank" <?=$net==='bank'?'checked':''?> <?=$can?'':'disabled'?>> The payroll bank account (as before)</label>
        <label class="ac-check"><input type="radio" name="payroll.net_to" value="payable" <?=$net==='payable'?'checked':''?> <?=$can?'':'disabled'?>> Salaries Payable, then pay it with a journal entry (Dr Salaries Payable / Cr bank)</label>
        <label class="ac-field">Salaries Payable account<?=$opt('payroll.payable',$settings['payroll.payable']??'','L','Choose a liability account')?></label>
      </fieldset></div></section>
    <section class="panel"><div class="panel-head"><div><h2>Tax accounts</h2><p>Needed before a VAT or EWT code can be used on a bill or invoice. Add the accounts in the Chart of Accounts first if they are missing.</p></div></div>
      <div class="panel-body ac-form-grid">
        <?php foreach(AccountingSetup::TAX_ACCOUNTS as $k=>[$label,$type]):?><label class="ac-field"><?=e($label)?> (<?=e(Accounting::TYPES[$type])?>)<?=$opt($k,$settings[$k]??'',$type,'Not set')?></label><?php endforeach;?>
      </div>
      <?php if($can):?><div class="panel-body ac-row"><span class="ac-spacer"></span><button class="btn primary" type="submit">Save settings</button></div><?php endif;?></section>
    </form>
    <section class="panel"><div class="panel-head"><div><h2>Tax codes</h2><p>VAT and EWT (ATC) codes with the rates your accountant confirms. Nothing is preset. Each bill and invoice keeps the rate it was saved with.</p></div></div>
      <div class="panel-body"><div class="ac-scroll"><table class="tbl"><thead><tr><th>Type</th><th>Code</th><th>Description</th><th class="ac-num">Rate</th><th>Status</th><?php if($can):?><th><span class="sr-only">Edit</span></th><?php endif;?></tr></thead><tbody>
      <?php if(!$codes):?><tr><td colspan="6" class="ac-muted">No tax codes yet. Bills and invoices are saved without VAT or withholding.</td></tr><?php endif;?>
      <?php foreach($codes as $c):?><tr><td><?=e($c['kind'])?></td><td><?=e($c['code'])?></td><td><?=e($c['description'])?></td><td class="ac-num"><?=e(accounting_rate($c['rate']))?></td><td><?=$c['is_active']?'<span class="badge green">Active</span>':'<span class="badge gray">Inactive</span>'?></td>
        <?php if($can):?><td><details class="ac-edit"><summary class="btn sm">Edit</summary><?=accounting_tax_code_form($c)?></details></td><?php endif;?></tr><?php endforeach;?>
      </tbody></table></div>
      <?php if($can):?><details class="ac-edit ac-add-code"><summary class="btn">Add tax code</summary><?=accounting_tax_code_form(null)?></details><?php endif;?></div></section>
    <?php accounting_close();
}

function accounting_tax_code_form(?array $c): string
{
    $v=fn(string $k)=>e((string)($c[$k]??''));
    $h='<form method="post" class="ac-form-grid ac-pop">'.csrf_field().'<input type="hidden" name="action" value="acct_tax_code_save"><input type="hidden" name="id" value="'.(int)($c['id']??0).'">';
    $h.='<label class="ac-field">Type<select name="kind">';
    foreach(['VAT'=>'VAT','EWT'=>'EWT (withholding)'] as $k=>$l) $h.='<option value="'.$k.'"'.(($c['kind']??'VAT')===$k?' selected':'').'>'.$l.'</option>';
    $h.='</select></label><label class="ac-field">Code<input name="code" maxlength="20" required value="'.$v('code').'" placeholder="For EWT, the ATC"></label>';
    $h.='<label class="ac-field ac-wide">Description<input name="description" maxlength="150" required value="'.$v('description').'"></label>';
    $h.='<label class="ac-field">Rate (%)<input name="rate" inputmode="decimal" required value="'.e($c?rtrim(accounting_rate($c['rate']),'%'):'').'"></label>';
    $h.='<label class="ac-check"><input type="checkbox" name="is_active" value="1"'.(!$c || $c['is_active']?' checked':'').'> Active</label>';
    return $h.'<div class="ac-end"><button class="btn primary" type="submit">Save code</button></div></form>';
}

/** VAT summary by month for a year and the EWT list for a month. */
function accounting_tax_page(array $vat,int $year,array $ewt,string $month): void
{
    accounting_open('accounting-tax','Tax Reports');
    $tot=['sales'=>0,'output'=>0,'purchases'=>0,'input'=>0,'net'=>0]; foreach($vat as $r) foreach($tot as $k=>$_) $tot[$k]+=$r[$k]; ?>
    <div class="ac-msg err">Working figures from posted bills and invoices. BIR forms, filing deadlines and whether the company is VAT-registered are still to be confirmed with the accountant.</div>
    <section class="panel"><div class="panel-head"><div><h2>VAT summary <?=$year?></h2><p>Output VAT on posted invoices less input VAT on posted bills, by document date</p></div>
      <a class="btn" href="<?=url('accounting-tax',['year'=>$year,'month'=>substr($month,0,7),'csv'=>'vat'])?>">Download CSV</a></div>
      <form method="get" action="index.php" class="panel-body ac-row"><input type="hidden" name="page" value="accounting-tax"><input type="hidden" name="month" value="<?=e(substr($month,0,7))?>">
        <label class="ac-field">Year<select name="year"><?php for($y=(int)date('Y');$y>=(int)date('Y')-5;$y--):?><option value="<?=$y?>" <?=$y===$year?'selected':''?>><?=$y?></option><?php endfor;?></select></label><div class="ac-end"><button class="btn" type="submit">Show</button></div></form>
      <div class="panel-body"><div class="ac-scroll"><table class="tbl"><thead><tr><th>Month</th><th class="ac-num">Taxable sales</th><th class="ac-num">Output VAT</th><th class="ac-num">Taxable purchases</th><th class="ac-num">Input VAT</th><th class="ac-num">VAT payable</th></tr></thead><tbody>
      <?php foreach($vat as $m=>$r):?><tr><td><?=e(date('F',strtotime($m)))?></td><?php foreach(['sales','output','purchases','input','net'] as $k):?><td class="ac-num <?=$k==='net'&&$r[$k]<0?'ac-bad':''?>"><?=Accounting::money($r[$k])?></td><?php endforeach;?></tr><?php endforeach;?>
      <tr class="ac-total"><td>Total</td><?php foreach($tot as $v):?><td class="ac-num"><?=Accounting::money($v)?></td><?php endforeach;?></tr></tbody></table></div></div></section>
    <section class="panel"><div class="panel-head"><div><h2>Withholding tax on suppliers</h2><p>EWT withheld on posted bills in <?=e(date('F Y',strtotime($month)))?></p></div>
      <a class="btn" href="<?=url('accounting-tax',['year'=>$year,'month'=>substr($month,0,7),'csv'=>'ewt'])?>">Download CSV</a></div>
      <form method="get" action="index.php" class="panel-body ac-row"><input type="hidden" name="page" value="accounting-tax"><input type="hidden" name="year" value="<?=$year?>">
        <label class="ac-field">Month<input type="month" name="month" value="<?=e(substr($month,0,7))?>" max="<?=date('Y-m')?>"></label><div class="ac-end"><button class="btn" type="submit">Show</button></div></form>
      <div class="panel-body"><div class="ac-scroll"><table class="tbl"><thead><tr><th>Vendor</th><th>TIN</th><th>Bill</th><th>ATC</th><th class="ac-num">Base</th><th class="ac-num">Rate</th><th class="ac-num">Withheld</th></tr></thead><tbody>
      <?php if(!$ewt):?><tr><td colspan="7" class="ac-muted">No withholding on posted bills this month.</td></tr><?php endif; $sum=0;?>
      <?php foreach($ewt as $r): $sum+=(int)round($r['ewt_amount']*100);?><tr><td><?=e($r['vendor'])?></td><td><?=e((string)$r['tin'])?></td><td><a href="<?=url('accounting-bill',['id'=>(int)$r['id']])?>"><?=e($r['bill_no'])?></a></td><td><?=e($r['atc'])?></td>
        <td class="ac-num"><?=e(number_format((float)$r['base'],2))?></td><td class="ac-num"><?=e(accounting_rate($r['ewt_rate']))?></td><td class="ac-num"><?=e(number_format((float)$r['ewt_amount'],2))?></td></tr><?php endforeach;?>
      <?php if($ewt):?><tr class="ac-total"><td colspan="6">Total withheld</td><td class="ac-num"><?=Accounting::money($sum)?></td></tr><?php endif;?></tbody></table></div></div></section>
    <?php accounting_close();
}

function accounting_tax_csv(string $kind,array $vat,int $year,array $ewt,string $month): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.($kind==='vat'?"vat-summary-$year":'ewt-'.substr($month,0,7)).'.csv"');
    $out=fopen('php://output','w'); $n=fn(int $c)=>number_format($c/100,2,'.','');
    $safe=fn($s)=>is_string($s)&&preg_match('/^[=+\-@\t\r]/',$s)?"'".$s:$s; // user text never runs as an Excel formula
    $row=fn(array $r)=>fputcsv($out,array_map($safe,$r));
    if($kind==='vat') { $row(['Month','Taxable sales','Output VAT','Taxable purchases','Input VAT','VAT payable']); foreach($vat as $m=>$r) $row([substr($m,0,7),$n($r['sales']),$n($r['output']),$n($r['purchases']),$n($r['input']),$n($r['net'])]); }
    else { $row(['Vendor','TIN','Bill','Bill date','Supplier invoice','ATC','Base','Rate %','Withheld']); foreach($ewt as $r) $row([$r['vendor'],(string)$r['tin'],$r['bill_no'],$r['bill_date'],(string)$r['vendor_ref'],$r['atc'],$r['base'],$r['ewt_rate'],$r['ewt_amount']]); }
    fclose($out);
}
