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
            $out[$key]=$value;
        }
        return $out;
    }
    public static function save(int $id,string $code,array $payload):void {}
    public static function complete(array $ticket,array $data=[]):void {}
    public static function label(string $status,string $code=''):string {
        if($code==='PAYROLL_CONCERN')return stage_label($status);
        return match($status){'PENDING_HR'=>'Pending','IN_PROGRESS'=>'In Progress','COMPLETED'=>'Served','CANCELLED'=>'Cancelled',default=>stage_label($status)};
    }
}
