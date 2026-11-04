<?php
declare(strict_types=1);

/**
 * Pipeline Head Office and Retail split over HTTP (SPEC-pipeline-head-office-retail.md): real login, real forms, real POSTs. Needs a SCRATCH copy:
 *   HRIS_TEST_DB=<scratch> C:\xampp\php\php.exe tests\pipeline_site_split_http_test.php
 * The app is copied to a temp folder and served by php -S; fixtures (Zz) are written to the scratch DB only.
 */
$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_ps_http_'.bin2hex(random_bytes(4));
$port = 8118;
$pdo = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function copyTree(string $from, string $to): void { @mkdir($to, 0777, true); foreach (new DirectoryIterator($from) as $f) { if ($f->isDot()) continue; $f->isDir() ? copyTree($f->getPathname(), $to.DIRECTORY_SEPARATOR.$f->getFilename()) : copy($f->getPathname(), $to.DIRECTORY_SEPARATOR.$f->getFilename()); } }
function rmTree(string $dir): void { if (!is_dir($dir)) return; foreach (new DirectoryIterator($dir) as $f) { if ($f->isDot()) continue; $f->isDir() ? rmTree($f->getPathname()) : @unlink($f->getPathname()); } @rmdir($dir); }
foreach (['app', 'config', 'public', 'vendor'] as $d) copyTree($root.'/'.$d, $tmp.'/'.$d);
copy($root.'/index.php', $tmp.'/index.php'); copy($root.'/router.php', $tmp.'/router.php');
@mkdir($tmp.'/storage/employee_documents', 0777, true);
$key = base64_encode(random_bytes(32));
file_put_contents($tmp.'/config/config.local.php', "<?php\nreturn ['app'=>['debug'=>true],'mail'=>[],'security'=>['credential_key'=>".var_export($key, true)."],'db'=>['host'=>'127.0.0.1','port'=>'3306','database'=>".var_export($dbName, true).",'username'=>'root','password'=>'','charset'=>'utf8mb4'],'incident_documents'=>['storage_root'=>".var_export($tmp.'/private', true)."]];\n");
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
$sfx = bin2hex(random_bytes(3)); $pw = 'Test#1234'; $email = 'zz_ps_hr_'.$sfx.'@example.test';
$pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="HR_USER"),?,?,?,"ACTIVE")')->execute(['Zz ps hr', $email, password_hash($pw, PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO clients(code,name) VALUES(?,?)')->execute(['ZP'.$sfx, 'Zz ps '.$sfx]); $client = (int)$pdo->lastInsertId();
[$b1, $b2] = array_map('intval', $pdo->query('SELECT id FROM branches WHERE site_type="RETAIL_STORE" AND active=1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
$mk = function (string $tag, string $site, array $branches) use ($pdo, $client, $sfx): void {
    $pdo->prepare('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status,requested_by) VALUES(?,?,?,5,0,"REGULAR","NORMAL","APPROVED",NULL)')->execute(['MR-ZP-'.$sfx.$tag, $client, 'Zp '.$tag]);
    $mr = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type,site_type) VALUES(?,?,?,?,?,?,5,"OPEN","Synthetic","REGULAR",?)')->execute(['ZP'.$sfx.$tag, 'zp-'.$sfx.strtolower($tag), 'Zp '.$tag.' '.$sfx, 'x', $client, $mr, $site]);
    $job = (int)$pdo->lastInsertId();
    foreach ($branches as $b) $pdo->prepare('INSERT INTO job_opening_branches(job_opening_id,branch_id) VALUES(?,?)')->execute([$job, $b]);
    $pdo->prepare('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")')->execute(['APP-ZP-'.$sfx.$tag, 'Zq'.$tag, 'Cand'.$sfx, 'zp'.$sfx.strtolower($tag).'@example.test', '09170000000']);
    $app = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code="SCREENING"),NOW(),NOW(),"ACTIVE")')->execute(['AP-ZP-'.$sfx.$tag, $app, $job, $mr, $client]);
};
$mk('HO', 'HEAD_OFFICE', []); $mk('RA', 'RETAIL', [$b1]); $mk('RB', 'RETAIL', [$b2]);
$hr = new Browser($port);
check('fixture HR account signs in', $hr->login($email, $pw));
$has = static fn(string $html, string $tag): bool => str_contains($html, 'Zq'.$tag.' Cand');
$view = static fn() => $GLOBALS['pdo']->query('SELECT site FROM user_pipeline_view WHERE user_id='.$GLOBALS['uid'])->fetchColumn();

// first visit: Head Office, never a mixed board
$p = $hr->req('GET', '/index.php?page=hr-pipeline');
check('Pipeline renders (200)', $p['code'] === 200 && str_contains($p['body'], 'Recruitment Pipeline'));
check('first visit shows Head Office only', $has($p['body'], 'HO') && !$has($p['body'], 'RA') && !$has($p['body'], 'RB'));
check('Head Office tab is the active one, there is no All tab', str_contains($p['body'], 'pv-tab on') && !preg_match('/class="pv-tab[^"]*"[^>]*>\s*All\b/', $p['body']));
check('no branch picker on Head Office', !str_contains($p['body'], 'name="branch[]"'));

// switch to Retail (CSRF POST) and it is remembered
$r = $hr->req('POST', '/index.php?page=hr-pipeline', ['csrf' => $csrfOf($p['body']), 'action' => 'pipeline_save_view', 'site' => 'RETAIL']);
check('switching to Retail redirects to the Pipeline', $r['code'] === 302 && str_contains($r['loc'], 'hr-pipeline'), $r['loc']);
$p = $hr->req('GET', '/index.php?page=hr-pipeline');
check('Retail view shows Retail candidates only', !$has($p['body'], 'HO') && $has($p['body'], 'RA') && $has($p['body'], 'RB'));
check('Retail view has the branch picker', str_contains($p['body'], 'name="branch[]"') && str_contains($p['body'], 'All Retail branches'));
check('saved in the database', $view() === 'RETAIL');

// pick one branch
$hr->req('POST', '/index.php?page=hr-pipeline', ['csrf' => $csrfOf($p['body']), 'action' => 'pipeline_save_view', 'site' => 'RETAIL', 'branches_form' => '1', 'branch' => [(string)$b2]]);
$p = $hr->req('GET', '/index.php?page=hr-pipeline');
check('one branch picked: only that branch candidate', !$has($p['body'], 'RA') && $has($p['body'], 'RB') && str_contains($p['body'], '1 selected'));

// peek at Head Office and come back: the branch choice is kept
$hr->req('POST', '/index.php?page=hr-pipeline', ['csrf' => $csrfOf($p['body']), 'action' => 'pipeline_save_view', 'site' => 'HEAD_OFFICE']);
$p = $hr->req('GET', '/index.php?page=hr-pipeline');
check('can switch to Head Office', $has($p['body'], 'HO') && !$has($p['body'], 'RB'));
$hr->req('POST', '/index.php?page=hr-pipeline', ['csrf' => $csrfOf($p['body']), 'action' => 'pipeline_save_view', 'site' => 'RETAIL']);
$p = $hr->req('GET', '/index.php?page=hr-pipeline');
check('back on Retail the picked branch is still there', $has($p['body'], 'RB') && !$has($p['body'], 'RA'));

// safety
$before = (string)$view();
$hr->req('POST', '/index.php?page=hr-pipeline', ['action' => 'pipeline_save_view', 'site' => 'HEAD_OFFICE']);
check('a POST without CSRF changes nothing', (string)$view() === $before);
$p = $hr->req('GET', '/index.php?page=hr-pipeline&site=junk%27%20OR%201=1');
check('a junk ?site= falls back to Head Office, no error', $p['code'] === 200 && $has($p['body'], 'HO') && !$has($p['body'], 'RB'));
$p = $hr->req('GET', '/index.php?page=hr-pipeline&site=RETAIL&branch[]='.$b1);
check('a link can carry site + branch', $has($p['body'], 'RA') && !$has($p['body'], 'RB'));

// Applicants list follows the same filter when opened from the board
$a = $hr->req('GET', '/index.php?page=hr-applicants&site=RETAIL&branch[]='.$b2);
check('Applicants opened with site+branch lists only those', $a['code'] === 200 && $has($a['body'], 'RB') && !$has($a['body'], 'RA') && !$has($a['body'], 'HO'));
check('Applicants says it is filtered and offers Show all sites', str_contains($a['body'], 'Show all sites'));
$a = $hr->req('GET', '/index.php?page=hr-applicants');
check('Applicants with no filter is unchanged (all three)', $has($a['body'], 'HO') && $has($a['body'], 'RA') && $has($a['body'], 'RB'));

// a non-HR account cannot save a view
$pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="EMPLOYEE"),?,?,?,"ACTIVE")')->execute(['Zz ps emp', 'zz_ps_emp_'.$sfx.'@example.test', password_hash($pw, PASSWORD_DEFAULT)]);
$empId = (int)$pdo->lastInsertId(); $emp = new Browser($port);
$tok = $csrfOf($emp->req('GET', '/index.php?page=login')['body']); // the session token, taken from the sign-in form before logging in
check('fixture employee account signs in', $emp->login('zz_ps_emp_'.$sfx.'@example.test', $pw));
$er = $emp->req('POST', '/index.php?page=hr-pipeline', ['csrf' => $csrfOf($emp->req('GET', '/index.php?page=home')['body']), 'action' => 'pipeline_save_view', 'site' => 'RETAIL']);
check('the employee holds a real CSRF token (so the refusal below is the permission, not the token)', $tok !== '');
$er = $emp->req('POST', '/index.php?page=hr-pipeline', ['csrf' => $tok, 'action' => 'pipeline_save_view', 'site' => 'RETAIL']);
check('employee save is refused with 403', $er['code'] === 403, (string)$er['code']);
check('an employee cannot save a Pipeline view (nothing stored)', (int)$pdo->query('SELECT COUNT(*) FROM user_pipeline_view WHERE user_id='.$empId)->fetchColumn() === 0);

if (getenv('HRIS_BROWSER') === '1') {
    $pdo->exec('DELETE FROM user_pipeline_view_branches WHERE user_id='.$uid); $pdo->exec('DELETE FROM user_pipeline_view WHERE user_id='.$uid); // the browser starts as a first-time user
    $out = $root.'/storage/slice15-private'; @mkdir($out, 0777, true);
    $env = array_merge(getenv(), ['PS_PORT' => (string)$port, 'PS_EMAIL' => $email, 'PS_PASSWORD' => $pw, 'PS_OUT' => $out]);
    $pr = proc_open(['node', $root.'/tests/pipeline_site_split_browser.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $root, $env);
    $o = stream_get_contents($pp[1]).stream_get_contents($pp[2]); $rc = proc_close($pr);
    echo $o; check('browser run finished clean', $rc === 0);
}
foreach ([$uid, $empId] as $id) { $pdo->exec('DELETE FROM user_pipeline_view_branches WHERE user_id='.$id); $pdo->exec('DELETE FROM user_pipeline_view WHERE user_id='.$id); $pdo->exec('DELETE FROM audit_logs WHERE user_id='.$id); $pdo->exec('DELETE FROM users WHERE id='.$id); } // leave no HR users behind: other suites count them
exit($fail ? 1 : 0);
