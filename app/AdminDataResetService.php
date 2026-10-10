<?php
declare(strict_types=1);

/** Explicit Super Administrator reset of demo transactions; accounts and masters are never targets. */
final class AdminDataResetService
{
    public const CONFIRMATION='CLEAR TEST DATA';
    private const BACKUP_LIFETIME=48*60*60;
    private const GROUPS=[
        'Payroll tickets'=>['payroll_requests','payroll_request_approvals','payroll_request_attachments','payroll_request_time_entries','payroll_request_history','payroll_request_applications','payroll_request_overtime_windows','payroll_backpay_claims','payroll_attendance_resolutions'],
        'Attendance and imports'=>['attendance_daily','attendance_imports','attendance_import_rows','attendance_punches','payroll_cutoff_runs','payroll_cutoff_site_coverage','payroll_cutoff_snapshots','payroll_cutoff_log_confirmations','payroll_daily_dispositions','payroll_punch_selections','payroll_cutoff_export_payments','payroll_cutoff_export_batches','payroll_cutoff_day_reviews'],
        'Leave'=>['leave_requests','leave_request_approvals','leave_credit_transactions'],
        'Recruitment'=>['applicants','applications','application_documents','application_stage_history','screening_reviews','interviews','endorsements','client_reviews','offers','deployments','job_openings','manpower_requests'],
    ];

    public static function canAccess(): bool
    {
        if(!Auth::check() || !Auth::can('system.test_data.clear')) return false;
        // Re-read the role and status instead of trusting an old browser session.
        $q=db()->prepare('SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status="ACTIVE" AND r.code="SUPER_ADMIN"');
        $q->execute([(int)Auth::user()['id']]);
        return (int)$q->fetchColumn()===1;
    }

    public static function requireAccess(): void
    {
        Auth::requirePermission('system.test_data.clear');
        if(!self::canAccess()) { http_response_code(403); exit('Only the Super Administrator can clear test data.'); }
    }

    /** Delete only this allowlist, in dependency order. New dependent modules must be reviewed explicitly. */
    private static function tables(): array
    {
        $installed=db()->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE="BASE TABLE"')->fetchAll(PDO::FETCH_KEY_PAIR);
        $targets=[];
        foreach(self::GROUPS as $group=>$names) foreach($names as $name) if(isset($installed[$name])) {
            if(strcasecmp((string)$installed[$name],'InnoDB')!==0) throw new RuntimeException('Cleanup requires transactional tables. Ask the administrator to review '.$name.'.');
            $targets[$name]=$group;
        }
        if(isset($installed['employee_leave_balances']) && strcasecmp((string)$installed['employee_leave_balances'],'InnoDB')!==0) throw new RuntimeException('Leave balances must use transactional storage before cleanup.');
        if(!isset($installed['audit_logs']) || strcasecmp((string)$installed['audit_logs'],'InnoDB')!==0) throw new RuntimeException('The audit log must be available before cleanup.');
        $triggers=db()->query('SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        foreach($triggers as $table) if(isset($targets[$table]) || $table==='employee_leave_balances') throw new RuntimeException('A custom database trigger affects '.$table.'. Cleanup needs review before it can run.');
        $edges=db()->query('SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll();
        foreach($edges as $edge) if(isset($targets[$edge['REFERENCED_TABLE_NAME']]) && !isset($targets[$edge['TABLE_NAME']])) throw new RuntimeException('Another module links to these records: '.$edge['TABLE_NAME'].'. Cleanup needs an update before it can run.');
        $ordered=[]; $remaining=$targets;
        while($remaining) {
            $progress=false;
            foreach($remaining as $name=>$group) {
                $hasChild=false;
                foreach($edges as $edge) if($edge['REFERENCED_TABLE_NAME']===$name && isset($remaining[$edge['TABLE_NAME']])) { $hasChild=true; break; }
                if($hasChild) continue;
                $ordered[$name]=$group; unset($remaining[$name]); $progress=true;
            }
            if(!$progress) throw new RuntimeException('The module links need review before cleanup.');
        }
        return $ordered;
    }

    private static function quoteName(string $name): string
    {
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name)) throw new RuntimeException('Unexpected database field.');
        return '`'.$name.'`';
    }

    /** Hash all values so a same-count edit also invalidates the confirmation preview. */
    private static function snapshot(array $tables,bool $lock=false,$sql=null): array
    {
        $hash=hash_init('sha256'); $counts=[]; $files=[];
        $names=array_reverse(array_keys($tables));
        if(FoundationRepository::tableExists('employee_leave_balances')) $names[]='employee_leave_balances';
        foreach($names as $name) {
            $quoted=self::quoteName($name); $columns=db()->query('SHOW COLUMNS FROM '.$quoted)->fetchAll();
            $keys=[]; foreach($columns as $column) if($column['Key']==='PRI') $keys[]=self::quoteName($column['Field']);
            if(!$keys) throw new RuntimeException('The table '.$name.' needs a primary key before cleanup.');
            hash_update($hash,json_encode([$name,$columns],JSON_THROW_ON_ERROR)); $counts[$name]=0;
            $q=db()->query('SELECT * FROM '.$quoted.' ORDER BY '.implode(',',$keys).($lock?' FOR UPDATE':''));
            while($row=$q->fetch()) {
                $counts[$name]++; hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));
                if($sql) {
                    if($name==='employee_leave_balances') {
                        $updated=isset($row['updated_at'])?', `updated_at`='.db()->quote($row['updated_at']):'';
                        self::write($sql,'UPDATE `employee_leave_balances` SET `balance`='.db()->quote((string)$row['balance']).$updated.' WHERE `employee_id`='.(int)$row['employee_id'].' AND `leave_type_id`='.(int)$row['leave_type_id'].";\n");
                    } else {
                        $values=array_map(static fn($v)=>$v===null?'NULL':db()->quote((string)$v),array_values($row));
                        self::write($sql,'INSERT INTO '.$quoted.' ('.implode(',',array_map([self::class,'quoteName'],array_keys($row))).') VALUES ('.implode(',',$values).");\n");
                    }
                }
                if(in_array($name,['attendance_imports','payroll_request_attachments','application_documents'],true) && !empty($row['stored_name'])) $files[$name.'|'.$row['stored_name']]=['table'=>$name,'name'=>$row['stored_name']];
            }
            $q->closeCursor();
        }
        return ['hash'=>hash_final($hash),'counts'=>$counts,'files'=>array_values($files)];
    }

    private static function creditChanges(): array
    {
        if(!FoundationRepository::tableExists('leave_credit_transactions')) return [];
        if(!FoundationRepository::tableExists('employee_leave_balances')) throw new RuntimeException('Leave balances are unavailable. Cleanup cannot reverse the test credits.');
        $rows=db()->query('SELECT t.employee_id,t.leave_type_id,e.employee_no,lt.name leave_type,b.balance current_balance,t.net,b.balance-t.net opening_balance FROM (SELECT employee_id,leave_type_id,SUM(amount) net FROM leave_credit_transactions GROUP BY employee_id,leave_type_id) t LEFT JOIN employee_leave_balances b ON b.employee_id=t.employee_id AND b.leave_type_id=t.leave_type_id LEFT JOIN employees e ON e.id=t.employee_id LEFT JOIN leave_types lt ON lt.id=t.leave_type_id ORDER BY t.employee_id,t.leave_type_id')->fetchAll();
        foreach($rows as $row) if($row['opening_balance']===null || (float)$row['opening_balance']<0) throw new RuntimeException('The leave credit history does not match the current balances. Ask HR to check the balances before clearing data.');
        return $rows;
    }

    public static function preview(): array
    {
        self::requireAccess(); unset($_SESSION['admin_reset_preview']);
        $tables=self::tables();
        db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); db()->beginTransaction();
        try {
            $snapshot=self::snapshot($tables); $credits=self::creditChanges();
            $kept=['accounts'=>(int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),'employees'=>FoundationRepository::tableExists('employees')?(int)db()->query('SELECT COUNT(*) FROM employees')->fetchColumn():0];
            db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
        $counts=array_intersect_key($snapshot['counts'],$tables);
        $preview=['token'=>bin2hex(random_bytes(32)),'hash'=>$snapshot['hash'],'user_id'=>(int)Auth::user()['id'],'expires'=>time()+600,'groups'=>self::GROUPS,'tables'=>$tables,'counts'=>$counts,'total'=>array_sum($counts),'credits'=>$credits,'kept'=>$kept];
        $_SESSION['admin_reset_preview']=$preview;
        return $preview;
    }

    public static function currentPreview(): ?array
    {
        $p=$_SESSION['admin_reset_preview']??null;
        return is_array($p) && (int)$p['user_id']===(int)Auth::user()['id'] && (int)$p['expires']>=time()?$p:null;
    }

    private static function verifyPassword(string $password): void
    {
        $uid=(int)Auth::user()['id'];
        $q=db()->prepare('SELECT COUNT(*) FROM audit_logs WHERE user_id=? AND action="TEST_DATA_PASSWORD_FAILED" AND created_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)'); $q->execute([$uid]);
        if((int)$q->fetchColumn()>=5) throw new RuntimeException('Too many incorrect passwords. Wait 15 minutes before trying again.');
        $q=db()->prepare('SELECT password_hash FROM users WHERE id=? AND status="ACTIVE"'); $q->execute([$uid]); $stored=$q->fetchColumn();
        if(!$stored || !password_verify($password,(string)$stored)) { audit('Administration','TEST_DATA_PASSWORD_FAILED','user',$uid); throw new RuntimeException('Your current password is incorrect.'); }
    }

    /** Store backups outside the web root; downloads always pass through the admin permission check. */
    private static function backupDirectory(): string
    {
        $app=realpath(dirname(__DIR__)); $temp=realpath(sys_get_temp_dir());
        if(!$app || !$temp) throw new RuntimeException('Private backup storage is unavailable. No data was cleared.');
        $dir=$temp.DIRECTORY_SEPARATOR.'pmbsi-hris-reset-'.substr(hash('sha256',$app.'|'.(string)db()->query('SELECT DATABASE()')->fetchColumn()),0,20);
        if(is_link($dir)) throw new RuntimeException('Private backup storage needs review.');
        if(!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Could not create private backup storage.');
        $resolved=realpath($dir);
        foreach([$app,realpath((string)($_SERVER['DOCUMENT_ROOT']??''))] as $public) if($public && self::inside($resolved?:$dir,$public)) throw new RuntimeException('Backup storage must be outside the website folder. No data was cleared.');
        if(!$resolved || !is_writable($resolved)) throw new RuntimeException('Private backup storage is not writable.');
        return $resolved;
    }

    private static function purgeOldBackups(string $dir): void
    {
        $removed=0;
        foreach(new DirectoryIterator($dir) as $file) {
            if($file->isDot() || $file->isLink() || !$file->isFile() || !preg_match('/^[a-f0-9]{32}\.(zip|sql)$/D',$file->getFilename())) continue;
            $path=$file->getRealPath();
            if($path && self::inside($path,$dir) && $file->getMTime()<time()-self::BACKUP_LIFETIME && @unlink($path)) $removed++;
            if($removed>=100) break;
        }
    }

    private static function inside(string $path,string $root): bool
    {
        $path=str_replace('\\','/',$path); $root=rtrim(str_replace('\\','/',$root),'/');
        if(PHP_OS_FAMILY==='Windows') { $path=strtolower($path); $root=strtolower($root); }
        return $path===$root || str_starts_with($path,$root.'/');
    }

    private static function storedFile(array $file): ?array
    {
        [$root,$folder]=match($file['table']) {
            'attendance_imports'=>[dirname(__DIR__).'/storage/biometric_uploads','biometric_uploads'],
            'payroll_request_attachments'=>[dirname(__DIR__).'/storage/payroll_requests','payroll_requests'],
            'application_documents'=>[(string)cfg('uploads.resume_dir'),'resumes'],
            default=>throw new RuntimeException('Unknown attachment group.'),
        };
        $name=(string)$file['name'];
        if(!preg_match('/^[A-Za-z0-9_-]+\.(pdf|csv|xlsx|doc|docx|jpg|jpeg|png|webp)$/iD',$name)) throw new RuntimeException('An attachment filename needs review before cleanup.');
        $base=realpath($root); if(!$base) return null;
        $path=$base.DIRECTORY_SEPARATOR.$name;
        if(is_link($path)) throw new RuntimeException('An attachment link needs review before cleanup.');
        if(!file_exists($path)) return null; // Previously expired uploads need no file cleanup.
        $real=realpath($path);
        if(!$real || !self::inside($real,$base) || !is_file($real) || !is_readable($real)) throw new RuntimeException('An attachment cannot be backed up. No data was cleared.');
        return ['path'=>$real,'entry'=>'files/'.$folder.'/'.$name];
    }

    private static function write($stream,string $text): void
    {
        $length=strlen($text); $offset=0;
        while($offset<$length) { $n=fwrite($stream,substr($text,$offset)); if($n===false || $n===0) throw new RuntimeException('Backup writing failed. No data was cleared.'); $offset+=$n; }
    }

    public static function clear(array $input): array
    {
        self::requireAccess(); $preview=self::currentPreview();
        if(!$preview || !hash_equals($preview['token'],(string)($input['preview_token']??''))) throw new RuntimeException('Preview the current records again before clearing data.');
        if(trim((string)($input['confirmation']??''))!==self::CONFIRMATION || ($input['acknowledge']??'')!=='1') throw new RuntimeException('Confirm the listed records are test data and type CLEAR TEST DATA.');
        self::verifyPassword((string)($input['current_password']??''));
        if(!class_exists(ZipArchive::class)) throw new RuntimeException('PHP ZIP support is needed to create the backup. Enable extension=zip and restart PHP. No data was cleared.');
        if(!$preview['total']) throw new RuntimeException('There are no test transactions to clear.');
        $dir=self::backupDirectory(); self::purgeOldBackups($dir); $id=bin2hex(random_bytes(16)); $archive=$dir.DIRECTORY_SEPARATOR.$id.'.zip'; $sqlPath=$dir.DIRECTORY_SEPARATOR.$id.'.sql';
        $tables=self::tables(); $filePaths=[]; $zip=null; $sql=null; $committed=false; $commitAttempted=false;
        db()->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE'); db()->beginTransaction();
        try {
            $actor=db()->prepare('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status="ACTIVE" AND r.code="SUPER_ADMIN" FOR UPDATE'); $actor->execute([(int)Auth::user()['id']]);
            if(!$actor->fetchColumn()) throw new RuntimeException('System Admin access changed. No data was cleared.');
            $sql=@fopen($sqlPath,'xb'); if(!$sql) throw new RuntimeException('Could not open the transaction backup.'); @chmod($sqlPath,0600);
            $sqlMode=(string)db()->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            self::write($sql,"-- HRIS test transaction backup. Restore only into the same database immediately after cleanup.\n-- Keep foreign keys enabled. Restore only while these transaction tables are empty.\nSET NAMES utf8mb4;\nSET @hris_restore_sql_mode=@@SESSION.sql_mode;\nSET SESSION sql_mode=".db()->quote($sqlMode).";\nSTART TRANSACTION;\n");
            $snapshot=self::snapshot($tables,true,$sql);
            if(!hash_equals($preview['hash'],$snapshot['hash'])) { unset($_SESSION['admin_reset_preview']); throw new RuntimeException('Records changed after your preview. Nothing was cleared. Preview again to review the updated counts.'); }
            self::creditChanges();
            self::write($sql,"COMMIT;\nSET SESSION sql_mode=@hris_restore_sql_mode;\n"); if(!fflush($sql)) throw new RuntimeException('Could not finish the transaction backup.'); fclose($sql); $sql=null;
            $zip=new ZipArchive();
            if($zip->open($archive,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Could not create the backup ZIP.');
            if(!$zip->addFile($sqlPath,'restore-transactions.sql')) throw new RuntimeException('Could not add the database backup.');
            foreach($snapshot['files'] as $file) {
                $stored=self::storedFile($file); if(!$stored) continue;
                if(isset($filePaths[$stored['path']])) continue;
                if(!$zip->addFile($stored['path'],$stored['entry'])) throw new RuntimeException('Could not back up an attachment.');
                $filePaths[$stored['path']]=$file;
            }
            $counts=array_intersect_key($snapshot['counts'],$tables);
            $manifest=['created_at'=>date(DATE_ATOM),'created_by'=>(int)Auth::user()['id'],'database'=>(string)db()->query('SELECT DATABASE()')->fetchColumn(),'record_counts'=>$counts,'retained'=>'Accounts, Employee 201, organization, settings and audit logs','attachment_files'=>count($filePaths)];
            if(!$zip->addFromString('manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)) || !$zip->addFromString('README.txt',"This backup contains the cleared transaction rows, previous leave balances and available linked attachments.\nRestore immediately after cleanup, before new test records are created, into the same HRIS database with its accounts and Employee 201 records intact.\nImport restore-transactions.sql using phpMyAdmin. Copy files/biometric_uploads and files/payroll_requests to the matching storage folders. Copy files/resumes to the configured resume upload folder.\nDo not restore over newer transactions or leave credit changes. Ask your administrator to reconcile those changes first.\n")) throw new RuntimeException('Could not finish backup metadata.');
            if(!$zip->close()) throw new RuntimeException('Could not finish the backup ZIP.'); $zip=null; @chmod($archive,0600);
            $verify=new ZipArchive();
            if($verify->open($archive,ZipArchive::CHECKCONS)!==true) throw new RuntimeException('The backup ZIP could not be read. No data was cleared.');
            $backupSql=$verify->getStream('restore-transactions.sql');
            if(!$backupSql) { $verify->close(); throw new RuntimeException('The backup is incomplete. No data was cleared.'); }
            $hash=hash_init('sha256'); hash_update_stream($hash,$backupSql); fclose($backupSql); $matches=hash_equals(hash_file('sha256',$sqlPath),hash_final($hash)); $verify->close();
            if(!$matches) throw new RuntimeException('The backup did not match the records. No data was cleared.');
            // Reverse the cleared ledger exactly; retain the opening balance and employee record.
            if(isset($tables['leave_credit_transactions'])) db()->exec('UPDATE employee_leave_balances b JOIN (SELECT employee_id,leave_type_id,SUM(amount) net FROM leave_credit_transactions GROUP BY employee_id,leave_type_id) t ON t.employee_id=b.employee_id AND t.leave_type_id=b.leave_type_id SET b.balance=b.balance-t.net');
            foreach($tables as $table=>$group) {
                $deleted=db()->exec('DELETE FROM '.self::quoteName($table));
                if($deleted!==$counts[$table]) throw new RuntimeException('Records changed during cleanup. The database reset was rolled back.');
            }
            audit('Administration','CLEAR_TEST_TRANSACTIONS','system',null,['backup_id'=>$id,'record_counts'=>$counts,'records'=>array_sum($counts),'accounts_preserved'=>true,'employee_records_preserved'=>true,'available_files'=>count($filePaths)]);
            $auditId=(int)db()->lastInsertId(); $commitAttempted=true; db()->commit(); $committed=true;
        } catch(Throwable $e) {
            if(db()->inTransaction()) db()->rollBack();
            if(is_resource($sql)) fclose($sql);
            if($zip instanceof ZipArchive) @$zip->close();
            if(!$committed && !$commitAttempted && is_file($archive)) @unlink($archive);
            throw $e;
        } finally { if(is_file($sqlPath)) @unlink($sqlPath); }
        unset($_SESSION['admin_reset_preview'],$_SESSION['payroll_request_old'],$_SESSION['payroll_v2_old']);
        // Files are removed only after a complete backup and successful database commit.
        $failed=0;
        foreach($filePaths as $path=>$file) {
            try { $stored=self::storedFile($file); if($stored && ($stored['path']!==$path || !@unlink($path))) $failed++; }
            catch(Throwable $e) { $failed++; }
        }
        if($failed) {
            try { audit('Administration','TEST_DATA_FILE_CLEANUP_PENDING','audit_log',$auditId,['files'=>$failed]); }
            catch(Throwable $e) { error_log('HRIS cleanup completed; some backed-up attachment files still need removal.'); }
        }
        return ['audit_id'=>$auditId,'records'=>array_sum($counts),'files_pending'=>$failed];
    }

    public static function backups(): array
    {
        self::requireAccess();
        $rows=db()->query('SELECT id,created_at,details_json FROM audit_logs WHERE action="CLEAR_TEST_TRANSACTIONS" ORDER BY id DESC LIMIT 10')->fetchAll();
        if($rows) self::purgeOldBackups(self::backupDirectory());
        foreach($rows as &$row) { $details=json_decode($row['details_json'],true)?:[]; $row['records']=(int)($details['records']??0); $row['expired']=strtotime($row['created_at'])+self::BACKUP_LIFETIME<time(); unset($row['details_json']); }
        unset($row); return $rows;
    }

    public static function download(int $auditId): never
    {
        self::requireAccess();
        $q=db()->prepare('SELECT created_at,details_json FROM audit_logs WHERE id=? AND action="CLEAR_TEST_TRANSACTIONS"'); $q->execute([$auditId]); $row=$q->fetch(); $details=json_decode((string)($row['details_json']??''),true)?:[];
        $id=(string)($details['backup_id']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new RuntimeException('Backup not found.');
        $dir=self::backupDirectory(); self::purgeOldBackups($dir);
        if(strtotime($row['created_at'])+self::BACKUP_LIFETIME<time()) throw new RuntimeException('This server backup has expired. Use your downloaded copy.');
        $path=$dir.DIRECTORY_SEPARATOR.$id.'.zip';
        if(is_link($path) || !is_file($path) || !is_readable($path)) throw new RuntimeException('The server copy is no longer available. Use your downloaded backup.');
        audit('Administration','DOWNLOAD_TEST_DATA_BACKUP','audit_log',$auditId);
        header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="HRIS-test-data-backup-'.$auditId.'.zip"'); header('Content-Length: '.filesize($path)); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
        readfile($path); exit;
    }
}
