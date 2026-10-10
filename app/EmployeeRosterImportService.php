<?php
declare(strict_types=1);

final class EmployeeRosterImportService
{
    private const TABLES = ['department'=>'departments','position'=>'positions','site'=>'branches','brand'=>'business_units','employer'=>'legal_entities'];

    public static function authorize(): void
    {
        Auth::requirePermission('employees.import');
        Auth::requirePermission('employees.manage');
        Auth::requirePermission('employees.view_all');
    }

    public static function ready(): bool
    {
        foreach (['employee_roster_imports','employee_roster_rows','business_units','legal_entities'] as $table) if (!FoundationRepository::tableExists($table)) return false;
        foreach (['business_unit_id','legal_entity_id','roster_full_name','roster_group','roster_needs_details','roster_pending_json','roster_original_code','roster_source_key'] as $column) if (!FoundationRepository::columnExists('employees', $column)) return false;
        return EmployeeRepository::ready();
    }

    private static function requireReady(): void
    {
        self::authorize();
        if (!self::ready()) throw new RuntimeException('Import the Employee Roster SQL and the Follow-up Details SQL patch, then reload.');
    }

    public static function stage(array $file): int
    {
        self::requireReady();
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('The upload failed. Choose the Excel file again, or check the PHP upload limit.');
        $path = (string)($file['tmp_name'] ?? '');
        if (!is_uploaded_file($path)) throw new RuntimeException('Choose an Excel file to upload.');
        if ((int)($file['size'] ?? 0) > 20 * 1024 * 1024 || filesize($path) > 20 * 1024 * 1024) throw new RuntimeException('Choose a file that is 20 MB or smaller.');
        $name = basename(str_replace('\\', '/', (string)($file['name'] ?? '')));
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('Use an .xlsx file with Retail and/or HO sheets.');
        $parsed = EmployeeRosterParser::parse($path);
        $sources = self::sources($parsed['rows']);
        $fileHash = hash_file('sha256',$path);
        $catalog = self::catalog($parsed['rows'],false,$fileHash);
        $map = [];
        foreach ($sources as $key=>$source) $map[$key] = self::suggest($source, $catalog);
        db()->beginTransaction();
        try {
            $st = db()->prepare('INSERT INTO employee_roster_imports(original_name,file_sha256,row_count,mapping_json,created_by) VALUES(?,?,?,?,?)');
            $st->execute([mb_substr($name,0,255),$fileHash,count($parsed['rows']),self::json($map),(int)Auth::user()['id']]);
            $id = (int)db()->lastInsertId();
            $st = db()->prepare('INSERT INTO employee_roster_rows(import_id,source_sheet,source_row,employee_code,data_json) VALUES(?,?,?,?,?)');
            foreach ($parsed['rows'] as $row) $st->execute([$id,$row['source_sheet'],$row['source_row'],mb_substr($row['employee_no'],0,50) ?: null,self::json($row)]);
            audit('Employees','UPLOAD_ROSTER','employee_roster_import',$id,['rows'=>count($parsed['rows']),'sheets'=>$parsed['sheets']]);
            db()->commit();
            return $id;
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function history(): array
    {
        self::requireReady();
        $st = db()->prepare('SELECT i.*, (SELECT COUNT(*) FROM employee_roster_rows r WHERE r.import_id=i.id AND r.imported_at IS NOT NULL) imported_count FROM employee_roster_imports i WHERE i.created_by=? ORDER BY i.id DESC LIMIT 30');
        $st->execute([(int)Auth::user()['id']]);
        return $st->fetchAll();
    }

    private static function load(int $id, bool $lock = false): array
    {
        self::requireReady();
        $st = db()->prepare('SELECT * FROM employee_roster_imports WHERE id=? AND created_by=?'.($lock ? ' FOR UPDATE' : ''));
        $st->execute([$id,(int)Auth::user()['id']]);
        $batch = $st->fetch();
        if (!$batch) throw new RuntimeException('This roster upload was not found in your import history.');
        $st = db()->prepare('SELECT * FROM employee_roster_rows WHERE import_id=? ORDER BY id'.($lock ? ' FOR UPDATE' : ''));
        $st->execute([$id]); $rows = [];
        foreach ($st->fetchAll() as $row) {
            $row['data'] = json_decode($row['data_json'],true,64,JSON_THROW_ON_ERROR);
            $rows[] = $row;
        }
        $batch['mapping'] = json_decode($batch['mapping_json'],true,64,JSON_THROW_ON_ERROR);
        $batch['rows'] = $rows;
        return $batch;
    }

    public static function review(int $id): array
    {
        $batch = self::load($id);
        $data = array_column($batch['rows'],'data');
        $catalog = self::catalogForBatch($batch);
        $batch['sources'] = self::sources($data);
        // Older previews may have blank matches. Fill them without changing saved HR overrides.
        foreach ($batch['sources'] as $key=>$source) {
            if (($batch['mapping'][$key] ?? '') === '') $batch['mapping'][$key] = self::suggest($source,$catalog);
        }
        $batch['catalog'] = $catalog;
        $batch['review'] = self::evaluate($batch, $catalog);
        return $batch;
    }

    public static function key(string $kind, string $source, string $context = ''): string
    {
        return $kind.'_'.substr(hash('sha256', $context."\0".$source),0,24);
    }

    public static function sources(array $rows): array
    {
        $sources = [];
        foreach ($rows as $d) {
            foreach (self::rowSources($d) as $kind=>$s) {
                $key = self::key($kind,$s['name'],$s['context']);
                if (!isset($sources[$key])) $sources[$key] = ['kind'=>$kind,'name'=>$s['name'],'context'=>$s['context'],'code'=>$s['code'] ?? '', 'count'=>0];
                $sources[$key]['count']++;
            }
        }
        return $sources;
    }

    private static function rowSources(array $d): array
    {
        $companyKind = $d['source_sheet'] === 'RETAIL' ? 'brand' : 'employer';
        return [
            'department'=>['name'=>$d['department_source'],'context'=>''],
            'position'=>['name'=>$d['position_source'],'context'=>$d['department_source']],
            'site'=>['name'=>$d['site_source'],'context'=>$d['branch_code'],'code'=>$d['branch_code']],
            $companyKind=>['name'=>$d['company_source'],'context'=>''],
        ];
    }

    private static function sourceKey(string $fileHash, array $data): string
    {
        return $fileHash === '' ? '' : hash('sha256',$fileHash.'|'.$data['source_sheet'].'|'.$data['source_row']);
    }

    private static function catalogForBatch(array $batch, bool $lock = false): array
    {
        return self::catalog(array_column($batch['rows'],'data'),$lock,$batch['file_sha256'],array_column($batch['rows'],'employee_id'));
    }

    private static function catalog(array $data, bool $lock = false, string $fileHash = '', array $employeeIds = []): array
    {
        $result = [];
        foreach (self::TABLES as $kind=>$table) {
            $result[$kind] = [];
            // Table names come from the fixed allowlist above.
            foreach (db()->query('SELECT * FROM '.$table.' ORDER BY id'.($lock ? ' FOR UPDATE' : ''))->fetchAll() as $r) $result[$kind][(int)$r['id']] = $r;
        }
        $codes = array_values(array_unique(array_filter(array_map(static fn($d)=>strtoupper(trim($d['employee_no'])), $data))));
        $sourceKeys = array_values(array_unique(array_filter(array_map(static fn($d)=>self::sourceKey($fileHash,$d),$data))));
        $result['employees'] = []; $result['by_id'] = []; $result['profiles'] = [];
        foreach (['employee_no'=>$codes,'id'=>array_values(array_unique(array_filter($employeeIds))),'roster_source_key'=>$sourceKeys] as $column=>$values) foreach (array_chunk($values,500) as $chunk) {
            $st = db()->prepare('SELECT * FROM employees WHERE '.$column.' IN ('.implode(',',array_fill(0,count($chunk),'?')).') ORDER BY id'.($lock ? ' FOR UPDATE' : ''));
            $st->execute($chunk);
            foreach ($st->fetchAll() as $e) {
                if (!empty($e['employee_no'])) $result['employees'][strtoupper($e['employee_no'])] = $e;
                $result['by_id'][(int)$e['id']] = $e;
                if (!empty($e['roster_source_key'])) $result['profiles'][$e['roster_source_key']] = $e;
            }
        }
        return $result;
    }

    private static function normalize(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u','',mb_strtoupper($value));
    }

    private static function suggest(array $s, array $c): string
    {
        $name = trim($s['name']);
        if ($name === '' || mb_strlen($name) > ($s['kind'] === 'department' ? 120 : 160)) return '';
        $needle = self::normalize($name);
        if ($needle === '') return '';
        $matches = []; $known = false;
        if ($s['kind'] === 'site' && $s['code'] !== '') {
            foreach ($c['site'] as $r) if (strcasecmp((string)$r['code'],$s['code']) === 0) {
                $known = true;
                if ($r['active']) $matches[] = $r['id'];
            }
            if ($known) return count($matches) === 1 ? (string)$matches[0] : '';
            // An existing mall with another code needs HR review, rather than a second branch.
            foreach ($c['site'] as $r) if (self::normalize($r['name']) === $needle) return '';
            return self::canAddSource($s) ? 'NEW' : '';
        }
        if ($s['kind'] === 'department') {
            $plain = preg_replace('/^(HO|SW)\s+/i','',$s['name']);
            $plain = preg_replace('/\s+(DEPT\.?|DEPARTMENT)$/i','',$plain);
            $aliases = ['IT'=>'MIS / IT','PMMD-SANGIMIGNANO'=>'PMMD','DIGITAL MKTG'=>'Digital Marketing'];
            $needle = self::normalize($aliases[strtoupper($plain)] ?? $plain);
        }
        $department = $s['kind'] === 'position' ? self::suggest(['kind'=>'department','name'=>$s['context'],'context'=>'','code'=>''],$c) : '';
        if ($s['kind'] === 'position' && $department === '') return '';
        foreach ($c[$s['kind']] as $r) {
            if (self::normalize($r['name']) !== $needle) continue;
            if ($s['kind'] === 'position' && !empty($r['department_id'])) {
                if ((string)$r['department_id'] !== $department) continue;
            }
            $known = true;
            if ($r['active']) $matches[] = $r['id'];
        }
        if ($known) return count($matches) === 1 ? (string)$matches[0] : '';
        return self::canAddSource($s) ? 'NEW' : '';
    }

    private static function canAddSource(array $source): bool
    {
        $name = trim($source['name']);
        if ($name === '' || self::normalize($name) === '' || mb_strlen($name) > ($source['kind'] === 'department' ? 120 : 160)) return false;
        if ($source['kind'] === 'site') return preg_match('/^[A-Z0-9_-]{1,30}$/Di',trim((string)($source['code'] ?? ''))) === 1;
        return in_array($source['kind'],['department','position','brand','employer'],true);
    }

    public static function choices(array $source, array $catalog): array
    {
        $choices = [''=>'Choose a match'];
        foreach ($catalog[$source['kind']] as $id=>$r) {
            if (!$r['active']) continue;
            $label = $r['name'];
            if ($source['kind'] === 'site') $label .= ' · '.($r['code'] ?: 'No branch code');
            if ($source['kind'] === 'position' && !empty($r['department_id'])) $label .= ' · '.($catalog['department'][(int)$r['department_id']]['name'] ?? '');
            $choices[(string)$id] = $label;
        }
        if (self::canAddSource($source)) $choices['NEW'] = 'Add from Excel: '.$source['name'].($source['kind'] === 'site' ? ' · '.$source['code'] : '');
        return $choices;
    }

    public static function saveMapping(int $id, int $version, array $input): void
    {
        db()->beginTransaction();
        try {
            $batch = self::load($id,true); self::version($batch,$version);
            $data = array_column($batch['rows'],'data'); $catalog = self::catalogForBatch($batch);
            $map = [];
            foreach (self::sources($data) as $key=>$source) {
                if (!array_key_exists($key,$input)) throw new RuntimeException('Some matches were not sent. Reload and try again, or ask your administrator to raise max_input_vars for this workbook.');
                $value = trim((string)$input[$key]);
                if (!array_key_exists($value,self::choices($source,$catalog))) throw new RuntimeException('One selected match is no longer available. Reload and choose it again.');
                $map[$key] = $value;
            }
            $st = db()->prepare('UPDATE employee_roster_imports SET mapping_json=?,version=version+1 WHERE id=?'); $st->execute([self::json($map),$id]);
            audit('Employees','MATCH_ROSTER_FIELDS','employee_roster_import',$id,['groups'=>count($map)]);
            db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function saveRow(int $id, int $rowId, int $version, array $input): void
    {
        db()->beginTransaction();
        try {
            $batch = self::load($id,true); self::version($batch,$version); $row = null;
            foreach ($batch['rows'] as $r) if ((int)$r['id'] === $rowId) $row = $r;
            if (!$row || $row['imported_at']) throw new RuntimeException('This row is unavailable or has already been imported.');
            $d = $row['data']; $action = (string)($input['row_action'] ?? 'AUTO');
            if (!in_array($action,['AUTO','UPDATE','IGNORE'],true)) throw new RuntimeException('Choose a valid row action.');
            if ($action === 'UPDATE' && empty($input['same_person'])) throw new RuntimeException('Confirm that this Employee Code belongs to the same person before choosing Update.');
            foreach (['employee_no'=>50,'first_name'=>100,'middle_name'=>100,'last_name'=>100,'suffix'=>30] as $field=>$max) {
                $v = trim((string)($input[$field] ?? ''));
                if (mb_strlen($v) > $max) throw new RuntimeException('The '.$field.' value is too long.');
                $d[$field] = $field === 'employee_no' ? strtoupper($v) : $v;
            }
            $v = trim((string)($input['hire_date'] ?? ''));
            $d['hire_date'] = EmployeeRosterParser::date($v);
            if ($v !== '' && $d['hire_date'] === '') throw new RuntimeException('Enter a valid Start Date.');
            $catalog = self::catalog([$d],false,$batch['file_sha256']);
            foreach (['department','position','site','brand','employer'] as $kind) {
                $v = trim((string)($input['override_'.$kind] ?? ''));
                if ($v !== '' && (!ctype_digit($v) || !isset($catalog[$kind][(int)$v]) || !$catalog[$kind][(int)$v]['active'])) throw new RuntimeException('One selected assignment is not available.');
                $d['override_'.$kind] = $v;
            }
            $d['name_checked'] = true;
            $d['assignment_checked'] = !empty($input['assignment_checked']);
            // A formula cannot silently become evidence; HR must confirm the typed details.
            $d['formula_checked'] = !empty($input['formula_checked']);
            $d['update_checked'] = $action === 'UPDATE' && !empty($input['same_person']);
            $st = db()->prepare('UPDATE employee_roster_rows SET employee_code=?,data_json=?,action=? WHERE id=? AND import_id=?');
            $st->execute([$d['employee_no'] ?: null,self::json($d),$action,$rowId,$id]);
            db()->prepare('UPDATE employee_roster_imports SET version=version+1 WHERE id=?')->execute([$id]);
            audit('Employees','REVIEW_ROSTER_ROW','employee_roster_import',$id,['row_id'=>$rowId,'action'=>$action]);
            db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    private static function resolve(string $kind, array $d, array $map, array $catalog): array
    {
        $source = self::rowSources($d)[$kind] ?? null;
        $choice = (string)($d['override_'.$kind] ?? '');
        $key = $source ? self::key($kind,$source['name'],$source['context']) : '';
        if ($choice === '' && $source) $choice = (string)($map[$key] ?? '');
        if ($choice === '' && $source) $choice = self::suggest(['kind'=>$kind]+$source,$catalog);
        if ($choice === 'NEW' && $source && self::canAddSource(['kind'=>$kind]+$source)) {
            return ['choice'=>'NEW','id'=>null,'name'=>$source['name'],'key'=>$key,'code'=>$source['code'] ?? ''];
        } elseif (ctype_digit($choice) && isset($catalog[$kind][(int)$choice]) && $catalog[$kind][(int)$choice]['active']) {
            $r = $catalog[$kind][(int)$choice];
            return ['choice'=>$choice,'id'=>(int)$choice,'name'=>$r['name'],'key'=>$key,'record'=>$r];
        }
        return ['choice'=>'','id'=>null,'name'=>'Not matched','key'=>$key];
    }

    private static function evaluate(array $batch, array $catalog): array
    {
        $counts = ['READY'=>0,'FOLLOWUP'=>0,'CHECK'=>0,'EXISTS'=>0,'IMPORTED'=>0,'DETAILS'=>0,'IGNORED'=>0]; $codes = []; $out = [];
        foreach ($batch['rows'] as $r) {
            $code = strtoupper(trim($r['data']['employee_no']));
            if ($code !== '' && $r['action'] !== 'IGNORE') $codes[$code][] = $r['source_sheet'].' row '.$r['source_row'];
        }
        foreach ($batch['rows'] as $r) {
            $d = $r['data']; $errors = []; $pending = []; $identityHeld = false;
            $sourceKey = self::sourceKey($batch['file_sha256'],$d);
            $existing = $r['employee_id'] ? ($catalog['by_id'][(int)$r['employee_id']] ?? null) : ($catalog['profiles'][$sourceKey] ?? ($catalog['employees'][strtoupper($d['employee_no'])] ?? null));
            $assignment = [];
            foreach (array_keys(self::TABLES) as $kind) $assignment[$kind] = self::resolve($kind,$d,$batch['mapping'],$catalog);
            $state = 'READY';
            if ($r['imported_at']) {
                $state = 'IMPORTED';
                if ($existing) {
                    $pending = EmployeeRepository::rosterIssues($existing);
                    $errors = array_values($pending);
                    foreach (['employee_no','first_name','middle_name','last_name','suffix','hire_date'] as $field) $d[$field] = (string)($existing[$field] ?? '');
                    foreach (['department'=>'department_id','position'=>'position_id','site'=>'branch_id','brand'=>'business_unit_id','employer'=>'legal_entity_id'] as $kind=>$field) {
                        $record = $catalog[$kind][(int)($existing[$field] ?? 0)] ?? null;
                        $assignment[$kind] = $record ? ['choice'=>(string)$record['id'],'id'=>(int)$record['id'],'name'=>$record['name'],'key'=>'','record'=>$record] : ['choice'=>'','id'=>null,'name'=>'Not set','key'=>''];
                    }
                }
            }
            elseif ($r['action'] === 'IGNORE') $state = 'IGNORED';
            elseif ($existing && $r['action'] !== 'UPDATE') $state = 'EXISTS';
            else {
                if (!preg_match('/^[A-Z0-9_-]{1,50}$/D',$d['employee_no'])) {
                    $identityHeld = true; $pending['employee_no'] = 'Enter the real Employee Code in Employment. The Excel code is missing or invalid.';
                } elseif (count($codes[$d['employee_no']] ?? []) > 1) {
                    $identityHeld = true; $pending['employee_no'] = 'Check Excel code '.$d['employee_no'].': '.implode(', ',$codes[$d['employee_no']]).'. The code is held until HR confirms the correct profile.';
                }
                foreach (['first_name'=>100,'middle_name'=>100,'last_name'=>100,'suffix'=>30] as $f=>$max) if (mb_strlen($d[$f]) > $max) $errors[] = 'The '.str_replace('_',' ',$f).' is too long.';
                if (!$d['name_checked'] || trim($d['first_name']) === '' || trim($d['last_name']) === '') $pending['name'] = 'Check First Name and Last Name in Personal Info. Original name: '.$d['raw']['NAME'];
                if (mb_strlen($d['raw']['NAME']) > 190) $errors[] = 'The source name is too long for Employee 201.';
                if (!$d['hire_date'] || EmployeeRosterParser::date($d['hire_date']) !== $d['hire_date']) {
                    $d['hire_date'] = ''; $pending['hire_date'] = 'Add the real Start Date in Employment. Excel value: '.($d['raw']['STARTDATE'] ?: 'blank');
                }
                if ($d['formula_fields'] && empty($d['formula_checked'])) $errors[] = 'This row has formula values. Check and confirm the typed details.';
                $companyKind = $d['source_sheet'] === 'RETAIL' ? 'brand' : 'employer';
                $positionDept = $assignment['position']['record']['department_id'] ?? null;
                if ($positionDept && (string)$positionDept !== (string)$assignment['department']['id']) {
                    $assignment['position'] = ['choice'=>'','id'=>null,'name'=>'Needs checking','key'=>''];
                }
                $siteLooksLikeDepartment = false;
                if ($d['source_sheet'] === 'HO') foreach ($catalog['site'] as $b) {
                    if (self::normalize($b['name']) === self::normalize($d['department_source'])) $siteLooksLikeDepartment = true;
                }
                if ($siteLooksLikeDepartment && (empty($d['assignment_checked']) || empty($d['override_site']) || empty($d['override_department']))) {
                    foreach (['site','department','position'] as $kind) $assignment[$kind] = ['choice'=>'','id'=>null,'name'=>'Needs checking','key'=>''];
                }
                foreach (['department'=>['department_id','Department',$d['department_source']],'position'=>['position_id','Position',$d['position_source']],'site'=>['branch_id','Branch / Site',$siteLooksLikeDepartment ? $d['department_source'] : $d['site_source']],$companyKind=>[$companyKind==='brand' ? 'business_unit_id' : 'legal_entity_id','Company',$d['company_source']]] as $kind=>[$field,$label,$source]) {
                    if ($assignment[$kind]['choice'] === '') $pending[$field] = 'Check '.$label.' in Employment. Excel value: '.($source ?: 'blank');
                }
                if ($r['action'] === 'UPDATE' && (!$existing || empty($d['update_checked']))) $errors[] = 'Update needs an existing Employee Code and confirmation that this is the same person.';
                if ($r['action'] === 'UPDATE' && ($identityHeld || empty($existing['employee_no']) || strcasecmp((string)$existing['employee_no'],$d['employee_no']) !== 0)) $errors[] = 'Resolve the Employee Code in Employee 201 before selecting an update.';
                $state = $errors ? 'CHECK' : ($pending ? 'FOLLOWUP' : 'READY');
                $errors = array_merge($errors,array_values($pending));
            }
            $r['data'] = $d; $r['state'] = $state; $r['errors'] = $errors; $r['pending'] = $pending; $r['identity_held'] = $identityHeld; $r['source_key'] = $sourceKey; $r['assignment'] = $assignment; $r['existing'] = $existing;
            $counts[$state]++; $out[] = $r;
            if ($state === 'IMPORTED' && $pending) $counts['DETAILS']++;
        }
        $digestRows = [];
        foreach ($out as $r) $digestRows[] = [$r['id'],$r['action'],$r['state'],$r['data'],$r['assignment'],$r['existing'],$r['pending'],$r['identity_held']];
        return ['rows'=>$out,'counts'=>$counts,'digest'=>hash('sha256',self::json([$batch['version'],$digestRows]))];
    }

    /** The confirmation token binds the posted action to the full server-reviewed preview. */
    public static function token(array $batch): string
    {
        $token = bin2hex(random_bytes(24));
        $_SESSION['roster_reviews'][(int)$batch['id']] = ['token'=>$token,'version'=>(int)$batch['version'],'digest'=>$batch['review']['digest'],'expires'=>time()+3600];
        if (count($_SESSION['roster_reviews']) > 10) unset($_SESSION['roster_reviews'][array_key_first($_SESSION['roster_reviews'])]);
        return $token;
    }

    public static function commit(int $id, int $version, string $token, bool $confirmed, bool $updates): array
    {
        self::requireReady();
        if (!$confirmed) throw new RuntimeException('Confirm that you reviewed the roster and its follow-up details.');
        $proof = $_SESSION['roster_reviews'][$id] ?? [];
        if (!$proof || !hash_equals($proof['token'],$token) || $proof['expires'] < time() || $proof['version'] !== $version) throw new RuntimeException('The preview expired or changed. Reload it before importing.');
        db()->beginTransaction();
        try {
            $batch = self::load($id,true); self::version($batch,$version);
            $catalog = self::catalogForBatch($batch,true);
            $review = self::evaluate($batch,$catalog);
            if (!hash_equals($proof['digest'],$review['digest'])) throw new RuntimeException('Employee details or matches changed since this preview. Reload and review them again.');
            $selected = array_values(array_filter($review['rows'],static fn($r)=>in_array($r['state'],['READY','FOLLOWUP'],true) && ($r['action'] !== 'UPDATE' || $updates)));
            if (!$selected) throw new RuntimeException('There are no new rows to import. Check existing codes or the rows that need correction.');
            $created = 0; $updated = 0; $followup = 0; $newIds = [];
            $save = db()->prepare('UPDATE employee_roster_rows SET employee_id=?,imported_at=NOW() WHERE id=? AND import_id=? AND imported_at IS NULL');
            foreach ($selected as $r) {
                $d = $r['data']; $values = [];
                foreach (['department','position','site','brand','employer'] as $kind) {
                    $a = $r['assignment'][$kind];
                    if ($a['choice'] === 'NEW') {
                        $departmentId = $values['department'] ?? null;
                        $cacheKey = $kind.'|'.($kind === 'site' ? strtoupper($a['code']) : self::normalize($a['name'])).'|'.($kind === 'position' ? $departmentId : '');
                        if (!isset($newIds[$cacheKey])) $newIds[$cacheKey] = self::master($kind,$a['name'],$departmentId,$a['code'] ?? '');
                        $values[$kind] = $newIds[$cacheKey];
                    } else $values[$kind] = $a['id'];
                }
                $record = [
                    'employee_no'=>$r['identity_held'] ? null : $d['employee_no'],'first_name'=>$d['first_name'],'middle_name'=>$d['middle_name'],'last_name'=>$d['last_name'],'suffix'=>$d['suffix'],
                    'hire_date'=>$d['hire_date'] ?: null,'department_id'=>$values['department'],'position_id'=>$values['position'],'branch_id'=>$values['site'],
                    'business_unit_id'=>$values['brand'],'legal_entity_id'=>$values['employer'],
                    'roster_full_name'=>$d['raw']['NAME'],'roster_group'=>$d['source_sheet'],
                    'roster_original_code'=>$d['employee_no'] ?: null,'roster_source_key'=>$r['source_key'],'roster_pending'=>$r['pending'],
                ];
                $existingId = $r['action'] === 'UPDATE' ? (int)$r['existing']['id'] : null;
                $employeeId = EmployeeRepository::importRosterRecord($record,$existingId,$id);
                if (EmployeeRepository::rosterIssues(EmployeeRepository::find($employeeId) ?? [])) $followup++;
                $save->execute([$employeeId,$r['id'],$id]);
                $existingId ? $updated++ : $created++;
            }
            $selectedIds = array_fill_keys(array_column($selected,'id'),true);
            $remaining = count(array_filter($review['rows'],static fn($r)=>$r['state'] === 'CHECK' || (in_array($r['state'],['READY','FOLLOWUP'],true) && !isset($selectedIds[$r['id']]))));
            db()->prepare('UPDATE employee_roster_imports SET status=?,version=version+1 WHERE id=?')->execute([$remaining ? 'PARTIAL' : 'IMPORTED',$id]);
            audit('Employees','IMPORT_ROSTER','employee_roster_import',$id,['created'=>$created,'updated'=>$updated,'needs_details'=>$followup,'remaining'=>$remaining]);
            db()->commit(); unset($_SESSION['roster_reviews'][$id]);
            return ['created'=>$created,'updated'=>$updated,'needs_details'=>$followup,'remaining'=>$remaining];
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            if ($e instanceof PDOException) {
                error_log('Roster import database error: '.$e->getCode());
                throw new RuntimeException('The import could not be saved. No rows from this click were changed. Reload the preview and check Employee Codes and organization matches.');
            }
            throw $e;
        }
    }

    private static function master(string $kind, string $name, ?int $departmentId, string $branchCode = ''): int
    {
        $name = trim($name);
        if (!array_key_exists($kind,self::TABLES) || !self::canAddSource(['kind'=>$kind,'name'=>$name,'code'=>$branchCode])) throw new RuntimeException('Check the organization name and branch code from Excel.');
        $table = self::TABLES[$kind];
        if ($kind === 'site') {
            $branchCode = strtoupper(trim($branchCode));
            $st = db()->prepare('SELECT id,active FROM branches WHERE code=? FOR UPDATE'); $st->execute([$branchCode]);
            $existing = $st->fetch();
            if ($existing) {
                if (!$existing['active']) throw new RuntimeException('This branch code is inactive. Choose an active branch before importing.');
                return (int)$existing['id'];
            }
            FoundationRepository::createBranch($branchCode,$name,'');
            $st = db()->prepare('SELECT id FROM branches WHERE code=?'); $st->execute([$branchCode]);
            $id = (int)$st->fetchColumn();
            if (!$id) throw new RuntimeException('The branch could not be saved from Excel.');
            audit('Organization','CREATE_FROM_ROSTER',$table,$id,['name'=>$name,'code'=>$branchCode]);
            return $id;
        }
        // Normalize the same way as preview matching, including records created earlier in this click.
        $sql = 'SELECT id,name,active FROM '.$table.' WHERE 1=1'; $params = [];
        if ($kind === 'position') { $sql .= ' AND department_id <=> ?'; $params[] = $departmentId; }
        $st = db()->prepare($sql.' ORDER BY id FOR UPDATE'); $st->execute($params);
        $matches = []; $known = false;
        foreach ($st->fetchAll() as $r) if (self::normalize($r['name']) === self::normalize($name)) {
            $known = true;
            if ($r['active']) $matches[] = (int)$r['id'];
        }
        if (count($matches) > 1) throw new RuntimeException('More than one '.$kind.' has this name. Choose the correct existing match.');
        if ($matches) return $matches[0];
        if ($known) throw new RuntimeException('This '.$kind.' is inactive. Choose an active match before importing.');
        $code = 'RI_'.strtoupper(substr(hash('sha256',$kind.'|'.$name.'|'.($kind === 'position' ? $departmentId : '')),0,24));
        $st = db()->prepare('INSERT INTO '.$table.'(code,name'.($kind === 'position' ? ',department_id' : '').') VALUES(?,?'.($kind === 'position' ? ',?' : '').')');
        $st->execute($kind === 'position' ? [$code,$name,$departmentId] : [$code,$name]);
        $id = (int)db()->lastInsertId();
        audit('Organization','CREATE_FROM_ROSTER',$table,$id,['name'=>$name]);
        return $id;
    }

    public static function companyDetails(array $employee): array
    {
        $result = ['brand'=>'Not set','employer'=>'Not set'];
        foreach (['brand'=>['business_units','business_unit_id'],'employer'=>['legal_entities','legal_entity_id']] as $key=>[$table,$column]) {
            if (empty($employee[$column]) || !FoundationRepository::tableExists($table)) continue;
            $st = db()->prepare('SELECT name FROM '.$table.' WHERE id=?'); $st->execute([(int)$employee[$column]]);
            $result[$key] = (string)($st->fetchColumn() ?: 'Not set');
        }
        return $result;
    }

    private static function version(array $batch, int $version): void
    {
        if ((int)$batch['version'] !== $version) throw new RuntimeException('This preview changed in another tab. Reload before saving.');
    }
    private static function json(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
