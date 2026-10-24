<?php
act($f['employee']);$id=TicketService::create(service_form('INCIDENT_REPORT',['title'=>'Synthetic incident','explanation'=>'Synthetic incident explanation']),true);$t=TicketRepository::detail($id);
check('Incident publishes with no approval chain',$t['status']==='SUBMITTED'&&!$t['steps']&&$t['payroll_period_id']===null);
foreach(['title'=>str_repeat('X',201),'explanation'=>str_repeat('X',5001)] as $key=>$bad)check('Incident limits '.$key,rejects(fn()=>TicketService::create(service_form('INCIDENT_REPORT',array_replace(['title'=>'Valid','explanation'=>'Valid'],[$key=>$bad])),true)));
check('Author cannot forge staff-only comment',rejects(fn()=>TicketService::comment($id,'Hidden','STAFF_ONLY')));
TicketService::comment($id,'Shared author comment');
act($f['outsider']);check('Incident same-branch outsider denied',rejects(fn()=>TicketRepository::detail($id)));
check('Incident outsider feed excludes ticket',!in_array($id,array_column(TicketRepository::listing('feed')['rows'],'id')));
act($f['hr']);TicketService::comment($id,'Private HR staff note','STAFF_ONLY');$all=TicketRepository::detail($id);
check('HR sees staff-only notes',count($all['comments'])===2);
check('Shared HR queue excludes Incident',!in_array($id,array_column(TicketRepository::listing('services')['rows'],'id')));
act($f['employee']);$own=TicketRepository::detail($id);check('Author cannot read staff-only comment or event',count($own['comments'])===1&&count($own['events'])===count($all['events'])-1);
check('Incident has no approval/completion action',rejects(fn()=>service_action($id,'APPROVE'))&&rejects(fn()=>service_action($id,'COMPLETE')));
db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code="ticket.sensitive.view"')->execute([$f['manager']['role_id']]);
act($f['manager']);check('Sensitive-view holder is NOT a case reader any more (plan 2026-10-25-incident-report-module)',rejects(fn()=>TicketRepository::detail($id))&&!in_array($id,array_column(TicketRepository::listing('feed')['rows'],'id')));
check('Sensitive-view holder cannot change the status',rejects(fn()=>service_action($id,'CANCEL'))&&rejects(fn()=>TicketService::incidentStatus($id,1,'UNDER_REVIEW','x')));
act($f['hr']);service_action($id,'CANCEL');check('HR can cancel Incident without approval chain',TicketRepository::find($id)['status']==='CANCELLED');
require_once dirname(__DIR__).'/app/TicketPrintForms.php';require_once dirname(__DIR__).'/app/TicketViews.php';
$beforeTickets=(int)db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn();$forms=require dirname(__DIR__).'/config/hr_print_forms.php';
foreach($forms as $key=>$form){$_GET=['form'=>$key,'company'=>'OFJI'];ob_start();ticket_print_forms();$html=ob_get_clean();check('Blank print layout '.$key,str_contains($html,'@page{size:A4')&&str_contains($html,e($form['title']))&&str_contains($html,"Ocampo&#039;s")&&!str_contains($html,'name="action"'));}
check('Printing creates no tickets',(int)db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn()===$beforeTickets);
act($f['outsider']);check('Non-HR print URL denied',rejects(fn()=>ticket_print_forms()));
