<?php
declare(strict_types=1);

/**
 * hr-appearance-palettes over HTTP (SPEC-hr-appearance-palettes.md, Task 2): real login, real routes, real HTML. Needs a SCRATCH database copy :
 *   HRIS_TEST_DB=<scratch> C:\xampp\php\php.exe tests\hr_palette_http_test.php
 * The app is copied to a temp folder and served by php -S; fixtures are written to the scratch DB only.
 */
$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_pal_http_'.bin2hex(random_bytes(4));
$port = 8104;
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
$pw = 'Test#1234';
$mkUser = static function (string $role, string $label) use ($pdo, $pw): array {
    $email = 'zz_pal_'.$label.'_'.bin2hex(random_bytes(3)).'@example.test';
    $pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code=?),?,?,?,"ACTIVE")')->execute([$role, 'Zz '.$label, $email, password_hash($pw, PASSWORD_DEFAULT)]);
    return ['id' => (int)$pdo->lastInsertId(), 'email' => $email];
};
$uHr = $mkUser('HR_USER', 'hr'); $uStaff = $mkUser('EMPLOYEE', 'staff'); $uAdmin = $mkUser('HRIS_ADMIN', 'admin');
$stored = static fn(int $id): string => (string)$GLOBALS['pdo']->query('SELECT ui_palette FROM users WHERE id='.$id)->fetchColumn();
$hr = new Browser($port); $staff = new Browser($port); $admin = new Browser($port); $anon = new Browser($port);
check('fixture accounts sign in', $hr->login($uHr['email'], $pw) && $staff->login($uStaff['email'], $pw) && $admin->login($uAdmin['email'], $pw));
$tok = static fn(Browser $b): string => $csrfOf($b->req('GET', '/index.php?page=change-password')['body']);
$post = static fn(Browser $b, array $f): array => $b->req('POST', '/index.php?page=palette-save', $f);

$hrTok = $tok($hr);
$r = $post($hr, ['csrf' => $hrTok, 'palette' => 'beige']);
check('HR saves Soft Beige: 200 with JSON ok and the DB row changes', $r['code'] === 200 && str_contains($r['head'], 'application/json') && json_decode($r['body'], true) === ['ok' => true, 'palette' => 'beige'] && $stored($uHr['id']) === 'beige');
$r = $post($hr, ['csrf' => $hrTok, 'palette' => 'neon']);
check('an unknown palette is refused with 422 and nothing changes', $r['code'] === 422 && $stored($uHr['id']) === 'beige');
$r = $post($hr, ['csrf' => $hrTok, 'palette' => "beige'; DROP TABLE users;--"]);
check('an injection-style value is refused and the users table is intact', $r['code'] === 422 && $stored($uHr['id']) === 'beige');
$r = $post($hr, ['palette' => 'earth']);
check('no CSRF token: 419 and nothing changes', $r['code'] === 419 && $stored($uHr['id']) === 'beige');
$r = $post($hr, ['csrf' => 'wrong', 'palette' => 'earth']);
check('a wrong CSRF token: 419 and nothing changes', $r['code'] === 419 && $stored($uHr['id']) === 'beige');
$r = $hr->req('GET', '/index.php?page=palette-save');
check('GET is refused with 405', $r['code'] === 405 && $stored($uHr['id']) === 'beige');
check('HR can switch to dark and back', $post($hr, ['csrf' => $hrTok, 'palette' => 'dark'])['code'] === 200 && $stored($uHr['id']) === 'dark' && $post($hr, ['csrf' => $hrTok, 'palette' => 'pastel'])['code'] === 200 && $stored($uHr['id']) === 'pastel');

$r = $post($staff, ['csrf' => $tok($staff), 'palette' => 'beige']);
check('an Employee-portal user is refused with 403 and keeps light', $r['code'] === 403 && $stored($uStaff['id']) === 'light');
$r = $post($admin, ['csrf' => $tok($admin), 'palette' => 'beige']);
check('an Admin user is refused with 403 (HR portal only) and keeps light', $r['code'] === 403 && $stored($uAdmin['id']) === 'light');
$r = $post($anon, ['csrf' => 'x', 'palette' => 'beige']);
check('a signed-out visitor cannot save', $r['code'] !== 200);

// ---- Task 4: the menu and the server-rendered palette ----
$pageOf = static fn(Browser $b, string $page): string => $b->req("GET", "/index.php?page=".$page)["body"];
$htmlTag = static fn(string $h): string => preg_match("/<html[^>]*>/", $h, $m) ? $m[0] : "";
$post($hr, ["csrf" => $hrTok, "palette" => "beige"]);
$h = $pageOf($hr, "hr-dashboard"); $tag = $htmlTag($h);
check("HR page: the saved palette is on the html tag from the server (no flash)", str_contains($tag, "data-theme=\"light\"") && str_contains($tag, "data-palette=\"beige\"") && str_contains($tag, "data-palette-user"));
check("HR Appearance opens a list of 6 radio options in order", preg_match_all("/role=\"menuitemradio\"/", $h) === 6 && (bool)preg_match("/Light.*Dark.*Soft Beige.*Earth Tones.*Soft Pastel.*Soft Pink/s", substr($h, (int)strpos($h, "id=\"palList\""), 3500)));
check("HR list: only the saved palette is checked", substr_count($h, "aria-checked=\"true\"") === 1 && (bool)preg_match("/aria-checked=\"true\"[^>]*data-palette-key=\"beige\"|data-palette-key=\"beige\"[^>]*aria-checked=\"true\"/", $h));
check("HR Appearance button announces a popup and starts collapsed", (bool)preg_match("/aria-haspopup=\"true\"[^>]*aria-expanded=\"false\"|aria-expanded=\"false\"[^>]*aria-haspopup=\"true\"/", $h) && (bool)preg_match("/id=\"palList\"[^>]* hidden/", $h));
check("HR page loads palettes.css and palette-menu.css and no longer toggles theme in one click", str_contains($h, "assets/palettes.css") && str_contains($h, "assets/palette-menu.css") && !str_contains($h, "onclick=\"toggleTheme()\""));
check("HR list carries the CSRF token for the save call", (bool)preg_match("/id=\"palList\"[^>]*data-csrf=\"[^\"]{16,}\"/", $h));
$GLOBALS["pdo"]->exec("UPDATE users SET ui_palette='neon' WHERE id=".$uHr["id"]);
$neon = new Browser($port); $neon->login($uHr["email"], $pw);
$tag = $htmlTag($pageOf($neon, "hr-dashboard"));
check("HR with an unknown stored value renders Light with no palette", str_contains($tag, "data-theme=\"light\"") && !str_contains($tag, "data-palette=\""));
$GLOBALS["pdo"]->exec("UPDATE users SET ui_palette='light' WHERE id=".$uHr["id"]);
foreach (["Employee" => [$staff, "employee-dashboard"], "Admin" => [$admin, "admin-dashboard"]] as $who => [$b, $pg]) { $p = $pageOf($b, $pg); $t = $htmlTag($p);
    check("$who page keeps the one-click Light/Dark toggle and no palette markup", str_contains($p, "onclick=\"toggleTheme()\"") && !str_contains($p, "palList") && !str_contains($p, "palettes.css") && !str_contains($t, "data-palette")); }

$GLOBALS["pdo"]->exec("UPDATE users SET ui_palette='pastel' WHERE id=".$uHr["id"]);
$again = new Browser($port);
check('a new sign-in of the same HR user shows the saved palette on the page', $again->login($uHr['email'], $pw) && str_contains($htmlTag($pageOf($again, 'hr-dashboard')), 'data-palette="pastel"'));
exit($fail ? 1 : 0);
