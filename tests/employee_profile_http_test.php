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
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_profile_http_'.bin2hex(random_bytes(4));
$port = 8099;
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
file_put_contents($tmp.'/config/config.local.php', "<?php\nreturn ['app'=>['debug'=>true],'mail'=>[],'db'=>['host'=>'127.0.0.1','port'=>'3306','database'=>".var_export($dbName, true).",'username'=>'root','password'=>'','charset'=>'utf8mb4'],'incident_documents'=>['storage_root'=>".var_export($tmp.'/private', true)."]];\n");
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
if (function_exists('imagecreatetruecolor')) { // a scan-sized image so the thumbnail path has something to shrink
    $im = imagecreatetruecolor(1600, 1200); imagefill($im, 0, 0, imagecolorallocate($im, 200, 60, 60)); ob_start(); imagepng($im); $png = (string)ob_get_clean();
}
file_put_contents($tmp.'/storage/employee_documents/x_own.png', $png);
file_put_contents($tmp.'/storage/employee_documents/x_other.png', $png); file_put_contents($tmp.'/storage/employee_documents/x_memo.png', $png);

// ---- fixtures (scratch DB only) ----
$hash = password_hash('Test#1234', PASSWORD_DEFAULT);
$empUser = 10; $hrUser = 9; $clientUser = 5; $unlinkedUser = 8; $otherUser = 11;
$pdo->prepare('UPDATE users SET password_hash=?, status="ACTIVE" WHERE id IN (?,?,?,?)')->execute([$hash, $empUser, $hrUser, $clientUser, $unlinkedUser]);
$email = fn(int $id): string => (string)$pdo->query('SELECT email FROM users WHERE id='.$id)->fetchColumn();
$own = (int)$pdo->query('SELECT id FROM employees WHERE user_id='.$empUser)->fetchColumn();
$other = (int)$pdo->query('SELECT id FROM employees WHERE user_id='.$otherUser)->fetchColumn();
$pdo->prepare('DELETE FROM employee_government_ids WHERE employee_id IN (?,?)')->execute([$own, $other]);
$pdo->exec('ALTER TABLE employee_government_ids AUTO_INCREMENT=100'); // keeps fixture row ids away from the fuzz value [1] (an array casts to 1)
$pdo->prepare('DELETE FROM employee_documents WHERE employee_id IN (?,?)')->execute([$own, $other]);
$gid = $pdo->prepare('INSERT INTO employee_government_ids(employee_id,id_type,id_number,issued_date,expiry_date,notes) VALUES(?,?,?,?,?,?)');
$gid->execute([$own, 'SSS', "'3522076357", '2020-01-01', null, 'internal HR note']); $ownId = (int)$pdo->lastInsertId();
$gid->execute([$other, 'TIN', '9999888877', null, null, '']); $otherId = (int)$pdo->lastInsertId();
$doc = $pdo->prepare('INSERT INTO employee_documents(employee_id,document_type,title,original_name,stored_name,mime_type,file_size) VALUES(?,?,?,?,?,?,?)');
$doc->execute([$own, 'GOVERNMENT_ID', 'My SSS scan', 'sss.png', 'x_own.png', 'image/png', strlen($png)]); $ownDoc = (int)$pdo->lastInsertId();
$doc->execute([$own, 'MEMO', 'My memo', 'memo.png', 'x_memo.png', 'image/png', strlen($png)]); $memoDoc = (int)$pdo->lastInsertId();
$doc->execute([$own, 'OTHER', 'Html doc', "q\"u\\o;t\r\ne.html", 'x_html.png', 'text/html', 20]); $htmlDoc = (int)$pdo->lastInsertId(); // hostile name and type: must be served as a download with a safe header
file_put_contents($tmp.'/storage/employee_documents/x_html.png', '<script>alert(1)</script>');
$doc->execute([$other, 'CONTRACT', 'Someone else contract', 'c.png', 'x_other.png', 'image/png', strlen($png)]); $otherDoc = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE employees SET profile_photo_stored_name=NULL WHERE id=?')->execute([$own]);

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
$main = static fn(string $html): string => (preg_match('/<main class="content">(.*)<\/main>/s', $html, $m) ? $m[1] : $html);

// ===== A. Employee =====
$a = new Browser($port);
check('A login as employee', $a->login($email($empUser), 'Test#1234'));
$r = $a->req('GET', '/index.php?page=employee-profile'); $html = $r['body']; $body = $main($html);
check('A profile page 200', $r['code'] === 200, 'code '.$r['code']);
check('A page title and breadcrumb', str_contains($html, '<title>My Profile') && str_contains($body, 'Employee Self-Service / My Profile'));
check('A sidebar: My Profile enabled and active', (bool)preg_match('/<a href="[^"]*page=employee-profile" class="nav-item active/', $html), 'nav link');
check('A top-right menu has no duplicate Profile link (sidebar has My Profile)', !preg_match('/<div class="profile-pop"[^>]*><a href="[^"]*page=employee-profile"/', $html) && str_contains($html, 'Appearance'));
check('A hero shows employee name and status', str_contains($body, 'myp-hero') && str_contains($body, 'myp-title'));
check('A four tabs with matching panels', substr_count($body, 'role="tab"') === 4 && substr_count($body, 'role="tabpanel"') === 4 && preg_match_all('/aria-controls="([^"]+)"/', $body, $mm) && count(array_filter($mm[1], fn($id) => str_contains($body, 'id="'.$id.'"'))) === 4);
check('A tab names', str_contains($body, '>Personal<') && str_contains($body, '>Contact and Address<') && str_contains($body, '>Employment<') && str_contains($body, '>IDs and Documents<'));
check('A shortcuts: print, DTR link, five real profile links, no Soon, no dead links', str_contains($body, 'data-print') && str_contains($body, 'page=employee-request-new') && !str_contains($body, 'myp-soon') && str_contains($body, 'page=profile-faqs') && str_contains($body, 'page=profile-payslip') && !str_contains($body, 'href="#"'));
check('A ID masked, full number and HR note absent from HTML', str_contains($body, '••••6357') && !str_contains($body, '3522076357') && !str_contains($body, 'internal HR note'));
check('A other employee data absent', !str_contains($body, '9999888877') && !str_contains($body, 'Someone else contract'));
check('A own documents shown, memo included', str_contains($body, 'My SSS scan') && str_contains($body, 'My memo') && str_contains($body, 'data-filter="MEMO"'));
$thumbs = preg_replace('#<dialog.*?</dialog>#s', '', $body);
check('A thumbnails are lazy with alt text', substr_count($thumbs, '<img') === 2 && substr_count($thumbs, '<img') === substr_count($thumbs, 'loading="lazy"') && !preg_match('/<img(?![^>]*alt=)/', $thumbs));
check('A read-only note, no form or editable field in profile', str_contains($body, 'contact HR') && !preg_match('/<form|<textarea|<input/', $body));
check('A stylesheet and script linked with version', str_contains($html, 'employee-profile.css?v=20261031-my-profile-v7') && str_contains($html, 'employee-profile.js?v=20261031-my-profile-v7'));
$css = $a->req('GET', '/public/assets/employee-profile.css?v=x'); $js = $a->req('GET', '/public/assets/employee-profile.js?v=x');
check('A assets served', $css['code'] === 200 && $js['code'] === 200 && strlen($css['body']) > 3000 && strlen($js['body']) > 2000);
$csrf = $a->csrf($body);
check('A reveal token present', $csrf !== '');
check('A profile assets are not loaded on other pages', !str_contains($a->req('GET', '/index.php?page=employee-leave')['body'], 'employee-profile.'));
$ok = $a->req('POST', '/index.php?page=employee-profile-reveal', ['csrf' => $csrf, 'id' => $ownId]); $j = json_decode($ok['body'], true);
check('A reveal own ID returns number, no-store', $ok['code'] === 200 && ($j['number'] ?? '') === "'3522076357" && stripos($ok['head'], 'no-store') !== false, $ok['body']);
$bad = $a->req('POST', '/index.php?page=employee-profile-reveal', ['csrf' => $csrf, 'id' => $otherId]);
check('A reveal other employee ID refused (404, no number)', $bad['code'] === 404 && !str_contains($bad['body'], '9999888877'), $bad['code'].' '.$bad['body']);
$noTok = $a->req('POST', '/index.php?page=employee-profile-reveal', ['csrf' => 'wrong', 'id' => $ownId]);
check('A reveal without valid CSRF refused', $noTok['code'] === 419 && !str_contains($noTok['body'], '3522076357'));
$get = $a->req('GET', '/index.php?page=employee-profile-reveal&id='.$ownId);
check('A reveal by GET refused', $get['code'] === 405 && !str_contains($get['body'], '3522076357'));
$d = $a->req('GET', '/index.php?page=employee-profile-document&id='.$ownDoc);
check('A own document streams inline as image, nosniff', $d['code'] === 200 && stripos($d['head'], 'image/png') !== false && stripos($d['head'], 'inline') !== false && stripos($d['head'], 'nosniff') !== false && $d['body'] === $png);
$dl = $a->req('GET', '/index.php?page=employee-profile-document&id='.$ownDoc.'&download=1');
check('A download is an attachment', $dl['code'] === 200 && stripos($dl['head'], 'attachment') !== false);
$th = $a->req('GET', '/index.php?page=employee-profile-document&id='.$ownDoc.'&thumb=1');
$gd = extension_loaded('gd');
check($gd ? 'A grid thumbnail is a smaller JPEG' : 'A without GD the thumbnail request serves the original', $th['code'] === 200 && ($gd ? (stripos($th['head'], 'image/jpeg') !== false && (getimagesizefromstring($th['body'])[0] ?? 0) === 480) : $th['body'] === $png), $th['code'].' '.strlen($th['body']));
$etag = preg_match('/^ETag:\s*(\S+)/mi', $th['head'], $em) ? $em[1] : '';
$nm = $a->req('GET', '/index.php?page=employee-profile-document&id='.$ownDoc.'&thumb=1', [], ['If-None-Match: '.$etag]);
check('A revisit with the ETag gets 304 and no body', $etag !== '' && $nm['code'] === 304 && $nm['body'] === '', $etag.' '.$nm['code']);
check('A scans are never browser-cached without asking (private, no-cache) and carry validators', (bool)preg_match('/^Cache-Control:\s*private, no-cache\s*$/mi', $th['head']) && stripos($th['head'], 'Last-Modified') !== false, $th['head']);
check('A another employee document thumbnail refused (404)', $a->req('GET', '/index.php?page=employee-profile-document&id='.$otherDoc.'&thumb=1')['code'] === 404);
$od = $a->req('GET', '/index.php?page=employee-profile-document&id='.$otherDoc);
check('A another employee document refused (404)', $od['code'] === 404);
check('A photo route 404 when no photo', $a->req('GET', '/index.php?page=employee-profile-photo')['code'] === 404);

// hostile content type and file name are served as a download with a safe header
$hd = $a->req('GET', '/index.php?page=employee-profile-document&id='.$htmlDoc);
check('A text/html document is an attachment, nosniff, no header injection from CR/LF/quote in its name', $hd['code'] === 200 && stripos($hd['head'], 'Content-Disposition: attachment') !== false && stripos($hd['head'], 'nosniff') !== false && str_contains($hd['head'], "filename*=UTF-8''") && !preg_match('/^e\.html/m', $hd['head']) && substr_count($hd['head'], "\n") < 25, $hd['head']);
// a photo row claiming text/html is never served as html
@mkdir($tmp.'/storage/employee_photos', 0777, true); file_put_contents($tmp.'/storage/employee_photos/p_evil.html', '<script>1</script>');
$pdo->prepare('UPDATE employees SET profile_photo_stored_name="p_evil.html",profile_photo_mime_type="text/html",profile_photo_original_name="p.html" WHERE id=?')->execute([$own]);
$ph = $a->req('GET', '/index.php?page=employee-profile-photo');
check('A photo with a non-image type is served as octet-stream, nosniff', $ph['code'] === 200 && stripos($ph['head'], 'Content-Type: application/octet-stream') !== false && stripos($ph['head'], 'nosniff') !== false && stripos($ph['head'], 'text/html') === false, $ph['head']);
$pdo->prepare('UPDATE employees SET profile_photo_stored_name=NULL,profile_photo_mime_type=NULL,profile_photo_original_name=NULL WHERE id=?')->execute([$own]);
// a row whose file is gone from disk is a clean 404, never a 500
unlink($tmp.'/storage/employee_documents/x_memo.png');
check('A missing file on disk gives 404 for the document and its thumbnail, page still renders', $a->req('GET', '/index.php?page=employee-profile-document&id='.$memoDoc)['code'] === 404 && $a->req('GET', '/index.php?page=employee-profile-document&id='.$memoDoc.'&thumb=1')['code'] === 404 && $a->req('GET', '/index.php?page=employee-profile')['code'] === 200);
// reveal input fuzz: never a 500, never a number, never an audit row
$auditCount = fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='REVEAL_OWN_GOV_ID' AND user_id=".$empUser." AND created_at>='".$runStart."'")->fetchColumn();
$beforeFuzz = $auditCount(); $fuzzBad = [];
foreach ([['csrf' => $csrf, 'id' => 'abc'], ['csrf' => $csrf, 'id' => '-1'], ['csrf' => $csrf, 'id' => '99999999999999999999'], ['csrf' => $csrf, 'id' => [1]], ['csrf' => ['x'], 'id' => $ownId], ['csrf' => '', 'id' => $ownId], ['id' => $ownId], []] as $i => $post) {
    $fz = $a->req('POST', '/index.php?page=employee-profile-reveal', $post);
    if (!in_array($fz['code'], [404, 419], true) || str_contains($fz['body'], '3522076357')) $fuzzBad[] = '#'.$i.' -> '.$fz['code'];
}
check('A reveal with junk ids/tokens (text, negative, huge, arrays, empty, missing) is refused cleanly and leaves no audit row', !$fuzzBad && $auditCount() === $beforeFuzz, implode(', ', $fuzzBad));
$sw = $a->req('GET', '/index.php?page=employee-profile&id='.$other.'&employee_id='.$other);
check('A id parameters are ignored (still own profile)', $sw['code'] === 200 && !str_contains($sw['body'], 'Someone else contract') && !str_contains($sw['body'], '9999888877'));

// ===== B. HR user =====
$b = new Browser($port);
check('B login as HR', $b->login($email($hrUser), 'Test#1234'));
$r = $b->req('GET', '/index.php?page=employee-profile'); $html = $r['body']; $body = $main($html);
check('B HR profile page 200 (not portal-denied)', $r['code'] === 200, 'code '.$r['code']);
check('B HR chrome and note', str_contains($body, 'Human Resources / My Profile') && str_contains($body, 'maintained in Employee 201') && str_contains($html, 'page=hr-employees'));
check('B HR Profile menu link works', (bool)preg_match('/<div class="profile-pop"[^>]*><a href="[^"]*page=employee-profile"/', $html));
check('B HR still sees tabs and masked data only', substr_count($body, 'role="tab"') === 4 && !str_contains($body, '3522076357'));
check('B HR cannot read the employee\'s documents', $b->req('GET', '/index.php?page=employee-profile-document&id='.$ownDoc)['code'] === 404);
$hrToken = preg_match('/name="csrf" value="([^"]+)"/', $html, $tm) ? html_entity_decode($tm[1]) : (preg_match('/csrf[^>]*value="([^"]+)"/', $html, $tm) ? html_entity_decode($tm[1]) : 'none');
$hrReveal = $b->req('POST', '/index.php?page=employee-profile-reveal', ['csrf' => $hrToken, 'id' => $ownId]);
check('B HR cannot reveal the employee\'s ID (404 or 419, never the number)', in_array($hrReveal['code'], [404, 419], true) && !str_contains($hrReveal['body'], '3522076357'), $hrReveal['code'].' token='.($hrToken === 'none' ? 'none' : 'found'));

// ===== C. Client + anonymous =====
$c = new Browser($port);
$anon = $c->req('GET', '/index.php?page=employee-profile');
check('C anonymous is sent to login', $anon['code'] === 302 && str_contains($anon['loc'], 'page=login'), $anon['code'].' '.$anon['loc']);
check('C anonymous cannot reach document/photo/reveal', $c->req('GET', '/index.php?page=employee-profile-document&id='.$ownDoc)['code'] === 302 && $c->req('GET', '/index.php?page=employee-profile-photo')['code'] === 302);
check('C login as client', $c->login($email($clientUser), 'Test#1234'));
check('C client is denied (403)', $c->req('GET', '/index.php?page=employee-profile')['code'] === 403);

// ===== D. Account without an employee record =====
$u = new Browser($port);
check('D login unlinked employee account', $u->login($email($unlinkedUser), 'Test#1234'));
$r = $u->req('GET', '/index.php?page=employee-profile');
check('D empty state, no error', $r['code'] === 200 && str_contains($r['body'], 'not linked to an employee record') && !str_contains($r['body'], 'role="tablist"'));

// ===== D2. Admin without an employee record, and a signed-in user who is deactivated mid-session =====
$adminId = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code IN ('SUPER_ADMIN','HRIS_ADMIN') AND u.id NOT IN (SELECT user_id FROM employees WHERE user_id IS NOT NULL) ORDER BY u.id LIMIT 1")->fetchColumn();
check('D2 scratch copy has an admin without an employee row', $adminId > 0);
if ($adminId) {
    $pdo->prepare('UPDATE users SET password_hash=?, status="ACTIVE" WHERE id=?')->execute([$hash, $adminId]);
    $ad = new Browser($port);
    check('D2 login as admin', $ad->login($email($adminId), 'Test#1234'));
    $r = $ad->req('GET', '/index.php?page=employee-profile');
    $adToken = preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $tm) ? html_entity_decode($tm[1]) : '';
    check('D2 admin without an employee row: admin chrome + empty state (no error)', $r['code'] === 200 && str_contains($r['body'], 'Administration / My Profile') && str_contains($r['body'], 'not linked to an employee record'));
    foreach (['admin-users', 'hr-employees', 'admin-dashboard'] as $tokenPage) { // the profile page of an unlinked admin has no form, so borrow the session token from a page that does
        if ($adToken !== '') break;
        $tp = $ad->req('GET', '/index.php?page='.$tokenPage);
        if (preg_match('/name="csrf" value="([^"]+)"/', $tp['body'], $tm)) $adToken = html_entity_decode($tm[1]);
    }
    $adCodes = [$ad->req('POST', '/index.php?page=employee-profile-reveal', ['csrf' => $adToken, 'id' => $ownId])['code'], $ad->req('GET', '/index.php?page=employee-profile-document&id='.$ownDoc)['code'], $ad->req('GET', '/index.php?page=employee-profile-photo')['code']];
    check('D2 same admin gets 404 (not 500) on reveal, document and photo', $adToken !== '' && $adCodes === [404, 404, 404], 'token='.($adToken === '' ? 'none' : 'ok').' codes='.implode(',', $adCodes));
}
// Deactivating a user in the app revokes their tracked sessions (FoundationRepository / EmployeeArchiveService); do the same here.
$pdo->prepare('UPDATE users SET status="INACTIVE" WHERE id=?')->execute([$empUser]);
$pdo->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL')->execute([$empUser]);
$gone = $a->req('GET', '/index.php?page=employee-profile');
$goneDoc = $a->req('GET', '/index.php?page=employee-profile-document&id='.$ownDoc);
check('D2 a deactivated user\'s live session is cut off on the page and on the file routes', $gone['code'] === 302 && str_contains($gone['loc'], 'page=login') && $goneDoc['code'] === 302, $gone['code'].' / '.$goneDoc['code']);
$pdo->prepare('UPDATE users SET status="ACTIVE" WHERE id=?')->execute([$empUser]);

// ===== audit =====
$n = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='REVEAL_OWN_GOV_ID' AND user_id=".$empUser." AND created_at>='".$runStart."'")->fetchColumn();
$aud = (string)$pdo->query("SELECT details_json FROM audit_logs WHERE action='REVEAL_OWN_GOV_ID' ORDER BY id DESC LIMIT 1")->fetchColumn();
check('E exactly one audited reveal, number not logged', $n === 1 && !str_contains($aud, '3522') && str_contains($aud, 'SSS'), $n.' '.$aud);
$log = (string)@file_get_contents($tmp.'/server.log');
check('E no PHP warnings or fatals during the run', !preg_match('/Warning|Notice|Deprecated|Fatal|Parse error/', $log), substr($log, 0, 300));

// ===== P. Optional measurements (HRIS_PERF=1): server timing and payload sizes of the isolated copy; printed, not gated =====
if (getenv('HRIS_PERF')) {
    $time = static function (Browser $br, string $path, int $n = 5): array {
        $ms = []; $last = null;
        for ($i = 0; $i < $n; $i++) { $t = microtime(true); $last = $br->req('GET', $path); $ms[] = (microtime(true) - $t) * 1000; }
        sort($ms);
        return [$last, round($ms[0]), round($ms[(int)floor($n / 2)])];
    };
    foreach (['employee-profile' => '/index.php?page=employee-profile', 'employee-dashboard (baseline)' => '/index.php?page=employee-dashboard'] as $name => $path) {
        [$r, $min, $med] = $time($a, $path);
        $h = $r['body'];
        preg_match_all('/<link[^>]+rel="stylesheet"[^>]*>/', $h, $css); preg_match_all('/<script[^>]+src=/', $h, $js); preg_match_all('/<img\b/', $h, $img);
        echo sprintf("PERF %-30s server min %d ms, median %d ms | html %d B (gzip %d B) | stylesheets %d, scripts %d, imgs %d\n", $name, $min, $med, strlen($h), strlen(gzencode($h, 6)), count($css[0]), count($js[0]), count($img[0]));
    }
    foreach (['employee-profile.css', 'employee-profile.js', 'hris-modern.css', 'app.css', 'hris-app.js'] as $f) {
        $p = $tmp.'/public/assets/'.$f; if (is_file($p)) echo sprintf("PERF asset %-22s %d B (gzip %d B)\n", $f, filesize($p), strlen(gzencode((string)file_get_contents($p), 6)));
    }
    $q = $pdo->query('SELECT COUNT(*) FROM employee_documents WHERE employee_id='.$own)->fetchColumn();
    echo 'PERF own documents in fixture: '.$q."\n";
}

// ===== F. Optional screenshots with headless Edge (HRIS_SHOTS=<existing folder>): real rendering of the isolated copy, never the live app =====
$shots = getenv('HRIS_SHOTS') ?: '';
$edge = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
if ($shots !== '' && is_dir($shots) && is_file($edge)) {
    // Test-only entry in the temp copy: sign in with the fixture password, then go to ?to=
    file_put_contents($tmp.'/shot_login.php', "<?php\nrequire __DIR__.'/app/bootstrap.php';\nAuth::login((string)\$_GET['email'], 'Test#1234');\nheader('Location: '.(string)\$_GET['to']);\n");
    $routerSrc = (string)file_get_contents($tmp.'/router.php');
    file_put_contents($tmp.'/router.php', preg_replace('/declare\(strict_types=1\);/', "declare(strict_types=1);\nif (parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/shot_login.php') { require __DIR__.'/shot_login.php'; return true; }", $routerSrc, 1));
    $targets = [
        ['employee-hero-1280', $empUser, '/index.php?page=employee-profile', 1280, 1100],
        ['employee-employment-1280', $empUser, '/index.php?page=employee-profile#myp-employment', 1280, 1500],
        ['employee-files-1280', $empUser, '/index.php?page=employee-profile#myp-files', 1280, 1200],
        ['employee-files-500', $empUser, '/index.php?page=employee-profile#myp-files', 500, 2200],
        ['employee-personal-500', $empUser, '/index.php?page=employee-profile', 500, 1500],
        ['hr-hero-1280', $hrUser, '/index.php?page=employee-profile', 1280, 900],
    ];
    foreach ($targets as [$name, $uid, $to, $w, $h]) {
        $url = 'http://127.0.0.1:'.$port.'/shot_login.php?email='.rawurlencode($email($uid)).'&to='.rawurlencode($to);
        $out = rtrim($shots, '/\\').DIRECTORY_SEPARATOR.$name.'.png';
        @unlink($out);
        exec('"'.$edge.'" --headless=new --disable-gpu --hide-scrollbars --user-data-dir="'.$tmp.'/edge_'.$name.'" --window-size='.$w.','.$h.' --virtual-time-budget=4000 --screenshot="'.$out.'" "'.$url.'" 2>&1', $o, $rc);
        check('F screenshot '.$name, is_file($out) && filesize($out) > 5000);
    }
}

exit($fail ? 1 : 0);
