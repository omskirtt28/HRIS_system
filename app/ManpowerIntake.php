<?php
declare(strict_types=1);
require_once __DIR__.'/TicketService.php';

/**
 * rec-manpower-intake (SPEC-rec-manpower-intake.md): Manpower Request tickets reach HR > Recruitment > Manpower Requests.
 * The ticket stays the approval record. A manpower_requests row exists only after HR approves (the employer is chosen then),
 * so the HR list is the recruitment rows plus the tickets that have no row yet. Callers check recruitment.manage and CSRF;
 * the ticket step itself is enforced by TicketService::act (HR reviewers only, never the requester).
 */
final class ManpowerIntake
{
    private const PRIORITIES=['NORMAL','URGENT'];
    private const EMPLOYMENT=['Probationary'=>'FULL_TIME','Seasonal'=>'CONTRACT'];
    private const OPEN_STATUSES=['PENDING_HR','IN_PROGRESS'];

    /** @param string $filter open (default: requests plus tickets waiting for HR) | declined | all */
    public static function list(string $filter='open'):array
    {
        $rows=[];
        if($filter!=='declined'){
            foreach(db()->query('SELECT m.id,m.ticket_id,m.request_no,m.position_title,m.requested_headcount,m.filled_headcount,m.priority,m.status,m.target_start_date,m.created_at,c.name employer,u.full_name requester FROM manpower_requests m JOIN clients c ON c.id=m.client_id LEFT JOIN users u ON u.id=m.requested_by'.($filter==='all'?'':' WHERE m.ticket_id IS NOT NULL'))->fetchAll() as $m){
                $rows[]=['source'=>$m['ticket_id']===null?'HR posting':'Ticket #'.$m['ticket_id'],'ticket_id'=>$m['ticket_id']===null?null:(int)$m['ticket_id'],'id'=>(int)$m['id'],'request_no'=>$m['request_no'],'position_title'=>$m['position_title'],'employer'=>$m['employer'],
                    'requested'=>(int)$m['requested_headcount'],'filled'=>(int)$m['filled_headcount'],'priority'=>$m['priority'],'status'=>$m['status'],'target_start_date'=>$m['target_start_date'],'requester'=>$m['requester'],'version'=>null,'detail'=>'','created_at'=>$m['created_at']];
            }
        }
        $statuses=match($filter){'declined'=>['REJECTED'],'all'=>[...self::OPEN_STATUSES,'REJECTED'],default=>self::OPEN_STATUSES};
        $in=implode(',',array_fill(0,count($statuses),'?'));
        $q=db()->prepare('SELECT t.id,t.status,t.version,t.created_at,tm.position_code,tm.quantity,tm.request_type,tm.employment_type,tm.section_branch,tm.area_department,u.full_name requester FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id JOIN ticket_manpower_requests tm ON tm.ticket_id=t.id LEFT JOIN users u ON u.id=tm.requested_by WHERE ty.code="MANPOWER_REQUEST" AND t.status IN ('.$in.') AND NOT EXISTS (SELECT 1 FROM manpower_requests m WHERE m.ticket_id=t.id)');
        $q->execute($statuses);
        foreach($q->fetchAll() as $t){
            $rows[]=['source'=>'Ticket #'.$t['id'],'ticket_id'=>(int)$t['id'],'id'=>null,'request_no'=>null,'position_title'=>$t['position_code'],'employer'=>null,'requested'=>(int)$t['quantity'],'filled'=>0,'priority'=>null,
                'status'=>$t['status']==='REJECTED'?'DECLINED':'AWAITING','target_start_date'=>null,'requester'=>$t['requester'],'version'=>(int)$t['version'],
                'detail'=>$t['request_type'].' / '.$t['employment_type'].' / '.$t['section_branch'].' / '.$t['area_department'],'created_at'=>$t['created_at']];
        }
        usort($rows,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));
        return $rows;
    }

    /** Employers are chosen by HR, nothing is pre-filled. Returns the clients.id. */
    public static function addEmployer(string $name):int
    {
        $name=trim((string)preg_replace('/\s+/',' ',$name));
        if($name===''||mb_strlen($name)>180)throw new RuntimeException('Enter an employer name of up to 180 characters.');
        $q=db()->prepare('SELECT id FROM clients WHERE LOWER(name)=LOWER(?)');$q->execute([$name]);
        if($q->fetchColumn())throw new RuntimeException('That employer already exists.');
        $code=strtoupper(substr((string)preg_replace('/[^A-Za-z0-9]/','',$name),0,20)).'-'.bin2hex(random_bytes(3));
        db()->prepare('INSERT INTO clients(code,name) VALUES(?,?)')->execute([$code,$name]);
        $id=(int)db()->lastInsertId();audit('Recruitment','ADD_EMPLOYER','client',$id);return $id;
    }

    /** Used by the Approve dialog: a retry after a refused approval must reuse the employer typed the first time. */
    public static function employerByName(string $name):int
    {
        $q=db()->prepare('SELECT id FROM clients WHERE LOWER(name)=LOWER(?)');$q->execute([trim((string)preg_replace('/\s+/',' ',$name))]);
        return (int)$q->fetchColumn()?:self::addEmployer($name);
    }

    /** Approve = complete the HR step of the ticket and create the linked request. Returns manpower_requests.id. */
    public static function approve(int $ticketId,int $version,int $clientId,string $priority='NORMAL',string $remarks='',string $title=''):int
    {
        $t=self::openTicket($ticketId);
        if(!in_array($priority,self::PRIORITIES,true))throw new RuntimeException('Choose a valid priority.');
        $q=db()->prepare('SELECT id FROM clients WHERE id=?');$q->execute([$clientId]);
        if(!$q->fetchColumn())throw new RuntimeException('Choose an employer.');
        $title=trim($title)===''?$t['position_code']:trim($title);
        if(mb_strlen($title)>160)throw new RuntimeException('Position title must be 160 characters or fewer.');
        $notes='Source: Manpower Request ticket #'.$ticketId.'. '.$t['request_type'].($t['replacement_of_employee_id']?' (replaces employee #'.$t['replacement_of_employee_id'].')':'').', '.$t['employment_type'].'. Section/Branch: '.$t['section_branch'].'. Area/Department: '.$t['area_department'].".\n".$t['job_description_qualifications'];
        // Insert first: the unique ticket_id turns a double click into a refusal before the ticket changes; undo the row if the ticket step is refused.
        db()->prepare('INSERT INTO manpower_requests(request_no,ticket_id,client_id,position_title,requested_headcount,employment_type,priority,status,requested_by,approved_by,approved_at,notes) VALUES(?,?,?,?,?,?,?,"APPROVED",?,?,NOW(),?)')
            ->execute(['MR-'.date('Ymd').'-T'.$ticketId,$ticketId,$clientId,$title,(int)$t['quantity'],self::EMPLOYMENT[$t['employment_type']],$priority,(int)$t['requested_by'],TicketRepository::uid(),$notes]);
        $id=(int)db()->lastInsertId();
        try{TicketService::act($ticketId,$version,'COMPLETE',$remarks);}
        catch(Throwable $e){db()->prepare('DELETE FROM manpower_requests WHERE id=?')->execute([$id]);throw $e;}
        audit('Recruitment','APPROVE_MANPOWER_REQUEST','manpower_request',$id,['ticket_id'=>$ticketId]);
        return $id;
    }

    /** Decline needs a remark; no recruitment row is created. */
    public static function decline(int $ticketId,int $version,string $remarks):void
    {
        self::openTicket($ticketId);
        if(trim($remarks)==='')throw new RuntimeException('Enter the reason for declining.');
        TicketService::act($ticketId,$version,'REJECT',$remarks);
        audit('Recruitment','DECLINE_MANPOWER_REQUEST','ticket',$ticketId);
    }

    /** The ticket must be a Manpower Request still waiting for HR and not yet turned into a request. */
    private static function openTicket(int $ticketId):array
    {
        // Same gate as the ticket step: recruitment.manage alone (Recruiter, Coordinator) is not enough to decide a ticket.
        if(!TicketRepository::isHr())throw new RuntimeException('Only HR reviewers can approve or decline manpower requests.');
        $q=db()->prepare('SELECT t.status,tm.* FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id JOIN ticket_manpower_requests tm ON tm.ticket_id=t.id WHERE t.id=? AND ty.code="MANPOWER_REQUEST"');
        $q->execute([$ticketId]);$t=$q->fetch();
        if(!$t)throw new RuntimeException('Manpower request not found.');
        if(!in_array($t['status'],self::OPEN_STATUSES,true))throw new RuntimeException('This request is no longer waiting for HR.');
        $q=db()->prepare('SELECT 1 FROM manpower_requests WHERE ticket_id=?');$q->execute([$ticketId]);
        if($q->fetchColumn())throw new RuntimeException('This request was already approved.');
        return $t;
    }
}
