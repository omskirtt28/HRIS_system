<?php
declare(strict_types=1);

/**
 * rec-screening-score (SPEC-rec-screening-score.md): rating, who may score, recruiter, convert gate. SCRATCH database only.
 *   HRIS_TEST_DB=hris_slice15_test_028 C:\xampp\php\php.exe tests\screening_rating_db_test.php
 * Every fixture change is rolled back at the end.
 */
$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
$GLOBALS['pdo'] = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (db()->query('SELECT DATABASE()')->fetchColumn() !== $dbName) exit(2);

$fail = 0;
function check(string $label, bool $ok): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ').$label."\n"; if (!$ok) $fail++; }
function asRole(string $role): int {
    $u = db()->prepare('SELECT u.id,u.role_id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code=? AND u.status="ACTIVE" ORDER BY u.id LIMIT 1'); $u->execute([$role]); $r = $u->fetch();
    if (!$r) { fwrite(STDERR, "No active $role user in the scratch copy.\n"); exit(2); }
    $_SESSION['user'] = ['id' => (int)$r['id'], 'role_id' => (int)$r['role_id'], 'role_code' => $role, 'role_portal' => 'hr']; Auth::clearPermissionCache(); return (int)$r['id'];
}
function throws(callable $f, string $needle): bool { try { $f(); } catch (RuntimeException $e) { return str_contains($e->getMessage(), $needle); } return false; }

$app = (int)db()->query('SELECT id FROM applications ORDER BY id LIMIT 1')->fetchColumn();
$job = (int)db()->query("SELECT job_opening_id FROM applications WHERE id=$app")->fetchColumn();
db()->beginTransaction();
try {
    db()->exec("UPDATE applications SET converted_employee_id=NULL,converted_at=NULL,status='ACTIVE',screening_rating=NULL,assigned_recruiter_id=NULL WHERE id=$app");
    db()->exec("UPDATE job_openings SET site_type='RETAIL' WHERE id=$job");
    db()->exec("DELETE FROM application_reviewers WHERE application_id=$app");
    $ins = db()->prepare('INSERT INTO application_reviewers(application_id,reviewer_user_id,endorsed_by,endorsed_at) VALUES(?,?,?,NOW())');
    $adl = asRole('ADL'); $sc = asRole('SC'); $mgr = asRole('MANAGER'); $hr = asRole('HR_USER');
    $ins->execute([$app, $adl, $hr]); $ins->execute([$app, $mgr, $hr]);   // SC is NOT endorsed

    // 1. HR rates: stored, recruiter auto-set once, history row kept
    asRole('HR_USER');
    RecruitmentRepository::saveScreening($app, 'OUTSTANDING', 'Strong');
    $row = db()->query("SELECT screening_rating,assigned_recruiter_id FROM applications WHERE id=$app")->fetch();
    check('1 HR rating is saved on the application', $row['screening_rating'] === 'OUTSTANDING');
    check('1 recruiter is set to the HR user who scored first', (int)$row['assigned_recruiter_id'] === $hr);
    check('1 history row is kept', (int)db()->query("SELECT COUNT(*) FROM screening_reviews WHERE application_id=$app AND rating='OUTSTANDING'")->fetchColumn() === 1);
    check('1 a saved rating is final: the same rater cannot rate again', throws(fn() => RecruitmentRepository::saveScreening($app, 'NEGATIVE', ''), 'final'));
    check('1 and nothing changed', db()->query("SELECT screening_rating FROM applications WHERE id=$app")->fetchColumn() === 'OUTSTANDING' && (int)db()->query("SELECT COUNT(*) FROM screening_reviews WHERE application_id=$app")->fetchColumn() === 1);
    check('1 myScreening returns the rater\'s own rating', (RecruitmentRepository::myScreening($app)['rating'] ?? null) === 'OUTSTANDING');

    // 2. Validation
    check('2 an unknown rating is refused', throws(fn() => RecruitmentRepository::saveScreening($app, 'GREAT', ''), 'rating'));
    check('2 a numeric score is refused', throws(fn() => RecruitmentRepository::saveScreening($app, '90', ''), 'rating'));

    // 3. Who may score, retail job
    asRole('ADL');          check('3 retail: routed ADL may score', RecruitmentRepository::canScore($app));
    asRole('SC');           check('3 retail: SC that the applicant did not go through may not', !RecruitmentRepository::canScore($app));
    asRole('MANAGER');      check('3 retail: routed Manager is not a retail scorer', !RecruitmentRepository::canScore($app));
    asRole('ADL');          RecruitmentRepository::saveScreening($app, 'FAIR', 'ADL view');
    check('3 routed ADL rating is saved and does not take the recruiter slot', db()->query("SELECT screening_rating FROM applications WHERE id=$app")->fetchColumn() === 'FAIR' && (int)db()->query("SELECT assigned_recruiter_id FROM applications WHERE id=$app")->fetchColumn() === $hr);
    asRole('SC');           check('3 un-routed SC save is refused', throws(fn() => RecruitmentRepository::saveScreening($app, 'FAIR', ''), 'permission'));

    // 4. Head office job
    db()->exec("UPDATE job_openings SET site_type='HEAD_OFFICE' WHERE id=$job");
    asRole('MANAGER');      check('4 head office: routed Manager may score', RecruitmentRepository::canScore($app));
    asRole('ADL');          check('4 head office: routed ADL may not', !RecruitmentRepository::canScore($app));

    // 5. Other company: HR only
    db()->exec("UPDATE job_openings SET site_type='OTHER' WHERE id=$job");
    asRole('MANAGER');      check('5 other company: routed Manager may not', !RecruitmentRepository::canScore($app));
    asRole('HR_USER');      check('5 other company: HR may', RecruitmentRepository::canScore($app));

    // 6. Convert gate and lock
    db()->exec("UPDATE applications SET screening_rating=NULL WHERE id=$app");
    asRole('HR_USER');
    check('6 convert is refused without a rating', throws(fn() => RecruitmentRepository::convertToEmployee($app, []), 'rating'));
    db()->exec("UPDATE applications SET converted_employee_id=(SELECT id FROM employees LIMIT 1),status='CONVERTED' WHERE id=$app");
    check('6 a converted application cannot be rated', throws(fn() => RecruitmentRepository::saveScreening($app, 'FAIR', ''), 'converted'));
} finally { db()->rollBack(); }
echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
