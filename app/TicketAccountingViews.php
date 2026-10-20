<?php
declare(strict_types=1);
function ticket_accounting_members_page():void {
 if(!TicketRepository::isHr())throw new RuntimeException('Only HR can manage Accounting members.');
 ticket_header('hr-ticket-accounting-members','Accounting members');page_head('HR Request Setup','Accounting members');
 $rows=db()->query('SELECT m.*,u.full_name FROM ticket_accounting_memberships m JOIN users u ON u.id=m.user_id ORDER BY u.full_name')->fetchAll(); ?>
 <section class="panel"><div class="panel-body"><p>Accounting is an additional ticket role. HR must select the exact account; employee roles remain unchanged.</p><ul><?php foreach($rows as $r):?><li><?=e($r['full_name'])?> · <?=$r['active']?'Active':'Removed'?></li><?php endforeach;?></ul>
 <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="core_ticket_accounting_member"><label for="member">Account</label><select id="member" name="user_id" required><option value="">Choose account</option><?php foreach(db()->query('SELECT id,full_name,email FROM users WHERE status="ACTIVE" ORDER BY full_name') as $u):?><option value="<?=(int)$u['id']?>"><?=e($u['full_name'].' · '.$u['email'])?></option><?php endforeach;?></select><button class="btn" name="active" value="1">Add Accounting member</button><button class="btn" name="active" value="0">Remove Accounting member</button></form>
 <h2>Seed results</h2><ul><?php foreach(db()->query('SELECT employee_no,result FROM ticket_accounting_seed_results ORDER BY employee_no') as $r):?><li><?=e($r['employee_no'].' · '.$r['result'])?></li><?php endforeach;?></ul><p>Jeniffer Dela Cruz Maglalang: HR must link the exact active account manually.</p></div></section>
 <?php render_portal_footer();
}
function ticket_netsuite_panel(array $t):void {
 if($t['type_code']!=='NETSUITE_CONCERN')return;
 ?><section class="panel ticket-panel"><div class="panel-body"><h2>HR and Accounting remarks</h2><?php foreach(TicketAccounting::remarks((int)$t['id']) as $r):?><article><strong><?=e($r['lane'].' · '.$r['full_name'].' · '.$r['created_at'])?></strong><p class="ticket-text"><?=e($r['body'])?></p></article><?php endforeach;
 foreach(['HR'=>TicketRepository::isHr(),'ACCOUNTING'=>TicketAccounting::canView()&&$t['status']==='COMPLETED'] as $lane=>$allowed):if(!$allowed)continue;?>
 <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="core_ticket_netsuite_remark"><input type="hidden" name="ticket_id" value="<?=(int)$t['id']?>"><input type="hidden" name="lane" value="<?=e($lane)?>"><div class="field"><label for="remarks-<?=e($lane)?>"><?=e($lane)?> remarks</label><textarea id="remarks-<?=e($lane)?>" name="body" required maxlength="5000" rows="3"></textarea></div><button class="btn">Save <?=e($lane)?> remarks</button></form>
 <?php endforeach;?></div></section><?php
}
