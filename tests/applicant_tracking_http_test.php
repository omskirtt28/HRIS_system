<?php
declare(strict_types=1);
/**
 * applicant-tracking-link over HTTP (SPEC-applicant-tracking-link.md): real apply, redirect, track by link and by ref/email.
 * Scratch DB with migration 038 only: HRIS_TEST_DB=<scratch> php tests/applicant_tracking_http_test.php . App copied to a temp folder, php -S port 8119, mail off.
 */
$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__); $port = 8119;
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_trk_http_'.bin2hex(random_bytes(4));
$pdo = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function copyTree(string $from, string $to): void { @mkdir($to, 0777, true); foreach (new DirectoryIterator($from) as $f) { if ($f->isDot()) continue; $f->isDir() ? copyTree($f->getPathname(), $to.'/'.$f->getFilename()) : copy($f->getPathname(), $to.'/'.$f->getFilename()); } }
function rmTree(string $dir): void { if (!is_dir($dir)) return; foreach (new DirectoryIterator($dir) as $f) { if ($f->isDot()) continue; $f->isDir() ? rmTree($f->getPathname()) : @unlink($f->getPathname()); } @rmdir($dir); }
foreach (['app', 'config', 'public', 'vendor'] as $d) copyTree($root.'/'.$d, $tmp.'/'.$d);
copy($root.'/index.php', $tmp.'/index.php'); copy($root.'/router.php', $tmp.'/router.php');
file_put_contents($tmp.'/config/config.local.php', "<?php\nreturn ['app'=>['debug'=>true],'mail'=>[],'security'=>['credential_key'=>".var_export(base64_encode(random_bytes(32)), true)."],'db'=>['host'=>'127.0.0.1','port'=>'3306','database'=>".var_export($dbName, true).",'username'=>'root','password'=>'','charset'=>'utf8mb4']];\n");
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $tmp, $tmp.'/router.php'], [['pipe', 'r'], ['file', $tmp.'/server.log', 'w'], ['file', $tmp.'/server.log', 'a']], $pipes, $tmp);
register_shutdown_function(function () use ($proc, $tmp) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } usleep(300000); rmTree($tmp); });
for ($i = 0; $i < 50; $i++) { $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($s) { fclose($s); break; } usleep(100000); }
$fail = 0;
function check(string $label, bool $ok): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ').$label."\n"; if (!$ok) $fail++; }
$jar = tempnam(sys_get_temp_dir(), 'jar');
function req(string $method, string $path, array $post = []): array {
    global $port, $jar; $ch = curl_init('http://127.0.0.1:'.$port.$path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $raw = (string)curl_exec($ch); $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch); $head = substr($raw, 0, $size);
    return ['head' => $head, 'body' => substr($raw, $size), 'loc' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : ''];
}

// fixtures: one published job (scratch DB only)
$sfx = strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');
$pdo->prepare('INSERT INTO clients(code,name) VALUES(?,?)')->execute(['ZH'.$sfx, 'Zzh '.$sfx.' Employer']); $client = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status) VALUES(?,?,?,1,0,"REGULAR","NORMAL","APPROVED")')->execute(['MR-ZH-'.$sfx, $client, 'Synthetic Role']); $mr = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type) VALUES(?,?,?,?,?,?,1,"PUBLISHED","Synthetic branch","REGULAR")')->execute(['ZH'.$sfx, 'zh-'.$sfx, 'Tracker Role '.$sfx, 'Test', $client, $mr]); $job = (int)$pdo->lastInsertId();
$email = 'zz_trkh_'.$sfx.'@example.test';

// 1. apply -> redirect carries a private token, never the email or reference
$form = req('GET', '/index.php?page=apply&job_id='.$job)['body'];
$csrf = preg_match('/name="csrf" value="([^"]+)"/', $form, $m) ? html_entity_decode($m[1]) : '';
$r = req('POST', '/index.php', ['csrf' => $csrf, 'action' => 'apply', 'first_name' => 'Zz', 'last_name' => 'Http', 'email' => $email, 'mobile_no' => '09000000000', 'job_opening_id' => $job, 'why_fit' => 'x']);
check('apply redirects to track with t= only (no email, no ref in the URL)', (bool)preg_match('/page=track&t=[a-f0-9]{64}$/', $r['loc']) && !str_contains($r['loc'], 'email') && !str_contains($r['loc'], 'ref='));
$token = preg_match('/t=([a-f0-9]{64})/', $r['loc'], $m) ? $m[1] : '';
$app = $pdo->query('SELECT a.id,a.application_no FROM applications a JOIN applicants ap ON ap.id=a.applicant_id WHERE ap.email='.$pdo->quote($email))->fetch();

// 2. the link opens the status with the save panel; no-store + no-referrer
$page = req('GET', '/index.php?page=track&t='.$token);
check('track by link shows the application, Screening and the reference', str_contains($page['body'], 'Tracker Role '.$sfx) && str_contains($page['body'], $app['application_no']) && str_contains($page['body'], '>Screening<'));
check('save panel: labelled link field, Copy link, private warning, 90-day date', str_contains($page['body'], 'for="trkLink"') && str_contains($page['body'], 'id="trkCopy"') && str_contains($page['body'], 'Keep this link private') && str_contains($page['body'], date('M j, Y', strtotime('+90 days'))) && str_contains($page['body'], 't='.$token));
check('credential page is no-store and no-referrer', stripos($page['head'], 'Cache-Control: no-store') !== false && stripos($page['head'], 'Referrer-Policy: no-referrer') !== false);
check('employer never shown', !str_contains($page['body'], 'Employer'));

// 3. internal stages and comments never reach either entry path
$stage = fn(string $c) => (int)$pdo->query('SELECT id FROM recruitment_stages WHERE code='.$pdo->quote($c))->fetchColumn();
foreach (['INTERVIEW', 'ENDORSED', 'CLIENT_REVIEW'] as $c) $pdo->prepare('INSERT INTO application_stage_history(application_id,to_stage_id,comment,changed_at) VALUES(?,?,?,NOW())')->execute([$app['id'], $stage($c), 'Secret HR remark '.$sfx]);
$pdo->prepare('UPDATE applications SET current_stage_id=? WHERE id=?')->execute([$stage('CLIENT_REVIEW'), $app['id']]);
$byLink = req('GET', '/index.php?page=track&t='.$token)['body'];
$byRef = req('GET', '/index.php?page=track&ref='.urlencode($app['application_no']).'&email='.urlencode($email))['body'];
foreach (['link' => $byLink, 'ref/email' => $byRef] as $how => $b) {
    check("$how: Hiring Review reads Interview, once, current step", substr_count($b, '<div style="font-weight:600;font-size:13px">Interview</div>') === 1 && preg_match('/class="step cur" aria-current="step"><span class="n" aria-hidden="true">2<\/span> Interview/', $b) === 1);
    check("$how: no internal stage name or HR comment", !str_contains($b, 'Hiring Review') && !str_contains($b, 'Endorsed') && !str_contains($b, 'Secret HR remark'));
}
check('ref/email path shows no private link panel', !str_contains($byRef, 'id="trkLink"'));

// 4. outcome + bad links
$pdo->prepare('UPDATE applications SET current_stage_id=? WHERE id=?')->execute([$stage('REJECTED'), $app['id']]);
$b = req('GET', '/index.php?page=track&t='.$token)['body'];
check('Rejected shows Not selected, no current step', str_contains($b, 'Status: Not selected.') && !str_contains($b, 'aria-current="step"'));
$bad = req('GET', '/index.php?page=track&t='.str_repeat('0', 64))['body'];
check('unknown link: generic message, no application data', str_contains($bad, 'This tracking link cannot be opened') && !str_contains($bad, $app['application_no']));
$pdo->prepare('UPDATE applicant_tracking_links SET expires_at=NOW()-INTERVAL 1 SECOND WHERE application_id=?')->execute([$app['id']]);
$exp = req('GET', '/index.php?page=track&t='.$token)['body'];
check('expired link: same generic message, manual lookup form still there', str_contains($exp, 'This tracking link cannot be opened') && !str_contains($exp, $app['application_no']) && str_contains($exp, 'name="ref"'));
echo $fail ? "FAILED ($fail)\n" : "OK\n"; exit($fail ? 1 : 0);
