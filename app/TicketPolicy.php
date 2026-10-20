<?php
declare(strict_types=1);

/** Pure transition rules. Authorization and transactional effects belong to the ticket service. */
final class TicketPolicy
{
    public const TRANSITIONS = [
        'DRAFT'=>['SUBMITTED','CANCELLED'],
        'SUBMITTED'=>['PENDING_APPROVAL','PENDING_HR'],
        'PENDING_APPROVAL'=>['PENDING_APPROVAL','PENDING_HR','REJECTED','RETURNED','CANCELLED'],
        'PENDING_HR'=>['IN_PROGRESS','COMPLETED','REJECTED','RETURNED','CANCELLED'],
        'IN_PROGRESS'=>['COMPLETED','REJECTED','RETURNED','CANCELLED'],
        'RETURNED'=>['SUBMITTED','CANCELLED'],
        'COMPLETED'=>[], 'REJECTED'=>[], 'CANCELLED'=>[],
    ];

    public static function assertTransition(string $from,string $to,string $remarks='',string $type='PAYROLL_CONCERN'): void
    {
        $extra=($type==='INCIDENT_REPORT'&&$from==='SUBMITTED'&&$to==='CANCELLED');
        if(!$extra && !in_array($to,self::TRANSITIONS[$from]??[],true))throw new RuntimeException('This ticket action is no longer allowed. Refresh the ticket.');
        if(in_array($to,['REJECTED','RETURNED'],true) && trim($remarks)==='')throw new RuntimeException('Remarks are required when returning or rejecting a ticket.');
    }
}
