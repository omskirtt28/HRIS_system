<?php
declare(strict_types=1);

// Cash and Bank screens (migration 043): bank accounts, payments, bank reconciliation. Shell and styles from AccountingViews.php / accounting.css.

/** Paid / open panel under a bill or invoice, with a shortcut to record the payment. */
function accounting_doc_payments(string $doc,array $row): string
{
    if($row['status']!=='POSTED' || $row['reversed_by_no']) return '';
    $pays=CashBank::docPayments($doc,(int)$row['id']);
    $paid=0; foreach($pays as $p) if($p['live']) $paid+=(int)round($p['amount']*100);
    $open=(int)round($row['total']*100)-$paid;
    [$kind,$verb,$party]=$doc==='bill'?['DISBURSEMENT','Pay this bill','vendor_id']:['COLLECTION','Record collection','customer_id'];
    ob_start(); ?>
    <section class="panel"><div class="panel-head"><div><h2><?=$doc==='bill'?'Payments':'Collections'?></h2>
      <p>Paid <?=Accounting::money($paid)?> · Open balance <strong><?=Accounting::money($open)?></strong>. Drafts and payments waiting for approval already hold their amount.</p></div>
      <?php if($open>0 && Auth::can('accounting.entry')):?><a class="btn primary" href="<?=url('accounting-payment',['new'=>$kind,'party'=>$row[$party]])?>"><?=$verb?></a><?php else:?><?=$open<=0?'<span class="badge green">Fully paid</span>':''?><?php endif;?></div>
    <?php if($pays):?><div class="panel-body"><div class="ac-scroll"><table class="tbl"><thead><tr><th>Payment</th><th>Date</th><th>Status</th><th class="ac-num">Amount</th></tr></thead><tbody>
      <?php foreach($pays as $p):?><tr><td><a href="<?=url('accounting-payment',['id'=>$p['id']])?>"><?=e($p['payment_no'])?></a></td><td><?=e(date('M j, Y',strtotime($p['pay_date'])))?></td>
        <td><?=accounting_status_badge($p['status'])?><?=!$p['live']&&$p['status']!=='VOID'?' <small class="ac-muted">Reversed</small>':''?></td><td class="ac-num"><?=Accounting::money((int)round($p['amount']*100))?></td></tr><?php endforeach;?>
    </tbody></table></div></div><?php endif;?></section>
    <?php return (string)ob_get_clean();
}

function accounting_bank_fields(?array $b,array $gl): string
{
    ob_start(); ?>
    <label class="ac-field">Account name<input name="name" required maxlength="100" value="<?=e($b['name']??'')?>" placeholder="e.g. BDO Payroll"></label>
    <label class="ac-field">Bank <small>(optional)</small><input name="bank_name" maxlength="100" value="<?=e($b['bank_name']??'')?>"></label>
    <label class="ac-field">Bank account no. <small>(optional)</small><input name="account_no" maxlength="30" pattern="[0-9 \-]{1,30}" inputmode="numeric" value="<?=e($b['account_no']??'')?>"></label>
    <label class="ac-field">Ledger account<select name="account_id" required><option value="">Choose asset account</option><?php foreach($gl as $a):?><option value="<?=(int)$a['id']?>" <?=(int)($b['account_id']??0)===(int)$a['id']?'selected':''?>><?=e($a['code'].' '.$a['name'])?></option><?php endforeach;?></select></label>
    <?php return (string)ob_get_clean();
}

function accounting_banks_page(array $banks,array $gl): void
{
    $canEdit=Auth::can('accounting.approve');
    accounting_open('accounting-banks','Bank Accounts','<a class="btn" href="'.url('accounting-recon').'">Bank reconciliation</a>'); ?>
    <?php if($canEdit):?>
    <section class="panel"><div class="panel-head"><div><h2>Add bank account</h2><p>Each bank account posts to its own asset account in the chart of accounts (for example 1020 Cash in Bank).</p></div></div>
      <form method="post" class="panel-body ac-form-grid"><?=csrf_field()?><input type="hidden" name="action" value="acct_bank_save"><input type="hidden" name="is_active" value="1">
        <?=accounting_bank_fields(null,$gl)?>
        <div class="ac-field ac-end"><button class="btn primary" type="submit" <?=$gl?'':'disabled'?>>Add bank account</button></div>
        <?php if(!$gl):?><p class="ac-muted ac-wide">Every active asset account is already used. Add one in the <a href="<?=url('accounting-coa')?>">chart of accounts</a> first.</p><?php endif;?>
      </form></section>
    <?php endif;?>
    <section class="panel"><div class="panel-head"><div><h2>Bank accounts</h2><p><?=count($banks)?> accounts. Inactive accounts keep their history but cannot be picked on new payments.</p></div></div>
    <div class="panel-body"><?php if(!$banks):?><div class="empty">No bank accounts yet.<?=$canEdit?' Add the first one above.':' An approver adds them.'?></div><?php else:?>
    <div class="ac-scroll" role="region" aria-label="Bank accounts" tabindex="0"><table class="tbl"><thead><tr><th>Name</th><th>Bank</th><th>Account no.</th><th>Ledger account</th><th class="ac-num">Payments</th><th>Last reconciled</th><th>Status</th><?php if($canEdit):?><th>Edit</th><?php endif;?></tr></thead><tbody>
    <?php foreach($banks as $b):?><tr><td><?=e($b['name'])?></td><td><?=e($b['bank_name']??'')?></td><td><?=e($b['account_no']??'')?></td><td><?=e($b['code'].' '.$b['gl_name'])?></td><td class="ac-num"><?=(int)$b['payments']?></td>
      <td><?php if($b['last_recon']):?><a href="<?=url('accounting-recon',['bank'=>$b['id']])?>"><?=e(date('F Y',strtotime($b['last_recon'])))?></a><?php else:?><a href="<?=url('accounting-recon',['bank'=>$b['id']])?>">Not yet</a><?php endif;?></td>
      <td><?=$b['is_active']?'<span class="badge green">Active</span>':'<span class="badge gray">Inactive</span>'?></td>
      <?php if($canEdit):?><td><details class="ac-edit"><summary class="btn sm">Edit</summary>
        <form method="post" class="ac-form-grid ac-pop"><?=csrf_field()?><input type="hidden" name="action" value="acct_bank_save"><input type="hidden" name="id" value="<?=(int)$b['id']?>">
          <?=accounting_bank_fields($b,CashBank::cashAccounts((int)$b['id']))?>
          <label class="ac-check"><input type="checkbox" name="is_active" value="1" <?=$b['is_active']?'checked':''?>> Active</label>
          <div class="ac-end"><button class="btn sm primary" type="submit">Save</button></div>
        </form></details></td><?php endif;?></tr>
    <?php endforeach;?></tbody></table></div><?php endif;?></div></section>
    <?php accounting_close();
}

function accounting_payments_page(string $kind,array $rows,array $counts,string $status): void
{
    $other=$kind==='DISBURSEMENT'?'COLLECTION':'DISBURSEMENT';
    $act=Auth::can('accounting.entry')?'<a class="btn primary" href="'.url('accounting-payment',['new'=>$kind]).'">New '.($kind==='DISBURSEMENT'?'disbursement':'collection').'</a>':'';
    accounting_open('accounting-payments','Payments',$act); ?>
    <nav class="ac-tabs" aria-label="Payment type">
      <a href="<?=url('accounting-payments',['kind'=>'DISBURSEMENT'])?>" <?=$kind==='DISBURSEMENT'?'aria-current="page"':''?>>Disbursements (bills paid)</a>
      <a href="<?=url('accounting-payments',['kind'=>'COLLECTION'])?>" <?=$kind==='COLLECTION'?'aria-current="page"':''?>>Collections (invoices collected)</a>
    </nav>
    <nav class="ac-tabs" aria-label="Filter by status">
      <a href="<?=url('accounting-payments',['kind'=>$kind])?>" <?=$status===''?'aria-current="page"':''?>>All <span><?=array_sum($counts)?></span></a>
      <?php foreach(Accounting::STATUS as $k=>$v):?><a href="<?=url('accounting-payments',['kind'=>$kind,'status'=>$k])?>" <?=$status===$k?'aria-current="page"':''?>><?=e($v)?> <span><?=(int)$counts[$k]?></span></a><?php endforeach;?>
    </nav>
    <section class="panel"><div class="panel-body"><?php if(!$rows):?><div class="empty"><?=array_sum($counts)?'No payments with this status.':($kind==='DISBURSEMENT'?'No disbursements yet. Pay a posted bill with New disbursement.':'No collections yet. Record money received on a posted invoice with New collection.')?></div><?php else:?>
    <div class="ac-scroll" role="region" aria-label="Payments" tabindex="0"><table class="tbl"><thead><tr><th>No.</th><th><?=$kind==='DISBURSEMENT'?'Vendor':'Customer'?></th><th>Bank account</th><th>Date</th><th>Reference</th><th class="ac-num">Amount</th><th>Status</th><th>Entry</th></tr></thead><tbody>
    <?php foreach($rows as $p):?><tr><td><a href="<?=url('accounting-payment',['id'=>$p['id']])?>"><?=e($p['payment_no'])?></a></td><td><?=e($p['party_name'])?></td><td><?=e($p['bank_name'])?></td>
      <td><?=e(date('M j, Y',strtotime($p['pay_date'])))?></td><td><?=e($p['reference']??'')?></td><td class="ac-num"><?=Accounting::money((int)round($p['total']*100))?></td>
      <td><?=accounting_status_badge($p['status'])?><?php if($p['reversed_by_no']):?><small class="ac-muted ac-block">Reversed by <?=e($p['reversed_by_no'])?></small><?php endif;?></td>
      <td><a href="<?=url('accounting-entry',['id'=>$p['entry_id']])?>"><?=e($p['entry_no'])?></a></td></tr><?php endforeach;?>
    </tbody></table></div><?php if(count($rows)>=500):?><p class="ac-muted">Showing the latest 500 payments.</p><?php endif;?><?php endif;?></div></section>
    <?php accounting_close();
}

function accounting_payment_page(?array $p,bool $editing,string $kind,int $partyId,array $parties,array $banks,array $docs,?array $form): void
{
    [$prefix,$label,$partyWord,$doc]=CashBank::KINDS[$kind];
    $docs_=$doc.'s'; $Party=ucfirst($partyWord);
    accounting_open('accounting-payments',$p?$p['payment_no']:'New '.strtolower($label),'<a class="btn" href="'.url('accounting-payments',['kind'=>$kind]).'">Back to payments</a>');
    if($editing) {
        $f=fn(string $k,string $d='')=>(string)($form[$k] ?? $p[$k] ?? $d);
        if(!$p && !$partyId) { // step 1: pick who
            ?>
      <?php if(!$parties):?><div class="ac-msg err">No active <?=$partyWord?>s. <a href="<?=url($kind==='DISBURSEMENT'?'accounting-vendors':'accounting-customers')?>">Add a <?=$partyWord?></a> first.</div><?php endif;?>
      <section class="panel"><div class="panel-head"><div><h2><?=$kind==='DISBURSEMENT'?'Who are you paying?':'Who paid you?'?></h2><p>Next you choose which posted <?=$docs_?> this <?=strtolower($label)?> settles, in full or in part.</p></div></div>
      <form method="get" class="panel-body ac-row"><input type="hidden" name="page" value="accounting-payment"><input type="hidden" name="new" value="<?=e($kind)?>">
        <label class="ac-field"><?=$Party?><select name="party" required><option value="">Choose <?=$partyWord?></option><?php foreach($parties as $x):?><option value="<?=(int)$x['id']?>"><?=e($x['name'])?></option><?php endforeach;?></select></label>
        <button class="btn primary" type="submit">Show open <?=$docs_?></button></form></section>
      <?php accounting_close(); return;
        }
        $partyName=''; foreach($parties as $x) if((int)$x['id']===$partyId) $partyName=$x['name'];
        if($p) $partyName=$p['party_name'];
        $amounts=is_array($form['amounts']??null)?$form['amounts']:null; ?>
      <?php if($p && $p['return_note']):?><div class="ac-msg err">Returned by the approver: <?=e($p['return_note'])?></div><?php endif;?>
      <?php if(!$banks):?><div class="ac-msg err">No active bank accounts. <?=Auth::can('accounting.approve')?'<a href="'.url('accounting-banks').'">Add a bank account</a> first.':'Ask an approver to add one.'?></div><?php endif;?>
      <?php if($partyName===''):?><div class="ac-msg err">That <?=$partyWord?> is not active. <a href="<?=url('accounting-payment',['new'=>$kind])?>">Choose another</a>.</div><?php accounting_close(); return; endif;?>
      <form method="post" class="panel" id="acPayForm"><?=csrf_field()?><input type="hidden" name="action" value="acct_payment_save"><input type="hidden" name="id" value="<?=(int)($p['id']??0)?>">
        <input type="hidden" name="kind" value="<?=e($kind)?>"><input type="hidden" name="party_id" value="<?=$partyId?>">
        <div class="panel-head"><div><h2><?=e($partyName)?></h2><p><?=$p?'The '.$partyWord.' of a saved payment cannot change.':'<a href="'.url('accounting-payment',['new'=>$kind]).'">Choose a different '.$partyWord.'</a>'?></p></div></div>
        <div class="panel-body ac-form-grid">
          <label class="ac-field">Bank account<select name="bank_account_id" required><option value="">Choose bank account</option><?php foreach($banks as $b):?><option value="<?=(int)$b['id']?>" <?=(int)$f('bank_account_id')===(int)$b['id']?'selected':''?>><?=e($b['name'])?> (<?=e($b['code'])?>)</option><?php endforeach;?></select></label>
          <label class="ac-field"><?=$kind==='DISBURSEMENT'?'Payment date':'Date received'?><input type="date" name="pay_date" required value="<?=e($f('pay_date',date('Y-m-d')))?>"></label>
          <label class="ac-field"><?=$kind==='DISBURSEMENT'?'Check or transfer no.':'Receipt or deposit ref.'?> <small>(optional)</small><input name="reference" maxlength="50" value="<?=e($f('reference'))?>"></label>
          <label class="ac-field">Memo <small>(optional)</small><input name="memo" maxlength="150" value="<?=e($f('memo'))?>"></label>
        </div>
        <div class="panel-body"><?php if(!$docs):?><div class="empty">No open posted <?=$docs_?> for this <?=$partyWord?>. Only <?=$docs_?> that are posted and not fully paid can be <?=$kind==='DISBURSEMENT'?'paid':'collected'?>.</div><?php else:?>
        <div class="ac-scroll" role="region" aria-label="Open <?=$docs_?>" tabindex="0"><table class="tbl"><thead><tr><th><?=ucfirst($doc)?></th><th>Date</th><th>Due</th><th class="ac-num">Total</th><th class="ac-num">Open balance</th><th class="ac-num">Amount to apply</th></tr></thead><tbody>
        <?php foreach($docs as $d): $avail=$d['open'];
            $val=$amounts!==null?(string)($amounts[$d['id']]??''):($d['this']?number_format($d['this']/100,2,'.',''):''); ?>
          <tr><td><a href="<?=url('accounting-'.$doc,['id'=>$d['id']])?>"><?=e($d['doc_no'])?></a></td><td><?=e(date('M j, Y',strtotime($d['doc_date'])))?></td><td><?=e(date('M j, Y',strtotime($d['due_date'])))?></td>
            <td class="ac-num"><?=Accounting::money($d['total'])?></td><td class="ac-num"><?=Accounting::money($avail)?></td>
            <td data-label="Amount" class="ac-num"><input name="amounts[<?=$d['id']?>]" inputmode="decimal" class="ac-num" data-ac-amt value="<?=e($val)?>" placeholder="0.00" aria-label="Amount to apply to <?=e($d['doc_no'])?>">
              <button type="button" class="btn sm" data-ac-fill="<?=e(number_format($avail/100,2,'.',''))?>" aria-label="Apply the full open balance of <?=e($d['doc_no'])?>">Full</button></td></tr>
        <?php endforeach;?></tbody>
        <tfoot><tr class="ac-total"><td colspan="5"><?=$kind==='DISBURSEMENT'?'Total paid from the bank (Dr Accounts Payable / Cr bank)':'Total deposited to the bank (Dr bank / Cr Accounts Receivable)'?></td><td class="ac-num" data-ac-ptotal>0.00</td></tr></tfoot></table></div><?php endif;?>
        <div class="ac-row ac-actions"><span class="ac-spacer"></span><button class="btn" type="submit" <?=$docs&&$banks?'':'disabled'?>>Save draft</button><button class="btn primary" type="submit" name="submit_after" value="1" <?=$docs&&$banks?'':'disabled'?>>Save and submit</button></div></div>
      </form>
      <?php accounting_close(true); return;
    } ?>
    <?php if($p['return_note'] && $p['status']==='DRAFT'):?><div class="ac-msg err">Returned by the approver: <?=e($p['return_note'])?></div><?php endif;?>
    <section class="panel"><div class="panel-head"><div><h2><?=e($label)?> · <?=e($p['party_name'])?></h2>
      <p><?=e(date('F j, Y',strtotime($p['pay_date'])))?> · <?=e($p['bank_name'])?><?=$p['reference']?' · ref. '.e($p['reference']):''?> · Journal entry <a href="<?=url('accounting-entry',['id'=>$p['entry_id']])?>"><?=e($p['entry_no'])?></a></p></div><?=accounting_status_badge($p['status'])?></div>
      <div class="panel-body">
      <?php if($p['memo']):?><p class="ac-muted"><?=e($p['memo'])?></p><?php endif;?>
      <?php if($p['reversed_by_no']):?><div class="ac-msg err">Cancelled by reversing entry <?=e($p['reversed_by_no'])?>; the <?=$docs_?> below are open again.</div><?php endif;?>
      <div class="ac-scroll"><table class="tbl"><thead><tr><th><?=ucfirst($doc)?></th><th class="ac-num"><?=ucfirst($doc)?> total</th><th class="ac-num">Applied</th></tr></thead><tbody>
      <?php foreach($p['allocations'] as $a):?><tr><td><a href="<?=url('accounting-'.$doc,['id'=>$a['doc_id']])?>"><?=e($a['doc_no'])?></a></td><td class="ac-num"><?=Accounting::money((int)round($a['total']*100))?></td><td class="ac-num"><?=Accounting::money((int)round($a['amount']*100))?></td></tr><?php endforeach;?>
      <tr class="ac-total"><td colspan="2">Total</td><td class="ac-num"><?=Accounting::money((int)round($p['total']*100))?></td></tr></tbody></table></div>
      <div class="ac-row ac-actions">
        <?php if($p['status']==='DRAFT' && Auth::can('accounting.entry')):?><a class="btn" href="<?=url('accounting-payment',['id'=>$p['id'],'edit'=>1])?>">Edit</a><?php endif;?>
        <span class="ac-spacer"></span>
        <span class="ac-muted">Submit, approve, void and reverse on the journal entry.</span><a class="btn primary" href="<?=url('accounting-entry',['id'=>$p['entry_id']])?>">Open <?=e($p['entry_no'])?></a>
      </div></div></section>
    <?php accounting_close();
}

function accounting_recon_page(array $banks,?array $bank,string $month,?array $d,?array $form): void
{
    accounting_open('accounting-recon','Bank Reconciliation','<a class="btn" href="'.url('accounting-banks').'">Bank accounts</a>');
    if(!$bank) { ?><section class="panel"><div class="panel-body"><div class="empty">No bank accounts yet. <a href="<?=url('accounting-banks')?>">Add a bank account</a> first.</div></div></section><?php accounting_close(); return; } ?>
    <section class="panel"><form method="get" class="panel-body ac-row"><input type="hidden" name="page" value="accounting-recon">
      <label class="ac-field">Bank account<select name="bank"><?php foreach($banks as $b):?><option value="<?=(int)$b['id']?>" <?=(int)$b['id']===(int)$bank['id']?'selected':''?>><?=e($b['name'])?><?=$b['is_active']?'':' (inactive)'?></option><?php endforeach;?></select></label>
      <label class="ac-field">Statement month<input type="month" name="month" max="<?=date('Y-m')?>" value="<?=e($month)?>"></label>
      <button class="btn" type="submit">Show</button></form></section>
    <?php $end=date('F j, Y',strtotime($d['end']));
    if($d['done']): $r=$d['done']; ?>
    <section class="panel"><div class="panel-head"><div><h2><?=e(date('F Y',strtotime($month)))?> is reconciled</h2>
      <p>Statement balance <?=Accounting::money((int)round($r['statement_balance']*100))?> · book balance on <?=e($end)?> <?=Accounting::money((int)round($r['book_balance']*100))?> · <?=(int)$r['line_count']?> lines cleared<?=$r['reconciled_by']?' by '.e($r['reconciled_by']):''?> on <?=e(date('M j, Y',strtotime($r['reconciled_at'])))?>.</p></div><span class="badge green">Reconciled</span></div></section>
    <?php elseif($d['last'] && $d['last']>$d['month']): ?>
    <div class="ac-msg err"><?=e(date('F Y',strtotime($d['last'])))?> is already reconciled, so earlier months are closed for reconciliation. Undo the later months first to redo this one.</div>
    <?php else:
        $ticked=is_array($form['cleared']??null)?array_flip(array_map('intval',$form['cleared'])):[]; $canEntry=Auth::can('accounting.entry'); ?>
    <form method="post" class="panel" id="acReconForm" data-cleared="<?=$d['cleared']?>"><?=csrf_field()?><input type="hidden" name="action" value="acct_recon_finish">
      <input type="hidden" name="bank_account_id" value="<?=(int)$bank['id']?>"><input type="hidden" name="month" value="<?=e($month)?>">
      <div class="panel-head"><div><h2><?=e($bank['name'])?> · <?=e(date('F Y',strtotime($month)))?></h2>
        <p>Tick every line that appears on the bank statement up to <?=e($end)?>. The cleared balance must equal the statement's ending balance. Book balance on <?=e($end)?>: <strong><?=Accounting::money($d['book'])?></strong> (<?=e($bank['code'].' '.$bank['gl_name'])?>).</p></div></div>
      <div class="panel-body ac-form-grid">
        <label class="ac-field">Statement ending balance<input name="statement_balance" inputmode="decimal" class="ac-num" required data-ac-stmt value="<?=e((string)($form['statement_balance']??''))?>" placeholder="0.00"></label>
        <div class="ac-field"><span>Cleared before this month</span><strong class="ac-num"><?=Accounting::money($d['cleared'])?></strong></div>
        <div class="ac-field"><span>Cleared balance</span><strong class="ac-num" data-ac-cleared><?=Accounting::money($d['cleared'])?></strong></div>
        <div class="ac-field"><span>Difference</span><strong class="ac-num" data-ac-rdiff aria-live="polite">-</strong></div>
      </div>
      <div class="panel-body"><?php if(!$d['lines']):?><div class="empty">No uncleared posted lines on this account up to <?=e($end)?>. If the statement balance equals the cleared balance, finish with no lines ticked.</div><?php else:?>
      <div class="ac-scroll" role="region" aria-label="Uncleared lines" tabindex="0"><table class="tbl"><thead><tr><th><span class="sr-only">Cleared</span></th><th>Date</th><th>Entry</th><th>Description</th><th class="ac-num">Deposit</th><th class="ac-num">Withdrawal</th></tr></thead><tbody>
      <?php foreach($d['lines'] as $l): $net=(int)round($l['debit']*100)-(int)round($l['credit']*100); ?>
        <tr><td><input type="checkbox" name="cleared[]" value="<?=(int)$l['id']?>" data-amt="<?=$net?>" <?=isset($ticked[(int)$l['id']])?'checked':''?> aria-label="Cleared: <?=e($l['entry_no'])?> <?=e(Accounting::money($net))?>"></td>
          <td><?=e(date('M j, Y',strtotime($l['entry_date'])))?></td><td><a href="<?=url('accounting-entry',['id'=>$l['entry_id']])?>"><?=e($l['entry_no'])?></a></td><td><?=e($l['description'] ?: $l['memo'])?></td>
          <td class="ac-num"><?=(float)$l['debit']?e(number_format((float)$l['debit'],2)):''?></td><td class="ac-num"><?=(float)$l['credit']?e(number_format((float)$l['credit'],2)):''?></td></tr>
      <?php endforeach;?></tbody></table></div><?php endif;?>
      <div class="ac-row ac-actions"><span class="ac-spacer"></span><?php if($canEntry):?><button class="btn primary" type="submit">Finish reconciliation</button><?php else:?><span class="ac-muted">Your role can view only.</span><?php endif;?></div></div>
    </form>
    <?php endif; ?>
    <section class="panel"><div class="panel-head"><div><h2>History</h2><p>Months are reconciled in order. Only the latest month can be undone.</p></div></div>
    <div class="panel-body"><?php if(!$d['history']):?><div class="empty">No reconciled months yet for this account.</div><?php else:?>
    <div class="ac-scroll" role="region" aria-label="Reconciliation history" tabindex="0"><table class="tbl"><thead><tr><th>Month</th><th class="ac-num">Statement</th><th class="ac-num">Book</th><th class="ac-num">Lines</th><th>By</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
    <?php foreach($d['history'] as $i=>$r):?><tr><td><a href="<?=url('accounting-recon',['bank'=>$bank['id'],'month'=>substr($r['period_month'],0,7)])?>"><?=e(date('F Y',strtotime($r['period_month'])))?></a></td>
      <td class="ac-num"><?=Accounting::money((int)round($r['statement_balance']*100))?></td><td class="ac-num"><?=Accounting::money((int)round($r['book_balance']*100))?></td><td class="ac-num"><?=(int)$r['line_count']?></td><td><?=e($r['reconciled_by']??'')?></td>
      <td><?php if($i===0 && Auth::can('accounting.approve')):?><form method="post" onsubmit="return confirm('Undo the <?=e(date('F Y',strtotime($r['period_month'])))?> reconciliation? Its lines become uncleared again.')"><?=csrf_field()?><input type="hidden" name="action" value="acct_recon_undo"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn sm" type="submit">Undo</button></form><?php endif;?></td></tr>
    <?php endforeach;?></tbody></table></div><?php endif;?></div></section>
    <?php accounting_close(true);
}
