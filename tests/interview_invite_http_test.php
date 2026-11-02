<?php
declare(strict_types=1);

/**
 * interview-invite over HTTP (SPEC-interview-invite.md): real login, real routes, real HTML. Needs a SCRATCH database copy :
 *   HRIS_TEST_DB=<scratch> C:\xampp\php\php.exe tests\rec_endorse_manager_http_test.php
 * The app is copied to a temp folder and served by php -S; fixtures are written to the scratch DB only.
 */
$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_ivi_http_'.bin2hex(random_bytes(4));
$port = 8101;
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

$tag = 'Zzvi'.strtr(bin2hex(random_bytes(2)), '0123456789', 'ghijklmnop');
$pw = 'Test#1234';
$mkUser = static function (string $role, string $label) use ($pdo, $pw): array {
    $email = 'zz_ivi_'.$label.'_'.bin2hex(random_bytes(3)).'@example.test';
    $pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code=?),?,?,?,"ACTIVE")')->execute([$role, 'Zz '.$label, $email, password_hash($pw, PASSWORD_DEFAULT)]);
    return ['id' => (int)$pdo->lastInsertId(), 'email' => $email];
};
$uHr = $mkUser('HR_USER', 'hr'); $uM1 = $mkUser('MANAGER', 'mgr1'); $uStaff = $mkUser('EMPLOYEE', 'staff');
$pdo->prepare('INSERT INTO clients(code,name) VALUES(?,?)')->execute(['ZV'.substr($tag, -4), $tag.' Trading']); $client = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status) VALUES(?,?,?,2,0,"REGULAR","NORMAL","APPROVED")')->execute(['MR-ZV-'.bin2hex(random_bytes(3)), $client, $tag.' Clerk']); $mrId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,1,"OPEN","Synthetic branch","REGULAR")')->execute(['JOB-ZV-'.$tag, strtolower($tag), $tag.' Clerk', 'x', $client, $mrId]); $jobId = (int)$pdo->lastInsertId();
$candEmail = 'zz_cand_'.$tag.'@example.test';
$pdo->prepare('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")')->execute(['APP-ZV-'.$tag, 'Zzcand', 'Inv'.$tag, $candEmail, '09175550188']); $applicant = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code="INTERVIEW"),NOW(),NOW(),"ACTIVE")')->execute(['PMBSI-ZV-'.$tag, $applicant, $jobId, $mrId, $client]); $appId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO application_reviewers(application_id,reviewer_user_id,endorsed_by,endorsed_at) VALUES(?,?,?,NOW())')->execute([$appId, $uM1['id'], $uHr['id']]);
$ivCount = static fn(): int => (int)$GLOBALS['pdo']->query('SELECT COUNT(*) FROM interviews WHERE application_id='.$GLOBALS['appId'])->fetchColumn();

$hr = new Browser($port); $m1 = new Browser($port); $staff = new Browser($port);
check('all fixture accounts sign in', $hr->login($uHr['email'], $pw) && $m1->login($uM1['email'], $pw) && $staff->login($uStaff['email'], $pw));

// HR page: schedule form hint, then schedule (mail is off in this server, so the invite is skipped and the timeline says so)
$page = $hr->req('GET', '/index.php?page=hr-applicant&id='.$appId); $tok = $csrfOf($page['body']);
check('HR schedule form tells HR the applicant is emailed', str_contains($page['body'], 'The applicant is emailed the date, time and this link.'));
$sched = ['csrf' => $tok, 'action' => 'schedule_interview', 'application_id' => (string)$appId, 'interview_type' => 'Mid Interview', 'scheduled_at' => date('Y-m-d\TH:i', strtotime('+2 days')), 'location_or_link' => 'https://meet.google.com/abc-defg-hij', 'notes' => 'internal only', 'return_page' => 'hr-applicant', 'return_id' => (string)$appId];
$r = $hr->req('POST', '/index.php?page=hr-applicant', $sched);
check('HR schedules: the interview is saved even though email is off', $r['code'] === 302 && $ivCount() === 1);
$page = $hr->req('GET', '/index.php?page=hr-applicant&id='.$appId)['body'];
check('HR timeline says the invite was not sent because email sending is off', str_contains($page, 'Invite email not sent: email sending is off'));
$ivId = (int)$pdo->query('SELECT id FROM interviews WHERE application_id='.$appId)->fetchColumn();
check('HR sees a Resend invite button for the interview', str_contains($page, 'value="resend_interview_invite"') && str_contains($page, 'name="interview_id" value="'.$ivId.'"'));

// Resend route: CSRF, permission
$resend = ['csrf' => $tok, 'action' => 'resend_interview_invite', 'application_id' => (string)$appId, 'interview_id' => (string)$ivId, 'return_page' => 'hr-applicant', 'return_id' => (string)$appId];
check('resend without CSRF is refused', $hr->req('POST', '/index.php?page=hr-applicant', array_diff_key($resend, ['csrf' => 1]))['code'] === 419);
$staffTok = $csrfOf($staff->req('GET', '/index.php?page=employee-dashboard')['body']);
$sr = $staff->req('POST', '/index.php?page=hr-applicant', array_replace($resend, ['csrf' => $staffTok]));
check('an Employee-role user cannot resend (no redirect back to the applicant page)', !str_contains($sr['loc'], 'hr-applicant'));
$r = $hr->req('POST', '/index.php?page=hr-applicant', $resend);
check('HR resend with email off is reported and nothing is emailed (back on the applicant page)', $r['code'] === 302 && str_contains($r['loc'], 'hr-applicant'));

// Reviewer: hint, and the applicant address never shows
$det = $m1->req('GET', '/index.php?page=review-candidate&id='.$appId)['body'];
check('the reviewer schedule form says the applicant is emailed', str_contains($det, 'The applicant is emailed the date, time and this link.'));
check('the reviewer page never shows the applicant email or phone, not even masked', !str_contains($det, $candEmail) && !str_contains($det, 'z***@') && !str_contains($det, '09175550188'));
$rtok = $csrfOf($det);
$m1->req('POST', '/index.php?page=review-candidate&id='.$appId, ['csrf' => $rtok, 'action' => 'review_schedule', 'application_id' => (string)$appId, 'interview_type' => 'Final Interview', 'scheduled_at' => date('Y-m-d\TH:i', strtotime('+3 days')), 'location_or_link' => 'Room 9', 'notes' => '', 'return_page' => 'review-candidate', 'return_id' => (string)$appId]);
check('the reviewer schedules too and the interview is saved', $ivCount() === 2);
check('the reviewer page after scheduling still has no applicant email', !str_contains($m1->req('GET', '/index.php?page=review-candidate&id='.$appId)['body'], $candEmail));

$pdo->prepare('DELETE FROM audit_logs WHERE created_at>=? AND user_id IN (?,?,?)')->execute([$runStart, $uHr['id'], $uM1['id'], $uStaff['id']]);
echo $fail ? "RESULT FAILED ($fail)\n" : "RESULT OK\n"; exit($fail ? 1 : 0);
