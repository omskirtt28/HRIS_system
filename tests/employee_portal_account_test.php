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
$sess=$_SESSION['user']??null;
check('create: HR role needs users.view_credentials (no session or plain employee is refused)',rejects(fn()=>EmployeeAccountService::createEmployee(emp_data('Zznohr','Case','PMBSI'),'HR'))&&(int)row('SELECT COUNT(*) c FROM employees WHERE first_name="Zznohr"')['c']===0);
act(['id'=>$f['hr']['id'],'role_id'=>$f['hr']['role_id'],'role_code'=>'SUPER_ADMIN','role_portal'=>'admin','name'=>'t','role_name'=>'t','email'=>'t@example.test','client_id'=>null]);
$h=EmployeeAccountService::createEmployee(emp_data($nm.'hr','Dela Cruz','PMBSI'),'HR');
if($sess)$_SESSION['user']=$sess;else unset($_SESSION['user']);Auth::clearPermissionCache();
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


// --- T4 employee changes own password ----------------------------------------------------
$uid=(int)$a['user_id'];$old=$plain;$newp='Brand-New-Pass-77';
check('must-change flag is on for a new account',EmployeeAccountService::mustChange($uid)===true);
$tok=function(string $t)use($uid){insert('INSERT INTO user_sessions(user_id,session_token_hash,last_activity_at) VALUES(?,?,NOW())',[$uid,hash('sha256',$t)]);};
$tok('keep-me'.$tag);$tok('other-device'.$tag);$keep=hash('sha256','keep-me'.$tag);
$try=fn(string $cur,string $new,string $conf)=>EmployeeAccountService::changeOwnPassword($uid,$cur,$new,$conf,$keep);
check('change: wrong current password is rejected',rejects(fn()=>$try('wrong-current-1',$newp,$newp)));
check('change: too short is rejected',rejects(fn()=>$try($old,'Ab1',  'Ab1')));
check('change: no number is rejected',rejects(fn()=>$try($old,'OnlyLettersHere','OnlyLettersHere')));
check('change: no letter is rejected',rejects(fn()=>$try($old,'1234567890123','1234567890123')));
check('change: confirmation mismatch is rejected',rejects(fn()=>$try($old,$newp,$newp.'x')));
check('change: same as current is rejected',rejects(fn()=>$try($old,$old,$old)));
check('change: rejected attempts changed nothing',password_verify($old,row('SELECT password_hash h FROM users WHERE id=?',[$uid])['h'])&&EmployeeAccountService::mustChange($uid));
$try($old,$newp,$newp);
$u2=row('SELECT * FROM users WHERE id=?',[$uid]);
check('change: new password verifies and old no longer does',password_verify($newp,$u2['password_hash'])&&!password_verify($old,$u2['password_hash']));
check('change: HR copy now decrypts to the new password',EmployeeAccountService::decrypt($u2['password_enc'])===$newp);
check('change: must-change cleared and changed-at set',(int)$u2['must_change_password']===0&&$u2['password_changed_at']!==null&&!EmployeeAccountService::mustChange($uid));
check('change: other sessions revoked, current kept',(int)row('SELECT COUNT(*) c FROM user_sessions WHERE user_id=? AND session_token_hash=? AND revoked_at IS NULL',[$uid,$keep])['c']===1&&(int)row('SELECT COUNT(*) c FROM user_sessions WHERE user_id=? AND session_token_hash<>? AND revoked_at IS NULL',[$uid,$keep])['c']===0);
check('change: audit row written without any password',(int)row('SELECT COUNT(*) c FROM audit_logs WHERE action="CHANGE_OWN_PASSWORD" AND record_id=? AND details_json NOT LIKE ? AND details_json NOT LIKE ?',[$uid,'%'.$newp.'%','%'.$old.'%'])['c']===1);
for($i=0;$i<5;$i++)rejects(fn()=>EmployeeAccountService::changeOwnPassword($uid,'wrong-guess-'.$i,'Another-Pass-88x','Another-Pass-88x',$keep));
check('change: 5 wrong attempts lock further tries for a while',rejects(fn()=>EmployeeAccountService::changeOwnPassword($uid,$newp,'Another-Pass-88x','Another-Pass-88x',$keep))&&password_verify($newp,row('SELECT password_hash h FROM users WHERE id=?',[$uid])['h']));


// --- T4 static: page, gate and menu ------------------------------------------------------
$mod=file_get_contents(dirname(__DIR__).'/app/AccountPasswordModule.php');$view=file_get_contents(dirname(__DIR__).'/app/View.php');$idx=file_get_contents(dirname(__DIR__).'/index.php');
check('gate: module is hooked in index.php before any module handler',strpos($idx,'account_password_handle($page);')!==false&&strpos($idx,'account_password_handle($page);')<strpos($idx,'ticket_handle($page);'));
check('gate: forced users are redirected except change-password and logout',str_contains($mod,"if(\$forced && !in_array(\$page,['change-password','logout'],true)) redirect('change-password');"));
check('reveal route: POST-only JSON, CSRF checked, no-store, uses the service',str_contains($mod,"\$page==='user-password-reveal'")&&str_contains($mod,"'Cache-Control: no-store'")&&str_contains($mod,'EmployeeAccountService::reveal('));
check('page: POST is CSRF-protected and uses the service',str_contains($mod,'verify_csrf();')&&str_contains($mod,'EmployeeAccountService::changeOwnPassword('));
check('page: password fields use the right autocomplete hints',substr_count($mod,'autocomplete="new-password"')===2&&str_contains($mod,'autocomplete="current-password"'));
check('menu: account menu links to Change password',str_contains($view,"url('change-password')"));


// --- T5 reveal and reset -----------------------------------------------------------------
$hrRole=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,"hr")',[$tag.'_hrv','Slice15 hr viewer']);
foreach(['users.view_directory','users.view_credentials'] as $pc){$pp=row('SELECT id FROM permissions WHERE code=?',[$pc]);db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$hrRole,$pp['id']]);}
$hrId=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$hrRole,'Slice15 HR Viewer',strtolower($tag).'_hrv@example.test',password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
$hrUser=['id'=>$hrId,'role_id'=>$hrRole,'role_code'=>$tag.'_hrv','role_portal'=>'hr','name'=>'Slice15 HR Viewer','role_name'=>'HR viewer','email'=>strtolower($tag).'_hrv@example.test','client_id'=>null];
$nobody=$f['employee'];
$superUser=$hrUser;$superUser['role_code']='SUPER_ADMIN';
$empUid=(int)$b['user_id'];$empPlain=EmployeeAccountService::decrypt(row('SELECT password_enc e FROM users WHERE id=?',[$empUid])['e']);
act($hrUser);
$r=EmployeeAccountService::reveal($empUid);
check('reveal: HR sees an employee password',$r['password']===$empPlain&&$r['password']!==null);
check('reveal: HR sees an HR account password too (D11)',EmployeeAccountService::reveal((int)$h['user_id'])['password']!==null);
$legacy=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$u['role_id'],'Slice15 Legacy',strtolower($tag).'_legacy@example.test',password_hash('Legacy-Pass-1x',PASSWORD_DEFAULT)]);
$r=EmployeeAccountService::reveal($legacy);
check('reveal: account without a stored copy returns no password and says to reset',$r['password']===null&&str_contains((string)$r['message'],'Reset'));
check('reveal: unknown user returns no password',EmployeeAccountService::reveal(999999999)['password']===null);
check('reveal: every reveal is audited without the password',(int)row('SELECT COUNT(*) c FROM audit_logs WHERE action="REVEAL_PASSWORD" AND user_id=? AND record_id=? AND details_json NOT LIKE ?',[$hrId,$empUid,'%'.$empPlain.'%'])['c']>=1);
check('reveal: employee without the permission is refused',(function()use($nobody,$empUid){act($nobody);return rejects(fn()=>EmployeeAccountService::reveal($empUid));})());
act($hrUser);
$ok=0;for($i=0;$i<12;$i++){try{EmployeeAccountService::reveal($empUid);$ok++;}catch(RuntimeException){}}
check('reveal: rate limited to 10 per minute per user',$ok<=7&&rejects(fn()=>EmployeeAccountService::reveal($empUid)));
db()->prepare('DELETE FROM audit_logs WHERE action="REVEAL_PASSWORD" AND user_id=?')->execute([$hrId]);

// reset: Super Admin any account, HR only employee-portal accounts; copy rewritten, must change set
act($hrUser);FoundationRepository::resetUserPassword($empUid,'HR-Reset-Pass-55');
$ru=row('SELECT * FROM users WHERE id=?',[$empUid]);
check('reset by HR: employee account gets the new password everywhere',password_verify('HR-Reset-Pass-55',$ru['password_hash'])&&EmployeeAccountService::decrypt($ru['password_enc'])==='HR-Reset-Pass-55'&&(int)$ru['must_change_password']===1);
check('reset by HR: HR-portal account is refused',rejects(fn()=>FoundationRepository::resetUserPassword((int)$h['user_id'],'HR-Reset-Pass-56')));
check('reset by HR: own account is refused',rejects(fn()=>FoundationRepository::resetUserPassword($hrId,'HR-Reset-Pass-57')));
act($nobody);check('reset by employee role is refused',rejects(fn()=>FoundationRepository::resetUserPassword($empUid,'Nope-Reset-Pass-58')));
act($superUser);FoundationRepository::resetUserPassword((int)$h['user_id'],'Super-Reset-Pass-59');
check('reset by Super Admin: HR account allowed and copy rewritten',EmployeeAccountService::decrypt(row('SELECT password_enc e FROM users WHERE id=?',[(int)$h['user_id']])['e'])==='Super-Reset-Pass-59');
act($hrUser);


// --- T5 static: Users & Access page -------------------------------------------------------
$idx=file_get_contents(dirname(__DIR__).'/index.php');$view=file_get_contents(dirname(__DIR__).'/app/View.php');
$users=substr($idx,strpos($idx,"if (\$page === 'admin-users') {"),30000);
check('users page: HR directory permission opens the page',str_contains($users,"Auth::can('users.view_directory')")&&str_contains($users,"\$canReveal=Auth::can('users.view_credentials');"));
check('users page: password column and modal follow users.view_credentials, not Super Admin only',substr_count($users,'<?php if($canReveal):?>')>=4&&!str_contains($users,'<?php if($isSuperAdmin):?><th>Password'));
check('users page: no password or reveal value is ever rendered into the page',!str_contains($users,'data-revealed-password')&&!str_contains($idx,'password_reset_reveal'));
check('users page: eye fetches the reveal route with CSRF and hides after 30 s',str_contains($users,"user-password-reveal")&&str_contains($users,'setTimeout(closePasswordAccess,30000)')&&str_contains($users,'id="passwordRevealForm"'));
check('users page: old one-way-hash-only claim removed',!str_contains($users,'cannot be revealed, even to a Super Administrator'));
check('users page: HR sees Reset only for employee-portal accounts',str_contains($users,"(\$isSuperAdmin || \$u['portal']==='employee')"));
check('users page: reset action allows HR via users.view_credentials',str_contains($idx,"if(!Auth::is('SUPER_ADMIN')) Auth::requirePermission('users.view_credentials');"));
check('nav: HR sidebar links to Users & Access with the directory permission',str_contains($view,"['Users & Access','admin-users','shield',false,'users.view_directory']"));


// --- T6 forgotten-password request under HR Requests --------------------------------------
require_once dirname(__DIR__).'/app/TicketService.php';
$def=TicketTypes::definition('PORTAL_ACCOUNT_REQUEST');
check('ticket type: defined as an HR-only request with a concern and optional remarks',$def['name']==='Portal account / forgotten password'&&$def['profile']==='HR_ONLY'&&in_array('Forgot password',$def['fields']['concern']['options'],true)&&!empty($def['fields']['remarks']['optional']));
check('ticket type: filing permission is ticket.file.portal_account',(TicketTypes::FILE_PERMISSIONS['PORTAL_ACCOUNT_REQUEST']??'')==='ticket.file.portal_account');
check('ticket type: row exists after migration 020',(int)row('SELECT COUNT(*) c FROM ticket_types WHERE code="PORTAL_ACCOUNT_REQUEST" AND active=1')['c']===1);
$empRole=row('SELECT r.id FROM roles r WHERE r.code="EMPLOYEE"')['id'];
check('permission: Employee role may file it, HR role (HR_USER) too',(int)row('SELECT COUNT(*) c FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.code="ticket.file.portal_account" AND rp.role_id IN (SELECT id FROM roles WHERE code IN ("EMPLOYEE","HR_USER"))')['c']===2);
$eu=row('SELECT u.id,u.role_id,u.full_name name,u.email,r.code role_code,r.name role_name,r.portal role_portal FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$empUid]);
db()->prepare('UPDATE employees SET branch_id=?,business_unit_id=?,legal_entity_id=? WHERE id=?')->execute([$f['branch'],$f['company'],$f['legal'],$b['employee_id']]); // existing rule: filers need a company and branch
$eu['client_id']=null;act($eu);
check('filing: a new employee account may file the request',TicketTypes::canFile('PORTAL_ACCOUNT_REQUEST'));
$tid=0;try{$tid=TicketService::create(['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code="PORTAL_ACCOUNT_REQUEST"')['id'],'submission_key'=>bin2hex(random_bytes(32)),'concern'=>'Forgot password','remarks'=>'Cannot remember it'],true);}catch(Throwable $x){echo 'NOTE '.$x->getMessage().PHP_EOL;}
check('filing: request is created and waits for HR',$tid>0&&row('SELECT status FROM tickets WHERE id=?',[$tid])['status']==='PENDING_HR');
check('filing: invalid concern is rejected',rejects(fn()=>TicketService::create(['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code="PORTAL_ACCOUNT_REQUEST"')['id'],'submission_key'=>bin2hex(random_bytes(32)),'concern'=>'Give me admin','remarks'=>''],true)));
$login=file_get_contents(dirname(__DIR__).'/index.php');
check('login page tells people to ask HR when they forgot the password',str_contains($login,'Forgot your password? Ask HR to reset it.'));
act($hrUser);


// --- T7 static: old creation paths removed ------------------------------------------------
$idx=file_get_contents(dirname(__DIR__).'/index.php');$rec=file_get_contents(dirname(__DIR__).'/app/RecruitmentRepository.php');$css=file_get_contents(dirname(__DIR__).'/public/assets/hris-modern.css');
check('admin gate: only admin-users opens to HR (users.view_directory); other admin pages keep requirePortal',str_contains($idx,"if (!(\$page==='admin-users' && Auth::can('users.view_directory'))) Auth::requirePortal('admin');"));
check('removed: Create New User card',!str_contains($idx,'Create New User')&&!str_contains($idx,'id="createUserForm"'));
check('removed: create_user action handler and route entry',!str_contains($idx,"'create_user'")&&!str_contains($idx,'value="create_user"'));
check('removed: no typed Temporary-password create form left on Users & Access',substr_count($idx,'name="password"')===substr_count($idx,'type="password" name="password"'));
check('users list uses the full width now (single column layout)',str_contains($css,'.users-access-layout{display:grid;grid-template-columns:minmax(0,1fr);'));
check('repository createUser stays as the tested API (not reachable from any page)',str_contains(file_get_contents(dirname(__DIR__).'/app/FoundationRepository.php'),'public static function createUser('));
check('recruitment conversion accounts get the HR copy and a forced first change',str_contains($rec,"EmployeeAccountService::issue(\$employeeId,\$employeeData['first_name'],\$employeeData['last_name'],\$employeeData['work_company'],'EMPLOYEE');")&&!str_contains($rec,'temporary_password'));

finish();
