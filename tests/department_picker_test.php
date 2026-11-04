<?php
declare(strict_types=1);

/**
 * Department + Position picker (SPEC-department-picker.md). Read-only integration test against a SCRATCH copy of the database:
 *   HRIS_TEST_DB=hris_slice15_test_YYYYMMDD_HHMMSS C:\xampp\php\php.exe tests\department_picker_test.php
 * Refuses the live database. Writes nothing.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/DepartmentPicker.php';
$GLOBALS['pdo'] = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (db()->query('SELECT DATABASE()')->fetchColumn() !== $dbName) exit(2);

$fail = 0; $pass = 0;
function check(string $name, bool $ok, string $detail = ''): void { global $fail, $pass; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === '' ? '' : ' -> '.$detail)."\n"; }
function throws(callable $fn): ?string { try { $fn(); return null; } catch (Throwable $e) { return $e->getMessage(); } }
function deptId(string $name): int { $q = db()->prepare('SELECT id FROM departments WHERE name=? ORDER BY active DESC LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); }
function posId(string $name, string $dept): int { $q = db()->prepare('SELECT p.id FROM positions p JOIN departments d ON d.id=p.department_id WHERE p.name=? AND d.name=? LIMIT 1'); $q->execute([$name, $dept]); return (int)$q->fetchColumn(); }

// T1 group list is exactly John's list, in order
check('T1 groups are the agreed list', array_values(DepartmentPicker::GROUPS) === ['Manpower Planning','Inventory Management','Finance','HR','Retail','Marketing & Creatives','Online'], json_encode(array_values(DepartmentPicker::GROUPS)));

// T2 mapping of stored departments (same Directory rules)
$expect = ['Manpower Planning'=>'MANPOWER','Admin'=>'MANPOWER','MIS / IT'=>'MANPOWER','PMMD'=>'MANPOWER','Inventory Management'=>'INVENTORY','Production'=>'INVENTORY','Stock Control'=>'INVENTORY','Supply & Demand'=>'INVENTORY','Ho Supply And Demand'=>'INVENTORY',
    'Finance'=>'FINANCE','Accounting'=>'FINANCE','Billing & Collection'=>'FINANCE','Ho Billing And Collection'=>'FINANCE','Audit'=>'FINANCE','Treasury'=>'FINANCE','HR'=>'HR','Retail'=>'RETAIL','Retail & Development'=>'RETAIL','Ho Retail And Development'=>'RETAIL',
    'Marketing'=>'MARKETING','Digital Marketing'=>'MARKETING','Creative'=>'MARKETING','Online Shop'=>'ONLINE','Online'=>'ONLINE'];
$bad = []; foreach ($expect as $n => $g) if (DepartmentPicker::groupOf($n) !== $g) $bad[] = $n.'='.DepartmentPicker::groupOf($n);
check('T2 stored department names map to the agreed groups', !$bad, implode(', ', $bad));
check('T2 an unknown department is in no group', DepartmentPicker::groupOf('PMBSI Office') === '');

// T3 every ACTIVE department is in a group; every active position with a department is reachable
$ungrouped = []; foreach (FoundationRepository::departments() as $d) if ((int)$d['active'] && !str_starts_with((string)$d['name'], 'Zz ') && DepartmentPicker::groupOf((string)$d['name']) === '') $ungrouped[] = $d['name']; // 'Zz ' = fixtures left by other tests
check('T3 every active department belongs to a picker group', !$ungrouped, implode(', ', $ungrouped));
$tree = DepartmentPicker::tree(); $listed = [];
foreach ($tree as $g) foreach ($g['teams'] as $rows) foreach ($rows as $r) $listed[$r['id']] = true;
$missing = []; foreach (FoundationRepository::positions() as $p) if ((int)$p['active'] && $p['department_id'] !== null && DepartmentPicker::groupOf((string)$p['department_name']) !== '' && !isset($listed[(int)$p['id']])) $missing[] = $p['name'];
check('T3 every active position of a grouped department is listed under a group', !$missing, implode(', ', $missing));
$payroll = posId('Payroll', 'PMBSI Office'); $keepTree = DepartmentPicker::tree($payroll, 26);
check('T3 an ungrouped current department stays selectable with its position (KEEP:26)', isset($keepTree['KEEP:26']) && isset(array_column(array_merge(...array_values($keepTree['KEEP:26']['teams'])), 'id', 'id')[$payroll]));
check('T3 the KEEP group is absent for everyone else', !isset($tree['KEEP:26']));
$rmId = posId('Retail Manager', 'Retail');
check('T3 Retail Manager is listed under Retail only', $rmId > 0 && isset(array_column($tree['RETAIL']['teams']['Retail'] ?? [], 'id', 'id')[$rmId]) && !isset(array_column(array_merge(...array_values($tree['MANPOWER']['teams'] ?: [[]])), 'id', 'id')[$rmId]));
$mp = array_keys($tree['MANPOWER']['teams']);
check('T3 Manpower Planning teams match the Directory', in_array('Administration', $mp, true) && in_array('Information System / MIS', $mp, true) && in_array('PMMD', $mp, true), implode(' | ', $mp));
$adminPos = array_column($tree['MANPOWER']['teams']['Administration'] ?? [], 'name');
check('T3 Administration lists Admin Staff and Project Coordinator', in_array('Admin Staff', $adminPos, true) && in_array('Project Coordinator', $adminPos, true), implode(', ', $adminPos));
check('T3 HR group is the PMBSI company, the others 2LOG', $tree['HR']['company'] === 'PMBSI' && $tree['RETAIL']['company'] === '2LOG' && $tree['FINANCE']['company'] === '2LOG', $tree['HR']['company'].'/'.$tree['RETAIL']['company']);

// T4 server rule (legacy callers unchanged, group derives the department, mismatch names both)
check('T4 no department_group key: posted department_id is used as before', DepartmentPicker::departmentId(['department_id' => '9', 'position_id' => '8']) === 9);
check('T4 empty group falls back to department_id', DepartmentPicker::departmentId(['department_group' => '', 'department_id' => '9']) === 9);
check('T4 Admin Staff under Manpower derives department Admin', DepartmentPicker::departmentId(['department_group' => 'MANPOWER', 'position_id' => (string)posId('Admin Staff', 'Admin')]) === deptId('Admin'));
check('T4 Retail Manager under Retail derives department Retail', DepartmentPicker::departmentId(['department_group' => 'RETAIL', 'position_id' => (string)$rmId]) === deptId('Retail'));
$m = throws(fn() => DepartmentPicker::departmentId(['department_group' => 'MANPOWER', 'position_id' => (string)$rmId]));
check('T4 Retail Manager under Manpower is rejected naming both', $m !== null && str_contains($m, 'Retail Manager') && str_contains($m, 'Retail') && str_contains($m, 'Manpower Planning'), (string)$m);
check('T4 no position: the group\'s own department (Finance -> Finance)', DepartmentPicker::departmentId(['department_group' => 'FINANCE']) === deptId('Finance'));
check('T4 no position: Retail -> Retail, Manpower -> Manpower Planning', DepartmentPicker::departmentId(['department_group' => 'RETAIL']) === deptId('Retail') && DepartmentPicker::departmentId(['department_group' => 'MANPOWER']) === deptId('Manpower Planning'));
check('T4 an invalid group is rejected', throws(fn() => DepartmentPicker::departmentId(['department_group' => 'NOPE'])) !== null);
check('T4 KEEP:<id> keeps an ungrouped current department', DepartmentPicker::departmentId(['department_group' => 'KEEP:26']) === 26);
check('T4 an unknown position is rejected', throws(fn() => DepartmentPicker::departmentId(['department_group' => 'FINANCE', 'position_id' => '99999999'])) !== null);

// T5 no-op save of every existing employee: stored department never moves
$moved = []; $checked = 0;
$rows = db()->query('SELECT e.id,e.department_id,e.position_id,d.name dname FROM employees e JOIN departments d ON d.id=e.department_id')->fetchAll();
foreach ($rows as $r) {
    $g = DepartmentPicker::groupOf((string)$r['dname']);
    $post = ['department_group' => $g !== '' ? $g : 'KEEP:'.$r['department_id'], 'position_id' => (string)($r['position_id'] ?? 0)];
    $checked++;
    try { $got = DepartmentPicker::departmentId($post, ['department_id' => $r['department_id'], 'position_id' => $r['position_id']]); }
    catch (Throwable $e) { $got = -1; }
    if ($got !== (int)$r['department_id']) $moved[] = $r['id'].':'.$r['department_id'].'->'.$got;
}
check('T5 no-op save leaves department unchanged for all '.$checked.' employees', !$moved, implode(', ', array_slice($moved, 0, 10)));

// T6 the Directory mapping itself is untouched (picker is separate)
check('T6 Directory mapping unchanged', EmployeeDirectoryHierarchy::department('Retail')['main'] === '' && EmployeeDirectoryHierarchy::department('Admin')['main'] === 'MANPOWER' && count(EmployeeDirectoryHierarchy::groups()) === 5);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
