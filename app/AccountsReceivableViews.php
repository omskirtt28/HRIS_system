<?php
declare(strict_types=1);

// Accounts Receivable screens (migration 042). Mirror of AccountsPayableViews.php; shell and styles from AccountingViews.php / accounting.css.

function accounting_customers_page(array $customers): void
{
    $canEdit=Auth::can('accounting.entry');
    accounting_open('accounting-customers','Customers','<a class="btn" href="'.url('accounting-invoices').'">Invoices</a>'); ?>
    <?php if($canEdit):?>
    <section class="panel"><div class="panel-head"><div><h2>Add customer</h2><p>Clients you invoice. Payment terms set the default due date of a new invoice.</p></div></div>
      <form method="post" class="panel-body ac-form-grid"><?=csrf_field()?><input type="hidden" name="action" value="acct_customer_save"><input type="hidden" name="is_active" value="1">
        <?=accounting_vendor_fields(null,'Customer')?>
        <div class="ac-field ac-end"><button class="btn primary" type="submit">Add customer</button></div>
      </form></section>
    <?php endif;?>
    <section class="panel"><div class="panel-head"><div><h2>Customers</h2><p><?=count($customers)?> customers. Inactive customers keep their invoices but cannot be picked on new ones.</p></div></div>
    <div class="panel-body"><?php if(!$customers):?><div class="empty">No customers yet.<?=$canEdit?' Add the first one above.':''?></div><?php else:?>
    <div class="ac-scroll" role="region" aria-label="Customers" tabindex="0"><table class="tbl"><thead><tr><th>Name</th><th>TIN</th><th>Contact</th><th class="ac-num">Terms</th><th class="ac-num">Invoices</th><th>Status</th><?php if($canEdit):?><th>Edit</th><?php endif;?></tr></thead><tbody>
    <?php foreach($customers as $c):?><tr><td><?=e($c['name'])?></td><td><?=e($c['tin']??'')?></td><td><?=e($c['contact']??'')?></td><td class="ac-num"><?=(int)$c['terms_days']?> days</td><td class="ac-num"><?=(int)$c['invoices']?></td>
      <td><?=$c['is_active']?'<span class="badge green">Active</span>':'<span class="badge gray">Inactive</span>'?></td>
      <?php if($canEdit):?><td><details class="ac-edit"><summary class="btn sm">Edit</summary>
        <form method="post" class="ac-form-grid ac-pop"><?=csrf_field()?><input type="hidden" name="action" value="acct_customer_save"><input type="hidden" name="id" value="<?=(int)$c['id']?>">
          <?=accounting_vendor_fields($c,'Customer')?>
          <label class="ac-check"><input type="checkbox" name="is_active" value="1" <?=$c['is_active']?'checked':''?>> Active</label>
          <div class="ac-end"><button class="btn sm primary" type="submit">Save</button></div>
        </form></details></td><?php endif;?></tr>
    <?php endforeach;?></tbody></table></div><?php endif;?></div></section>
    <?php accounting_close();
}

function accounting_invoices_page(array $rows,array $counts,string $status): void
{
    $act='<a class="btn" href="'.url('accounting-invoices',['view'=>'aging']).'">AR aging</a>'.(Auth::can('accounting.entry')?' <a class="btn primary" href="'.url('accounting-invoice',['new'=>1]).'">New invoice</a>':'');
    accounting_open('accounting-invoices','Invoices',$act); ?>
    <nav class="ac-tabs" aria-label="Filter by status">
      <a href="<?=url('accounting-invoices')?>" <?=$status===''?'aria-current="page"':''?>>All <span><?=array_sum($counts)?></span></a>
      <?php foreach(Accounting::STATUS as $k=>$v):?><a href="<?=url('accounting-invoices',['status'=>$k])?>" <?=$status===$k?'aria-current="page"':''?>><?=e($v)?> <span><?=(int)$counts[$k]?></span></a><?php endforeach;?>
    </nav>
    <section class="panel"><div class="panel-body"><?php if(!$rows):?><div class="empty"><?=array_sum($counts)?'No invoices with this status.':'No invoices yet. Add customers first, then use New invoice.'?></div><?php else:?>
    <div class="ac-scroll" role="region" aria-label="Invoices" tabindex="0"><table class="tbl"><thead><tr><th>Invoice no.</th><th>Customer</th><th>Customer PO</th><th>Invoice date</th><th>Due</th><th class="ac-num">Total</th><th>Status</th><th>Entry</th></tr></thead><tbody>
    <?php foreach($rows as $i):?><tr><td><a href="<?=url('accounting-invoice',['id'=>$i['id']])?>"><?=e($i['invoice_no'])?></a></td><td><?=e($i['customer_name'])?></td><td><?=e($i['customer_ref']??'')?></td>
      <td><?=e(date('M j, Y',strtotime($i['invoice_date'])))?></td><td><?=e(date('M j, Y',strtotime($i['due_date'])))?></td><td class="ac-num"><?=Accounting::money((int)round($i['total']*100))?></td>
      <td><?=accounting_status_badge($i['status'])?><?php if($i['reversed_by_no']):?><small class="ac-muted ac-block">Reversed by <?=e($i['reversed_by_no'])?></small><?php endif;?></td>
      <td><a href="<?=url('accounting-entry',['id'=>$i['entry_id']])?>"><?=e($i['entry_no'])?></a></td></tr><?php endforeach;?>
    </tbody></table></div><?php if(count($rows)>=500):?><p class="ac-muted">Showing the latest 500 invoices.</p><?php endif;?><?php endif;?></div></section>
    <?php accounting_close();
}

function accounting_invoice_page(?array $inv,bool $editing,array $customers,array $accounts,?array $form): void
{
    accounting_open('accounting-invoices',$inv?$inv['invoice_no']:'New invoice','<a class="btn" href="'.url('accounting-invoices').'">Back to invoices</a>');
    if($editing) {
        $f=fn(string $k,string $d='')=>(string)($form[$k] ?? $inv[$k] ?? $d);
        $lines=is_array($form['lines']??null)?array_values($form['lines']):array_map(fn($l)=>['account_id'=>$l['account_id'],'description'=>$l['description'],'amount'=>$l['credit']],$inv['lines']??[]);
        while(count($lines)<3) $lines[]=[]; ?>
      <?php if($inv && $inv['return_note']):?><div class="ac-msg err">Returned by the approver: <?=e($inv['return_note'])?></div><?php endif;?>
      <?php if(!$customers):?><div class="ac-msg err">No active customers. <a href="<?=url('accounting-customers')?>">Add a customer</a> first.</div><?php endif;?>
      <form method="post" class="panel" id="acInvoiceForm"><?=csrf_field()?><input type="hidden" name="action" value="acct_invoice_save"><input type="hidden" name="id" value="<?=(int)($inv['id']??0)?>">
        <div class="panel-body ac-form-grid">
          <label class="ac-field">Customer<select name="customer_id" required><option value="">Choose customer</option><?php foreach($customers as $c):?><option value="<?=(int)$c['id']?>" <?=(int)$f('customer_id')===(int)$c['id']?'selected':''?>><?=e($c['name'])?> (<?=(int)$c['terms_days']?> days)</option><?php endforeach;?></select></label>
          <label class="ac-field">Customer PO no. <small>(optional)</small><input name="customer_ref" maxlength="50" value="<?=e($f('customer_ref'))?>"></label>
          <label class="ac-field">Invoice date<input type="date" name="invoice_date" required value="<?=e($f('invoice_date',date('Y-m-d')))?>"></label>
          <label class="ac-field">Due date <small>(blank = customer terms)</small><input type="date" name="due_date" value="<?=e($f('due_date'))?>"></label>
          <label class="ac-field ac-wide">Memo <small>(optional)</small><input name="memo" maxlength="150" value="<?=e($f('memo'))?>" placeholder="What was sold or billed?"></label>
          <?=accounting_tax_fields(false,is_array($form)?$form:[],$inv)?>
        </div>
        <div class="panel-body"><div class="ac-scroll"><table class="tbl ac-lines"><thead><tr><th>Income account</th><th>Description</th><th class="ac-num">Amount</th></tr></thead><tbody data-ac-lines>
        <?php foreach($lines as $i=>$l):?><tr>
          <td data-label="Account"><select name="lines[<?=$i?>][account_id]" aria-label="Account, line <?=$i+1?>"><option value="">Choose account</option><?php foreach($accounts as $a):?><option value="<?=(int)$a['id']?>" <?=(int)($l['account_id']??0)===(int)$a['id']?'selected':''?>><?=e($a['code'].' '.$a['name'])?></option><?php endforeach;?></select></td>
          <td data-label="Description"><input name="lines[<?=$i?>][description]" maxlength="255" value="<?=e($l['description']??'')?>" aria-label="Description, line <?=$i+1?>"></td>
          <td data-label="Amount"><input name="lines[<?=$i?>][amount]" inputmode="decimal" class="ac-num" data-ac-dr value="<?=e($l['amount']??'')?>" aria-label="Amount, line <?=$i+1?>"></td>
        </tr><?php endforeach;?></tbody>
        <tfoot><tr class="ac-total"><td colspan="2"><button type="button" class="btn sm" data-ac-add>Add line</button> <span class="ac-muted">Total is debited to <?=e(AccountsReceivable::AR_CODE)?> Accounts Receivable</span></td><td class="ac-num" data-ac-tdr>0.00</td></tr></tfoot></table></div>
        <div class="ac-row ac-actions"><span class="ac-spacer"></span><button class="btn" type="submit">Save draft</button><button class="btn primary" type="submit" name="submit_after" value="1">Save and submit</button></div></div>
      </form>
      <?php accounting_close(true); return;
    } ?>
    <?php if($inv['return_note'] && $inv['status']==='DRAFT'):?><div class="ac-msg err">Returned by the approver: <?=e($inv['return_note'])?></div><?php endif;?>
    <section class="panel"><div class="panel-head"><div><h2><?=e($inv['customer_name'])?><?=$inv['customer_ref']?' · PO '.e($inv['customer_ref']):''?></h2>
      <p>Invoice date <?=e(date('F j, Y',strtotime($inv['invoice_date'])))?> · Due <?=e(date('F j, Y',strtotime($inv['due_date'])))?> · Journal entry <a href="<?=url('accounting-entry',['id'=>$inv['entry_id']])?>"><?=e($inv['entry_no'])?></a></p></div><?=accounting_status_badge($inv['status'])?></div>
      <div class="panel-body">
      <?php if($inv['memo']):?><p class="ac-muted"><?=e($inv['memo'])?></p><?php endif;?>
      <?php if($inv['reversed_by_no']):?><div class="ac-msg err">Cancelled by reversing entry <?=e($inv['reversed_by_no'])?>; it is no longer in AR aging.</div><?php endif;?>
      <div class="ac-scroll"><table class="tbl"><thead><tr><th>Account</th><th>Description</th><th class="ac-num">Amount</th></tr></thead><tbody>
      <?php foreach($inv['lines'] as $l):?><tr><td><?=e($l['code'].' '.$l['name'])?></td><td><?=e($l['description']??'')?></td><td class="ac-num"><?=e(number_format((float)$l['credit'],2))?></td></tr><?php endforeach;?>
      <?=accounting_tax_rows($inv,false)?><tr class="ac-total"><td colspan="2">Total (debited to Accounts Receivable)</td><td class="ac-num"><?=Accounting::money((int)round($inv['total']*100))?></td></tr></tbody></table></div>
      <div class="ac-row ac-actions">
        <?php if($inv['status']==='DRAFT' && Auth::can('accounting.entry')):?><a class="btn" href="<?=url('accounting-invoice',['id'=>$inv['id'],'edit'=>1])?>">Edit</a><?php endif;?>
        <span class="ac-spacer"></span>
        <span class="ac-muted">Submit, approve, void and reverse on the journal entry.</span><a class="btn primary" href="<?=url('accounting-entry',['id'=>$inv['entry_id']])?>">Open <?=e($inv['entry_no'])?></a>
      </div></div></section>
    <?=accounting_doc_payments('invoice',$inv)?>
    <?php accounting_close();
}
