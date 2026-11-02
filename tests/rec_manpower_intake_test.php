<?php
declare(strict_types=1);
// rec-manpower-intake (SPEC-rec-manpower-intake.md). Synthetic data only; run with HRIS_TEST_DB on a copy that has migration 022.
// TicketService::act opens its own transaction, so this suite cannot wrap itself in one: it leaves synthetic rows in the scratch copy.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/ManpowerIntake.php';
function service_form(string $code,array $extra=[]): array { return array_replace(incident_fields($code),['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'],'submission_key'=>bin2hex(random_bytes(32))],$extra); }
$f=fixtures();
$sfx=strtr(bin2hex(random_bytes(3)),'0123456789','ghijklmnop');
$body=['section_branch'=>'Synthetic section','position_code'=>'SC','employment_type'=>'Probationary','request_type'=>'Additional','quantity'=>'3','job_description_qualifications'=>'Synthetic qualifications','area_department'=>'Synthetic department'];
function file_ticket(array $f,array $extra=[]): int { global $body; act($f['employee']); return TicketService::create(service_form('MANPOWER_REQUEST',$extra+$body),true); }
function tk(int $id): array { return row('SELECT status,version FROM tickets WHERE id=?',[$id]); }
function mr_for(int $ticket): array { return row('SELECT * FROM manpower_requests WHERE ticket_id=?',[$ticket]); }
function mr_count(): int { return (int)row('SELECT COUNT(*) c FROM manpower_requests')['c']; }
function listed_rows(string $filter,int $ticket): array { return array_values(array_filter(ManpowerIntake::list($filter),fn($r)=>(int)($r['ticket_id']??0)===$ticket)); }

// --- employers -----------------------------------------------------------------------------------------
act($f['hr']);
$client=ManpowerIntake::addEmployer('  Zzr '.$sfx.' Trading  ');
check('employer: added with the name trimmed',row('SELECT name FROM clients WHERE id=?',[$client])['name']==='Zzr '.$sfx.' Trading');
check('employer: duplicate name refused regardless of case',rejects(fn()=>ManpowerIntake::addEmployer('zzr '.$sfx.' TRADING')));
check('employer: typing an existing name again reuses it, a new name creates it',ManpowerIntake::employerByName(' ZZR '.$sfx.' trading')===$client&&ManpowerIntake::employerByName('Zzr '.$sfx.' Second')!==$client);
check('employer: empty and over-long names refused',rejects(fn()=>ManpowerIntake::addEmployer('   '))&&rejects(fn()=>ManpowerIntake::addEmployer(str_repeat('x',181))));

// --- a filed ticket shows up for HR without retyping ---------------------------------------------------------
$t1=file_ticket($f);act($f['hr']);
$rows=listed_rows('open',$t1);
check('list: filed ticket appears once, awaiting HR, with its headcount and position',count($rows)===1&&$rows[0]['status']==='AWAITING'&&(int)$rows[0]['requested']===3&&$rows[0]['position_title']==='SC'&&$rows[0]['source']==='Ticket #'.$t1);
check('list: waiting ticket has no recruitment row yet',!mr_for($t1));

// --- approve ---------------------------------------------------------------------------------------------
$before=mr_count();$v=(int)tk($t1)['version'];
check('approve: unknown employer refused and the ticket is untouched',rejects(fn()=>ManpowerIntake::approve($t1,$v,999999999))&&tk($t1)['status']==='PENDING_HR'&&mr_count()===$before);
check('approve: unknown priority refused and the ticket is untouched',rejects(fn()=>ManpowerIntake::approve($t1,$v,$client,'WHENEVER'))&&tk($t1)['status']==='PENDING_HR'&&mr_count()===$before);
act($f['employee']);
check('approve: a non-HR user (the requester) is refused',rejects(fn()=>ManpowerIntake::approve($t1,$v,$client))&&tk($t1)['status']==='PENDING_HR'&&mr_count()===$before);
act($f['hr']);
$id1=ManpowerIntake::approve($t1,$v,$client,'URGENT','',' Sales Clerk ');
$m=mr_for($t1);
check('approve: one APPROVED request linked to the ticket, headcount and employer carried over',(int)$m['id']===$id1&&$m['status']==='APPROVED'&&(int)$m['requested_headcount']===3&&(int)$m['client_id']===$client&&$m['priority']==='URGENT');
check('approve: HR title is used, Probationary maps to FULL_TIME, filled count starts at 0',$m['position_title']==='Sales Clerk'&&$m['employment_type']==='FULL_TIME'&&(int)$m['filled_headcount']===0);
check('approve: approver and time recorded, qualifications kept in the notes',(int)$m['approved_by']===$f['hr']['id']&&$m['approved_at']!==null&&str_contains((string)$m['notes'],'Synthetic qualifications'));
check('approve: requester is the person who filed the ticket',(int)$m['requested_by']===$f['employee']['id']);
check('approve: the ticket is completed and the served date stamped',tk($t1)['status']==='COMPLETED'&&row('SELECT date_served FROM ticket_manpower_requests WHERE ticket_id=?',[$t1])['date_served']===date('Y-m-d'));
$rows=listed_rows('open',$t1);
check('list: approved ticket appears once, from the request row, not as awaiting',count($rows)===1&&$rows[0]['status']==='APPROVED'&&$rows[0]['employer']==='Zzr '.$sfx.' Trading'&&(int)$rows[0]['filled']===0);
$after=mr_count();
check('approve: a second approve (double click or stale page) is refused and adds no row',rejects(fn()=>ManpowerIntake::approve($t1,$v,$client))&&mr_count()===$after);
check('approve: the request is visible to the recruitment list that rec-jobs reads',in_array($id1,array_map('intval',array_column(RecruitmentRepository::manpowerRequests(),'id')),true));

// --- mapping for the other values ------------------------------------------------------------------------
$t2=file_ticket($f,['employment_type'=>'Seasonal','position_code'=>'VS','quantity'=>'1']);act($f['hr']);
$id2=ManpowerIntake::approve($t2,(int)tk($t2)['version'],$client);$m2=mr_for($t2);
check('approve: Seasonal maps to CONTRACT, title defaults to the position code, priority defaults to NORMAL',$m2['employment_type']==='CONTRACT'&&$m2['position_title']==='VS'&&$m2['priority']==='NORMAL');

// --- decline -----------------------------------------------------------------------------------------------
$t3=file_ticket($f);act($f['hr']);$before=mr_count();
check('decline: a remark is required and the ticket stays waiting',rejects(fn()=>ManpowerIntake::decline($t3,(int)tk($t3)['version'],'   '))&&tk($t3)['status']==='PENDING_HR');
ManpowerIntake::decline($t3,(int)tk($t3)['version'],'Budget not approved');
check('decline: the ticket is rejected and no recruitment row is created',tk($t3)['status']==='REJECTED'&&!mr_for($t3)&&mr_count()===$before);
check('decline: hidden from the default list, shown under the declined filter',!listed_rows('open',$t3)&&count(listed_rows('declined',$t3))===1&&listed_rows('declined',$t3)[0]['status']==='DECLINED');
check('decline: a declined ticket can no longer be approved',rejects(fn()=>ManpowerIntake::approve($t3,(int)tk($t3)['version'],$client))&&!mr_for($t3));

act($f['employee']);$seen=array_column(TicketRepository::detail($t3)['responses'],'remarks');
check('decline: the requester sees the HR reason on the ticket',in_array('Budget not approved',$seen,true));

// --- the ticket screens cannot skip the recruitment request ------------------------------------------------------
$t5=file_ticket($f);act($f['hr']);
check('serve: marking the ticket Served from the ticket screens is refused until it is approved here',rejects(fn()=>TicketService::act($t5,(int)tk($t5)['version'],'COMPLETE'))&&tk($t5)['status']==='PENDING_HR'&&!mr_for($t5));
act($f['manager']);
check('approve: a signed-in user without the HR review right is refused',rejects(fn()=>ManpowerIntake::approve($t5,(int)tk($t5)['version'],$client))&&rejects(fn()=>ManpowerIntake::decline($t5,(int)tk($t5)['version'],'No'))&&!mr_for($t5));
act($f['hr']);

// --- manual requests and the filled count are untouched ------------------------------------------------------
$manual=insert('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,employment_type,priority,status) VALUES(?,?,?,?,?,?,"SUBMITTED")',['MR-T-'.$sfx,$client,'Manual position',2,'FULL_TIME','NORMAL']);
$snap=hash('sha256',json_encode(row('SELECT * FROM manpower_requests WHERE id=?',[$manual])));
$t4=file_ticket($f);act($f['hr']);ManpowerIntake::approve($t4,(int)tk($t4)['version'],$client);
$man=array_values(array_filter(ManpowerIntake::list('open'),fn($r)=>(int)$r['id']===$manual));
$manAll=array_values(array_filter(ManpowerIntake::list('all'),fn($r)=>(int)$r['id']===$manual));
check('list: a request with no ticket (an HR posting, D5) is hidden from Open and labelled HR posting under All',count($man)===0&&count($manAll)===1&&$manAll[0]['source']==='HR posting'&&$manAll[0]['ticket_id']===null);
check('approve: manual requests and every filled count are unchanged',$snap===hash('sha256',json_encode(row('SELECT * FROM manpower_requests WHERE id=?',[$manual])))&&(int)row('SELECT COALESCE(SUM(filled_headcount),0) s FROM manpower_requests WHERE ticket_id IS NOT NULL')['s']===0);
check('list: every ticket appears exactly once in the all view',count(array_filter(ManpowerIntake::list('all'),fn($r)=>(int)($r['ticket_id']??0)===$t1))===1);
finish();
