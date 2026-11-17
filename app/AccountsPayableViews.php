<?php
declare(strict_types=1);

// Accounts Payable screens (migration 041). Shell, badges and styles come from AccountingViews.php / accounting.css.

function accounting_vendor_fields(?array $v,string $label='Vendor'): string
{
    ob_start(); ?>
    <label class="ac-field"><?=e($label)?> name<input name="name" required maxlength="150" value="<?=e($v['name']??'')?>"></label>
    <label class="ac-field">TIN <small>(optional)</small><input name="tin" maxlength="20" pattern="[0-9\-]{1,20}" inputmode="numeric" value="<?=e($v['tin']??'')?>"></label>
    <label class="ac-field">Contact <small>(optional)</small><input name="contact" maxlength="150" value="<?=e($v['contact']??'')?>" placeholder="Name, phone or email"></label>
    <label class="ac-field">Payment terms (days)<input type="number" name="terms_days" min="0" max="365" required value="<?=(int)($v['terms_days']??30)?>"></label>
    <?php return (string)ob_get_clean();
}

function accounting_vendors_page(array $vendors): void
{
    $canEdit=Auth::can('accounting.entry');
    accounting_open('accounting-vendors','Vendors','<a class="btn" href="'.url('accounting-bills').'">Bills</a>'); ?>
    <?php if($canEdit):?>
    <section class="panel"><div class="panel-head"><div><h2>Add vendor</h2><p>Suppliers you receive bills from. Payment terms set the default due date of a new bill.</p></div></div>
      <form method="post" class="panel-body ac-form-grid"><?=csrf_field()?><input type="hidden" name="action" value="acct_vendor_save"><input type="hidden" name="is_active" value="1">
        <?=accounting_vendor_fields(null)?>
        <div class="ac-field ac-end"><button class="btn primary" type="submit">Add vendor</button></div>
      </form></section>
    <?php endif;?>
    <section class="panel"><div class="panel-head"><div><h2>Vendors</h2><p><?=count($vendors)?> vendors. Inactive vendors keep their bills but cannot be picked on new ones.</p></div></div>
    <div class="panel-body"><?php if(!$vendors):?><div class="empty">No vendors yet.<?=$canEdit?' Add the first one above.':''?></div><?php else:?>
    <div class="ac-scroll" role="region" aria-label="Vendors" tabindex="0"><table class="tbl"><thead><tr><th>Name</th><th>TIN</th><th>Contact</th><th class="ac-num">Terms</th><th class="ac-num">Bills</th><th>Status</th><?php if($canEdit):?><th>Edit</th><?php endif;?></tr></thead><tbody>
    <?php foreach($vendors as $v):?><tr><td><?=e($v['name'])?></td><td><?=e($v['tin']??'')?></td><td><?=e($v['contact']??'')?></td><td class="ac-num"><?=(int)$v['terms_days']?> days</td><td class="ac-num"><?=(int)$v['bills']?></td>
      <td><?=$v['is_active']?'<span class="badge green">Active</span>':'<span class="badge gray">Inactive</span>'?></td>
      <?php if($canEdit):?><td><details class="ac-edit"><summary class="btn sm">Edit</summary>
        <form method="post" class="ac-form-grid ac-pop"><?=csrf_field()?><input type="hidden" name="action" value="acct_vendor_save"><input type="hidden" name="id" value="<?=(int)$v['id']?>">
          <?=accounting_vendor_fields($v)?>
          <label class="ac-check"><input type="checkbox" name="is_active" value="1" <?=$v['is_active']?'checked':''?>> Active</label>
          <div class="ac-end"><button class="btn sm primary" type="submit">Save</button></div>
        </form></details></td><?php endif;?></tr>
    <?php endforeach;?></tbody></table></div><?php endif;?></div></section>
    <?php accounting_close();
}

function accounting_bills_page(array $rows,array $counts,string $status): void
{
    $act='<a class="btn" href="'.url('accounting-bills',['view'=>'aging']).'">AP aging</a>'.(Auth::can('accounting.entry')?' <a class="btn primary" href="'.url('accounting-bill',['new'=>1]).'">New bill</a>':'');
    accounting_open('accounting-bills','Bills',$act); ?>
    <nav class="ac-tabs" aria-label="Filter by status">
      <a href="<?=url('accounting-bills')?>" <?=$status===''?'aria-current="page"':''?>>All <span><?=array_sum($counts)?></span></a>
      <?php foreach(Accounting::STATUS as $k=>$v):?><a href="<?=url('accounting-bills',['status'=>$k])?>" <?=$status===$k?'aria-current="page"':''?>><?=e($v)?> <span><?=(int)$counts[$k]?></span></a><?php endforeach;?>
    </nav>
    <section class="panel"><div class="panel-body"><?php if(!$rows):?><div class="empty"><?=array_sum($counts)?'No bills with this status.':'No bills yet. Add vendors first, then use New bill.'?></div><?php else:?>
    <div class="ac-scroll" role="region" aria-label="Bills" tabindex="0"><table class="tbl"><thead><tr><th>Bill no.</th><th>Vendor</th><th>Supplier inv.</th><th>Bill date</th><th>Due</th><th class="ac-num">Total</th><th>Status</th><th>Entry</th></tr></thead><tbody>
    <?php foreach($rows as $b):?><tr><td><a href="<?=url('accounting-bill',['id'=>$b['id']])?>"><?=e($b['bill_no'])?></a></td><td><?=e($b['vendor_name'])?></td><td><?=e($b['vendor_ref']??'')?></td>
      <td><?=e(date('M j, Y',strtotime($b['bill_date'])))?></td><td><?=e(date('M j, Y',strtotime($b['due_date'])))?></td><td class="ac-num"><?=Accounting::money((int)round($b['total']*100))?></td>
      <td><?=accounting_status_badge($b['status'])?><?php if($b['reversed_by_no']):?><small class="ac-muted ac-block">Reversed by <?=e($b['reversed_by_no'])?></small><?php endif;?></td>
      <td><a href="<?=url('accounting-entry',['id'=>$b['entry_id']])?>"><?=e($b['entry_no'])?></a></td></tr><?php endforeach;?>
    </tbody></table></div><?php if(count($rows)>=500):?><p class="ac-muted">Showing the latest 500 bills.</p><?php endif;?><?php endif;?></div></section>
    <?php accounting_close();
}

function accounting_aging_page(array $d,string $asOf,bool $ar=false): void
{
    // AP (bills) and AR (invoices) aging share this page; $ar picks the words and the list it belongs to.
    [$pg,$doc,$who,$key,$open,$paid]=$ar?['accounting-invoices','invoices','Customer','customer','receivables','Collections']:['accounting-bills','bills','Vendor','vendor','payables','Payments'];
    accounting_open($pg,($ar?'AR':'AP').' Aging','<a class="btn" href="'.url($pg).'">Back to '.$doc.'</a>'); ?>
    <section class="panel"><form method="get" class="panel-body ac-row"><input type="hidden" name="page" value="<?=e($pg)?>"><input type="hidden" name="view" value="aging">
      <label class="ac-field">As of<input type="date" name="to" value="<?=e($asOf)?>"></label><button class="btn" type="submit">Show</button></form>
    <div class="panel-body"><p class="ac-muted">Open <?=$open?> by days past the due date · posted <?=$doc?> only · as of <?=e(date('M j, Y',strtotime($asOf)))?>. Posted <?=strtolower($paid)?> by that date are subtracted; fully settled <?=$doc?> drop out.</p>
    <div class="ac-scroll" role="region" aria-label="<?=$ar?'AR':'AP'?> aging" tabindex="0"><table class="tbl"><thead><tr><th><?=$who?></th><?php foreach(AccountsPayable::BUCKETS as $l):?><th class="ac-num"><?=e($l)?></th><?php endforeach;?><th class="ac-num">Total</th></tr></thead><tbody>
    <?php if(!$d['rows']):?><tr><td colspan="7" class="ac-muted">No open posted <?=$doc?> as of this date.</td></tr><?php endif;?>
    <?php foreach([...$d['rows'],[$key=>'Total']+$d['total']] as $i=>$r):?><tr<?=$i===count($d['rows'])?' class="ac-total"':''?>><td><?=e($r[$key])?></td>
      <?php foreach(array_keys(AccountsPayable::BUCKETS) as $k):?><td class="ac-num<?=$k!=='current'&&$r[$k]?' ac-bad':''?>"><?=$r[$k]?Accounting::money($r[$k]):''?></td><?php endforeach;?><td class="ac-num"><?=Accounting::money($r['total'])?></td></tr><?php endforeach;?>
    </tbody></table></div></div></section>
    <?php accounting_close();
}

function accounting_bill_page(?array $b,bool $editing,array $vendors,array $accounts,?array $form): void
{
    accounting_open('accounting-bills',$b?$b['bill_no']:'New bill','<a class="btn" href="'.url('accounting-bills').'">Back to bills</a>');
    if($editing) {
        $f=fn(string $k,string $d='')=>(string)($form[$k] ?? $b[$k] ?? $d);
        $lines=is_array($form['lines']??null)?array_values($form['lines']):array_map(fn($l)=>['account_id'=>$l['account_id'],'description'=>$l['description'],'amount'=>$l['debit']],$b['lines']??[]);
        while(count($lines)<3) $lines[]=[]; ?>
      <?php if($b && $b['return_note']):?><div class="ac-msg err">Returned by the approver: <?=e($b['return_note'])?></div><?php endif;?>
      <?php if(!$vendors):?><div class="ac-msg err">No active vendors. <a href="<?=url('accounting-vendors')?>">Add a vendor</a> first.</div><?php endif;?>
      <form method="post" class="panel" id="acBillForm"><?=csrf_field()?><input type="hidden" name="action" value="acct_bill_save"><input type="hidden" name="id" value="<?=(int)($b['id']??0)?>">
        <div class="panel-body ac-form-grid">
          <label class="ac-field">Vendor<select name="vendor_id" required><option value="">Choose vendor</option><?php foreach($vendors as $v):?><option value="<?=(int)$v['id']?>" <?=(int)$f('vendor_id')===(int)$v['id']?'selected':''?>><?=e($v['name'])?> (<?=(int)$v['terms_days']?> days)</option><?php endforeach;?></select></label>
          <label class="ac-field">Supplier invoice no. <small>(optional)</small><input name="vendor_ref" maxlength="50" value="<?=e($f('vendor_ref'))?>"></label>
          <label class="ac-field">Bill date<input type="date" name="bill_date" required value="<?=e($f('bill_date',date('Y-m-d')))?>"></label>
          <label class="ac-field">Due date <small>(blank = vendor terms)</small><input type="date" name="due_date" value="<?=e($f('due_date'))?>"></label>
          <label class="ac-field ac-wide">Memo <small>(optional)</small><input name="memo" maxlength="150" value="<?=e($f('memo'))?>" placeholder="What was bought?"></label>
        </div>
        <div class="panel-body"><div class="ac-scroll"><table class="tbl ac-lines"><thead><tr><th>Expense or asset account</th><th>Description</th><th class="ac-num">Amount</th></tr></thead><tbody data-ac-lines>
        <?php foreach($lines as $i=>$l):?><tr>
          <td data-label="Account"><select name="lines[<?=$i?>][account_id]" aria-label="Account, line <?=$i+1?>"><option value="">Choose account</option><?php foreach($accounts as $a):?><option value="<?=(int)$a['id']?>" <?=(int)($l['account_id']??0)===(int)$a['id']?'selected':''?>><?=e($a['code'].' '.$a['name'])?></option><?php endforeach;?></select></td>
          <td data-label="Description"><input name="lines[<?=$i?>][description]" maxlength="255" value="<?=e($l['description']??'')?>" aria-label="Description, line <?=$i+1?>"></td>
          <td data-label="Amount"><input name="lines[<?=$i?>][amount]" inputmode="decimal" class="ac-num" data-ac-dr value="<?=e($l['amount']??'')?>" aria-label="Amount, line <?=$i+1?>"></td>
        </tr><?php endforeach;?></tbody>
        <tfoot><tr class="ac-total"><td colspan="2"><button type="button" class="btn sm" data-ac-add>Add line</button> <span class="ac-muted">Total is credited to <?=e(AccountsPayable::AP_CODE)?> Accounts Payable</span></td><td class="ac-num" data-ac-tdr>0.00</td></tr></tfoot></table></div>
        <div class="ac-row ac-actions"><span class="ac-spacer"></span><button class="btn" type="submit">Save draft</button><button class="btn primary" type="submit" name="submit_after" value="1">Save and submit</button></div></div>
      </form>
      <?php accounting_close(true); return;
    } ?>
    <?php if($b['return_note'] && $b['status']==='DRAFT'):?><div class="ac-msg err">Returned by the approver: <?=e($b['return_note'])?></div><?php endif;?>
    <section class="panel"><div class="panel-head"><div><h2><?=e($b['vendor_name'])?><?=$b['vendor_ref']?' · inv. '.e($b['vendor_ref']):''?></h2>
      <p>Bill date <?=e(date('F j, Y',strtotime($b['bill_date'])))?> · Due <?=e(date('F j, Y',strtotime($b['due_date'])))?> · Journal entry <a href="<?=url('accounting-entry',['id'=>$b['entry_id']])?>"><?=e($b['entry_no'])?></a></p></div><?=accounting_status_badge($b['status'])?></div>
      <div class="panel-body">
      <?php if($b['memo']):?><p class="ac-muted"><?=e($b['memo'])?></p><?php endif;?>
      <?php if($b['reversed_by_no']):?><div class="ac-msg err">Cancelled by reversing entry <?=e($b['reversed_by_no'])?>; it is no longer in AP aging.</div><?php endif;?>
      <div class="ac-scroll"><table class="tbl"><thead><tr><th>Account</th><th>Description</th><th class="ac-num">Amount</th></tr></thead><tbody>
      <?php foreach($b['lines'] as $l):?><tr><td><?=e($l['code'].' '.$l['name'])?></td><td><?=e($l['description']??'')?></td><td class="ac-num"><?=e(number_format((float)$l['debit'],2))?></td></tr><?php endforeach;?>
      <tr class="ac-total"><td colspan="2">Total (credited to Accounts Payable)</td><td class="ac-num"><?=Accounting::money((int)round($b['total']*100))?></td></tr></tbody></table></div>
      <div class="ac-row ac-actions">
        <?php if($b['status']==='DRAFT' && Auth::can('accounting.entry')):?><a class="btn" href="<?=url('accounting-bill',['id'=>$b['id'],'edit'=>1])?>">Edit</a><?php endif;?>
        <span class="ac-spacer"></span>
        <span class="ac-muted">Submit, approve, void and reverse on the journal entry.</span><a class="btn primary" href="<?=url('accounting-entry',['id'=>$b['entry_id']])?>">Open <?=e($b['entry_no'])?></a>
      </div></div></section>
    <?=accounting_doc_payments('bill',$b)?>
    <?php accounting_close();
}
