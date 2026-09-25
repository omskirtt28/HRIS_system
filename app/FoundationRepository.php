<?php
declare(strict_types=1);

final class FoundationRepository
{
    public static function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) return $cache[$table];
        try {
            $st = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $st->execute([$table]);
            return $cache[$table] = ((int)$st->fetchColumn() > 0);
        } catch (Throwable) {
            return $cache[$table] = false;
        }
    }

    public static function permissionsForRole(int $roleId): array
    {
        if (!self::tableExists('permissions') || !self::tableExists('role_permissions')) return [];
        $st = db()->prepare('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? ORDER BY p.code');
        $st->execute([$roleId]);
        return array_values(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }

    public static function permissionsGrouped(): array
    {
        if (!self::tableExists('permissions')) return [];
        $rows = db()->query('SELECT * FROM permissions ORDER BY module, sort_order, name')->fetchAll();
        $out = [];
        foreach ($rows as $row) $out[$row['module']][] = $row;
        return $out;
    }

    public static function roles(): array
    {
        $permCount = self::tableExists('role_permissions')
            ? '(SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id=r.id)'
            : '0';
        $sql='SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) user_count, '.$permCount.' permission_count FROM roles r ORDER BY r.is_system DESC, r.name';
        return db()->query($sql)->fetchAll();
    }

    public static function users(): array
    {
        return db()->query('SELECT u.id,u.full_name,u.email,u.status,u.last_login_at,u.created_at,r.id role_id,r.code role_code,r.name role_name,r.portal FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.full_name')->fetchAll();
    }

    public static function departments(): array
    {
        if (!self::tableExists('positions')) {
            return db()->query('SELECT d.*, 0 position_count FROM departments d ORDER BY d.active DESC,d.name')->fetchAll();
        }
        return db()->query('SELECT d.*, (SELECT COUNT(*) FROM positions p WHERE p.department_id=d.id) position_count FROM departments d ORDER BY d.active DESC,d.name')->fetchAll();
    }

    public static function positions(): array
    {
        if (!self::tableExists('positions')) return [];
        return db()->query('SELECT p.*,d.name department_name FROM positions p LEFT JOIN departments d ON d.id=p.department_id ORDER BY p.active DESC,p.name')->fetchAll();
    }

    public static function branches(): array
    {
        return db()->query('SELECT b.*,c.name client_name FROM branches b LEFT JOIN clients c ON c.id=b.client_id ORDER BY b.active DESC,b.name')->fetchAll();
    }

    public static function employmentTypes(): array
    {
        if (!self::tableExists('employment_types')) return [];
        return db()->query('SELECT * FROM employment_types ORDER BY active DESC, sort_order, name')->fetchAll();
    }

    public static function organizationSummary(): array
    {
        $q = fn(string $sql)=>(int)db()->query($sql)->fetchColumn();
        return [
            'departments'=>$q('SELECT COUNT(*) FROM departments WHERE active=1'),
            'positions'=>self::tableExists('positions') ? $q('SELECT COUNT(*) FROM positions WHERE active=1') : 0,
            'branches'=>$q('SELECT COUNT(*) FROM branches WHERE active=1'),
            'employment_types'=>self::tableExists('employment_types') ? $q('SELECT COUNT(*) FROM employment_types WHERE active=1') : 0,
            'users'=>$q('SELECT COUNT(*) FROM users WHERE status="ACTIVE"'),
            'roles'=>$q('SELECT COUNT(*) FROM roles'),
        ];
    }

    public static function auditLogs(int $limit=100): array
    {
        $limit=max(1,min(300,$limit));
        $sql='SELECT a.*,u.full_name user_name,u.email user_email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT '.$limit;
        return db()->query($sql)->fetchAll();
    }

    public static function sessions(int $limit=100): array
    {
        if (!self::tableExists('user_sessions')) return [];
        $limit=max(1,min(300,$limit));
        return db()->query('SELECT s.*,u.full_name,u.email,r.name role_name FROM user_sessions s JOIN users u ON u.id=s.user_id JOIN roles r ON r.id=u.role_id ORDER BY s.last_activity_at DESC LIMIT '.$limit)->fetchAll();
    }

    public static function createDepartment(string $code,string $name): void
    {
        $code=strtoupper(trim($code)); $name=trim($name);
        if($code===''||$name==='') throw new RuntimeException('Department code and name are required.');
        $st=db()->prepare('INSERT INTO departments(code,name,active) VALUES(?,?,1)'); $st->execute([$code,$name]);
        audit('Organization','CREATE_DEPARTMENT','department',(int)db()->lastInsertId(),['code'=>$code,'name'=>$name]);
    }

    public static function createPosition(string $code,string $name,?int $departmentId): void
    {
        if(!self::tableExists('positions')) throw new RuntimeException('Run the Phase 1 foundation migration first.');
        $code=strtoupper(trim($code)); $name=trim($name);
        if($code===''||$name==='') throw new RuntimeException('Position code and name are required.');
        $st=db()->prepare('INSERT INTO positions(department_id,code,name,active) VALUES(?,?,?,1)'); $st->execute([$departmentId ?: null,$code,$name]);
        audit('Organization','CREATE_POSITION','position',(int)db()->lastInsertId(),['code'=>$code,'name'=>$name]);
    }

    public static function createBranch(string $code,string $name,string $address): void
    {
        $code=strtoupper(trim($code)); $name=trim($name); $address=trim($address);
        if($code===''||$name==='') throw new RuntimeException('Branch code and name are required.');
        $st=db()->prepare('INSERT INTO branches(client_id,code,name,address_text,active) VALUES(NULL,?,?,?,1)'); $st->execute([$code,$name,$address ?: null]);
        audit('Organization','CREATE_BRANCH','branch',(int)db()->lastInsertId(),['code'=>$code,'name'=>$name]);
    }

    public static function createEmploymentType(string $code,string $name): void
    {
        if(!self::tableExists('employment_types')) throw new RuntimeException('Run the Phase 1 foundation migration first.');
        $code=strtoupper(trim($code)); $name=trim($name);
        if($code===''||$name==='') throw new RuntimeException('Employment type code and name are required.');
        $st=db()->prepare('INSERT INTO employment_types(code,name,active,sort_order) VALUES(?,?,1,100)'); $st->execute([$code,$name]);
        audit('Organization','CREATE_EMPLOYMENT_TYPE','employment_type',(int)db()->lastInsertId(),['code'=>$code,'name'=>$name]);
    }

    public static function toggleMaster(string $entity,int $id): void
    {
        $map=[
            'department'=>['departments','active'],
            'position'=>['positions','active'],
            'branch'=>['branches','active'],
            'employment_type'=>['employment_types','active'],
        ];
        if(!isset($map[$entity])) throw new RuntimeException('Unsupported organization record.');
        [$table,$col]=$map[$entity];
        if(!self::tableExists($table)) throw new RuntimeException('Run the Phase 1 foundation migration first.');
        db()->prepare("UPDATE {$table} SET {$col}=IF({$col}=1,0,1) WHERE id=?")->execute([$id]);
        audit('Organization','TOGGLE_STATUS',$entity,$id);
    }

    public static function createRole(string $code,string $name,string $portal): void
    {
        $code=strtoupper(trim($code)); $name=trim($name); $portal=strtolower(trim($portal));
        if($code===''||$name==='') throw new RuntimeException('Role code and name are required.');
        if(!in_array($portal,['employee','hr','admin','client'],true)) throw new RuntimeException('Invalid portal type.');
        $st=db()->prepare('INSERT INTO roles(code,name,portal,is_system) VALUES(?,?,?,0)'); $st->execute([$code,$name,$portal]);
        audit('Access Control','CREATE_ROLE','role',(int)db()->lastInsertId(),['code'=>$code,'portal'=>$portal]);
    }

    public static function saveRolePermissions(int $roleId,array $permissionIds): void
    {
        if(!self::tableExists('role_permissions')) throw new RuntimeException('Run the Phase 1 foundation migration first.');
        $role=db()->prepare('SELECT id,code FROM roles WHERE id=?'); $role->execute([$roleId]); $r=$role->fetch();
        if(!$r) throw new RuntimeException('Role not found.');
        db()->beginTransaction();
        try {
            db()->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$roleId]);
            $ins=db()->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE id=?');
            foreach(array_unique(array_map('intval',$permissionIds)) as $pid) if($pid>0) $ins->execute([$roleId,$pid]);
            db()->commit();
        } catch(Throwable $e) { db()->rollBack(); throw $e; }
        audit('Access Control','UPDATE_ROLE_PERMISSIONS','role',$roleId,['permission_ids'=>array_values(array_unique(array_map('intval',$permissionIds)))]);
        Auth::clearPermissionCache();
    }

    public static function createUser(string $name,string $email,string $password,int $roleId): void
    {
        $name=trim($name); $email=strtolower(trim($email));
        if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid name and email address.');
        if(strlen($password)<10) throw new RuntimeException('Temporary password must be at least 10 characters.');
        $st=db()->prepare('INSERT INTO users(role_id,client_id,full_name,email,password_hash,status) VALUES(?,NULL,?,?,?,"ACTIVE")');
        $st->execute([$roleId,$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
        audit('Access Control','CREATE_USER','user',(int)db()->lastInsertId(),['email'=>$email,'role_id'=>$roleId]);
    }

    public static function toggleUserStatus(int $id): void
    {
        $me=(int)(Auth::user()['id']??0);
        if($id===$me) throw new RuntimeException('You cannot deactivate your own account.');
        db()->prepare('UPDATE users SET status=IF(status="ACTIVE","INACTIVE","ACTIVE") WHERE id=?')->execute([$id]);
        if(self::tableExists('user_sessions')) db()->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL')->execute([$id]);
        audit('Access Control','TOGGLE_USER_STATUS','user',$id);
    }

    public static function resetUserPassword(int $id,string $password): void
    {
        $me=(int)(Auth::user()['id']??0);
        if($id<=0) throw new RuntimeException('User account not found.');
        if($id===$me) throw new RuntimeException('Use a dedicated change-password flow for your own Super Admin account.');
        if(strlen($password)<10) throw new RuntimeException('Temporary password must be at least 10 characters.');
        if(!preg_match('/[A-Za-z]/',$password) || !preg_match('/[0-9]/',$password)) throw new RuntimeException('Temporary password must include at least one letter and one number.');

        $st=db()->prepare('SELECT id,email FROM users WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $user=$st->fetch();
        if(!$user) throw new RuntimeException('User account not found.');

        db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
        if(self::tableExists('user_sessions')) {
            db()->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL')->execute([$id]);
        }
        audit('Access Control','RESET_USER_PASSWORD','user',$id,['email'=>$user['email'],'sessions_revoked'=>true]);
    }
}
