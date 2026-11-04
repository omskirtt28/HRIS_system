<?php
declare(strict_types=1);

/**
 * Retail Manager (head of the ADLs). Integration test against a SCRATCH copy of the database.
 * Needs migration 20261104_032 applied to the copy. Run:
 *   HRIS_TEST_DB=pmbsi_hris_test C:\xampp\php\php.exe tests\retail_manager_test.php
 * Refuses the live database. Everything runs in one transaction that is rolled back at the end.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
$GLOBALS['pdo'] = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (db()->query('SELECT DATABASE()')->fetchColumn() !== $dbName) exit(2);

$fail = 0; $pass = 0;
function check(string $name, bool $ok, string $detail = ''): void { global $fail, $pass; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === '' ? '' : ' -> '.$detail)."\n"; }
function one(string $sql): ?int { $v = db()->query($sql)->fetchColumn(); return $v === false || $v === null ? null : (int)$v; }
function grants(string $code): array { $q = db()->prepare('SELECT p.code FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.code=? ORDER BY p.code'); $q->execute([$code]); return $q->fetchAll(PDO::FETCH_COLUMN); }

db()->beginTransaction();
try {
    // --- T1: position and role exist, role = MANAGER's grants ---
    $retailDept = one("SELECT id FROM departments WHERE UPPER(code)='RETAIL'");
    check('T1 position "Retail Manager" exists in Retail', one("SELECT COUNT(*) FROM positions WHERE code='RETAIL_MANAGER' AND name='Retail Manager' AND active=1 AND department_id=".(int)$retailDept) === 1);
    check('T1 role RETAIL_MANAGER exists on the employee portal', one("SELECT COUNT(*) FROM roles WHERE code='RETAIL_MANAGER' AND portal='employee'") === 1);
    $noRecruitment = static fn(array $codes) => array_values(array_filter($codes, static fn($c) => !str_starts_with($c, 'recruitment.')));
    check('T1 RETAIL_MANAGER grants equal MANAGER grants minus recruitment (no new permission)', grants('RETAIL_MANAGER') === $noRecruitment(grants('MANAGER')) && count(grants('MANAGER')) > 0);
    check('T1 RETAIL_MANAGER can approve as Manager and view the team', in_array('payroll.approve_manager', grants('RETAIL_MANAGER'), true) && in_array('ticket.view_team', grants('RETAIL_MANAGER'), true));
    check('T1 RETAIL_MANAGER has no recruitment permission (John: no Hiring Review role)', count(array_filter(grants('RETAIL_MANAGER'), static fn($c) => str_starts_with($c, 'recruitment.'))) === 0);

    // --- fixtures for T2+ (synthetic employees, own accounts) ---
    function throws(callable $fn): ?string { try { $fn(); return null; } catch (Throwable $e) { return $e->getMessage(); } }
    function actAs(string $roleCode): void {
        $r = db()->prepare('SELECT id,name,portal FROM roles WHERE code=?'); $r->execute([$roleCode]); $role = $r->fetch();
        $_SESSION['user'] = ['id' => 1, 'role_id' => (int)$role['id'], 'name' => 'Test', 'email' => 'test@example.test', 'role_code' => $roleCode, 'role_name' => $role['name'], 'role_portal' => $role['portal'], 'client_id' => null, 'client_name' => null];
        Auth::clearPermissionCache();
    }
    function manager(int $id): ?int { $v = db()->query('SELECT manager_employee_id FROM employees WHERE id='.$id)->fetchColumn(); return $v === null || $v === false ? null : (int)$v; }
    $hoBranch = one("SELECT id FROM branches WHERE site_type='HEAD_OFFICE' ORDER BY id LIMIT 1");
    $legal = one("SELECT id FROM legal_entities ORDER BY id LIMIT 1");
    $unit = one("SELECT id FROM business_units ORDER BY id LIMIT 1");
    $adlPos = one("SELECT id FROM positions WHERE UPPER(name)='AREA DEVELOPMENT LEADER' AND department_id=$retailDept ORDER BY id LIMIT 1");
    $rmPos = one("SELECT id FROM positions WHERE code='RETAIL_MANAGER'");
    $mkEmp = function (string $tag, int $pos, ?int $dept, ?string $roleCode) use ($hoBranch, $legal, $unit): int {
        $uid = null;
        if ($roleCode) {
            db()->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code=?),?,?,?,"ACTIVE")')->execute([$roleCode, 'Fixture '.$tag, 'rm_'.strtolower($tag).'@example.test', password_hash('Test#1234', PASSWORD_DEFAULT)]);
            $uid = (int)db()->lastInsertId();
        }
        db()->prepare("INSERT INTO employees(employee_no,first_name,last_name,branch_id,legal_entity_id,business_unit_id,position_id,department_id,user_id,status,hire_date) VALUES(?,?,?,?,?,?,?,?,?,'ACTIVE','2020-01-01')")
            ->execute(['RMFIX_'.$tag, 'Fixture', 'RM '.$tag, $hoBranch, $legal, $unit, $pos, $dept, $uid]);
        return (int)db()->lastInsertId();
    };
    $rm = $mkEmp('RM1', $rmPos, $retailDept, 'RETAIL_MANAGER');
    $rm2 = $mkEmp('RM2', $rmPos, $retailDept, 'RETAIL_MANAGER');           // flexible: more than one is fine
    $adl = $mkEmp('ADL1', $adlPos, $retailDept, null);
    $otherMgr = one("SELECT e.id FROM employees e JOIN users u ON u.id=e.user_id JOIN roles r ON r.id=u.role_id WHERE r.code='MANAGER' AND u.status='ACTIVE' AND e.status='ACTIVE' LIMIT 1");
    $retailStaff = $mkEmp('STAFF1', (int)one("SELECT id FROM positions WHERE department_id=$retailDept AND code<>'RETAIL_MANAGER' AND UPPER(name) NOT LIKE '%AREA DEV%' AND active=1 ORDER BY id LIMIT 1"), $retailDept, null);
    $hoStaff = $mkEmp('HOSTAFF1', (int)one("SELECT id FROM positions WHERE department_id IS NOT NULL AND department_id<>$retailDept ORDER BY id LIMIT 1"), (int)one("SELECT department_id FROM positions WHERE department_id<>$retailDept ORDER BY id LIMIT 1"), null);
    foreach (compact('rmPos','adlPos','rm','rm2','adl','otherMgr','retailStaff','hoStaff') as $k => $v) if (!$v) { echo "Fixture missing: $k\n"; exit(2); }
    actAs('HR_USER');

    // --- T2: an ADL reports to a Retail Manager only ---
    $cands = []; foreach (EmployeeRepository::managerCandidates($adl) as $c) $cands[(int)$c['id']] = $c;
    check('T2 candidates flag Retail Managers', (int)($cands[$rm]['is_retail_manager'] ?? 0) === 1 && (int)($cands[$rm2]['is_retail_manager'] ?? 0) === 1 && (int)($cands[$otherMgr]['is_retail_manager'] ?? 1) === 0);
    $m = throws(fn() => EmployeeRepository::updateEmployment($adl, ['manager_employee_id' => $otherMgr]));
    check('T2 ADL + other Manager is rejected', $m !== null && str_contains($m, 'Retail Manager'), (string)$m);
    check('T2 rejected save left the ADL unchanged', manager($adl) === null);
    $m = throws(fn() => EmployeeRepository::updateEmployment($adl, ['manager_employee_id' => $retailStaff]));
    check('T2 ADL + a plain employee is rejected', $m !== null && str_contains($m, 'Retail Manager'), (string)$m);
    $m = throws(fn() => EmployeeRepository::updateEmployment($adl, ['manager_employee_id' => $rm]));
    check('T2 ADL + Retail Manager is accepted and saved', $m === null && manager($adl) === $rm, (string)$m);
    $m = throws(fn() => EmployeeRepository::updateEmployment($adl, ['manager_employee_id' => $rm2]));
    check('T2 a second Retail Manager works too (not hard-coded to one person)', $m === null && manager($adl) === $rm2, (string)$m);
    $m = throws(fn() => EmployeeRepository::updateEmployment($adl, ['manager_employee_id' => '']));
    check('T2 ADL can be set back to Unassigned', $m === null && manager($adl) === null, (string)$m);
    db()->prepare('UPDATE users SET status="INACTIVE" WHERE id=(SELECT user_id FROM employees WHERE id=?)')->execute([$rm2]);
    $m = throws(fn() => EmployeeRepository::updateEmployment($adl, ['manager_employee_id' => $rm2]));
    check('T2 a Retail Manager with an inactive account is rejected', $m !== null && str_contains($m, 'Retail Manager'), (string)$m);
    $m = throws(fn() => EmployeeRepository::updateEmployment($adl, ['manager_employee_id' => $adl]));
    check('T2 an ADL cannot report to themselves', $m !== null, (string)$m);
    // unchanged rules
    $m = throws(fn() => EmployeeRepository::updateEmployment($retailStaff, ['manager_employee_id' => $otherMgr]));
    check('T2 Retail staff + Manager still rejected (ADL only)', $m !== null && str_contains($m, 'Area Development Leader'), (string)$m);
    $m = throws(fn() => EmployeeRepository::updateEmployment($retailStaff, ['manager_employee_id' => $adl]));
    check('T2 Retail staff + ADL still accepted', $m === null && manager($retailStaff) === $adl, (string)$m);
    $m = throws(fn() => EmployeeRepository::updateEmployment($hoStaff, ['manager_employee_id' => $otherMgr]));
    check('T2 Head Office staff + Manager still accepted', $m === null && manager($hoStaff) === $otherMgr, (string)$m);
    // the Retail Manager is not forced to report to an ADL
    $m = throws(fn() => EmployeeRepository::updateEmployment($rm, ['manager_employee_id' => $otherMgr]));
    check('T2 a Retail Manager is not held to the "Retail staff report to an ADL" rule', $m === null && manager($rm) === $otherMgr, (string)$m);
    $m = throws(fn() => EmployeeRepository::updateEmployment($rm, ['manager_employee_id' => '']));
    check('T2 a Retail Manager can be Unassigned (no auto ADL)', $m === null && manager($rm) === null, (string)$m);

    // --- T3: Directory placement and the Manager-filer rule ---
    $groupOf = function (int $id, string $workplace): string {
        $x = EmployeeDirectoryPlacement::expressions($workplace, false);
        $q = db()->prepare("SELECT ({$x['group']}) FROM employees e LEFT JOIN positions p ON p.id=e.position_id LEFT JOIN branches b ON b.id=e.branch_id WHERE e.id=?"); $q->execute([$id]); return (string)$q->fetchColumn();
    };
    db()->prepare('UPDATE employees SET directory_role=NULL WHERE id IN (?,?)')->execute([$rm, $adl]);
    check('T3 Retail Manager is listed under Department Managers (Retail workplace)', $groupOf($rm, '"RETAIL"') === 'MANAGERS', $groupOf($rm, '"RETAIL"'));
    check('T3 Retail Manager is listed under Department Managers (Head Office workplace)', $groupOf($rm, '"HO"') === 'MANAGERS', $groupOf($rm, '"HO"'));
    check('T3 an ADL is still in the ADL group', $groupOf($adl, '"RETAIL"') === 'ADL');
    $row = fn(int $id) => db()->query('SELECT id,user_id FROM employees WHERE id='.$id)->fetch();
    check('T3 Retail Manager files straight to HR like a Manager', EmployeeApproverAssignmentService::isManagerFiler($row($rm)));
    check('T3 an ADL is not a Manager filer', !EmployeeApproverAssignmentService::isManagerFiler($row($adl)));
    $uidOf = fn(int $id) => (int)db()->query('SELECT user_id FROM employees WHERE id='.$id)->fetchColumn();
    db()->prepare('UPDATE users SET status="ACTIVE" WHERE id=?')->execute([$uidOf($rm2)]);
    db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$otherMgr, $rm]);
    $scopeMgr = TicketViewerService::scope($uidOf($otherMgr));
    check('T3 a Manager does not become the viewer of a Retail Manager (filer, no viewer above)', $scopeMgr !== null && !in_array($rm, $scopeMgr['manager_employee_ids'], true));

    // --- T4: end to end (routing and visibility) ---
    $adl2 = $mkEmp('ADL2', $adlPos, $retailDept, null);
    $route = fn(int $id) => EmployeeApproverAssignmentService::resolve(EmployeeApproverAssignmentService::employee($id), date('Y-m-d'));
    $unassigned = $route($adl);
    check('T4 an ADL with no head is still unrouted (nothing assigned automatically)', $unassigned['user_id'] === null && $unassigned['type'] === 'MANAGER', json_encode($unassigned));
    foreach ([[$adl, $rm], [$adl2, $rm2]] as [$a, $h]) db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$h, $a]);
    $r = $route($adl);
    check('T4 an ADL ticket is routed to the Retail Manager (payroll.approve_manager)', $r['user_id'] === $uidOf($rm) && $r['reason'] === null, json_encode($r));
    $r2 = $route($adl2);
    check('T4 a second Retail Manager routes only their own ADL', $r2['user_id'] === $uidOf($rm2) && $r2['user_id'] !== $r['user_id']);
    $s1 = TicketViewerService::scope($uidOf($rm));
    check('T4 Retail Manager can view their own ADL (read-only team view)', $s1 !== null && in_array($adl, $s1['manager_employee_ids'], true));
    check('T4 Retail Manager cannot view another Retail Manager\'s ADL', $s1 !== null && !in_array($adl2, $s1['manager_employee_ids'], true));
    check('T4 Retail Manager gets no branch or area scope from the role', $s1 !== null && $s1['branch_ids'] === [] && $s1['area_ids'] === []);
    $perm = fn(int $uid, string $code) => (bool)db()->query('SELECT 1 FROM users u JOIN role_permissions rp ON rp.role_id=u.role_id JOIN permissions p ON p.id=rp.permission_id WHERE u.id='.$uid." AND p.code='$code'")->fetchColumn();
    check('T4 Retail Manager account has no recruitment access', !$perm($uidOf($rm), 'recruitment.review'));
    check('T4 Retail Manager account can approve as Manager', $perm($uidOf($rm), 'payroll.approve_manager'));
    db()->prepare('UPDATE users SET status="INACTIVE" WHERE id=?')->execute([$uidOf($rm)]);
    check('T4 a Retail Manager with an inactive account no longer receives routed tickets', $route($adl)['user_id'] === null);
} catch (Throwable $e) { check('test run', false, $e->getMessage()); }
db()->rollBack();
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
