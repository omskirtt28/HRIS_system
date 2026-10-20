<?php
declare(strict_types=1);
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
            $out[$key]=$value;
        }
        return $out;
    }
    public static function save(int $id,string $code,array $payload):void {
        if($code==='UNIFORM_REQUEST')db()->prepare('INSERT INTO uniform_requests(ticket_id,position,size,company,branch_id,quantity,id_lace) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE position=VALUES(position),size=VALUES(size),company=VALUES(company),branch_id=VALUES(branch_id),quantity=VALUES(quantity),id_lace=VALUES(id_lace)')->execute([$id,$payload['position'],$payload['size'],$payload['company'],$payload['branch'],$payload['quantity'],$payload['id_lace']==='YES'?1:0]);
    }
    public static function complete(array $ticket,array $data=[]):void {}
    public static function label(string $status,string $code=''):string {
        if($code==='PAYROLL_CONCERN')return stage_label($status);
        return match($status){'PENDING_HR'=>'Pending','IN_PROGRESS'=>'In Progress','COMPLETED'=>'Served','CANCELLED'=>'Cancelled',default=>stage_label($status)};
    }
}
