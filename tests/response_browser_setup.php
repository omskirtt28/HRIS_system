<?php
declare(strict_types=1);
// Seeds synthetic fixtures for response_browser.cjs in the isolated database. Never run on the configured database.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketService.php';
$f=fixtures();
function form(string $code,array $extra=[]):array{return array_replace(['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'],'submission_key'=>bin2hex(random_bytes(32))],$extra);}
$act=fn(int $id,string $a,string $r,string $n='')=>TicketService::act($id,(int)row('SELECT version FROM tickets WHERE id=?',[$id])['version'],$a,$r,'',0,[],$n);
act($f['employee']);
$f['served']=TicketService::create(form('ATM_REQUEST',['reason'=>'test1']),true);
$f['pending']=TicketService::create(form('ATM_REQUEST',['reason'=>'still waiting']),true);
$f['cancelled']=TicketService::create(form('ID_REQUEST',['reason'=>'duplicate']),true);
act($f['hr']);$act($f['served'],'START','We are processing your ATM request.');$act($f['served'],'COMPLETE','Please collect your ATM card at HR, ground floor, between 9 AM and 4 PM. Bring one valid ID.','ZZ-INTERNAL-NOTE do not disclose');
$act($f['cancelled'],'CANCEL','Duplicate of an earlier request.');
act($f['employee']);TicketService::comment($f['served'],'Thank you, I will pick it up tomorrow.');
act($f['hr']);TicketService::comment($f['served'],'Noted. See you then.');
file_put_contents(dirname(__DIR__).'/storage/slice15-private/response-fixtures.json',json_encode($f,JSON_THROW_ON_ERROR));
echo "PASS response fixtures created\n";
