<?php
declare(strict_types=1);

/**
 * Portal account that is created together with an employee (SPEC-employee-portal-account.md).
 * Login always checks users.password_hash. users.password_enc is an AES-256-GCM copy so HR can view the current password (spec D4);
 * the key lives in config.local.php (security.credential_key), never in the database. A missing or short key fails closed.
 * Callers own the transaction; nothing here ever logs or returns a plaintext password except generatePassword().
 */
final class EmployeeAccountService
{
    // shortcut: fixed two-company map, move to a table when companies are managed in the UI.
    public const DOMAINS=['PMBSI'=>'pmbsi.com','2LOG'=>'2log.com'];
    // Role choices offered on Create Employee (spec D7). Anything else is rejected server-side.
    public const ROLES=['EMPLOYEE'=>'EMPLOYEE','HR'=>'HR_USER'];
    private const ALPHABET_UPPER='ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const ALPHABET_LOWER='abcdefghijkmnopqrstuvwxyz';
    private const ALPHABET_DIGIT='23456789';

    public static function generatePassword(): string
    {
        $pick=static fn(string $set): string => $set[random_int(0,strlen($set)-1)];
        $chars=[$pick(self::ALPHABET_UPPER),$pick(self::ALPHABET_LOWER),$pick(self::ALPHABET_DIGIT)];
        $all=self::ALPHABET_UPPER.self::ALPHABET_LOWER.self::ALPHABET_DIGIT;
        while(count($chars)<12) $chars[]=$pick($all);
        for($i=count($chars)-1;$i>0;$i--){ $j=random_int(0,$i); [$chars[$i],$chars[$j]]=[$chars[$j],$chars[$i]]; }
        return implode('',$chars);
    }

    public static function localPart(string $first,string $last): string
    {
        $fold=static function(string $s): string {
            $s=strtr($s,['á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c',
                'Á'=>'a','À'=>'a','Â'=>'a','Ä'=>'a','Ã'=>'a','Å'=>'a','É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e','Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i','Ó'=>'o','Ò'=>'o','Ô'=>'o','Ö'=>'o','Õ'=>'o','Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u','Ñ'=>'n','Ç'=>'c']);
            return preg_replace('/[^a-z]/','',strtolower($s)) ?? '';
        };
        $last=preg_replace('/[\s,]+(jr|sr|ii|iii|iv)\.?$/i','',trim($last)) ?? $last;
        $local=$fold($first).$fold($last);
        if($local==='') throw new RuntimeException('First and last name must contain letters to build the account email.');
        return $local;
    }

    public static function domainFor(?string $workCompany): string
    {
        $domain=self::DOMAINS[strtoupper(trim((string)$workCompany))] ?? null;
        if($domain===null) throw new RuntimeException('Choose a Company that has an email domain.');
        return $domain;
    }

    /** First free address: name@domain, then name2@domain, name3@domain... (users.email and employees.company_email). */
    public static function uniqueEmail(string $first,string $last,?string $workCompany,?int $exceptEmployeeId=null): string
    {
        $local=self::localPart($first,$last); $domain=self::domainFor($workCompany);
        $u=db()->prepare('SELECT 1 FROM users WHERE email=?');
        $e=db()->prepare('SELECT 1 FROM employees WHERE company_email=? AND id<>?');
        for($n=1;$n<=999;$n++){
            $email=$local.($n>1?(string)$n:'').'@'.$domain;
            $u->execute([$email]); $e->execute([$email,(int)$exceptEmployeeId]);
            if(!$u->fetchColumn() && !$e->fetchColumn()) return $email;
        }
        throw new RuntimeException('No free account email is available for this name.');
    }

    private static function key(): string
    {
        $raw=base64_decode((string)cfg('security.credential_key',''),true);
        if($raw===false || strlen($raw)!==32) throw new RuntimeException('Account password storage is not configured (security.credential_key in config.local.php).');
        return $raw;
    }

    public static function encrypt(string $plain): string
    {
        $key=self::key(); $iv=random_bytes(12); $tag='';
        $ct=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'',16);
        if($ct===false || strlen($tag)!==16) throw new RuntimeException('Could not protect the password.');
        return 'v1:'.base64_encode($iv.$tag.$ct);
    }

    /** Null when there is no copy, the key is missing or wrong, or the value was altered. */
    public static function decrypt(?string $stored): ?string
    {
        if($stored===null || !str_starts_with($stored,'v1:')) return null;
        $raw=base64_decode(substr($stored,3),true);
        if($raw===false || strlen($raw)<29) return null;
        try { $key=self::key(); } catch(RuntimeException) { return null; }
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
        return $plain===false ? null : $plain;
    }

    /** Single place that changes a password: hash for login, encrypted copy for HR view. */
    public static function setPassword(int $userId,string $plain,bool $mustChange): void
    {
        $st=db()->prepare('UPDATE users SET password_hash=?,password_enc=?,must_change_password=?,password_changed_at=NOW() WHERE id=?');
        $st->execute([password_hash($plain,PASSWORD_DEFAULT),self::encrypt($plain),$mustChange?1:0,$userId]);
    }

    /**
     * HR/Super Admin view of an account's current password (spec D4/D11). Needs users.view_credentials, max 10 per minute per viewer,
     * every call audited with who/whose/when and never the value. password is null (with a message) when no copy exists.
     */
    public static function reveal(int $targetUserId): array
    {
        $actor=(int)(Auth::user()['id'] ?? 0);
        if($actor<=0 || !Auth::can('users.view_credentials')) throw new RuntimeException('You do not have permission to view account passwords.');
        $n=db()->prepare('SELECT COUNT(*) FROM audit_logs WHERE action="REVEAL_PASSWORD" AND user_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 MINUTE)');
        $n->execute([$actor]);
        if((int)$n->fetchColumn()>=10) throw new RuntimeException('Too many password views. Wait a minute and try again.');
        $st=db()->prepare('SELECT password_enc FROM users WHERE id=?'); $st->execute([$targetUserId]);
        $row=$st->fetch();
        if(!$row) return ['password'=>null,'message'=>'Account not found.'];
        audit('Access Control','REVEAL_PASSWORD','user',$targetUserId,['viewer_user_id'=>$actor,'has_copy'=>$row['password_enc']!==null]);
        $plain=self::decrypt($row['password_enc']);
        return $plain!==null ? ['password'=>$plain,'message'=>null]
            : ['password'=>null,'message'=>'This password cannot be shown (the account predates password viewing, or the key changed). Reset the password to enable viewing.'];
    }

    /** True while the account still has to choose its own password. A missing column (migration 020 not applied) counts as false. */
    public static function mustChange(int $userId): bool
    {
        try { $st=db()->prepare('SELECT must_change_password FROM users WHERE id=?'); $st->execute([$userId]); return (int)$st->fetchColumn()===1; }
        catch(PDOException) { return false; }
    }

    /** Own-password change: needs the current password, enforces the policy, locks after 5 misses in 15 minutes, signs out other devices. */
    public static function changeOwnPassword(int $userId,string $current,string $new,string $confirm,string $keepTokenHash): void
    {
        $miss=db()->prepare('SELECT COUNT(*) FROM audit_logs WHERE action="CHANGE_PASSWORD_FAIL" AND record_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)');
        $miss->execute([$userId]);
        if((int)$miss->fetchColumn()>=5) throw new RuntimeException('Too many attempts. Try again in 15 minutes or ask HR to reset your password.');
        $st=db()->prepare('SELECT password_hash FROM users WHERE id=?'); $st->execute([$userId]);
        $hash=(string)$st->fetchColumn();
        if($hash==='' || !password_verify($current,$hash)) { audit('Auth','CHANGE_PASSWORD_FAIL','user',$userId); throw new RuntimeException('Your current password is not correct.'); }
        if(strlen($new)<10) throw new RuntimeException('New password must be at least 10 characters.');
        if(!preg_match('/[A-Za-z]/',$new) || !preg_match('/[0-9]/',$new)) throw new RuntimeException('New password must include at least one letter and one number.');
        if($new!==$confirm) throw new RuntimeException('The new password and its confirmation do not match.');
        if(hash_equals($current,$new)) throw new RuntimeException('Choose a password different from your current one.');
        self::setPassword($userId,$new,false);
        if(FoundationRepository::tableExists('user_sessions')) db()->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND session_token_hash<>? AND revoked_at IS NULL')->execute([$userId,$keepTokenHash]);
        audit('Auth','CHANGE_OWN_PASSWORD','user',$userId,['other_sessions_revoked'=>true]);
    }

    /** Employee + login in ONE transaction: if either fails, neither is saved. The posted user_id/company_email are ignored (spec D1, D2). */
    public static function createEmployee(array $data,string $roleKey): array
    {
        $roleKey=strtoupper(trim($roleKey));
        if(!isset(self::ROLES[$roleKey])) throw new RuntimeException('Choose HR or Employee as the account role.');
        self::key(); // fail closed before anything is written
        $data['user_id']=0; $data['company_email']='';
        $pdo=db(); $own=!$pdo->inTransaction(); if($own) $pdo->beginTransaction();
        try {
            $id=EmployeeRepository::create($data);
            $account=self::issue($id,(string)($data['first_name']??''),(string)($data['last_name']??''),(string)($data['work_company']??''),$roleKey);
            if($own) $pdo->commit();
            return ['employee_id'=>$id]+$account;
        } catch(Throwable $e) { if($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Creates the linked login for an employee row. Run inside the caller's transaction. Returns [user_id,email]. */
    public static function issue(int $employeeId,string $first,string $last,?string $workCompany,string $roleKey): array
    {
        $roleCode=self::ROLES[strtoupper(trim($roleKey))] ?? throw new RuntimeException('Choose HR or Employee as the account role.');
        $role=db()->prepare('SELECT id FROM roles WHERE code=?'); $role->execute([$roleCode]);
        $roleId=(int)$role->fetchColumn(); if($roleId<=0) throw new RuntimeException('The account role does not exist.');
        $email=self::uniqueEmail($first,$last,$workCompany,$employeeId);
        $plain=self::generatePassword();
        $st=db()->prepare('INSERT INTO users(role_id,client_id,full_name,email,password_hash,password_enc,must_change_password,status) VALUES(?,NULL,?,?,?,?,1,"ACTIVE")');
        $st->execute([$roleId,trim($first.' '.$last),$email,password_hash($plain,PASSWORD_DEFAULT),self::encrypt($plain)]);
        $userId=(int)db()->lastInsertId();
        db()->prepare('UPDATE employees SET user_id=?,company_email=? WHERE id=?')->execute([$userId,$email,$employeeId]);
        audit('Access Control','AUTO_CREATE_USER','user',$userId,['employee_id'=>$employeeId,'email'=>$email,'role'=>$roleCode]);
        return ['user_id'=>$userId,'email'=>$email];
    }
}
