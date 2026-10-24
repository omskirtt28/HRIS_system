<?php
declare(strict_types=1);
// Seeds synthetic fixtures for hr_ops_browser.cjs in the isolated database. Never run on the configured database.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketService.php';
$f=fixtures();
foreach(['dashboard.hr.view'] as $code){$p=row('SELECT id FROM permissions WHERE code=?',[$code]);if($p)db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$f['hr']['role_id'],$p['id']]);}
function form(string $code,array $extra=[]):array{return array_replace(incident_fields($code),['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'],'submission_key'=>bin2hex(random_bytes(32))],$extra);}
$ids=[];
act($f['employee']);
$sizes=['S','M','M','L','XL','M','2XL','L'];
foreach($sizes as $i=>$size)$ids[]=TicketService::create(form('UNIFORM_REQUEST',['position'=>'Sales Crew','size'=>$size,'company'=>$i%2?'OFJI':'SW','branch'=>(string)($i%3?$f['branch']:$f['ho']),'quantity'=>(string)(1+$i%3),'id_lace'=>'NO']),true);
foreach(range(1,6) as $i)$ids[]=TicketService::create(form('ATM_REQUEST',['reason'=>'Lost my ATM card at the branch and need a replacement as soon as possible ('.$i.')']),true);
foreach(range(1,4) as $i)$ids[]=TicketService::create(form('ID_REQUEST',['reason'=>'ID damaged '.$i]),true);
$ids[]=TicketService::create(form('MANPOWER_REQUEST',['section_branch'=>'T','position_code'=>'BS','employment_type'=>'Seasonal','request_type'=>'Additional','quantity'=>'2','job_description_qualifications'=>'x','area_department'=>'x']),true);
$ids[]=TicketService::create(form('NETSUITE_CONCERN',['concern'=>'NetSuite access']),true);
TicketService::create(form('INCIDENT_REPORT',['title'=>'Browser fixture incident','explanation'=>'Synthetic']),true);
foreach($ids as $n=>$id)db()->prepare('UPDATE tickets SET filed_at=DATE_SUB(NOW(),INTERVAL ? DAY) WHERE id=?')->execute([$n%10,$id]);
act($f['hr']);$svc=fn(int $id,string $a)=>TicketService::act($id,(int)row('SELECT version FROM tickets WHERE id=?',[$id])['version'],$a);
$svc($ids[8],'START');$svc($ids[9],'COMPLETE');$svc($ids[10],'CANCEL');
$f['ids']=$ids;
file_put_contents(dirname(__DIR__).'/storage/slice15-private/hr-ops-fixtures.json',json_encode($f,JSON_THROW_ON_ERROR));
echo "PASS synthetic HR queue fixtures created (".count($ids)." tickets)\n";
