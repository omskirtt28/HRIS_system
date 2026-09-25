<?php
declare(strict_types=1);

final class RecruitmentRepository
{
    public static function publishedJobs(?string $q=null, ?string $department=null, ?string $type=null): array {
        $sql='SELECT j.*, c.name client_name, d.name department_name FROM job_openings j JOIN clients c ON c.id=j.client_id LEFT JOIN departments d ON d.id=j.department_id WHERE j.status="PUBLISHED"';
        $p=[];
        if ($q) { $sql.=' AND (j.title LIKE ? OR c.name LIKE ? OR j.location_text LIKE ?)'; $x='%'.$q.'%'; array_push($p,$x,$x,$x); }
        if ($department) { $sql.=' AND d.name=?'; $p[]=$department; }
        if ($type) { $sql.=' AND j.employment_type=?'; $p[]=$type; }
        $sql.=' ORDER BY j.is_featured DESC,j.published_at DESC,j.id DESC';
        $st=db()->prepare($sql); $st->execute($p); return $st->fetchAll();
    }
    public static function jobBySlug(string $slug): ?array {
        $st=db()->prepare('SELECT j.*, c.name client_name, d.name department_name FROM job_openings j JOIN clients c ON c.id=j.client_id LEFT JOIN departments d ON d.id=j.department_id WHERE j.slug=? AND j.status="PUBLISHED" LIMIT 1');
        $st->execute([$slug]); return $st->fetch() ?: null;
    }
    public static function stages(): array { return db()->query('SELECT * FROM recruitment_stages WHERE active=1 ORDER BY sequence_no')->fetchAll(); }
    public static function clients(): array { return db()->query('SELECT * FROM clients WHERE status="ACTIVE" ORDER BY name')->fetchAll(); }
    public static function departments(): array { return db()->query('SELECT * FROM departments WHERE active=1 ORDER BY name')->fetchAll(); }
    public static function branches(): array { return db()->query('SELECT b.*,c.name client_name FROM branches b LEFT JOIN clients c ON c.id=b.client_id WHERE b.active=1 ORDER BY b.name')->fetchAll(); }
    public static function createApplication(array $d, ?array $file): string {
        $pdo=db(); $pdo->beginTransaction();
        try {
            $st=$pdo->prepare('SELECT id FROM applicants WHERE LOWER(email)=LOWER(?) LIMIT 1'); $st->execute([$d['email']]); $applicantId=$st->fetchColumn();
            if (!$applicantId) {
                $no='APP-'.date('Y').'-'.str_pad((string)random_int(1,999999),6,'0',STR_PAD_LEFT);
                $st=$pdo->prepare('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status,created_at,updated_at) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE",NOW(),NOW())');
                $st->execute([$no,$d['first_name'],$d['last_name'],$d['email'],$d['mobile_no']]); $applicantId=(int)$pdo->lastInsertId();
            }
            $job=self::jobById((int)$d['job_opening_id']); if (!$job) throw new RuntimeException('Selected job is no longer available.');
            $dup=$pdo->prepare('SELECT application_no FROM applications WHERE applicant_id=? AND job_opening_id=? AND status="ACTIVE" LIMIT 1'); $dup->execute([$applicantId,$job['id']]);
            if ($x=$dup->fetchColumn()) throw new RuntimeException('You already have an active application for this position. Reference: '.$x);
            $stage=(int)$pdo->query('SELECT id FROM recruitment_stages WHERE code="APPLIED" LIMIT 1')->fetchColumn();
            $ref='PMBSI-'.date('Y').'-'.str_pad((string)random_int(1,999999),6,'0',STR_PAD_LEFT);
            $st=$pdo->prepare('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,why_fit,applied_at,last_stage_changed_at,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,NOW(),NOW(),"ACTIVE",NOW(),NOW())');
            $st->execute([$ref,$applicantId,$job['id'],$job['manpower_request_id'],$job['client_id'],$stage,$d['why_fit']]); $applicationId=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO application_stage_history(application_id,to_stage_id,comment,changed_at) VALUES(?,?,"Application submitted through Careers site",NOW())')->execute([$applicationId,$stage]);
            if ($file && $file['error']===UPLOAD_ERR_OK) self::storeResume($applicationId,$file);
            $pdo->commit(); audit('Recruitment','APPLICATION_SUBMIT','application',$applicationId,['reference'=>$ref]); return $ref;
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }
    private static function storeResume(int $applicationId,array $file): void {
        if ($file['size'] > cfg('uploads.max_bytes')) throw new RuntimeException('Resume exceeds the 5MB limit.');
        $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($file['tmp_name']); $allowed=cfg('uploads.allowed_mime',[]);
        if (!isset($allowed[$mime])) throw new RuntimeException('Resume must be PDF, DOC, or DOCX.');
        $dir=cfg('uploads.resume_dir'); if (!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('Upload folder cannot be created.');
        $stored=bin2hex(random_bytes(16)).'.'.$allowed[$mime]; $dest=$dir.'/'.$stored;
        if (!move_uploaded_file($file['tmp_name'],$dest)) throw new RuntimeException('Resume upload failed.');
        $st=db()->prepare('INSERT INTO application_documents(application_id,document_type,original_name,stored_name,mime_type,file_size,created_at) VALUES(?,"RESUME",?,?,?,?,NOW())');
        $st->execute([$applicationId,$file['name'],$stored,$mime,$file['size']]);
    }
    public static function jobById(int $id): ?array { $st=db()->prepare('SELECT * FROM job_openings WHERE id=? AND status="PUBLISHED"'); $st->execute([$id]); return $st->fetch()?:null; }
    public static function track(string $ref,string $email): ?array {
        $st=db()->prepare('SELECT a.id,a.application_no,a.applied_at,j.title,c.name client_name,s.code stage_code,s.name stage_name FROM applications a JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id JOIN clients c ON c.id=a.client_id JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.application_no=? AND LOWER(ap.email)=LOWER(?) LIMIT 1');
        $st->execute([$ref,$email]); $row=$st->fetch(); if (!$row) return null;
        $hs=db()->prepare('SELECT h.*,fs.name from_name,ts.name to_name FROM application_stage_history h LEFT JOIN recruitment_stages fs ON fs.id=h.from_stage_id JOIN recruitment_stages ts ON ts.id=h.to_stage_id WHERE h.application_id=? ORDER BY h.changed_at'); $hs->execute([$row['id']]); $row['history']=$hs->fetchAll(); return $row;
    }
    public static function applications(array $filters=[]): array {
        $sql='SELECT a.id,a.application_no,a.status application_status,a.screening_score,a.applied_at,a.last_stage_changed_at,ap.first_name,ap.last_name,ap.email,ap.mobile_no,j.title job_title,c.name client_name,s.code stage_code,s.name stage_name,u.full_name recruiter_name FROM applications a JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id JOIN clients c ON c.id=a.client_id JOIN recruitment_stages s ON s.id=a.current_stage_id LEFT JOIN users u ON u.id=a.assigned_recruiter_id WHERE 1=1'; $p=[];
        if (!empty($filters['client_id'])) { $sql.=' AND a.client_id=?'; $p[]=$filters['client_id']; }
        if (!empty($filters['client_portal'])) { $sql.=' AND EXISTS (SELECT 1 FROM endorsements e WHERE e.application_id=a.id AND e.client_id=a.client_id)'; }
        if (!empty($filters['stage'])) { $sql.=' AND s.code=?'; $p[]=$filters['stage']; }
        if (!empty($filters['q'])) { $x='%'.$filters['q'].'%'; $sql.=' AND (ap.first_name LIKE ? OR ap.last_name LIKE ? OR ap.email LIKE ? OR a.application_no LIKE ?)'; array_push($p,$x,$x,$x,$x); }
        $sql.=' ORDER BY a.last_stage_changed_at DESC,a.id DESC'; $st=db()->prepare($sql); $st->execute($p); return $st->fetchAll();
    }
    public static function application(int $id, ?int $clientId=null): ?array {
        $sql='SELECT a.*,ap.applicant_no,ap.first_name,ap.middle_name,ap.last_name,ap.suffix,ap.email,ap.mobile_no,j.title job_title,j.location_text,j.department_id job_department_id,j.employment_type job_employment_type,m.branch_id request_branch_id,m.department_id request_department_id,c.name client_name,s.code stage_code,s.name stage_name,u.full_name recruiter_name FROM applications a JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id JOIN manpower_requests m ON m.id=a.manpower_request_id JOIN clients c ON c.id=a.client_id JOIN recruitment_stages s ON s.id=a.current_stage_id LEFT JOIN users u ON u.id=a.assigned_recruiter_id WHERE a.id=?'; $p=[$id];
        if ($clientId) { $sql.=' AND a.client_id=? AND EXISTS (SELECT 1 FROM endorsements e WHERE e.application_id=a.id AND e.client_id=?)'; $p[]=$clientId; $p[]=$clientId; }
        $st=db()->prepare($sql); $st->execute($p); $a=$st->fetch(); if(!$a)return null;
        $hs=db()->prepare('SELECT h.*,fs.name from_name,ts.name to_name,u.full_name changed_by_name FROM application_stage_history h LEFT JOIN recruitment_stages fs ON fs.id=h.from_stage_id JOIN recruitment_stages ts ON ts.id=h.to_stage_id LEFT JOIN users u ON u.id=h.changed_by WHERE h.application_id=? ORDER BY h.changed_at DESC'); $hs->execute([$id]); $a['history']=$hs->fetchAll();
        $iv=db()->prepare('SELECT i.*,u.full_name interviewer_name FROM interviews i LEFT JOIN users u ON u.id=i.interviewer_id WHERE i.application_id=? ORDER BY i.scheduled_at DESC'); $iv->execute([$id]); $a['interviews']=$iv->fetchAll();
        $of=db()->prepare('SELECT * FROM offers WHERE application_id=? ORDER BY id DESC LIMIT 1'); $of->execute([$id]); $a['offer']=$of->fetch()?:null;
        $dp=db()->prepare('SELECT d.*,b.name branch_name FROM deployments d LEFT JOIN branches b ON b.id=d.branch_id WHERE d.application_id=? ORDER BY d.id DESC LIMIT 1'); $dp->execute([$id]); $a['deployment']=$dp->fetch()?:null;
        return $a;
    }
    private static function assertApplicationMutable(int $applicationId): void {
        if(!self::phase2CReady()) return;
        $st=db()->prepare('SELECT converted_employee_id FROM applications WHERE id=? LIMIT 1');$st->execute([$applicationId]);$converted=(int)($st->fetchColumn()?:0);
        if($converted>0) throw new RuntimeException('This recruitment record has already been converted to an employee and is locked for further recruitment changes.');
    }

    public static function moveStage(int $id,string $target,?string $comment=null): void {
        self::assertApplicationMutable($id);
        $pdo=db(); $pdo->beginTransaction(); try {
            $st=$pdo->prepare('SELECT current_stage_id FROM applications WHERE id=? FOR UPDATE'); $st->execute([$id]); $from=(int)$st->fetchColumn(); if(!$from) throw new RuntimeException('Application not found.');
            $ts=$pdo->prepare('SELECT id FROM recruitment_stages WHERE code=? AND active=1'); $ts->execute([$target]); $to=(int)$ts->fetchColumn(); if(!$to) throw new RuntimeException('Invalid target stage.');
            $pdo->prepare('UPDATE applications SET current_stage_id=?,last_stage_changed_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$to,$id]);
            $pdo->prepare('INSERT INTO application_stage_history(application_id,from_stage_id,to_stage_id,comment,changed_by,changed_at) VALUES(?,?,?,?,?,NOW())')->execute([$id,$from,$to,$comment,$_SESSION['user']['id']??null]);
            $pdo->commit(); audit('Recruitment','MOVE_STAGE','application',$id,['target'=>$target]);
        } catch(Throwable $e){$pdo->rollBack();throw $e;}
    }
    public static function scheduleInterview(int $id,array $d): void {
        self::assertApplicationMutable($id);
        $st=db()->prepare('INSERT INTO interviews(application_id,interview_type,scheduled_at,location_or_link,interviewer_id,status,notes,created_at,updated_at) VALUES(?,?,?,?,?,"SCHEDULED",?,NOW(),NOW())');
        $st->execute([$id,$d['interview_type'],$d['scheduled_at'],$d['location_or_link'],$_SESSION['user']['id']??null,$d['notes']]);
        self::moveStage($id,'INTERVIEW','Interview scheduled'); audit('Recruitment','SCHEDULE_INTERVIEW','application',$id);
    }
    public static function createManpowerRequest(array $d): int {
        $no='MR-'.date('Ymd').'-'.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);
        $st=db()->prepare('INSERT INTO manpower_requests(request_no,client_id,branch_id,department_id,position_title,requested_headcount,employment_type,priority,target_start_date,status,requested_by,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,"SUBMITTED",?,?,NOW(),NOW())');
        $st->execute([$no,$d['client_id'],$d['branch_id']?:null,$d['department_id']?:null,$d['position_title'],$d['requested_headcount'],$d['employment_type'],$d['priority'],$d['target_start_date']?:null,$_SESSION['user']['id'],$d['notes']]);
        $id=(int)db()->lastInsertId(); audit('Recruitment','CREATE_MANPOWER_REQUEST','manpower_request',$id,['request_no'=>$no]); return $id;
    }
    public static function manpowerRequests(): array {
        return db()->query('SELECT m.*,c.name client_name,b.name branch_name,d.name department_name,u.full_name requested_by_name FROM manpower_requests m JOIN clients c ON c.id=m.client_id LEFT JOIN branches b ON b.id=m.branch_id LEFT JOIN departments d ON d.id=m.department_id LEFT JOIN users u ON u.id=m.requested_by ORDER BY m.created_at DESC')->fetchAll();
    }
    public static function stageCounts(?int $clientId=null): array {
        $sql='SELECT s.code,s.name,COUNT(a.id) total FROM recruitment_stages s LEFT JOIN applications a ON a.current_stage_id=s.id'; $p=[];
        if($clientId){$sql.=' AND a.client_id=?';$p[]=$clientId;}
        $sql.=' WHERE s.active=1 GROUP BY s.id,s.code,s.name,s.sequence_no ORDER BY s.sequence_no'; $st=db()->prepare($sql);$st->execute($p);return $st->fetchAll();
    }
    public static function dashboard(?int $clientId=null): array {
        $where=$clientId?' WHERE client_id='.(int)$clientId:'';
        $active=(int)db()->query('SELECT COUNT(*) FROM applications'.$where)->fetchColumn();
        $extra=$clientId?' AND a.client_id='.(int)$clientId:'';
        $interviews=(int)db()->query('SELECT COUNT(*) FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE s.code="INTERVIEW"'.$extra)->fetchColumn();
        $endorsed=(int)db()->query('SELECT COUNT(*) FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE s.code IN("ENDORSED","CLIENT_REVIEW")'.$extra)->fetchColumn();
        $deployed=(int)db()->query('SELECT COUNT(*) FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE s.code="DEPLOYED"'.$extra)->fetchColumn();
        $openReq=(int)db()->query('SELECT COALESCE(SUM(requested_headcount-filled_headcount),0) FROM manpower_requests WHERE status IN("APPROVED","OPEN","PARTIALLY_FILLED","SUBMITTED")'.($clientId?' AND client_id='.(int)$clientId:''))->fetchColumn();
        return compact('active','interviews','endorsed','deployed','openReq');
    }
    public static function clientDecision(int $applicationId,string $decision,string $remarks=''): void {
        self::assertApplicationMutable($applicationId);
        $uid=$_SESSION['user']['id']; $clientId=$_SESSION['user']['client_id'];
        $app=self::application($applicationId,$clientId); if(!$app) throw new RuntimeException('Candidate not found in your client scope.');
        if (!in_array($app['stage_code'],['ENDORSED','CLIENT_REVIEW'],true)) throw new RuntimeException('This candidate is not awaiting a client decision.');
        $st=db()->prepare('INSERT INTO client_reviews(application_id,client_id,reviewer_user_id,decision,remarks,decided_at,created_at) VALUES(?,?,?,?,?,NOW(),NOW())');
        $st->execute([$applicationId,$clientId,$uid,$decision,$remarks]);
        if($decision==='APPROVED') self::moveStage($applicationId,'OFFER','Client approved candidate');
        elseif($decision==='DECLINED') self::moveStage($applicationId,'RETURNED','Client declined candidate');
        else self::moveStage($applicationId,'INTERVIEW','Client requested interview');
        audit('Recruitment','CLIENT_DECISION','application',$applicationId,['decision'=>$decision]);
    }
    public static function endorse(int $applicationId,string $note=''): void {
        self::assertApplicationMutable($applicationId);
        $app=self::application($applicationId); if(!$app) throw new RuntimeException('Application not found.');
        if (in_array($app['stage_code'],['REJECTED','WITHDRAWN','DEPLOYED'],true)) throw new RuntimeException('This application can no longer be endorsed.');
        $st=db()->prepare('SELECT id FROM endorsements WHERE application_id=? AND client_id=? LIMIT 1'); $st->execute([$applicationId,$app['client_id']]);
        if (!$st->fetchColumn()) {
            $ins=db()->prepare('INSERT INTO endorsements(application_id,client_id,endorsed_by,endorsement_note,endorsed_at,status) VALUES(?,?,?,?,NOW(),"SENT")');
            $ins->execute([$applicationId,$app['client_id'],$_SESSION['user']['id'],$note]);
        }
        self::moveStage($applicationId,'CLIENT_REVIEW',$note?:'Candidate endorsed to client');
        audit('Recruitment','ENDORSE','application',$applicationId,['client_id'=>$app['client_id']]);
    }
    public static function saveOffer(int $applicationId,array $d): void {
        self::assertApplicationMutable($applicationId);
        $app=self::application($applicationId); if(!$app) throw new RuntimeException('Application not found.');
        $st=db()->prepare('SELECT id FROM offers WHERE application_id=? ORDER BY id DESC LIMIT 1'); $st->execute([$applicationId]); $id=$st->fetchColumn();
        if($id){
            db()->prepare('UPDATE offers SET offered_salary=?,employment_type=?,start_date=?,status="SENT",sent_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$d['offered_salary']?:null,$d['employment_type'],$d['start_date']?:null,$id]);
        } else {
            $no='OFR-'.date('Ymd').'-'.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);
            db()->prepare('INSERT INTO offers(application_id,offer_no,offered_salary,employment_type,start_date,status,sent_at,created_by,created_at,updated_at) VALUES(?,?,?,?,?,"SENT",NOW(),?,NOW(),NOW())')->execute([$applicationId,$no,$d['offered_salary']?:null,$d['employment_type'],$d['start_date']?:null,$_SESSION['user']['id']]);
        }
        self::moveStage($applicationId,'OFFER','Offer prepared/sent'); audit('Recruitment','OFFER_SENT','application',$applicationId);
    }
    public static function acceptOffer(int $applicationId): void {
        self::assertApplicationMutable($applicationId);
        $st=db()->prepare('UPDATE offers SET status="ACCEPTED",responded_at=NOW(),updated_at=NOW() WHERE application_id=?'); $st->execute([$applicationId]);
        if(!$st->rowCount()) throw new RuntimeException('Create an offer first.');
        self::moveStage($applicationId,'DEPLOYMENT','Offer accepted; preparing deployment'); audit('Recruitment','OFFER_ACCEPTED','application',$applicationId);
    }
    public static function saveDeployment(int $applicationId,array $d): void {
        self::assertApplicationMutable($applicationId);
        $st=db()->prepare('SELECT id FROM deployments WHERE application_id=? ORDER BY id DESC LIMIT 1'); $st->execute([$applicationId]); $id=$st->fetchColumn();
        if($id){
            db()->prepare('UPDATE deployments SET branch_id=?,scheduled_date=?,notes=?,status="SCHEDULED",updated_at=NOW() WHERE id=?')->execute([$d['branch_id']?:null,$d['scheduled_date']?:null,$d['notes'],$id]);
        } else {
            $no='DEP-'.date('Ymd').'-'.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);
            db()->prepare('INSERT INTO deployments(application_id,deployment_no,branch_id,scheduled_date,status,notes,created_by,created_at,updated_at) VALUES(?,?,?,?,"SCHEDULED",?,?,NOW(),NOW())')->execute([$applicationId,$no,$d['branch_id']?:null,$d['scheduled_date']?:null,$d['notes'],$_SESSION['user']['id']]);
        }
        self::moveStage($applicationId,'DEPLOYMENT','Deployment scheduled'); audit('Recruitment','DEPLOYMENT_SCHEDULED','application',$applicationId);
    }
    public static function completeDeployment(int $applicationId): void {
        self::assertApplicationMutable($applicationId);
        $pdo=db(); $pdo->beginTransaction();
        try {
            $st=$pdo->prepare('SELECT current_stage_id,manpower_request_id FROM applications WHERE id=? FOR UPDATE'); $st->execute([$applicationId]); $app=$st->fetch();
            if(!$app) throw new RuntimeException('Application not found.');
            $target=(int)$pdo->query('SELECT id FROM recruitment_stages WHERE code="DEPLOYED" LIMIT 1')->fetchColumn();
            if(!$target) throw new RuntimeException('DEPLOYED stage is not configured.');
            $dep=$pdo->prepare('UPDATE deployments SET status="DEPLOYED",actual_date=CURDATE(),updated_at=NOW() WHERE application_id=? AND status<>"DEPLOYED"'); $dep->execute([$applicationId]);
            if(!$dep->rowCount()) throw new RuntimeException('Schedule deployment first or the candidate is already deployed.');
            $pdo->prepare('UPDATE manpower_requests SET filled_headcount=LEAST(requested_headcount,filled_headcount+1), status=CASE WHEN filled_headcount+1>=requested_headcount THEN "FILLED" ELSE "PARTIALLY_FILLED" END, updated_at=NOW() WHERE id=?')->execute([$app['manpower_request_id']]);
            $pdo->prepare('UPDATE applications SET current_stage_id=?,last_stage_changed_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$target,$applicationId]);
            $pdo->prepare('INSERT INTO application_stage_history(application_id,from_stage_id,to_stage_id,comment,changed_by,changed_at) VALUES(?,?,?,?,?,NOW())')->execute([$applicationId,$app['current_stage_id'],$target,'Deployment completed',$_SESSION['user']['id']??null]);
            $pdo->commit(); audit('Recruitment','DEPLOYED','application',$applicationId);
        } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }
    public static function phase2CReady(): bool {
        try {
            $st=db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="applications" AND column_name IN ("converted_employee_id","converted_at","converted_by")');
            $st->execute();
            return (int)$st->fetchColumn()===3 && EmployeeRepository::phase2Ready();
        } catch(Throwable) { return false; }
    }

    public static function applicationDocuments(int $applicationId): array {
        if(!FoundationRepository::tableExists('application_documents')) return [];
        $st=db()->prepare('SELECT * FROM application_documents WHERE application_id=? ORDER BY created_at,id');
        $st->execute([$applicationId]);
        return $st->fetchAll();
    }

    public static function employeeSource(int $employeeId): ?array {
        if(!self::phase2CReady()) return null;
        $sql='SELECT a.id application_id,a.application_no,a.converted_at,ap.applicant_no,j.title job_title,c.name client_name,u.full_name converted_by_name
              FROM applications a
              JOIN applicants ap ON ap.id=a.applicant_id
              JOIN job_openings j ON j.id=a.job_opening_id
              JOIN clients c ON c.id=a.client_id
              LEFT JOIN users u ON u.id=a.converted_by
              WHERE a.converted_employee_id=? LIMIT 1';
        $st=db()->prepare($sql);$st->execute([$employeeId]);$r=$st->fetch();return $r?:null;
    }

    public static function employeeDuplicateByEmail(string $email): ?array {
        $email=strtolower(trim($email));if($email===''||!EmployeeRepository::ready())return null;
        $st=db()->prepare('SELECT id,employee_no,first_name,middle_name,last_name,company_email,personal_email FROM employees WHERE LOWER(COALESCE(company_email,""))=LOWER(?) OR LOWER(COALESCE(personal_email,""))=LOWER(?) LIMIT 1');
        $st->execute([$email,$email]);$r=$st->fetch();return $r?:null;
    }

    public static function conversionDefaults(int $applicationId): array {
        $a=self::application($applicationId); if(!$a) throw new RuntimeException('Application not found.');
        $positions=FoundationRepository::positions();
        $types=FoundationRepository::employmentTypes();
        $departmentId=(int)($a['job_department_id']??0) ?: (int)($a['request_department_id']??0) ?: null;
        $branchId=(int)($a['deployment']['branch_id']??0) ?: (int)($a['request_branch_id']??0) ?: null;
        $positionId=null;
        foreach($positions as $p){
            if(strcasecmp(trim((string)$p['name']),trim((string)$a['job_title']))===0){$positionId=(int)$p['id'];break;}
        }
        $employmentTypeId=null;
        $sourceType=strtoupper(trim((string)($a['offer']['employment_type']??$a['job_employment_type']??'')));
        $map=['CONTRACT'=>'CONTRACTUAL','FULL_TIME'=>'PROBATIONARY','PART_TIME'=>'PART_TIME','PROJECT_BASED'=>'PROJECT_BASED','FIXED_TERM'=>'FIXED_TERM','INTERN'=>'INTERN'];
        $wanted=$map[$sourceType]??$sourceType;
        foreach($types as $t){if(strtoupper((string)$t['code'])===$wanted){$employmentTypeId=(int)$t['id'];break;}}
        if(!$employmentTypeId){foreach($types as $t){if(strtoupper((string)$t['code'])==='PROBATIONARY'){$employmentTypeId=(int)$t['id'];break;}}}
        $hireDate=(string)($a['offer']['start_date']??'');
        if($hireDate==='') $hireDate=(string)($a['deployment']['actual_date']??'');
        if($hireDate==='') $hireDate=date('Y-m-d');
        return [
            'employee_no'=>EmployeeRepository::nextEmployeeNo(),
            'first_name'=>(string)$a['first_name'],'middle_name'=>(string)($a['middle_name']??''),'last_name'=>(string)$a['last_name'],'suffix'=>(string)($a['suffix']??''),
            'personal_email'=>(string)$a['email'],'mobile_no'=>(string)$a['mobile_no'],'department_id'=>$departmentId,'position_id'=>$positionId,'branch_id'=>$branchId,
            'employment_type_id'=>$employmentTypeId,'hire_date'=>$hireDate,'status'=>'PROBATIONARY'
        ];
    }

    public static function convertToEmployee(int $applicationId,array $data): int {
        if(!self::phase2CReady()) throw new RuntimeException('Import the Phase 2C recruitment-to-employee migration first.');
        $pdo=db();$copiedFiles=[];$pdo->beginTransaction();
        try {
            $lock=$pdo->prepare('SELECT a.id,a.applicant_id,a.current_stage_id,a.converted_employee_id,a.status,s.code stage_code FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.id=? FOR UPDATE');
            $lock->execute([$applicationId]);$locked=$lock->fetch();
            if(!$locked) throw new RuntimeException('Application not found.');
            if(!empty($locked['converted_employee_id'])) throw new RuntimeException('This application has already been converted to an employee.');
            if(!in_array((string)$locked['stage_code'],['DEPLOYED','HIRED'],true)) throw new RuntimeException('Only hired/deployed candidates can be converted to an employee record.');

            $a=self::application($applicationId);if(!$a) throw new RuntimeException('Application not found.');
            $deploymentComplete=(string)$locked['stage_code']==='HIRED' || (($a['deployment']['status']??'')==='DEPLOYED');
            if(!$deploymentComplete) throw new RuntimeException('Complete the deployment record before converting this candidate to an employee.');
            $personalEmail=strtolower(trim((string)($data['personal_email']??$a['email']??'')));
            if($personalEmail!==''){
                $dupe=$pdo->prepare('SELECT id,employee_no FROM employees WHERE LOWER(COALESCE(company_email,""))=LOWER(?) OR LOWER(COALESCE(personal_email,""))=LOWER(?) LIMIT 1');
                $dupe->execute([$personalEmail,$personalEmail]);
                if($existing=$dupe->fetch()) throw new RuntimeException('An employee record already uses this email ('.$existing['employee_no'].'). Review the existing employee instead of converting a duplicate.');
            }

            $accountMode=(string)($data['account_mode']??'later');$userId=null;
            if($accountMode==='existing'){
                $userId=(int)($data['user_id']??0);if($userId<=0)throw new RuntimeException('Select an employee portal account to link.');
                $st=$pdo->prepare('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN employees e ON e.user_id=u.id WHERE u.id=? AND u.status="ACTIVE" AND r.portal="employee" AND e.id IS NULL LIMIT 1');
                $st->execute([$userId]);if(!$st->fetchColumn())throw new RuntimeException('The selected employee account is unavailable or already linked.');
            } elseif($accountMode==='create') {
                $accountEmail=strtolower(trim((string)($data['account_email']??'')));$password=(string)($data['temporary_password']??'');
                if(!filter_var($accountEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid employee account email.');
                if(strlen($password)<10||!preg_match('/[A-Za-z]/',$password)||!preg_match('/[0-9]/',$password))throw new RuntimeException('Temporary password must be at least 10 characters and include a letter and a number.');
                $role=$pdo->query('SELECT id FROM roles WHERE code="EMPLOYEE" LIMIT 1')->fetchColumn();if(!$role)throw new RuntimeException('Employee portal role is not configured.');
                $st=$pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email)=LOWER(?)');$st->execute([$accountEmail]);if((int)$st->fetchColumn()>0)throw new RuntimeException('A user account already uses that email address.');
                $fullName=trim((string)($data['first_name']??$a['first_name']).' '.(string)($data['middle_name']??$a['middle_name']??'').' '.(string)($data['last_name']??$a['last_name']));
                $pdo->prepare('INSERT INTO users(role_id,client_id,full_name,email,password_hash,status) VALUES(?,NULL,?,?,?,"ACTIVE")')->execute([(int)$role,$fullName,$accountEmail,password_hash($password,PASSWORD_DEFAULT)]);
                $userId=(int)$pdo->lastInsertId();
                audit('Access Control','CREATE_EMPLOYEE_ACCOUNT','user',$userId,['email'=>$accountEmail,'source_application_id'=>$applicationId]);
                if(trim((string)($data['company_email']??''))==='')$data['company_email']=$accountEmail;
            } elseif($accountMode!=='later') throw new RuntimeException('Invalid employee account option.');

            $employeeData=[
                'employee_no'=>(string)($data['employee_no']??''),'user_id'=>$userId,'first_name'=>(string)($data['first_name']??$a['first_name']),
                'middle_name'=>(string)($data['middle_name']??$a['middle_name']??''),'last_name'=>(string)($data['last_name']??$a['last_name']),'suffix'=>(string)($data['suffix']??$a['suffix']??''),
                'company_email'=>(string)($data['company_email']??''),'personal_email'=>$personalEmail,'mobile_no'=>(string)($data['mobile_no']??$a['mobile_no']),
                'department_id'=>(int)($data['department_id']??0),'position_id'=>(int)($data['position_id']??0),'branch_id'=>(int)($data['branch_id']??0),
                'employment_type_id'=>(int)($data['employment_type_id']??0),'hire_date'=>(string)($data['hire_date']??''),'regularization_date'=>(string)($data['regularization_date']??''),
                'status'=>(string)($data['status']??'PROBATIONARY')
            ];
            $employeeId=EmployeeRepository::create($employeeData);

            if(!empty($data['transfer_documents'])){
                $srcDir=(string)cfg('uploads.resume_dir');$destDir=dirname(__DIR__).'/storage/employee_documents';
                if(!is_dir($destDir)&&!mkdir($destDir,0775,true)&&!is_dir($destDir))throw new RuntimeException('Could not create secure employee document storage.');
                foreach(self::applicationDocuments($applicationId) as $doc){
                    $src=$srcDir.'/'.$doc['stored_name'];if(!is_file($src))continue;
                    $ext=strtolower(pathinfo((string)$doc['original_name'],PATHINFO_EXTENSION));if($ext==='')$ext='bin';
                    $stored='emp_'.$employeeId.'_'.bin2hex(random_bytes(16)).'.'.$ext;$dest=$destDir.'/'.$stored;
                    if(!copy($src,$dest))throw new RuntimeException('Could not transfer recruitment document: '.$doc['original_name']);
                    $copiedFiles[]=$dest;$docType=strtoupper((string)$doc['document_type'])==='RESUME'?'RESUME':'OTHER';
                    $title=pathinfo((string)$doc['original_name'],PATHINFO_FILENAME)?:'Recruitment document';
                    $ins=$pdo->prepare('INSERT INTO employee_documents(employee_id,document_type,title,original_name,stored_name,mime_type,file_size,uploaded_by) VALUES(?,?,?,?,?,?,?,?)');
                    $ins->execute([$employeeId,$docType,mb_substr($title,0,180),$doc['original_name'],$stored,$doc['mime_type'],(int)$doc['file_size'],(int)(Auth::user()['id']??0)?:null]);
                }
            }

            $uid=(int)(Auth::user()['id']??0)?:null;
            $pdo->prepare('UPDATE applications SET converted_employee_id=?,converted_at=NOW(),converted_by=?,status="CONVERTED",updated_at=NOW() WHERE id=? AND converted_employee_id IS NULL')->execute([$employeeId,$uid,$applicationId]);
            audit('Recruitment','CONVERT_TO_EMPLOYEE','application',$applicationId,['employee_id'=>$employeeId]);
            audit('Employees','CREATED_FROM_RECRUITMENT','employee',$employeeId,['application_id'=>$applicationId,'application_no'=>$a['application_no']]);
            $pdo->commit();
            return $employeeId;
        } catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();foreach($copiedFiles as $file)if(is_file($file))@unlink($file);throw $e;
        }
    }

    public static function users(): array { return db()->query('SELECT u.*,r.name role_name,c.name client_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN clients c ON c.id=u.client_id ORDER BY u.full_name')->fetchAll(); }
    public static function auditLogs(): array { return db()->query('SELECT a.*,u.full_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 100')->fetchAll(); }
}
