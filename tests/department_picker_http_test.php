<?php
declare(strict_types=1);

/**
 * Department + Position picker over HTTP (SPEC-department-picker.md): real login, real forms, real POSTs. Needs a SCRATCH copy:
 *   HRIS_TEST_DB=<scratch> C:\xampp\php\php.exe tests\department_picker_http_test.php
 * The app is copied to a temp folder and served by php -S; fixtures (Zz) are written to the scratch DB only.
 */
$dbName = getenv('HRIS_TEST_DB') ?: '';
if ($dbName === '' || $dbName === 'pmbsi_hris') { fwrite(STDERR, "Set HRIS_TEST_DB to a scratch copy (not pmbsi_hris).\n"); exit(2); }
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hris_dp_http_'.bin2hex(random_bytes(4));
$port = 8117;
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
$pw = 'Test#1234'; $email = 'zz_dp_hr_'.bin2hex(random_bytes(3)).'@example.test';
$pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE code="HR_USER"),?,?,?,"ACTIVE")')->execute(['Zz dp hr', $email, password_hash($pw, PASSWORD_DEFAULT)]);
$hr = new Browser($port);
check('fixture HR account signs in', $hr->login($email, $pw));
function selectOptions(string $html, string $name): array {
    if (!preg_match('#<select name="'.$name.'"[^>]*>(.*?)</select>#s', $html, $m)) return [];
    preg_match_all('#<option value="([^"]*)"[^>]*>([^<]*)</option>#', $m[1], $o, PREG_SET_ORDER);
    return array_map(static fn($x) => [$x[1], html_entity_decode($x[2])], $o);
}

// ---- Add Employee: the short Department list and grouped positions ----
$new = $hr->req('GET', '/index.php?page=hr-employee-new');
check('Add Employee renders (200)', $new['code'] === 200 && str_contains($new['body'], 'name="department_group"'));
check('no old department_id select on Add Employee', !str_contains($new['body'], 'name="department_id"'));
$groups = array_column(selectOptions($new['body'], 'department_group'), 1);
check('Department lists exactly the agreed choices', $groups === ['Unassigned', 'Manpower Planning', 'Inventory Management', 'Finance', 'HR', 'Retail', 'Marketing & Creatives', 'Online'], implode(' | ', $groups));
check('no long-list department names remain (Ho Billing, Treasury, Audit...)', !preg_match('/>(Ho Billing|Treasury|Audit|Ho Supply|Stock Control)</', $new['body']));
check('positions carry their group for the page script', preg_match_all('/<option value="\d+" data-group="[A-Z]+"/', $new['body']) > 30 && str_contains($new['body'], '<optgroup label="Administration" data-group="MANPOWER">'));
$retailManager = (int)$pdo->query('SELECT p.id FROM positions p JOIN departments d ON d.id=p.department_id WHERE p.name="Retail Manager" AND d.code="RETAIL"')->fetchColumn();
check('Retail Manager sits under Retail only', (bool)preg_match('/<option value="'.$retailManager.'" data-group="RETAIL"/', $new['body']) && substr_count($new['body'], 'value="'.$retailManager.'" data-group=') === 1);

// ---- POST: a mismatched pair is refused with both names, and nothing is saved ----
$base = fn(string $no, array $more) => array_merge(['csrf' => $csrfOf($new['body']), 'action' => 'create_employee', 'return_page' => 'hr-employee-new', 'first_name' => 'Zz'.substr($no, -4), 'last_name' => 'Picker', 'work_company' => '2LOG', 'employment_type_id' => '1', 'hire_date' => '2026-11-02', 'account_role' => 'EMPLOYEE', 'employee_no' => $no], $more);
$noBad = 'ZZDP-'.bin2hex(random_bytes(3));
$r = $hr->req('POST', '/index.php?page=hr-employee-new', $base($noBad, ['department_group' => 'MANPOWER', 'position_id' => (string)$retailManager]));
$back = $hr->req('GET', '/index.php?page=hr-employee-new');
check('mismatch: redirected back to the form', $r['code'] === 302 && str_contains($r['loc'], 'hr-employee-new'), $r['loc']);
check('mismatch: the error names both the position and the department', str_contains($back['body'], 'Retail Manager') && str_contains($back['body'], 'belongs to Retail, not Manpower Planning'));
check('mismatch: no employee was created', (int)$pdo->query('SELECT COUNT(*) FROM employees WHERE employee_no='.$pdo->quote($noBad))->fetchColumn() === 0);

// ---- POST: a matching pair saves and derives the stored department ----
$noOk = 'ZZDP-'.bin2hex(random_bytes(3));
$r = $hr->req('POST', '/index.php?page=hr-employee-new', $base($noOk, ['department_group' => 'RETAIL', 'position_id' => (string)$retailManager]));
$row = $pdo->query('SELECT e.id,e.department_id,e.position_id,d.code dcode FROM employees e LEFT JOIN departments d ON d.id=e.department_id WHERE e.employee_no='.$pdo->quote($noOk))->fetch();
check('Retail Manager under Retail saves; stored department is Retail', $row && $row['dcode'] === 'RETAIL' && (int)$row['position_id'] === $retailManager, json_encode($row).' '.$r['loc']);
$noAdmin = 'ZZDP-'.bin2hex(random_bytes(3));
$adminStaff = (int)$pdo->query('SELECT p.id FROM positions p JOIN departments d ON d.id=p.department_id WHERE p.name="Admin Staff" AND d.name="Admin"')->fetchColumn();
$hr->req('POST', '/index.php?page=hr-employee-new', $base($noAdmin, ['department_group' => 'MANPOWER', 'position_id' => (string)$adminStaff]));
$row2 = $pdo->query('SELECT d.name dname FROM employees e LEFT JOIN departments d ON d.id=e.department_id WHERE e.employee_no='.$pdo->quote($noAdmin))->fetch();
check('Admin Staff under Manpower Planning stores the team department Admin', $row2 && $row2['dname'] === 'Admin', json_encode($row2));

// ---- Employee 201: edit form pre-selects the current group/position; a legacy POST moves nothing ----
$id = (int)($row['id'] ?? 0);
$edit = $hr->req('GET', '/index.php?page=hr-employee&id='.$id.'&tab=employment');
check('Employee 201 renders (200) with the picker', $edit['code'] === 200 && str_contains($edit['body'], 'name="department_group"') && !str_contains($edit['body'], 'name="department_id"'));
check('Employee 201 pre-selects Retail and Retail Manager', (bool)preg_match('/<option value="RETAIL"[^>]*selected/', $edit['body']) && (bool)preg_match('/<option value="'.$retailManager.'"[^>]*selected/', $edit['body']));
$legacy = $hr->req('POST', '/index.php?page=hr-employee&id='.$id, ['csrf' => $csrfOf($edit['body']), 'action' => 'update_employee_employment', 'employee_id' => (string)$id, 'return_page' => 'hr-employee', 'return_id' => (string)$id, 'return_tab' => 'employment', 'work_company' => '2LOG', 'employee_no' => $noOk, 'department_id' => (string)$row['department_id'], 'position_id' => (string)$retailManager, 'employment_type_id' => '1', 'hire_date' => '2026-11-02']);
$after = $pdo->query('SELECT department_id,position_id FROM employees WHERE id='.$id)->fetch();
check('a legacy POST with department_id still saves and changes nothing', (int)$after['department_id'] === (int)$row['department_id'] && (int)$after['position_id'] === $retailManager, json_encode($after).' '.$legacy['loc']);
// ---- optional real-browser step (HRIS_BROWSER=1): same served copy, same scratch DB ----
if (getenv('HRIS_BROWSER') === '1') {
    $out = $root.'/storage/slice15-private'; @mkdir($out, 0777, true);
    $env = array_merge(getenv(), ['DP_PORT' => (string)$port, 'DP_EMAIL' => $email, 'DP_PASSWORD' => $pw, 'DP_EMPLOYEE_ID' => (string)$id, 'DP_OUT' => $out]);
    $p = proc_open(['node', $root.'/tests/department_picker_browser.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $root, $env);
    $o = stream_get_contents($pp[1]).stream_get_contents($pp[2]); $rc = proc_close($p);
    echo $o; check('browser run finished clean', $rc === 0);
}
exit($fail ? 1 : 0);
