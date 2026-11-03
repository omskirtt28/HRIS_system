<?php
declare(strict_types=1);
// rec-endorse-manager (SPEC-rec-endorse-manager.md). Synthetic data only; run with HRIS_TEST_DB on a scratch copy that already has migration 023 applied.
require __DIR__.'/ticket_test_support.php';
$f=fixtures();
$sfx=strtr(bin2hex(random_bytes(3)),'0123456789','ghijklmnop');
foreach(['recruitment.view','recruitment.manage'] as $code){db()->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code=?')->execute([$f['hr']['role_id'],$code]);}

function real_user(string $roleCode,string $label,string $status='ACTIVE'): array {
    $r=row('SELECT id,code,portal FROM roles WHERE code=?',[$roleCode]);
    $email=strtolower('zz_'.$label.'_'.bin2hex(random_bytes(3))).'@example.test';
    $id=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,?)',[$r['id'],'Zz '.$label,$email,password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT),$status]);
    return ['id'=>$id,'role_id'=>(int)$r['id'],'role_code'=>$r['code'],'role_portal'=>$r['portal'],'name'=>'Zz '.$label,'role_name'=>$r['code'],'email'=>$email,'client_id'=>null];
}
function make_app(string $stage,int $mr,int $client,int $job): int {
    $applicant=insert('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")',['APP-ZE-'.bin2hex(random_bytes(4)),'Zz','Candidate','zz_'.bin2hex(random_bytes(4)).'@example.test','09170000000']);
    return insert('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code=?),NOW(),NOW(),"ACTIVE")',['PMBSI-ZE-'.bin2hex(random_bytes(4)),$applicant,$job,$mr,$client,$stage]);
}
function stage_of(int $app): string { return row('SELECT s.code FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.id=?',[$app])['code']; }
function reviewer_row(int $app,int $uid): array { return row('SELECT * FROM application_reviewers WHERE application_id=? AND reviewer_user_id=?',[$app,$uid]); }
function notes_for(int $uid,string $kind): int { return (int)row('SELECT COUNT(*) c FROM notifications WHERE recipient_user_id=? AND kind=?',[$uid,$kind])['c']; }

$client=insert('INSERT INTO clients(code,name) VALUES(?,?)',['ZE'.$sfx,'Zze '.$sfx.' Trading']);
$mr=insert('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status) VALUES(?,?,?,?,0,"REGULAR","NORMAL","APPROVED")',['MR-ZE-'.bin2hex(random_bytes(4)),$client,'Synthetic clerk',2]);
$job=insert('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,1,"OPEN","Synthetic branch","REGULAR")',['JOB-ZE-'.$sfx,'zze-'.$sfx,'Synthetic clerk','x',$client,$mr]);

$mgr1=real_user('MANAGER','mgr1');$mgr2=real_user('MANAGER','mgr2');$adl=real_user('ADL','adl');$tc=real_user('TC','tc');
$plain=real_user('EMPLOYEE','plain');$gone=real_user('MANAGER','gone','INACTIVE');

// --- migration / permission ---------------------------------------------------------------------------------
$perm=db()->query('SELECT GROUP_CONCAT(r.code ORDER BY r.code) c FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id JOIN roles r ON r.id=rp.role_id WHERE p.code="recruitment.review" AND r.code NOT LIKE "T15\_%"')->fetchColumn();
check('permission: recruitment.review is granted to MANAGER, ADL, SC, TC, OIC and MT only (rec-screening-score added OIC and MT)',$perm==='ADL,MANAGER,MT,OIC,SC,TC');
check('permission: HR_USER does not get recruitment.review (it is not an HR screen)',(int)row('SELECT COUNT(*) c FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.code="HR_USER" AND p.code="recruitment.review"')['c']===0);

// --- label ----------------------------------------------------------------------------------------------------
check('label: stage CLIENT_REVIEW reads Manager Review everywhere (label only, code unchanged)',stage_label('CLIENT_REVIEW')==='Manager Review'
    &&array_values(array_filter(RecruitmentRepository::stages(),fn($s)=>$s['code']==='CLIENT_REVIEW'))[0]['name']==='Manager Review'
    &&array_values(array_filter(RecruitmentRepository::stageCounts(),fn($s)=>$s['code']==='CLIENT_REVIEW'))[0]['name']==='Manager Review');

// --- who can be picked ----------------------------------------------------------------------------------------
$ids=array_map('intval',array_column(RecruitmentRepository::reviewerChoices(),'id'));
check('choices: active Manager, ADL and TC users are offered',in_array($mgr1['id'],$ids,true)&&in_array($adl['id'],$ids,true)&&in_array($tc['id'],$ids,true));
check('choices: a plain employee and an inactive manager are not offered',!in_array($plain['id'],$ids,true)&&!in_array($gone['id'],$ids,true));

$a0=make_app('INTERVIEW',$mr,$client,$job);
check('default: a request with no ticket (walk-in) has no default reviewer',RecruitmentRepository::defaultReviewers($a0)===[]);
act($f['employee']);
$tid=TicketService::create(array_replace(incident_fields('MANPOWER_REQUEST'),['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code="MANPOWER_REQUEST"')['id'],'submission_key'=>bin2hex(random_bytes(32)),'section_branch'=>'Synthetic section','position_code'=>'SC','employment_type'=>'Probationary','request_type'=>'Additional','quantity'=>'1','job_description_qualifications'=>'Synthetic qualifications','area_department'=>'Synthetic department']),true);
act($f['hr']);
$tmr=ManpowerIntake::approve($tid,(int)row('SELECT version FROM tickets WHERE id=?',[$tid])['version'],$client);
$tjob=insert('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,1,"OPEN","Synthetic branch","REGULAR")',['JOB-ZF-'.$sfx,'zzf-'.$sfx,'Synthetic ticket job','x',$client,$tmr]);
$at=make_app('INTERVIEW',$tmr,$client,$tjob);
check('default: a ticket request filed by a non-reviewer role gives no default',RecruitmentRepository::defaultReviewers($at)===[]);
db()->prepare('UPDATE manpower_requests SET requested_by=? WHERE id=?')->execute([$mgr1['id'],$tmr]);
check('default: the ticket requester is pre-selected when they hold a reviewer role',RecruitmentRepository::defaultReviewers($at)===[$mgr1['id']]);

// --- endorse to reviewers -------------------------------------------------------------------------------------
$app=make_app('INTERVIEW',$mr,$client,$job);
act($f['employee']);
check('endorse: a user without recruitment.manage cannot endorse',rejects(fn()=>RecruitmentRepository::endorseToReviewers($app,'x',[$mgr1['id']])));
act($f['hr']);
check('endorse: at least one reviewer is required',rejects(fn()=>RecruitmentRepository::endorseToReviewers($app,'x',[])));
check('endorse: a plain employee, an inactive manager or an unknown id is refused, and nothing is saved',
    rejects(fn()=>RecruitmentRepository::endorseToReviewers($app,'x',[$mgr1['id'],$plain['id']]))
    &&rejects(fn()=>RecruitmentRepository::endorseToReviewers($app,'x',[$gone['id']]))
    &&rejects(fn()=>RecruitmentRepository::endorseToReviewers($app,'x',[999999999]))
    &&(int)row('SELECT COUNT(*) c FROM application_reviewers WHERE application_id=?',[$app])['c']===0&&stage_of($app)==='INTERVIEW');
$ap=make_app('REJECTED',$mr,$client,$job);
check('endorse: a rejected application cannot be endorsed',rejects(fn()=>RecruitmentRepository::endorseToReviewers($ap,'x',[$mgr1['id']])));
RecruitmentRepository::endorseToReviewers($app,'Strong candidate',[$mgr1['id'],$adl['id'],$mgr1['id']]);
check('endorse: two recipients get one row each (a repeated id is not doubled) and the stage becomes Manager Review',
    (int)row('SELECT COUNT(*) c FROM application_reviewers WHERE application_id=?',[$app])['c']===2&&stage_of($app)==='CLIENT_REVIEW'&&reviewer_row($app,$mgr1['id'])['note']==='Strong candidate');
check('endorse: each recipient is notified once',notes_for($mgr1['id'],'CANDIDATE_REVIEW')===1&&notes_for($adl['id'],'CANDIDATE_REVIEW')===1&&notes_for($tc['id'],'CANDIDATE_REVIEW')===0);
check('endorse: the old client endorsements table is untouched',(int)row('SELECT COUNT(*) c FROM endorsements WHERE application_id=?',[$app])['c']===0);

// --- scope: reviewers see only their own ------------------------------------------------------------------------
check('scope: a recipient sees the candidate in their queue, a non-recipient does not',
    in_array($app,array_map('intval',array_column(RecruitmentRepository::reviewQueue($mgr1['id']),'id')),true)&&RecruitmentRepository::reviewQueue($tc['id'])===[]&&RecruitmentRepository::reviewQueue($mgr2['id'])===[]);
$view=RecruitmentRepository::reviewerApplication($app,$mgr1['id']);
check('scope: a non-recipient (another Manager) gets nothing for that candidate',RecruitmentRepository::reviewerApplication($app,$mgr2['id'])===null&&RecruitmentRepository::reviewerApplication($app,$tc['id'])===null);
check('scope: the reviewer view has the name, job and note but no email or phone',$view!==null&&$view['job_title']==='Synthetic clerk'&&$view['note']==='Strong candidate'&&!array_key_exists('email',$view)&&!array_key_exists('mobile_no',$view));

// --- decisions ----------------------------------------------------------------------------------------------------
act($mgr2);
check('decide: a user who was not endorsed to cannot decide',rejects(fn()=>RecruitmentRepository::decide($app,$mgr2['id'],'PROCEED','ok')));
act($mgr1);
check('decide: an unknown decision value is refused',rejects(fn()=>RecruitmentRepository::decide($app,$mgr1['id'],'MAYBE','')));
check('decide: Not suitable needs a comment',rejects(fn()=>RecruitmentRepository::decide($app,$mgr1['id'],'NOT_SUITABLE','  ')));
check('decide: a user cannot decide on behalf of someone else',rejects(fn()=>RecruitmentRepository::decide($app,$adl['id'],'PROCEED','ok')));
RecruitmentRepository::decide($app,$mgr1['id'],'ANOTHER_INTERVIEW','Please meet again');
check('decide: Needs another interview keeps the stage and is saved with the comment',stage_of($app)==='CLIENT_REVIEW'&&reviewer_row($app,$mgr1['id'])['decision']==='ANOTHER_INTERVIEW'&&reviewer_row($app,$mgr1['id'])['decision_comment']==='Please meet again');
check('decide: HR is told about the decision',notes_for($f['hr']['id'],'CANDIDATE_DECISION')>=1&&(int)row('SELECT COUNT(*) c FROM application_stage_history WHERE application_id=? AND comment LIKE "%Please meet again%"',[$app])['c']===1);
RecruitmentRepository::decide($app,$mgr1['id'],'NOT_SUITABLE','Not a fit');
check('decide: one Not suitable while another recipient has not answered keeps the stage',stage_of($app)==='CLIENT_REVIEW');
act($adl);
RecruitmentRepository::decide($app,$adl['id'],'NOT_SUITABLE','Agree');
check('decide: when every recipient says Not suitable the candidate goes back to HR (Returned)',stage_of($app)==='RETURNED');
act($f['hr']);
RecruitmentRepository::endorseToReviewers($app,'Try another',[$mgr2['id'],$adl['id']]);
check('endorse: HR can endorse again after Returned; the old answer of a repeated recipient is reset, the stage is Manager Review',
    stage_of($app)==='CLIENT_REVIEW'&&reviewer_row($app,$adl['id'])['decision']===null&&reviewer_row($app,$mgr2['id'])['decision']===null);
act($mgr2);
RecruitmentRepository::decide($app,$mgr2['id'],'PROCEED','Hire');
check('decide: Proceed while in Manager Review never moves the candidate to Offer; it stays for HR (no final interviewer named)',stage_of($app)==='CLIENT_REVIEW'&&RecruitmentRepository::finalDone($app)!==null);
act($adl);
RecruitmentRepository::decide($app,$adl['id'],'NOT_SUITABLE','Late answer');
check('decide: a later answer is recorded on the timeline but does not move the stage',stage_of($app)==='CLIENT_REVIEW'&&reviewer_row($app,$adl['id'])['decision']==='NOT_SUITABLE');

// --- reviewer schedules an interview ------------------------------------------------------------------------------
$s1=make_app('SCREENING',$mr,$client,$job);$s2=make_app('INTERVIEW',$mr,$client,$job);
act($f['hr']);
insert('INSERT INTO application_reviewers(application_id,reviewer_user_id,endorsed_by,endorsed_at) VALUES(?,?,?,NOW())',[$s1,$tc['id'],$f['hr']['id']]); // an earlier-stage row (HR can only endorse from Interview on), to prove the advance rule
act($tc);
RecruitmentRepository::reviewerSchedule($s1,$tc['id'],['interview_type'=>'Initial interview','scheduled_at'=>date('Y-m-d H:i:s',strtotime('+2 days')),'location_or_link'=>'Room 1','notes'=>'']);
check('schedule: a recipient schedules an interview under their own name; an earlier stage advances to Interview',
    (int)row('SELECT interviewer_id i FROM interviews WHERE application_id=?',[$s1])['i']===$tc['id']&&stage_of($s1)==='INTERVIEW');
act($f['hr']);
RecruitmentRepository::endorseToReviewers($s2,'x',[$tc['id']]);
act($tc);
RecruitmentRepository::reviewerSchedule($s2,$tc['id'],['interview_type'=>'Final interview','scheduled_at'=>date('Y-m-d H:i:s',strtotime('+3 days')),'location_or_link'=>'Room 2','notes'=>'']);
check('schedule: at Manager Review the stage is not pushed back to Interview',stage_of($s2)==='CLIENT_REVIEW');
check('schedule: someone who was not endorsed to cannot schedule',rejects(fn()=>RecruitmentRepository::reviewerSchedule($s2,$mgr2['id'],['interview_type'=>'x','scheduled_at'=>date('Y-m-d H:i:s'),'location_or_link'=>'','notes'=>''])));
check('schedule: a missing time or type is refused',rejects(fn()=>RecruitmentRepository::reviewerSchedule($s2,$tc['id'],['interview_type'=>'','scheduled_at'=>'','location_or_link'=>'','notes'=>''])));
// --- the server allows endorsing only where the card is shown ------------------------------------------------------
$late=make_app('OFFER',$mr,$client,$job);act($f['hr']);
check('endorse: a candidate already at Offer cannot be pulled back to Manager Review by a forged post, and nothing is saved',rejects(fn()=>RecruitmentRepository::endorseToReviewers($late,'x',[$mgr1['id']]))&&stage_of($late)==='OFFER'&&(int)row('SELECT COUNT(*) c FROM application_reviewers WHERE application_id=?',[$late])['c']===0);

// --- a new round is not blocked by the answers of an old round ----------------------------------------------------------
$rd=make_app('INTERVIEW',$mr,$client,$job);act($f['hr']);
RecruitmentRepository::endorseToReviewers($rd,'round 1',[$mgr1['id']]);
act($mgr1);RecruitmentRepository::decide($rd,$mgr1['id'],'PROCEED','ok');
db()->prepare('UPDATE application_reviewers SET decided_at=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE application_id=? AND reviewer_user_id=?')->execute([$rd,$mgr1['id']]); // time passes before HR returns the candidate
act($f['hr']);RecruitmentRepository::moveStage($rd,'RETURNED','HR returned');
RecruitmentRepository::endorseToReviewers($rd,'round 2',[$mgr2['id']]);
act($mgr2);RecruitmentRepository::decide($rd,$mgr2['id'],'NOT_SUITABLE','No');
check('decide: in a new round, an old Proceed from the earlier round does not stop the candidate returning to HR',stage_of($rd)==='RETURNED');

// --- sweep: the offer uses the same employment types as Job Openings -------------------------------------------------
act($f['hr']);
check('offer: a type outside the Job Openings list is refused and nothing is saved',rejects(fn()=>RecruitmentRepository::saveOffer($app,['offered_salary'=>1,'employment_type'=>'ZZ_BAD','start_date'=>'2026-12-01']))&&(int)row('SELECT COUNT(*) c FROM offers WHERE application_id=?',[$app])['c']===0);
RecruitmentRepository::saveOffer($app,['offered_salary'=>1,'employment_type'=>'PROBATIONARY','start_date'=>'2026-12-01']);
check('offer: a Job Openings type such as Probationary is saved',row('SELECT employment_type t FROM offers WHERE application_id=?',[$app])['t']==='PROBATIONARY');
check('offer: a Daily rate is saved with its type, typed rate and the computed monthly amount',(function()use($app){RecruitmentRepository::saveOffer($app,['rate_type'=>'DAILY','rate_amount'=>800,'employment_type'=>'PROBATIONARY','start_date'=>'2026-12-01']);$o=row('SELECT rate_type,rate_amount,offered_salary FROM offers WHERE application_id=?',[$app]);return $o['rate_type']==='DAILY'&&(float)$o['rate_amount']===800.0&&(float)$o['offered_salary']===20800.0;})());
check('offer: a bad rate type or a zero rate is refused and the saved offer is unchanged',rejects(fn()=>RecruitmentRepository::saveOffer($app,['rate_type'=>'WEEKLY','rate_amount'=>5,'employment_type'=>'PROBATIONARY','start_date'=>'2026-12-01']))&&rejects(fn()=>RecruitmentRepository::saveOffer($app,['rate_type'=>'DAILY','rate_amount'=>0,'employment_type'=>'PROBATIONARY','start_date'=>'2026-12-01']))&&(float)row('SELECT offered_salary s FROM offers WHERE application_id=?',[$app])['s']===20800.0);
check('offer: an Hourly rate re-saves the same offer row as monthly 20,800',(function()use($app){RecruitmentRepository::saveOffer($app,['rate_type'=>'HOURLY','rate_amount'=>100,'employment_type'=>'PROBATIONARY','start_date'=>'2026-12-01']);$r=row('SELECT COUNT(*) c,MAX(rate_type) t,MAX(offered_salary) s FROM offers WHERE application_id=?',[$app]);return (int)$r['c']===1&&$r['t']==='HOURLY'&&(float)$r['s']===20800.0;})());
check('offer: a caller that sends only offered_salary is treated as a monthly rate',(function()use($app){RecruitmentRepository::saveOffer($app,['offered_salary'=>15000,'employment_type'=>'PROBATIONARY','start_date'=>'2026-12-01']);$o=row('SELECT rate_type t,rate_amount a,offered_salary s FROM offers WHERE application_id=?',[$app]);return $o['t']==='MONTHLY'&&(float)$o['a']===15000.0&&(float)$o['s']===15000.0;})());
check('offer: 22 working days is saved and the monthly amount uses it; a bad day count saves nothing',(function()use($app){RecruitmentRepository::saveOffer($app,['rate_type'=>'DAILY','rate_amount'=>800,'working_days'=>22,'employment_type'=>'PROBATIONARY','start_date'=>'2026-12-01']);$o=row('SELECT working_days w,offered_salary s FROM offers WHERE application_id=?',[$app]);$ok=(int)$o['w']===22&&(float)$o['s']===17600.0;$bad=rejects(fn()=>RecruitmentRepository::saveOffer($app,['rate_type'=>'DAILY','rate_amount'=>800,'working_days'=>24,'employment_type'=>'PROBATIONARY','start_date'=>'2026-12-01']));$o=row('SELECT working_days w FROM offers WHERE application_id=?',[$app]);return $ok&&$bad&&(int)$o['w']===22;})());
check('offer: a caller that sends no working_days gets 26',(function()use($app){RecruitmentRepository::saveOffer($app,['rate_type'=>'DAILY','rate_amount'=>800,'employment_type'=>'PROBATIONARY','start_date'=>'2026-12-01']);$o=row('SELECT working_days w,offered_salary s FROM offers WHERE application_id=?',[$app]);return (int)$o['w']===26&&(float)$o['s']===20800.0;})());
finish();
