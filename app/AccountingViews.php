<?php
declare(strict_types=1);

function accounting_open(string $page,string $title,string $actions=''): void
{
    $u=Auth::user(); $kind=(($u['role_portal']??'')==='admin' || in_array((string)($u['role_code']??''),['SUPER_ADMIN','HRIS_ADMIN'],true))?'admin':'hr';
    render_portal_header($kind,$page,$title);
    page_head('Accounting',$title,$actions); ?>
    <link rel="stylesheet" href="public/assets/accounting.css?v=20261117p3a"><div class="acct"><?php
}

function accounting_close(bool $script=false): void
{
    echo '</div>';
    if($script) echo '<script src="public/assets/accounting.js?v=20261117p3a"></script>';
    render_portal_footer();
}

function accounting_status_badge(string $s): string
{
    $tone=['DRAFT'=>'gray','FOR_APPROVAL'=>'amber','POSTED'=>'green','VOID'=>'red'][$s]??'gray';
    return '<span class="badge '.$tone.'">'.e(Accounting::STATUS[$s]??$s).'</span>';
}

function accounting_coa_page(array $accounts): void
{
    $canEdit=Auth::can('accounting.approve');
    accounting_open('accounting-coa','Chart of Accounts'); ?>
    <?php if($canEdit):?>
    <section class="panel"><div class="panel-head"><div><h2>Add account</h2><p>Codes are unique. Once an account has journal lines its code and type are locked.</p></div></div>
      <form method="post" class="panel-body ac-form-grid"><?=csrf_field()?><input type="hidden" name="action" value="acct_account_save">
        <label class="ac-field">Code<input name="code" required maxlength="20" pattern="[0-9A-Za-z\-]{1,20}"></label>
        <label class="ac-field">Name<input name="name" required maxlength="150"></label>
        <label class="ac-field">Type<select name="type" required><?php foreach(Accounting::TYPES as $k=>$v):?><option value="<?=$k?>"><?=e($v)?></option><?php endforeach;?></select></label>
        <input type="hidden" name="is_active" value="1">
        <div class="ac-field ac-end"><button class="btn primary" type="submit">Add account</button></div>
      </form></section>
    <?php endif;?>
    <section class="panel"><div class="panel-head"><div><h2>Accounts</h2><p><?=count($accounts)?> accounts. Inactive accounts stay in reports but cannot be picked on new lines.</p></div></div>
    <div class="panel-body"><?php if(!$accounts):?><div class="empty">No accounts yet. Add the first one above.</div><?php else:?>
    <div class="ac-scroll" role="region" aria-label="Chart of accounts" tabindex="0"><table class="tbl"><thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Status</th><th class="ac-num">Lines</th><?php if($canEdit):?><th>Edit</th><?php endif;?></tr></thead><tbody>
    <?php $last=''; foreach($accounts as $a): if($a['type']!==$last): $last=$a['type'];?><tr class="ac-group"><td colspan="<?=$canEdit?6:5?>"><?=e(Accounting::TYPES[$a['type']])?></td></tr><?php endif;?>
      <tr><td><?=e($a['code'])?></td><td><a href="<?=url('accounting-ledger',['account'=>(int)$a['id']])?>"><?=e($a['name'])?></a></td><td><?=e(Accounting::TYPES[$a['type']])?></td>
        <td><?=$a['is_active']?'<span class="badge green">Active</span>':'<span class="badge gray">Inactive</span>'?></td><td class="ac-num"><?=(int)$a['used']?></td>
        <?php if($canEdit):?><td><details class="ac-edit"><summary class="btn sm">Edit</summary>
          <form method="post" class="ac-form-grid ac-pop"><?=csrf_field()?><input type="hidden" name="action" value="acct_account_save"><input type="hidden" name="id" value="<?=(int)$a['id']?>">
            <label class="ac-field">Code<input name="code" required maxlength="20" value="<?=e($a['code'])?>" <?=$a['used']?'readonly':''?>></label>
            <label class="ac-field">Name<input name="name" required maxlength="150" value="<?=e($a['name'])?>"></label>
            <label class="ac-field">Type<select name="type"><?php foreach(Accounting::TYPES as $k=>$v): if($a['used'] && $k!==$a['type']) continue;?><option value="<?=$k?>" <?=$k===$a['type']?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></label>
            <label class="ac-check"><input type="checkbox" name="is_active" value="1" <?=$a['is_active']?'checked':''?>> Active</label>
            <div class="ac-end"><button class="btn sm primary" type="submit">Save</button></div>
          </form></details></td><?php endif;?></tr>
    <?php endforeach;?></tbody></table></div><?php endif;?></div></section>
    <?php accounting_close();
}

function accounting_journal_page(array $rows,array $counts,string $status): void
{
    $new=Auth::can('accounting.entry')?'<a class="btn primary" href="'.url('accounting-entry',['new'=>1]).'">New journal entry</a>':'';
    accounting_open('accounting-journal','Journal Entries',$new); ?>
    <nav class="ac-tabs" aria-label="Filter by status">
      <a href="<?=url('accounting-journal')?>" <?=$status===''?'aria-current="page"':''?>>All <span><?=array_sum($counts)?></span></a>
      <?php foreach(Accounting::STATUS as $k=>$v):?><a href="<?=url('accounting-journal',['status'=>$k])?>" <?=$status===$k?'aria-current="page"':''?>><?=e($v)?> <span><?=(int)$counts[$k]?></span></a><?php endforeach;?>
    </nav>
    <section class="panel"><div class="panel-body"><?php if(!$rows):?><div class="empty"><?=array_sum($counts)?'No entries with this status.':'No journal entries yet.'.($new?' Use New journal entry to record the first one.':'')?></div><?php else:?>
    <div class="ac-scroll" role="region" aria-label="Journal entries" tabindex="0"><table class="tbl"><thead><tr><th>No.</th><th>Date</th><th>Memo</th><th class="ac-num">Amount</th><th>Status</th><th>Prepared by</th></tr></thead><tbody>
    <?php foreach($rows as $r):?><tr><td><a href="<?=url('accounting-entry',['id'=>$r['id']])?>"><?=e($r['entry_no'])?></a></td><td><?=e(date('M j, Y',strtotime($r['entry_date'])))?></td>
      <td><?=e($r['memo'])?><?php if($r['return_note'] && $r['status']==='DRAFT'):?><small class="ac-bad ac-block">Returned: <?=e($r['return_note'])?></small><?php endif;?><?php if($r['reversed_by_no']):?><small class="ac-muted ac-block">Reversed by <?=e($r['reversed_by_no'])?></small><?php endif;?></td>
      <td class="ac-num"><?=Accounting::money((int)round($r['total_debit']*100))?></td><td><?=accounting_status_badge($r['status'])?></td><td><?=e($r['created_by_name']??'')?></td></tr><?php endforeach;?>
    </tbody></table></div><?php if(count($rows)>=500):?><p class="ac-muted">Showing the latest 500 entries.</p><?php endif;?><?php endif;?></div></section>
    <?php accounting_close();
}

function accounting_entry_page(?array $e,bool $editing,array $accounts,?array $form,int $uid): void
{
    $title=$e?$e['entry_no']:'New journal entry';
    accounting_open('accounting-journal',$title,'<a class="btn" href="'.url('accounting-journal').'">Back to journal</a>');
    if($editing) {
        $date=(string)($form['entry_date'] ?? $e['entry_date'] ?? date('Y-m-d')); $memo=(string)($form['memo'] ?? $e['memo'] ?? '');
        $lines=is_array($form['lines']??null)?array_values($form['lines']):array_map(fn($l)=>['account_id'=>$l['account_id'],'description'=>$l['description'],'debit'=>(float)$l['debit']?$l['debit']:'','credit'=>(float)$l['credit']?$l['credit']:''],$e['lines']??[]);
        while(count($lines)<4) $lines[]=[]; ?>
      <?php if($e && $e['return_note']):?><div class="ac-msg err">Returned by the approver: <?=e($e['return_note'])?></div><?php endif;?>
      <form method="post" class="panel" id="acEntryForm"><?=csrf_field()?><input type="hidden" name="action" value="acct_journal_save"><input type="hidden" name="id" value="<?=(int)($e['id']??0)?>">
        <div class="panel-body ac-form-grid">
          <label class="ac-field">Entry date<input type="date" name="entry_date" required value="<?=e($date)?>"></label>
          <label class="ac-field ac-wide">Memo<input name="memo" required maxlength="255" value="<?=e($memo)?>" placeholder="What is this entry for?"></label>
        </div>
        <div class="panel-body"><div class="ac-scroll"><table class="tbl ac-lines"><thead><tr><th>Account</th><th>Description</th><th class="ac-num">Debit</th><th class="ac-num">Credit</th></tr></thead><tbody data-ac-lines>
        <?php foreach($lines as $i=>$l):?><tr>
          <td data-label="Account"><select name="lines[<?=$i?>][account_id]" aria-label="Account, line <?=$i+1?>"><option value="">Choose account</option><?php foreach($accounts as $a):?><option value="<?=(int)$a['id']?>" <?=(int)($l['account_id']??0)===(int)$a['id']?'selected':''?>><?=e($a['code'].' '.$a['name'])?></option><?php endforeach;?></select></td>
          <td data-label="Description"><input name="lines[<?=$i?>][description]" maxlength="255" value="<?=e($l['description']??'')?>" aria-label="Description, line <?=$i+1?>"></td>
          <td data-label="Debit"><input name="lines[<?=$i?>][debit]" inputmode="decimal" class="ac-num" data-ac-dr value="<?=e($l['debit']??'')?>" aria-label="Debit, line <?=$i+1?>"></td>
          <td data-label="Credit"><input name="lines[<?=$i?>][credit]" inputmode="decimal" class="ac-num" data-ac-cr value="<?=e($l['credit']??'')?>" aria-label="Credit, line <?=$i+1?>"></td>
        </tr><?php endforeach;?></tbody>
        <tfoot><tr class="ac-total"><td colspan="2"><button type="button" class="btn sm" data-ac-add>Add line</button> <span data-ac-diff class="ac-muted" aria-live="polite"></span></td><td class="ac-num" data-ac-tdr>0.00</td><td class="ac-num" data-ac-tcr>0.00</td></tr></tfoot></table></div>
        <div class="ac-row ac-actions"><span class="ac-spacer"></span><button class="btn" type="submit">Save draft</button><button class="btn primary" type="submit" name="submit_after" value="1">Save and submit</button></div></div>
      </form>
      <?php accounting_close(true); return;
    }
    $canEntry=Auth::can('accounting.entry'); $canApprove=Auth::can('accounting.approve'); $own=(int)$e['created_by_user_id']===$uid; $depMonth=FixedAssets::entryMonth((int)$e['id']); ?>
    <?php if($e['return_note'] && $e['status']==='DRAFT'):?><div class="ac-msg err">Returned by the approver: <?=e($e['return_note'])?></div><?php endif;?>
    <section class="panel"><div class="panel-head"><div><h2><?=e($e['memo'])?></h2><p><?=e(date('F j, Y',strtotime($e['entry_date'])))?> · Prepared by <?=e($e['created_by_name']??'')?><?php if($e['approved_by_name']):?> · Posted by <?=e($e['approved_by_name'])?><?php endif;?></p></div><?=accounting_status_badge($e['status'])?></div>
      <div class="panel-body">
      <?php if($e['status']==='VOID'):?><div class="ac-msg err">Voided<?=$e['voided_at']?' on '.e(date('M j, Y',strtotime($e['voided_at']))):''?>: <?=e($e['void_reason']??'')?>. It no longer counts anywhere; the number is kept so the journal has no gaps.</div><?php endif;?>
      <?php if($e['entry_kind']==='CLOSING'):?><p class="ac-muted">Year-end closing entry: it moves the year's income and expenses to equity and is left out of the income statement.</p><?php endif;?>
      <?php if($e['bill_id']):?><p class="ac-muted">Created from bill <a href="<?=url('accounting-bill',['id'=>$e['bill_id']])?>"><?=e($e['bill_no'])?></a>. Its lines are edited on the bill.</p><?php endif;?>
      <?php if($e['invoice_id']):?><p class="ac-muted">Created from invoice <a href="<?=url('accounting-invoice',['id'=>$e['invoice_id']])?>"><?=e($e['invoice_no'])?></a>. Its lines are edited on the invoice.</p><?php endif;?>
      <?php if($e['payment_id']):?><p class="ac-muted">Created from payment <a href="<?=url('accounting-payment',['id'=>$e['payment_id']])?>"><?=e($e['payment_no'])?></a>. Its lines are edited on the payment.</p><?php endif;?>
      <?php if($e['remittance_id']):?><p class="ac-muted">Created from remittance <a href="<?=url('accounting-remittance',['id'=>$e['remittance_id']])?>"><?=e($e['remittance_no'])?></a>. Its lines are edited on the remittance.</p><?php endif;?>
      <?php if($depMonth):?><p class="ac-muted">Depreciation run for <a href="<?=url('accounting-assets')?>"><?=e(date('F Y',strtotime($depMonth)))?></a>. Its lines come from the asset register; void it and run the month again to change it.</p><?php endif;?>
      <?php if($e['reverses_no']):?><p class="ac-muted">Reverses <a href="<?=url('accounting-entry',['id'=>$e['reverses_entry_id']])?>"><?=e($e['reverses_no'])?></a>.</p><?php endif;?>
      <?php if($e['reversed_by_no']):?><p class="ac-muted">Reversed by <a href="<?=url('accounting-entry',['id'=>$e['reversed_by_id']])?>"><?=e($e['reversed_by_no'])?></a>.</p><?php endif;?>
      <div class="ac-scroll"><table class="tbl"><thead><tr><th>Account</th><th>Description</th><th class="ac-num">Debit</th><th class="ac-num">Credit</th></tr></thead><tbody>
      <?php foreach($e['lines'] as $l):?><tr><td><?=e($l['code'].' '.$l['name'])?></td><td><?=e($l['description']??'')?></td><td class="ac-num"><?=(float)$l['debit']?e(number_format((float)$l['debit'],2)):''?></td><td class="ac-num"><?=(float)$l['credit']?e(number_format((float)$l['credit'],2)):''?></td></tr><?php endforeach;?>
      <tr class="ac-total"><td colspan="2">Total<?php if($e['debit']!==$e['credit']):?> <span class="ac-bad">Not balanced (difference <?=Accounting::money(abs($e['debit']-$e['credit']))?>)</span><?php endif;?></td><td class="ac-num"><?=Accounting::money($e['debit'])?></td><td class="ac-num"><?=Accounting::money($e['credit'])?></td></tr>
      </tbody></table></div>
      <div class="ac-row ac-actions">
      <?php if($e['status']==='DRAFT' && $canEntry):?>
        <?php if($e['bill_id']):?><a class="btn" href="<?=url('accounting-bill',['id'=>$e['bill_id'],'edit'=>1])?>">Edit bill</a>
        <?php elseif($e['invoice_id']):?><a class="btn" href="<?=url('accounting-invoice',['id'=>$e['invoice_id'],'edit'=>1])?>">Edit invoice</a>
        <?php elseif($e['payment_id']):?><a class="btn" href="<?=url('accounting-payment',['id'=>$e['payment_id'],'edit'=>1])?>">Edit payment</a>
        <?php elseif($e['remittance_id']):?><a class="btn" href="<?=url('accounting-remittance',['id'=>$e['remittance_id'],'edit'=>1])?>">Edit remittance</a>
        <?php elseif($e['entry_kind']!=='CLOSING' && !$depMonth):?><a class="btn" href="<?=url('accounting-entry',['id'=>$e['id'],'edit'=>1])?>">Edit</a><?php endif;?>
        <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="acct_journal_submit"><input type="hidden" name="id" value="<?=(int)$e['id']?>"><button class="btn primary" type="submit">Submit for approval</button></form>
        <span class="ac-spacer"></span>
        <form method="post" class="ac-row"><?=csrf_field()?><input type="hidden" name="action" value="acct_journal_void"><input type="hidden" name="id" value="<?=(int)$e['id']?>">
          <label class="ac-field">Reason for voiding<input name="note" maxlength="255" required></label><button class="btn" type="submit">Void draft</button></form>
      <?php elseif($e['status']==='FOR_APPROVAL' && $canApprove):?>
        <form method="post" class="ac-row"><?=csrf_field()?><input type="hidden" name="action" value="acct_journal_return"><input type="hidden" name="id" value="<?=(int)$e['id']?>">
          <label class="ac-field">Reason for returning<input name="note" maxlength="255" required></label><button class="btn" type="submit">Return to draft</button></form>
        <span class="ac-spacer"></span>
        <?php if($own):?><span class="ac-muted">You prepared this entry; another approver must post it.</span>
        <?php else:?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="acct_journal_approve"><input type="hidden" name="id" value="<?=(int)$e['id']?>"><button class="btn primary" type="submit">Approve and post</button></form><?php endif;?>
      <?php elseif($e['status']==='FOR_APPROVAL'):?><span class="ac-muted">Waiting for the Finance Head.</span>
      <?php elseif($e['status']==='POSTED' && $canApprove && !$e['reversed_by_no']):?>
        <form method="post" class="ac-row"><?=csrf_field()?><input type="hidden" name="action" value="acct_journal_reverse"><input type="hidden" name="id" value="<?=(int)$e['id']?>">
          <label class="ac-field">Reversal date<input type="date" name="entry_date" required value="<?=e(max(date('Y-m-d'),$e['entry_date']))?>"></label><button class="btn" type="submit">Create reversing entry</button></form>
        <span class="ac-muted">Posted entries cannot be edited. A reversing entry swaps every debit and credit and goes through approval.</span>
      <?php endif;?>
      </div></div></section>
    <?php accounting_close();
}

function accounting_periods_page(array $periods,array $equity=[]): void
{
    $canApprove=Auth::can('accounting.approve');
    accounting_open('accounting-periods','Fiscal Periods'); ?>
    <section class="panel"><div class="panel-head"><div><h2>Months</h2><p>A closed month is locked: no entry dated in it can be saved, submitted or posted. Close it once every entry in it is posted. The checklist also shows bank reconciliations and remittances for the month; those are reminders and do not block the close.</p></div></div>
    <div class="panel-body"><div class="ac-scroll" role="region" aria-label="Fiscal periods" tabindex="0"><table class="tbl"><thead><tr><th>Month</th><th class="ac-num">Posted</th><th class="ac-num">Not yet posted</th><th>Close checklist</th><th>Status</th><?php if($canApprove):?><th>Action</th><?php endif;?></tr></thead><tbody>
    <?php foreach($periods as $p): $label=date('F Y',strtotime($p['month']));
      $items=[['All entries posted',$p['pending']===0,''],['Banks reconciled',$p['banks_done']>=$p['banks_total'],$p['banks_done'].' of '.$p['banks_total']],['Remittances recorded',$p['remit_done']>=$p['remit_total'],$p['remit_done'].' of '.$p['remit_total'].' agencies']];?>
    <tr><td><?=e($label)?></td><td class="ac-num"><?=$p['posted']?></td><td class="ac-num"><?=$p['pending']?:'0'?></td>
      <td><ul class="ac-checklist"><?php foreach($items as [$t,$ok,$n]):?><li class="<?=$ok?'ac-good':'ac-muted'?>"><span aria-hidden="true"><?=$ok?'✓':'○'?></span> <?=e($t)?><?=$n!==''?' <small>('.e($n).')</small>':''?><span class="sr-only"><?=$ok?' - done':' - not yet'?></span></li><?php endforeach;?></ul></td>
      <td><?php if($p['closed']):?><span class="badge gray">Closed</span> <small class="ac-muted"><?=e(($p['closed']['full_name']??'').' · '.date('M j, Y',strtotime($p['closed']['closed_at'])))?></small><?php else:?><span class="badge blue">Open</span><?php endif;?></td>
      <?php if($canApprove):?><td><form method="post"><?=csrf_field()?><input type="hidden" name="month" value="<?=e($p['month'])?>">
        <?php if($p['closed']):?><input type="hidden" name="action" value="acct_period_reopen"><button class="btn sm" type="submit">Reopen</button>
        <?php elseif($p['month']<=date('Y-m-01')):?><input type="hidden" name="action" value="acct_period_close"><button class="btn sm" type="submit" <?=$p['pending']?'disabled title="Post or return the pending entries first"':''?>>Close month</button><?php endif;?>
      </form></td><?php endif;?></tr><?php endforeach;?>
    </tbody></table></div></div></section>
    <?php if($canApprove):?>
    <section class="panel"><div class="panel-head"><div><h2>Year-end closing</h2><p>Creates a draft dated December 31 that brings every income and expense account of the year to zero against an equity account. It goes through approval like any entry.</p></div></div>
      <form method="post" class="panel-body ac-form-grid"><?=csrf_field()?><input type="hidden" name="action" value="acct_year_close">
        <label class="ac-field">Year<select name="year" required><?php for($y=(int)date('Y');$y>=(int)date('Y')-5;$y--):?><option value="<?=$y?>" <?=$y===(int)date('Y')-1?'selected':''?>><?=$y?></option><?php endfor;?></select></label>
        <label class="ac-field">Close to<select name="equity_id" required><?php if(!$equity):?><option value="">No active equity account</option><?php endif; foreach($equity as $a):?><option value="<?=(int)$a['id']?>" <?=stripos($a['name'],'retained')!==false?'selected':''?>><?=e($a['code'].' '.$a['name'])?></option><?php endforeach;?></select></label>
        <div class="ac-field ac-end"><button class="btn primary" type="submit" <?=$equity?'':'disabled'?>>Create closing entry</button></div>
      </form></section>
    <?php endif;?>
    <?php accounting_close();
}

function accounting_report_rows(array $rows,string $from='',string $to=''): string
{
    $h=''; foreach($rows as $r) $h.='<tr><td class="ac-indent">'.accounting_ledger_link($r,$from,$to).'</td><td class="ac-num">'.Accounting::money($r['balance']).'</td></tr>';
    return $h;
}

function accounting_reports_page(string $report,array $d,string $from,string $to): void
{
    $names=['tb'=>'Trial Balance','is'=>'Income Statement','bs'=>'Balance Sheet'];
    accounting_open('accounting-reports','Financial Reports'); ?>
    <nav class="ac-tabs" aria-label="Report"><?php foreach($names as $k=>$v):?><a href="<?=url('accounting-reports',['report'=>$k,'from'=>$from,'to'=>$to])?>" <?=$report===$k?'aria-current="page"':''?>><?=e($v)?></a><?php endforeach;?><a href="<?=url('accounting-ledger',['to'=>$to])?>">General ledger</a></nav>
    <section class="panel"><form method="get" class="panel-body ac-row"><input type="hidden" name="page" value="accounting-reports"><input type="hidden" name="report" value="<?=e($report)?>">
      <?php if($report==='is'):?><label class="ac-field">From<input type="date" name="from" value="<?=e($from)?>"></label><?php endif;?>
      <label class="ac-field"><?=$report==='is'?'To':'As of'?><input type="date" name="to" value="<?=e($to)?>"></label>
      <button class="btn" type="submit">Show</button><span class="ac-spacer"></span>
      <a class="btn" href="<?=url('accounting-reports',['report'=>$report,'from'=>$from,'to'=>$to,'csv'=>1])?>">Download CSV</a></form>
    <div class="panel-body"><p class="ac-muted"><?=e($names[$report])?> · <?=$report==='is'?e(date('M j, Y',strtotime($from)).' to '.date('M j, Y',strtotime($to))):'as of '.e(date('M j, Y',strtotime($to)))?> · posted entries only</p>
    <div class="ac-scroll"><table class="tbl">
    <?php if($report==='tb'):?>
      <thead><tr><th>Account</th><th class="ac-num">Debit</th><th class="ac-num">Credit</th></tr></thead><tbody>
      <?php if(!$d['rows']):?><tr><td colspan="3" class="ac-muted">No posted entries up to this date.</td></tr><?php endif;?>
      <?php foreach($d['rows'] as $r):?><tr><td><?=accounting_ledger_link($r,'',$to)?></td><td class="ac-num"><?=$r['dr']?Accounting::money($r['dr']):''?></td><td class="ac-num"><?=$r['cr']?Accounting::money($r['cr']):''?></td></tr><?php endforeach;?>
      <tr class="ac-total"><td>Total <?=$d['debit']===$d['credit']?'<span class="ac-good">Balanced</span>':'<span class="ac-bad">Not balanced</span>'?></td><td class="ac-num"><?=Accounting::money($d['debit'])?></td><td class="ac-num"><?=Accounting::money($d['credit'])?></td></tr></tbody>
    <?php elseif($report==='is'):?>
      <tbody><tr class="ac-group"><td colspan="2">Income</td></tr><?=accounting_report_rows($d['income'],$from,$to)?><tr class="ac-total"><td>Total income</td><td class="ac-num"><?=Accounting::money($d['total_income'])?></td></tr>
      <tr class="ac-group"><td colspan="2">Expenses</td></tr><?=accounting_report_rows($d['expense'],$from,$to)?><tr class="ac-total"><td>Total expenses</td><td class="ac-num"><?=Accounting::money($d['total_expense'])?></td></tr>
      <tr class="ac-total"><td>Net income</td><td class="ac-num <?=$d['net']<0?'ac-bad':'ac-good'?>"><?=Accounting::money($d['net'])?></td></tr></tbody>
    <?php else:?>
      <tbody><tr class="ac-group"><td colspan="2">Assets</td></tr><?=accounting_report_rows($d['assets'],'',$to)?><tr class="ac-total"><td>Total assets</td><td class="ac-num"><?=Accounting::money($d['total_assets'])?></td></tr>
      <tr class="ac-group"><td colspan="2">Liabilities</td></tr><?=accounting_report_rows($d['liabilities'],'',$to)?><tr class="ac-total"><td>Total liabilities</td><td class="ac-num"><?=Accounting::money($d['total_liabilities'])?></td></tr>
      <tr class="ac-group"><td colspan="2">Equity</td></tr><?=accounting_report_rows($d['equity'],'',$to)?><tr><td class="ac-indent">Current earnings (income less expenses to date)</td><td class="ac-num"><?=Accounting::money($d['earnings'])?></td></tr>
      <tr class="ac-total"><td>Total equity</td><td class="ac-num"><?=Accounting::money($d['total_equity'])?></td></tr>
      <tr class="ac-total"><td>Liabilities and equity <?=$d['total_assets']===$d['total_liabilities']+$d['total_equity']?'<span class="ac-good">Balanced</span>':'<span class="ac-bad">Not balanced</span>'?></td><td class="ac-num"><?=Accounting::money($d['total_liabilities']+$d['total_equity'])?></td></tr></tbody>
    <?php endif;?></table></div></div></section>
    <?php accounting_close();
}

function accounting_report_csv(string $report,array $d,string $from,string $to): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.['tb'=>'trial-balance','is'=>'income-statement','bs'=>'balance-sheet'][$report].'-'.$to.'.csv"');
    $out=fopen('php://output','w'); $n=fn(int $c)=>number_format($c/100,2,'.','');
    // Leading = + - @ would run as a formula in Excel; account names are user-entered.
    $safe=fn(string $s)=>preg_match('/^[=+\-@\t\r]/',$s)?"'".$s:$s;
    $row=fn(array $r)=>fputcsv($out,array_map(fn($v)=>is_string($v)&&!is_numeric($v)?$safe($v):$v,$r));
    if($report==='tb') { $row(['Account code','Account','Debit','Credit']); foreach($d['rows'] as $r) $row([$r['code'],$r['name'],$n($r['dr']),$n($r['cr'])]); $row(['','Total',$n($d['debit']),$n($d['credit'])]); }
    else {
        $row(['Section','Account code','Account','Amount']);
        $sections=$report==='is'?['Income'=>'income','Expenses'=>'expense']:['Assets'=>'assets','Liabilities'=>'liabilities','Equity'=>'equity'];
        foreach($sections as $label=>$key) foreach($d[$key] as $r) $row([$label,$r['code'],$r['name'],$n($r['balance'])]);
        if($report==='is') $row(['','','Net income ('.$from.' to '.$to.')',$n($d['net'])]);
        else { $row(['Equity','','Current earnings',$n($d['earnings'])]); $row(['','','Total assets',$n($d['total_assets'])]); $row(['','','Total liabilities and equity',$n($d['total_liabilities']+$d['total_equity'])]); }
    }
    fclose($out);
}

/** Account name linking to its general ledger. Blank $from = January 1 of the $to year (balance-type reports). */
function accounting_ledger_link(array $r,string $from,string $to): string
{
    $to=$to!==''?$to:date('Y-m-d'); $from=$from!==''?$from:substr($to,0,4).'-01-01';
    return '<a href="'.e(url('accounting-ledger',['account'=>(int)$r['id'],'from'=>$from,'to'=>$to])).'">'.e($r['code'].' '.$r['name']).'</a>';
}

/** Phase 3 B2: general ledger of one account with opening and running balance. */
function accounting_ledger_page(array $l,array $accounts,string $from,string $to): void
{
    $a=$l['account']; $q=['account'=>(int)$a['id'],'from'=>$from,'to'=>$to];
    accounting_open('accounting-reports','General Ledger'); ?>
    <section class="panel"><form method="get" action="index.php" class="panel-body ac-row"><input type="hidden" name="page" value="accounting-ledger">
      <label class="ac-field">Account<select name="account"><?php foreach($accounts as $x):?><option value="<?=(int)$x['id']?>" <?=(int)$x['id']===(int)$a['id']?'selected':''?>><?=e($x['code'].' '.$x['name'])?></option><?php endforeach;?></select></label>
      <label class="ac-field">From<input type="date" name="from" value="<?=e($from)?>"></label><label class="ac-field">To<input type="date" name="to" value="<?=e($to)?>"></label>
      <button class="btn" type="submit">Show</button><span class="ac-spacer"></span><a class="btn" href="<?=url('accounting-ledger',$q+['csv'=>1])?>">Download CSV</a></form>
    <div class="panel-body"><p class="ac-muted"><?=e($a['code'].' '.$a['name'])?> · <?=e(Accounting::TYPES[$a['type']])?> · <?=e(date('M j, Y',strtotime($from)).' to '.date('M j, Y',strtotime($to)))?> · posted entries only<?=$a['is_active']?'':' · inactive account'?></p>
    <div class="ac-scroll"><table class="tbl"><thead><tr><th>Date</th><th>Entry</th><th>Description</th><th class="ac-num">Debit</th><th class="ac-num">Credit</th><th class="ac-num">Balance</th></tr></thead><tbody>
      <tr class="ac-group"><td colspan="5">Opening balance</td><td class="ac-num"><?=Accounting::money($l['opening'])?></td></tr>
      <?php if(!$l['rows']):?><tr><td colspan="6" class="ac-muted">No posted lines in this range.</td></tr><?php endif;?>
      <?php foreach($l['rows'] as $r):?><tr><td><?=e(date('M j, Y',strtotime($r['entry_date'])))?></td><td><a href="<?=url('accounting-entry',['id'=>(int)$r['entry_id']])?>"><?=e($r['entry_no'])?></a></td>
        <td><?=e($r['description']!==''&&$r['description']!==null?$r['description']:$r['memo'])?></td><td class="ac-num"><?=$r['dr']?Accounting::money($r['dr']):''?></td><td class="ac-num"><?=$r['cr']?Accounting::money($r['cr']):''?></td><td class="ac-num"><?=Accounting::money($r['balance'])?></td></tr><?php endforeach;?>
      <tr class="ac-total"><td colspan="3">Totals and closing balance</td><td class="ac-num"><?=Accounting::money($l['debit'])?></td><td class="ac-num"><?=Accounting::money($l['credit'])?></td><td class="ac-num"><?=Accounting::money($l['closing'])?></td></tr>
    </tbody></table></div></div></section>
    <?php accounting_close();
}

function accounting_ledger_csv(array $l,string $from,string $to): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ledger-'.preg_replace('/[^A-Za-z0-9-]/','',$l['account']['code']).'-'.$from.'-'.$to.'.csv"');
    $out=fopen('php://output','w'); $n=fn(int $c)=>number_format($c/100,2,'.','');
    $safe=fn($s)=>is_string($s)&&preg_match('/^[=+\-@\t\r]/',$s)?"'".$s:$s; // user text never runs as an Excel formula
    $row=fn(array $r)=>fputcsv($out,array_map($safe,$r));
    $row(['Account',$l['account']['code'].' '.$l['account']['name']]); $row(['From',$from]); $row(['To',$to]);
    $row(['Date','Entry','Description','Debit','Credit','Balance']); $row(['','','Opening balance','','',$n($l['opening'])]);
    foreach($l['rows'] as $r) $row([$r['entry_date'],$r['entry_no'],(string)($r['description']?:$r['memo']),$r['dr']?$n($r['dr']):'',$r['cr']?$n($r['cr']):'',$n($r['balance'])]);
    $row(['','','Totals',$n($l['debit']),$n($l['credit']),$n($l['closing'])]);
    fclose($out);
}
