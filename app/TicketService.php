<?php
declare(strict_types=1);
require_once __DIR__.'/EmployeeApproverAssignmentService.php';
require_once __DIR__.'/TicketPolicy.php';
require_once __DIR__.'/TicketRepository.php';
require_once __DIR__.'/TicketNotifications.php';
require_once __DIR__.'/TicketTypes.php';

final class TicketService
{
    private static array $movedFiles=[];
    private static function transaction(callable $work):mixed
    {
        self::$movedFiles=[];db()->beginTransaction();
        try{$result=$work();db()->commit();self::$movedFiles=[];}
        catch(Throwable $error){if(db()->inTransaction())db()->rollBack();foreach(self::$movedFiles as $path)if(is_file($path))unlink($path);self::$movedFiles=[];throw $error;}
        try{TicketNotifications::flush(50,is_int($result)?$result:null);}catch(Throwable){error_log('HR request notification delivery deferred.');}
        return $result;
    }
    private static function event(int $id,string $type,?string $from=null,?string $to=null,array $details=[]):int
    {
        $q=db()->prepare('INSERT INTO ticket_events(ticket_id,actor_user_id,event_type,from_status,to_status,details_json) VALUES(?,?,?,?,?,?)');
        $q->execute([$id,TicketRepository::uid(),$type,$from,$to,json_encode($details,JSON_THROW_ON_ERROR)]);return (int)db()->lastInsertId();
    }
    private static function transition(array &$t,string $status,string $remarks='',string $event='TRANSITION'):void
    {
        TicketPolicy::assertTransition($t['status'],$status,$remarks,$t['type_code']);$from=$t['status'];
        db()->prepare('UPDATE tickets SET status=?,version=version+1,closed_at=? WHERE id=?')->execute([$status,in_array($status,['COMPLETED','REJECTED','CANCELLED'],true)?date('Y-m-d H:i:s'):null,$t['id']]);
        $t['status']=$status;$t['version']++;$eventId=self::event((int)$t['id'],$event,$from,$status,['remarks'=>$remarks]);TicketNotifications::enqueue($eventId,$t);
    }
    private static function requireFiler(array $data=[]):array
    {
        TicketRepository::uid();if(!Auth::can('tickets.request_self')&&!Auth::can('ticket.file_for_others'))throw new RuntimeException('You do not have permission to file HR requests.');
        if(!empty($data['employee'])){
            $q=db()->prepare('SELECT code FROM ticket_types WHERE id=? AND active=1');$q->execute([(int)($data['ticket_type_id']??0)]);
            $typeCode=$q->fetchColumn();
            if($typeCode==='OIC_AUTHORIZATION'){
                TicketTypes::assertCanFile('OIC_AUTHORIZATION');
                // The subject is the nominee; TicketOicAuthorization::validate decides whether this filer may nominate them.
                $employee=EmployeeApproverAssignmentService::employee((int)$data['employee'],true);
                if(!in_array($employee['status'],['ACTIVE','PROBATIONARY','ON_LEAVE'],true))throw new RuntimeException('Choose an active employee.');
                return $employee;
            }
            if($typeCode==='BRANCH_TRANSFER'){
                $employee=EmployeeApproverAssignmentService::employee((int)$data['employee'],true);
                if((int)$employee['user_id']!==TicketRepository::uid()&&!Auth::can('ticket.file_for_others'))throw new RuntimeException('You cannot file a ticket for another employee.');
                if(!in_array($employee['status'],['ACTIVE','PROBATIONARY','ON_LEAVE'],true))throw new RuntimeException('Choose an active employee.');
                return $employee;
            }
        }
        return TicketRepository::ownEmployee();
    }
    private static function text(mixed $value,int $max,string $label):string
    {
        if(!is_string($value))throw new RuntimeException($label.' is required.');$value=trim($value);
        if($value==='' || mb_strlen($value)>$max)throw new RuntimeException($label.' is required and must be '.$max.' characters or fewer.');return $value;
    }
    private static function fields(array $data):array
    {
        $q=db()->prepare('SELECT * FROM ticket_types WHERE id=? AND active=1');$q->execute([(int)($data['ticket_type_id']??0)]);$type=$q->fetch();
        if(!$type)throw new RuntimeException('Choose an available request type.');
        TicketTypes::assertCanFile($type['code']);
        if($type['code']!=='PAYROLL_CONCERN'){
            $e=self::requireFiler($data);
            return ['type'=>$type,'period'=>null,'date'=>null,'explanation'=>'','payload'=>TicketTypes::validate($type['code'],$data,$e)];
        }
        $date=EmployeeApproverAssignmentService::date(is_string($data['concern_date']??null)?$data['concern_date']:'');
        if($date>date('Y-m-d'))throw new RuntimeException('The concern date cannot be in the future.');
        $remarks=self::text($data['explanation']??null,5000,'Remarks / Explanation');
        $type=(int)($data['ticket_type_id']??0);$q=db()->prepare('SELECT * FROM ticket_types WHERE id=? AND active=1 AND code="PAYROLL_CONCERN"');$q->execute([$type]);$row=$q->fetch();
        if(!$row)throw new RuntimeException('Choose an available request type.');
        $period=TicketRepository::periodForDate($date,true);
        if(!in_array($period['status'],['OPEN','CLOSED'],true) && TicketRepository::lateReason($period)===null)throw new RuntimeException('This cutoff is not open for filing. Please ask HR to check the cutoff calendar.');
        return ['date'=>$date,'explanation'=>$remarks,'type'=>$row,'period'=>$period];
    }
    private static function snapshot(array $e):array
    {
        if(empty($e['legal_entity_id']) || empty($e['business_unit_id']) || empty($e['branch_id']) || empty($e['company_name']) || empty($e['company_group']))throw new RuntimeException('Please ask HR to complete your company and branch assignment first.');
        $q=db()->prepare('SELECT COALESCE(ds.name,d.name,b.name) section_name FROM employees e LEFT JOIN directory_sections ds ON ds.id=e.directory_section_id LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN branches b ON b.id=e.branch_id WHERE e.id=?');$q->execute([$e['id']]);
        return ['employee_code'=>$e['employee_no'],'employee_name'=>trim($e['first_name'].' '.$e['last_name']),'branch_name'=>$e['branch_name'],'section_name'=>$q->fetchColumn()?:$e['branch_name']];
    }
    public static function create(array $data,bool $submit,array $files=[]):int
    {
        $e=self::requireFiler($data);$key=(string)($data['submission_key']??'');
        if(!preg_match('/^[a-f0-9]{64}$/',$key))throw new RuntimeException('Reload the filing form before submitting.');
        return self::transaction(function()use($e,$key,$data,$submit,$files){
            $employee=EmployeeApproverAssignmentService::employee((int)$e['id'],true);
            $q=db()->prepare('SELECT ticket_id FROM ticket_submission_keys WHERE submission_key=? AND requester_user_id=?');$q->execute([$key,TicketRepository::uid()]);
            if($id=$q->fetchColumn()){TicketRepository::find((int)$id);return (int)$id;}
            $fields=self::fields($data);$snapshot=self::snapshot($employee);$payload=$snapshot+($fields['payload']??['explanation'=>$fields['explanation']]);
            $no='HR-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(6)));
            $q=db()->prepare('INSERT INTO tickets(ticket_no,ticket_type_id,requester_user_id,subject_employee_id,company_id,branch_id,payroll_period_id,company_group_snapshot,company_name_snapshot,business_unit_id_snapshot,payload_json) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$no,$fields['type']['id'],TicketRepository::uid(),$employee['id'],$employee['legal_entity_id'],$employee['branch_id'],$fields['period']['id']??null,$employee['company_group'],$employee['company_name'],$employee['business_unit_id'],json_encode($payload,JSON_THROW_ON_ERROR)]);$id=(int)db()->lastInsertId();
            if($fields['type']['code']==='PAYROLL_CONCERN')db()->prepare('INSERT INTO payroll_concerns(ticket_id,concern_date) VALUES(?,?)')->execute([$id,$fields['date']]);
            else TicketTypes::save($id,$fields['type']['code'],$payload);
            db()->prepare('INSERT INTO ticket_submission_keys(submission_key,requester_user_id,ticket_id) VALUES(?,?,?)')->execute([$key,TicketRepository::uid(),$id]);
            self::event($id,'CREATED',null,'DRAFT');$t=TicketRepository::find($id,true);
            if($submit)self::submitLocked($t,$employee,$fields['type']);
            self::storeAttachments($t,$files);return $id;
        });
    }
    private static function assertVersion(array $t,int $version):void
    {
        if((int)$t['version']!==$version)throw new RuntimeException('This ticket changed. Refresh it before trying again.');
    }
    public static function edit(int $id,int $version,array $data,bool $submit=false,array $files=[]):int
    {
        self::requireFiler($data);return self::transaction(function()use($id,$version,$data,$submit,$files){
            $t=TicketRepository::find($id,true);self::assertVersion($t,$version);
            if((int)$t['requester_user_id']!==TicketRepository::uid() || !in_array($t['status'],['DRAFT','RETURNED'],true))throw new RuntimeException('Only your draft or returned request can be edited.');
            $e=EmployeeApproverAssignmentService::employee((int)$t['subject_employee_id'],true);
            if((int)$e['user_id']!==TicketRepository::uid() && !($t['type_code']==='BRANCH_TRANSFER'&&Auth::can('ticket.file_for_others')) && $t['type_code']!=='OIC_AUTHORIZATION')throw new RuntimeException('Please ask HR to check your linked employee record.');
            $fields=self::fields($data);
            if(in_array($t['type_code'],['BRANCH_TRANSFER','OIC_AUTHORIZATION'],true)&&(int)$fields['payload']['employee']!==(int)$t['subject_employee_id'])throw new RuntimeException('The subject employee cannot change.');
            if((int)$fields['type']['id']!==(int)$t['ticket_type_id'])throw new RuntimeException('Keep this ticket type. File a separate request for another type.');
            if($t['type_code']==='PAYROLL_CONCERN' && $t['filed_at']!==null && ($fields['date']!==$t['concern_date'] || (int)$fields['period']['id']!==(int)$t['payroll_period_id']))throw new RuntimeException('Keep the original concern date and cutoff. File a separate ticket for another cutoff.');
            $payload=$t['payload'];$oldHash=hash('sha256',json_encode($payload));$payload=array_replace($payload,$fields['payload']??['explanation'=>$fields['explanation']]);
            if($t['filed_at']===null)$payload=array_replace($payload,self::snapshot($e));
            db()->prepare('UPDATE tickets SET payroll_period_id=?,payload_json=?,version=version+1 WHERE id=?')->execute([$fields['period']['id']??null,json_encode($payload,JSON_THROW_ON_ERROR),$id]);
            if($t['type_code']==='PAYROLL_CONCERN')db()->prepare('UPDATE payroll_concerns SET concern_date=? WHERE ticket_id=?')->execute([$fields['date'],$id]);
            else TicketTypes::save($id,$t['type_code'],$payload);
            self::event($id,'EDITED',$t['status'],$t['status'],['fields'=>['explanation','concern_date'],'before_explanation_sha256'=>$oldHash,'after_explanation_sha256'=>hash('sha256',json_encode($payload))]);
            $t=TicketRepository::find($id,true);
            if($submit)self::submitLocked($t,$e,$fields['type']);
            self::storeAttachments($t,$files);return $id;
        });
    }
    private static function submitLocked(array &$t,array $employee,array $type):void
    {
        if($t['type_code']==='INCIDENT_REPORT'){
            db()->prepare('UPDATE tickets SET filed_at=COALESCE(filed_at,NOW()),current_step_no=0 WHERE id=?')->execute([$t['id']]);
            self::transition($t,'SUBMITTED','','PUBLISHED');return;
        }
        $late=null;if($t['type_code']==='PAYROLL_CONCERN'){$period=TicketRepository::period((int)$t['payroll_period_id'],true);$late=TicketRepository::lateReason($period);}
        if($t['is_late'])$late=$late?:$t['late_reason'];
        $config=json_decode($type['routing_config_json']?:'{}',true,512,JSON_THROW_ON_ERROR);
        $kind=EmployeeApproverAssignmentService::kind($employee);$key=$late?'LATE':($kind==='MANAGER'?'HEAD_OFFICE':'BRANCH');
        // A Manager files straight to HR (no Manager above): reuse the HR-only profile of the late route.
        if($key==='HEAD_OFFICE' && $t['type_code']==='PAYROLL_CONCERN' && EmployeeApproverAssignmentService::isManagerFiler($employee))$key='LATE';
        $profile=$config[$key]??$type['routing_profile'];$q=db()->prepare('SELECT steps_json FROM ticket_routing_profiles WHERE code=? AND active=1');$q->execute([$profile]);$json=$q->fetchColumn();
        if(!$json)throw new RuntimeException('Please ask HR to configure this request routing profile.');$chain=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!$chain || !is_array($chain) || end($chain)!=='HR')throw new RuntimeException('This request needs a final HR routing step.');
        if($late && $chain!==['HR'])throw new RuntimeException('Please ask HR to configure the HR-only late-concern route.');
        $resolved=[];
        $q=db()->prepare('SELECT * FROM ticket_steps WHERE ticket_id=? ORDER BY step_no');$q->execute([$t['id']]);$frozen=$q->fetchAll();
        if($t['type_code']!=='PAYROLL_CONCERN' && $frozen){
            // Each revision copies the original identities, never current assignments.
            $firstProfile=$frozen[0]['routing_profile_snapshot'];
            foreach($frozen as $s){if($s['routing_profile_snapshot']!==$firstProfile)break;$resolved[]=['type'=>$s['approver_type'],'user_id'=>$s['approver_user_id'],'assignment_id'=>$s['assignment_id_snapshot']];if($s['approver_type']==='HR')break;}
            $profile=$firstProfile;
        }
        foreach($resolved?[]:$chain as $approver){
            if($approver==='HR'){$resolved[]=['type'=>'HR','user_id'=>null,'assignment_id'=>null];continue;}
            if(!in_array($approver,['MANAGER','ADL'],true) || $approver!==$kind)throw new RuntimeException('Please ask HR to check this request routing configuration.');
            $route=EmployeeApproverAssignmentService::resolve($employee,date('Y-m-d'));
            if(!$route['user_id'])throw new RuntimeException($route['reason']);$resolved[]=$route;
        }
        db()->prepare('UPDATE ticket_steps SET status="SKIPPED" WHERE ticket_id=? AND status="PENDING"')->execute([$t['id']]);
        $q=db()->prepare('SELECT COALESCE(MAX(step_no),0) FROM ticket_steps WHERE ticket_id=?');$q->execute([$t['id']]);$step=(int)$q->fetchColumn()+1;$first=$step;
        foreach($resolved as $row){db()->prepare('INSERT INTO ticket_steps(ticket_id,step_no,approver_type,approver_user_id,assignment_id_snapshot,routing_profile_snapshot) VALUES(?,?,?,?,?,?)')->execute([$t['id'],$step++,$row['type'],$row['user_id'],$row['assignment_id'],$profile]);}
        if($t['type_code']==='NETSUITE_CONCERN')db()->prepare('INSERT INTO ticket_steps(ticket_id,step_no,approver_type,routing_profile_snapshot) VALUES(?,?,"ACCOUNTING",?)')->execute([$t['id'],$step,$profile]);
        if($t['filed_at']===null){
            db()->prepare('UPDATE tickets SET company_id=?,branch_id=?,business_unit_id_snapshot=?,company_group_snapshot=?,company_name_snapshot=?,filed_at=NOW() WHERE id=?')->execute([$employee['legal_entity_id'],$employee['branch_id'],$employee['business_unit_id'],$employee['company_group'],$employee['company_name'],$t['id']]);
        }
        if($t['type_code']==='PAYROLL_CONCERN')db()->prepare('UPDATE payroll_concerns SET is_late=?,late_reason=? WHERE ticket_id=?')->execute([$late?1:0,$late,$t['id']]);
        // SUBMITTED is an audited transient state; notify only the selected pending step.
        $from=$t['status'];TicketPolicy::assertTransition($from,'SUBMITTED');self::event((int)$t['id'],'SUBMITTED',$from,'SUBMITTED',['routing_profile'=>$profile,'late_reason'=>$late]);
        db()->prepare('UPDATE tickets SET status="SUBMITTED",current_step_no=?,version=version+1 WHERE id=?')->execute([$first,$t['id']]);
        $t['status']='SUBMITTED';$t['version']++;$t['current_step_no']=$first;
        self::transition($t,$resolved[0]['type']==='HR'?'PENDING_HR':'PENDING_APPROVAL');
    }
    public static function permissions(array $t):array
    {
        $uid=TicketRepository::uid();$own=(int)$t['requester_user_id']===$uid;
        $self=$own || (int)($t['subject_user_id']??0)===$uid;
        $q=db()->prepare('SELECT * FROM ticket_steps WHERE ticket_id=? AND step_no=? AND status="PENDING"');$q->execute([$t['id'],$t['current_step_no']]);$step=$q->fetch();
        $named=$step && (int)$step['approver_user_id']===$uid && Auth::can($step['approver_type']==='ADL'?'payroll.approve_adl':'payroll.approve_manager');
        if(!empty($t['viewer_only']))return ['edit'=>false,'cancel'=>false,'approve'=>false,'hr'=>false,'return_reject'=>false,'attach'=>false];// read-only viewer
        $hr=TicketRepository::isHr();$hrStep=$hr && $step && $step['approver_type']==='HR' && in_array($t['status'],['PENDING_HR','IN_PROGRESS'],true);$pending=in_array($t['status'],['PENDING_APPROVAL','PENDING_HR','IN_PROGRESS'],true);
        return ['edit'=>$own && TicketTypes::canFile($t['type_code']) && in_array($t['status'],['DRAFT','RETURNED'],true),
            'cancel'=>!(($t['type_code']==='NETSUITE_CONCERN')&&$t['filed_at']!==null) && (($own&&in_array($t['status'],['DRAFT','RETURNED','PENDING_APPROVAL'],true))||(!$self&&($hrStep||($hr&&$t['type_code']==='INCIDENT_REPORT'&&$t['status']==='SUBMITTED')))),
            'approve'=>!$self && $named && $t['status']==='PENDING_APPROVAL',
            'hr'=>!$self && $hr && $step && $step['approver_type']==='HR' && in_array($t['status'],['PENDING_HR','IN_PROGRESS'],true),
            'return_reject'=>$t['type_code']!=='NETSUITE_CONCERN' && !$self && $pending && (($named && $t['status']==='PENDING_APPROVAL') || $hrStep),
            'attach'=>($own && (in_array($t['status'],['DRAFT','RETURNED'],true)||($t['type_code']==='INCIDENT_REPORT'&&$t['status']==='SUBMITTED'))) || (!$self && $hr && (in_array($t['status'],['PENDING_HR','IN_PROGRESS'],true)||($t['type_code']==='INCIDENT_REPORT'&&$t['status']==='SUBMITTED'))),
        ];
    }
    public static function act(int $id,int $version,string $action,string $remarks='',string $outcome='',int $target=0,array $completionData=[]):int
    {
        return self::transaction(function()use($id,$version,$action,$remarks,$outcome,$target,$completionData){
            $t=TicketRepository::find($id,true);if(!empty($t['viewer_only']))throw new RuntimeException('View-only access: you cannot act on this request.');$p=self::permissions($t);$remarks=trim($remarks);
            if($t['type_code']==='NETSUITE_CONCERN'&&$t['filed_at']!==null&&in_array($action,['START','RETURN','REJECT','CANCEL'],true))throw new RuntimeException('NetSuite uses Pending and Complete.');
            if(mb_strlen($remarks)>5000)throw new RuntimeException('Remarks must be 5000 characters or fewer.');
            if($action==='COMPLETE' && $t['status']==='COMPLETED'){
                if(!TicketRepository::isHr() || (int)$t['requester_user_id']===TicketRepository::uid() || (int)$t['subject_user_id']===TicketRepository::uid())throw new RuntimeException('Another HR reviewer must handle your request.');
                $q=db()->prepare('SELECT target_payroll_period_id,created_by FROM ticket_effects WHERE ticket_id=? AND effect_type="MANUAL_PAYROLL_ADJUSTMENT"');$q->execute([$id]);$effect=$q->fetch();
                if($t['type_code']!=='PAYROLL_CONCERN')return $id;
                if($t['outcome']===$outcome && $t['outcome_note']===$remarks && (($outcome==='NO_CHANGE' && !$target) || ($effect && (int)$effect['target_payroll_period_id']===$target && (int)$effect['created_by']===TicketRepository::uid())))return $id;
                throw new RuntimeException('This completed outcome cannot be changed.');
            }
            self::assertVersion($t,$version);
            $q=db()->prepare('SELECT * FROM ticket_steps WHERE ticket_id=? AND step_no=? AND status="PENDING" FOR UPDATE');$q->execute([$id,$t['current_step_no']]);$step=$q->fetch();
            if($action==='CANCEL'){
                if(!$p['cancel'])throw new RuntimeException('This request cannot be cancelled by your account.');
                db()->prepare('UPDATE ticket_steps SET status="SKIPPED" WHERE ticket_id=? AND status="PENDING"')->execute([$id]);self::transition($t,'CANCELLED',$remarks);return $id;
            }
            if(in_array($action,['RETURN','REJECT'],true)){
                if(!$p['return_reject'])throw new RuntimeException('This action belongs to the current approver or HR.');
                $remarks=self::text($remarks,5000,'Remarks');$new=$action==='RETURN'?'RETURNED':'REJECTED';
                self::decideStep($step,$new,$remarks);
                db()->prepare('UPDATE ticket_steps SET status="SKIPPED" WHERE ticket_id=? AND status="PENDING"')->execute([$id]);
                self::transition($t,$new,$remarks);return $id;
            }
            if($action==='APPROVE'){
                if(!$p['approve'])throw new RuntimeException('Only the named current approver can approve this step.');
                self::decideStep($step,'APPROVED',$remarks);
                $q=db()->prepare('SELECT * FROM ticket_steps WHERE ticket_id=? AND step_no>? AND status="PENDING" ORDER BY step_no LIMIT 1');$q->execute([$id,$t['current_step_no']]);$next=$q->fetch();
                if(!$next)throw new RuntimeException('The final HR step is missing.');
                $t['current_step_no']=(int)$next['step_no'];db()->prepare('UPDATE tickets SET current_step_no=? WHERE id=?')->execute([$t['current_step_no'],$id]);self::transition($t,$next['approver_type']==='HR'?'PENDING_HR':'PENDING_APPROVAL',$remarks);return $id;
            }
            if(!$p['hr'])throw new RuntimeException('Only another authorized HR reviewer can handle this step.');
            if($action==='START'){if($t['type_code']==='NETSUITE_CONCERN')throw new RuntimeException('NetSuite uses Pending and Complete.');self::transition($t,'IN_PROGRESS',$remarks);return $id;}
            if($action!=='COMPLETE')throw new RuntimeException('Unknown ticket action.');
            if($t['type_code']!=='PAYROLL_CONCERN'){if(!TicketTypes::complete($t,$completionData)){if($t['status']==='PENDING_HR')self::transition($t,'IN_PROGRESS','Awaiting transfer effective date');return $id;}self::decideStep($step,'APPROVED',$remarks);self::transition($t,'COMPLETED',$remarks);return $id;}
            $remarks=self::text($remarks,5000,'HR outcome explanation');
            if(!in_array($outcome,['NO_CHANGE','MANUAL_ADJUSTMENT'],true))throw new RuntimeException('Choose an HR outcome.');
            if($outcome==='MANUAL_ADJUSTMENT'){
                // Re-read and lock the target at completion; never call attendance rebuild/finalization.
                $period=TicketRepository::period($target,true);
                if($period['status']!=='OPEN' || TicketRepository::lateReason($period)!==null || $period['period_start']<=$t['period_end'])throw new RuntimeException('Choose a later open cutoff with an open filing deadline.');
                $choices=TicketRepository::targetPeriods($t);
                if(!$choices || (int)$choices[0]['id']!==$target)throw new RuntimeException('Choose the next available open cutoff. Refresh the ticket.');
                $instruction=['source_cutoff_id'=>(int)$t['payroll_period_id'],'target_cutoff_id'=>$target,'target_period_label'=>$period['period_start'].' to '.$period['period_end'],'employee_id'=>(int)$t['subject_employee_id'],'instruction'=>$remarks,'manual_processing'=>true,'company_group'=>$t['company_group_snapshot'],'company_name'=>$t['company_name_snapshot']];
                db()->prepare('INSERT INTO ticket_effects(ticket_id,effect_type,target_payroll_period_id,instruction_json,created_by) VALUES(?,"MANUAL_PAYROLL_ADJUSTMENT",?,?,?)')->execute([$id,$target,json_encode($instruction,JSON_THROW_ON_ERROR),TicketRepository::uid()]);
                self::event($id,'EFFECT_CREATED',$t['status'],$t['status'],['effect_type'=>'MANUAL_PAYROLL_ADJUSTMENT','target_period_id'=>$target]);
            }elseif($target!==0)throw new RuntimeException('No-change outcomes do not have an adjustment cutoff.');
            db()->prepare('UPDATE payroll_concerns SET outcome=?,outcome_note=? WHERE ticket_id=?')->execute([$outcome,$remarks,$id]);self::decideStep($step,'APPROVED',$remarks);self::transition($t,'COMPLETED',$remarks);return $id;
        });
    }
    private static function decideStep(array $step,string $status,string $remarks):void
    {
        db()->prepare('UPDATE ticket_steps SET status=?,remarks=?,acted_by=?,acted_at=NOW() WHERE id=?')->execute([$status,$remarks,TicketRepository::uid(),$step['id']]);
    }
    public static function comment(int $id,string $body,string $visibility='ALL'):int
    {
        $body=self::text($body,3000,'Comment');return self::transaction(function()use($id,$body,$visibility){$t=TicketRepository::find($id,true);if(!empty($t['viewer_only']))throw new RuntimeException('View-only access: you cannot comment on this request.');
            if(!in_array($visibility,['ALL','STAFF_ONLY'],true)||($visibility==='STAFF_ONLY'&&($t['type_code']!=='INCIDENT_REPORT'||!TicketRepository::isStaff($t))))throw new RuntimeException('Staff-only comments require authorized staff access.');
            if(FoundationRepository::columnExists('ticket_comments','visibility'))db()->prepare('INSERT INTO ticket_comments(ticket_id,author_user_id,body,visibility) VALUES(?,?,?,?)')->execute([$id,TicketRepository::uid(),$body,$visibility]);
            else db()->prepare('INSERT INTO ticket_comments(ticket_id,author_user_id,body) VALUES(?,?,?)')->execute([$id,TicketRepository::uid(),$body]);$comment=(int)db()->lastInsertId();
            $event=self::event($id,'COMMENT_ADDED',$t['status'],$t['status'],['comment_id'=>$comment]);if($visibility==='ALL')TicketNotifications::enqueue($event,$t);return $id;});
    }
    public static function attach(int $id,array $files):int
    {
        return self::transaction(function()use($id,$files){$t=TicketRepository::find($id,true);if(!self::permissions($t)['attach'])throw new RuntimeException('Attachments can be added to your draft/returned request or during HR review.');self::storeAttachments($t,$files);return $id;});
    }
    private static function storeAttachments(array $t,array $files):void
    {
        if(!$files)return;
        $names=is_array($files['name']??null)?$files['name']:[$files['name']??''];$tmp=is_array($files['tmp_name']??null)?$files['tmp_name']:[$files['tmp_name']??''];$errors=is_array($files['error']??null)?$files['error']:[$files['error']??UPLOAD_ERR_NO_FILE];
        $q=db()->prepare('SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id=?');$q->execute([$t['id']]);$count=(int)$q->fetchColumn();
        $allowed=['application/pdf'=>['pdf'],'image/jpeg'=>['jpg','jpeg'],'image/png'=>['png'],'image/webp'=>['webp']];$finfo=new finfo(FILEINFO_MIME_TYPE);
        foreach($names as $i=>$name){if(($errors[$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
            if(($errors[$i]??null)!==UPLOAD_ERR_OK || !is_string($tmp[$i]??null) || !is_uploaded_file($tmp[$i]))throw new RuntimeException('The attachment could not be uploaded.');
            if(++$count>5)throw new RuntimeException('Each request may have up to five attachments.');$size=filesize($tmp[$i]);
            if(!$size || $size>10*1024*1024)throw new RuntimeException('Each attachment must be between 1 byte and 10 MB.');
            $mime=$finfo->file($tmp[$i]);$ext=strtolower(pathinfo((string)$name,PATHINFO_EXTENSION));
            if(!isset($allowed[$mime]) || !in_array($ext,$allowed[$mime],true))throw new RuntimeException('Attach PDF, JPG, PNG, or WEBP files with a matching file extension.');
            $dir=dirname(__DIR__).'/storage/ticket_attachments';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Attachment storage is unavailable.');
            $stored=bin2hex(random_bytes(24)).'.'.$allowed[$mime][0];$path=$dir.'/'.$stored;
            if(!move_uploaded_file($tmp[$i],$path))throw new RuntimeException('The attachment could not be saved.');self::$movedFiles[]=$path;
            db()->prepare('INSERT INTO ticket_attachments(ticket_id,original_name,stored_name,mime_type,file_size,uploaded_by) VALUES(?,?,?,?,?,?)')->execute([$t['id'],mb_substr(basename((string)$name),0,255),$stored,$mime,$size,TicketRepository::uid()]);
            $event=self::event((int)$t['id'],'ATTACHMENT_ADDED',$t['status'],$t['status'],['attachment_id'=>(int)db()->lastInsertId()]);TicketNotifications::enqueue($event,$t);
        }
    }
    public static function attachment(int $id):array
    {
        $q=db()->prepare('SELECT * FROM ticket_attachments WHERE id=?');$q->execute([$id]);$file=$q->fetch();
        if(!$file)throw new RuntimeException('Attachment not found.');$ticket=TicketRepository::find((int)$file['ticket_id']);if(!empty($ticket['viewer_only']))throw new RuntimeException('View-only access: attachments are not available.');
        $path=dirname(__DIR__).'/storage/ticket_attachments/'.$file['stored_name'];
        if(!preg_match('/^[a-f0-9]{48}\.(pdf|jpg|png|webp)$/',$file['stored_name']) || !is_file($path))throw new RuntimeException('Attachment file is missing.');
        return $file+['path'=>$path];
    }
}
