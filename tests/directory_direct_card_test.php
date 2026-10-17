<?php
declare(strict_types=1);

/**
 * Head Office team grid: the synthetic "<Main> employees" card shows only while someone is assigned directly. Run on a SCRATCH copy.
 *
 *   mysql -uroot -e "DROP DATABASE IF EXISTS pmbsi_hris_test; CREATE DATABASE pmbsi_hris_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 *   mysqldump -uroot --single-transaction --routines --triggers pmbsi_hris | mysql -uroot pmbsi_hris_test
 *   HRIS_TEST_DB=pmbsi_hris_test C:\xampp\php\php.exe tests\directory_direct_card_test.php
 *   mysql -uroot -e "DROP DATABASE pmbsi_hris_test"
 *
 * Refuses to run on the live database. Data changes happen inside one transaction that is rolled back. No names are printed.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }

$fail = 0; $pass = 0;
function check(string $name, bool $ok, string $detail = ''): void { global $fail, $pass; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === '' ? '' : ' -> '.$detail)."\n"; }

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/EmployeeDirectoryModule.php';
$GLOBALS['pdo'] = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (db()->query('SELECT DATABASE()')->fetchColumn() !== $dbName) exit(2);

$role = db()->query("SELECT id,name,portal,code FROM roles WHERE code='SUPER_ADMIN'")->fetch();
$_SESSION['user'] = ['id' => 1, 'role_id' => (int)$role['id'], 'name' => 'Test', 'email' => 'test@example.test', 'role_code' => $role['code'], 'role_name' => $role['name'], 'role_portal' => $role['portal'], 'client_id' => null, 'client_name' => null];
Auth::clearPermissionCache();

function pageOf(string $group, string $main): array { return EmployeeDirectoryService::page(['group' => $group, 'main_department' => $main]); }
function titles(array $page): array { return array_column($page['cards'], 'title'); }

$mains = ['FINANCE' => 'Finance', 'INVENTORY' => 'Inventory Management', 'MANPOWER' => 'Manpower Planning'];
$before = [];
foreach (['SW_HO', 'OC_HO'] as $g) foreach ($mains as $code => $name) $before[$g][$code] = pageOf($g, $code);
$allCounts = EmployeeDirectoryService::page(['group' => 'ALL'])['counts'];

// 1. no direct employees -> no "<Main> employees" card, in every group and main department
foreach ($before as $g => $perMain) foreach ($perMain as $code => $p) {
    $direct = array_filter($p['departments'], static fn($d) => $d['main'] === $code && $d['display_name'] === $mains[$code] && $d['count'] > 0);
    $cards = array_filter(titles($p), static fn($t) => $t === $mains[$code].' employees');
    check("$g/$code: direct card only when direct count > 0", (bool)$direct === (bool)$cards, implode(',', titles($p)));
}
$inv = $before['SW_HO']['INVENTORY'];
check('INVENTORY (SW_HO): the 3 real teams remain', titles($inv) === ['Production', 'Stock Management', 'Supply and Demand'], implode(',', titles($inv)));
check('INVENTORY (SW_HO): badge count is 3 teams', count($inv['cards']) === 3 && $inv['grid_label'] === 'teams');

// 2. direct employee -> card appears with the right count; other departments unaffected
db()->beginTransaction();
try {
    $deptId = (int)db()->query("SELECT id FROM departments WHERE REPLACE(UPPER(name),' ','')='INVENTORYMANAGEMENT' LIMIT 1")->fetchColumn();
    $emp = (int)db()->query("SELECT e.id FROM employees e JOIN branches b ON b.id=e.branch_id JOIN departments d ON d.id=e.department_id WHERE e.status='ACTIVE' AND (b.site_type='HEAD_OFFICE' OR UPPER(b.name)='HEAD OFFICE') AND (UPPER(d.name) LIKE '%PRODUCTION%' OR UPPER(d.name) LIKE '%STOCK%') AND e.id NOT IN (SELECT id FROM employees WHERE position_id IN (SELECT id FROM positions WHERE UPPER(name) LIKE '%MANAGER%')) LIMIT 1")->fetchColumn();
    check('fixture found (a Head Office employee in an Inventory team)', $deptId > 0 && $emp > 0);
    $group = 'SW_HO';
    foreach (['SW_HO', 'OC_HO'] as $g) { $r = EmployeeDirectoryService::page(['group' => $g, 'view' => 'list', 'main_department' => 'INVENTORY']); foreach ($r['rows'] as $row) if ((int)$row['id'] === $emp) $group = $g; }
    $beforeTeams = array_column(pageOf($group, 'INVENTORY')['cards'], 'count', 'title');
    db()->prepare('UPDATE employees SET department_id=? WHERE id=?')->execute([$deptId, $emp]);
    $afterP = pageOf($group, 'INVENTORY');
    $afterTeams = array_column($afterP['cards'], 'count', 'title');
    check('direct employee -> card appears', isset($afterTeams['Inventory Management employees']), implode(',', array_keys($afterTeams)));
    check('direct card count is 1', ($afterTeams['Inventory Management employees'] ?? -1) === 1);
    check('badge count is 4 teams while the direct card shows', count($afterP['cards']) === 4);
    check('team counts stay consistent (employee moved, not lost)', array_sum($afterTeams) === array_sum($beforeTeams));
    foreach (['FINANCE', 'MANPOWER'] as $code) check("other department $code unaffected", titles(pageOf($group, $code)) === titles($before[$group][$code]));
    $reachable = false;
    foreach ($afterP['cards'] as $c) if ($c['title'] === 'Inventory Management employees') {
        $r = EmployeeDirectoryService::page($c['params'] + ['view' => 'list']);
        foreach ($r['rows'] as $row) if ((int)$row['id'] === $emp) $reachable = true;
    }
    check('direct employee is reachable from the card', $reachable);
} finally { db()->rollBack(); }

// 3. totals unchanged
check('All employees total and group counts unchanged after rollback', EmployeeDirectoryService::page(['group' => 'ALL'])['counts'] === $allCounts, json_encode($allCounts));
check('per-team counts unchanged after rollback', array_column(pageOf('SW_HO', 'INVENTORY')['cards'], 'count', 'title') === array_column($inv['cards'], 'count', 'title'));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
