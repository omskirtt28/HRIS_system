<?php
declare(strict_types=1);
// HR Dashboard: period windows, KPI definitions, company filter, incident privacy, zero data, real page render.
// Run: HRIS_TEST_DB=hris_slice15_test_YYYYMMDD_HHMMSS php tests/hr_dashboard_test.php   (isolated database only)
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/TicketModule.php';
require_once dirname(__DIR__).'/app/HrDashboardViews.php';
function need_db():void{db();}
$f=fixtures();$hr=$f['hr'];
function form(string $code,array $extra=[]):array{return array_replace(['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'],'submission_key'=>bin2hex(random_bytes(32))],$extra);}
function file_as(array $user,string $code,array $extra):int{act($user);return TicketService::create(form($code,$extra),true);}
function page_html(array $get):string{$_GET=$get;ob_start();hr_dashboard_page();return ob_get_clean();}
function clean(string $html):bool{return !preg_match('/(Warning|Notice|Deprecated|Fatal error)\s*:/',$html);}
$D=fn(string $s)=>new DateTimeImmutable($s);

// ---------------------------------------------------------------- windows (pure)
$w=HrDashboard::window('this_week',$D('2026-10-21'));// Wednesday
check('this week: Monday to today, previous = same stretch last week',$w['start']==='2026-10-19'&&$w['end']==='2026-10-22'&&$w['last_day']==='2026-10-21'&&$w['prev_start']==='2026-10-12'&&$w['prev_end']==='2026-10-15'&&$w['live']);
$w=HrDashboard::window('this_week',$D('2026-10-19'));check('this week on a Monday is one day long',$w['start']==='2026-10-19'&&$w['end']==='2026-10-20'&&$w['prev_start']==='2026-10-12'&&$w['prev_end']==='2026-10-13');
$w=HrDashboard::window('this_week',$D('2026-10-25'));check('this week on a Sunday is the whole week',$w['start']==='2026-10-19'&&$w['end']==='2026-10-26'&&$w['prev_end']==='2026-10-19');
$w=HrDashboard::window('last_week',$D('2026-10-21'));check('last week is Monday to Sunday, not live',$w['start']==='2026-10-12'&&$w['end']==='2026-10-19'&&$w['prev_start']==='2026-10-05'&&$w['prev_end']==='2026-10-12'&&!$w['live']);
$w=HrDashboard::window('this_month',$D('2026-10-22'));check('this month: 1st to today, previous month same length',$w['start']==='2026-10-01'&&$w['end']==='2026-10-23'&&$w['prev_start']==='2026-09-01'&&$w['prev_end']==='2026-09-23');
$w=HrDashboard::window('this_month',$D('2026-03-31'));check('this month never reaches into the current month for the previous window',$w['prev_start']==='2026-02-01'&&$w['prev_end']==='2026-03-01');
$w=HrDashboard::window('last_30',$D('2026-10-21'));check('last 30 days',$w['start']==='2026-09-22'&&$w['end']==='2026-10-22'&&$w['prev_start']==='2026-08-23'&&$w['prev_end']==='2026-09-22');
check('delta wording',HrDashboard::delta(5,3)['text']==='+2 vs previous period'&&HrDashboard::delta(1,4)['text']==='−3 vs previous period'&&HrDashboard::delta(2,2)['text']==='Same as previous period'&&HrDashboard::delta(0,0)['value']===0);
check('unknown company or period falls back to defaults',HrDashboard::params(['company'=>"x'",'period'=>'forever'])===['company'=>'ALL','period'=>'this_week']&&HrDashboard::params(['company'=>'OC','period'=>'last_30'])===['company'=>'OC','period'=>'last_30']);

// ---------------------------------------------------------------- fixtures: real tickets, controlled dates
$atm=[];foreach(range(1,3) as $i)$atm[$i]=file_as($f['employee'],'ATM_REQUEST',['reason'=>'Dash ATM '.$i]);
$idr=file_as($f['employee'],'ID_REQUEST',['reason'=>'Dash ID']);
$uni=file_as($f['employee'],'UNIFORM_REQUEST',['position'=>'Sales Crew','size'=>'M','company'=>'OFJI','branch'=>(string)$f['branch'],'quantity'=>'2','id_lace'=>'NO']);
$man=file_as($f['employee'],'MANPOWER_REQUEST',['section_branch'=>'T','position_code'=>'BS','employment_type'=>'Seasonal','request_type'=>'Additional','quantity'=>'1','job_description_qualifications'=>'x','area_department'=>'x']);
$inc=file_as($f['employee'],'INCIDENT_REPORT',['title'=>'SECRET dashboard incident','explanation'=>'SECRET body']);
act($hr);
TicketService::act($atm[3],(int)row('SELECT version FROM tickets WHERE id=?',[$atm[3]])['version'],'START');
TicketService::act($idr,(int)row('SELECT version FROM tickets WHERE id=?',[$idr])['version'],'COMPLETE');
$today=date('Y-m-d');
foreach([[$atm[1],0],[$atm[2],4],[$man,9]] as [$id,$days])db()->prepare('UPDATE tickets SET filed_at=DATE_SUB(NOW(),INTERVAL ? DAY) WHERE id=?')->execute([$days,$id]);

$p=HrDashboard::params([]);$d=HrDashboard::data($p);$t=$d['tickets'];$w=$d['window'];
$sql=fn(string $where,array $a=[])=>(int)row('SELECT COUNT(*) n FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code<>"INCIDENT_REPORT" AND t.status<>"DRAFT"'.$where,$a)['n'];
check('Pending KPI = PENDING_HR tickets, In progress = IN_PROGRESS (queue definitions)',$t['pending']===$sql(' AND t.status="PENDING_HR"')&&$t['progress']===$sql(' AND t.status="IN_PROGRESS"')&&$t['progress']>=1);
check('Served in window = COMPLETED closed in the window',$t['served']===$sql(' AND t.status="COMPLETED" AND t.closed_at>=? AND t.closed_at<?',[$w['start'].' 00:00:00',$w['end'].' 00:00:00'])&&$t['served']>=1);
check('Served in previous window equals the data',$t['served_before']===$sql(' AND t.status="COMPLETED" AND t.closed_at>=? AND t.closed_at<?',[$w['prev_start'].' 00:00:00',$w['prev_end'].' 00:00:00']));
check('pending age buckets add up to the pending count',array_sum($t['age'])===$t['pending']&&$t['age'][0]>=1&&$t['age'][1]>=1&&$t['age'][2]>=1);
check('oldest pending list is oldest first, capped at 5, with ages',count($t['oldest'])<=5&&$t['oldest'][0]['age_days']>=array_values(array_slice($t['oldest'],-1))[0]['age_days']&&$t['oldest_days']===$t['oldest'][0]['age_days']&&$t['oldest_days']>=9);
check('Requests by type: eight configured types, no Incident Report',!isset($t['types']['INCIDENT_REPORT'])&&array_keys($t['types'])===array_values(array_filter(HrDashboard::TYPE_ORDER,fn($c)=>isset($t['types'][$c]))));
$filed=$sql(' AND COALESCE(t.filed_at,t.created_at)>=? AND COALESCE(t.filed_at,t.created_at)<?',[$w['start'].' 00:00:00',$w['end'].' 00:00:00']);
check('type totals equal tickets filed in the window; status buckets add up per type',array_sum(array_column($t['types'],'total'))===$filed&&$t['filed']===$filed&&!array_filter($t['types'],fn($x)=>$x['total']!==$x['pending']+$x['progress']+$x['served']+$x['cancelled']+$x['other']));
check('ATM row shows the in-progress one and the pending ones',$t['types']['ATM_REQUEST']['progress']>=1&&$t['types']['ATM_REQUEST']['pending']>=2);
check('Incident KPI counts open Incident Reports; breakdowns never include them',$d['incident']['open']===(int)row('SELECT COUNT(*) n FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code="INCIDENT_REPORT" AND t.status="SUBMITTED"')['n']&&$d['incident']['open']>=1&&!str_contains(json_encode($t),'SECRET'));
check('latest Incident title is available to HR only through the incident block',$d['incident']['latest'][0]['title']==='SECRET dashboard incident');

// status as it was a period ago comes from the event history
// ticket_events is append-only, so move the clock instead of the rows: from a date weeks ahead, every ticket filed today was already pending a period earlier.
$ahead=new DateTimeImmutable('+40 days');$f0=HrDashboard::data($p,$ahead)['tickets'];$newPending=file_as($f['employee'],'ATM_REQUEST',['reason'=>'Dash history']);act($hr);$f1=HrDashboard::data($p,$ahead)['tickets'];$past=HrDashboard::data($p,new DateTimeImmutable('2020-06-10'))['tickets'];
check('Pending a period ago is rebuilt from ticket events (each new Pending ticket adds one when the clock is moved ahead; none exist before any ticket)',$f1['pending_before']===$f0['pending_before']+1&&$past['pending_before']===0&&$past['progress_before']===0);
$t=HrDashboard::data($p)['tickets'];// the new ticket changed the live counts

// company filter
$all=HrDashboard::data(['company'=>'ALL','period'=>'this_week']);$sw=HrDashboard::data(['company'=>'SW','period'=>'this_week']);$oc=HrDashboard::data(['company'=>'OC','period'=>'this_week']);
$byGroup=fn(string $g)=>$sql(' AND t.status="PENDING_HR" AND t.company_group_snapshot=?',[$g]);
check('company filter: SW and OC pending equal their own rows; both within All',$sw['tickets']['pending']===$byGroup('SW')&&$oc['tickets']['pending']===$byGroup('OC')&&$sw['tickets']['pending']+$oc['tickets']['pending']<=$all['tickets']['pending']);
check('company filter reaches employees and incidents',$sw['employees']['active']+$oc['employees']['active']<=$all['employees']['active']&&$oc['incident']['open']===(int)row('SELECT COUNT(*) n FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE ty.code="INCIDENT_REPORT" AND t.status="SUBMITTED" AND t.company_group_snapshot="OC"')['n']);
$active=(int)row('SELECT COUNT(*) n FROM employees WHERE status IN ("ACTIVE","PROBATIONARY","ON_LEAVE")')['n'];
check('Active employees equals the directory count; probation due uses the 30-day window',$all['employees']['active']===$active&&$all['employees']['probation_due']>=0&&$all['employees']['due_days']===30);
check('recruitment KPI reuses the existing recruitment numbers',$all['recruitment']['active']===RecruitmentRepository::dashboard()['active']&&$all['recruitment']['openReq']===RecruitmentRepository::dashboard()['openReq']);

// setup warnings = the existing unresolved-viewers report
$t0=microtime(true);$r=TicketViewerService::report(1);$ms=round((microtime(true)-$t0)*1000);
check('setup block equals the Ticket Viewers report',$all['setup']['branches_without_sc']===count($r['branches_without_sc'])&&$all['setup']['retail_missing']===$r['counts']['retail_missing']&&$all['setup']['ho_missing']===$r['counts']['ho_missing']);
echo "INFO viewer report took {$ms} ms on ".$active." active employees\n";

// ---------------------------------------------------------------- who sees what
$role=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,"hr")',[$f['tag'].'_REC','Slice15 recruiter']);
foreach(['dashboard.hr.view','recruitment.view','employees.view_all'] as $code){$pr=row('SELECT id FROM permissions WHERE code=?',[$code]);if($pr)db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$pr['id']]);}
$uid=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$role,'Slice15 Recruiter',strtolower($f['tag']).'_rec@example.test',password_hash('x',PASSWORD_DEFAULT)]);
$rec=['id'=>$uid,'role_id'=>$role,'role_code'=>$f['tag'].'_REC','role_portal'=>'hr','name'=>'Slice15 Recruiter','role_name'=>'Recruiter','email'=>'r@example.test','client_id'=>null];
act($rec);$dr=HrDashboard::data($p);$html=page_html([]);
check('HR-portal account without ticket review sees no request figures, no incidents, no setup',$dr['tickets']===null&&$dr['incident']===null&&$dr['setup']===null&&!str_contains($html,'SECRET')&&!str_contains($html,'Needs your action today')&&!str_contains($html,'Pending requests')&&clean($html));
check('that account still gets recruitment and employee KPIs',str_contains($html,'Recruitment')&&str_contains($html,'Active employees'));

// ---------------------------------------------------------------- the real page as HR
act($hr);$html=page_html([]);
check('page renders with greeting, action strip, filter bar, six KPI cards',str_contains($html,'Needs your action today')&&str_contains($html,'hd-filterbar')&&substr_count($html,'class="metric-card hd-kpi"')===6&&clean($html));
foreach(['Pending requests','In progress','Served ·','Incident Reports','Recruitment','Active employees'] as $label)check("KPI card present: $label",str_contains($html,'metric-label">'.$label)||str_contains($html,'metric-label">'.e($label)));
check('request KPIs open the filtered queue (Pending, In progress, Served this week with its date range)',
    str_contains($html,'href="index.php?page=hr-service-requests&amp;tab=pending"')&&str_contains($html,'tab=progress')&&str_contains($html,'tab=served&amp;closed_from='.$w['start'].'&amp;closed_to='.$w['last_day']));
check('charts and attention panel render: by-type bars, age bars, oldest pending, incident block, setup warnings',str_contains($html,'Requests by type')&&str_contains($html,'How long requests have waited')&&str_contains($html,'Oldest pending requests')&&str_contains($html,'Setup warnings')&&str_contains($html,'Latest Incident Feed'));
check('every bar links to the queue with its type; legend uses text labels',str_contains($html,'type_code=ATM_REQUEST')&&str_contains($html,'Other (awaiting approver')&&str_contains($html,'aria-label="ATM Request:'));
check('existing recruitment pulse, interviews and quick actions are kept',str_contains($html,'Recruitment pulse')&&str_contains($html,'pulse-bars compact')&&str_contains($html,'Today’s interviews')&&str_contains($html,'Quick actions')&&str_contains($html,'HR Request Queue'));
foreach(['this_week','last_week','this_month','last_30'] as $per)foreach(['ALL','OC','SW'] as $co){$h=page_html(['period'=>$per,'company'=>$co]);if(!clean($h)||!str_contains($h,'hd-kpis')){check("page $per/$co renders cleanly",false);}}
check('all period and company combinations render cleanly',true);
$h=page_html(['company'=>'OC','period'=>'last_30']);check('company filter keeps the queue links scoped (company_group=OC)',str_contains($h,'company_group=OC')&&str_contains($h,'selected>Ocampo'));

// ---------------------------------------------------------------- zero data: no tickets, no applications, no interviews
// Triggers keep ticket_events append-only, so empty it with TRUNCATE. This only ever runs on the throwaway copy (the support file refuses anything else).
db()->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['ticket_hr_notes','ticket_events','ticket_steps','ticket_comments','ticket_attachments','ticket_effects','ticket_submission_keys','ticket_notification_outbox','uniform_requests','tickets','interviews','applications','manpower_requests'] as $tbl)if(FoundationRepository::tableExists($tbl))db()->exec('TRUNCATE TABLE '.$tbl);
db()->exec('SET FOREIGN_KEY_CHECKS=1');
$z=HrDashboard::data($p);$html=page_html([]);$html2=page_html(['company'=>'OC','period'=>'last_week']);
check('zero data: every request figure is 0 and nothing divides by zero',$z['tickets']['pending']===0&&$z['tickets']['progress']===0&&$z['tickets']['served']===0&&$z['tickets']['oldest']===[]&&array_sum($z['tickets']['age'])===0&&$z['incident']['open']===0&&$z['recruitment']['active']===0);
check('zero data: page renders with empty states and no PHP warnings',clean($html)&&clean($html2)&&str_contains($html,'No requests were filed in this period')&&str_contains($html,'Nothing is waiting for HR')&&str_contains($html,'No pending requests')&&str_contains($html,'No interviews are scheduled today')&&str_contains($html,'No Incident Reports have been filed'));
check('the copy really was empty',(int)row('SELECT COUNT(*) n FROM tickets')['n']===0);
finish();
