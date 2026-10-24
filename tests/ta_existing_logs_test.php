<?php
declare(strict_types=1);

/**
 * File TA form uses the same existing logs as Check Attendance (plan 2026-10-25-ta-form-existing-logs).
 * Runs only on the isolated scratch copy:
 *   php scripts/ticket_stageb_database.php prepare <n>   (prints TEST_DATABASE=...)
 *   HRIS_TEST_DB=hris_slice15_test_... php tests/ta_existing_logs_test.php
 * Prints "N passed, M failed". Writes only to the scratch copy.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if (!preg_match('/^hris_slice15_test_\d{8}_\d{6}$/', $dbName)) { fwrite(STDERR, "Set HRIS_TEST_DB to the isolated Slice-15 database.\n"); exit(2); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
$GLOBALS['pdo'] = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (db()->query('SELECT DATABASE()')->fetchColumn() !== $dbName) exit(2);

$fail = 0; $pass = 0;
function check(string $name, bool $ok, string $detail = ''): void { global $fail, $pass; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ').$name.($ok || $detail === '' ? '' : ' -> '.$detail)."\n"; }
function one(string $sql, array $args = []) { $q = db()->prepare($sql); $q->execute($args); $v = $q->fetchColumn(); return $v === false ? null : $v; }
function actAs(string $roleCode, int $userId): void {
    $r = db()->prepare('SELECT id,name,portal FROM roles WHERE code=?'); $r->execute([$roleCode]); $role = $r->fetch();
    $_SESSION['user'] = ['id' => $userId, 'role_id' => (int)$role['id'], 'name' => 'Test', 'email' => 'test@example.test', 'role_code' => $roleCode, 'role_name' => $role['name'], 'role_portal' => $role['portal'], 'client_id' => null, 'client_name' => null];
    Auth::clearPermissionCache();
}
function importFile(int $cutoff, string $bio, array $rows): void {
    static $n = 0; $n++;
    db()->prepare('INSERT INTO attendance_imports(cutoff_id,branch_id,original_name,stored_name,file_hash,row_count,created_by,expires_at) VALUES(?,NULL,?,NULL,?,?,?,DATE_ADD(NOW(),INTERVAL 2 HOUR))')
        ->execute([$cutoff, 'ta-logs-test-'.$n.'.pdf', hash('sha256', 'ta-logs-test-'.$n.microtime()), count($rows), (int)Auth::user()['id']]);
    $id = (int)db()->lastInsertId(); $ins = db()->prepare('INSERT INTO attendance_import_rows(import_id,row_no,biometric_id,punched_at,punch_status,row_state) VALUES(?,?,?,?,?,"READY")');
    foreach ($rows as $i => [$at, $status]) $ins->execute([$id, $i + 1, $bio, $at, $status]);
    PayrollAttendanceService::commitImport($id, '');
}
/** "HH:MM|HH:MM|-|-" for a requestTimes()/cutoff() values array. */
function shown(array $values): string { return implode('|', array_map(static fn($f) => !empty($values[$f]) ? substr((string)$values[$f], 11, 5) : '-', PayrollCalculator::FIELDS)); }
function dailySnapshot(int $emp): string { return md5(json_encode(db()->query('SELECT * FROM attendance_daily WHERE employee_id='.$emp.' ORDER BY id')->fetchAll())); }
function checkDay(string $date): array { foreach (EmployeeAttendanceService::cutoff(47)['days'] as $day) if ($day['date'] === $date) return $day; return []; }
function missingLabels(array $ctx): string { return implode(',', $ctx['missing']); }

try {
    $emp = db()->query('SELECT e.id,e.user_id,m.biometric_id FROM employees e JOIN payroll_biometric_mappings m ON m.employee_id=e.id WHERE e.employee_no=\'17845\' AND e.user_id IS NOT NULL')->fetch()
        ?: db()->query('SELECT e.id,e.user_id,m.biometric_id FROM employees e JOIN payroll_biometric_mappings m ON m.employee_id=e.id WHERE e.user_id IS NOT NULL ORDER BY e.id LIMIT 1')->fetch();
    if (!$emp) throw new RuntimeException('No employee with an account and a biometric mapping in the scratch copy.');
    $empId = (int)$emp['id']; $bio = (string)$emp['biometric_id']; $user = (int)$emp['user_id'];
    $cutoff = 47;
    if ((string)one('SELECT period_start FROM payroll_cutoffs WHERE id=?', [$cutoff]) !== '2026-10-04') throw new RuntimeException('Cutoff 47 fixture missing.');
    // Clean slate for this employee in the scratch copy only.
    db()->prepare('DELETE FROM payroll_request_applications WHERE request_id IN (SELECT id FROM payroll_requests WHERE employee_id=? AND affected_date BETWEEN "2026-10-04" AND "2026-10-18")')->execute([$empId]);
    db()->prepare('DELETE FROM payroll_requests WHERE employee_id=? AND affected_date BETWEEN "2026-10-04" AND "2026-10-18"')->execute([$empId]);
    db()->prepare('DELETE FROM attendance_punches WHERE employee_id=? AND punched_at>="2026-10-04" AND punched_at<"2026-10-20"')->execute([$empId]);
    db()->prepare('DELETE FROM attendance_daily WHERE employee_id=? AND cutoff_id=?')->execute([$empId, $cutoff]);
    db()->prepare('UPDATE payroll_cutoff_runs SET state="DRAFT" WHERE cutoff_id=?')->execute([$cutoff]);

    actAs('PAYROLL', $user);
    importFile($cutoff, $bio, [
        ['2026-10-12 12:17:00', 'IN'], ['2026-10-12 17:08:00', 'OUT'],                                   // the reported case: 2 ambiguous
        ['2026-10-13 09:30:00', 'IN'],                                                                    // 1 punch
        ['2026-10-14 09:18:00', 'IN'], ['2026-10-14 13:40:00', 'OUT'], ['2026-10-14 14:33:00', 'IN'],     // 3 punches
        ['2026-10-15 09:00:00', 'IN'], ['2026-10-15 12:00:00', 'OUT'], ['2026-10-15 13:00:00', 'IN'], ['2026-10-15 18:00:00', 'OUT'], // 4 punches
        ['2026-10-16 09:00:00', 'IN'], ['2026-10-16 09:01:00', 'IN'], ['2026-10-16 12:00:00', 'OUT'], ['2026-10-16 13:00:00', 'IN'], ['2026-10-16 18:00:00', 'OUT'], // 5 punches
        ['2026-10-17 12:17:00', 'IN'], ['2026-10-17 17:08:00', 'OUT'],                                     // 2 ambiguous + approved TA
    ]);
    actAs('EMPLOYEE', $user);
    $dailyBefore = dailySnapshot($empId);
    $punchesBefore = (int)one('SELECT COUNT(*) FROM attendance_punches WHERE employee_id=?', [$empId]);
    $assignBefore = json_encode(PayrollCalculator::assign([['punched_at'=>'2026-10-12 12:17:00','punch_status'=>'IN'],['punched_at'=>'2026-10-12 17:08:00','punch_status'=>'OUT']], []));

    // 0 punches
    $c = EmployeeAttendanceService::requestTimes('2026-10-05', $cutoff);
    check('0 punches: nothing shown, all four missing', shown($c['values']) === '-|-|-|-' && count($c['missing']) === 4, shown($c['values']));

    // 1 punch: same as Check Attendance
    $c = EmployeeAttendanceService::requestTimes('2026-10-13', $cutoff); $d = checkDay('2026-10-13');
    check('1 punch: Time In shown, three missing', shown($c['values']) === '09:30|-|-|-' && missingLabels($c) === 'lunch_out,lunch_in,time_out', shown($c['values']).' / '.missingLabels($c));
    check('1 punch: form values equal Check Attendance values', shown($c['values']) === shown($d['values']), shown($d['values']));

    // 2 ambiguous punches: the reported case
    $c = EmployeeAttendanceService::requestTimes('2026-10-12', $cutoff); $d = checkDay('2026-10-12');
    check('2 ambiguous punches: Time In 12:17 and Break Out 17:08 shown', shown($c['values']) === '12:17|17:08|-|-', shown($c['values']));
    check('2 ambiguous punches: only Break In and Time Out are missing', missingLabels($c) === 'lunch_in,time_out', missingLabels($c));
    check('2 ambiguous punches: form values equal Check Attendance values', shown($c['values']) === shown($d['values']), shown($d['values']));

    // 3 punches
    $c = EmployeeAttendanceService::requestTimes('2026-10-14', $cutoff); $d = checkDay('2026-10-14');
    check('3 punches: three shown, only Time Out missing', shown($c['values']) === '09:18|13:40|14:33|-' && missingLabels($c) === 'time_out', shown($c['values']).' / '.missingLabels($c));
    check('3 punches: form values equal Check Attendance values', shown($c['values']) === shown($d['values']), shown($d['values']));

    // 4 punches: nothing to file
    $c = EmployeeAttendanceService::requestTimes('2026-10-15', $cutoff);
    check('4 punches: all four shown, nothing missing', shown($c['values']) === '09:00|12:00|13:00|18:00' && $c['missing'] === [], shown($c['values']));

    // 5 punches: nothing guessed
    $c = EmployeeAttendanceService::requestTimes('2026-10-16', $cutoff);
    check('5 punches: nothing invented, all four stay missing', shown($c['values']) === '-|-|-|-' && count($c['missing']) === 4, shown($c['values']));

    // Submit guard (scratch copy only: evidence upload is not what is under test here)
    db()->exec('UPDATE payroll_request_types SET requires_attachment=0 WHERE code="TA"');
    $taType = (int)one('SELECT id FROM payroll_request_types WHERE code="TA"');
    $submit = static fn(string $date, array $times) => PayrollRepository::createPayrollRequest($empId, ['request_type_id'=>$taType,'affected_date'=>$date,'cutoff_id'=>$cutoff,'reason'=>'Test']+$times);
    try {
        $id = $submit('2026-10-12', ['time_in'=>'08:00','lunch_out'=>'12:00','lunch_in'=>'13:00','time_out'=>'18:00']);
        $row = db()->prepare('SELECT time_in,lunch_out,lunch_in,time_out FROM payroll_request_time_entries WHERE request_id=?'); $row->execute([$id]); $row = $row->fetch();
        check('submit on 2-punch day: existing slots dropped, only Break In and Time Out saved', $row['time_in'] === null && $row['lunch_out'] === null && substr((string)$row['lunch_in'], 0, 5) === '13:00' && substr((string)$row['time_out'], 0, 5) === '18:00', json_encode($row));
    } catch (Throwable $e) { check('submit on 2-punch day saves missing only', false, $e->getMessage()); }
    try { $submit('2026-10-15', ['time_in'=>'08:00','time_out'=>'19:00']); check('submit on 4-punch day is refused', false, 'accepted'); }
    catch (RuntimeException $e) { check('submit on 4-punch day is refused', str_contains($e->getMessage(), 'already saved'), $e->getMessage()); }
    try { $submit('2026-10-13', ['time_in'=>'08:00']); check('submit that only re-types an existing slot is refused', false, 'accepted'); }
    catch (RuntimeException $e) { check('submit that only re-types an existing slot is refused', str_contains($e->getMessage(), 'already saved'), $e->getMessage()); }

    // Approved TA day: Oct 17 has the same two ambiguous punches plus an approved TA for Break In / Time Out
    $rn = 'TEST-TA-'.bin2hex(random_bytes(3));
    db()->prepare('INSERT INTO payroll_requests(request_no,employee_id,request_type_id,cutoff_id,affected_date,status,current_step,reason,submitted_at,created_by) VALUES(?,?,?,?,?,"APPROVED",3,"Test",NOW(),?)')->execute([$rn, $empId, $taType, $cutoff, '2026-10-17', $user]);
    $rid = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO payroll_request_time_entries(request_id,lunch_in,time_out) VALUES(?,?,?)')->execute([$rid, '18:00:00', '19:00:00']);
    db()->prepare('INSERT INTO payroll_request_approvals(request_id,step_no,approver_type,approver_user_id,status,acted_by,acted_at) VALUES(?,1,"MANAGER",?,"APPROVED",?,NOW())')->execute([$rid, $user, $user]);
    PayrollAttendanceService::afterApproval($rid);
    $c = EmployeeAttendanceService::requestTimes('2026-10-17', $cutoff); $d = checkDay('2026-10-17');
    check('approved TA day: same four values as Check Attendance, nothing missing', shown($c['values']) === shown($d['values']) && $c['missing'] === [] && shown($c['values']) !== '-|-|-|-', shown($c['values']).' vs '.shown($d['values']));
    check('approved TA day: device punches keep their slots (12:17 / 17:08)', substr((string)$c['values']['time_in'], 11, 5) === '12:17' && substr((string)$c['values']['lunch_out'], 11, 5) === '17:08', shown($c['values']));

    // Read-only and payroll safety
    $submitRows = (int)one('SELECT COUNT(*) FROM payroll_requests WHERE employee_id=?', [$empId]);
    $dailyMid = dailySnapshot($empId);
    EmployeeAttendanceService::requestTimes('2026-10-12', $cutoff); EmployeeAttendanceService::requestTimes('2026-10-13', $cutoff);
    check('requestTimes writes nothing to attendance_daily', dailySnapshot($empId) === $dailyMid);
    check('punch count unchanged by filing and context reads', (int)one('SELECT COUNT(*) FROM attendance_punches WHERE employee_id=?', [$empId]) === $punchesBefore);
    check('PayrollCalculator::assign still returns null originals for the ambiguous pair', json_encode(PayrollCalculator::assign([['punched_at'=>'2026-10-12 12:17:00','punch_status'=>'IN'],['punched_at'=>'2026-10-12 17:08:00','punch_status'=>'OUT']], [])) === $assignBefore);
    check('attendance_daily for days without a TA ticket is unchanged by filing a TA', (string)one('SELECT original_time_in FROM attendance_daily WHERE employee_id=? AND work_date="2026-10-12"', [$empId]) === '' );
} catch (Throwable $e) {
    $fail++; echo 'FAIL setup/run -> '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine()."\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
