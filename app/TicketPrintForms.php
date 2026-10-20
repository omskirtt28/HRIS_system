<?php
declare(strict_types=1);
function ticket_print_forms():void {
 TicketRepository::uid();if(!TicketRepository::isHr())throw new RuntimeException('HR access is required for printable forms.');
 $forms=require dirname(__DIR__).'/config/hr_print_forms.php';$key=is_string($_GET['form']??null)?$_GET['form']:'';
 if($key===''){
  ticket_header('hr-print-forms','Printable HR forms');page_head('HR Forms','Printable HR forms');echo '<section class="panel"><div class="panel-body"><p>Blank forms only. Nothing entered or printed is saved.</p><ul>';
  foreach($forms as $id=>$form)echo '<li><a href="'.e(url('hr-print-forms',['form'=>$id,'company'=>'SW'])).'">'.e($form['title']).'</a></li>';
  echo '</ul></div></section>';render_portal_footer();return;
 }
 if(!isset($forms[$key]))throw new RuntimeException('Choose an available printable form.');
 $company=(string)($_GET['company']??'SW');if(!in_array($company,['SW','OFJI'],true))throw new RuntimeException('Choose SW or OFJI.');
 $form=$forms[$key];$header=$company==='OFJI'?"OFJI - Ocampo's":'SW';
 ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($form['title'])?></title><style>
 @page{size:A4 portrait;margin:12mm}*{box-sizing:border-box}body{font:11pt Arial,sans-serif;color:#111;background:#eee;margin:0}.sheet{width:186mm;margin:12mm auto;padding:0;background:white}header{text-align:center;border-bottom:2px solid #111;padding:5mm 0}header strong{display:block;font-size:16pt}h1{text-align:center;font-size:17pt;margin:6mm 0}table{border-collapse:collapse;width:calc(100% - 2px);margin:0 1px}td{border:1px solid #333;vertical-align:top;padding:2.5mm;font-size:10pt}.no-print{padding:14px;background:white;display:flex;gap:12px;align-items:center;justify-content:center}.no-print select,.no-print button{padding:8px}@media print{body{background:white}.sheet{width:auto;margin:0}.no-print{display:none}tr{break-inside:avoid}}@media screen and (max-width:740px){.sheet{width:96%;margin:12px auto}.no-print{flex-wrap:wrap}}
 </style></head><body><div class="no-print"><a href="<?=e(url('hr-print-forms'))?>">All HR forms</a><form method="get"><input type="hidden" name="page" value="hr-print-forms"><input type="hidden" name="form" value="<?=e($key)?>"><label for="print-company">Company header</label><select id="print-company" name="company" onchange="this.form.submit()"><option value="SW" <?=$company==='SW'?'selected':''?>>SW</option><option value="OFJI" <?=$company==='OFJI'?'selected':''?>>OFJI - Ocampo's</option></select></form><button onclick="window.print()">Print blank form</button><span>Blank form · no data saved</span></div><main class="sheet"><header><strong>Prime Mover Business Solutions, Inc.</strong><div>PMBSI · <?=e($header)?></div></header><h1><?=e($form['title'])?></h1><table><?php foreach($form['rows'] as [$label,$height]):?><tr><td style="height:<?=(int)$height?>mm"><?=e($label)?><br><br></td></tr><?php endforeach;?></table></main></body></html><?php
}
