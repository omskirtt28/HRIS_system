<?php
declare(strict_types=1);

/** Deletes one unused employee profile; transaction records and user accounts are never targets. */
final class EmployeeDeleteService
{
    private const OWNED=[
        'employee_government_ids'=>'Government ID records',
        'employee_emergency_contacts'=>'Emergency contacts',
        'employee_documents'=>'Uploaded documents',
        'employee_employment_history'=>'Employee employment history',
        'employee_leave_balances'=>'Leave balance records',
        'payroll_biometric_mappings'=>'Biometric ID mapping',
    ];

    public static function canAccess(bool $lock=false): bool
    {
        if(!Auth::check() || !(($GLOBALS['pdo'] ?? null) instanceof PDO)) return false;
        $q=db()->prepare('SELECT u.id,r.id role_id,r.code,r.portal FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status="ACTIVE"'.($lock?' FOR UPDATE':''));
        $q->execute([(int)Auth::user()['id']]); $user=$q->fetch();
        if(!$user) return false;
        if($user['code']==='SUPER_ADMIN') return true;
        if(!in_array($user['portal'],['hr','admin'],true) || !FoundationRepository::tableExists('permissions') || !FoundationRepository::tableExists('role_permissions')) return false;
        $q=db()->prepare('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.code IN ("employees.delete","employees.view_all")'.($lock?' FOR UPDATE':''));
        $q->execute([(int)$user['role_id']]); $codes=$q->fetchAll(PDO::FETCH_COLUMN);
        return in_array('employees.delete',$codes,true) && in_array('employees.view_all',$codes,true);
    }

    public static function requireAccess(): void
    {
        Auth::requirePermission('employees.view_all');
        if(!self::canAccess()) { http_response_code(403); include dirname(__DIR__).'/public/403.php'; exit; }
    }

    public static function ready(): bool
    {
        return EmployeeRepository::ready() && FoundationRepository::tableExists('employee_delete_file_jobs') && FoundationRepository::tableExists('audit_logs');
    }

    private static function quoted(string $name): string
    {
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$name)) throw new RuntimeException('A database link needs administrator review. No employee was deleted.');
        return '`'.$name.'`';
    }

    private static function label(string $table,string $column): string
    {
        if($table==='employees') return 'Employees reporting to this employee';
        if(str_starts_with($table,'attendance_')) return 'Attendance records';
        if(str_starts_with($table,'leave_')) return 'Leave requests or credit history';
        if($table==='payroll_employee_rates') return 'Salary rate settings';
        if(str_starts_with($table,'payroll_')) return 'Payroll or timekeeping records';
        if(in_array($table,['applications','applicants','offers','deployments'],true)) return 'Recruitment records';
        if($table==='users') return 'An additional account link';
        return 'Other linked records';
    }

    /** Discover installed links, including logical employee ID columns without foreign keys. */
    private static function references(): array
    {
        $links=[]; $issues=[]; $schema=db()->query('SELECT DATABASE()')->fetchColumn();
        $q=db()->query('SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME="employees"');
        foreach($q->fetchAll() as $row) {
            if($row['TABLE_SCHEMA']!==$schema || $row['REFERENCED_COLUMN_NAME']!=='id') { $issues[]='A custom employee relationship needs administrator review.'; continue; }
            $links[$row['TABLE_NAME'].'|'.$row['COLUMN_NAME']]=[$row['TABLE_NAME'],$row['COLUMN_NAME']];
        }
        $q=db()->query('SELECT c.TABLE_NAME,c.COLUMN_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE="BASE TABLE" AND c.COLUMN_NAME LIKE "%employee_id"');
        foreach($q->fetchAll() as $row) if(preg_match('/(?:^|_)employee_id$/D',$row['COLUMN_NAME'])) $links[$row['TABLE_NAME'].'|'.$row['COLUMN_NAME']]=[$row['TABLE_NAME'],$row['COLUMN_NAME']];
        ksort($links);
        return ['links'=>$links,'issues'=>array_values(array_unique($issues))];
    }

    private static function schemaIssues(array $owned,array $links): array
    {
        $targets=array_merge(['employees','employee_roster_rows','employee_delete_file_jobs','audit_logs'],array_keys($owned));
        $installed=db()->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE="BASE TABLE"')->fetchAll(PDO::FETCH_KEY_PAIR);
        $issues=[];
        $lockTargets=array_unique(array_merge($targets,array_column(array_values($links),0)));
        foreach($lockTargets as $table) if(isset($installed[$table]) && strcasecmp((string)$installed[$table],'InnoDB')!==0) $issues[]='Employee deletion requires transactional storage. Ask your administrator to review the database.';
        $q=db()->query('SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()');
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $table) if(in_array($table,$targets,true)) $issues[]='A custom database trigger needs review before employee deletion.';
        // A profile child linked by another module cannot be silently cascaded away.
        foreach(array_keys($owned) as $table) {
            $q=db()->prepare('SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=?');
            $q->execute([$table]);
            if($q->fetchColumn()!==false) $issues[]='Another module references an Employee 201 child record. Ask your administrator to review that relationship.';
        }
        return array_values(array_unique($issues));
    }

    private static function snapshot(int $id,bool $lock=false): array
    {
        $q=db()->prepare('SELECT * FROM employees WHERE id=?'.($lock?' FOR UPDATE':'')); $q->execute([$id]); $employee=$q->fetch();
        if(!$employee) throw new RuntimeException('Employee record not found.');
        $hash=hash_init('sha256'); hash_update($hash,json_encode($employee,JSON_THROW_ON_ERROR));
        $owned=[]; $files=[];
        foreach(self::OWNED as $table=>$label) if(FoundationRepository::tableExists($table)) {
            if(!FoundationRepository::columnExists($table,'employee_id')) throw new RuntimeException('An Employee 201 table needs review. No employee was deleted.');
            $q=db()->prepare('SELECT * FROM '.self::quoted($table).' WHERE employee_id=?'.($lock?' FOR UPDATE':'')); $q->execute([$id]); $rows=$q->fetchAll();
            // Stable snapshot even if the database changes its row-return order.
            $fingerprints=array_map(static fn($row)=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)),$rows); sort($fingerprints);
            hash_update($hash,json_encode([$table,$fingerprints],JSON_THROW_ON_ERROR));
            $owned[$table]=['label'=>$label,'count'=>count($rows)];
            if($table==='employee_documents') foreach($rows as $row) if(!empty($row['stored_name'])) $files['employee_documents|'.$row['stored_name']]=['kind'=>'employee_documents','name'=>$row['stored_name']];
        }
        if(!empty($employee['profile_photo_stored_name'])) $files['employee_photos|'.$employee['profile_photo_stored_name']]=['kind'=>'employee_photos','name'=>$employee['profile_photo_stored_name']];
        $refs=self::references(); $blockers=[]; $rosterCount=0;
        foreach($refs['links'] as [$table,$column]) {
            if($column==='employee_id' && isset($owned[$table])) continue;
            if($table==='employee_roster_rows' && $column==='employee_id') {
                $q=db()->prepare('SELECT id FROM employee_roster_rows WHERE employee_id=? ORDER BY id'.($lock?' FOR UPDATE':'')); $q->execute([$id]); $ids=$q->fetchAll(PDO::FETCH_COLUMN); $rosterCount=count($ids);
                hash_update($hash,json_encode(['roster_links',$ids],JSON_THROW_ON_ERROR)); continue;
            }
            if($lock) {
                $q=db()->prepare('SELECT '.self::quoted($column).' FROM '.self::quoted($table).' WHERE '.self::quoted($column).'=? FOR UPDATE'); $q->execute([$id]); $count=count($q->fetchAll(PDO::FETCH_COLUMN));
            } else {
                $q=db()->prepare('SELECT COUNT(*) FROM '.self::quoted($table).' WHERE '.self::quoted($column).'=?'); $q->execute([$id]); $count=(int)$q->fetchColumn();
            }
            if($count) $blockers[]=['label'=>self::label($table,$column),'count'=>$count,'table'=>$table,'column'=>$column];
        }
        foreach(['source_application_id','source_deployment_id'] as $field) if(!empty($employee[$field])) $blockers[]=['label'=>'Recruitment source','count'=>1,'table'=>'employees','column'=>$field];
        $issues=array_merge($refs['issues'],self::schemaIssues($owned,$refs['links']));
        foreach($files as $file) {
            self::filePath($file['kind'],$file['name']);
            if(self::fileInUse($file['kind'],$file['name'],$id,$lock)) $issues[]='An uploaded file is shared with another employee. Ask your administrator to review it.';
        }
        hash_update($hash,json_encode([$refs['links'],$blockers,$issues,$rosterCount],JSON_THROW_ON_ERROR));
        return ['employee'=>$employee,'owned'=>$owned,'files'=>array_values($files),'blockers'=>$blockers,'issues'=>array_values(array_unique($issues)),'roster_links'=>$rosterCount,'hash'=>hash_final($hash)];
    }

    public static function preview(int $id): array
    {
        self::requireAccess();
        if(!self::ready()) throw new RuntimeException('Import 20261010_employee_delete.sql first.');
        $data=self::snapshot($id); $employee=$data['employee'];
        $data['name']=EmployeeRepository::fullName($employee);
        $data['confirmation']=trim((string)($employee['employee_no'] ?? '')) ?: ($data['name'] ?: 'DELETE '.$id);
        $data['details']=EmployeeRepository::find($id) ?? $employee;
        $data['token']=bin2hex(random_bytes(32)); $data['expires']=time()+600;
        $_SESSION['employee_delete_previews'][$id]=['user_id'=>(int)Auth::user()['id'],'hash'=>$data['hash'],'token'=>$data['token'],'expires'=>$data['expires']];
        // Keep only a few review tabs per session.
        while(count($_SESSION['employee_delete_previews'])>5) unset($_SESSION['employee_delete_previews'][array_key_first($_SESSION['employee_delete_previews'])]);
        return $data;
    }

    public static function delete(int $id,array $input): array
    {
        self::requireAccess();
        if(!self::ready()) throw new RuntimeException('Import 20261010_employee_delete.sql first.');
        $preview=$_SESSION['employee_delete_previews'][$id] ?? null;
        $token=(string)($input['preview_token'] ?? '');
        if(!$preview || (int)$preview['user_id']!==(int)Auth::user()['id'] || (int)$preview['expires']<time() || !hash_equals($preview['token'],$token)) throw new RuntimeException('The review expired. Check the employee details again before deleting.');
        $reason=trim((string)($input['reason'] ?? ''));
        if(mb_strlen($reason)<5 || mb_strlen($reason)>500) throw new RuntimeException('Enter a reason between 5 and 500 characters.');
        if(empty($input['confirmed'])) throw new RuntimeException('Confirm that you reviewed the employee and the records to remove.');
        db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); db()->beginTransaction();
        try {
            if(!self::canAccess(true)) throw new RuntimeException('Your delete permission has changed. No employee was deleted.');
            $data=self::snapshot($id,true); $employee=$data['employee'];
            if($data['blockers'] || $data['issues']) throw new RuntimeException('This employee has linked records that must be reviewed first. No employee was deleted.');
            if(!hash_equals($preview['hash'],$data['hash'])) throw new RuntimeException('The employee records changed after your review. Check the updated counts before deleting.');
            $name=EmployeeRepository::fullName($employee); $expected=trim((string)($employee['employee_no'] ?? '')) ?: ($name ?: 'DELETE '.$id);
            if(!hash_equals($expected,trim((string)($input['confirmation'] ?? '')))) throw new RuntimeException('The confirmation does not match the employee code or name shown.');
            $jobIds=[];
            foreach($data['files'] as $file) {
                $q=db()->prepare('SELECT id,employee_record_id,status FROM employee_delete_file_jobs WHERE storage_kind=? AND stored_name=? FOR UPDATE'); $q->execute([$file['kind'],$file['name']]); $job=$q->fetch();
                if($job && ((int)$job['employee_record_id']!==$id || $job['status']!=='PENDING')) throw new RuntimeException('A stored file needs review. No employee was deleted.');
                if(!$job) {
                    $q=db()->prepare('INSERT INTO employee_delete_file_jobs(employee_record_id,storage_kind,stored_name,created_by) VALUES(?,?,?,?)');
                    $q->execute([$id,$file['kind'],$file['name'],(int)Auth::user()['id']]); $jobIds[]=(int)db()->lastInsertId();
                } else $jobIds[]=(int)$job['id'];
            }
            foreach($data['owned'] as $table=>$record) { $q=db()->prepare('DELETE FROM '.self::quoted($table).' WHERE employee_id=?'); $q->execute([$id]); }
            if($data['roster_links']) { $q=db()->prepare('UPDATE employee_roster_rows SET employee_id=NULL WHERE employee_id=?'); $q->execute([$id]); }
            $q=db()->prepare('DELETE FROM employees WHERE id=?'); $q->execute([$id]);
            if($q->rowCount()!==1) throw new RuntimeException('Employee deletion could not finish.');
            audit('Employees','DELETE_EMPLOYEE','employee',$id,['employee_no'=>$employee['employee_no'] ?? null,'name'=>$name,'reason'=>$reason,'removed'=>array_map(static fn($record)=>$record['count'],$data['owned']),'user_account_kept'=>$employee['user_id'] ?? null,'roster_links_removed'=>$data['roster_links'],'files_queued'=>count($jobIds)]);
            db()->commit(); unset($_SESSION['employee_delete_previews'][$id],$_SESSION['employee_delete_form'][$id]);
        } catch(Throwable $error) { if(db()->inTransaction()) db()->rollBack(); throw $error; }
        // Physical file removal follows the committed database deletion and is retryable.
        try { self::cleanFiles($jobIds); } catch(Throwable $error) { error_log('Employee file cleanup deferred: '.get_class($error)); }
        $pending=count($jobIds);
        try { $q=db()->prepare('SELECT COUNT(*) FROM employee_delete_file_jobs WHERE employee_record_id=? AND status="PENDING"'); $q->execute([$id]); $pending=(int)$q->fetchColumn(); }
        catch(Throwable $error) { error_log('Employee cleanup count unavailable: '.get_class($error)); }
        return ['name'=>$name,'files_pending'=>$pending];
    }

    private static function filePath(string $kind,string $name): ?string
    {
        if(!in_array($kind,['employee_documents','employee_photos'],true) || $name==='' || basename($name)!==$name || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D',$name) || in_array($name,['.','..'],true)) throw new RuntimeException('An uploaded file name needs administrator review.');
        $app=realpath(dirname(__DIR__)); $storage=dirname(__DIR__).'/storage'; $directory=$storage.'/'.$kind; $candidate=$directory.'/'.$name;
        if(is_link($storage) || is_link($directory) || is_link($candidate)) throw new RuntimeException('A storage link needs administrator review.');
        if(!file_exists($candidate)) return null;
        $root=realpath($directory); $path=realpath($candidate);
        if(!$app || !$root || !$path || strncasecmp($root,$app.DIRECTORY_SEPARATOR,strlen($app)+1)!==0 || strncasecmp($path,$root.DIRECTORY_SEPARATOR,strlen($root)+1)!==0 || !is_file($path)) throw new RuntimeException('Employee storage needs administrator review.');
        return $path;
    }

    private static function fileInUse(string $kind,string $name,?int $except=null,bool $lock=false): bool
    {
        if($kind==='employee_documents' && FoundationRepository::tableExists('employee_documents')) {
            $sql='SELECT stored_name FROM employee_documents WHERE stored_name=?'.($except!==null?' AND (employee_id IS NULL OR employee_id<>?)':'');
        } elseif($kind==='employee_photos' && FoundationRepository::columnExists('employees','profile_photo_stored_name')) {
            $sql='SELECT profile_photo_stored_name FROM employees WHERE profile_photo_stored_name=?'.($except!==null?' AND id<>?':'');
        } else return false;
        $q=db()->prepare($sql.($lock?' FOR UPDATE':'')); $q->execute($except!==null?[$name,$except]:[$name]); return count($q->fetchAll(PDO::FETCH_COLUMN))>0;
    }

    public static function cleanFiles(?array $ids=null): int
    {
        self::requireAccess();
        if(!self::ready()) throw new RuntimeException('Import 20261010_employee_delete.sql first.');
        if($ids===[]) return 0;
        $params=[]; $where='status="PENDING"';
        if($ids!==null) { $ids=array_values(array_unique(array_map('intval',$ids))); $where.=' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')'; $params=$ids; }
        $q=db()->prepare('SELECT id FROM employee_delete_file_jobs WHERE '.$where.' ORDER BY id LIMIT 100'); $q->execute($params); $selected=$q->fetchAll(PDO::FETCH_COLUMN); $done=0;
        foreach($selected as $id) {
            db()->beginTransaction();
            try {
                if(!self::canAccess(true)) throw new RuntimeException('Your delete permission has changed. File cleanup was stopped.');
                $q=db()->prepare('SELECT * FROM employee_delete_file_jobs WHERE id=? AND status="PENDING" FOR UPDATE'); $q->execute([(int)$id]); $job=$q->fetch();
                if(!$job) { db()->commit(); continue; }
                $error=null;
                try {
                    if(self::fileInUse($job['storage_kind'],$job['stored_name'],null,true)) throw new RuntimeException('FILE_IN_USE');
                    $path=self::filePath($job['storage_kind'],$job['stored_name']);
                    if($path!==null && !@unlink($path)) throw new RuntimeException('REMOVE_FAILED');
                } catch(Throwable $failure) { $error='FILE_REVIEW_REQUIRED'; }
                $q=db()->prepare('UPDATE employee_delete_file_jobs SET status=?,attempts=attempts+1,last_error=?,completed_at=? WHERE id=?');
                $q->execute([$error?'PENDING':'DELETED',$error,$error?null:date('Y-m-d H:i:s'),(int)$id]);
                db()->commit(); if(!$error) $done++;
            } catch(Throwable $failure) { if(db()->inTransaction()) db()->rollBack(); throw $failure; }
        }
        return $done;
    }

    public static function pendingFiles(): array
    {
        self::requireAccess(); if(!self::ready()) return ['count'=>0,'rows'=>[]];
        $count=(int)db()->query('SELECT COUNT(*) FROM employee_delete_file_jobs WHERE status="PENDING"')->fetchColumn();
        $rows=db()->query('SELECT id,employee_record_id,storage_kind,attempts,created_at FROM employee_delete_file_jobs WHERE status="PENDING" ORDER BY id LIMIT 50')->fetchAll();
        return ['count'=>$count,'rows'=>$rows];
    }
}
