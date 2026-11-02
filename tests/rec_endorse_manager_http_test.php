<?php
declare(strict_types=1);

/**
 * rec-endorse-manager over HTTP (SPEC-rec-endorse-manager.md): real login, real routes, real HTML. Needs a SCRATCH database copy with migration 023:
 *   HRIS_TEST_DB=<scratch> C:\xampp\php\php.exe tests\rec_endorse_manager_http_test.php
 * The app is copied to a temp folder and served by php -S; fixtures are written to the scratch DB only.
 */
$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_rem_http_'.bin2hex(random_bytes(4));
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

$tag = 'Zzre'.strtr(bin2hex(random_bytes(2)), '0123456789', 'ghijklmnop');
$pw = 'Test#1234';
$mkUser = static function (string $role, string $label) use ($pdo, $pw): array {
    $email = 'zz_rem_'.$label.'_'.bin2hex(random_bytes(3)).'@example.test';
    $pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code=?),?,?,?,"ACTIVE")')->execute([$role, 'Zz '.$label, $email, password_hash($pw, PASSWORD_DEFAULT)]);
    return ['id' => (int)$pdo->lastInsertId(), 'email' => $email, 'name' => 'Zz '.$label];
};
$uHr = $mkUser('HR_USER', 'hr'); $uM1 = $mkUser('MANAGER', 'mgr1'); $uM2 = $mkUser('MANAGER', 'mgr2'); $uTc = $mkUser('TC', 'tc'); $uStaff = $mkUser('EMPLOYEE', 'staff');
$pdo->prepare('INSERT INTO clients(code,name) VALUES(?,?)')->execute(['ZR'.substr($tag, -4), $tag.' Trading']); $client = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status) VALUES(?,?,?,2,0,"REGULAR","NORMAL","APPROVED")')->execute(['MR-ZR-'.bin2hex(random_bytes(3)), $client, $tag.' Clerk']); $mrId = (int)$pdo->lastInsertId();
$jobTitle = $tag.' Sales Clerk';
$pdo->prepare('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,1,"OPEN","Synthetic branch","REGULAR")')->execute(['JOB-ZR-'.$tag, strtolower($tag), $jobTitle, 'x', $client, $mrId]); $jobId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")')->execute(['APP-ZR-'.$tag, 'Zzcand', 'Rev'.$tag, 'zz_cand_'.$tag.'@example.test', '09175550199']);
$applicant = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status,why_fit) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code="INTERVIEW"),NOW(),NOW(),"ACTIVE","Synthetic screening answer")')->execute(['PMBSI-ZR-'.$tag, $applicant, $jobId, $mrId, $client]);
$appId = (int)$pdo->lastInsertId();
@mkdir($tmp.'/storage/resumes', 0777, true);
file_put_contents($tmp.'/storage/resumes/zr'.$tag.'.pdf', "%PDF-1.4\n%zz resume\n");
$pdo->prepare('INSERT INTO application_documents(application_id,document_type,original_name,stored_name,mime_type,file_size,created_at) VALUES(?,"RESUME","zz-resume.pdf",?,"application/pdf",20,NOW())')->execute([$appId, 'zr'.$tag.'.pdf']);
$stageOf = static fn(): string => (string)$GLOBALS['pdo']->query('SELECT s.code FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.id='.$GLOBALS['appId'])->fetchColumn();
$rows = static fn(): int => (int)$GLOBALS['pdo']->query('SELECT COUNT(*) FROM application_reviewers WHERE application_id='.$GLOBALS['appId'])->fetchColumn();
$decisionOf = static function (int $uid) use ($pdo, $appId): ?string { $q = $pdo->prepare('SELECT decision FROM application_reviewers WHERE application_id=? AND reviewer_user_id=?'); $q->execute([$appId, $uid]); $v = $q->fetch(); return $v === false ? null : (string)($v['decision'] ?? ''); };

$hr = new Browser($port); $m1 = new Browser($port); $m2 = new Browser($port); $tc = new Browser($port); $staff = new Browser($port); $anon = new Browser($port);
check('all fixture accounts sign in', $hr->login($uHr['email'], $pw) && $m1->login($uM1['email'], $pw) && $m2->login($uM2['email'], $pw) && $tc->login($uTc['email'], $pw) && $staff->login($uStaff['email'], $pw));

// ===== A. HR card =====
$page = $hr->req('GET', '/index.php?page=hr-applicant&id='.$appId);
$tok = $csrfOf($page['body']);
check('A the HR page has the Endorse to Manager review card with a name filter and reviewer checkboxes', str_contains($page['body'], 'Endorse to Manager review') && str_contains($page['body'], 'id="rv-filter"') && str_contains($page['body'], 'name="reviewers[]" value="'.$uM1['id'].'"') && str_contains($page['body'], 'name="reviewers[]" value="'.$uTc['id'].'"'));
check('A a plain employee is not in the list and the old client endorsement card is gone', !str_contains($page['body'], 'name="reviewers[]" value="'.$uStaff['id'].'"') && !str_contains($page['body'], 'Client endorsement') && !str_contains($page['body'], 'Endorse to client'));
check('A no reviewer is pre-checked for a request without a ticket', !preg_match('/name="reviewers\[\]" value="\d+" checked/', $page['body']));

// ===== B. HR endorses =====
$endorse = ['csrf' => $tok, 'action' => 'endorse', 'application_id' => (string)$appId, 'note' => 'Strong candidate', 'return_page' => 'hr-applicant', 'return_id' => (string)$appId];
check('B endorsing without CSRF is refused', $hr->req('POST', '/index.php?page=hr-applicant', array_diff_key($endorse + ['reviewers' => [(string)$uM1['id']]], ['csrf' => 1]))['code'] === 419 && $rows() === 0);
$hr->req('POST', '/index.php?page=hr-applicant', $endorse);
check('B endorsing with nobody ticked changes nothing', $rows() === 0 && $stageOf() === 'INTERVIEW');
$hr->req('POST', '/index.php?page=hr-applicant', $endorse + ['reviewers' => [(string)$uStaff['id']]]);
check('B posting a user who is not a reviewer (forged id) changes nothing', $rows() === 0 && $stageOf() === 'INTERVIEW');
$staff->req('POST', '/index.php?page=hr-applicant', array_replace($endorse, ['csrf' => $csrfOf($staff->req('GET', '/index.php?page=employee-dashboard')['body'])]) + ['reviewers' => [(string)$uM1['id']]]);
check('B an Employee-role user cannot endorse', $rows() === 0);
$r = $hr->req('POST', '/index.php?page=hr-applicant', $endorse + ['reviewers' => [(string)$uM1['id'], (string)$uTc['id']]]);
check('B HR endorses to two people: two rows, stage Manager Review, back on the applicant page', $r['code'] === 302 && $rows() === 2 && $stageOf() === 'CLIENT_REVIEW' && str_contains($r['loc'], 'hr-applicant'));
$page = $hr->req('GET', '/index.php?page=hr-applicant&id='.$appId)['body'];
check('B the HR page now shows Manager Review, both names and Waiting; no Client Review wording', str_contains($page, 'Manager Review') && str_contains($page, 'Zz mgr1') && str_contains($page, 'Zz tc') && str_contains($page, 'Waiting') && !str_contains($page, 'Client Review'));

// ===== C. reviewers see only their own =====
$list1 = $m1->req('GET', '/index.php?page=review-candidates');
check('C the endorsed Manager sees the candidate and the job in Candidates for review', $list1['code'] === 200 && str_contains($list1['body'], 'Zzcand') && str_contains($list1['body'], $jobTitle));
check('C the sidebar shows the Candidates for review link', str_contains($list1['body'], 'page=review-candidates'));
$list2 = $m2->req('GET', '/index.php?page=review-candidates');
check('C another Manager sees an empty list', $list2['code'] === 200 && !str_contains($list2['body'], 'Zzcand') && str_contains($list2['body'], 'Nothing has been endorsed'));
check('C another Manager cannot open the candidate by id (404)', $m2->req('GET', '/index.php?page=review-candidate&id='.$appId)['code'] === 404);
$det = $m1->req('GET', '/index.php?page=review-candidate&id='.$appId);
check('C the endorsed Manager sees the note, screening answer and the decision and schedule forms', $det['code'] === 200 && str_contains($det['body'], 'Strong candidate') && str_contains($det['body'], 'Synthetic screening answer') && str_contains($det['body'], 'Your decision') && str_contains($det['body'], 'Schedule an interview'));
check('C the reviewer page shows no email or phone number', !str_contains($det['body'], 'zz_cand_'.$tag) && !str_contains($det['body'], '09175550199'));
check('C a plain employee, HR and a logged-out visitor have no access to the reviewer pages', $staff->req('GET', '/index.php?page=review-candidates')['code'] !== 200 && $hr->req('GET', '/index.php?page=review-candidates')['code'] !== 200 && $anon->req('GET', '/index.php?page=review-candidates')['code'] !== 200);
check('C a reviewer cannot open HR recruitment screens', $m1->req('GET', '/index.php?page=hr-applicants')['code'] !== 200 && $m1->req('GET', '/index.php?page=hr-applicant&id='.$appId)['code'] !== 200);
$n = fn(int $uid, string $kind) => (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE recipient_user_id='.$uid.' AND kind='.$pdo->quote($kind))->fetchColumn();
check('C each recipient got one notification and the other Manager got none', $n($uM1['id'], 'CANDIDATE_REVIEW') === 1 && $n($uTc['id'], 'CANDIDATE_REVIEW') === 1 && $n($uM2['id'], 'CANDIDATE_REVIEW') === 0);

// ===== D. resume =====
$res = $m1->req('GET', '/index.php?page=review-resume&id='.$appId);
check('D the endorsed reviewer can open the resume (PDF)', $res['code'] === 200 && stripos($res['head'], 'application/pdf') !== false && str_contains($res['body'], '%PDF'));
check('D another Manager, an employee and a logged-out visitor cannot', $m2->req('GET', '/index.php?page=review-resume&id='.$appId)['code'] === 404 && $staff->req('GET', '/index.php?page=review-resume&id='.$appId)['code'] !== 200 && $anon->req('GET', '/index.php?page=review-resume&id='.$appId)['code'] !== 200);
check('D the HR resume route stays closed to a reviewer', $m1->req('GET', '/index.php?page=hr-application-resume&id='.$appId)['code'] !== 200);
check('D HR can still open the resume', $hr->req('GET', '/index.php?page=hr-application-resume&id='.$appId)['code'] === 200);

// ===== E. decisions =====
$tokM1 = $csrfOf($det['body']);
$decide = fn(Browser $b, string $tk, string $d, string $c = '') => $b->req('POST', '/index.php?page=review-candidate', ['csrf' => $tk, 'action' => 'review_decide', 'application_id' => (string)$appId, 'decision' => $d, 'comment' => $c, 'return_page' => 'review-candidate', 'return_id' => (string)$appId]);
check('E answering without CSRF is refused', $decide($m1, '', 'PROCEED')['code'] === 419 && $decisionOf($uM1['id']) === '');
$decide($m2, $csrfOf($m2->req('GET', '/index.php?page=review-candidates')['body']), 'PROCEED', 'sneaky');
check('E a Manager who was not endorsed to cannot answer, and the stage does not move', $decisionOf($uM2['id']) === null && $stageOf() === 'CLIENT_REVIEW');
$decide($m1, $tokM1, 'NOT_SUITABLE', '');
check('E Not suitable without a reason is refused', $decisionOf($uM1['id']) === '');
$decide($m1, $tokM1, 'ANOTHER_INTERVIEW', 'Meet again');
check('E Needs another interview is saved and keeps the stage', $decisionOf($uM1['id']) === 'ANOTHER_INTERVIEW' && $stageOf() === 'CLIENT_REVIEW');
$hrPage = $hr->req('GET', '/index.php?page=hr-applicant&id='.$appId)['body'];
check('E HR sees the answer and the comment, and is notified', str_contains($hrPage, 'Another interview') && str_contains($hrPage, 'Meet again') && $n($uHr['id'], 'CANDIDATE_DECISION') >= 1);
$decide($m1, $tokM1, 'PROCEED', 'Hire');
check('E Proceed is saved but never moves the candidate to Offer; with no final interviewer named HR is told it is ready', $stageOf() === 'CLIENT_REVIEW' && $decisionOf($uM1['id']) === 'PROCEED' && $n($uHr['id'], 'CANDIDATE_DECISION') >= 2);

// ===== F. reviewer schedules an interview =====
$tokTc = $csrfOf($tc->req('GET', '/index.php?page=review-candidate&id='.$appId)['body']);
$when = date('Y-m-d\TH:i', strtotime('+2 days'));
$sched = ['csrf' => $tokTc, 'action' => 'review_schedule', 'application_id' => (string)$appId, 'interview_type' => 'Final Interview', 'scheduled_at' => $when, 'location_or_link' => 'Room 9', 'notes' => '', 'return_page' => 'review-candidate', 'return_id' => (string)$appId];
$m2->req('POST', '/index.php?page=review-candidate', array_replace($sched, ['csrf' => $csrfOf($m2->req('GET', '/index.php?page=review-candidates')['body'])]));
check('F someone not endorsed to cannot schedule', (int)$pdo->query('SELECT COUNT(*) FROM interviews WHERE application_id='.$appId)->fetchColumn() === 0);
$tc->req('POST', '/index.php?page=review-candidate', array_replace($sched, ['scheduled_at' => '']));
check('F a missing date is refused', (int)$pdo->query('SELECT COUNT(*) FROM interviews WHERE application_id='.$appId)->fetchColumn() === 0);
$tc->req('POST', '/index.php?page=review-candidate', $sched);
check('F an endorsed reviewer schedules an interview under their own name; Manager Review is not pushed back', (int)$pdo->query('SELECT COUNT(*) FROM interviews WHERE application_id='.$appId.' AND interviewer_id='.$uTc['id'])->fetchColumn() === 1 && $stageOf() === 'CLIENT_REVIEW');
check('F HR sees the interview the reviewer scheduled', str_contains($hr->req('GET', '/index.php?page=hr-applicant&id='.$appId)['body'], 'Final Interview'));

exit($fail ? 1 : 0);
