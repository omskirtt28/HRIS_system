<?php
declare(strict_types=1);

require_once __DIR__.'/OfferRate.php';
require_once __DIR__.'/JobOpenings.php';
require_once __DIR__.'/Mailer.php';
require_once __DIR__.'/InterviewInvite.php';
final class RecruitmentRepository
{
    public static function publishedJobs(?string $q=null, ?string $department=null, ?string $type=null): array {
        $sql='SELECT j.*, "PMBSI" client_name, d.name department_name FROM job_openings j LEFT JOIN departments d ON d.id=j.department_id WHERE '.JobOpenings::PUBLIC_WHERE;
        $p=[];
        if ($q) { $sql.=' AND (j.title LIKE ? OR j.location_text LIKE ?)'; $x='%'.$q.'%'; array_push($p,$x,$x); }
        if ($department) { $sql.=' AND d.name=?'; $p[]=$department; }
        if ($type) { $sql.=' AND j.employment_type=?'; $p[]=$type; }
        $sql.=' ORDER BY j.is_featured DESC,j.published_at DESC,j.id DESC';
        $st=db()->prepare($sql); $st->execute($p); return $st->fetchAll();
    }
    public static function jobBySlug(string $slug): ?array {
        $st=db()->prepare('SELECT j.*, "PMBSI" client_name, d.name department_name FROM job_openings j LEFT JOIN departments d ON d.id=j.department_id WHERE j.slug=? AND '.JobOpenings::PUBLIC_WHERE.' LIMIT 1');
        $st->execute([$slug]); return $st->fetch() ?: null;
    }
    public static function stages(): array { return db()->query('SELECT id,code,IF(code="CLIENT_REVIEW","Hiring Review",name) name,sequence_no,stage_type,client_visible,applicant_visible,active FROM recruitment_stages WHERE active=1 ORDER BY sequence_no')->fetchAll(); }
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
            $stage=(int)$pdo->query('SELECT id FROM recruitment_stages WHERE code="SCREENING" LIMIT 1')->fetchColumn();
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
    public static function jobById(int $id): ?array { $st=db()->prepare('SELECT j.* FROM job_openings j WHERE j.id=? AND '.JobOpenings::PUBLIC_WHERE); $st->execute([$id]); return $st->fetch()?:null; }
    public static function track(string $ref,string $email): ?array {
        $st=db()->prepare('SELECT a.id,a.application_no,a.applied_at,j.title,"PMBSI" client_name,s.code stage_code,IF(s.code="CLIENT_REVIEW","Hiring Review",s.name) stage_name FROM applications a JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.application_no=? AND LOWER(ap.email)=LOWER(?) LIMIT 1');
        $st->execute([$ref,$email]); $row=$st->fetch(); if (!$row) return null;
        $hs=db()->prepare('SELECT h.*,IF(fs.code="CLIENT_REVIEW","Hiring Review",fs.name) from_name,IF(ts.code="CLIENT_REVIEW","Hiring Review",ts.name) to_name FROM application_stage_history h LEFT JOIN recruitment_stages fs ON fs.id=h.from_stage_id JOIN recruitment_stages ts ON ts.id=h.to_stage_id WHERE h.application_id=? ORDER BY h.changed_at'); $hs->execute([$row['id']]); $row['history']=$hs->fetchAll(); return $row;
    }
    public static function applications(array $filters=[]): array {
        $sql='SELECT a.id,a.application_no,a.status application_status,a.screening_score,'.(self::screeningReady()?'a.screening_rating,':'').'a.applied_at,a.last_stage_changed_at,ap.first_name,ap.last_name,ap.email,ap.mobile_no,j.title job_title,j.employment_type job_employment_type,j.location_text job_location,c.name client_name,IF(s.code="APPLIED","SCREENING",s.code) stage_code,IF(s.code="APPLIED","Screening",IF(s.code="CLIENT_REVIEW","Hiring Review",s.name)) stage_name,u.full_name recruiter_name FROM applications a JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id JOIN clients c ON c.id=a.client_id JOIN recruitment_stages s ON s.id=a.current_stage_id LEFT JOIN users u ON u.id=a.assigned_recruiter_id WHERE 1=1'; $p=[];
        if (!empty($filters['client_id'])) { $sql.=' AND a.client_id=?'; $p[]=$filters['client_id']; }
        if (!empty($filters['client_portal'])) { $sql.=' AND EXISTS (SELECT 1 FROM endorsements e WHERE e.application_id=a.id AND e.client_id=a.client_id)'; }
        if (!empty($filters['stage'])) { $sql.=' AND IF(s.code="APPLIED","SCREENING",s.code)=?'; $p[]=$filters['stage']; }
        if (!empty($filters['q'])) { $x='%'.$filters['q'].'%'; $sql.=' AND (ap.first_name LIKE ? OR ap.last_name LIKE ? OR ap.email LIKE ? OR a.application_no LIKE ?)'; array_push($p,$x,$x,$x,$x); }
        $sql.=' ORDER BY a.last_stage_changed_at DESC,a.id DESC'; $st=db()->prepare($sql); $st->execute($p); return $st->fetchAll();
    }
    /** The applicant's latest uploaded resume (row only). HR opens it through the hr-application-resume route; the file is found by application id, never by a posted path. */
    public static function resume(int $applicationId): ?array {
        $st=db()->prepare('SELECT id,original_name,stored_name,mime_type,file_size FROM application_documents WHERE application_id=? AND document_type="RESUME" ORDER BY id DESC LIMIT 1');$st->execute([$applicationId]);
        return $st->fetch()?:null;
    }
    public static function application(int $id, ?int $clientId=null): ?array {
        $sql='SELECT a.*,ap.applicant_no,ap.first_name,ap.middle_name,ap.last_name,ap.suffix,ap.email,ap.mobile_no,j.title job_title,j.location_text,j.department_id job_department_id,j.employment_type job_employment_type,j.id job_id,j.job_code,j.status job_status,j.openings job_openings,j.salary_min,j.salary_max,j.closes_at,j.description job_description,j.requirements job_requirements,m.branch_id request_branch_id,m.department_id request_department_id,c.name client_name,IF(s.code="APPLIED","SCREENING",s.code) stage_code,IF(s.code="APPLIED","Screening",IF(s.code="CLIENT_REVIEW","Hiring Review",s.name)) stage_name,u.full_name recruiter_name FROM applications a JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id JOIN manpower_requests m ON m.id=a.manpower_request_id JOIN clients c ON c.id=a.client_id JOIN recruitment_stages s ON s.id=a.current_stage_id LEFT JOIN users u ON u.id=a.assigned_recruiter_id WHERE a.id=?'; $p=[$id];
        if ($clientId) { $sql.=' AND a.client_id=? AND EXISTS (SELECT 1 FROM endorsements e WHERE e.application_id=a.id AND e.client_id=?)'; $p[]=$clientId; $p[]=$clientId; }
        $st=db()->prepare($sql); $st->execute($p); $a=$st->fetch(); if(!$a)return null;
        $hs=db()->prepare('SELECT h.*,IF(fs.code="CLIENT_REVIEW","Hiring Review",fs.name) from_name,IF(ts.code="CLIENT_REVIEW","Hiring Review",ts.name) to_name,u.full_name changed_by_name FROM application_stage_history h LEFT JOIN recruitment_stages fs ON fs.id=h.from_stage_id JOIN recruitment_stages ts ON ts.id=h.to_stage_id LEFT JOIN users u ON u.id=h.changed_by WHERE h.application_id=? ORDER BY h.changed_at DESC'); $hs->execute([$id]); $a['history']=$hs->fetchAll();
        $iv=db()->prepare('SELECT i.*,u.full_name interviewer_name FROM interviews i LEFT JOIN users u ON u.id=i.interviewer_id WHERE i.application_id=? ORDER BY i.scheduled_at DESC'); $iv->execute([$id]); $a['interviews']=$iv->fetchAll();
        $of=db()->prepare('SELECT * FROM offers WHERE application_id=? ORDER BY id DESC LIMIT 1'); $of->execute([$id]); $a['offer']=$of->fetch()?:null;
        $dp=db()->prepare('SELECT d.*,b.name branch_name FROM deployments d LEFT JOIN branches b ON b.id=d.branch_id WHERE d.application_id=? ORDER BY d.id DESC LIMIT 1'); $dp->execute([$id]); $a['deployment']=$dp->fetch()?:null;
        $a['resume']=self::resume($id);
        return $a;
    }
    private static function assertApplicationMutable(int $applicationId): void {
        if(!self::phase2CReady()) return;
        $st=db()->prepare('SELECT converted_employee_id FROM applications WHERE id=? LIMIT 1');$st->execute([$applicationId]);$converted=(int)($st->fetchColumn()?:0);
        if($converted>0) throw new RuntimeException('This recruitment record has already been converted to an employee and is locked for further recruitment changes.');
    }

    /** The next normal stage for the Update button. Only before the offer: Offer, Deployment and Deployed move through their own actions (offer, acceptance, completing deployment). */
    public static function nextStageCode(int $id): ?string {
        $st=db()->prepare('SELECT s.code,s.sequence_no FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.id=?');$st->execute([$id]);$cur=$st->fetch();
        if(!$cur||!in_array($cur['code'],['APPLIED','SCREENING','INTERVIEW','ENDORSED','CLIENT_REVIEW'],true))return null;
        $n=db()->prepare('SELECT code FROM recruitment_stages WHERE active=1 AND sequence_no>? AND sequence_no<=80 ORDER BY sequence_no LIMIT 1');$n->execute([$cur['code']==='APPLIED'?20:$cur['sequence_no']]); // a legacy Applied row counts as Screening, so its next step is Interview
        return $n->fetchColumn()?:null;
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
        $interviewId=(int)db()->lastInsertId();
        self::moveToInterviewOrNote($id,'Interview scheduled');
        audit('Recruitment','SCHEDULE_INTERVIEW','application',$id);
        self::inviteApplicant($id,$interviewId);
    }
    /** An earlier stage advances to Interview; a candidate already at Interview or later (Hiring Review, Offer, Deployment) only gets the timeline line and is never pushed back. */
    private static function moveToInterviewOrNote(int $applicationId,string $line): void {
        $seq=(int)db()->query('SELECT s.sequence_no FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.id='.(int)$applicationId)->fetchColumn();
        $interviewSeq=(int)db()->query('SELECT sequence_no FROM recruitment_stages WHERE code="INTERVIEW"')->fetchColumn();
        if($seq<$interviewSeq) self::moveStage($applicationId,'INTERVIEW',$line); else self::timelineNote($applicationId,$line);
    }
    /** interview-invite: email the applicant and record the outcome on the timeline. A mail problem never undoes the interview. */
    private static function inviteApplicant(int $applicationId,int $interviewId): string {
        [$status,$line]=InterviewInvite::send($interviewId);
        self::timelineNote($applicationId,$line);
        if($status!=='skipped') audit('Recruitment',$status==='sent'?'INTERVIEW_INVITE_SENT':'INTERVIEW_INVITE_FAILED','application',$applicationId,['interview_id'=>$interviewId]);
        return $status;
    }
    /** HR resends the invite of one interview. shortcut: the 60-second limit is per browser session, not stored; add a column if abuse from several sessions ever matters. */
    public static function resendInterviewInvite(int $interviewId): void {
        if(!Auth::can('recruitment.manage')) throw new RuntimeException('You do not have permission to resend interview invites.');
        $st=db()->prepare('SELECT application_id FROM interviews WHERE id=?'); $st->execute([$interviewId]); $appId=(int)$st->fetchColumn();
        if(!$appId) throw new RuntimeException('Interview not found.');
        self::assertApplicationMutable($appId);
        $last=(int)($_SESSION['invite_resent'][$interviewId]??0);
        if(time()-$last<60) throw new RuntimeException('The invite was just sent. Wait a minute before resending.');
        $_SESSION['invite_resent'][$interviewId]=time();
        $status=self::inviteApplicant($appId,$interviewId);
        if($status!=='sent') throw new RuntimeException($status==='failed'?'The invite email could not be sent. See the timeline.':'The invite was not sent. See the timeline.');
    }
    public static function manpowerRequests(): array {
        return db()->query('SELECT m.*,c.name client_name,b.name branch_name,d.name department_name,u.full_name requested_by_name FROM manpower_requests m JOIN clients c ON c.id=m.client_id LEFT JOIN branches b ON b.id=m.branch_id LEFT JOIN departments d ON d.id=m.department_id LEFT JOIN users u ON u.id=m.requested_by ORDER BY m.created_at DESC')->fetchAll();
    }
    public static function stageCounts(?int $clientId=null): array {
        $sql='SELECT s.code,IF(s.code="CLIENT_REVIEW","Hiring Review",s.name) name,COUNT(a.id) total FROM recruitment_stages s LEFT JOIN applications a ON (a.current_stage_id=s.id OR (s.code="SCREENING" AND a.current_stage_id=(SELECT id FROM recruitment_stages WHERE code="APPLIED")))'; $p=[];
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
    // ---- rec-endorse-manager (SPEC-rec-endorse-manager.md): HR endorses to Manager / ADL / SC / TC reviewers, who answer in their own portal ----
    public const REVIEWER_ROLES=['MANAGER','ADL','SC','TC','OIC','MT'];
    public const ENDORSE_STAGES=['INTERVIEW','ENDORSED','CLIENT_REVIEW','RETURNED'];
    public const REVIEW_DECISIONS=['PROCEED'=>'Proceed','NOT_SUITABLE'=>'Not suitable','ANOTHER_INTERVIEW'=>'Needs another interview'];
    /** Active Manager / ADL / SC / TC / OIC / MT users HR may endorse to (a removed or inactive account is never offered). */
    public static function reviewerChoices(): array {
        $st=db()->prepare('SELECT u.id,u.full_name,r.code role_code,r.name role_name,b.id branch_id,b.area_id FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN employees e ON e.user_id=u.id LEFT JOIN branches b ON b.id=e.branch_id WHERE u.status="ACTIVE" AND u.removed_at IS NULL AND r.code IN('.implode(',',array_fill(0,count(self::REVIEWER_ROLES),'?')).') ORDER BY u.full_name,u.id');
        $st->execute(self::REVIEWER_ROLES); $out=[]; foreach($st->fetchAll() as $r) $out[(int)$r['id']]??=$r; return array_values($out); // one row per user
    }
    /** True once migration 031 is applied (until then a Retail job has no branch scope, so nobody but HR can act on it). */
    public static function branchScopeReady(): bool {
        static $ok=null; if($ok!==null) return $ok;
        try { $ok=(int)db()->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name="job_opening_branches"')->fetchColumn()===1; } catch(Throwable $e) { $ok=false; }
        return $ok;
    }
    /** branch_id => area_id for the branches HR set on the candidate's job opening (Retail). */
    public static function scopeBranches(int $applicationId): array {
        if(!self::branchScopeReady()) return [];
        $st=db()->prepare('SELECT jb.branch_id,b.area_id FROM applications a JOIN job_opening_branches jb ON jb.job_opening_id=a.job_opening_id JOIN branches b ON b.id=jb.branch_id WHERE a.id=?');
        $st->execute([$applicationId]); $o=[]; foreach($st->fetchAll() as $r) $o[(int)$r['branch_id']]=(int)$r['area_id']; return $o;
    }
    /** The Manpower Request requester, i.e. the department's direct manager; 0 for a walk-in job. */
    public static function directManagerId(int $applicationId): int {
        $st=db()->prepare('SELECT m.requested_by FROM applications a JOIN job_openings j ON j.id=a.job_opening_id JOIN manpower_requests m ON m.id=j.manpower_request_id WHERE a.id=?');
        $st->execute([$applicationId]); return (int)$st->fetchColumn(); // an HR-posted job's bridge request is requested by HR, who is never a Manager, so it has no direct manager
    }
    /** Head Office pass-on chain: HR->TC/Manager, TC->Manager, Manager->Manager/TC (only the direct manager, see targetsFor). */
    public const HO_ROLES=['HR'=>['TC','MANAGER'],'TC'=>['MANAGER'],'MANAGER'=>['MANAGER','TC']];
    /** People the actor may endorse or pass this candidate to. $actorRole null = HR. Head Office: TC/Manager only. Retail: ADL of the job's areas, then OIC/MT/SC of its branches. Unknown or Other site: HR may pick anyone, nobody else may pass on. Callers still drop self and people who already have the candidate. */
    public static function targetsFor(int $applicationId,?string $actorRole,int $actorId=0): array {
        $all=self::reviewerChoices(); $site=self::siteTypeOf($applicationId);
        if($site==='HEAD_OFFICE'){
            $roles=self::HO_ROLES[$actorRole??'HR']??[];
            if($actorRole==='MANAGER'&&($actorId<=0||self::directManagerId($applicationId)!==$actorId)) $roles=[]; // a Manager passed in by TC is the end of the chain
            return array_values(array_filter($all,fn($c)=>in_array($c['role_code'],$roles,true)));
        }
        if($site==='RETAIL'){
            if($actorRole!==null&&!in_array($actorRole,['ADL','OIC','SC','MT'],true)) return [];
            $scope=self::scopeBranches($applicationId); if(!$scope) return [];
            $areas=array_values(array_unique($scope));
            $adlAreas=[]; foreach($all as $c) if($c['role_code']==='ADL'&&in_array((int)$c['area_id'],$areas,true)) $adlAreas[(int)$c['area_id']]=1;
            return array_values(array_filter($all,function($c) use($actorRole,$scope,$areas,$adlAreas){
                if($c['role_code']==='ADL') return $actorRole===null&&in_array((int)$c['area_id'],$areas,true);
                if(!in_array($c['role_code'],['OIC','SC','MT'],true)||!isset($scope[(int)$c['branch_id']])) return false;
                return $actorRole!==null||!isset($adlAreas[(int)$c['area_id']]); // HR goes to the area's ADL; to the branch's OIC/SC/MT only when the area has no ADL
            }));
        }
        return $actorRole===null?$all:[];
    }
    /** The ticket requester, only when the request came from a Manpower Request ticket and they hold a reviewer role (walk-in requests have none). */
    public static function defaultReviewers(int $applicationId): array {
        $st=db()->prepare('SELECT m.requested_by FROM applications a JOIN manpower_requests m ON m.id=a.manpower_request_id WHERE a.id=? AND m.ticket_id IS NOT NULL AND m.requested_by IS NOT NULL');
        $st->execute([$applicationId]); $uid=(int)$st->fetchColumn();
        return $uid>0&&in_array($uid,array_map('intval',array_column(self::reviewerChoices(),'id')),true)?[$uid]:[];
    }
    /** Who was endorsed and what they answered (HR view). */
    public static function reviewersFor(int $applicationId): array {
        $st=db()->prepare('SELECT r.reviewer_user_id,u.full_name,ro.name role_name,r.endorsed_at,r.decision,r.decision_comment,r.decided_at,r.is_final,r.passed_by,pb.full_name passed_by_name FROM application_reviewers r JOIN users u ON u.id=r.reviewer_user_id JOIN roles ro ON ro.id=u.role_id LEFT JOIN users pb ON pb.id=r.passed_by WHERE r.application_id=? ORDER BY r.endorsed_at,r.id');
        $st->execute([$applicationId]); return $st->fetchAll();
    }
    private static function hrRecipientIds(): array {
        return array_map('intval',db()->query('SELECT DISTINCT u.id FROM users u JOIN role_permissions rp ON rp.role_id=u.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.code="recruitment.manage" AND u.status="ACTIVE" AND u.removed_at IS NULL')->fetchAll(PDO::FETCH_COLUMN));
    }
    private static function notify(int $userId,string $kind,string $title,string $body,string $link): void {
        if(NotificationService::ready()) NotificationService::create(['recipient_user_id'=>$userId,'kind'=>$kind,'title'=>$title,'body'=>$body,'link'=>$link,'dedupe_key'=>strtolower($kind).':'.bin2hex(random_bytes(8))]);
    }
    /** A timeline line with no stage change (from = to = the current stage). */
    private static function timelineNote(int $applicationId,string $comment): void {
        db()->prepare('INSERT INTO application_stage_history(application_id,from_stage_id,to_stage_id,comment,changed_by,changed_at) SELECT id,current_stage_id,current_stage_id,?,?,NOW() FROM applications WHERE id=?')->execute([mb_substr($comment,0,2000),$_SESSION['user']['id']??null,$applicationId]);
    }
    private static function reviewerRow(int $applicationId,int $userId): ?array {
        $st=db()->prepare('SELECT * FROM application_reviewers WHERE application_id=? AND reviewer_user_id=?'); $st->execute([$applicationId,$userId]); return $st->fetch()?:null;
    }
    /** Resume access for a reviewer: only the candidates endorsed to them. */
    public static function isReviewerOf(int $applicationId,int $userId): bool { return self::reviewerRow($applicationId,$userId)!==null; }
    // ---- rec-screening-score (SPEC-rec-screening-score.md): Negative / Fair / Outstanding, advice only; HR decides ----
    /** True once migration 028 is applied (until then nothing is gated, so code can ship before the migration). */
    public static function screeningReady(): bool {
        static $ok=null; if($ok!==null) return $ok;
        try { $ok=(int)db()->query('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND ((table_name="applications" AND column_name="screening_rating") OR (table_name="job_openings" AND column_name="site_type"))')->fetchColumn()===2; } catch(Throwable $e) { $ok=false; }
        return $ok;
    }
    public static function ratingOf(int $applicationId): ?string { if(!self::screeningReady()) return null; $st=db()->prepare("SELECT screening_rating FROM applications WHERE id=?"); $st->execute([$applicationId]); $v=(string)$st->fetchColumn(); return $v===""?null:$v; }
    private static function hasRating(int $applicationId): bool { return self::ratingOf($applicationId)!==null; }
    public const RATINGS=['NEGATIVE'=>'Negative','FAIR'=>'Fair','OUTSTANDING'=>'Outstanding'];
    /** Roles (besides HR) that may rate, by site type; they must also be among the people the applicant was endorsed to. OTHER = HR only. */
    public const SCORER_ROLES=['RETAIL'=>['ADL','OIC','SC','MT'],'HEAD_OFFICE'=>['MANAGER','TC'],'OTHER'=>[]];
    /** RETAIL / HEAD_OFFICE / OTHER from the job, else from the manpower request branch; null when unknown (treated as HR only). */
    public static function siteTypeOf(int $applicationId): ?string {
        $st=db()->prepare('SELECT j.site_type,b.site_type branch_type FROM applications a JOIN job_openings j ON j.id=a.job_opening_id LEFT JOIN manpower_requests m ON m.id=j.manpower_request_id LEFT JOIN branches b ON b.id=m.branch_id WHERE a.id=?');
        $st->execute([$applicationId]); $r=$st->fetch(); if(!$r) return null;
        if(!empty($r['site_type'])) return (string)$r['site_type'];
        return ['RETAIL_STORE'=>'RETAIL','HEAD_OFFICE'=>'HEAD_OFFICE'][(string)$r['branch_type']]??null;
    }
    /** HR always; a reviewer only when the applicant was endorsed to them and their role fits the site type. */
    public static function canScore(int $applicationId): bool {
        if(Auth::can('recruitment.manage')) return true;
        $me=(int)(Auth::user()['id']??0); if($me<=0||!Auth::can('recruitment.review')||!self::isReviewerOf($applicationId,$me)) return false;
        $site=self::siteTypeOf($applicationId);
        return $site!==null&&in_array((string)(Auth::user()['role_code']??''),self::SCORER_ROLES[$site]??[],true);
    }
    public static function saveScreening(int $applicationId,string $rating,string $summary): void {
        if(!self::canScore($applicationId)) throw new RuntimeException('You do not have permission to rate this candidate.');
        if(!isset(self::RATINGS[$rating])) throw new RuntimeException('Choose a rating: Negative, Fair or Outstanding.');
        self::assertApplicationMutable($applicationId);
        $me=(int)Auth::user()['id'];
        $pdo=db(); $own=!$pdo->inTransaction(); if($own) $pdo->beginTransaction(); // a caller (a test) may already hold one
        try {
            $pdo->prepare('INSERT INTO screening_reviews(application_id,reviewer_id,recommendation,rating,summary,completed_at) VALUES(?,?,?,?,?,NOW())')->execute([$applicationId,$me,$rating,$rating,mb_substr(trim($summary),0,2000)?:null]);
            // The recruiter slot is the first HR user who rates; a reviewer's rating never takes it.
            $pdo->prepare('UPDATE applications SET screening_rating=?,assigned_recruiter_id=IF(assigned_recruiter_id IS NULL AND ?=1,?,assigned_recruiter_id),updated_at=NOW() WHERE id=?')->execute([$rating,Auth::can('recruitment.manage')?1:0,$me,$applicationId]);
            self::timelineNote($applicationId,'Screening rating: '.self::RATINGS[$rating].'.');
            audit('Recruitment','SCREENING_RATED','application',$applicationId,['rating'=>$rating]);
            if($own) $pdo->commit();
        } catch(Throwable $e) { if($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    public const CRITERIA=['communication'=>'Communication','job_knowledge'=>'Job knowledge','attitude'=>'Attitude','culture_fit'=>'Culture fit','overall'=>'Overall impression'];
    public const RECOMMENDATIONS=['PROCEED'=>'Proceed','HOLD'=>'Hold','NO_PROCEED'=>'Do not proceed'];
    public static function scorecardReady(): bool {
        static $ok=null; if($ok!==null) return $ok;
        try { $ok=(int)db()->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name="interview_scorecards"')->fetchColumn()===1; } catch(Throwable $e) { $ok=false; }
        return $ok;
    }
    /** True from the Interview stage onward (Hiring Review, Offer, ...). Same scorer rule as the screening rating (canScore). */
    public static function atInterviewOrLater(int $applicationId): bool {
        $st=db()->prepare('SELECT s.sequence_no>=(SELECT sequence_no FROM recruitment_stages WHERE code="INTERVIEW") FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.id=?');
        $st->execute([$applicationId]); return (bool)$st->fetchColumn();
    }
    public static function saveScorecard(int $applicationId,array $scores,string $recommendation,string $note): void {
        if(!self::scorecardReady()) throw new RuntimeException('The interview scorecard is not available yet.');
        if(!self::canScore($applicationId)) throw new RuntimeException('You do not have permission to score this candidate.');
        $vals=[]; foreach(self::CRITERIA as $k=>$label){ $v=$scores[$k]??null; if(!is_string($v)&&!is_int($v)||!preg_match('/^[1-5]$/',(string)$v)) throw new RuntimeException('Score '.$label.' from 1 to 5.'); $vals[]=(int)$v; }
        if(!isset(self::RECOMMENDATIONS[$recommendation])) throw new RuntimeException('Choose Proceed, Hold or Do not proceed.');
        self::assertApplicationMutable($applicationId);
        if(!self::atInterviewOrLater($applicationId)) throw new RuntimeException('A scorecard can be saved once the candidate reaches the Interview stage.');
        $me=(int)Auth::user()['id'];
        $pdo=db(); $own=!$pdo->inTransaction(); if($own) $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO interview_scorecards(application_id,scorer_id,communication,job_knowledge,attitude,culture_fit,overall,recommendation,note) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE communication=VALUES(communication),job_knowledge=VALUES(job_knowledge),attitude=VALUES(attitude),culture_fit=VALUES(culture_fit),overall=VALUES(overall),recommendation=VALUES(recommendation),note=VALUES(note)')
                ->execute(array_merge([$applicationId,$me],$vals,[$recommendation,mb_substr(trim($note),0,2000)?:null]));
            self::timelineNote($applicationId,'Interview scorecard: '.self::RECOMMENDATIONS[$recommendation].', '.number_format(array_sum($vals)/count($vals),1).' / 5.');
            audit('Recruitment','INTERVIEW_SCORED','application',$applicationId,['recommendation'=>$recommendation]);
            if($own) $pdo->commit();
        } catch(Throwable $e) { if($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    /** HR sees every scorecard; a reviewer sees only their own (BR-861). */
    public static function scorecards(int $applicationId): array {
        if(!self::scorecardReady()) return [];
        $sql='SELECT c.*,u.full_name scorer_name FROM interview_scorecards c JOIN users u ON u.id=c.scorer_id WHERE c.application_id=?'; $p=[$applicationId];
        if(!Auth::can('recruitment.manage')){ $sql.=' AND c.scorer_id=?'; $p[]=(int)(Auth::user()['id']??0); }
        $st=db()->prepare($sql.' ORDER BY c.updated_at DESC'); $st->execute($p); return $st->fetchAll();
    }
    public static function scorecardAverage(array $cards): ?float {
        if(!$cards) return null; $t=0; foreach($cards as $c) foreach(array_keys(self::CRITERIA) as $k) $t+=(int)$c[$k]; return $t/(count($cards)*count(self::CRITERIA));
    }
    public static function endorseToReviewers(int $applicationId,string $note,array $reviewerIds,array $finalIds=[]): void {
        if(!Auth::can('recruitment.manage')) throw new RuntimeException('You do not have permission to endorse candidates.');
        self::assertApplicationMutable($applicationId);
        $app=self::application($applicationId); if(!$app) throw new RuntimeException('Application not found.');
        if(!in_array($app['stage_code'],self::ENDORSE_STAGES,true)) throw new RuntimeException('This candidate cannot be endorsed at the current stage.'); // same stages as the HR card
        $ids=array_values(array_unique(array_map('intval',$reviewerIds)));
        if(!$ids) throw new RuntimeException('Choose at least one person to endorse to.');
        if(count($ids)>10) throw new RuntimeException('Choose at most 10 people.');
        $valid=array_column(self::targetsFor($applicationId,null),'full_name','id');
        foreach($ids as $id) if(!isset($valid[$id])) throw new RuntimeException('One of the selected people cannot be endorsed this candidate.');
        $final=array_values(array_unique(array_map('intval',$finalIds))); if(array_diff($final,$ids)) throw new RuntimeException('The final interviewer must be one of the people you chose.');
        $note=mb_substr(trim($note),0,2000); $by=(int)$_SESSION['user']['id'];
        $pdo=db(); $pdo->beginTransaction(); try {
            if($app['stage_code']!=='CLIENT_REVIEW') $pdo->prepare('UPDATE application_reviewers SET is_final=0 WHERE application_id=?')->execute([$applicationId]); // a new round starts clean: a final interviewer from an earlier round must not block Ready for HR
            $ins=$pdo->prepare('INSERT INTO application_reviewers(application_id,reviewer_user_id,endorsed_by,note,endorsed_at,is_final,passed_by) VALUES(?,?,?,?,NOW(),?,NULL) ON DUPLICATE KEY UPDATE endorsed_by=VALUES(endorsed_by),note=VALUES(note),endorsed_at=NOW(),decision=NULL,decision_comment=NULL,decided_at=NULL,is_final=VALUES(is_final),passed_by=NULL');
            foreach($ids as $id) $ins->execute([$applicationId,$id,$by,$note!==''?$note:null,in_array($id,$final,true)?1:0]);
            $pdo->commit();
        } catch(Throwable $e){ $pdo->rollBack(); throw $e; }
        $names=array_map(fn($id)=>$valid[$id],$ids);
        self::moveStage($applicationId,'CLIENT_REVIEW','Endorsed to '.implode(', ',$names).($note!==''?': '.$note:''));
        foreach($ids as $id) self::notify($id,'CANDIDATE_REVIEW','Candidate for your review',$app['first_name'].' '.$app['last_name'].' for '.$app['job_title'].' was endorsed to you.',url('review-candidate',['id'=>$applicationId]));
        audit('Recruitment','ENDORSE_REVIEWERS','application',$applicationId,['reviewers'=>$ids]);
    }
    // ---- interview-chain (SPEC-interview-chain.md): a reviewer passes the candidate to the next interviewer; the final answer hands the candidate back to HR ----
    /** A reviewer adds the next interviewer(s). The stage does not change. Only while the candidate is in Hiring Review. */
    public static function passOn(int $applicationId,int $fromUserId,array $toIds,array $finalIds,string $note): void {
        if(!Auth::can('recruitment.review')) throw new RuntimeException('You do not have permission to review candidates.');
        if((int)($_SESSION['user']['id']??0)!==$fromUserId) throw new RuntimeException('You can only pass a candidate on for yourself.');
        self::assertApplicationMutable($applicationId);
        if(!self::reviewerRow($applicationId,$fromUserId)) throw new RuntimeException('This candidate was not endorsed to you.');
        $app=self::application($applicationId); if(!$app) throw new RuntimeException('Application not found.');
        if($app['stage_code']!=='CLIENT_REVIEW') throw new RuntimeException('This candidate is no longer in Hiring Review.');
        $ids=array_values(array_unique(array_map('intval',$toIds)));
        if(!$ids) throw new RuntimeException('Choose at least one person to pass the candidate to.');
        if(count($ids)>10) throw new RuntimeException('Choose at most 10 people.');
        if(in_array($fromUserId,$ids,true)) throw new RuntimeException('You cannot pass a candidate to yourself.');
        $mine=self::reviewerRow($applicationId,$fromUserId); if($mine&&$mine['decision']==='PROCEED') throw new RuntimeException('You already answered Proceed, so you cannot pass this candidate on.');
        $valid=array_column(self::targetsFor($applicationId,(string)(Auth::user()['role_code']??''),$fromUserId),'full_name','id');
        foreach($ids as $id){
            if(!isset($valid[$id])) throw new RuntimeException('One of the selected people cannot be passed this candidate.');
            if(self::reviewerRow($applicationId,$id)) throw new RuntimeException($valid[$id].' already has this candidate.');
        }
        $final=array_values(array_unique(array_map('intval',$finalIds)));
        if(array_diff($final,$ids)) throw new RuntimeException('The final interviewer must be one of the people you chose.');
        $note=mb_substr(trim($note),0,2000);
        $pdo=db(); $pdo->beginTransaction(); try {
            $ins=$pdo->prepare('INSERT INTO application_reviewers(application_id,reviewer_user_id,endorsed_by,note,endorsed_at,is_final,passed_by) VALUES(?,?,?,?,NOW(),?,?)');
            foreach($ids as $id) $ins->execute([$applicationId,$id,$fromUserId,$note!==''?$note:null,in_array($id,$final,true)?1:0,$fromUserId]);
            $pdo->commit();
        } catch(Throwable $e){ $pdo->rollBack(); throw $e; }
        $who=(string)($_SESSION['user']['name']??'Reviewer');
        $names=array_map(fn($id)=>$valid[$id].(in_array($id,$final,true)?' (final)':''),$ids);
        self::timelineNote($applicationId,$who.' passed the candidate to '.implode(', ',$names).($note!==''?': '.$note:''));
        foreach($ids as $id) self::notify($id,'CANDIDATE_REVIEW','Candidate for your review',$app['first_name'].' '.$app['last_name'].' for '.$app['job_title'].' was passed to you by '.$who.'.',url('review-candidate',['id'=>$applicationId]));
        audit('Recruitment','PASS_ON','application',$applicationId,['to'=>$ids,'final'=>$final]);
    }
    /** HR, or the person who passed the candidate on, can change who is final while that reviewer has not answered. */
    public static function markFinal(int $applicationId,int $reviewerUserId,bool $final): void {
        $me=(int)($_SESSION['user']['id']??0);
        $row=self::reviewerRow($applicationId,$reviewerUserId);
        $isHr=Auth::can('recruitment.manage');
        if(!$isHr&&!(Auth::can('recruitment.review')&&$row&&(int)$row['passed_by']===$me&&$me>0)) throw new RuntimeException('You cannot change the final interviewer.');
        self::assertApplicationMutable($applicationId);
        if(!$row) throw new RuntimeException('That person was not endorsed this candidate.');
        if($row['decision']!==null) throw new RuntimeException('That person already answered, so this can no longer be changed.');
        db()->prepare('UPDATE application_reviewers SET is_final=? WHERE application_id=? AND reviewer_user_id=?')->execute([$final?1:0,$applicationId,$reviewerUserId]);
        audit('Recruitment','MARK_FINAL','application',$applicationId,['reviewer'=>$reviewerUserId,'final'=>$final]);
    }
    /** The reviewer whose Proceed hands the candidate back to HR (the final interviewer, or any reviewer when no final one was named). Null while nobody has finished. Only answers given in the current Hiring Review round count. */
    public static function finalDone(int $applicationId): ?array {
        $st=db()->prepare('SELECT u.full_name,r.decision_comment,r.decided_at FROM application_reviewers r JOIN users u ON u.id=r.reviewer_user_id JOIN applications a ON a.id=r.application_id JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE r.application_id=? AND s.code="CLIENT_REVIEW" AND r.decision="PROCEED"
            AND r.decided_at>=(SELECT COALESCE(MAX(h.changed_at),"1970-01-01") FROM application_stage_history h JOIN recruitment_stages ts ON ts.id=h.to_stage_id WHERE h.application_id=r.application_id AND ts.code="CLIENT_REVIEW" AND h.from_stage_id<>h.to_stage_id)
            AND (r.is_final=1 OR NOT EXISTS(SELECT 1 FROM application_reviewers f WHERE f.application_id=r.application_id AND f.is_final=1)) ORDER BY r.decided_at DESC LIMIT 1');
        $st->execute([$applicationId]); return $st->fetch()?:null;
    }
    public static function reviewQueue(int $userId): array {
        $st=db()->prepare('SELECT a.id,a.application_no,ap.first_name,ap.last_name,j.title job_title,j.location_text,s.code stage_code,IF(s.code="CLIENT_REVIEW","Hiring Review",s.name) stage_name,r.endorsed_at,r.decision FROM application_reviewers r JOIN applications a ON a.id=r.application_id JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE r.reviewer_user_id=? ORDER BY (r.decision IS NULL) DESC,r.endorsed_at DESC');
        $st->execute([$userId]); return $st->fetchAll();
    }
    /** What a reviewer may see of a candidate endorsed to them: no email, phone or other applicants. Null when not endorsed to this user. */
    public static function reviewerApplication(int $applicationId,int $userId): ?array {
        $st=db()->prepare('SELECT a.id,a.application_no,a.applied_at,a.why_fit,ap.first_name,ap.last_name,j.title job_title,j.location_text,s.code stage_code,IF(s.code="CLIENT_REVIEW","Hiring Review",s.name) stage_name,r.note,r.endorsed_at,r.decision,r.decision_comment,r.decided_at,r.is_final,eb.full_name endorsed_by_name FROM application_reviewers r JOIN applications a ON a.id=r.application_id JOIN applicants ap ON ap.id=a.applicant_id JOIN job_openings j ON j.id=a.job_opening_id JOIN recruitment_stages s ON s.id=a.current_stage_id LEFT JOIN users eb ON eb.id=r.endorsed_by WHERE r.application_id=? AND r.reviewer_user_id=?');
        $st->execute([$applicationId,$userId]); $a=$st->fetch(); if(!$a) return null;
        $iv=db()->prepare('SELECT i.interview_type,i.scheduled_at,i.location_or_link,i.status,u.full_name interviewer_name FROM interviews i LEFT JOIN users u ON u.id=i.interviewer_id WHERE i.application_id=? ORDER BY i.scheduled_at DESC'); $iv->execute([$applicationId]); $a['interviews']=$iv->fetchAll();
        $a['resume']=self::resume($applicationId);
        return $a;
    }
    /** True when nobody in the current round is still pending or in favour. A round starts when the candidate last entered Hiring Review; an answer given before that belongs to an earlier round and is ignored (a pending row is always current).
     *  shortcut: a recipient from an earlier round who never answered still counts as pending; HR can always move the stage by hand. */
    private static function everyCurrentReviewerDeclined(int $applicationId): bool {
        $st=db()->prepare('SELECT COUNT(*) FROM application_reviewers r WHERE r.application_id=? AND (r.decision IS NULL OR (r.decision<>"NOT_SUITABLE" AND r.decided_at>=(SELECT COALESCE(MAX(h.changed_at),"1970-01-01") FROM application_stage_history h JOIN recruitment_stages ts ON ts.id=h.to_stage_id WHERE h.application_id=r.application_id AND ts.code="CLIENT_REVIEW" AND h.from_stage_id<>h.to_stage_id)))');
        $st->execute([$applicationId]); return (int)$st->fetchColumn()===0;
    }
    public static function decide(int $applicationId,int $userId,string $decision,string $comment): void {
        if(!Auth::can('recruitment.review')) throw new RuntimeException('You do not have permission to review candidates.');
        if((int)($_SESSION['user']['id']??0)!==$userId) throw new RuntimeException('You can only answer for yourself.');
        if(!isset(self::REVIEW_DECISIONS[$decision])) throw new RuntimeException('Choose a decision.');
        $comment=mb_substr(trim($comment),0,2000);
        if($decision==='NOT_SUITABLE'&&$comment==='') throw new RuntimeException('Add a short reason when the candidate is not suitable.');
        self::assertApplicationMutable($applicationId);
        $row=self::reviewerRow($applicationId,$userId); if(!$row) throw new RuntimeException('This candidate was not endorsed to you.');
        db()->prepare('UPDATE application_reviewers SET decision=?,decision_comment=?,decided_at=NOW() WHERE application_id=? AND reviewer_user_id=?')->execute([$decision,$comment!==''?$comment:null,$applicationId,$userId]);
        $who=(string)($_SESSION['user']['name']??'Reviewer'); $line=$who.': '.self::REVIEW_DECISIONS[$decision].($comment!==''?' - '.$comment:'');
        $stage=(string)db()->query('SELECT s.code FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.id='.(int)$applicationId)->fetchColumn();
        $hasFinal=(int)db()->query('SELECT COUNT(*) FROM application_reviewers WHERE is_final=1 AND application_id='.(int)$applicationId)->fetchColumn()>0;
        $ready=$stage==='CLIENT_REVIEW'&&$decision==='PROCEED'&&((int)$row['is_final']===1||!$hasFinal); // only HR moves a candidate to Offer; the final answer hands the candidate back to HR
        if($ready) self::timelineNote($applicationId,'Final interview done. '.$line);
        elseif($stage==='CLIENT_REVIEW'&&$decision==='NOT_SUITABLE'&&self::everyCurrentReviewerDeclined($applicationId))
            self::moveStage($applicationId,'RETURNED',$line.' (every recipient answered Not suitable)');
        else self::timelineNote($applicationId,$line);
        $app=self::application($applicationId);
        foreach(self::hrRecipientIds() as $hr) self::notify($hr,'CANDIDATE_DECISION',$ready?'Ready for HR: final interview done':'Reviewer answered: '.self::REVIEW_DECISIONS[$decision],$who.($ready?' finished the final interview for ':' answered for ').$app['first_name'].' '.$app['last_name'].' ('.$app['job_title'].').'.($ready?' Next: Offer.':''),url('hr-applicant',['id'=>$applicationId]));
        audit('Recruitment','REVIEW_DECISION','application',$applicationId,['decision'=>$decision]);
    }
    public static function reviewerSchedule(int $applicationId,int $userId,array $d): void {
        if(!Auth::can('recruitment.review')) throw new RuntimeException('You do not have permission to review candidates.');
        if((int)($_SESSION['user']['id']??0)!==$userId) throw new RuntimeException('You can only schedule for yourself.');
        $type=mb_substr(trim((string)($d['interview_type']??'')),0,80); $ts=strtotime((string)($d['scheduled_at']??''));
        if($type===''||$ts===false) throw new RuntimeException('Enter the interview type and a valid date and time.');
        self::assertApplicationMutable($applicationId);
        if(!self::reviewerRow($applicationId,$userId)) throw new RuntimeException('This candidate was not endorsed to you.');
        db()->prepare('INSERT INTO interviews(application_id,interview_type,scheduled_at,location_or_link,interviewer_id,status,notes,created_at,updated_at) VALUES(?,?,?,?,?,"SCHEDULED",?,NOW(),NOW())')
            ->execute([$applicationId,$type,date('Y-m-d H:i:s',$ts),mb_substr(trim((string)($d['location_or_link']??'')),0,255),$userId,trim((string)($d['notes']??''))?:null]);
        $interviewId=(int)db()->lastInsertId();
        $line=(string)($_SESSION['user']['name']??'Reviewer').' scheduled '.$type.' on '.date('M j, Y g:i A',$ts);
        self::moveToInterviewOrNote($applicationId,$line);
        audit('Recruitment','REVIEWER_SCHEDULE_INTERVIEW','application',$applicationId);
        self::inviteApplicant($applicationId,$interviewId);
    }
    public static function saveOffer(int $applicationId,array $d): void {
        if(!isset(JobOpenings::TYPES[(string)($d['employment_type']??'')])) throw new RuntimeException('Choose an employment type from the list.');
        // Rate type + typed rate; a caller that only sends offered_salary is treated as a monthly rate.
        $type=(string)($d['rate_type']??'MONTHLY'); $typed=(float)($d['rate_amount']??$d['offered_salary']??0);
        $days=(int)($d['working_days']??OfferRate::DEFAULT_DAYS);
        $conv=OfferRate::convert($type,$typed,$days); $d['offered_salary']=$conv['monthly']; $d['rate_type']=$type; $d['rate_amount']=$typed; $d['working_days']=$days;
        self::assertApplicationMutable($applicationId);
        $app=self::application($applicationId); if(!$app) throw new RuntimeException('Application not found.');
        $st=db()->prepare('SELECT id FROM offers WHERE application_id=? ORDER BY id DESC LIMIT 1'); $st->execute([$applicationId]); $id=$st->fetchColumn();
        if($id){
            db()->prepare('UPDATE offers SET offered_salary=?,rate_type=?,rate_amount=?,working_days=?,employment_type=?,start_date=?,status="SENT",sent_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$d['offered_salary'],$d['rate_type'],$d['rate_amount'],$d['working_days'],$d['employment_type'],$d['start_date']?:null,$id]);
        } else {
            $no='OFR-'.date('Ymd').'-'.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);
            db()->prepare('INSERT INTO offers(application_id,offer_no,offered_salary,rate_type,rate_amount,working_days,employment_type,start_date,status,sent_at,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,"SENT",NOW(),?,NOW(),NOW())')->execute([$applicationId,$no,$d['offered_salary'],$d['rate_type'],$d['rate_amount'],$d['working_days'],$d['employment_type'],$d['start_date']?:null,$_SESSION['user']['id']]);
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
        $map=['CONTRACT'=>'CONTRACTUAL','FULL_TIME'=>'PROBATIONARY','PART_TIME'=>'PART_TIME','PROJECT_BASED'=>'PROJECT_BASED','FIXED_TERM'=>'FIXED_TERM','SEASONAL'=>'SEASONAL','INTERN'=>'PROBATIONARY','INTERNSHIP'=>'PROBATIONARY'];
        $wanted=$map[$sourceType]??$sourceType;
        foreach($types as $t){if(!empty($t['active'])&&strtoupper((string)$t['code'])===$wanted){$employmentTypeId=(int)$t['id'];break;}}
        if(!$employmentTypeId){foreach($types as $t){if(!empty($t['active'])&&strtoupper((string)$t['code'])==='PROBATIONARY'){$employmentTypeId=(int)$t['id'];break;}}}
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
        if(self::screeningReady()&&!self::hasRating($applicationId)) throw new RuntimeException('Add a screening rating (Negative, Fair or Outstanding) before converting this candidate.');
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

            // The portal account is always created with the employee (SPEC-convert-auto-account.md): the server builds the email from the Company
            // and ignores any posted user_id, company_email or account fields, exactly like Create Employee.
            require_once __DIR__.'/EmployeeAccountService.php';
            $employeeData=[
                'employee_no'=>(string)($data['employee_no']??''),'user_id'=>0,'first_name'=>(string)($data['first_name']??$a['first_name']),
                'middle_name'=>(string)($data['middle_name']??$a['middle_name']??''),'last_name'=>(string)($data['last_name']??$a['last_name']),'suffix'=>(string)($data['suffix']??$a['suffix']??''),
                'company_email'=>'','personal_email'=>$personalEmail,'mobile_no'=>(string)($data['mobile_no']??$a['mobile_no']),
                'work_company'=>(string)($data['work_company']??''),'require_work_company'=>true,
                'department_id'=>(int)($data['department_id']??0),'position_id'=>(int)($data['position_id']??0),'branch_id'=>(int)($data['branch_id']??0),
                'employment_type_id'=>(int)($data['employment_type_id']??0),'hire_date'=>(string)($data['hire_date']??''),'regularization_date'=>(string)($data['regularization_date']??''),
                'status'=>(string)($data['status']??'PROBATIONARY')
            ];
            $employeeId=EmployeeRepository::create($employeeData);
            EmployeeAccountService::issue($employeeId,$employeeData['first_name'],$employeeData['last_name'],$employeeData['work_company'],'EMPLOYEE');

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

    public static function users(): array { return db()->query('SELECT u.*,r.name role_name,c.name client_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN clients c ON c.id=u.client_id WHERE u.removed_at IS NULL ORDER BY u.full_name')->fetchAll(); }
    public static function auditLogs(): array { return db()->query('SELECT a.*,u.full_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 100')->fetchAll(); }
}
