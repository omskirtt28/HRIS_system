<?php
// Migration 010: Manpower is filed by leads and routed HR_ONLY; HR sets the status.
$manpower=['section_branch'=>'Synthetic section','position_code'=>'SC','employment_type'=>'Probationary','request_type'=>'Additional','quantity'=>'1','job_description_qualifications'=>'Synthetic qualifications','area_department'=>'Synthetic department'];
$recruitmentBefore=hash('sha256',json_encode(db()->query('SELECT * FROM manpower_requests ORDER BY id')->fetchAll()));
$hrOnly=row('SELECT routing_profile,routing_config_json FROM ticket_types WHERE code="MANPOWER_REQUEST"');
check('Manpower type routed HR_ONLY',$hrOnly['routing_profile']==='HR_ONLY'&&$hrOnly['routing_config_json']===null);
act($f['employee']);$id=TicketService::create(service_form('MANPOWER_REQUEST',$manpower+['requested_by'=>999999]),true);$t=TicketRepository::detail($id);
check('HO Manpower goes straight to HR',array_column($t['steps'],'approver_type')===['HR']&&$t['status']==='PENDING_HR');
check('Manpower requester auto and cutoff null',(int)row('SELECT requested_by FROM ticket_manpower_requests WHERE ticket_id=?',[$id])['requested_by']===$f['employee']['id']&&$t['payroll_period_id']===null);
check('Replacement requires employee',rejects(fn()=>TicketService::create(service_form('MANPOWER_REQUEST',array_replace($manpower,['request_type'=>'Replacement'])),true)));
check('Additional rejects replacement employee',rejects(fn()=>TicketService::create(service_form('MANPOWER_REQUEST',$manpower+['replacement_of_employee_id'=>(string)$f['manager_employee']]),true)));
act($f['manager']);check('Manager has no Manpower approval step',rejects(fn()=>service_action($id,'APPROVE')));
act($f['hr']);service_action($id,'RETURN','Clarify');
act($f['employee']);TicketService::edit($id,(int)TicketRepository::find($id)['version'],service_form('MANPOWER_REQUEST',$manpower),true);
check('Resubmitted Manpower stays HR only',array_values(array_unique(array_column(TicketRepository::detail($id)['steps'],'approver_type')))===['HR']);
act($f['hr']);service_action($id,'COMPLETE');service_action($id,'COMPLETE');
check('Manpower Served records date only',row('SELECT date_served FROM ticket_manpower_requests WHERE ticket_id=?',[$id])['date_served']===date('Y-m-d')&&!row('SELECT id FROM ticket_effects WHERE ticket_id=?',[$id]));
act($f['outsider']);$id=TicketService::create(service_form('MANPOWER_REQUEST',$manpower),true);
check('Branch Manpower no longer needs an ADL',array_column(TicketRepository::detail($id)['steps'],'approver_type')===['HR']);
act($f['hr']);service_action($id,'COMPLETE');
// A ticket filed under the old chain keeps its frozen Manager step after a return.
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$f['manager_employee'],$f['requester']]);
db()->exec('UPDATE ticket_types SET routing_profile="HO_MANAGER_THEN_HR",routing_config_json=\'{"HEAD_OFFICE":"HO_MANAGER_THEN_HR","BRANCH":"BRANCH_ADL_THEN_HR"}\' WHERE code="MANPOWER_REQUEST"');
try{act($f['employee']);$legacy=TicketService::create(service_form('MANPOWER_REQUEST',$manpower),true);}
finally{db()->exec('UPDATE ticket_types SET routing_profile="HR_ONLY",routing_config_json=NULL WHERE code="MANPOWER_REQUEST"');}
act($f['manager']);service_action($legacy,'RETURN','Clarify');
act($f['employee']);TicketService::edit($legacy,(int)TicketRepository::find($legacy)['version'],service_form('MANPOWER_REQUEST',$manpower),true);
$steps=TicketRepository::detail($legacy)['steps'];check('Open legacy Manpower keeps frozen Manager step',$steps[count($steps)-2]['approver_type']==='MANAGER'&&(int)$steps[count($steps)-2]['approver_user_id']===$f['manager']['id']);
act($f['manager']);service_action($legacy,'APPROVE');act($f['hr']);service_action($legacy,'COMPLETE');
check('Recruitment manpower records untouched',$recruitmentBefore===hash('sha256',json_encode(db()->query('SELECT * FROM manpower_requests ORDER BY id')->fetchAll())));
