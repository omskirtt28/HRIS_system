<?php
declare(strict_types=1);
// applicant-tracking-link (SPEC-applicant-tracking-link.md). Synthetic data only; scratch copy with migration 038 applied.
require __DIR__.'/ticket_test_support.php';
$sfx=strtr(bin2hex(random_bytes(3)),'0123456789','ghijklmnop');
$client=insert('INSERT INTO clients(code,name) VALUES(?,?)',['ZT'.$sfx,'Zzt '.$sfx.' Trading']);
$mr=insert('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status) VALUES(?,?,?,?,0,"REGULAR","NORMAL","APPROVED")',['MR-ZT-'.$sfx,$client,'Synthetic Role',1]);
$job=insert('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,1,"PUBLISHED","Synthetic branch","REGULAR")',['ZT'.$sfx,'zt-'.$sfx,'Synthetic Role','Test',$client,$mr]);
$apply=fn(string $who)=>RecruitmentRepository::createApplication(['first_name'=>'Zz','last_name'=>$who,'email'=>'zz_trk_'.$sfx.'@example.test','mobile_no'=>'09000000000','job_opening_id'=>$job,'why_fit'=>'test'],null);
$ref=$apply('One');
$id=(int)row('SELECT id FROM applications WHERE application_no=?',[$ref])['id'];

// 1. issue + open: one application only, hash stored, never the token
$token=ApplicantTracking::issue($ref);
check('token is 64 hex characters',ApplicantTracking::validToken($token));
$stored=row('SELECT token_hash,expires_at,revoked_at FROM applicant_tracking_links WHERE application_id=?',[$id]);
check('only the SHA-256 is stored, not the token',$stored['token_hash']===hash('sha256',$token)&&(int)row('SELECT COUNT(*) c FROM applicant_tracking_links WHERE token_hash=?',[$token])['c']===0);
check('expires in 90 days',(int)row('SELECT DATEDIFF(?,NOW()) d',[$stored['expires_at']])['d']===90&&$stored['revoked_at']===null);
$found=ApplicantTracking::find($token);
check('find() opens that application',$found&&(int)$found['application_id']===$id);
check('trackById gives the same row as ref/email lookup',RecruitmentRepository::trackById($id)==RecruitmentRepository::track($ref,'zz_trk_'.$sfx.'@example.test'));
check('audit records the issue without the token',($a=row('SELECT details_json FROM audit_logs WHERE action="TRACKING_LINK_ISSUED" AND record_id=? ORDER BY id DESC LIMIT 1',[$id]))&&!str_contains((string)$a['details_json'],$token));

// 2. same email, second application: the first link never opens it
$job2=insert('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,1,"PUBLISHED","Synthetic branch","REGULAR")',['ZU'.$sfx,'zu-'.$sfx,'Other Role','Test',$client,$mr]);
$ref2=RecruitmentRepository::createApplication(['first_name'=>'Zz','last_name'=>'Two','email'=>'zz_trk_'.$sfx.'@example.test','mobile_no'=>'09000000000','job_opening_id'=>$job2,'why_fit'=>'x'],null);
$id2=(int)row('SELECT id FROM applications WHERE application_no=?',[$ref2])['id'];
$token2=ApplicantTracking::issue($ref2);
check('each link opens only its own application',(int)ApplicantTracking::find($token)['application_id']===$id&&(int)ApplicantTracking::find($token2)['application_id']===$id2);

// 3. invalid, expired, revoked: no data
check('unknown well-formed token opens nothing',ApplicantTracking::find(str_repeat('0',64))===null);
check('malformed token opens nothing',ApplicantTracking::find("x' OR '1'='1")===null);
db()->prepare('UPDATE applicant_tracking_links SET expires_at=NOW()-INTERVAL 1 SECOND WHERE token_hash=?')->execute([hash('sha256',$token2)]);
check('expired link opens nothing',ApplicantTracking::find($token2)===null);
db()->prepare('UPDATE applicant_tracking_links SET revoked_at=NOW() WHERE token_hash=?')->execute([hash('sha256',$token)]);
check('revoked link opens nothing',ApplicantTracking::find($token)===null);

// 4. public view through both entry paths: Interview only, no comments
RecruitmentRepository::moveStage($id,'INTERVIEW','Recruiter private remark '.$sfx);
$v=ApplicantTracking::publicView(RecruitmentRepository::trackById($id));
check('history reaches public view as Screening then Interview, no comment text',array_column($v['milestones'],'label')===['Screening','Interview']&&!str_contains(json_encode($v),'private remark'));
check('issue() for an unknown reference fails without inserting',(function(){$n=(int)row('SELECT COUNT(*) c FROM applicant_tracking_links')['c'];try{ApplicantTracking::issue('NOPE-'.bin2hex(random_bytes(3)));return false;}catch(RuntimeException){return (int)row('SELECT COUNT(*) c FROM applicant_tracking_links')['c']===$n;}})());
check('email() does nothing while mail is off (tests keep mail disabled)',ApplicantTracking::email('zz@example.test',$ref,str_repeat('a',64))===false);
finish();
