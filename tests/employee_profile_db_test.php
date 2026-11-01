<?php
declare(strict_types=1);

/**
 * My Profile: own-data-only, unlinked account and masked reveal against a SCRATCH copy of the database.
 *
 * 1. Scratch copy (Git Bash):
 *      mysql -uroot -e "DROP DATABASE IF EXISTS pmbsi_hris_test; CREATE DATABASE pmbsi_hris_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 *      mysqldump -uroot --single-transaction --routines --triggers pmbsi_hris | mysql -uroot pmbsi_hris_test
 * 2. Run:
 *      HRIS_TEST_DB=pmbsi_hris_test C:\xampp\php\php.exe tests\employee_profile_db_test.php
 *
 * Refuses to run on the live database. Every fixture is written inside one transaction that is rolled back at the end.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/EmployeeProfileService.php';
$GLOBALS['pdo'] = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if (db()->query('SELECT DATABASE()')->fetchColumn() !== $dbName) exit(2);

$fail = 0;
function check(string $label, bool $ok): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ').$label."\n"; if (!$ok) $fail++; }
function asUser(int $userId, string $role = 'EMPLOYEE', string $portal = 'employee'): void { $_SESSION['user'] = ['id' => $userId, 'role_code' => $role, 'role_portal' => $portal]; }

$pair = db()->query('SELECT id,user_id FROM employees WHERE user_id IS NOT NULL ORDER BY id LIMIT 2')->fetchAll();
if (count($pair) < 2) { fwrite(STDERR, "Need two employees with linked accounts in the scratch copy.\n"); exit(2); }
[$a, $b] = $pair;
$unlinked = db()->query('SELECT id FROM users WHERE id NOT IN (SELECT user_id FROM employees WHERE user_id IS NOT NULL) ORDER BY id LIMIT 1')->fetchColumn();

db()->beginTransaction();
try {
    $ins = db()->prepare('INSERT INTO employee_government_ids(employee_id,id_type,id_number) VALUES(?,?,?)');
    $ins->execute([$a['id'], 'OTHER', 'AAAA-1111-2222']); $aId = (int)db()->lastInsertId();
    $ins->execute([$b['id'], 'OTHER', 'BBBB-9999-8888']); $bId = (int)db()->lastInsertId();
    $doc = db()->prepare('INSERT INTO employee_documents(employee_id,document_type,title,original_name,stored_name,mime_type,file_size) VALUES(?,?,?,?,?,?,?)');
    $doc->execute([$a['id'], 'MEMO', 'A-own-doc', 'a.jpg', 'x_a.jpg', 'image/jpeg', 10]); $aDoc = (int)db()->lastInsertId();
    $doc->execute([$b['id'], 'CONTRACT', 'B-private-doc', 'b.pdf', 'x_b.pdf', 'application/pdf', 10]); $bDoc = (int)db()->lastInsertId();

    // 1. Own data only
    asUser((int)$a['user_id']);
    $me = EmployeeProfileService::forCurrentUser();
    check('1 current employee is the signed-in user\'s own row', $me && (int)$me['id'] === (int)$a['id']);
    check('1 ownEmployeeId matches the signed-in user\'s row, and is null when signed out', EmployeeProfileService::ownEmployeeId() === (int)$a['id'] && (function () { $keep = $_SESSION['user']; unset($_SESSION['user']); $none = EmployeeProfileService::ownEmployeeId(); $_SESSION['user'] = $keep; return $none === null; })());
    check('1 cannot reveal another employee\'s ID (by row id)', EmployeeProfileService::revealNumber((int)$a['id'], $bId) === null);
    check('1 cannot open another employee\'s document (ownership in query)', EmployeeRepository::document((int)$a['id'], $bDoc) === null && EmployeeRepository::document((int)$a['id'], $aDoc) !== null);
    $extras = json_encode(EmployeeProfileService::extras((int)$a['id']), JSON_UNESCAPED_UNICODE);
    check('1 page data holds none of the other employee\'s IDs or documents', !str_contains($extras, 'BBBB') && !str_contains($extras, 'B-private-doc') && str_contains($extras, 'A-own-doc'));
    check('1 ALL own document types are listed (MEMO included)', str_contains($extras, '"type_code":"MEMO"'));

    // Deleting a document also removes its cached preview (no retained copy of deleted personal data)
    $tdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'myp_del_'.bin2hex(random_bytes(4)); mkdir($tdir.'/.thumbs', 0777, true);
    file_put_contents($tdir.'/.thumbs/emp_1_x.png.jpg', 'x'); file_put_contents($tdir.'/.thumbs/keep.jpg', 'x');
    EmployeeRepository::removeThumbnail($tdir, 'emp_1_x.png');
    check('1 removeThumbnail deletes only that document\'s preview', !is_file($tdir.'/.thumbs/emp_1_x.png.jpg') && is_file($tdir.'/.thumbs/keep.jpg'));
    @unlink($tdir.'/.thumbs/keep.jpg'); @rmdir($tdir.'/.thumbs'); @rmdir($tdir);

    // 2. Unlinked / unauthorised
    if ($unlinked) { asUser((int)$unlinked, 'HR_USER', 'hr'); check('2 account without an employee record gets no profile', EmployeeProfileService::forCurrentUser() === null); }
    else echo "SKIP 2 unlinked account (every user is linked in this copy)\n";
    check('2 client accounts are denied', !EmployeeProfileService::canView(['role_code' => 'CLIENT_USER', 'role_portal' => 'client']));
    check('2 HR accounts are allowed', EmployeeProfileService::canView(['role_code' => 'HR_USER', 'role_portal' => 'hr']));

    // 3. Masked + audited reveal
    asUser((int)$a['user_id']);
    check('3 page data never contains the full number', !str_contains($extras, 'AAAA-1111-2222') && str_contains($extras, '••••2222'));
    $before = (int)db()->query("SELECT COUNT(*) FROM audit_logs WHERE action='REVEAL_OWN_GOV_ID'")->fetchColumn();
    check('3 reveal returns the number for the owner', EmployeeProfileService::revealNumber((int)$a['id'], $aId) === 'AAAA-1111-2222');
    $row = db()->query("SELECT details_json,user_id FROM audit_logs WHERE action='REVEAL_OWN_GOV_ID' ORDER BY id DESC LIMIT 1")->fetch();
    check('3 reveal is audited without the number', (int)db()->query("SELECT COUNT(*) FROM audit_logs WHERE action='REVEAL_OWN_GOV_ID'")->fetchColumn() === $before + 1 && $row && !str_contains((string)$row['details_json'], 'AAAA') && (int)$row['user_id'] === (int)$a['user_id']);
    check('3 a refused reveal is not audited as success', EmployeeProfileService::revealNumber((int)$a['id'], $bId) === null && (int)db()->query("SELECT COUNT(*) FROM audit_logs WHERE action='REVEAL_OWN_GOV_ID'")->fetchColumn() === $before + 1);
} finally {
    if (db()->inTransaction()) db()->rollBack();
}
exit($fail ? 1 : 0);
