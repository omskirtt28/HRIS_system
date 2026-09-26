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
        if ($action === 'convert_application_to_employee') {
            Auth::requirePermission('recruitment.manage');
            Auth::requirePermission('employees.create');
            if(empty($_POST['conversion_confirmed'])) throw new RuntimeException('Confirm that you reviewed the employee details before conversion.');
            $applicationId=(int)($_POST['application_id']??0);
            $employeeId=RecruitmentRepository::convertToEmployee($applicationId,[
                'employee_no'=>trim((string)($_POST['employee_no']??'')),
                'first_name'=>trim((string)($_POST['first_name']??'')),
                'middle_name'=>trim((string)($_POST['middle_name']??'')),
                'last_name'=>trim((string)($_POST['last_name']??'')),
                'suffix'=>trim((string)($_POST['suffix']??'')),
                'personal_email'=>trim((string)($_POST['personal_email']??'')),
                'company_email'=>trim((string)($_POST['company_email']??'')),
                'mobile_no'=>trim((string)($_POST['mobile_no']??'')),
                'department_id'=>(int)($_POST['department_id']??0),
                'position_id'=>(int)($_POST['position_id']??0),
                'branch_id'=>(int)($_POST['branch_id']??0),
                'employment_type_id'=>(int)($_POST['employment_type_id']??0),
                'hire_date'=>(string)($_POST['hire_date']??''),
                'regularization_date'=>(string)($_POST['regularization_date']??''),
                'status'=>(string)($_POST['status']??'PROBATIONARY'),
                'account_mode'=>(string)($_POST['account_mode']??'later'),
                'user_id'=>(int)($_POST['user_id']??0),
                'account_email'=>trim((string)($_POST['account_email']??'')),
                'temporary_password'=>(string)($_POST['temporary_password']??''),
                'transfer_documents'=>!empty($_POST['transfer_documents'])?1:0,
            ]);
            flash('success','Candidate converted to an employee record successfully.');
            redirect('hr-employee',['id'=>$employeeId]);
        }
        if ($action === 'create_payroll_request') {
            Auth::requirePermission('payroll.request_self');
            $employee=PayrollRepository::currentEmployee();
            if(!$employee) throw new RuntimeException('Your user account is not linked to an Employee 201 File yet. Ask HR to link your account first.');
            $id=PayrollRepository::createPayrollRequest((int)$employee['id'],[
                'request_type_id'=>(int)($_POST['request_type_id']??0),
                'cutoff_id'=>(int)($_POST['cutoff_id']??0),
                'affected_date'=>(string)($_POST['affected_date']??''),
                'reason'=>(string)($_POST['reason']??''),
                'remarks'=>(string)($_POST['remarks']??''),
                'time_in'=>(string)($_POST['time_in']??''),
                'lunch_out'=>(string)($_POST['lunch_out']??''),
                'lunch_in'=>(string)($_POST['lunch_in']??''),
                'time_out'=>(string)($_POST['time_out']??''),
                'ot_start'=>(string)($_POST['ot_start']??''),
                'ot_end'=>(string)($_POST['ot_end']??''),
                'destination'=>(string)($_POST['destination']??''),
                'purpose'=>(string)($_POST['purpose']??''),
                'original_rest_day'=>(string)($_POST['original_rest_day']??''),
                'new_rest_day'=>(string)($_POST['new_rest_day']??''),
            ],$_FILES['attachments']??[]);
            flash('success','Payroll & Timekeeping request submitted successfully.');
            redirect('employee-request',['id'=>$id]);
        }
        if ($action === 'payroll_request_decision') {
            $id=(int)($_POST['request_id']??0);
            PayrollRepository::decidePayroll($id,(string)($_POST['decision']??''),(string)($_POST['remarks']??''));
            flash('success','Request decision recorded.');
            $return=(string)($_POST['return_page']??'manager-approvals');
            redirect($return);
        }
        if ($action === 'create_leave_request') {
            Auth::requirePermission('leave.request_self');
            $employee=PayrollRepository::currentEmployee();
            if(!$employee) throw new RuntimeException('Your user account is not linked to an Employee 201 File yet. Ask HR to link your account first.');
            PayrollRepository::createLeaveRequest((int)$employee['id'],[
                'leave_type_id'=>(int)($_POST['leave_type_id']??0),
                'date_from'=>(string)($_POST['date_from']??''),
                'date_to'=>(string)($_POST['date_to']??''),
                'reason'=>(string)($_POST['reason']??''),
            ]);
            flash('success','Leave request submitted for approval.');
            redirect('employee-leave');
        }
        if ($action === 'leave_request_decision') {
            PayrollRepository::decideLeave((int)($_POST['request_id']??0),(string)($_POST['decision']??''),(string)($_POST['remarks']??''));
            flash('success','Leave decision recorded.');
            redirect((string)($_POST['return_page']??'manager-approvals'));
        }
        if ($action === 'adjust_leave_credit') {
            Auth::requirePermission('leave.credits.manage');
            PayrollRepository::adjustLeaveCredit((int)($_POST['employee_id']??0),(int)($_POST['leave_type_id']??0),(float)($_POST['amount']??0),(string)($_POST['reason']??''));
            flash('success','Leave credit adjustment saved.');
            redirect('hr-leave');
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
        if ($action === 'create_area') {
            Auth::requirePermission('organization.manage');
            FoundationRepository::createArea((string)($_POST['code']??''),(string)($_POST['name']??''));
            flash('success','Area created.'); redirect('admin-organization');
        }
        if ($action === 'create_branch') {
            Auth::requirePermission('organization.manage');
            FoundationRepository::createBranch((string)($_POST['code']??''),(string)($_POST['name']??''),(string)($_POST['address_text']??''),(int)($_POST['area_id']??0));
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
        if ($action === 'reset_user_password') {
            Auth::requireRoles(['SUPER_ADMIN']);
            $targetUserId=(int)($_POST['user_id']??0);
            $newTemporaryPassword=(string)($_POST['new_password']??'');
            FoundationRepository::resetUserPassword($targetUserId,$newTemporaryPassword);
            // Make the freshly reset temporary password available only on the immediate
            // Super Admin response page. It is never stored as plaintext in the database.
            $_SESSION['password_reset_reveal']=['user_id'=>$targetUserId,'password'=>$newTemporaryPassword];
            flash('success','Password reset successfully. Use the eye icon for that user to view or copy the new temporary password on this page. All active sessions for that account were signed out.');
            redirect('admin-users');
        }
        if ($action === 'create_employee') {
            Auth::requirePermission('employees.manage');
            $id=EmployeeRepository::create([
                'employee_no'=>(string)($_POST['employee_no']??''),
                'user_id'=>(int)($_POST['user_id']??0),
                'first_name'=>(string)($_POST['first_name']??''),
                'middle_name'=>(string)($_POST['middle_name']??''),
                'last_name'=>(string)($_POST['last_name']??''),
                'suffix'=>(string)($_POST['suffix']??''),
                'preferred_name'=>(string)($_POST['preferred_name']??''),
                'company_email'=>(string)($_POST['company_email']??''),
                'personal_email'=>(string)($_POST['personal_email']??''),
                'mobile_no'=>(string)($_POST['mobile_no']??''),
                'birth_date'=>(string)($_POST['birth_date']??''),
                'gender'=>(string)($_POST['gender']??''),
                'civil_status'=>(string)($_POST['civil_status']??''),
                'address_text'=>(string)($_POST['address_text']??''),
                'department_id'=>(int)($_POST['department_id']??0),
                'position_id'=>(int)($_POST['position_id']??0),
                'branch_id'=>(int)($_POST['branch_id']??0),
                'employment_type_id'=>(int)($_POST['employment_type_id']??0),
                'hire_date'=>(string)($_POST['hire_date']??''),
                'regularization_date'=>(string)($_POST['regularization_date']??''),
                'status'=>(string)($_POST['status']??'ACTIVE'),
            ]);
            flash('success','Employee record created successfully.');
            redirect('hr-employee',['id'=>$id]);
        }
        if ($action === 'update_employee_personal') {
            Auth::requirePermission('employees.manage');
            $id=(int)($_POST['employee_id']??0);
            EmployeeRepository::updatePersonal($id,[
                'first_name'=>(string)($_POST['first_name']??''),'middle_name'=>(string)($_POST['middle_name']??''),'last_name'=>(string)($_POST['last_name']??''),
                'suffix'=>(string)($_POST['suffix']??''),'preferred_name'=>(string)($_POST['preferred_name']??''),'birth_date'=>(string)($_POST['birth_date']??''),
                'place_of_birth'=>(string)($_POST['place_of_birth']??''),'gender'=>(string)($_POST['gender']??''),'civil_status'=>(string)($_POST['civil_status']??''),
                'nationality'=>(string)($_POST['nationality']??''),'mobile_no'=>(string)($_POST['mobile_no']??''),'personal_email'=>(string)($_POST['personal_email']??''),
                'company_email'=>(string)($_POST['company_email']??''),'address_text'=>(string)($_POST['address_text']??''),'permanent_address_text'=>(string)($_POST['permanent_address_text']??''),
            ]);
            flash('success','Personal information updated.'); redirect('hr-employee',['id'=>$id,'tab'=>'personal']);
        }
        if ($action === 'update_employee_employment') {
            Auth::requirePermission('employees.manage');
            $id=(int)($_POST['employee_id']??0);
            EmployeeRepository::updateEmployment($id,[
                'employee_no'=>(string)($_POST['employee_no']??''),'user_id'=>(int)($_POST['user_id']??0),'department_id'=>(int)($_POST['department_id']??0),
                'position_id'=>(int)($_POST['position_id']??0),'branch_id'=>(int)($_POST['branch_id']??0),'employment_type_id'=>(int)($_POST['employment_type_id']??0),'manager_employee_id'=>(int)($_POST['manager_employee_id']??0),
                'hire_date'=>(string)($_POST['hire_date']??''),'regularization_date'=>(string)($_POST['regularization_date']??''),'status'=>(string)($_POST['status']??'ACTIVE'),
                'effective_date'=>(string)($_POST['effective_date']??''),'remarks'=>(string)($_POST['remarks']??''),
            ]);
            flash('success','Employment information updated and history recorded.'); redirect('hr-employee',['id'=>$id,'tab'=>'employment']);
        }
        if ($action === 'upload_employee_photo') {
            Auth::requirePermission('employees.manage'); $id=(int)($_POST['employee_id']??0);
            EmployeeRepository::uploadProfilePhoto($id,$_FILES['profile_photo']??[]);
            flash('success','Profile photo updated.'); redirect('hr-employee',['id'=>$id]);
        }
        if ($action === 'save_government_id') {
            Auth::requirePermission('employees.manage'); $id=(int)($_POST['employee_id']??0);
            EmployeeRepository::saveGovernmentId($id,$_POST);
            flash('success','Government ID saved.'); redirect('hr-employee',['id'=>$id,'tab'=>'government']);
        }
        if ($action === 'delete_government_id') {
            Auth::requirePermission('employees.manage'); $id=(int)($_POST['employee_id']??0);
            EmployeeRepository::deleteGovernmentId($id,(int)($_POST['government_id_id']??0));
            flash('success','Government ID removed.'); redirect('hr-employee',['id'=>$id,'tab'=>'government']);
        }
        if ($action === 'save_emergency_contact') {
            Auth::requirePermission('employees.manage'); $id=(int)($_POST['employee_id']??0);
            EmployeeRepository::saveEmergencyContact($id,$_POST);
            flash('success','Emergency contact saved.'); redirect('hr-employee',['id'=>$id,'tab'=>'emergency']);
        }
        if ($action === 'delete_emergency_contact') {
            Auth::requirePermission('employees.manage'); $id=(int)($_POST['employee_id']??0);
            EmployeeRepository::deleteEmergencyContact($id,(int)($_POST['contact_id']??0));
            flash('success','Emergency contact removed.'); redirect('hr-employee',['id'=>$id,'tab'=>'emergency']);
        }
        if ($action === 'upload_employee_document') {
            Auth::requirePermission('employees.manage'); $id=(int)($_POST['employee_id']??0);
            EmployeeRepository::uploadDocument($id,$_FILES['document_file']??[],$_POST);
            flash('success','Employee document uploaded.'); redirect('hr-employee',['id'=>$id,'tab'=>'documents']);
        }
        if ($action === 'delete_employee_document') {
            Auth::requirePermission('employees.manage'); $id=(int)($_POST['employee_id']??0);
            EmployeeRepository::deleteDocument($id,(int)($_POST['document_id']??0));
            flash('success','Employee document deleted.'); redirect('hr-employee',['id'=>$id,'tab'=>'documents']);
        }
    } catch(Throwable $e) {
        flash('error',$e->getMessage());
        $defaultBack = match($action) {
            'create_department','create_position','create_area','create_branch','create_employment_type','toggle_master' => 'admin-organization',
            'create_role','save_role_permissions' => 'admin-roles',
            'create_user','toggle_user_status','reset_user_password' => 'admin-users',
            'create_employee' => 'hr-employee-new',
            'create_payroll_request' => 'employee-request-new',
            'payroll_request_decision' => 'manager-approvals',
            'create_leave_request' => 'employee-leave',
            'leave_request_decision' => 'manager-approvals',
            'adjust_leave_credit' => 'hr-leave',
            'update_employee_personal','update_employee_employment','upload_employee_photo','save_government_id','delete_government_id','save_emergency_contact','delete_emergency_contact','upload_employee_document','delete_employee_document' => 'hr-employee',
            default => 'home',
        };
        $back=(string)($_POST['return_page']??$defaultBack); $params=[]; if(!empty($_POST['return_id']))$params['id']=(int)$_POST['return_id']; if(!empty($_POST['return_tab']))$params['tab']=(string)$_POST['return_tab'];
        redirect($back,$params);
    }
}


// -------- secure employee file streams --------
if ($page === 'hr-employee-photo') {
    need_db(); Auth::requirePermission('employees.view_all');
    $employeeId=(int)($_GET['id']??0); $photo=EmployeeRepository::profilePhoto($employeeId);
    if(!$photo){http_response_code(404);exit('Photo not found');}
    $path=__DIR__.'/storage/employee_photos/'.basename((string)$photo['stored_name']);
    if(!is_file($path)){http_response_code(404);exit('Photo file not found');}
    header('Content-Type: '.($photo['mime_type']?:'application/octet-stream'));
    header('Content-Length: '.filesize($path)); header('Cache-Control: private, max-age=300');
    readfile($path); exit;
}
if ($page === 'hr-employee-document') {
    need_db(); Auth::requirePermission('employees.view_all');
    $employeeId=(int)($_GET['employee_id']??0); $documentId=(int)($_GET['id']??0); $doc=EmployeeRepository::document($employeeId,$documentId);
    if(!$doc){http_response_code(404);exit('Document not found');}
    $path=__DIR__.'/storage/employee_documents/'.basename((string)$doc['stored_name']);
    if(!is_file($path)){http_response_code(404);exit('Document file not found');}
    $inline=in_array((string)$doc['mime_type'],['application/pdf','image/jpeg','image/png','image/webp'],true);
    $name=str_replace(["\r","\n",'"'],['','',''],(string)$doc['original_name']);
    header('Content-Type: '.($doc['mime_type']?:'application/octet-stream'));
    header('Content-Length: '.filesize($path)); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.$name.'"');
    readfile($path); exit;
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
            <aside><div class="home-window-brand"><img src="public/assets/branding/pmbsi-logo-transparent-v2.png" alt="Prime Mover Business Solutions, Inc."><b>PMBSI HRIS</b></div><i class="active"></i><i></i><i></i><i></i><i></i><i></i></aside>
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
        'hr' => ['Human Resources','Recruitment, people operations and HR services in one secure workspace.',''],
        'employee' => ['Employee Self-Service','Your workday, requests and HR services in one modern workspace.',''],
        default => ['PMBSI HRIS','Your workday, HR services and workforce tools in one modern workspace.',''],
    };
    render_head($meta[0]); render_flashes(); ?>
    <div class="loginwrap">
      <div class="login-hero">
        <a href="<?=url('home')?>" class="brand brand-official brand-official-login" style="position:relative;z-index:2" aria-label="Prime Mover Business Solutions, Inc."><img src="public/assets/branding/pmbsi-logo-transparent-v2.png" alt="Prime Mover Business Solutions, Inc." class="brand-full-logo"></a>
        <div class="login-copy"><span class="login-kicker">Secure HR workspace</span><h1>People operations,<br>designed for how teams work now.</h1><p>One HRIS experience for employees, HR teams and administrators — with role-based access, clear workflows and a modern responsive interface.</p><div class="login-benefits"><div class="login-benefit"><span class="login-benefit-icon"><?=icon_svg('users')?></span><div><strong>Employee self-service</strong><span>Attendance, leave, requests and profile access.</span></div></div><div class="login-benefit"><span class="login-benefit-icon"><?=icon_svg('briefcase')?></span><div><strong>HR operations</strong><span>Recruitment, workforce actions and reporting.</span></div></div><div class="login-benefit"><span class="login-benefit-icon"><?=icon_svg('lock')?></span><div><strong>Role-based access</strong><span>Each user only sees tools relevant to their role.</span></div></div><div class="login-benefit"><span class="login-benefit-icon"><?=icon_svg('shield')?></span><div><strong>Audit-ready</strong><span>Security controls and traceable system activity.</span></div></div></div></div>
        <div class="tiny login-hero-foot">Prime Mover Business Solutions, Inc. · Human Resources Information System</div>
      </div>
      <div class="login-form"><form class="login-card" method="post"><?=csrf_field()?><input type="hidden" name="action" value="login"><?php if($portal!==''):?><input type="hidden" name="portal" value="<?=e($portal)?>"><?php endif;?>
        <div><h2>Welcome back</h2><div class="login-sub"><?=e($meta[1])?></div></div>
        <div class="field"><label>Email address</label><input type="email" name="email" required value="<?=e($meta[2])?>" autocomplete="username"></div>
        <div class="field"><label>Password</label><input type="password" name="password" required value="" autocomplete="current-password"></div>
        <button class="btn primary block" style="height:44px;justify-content:center">Sign in <?=icon_svg('arrow')?></button>
        <div class="login-footnote">Your dashboard is selected automatically based on your account role.</div>
        <div style="text-align:center;margin-top:14px"><a href="<?=url('home')?>" class="small muted">← Back to PMBSI Careers</a></div>
      </form></div>
    </div></body></html>
    <?php exit;
}

// -------- Phase 3A shared request routes --------
if ($page === 'payroll-request') {
    need_db(); if(!Auth::check()) redirect('login');
    if(!PayrollRepository::ready()) throw new RuntimeException('Import the Phase 3A database migration first.');
    $id=(int)($_GET['id']??0); $r=PayrollRepository::payrollRequest($id); if(!$r){http_response_code(404);exit('Request not found');}
    $employee=PayrollRepository::currentEmployee(); $own=$employee && (int)$employee['id']===(int)$r['employee_id'];
    $canManager=false; foreach($r['approvals'] as $a){if($a['approver_type']==='MANAGER' && (int)($a['approver_user_id']??0)===(int)(Auth::user()['id']??0)){$canManager=true;break;}}
    $canHr=Auth::can('payroll.approve_hr')||Auth::can('payroll.process')||Auth::is('SUPER_ADMIN','HRIS_ADMIN');
    if(!$own&&!$canManager&&!$canHr){http_response_code(403);exit('Access denied');}
    $kind=in_array((string)(Auth::user()['role_portal']??''),['hr','admin'],true)?'hr':'employee';
    $active=$kind==='hr'?'hr-timekeeping':'employee-requests';
    render_portal_header($kind,$active,'Request '.$r['request_no']);
    page_head(($kind==='hr'?'HR Portal':'Employee Self-Service').' / Requests','Request '.$r['request_no'],'<a class="btn" href="'.url($kind==='hr'?'hr-timekeeping':'employee-requests').'">Back to requests</a>'); ?>
    <div class="phase3a-detail-grid">
      <section class="panel"><div class="panel-head"><div><h2><?=e($r['type_name'])?></h2><p><?=e(date('M j, Y',strtotime($r['affected_date'])))?> · <?=e($r['employee_no'].' · '.trim($r['first_name'].' '.$r['last_name']))?></p></div><span class="badge amber"><?=e(stage_label($r['status']))?></span></div><div class="panel-body phase3a-detail-list">
        <div><span>Department</span><strong><?=e($r['department_name']??'Unassigned')?></strong></div><div><span>Branch / Site</span><strong><?=e($r['branch_name']??'Unassigned')?></strong></div>
        <?php if(!empty($r['cutoff_start'])):?><div><span>Payroll cutoff</span><strong><?=e(date('M j',strtotime($r['cutoff_start'])))?> – <?=e(date('M j, Y',strtotime($r['cutoff_end'])))?></strong></div><?php endif;?>
        <div class="full"><span>Reason</span><strong><?=nl2br(e($r['reason']))?></strong></div>
        <?php $t=$r['time']; foreach(['time_in'=>'Time In','lunch_out'=>'Lunch Out','lunch_in'=>'Lunch In','time_out'=>'Time Out','ot_start'=>'OT Start','ot_end'=>'OT End','destination'=>'Destination','purpose'=>'Purpose','original_rest_day'=>'Original Rest Day','new_rest_day'=>'New Rest Day'] as $k=>$label): if(empty($t[$k]))continue;?><div><span><?=e($label)?></span><strong><?=str_contains($k,'time')||str_contains($k,'out')||str_contains($k,'in')?e(date('g:i A',strtotime($t[$k]))):e($t[$k])?></strong></div><?php endforeach;?>
      </div></section>
      <section class="panel"><div class="panel-head"><div><h2>Approval timeline</h2><p>Every decision is recorded.</p></div></div><div class="panel-body phase3a-approval-list"><?php foreach($r['approvals'] as $a):?><div class="phase3a-approval-step <?=$a['status']==='APPROVED'?'done':($a['status']==='PENDING'?'current':'') ?>"><span class="phase3a-step-dot"></span><div><strong><?=e(stage_label($a['approver_type']))?></strong><span><?=e(stage_label($a['status']))?><?=!empty($a['acted_by_name'])?' · '.e($a['acted_by_name']):(!empty($a['assigned_name'])?' · '.e($a['assigned_name']):'')?></span><?php if(!empty($a['acted_at'])):?><small><?=e(date('M j, Y g:i A',strtotime($a['acted_at'])))?></small><?php endif;?></div></div><?php endforeach;?></div></section>
    </div>
    <?php if($r['attachments']):?><section class="panel phase3a-section"><div class="panel-head"><div><h2>Supporting documents</h2><p><?=count($r['attachments'])?> attachment<?=count($r['attachments'])===1?'':'s'?></p></div></div><div class="panel-body phase3a-files"><?php foreach($r['attachments'] as $a):?><a class="phase3a-file" href="<?=url('payroll-attachment',['id'=>$a['id']])?>" target="_blank"><?=icon_svg('file')?><span><strong><?=e($a['original_name'])?></strong><small><?=e(strtoupper($a['mime_type']))?></small></span></a><?php endforeach;?></div></section><?php endif;?>
    <?php render_portal_footer(); exit;
}

if ($page === 'payroll-attachment') {
    need_db();if(!Auth::check())redirect('login');$id=(int)($_GET['id']??0);
    $st=db()->prepare('SELECT a.*,pr.employee_id FROM payroll_request_attachments a JOIN payroll_requests pr ON pr.id=a.request_id WHERE a.id=?');$st->execute([$id]);$a=$st->fetch();if(!$a){http_response_code(404);exit('File not found');}
    $emp=PayrollRepository::currentEmployee();$allowed=$emp&&(int)$emp['id']===(int)$a['employee_id'];
    if(!$allowed){$r=PayrollRepository::payrollRequest((int)$a['request_id']);foreach($r['approvals']??[] as $ap){if((int)($ap['approver_user_id']??0)===(int)(Auth::user()['id']??0))$allowed=true;}$allowed=$allowed||Auth::can('payroll.approve_hr')||Auth::can('payroll.process')||Auth::is('SUPER_ADMIN','HRIS_ADMIN');}
    if(!$allowed){http_response_code(403);exit('Access denied');}$path=__DIR__.'/storage/payroll_requests/'.$a['stored_name'];if(!is_file($path)){http_response_code(404);exit('File missing');}
    header('Content-Type: '.$a['mime_type']);header('Content-Disposition: inline; filename="'.rawurlencode($a['original_name']).'"');header('X-Content-Type-Options: nosniff');readfile($path);exit;
}

// -------- Employee portal --------
if (str_starts_with($page,'employee-')) {
    need_db(); if (!Auth::check()) redirect('login'); Auth::requireRoles(['EMPLOYEE','MANAGER','SUPER_ADMIN','HRIS_ADMIN']);
}

if ($page === 'employee-dashboard') {
    $u=Auth::user();
    $employee=PayrollRepository::ready()?PayrollRepository::currentEmployee():null;
    $leaveBalances=$employee?PayrollRepository::leaveBalances((int)$employee['id']):[];
    $displayBalances=array_slice($leaveBalances,0,2);
    $recentRequests=$employee?array_slice(PayrollRepository::myPayrollRequests((int)$employee['id']),0,3):[];
    render_portal_header('employee',$page,'Employee Home');
    dashboard_hero('Employee self-service','Good morning, '.explode(' ',trim((string)$u['name']))[0].'.','Here is your personal HR workspace for today.'); ?>
    <div class="employee-focus">
      <section class="panel today-card"><div class="panel-head"><div><h2>Today</h2><p><?=e(date('l · F j, Y'))?></p></div><span class="status-pill">Attendance</span></div><div class="today-time"><div><div class="big phase3a-no-time">No time record yet</div><div class="smallline">Attendance & scheduling records will connect here in Phase 3B.</div></div><div style="text-align:right"><div class="smallline">Work location</div><strong style="font-size:13px"><?=e($employee['branch_name']??'Not assigned')?></strong></div></div></section>
      <section class="panel"><div class="panel-head"><div><h2>Leave balance</h2><p>Available credits</p></div><a class="panel-link" href="<?=url('employee-leave')?>">View details</a></div><div class="panel-body"><?php if(!$employee):?><div class="empty phase3a-dashboard-empty">Link your Employee 201 File to view leave credits.</div><?php elseif(!$displayBalances):?><div class="empty phase3a-dashboard-empty">No leave credits have been assigned yet.</div><?php else:?><div class="balance-grid"><?php foreach($displayBalances as $b):?><div class="balance-card"><strong><?=number_format((float)$b['balance'],2)?></strong><span><?=e($b['name'])?></span><?php if((float)$b['pending']>0):?><small><?=number_format((float)$b['pending'],2)?> pending</small><?php endif;?></div><?php endforeach;?></div><?php endif;?></div></section>
    </div>
    <div class="dashboard-grid equal">
      <section class="panel"><div class="panel-head"><div><h2>Quick actions</h2><p>Common employee requests</p></div></div><div class="panel-body"><div class="quick-actions"><a class="quick-action" href="<?=url('employee-leave')?>"><span class="qa-icon"><?=icon_svg('calendar')?></span><span><strong>Apply for leave</strong><span>View credits and submit leave</span></span></a><a class="quick-action" href="<?=url('employee-request-new')?>"><span class="qa-icon"><?=icon_svg('clock')?></span><span><strong>Payroll & Timekeeping</strong><span>TA, OB, OT and attendance adjustments</span></span></a><a class="quick-action" href="#"><span class="qa-icon"><?=icon_svg('file')?></span><span><strong>Request COE</strong><span>Employment document request</span></span></a><a class="quick-action" href="<?=url('employee-requests')?>"><span class="qa-icon"><?=icon_svg('clipboard')?></span><span><strong>My requests</strong><span>Track submitted requests</span></span></a></div></div></section>
      <section class="panel"><div class="panel-head"><div><h2>Recent requests</h2><p>Your latest Payroll & Timekeeping activity</p></div><a class="panel-link" href="<?=url('employee-requests')?>">View all</a></div><div class="panel-body"><?php if(!$recentRequests):?><div class="empty phase3a-dashboard-empty">No requests submitted yet.</div><?php else:?><div class="timeline-list"><?php foreach($recentRequests as $r):?><div class="timeline-row"><div class="time"><?=e(date('M j',strtotime($r['created_at'])))?></div><span class="timeline-dot"></span><div class="detail"><strong><?=e($r['type_name'])?></strong><span><?=e($r['request_no'])?></span></div><span class="phase3a-status"><?=e(stage_label($r['status']))?></span></div><?php endforeach;?></div><?php endif;?></div></section>
    </div>
    <?php render_portal_footer(); exit;
}

if ($page === 'employee-requests') {
    Auth::requirePermission('payroll.request_self');
    if(!PayrollRepository::ready()){$employee=null;$requests=[];$types=[];$cutoffs=[];}else{$employee=PayrollRepository::currentEmployee();PayrollRepository::ensureCurrentCutoff();$requests=$employee?PayrollRepository::myPayrollRequests((int)$employee['id']):[];$types=PayrollRepository::requestTypes();$cutoffs=PayrollRepository::openCutoffs();}
    render_portal_header('employee',$page,'Payroll & Timekeeping');
    page_head('Employee Self-Service / Requests','Payroll & Timekeeping','<a class="btn primary" href="'.url('employee-request-new').'">'.icon_svg('plus').' New request</a>'); ?>
    <?php if(!PayrollRepository::ready()):?><div class="alert error">Phase 3A database migration is required before Payroll & Timekeeping Requests can be used.</div><?php elseif(!$employee):?><div class="alert error">Your account is not linked to an Employee 201 File. Ask HR to link your user account under Employee → Employment before filing requests.</div><?php endif;?>
    <div class="phase3a-summary-grid"><div class="phase3a-summary-card"><span>All requests</span><strong><?=count($requests)?></strong></div><div class="phase3a-summary-card"><span>Pending approval</span><strong><?=count(array_filter($requests,fn($x)=>str_contains((string)$x['status'],'FOR_')))?></strong></div><div class="phase3a-summary-card"><span>Completed</span><strong><?=count(array_filter($requests,fn($x)=>$x['status']==='COMPLETED'))?></strong></div></div>
    <section class="panel"><div class="panel-head"><div><h2>My requests</h2><p>Track TA, OB, OT and payroll/timekeeping adjustments.</p></div></div><div class="phase3a-table-wrap"><table class="phase3a-table"><thead><tr><th>Request</th><th>Type</th><th>Affected date</th><th>Cutoff</th><th>Status</th><th>Filed</th><th></th></tr></thead><tbody><?php if(!$requests):?><tr><td colspan="7" class="empty">No Payroll & Timekeeping requests yet.</td></tr><?php endif;foreach($requests as $r):?><tr><td><strong><?=e($r['request_no'])?></strong></td><td><?=e($r['type_name'])?></td><td><?=e(date('M j, Y',strtotime($r['affected_date'])))?></td><td><?=!empty($r['cutoff_start'])?e(date('M j',strtotime($r['cutoff_start'])).' – '.date('M j',strtotime($r['cutoff_end']))):'—'?></td><td><span class="phase3a-status"><?=e(stage_label($r['status']))?></span></td><td><?=e(date('M j, g:i A',strtotime($r['created_at'])))?></td><td><a class="btn sm" href="<?=url('payroll-request',['id'=>$r['id']])?>">View</a></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php render_portal_footer(); exit;
}

if ($page === 'employee-request-new') {
    Auth::requirePermission('payroll.request_self');
    if(!PayrollRepository::ready())throw new RuntimeException('Import the Phase 3A database migration first.');
    $employee=PayrollRepository::currentEmployee();PayrollRepository::ensureCurrentCutoff();$types=PayrollRepository::requestTypes();$cutoffs=PayrollRepository::openCutoffs();
    render_portal_header('employee','employee-requests','New Payroll & Timekeeping Request');
    page_head('Employee Self-Service / Requests','New Payroll & Timekeeping Request','<a class="btn" href="'.url('employee-requests').'">Cancel</a>'); ?>
    <?php if(!$employee):?><div class="alert error">Your account is not linked to an Employee 201 File. HR must link your account before you can file a request.</div><?php else:?><div class="phase3a-form-layout"><section class="panel"><div class="panel-head"><div><h2>Request details</h2><p>The form changes based on the request type you select.</p></div><span class="badge amber">Employee request</span></div><form method="post" enctype="multipart/form-data" class="panel-body phase3a-request-form" id="payrollRequestForm"><?=csrf_field()?><input type="hidden" name="action" value="create_payroll_request">
      <div class="phase3a-employee-banner"><span class="employee-avatar"><?=e(initials(EmployeeRepository::fullName($employee)))?></span><div><strong><?=e(EmployeeRepository::fullName($employee))?></strong><span><?=e($employee['employee_no'])?> · <?=e($employee['department_name']??'No department')?> · <?=e($employee['branch_name']??'No branch')?><?=!empty($employee['area_name'])?' · '.e($employee['area_name']):''?></span></div></div>
      <div class="phase3a-form-grid"><div class="field"><label>Request type <span class="req">*</span></label><select name="request_type_id" id="phase3aRequestType" required><option value="">Select request type</option><?php foreach($types as $t):?><option value="<?=$t['id']?>" data-code="<?=e($t['code'])?>" data-attachment="<?=$t['requires_attachment']?>"><?=e($t['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Affected date <span class="req">*</span></label><input type="date" name="affected_date" required></div>
      <div class="field"><label>Payroll cutoff</label><select name="cutoff_id"><option value="">Not applicable / Auto</option><?php foreach($cutoffs as $c):?><option value="<?=$c['id']?>"><?=e(date('M j',strtotime($c['period_start'])).' – '.date('M j, Y',strtotime($c['period_end'])))?> · <?=e(stage_label($c['status']))?></option><?php endforeach;?></select></div><div class="field"><label>Immediate Manager</label><input value="<?=e(trim(($employee['manager_first_name']??'').' '.($employee['manager_last_name']??''))?:'Not configured')?>" readonly></div></div>
      <div class="phase3a-dynamic-block" data-block="TA PTA"><h3>Time adjustment</h3><p>Enter only the time fields that need correction.</p><div class="phase3a-time-grid"><div class="field"><label>Time In</label><input type="time" name="time_in"></div><div class="field"><label>Lunch Out</label><input type="time" name="lunch_out"></div><div class="field"><label>Lunch In</label><input type="time" name="lunch_in"></div><div class="field"><label>Time Out</label><input type="time" name="time_out"></div></div></div>
      <div class="phase3a-dynamic-block" data-block="OT POT"><h3>Overtime details</h3><div class="phase3a-time-grid"><div class="field"><label>OT Start</label><input type="time" name="ot_start"></div><div class="field"><label>OT End</label><input type="time" name="ot_end"></div></div></div>
      <div class="phase3a-dynamic-block" data-block="OB POB"><h3>Official business details</h3><div class="phase3a-form-grid"><div class="field"><label>Destination / Location</label><input name="destination" placeholder="Work location / destination"></div><div class="field full"><label>Purpose</label><textarea name="purpose" rows="3" placeholder="Purpose of official business"></textarea></div></div></div>
      <div class="phase3a-dynamic-block" data-block="CHANGE_DAY_OFF"><h3>Rest day adjustment</h3><div class="phase3a-time-grid"><div class="field"><label>Original rest day</label><input type="date" name="original_rest_day"></div><div class="field"><label>New rest day</label><input type="date" name="new_rest_day"></div></div></div>
      <div class="field"><label>Reason <span class="req">*</span></label><textarea name="reason" rows="4" required placeholder="Explain the adjustment clearly."></textarea></div><div class="field"><label>Supporting attachments</label><input type="file" name="attachments[]" multiple accept="application/pdf,image/jpeg,image/png,image/webp"><small>Up to 5 files · PDF, JPG, PNG or WEBP · max 10 MB each. TA/OB evidence should be clear and readable.</small></div><div class="field"><label>Additional remarks</label><textarea name="remarks" rows="3"></textarea></div>
      <div class="phase3a-submit-bar"><div><strong>Approval route</strong><span>Immediate Manager → HR Timekeeping<?=in_array('OT',array_column($types,'code'),true)?' → Payroll when applicable':''?></span></div><button class="btn primary" type="submit">Submit request <?=icon_svg('arrow')?></button></div>
    </form></section><aside class="panel phase3a-help"><div class="panel-head"><div><h2>Before submitting</h2><p>Request evidence guide</p></div></div><div class="panel-body"><div class="phase3a-help-item"><strong>Time Adjustment</strong><span>CCTV / attendance proof for Time In or Time Out. Verified logbook for lunch corrections.</span></div><div class="phase3a-help-item"><strong>Official Business</strong><span>Attach a clear Travel Report or supporting OB document.</span></div><div class="phase3a-help-item"><strong>Approval</strong><span>Your manager approves first. HR Timekeeping receives the request only after manager approval.</span></div></div></aside></div>
    <script>(()=>{const sel=document.getElementById('phase3aRequestType');const blocks=[...document.querySelectorAll('[data-block]')];const sync=()=>{const opt=sel.options[sel.selectedIndex];const code=opt?.dataset.code||'';blocks.forEach(b=>{b.hidden=!b.dataset.block.split(' ').includes(code)});};sel.addEventListener('change',sync);sync();})();</script><?php endif;?>
    <?php render_portal_footer(); exit;
}

if ($page === 'employee-leave') {
    Auth::requirePermission('leave.view_self');$employee=PayrollRepository::ready()?PayrollRepository::currentEmployee():null;$balances=$employee?PayrollRepository::leaveBalances((int)$employee['id']):[];$history=$employee?PayrollRepository::leaveTransactions((int)$employee['id']):[];$requests=$employee?PayrollRepository::myLeaveRequests((int)$employee['id']):[];$types=PayrollRepository::leaveTypes();
    render_portal_header('employee',$page,'Leave');page_head('Employee Self-Service / Leave','Leave & Credits'); ?>
    <?php if(!PayrollRepository::ready()):?><div class="alert error">Import the Phase 3A database migration to activate leave credits and leave requests.</div><?php elseif(!$employee):?><div class="alert error">Your account is not linked to an Employee 201 File yet.</div><?php else:?><div class="phase3a-leave-balance-grid"><?php foreach($balances as $b):?><div class="phase3a-leave-card"><span><?=e($b['name'])?></span><strong><?=number_format((float)$b['balance'],2)?></strong><small><?=number_format((float)$b['pending'],2)?> pending</small></div><?php endforeach;?></div>
    <div class="phase3a-leave-layout"><section class="panel"><div class="panel-head"><div><h2>Apply for leave</h2><p>Manager approval followed by HR Leave approval.</p></div></div><form method="post" class="panel-body"><?=csrf_field()?><input type="hidden" name="action" value="create_leave_request"><div class="field"><label>Leave type</label><select name="leave_type_id" required><option value="">Select leave type</option><?php foreach($types as $t):?><option value="<?=$t['id']?>"><?=e($t['name'])?></option><?php endforeach;?></select></div><div class="split"><div class="field"><label>From</label><input type="date" name="date_from" required></div><div class="field"><label>To</label><input type="date" name="date_to" required></div></div><div class="field"><label>Reason</label><textarea name="reason" rows="4" required></textarea></div><button class="btn primary">Submit leave request</button></form></section><section class="panel"><div class="panel-head"><div><h2>Leave history</h2><p>Current and previous requests.</p></div></div><div class="phase3a-list"><?php if(!$requests):?><div class="empty">No leave requests yet.</div><?php endif;foreach($requests as $r):?><div class="phase3a-list-row"><div><strong><?=e($r['leave_type_name'])?></strong><span><?=e(date('M j',strtotime($r['date_from'])))?> – <?=e(date('M j, Y',strtotime($r['date_to'])))?> · <?=e((string)$r['days'])?> day(s)</span></div><span class="phase3a-status"><?=e(stage_label($r['status']))?></span></div><?php endforeach;?></div></section></div>
    <section class="panel phase3a-section"><div class="panel-head"><div><h2>Leave credit transactions</h2><p>Every credit and deduction is traceable.</p></div></div><div class="phase3a-table-wrap"><table class="phase3a-table"><thead><tr><th>Date</th><th>Leave type</th><th>Transaction</th><th>Amount</th><th>Reason</th></tr></thead><tbody><?php if(!$history):?><tr><td colspan="5" class="empty">No leave credit transactions yet.</td></tr><?php endif;foreach($history as $h):?><tr><td><?=e(date('M j, Y',strtotime($h['created_at'])))?></td><td><?=e($h['leave_type_name'])?></td><td><?=e(stage_label($h['transaction_type']))?></td><td class="mono"><?=((float)$h['amount']>0?'+':'')?><?=number_format((float)$h['amount'],2)?></td><td><?=e($h['reason'])?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
    <?php render_portal_footer(); exit;
}

if ($page === 'manager-approvals') {
    Auth::requirePermission('payroll.approve_manager');$payroll=PayrollRepository::managerQueue();$leave=PayrollRepository::leaveQueue('MANAGER');render_portal_header('employee',$page,'Team Approvals');page_head('Employee Self-Service / My Team','Pending Approvals'); ?>
    <div class="phase3a-approval-grid"><section class="panel"><div class="panel-head"><div><h2>Payroll & Timekeeping</h2><p><?=count($payroll)?> request<?=count($payroll)===1?'':'s'?> waiting for you.</p></div></div><div class="phase3a-list"><?php if(!$payroll):?><div class="empty">No pending payroll/timekeeping requests.</div><?php endif;foreach($payroll as $r):?><div class="phase3a-approval-row"><div><strong><?=e($r['first_name'].' '.$r['last_name'])?></strong><span><?=e($r['type_name'])?> · <?=e(date('M j, Y',strtotime($r['affected_date'])))?></span></div><div class="phase3a-row-actions"><a class="btn sm" href="<?=url('payroll-request',['id'=>$r['id']])?>">Review</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="payroll_request_decision"><input type="hidden" name="request_id" value="<?=$r['id']?>"><input type="hidden" name="decision" value="APPROVE"><input type="hidden" name="return_page" value="manager-approvals"><button class="btn primary sm">Approve</button></form></div></div><?php endforeach;?></div></section>
    <section class="panel"><div class="panel-head"><div><h2>Leave</h2><p><?=count($leave)?> leave request<?=count($leave)===1?'':'s'?> waiting for you.</p></div></div><div class="phase3a-list"><?php if(!$leave):?><div class="empty">No pending leave requests.</div><?php endif;foreach($leave as $r):?><div class="phase3a-approval-row"><div><strong><?=e($r['first_name'].' '.$r['last_name'])?></strong><span><?=e($r['leave_type_name'])?> · <?=e(date('M j',strtotime($r['date_from'])))?> – <?=e(date('M j',strtotime($r['date_to'])))?></span></div><div class="phase3a-row-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="leave_request_decision"><input type="hidden" name="request_id" value="<?=$r['id']?>"><input type="hidden" name="decision" value="APPROVE"><input type="hidden" name="return_page" value="manager-approvals"><button class="btn primary sm">Approve</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="leave_request_decision"><input type="hidden" name="request_id" value="<?=$r['id']?>"><input type="hidden" name="decision" value="REJECT"><input type="hidden" name="return_page" value="manager-approvals"><button class="btn sm">Reject</button></form></div></div><?php endforeach;?></div></section></div>
    <?php render_portal_footer();exit;
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
    dashboard_hero('Human Resources','Good morning, '.$first.'.','Here is the workforce and recruitment activity that needs your attention today.','<a href="'.url('hr-employees').'" class="btn">'.icon_svg('users').' Employee directory</a><a href="'.url('hr-pipeline').'" class="btn primary">Open recruitment pipeline '.icon_svg('arrow').'</a>'); ?>
    <div class="metric-grid"><?php metric_card('Active applicants',$d['active'],'Live recruitment pool','users','up'); metric_card('Interviews',$d['interviews'],count($today).' scheduled today','calendar'); metric_card('Client review',$d['endorsed'],'Candidates awaiting decision','check-square'); metric_card('Open headcount',$d['openReq'],'Across active manpower requests','briefcase','warn'); ?></div>
    <div class="dashboard-grid">
      <section class="panel"><div class="panel-head"><div><h2>Recruitment pulse</h2><p>Applicant distribution across your current pipeline</p></div><a class="panel-link" href="<?=url('hr-pipeline')?>">View pipeline</a></div><div class="panel-body"><div class="pulse-bars"><?php foreach($counts as $c): $h=max(8,round(((int)$c['total']/$maxStage)*145)); ?><div class="col" title="<?=e($c['name'].' · '.$c['total'])?>"><div class="bar" style="height:<?=$h?>px"></div><div class="label"><?=e(mb_substr($c['name'],0,8))?></div></div><?php endforeach;?></div><div class="legend-row"><span><i class="legend-dot" style="background:var(--hris-brand)"></i>Applicant volume by stage</span><span>Updated from live recruitment records</span></div></div></section>
      <section class="panel"><div class="panel-head"><div><h2>Requires attention</h2><p>Priority recruitment work</p></div></div><div class="panel-body"><div class="attention-list"><?php attention_item('Open manpower requirements','Unfilled approved headcount',$d['openReq'],'warn','briefcase'); attention_item('Client review','Candidates waiting for a decision',$d['endorsed'],'info','check-square'); attention_item('Interviews today','Scheduled candidate interviews',count($today),'success','calendar'); attention_item('Active applicants','Profiles currently moving through recruitment',$d['active'],'info','users'); ?></div></div></section>
    </div>
    <div class="dashboard-grid equal">
      <section class="panel"><div class="panel-head"><div><h2>Today’s interviews</h2><p>Scheduled candidate conversations</p></div><a class="panel-link" href="<?=url('hr-applicants')?>">All applicants</a></div><div class="panel-body"><?php if(!$today):?><div class="empty">No interviews are scheduled today.</div><?php else:?><div class="timeline-list"><?php foreach($today as $i):?><div class="timeline-row"><div class="time"><?=e(date('g:i A',strtotime($i['scheduled_at'])))?></div><span class="timeline-dot"></span><div class="detail"><strong><?=e($i['applicant_name'])?></strong><span><?=e($i['job_title'])?> · <?=e(stage_label($i['interview_type']))?></span></div><span class="badge amber"><?=e(stage_label($i['interview_type']))?></span></div><?php endforeach;?></div><?php endif;?></div></section>
      <section class="panel"><div class="panel-head"><div><h2>Quick actions</h2><p>Start common HR workflows</p></div></div><div class="panel-body"><div class="quick-actions"><a class="quick-action" href="<?=url('hr-employees')?>"><span class="qa-icon"><?=icon_svg('users')?></span><span><strong>Employee directory</strong><span>Open employee master records</span></span></a><a class="quick-action" href="<?=url('hr-manpower')?>"><span class="qa-icon"><?=icon_svg('briefcase')?></span><span><strong>Manpower request</strong><span>Create or review hiring demand</span></span></a><a class="quick-action" href="<?=url('hr-applicants')?>"><span class="qa-icon"><?=icon_svg('user-plus')?></span><span><strong>Applicant database</strong><span>Search candidate profiles</span></span></a><a class="quick-action" href="<?=url('hr-reports')?>"><span class="qa-icon"><?=icon_svg('chart')?></span><span><strong>Reports</strong><span>Review HR and recruitment activity</span></span></a></div></div></section>
    </div>
    <?php render_portal_footer(); exit;
}

if ($page === 'hr-timekeeping') {
    Auth::requirePermission('payroll.approve_hr');$queue=PayrollRepository::ready()?PayrollRepository::hrPayrollQueue('HR_TIMEKEEPING'):[];
    render_portal_header('hr',$page,'Timekeeping Queue');page_head('HR Portal / Payroll & Timekeeping','Timekeeping Approval Queue'); ?>
    <?php if(!PayrollRepository::ready()):?><div class="alert error">Import the Phase 3A database migration first.</div><?php endif;?>
    <section class="panel"><div class="panel-head"><div><h2>For HR Timekeeping Review</h2><p>Requests appear here only after required manager approval.</p></div><span class="badge amber"><?=count($queue)?> pending</span></div><div class="phase3a-table-wrap"><table class="phase3a-table"><thead><tr><th>Request</th><th>Employee</th><th>Type</th><th>Date</th><th>Branch</th><th>Filed</th><th>Actions</th></tr></thead><tbody><?php if(!$queue):?><tr><td colspan="7" class="empty">No requests are waiting for HR Timekeeping approval.</td></tr><?php endif;foreach($queue as $r):?><tr><td><strong><?=e($r['request_no'])?></strong></td><td><?=e($r['first_name'].' '.$r['last_name'])?><div class="tiny muted"><?=e($r['employee_no'])?></div></td><td><?=e($r['type_name'])?></td><td><?=e(date('M j, Y',strtotime($r['affected_date'])))?></td><td><?=e($r['branch_name']??'—')?></td><td><?=e(date('M j, g:i A',strtotime($r['created_at'])))?></td><td><div class="phase3a-row-actions"><a class="btn sm" href="<?=url('payroll-request',['id'=>$r['id']])?>">Review</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="payroll_request_decision"><input type="hidden" name="request_id" value="<?=$r['id']?>"><input type="hidden" name="decision" value="APPROVE"><input type="hidden" name="return_page" value="hr-timekeeping"><button class="btn primary sm">Approve</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="payroll_request_decision"><input type="hidden" name="request_id" value="<?=$r['id']?>"><input type="hidden" name="decision" value="REJECT"><input type="hidden" name="return_page" value="hr-timekeeping"><button class="btn sm">Reject</button></form></div></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php render_portal_footer();exit;
}

if ($page === 'hr-payroll-processing') {
    Auth::requirePermission('payroll.process');$queue=PayrollRepository::ready()?PayrollRepository::hrPayrollQueue('PAYROLL'):[];
    render_portal_header('hr',$page,'Payroll Processing');page_head('HR Portal / Payroll & Timekeeping','Payroll Processing Queue'); ?>
    <section class="panel"><div class="panel-head"><div><h2>Approved items for Payroll</h2><p>Only requests that completed earlier approval steps appear here.</p></div><span class="badge amber"><?=count($queue)?> pending</span></div><div class="phase3a-table-wrap"><table class="phase3a-table"><thead><tr><th>Request</th><th>Employee</th><th>Type</th><th>Affected Date</th><th>Actions</th></tr></thead><tbody><?php if(!$queue):?><tr><td colspan="5" class="empty">No approved requests are waiting for Payroll processing.</td></tr><?php endif;foreach($queue as $r):?><tr><td><?=e($r['request_no'])?></td><td><?=e($r['first_name'].' '.$r['last_name'])?></td><td><?=e($r['type_name'])?></td><td><?=e(date('M j, Y',strtotime($r['affected_date'])))?></td><td><div class="phase3a-row-actions"><a class="btn sm" href="<?=url('payroll-request',['id'=>$r['id']])?>">Review</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="payroll_request_decision"><input type="hidden" name="request_id" value="<?=$r['id']?>"><input type="hidden" name="decision" value="APPROVE"><input type="hidden" name="return_page" value="hr-payroll-processing"><button class="btn primary sm">Mark processed</button></form></div></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php render_portal_footer();exit;
}

if ($page === 'hr-leave') {
    Auth::requirePermission('leave.approve_hr');$queue=PayrollRepository::ready()?PayrollRepository::leaveQueue('HR_LEAVE'):[];$employees=PayrollRepository::allActiveEmployees();$types=PayrollRepository::leaveTypes();
    render_portal_header('hr',$page,'Leave Administration');page_head('HR Portal / Leave','Leave Administration'); ?>
    <?php if(!PayrollRepository::ready()):?><div class="alert error">Import the Phase 3A database migration first.</div><?php endif;?>
    <div class="phase3a-leave-admin"><section class="panel"><div class="panel-head"><div><h2>For HR Leave Approval</h2><p>Manager-approved leave requests.</p></div><span class="badge amber"><?=count($queue)?> pending</span></div><div class="phase3a-list"><?php if(!$queue):?><div class="empty">No leave requests are waiting for HR Leave.</div><?php endif;foreach($queue as $r):?><div class="phase3a-approval-row"><div><strong><?=e($r['first_name'].' '.$r['last_name'])?></strong><span><?=e($r['employee_no'])?> · <?=e($r['leave_type_name'])?> · <?=e($r['days'])?> day(s)</span><small><?=e(date('M j',strtotime($r['date_from'])))?> – <?=e(date('M j, Y',strtotime($r['date_to'])))?></small></div><div class="phase3a-row-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="leave_request_decision"><input type="hidden" name="request_id" value="<?=$r['id']?>"><input type="hidden" name="decision" value="APPROVE"><input type="hidden" name="return_page" value="hr-leave"><button class="btn primary sm">Approve</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="leave_request_decision"><input type="hidden" name="request_id" value="<?=$r['id']?>"><input type="hidden" name="decision" value="REJECT"><input type="hidden" name="return_page" value="hr-leave"><button class="btn sm">Reject</button></form></div></div><?php endforeach;?></div></section>
    <?php if(Auth::can('leave.credits.manage')):?><section class="panel"><div class="panel-head"><div><h2>Leave Credit Adjustment</h2><p>Every manual adjustment is added to the employee credit ledger.</p></div></div><form method="post" class="panel-body"><?=csrf_field()?><input type="hidden" name="action" value="adjust_leave_credit"><div class="field"><label>Employee</label><select name="employee_id" required><option value="">Select employee</option><?php foreach($employees as $e):?><option value="<?=$e['id']?>"><?=e($e['employee_no'].' · '.EmployeeRepository::fullName($e))?></option><?php endforeach;?></select></div><div class="field"><label>Leave type</label><select name="leave_type_id" required><option value="">Select leave type</option><?php foreach($types as $t):?><option value="<?=$t['id']?>"><?=e($t['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Adjustment</label><input type="number" name="amount" step="0.25" required placeholder="Example: 5 or -1"></div><div class="field"><label>Reason</label><textarea name="reason" rows="3" required placeholder="Annual credit allocation, correction, carry-over..."></textarea></div><button class="btn primary">Save credit adjustment</button></form></section><?php endif;?></div>
    <?php render_portal_footer();exit;
}

if ($page === 'hr-employees') {
    Auth::requirePermission('employees.view_all');
    $ready=EmployeeRepository::ready();
    $filters=[
        'q'=>(string)($_GET['q']??''),
        'department_id'=>(int)($_GET['department_id']??0),
        'branch_id'=>(int)($_GET['branch_id']??0),
        'employment_type_id'=>(int)($_GET['employment_type_id']??0),
        'status'=>(string)($_GET['status']??''),
    ];
    $employees=$ready?EmployeeRepository::directory($filters):[];
    $summary=$ready?EmployeeRepository::summary():['total'=>0,'active'=>0,'probationary'=>0,'inactive'=>0,'new_this_month'=>0];
    $masters=EmployeeRepository::masters();
    $canManage=Auth::can('employees.manage');
    render_portal_header('hr',$page,'Employee Directory');
    page_head('HR Portal / People','Employee Directory',$canManage?'<a href="'.url('hr-employee-new').'" class="btn primary">'.icon_svg('plus').' Add employee</a>':''); ?>
    <?php if(!$ready):?><div class="alert error">Phase 2A database migration is required. Import <code>database/migrations/20260924_phase2a_employees.sql</code> in phpMyAdmin.</div><?php endif;?>
    <div class="metric-grid employee-metrics"><?php metric_card('Total employees',$summary['total'],'Employee master records','users'); metric_card('Active',$summary['active'],'Currently active','check','up'); metric_card('Probationary',$summary['probationary'],'Under probationary status','clock'); metric_card('New this month',$summary['new_this_month'],'Based on hire date','user-plus'); ?></div>
    <section class="panel employee-directory-panel">
      <div class="panel-head"><div><h2>Employee master list</h2><p>Search and filter employee records across the organization.</p></div><span class="badge gray"><?=count($employees)?> shown</span></div>
      <form method="get" class="employee-filterbar">
        <input type="hidden" name="page" value="hr-employees">
        <div class="employee-search"><?=icon_svg('search')?><input name="q" value="<?=e($filters['q'])?>" placeholder="Search employee no., name or email"></div>
        <select name="department_id"><option value="">All departments</option><?php foreach($masters['departments'] as $x):?><option value="<?=$x['id']?>" <?=$filters['department_id']==$x['id']?'selected':''?>><?=e($x['name'])?></option><?php endforeach;?></select>
        <select name="branch_id"><option value="">All branches</option><?php foreach($masters['branches'] as $x):?><option value="<?=$x['id']?>" <?=$filters['branch_id']==$x['id']?'selected':''?>><?=e($x['name'])?></option><?php endforeach;?></select>
        <select name="status"><option value="">All statuses</option><?php foreach(['ACTIVE'=>'Active','PROBATIONARY'=>'Probationary','ON_LEAVE'=>'On Leave','INACTIVE'=>'Inactive','RESIGNED'=>'Resigned','TERMINATED'=>'Terminated'] as $k=>$label):?><option value="<?=$k?>" <?=$filters['status']===$k?'selected':''?>><?=$label?></option><?php endforeach;?></select>
        <button class="btn sm">Filter</button><a class="btn sm ghost" href="<?=url('hr-employees')?>">Reset</a>
      </form>
      <?php if(!$employees):?><div class="empty employee-empty"><?= $ready ? 'No employee records match the current filters.' : 'Employee directory will be available after the Phase 2A migration.' ?></div><?php else:?><div class="employee-table-wrap"><table class="tbl employee-table"><thead><tr><th>Employee</th><th>Employment</th><th>Department / Position</th><th>Branch</th><th>Hire date</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($employees as $e): $name=EmployeeRepository::fullName($e); $tone=in_array($e['status'],['ACTIVE','PROBATIONARY'],true)?'green':'gray'; ?><tr>
        <td><a class="employee-cell" href="<?=url('hr-employee',['id'=>$e['id']])?>"><span class="employee-avatar"><?=e(initials($name))?></span><span><strong><?=e($name)?></strong><small><?=e($e['employee_no'])?><?=!empty($e['company_email'])?' · '.e($e['company_email']):''?></small></span></a></td>
        <td><?=e($e['employment_type_name']??'—')?></td><td><strong><?=e($e['department_name']??'Unassigned')?></strong><div class="tiny muted"><?=e($e['position_name']??'No position')?></div></td><td><?=e($e['branch_name']??'Unassigned')?></td><td><?=e(date('M j, Y',strtotime($e['hire_date'])))?></td><td><span class="badge <?=$tone?>"><?=e(stage_label($e['status']))?></span></td><td><a href="<?=url('hr-employee',['id'=>$e['id']])?>" class="employee-open" aria-label="Open employee"><?=icon_svg('arrow')?></a></td>
      </tr><?php endforeach;?></tbody></table></div><?php endif;?>
    </section>
    <?php render_portal_footer(); exit;
}

if ($page === 'hr-employee-new') {
    Auth::requirePermission('employees.manage');
    $ready=EmployeeRepository::ready(); $masters=EmployeeRepository::masters();
    render_portal_header('hr','hr-employees','Add Employee');
    page_head('HR Portal / People / Employees','Add Employee','<a href="'.url('hr-employees').'" class="btn">Back to directory</a>'); ?>
    <?php if(!$ready):?><div class="alert error">Import <code>database/migrations/20260924_phase2a_employees.sql</code> before adding employees.</div><?php else:?>
    <form method="post" class="employee-create-layout"><?=csrf_field()?><input type="hidden" name="action" value="create_employee"><input type="hidden" name="return_page" value="hr-employee-new">
      <div class="employee-form-main">
        <section class="panel employee-form-card"><div class="panel-head"><div><h2>Personal information</h2><p>Core identity and contact information for the employee master record.</p></div><span class="badge amber">201 File foundation</span></div><div class="panel-body employee-form-grid">
          <div class="field"><label>Employee number</label><input name="employee_no" value="<?=e(EmployeeRepository::nextEmployeeNo())?>" required><small>Auto-generated; can be adjusted before saving.</small></div>
          <div class="field"><label>Preferred name</label><input name="preferred_name" placeholder="Optional"></div>
          <div class="field"><label>First name <span class="req">*</span></label><input name="first_name" required></div>
          <div class="field"><label>Middle name</label><input name="middle_name"></div>
          <div class="field"><label>Last name <span class="req">*</span></label><input name="last_name" required></div>
          <div class="field"><label>Suffix</label><input name="suffix" placeholder="Jr., III, etc."></div>
          <div class="field"><label>Birth date</label><input type="date" name="birth_date"></div>
          <div class="field"><label>Gender</label><select name="gender"><option value="">Not specified</option><option>Female</option><option>Male</option><option>Prefer not to say</option></select></div>
          <div class="field"><label>Civil status</label><select name="civil_status"><option value="">Not specified</option><option>Single</option><option>Married</option><option>Widowed</option><option>Separated</option></select></div>
          <div class="field"><label>Mobile number</label><input name="mobile_no" placeholder="+63 ..."></div>
          <div class="field"><label>Personal email</label><input type="email" name="personal_email"></div>
          <div class="field"><label>Company email</label><input type="email" name="company_email"></div>
          <div class="field full"><label>Current address</label><input name="address_text" placeholder="House / street / city / province"></div>
        </div></section>
        <section class="panel employee-form-card"><div class="panel-head"><div><h2>Employment information</h2><p>Organization assignment and employment details.</p></div></div><div class="panel-body employee-form-grid">
          <div class="field"><label>Department</label><select name="department_id"><option value="">Unassigned</option><?php foreach($masters['departments'] as $x): if(!$x['active'])continue;?><option value="<?=$x['id']?>"><?=e($x['name'])?></option><?php endforeach;?></select></div>
          <div class="field"><label>Position</label><select name="position_id"><option value="">Unassigned</option><?php foreach($masters['positions'] as $x): if(!$x['active'])continue;?><option value="<?=$x['id']?>"><?=e($x['name'])?><?=!empty($x['department_name'])?' · '.e($x['department_name']):''?></option><?php endforeach;?></select></div>
          <div class="field"><label>Branch / Site</label><select name="branch_id"><option value="">Unassigned</option><?php foreach($masters['branches'] as $x): if(!$x['active'])continue;?><option value="<?=$x['id']?>"><?=e($x['name'])?></option><?php endforeach;?></select></div>
          <div class="field"><label>Employment type</label><select name="employment_type_id"><option value="">Unassigned</option><?php foreach($masters['employment_types'] as $x): if(!$x['active'])continue;?><option value="<?=$x['id']?>"><?=e($x['name'])?></option><?php endforeach;?></select></div>
          <div class="field"><label>Hire date <span class="req">*</span></label><input type="date" name="hire_date" value="<?=e(date('Y-m-d'))?>" required></div>
          <div class="field"><label>Regularization date</label><input type="date" name="regularization_date"></div>
          <div class="field"><label>Employee status</label><select name="status"><option value="ACTIVE">Active</option><option value="PROBATIONARY">Probationary</option><option value="ON_LEAVE">On Leave</option><option value="INACTIVE">Inactive</option></select></div>
          <div class="field"><label>Employee portal account</label><select name="user_id"><option value="">Link later</option><?php foreach($masters['employee_users'] as $x):?><option value="<?=$x['id']?>"><?=e($x['full_name'])?> · <?=e($x['email'])?></option><?php endforeach;?></select><small>Only unlinked active Employee portal accounts are listed.</small></div>
        </div></section>
      </div>
      <aside class="employee-form-aside"><section class="panel sticky-card"><div class="panel-head"><div><h2>Create record</h2><p>Phase 2A employee master</p></div></div><div class="panel-body"><div class="employee-save-note"><span class="qa-icon"><?=icon_svg('shield')?></span><div><strong>Audited action</strong><p>Creation is recorded in the HRIS audit log.</p></div></div><button class="btn primary block" type="submit"><?=icon_svg('plus')?> Create employee</button><a href="<?=url('hr-employees')?>" class="btn block" style="margin-top:8px">Cancel</a></div></section></aside>
    </form><?php endif;?>
    <?php render_portal_footer(); exit;
}

if ($page === 'hr-employee') {
    Auth::requirePermission('employees.view_all');
    $id=(int)($_GET['id']??0); $e=EmployeeRepository::find($id);
    if(!$e){flash('error','Employee record not found.');redirect('hr-employees');}
    $tabs=['overview','personal','employment','government','emergency','documents','history','audit'];
    $tab=strtolower((string)($_GET['tab']??'overview')); if(!in_array($tab,$tabs,true))$tab='overview';
    $phase2Ready=EmployeeRepository::phase2Ready(); $canManage=Auth::can('employees.manage'); $masters=EmployeeRepository::masters($id);
    $name=EmployeeRepository::fullName($e); $tone=in_array($e['status'],['ACTIVE','PROBATIONARY'],true)?'green':'gray';
    $govIds=$phase2Ready?EmployeeRepository::governmentIds($id):[]; $contacts=$phase2Ready?EmployeeRepository::emergencyContacts($id):[];
    $documents=$phase2Ready?EmployeeRepository::documents($id):[]; $history=$phase2Ready?EmployeeRepository::history($id):[]; $auditTrail=EmployeeRepository::auditTrail($id);
    $recruitmentSource=RecruitmentRepository::employeeSource($id);
    render_portal_header('hr','hr-employees','Employee 201 File');
    page_head('HR Portal / People / Employees','Employee 201 File','<a href="'.url('hr-employees').'" class="btn">Back to directory</a>'); ?>
    <?php if(!$phase2Ready):?><div class="alert error">Phase 2B database migration is required. Import <code>database/migrations/20260924_phase2b_201_file.sql</code> in phpMyAdmin to activate the complete 201 File tabs.</div><?php endif;?>
    <section class="panel employee-profile-head phase2b-profile-head"><div class="employee-profile-main">
      <?php if(!empty($e['profile_photo_stored_name'])):?><img class="employee-profile-photo" src="<?=url('hr-employee-photo',['id'=>$id])?>" alt="<?=e($name)?>"><?php else:?><span class="employee-profile-avatar"><?=e(initials($name))?></span><?php endif;?>
      <div class="employee-profile-copy"><div class="employee-profile-title"><h2><?=e($name)?></h2><span class="badge <?=$tone?>"><?=e(stage_label($e['status']))?></span></div><p><?=e($e['employee_no'])?> · <?=e($e['position_name']??'No position assigned')?> · <?=e($e['department_name']??'No department assigned')?></p><div class="employee-profile-meta"><span><?=icon_svg('building')?> <?=e($e['branch_name']??'No branch')?></span><span><?=icon_svg('briefcase')?> <?=e($e['employment_type_name']??'No employment type')?></span><span><?=icon_svg('calendar')?> Hired <?=e(date('M j, Y',strtotime($e['hire_date'])))?></span></div></div>
      <div class="employee-profile-completeness"><span>201 File</span><strong><?=$phase2Ready?'Active':'Migration needed'?></strong><small><?=count($documents)?> docs · <?=count($govIds)?> IDs</small></div>
    </div></section>
    <nav class="employee-tabs phase2b-tabs"><?php foreach(['overview'=>'Overview','personal'=>'Personal Info','employment'=>'Employment','government'=>'Government IDs','emergency'=>'Emergency Contact','documents'=>'Documents','history'=>'History','audit'=>'Audit Trail'] as $key=>$label):?><a href="<?=url('hr-employee',['id'=>$id,'tab'=>$key])?>" class="<?=$tab===$key?'active':''?>"><?=e($label)?></a><?php endforeach;?></nav>

    <?php if($tab==='overview'):?>
      <div class="dashboard-grid equal employee-profile-grid">
        <section class="panel"><div class="panel-head"><div><h2>Employee overview</h2><p>Current master-data assignment</p></div><?php if($canManage):?><a class="panel-link" href="<?=url('hr-employee',['id'=>$id,'tab'=>'employment'])?>">Edit employment</a><?php endif;?></div><div class="panel-body employee-detail-list">
          <div><span>Employee number</span><strong><?=e($e['employee_no'])?></strong></div><div><span>Department</span><strong><?=e($e['department_name']??'Unassigned')?></strong></div><div><span>Position</span><strong><?=e($e['position_name']??'Unassigned')?></strong></div><div><span>Branch / Site</span><strong><?=e($e['branch_name']??'Unassigned')?></strong></div><div><span>Employment type</span><strong><?=e($e['employment_type_name']??'Unassigned')?></strong></div><div><span>Immediate Manager</span><strong><?=e($e['manager_name']??'Unassigned')?></strong></div><div><span>Hire date</span><strong><?=e(date('F j, Y',strtotime($e['hire_date'])))?></strong></div>
        </div></section>
        <section class="panel"><div class="panel-head"><div><h2>Contact & access</h2><p>Employee contact and portal link</p></div><?php if($canManage):?><a class="panel-link" href="<?=url('hr-employee',['id'=>$id,'tab'=>'personal'])?>">Edit profile</a><?php endif;?></div><div class="panel-body employee-detail-list">
          <div><span>Company email</span><strong><?=e($e['company_email']??'Not provided')?></strong></div><div><span>Personal email</span><strong><?=e($e['personal_email']??'Not provided')?></strong></div><div><span>Mobile number</span><strong><?=e($e['mobile_no']??'Not provided')?></strong></div><div><span>Portal account</span><strong><?=e($e['user_email']??'Not linked')?></strong></div><div><span>Portal status</span><strong><?=e($e['user_status']??'—')?></strong></div><div><span>201 File records</span><strong><?=count($govIds)?> IDs · <?=count($contacts)?> contacts · <?=count($documents)?> docs</strong></div>
        </div></section>
      </div>
      <?php if($recruitmentSource):?><section class="panel phase2c-source-link"><div class="panel-head"><div><h2>Recruitment source</h2><p>This employee was created from a completed recruitment record.</p></div><a class="panel-link" href="<?=url('hr-applicant',['id'=>$recruitmentSource['application_id']])?>">Open applicant record</a></div><div class="panel-body phase2c-source-link-grid"><div><span>Application</span><strong><?=e($recruitmentSource['application_no'])?></strong></div><div><span>Position</span><strong><?=e($recruitmentSource['job_title'])?></strong></div><div><span>Client</span><strong><?=e($recruitmentSource['client_name'])?></strong></div><div><span>Converted</span><strong><?=e(date('M j, Y g:i A',strtotime($recruitmentSource['converted_at'])))?></strong><small><?=!empty($recruitmentSource['converted_by_name'])?'by '.e($recruitmentSource['converted_by_name']):'System conversion'?></small></div></div></section><?php endif;?>
      <div class="dashboard-grid equal phase2b-overview-row">
        <section class="panel"><div class="panel-head"><div><h2>Profile photo</h2><p>Employee identification photo</p></div></div><div class="panel-body phase2b-photo-panel"><?php if(!empty($e['profile_photo_stored_name'])):?><img src="<?=url('hr-employee-photo',['id'=>$id])?>" alt="<?=e($name)?>"><?php else:?><span class="employee-profile-avatar big"><?=e(initials($name))?></span><?php endif;?><?php if($canManage&&$phase2Ready):?><form method="post" enctype="multipart/form-data" class="phase2b-inline-upload"><?=csrf_field()?><input type="hidden" name="action" value="upload_employee_photo"><input type="hidden" name="employee_id" value="<?=$id?>"><input type="hidden" name="return_page" value="hr-employee"><input type="hidden" name="return_id" value="<?=$id?>"><input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" required><button class="btn sm">Upload photo</button><small>JPG, PNG or WEBP · max 3 MB</small></form><?php endif;?></div></section>
        <section class="panel"><div class="panel-head"><div><h2>201 File summary</h2><p>Completion snapshot</p></div></div><div class="panel-body phase2b-summary-grid"><a href="<?=url('hr-employee',['id'=>$id,'tab'=>'government'])?>"><strong><?=count($govIds)?></strong><span>Government IDs</span></a><a href="<?=url('hr-employee',['id'=>$id,'tab'=>'emergency'])?>"><strong><?=count($contacts)?></strong><span>Emergency contacts</span></a><a href="<?=url('hr-employee',['id'=>$id,'tab'=>'documents'])?>"><strong><?=count($documents)?></strong><span>Documents</span></a><a href="<?=url('hr-employee',['id'=>$id,'tab'=>'history'])?>"><strong><?=count($history)?></strong><span>History events</span></a></div></section>
      </div>

    <?php elseif($tab==='personal'):?>
      <section class="panel phase2b-form-panel"><div class="panel-head"><div><h2>Personal information</h2><p>Identity, contact details and addresses.</p></div><span class="badge amber">201 File</span></div><?php if(!$canManage):?><div class="panel-body"><div class="alert">You have view-only access.</div></div><?php else:?><form method="post" class="panel-body employee-form-grid"><?=csrf_field()?><input type="hidden" name="action" value="update_employee_personal"><input type="hidden" name="employee_id" value="<?=$id?>"><input type="hidden" name="return_page" value="hr-employee"><input type="hidden" name="return_id" value="<?=$id?>"><input type="hidden" name="return_tab" value="personal">
        <div class="field"><label>First name <span class="req">*</span></label><input name="first_name" value="<?=e($e['first_name'])?>" required></div><div class="field"><label>Middle name</label><input name="middle_name" value="<?=e($e['middle_name']??'')?>"></div><div class="field"><label>Last name <span class="req">*</span></label><input name="last_name" value="<?=e($e['last_name'])?>" required></div><div class="field"><label>Suffix</label><input name="suffix" value="<?=e($e['suffix']??'')?>"></div>
        <div class="field"><label>Preferred name</label><input name="preferred_name" value="<?=e($e['preferred_name']??'')?>"></div><div class="field"><label>Birth date</label><input type="date" name="birth_date" value="<?=e($e['birth_date']??'')?>"></div><div class="field"><label>Place of birth</label><input name="place_of_birth" value="<?=e($e['place_of_birth']??'')?>"></div><div class="field"><label>Nationality</label><input name="nationality" value="<?=e($e['nationality']??'')?>" placeholder="Filipino"></div>
        <div class="field"><label>Gender</label><select name="gender"><?php foreach([''=>'Not specified','Female'=>'Female','Male'=>'Male','Prefer not to say'=>'Prefer not to say'] as $v=>$l):?><option value="<?=e($v)?>" <?=($e['gender']??'')===$v?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div><div class="field"><label>Civil status</label><select name="civil_status"><?php foreach([''=>'Not specified','Single'=>'Single','Married'=>'Married','Widowed'=>'Widowed','Separated'=>'Separated'] as $v=>$l):?><option value="<?=e($v)?>" <?=($e['civil_status']??'')===$v?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div>
        <div class="field"><label>Mobile number</label><input name="mobile_no" value="<?=e($e['mobile_no']??'')?>"></div><div class="field"><label>Personal email</label><input type="email" name="personal_email" value="<?=e($e['personal_email']??'')?>"></div><div class="field"><label>Company email</label><input type="email" name="company_email" value="<?=e($e['company_email']??'')?>"></div><div class="field full"><label>Current address</label><input name="address_text" value="<?=e($e['address_text']??'')?>"></div><div class="field full"><label>Permanent address</label><input name="permanent_address_text" value="<?=e($e['permanent_address_text']??'')?>"></div>
        <div class="phase2b-form-actions full"><button class="btn primary" type="submit">Save personal information</button></div>
      </form><?php endif;?></section>

    <?php elseif($tab==='employment'):?>
      <section class="panel phase2b-form-panel"><div class="panel-head"><div><h2>Employment information</h2><p>Assignment, status, portal linkage and effective-date history.</p></div><span class="badge gray">Changes are audited</span></div><?php if(!$canManage):?><div class="panel-body"><div class="alert">You have view-only access.</div></div><?php else:?><form method="post" class="panel-body employee-form-grid"><?=csrf_field()?><input type="hidden" name="action" value="update_employee_employment"><input type="hidden" name="employee_id" value="<?=$id?>"><input type="hidden" name="return_page" value="hr-employee"><input type="hidden" name="return_id" value="<?=$id?>"><input type="hidden" name="return_tab" value="employment">
        <div class="field"><label>Employee number</label><input name="employee_no" value="<?=e($e['employee_no'])?>" required></div><div class="field"><label>Employee portal account</label><select name="user_id"><option value="">Not linked</option><?php foreach($masters['employee_users'] as $x):?><option value="<?=$x['id']?>" <?=((int)($e['user_id']??0)===(int)$x['id'])?'selected':''?>><?=e($x['full_name'])?> · <?=e($x['email'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Department</label><select name="department_id"><option value="">Unassigned</option><?php foreach($masters['departments'] as $x): if(!$x['active'])continue;?><option value="<?=$x['id']?>" <?=((int)($e['department_id']??0)===(int)$x['id'])?'selected':''?>><?=e($x['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Position</label><select name="position_id"><option value="">Unassigned</option><?php foreach($masters['positions'] as $x): if(!$x['active'])continue;?><option value="<?=$x['id']?>" <?=((int)($e['position_id']??0)===(int)$x['id'])?'selected':''?>><?=e($x['name'])?><?=!empty($x['department_name'])?' · '.e($x['department_name']):''?></option><?php endforeach;?></select></div>
        <div class="field"><label>Branch / Site</label><select name="branch_id"><option value="">Unassigned</option><?php foreach($masters['branches'] as $x): if(!$x['active'])continue;?><option value="<?=$x['id']?>" <?=((int)($e['branch_id']??0)===(int)$x['id'])?'selected':''?>><?=e($x['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Employment type</label><select name="employment_type_id"><option value="">Unassigned</option><?php foreach($masters['employment_types'] as $x): if(!$x['active'])continue;?><option value="<?=$x['id']?>" <?=((int)($e['employment_type_id']??0)===(int)$x['id'])?'selected':''?>><?=e($x['name'])?></option><?php endforeach;?></select></div><div class="field full"><label>Immediate Manager / Reporting To</label><select name="manager_employee_id"><option value="">Unassigned</option><?php foreach($masters['manager_candidates'] as $m): $mn=EmployeeRepository::fullName($m);?><option value="<?=$m['id']?>" <?=((int)($e['manager_employee_id']??0)===(int)$m['id'])?'selected':''?>><?=e($mn)?> · <?=e($m['employee_no'])?><?=!empty($m['position_name'])?' · '.e($m['position_name']):''?></option><?php endforeach;?></select><small>Payroll/Timekeeping and Leave requests route first to this employee's linked manager account.</small></div>
        <div class="field"><label>Hire date</label><input type="date" name="hire_date" value="<?=e($e['hire_date'])?>" required></div><div class="field"><label>Regularization date</label><input type="date" name="regularization_date" value="<?=e($e['regularization_date']??'')?>"></div><div class="field"><label>Employee status</label><select name="status"><?php foreach(['ACTIVE'=>'Active','PROBATIONARY'=>'Probationary','ON_LEAVE'=>'On Leave','INACTIVE'=>'Inactive','RESIGNED'=>'Resigned','TERMINATED'=>'Terminated'] as $v=>$l):?><option value="<?=$v?>" <?=$e['status']===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select></div><div class="field"><label>Effective date of this change</label><input type="date" name="effective_date" value="<?=e(date('Y-m-d'))?>"></div><div class="field full"><label>Change remarks</label><textarea name="remarks" rows="3" placeholder="Example: Transferred to Makati HQ; promoted to HR Associate."></textarea><small>When department, position, branch, employment type, or status changes, HRIS creates an employment history entry automatically.</small></div>
        <div class="phase2b-form-actions full"><button class="btn primary" type="submit">Save employment changes</button></div>
      </form><?php endif;?></section>

    <?php elseif($tab==='government'):?>
      <?php if(!$phase2Ready):?><div class="panel pad"><div class="empty">Import the Phase 2B migration to use Government IDs.</div></div><?php else:?><div class="phase2b-two-col"><section class="panel"><div class="panel-head"><div><h2>Government IDs</h2><p>Official employee identifiers.</p></div><span class="badge gray"><?=count($govIds)?> records</span></div><div class="phase2b-list"><?php if(!$govIds):?><div class="empty">No government IDs recorded yet.</div><?php endif;foreach($govIds as $g):?><div class="phase2b-record"><div><span><?=e(stage_label($g['id_type']))?></span><strong><?=e($g['id_number'])?></strong><small><?=!empty($g['expiry_date'])?'Expires '.e(date('M j, Y',strtotime($g['expiry_date']))):'No expiry recorded'?></small></div><?php if($canManage):?><form method="post" onsubmit="return confirm('Remove this government ID?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_government_id"><input type="hidden" name="employee_id" value="<?=$id?>"><input type="hidden" name="government_id_id" value="<?=$g['id']?>"><input type="hidden" name="return_page" value="hr-employee"><input type="hidden" name="return_id" value="<?=$id?>"><input type="hidden" name="return_tab" value="government"><button class="mini-action" title="Remove">×</button></form><?php endif;?></div><?php endforeach;?></div></section>
      <?php if($canManage):?><section class="panel"><div class="panel-head"><div><h2>Add / update ID</h2><p>One record per ID type.</p></div></div><form method="post" class="panel-body"><?=csrf_field()?><input type="hidden" name="action" value="save_government_id"><input type="hidden" name="employee_id" value="<?=$id?>"><input type="hidden" name="return_page" value="hr-employee"><input type="hidden" name="return_id" value="<?=$id?>"><input type="hidden" name="return_tab" value="government"><div class="field"><label>ID type</label><select name="id_type"><?php foreach(EmployeeRepository::govIdTypes() as $t):?><option value="<?=$t?>"><?=e(stage_label($t))?></option><?php endforeach;?></select></div><div class="field"><label>ID number</label><input name="id_number" required></div><div class="split"><div class="field"><label>Issued date</label><input type="date" name="issued_date"></div><div class="field"><label>Expiry date</label><input type="date" name="expiry_date"></div></div><div class="field"><label>Notes</label><input name="notes"></div><button class="btn primary">Save government ID</button></form></section><?php endif;?></div><?php endif;?>

    <?php elseif($tab==='emergency'):?>
      <?php if(!$phase2Ready):?><div class="panel pad"><div class="empty">Import the Phase 2B migration to use Emergency Contacts.</div></div><?php else:?><div class="phase2b-two-col"><section class="panel"><div class="panel-head"><div><h2>Emergency contacts</h2><p>People HR may contact during an emergency.</p></div></div><div class="phase2b-list"><?php if(!$contacts):?><div class="empty">No emergency contacts recorded yet.</div><?php endif;foreach($contacts as $c):?><div class="phase2b-record"><div><span><?=e($c['relationship'])?> <?=$c['is_primary']?'<em class="phase2b-primary">Primary</em>':''?></span><strong><?=e($c['name'])?></strong><small><?=e($c['mobile_no'])?><?=!empty($c['email'])?' · '.e($c['email']):''?></small></div><?php if($canManage):?><form method="post" onsubmit="return confirm('Remove this emergency contact?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_emergency_contact"><input type="hidden" name="employee_id" value="<?=$id?>"><input type="hidden" name="contact_id" value="<?=$c['id']?>"><input type="hidden" name="return_page" value="hr-employee"><input type="hidden" name="return_id" value="<?=$id?>"><input type="hidden" name="return_tab" value="emergency"><button class="mini-action" title="Remove">×</button></form><?php endif;?></div><?php endforeach;?></div></section>
      <?php if($canManage):?><section class="panel"><div class="panel-head"><div><h2>Add emergency contact</h2><p>Set one contact as primary.</p></div></div><form method="post" class="panel-body"><?=csrf_field()?><input type="hidden" name="action" value="save_emergency_contact"><input type="hidden" name="employee_id" value="<?=$id?>"><input type="hidden" name="return_page" value="hr-employee"><input type="hidden" name="return_id" value="<?=$id?>"><input type="hidden" name="return_tab" value="emergency"><div class="field"><label>Name</label><input name="name" required></div><div class="field"><label>Relationship</label><input name="relationship" required placeholder="Parent, spouse, sibling..."></div><div class="field"><label>Mobile number</label><input name="mobile_no" required></div><div class="field"><label>Email</label><input type="email" name="email"></div><div class="field"><label>Address</label><input name="address_text"></div><label class="phase2b-check"><input type="checkbox" name="is_primary" value="1"> Set as primary contact</label><button class="btn primary">Save emergency contact</button></form></section><?php endif;?></div><?php endif;?>

    <?php elseif($tab==='documents'):?>
      <?php if(!$phase2Ready):?><div class="panel pad"><div class="empty">Import the Phase 2B migration to use secure Employee Documents.</div></div><?php else:?><div class="phase2b-doc-layout"><?php if($canManage):?><section class="panel"><div class="panel-head"><div><h2>Upload document</h2><p>Files are stored outside the public web directory.</p></div></div><form method="post" enctype="multipart/form-data" class="panel-body"><?=csrf_field()?><input type="hidden" name="action" value="upload_employee_document"><input type="hidden" name="employee_id" value="<?=$id?>"><input type="hidden" name="return_page" value="hr-employee"><input type="hidden" name="return_id" value="<?=$id?>"><input type="hidden" name="return_tab" value="documents"><div class="field"><label>Document type</label><select name="document_type"><?php foreach(EmployeeRepository::documentTypes() as $t):?><option value="<?=$t?>"><?=e(stage_label($t))?></option><?php endforeach;?></select></div><div class="field"><label>Title</label><input name="title" placeholder="Optional custom title"></div><div class="field"><label>File</label><input type="file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" required><small>PDF, images, Word or Excel · max 10 MB</small></div><button class="btn primary">Upload document</button></form></section><?php endif;?><section class="panel phase2b-doc-list-panel"><div class="panel-head"><div><h2>Employee documents</h2><p><?=count($documents)?> secure file<?=count($documents)===1?'':'s'?></p></div></div><div class="phase2b-doc-list"><?php if(!$documents):?><div class="empty">No employee documents uploaded yet.</div><?php endif;foreach($documents as $d):?><div class="phase2b-document"><span class="phase2b-file-icon"><?=icon_svg('file')?></span><div><strong><?=e($d['title'])?></strong><span><?=e(stage_label($d['document_type']))?> · <?=e($d['original_name'])?></span><small><?=number_format(((int)$d['file_size'])/1024,1)?> KB · Uploaded <?=e(date('M j, Y g:i A',strtotime($d['uploaded_at'])))?><?=!empty($d['uploaded_by_name'])?' by '.e($d['uploaded_by_name']):''?></small></div><div class="phase2b-doc-actions"><a class="btn sm" target="_blank" href="<?=url('hr-employee-document',['employee_id'=>$id,'id'=>$d['id']])?>">View / Download</a><?php if($canManage):?><form method="post" onsubmit="return confirm('Delete this document permanently?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_employee_document"><input type="hidden" name="employee_id" value="<?=$id?>"><input type="hidden" name="document_id" value="<?=$d['id']?>"><input type="hidden" name="return_page" value="hr-employee"><input type="hidden" name="return_id" value="<?=$id?>"><input type="hidden" name="return_tab" value="documents"><button class="btn sm ghost" type="submit">Delete</button></form><?php endif;?></div></div><?php endforeach;?></div></section></div><?php endif;?>

    <?php elseif($tab==='history'):?>
      <section class="panel"><div class="panel-head"><div><h2>Employment history</h2><p>Automatic trail of hiring, transfers, promotions, assignment and status changes.</p></div><span class="badge gray"><?=count($history)?> events</span></div><div class="phase2b-history"><?php if(!$phase2Ready):?><div class="empty">Import the Phase 2B migration to activate employment history.</div><?php elseif(!$history):?><div class="empty">No employment history events yet.</div><?php endif;foreach($history as $h):?><article><span class="phase2b-history-dot"></span><div class="phase2b-history-date"><?=e(date('M j, Y',strtotime($h['effective_date'])))?></div><div class="phase2b-history-card"><div><strong><?=e(stage_label($h['event_type']))?></strong><small><?=!empty($h['created_by_name'])?'Recorded by '.e($h['created_by_name']):'System record'?></small></div><p><?=e($h['remarks']??'Employment record updated.')?></p><div class="phase2b-history-changes"><?php if(($h['from_department_name']??'')!==($h['to_department_name']??'')):?><span>Department: <?=e($h['from_department_name']??'—')?> → <?=e($h['to_department_name']??'—')?></span><?php endif;?><?php if(($h['from_position_name']??'')!==($h['to_position_name']??'')):?><span>Position: <?=e($h['from_position_name']??'—')?> → <?=e($h['to_position_name']??'—')?></span><?php endif;?><?php if(($h['from_branch_name']??'')!==($h['to_branch_name']??'')):?><span>Branch: <?=e($h['from_branch_name']??'—')?> → <?=e($h['to_branch_name']??'—')?></span><?php endif;?><?php if(($h['from_status']??'')!==($h['to_status']??'')):?><span>Status: <?=e(stage_label((string)($h['from_status']??'—')))?> → <?=e(stage_label((string)($h['to_status']??'—')))?></span><?php endif;?></div></div></article><?php endforeach;?></div></section>

    <?php elseif($tab==='audit'):?>
      <section class="panel"><div class="panel-head"><div><h2>Audit trail</h2><p>Traceable system actions associated with this employee.</p></div><span class="badge gray"><?=count($auditTrail)?> actions</span></div><div class="phase2b-audit-list"><?php if(!$auditTrail):?><div class="empty">No audit actions recorded for this employee yet.</div><?php endif;foreach($auditTrail as $a):$details=$a['details_json']?json_decode((string)$a['details_json'],true):null;?><div class="phase2b-audit-row"><span class="phase2b-audit-icon"><?=icon_svg('audit')?></span><div><strong><?=e(stage_label($a['action']))?></strong><span><?=e($a['module'])?><?=!empty($a['user_name'])?' · '.e($a['user_name']):''?></span><?php if(is_array($details)&&$details):?><small><?=e(mb_substr(json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),0,220))?></small><?php endif;?></div><time><?=e(date('M j, Y g:i A',strtotime($a['created_at'])))?></time></div><?php endforeach;?></div></section>
    <?php endif;?>
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
    <div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>Applicant</th><th>Reference</th><th>Position</th><th>Client</th><th>Stage</th><th>Score</th><th>Recruiter</th><th></th></tr></thead><tbody><?php foreach($apps as $a):$name=$a['first_name'].' '.$a['last_name'];?><tr><td><div style="display:flex;gap:9px;align-items:center"><span class="avatar"><?=e(initials($name))?></span><div><div style="font-weight:600"><?=e($name)?></div><div class="tiny muted"><?=e($a['email'])?></div></div></div></td><td class="mono tiny"><?=e($a['application_no'])?></td><td><?=e($a['job_title'])?></td><td><?=e($a['client_name'])?></td><td><?=($a['application_status']??'')==='CONVERTED'?'<span class="badge green">Converted</span>':stage_badge($a['stage_code'])?></td><td><span class="badge <?=((float)$a['screening_score']>=85)?'green':'amber'?>"><?=e((string)($a['screening_score']??'—'))?></span></td><td class="small"><?=e($a['recruiter_name']??'Unassigned')?></td><td><a href="<?=url('hr-applicant',['id'=>$a['id']])?>" class="btn sm">View</a></td></tr><?php endforeach;?></tbody></table><?php if(!$apps):?><div class="empty">No applicants found.</div><?php endif;?></div>
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
    $phase2CReady=RecruitmentRepository::phase2CReady();
    $convertedEmployeeId=$phase2CReady?(int)($a['converted_employee_id']??0):0;
    $canConvert=Auth::can('recruitment.manage')&&Auth::can('employees.create');
    $conversionEligible=$a['stage_code']==='HIRED' || ($a['stage_code']==='DEPLOYED' && (($a['deployment']['status']??'')==='DEPLOYED'));
    render_portal_header('hr','hr-applicants','Applicant Profile'); page_head('HR Portal / Applicants / Profile','Applicant Profile'); ?>
    <div class="split3"><div><div class="card pad" style="margin-bottom:16px"><div style="display:flex;gap:14px;align-items:center"><span class="avatar" style="width:52px;height:52px;font-size:18px"><?=e(initials($name))?></span><div><h2 style="font-size:20px"><?=e($name)?></h2><div class="muted small"><?=e($a['job_title'])?> · <?=e($a['client_name'])?></div></div><div style="margin-left:auto;text-align:right"><?=$convertedEmployeeId>0?'<span class="badge green">Converted</span>':stage_badge($a['stage_code'])?><div class="tiny muted mono" style="margin-top:5px"><?=e($a['application_no'])?></div></div></div><div style="display:flex;gap:24px;margin-top:16px;flex-wrap:wrap" class="small"><div><div class="tiny muted">EMAIL</div><?=e($a['email'])?></div><div><div class="tiny muted">PHONE</div><?=e($a['mobile_no'])?></div><div><div class="tiny muted">MATCH / SCREEN SCORE</div><span class="badge green"><?=e((string)($a['screening_score']??'—'))?></span></div></div></div>
    <?php if(Auth::is('SUPER_ADMIN','RECRUITMENT_MANAGER','RECRUITER') && $convertedEmployeeId===0):?><div class="card pad" style="margin-bottom:16px"><div class="section-t">Stage & actions</div><form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end"><?=csrf_field()?><input type="hidden" name="action" value="move_stage"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><div class="field" style="margin:0"><label>Move to</label><select name="target_stage" class="btn status-select"><?php foreach($stages as $s):?><option value="<?=e($s['code'])?>" <?=$s['code']===$a['stage_code']?'selected':''?>><?=e($s['name'])?></option><?php endforeach;?></select></div><div class="field" style="margin:0;flex:1;min-width:200px"><label>Comment</label><input name="comment" placeholder="Reason / note"></div><button class="btn primary">Update stage</button></form></div>
    <details class="card pad" style="margin-bottom:16px"><summary style="font-weight:600;cursor:pointer">Schedule interview</summary><form method="post" style="margin-top:14px"><?=csrf_field()?><input type="hidden" name="action" value="schedule_interview"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><div class="split"><div class="field"><label>Interview type</label><select name="interview_type"><option>Initial Interview</option><option>Final Interview</option><option>Client Interview</option><option>Technical Interview</option></select></div><div class="field"><label>Date & time</label><input type="datetime-local" name="scheduled_at" required></div></div><div class="field"><label>Location or meeting link</label><input name="location_or_link"></div><div class="field"><label>Notes</label><textarea name="notes" rows="2"></textarea></div><button class="btn primary">Schedule interview</button></form></details>
    <?php if(in_array($a['stage_code'],['INTERVIEW','ENDORSED'],true)):?><form method="post" class="card pad" style="margin-bottom:16px"><?=csrf_field()?><input type="hidden" name="action" value="endorse"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><div class="section-t">Client endorsement</div><div class="field"><label>Endorsement note</label><textarea name="note" rows="2" placeholder="Summary for client review"></textarea></div><button class="btn primary">Endorse to client →</button></form><?php endif;?>
    <?php if($a['stage_code']==='OFFER' || $a['offer']):?><div class="card pad" style="margin-bottom:16px"><div class="section-t">Offer</div><?php if($a['offer']):?><div class="srow"><div class="small" style="flex:1"><strong><?=e($a['offer']['offer_no'])?></strong><div class="tiny muted">Status: <?=e($a['offer']['status'])?><?=!empty($a['offer']['start_date'])?' · Start '.e($a['offer']['start_date']):''?></div></div><span class="mono small"><?=e($a['offer']['offered_salary']!==null?'₱'.number_format((float)$a['offer']['offered_salary'],2):'—')?></span></div><?php endif;?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_offer"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><div class="split"><div class="field"><label>Offered salary</label><input type="number" step="0.01" name="offered_salary" value="<?=e((string)($a['offer']['offered_salary']??''))?>"></div><div class="field"><label>Employment type</label><select name="employment_type"><option value="FULL_TIME">Full-time</option><option value="CONTRACT">Contract</option></select></div></div><div class="field"><label>Start date</label><input type="date" name="start_date" value="<?=e((string)($a['offer']['start_date']??''))?>"></div><button class="btn primary">Save / Send offer</button></form><?php if(($a['offer']['status']??'')==='SENT'):?><form method="post" style="margin-top:10px"><?=csrf_field()?><input type="hidden" name="action" value="accept_offer"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><button class="btn">Mark offer accepted → Deployment</button></form><?php endif;?></div><?php endif;?>
    <?php if($a['stage_code']==='DEPLOYMENT' || $a['deployment']):?><div class="card pad" style="margin-bottom:16px"><div class="section-t">Deployment</div><?php if($a['deployment']):?><div class="srow"><div class="small" style="flex:1"><strong><?=e($a['deployment']['deployment_no'])?></strong><div class="tiny muted"><?=e($a['deployment']['status'])?><?=!empty($a['deployment']['branch_name'])?' · '.e($a['deployment']['branch_name']):''?></div></div><span class="tiny muted"><?=e($a['deployment']['scheduled_date']??'')?></span></div><?php endif;?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_deployment"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><div class="split"><div class="field"><label>Deployment branch/site</label><select name="branch_id"><option value="">Not specified</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=((int)($a['deployment']['branch_id']??0)===(int)$b['id'])?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Scheduled date</label><input type="date" name="scheduled_date" value="<?=e((string)($a['deployment']['scheduled_date']??''))?>"></div></div><div class="field"><label>Notes</label><textarea name="notes" rows="2"><?=e((string)($a['deployment']['notes']??''))?></textarea></div><button class="btn primary">Save deployment schedule</button></form><?php if($a['deployment'] && $a['deployment']['status']!=='DEPLOYED'):?><form method="post" style="margin-top:10px" onsubmit="return confirm('Mark this candidate as deployed?')"><?=csrf_field()?><input type="hidden" name="action" value="complete_deployment"><input type="hidden" name="application_id" value="<?=$a['id']?>"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="<?=$a['id']?>"><button class="btn">Mark as Deployed</button></form><?php endif;?></div><?php endif;?>
    <?php endif;?>
    <?php if($convertedEmployeeId>0):?>
      <section class="card phase2c-conversion-state success"><div class="phase2c-state-icon"><?=icon_svg('check-square')?></div><div><strong>Converted to employee</strong><p>This recruitment record is locked for recruitment changes and linked to its Employee 201 File.</p></div><a class="btn primary sm" href="<?=url('hr-employee',['id'=>$convertedEmployeeId])?>">Open 201 File</a></section>
    <?php elseif($conversionEligible):?>
      <section class="card phase2c-conversion-state"><div class="phase2c-state-icon"><?=icon_svg('user-plus')?></div><div><strong>Ready for employee conversion</strong><p>Create the Employee Master / 201 File directly from this recruitment record without re-encoding the candidate.</p></div><?php if(!$phase2CReady):?><span class="badge amber">Migration required</span><?php elseif($canConvert):?><a class="btn primary sm" href="<?=url('hr-convert-employee',['id'=>$a['id']])?>">Convert to Employee</a><?php else:?><span class="badge gray">No conversion access</span><?php endif;?></section>
    <?php elseif($a['stage_code']==='DEPLOYED'):?>
      <section class="card phase2c-conversion-state"><div class="phase2c-state-icon"><?=icon_svg('briefcase')?></div><div><strong>Complete deployment first</strong><p>The stage is Deployed, but the deployment record has not been completed. Finish the deployment workflow before employee conversion.</p></div><span class="badge amber">Not ready</span></section>
    <?php endif;?>
    <div class="card pad"><div class="section-t">Screening answer</div><p class="muted"><?=nl2br(e($a['why_fit']?:'No answer provided.'))?></p><div class="section-t" style="margin-top:18px">Interviews</div><?php if(!$a['interviews']):?><div class="tiny muted">No interviews yet.</div><?php endif;foreach($a['interviews'] as $i):?><div class="srow"><div style="flex:1"><div class="small" style="font-weight:600"><?=e($i['interview_type'])?></div><div class="tiny muted"><?=e(date('M j, Y g:i A',strtotime($i['scheduled_at'])))?> · <?=e($i['location_or_link']?:'TBD')?></div></div><span class="badge blue"><?=e($i['status'])?></span></div><?php endforeach;?></div></div><div><div class="card pad"><div class="section-t">Activity timeline</div><div class="tl"><?php foreach($a['history'] as $h):?><div class="ev"><div class="small" style="font-weight:600"><?=e($h['to_name'])?></div><div class="tiny muted"><?=e(date('M j, Y g:i A',strtotime($h['changed_at'])))?><?=!empty($h['changed_by_name'])?' · '.e($h['changed_by_name']):''?></div><?php if($h['comment']):?><div class="tiny muted"><?=e($h['comment'])?></div><?php endif;?></div><?php endforeach;?></div></div></div></div>
    <?php render_portal_footer(); exit;
}

if ($page === 'hr-convert-employee') {
    Auth::requirePermission('recruitment.manage');
    Auth::requirePermission('employees.create');
    $id=(int)($_GET['id']??0);$a=RecruitmentRepository::application($id);
    if(!$a){flash('error','Application not found.');redirect('hr-applicants');}
    if(!RecruitmentRepository::phase2CReady()){
        render_portal_header('hr','hr-applicants','Convert to Employee');
        page_head('HR Portal / Recruitment / Employee Conversion','Convert to Employee','<a href="'.url('hr-applicant',['id'=>$id]).'" class="btn">Back to applicant</a>'); ?>
        <div class="alert error">Phase 2C database migration is required. Import <code>database/migrations/20260925_phase2c_recruitment_to_employee.sql</code> in phpMyAdmin first.</div>
        <?php render_portal_footer();exit;
    }
    if(!empty($a['converted_employee_id'])){redirect('hr-employee',['id'=>(int)$a['converted_employee_id']]);}
    $conversionEligible=$a['stage_code']==='HIRED' || ($a['stage_code']==='DEPLOYED' && (($a['deployment']['status']??'')==='DEPLOYED'));
    if(!$conversionEligible){flash('error','Complete the hired/deployment workflow before converting this candidate to Employee Management.');redirect('hr-applicant',['id'=>$id]);}
    $defaults=RecruitmentRepository::conversionDefaults($id);$masters=EmployeeRepository::masters();$documents=RecruitmentRepository::applicationDocuments($id);
    $duplicate=RecruitmentRepository::employeeDuplicateByEmail((string)$a['email']);$name=trim($a['first_name'].' '.($a['middle_name']??'').' '.$a['last_name']);
    render_portal_header('hr','hr-applicants','Convert to Employee');
    page_head('HR Portal / Recruitment / Employee Conversion','Convert to Employee','<a href="'.url('hr-applicant',['id'=>$id]).'" class="btn">Back to applicant</a>'); ?>
    <div class="phase2c-flow" aria-label="Recruitment to employee workflow"><span class="done">Applicant</span><i>→</i><span class="done">Hired / Deployed</span><i>→</i><span class="active">Review employee record</span><i>→</i><span>201 File</span></div>
    <?php if($duplicate):?><div class="alert error phase2c-duplicate"><strong>Possible duplicate employee found.</strong> <?=e($a['email'])?> is already used by <a href="<?=url('hr-employee',['id'=>$duplicate['id']])?>"><?=e(trim(($duplicate['first_name']??'').' '.($duplicate['last_name']??'')))?> (<?=e($duplicate['employee_no'])?>)</a>. Review that employee before continuing.</div><?php endif;?>
    <form method="post" class="phase2c-layout" id="convertEmployeeForm">
      <?=csrf_field()?><input type="hidden" name="action" value="convert_application_to_employee"><input type="hidden" name="application_id" value="<?=$id?>">
      <div class="phase2c-main">
        <section class="panel phase2c-source-card">
          <div class="panel-head"><div><h2>Recruitment source</h2><p>Original hiring record that will remain linked to the employee.</p></div><span class="badge green"><?=e(stage_label($a['stage_code']))?></span></div>
          <div class="panel-body phase2c-source-grid">
            <div><span>Candidate</span><strong><?=e($name)?></strong><small><?=e($a['applicant_no'])?></small></div>
            <div><span>Application</span><strong><?=e($a['application_no'])?></strong><small>Applied <?=e(date('M j, Y',strtotime($a['applied_at'])))?></small></div>
            <div><span>Position</span><strong><?=e($a['job_title'])?></strong><small><?=e($a['client_name'])?></small></div>
            <div><span>Deployment</span><strong><?=e($a['deployment']['branch_name']??'Not specified')?></strong><small><?=!empty($a['deployment']['actual_date'])?'Deployed '.e(date('M j, Y',strtotime($a['deployment']['actual_date']))):'Deployment completed'?></small></div>
          </div>
        </section>

        <section class="panel">
          <div class="panel-head"><div><h2>Employee identity</h2><p>Review the details before creating the Employee Master record.</p></div><span class="badge amber">Review required</span></div>
          <div class="panel-body employee-form-grid">
            <div class="field"><label>Employee number</label><input name="employee_no" value="<?=e($defaults['employee_no'])?>" required></div>
            <div class="field"><label>Company email</label><input type="email" name="company_email" placeholder="name@pmbsi.com"></div>
            <div class="field"><label>First name</label><input name="first_name" value="<?=e($defaults['first_name'])?>" required></div>
            <div class="field"><label>Middle name</label><input name="middle_name" value="<?=e($defaults['middle_name'])?>"></div>
            <div class="field"><label>Last name</label><input name="last_name" value="<?=e($defaults['last_name'])?>" required></div>
            <div class="field"><label>Suffix</label><input name="suffix" value="<?=e($defaults['suffix'])?>"></div>
            <div class="field"><label>Personal email</label><input type="email" name="personal_email" value="<?=e($defaults['personal_email'])?>"></div>
            <div class="field"><label>Mobile number</label><input name="mobile_no" value="<?=e($defaults['mobile_no'])?>"></div>
          </div>
        </section>

        <section class="panel">
          <div class="panel-head"><div><h2>Employment assignment</h2><p>Confirm the employee's organization assignment and start details.</p></div></div>
          <div class="panel-body employee-form-grid">
            <div class="field"><label>Department</label><select name="department_id" id="phase2cDepartment"><option value="">Unassigned</option><?php foreach($masters['departments'] as $x):if(!$x['active'])continue;?><option value="<?=$x['id']?>" <?=((int)$defaults['department_id']===(int)$x['id'])?'selected':''?>><?=e($x['name'])?></option><?php endforeach;?></select></div>
            <div class="field"><label>Position</label><select name="position_id" id="phase2cPosition"><option value="">Unassigned</option><?php foreach($masters['positions'] as $x):if(!$x['active'])continue;?><option value="<?=$x['id']?>" data-department="<?=e((string)($x['department_id']??''))?>" <?=((int)$defaults['position_id']===(int)$x['id'])?'selected':''?>><?=e($x['name'])?><?=!empty($x['department_name'])?' · '.e($x['department_name']):''?></option><?php endforeach;?></select><small>If the recruitment title has no exact Position master match, select the correct position here.</small></div>
            <div class="field"><label>Branch / Site</label><select name="branch_id"><option value="">Unassigned</option><?php foreach($masters['branches'] as $x):if(!$x['active'])continue;?><option value="<?=$x['id']?>" <?=((int)$defaults['branch_id']===(int)$x['id'])?'selected':''?>><?=e($x['name'])?></option><?php endforeach;?></select></div>
            <div class="field"><label>Employment type</label><select name="employment_type_id" required><option value="">Select employment type</option><?php foreach($masters['employment_types'] as $x):if(!$x['active'])continue;?><option value="<?=$x['id']?>" <?=((int)$defaults['employment_type_id']===(int)$x['id'])?'selected':''?>><?=e($x['name'])?></option><?php endforeach;?></select></div>
            <div class="field"><label>Hire date</label><input type="date" name="hire_date" value="<?=e($defaults['hire_date'])?>" required></div>
            <div class="field"><label>Regularization date</label><input type="date" name="regularization_date"></div>
            <div class="field"><label>Employee status</label><select name="status"><option value="PROBATIONARY" selected>Probationary</option><option value="ACTIVE">Active</option></select></div>
          </div>
        </section>

        <section class="panel">
          <div class="panel-head"><div><h2>Employee portal access</h2><p>Link an existing Employee portal account, create one now, or complete account setup later.</p></div></div>
          <div class="panel-body">
            <div class="field"><label>Account setup</label><select name="account_mode" id="phase2cAccountMode"><option value="later">Link account later</option><option value="existing">Link existing Employee account</option><option value="create">Create Employee account now</option></select></div>
            <div class="phase2c-account-block" id="phase2cExistingAccount" hidden><div class="field"><label>Existing Employee account</label><select name="user_id"><option value="">Select account</option><?php foreach($masters['employee_users'] as $u):?><option value="<?=$u['id']?>"><?=e($u['full_name'])?> · <?=e($u['email'])?></option><?php endforeach;?></select><small>Only active, unlinked Employee portal accounts are shown.</small></div></div>
            <div class="phase2c-account-grid" id="phase2cCreateAccount" hidden><div class="field"><label>Account email</label><input type="email" name="account_email" id="phase2cAccountEmail" placeholder="employee@pmbsi.com"></div><div class="field"><label>Temporary password</label><div class="users-password-input"><input type="password" name="temporary_password" id="phase2cTempPassword" minlength="10" placeholder="Minimum 10 characters" autocomplete="new-password"><button class="users-eye-btn" type="button" data-password-toggle="#phase2cTempPassword" aria-label="Show temporary password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div><small>At least 10 characters with one letter and one number.</small></div></div>
          </div>
        </section>

        <section class="panel">
          <div class="panel-head"><div><h2>Recruitment documents</h2><p><?=count($documents)?> application document<?=count($documents)===1?'':'s'?> available for transfer.</p></div></div>
          <div class="panel-body"><label class="phase2c-check"><input type="checkbox" name="transfer_documents" value="1" checked> Copy recruitment documents into the Employee 201 File</label><?php if($documents):?><div class="phase2c-doc-preview"><?php foreach($documents as $doc):?><span><?=icon_svg('file')?> <?=e($doc['original_name'])?></span><?php endforeach;?></div><?php else:?><p class="muted small phase2c-no-docs">No recruitment documents are attached to this application.</p><?php endif;?></div>
        </section>
      </div>

      <aside class="phase2c-aside">
        <section class="panel phase2c-review-card">
          <div class="panel-head"><div><h2>Final review</h2><p>One-time conversion</p></div></div>
          <div class="panel-body">
            <div class="phase2c-review-note"><span><?=icon_svg('shield')?></span><div><strong>Duplicate protected</strong><p>The application can only be converted once. Email and account-link checks run again on save.</p></div></div>
            <div class="phase2c-review-note"><span><?=icon_svg('audit')?></span><div><strong>Audited</strong><p>The source application, employee record, converter and timestamp remain traceable.</p></div></div>
            <label class="phase2c-confirm"><input type="checkbox" name="conversion_confirmed" value="1" required><span>I reviewed the employee identity, assignment and portal-access details.</span></label>
            <button class="btn primary block phase2c-submit" type="submit" <?=$duplicate?'disabled':''?>><?=icon_svg('user-plus')?> Create Employee 201 File</button>
            <?php if($duplicate):?><a class="btn block" href="<?=url('hr-employee',['id'=>$duplicate['id']])?>">Review existing employee</a><?php endif;?>
          </div>
        </section>
      </aside>
    </form>
    <script>
    (()=>{
      const mode=document.getElementById('phase2cAccountMode'),existing=document.getElementById('phase2cExistingAccount'),create=document.getElementById('phase2cCreateAccount'),email=document.getElementById('phase2cAccountEmail'),password=document.getElementById('phase2cTempPassword');
      const syncAccount=()=>{const v=mode?.value||'later';if(existing)existing.hidden=v!=='existing';if(create)create.hidden=v!=='create';if(email)email.required=v==='create';if(password)password.required=v==='create';};mode?.addEventListener('change',syncAccount);syncAccount();
      document.querySelectorAll('[data-password-toggle]').forEach(btn=>{if(btn.dataset.phase2cBound)return;btn.dataset.phase2cBound='1';btn.addEventListener('click',()=>{const input=document.querySelector(btn.dataset.passwordToggle);if(!input)return;input.type=input.type==='password'?'text':'password';btn.classList.toggle('active',input.type==='text');btn.setAttribute('aria-label',input.type==='text'?'Hide temporary password':'Show temporary password');});});
      const department=document.getElementById('phase2cDepartment'),position=document.getElementById('phase2cPosition');
      const syncPositions=()=>{if(!department||!position)return;const dep=department.value;Array.from(position.options).forEach((o,i)=>{if(i===0)return;const match=!dep||!o.dataset.department||o.dataset.department===dep;o.hidden=!match;if(!match&&o.selected)position.value='';});};department?.addEventListener('change',syncPositions);syncPositions();
    })();
    </script>
    <?php render_portal_footer();exit;
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
    $users=FoundationRepository::users();
    $roles=FoundationRepository::roles();
    $isSuperAdmin=Auth::is('SUPER_ADMIN');
    $passwordResetReveal=$_SESSION['password_reset_reveal'] ?? null;
    unset($_SESSION['password_reset_reveal']);
    $passwordResetRevealUserId=is_array($passwordResetReveal)?(int)($passwordResetReveal['user_id']??0):0;
    $passwordResetRevealValue=is_array($passwordResetReveal)?(string)($passwordResetReveal['password']??''):'';
    $portalOptions=[];
    foreach($users as $usr){$portalKey=strtoupper((string)$usr['portal']); if($portalKey!==''){$portalOptions[$portalKey]=$portalKey;}}
    ksort($portalOptions);
    render_portal_header('admin',$page,'Users'); ?>

    <div class="users-pagehead">
      <div>
        <h1>Users &amp; Access</h1>
        <p>Manage user accounts, roles, and access to PMBSI HRIS.</p>
      </div>
    </div>

    <div class="users-access-layout">
      <?php if(Auth::can('users.manage')):?><form method="post" class="panel users-create-card" id="createUserForm"><?=csrf_field()?>
        <input type="hidden" name="action" value="create_user">
        <div class="users-card-head"><h2>Create New User</h2><p>Add a new user account to PMBSI HRIS.</p></div>
        <div class="users-create-body">
          <div class="field"><label for="create_full_name">Full name</label><input id="create_full_name" name="full_name" required placeholder="Enter full name" autocomplete="name"></div>
          <div class="field"><label for="create_email">Email address</label><input id="create_email" name="email" type="email" required placeholder="name@pmbsi.com" autocomplete="email"></div>
          <div class="field"><label for="create_role">Role</label><select id="create_role" name="role_id" required><option value="">Select role</option><?php foreach($roles as $r):?><option value="<?=$r['id']?>"><?=e($r['name'])?></option><?php endforeach;?></select></div>
          <div class="field"><label for="create_password">Temporary password</label><div class="users-password-input"><input id="create_password" name="password" type="password" minlength="10" required autocomplete="new-password" placeholder="Minimum 10 characters"><button class="users-eye-btn" type="button" aria-label="Show temporary password" title="Show / hide password" data-password-toggle="#create_password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div><small>Minimum 10 characters. Include at least one letter and one number.</small></div>
          <button class="btn primary block users-create-btn" type="submit"><?=icon_svg('user-plus')?> <span>Create account</span></button>
        </div>
      </form><?php endif;?>

      <section class="panel users-list-card">
        <div class="users-list-head">
          <div><h2>User Accounts (<span id="usersVisibleCount"><?=count($users)?></span>)</h2><p>View and manage all user accounts in the system.</p></div>
          <div class="users-list-tools">
            <div class="users-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input id="userAccountSearch" type="search" placeholder="Search users..." autocomplete="off" aria-label="Search users"></div>
            <div class="users-filter-wrap"><button class="btn users-filter-btn" id="userFiltersButton" type="button" aria-expanded="false" aria-controls="userFiltersPanel"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16M7 12h10M10 19h4"/></svg><span>Filters</span></button>
              <div class="users-filter-panel" id="userFiltersPanel" hidden>
                <div class="field"><label for="filterRole">Role</label><select id="filterRole"><option value="">All roles</option><?php foreach($roles as $r):?><option value="<?=e(strtolower($r['name']))?>"><?=e($r['name'])?></option><?php endforeach;?></select></div>
                <div class="field"><label for="filterDepartment">Department</label><select id="filterDepartment"><option value="">All departments</option><?php foreach($portalOptions as $p):?><option value="<?=e(strtolower($p))?>"><?=e($p)?></option><?php endforeach;?></select></div>
                <div class="field"><label for="filterStatus">Status</label><select id="filterStatus"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="locked">Locked</option></select></div>
                <button class="btn sm users-clear-filters" id="clearUserFilters" type="button">Clear filters</button>
              </div>
            </div>
          </div>
        </div>
<div class="users-table-shell">
          <table class="users-access-table" id="usersAccessTable">
            <colgroup>
              <col class="users-col-name">
              <col class="users-col-role">
              <col class="users-col-department">
              <col class="users-col-status">
              <col class="users-col-last-login">
              <?php if($isSuperAdmin):?><col class="users-col-password"><?php endif;?>
              <col class="users-col-actions">
            </colgroup>
            <thead><tr><th>Name / Email</th><th>Role</th><th class="users-department-head">Department</th><th>Status</th><th>Last login</th><?php if($isSuperAdmin):?><th>Password</th><?php endif;?><th class="users-actions-head">Actions</th></tr></thead>
            <tbody>
            <?php foreach($users as $u):
              $roleFilter=strtolower((string)$u['role_name']);
              $portalFilter=strtolower(strtoupper((string)$u['portal']));
              $statusFilter=strtolower((string)$u['status']);
              $searchFilter=strtolower(trim((string)$u['full_name'].' '.(string)$u['email'].' '.(string)$u['role_name'].' '.(string)$u['portal'].' '.(string)$u['status']));
              $isCurrent=(int)$u['id']===(int)(Auth::user()['id']??0);
            ?>
              <tr data-user-row data-search="<?=e($searchFilter)?>" data-role="<?=e($roleFilter)?>" data-department="<?=e($portalFilter)?>" data-status="<?=e($statusFilter)?>">
                <td data-label="Name / Email"><div class="users-person"><div><strong><?=e($u['full_name'])?></strong><span><?=e($u['email'])?></span></div></div></td>
                <td data-label="Role"><span class="users-role-badge"><?=e($u['role_name'])?></span></td>
                <td data-label="Department"><span class="users-department"><?=e(strtoupper($u['portal']))?></span></td>
                <td data-label="Status"><span class="users-status users-status-<?=e(strtolower($u['status']))?>"><?=e(strtoupper($u['status']))?></span></td>
                <td data-label="Last login"><span class="users-last-login"><?=e($u['last_login_at']?date('M j, Y g:i A',strtotime($u['last_login_at'])):'Never')?></span></td>
                <?php if($isSuperAdmin):?>
                <td data-label="Password">
                  <div class="users-password-cell">
                    <span class="password-mask">••••••••</span>
                    <button class="users-eye-btn users-hash-eye" type="button" aria-label="Open password access" title="Password access" data-password-info data-user-id="<?=$u['id']?>" data-user-name="<?=e($u['full_name'])?>" data-user-email="<?=e($u['email'])?>" data-user-initials="<?=e(initials($u['full_name']))?>" data-is-current="<?=$isCurrent?'1':'0'?>" <?php if($passwordResetRevealUserId===(int)$u['id'] && $passwordResetRevealValue!==''):?>data-revealed-password="<?=e($passwordResetRevealValue)?>"<?php endif;?>><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                  </div>
                </td>
                <?php endif;?>
                <td data-label="Actions" class="users-actions-cell">
                  <div class="users-row-actions">
                    <div class="users-action-primary">
                      <?php if(!$isCurrent):?><button class="btn sm users-reset-button" type="button" data-reset-user data-user-id="<?=$u['id']?>" data-user-name="<?=e($u['full_name'])?>" data-user-email="<?=e($u['email'])?>" data-user-initials="<?=e(initials($u['full_name']))?>">Reset password</button><?php else:?><span class="users-current-account">Current account</span><?php endif;?>
                    </div>
                    <div class="users-action-menu-slot">
                      <button class="users-more-btn" type="button" aria-label="Open user actions" aria-expanded="false" data-user-menu-button>⋮</button>
                      <div class="users-row-menu" hidden><?php if(Auth::can('users.manage')):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle_user_status"><input type="hidden" name="user_id" value="<?=$u['id']?>"><button type="submit" <?=$isCurrent?'disabled':''?>><?=$u['status']==='ACTIVE'?'Deactivate account':'Activate account'?></button></form><?php else:?><span>No available actions</span><?php endif;?></div>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach;?>
            <tr class="users-no-results" id="usersNoResults" hidden><td colspan="<?=6+($isSuperAdmin?1:0)?>">No user accounts match the current search or filters.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <?php if($isSuperAdmin):?>
    <div class="users-modal-backdrop" id="passwordAccessModal" hidden>
      <div class="users-modal users-password-access-modal" role="dialog" aria-modal="true" aria-labelledby="passwordAccessTitle" tabindex="-1">
        <div class="users-modal-head"><div><h2 id="passwordAccessTitle">Password access</h2><p>Secure credential support for this account.</p></div><button class="users-modal-close" type="button" aria-label="Close password access dialog" data-close-password-access>×</button></div>
        <div class="users-modal-user"><span class="users-modal-avatar" id="passwordAccessInitials">U</span><div><strong id="passwordAccessName">User</strong><span id="passwordAccessEmail">user@pmbsi.com</span></div></div>
        <div class="users-credential-reveal" id="passwordCredentialReveal" hidden>
          <label for="passwordCredentialValue">Temporary password</label>
          <div class="users-password-input"><input id="passwordCredentialValue" type="password" readonly autocomplete="off"><button class="users-eye-btn" type="button" aria-label="Show temporary password" data-password-toggle="#passwordCredentialValue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
          <div class="users-credential-note success">This temporary password was just issued in your current Super Admin session. Copy it now; it will not be recoverable after this page is left or refreshed.</div>
          <div class="users-modal-actions"><button class="btn" type="button" id="copyCredentialPassword"><?=icon_svg('clipboard')?> <span>Copy password</span></button><button class="btn primary" type="button" data-close-password-access>Done</button></div>
        </div>
        <div class="users-credential-protected" id="passwordCredentialProtected">
          <div class="users-password-help"><span class="users-help-lock"><?=icon_svg('lock')?></span><div><strong>Current password is protected.</strong><br>PMBSI HRIS stores account passwords using secure one-way hashing. The existing password cannot be revealed, even to a Super Administrator.</div></div>
          <p class="users-credential-guidance">If the user forgot the password, issue a new temporary password. This keeps the account secure and preserves the production authentication standard.</p>
          <div class="users-modal-actions"><button class="btn" type="button" data-close-password-access>Close</button><button class="btn primary" type="button" id="passwordAccessResetButton"><?=icon_svg('key')?> <span>Reset password</span></button></div>
        </div>
      </div>
    </div>

    <div class="users-modal-backdrop" id="resetPasswordModal" hidden>
      <div class="users-modal" role="dialog" aria-modal="true" aria-labelledby="resetPasswordTitle" tabindex="-1">
        <div class="users-modal-head"><div><h2 id="resetPasswordTitle">Reset password</h2><p>Set a new temporary password for this user.</p></div><button class="users-modal-close" type="button" aria-label="Close reset password dialog" data-close-reset-modal>×</button></div>
        <div class="users-modal-user"><span class="users-modal-avatar" id="resetModalInitials">U</span><div><strong id="resetModalName">User</strong><span id="resetModalEmail">user@pmbsi.com</span></div></div>
        <form method="post" id="resetPasswordForm">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="reset_user_password">
          <input type="hidden" name="user_id" id="resetModalUserId" value="">
          <div class="field"><label for="resetModalPassword">New temporary password</label><div class="users-password-input"><input id="resetModalPassword" name="new_password" type="password" minlength="10" required autocomplete="new-password" placeholder="Minimum 10 characters"><button class="users-eye-btn" type="button" aria-label="Show new temporary password" data-password-toggle="#resetModalPassword"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div>
          <div class="users-password-help"><span class="users-help-lock"><?=icon_svg('lock')?></span><div>Minimum 10 characters. Include at least one letter and one number.<br>This will be set as a temporary password for the user.</div></div>
          <div class="users-modal-actions"><button class="btn" type="button" data-close-reset-modal>Cancel</button><button class="btn primary" type="submit"><?=icon_svg('key')?> <span>Save new password</span></button></div>
        </form>
      </div>
    </div>
    <?php endif;?>


    <script>
    (()=>{
      const qs=(s,r=document)=>r.querySelector(s), qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
      const table=qs('#usersAccessTable');
      const rows=qsa('[data-user-row]');
      const search=qs('#userAccountSearch');
      const role=qs('#filterRole');
      const dept=qs('#filterDepartment');
      const status=qs('#filterStatus');
      const count=qs('#usersVisibleCount');
      const noResults=qs('#usersNoResults');
      const filterBtn=qs('#userFiltersButton');
      const filterPanel=qs('#userFiltersPanel');
      const clearBtn=qs('#clearUserFilters');
      const normalize=v=>(v||'').toString().trim().toLowerCase();
      const applyFilters=()=>{
        const q=normalize(search?.value), rv=normalize(role?.value), dv=normalize(dept?.value), sv=normalize(status?.value);
        let visible=0;
        rows.forEach(row=>{
          const ok=(!q||normalize(row.dataset.search).includes(q))&&(!rv||normalize(row.dataset.role)===rv)&&(!dv||normalize(row.dataset.department)===dv)&&(!sv||normalize(row.dataset.status)===sv);
          row.hidden=!ok; if(ok) visible++;
        });
        if(count) count.textContent=visible;
        if(noResults) noResults.hidden=visible!==0;
      };
      [search,role,dept,status].forEach(el=>el&&el.addEventListener(el===search?'input':'change',applyFilters));
      filterBtn?.addEventListener('click',()=>{const open=filterPanel.hidden;filterPanel.hidden=!open;filterBtn.setAttribute('aria-expanded',open?'true':'false');});
      clearBtn?.addEventListener('click',()=>{if(search)search.value='';if(role)role.value='';if(dept)dept.value='';if(status)status.value='';applyFilters();});

      qsa('[data-password-toggle]').forEach(btn=>btn.addEventListener('click',()=>{const input=qs(btn.dataset.passwordToggle);if(!input)return;input.type=input.type==='password'?'text':'password';btn.classList.toggle('active',input.type==='text');btn.setAttribute('aria-label',input.type==='text'?'Hide password':'Show password');}));

      const passwordAccessModal=qs('#passwordAccessModal');
      const passwordAccessBox=passwordAccessModal?.querySelector('.users-modal');
      const passwordAccessName=qs('#passwordAccessName');
      const passwordAccessEmail=qs('#passwordAccessEmail');
      const passwordAccessInitials=qs('#passwordAccessInitials');
      const credentialReveal=qs('#passwordCredentialReveal');
      const credentialProtected=qs('#passwordCredentialProtected');
      const credentialValue=qs('#passwordCredentialValue');
      const accessResetButton=qs('#passwordAccessResetButton');
      let passwordAccessTrigger=null;
      let passwordAccessData=null;
      const openPasswordAccess=btn=>{
        if(!passwordAccessModal)return;
        passwordAccessTrigger=btn;
        passwordAccessData={userId:btn.dataset.userId||'',userName:btn.dataset.userName||'User',userEmail:btn.dataset.userEmail||'',userInitials:btn.dataset.userInitials||'U',isCurrent:btn.dataset.isCurrent==='1',revealedPassword:btn.dataset.revealedPassword||''};
        passwordAccessName.textContent=passwordAccessData.userName;
        passwordAccessEmail.textContent=passwordAccessData.userEmail;
        passwordAccessInitials.textContent=passwordAccessData.userInitials;
        const hasReveal=passwordAccessData.revealedPassword!=='';
        credentialReveal.hidden=!hasReveal;
        credentialProtected.hidden=hasReveal;
        if(hasReveal){credentialValue.value=passwordAccessData.revealedPassword;credentialValue.type='password';}
        if(accessResetButton)accessResetButton.hidden=passwordAccessData.isCurrent;
        passwordAccessModal.hidden=false;
        document.body.classList.add('users-modal-open');
        requestAnimationFrame(()=>passwordAccessBox?.focus());
      };
      const closePasswordAccess=()=>{if(!passwordAccessModal||passwordAccessModal.hidden)return;passwordAccessModal.hidden=true;document.body.classList.remove('users-modal-open');passwordAccessTrigger?.focus();};
      qsa('[data-password-info]').forEach(btn=>btn.addEventListener('click',()=>openPasswordAccess(btn)));
      qsa('[data-close-password-access]').forEach(btn=>btn.addEventListener('click',closePasswordAccess));
      passwordAccessModal?.addEventListener('mousedown',e=>{if(e.target===passwordAccessModal)closePasswordAccess();});

      qsa('[data-user-menu-button]').forEach(btn=>btn.addEventListener('click',e=>{e.stopPropagation();qsa('.users-row-menu').forEach(m=>{if(m!==btn.nextElementSibling)m.hidden=true});const menu=btn.nextElementSibling;menu.hidden=!menu.hidden;btn.setAttribute('aria-expanded',menu.hidden?'false':'true');}));
      document.addEventListener('click',()=>qsa('.users-row-menu').forEach(m=>m.hidden=true));

      const modal=qs('#resetPasswordModal'), modalBox=modal?.querySelector('.users-modal'), modalId=qs('#resetModalUserId'), modalName=qs('#resetModalName'), modalEmail=qs('#resetModalEmail'), modalInitials=qs('#resetModalInitials'), modalPassword=qs('#resetModalPassword');
      let lastTrigger=null;
      const getFocusable=()=>modal? qsa('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])',modal).filter(el=>!el.disabled&&!el.hidden):[];
      const openModal=btn=>{if(!modal)return;lastTrigger=btn;modalId.value=btn.dataset.userId||'';modalName.textContent=btn.dataset.userName||'User';modalEmail.textContent=btn.dataset.userEmail||'';modalInitials.textContent=btn.dataset.userInitials||'U';modalPassword.value='';modalPassword.type='password';modal.hidden=false;document.body.classList.add('users-modal-open');requestAnimationFrame(()=>modalPassword.focus());};
      const closeModal=()=>{if(!modal||modal.hidden)return;modal.hidden=true;document.body.classList.remove('users-modal-open');lastTrigger?.focus();};
      qsa('[data-reset-user]').forEach(btn=>btn.addEventListener('click',()=>openModal(btn)));
      accessResetButton?.addEventListener('click',()=>{
        if(!passwordAccessData||passwordAccessData.isCurrent)return;
        const proxy={dataset:{userId:passwordAccessData.userId,userName:passwordAccessData.userName,userEmail:passwordAccessData.userEmail,userInitials:passwordAccessData.userInitials},focus:()=>passwordAccessTrigger?.focus()};
        closePasswordAccess();
        openModal(proxy);
      });
      qsa('[data-close-reset-modal]').forEach(btn=>btn.addEventListener('click',closeModal));
      modal?.addEventListener('mousedown',e=>{if(e.target===modal)closeModal();});
      document.addEventListener('keydown',e=>{
        if(passwordAccessModal&&!passwordAccessModal.hidden){
          if(e.key==='Escape'){e.preventDefault();closePasswordAccess();return;}
          if(e.key==='Tab'){const f=qsa('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])',passwordAccessModal).filter(el=>!el.disabled&&!el.hidden);if(f.length){const first=f[0],last=f[f.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}}
          return;
        }
        if(!modal||modal.hidden)return;
        if(e.key==='Escape'){e.preventDefault();closeModal();return;}
        if(e.key==='Tab'){const f=getFocusable();if(!f.length)return;const first=f[0],last=f[f.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}
      });

      const credentialCopyBtn=qs('#copyCredentialPassword'); credentialCopyBtn?.addEventListener('click',async()=>{if(!credentialValue?.value)return;const label=credentialCopyBtn.querySelector('span');try{await navigator.clipboard.writeText(credentialValue.value);if(label)label.textContent='Copied';setTimeout(()=>{if(label)label.textContent='Copy password';},1500);}catch(e){credentialValue.type='text';credentialValue.select();}});
      applyFilters();
    })();
    </script>
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
    $departments=FoundationRepository::departments(); $positions=FoundationRepository::positions(); $areas=FoundationRepository::areas(); $branches=FoundationRepository::branches(); $types=FoundationRepository::employmentTypes();
    $canManage=Auth::can('organization.manage');
    render_portal_header('admin',$page,'Organization Setup'); page_head('HR Admin / Organization','Organization Setup','<span class="badge amber">Core master data</span>'); ?>
    <?php if(!FoundationRepository::tableExists('positions')):?><div class="alert error">Phase 1 foundation migration has not been applied. Import <code>database/migrations/20260924_phase1_foundation.sql</code>.</div><?php endif;?>
    <div class="org-grid">
      <section class="panel org-card"><div class="panel-head"><div><h2>Departments</h2><p><?=count($departments)?> records</p></div></div><?php if($canManage):?><form method="post" class="master-add"><?=csrf_field()?><input type="hidden" name="action" value="create_department"><input name="code" placeholder="Code" required><input name="name" placeholder="Department name" required><button class="btn primary sm"><?=icon_svg('plus')?> Add</button></form><?php endif;?><div class="master-list"><?php foreach($departments as $x):?><div class="master-row"><div><strong><?=e($x['name'])?></strong><span><?=e($x['code'])?> · <?=$x['position_count']?> positions</span></div><div><span class="badge <?=$x['active']?'green':'gray'?>"><?=$x['active']?'Active':'Inactive'?></span><?php if($canManage):?><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle_master"><input type="hidden" name="entity" value="department"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="mini-action" title="Toggle status">•••</button></form><?php endif;?></div></div><?php endforeach;?></div></section>
      <section class="panel org-card"><div class="panel-head"><div><h2>Positions</h2><p><?=count($positions)?> records</p></div></div><?php if($canManage):?><form method="post" class="master-add master-add-4"><?=csrf_field()?><input type="hidden" name="action" value="create_position"><input name="code" placeholder="Code" required><input name="name" placeholder="Position name" required><select name="department_id"><option value="">No department</option><?php foreach($departments as $d):?><option value="<?=$d['id']?>"><?=e($d['name'])?></option><?php endforeach;?></select><button class="btn primary sm"><?=icon_svg('plus')?> Add</button></form><?php endif;?><div class="master-list"><?php foreach($positions as $x):?><div class="master-row"><div><strong><?=e($x['name'])?></strong><span><?=e($x['code'])?> · <?=e($x['department_name']??'Unassigned')?></span></div><div><span class="badge <?=$x['active']?'green':'gray'?>"><?=$x['active']?'Active':'Inactive'?></span><?php if($canManage):?><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle_master"><input type="hidden" name="entity" value="position"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="mini-action">•••</button></form><?php endif;?></div></div><?php endforeach;?></div></section>
      <section class="panel org-card"><div class="panel-head"><div><h2>Areas</h2><p><?=count($areas)?> records</p></div></div><?php if($canManage):?><form method="post" class="master-add"><?=csrf_field()?><input type="hidden" name="action" value="create_area"><input name="code" placeholder="Code" required><input name="name" placeholder="Area name" required><button class="btn primary sm"><?=icon_svg('plus')?> Add</button></form><?php endif;?><div class="master-list"><?php if(!$areas):?><div class="empty">Add the official PMBSI areas used for employee and branch assignment.</div><?php endif;foreach($areas as $x):?><div class="master-row"><div><strong><?=e($x['name'])?></strong><span><?=e($x['code'])?></span></div><div><span class="badge <?=$x['active']?'green':'gray'?>"><?=$x['active']?'Active':'Inactive'?></span><?php if($canManage):?><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle_master"><input type="hidden" name="entity" value="area"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="mini-action">•••</button></form><?php endif;?></div></div><?php endforeach;?></div></section>
      <section class="panel org-card"><div class="panel-head"><div><h2>Branches & Sites</h2><p><?=count($branches)?> records</p></div></div><?php if($canManage):?><form method="post" class="master-add master-add-4"><?=csrf_field()?><input type="hidden" name="action" value="create_branch"><input name="code" placeholder="Code" required><input name="name" placeholder="Branch name" required><select name="area_id"><option value="">No area</option><?php foreach($areas as $a):if(!$a['active'])continue;?><option value="<?=$a['id']?>"><?=e($a['name'])?></option><?php endforeach;?></select><input name="address_text" placeholder="City / address"><button class="btn primary sm"><?=icon_svg('plus')?> Add</button></form><?php endif;?><div class="master-list"><?php foreach($branches as $x):?><div class="master-row"><div><strong><?=e($x['name'])?></strong><span><?=e($x['code'])?> · <?=e($x['area_name']??'No area')?> · <?=e($x['address_text']??'No address')?></span></div><div><span class="badge <?=$x['active']?'green':'gray'?>"><?=$x['active']?'Active':'Inactive'?></span><?php if($canManage):?><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle_master"><input type="hidden" name="entity" value="branch"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="mini-action">•••</button></form><?php endif;?></div></div><?php endforeach;?></div></section>
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
    <div class="dashboard-grid equal"><section class="panel"><div class="panel-head"><div><h2>Security baseline</h2><p>Controls already enforced in Phase 1</p></div></div><div class="panel-body"><?php foreach(['Server-side role and permission checks','CSRF verification for all write actions','PDO prepared statements','Password hashing and session ID regeneration','Tracked login sessions with revocation support','Audit logging for access-control changes'] as $x):?><div class="srow"><span class="badge green">On</span><div class="small" style="flex:1"><?=e($x)?></div></div><?php endforeach;?></div></section><section class="panel"><div class="panel-head"><div><h2>Production checklist</h2><p>Before public deployment</p></div></div><div class="panel-body"><p class="muted small">Disable debug mode, review inactive/test accounts, enforce HTTPS, use a dedicated MySQL account, enable backups, configure secure upload storage and review least-privilege role assignments.</p></div></section></div>
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
