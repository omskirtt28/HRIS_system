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

    public static function columnExists(string $table,string $column): bool
    {
        static $cache = [];
        $key=$table.'.'.$column;
        if (array_key_exists($key,$cache)) return $cache[$key];
        try {
            $st=db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $st->execute([$table,$column]);
            return $cache[$key]=((int)$st->fetchColumn()>0);
        } catch(Throwable) {
            return $cache[$key]=false;
        }
    }

    public static function businessUnits(): array
    {
        if(!self::tableExists('business_units')) return [];
        return db()->query('SELECT * FROM business_units ORDER BY active DESC,sort_order,name')->fetchAll();
    }

    public static function legalEntities(): array
    {
        if(!self::tableExists('legal_entities')) return [];
        return db()->query('SELECT * FROM legal_entities ORDER BY active DESC,name')->fetchAll();
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
        return db()->query('SELECT u.id,u.full_name,u.email,u.status,u.last_login_at,u.created_at,r.id role_id,r.code role_code,r.name role_name,r.portal,e.id employee_id,e.employee_no,TRIM(CONCAT(e.first_name," ",e.last_name)) employee_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN employees e ON e.user_id=u.id ORDER BY u.full_name')->fetchAll();
    }

    public static function departments(): array
    {
        if (!self::tableExists('positions')) {
            return db()->query('SELECT d.*, 0 position_count FROM departments d ORDER BY d.active DESC,d.name')->fetchAll();
        }
        return db()->query('SELECT d.*, (SELECT COUNT(*) FROM positions p WHERE p.department_id=d.id AND p.active=1) position_count FROM departments d ORDER BY d.active DESC,d.name')->fetchAll();
    }

    public static function positions(): array
    {
        if (!self::tableExists('positions')) return [];
        return db()->query('SELECT p.*,d.name department_name FROM positions p LEFT JOIN departments d ON d.id=p.department_id ORDER BY p.active DESC,p.name')->fetchAll();
    }

    public static function areas(): array
    {
        if(!self::tableExists('areas')) return [];
        return db()->query('SELECT * FROM areas ORDER BY active DESC,name')->fetchAll();
    }

    public static function branches(): array
    {
        $hasAreas=self::tableExists('areas') && self::columnExists('branches','area_id');
        $hasOrg=self::tableExists('business_units') && self::tableExists('legal_entities')
            && self::columnExists('branches','default_business_unit_id')
            && self::columnExists('branches','default_legal_entity_id');
        $areaJoin=$hasAreas?' LEFT JOIN areas a ON a.id=b.area_id ':'';
        $orgJoin=$hasOrg?' LEFT JOIN business_units bu ON bu.id=b.default_business_unit_id LEFT JOIN legal_entities le ON le.id=b.default_legal_entity_id ':'';
        $select='SELECT b.*,c.name client_name'
            .($hasAreas?',a.name area_name,a.code area_code':',NULL area_name,NULL area_code')
            .($hasOrg?',bu.name business_unit_name,bu.code business_unit_code,bu.company_group business_unit_group,le.name legal_entity_name,le.code legal_entity_code':',NULL business_unit_name,NULL business_unit_code,NULL business_unit_group,NULL legal_entity_name,NULL legal_entity_code')
            .' FROM branches b LEFT JOIN clients c ON c.id=b.client_id '.$areaJoin.$orgJoin
            .' ORDER BY b.active DESC,b.name,COALESCE(b.code,"")';
        return db()->query($select)->fetchAll();
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
            'business_units'=>self::tableExists('business_units') ? $q('SELECT COUNT(*) FROM business_units WHERE active=1') : 0,
            'legal_entities'=>self::tableExists('legal_entities') ? $q('SELECT COUNT(*) FROM legal_entities WHERE active=1') : 0,
            'departments'=>$q('SELECT COUNT(*) FROM departments WHERE active=1'),
            'positions'=>self::tableExists('positions') ? $q('SELECT COUNT(*) FROM positions WHERE active=1') : 0,
            'branches'=>$q('SELECT COUNT(*) FROM branches WHERE active=1'),
            'areas'=>self::tableExists('areas') ? $q('SELECT COUNT(*) FROM areas WHERE active=1') : 0,
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

    /** Company Group (Company Name filter): code => label. */
    public static function companyGroups(): array { return ['SW'=>'Sw','OC'=>"Ocampo's",'UF'=>'Urban Farm']; }

    /**
     * Company Name per Company Group for each branch: [branch_id => [group => business_unit_id]].
     * A mall can hold a Sw store and an Ocampo's store (counterparts checked against the company directory addresses);
     * Head Office uses 2L.O.G., INC. (Sw), Ocampo's (Ocampo's) and Urban Farm.
     */
    public static function branchCompanyMap(): array
    {
        static $map=null; if($map!==null) return $map;
        $map=[]; if(!self::tableExists('business_units')||!self::columnExists('branches','default_business_unit_id')||!self::columnExists('business_units','company_group')) return $map;
        $pairs=['ASIA'=>'OMOA','GALE'=>'OGAL','MEGA'=>'OMEG','FAR3'=>'OFAR','ERMI'=>'ORMI']; $pairs+=array_flip($pairs);
        $rows=db()->query('SELECT b.id,b.code,b.site_type,b.default_business_unit_id company_id,bu.company_group FROM branches b LEFT JOIN business_units bu ON bu.id=b.default_business_unit_id')->fetchAll();
        $byCode=[]; foreach($rows as $r) if($r['code']!==null) $byCode[$r['code']]=$r;
        $ho=[]; foreach(db()->query('SELECT id,code,company_group FROM business_units WHERE code IN ("2LOG","OCAMPOS","URBAN_FARM")')->fetchAll() as $c) $ho[$c['company_group']]=(int)$c['id'];
        foreach($rows as $r) {
            $id=(int)$r['id'];
            if($r['site_type']==='HEAD_OFFICE') { $map[$id]=$ho; continue; }
            if($r['company_id'] && $r['company_group']) $map[$id][$r['company_group']]=(int)$r['company_id'];
            $other=$byCode[$pairs[$r['code']??''] ?? ''] ?? null;
            if($other && $other['company_id'] && $other['company_group'] && empty($map[$id][$other['company_group']])) $map[$id][$other['company_group']]=(int)$other['company_id'];
        }
        return $map;
    }

    /** Employer is always PMBSI. */
    public static function pmbsiEntityId(): ?int
    {
        if(!self::tableExists('legal_entities')) return null;
        $id=db()->query('SELECT id FROM legal_entities WHERE code="PMBSI"')->fetchColumn();
        return $id===false?null:(int)$id;
    }

    /** Code is an internal key: use the typed one, otherwise derive it from the name and keep it unique. */
    private static function uniqueCode(string $table,string $label,string $name,string $given=''): string
    {
        $name=trim($name);
        if($name==='') throw new RuntimeException($label.' name is required.');
        $st=db()->prepare("SELECT COUNT(*) FROM {$table} WHERE LOWER(name)=LOWER(?)");$st->execute([$name]);
        if((int)$st->fetchColumn()>0) throw new RuntimeException($label." '".$name."' already exists.");
        $code=strtoupper(trim($given));
        if($code==='') {
            $code=trim((string)preg_replace('/[^A-Z0-9]+/','_',strtoupper($name)),'_');
            $code=substr($code!==''?$code:'ITEM',0,30);
        }
        $base=$code;$n=1;
        $chk=db()->prepare("SELECT COUNT(*) FROM {$table} WHERE code=?");
        while(true) {
            $chk->execute([$code]);
            if((int)$chk->fetchColumn()===0) return $code;
            $n++;$code=substr($base,0,27).'_'.$n;
        }
    }

    public static function createBusinessUnit(string $code,string $name,string $group=''): void
    {
        if(!self::tableExists('business_units')) throw new RuntimeException('Import the Organization Master migration first.');
        $name=trim($name);$group=strtoupper(trim($group));
        $code=self::uniqueCode('business_units','Company Name',$name,$code);
        if(!isset(self::companyGroups()[$group])) throw new RuntimeException('Choose the Company Group for this Company Name.');
        $st=db()->prepare('INSERT INTO business_units(code,name,company_group,active,sort_order) VALUES(?,?,?,1,300)');
        $st->execute([$code,$name,$group]);
        audit('Organization','CREATE_BUSINESS_UNIT','business_unit',(int)db()->lastInsertId(),['code'=>$code,'name'=>$name,'company_group'=>$group]);
    }

    public static function createLegalEntity(string $code,string $name): void
    {
        if(!self::tableExists('legal_entities')) throw new RuntimeException('Import the Organization Master migration first.');
        $name=trim($name);
        $code=self::uniqueCode('legal_entities','Company / legal entity',$name,$code);
        $st=db()->prepare('INSERT INTO legal_entities(code,name,active) VALUES(?,?,1)');
        $st->execute([$code,$name]);
        audit('Organization','CREATE_LEGAL_ENTITY','legal_entity',(int)db()->lastInsertId(),['code'=>$code,'name'=>$name]);
    }

    public static function createDepartment(string $code,string $name,string $company='2LOG'): void
    {
        $name=trim($name);$company=strtoupper(trim($company));
        if(!in_array($company,['2LOG','PMBSI'],true)) throw new RuntimeException('Choose a valid department company.');
        $hasCompany=self::columnExists('departments','work_company');
        if(!$hasCompany && $company==='PMBSI') throw new RuntimeException('The department company migration is required.');
        $code=self::uniqueCode('departments','Department',$name,$code);
        $st=db()->prepare($hasCompany?'INSERT INTO departments(code,name,work_company,active) VALUES(?,?,?,1)':'INSERT INTO departments(code,name,active) VALUES(?,?,1)');
        $st->execute($hasCompany?[$code,$name,$company]:[$code,$name]);
        audit('Organization','CREATE_DEPARTMENT','department',(int)db()->lastInsertId(),['code'=>$code,'name'=>$name,'work_company'=>$company]);
    }

    public static function createPosition(string $code,string $name,?int $departmentId,string $company): void
    {
        if(!self::tableExists('positions')) throw new RuntimeException('Run the Phase 1 foundation migration first.');
        $name=trim($name);$company=strtoupper(trim($company));
        if(!in_array($company,['2LOG','PMBSI'],true)) throw new RuntimeException('Choose a valid position company.');
        if(!EmployeeRepository::positionAllowedForWorkCompany($name,$company)) throw new RuntimeException($company==='PMBSI'?'This position name is not eligible for PMBSI. Use an allowed Human Resource name.':'This position name is not eligible for 2LOG.');
        if($departmentId) {
            $hasCompany=self::columnExists('departments','work_company');
            if(!$hasCompany && $company==='PMBSI') throw new RuntimeException('The department company migration is required.');
            $st=db()->prepare($hasCompany?'SELECT active,work_company FROM departments WHERE id=?':"SELECT active,'2LOG' work_company FROM departments WHERE id=?");
            $st->execute([$departmentId]);$department=$st->fetch();
            if(!$department || !(int)$department['active'] || $department['work_company']!==$company) throw new RuntimeException('Choose an active department for '.$company.'.');
        }
        $code=self::uniqueCode('positions','Position',$name,$code);
        $st=db()->prepare('INSERT INTO positions(department_id,code,name,active) VALUES(?,?,?,1)'); $st->execute([$departmentId ?: null,$code,$name]);
        audit('Organization','CREATE_POSITION','position',(int)db()->lastInsertId(),['code'=>$code,'name'=>$name]);
    }

    public static function updatePositionDepartment(int $id,?int $departmentId,string $company): void
    {
        if(!in_array($company,['2LOG','PMBSI'],true) || $id<=0 || $departmentId<0) throw new RuntimeException('Choose a valid position and company.');
        $st=db()->prepare('SELECT name,department_id FROM positions WHERE id=?'); $st->execute([$id]); $position=$st->fetch();
        if(!$position || !EmployeeRepository::positionAllowedForWorkCompany((string)$position['name'],$company)) throw new RuntimeException('Position is not eligible for this company.');
        if($departmentId) {
            $hasCompany=self::columnExists('departments','work_company');
            if(!$hasCompany && $company==='PMBSI') throw new RuntimeException('The department company migration is required.');
            $st=db()->prepare($hasCompany?'SELECT active,work_company FROM departments WHERE id=?':"SELECT active,'2LOG' work_company FROM departments WHERE id=?");
            $st->execute([$departmentId]); $department=$st->fetch();
            if(!$department || !(int)$department['active'] || $department['work_company']!==$company) throw new RuntimeException('Choose an active department for '.$company.'.');
        }
        $st=db()->prepare('UPDATE positions SET department_id=? WHERE id=?'); $st->execute([$departmentId ?: null,$id]);
        audit('Organization','UPDATE_POSITION_DEPARTMENT','position',$id,['from_department_id'=>$position['department_id'],'to_department_id'=>$departmentId ?: null]);
    }

    public static function createArea(string $code,string $name): void
    {
        if(!self::tableExists('areas')) throw new RuntimeException('Import the Phase 3A migration first.');
        $name=trim($name);
        $code=self::uniqueCode('areas','Area',$name,$code);
        $st=db()->prepare('INSERT INTO areas(code,name,active) VALUES(?,?,1)');$st->execute([$code,$name]);
        audit('Organization','CREATE_AREA','area',(int)db()->lastInsertId(),['code'=>$code,'name'=>$name]);
    }

    public static function createBranch(string $code,string $name,string $address,?int $areaId=null,?int $businessUnitId=null,?int $legalEntityId=null,string $siteType='RETAIL_STORE'): void
    {
        $code=strtoupper(trim($code));$name=trim($name);$address=trim($address);$siteType=strtoupper(trim($siteType));
        if($name==='') throw new RuntimeException('Branch / site name is required.');
        if(!in_array($siteType,['RETAIL_STORE','HEAD_OFFICE','OTHER'],true)) throw new RuntimeException('Invalid site type.');
        if($code!=='' && self::columnExists('branches','code')) {
            $st=db()->prepare('SELECT COUNT(*) FROM branches WHERE code=?');$st->execute([$code]);
            if((int)$st->fetchColumn()>0) throw new RuntimeException('Branch code already exists.');
        }
        if(self::columnExists('branches','default_business_unit_id') && self::columnExists('branches','default_legal_entity_id')) {
            $sql='INSERT INTO branches(client_id,area_id,default_business_unit_id,default_legal_entity_id,code,name,address_text,site_type,mapping_status,active) VALUES(NULL,?,?,?,?,?,?,?,"MANUAL",1)';
            $st=db()->prepare($sql);
            $st->execute([$areaId?:null,$businessUnitId?:null,$legalEntityId?:null,$code!==''?$code:null,$name,$address?:null,$siteType]);
        } else {
            if($code==='') throw new RuntimeException('Branch code is required until the Organization Master migration is imported.');
            $sql=self::tableExists('areas')?'INSERT INTO branches(client_id,area_id,code,name,address_text,active) VALUES(NULL,?,?,?,?,1)':'INSERT INTO branches(client_id,code,name,address_text,active) VALUES(NULL,?,?,?,1)';
            $st=db()->prepare($sql);$st->execute(self::tableExists('areas')?[$areaId?:null,$code,$name,$address?:null]:[$code,$name,$address?:null]);
        }
        audit('Organization','CREATE_BRANCH','branch',(int)db()->lastInsertId(),['code'=>$code?:null,'name'=>$name,'area_id'=>$areaId,'business_unit_id'=>$businessUnitId,'legal_entity_id'=>$legalEntityId,'site_type'=>$siteType]);
    }

    public static function createEmploymentType(string $code,string $name): void
    {
        if(!self::tableExists('employment_types')) throw new RuntimeException('Run the Phase 1 foundation migration first.');
        $name=trim($name);
        $code=self::uniqueCode('employment_types','Employee Status',$name,$code);
        $st=db()->prepare('INSERT INTO employment_types(code,name,active,sort_order) VALUES(?,?,1,100)'); $st->execute([$code,$name]);
        audit('Organization','CREATE_EMPLOYMENT_TYPE','employment_type',(int)db()->lastInsertId(),['code'=>$code,'name'=>$name]);
    }

    public static function toggleMaster(string $entity,int $id): void
    {
        $map=[
            'business_unit'=>['business_units','active'],
            'legal_entity'=>['legal_entities','active'],
            'department'=>['departments','active'],
            'position'=>['positions','active'],
            'branch'=>['branches','active'],
            'area'=>['areas','active'],
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
        if(!preg_match('/^[A-Z][A-Z0-9_]{1,49}$/',$code)) throw new RuntimeException('Role code must use capital letters, numbers or underscores (e.g. ADL).');
        if(mb_strlen($name)>120) throw new RuntimeException('Role name must be 120 characters or fewer.');
        if(!in_array($portal,['employee','hr','admin','client'],true)) throw new RuntimeException('Invalid portal type.');
        $exists=db()->prepare('SELECT 1 FROM roles WHERE code=?'); $exists->execute([$code]);
        if($exists->fetchColumn()) throw new RuntimeException('A role with this code already exists.');
        $st=db()->prepare('INSERT INTO roles(code,name,portal,is_system) VALUES(?,?,?,0)'); $st->execute([$code,$name,$portal]);
        audit('Access Control','CREATE_ROLE','role',(int)db()->lastInsertId(),['code'=>$code,'portal'=>$portal]);
    }

    private static function role(int $roleId): array
    {
        $st=db()->prepare('SELECT id,code,name,portal FROM roles WHERE id=?'); $st->execute([$roleId]);
        return $st->fetch() ?: throw new RuntimeException('Role not found.');
    }

    // Escalation guards: nobody assigns Super Admin from the UI; HR/Admin-portal roles need Super Admin;
    // everyone else may only hand out a role whose permissions they hold themselves.
    public static function roleAssignmentError(array $role): ?string
    {
        if($role['code']==='SUPER_ADMIN') return 'The Super Admin role cannot be assigned from this screen.';
        if(Auth::is('SUPER_ADMIN')) return null;
        if(in_array($role['portal'],['hr','admin'],true)) return 'Only a Super Admin can assign HR or Admin portal roles.';
        $missing=array_diff(self::permissionsForRole((int)$role['id']),Auth::permissionCodes());
        return $missing ? 'You cannot assign a role with permissions you do not hold: '.implode(', ',$missing).'.' : null;
    }

    public static function assignableRoles(): array
    {
        return array_values(array_filter(self::roles(),fn($r)=>self::roleAssignmentError($r)===null));
    }

    public static function roleEditError(array $role): ?string
    {
        if($role['code']==='SUPER_ADMIN') return 'The Super Admin role cannot be edited.';
        if((int)$role['id']===(int)(Auth::user()['role_id']??0)) return 'You cannot edit the permissions of your own role.';
        return null;
    }

    public static function saveRolePermissions(int $roleId,array $permissionIds): void
    {
        if(!self::tableExists('role_permissions')) throw new RuntimeException('Run the Phase 1 foundation migration first.');
        $r=self::role($roleId);
        if($error=self::roleEditError($r)) throw new RuntimeException($error);
        $codes=db()->query('SELECT id,code FROM permissions')->fetchAll(PDO::FETCH_KEY_PAIR);
        $requested=array_values(array_filter(array_unique(array_map('intval',$permissionIds)),fn($pid)=>isset($codes[$pid])));
        $current=db()->prepare('SELECT permission_id FROM role_permissions WHERE role_id=?'); $current->execute([$roleId]);
        $before=array_map('intval',$current->fetchAll(PDO::FETCH_COLUMN));
        $final=$requested;
        if(!Auth::is('SUPER_ADMIN')){
            // Permissions you do not hold can be neither granted nor removed by you.
            $mine=Auth::permissionCodes();
            $foreign=fn($pid)=>!in_array($codes[$pid],$mine,true);
            $granted=array_filter(array_diff($requested,$before),$foreign);
            if($granted) throw new RuntimeException('You cannot grant permissions you do not hold: '.implode(', ',array_map(fn($pid)=>$codes[$pid],$granted)).'.');
            $final=array_values(array_unique(array_merge(array_filter($requested,fn($pid)=>!$foreign($pid)),array_filter($before,$foreign))));
        }
        db()->beginTransaction();
        try {
            db()->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$roleId]);
            $ins=db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)');
            foreach($final as $pid) $ins->execute([$roleId,$pid]);
            db()->commit();
        } catch(Throwable $e) { db()->rollBack(); throw $e; }
        $names=fn(array $ids)=>array_values(array_map(fn($pid)=>$codes[$pid]??(string)$pid,$ids));
        audit('Access Control','UPDATE_ROLE_PERMISSIONS','role',$roleId,['role_code'=>$r['code'],'added'=>$names(array_diff($final,$before)),'removed'=>$names(array_diff($before,$final)),'permission_ids'=>$final]);
        Auth::clearPermissionCache();
    }

    public static function createUser(string $name,string $email,string $password,int $roleId): void
    {
        $name=trim($name); $email=strtolower(trim($email));
        if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid name and email address.');
        if(strlen($password)<10) throw new RuntimeException('Temporary password must be at least 10 characters.');
        if($error=self::roleAssignmentError(self::role($roleId))) throw new RuntimeException($error);
        $exists=db()->prepare('SELECT 1 FROM users WHERE email=?'); $exists->execute([$email]);
        if($exists->fetchColumn()) throw new RuntimeException('A user with this email already exists.');
        $st=db()->prepare('INSERT INTO users(role_id,client_id,full_name,email,password_hash,status) VALUES(?,NULL,?,?,?,"ACTIVE")');
        $st->execute([$roleId,$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
        audit('Access Control','CREATE_USER','user',(int)db()->lastInsertId(),['email'=>$email,'role_id'=>$roleId]);
    }

    private static function managedUser(int $userId): array
    {
        if($userId===(int)(Auth::user()['id']??0)) throw new RuntimeException('You cannot change your own account here.');
        $st=db()->prepare('SELECT u.id,u.role_id,r.code role_code,r.portal FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?'); $st->execute([$userId]);
        $user=$st->fetch() ?: throw new RuntimeException('User account not found.');
        if(!Auth::is('SUPER_ADMIN') && ($user['role_code']==='SUPER_ADMIN' || in_array($user['portal'],['hr','admin'],true))) throw new RuntimeException('Only a Super Admin can change HR or Admin portal accounts.');
        return $user;
    }

    public static function changeUserRole(int $userId,int $roleId): void
    {
        $user=self::managedUser($userId); $role=self::role($roleId);
        if($user['role_code']==='SUPER_ADMIN') throw new RuntimeException('Super Admin accounts cannot be changed from this screen.');
        if($error=self::roleAssignmentError($role)) throw new RuntimeException($error);
        if((int)$user['role_id']===$roleId) return;
        db()->prepare('UPDATE users SET role_id=? WHERE id=?')->execute([$roleId,$userId]);
        // The role lives in the session, so sign the account out everywhere.
        if(self::tableExists('user_sessions')) db()->prepare('UPDATE user_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL')->execute([$userId]);
        audit('Access Control','CHANGE_USER_ROLE','user',$userId,['from_role_id'=>(int)$user['role_id'],'from_role'=>$user['role_code'],'to_role_id'=>$roleId,'to_role'=>$role['code']]);
    }

    public static function linkUserEmployee(int $userId,int $employeeId): void
    {
        self::managedUser($userId);
        db()->beginTransaction();
        try {
            $old=db()->prepare('SELECT id FROM employees WHERE user_id=? FOR UPDATE'); $old->execute([$userId]); $from=(int)$old->fetchColumn();
            if($employeeId>0){
                $st=db()->prepare('SELECT id,user_id,status FROM employees WHERE id=? FOR UPDATE'); $st->execute([$employeeId]);
                $employee=$st->fetch() ?: throw new RuntimeException('Employee not found.');
                if(!in_array($employee['status'],['ACTIVE','PROBATIONARY','ON_LEAVE'],true)) throw new RuntimeException('Choose an active employee.');
                if($employee['user_id']!==null && (int)$employee['user_id']!==$userId) throw new RuntimeException('This employee is already linked to another user account.');
            }
            if($from===$employeeId){ db()->commit(); return; }
            if($from) db()->prepare('UPDATE employees SET user_id=NULL WHERE id=?')->execute([$from]);
            if($employeeId>0) db()->prepare('UPDATE employees SET user_id=? WHERE id=?')->execute([$userId,$employeeId]);
            audit('Access Control',$employeeId>0?'LINK_USER_EMPLOYEE':'UNLINK_USER_EMPLOYEE','user',$userId,['from_employee_id'=>$from?:null,'to_employee_id'=>$employeeId?:null]);
            db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function linkableEmployees(): array
    {
        return db()->query('SELECT id,employee_no,first_name,last_name,user_id FROM employees WHERE status IN ("ACTIVE","PROBATIONARY","ON_LEAVE") ORDER BY last_name,first_name')->fetchAll();
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
