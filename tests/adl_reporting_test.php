<?php
declare(strict_types=1);

/**
 * Retail "Reporting To" = ADL only. Integration test against a SCRATCH copy of the database.
 *
 * 1. Make a scratch copy (Git Bash):
 *      mysql -uroot -e "DROP DATABASE IF EXISTS pmbsi_hris_test; CREATE DATABASE pmbsi_hris_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 *      mysqldump -uroot --single-transaction --routines --triggers pmbsi_hris | mysql -uroot pmbsi_hris_test
 * 2. Run:
 *      HRIS_TEST_DB=pmbsi_hris_test C:\xampp\php\php.exe tests\adl_reporting_test.php
 * 3. Drop the copy: mysql -uroot -e "DROP DATABASE pmbsi_hris_test"
 *
 * Refuses to run on the live database. Everything runs inside one transaction that is rolled back at the end.
 * Test data is picked from the copy (no real names are printed).
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
$GLOBALS['pdo'] = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (db()->query('SELECT DATABASE()')->fetchColumn() !== $dbName) exit(2);

$fail = 0; $pass = 0;
function check(string $name, bool $ok, string $detail = ''): void { global $fail, $pass; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === '' ? '' : ' -> '.$detail)."\n"; }
function throws(callable $fn): ?string { try { $fn(); return null; } catch (Throwable $e) { return $e->getMessage(); } }
function actAs(string $roleCode): void {
    $r = db()->prepare('SELECT id,name,portal FROM roles WHERE code=?'); $r->execute([$roleCode]); $role = $r->fetch();
    $_SESSION['user'] = ['id' => 1, 'role_id' => (int)$role['id'], 'name' => 'Test', 'email' => 'test@example.test', 'role_code' => $roleCode, 'role_name' => $role['name'], 'role_portal' => $role['portal'], 'client_id' => null, 'client_name' => null];
    Auth::clearPermissionCache();
}
function manager(int $id): ?int { $v = db()->query('SELECT manager_employee_id FROM employees WHERE id='.$id)->fetchColumn(); return $v === null || $v === false ? null : (int)$v; }
function one(string $sql): ?int { $v = db()->query($sql)->fetchColumn(); return $v === false || $v === null ? null : (int)$v; }

db()->beginTransaction();
try {
    // --- fixtures taken from the copy ---
    $retail = one("SELECT id FROM departments WHERE UPPER(code)='RETAIL'");
    $headOfficeDept = one("SELECT id FROM departments WHERE name LIKE 'MIS%' LIMIT 1");
    $mgr = one("SELECT e.id FROM employees e JOIN users u ON u.id=e.user_id JOIN roles r ON r.id=u.role_id WHERE r.code='MANAGER' AND e.status='ACTIVE' LIMIT 1");
    // The two ADLs are synthetic (own fixtures), so the result does not depend on which real ADLs have accounts or areas.
    $adlPosition = one("SELECT id FROM positions WHERE UPPER(name)='AREA DEVELOPMENT LEADER' ORDER BY id LIMIT 1");
    $adlBranch = one("SELECT id FROM branches ORDER BY id LIMIT 1");
    $adlLegal = one("SELECT id FROM legal_entities ORDER BY id LIMIT 1");
    $adlUnit = one("SELECT id FROM business_units ORDER BY id LIMIT 1");
    $mkAdl = function (string $group, string $tag) use ($adlPosition, $adlBranch, $adlLegal, $adlUnit): ?int {
        if (!$adlPosition) return null;
        $q = db()->prepare("INSERT INTO employees(employee_no,first_name,last_name,branch_id,legal_entity_id,business_unit_id,position_id,directory_company_group,status,hire_date) VALUES(?,?,?,?,?,?,?,?,'ACTIVE','2020-01-01')");
        $q->execute(['ADLFIX_'.$tag, 'Fixture', 'ADL '.$tag, $adlBranch, $adlLegal, $adlUnit, $adlPosition, $group]);
        return (int)db()->lastInsertId();
    };
    $adlA = $mkAdl('OC', 'A');
    $adlB = $mkAdl('SW', 'B');
    // Nobody holds an area at the start; the areas HR has set on real ADLs are irrelevant to this rule.
    db()->exec("UPDATE employees SET directory_area_ids=NULL WHERE directory_area_ids IS NOT NULL");
    $ocRetail = one("SELECT id FROM employees WHERE department_id=$retail AND directory_company_group='OC' AND employee_no IS NOT NULL AND hire_date IS NOT NULL AND status='ACTIVE' ORDER BY id LIMIT 1");
    $swRetail = one("SELECT id FROM employees WHERE department_id=$retail AND directory_company_group='SW' AND employee_no IS NOT NULL AND hire_date IS NOT NULL AND status='ACTIVE' ORDER BY id LIMIT 1");
    $hoEmp = one("SELECT id FROM employees WHERE department_id=$headOfficeDept AND employee_no IS NOT NULL AND hire_date IS NOT NULL AND status='ACTIVE' ORDER BY id LIMIT 1");
    foreach (compact('retail','headOfficeDept','mgr','adlA','adlB','ocRetail','swRetail','hoEmp') as $k => $v) if (!$v) { echo "Fixture missing: $k\n"; exit(2); }
    db()->exec("UPDATE employees SET manager_employee_id=NULL WHERE id IN ($ocRetail,$swRetail,$hoEmp)");

    // The ADL accounts do not exist yet in real data; the rule must not need one.
    check('fixture: ADLs have no linked account', one("SELECT COUNT(*) FROM employees WHERE id IN ($adlA,$adlB) AND user_id IS NOT NULL") === 0);

    // --- candidates / ADL definition ---
    $cands = []; foreach (EmployeeRepository::managerCandidates($ocRetail) as $c) $cands[(int)$c['id']] = $c;
    check('ADL position is flagged is_adl', (int)($cands[$adlA]['is_adl'] ?? 0) === 1 && (int)($cands[$adlB]['is_adl'] ?? 0) === 1);
    check('Manager is not flagged is_adl', (int)($cands[$mgr]['is_adl'] ?? 1) === 0);
    check('isAdl() agrees', EmployeeRepository::isAdl($adlA) && !EmployeeRepository::isAdl($mgr));
    db()->exec("UPDATE employees SET directory_role='EMPLOYEE' WHERE id=$adlB");
    check('Directory section override to Employees removes ADL (same rule as the directory)', !EmployeeRepository::isAdl($adlB));
    db()->exec("UPDATE employees SET directory_role=NULL WHERE id=$adlB");

    // --- HR session from here ---
    actAs('HR_USER');

    // 1. Retail -> ADLs only
    $m = throws(fn() => EmployeeRepository::updateEmployment($ocRetail, ['manager_employee_id' => $mgr]));
    check('Retail + Manager is rejected', $m !== null && str_contains($m, 'Area Development Leader'), (string)$m);
    check('Retail + Manager left the employee unchanged', manager($ocRetail) === null);
    $m = throws(fn() => EmployeeRepository::updateEmployment($swRetail, ['manager_employee_id' => $adlB]));
    check('Retail + ADL (no account yet) is accepted', $m === null, (string)$m);
    check('Retail + ADL is saved', manager($swRetail) === $adlB);

    // 2. Non-Retail unchanged
    $m = throws(fn() => EmployeeRepository::updateEmployment($hoEmp, ['manager_employee_id' => $mgr]));
    check('Non-Retail + Manager still accepted', $m === null, (string)$m);
    $m = throws(fn() => EmployeeRepository::updateEmployment($hoEmp, ['manager_employee_id' => $adlB]));
    check('Non-Retail + ADL without account still rejected as before', $m !== null && str_contains($m, 'active linked HRIS account'), (string)$m);
    check('Non-Retail keeps its Manager', manager($hoEmp) === $mgr);
    $retailPosition = one("SELECT id FROM positions WHERE department_id=$retail AND active=1 ORDER BY id LIMIT 1");
    $m = throws(fn() => EmployeeRepository::updateEmployment($hoEmp, ['department_id' => $retail, 'position_id' => $retailPosition]));
    check('Moving a Manager-led employee into Retail is rejected until an ADL is chosen', $m !== null && str_contains($m, 'Area Development Leader'), (string)$m);

    // 3. Ocampo's auto-fill (area-based, not a name)
    check('No auto ADL while nobody holds the Ocampos area', EmployeeRepository::adlIdForArea('OCAMPOS') === null);
    $m = throws(fn() => EmployeeRepository::updateEmployment($ocRetail, ['manager_employee_id' => '']));
    check('Ocampo\'s Retail stays unassigned when no ADL holds the Ocampos area', $m === null && manager($ocRetail) === null, (string)$m);
    $ocamposArea = one("SELECT id FROM areas WHERE UPPER(code)='OCAMPOS'");
    db()->exec("UPDATE employees SET directory_area_ids=',$ocamposArea,' WHERE id=$adlA");   // what HR does in Employee 201
    check('adlIdForArea finds the ADL who holds Ocampos', EmployeeRepository::adlIdForArea('OCAMPOS') === $adlA);
    $m = throws(fn() => EmployeeRepository::updateEmployment($ocRetail, ['manager_employee_id' => '']));
    check('Ocampo\'s Retail is auto-assigned to the Ocampos ADL', $m === null && manager($ocRetail) === $adlA, (string)$m);
    $m = throws(fn() => EmployeeRepository::updateEmployment($swRetail, ['manager_employee_id' => '']));
    check('Non-Ocampo\'s Retail is not auto-assigned', $m === null && manager($swRetail) === null, (string)$m);
    $m = throws(fn() => EmployeeRepository::updateEmployment($ocRetail, ['name_only_edit' => 1]));
    check('An unrelated save does not touch Reporting To', $m === null && manager($ocRetail) === $adlA, (string)$m);

    // 4. HR override
    $m = throws(fn() => EmployeeRepository::updateEmployment($ocRetail, ['manager_employee_id' => $adlB]));
    check('HR can override the auto ADL with another ADL', $m === null && manager($ocRetail) === $adlB, (string)$m);
    db()->exec("UPDATE employees SET directory_area_ids=',$ocamposArea,' WHERE id=$adlB");
    check('Two ADLs on the same area: no auto-assign (HR must choose)', EmployeeRepository::adlIdForArea('OCAMPOS') === null);
    db()->exec("UPDATE employees SET directory_area_ids=NULL WHERE id=$adlB");

    // 5. Non-HR cannot change Reporting To
    check('HR_USER holds employees.manage', Auth::can('employees.manage'));
    foreach (['EMPLOYEE', 'MANAGER'] as $role) {
        actAs($role);
        check("$role does not hold employees.manage", !Auth::can('employees.manage'));
        $m = throws(fn() => EmployeeRepository::updateEmployment($ocRetail, ['manager_employee_id' => $adlA]));
        check("$role cannot change Reporting To", $m !== null && str_contains($m, 'Only HR'), (string)$m);
        check("$role attempt left it unchanged", manager($ocRetail) === $adlB);
    }
    actAs('HR_USER');

    // 6. A legacy non-ADL line does not block unrelated edits, but cannot be re-saved into Retail either
    db()->exec("UPDATE employees SET manager_employee_id=$mgr WHERE id=$swRetail");
    $m = throws(fn() => EmployeeRepository::updateEmployment($swRetail, ['remarks' => 'unrelated edit']));
    check('Legacy Manager on a Retail employee does not block an unrelated edit', $m === null && manager($swRetail) === $mgr, (string)$m);
    $m = throws(fn() => EmployeeRepository::updateEmployment($swRetail, ['manager_employee_id' => $mgr]));
    check('Re-saving the same legacy Manager is not treated as a change', $m === null, (string)$m);
} finally {
    db()->rollBack();
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
