<?php
declare(strict_types=1);

/**
 * rec-jobs over HTTP (SPEC-rec-jobs.md): real login, real routes, real HTML. Needs a SCRATCH database copy:
 *   mysql -uroot -e "DROP DATABASE IF EXISTS pmbsi_hris_test; CREATE DATABASE pmbsi_hris_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 *   mysqldump -uroot --single-transaction --routines --triggers pmbsi_hris | mysql -uroot pmbsi_hris_test
 *   HRIS_TEST_DB=pmbsi_hris_test C:\xampp\php\php.exe tests\rec_jobs_http_test.php
 * The app is copied to a temp folder, pointed at the scratch DB with a config.local.php there (the repo is not touched),
 * served by php -S, and exercised with curl. Fixtures and passwords are written to the scratch DB only. Drop it afterwards.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_rj_http_'.bin2hex(random_bytes(4));
$port = 8096;
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
$tag = 'Zzrj'.strtr(bin2hex(random_bytes(2)), '0123456789', 'ghijklmnop');
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
$title = $tag.' Sales Clerk';
$jobRow = static function (string $like) use ($pdo): array { $q = $pdo->prepare('SELECT * FROM job_openings WHERE title LIKE ? ORDER BY id DESC LIMIT 1'); $q->execute([$like]); return $q->fetch() ?: []; };
$jobsNamed = static fn(string $like): int => (int)$pdo->query('SELECT COUNT(*) FROM job_openings WHERE title LIKE '.$pdo->quote($like))->fetchColumn();

$hr = new Browser($port);
check('HR signs in', $hr->login($hrEmail, 'Test#1234'));
$anon = new Browser($port);

// ===== A. Manpower Requests is ticket-only (D5) and has Post job =====
$page = $hr->req('GET', '/index.php?page=hr-manpower');
$tok = $csrfOf($page['body']);
$hr->req('POST', '/index.php?page=hr-manpower', ['csrf' => $tok, 'action' => 'approve_manpower', 'ticket_id' => (string)$t1, 'version' => (string)(int)$pdo->query('SELECT version FROM tickets WHERE id='.$t1)->fetchColumn(), 'client_id' => '', 'new_employer' => $employer, 'position_title' => 'Sales Clerk', 'priority' => 'NORMAL', 'remarks' => '']);
$mrId = (int)$pdo->query('SELECT id FROM manpower_requests WHERE ticket_id='.$t1)->fetchColumn();
$page = $hr->req('GET', '/index.php?page=hr-manpower');
check('A the HR create-request form is gone (D5)', !str_contains($page['body'], 'Create new manpower request') && !str_contains($page['body'], 'name="action" value="create_manpower"'));
check('A tickets are still listed with Approve and Decline', str_contains($page['body'], 'Ticket #'.$t2) && str_contains($page['body'], 'data-mi-open="mi-approve"'));
check('A an approved request shows Post job that links to the job form', str_contains($page['body'], 'Post job') && str_contains($page['body'], 'request_id='.$mrId));
$before = (int)$pdo->query('SELECT COUNT(*) FROM manpower_requests')->fetchColumn();
$hr->req('POST', '/index.php?page=hr-manpower', ['csrf' => $tok, 'action' => 'create_manpower', 'client_id' => '', 'new_employer' => $tag.' Walk-in', 'position_title' => 'Walk-in role', 'requested_headcount' => '2', 'employment_type' => 'FULL_TIME', 'priority' => 'NORMAL']);
check('A the old create_manpower action no longer creates anything', (int)$pdo->query('SELECT COUNT(*) FROM manpower_requests')->fetchColumn() === $before && (int)$pdo->query('SELECT COUNT(*) FROM clients WHERE name='.$pdo->quote($tag.' Walk-in'))->fetchColumn() === 0);
$pipe = $hr->req('GET', '/index.php?page=hr-pipeline')['body'];
check('A Pipeline offers + New job instead of + New manpower request', str_contains($pipe, '+ New job') && !str_contains($pipe, '+ New manpower request'));

// ===== B. Job Openings page and who may use it =====
$jobs = $hr->req('GET', '/index.php?page=hr-jobs');
check('B Job Openings loads for HR with the filter tabs and + New job', $jobs['code'] === 200 && str_contains($jobs['body'], 'Job Openings') && str_contains($jobs['body'], '+ New job') && str_contains($jobs['body'], 'Published'));
$form = $hr->req('GET', '/index.php?page=hr-jobs&request_id='.$mrId)['body'];
check('B the Post job form is prefilled from the request and carries the request id', str_contains($form, 'value="Sales Clerk"') && str_contains($form, 'name="manpower_request_id" value="'.$mrId.'"'));
$emp = new Browser($port);
check('B staff signs in', $emp->login($staffEmail, 'Test#1234'));
$empJobs = $emp->req('GET', '/index.php?page=hr-jobs');
check('B an Employee-role user cannot open Job Openings', $empJobs['code'] !== 200 && !str_contains($empJobs['body'], '+ New job'));
$tokJ = $csrfOf($form);
$formToken = static fn(string $html): string => preg_match('/name="form_token" value="([^"]+)"/', $html, $m) ? $m[1] : '';
check('B the Post job form carries only the qualifications, not the ticket number, section or department', str_contains($form, 'Synthetic qualifications') && !str_contains($form, 'Synthetic section') && !str_contains($form, 'Synthetic department') && !str_contains($form, 'ticket #') && !str_contains($form, 'Ticket #'));
$save = ['csrf' => $tokJ, 'action' => 'save_job', 'manpower_request_id' => (string)$mrId, 'title' => $title, 'description' => 'Sell things.', 'requirements' => "Line one\nLine two", 'location_text' => 'Synthetic branch', 'employment_type' => 'REGULAR', 'openings' => '2', 'department_id' => '', 'salary_min' => '', 'salary_max' => '', 'closes_on' => '', 'form_token' => $formToken($form)];
check('B saving without CSRF is refused', $hr->req('POST', '/index.php?page=hr-jobs', array_diff_key($save, ['csrf' => 1]))['code'] === 419 && $jobsNamed($title) === 0);
$emp->req('POST', '/index.php?page=hr-jobs', array_replace($save, ['csrf' => $csrfOf($emp->req('GET', '/index.php?page=employee-dashboard')['body'])]));
check('B an Employee-role user cannot create a job', $jobsNamed($title) === 0);

// ===== C. create, publish =====
$r = $hr->req('POST', '/index.php?page=hr-jobs', $save);
$job = $jobRow($title);
$hr->req('POST', '/index.php?page=hr-jobs', $save);
check('C a double click on Save creates one job, not two', $jobsNamed($title) === 1);
check('C a new-job POST without the form token is refused', (function () use ($hr, $save, $tag, $jobsNamed) { $hr->req('POST', '/index.php?page=hr-jobs', array_replace($save, ['title' => $tag.' No token', 'form_token' => ''])); return $jobsNamed($tag.' No token') === 0; })());
check('C HR saves a Draft linked to the request and lands on Job Openings', $r['code'] === 302 && str_contains($r['loc'], 'hr-jobs') && $job && $job['status'] === 'DRAFT' && (int)$job['manpower_request_id'] === $mrId);
$slug = (string)($job['slug'] ?? '');
check('C a Draft is not public: not on Careers, job page 404 or redirect', !str_contains($anon->req('GET', '/index.php?page=careers')['body'], $title) && !str_contains($anon->req('GET', '/index.php?page=job&slug='.urlencode($slug))['body'], $title));
$pubPost = fn(string $action) => $hr->req('POST', '/index.php?page=hr-jobs', ['csrf' => $tokJ, 'action' => $action, 'job_id' => (string)$job['id']]);
$pubPost('publish_job');
check('C publish makes it Published', $jobRow($title)['status'] === 'PUBLISHED' && $jobRow($title)['published_at'] !== null);
$pubPost('publish_job');
check('C publishing twice (double click) leaves it Published and refuses the second move', $jobRow($title)['status'] === 'PUBLISHED');

// ===== D. what the public sees =====
$careers = $anon->req('GET', '/index.php?page=careers')['body'];
check('D Careers lists the live job with PMBSI as the employer', str_contains($careers, $title) && str_contains($careers, 'PMBSI'));
$pages = ['home' => $anon->req('GET', '/index.php?page=home')['body'], 'careers' => $careers, 'job' => $anon->req('GET', '/index.php?page=job&slug='.urlencode($slug))['body'], 'apply' => $anon->req('GET', '/index.php?page=apply&job_id='.(int)$job['id'])['body']];
check('D the job page opens and the Apply page offers the job', str_contains($pages['job'], $title) && str_contains($pages['apply'], $title));
check('D the real employer name appears on no public page', !array_filter($pages, fn($h) => str_contains($h, $employer)));
check('D searching for the employer name finds nothing', !str_contains($anon->req('GET', '/index.php?page=careers&q='.urlencode($employer))['body'], $title));
check('D searching for the title still finds the job', str_contains($anon->req('GET', '/index.php?page=careers&q='.urlencode($tag))['body'], $title));
$pdo->prepare('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")')->execute(['APP-ZZ-'.$tag, 'Zz', 'Applicant', 'zz_'.$tag.'@example.test', '09170000000']);
$applicant = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code="APPLIED"),NOW(),NOW(),"ACTIVE")')->execute(['PMBSI-ZZ-'.$tag, $applicant, $job['id'], $mrId, $job['client_id']]);
$track = $anon->req('GET', '/index.php?page=track&ref='.urlencode('PMBSI-ZZ-'.$tag).'&email='.urlencode('zz_'.$tag.'@example.test'))['body'];
check('D the applicant tracker shows the position as PMBSI, never the employer', str_contains($track, $title) && !str_contains($track, $employer));
$hrList = $hr->req('GET', '/index.php?page=hr-jobs')['body'];
check('D HR still sees the real employer and the application count', str_contains($hrList, $employer) && str_contains($hrList, 'JOB-'));

// ===== D2. salary: a range only when both ends are set =====
$hr->req('POST', '/index.php?page=hr-jobs', array_replace($save, ['job_id' => (string)$job['id'], 'salary_min' => '12345', 'salary_max' => '']));
check('D a job with only one salary end shows no figure', !str_contains($anon->req('GET', '/index.php?page=job&slug='.urlencode($slug))['body'], '12,345') && str_contains($anon->req('GET', '/index.php?page=job&slug='.urlencode($slug))['body'], 'Salary negotiable'));
$hr->req('POST', '/index.php?page=hr-jobs', array_replace($save, ['job_id' => (string)$job['id'], 'salary_min' => '12345', 'salary_max' => '23456']));
check('D a job with both ends shows the range', str_contains($anon->req('GET', '/index.php?page=careers')['body'], '12,345') && str_contains($anon->req('GET', '/index.php?page=job&slug='.urlencode($slug))['body'], '23,456'));
$hr->req('POST', '/index.php?page=hr-jobs', array_replace($save, ['job_id' => (string)$job['id'], 'salary_min' => '', 'salary_max' => '']));

// ===== E. edit, unpublish, close, reopen =====
$hr->req('POST', '/index.php?page=hr-jobs', array_replace($save, ['job_id' => (string)$job['id'], 'openings' => '4', 'manpower_request_id' => '999999999']));
$j = $jobRow($title);
check('E editing changes the fields but never the request, code or slug', (int)$j['openings'] === 4 && (int)$j['manpower_request_id'] === $mrId && $j['slug'] === $slug && $j['job_code'] === $job['job_code']);
$pubPost('unpublish_job');
check('E unpublish hides it from Careers and the job page', $jobRow($title)['status'] === 'DRAFT' && !str_contains($anon->req('GET', '/index.php?page=careers')['body'], $title) && !str_contains($anon->req('GET', '/index.php?page=job&slug='.urlencode($slug))['body'], $title));
$pubPost('publish_job');
$pdo->prepare('UPDATE job_openings SET closes_at=? WHERE id=?')->execute([date('Y-m-d 23:59:59', strtotime('-1 day')), $job['id']]);
check('E a job past its closing date disappears from Careers on the next load and HR sees Expired', !str_contains($anon->req('GET', '/index.php?page=careers')['body'], $title) && str_contains($hr->req('GET', '/index.php?page=hr-jobs')['body'], 'Expired'));
$pdo->prepare('UPDATE job_openings SET closes_at=NULL WHERE id=?')->execute([$job['id']]);
$pubPost('close_job');
check('E close hides it and the Apply page no longer offers it', $jobRow($title)['status'] === 'CLOSED' && !str_contains($anon->req('GET', '/index.php?page=apply')['body'], $title) && !str_contains($anon->req('GET', '/index.php?page=job&slug='.urlencode($slug))['body'], $title));
$hr->req('POST', '/index.php?page=hr-jobs', array_replace($save, ['job_id' => (string)$job['id'], 'title' => $title.' X']));
check('E a closed job cannot be edited', $jobRow($title)['title'] === $title);
$pubPost('reopen_job');
check('E reopen returns it to Draft, still not public', $jobRow($title)['status'] === 'DRAFT' && !str_contains($anon->req('GET', '/index.php?page=careers')['body'], $title));
check('E the application and the job still exist after all of that', (int)$pdo->query('SELECT COUNT(*) FROM applications WHERE application_no='.$pdo->quote('PMBSI-ZZ-'.$tag))->fetchColumn() === 1);

// ===== F. walk-in job (no ticket) =====
$walk = ['csrf' => $tokJ, 'action' => 'save_job', 'manpower_request_id' => '', 'client_id' => '', 'new_employer' => $tag.' Walk-in', 'title' => $tag.' Walk-in role', 'description' => 'Walk in.', 'employment_type' => 'CONTRACT', 'openings' => '1'];
$walk['form_token'] = $formToken($hr->req('GET', '/index.php?page=hr-jobs&new=1')['body']);
$hr->req('POST', '/index.php?page=hr-jobs', $walk);
$w = $jobRow($tag.' Walk-in role');
$bridge = $w ? $pdo->query('SELECT * FROM manpower_requests WHERE id='.(int)$w['manpower_request_id'])->fetch() : [];
check('F a walk-in job adds its employer and one silent APPROVED request with no ticket', $w && $bridge && $bridge['status'] === 'APPROVED' && $bridge['ticket_id'] === null && (int)$pdo->query('SELECT COUNT(*) FROM clients WHERE name='.$pdo->quote($tag.' Walk-in'))->fetchColumn() === 1);
$open = $hr->req('GET', '/index.php?page=hr-manpower')['body'];
$all = $hr->req('GET', '/index.php?page=hr-manpower&filter=all')['body'];
check('F the walk-in request is hidden from Open and labelled HR posting under All', !str_contains($open, $tag.' Walk-in role') && str_contains($all, $tag.' Walk-in role') && str_contains($all, 'HR posting'));
$hr->req('POST', '/index.php?page=hr-jobs', array_replace($walk, ['title' => '', 'new_employer' => $tag.' Other', 'form_token' => $formToken($hr->req('GET', '/index.php?page=hr-jobs&new=1')['body'])]));
check('F a refused walk-in save leaves no employer or request behind', (int)$pdo->query('SELECT COUNT(*) FROM clients WHERE name='.$pdo->quote($tag.' Other'))->fetchColumn() === 0);

// ===== G. rec-apply-job: Apply page takes the job from the link; HR candidate page shows job details and the resume =====
$pdo->prepare('UPDATE job_openings SET status="PUBLISHED",closes_at=NULL WHERE id=?')->execute([(int)$job['id']]); // earlier sections closed it
$apply = $anon->req('GET', '/index.php?page=apply&job_id='.(int)$job['id'])['body'];
check('G Apply with a job carries it as a hidden field and has no position dropdown', str_contains($apply, 'name="job_opening_id" value="'.(int)$job['id'].'"') && !str_contains($apply, '<select required name="job_opening_id"') && str_contains($apply, 'Position applying for'));
$applyNone = $anon->req('GET', '/index.php?page=apply')['body'];
check('G Apply with no job shows the choose-a-job prompt and no form', str_contains($applyNone, 'Choose the job you are applying for') && !str_contains($applyNone, 'name="first_name"'));
check('G Apply with a draft (not public) job shows no form', !str_contains($anon->req('GET', '/index.php?page=apply&job_id='.(int)$w['id'])['body'], 'name="first_name"'));
$appId = (int)$pdo->query('SELECT id FROM applications WHERE application_no='.$pdo->quote('PMBSI-ZZ-'.$tag))->fetchColumn();
@mkdir($tmp.'/storage/resumes', 0777, true);
file_put_contents($tmp.'/storage/resumes/zz'.$tag.'.pdf', "%PDF-1.4\n%zz resume\n");
$pdo->prepare('INSERT INTO application_documents(application_id,document_type,original_name,stored_name,mime_type,file_size,created_at) VALUES(?,"RESUME","zz-resume.pdf",?,"application/pdf",20,NOW())')->execute([$appId, 'zz'.$tag.'.pdf']);
$prof = $hr->req('GET', '/index.php?page=hr-applicant&id='.$appId)['body'];
check('G the HR candidate page shows the applied job and its details', str_contains($prof, 'Applied for:') && str_contains($prof, 'Job details') && str_contains($prof, 'Sell things.') && str_contains($prof, 'Line two') && str_contains($prof, 'Regular') && str_contains($prof, 'Synthetic branch'));
check('G the HR candidate page has the stage tracker, tabs and a resume preview', str_contains($prof, 'stage-track') && str_contains($prof, 'role="tablist"') && str_contains($prof, '<iframe') && str_contains($prof, 'hr-application-resume'));
$res = $hr->req('GET', '/index.php?page=hr-application-resume&id='.$appId);
check('G HR can open the resume inline as a PDF', $res['code'] === 200 && str_contains($res['body'], '%PDF') && stripos($res['head'], 'inline') !== false && stripos($res['head'], 'nosniff') !== false);
check('G HR can download the resume', stripos($hr->req('GET', '/index.php?page=hr-application-resume&id='.$appId.'&download=1')['head'], 'attachment') !== false);
check('G the resume is not served to the public or to an Employee-role user', $anon->req('GET', '/index.php?page=hr-application-resume&id='.$appId)['code'] !== 200 && $emp->req('GET', '/index.php?page=hr-application-resume&id='.$appId)['code'] !== 200);
check('G an unknown application gives 404, not a path', $hr->req('GET', '/index.php?page=hr-application-resume&id=999999999')['code'] === 404);
$list = $hr->req('GET', '/index.php?page=hr-applicants')['body'];
check('G the Applicants list shows the job type and location', str_contains($list, '<th>Type</th>') && str_contains($list, '<th>Location</th>') && str_contains($list, 'Regular') && str_contains($list, 'Synthetic branch'));

check('G Stage & actions: Update continues to the next step by default, exceptions are optional, no manual jump to later stages', str_contains($prof, '<option value="NEXT" selected>Continue to Screening') && str_contains($prof, '<option value="REJECTED">') && !str_contains($prof, '<option value="DEPLOYMENT">'));
$hr->req('POST', '/index.php?page=hr-applicant', ['csrf' => $tokJ, 'action' => 'move_stage', 'application_id' => (string)$appId, 'target_stage' => 'NEXT', 'comment' => 'zz pass', 'return_page' => 'hr-applicant', 'return_id' => (string)$appId]);
check('G Update with the default moves Applied to Screening and keeps the note', (string)$pdo->query('SELECT s.code FROM applications a JOIN recruitment_stages s ON s.id=a.current_stage_id WHERE a.id='.$appId)->fetchColumn() === 'SCREENING' && (int)$pdo->query('SELECT COUNT(*) FROM application_stage_history WHERE application_id='.$appId.' AND comment="zz pass"')->fetchColumn() === 1);
$hr->req('POST', '/index.php?page=hr-applicant', ['csrf' => $tokJ, 'action' => 'move_stage', 'application_id' => (string)$appId, 'target_stage' => 'REJECTED', 'comment' => 'zz', 'return_page' => 'hr-applicant', 'return_id' => (string)$appId]);
$prof2 = $hr->req('GET', '/index.php?page=hr-applicant&id='.$appId)['body'];
check('G after a reject the page offers Resume at with the normal stages', str_contains($prof2, 'Resume at') && str_contains($prof2, '<option value="SCREENING">') && !str_contains($prof2, 'Continue to'));

exit($fail ? 1 : 0);
