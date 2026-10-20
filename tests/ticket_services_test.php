<?php
declare(strict_types=1);
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/TicketService.php';
$f=fixtures();
function service_form(string $code,array $extra=[]):array {
    return array_replace(['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'],'submission_key'=>bin2hex(random_bytes(32))],$extra);
}
function service_action(int $id,string $decision,string $remarks=''):void {
    TicketService::act($id,(int)TicketRepository::find($id)['version'],$decision,$remarks);
}
foreach(['ATM_REQUEST','ID_REQUEST'] as $code){
 act($f['employee']);$data=service_form($code,['reason'=>'Synthetic own request','requester_user_id'=>$f['outsider']['id'],'payroll_period_id'=>999]);
 $id=TicketService::create($data,true);$t=TicketRepository::detail($id);
 check($code.' HR-only and no cutoff',$t['status']==='PENDING_HR' && $t['payroll_period_id']===null && array_column($t['steps'],'approver_type')===['HR']);
 check($code.' requester derived from account',(int)$t['requester_user_id']===$f['employee']['id']);
 check($code.' self approval blocked',rejects(fn()=>service_action($id,'COMPLETE')));
 check($code.' missing reason rejected',rejects(fn()=>TicketService::create(service_form($code),true)));
 $second=TicketService::create(service_form($code,['reason'=>'Second open request']),true);check($code.' duplicates allowed',$second!==$id);
 act($f['outsider']);check($code.' IDOR read/write denied',rejects(fn()=>TicketRepository::detail($id))&&rejects(fn()=>TicketService::comment($id,'Forged')));
 act($f['hr']);check($code.' return requires remarks',rejects(fn()=>service_action($id,'RETURN')));
 service_action($id,'START');service_action($id,'COMPLETE');service_action($id,'COMPLETE');
 check($code.' completion retry has no effect',TicketRepository::find($id)['status']==='COMPLETED' && !row('SELECT id FROM ticket_effects WHERE ticket_id=?',[$id]));
 check($code.' terminal transitions denied',rejects(fn()=>service_action($id,'RETURN','No')));
 service_action($second,'START');service_action($second,'CANCEL');check($code.' HR can cancel in progress',TicketRepository::find($second)['status']==='CANCELLED');
}
// The explicitly approved HR gate fix applies to the shared engine.
act($f['employee']);db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$f['manager_employee'],$f['requester']]);
$date='2018-01-10';$cutoff=row('SELECT id FROM payroll_cutoffs WHERE period_start=? AND period_end=?',['2018-01-01','2018-01-15']);
if(!$cutoff)insert('INSERT INTO payroll_cutoffs(code,period_start,period_end,status) VALUES(?,?,?,"OPEN")',[$f['tag'],'2018-01-01','2018-01-15']);
$id=TicketService::create(service_form('PAYROLL_CONCERN',['concern_date'=>$date,'explanation'=>'Gate test']),true);
act($f['hr']);check('HR cannot return/reject before Manager approval',!TicketService::permissions(TicketRepository::find($id))['return_reject'] && rejects(fn()=>service_action($id,'REJECT','Too early')));
if(row('SELECT id FROM ticket_types WHERE code="UNIFORM_REQUEST"'))require __DIR__.'/ticket_uniform_cases.php';
finish();
