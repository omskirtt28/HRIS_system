<?php
declare(strict_types=1);

/**
 * rec-manpower-intake over HTTP: real login, real routes, real HTML. Needs a SCRATCH database copy:
 *   mysql -uroot -e "DROP DATABASE IF EXISTS pmbsi_hris_test; CREATE DATABASE pmbsi_hris_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 *   mysqldump -uroot --single-transaction --routines --triggers pmbsi_hris | mysql -uroot pmbsi_hris_test
 *   HRIS_TEST_DB=pmbsi_hris_test C:\xampp\php\php.exe tests\rec_manpower_intake_http_test.php
 * The app is copied to a temp folder, pointed at the scratch DB with a config.local.php there (the repo is not touched),
 * served by php -S, and exercised with curl. Fixtures and passwords are written to the scratch DB only. Drop it afterwards.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_mi_http_'.bin2hex(random_bytes(4));
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

$csrfOf = static fn(string $html): string => preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
$tag = 'Zzmi'.strtr(bin2hex(random_bytes(2)), '0123456789', 'ghijklmnop');

// ---- three synthetic Manpower Request tickets filed by a fixture employee (separate process, scratch DB only) ----
putenv('HRIS_TEST_DB='.$dbName);
$ids = json_decode((string)shell_exec('"'.PHP_BINARY.'" '.escapeshellarg($root.'/tests/rec_manpower_intake_http_fixture.php').' 2>&1'), true);
if (!is_array($ids) || count($ids) !== 3) { fwrite(STDERR, "Fixture tickets were not created.\n"); exit(2); }
[$t1, $t2, $t3] = array_map('intval', $ids);
$tk = static function (int $id) use ($pdo): array { $q = $pdo->prepare('SELECT status,version FROM tickets WHERE id=?'); $q->execute([$id]); return $q->fetch(); };
$mr = static function (int $ticket) use ($pdo): array { $q = $pdo->prepare('SELECT m.*,c.name employer FROM manpower_requests m JOIN clients c ON c.id=m.client_id WHERE m.ticket_id=?'); $q->execute([$ticket]); return $q->fetch() ?: []; };
$staffEmail = 'zz_http_staff_'.bin2hex(random_bytes(3)).'@example.test';
$pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="EMPLOYEE"),?,?,?,"ACTIVE")')->execute(['Zz Staff', $staffEmail, password_hash('Test#1234', PASSWORD_DEFAULT)]);
$employer = $tag.' Staffing';

$hr = new Browser($port);
check('HR signs in', $hr->login($hrEmail, 'Test#1234'));

// ===== A. the page =====
$page = $hr->req('GET', '/index.php?page=hr-manpower');
check('A Manpower Requests loads for an HR user', $page['code'] === 200, 'code '.$page['code']);
check('A the filed tickets are listed as awaiting HR with Approve and Decline', str_contains($page['body'], 'Ticket #'.$t1) && str_contains($page['body'], 'Awaiting HR') && str_contains($page['body'], 'data-mi-open="mi-approve"') && str_contains($page['body'], 'data-mi-open="mi-decline"'));
check('A the HR create-request form is gone: HR does not file requests (D5)', !str_contains($page['body'], 'Create new manpower request'));
$tok = $csrfOf($page['body']);
$v1 = (int)$tk($t1)['version'];
$approve = ['csrf' => $tok, 'action' => 'approve_manpower', 'ticket_id' => (string)$t1, 'version' => (string)$v1, 'client_id' => '', 'new_employer' => $employer, 'position_title' => 'Sales Clerk', 'priority' => 'URGENT', 'remarks' => ''];

// ===== B. CSRF, permission =====
check('B approve without CSRF is refused', $hr->req('POST', '/index.php?page=hr-manpower', array_diff_key($approve, ['csrf' => 1]))['code'] === 419 && !$mr($t1));
$emp = new Browser($port);
check('B staff signs in', $emp->login($staffEmail, 'Test#1234'));
$emp->req('POST', '/index.php?page=hr-manpower', array_replace($approve, ['csrf' => $csrfOf($emp->req('GET', '/index.php?page=employee-dashboard')['body'])]));
check('B an Employee-role user cannot approve', !$mr($t1) && $tk($t1)['status'] === 'PENDING_HR');
$r = $hr->req('POST', '/index.php?page=hr-manpower', array_replace($approve, ['new_employer' => '']));
check('B approve without an employer is refused and nothing changes', !$mr($t1) && $tk($t1)['status'] === 'PENDING_HR' && $r['code'] === 302);

// ===== C. approve with a new employer =====
$r = $hr->req('POST', '/index.php?page=hr-manpower', $approve);
$m = $mr($t1);
check('C HR approves, lands back on the list', $r['code'] === 302 && str_contains($r['loc'], 'hr-manpower'), $r['code'].' '.$r['loc']);
check('C a request is created for the new employer with the HR title, priority and headcount', $m && $m['employer'] === $employer && $m['position_title'] === 'Sales Clerk' && $m['priority'] === 'URGENT' && (int)$m['requested_headcount'] === 3 && $m['status'] === 'APPROVED');
check('C the ticket is completed', $tk($t1)['status'] === 'COMPLETED');
$page = $hr->req('GET', '/index.php?page=hr-manpower');
check('C the list now shows the request number and employer', str_contains($page['body'], 'MR-'.date('Ymd').'-T'.$t1) && str_contains($page['body'], $employer));
$hr->req('POST', '/index.php?page=hr-manpower', $approve);
check('C a repeated submit (double click) adds no second request or employer', (int)$pdo->query('SELECT COUNT(*) FROM manpower_requests WHERE ticket_id='.$t1)->fetchColumn() === 1 && (int)$pdo->query('SELECT COUNT(*) FROM clients WHERE name='.$pdo->quote($employer))->fetchColumn() === 1);

// ===== D. decline =====
$v2 = (int)$tk($t2)['version'];
$hr->req('POST', '/index.php?page=hr-manpower', ['csrf' => $tok, 'action' => 'decline_manpower', 'ticket_id' => (string)$t2, 'version' => (string)$v2, 'remarks' => '  ']);
check('D decline without a reason is refused', $tk($t2)['status'] === 'PENDING_HR');
$hr->req('POST', '/index.php?page=hr-manpower', ['csrf' => $tok, 'action' => 'decline_manpower', 'ticket_id' => (string)$t2, 'version' => (string)$v2, 'remarks' => 'No budget this quarter']);
check('D decline with a reason rejects the ticket and creates no request', $tk($t2)['status'] === 'REJECTED' && !$mr($t2));
$open = $hr->req('GET', '/index.php?page=hr-manpower')['body'];
$declined = $hr->req('GET', '/index.php?page=hr-manpower&filter=declined')['body'];
check('D the declined ticket is hidden from the default list and shown under Declined', !str_contains($open, 'Ticket #'.$t2) && str_contains($declined, 'Ticket #'.$t2) && str_contains($declined, 'Declined'));
check('D the third ticket is still waiting', str_contains($open, 'Ticket #'.$t3) && $tk($t3)['status'] === 'PENDING_HR');

// ===== E. the old manual create action is gone (D5) =====
$before = (int)$pdo->query('SELECT COUNT(*) FROM manpower_requests')->fetchColumn();
$hr->req('POST', '/index.php?page=hr-manpower', ['csrf' => $tok, 'action' => 'create_manpower', 'client_id' => '', 'new_employer' => $tag.' Walk-in', 'branch_id' => '', 'department_id' => '', 'position_title' => 'Walk-in role', 'requested_headcount' => '2', 'employment_type' => 'FULL_TIME', 'priority' => 'NORMAL']);
check('E the old create_manpower action creates no request', (int)$pdo->query('SELECT COUNT(*) FROM manpower_requests')->fetchColumn() === $before);
check('E and adds no employer', (int)$pdo->query('SELECT COUNT(*) FROM clients WHERE name='.$pdo->quote($tag.' Walk-in'))->fetchColumn() === 0);

exit($fail ? 1 : 0);
