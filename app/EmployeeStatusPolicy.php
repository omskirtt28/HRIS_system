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
}
