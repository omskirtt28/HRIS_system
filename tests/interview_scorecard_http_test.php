<?php
declare(strict_types=1);

/**
 * applicant-profile-polish T3 over HTTP (SPEC-applicant-profile-polish.md): real login, real routes, real HTML. Needs a SCRATCH database copy with migration 030:
 *   HRIS_TEST_DB=<scratch> C:\xampp\php\php.exe testsinterview_scorecard_http_test.php
 * The app is copied to a temp folder and served by php -S; fixtures are written to the scratch DB only.
 */
$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_isc_http_'.bin2hex(random_bytes(4));
$port = 8106;
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
$tag = 'Zzisc'.strtr(bin2hex(random_bytes(2)), '0123456789', 'ghijklmnop');
$pw = 'Test#1234';
$mkUser = static function (string $role, string $label) use ($pdo, $pw): array {
    $email = 'zz_isc_'.$label.'_'.bin2hex(random_bytes(3)).'@example.test';
    $pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code=?),?,?,?,"ACTIVE")')->execute([$role, 'Zz '.$label, $email, password_hash($pw, PASSWORD_DEFAULT)]);
    return ['id' => (int)$pdo->lastInsertId(), 'email' => $email];
};
$uHr = $mkUser('HR_USER', 'hr'); $uMgr = $mkUser('MANAGER', 'mgr'); $uAdl = $mkUser('ADL', 'adl');
$pdo->prepare('INSERT INTO clients(code,name) VALUES(?,?)')->execute(['ZS'.substr($tag, -4), $tag.' Trading']); $client = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status) VALUES(?,?,?,1,0,"REGULAR","NORMAL","APPROVED")')->execute(['MR-ZS-'.bin2hex(random_bytes(3)), $client, $tag.' Clerk']); $mr = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type,site_type) VALUES(?,?,?,?,?,?,1,"OPEN","Synthetic","REGULAR","HEAD_OFFICE")')->execute(['JOB-ZS-'.$tag, strtolower($tag), $tag.' Clerk', 'd', $client, $mr]); $job = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")')->execute(['APP-ZS-'.$tag, 'Zzcand', 'Rate'.$tag, 'zz_c_'.$tag.'@example.test', '09170000000']); $ap = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code="INTERVIEW"),NOW(),NOW(),"ACTIVE")')->execute(['PMBSI-ZS-'.bin2hex(random_bytes(3)), $ap, $job, $mr, $client]); $appId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO application_reviewers(application_id,reviewer_user_id,endorsed_by,endorsed_at) VALUES(?,?,?,NOW())')->execute([$appId, $uMgr['id'], $uHr['id']]);
$ratingOf = static function (): ?string { $v = $GLOBALS['pdo']->query('SELECT screening_rating FROM applications WHERE id='.$GLOBALS['appId'])->fetchColumn(); return ($v === false || $v === null) ? null : (string)$v; };

$hr = new Browser($port); $mgr = new Browser($port); $adl = new Browser($port);
check('accounts sign in', $hr->login($uHr['email'], $pw) && $mgr->login($uMgr['email'], $pw) && $adl->login($uAdl['email'], $pw));

$goodPost = ['action' => 'save_scorecard', 'application_id' => (string)$appId, 'return_page' => 'hr-applicant', 'score_communication' => '4', 'score_job_knowledge' => '5', 'score_attitude' => '3', 'score_culture_fit' => '4', 'score_overall' => '4', 'recommendation' => 'PROCEED', 'note' => 'Good'];
$cardCount = static fn(): int => (int)$GLOBALS['pdo']->query('SELECT COUNT(*) FROM interview_scorecards WHERE application_id='.$GLOBALS['appId'])->fetchColumn();

$page = $hr->req('GET', '/index.php?page=hr-applicant&id='.$appId); $tok = $csrfOf($page['body']);
check('A HR page shows the Interview scorecard card with five criteria and three recommendations', str_contains($page['body'], 'Interview scorecard') && str_contains($page['body'], 'name="score_communication"') && str_contains($page['body'], 'name="score_overall"') && str_contains($page['body'], 'value="NO_PROCEED"'));
check('A the card sits in the side column after the Screening rating', ($a = strpos($page['body'], 'class="ap-side"')) !== false && ($b = strpos($page['body'], 'Interview scorecard')) !== false && $b > $a);
check('A no Manager Review wording is left on the page', !str_contains($page['body'], 'Manager Review') && !str_contains($page['body'], 'Manager review'));
check('B saving without CSRF is refused', $hr->req('POST', '/index.php?page=hr-applicant', $goodPost)['code'] === 419 && $cardCount() === 0);
$hr->req('POST', '/index.php?page=hr-applicant', array_replace($goodPost, ['csrf' => $tok, 'score_attitude' => '9']));
check('B a forged score changes nothing', $cardCount() === 0);
$hr->req('POST', '/index.php?page=hr-applicant', array_replace($goodPost, ['csrf' => $tok, 'recommendation' => 'GREAT']));
check('B a forged recommendation changes nothing', $cardCount() === 0);
$r = $hr->req('POST', '/index.php?page=hr-applicant', array_replace($goodPost, ['csrf' => $tok]));
check('B HR saves a scorecard and returns to the applicant', $r['code'] === 302 && str_contains($r['loc'], 'hr-applicant') && $cardCount() === 1);
$page = $hr->req('GET', '/index.php?page=hr-applicant&id='.$appId);
check('B the card shows the 4.0 / 5 average and the saved choice', str_contains($page['body'], '4.0 / 5') && str_contains($page['body'], 'name="score_job_knowledge" value="5" checked'));

$mp = $mgr->req('GET', '/index.php?page=review-candidate&id='.$appId);
check('C the routed Manager sees the card but not HR\'s scorecard row', $mp['code'] === 200 && str_contains($mp['body'], 'Interview scorecard') && !str_contains($mp['body'], '4.0 / 5'));
$mgr->req('POST', '/index.php?page=review-candidate', array_replace($goodPost, ['csrf' => $csrfOf($mp['body']), 'return_page' => 'review-candidate', 'recommendation' => 'HOLD']));
check('C the Manager saves their own scorecard (two rows now)', $cardCount() === 2);
$ap = $adl->req('GET', '/index.php?page=review-candidate&id='.$appId);
check('D an ADL the applicant did not go through gets 404, not the card', $ap['code'] === 404 && !str_contains($ap['body'], 'Interview scorecard'));
$adl->req('POST', '/index.php?page=review-candidate', array_replace($goodPost, ['csrf' => $csrfOf($adl->req('GET', '/index.php?page=employee-dashboard')['body']), 'return_page' => 'review-candidate']));
check('D and cannot score by POST either', $cardCount() === 2);

$pdo->exec('DELETE FROM interview_scorecards WHERE application_id='.$appId);
$pdo->exec('UPDATE applications SET current_stage_id=(SELECT id FROM recruitment_stages WHERE code="SCREENING") WHERE id='.$appId);
$page = $hr->req('GET', '/index.php?page=hr-applicant&id='.$appId);
check('E before the Interview stage the card is not shown', !str_contains($page['body'], 'name="score_communication"'));
check('F no PHP warnings or fatals during the run', !preg_match('/PHP (Warning|Fatal|Notice|Deprecated)/', (string)@file_get_contents($tmp.'/server.log')));
echo $fail === 0 ? "ALL PASS\n" : "FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
