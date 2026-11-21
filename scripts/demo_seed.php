<?php
declare(strict_types=1);
/**
 * Walkthrough demo database: fictional company "Northwind Retail Group".
 *   C:\xampp\php\php.exe scripts\demo_seed.php
 * Rebuilds hris_demo from .work/clean-db-20261117/hris_clean.sql, then fills every module
 * through the app's own services (routing, steps, history and audit stay real).
 * Only ever touches hris_demo. All demo logins use password Demo@2026 (admin@hris.local keeps its own).
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
const DEMO_DB = 'hris_demo';
const DEMO_PASS = 'Demo@2026';
$root = dirname(__DIR__);
$dump = $root.'/.work/clean-db-20261117/hris_clean.sql';
if (!is_file($dump)) exit("Missing $dump\n");

$raw = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raw->exec('DROP DATABASE IF EXISTS `'.DEMO_DB.'`');
$raw->exec('CREATE DATABASE `'.DEMO_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';
exec('"'.$mysql.'" -uroot '.DEMO_DB.' < "'.str_replace('/', '\\', $dump).'"', $out, $code);
if ($code !== 0) exit("Import of hris_clean.sql failed.\n");
// hris_clean.sql predates the accounting migrations; apply them in order (each is idempotent).
foreach (glob($root.'/database/migrations/202611*_0[4-9]*.sql') ?: [] as $m) if (!str_ends_with($m, '.down.sql')) $mig[] = $m;
foreach (array_merge([$root.'/database/migrations/20261117_039_accounting_core.sql'], $mig ?? []) as $m) {
    exec('"'.$mysql.'" -uroot '.DEMO_DB.' < "'.str_replace('/', '\\', $m).'"', $out, $code);
    if ($code !== 0) exit('Migration failed: '.basename($m)."\n");
}
require $root.'/app/bootstrap.php';
echo "hris_demo rebuilt from hris_clean.sql\n";
foreach (glob($root.'/app/*.php') as $f) require_once $f;
$GLOBALS['pdo'] = new PDO('mysql:host=127.0.0.1;dbname='.DEMO_DB.';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
mt_srand(2026);

function q(string $sql, array $a = []): PDOStatement { $s = db()->prepare($sql); $s->execute($a); return $s; }
function ins(string $table, array $row): int {
    q('INSERT INTO '.$table.'('.implode(',', array_keys($row)).') VALUES('.implode(',', array_fill(0, count($row), '?')).')', array_values($row));
    return (int)db()->lastInsertId();
}
function id_of(string $table, string $code): int { return (int)q("SELECT id FROM $table WHERE code=?", [$code])->fetchColumn(); }
/** Switch the acting user the way a login would, and drop per-user static caches. */
function act_as(int $userId): void {
    $u = q('SELECT u.*,r.code role_code,r.name role_name,r.portal role_portal FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$userId])->fetch();
    $_SESSION = ['user' => ['id' => (int)$u['id'], 'role_id' => (int)$u['role_id'], 'name' => $u['full_name'], 'email' => $u['email'],
        'role_code' => $u['role_code'], 'role_name' => $u['role_name'], 'role_portal' => $u['role_portal'], 'client_id' => null, 'client_name' => null, 'ui_palette' => 'light']];
    foreach (['Auth', 'TicketRepository', 'TicketPolicy', 'TicketTypes'] as $class) {
        if (!class_exists($class)) continue;
        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC) as $p) {
            $t = $p->getType();
            if ($t && !$t->allowsNull() && $p->hasDefaultValue()) $p->setValue(null, $p->getDefaultValue());
            elseif ($p->hasDefaultValue()) $p->setValue(null, $p->getDefaultValue());
        }
    }
}
/** One-page PDF with a single line of text, for demo attachments. */
function demo_pdf(string $text): string {
    $stream = 'BT /F1 18 Tf 72 720 Td ('.addcslashes($text, '()\\').') Tj ET';
    $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $pdf = "%PDF-1.4\n"; $offsets = [];
    foreach ($objs as $n => $o) { $offsets[] = strlen($pdf); $pdf .= ($n + 1)." 0 obj\n$o\nendobj\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
    return $pdf.'trailer << /Size '.(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}
function step(string $label, callable $fn): void {
    try { $fn(); echo "  ok  $label\n"; }
    catch (Throwable $e) { echo "  !!  $label: ".$e->getMessage()."\n"; }
}
// Literal created_by/assigned_by values below use 1, so the single admin account becomes id 1.
q('SET FOREIGN_KEY_CHECKS=0'); q('UPDATE audit_logs SET user_id=1'); q('UPDATE users SET id=1'); q('SET FOREIGN_KEY_CHECKS=1');
$admin = 1;
foreach (glob($root.'/storage/payroll_requests/ptr_*_demo*.pdf') ?: [] as $old) unlink($old); // previous run's demo evidence
if (!is_dir($root.'/storage/payroll_requests')) mkdir($root.'/storage/payroll_requests', 0775, true);
act_as($admin);

/* ---------- Organisation ---------- */
$le = ['NWR' => ins('legal_entities', ['code' => 'NWR', 'name' => 'Northwind Retail Inc.']),
       'BLM' => ins('legal_entities', ['code' => 'BLM', 'name' => 'Bloom Fine Jewelry Corp.'])];
$bu = ['NWR' => ins('business_units', ['code' => 'NORTHWIND', 'name' => 'Northwind Retail Inc.', 'company_group' => 'SW', 'sort_order' => 1]),
       'BLM' => ins('business_units', ['code' => 'BLOOM', 'name' => 'Bloom Fine Jewelry Corp.', 'company_group' => 'OC', 'sort_order' => 2])];
$areas = ['N' => ins('areas', ['code' => 'NORTH', 'name' => 'Metro North']), 'S' => ins('areas', ['code' => 'SOUTH', 'name' => 'Metro South'])];
$client = ins('clients', ['code' => 'NWRG', 'name' => 'Northwind Retail Group', 'contact_email' => 'hr@northwind.demo', 'status' => 'ACTIVE']);
$branchDefs = [
    'HO'  => ['Head Office', 'HEAD_OFFICE', null, 'NWR', '8F Northwind Tower, Ortigas Center, Pasig City'],
    'QAV' => ['Quezon Avenue Store', 'RETAIL_STORE', 'N', 'NWR', 'Quezon Avenue, Quezon City'],
    'CUB' => ['Cubao Store', 'RETAIL_STORE', 'N', 'NWR', 'Araneta Center, Cubao, Quezon City'],
    'FVW' => ['Fairview Store', 'RETAIL_STORE', 'N', 'BLM', 'Regalado Avenue, Fairview, Quezon City'],
    'ALB' => ['Alabang Store', 'RETAIL_STORE', 'S', 'NWR', 'Alabang-Zapote Road, Muntinlupa City'],
    'PSG' => ['Pasig Store', 'RETAIL_STORE', 'S', 'BLM', 'C. Raymundo Avenue, Pasig City'],
    'MKT' => ['Makati Store', 'RETAIL_STORE', 'S', 'NWR', 'Ayala Avenue, Makati City'],
];
$br = [];
foreach ($branchDefs as $code => [$name, $site, $area, $co, $addr]) {
    $br[$code] = ins('branches', ['client_id' => $client, 'area_id' => $area ? $areas[$area] : null, 'default_business_unit_id' => $bu[$co],
        'default_legal_entity_id' => $le[$co], 'site_type' => $site, 'mapping_status' => 'READY', 'code' => $code, 'name' => $name, 'address_text' => $addr]);
}
$deptDefs = ['HR' => 'Human Resources', 'FIN' => 'Finance', 'ACCT' => 'Accounting', 'MIS_IT' => 'MIS / IT', 'OPS' => 'Operations', 'MKTG' => 'Marketing', 'RETAIL' => 'Retail'];
$dept = [];
foreach ($deptDefs as $c => $n) $dept[$c] = ins('departments', ['code' => $c, 'name' => $n]);
$posDefs = ['HR_MGR' => ['HR', 'HR Manager'], 'HR_TK' => ['HR', 'HR Timekeeping Officer'], 'HR_LV' => ['HR', 'HR Leave Officer'],
    'PAYROLL' => ['FIN', 'Payroll Specialist'], 'REC_MGR' => ['HR', 'Recruitment Manager'], 'RECRUITER' => ['HR', 'Recruiter'],
    'OPS_MGR' => ['OPS', 'Operations Manager'], 'FIN_OFF' => ['FIN', 'Finance Officer'], 'IT_SPEC' => ['MIS_IT', 'IT Support Specialist'],
    'ACCT_STAFF' => ['ACCT', 'Accounting Staff'], 'MKTG_ASSOC' => ['MKTG', 'Marketing Associate'], 'RET_MGR' => ['RETAIL', 'Retail Manager'],
    'ADL' => ['RETAIL', 'Area Development Leader'], 'OIC' => ['RETAIL', 'Officer-in-Charge'], 'SC' => ['RETAIL', 'Sales Captain'],
    'SA' => ['RETAIL', 'Sales Associate'], 'VM' => ['RETAIL', 'Visual Merchandiser']];
$pos = [];
foreach ($posDefs as $c => [$d, $n]) $pos[$c] = ins('positions', ['department_id' => $dept[$d], 'code' => $c, 'name' => $n]);
q('INSERT INTO payroll_settings(id,night_start,night_end) VALUES(1,"22:00:00","06:00:00") ON DUPLICATE KEY UPDATE night_start=VALUES(night_start)');
echo "  ok  organisation: 2 companies, 2 areas, 7 sites, 7 departments, ".count($pos)." positions\n";

/* ---------- People ---------- */
$role = fn(string $c) => id_of('roles', $c);
$et = fn(string $c) => id_of('employment_types', $c);
$hash = password_hash(DEMO_PASS, PASSWORD_DEFAULT);
// Accounting-only roles: no HR permissions, so the sidebar shows just Dashboard + Accounting.
foreach ([['ACCOUNTANT', 'Accountant (Bookkeeper)', ['accounting.view', 'accounting.entry']],
          ['FINANCE_HEAD', 'Finance Head', ['accounting.view', 'accounting.entry', 'accounting.approve']]] as [$rc, $rn, $perms]) {
    q('INSERT IGNORE INTO roles(code,name,portal,is_system) VALUES(?,?,"hr",0)', [$rc, $rn]);
    foreach ($perms as $pc) q('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code=? WHERE r.code=?', [$pc, $rc]);
}
$first = ['Angelo','Bea','Carlo','Dana','Enzo','Faith','Gabriel','Hannah','Ivan','Jasmine','Kyle','Lara','Marco','Nina','Oscar','Patricia','Quinn','Rafael','Sam','Trixie','Ulysses','Vince','Wendy','Xavier','Yna','Zack'];
$last = ['Abad','Bautista','Castillo','Dizon','Estrada','Flores','Gomez','Herrera','Ilagan','Jimenez','Lopez','Morales','Natividad','Ocampo','Pascual','Quiambao','Rivera','Salazar','Tan','Uy','Valdez','Yap'];
$emp = []; $users = []; $no = 1000;
/** @return int employee id */
$person = function (string $key, string $fn, string $ln, string $site, string $posCode, ?string $roleCode, ?string $mgr, string $hire, string $status = 'ACTIVE', string $type = 'REGULAR', string $gender = 'Female')
    use (&$emp, &$users, &$no, $br, $branchDefs, $bu, $le, $pos, $posDefs, $dept, $role, $et, $hash): int {
    $co = $branchDefs[$site][3]; $no++;
    $email = strtolower(str_replace(' ', '', $fn.'.'.$ln)).'@northwind.demo';
    $uid = null;
    if ($roleCode) {
        $uid = ins('users', ['role_id' => $role($roleCode), 'full_name' => "$fn $ln", 'email' => $email, 'password_hash' => $hash, 'must_change_password' => 0, 'status' => 'ACTIVE', 'password_changed_at' => date('Y-m-d H:i:s')]);
        $users[$key] = $uid;
    }
    $prob = $type === 'PROBATIONARY';
    $id = ins('employees', ['employee_no' => (string)$no, 'user_id' => $uid, 'first_name' => $fn, 'last_name' => $ln,
        'company_email' => $roleCode ? $email : null, 'personal_email' => strtolower($fn.$ln).'@mail.demo', 'mobile_no' => '0917'.str_pad((string)mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT),
        'birth_date' => sprintf('%d-%02d-%02d', mt_rand(1975, 2002), mt_rand(1, 12), mt_rand(1, 28)), 'place_of_birth' => 'Quezon City', 'gender' => $gender,
        'civil_status' => mt_rand(0, 1) ? 'Single' : 'Married', 'nationality' => 'Filipino', 'address_text' => mt_rand(10, 999).' Mabini St., Quezon City',
        'department_id' => $dept[$posDefs[$posCode][0]], 'position_id' => $pos[$posCode], 'branch_id' => $br[$site], 'employment_type_id' => $et($type),
        'manager_employee_id' => $mgr ? $emp[$mgr] : null, 'hire_date' => $hire, 'regularization_date' => $prob ? null : date('Y-m-d', strtotime($hire.' +6 months')),
        'probation_start_date' => $prob ? $hire : null, 'probation_end_date' => $prob ? date('Y-m-d', strtotime($hire.' +6 months')) : null,
        'status' => $status, 'business_unit_id' => $bu[$co], 'legal_entity_id' => $le[$co], 'directory_company_group' => $co === 'BLM' ? 'OC' : 'SW',
        'roster_group' => $site === 'HO' ? 'HO' : 'RETAIL', 'created_by' => 1]);
    q('INSERT INTO employee_employment_history(employee_id,event_type,effective_date,to_department_id,to_position_id,to_branch_id,to_employment_type_id,to_status,remarks,created_by) VALUES(?,?,?,?,?,?,?,?,?,1)',
        [$id, 'HIRED', $hire, $dept[$posDefs[$posCode][0]], $pos[$posCode], $br[$site], $et($type), $status, 'Hired']);
    q('INSERT INTO employee_placements(employee_id,branch_id,effective_from,provenance,observed_at,current_employee_id) VALUES(?,?,?,"HIRE",NOW(),?)', [$id, $br[$site], $hire, $id]);
    return $emp[$key] = $id;
};
// Head Office
$person('ops', 'Ramon', 'Villanueva', 'HO', 'OPS_MGR', 'MANAGER', null, '2018-03-12', gender: 'Male');
$person('hr', 'Maria', 'Santos', 'HO', 'HR_MGR', 'HR_USER', 'ops', '2019-06-03');
$person('tk', 'Paolo', 'Reyes', 'HO', 'HR_TK', 'HR_TIMEKEEPING', 'ops', '2020-01-15', gender: 'Male');
$person('lv', 'Andrea', 'Cruz', 'HO', 'HR_LV', 'HR_LEAVE', 'ops', '2021-04-19');
$person('pay', 'Miguel', 'Torres', 'HO', 'PAYROLL', 'PAYROLL', 'ops', '2019-09-09', gender: 'Male');
$person('recm', 'Carla', 'Mendoza', 'HO', 'REC_MGR', 'RECRUITMENT_MANAGER', 'ops', '2020-07-01');
$person('rec', 'Jerome', 'Lim', 'HO', 'RECRUITER', 'RECRUITER', 'recm', '2022-02-14', gender: 'Male');
$person('fin', 'Grace', 'Navarro', 'HO', 'FIN_OFF', 'EMPLOYEE', 'ops', '2021-08-23');
$person('it', 'Kevin', 'Bautista', 'HO', 'IT_SPEC', 'EMPLOYEE', 'ops', '2022-05-16', gender: 'Male');
$person('acct', 'Liza', 'Ramos', 'HO', 'ACCT_STAFF', 'EMPLOYEE', 'ops', '2026-07-01', 'PROBATIONARY', 'PROBATIONARY');
$person('acctnt', 'Camille', 'Mercado', 'HO', 'ACCT_STAFF', 'ACCOUNTANT', 'ops', '2020-05-04');
$person('finhead', 'Benjamin', 'Ocampo', 'HO', 'FIN_OFF', 'FINANCE_HEAD', 'ops', '2018-09-17', gender: 'Male');
$person('mktg', 'Bianca', 'Ferrer', 'HO', 'MKTG_ASSOC', 'EMPLOYEE', 'ops', '2023-10-02');
$person('retm', 'Teresa', 'Aquino', 'HO', 'RET_MGR', 'RETAIL_MANAGER', 'ops', '2017-11-06');
// Retail: one ADL per area, then each store's OIC / Sales Captain / associates
$person('adlN', 'Daniel', 'Garcia', 'QAV', 'ADL', 'ADL', 'retm', '2018-08-20', gender: 'Male');
$person('adlS', 'Sofia', 'Del Rosario', 'ALB', 'ADL', 'ADL', 'retm', '2019-02-11');
$i = 0;
foreach (['QAV' => 'adlN', 'CUB' => 'adlN', 'FVW' => 'adlN', 'ALB' => 'adlS', 'PSG' => 'adlS', 'MKT' => 'adlS'] as $site => $adl) {
    $person("oic_$site", $first[$i % 26], $last[$i % 22], $site, 'OIC', 'OIC', $adl, '2020-0'.(1 + $i % 9).'-10'); $i++;
    $person("sc_$site", $first[$i % 26], $last[$i % 22], $site, 'SC', 'SC', $adl, '2021-0'.(1 + $i % 9).'-05', gender: 'Male'); $i++;
    for ($k = 1; $k <= 3; $k++) {
        $prob = $k === 3 && in_array($site, ['QAV', 'ALB', 'PSG'], true);
        $person("sa_{$site}_$k", $first[$i % 26], $last[($i + 7) % 22], $site, $k === 2 && $site === 'MKT' ? 'VM' : 'SA', $k === 1 ? 'EMPLOYEE' : null, $adl,
            $prob ? '2026-0'.(6 + $k % 3).'-01' : '2022-'.str_pad((string)(1 + $i % 12), 2, '0', STR_PAD_LEFT).'-01', $prob ? 'PROBATIONARY' : 'ACTIVE', $prob ? 'PROBATIONARY' : 'REGULAR', $i % 2 ? 'Male' : 'Female');
        $i++;
    }
}
// A few non-active records so status filters and archive views have content.
$person('left1', 'Nestor', 'Galang', 'CUB', 'SA', null, 'adlN', '2021-03-01', 'TERMINATED', gender: 'Male');
$person('left2', 'Rowena', 'Pineda', 'MKT', 'SA', null, 'adlS', '2022-06-01', 'INACTIVE');
$person('mat', 'Joanna', 'Lacson', 'PSG', 'SA', null, 'adlS', '2022-09-01', 'ON_LEAVE', 'REGULAR');
foreach (['QAV' => 'adlN', 'CUB' => 'adlN', 'FVW' => 'adlN', 'ALB' => 'adlS', 'PSG' => 'adlS', 'MKT' => 'adlS'] as $site => $adl)
    q('INSERT INTO branch_leads(branch_id,employee_id,effective_from,assigned_by) VALUES(?,?,"2026-01-01",1)', [$br[$site], $emp[$adl]]);
// ADL scope (My Team, OIC nominations, recruitment review) comes from the directory area list.
q('UPDATE employees SET directory_role="ADL",directory_area_ids=? WHERE id=?', [(string)$areas['N'], $emp['adlN']]);
q('UPDATE employees SET directory_role="ADL",directory_area_ids=? WHERE id=?', [(string)$areas['S'], $emp['adlS']]);
echo '  ok  people: '.count($emp).' employees, '.count($users)." portal accounts\n";

// 201 details: government IDs, emergency contacts, leave credits, salary rates
$vl = id_of('leave_types', 'VL'); $sl = id_of('leave_types', 'SL');
foreach ($emp as $key => $eid) {
    foreach (['SSS' => '34-%07d-%d', 'PHILHEALTH' => '12-%09d-%d', 'PAGIBIG' => '1210-%04d-%04d', 'TIN' => '%03d-%03d-%03d-000'] as $type => $fmt)
        q('INSERT INTO employee_government_ids(employee_id,id_type,id_number,created_by) VALUES(?,?,?,1)', [$eid, $type, sprintf($fmt, mt_rand(1, 9999999), mt_rand(1, 9), mt_rand(1, 999))]);
    q('INSERT INTO employee_emergency_contacts(employee_id,name,relationship,mobile_no,is_primary,created_by) VALUES(?,?,?,?,1,1)', [$eid, $first[mt_rand(0, 25)].' '.$last[mt_rand(0, 21)], ['Spouse', 'Mother', 'Father', 'Sibling'][mt_rand(0, 3)], '0918'.mt_rand(1000000, 9999999)]);
    q('INSERT INTO employee_leave_balances(employee_id,leave_type_id,balance) VALUES(?,?,?),(?,?,?)', [$eid, $vl, mt_rand(4, 15), $eid, $sl, mt_rand(3, 15)]);
    $ho = str_starts_with($key, 'sa_') || str_starts_with($key, 'sc_') || str_starts_with($key, 'oic_') ? false : true;
    q('INSERT INTO payroll_employee_rates(employee_id,effective_from,salary_basis,salary_amount,hourly_divisor,created_by) VALUES(?,"2026-01-01",?,?,?,1)',
        $ho ? [$eid, 'MONTHLY', mt_rand(28, 85) * 1000, 174] : [$eid, 'DAILY', 645 + mt_rand(0, 6) * 25, 8]);
}
// Work schedules: Head Office approvals follow Reporting To, stores follow their area ADL.
q('INSERT INTO payroll_site_settings(branch_id,workplace,shift_start,shift_end,break_minutes,workdays,updated_by) VALUES(?,"HEAD_OFFICE","08:00:00","17:00:00",60,"1,2,3,4,5",1)', [$br['HO']]);
foreach (['QAV' => 'adlN', 'CUB' => 'adlN', 'FVW' => 'adlN', 'ALB' => 'adlS', 'PSG' => 'adlS', 'MKT' => 'adlS'] as $site => $adl)
    q('INSERT INTO payroll_site_settings(branch_id,workplace,approver_user_id,shift_start,shift_end,break_minutes,workdays,updated_by) VALUES(?,"BRANCH",?,"10:00:00","19:00:00",60,"1,2,3,4,5,6",1)', [$br[$site], $users[$adl]]);
foreach ([['2026-11-01', 'SPECIAL_HOLIDAY', "All Saints' Day"], ['2026-11-30', 'REGULAR_HOLIDAY', 'Bonifacio Day'], ['2026-12-25', 'REGULAR_HOLIDAY', 'Christmas Day'], ['2026-12-30', 'REGULAR_HOLIDAY', 'Rizal Day']] as [$d, $t, $n])
    q('INSERT INTO payroll_holidays(holiday_date,day_type,name,created_by) VALUES(?,?,?,1)', [$d, $t, $n]);
echo "  ok  201 records, leave credits, salary rates, schedules, holidays\n";

/* ---------- Attendance: two cutoffs of biometric punches ---------- */
step('attendance punches + daily review', function () use ($emp, $br, $admin) {
    $cutoffs = [['20261019-20261103', '2026-10-19', '2026-11-03', 'CLOSED'], ['20261104-20261118', '2026-11-04', '2026-11-18', 'OPEN']];
    foreach ($cutoffs as [$code, $start, $end, $status]) {
        $cid = ins('payroll_cutoffs', ['code' => $code, 'period_start' => $start, 'period_end' => $end, 'status' => 'OPEN', 'created_by' => 1]);
        $imp = ins('attendance_imports', ['cutoff_id' => $cid, 'original_name' => "biometrics_$code.csv", 'stored_name' => null, 'file_hash' => hash('sha256', $code),
            'state' => 'IMPORTED', 'row_count' => 0, 'created_by' => $admin, 'imported_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', strtotime('+48 hours'))]);
        $rows = 0; $last = min($end, date('Y-m-d', strtotime('-1 day')));
        foreach (q('SELECT e.id,e.employee_no,e.branch_id FROM employees e WHERE e.status IN ("ACTIVE","PROBATIONARY")')->fetchAll() as $e) {
            q('INSERT IGNORE INTO payroll_biometric_mappings(employee_id,biometric_id,updated_by) VALUES(?,?,1)', [$e['id'], $e['employee_no']]);
            $ho = (int)$e['branch_id'] === $br['HO'];
            for ($d = $start; $d <= $last; $d = date('Y-m-d', strtotime($d.' +1 day'))) {
                $dow = (int)date('N', strtotime($d));
                if ($dow === 7 || ($ho && $dow === 6) || $d === '2026-11-01') continue;
                if (mt_rand(1, 40) === 1) continue;                       // absent day -> shows in review
                [$in, $out] = $ho ? [8, 17] : [10, 19];
                $late = mt_rand(1, 6) === 1 ? mt_rand(5, 45) : -mt_rand(0, 12);
                $ot = mt_rand(1, 8) === 1 ? mt_rand(60, 150) : mt_rand(0, 15);
                $punches = [[$in * 60 + $late, 'TIME_IN'], [($in + 4) * 60 + mt_rand(0, 10), 'LUNCH_OUT'], [($in + 5) * 60 - mt_rand(0, 8), 'LUNCH_IN'], [$out * 60 + $ot, 'TIME_OUT']];
                if (mt_rand(1, 25) === 1) array_pop($punches);           // missing time-out -> needs a TA
                foreach ($punches as [$min, $st]) {
                    $at = sprintf('%s %02d:%02d:%02d', $d, intdiv($min, 60), $min % 60, mt_rand(0, 59));
                    $rid = ins('attendance_import_rows', ['import_id' => $imp, 'row_no' => ++$rows, 'biometric_id' => $e['employee_no'], 'punched_at' => $at, 'punch_status' => $st, 'employee_id' => $e['id'], 'row_state' => 'IMPORTED']);
                    q('INSERT IGNORE INTO attendance_punches(employee_id,punched_at,punch_status,import_row_id) VALUES(?,?,?,?)', [$e['id'], $at, $st, $rid]);
                }
            }
        }
        q('UPDATE attendance_imports SET row_count=?,source_covered_through=? WHERE id=?', [$rows, $last, $imp]);
        PayrollAttendanceService::confirmCoverage($cid, $last); // payroll confirms the logs are complete; absences settle as zero-credit days
        if ($status === 'CLOSED') q('UPDATE payroll_cutoffs SET status="CLOSED" WHERE id=?', [$cid]);
    }
});

/* ---------- Leave requests (every state) ---------- */
$leave = function (string $who, string $type, string $from, string $to, string $reason, array $decisions) use ($emp, $users) {
    step("leave $who $from", function () use ($who, $type, $from, $to, $reason, $decisions, $emp, $users) {
        act_as($users[$who]);
        $id = PayrollRepository::createLeaveRequest($emp[$who], ['leave_type_id' => id_of('leave_types', $type), 'date_from' => $from, 'date_to' => $to, 'reason' => $reason]);
        foreach ($decisions as [$actor, $decision, $remarks]) { act_as($users[$actor]); PayrollRepository::decideLeave($id, $decision, $remarks); }
    });
};
$leave('fin', 'VL', '2026-11-24', '2026-11-25', 'Family trip to Baguio.', []);
$leave('it', 'SL', '2026-11-10', '2026-11-10', 'Fever and flu, with medical certificate.', [['ops', 'APPROVE', '']]);
$leave('acct', 'VL', '2026-12-22', '2026-12-23', 'Christmas travel to the province.', [['ops', 'APPROVE', 'Enjoy.'], ['lv', 'APPROVE', 'Credits deducted.']]);
$leave('mktg', 'VL', '2026-11-27', '2026-11-28', 'Personal errands.', [['ops', 'REJECT', 'Campaign launch week; please move to December.']]);
$leave('sa_QAV_1', 'SL', '2026-11-12', '2026-11-13', 'Dental surgery.', [['adlN', 'APPROVE', ''], ['lv', 'APPROVE', '']]);
$leave('sa_ALB_1', 'VL', '2026-12-02', '2026-12-04', 'Sister\'s wedding.', [['adlS', 'APPROVE', '']]);
$leave('sa_CUB_1', 'VL', '2026-11-20', '2026-11-20', 'Renewing passport.', []);
$leave('oic_MKT', 'VL', '2026-12-28', '2026-12-29', 'Year-end family time.', [['adlS', 'RETURN', 'Please arrange a reliever OIC first.']]);

/* ---------- Payroll requests (TA / OT / OB / day off) ---------- */
$pr = function (string $who, string $type, string $date, array $data, array $decisions) use ($emp, $users) {
    step("payroll $type $who $date", function () use ($who, $type, $date, $data, $decisions, $emp, $users) {
        act_as($users[$who]);
        // CLI cannot move_uploaded_file, so lift the evidence rule for this insert and attach a demo PDF afterwards.
        $needs = (int)q('SELECT requires_attachment FROM payroll_request_types WHERE code=?', [$type])->fetchColumn();
        q('UPDATE payroll_request_types SET requires_attachment=0 WHERE code=?', [$type]);
        if ($type === 'TA') { // make the corrected punch genuinely missing first
            q('DELETE FROM attendance_punches WHERE employee_id=? AND DATE(punched_at)=? AND punch_status=?', [$emp[$who], $date, isset($data['time_in']) ? 'TIME_IN' : 'TIME_OUT']);
            PayrollAttendanceService::rebuild((int)q('SELECT id FROM payroll_cutoffs WHERE ? BETWEEN period_start AND period_end', [$date])->fetchColumn(), $emp[$who]);
        }
        try { $id = PayrollRepository::createPayrollRequest($emp[$who], ['request_type_id' => id_of('payroll_request_types', $type), 'affected_date' => $date] + $data); }
        finally { q('UPDATE payroll_request_types SET requires_attachment=? WHERE code=?', [$needs, $type]); }
        if ($needs) {
            $stored = 'ptr_'.$id.'_demo'.bin2hex(random_bytes(4)).'.pdf';
            file_put_contents(dirname(__DIR__).'/storage/payroll_requests/'.$stored, demo_pdf('Supporting evidence - '.$type.' '.$date));
            ins('payroll_request_attachments', ['request_id' => $id, 'original_name' => 'supporting-evidence.pdf', 'stored_name' => $stored, 'mime_type' => 'application/pdf', 'file_size' => filesize(dirname(__DIR__).'/storage/payroll_requests/'.$stored), 'uploaded_by' => $users[$who]]);
        }
        foreach ($decisions as [$actor, $decision, $remarks]) { act_as($users[$actor]); PayrollRepository::decidePayroll($id, $decision, $remarks); }
    });
};
$pr('fin', 'TA', '2026-11-05', ['time_out' => '17:30', 'reason' => 'Biometric did not read my time-out.'], [['tk', 'APPROVE', 'Verified with CCTV log.'], ['ops', 'APPROVE', '']]);
$pr('it', 'OT', '2026-11-06', ['ot_start' => '17:00', 'ot_end' => '20:00', 'reason' => 'Server migration after office hours.'], [['tk', 'APPROVE', '']]);
$pr('acct', 'OB', '2026-11-09', ['purpose' => 'BIR filing and bank deposit.', 'ob_time_out' => '16:30'], []);
$pr('sa_QAV_1', 'OT', '2026-11-07', ['ot_start' => '19:00', 'ot_end' => '21:30', 'reason' => 'Inventory count.'], [['tk', 'APPROVE', ''], ['adlN', 'APPROVE', '']]);
$pr('sa_ALB_1', 'TA', '2026-11-10', ['time_in' => '10:02', 'reason' => 'Forgot to clock in, opened the store with OIC.'], [['tk', 'APPROVE', 'Log reviewed.'], ['adlS', 'REJECT', 'No supporting log from the OIC.']]);
$pr('sa_CUB_1', 'CHANGE_DAY_OFF', '2026-11-14', ['reason' => 'Swap rest day with a colleague for a family event.'], []);
$pr('oic_PSG', 'OT', '2026-11-11', ['ot_start' => '19:00', 'ot_end' => '22:00', 'reason' => 'Mall-wide sale extended hours.'], [['tk', 'APPROVE', ''], ['adlS', 'APPROVE', '']]);
$pr('mktg', 'TA', '2026-11-12', ['time_in' => '08:05', 'reason' => 'Fingerprint scanner offline in the morning.'], [['tk', 'RETURN', 'Please attach the IT incident note.']]);

/* ---------- HR tickets: one of every type, spread across statuses ---------- */
$tickets = [];
$ticket = function (string $label, string $who, string $type, array $data, array $actions = []) use ($emp, $users, &$tickets) {
    step("ticket $type ($label)", function () use ($label, $who, $type, $data, $actions, $users, &$tickets) {
        act_as($users[$who]);
        $id = TicketService::create($data + ['ticket_type_id' => id_of('ticket_types', $type), 'submission_key' => bin2hex(random_bytes(32))], true);
        foreach ($actions as [$actor, $action, $remarks]) {
            act_as($users[$actor]);
            $v = (int)q('SELECT version FROM tickets WHERE id=?', [$id])->fetchColumn();
            if ($action === 'COMMENT') { ins('ticket_comments', ['ticket_id' => $id, 'author_user_id' => $users[$actor], 'body' => $remarks]); continue; }
            TicketService::act($id, $v, $action, $remarks, $action === 'COMPLETE' && $type === 'PAYROLL_CONCERN' ? 'NO_CHANGE' : '');
        }
        $tickets[$label] = $id;
    });
};
$ticket('payroll pending', 'sa_QAV_1', 'PAYROLL_CONCERN', ['concern_date' => '2026-11-07', 'explanation' => 'My OT on Nov 7 is not reflected in my payslip.']);
$ticket('payroll done', 'fin', 'PAYROLL_CONCERN', ['concern_date' => '2026-10-28', 'explanation' => 'Night differential looks short for Oct 28.'], [['hr', 'START', 'Checking with Payroll.'], ['hr', 'COMPLETE', 'Rates were correct; computation explained to employee.']]);
$ticket('atm new', 'sa_ALB_1', 'ATM_REQUEST', ['reason' => 'Lost my payroll ATM card, need a replacement.']);
$ticket('atm done', 'it', 'ATM_REQUEST', ['reason' => 'ATM card expired this month.'], [['hr', 'START', ''], ['hr', 'COMPLETE', 'New card ready for pickup at HR.']]);
$ticket('id', 'acct', 'ID_REQUEST', ['reason' => 'New hire company ID.'], [['hr', 'START', 'Photo taken, printing.']]);
$ticket('id rejected', 'sa_CUB_1', 'ID_REQUEST', ['reason' => 'Want a second ID.'], [['hr', 'REJECT', 'Only one active ID per employee.']]);
$ticket('uniform', 'sa_PSG_1', 'UNIFORM_REQUEST', ['position' => 'Sales Associate', 'size' => 'M', 'company' => 'OFJI', 'branch' => (string)$br['PSG'], 'quantity' => '2', 'id_lace' => 'YES'], [['hr', 'START', ''], ['hr', 'COMPLETE', 'Released 2 sets.']]);
$ticket('uniform new', 'sa_MKT_1', 'UNIFORM_REQUEST', ['position' => 'Sales Associate', 'size' => 'S', 'company' => 'SW', 'branch' => (string)$br['MKT'], 'quantity' => '3', 'id_lace' => 'NO']);
$ticket('netsuite', 'oic_QAV', 'NETSUITE_CONCERN', ['concern' => 'Cannot post the Nov 10 sales invoice in NetSuite; error "period closed".']);
$ticket('manpower', 'adlN', 'MANPOWER_REQUEST', ['section_branch' => 'Cubao Store', 'position_code' => 'SC', 'employment_type' => 'Probationary', 'request_type' => 'Replacement', 'quantity' => '1',
    'replacement_of_employee_id' => (string)$emp['sa_CUB_3'], 'job_description_qualifications' => "Leads the sales floor, opens and closes the store.\nAt least 1 year retail experience.", 'area_department' => 'Metro North / Retail']);
$ticket('manpower 2', 'adlS', 'MANPOWER_REQUEST', ['section_branch' => 'Makati Store', 'position_code' => 'BS', 'employment_type' => 'Seasonal', 'request_type' => 'Additional', 'quantity' => '3',
    'replacement_of_employee_id' => '', 'job_description_qualifications' => "Holiday season sales support.\nCustomer-friendly, can work weekends.", 'area_department' => 'Metro South / Retail']);
$ticket('transfer', 'hr', 'BRANCH_TRANSFER', ['employee' => (string)$emp['sa_CUB_2'], 'from_branch' => (string)$br['CUB'], 'to_branch' => (string)$br['FVW'], 'effective_date' => '2026-12-01', 'company' => 'SW']);
$ticket('incident', 'oic_ALB', 'INCIDENT_REPORT', ['title' => 'Customer slipped near the entrance', 'explanation' => 'A customer slipped on the wet floor near the entrance at around 3 PM. First aid was given; no serious injury.',
    'incident_at' => '2026-11-13T15:05', 'location' => 'Alabang Store, main entrance', 'nature' => 'NEGLIGENCE', 'location_type' => 'BRANCH_SITE', 'involved_names' => 'Store utility staff on duty', 'witness_names' => 'Two sales associates at the counter', 'initial_action' => 'Wet floor sign placed; first aid given; customer signed the incident slip.', 'supporting' => ['CCTV', 'WRITTEN_STATEMENTS']]);
$ticket('incident 2', 'oic_CUB', 'INCIDENT_REPORT', ['title' => 'Missing display item', 'explanation' => 'One silver bracelet missing from display case 3 during the afternoon count.',
    'incident_at' => '2026-11-15T16:30', 'location' => 'Cubao Store, display case 3', 'nature' => 'LOSS', 'location_type' => 'BRANCH_SITE', 'involved_names' => '', 'witness_names' => 'Sales Captain on duty', 'initial_action' => 'Store locked down, inventory recounted, mall security informed.', 'supporting' => ['CCTV', 'TRANSACTION_RECORDS']]);
$ticket('oic', 'hr', 'OIC_AUTHORIZATION', ['employee' => (string)$emp['sa_MKT_1'], 'branch' => (string)$br['MKT'], 'reason' => 'Covering while the store OIC is on year-end leave.', 'start_date' => '2026-12-28', 'end_date' => '2026-12-29']);
$ticket('coe', 'it', 'COE_REQUEST', ['purpose' => 'Bank', 'purpose_other' => '', 'needed_by' => '2026-11-28', 'delivery' => 'Email']);
$ticket('coe visa', 'fin', 'COE_REQUEST', ['purpose' => 'Visa/Embassy', 'purpose_other' => '', 'needed_by' => '2026-12-05', 'delivery' => 'HR pickup'], [['hr', 'START', 'Preparing certificate.']]);
$ticket('portal', 'sa_FVW_1', 'PORTAL_ACCOUNT_REQUEST', ['concern' => 'Forgot password', 'remarks' => 'Locked out after changing phones.'], [['hr', 'START', ''], ['hr', 'COMPLETE', 'Temporary password sent to company email.']]);
$ticket('returned', 'mktg', 'ATM_REQUEST', ['reason' => 'Need ATM.'], [['hr', 'RETURN', 'Please state if this is new, lost or expired.']]);
step('ticket comments', function () use ($tickets, $users) {
    foreach ([['payroll pending', 'sa_QAV_1', 'Attached my approved OT form for reference.'], ['netsuite', 'hr', 'Forwarded to Finance systems team.'], ['atm new', 'hr', 'Please bring a valid ID when you claim the card.']] as [$t, $who, $body])
        if (isset($tickets[$t])) ins('ticket_comments', ['ticket_id' => $tickets[$t], 'author_user_id' => $users[$who], 'body' => $body]);
});

/* ---------- Recruitment: jobs, applicants at every stage ---------- */
step('recruitment pipeline', function () use ($users, $dept, $br, $client, &$tickets) {
    act_as($users['hr']);
    if (isset($tickets['manpower'])) {
        $v = (int)q('SELECT version FROM tickets WHERE id=?', [$tickets['manpower']])->fetchColumn();
        ManpowerIntake::approve($tickets['manpower'], $v, $client, 'URGENT', 'Approved for posting.', 'Sales Captain');
    }
    act_as($users['recm']);
    $jobs = [
        ['Sales Associate', 'RETAIL', 'Assist customers, keep displays tidy and hit monthly sales targets.', "High school graduate or higher\nGood communication skills\nWilling to work weekends", 'Metro North stores', 'PROBATIONARY', 4, 16000, 18500, [$br['QAV'], $br['CUB'], $br['FVW']], 1],
        ['Payroll Specialist', 'HEAD_OFFICE', 'Run semi-monthly payroll, statutory remittances and payroll reports.', "BS Accountancy or related\n2+ years payroll experience", 'Head Office, Pasig', 'REGULAR', 1, 30000, 38000, [], 0],
        ['IT Support Specialist', 'HEAD_OFFICE', 'Support store POS, network and laptops across all branches.', "IT graduate\nCan travel to stores", 'Head Office, Pasig', 'REGULAR', 1, 25000, 32000, [], 0],
        ['Visual Merchandiser', 'RETAIL', 'Design and maintain window and in-store displays.', "Portfolio of displays\nCreative and detail-oriented", 'Metro South stores', 'PROBATIONARY', 2, 18000, 22000, [$br['ALB'], $br['MKT']], 0],
    ];
    $jobIds = [];
    foreach ($jobs as [$title, $site, $desc, $req, $loc, $etype, $open, $min, $max, $branches, $featured]) {
        $id = JobOpenings::save(['client_id' => $client, 'department_id' => $site === 'RETAIL' ? $dept['RETAIL'] : ($title === 'Payroll Specialist' ? $dept['FIN'] : $dept['MIS_IT']), 'title' => $title, 'description' => $desc, 'requirements' => $req,
            'location_text' => $loc, 'employment_type' => $etype, 'openings' => $open, 'salary_min' => $min, 'salary_max' => $max, 'closes_at' => '2026-12-31', 'is_featured' => $featured, 'site_type' => $site, 'branch_ids' => $branches]);
        JobOpenings::publish($id); $jobIds[] = $id;
    }
    $jobIds[] = JobOpenings::save(['client_id' => $client, 'department_id' => $dept['MKTG'], 'title' => 'Social Media Associate', 'description' => 'Plan and post content for our brands.', 'requirements' => 'Portfolio required',
        'location_text' => 'Head Office, Pasig', 'employment_type' => 'REGULAR', 'openings' => 1, 'salary_min' => 22000, 'salary_max' => 26000, 'closes_at' => '2026-12-31', 'is_featured' => 0, 'site_type' => 'HEAD_OFFICE']); // stays a draft
    $names = [['Alyssa', 'Mercado'], ['Bryan', 'Cortez'], ['Camille', 'Santiago'], ['Dennis', 'Aguilar'], ['Elaine', 'Robles'], ['Francis', 'Velasco'], ['Gwen', 'Sarmiento'], ['Harold', 'Manalo'],
        ['Irene', 'Padilla'], ['Jake', 'Soriano'], ['Kristine', 'Lazaro'], ['Leo', 'Medina'], ['Mae', 'Domingo'], ['Noel', 'Fajardo'], ['Olive', 'Gatchalian'], ['Peter', 'Hizon']];
    // [job index, final stage]
    $plan = [[0, 'SCREENING'], [0, 'SCREENING'], [0, 'INTERVIEW'], [0, 'ENDORSED'], [0, 'OFFER'], [0, 'REJECTED'], [1, 'SCREENING'], [1, 'INTERVIEW'], [1, 'CLIENT_REVIEW'],
        [2, 'SCREENING'], [2, 'INTERVIEW'], [2, 'WITHDRAWN'], [3, 'SCREENING'], [3, 'INTERVIEW'], [3, 'ON_HOLD'], [0, 'DEPLOYMENT']];
    $order = ['SCREENING', 'INTERVIEW', 'ENDORSED', 'CLIENT_REVIEW', 'OFFER', 'DEPLOYMENT'];
    act_as($users['rec']);
    foreach ($plan as $n => [$job, $final]) {
        [$fn, $ln] = $names[$n];
        $ref = RecruitmentRepository::createApplication(['first_name' => $fn, 'last_name' => $ln, 'email' => strtolower("$fn.$ln").'@mail.demo', 'mobile_no' => '0927'.mt_rand(1000000, 9999999),
            'job_opening_id' => $jobIds[$job], 'why_fit' => 'I have '.mt_rand(1, 6).' years of related experience and live near the work site.'], null);
        $app = (int)q('SELECT id FROM applications WHERE application_no=?', [$ref])->fetchColumn();
        q('UPDATE applications SET applied_at=DATE_SUB(NOW(),INTERVAL ? DAY),assigned_recruiter_id=? WHERE id=?', [mt_rand(2, 25), $users['rec'], $app]);
        if (in_array($final, ['REJECTED', 'WITHDRAWN', 'ON_HOLD'], true)) { RecruitmentRepository::moveStage($app, $final, $final === 'REJECTED' ? 'Did not meet the minimum requirements.' : ($final === 'WITHDRAWN' ? 'Accepted another offer.' : 'Waiting for the next hiring batch.')); continue; }
        for ($s = 1; $s <= array_search($final, $order, true); $s++) {
            if ($order[$s] === 'INTERVIEW') { RecruitmentRepository::scheduleInterview($app, ['interview_type' => 'Initial interview', 'scheduled_at' => date('Y-m-d', strtotime('+'.mt_rand(1, 6).' days')).' 10:00:00', 'location_or_link' => 'Head Office, 8F Conference Room', 'notes' => 'Bring a valid ID and resume.']); continue; }
            RecruitmentRepository::moveStage($app, $order[$s], 'Moved to '.ucwords(strtolower(str_replace('_', ' ', $order[$s]))).'.');
        }
    }
});

/* ---------- Employee portal content ---------- */
step('portal FAQs + notifications', function () use ($emp, $users) {
    foreach ([['How do I file a leave?', 'Open My Requests, choose Leave, pick the dates and submit. Your manager approves first, then HR.'],
              ['When is payday?', 'Every 10th and 25th of the month, for the cutoff that ended about a week before.'],
              ['I forgot my password. What should I do?', 'File a Portal account request from the sign-in page, or ask your OIC to file it for you.'],
              ['How do I correct a missing time-in or time-out?', 'File a Time Adjustment (TA) for that date before the cutoff deadline.']] as $n => [$qq, $a])
        ins('portal_faqs', ['question' => $qq, 'answer' => $a, 'sort_order' => $n + 1, 'active' => 1, 'created_by' => 1]);
    foreach (['fin', 'it', 'sa_QAV_1', 'hr'] as $who)
        ins('notifications', ['recipient_employee_id' => $emp[$who], 'recipient_user_id' => $users[$who], 'kind' => 'HR_REQUEST', 'title' => 'Welcome to the employee portal',
            'body' => 'Your 201 file, payslips and requests are now in one place.', 'link' => '/index.php', 'dedupe_key' => 'demo-welcome-'.$who]);
});

/* ---------- Accounting: three months of sample books ---------- */
// Accountant prepares, admin approves: segregation of duties stays visible. Payroll role has no accounting access.
step('accounting journals', function () use ($users, $admin) {
    $acc = q('SELECT code,id FROM acct_accounts')->fetchAll(PDO::FETCH_KEY_PAIR);
    $prep = $users['acctnt'];
    $je = function (string $date, string $memo, array $lines, string $end = 'POSTED', string $note = '') use ($acc, $prep, $admin): int {
        act_as($prep);
        $rows = array_map(fn($l) => ['account_id' => $acc[$l[0]], 'description' => $l[3] ?? '', 'debit' => $l[1] ?: '', 'credit' => $l[2] ?: ''], $lines);
        $id = Accounting::saveDraft(0, $date, $memo, $rows, $prep);
        if ($end === 'DRAFT') return $id;
        Accounting::submit($id);
        if ($end === 'RETURNED') { act_as($admin); Accounting::returnToDraft($id, $note); return $id; }
        if ($end === 'POSTED') { act_as($admin); Accounting::approve($id, $admin); }
        return $id;
    };
    $m = fn(int $back, int $day) => date('Y-m-', strtotime(date('Y-m-01')." -$back month")).sprintf('%02d', $day);
    $je($m(2, 1), 'Opening capital contribution', [['1020', '2500000', 0, 'Deposit to savings'], ['3010', 0, '2500000']]);
    $je($m(2, 2), 'Transfer to payroll account', [['1030', '600000', 0], ['1020', 0, '600000']]);
    $je($m(2, 3), 'Office equipment purchase (laptops, printers)', [['1500', '185000', 0], ['1020', 0, '185000']]);
    foreach ([2, 1, 0] as $b) {
        $je($m($b, 5), 'Office rent for '.date('F', strtotime($m($b, 1))), [['5100', '85000', 0], ['1020', 0, '85000']]);
        $je($m($b, 12), 'Electricity and internet', [['5110', '18450.75', 0], ['1020', 0, '18450.75']]);
        $je($m($b, 15), 'Store management services billed', [['1100', '420000', 0], ['4010', 0, '420000']]);
        if ($b > 0) $je($m($b, 25), 'Collection from client', [['1020', '380000', 0], ['1100', 0, '380000']]);
        // Sample payroll totals typed as a normal entry; the HRIS does not compute peso payroll yet.
        $je($m($b, 26), 'Payroll for '.date('F', strtotime($m($b, 1))).' (sample totals)', [
            ['5010', '310000', 0, 'Gross pay'], ['5020', '28500', 0, 'Employer share'],
            ['2100', 0, '27900', 'SSS EE+ER'], ['2110', 0, '15500', 'PhilHealth EE+ER'], ['2120', 0, '4000', 'Pag-IBIG EE+ER'],
            ['2130', 0, '21600', 'Withholding tax'], ['1030', 0, '269500', 'Net pay released']], $b === 0 ? 'FOR_APPROVAL' : 'POSTED');
    }
    $je($m(1, 18), 'Office supplies', [['5120', '7820.50', 0], ['1010', 0, '7820.50']]);
    $bad = $je($m(1, 20), 'Transportation reimbursements', [['5140', '4600', 0], ['1010', 0, '4600']]);
    act_as($admin); Accounting::reverse($bad, $m(1, 22), $admin); // recorded twice by mistake; reversal waits for approval
    $je($m(0, 8), 'Office supplies (receipt pending)', [['5120', '3150', 0], ['1010', 0, '3150']], 'RETURNED', 'Attach the official receipt number in the memo.');
    $je($m(0, 10), 'Petty cash replenishment', [['1010', '15000', 0]], 'DRAFT');
    $dup = $je($m(0, 9), 'Electricity (entered twice)', [['5110', '18450.75', 0], ['1020', 0, '18450.75']], 'DRAFT');
    Accounting::void($dup, 'Duplicate of the electricity entry already posted.', $prep);
    act_as($admin); Accounting::closeMonth($m(2, 1), $admin);
});

/* ---------- Accounting Phase 2: vendors/bills, customers/invoices, payments, bank recon, remittances ---------- */
step('accounting AP/AR/cash/remittances', function () use ($users, $admin) {
    $acc = q('SELECT code,id FROM acct_accounts')->fetchAll(PDO::FETCH_KEY_PAIR);
    $prep = $users['acctnt'];
    $m = fn(int $back, int $day) => date('Y-m-', strtotime(date('Y-m-01')." -$back month")).sprintf('%02d', $day);
    $entry = fn(string $t, int $id) => (int)q("SELECT entry_id FROM $t WHERE id=?", [$id])->fetchColumn();
    $post = function (int $entryId) use ($prep, $admin) { act_as($prep); Accounting::submit($entryId); act_as($admin); Accounting::approve($entryId, $admin); };
    act_as($admin);
    $savings = CashBank::saveBank(0, 'Operating account', 'Sample Bank', '0000-1111-22', $acc['1020'], true);
    $payroll = CashBank::saveBank(0, 'Payroll account', 'Sample Bank', '0000-3333-44', $acc['1030'], true);
    act_as($prep);
    $v1 = AccountsPayable::saveVendor(0, 'Metro Office Supplies Co.', '000-111-222-000', 'orders@metro-office.demo', 30, true);
    $v2 = AccountsPayable::saveVendor(0, 'Brightline Utilities', '000-333-444-000', 'billing@brightline.demo', 15, true);
    $c1 = AccountsReceivable::saveCustomer(0, 'Harbor Mart Inc.', '000-555-666-000', 'ap@harbormart.demo', 30, true);
    $c2 = AccountsReceivable::saveCustomer(0, 'Summit Foods Corp.', '000-777-888-000', 'finance@summitfoods.demo', 45, true);
    $bills = [];
    foreach ([[$v1, 'MOS-1042', $m(1, 6), '5120', '12450.00'], [$v2, 'BU-7781', $m(1, 9), '5110', '21300.50'], [$v1, 'MOS-1088', $m(0, 3), '5120', '8900.00'], [$v2, 'BU-7802', $m(0, 7), '5110', '19880.25']] as $i => [$v, $ref, $d, $code, $amt]) {
        act_as($prep);
        $bills[$i] = AccountsPayable::saveBill(0, $v, $ref, $d, '', '', [['account_id' => $acc[$code], 'description' => '', 'amount' => $amt]], $prep);
        if ($i < 3) $post($entry('acct_bills', $bills[$i])); // last bill stays a draft
    }
    $inv = [];
    foreach ([[$c1, $m(1, 4), '185000.00'], [$c2, $m(1, 14), '240000.00'], [$c1, $m(0, 2), '195000.00']] as $i => [$c, $d, $amt]) {
        act_as($prep);
        $inv[$i] = AccountsReceivable::saveInvoice(0, $c, '', $d, '', 'Store management services', [['account_id' => $acc['4010'], 'description' => '', 'amount' => $amt]], $prep);
        $post($entry('acct_invoices', $inv[$i]));
    }
    act_as($prep);
    $dv = CashBank::savePayment('DISBURSEMENT', 0, $savings, $v1, $m(1, 28), 'CHK-20015', '', [$bills[0] => '12450.00'], $prep);
    $post($entry('acct_payments', $dv));
    act_as($prep);
    $cr = CashBank::savePayment('COLLECTION', 0, $savings, $c1, $m(1, 30), 'OR-5521', '', [$inv[0] => '100000.00'], $prep); // partial collection
    $post($entry('acct_payments', $cr));
    foreach (['SSS' => ['2100', '27900'], 'PHILHEALTH' => ['2110', '15500'], 'PAGIBIG' => ['2120', '4000']] as $ag => [$code, $amt]) {
        act_as($prep);
        $r = Remittances::save(0, $ag, substr($m(2, 1), 0, 7), $payroll, $m(1, 10), "$ag-REF-".substr($m(2, 1), 0, 7), '', [['account_id' => $acc[$code], 'amount' => $amt]], $prep);
        $post($entry('acct_remittances', $r));
    }
    // Reconcile the operating account for the oldest month: tick every posted line, statement = their total.
    act_as($prep);
    $data = CashBank::reconData(CashBank::bank($savings), $m(2, 1));
    $sum = array_sum(array_map(fn($l) => (int)round($l['debit'] * 100) - (int)round($l['credit'] * 100), $data['lines']));
    CashBank::finishRecon($savings, substr($m(2, 1), 0, 7), number_format($sum / 100, 2, '.', ''), array_column($data['lines'], 'id'), $prep);
});

/* ---------- Accounting Phase 3: fixed assets (048) ---------- */
step('accounting fixed assets', function () use ($users, $admin) {
    $acc = q('SELECT code,id FROM acct_accounts')->fetchAll(PDO::FETCH_KEY_PAIR);
    $prep = $users['acctnt'];
    $m = fn(int $back, int $day) => date('Y-m-', strtotime(date('Y-m-01')." -$back month")).sprintf('%02d', $day);
    $gl = ['asset_account_id' => $acc['1500'], 'accum_account_id' => $acc['1510'], 'expense_account_id' => $acc['5130'], 'salvage' => '0'];
    act_as($prep);
    FixedAssets::save(0, ['name' => 'Laptops (12 units)', 'acquired_on' => $m(2, 3), 'cost' => '150000', 'life_months' => '36'] + $gl, $prep);
    FixedAssets::save(0, ['name' => 'Office printers', 'acquired_on' => $m(2, 3), 'cost' => '35000', 'life_months' => '60', 'salvage' => '5000'] + $gl, $prep);
    $run = FixedAssets::run(substr($m(1, 1), 0, 7), $prep); // last month; this month is left for the demo button
    Accounting::submit($run); act_as($admin); Accounting::approve($run, $admin);
});

/* ---------- Accounting Phase 3: settings and SAMPLE tax codes (049). Demo only: real rates are to be confirmed by the client. ---------- */
step('accounting settings + sample VAT/EWT', function () use ($users, $admin) {
    $prep = $users['acctnt'];
    $m = fn(int $back, int $day) => date('Y-m-', strtotime(date('Y-m-01')." -$back month")).sprintf('%02d', $day);
    act_as($admin);
    // The 039 sample chart already has 1300 Input VAT, 2200 Output VAT and 2140 EWT Payable; only Salaries Payable is new.
    if (!AccountingSetup::accountByCode('2150')) Accounting::saveAccount(0, '2150', 'Salaries Payable', 'L', true);
    AccountingSetup::saveSettings(['tax.vat_input' => '1300', 'tax.vat_output' => '2200', 'tax.ewt' => '2140', 'payroll.net_to' => 'bank', 'payroll.payable' => '2150'], $admin);
    $vat = AccountingSetup::saveCode(0, 'VAT', 'VAT-S', 'Sample VAT rate (to confirm)', '12', true);
    $ewt = AccountingSetup::saveCode(0, 'EWT', 'EWT-S', 'Sample supplier withholding (to confirm)', '2', true);
    $acc = q('SELECT code,id FROM acct_accounts')->fetchAll(PDO::FETCH_KEY_PAIR);
    $vendor = (int)q("SELECT id FROM acct_vendors WHERE name='Metro Office Supplies Co.'")->fetchColumn();
    $customer = (int)q("SELECT id FROM acct_customers WHERE name='Summit Foods Corp.'")->fetchColumn();
    act_as($prep);
    $b = AccountsPayable::saveBill(0, $vendor, 'MOS-1101', $m(0, 5), '', 'Printer toner', [['account_id' => $acc['5120'], 'description' => '', 'amount' => '15000']], $prep, $vat, $ewt);
    $i = AccountsReceivable::saveInvoice(0, $customer, '', $m(0, 6), '', 'Store management services', [['account_id' => $acc['4010'], 'description' => '', 'amount' => '120000']], $prep, $vat);
    foreach ([(int)AccountsPayable::bill($b)['entry_id'], (int)AccountsReceivable::invoice($i)['entry_id']] as $e) { act_as($prep); Accounting::submit($e); act_as($admin); Accounting::approve($e, $admin); }
});

act_as($admin);
echo "\nSummary\n";
foreach (['employees', 'users', 'attendance_daily', 'leave_requests', 'payroll_requests', 'tickets', 'job_openings', 'applications', 'interviews'] as $t)
    printf("  %-18s %d\n", $t, (int)db()->query("SELECT COUNT(*) FROM $t")->fetchColumn());
echo "\nDemo logins (password ".DEMO_PASS."):\n";
foreach (q('SELECT u.email,r.name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email LIKE "%@northwind.demo" ORDER BY r.portal,r.name LIMIT 40')->fetchAll() as $u) printf("  %-40s %s\n", $u['email'], $u['name']);

// Demo addresses are fictional: mark queued e-mails as already handled so the notification runner never sends them.
q('UPDATE ticket_notification_outbox SET delivered_at=NOW() WHERE delivered_at IS NULL');
q('UPDATE notifications SET emailed_at=NOW() WHERE email_to IS NOT NULL AND emailed_at IS NULL');
