<?php
declare(strict_types=1);

/**
 * Constraint runner (see CONSTRAINTS.md).
 *   php scripts/check.php fast   seconds: syntax + secrets on files changed in the working tree
 *   php scripts/check.php task   fast + tests that need no database
 *   php scripts/check.php full   syntax on everything + all tests (database suites only when HRIS_TEST_DB is a scratch copy)
 * Exit code 1 when any enforced check fails. Nothing here writes to a database except the scratch copy named by HRIS_TEST_DB.
 */

$root = dirname(__DIR__);
chdir($root);
$mode = $argv[1] ?? 'fast';
if (!in_array($mode, ['fast', 'task', 'full'], true)) { fwrite(STDERR, "Usage: php scripts/check.php fast|task|full\n"); exit(2); }

$failed = 0;
function report(string $label, bool $ok, string $why = ''): void { global $failed; echo ($ok ? 'PASS ' : 'FAIL ').$label.($ok || $why === '' ? '' : "\n     ".str_replace("\n", "\n     ", trim($why)))."\n"; if (!$ok) $failed++; }
function skip(string $label, string $why): void { echo 'SKIP '.$label.' ('.$why.")\n"; }
function run(string $cmd, ?array $env = null): array { $out = []; $rc = 0; exec($cmd.' 2>&1', $out, $rc); return [$rc, implode("\n", $out)]; }

/** Files in play: changed or untracked in the working tree, or everything for `full`. */
function files(string $mode): array {
    $skip = '#^(vendor|storage|\.git|HRIS-Vault|Claude outputs|public/assets/vendor)/#';
    if ($mode === 'full') {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
        $all = [];
        foreach ($it as $f) { $p = ltrim(str_replace('\\', '/', substr($f->getPathname(), 1)), '/'); if (!preg_match($skip, $p)) $all[] = $p; }
        return array_values(array_filter($all, 'is_file'));
    }
    $paths = [];
    foreach (['git diff --name-only HEAD', 'git ls-files --others --exclude-standard'] as $cmd) { [, $o] = run($cmd); foreach (explode("\n", $o) as $l) { $l = trim($l); if ($l !== '' && !str_starts_with($l, 'warning:')) $paths[$l] = true; } }
    return array_values(array_filter(array_keys($paths), fn($p) => is_file($p) && !preg_match($skip, $p)));
}

$files = files($mode);
$php = array_values(array_filter($files, fn($f) => str_ends_with($f, '.php')));
$js = array_values(array_filter($files, fn($f) => str_ends_with($f, '.js') && !str_contains($f, '/vendor/')));
echo 'Mode '.$mode.': '.count($files).' file(s) in scope ('.count($php).' PHP, '.count($js)." JS)\n";

// 1. Syntax (floor): every PHP file parses, every JS file parses.
$bad = [];
foreach ($php as $f) { [$rc, $o] = run('"'.PHP_BINARY.'" -l '.escapeshellarg($f)); if ($rc !== 0) $bad[] = $f.': '.$o; }
report('php -l on '.count($php).' file(s)', !$bad, implode("\n", $bad));
$node = trim((string)shell_exec('where node 2>NUL')) !== '' || trim((string)shell_exec('which node 2>/dev/null')) !== '';
if ($js && $node) { $bad = []; foreach ($js as $f) { [$rc, $o] = run('node --check '.escapeshellarg($f)); if ($rc !== 0) $bad[] = $f.': '.$o; } report('node --check on '.count($js).' file(s)', !$bad, implode("\n", $bad)); }
elseif ($js) skip('node --check', 'node not found');

// 2. Secrets (floor): never commit credentials. Placeholders in *.example.php are allowed. Matches are redacted in the output.
$patterns = [
    'private key' => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
    'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
    'Google/Gmail app password' => '/[\'"]password[\'"]\s*=>\s*[\'"][a-z]{4} [a-z]{4} [a-z]{4} [a-z]{4}[\'"]/',
    'literal password in config' => '/[\'"]password[\'"]\s*=>\s*[\'"](?!xxxx|your|change|\s*[\'"])[^\'"]{6,}[\'"]/i',
];
$hits = [];
foreach ($files as $f) {
    if (preg_match('/\.(png|jpe?g|webp|gif|ico|pdf|zip|woff2?|xlsx?|docx?)$/i', $f) || str_ends_with($f, '.example.php') || str_starts_with($f, 'tests/') || $f === 'scripts/check.php') continue;
    $text = (string)@file_get_contents($f);
    foreach ($patterns as $name => $re) if (preg_match($re, $text)) $hits[] = $f.' matches "'.$name.'" (value redacted)';
}
report('secrets scan on '.count($files).' file(s)', !$hits, implode("\n", $hits));

if ($mode === 'fast') { echo ($failed ? 'FAILED' : 'OK')." ($mode)\n"; exit($failed ? 1 : 0); }

// 3. Tests. Floors are the counts recorded in CONSTRAINTS.md; a suite may add checks but never lose them.
$suites = [ // script => [minimum PASS count, needs scratch DB]
    'tests/employee_profile_test.php' => [84, false],
    'tests/notification_sidebar_test.php' => [3, false],
    'tests/sidebar_fold_test.php' => [16, false],
    'tests/hr_dashboard_ui_test.php' => [20, false],
    'tests/employee_profile_db_test.php' => [14, true],
    'tests/employee_profile_http_test.php' => [55, true],
    'tests/lee_code_db_test.php' => [8, true],
    'tests/profile_content_http_test.php' => [51, true],
    'tests/ticket_lead_followup_test.php' => [30, true],
    'tests/employee_portal_account_test.php' => [100, true],
];
$db = getenv('HRIS_TEST_DB') ?: '';
$dbOk = $db !== '' && $db !== 'pmbsi_hris';
foreach ($suites as $script => [$floor, $needsDb]) {
    if ($needsDb && !$dbOk) { if ($mode === 'full') skip($script, 'set HRIS_TEST_DB to a scratch copy to run'); continue; }
    if ($mode === 'task' && $needsDb) continue;
    [$rc, $o] = run('"'.PHP_BINARY.'" '.escapeshellarg($script));
    preg_match_all('/^PASS/m', $o, $m); $pass = count($m[0]);
    // notification_sidebar_test prints one summary line ("PASS: three ... scenarios") instead of one line per check.
    if ($script === 'tests/notification_sidebar_test.php') $pass = $rc === 0 ? $floor : 0;
    report($script.' ('.$pass.' PASS, floor '.$floor.')', $rc === 0 && $pass >= $floor && !preg_match('/^FAIL/m', $o), $rc === 0 ? '' : substr($o, -600));
}

echo ($failed ? 'FAILED' : 'OK')." ($mode)\n";
exit($failed ? 1 : 0);
