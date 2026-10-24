<?php
declare(strict_types=1);
require_once __DIR__.'/TicketQueue.php';

function hq_pill(string $status,string $type=''):string
{
    $tone=match($status){'PENDING_HR'=>'amber','IN_PROGRESS'=>'blue','COMPLETED'=>'green','CANCELLED','REJECTED'=>'red',default=>'gray'};
    return '<span class="badge '.$tone.'">'.e(TicketTypes::label($status,$type)).'</span>';
}
function hq_age(?int $days,string $tone):string
{
    if($days===null)return '<span class="hq-muted">—</span>';
    return '<span class="hq-age '.e($tone).'" title="Days since filed">'.$days.($days===1?' day':' days').'</span>';
}
/** Hidden fields every queue POST carries: CSRF and the filters to come back to. */
function hq_back(array $f):string { return '<input type="hidden" name="back" value="'.e(http_build_query(TicketQueue::linkParams($f))).'">'; }
function hq_action_forms(array $t,array $f,string $page='hr-service-requests'):string
{
    if(!$t['actions'])return '';
    $labels=['START'=>'In Progress','SERVED'=>'Served','CANCEL'=>'Cancel'];
    $o='<form method="post" action="'.e(url($page)).'" class="hq-quick" data-ticket="'.e($t['ticket_no']).'" data-type="'.e($t['type_name']).'">'.csrf_field().'<input type="hidden" name="action" value="core_ticket_quick"><input type="hidden" name="ticket_id" value="'.(int)$t['id'].'"><input type="hidden" name="version" value="'.(int)$t['version'].'">'.hq_back($f);
    foreach($t['actions'] as $a)$o.='<button class="btn sm'.($a==='SERVED'?' primary':'').'" name="do" value="'.$a.'"'.($a!=='START'?' data-confirm="'.e($labels[$a]).'"':'').'>'.e($labels[$a]).'</button>';
    return $o.'</form>';
}

function ticket_queue_chips(array $f):array
{
    $chips=[];$name=static function(string $sql,int $id):string{$q=db()->prepare($sql);$q->execute([$id]);return (string)$q->fetchColumn();};
    if($f['q']!=='')$chips['q']='Search: '.$f['q'];
    if($f['type_code']!==''){$q=db()->prepare('SELECT name FROM ticket_types WHERE code=?');$q->execute([$f['type_code']]);$chips['type_code']='Type: '.($q->fetchColumn()?:$f['type_code']);}
    if($f['company_label']!=='')$chips['company_label']='Label: '.$f['company_label'];
    if($f['company_group']!=='')$chips['company_group']='Group: '.($f['company_group']==='OC'?'Ocampo\'s':'SW');
    if($f['business_unit_id'])$chips['business_unit_id']='Company: '.($name('SELECT name FROM business_units WHERE id=?',$f['business_unit_id'])?:'#'.$f['business_unit_id']);
    if($f['branch_id'])$chips['branch_id']='Branch: '.($name('SELECT name FROM branches WHERE id=?',$f['branch_id'])?:'#'.$f['branch_id']);
    if($f['age']!=='')$chips['age']='Age: '.['0'=>'0-2 days','1'=>'3-5 days','2'=>'over 5 days'][$f['age']];
    if($f['closed_from']!==''||$f['closed_to']!=='')$chips['closed']='Served '.($f['closed_from']?:'…').' to '.($f['closed_to']?:'…');
    if(!empty($f['status']))$chips['status']='Status: '.TicketTypes::label($f['status']);
    return $chips;
}

function ticket_queue_page(array $f,array $page,array $counts,array $uniform,?array $result):void
{
    ticket_header('hr-service-requests','HR Request Queue');
    echo '<link rel="stylesheet" href="public/assets/hr-ops.css?v=20261025-1">';
    $self=static fn(array $o=[])=>url('hr-service-requests',TicketQueue::linkParams($f,$o));
    page_head('HR Requests','HR Request Queue','<a class="btn" href="'.e(url('hr-service-requests-export',TicketQueue::linkParams($f))).'">'.icon_svg('download').' Export CSV</a>');
    if($result):?>
    <section class="panel hq-result" role="status"><div class="panel-body"><strong><?=e(TicketQueue::summaryLine($result))?></strong>
      <?php if($result['skipped']):?><ul class="hq-skips"><?php foreach($result['skipped'] as $s):?><li><span class="hq-mono"><?=e($s['ticket_no'])?></span><?=$s['type']!==''?' · '.e($s['type']):''?> — <?=e($s['reason'])?></li><?php endforeach;?></ul><?php endif;?></div></section>
    <?php endif;
    // Tabs
    echo '<nav class="hq-tabs" aria-label="Request status">';
    foreach(TicketQueue::TABS as $key=>$label){$on=$f['tab']===$key;echo '<a class="hq-tab'.($on?' is-active':'').'" href="'.e($self(['tab'=>$key,'status'=>'','p'=>1])).'"'.($on?' aria-current="page"':'').'>'.e($label).' <span class="hq-count">'.(int)$counts[$key].'</span></a>';}
    echo '</nav>';
    $chips=ticket_queue_chips($f);$types=db()->query('SELECT code,name FROM ticket_types WHERE active=1 AND code<>"INCIDENT_REPORT" ORDER BY name')->fetchAll();?>
    <form method="get" class="hq-toolbar" role="search" action="index.php"><input type="hidden" name="page" value="hr-service-requests"><input type="hidden" name="tab" value="<?=e($f['tab'])?>">
      <?php foreach(['age','closed_from','closed_to'] as $k)if($f[$k]!==''):?><input type="hidden" name="<?=e($k)?>" value="<?=e($f[$k])?>"><?php endif;?>
      <div class="hq-search"><label class="hq-sr" for="hq-q">Search by ticket number, name or branch</label><?=icon_svg('search')?><input id="hq-q" type="search" name="q" value="<?=e($f['q'])?>" placeholder="Search ticket no., name or branch" maxlength="80"></div>
      <div class="field hq-sort"><label for="hq-sort">Sort</label><select id="hq-sort" name="sort"><option value="oldest" <?=$f['sort']==='oldest'?'selected':''?>>Oldest first</option><option value="newest" <?=$f['sort']==='newest'?'selected':''?>>Newest first</option><option value="type" <?=$f['sort']==='type'?'selected':''?>>By type</option></select></div>
      <details class="hq-filters" <?=array_diff_key($chips,['q'=>1,'age'=>1,'closed'=>1])?'open':''?>><summary class="btn"><?=icon_svg('table')?> Filters<?=count($chips)?' <span class="hq-count">'.count($chips).'</span>':''?></summary>
        <div class="hq-filter-grid">
          <div class="field"><label for="hq-type">Type</label><select id="hq-type" name="type_code"><option value="">All types</option><?php foreach($types as $ty):?><option value="<?=e($ty['code'])?>" <?=$f['type_code']===$ty['code']?'selected':''?>><?=e($ty['name'])?></option><?php endforeach;?></select></div>
          <div class="field"><label for="hq-label">Company label</label><select id="hq-label" name="company_label"><option value="">All labels</option><?php foreach(['SW','OFJI'] as $l):?><option <?=$f['company_label']===$l?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div>
          <div class="field"><label for="hq-company">Company</label><select id="hq-company" name="business_unit_id"><option value="0">All companies</option><?php foreach(FoundationRepository::businessUnits() as $row):?><option value="<?=(int)$row['id']?>" <?=$f['business_unit_id']===(int)$row['id']?'selected':''?>><?=e($row['name'])?></option><?php endforeach;?></select></div>
          <div class="field"><label for="hq-branch">Branch</label><select id="hq-branch" name="branch_id"><option value="0">All branches</option><?php foreach(FoundationRepository::branches() as $row):?><option value="<?=(int)$row['id']?>" <?=$f['branch_id']===(int)$row['id']?'selected':''?>><?=e($row['name'])?></option><?php endforeach;?></select></div>
        </div></details>
      <button class="btn primary">Apply</button>
    </form>
    <?php if($chips):?><div class="hq-chips" aria-label="Active filters"><?php foreach($chips as $key=>$text):$drop=$key==='closed'?['closed_from'=>'','closed_to'=>'']:[$key=>($key==='business_unit_id'||$key==='branch_id')?0:''];?><a class="hq-chip" href="<?=e($self($drop+['p'=>1]))?>" title="Remove this filter"><?=e($text)?> <span aria-hidden="true">×</span><span class="hq-sr">Remove filter</span></a><?php endforeach;?><a class="hq-chip hq-chip-clear" href="<?=e(url('hr-service-requests',$f['tab']==='all'?[]:['tab'=>$f['tab']]))?>">Clear all</a></div><?php endif;?>

    <section class="panel ticket-scroll hq-card"><table class="tbl hq-table"><caption class="hq-sr">HR requests, <?=e(strtolower(TicketQueue::TABS[$f['tab']]))?></caption>
      <thead><tr><th class="hq-check"><input type="checkbox" id="hq-all" aria-label="Select all requests on this page" <?=$page['rows']?'':'disabled'?>></th><th>Request</th><th>Employee</th><th>Filed · Age</th><th>Status</th><th><span class="hq-sr">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach($page['rows'] as $t):?>
      <tr data-id="<?=(int)$t['id']?>" data-type="<?=e($t['type_code'])?>" data-type-name="<?=e($t['type_name'])?>" data-bulk="<?=$t['bulk_ok']?1:0?>" data-status="<?=e($t['status'])?>">
        <td class="hq-check" data-label="Select"><input type="checkbox" class="hq-pick" form="hq-bulk-form" name="items[<?=(int)$t['id']?>]" value="<?=(int)$t['version']?>" aria-label="Select <?=e($t['ticket_no'])?>"></td>
        <td data-label="Request"><div class="hq-type"><span class="hq-ticon"><?=icon_svg(TicketQueue::icon($t['type_code']))?></span><span><strong><?=e($t['type_name'])?></strong>
          <a class="hq-mono" href="<?=e(url('hr-request',['id'=>$t['id']]))?>" data-preview="<?=e(url('hr-request-preview',['id'=>$t['id']]))?>"><?=e($t['ticket_no'])?></a>
          <?php if($t['summary']!==''):?><small class="hq-reason"><?=e($t['summary'])?></small><?php endif;?></span></div></td>
        <td data-label="Employee"><strong><?=e(trim($t['first_name'].' '.$t['last_name']))?></strong><small><?=e($t['branch_name'])?> · <?=e($t['company_name_snapshot'])?><?=!empty($t['payload']['company'])?' · '.e($t['payload']['company']):''?></small></td>
        <td data-label="Filed · Age"><?=e(substr((string)($t['filed_at']??$t['created_at']),0,10))?> <?=hq_age($t['age_days'],$t['age_tone'])?></td>
        <td data-label="Status"><?=hq_pill($t['status'],$t['type_code'])?></td>
        <td data-label="Actions" class="hq-actions"><?=hq_action_forms($t,$f)?><a class="btn sm" href="<?=e(url('hr-request',['id'=>$t['id']]))?>" data-preview="<?=e(url('hr-request-preview',['id'=>$t['id']]))?>">Preview</a></td>
      </tr>
      <?php endforeach;?>
      <?php if(!$page['rows']):?><tr class="hq-empty-row"><td colspan="6"><div class="hq-empty"><?=icon_svg('clipboard')?><strong><?=$chips?'No requests match these filters.':'No requests in this list yet.'?></strong><span><?=$chips?'Try removing a filter or searching for something else.':'New HR requests will appear here as employees file them.'?></span><?php if($chips):?><a class="btn" href="<?=e(url('hr-service-requests'))?>">Clear filters</a><?php endif;?></div></td></tr><?php endif;?>
      </tbody></table></section>
    <nav class="hq-pager" aria-label="HR request pages"><span>Page <?=(int)$page['page']?></span><?php if($page['page']>1):?><a class="btn sm" href="<?=e(url('hr-service-requests',TicketQueue::linkParams($f)+['p'=>$page['page']-1]))?>">Previous</a><?php endif;if($page['has_next']):?><a class="btn sm" href="<?=e(url('hr-service-requests',TicketQueue::linkParams($f)+['p'=>$page['page']+1]))?>">Next</a><?php endif;?></nav>

    <?php if($uniform['requests']>0||$f['type_code']==='UNIFORM_REQUEST'):?>
    <details class="panel ticket-panel hq-uniform"><summary><strong>Uniform order summary</strong> <span class="hq-muted"><?=(int)$uniform['requests']?> <?=e($uniform['scope']?:'requests')?> · quantity per size, by branch</span></summary>
      <?php if($uniform['requests']<1):?><p class="hq-muted hq-pad">No uniform requests in this view.</p><?php else:?>
      <div class="ticket-scroll"><table class="tbl"><thead><tr><th>Branch</th><?php foreach($uniform['sizes'] as $s):?><th class="hq-num"><?=e($s)?></th><?php endforeach;?><th class="hq-num">Total</th></tr></thead><tbody>
        <?php foreach($uniform['branches'] as $branch=>$row):?><tr><td><?=e($branch)?></td><?php foreach($uniform['sizes'] as $s):?><td class="hq-num"><?=$row[$s]?:'<span class="hq-muted">·</span>'?></td><?php endforeach;?><td class="hq-num"><strong><?=(int)$row['_total']?></strong></td></tr><?php endforeach;?>
        <tr class="hq-total"><td>All branches</td><?php foreach($uniform['sizes'] as $s):?><td class="hq-num"><?=(int)$uniform['totals'][$s]?></td><?php endforeach;?><td class="hq-num"><?=array_sum($uniform['totals'])?></td></tr></tbody></table></div><?php endif;?></details>
    <?php endif;?>

    <div class="hq-bulkbar" data-bulkbar hidden role="region" aria-label="Bulk actions"><span data-bulk-count aria-live="polite">0 selected</span>
      <button type="button" class="btn primary" data-bulk-open="SERVED">Mark as Served</button><button type="button" class="btn" data-bulk-open="CANCEL">Cancel</button><button type="button" class="btn" data-bulk-clear>Clear</button></div>

    <dialog class="hq-dialog" id="hq-bulk-dialog" aria-labelledby="hq-bulk-title"><form method="post" id="hq-bulk-form" action="<?=e(url('hr-service-requests'))?>"><?=csrf_field()?><input type="hidden" name="action" value="core_ticket_bulk"><input type="hidden" name="bulk_action" value="SERVED"><?=hq_back($f)?>
      <h2 id="hq-bulk-title">Confirm</h2><p data-bulk-summary></p><p class="hq-note" data-bulk-skip hidden></p>
      <div class="field"><label for="hq-bulk-remarks">Remarks (optional, shared by every selected request)</label><textarea id="hq-bulk-remarks" name="remarks" rows="3" maxlength="500"></textarea></div>
      <div class="hq-dialog-actions"><button type="button" class="btn" data-dialog-close>Go back</button><button class="btn primary" data-bulk-confirm>Confirm</button></div></form></dialog>
    <dialog class="hq-dialog" id="hq-quick-dialog" aria-labelledby="hq-quick-title"><form method="post" id="hq-quick-form" action="<?=e(url('hr-service-requests'))?>"><?=csrf_field()?><input type="hidden" name="action" value="core_ticket_quick"><input type="hidden" name="ticket_id" value=""><input type="hidden" name="version" value=""><input type="hidden" name="do" value=""><?=hq_back($f)?>
      <h2 id="hq-quick-title">Confirm</h2><p data-quick-summary></p>
      <div class="field"><label for="hq-quick-remarks">Remarks (optional)</label><textarea id="hq-quick-remarks" name="remarks" rows="3" maxlength="500"></textarea></div>
      <div class="hq-dialog-actions"><button type="button" class="btn" data-dialog-close>Go back</button><button class="btn primary">Confirm</button></div></form></dialog>
    <dialog class="hq-drawer" id="hq-drawer" aria-labelledby="hq-drawer-title"><div data-drawer-body aria-live="polite"></div></dialog>
    <script src="public/assets/hr-queue.js?v=20261025-1" defer></script>
    <?php render_portal_footer();
}

/** Drawer content (HTML fragment, no page shell). */
function ticket_queue_preview(array $t,array $f):void
{
    $def=TicketTypes::definition($t['type_code']);?>
    <header class="hq-drawer-head"><div><span class="hq-mono"><?=e($t['ticket_no'])?></span><h2 id="hq-drawer-title"><?=e($t['type_name'])?></h2></div><button type="button" class="iconbtn" data-dialog-close aria-label="Close preview"><?=icon_svg('x')?></button></header>
    <div class="hq-drawer-main">
      <p><?=hq_pill($t['status'],$t['type_code'])?> <?=hq_age($t['age_days'],$t['age_tone'])?></p>
      <dl class="hq-facts"><div><dt>Employee</dt><dd><?=e(trim($t['first_name'].' '.$t['last_name']))?> <span class="hq-mono">(<?=e($t['employee_no'])?>)</span></dd></div>
        <div><dt>Branch</dt><dd><?=e($t['branch_name'])?></dd></div><div><dt>Company</dt><dd><?=e($t['company_name_snapshot'])?></dd></div>
        <div><dt>Filed</dt><dd><?=e(substr((string)($t['filed_at']??$t['created_at']),0,10))?></dd></div>
        <?php foreach($def['fields'] as $key=>$field):if(($field['kind']??'')==='employee')continue;$value=ticket_service_value($field,$t['payload'][$key]??'');if($value==='')continue;?>
        <div><dt><?=e($field['label'])?></dt><dd class="ticket-text"><?=e($value)?></dd></div><?php endforeach;?>
      </dl>
      <?php if($t['steps']):?><h3>Approval chain</h3><ol class="hq-steps"><?php foreach($t['steps'] as $s):?><li><?=e($s['approver_type']==='HR'?'HR review':ucfirst(strtolower($s['approver_type'])))?> · <?=e($s['full_name']?:'Authorized HR reviewer')?> · <?=e(stage_label($s['status']))?></li><?php endforeach;?></ol><?php endif;?>
    </div>
    <footer class="hq-drawer-foot"><?=hq_action_forms($t,$f)?><a class="btn" href="<?=e(url('hr-request',['id'=>$t['id']]))?>">Open full request</a></footer>
<?php }
