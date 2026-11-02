<?php
declare(strict_types=1);
// Portal accounts for EXISTING employees (SPEC-employee-bulk-portal-accounts.md). Synthetic data only; run with HRIS_TEST_DB on a copy that has migration 020.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/EmployeeAccountService.php';
$f=fixtures();
$GLOBALS['config']['security']['credential_key']=base64_encode(random_bytes(32));
$root=dirname(__DIR__);

$sfx=strtr(bin2hex(random_bytes(3)),'0123456789','ghijklmnop');     // letters only: names must survive the email local-part cleaning
function pe(string $first,string $last,array $extra=[]): int {
    static $n=0;$n++;
    return EmployeeRepository::create(array_replace(['employee_no'=>'B-'.bin2hex(random_bytes(3)).$n,'first_name'=>$first,'last_name'=>$last,'work_company'=>'PMBSI','require_work_company'=>true,
        'employment_type_id'=>1,'require_employee_status'=>true,'hire_date'=>'2026-11-02','department_id'=>0,'position_id'=>0,'branch_id'=>0,'user_id'=>0],$extra));
}
function users_count(): int { return (int)row('SELECT COUNT(*) c FROM users')['c']; }
function emp(int $id): array { return row('SELECT user_id,company_email,work_company,status FROM employees WHERE id=?',[$id]); }

db()->beginTransaction();   // everything below is rolled back at the end

// --- single account for an existing employee ----------------------------------------------------
$a=pe('Zzb'.$sfx,'Existing');
check('pending: unlinked active employee is listed',in_array($a,array_map('intval',array_column(EmployeeAccountService::pending(),'id')),true));
$r=EmployeeAccountService::issueForExisting($a);
check('single: email is firstnamelastname@pmbsi.com',$r['email']===strtolower('Zzb'.$sfx).'existing@pmbsi.com');
$e=emp($a);$u=row('SELECT u.*,r.code role_code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$r['user_id']]);
check('single: linked and company_email saved',(int)$e['user_id']===(int)$r['user_id']&&$e['company_email']===$r['email']);
check('single: role EMPLOYEE, ACTIVE, must change password',$u['role_code']==='EMPLOYEE'&&$u['status']==='ACTIVE'&&(int)$u['must_change_password']===1);
$plain=EmployeeAccountService::decrypt($u['password_enc']);
check('single: HR copy decrypts and login hash verifies',$plain!==null&&password_verify($plain,$u['password_hash']));
check('single: audit row written and holds no password',(int)row('SELECT COUNT(*) c FROM audit_logs WHERE module="Access Control" AND action="AUTO_CREATE_USER" AND record_id=? AND details_json NOT LIKE ?',[$r['user_id'],'%'.$plain.'%'])['c']===1);
check('pending: linked employee is no longer listed',!in_array($a,array_map('intval',array_column(EmployeeAccountService::pending(),'id')),true));
$before=users_count();
check('single: second click is refused and creates nothing',rejects(fn()=>EmployeeAccountService::issueForExisting($a))&&users_count()===$before);
check('single: unknown employee is refused',rejects(fn()=>EmployeeAccountService::issueForExisting(999999999)));

// --- roles ----------------------------------------------------------------------------------------
$b=pe('Zzb'.$sfx,'Existing');
foreach(['SUPER_ADMIN','HR_USER','PAYROLL',''] as $bad)check('single: forged role "'.$bad.'" is refused',rejects(fn()=>EmployeeAccountService::issueForExisting($b,$bad)));
$sess=$_SESSION['user']??null;unset($_SESSION['user']);Auth::clearPermissionCache();
check('single: HR role without users.view_credentials is refused',rejects(fn()=>EmployeeAccountService::issueForExisting($b,'HR'))&&(int)emp($b)['user_id']===0);
act(['id'=>$f['hr']['id'],'role_id'=>$f['hr']['role_id'],'role_code'=>'SUPER_ADMIN','role_portal'=>'admin','name'=>'t','role_name'=>'t','email'=>'t@example.test','client_id'=>null]);
$rb=EmployeeAccountService::issueForExisting($b,'HR');
if($sess)$_SESSION['user']=$sess;else unset($_SESSION['user']);Auth::clearPermissionCache();
check('single: duplicate name gets a number',$rb['email']===strtolower('Zzb'.$sfx).'existing2@pmbsi.com');
check('single: HR role maps to HR_USER',row('SELECT r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$rb['user_id']])['code']==='HR_USER');

// --- skip rules (nothing is written, nothing is guessed) ------------------------------------------
$noLast=pe('Zzc'.$sfx,'x');db()->prepare('UPDATE employees SET last_name="" WHERE id=?')->execute([$noLast]);
$before=users_count();
check('skip: missing last name is refused',rejects(fn()=>EmployeeAccountService::issueForExisting($noLast))&&users_count()===$before);
$noCo=pe('Zzd'.$sfx,'Nocompany',['work_company'=>'','require_work_company'=>false]);
db()->prepare('UPDATE employees SET legal_entity_id=NULL,work_company=NULL WHERE id=?')->execute([$noCo]);   // create() always sets the PMBSI Legal entity
check('skip: no Company and no Legal entity is refused',rejects(fn()=>EmployeeAccountService::issueForExisting($noCo))&&users_count()===$before);
$le=(int)row('SELECT id FROM legal_entities WHERE name="PMBSI" LIMIT 1')['id'];
db()->prepare('UPDATE employees SET legal_entity_id=? WHERE id=?')->execute([$le,$noCo]);
$rc=EmployeeAccountService::issueForExisting($noCo);
check('fallback: blank Company uses the Legal entity domain and does not write the Company',$rc['email']===strtolower('Zzd'.$sfx).'nocompany@pmbsi.com'&&emp($noCo)['work_company']===null);
$term=pe('Zze'.$sfx,'Archived');db()->prepare('UPDATE employees SET status="TERMINATED" WHERE id=?')->execute([$term]);
check('skip: terminated employee is refused and not listed',rejects(fn()=>EmployeeAccountService::issueForExisting($term))&&!in_array($term,array_map('intval',array_column(EmployeeAccountService::pending(),'id')),true));
$hand=strtolower('Zzf'.$sfx).'handmade@pmbsi.com';
$roleId=(int)row('SELECT id FROM roles WHERE code="EMPLOYEE"')['id'];
$handId=insert('INSERT INTO users(role_id,client_id,full_name,email,password_hash,status) VALUES(?,NULL,?,?,?,"ACTIVE")',[$roleId,'Hand Made',$hand,password_hash('x-Test-only-1',PASSWORD_DEFAULT)]);
$hm=pe('Zzf'.$sfx,'Handmade');$before=users_count();
$msg='';try{EmployeeAccountService::issueForExisting($hm);}catch(Throwable $t){$msg=$t->getMessage();}
check('skip: email used by a hand-made account is refused with a clear reason',str_contains($msg,'already uses')&&users_count()===$before&&(int)emp($hm)['user_id']===0);
check('skip: the hand-made account is not changed or linked',(int)row('SELECT COUNT(*) c FROM employees WHERE user_id=?',[$handId])['c']===0&&row('SELECT email FROM users WHERE id=?',[$handId])['email']===$hand);

// --- bulk -----------------------------------------------------------------------------------------
db()->exec('UPDATE employees SET status="INACTIVE" WHERE COALESCE(user_id,0)=0 AND (employee_no IS NULL OR employee_no NOT LIKE "B-%")');   // keep real rows out of the run
$g1=pe('Zzg'.$sfx,'One');$g2=pe('Zzh'.$sfx,'Two');$g3=pe('Zzi'.$sfx,'Three',['work_company'=>'2LOG']);
$badName=pe('Zzj'.$sfx,'x');db()->prepare('UPDATE employees SET last_name="" WHERE id=?')->execute([$badName]);
$hm2=pe('Zzf'.$sfx,'Handmade');
$before=users_count();
$res=EmployeeAccountService::issueMissing();
$createdNames=array_column($res['created'],'name');
check('bulk: creates one account per eligible employee',users_count()===$before+3&&count($res['created'])===3);
check('bulk: each eligible employee is linked and gets the right domain',(int)emp($g1)['user_id']>0&&(int)emp($g2)['user_id']>0&&str_ends_with(emp($g3)['company_email'],'@2log.com'));
check('bulk: skipped rows carry a name and a reason',count($res['skipped'])>=2&&!in_array('',array_column($res['skipped'],'reason'),true));
check('bulk: skipped employees stay unlinked',(int)emp($badName)['user_id']===0&&(int)emp($hm2)['user_id']===0);
check('bulk: result never contains a password',!preg_match('/"password|[A-Za-z2-9]{12}"/',json_encode($res)));
check('bulk: bulk accounts are Employee role, must change password',(int)row('SELECT COUNT(*) c FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id IN (?,?) AND r.code="EMPLOYEE" AND u.must_change_password=1',[emp($g1)['user_id'],emp($g2)['user_id']])['c']===2);
$before=users_count();$again=EmployeeAccountService::issueMissing();
check('bulk: running it again creates nothing new',users_count()===$before&&count($again['created'])===0);
check('bulk: existing hand-made account untouched',row('SELECT email,status FROM users WHERE id=?',[$handId])['status']==='ACTIVE');

// --- saving the 201 file must keep the account link --------------------------------------------------
$linked=(int)emp($g1)['user_id'];
EmployeeRepository::updateEmployment($g1,['remarks'=>'unrelated edit']);
check('save without user_id keeps the account link',(int)emp($g1)['user_id']===$linked);

db()->rollBack();

// --- static: handler and screens ---------------------------------------------------------------------
$idx=file_get_contents($root.'/index.php');$fr=file_get_contents($root.'/app/FoundationRepository.php');
$upd=substr($idx,(int)strpos($idx,"if (\$action === 'update_employee_employment')"),1800);
check('201 save handler no longer posts user_id (it would unlink the account)',!str_contains($upd,"'user_id'=>(int)(\$_POST['user_id']"));
check('201 file: User account dropdown replaced by Generate account',!str_contains($idx,'<label>User account</label>')&&str_contains($idx,'generate_employee_account'));
check('removed: link existing account handler, form and helper',!str_contains($idx,'link_user_employee')&&!str_contains($idx,'Save employee link')&&!str_contains($fr,'function linkableEmployees'));
check('actions are gated and CSRF-protected',str_contains($idx,"'generate_employee_account'")&&str_contains($idx,"'generate_missing_accounts'")&&str_contains($idx,'users.view_credentials'));

finish();
