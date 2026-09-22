<?php
declare(strict_types=1);

function cfg(?string $key = null, mixed $default = null): mixed {
    $cfg = $GLOBALS['config'] ?? [];
    if ($key === null) return $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($cfg) || !array_key_exists($part, $cfg)) return $default;
        $cfg = $cfg[$part];
    }
    return $cfg;
}
function db(): PDO {
    if (!($GLOBALS['pdo'] ?? null) instanceof PDO) {
        throw new RuntimeException('Database unavailable. Import database/schema.sql and check config/config.local.php.');
    }
    return $GLOBALS['pdo'];
}
function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function url(string $page = 'home', array $params = []): string {
    return 'index.php?' . http_build_query(array_merge(['page'=>$page], $params));
}
function redirect(string $page, array $params = []): never {
    header('Location: ' . url($page, $params)); exit;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">'; }
function verify_csrf(): void {
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419); exit('Invalid or expired form token. Please go back and try again.');
    }
}
function flash(string $type, string $message): void { $_SESSION['flash'][] = ['type'=>$type,'message'=>$message]; }
function pull_flashes(): array { $f=$_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return strtoupper(implode('', array_map(fn($p)=>mb_substr($p,0,1), array_slice($parts,0,2))));
}
function stage_label(string $code): string {
    return ucwords(strtolower(str_replace('_',' ', $code)));
}
function stage_badge(string $code): string {
    $map=['APPLIED'=>'gray','SCREENING'=>'blue','INTERVIEW'=>'amber','ENDORSED'=>'amber','CLIENT_REVIEW'=>'blue','OFFER'=>'green','DEPLOYMENT'=>'amber','DEPLOYED'=>'green','REJECTED'=>'red','WITHDRAWN'=>'gray','ON_HOLD'=>'gray','RETURNED'=>'amber'];
    $cls=$map[$code] ?? 'gray';
    return '<span class="badge '.$cls.'">'.e(stage_label($code)).'</span>';
}
function money(?float $min, ?float $max): string {
    if ($min === null && $max === null) return 'Salary negotiable';
    if ($min !== null && $max !== null) return '₱'.number_format($min,0).'–₱'.number_format($max,0);
    return '₱'.number_format((float)($min ?? $max),0);
}
function audit(string $module, string $action, ?string $recordType=null, ?int $recordId=null, ?array $details=null): void {
    if (!($GLOBALS['pdo'] ?? null) instanceof PDO) return;
    $uid = $_SESSION['user']['id'] ?? null;
    $stmt=db()->prepare('INSERT INTO audit_logs(user_id,module,action,record_type,record_id,details_json,ip_address,created_at) VALUES(?,?,?,?,?,?,?,NOW())');
    $stmt->execute([$uid,$module,$action,$recordType,$recordId,$details?json_encode($details,JSON_UNESCAPED_UNICODE):null,$_SERVER['REMOTE_ADDR'] ?? null]);
}
function require_post(): void { if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); } }
