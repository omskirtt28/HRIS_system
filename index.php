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
            $portal=trim((string)($_POST['portal'] ?? ''));
            if (Auth::login((string)($_POST['email']??''),(string)($_POST['password']??''),$portal !== '' ? $portal : null)) {
                flash('success','Signed in successfully.');
                redirect(Auth::landingPage());
            }
            flash('error','Invalid credentials or portal access.');
            redirect('login',$portal !== '' ? ['portal'=>$portal] : []);
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
            Auth::requirePermission('recruitment.manage');
            RecruitmentRepository::moveStage((int)$_POST['application_id'],(string)$_POST['target_stage'],trim((string)($_POST['comment']??'')));
            flash('success','Application stage updated.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'schedule_interview') {
            Auth::requirePermission('recruitment.manage');
            RecruitmentRepository::scheduleInterview((int)$_POST['application_id'],[
                'interview_type'=>(string)$_POST['interview_type'], 'scheduled_at'=>(string)$_POST['scheduled_at'],
                'location_or_link'=>trim((string)($_POST['location_or_link']??'')), 'notes'=>trim((string)($_POST['notes']??''))
            ]);
            flash('success','Interview scheduled and candidate moved to Interview stage.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'create_manpower') {
            Auth::requirePermission('recruitment.manage');
            RecruitmentRepository::createManpowerRequest([
                'client_id'=>(int)$_POST['client_id'],'branch_id'=>(int)($_POST['branch_id']??0),'department_id'=>(int)($_POST['department_id']??0),
                'position_title'=>trim((string)$_POST['position_title']),'requested_headcount'=>(int)$_POST['requested_headcount'],
                'employment_type'=>(string)$_POST['employment_type'],'priority'=>(string)$_POST['priority'],
                'target_start_date'=>(string)($_POST['target_start_date']??''),'notes'=>trim((string)($_POST['notes']??''))
            ]);
            flash('success','Manpower request created.'); redirect('hr-manpower');
        }
        if ($action === 'endorse') {
            Auth::requirePermission('recruitment.manage');
            RecruitmentRepository::endorse((int)$_POST['application_id'],trim((string)($_POST['note']??'')));
            flash('success','Candidate endorsed to the client and moved to Client Review.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'save_offer') {
            Auth::requirePermission('recruitment.manage');
            RecruitmentRepository::saveOffer((int)$_POST['application_id'],['offered_salary'=>(float)($_POST['offered_salary']??0),'employment_type'=>(string)$_POST['employment_type'],'start_date'=>(string)($_POST['start_date']??'')]);
            flash('success','Offer saved and marked as sent.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'accept_offer') {
            Auth::requirePermission('recruitment.manage');
            RecruitmentRepository::acceptOffer((int)$_POST['application_id']);
            flash('success','Offer marked accepted; candidate moved to Deployment.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'save_deployment') {
            Auth::requirePermission('recruitment.manage');
            RecruitmentRepository::saveDeployment((int)$_POST['application_id'],['branch_id'=>(int)($_POST['branch_id']??0),'scheduled_date'=>(string)($_POST['scheduled_date']??''),'notes'=>trim((string)($_POST['notes']??''))]);
            flash('success','Deployment schedule saved.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'complete_deployment') {
            Auth::requirePermission('recruitment.manage');
            RecruitmentRepository::completeDeployment((int)$_POST['application_id']);
            flash('success','Candidate marked Deployed and manpower fill count updated.'); redirect('hr-applicant',['id'=>(int)$_POST['application_id']]);
        }
        if ($action === 'client_decision') {
            Auth::requireRoles(['CLIENT_USER']);
            RecruitmentRepository::clientDecision((int)$_POST['application_id'],(string)$_POST['decision'],trim((string)($_POST['remarks']??'')));
            flash('success','Candidate decision saved.'); redirect('client-approvals');
        }
        if ($action === 'create_department') {
            Auth::requirePermission('organization.manage');
            FoundationRepository::createDepartment((string)($_POST['code']??''),(string)($_POST['name']??''));
            flash('success','Department created.'); redirect('admin-organization');
        }
        if ($action === 'create_position') {
            Auth::requirePermission('organization.manage');
            FoundationRepository::createPosition((string)($_POST['code']??''),(string)($_POST['name']??''),(int)($_POST['department_id']??0));
            flash('success','Position created.'); redirect('admin-organization');
        }
        if ($action === 'create_branch') {
            Auth::requirePermission('organization.manage');
            FoundationRepository::createBranch((string)($_POST['code']??''),(string)($_POST['name']??''),(string)($_POST['address_text']??''));
            flash('success','Branch created.'); redirect('admin-organization');
        }
        if ($action === 'create_employment_type') {
            Auth::requirePermission('organization.manage');
            FoundationRepository::createEmploymentType((string)($_POST['code']??''),(string)($_POST['name']??''));
            flash('success','Employment type created.'); redirect('admin-organization');
        }
        if ($action === 'toggle_master') {
            Auth::requirePermission('organization.manage');
            FoundationRepository::toggleMaster((string)($_POST['entity']??''),(int)($_POST['id']??0));
            flash('success','Organization record status updated.'); redirect('admin-organization');
        }
        if ($action === 'create_role') {
            Auth::requirePermission('roles.manage');
            FoundationRepository::createRole((string)($_POST['code']??''),(string)($_POST['name']??''),(string)($_POST['portal']??''));
            flash('success','Role created. Assign permissions below.'); redirect('admin-roles');
        }
        if ($action === 'save_role_permissions') {
            Auth::requirePermission('roles.manage');
            FoundationRepository::saveRolePermissions((int)($_POST['role_id']??0),(array)($_POST['permission_ids']??[]));
            flash('success','Role permissions updated.'); redirect('admin-roles',['role'=>(int)($_POST['role_id']??0)]);
        }
        if ($action === 'create_user') {
            Auth::requirePermission('users.manage');
            FoundationRepository::createUser((string)($_POST['full_name']??''),(string)($_POST['email']??''),(string)($_POST['password']??''),(int)($_POST['role_id']??0));
            flash('success','User account created.'); redirect('admin-users');
        }
        if ($action === 'toggle_user_status') {
            Auth::requirePermission('users.manage');
            FoundationRepository::toggleUserStatus((int)($_POST['user_id']??0));
            flash('success','User account status updated.'); redirect('admin-users');
        }
    } catch(Throwable $e) {
        flash('error',$e->getMessage());
        $defaultBack = match($action) {
            'create_department','create_position','create_branch','create_employment_type','toggle_master' => 'admin-organization',
            'create_role','save_role_permissions' => 'admin-roles',
            'create_user','toggle_user_status' => 'admin-users',
            default => 'home',
        };
        $back=(string)($_POST['return_page']??$defaultBack); $params=[]; if(!empty($_POST['return_id']))$params['id']=(int)$_POST['return_id'];
        redirect($back,$params);
    }
}

// -------- public pages --------
if ($page === 'home') {
    need_db();
    $jobs=RecruitmentRepository::publishedJobs();
    $clients=RecruitmentRepository::clients();
    $open=array_sum(array_map(fn($j)=>(int)$j['openings'],$jobs));
    render_head('Home'); render_public_header('home'); ?>

    <section class="home-hero">
      <div class="home-hero-grid">
        <div class="home-hero-copy">
          <span class="home-kicker">Prime Mover Business Solutions, Inc.</span>
          <h1>People operations,<br><span>built for today.</span></h1>
          <p>Modern workforce solutions for hiring, deployment, employee services and HR operations — connected through one secure HRIS experience.</p>
          <div class="home-hero-actions">
            <a class="btn primary home-btn" href="<?=url('careers')?>">Explore opportunities <?=icon_svg('arrow')?></a>
            <a class="btn home-btn home-btn-light" href="<?=url('login')?>">Employee Login</a>
          </div>
          <div class="home-trust-row">
            <span>Recruitment</span><i></i><span>Staffing</span><i></i><span>Outsourcing</span><i></i><span>HR Services</span>
          </div>
        </div>
        <div class="home-hero-visual" aria-label="PMBSI workforce platform preview">
          <div class="home-float-card home-float-card--main">
            <div class="home-card-head"><div><span class="home-card-eyebrow">WORKFORCE OVERVIEW</span><strong>Today at PMBSI</strong></div><span class="home-live"><i></i> Live</span></div>
            <div class="home-metric-row">
              <div><span>Employees</span><strong>428</strong><small>+12 this month</small></div>
              <div><span>Present</span><strong>376</strong><small>87.9% today</small></div>
              <div><span>Open Roles</span><strong><?=$open?></strong><small>Across partner sites</small></div>
            </div>
            <div class="home-chart-wrap">
              <div class="home-chart-title"><span>Attendance pulse</span><small>Last 8 workdays</small></div>
              <div class="home-mini-bars"><i style="height:48%"></i><i style="height:66%"></i><i style="height:82%"></i><i style="height:61%"></i><i style="height:91%"></i><i style="height:73%"></i><i style="height:86%"></i><i style="height:94%"></i></div>
            </div>
          </div>
          <div class="home-float-card home-float-card--access">
            <span class="home-access-icon"><?=icon_svg('shield')?></span>
            <div><strong>Secure HRIS Access</strong><small>Employee · HR · HR Admin</small></div>
          </div>
          <div class="home-float-card home-float-card--candidate">
            <span class="home-candidate-icon"><?=icon_svg('user-plus')?></span>
            <div><strong>46 Active Applicants</strong><small>Recruitment pipeline</small></div>
          </div>
        </div>
      </div>
    </section>

    <section class="home-section" id="services">
      <div class="home-section-head">
        <div><span class="home-overline">WHAT WE DO</span><h2>Workforce solutions that move with your business.</h2></div>
        <p>From flexible staffing to direct placement and outsourced operations, PMBSI supports organizations across the employee lifecycle.</p>
      </div>
      <div class="home-service-grid">
        <?php foreach([
          ['Contractual Staffing','Flexible manpower support for operational and clerical requirements.','users'],
          ['Project-Based Staffing','Deploy skilled resources for fixed-term projects and time-boxed requirements.','calendar'],
          ['One-Time Placement','Direct placement support from rank-and-file to specialized and leadership roles.','briefcase'],
          ['Outsourcing Services','Managed back-office and workforce functions built around your operational needs.','settings']
        ] as [$title,$desc,$ico]): ?>
        <article class="home-service-card"><span class="home-service-icon"><?=icon_svg($ico)?></span><h3><?=e($title)?></h3><p><?=e($desc)?></p><span class="home-card-link">Learn more <?=icon_svg('arrow')?></span></article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="home-platform-section">
      <div class="home-platform-copy">
        <span class="home-overline">CONNECTED HR EXPERIENCE</span>
        <h2>One platform. Different experiences for every role.</h2>
        <p>Employees get a simple self-service portal. HR gets the operational workspace they need. HR Admin gets organization, security and access control — all inside the same system.</p>
        <div class="home-feature-list">
          <div><span><?=icon_svg('user')?></span><div><strong>Employee Self-Service</strong><small>Profile, attendance, leave, requests and personal HR records.</small></div></div>
          <div><span><?=icon_svg('users')?></span><div><strong>HR Operations</strong><small>Recruitment, workforce monitoring, employee records and HR workflows.</small></div></div>
          <div><span><?=icon_svg('shield')?></span><div><strong>HR Administration</strong><small>Roles, permissions, organization setup, security and audit logs.</small></div></div>
        </div>
        <a class="home-text-link" href="<?=url('login')?>">Open the HRIS portal <?=icon_svg('arrow')?></a>
      </div>
      <div class="home-platform-panel">
        <div class="home-platform-window">
          <div class="home-window-top"><span class="home-dots"><i></i><i></i><i></i></span><small>PMBSI HRIS</small><span></span></div>
          <div class="home-window-body">
            <aside><div class="home-window-brand"><img src="public/assets/branding/pmbsi-mark.png" alt=""><b>PMBSI HRIS</b></div><i class="active"></i><i></i><i></i><i></i><i></i><i></i></aside>
            <main><div class="home-window-bar"><i></i><i></i><i></i></div><div class="home-window-title"></div><div class="home-window-kpis"><i></i><i></i><i></i><i></i></div><div class="home-window-panels"><i></i><i></i></div></main>
          </div>
        </div>
      </div>
    </section>

    <section class="home-section home-jobs-section">
      <div class="home-section-head compact">
        <div><span class="home-overline">CAREERS</span><h2>Build your next chapter with PMBSI.</h2></div>
        <a href="<?=url('careers')?>" class="btn">View all openings <?=icon_svg('arrow')?></a>
      </div>
      <div class="jobgrid home-jobgrid"><?php foreach(array_slice($jobs,0,3) as $j) job_card($j); ?></div>
      <?php if(!$jobs): ?><div class="home-empty-jobs">New opportunities will appear here as soon as positions are published.</div><?php endif; ?>
    </section>

    <section class="home-portal-cta">
      <div><span class="home-overline">PMBSI EMPLOYEE PORTAL</span><h2>Already part of the team?</h2><p>Access your personal HR workspace, attendance, leave, requests and company updates through one secure login.</p></div>
      <a href="<?=url('login')?>" class="btn primary home-btn">Open Employee Login <?=icon_svg('arrow')?></a>
    </section>

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
    need_db();
    $portal=trim((string)($_GET['portal']??''));
    if(!in_array($portal,['employee','hr','client','admin'],true)) $portal='';
    $meta = match($portal) {
        'client' => ['Client Portal','Review endorsed candidates and recruitment decisions in one secure workspace.','ops@primelogistics.com'],
        'admin' => ['HRIS Administration','Manage access, organization settings, security and audit visibility.','winston.cruz@pmbsi.com'],
        'hr' => ['Human Resources','Recruitment, people operations and HR services in one secure workspace.','hr.demo@pmbsi.com'],
        'employee' => ['Employee Self-Service','Your workday, requests and HR services in one modern workspace.','employee.demo@pmbsi.com'],
        default => ['PMBSI HRIS','Your workday, HR services and workforce tools in one modern workspace.','employee.demo@pmbsi.com'],
    };
    render_head($meta[0]); render_flashes(); ?>
    <div class="loginwrap">
      <div class="login-hero">
        <a href="<?=url('home')?>" class="brand brand-official brand-official-login" style="position:relative;z-index:2" aria-label="Prime Mover Business Solutions, Inc."><img src="public/assets/branding/pmbsi-logo.png" alt="Prime Mover Business Solutions, Inc." class="brand-full-logo"></a>
        <div class="login-copy"><span class="login-kicker">Secure HR workspace</span><h1>People operations,<br>designed for how teams work now.</h1><p>One HRIS experience for employees, HR teams and administrators — with role-based access, clear workflows and a modern responsive interface.</p><div class="login-benefits"><div class="login-benefit"><strong>Employee self-service</strong><span>Attendance, leave, requests and profile access.</span></div><div class="login-benefit"><strong>HR operations</strong><span>Recruitment, workforce actions and reporting.</span></div><div class="login-benefit"><strong>Role-based access</strong><span>Each user only sees tools relevant to their role.</span></div><div class="login-benefit"><strong>Audit-ready</strong><span>Security controls and traceable system activity.</span></div></div></div>
        <div class="tiny" style="position:relative;z-index:2;color:#777f8b">Prime Mover Business Solutions, Inc. · Local development: localhost:3000</div>
      </div>
      <div class="login-form"><form class="login-card" method="post"><?=csrf_field()?><input type="hidden" name="action" value="login"><?php if($portal!==''):?><input type="hidden" name="portal" value="<?=e($portal)?>"><?php endif;?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:4px"><div><h2>Welcome back</h2><div class="login-sub"><?=e($meta[1])?></div></div><span class="badge amber"><?=e($portal===''?'Unified Login':ucfirst($portal).' Portal')?></span></div>
        <?php if(($GLOBALS['config']['app']['debug'] ?? false)===true):?><div class="portal-hint">Demo environment · password <code>demo1234</code></div><?php endif;?>
        <div class="field"><label>Email address</label><input type="email" name="email" required value="<?=e($meta[2])?>" autocomplete="username"></div>
        <div class="field"><label>Password</label><input type="password" name="password" required value="<?=($GLOBALS['config']['app']['debug'] ?? false)?'demo1234':''?>" autocomplete="current-password"></div>
        <button class="btn primary block" style="height:44px;justify-content:center">Sign in <?=icon_svg('arrow')?></button>
        <div class="login-footnote">Your dashboard is selected automatically based on your account role.</div>
        <div style="text-align:center;margin-top:14px"><a href="<?=url('home')?>" class="small muted">← Back to PMBSI Careers</a></div>
      </form></div>
    </div></body></html>
    <?php exit;
}

// -------- Employee portal --------
if (str_starts_with($page,'employee-')) {
    need_db(); if (!Auth::check()) redirect('login'); Auth::requireRoles(['EMPLOYEE','SUPER_ADMIN','HRIS_ADMIN']);
}

if ($page === 'employee-dashboard') {
    $u=Auth::user();
    render_portal_header('employee',$page,'Employee Home');
    dashboard_hero('Employee self-service','Good morning, '.explode(' ',trim((string)$u['name']))[0].'.','Here is your personal HR workspace for today.','<span class="badge amber">UI preview · employee modules next</span>'); ?>
    <div class="employee-focus">
      <section class="panel today-card"><div class="panel-head"><div><h2>Today</h2><p>Thursday · September 24, 2026</p></div><span class="status-pill">On time</span></div><div class="today-time"><div><div class="big">08:47 AM</div><div class="smallline">Time in · Schedule 9:00 AM — 6:00 PM</div></div><div style="text-align:right"><div class="smallline">Work location</div><strong style="font-size:13px">PMBSI Head Office</strong></div></div></section>
      <section class="panel"><div class="panel-head"><div><h2>Leave balance</h2><p>Available credits</p></div></div><div class="panel-body"><div class="balance-grid"><div class="balance-card"><strong>5.0</strong><span>Vacation leave</span></div><div class="balance-card"><strong>4.0</strong><span>Sick leave</span></div></div></div></section>
    </div>
    <div class="dashboard-grid equal">
      <section class="panel"><div class="panel-head"><div><h2>Quick actions</h2><p>Common employee requests</p></div></div><div class="panel-body"><div class="quick-actions"><a class="quick-action" href="#"><span class="qa-icon"><?=icon_svg('calendar')?></span><span><strong>Apply for leave</strong><span>Submit and track requests</span></span></a><a class="quick-action" href="#"><span class="qa-icon"><?=icon_svg('clock')?></span><span><strong>Attendance correction</strong><span>Request time record adjustment</span></span></a><a class="quick-action" href="#"><span class="qa-icon"><?=icon_svg('file')?></span><span><strong>Request COE</strong><span>Employment document request</span></span></a><a class="quick-action" href="#"><span class="qa-icon"><?=icon_svg('clipboard')?></span><span><strong>HR request</strong><span>Send a concern to HR</span></span></a></div></div></section>
      <section class="panel"><div class="panel-head"><div><h2>Upcoming</h2><p>Your next HR activities</p></div></div><div class="panel-body"><div class="timeline-list"><div class="timeline-row"><div class="time">Sep 27</div><span class="timeline-dot"></span><div class="detail"><strong>Workplace Safety Training</strong><span>10:00 AM · Training Room</span></div></div><div class="timeline-row"><div class="time">Oct 02</div><span class="timeline-dot"></span><div class="detail"><strong>Performance Check-in</strong><span>With immediate supervisor</span></div></div><div class="timeline-row"><div class="time">Oct 15</div><span class="timeline-dot"></span><div class="detail"><strong>Document renewal</strong><span>Government ID record</span></div></div></div></div></section>
    </div>
    <?php render_portal_footer(); exit;
}

// -------- HR portal --------
if (str_starts_with($page,'hr-')) {
    need_db();
    if (!Auth::check()) redirect('login',['portal'=>'hr']);
    Auth::requirePortal('hr');
    Auth::requirePermission('dashboard.hr.view');
}

if ($page === 'hr-dashboard') {
    $d=RecruitmentRepository::dashboard(); $counts=RecruitmentRepository::stageCounts();
    $today=db()->query('SELECT i.*, CONCAT(ap.first_name," ",ap.last_name) applicant_name,j.title job_title FROM interviews i JOIN applications a ON a.id=i.application_id JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id WHERE DATE(i.scheduled_at)=CURDATE() ORDER BY i.scheduled_at LIMIT 6')->fetchAll();
    $first=explode(' ',trim((string)(Auth::user()['name']??'HR Team')))[0];
    $maxStage=max(1,...array_map(fn($x)=>(int)$x['total'],$counts));
    render_portal_header('hr',$page,'HR Dashboard');
    dashboard_hero('Human Resources','Good morning, '.$first.'.','Here is the recruitment activity that needs your attention today.','<a href="'.url('hr-manpower').'" class="btn">'.icon_svg('plus').' New request</a><a href="'.url('hr-pipeline').'" class="btn primary">Open recruitment pipeline '.icon_svg('arrow').'</a>'); ?>
    <div class="metric-grid"><?php metric_card('Active applicants',$d['active'],'Live recruitment pool','users','up'); metric_card('Interviews',$d['interviews'],count($today).' scheduled today','calendar'); metric_card('Client review',$d['endorsed'],'Candidates awaiting decision','check-square'); metric_card('Open headcount',$d['openReq'],'Across active manpower requests','briefcase','warn'); ?></div>
    <div class="dashboard-grid">
      <section class="panel"><div class="panel-head"><div><h2>Recruitment pulse</h2><p>Applicant distribution across your current pipeline</p></div><a class="panel-link" href="<?=url('hr-pipeline')?>">View pipeline</a></div><div class="panel-body"><div class="pulse-bars"><?php foreach($counts as $c): $h=max(8,round(((int)$c['total']/$maxStage)*145)); ?><div class="col" title="<?=e($c['name'].' · '.$c['total'])?>"><div class="bar" style="height:<?=$h?>px"></div><div class="label"><?=e(mb_substr($c['name'],0,8))?></div></div><?php endforeach;?></div><div class="legend-row"><span><i class="legend-dot" style="background:var(--hris-brand)"></i>Applicant volume by stage</span><span>Updated from live recruitment records</span></div></div></section>
      <section class="panel"><div class="panel-head"><div><h2>Requires attention</h2><p>Priority recruitment work</p></div></div><div class="panel-body"><div class="attention-list"><?php attention_item('Open manpower requirements','Unfilled approved headcount',$d['openReq'],'warn','briefcase'); attention_item('Client review','Candidates waiting for a decision',$d['endorsed'],'info','check-square'); attention_item('Interviews today','Scheduled candidate interviews',count($today),'success','calendar'); attention_item('Active applicants','Profiles currently moving through recruitment',$d['active'],'info','users'); ?></div></div></section>
    </div>
    <div class="dashboard-grid equal">
      <section class="panel"><div class="panel-head"><div><h2>Today’s interviews</h2><p>Scheduled candidate conversations</p></div><a class="panel-link" href="<?=url('hr-applicants')?>">All applicants</a></div><div class="panel-body"><?php if(!$today):?><div class="empty">No interviews are scheduled today.</div><?php else:?><div class="timeline-list"><?php foreach($today as $i):?><div class="timeline-row"><div class="time"><?=e(date('g:i A',strtotime($i['scheduled_at'])))?></div><span class="timeline-dot"></span><div class="detail"><strong><?=e($i['applicant_name'])?></strong><span><?=e($i['job_title'])?> · <?=e(stage_label($i['interview_type']))?></span></div><span class="badge amber"><?=e(stage_label($i['interview_type']))?></span></div><?php endforeach;?></div><?php endif;?></div></section>
      <section class="panel"><div class="panel-head"><div><h2>Quick actions</h2><p>Start common HR workflows</p></div></div><div class="panel-body"><div class="quick-actions"><a class="quick-action" href="<?=url('hr-manpower')?>"><span class="qa-icon"><?=icon_svg('briefcase')?></span><span><strong>Manpower request</strong><span>Create or review hiring demand</span></span></a><a class="quick-action" href="<?=url('hr-applicants')?>"><span class="qa-icon"><?=icon_svg('users')?></span><span><strong>Applicant database</strong><span>Search candidate profiles</span></span></a><a class="quick-action" href="<?=url('hr-pipeline')?>"><span class="qa-icon"><?=icon_svg('pipeline')?></span><span><strong>Recruitment pipeline</strong><span>Move candidates through stages</span></span></a><a class="quick-action" href="<?=url('hr-reports')?>"><span class="qa-icon"><?=icon_svg('chart')?></span><span><strong>Reports</strong><span>Review recruitment performance</span></span></a></div></div></section>
    </div>
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
if (str_starts_with($page,'admin-')) {
    need_db();
    if (!Auth::check()) redirect('login',['portal'=>'admin']);
    Auth::requirePortal('admin');
}

if ($page === 'admin-dashboard') {
    Auth::requirePermission('dashboard.admin.view');
    $d=RecruitmentRepository::dashboard();
    $org=FoundationRepository::organizationSummary();
    $logs=FoundationRepository::auditLogs(8);
    $first=explode(' ',trim((string)(Auth::user()['name']??'Admin')))[0];
    $foundationReady=FoundationRepository::tableExists('permissions') && FoundationRepository::tableExists('positions');
    render_portal_header('admin',$page,'HRIS Administration');
    dashboard_hero('HR Administration','Welcome back, '.$first.'.','Manage access, organization master data, security and system activity from one workspace.','<a href="'.url('admin-users').'" class="btn">'.icon_svg('users').' Manage users</a><a href="'.url('admin-organization').'" class="btn primary">Organization setup '.icon_svg('arrow').'</a>'); ?>
    <?php if(!$foundationReady):?><div class="alert error"><strong>Phase 1 migration required.</strong> Import <code>database/migrations/20260924_phase1_foundation.sql</code> in phpMyAdmin to enable permissions, positions, employment types and tracked sessions.</div><?php endif;?>
    <div class="metric-grid"><?php metric_card('Active users',$org['users'],'Role-based system access','users'); metric_card('Departments',$org['departments'],$org['positions'].' active positions','building'); metric_card('Branches & sites',$org['branches'],$org['employment_types'].' employment types','building'); metric_card('Open headcount',$d['openReq'],'Recruitment demand','briefcase','warn'); ?></div>
    <div class="dashboard-grid">
      <section class="panel"><div class="panel-head"><div><h2>System activity</h2><p>Latest auditable actions across the HRIS</p></div><a class="panel-link" href="<?=url('admin-audit')?>">Open audit logs</a></div><div class="panel-body"><div class="timeline-list"><?php foreach($logs as $l):?><div class="timeline-row"><div class="time"><?=e(date('g:i A',strtotime($l['created_at'])))?></div><span class="timeline-dot"></span><div class="detail"><strong><?=e($l['action'])?> · <?=e($l['module'])?></strong><span><?=e($l['user_name']??'System')?> · <?=e(date('M j, Y',strtotime($l['created_at'])))?></span></div><span class="mono tiny muted"><?=e(($l['record_type']??'').($l['record_id']?' #'.$l['record_id']:''))?></span></div><?php endforeach;?></div></div></section>
      <section class="panel"><div class="panel-head"><div><h2>Foundation controls</h2><p>Core HRIS administration</p></div></div><div class="panel-body"><div class="quick-actions"><a class="quick-action" href="<?=url('admin-users')?>"><span class="qa-icon"><?=icon_svg('users')?></span><span><strong>Users</strong><span>Create and control HRIS accounts</span></span></a><a class="quick-action" href="<?=url('admin-roles')?>"><span class="qa-icon"><?=icon_svg('shield')?></span><span><strong>Roles & permissions</strong><span>Control access at function level</span></span></a><a class="quick-action" href="<?=url('admin-organization')?>"><span class="qa-icon"><?=icon_svg('building')?></span><span><strong>Organization</strong><span>Departments, positions and branches</span></span></a><a class="quick-action" href="<?=url('admin-security')?>"><span class="qa-icon"><?=icon_svg('audit')?></span><span><strong>Security & sessions</strong><span>Tracked sign-ins and safeguards</span></span></a></div></div></section>
    </div>
    <?php render_portal_footer(); exit;
}

if ($page === 'admin-users') {
    Auth::requirePermission('users.view');
    $users=FoundationRepository::users(); $roles=FoundationRepository::roles();
    render_portal_header('admin',$page,'Users'); page_head('HR Admin / Access Control','Users & Access',Auth::can('users.manage')?'<span class="badge amber">Account provisioning enabled</span>':''); ?>
    <div class="foundation-layout">
      <?php if(Auth::can('users.manage')):?><form method="post" class="panel foundation-form"><?=csrf_field()?><input type="hidden" name="action" value="create_user"><div class="panel-head"><div><h2>Create user</h2><p>Provision a new HRIS account.</p></div></div><div class="panel-body"><div class="field"><label>Full name</label><input name="full_name" required placeholder="Employee or HR user name"></div><div class="field"><label>Email</label><input name="email" type="email" required placeholder="name@pmbsi.com"></div><div class="field"><label>Role</label><select name="role_id" required><option value="">Select role</option><?php foreach($roles as $r):?><option value="<?=$r['id']?>"><?=e($r['name'])?> · <?=e(strtoupper($r['portal']))?></option><?php endforeach;?></select></div><div class="field"><label>Temporary password</label><input name="password" type="password" minlength="10" required placeholder="Minimum 10 characters"></div><button class="btn primary block" style="justify-content:center">Create account</button></div></form><?php endif;?>
      <section class="panel foundation-main"><div class="panel-head"><div><h2>User accounts</h2><p><?=count($users)?> configured accounts</p></div></div><div style="overflow-x:auto"><table class="tbl"><thead><tr><th>User</th><th>Role</th><th>Portal</th><th>Status</th><th>Last login</th><?php if(Auth::can('users.manage')):?><th></th><?php endif;?></tr></thead><tbody><?php foreach($users as $u):?><tr><td><strong><?=e($u['full_name'])?></strong><div class="tiny muted"><?=e($u['email'])?></div></td><td><span class="badge gray"><?=e($u['role_name'])?></span></td><td class="tiny mono"><?=e(strtoupper($u['portal']))?></td><td><span class="badge <?=$u['status']==='ACTIVE'?'green':'gray'?>"><?=e($u['status'])?></span></td><td class="tiny muted"><?=e($u['last_login_at']?date('M j, Y g:i A',strtotime($u['last_login_at'])):'Never')?></td><?php if(Auth::can('users.manage')):?><td class="nowrap"><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle_user_status"><input type="hidden" name="user_id" value="<?=$u['id']?>"><button class="btn sm" <?=$u['id']===(Auth::user()['id']??0)?'disabled':''?>><?=$u['status']==='ACTIVE'?'Deactivate':'Activate'?></button></form></td><?php endif;?></tr><?php endforeach;?></tbody></table></div></section>
    </div>
    <?php render_portal_footer(); exit;
}

if ($page === 'admin-roles') {
    Auth::requirePermission('roles.view');
    $roles=FoundationRepository::roles(); $groups=FoundationRepository::permissionsGrouped();
    $selectedId=(int)($_GET['role']??($roles[0]['id']??0));
    $selected=null; foreach($roles as $r) if((int)$r['id']===$selectedId){$selected=$r;break;}
    if(!$selected && $roles){$selected=$roles[0];$selectedId=(int)$selected['id'];}
    $selectedCodes=$selectedId?FoundationRepository::permissionsForRole($selectedId):[];
    render_portal_header('admin',$page,'Roles & Permissions'); page_head('HR Admin / Access Control','Roles & Permissions'); ?>
    <?php if(!FoundationRepository::tableExists('permissions')):?><div class="alert error">Run <code>database/migrations/20260924_phase1_foundation.sql</code> first to enable permission management.</div><?php endif;?>
    <div class="foundation-layout">
      <div><section class="panel"><div class="panel-head"><div><h2>Roles</h2><p>Choose a role to review access.</p></div></div><div class="role-list"><?php foreach($roles as $r):?><a class="role-row <?=$selectedId===(int)$r['id']?'active':''?>" href="<?=url('admin-roles',['role'=>$r['id']])?>"><div><strong><?=e($r['name'])?></strong><span><?=e($r['code'])?> · <?=e(strtoupper($r['portal']))?></span></div><span class="badge gray"><?=e((string)$r['permission_count'])?></span></a><?php endforeach;?></div></section>
      <?php if(Auth::can('roles.manage')):?><form method="post" class="panel foundation-form compact"><?=csrf_field()?><input type="hidden" name="action" value="create_role"><div class="panel-head"><div><h2>New role</h2><p>Create a custom access role.</p></div></div><div class="panel-body"><div class="field"><label>Role code</label><input name="code" required placeholder="HR_COORDINATOR"></div><div class="field"><label>Role name</label><input name="name" required placeholder="HR Coordinator"></div><div class="field"><label>Portal</label><select name="portal"><option value="employee">Employee</option><option value="hr">HR</option><option value="admin">HR Admin</option><option value="client">Client</option></select></div><button class="btn primary block" style="justify-content:center">Create role</button></div></form><?php endif;?></div>
      <section class="panel foundation-main"><?php if($selected):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_role_permissions"><input type="hidden" name="role_id" value="<?=$selectedId?>"><div class="panel-head"><div><h2><?=e($selected['name'])?></h2><p><?=e($selected['code'])?> · <?=e(strtoupper($selected['portal']))?> portal · <?=$selected['user_count']?> user(s)</p></div><?php if(Auth::can('roles.manage')):?><button class="btn primary">Save permissions</button><?php endif;?></div><div class="permission-groups"><?php foreach($groups as $module=>$perms):?><div class="permission-group"><div class="permission-module"><?=e($module)?></div><?php foreach($perms as $perm): $checked=in_array($perm['code'],$selectedCodes,true); ?><label class="permission-row"><input type="checkbox" name="permission_ids[]" value="<?=$perm['id']?>" <?=$checked?'checked':''?> <?=Auth::can('roles.manage')?'':'disabled'?>><span><strong><?=e($perm['name'])?></strong><small><?=e($perm['description']??$perm['code'])?></small><code><?=e($perm['code'])?></code></span></label><?php endforeach;?></div><?php endforeach;?></div></form><?php else:?><div class="empty">No roles configured.</div><?php endif;?></section>
    </div>
    <?php render_portal_footer(); exit;
}

if ($page === 'admin-organization') {
    Auth::requirePermission('organization.view');
    $departments=FoundationRepository::departments(); $positions=FoundationRepository::positions(); $branches=FoundationRepository::branches(); $types=FoundationRepository::employmentTypes();
    $canManage=Auth::can('organization.manage');
    render_portal_header('admin',$page,'Organization Setup'); page_head('HR Admin / Organization','Organization Setup','<span class="badge amber">Core master data</span>'); ?>
    <?php if(!FoundationRepository::tableExists('positions')):?><div class="alert error">Phase 1 foundation migration has not been applied. Import <code>database/migrations/20260924_phase1_foundation.sql</code>.</div><?php endif;?>
    <div class="org-grid">
      <section class="panel org-card"><div class="panel-head"><div><h2>Departments</h2><p><?=count($departments)?> records</p></div></div><?php if($canManage):?><form method="post" class="master-add"><?=csrf_field()?><input type="hidden" name="action" value="create_department"><input name="code" placeholder="Code" required><input name="name" placeholder="Department name" required><button class="btn primary sm"><?=icon_svg('plus')?> Add</button></form><?php endif;?><div class="master-list"><?php foreach($departments as $x):?><div class="master-row"><div><strong><?=e($x['name'])?></strong><span><?=e($x['code'])?> · <?=$x['position_count']?> positions</span></div><div><span class="badge <?=$x['active']?'green':'gray'?>"><?=$x['active']?'Active':'Inactive'?></span><?php if($canManage):?><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle_master"><input type="hidden" name="entity" value="department"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="mini-action" title="Toggle status">•••</button></form><?php endif;?></div></div><?php endforeach;?></div></section>
      <section class="panel org-card"><div class="panel-head"><div><h2>Positions</h2><p><?=count($positions)?> records</p></div></div><?php if($canManage):?><form method="post" class="master-add master-add-4"><?=csrf_field()?><input type="hidden" name="action" value="create_position"><input name="code" placeholder="Code" required><input name="name" placeholder="Position name" required><select name="department_id"><option value="">No department</option><?php foreach($departments as $d):?><option value="<?=$d['id']?>"><?=e($d['name'])?></option><?php endforeach;?></select><button class="btn primary sm"><?=icon_svg('plus')?> Add</button></form><?php endif;?><div class="master-list"><?php foreach($positions as $x):?><div class="master-row"><div><strong><?=e($x['name'])?></strong><span><?=e($x['code'])?> · <?=e($x['department_name']??'Unassigned')?></span></div><div><span class="badge <?=$x['active']?'green':'gray'?>"><?=$x['active']?'Active':'Inactive'?></span><?php if($canManage):?><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle_master"><input type="hidden" name="entity" value="position"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="mini-action">•••</button></form><?php endif;?></div></div><?php endforeach;?></div></section>
      <section class="panel org-card"><div class="panel-head"><div><h2>Branches & Sites</h2><p><?=count($branches)?> records</p></div></div><?php if($canManage):?><form method="post" class="master-add master-add-4"><?=csrf_field()?><input type="hidden" name="action" value="create_branch"><input name="code" placeholder="Code" required><input name="name" placeholder="Branch name" required><input name="address_text" placeholder="City / address"><button class="btn primary sm"><?=icon_svg('plus')?> Add</button></form><?php endif;?><div class="master-list"><?php foreach($branches as $x):?><div class="master-row"><div><strong><?=e($x['name'])?></strong><span><?=e($x['code'])?> · <?=e($x['address_text']??'No address')?></span></div><div><span class="badge <?=$x['active']?'green':'gray'?>"><?=$x['active']?'Active':'Inactive'?></span><?php if($canManage):?><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle_master"><input type="hidden" name="entity" value="branch"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="mini-action">•••</button></form><?php endif;?></div></div><?php endforeach;?></div></section>
      <section class="panel org-card"><div class="panel-head"><div><h2>Employment Types</h2><p><?=count($types)?> records</p></div></div><?php if($canManage):?><form method="post" class="master-add"><?=csrf_field()?><input type="hidden" name="action" value="create_employment_type"><input name="code" placeholder="Code" required><input name="name" placeholder="Employment type" required><button class="btn primary sm"><?=icon_svg('plus')?> Add</button></form><?php endif;?><div class="master-list"><?php foreach($types as $x):?><div class="master-row"><div><strong><?=e($x['name'])?></strong><span><?=e($x['code'])?></span></div><div><span class="badge <?=$x['active']?'green':'gray'?>"><?=$x['active']?'Active':'Inactive'?></span><?php if($canManage):?><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle_master"><input type="hidden" name="entity" value="employment_type"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="mini-action">•••</button></form><?php endif;?></div></div><?php endforeach;?></div></section>
    </div>
    <?php render_portal_footer(); exit;
}

if ($page === 'admin-audit') {
    Auth::requirePermission('audit.view');
    $logs=FoundationRepository::auditLogs(200); render_portal_header('admin',$page,'Audit Logs'); page_head('HR Admin / Security','Audit Logs'); ?>
    <div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>Timestamp</th><th>User</th><th>Module</th><th>Action</th><th>Record</th><th>IP</th></tr></thead><tbody><?php foreach($logs as $l):?><tr><td class="mono tiny"><?=e(date('M j H:i:s',strtotime($l['created_at'])))?></td><td><?=e($l['user_name']??'Public / System')?><div class="tiny muted"><?=e($l['user_email']??'')?></div></td><td><?=e($l['module'])?></td><td><span class="badge gray"><?=e($l['action'])?></span></td><td class="mono tiny"><?=e(($l['record_type']??'').($l['record_id']?' #'.$l['record_id']:''))?></td><td class="mono tiny muted"><?=e($l['ip_address']??'—')?></td></tr><?php endforeach;?></tbody></table></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'admin-security') {
    Auth::requirePermission('audit.view');
    $sessions=FoundationRepository::sessions(100); $activeSessions=array_values(array_filter($sessions,fn($s)=>empty($s['revoked_at'])));
    render_portal_header('admin',$page,'Security & Sessions'); page_head('HR Admin / Security','Security & Sessions'); ?>
    <div class="metric-grid"><?php metric_card('Active sessions',count($activeSessions),'Tracked server-side sessions','shield'); metric_card('CSRF','Enabled','POST actions protected','check'); metric_card('Passwords','Hashed','PHP password_hash()','shield'); metric_card('Audit trail','Enabled','Security-sensitive actions logged','audit'); ?></div>
    <div class="dashboard-grid equal"><section class="panel"><div class="panel-head"><div><h2>Security baseline</h2><p>Controls already enforced in Phase 1</p></div></div><div class="panel-body"><?php foreach(['Server-side role and permission checks','CSRF verification for all write actions','PDO prepared statements','Password hashing and session ID regeneration','Tracked login sessions with revocation support','Audit logging for access-control changes'] as $x):?><div class="srow"><span class="badge green">On</span><div class="small" style="flex:1"><?=e($x)?></div></div><?php endforeach;?></div></section><section class="panel"><div class="panel-head"><div><h2>Production checklist</h2><p>Before public deployment</p></div></div><div class="panel-body"><p class="muted small">Disable debug mode, remove demo users, enforce HTTPS, use a dedicated MySQL account, enable backups, configure secure upload storage and review least-privilege role assignments.</p></div></section></div>
    <section class="panel"><div class="panel-head"><div><h2>Tracked sessions</h2><p>Latest authenticated sessions</p></div></div><?php if(!FoundationRepository::tableExists('user_sessions')):?><div class="empty">Import the Phase 1 migration to enable session tracking.</div><?php else:?><div style="overflow-x:auto"><table class="tbl"><thead><tr><th>User</th><th>Role</th><th>IP</th><th>Created</th><th>Last activity</th><th>Status</th></tr></thead><tbody><?php foreach($sessions as $x):?><tr><td><strong><?=e($x['full_name'])?></strong><div class="tiny muted"><?=e($x['email'])?></div></td><td><span class="badge gray"><?=e($x['role_name'])?></span></td><td class="mono tiny"><?=e($x['ip_address']??'—')?></td><td class="tiny muted"><?=e(date('M j g:i A',strtotime($x['created_at'])))?></td><td class="tiny muted"><?=e(date('M j g:i A',strtotime($x['last_activity_at'])))?></td><td><span class="badge <?=empty($x['revoked_at'])?'green':'gray'?>"><?=empty($x['revoked_at'])?'Active':'Revoked'?></span></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
    <?php render_portal_footer(); exit;
}

// Backward-compatible admin links from the earlier recruitment build.
if ($page === 'admin-clients' || $page === 'admin-branches') redirect('admin-organization');

if ($page === 'admin-recruitment-config') {
    Auth::requirePermission('recruitment.view');
    $stages=RecruitmentRepository::stages(); $counts=RecruitmentRepository::stageCounts(); $map=[]; foreach($counts as $c)$map[$c['code']]=$c['total'];
    render_portal_header('admin',$page,'Recruitment Configuration'); page_head('HR Admin / Recruitment','Recruitment Configuration'); ?>
    <div class="card pad"><div class="section-t">Pipeline stages</div><p class="muted small">Stage codes are stable business identifiers. Display order and visibility are stored in MySQL.</p><?php foreach($stages as $s):?><div class="srow"><span class="mono tiny muted"><?=e((string)$s['sequence_no'])?></span><div style="flex:1"><div class="small" style="font-weight:600"><?=e($s['name'])?></div><div class="tiny mono muted"><?=e($s['code'])?></div></div><span class="badge gray"><?=e((string)($map[$s['code']]??0))?> active</span><span class="badge <?=$s['client_visible']?'blue':'gray'?>">Client <?=$s['client_visible']?'visible':'hidden'?></span></div><?php endforeach;?></div>
    <?php render_portal_footer(); exit;
}

// -------- fallback --------
http_response_code(404); render_head('Page not found'); ?>
<div class="wrap" style="padding:80px 24px;text-align:center;max-width:520px"><div style="font-size:56px;font-weight:700;color:var(--amber)">404</div><h1 style="font-size:22px;margin:8px 0">Page not found</h1><p class="muted">The requested Recruitment page does not exist.</p><a href="<?=url('home')?>" class="btn primary" style="margin-top:16px">Careers site</a></div></body></html>
