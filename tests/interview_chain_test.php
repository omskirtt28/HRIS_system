<?php
declare(strict_types=1);
// interview-chain (SPEC-interview-chain.md). Synthetic data only; run with HRIS_TEST_DB on a scratch copy that already has migrations 023 and 024.
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
    $applicant=insert('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")',['APP-ZC-'.bin2hex(random_bytes(4)),'Zz','Candidate','zz_'.bin2hex(random_bytes(4)).'@example.test','09170000000']);
    return insert('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code=?),NOW(),NOW(),"ACTIVE")',['PMBSI-ZC-'.bin2hex(random_bytes(4)),$applicant,$job,$mr,$client,$stage]);
}
function stage_of(int $app): string { return row('SELECT s.code FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.id=?',[$app])['code']; }
function rv(int $app,int $uid): array { return row('SELECT * FROM application_reviewers WHERE application_id=? AND reviewer_user_id=?',[$app,$uid]); }
function rows_of(int $app): int { return (int)row('SELECT COUNT(*) c FROM application_reviewers WHERE application_id=?',[$app])['c']; }
function notes_for(int $uid,string $kind,string $like='%'): int { return (int)row('SELECT COUNT(*) c FROM notifications WHERE recipient_user_id=? AND kind=? AND title LIKE ?',[$uid,$kind,$like])['c']; }
function timeline(int $app,string $like): int { return (int)row('SELECT COUNT(*) c FROM application_stage_history WHERE application_id=? AND comment LIKE ?',[$app,$like])['c']; }

$client=insert('INSERT INTO clients(code,name) VALUES(?,?)',['ZC'.$sfx,'Zzc '.$sfx.' Trading']);
$mr=insert('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status) VALUES(?,?,?,?,0,"REGULAR","NORMAL","APPROVED")',['MR-ZC-'.bin2hex(random_bytes(4)),$client,'Synthetic clerk',2]);
$job=insert('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,1,"OPEN","Synthetic branch","REGULAR")',['JOB-ZC-'.$sfx,'zzc-'.$sfx,'Synthetic clerk','x',$client,$mr]);
$mgr1=real_user('MANAGER','mgr1');$mgr2=real_user('MANAGER','mgr2');$adl=real_user('ADL','adl');$tc=real_user('TC','tc');$plain=real_user('EMPLOYEE','plain');$gone=real_user('MANAGER','gone','INACTIVE');

// --- migration columns ------------------------------------------------------------------------------------------------
$cols=db()->query('SELECT GROUP_CONCAT(column_name ORDER BY column_name) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="application_reviewers" AND column_name IN("is_final","passed_by")')->fetchColumn();
check('migration: application_reviewers has is_final and passed_by',$cols==='is_final,passed_by');

// --- HR endorses and names the final interviewer ---------------------------------------------------------------------------
$app=make_app('INTERVIEW',$mr,$client,$job);act($f['hr']);
check('endorse: a final interviewer who is not among the chosen people is refused and nothing is saved',rejects(fn()=>RecruitmentRepository::endorseToReviewers($app,'x',[$mgr1['id']],[$adl['id']]))&&rows_of($app)===0);
RecruitmentRepository::endorseToReviewers($app,'Round 1',[$mgr1['id'],$adl['id']],[$adl['id']]);
check('endorse: the ticked person is the final interviewer, the other is not, and HR (not a reviewer) is the passer',(int)rv($app,$adl['id'])['is_final']===1&&(int)rv($app,$mgr1['id'])['is_final']===0&&rv($app,$mgr1['id'])['passed_by']===null);

// --- a reviewer passes the candidate on --------------------------------------------------------------------------------------
act($mgr2);
check('pass on: someone the candidate was not endorsed to cannot pass it on',rejects(fn()=>RecruitmentRepository::passOn($app,$mgr2['id'],[$tc['id']],[],'x'))&&rows_of($app)===2);
act($plain);
check('pass on: a user without recruitment.review cannot pass it on',rejects(fn()=>RecruitmentRepository::passOn($app,$plain['id'],[$tc['id']],[],'x')));
act($mgr1);
check('pass on: nobody chosen, oneself, a plain employee, an inactive manager, an unknown id and an existing reviewer are all refused, nothing saved',
    rejects(fn()=>RecruitmentRepository::passOn($app,$mgr1['id'],[],[],''))
    &&rejects(fn()=>RecruitmentRepository::passOn($app,$mgr1['id'],[$mgr1['id']],[],''))
    &&rejects(fn()=>RecruitmentRepository::passOn($app,$mgr1['id'],[$plain['id']],[],''))
    &&rejects(fn()=>RecruitmentRepository::passOn($app,$mgr1['id'],[$gone['id']],[],''))
    &&rejects(fn()=>RecruitmentRepository::passOn($app,$mgr1['id'],[999999999],[],''))
    &&rejects(fn()=>RecruitmentRepository::passOn($app,$mgr1['id'],[$adl['id']],[],''))
    &&rows_of($app)===2);
check('pass on: a final interviewer outside the chosen people is refused',rejects(fn()=>RecruitmentRepository::passOn($app,$mgr1['id'],[$tc['id']],[$mgr2['id']],'')));
RecruitmentRepository::passOn($app,$mgr1['id'],[$tc['id']],[$tc['id']],'Please do the last round');
check('pass on: the new person gets a row marked final and records who passed it',rows_of($app)===3&&(int)rv($app,$tc['id'])['is_final']===1&&(int)rv($app,$tc['id'])['passed_by']===$mgr1['id']&&rv($app,$tc['id'])['note']==='Please do the last round');
check('pass on: the new person is notified once, the others are not, the stage does not change, the chain is on the timeline and audited',
    notes_for($tc['id'],'CANDIDATE_REVIEW')===1&&notes_for($mgr2['id'],'CANDIDATE_REVIEW')===0&&stage_of($app)==='CLIENT_REVIEW'&&timeline($app,'%passed%Zz tc%')===1
    &&(int)row('SELECT COUNT(*) c FROM audit_logs WHERE module="Recruitment" AND action="PASS_ON" AND record_id=?',[$app])['c']===1);
check('pass on: the new person sees the candidate; another manager still does not',
    in_array($app,array_map('intval',array_column(RecruitmentRepository::reviewQueue($tc['id']),'id')),true)&&RecruitmentRepository::reviewerApplication($app,$mgr2['id'])===null&&(int)RecruitmentRepository::reviewerApplication($app,$tc['id'])['is_final']===1);
check('pass on: HR sees the whole chain, with who passed to whom and the final flag',(function() use ($app,$mgr1,$tc){ foreach(RecruitmentRepository::reviewersFor($app) as $r) if((int)$r['reviewer_user_id']===$tc['id']) return (int)$r['is_final']===1&&$r['passed_by_name']==='Zz mgr1'; return false; })());
check('pass on: more than ten people at once is refused',(function() use ($app,$mgr1){ $many=[]; for($i=0;$i<11;$i++)$many[]=real_user('MANAGER','bulk'.$i)['id']; return rejects(fn()=>RecruitmentRepository::passOn($app,$mgr1['id'],$many,[],'')); })());
$late=make_app('OFFER',$mr,$client,$job);db()->prepare('INSERT INTO application_reviewers(application_id,reviewer_user_id,endorsed_by,endorsed_at) VALUES(?,?,?,NOW())')->execute([$late,$mgr1['id'],$f['hr']['id']]);
check('pass on: a candidate that is no longer in Manager Review cannot be passed on',rejects(fn()=>RecruitmentRepository::passOn($late,$mgr1['id'],[$tc['id']],[],''))&&rows_of($late)===1);

// --- Proceed never moves a candidate to Offer; the final answer hands the candidate back to HR ------------------------------------
check('ready: nobody has answered, so the candidate is not ready for HR',RecruitmentRepository::finalDone($app)===null);
act($mgr1);
RecruitmentRepository::decide($app,$mgr1['id'],'PROCEED','Good, pass to final');
check('proceed (not final): only an opinion; the stage stays Manager Review and HR is not told it is ready',stage_of($app)==='CLIENT_REVIEW'&&RecruitmentRepository::finalDone($app)===null&&notes_for($f['hr']['id'],'CANDIDATE_DECISION','Ready for HR%')===0);
act($tc);
RecruitmentRepository::decide($app,$tc['id'],'PROCEED','Hire');
$done=RecruitmentRepository::finalDone($app);
check('proceed (final): the stage does NOT move to Offer, and HR sees who finished the final interview',stage_of($app)==='CLIENT_REVIEW'&&$done!==null&&$done['full_name']==='Zz tc'&&$done['decision_comment']==='Hire');
check('proceed (final): HR gets one Ready for HR notification and the timeline says Final interview done',notes_for($f['hr']['id'],'CANDIDATE_DECISION','Ready for HR%')===1&&timeline($app,'Final interview done%')===1);

// --- legacy endorsement with no final person: any Proceed is ready for HR, still no auto-Offer ----------------------------------------
$legacy=make_app('INTERVIEW',$mr,$client,$job);act($f['hr']);
RecruitmentRepository::endorseToReviewers($legacy,'old style',[$mgr2['id']]);
act($mgr2);RecruitmentRepository::decide($legacy,$mgr2['id'],'PROCEED','ok');
check('legacy: with no final interviewer named, a Proceed is ready for HR and the stage stays Manager Review',stage_of($legacy)==='CLIENT_REVIEW'&&RecruitmentRepository::finalDone($legacy)!==null);

// --- mark final ------------------------------------------------------------------------------------------------------------------
$mf=make_app('INTERVIEW',$mr,$client,$job);act($f['hr']);
RecruitmentRepository::endorseToReviewers($mf,'x',[$mgr1['id']]);
act($mgr1);RecruitmentRepository::passOn($mf,$mgr1['id'],[$adl['id'],$tc['id']],[],'');
act($mgr2);
check('mark final: a person who is neither HR nor the one who passed it cannot change it',rejects(fn()=>RecruitmentRepository::markFinal($mf,$adl['id'],true)));
act($tc);
check('mark final: the invited person cannot mark themselves final',rejects(fn()=>RecruitmentRepository::markFinal($mf,$tc['id'],true)));
act($mgr1);RecruitmentRepository::markFinal($mf,$adl['id'],true);
check('mark final: the person who passed it can mark an unanswered reviewer final',(int)rv($mf,$adl['id'])['is_final']===1);
act($f['hr']);RecruitmentRepository::markFinal($mf,$adl['id'],false);
check('mark final: HR can clear it again',(int)rv($mf,$adl['id'])['is_final']===0);
act($adl);RecruitmentRepository::decide($mf,$adl['id'],'ANOTHER_INTERVIEW','later');
act($f['hr']);
check('mark final: an answered reviewer can no longer be changed',rejects(fn()=>RecruitmentRepository::markFinal($mf,$adl['id'],true)));

// --- no reviewer path reaches Offer ----------------------------------------------------------------------------------------------
check('rule: across all the scenarios above no candidate was moved to Offer by a reviewer',(int)row('SELECT COUNT(*) c FROM application_stage_history h JOIN recruitment_stages ts ON ts.id=h.to_stage_id WHERE ts.code="OFFER" AND h.application_id IN (?,?,?,?)',[$app,$legacy,$mf,$late])['c']===0);

// --- HR re-endorse resets the final flag per the new ticks ------------------------------------------------------------------------
$rr=make_app('INTERVIEW',$mr,$client,$job);act($f['hr']);
RecruitmentRepository::endorseToReviewers($rr,'a',[$mgr1['id'],$adl['id']],[$adl['id']]);
RecruitmentRepository::moveStage($rr,'RETURNED','back');
RecruitmentRepository::endorseToReviewers($rr,'b',[$mgr1['id'],$adl['id']],[$mgr1['id']]);
check('re-endorse: the final flag follows the new ticks and the earlier answers are reset',(int)rv($rr,$mgr1['id'])['is_final']===1&&(int)rv($rr,$adl['id'])['is_final']===0&&rv($rr,$adl['id'])['decision']===null);
// --- a final interviewer from an earlier round must not block Ready for HR in a later round ------------------------------------------
$st=make_app('INTERVIEW',$mr,$client,$job);act($f['hr']);
RecruitmentRepository::endorseToReviewers($st,'round 1',[$mgr1['id'],$tc['id']],[$tc['id']]);
act($mgr1);RecruitmentRepository::decide($st,$mgr1['id'],'NOT_SUITABLE','no');act($tc);RecruitmentRepository::decide($st,$tc['id'],'NOT_SUITABLE','no');
check('stale final: after every recipient declined the candidate is Returned',stage_of($st)==='RETURNED');
act($f['hr']);RecruitmentRepository::endorseToReviewers($st,'round 2',[$mgr2['id']]);
check('stale final: a new round clears the earlier round\'s final flag, so nobody blocks the new round',(int)rv($st,$tc['id'])['is_final']===0&&(int)rv($st,$mgr2['id'])['is_final']===0);
act($mgr2);RecruitmentRepository::decide($st,$mgr2['id'],'PROCEED','ok');
check('stale final: the new round\'s only reviewer Proceed is Ready for HR and the stage stays Manager Review',RecruitmentRepository::finalDone($st)!==null&&stage_of($st)==='CLIENT_REVIEW');
// adding people in the middle of a round keeps the final already named
$mid=make_app('INTERVIEW',$mr,$client,$job);act($f['hr']);
RecruitmentRepository::endorseToReviewers($mid,'x',[$mgr1['id'],$adl['id']],[$adl['id']]);
RecruitmentRepository::endorseToReviewers($mid,'y',[$tc['id']]);
check('stale final: endorsing one more person during a round keeps the final already named',(int)rv($mid,$adl['id'])['is_final']===1&&(int)rv($mid,$tc['id'])['is_final']===0);
finish();
