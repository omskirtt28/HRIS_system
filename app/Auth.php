<?php
declare(strict_types=1);

final class Auth
{
    private static ?array $permissionCache = null;
    private static bool $sessionValidated = false;

    public static function user(): ?array { return $_SESSION['user'] ?? null; }

    public static function check(): bool
    {
        if (empty($_SESSION['user'])) return false;
        if (!self::$sessionValidated) {
            self::$sessionValidated = true;
            if (!self::validateTrackedSession()) {
                $_SESSION=[];
                return false;
            }
        }
        return true;
    }

    public static function login(string $email, string $password, ?string $portal = null): bool
    {
        $st=db()->prepare('SELECT u.*, r.code role_code, r.name role_name, r.portal role_portal, c.id client_id, c.name client_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN clients c ON c.id=u.client_id WHERE LOWER(u.email)=LOWER(?) AND u.status="ACTIVE" LIMIT 1');
        $st->execute([trim($email)]); $u=$st->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) {
            audit('Auth','LOGIN_FAIL','user', $u['id'] ?? null, ['email'=>$email]);
            return false;
        }

        $portal = $portal !== null ? strtolower(trim($portal)) : null;
        if ($portal !== null && $portal !== '') {
            $allowed = ($u['role_code']==='SUPER_ADMIN') || ($u['role_portal']===$portal) || ($portal==='hr' && $u['role_portal']==='admin');
            if (!$allowed) {
                audit('Auth','PORTAL_DENIED','user',(int)$u['id'],['portal'=>$portal,'role_portal'=>$u['role_portal']]);
                return false;
            }
        }

        session_regenerate_id(true);
        self::$sessionValidated = false;
        self::$permissionCache = null;
        $_SESSION['user']=[
            'id'=>(int)$u['id'], 'role_id'=>(int)$u['role_id'], 'name'=>$u['full_name'], 'email'=>$u['email'],
            'role_code'=>$u['role_code'], 'role_name'=>$u['role_name'], 'role_portal'=>$u['role_portal'],
            'client_id'=>$u['client_id'] ? (int)$u['client_id'] : null, 'client_name'=>$u['client_name'] ?? null,
        ];
        db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$u['id']]);
        self::startTrackedSession((int)$u['id']);
        audit('Auth','LOGIN','user',(int)$u['id'],['portal'=>$u['role_portal']]);
        return true;
    }

    public static function landingPage(): string
    {
        $role = (string)(self::user()['role_code'] ?? '');
        $portal = (string)(self::user()['role_portal'] ?? '');
        if (in_array($role,['SUPER_ADMIN','HRIS_ADMIN'],true) || $portal==='admin') return 'admin-dashboard';
        return match ($portal) {
            'employee' => 'employee-dashboard',
            'hr' => 'hr-dashboard',
            'client' => 'client-dashboard',
            default => 'login',
        };
    }

    public static function logout(): void
    {
        if (!empty($_SESSION['user'])) {
            self::revokeTrackedSession();
            audit('Auth','LOGOUT','user',(int)$_SESSION['user']['id']);
        }
        $_SESSION=[];
        if (ini_get('session.use_cookies')) {
            $p=session_get_cookie_params();
            setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
        }
        session_destroy();
        self::$permissionCache=null;
        self::$sessionValidated=false;
    }

    public static function requireRoles(array $roles): void
    {
        if (!self::check()) redirect('login');
        if (!in_array((string)$_SESSION['user']['role_code'],$roles,true)) self::deny();
    }

    public static function requirePermission(string $permission): void
    {
        if (!self::check()) redirect('login');
        if (!self::can($permission)) self::deny();
    }

    public static function requirePortal(string $portal): void
    {
        if (!self::check()) redirect('login',['portal'=>$portal]);
        $role=(string)(self::user()['role_code']??'');
        $own=(string)(self::user()['role_portal']??'');
        if ($own==='') {
            $own = match(true) {
                in_array($role,['SUPER_ADMIN','HRIS_ADMIN'],true) => 'admin',
                in_array($role,['HR_USER','RECRUITMENT_MANAGER','RECRUITER','COORDINATOR'],true) => 'hr',
                $role==='CLIENT_USER' => 'client',
                $role==='EMPLOYEE' => 'employee',
                default => '',
            };
        }
        if ($role==='SUPER_ADMIN') return;
        if ($own==='admin' && $portal==='hr') return;
        if ($own!==$portal) self::deny();
    }

    public static function can(string $permission): bool
    {
        if (!self::check()) return false;
        $role=(string)(self::user()['role_code']??'');
        if ($role==='SUPER_ADMIN') return true;
        $perms=self::permissionCodes();
        if ($perms !== []) return in_array($permission,$perms,true);

        // Backward-compatible access before the Phase 1 migration is imported.
        if ($role==='HRIS_ADMIN') return true;
        if ($role==='EMPLOYEE') return str_starts_with($permission,'dashboard.employee') || str_ends_with($permission,'.view_self') || str_ends_with($permission,'.request_self');
        if (in_array($role,['HR_USER','RECRUITMENT_MANAGER','RECRUITER','COORDINATOR'],true)) {
            return str_starts_with($permission,'dashboard.hr') || str_starts_with($permission,'recruitment.') || $permission==='reports.view';
        }
        return false;
    }

    public static function is(string ...$roles): bool
    {
        return self::check() && in_array((string)$_SESSION['user']['role_code'],$roles,true);
    }

    public static function permissionCodes(): array
    {
        if (self::$permissionCache !== null) return self::$permissionCache;
        $roleId=(int)(self::user()['role_id']??0);
        if ($roleId<=0 || !FoundationRepository::tableExists('permissions') || !FoundationRepository::tableExists('role_permissions')) return self::$permissionCache=[];
        return self::$permissionCache=FoundationRepository::permissionsForRole($roleId);
    }

    public static function clearPermissionCache(): void { self::$permissionCache=null; }

    private static function deny(): never
    {
        http_response_code(403);
        include dirname(__DIR__).'/public/403.php';
        exit;
    }

    private static function startTrackedSession(int $userId): void
    {
        if (!FoundationRepository::tableExists('user_sessions')) return;
        $raw=bin2hex(random_bytes(32));
        $_SESSION['auth_session_token']=$raw;
        $hash=hash('sha256',$raw);
        $st=db()->prepare('INSERT INTO user_sessions(user_id,session_token_hash,ip_address,user_agent,last_activity_at,created_at) VALUES(?,?,?,?,NOW(),NOW())');
        $st->execute([$userId,$hash,$_SERVER['REMOTE_ADDR']??null,mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255)]);
    }

    private static function validateTrackedSession(): bool
    {
        if (!FoundationRepository::tableExists('user_sessions')) return true;
        $raw=(string)($_SESSION['auth_session_token']??'');
        if ($raw==='') return true; // Supports sessions created before the migration was applied.
        $hash=hash('sha256',$raw);
        $st=db()->prepare('SELECT id FROM user_sessions WHERE user_id=? AND session_token_hash=? AND revoked_at IS NULL LIMIT 1');
        $st->execute([(int)($_SESSION['user']['id']??0),$hash]);
        $id=(int)($st->fetchColumn()?:0);
        if ($id<=0) return false;
        db()->prepare('UPDATE user_sessions SET last_activity_at=NOW() WHERE id=?')->execute([$id]);
        return true;
    }

    private static function revokeTrackedSession(): void
    {
        if (!FoundationRepository::tableExists('user_sessions')) return;
        $raw=(string)($_SESSION['auth_session_token']??'');
        if ($raw==='') return;
        db()->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND session_token_hash=? AND revoked_at IS NULL')
            ->execute([(int)($_SESSION['user']['id']??0),hash('sha256',$raw)]);
    }
}
