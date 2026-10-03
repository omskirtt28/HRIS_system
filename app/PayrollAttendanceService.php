<?php
declare(strict_types=1);

final class PayrollAttendanceService
{
    public static function ready(): bool
    {
        foreach(['payroll_site_settings','payroll_biometric_mappings','payroll_employee_rates','payroll_rate_rules','payroll_settings','payroll_holidays','payroll_cutoff_runs','attendance_imports','attendance_import_rows','attendance_punches','attendance_daily','payroll_request_applications','payroll_cutoff_snapshots','payroll_attendance_resolutions','payroll_daily_dispositions','payroll_cutoff_site_coverage','payroll_request_overtime_windows','payroll_request_pay_rules','payroll_backpay_claims','payroll_punch_selections'] as $table) if(!FoundationRepository::tableExists($table)) return false;
        return PayrollRepository::ready();
    }

    public static function requireReady(): void
    {
        if(!self::ready()) throw new RuntimeException('Import database/migrations/20261002_payroll_biometric_workflow.sql into the existing Phase 3A HRIS database first.');
    }

    public static function automaticType(string $code): bool { return in_array(strtoupper($code),['TA','PTA','OB','POB','OT','POT'],true); }

    public static function route(array $employee): array
    {
        self::requireReady();
        $site=self::site((int)($employee['branch_id']??0));
        if(!$site) throw new RuntimeException('HR Payroll must configure the employee branch/Head Office in Payroll Setup first.');
        $kind=$site['workplace']==='HEAD_OFFICE'?'MANAGER':'ADL';
        $uid=(int)($site['approver_user_id']??0);
        if($kind==='MANAGER') {
            $st=db()->prepare('SELECT user_id FROM employees WHERE id=? AND status IN ("ACTIVE","PROBATIONARY")');
            $st->execute([(int)($employee['manager_employee_id']??0)]); $uid=(int)$st->fetchColumn();
        }
        if(!$uid || $uid===(int)($employee['user_id']??0)) throw new RuntimeException('Assign an active '.($kind==='ADL'?'branch ADL':'reporting Manager').' other than the requester.');
        $st=db()->prepare('SELECT u.id,r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status="ACTIVE" AND (r.code="SUPER_ADMIN" OR EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=u.role_id AND p.code=?))');
        $st->execute([$uid,$kind==='ADL'?'payroll.approve_adl':'payroll.approve_manager']);
        if(!$st->fetch()) throw new RuntimeException('The assigned '.$kind.' needs an active account and the corresponding payroll approval permission.');
        return ['type'=>$kind,'user_id'=>$uid];
    }

    public static function site(int $branchId): ?array
    {
        $st=db()->prepare('SELECT * FROM payroll_site_settings WHERE branch_id=?'); $st->execute([$branchId]); return $st->fetch()?:null;
    }

    private static function requireImportSite(int $branchId): void
    {
        $st=db()->prepare('SELECT id FROM branches WHERE id=? AND active=1'); $st->execute([$branchId]);
        if(!$st->fetchColumn()) throw new RuntimeException('Choose an active branch or Head Office for this export.');
    }

    public static function run(int $cutoffId,bool $lock=false): array
    {
        $st=db()->prepare('SELECT c.*,r.state run_state,r.covered_through,r.ticket_deadline,r.finalized_at FROM payroll_cutoffs c LEFT JOIN payroll_cutoff_runs r ON r.cutoff_id=c.id WHERE c.id=?'.($lock?' FOR UPDATE':''));
        $st->execute([$cutoffId]); $r=$st->fetch(); if(!$r) throw new RuntimeException('Cutoff not found.');
        return $r;
    }

    private static function updateCoverage(int $cutoffId): void
    {
        $run=self::run($cutoffId);
        $q=db()->prepare('SELECT COUNT(DISTINCT e.branch_id) sites,COUNT(DISTINCT cov.branch_id) covered,MIN(cov.covered_through) through_date FROM employees e JOIN payroll_biometric_mappings m ON m.employee_id=e.id LEFT JOIN payroll_cutoff_site_coverage cov ON cov.branch_id=e.branch_id AND cov.cutoff_id=? WHERE e.hire_date<=? AND (e.status IN ("ACTIVE","PROBATIONARY","ON_LEAVE") OR EXISTS(SELECT 1 FROM attendance_daily d WHERE d.employee_id=e.id AND d.cutoff_id=?))');
        $q->execute([$cutoffId,$run['period_end'],$cutoffId]); $r=$q->fetch();
        $date=(int)$r['sites']>0 && (int)$r['sites']===(int)$r['covered']?$r['through_date']:null;
        db()->prepare('UPDATE payroll_cutoff_runs SET covered_through=? WHERE cutoff_id=?')->execute([$date,$cutoffId]);
    }

    public static function lockOpen(int $cutoffId): array
    {
        db()->prepare('INSERT IGNORE INTO payroll_cutoff_runs(cutoff_id) VALUES(?)')->execute([$cutoffId]);
        $r=self::run($cutoffId,true);
        if($r['run_state']==='FINALIZED') throw new RuntimeException('This cutoff is finalized. Its attendance/payroll snapshot cannot be changed.');
        return $r;
    }

    public static function assertTicketAllowed(array $cutoff): void
    {
        $r=self::run((int)$cutoff['id']);
        if($r['run_state']==='FINALIZED') throw new RuntimeException('This cutoff is finalized; this request cannot change its payroll.');
        if(!empty($r['ticket_deadline']) && new DateTimeImmutable('now')>new DateTimeImmutable($r['ticket_deadline'])) throw new RuntimeException('The ticket/approval deadline for this cutoff has passed. HR Payroll must explicitly extend it before a late filing or approval.');
    }

    public static function prepareImport(int $cutoffId,array $file,int $branchId): int
    {
        Auth::requirePermission('payroll.biometric_import'); self::requireReady(); self::run($cutoffId);
        // Raw logs may be imported before schedule setup; calculation still flags SCHEDULE_NOT_CONFIGURED.
        self::requireImportSite($branchId);
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name']??''))) throw new RuntimeException('Choose a valid biometric upload.');
        if((int)($file['size']??0)>20*1024*1024) throw new RuntimeException('Biometric uploads must be 20 MB or smaller.');
        $ext=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));
        if(!in_array($ext,['pdf','csv','xlsx'],true)) throw new RuntimeException('Upload PDF, CSV or XLSX.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        $allowed=['pdf'=>['application/pdf'],'csv'=>['text/plain','text/csv','application/csv','application/vnd.ms-excel'],'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']];
        if(!in_array($mime,$allowed[$ext],true)) throw new RuntimeException('File content does not match the chosen format.');
        $hash=hash_file('sha256',(string)$file['tmp_name']);
        $st=db()->prepare('SELECT id,state FROM attendance_imports WHERE cutoff_id=? AND branch_id=? AND file_hash=?'); $st->execute([$cutoffId,$branchId,$hash]);
        $old=$st->fetch();
        if($old && $old['state']!=='EXPIRED') throw new RuntimeException('This file already has import #'.$old['id'].'. Open its preview/history rather than importing it twice.');
        $rows=BiometricParser::parse((string)$file['tmp_name'],$ext);
        $dir=self::uploadDir(); if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir)) throw new RuntimeException('Unable to create biometric upload storage.');
        $stored='bio_'.bin2hex(random_bytes(16)).'.'.$ext;
        if(!move_uploaded_file((string)$file['tmp_name'],$dir.'/'.$stored)) throw new RuntimeException('Unable to save biometric upload.');
        db()->beginTransaction();
        try {
            self::lockOpen($cutoffId);
            // Recheck under the cutoff lock: simultaneous uploads must never overwrite a committed import.
            $st=db()->prepare('SELECT id,state FROM attendance_imports WHERE cutoff_id=? AND branch_id=? AND file_hash=? FOR UPDATE'); $st->execute([$cutoffId,$branchId,$hash]); $current=$st->fetch();
            if($current && $current['state']!=='EXPIRED') throw new RuntimeException('This file already has import #'.$current['id'].'.');
            $name=mb_substr(basename((string)$file['name']),0,255); $actor=(int)Auth::user()['id'];
            if($current) {
                $id=(int)$current['id'];
                db()->prepare('DELETE FROM attendance_import_rows WHERE import_id=?')->execute([$id]);
                db()->prepare('UPDATE attendance_imports SET state="PREVIEW",original_name=?,stored_name=?,row_count=?,created_by=?,created_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL 2 HOUR),deleted_at=NULL WHERE id=?')->execute([$name,$stored,count($rows),$actor,$id]);
            } else {
                $st=db()->prepare('INSERT INTO attendance_imports(cutoff_id,branch_id,original_name,stored_name,file_hash,row_count,created_by,expires_at) VALUES(?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 2 HOUR))');
                $st->execute([$cutoffId,$branchId,$name,$stored,$hash,count($rows),$actor]); $id=(int)db()->lastInsertId();
            }
            $insert=db()->prepare('INSERT INTO attendance_import_rows(import_id,row_no,biometric_id,punched_at,punch_status,employee_id,row_state) VALUES(?,?,?,?,?,?,?)');
            $mapping=db()->query('SELECT biometric_id,employee_id FROM payroll_biometric_mappings')->fetchAll(PDO::FETCH_KEY_PAIR);
            $cutoff=self::run($cutoffId);
            $employeeSites=db()->query('SELECT id,branch_id FROM employees')->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach($rows as $i=>$r) {
                $date=substr($r['punched_at'],0,10);
                // Next-day punches may belong to overnight duty on the cutoff's last day.
                if($date<$cutoff['period_start']||$date>(new DateTimeImmutable($cutoff['period_end']))->modify('+1 day')->format('Y-m-d')) throw new RuntimeException('Row '.($i+1).' is outside this cutoff (including its overnight tail). Choose the correct cutoff or split the export.');
                $employee=$mapping[$r['biometric_id']]??null;
                $state=!$employee?'UNMAPPED':((int)($employeeSites[$employee]??0)===$branchId?'READY':'SITE_MISMATCH');
                $insert->execute([$id,$i+1,$r['biometric_id'],$r['punched_at'],$r['punch_status'],$employee,$state]);
            }
            audit('Payroll','BIOMETRIC_PREVIEW','attendance_import',$id,['rows'=>count($rows),'cutoff_id'=>$cutoffId]); db()->commit(); return $id;
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); @unlink($dir.'/'.$stored); throw $e; }
    }

    public static function import(int $id): array
    {
        $st=db()->prepare('SELECT i.*,b.name branch_name,u.full_name uploaded_by FROM attendance_imports i JOIN branches b ON b.id=i.branch_id JOIN users u ON u.id=i.created_by WHERE i.id=?'); $st->execute([$id]); $r=$st->fetch(); if(!$r) throw new RuntimeException('Import not found.'); return $r;
    }

    private static function previewMatchingSql(): string
    {
        // A saved biometric mapping wins. Otherwise accept only an exact employee number
        // whose employee has no other biometric mapping; never guess by name or cast IDs to numbers.
        return 'SELECT r.*,COALESCE(mapped.id,exact_employee.id) matched_employee_id,
            COALESCE(mapped.employee_no,exact_employee.employee_no) employee_no,
            CONCAT_WS(" ",COALESCE(mapped.first_name,exact_employee.first_name),COALESCE(mapped.last_name,exact_employee.last_name)) employee_name,
            COALESCE(mapped.branch_id,exact_employee.branch_id) employee_branch_id,b.name employee_branch_name,
            CASE WHEN mapped.id IS NOT NULL THEN "BIOMETRIC_MAPPING" WHEN exact_employee.id IS NOT NULL THEN "EMPLOYEE_NUMBER" ELSE "UNMAPPED" END match_source,
            CASE WHEN COALESCE(mapped.id,exact_employee.id) IS NULL THEN "UNMAPPED"
                 WHEN COALESCE(mapped.branch_id,exact_employee.branch_id,0)<>i.branch_id THEN "SITE_MISMATCH" ELSE "READY" END current_match_state
            FROM attendance_import_rows r JOIN attendance_imports i ON i.id=r.import_id
            LEFT JOIN payroll_biometric_mappings m ON m.biometric_id=r.biometric_id
            LEFT JOIN employees mapped ON mapped.id=m.employee_id
            LEFT JOIN employees exact_employee ON m.employee_id IS NULL AND exact_employee.employee_no=r.biometric_id AND BINARY exact_employee.employee_no=BINARY r.biometric_id
                AND NOT EXISTS(SELECT 1 FROM payroll_biometric_mappings own_mapping WHERE own_mapping.employee_id=exact_employee.id)
            LEFT JOIN branches b ON b.id=COALESCE(mapped.branch_id,exact_employee.branch_id)';
    }

    public static function previewCounts(int $id): array
    {
        if(self::import($id)['state']==='PREVIEW') {
            $st=db()->prepare('SELECT current_match_state,COUNT(*) total FROM ('.self::previewMatchingSql().' WHERE r.import_id=?) matching GROUP BY current_match_state');
        } else {
            $st=db()->prepare('SELECT row_state,COUNT(*) total FROM attendance_import_rows WHERE import_id=? GROUP BY row_state');
        }
        $st->execute([$id]); return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function unmappedBiometricIds(): array
    {
        $sql='SELECT biometric_id FROM (
            SELECT matching.biometric_id FROM ('.self::previewMatchingSql().' WHERE i.state="PREVIEW") matching WHERE current_match_state="UNMAPPED"
            UNION SELECT r.biometric_id FROM attendance_import_rows r JOIN attendance_imports i ON i.id=r.import_id WHERE i.state="IMPORTED" AND r.row_state="UNMAPPED"
            ) unknown_ids ORDER BY biometric_id LIMIT 250';
        return db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function previewRows(int $id): array
    {
        if(self::import($id)['state']==='PREVIEW') {
            $st=db()->prepare(self::previewMatchingSql().' WHERE r.import_id=? ORDER BY r.row_no LIMIT 250'); $st->execute([$id]); $rows=$st->fetchAll();
            foreach($rows as &$r) { $r['employee_id']=$r['matched_employee_id']; $r['row_state']=$r['current_match_state']; } unset($r);
            return $rows;
        }
        $st=db()->prepare('SELECT r.*,CONCAT_WS(" ",e.first_name,e.last_name) employee_name,e.employee_no,b.name employee_branch_name FROM attendance_import_rows r LEFT JOIN employees e ON e.id=r.employee_id LEFT JOIN branches b ON b.id=e.branch_id WHERE r.import_id=? ORDER BY r.row_no LIMIT 250'); $st->execute([$id]); return $st->fetchAll();
    }

    private static function autoMapEmployeeNumbers(int $importId,int $branchId): int
    {
        // Persist exact matches only when HR confirms the import, inside the same transaction.
        // INSERT IGNORE respects both identity uniqueness constraints if another user maps concurrently.
        $st=db()->prepare('INSERT IGNORE INTO payroll_biometric_mappings(employee_id,biometric_id,updated_by)
            SELECT DISTINCT e.id,r.biometric_id,? FROM attendance_import_rows r
            JOIN employees e ON e.employee_no=r.biometric_id AND BINARY e.employee_no=BINARY r.biometric_id
            LEFT JOIN payroll_biometric_mappings by_id ON by_id.biometric_id=r.biometric_id
            LEFT JOIN payroll_biometric_mappings by_employee ON by_employee.employee_id=e.id
            WHERE r.import_id=? AND e.branch_id=? AND by_id.employee_id IS NULL AND by_employee.employee_id IS NULL');
        $st->execute([(int)Auth::user()['id'],$importId,$branchId]); return $st->rowCount();
    }

    public static function commitImport(int $id,string $coveredThrough): void
    {
        Auth::requirePermission('payroll.biometric_import'); self::requireReady(); $coveredThrough=self::date($coveredThrough);
        db()->beginTransaction();
        try {
            $import=self::import($id); $run=self::lockOpen((int)$import['cutoff_id']);
            $st=db()->prepare('SELECT *,expires_at<=NOW() preview_expired FROM attendance_imports WHERE id=? FOR UPDATE'); $st->execute([$id]); $import=$st->fetch();
            if($import['state']!=='PREVIEW') throw new RuntimeException('This import was already committed or expired.');
            if((int)$import['preview_expired']) throw new RuntimeException('Preview expired. Use a fresh export.');
            self::requireImportSite((int)$import['branch_id']);
            if($coveredThrough<$run['period_start']||$coveredThrough>$run['period_end']||$coveredThrough>=date('Y-m-d')) throw new RuntimeException('Coverage must be a completed day within this cutoff. Today remains awaiting logs.');
            $st=db()->prepare('SELECT matching.biometric_id,matching.employee_name,matching.employee_branch_name FROM ('.self::previewMatchingSql().' WHERE r.import_id=?) matching WHERE current_match_state="SITE_MISMATCH" LIMIT 1'); $st->execute([$id]); $wrongSite=$st->fetch();
            if($wrongSite) throw new RuntimeException('Biometric ID '.$wrongSite['biometric_id'].' matches '.$wrongSite['employee_name'].' at '.($wrongSite['employee_branch_name']?:'an unassigned site').'. Choose the matching site for the export, or split the file by site before confirming.');
            $autoMapped=self::autoMapEmployeeNumbers($id,(int)$import['branch_id']);
            $st=db()->prepare('UPDATE attendance_import_rows r LEFT JOIN payroll_biometric_mappings m ON m.biometric_id=r.biometric_id SET r.employee_id=m.employee_id,r.row_state=IF(m.employee_id IS NULL,"UNMAPPED","READY") WHERE r.import_id=?'); $st->execute([$id]);
            $mismatch=db()->prepare('SELECT COUNT(*) FROM attendance_import_rows r JOIN employees e ON e.id=r.employee_id WHERE r.import_id=? AND COALESCE(e.branch_id,0)<>?'); $mismatch->execute([$id,$import['branch_id']]); if((int)$mismatch->fetchColumn()) throw new RuntimeException('A biometric ID is mapped to another site. Correct mapping or split the export.');
            $rows=db()->prepare('SELECT * FROM attendance_import_rows WHERE import_id=? ORDER BY id'); $rows->execute([$id]);
            $insert=db()->prepare('INSERT IGNORE INTO attendance_punches(employee_id,punched_at,punch_status,import_row_id) VALUES(?,?,?,?)');
            $update=db()->prepare('UPDATE attendance_import_rows SET row_state=? WHERE id=?');
            foreach($rows->fetchAll() as $r) {
                if(!$r['employee_id']) continue;
                $insert->execute([$r['employee_id'],$r['punched_at'],$r['punch_status'],$r['id']]); $update->execute([$insert->rowCount()?'IMPORTED':'DUPLICATE',$r['id']]);
            }
            db()->prepare('UPDATE attendance_imports SET state="IMPORTED",imported_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL 48 HOUR) WHERE id=?')->execute([$id]);
            db()->prepare('INSERT INTO payroll_cutoff_site_coverage(cutoff_id,branch_id,covered_through) VALUES(?,?,?) ON DUPLICATE KEY UPDATE covered_through=GREATEST(covered_through,VALUES(covered_through))')->execute([$run['id'],$import['branch_id'],$coveredThrough]);
            self::updateCoverage((int)$run['id']);
            self::rebuild((int)$run['id']);
            audit('Payroll','BIOMETRIC_IMPORT','attendance_import',$id,['cutoff_id'=>(int)$run['id'],'covered_through'=>$coveredThrough,'employee_number_mappings_created'=>$autoMapped]); db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function saveMapping(int $employeeId,string $biometric): void
    {
        Auth::requirePermission('payroll.configure'); self::requireReady();
        $biometric=trim($biometric); if(!preg_match('/^[A-Za-z0-9_-]{1,50}$/',$biometric)) throw new RuntimeException('Enter a valid biometric ID.');
        self::employee($employeeId);
        db()->beginTransaction();
        try {
            $old=db()->prepare('SELECT biometric_id FROM payroll_biometric_mappings WHERE employee_id=?'); $old->execute([$employeeId]); $existing=$old->fetchColumn();
            if($existing && $existing!==$biometric) {
                $used=db()->prepare('SELECT COUNT(*) FROM attendance_import_rows WHERE biometric_id=? AND row_state="IMPORTED"'); $used->execute([$existing]);
                if((int)$used->fetchColumn()>0) throw new RuntimeException('This biometric mapping has imported history. Keep the stable ID; contact your administrator for an audited identity migration.');
            }
            // Explicit check avoids MySQL upsert redirecting a duplicate biometric ID to another employee.
            $check=db()->prepare('SELECT employee_id FROM payroll_biometric_mappings WHERE biometric_id=? FOR UPDATE'); $check->execute([$biometric]); $owner=(int)$check->fetchColumn();
            if($owner && $owner!==$employeeId) throw new RuntimeException('That biometric ID belongs to another employee.');
            if($existing) db()->prepare('UPDATE payroll_biometric_mappings SET biometric_id=?,updated_by=? WHERE employee_id=?')->execute([$biometric,(int)Auth::user()['id'],$employeeId]);
            else db()->prepare('INSERT INTO payroll_biometric_mappings(employee_id,biometric_id,updated_by) VALUES(?,?,?)')->execute([$employeeId,$biometric,(int)Auth::user()['id']]);
            $pending=db()->prepare('SELECT r.*,i.cutoff_id,i.branch_id import_branch_id FROM attendance_import_rows r JOIN attendance_imports i ON i.id=r.import_id WHERE r.biometric_id=? AND r.row_state="UNMAPPED" AND i.state="IMPORTED"'); $pending->execute([$biometric]); $cutoffs=[];
            foreach($pending->fetchAll() as $r) {
                self::lockOpen((int)$r['cutoff_id']);
                if((int)self::employee($employeeId)['branch_id']!==(int)$r['import_branch_id']) throw new RuntimeException('This unknown ID came from a different site import. Correct the site/mapping before importing.');
                $q=db()->prepare('INSERT IGNORE INTO attendance_punches(employee_id,punched_at,punch_status,import_row_id) VALUES(?,?,?,?)'); $q->execute([$employeeId,$r['punched_at'],$r['punch_status'],$r['id']]);
                db()->prepare('UPDATE attendance_import_rows SET employee_id=?,row_state=? WHERE id=?')->execute([$employeeId,$q->rowCount()?'IMPORTED':'DUPLICATE',$r['id']]); $cutoffs[(int)$r['cutoff_id']]=true;
            }
            foreach(array_keys($cutoffs) as $c) self::rebuild($c);
            audit('Payroll','BIOMETRIC_MAPPING','employee',$employeeId); db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function saveSite(array $data): void
    {
        Auth::requirePermission('payroll.configure'); self::requireReady();
        $branch=(int)($data['branch_id']??0); $kind=(string)($data['workplace']??'');
        if(!in_array($kind,['HEAD_OFFICE','BRANCH'],true)) throw new RuntimeException('Choose Head Office or Branch.');
        $start=self::time((string)($data['shift_start']??'')); $end=self::time((string)($data['shift_end']??''));
        if($start===$end) throw new RuntimeException('Shift start and end must differ.');
        $minutes=self::number($data['break_minutes']??'',0,240);
        $days=array_values(array_unique(array_map('intval',(array)($data['workdays']??[])))); sort($days);
        if(!$days || min($days)<1 || max($days)>7) throw new RuntimeException('Select scheduled workdays.');
        $uid=(int)($data['approver_user_id']??0)?:null;
        if($kind==='BRANCH' && !$uid) throw new RuntimeException('Select the branch ADL account.');
        if($uid) { $q=db()->prepare('SELECT id FROM users WHERE id=? AND status="ACTIVE"'); $q->execute([$uid]); if(!$q->fetch()) throw new RuntimeException('Choose an active approver account.'); }
        $window=PayrollCalculator::window('2000-01-01',['shift_start'=>$start,'shift_end'=>$end]);
        if(($window[1]->getTimestamp()-$window[0]->getTimestamp())/60 <= $minutes) throw new RuntimeException('Break duration must be shorter than the shift.');
        $q=db()->prepare('INSERT INTO payroll_site_settings(branch_id,workplace,approver_user_id,shift_start,shift_end,break_minutes,workdays,updated_by) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE workplace=VALUES(workplace),approver_user_id=VALUES(approver_user_id),shift_start=VALUES(shift_start),shift_end=VALUES(shift_end),break_minutes=VALUES(break_minutes),workdays=VALUES(workdays),updated_by=VALUES(updated_by)');
        $q->execute([$branch,$kind,$uid,$start,$end,(int)$minutes,implode(',',$days),(int)Auth::user()['id']]); audit('Payroll','SITE_CONFIG','branch',$branch);
    }

    public static function saveRate(array $data): void
    {
        Auth::requirePermission('payroll.configure'); self::requireReady(); $employee=(int)($data['employee_id']??0); self::employee($employee);
        $from=self::date((string)($data['effective_from']??'')); $to=trim((string)($data['effective_to']??'')); $to=$to===''?null:self::date($to);
        if($to!==null && $to<$from) throw new RuntimeException('Rate end must be on or after its start.');
        $basis=(string)($data['salary_basis']??''); if(!in_array($basis,['HOURLY','DAILY','MONTHLY'],true)) throw new RuntimeException('Choose a salary basis.');
        $salary=self::number($data['salary_amount']??'',0.0001,100000000); $divisor=self::number($data['hourly_divisor']??'',0.0001,10000);
        if($basis==='HOURLY' && $divisor!==1.0) throw new RuntimeException('Hourly salary basis must use divisor 1.');
        db()->beginTransaction();
        try {
            db()->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE')->execute([$employee]);
            $st=db()->prepare('SELECT COUNT(*) FROM payroll_employee_rates WHERE employee_id=? AND effective_from<=COALESCE(?,"9999-12-31") AND COALESCE(effective_to,"9999-12-31")>=?'); $st->execute([$employee,$to,$from]);
            if((int)$st->fetchColumn()) throw new RuntimeException('Salary effective dates overlap. End the previous rate before adding the next one.');
            db()->prepare('INSERT INTO payroll_employee_rates(employee_id,effective_from,effective_to,salary_basis,salary_amount,hourly_divisor,created_by) VALUES(?,?,?,?,?,?,?)')->execute([$employee,$from,$to,$basis,$salary,$divisor,(int)Auth::user()['id']]);
            audit('Payroll','EMPLOYEE_RATE','employee',$employee,['effective_from'=>$from,'salary_basis'=>$basis]); db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function endRate(int $id,string $end): void
    {
        Auth::requirePermission('payroll.configure'); self::requireReady(); $end=self::date($end);
        db()->beginTransaction();
        try {
            $q=db()->prepare('SELECT * FROM payroll_employee_rates WHERE id=? FOR UPDATE'); $q->execute([$id]); $r=$q->fetch();
            if(!$r || $end<$r['effective_from']) throw new RuntimeException('Invalid rate end date.');
            db()->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE')->execute([$r['employee_id']]);
            $q=db()->prepare('SELECT COUNT(*) FROM payroll_employee_rates WHERE employee_id=? AND id<>? AND effective_from<=? AND COALESCE(effective_to,"9999-12-31")>=?'); $q->execute([$r['employee_id'],$id,$end,$r['effective_from']]);
            if((int)$q->fetchColumn()) throw new RuntimeException('This end date would overlap another salary rate.');
            db()->prepare('UPDATE payroll_employee_rates SET effective_to=? WHERE id=?')->execute([$end,$id]); audit('Payroll','END_RATE','payroll_employee_rate',$id,['effective_to'=>$end]); db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function saveRules(array $data): void
    {
        Auth::requirePermission('payroll.configure'); self::requireReady();
        $nightStart=self::time((string)($data['night_start']??'')); $nightEnd=self::time((string)($data['night_end']??''));
        if($nightStart===$nightEnd) throw new RuntimeException('Night period start and end must differ.');
        $rows=[];
        foreach(self::rules() as $type=>$existing) {
            $regular=trim((string)($data['regular_multiplier'][$type]??'')); $ot=trim((string)($data['ot_multiplier'][$type]??'')); $night=trim((string)($data['night_percent'][$type]??''));
            $rows[]=[$regular===''?null:self::number($regular,0,20),$ot===''?null:self::number($ot,0,20),$night===''?null:self::number($night,0,500),(int)Auth::user()['id'],$type];
        }
        db()->beginTransaction(); try {
            db()->prepare('UPDATE payroll_settings SET night_start=?,night_end=?,updated_by=? WHERE id=1')->execute([$nightStart,$nightEnd,(int)Auth::user()['id']]);
            $q=db()->prepare('UPDATE payroll_rate_rules SET regular_multiplier=?,ot_multiplier=?,night_percent=?,updated_by=? WHERE day_type=?'); foreach($rows as $row) $q->execute($row);
            $paytypes=db()->query('SELECT request_type_id FROM payroll_request_pay_rules')->fetchAll(PDO::FETCH_COLUMN);
            foreach($paytypes as $typeId) { $treatment=(string)($data['pay_treatment'][$typeId]??'REVIEW'); if(!in_array($treatment,['REVIEW','PAYABLE','NO_PAY'],true)) throw new RuntimeException('Invalid unpaid request treatment.'); db()->prepare('UPDATE payroll_request_pay_rules SET pay_treatment=?,updated_by=? WHERE request_type_id=?')->execute([$treatment,(int)Auth::user()['id'],$typeId]); }
            audit('Payroll','RATE_RULES'); db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function saveHoliday(array $data): void
    {
        Auth::requirePermission('payroll.configure'); self::requireReady();
        $date=self::date((string)($data['holiday_date']??'')); $type=(string)($data['day_type']??''); $name=trim((string)($data['name']??''));
        if(!in_array($type,['REGULAR_HOLIDAY','SPECIAL_HOLIDAY','DOUBLE_HOLIDAY'],true)||$name==='') throw new RuntimeException('Enter a holiday name and valid classification.');
        $branch=(int)($data['branch_id']??0)?:null;
        db()->prepare('INSERT INTO payroll_holidays(holiday_date,branch_id,day_type,name,created_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE day_type=VALUES(day_type),name=VALUES(name),created_by=VALUES(created_by)')->execute([$date,$branch,$type,mb_substr($name,0,160),(int)Auth::user()['id']]); audit('Payroll','HOLIDAY_CONFIG',null,null,['date'=>$date,'day_type'=>$type]);
    }

    public static function rules(): array { return db()->query('SELECT * FROM payroll_rate_rules ORDER BY day_type')->fetchAll(PDO::FETCH_UNIQUE); }
    public static function settings(): array { return db()->query('SELECT * FROM payroll_settings WHERE id=1')->fetch()?:[]; }

    public static function approvedRequests(int $employee,string $start,string $end): array
    {
        $q=db()->prepare('SELECT pr.*,prt.code type_code,t.time_in,t.lunch_out,t.lunch_in,t.time_out,t.ot_start,t.ot_end,ow.started_at,ow.ended_at,COALESCE(payrule.pay_treatment,"PAYABLE") pay_treatment FROM payroll_requests pr JOIN payroll_request_types prt ON prt.id=pr.request_type_id LEFT JOIN payroll_request_time_entries t ON t.request_id=pr.id LEFT JOIN payroll_request_overtime_windows ow ON ow.request_id=pr.id LEFT JOIN payroll_request_pay_rules payrule ON payrule.request_type_id=pr.request_type_id WHERE pr.employee_id=? AND pr.affected_date BETWEEN ? AND ? AND pr.status IN ("APPROVED","COMPLETED") AND EXISTS(SELECT 1 FROM payroll_request_approvals a WHERE a.request_id=pr.id AND a.approver_type IN ("ADL","MANAGER") AND a.status="APPROVED") ORDER BY pr.id');
        $q->execute([$employee,$start,$end]); return $q->fetchAll();
    }

    private static function context(int $cutoffId,?int $onlyEmployee=null): array
    {
        $run=self::run($cutoffId);
        $sql='SELECT e.*,s.workplace,s.shift_start,s.shift_end,s.break_minutes,s.workdays FROM employees e JOIN payroll_biometric_mappings m ON m.employee_id=e.id LEFT JOIN payroll_site_settings s ON s.branch_id=e.branch_id WHERE e.hire_date<=? AND (e.status IN ("ACTIVE","PROBATIONARY","ON_LEAVE") OR EXISTS (SELECT 1 FROM attendance_daily d WHERE d.employee_id=e.id AND d.cutoff_id='.(int)$cutoffId.'))';
        $args=[$run['period_end']]; if($onlyEmployee!==null) { $sql.=' AND e.id=?'; $args[]=$onlyEmployee; }
        $q=db()->prepare($sql.' ORDER BY e.id'); $q->execute($args);
        return ['run'=>$run,'employees'=>$q->fetchAll(),'rules'=>self::rules(),'settings'=>self::settings()];
    }

    public static function rebuild(int $cutoffId,?int $onlyEmployee=null): void
    {
        self::requireReady();
        $owned=!db()->inTransaction(); if($owned) db()->beginTransaction();
        try {
            $run=self::lockOpen($cutoffId); $context=self::context($cutoffId,$onlyEmployee);
            self::updateCoverage($cutoffId); $context['run']=self::run($cutoffId);
            foreach($context['employees'] as $e) self::rebuildEmployee($e,$context);
            self::rebuildBackpay($cutoffId);
            if($owned) { audit('Payroll','RECALCULATE','payroll_cutoff',$cutoffId); db()->commit(); }
        } catch(Throwable $e) { if($owned&&db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    private static function rebuildEmployee(array $e,array $context): void
    {
        $run=$context['run']; $id=(int)$e['id'];
        $q=db()->prepare('SELECT work_date FROM attendance_daily WHERE employee_id=? AND work_date BETWEEN ? AND ? AND cutoff_id<>? LIMIT 1'); $q->execute([$id,$run['period_start'],$run['period_end'],$run['id']]);
        if($q->fetchColumn()) throw new RuntimeException('This cutoff overlaps another attendance cutoff. Correct the cutoff calendar before recalculating.');
        $coverage=db()->prepare('SELECT covered_through FROM payroll_cutoff_site_coverage WHERE cutoff_id=? AND branch_id=?'); $coverage->execute([$run['id'],$e['branch_id']]); $siteCovered=$coverage->fetchColumn()?:null;
        $q=db()->prepare('SELECT * FROM payroll_daily_dispositions WHERE employee_id=? AND work_date BETWEEN ? AND ?'); $q->execute([$id,$run['period_start'],$run['period_end']]); $dispositions=[]; foreach($q->fetchAll() as $x) $dispositions[$x['work_date'].'|'.$x['source_hash']]=$x;
        $requests=self::approvedRequests($id,$run['period_start'],$run['period_end']); $grouped=[];
        foreach($requests as $r) $grouped[$r['affected_date']][]=$r;
        $q=db()->prepare('SELECT * FROM attendance_punches WHERE employee_id=? AND punched_at>=? AND punched_at<? ORDER BY punched_at,id');
        $q->execute([$id,$run['period_start'].' 00:00:00',(new DateTimeImmutable($run['period_end']))->modify('+2 days')->format('Y-m-d').' 00:00:00']); $punchDays=[];
        foreach($q->fetchAll() as $p) {
            $day=substr($p['punched_at'],0,10); $clock=substr($p['punched_at'],11);
            if(!empty($e['shift_start']) && !empty($e['shift_end']) && $e['shift_end']<$e['shift_start'] && $clock<$e['shift_start']) $day=(new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
            else {
                $previous=(new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
                foreach($grouped[$previous]??[] as $r) if(in_array($r['type_code'],['OT','POT'],true) && (($r['ended_at']??'')!=='' ? substr($r['ended_at'],0,10)===$day && $p['punched_at']<=$r['ended_at'] : (!empty($r['ot_start']) && !empty($r['ot_end']) && $r['ot_end']<=$r['ot_start'] && $clock<=$r['ot_end']))) { $day=$previous; break; }
            }
            $punchDays[$day][]=$p;
        }
        $q=db()->prepare('SELECT * FROM payroll_employee_rates WHERE employee_id=? AND effective_from<=? AND COALESCE(effective_to,"9999-12-31")>=? ORDER BY effective_from DESC'); $q->execute([$id,$run['period_end'],$run['period_start']]); $rates=$q->fetchAll();
        $q=db()->prepare('SELECT * FROM payroll_holidays WHERE holiday_date BETWEEN ? AND ? AND (branch_id IS NULL OR branch_id=?) ORDER BY branch_id IS NULL DESC'); $q->execute([$run['period_start'],(new DateTimeImmutable($run['period_end']))->modify('+1 day')->format('Y-m-d'),$e['branch_id']]); $holidays=[]; foreach($q->fetchAll() as $h) $holidays[$h['holiday_date']]=$h['day_type'];
        $q=db()->prepare('SELECT date_from,date_to FROM leave_requests WHERE employee_id=? AND status="APPROVED" AND date_from<=? AND date_to>=?'); $q->execute([$id,$run['period_end'],$run['period_start']]); $leaves=$q->fetchAll();
        $q=db()->prepare('SELECT * FROM payroll_attendance_resolutions WHERE employee_id=? AND work_date BETWEEN ? AND ?'); $q->execute([$id,$run['period_start'],$run['period_end']]); $resolutions=[]; foreach($q->fetchAll() as $r) $resolutions[$r['work_date']][$r['punch_field'].'|'.$r['source_hash']]=$r;
        $q=db()->prepare('SELECT * FROM payroll_punch_selections WHERE employee_id=? AND work_date BETWEEN ? AND ?'); $q->execute([$id,$run['period_start'],$run['period_end']]); $selections=[]; foreach($q->fetchAll() as $x) $selections[$x['work_date'].'|'.$x['source_hash']]=$x;
        $fields=['cutoff_id','employee_id','work_date','original_time_in','original_lunch_out','original_lunch_in','original_time_out','time_in','lunch_out','lunch_in','time_out','state','issues_json','sources_json','regular_minutes','late_minutes','undertime_minutes','regular_ot_minutes','night_ot_minutes','holiday_ot_minutes','holiday_night_ot_minutes','unpaid_ot_minutes','regular_amount','ot_amount','night_amount','day_type','rate_snapshot_json'];
        $updates=array_map(static fn($f)=>$f.'=VALUES('.$f.')',array_diff($fields,['employee_id','work_date']));
        $save=db()->prepare('INSERT INTO attendance_daily('.implode(',',$fields).') VALUES('.implode(',',array_fill(0,count($fields),'?')).') ON DUPLICATE KEY UPDATE '.implode(',',$updates));
        $apply=db()->prepare('INSERT INTO payroll_request_applications(request_id,application_state,note) VALUES(?,?,?) ON DUPLICATE KEY UPDATE application_state=VALUES(application_state),note=VALUES(note)');
        for($d=new DateTimeImmutable(max($run['period_start'],$e['hire_date']));$d<=new DateTimeImmutable($run['period_end']);$d=$d->modify('+1 day')) {
            $date=$d->format('Y-m-d'); $rate=null; foreach($rates as $r) if($date>=$r['effective_from'] && (empty($r['effective_to'])||$date<=$r['effective_to'])) { $rate=$r; break; }
            $leave=false; foreach($leaves as $l) if($date>=$l['date_from']&&$date<=$l['date_to']) { $leave=true; break; }
            $covered=$siteCovered && $date<=$siteCovered;
            $rawPunches=$punchDays[$date]??[]; $punchHash=hash('sha256',json_encode($rawPunches,JSON_THROW_ON_ERROR)); $effectivePunches=$rawPunches;
            $selection=$selections[$date.'|'.$punchHash]??null;
            if($selection) { $effectivePunches=[]; foreach(PayrollCalculator::FIELDS as $field) foreach($rawPunches as $p) if((int)$p['id']===(int)($selection[$field]??0)) { $p['punch_status']=strtoupper($field); $effectivePunches[]=$p; break; } }
            $calc=PayrollCalculator::calculate($date,$e,$effectivePunches,$grouped[$date]??[],$rate,$context['rules'],$context['settings'],$holidays,$resolutions[$date]??[],$covered,$leave);
            $hash=hash('sha256',json_encode([$punchDays[$date]??[],$grouped[$date]??[]],JSON_THROW_ON_ERROR));
            if($covered && isset($dispositions[$date.'|'.$hash]) && !$leave && !array_filter($grouped[$date]??[],static fn($r)=>in_array($r['type_code'],['OB','POB'],true))) {
                $attendanceIssues=['AMBIGUOUS_PUNCHES','INVALID_PUNCH_SEQUENCE','EXTRA_PUNCHES_REVIEW','LATE','UNDERTIME','EXCESS_BREAK'];
                $calc['issues']=array_values(array_filter($calc['issues'],static fn($i)=>!str_starts_with($i,'MISSING_')&&!in_array($i,$attendanceIssues,true)));
                $calc['regular_minutes']=0; $calc['regular_amount']=0; $calc['late_minutes']=0; $calc['undertime_minutes']=0;
                $calc['sources'][]=['type'=>'ZERO_CREDIT','note'=>$dispositions[$date.'|'.$hash]['remarks'],'actor'=>(int)$dispositions[$date.'|'.$hash]['recorded_by']];
                if(!$calc['issues']) $calc['state']='ZERO_CREDIT';
            }
            $calc['rate_snapshot']['attendance_source_hash']=$hash; $calc['rate_snapshot']['punch_source_hash']=$punchHash; $calc['rate_snapshot']['raw_punch_ids']=array_column($rawPunches,'id');
            if($selection) $calc['sources'][]=['type'=>'VERIFIED_PUNCH_SELECTION','actor'=>(int)$selection['recorded_by'],'note'=>$selection['remarks']];
            $calc['issues_json']=json_encode($calc['issues'],JSON_THROW_ON_ERROR); $calc['sources_json']=json_encode($calc['sources'],JSON_THROW_ON_ERROR); $calc['rate_snapshot_json']=json_encode($calc['rate_snapshot'],JSON_THROW_ON_ERROR);
            $values=['cutoff_id'=>(int)$run['id'],'employee_id'=>$id,'work_date'=>$date]+$calc; $save->execute(array_map(static fn($f)=>$values[$f],$fields));
            foreach($grouped[$date]??[] as $r) {
                $state=!$covered && empty($punchDays[$date])?'WAITING_FOR_IMPORT':($calc['state']==='ISSUES'?'NEEDS_REVIEW':'APPLIED');
                $apply->execute([$r['id'],$state,$state==='APPLIED'?'Applied to cutoff attendance.':($state==='WAITING_FOR_IMPORT'?'Approved; waiting for biometric coverage.':'Attendance issues require review.')]);
            }
        }
    }

    public static function afterApproval(int $requestId): void
    {
        if(!self::ready()) return;
        $q=db()->prepare('SELECT employee_id,cutoff_id,status FROM payroll_requests WHERE id=?'); $q->execute([$requestId]); $r=$q->fetch();
        if(!$r||!$r['cutoff_id']) return;
        $claim=self::backpayRequest($requestId);
        if($claim) { self::rebuild((int)$claim['processing_cutoff_id']); return; }
        db()->prepare('INSERT IGNORE INTO payroll_request_applications(request_id) VALUES(?)')->execute([$requestId]);
        self::rebuild((int)$r['cutoff_id'],(int)$r['employee_id']);
        if(!in_array($r['status'],['APPROVED','COMPLETED'],true)) db()->prepare('UPDATE payroll_request_applications SET application_state=?,note="Request has not completed final approval." WHERE request_id=?')->execute([$r['status']==='REJECTED'?'REJECTED':'AWAITING_APPROVAL',$requestId]);
    }

    public static function backpayRequest(int $requestId): ?array
    {
        $q=db()->prepare('SELECT bc.*,c.period_start payout_start,c.period_end payout_end FROM payroll_backpay_claims bc JOIN payroll_cutoffs c ON c.id=bc.processing_cutoff_id WHERE bc.request_id=?'); $q->execute([$requestId]); return $q->fetch()?:null;
    }

    public static function backpayRows(int $cutoffId): array
    {
        $q=db()->prepare('SELECT bc.*,pr.request_no,pr.affected_date,pr.employee_id,pr.status request_status,t.code type_code,e.employee_no,CONCAT_WS(" ",e.first_name,e.last_name) employee_name FROM payroll_backpay_claims bc JOIN payroll_requests pr ON pr.id=bc.request_id JOIN payroll_request_types t ON t.id=pr.request_type_id JOIN employees e ON e.id=pr.employee_id WHERE bc.processing_cutoff_id=? ORDER BY pr.affected_date,pr.id'); $q->execute([$cutoffId]); return $q->fetchAll();
    }

    private static function rebuildBackpay(int $cutoffId): void
    {
        $save=db()->prepare('UPDATE payroll_backpay_claims SET amount=?,state=?,note=?,snapshot_json=?,computed_at=NOW() WHERE request_id=?');
        $application=db()->prepare('INSERT INTO payroll_request_applications(request_id,application_state,note) VALUES(?,?,?) ON DUPLICATE KEY UPDATE application_state=VALUES(application_state),note=VALUES(note)');
        foreach(self::backpayRows($cutoffId) as $claim) {
            $amount=null; $state='AWAITING_APPROVAL'; $note='Waiting for final Manager/ADL approval.'; $snapshot=null;
            if(in_array($claim['request_status'],['REJECTED','CANCELLED'],true)) { $state='REJECTED'; $amount=0; $note='Claim was rejected/cancelled.'; }
            elseif(in_array($claim['request_status'],['APPROVED','COMPLETED'],true)) {
                $all=self::approvedRequests((int)$claim['employee_id'],$claim['affected_date'],$claim['affected_date']);
                $request=null; foreach($all as $r) if((int)$r['id']===(int)$claim['request_id']) { $request=$r; break; }
                $q=db()->prepare('SELECT s.snapshot_json FROM payroll_cutoff_snapshots s JOIN payroll_requests pr ON pr.cutoff_id=s.cutoff_id WHERE pr.id=? AND s.employee_id=pr.employee_id AND s.work_date=pr.affected_date'); $q->execute([$claim['request_id']]); $raw=$q->fetchColumn();
                if(!$request || !$raw) { $state='NEEDS_REVIEW'; $note='Historical finalized attendance snapshot is unavailable.'; }
                else {
                    $historical=json_decode((string)$raw,true)?:[]; $config=json_decode($historical['rate_snapshot_json']??'{}',true)?:[];
                    $site=$config['site']??[]; $site['break_minutes']=$site['break_minutes']??null;
                    $rules=$config['rules']??self::rules(); $current=self::rules();
                    foreach($current as $type=>$r) foreach(['regular_multiplier','ot_multiplier','night_percent'] as $field) if(($rules[$type][$field]??null)===null) $rules[$type][$field]=$r[$field];
                    $punches=[];
                    foreach(PayrollCalculator::FIELDS as $f) if(!empty($historical[$f])) $punches[]=['punch_status'=>strtoupper($f),'punched_at'=>$historical[$f]];
                    $holidayType=(string)($historical['day_type']??'REGULAR'); $holidays=[];
                    if(!in_array($holidayType,['REGULAR','REST_DAY'],true)) $holidays[$claim['affected_date']]=str_replace('_REST_DAY','',$holidayType);
                    $q=db()->prepare('SELECT holiday_date,day_type FROM payroll_holidays WHERE holiday_date=? AND (branch_id IS NULL OR branch_id=?) ORDER BY branch_id IS NULL DESC'); $q->execute([(new DateTimeImmutable($claim['affected_date']))->modify('+1 day')->format('Y-m-d'),$site['branch_id']??0]); foreach($q->fetchAll() as $h) $holidays[$h['holiday_date']]=$h['day_type'];
                    $creditedWindows=[];
                    if($claim['type_code']==='POT') {
                        $sourceIds=array_column(json_decode($historical['sources_json']??'[]',true)?:[],'id');
                        $q=db()->prepare('SELECT bc.request_id FROM payroll_backpay_claims bc JOIN payroll_requests pr ON pr.id=bc.request_id JOIN payroll_request_types t ON t.id=pr.request_type_id JOIN payroll_cutoff_runs cr ON cr.cutoff_id=bc.processing_cutoff_id WHERE pr.employee_id=? AND pr.affected_date=? AND t.code="POT" AND cr.state="FINALIZED" AND bc.state="READY"'); $q->execute([$claim['employee_id'],$claim['affected_date']]); $sourceIds=array_merge($sourceIds,$q->fetchAll(PDO::FETCH_COLUMN));
                        foreach($all as $prior) if(in_array((int)$prior['id'],array_map('intval',$sourceIds),true) && in_array($prior['type_code'],['OT','POT'],true) && !empty($prior['ot_start']) && !empty($prior['ot_end']) && !empty($site['shift_start'])) {
                            $a=$prior['started_at']??PayrollCalculator::correctionTime($claim['affected_date'],$prior['ot_start'],$site); $b=$prior['ended_at']??PayrollCalculator::correctionTime($claim['affected_date'],$prior['ot_end'],$site);
                            if($b<=$a) $b=(new DateTimeImmutable($b))->modify('+1 day')->format('Y-m-d H:i:s'); $creditedWindows[]=[$a,$b];
                        }
                        $request['credited_windows']=$creditedWindows;
                    }
                    $night=$config['night_period']??self::settings();
                    if(empty($night['night_start'])||empty($night['night_end'])) $night=self::settings();
                    $calc=PayrollCalculator::calculate($claim['affected_date'],$site,$punches,[$request],$config['rate']??null,$rules,$night,$holidays,[],true,($historical['state']??'')==='APPROVED_LEAVE');
                    $blocking=array_filter($calc['issues'],static fn($i)=>!in_array($i,['LATE','UNDERTIME','EXCESS_BREAK'],true));
                    if($blocking || $calc['regular_amount']===null || $calc['ot_amount']===null || $calc['night_amount']===null) { $state='NEEDS_REVIEW'; $note=mb_substr(implode(', ',$blocking)?:'Historical rate configuration is incomplete.',0,255); }
                    else {
                        if($claim['type_code']==='POB') {
                            $q=db()->prepare('SELECT COALESCE(SUM(bc.amount),0) FROM payroll_backpay_claims bc JOIN payroll_requests pr ON pr.id=bc.request_id JOIN payroll_request_types t ON t.id=pr.request_type_id JOIN payroll_cutoff_runs cr ON cr.cutoff_id=bc.processing_cutoff_id WHERE pr.employee_id=? AND pr.affected_date=? AND t.code="POB" AND cr.state="FINALIZED" AND bc.state="READY"'); $q->execute([$claim['employee_id'],$claim['affected_date']]);
                            $amount=max(0,(float)$calc['regular_amount']-(float)($historical['regular_amount']??0)-(float)$q->fetchColumn());
                        } else $amount=(float)$calc['ot_amount']+(float)$calc['night_amount'];
                        $amount=round($amount,2); $state=$amount>0?'READY':'NO_BALANCE'; $note=$amount>0?'Incremental claim queued for this payout cutoff.':'No additional balance after previously credited attendance/OT.';
                    }
                    $snapshot=json_encode(['historical_cutoff_id'=>$historical['cutoff_id']??null,'historical_attendance_id'=>$historical['id']??null,'calculation'=>$calc,'already_credited_windows'=>$creditedWindows,'amount'=>$amount],JSON_THROW_ON_ERROR);
                }
            }
            $save->execute([$amount,$state,$note,$snapshot,$claim['request_id']]);
            $application->execute([$claim['request_id'],$state,$note]);
        }
    }

    public static function dailyRows(int $cutoffId,?int $employee=null,string $state=''): array
    {
        $sql='SELECT d.*,e.employee_no,CONCAT_WS(" ",e.first_name,e.last_name) employee_name,b.name branch_name FROM attendance_daily d JOIN employees e ON e.id=d.employee_id LEFT JOIN branches b ON b.id=e.branch_id WHERE d.cutoff_id=?'; $params=[$cutoffId];
        if($employee!==null) { $sql.=' AND d.employee_id=?'; $params[]=$employee; }
        if($state==='ISSUES') $sql.=' AND d.state IN ("ISSUES","AWAITING_LOGS")';
        $q=db()->prepare($sql.' ORDER BY e.last_name,e.first_name,d.work_date'); $q->execute($params); return $q->fetchAll();
    }

    public static function daily(int $id): array
    {
        $q=db()->prepare('SELECT d.*,e.employee_no,CONCAT_WS(" ",e.first_name,e.last_name) employee_name FROM attendance_daily d JOIN employees e ON e.id=d.employee_id WHERE d.id=?'); $q->execute([$id]); $r=$q->fetch(); if(!$r) throw new RuntimeException('Attendance record not found.'); return $r;
    }

    public static function detail(int $id): array
    {
        $d=self::daily($id); $context=self::context((int)$d['cutoff_id'],(int)$d['employee_id']);
        $q=db()->prepare('SELECT p.* FROM attendance_punches p WHERE p.employee_id=? AND p.punched_at BETWEEN ? AND ? ORDER BY p.punched_at');
        $q->execute([$d['employee_id'],$d['work_date'].' 00:00:00',(new DateTimeImmutable($d['work_date']))->modify('+1 day')->format('Y-m-d').' 23:59:59']);
        $d['punches']=$q->fetchAll();
        $d['requests']=self::approvedRequests((int)$d['employee_id'],$d['work_date'],$d['work_date']);
        $d['conflicts']=[];
        // Stored snapshots contain every raw/approved source; recompute conflict fingerprints without mutations.
        if($context['employees']) {
            $site=$context['employees'][0]; $proposals=[];
            if(!empty($site['shift_start'])&&!empty($site['shift_end'])) {
                foreach($d['requests'] as $r) if(in_array($r['type_code'],['TA','PTA'],true)) foreach(PayrollCalculator::FIELDS as $f) if(!empty($r[$f])) $proposals[$f][]=['request_id'=>(int)$r['id'],'value'=>PayrollCalculator::correctionTime($d['work_date'],$r[$f],$site)];
                foreach($proposals as $f=>$list) if(in_array('CORRECTION_CONFLICT_'.strtoupper($f),json_decode($d['issues_json'],true)?:[],true)) $d['conflicts'][$f]=['raw'=>$d['original_'.$f],'proposals'=>$list,'hash'=>hash('sha256',json_encode([$d['original_'.$f],$list],JSON_THROW_ON_ERROR))];
            }
        }
        return $d;
    }

    public static function resolve(int $dailyId,string $field,string $hash,int $requestId,string $remarks): void
    {
        Auth::requirePermission('payroll.process'); self::requireReady(); $remarks=trim($remarks);
        if($remarks==='') throw new RuntimeException('Explain the conflict resolution.');
        db()->beginTransaction(); try {
            $day=self::daily($dailyId); self::lockOpen((int)$day['cutoff_id']); self::rebuild((int)$day['cutoff_id'],(int)$day['employee_id']); $day=self::detail($dailyId);
            $c=$day['conflicts'][$field]??null;
            if(!$c || !hash_equals($c['hash'],$hash)) throw new RuntimeException('Attendance sources changed. Refresh and review the current conflict.');
            if($requestId && !in_array($requestId,array_column($c['proposals'],'request_id'),true)) throw new RuntimeException('Choose a correction from this attendance day.');
            db()->prepare('INSERT INTO payroll_attendance_resolutions(employee_id,work_date,punch_field,source_hash,request_id,remarks,resolved_by) VALUES(?,?,?,?,?,?,?)')->execute([$day['employee_id'],$day['work_date'],$field,$hash,$requestId?:null,mb_substr($remarks,0,255),(int)Auth::user()['id']]);
            self::rebuild((int)$day['cutoff_id'],(int)$day['employee_id']); audit('Payroll','RESOLVE_CONFLICT','attendance_daily',$dailyId,['field'=>$field,'request_id'=>$requestId?:null]); db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function saveDeadline(int $cutoffId,string $deadline): void
    {
        Auth::requirePermission('payroll.process'); $deadline=trim($deadline);
        if($deadline!=='') { $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$deadline); if(!$d || $d->format('Y-m-d\TH:i')!==$deadline) throw new RuntimeException('Invalid ticket deadline.'); $deadline=$d->format('Y-m-d H:i:s'); }
        db()->beginTransaction(); try { self::lockOpen($cutoffId); db()->prepare('UPDATE payroll_cutoff_runs SET ticket_deadline=? WHERE cutoff_id=?')->execute([$deadline?:null,$cutoffId]); audit('Payroll','TICKET_DEADLINE','payroll_cutoff',$cutoffId,['deadline'=>$deadline?:null]); db()->commit(); }
        catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function zeroCredit(int $dailyId,string $remarks,string $expectedHash): void
    {
        Auth::requirePermission('payroll.process'); self::requireReady(); $remarks=trim($remarks);
        if($remarks==='') throw new RuntimeException('Record the verified reason for zero attendance credit.');
        db()->beginTransaction();
        try {
            $d=self::daily($dailyId); self::lockOpen((int)$d['cutoff_id']); self::rebuild((int)$d['cutoff_id'],(int)$d['employee_id']); $d=self::daily($dailyId);
            $e=self::employee((int)$d['employee_id']); $q=db()->prepare('SELECT covered_through FROM payroll_cutoff_site_coverage WHERE cutoff_id=? AND branch_id=?'); $q->execute([$d['cutoff_id'],$e['branch_id']]); $through=$q->fetchColumn();
            if(!$through||$d['work_date']>$through) throw new RuntimeException('Import complete site coverage before recording a zero-credit disposition.');
            foreach(self::approvedRequests((int)$d['employee_id'],$d['work_date'],$d['work_date']) as $r) if(in_array($r['type_code'],['OB','POB'],true)) throw new RuntimeException('This date has approved Official Business coverage. Resolve its tickets before recording zero credit.');
            if($d['state']==='APPROVED_LEAVE') throw new RuntimeException('This date has approved leave coverage.');
            $snapshot=json_decode($d['rate_snapshot_json'],true)?:[]; $hash=$snapshot['attendance_source_hash']??null;
            if(!$hash) throw new RuntimeException('Recalculate this attendance day first.');
            if(!hash_equals($hash,$expectedHash)) throw new RuntimeException('Attendance sources changed. Refresh this day before recording zero credit.');
            db()->prepare('INSERT INTO payroll_daily_dispositions(employee_id,work_date,source_hash,remarks,recorded_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE remarks=VALUES(remarks),recorded_by=VALUES(recorded_by),recorded_at=NOW()')->execute([$d['employee_id'],$d['work_date'],$hash,mb_substr($remarks,0,255),(int)Auth::user()['id']]);
            self::rebuild((int)$d['cutoff_id'],(int)$d['employee_id']); audit('Payroll','ZERO_CREDIT_DISPOSITION','attendance_daily',$dailyId,['reason'=>mb_substr($remarks,0,255)]); db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function clearZeroCredit(int $dailyId,string $remarks,string $expectedHash): void
    {
        Auth::requirePermission('payroll.process'); self::requireReady(); $remarks=trim($remarks);
        if($remarks==='') throw new RuntimeException('Explain why zero attendance credit is being withdrawn.');
        db()->beginTransaction();
        try {
            $d=self::daily($dailyId); self::lockOpen((int)$d['cutoff_id']); self::rebuild((int)$d['cutoff_id'],(int)$d['employee_id']); $d=self::daily($dailyId);
            $snapshot=json_decode($d['rate_snapshot_json'],true)?:[]; $hash=$snapshot['attendance_source_hash']??'';
            if(!$hash || !hash_equals($hash,$expectedHash)) throw new RuntimeException('Attendance sources changed. Refresh the day before withdrawing zero credit.');
            $q=db()->prepare('SELECT * FROM payroll_daily_dispositions WHERE employee_id=? AND work_date=? AND source_hash=? FOR UPDATE'); $q->execute([$d['employee_id'],$d['work_date'],$hash]); $old=$q->fetch();
            if(!$old) throw new RuntimeException('No current zero-credit disposition exists for this day.');
            db()->prepare('DELETE FROM payroll_daily_dispositions WHERE employee_id=? AND work_date=? AND source_hash=?')->execute([$d['employee_id'],$d['work_date'],$hash]);
            audit('Payroll','WITHDRAW_ZERO_CREDIT','attendance_daily',$dailyId,['prior_reason'=>$old['remarks'],'prior_actor'=>$old['recorded_by'],'reason'=>mb_substr($remarks,0,255)]);
            self::rebuild((int)$d['cutoff_id'],(int)$d['employee_id']); db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function selectPunches(int $dailyId,array $data): void
    {
        Auth::requirePermission('payroll.process'); self::requireReady(); $remarks=trim((string)($data['remarks']??''));
        if($remarks==='') throw new RuntimeException('Explain the verified punch selection.');
        db()->beginTransaction();
        try {
            $d=self::daily($dailyId); self::lockOpen((int)$d['cutoff_id']); self::rebuild((int)$d['cutoff_id'],(int)$d['employee_id']); $d=self::daily($dailyId);
            $snapshot=json_decode($d['rate_snapshot_json'],true)?:[]; $allowed=array_map('intval',$snapshot['raw_punch_ids']??[]); $hash=$snapshot['punch_source_hash']??'';
            if(!$hash||!$allowed) throw new RuntimeException('No raw punches are available for this duty day. Use an approved TA for missing times.');
            if(!hash_equals($hash,(string)($data['source_hash']??''))) throw new RuntimeException('Biometric sources changed. Refresh this day and review all current punches.');
            $chosen=[];
            foreach(PayrollCalculator::FIELDS as $field) { $value=(int)($data[$field]??0); if($value && !in_array($value,$allowed,true)) throw new RuntimeException('Choose only a biometric punch from this duty day.'); $chosen[$field]=$value?:null; }
            $ids=array_values(array_filter($chosen)); if(count($ids)!==count(array_unique($ids)) || !$ids) throw new RuntimeException('Use each actual punch at most once.');
            db()->prepare('INSERT INTO payroll_punch_selections(employee_id,work_date,source_hash,time_in,lunch_out,lunch_in,time_out,remarks,recorded_by) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE time_in=VALUES(time_in),lunch_out=VALUES(lunch_out),lunch_in=VALUES(lunch_in),time_out=VALUES(time_out),remarks=VALUES(remarks),recorded_by=VALUES(recorded_by),recorded_at=NOW()')->execute([$d['employee_id'],$d['work_date'],$hash,$chosen['time_in'],$chosen['lunch_out'],$chosen['lunch_in'],$chosen['time_out'],mb_substr($remarks,0,255),(int)Auth::user()['id']]);
            self::rebuild((int)$d['cutoff_id'],(int)$d['employee_id']); audit('Payroll','VERIFY_PUNCH_SELECTION','attendance_daily',$dailyId,['punch_ids'=>$chosen]); db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function finalize(int $cutoffId): void
    {
        Auth::requirePermission('payroll.finalize'); self::requireReady(); db()->beginTransaction();
        try {
            $run=self::lockOpen($cutoffId); self::rebuild($cutoffId); $run=self::run($cutoffId);
            if(empty($run['covered_through'])||$run['covered_through']<$run['period_end']) throw new RuntimeException('Import complete biometric coverage through the cutoff end before finalizing.');
            $q=db()->prepare('SELECT COUNT(*) FROM employees e LEFT JOIN payroll_biometric_mappings m ON m.employee_id=e.id WHERE e.status IN ("ACTIVE","PROBATIONARY","ON_LEAVE") AND e.hire_date<=? AND m.employee_id IS NULL'); $q->execute([$run['period_end']]);
            if((int)$q->fetchColumn()) throw new RuntimeException('Map all active employees to biometric IDs before finalizing.');
            $q=db()->prepare('SELECT COUNT(*) FROM attendance_import_rows r JOIN attendance_imports i ON i.id=r.import_id WHERE i.cutoff_id=? AND i.state="IMPORTED" AND r.row_state="UNMAPPED"'); $q->execute([$cutoffId]); if((int)$q->fetchColumn()) throw new RuntimeException('Resolve unknown biometric IDs first.');
            $q=db()->prepare('SELECT COUNT(*) FROM payroll_requests pr LEFT JOIN payroll_backpay_claims bc ON bc.request_id=pr.id WHERE (pr.cutoff_id=? OR bc.processing_cutoff_id=?) AND pr.status NOT IN ("APPROVED","COMPLETED","REJECTED","CANCELLED")'); $q->execute([$cutoffId,$cutoffId]); if((int)$q->fetchColumn()) throw new RuntimeException('Resolve pending/returned tickets before finalizing.');
            $q=db()->prepare('SELECT COUNT(*) FROM payroll_backpay_claims WHERE processing_cutoff_id=? AND state NOT IN ("READY","NO_BALANCE","REJECTED")'); $q->execute([$cutoffId]); if((int)$q->fetchColumn()) throw new RuntimeException('Resolve back-pay claim issues before finalizing.');
            $q=db()->prepare('SELECT COUNT(*) FROM attendance_daily WHERE cutoff_id=? AND (state IN ("ISSUES","AWAITING_LOGS") OR regular_amount IS NULL OR ot_amount IS NULL OR night_amount IS NULL)'); $q->execute([$cutoffId]); if((int)$q->fetchColumn()) throw new RuntimeException('Resolve attendance issues and missing computation settings before finalizing.');
            $q=db()->prepare('SELECT * FROM attendance_daily WHERE cutoff_id=? ORDER BY id'); $q->execute([$cutoffId]); $rows=$q->fetchAll(); if(!$rows) throw new RuntimeException('No attendance records to finalize.');
            $save=db()->prepare('INSERT INTO payroll_cutoff_snapshots(cutoff_id,employee_id,work_date,snapshot_json) VALUES(?,?,?,?)'); foreach($rows as $r) $save->execute([$cutoffId,$r['employee_id'],$r['work_date'],json_encode($r,JSON_THROW_ON_ERROR)]);
            db()->prepare('UPDATE payroll_cutoff_runs SET state="FINALIZED",finalized_at=NOW(),finalized_by=? WHERE cutoff_id=?')->execute([(int)Auth::user()['id'],$cutoffId]); audit('Payroll','FINALIZE_CUTOFF','payroll_cutoff',$cutoffId,['records'=>count($rows)]); db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    public static function purgeExpired(): int
    {
        if(!self::ready() || db()->inTransaction()) return 0;
        $ids=db()->query('SELECT id FROM attendance_imports WHERE expires_at<=NOW() AND deleted_at IS NULL ORDER BY expires_at LIMIT 200')->fetchAll(PDO::FETCH_COLUMN);
        $count=0; $dir=self::uploadDir();
        foreach($ids as $id) {
            db()->beginTransaction();
            try {
                $q=db()->prepare('SELECT * FROM attendance_imports WHERE id=? AND expires_at<=NOW() AND deleted_at IS NULL FOR UPDATE'); $q->execute([$id]); $r=$q->fetch();
                if(!$r || ($r['stored_name']&&!preg_match('/^bio_[a-f0-9]{32}\.(pdf|csv|xlsx)$/',$r['stored_name']))) { db()->rollBack(); continue; }
                $path=$dir.'/'.$r['stored_name'];
                if($r['stored_name'] && is_file($path) && !@unlink($path)) { db()->rollBack(); continue; }
                db()->prepare('UPDATE attendance_imports SET stored_name=NULL,deleted_at=NOW(),state=IF(state="PREVIEW","EXPIRED",state) WHERE id=?')->execute([$id]);
                if($r['state']==='PREVIEW') db()->prepare('DELETE FROM attendance_import_rows WHERE import_id=?')->execute([$id]);
                audit('Payroll','EXPIRED_UPLOAD_CLEANUP','attendance_import',(int)$id); db()->commit(); $count++;
            } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
        }
        return $count;
    }

    private static function uploadDir(): string { return dirname(__DIR__).'/storage/biometric_uploads'; }
    public static function employee(int $id): array { $q=db()->prepare('SELECT * FROM employees WHERE id=?'); $q->execute([$id]); $r=$q->fetch(); if(!$r) throw new RuntimeException('Employee not found.'); return $r; }
    public static function date(string $v): string { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v); if(!$d||$d->format('Y-m-d')!==$v) throw new RuntimeException('Invalid date.'); return $v; }
    public static function time(string $v): string { if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',$v)) throw new RuntimeException('Invalid time.'); return strlen($v)===5?$v.':00':$v; }
    private static function number(mixed $v,float $min,float $max): float { if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<$min||(float)$v>$max) throw new RuntimeException('Enter a numeric value between '.$min.' and '.$max.'.'); return (float)$v; }
}
