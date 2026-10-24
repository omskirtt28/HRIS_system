<?php
declare(strict_types=1);
// Incident Reports, employee screens (I2): people search, My Incidents list, filing list, categories, navigation. Isolated database only.
// The handler, CSRF and the generic-action refusal are exercised in a real browser by incident_browser.cjs.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/TicketServiceViews.php';
$f=fixtures();$tag=$f['tag'];
function mk(string $tag,string $key,string $name,array $perms,string $portal,array $f,bool $employee=true,string $status='ACTIVE'):array{
    $role=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,?)',[$tag.'_u_'.$key,'Slice15 '.$key,$portal]);
    foreach($perms as $c){$p=row('SELECT id FROM permissions WHERE code=?',[$c]);if($p)db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);}
    $id=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,?)',[$role,$name,strtolower($tag.'_u_'.$key).'@example.test',password_hash('x',PASSWORD_DEFAULT),$status]);
    if($employee)insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,branch_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,?,"ACTIVE",?)',[$tag.'_u_'.$key,'Synthetic',$key,$id,$f['branch'],$f['legal'],$f['company'],'2020-01-01']);
    return ['id'=>$id,'role_id'=>$role,'role_code'=>$tag.'_u_'.$key,'role_portal'=>$portal,'name'=>$name,'role_name'=>'Test '.$key,'email'=>'x@example.test','client_id'=>null];
}
$filePerms=['tickets.request_self','ticket.file.incident'];
$needle='Zq'.strtolower(substr($tag,-4));                     // unlikely to collide with anything else in the database
$a=mk($tag,'a',"Anna $needle",$filePerms,'employee',$f);
$b=mk($tag,'b',"Berto $needle",$filePerms,'employee',$f,false);   // account without an Employee 201 record
$c=mk($tag,'c',"Carla $needle",$filePerms,'employee',$f,true,'INACTIVE');
$d=mk($tag,'d',"Dino $needle%_",$filePerms,'employee',$f);          // wildcard characters in a name
$viewer=mk($tag,'v',"Vera $needle",['tickets.request_self'],'employee',$f);   // cannot file incidents
$many=[];for($i=0;$i<12;$i++)$many[]=mk($tag,'m'.$i,"Mara$i $needle many",$filePerms,'employee',$f);
$ir=(int)row('SELECT id FROM ticket_types WHERE code="INCIDENT_REPORT"')['id'];
function inc(int $ir,array $extra=[]):array{return array_replace(incident_fields('INCIDENT_REPORT'),['ticket_type_id'=>$ir,'submission_key'=>bin2hex(random_bytes(32)),'title'=>'UI case','explanation'=>'SECRET narrative for the list page','location'=>'Place'],$extra);}

// ---------------------------------------------------------------- people search (BR-770)
act($f['employee']);
$names=fn(string $t)=>array_column(TicketIncident::searchPeople($t),'name');
check('test_BR_770 search needs at least 2 characters',TicketIncident::searchPeople('')===[]&&TicketIncident::searchPeople('A')===[]&&TicketIncident::searchPeople('  A ')===[]);
check('test_BR_770 search returns active accounts, with or without an employee record',in_array("Anna $needle",$names($needle),true)&&in_array("Berto $needle",$names($needle),true));
check('test_BR_770 inactive accounts never appear',!in_array("Carla $needle",$names($needle),true)&&!in_array("Carla $needle",$names('Carla')));
check('test_BR_770 at most 10 results',count($names($needle))===10&&count(TicketIncident::searchPeople("$needle many"))===10);
$row=TicketIncident::searchPeople("Anna $needle")[0];
check('test_BR_770 a result carries id, name and branch only',array_keys($row)===['id','name','branch']&&$row['branch']===(string)row('SELECT name FROM branches WHERE id=?',[$f['branch']])['name']);
check('test_BR_770 a person without an employee record has an empty branch',TicketIncident::searchPeople("Berto $needle")[0]['branch']==='');
act($a);check('test_BR_770 the signed-in user is never in their own results',!in_array("Anna $needle",$names($needle),true)&&in_array("Berto $needle",$names($needle),true));
act($f['employee']);
check('test_BR_770 LIKE wildcards in the search are literal',TicketIncident::searchPeople('%%')===[]&&TicketIncident::searchPeople('__')===[]&&count(TicketIncident::searchPeople("$needle%_"))===1);
check('test_BR_770 a very long term is cut, not an error',is_array(TicketIncident::searchPeople(str_repeat('x',500))));
$pair=TicketIncident::searchPeople("Anna $needle");check('test_BR_770 no email, role or id-card data in a result',!str_contains(json_encode($pair),'@')&&!str_contains(json_encode($pair),'role'));
check('test_BR_770 the filing permission is what gates search (canFile)',(function()use($viewer){act($viewer);return !TicketTypes::canFile('INCIDENT_REPORT');})()&&(function()use($f){act($f['employee']);return TicketTypes::canFile('INCIDENT_REPORT');})());

// ---------------------------------------------------------------- categories (Q56) and the Other rule
$labels=array_values(TicketIncident::CATEGORIES);
check('test_BR_776 six categories with stable codes',$labels===['Workplace conduct','Harassment or discrimination','Safety and health','Property or equipment','Attendance-related','Other']&&isset(TicketIncident::CATEGORIES['SAFETY'],TicketIncident::CATEGORIES['PROPERTY'],TicketIncident::CATEGORIES['HARASSMENT_DISCRIMINATION']));
check('test_BR_776 the filing form lists exactly those labels',TicketTypes::definition('INCIDENT_REPORT')['fields']['category']['options']===$labels);
check('test_BR_776 every category code fits the schema CHECK',count(array_filter(array_keys(TicketIncident::CATEGORIES),fn($k)=>preg_match('/^[A-Z_]{2,30}$/',$k)))===6);
act($f['employee']);
$id=TicketService::create(inc($ir,['category'=>'Harassment or discrimination']),true);
check('test_BR_776 a new category is stored under its code',row('SELECT category FROM incident_details WHERE ticket_id=?',[$id])['category']==='HARASSMENT_DISCRIMINATION');
check('test_BR_776 the old label "Safety" is no longer accepted',rejects(fn()=>TicketService::create(inc($ir,['category'=>'Safety']),true)));
check('test_BR_776 category Other needs an explanation of enough length',rejects(fn()=>TicketService::create(inc($ir,['category'=>'Other','explanation'=>'too short']),true))&&TicketService::create(inc($ir,['category'=>'Other','explanation'=>str_repeat('A real description. ',3)]),true)>0);

// ---------------------------------------------------------------- filing list and My Incidents
check('test_BR_720 File an HR Request no longer offers Incident Report, but the role may still file it',!in_array('INCIDENT_REPORT',array_column(TicketRepository::types(),'code'),true)&&TicketTypes::canFile('INCIDENT_REPORT'));
$mineIds=fn(string $mode)=>array_map('intval',array_column(TicketRepository::listing($mode)['rows'],'id'));
check('test_BR_769 My HR Requests excludes incident cases; My Incidents includes them',!in_array($id,$mineIds('my'),true)&&in_array($id,$mineIds('incidents_mine'),true));
$withCc=TicketService::create(inc($ir,['title'=>'Shared case','participants'=>[['user_id'=>$a['id'],'role'=>'CC'],['user_id'=>$b['id'],'role'=>'INVOLVED']]]),true);
act($a);check('test_BR_769 a CC sees the shared case in My Incidents and nothing else',$mineIds('incidents_mine')===[$withCc]);
act($b);check('test_BR_769 a picked Involved person sees nothing',$mineIds('incidents_mine')===[]);
act($viewer);check('test_BR_769 an unrelated user sees nothing, and My Incidents never errors',$mineIds('incidents_mine')===[]);
act($f['hr']);check('test_BR_769 an HR reviewer\'s My Incidents is empty unless they are on the case',$mineIds('incidents_mine')===[]);
act($f['employee']);$page=TicketRepository::listing('incidents_mine',['p'=>1],1);
check('test_BR_769 My Incidents paginates',count($page['rows'])===1&&$page['has_next']===true&&TicketRepository::listing('incidents_mine',['p'=>2],1)['rows']!==[]);
$sum=TicketIncident::summaries($mineIds('incidents_mine'));
check('test_BR_776 summaries give the category label and when, keyed by case',isset($sum[$id])&&$sum[$id]['category_label']==='Harassment or discrimination'&&TicketIncident::summaries([])===[]);
check('test_BR_776 summaries expose no case text',!str_contains(json_encode($sum),'SECRET'));
check('test_BR_770 names for picked ids skip inactive and unknown accounts',TicketIncident::namesFor([$a['id'],$c['id'],999999999,'x',0])===[$a['id']=>"Anna $needle"]);

// ---------------------------------------------------------------- tones, sidebar
check('test_BR_771 status tones: Under review and In progress blue, Resolved green, Dismissed red, Closed gray',ticket_tone('UNDER_REVIEW')==='blue'&&ticket_tone('IN_PROGRESS')==='blue'&&ticket_tone('RESOLVED')==='green'&&ticket_tone('DISMISSED')==='red'&&ticket_tone('CLOSED')==='gray'&&ticket_tone('SUBMITTED')==='gray');
check('test_BR_771 status chips use readable labels',str_contains(ticket_status_chip('UNDER_REVIEW','INCIDENT_REPORT'),'Under Review')&&!str_contains(ticket_status_chip('UNDER_REVIEW','INCIDENT_REPORT'),'UNDER_REVIEW'));
foreach(['employee','hr','admin'] as $portal){
    $_SESSION['user']=$portal==='hr'?$f['hr']:$f['employee'];Auth::clearPermissionCache();
    $cfg=nav_config($portal);$labels2=[];foreach($cfg['groups'] as [$g,$items])foreach($items as $it)$labels2[$g][]=$it[0];
    $inc=$labels2['Incident Reports']??[];
    check("test_BR_769 sidebar ($portal): group Incident Reports with My Incidents and File an Incident, no Incident Feed",in_array('My Incidents',$inc,true)&&in_array('File an Incident',$inc,true)&&!in_array('Incident Feed',$labels2['Requests']??[],true)&&!in_array('Incident Feed',$inc,true));
    check("test_BR_769 sidebar ($portal): Incident Cases only outside the employee portal",in_array('Incident Cases',$inc,true)===($portal!=='employee'));
}
// ---------------------------------------------------------------- client-portal accounts are never offered or added
$client=mk($tag,'cl',"Client $needle",$filePerms,'client',$f,false);
act($f['employee']);
check('test_BR_770 a client-portal account is not found by the search',!in_array("Client $needle",$names($needle),true)&&TicketIncident::searchPeople("Client $needle")===[]);
check('test_BR_770 a client-portal account cannot be picked as a participant or named for the form',rejects(fn()=>TicketService::create(inc($ir,['participants'=>[['user_id'=>$client['id'],'role'=>'CC']]]),true))&&TicketIncident::namesFor([$client['id']])===[]);
act($f['hr']);$caseForClient=(function()use($f,$ir){act($f['employee']);return TicketService::create(inc($ir),true);})();act($f['hr']);
check('test_BR_770 HR cannot include a client-portal account either',rejects(fn()=>TicketService::incidentAddParticipant($caseForClient,$client['id'],'CC')));
finish();
