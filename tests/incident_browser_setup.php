<?php
declare(strict_types=1);
// Seeds synthetic fixtures for incident_browser.cjs in the isolated database. Never run on the configured database.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketService.php';
$f=fixtures();$tag=$f['tag'];
function mk(string $tag,string $key,string $name,array $perms,string $portal,array $f,bool $employee=true,string $status='ACTIVE'):array{
    $role=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,?)',[$tag.'_b_'.$key,'Slice15 '.$key,$portal]);
    foreach($perms as $c){$p=row('SELECT id FROM permissions WHERE code=?',[$c]);if($p)db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);}
    $email=strtolower($tag.'_b_'.$key).'@example.test';
    $id=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,?)',[$role,$name,$email,password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT),$status]);
    if($employee)insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,branch_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,?,"ACTIVE",?)',[$tag.'_b_'.$key,'Synthetic',$key,$id,$f['branch'],$f['legal'],$f['company'],'2020-01-01']);
    return ['id'=>$id,'role_id'=>$role,'role_code'=>$tag.'_b_'.$key,'role_portal'=>$portal,'name'=>$name,'role_name'=>'Test '.$key,'email'=>$email,'client_id'=>null];
}
function form(array $extra=[]):array{return array_replace(incident_fields('INCIDENT_REPORT'),['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code="INCIDENT_REPORT"')['id'],'submission_key'=>bin2hex(random_bytes(32)),'title'=>'Fixture case','explanation'=>'Synthetic narrative for the browser check.','location'=>'Fixture office'],$extra);}
$filePerms=['tickets.request_self','ticket.file.incident'];
$f['cc']=mk($tag,'Ccperson','Cora Ccperson',$filePerms,'employee',$f);
$f['involved']=mk($tag,'Involvedperson','Ivan Involvedperson',$filePerms,'employee',$f);
$f['target']=mk($tag,'Searchtarget','Sasha Searchtarget',$filePerms,'employee',$f);
$f['target2']=mk($tag,'Searchtargetb','Sasha Searchtargetb',$filePerms,'employee',$f,false);
$f['inactive']=mk($tag,'Searchinactive','Sasha Searchinactive',$filePerms,'employee',$f,true,'INACTIVE');
$f['noemp']=mk($tag,'Noemployee','Nora Noemployee',$filePerms,'employee',$f,false);
$f['hr2']=mk($tag,'Hrtwo','Hana Hrtwo',['tickets.review_hr'],'hr',$f);
// I3 Case page fixtures: their own people, so the I2 list checks keep their exact expectations.
$f['rita']=mk($tag,'Ritareporter','Rita Ritareporter',$filePerms,'employee',$f);
$f['carl']=mk($tag,'Carlcc','Carl Carlcc',$filePerms,'employee',$f);
$f['ina']=mk($tag,'Inainvolved','Ina Inainvolved',$filePerms,'employee',$f);
$f['lena']=mk($tag,'Lenalatepick','Lena Lenalatepick',$filePerms,'employee',$f);
$f['viewer']=mk($tag,'Vivviewer','Viv Vivviewer',['ticket.view_team','tickets.request_self','ticket.file.incident'],'employee',$f);
$f['sens']=mk($tag,'Sensholder','Sam Sensholder',['ticket.sensitive.view','tickets.request_self'],'employee',$f);
act($f['rita']);
$f['caseD']=TicketService::create(form(['title'=>'Flow case for the page','participants'=>[['user_id'=>$f['carl']['id'],'role'=>'CC'],['user_id'=>$f['ina']['id'],'role'=>'INVOLVED']]]),true);
$f['caseE']=TicketService::create(form(['title'=>'Case to withdraw']),true);
act($f['employee']);
$f['caseA']=TicketService::create(form(['title'=>'Fixture case with a CC','participants'=>[['user_id'=>$f['cc']['id'],'role'=>'CC'],['user_id'=>$f['involved']['id'],'role'=>'INVOLVED']]]),true);
$f['caseB']=TicketService::create(form(['title'=>'Fixture case, reporter only','category'=>'Safety and health']),true);
act($f['outsider']);
$f['caseC']=TicketService::create(form(['title'=>'Someone else\'s private case']),true);
act($f['hr']);
TicketService::incidentStatus($f['caseA'],(int)row('SELECT version FROM tickets WHERE id=?',[$f['caseA']])['version'],'UNDER_REVIEW','HR is looking into this.');
$f['irType']=(int)row('SELECT id FROM ticket_types WHERE code="INCIDENT_REPORT"')['id'];
file_put_contents(dirname(__DIR__).'/storage/slice15-private/incident-fixtures.json',json_encode($f,JSON_THROW_ON_ERROR));
echo "PASS incident fixtures created\n";
