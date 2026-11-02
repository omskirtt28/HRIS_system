<?php
declare(strict_types=1);

/**
 * My Profile end to end over HTTP: real login, real routes, real HTML. Needs a SCRATCH database copy:
 *   mysql -uroot -e "DROP DATABASE IF EXISTS pmbsi_hris_test; CREATE DATABASE pmbsi_hris_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 *   mysqldump -uroot --single-transaction --routines --triggers pmbsi_hris | mysql -uroot pmbsi_hris_test
 *   HRIS_TEST_DB=pmbsi_hris_test C:\xampp\php\php.exe tests\employee_profile_http_test.php
 * The app is copied to a temp folder, pointed at the scratch DB with a config.local.php there (the repo is not touched),
 * served by php -S, and exercised with curl. Fixtures and passwords are written to the scratch DB only. Drop it afterwards.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_account_http_'.bin2hex(random_bytes(4));
$port = 8099;
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
$tag = 'Zzbulk'.strtr(bin2hex(random_bytes(2)), '0123456789', 'ghijklmnop');

// scratch only: keep the copied real rows out of the bulk run, add three pending employees and one Employee-role user
$pdo->exec("UPDATE employees SET status='INACTIVE' WHERE COALESCE(user_id,0)=0");
$ins = $pdo->prepare('INSERT INTO employees(employee_no,first_name,last_name,status,hire_date,work_company) VALUES(?,?,?,"ACTIVE","2026-11-02","PMBSI")');
$ins->execute(['HB-'.bin2hex(random_bytes(3)), $tag.'one', 'Single']); $idOne = (int)$pdo->lastInsertId();
$ins->execute(['HB-'.bin2hex(random_bytes(3)), $tag.'two', 'Bulk']);   $idTwo = (int)$pdo->lastInsertId();
$ins->execute(['HB-'.bin2hex(random_bytes(3)), $tag.'three', 'Bulk']); $idThree = (int)$pdo->lastInsertId();
$staffEmail = 'zz_http_emp_'.bin2hex(random_bytes(3)).'@example.test';
$pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="EMPLOYEE"),?,?,?,"ACTIVE")')->execute(['Zz Http Staff', $staffEmail, password_hash('Test#1234', PASSWORD_DEFAULT)]);
$linkOf = static function (int $id) use ($pdo): int { $q = $pdo->prepare('SELECT COALESCE(user_id,0) FROM employees WHERE id=?'); $q->execute([$id]); return (int)$q->fetchColumn(); };
$userCount = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

$hr = new Browser($port);
check('HR signs in', $hr->login($hrEmail, 'Test#1234'));

// ===== A. the directory tells HR who has no account =====
$dir = $hr->req('GET', '/index.php?page=hr-employees');
check('A directory loads', $dir['code'] === 200, 'code '.$dir['code']);
check('A notice shows the pending count and the Generate all button', str_contains($dir['body'], 'have no portal account') && str_contains($dir['body'], 'Generate all accounts') && str_contains($dir['body'], '3 active employee(s)'));
check('A notice lists the pending names', str_contains($dir['body'], $tag.'one Single'));

// ===== B. 201 file: Generate account replaces the dropdown =====
$emp = $hr->req('GET', '/index.php?page=hr-employee&id='.$idOne.'&tab=employment');
check('B employment tab shows Generate account, no User account dropdown', $emp['code'] === 200 && str_contains($emp['body'], 'Generate account') && str_contains($emp['body'], 'id="genAccountRole"') && !str_contains($emp['body'], '<label>User account</label>') && !str_contains($emp['body'], 'name="user_id"'), 'code '.$emp['code']);
$tok = $csrfOf($emp['body']);

// ===== C. one click =====
$before = $userCount();
check('C POST without CSRF is refused and creates nothing', $hr->req('POST', '/index.php?page=hr-employee&id='.$idOne.'&tab=employment', ['action' => 'generate_employee_account', 'employee_id' => (string)$idOne])['code'] === 419 && $userCount() === $before);
$r = $hr->req('POST', '/index.php?page=hr-employee&id='.$idOne.'&tab=employment', ['csrf' => $tok, 'action' => 'generate_employee_account', 'employee_id' => (string)$idOne, 'account_role' => 'EMPLOYEE']);
check('C one click creates and links the account', $r['code'] === 302 && $linkOf($idOne) > 0 && $userCount() === $before + 1, $r['code'].' '.$r['loc']);
$emp = $hr->req('GET', '/index.php?page=hr-employee&id='.$idOne.'&tab=employment');
check('C linked employee now shows the email and no Generate button', str_contains($emp['body'], strtolower($tag).'onesingle@pmbsi.com') && !str_contains($emp['body'], 'id="genAccountRole"'));
$hr->req('POST', '/index.php?page=hr-employee&id='.$idOne.'&tab=employment', ['csrf' => $tok, 'action' => 'generate_employee_account', 'employee_id' => (string)$idOne, 'account_role' => 'EMPLOYEE']);
check('C a second click creates nothing', $userCount() === $before + 1);
$hr->req('POST', '/index.php?page=hr-employee&id='.$idTwo.'&tab=employment', ['csrf' => $tok, 'action' => 'generate_employee_account', 'employee_id' => (string)$idTwo, 'account_role' => 'SUPER_ADMIN']);
check('C forged role is refused', $linkOf($idTwo) === 0);

// ===== D. saving the 201 file keeps the link =====
$no = (string)$pdo->query('SELECT employee_no FROM employees WHERE id='.$idOne)->fetchColumn();
$save = $hr->req('POST', '/index.php?page=hr-employee&id='.$idOne.'&tab=employment', ['csrf' => $tok, 'action' => 'update_employee_employment', 'employee_id' => (string)$idOne, 'employee_no' => $no, 'work_company' => 'PMBSI', 'employment_type_id' => '1', 'hire_date' => '2026-11-02', 'remarks' => 'http save']);
check('D the save really ran (audited) and kept the account link', $linkOf($idOne) > 0 && (int)$pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action="UPDATE_EMPLOYMENT" AND record_id='.$idOne)->fetchColumn() >= 1, $save['code'].' '.$save['loc']);

// ===== E. bulk =====
$staff = new Browser($port);
check('E staff user signs in', $staff->login($staffEmail, 'Test#1234'));
$before = $userCount();
$staffTry = $staff->req('POST', '/index.php?page=hr-employees', ['csrf' => $csrfOf($staff->req('GET', '/index.php?page=employee-dashboard')['body']), 'action' => 'generate_missing_accounts']);
check('E an employee cannot run the bulk action', $userCount() === $before && $linkOf($idTwo) === 0 && $linkOf($idThree) === 0, 'code '.$staffTry['code']);
check('E bulk without CSRF is refused', $hr->req('POST', '/index.php?page=hr-employees', ['action' => 'generate_missing_accounts'])['code'] === 419 && $userCount() === $before);
$dir = $hr->req('GET', '/index.php?page=hr-employees');
$bulk = $hr->req('POST', '/index.php?page=hr-employees', ['csrf' => $csrfOf($dir['body']), 'action' => 'generate_missing_accounts']);
check('E HR bulk creates the missing accounts and goes back to the directory', $bulk['code'] === 302 && str_contains($bulk['loc'], 'hr-employees') && $linkOf($idTwo) > 0 && $linkOf($idThree) > 0 && $userCount() === $before + 2, $bulk['code'].' '.$bulk['loc']);
$dir = $hr->req('GET', '/index.php?page=hr-employees');
check('E directory shows the result, no password, and the notice is gone', str_contains($dir['body'], '2 account(s) created') && !str_contains($dir['body'], 'have no portal account') && !preg_match('/password:/i', strip_tags($dir['body'])));
$dir2 = $hr->req('GET', '/index.php?page=hr-employees');
check('E the result panel shows once', !str_contains($dir2['body'], 'account-result'));
$sd = $staff->req('GET', '/index.php?page=hr-employees');
check('E an employee never sees the notice', !str_contains($sd['body'], 'Generate all accounts'));
check('E audit rows exist for the new accounts', (int)$pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action="AUTO_CREATE_USER" AND details_json LIKE "%'.strtolower($tag).'%"')->fetchColumn() >= 3);

exit($fail ? 1 : 0);
