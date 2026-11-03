<?php
declare(strict_types=1);
// interview-invite (SPEC-interview-invite.md). Synthetic data only; run with HRIS_TEST_DB on a scratch copy. Mailer is stubbed: no real email is ever sent.
require __DIR__.'/ticket_test_support.php';
$f=fixtures();
$sfx=strtr(bin2hex(random_bytes(3)),'0123456789','ghijklmnop');
foreach(['recruitment.view','recruitment.manage'] as $code){db()->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code=?')->execute([$f['hr']['role_id'],$code]);}

function real_user(string $roleCode,string $label): array {
    $r=row('SELECT id,code,portal FROM roles WHERE code=?',[$roleCode]);
    $email=strtolower('zz_'.$label.'_'.bin2hex(random_bytes(3))).'@example.test';
    $id=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$r['id'],'Zz '.$label,$email,password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
    return ['id'=>$id,'role_id'=>(int)$r['id'],'role_code'=>$r['code'],'role_portal'=>$r['portal'],'name'=>'Zz '.$label,'role_name'=>$r['code'],'email'=>$email,'client_id'=>null];
}
function make_app(string $stage,int $mr,int $client,int $job,string $email): int {
    $applicant=insert('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")',['APP-ZI-'.bin2hex(random_bytes(4)),'Juana','Candidate',$email,'09170000000']);
    return insert('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code=?),NOW(),NOW(),"ACTIVE")',['PMBSI-ZI-'.bin2hex(random_bytes(4)),$applicant,$job,$mr,$client,$stage]);
}
function note_count(int $app,string $like): int { return (int)row('SELECT COUNT(*) c FROM application_stage_history WHERE application_id=? AND comment LIKE ?',[$app,$like])['c']; }
function audit_count(string $action,int $app): int { return (int)row('SELECT COUNT(*) c FROM audit_logs WHERE module="Recruitment" AND action=? AND record_id=?',[$action,$app])['c']; }
function interview_count(int $app): int { return (int)row('SELECT COUNT(*) c FROM interviews WHERE application_id=?',[$app])['c']; }

$client=insert('INSERT INTO clients(code,name) VALUES(?,?)',['ZI'.$sfx,'Zzi '.$sfx.' Trading']);
$mr=insert('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status) VALUES(?,?,?,?,0,"REGULAR","NORMAL","APPROVED")',['MR-ZI-'.bin2hex(random_bytes(4)),$client,'Synthetic clerk',2]);
$job=insert('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,1,"OPEN","Synthetic branch","REGULAR")',['JOB-ZI-'.$sfx,'zzi-'.$sfx,'Synthetic clerk','x',$client,$mr]);
$mgr=real_user('MANAGER','mgr');$plain=real_user('EMPLOYEE','plain');

// Stubbed transport: collects what would have been sent.
$sent=[];$mailDown=false;
Mailer::$transport=function(string $to,string $subject,string $body) use (&$sent,&$mailDown): void { if($mailDown) throw new RuntimeException('smtp down'); $sent[]=compact('to','subject','body'); };
$GLOBALS['config']['mail']=['send_interview_invites'=>true];

$email='juana_'.$sfx.'@example.test';
$meet='https://meet.google.com/abc-defg-hij';
$when=date('Y-m-d H:i:s',strtotime('+2 days 10:00'));
$data=['interview_type'=>'Mid Interview','scheduled_at'=>$when,'location_or_link'=>$meet,'notes'=>'SECRET-INTERNAL-NOTE'];

// --- HR schedules ------------------------------------------------------------------------------------------------
$a1=make_app('SCREENING',$mr,$client,$job,$email);
act($f['hr']);
RecruitmentRepository::scheduleInterview($a1,$data);
check('hr schedule: exactly one email goes to the applicant address stored on the application',count($sent)===1&&$sent[0]['to']===$email);
check('hr schedule: subject names the job',$sent[0]['subject']==='Interview schedule: Synthetic clerk');
check('hr schedule: body greets the first name and has the type, the date and the Meet link',str_contains($sent[0]['body'],'Hello Juana,')&&str_contains($sent[0]['body'],'Mid Interview')&&str_contains($sent[0]['body'],date('F j, Y',strtotime($when)))&&str_contains($sent[0]['body'],$meet));
check('hr schedule: body is from PMBSI HR and never carries the internal notes or the last name',str_contains($sent[0]['body'],'PMBSI HR')&&!str_contains($sent[0]['body'],'SECRET-INTERNAL-NOTE')&&!str_contains($sent[0]['body'],'Candidate'));
check('hr schedule: the timeline says the invite was emailed, with the address masked',note_count($a1,'Invite emailed to j%***@example.test')===1&&note_count($a1,'%'.$email.'%')===0);
check('hr schedule: the sent invite is audited',audit_count('INTERVIEW_INVITE_SENT',$a1)===1);
check('hr schedule: the interview row is saved',interview_count($a1)===1);

// --- HR scheduling never pushes a later stage back ---
$a0=make_app('CLIENT_REVIEW',$mr,$client,$job,$email);
RecruitmentRepository::scheduleInterview($a0,$data);
check('hr schedule: at Hiring Review the stage is not pushed back to Interview',row('SELECT s.code FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.id=?',[$a0])['code']==='CLIENT_REVIEW'&&note_count($a0,'Interview scheduled')===1);

// --- reviewer schedules ------------------------------------------------------------------------------------------
$a2=make_app('INTERVIEW',$mr,$client,$job,$email);
db()->prepare('INSERT INTO application_reviewers(application_id,reviewer_user_id,endorsed_by,endorsed_at) VALUES(?,?,?,NOW())')->execute([$a2,$mgr['id'],$f['hr']['id']]);
$before=count($sent);
act($mgr);
RecruitmentRepository::reviewerSchedule($a2,$mgr['id'],$data);
check('reviewer schedule: one email goes to the applicant address, looked up on the server',count($sent)===$before+1&&$sent[$before]['to']===$email&&str_contains($sent[$before]['body'],$meet));

// --- never blocks scheduling ---------------------------------------------------------------------------------------
act($f['hr']);
$GLOBALS['config']['mail']['send_interview_invites']=false;$before=count($sent);
$a3=make_app('SCREENING',$mr,$client,$job,$email);
RecruitmentRepository::scheduleInterview($a3,$data);
check('switch off: nothing is sent, the interview is saved and the timeline says why',count($sent)===$before&&interview_count($a3)===1&&note_count($a3,'Invite email not sent%')===1);
$GLOBALS['config']['mail']['send_interview_invites']=true;
$mailDown=true;$a4=make_app('SCREENING',$mr,$client,$job,$email);
RecruitmentRepository::scheduleInterview($a4,$data);
check('failure: a mail error keeps the interview and writes a timeline note and an audit entry',interview_count($a4)===1&&note_count($a4,'Invite email failed%')===1&&audit_count('INTERVIEW_INVITE_FAILED',$a4)===1);
$mailDown=false;$before=count($sent);
$a5=make_app('SCREENING',$mr,$client,$job,'not-an-email');
RecruitmentRepository::scheduleInterview($a5,$data);
check('no valid address: nothing is sent and the interview is saved',count($sent)===$before&&interview_count($a5)===1&&note_count($a5,'Invite email not sent%')===1);

// --- resend ----------------------------------------------------------------------------------------------------------
$iv=(int)row('SELECT id FROM interviews WHERE application_id=?',[$a4])['id'];
act($f['employee']);
check('resend: a user without recruitment.manage cannot resend',rejects(fn()=>RecruitmentRepository::resendInterviewInvite($iv)));
act($f['hr']);$before=count($sent);
RecruitmentRepository::resendInterviewInvite($iv);
check('resend: HR can resend and the applicant gets the email again',count($sent)===$before+1&&$sent[$before]['to']===$email);
check('resend: a second resend within a minute is refused and nothing more is sent',rejects(fn()=>RecruitmentRepository::resendInterviewInvite($iv))&&count($sent)===$before+1);
check('resend: an unknown interview is refused',rejects(fn()=>RecruitmentRepository::resendInterviewInvite(999999999)));
check('resend: a failed resend is reported to HR and not silent',(function() use ($iv,&$mailDown,$f){ $_SESSION['invite_resent']=[]; $mailDown=true; $r=rejects(fn()=>RecruitmentRepository::resendInterviewInvite($iv)); $mailDown=false; return $r; })());
finish();
