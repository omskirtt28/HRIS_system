<?php
declare(strict_types=1);

/** Phase 3 B6: asset register, add/edit form and monthly depreciation runs. */
function accounting_assets_page(array $assets,array $runs,?array $edit,?array $form): void
{
    $canEntry=Auth::can('accounting.entry');
    $aAcc=FixedAssets::accountsOf('A'); $xAcc=FixedAssets::accountsOf('X');
    $code=fn(string $c)=>(int)(array_values(array_filter(array_merge($aAcc,$xAcc),fn($x)=>$x['code']===$c))[0]['id']??0);
    $v=$form ?? ($edit ?: ['name'=>'','acquired_on'=>date('Y-m-d'),'cost'=>'','salvage'=>'0','life_months'=>'60','disposed_on'=>'',
        'asset_account_id'=>$code('1500'),'accum_account_id'=>$code('1510'),'expense_account_id'=>$code('5130')]); // 039 sample chart defaults
    $pick=function(string $name,array $list,$sel,string $label){ ?>
        <label class="ac-field"><?=e($label)?><select name="<?=$name?>" required><option value="">Choose</option><?php foreach($list as $x):?><option value="<?=(int)$x['id']?>" <?=(int)$x['id']===(int)$sel?'selected':''?>><?=e($x['code'].' '.$x['name'])?></option><?php endforeach;?></select></label>
    <?php };
    $tc=0; $td=0; foreach($assets as $a) { $tc+=(int)round($a['cost']*100); $td+=(int)round($a['depreciated']*100); }
    accounting_open('accounting-assets','Fixed Assets'); ?>
    <?php if($canEntry):?>
    <section class="panel"><details class="ac-options" <?=$edit||$form?'open':''?>><summary><?=$edit?'Edit '.e($edit['asset_no']):'Add asset'?></summary>
      <form method="post" class="ac-options-body"><?=csrf_field()?><input type="hidden" name="action" value="acct_asset_save"><input type="hidden" name="id" value="<?=(int)($edit['id']??0)?>">
        <?php if($edit && (int)$edit['months_run']>0):?><p class="ac-muted">This asset has depreciation entries: only the name and disposal date can change.</p><?php endif;?>
        <div class="ac-form-grid">
          <label class="ac-field ac-wide">Name<input name="name" maxlength="150" required value="<?=e($v['name'])?>"></label>
          <label class="ac-field">Acquired on<input type="date" name="acquired_on" required value="<?=e($v['acquired_on'])?>"></label>
          <label class="ac-field">Cost<input name="cost" inputmode="decimal" required value="<?=e($v['cost'])?>"></label>
          <label class="ac-field">Salvage value<input name="salvage" inputmode="decimal" value="<?=e($v['salvage'])?>"></label>
          <label class="ac-field">Useful life (months)<input type="number" name="life_months" min="1" max="600" required value="<?=e((string)$v['life_months'])?>"></label>
          <?php $pick('asset_account_id',$aAcc,$v['asset_account_id'],'Asset account'); $pick('accum_account_id',$aAcc,$v['accum_account_id'],'Accumulated depreciation'); $pick('expense_account_id',$xAcc,$v['expense_account_id'],'Depreciation expense');?>
          <label class="ac-field">Disposed on <small class="ac-muted">(optional)</small><input type="date" name="disposed_on" value="<?=e((string)($v['disposed_on']??''))?>"></label>
        </div>
        <div class="ac-row"><button class="btn primary" type="submit">Save asset</button><?php if($edit):?><a class="btn" href="<?=url('accounting-assets')?>">Cancel</a><?php endif;?></div>
        <p class="ac-muted">Straight line: (cost less salvage) divided by the months of useful life, starting the month after acquisition. The last month takes the rounding difference. Gain or loss on disposal is not booked here; record it as a journal entry.</p>
      </form></details></section>
    <?php endif;?>
    <section class="panel"><div class="panel-head"><div><h2>Asset register</h2><p>Accumulated depreciation counts draft and posted runs that are not voided or reversed</p></div></div>
      <div class="panel-body"><div class="ac-scroll" role="region" aria-label="Asset register" tabindex="0"><table class="tbl"><thead><tr><th>Asset</th><th>Acquired</th><th class="ac-num">Cost</th><th class="ac-num">Monthly</th><th class="ac-num">Accumulated</th><th class="ac-num">Book value</th><th>Status</th><?php if($canEntry):?><th><span class="sr-only">Actions</span></th><?php endif;?></tr></thead><tbody>
      <?php if(!$assets):?><tr><td colspan="8" class="ac-muted">No assets yet.</td></tr><?php endif;?>
      <?php foreach($assets as $a): $cost=(int)round($a['cost']*100); $base=$cost-(int)round($a['salvage']*100); $dep=(int)round($a['depreciated']*100);
        $status=$a['disposed_on']?'<span class="badge gray">Disposed '.e(date('M Y',strtotime($a['disposed_on']))).'</span>':($dep>=$base?'<span class="badge green">Fully depreciated</span>':'<span class="badge amber">'.(int)$a['months_run'].' of '.(int)$a['life_months'].' months</span>');?>
        <tr><td><strong><?=e($a['asset_no'])?></strong> <?=e($a['name'])?><small class="ac-block ac-muted"><?=e($a['asset_code'].' '.$a['asset_account'])?></small></td><td><?=e(date('M j, Y',strtotime($a['acquired_on'])))?></td>
          <td class="ac-num"><?=Accounting::money($cost)?></td><td class="ac-num"><?=Accounting::money(FixedAssets::monthly($base,(int)$a['life_months']))?></td><td class="ac-num"><?=Accounting::money($dep)?></td><td class="ac-num"><?=Accounting::money($cost-$dep)?></td><td><?=$status?></td>
          <?php if($canEntry):?><td><a class="btn sm" href="<?=url('accounting-assets',['edit'=>(int)$a['id']])?>">Edit</a></td><?php endif;?></tr>
      <?php endforeach;?>
      <?php if($assets):?><tr class="ac-total"><td colspan="2">Total</td><td class="ac-num"><?=Accounting::money($tc)?></td><td></td><td class="ac-num"><?=Accounting::money($td)?></td><td class="ac-num"><?=Accounting::money($tc-$td)?></td><td colspan="2"></td></tr><?php endif;?>
      </tbody></table></div></div></section>
    <section class="panel"><div class="panel-head"><div><h2>Depreciation runs</h2><p>One draft journal entry per month; submit it for approval like any entry. Void or reverse it to run the month again.</p></div></div>
      <?php if($canEntry):?><form method="post" class="panel-body ac-row"><?=csrf_field()?><input type="hidden" name="action" value="acct_depreciation_run">
        <label class="ac-field">Month<input type="month" name="month" required max="<?=date('Y-m')?>" value="<?=e(date('Y-m',strtotime('first day of last month')))?>"></label>
        <div class="ac-end"><button class="btn primary" type="submit">Create depreciation entry</button></div></form><?php endif;?>
      <div class="panel-body"><div class="ac-scroll"><table class="tbl"><thead><tr><th>Month</th><th>Entry</th><th class="ac-num">Assets</th><th class="ac-num">Amount</th><th>Status</th></tr></thead><tbody>
      <?php if(!$runs):?><tr><td colspan="5" class="ac-muted">No depreciation runs yet.</td></tr><?php endif;?>
      <?php foreach($runs as $r):?><tr><td><?=e(date('F Y',strtotime($r['period_month'])))?></td><td><a href="<?=url('accounting-entry',['id'=>(int)$r['entry_id']])?>"><?=e($r['entry_no'])?></a></td><td class="ac-num"><?=(int)$r['assets']?></td>
        <td class="ac-num"><?=e(number_format((float)$r['total'],2))?></td><td><?=accounting_status_badge($r['status'])?><?=$r['reversed_by_no']?' <small class="ac-muted">Reversed by '.e($r['reversed_by_no']).'</small>':''?></td></tr><?php endforeach;?>
      </tbody></table></div></div></section>
    <?php accounting_close();
}
