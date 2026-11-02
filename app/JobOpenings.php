<?php
declare(strict_types=1);
require_once __DIR__.'/ManpowerIntake.php';

/**
 * rec-jobs (SPEC-rec-jobs.md): HR posts, edits, publishes, unpublishes and closes job openings.
 * Lifecycle: DRAFT -> PUBLISHED -> CLOSED (unpublish = back to DRAFT, reopen = CLOSED back to DRAFT). Jobs are never deleted.
 * A job always hangs off a manpower_requests row (job_openings.manpower_request_id is NOT NULL). From a ticket request the row
 * exists already; a walk-in job (D5, HR files no requests) gets a silent APPROVED bridge row with no ticket.
 * Every mutator checks recruitment.manage itself and throws; the callers add CSRF.
 */
final class JobOpenings
{
    /** The one rule for "visible to the public"; Careers, Home, the job page and Apply all use it. */
    public const PUBLIC_WHERE='j.status="PUBLISHED" AND (j.closes_at IS NULL OR j.closes_at>=NOW())';
    public const TYPES=['REGULAR'=>'Regular','PROBATIONARY'=>'Probationary','SEASONAL'=>'Seasonal','CONTRACT'=>'Contract','PART_TIME'=>'Part-time','PROJECT_BASED'=>'Project-based','FIXED_TERM'=>'Fixed-term','INTERNSHIP'=>'Internship'];
    private const POSTABLE=['APPROVED','PARTIALLY_FILLED'];

    private static function guard():void
    {
        if(!Auth::can('recruitment.manage'))throw new RuntimeException('You do not have permission to manage job openings.');
    }

    private static function uid():int{return (int)(Auth::user()['id']??0);}

    /** @return array<string,mixed> validated, DB-ready fields (employer and request link are handled by the caller). */
    private static function clean(array $in):array
    {
        $title=trim((string)preg_replace('/\s+/',' ',(string)($in['title']??'')));
        if($title===''||mb_strlen($title)>180)throw new RuntimeException('Enter a job title of up to 180 characters.');
        $description=trim((string)($in['description']??''));
        if($description==='')throw new RuntimeException('Enter the job description.');
        $type=(string)($in['employment_type']??'');
        if(!isset(self::TYPES[$type]))throw new RuntimeException('Choose an employment type.');
        $openings=(string)($in['openings']??'');
        if(!ctype_digit($openings)||(int)$openings<1||(int)$openings>999)throw new RuntimeException('Openings must be a whole number from 1 to 999.');
        $location=trim((string)($in['location_text']??''));
        if(mb_strlen($location)>180)throw new RuntimeException('Location must be 180 characters or fewer.');
        $salary=[];
        foreach(['salary_min','salary_max'] as $k){
            $v=trim((string)($in[$k]??''));
            if($v!==''&&(!is_numeric($v)||(float)$v<0||(float)$v>9999999999))throw new RuntimeException('Enter salary as a positive number, or leave it blank.');
            $salary[$k]=$v===''?null:round((float)$v,2);
        }
        if($salary['salary_min']!==null&&$salary['salary_max']!==null&&$salary['salary_min']>$salary['salary_max'])throw new RuntimeException('Salary minimum cannot be higher than the maximum.');
        $closes=null;$on=trim((string)($in['closes_on']??''));
        if($on!==''){
            $dt=DateTime::createFromFormat('!Y-m-d',$on);
            if(!$dt||$dt->format('Y-m-d')!==$on)throw new RuntimeException('Enter the closing date as a real date.');
            if($on<date('Y-m-d'))throw new RuntimeException('The closing date cannot be in the past.');
            $closes=$on.' 23:59:59'; // DATETIME column: stay live through the closing day.
        }
        $dept=(int)($in['department_id']??0)?:null;
        if($dept!==null){$q=db()->prepare('SELECT 1 FROM departments WHERE id=?');$q->execute([$dept]);if(!$q->fetchColumn())throw new RuntimeException('Choose a valid department.');}
        return ['title'=>$title,'description'=>$description,'requirements'=>trim((string)($in['requirements']??''))?:null,'location_text'=>$location?:null,'employment_type'=>$type,
            'openings'=>(int)$openings,'salary_min'=>$salary['salary_min'],'salary_max'=>$salary['salary_max'],'closes_at'=>$closes,'department_id'=>$dept,'is_featured'=>empty($in['is_featured'])?0:1];
    }

    private static function slug(string $title,int $id):string
    {
        $s=trim((string)preg_replace('/[^a-z0-9]+/','-',strtolower($title)),'-');
        return ($s===''?'job':substr($s,0,120)).'-'.$id;
    }

    /** The request must be approved and still hiring; a hidden form field is never trusted. */
    private static function postableRequest(int $id):array
    {
        $q=db()->prepare('SELECT * FROM manpower_requests WHERE id=?');$q->execute([$id]);$r=$q->fetch();
        if(!$r||!in_array($r['status'],self::POSTABLE,true))throw new RuntimeException('Only an approved manpower request that is still hiring can be posted.');
        return $r;
    }

    /** Heads still unplanned on a request: requested - filled - openings of its other jobs that are not closed. */
    private static function remaining(array $request,int $exceptJob=0):int
    {
        $q=db()->prepare('SELECT COALESCE(SUM(openings),0) FROM job_openings WHERE manpower_request_id=? AND status<>"CLOSED" AND id<>?');$q->execute([(int)$request['id'],$exceptJob]);
        return (int)$request['requested_headcount']-(int)$request['filled_headcount']-(int)$q->fetchColumn();
    }

    /** Values that prefill the Post job form for an approved request. */
    public static function defaultsFor(int $requestId):array
    {
        $r=self::postableRequest($requestId);
        // Never the request notes: for a ticket request they hold the ticket number, employee id and section, which must not go public.
        $desc='';if($r['ticket_id']){$q=db()->prepare('SELECT job_description_qualifications FROM ticket_manpower_requests WHERE ticket_id=?');$q->execute([(int)$r['ticket_id']]);$desc=trim((string)$q->fetchColumn());}
        $branch='';if($r['branch_id']){$q=db()->prepare('SELECT name FROM branches WHERE id=?');$q->execute([(int)$r['branch_id']]);$branch=(string)$q->fetchColumn();}
        return ['manpower_request_id'=>(int)$r['id'],'client_id'=>(int)$r['client_id'],'title'=>$r['position_title'],'department_id'=>$r['department_id'],'location_text'=>$branch,
            'employment_type'=>isset(self::TYPES[$r['employment_type']])?$r['employment_type']:'REGULAR','openings'=>max(1,self::remaining($r)),'description'=>$desc,'requirements'=>''];
    }

    /** How many heads the live (not closed) jobs of this job's request exceed what is still unfilled; a warning, never a block. */
    public static function overBy(int $jobId):int
    {
        $q=db()->prepare('SELECT m.* FROM job_openings j JOIN manpower_requests m ON m.id=j.manpower_request_id WHERE j.id=?');$q->execute([$jobId]);$r=$q->fetch();
        return $r?max(0,-self::remaining($r)):0;
    }

    /** Create (id 0) or edit. Create takes the employer from the request, or from client_id for a walk-in job; edit never moves either. */
    public static function save(array $in,int $id=0):int
    {
        self::guard();
        $d=self::clean($in);
        $pdo=db();$pdo->beginTransaction();
        try{
            if($id>0){
                $q=$pdo->prepare('SELECT status FROM job_openings WHERE id=? FOR UPDATE');$q->execute([$id]);$status=$q->fetchColumn();
                if($status===false)throw new RuntimeException('Job not found.');
                if($status==='CLOSED')throw new RuntimeException('A closed job cannot be edited. Reopen it first.');
                $pdo->prepare('UPDATE job_openings SET title=?,description=?,requirements=?,location_text=?,employment_type=?,openings=?,salary_min=?,salary_max=?,closes_at=?,is_featured=? WHERE id=?')
                    ->execute([...array_values(array_diff_key($d,['department_id'=>1])),$id]); // the form has no department field; an edit keeps the one from the request
                audit('Recruitment','EDIT_JOB','job_opening',$id);
            }else{
                $mrId=(int)($in['manpower_request_id']??0);$bridge=false;
                if($mrId>0){$client=(int)self::postableRequest($mrId)['client_id'];}
                else{
                    // The form no longer asks for an employer: with none posted, the employer is PMBSI.
                    $client=trim((string)($in['new_employer']??''))!==''?ManpowerIntake::employerByName((string)$in['new_employer']):((string)($in['client_id']??'')===''?ManpowerIntake::employerByName('PMBSI'):(int)$in['client_id']);
                    $q=$pdo->prepare('SELECT 1 FROM clients WHERE id=?');$q->execute([$client]);
                    if(!$client||!$q->fetchColumn())throw new RuntimeException('Choose an employer.');
                    $pdo->prepare('INSERT INTO manpower_requests(request_no,client_id,department_id,position_title,requested_headcount,employment_type,priority,status,requested_by,approved_by,approved_at,notes) VALUES(?,?,?,?,?,?,"NORMAL","APPROVED",?,?,NOW(),"HR posting (no ticket).")')
                        ->execute(['MR-TMP-'.bin2hex(random_bytes(6)),$client,$d['department_id'],$d['title'],$d['openings'],$d['employment_type'],self::uid(),self::uid()]);
                    $mrId=(int)$pdo->lastInsertId();$bridge=true;
                }
                $tmp=bin2hex(random_bytes(8));
                $pdo->prepare('INSERT INTO job_openings(manpower_request_id,client_id,department_id,job_code,slug,title,description,requirements,location_text,employment_type,salary_min,salary_max,openings,status,is_featured,closes_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,"DRAFT",?,?,?)')
                    ->execute([$mrId,$client,$d['department_id'],'TMP-'.$tmp,'tmp-'.$tmp,$d['title'],$d['description'],$d['requirements'],$d['location_text'],$d['employment_type'],$d['salary_min'],$d['salary_max'],$d['openings'],$d['is_featured'],$d['closes_at'],self::uid()]);
                // Code, slug and the bridge request number come from the row id, so two saves at once can never collide.
                $id=(int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE job_openings SET job_code=?,slug=? WHERE id=?')->execute([sprintf('JOB-%s-%05d',date('Y'),$id),self::slug($d['title'],$id),$id]);
                if($bridge)$pdo->prepare('UPDATE manpower_requests SET request_no=? WHERE id=?')->execute(['MR-'.date('Ymd').'-H'.$id,$mrId]);
                audit('Recruitment','CREATE_JOB','job_opening',$id,['manpower_request_id'=>$mrId]);
            }
            $pdo->commit();return $id;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function find(int $id):?array{$q=db()->prepare('SELECT * FROM job_openings WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}

    /** One guarded UPDATE per move: the WHERE clause is the state check, so a double click or stale page is refused, not repeated. */
    private static function move(int $id,array $from,string $to,string $action,string $extra=''):void
    {
        self::guard();
        $st=db()->prepare('UPDATE job_openings SET status=?'.$extra.' WHERE id=? AND status IN ('.implode(',',array_fill(0,count($from),'?')).')');
        $st->execute([$to,$id,...$from]);
        if($st->rowCount()!==1)throw new RuntimeException('That job is not in a state where this can be done.');
        audit('Recruitment',$action,'job_opening',$id);
    }

    public static function publish(int $id):void{self::move($id,['DRAFT'],'PUBLISHED','PUBLISH_JOB',',published_at=COALESCE(published_at,NOW())');}
    public static function unpublish(int $id):void{self::move($id,['PUBLISHED'],'DRAFT','UNPUBLISH_JOB');}
    public static function close(int $id):void{self::move($id,['DRAFT','PUBLISHED'],'CLOSED','CLOSE_JOB');}
    public static function reopen(int $id):void{self::move($id,['CLOSED'],'DRAFT','REOPEN_JOB');}

    /** Status HR sees: a Published job past its closing date is Expired (public already hides it). */
    public static function displayStatus(array $job):string
    {
        return $job['status']==='PUBLISHED'&&$job['closes_at']!==null&&strtotime((string)$job['closes_at'])<time()?'EXPIRED':(string)$job['status'];
    }

    /** @param string $filter all|draft|published|closed */
    public static function listJobs(string $filter='all',string $search=''):array
    {
        $sql='SELECT j.*,c.name employer,d.name department_name,(SELECT COUNT(*) FROM applications a WHERE a.job_opening_id=j.id) applications FROM job_openings j JOIN clients c ON c.id=j.client_id LEFT JOIN departments d ON d.id=j.department_id WHERE 1=1';
        $p=[];
        if(in_array($filter,['draft','published','closed'],true)){$sql.=' AND j.status=?';$p[]=strtoupper($filter);}
        if(trim($search)!==''){$sql.=' AND (j.title LIKE ? OR j.job_code LIKE ?)';$x='%'.trim($search).'%';array_push($p,$x,$x);}
        $q=db()->prepare($sql.' ORDER BY j.id DESC');$q->execute($p);
        return $q->fetchAll();
    }

    /** request id => number of jobs that are not closed, for the Manpower Requests list. */
    public static function openJobCounts():array
    {
        return array_map('intval',db()->query('SELECT manpower_request_id,COUNT(*) FROM job_openings WHERE status<>"CLOSED" GROUP BY manpower_request_id')->fetchAll(PDO::FETCH_KEY_PAIR));
    }
}
