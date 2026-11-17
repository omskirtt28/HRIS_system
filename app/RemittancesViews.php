<?php
declare(strict_types=1);

// Remittance screens (migration 044). Shell, badges, line editor and styles come from AccountingViews.php / accounting.css / accounting.js.

function accounting_remittances_page(array $rows,array $counts,string $status,int $year,array $grid): void
{
    accounting_open('accounting-remittances','Remittances',Auth::can('accounting.entry')?'<a class="btn primary" href="'.url('accounting-remittance',['new'=>1]).'">New remittance</a>':''); ?>
    <section class="panel"><div class="panel-head"><div><h2>Recorded by month, <?=$year?></h2>
      <p>Posted remittances per period. A blank cell means nothing is posted for that agency and month yet. Filing deadlines and BIR form numbers: <strong>To confirm</strong>.</p></div>
      <form method="get" class="ac-row"><input type="hidden" name="page" value="accounting-remittances"><?php if($status!==''):?><input type="hidden" name="status" value="<?=e($status)?>"><?php endif;?>
        <label class="ac-field">Year<input type="number" name="year" min="2000" max="<?=(int)date('Y')?>" value="<?=$year?>"></label><button class="btn" type="submit">Show</button></form></div>
    <div class="panel-body"><div class="ac-scroll" role="region" aria-label="Remittances recorded by month" tabindex="0"><table class="tbl"><thead><tr><th>Period</th><?php foreach(Remittances::AGENCIES as [$label]):?><th class="ac-num"><?=e($label)?></th><?php endforeach;?></tr></thead><tbody>
    <?php foreach($grid as $m=>$cells): if(sprintf('%04d-%02d',$year,$m)>date('Y-m')) break; ?><tr><td><?=e(date('F',mktime(0,0,0,$m,1)))?></td>
      <?php foreach($cells as $c):?><td class="ac-num"><?=$c?Accounting::money($c):'<span class="ac-muted">-</span>'?></td><?php endforeach;?></tr><?php endforeach;?>
    </tbody></table></div></div></section>
    <nav class="ac-tabs" aria-label="Filter by status">
      <a href="<?=url('accounting-remittances',['year'=>$year])?>" <?=$status===''?'aria-current="page"':''?>>All <span><?=array_sum($counts)?></span></a>
      <?php foreach(Accounting::STATUS as $k=>$v):?><a href="<?=url('accounting-remittances',['status'=>$k,'year'=>$year])?>" <?=$status===$k?'aria-current="page"':''?>><?=e($v)?> <span><?=(int)$counts[$k]?></span></a><?php endforeach;?>
    </nav>
    <section class="panel"><div class="panel-body"><?php if(!$rows):?><div class="empty"><?=array_sum($counts)?'No remittances with this status.':'No remittances yet. Set up a bank account first, then use New remittance.'?></div><?php else:?>
    <div class="ac-scroll" role="region" aria-label="Remittances" tabindex="0"><table class="tbl"><thead><tr><th>No.</th><th>Agency</th><th>Period</th><th>Paid on</th><th>Reference</th><th>Bank</th><th class="ac-num">Total</th><th>Status</th><th>Entry</th></tr></thead><tbody>
    <?php foreach($rows as $r):?><tr><td><a href="<?=url('accounting-remittance',['id'=>$r['id']])?>"><?=e($r['remittance_no'])?></a></td><td><?=e(Remittances::label($r['agency']))?></td>
      <td><?=e(date('M Y',strtotime($r['period_month'])))?></td><td><?=e(date('M j, Y',strtotime($r['pay_date'])))?></td><td><?=e($r['reference'])?></td><td><?=e($r['bank_name'])?></td>
      <td class="ac-num"><?=Accounting::money((int)round($r['total']*100))?></td>
      <td><?=accounting_status_badge($r['status'])?><?php if($r['reversed_by_no']):?><small class="ac-muted ac-block">Reversed by <?=e($r['reversed_by_no'])?></small><?php endif;?></td>
      <td><a href="<?=url('accounting-entry',['id'=>$r['entry_id']])?>"><?=e($r['entry_no'])?></a></td></tr><?php endforeach;?>
    </tbody></table></div><?php if(count($rows)>=500):?><p class="ac-muted">Showing the latest 500 remittances.</p><?php endif;?><?php endif;?></div></section>
    <?php accounting_close();
}

function accounting_remittance_page(?array $r,bool $editing,array $banks,array $accounts,?array $form): void
{
    accounting_open('accounting-remittances',$r?$r['remittance_no']:'New remittance','<a class="btn" href="'.url('accounting-remittances').'">Back to remittances</a>');
    if($editing) {
        $f=fn(string $k,string $d='')=>(string)($form[$k] ?? $r[$k] ?? $d);
        $agency=$f('agency','SSS');
        $period=(string)($form['period'] ?? (isset($r['period_month'])?substr($r['period_month'],0,7):date('Y-m',strtotime('first day of last month'))));
        $lines=is_array($form['lines']??null)?array_values($form['lines']):array_map(fn($l)=>['account_id'=>$l['account_id'],'description'=>$l['description'],'amount'=>$l['debit']],$r['lines']??[]);
        if(!$lines) $lines[]=['account_id'=>Remittances::defaultAccount($agency)]; // new: start on the agency's payable
        while(count($lines)<3) $lines[]=[]; ?>
      <?php if($r && $r['return_note']):?><div class="ac-msg err">Returned by the approver: <?=e($r['return_note'])?></div><?php endif;?>
      <?php if(!$banks):?><div class="ac-msg err">No active bank accounts. <a href="<?=url('accounting-banks')?>">Set up a bank account</a> first.</div><?php endif;?>
      <form method="post" class="panel" id="acRemitForm"><?=csrf_field()?><input type="hidden" name="action" value="acct_remittance_save"><input type="hidden" name="id" value="<?=(int)($r['id']??0)?>">
        <div class="panel-body ac-form-grid">
          <label class="ac-field">Agency<select name="agency" required><?php foreach(Remittances::AGENCIES as $k=>[$label]):?><option value="<?=$k?>" <?=$agency===$k?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
          <label class="ac-field">Period remitted<input type="month" name="period" required max="<?=date('Y-m')?>" value="<?=e($period)?>"></label>
          <label class="ac-field">Paid from<select name="bank_account_id" required><option value="">Choose bank account</option><?php foreach($banks as $b):?><option value="<?=(int)$b['id']?>" <?=(int)$f('bank_account_id')===(int)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></label>
          <label class="ac-field">Payment date<input type="date" name="pay_date" required value="<?=e($f('pay_date',date('Y-m-d')))?>"></label>
          <label class="ac-field">Reference no.<input name="reference" required maxlength="50" value="<?=e($f('reference'))?>" placeholder="PRN or payment reference"></label>
          <label class="ac-field ac-wide">Memo <small>(optional)</small><input name="memo" maxlength="150" value="<?=e($f('memo'))?>"></label>
        </div>
        <div class="panel-body"><p class="ac-muted">Filing deadline and form for this agency: To confirm.</p><div class="ac-scroll"><table class="tbl ac-lines"><thead><tr><th>Payable account</th><th>Description</th><th class="ac-num">Amount</th></tr></thead><tbody data-ac-lines>
        <?php foreach($lines as $i=>$l):?><tr>
          <td data-label="Account"><select name="lines[<?=$i?>][account_id]" aria-label="Account, line <?=$i+1?>"><option value="">Choose account</option><?php foreach($accounts as $a):?><option value="<?=(int)$a['id']?>" <?=(int)($l['account_id']??0)===(int)$a['id']?'selected':''?>><?=e($a['code'].' '.$a['name'])?></option><?php endforeach;?></select></td>
          <td data-label="Description"><input name="lines[<?=$i?>][description]" maxlength="255" value="<?=e($l['description']??'')?>" aria-label="Description, line <?=$i+1?>" placeholder="e.g. Employee and employer share"></td>
          <td data-label="Amount"><input name="lines[<?=$i?>][amount]" inputmode="decimal" class="ac-num" data-ac-dr value="<?=e($l['amount']??'')?>" aria-label="Amount, line <?=$i+1?>"></td>
        </tr><?php endforeach;?></tbody>
        <tfoot><tr class="ac-total"><td colspan="2"><button type="button" class="btn sm" data-ac-add>Add line</button> <span class="ac-muted">Total is credited to the bank account's ledger account</span></td><td class="ac-num" data-ac-tdr>0.00</td></tr></tfoot></table></div>
        <div class="ac-row ac-actions"><span class="ac-spacer"></span><button class="btn" type="submit">Save draft</button><button class="btn primary" type="submit" name="submit_after" value="1">Save and submit</button></div></div>
      </form>
      <?php accounting_close(true); return;
    } ?>
    <?php if($r['return_note'] && $r['status']==='DRAFT'):?><div class="ac-msg err">Returned by the approver: <?=e($r['return_note'])?></div><?php endif;?>
    <section class="panel"><div class="panel-head"><div><h2><?=e(Remittances::label($r['agency']))?> · <?=e(date('F Y',strtotime($r['period_month'])))?></h2>
      <p>Paid <?=e(date('F j, Y',strtotime($r['pay_date'])))?> from <?=e($r['bank_name'])?> · Ref. <?=e($r['reference'])?> · Journal entry <a href="<?=url('accounting-entry',['id'=>$r['entry_id']])?>"><?=e($r['entry_no'])?></a></p></div><?=accounting_status_badge($r['status'])?></div>
      <div class="panel-body">
      <?php if($r['memo']):?><p class="ac-muted"><?=e($r['memo'])?></p><?php endif;?>
      <?php if($r['reversed_by_no']):?><div class="ac-msg err">Cancelled by reversing entry <?=e($r['reversed_by_no'])?>; it no longer counts as remitted.</div><?php endif;?>
      <div class="ac-scroll"><table class="tbl"><thead><tr><th>Payable account</th><th>Description</th><th class="ac-num">Amount</th></tr></thead><tbody>
      <?php foreach($r['lines'] as $l):?><tr><td><?=e($l['code'].' '.$l['name'])?></td><td><?=e($l['description']??'')?></td><td class="ac-num"><?=e(number_format((float)$l['debit'],2))?></td></tr><?php endforeach;?>
      <tr class="ac-total"><td colspan="2">Total (credited to the bank)</td><td class="ac-num"><?=Accounting::money((int)round($r['total']*100))?></td></tr></tbody></table></div>
      <div class="ac-row ac-actions">
        <?php if($r['status']==='DRAFT' && Auth::can('accounting.entry')):?><a class="btn" href="<?=url('accounting-remittance',['id'=>$r['id'],'edit'=>1])?>">Edit</a><?php endif;?>
        <span class="ac-spacer"></span>
        <span class="ac-muted">Submit, approve, void and reverse on the journal entry.</span><a class="btn primary" href="<?=url('accounting-entry',['id'=>$r['entry_id']])?>">Open <?=e($r['entry_no'])?></a>
      </div></div></section>
    <?php accounting_close();
}
