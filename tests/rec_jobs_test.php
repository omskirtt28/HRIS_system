<?php
declare(strict_types=1);
// rec-jobs (SPEC-rec-jobs.md). Synthetic data only; run with HRIS_TEST_DB on a scratch copy (no migration is needed).
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/JobOpenings.php';
$f=fixtures();
$sfx=strtr(bin2hex(random_bytes(3)),'0123456789','ghijklmnop');
foreach(['recruitment.view','recruitment.manage'] as $code){db()->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code=?')->execute([$f['hr']['role_id'],$code]);}
$client=insert('INSERT INTO clients(code,name) VALUES(?,?)',['ZJ'.$sfx,'Zzj '.$sfx.' Trading']);
function approved_request(int $client,int $qty=3,int $filled=0,string $status='APPROVED'): int {
    return insert('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status,notes) VALUES(?,?,?,?,?,"FULL_TIME","NORMAL",?,?)',
        ['MR-ZJ-'.bin2hex(random_bytes(4)),$client,'Synthetic clerk',$qty,$filled,$status,'Synthetic qualifications']);
}
function job(int $id): array { return row('SELECT * FROM job_openings WHERE id=?',[$id]); }
function form(array $extra=[]): array { return $extra+['title'=>'Synthetic Sales Clerk','description'=>'Sell things.','requirements'=>"Line one\nLine two",'location_text'=>'Synthetic branch','employment_type'=>'REGULAR','openings'=>'2']; }
function visible_ids(): array { return array_map('intval',array_column(db()->query('SELECT j.id FROM job_openings j WHERE '.JobOpenings::PUBLIC_WHERE)->fetchAll(),'id')); }

// --- permission ------------------------------------------------------------------------------------------
act($f['employee']);
$req=approved_request($client);
check('permission: a non-HR user cannot create a job',rejects(fn()=>JobOpenings::save(form(['manpower_request_id'=>$req]))));
check('permission: a non-HR user cannot publish, close or edit',rejects(fn()=>JobOpenings::publish(1))&&rejects(fn()=>JobOpenings::close(1))&&rejects(fn()=>JobOpenings::save(form(),1)));
act($f['hr']);

// --- create from an approved request ----------------------------------------------------------------------
$d=JobOpenings::defaultsFor($req);
check('prefill: title, employer and employment type come from the request; a ticketless request has no description to copy',$d['title']==='Synthetic clerk'&&(int)$d['client_id']===$client&&$d['employment_type']==='REGULAR'&&$d['description']==='');
act($f['employee']);
$tid=TicketService::create(array_replace(incident_fields('MANPOWER_REQUEST'),['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code="MANPOWER_REQUEST"')['id'],'submission_key'=>bin2hex(random_bytes(32)),'section_branch'=>'Synthetic section','position_code'=>'SC','employment_type'=>'Probationary','request_type'=>'Additional','quantity'=>'3','job_description_qualifications'=>'Synthetic qualifications','area_department'=>'Synthetic department']),true);
act($f['hr']);$tmr=ManpowerIntake::approve($tid,(int)row('SELECT version FROM tickets WHERE id=?',[$tid])['version'],$client);$td=JobOpenings::defaultsFor($tmr)['description'];
check('prefill: a ticket request copies only the qualifications, never the ticket number, section or department',$td==='Synthetic qualifications'&&!str_contains($td,'#')&&!str_contains($td,'Synthetic section')&&!str_contains($td,'Synthetic department'));
check('prefill: openings = requested - filled - jobs already open, never below 1',(int)$d['openings']===3&&(int)JobOpenings::defaultsFor(approved_request($client,3,3,'PARTIALLY_FILLED'))['openings']===1);
$mrBefore=(int)row('SELECT COUNT(*) c FROM manpower_requests')['c'];
$j1=JobOpenings::save(form(['manpower_request_id'=>$req]));$a=job($j1);
check('create: a new job is a Draft, not public, linked to the request and its employer',$a['status']==='DRAFT'&&(int)$a['manpower_request_id']===$req&&(int)$a['client_id']===$client&&!in_array($j1,visible_ids(),true)&&$a['published_at']===null);
check('create: code and slug are built from the row id and are unique',preg_match('/^JOB-'.date('Y').'-\d{5}$/',$a['job_code'])===1&&str_ends_with($a['slug'],'-'.(int)$j1)&&str_contains($a['slug'],'synthetic-sales-clerk'));
check('create: the request row is not changed and gets no extra rows',(int)row('SELECT COUNT(*) c FROM manpower_requests')['c']===$mrBefore);
$over=JobOpenings::save(form(['manpower_request_id'=>$req,'openings'=>'9']));
check('create: more openings than the remaining headcount is allowed but reported as a warning',JobOpenings::overBy($over)>0&&JobOpenings::overBy($j1)>=0);
foreach(['REJECTED','FILLED','SUBMITTED'] as $bad)check('create: a '.$bad.' request cannot be posted',rejects(fn()=>JobOpenings::save(form(['manpower_request_id'=>approved_request($client,1,0,$bad)]))));
check('create: unknown request id refused',rejects(fn()=>JobOpenings::save(form(['manpower_request_id'=>999999999]))));
check('create: the employer comes from the request, a posted client_id is ignored',(int)job(JobOpenings::save(form(['manpower_request_id'=>$req,'client_id'=>$client+99999])))['client_id']===$client);

// --- standalone (D5) ----------------------------------------------------------------------------------------
$before=(int)row('SELECT COUNT(*) c FROM manpower_requests')['c'];
$j2=JobOpenings::save(form(['client_id'=>$client,'title'=>'Walk-in vacancy']));$b=job($j2);$m=row('SELECT * FROM manpower_requests WHERE id=?',[$b['manpower_request_id']]);
check('standalone: creates exactly one APPROVED bridge request with no ticket',(int)row('SELECT COUNT(*) c FROM manpower_requests')['c']===$before+1&&$m['status']==='APPROVED'&&$m['ticket_id']===null&&(int)$m['client_id']===$client);
check('standalone: bridge request carries the title, headcount, approver and a unique request number',$m['position_title']==='Walk-in vacancy'&&(int)$m['requested_headcount']===2&&(int)$m['approved_by']===$f['hr']['id']&&preg_match('/^MR-\d{8}-H\d+$/',$m['request_no'])===1);
check('standalone: a posted employer must exist',rejects(fn()=>JobOpenings::save(form(['client_id'=>999999999])))&&(int)row('SELECT COUNT(*) c FROM manpower_requests')['c']===$before+1);
check('standalone: a failed save leaves no bridge row behind',rejects(fn()=>JobOpenings::save(form(['client_id'=>$client,'title'=>''])))&&(int)row('SELECT COUNT(*) c FROM manpower_requests')['c']===$before+1);
check('standalone: with no employer posted the job belongs to PMBSI',row('SELECT c.name n FROM job_openings j JOIN clients c ON c.id=j.client_id WHERE j.id=?',[JobOpenings::save(form())])['n']==='PMBSI');
check('type: the eight employment types are accepted and an unknown one is refused',(function() use($client){foreach(array_keys(JobOpenings::TYPES) as $t)JobOpenings::save(form(['employment_type'=>$t,'client_id'=>$client]));return rejects(fn()=>JobOpenings::save(form(['employment_type'=>'FULL_TIME','client_id'=>$client])));})());

// --- validation -------------------------------------------------------------------------------------------
$bad=fn(array $x)=>rejects(fn()=>JobOpenings::save(form($x+['client_id'=>$client])));
check('validate: title and description are required, title up to 180 characters',$bad(['title'=>' '])&&$bad(['description'=>''])&&$bad(['title'=>str_repeat('x',181)]));
check('validate: employment type must be known, openings between 1 and 999',$bad(['employment_type'=>'FOREVER'])&&$bad(['openings'=>'0'])&&$bad(['openings'=>'1000'])&&$bad(['openings'=>'abc']));
check('validate: salary is optional but a range must be ordered and non-negative',!$bad(['salary_min'=>'','salary_max'=>''])&&$bad(['salary_min'=>'30000','salary_max'=>'20000'])&&$bad(['salary_min'=>'-1']));
check('validate: closing date must be a real date and not in the past',$bad(['closes_on'=>'not a date'])&&$bad(['closes_on'=>date('Y-m-d',strtotime('-1 day'))]));
check('validate: an unknown department is refused',$bad(['department_id'=>'999999999']));

// --- state machine ----------------------------------------------------------------------------------------
JobOpenings::publish($j1);$p=job($j1);
check('publish: Draft becomes Published, published_at set, visible to the public',$p['status']==='PUBLISHED'&&$p['published_at']!==null&&in_array($j1,visible_ids(),true));
check('publish: publishing again is refused and published_at does not move',rejects(fn()=>JobOpenings::publish($j1))&&job($j1)['published_at']===$p['published_at']);
JobOpenings::unpublish($j1);JobOpenings::publish($j1);
check('unpublish: back to Draft and hidden; republishing keeps the first published_at',job($j1)['published_at']===$p['published_at']&&in_array($j1,visible_ids(),true));
JobOpenings::unpublish($j1);
check('unpublish: hidden from the public',!in_array($j1,visible_ids(),true)&&job($j1)['status']==='DRAFT');
check('unpublish: a Draft cannot be unpublished again',rejects(fn()=>JobOpenings::unpublish($j1)));
JobOpenings::publish($j1);JobOpenings::close($j1);
check('close: Closed job is hidden and cannot be published or edited',job($j1)['status']==='CLOSED'&&!in_array($j1,visible_ids(),true)&&rejects(fn()=>JobOpenings::publish($j1))&&rejects(fn()=>JobOpenings::save(form(),$j1)));
JobOpenings::reopen($j1);
check('reopen: Closed goes back to Draft only',job($j1)['status']==='DRAFT'&&rejects(fn()=>JobOpenings::reopen($j1)));
JobOpenings::close($j1);
check('close: a Draft can be closed too',job($j1)['status']==='CLOSED');
check('state: an unknown id is refused for every action',rejects(fn()=>JobOpenings::publish(999999999))&&rejects(fn()=>JobOpenings::close(999999999)));

// --- edit --------------------------------------------------------------------------------------------------
JobOpenings::reopen($j1);$slug=job($j1)['slug'];$code=job($j1)['job_code'];
JobOpenings::save(form(['title'=>'Renamed role','openings'=>'4','salary_min'=>'20000','salary_max'=>'25000','is_featured'=>'1','manpower_request_id'=>999999999,'client_id'=>$client+1]),$j1);$e=job($j1);
check('edit: fields change, salary and featured saved',$e['title']==='Renamed role'&&(int)$e['openings']===4&&(float)$e['salary_min']===20000.0&&(int)$e['is_featured']===1);
check('edit: code, slug, request and employer never change',$e['slug']===$slug&&$e['job_code']===$code&&(int)$e['manpower_request_id']===$req&&(int)$e['client_id']===$client);
JobOpenings::save(form(['salary_min'=>'','salary_max'=>'']),$j1);
check('edit: salary can be cleared again',job($j1)['salary_min']===null&&job($j1)['salary_max']===null);

// --- expiry ------------------------------------------------------------------------------------------------
JobOpenings::publish($j1);
$setClose=fn(?string $v)=>db()->prepare('UPDATE job_openings SET closes_at=? WHERE id=?')->execute([$v,$j1]);
$setClose(date('Y-m-d 23:59:59',strtotime('-1 day')));
check('expiry: a job that closed yesterday is hidden but stays Published for HR (shown as Expired)',!in_array($j1,visible_ids(),true)&&job($j1)['status']==='PUBLISHED'&&JobOpenings::displayStatus(job($j1))==='EXPIRED');
$setClose(date('Y-m-d 23:59:59'));
check('expiry: a job that closes today is still visible',in_array($j1,visible_ids(),true)&&JobOpenings::displayStatus(job($j1))==='PUBLISHED');
$setClose(null);
check('expiry: no closing date means no expiry',in_array($j1,visible_ids(),true));
JobOpenings::save(form(['closes_on'=>date('Y-m-d',strtotime('+3 days'))]),$j1);
check('expiry: a closing date from the form is stored as the end of that day',substr((string)job($j1)['closes_at'],11)==='23:59:59');

// --- list --------------------------------------------------------------------------------------------------
db()->prepare('UPDATE job_openings SET closes_at=NULL WHERE id=?')->execute([$j1]);
$ids=fn(string $filter,string $q='')=>array_map('intval',array_column(JobOpenings::listJobs($filter,$q),'id'));
check('list: filters by status and searches the title',in_array($j1,$ids('published'),true)&&!in_array($j1,$ids('draft'),true)&&in_array($j2,$ids('draft'),true)&&in_array($j1,$ids('all','Sales Clerk'),true)&&!in_array($j2,$ids('all','Sales Clerk'),true));
$wasClosedBefore=in_array($j1,$ids('closed'),true);JobOpenings::close($j1);
check('list: closed jobs are only under Closed and All',!$wasClosedBefore&&in_array($j1,$ids('closed'),true)&&in_array($j1,$ids('all'),true));
check('list: HR sees the real employer, with the application count',(function() use($j2,$client){foreach(JobOpenings::listJobs('all','') as $r)if((int)$r['id']===$j2)return $r['employer']==='Zzj '.$GLOBALS['sfx'].' Trading'&&(int)$r['applications']===0;return false;})());
check('audit: create, publish and close are written to the audit log',(int)row('SELECT COUNT(*) c FROM audit_logs WHERE module="Recruitment" AND action IN ("CREATE_JOB","PUBLISH_JOB","CLOSE_JOB") AND record_id=?',[$j1])['c']>=3);
check('no delete: the service has no delete method and every job row still exists',!method_exists('JobOpenings','delete')&&(int)row('SELECT COUNT(*) c FROM job_openings WHERE id IN (?,?)',[$j1,$j2])['c']===2);
// --- public readers share the one rule (Careers, job page, Apply) ------------------------------------------
require_once dirname(__DIR__).'/app/RecruitmentRepository.php';
$pub=JobOpenings::save(form(['manpower_request_id'=>$req,'title'=>'Zzj public check '.$sfx]));JobOpenings::publish($pub);$ps=job($pub)['slug'];
$seen=fn()=>[RecruitmentRepository::jobById($pub)!==null,RecruitmentRepository::jobBySlug($ps)!==null,in_array($pub,array_map(fn($r)=>(int)$r['id'],RecruitmentRepository::publishedJobs()),true)];
check('public: a live job is found by id, by slug and in the Careers list',$seen()===[true,true,true]);
db()->prepare('UPDATE job_openings SET closes_at=? WHERE id=?')->execute([date('Y-m-d 23:59:59',strtotime('-1 day')),$pub]);
check('public: an expired job is refused everywhere, so Apply cannot take an application for it',$seen()===[false,false,false]);
db()->prepare('UPDATE job_openings SET closes_at=NULL WHERE id=?')->execute([$pub]);JobOpenings::close($pub);
check('public: a closed job is refused everywhere',$seen()===[false,false,false]);
check('public: the employer name is never returned, and searching for it finds nothing',(function() use($pub,$sfx){JobOpenings::reopen($pub);JobOpenings::publish($pub);$all=RecruitmentRepository::publishedJobs();$byName=RecruitmentRepository::publishedJobs('Zzj '.$sfx.' Trading');return $all&&array_unique(array_column($all,'client_name'))===['PMBSI']&&$byName===[];})());

// --- a new employer typed on the walk-in form is created only when the job saves ---------------------------
$cc=fn()=>(int)row('SELECT COUNT(*) c FROM clients')['c'];$c0=$cc();
check('walk-in: a refused save does not leave the typed employer behind',rejects(fn()=>JobOpenings::save(form(['new_employer'=>'Zzj '.$sfx.' Fresh','title'=>''])))&&$cc()===$c0);
$w1=JobOpenings::save(form(['new_employer'=>' zzj '.$sfx.' fresh ']));$w2=JobOpenings::save(form(['new_employer'=>'Zzj '.$sfx.' Fresh']));
check('walk-in: a saved job creates the employer once and later jobs reuse it',$cc()===$c0+1&&(int)job($w1)['client_id']===(int)job($w2)['client_id']);
finish();
