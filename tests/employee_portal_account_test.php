<?php
declare(strict_types=1);
// Employee portal account (SPEC-employee-portal-account.md). Synthetic data only; run with HRIS_TEST_DB on a copy with migration 020 applied.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/EmployeeAccountService.php';
$f=fixtures();$tag=$f['tag'];
$GLOBALS['config']['security']['credential_key']=base64_encode(random_bytes(32));

// --- T2 generator, email, encryption -------------------------------------------------
$seen=[];$okShape=true;
for($i=0;$i<500;$i++){$p=EmployeeAccountService::generatePassword();$seen[$p]=1;
    if(strlen($p)!==12||!preg_match('/[a-z]/',$p)||!preg_match('/[A-Z]/',$p)||!preg_match('/[2-9]/',$p)||preg_match('/[01OIl]/',$p))$okShape=false;}
check('generated password: 12 chars, upper+lower+digit, no look-alikes',$okShape);
check('generated passwords do not repeat in 500 runs',count($seen)===500);
check('generated password passes the existing policy',strlen($p)>=10&&preg_match('/[A-Za-z]/',$p)&&preg_match('/[0-9]/',$p));

check('email local part: plain name',EmployeeAccountService::localPart('Juan','Dela Cruz')==='juandelacruz');
check('email local part: accents and punctuation folded',EmployeeAccountService::localPart('José Ma.','Peña-Cruz')==='josemapenacruz');
check('email local part: suffix is not part of last name',EmployeeAccountService::localPart('Nelson','Tambal Jr.')==='nelsontambal');
check('email local part: empty after cleaning is rejected',rejects(fn()=>EmployeeAccountService::localPart('...','  ')));
check('domain: PMBSI',EmployeeAccountService::domainFor('PMBSI')==='pmbsi.com');
check('domain: 2LOG',EmployeeAccountService::domainFor('2LOG')==='2log.com');
check('domain: unknown company is rejected',rejects(fn()=>EmployeeAccountService::domainFor('NOPE')));
check('domain: missing company is rejected',rejects(fn()=>EmployeeAccountService::domainFor(null)));

$enc=EmployeeAccountService::encrypt('Abc-Test-Only-9x');
check('encrypted copy is not plaintext',!str_contains($enc,'Abc-Test'));
check('encrypt/decrypt round trip',EmployeeAccountService::decrypt($enc)==='Abc-Test-Only-9x');
check('two encryptions of one value differ (random IV)',EmployeeAccountService::encrypt('Abc-Test-Only-9x')!==$enc);
$tampered=substr($enc,0,-4).(substr($enc,-4)==='AAAA'?'BBBB':'AAAA');
check('tampered ciphertext is rejected (null)',EmployeeAccountService::decrypt($tampered)===null);
check('decrypt of null or garbage is null',EmployeeAccountService::decrypt(null)===null&&EmployeeAccountService::decrypt('not-base64!!')===null);
$good=$GLOBALS['config']['security']['credential_key'];
$GLOBALS['config']['security']['credential_key']=base64_encode(random_bytes(32));
check('wrong key cannot decrypt',EmployeeAccountService::decrypt($enc)===null);
$GLOBALS['config']['security']['credential_key']='';
check('missing key fails closed on encrypt',rejects(fn()=>EmployeeAccountService::encrypt('x')));
$GLOBALS['config']['security']['credential_key']='short';
check('short key fails closed on encrypt',rejects(fn()=>EmployeeAccountService::encrypt('x')));
$GLOBALS['config']['security']['credential_key']=$good;


// --- T3 create employee + account in one transaction ----------------------------------
function emp_data(string $first,string $last,string $company,array $extra=[]): array {
    static $n=0;$n++;
    return array_replace(['employee_no'=>'T3-'.bin2hex(random_bytes(3)).$n,'first_name'=>$first,'last_name'=>$last,'work_company'=>$company,'require_work_company'=>true,
        'employment_type_id'=>1,'require_employee_status'=>true,'hire_date'=>'2026-11-02','department_id'=>0,'position_id'=>0,'branch_id'=>0],$extra);
}
$nm='Zzjuan'.strtr(bin2hex(random_bytes(2)),'0123456789','ghijklmnop');          // unique synthetic first name so the test never collides with real rows
$a=EmployeeAccountService::createEmployee(emp_data($nm,'Dela Cruz','PMBSI'),'EMPLOYEE');
check('create: email is firstnamelastname@pmbsi.com',$a['email']===strtolower($nm).'delacruz@pmbsi.com');
$u=row('SELECT u.*,r.code role_code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$a['user_id']]);
$e=row('SELECT user_id,company_email,work_company FROM employees WHERE id=?',[$a['employee_id']]);
check('create: user is linked to the employee',(int)$e['user_id']===(int)$a['user_id']);
check('create: employee company_email equals the account email',$e['company_email']===$a['email']);
check('create: role EMPLOYEE, ACTIVE, must change password',$u['role_code']==='EMPLOYEE'&&$u['status']==='ACTIVE'&&(int)$u['must_change_password']===1);
$plain=EmployeeAccountService::decrypt($u['password_enc']);
check('create: stored copy decrypts to a policy-valid password',$plain!==null&&strlen($plain)===12);
check('create: login hash verifies the generated password',$plain!==null&&password_verify($plain,$u['password_hash']));
check('create: audit row exists and holds no password',(int)row('SELECT COUNT(*) c FROM audit_logs WHERE module="Access Control" AND action="AUTO_CREATE_USER" AND record_id=? AND details_json NOT LIKE ?',[$a['user_id'],'%'.($plain??'zz').'%'])['c']===1);
$b=EmployeeAccountService::createEmployee(emp_data($nm,'Dela Cruz','2LOG'),'EMPLOYEE');
check('create: 2LOG employee gets @2log.com',$b['email']===strtolower($nm).'delacruz@2log.com');
$c=EmployeeAccountService::createEmployee(emp_data($nm,'Dela Cruz','PMBSI'),'EMPLOYEE');
check('create: duplicate name gets a number',$c['email']===strtolower($nm).'delacruz2@pmbsi.com');
$h=EmployeeAccountService::createEmployee(emp_data($nm.'hr','Dela Cruz','PMBSI'),'HR');
check('create: role HR maps to HR_USER',row('SELECT r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$h['user_id']])['code']==='HR_USER');
$before=(int)row('SELECT COUNT(*) c FROM employees')['c'];$beforeU=(int)row('SELECT COUNT(*) c FROM users')['c'];
foreach(['SUPER_ADMIN','HR_USER','PAYROLL','',' '] as $bad)check('create: forged role "'.$bad.'" is rejected',rejects(fn()=>EmployeeAccountService::createEmployee(emp_data('Zzbad','Role','PMBSI'),$bad)));
check('create: rejected role leaves no employee or user',(int)row('SELECT COUNT(*) c FROM employees')['c']===$before&&(int)row('SELECT COUNT(*) c FROM users')['c']===$beforeU);
$dup=emp_data('Zzdup','Person','PMBSI');EmployeeAccountService::createEmployee($dup,'EMPLOYEE');$beforeU=(int)row('SELECT COUNT(*) c FROM users')['c'];
check('create: duplicate employee number is rejected',rejects(fn()=>EmployeeAccountService::createEmployee(array_replace($dup,['first_name'=>'Other']),'EMPLOYEE')));
check('create: duplicate employee number creates no extra user',(int)row('SELECT COUNT(*) c FROM users')['c']===$beforeU);
db()->exec('UPDATE roles SET code="EMPLOYEE_T3HOLD" WHERE code="EMPLOYEE"');
$failNo=emp_data('Zzatomic','Case','PMBSI');
try{check('create: failure after the employee insert is reported',rejects(fn()=>EmployeeAccountService::createEmployee($failNo,'EMPLOYEE')));}
finally{db()->exec('UPDATE roles SET code="EMPLOYEE" WHERE code="EMPLOYEE_T3HOLD"');}
check('create: failure after insert rolls the employee back (atomic)',(int)row('SELECT COUNT(*) c FROM employees WHERE employee_no=?',[$failNo['employee_no']])['c']===0);
check('create: not left inside a transaction',!db()->inTransaction());
$GLOBALS['config']['security']['credential_key']='';
check('create: missing key fails closed before any insert',rejects(fn()=>EmployeeAccountService::createEmployee(emp_data('Zznokey','Case','PMBSI'),'EMPLOYEE'))&&(int)row('SELECT COUNT(*) c FROM employees WHERE first_name="Zznokey"')['c']===0);
$GLOBALS['config']['security']['credential_key']=$good;


// --- T3 static: Create Employee form and handler ----------------------------------------
$idx=file_get_contents(dirname(__DIR__).'/index.php');
check('form: "Link later" user-account select is gone',!str_contains($idx,'<option value="">Link later</option>'));
check('form: Role select offers only Employee and HR',preg_match('#name="account_role" required><option value="EMPLOYEE" selected>Employee</option><option value="HR">HR</option></select>#',$idx)===1);
check('form: company email is a read-only preview with no name (never posted)',preg_match('#<input id="company_email_preview" type="email" readonly#',$idx)===1&&!preg_match('#name="company_email"#',substr($idx,strpos($idx,'Personal information'),9000)));
check('handler: create_employee goes through EmployeeAccountService::createEmployee',str_contains($idx,'EmployeeAccountService::createEmployee([')&&str_contains($idx,"(string)(\$_POST['account_role']??'')"));
check('handler: create_employee no longer trusts a posted user_id',!str_contains(substr($idx,strpos($idx,'EmployeeAccountService::createEmployee(['),400),"'user_id'"));

finish();
