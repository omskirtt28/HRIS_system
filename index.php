<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/View.php';

$page = (string)($_GET['page'] ?? 'home');

function need_db(): void {
    if (($GLOBALS['pdo'] ?? null) instanceof PDO) return;
    render_head('Database setup required');
    echo '<div class="wrap" style="padding:60px 24px;max-width:800px"><div class="card pad"><h1 style="font-size:24px;margin-bottom:10px">Database setup required</h1><p class="muted">The PHP application is running, but MySQL is not connected yet.</p><div class="alert error">'.e($GLOBALS['db_error'] ?? 'Unknown database error').'</div><ol class="muted"><li>Create/import <code>database/schema.sql</code> in phpMyAdmin.</li><li>Copy <code>config/config.local.example.php</code> to <code>config/config.local.php</code>.</li><li>Set the MySQL database name, username and password.</li><li>Reload this page.</li></ol></div></div></body></html>';
    exit;
}

// -------- actions --------
if ($page === 'logout') { Auth::logout(); redirect('home'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    need_db(); verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'login') {
            $portal=(string)($_POST['portal'] ?? 'hr');
            if (Auth::login((string)($_POST['email']??''),(string)($_POST['password']??''),$portal)) {
                flash('success','Signed in successfully.');
                redirect($portal.'-dashboard');
            }
            flash('error','Invalid credentials or portal access.'); redirect('login',['portal'=>$portal]);
        }
        if ($action === 'apply') {
            $required=['first_name','last_name','email','mobile_no','job_opening_id'];
            foreach($required as $k) if(trim((string)($_POST[$k]??''))==='') throw new RuntimeException('Please complete all required fields.');
            if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Please enter a valid email address.');
            $ref=RecruitmentRepository::createApplication([
                'first_name'=>trim((string)$_POST['first_name']), 'last_name'=>trim((string)$_POST['last_name']),
                'email'=>trim((string)$_POST['email']), 'mobile_no'=>trim((string)$_POST['mobile_no']),
                'job_opening_id'=>(int)$_POST['job_opening_id'], 'why_fit'=>trim((string)($_POST['why_fit']??'')),
            ], $_FILES['resume'] ?? null);
            flash('success','Application submitted successfully. Your reference number is '.$ref.'.');
            redirect('track',['ref'=>$ref,'email'=>trim((string)$_POST['email'])]);
        }
        if ($action === 'move_stage') {
            Auth::requireRoles(['SUPER_ADMIN','RECRUITMENT_MANAGER','RECRUITER']);
            RecruitmentRepository::moveStage((int)$_POST['application_id'],(string)$_POST['target_stage'],trim((string)($_POST['comment']??'')));
            flash('success','Application stage updated.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'schedule_interview') {
            Auth::requireRoles(['SUPER_ADMIN','RECRUITMENT_MANAGER','RECRUITER']);
            RecruitmentRepository::scheduleInterview((int)$_POST['application_id'],[
                'interview_type'=>(string)$_POST['interview_type'], 'scheduled_at'=>(string)$_POST['scheduled_at'],
                'location_or_link'=>trim((string)($_POST['location_or_link']??'')), 'notes'=>trim((string)($_POST['notes']??''))
            ]);
            flash('success','Interview scheduled and candidate moved to Interview stage.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'create_manpower') {
            Auth::requireRoles(['SUPER_ADMIN','RECRUITMENT_MANAGER']);
            RecruitmentRepository::createManpowerRequest([
                'client_id'=>(int)$_POST['client_id'],'branch_id'=>(int)($_POST['branch_id']??0),'department_id'=>(int)($_POST['department_id']??0),
                'position_title'=>trim((string)$_POST['position_title']),'requested_headcount'=>(int)$_POST['requested_headcount'],
                'employment_type'=>(string)$_POST['employment_type'],'priority'=>(string)$_POST['priority'],
                'target_start_date'=>(string)($_POST['target_start_date']??''),'notes'=>trim((string)($_POST['notes']??''))
            ]);
            flash('success','Manpower request created.'); redirect('hr-manpower');
        }
        if ($action === 'endorse') {
            Auth::requireRoles(['SUPER_ADMIN','RECRUITMENT_MANAGER','RECRUITER']);
            RecruitmentRepository::endorse((int)$_POST['application_id'],trim((string)($_POST['note']??'')));
            flash('success','Candidate endorsed to the client and moved to Client Review.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'save_offer') {
            Auth::requireRoles(['SUPER_ADMIN','RECRUITMENT_MANAGER','RECRUITER']);
            RecruitmentRepository::saveOffer((int)$_POST['application_id'],['offered_salary'=>(float)($_POST['offered_salary']??0),'employment_type'=>(string)$_POST['employment_type'],'start_date'=>(string)($_POST['start_date']??'')]);
            flash('success','Offer saved and marked as sent.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'accept_offer') {
            Auth::requireRoles(['SUPER_ADMIN','RECRUITMENT_MANAGER','RECRUITER']);
            RecruitmentRepository::acceptOffer((int)$_POST['application_id']);
            flash('success','Offer marked accepted; candidate moved to Deployment.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'save_deployment') {
            Auth::requireRoles(['SUPER_ADMIN','RECRUITMENT_MANAGER','RECRUITER','COORDINATOR']);
            RecruitmentRepository::saveDeployment((int)$_POST['application_id'],['branch_id'=>(int)($_POST['branch_id']??0),'scheduled_date'=>(string)($_POST['scheduled_date']??''),'notes'=>trim((string)($_POST['notes']??''))]);
            flash('success','Deployment schedule saved.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'complete_deployment') {
            Auth::requireRoles(['SUPER_ADMIN','RECRUITMENT_MANAGER','RECRUITER','COORDINATOR']);
            RecruitmentRepository::completeDeployment((int)$_POST['application_id']);
            flash('success','Candidate marked Deployed and manpower fill count updated.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'client_decision') {
            Auth::requireRoles(['CLIENT_USER']);
            RecruitmentRepository::clientDecision((int)$_POST['application_id'],(string)$_POST['decision'],trim((string)($_POST['remarks']??'')));
            flash('success','Candidate decision saved.'); redirect('client-approvals');
        }
    } catch(Throwable $e) {
        flash('error',$e->getMessage());
        $back=(string)($_POST['return_page']??'home'); $params=[]; if(!empty($_POST['return_id']))$params['id']=(int)$_POST['return_id'];
        redirect($back,$params);
    }
}

// -------- public pages --------
if ($page === 'home') {
    need_db(); $jobs=RecruitmentRepository::publishedJobs(); $clients=RecruitmentRepository::clients(); $open=array_sum(array_map(fn($j)=>(int)$j['openings'],$jobs));
    render_head('Careers'); render_public_header('home'); ?>
    <div class="hero"><div class="in"><span class="eyebrow">Now hiring across Luzon, Visayas & Mindanao</span><h1>Find your next role with PMBSI</h1><p>We match skilled Filipino workers with trusted employers in retail, logistics, manufacturing, food service and finance.</p><form class="searchbar" method="get"><input type="hidden" name="page" value="careers"><input name="q" placeholder="Search job title, e.g. Warehouse Supervisor"><button class="btn primary">Search jobs</button></form><div style="display:flex;gap:26px;margin-top:26px"><div><div style="font-size:24px;font-weight:700;color:#fff"><?=$open?>+</div><div class="tiny" style="color:#C7C8C9">Open positions</div></div><div><div style="font-size:24px;font-weight:700;color:#fff"><?=count($clients)?></div><div class="tiny" style="color:#C7C8C9">Partner companies</div></div><div><div style="font-size:24px;font-weight:700;color:#fff">2,400+</div><div class="tiny" style="color:#C7C8C9">Careers launched</div></div></div></div></div>
    <div class="wrap" style="margin-top:40px"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px"><h2 style="font-size:22px">Featured openings</h2><a href="<?=url('careers')?>" class="btn sm">View all jobs →</a></div><div class="jobgrid"><?php foreach(array_slice($jobs,0,6) as $j) job_card($j); ?></div></div>
    <?php render_public_footer(); exit;
}

if ($page === 'careers') {
    need_db(); $q=trim((string)($_GET['q']??'')); $dept=trim((string)($_GET['department']??'')); $type=trim((string)($_GET['type']??'')); $jobs=RecruitmentRepository::publishedJobs($q?:null,$dept?:null,$type?:null); $departments=RecruitmentRepository::departments();
    render_head('Careers'); render_public_header('careers'); ?>
    <div class="wrap" style="padding-top:36px"><div class="pagehead"><div><div class="crumb">Home / Careers</div><h1 style="font-size:26px">Browse <?=array_sum(array_map(fn($j)=>(int)$j['openings'],$jobs))?> open positions</h1></div></div>
    <form style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px"><input type="hidden" name="page" value="careers"><div class="topbar" style="height:auto;border:1px solid var(--border);border-radius:10px;padding:8px 12px;flex:1;min-width:260px;position:static"><div class="search" style="max-width:none">🔍<input name="q" value="<?=e($q)?>" placeholder="Search jobs by title, client or location"></div></div><select class="btn" name="department"><option value="">All departments</option><?php foreach($departments as $d):?><option <?=$dept===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select><select class="btn" name="type"><option value="">All types</option><option value="FULL_TIME" <?=$type==='FULL_TIME'?'selected':''?>>Full-time</option><option value="CONTRACT" <?=$type==='CONTRACT'?'selected':''?>>Contract</option></select><button class="btn primary">Filter</button></form>
    <div class="jobgrid"><?php if(!$jobs):?><div class="card empty">No published jobs match your filters.</div><?php endif; foreach($jobs as $j) job_card($j); ?></div></div>
    <?php render_public_footer(); exit;
}

if ($page === 'job') {
    need_db(); $job=RecruitmentRepository::jobBySlug((string)($_GET['slug']??'')); if(!$job){http_response_code(404);redirect('careers');}
    render_head($job['title']); render_public_header('careers'); $reqs=preg_split('/\R/',trim((string)$job['requirements'])) ?: []; ?>
    <div class="wrap" style="padding-top:36px"><div class="crumb"><a href="<?=url('careers')?>" class="muted">← Back to all jobs</a></div><div class="split3" style="margin-top:14px"><div><div class="card pad"><span class="badge amber"><?=e($job['department_name']?:'General')?></span><h1 style="font-size:27px;margin:12px 0 6px"><?=e($job['title'])?></h1><div class="muted"><?=e($job['client_name'])?> · <?=e($job['location_text'])?></div><div style="display:flex;gap:22px;margin:18px 0;flex-wrap:wrap"><div><div class="tiny muted">SALARY</div><div class="mono" style="font-weight:600"><?=e(money($job['salary_min']!==null?(float)$job['salary_min']:null,$job['salary_max']!==null?(float)$job['salary_max']:null))?></div></div><div><div class="tiny muted">TYPE</div><div style="font-weight:600"><?=e(stage_label($job['employment_type']))?></div></div><div><div class="tiny muted">OPENINGS</div><div style="font-weight:600"><?=e((string)$job['openings'])?></div></div></div><h3 class="section-t">Job description</h3><p class="muted"><?=nl2br(e($job['description']))?></p><h3 class="section-t" style="margin-top:20px">Requirements</h3><ul class="muted" style="padding-left:18px"><?php foreach($reqs as $r):?><li><?=e($r)?></li><?php endforeach;?></ul></div></div><div><div class="card pad" style="position:sticky;top:80px"><div class="mono" style="font-size:20px;font-weight:600"><?=e(money($job['salary_min']!==null?(float)$job['salary_min']:null,$job['salary_max']!==null?(float)$job['salary_max']:null))?></div><div class="tiny muted" style="margin-bottom:14px">estimated monthly</div><a href="<?=url('apply',['job_id'=>$job['id']])?>" class="btn primary block" style="margin-bottom:9px">Apply for this role</a><div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border)" class="small muted"><div style="margin-bottom:6px">✓ Direct employment</div><div style="margin-bottom:6px">✓ Government-mandated benefits</div><div>✓ Career growth support</div></div></div></div></div></div>
    <?php render_public_footer(); exit;
}

if ($page === 'apply') {
    need_db(); $jobs=RecruitmentRepository::publishedJobs(); $selected=(int)($_GET['job_id']??0); render_head('Apply'); render_public_header('careers'); ?>
    <div class="wrap" style="padding-top:36px;max-width:760px"><div class="pagehead"><div><div class="crumb">Home / Careers / Apply</div><h1 style="font-size:26px">Submit your application</h1></div></div><div style="display:flex;gap:8px;margin-bottom:22px;flex-wrap:wrap"><div class="step cur"><span class="n">1</span> Your details</div><span class="muted">──</span><div class="step"><span class="n">2</span> Resume</div><span class="muted">──</span><div class="step"><span class="n">3</span> Screening</div><span class="muted">──</span><div class="step"><span class="n">4</span> Review</div></div>
    <form class="card pad" method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="apply"><input type="hidden" name="return_page" value="apply"><h3 class="section-t">Personal information</h3><div class="split"><div class="field"><label>First name <span class="req">*</span></label><input required name="first_name"></div><div class="field"><label>Last name <span class="req">*</span></label><input required name="last_name"></div></div><div class="split"><div class="field"><label>Email <span class="req">*</span></label><input required type="email" name="email"></div><div class="field"><label>Mobile number <span class="req">*</span></label><input required name="mobile_no" placeholder="+63 9XX XXX XXXX"></div></div><div class="field"><label>Position applying for <span class="req">*</span></label><select required name="job_opening_id"><option value="">Select position</option><?php foreach($jobs as $j):?><option value="<?=$j['id']?>" <?=$selected===(int)$j['id']?'selected':''?>><?=e($j['title'].' — '.$j['client_name'])?></option><?php endforeach;?></select></div><div class="field"><label>Resume / CV</label><div class="dropzone"><input type="file" name="resume" accept=".pdf,.doc,.docx"><div class="tiny" style="margin-top:6px">PDF, DOC, DOCX up to 5MB</div></div></div><div class="field"><label>Why are you a good fit for this role?</label><textarea rows="4" name="why_fit"></textarea></div><label class="small muted" style="display:flex;gap:7px;align-items:flex-start;margin-bottom:14px"><input type="checkbox" required> I consent to the processing of my recruitment information for this application.</label><div style="display:flex;gap:10px;justify-content:flex-end"><a href="<?=url('careers')?>" class="btn">Cancel</a><button class="btn primary">Submit application →</button></div></form></div>
    <?php render_public_footer(); exit;
}

if ($page === 'track') {
    need_db(); $ref=trim((string)($_GET['ref']??'')); $email=trim((string)($_GET['email']??'')); $result=($ref&&$email)?RecruitmentRepository::track($ref,$email):null; render_head('Track Application'); render_public_header('track'); ?>
    <div class="wrap" style="padding-top:36px;max-width:720px"><div class="pagehead"><div><div class="crumb">Home / Track Application</div><h1 style="font-size:26px">Track your application</h1></div></div><form class="card pad" style="margin-bottom:20px" method="get"><input type="hidden" name="page" value="track"><div class="split"><div class="field"><label>Reference number</label><input required name="ref" value="<?=e($ref)?>" placeholder="PMBSI-2026-000912"></div><div class="field"><label>Email used to apply</label><input required type="email" name="email" value="<?=e($email)?>"></div></div><button class="btn primary">Check status</button></form>
    <?php if($ref&&$email&&!$result):?><div class="alert error">No application matched that reference number and email.</div><?php endif; if($result):?><div class="card pad"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px"><div><div class="tiny muted">APPLICATION</div><div style="font-weight:600"><?=e($result['title'])?> · <?=e($result['client_name'])?></div><div class="tiny muted mono"><?=e($result['application_no'])?></div></div><?=stage_badge($result['stage_code'])?></div><div class="tl"><?php foreach($result['history'] as $h):?><div class="ev"><div style="font-weight:600;font-size:13px"><?=e($h['to_name'])?></div><div class="tiny muted"><?=e(date('M j, Y g:i A',strtotime($h['changed_at'])))?><?=!empty($h['comment'])?' · '.e($h['comment']):''?></div></div><?php endforeach;?></div></div><?php endif;?></div>
    <?php render_public_footer(); exit;
}

if ($page === 'about' || $page === 'contact') {
    render_head($page==='about'?'About Us':'Contact'); render_public_header($page); ?>
    <div class="wrap" style="padding-top:36px;max-width:820px"><?php if($page==='about'):?><div class="pagehead"><div><div class="crumb">Home / About Us</div><h1 style="font-size:30px">About PMBSI</h1></div></div><p class="muted" style="font-size:16px">Prime Mover Business Solutions, Inc. is a Philippine workforce management and recruitment partner. This HRIS Recruitment module manages the lifecycle from manpower request to deployment.</p><div class="jobgrid" style="margin-top:26px"><div class="card pad"><h3>End-to-end recruitment</h3><p class="muted small">Sourcing, screening, interviews, endorsement, offers and deployment.</p></div><div class="card pad"><h3>Client collaboration</h3><p class="muted small">Scoped client review and approval of formally endorsed candidates.</p></div><div class="card pad"><h3>Recruitment records</h3><p class="muted small">Structured applicant/application history with audit visibility.</p></div></div><?php else:?><div class="pagehead"><div><div class="crumb">Home / Contact</div><h1 style="font-size:26px">Get in touch</h1></div></div><div class="card pad"><p class="muted">Use your organization’s official PMBSI contact details here before production deployment.</p><div class="small muted">HR Portal: <a class="btn sm" href="<?=url('login',['portal'=>'hr'])?>">Sign in</a> &nbsp; Client Portal: <a class="btn sm" href="<?=url('login',['portal'=>'client'])?>">Sign in</a></div></div><?php endif;?></div>
    <?php render_public_footer(); exit;
}

// -------- login --------
if ($page === 'login') {
    need_db(); $portal=(string)($_GET['portal']??'hr'); if(!in_array($portal,['hr','client','admin'],true))$portal='hr';
    $meta=['hr'=>['HR Portal','Recruitment workspace','a.domingo@pmbsi.com'], 'client'=>['Client Portal','Your candidates, your decisions','ops@primelogistics.com'], 'admin'=>['Super Admin','System administration console','winston.cruz@pmbsi.com']][$portal];
    render_head($meta[0]); render_flashes(); ?>
    <div class="loginwrap"><div class="login-hero"><a href="<?=url('home')?>" class="brand" style="color:#fff"><span class="logo">P</span><span style="color:#fff">PMBSI<span class="sub" style="color:#9A9DA0">HRIS</span></span></a><div style="position:relative;z-index:2"><span class="eyebrow" style="background:var(--amber);color:var(--ink);display:inline-block;padding:5px 12px;border-radius:999px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em"><?=e($meta[0])?></span><h1 style="font-size:34px;margin:16px 0 10px;max-width:340px"><?=e($meta[1])?></h1><p style="color:#C7C8C9;max-width:340px">Role-isolated access with server-side authorization and MySQL-backed accounts.</p></div><div class="tiny" style="color:#9A9DA0">Recruitment Module · PHP + MySQL</div></div><div class="login-form"><form class="login-card" method="post"><?=csrf_field()?><input type="hidden" name="action" value="login"><input type="hidden" name="portal" value="<?=e($portal)?>"><h2 style="font-size:22px">Sign in</h2><p class="muted small" style="margin:6px 0 22px">Demo seed credentials use password <code>demo1234</code>.</p><div class="field"><label>Email</label><input type="email" name="email" required value="<?=e($meta[2])?>"></div><div class="field"><label>Password</label><input type="password" name="password" required value="demo1234"></div><button class="btn primary block">Sign in →</button><div style="text-align:center;margin-top:12px"><a href="<?=url('home')?>" class="small muted">← Back to careers site</a></div></form></div></div></body></html>
    <?php exit;
}

// -------- HR portal --------
if (str_starts_with($page,'hr-')) {
    need_db(); if (!Auth::check()) redirect('login',['portal'=>'hr']); Auth::requireRoles(['SUPER_ADMIN','RECRUITMENT_MANAGER','RECRUITER','COORDINATOR']);
}

if ($page === 'hr-dashboard') {
    $d=RecruitmentRepository::dashboard(); $counts=RecruitmentRepository::stageCounts();
    $today=db()->query('SELECT i.*, CONCAT(ap.first_name," ",ap.last_name) applicant_name,j.title job_title FROM interviews i JOIN applications a ON a.id=i.application_id JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id WHERE DATE(i.scheduled_at)=CURDATE() ORDER BY i.scheduled_at LIMIT 6')->fetchAll();
    render_portal_header('hr',$page,'Recruitment Dashboard'); page_head('HR Portal / Dashboard','Recruitment Dashboard','<a href="'.url('hr-pipeline').'" class="btn primary sm">Open pipeline</a>'); ?>
    <div class="kpi-auto" style="margin-bottom:20px"><?php kpi_card('Active applicants',$d['active'],'','👤');kpi_card('In interview',$d['interviews'],'','◔');kpi_card('Endorsed / client review',$d['endorsed'],'','✓');kpi_card('Deployed',$d['deployed'],'','▲');kpi_card('Open requested headcount',$d['openReq'],'','▣');?></div>
    <div class="split3"><div class="card pad"><div class="section-t">Pipeline distribution</div><?php foreach($counts as $c):$pct=$d['active']?round(((int)$c['total']/$d['active'])*100):0;?><div class="srow" style="border:none;padding:6px 0"><div style="flex:1"><div class="small" style="display:flex;justify-content:space-between;margin-bottom:5px"><span><?=e($c['name'])?></span><span class="muted"><?=$c['total']?> (<?=$pct?>%)</span></div><div class="meter"><span style="width:<?=$pct?>%"></span></div></div></div><?php endforeach;?></div><div class="card pad"><div class="section-t">Today’s interviews</div><?php if(!$today):?><div class="empty">No interviews scheduled today.</div><?php endif;foreach($today as $i):?><div class="srow"><span class="avatar"><?=e(initials($i['applicant_name']))?></span><div style="flex:1"><div class="small" style="font-weight:600"><?=e($i['applicant_name'])?></div><div class="tiny muted"><?=e($i['job_title'])?></div></div><span class="badge amber"><?=e(date('g:i A',strtotime($i['scheduled_at'])))?></span></div><?php endforeach;?></div></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'hr-manpower') {
    $mrs=RecruitmentRepository::manpowerRequests(); $clients=RecruitmentRepository::clients(); $branches=RecruitmentRepository::branches(); $deps=RecruitmentRepository::departments();
    render_portal_header('hr',$page,'Manpower Requests'); page_head('HR Portal / Manpower Requests','Manpower Requests'); ?>
    <?php if(Auth::is('SUPER_ADMIN','RECRUITMENT_MANAGER')):?><details class="card pad" style="margin-bottom:18px"><summary style="cursor:pointer;font-weight:600">+ Create new manpower request</summary><form method="post" style="margin-top:16px"><?=csrf_field()?><input type="hidden" name="action" value="create_manpower"><input type="hidden" name="return_page" value="hr-manpower"><div class="split"><div class="field"><label>Client</label><select name="client_id" required><?php foreach($clients as $c):?><option value="<?=$c['id']?>"><?=e($c['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Branch / Site</label><select name="branch_id"><option value="">Not specified</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>"><?=e($b['name'])?></option><?php endforeach;?></select></div></div><div class="split"><div class="field"><label>Department</label><select name="department_id"><option value="">Not specified</option><?php foreach($deps as $d):?><option value="<?=$d['id']?>"><?=e($d['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Position title</label><input name="position_title" required></div></div><div class="split"><div class="field"><label>Requested headcount</label><input type="number" min="1" name="requested_headcount" required value="1"></div><div class="field"><label>Employment type</label><select name="employment_type"><option value="FULL_TIME">Full-time</option><option value="CONTRACT">Contract</option><option value="PART_TIME">Part-time</option></select></div></div><div class="split"><div class="field"><label>Priority</label><select name="priority"><option>NORMAL</option><option>URGENT</option></select></div><div class="field"><label>Target start date</label><input type="date" name="target_start_date"></div></div><div class="field"><label>Notes</label><textarea name="notes" rows="3"></textarea></div><button class="btn primary">Create request</button></form></details><?php endif;?>
    <div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>Request</th><th>Client</th><th>Position</th><th>Headcount</th><th>Filled</th><th>Priority</th><th>Status</th><th>Target</th></tr></thead><tbody><?php foreach($mrs as $m):?><tr><td class="mono tiny"><?=e($m['request_no'])?></td><td><?=e($m['client_name'])?></td><td><strong><?=e($m['position_title'])?></strong><div class="tiny muted"><?=e($m['department_name']??'')?></div></td><td><?=$m['requested_headcount']?></td><td><?=$m['filled_headcount']?></td><td><span class="badge <?=$m['priority']==='URGENT'?'red':'gray'?>"><?=e($m['priority'])?></span></td><td><span class="badge blue"><?=e(stage_label($m['status']))?></span></td><td><?=e($m['target_start_date']?:'—')?></td></tr><?php endforeach;?></tbody></table></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'hr-applicants') {
    $filters=['q'=>trim((string)($_GET['q']??'')),'stage'=>trim((string)($_GET['stage']??''))]; $apps=RecruitmentRepository::applications($filters); $stages=RecruitmentRepository::stages();
    render_portal_header('hr',$page,'Applicant Database'); page_head('HR Portal / Applicants','Applicant Database'); ?>
    <form style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap"><input type="hidden" name="page" value="hr-applicants"><div class="search" style="flex:1;min-width:220px;max-width:340px;border:1px solid var(--border)">🔍<input name="q" value="<?=e($filters['q'])?>" placeholder="Search by name, ref, email…"></div><select class="btn" name="stage"><option value="">All stages</option><?php foreach($stages as $s):?><option value="<?=e($s['code'])?>" <?=$filters['stage']===$s['code']?'selected':''?>><?=e($s['name'])?></option><?php endforeach;?></select><button class="btn primary">Filter</button></form>
    <div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>Applicant</th><th>Reference</th><th>Position</th><th>Client</th><th>Stage</th><th>Score</th><th>Recruiter</th><th></th></tr></thead><tbody><?php foreach($apps as $a):$name=$a['first_name'].' '.$a['last_name'];?><tr><td><div style="display:flex;gap:9px;align-items:center"><span class="avatar"><?=e(initials($name))?></span><div><div style="font-weight:600"><?=e($name)?></div><div class="tiny muted"><?=e($a['email'])?></div></div></div></td><td class="mono tiny"><?=e($a['application_no'])?></td><td><?=e($a['job_title'])?></td><td><?=e($a['client_name'])?></td><td><?=stage_badge($a['stage_code'])?></td><td><span class="badge <?=((float)$a['screening_score']>=85)?'green':'amber'?>"><?=e((string)($a['screening_score']??'—'))?></span></td><td class="small"><?=e($a['recruiter_name']??'Unassigned')?></td><td><a href="<?=url('hr-applicant',['id'=>$a['id']])?>" class="btn sm">View</a></td></tr><?php endforeach;?></tbody></table><?php if(!$apps):?><div class="empty">No applicants found.</div><?php endif;?></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'hr-pipeline') {
    $apps=RecruitmentRepository::applications(); $stages=RecruitmentRepository::stages(); $by=[]; foreach($apps as $a)$by[$a['stage_code']][]=$a;
    render_portal_header('hr',$page,'Recruitment Pipeline'); page_head('HR Portal / Pipeline','Recruitment Pipeline','<a href="'.url('hr-manpower').'" class="btn primary sm">+ New manpower request</a>'); ?>
    <div class="kanban"><?php foreach($stages as $s):$items=$by[$s['code']]??[];?><div class="kcol"><div class="kh"><span><span class="kdot" style="background:var(--amber)"></span> <?=e($s['name'])?></span><span class="badge gray"><?=count($items)?></span></div><?php foreach(array_slice($items,0,8) as $a):$name=$a['first_name'].' '.$a['last_name'];?><a href="<?=url('hr-applicant',['id'=>$a['id']])?>" class="kcard" style="display:block"><div class="nm"><?=e($name)?></div><div class="tiny muted" style="margin:3px 0 7px"><?=e($a['job_title'])?></div><div style="display:flex;justify-content:space-between"><span class="tiny mono muted"><?=e(substr($a['application_no'],-6))?></span><span class="badge amber"><?=e((string)($a['screening_score']??'—'))?></span></div></a><?php endforeach;if(!$items):?><div class="tiny muted" style="text-align:center;padding:16px 0">No candidates</div><?php endif;?></div><?php endforeach;?></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'hr-applicant') {
    $id=(int)($_GET['id']??0); $a=RecruitmentRepository::application($id); if(!$a){http_response_code(404);exit('Application not found');} $stages=RecruitmentRepository::stages(); $branches=RecruitmentRepository::branches(); $name=$a['first_name'].' '.$a['last_name'];
    render_portal_header('hr','hr-applicants','Applicant Profile'); page_head('HR Portal / Applicants / Profile','Applicant Profile'); ?>
    <div class="split3"><div><div class="card pad" style="margin-bottom:16px"><div style="display:flex;gap:14px;align-items:center"><span class="avatar" style="width:52px;height:52px;font-size:18px"><?=e(initials($name))?></span><div><h2 style="font-size:20px"><?=e($name)?></h2><div class="muted small"><?=e($a['job_title'])?> · <?=e($a['client_name'])?></div></div><div style="margin-left:auto;text-align:right"><?=stage_badge($a['stage_code'])?><div class="tiny muted mono" style="margin-top:5px"><?=e($a['application_no'])?></div></div></div><div style="display:flex;gap:24px;margin-top:16px;flex-wrap:wrap" class="small"><div><div class="tiny muted">EMAIL</div><?=e($a['email'])?></div><div><div class="tiny muted">PHONE</div><?=e($a['mobile_no'])?></div><div><div class="tiny muted">MATCH / SCREEN SCORE</div><span class="badge green"><?=e((string)($a['screening_score']??'—'))?></span></div></div></div>
    <?php if(Auth::is('SUPER_ADMIN','RECRUITMENT_MANAGER','RECRUITER')):?><div class="card pad" style="margin-bottom:16px"><div class="section-t">Stage & actions</div><form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end"><?=csrf_field()?><input type="hidden" name="action" value="move_stage"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><div class="field" style="margin:0"><label>Move to</label><select name="target_stage" class="btn status-select"><?php foreach($stages as $s):?><option value="<?=e($s['code'])?>" <?=$s['code']===$a['stage_code']?'selected':''?>><?=e($s['name'])?></option><?php endforeach;?></select></div><div class="field" style="margin:0;flex:1;min-width:200px"><label>Comment</label><input name="comment" placeholder="Reason / note"></div><button class="btn primary">Update stage</button></form></div>
    <details class="card pad" style="margin-bottom:16px"><summary style="font-weight:600;cursor:pointer">Schedule interview</summary><form method="post" style="margin-top:14px"><?=csrf_field()?><input type="hidden" name="action" value="schedule_interview"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><div class="split"><div class="field"><label>Interview type</label><select name="interview_type"><option>Initial Interview</option><option>Final Interview</option><option>Client Interview</option><option>Technical Interview</option></select></div><div class="field"><label>Date & time</label><input type="datetime-local" name="scheduled_at" required></div></div><div class="field"><label>Location or meeting link</label><input name="location_or_link"></div><div class="field"><label>Notes</label><textarea name="notes" rows="2"></textarea></div><button class="btn primary">Schedule interview</button></form></details>
    <?php if(in_array($a['stage_code'],['INTERVIEW','ENDORSED'],true)):?><form method="post" class="card pad" style="margin-bottom:16px"><?=csrf_field()?><input type="hidden" name="action" value="endorse"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><div class="section-t">Client endorsement</div><div class="field"><label>Endorsement note</label><textarea name="note" rows="2" placeholder="Summary for client review"></textarea></div><button class="btn primary">Endorse to client →</button></form><?php endif;?>
    <?php if($a['stage_code']==='OFFER' || $a['offer']):?><div class="card pad" style="margin-bottom:16px"><div class="section-t">Offer</div><?php if($a['offer']):?><div class="srow"><div class="small" style="flex:1"><strong><?=e($a['offer']['offer_no'])?></strong><div class="tiny muted">Status: <?=e($a['offer']['status'])?><?=!empty($a['offer']['start_date'])?' · Start '.e($a['offer']['start_date']):''?></div></div><span class="mono small"><?=e($a['offer']['offered_salary']!==null?'₱'.number_format((float)$a['offer']['offered_salary'],2):'—')?></span></div><?php endif;?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_offer"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><div class="split"><div class="field"><label>Offered salary</label><input type="number" step="0.01" name="offered_salary" value="<?=e((string)($a['offer']['offered_salary']??''))?>"></div><div class="field"><label>Employment type</label><select name="employment_type"><option value="FULL_TIME">Full-time</option><option value="CONTRACT">Contract</option></select></div></div><div class="field"><label>Start date</label><input type="date" name="start_date" value="<?=e((string)($a['offer']['start_date']??''))?>"></div><button class="btn primary">Save / Send offer</button></form><?php if(($a['offer']['status']??'')==='SENT'):?><form method="post" style="margin-top:10px"><?=csrf_field()?><input type="hidden" name="action" value="accept_offer"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><button class="btn">Mark offer accepted → Deployment</button></form><?php endif;?></div><?php endif;?>
    <?php if($a['stage_code']==='DEPLOYMENT' || $a['deployment']):?><div class="card pad" style="margin-bottom:16px"><div class="section-t">Deployment</div><?php if($a['deployment']):?><div class="srow"><div class="small" style="flex:1"><strong><?=e($a['deployment']['deployment_no'])?></strong><div class="tiny muted"><?=e($a['deployment']['status'])?><?=!empty($a['deployment']['branch_name'])?' · '.e($a['deployment']['branch_name']):''?></div></div><span class="tiny muted"><?=e($a['deployment']['scheduled_date']??'')?></span></div><?php endif;?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_deployment"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><div class="split"><div class="field"><label>Deployment branch/site</label><select name="branch_id"><option value="">Not specified</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=((int)($a['deployment']['branch_id']??0)===(int)$b['id'])?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Scheduled date</label><input type="date" name="scheduled_date" value="<?=e((string)($a['deployment']['scheduled_date']??''))?>"></div></div><div class="field"><label>Notes</label><textarea name="notes" rows="2"><?=e((string)($a['deployment']['notes']??''))?></textarea></div><button class="btn primary">Save deployment schedule</button></form><?php if($a['deployment'] && $a['deployment']['status']!=='DEPLOYED'):?><form method="post" style="margin-top:10px" onsubmit="return confirm('Mark this candidate as deployed?')"><?=csrf_field()?><input type="hidden" name="action" value="complete_deployment"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><button class="btn">Mark as Deployed</button></form><?php endif;?></div><?php endif;?>
    <?php endif;?>
    <div class="card pad"><div class="section-t">Screening answer</div><p class="muted"><?=nl2br(e($a['why_fit']?:'No answer provided.'))?></p><div class="section-t" style="margin-top:18px">Interviews</div><?php if(!$a['interviews']):?><div class="tiny muted">No interviews yet.</div><?php endif;foreach($a['interviews'] as $i):?><div class="srow"><div style="flex:1"><div class="small" style="font-weight:600"><?=e($i['interview_type'])?></div><div class="tiny muted"><?=e(date('M j, Y g:i A',strtotime($i['scheduled_at'])))?> · <?=e($i['location_or_link']?:'TBD')?></div></div><span class="badge blue"><?=e($i['status'])?></span></div><?php endforeach;?></div></div><div><div class="card pad"><div class="section-t">Activity timeline</div><div class="tl"><?php foreach($a['history'] as $h):?><div class="ev"><div class="small" style="font-weight:600"><?=e($h['to_name'])?></div><div class="tiny muted"><?=e(date('M j, Y g:i A',strtotime($h['changed_at'])))?><?=!empty($h['changed_by_name'])?' · '.e($h['changed_by_name']):''?></div><?php if($h['comment']):?><div class="tiny muted"><?=e($h['comment'])?></div><?php endif;?></div><?php endforeach;?></div></div></div></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'hr-endorsements') {
    $apps=array_filter(RecruitmentRepository::applications(),fn($a)=>in_array($a['stage_code'],['ENDORSED','CLIENT_REVIEW'],true));
    render_portal_header('hr',$page,'Client Endorsements'); page_head('HR Portal / Endorsements','Client Endorsements'); ?>
    <div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>Candidate</th><th>Position</th><th>Client</th><th>Score</th><th>Stage</th><th></th></tr></thead><tbody><?php foreach($apps as $a):$name=$a['first_name'].' '.$a['last_name'];?><tr><td><strong><?=e($name)?></strong><div class="tiny mono muted"><?=e($a['application_no'])?></div></td><td><?=e($a['job_title'])?></td><td><?=e($a['client_name'])?></td><td><span class="badge green"><?=e((string)$a['screening_score'])?></span></td><td><?=stage_badge($a['stage_code'])?></td><td><a class="btn sm" href="<?=url('hr-applicant',['id'=>$a['id']])?>">View</a></td></tr><?php endforeach;?></tbody></table><?php if(!$apps):?><div class="empty">No candidates currently endorsed or under client review.</div><?php endif;?></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'hr-reports') {
    $d=RecruitmentRepository::dashboard(); $counts=RecruitmentRepository::stageCounts();
    render_portal_header('hr',$page,'Recruitment Reports'); page_head('HR Portal / Reports','Recruitment Reports'); ?>
    <div class="kpi-auto" style="margin-bottom:20px"><?php kpi_card('Applications',$d['active'],'','👤');kpi_card('Interview stage',$d['interviews'],'','◔');kpi_card('Endorsed / review',$d['endorsed'],'','✓');kpi_card('Deployed',$d['deployed'],'','▲');?></div><div class="card pad"><div class="section-t">Current pipeline totals</div><?php foreach($counts as $c):?><div class="srow"><div class="small" style="flex:1"><?=e($c['name'])?></div><span class="badge gray"><?=$c['total']?></span></div><?php endforeach;?></div>
    <?php render_portal_footer(); exit;
}

// -------- Client portal --------
if (str_starts_with($page,'client-')) {
    need_db(); if (!Auth::check()) redirect('login',['portal'=>'client']); Auth::requireRoles(['CLIENT_USER']);
}

if ($page === 'client-dashboard') {
    $clientId=(int)Auth::user()['client_id']; $d=RecruitmentRepository::dashboard($clientId); $apps=RecruitmentRepository::applications(['client_id'=>$clientId,'client_portal'=>true]);
    $d['active']=count($apps); $d['interviews']=count(array_filter($apps,fn($a)=>$a['stage_code']==='INTERVIEW')); $d['endorsed']=count(array_filter($apps,fn($a)=>in_array($a['stage_code'],['ENDORSED','CLIENT_REVIEW'],true))); $d['deployed']=count(array_filter($apps,fn($a)=>$a['stage_code']==='DEPLOYED')); $pending=array_values(array_filter($apps,fn($a)=>in_array($a['stage_code'],['ENDORSED','CLIENT_REVIEW'],true)));
    render_portal_header('client',$page,'Client Dashboard'); page_head('Client Portal / Dashboard','Welcome, '.(Auth::user()['client_name']??'Client'),'<a href="'.url('client-approvals').'" class="btn primary sm">Review candidates</a>'); ?>
    <div class="kpi-auto" style="margin-bottom:20px"><?php kpi_card('Active candidates',$d['active'],'','👤');kpi_card('Awaiting review',count($pending),'','◔');kpi_card('In interview',$d['interviews'],'','✓');kpi_card('Deployed',$d['deployed'],'','▲');kpi_card('Open requested headcount',$d['openReq'],'','▣');?></div>
    <div class="card pad"><div class="section-t">Candidates requiring attention</div><?php if(!$pending):?><div class="empty">No candidates awaiting review.</div><?php endif;foreach(array_slice($pending,0,8) as $a):$name=$a['first_name'].' '.$a['last_name'];?><div class="srow"><span class="avatar"><?=e(initials($name))?></span><div style="flex:1"><div class="small" style="font-weight:600"><?=e($name)?></div><div class="tiny muted"><?=e($a['job_title'])?> · match <?=e((string)$a['screening_score'])?></div></div><?=stage_badge($a['stage_code'])?><a class="btn sm" href="<?=url('client-candidate',['id'=>$a['id']])?>">Review</a></div><?php endforeach;?></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'client-candidates') {
    $apps=RecruitmentRepository::applications(['client_id'=>(int)Auth::user()['client_id'],'client_portal'=>true]);
    render_portal_header('client',$page,'Endorsed Candidates'); page_head('Client Portal / Candidates','Candidates'); ?>
    <div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>Candidate</th><th>Position</th><th>Match</th><th>Stage</th><th>Applied</th><th></th></tr></thead><tbody><?php foreach($apps as $a):$name=$a['first_name'].' '.$a['last_name'];?><tr><td><strong><?=e($name)?></strong><div class="tiny mono muted"><?=e($a['application_no'])?></div></td><td><?=e($a['job_title'])?></td><td><span class="badge green"><?=e((string)($a['screening_score']??'—'))?></span></td><td><?=stage_badge($a['stage_code'])?></td><td><?=e(date('M j, Y',strtotime($a['applied_at'])))?></td><td><a href="<?=url('client-candidate',['id'=>$a['id']])?>" class="btn sm">View</a></td></tr><?php endforeach;?></tbody></table></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'client-approvals') {
    $apps=RecruitmentRepository::applications(['client_id'=>(int)Auth::user()['client_id'],'client_portal'=>true]);
    $pending=array_values(array_filter($apps,fn($a)=>in_array($a['stage_code'],['ENDORSED','CLIENT_REVIEW'],true)));
    render_portal_header('client',$page,'Candidate Approvals'); page_head('Client Portal / Approvals','Candidate Approvals'); ?>
    <p class="muted small" style="margin-bottom:16px">Review only candidates endorsed to your organization. An approval moves the application to Offer; a decline returns it to HR for follow-up.</p><div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(340px,1fr))"><?php foreach($pending as $a):$name=$a['first_name'].' '.$a['last_name'];?><div class="card pad"><div style="display:flex;gap:12px;align-items:center;margin-bottom:12px"><span class="avatar" style="width:44px;height:44px;font-size:15px"><?=e(initials($name))?></span><div style="flex:1"><div style="font-weight:600"><?=e($name)?></div><div class="tiny muted"><?=e($a['job_title'])?></div></div><span class="badge green"><?=e((string)$a['screening_score'])?></span></div><div class="tiny muted" style="margin-bottom:12px"><?=e($a['application_no'])?></div><a href="<?=url('client-candidate',['id'=>$a['id']])?>" class="btn primary block">Review candidate</a></div><?php endforeach;if(!$pending):?><div class="card empty">No candidates are waiting for your decision.</div><?php endif;?></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'client-candidate') {
    $a=RecruitmentRepository::application((int)($_GET['id']??0),(int)Auth::user()['client_id']); if(!$a){http_response_code(404);exit('Candidate not found');} $name=$a['first_name'].' '.$a['last_name'];
    render_portal_header('client','client-candidates','Candidate Review'); page_head('Client Portal / Candidates / Review','Candidate Review'); ?>
    <div class="split3"><div><div class="card pad" style="margin-bottom:16px"><div style="display:flex;gap:14px;align-items:center"><span class="avatar" style="width:52px;height:52px;font-size:18px"><?=e(initials($name))?></span><div><h2 style="font-size:20px"><?=e($name)?></h2><div class="muted small"><?=e($a['job_title'])?></div></div><div style="margin-left:auto"><?=stage_badge($a['stage_code'])?></div></div><div class="small muted" style="margin-top:14px"><strong>HR screening summary:</strong><br><?=nl2br(e($a['why_fit']?:'No screening note provided.'))?></div></div><?php if(in_array($a['stage_code'],['ENDORSED','CLIENT_REVIEW'],true)):?><form method="post" class="card pad"><?=csrf_field()?><input type="hidden" name="action" value="client_decision"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="client-candidate"><input type="hidden" name="return_id" value="<?=$a['id']?>"><div class="section-t">Decision</div><div class="field"><label>Remarks</label><textarea name="remarks" rows="3" placeholder="Optional client remarks"></textarea></div><div class="actions-inline"><button name="decision" value="APPROVED" class="btn primary">Approve for offer</button><button name="decision" value="REQUEST_INTERVIEW" class="btn">Request interview</button><button name="decision" value="DECLINED" class="btn">Decline / return to HR</button></div></form><?php endif;?></div><div><div class="card pad"><div class="section-t">Application details</div><div class="srow"><div class="small" style="flex:1">Reference</div><span class="mono tiny"><?=e($a['application_no'])?></span></div><div class="srow"><div class="small" style="flex:1">Match score</div><span class="badge green"><?=e((string)($a['screening_score']??'—'))?></span></div><div class="srow"><div class="small" style="flex:1">Applied</div><span class="tiny muted"><?=e(date('M j, Y',strtotime($a['applied_at'])))?></span></div></div></div></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'client-reports') {
    $d=RecruitmentRepository::dashboard((int)Auth::user()['client_id']); $apps=RecruitmentRepository::applications(['client_id'=>(int)Auth::user()['client_id'],'client_portal'=>true]); $d['active']=count($apps); $d['interviews']=count(array_filter($apps,fn($a)=>$a['stage_code']==='INTERVIEW')); $d['endorsed']=count(array_filter($apps,fn($a)=>in_array($a['stage_code'],['ENDORSED','CLIENT_REVIEW'],true))); $d['deployed']=count(array_filter($apps,fn($a)=>$a['stage_code']==='DEPLOYED')); $counts=[]; foreach(RecruitmentRepository::stages() as $st){$counts[]=['name'=>$st['name'],'total'=>count(array_filter($apps,fn($a)=>$a['stage_code']===$st['code']))];}
    render_portal_header('client',$page,'Client Recruitment Reports'); page_head('Client Portal / Reports','Recruitment Reports'); ?>
    <div class="kpi-auto" style="margin-bottom:20px"><?php kpi_card('Candidates',$d['active'],'','👤');kpi_card('Interview',$d['interviews'],'','◔');kpi_card('Endorsed / review',$d['endorsed'],'','✓');kpi_card('Deployed',$d['deployed'],'','▲');?></div><div class="card pad"><?php foreach($counts as $c):?><div class="srow"><div class="small" style="flex:1"><?=e($c['name'])?></div><span class="badge gray"><?=$c['total']?></span></div><?php endforeach;?></div>
    <?php render_portal_footer(); exit;
}

// -------- Admin portal --------
if (str_starts_with($page,'admin-')) { need_db(); if (!Auth::check()) redirect('login',['portal'=>'admin']); Auth::requireRoles(['SUPER_ADMIN','HRIS_ADMIN']); }

if ($page === 'admin-dashboard') {
    $d=RecruitmentRepository::dashboard(); $users=RecruitmentRepository::users(); $clients=RecruitmentRepository::clients(); $logs=RecruitmentRepository::auditLogs();
    render_portal_header('admin',$page,'System Administration'); page_head('Super Admin / Dashboard','System Administration'); ?>
    <div class="kpi-auto" style="margin-bottom:20px"><?php kpi_card('User accounts',count($users),'','👤');kpi_card('Clients',count($clients),'','▣');kpi_card('Applications',$d['active'],'','◆');kpi_card('Open headcount',$d['openReq'],'','▲');?></div><div class="card pad"><div class="section-t">Recent audit activity</div><?php foreach(array_slice($logs,0,8) as $l):?><div class="srow"><div style="flex:1"><div class="small" style="font-weight:600"><?=e($l['action'])?> · <?=e($l['module'])?></div><div class="tiny muted"><?=e($l['full_name']??'System')?> · <?=e(date('M j, Y g:i A',strtotime($l['created_at'])))?></div></div><span class="mono tiny muted"><?=e(($l['record_type']??'').($l['record_id']?' #'.$l['record_id']:''))?></span></div><?php endforeach;?></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'admin-users') {
    $users=RecruitmentRepository::users(); render_portal_header('admin',$page,'Users'); page_head('Super Admin / Users','Users & Access'); ?>
    <div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Client scope</th><th>Status</th><th>Last login</th></tr></thead><tbody><?php foreach($users as $u):?><tr><td><strong><?=e($u['full_name'])?></strong></td><td><?=e($u['email'])?></td><td><span class="badge gray"><?=e($u['role_name'])?></span></td><td><?=e($u['client_name']??'PMBSI / Global')?></td><td><span class="badge green"><?=e($u['status'])?></span></td><td class="tiny muted"><?=e($u['last_login_at']?:'Never')?></td></tr><?php endforeach;?></tbody></table></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'admin-clients') {
    $clients=RecruitmentRepository::clients(); render_portal_header('admin',$page,'Clients'); page_head('Super Admin / Organization','Clients'); ?>
    <div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>Code</th><th>Client</th><th>Contact</th><th>Status</th></tr></thead><tbody><?php foreach($clients as $c):?><tr><td class="mono tiny"><?=e($c['code'])?></td><td><strong><?=e($c['name'])?></strong></td><td><?=e($c['contact_email']??'—')?></td><td><span class="badge green"><?=e($c['status'])?></span></td></tr><?php endforeach;?></tbody></table></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'admin-branches') {
    $branches=RecruitmentRepository::branches(); render_portal_header('admin',$page,'Branches'); page_head('Super Admin / Organization','Branches & Sites'); ?>
    <div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>Code</th><th>Name</th><th>Client</th><th>Address</th></tr></thead><tbody><?php foreach($branches as $b):?><tr><td class="mono tiny"><?=e($b['code'])?></td><td><strong><?=e($b['name'])?></strong></td><td><?=e($b['client_name']??'PMBSI')?></td><td><?=e($b['address_text']??'—')?></td></tr><?php endforeach;?></tbody></table></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'admin-recruitment-config') {
    $stages=RecruitmentRepository::stages(); $counts=RecruitmentRepository::stageCounts(); $map=[];foreach($counts as $c)$map[$c['code']]=$c['total'];
    render_portal_header('admin',$page,'Recruitment Configuration'); page_head('Super Admin / Recruitment Config','Recruitment Configuration'); ?>
    <div class="card pad"><div class="section-t">Pipeline stages</div><p class="muted small">Stage codes are stable business identifiers. Display order and visibility are stored in MySQL.</p><?php foreach($stages as $s):?><div class="srow"><span class="mono tiny muted"><?=e((string)$s['sequence_no'])?></span><div style="flex:1"><div class="small" style="font-weight:600"><?=e($s['name'])?></div><div class="tiny mono muted"><?=e($s['code'])?></div></div><span class="badge gray"><?=e((string)($map[$s['code']]??0))?> active</span><span class="badge <?=$s['client_visible']?'blue':'gray'?>">Client <?=$s['client_visible']?'visible':'hidden'?></span></div><?php endforeach;?></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'admin-audit') {
    $logs=RecruitmentRepository::auditLogs(); render_portal_header('admin',$page,'Audit Logs'); page_head('Super Admin / Audit','Audit Logs'); ?>
    <div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>Timestamp</th><th>User</th><th>Module</th><th>Action</th><th>Record</th><th>IP</th></tr></thead><tbody><?php foreach($logs as $l):?><tr><td class="mono tiny"><?=e(date('M j H:i:s',strtotime($l['created_at'])))?></td><td><?=e($l['full_name']??'Public / System')?></td><td><?=e($l['module'])?></td><td><span class="badge gray"><?=e($l['action'])?></span></td><td class="mono tiny"><?=e(($l['record_type']??'').($l['record_id']?' #'.$l['record_id']:''))?></td><td class="mono tiny muted"><?=e($l['ip_address']??'—')?></td></tr><?php endforeach;?></tbody></table></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'admin-security') {
    render_portal_header('admin',$page,'Security Center'); page_head('Super Admin / Security','Security Center'); ?>
    <div class="kpi-auto" style="margin-bottom:18px"><?php kpi_card('Authentication','Session-based','','🛡');kpi_card('Passwords','password_hash()','','🔒');kpi_card('Database','PDO prepared statements','','✓');kpi_card('Forms','CSRF protected','','◆');?></div><div class="split"><div class="card pad"><div class="section-t">Enforced baseline</div><?php foreach(['Server-side role checks','Client record scope enforcement','CSRF token verification','Prepared SQL statements','Randomized resume filenames','MIME + size validation','Append-only audit records'] as $x):?><div class="srow"><span>✓</span><div class="small"><?=e($x)?></div></div><?php endforeach;?></div><div class="card pad"><div class="section-t">Production checklist</div><p class="muted small">Set <code>debug=false</code>, use a dedicated MySQL user, enable HTTPS, configure secure session cookies, move uploads outside the public web root, remove demo seed users, and schedule database backups before live deployment.</p></div></div>
    <?php render_portal_footer(); exit;
}

// -------- fallback --------
http_response_code(404); render_head('Page not found'); ?>
<div class="wrap" style="padding:80px 24px;text-align:center;max-width:520px"><div style="font-size:56px;font-weight:700;color:var(--amber)">404</div><h1 style="font-size:22px;margin:8px 0">Page not found</h1><p class="muted">The requested Recruitment page does not exist.</p><a href="<?=url('home')?>" class="btn primary" style="margin-top:16px">Careers site</a></div></body></html>
