<?php
declare(strict_types=1);

/**
 * Remove portal account over HTTP: real login, real routes, real HTML. Needs a SCRATCH database copy:
 *   mysql -uroot -e "DROP DATABASE IF EXISTS pmbsi_hris_test; CREATE DATABASE pmbsi_hris_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 *   mysqldump -uroot --single-transaction --routines --triggers pmbsi_hris | mysql -uroot pmbsi_hris_test
 *   HRIS_TEST_DB=pmbsi_hris_test C:\xampp\php\php.exe tests\remove_account_http_test.php
 * The app is copied to a temp folder, pointed at the scratch DB with a config.local.php there (the repo is not touched),
 * served by php -S, and exercised with curl. Fixtures and passwords are written to the scratch DB only. Drop it afterwards.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_remove_http_'.bin2hex(random_bytes(4));
$port = 8097;
$pdo = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function copyTree(string $from, string $to): void {
    @mkdir($to, 0777, true);
    foreach (new DirectoryIterator($from) as $f) {
        if ($f->isDot()) continue;
        $f->isDir() ? copyTree($f->getPathname(), $to.DIRECTORY_SEPARATOR.$f->getFilename()) : copy($f->getPathname(), $to.DIRECTORY_SEPARATOR.$f->getFilename());
    }
}
function rmTree(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new DirectoryIterator($dir) as $f) { if ($f->isDot()) continue; $f->isDir() ? rmTree($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}

foreach (['app', 'config', 'public', 'vendor'] as $d) copyTree($root.'/'.$d, $tmp.'/'.$d);
copy($root.'/index.php', $tmp.'/index.php'); copy($root.'/router.php', $tmp.'/router.php');
@mkdir($tmp.'/storage/employee_documents', 0777, true);
$key = base64_encode(random_bytes(32));
file_put_contents($tmp.'/config/config.local.php', "<?php\nreturn ['app'=>['debug'=>true],'mail'=>[],'security'=>['credential_key'=>".var_export($key, true)."],'db'=>['host'=>'127.0.0.1','port'=>'3306','database'=>".var_export($dbName, true).",'username'=>'root','password'=>'','charset'=>'utf8mb4'],'incident_documents'=>['storage_root'=>".var_export($tmp.'/private', true)."]];\n");

// ---- fixtures (scratch DB only): one HR viewer account ----
$hrEmail = 'zz_http_hr_'.bin2hex(random_bytes(3)).'@example.test';
$pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="HR_USER"),?,?,?,"ACTIVE")')->execute(['Zz Http HR', $hrEmail, password_hash('Test#1234', PASSWORD_DEFAULT)]);
// ---- server ----
$proc = proc_open(array_merge([PHP_BINARY], extension_loaded('gd') ? ['-d', 'extension=gd'] : [], ['-S', '127.0.0.1:'.$port, '-t', $tmp, $tmp.'/router.php']), [['pipe', 'r'], ['file', $tmp.'/server.log', 'w'], ['file', $tmp.'/server.log', 'a']], $pipes, $tmp);
register_shutdown_function(function () use ($proc, $tmp) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } usleep(300000); rmTree($tmp); });
for ($i = 0; $i < 50; $i++) { $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($s) { fclose($s); break; } usleep(100000); }

$runStart = (string)$pdo->query('SELECT NOW()')->fetchColumn();
$fail = 0;
function check(string $label, bool $ok, string $why = ''): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ').$label.($ok || $why === '' ? '' : '  ['.$why.']')."\n"; if (!$ok) $fail++; }

/** Tiny browser: cookie jar per instance. */
final class Browser {
    private string $jar;
    public function __construct(private int $port) { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    public function req(string $method, string $path, array $post = [], array $headers = []): array {
        $ch = curl_init('http://127.0.0.1:'.$this->port.$path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
        if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        $raw = (string)curl_exec($ch); $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        $head = substr($raw, 0, $size);
        return ['code' => $code, 'head' => $head, 'body' => substr($raw, $size), 'loc' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : ''];
    }
    public function login(string $email, string $password): bool {
        $page = $this->req('GET', '/index.php?page=login');
        if (!preg_match('/name="csrf" value="([^"]+)"/', $page['body'], $m)) return false;
        $r = $this->req('POST', '/index.php?page=login', ['csrf' => html_entity_decode($m[1]), 'action' => 'login', 'email' => $email, 'password' => $password]);
        return $r['code'] === 302 && !str_contains($r['loc'], 'page=login');
    }
    public function csrf(string $html): string { return preg_match('/data-csrf="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }
}

$csrfOf = static fn(string $html): string => preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
$tag = 'Zzrem'.strtr(bin2hex(random_bytes(2)), '0123456789', 'ghijklmnop');

// scratch only: one employee with an account, one Employee-role viewer, one HR-portal account
$targetEmail = strtolower($tag).'onetarget@pmbsi.com';
$pdo->prepare('INSERT INTO employees(employee_no,first_name,last_name,status,hire_date,work_company,company_email) VALUES(?,?,?,"ACTIVE","2026-11-02","PMBSI",?)')->execute(['HR-'.bin2hex(random_bytes(3)), $tag.'one', 'Target', $targetEmail]);
$empId = (int)$pdo->lastInsertId();
$mk = static function (string $role, string $name, string $email) use ($pdo): int { $pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code=?),?,?,?,"ACTIVE")')->execute([$role, $name, $email, password_hash('Test#1234', PASSWORD_DEFAULT)]); return (int)$pdo->lastInsertId(); };
$target = $mk('EMPLOYEE', 'Zz Target', $targetEmail); $pdo->prepare('UPDATE employees SET user_id=? WHERE id=?')->execute([$target, $empId]);
$staffEmail = 'zz_http_staff_'.bin2hex(random_bytes(3)).'@example.test'; $staff = $mk('EMPLOYEE', 'Zz Staff', $staffEmail);
$adminAcct = $mk('HRIS_ADMIN', 'Zz Admin Acct', 'zz_http_adm_'.bin2hex(random_bytes(3)).'@example.test');
$otherHr = $mk('HR_USER', 'Zz Other HR', 'zz_http_hr2_'.bin2hex(random_bytes(3)).'@example.test');
$row = static function (int $id) use ($pdo): array { $q = $pdo->prepare('SELECT status,removed_at,email FROM users WHERE id=?'); $q->execute([$id]); return $q->fetch(); };
$empRow = static function () use ($pdo, $empId): array { $q = $pdo->prepare('SELECT user_id,company_email,first_name,employee_no FROM employees WHERE id=?'); $q->execute([$empId]); return $q->fetch(); };

$hr = new Browser($port);
check('HR signs in', $hr->login($hrEmail, 'Test#1234'));

// ===== A. HR sees Remove account in the menu (was "No available actions") =====
$page = $hr->req('GET', '/index.php?page=admin-users');
check('A Users & Access loads for HR', $page['code'] === 200, 'code '.$page['code']);
check('A menu offers Remove account for an Employee account, with a confirm', str_contains($page['body'], 'name="action" value="remove_user"') && str_contains($page['body'], 'onsubmit="return confirm('));
$tok = $csrfOf($page['body']);

// ===== B. permission, scope and CSRF =====
check('B POST without CSRF is refused', $hr->req('POST', '/index.php?page=admin-users', ['action' => 'remove_user', 'user_id' => (string)$target])['code'] === 419 && $row($target)['removed_at'] === null);
$emp = new Browser($port);
check('B staff signs in', $emp->login($staffEmail, 'Test#1234'));
$emp->req('POST', '/index.php?page=admin-users', ['csrf' => $csrfOf($emp->req('GET', '/index.php?page=employee-dashboard')['body']), 'action' => 'remove_user', 'user_id' => (string)$target]);
check('B an Employee cannot remove an account', $row($target)['removed_at'] === null);
$hr->req('POST', '/index.php?page=admin-users', ['csrf' => $tok, 'action' => 'remove_user', 'user_id' => (string)$adminAcct]);
check('B HR cannot remove an Admin-portal account', $row($adminAcct)['removed_at'] === null);
$hr->req('POST', '/index.php?page=admin-users', ['csrf' => $tok, 'action' => 'remove_user', 'user_id' => (string)$otherHr]);
check('B HR can remove an HR account', $row($otherHr)['removed_at'] !== null);

// ===== C. remove =====
$before = $empRow();
$r = $hr->req('POST', '/index.php?page=admin-users', ['csrf' => $tok, 'action' => 'remove_user', 'user_id' => (string)$target]);
check('C HR removes the account and lands on Users & Access', $r['code'] === 302 && str_contains($r['loc'], 'admin-users') && $row($target)['removed_at'] !== null, $r['code'].' '.$r['loc']);
$after = $empRow();
check('C the employee record stays, only the link and company email are cleared', $after['user_id'] === null && $after['company_email'] === null && $after['first_name'] === $before['first_name'] && $after['employee_no'] === $before['employee_no']);
$page = $hr->req('GET', '/index.php?page=admin-users');
check('C the account is gone from the list and shown under Removed accounts', !str_contains($page['body'], 'data-user-id="'.$target.'" data-user-name') && str_contains($page['body'], 'Removed accounts (') && str_contains($page['body'], 'Restore account'));
$gone = new Browser($port);
check('C the removed person can no longer sign in', !$gone->login($targetEmail, 'Test#1234'));
$emp201 = $hr->req('GET', '/index.php?page=hr-employee&id='.$empId.'&tab=employment');
check('C the 201 file still opens and offers Generate account again', $emp201['code'] === 200 && str_contains($emp201['body'], 'Generate account'));

// ===== D. generate again; restore is refused while the email is taken =====
$gen = $hr->req('POST', '/index.php?page=hr-employee&id='.$empId.'&tab=employment', ['csrf' => $csrfOf($emp201['body']), 'action' => 'generate_employee_account', 'employee_id' => (string)$empId, 'account_role' => 'EMPLOYEE']);
check('D a new account is generated for the same employee with the same email', $gen['code'] === 302 && (int)$empRow()['user_id'] > 0 && (int)$empRow()['user_id'] !== $target && $empRow()['company_email'] === $targetEmail);
$hr->req('POST', '/index.php?page=admin-users', ['csrf' => $tok, 'action' => 'restore_user', 'user_id' => (string)$target]);
check('D restore is refused while another account uses the email', $row($target)['removed_at'] !== null);

// ===== E. restore =====
$new = (int)$empRow()['user_id'];
$hr->req('POST', '/index.php?page=admin-users', ['csrf' => $tok, 'action' => 'remove_user', 'user_id' => (string)$new]);
$hr->req('POST', '/index.php?page=admin-users', ['csrf' => $tok, 'action' => 'restore_user', 'user_id' => (string)$target]);
check('E restore brings the original account back and relinks the employee', $row($target)['removed_at'] === null && $row($target)['status'] === 'ACTIVE' && $row($target)['email'] === $targetEmail && (int)$empRow()['user_id'] === $target);
check('E audit rows for remove and restore exist', (int)$pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action IN ("REMOVE_USER","RESTORE_USER") AND record_id='.$target)->fetchColumn() >= 2);

exit($fail ? 1 : 0);
