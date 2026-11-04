<?php
declare(strict_types=1);
// hiring-review-pass-visibility (SPEC-hiring-review-pass-visibility.md). Synthetic data only; run with HRIS_TEST_DB on a scratch copy that has migration 031.
require __DIR__.'/ticket_test_support.php';
$f=fixtures();
$sfx=strtr(bin2hex(random_bytes(3)),'0123456789','ghijklmnop');
foreach(['recruitment.view','recruitment.manage'] as $code){db()->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code=?')->execute([$f['hr']['role_id'],$code]);}

function real_user(string $roleCode,string $label,?int $branch=null): array {
    $r=row('SELECT id,code,portal FROM roles WHERE code=?',[$roleCode]);
    $email=strtolower('zz_'.$label.'_'.bin2hex(random_bytes(3))).'@example.test';
    $id=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$r['id'],'Zz '.$label,$email,password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
    if($branch!==null) insert('INSERT INTO employees(employee_no,first_name,last_name,branch_id,user_id,status,hire_date) VALUES(?,?,?,?,?,"ACTIVE","2020-01-01")',['ZV'.bin2hex(random_bytes(4)),'Zz',$label,$branch,$id]);
    return ['id'=>$id,'role_id'=>(int)$r['id'],'role_code'=>$r['code'],'role_portal'=>$r['portal'],'name'=>'Zz '.$label,'role_name'=>$r['code'],'email'=>$email,'client_id'=>null];
}
function make_app(int $mr,int $client,int $job): int {
    $applicant=insert('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")',['APP-ZV-'.bin2hex(random_bytes(4)),'Zz','Candidate','zz_'.bin2hex(random_bytes(4)).'@example.test','09175550188']);
    return insert('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code="INTERVIEW"),NOW(),NOW(),"ACTIVE")',['PMBSI-ZV-'.bin2hex(random_bytes(4)),$applicant,$job,$mr,$client]);
}
function ids(array $rows): array { $o=array_map('intval',array_column($rows,'id')); sort($o); return $o; }
function sorted(int ...$a): array { sort($a); return $a; }

$client=insert('INSERT INTO clients(code,name) VALUES(?,?)',['ZV'.$sfx,'Zzv '.$sfx.' Trading']);
function make_job(int $client,string $site,string $sfx,?int $requester=null): array {
    $mr=insert('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status,requested_by) VALUES(?,?,?,?,0,"REGULAR","NORMAL","APPROVED",?)',['MR-ZV-'.bin2hex(random_bytes(4)),$client,'Synthetic clerk',1,$requester]);
    $job=insert('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type,site_type) VALUES(?,?,?,?,?,?,1,"OPEN","Synthetic","REGULAR",?)',['JOB-ZV-'.bin2hex(random_bytes(3)),'zzv-'.bin2hex(random_bytes(4)),'Synthetic clerk','x',$client,$mr,$site===''?null:$site]);
    return [$mr,$job];
}

// areas: A has an ADL, B has none
$areaA=insert('INSERT INTO areas(code,name) VALUES(?,?)',['ZA'.$sfx,'Zz Area A']);$areaB=insert('INSERT INTO areas(code,name) VALUES(?,?)',['ZB'.$sfx,'Zz Area B']);
$brA1=insert('INSERT INTO branches(area_id,code,name,site_type,active) VALUES(?,?,?,"RETAIL_STORE",1)',[$areaA,'ZA1'.$sfx,'Zz Branch A1']);
$brA2=insert('INSERT INTO branches(area_id,code,name,site_type,active) VALUES(?,?,?,"RETAIL_STORE",1)',[$areaA,'ZA2'.$sfx,'Zz Branch A2']);
$brB1=insert('INSERT INTO branches(area_id,code,name,site_type,active) VALUES(?,?,?,"RETAIL_STORE",1)',[$areaB,'ZB1'.$sfx,'Zz Branch B1']);
$brC1=insert('INSERT INTO branches(area_id,code,name,site_type,active) VALUES(?,?,?,"RETAIL_STORE",1)',[$areaA,'ZC1'.$sfx,'Zz Branch C1 other']);
$adlA=real_user('ADL','adlA',$brA1);$oicA1=real_user('OIC','oicA1',$brA1);$scA2=real_user('SC','scA2',$brA2);$mtB1=real_user('MT','mtB1',$brB1);$oicOut=real_user('OIC','oicOut',$brC1);
$mgr1=real_user('MANAGER','mgr1');$mgr2=real_user('MANAGER','mgr2');$tc=real_user('TC','tc');$tc2=real_user('TC','tc2');

check('migration 031: job_opening_branches exists',RecruitmentRepository::branchScopeReady());

// --- Head Office ---------------------------------------------------------------------------------------------
[$mrH,$jobH]=make_job($client,'HEAD_OFFICE',$sfx,$mgr1['id']);$appH=make_app($mrH,$client,$jobH);
check('HO: HR may endorse to TC and Manager only, never ADL/OIC/SC/MT',(function() use($appH){ $r=array_unique(array_column(RecruitmentRepository::targetsFor($appH,null),'role_code')); sort($r); return $r===['MANAGER','TC']; })());
check('HO: a TC may pass to Managers only',(function() use($appH,$mgr1,$mgr2,$tc){ $t=RecruitmentRepository::targetsFor($appH,'TC',$tc['id']); return $t&&array_unique(array_column($t,'role_code'))===['MANAGER']; })());
check('HO: the direct manager may pass to another Manager or a TC',(function() use($appH,$mgr1){ $r=array_unique(array_column(RecruitmentRepository::targetsFor($appH,'MANAGER',$mgr1['id']),'role_code')); sort($r); return $r===['MANAGER','TC']; })());
check('HO: a Manager who is not the direct manager is the end of the chain (nobody to pass to)',RecruitmentRepository::targetsFor($appH,'MANAGER',$mgr2['id'])===[]);
act($f['hr']);
check('HO: HR endorsing an ADL, OIC, SC or MT is refused',rejects(fn()=>RecruitmentRepository::endorseToReviewers($appH,'x',[$adlA['id']]))&&rejects(fn()=>RecruitmentRepository::endorseToReviewers($appH,'x',[$oicA1['id']])));
RecruitmentRepository::endorseToReviewers($appH,'Round 1',[$tc['id'],$mgr1['id']]);
act($tc);
check('HO: TC passing to a TC, an ADL or an OIC is refused; passing to a Manager works',rejects(fn()=>RecruitmentRepository::passOn($appH,$tc['id'],[$tc2['id']],[],''))&&rejects(fn()=>RecruitmentRepository::passOn($appH,$tc['id'],[$adlA['id']],[],''))&&rejects(fn()=>RecruitmentRepository::passOn($appH,$tc['id'],[$oicA1['id']],[],''))&&(function() use($appH,$tc,$mgr2){RecruitmentRepository::passOn($appH,$tc['id'],[$mgr2['id']],[],'Please review');return true;})());
act($mgr2);
check('HO: the Manager passed in by TC cannot pass further',rejects(fn()=>RecruitmentRepository::passOn($appH,$mgr2['id'],[$tc2['id']],[],'')));
act($mgr1);
RecruitmentRepository::decide($appH,$mgr1['id'],'PROCEED','Good');
check('HO: the direct manager who answered Proceed is locked (cannot pass on)',rejects(fn()=>RecruitmentRepository::passOn($appH,$mgr1['id'],[$tc2['id']],[],'')));
[$mrH2,$jobH2]=make_job($client,'HEAD_OFFICE',$sfx,$mgr1['id']);$appH2=make_app($mrH2,$client,$jobH2);act($f['hr']);RecruitmentRepository::endorseToReviewers($appH2,'x',[$mgr1['id'],$tc['id']]);act($mgr1); // a second reviewer (tc), so one Not suitable does not return the candidate to HR
RecruitmentRepository::decide($appH2,$mgr1['id'],'NOT_SUITABLE','Not for us');
RecruitmentRepository::passOn($appH2,$mgr1['id'],[$tc2['id']],[],'Second opinion');
check('HO: the direct manager who did not Proceed may pass to a TC',(bool)row('SELECT 1 x FROM application_reviewers WHERE application_id=? AND reviewer_user_id=?',[$appH2,$tc2['id']]));

// --- Retail ----------------------------------------------------------------------------------------------------
[$mrR,$jobR]=make_job($client,'RETAIL',$sfx);$appR=make_app($mrR,$client,$jobR);
check('Retail with no branches chosen: nobody but HR (HR sees nobody to endorse either)',RecruitmentRepository::targetsFor($appR,null)===[]&&RecruitmentRepository::targetsFor($appR,'ADL',$adlA['id'])===[]);
foreach([$brA1,$brA2,$brB1] as $b) insert('INSERT INTO job_opening_branches(job_opening_id,branch_id) VALUES(?,?)',[$jobR,$b]);
$hr=RecruitmentRepository::targetsFor($appR,null);
check('Retail: HR sees the ADL of area A, and OIC/SC/MT only for area B (it has no ADL); never a Manager/TC, never a branch outside the job',
    ids($hr)===sorted($adlA['id'],$mtB1['id'])&&!in_array($oicOut['id'],ids($hr),true)&&!array_intersect(array_column($hr,'role_code'),['MANAGER','TC']));
check('Retail: the ADL passes only to OIC/SC/MT of the chosen branches',ids(RecruitmentRepository::targetsFor($appR,'ADL',$adlA['id']))===sorted($oicA1['id'],$scA2['id'],$mtB1['id']));
check('Retail: an OIC passes only to OIC/SC/MT in scope, never to an ADL, Manager or TC',ids(RecruitmentRepository::targetsFor($appR,'OIC',$oicA1['id']))===sorted($oicA1['id'],$scA2['id'],$mtB1['id']));
check('Retail: a Manager or TC acting on a Retail candidate gets nothing',RecruitmentRepository::targetsFor($appR,'MANAGER',$mgr1['id'])===[]&&RecruitmentRepository::targetsFor($appR,'TC',$tc['id'])===[]);
act($f['hr']);
check('Retail: HR endorsing a Manager, a TC or an out-of-scope OIC is refused',rejects(fn()=>RecruitmentRepository::endorseToReviewers($appR,'x',[$mgr1['id']]))&&rejects(fn()=>RecruitmentRepository::endorseToReviewers($appR,'x',[$tc['id']]))&&rejects(fn()=>RecruitmentRepository::endorseToReviewers($appR,'x',[$oicOut['id']])));
RecruitmentRepository::endorseToReviewers($appR,'Round 1',[$adlA['id']]);
act($adlA);
check('Retail: the ADL cannot pass to a Manager, a TC or an out-of-scope OIC, but can pass to an OIC in scope',rejects(fn()=>RecruitmentRepository::passOn($appR,$adlA['id'],[$mgr1['id']],[],''))&&rejects(fn()=>RecruitmentRepository::passOn($appR,$adlA['id'],[$tc['id']],[],''))&&rejects(fn()=>RecruitmentRepository::passOn($appR,$adlA['id'],[$oicOut['id']],[],''))&&(function() use($appR,$adlA,$oicA1){RecruitmentRepository::passOn($appR,$adlA['id'],[$oicA1['id']],[],'');return true;})());

// --- Reviewer position but not endorsable: HR is told why ---------------------------------------------------------
$brD=insert('INSERT INTO branches(area_id,code,name,site_type,active) VALUES(?,?,?,"RETAIL_STORE",1)',[$areaB,'ZD1'.$sfx,'Zz Branch D1']);
[$mrD,$jobD]=make_job($client,'RETAIL',$sfx);$appD=make_app($mrD,$client,$jobD);insert('INSERT INTO job_opening_branches(job_opening_id,branch_id) VALUES(?,?)',[$jobD,$brD]);
$scEmp=real_user('EMPLOYEE','scEmp'); // a Sales Captain by position whose portal account is still a plain Employee
$posSc=(int)row('SELECT id FROM positions WHERE name="Sales Captain" LIMIT 1')['id'];
insert('INSERT INTO employees(employee_no,first_name,last_name,branch_id,position_id,user_id,status,hire_date) VALUES(?,?,?,?,?,?,"ACTIVE","2020-01-01")',['ZV'.bin2hex(random_bytes(4)),'Zz','scEmp',$brD,$posSc,$scEmp['id']]);
insert('INSERT INTO employees(employee_no,first_name,last_name,branch_id,position_id,status,hire_date) VALUES(?,?,?,?,?,"ACTIVE","2020-01-01")',['ZV'.bin2hex(random_bytes(4)),'Zz','scNone',$brD,$posSc]);
$why=array_column(RecruitmentRepository::scopeStaffWithoutAccount($appD),'reason','name');
check('Retail: not offered, and HR is told: Sales Captain with an Employee-role account, and one with no account',RecruitmentRepository::targetsFor($appD,null)===[]&&count($why)===2&&str_contains($why['Zz scNone'],'no portal account')&&str_contains($why['Zz scEmp'],'role is'));
// --- Inline role fix on the Endorse card (SPEC-endorse-inline-role-fix.md) ---
act($f['hr']);
check('inline fix: without recruitment.set_reviewer_role there is no suggested role and the fix is refused',array_filter(array_column(RecruitmentRepository::scopeStaffWithoutAccount($appD),'suggested_role'))===[]&&rejects(fn()=>RecruitmentRepository::fixReviewerRole($appD,$scEmp['id'])));
// what migration 035 gives HR_USER: only the narrow permission (no users.manage, no SC permissions)
db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code="recruitment.set_reviewer_role"')->execute([$f['hr']['role_id']]);
Auth::clearPermissionCache();
$sug=array_column(RecruitmentRepository::scopeStaffWithoutAccount($appD),'suggested_role','name');
check('inline fix: the Sales Captain with an Employee account gets SC suggested; the no-account one gets nothing',($sug['Zz scEmp']??null)==='SC'&&$sug['Zz scNone']===null);
require_once __DIR__.'/../app/CandidateReviewViews.php';
$card=applicant_reviewer_card(['id'=>$appD,'stage_code'=>'INTERVIEW']);
check('inline fix: the card shows a Set role to Sales Captain button wired to a form outside the endorse form (no nested forms)',str_contains($card,'Set role to Sales Captain')&&str_contains($card,'name="action" value="endorse_fix_role"')&&preg_match('~</form>\s*<form id="fixrole-~',$card)===1);
$oicEmp=real_user('EMPLOYEE','oicEmp'); $posOic=(int)(row('SELECT id FROM positions WHERE name="Officer In Charge" LIMIT 1')['id']??0);
if($posOic>0){insert('INSERT INTO employees(employee_no,first_name,last_name,branch_id,position_id,user_id,status,hire_date) VALUES(?,?,?,?,?,?,"ACTIVE","2020-01-01")',['ZV'.bin2hex(random_bytes(4)),'Zz','oicEmp',$brD,$posOic,$oicEmp['id']]);
    $sug=array_column(RecruitmentRepository::scopeStaffWithoutAccount($appD),'suggested_role','name');
    check('inline fix: OIC is not offered to HR (it carries payroll.approve_manager; Super Admin only)',array_key_exists('Zz oicEmp',$sug)&&$sug['Zz oicEmp']===null&&rejects(fn()=>RecruitmentRepository::fixReviewerRole($appD,$oicEmp['id'])));}
    db()->prepare("DELETE FROM employees WHERE user_id=?")->execute([$oicEmp["id"]]); // synthetic row, keeps the later "only the no-account one" check exact
check('inline fix: a user who is not listed on this card cannot be changed',rejects(fn()=>RecruitmentRepository::fixReviewerRole($appD,$f['employee']['id']))&&(int)row('SELECT role_id FROM users WHERE id=?',[$f['employee']['id']])['role_id']===(int)$f['employee']['role_id']);
$audits=(int)row('SELECT COUNT(*) c FROM audit_logs WHERE action="CHANGE_USER_ROLE" AND record_type="user" AND record_id=?',[$scEmp['id']])['c'];
check('inline fix: sets the account to Sales Captain',RecruitmentRepository::fixReviewerRole($appD,$scEmp['id'])==='Sales Captain'&&(string)row('SELECT r.code c FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$scEmp['id']])['c']==='SC');
check('inline fix: the role change is audited',(int)row('SELECT COUNT(*) c FROM audit_logs WHERE action="CHANGE_USER_ROLE" AND record_type="user" AND record_id=?',[$scEmp['id']])['c']===$audits+1);
check('inline fix: the narrow permission does not widen Users & Access: HR still cannot assign the Manager role',rejects(fn()=>FoundationRepository::changeUserRole($f['employee']['id'],(int)row('SELECT id FROM roles WHERE code="MANAGER"')['id'])));
check('inline fix: asking again for the same person is refused (no longer listed)',rejects(fn()=>RecruitmentRepository::fixReviewerRole($appD,$scEmp['id'])));
check('Retail: once the account role is SC, the Sales Captain is offered and only the no-account one is still listed',ids(RecruitmentRepository::targetsFor($appD,null))===sorted($scEmp['id'])&&array_keys(array_column(RecruitmentRepository::scopeStaffWithoutAccount($appD),'reason','name'))===['Zz scNone']);

// --- Other / unknown site ------------------------------------------------------------------------------------------
[$mrO,$jobO]=make_job($client,'OTHER',$sfx);$appO=make_app($mrO,$client,$jobO);
check('Other company: nobody can pass on; HR keeps the full list',RecruitmentRepository::targetsFor($appO,'TC',$tc['id'])===[]&&count(RecruitmentRepository::targetsFor($appO,null))>=5);

// --- Job form save ---------------------------------------------------------------------------------------------------
act($f['hr']);
$jid=JobOpenings::save(['title'=>'Zz scope job','description'=>'x','employment_type'=>'REGULAR','openings'=>1,'site_type'=>'RETAIL','branch_ids'=>[(string)$brA1,(string)$brB1],'form_token'=>''],0);
check('job save: a Retail job stores its branches',(int)row('SELECT COUNT(*) c FROM job_opening_branches WHERE job_opening_id=?',[$jid])['c']===2);
JobOpenings::save(['title'=>'Zz scope job','description'=>'x','employment_type'=>'REGULAR','openings'=>1,'site_type'=>'HEAD_OFFICE','branch_ids'=>[(string)$brA1]],$jid);
check('job save: switching to Head Office clears the branches',(int)row('SELECT COUNT(*) c FROM job_opening_branches WHERE job_opening_id=?',[$jid])['c']===0);
check('job save: an unknown branch id is refused',rejects(fn()=>JobOpenings::save(['title'=>'Zz bad','description'=>'x','employment_type'=>'REGULAR','openings'=>1,'site_type'=>'RETAIL','branch_ids'=>['999999999']],0)));

finish();
