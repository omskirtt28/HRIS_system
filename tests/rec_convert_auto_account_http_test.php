<?php
declare(strict_types=1);

/**
 * Convert to Employee over HTTP (SPEC-convert-auto-account.md, Task 3): real login, real routes, real HTML. Needs a SCRATCH database copy:
 *   HRIS_TEST_DB=hris_slice15_test_YYYYMMDD_HHMMSS C:\xampp\php\php.exe tests\rec_convert_auto_account_http_test.php
 * The app is copied to a temp folder, pointed at the scratch DB, served by php -S (port 8103) and exercised with curl. Synthetic data only.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_convert_http_'.bin2hex(random_bytes(4));
$port = 8103;
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

// ---- fixtures (scratch DB only) ----
$sfx = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$mkUser = function (string $role, string $label) use ($pdo): string {
    $email = 'zz_cv_'.$label.'_'.bin2hex(random_bytes(3)).'@example.test';
    $pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code=?),?,?,?,"ACTIVE")')->execute([$role, 'Zz '.$label, $email, password_hash('Test#1234', PASSWORD_DEFAULT)]);
    return $email;
};
$hrEmail = $mkUser('HR_USER', 'hr'); $plainEmail = $mkUser('EMPLOYEE', 'plain');
$pdo->prepare('INSERT INTO clients(code,name) VALUES(?,?)')->execute(['ZH'.$sfx, 'Zzh '.$sfx.' Trading']); $client = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status) VALUES(?,?,?,5,0,"REGULAR","NORMAL","APPROVED")')->execute(['MR-ZH-'.bin2hex(random_bytes(4)), $client, 'Synthetic clerk']); $mr = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,5,"OPEN","Synthetic branch","REGULAR")')->execute(['JOB-ZH-'.$sfx, 'zzh-'.$sfx, 'Synthetic clerk', 'x', $client, $mr]); $job = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO departments(code,name,active,work_company) VALUES(?,?,1,"2LOG")')->execute(['ZH'.$sfx, 'Zz dept '.$sfx]); $dept = (int)$pdo->lastInsertId();
$mkApp = function (string $first, string $last) use ($pdo, $mr, $client, $job): int {
    $pdo->prepare('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")')->execute(['APP-ZH-'.bin2hex(random_bytes(4)), $first, $last, 'zz_'.bin2hex(random_bytes(4)).'@example.test', '09170000000']);
    $ap = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status,screening_rating) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code="DEPLOYED"),NOW(),NOW(),"ACTIVE","FAIR")')->execute(['PMBSI-ZH-'.bin2hex(random_bytes(4)), $ap, $job, $mr, $client]);
    $app = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO deployments(application_id,deployment_no,status,actual_date) VALUES(?,?,"DEPLOYED",CURDATE())')->execute([$app, 'DEP-ZH-'.bin2hex(random_bytes(4))]);
    return $app;
};
$proc = proc_open(array_merge([PHP_BINARY], extension_loaded('gd') ? ['-d', 'extension=gd'] : [], ['-S', '127.0.0.1:'.$port, '-t', $tmp, $tmp.'/router.php']), [['pipe', 'r'], ['file', $tmp.'/server.log', 'w'], ['file', $tmp.'/server.log', 'a']], $pipes, $tmp);
register_shutdown_function(function () use ($proc, $tmp) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } usleep(300000); rmTree($tmp); });
for ($i = 0; $i < 50; $i++) { $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($s) { fclose($s); break; } usleep(100000); }

$fail = 0;
function check(string $label, bool $ok, string $why = ''): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ').$label.($ok || $why === '' ? '' : '  ['.$why.']')."\n"; if (!$ok) $fail++; }

final class Browser {
    private string $jar;
    public function __construct(private int $port) { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    public function req(string $method, string $path, array $post = []): array {
        $ch = curl_init('http://127.0.0.1:'.$this->port.$path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30]);
        if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        $raw = (string)curl_exec($ch); $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        $head = substr($raw, 0, $size);
        return ['code' => $code, 'body' => substr($raw, $size), 'loc' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : ''];
    }
    public function login(string $email, string $password): bool {
        $page = $this->req('GET', '/index.php?page=login');
        if (!preg_match('/name="csrf" value="([^"]+)"/', $page['body'], $m)) return false;
        $r = $this->req('POST', '/index.php?page=login', ['csrf' => html_entity_decode($m[1]), 'action' => 'login', 'email' => $email, 'password' => $password]);
        return $r['code'] === 302 && !str_contains($r['loc'], 'page=login');
    }
}
$csrfOf = static fn(string $html): string => preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
$count = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
$fields = fn(array $x = []) => array_replace(['action' => 'convert_application_to_employee', 'conversion_confirmed' => '1', 'employee_no' => 'ZH-'.bin2hex(random_bytes(3)), 'mobile_no' => '09170000000', 'employment_type_id' => '1', 'hire_date' => '2026-11-02', 'department_id' => '', 'position_id' => '', 'branch_id' => ''], $x);

// ===== A. The form =====
$hr = new Browser($port);
check('A HR signs in', $hr->login($hrEmail, 'Test#1234'));
$nm = 'Zzhttp'.$sfx; $a1 = $mkApp($nm, 'Reyes');
$page = $hr->req('GET', '/index.php?page=hr-convert-employee&id='.$a1);
$b = $page['body'];
check('A convert page loads', $page['code'] === 200, 'code '.$page['code']);
check('A old account choices are gone (Account setup, link later, link existing, temporary password)', !str_contains($b, 'Account setup') && !str_contains($b, 'Link account later') && !str_contains($b, 'Link existing') && !str_contains($b, 'name="account_mode"') && !str_contains($b, 'name="temporary_password"') && !str_contains($b, 'name="user_id"') && !str_contains($b, 'Employee portal access'));
check('A Employer PMBSI is a read-only label', (bool)preg_match('/<label>Employer<\/label><input value="PMBSI" readonly/', $b));
check('A Company is a required select with 2LOG and PMBSI only', (bool)preg_match('/<select name="work_company" id="phase2cCompany" required><option value="">Select Company<\/option><option value="2LOG">2LOG<\/option><option value="PMBSI">PMBSI<\/option><\/select>/', $b));
check('A Company email is a read-only preview that is not posted', (bool)preg_match('/<input type="email" id="phase2cCompanyEmail" readonly/', $b) && !str_contains($b, 'name="company_email"'));
check('A Department options carry their company and the preview script has both domains', str_contains($b, 'data-company="2LOG"') && str_contains($b, '"2LOG":"2log.com"') && str_contains($b, '"PMBSI":"pmbsi.com"'));
$token = $csrfOf($b);

// ===== B. Refusals =====
$u0 = $count('users'); $e0 = $count('employees');
check('B posting without a CSRF token is refused (419) and saves nothing', $hr->req('POST', '/index.php?page=hr-convert-employee&id='.$a1, $fields(['application_id' => (string)$a1, 'first_name' => $nm, 'last_name' => 'Reyes', 'work_company' => 'PMBSI']))['code'] === 419 && $count('users') === $u0 && $count('employees') === $e0);
$plain = new Browser($port);
check('B an Employee-portal user signs in', $plain->login($plainEmail, 'Test#1234'));
$view = $plain->req('GET', '/index.php?page=hr-convert-employee&id='.$a1);
check('B that user cannot open the convert page', $view['code'] !== 200 || !str_contains($view['body'], 'name="work_company"'), 'code '.$view['code']);
$deny = $plain->req('POST', '/index.php?page=hr-convert-employee&id='.$a1, $fields(['csrf' => $token, 'application_id' => (string)$a1, 'first_name' => $nm, 'last_name' => 'Reyes', 'work_company' => 'PMBSI']));
check('B that user cannot convert and nothing is saved', $deny['code'] !== 302 || !str_contains($deny['loc'], 'hr-employee'), $deny['code'].' '.$deny['loc']);
check('B nothing was created by the refused posts', $count('users') === $u0 && $count('employees') === $e0);
$miss = $hr->req('POST', '/index.php?page=hr-convert-employee&id='.$a1, $fields(['csrf' => $token, 'application_id' => (string)$a1, 'first_name' => $nm, 'last_name' => 'Reyes', 'work_company' => '']));
check('B missing Company is refused and nothing is saved', !str_contains($miss['loc'], 'page=hr-employee') && $count('users') === $u0 && $count('employees') === $e0, $miss['code'].' '.$miss['loc']);

// ===== C. Convert (forged account fields are ignored) =====
$ok = $hr->req('POST', '/index.php?page=hr-convert-employee&id='.$a1, $fields(['csrf' => $token, 'application_id' => (string)$a1, 'first_name' => $nm, 'last_name' => 'Reyes', 'work_company' => 'PMBSI', 'account_mode' => 'later', 'user_id' => '1', 'company_email' => 'attacker@pmbsi.com', 'account_email' => 'attacker2@pmbsi.com', 'temporary_password' => 'Attacker-Pass-1']));
check('C convert redirects to the new employee', $ok['code'] === 302 && str_contains($ok['loc'], 'hr-employee'), $ok['code'].' '.$ok['loc']);
$email1 = strtolower($nm).'reyes@pmbsi.com';
$emp = $pdo->prepare('SELECT e.id,e.user_id,e.work_company,e.company_email,u.must_change_password mcp,r.code rc FROM employees e JOIN users u ON u.id=e.user_id JOIN roles r ON r.id=u.role_id WHERE u.email=?'); $emp->execute([$email1]); $e1 = $emp->fetch();
check('C account is created from the Company, Employee role, forced password change, linked', (bool)$e1 && $e1['rc'] === 'EMPLOYEE' && (int)$e1['mcp'] === 1 && $e1['company_email'] === $email1 && $e1['work_company'] === 'PMBSI');
check('C forged user_id, company_email, account_email and temporary password were ignored', (int)($e1['user_id'] ?? 0) > 1 && (int)$pdo->query('SELECT COUNT(*) FROM users WHERE email LIKE "attacker%"')->fetchColumn() === 0);
$again = $hr->req('GET', '/index.php?page=hr-convert-employee&id='.$a1);
check('C opening the convert page again goes to the employee, no second convert', $again['code'] === 302 && str_contains($again['loc'], 'hr-employee'));

// ===== D. 2LOG with a matching Department; mismatch refused =====
$a2 = $mkApp('Zzlog'.$sfx, 'Santos');
$p2 = $hr->req('GET', '/index.php?page=hr-convert-employee&id='.$a2); $t2 = $csrfOf($p2['body']);
$u1 = $count('users');
$bad = $hr->req('POST', '/index.php?page=hr-convert-employee&id='.$a2, $fields(['csrf' => $t2, 'application_id' => (string)$a2, 'first_name' => 'Zzlog'.$sfx, 'last_name' => 'Santos', 'work_company' => 'PMBSI', 'department_id' => (string)$dept]));
check('D Company that does not match the Department is refused and nothing is saved', !str_contains($bad['loc'], 'page=hr-employee') && $count('users') === $u1);
$p2 = $hr->req('GET', '/index.php?page=hr-convert-employee&id='.$a2); $t2 = $csrfOf($p2['body']);
$good = $hr->req('POST', '/index.php?page=hr-convert-employee&id='.$a2, $fields(['csrf' => $t2, 'application_id' => (string)$a2, 'first_name' => 'Zzlog'.$sfx, 'last_name' => 'Santos', 'work_company' => '2LOG', 'department_id' => (string)$dept]));
$email2 = strtolower('Zzlog'.$sfx).'santos@2log.com';
check('D 2LOG convert gets an @2log.com account', $good['code'] === 302 && (int)$pdo->query('SELECT COUNT(*) FROM users WHERE email='.$pdo->quote($email2))->fetchColumn() === 1, $good['code'].' '.$good['loc']);

// ===== E. HR can reveal the new password with the existing button =====
$list = $hr->req('GET', '/index.php?page=admin-users');
$uid = (int)$pdo->query('SELECT id FROM users WHERE email='.$pdo->quote($email1))->fetchColumn();
$rv = $hr->req('POST', '/index.php?page=user-password-reveal', ['csrf' => $csrfOf($list['body']), 'user_id' => (string)$uid]);
$j = json_decode($rv['body'], true) ?: [];
check('E HR reveals a 12-character password for the converted employee', $rv['code'] === 200 && !empty($j['ok']) && strlen((string)($j['password'] ?? '')) === 12, $rv['code'].' '.substr($rv['body'], 0, 80));
$pw = (string)($j['password'] ?? '');
check('E the password is not in any page HTML or flash', $pw !== '' && !str_contains($list['body'], $pw) && !str_contains($again['body'], $pw));
$emp = new Browser($port);
check('E the converted employee signs in with it and is sent to Change password', $emp->login($email1, $pw) && str_contains($emp->req('GET', '/index.php?page=employee-dashboard')['loc'], 'change-password'));

exit($fail ? 1 : 0);
