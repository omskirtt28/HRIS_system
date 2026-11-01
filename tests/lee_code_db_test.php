<?php
declare(strict_types=1);

/**
 * Lee code on the HR employment update, against a SCRATCH copy of the database (copy steps: header of employee_profile_db_test.php).
 *   HRIS_TEST_DB=pmbsi_hris_test C:\xampp\php\php.exe tests\lee_code_db_test.php
 * Refuses the live database. Everything runs inside one transaction that is rolled back at the end.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
$GLOBALS['pdo'] = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (db()->query('SELECT DATABASE()')->fetchColumn() !== $dbName) exit(2);

$fail = 0;
function check(string $label, bool $ok, string $why = ''): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ').$label.($ok || $why === '' ? '' : '  ['.$why.']')."\n"; if (!$ok) $fail++; }
$_SESSION['user'] = ['id' => 9, 'role_code' => 'HR_USER', 'role_portal' => 'hr'];

$emp = db()->query('SELECT id FROM employees WHERE user_id IS NOT NULL ORDER BY id LIMIT 1')->fetchColumn();
if (!$emp) { fwrite(STDERR, "Need an employee in the scratch copy.\n"); exit(2); }
$emp = (int)$emp;
$lee = fn(): ?string => ($v = db()->query('SELECT lee_code FROM employees WHERE id='.$emp)->fetchColumn()) === null || $v === false ? null : (string)$v;
$attempt = function (array $data) use ($emp): ?string { try { EmployeeRepository::updateEmployment($emp, $data); return null; } catch (Throwable $e) { return $e->getMessage(); } };

db()->beginTransaction();
try {
    check('column exists in the scratch copy', FoundationRepository::columnExists('employees', 'lee_code'));
    check('save a value (trimmed)', $attempt(['lee_code' => '  LEE-1234  ']) === null && $lee() === 'LEE-1234');
    check('exactly 30 characters is accepted', $attempt(['lee_code' => str_repeat('a', 30)]) === null && $lee() === str_repeat('a', 30));
    $err = $attempt(['lee_code' => str_repeat('b', 31)]);
    check('31 characters is rejected with a clear message and the old value stays', $err !== null && str_contains($err, 'at most 30') && $lee() === str_repeat('a', 30), (string)$err);
    check('a save that does not post lee_code leaves it alone', $attempt(['remarks' => 'no lee field']) === null && $lee() === str_repeat('a', 30));
    check('blank clears it to NULL', $attempt(['lee_code' => '   ']) === null && $lee() === null);
    // A duplicate must name the Lee code, not "employee number/email/user", and keep the old value. (Its own savepoint: the failed UPDATE must not poison the rest of the run.)
    $other = (int)db()->query('SELECT id FROM employees WHERE id<>'.$emp.' ORDER BY id LIMIT 1')->fetchColumn();
    if ($other) {
        db()->prepare('UPDATE employees SET lee_code=? WHERE id=?')->execute(['DUP-1', $other]);
        $attempt(['lee_code' => 'KEEP-1']);
        $dup = $attempt(['lee_code' => 'DUP-1']);
        check('duplicate Lee code names the Lee code and the old value stays', $dup === 'That Lee code is already used by another employee.' && $lee() === 'KEEP-1', (string)$dup);
    }
    $attempt(['lee_code' => 'AUD-9']);
    $aud = (string)db()->query("SELECT details_json FROM audit_logs WHERE action='UPDATE_EMPLOYMENT' AND record_id=".$emp.' ORDER BY id DESC LIMIT 1')->fetchColumn();
    check('audit records before and after', str_contains($aud, '"lee_code_after":"AUD-9"') && str_contains($aud, 'lee_code_before'), $aud);
} finally {
    db()->rollBack();
}
exit($fail ? 1 : 0);
