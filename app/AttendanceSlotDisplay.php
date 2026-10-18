<?php
declare(strict_types=1);

/**
 * Display-only slot placement for Check Attendance.
 *
 * Takes every saved punch of one duty day (whichever import or file it came from), sorts it by time and
 * places it in Time In, Break Out, Break In, Time Out in that order. Empty slots stay null ("Missing");
 * nothing is invented. This never feeds attendance_daily or payroll: PayrollCalculator::assign() still
 * owns the stored original_* values, late/undertime and the AMBIGUOUS_PUNCHES issue.
 */
final class AttendanceSlotDisplay
{
    /** Same keys as PayrollCalculator::FIELDS, kept here so this class stays dependency-free. */
    public const FIELDS = ['time_in','lunch_out','lunch_in','time_out'];
    private const EXPLICIT = ['TIME_IN'=>0,'LUNCH_OUT'=>1,'LUNCH_IN'=>2,'TIME_OUT'=>3];

    /**
     * @param array<int,array{id?:int|string,punched_at:string,punch_status?:string}> $punches
     * @return array{slots:array<string,?string>,extra:bool,count:int}
     */
    public static function assign(array $punches): array
    {
        $slots = array_fill_keys(self::FIELDS, null);
        $punches = array_values($punches);
        usort($punches, static fn(array $a, array $b): int => strcmp($a['punched_at'], $b['punched_at']) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0)));
        $count = count($punches);
        // More than four: flag it and leave placement to the person reviewing the full list.
        if ($count > 4) return ['slots'=>$slots, 'extra'=>true, 'count'=>$count];
        $placement = self::placement($punches);
        foreach ($punches as $n => $punch) $slots[self::FIELDS[$placement[$n]]] = $punch['punched_at'];
        return ['slots'=>$slots, 'extra'=>false, 'count'=>$count];
    }

    /** Slot index per punch: plain sequence, unless a device status names the slot and the order allows it. */
    private static function placement(array $punches): array
    {
        $sequence = array_keys($punches);
        $hasExplicit = false;
        foreach ($punches as $p) if (isset(self::EXPLICIT[strtoupper((string)($p['punch_status'] ?? ''))])) { $hasExplicit = true; break; }
        if (!$hasExplicit) return $sequence;
        $placement = []; $cursor = 0;
        foreach ($punches as $p) {
            $explicit = self::EXPLICIT[strtoupper((string)($p['punch_status'] ?? ''))] ?? null;
            if ($explicit !== null && $explicit < $cursor) return $sequence;
            $slot = $explicit ?? $cursor;
            if ($slot > 3) return $sequence;
            $placement[] = $slot; $cursor = $slot + 1;
        }
        return $placement;
    }
}
