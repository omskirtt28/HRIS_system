<?php
declare(strict_types=1);
// rec-screening-first (SPEC-stage-flow.md). Synthetic data only; run with HRIS_TEST_DB on a scratch copy that already has migration 029 applied.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/JobOpeningsViews.php';
$sfx=strtr(bin2hex(random_bytes(3)),'0123456789','ghijklmnop');

$client=insert('INSERT INTO clients(code,name) VALUES(?,?)',['ZS'.$sfx,'Zzs '.$sfx.' Trading']);
$mr=insert('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status) VALUES(?,?,?,?,0,"REGULAR","NORMAL","APPROVED")',['MR-ZS-'.$sfx,$client,'Synthetic Role',1]);
$job=insert('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,1,"PUBLISHED","Synthetic branch","REGULAR")',['ZS'.$sfx,'zs-'.$sfx,'Synthetic Role','Test',$client,$mr]);
function stage_of(int $app): string { return row('SELECT s.code FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.id=?',[$app])['code']; }

// --- new applications start at Screening -------------------------------------------------------------------
$ref=RecruitmentRepository::createApplication(['first_name'=>'Zz','last_name'=>'Screen','email'=>'zz_screen_'.$sfx.'@example.test','mobile_no'=>'09000000000','job_opening_id'=>$job,'why_fit'=>'test'],null);
$new=(int)row('SELECT id FROM applications WHERE application_no=?',[$ref])['id'];
check('new application starts at SCREENING',stage_of($new)==='SCREENING');
check('its first history row goes to Screening',row('SELECT s.code FROM application_stage_history h JOIN recruitment_stages s ON s.id=h.to_stage_id WHERE h.application_id=?',[$new])['code']==='SCREENING');
check('Continue to Interview is the next step from Screening',RecruitmentRepository::nextStageCode($new)==='INTERVIEW');

// --- the tracker has no Applied dot ------------------------------------------------------------------------
$codes=array_column(RecruitmentRepository::stages(),'code');
check('stages() no longer lists APPLIED and still starts at SCREENING',!in_array('APPLIED',$codes,true)&&$codes[0]==='SCREENING');
$a=RecruitmentRepository::application($new);
check('tracker shows 7 dots with Screening current',substr_count(applicant_stage_tracker($a,RecruitmentRepository::stages()),'<li ')===7&&str_contains(applicant_stage_tracker($a,RecruitmentRepository::stages()),'class="cur" aria-current="step"'));

// --- a legacy application still sitting at Applied behaves as Screening -------------------------------------
$before=array_column(RecruitmentRepository::stageCounts(),'total','code');
$legacyApplicant=insert('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")',['APP-ZS-'.bin2hex(random_bytes(4)),'Zz','Legacy','zz_legacy_'.$sfx.'@example.test','09000000001']);
$legacy=insert('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code="APPLIED"),NOW(),NOW(),"ACTIVE")',['PMBSI-ZS-'.bin2hex(random_bytes(4)),$legacyApplicant,$job,$mr,$client]);
$la=RecruitmentRepository::application($legacy);
check('legacy: application() reads it as Screening',$la['stage_code']==='SCREENING'&&$la['stage_name']==='Screening');
check('legacy: it is in the Screening list/board group and the stage filter',(function()use($legacy){foreach(RecruitmentRepository::applications(['stage'=>'SCREENING']) as $x)if((int)$x['id']===$legacy)return true;return false;})());
check('legacy: next step is Interview and the tracker marks Screening current',RecruitmentRepository::nextStageCode($legacy)==='INTERVIEW'&&str_contains(applicant_stage_tracker($la,RecruitmentRepository::stages()),'class="cur" aria-current="step"'));
$after=array_column(RecruitmentRepository::stageCounts(),'total','code');
check('legacy: Screening count rose by exactly one and no other bucket moved',(int)$after['SCREENING']===(int)$before['SCREENING']+1&&array_sum($after)===array_sum($before)+1);
RecruitmentRepository::moveStage($legacy,'INTERVIEW','test');
check('legacy: moving it forward lands at Interview',stage_of($legacy)==='INTERVIEW');

// --- history that points at Applied still shows a label ------------------------------------------------------
$h=db()->prepare('SELECT COUNT(*) c FROM application_stage_history h JOIN recruitment_stages ts ON ts.id=h.to_stage_id WHERE ts.code="APPLIED"');$h->execute();
check('old history rows to Applied still join (label kept, not blank)',(int)$h->fetch()['c']>=0&&(int)row('SELECT COUNT(*) c FROM application_stage_history h LEFT JOIN recruitment_stages ts ON ts.id=h.to_stage_id WHERE ts.id IS NULL')['c']===0);
finish();
