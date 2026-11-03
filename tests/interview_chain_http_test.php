<?php
declare(strict_types=1);

/**
 * interview-chain over HTTP (SPEC-interview-chain.md): real login, real routes, real HTML. Needs a SCRATCH database copy with migrations 023 and 024:
 *   HRIS_TEST_DB=<scratch> C:\xampp\php\php.exe tests\rec_endorse_manager_http_test.php
 * The app is copied to a temp folder and served by php -S; fixtures are written to the scratch DB only.
 */
$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_ich_http_'.bin2hex(random_bytes(4));
$port = 8102;
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

$tag = 'Zzch'.strtr(bin2hex(random_bytes(2)), '0123456789', 'ghijklmnop');
$pw = 'Test#1234';
$mkUser = static function (string $role, string $label) use ($pdo, $pw): array {
    $email = 'zz_ich_'.$label.'_'.bin2hex(random_bytes(3)).'@example.test';
    $pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code=?),?,?,?,"ACTIVE")')->execute([$role, 'Zz '.$label, $email, password_hash($pw, PASSWORD_DEFAULT)]);
    return ['id' => (int)$pdo->lastInsertId(), 'email' => $email];
};
$uHr = $mkUser('HR_USER', 'hr'); $uM1 = $mkUser('MANAGER', 'mgr1'); $uM2 = $mkUser('MANAGER', 'mgr2'); $uTc = $mkUser('TC', 'tc'); $uStaff = $mkUser('EMPLOYEE', 'staff');
$pdo->prepare('INSERT INTO clients(code,name) VALUES(?,?)')->execute(['ZH'.substr($tag, -4), $tag.' Trading']); $client = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status) VALUES(?,?,?,2,0,"REGULAR","NORMAL","APPROVED")')->execute(['MR-ZH-'.bin2hex(random_bytes(3)), $client, $tag.' Clerk']); $mrId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,1,"OPEN","Synthetic branch","REGULAR")')->execute(['JOB-ZH-'.$tag, strtolower($tag), $tag.' Clerk', 'x', $client, $mrId]); $jobId = (int)$pdo->lastInsertId();
// Head Office job whose requester (direct manager) is the first manager: HR to Manager, Manager to TC (hiring-review-pass-visibility)
$pdo->prepare('UPDATE job_openings SET site_type="HEAD_OFFICE" WHERE id=?')->execute([$jobId]); $pdo->prepare('UPDATE manpower_requests SET requested_by=? WHERE id=?')->execute([$uM1['id'], $mrId]);
$candEmail = 'zz_cand_'.$tag.'@example.test';
$pdo->prepare('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")')->execute(['APP-ZH-'.$tag, 'Zzcand', 'Chn'.$tag, $candEmail, '09175550177']); $applicant = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code="INTERVIEW"),NOW(),NOW(),"ACTIVE")')->execute(['PMBSI-ZH-'.$tag, $applicant, $jobId, $mrId, $client]); $appId = (int)$pdo->lastInsertId();
$stageOf = static fn(): string => (string)$GLOBALS['pdo']->query('SELECT s.code FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.id='.$GLOBALS['appId'])->fetchColumn();
$rows = static fn(): int => (int)$GLOBALS['pdo']->query('SELECT COUNT(*) FROM application_reviewers WHERE application_id='.$GLOBALS['appId'])->fetchColumn();
$n = fn(int $uid, string $kind) => (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE recipient_user_id='.$uid.' AND kind='.$pdo->quote($kind))->fetchColumn();

$hr = new Browser($port); $m1 = new Browser($port); $m2 = new Browser($port); $tc = new Browser($port); $staff = new Browser($port);
check('all fixture accounts sign in', $hr->login($uHr['email'], $pw) && $m1->login($uM1['email'], $pw) && $m2->login($uM2['email'], $pw) && $tc->login($uTc['email'], $pw) && $staff->login($uStaff['email'], $pw));

// HR endorses to the first interviewer; the endorse card now has a Final tick per person
$page = $hr->req('GET', '/index.php?page=hr-applicant&id='.$appId); $tok = $csrfOf($page['body']);
check('A the HR endorse card has a Final tick for each person', str_contains($page['body'], 'name="final[]" value="'.$uM1['id'].'"') && str_contains($page['body'], 'name="final[]" value="'.$uTc['id'].'"'));
$hr->req('POST', '/index.php?page=hr-applicant', ['csrf' => $tok, 'action' => 'endorse', 'application_id' => (string)$appId, 'note' => 'Round 1', 'reviewers' => [(string)$uM1['id']], 'return_page' => 'hr-applicant', 'return_id' => (string)$appId]);
check('A HR endorses to the first interviewer: stage Hiring Review, one reviewer', $stageOf() === 'CLIENT_REVIEW' && $rows() === 1);

// The first interviewer passes the candidate on
$det = $m1->req('GET', '/index.php?page=review-candidate&id='.$appId)['body']; $t1 = $csrfOf($det);
check('B the reviewer sees the Pass to next interviewer card with other people but not themselves', str_contains($det, 'Pass to next interviewer') && str_contains($det, 'name="to[]" value="'.$uTc['id'].'"') && !str_contains($det, 'name="to[]" value="'.$uM1['id'].'"'));
check('B a non-final reviewer is told their Proceed is only an opinion', str_contains($det, 'Your Proceed is recorded as your opinion'));
$pass = ['csrf' => $t1, 'action' => 'review_pass_on', 'application_id' => (string)$appId, 'to' => [(string)$uTc['id']], 'final' => [(string)$uTc['id']], 'note' => 'Last round please', 'return_page' => 'review-candidate', 'return_id' => (string)$appId];
check('B passing on without CSRF is refused and nothing changes', $m1->req('POST', '/index.php?page=review-candidate', array_diff_key($pass, ['csrf' => 1]))['code'] === 419 && $rows() === 1);
$m2->req('POST', '/index.php?page=review-candidate', array_replace($pass, ['csrf' => $csrfOf($m2->req('GET', '/index.php?page=review-candidates')['body'])]));
check('B a manager the candidate was not endorsed to cannot pass it on', $rows() === 1);
$staff->req('POST', '/index.php?page=review-candidate', array_replace($pass, ['csrf' => $csrfOf($staff->req('GET', '/index.php?page=employee-dashboard')['body'])]));
check('B a plain employee cannot pass it on', $rows() === 1);
$r = $m1->req('POST', '/index.php?page=review-candidate', $pass);
check('B the reviewer passes it on: a second reviewer exists and we are back on the candidate page', $r['code'] === 302 && $rows() === 2 && str_contains($r['loc'], 'review-candidate'));
check('B the stage does not change and the new interviewer is notified', $stageOf() === 'CLIENT_REVIEW' && $n($uTc['id'], 'CANDIDATE_REVIEW') === 1);

// The final interviewer
$list = $tc->req('GET', '/index.php?page=review-candidates');
check('C the new interviewer sees the candidate in their list', str_contains($list['body'], 'Zzcand'));
check('C another manager still cannot open the candidate (404)', $m2->req('GET', '/index.php?page=review-candidate&id='.$appId)['code'] === 404);
$tdet = $tc->req('GET', '/index.php?page=review-candidate&id='.$appId)['body'];
check('C the final interviewer is told so and the page shows no applicant email or phone', str_contains($tdet, 'You are the final interviewer') && !str_contains($tdet, $candEmail) && !str_contains($tdet, '09175550177'));
$tc->req('POST', '/index.php?page=review-candidate', ['csrf' => $csrfOf($tdet), 'action' => 'review_decide', 'application_id' => (string)$appId, 'decision' => 'PROCEED', 'comment' => 'Hire', 'return_page' => 'review-candidate', 'return_id' => (string)$appId]);
check('C the final Proceed does not move the candidate to Offer', $stageOf() === 'CLIENT_REVIEW');

// HR takes over
$hp = $hr->req('GET', '/index.php?page=hr-applicant&id='.$appId)['body'];
check('D HR sees Final interview done, next: Offer with who answered and a Move to Offer button', str_contains($hp, 'Final interview done, next: Offer') && str_contains($hp, 'Zz tc answered Proceed') && str_contains($hp, 'Move to Offer'));
check('D HR sees the chain: the Final interviewer badge and who passed the candidate', str_contains($hp, 'Final interviewer') && str_contains($hp, 'passed by Zz mgr1'));
check('D HR is notified that the candidate is ready', $n($uHr['id'], 'CANDIDATE_DECISION') >= 1);
$hr->req('POST', '/index.php?page=hr-applicant', ['csrf' => $csrfOf($hp), 'action' => 'move_stage', 'application_id' => (string)$appId, 'target_stage' => 'OFFER', 'comment' => 'Final interview done', 'return_page' => 'hr-applicant', 'return_id' => (string)$appId]);
check('D only HR moves the candidate to Offer', $stageOf() === 'OFFER');
check('D once at Offer the reviewer no longer has the pass-on card and HR no longer has the final card', !str_contains($tc->req('GET', '/index.php?page=review-candidate&id='.$appId)['body'], 'Pass to next interviewer') && !str_contains($hr->req('GET', '/index.php?page=hr-applicant&id='.$appId)['body'], 'Final interview done, next: Offer'));

$pdo->prepare('DELETE FROM audit_logs WHERE created_at>=? AND user_id IN (?,?,?,?,?)')->execute([$runStart, $uHr['id'], $uM1['id'], $uM2['id'], $uTc['id'], $uStaff['id']]);
echo $fail ? "RESULT FAILED ($fail)\n" : "RESULT OK\n"; exit($fail ? 1 : 0);
