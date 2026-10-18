<?php
declare(strict_types=1);

/**
 * Check Attendance slot placement + rebuild after every import. Integration test on a SCRATCH copy of the database.
 *
 * 1. Scratch copy (Git Bash):
 *      mysql -uroot -e "DROP DATABASE IF EXISTS pmbsi_hris_test; CREATE DATABASE pmbsi_hris_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 *      mysqldump -uroot --single-transaction --routines --triggers pmbsi_hris | mysql -uroot pmbsi_hris_test
 * 2. Run:
 *      HRIS_TEST_DB=pmbsi_hris_test C:\xampp\php\php.exe tests\attendance_slots_test.php
 *
 * Refuses to run on the live database. Part 2 writes to the scratch copy (re-runnable; drop it afterwards: mysql -uroot -e "DROP DATABASE pmbsi_hris_test").
 * Part 1 (pure AttendanceSlotDisplay) needs no database. Part 2 drives the real commitImport() and
 * EmployeeAttendanceService::cutoff() for the first employee that has an account and a biometric mapping.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/EmployeeAttendanceViews.php';
$GLOBALS['pdo'] = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (db()->query('SELECT DATABASE()')->fetchColumn() !== $dbName) exit(2);

$fail = 0; $pass = 0;
function check(string $name, bool $ok, string $detail = ''): void { global $fail, $pass; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === '' ? '' : ' -> '.$detail)."\n"; }
function p(string $at, string $status = 'IN', int $id = 0): array { return ['id'=>$id, 'punched_at'=>$at, 'punch_status'=>$status]; }
function slotTimes(array $slots): string { return implode('|', array_map(static fn($v) => $v ? substr($v, 11, 5) : '-', $slots)); }

// ---------- Part 1: pure placement ----------
$d = '2026-10-05 ';
check('0 punches: all slots empty', slotTimes(AttendanceSlotDisplay::assign([])['slots']) === '-|-|-|-');
check('1 punch: Time In only', slotTimes(AttendanceSlotDisplay::assign([p($d.'09:06')])['slots']) === '09:06|-|-|-');
$two = AttendanceSlotDisplay::assign([p($d.'09:06'), p($d.'13:10', 'OUT')]);
check('2 punches (Oct 5 case): Time In, Break Out; rest Missing', slotTimes($two['slots']) === '09:06|13:10|-|-' && !$two['extra']);
check('3 punches: first three slots', slotTimes(AttendanceSlotDisplay::assign([p($d.'09:18'), p($d.'13:40', 'OUT'), p($d.'14:33')])['slots']) === '09:18|13:40|14:33|-');
check('4 punches: all slots', slotTimes(AttendanceSlotDisplay::assign([p($d.'09:00'), p($d.'12:00', 'OUT'), p($d.'13:00'), p($d.'18:00', 'OUT')])['slots']) === '09:00|12:00|13:00|18:00');
check('input order does not matter (reverse import order)', slotTimes(AttendanceSlotDisplay::assign([p($d.'18:00', 'OUT', 4), p($d.'13:00', 'IN', 3), p($d.'12:00', 'OUT', 2), p($d.'09:00', 'IN', 1)])['slots']) === '09:00|12:00|13:00|18:00');
$five = AttendanceSlotDisplay::assign([p($d.'09:00'), p($d.'09:01'), p($d.'12:00', 'OUT'), p($d.'13:00'), p($d.'18:00', 'OUT')]);
check('5 punches: flagged extra, nothing guessed', $five['extra'] && $five['count'] === 5 && slotTimes($five['slots']) === '-|-|-|-');
check('explicit TIME_OUT status goes to Time Out', slotTimes(AttendanceSlotDisplay::assign([p($d.'09:00', 'TIME_IN'), p($d.'18:00', 'TIME_OUT')])['slots']) === '09:00|-|-|18:00');
check('conflicting explicit statuses fall back to time order', slotTimes(AttendanceSlotDisplay::assign([p($d.'09:00', 'TIME_OUT'), p($d.'10:00', 'TIME_IN')])['slots']) === '09:00|10:00|-|-');
$night = AttendanceSlotDisplay::assign([p('2026-10-06 02:05', 'OUT', 2), p('2026-10-05 22:01', 'IN', 1)]);
check('night shift: order across midnight is by timestamp', slotTimes($night['slots']) === '22:01|02:05|-|-' && substr((string)$night['slots']['lunch_out'], 0, 10) === '2026-10-06');

// ---------- Part 2: real imports on the scratch DB ----------
function one(string $sql, array $args = []) { $q = db()->prepare($sql); $q->execute($args); $v = $q->fetchColumn(); return $v === false ? null : $v; }
function actAs(string $roleCode, int $userId): void {
    $r = db()->prepare('SELECT id,name,portal FROM roles WHERE code=?'); $r->execute([$roleCode]); $role = $r->fetch();
    $_SESSION['user'] = ['id' => $userId, 'role_id' => (int)$role['id'], 'name' => 'Test', 'email' => 'test@example.test', 'role_code' => $roleCode, 'role_name' => $role['name'], 'role_portal' => $role['portal'], 'client_id' => null, 'client_name' => null];
    Auth::clearPermissionCache();
}
/** Stage rows as a PREVIEW file under $cutoff and commit it through the real service. */
function importFile(int $cutoff, string $bio, array $rows): int {
    static $n = 0; $n++;
    db()->prepare('INSERT INTO attendance_imports(cutoff_id,branch_id,original_name,stored_name,file_hash,row_count,created_by,expires_at) VALUES(?,NULL,?,NULL,?,?,?,DATE_ADD(NOW(),INTERVAL 2 HOUR))')
        ->execute([$cutoff, 'slot-test-'.$n.'.pdf', hash('sha256', 'slot-test-'.$n.microtime()), count($rows), (int)Auth::user()['id']]);
    $id = (int)db()->lastInsertId(); $ins = db()->prepare('INSERT INTO attendance_import_rows(import_id,row_no,biometric_id,punched_at,punch_status,row_state) VALUES(?,?,?,?,?,"READY")');
    foreach ($rows as $i => [$at, $status]) $ins->execute([$id, $i + 1, $bio, $at, $status]);
    PayrollAttendanceService::commitImport($id, '');
    return $id;
}
function punchCount(int $emp, string $date): int { return (int)one('SELECT COUNT(*) FROM attendance_punches WHERE employee_id=? AND punched_at>=? AND punched_at<DATE_ADD(?,INTERVAL 1 DAY)', [$emp, $date, $date]); }
function dayModel(string $date): array {
    $model = EmployeeAttendanceService::cutoff(47);
    foreach ($model['days'] as $day) if ($day['date'] === $date) return $day;
    return [];
}
function describe(array $day): string { return slotTimes($day['values'] + array_fill_keys(['time_in','lunch_out','lunch_in','time_out'], null)).' / '.employee_attendance_day_status($day)[0]; }

// commitImport() opens its own transaction, so this part really writes: it is only ever run on the scratch copy.
try {
    $emp = db()->query('SELECT e.id,e.employee_no,e.user_id,m.biometric_id FROM employees e JOIN payroll_biometric_mappings m ON m.employee_id=e.id WHERE e.user_id IS NOT NULL ORDER BY (e.employee_no=\'17845\') DESC, e.id LIMIT 1')->fetch();
    if (!$emp) throw new RuntimeException('No employee with an account and a biometric mapping in the scratch copy.');
    $empId = (int)$emp['id']; $bio = $emp['biometric_id']; $user = (int)$emp['user_id'];
    $cutoff = (int)one("SELECT id FROM payroll_cutoffs WHERE period_start='2026-10-04'"); $otherCutoff = (int)one("SELECT id FROM payroll_cutoffs WHERE period_end<'2026-10-04' ORDER BY period_end DESC LIMIT 1");
    if (!$cutoff || !$otherCutoff) throw new RuntimeException('Cutoff fixtures missing in the scratch copy.');
    db()->prepare('DELETE FROM attendance_punches WHERE employee_id=? AND punched_at>=?')->execute([$empId, '2026-10-08']);
    db()->prepare('UPDATE payroll_cutoff_runs SET state="DRAFT" WHERE cutoff_id=?')->execute([$cutoff]); // scenario H leaves it FINALIZED on a previous run

    actAs('PAYROLL', $user);
    // A (user's case): file 1 holds only IN/OUT of Oct 8; file 2 holds the rest.
    importFile($cutoff, $bio, [['2026-10-08 09:06:00', 'IN'], ['2026-10-08 13:10:00', 'OUT']]);
    actAs('EMPLOYEE', $user); $day = dayModel('2026-10-08');
    check('A after file 1: Time In + Break Out shown, rest Missing, Check TA', describe($day) === '09:06|13:10|-|- / Check TA', describe($day));
    actAs('PAYROLL', $user);
    importFile($cutoff, $bio, [['2026-10-08 09:06:00', 'IN'], ['2026-10-08 14:00:00', 'IN'], ['2026-10-08 18:00:00', 'OUT']]);
    actAs('EMPLOYEE', $user); $day = dayModel('2026-10-08');
    check('A after file 2: four slots, Complete, no refresh needed', describe($day) === '09:06|13:10|14:00|18:00 / Complete', describe($day));
    check('A re-sent punch (09:06 IN) did not double', punchCount($empId, '2026-10-08') === 4, (string)punchCount($empId, '2026-10-08'));
    check('A attendance_daily rebuilt to COMPLETE', one('SELECT state FROM attendance_daily WHERE employee_id=? AND work_date=?', [$empId, '2026-10-08']) === 'COMPLETE');

    // B: reverse order (second file first).
    actAs('PAYROLL', $user);
    importFile($cutoff, $bio, [['2026-10-09 14:00:00', 'IN'], ['2026-10-09 18:00:00', 'OUT']]);
    actAs('EMPLOYEE', $user); $day = dayModel('2026-10-09');
    check('B file 2 first: shows Time In + Break Out as Check TA', describe($day) === '14:00|18:00|-|- / Check TA', describe($day));
    actAs('PAYROLL', $user);
    importFile($cutoff, $bio, [['2026-10-09 09:06:00', 'IN'], ['2026-10-09 13:10:00', 'OUT']]);
    actAs('EMPLOYEE', $user); $day = dayModel('2026-10-09');
    check('B after the earlier file: same final four slots', describe($day) === '09:06|13:10|14:00|18:00 / Complete', describe($day));

    // C/D/E/F: 3 punches, 1 punch, 5 punches, no punches.
    actAs('PAYROLL', $user);
    importFile($cutoff, $bio, [['2026-10-10 09:18:00', 'IN'], ['2026-10-10 13:40:00', 'OUT'], ['2026-10-10 14:33:00', 'IN'],
        ['2026-10-11 09:30:00', 'IN'],
        ['2026-10-12 09:00:00', 'IN'], ['2026-10-12 09:01:00', 'IN'], ['2026-10-12 12:00:00', 'OUT'], ['2026-10-12 13:00:00', 'IN'], ['2026-10-12 18:00:00', 'OUT']]);
    actAs('EMPLOYEE', $user);
    $day = dayModel('2026-10-10'); check('C 3 punches: three slots, Time Out Missing, Check TA', describe($day) === '09:18|13:40|14:33|- / Check TA', describe($day));
    $day = dayModel('2026-10-11'); check('D 1 punch: Time In only, Check TA', describe($day) === '09:30|-|-|- / Check TA', describe($day));
    $day = dayModel('2026-10-12');
    check('E 5 punches: flagged, nothing hidden, all five listed', !empty($day['extra_punches']) && count($day['raw']) === 5 && employee_attendance_day_status($day)[0] === 'Check TA', describe($day).' raw='.count($day['raw']));
    $day = dayModel('2026-10-13'); check('F no punches: No logs yet', employee_attendance_day_status($day)[0] === 'No logs yet', describe($day));

    // G: second file filed under a different (earlier) cutoff still completes the day in the cutoff that owns it.
    actAs('PAYROLL', $user);
    importFile($cutoff, $bio, [['2026-10-14 09:00:00', 'IN'], ['2026-10-14 12:00:00', 'OUT']]);
    importFile($otherCutoff, $bio, [['2026-10-14 13:00:00', 'IN'], ['2026-10-14 18:00:00', 'OUT']]);
    actAs('EMPLOYEE', $user); $day = dayModel('2026-10-14');
    check('G file 2 filed under another cutoff: Oct 14 still completes', describe($day) === '09:00|12:00|13:00|18:00 / Complete', describe($day));

    check('G attendance_daily itself was rebuilt (not just the display)', one('SELECT state FROM attendance_daily WHERE employee_id=? AND work_date=?', [$empId, '2026-10-14']) === 'COMPLETE');

    // H: a finalized cutoff is never rewritten.
    db()->prepare('INSERT INTO payroll_cutoff_runs(cutoff_id,state) VALUES(?,"FINALIZED") ON DUPLICATE KEY UPDATE state="FINALIZED"')->execute([$cutoff]);
    $before = one('SELECT state FROM attendance_daily WHERE employee_id=? AND work_date=?', [$empId, '2026-10-15']);
    actAs('PAYROLL', $user);
    importFile($otherCutoff, $bio, [['2026-10-15 09:00:00', 'IN']]);
    check('H finalized cutoff left unchanged', one('SELECT state FROM attendance_daily WHERE employee_id=? AND work_date=?', [$empId, '2026-10-15']) === $before);
} catch (Throwable $e) {
    $fail++; echo 'FAIL setup/run -> '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine()."\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
