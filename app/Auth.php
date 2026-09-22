<?php
declare(strict_types=1);

final class Auth
{
    public static function user(): ?array { return $_SESSION['user'] ?? null; }
    public static function check(): bool { return !empty($_SESSION['user']); }
    public static function login(string $email, string $password, ?string $portal = null): bool
    {
        $st=db()->prepare('SELECT u.*, r.code role_code, r.name role_name, c.id client_id, c.name client_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN clients c ON c.id=u.client_id WHERE LOWER(u.email)=LOWER(?) AND u.status="ACTIVE" LIMIT 1');
        $st->execute([trim($email)]); $u=$st->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) {
            audit('Auth','LOGIN_FAIL','user', $u['id'] ?? null, ['email'=>$email]); return false;
        }
        $portalRoles=[
            'hr'=>['RECRUITMENT_MANAGER','RECRUITER','COORDINATOR','SUPER_ADMIN'],
            'client'=>['CLIENT_USER','SUPER_ADMIN'],
            'admin'=>['SUPER_ADMIN','HRIS_ADMIN'],
        ];
        if ($portal && !in_array($u['role_code'], $portalRoles[$portal] ?? [], true)) return false;
        session_regenerate_id(true);
        $_SESSION['user']=[
            'id'=>(int)$u['id'],'name'=>$u['full_name'],'email'=>$u['email'],'role_code'=>$u['role_code'],'role_name'=>$u['role_name'],
            'client_id'=>$u['client_id'] ? (int)$u['client_id'] : null,'client_name'=>$u['client_name'] ?? null
        ];
        db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$u['id']]);
        audit('Auth','LOGIN','user',(int)$u['id']); return true;
    }
    public static function logout(): void {
        if (self::check()) audit('Auth','LOGOUT','user',(int)$_SESSION['user']['id']);
        $_SESSION=[]; if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); }
        session_destroy();
    }
    public static function requireRoles(array $roles): void {
        if (!self::check()) redirect('login',['portal'=>'hr']);
        if (!in_array($_SESSION['user']['role_code'],$roles,true)) { http_response_code(403); include dirname(__DIR__).'/public/403.php'; exit; }
    }
    public static function is(string ...$roles): bool { return self::check() && in_array($_SESSION['user']['role_code'],$roles,true); }
}
