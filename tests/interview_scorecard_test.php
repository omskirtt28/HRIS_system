<?php
declare(strict_types=1);

/**
 * applicant-profile-polish T3 (SPEC-applicant-profile-polish.md): interview scorecard. SCRATCH database with migration 030 only.
 *   HRIS_TEST_DB=<scratch> C:\xampp\php\php.exe tests\interview_scorecard_test.php
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
function stageId(string $code): int { return (int)db()->query("SELECT id FROM recruitment_stages WHERE code='$code'")->fetchColumn(); }
$good = ['communication' => '4', 'job_knowledge' => '5', 'attitude' => '3', 'culture_fit' => '4', 'overall' => '4'];

check('0 migration 030 is present', RecruitmentRepository::scorecardReady());
$app = (int)db()->query('SELECT id FROM applications ORDER BY id LIMIT 1')->fetchColumn();
$job = (int)db()->query("SELECT job_opening_id FROM applications WHERE id=$app")->fetchColumn();
db()->beginTransaction();
try {
    db()->exec("UPDATE applications SET converted_employee_id=NULL,converted_at=NULL,status='ACTIVE',current_stage_id=".stageId('INTERVIEW')." WHERE id=$app");
    db()->exec("UPDATE job_openings SET site_type='RETAIL' WHERE id=$job");
    db()->exec("DELETE FROM interview_scorecards WHERE application_id=$app");
    db()->exec("DELETE FROM application_reviewers WHERE application_id=$app");
    $ins = db()->prepare('INSERT INTO application_reviewers(application_id,reviewer_user_id,endorsed_by,endorsed_at) VALUES(?,?,?,NOW())');
    $adl = asRole('ADL'); $sc = asRole('SC'); $hr = asRole('HR_USER');
    $ins->execute([$app, $adl, $hr]);   // SC is NOT endorsed
    $stage = fn() => (int)db()->query("SELECT current_stage_id FROM applications WHERE id=$app")->fetchColumn();
    $count = fn() => (int)db()->query("SELECT COUNT(*) FROM interview_scorecards WHERE application_id=$app")->fetchColumn();

    asRole('HR_USER'); $s0 = $stage();
    RecruitmentRepository::saveScorecard($app, $good, 'PROCEED', 'Solid');
    check('1 HR scorecard is saved', $count() === 1);
    check('1 average of the five scores is 4.0', abs(RecruitmentRepository::scorecardAverage(RecruitmentRepository::scorecards($app)) - 4.0) < 0.001);
    check('1 the timeline records it', (int)db()->query("SELECT COUNT(*) FROM application_stage_history WHERE application_id=$app AND comment LIKE 'Interview scorecard: Proceed%'")->fetchColumn() >= 1);
    check('1 the stage never changes', $stage() === $s0);
    RecruitmentRepository::saveScorecard($app, array_replace($good, ['overall' => '2']), 'HOLD', '');
    check('1 a second save by the same scorer updates, not duplicates', $count() === 1 && db()->query("SELECT recommendation FROM interview_scorecards WHERE application_id=$app")->fetchColumn() === 'HOLD');

    foreach (['0', '6', 'x', '', '3.5'] as $bad) check("2 score '$bad' is refused", throws(fn() => RecruitmentRepository::saveScorecard($app, array_replace($good, ['attitude' => $bad]), 'PROCEED', ''), 'from 1 to 5'));
    $missing = $good; unset($missing['culture_fit']);
    check('2 a missing criterion is refused', throws(fn() => RecruitmentRepository::saveScorecard($app, $missing, 'PROCEED', ''), 'from 1 to 5'));
    check('2 an unknown recommendation is refused', throws(fn() => RecruitmentRepository::saveScorecard($app, $good, 'GREAT', ''), 'Choose'));
    check('2 nothing was added by the refused saves', $count() === 1);

    asRole('ADL');
    RecruitmentRepository::saveScorecard($app, $good, 'PROCEED', 'ADL view');
    check('3 a routed ADL may score and has their own row', $count() === 2);
    check('3 a reviewer sees only their own scorecard', count(RecruitmentRepository::scorecards($app)) === 1);
    asRole('HR_USER');
    check('3 HR sees every scorecard', count(RecruitmentRepository::scorecards($app)) === 2);
    asRole('SC');
    check('3 an un-routed SC is refused', throws(fn() => RecruitmentRepository::saveScorecard($app, $good, 'PROCEED', ''), 'permission') && $count() === 2);

    asRole('HR_USER');
    db()->exec('UPDATE applications SET current_stage_id='.stageId('SCREENING')." WHERE id=$app");
    check('4 at Screening the card is hidden and a save is refused', !RecruitmentRepository::atInterviewOrLater($app) && throws(fn() => RecruitmentRepository::saveScorecard($app, $good, 'PROCEED', ''), 'Interview stage'));
    db()->exec('UPDATE applications SET current_stage_id='.stageId('CLIENT_REVIEW')." WHERE id=$app");
    check('4 at Hiring Review the card shows and a save is allowed', RecruitmentRepository::atInterviewOrLater($app) && (function () use ($app, $good) { RecruitmentRepository::saveScorecard($app, $good, 'PROCEED', ''); return true; })());

    $emp = (int)db()->query('SELECT id FROM employees ORDER BY id LIMIT 1')->fetchColumn();
    if ($emp > 0 && RecruitmentRepository::phase2CReady()) {
        db()->exec("UPDATE applications SET converted_employee_id=$emp,status='CONVERTED' WHERE id=$app");
        check('5 a converted application is locked', throws(fn() => RecruitmentRepository::saveScorecard($app, $good, 'PROCEED', ''), 'converted'));
    } else check('5 converted lock (skipped: no employee or phase 2C)', true);
} finally {
    db()->rollBack();
}
echo $fail === 0 ? "ALL PASS\n" : "FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
