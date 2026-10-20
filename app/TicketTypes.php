<?php
declare(strict_types=1);
require_once __DIR__.'/TicketBranchTransfer.php';
final class TicketTypes
{
    public static function definitions():array { return require dirname(__DIR__).'/config/ticket_types.php'; }
    public static function definition(string $code):array {
        return self::definitions()[$code]??throw new RuntimeException('Choose an available request type.');
    }
    public static function validate(string $code,array $data,array $employee):array {
        $out=[];
        foreach(self::definition($code)['fields'] as $key=>$field){
            $value=$data[$key]??'';
            if(!is_scalar($value))throw new RuntimeException($field['label'].' is required.');
            $value=trim((string)$value);
            if(!empty($field['optional'])&&$value===''){$out[$key]=null;continue;}
            if($value===''||mb_strlen($value)>($field['max']??5000))throw new RuntimeException($field['label'].' is required and is too long or empty.');
            if(isset($field['options'])&&!in_array($value,$field['options'],true))throw new RuntimeException('Choose a valid '.$field['label'].'.');
            if(($field['kind']??'')==='number'){
                if(!ctype_digit($value)||(int)$value<($field['min']??1)||(int)$value>($field['max_value']??PHP_INT_MAX))throw new RuntimeException($field['label'].' is outside the allowed range.');
                $value=(int)$value;
            }
            if(($field['kind']??'')==='branch'){
                $q=db()->prepare('SELECT id FROM branches WHERE id=? AND active=1');$q->execute([$value]);
                if(!ctype_digit($value)||!$q->fetchColumn())throw new RuntimeException('Choose an active branch.');$value=(int)$value;
            }
            if(($field['kind']??'')==='employee'){
                if(!ctype_digit($value)||!isset(self::employeeChoices()[(int)$value]))throw new RuntimeException('Choose an employee you are allowed to select.');$value=(int)$value;
            }
            $out[$key]=$value;
        }
        if($code==='MANPOWER_REQUEST'){
            if($out['request_type']==='Replacement'&&empty($out['replacement_of_employee_id']))throw new RuntimeException('Choose the employee being replaced.');
            if($out['request_type']==='Additional'&&!empty($out['replacement_of_employee_id']))throw new RuntimeException('Additional requests must not specify a replacement employee.');
            $out['requested_by']=TicketRepository::uid();
        }
        if($code==='BRANCH_TRANSFER')$out=TicketBranchTransfer::validate($out,$employee);
        return $out;
    }
    public static function save(int $id,string $code,array $payload):void {
        if($code==='UNIFORM_REQUEST')db()->prepare('INSERT INTO uniform_requests(ticket_id,position,size,company,branch_id,quantity,id_lace) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE position=VALUES(position),size=VALUES(size),company=VALUES(company),branch_id=VALUES(branch_id),quantity=VALUES(quantity),id_lace=VALUES(id_lace)')->execute([$id,$payload['position'],$payload['size'],$payload['company'],$payload['branch'],$payload['quantity'],$payload['id_lace']==='YES'?1:0]);
        if($code==='MANPOWER_REQUEST'){
            $columns=['section_branch','requested_by','position_code','employment_type','request_type','quantity','replacement_of_employee_id','job_description_qualifications','area_department'];
            db()->prepare('INSERT INTO ticket_manpower_requests(ticket_id,'.implode(',',$columns).') VALUES(?,'.implode(',',array_fill(0,count($columns),'?')).') ON DUPLICATE KEY UPDATE '.implode(',',array_map(fn($c)=>$c.'=VALUES('.$c.')',$columns)))->execute(array_merge([$id],array_map(fn($c)=>$payload[$c],$columns)));
        }
        if($code==='BRANCH_TRANSFER')db()->prepare('INSERT INTO branch_transfers(ticket_id,employee_id,from_branch_id,to_branch_id,effective_date,company) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE from_branch_id=VALUES(from_branch_id),to_branch_id=VALUES(to_branch_id),effective_date=VALUES(effective_date),company=VALUES(company)')->execute([$id,$payload['employee'],$payload['from_branch'],$payload['to_branch'],$payload['effective_date'],$payload['company']]);
    }
    public static function complete(array $ticket,array $data=[]):bool {
        if($ticket['type_code']==='MANPOWER_REQUEST')db()->prepare('UPDATE ticket_manpower_requests SET date_served=CURDATE() WHERE ticket_id=? AND date_served IS NULL')->execute([$ticket['id']]);
        if($ticket['type_code']==='BRANCH_TRANSFER')return TicketBranchTransfer::complete($ticket,$data);
        return true;
    }
    public static function employeeChoices():array {
        $hr=TicketRepository::isHr()||Auth::can('ticket.file_for_others');$own=$hr?['branch_id'=>0]:TicketRepository::ownEmployee();
        $q=db()->prepare('SELECT id,employee_no,first_name,last_name FROM employees WHERE status IN ("ACTIVE","PROBATIONARY","ON_LEAVE") AND (?=1 OR branch_id=?) ORDER BY last_name,first_name');$q->execute([$hr?1:0,$own['branch_id']]);$out=[];
        foreach($q->fetchAll() as $e)$out[(int)$e['id']]=$e['employee_no'].' · '.$e['first_name'].' '.$e['last_name'];return $out;
    }
    public static function label(string $status,string $code=''):string {
        if($code==='PAYROLL_CONCERN')return stage_label($status);
        if($code==='NETSUITE_CONCERN'&&in_array($status,['PENDING_HR','IN_PROGRESS','COMPLETED'],true))return $status==='COMPLETED'?'Complete':'Pending';
        return match($status){'PENDING_HR'=>'Pending','IN_PROGRESS'=>'In Progress','COMPLETED'=>'Served','CANCELLED'=>'Cancelled',default=>stage_label($status)};
    }
}
