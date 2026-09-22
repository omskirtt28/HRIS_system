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
        $sql='SELECT a.id,a.application_no,a.screening_score,a.applied_at,a.last_stage_changed_at,ap.first_name,ap.last_name,ap.email,ap.mobile_no,j.title job_title,c.name client_name,s.code stage_code,s.name stage_name,u.full_name recruiter_name FROM applications a JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id JOIN clients c ON c.id=a.client_id JOIN recruitment_stages s ON s.id=a.current_stage_id LEFT JOIN users u ON u.id=a.assigned_recruiter_id WHERE 1=1'; $p=[];
        if (!empty($filters['client_id'])) { $sql.=' AND a.client_id=?'; $p[]=$filters['client_id']; }
        if (!empty($filters['client_portal'])) { $sql.=' AND EXISTS (SELECT 1 FROM endorsements e WHERE e.application_id=a.id AND e.client_id=a.client_id)'; }
        if (!empty($filters['stage'])) { $sql.=' AND s.code=?'; $p[]=$filters['stage']; }
        if (!empty($filters['q'])) { $x='%'.$filters['q'].'%'; $sql.=' AND (ap.first_name LIKE ? OR ap.last_name LIKE ? OR ap.email LIKE ? OR a.application_no LIKE ?)'; array_push($p,$x,$x,$x,$x); }
        $sql.=' ORDER BY a.last_stage_changed_at DESC,a.id DESC'; $st=db()->prepare($sql); $st->execute($p); return $st->fetchAll();
    }
    public static function application(int $id, ?int $clientId=null): ?array {
        $sql='SELECT a.*,ap.applicant_no,ap.first_name,ap.last_name,ap.email,ap.mobile_no,j.title job_title,j.location_text,c.name client_name,s.code stage_code,s.name stage_name,u.full_name recruiter_name FROM applications a JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id JOIN clients c ON c.id=a.client_id JOIN recruitment_stages s ON s.id=a.current_stage_id LEFT JOIN users u ON u.id=a.assigned_recruiter_id WHERE a.id=?'; $p=[$id];
        if ($clientId) { $sql.=' AND a.client_id=? AND EXISTS (SELECT 1 FROM endorsements e WHERE e.application_id=a.id AND e.client_id=?)'; $p[]=$clientId; $p[]=$clientId; }
        $st=db()->prepare($sql); $st->execute($p); $a=$st->fetch(); if(!$a)return null;
        $hs=db()->prepare('SELECT h.*,fs.name from_name,ts.name to_name,u.full_name changed_by_name FROM application_stage_history h LEFT JOIN recruitment_stages fs ON fs.id=h.from_stage_id JOIN recruitment_stages ts ON ts.id=h.to_stage_id LEFT JOIN users u ON u.id=h.changed_by WHERE h.application_id=? ORDER BY h.changed_at DESC'); $hs->execute([$id]); $a['history']=$hs->fetchAll();
        $iv=db()->prepare('SELECT i.*,u.full_name interviewer_name FROM interviews i LEFT JOIN users u ON u.id=i.interviewer_id WHERE i.application_id=? ORDER BY i.scheduled_at DESC'); $iv->execute([$id]); $a['interviews']=$iv->fetchAll();
        $of=db()->prepare('SELECT * FROM offers WHERE application_id=? ORDER BY id DESC LIMIT 1'); $of->execute([$id]); $a['offer']=$of->fetch()?:null;
        $dp=db()->prepare('SELECT d.*,b.name branch_name FROM deployments d LEFT JOIN branches b ON b.id=d.branch_id WHERE d.application_id=? ORDER BY d.id DESC LIMIT 1'); $dp->execute([$id]); $a['deployment']=$dp->fetch()?:null;
        return $a;
    }
    public static function moveStage(int $id,string $target,?string $comment=null): void {
        $pdo=db(); $pdo->beginTransaction(); try {
            $st=$pdo->prepare('SELECT current_stage_id FROM applications WHERE id=? FOR UPDATE'); $st->execute([$id]); $from=(int)$st->fetchColumn(); if(!$from) throw new RuntimeException('Application not found.');
            $ts=$pdo->prepare('SELECT id FROM recruitment_stages WHERE code=? AND active=1'); $ts->execute([$target]); $to=(int)$ts->fetchColumn(); if(!$to) throw new RuntimeException('Invalid target stage.');
            $pdo->prepare('UPDATE applications SET current_stage_id=?,last_stage_changed_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$to,$id]);
            $pdo->prepare('INSERT INTO application_stage_history(application_id,from_stage_id,to_stage_id,comment,changed_by,changed_at) VALUES(?,?,?,?,?,NOW())')->execute([$id,$from,$to,$comment,$_SESSION['user']['id']??null]);
            $pdo->commit(); audit('Recruitment','MOVE_STAGE','application',$id,['target'=>$target]);
        } catch(Throwable $e){$pdo->rollBack();throw $e;}
    }
    public static function scheduleInterview(int $id,array $d): void {
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
        $st=db()->prepare('UPDATE offers SET status="ACCEPTED",responded_at=NOW(),updated_at=NOW() WHERE application_id=?'); $st->execute([$applicationId]);
        if(!$st->rowCount()) throw new RuntimeException('Create an offer first.');
        self::moveStage($applicationId,'DEPLOYMENT','Offer accepted; preparing deployment'); audit('Recruitment','OFFER_ACCEPTED','application',$applicationId);
    }
    public static function saveDeployment(int $applicationId,array $d): void {
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
    public static function users(): array { return db()->query('SELECT u.*,r.name role_name,c.name client_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN clients c ON c.id=u.client_id ORDER BY u.full_name')->fetchAll(); }
    public static function auditLogs(): array { return db()->query('SELECT a.*,u.full_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 100')->fetchAll(); }
}
