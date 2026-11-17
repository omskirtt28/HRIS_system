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
/** White-label brand settings (config brand.*); the defaults are a neutral demo identity. */
function brand(string $key): string {
    static $d = ['product'=>'HRIS','company'=>'Demo Company','tagline'=>'Human Resources Information System','logo'=>'','color'=>'#2563eb','color_strong'=>'#1d4ed8'];
    return (string)cfg('brand.'.$key, $d[$key] ?? '');
}
/** Logo lockup: the configured image (brand.logo), otherwise the built-in mark plus the product name as live text. */
function brand_lockup(bool $word = true): string {
    $logo = brand('logo');
    if ($logo !== '') return '<img src="'.e($logo).'" alt="'.e(brand('company')).'" class="brand-full-logo">';
    return '<span class="brand-lockup"><svg class="brand-glyph" viewBox="0 0 32 32" aria-hidden="true" focusable="false"><rect width="32" height="32" rx="8" fill="currentColor"/><path d="M10.5 9.5v13M21.5 9.5v13M10.5 17.5h11" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/><circle cx="16" cy="12.6" r="2.1" fill="#fff"/></svg>'.($word ? '<b>'.e(brand('product')).'</b>' : '').'</span>';
}
/** Inline override of the CSS brand tokens; only valid #rrggbb values from config are emitted. */
function brand_style(): string {
    $c = brand('color'); $s = brand('color_strong');
    if (!preg_match('/^#[0-9a-f]{6}$/i', $c) || !preg_match('/^#[0-9a-f]{6}$/i', $s)) return '';
    return '<style>:root{--hris-brand:'.$c.';--hris-brand-strong:'.$s.'}</style>';
}
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
    if($code==='FOR_HR_TIMEKEEPING_APPROVAL') return 'For HR Review';
    if($code==='HR_TIMEKEEPING') return 'HR Review';
    if($code==='RETURNED_FOR_REVISION') return 'For Changes';
    if($code==='CLIENT_REVIEW') return 'Hiring Review'; // label only (SPEC-rec-endorse-manager Q4); the stage code is unchanged
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
/** Public salary text: a range only when both ends are set, otherwise Salary negotiable (Q-J5). */
function job_salary(array $j): string {
    $both=$j['salary_min']!==null&&$j['salary_max']!==null;
    return money($both?(float)$j['salary_min']:null,$both?(float)$j['salary_max']:null);
}
function audit(string $module, string $action, ?string $recordType=null, ?int $recordId=null, ?array $details=null): void {
    if (!($GLOBALS['pdo'] ?? null) instanceof PDO) return;
    $uid = $_SESSION['user']['id'] ?? null;
    $stmt=db()->prepare('INSERT INTO audit_logs(user_id,module,action,record_type,record_id,details_json,ip_address,created_at) VALUES(?,?,?,?,?,?,?,NOW())');
    $stmt->execute([$uid,$module,$action,$recordType,$recordId,$details?json_encode($details,JSON_UNESCAPED_UNICODE):null,$_SERVER['REMOTE_ADDR'] ?? null]);
}
function require_post(): void { if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); } }

/** Screening rating badge: Negative red, Fair amber, Outstanding green; a dash when not rated yet. */
function rating_badge(?string $rating): string {
    $label=RecruitmentRepository::RATINGS[$rating??'']??null;
    return $label===null?'<span class="badge gray">—</span>':'<span class="badge '.['NEGATIVE'=>'red','FAIR'=>'amber','OUTSTANDING'=>'green'][$rating].'">'.e($label).'</span>';
}

/** Prev / Next pager for server-side lists. $params are the active filters; the page number goes in `p`. Empty when there is only one page. */
function pager_html(int $total,int $page,int $per,string $route,array $params=[]): string {
    $pages=max(1,(int)ceil($total/$per)); if($pages<2)return '';
    $from=($page-1)*$per+1; $to=min($total,$page*$per);
    $link=fn(int $p,string $label,string $aria)=>'<a class="btn sm" href="'.e(url($route,$params+['p'=>$p])).'" aria-label="'.e($aria).'">'.$label.'</a>';
    return '<nav class="pager" aria-label="Pagination"><span class="tiny muted" role="status">Showing '.$from.'-'.$to.' of '.$total.'</span><span class="pager-btns">'
        .($page>1?$link($page-1,'‹ Previous','Previous page'):'<span class="btn sm" aria-disabled="true">‹ Previous</span>')
        .'<span class="tiny muted">Page '.$page.' of '.$pages.'</span>'
        .($page<$pages?$link($page+1,'Next ›','Next page'):'<span class="btn sm" aria-disabled="true">Next ›</span>').'</span></nav>';
}
