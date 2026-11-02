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
$port = 8098;
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

$nm = 'Zzhttp'.strtr(bin2hex(random_bytes(2)), '0123456789', 'ghijklmnop');
$newEmail = strtolower($nm).'delacruz@pmbsi.com';
$csrfOf = static fn(string $html): string => preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';

// ===== A. HR creates an employee =====
$hr = new Browser($port);
check('A HR signs in', $hr->login($hrEmail, 'Test#1234'));
$form = $hr->req('GET', '/index.php?page=hr-employee-new');
check('A create form loads', $form['code'] === 200, 'code '.$form['code']);
check('A form has Role, no "Link later", read-only email preview', str_contains($form['body'], 'name="account_role"') && !str_contains($form['body'], 'Link later') && str_contains($form['body'], 'id="company_email_preview"'));
$post = ['csrf' => $csrfOf($form['body']), 'action' => 'create_employee', 'first_name' => $nm, 'last_name' => 'Dela Cruz', 'work_company' => 'PMBSI', 'employment_type_id' => '1', 'hire_date' => '2026-11-02', 'account_role' => 'EMPLOYEE', 'employee_no' => 'HTTP-'.bin2hex(random_bytes(3)), 'company_email' => 'attacker@pmbsi.com', 'user_id' => '1'];
$r = $hr->req('POST', '/index.php?page=hr-employee-new', $post);
check('A create redirects to the new employee', $r['code'] === 302 && str_contains($r['loc'], 'hr-employee'), $r['code'].' '.$r['loc']);
$u = $pdo->prepare('SELECT u.id,u.must_change_password,r.code rc FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=?'); $u->execute([$newEmail]); $acct = $u->fetch();
check('A account email is generated and role is Employee (posted company_email and user_id ignored)', (bool)$acct && $acct['rc'] === 'EMPLOYEE' && (int)$acct['must_change_password'] === 1);
$linked = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE user_id=? AND company_email=?'); $linked->execute([$acct['id'] ?? 0, $newEmail]);
check('A employee is linked to that account only', (int)$linked->fetchColumn() === 1 && (int)$pdo->query('SELECT COUNT(*) FROM users WHERE email="attacker@pmbsi.com"')->fetchColumn() === 0);

// ===== B. HR views the password =====
$list = $hr->req('GET', '/index.php?page=admin-users');
check('B HR opens Users & Access', $list['code'] === 200 && str_contains($list['body'], $newEmail), 'code '.$list['code']);
check('B no Create New User card, eye button and form token present', !str_contains($list['body'], 'Create New User') && str_contains($list['body'], 'data-password-info') && str_contains($list['body'], 'id="passwordRevealForm"'));
$rv = $hr->req('POST', '/index.php?page=user-password-reveal', ['csrf' => $csrfOf($list['body']), 'user_id' => (string)$acct['id']]);
$j = json_decode($rv['body'], true) ?: [];
$pw1 = (string)($j['password'] ?? '');
check('B reveal returns a 12-character password as JSON', $rv['code'] === 200 && !empty($j['ok']) && strlen($pw1) === 12, $rv['code'].' '.substr($rv['body'], 0, 80));
check('B the password never appears in the page HTML', $pw1 !== '' && !str_contains($list['body'], $pw1));
check('B reveal without CSRF token is refused', $hr->req('POST', '/index.php?page=user-password-reveal', ['user_id' => (string)$acct['id']])['code'] === 419);
check('B reveal by GET is refused', $hr->req('GET', '/index.php?page=user-password-reveal&user_id='.$acct['id'])['code'] === 405);

// ===== C. The employee signs in and must choose a password =====
$emp = new Browser($port);
check('C employee signs in with the generated password', $emp->login($newEmail, $pw1));
$go = $emp->req('GET', '/index.php?page=employee-dashboard');
check('C every page redirects to Change password until it is changed', $go['code'] === 302 && str_contains($go['loc'], 'change-password'), $go['code'].' '.$go['loc']);
$cp = $emp->req('GET', '/index.php?page=change-password');
check('C change-password page loads', $cp['code'] === 200 && str_contains($cp['body'], 'name="new_password"'));
$bad = $emp->req('POST', '/index.php?page=change-password', ['csrf' => $csrfOf($cp['body']), 'current_password' => 'wrong', 'new_password' => 'Brand-New-Pass-77', 'confirm_password' => 'Brand-New-Pass-77']);
check('C wrong current password keeps the gate', $bad['code'] === 302 && str_contains($bad['loc'], 'change-password'));
$cp = $emp->req('GET', '/index.php?page=change-password');
$ok = $emp->req('POST', '/index.php?page=change-password', ['csrf' => $csrfOf($cp['body']), 'current_password' => $pw1, 'new_password' => 'Brand-New-Pass-77', 'confirm_password' => 'Brand-New-Pass-77']);
check('C a valid change redirects away from the gate', $ok['code'] === 302 && !str_contains($ok['loc'], 'change-password'), $ok['loc']);
$go = $emp->req('GET', '/index.php?page=employee-dashboard');
check('C pages open normally afterwards', !($go['code'] === 302 && str_contains($go['loc'], 'change-password')), $go['code'].' '.$go['loc']);

// ===== D. HR still sees the current password; the employee cannot =====
$rv = $hr->req('POST', '/index.php?page=user-password-reveal', ['csrf' => $csrfOf($list['body']), 'user_id' => (string)$acct['id']]);
$j = json_decode($rv['body'], true) ?: [];
check('D HR now sees the employee-chosen password', !empty($j['ok']) && ($j['password'] ?? '') === 'Brand-New-Pass-77');
check('D old password no longer signs in, new one does', !(new Browser($port))->login($newEmail, $pw1) && (new Browser($port))->login($newEmail, 'Brand-New-Pass-77'));
$page = $emp->req('GET', '/index.php?page=admin-users');
check('D employee cannot open Users & Access', $page['code'] !== 200 || !str_contains($page['body'], 'data-password-info'), 'code '.$page['code']);
$tok = $csrfOf($emp->req('GET', '/index.php?page=change-password')['body'].$emp->req('GET', '/index.php?page=employee-dashboard')['body']);
$deny = $emp->req('POST', '/index.php?page=user-password-reveal', ['csrf' => $tok, 'user_id' => (string)$acct['id']]);
check('D employee cannot reveal any password', $deny['code'] !== 200 && empty((json_decode($deny['body'], true) ?: [])['password']), $deny['code'].' '.substr($deny['body'], 0, 80));
check('D reveals are audited without the value', (int)$pdo->query('SELECT COUNT(*) FROM audit_logs WHERE action="REVEAL_PASSWORD" AND record_id='.(int)$acct['id'])->fetchColumn() >= 2 && (int)$pdo->query('SELECT COUNT(*) FROM audit_logs WHERE details_json LIKE "%Brand-New-Pass-77%" OR details_json LIKE "%'.$pw1.'%"')->fetchColumn() === 0);

exit($fail ? 1 : 0);
