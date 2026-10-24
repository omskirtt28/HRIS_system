<?php
declare(strict_types=1);
// Head Office Manager viewer: the ONLY viewer of a Head Office employee's ticket is the "Reporting To" Manager, read live.
// Regression for the live bug: Head Office is stored with an EMPTY branch code, which made resolve() return "no Manager".
// Synthetic fixtures only; run with HRIS_TEST_DB pointing at an isolated copy.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/TicketViewerService.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketViews.php';
require_once dirname(__DIR__).'/app/TicketViewerViews.php';
$f=fixtures();$tag=$f['tag'];

function perm(int $role,string $code,bool $on):void {
    $p=row('SELECT id FROM permissions WHERE code=?',[$code]);
    if($on)db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);
    else db()->prepare('DELETE FROM role_permissions WHERE role_id=? AND permission_id=?')->execute([$role,$p['id']]);
    Auth::clearPermissionCache();
}
function role(string $tag,string $key,array $perms):int {
    $id=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,"employee")',[$tag.'_'.$key,'Slice15 '.$key]);foreach($perms as $c)perm($id,$c,true);return $id;
}
/** @return array{user:?array,employee:int} an active employee at $branch, with an account on $roleId unless it is null */
function person(string $tag,string $key,?int $roleId,int $branch,array $f,?int $managerEmployee=null):array {
    $uid=null;$user=null;
    if($roleId!==null){
        $r=row('SELECT code,portal FROM roles WHERE id=?',[$roleId]);$email=strtolower($tag.'_h_'.$key).'@example.test';
        $uid=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$roleId,'Slice15 Test '.$key,$email,password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT)]);
        $user=['id'=>$uid,'role_id'=>$roleId,'role_code'=>$r['code'],'role_portal'=>$r['portal'],'name'=>'Slice15 Test '.$key,'role_name'=>'Test '.$key,'email'=>$email,'client_id'=>null];
    }
    $emp=insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,branch_id,manager_employee_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,?,?,"ACTIVE",?)',[$tag.'_h_'.$key,'Slice15','Test '.$key,$uid,$branch,$managerEmployee,$f['legal'],$f['company'],'2020-01-01']);
    return ['user'=>$user,'employee'=>$emp];
}
function sees(array $user,int $ticket):bool { act($user);try{TicketRepository::find($ticket);return true;}catch(RuntimeException){return false;} }
function listed(array $user,int $ticket):bool { act($user);foreach(TicketRepository::listing('team',[],100)['rows'] as $r)if((int)$r['id']===$ticket)return true;return false; }
function inInbox(array $user,int $ticket):bool { act($user);foreach(TicketRepository::listing('inbox',[],100)['rows'] as $r)if((int)$r['id']===$ticket)return true;return false; }
function type(string $code):int { return (int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id']; }
function file_ticket(array $user,string $code,array $extra,bool $submit=true):int { act($user);return TicketService::create(array_replace(['ticket_type_id'=>type($code),'submission_key'=>bin2hex(random_bytes(32))],$extra),$submit); }
function atm(array $user,bool $submit=true):int { return file_ticket($user,'ATM_REQUEST',['reason'=>'Synthetic HO viewer test'],$submit); }
function problems(array $v):array { return array_column($v['problems'],0); }
function cutoff(string $start,string $end):int {
    $old=row('SELECT id FROM payroll_cutoffs WHERE period_start=? AND period_end=?',[$start,$end]);
    if($old){$id=(int)$old['id'];db()->prepare('UPDATE payroll_cutoffs SET status="OPEN" WHERE id=?')->execute([$id]);}
    else $id=insert('INSERT INTO payroll_cutoffs(code,period_start,period_end,status) VALUES(?,?,?,"OPEN")',['T15-'.$start.'-'.$end,$start,$end]);
    db()->prepare('INSERT INTO payroll_cutoff_runs(cutoff_id,state,ticket_deadline) VALUES(?,"OPEN",NULL) ON DUPLICATE KEY UPDATE state="OPEN",ticket_deadline=NULL')->execute([$id]);return $id;
}

// ---- Fixtures: the live shape. Head Office with an EMPTY (NULL) code, the real MANAGER role for Charles-like Managers.
$ho=insert('INSERT INTO branches(code,name,site_type) VALUES(NULL,?,"HEAD_OFFICE")',['T15 Head Office no code '.$tag]);
check('fixture Head Office has no branch code (as on live)',row('SELECT code FROM branches WHERE id=?',[$ho])['code']===null);
$viewRole=role($tag,'viewrole',['ticket.view_team','tickets.request_self','ticket.file.atm','ticket.file.incident']);  // no payroll.approve_manager on purpose
$staffRole=role($tag,'staffrole',['tickets.request_self','payroll.request_self','ticket.file.atm','ticket.file.incident','ticket.file.payroll_concern']);
$mgrRole=(int)row('SELECT id FROM roles WHERE code="MANAGER"')['id'];   // the real MANAGER role: approve_manager + view_team
$mgrA=person($tag,'mgra',$mgrRole,$ho,$f);                                // direct Manager (Charles)
$mgrB=person($tag,'mgrb',$mgrRole,$ho,$f);                                // new Manager after a reassignment
$viewOnly=person($tag,'viewonly',$viewRole,$ho,$f);                       // may view, may not approve
$staff=person($tag,'staff',$staffRole,$ho,$f,$mgrA['employee']);          // MIS employee reporting to mgrA
$other=person($tag,'other',$staffRole,$ho,$f,$mgrB['employee']);          // reports to mgrB
$boss=person($tag,'boss',$mgrRole,$ho,$f,$mgrA['employee']);              // a Manager (role MANAGER) whose Reporting To is set anyway
$hr=$f['hr'];

check('real MANAGER role holds view_team and approve_manager',(bool)row('SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.code="ticket.view_team"',[$mgrRole])&&(bool)row('SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.code="payroll.approve_manager"',[$mgrRole]));

// ---- The failing case: before the fix this ticket was invisible to its Reporting To Manager.
$tAtm=atm($staff['user']);$tDraft=atm($staff['user'],false);$tInc=file_ticket($staff['user'],'INCIDENT_REPORT',['title'=>'T15 incident','explanation'=>'Synthetic'],true);
$tOther=atm($other['user']);
$route=EmployeeApproverAssignmentService::resolve(EmployeeApproverAssignmentService::employee($staff['employee']),date('Y-m-d'));
check('resolve() finds the Head Office Manager even though the branch code is empty',(int)$route['user_id']===$mgrA['user']['id']);
check('Manager sees the report\'s ATM ticket (detail, read-only) and lists it in Team Requests',sees($mgrA['user'],$tAtm)&&listed($mgrA['user'],$tAtm)&&TicketRepository::find($tAtm)['viewer_only']===true);
act($mgrA['user']);$v=TicketViewerService::scope($mgrA['user']['id']);
check('scope names the direct report only',$v['manager_employee_ids']===[$staff['employee']]||(count($v['manager_employee_ids'])===2&&in_array($staff['employee'],$v['manager_employee_ids'],true)&&!in_array($other['employee'],$v['manager_employee_ids'],true)));
check('Manager does not see another Manager\'s report',!sees($mgrA['user'],$tOther)&&!listed($mgrA['user'],$tOther));
check('Incident Report is hidden from the Manager (detail and list)',!sees($mgrA['user'],$tInc)&&!listed($mgrA['user'],$tInc));
check('draft is hidden from the Manager',!sees($mgrA['user'],$tDraft)&&!listed($mgrA['user'],$tDraft));
check('guessed URL: a non-viewer employee is denied',!sees($other['user'],$tAtm)&&!sees($f['outsider'],$tAtm));
check('guessed URL: HO Manager of someone else is denied',!sees($mgrB['user'],$tAtm));

// ---- Read-only: forged POSTs
act($mgrA["user"]);$ver=(int)TicketRepository::find($tAtm)['version'];$stepsBefore=(int)row('SELECT COUNT(*) c FROM ticket_steps WHERE ticket_id=?',[$tAtm])['c'];
act($mgrA['user']);
check('forged approve/complete denied',rejects(fn()=>TicketService::act($tAtm,$ver,'COMPLETE','x','NO_CHANGE'))&&rejects(fn()=>TicketService::act($tAtm,$ver,'APPROVE','x')));
check('forged comment denied',rejects(fn()=>TicketService::comment($tAtm,'x')));
$row=row('SELECT status,version FROM tickets WHERE id=?',[$tAtm]);
check('ticket unchanged after forged POSTs',$row['status']==='PENDING_HR'&&(int)$row['version']===$ver&&(int)row('SELECT COUNT(*) c FROM ticket_comments WHERE ticket_id=?',[$tAtm])['c']===0&&(int)row('SELECT COUNT(*) c FROM ticket_steps WHERE ticket_id=?',[$tAtm])['c']===$stepsBefore);

// ---- Viewing is not approving: no payroll.approve_manager and no assignment needed
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$viewOnly['employee'],$staff['employee']]);
check('a Reporting To Manager without approve permission still views (view_team is enough)',sees($viewOnly['user'],$tAtm)&&listed($viewOnly['user'],$tAtm));
perm($viewRole,'ticket.view_team',false);check('without ticket.view_team nobody views',!sees($viewOnly['user'],$tAtm));
perm($viewRole,'ticket.view_team',true);

// ---- Manager reassignment: the new Manager gets the old tickets at once, the old one loses them
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$mgrA['employee'],$staff['employee']]);
check('before reassignment: old Manager views',sees($mgrA['user'],$tAtm));
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$mgrB['employee'],$staff['employee']]);
check('after reassignment: the new Manager sees the OLD ticket (detail and list)',sees($mgrB['user'],$tAtm)&&listed($mgrB['user'],$tAtm));
check('after reassignment: the old Manager loses the ticket (detail and list)',!sees($mgrA['user'],$tAtm)&&!listed($mgrA['user'],$tAtm));
db()->prepare('UPDATE employees SET manager_employee_id=NULL WHERE id=?')->execute([$staff['employee']]);
check('no Reporting To: nobody views',!sees($mgrA['user'],$tAtm)&&!sees($mgrB['user'],$tAtm));
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$mgrA['employee'],$staff['employee']]);

// ---- Manager as filer: straight to HR, nobody above views
$tBoss=atm($boss['user']);
check('a Manager\'s own ATM request goes to HR',(string)row('SELECT status FROM tickets WHERE id=?',[$tBoss])['status']==='PENDING_HR');
check('a Manager\'s ticket has no viewer, even with Reporting To set',!sees($mgrA['user'],$tBoss)&&!listed($mgrA['user'],$tBoss));
$vb=TicketViewerService::viewersFor($boss['employee']);
check('201 for a Manager filer: not applicable, no problem line',$vb['manager_filer']===true&&!$vb['manager']&&!$vb['problems']&&str_contains(TicketViewerEmploymentLine::html($boss['employee']),'requests go straight to HR'));

// ---- Payroll Concern: a direct report goes Manager then HR (Manager inbox, not Team Requests); a Manager goes straight to HR
$cut=cutoff('2020-01-04','2020-01-18');
db()->prepare('INSERT IGNORE INTO payroll_cutoff_snapshots(cutoff_id,employee_id,work_date,snapshot_json) VALUES(?,?,?,?)')->execute([$cut,$staff['employee'],'2020-01-10','{"sentinel":"Synthetic"}']);
$pc=['concern_date'=>'2020-01-10','explanation'=>'Synthetic payroll concern'];
$tPc=file_ticket($staff['user'],'PAYROLL_CONCERN',$pc);$d=TicketRepository::detail($tPc);
check('direct report\'s Payroll Concern chain is Manager then HR (unchanged), Manager step named',array_column($d['steps'],'approver_type')===['MANAGER','HR']&&(int)$d['steps'][0]['approver_user_id']===$mgrA['user']['id']&&$d['status']==='PENDING_APPROVAL');
check('it sits in the Manager\'s HR Request Inbox',inInbox($mgrA['user'],$tPc));
db()->prepare('INSERT IGNORE INTO payroll_cutoff_snapshots(cutoff_id,employee_id,work_date,snapshot_json) VALUES(?,?,?,?)')->execute([$cut,$boss['employee'],'2020-01-10','{"sentinel":"Synthetic"}']);
perm((int)$boss['user']['role_id'],'ticket.file.payroll_concern',true);perm((int)$boss['user']['role_id'],'payroll.request_self',true);
$tBossPc=file_ticket($boss['user'],'PAYROLL_CONCERN',$pc);$d=TicketRepository::detail($tBossPc);
check('a Manager\'s Payroll Concern goes straight to HR (single HR step)',array_column($d['steps'],'approver_type')===['HR']&&$d['status']==='PENDING_HR');
check('and nobody above the Manager views it',!sees($mgrA['user'],$tBossPc)&&!inInbox($mgrA['user'],$tBossPc));
check('Manager still sees the report\'s Payroll Concern read-only after approving it',(function()use($tPc,$mgrA){act($mgrA['user']);TicketService::act($tPc,(int)TicketRepository::find($tPc)['version'],'APPROVE','Synthetic');return sees($mgrA['user'],$tPc)&&listed($mgrA['user'],$tPc);})());

// ---- Employee 201 "Who can view" panel
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$mgrA['employee'],$staff['employee']]);
$v=TicketViewerService::viewersFor($staff['employee']);$html=TicketViewerEmploymentLine::html($staff['employee']);
check('201: Head Office shows the Reporting To Manager, active, no problem',$v['head_office']&&$v['has_viewer']&&$v['manager']['can_view']&&!$v['problems']&&str_contains($html,'>Manager<')&&str_contains($html,'Test mgra'));
$noAcct=person($tag,'noacct',null,$ho,$f);db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$noAcct['employee'],$staff['employee']]);
$v=TicketViewerService::viewersFor($staff['employee']);$html=TicketViewerEmploymentLine::html($staff['employee']);
check('201: Manager with no account shows a red PROBLEM',!$v['has_viewer']&&in_array('MANAGER_NO_ACCOUNT',problems($v),true)&&str_contains($html,'data-viewer-problem="MANAGER_NO_ACCOUNT"')&&str_contains($html,'class="alert error"'));
$noPerm=person($tag,'noperm',role($tag,'noperm',['tickets.request_self']),$ho,$f);db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$noPerm['employee'],$staff['employee']]);
$v=TicketViewerService::viewersFor($staff['employee']);$html=TicketViewerEmploymentLine::html($staff['employee']);
check('201: Manager without ticket.view_team shows a red PROBLEM',!$v['has_viewer']&&in_array('MANAGER_CANNOT_VIEW',problems($v),true)&&str_contains($html,'data-viewer-problem="MANAGER_CANNOT_VIEW"'));
db()->prepare('UPDATE employees SET manager_employee_id=NULL WHERE id=?')->execute([$staff['employee']]);
$v=TicketViewerService::viewersFor($staff['employee']);
check('201: no Reporting To shows a red PROBLEM',!$v['has_viewer']&&in_array('NO_MANAGER',problems($v),true));
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$mgrA['employee'],$staff['employee']]);
$panelMatches=TicketViewerService::viewersFor($staff['employee'])['has_viewer']===sees($mgrA['user'],$tAtm);
check('201 panel agrees with what the Manager can really read',$panelMatches);

// ---- Report: a Manager filer is not flagged as "no viewer"
act($hr);$rep=TicketViewerService::report(1);
check('report does not list a Manager filer as missing a viewer',!in_array($boss['employee'],array_map(fn($e)=>(int)$e['id'],$rep['missing']),true));
finish();
