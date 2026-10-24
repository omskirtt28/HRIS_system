<?php
declare(strict_types=1);

/** Panel on the request page: download for the requester and HR once Served, a short note otherwise. */
function ticket_coe_panel(array $t): void
{
    $doc=TicketCoe::document((int)$t['id']);$own=(int)$t['requester_user_id']===TicketRepository::uid();
    if(!$own&&!TicketRepository::isHr())return;
    ?>
    <section class="panel ticket-panel"><div class="panel-body"><h2>Certificate of Employment</h2>
    <?php if($t['status']==='COMPLETED'&&$doc):?><p>Issued <?=e($doc['date_issued'])?>. The certificate is a Word file; open it to print or send it.</p>
      <a class="btn primary" href="<?=e(url('hr-coe-download',['id'=>$t['id']]))?>">Download certificate (.docx)</a>
    <?php elseif($t['status']==='COMPLETED'):?><p>This request was served before certificates were generated here. Please ask HR.</p>
    <?php elseif(in_array($t['status'],['CANCELLED','REJECTED'],true)):?><p>No certificate was issued for this request.</p>
    <?php else:?><p><?=TicketRepository::isHr()&&!$own?'Use "Preview &amp; Served" to check the values, then confirm. The certificate is generated at that moment.':'HR is preparing your certificate. You can download it here once it is Served.'?></p>
    <?php endif;?></div></section>
<?php }

function ticket_coe_preview_page(array $d): void
{
    $t=$d['ticket'];$v=$d['values'];$labels=['employee_name'=>'Employee name','position'=>'Position','hire_date'=>'Hire date','company'=>'Company','purpose'=>'Purpose','date_issued'=>'Date issued (today, the Served date)','salary'=>'Salary','signatory_name'=>'Signatory name','signatory_title'=>'Signatory title'];
    render_portal_header((Auth::user()['role_portal']??'')==='admin'?'admin':'hr','hr-service-requests','Preview Certificate of Employment');
    echo '<link rel="stylesheet" href="public/assets/tickets.css?v=20261025-17">';
page_head('HR Requests / Certificate of Employment','Preview · '.$t['ticket_no'],'<a class="btn" href="'.e(url('hr-request',['id'=>$t['id']])).'">Back to request</a>'); ?>
    <section class="panel ticket-panel"><div class="panel-body">
      <p>These are the exact values that will be written into the certificate (<?=$d['template']?'template version '.(int)$d['template']['version_no'].' · '.e($d['template']['original_name']):'built-in default layout, no template uploaded yet'?>). Purpose requested: <strong><?=e($d['request']['purpose'])?></strong>, needed by <?=e($d['request']['needed_by'])?>, delivery: <?=e($d['request']['delivery'])?>.</p>
      <?php foreach($d['problems'] as $problem):?><div class="alert error"><?=e($problem)?></div><?php endforeach;?>
      <table class="tbl"><tbody><?php foreach($labels as $k=>$label):if($k==='salary'&&!$d['include_salary'])continue;?><tr><th scope="row"><?=e($label)?></th><td><?=$v[$k]!==''?e($v[$k]):'<span class="badge amber">blank</span>'?></td></tr><?php endforeach;?></tbody></table>
      <?php if($v['signatory_name']===''):?><p class="ticket-note">The signatory is blank. HR can set it under HR Request Setup &rarr; COE Template.</p><?php endif;?>
      <form method="get" class="ticket-actions"><input type="hidden" name="page" value="hr-coe-preview"><input type="hidden" name="id" value="<?=(int)$t['id']?>">
        <label><input type="checkbox" name="include_salary" value="1" <?=$d['include_salary']?'checked':''?>> Include salary (HR decision; off by default)</label>
        <button class="btn">Update preview</button></form>
    </div></section>
    <form method="post" class="panel ticket-panel"><?=csrf_field()?><input type="hidden" name="action" value="core_ticket_act"><input type="hidden" name="ticket_id" value="<?=(int)$t['id']?>"><input type="hidden" name="version" value="<?=(int)$t['version']?>"><input type="hidden" name="decision" value="COMPLETE"><input type="hidden" name="include_salary" value="<?=$d['include_salary']?'1':'0'?>"><div class="panel-body">
      <div class="field"><label for="coe-remarks">Remarks (optional)</label><textarea id="coe-remarks" name="remarks" rows="2" maxlength="500"></textarea></div>
      <div class="ticket-actions"><button class="btn primary" <?=$d['problems']?'disabled':''?>>Confirm Served and generate certificate</button><a class="btn" href="<?=e(url('hr-request',['id'=>$t['id']]))?>">Cancel</a></div>
    </div></form>
    <?php render_portal_footer();
}

function ticket_coe_template_page(array $templates,array $settings,?array $active): void
{
    render_portal_header((Auth::user()['role_portal']??'')==='admin'?'admin':'hr','hr-coe-template','COE Template');
    echo '<link rel="stylesheet" href="public/assets/tickets.css?v=20261025-17">';
page_head('HR Request Setup','COE Template'); ?>
    <section class="panel ticket-panel"><div class="panel-head"><div><h2>Current template</h2></div></div><div class="panel-body">
      <?php if($active):?><p>Version <?=(int)$active['version_no']?> · <?=e($active['original_name'])?> · uploaded <?=e($active['uploaded_at'])?></p>
      <?php else:?><p>No template uploaded. Certificates use a simple built-in layout until you upload one.</p><?php endif;?>
    </div></section>
    <form method="post" enctype="multipart/form-data" class="panel ticket-panel"><?=csrf_field()?><input type="hidden" name="action" value="coe_template_upload"><div class="panel-head"><div><h2>Upload a new template</h2><p>Word .docx only, up to 2 MB. Becomes the active version; earlier versions stay in the history below.</p></div></div><div class="panel-body">
      <div class="field"><label for="coe-file">Template file (.docx)</label><input id="coe-file" type="file" name="template" accept=".docx" required></div>
      <div class="field"><label for="coe-note">Note (optional)</label><input id="coe-note" name="note" maxlength="200"></div>
      <p>Required placeholders: <?php foreach(TicketCoe::REQUIRED as $p):?><code>{{<?=e($p)?>}}</code> <?php endforeach;?><br>Optional: <code>{{salary}}</code> (filled only when HR ticks Include salary), <code>{{signatory_name}}</code>, <code>{{signatory_title}}</code>.</p>
      <button class="btn primary">Upload template</button>
    </div></form>
    <form method="post" class="panel ticket-panel"><?=csrf_field()?><input type="hidden" name="action" value="coe_settings_save"><div class="panel-head"><div><h2>Signatory and company</h2><p>Used for the optional signatory placeholders and the {{company}} value. Left blank, they print blank.</p></div></div><div class="panel-body">
      <?php foreach(TicketCoe::SETTINGS as $k=>$label):?><div class="field"><label for="coe-<?=e($k)?>"><?=e($label)?></label><input id="coe-<?=e($k)?>" name="<?=e($k)?>" maxlength="150" value="<?=e($settings[$k])?>"></div><?php endforeach;?>
      <button class="btn">Save</button>
    </div></form>
    <section class="panel ticket-panel ticket-scroll"><div class="panel-head"><div><h2>Version history</h2></div></div><table class="tbl"><thead><tr><th>Version</th><th>File</th><th>Uploaded</th><th>Size</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach($templates as $row):?><tr><td>v<?=(int)$row['version_no']?></td><td><?=e($row['original_name'])?><?=$row['note']?'<div><small>'.e($row['note']).'</small></div>':''?></td><td><?=e($row['uploaded_at'])?><div><small><?=e($row['uploader'])?></small></div></td><td><?=number_format($row['file_size']/1024,1)?> KB</td><td><?=$row['is_active']?'<span class="badge green">Active</span>':'<span class="badge gray">Earlier</span>'?></td>
        <td><a class="btn sm" href="<?=e(url('hr-coe-template-file',['id'=>$row['id']]))?>">Download</a>
        <?php if(!$row['is_active']):?><form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="coe_template_activate"><input type="hidden" name="template_id" value="<?=(int)$row['id']?>"><button class="btn sm">Make active</button></form><?php endif;?></td></tr><?php endforeach;
      if(!$templates):?><tr><td colspan="6" class="empty">No templates uploaded yet.</td></tr><?php endif;?></tbody></table></section>
    <?php render_portal_footer();
}
