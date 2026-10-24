<?php
declare(strict_types=1);
// HR Request Queue: tabs, search, filters, counts, age, uniform summary, CSV, quick and bulk actions.
// Run: HRIS_TEST_DB=hris_slice15_test_YYYYMMDD_HHMMSS php tests/hr_queue_bulk_test.php   (isolated database only)
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/TicketModule.php';
function need_db():void{db();}
$f=fixtures();
$hr=$f['hr'];
function form(string $code,array $extra=[]):array{return array_replace(['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'],'submission_key'=>bin2hex(random_bytes(32))],$extra);}
function file_as(array $user,string $code,array $extra):int{act($user);return TicketService::create(form($code,$extra),true);}
function raw(array $f,string $code,string $status,array $payload=[],?string $filed=null,?string $closed=null,?int $requester=null):int
{
    $emp=row('SELECT * FROM employees WHERE id=?',[$f['requester']]);
    $id=insert('INSERT INTO tickets(ticket_no,ticket_type_id,requester_user_id,subject_employee_id,company_id,branch_id,company_group_snapshot,company_name_snapshot,business_unit_id_snapshot,status,payload_json,filed_at,closed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
        ['HR-RAW-'.strtoupper(bin2hex(random_bytes(6))),row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'],$requester??$f['employee']['id'],$f['requester'],$emp['legal_entity_id'],$emp['branch_id'],'SW','Slice 15 Test Company',$f['company'],$status,json_encode($payload+['employee_name'=>'Raw Person']),$filed??date('Y-m-d H:i:s'),$closed]);
    return $id;
}
function events(int $id):array{$q=db()->prepare('SELECT event_type,from_status,to_status,details_json,actor_user_id FROM ticket_events WHERE ticket_id=? ORDER BY id');$q->execute([$id]);return $q->fetchAll();}
function status(int $id):string{return (string)row('SELECT status FROM tickets WHERE id=?',[$id])['status'];}
function ver(int $id):int{return (int)row('SELECT version FROM tickets WHERE id=?',[$id])['version'];}
function items(int ...$ids):array{$o=[];foreach($ids as $id)$o[$id]=ver($id);return $o;}
function queue_f(array $o=[]):array{return TicketQueue::filters($o);}
/** Walk the pages (25 per page) until the ticket shows up, so older data in a shared test copy cannot hide it. */
function found(array $filters,int $id):bool{for($n=1;$n<=60;$n++){$pg=TicketQueue::page(queue_f($filters+['p'=>$n]));if(in_array($id,ids($pg)))return true;if(!$pg['has_next'])return false;}return false;}
function ids(array $page):array{return array_map('intval',array_column($page['rows'],'id'));}

// ---------------------------------------------------------------- real tickets
$atm=[];$idr=[];$uni=[];
foreach(range(1,3) as $i)$atm[$i]=file_as($f['employee'],'ATM_REQUEST',['reason'=>'Lost ATM card number '.$i]);
foreach(range(1,2) as $i)$idr[$i]=file_as($f['employee'],'ID_REQUEST',['reason'=>'Replacement ID '.$i]);
$uni[1]=file_as($f['employee'],'UNIFORM_REQUEST',['position'=>'Sales Crew','size'=>'M','company'=>'OFJI','branch'=>(string)$f['branch'],'quantity'=>'2','id_lace'=>'YES']);
$uni[2]=file_as($f['employee'],'UNIFORM_REQUEST',['position'=>'Sales Crew','size'=>'M','company'=>'OFJI','branch'=>(string)$f['branch'],'quantity'=>'3','id_lace'=>'NO']);
$uni[3]=file_as($f['employee'],'UNIFORM_REQUEST',['position'=>'Cashier','size'=>'L','company'=>'SW','branch'=>(string)$f['ho'],'quantity'=>'1','id_lace'=>'NO']);
$man=file_as($f['employee'],'MANPOWER_REQUEST',['section_branch'=>'Test','position_code'=>'BS','employment_type'=>'Seasonal','request_type'=>'Additional','quantity'=>'1','job_description_qualifications'=>'x','area_department'=>'x']);
$net=file_as($f['employee'],'NETSUITE_CONCERN',['concern'=>'Synthetic NetSuite concern']);
$inc=file_as($f['employee'],'INCIDENT_REPORT',['title'=>'SECRET incident title','explanation'=>'SECRET incident body']);
$pc=raw($f,'PAYROLL_CONCERN','PENDING_HR');$bt=raw($f,'BRANCH_TRANSFER','PENDING_HR');
check('fixtures filed through the real service (all Pending)',status($atm[1])==='PENDING_HR'&&status($uni[1])==='PENDING_HR'&&status($man)==='PENDING_HR');

// ---------------------------------------------------------------- permission: forged requests by non-HR
act($hr);$before=[$atm[1]=>status($atm[1]),$uni[1]=>status($uni[1])];$eventsBefore=count(events($atm[1]));
foreach(['employee','manager','adl','outsider'] as $who){
    act($f[$who]);
    check("non-HR ($who) cannot bulk process",rejects(fn()=>TicketQueue::apply(items($atm[1],$uni[1]),'SERVED','',true)));
    check("non-HR ($who) cannot quick process",rejects(fn()=>TicketQueue::apply(items($atm[1]),'START','',false)));
    check("non-HR ($who) cannot read queue, counts, export or preview",rejects(fn()=>TicketQueue::page(queue_f()))&&rejects(fn()=>TicketQueue::counts(queue_f()))&&rejects(fn()=>TicketQueue::exportRows(queue_f()))&&rejects(fn()=>TicketQueue::preview($atm[1])));
}
act($hr);check('forged calls changed nothing',status($atm[1])===$before[$atm[1]]&&status($uni[1])===$before[$uni[1]]&&count(events($atm[1]))===$eventsBefore);

// ---------------------------------------------------------------- bulk == single (same events, steps, versions)
$remarks='Shared note';
act($hr);
$bulk=TicketQueue::apply(items($atm[1]),'SERVED',$remarks,true);
$quick=TicketQueue::apply(items($atm[2]),'SERVED',$remarks,false);
TicketService::act($atm[3],ver($atm[3]),'COMPLETE',$remarks);
$sig=fn(int $id)=>[events($id),row('SELECT status,version,closed_at IS NOT NULL closed FROM tickets WHERE id=?',[$id]),db()->query('SELECT approver_type,status,remarks FROM ticket_steps WHERE ticket_id='.$id.' ORDER BY step_no')->fetchAll()];
$norm=fn(array $s)=>json_encode($s);
check('bulk, quick and direct service call give identical events, status, version and steps',$norm($sig($atm[1]))===$norm($sig($atm[2]))&&$norm($sig($atm[2]))===$norm($sig($atm[3])));
check('bulk result counts',$bulk['done']===1&&$bulk['skipped']===[]&&TicketQueue::summaryLine($bulk)==='1 served');
$last=events($atm[1]);$last=end($last);check('bulk event is the normal completion event by the HR actor with the shared remark',$last['to_status']==='COMPLETED'&&(int)$last['actor_user_id']===$hr['id']&&str_contains($last['details_json'],$remarks));
check('audit rows written for bulk and quick',(int)row('SELECT COUNT(*) n FROM audit_logs WHERE module="Tickets" AND action="BULK_SERVED"')['n']>=1&&(int)row('SELECT COUNT(*) n FROM audit_logs WHERE module="Tickets" AND action="QUICK_SERVED"')['n']>=1);

// ---------------------------------------------------------------- bulk batch with mixed types and statuses
$stale=file_as($f['employee'],'ATM_REQUEST',['reason'=>'Stale version test']);act($hr);$staleItems=[$stale=>ver($stale)];TicketService::act($stale,ver($stale),'START');// version moves on after the list was drawn
TicketQueue::apply(items($idr[2]),'START','',false);
$mixed=[$idr[1]=>ver($idr[1]),$idr[2]=>ver($idr[2]),$uni[1]=>ver($uni[1]),$uni[2]=>ver($uni[2])]+items($man,$net,$inc,$pc,$bt,$atm[1])+$staleItems;
$r=TicketQueue::apply($mixed,'SERVED','',true);
$reasons=array_column($r['skipped'],'reason','ticket_no');
check('ATM, ID and Uniform Pending/In Progress are served (2 ID incl. In Progress, 2 Uniform)',$r['done']===4&&status($idr[1])==='COMPLETED'&&status($idr[2])==='COMPLETED'&&status($uni[1])==='COMPLETED'&&status($uni[2])==='COMPLETED');
check('Manpower, NetSuite, Incident, Payroll Concern, Branch Transfer are skipped with a reason and unchanged',
    count($r['skipped'])===7&&status($man)==='PENDING_HR'&&status($net)==='PENDING_HR'&&status($inc)==='SUBMITTED'&&status($pc)==='PENDING_HR'&&status($bt)==='PENDING_HR'
    &&str_contains($reasons[row('SELECT ticket_no FROM tickets WHERE id=?',[$man])['ticket_no']],'one by one')&&str_contains($reasons[row('SELECT ticket_no FROM tickets WHERE id=?',[$pc])['ticket_no']],'HR outcome')
    &&str_contains($reasons[row('SELECT ticket_no FROM tickets WHERE id=?',[$bt])['ticket_no']],'approver')&&str_contains($reasons[row('SELECT ticket_no FROM tickets WHERE id=?',[$inc])['ticket_no']],'Incident Feed'));
check('already-served ticket is skipped, not re-served',str_contains($reasons[row('SELECT ticket_no FROM tickets WHERE id=?',[$atm[1]])['ticket_no']],'Already served'));
check('stale version is skipped and left In Progress',str_contains($reasons[row('SELECT ticket_no FROM tickets WHERE id=?',[$stale])['ticket_no']]??'','Changed since')&&status($stale)==='IN_PROGRESS');
check('summary line reads "4 served, 7 skipped"',TicketQueue::summaryLine($r)==='4 served, 7 skipped');
check('skip reasons never carry incident content',!str_contains(json_encode($r['skipped']),'SECRET'));

// ---------------------------------------------------------------- bulk cancel, START not bulkable, limits
$c1=file_as($f['employee'],'ATM_REQUEST',['reason'=>'Cancel me']);$c2=file_as($f['employee'],'ID_REQUEST',['reason'=>'Cancel me too']);act($hr);TicketService::act($c2,ver($c2),'START');
$r=TicketQueue::apply(items($c1,$c2),'CANCEL','Duplicate',true);
check('bulk cancel works for Pending and In Progress',$r['done']===2&&status($c1)==='CANCELLED'&&status($c2)==='CANCELLED'&&TicketQueue::summaryLine($r)==='2 cancelled');
check('START is not a bulk action',rejects(fn()=>TicketQueue::apply(items($atm[1]),'START','',true)));
check('unknown action, empty selection, too many, long remarks rejected',rejects(fn()=>TicketQueue::apply(items($atm[1]),'DELETE','',true))&&rejects(fn()=>TicketQueue::apply([],'SERVED','',true))
    &&rejects(fn()=>TicketQueue::apply(array_fill(1,TicketQueue::MAX_BULK+1,1),'SERVED','',true))&&rejects(fn()=>TicketQueue::apply(items($atm[1]),'SERVED',str_repeat('x',501),true)));
$q1=file_as($f['employee'],'ATM_REQUEST',['reason'=>'Quick start']);act($hr);
$r=TicketQueue::apply(items($q1),'START','',false);$r2=TicketQueue::apply(items($q1),'START','',false);$r3=TicketQueue::apply(items($man),'START','',false);
check('quick In Progress works once, second try skipped; non-simple type refused',status($q1)==='IN_PROGRESS'&&$r['done']===1&&$r2['done']===0&&str_contains($r2['skipped'][0]['reason'],'Already In Progress')&&$r3['done']===0&&status($man)==='PENDING_HR');
$own=['requester_user_id'=>$hr['id'],'subject_user_id'=>0,'type_code'=>'ATM_REQUEST','status'=>'PENDING_HR'];
check('no quick actions on your own request or on non-simple types',TicketQueue::actionsFor($own,$hr['id'])===[]&&TicketQueue::actionsFor(['type_code'=>'MANPOWER_REQUEST','status'=>'PENDING_HR','requester_user_id'=>1,'subject_user_id'=>1],$hr['id'])===[]
    &&TicketQueue::actionsFor(['type_code'=>'ATM_REQUEST','status'=>'PENDING_HR','requester_user_id'=>1,'subject_user_id'=>1],$hr['id'])===['START','SERVED','CANCEL']);

// ---------------------------------------------------------------- filters, tabs, counts, search, sort, age
act($hr);
$all=queue_f();$counts=TicketQueue::counts($all);
$db=fn(string $where,array $a=[])=>(int)row('SELECT COUNT(*) n FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code<>"INCIDENT_REPORT" AND t.status<>"DRAFT"'.$where,$a)['n'];
check('tab counts equal the database',$counts['all']===$db('')&&$counts['pending']===$db(' AND t.status="PENDING_HR"')&&$counts['progress']===$db(' AND t.status="IN_PROGRESS"')&&$counts['served']===$db(' AND t.status="COMPLETED"')&&$counts['cancelled']===$db(' AND t.status="CANCELLED"'));
check('Incident Reports are in no count and no page',!in_array($inc,ids(TicketQueue::page(queue_f())))&&$counts['all']===$db(''));
$page=TicketQueue::page(queue_f(['tab'=>'pending']));
check('Pending tab lists exactly the Pending tickets',count($page['rows'])===min(25,$counts['pending'])&&!array_diff(array_unique(array_column($page['rows'],'status')),['PENDING_HR']));
check('tab Served/Cancelled/In progress lists match',count(TicketQueue::page(queue_f(['tab'=>'served']))['rows'])===min(25,$counts['served'])&&count(TicketQueue::page(queue_f(['tab'=>'cancelled']))['rows'])===min(25,$counts['cancelled'])&&count(TicketQueue::page(queue_f(['tab'=>'progress']))['rows'])===min(25,$counts['progress']));
check('legacy ?status=COMPLETED maps to the Served tab',queue_f(['status'=>'COMPLETED'])['tab']==='served');
$no=row('SELECT ticket_no FROM tickets WHERE id=?',[$man])['ticket_no'];
check('search by ticket number',ids(TicketQueue::page(queue_f(['q'=>$no])))===[$man]);
check('search by employee name (first, last and "Last, First")',found(['q'=>'Test requester'],$man)&&found(['q'=>'requester'],$man)&&found(['q'=>'Test requester, Slice15'],$man)&&found(['q'=>'Slice15 Test requester'],$man));
check('search by branch',found(['q'=>'Slice15 Test Head Office'],$man));
$literal=true;foreach(['%','_'] as $ch)foreach(TicketQueue::page(queue_f(['q'=>$ch]))['rows'] as $t)if(!str_contains($t['ticket_no'].' '.$t['first_name'].' '.$t['last_name'].' '.$t['branch_name'],$ch))$literal=false;
check('LIKE wildcards in the search box are literal (a % or _ only matches a real % or _)',$literal&&TicketQueue::page(queue_f(['q'=>'%_%zz']))['rows']===[]);
check('type filter',!array_diff(array_unique(array_column(TicketQueue::page(queue_f(['type_code'=>'UNIFORM_REQUEST']))['rows'],'type_code')),['UNIFORM_REQUEST']));
check('type filter counts match',TicketQueue::counts(queue_f(['type_code'=>'UNIFORM_REQUEST']))['all']===$db(' AND ty.code="UNIFORM_REQUEST"'));
$of=['type_code'=>'UNIFORM_REQUEST','company_label'=>'OFJI'];check('company label filter (OFJI uniforms)',found($of,$uni[1])&&found($of,$uni[2])&&!found($of,$uni[3]));
check('uniform branch filter uses the target branch',found(['type_code'=>'UNIFORM_REQUEST','branch_id'=>$f['ho']],$uni[3])&&!found(['type_code'=>'UNIFORM_REQUEST','branch_id'=>$f['ho']],$uni[1]));
check('company filter',ids(TicketQueue::page(queue_f(['business_unit_id'=>999999])))===[]&&TicketQueue::counts(queue_f(['business_unit_id'=>999999]))['all']===0);
check('unknown filter values are dropped',queue_f(['tab'=>'x','sort'=>'x','company_label'=>"x'",'age'=>'9','closed_from'=>'nope','type_code'=>'a b'])===queue_f());

// age + sort on controlled dates
foreach([[$man,2],[$net,4],[$pc,9]] as [$id,$days])db()->prepare('UPDATE tickets SET filed_at=DATE_SUB(NOW(),INTERVAL ? DAY) WHERE id=?')->execute([$days,$id]);
$rows=[];foreach([$man,$net,$pc] as $rid)$rows[$rid]=TicketQueue::page(queue_f(['q'=>row('SELECT ticket_no FROM tickets WHERE id=?',[$rid])['ticket_no']]))['rows'][0];
check('age days and colour: 2 green, 4 amber, 9 red',$rows[$man]['age_days']===2&&$rows[$man]['age_tone']==='ok'&&$rows[$net]['age_days']===4&&$rows[$net]['age_tone']==='warn'&&$rows[$pc]['age_days']===9&&$rows[$pc]['age_tone']==='late');
check('served tickets have no age',TicketQueue::ageDays(['status'=>'COMPLETED','filed_at'=>'2020-01-01 00:00:00','created_at'=>'2020-01-01 00:00:00'])===null);
$filedOf=fn(array $pg)=>array_map(fn($t)=>(string)($t['filed_at']??$t['created_at']),$pg['rows']);
$asc=$filedOf(TicketQueue::page(queue_f(['tab'=>'pending'])));$desc=$filedOf(TicketQueue::page(queue_f(['tab'=>'pending','sort'=>'newest'])));
$sorted=$asc;sort($sorted);$rsorted=$desc;rsort($rsorted);
check('default sort is oldest first; newest sort reverses it',$asc===$sorted&&$desc===$rsorted&&queue_f()['sort']==='oldest');
check('age filter buckets',found(['tab'=>'pending','age'=>'0'],$man)&&found(['tab'=>'pending','age'=>'1'],$net)&&found(['tab'=>'pending','age'=>'2'],$pc)&&!found(['age'=>'0'],$pc)&&!found(['age'=>'1'],$man));
// served date range
db()->prepare('UPDATE tickets SET closed_at="2026-03-10 10:00:00" WHERE id=?')->execute([$idr[1]]);
check('served range filter (inclusive end day)',found(['tab'=>'served','closed_from'=>'2026-03-10','closed_to'=>'2026-03-10'],$idr[1])&&!found(['tab'=>'served','closed_from'=>'2026-03-11','closed_to'=>'2026-03-12'],$idr[1]));
check('short reason for ATM/ID, size for Uniform',TicketQueue::page(queue_f(['q'=>row('SELECT ticket_no FROM tickets WHERE id=?',[$c1])['ticket_no']]))['rows'][0]['summary']==='Cancel me'&&str_starts_with(TicketQueue::page(queue_f(['q'=>row('SELECT ticket_no FROM tickets WHERE id=?',[$uni[1]])['ticket_no']]))['rows'][0]['summary'],'Size M'));

// pagination
foreach(range(1,30) as $i)raw($f,'ATM_REQUEST','PENDING_HR',['reason'=>'bulk page '.$i],date('Y-m-d H:i:s',time()-86400*20));
$p1=TicketQueue::page(queue_f(['tab'=>'pending']));$p2=TicketQueue::page(queue_f(['tab'=>'pending','p'=>2]));
check('pagination: 25 per page, next page continues, no overlap',count($p1['rows'])===25&&$p1['has_next']&&count($p2['rows'])>=1&&!array_intersect(ids($p1),ids($p2))&&$p2['page']===2);
check('select-all scope is the page (counts still cover everything)',TicketQueue::counts(queue_f())['pending']>25);

// uniform summary
$u=TicketQueue::uniformSummary(queue_f(['type_code'=>'UNIFORM_REQUEST','tab'=>'served']));
$expected=[];foreach(db()->query('SELECT JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.size")) s,SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.quantity")) AS UNSIGNED)) q FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code="UNIFORM_REQUEST" AND t.status="COMPLETED" GROUP BY s') as $r)$expected[$r['s']]=(int)$r['q'];
check('uniform summary totals equal the data (Served tab, quantity per size)',$u['totals']['M']===($expected['M']??0)&&$u['totals']['L']===($expected['L']??0)&&$u['requests']>=2&&$u['branches'][row('SELECT name FROM branches WHERE id=?',[$f['branch']])['name']]['M']>=5);
$openQty=fn(string $size)=>(int)row('SELECT COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.quantity")) AS UNSIGNED)),0) n FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code="UNIFORM_REQUEST" AND t.status IN ("PENDING_HR","IN_PROGRESS") AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.size"))=?',[$size])['n'];
$o=TicketQueue::uniformSummary(queue_f());$uOpen=file_as($f['employee'],'UNIFORM_REQUEST',['position'=>'Sales Crew','size'=>'XL','company'=>'SW','branch'=>(string)$f['branch'],'quantity'=>'4','id_lace'=>'NO']);act($hr);
$o2=TicketQueue::uniformSummary(queue_f());
check('All tab summary counts only open requests (served ones excluded) and updates with new ones',$o['totals']['M']===$openQty('M')&&$o['totals']['L']===$openQty('L')&&$o2['totals']['XL']===$o['totals']['XL']+4&&$o2['totals']['XL']===$openQty('XL')&&str_contains($o2['scope'],'open'));
check('summary is empty for another type filter',TicketQueue::uniformSummary(queue_f(['type_code'=>'ATM_REQUEST']))['requests']===0);

// CSV
$rows=TicketQueue::exportRows(queue_f(['type_code'=>'ATM_REQUEST']));$flat=json_encode($rows);
check('CSV has the header width and one row per ATM ticket',$rows&&count($rows[0])===count(TicketQueue::EXPORT_HEADER)&&count($rows)===TicketQueue::counts(queue_f(['type_code'=>'ATM_REQUEST']))['all']);
check('CSV carries no reason text and no incident data',!str_contains($flat,'Lost ATM card')&&!str_contains($flat,'bulk page')&&!str_contains(json_encode(TicketQueue::exportRows(queue_f())),'SECRET'));
check('CSV neutralises formula cells',TicketQueue::csvCell('=1+1')==="'=1+1"&&TicketQueue::csvCell('+1')==="'+1"&&TicketQueue::csvCell('@x')==="'@x"&&TicketQueue::csvCell('Juan Cruz')==='Juan Cruz');

// preview
$pv=TicketQueue::preview($atm[1]);check('preview returns the ticket and steps; Incident refused',$pv['ticket_no']===row('SELECT ticket_no FROM tickets WHERE id=?',[$atm[1]])['ticket_no']&&isset($pv['steps'])&&rejects(fn()=>TicketQueue::preview($inc)));
ob_start();ticket_queue_preview(TicketQueue::preview($uni[3]),queue_f());$html=ob_get_clean();
check('preview fragment shows employee, branch, size and the open-full-request link',str_contains($html,'Slice15')&&str_contains($html,'Cashier')&&str_contains($html,'Open full request')&&!str_contains($html,'password'));

// ---------------------------------------------------------------- real pages (rendered as HR) and the real handler
ob_start();$fl=queue_f(['tab'=>'pending']);ticket_queue_page($fl,TicketQueue::page($fl),TicketQueue::counts($fl),TicketQueue::uniformSummary($fl),['done'=>1,'skipped'=>[['ticket_no'=>'X','type'=>'ATM Request','reason'=>'Because']],'verb'=>'served','requested'=>2]);$html=ob_get_clean();
check('queue page renders tabs, table, bulk bar, dialogs, drawer, result summary',str_contains($html,'hq-tabs')&&str_contains($html,'hq-table')&&str_contains($html,'data-bulkbar')&&str_contains($html,'hq-bulk-dialog')&&str_contains($html,'hq-drawer')&&str_contains($html,'1 served, 1 skipped')&&str_contains($html,'Export CSV'));
check('every row has a labelled checkbox that carries the version seen on screen',(bool)preg_match('/name="items\[\d+\]" value="\d+" aria-label="Select HR-/',$html));
check('page has no PHP warnings or notices in its output',!preg_match('/(Warning|Notice|Deprecated|Fatal error)\s*:/',$html));
ob_start();$fe=queue_f(['q'=>'zzzz-no-match']);ticket_queue_page($fe,TicketQueue::page($fe),TicketQueue::counts($fe),TicketQueue::uniformSummary($fe),null);$html=ob_get_clean();
check('empty state when nothing matches (with a clear-filters link)',str_contains($html,'No requests match these filters')&&str_contains($html,'Clear filters')&&!preg_match('/(Warning|Notice|Deprecated|Fatal error)\s*:/',$html));

function chk(string $name,bool $ok,string $out):void{check($name,$ok);if(!$ok)echo '--- child output ---'.PHP_EOL.substr($out,0,1800).PHP_EOL;}
function child(int $uid,string $method,string $page,array $get,array $post,bool $csrf=true):string{
    $cmd=[PHP_BINARY,__DIR__.'/hr_queue_handler_run.php',(string)$uid,$method,$page,json_encode($get),json_encode($post)];if(!$csrf)$cmd[]='no-csrf';
    $p=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),['HRIS_TEST_DB'=>getenv('HRIS_TEST_DB'),'SystemRoot'=>getenv('SystemRoot')?:'C:\\Windows','PATH'=>getenv('PATH')]);
    $out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);proc_close($p);return $out;
}
$h1=file_as($f['employee'],'ID_REQUEST',['reason'=>'Handler test one']);$h2=file_as($f['employee'],'UNIFORM_REQUEST',['position'=>'Sales Crew','size'=>'S','company'=>'SW','branch'=>(string)$f['branch'],'quantity'=>'1','id_lace'=>'NO']);act($hr);
$post=['action'=>'core_ticket_bulk','bulk_action'=>'SERVED','items'=>[$h1=>ver($h1),$h2=>ver($h2)],'back'=>'tab=pending&type_code=ID_REQUEST','remarks'=>'via handler'];
$out=child($f['employee']['id'],'POST','hr-service-requests',[],$post);
chk('real handler: forged bulk POST by a non-HR account is refused and nothing changes',status($h1)==='PENDING_HR'&&status($h2)==='PENDING_HR'&&str_contains($out,'HTTP=302')&&!str_contains($out,'"done":2'),$out);
$out=child($hr['id'],'POST','hr-service-requests',[],$post,false);
chk('real handler: missing CSRF token gives 419 and changes nothing',str_contains($out,'HTTP=419')&&status($h1)==='PENDING_HR',$out);
$out=child($f['manager']['id'],'GET','hr-request-preview',['id'=>$h1],[]);$csv=child($f['manager']['id'],'GET','hr-service-requests-export',[],[]);
chk('real handler: non-HR preview and export are 403',str_contains($out,'HTTP=403')&&str_contains($csv,'HTTP=403')&&!str_contains($csv,'Ticket no'),$out);
$out=child($hr['id'],'POST','hr-service-requests',[],$post);
parse_str('tab=pending&type_code=ID_REQUEST&evil=1&p=3',$backQuery);check('return link keeps only whitelisted filters (no page, no unknown keys)',http_build_query(TicketQueue::linkParams(TicketQueue::filters($backQuery)))==='tab=pending&type_code=ID_REQUEST');
chk('real handler: HR bulk POST processes both through the service and redirects back (302)',status($h1)==='COMPLETED'&&status($h2)==='COMPLETED'&&str_contains($out,'HTTP=302')&&str_contains($out,'"done":2'),$out);
$out=child($hr['id'],'GET','hr-service-requests',['tab'=>'served'],[]);
chk('real handler: queue page returns 200 with no PHP warnings',str_contains($out,'HTTP=200')&&str_contains($out,'hq-table')&&!preg_match('/(Warning|Notice|Deprecated|Fatal error)\s*:/',$out),$out);
$out=child($hr['id'],'GET','hr-service-requests-export',['type_code'=>'ID_REQUEST'],[]);
chk('real handler: CSV export streams a header and rows',str_contains($out,'"Ticket no",Type,Employee')&&str_contains($out,'ID Request')&&!str_contains($out,'Handler test one'),$out);
$out=child($hr['id'],'GET','hr-request-preview',['id'=>$h2],[]);check('real handler: preview fragment returns 200',str_contains($out,'HTTP=200')&&str_contains($out,'hq-drawer-head'));
$out=child($hr['id'],'GET','hr-request-preview',['id'=>$inc],[]);check('real handler: Incident preview refused even for HR',str_contains($out,'HTTP=403')&&!str_contains($out,'SECRET'));
finish();
