<?php
declare(strict_types=1);

/**
 * Ocampo's Head Office directory sections (currently Ocampos Production; Goldsmith is a position under it).
 * A section belongs to one (company group, site type) scope; an employee can only hold a section while they are
 * in that scope. Directory display only: never changes departments, positions, payroll approvers or permissions.
 */
final class DirectorySectionService
{
    public const COMPANY_GROUP = 'OC';
    public const SITE_TYPE = 'HEAD_OFFICE';

    /**
     * Position -> section HINTS for the dry-run only (suggestions are never applied automatically).
     * Goldsmith is under Ocampos Production (confirmed by John). Production Admin and Stockcontrol/Production Staff follow the data.
     */
    private const POSITION_HINTS = [
        'OC_HO_OCAMPOS_PRODUCTION' => ['GOLDSMITH', 'PRODUCTION ADMIN', 'STOCKCONTROL/PRODUCTION STAFF'],
    ];

    public static function ready(): bool
    {
        return FoundationRepository::tableExists('directory_sections') && FoundationRepository::columnExists('employees', 'directory_section_id');
    }

    /** Active sections for one scope, plus $keepId (an employee's current section that was since retired). */
    public static function forScope(string $companyGroup, string $siteType, ?int $keepId = null): array
    {
        if (!self::ready()) return [];
        $st = db()->prepare('SELECT id,code,name,company_group,site_type,active FROM directory_sections WHERE company_group=? AND site_type=? AND (active=1 OR id=?) ORDER BY sort_order,name');
        $st->execute([strtoupper(trim($companyGroup)), strtoupper(trim($siteType)), (int)$keepId]);
        return $st->fetchAll();
    }

    /** Branch ids whose site type is Head Office (the form shows the Section field only for these). */
    public static function headOfficeBranchIds(): array
    {
        return array_map('intval', db()->query('SELECT id FROM branches WHERE site_type="'.self::SITE_TYPE.'"')->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Saves the Section chosen on the Employment tab. Runs after Company Group is resolved, inside the employee transaction.
     * Rejects a section outside its scope; clears the section when the employee leaves Ocampo's Head Office.
     */
    public static function save(int $employeeId, array $data): ?array
    {
        if (empty($data['directory_section_present']) || !self::ready()) return null;
        if (!db()->inTransaction()) throw new RuntimeException('Directory sections must be saved with the employee transaction.');
        $st = db()->prepare('SELECT e.directory_company_group,e.directory_section_id,b.site_type FROM employees e LEFT JOIN branches b ON b.id=e.branch_id WHERE e.id=?');
        $st->execute([$employeeId]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException('Employee not found.');
        $current = $row['directory_section_id'] === null ? null : (int)$row['directory_section_id'];
        $inScope = strtoupper((string)$row['directory_company_group']) === self::COMPANY_GROUP && strtoupper((string)$row['site_type']) === self::SITE_TYPE;
        $wanted = (int)($data['directory_section_id'] ?? 0);
        if ($wanted > 0) {
            $sec = db()->prepare('SELECT id,name,company_group,site_type,active FROM directory_sections WHERE id=?');
            $sec->execute([$wanted]);
            $section = $sec->fetch();
            if (!$section) throw new RuntimeException('Choose a valid section.');
            if (!$inScope || strtoupper($section['company_group']) !== strtoupper((string)$row['directory_company_group']) || strtoupper($section['site_type']) !== strtoupper((string)$row['site_type'])) {
                throw new RuntimeException('The section "'.$section['name'].'" is only for Ocampo\'s Head Office employees. Set Company Group to Ocampo\'s and Branch / Site to Head Office first.');
            }
            if (empty($section['active']) && $wanted !== $current) throw new RuntimeException('Choose an active section.');
        }
        $value = ($wanted > 0 && $inScope) ? $wanted : null;
        if ($value !== $current) db()->prepare('UPDATE employees SET directory_section_id=? WHERE id=?')->execute([$value, $employeeId]);
        return ['directory_section_id' => $value];
    }

    /**
     * Read-only dry-run: Ocampo's Head Office employees with no section yet and the section their position suggests.
     * Nothing is written. Returns ids and codes only (no names).
     */
    public static function suggestions(): array
    {
        if (!self::ready()) return [];
        $st = db()->prepare('SELECT e.id,e.employee_no,d.name department_name,p.name position_name
            FROM employees e JOIN branches b ON b.id=e.branch_id AND b.site_type=?
            LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN positions p ON p.id=e.position_id
            WHERE e.directory_company_group=? AND e.directory_section_id IS NULL ORDER BY e.id');
        $st->execute([self::SITE_TYPE, self::COMPANY_GROUP]);
        $names = [];
        foreach (self::forScope(self::COMPANY_GROUP, self::SITE_TYPE) as $s) $names[$s['code']] = $s['name'];
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $code = self::suggestCode((string)$r['position_name']);
            $rows[] = ['employee_id' => (int)$r['id'], 'employee_no' => $r['employee_no'], 'department' => $r['department_name'], 'position' => $r['position_name'],
                'suggested_code' => $code, 'suggested_section' => $code ? ($names[$code] ?? null) : null];
        }
        return $rows;
    }

    public static function suggestCode(string $positionName): ?string
    {
        $key = strtoupper(trim(preg_replace('/\s+/', ' ', str_replace("\xC2\xA0", ' ', $positionName))));
        foreach (self::POSITION_HINTS as $code => $positions) if (in_array($key, $positions, true)) return $code;
        return null;
    }
}
