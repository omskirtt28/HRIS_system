<?php
declare(strict_types=1);

/**
 * Ocampo's Head Office section (Ocampos Production; Goldsmith is a position under it). Integration test against a SCRATCH copy.
 *
 * 1. Make a scratch copy (Git Bash):
 *      mysql -uroot -e "DROP DATABASE IF EXISTS pmbsi_hris_test; CREATE DATABASE pmbsi_hris_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 *      mysqldump -uroot --single-transaction --routines --triggers pmbsi_hris | mysql -uroot pmbsi_hris_test
 * 2. Run:
 *      HRIS_TEST_DB=pmbsi_hris_test C:\xampp\php\php.exe tests\oc_head_office_sections_test.php
 * 3. Drop the copy: mysql -uroot -e "DROP DATABASE pmbsi_hris_test"
 *
 * Refuses to run on the live database. The migration is applied to the copy (twice, to prove it is idempotent) before the
 * data checks, which then run inside one transaction that is rolled back. No real names are printed.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$mysql = getenv('HRIS_MYSQL_BIN') ?: 'C:\\xampp\\mysql\\bin\\mysql.exe';
$migration = dirname(__DIR__).'/database/migrations/20261017_oc_head_office_sections.sql';

$fail = 0; $pass = 0;
function check(string $name, bool $ok, string $detail = ''): void { global $fail, $pass; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === '' ? '' : ' -> '.$detail)."\n"; }
function throws(callable $fn): ?string { try { $fn(); return null; } catch (Throwable $e) { return $e->getMessage(); } }
function one(string $sql): ?int { $v = db()->query($sql)->fetchColumn(); return $v === false || $v === null ? null : (int)$v; }
function actAs(string $roleCode): void {
    $r = db()->prepare('SELECT id,name,portal FROM roles WHERE code=?'); $r->execute([$roleCode]); $role = $r->fetch();
    $_SESSION['user'] = ['id' => 1, 'role_id' => (int)$role['id'], 'name' => 'Test', 'email' => 'test@example.test', 'role_code' => $roleCode, 'role_name' => $role['name'], 'role_portal' => $role['portal'], 'client_id' => null, 'client_name' => null];
    Auth::clearPermissionCache();
}
function runMigration(string $mysql, string $dbName, string $file): bool {
    exec('"'.$mysql.'" -uroot '.escapeshellarg($dbName).' < '.escapeshellarg($file).' 2>&1', $out, $code);
    if ($code !== 0) echo implode("\n", $out)."\n";
    return $code === 0;
}

// --- the migration must be re-runnable. DDL commits, so this happens before the rolled-back transaction. ---
$applied = runMigration($mysql, $dbName, $migration);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';   // before any output, so the session can start
check('migration applies', $applied);
$GLOBALS['pdo'] = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (db()->query('SELECT DATABASE()')->fetchColumn() !== $dbName) exit(2);

$snapshot = static fn() => [
    db()->query('SELECT id,code,name,company_group,site_type,active FROM directory_sections ORDER BY id')->fetchAll(),
    one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employees' AND column_name='directory_section_id'"),
    one("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND table_name='employees' AND constraint_name='fk_employees_directory_section'"),
    one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='employees' AND index_name='idx_employees_directory_section'"),
];
$first = $snapshot();
check('migration is re-runnable', runMigration($mysql, $dbName, $migration));
$second = $snapshot();
check('seed: exactly 1 section (the 2 earlier ones are retired)', count($first[0]) === 1, (string)count($first[0]));
check('seed: re-run creates no duplicates and changes nothing', $first === $second);
check('seed: the name', array_column($first[0], 'name') === ['Ocampos Production']);
check('column, FK and index exist once', $second[1] === 1 && $second[2] === 1 && $second[3] === 1);
check('nothing assigned automatically', one('SELECT COUNT(*) FROM employees WHERE directory_section_id IS NOT NULL') === 0);

// --- Goldsmith position sits under Production; a later manual choice by HR is not overwritten ---
$goldsmithDept = static fn() => db()->query("SELECT d.code FROM positions p JOIN departments d ON d.id=p.department_id WHERE p.name='Goldsmith'")->fetchColumn();
check('Goldsmith position is under Production', $goldsmithDept() === 'PRODUCTION', (string)$goldsmithDept());
db()->exec("UPDATE positions SET department_id=(SELECT id FROM departments WHERE code='ADMIN') WHERE name='Goldsmith'");
runMigration($mysql, $dbName, $migration);
check('re-run does not overwrite a manual department choice', $goldsmithDept() === 'ADMIN', (string)$goldsmithDept());
db()->exec("UPDATE positions SET department_id=(SELECT id FROM departments WHERE code='INVENTORY_MANAGEMENT') WHERE name='Goldsmith'");
runMigration($mysql, $dbName, $migration);
check('a Goldsmith still under Inventory Management is moved to Production', $goldsmithDept() === 'PRODUCTION', (string)$goldsmithDept());

// --- the two earlier sections are retired: removed when unused, only deactivated when an employee holds one ---
$holder = one("SELECT e.id FROM employees e JOIN branches b ON b.id=e.branch_id AND b.site_type='HEAD_OFFICE' WHERE e.directory_company_group='OC' ORDER BY e.id LIMIT 1");
db()->exec("INSERT INTO directory_sections (code,name,company_group,site_type) VALUES ('OC_HO_GOLDSMITH','Goldsmith','OC','HEAD_OFFICE'),('OC_HO_INVENTORY_MANAGEMENT','Inventory Management','OC','HEAD_OFFICE')");
db()->exec("UPDATE employees SET directory_section_id=(SELECT id FROM directory_sections WHERE code='OC_HO_GOLDSMITH') WHERE id=$holder");
runMigration($mysql, $dbName, $migration);
check('an old section nobody holds is removed', one("SELECT COUNT(*) FROM directory_sections WHERE code='OC_HO_INVENTORY_MANAGEMENT'") === 0);
check('an old section an employee holds is kept but inactive', one("SELECT COUNT(*) FROM directory_sections WHERE code='OC_HO_GOLDSMITH' AND active=0") === 1 && one("SELECT COUNT(*) FROM employees WHERE id=$holder AND directory_section_id IS NOT NULL") === 1);
db()->exec("UPDATE employees SET directory_section_id=NULL WHERE id=$holder");
runMigration($mysql, $dbName, $migration);
check('...and is removed once released', one("SELECT COUNT(*) FROM directory_sections WHERE code='OC_HO_GOLDSMITH'") === 0);
check('only Ocampos Production remains', one('SELECT COUNT(*) FROM directory_sections') === 1);

db()->beginTransaction();
try {
    // --- scoping: only Ocampo's + Head Office ---
    $codes = static fn(array $rows) => array_column($rows, 'code');
    check('ready() is true after the migration', DirectorySectionService::ready());
    check('OC + HEAD_OFFICE lists the section', count(DirectorySectionService::forScope('OC', 'HEAD_OFFICE')) === 1);
    check('SW + HEAD_OFFICE lists none', DirectorySectionService::forScope('SW', 'HEAD_OFFICE') === []);
    check('UF + HEAD_OFFICE lists none', DirectorySectionService::forScope('UF', 'HEAD_OFFICE') === []);
    check('OC + RETAIL_STORE lists none', DirectorySectionService::forScope('OC', 'RETAIL_STORE') === []);
    check('scope is case/space tolerant', count(DirectorySectionService::forScope(' oc ', 'head_office')) === 1);
    check('a retired section is hidden unless it is the current one', (function () {
        $id = one("SELECT id FROM directory_sections WHERE code='OC_HO_OCAMPOS_PRODUCTION'");
        db()->exec("UPDATE directory_sections SET active=0 WHERE id=$id");
        $hidden = count(DirectorySectionService::forScope('OC', 'HEAD_OFFICE')) === 0;
        $kept = count(DirectorySectionService::forScope('OC', 'HEAD_OFFICE', $id)) === 1;
        db()->exec("UPDATE directory_sections SET active=1 WHERE id=$id");
        return $hidden && $kept;
    })());

    // --- fixtures taken from the copy ---
    $ocHo = one("SELECT e.id FROM employees e JOIN branches b ON b.id=e.branch_id AND b.site_type='HEAD_OFFICE' WHERE e.directory_company_group='OC' AND e.employee_no IS NOT NULL AND e.hire_date IS NOT NULL AND e.status='ACTIVE' ORDER BY e.id LIMIT 1");
    $swHo = one("SELECT e.id FROM employees e JOIN branches b ON b.id=e.branch_id AND b.site_type='HEAD_OFFICE' WHERE e.directory_company_group='SW' AND e.employee_no IS NOT NULL AND e.hire_date IS NOT NULL AND e.status='ACTIVE' ORDER BY e.id LIMIT 1");
    $ocRetail = one("SELECT e.id FROM employees e JOIN branches b ON b.id=e.branch_id AND b.site_type='RETAIL_STORE' WHERE e.directory_company_group='OC' AND e.employee_no IS NOT NULL AND e.hire_date IS NOT NULL AND e.status='ACTIVE' ORDER BY e.id LIMIT 1");
    $hoBranch = one("SELECT id FROM branches WHERE site_type='HEAD_OFFICE' AND active=1 ORDER BY id LIMIT 1");
    $retailBranch = one("SELECT branch_id FROM employees WHERE id=$ocRetail");
    foreach (compact('ocHo', 'swHo', 'ocRetail', 'hoBranch', 'retailBranch') as $k => $v) if (!$v) { echo "Fixture missing: $k\n"; exit(2); }
    $sec = fn(string $code) => one("SELECT id FROM directory_sections WHERE code='$code'");
    $production = $sec('OC_HO_OCAMPOS_PRODUCTION');
    $section = fn(int $id) => one("SELECT directory_section_id FROM employees WHERE id=$id");
    $post = fn(int $id, ?int $sectionId) => EmployeeRepository::updateEmployment($id, ['directory_section_present' => true, 'directory_section_id' => (int)$sectionId]);
    actAs('HR_USER');

    // --- save: accepted inside scope ---
    $m = throws(fn() => $post($ocHo, $production));
    check('OC + Head Office can take Ocampos Production', $m === null && $section($ocHo) === $production, (string)$m);

    // --- save: rejected outside scope, and left unchanged ---
    $m = throws(fn() => $post($swHo, $production));
    check('Sw Head Office employee is rejected', $m !== null && str_contains($m, 'only for Ocampo'), (string)$m);
    check('...and nothing was saved', $section($swHo) === null);
    $m = throws(fn() => $post($ocRetail, $production));
    check('Ocampo\'s Retail employee is rejected', $m !== null && str_contains($m, 'only for Ocampo'), (string)$m);
    check('...and nothing was saved (retail)', $section($ocRetail) === null);
    $m = throws(fn() => $post($ocHo, 999999));
    check('an unknown section id is rejected', $m !== null && str_contains($m, 'valid section'), (string)$m);
    check('...and the previous section is kept', $section($ocHo) === $production);

    // --- save: blank clears; omitted field does nothing ---
    $m = throws(fn() => EmployeeRepository::updateEmployment($ocHo, ['name_only_edit' => 1]));
    check('an unrelated save (no section field) does not touch the section', $m === null && $section($ocHo) === $production, (string)$m);
    $m = throws(fn() => $post($ocHo, null));
    check('"No section" clears it', $m === null && $section($ocHo) === null, (string)$m);

    // --- leaving Ocampo's Head Office clears the section ---
    throws(fn() => $post($ocHo, $production));
    $m = throws(fn() => EmployeeRepository::updateEmployment($ocHo, ['branch_id' => $retailBranch, 'directory_section_present' => true, 'directory_section_id' => $production]));
    check('moving to a retail branch with a section is rejected', $m !== null && str_contains($m, 'only for Ocampo'), (string)$m);
    $m = throws(fn() => EmployeeRepository::updateEmployment($ocHo, ['branch_id' => $retailBranch, 'directory_section_present' => true, 'directory_section_id' => 0]));
    check('moving to a retail branch with no section clears it', $m === null && $section($ocHo) === null, (string)$m);
    db()->exec("UPDATE employees SET branch_id=$hoBranch WHERE id=$ocHo");

    // --- form dropdown ---
    $ocHoRow = EmployeeRepository::find($ocHo);
    $field = DirectorySectionViews::field($ocHoRow);
    check('form: field is visible for Ocampo\'s + Head Office', !str_contains($field, 'display:none') && !str_contains($field, ' disabled'));
    check('form: lists only Ocampos Production', str_contains($field, '>Ocampos Production<') && substr_count($field, '<option') === 2);
    check('form: always posts the marker so the server can clear', str_contains($field, 'name="directory_section_present"'));
    check('form: knows the Head Office branch ids', str_contains($field, htmlspecialchars(json_encode(DirectorySectionService::headOfficeBranchIds()), ENT_QUOTES)));
    $swRow = EmployeeRepository::find($swHo);
    check('form: hidden and disabled for Sw Head Office', str_contains(DirectorySectionViews::field($swRow), 'display:none') && str_contains(DirectorySectionViews::field($swRow), ' disabled'));
    $retailRow = EmployeeRepository::find($ocRetail);
    check('form: hidden and disabled for Ocampo\'s Retail', str_contains(DirectorySectionViews::field($retailRow), 'display:none') && str_contains(DirectorySectionViews::field($retailRow), ' disabled'));
    $ocHoRow['directory_section_id'] = $production;
    check('form: preselects the saved section', (bool)preg_match('/value="'.$production.'" selected/', DirectorySectionViews::field($ocHoRow)));

    // --- Position hints (dry-run only) ---
    check('hint: Goldsmith -> Ocampos Production', DirectorySectionService::suggestCode('Goldsmith') === 'OC_HO_OCAMPOS_PRODUCTION');
    check('hint: Production Admin -> Ocampos Production', DirectorySectionService::suggestCode('  production   admin ') === 'OC_HO_OCAMPOS_PRODUCTION');
    check('hint: Accounting Staff -> none', DirectorySectionService::suggestCode('Accounting Staff') === null);
    $before = one('SELECT COUNT(*) FROM employees WHERE directory_section_id IS NOT NULL');
    $suggestions = DirectorySectionService::suggestions();
    check('dry-run only reads (no section assigned)', one('SELECT COUNT(*) FROM employees WHERE directory_section_id IS NOT NULL') === $before);
    check('dry-run only lists Ocampo\'s Head Office employees', !in_array($swHo, array_column($suggestions, 'employee_id'), true) && !in_array($ocRetail, array_column($suggestions, 'employee_id'), true));
} catch (Throwable $e) {
    $fail++; echo 'FAIL unexpected error -> '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine()."\n";
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
