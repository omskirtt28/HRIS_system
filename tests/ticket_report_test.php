<?php
declare(strict_types=1);
// Patch 2 (migration 015): Ticketing Report. Numbers, filters, privacy, CSV, print page, zero data, speed. Synthetic fixtures only.
// Run: HRIS_TEST_DB=hris_slice15_test_YYYYMMDD_HHMMSS php tests/ticket_report_test.php   (isolated database only)
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/TicketReportViews.php';
require_once dirname(__DIR__).'/app/TicketViews.php';
function need_db():void{db();}
$f=fixtures();$hr=$f['hr'];$b=$f['branch'];$ho=$f['ho'];
function perm(int $role,string $code,bool $on=true):void{$p=row('SELECT id FROM permissions WHERE code=?',[$code]);if(!$p)throw new RuntimeException('missing '.$code);
    if($on)db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);else db()->prepare('DELETE FROM role_permissions WHERE role_id=? AND permission_id=?')->execute([$role,$p['id']]);Auth::clearPermissionCache();}
$clean=fn(string $h)=>!preg_match('/(Warning|Notice|Deprecated|Fatal error)\s*:/',$h);
$cutoff=insert('INSERT INTO payroll_cutoffs(code,period_start,period_end,status) VALUES(?,?,?,?)',[$f['tag'].'-X','2001-03-01','2001-03-15','OPEN']);
$typeId=fn(string $code)=>(int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'];
/** Raw synthetic ticket so dates and statuses are exact. $branch = the requester's branch; $detail runs after the insert. */
function mk(string $code,string $status,int $filedAgo,?int $closedAgo,int $branch,string $group='SW',array $payload=[],?callable $detail=null):int{
    global $f,$typeId;$emp=$branch===$f['ho']?$f['requester']:$f['branch_requester'];$user=$branch===$f['ho']?$f['employee']['id']:$f['outsider']['id'];
    $id=insert('INSERT INTO tickets(ticket_no,ticket_type_id,requester_user_id,subject_employee_id,company_id,branch_id,company_group_snapshot,company_name_snapshot,business_unit_id_snapshot,status,payload_json,filed_at,closed_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,DATE_SUB(NOW(),INTERVAL ? DAY),'.($closedAgo===null?'NULL':'DATE_SUB(NOW(),INTERVAL '.(int)$closedAgo.' DAY)').',DATE_SUB(NOW(),INTERVAL ? DAY))',
        ['HR-T-'.strtoupper(bin2hex(random_bytes(6))),$typeId($code),$user,$emp,$f['legal'],$branch,$group,'Slice 15 Test Company',$f['company'],$status,json_encode($payload),$filedAgo,$filedAgo]);
    if($detail)$detail($id);return $id;
}
function uni(int $id,string $size,int $qty,int $branch):void{db()->prepare('INSERT INTO uniform_requests(ticket_id,position,size,company,branch_id,quantity,id_lace) VALUES(?,?,?,?,?,?,0)')->execute([$id,'Crew',$size,'SW',$branch,$qty]);}

// ---------------------------------------------------------------- dataset (all filed at the fixture branch unless noted)
$o=[1=>10,2=>6,3=>1,4=>3,5=>2,6=>8,7=>4,8=>5,9=>3,10=>4,11=>9,12=>5,13=>5,14=>6,15=>2,16=>12];// days ago filed, by ticket number
mk('ATM_REQUEST','PENDING_HR',$o[1],null,$b);
mk('ATM_REQUEST','COMPLETED',$o[2],4,$b);
mk('ID_REQUEST','PENDING_HR',$o[3],null,$b,'OC');
mk('ID_REQUEST','CANCELLED',$o[4],2,$b);
$u5=mk('UNIFORM_REQUEST','PENDING_HR',$o[5],null,$b,'SW',['size'=>'M','quantity'=>2,'branch'=>$b],fn($i)=>uni($i,'M',2,$b));
$u6=mk('UNIFORM_REQUEST','COMPLETED',$o[6],5,$b,'SW',['size'=>'M','quantity'=>1,'branch'=>$b],fn($i)=>uni($i,'M',1,$b));
$u7=mk('UNIFORM_REQUEST','IN_PROGRESS',$o[7],null,$b,'SW',['size'=>'L','quantity'=>3,'branch'=>$b],fn($i)=>uni($i,'L',3,$b));
$u8=mk('UNIFORM_REQUEST','CANCELLED',$o[8],3,$b,'SW',['size'=>'XL','quantity'=>5,'branch'=>$b],fn($i)=>uni($i,'XL',5,$b));
mk('NETSUITE_CONCERN','PENDING_HR',$o[9],null,$b);
mk('MANPOWER_REQUEST','PENDING_HR',$o[10],null,$b,'SW',[],fn($i)=>db()->prepare('INSERT INTO ticket_manpower_requests(ticket_id,section_branch,requested_by,position_code,employment_type,request_type,quantity,replacement_of_employee_id,job_description_qualifications,area_department) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$i,'T',$f['hr']['id'],'BS','Seasonal','Replacement',2,$f['requester'],'x','x']));
mk('MANPOWER_REQUEST','COMPLETED',$o[11],6,$b,'SW',[],fn($i)=>db()->prepare('INSERT INTO ticket_manpower_requests(ticket_id,section_branch,requested_by,position_code,employment_type,request_type,quantity,job_description_qualifications,area_department) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$i,'T',$f['hr']['id'],'SC','Probationary','Additional',3,'x','x']));
mk('COE_REQUEST','COMPLETED',$o[12],4,$b,'SW',[],fn($i)=>db()->prepare('INSERT INTO coe_requests(ticket_id,purpose,needed_by,delivery) VALUES(?,?,?,?)')->execute([$i,'Bank',date('Y-m-d'),'Email']));
mk('COE_REQUEST','PENDING_HR',$o[13],null,$b,'SW',[],fn($i)=>db()->prepare('INSERT INTO coe_requests(ticket_id,purpose,needed_by,delivery) VALUES(?,?,?,?)')->execute([$i,'Loan',date('Y-m-d'),'HR pickup']));
mk('BRANCH_TRANSFER','PENDING_HR',$o[14],null,$b,'SW',['from_branch'=>$b,'to_branch'=>$ho],fn($i)=>db()->prepare('INSERT INTO branch_transfers(ticket_id,employee_id,from_branch_id,to_branch_id,effective_date,company) VALUES(?,?,?,?,?,?)')->execute([$i,$f['branch_requester'],$b,$ho,date('Y-m-d',strtotime('+3 days')),'SW']));
$pc=fn(int $late)=>fn($i)=>(function()use($i,$late,$cutoff){db()->prepare('UPDATE tickets SET payroll_period_id=? WHERE id=?')->execute([$cutoff,$i]);db()->prepare('INSERT INTO payroll_concerns(ticket_id,concern_date,is_late) VALUES(?,?,?)')->execute([$i,'2001-03-05',$late]);})();
mk('PAYROLL_CONCERN','PENDING_APPROVAL',$o[15],null,$b,'SW',[],$pc(1));
mk('PAYROLL_CONCERN','COMPLETED',$o[16],2,$b,'SW',[],$pc(0));
mk('INCIDENT_REPORT','SUBMITTED',3,null,$b,'SW',['title'=>'SECRET incident title '.$f['tag'],'explanation'=>'SECRET detail']);
mk('ATM_REQUEST','PENDING_HR',40,null,$b);                        // 18: outside last 30 days, still pending
mk('ATM_REQUEST','DRAFT',2,null,$b);                              // 19: draft, never counted
$u21=mk('UNIFORM_REQUEST','PENDING_HR',1,null,$ho,'SW',['size'=>'S','quantity'=>1,'branch'=>$b],fn($i)=>uni($i,'S',1,$b)); // 21: filed from Head Office for the fixture branch

// ---------------------------------------------------------------- access
check('migration 015 permission exists',(int)row('SELECT COUNT(*) n FROM permissions WHERE code="ticket.report.view"')['n']===1);
$reviewRoles=db()->query('SELECT DISTINCT r.code FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.code="tickets.review_hr" AND r.code NOT LIKE "T15\_%"')->fetchAll(PDO::FETCH_COLUMN);
$reportRoles=db()->query('SELECT DISTINCT r.code FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.code="ticket.report.view" AND r.code NOT LIKE "T15\_%"')->fetchAll(PDO::FETCH_COLUMN);
sort($reviewRoles);sort($reportRoles);check('report permission seeded to the HR roles that review requests, and only those',$reviewRoles===$reportRoles&&$reportRoles!==[]);
$P=fn(array $x=[])=>TicketReport::params(array_replace(['period'=>'last_30','branch_id'=>$b],$x));
act($hr);check('HR without the permission is refused',rejects(fn()=>TicketReport::data($P())));
perm($hr['role_id'],'ticket.report.view');act($hr);
foreach(['employee'=>$f['employee'],'manager'=>$f['manager'],'adl'=>$f['adl'],'outsider'=>$f['outsider']] as $who=>$user){perm($user['role_id'],'ticket.report.view');act($user);check('non-HR '.$who.' is refused even if the permission is granted',rejects(fn()=>TicketReport::data($P()))&&rejects(fn()=>TicketReport::csvRows($P())));perm($user['role_id'],'ticket.report.view',false);}
act($hr);

// ---------------------------------------------------------------- params
$bad=TicketReport::params(['period'=>'forever','company'=>'XX','type_code'=>'INCIDENT_REPORT','branch_id'=>'-5']);
check('unknown period, company and incident type fall back safely',$bad['period']==='this_month'&&$bad['company']==='ALL'&&$bad['type_code']===''&&$bad['branch_id']===0);
check('SQL in type_code is dropped',TicketReport::params(['type_code'=>'ATM_REQUEST" OR 1=1 --'])['type_code']==='');
check('custom needs two valid dates in order',TicketReport::params(['period'=>'custom','from'=>'2026-02-30','to'=>'2026-03-01'])['period']==='this_month'&&TicketReport::params(['period'=>'custom','from'=>'2026-03-02','to'=>'2026-03-01'])['period']==='this_month'&&TicketReport::params(['period'=>'custom','from'=>'2026-03-01','to'=>'2026-03-31'])['period']==='custom');
$T=new DateTimeImmutable('2026-10-21');// Wednesday
$w=fn(string $per)=>TicketReport::window(['period'=>$per,'from'=>'2026-01-05','to'=>'2026-01-09'],$T);
check('windows: this week, last week, this month, last month, last 30, this year, custom',$w('this_week')['start']==='2026-10-19'&&$w('last_week')['start']==='2026-10-12'&&$w('last_week')['end']==='2026-10-19'&&$w('this_month')['start']==='2026-10-01'&&$w('last_month')['start']==='2026-09-01'&&$w('last_month')['end']==='2026-10-01'&&$w('last_30')['start']==='2026-09-22'&&$w('this_year')['start']==='2026-01-01'&&$w('custom')['end']==='2026-01-10');

// ---------------------------------------------------------------- numbers (branch filter isolates the synthetic data)
$d=TicketReport::data($P());$s=$d['summary'];
check('summary: filed in period excludes drafts, incident reports and older requests (17)',$s['status']['total']===17);
check('summary: status buckets',$s['status']['pending']===8&&$s['status']['progress']===1&&$s['status']['served']===5&&$s['status']['cancelled']===2&&$s['status']['other']===1);
check('summary: per type totals',$s['types']['ATM_REQUEST']['total']===2&&$s['types']['ID_REQUEST']['total']===2&&$s['types']['UNIFORM_REQUEST']['total']===5&&$s['types']['MANPOWER_REQUEST']['total']===2&&$s['types']['COE_REQUEST']['total']===2&&$s['types']['PAYROLL_CONCERN']['total']===2&&$s['types']['BRANCH_TRANSFER']['total']===1&&$s['types']['NETSUITE_CONCERN']['total']===1);
$sinceMon=(int)date('N')-1;$expectNew=count(array_filter($o,fn($n)=>$n<=$sinceMon))+(1<=$sinceMon?1:0);// the Head Office uniform was filed 1 day ago
check('summary: new this week counts requests filed since Monday',$s['new_this_week']===$expectNew);
check('summary: average time to Served is 3.8 days over 5 served',$s['avg_days']===3.8&&$s['served_n']===5);
check('summary: per type average (ATM 2.0, Uniform 3.0)',$s['types']['ATM_REQUEST']['avg_days']===2.0&&$s['types']['UNIFORM_REQUEST']['avg_days']===3.0);
$a=$d['aging'];
check('aging: buckets ignore the period (the 40-day-old ATM counts)',$a['buckets']===[3,3,3]&&$a['pending']===9&&$a['over']===3);
check('aging: oldest first, with name and branch',count($a['oldest'])===9&&$a['oldest'][0]['age_days']===40&&$a['oldest'][1]['age_days']===10&&$a['oldest'][0]['branch_name']==='Slice15 Test Branch'&&str_contains($a['oldest'][0]['first_name'],'Slice15'));
check('aging: over-5-days by type',array_sum(array_column($a['over_by_type'],'n'))===3);
$og=$d['origin'];$byBranch=array_column($og['branch'],'n','name');
check('origin: by branch counts the requester branch (Head Office filing for the branch is its own row)',($byBranch['Slice15 Test Branch']??0)===16&&($byBranch['Slice15 Test Head Office']??0)===1);
check('origin: by company group',array_column($og['company'],'n','name')==["SW"=>16,"Ocampo's"=>1]);
check('origin: branch filter hides the no-filing list',$og['no_filing']===null);
$dd=$d['detail'];
check('uniform: pieces per size per branch, cancelled left out',$dd['UNIFORM_REQUEST']['totals']['M']===3&&$dd['UNIFORM_REQUEST']['totals']['L']===3&&$dd['UNIFORM_REQUEST']['totals']['S']===1&&$dd['UNIFORM_REQUEST']['totals']['XL']===0&&$dd['UNIFORM_REQUEST']['pieces']===7&&$dd['UNIFORM_REQUEST']['requests']===4&&count($dd['UNIFORM_REQUEST']['rows'])===1&&$dd['UNIFORM_REQUEST']['rows'][0]['sizes']==['M'=>3,'L'=>3,'S'=>1]);
check('payroll concern: by cutoff with late and open',$dd['PAYROLL_CONCERN']['rows'][0]['cutoff']===$f['tag'].'-X'&&$dd['PAYROLL_CONCERN']['rows'][0]['total']===2&&$dd['PAYROLL_CONCERN']['rows'][0]['open']===1&&$dd['PAYROLL_CONCERN']['rows'][0]['served']===1&&$dd['PAYROLL_CONCERN']['rows'][0]['late']===1);
$mp=array_column($dd['MANPOWER_REQUEST']['rows'],null,'position');
check('manpower: position and quantity',$mp['BS']['Replacement']===2&&$mp['SC']['Additional']===3&&$mp['SC']['total']===3);
check('branch transfer route',$dd['BRANCH_TRANSFER']['rows'][0]['from']==='Slice15 Test Branch'&&$dd['BRANCH_TRANSFER']['rows'][0]['to']==='Slice15 Test Head Office'&&$dd['BRANCH_TRANSFER']['rows'][0]['n']===1);
$coe=array_column($dd['COE_REQUEST']['rows'],null,'purpose');check('COE by purpose',$coe['Bank']['served']===1&&$coe['Loan']['n']===1);
check('ATM, ID and NetSuite appear as basic sections; OIC with no data is a zero section',isset($dd['ATM_REQUEST'],$dd['ID_REQUEST'],$dd['NETSUITE_CONCERN'])&&$dd['OIC_AUTHORIZATION']['rows']===[]);
check('incident reports are a count only',$d['incident']===1);
$th=$d['throughput'];
check('throughput: daily for 30 days, totals match the summary and served count',!$th['weekly']&&count($th['series'])===30&&$th['filed']===17&&$th['served']===5);
$d90=TicketReport::data($P(['period'=>'last_90']));
check('throughput: weekly beyond 62 days, same totals',$d90['throughput']['weekly']&&$d90['throughput']['filed']===18&&$d90['throughput']['served']===5);
check('throughput is not per person (no user fields)',!array_intersect(['user','user_id','name','actor'],array_keys($th['series'][0])));
check('setup health reduced to counts and branch names',is_array($d['setup'])&&isset($d['setup']['without_viewer'])&&is_array($d['setup']['branches_without_sc'])&&!array_filter($d['setup']['branches_without_sc'],'is_array'));

// ---------------------------------------------------------------- filters
$dOC=TicketReport::data($P(['company'=>'OC']));check('company filter Ocampo\'s',$dOC['summary']['status']['total']===1&&$dOC['summary']['types']['ID_REQUEST']['total']===1);
$dT=TicketReport::data($P(['type_code'=>'ATM_REQUEST']));check('type filter narrows summary, aging and hides other sections',array_keys($dT['summary']['types'])===['ATM_REQUEST']&&$dT['summary']['status']['total']===2&&$dT['aging']['pending']===2&&array_keys($dT['detail'])===['ATM_REQUEST']&&$dT['incident']===null);
$dU=TicketReport::data($P(['type_code'=>'UNIFORM_REQUEST']));check('branch filter follows the uniform target branch',$dU['summary']['status']['total']===5&&$dU['detail']['UNIFORM_REQUEST']['pieces']===7);
$dC=TicketReport::data($P(['period'=>'custom','from'=>date('Y-m-d',strtotime('-3 days')),'to'=>date('Y-m-d')]));check('custom range',$dC['summary']['status']['total']>=1&&$dC['summary']['status']['total']<17);
$dLast=TicketReport::data($P(['period'=>'last_week']));check('different period changes the count, aging does not',$dLast['aging']['pending']===9);

// ---------------------------------------------------------------- zero data
$zero=TicketReport::data($P(['branch_id'=>$ho,'type_code'=>'BRANCH_TRANSFER']));
check('zero data: totals are zero, averages are blank, no errors',$zero['summary']['status']['total']===0&&$zero['summary']['avg_days']===null&&$zero['aging']['pending']===0&&$zero['throughput']['filed']===0&&$zero['detail']['BRANCH_TRANSFER']['rows']===[]);
ob_start();ticket_report_page($zero['params'],$zero);$html=ob_get_clean();check('zero-data page renders cleanly with plain empty states',$clean($html)&&str_contains($html,'No requests were filed in this period')&&str_contains($html,'Nothing is pending'));
ob_start();ticket_report_print_page($zero['params'],$zero);$html=ob_get_clean();check('zero-data print page renders',$clean($html)&&str_contains($html,'No requests were filed'));

// ---------------------------------------------------------------- pages
ob_start();ticket_report_page($d['params'],$d);$html=ob_get_clean();
check('page renders: sections, filters, export and print links, no PHP messages',$clean($html)&&str_contains($html,'Summary')&&str_contains($html,'Aging')&&str_contains($html,'Where requests come from')&&str_contains($html,'Uniform Request')&&str_contains($html,'HR throughput')&&str_contains($html,'Setup health')&&str_contains($html,'hr-ticket-report-export')&&str_contains($html,'hr-ticket-report-print'));
check('page never shows incident details',!str_contains($html,'SECRET')&&str_contains($html,'count only'));
ob_start();ticket_report_print_page($d['params'],$d);$html=ob_get_clean();
check('print page is standalone (no sidebar) with the key numbers',$clean($html)&&!str_contains($html,'class="side"')&&str_contains($html,'HR Ticketing Summary')&&str_contains($html,'By request type')&&!str_contains($html,'SECRET'));

// ---------------------------------------------------------------- CSV
db()->prepare('UPDATE employees SET personal_email="pii-leak@example.test",mobile_no="09170000000",address_text="12 Secret St",birth_date="1990-01-01" WHERE id IN (?,?)')->execute([$f['branch_requester'],$f['requester']]);
$csv=TicketReport::csvRows($P());$rows=$csv['rows'];
check('csv: one row per filtered request (17), header as documented',count($rows)===17&&!$csv['truncated']&&TicketReport::CSV_HEADER===['Request no','Type','Status','Company','Branch','Area','Employee','Filed','Closed','Days open or to Served','Detail']&&count($rows[0])===11);
$flat=json_encode($rows);
check('csv: no personal data beyond name and branch',!str_contains($flat,'pii-leak')&&!str_contains($flat,'0917')&&!str_contains($flat,'Secret St')&&!str_contains($flat,'1990'));
check('csv: no incident rows and no incident text',!str_contains($flat,'Incident')&&!str_contains($flat,'SECRET'));
$byNo=array_column($rows,null,0);$uniRow=array_values(array_filter($rows,fn($r)=>$r[1]==='Uniform Request'&&str_contains($r[10],'Size L')))[0]??null;
check('csv: detail text is structured, not free text',$uniRow!==null&&str_starts_with($uniRow[10],'Size L x3 for Slice15 Test Branch')&&(bool)array_filter($rows,fn($r)=>$r[10]==='Purpose: Bank'));
check('csv: status labels match the queue and served rows carry days to Served',(bool)array_filter($rows,fn($r)=>$r[2]==='Served'&&$r[8]!==''&&$r[9]>=1)&&(bool)array_filter($rows,fn($r)=>$r[2]==='Pending'));
db()->prepare('UPDATE employees SET last_name=? WHERE id=?')->execute(['=HYPERLINK("http://x")',$f['branch_requester']]);
$cell=TicketQueue::csvCell(trim('Slice15 =HYPERLINK'));$formula=TicketQueue::csvCell('=HYPERLINK("http://x")');
check('csv cells that start with a formula character are neutralised',str_starts_with($formula,"'=")&&$cell==='Slice15 =HYPERLINK');
db()->prepare('UPDATE employees SET last_name="Test branch_requester" WHERE id=?')->execute([$f['branch_requester']]);

// ---------------------------------------------------------------- size and speed (363-employee scale)
$batch=[];for($i=0;$i<700;$i++){$batch[]=[$i%7===0?'ID_REQUEST':'ATM_REQUEST',['PENDING_HR','COMPLETED','CANCELLED','IN_PROGRESS'][$i%4],$i%60,null,$i%2?$ho:$b];}
foreach($batch as $x){$id=mk($x[0],$x[1],$x[2],$x[1]==='COMPLETED'?max(0,$x[2]-1):null,$x[4]);}
$t0=microtime(true);$all=TicketReport::data(TicketReport::params(['period'=>'this_year']));$t1=microtime(true);
check('unfiltered report over 700+ tickets builds in under 1.5 seconds',($t1-$t0)<1.5);
$t0=microtime(true);ob_start();ticket_report_page($all['params'],$all);$html=ob_get_clean();$t1=microtime(true);
check('and renders in under 1.5 seconds with no PHP messages',($t1-$t0)<1.5&&$clean($html));
$t0=microtime(true);$big=TicketReport::csvRows(TicketReport::params(['period'=>'all']));$t1=microtime(true);
check('unfiltered CSV of everything is capped and fast',($t1-$t0)<1.5&&count($big['rows'])<=TicketReport::CSV_CAP);
echo sprintf("INFO build %.0f ms\n",($t1-$t0)*1000);
finish();
