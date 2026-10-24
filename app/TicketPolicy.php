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
    /** Incident case flow (HR sets it; the reporter may cancel while SUBMITTED). Resolved and Closed reopen to In Progress. */
    public const INCIDENT_TRANSITIONS = [
        'DRAFT'=>['SUBMITTED','CANCELLED'],
        'SUBMITTED'=>['UNDER_REVIEW','DISMISSED','CANCELLED'],
        'UNDER_REVIEW'=>['IN_PROGRESS','RESOLVED','DISMISSED'],
        'IN_PROGRESS'=>['RESOLVED','DISMISSED'],
        'RESOLVED'=>['CLOSED','IN_PROGRESS'],
        'CLOSED'=>['IN_PROGRESS'],
        'DISMISSED'=>[], 'CANCELLED'=>[],
    ];
    /** Every HR status change on an incident carries remarks (Dismissed needs the reason, a reopen needs one too). */
    public const INCIDENT_REMARKS_TO = ['UNDER_REVIEW','IN_PROGRESS','RESOLVED','CLOSED','DISMISSED'];

    public static function assertTransition(string $from,string $to,string $remarks='',string $type='PAYROLL_CONCERN'): void
    {
        if($type==='INCIDENT_REPORT'){
            if(!in_array($to,self::INCIDENT_TRANSITIONS[$from]??[],true))throw new RuntimeException('This case status change is no longer allowed. Refresh the case.');
            if(in_array($to,self::INCIDENT_REMARKS_TO,true) && trim($remarks)==='')throw new RuntimeException('Remarks are required for this case status change.');
            return;
        }
        if(!in_array($to,self::TRANSITIONS[$from]??[],true))throw new RuntimeException('This ticket action is no longer allowed. Refresh the ticket.');
        if(in_array($to,['REJECTED','RETURNED'],true) && trim($remarks)==='')throw new RuntimeException('Remarks are required when returning or rejecting a ticket.');
    }
}
