<?php
declare(strict_types=1);

/**
 * Employee Status (the employment_types master) is what HR picks. The internal lifecycle status that payroll,
 * approvals, login and the directory read (employees.status) is derived from it here, in one place.
 */
final class EmployeeStatusPolicy
{
    private const INTERNAL=[
        'PROBATIONARY'=>'PROBATIONARY','RETURNEE_PROBATIONARY'=>'PROBATIONARY',
        'ON_LEAVE'=>'ON_LEAVE','MATERNITY_LEAVE'=>'ON_LEAVE',
        'RESIGN'=>'RESIGNED',
    ];
    private const PROBATION_CODES=['PROBATIONARY','RETURNEE_PROBATIONARY'];
    private static array $codes=[];

    public static function code(?int $typeId): ?string
    {
        if(!$typeId) return null;
        if(!array_key_exists($typeId,self::$codes)) {
            $q=db()->prepare('SELECT code FROM employment_types WHERE id=?'); $q->execute([$typeId]);
            $code=$q->fetchColumn(); self::$codes[$typeId]=$code===false?null:strtoupper((string)$code);
        }
        return self::$codes[$typeId];
    }

    public static function idForCode(string $code): ?int
    {
        $q=db()->prepare('SELECT id FROM employment_types WHERE code=?'); $q->execute([strtoupper($code)]);
        $id=$q->fetchColumn(); return $id===false?null:(int)$id;
    }

    /** Internal status for an Employee Status. Unknown or unassigned keeps the fallback (legacy rows), custom statuses are ACTIVE. */
    public static function internalStatus(?int $typeId,string $fallback='ACTIVE'): string
    {
        $code=self::code($typeId);
        if($code===null) return $fallback;
        return self::INTERNAL[$code] ?? 'ACTIVE';
    }

    public static function isProbation(?string $code): bool { return $code!==null && in_array($code,self::PROBATION_CODES,true); }

    public const PROBATION_MONTHS=6;
    public const SEASONAL_MONTHS=3;

    public static function isSeasonal(?string $code): bool { return $code==='SEASONAL'; }

    /** Statuses that run for a fixed term counted from the hire date: Probationary (6 months) and Seasonal (3 months). */
    public static function isTimed(?string $code): bool { return self::isProbation($code) || self::isSeasonal($code); }

    public static function termMonths(?string $code): int { return self::isSeasonal($code)?self::SEASONAL_MONTHS:self::PROBATION_MONTHS; }

    public static function termLabel(?string $code): string { return self::isSeasonal($code)?'Seasonal contract':'Probation'; }

    /** Same day N months later, clamped to the month's last day (Aug 31 + 6 months = Feb 28). */
    public static function addMonths(string $date,int $months): string
    {
        $d=new DateTimeImmutable($date); $first=$d->modify('first day of this month')->modify('+'.$months.' months');
        return $first->setDate((int)$first->format('Y'),(int)$first->format('n'),min((int)$d->format('j'),(int)$first->format('t')))->format('Y-m-d');
    }

    /** The term runs from the hire date (Returnee-Probationary: from the date HR sets it) and ends the day before the anniversary: 6 months for probation, 3 for Seasonal. */
    public static function probationDates(?string $typeCode,?string $hireDate,?string $effectiveDate): array
    {
        if(!self::isTimed($typeCode)) return [null,null];
        $start=$typeCode==='RETURNEE_PROBATIONARY'?$effectiveDate:$hireDate;
        if(!$start) return [null,null];
        $end=(new DateTimeImmutable(self::addMonths($start,self::termMonths($typeCode))))->modify('-1 day')->format('Y-m-d');
        return [$start,$end];
    }
}
