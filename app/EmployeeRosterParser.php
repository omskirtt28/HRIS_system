<?php
declare(strict_types=1);

/** Reads roster evidence only. No database writes, account creation or guessed dates. */
final class EmployeeRosterParser
{
    /** Columns of the Ocampo's list (one sheet, split names, Bio Code and Lee Code). Keys are headerKey() forms. */
    private const LEE_COLUMNS = ['EMPLOYEECODE','BIOCODE','LEECODE','LASTNAME','FIRSTNAME','MIDDLENAME','PAYROLLGROUP','COMPANYNAME','BRANCH','POSITION','STARTDATE'];

    /** Excel Company Name -> official Company Name master. Anything not listed is passed through as typed. */
    private const LEE_COMPANIES = [
        'OCAMPOSVENTURES'=>"Ocampo's Ventures, Inc.", 'CRYSSOBERYL'=>'Cryssoberyl Ventures, Inc.',
        'ALMANDITEVENTURES'=>'Almandite Ventures, Inc.', 'ARTCHICVENTURES'=>'Artchic Ventures OPC',
    ];

    /** Excel Position -> the one spelling HR wants (merges GOLD SMITH/GOLDSMITH, VIP SPECIALIST(S), PROD-ADMIN sTAFF). */
    private const LEE_POSITIONS = [
        'VIPSPECIALIST'=>'VIP Specialist', 'VIPSPECIALISTS'=>'VIP Specialist', 'GOLDSMITH'=>'Goldsmith',
        'PRODADMINSTAFF'=>'Production Admin', 'ACCOUNTINGSTAFF'=>'Accounting Staff', 'AREADEVELOPMENTLEADER'=>'Area Development Leader',
        'SALESCAPTAIN'=>'Sales Captain', 'OFFICERINCHARGE'=>'Officer In Charge', 'COMPANYDRIVER'=>'Company Driver',
        'HUMANRESOURCESASSISTANT'=>'Human Resources Assistant', 'STOCKCONTROLPRODUCTIONSTAFF'=>'Stockcontrol/Production Staff',
    ];

    /**
     * Position -> Department. The Ocampo's list has no Department column, so this follows how the positions are used.
     * ASSUMED (HR to confirm in Employee 201): Company Driver, Human Resources Assistant, Stockcontrol/Production Staff.
     * A position that is not listed gets no department and shows as Needs Details.
     */
    private const LEE_DEPARTMENTS = [
        'VIP Specialist'=>'Retail', 'Sales Captain'=>'Retail', 'Officer In Charge'=>'Retail',
        'Accounting Staff'=>'Accounting', 'Area Development Leader'=>'Retail & Development', 'Production Admin'=>'Production',
        'Goldsmith'=>'Production',
        'Company Driver'=>'Admin', 'Human Resources Assistant'=>'Admin', 'Stockcontrol/Production Staff'=>'Stock Control',
    ];

    public static function parse(string $path): array
    {
        if (!class_exists(ZipArchive::class) || !function_exists('simplexml_load_string')) {
            throw new RuntimeException('Enable the PHP zip and SimpleXML extensions to read Excel files.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Choose a valid .xlsx workbook.');
        try {
            if ($zip->numFiles > 2000) throw new RuntimeException('This workbook has too many parts. Save a smaller copy.');
            $bytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $bytes += (int)($zip->statIndex($i)['size'] ?? 0);
                if ($bytes > 64 * 1024 * 1024) throw new RuntimeException('The workbook is too large after opening. Split it into smaller files.');
            }
            $workbook = self::xml($zip, 'xl/workbook.xml');
            $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
            $targets = [];
            foreach ($rels->xpath('//*[local-name()="Relationship"]') ?: [] as $rel) {
                if (strtolower((string)$rel['TargetMode']) === 'external') continue;
                $target = str_replace('\\', '/', (string)$rel['Target']);
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                if (!preg_match('~^xl/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.xml$~D', $target) || str_contains($target, '..')) continue;
                $targets[(string)$rel['Id']] = $target;
            }
            $shared = [];
            $strings = self::xml($zip, 'xl/sharedStrings.xml', false);
            if ($strings) foreach ($strings->xpath('/*/*[local-name()="si"]') ?: [] as $si) {
                $shared[] = self::textParts($si);
            }
            $formats = [];
            $styles = self::xml($zip, 'xl/styles.xml', false);
            if ($styles) {
                $custom = [];
                foreach ($styles->xpath('//*[local-name()="numFmt"]') ?: [] as $f) $custom[(int)$f['numFmtId']] = (string)$f['formatCode'];
                foreach ($styles->xpath('//*[local-name()="cellXfs"]/*[local-name()="xf"]') ?: [] as $f) $formats[] = $custom[(int)$f['numFmtId']] ?? '';
            }
            $props = $workbook->xpath('//*[local-name()="workbookPr"]');
            $date1904 = $props && in_array(strtolower((string)$props[0]['date1904']), ['1','true'], true);
            $rows = []; $sheets = [];
            $sheetNames = array_map(static fn($s)=>strtoupper(trim((string)$s['name'])), $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: []);
            // The Retail/HO layout wins. Only a workbook without those sheets is read as the Ocampo's list.
            $hasRosterSheets = (bool)array_intersect($sheetNames, ['RETAIL','HO']);
            foreach ($workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [] as $sheet) {
                $group = strtoupper(trim((string)$sheet['name']));
                $isRoster = in_array($group, ['RETAIL','HO'], true);
                if (!$isRoster && $hasRosterSheets) continue;
                if (isset($sheets[$group])) throw new RuntimeException('Keep one Retail sheet and one HO sheet in the workbook.');
                $rid = '';
                foreach ($sheet->getNamespaces(true) as $ns) {
                    if (str_ends_with($ns, '/relationships')) $rid = (string)$sheet->attributes($ns)['id'];
                }
                if (!isset($targets[$rid])) throw new RuntimeException('The '.$group.' sheet cannot be opened. Save the file as .xlsx again.');
                $xml = self::xml($zip, $targets[$rid]);
                $headers = null; $physical = 0; $count = 0;
                foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
                    if (++$physical > 10000) throw new RuntimeException('Use at most 10,000 rows per worksheet.');
                    $cells = []; $formulas = [];
                    foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                        if (!preg_match('/^([A-Z]{1,3})[0-9]+$/D', (string)$cell['r'], $m)) continue;
                        $index = 0;
                        foreach (str_split($m[1]) as $letter) $index = $index * 26 + ord($letter) - 64;
                        if ($index > 64) continue;
                        $v = $cell->xpath('./*[local-name()="v"]');
                        $value = (string)($v[0] ?? '');
                        if ((string)$cell['t'] === 's') {
                            if (!ctype_digit($value) || !array_key_exists((int)$value, $shared)) throw new RuntimeException('A workbook text value cannot be read. Save the file again.');
                            $value = $shared[(int)$value];
                        } elseif ((string)$cell['t'] === 'inlineStr') $value = self::textParts($cell);
                        elseif (is_numeric($value) && preg_match('/^0+$/D', $formats[(int)$cell['s']] ?? '')) {
                            $value = str_pad((string)(int)$value, strlen($formats[(int)$cell['s']]), '0', STR_PAD_LEFT);
                        }
                        $cells[$index] = self::clean($value);
                        if ($cell->xpath('./*[local-name()="f"]')) $formulas[$index] = true;
                    }
                    if ($headers === null) {
                        $keys = array_map([self::class, 'headerKey'], $cells);
                        if (!in_array('EMPLOYEECODE', $keys, true) || !in_array($isRoster ? 'NAME' : 'LEECODE', $keys, true)) continue;
                        $required = !$isRoster ? self::LEE_COLUMNS : ($group === 'RETAIL' ? ['EMPLOYEECODE','NAME','ADDRESSOFMALL','POSITION','BRANCHCODE','COMPANY','STARTDATE'] : ['EMPLOYEECODE','NAME','DEPARTMENT','POSITION','COMPANY','STARTDATE']);
                        foreach ($required as $key) if (count(array_keys($keys,$key,true)) > 1) throw new RuntimeException($group.' has more than one '.$key.' column. Keep one copy of each roster column.');
                        $headers = array_flip($keys);
                        foreach ($required as $key) if (!isset($headers[$key])) throw new RuntimeException($group.' is missing the '.$key.' column.');
                        continue;
                    }
                    if (!$isRoster) {
                        $parsed = self::leeRow($cells, $headers, $formulas, (int)$row['r'], $date1904, $group);
                        if ($parsed === null) continue;
                        $rows[] = $parsed; $count++;
                        if (count($rows) > 5000) throw new RuntimeException('Upload at most 5,000 employees at a time.');
                        continue;
                    }
                    $raw = []; $formulaFields = [];
                    foreach (['EMPLOYEECODE','NAME','ADDRESSOFMALL','DEPARTMENT','POSITION','BRANCHCODE','COMPANY','STARTDATE'] as $key) {
                        $col = $headers[$key] ?? 0;
                        $raw[$key] = $cells[$col] ?? '';
                        if (isset($formulas[$col])) $formulaFields[] = $key;
                        if (mb_strlen($raw[$key]) > 500) throw new RuntimeException($group.' row '.(string)$row['r'].' contains a very long value.');
                    }
                    if (!array_filter($raw, static fn($v) => $v !== '')) continue;
                    $code = strtoupper($raw['EMPLOYEECODE']);
                    if (preg_match('/^([0-9]+)\.0+$/D', $code, $m)) $code = $m[1];
                    [$first,$middle,$last,$suffix] = self::nameParts($raw['NAME']);
                    $rows[] = [
                        'source_sheet'=>$group,'source_row'=>(int)$row['r'], 'raw'=>$raw,
                        'employee_no'=>$code,'first_name'=>$first,'middle_name'=>$middle,'last_name'=>$last,'suffix'=>$suffix,
                        'hire_date'=>self::date($raw['STARTDATE'], $date1904), 'formula_fields'=>$formulaFields,
                        'department_source'=>$group === 'RETAIL' ? 'Retail' : $raw['DEPARTMENT'],
                        'site_source'=>$group === 'RETAIL' ? $raw['ADDRESSOFMALL'] : 'Head Office',
                        'branch_code'=>$raw['BRANCHCODE'],'position_source'=>$raw['POSITION'],'company_source'=>$raw['COMPANY'],
                        'name_checked'=>substr_count($raw['NAME'], ',') === 1, 'assignment_checked'=>false,
                    ];
                    $count++;
                    if (count($rows) > 5000) throw new RuntimeException('Upload at most 5,000 employees at a time.');
                }
                if ($headers === null) {
                    if (!$isRoster) continue; // another sheet in an Ocampo's workbook
                    throw new RuntimeException('No employee header was found in '.$group.'.');
                }
                $sheets[$group] = $count;
            }
            if (!$rows) throw new RuntimeException('No employees found. Use sheets named Retail and/or HO with the roster headers, or the Ocampo\'s list with Employee Code, Bio Code and Lee Code columns.');
            return ['rows'=>$rows,'sheets'=>$sheets];
        } finally { $zip->close(); }
    }

    /** Trims spaces AND non-breaking spaces (U+00A0), which trim() leaves behind. */
    public static function clean(string $value): string
    {
        return preg_replace('/^[\s\xC2\xA0]+|[\s\xC2\xA0]+$/u', '', $value) ?? '';
    }

    /** One row of the Ocampo's list. Employee Code is kept as the original code; the Bio Code becomes the Employee Code. */
    private static function leeRow(array $cells, array $headers, array $formulas, int $line, bool $date1904, string $sheet): ?array
    {
        $raw = []; $formulaFields = [];
        foreach (self::LEE_COLUMNS as $key) {
            $col = $headers[$key];
            $raw[$key] = $cells[$col] ?? '';
            if (isset($formulas[$col])) $formulaFields[] = $key;
            if (mb_strlen($raw[$key]) > 500) throw new RuntimeException($sheet.' row '.$line.' contains a very long value.');
        }
        if (!array_filter($raw, static fn($v) => $v !== '')) return null;
        $bio = strtoupper($raw['BIOCODE']);
        if (preg_match('/^([0-9]+)\.0+$/D', $bio, $m)) $bio = $m[1];
        $head = self::headerKey($raw['BRANCH']) === 'HEADOFFICE';
        $position = self::LEE_POSITIONS[self::headerKey($raw['POSITION'])] ?? $raw['POSITION'];
        $raw['NAME'] = trim($raw['LASTNAME'].', '.trim($raw['FIRSTNAME'].' '.$raw['MIDDLENAME']));
        return [
            // Branch rows behave like the Retail sheet and Head Office rows like the HO sheet (payroll export groups by this).
            'source_sheet'=>$head ? 'HO' : 'RETAIL', 'source_row'=>$line, 'raw'=>$raw,
            'format'=>'LEE', 'company_kind'=>'brand',
            'employee_no'=>$bio, 'original_code'=>strtoupper($raw['EMPLOYEECODE']),
            'lee_code'=>$raw['LEECODE'], 'payroll_group'=>$raw['PAYROLLGROUP'],
            'first_name'=>$raw['FIRSTNAME'], 'middle_name'=>$raw['MIDDLENAME'], 'last_name'=>$raw['LASTNAME'], 'suffix'=>'',
            'hire_date'=>self::date($raw['STARTDATE'], $date1904), 'formula_fields'=>$formulaFields,
            'department_source'=>self::LEE_DEPARTMENTS[$position] ?? '',
            'site_source'=>$head ? 'Head Office' : $raw['BRANCH'], 'branch_code'=>$head ? '' : $raw['BRANCH'],
            'position_source'=>$position,
            'company_source'=>self::LEE_COMPANIES[self::headerKey($raw['COMPANYNAME'])] ?? $raw['COMPANYNAME'],
            'name_checked'=>true, 'assignment_checked'=>false,
        ];
    }

    public static function date(string $value, bool $date1904 = false): string
    {
        $value = self::clean($value);
        if ($value === '') return '';
        if (is_numeric($value)) {
            $serial = (float)$value;
            if ($serial < 1 || $serial > 73050 || (!$date1904 && (int)floor($serial) === 60)) return '';
            $base = new DateTimeImmutable($date1904 ? '1904-01-01' : ($serial < 60 ? '1899-12-31' : '1899-12-30'));
            $value = $base->modify('+'.(int)floor($serial).' days')->format('Y-m-d');
        }
        $value = preg_replace('/\s+/', ' ', $value);
        foreach (['!Y-m-d','!m/d/Y','!n/j/Y','!F j, Y','!M j, Y','!F j Y','!M j Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || (!$errors['warning_count'] && !$errors['error_count'])) && (int)$date->format('Y') >= 1900 && (int)$date->format('Y') <= 2100) return $date->format('Y-m-d');
        }
        return '';
    }

    private static function nameParts(string $name): array
    {
        if (!str_contains($name, ',')) return ['', '', '', ''];
        [$last,$given] = explode(',', $name, 2);
        $given = trim(preg_replace('/\s+/', ' ', $given)); $suffix = ''; $middle = '';
        if (preg_match('/\s+(JR\.?|SR\.?|II|III|IV)$/i', $given, $m)) { $suffix = $m[1]; $given = trim(substr($given, 0, -strlen($m[0]))); }
        if (preg_match('/\s+([A-Z]\.)$/i', $given, $m)) { $middle = $m[1]; $given = trim(substr($given, 0, -strlen($m[0]))); }
        return [$given,$middle,trim($last),$suffix];
    }

    private static function headerKey(string $value): string { return preg_replace('/[^A-Z0-9]/', '', strtoupper($value)); }
    private static function textParts(SimpleXMLElement $x): string
    {
        return implode('', array_map('strval', $x->xpath('.//*[local-name()="t" and not(ancestor::*[local-name()="rPh"])]') ?: []));
    }
    private static function xml(ZipArchive $zip, string $path, bool $required = true): ?SimpleXMLElement
    {
        $text = $zip->getFromName($path);
        if ($text === false) { if (!$required) return null; throw new RuntimeException('A required workbook part is missing.'); }
        if (stripos($text, '<!DOCTYPE') !== false || stripos($text, '<!ENTITY') !== false) throw new RuntimeException('This workbook contains unsupported XML.');
        $old = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET);
            if (!$xml) throw new RuntimeException('A workbook part cannot be read. Save the file as .xlsx again.');
            return $xml;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    }
}
