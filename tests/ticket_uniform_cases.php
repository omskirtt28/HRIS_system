<?php
act($f['employee']);$uniform=['position'=>'Sales Crew','size'=>'M','company'=>'OFJI','branch'=>(string)$f['branch'],'quantity'=>'2','id_lace'=>'YES'];
$id=TicketService::create(service_form('UNIFORM_REQUEST',$uniform),true);$t=TicketRepository::find($id);
check('Uniform persists relational fields',row('SELECT company,quantity,id_lace FROM uniform_requests WHERE ticket_id=?',[$id])==['company'=>'OFJI','quantity'=>2,'id_lace'=>1] && $t['payroll_period_id']===null);
foreach(['size'=>'BAD','company'=>'BAD','quantity'=>'11','id_lace'=>'MAYBE','branch'=>'99999999','position'=>''] as $key=>$bad)check('Uniform rejects invalid '.$key,rejects(fn()=>TicketService::create(service_form('UNIFORM_REQUEST',array_replace($uniform,[$key=>$bad])),true)));
act($f['hr']);$list=TicketRepository::listing('services',['type_code'=>'UNIFORM_REQUEST','company_label'=>'OFJI','branch_id'=>$f['branch'],'status'=>'PENDING_HR']);
check('Uniform queue filters target company/branch/status',in_array($id,array_column($list['rows'],'id')));
service_action($id,'COMPLETE');check('Uniform no side effect',!row('SELECT id FROM ticket_effects WHERE ticket_id=?',[$id]));
