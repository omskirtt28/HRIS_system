<?php
declare(strict_types=1);

/**
 * Downloads, FAQs and the three pay pages over real HTTP. SCRATCH database only (see employee_profile_http_test.php for the copy steps);
 * the portal content migration is applied to the scratch copy by this test if the tables are missing.
 *   HRIS_TEST_DB=pmbsi_hris_test C:\xampp\php\php.exe tests\profile_content_http_test.php
 * The app is copied to a temp folder, pointed at the scratch DB, served by php -S and driven with curl. The repo is not touched.
 */

$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_content_http_'.bin2hex(random_bytes(4));
$port = 8097;
$pdo = new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='portal_faqs'")->fetchColumn() === 0) {
    $pdo->exec((string)file_get_contents($root.'/database/migrations/20261031_018_portal_content.sql'));
}
$pdo->exec('DELETE FROM portal_downloads'); $pdo->exec('DELETE FROM portal_faqs');

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
file_put_contents($tmp.'/config/config.local.php', "<?php\nreturn ['app'=>['debug'=>true],'mail'=>[],'db'=>['host'=>'127.0.0.1','port'=>'3306','database'=>".var_export($dbName, true).",'username'=>'root','password'=>'','charset'=>'utf8mb4'],'incident_documents'=>['storage_root'=>".var_export($tmp.'/private', true)."]];\n");

$hash = password_hash('Test#1234', PASSWORD_DEFAULT);
$empUser = 10; $hrUser = 9;
$pdo->prepare('UPDATE users SET password_hash=?, status="ACTIVE" WHERE id IN (?,?)')->execute([$hash, $empUser, $hrUser]);
$email = fn(int $id): string => (string)$pdo->query('SELECT email FROM users WHERE id='.$id)->fetchColumn();

$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $tmp, $tmp.'/router.php'], [['pipe', 'r'], ['file', $tmp.'/server.log', 'w'], ['file', $tmp.'/server.log', 'a']], $pipes, $tmp);
register_shutdown_function(function () use ($proc, $tmp) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } usleep(300000); rmTree($tmp); });
for ($i = 0; $i < 50; $i++) { $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($s) { fclose($s); break; } usleep(100000); }

$fail = 0;
function check(string $label, bool $ok, string $why = ''): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ').$label.($ok || $why === '' ? '' : '  ['.$why.']')."\n"; if (!$ok) $fail++; }

final class Browser {
    private string $jar;
    public function __construct(private int $port) { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    /** $post may hold CURLFile values (sent as multipart). */
    public function req(string $method, string $path, array $post = []): array {
        $ch = curl_init('http://127.0.0.1:'.$this->port.$path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30]);
        if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
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
    public function csrf(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }
}
$main = static fn(string $html): string => (preg_match('/<main class="content">(.*)<\/main>/s', $html, $m) ? $m[1] : $html);
$cnt = fn(string $t): int => (int)$pdo->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();
$tmpFile = function (string $content, string $name) use ($tmp): CURLFile { $p = $tmp.'/up_'.bin2hex(random_bytes(3)); file_put_contents($p, $content); return new CURLFile($p, 'application/octet-stream', $name); };
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

// ===== A. Employee: read-only =====
$a = new Browser($port);
check('A login as employee', $a->login($email($empUser), 'Test#1234'));
foreach (['profile-downloads' => 'Downloads', 'profile-faqs' => 'FAQs', 'profile-payslip' => 'Payslip', 'profile-deductions' => 'Deductions', 'profile-additional' => 'Additional'] as $pg => $title) {
    $r = $a->req('GET', '/index.php?page='.$pg);
    check('A '.$pg.' opens with its title and the employee breadcrumb', $r['code'] === 200 && str_contains($r['body'], 'Employee Self-Service / '.$title), 'code '.$r['code']);
}
$pay = $main($a->req('GET', '/index.php?page=profile-additional')['body']);
check('A Additional shows the empty state naming bonuses, allowances and 13th month', str_contains($pay, 'Bonuses, allowances, 13th month pay') && !preg_match('/<form|<input/', $pay));
$dl = $main($a->req('GET', '/index.php?page=profile-downloads')['body']);
check('A downloads page has no upload form for an employee', !str_contains($dl, 'type="file"') && !str_contains($dl, 'name="action"'));
$side = $a->req('GET', '/index.php?page=profile-faqs')['body'];
check('A employee sidebar has no manage links', !str_contains($side, 'Portal Downloads') && !str_contains($side, 'Portal FAQs'));
$tok = $a->csrf($a->req('GET', '/index.php?page=employee-leave')['body']); // a page that really carries a session CSRF token, so the 403 (not the 419) is what is tested
check('A employee holds a valid CSRF token', $tok !== '');
$blocked = $a->req('POST', '/index.php?page=profile-downloads', ['csrf' => $tok, 'action' => 'add', 'title' => 'Nope', 'file' => $tmpFile($pdf, 'nope.pdf')]);
check('A employee upload is refused on the server and nothing is stored', $blocked['code'] === 403 && $cnt('portal_downloads') === 0, (string)$blocked['code']);
$blockedFaq = $a->req('POST', '/index.php?page=profile-faqs', ['csrf' => $tok, 'action' => 'save', 'id' => '0', 'question' => 'Q', 'answer' => 'A', 'sort_order' => '1']);
check('A employee FAQ write is refused', $blockedFaq['code'] === 403 && $cnt('portal_faqs') === 0, (string)$blockedFaq['code']);
$anon = (new Browser($port))->req('GET', '/index.php?page=profile-downloads');
check('A anonymous is sent to login', $anon['code'] === 302 && str_contains($anon['loc'], 'login'), $anon['code'].' '.$anon['loc']);

// ===== B. HR: manage =====
$b = new Browser($port);
check('B login as HR', $b->login($email($hrUser), 'Test#1234'));
$page = $b->req('GET', '/index.php?page=profile-downloads'); $hrHtml = $page['body']; $hrBody = $main($hrHtml); $csrf = $b->csrf($hrBody);
check('B downloads page shows the upload form and a CSRF token', $page['code'] === 200 && str_contains($hrBody, 'type="file"') && $csrf !== '' && str_contains($hrBody, 'Human Resources / Downloads'));
check('B HR sidebar lists Portal Downloads and Portal FAQs', str_contains($hrHtml, 'page=profile-downloads') && str_contains($hrHtml, 'Portal FAQs'));
$add = $b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'add', 'title' => 'Leave form <b>x</b>', 'description' => 'Fill and submit', 'file' => $tmpFile($pdf, 'leave form.pdf')]);
check('B valid PDF is accepted (redirect back)', $add['code'] === 302 && $cnt('portal_downloads') === 1, $add['code'].' '.substr($add['body'], 0, 120));
$row = $pdo->query('SELECT * FROM portal_downloads LIMIT 1')->fetch();
check('B stored under a random name inside storage/portal_downloads, original name kept', $row && preg_match('/^dl_[0-9a-f]{32}\.pdf$/', (string)$row['stored_name']) === 1 && $row['original_name'] === 'leave form.pdf' && is_file($tmp.'/storage/portal_downloads/'.$row['stored_name']));
$listed = $main($b->req('GET', '/index.php?page=profile-downloads')['body']);
check('B title is escaped in the list', str_contains($listed, 'Leave form &lt;b&gt;x&lt;/b&gt;') && !str_contains($listed, '<b>x</b>'));
$bad1 = $b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'add', 'title' => 'x', 'file' => $tmpFile('<?php echo 1;', 'shell.php')]);
$bad2 = $b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'add', 'title' => 'x', 'file' => $tmpFile('<?php echo 1;', 'fake.pdf')]);
$bad3 = $b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'add', 'title' => 'x']);
check('B .php, a PHP file renamed .pdf, and no file are all rejected', $cnt('portal_downloads') === 1 && count(glob($tmp.'/storage/portal_downloads/*')) === 1, $bad1['code'].$bad2['code'].$bad3['code']);
$noTok = $b->req('POST', '/index.php?page=profile-downloads', ['action' => 'add', 'title' => 'x', 'file' => $tmpFile($pdf, 'a.pdf')]);
check('B upload without a CSRF token is refused (419)', $noTok['code'] === 419 && $cnt('portal_downloads') === 1);
$big = $b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'add', 'title' => 'big', 'file' => $tmpFile($pdf.str_repeat('0', 10 * 1024 * 1024), 'big.pdf')]);
check('B a file over 10 MB is rejected', $cnt('portal_downloads') === 1, (string)$big['code']);

// ===== C. File download =====
$id = (int)$row['id'];
$f = $a->req('GET', '/index.php?page=profile-download-file&id='.$id);
check('C employee downloads it as an attachment, nosniff, same bytes', $f['code'] === 200 && stripos($f['head'], 'attachment') !== false && stripos($f['head'], 'nosniff') !== false && $f['body'] === $pdf, (string)$f['code']);
check('C unknown id is 404', $a->req('GET', '/index.php?page=profile-download-file&id=999999')['code'] === 404);
check('C anonymous cannot download', (new Browser($port))->req('GET', '/index.php?page=profile-download-file&id='.$id)['code'] === 302);

// ===== D. FAQs =====
$fp = $b->req('GET', '/index.php?page=profile-faqs'); $fcsrf = $b->csrf($fp['body']);
$b->req('POST', '/index.php?page=profile-faqs', ['csrf' => $fcsrf, 'action' => 'save', 'id' => '0', 'question' => 'How do I file leave? <script>alert(1)</script>', 'answer' => "Open Leave.\nThen apply.", 'sort_order' => '5']);
check('D HR adds an FAQ', $cnt('portal_faqs') === 1);
$ev = $main($a->req('GET', '/index.php?page=profile-faqs')['body']);
check('D employee reads it, script escaped, line break kept', str_contains($ev, '&lt;script&gt;') && !str_contains($ev, '<script>alert') && str_contains($ev, 'Open Leave.<br />') && !str_contains($ev, 'name="action"'));
$faqId = (int)$pdo->query('SELECT id FROM portal_faqs LIMIT 1')->fetchColumn();
$b->req('POST', '/index.php?page=profile-faqs', ['csrf' => $fcsrf, 'action' => 'save', 'id' => (string)$faqId, 'question' => 'Edited?', 'answer' => 'Yes.', 'sort_order' => '1']);
check('D HR edits it', (string)$pdo->query('SELECT question FROM portal_faqs WHERE id='.$faqId)->fetchColumn() === 'Edited?' && $cnt('portal_faqs') === 1);
$b->req('POST', '/index.php?page=profile-faqs', ['csrf' => $fcsrf, 'action' => 'save', 'id' => '0', 'question' => str_repeat('q', 301), 'answer' => 'A', 'sort_order' => '1']);
$b->req('POST', '/index.php?page=profile-faqs', ['csrf' => $fcsrf, 'action' => 'save', 'id' => '0', 'question' => '  ', 'answer' => 'A', 'sort_order' => '1']);
check('D a 301-character question and a blank question are rejected', $cnt('portal_faqs') === 1);
$b->req('POST', '/index.php?page=profile-faqs', ['csrf' => $fcsrf, 'action' => 'remove', 'id' => (string)$faqId]);
check('D remove hides it from employees (row kept, active=0)', $cnt('portal_faqs') === 1 && (int)$pdo->query('SELECT active FROM portal_faqs WHERE id='.$faqId)->fetchColumn() === 0 && !str_contains($a->req('GET', '/index.php?page=profile-faqs')['body'], 'Edited?'));

// ===== E. Remove a download =====
$b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'remove', 'id' => (string)$id]);
check('E remove hides it: gone from the list, 404 for employees, file kept on disk', !str_contains($a->req('GET', '/index.php?page=profile-downloads')['body'], 'Leave form') && $a->req('GET', '/index.php?page=profile-download-file&id='.$id)['code'] === 404 && is_file($tmp.'/storage/portal_downloads/'.$row['stored_name']));
$aud = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE module='Portal' AND action IN ('ADD_DOWNLOAD','REMOVE_DOWNLOAD','ADD_FAQ','UPDATE_FAQ','REMOVE_FAQ')")->fetchColumn();
check('E every write was audited (5 events)', $aud >= 5, (string)$aud);

// ===== H. Client and admin on the new routes =====
$pdo->prepare('UPDATE users SET password_hash=?, status="ACTIVE" WHERE id IN (5,7)')->execute([$hash]);
$cl = new Browser($port);
check('H login as client', $cl->login($email(5), 'Test#1234'));
$clientOk = true; $clientWhy = '';
foreach (['profile-downloads', 'profile-faqs', 'profile-payslip', 'profile-deductions', 'profile-additional', 'profile-download-file&id='.$id] as $pg) {
    $r = $cl->req('GET', '/index.php?page='.$pg);
    if (in_array($r['code'], [200], true) || str_contains($r['body'], 'Leave form')) { $clientOk = false; $clientWhy .= $pg.'='.$r['code'].' '; }
}
check('H client account cannot open any new page or download', $clientOk, $clientWhy);
$cp = $cl->req('POST', '/index.php?page=profile-faqs', ['action' => 'save', 'id' => '0', 'question' => 'c', 'answer' => 'c', 'sort_order' => '1']);
check('H client POST writes nothing', $cnt('portal_faqs') === 1 && $cp['code'] !== 200);
$ad = new Browser($port);
check('H login as admin', $ad->login($email(7), 'Test#1234'));
$ap = $ad->req('GET', '/index.php?page=profile-faqs');
check('H admin sees the Administration breadcrumb and the add form', $ap['code'] === 200 && str_contains($ap['body'], 'Administration / FAQs') && str_contains($ap['body'], 'Add an FAQ'));
check('H admin can open all pay pages', array_reduce(['profile-payslip', 'profile-deductions', 'profile-additional'], fn($ok, $p) => $ok && $ad->req('GET', '/index.php?page='.$p)['code'] === 200, true));

// ===== I. Upload edge pack =====
$before = $cnt('portal_downloads'); $files0 = count(glob($tmp.'/storage/portal_downloads/*'));
$z = $b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'add', 'title' => 'zero', 'file' => $tmpFile('', 'zero.pdf')]);
$dbl = $b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'add', 'title' => 'dbl', 'file' => $tmpFile($pdf, 'shell.pdf.php')]);
check('I zero-byte file and shell.pdf.php are rejected, nothing stored', $cnt('portal_downloads') === $before && count(glob($tmp.'/storage/portal_downloads/*')) === $files0);
$b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'add', 'title' => 'a.php.pdf', 'file' => $tmpFile($pdf, 'a.php.pdf')]);
$dr = $pdo->query("SELECT stored_name FROM portal_downloads WHERE title='a.php.pdf'")->fetchColumn();
check('I a.php.pdf is a real PDF: stored with a random .pdf name only', $dr !== false && preg_match('/^dl_[0-9a-f]{32}\.pdf$/', (string)$dr) === 1);
$exact = str_pad($pdf, 10 * 1024 * 1024, "\n");
$b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'add', 'title' => 'exact', 'file' => $tmpFile($exact, 'exact.pdf')]);
$b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'add', 'title' => 'over', 'file' => $tmpFile($exact."\n", 'over.pdf')]);
check('I exactly 10 MB is accepted, 10 MB + 1 byte is rejected', (int)$pdo->query("SELECT COUNT(*) FROM portal_downloads WHERE title='exact'")->fetchColumn() === 1 && (int)$pdo->query("SELECT COUNT(*) FROM portal_downloads WHERE title='over'")->fetchColumn() === 0);
$uni = "Płać – 給与 \"x\";\r\n.pdf";
$b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'add', 'title' => 'uni', 'file' => $tmpFile($pdf, $uni)]);
$uid = (int)$pdo->query("SELECT id FROM portal_downloads WHERE title='uni'")->fetchColumn();
$ur = $a->req('GET', '/index.php?page=profile-download-file&id='.$uid);
check('I unicode/quoted file name downloads with a header-safe name and the same bytes', $uid > 0 && $ur['code'] === 200 && $ur['body'] === $pdf && !preg_match('/filename="[^"]*[\r\n";][^"]*"/', $ur['head']) && substr_count($ur['head'], "\n") < 20);
$arr = $b->req('POST', '/index.php?page=profile-downloads', ['csrf' => $csrf, 'action' => 'add', 'title' => 'arr', 'file[0]' => $tmpFile($pdf, 'x.pdf')]);
check('I a file[] array field is refused without a server error', $arr['code'] < 500 && (int)$pdo->query("SELECT COUNT(*) FROM portal_downloads WHERE title='arr'")->fetchColumn() === 0);

// ===== J. FAQ boundaries =====
$faqCount = $cnt('portal_faqs');
$b->req('POST', '/index.php?page=profile-faqs', ['csrf' => $fcsrf, 'action' => 'save', 'id' => '0', 'question' => str_repeat('q', 300), 'answer' => str_repeat('a', 4000), 'sort_order' => '-5']);
$b->req('POST', '/index.php?page=profile-faqs', ['csrf' => $fcsrf, 'action' => 'save', 'id' => '0', 'question' => 'long answer', 'answer' => str_repeat('a', 4001), 'sort_order' => '1']);
$b->req('POST', '/index.php?page=profile-faqs', ['csrf' => $fcsrf, 'action' => 'save', 'id' => '0', 'question' => 'big order', 'answer' => 'x', 'sort_order' => '999999']);
check('J 300/4000 accepted, 4001 rejected', $cnt('portal_faqs') === $faqCount + 2);
check('J sort_order is clamped to 0..9999', (int)$pdo->query("SELECT sort_order FROM portal_faqs WHERE question LIKE 'qqqq%'")->fetchColumn() === 0 && (int)$pdo->query("SELECT sort_order FROM portal_faqs WHERE question='big order'")->fetchColumn() === 9999);
$b->req('POST', '/index.php?page=profile-faqs', ['csrf' => $fcsrf, 'action' => 'save', 'id' => (string)$faqId, 'question' => 'Revive', 'answer' => 'x', 'sort_order' => '1']);
check('J editing a removed FAQ changes nothing', (string)$pdo->query('SELECT question FROM portal_faqs WHERE id='.$faqId)->fetchColumn() === 'Edited?');
$b->req('POST', '/index.php?page=profile-faqs', ['csrf' => $fcsrf, 'action' => 'remove', 'id' => (string)$faqId]);
check('J removing twice is harmless', (int)$pdo->query('SELECT active FROM portal_faqs WHERE id='.$faqId)->fetchColumn() === 0);

// ===== G. HR employment form (Lee code, directory labels) =====
$empId = (int)$pdo->query('SELECT id FROM employees WHERE user_id IS NOT NULL ORDER BY id LIMIT 1')->fetchColumn();
$form = $b->req('GET', '/index.php?page=hr-employee&id='.$empId.'&tab=employment');
$fh = $form['body'];
check('G employment form opens for HR', $form['code'] === 200 && str_contains($fh, 'name="employee_no"'), (string)$form['code']);
check('G Lee code input (max 30) is on the form, prefilled value escaped', str_contains($fh, 'name="lee_code"') && str_contains($fh, 'maxlength="30"') && str_contains($fh, 'Payroll code used by Lee'));
check('G directory fields use the clear labels', str_contains($fh, '>Directory role<') && str_contains($fh, 'Automatic (from position)') && str_contains($fh, 'Head Office team') && !str_contains($fh, '>Directory section<') && !str_contains($fh, 'Use saved position'));

// ===== F. Direct access to the storage folder is blocked by .htaccess rule (static) =====
$ht = (string)file_get_contents($root.'/.htaccess');
check('F storage/ is denied by the root .htaccess', str_contains($ht, 'storage'));

exit($fail ? 1 : 0);
