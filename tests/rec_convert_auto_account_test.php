<?php
declare(strict_types=1);
// Convert to Employee creates the portal account automatically (SPEC-convert-auto-account.md, Task 1).
// Synthetic data only; run with HRIS_TEST_DB on a scratch copy of pmbsi_hris.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/EmployeeAccountService.php';
$f=fixtures();
$sfx=strtr(bin2hex(random_bytes(3)),'0123456789','ghijklmnop');
$good=base64_encode(random_bytes(32));
$GLOBALS['config']['security']['credential_key']=$good;
act($f['hr']);
foreach(['employees.create','recruitment.view','recruitment.manage'] as $code){db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code=?')->execute([$f['hr']['role_id'],$code]);}
Auth::clearPermissionCache();

$client=insert('INSERT INTO clients(code,name) VALUES(?,?)',['ZC'.$sfx,'Zzc '.$sfx.' Trading']);
$mr=insert('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status) VALUES(?,?,?,?,0,"REGULAR","NORMAL","APPROVED")',['MR-ZC-'.bin2hex(random_bytes(4)),$client,'Synthetic clerk',5]);
$job=insert('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,5,"OPEN","Synthetic branch","REGULAR")',['JOB-ZC-'.$sfx,'zzc-'.$sfx,'Synthetic clerk','x',$client,$mr]);
function hired_app(string $first,string $last,int $mr,int $client,int $job): int {
    $applicant=insert('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")',['APP-ZC-'.bin2hex(random_bytes(4)),$first,$last,'zz_'.bin2hex(random_bytes(4)).'@example.test','09170000000']);
    $app=insert('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status,screening_rating) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code="DEPLOYED"),NOW(),NOW(),"ACTIVE","FAIR")',['PMBSI-ZC-'.bin2hex(random_bytes(4)),$applicant,$job,$mr,$client]);
    insert('INSERT INTO deployments(application_id,deployment_no,status,actual_date) VALUES(?,?,"DEPLOYED",CURDATE())',[$app,'DEP-ZC-'.bin2hex(random_bytes(4))]);
    return $app;
}
function conv(array $extra=[]): array {
    static $n=0;$n++;
    return array_replace(['employee_no'=>'ZC-'.bin2hex(random_bytes(3)).$n,'first_name'=>'','last_name'=>'','personal_email'=>'zz_'.bin2hex(random_bytes(4)).'@example.test','mobile_no'=>'09170000000',
        'department_id'=>0,'position_id'=>0,'branch_id'=>0,'employment_type_id'=>1,'require_employee_status'=>true,'hire_date'=>'2026-11-02','regularization_date'=>'','transfer_documents'=>0],$extra);
}
function emp(int $id): array { return row('SELECT e.*,u.email user_email,u.must_change_password mcp,u.password_enc,r.code role_code FROM employees e LEFT JOIN users u ON u.id=e.user_id LEFT JOIN roles r ON r.id=u.role_id WHERE e.id=?',[$id]); }
$count=fn(string $t)=>(int)db()->query("SELECT COUNT(*) FROM $t")->fetchColumn();

$first='Zzana'.$sfx;
// --- PMBSI ---------------------------------------------------------------------------------------------------
$a1=hired_app($first,'Reyes',$mr,$client,$job);
$id1=RecruitmentRepository::convertToEmployee($a1,conv(['first_name'=>$first,'last_name'=>'Reyes','work_company'=>'PMBSI']));
$e1=emp($id1);
check('PMBSI: employee is linked to a new portal account',(int)$e1['user_id']>0);
check('PMBSI: email is firstnamelastname@pmbsi.com',$e1['user_email']===strtolower($first).'reyes@pmbsi.com'&&$e1['company_email']===$e1['user_email']);
check('PMBSI: work_company is saved',$e1['work_company']==='PMBSI');
check('PMBSI: account has the Employee role, must change password, HR can reveal a copy',$e1['role_code']==='EMPLOYEE'&&(int)$e1['mcp']===1&&$e1['password_enc']!==null);
check('PMBSI: the application is marked converted',(int)row('SELECT converted_employee_id c FROM applications WHERE id=?',[$a1])['c']===$id1);
check('PMBSI: AUTO_CREATE_USER audit written',(int)row('SELECT COUNT(*) c FROM audit_logs WHERE action="AUTO_CREATE_USER" AND record_id=?',[(int)$e1['user_id']])['c']===1);

// --- 2LOG and duplicate name ---------------------------------------------------------------------------------
$a2=hired_app($first,'Reyes',$mr,$client,$job);
$id2=RecruitmentRepository::convertToEmployee($a2,conv(['first_name'=>$first,'last_name'=>'Reyes','work_company'=>'2LOG']));
check('2LOG: email ends with @2log.com',emp($id2)['user_email']===strtolower($first).'reyes@2log.com');
$a3=hired_app($first,'Reyes',$mr,$client,$job);
$id3=RecruitmentRepository::convertToEmployee($a3,conv(['first_name'=>$first,'last_name'=>'Reyes','work_company'=>'PMBSI']));
check('duplicate name gets a number',emp($id3)['user_email']===strtolower($first).'reyes2@pmbsi.com');

// --- forged account fields are ignored -----------------------------------------------------------------------
$a4=hired_app('Zzbo'.$sfx,'Cruz',$mr,$client,$job);
$id4=RecruitmentRepository::convertToEmployee($a4,conv(['first_name'=>'Zzbo'.$sfx,'last_name'=>'Cruz','work_company'=>'PMBSI','account_mode'=>'later','user_id'=>1,'company_email'=>'attacker@pmbsi.com','account_email'=>'attacker2@pmbsi.com','temporary_password'=>'Attacker-Pass-1']));
$e4=emp($id4);
check('forged account_mode, user_id, company_email and account_email are ignored',(int)$e4['user_id']>1&&$e4['user_email']===strtolower('Zzbo'.$sfx).'cruz@pmbsi.com'&&(int)db()->query('SELECT COUNT(*) FROM users WHERE email LIKE "attacker%"')->fetchColumn()===0);

// --- failures save nothing -----------------------------------------------------------------------------------
$a5=hired_app('Zzcy'.$sfx,'Lim',$mr,$client,$job);
$u0=$count('users');$e0=$count('employees');
foreach(['missing Company'=>'','unknown Company'=>'NOPE'] as $label=>$company){
    check("$label is rejected and nothing is saved",rejects(fn()=>RecruitmentRepository::convertToEmployee($a5,conv(['first_name'=>'Zzcy'.$sfx,'last_name'=>'Lim','work_company'=>$company])))&&$count('users')===$u0&&$count('employees')===$e0);
}
$GLOBALS['config']['security']['credential_key']='';
check('missing credential key is rejected and nothing is saved',rejects(fn()=>RecruitmentRepository::convertToEmployee($a5,conv(['first_name'=>'Zzcy'.$sfx,'last_name'=>'Lim','work_company'=>'PMBSI'])))&&$count('users')===$u0&&$count('employees')===$e0);
$GLOBALS['config']['security']['credential_key']=$good;
check('the application stays unconverted after those failures',row('SELECT converted_employee_id c FROM applications WHERE id=?',[$a5])['c']===null);
$id5=RecruitmentRepository::convertToEmployee($a5,conv(['first_name'=>'Zzcy'.$sfx,'last_name'=>'Lim','work_company'=>'PMBSI']));
check('the same application converts once the input is valid',$id5>0);
check('second convert of the same application is refused',rejects(fn()=>RecruitmentRepository::convertToEmployee($a5,conv(['first_name'=>'Zzcy'.$sfx,'last_name'=>'Lim','work_company'=>'PMBSI']))));

// --- a hand-made user with the same email is never taken over ------------------------------------------------
$name='Zzdi'.$sfx;
insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="EMPLOYEE"),?,?,?,"ACTIVE")',['Hand made',strtolower($name).'tan@pmbsi.com',password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
$a6=hired_app($name,'Tan',$mr,$client,$job);
$id6=RecruitmentRepository::convertToEmployee($a6,conv(['first_name'=>$name,'last_name'=>'Tan','work_company'=>'PMBSI']));
check('hand-made user with the same email stays untouched; new account gets a number',emp($id6)['user_email']===strtolower($name).'tan2@pmbsi.com'&&(int)row('SELECT COUNT(*) c FROM employees WHERE user_id=(SELECT id FROM users WHERE email=?)',[strtolower($name).'tan@pmbsi.com'])['c']===0);

// --- Company must match the Department's company (so the form defaults Company from the Department) -----------
$dept2=insert('INSERT INTO departments(code,name,active,work_company) VALUES(?,?,1,"2LOG")',['ZD'.$sfx,'Zz dept '.$sfx]);
$a7=hired_app('Zzed'.$sfx,'Go',$mr,$client,$job);
$u0=$count('users');$e0=$count('employees');
check('Company that does not match the Department is rejected and nothing is saved',rejects(fn()=>RecruitmentRepository::convertToEmployee($a7,conv(['first_name'=>'Zzed'.$sfx,'last_name'=>'Go','work_company'=>'PMBSI','department_id'=>$dept2])))&&$count('users')===$u0&&$count('employees')===$e0);
$id7=RecruitmentRepository::convertToEmployee($a7,conv(['first_name'=>'Zzed'.$sfx,'last_name'=>'Go','work_company'=>'2LOG','department_id'=>$dept2]));
check('Company matching the Department converts and uses its domain',emp($id7)['user_email']===strtolower('Zzed'.$sfx).'go@2log.com');

finish();
