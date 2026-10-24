<?php
declare(strict_types=1);
// Migration 013: OIC Authorization request type, dated OIC access and automatic expiry. Synthetic fixtures only.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/TicketViewerService.php';
require_once dirname(__DIR__).'/app/TicketOicAuthorization.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketViews.php';
require_once dirname(__DIR__).'/app/TicketServiceViews.php';
require_once dirname(__DIR__).'/app/TicketAccountingViews.php';
$f=fixtures();$tag=$f['tag'];
$day=fn(int $n)=>date('Y-m-d',strtotime(($n>=0?'+':'').$n.' days'));

function oform(array $extra=[]):array {
    return array_replace(['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code="OIC_AUTHORIZATION"')['id'],'submission_key'=>bin2hex(random_bytes(32))],$extra);
}
function roleId(string $code):int { return (int)row('SELECT id FROM roles WHERE code=?',[$code])['id']; }
/** account on a real role plus a linked active employee; $account=false gives an employee with no login */
function who(string $tag,string $key,string $roleCode,int $branch,array $f,?int $position=null,bool $account=true):array {
    $rid=roleId($roleCode);$r=row('SELECT code,portal FROM roles WHERE id=?',[$rid]);$email=strtolower($tag.'_o_'.$key).'@example.test';$uid=null;$user=null;
    if($account){
        $uid=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$rid,'Slice15 Oic '.$key,$email,password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
        $user=['id'=>$uid,'role_id'=>$rid,'role_code'=>$r['code'],'role_portal'=>$r['portal'],'name'=>'Slice15 Oic '.$key,'role_name'=>$r['code'],'email'=>$email,'client_id'=>null];
    }
    $emp=insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,branch_id,position_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,?,?,"ACTIVE",?)',[$tag.'_o_'.$key,'Slice15','Oic '.$key,$uid,$branch,$position,$f['legal'],$f['company'],date('Y-m-d',strtotime('-2 years'))]);
    return ['user'=>$user,'employee'=>$emp,'no'=>$tag.'_o_'.$key];
}
function asRole(array $w,string $roleCode):array { $rid=roleId($roleCode);db()->prepare('UPDATE users SET role_id=? WHERE id=?')->execute([$rid,$w['user']['id']]);$w['user']['role_id']=$rid;$w['user']['role_code']=$roleCode;return $w; }
function file_oic(array $user,array $w,int $branch,string $from,string $to,bool $submit=true,array $extra=[]):int {
    act($user);return TicketService::create(oform(array_replace(['employee'=>$w['employee'],'branch'=>$branch,'reason'=>'Sales Captain on leave','start_date'=>$from,'end_date'=>$to],$extra)),$submit);
}
function serve(array $hr,int $ticket):void { act($hr);$t=TicketRepository::find($ticket);TicketService::act($ticket,(int)$t['version'],'COMPLETE','Served');}
function roleOf(int $uid):string { return (string)row('SELECT r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$uid])['code']; }
function authRow(int $ticket):array { return row('SELECT * FROM oic_authorizations WHERE ticket_id=?',[$ticket]); }
function sees(array $user,int $ticket):bool { act($user);try{TicketRepository::find($ticket);return true;}catch(RuntimeException){return false;} }

// ---- Migration 013 shape
$perm=row('SELECT id FROM permissions WHERE code="ticket.file.oic_authorization"');
check('permission ticket.file.oic_authorization exists',$perm!==[]);
$granted=array_column(db()->query('SELECT r.code FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.code="ticket.file.oic_authorization" AND r.code NOT LIKE "T15\_%" ORDER BY r.code')->fetchAll(),'code');
check('granted to ADL and SC only (deny by default)',$granted===['ADL','SC']);
$type=row('SELECT code,routing_profile FROM ticket_types WHERE code="OIC_AUTHORIZATION"');
check('type OIC_AUTHORIZATION is HR_ONLY',($type['routing_profile']??'')==='HR_ONLY');
check('the registry defines employee, branch, reason, start and end date',array_keys(TicketTypes::definition('OIC_AUTHORIZATION')['fields'])===['employee','branch','reason','start_date','end_date']);

// ---- Fixtures
$areaA=insert('INSERT INTO areas(code,name) VALUES(?,?)',[$tag.'_A','T15 Oic Area A']);$areaB=insert('INSERT INTO areas(code,name) VALUES(?,?)',[$tag.'_B','T15 Oic Area B']);
db()->prepare('UPDATE branches SET area_id=? WHERE id=?')->execute([$areaA,$f['branch']]);
$brA2=insert('INSERT INTO branches(code,name,site_type,area_id) VALUES(?,?,"BRANCH",?)',[$tag.'_A2','T15 Oic Branch A2',$areaA]);
$brB=insert('INSERT INTO branches(code,name,site_type,area_id) VALUES(?,?,"BRANCH",?)',[$tag.'_BB','T15 Oic Branch B',$areaB]);
$scPos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$tag.'_SC','Sales Captain']);
$oicPos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$tag.'_OIC','Officer In Charge']);
$adlPos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$tag.'_ADL','Area Development Leader']);
$baPos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$tag.'_BA','Beauty Advisor']);
$sc=who($tag,'sc','SC',$f['branch'],$f,$scPos);
$scB=who($tag,'scb','SC',$brB,$f,$scPos);
$adl=who($tag,'adl','ADL',$f['ho'],$f,$adlPos);
db()->prepare('UPDATE employees SET directory_role="ADL",directory_area_ids=? WHERE id=?')->execute([','.$areaA.',',$adl['employee']]);
$n1=who($tag,'n1','EMPLOYEE',$f['branch'],$f,$baPos);          // nominee in the Sales Captain's branch
$nA2=who($tag,'na2','EMPLOYEE',$brA2,$f,$baPos);               // nominee in another branch of area A
$nB=who($tag,'nb','EMPLOYEE',$brB,$f,$baPos);                  // nominee in area B
$nHo=who($tag,'nho','EMPLOYEE',$f['ho'],$f,$baPos);            // Head Office employee
$plain=who($tag,'plain','EMPLOYEE',$f['branch'],$f,$baPos);    // ordinary employee: may not file this type
$hrFixture=$f['hr'];
db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code="ticket.file_for_others"')->execute([$hrFixture['role_id']]);Auth::clearPermissionCache();
$hr2=['id'=>insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$hrFixture['role_id'],'Slice15 Oic HR2',strtolower($tag).'_hr2@example.test',password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]),'role_id'=>$hrFixture['role_id'],'role_code'=>$hrFixture['role_code'],'role_portal'=>'hr','name'=>'Slice15 Oic HR2','role_name'=>'HR2','email'=>strtolower($tag).'_hr2@example.test','client_id'=>null];

// ---- Who may file
act($sc['user']);check('a Sales Captain can file it and sees it in the type list',TicketTypes::canFile('OIC_AUTHORIZATION')&&in_array('OIC_AUTHORIZATION',array_column(TicketRepository::types(),'code'),true));
act($adl['user']);check('an ADL can file it',TicketTypes::canFile('OIC_AUTHORIZATION'));
act($plain['user']);check('an ordinary employee cannot file it (not listed, create denied)',!TicketTypes::canFile('OIC_AUTHORIZATION')&&!in_array('OIC_AUTHORIZATION',array_column(TicketRepository::types(),'code'),true)&&rejects(fn()=>TicketService::create(oform(['employee'=>$n1['employee'],'branch'=>$f['branch'],'reason'=>'x','start_date'=>$day(0),'end_date'=>$day(1)]),true)));
act($hrFixture);check('HR/Admin can file it for others',TicketTypes::canFile('OIC_AUTHORIZATION'));
act($f['manager']);check('a Manager role without the permission cannot file it',!TicketTypes::canFile('OIC_AUTHORIZATION'));

// ---- Who can be nominated
act($sc['user']);$choices=TicketTypes::employeeChoices('OIC_AUTHORIZATION');
check('Sales Captain choices: own branch only, not themselves',isset($choices[$n1['employee']])&&!isset($choices[$nA2['employee']])&&!isset($choices[$nB['employee']])&&!isset($choices[$nHo['employee']])&&!isset($choices[$sc['employee']]));
act($adl['user']);$choices=TicketTypes::employeeChoices('OIC_AUTHORIZATION');
check('ADL choices: every branch of the assigned area, nothing outside it',isset($choices[$n1['employee']])&&isset($choices[$nA2['employee']])&&!isset($choices[$nB['employee']])&&!isset($choices[$nHo['employee']]));
act($hrFixture);$choices=TicketTypes::employeeChoices('OIC_AUTHORIZATION');
check('HR choices: every retail employee, never Head Office',isset($choices[$n1['employee']])&&isset($choices[$nB['employee']])&&!isset($choices[$nHo['employee']]));

// ---- Filing and validation
$t1=file_oic($sc['user'],$n1,$f['branch'],$day(0),$day(4));
$tk=row('SELECT status,subject_employee_id,requester_user_id,branch_id FROM tickets WHERE id=?',[$t1]);
check('Sales Captain files for a same-branch employee: routed to HR only',$tk['status']==='PENDING_HR'&&(int)$tk['subject_employee_id']===$n1['employee']&&(int)$tk['requester_user_id']===$sc['user']['id']&&(int)$tk['branch_id']===$f['branch']);
$st=db()->prepare('SELECT approver_type FROM ticket_steps WHERE ticket_id=? ORDER BY step_no');$st->execute([$t1]);check('the only routing step is HR',$st->fetchAll(PDO::FETCH_COLUMN)===['HR']);
$a=authRow($t1);check('the authorization is saved as REQUESTED with the dates',$a['status']==='REQUESTED'&&$a['start_date']===$day(0)&&$a['end_date']===$day(4)&&(int)$a['employee_id']===$n1['employee']&&(int)$a['branch_id']===$f['branch']&&$a['previous_role_id']===null);
$bad=[
 'nominee from another branch (Sales Captain)'=>fn()=>file_oic($sc['user'],$nA2,$brA2,$day(0),$day(2)),
 'nominee from another area (Sales Captain)'=>fn()=>file_oic($sc['user'],$nB,$brB,$day(0),$day(2)),
 'Head Office nominee'=>fn()=>file_oic($sc['user'],$nHo,$f['ho'],$day(0),$day(2)),
 'filer nominating themselves'=>fn()=>file_oic($sc['user'],$sc,$f['branch'],$day(0),$day(2)),
 'branch that is not the nominee\'s branch'=>fn()=>file_oic($sc['user'],$n1,$brB,$day(10),$day(12)),
 'end before start'=>fn()=>file_oic($sc['user'],$n1,$f['branch'],$day(20),$day(19)),
 'end date in the past'=>fn()=>file_oic($sc['user'],$n1,$f['branch'],$day(-5),$day(-1)),
 'unreal date'=>fn()=>file_oic($sc['user'],$n1,$f['branch'],'2026-02-31',$day(30)),
 'empty reason'=>fn()=>file_oic($sc['user'],$n1,$f['branch'],$day(30),$day(31),true,['reason'=>'  ']),
 'overlapping authorization for the same employee'=>fn()=>file_oic($sc['user'],$n1,$f['branch'],$day(2),$day(6)),
 'ADL nominating outside the assigned area'=>fn()=>file_oic($adl['user'],$nB,$brB,$day(0),$day(2)),
];
foreach($bad as $name=>$fn)check('rejected: '.$name,rejects($fn));
check('none of the rejected attempts left a ticket or authorization behind',(int)row('SELECT COUNT(*) c FROM oic_authorizations WHERE employee_id IN (?,?,?,?)',[$nA2['employee'],$nB['employee'],$nHo['employee'],$sc['employee']])['c']===0&&(int)row('SELECT COUNT(*) c FROM oic_authorizations WHERE employee_id=?',[$n1['employee']])['c']===1);
$tA=file_oic($adl['user'],$nA2,$brA2,$day(0),$day(3));
check('an ADL files for an employee anywhere in the assigned area',(string)row('SELECT status FROM tickets WHERE id=?',[$tA])['status']==='PENDING_HR');
$tH=file_oic($hrFixture,$nB,$brB,$day(1),$day(5));
check('HR files for someone in any retail branch',(string)row('SELECT status FROM tickets WHERE id=?',[$tH])['status']==='PENDING_HR');
// Higher roles are blocked
$higher=['a Team Coordinator account'=>fn()=>asRole(who($tag,'h_tc','EMPLOYEE',$f['branch'],$f,$baPos),'TC'),
 'an HR account'=>fn()=>asRole(who($tag,'h_hr','EMPLOYEE',$f['branch'],$f,$baPos),'HR_USER'),
 'a Super Admin account'=>fn()=>asRole(who($tag,'h_sa','EMPLOYEE',$f['branch'],$f,$baPos),'SUPER_ADMIN'),
 'an ADL account'=>fn()=>asRole(who($tag,'h_adl','EMPLOYEE',$f['branch'],$f,$baPos),'ADL'),
 'a Manager account'=>fn()=>asRole(who($tag,'h_mgr','EMPLOYEE',$f['branch'],$f,$baPos),'MANAGER'),
 'an account already on the OIC role'=>fn()=>asRole(who($tag,'h_oic','EMPLOYEE',$f['branch'],$f,$baPos),'OIC')];
foreach($higher as $name=>$mk){$w=$mk();check('blocked: nominee with '.$name,rejects(fn()=>file_oic($sc['user'],$w,$f['branch'],$day(0),$day(2))));}
$hSc=who($tag,'h_sc','EMPLOYEE',$f['branch'],$f,$scPos,false);$hAdl=who($tag,'h_adl2','EMPLOYEE',$f['branch'],$f,$adlPos,false);$hOic=who($tag,'h_oic2','EMPLOYEE',$f['branch'],$f,$oicPos,false);
check('blocked: nominee who is a Sales Captain, ADL or OIC by position',rejects(fn()=>file_oic($sc['user'],$hSc,$f['branch'],$day(0),$day(2)))&&rejects(fn()=>file_oic($sc['user'],$hAdl,$f['branch'],$day(0),$day(2)))&&rejects(fn()=>file_oic($sc['user'],$hOic,$f['branch'],$day(0),$day(2))));
// Forged POST: a nominee id the filer could not pick, and a filer without the permission
act($sc['user']);check('forged nominee id from another branch is denied server-side',rejects(fn()=>TicketService::create(oform(['employee'=>$nB['employee'],'branch'=>$brB,'reason'=>'forged','start_date'=>$day(0),'end_date'=>$day(2)]),true)));
act($plain['user']);check('forged filing by an ordinary employee is denied',rejects(fn()=>TicketService::create(oform(['employee'=>$plain['employee'],'branch'=>$f['branch'],'reason'=>'forged','start_date'=>$day(0),'end_date'=>$day(2)]),true)));
$dr=file_oic($sc['user'],who($tag,'draftn','EMPLOYEE',$f['branch'],$f,$baPos),$f['branch'],$day(40),$day(41),false);
check('a draft can be saved',(string)row('SELECT status FROM tickets WHERE id=?',[$dr])['status']==='DRAFT'&&authRow($dr)['status']==='REQUESTED');

// ---- Only HR serves it; viewers and the filer cannot
act($sc['user']);$ver=(int)TicketRepository::find($t1)['version'];
foreach(['COMPLETE','START','APPROVE','RETURN','REJECT'] as $action)check('the filing Sales Captain cannot '.$action.' their own request',rejects(fn()=>TicketService::act($t1,$ver,$action,'x')));
act($adl['user']);check('a covering ADL reads the request but cannot act',sees($adl['user'],$t1)&&rejects(fn()=>TicketService::act($t1,$ver,'COMPLETE','x'))&&rejects(fn()=>TicketService::comment($t1,'x')));
act($hrFixture);check('HR who filed a request cannot serve it themselves',rejects(fn()=>TicketService::act($tH,(int)TicketRepository::find($tH)['version'],'COMPLETE','x')));

// ---- Serving: re-checks and previous role
db()->prepare('UPDATE employees SET branch_id=? WHERE id=?')->execute([$brA2,$n1['employee']]);
act($hr2);check('serving is refused if the nominee moved branch',rejects(fn()=>TicketService::act($t1,(int)TicketRepository::find($t1)['version'],'COMPLETE','x'))&&authRow($t1)['status']==='REQUESTED');
db()->prepare('UPDATE employees SET branch_id=? WHERE id=?')->execute([$f['branch'],$n1['employee']]);
$n1Tc=asRole($n1,'TC');act($hr2);
check('serving is refused if the nominee now holds a higher role',rejects(fn()=>TicketService::act($t1,(int)TicketRepository::find($t1)['version'],'COMPLETE','x'))&&authRow($t1)['status']==='REQUESTED');
$n1=asRole($n1,'EMPLOYEE');
db()->prepare('UPDATE oic_authorizations SET start_date=?,end_date=? WHERE ticket_id=?')->execute([$day(-3),$day(-1),$t1]);act($hr2);
check('serving is refused once the period has ended',rejects(fn()=>TicketService::act($t1,(int)TicketRepository::find($t1)['version'],'COMPLETE','x'))&&authRow($t1)['status']==='REQUESTED');
db()->prepare('UPDATE oic_authorizations SET start_date=?,end_date=? WHERE ticket_id=?')->execute([$day(0),$day(4),$t1]);
serve($hr2,$t1);$a=authRow($t1);
check('HR serves it: ticket Served, authorization APPROVED',(string)row('SELECT status FROM tickets WHERE id=?',[$t1])['status']==='COMPLETED'&&$a['status']==='APPROVED'&&(int)$a['approved_by']===$hr2['id']&&$a['approved_at']!==null);
check('the previous role (Employee) and account are recorded at approval time',(int)$a['previous_role_id']===roleId('EMPLOYEE')&&(int)$a['previous_user_id']===$n1['user']['id']);
check('serving changed no user or role (HR does that by hand)',roleOf($n1['user']['id'])==='EMPLOYEE');
check('serving is audited and leaves a manual-step effect',row('SELECT id FROM audit_logs WHERE action="OIC_AUTHORIZATION_SERVED" AND record_id=?',[$n1['employee']])!==[]&&row('SELECT id FROM ticket_effects WHERE ticket_id=? AND effect_type="OIC_AUTHORIZATION"',[$t1])!==[]);
check('serving twice does not change the record',(function()use($t1,$hr2){$before=authRow($t1);try{serve($hr2,$t1);}catch(Throwable){}return authRow($t1)==$before;})());
ob_start();act($hr2);ticket_detail_page(TicketRepository::detail($t1));$html=ob_get_clean();
check('the detail page shows the dates, the previous role and the manual HR step',str_contains($html,'data-oic-panel')&&str_contains($html,$day(4))&&str_contains($html,'Role before this authorization')&&str_contains($html,'Users'));
ob_start();act($sc['user']);$types=TicketRepository::types();$ty=array_values(array_filter($types,fn($x)=>$x['code']==='OIC_AUTHORIZATION'))[0];ticket_service_new(EmployeeApproverAssignmentService::employee($sc['employee']),$types,$ty,bin2hex(random_bytes(32)),[]);$html=ob_get_clean();
check('the filing form lists own-branch nominees only and has start/end date inputs',str_contains($html,$n1['no'])&&!str_contains($html,$nB['no'])&&!str_contains($html,$nHo['no'])&&str_contains($html,'name="start_date"')&&str_contains($html,'name="end_date"')&&str_contains($html,'type="date"'));

// ---- Dated OIC access (HR has now given the role by hand)
$n1=asRole($n1,'OIC');
$subj=who($tag,'subj','EMPLOYEE',$f['branch'],$f,$baPos);$spare=function()use($subj):int{act($subj['user']);return TicketService::create(['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code="ATM_REQUEST"')['id'],'submission_key'=>bin2hex(random_bytes(32)),'reason'=>'oic spare ticket'],true);};
$tSubj=$spare();
check('during the window the OIC (role OIC + served authorization) reads the branch tickets, read-only',sees($n1['user'],$tSubj)&&TicketRepository::find($tSubj)['viewer_only']===true&&rejects(fn()=>TicketService::comment($tSubj,'x')));
check('the OIC does not see other branches',!sees($n1['user'],$tA)&&!sees($n1['user'],$tH));
db()->prepare('UPDATE oic_authorizations SET start_date=?,end_date=? WHERE ticket_id=?')->execute([$day(-5),$day(-1),$t1]);
check('the day after the end date the OIC loses access at once, before any job has run',!sees($n1['user'],$tSubj));
check('...while still holding the OIC role until the job runs',roleOf($n1['user']['id'])==='OIC');

// ---- Expiry
db()->prepare('INSERT INTO user_sessions(user_id,session_token_hash) VALUES(?,?)')->execute([$n1['user']['id'],hash('sha256',random_bytes(8))]);
check('an approved authorization past its end date is reported as due',TicketOicAuthorization::dueExists());
$r=TicketOicAuthorization::runDue();
check('runDue expired one authorization and restored one role',$r===['expired'=>1,'restored'=>1]);
$a=authRow($t1);
check('the account is back on its previous role (Employee)',roleOf($n1['user']['id'])==='EMPLOYEE'&&$a['status']==='EXPIRED'&&(int)$a['restored_role_id']===roleId('EMPLOYEE')&&$a['expired_at']!==null);
check('the expiry is audited with the role it came from and went to',(function()use($n1){$r=row('SELECT details_json FROM audit_logs WHERE action="OIC_AUTHORIZATION_EXPIRED" AND record_id=? ORDER BY id DESC LIMIT 1',[$n1['employee']]);$d=$r?json_decode($r['details_json'],true):[];return ($d['from_role_id']??0)===roleId('OIC')&&($d['restored_role_id']??0)===roleId('EMPLOYEE');})());
check('the account\'s sessions were revoked so the new role applies',(int)row('SELECT COUNT(*) c FROM user_sessions WHERE user_id=? AND revoked_at IS NULL',[$n1['user']['id']])['c']===0);
check('running it again changes nothing (idempotent)',TicketOicAuthorization::expireDue()===['expired'=>0,'restored'=>0]&&TicketOicAuthorization::runDue()===null&&roleOf($n1['user']['id'])==='EMPLOYEE'&&authRow($t1)==$a);
check('a former OIC cannot see team tickets any more',!sees($n1['user'],$tSubj));

// ---- Expiry edge cases
$mk=function(string $key,array $over=[])use($tag,$f,$baPos,$sc,$day,$hr2){
    $w=who($tag,$key,'EMPLOYEE',$f['branch'],$f,$baPos);$tid=file_oic($sc['user'],$w,$f['branch'],$day(0),$day(3));serve($hr2,$tid);return [$w,$tid];
};
$end=fn(int $ticket,int $from=-6,int $to=-1)=>db()->prepare('UPDATE oic_authorizations SET start_date=?,end_date=? WHERE ticket_id=?')->execute([date('Y-m-d',strtotime("$from days")),date('Y-m-d',strtotime("$to days")),$ticket]);
// B: HR already moved the account to another role
[$wB,$tB]=$mk('xb');db()->prepare('UPDATE users SET role_id=? WHERE id=?')->execute([roleId('PAYROLL'),$wB['user']['id']]);$end($tB);
// C: a corrupt previous role pointing at Super Admin must never be restored
[$wC,$tC]=$mk('xc');db()->prepare('UPDATE users SET role_id=? WHERE id=?')->execute([roleId('OIC'),$wC['user']['id']]);db()->prepare('UPDATE oic_authorizations SET previous_role_id=? WHERE ticket_id=?')->execute([roleId('SUPER_ADMIN'),$tC]);$end($tC);
// D: previous role belongs to a different account than the one now linked
[$wD,$tD]=$mk('xd');db()->prepare('UPDATE users SET role_id=? WHERE id=?')->execute([roleId('OIC'),$wD['user']['id']]);db()->prepare('UPDATE oic_authorizations SET previous_role_id=?,previous_user_id=? WHERE ticket_id=?')->execute([roleId('MANAGER'),$sc['user']['id'],$tD]);$end($tD);
// E: nominee had no account when served; HR created one later on the OIC role
$wE=who($tag,'xe','EMPLOYEE',$f['branch'],$f,$baPos,false);$tE=file_oic($sc['user'],$wE,$f['branch'],$day(0),$day(3));serve($hr2,$tE);
$uE=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[roleId('OIC'),'Slice15 Oic xe',strtolower($tag).'_xe@example.test',password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);db()->prepare('UPDATE employees SET user_id=? WHERE id=?')->execute([$uE,$wE['employee']]);$end($tE);
// F: a permanent OIC by position keeps the role
$wF=who($tag,'xf','OIC',$f['branch'],$f,$oicPos);db()->prepare('INSERT INTO oic_authorizations(ticket_id,employee_id,branch_id,start_date,end_date,status,previous_role_id,previous_user_id) VALUES(?,?,?,?,?,"APPROVED",?,?)')->execute([$tF=$spare(),$wF['employee'],$f['branch'],$day(-6),$day(-1),roleId('EMPLOYEE'),$wF['user']['id']]);
// G: HR / admin accounts are never touched even with an expired row
$wG=who($tag,'xg','HR_USER',$f['branch'],$f,$baPos);db()->prepare('INSERT INTO oic_authorizations(ticket_id,employee_id,branch_id,start_date,end_date,status,previous_role_id,previous_user_id) VALUES(?,?,?,?,?,"APPROVED",?,?)')->execute([$tG=$spare(),$wG['employee'],$f['branch'],$day(-6),$day(-1),roleId('EMPLOYEE'),$wG['user']['id']]);
// H: two authorizations for one employee; the first ends, the second is still running
[$wH,$tH1]=$mk('xh');
$tH2=file_oic($sc['user'],$wH,$f['branch'],$day(6),$day(8));serve($hr2,$tH2);db()->prepare('UPDATE users SET role_id=? WHERE id=?')->execute([roleId('OIC'),$wH['user']['id']]);$end($tH1,-6,-1);db()->prepare('UPDATE oic_authorizations SET start_date=?,end_date=? WHERE ticket_id=?')->execute([$day(-1),$day(3),$tH2]);
$r=TicketOicAuthorization::expireDue();
check('edge cases processed in one idempotent run',$r['expired']===7&&$r['restored']===3);
check('B: an account HR already moved to another role is left alone',roleOf($wB['user']['id'])==='PAYROLL'&&authRow($tB)['status']==='EXPIRED'&&authRow($tB)['restored_role_id']===null);
check('C: a corrupt previous role is never restored; the account returns to Employee',roleOf($wC['user']['id'])==='EMPLOYEE'&&roleOf($wC['user']['id'])!=='SUPER_ADMIN');
check('D: a previous role recorded for a different account is not applied',roleOf($wD['user']['id'])==='EMPLOYEE');
check('E: an account created after serving returns to Employee',roleOf($uE)==='EMPLOYEE'&&authRow($tE)['status']==='EXPIRED');
check('F: a permanent OIC by position keeps the OIC role',roleOf($wF['user']['id'])==='OIC'&&authRow($tF)['status']==='EXPIRED');
check('G: an HR account is never touched',roleOf($wG['user']['id'])==='HR_USER'&&authRow($tG)['status']==='EXPIRED');
check('H: a second running authorization keeps the OIC role',roleOf($wH['user']['id'])==='OIC'&&authRow($tH1)['status']==='EXPIRED'&&authRow($tH2)['status']==='APPROVED');
check('no Super Admin or HR account changed role in any case',roleOf($wG['user']['id'])==='HR_USER'&&(int)row('SELECT COUNT(*) c FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code="SUPER_ADMIN" AND u.id IN (?,?,?)',[$wC['user']['id'],$wD['user']['id'],$wB['user']['id']])['c']===0);
check('a second run is a no-op',TicketOicAuthorization::expireDue()===['expired'=>0,'restored'=>0]);
// Returned request can be revised by the filer, but not for another nominee
act($sc['user']);$tR=file_oic($sc['user'],who($tag,'ret','EMPLOYEE',$f['branch'],$f,$baPos),$f['branch'],$day(50),$day(52));
$retNominee=(int)row('SELECT subject_employee_id s FROM tickets WHERE id=?',[$tR])['s'];act($hr2);TicketService::act($tR,(int)TicketRepository::find($tR)['version'],'RETURN','please fix dates');
act($sc['user']);$tkR=TicketRepository::find($tR);
check('the filer can edit a returned request and the dates update',(function()use($tR,$tkR,$retNominee,$f,$day){TicketService::edit($tR,(int)$tkR['version'],oform(['ticket_type_id'=>(int)$tkR['ticket_type_id'],'employee'=>$retNominee,'branch'=>$f['branch'],'reason'=>'fixed','start_date'=>$day(51),'end_date'=>$day(53)]),true);return authRow($tR)['start_date']===$day(51);})());
act($sc['user']);$tkR=TicketRepository::find($tR);act($hr2);TicketService::act($tR,(int)$tkR['version'],'RETURN','again');act($sc['user']);$tkR=TicketRepository::find($tR);
check('the nominee cannot be changed when editing',rejects(fn()=>TicketService::edit($tR,(int)$tkR['version'],oform(['ticket_type_id'=>(int)$tkR['ticket_type_id'],'employee'=>$n1['employee'],'branch'=>$f['branch'],'reason'=>'swap','start_date'=>$day(51),'end_date'=>$day(53)]),true)));
// Other types keep working: an ATM request is filed exactly as before
act($sc['user']);check('other request types are unchanged for the same filer',(function()use($sc){$id=TicketService::create(['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code="ATM_REQUEST"')['id'],'submission_key'=>bin2hex(random_bytes(32)),'reason'=>'still works'],true);return (string)row('SELECT status FROM tickets WHERE id=?',[$id])['status']==='PENDING_HR';})());
finish();
