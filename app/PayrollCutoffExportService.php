<?php
declare(strict_types=1);
require_once __DIR__.'/EmployeeDirectoryHierarchy.php';
require_once __DIR__.'/EmployeeDirectoryService.php';

/** One complete, immutable cutoff shared by every download format. */
final class PayrollCutoffExportService
{
    public static function ready(): bool
    {
        foreach(['payroll_cutoff_export_batches','payroll_cutoff_day_reviews','payroll_cutoff_export_payments'] as $table)
            if(!FoundationRepository::tableExists($table)) return false;
        return true;
    }

    public static function requireReady(): void
    {
        PayrollAttendanceService::requireReady();
        if(!self::ready()) throw new RuntimeException('Import 20261011_payroll_cutoff_exports.sql into the existing HRIS database first.');
    }

    private static function roster(int $cutoffId): array
    {
        $hasArea=FoundationRepository::tableExists('areas') && FoundationRepository::columnExists('branches','area_id');
        $ho='(UPPER(COALESCE(b.code,"")) IN ("HO","HEAD_OFFICE","HEAD-OFFICE","SW_HO","SWHO","OC_HO","OCHO","OCAMPOS_HO","OCAMPOS_HEAD_OFFICE") OR UPPER(COALESCE(b.name,"")) LIKE "%HEAD OFFICE%")';
        if(FoundationRepository::columnExists('branches','site_type')) $ho='('.$ho.' OR b.site_type="HEAD_OFFICE")';
        $roster=FoundationRepository::columnExists('employees','roster_group')?'UPPER(COALESCE(e.roster_group,""))':'""';
        $workplace='CASE WHEN '.$ho.' THEN "HO" WHEN '.($hasArea?'b.area_id IS NOT NULL':'0=1').' OR '.$roster.'="RETAIL" THEN "RETAIL" WHEN '.$roster.' IN ("HO","HEAD OFFICE") THEN "HO" ELSE "UNASSIGNED" END';
        $placement=EmployeeDirectoryPlacement::expressions($workplace,$hasArea);
        $company='COALESCE(c.name,"")'; $joins='';
        if(FoundationRepository::tableExists('business_units') && FoundationRepository::columnExists('employees','business_unit_id')) {
            $joins.=' LEFT JOIN business_units bu ON bu.id=e.business_unit_id'; $company='COALESCE(bu.name,c.name,"")';
        }
        if(FoundationRepository::tableExists('legal_entities') && FoundationRepository::columnExists('employees','legal_entity_id')) {
            $joins.=' LEFT JOIN legal_entities le ON le.id=e.legal_entity_id'; $company='CASE WHEN ('.$workplace.')="HO" THEN COALESCE(le.name,'.$company.') ELSE '.$company.' END';
        }
        $q=db()->prepare('SELECT e.*,CONCAT_WS(" ",e.first_name,e.last_name) employee_name,COALESCE(b.code,"") branch_code,COALESCE(b.name,"Not assigned") branch_name,COALESCE(d.name,"Not assigned") department_name,COALESCE(p.name,"Not assigned") position_name,'.$company.' company_name,('.$workplace.') export_workplace,('.$placement['company'].') export_company,('.$placement['role'].') export_role,'.($hasArea?'COALESCE(a.code,"") area_code,COALESCE(a.name,"Area to assign") area_name':'"" area_code,"Area to assign" area_name').' FROM employees e LEFT JOIN branches b ON b.id=e.branch_id LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN positions p ON p.id=e.position_id LEFT JOIN clients c ON c.id=b.client_id'.($hasArea?' LEFT JOIN areas a ON a.id=b.area_id':'').$joins.' WHERE '.PayrollAttendanceService::cutoffEmployeeScope($cutoffId).' ORDER BY e.last_name,e.first_name,e.id');
        $q->execute(); $result=[];
        foreach($q->fetchAll() as $e) {
            $dept=EmployeeDirectoryHierarchy::department(EmployeeDirectoryService::departmentLabel($e['department_name']));
            $main=EmployeeDirectoryHierarchy::groups()[$dept['main']]['name']??$dept['name'];
            if($dept['main']==='MARKETING') $dept['name']=$main;
            $prefix=$e['export_company']==='OC'?"Ocampo's":($e['export_company']==='SW'?'Sw':'Company to assign');
            $e['export_group']=$e['export_workplace']==='HO'?'Head Office '.$prefix:$prefix.($e['export_workplace']==='RETAIL'?' Retail':' / To assign');
            $e['export_department']=$dept['name']; $e['export_main_department']=$main;
            if($e['export_role']==='DEPARTMENT_MANAGER') $e['export_department']='Department Managers';
            if($e['export_role']==='ADL') $e['export_department']='ADL';
            $e['export_section']=$e['export_group'].' / '.($e['export_workplace']==='RETAIL'?$e['area_name'].' / '.$e['branch_code']:$e['export_department']);
            if($e['export_workplace']==='UNASSIGNED') $e['export_section'].=' / '.$e['export_department'];
            $result[(int)$e['id']]=$e;
        }
        return $result;
    }

    public static function batch(int $cutoffId): ?array
    {
        if(!self::ready()) return null;
        $q=db()->prepare('SELECT * FROM payroll_cutoff_export_batches WHERE cutoff_id=?'); $q->execute([$cutoffId]); return $q->fetch()?:null;
    }

    public static function model(int $cutoffId): array
    {
        self::requireReady(); $run=PayrollAttendanceService::run($cutoffId); $batch=self::batch($cutoffId);
        if($batch) return self::saved($cutoffId)+['run'=>$run,'batch'=>$batch,'pending'=>0,'unmapped'=>0,'unapplied'=>0,'pending_leave'=>0,'problems'=>[],'calendar'=>[]];
        $roster=self::roster($cutoffId); $q=db()->prepare('SELECT * FROM attendance_daily WHERE cutoff_id=?');$q->execute([$cutoffId]);$days=[];
        foreach($q->fetchAll() as $d) $days[(int)$d['employee_id']][$d['work_date']]=$d;
        $q=db()->prepare('SELECT * FROM payroll_cutoff_day_reviews WHERE cutoff_id=?');$q->execute([$cutoffId]);$reviews=[];
        foreach($q->fetchAll() as $r) $reviews[$r['employee_id'].'|'.$r['work_date']]=$r;
        $evidence=[];$q=db()->prepare('SELECT DISTINCT employee_id,DATE(punched_at) punch_date FROM attendance_punches WHERE punched_at>=? AND punched_at<?');
        $q->execute([$run['period_start'].' 00:00:00',(new DateTimeImmutable($run['period_end']))->modify('+2 days')->format('Y-m-d').' 00:00:00']);foreach($q->fetchAll() as $p)$evidence[$p['employee_id'].'|'.$p['punch_date']]=true;
        $rows=[];$problems=[];$calendar=[];
        foreach($roster as $id=>$e) for($date=new DateTimeImmutable($run['period_start']);$date<=new DateTimeImmutable($run['period_end']);$date=$date->modify('+1 day')) {
            $workDate=$date->format('Y-m-d');$d=$days[$id][$workDate]??null;
            if(!$d) $d=['id'=>0,'employee_id'=>$id,'cutoff_id'=>$cutoffId,'work_date'=>$workDate,'state'=>'AWAITING_LOGS','issues_json'=>'[]','sources_json'=>'[]','rate_snapshot_json'=>'{}','regular_minutes'=>0,'late_minutes'=>0,'undertime_minutes'=>0,'regular_ot_minutes'=>0,'night_ot_minutes'=>0,'holiday_ot_minutes'=>0,'holiday_night_ot_minutes'=>0]+array_fill_keys(array_merge(PayrollCalculator::FIELDS,array_map(static fn($f)=>'original_'.$f,PayrollCalculator::FIELDS)),null);
            $hash=json_decode($d['rate_snapshot_json'],true)['attendance_source_hash']??'';
            if(!$d['id']) $hash=hash('sha256','empty|'.$cutoffId.'|'.$id.'|'.$workDate);
            $d['export_source_hash']=$hash;
            $review=$reviews[$id.'|'.$workDate]??null;
            if($d['state']==='AWAITING_LOGS' && $review && hash_equals($review['source_hash'],$hash)) {
                $d['state']=$review['classification'];$d['export_note']=$review['reason'];$d['calendar_review']=$review;
            }
            if(!empty($e['hire_date']) && $workDate<$e['hire_date']) {
                if(isset($evidence[$id.'|'.$workDate]) || array_filter(array_intersect_key($d,array_flip(PayrollCalculator::FIELDS))) || $d['state']==='APPROVED_LEAVE' || $d['state']==='APPROVED_OB') $d['state']='ISSUES';
                else $d['state']='NOT_EMPLOYED';
            }
            $d['_employee']=$e;
            // OB does not excuse unknown clock fields in a complete attendance export.
            if(in_array($d['state'],['COMPLETE','CORRECTED','APPROVED_OB'],true)) foreach(PayrollCalculator::FIELDS as $f) if(empty($d[$f])) {$d['state']='ISSUES';break;}
            if($d['state']==='AWAITING_LOGS') $calendar[]=$d;
            elseif($d['state']==='ISSUES') $problems[]=$d;
            $rows[]=$d;
        }
        $q=db()->prepare('SELECT COUNT(*) FROM payroll_requests pr LEFT JOIN payroll_backpay_claims bc ON bc.request_id=pr.id WHERE (pr.cutoff_id=? OR bc.processing_cutoff_id=?) AND pr.status NOT IN ("APPROVED","COMPLETED","REJECTED","CANCELLED","DRAFT")');$q->execute([$cutoffId,$cutoffId]);$pending=(int)$q->fetchColumn();
        $q=db()->prepare('SELECT COUNT(*) FROM leave_requests WHERE date_from<=? AND date_to>=? AND status NOT IN ("APPROVED","REJECTED","CANCELLED","DRAFT")');$q->execute([$run['period_end'],$run['period_start']]);$pendingLeave=(int)$q->fetchColumn();
        $q=db()->prepare('SELECT COUNT(*) FROM attendance_import_rows r JOIN attendance_imports i ON i.id=r.import_id WHERE i.cutoff_id=? AND i.state="IMPORTED" AND r.row_state="UNMAPPED"');$q->execute([$cutoffId]);$unknown=(int)$q->fetchColumn();
        $q=db()->prepare('SELECT COUNT(*) FROM payroll_requests pr JOIN payroll_request_types t ON t.id=pr.request_type_id LEFT JOIN payroll_backpay_claims bc ON bc.request_id=pr.id LEFT JOIN payroll_request_applications pa ON pa.request_id=pr.id WHERE pr.cutoff_id=? AND bc.request_id IS NULL AND t.code IN ("TA","PTA","OB","POB","OT","POT") AND pr.status IN ("APPROVED","COMPLETED") AND COALESCE(pa.application_state,"")<>"APPLIED"');$q->execute([$cutoffId]);$unapplied=(int)$q->fetchColumn();
        return ['run'=>$run,'batch'=>null,'employees'=>$roster,'rows'=>$rows,'pending'=>$pending,'pending_leave'=>$pendingLeave,'unmapped'=>$unknown,'unapplied'=>$unapplied,'problems'=>$problems,'calendar'=>$calendar];
    }

    public static function reviewCalendar(int $cutoffId,array $ids,array $hashes,string $classification,string $reason): void
    {
        Auth::requirePermission('payroll.process');self::requireReady();$reason=trim($reason);
        if(!in_array($classification,['REST_DAY','NO_WORK','ABSENT'],true)||$reason===''||mb_strlen($reason)>255) throw new RuntimeException('Choose the day type and enter a reason (up to 255 characters).');
        $ids=self::ids($ids);db()->beginTransaction();
        try {
            PayrollAttendanceService::lockOpen($cutoffId);PayrollAttendanceService::rebuild($cutoffId);$model=self::model($cutoffId);
            $allowed=[];foreach($model['rows'] as $d) if($d['id'] && in_array($d['state'],['AWAITING_LOGS','REST_DAY','NO_WORK','ABSENT'],true)) $allowed[(int)$d['id']]=$d;
            $save=db()->prepare('INSERT INTO payroll_cutoff_day_reviews(cutoff_id,employee_id,work_date,source_hash,classification,reason,reviewed_by) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_hash=VALUES(source_hash),classification=VALUES(classification),reason=VALUES(reason),reviewed_by=VALUES(reviewed_by),reviewed_at=NOW()');
            foreach($ids as $id) {
                $d=$allowed[$id]??null;
                if(!$d || !hash_equals($d['export_source_hash'],(string)($hashes[$id]??''))) throw new RuntimeException('A selected day changed. Refresh attendance and review the current logs.');
                $save->execute([$cutoffId,$d['employee_id'],$d['work_date'],$d['export_source_hash'],$classification,$reason,(int)Auth::user()['id']]);
            }
            audit('Payroll','REVIEW_NO_LOG_DAYS','payroll_cutoff',$cutoffId,['classification'=>$classification,'day_ids'=>$ids,'reason'=>$reason]);db()->commit();
        } catch(Throwable $e) {if(db()->inTransaction())db()->rollBack();throw $e;}
    }

    public static function generate(int $cutoffId,bool $filingClosed): void
    {
        Auth::requirePermission('payroll.finalize');Auth::requirePermission('payroll.export');self::requireReady();
        if(!$filingClosed) throw new RuntimeException('Confirm that employees have finished filing for this cutoff.');
        db()->beginTransaction();
        try {
            db()->prepare('INSERT IGNORE INTO payroll_cutoff_runs(cutoff_id) VALUES(?)')->execute([$cutoffId]);
            $run=PayrollAttendanceService::run($cutoffId,true);
            if(self::batch($cutoffId)) {db()->commit();return;}
            if($run['run_state']==='FINALIZED') throw new RuntimeException('This older cutoff is already locked. Its original CSV remains available. Generate Excel/PDF for an open cutoff; historical snapshots are preserved.');
            if($run['period_end']>=date('Y-m-d')) throw new RuntimeException('Wait until the cutoff has ended before generating all attendance.');
            if(!empty($run['ticket_deadline'])&&$run['ticket_deadline']>date('Y-m-d H:i:s')) throw new RuntimeException('The filing deadline has not ended yet.');
            PayrollAttendanceService::rebuild($cutoffId);$model=self::model($cutoffId);$run=$model['run'];
            if(empty($run['covered_through'])||$run['covered_through']<$run['period_end']) throw new RuntimeException('Confirm that all MIS / Sales Captain files are imported through the cutoff end.');
            if($model['pending']||$model['pending_leave']) throw new RuntimeException('Finish pending or returned payroll tickets and leave approvals first.');
            if($model['unmapped']) throw new RuntimeException('Match the unknown employee numbers in imported files first.');
            if($model['unapplied']) throw new RuntimeException('Review approved tickets that have not applied to attendance first.');
            if($model['problems']) throw new RuntimeException('Fix missing or conflicting clock times before generating the complete cutoff.');
            if($model['calendar']) throw new RuntimeException('Review the no-log dates as Rest day, No work or Absent before generating.');
            if(!$model['rows']) throw new RuntimeException('No employees are available for this cutoff.');
            $q=db()->prepare('SELECT pr.employee_id,pr.affected_date,pr.id,prt.code type_code,t.time_in,t.lunch_out,t.lunch_in,t.time_out FROM payroll_requests pr JOIN payroll_request_types prt ON prt.id=pr.request_type_id LEFT JOIN payroll_request_time_entries t ON t.request_id=pr.id WHERE pr.cutoff_id=? AND pr.status IN ("APPROVED","COMPLETED") AND EXISTS(SELECT 1 FROM payroll_request_approvals a WHERE a.request_id=pr.id AND a.approver_type IN ("MANAGER","ADL") AND a.status="APPROVED")');$q->execute([$cutoffId]);$requests=[];
            foreach($q->fetchAll() as $r) $requests[$r['employee_id'].'|'.$r['affected_date']][]=$r;
            // Freeze identity, organization placement, clocks and correction provenance together.
            $save=db()->prepare('INSERT INTO payroll_cutoff_snapshots(cutoff_id,employee_id,work_date,snapshot_json) VALUES(?,?,?,?)');$digest=hash_init('sha256');
            usort($model['rows'],static fn($a,$b)=>((int)$a['employee_id']<=>(int)$b['employee_id'])?:strcmp($a['work_date'],$b['work_date']));
            foreach($model['rows'] as $d) {
                $d['export_marks']=[];$snap=json_decode($d['rate_snapshot_json'],true)?:[];$original=[];
                foreach(PayrollCalculator::FIELDS as $f) $original[$f]=$d['original_'.$f];
                $req=$requests[$d['employee_id'].'|'.$d['work_date']]??[];$types=array_column($req,'type_code','id');
                $proposals=PayrollCalculator::timeProposals($d['work_date'],$snap['site']??[],$req,(bool)($snap['ob_time_out_allowed']??true),$original);
                foreach(PayrollCalculator::FIELDS as $f) foreach($proposals[$f]??[] as $proposal) if($d[$f]===$proposal['value']) $d['export_marks'][$f]=str_ends_with($types[$proposal['request_id']],'TA')?'TA':'OB';
                if(self::otMinutes($d)>0) $d['export_marks']['ot']='OT';
                // Store only identity and organization fields needed by the report.
                $d['_employee']=array_intersect_key($d['_employee'],array_flip(['id','employee_no','employee_name','hire_date','branch_code','branch_name','department_name','position_name','company_name','area_code','area_name','export_workplace','export_company','export_role','export_group','export_department','export_main_department','export_section']));
                $json=json_encode($d,JSON_THROW_ON_ERROR);hash_update($digest,$json."\n");$save->execute([$cutoffId,$d['employee_id'],$d['work_date'],$json]);
            }
            db()->prepare('INSERT INTO payroll_cutoff_export_batches(cutoff_id,employee_count,record_count,content_hash,generated_by) VALUES(?,?,?,?,?)')->execute([$cutoffId,count($model['employees']),count($model['rows']),hash_final($digest),(int)Auth::user()['id']]);
            db()->prepare('UPDATE payroll_cutoff_runs SET state="FINALIZED",finalized_at=NOW(),finalized_by=? WHERE cutoff_id=?')->execute([(int)Auth::user()['id'],$cutoffId]);
            audit('Payroll','GENERATE_COMPLETE_ATTENDANCE','payroll_cutoff',$cutoffId,['employees'=>count($model['employees']),'records'=>count($model['rows'])]);db()->commit();
        } catch(Throwable $e) {if(db()->inTransaction())db()->rollBack();throw $e;}
    }

    public static function saved(int $cutoffId): array
    {
        self::requireReady();$batch=self::batch($cutoffId);if(!$batch)throw new RuntimeException('Generate the complete cutoff before downloading Excel or PDF.');
        $q=db()->prepare('SELECT snapshot_json FROM payroll_cutoff_snapshots WHERE cutoff_id=? ORDER BY employee_id,work_date');$q->execute([$cutoffId]);$rows=[];$employees=[];$hash=hash_init('sha256');
        while($json=$q->fetchColumn()) {
            hash_update($hash,$json."\n");$d=json_decode($json,true,512,JSON_THROW_ON_ERROR);$e=$d['_employee']??null;
            if(!$e)throw new RuntimeException('This cutoff does not contain complete grouped export records.');
            $rows[]=$d;$employees[(int)$d['employee_id']]=$e;
        }
        if(count($rows)!==(int)$batch['record_count']||count($employees)!==(int)$batch['employee_count']||!hash_equals($batch['content_hash'],hash_final($hash))) throw new RuntimeException('Saved cutoff records changed. Ask the administrator to check the export snapshot.');
        return ['rows'=>$rows,'employees'=>$employees];
    }

    public static function otMinutes(array $d): int
    {
        return (int)($d['regular_ot_minutes']??0)+(int)($d['night_ot_minutes']??0)+(int)($d['holiday_ot_minutes']??0)+(int)($d['holiday_night_ot_minutes']??0);
    }

    private static function ids(array $ids): array
    {
        if(!$ids||count($ids)>200)throw new RuntimeException('Select between 1 and 200 records.');
        foreach($ids as $id) if(!is_scalar($id)||!ctype_digit((string)$id)||(int)$id<1)throw new RuntimeException('Invalid record selection.');
        return array_values(array_unique(array_map('intval',$ids)));
    }

    public static function payments(int $cutoffId): array
    {
        $q=db()->prepare('SELECT p.*,u.full_name recorded_name FROM payroll_cutoff_export_payments p JOIN users u ON u.id=p.recorded_by WHERE p.cutoff_id=?');$q->execute([$cutoffId]);$rows=[];
        foreach($q->fetchAll() as $r)$rows[$r['employee_id'].'|'.$r['payment_kind']]=$r;return $rows;
    }

    public static function markPaid(int $cutoffId,array $ids,string $kind,string $date,string $reference): void
    {
        Auth::requirePermission('payroll.mark_paid');self::requireReady();$ids=self::ids($ids);$date=PayrollAttendanceService::date($date);$reference=trim($reference);
        if(!in_array($kind,['SALARY','OT'],true)||$date>date('Y-m-d')||$reference===''||mb_strlen($reference)>100)throw new RuntimeException('Enter a valid payment type, payment date and reference (up to 100 characters).');
        db()->beginTransaction();try {
            PayrollAttendanceService::run($cutoffId,true);$saved=self::saved($cutoffId);$paid=self::payments($cutoffId);$ot=[];
            foreach($saved['rows'] as $d) $ot[$d['employee_id']]=($ot[$d['employee_id']]??0)+self::otMinutes($d);
            foreach($ids as $id)if(!isset($saved['employees'][$id])||isset($paid[$id.'|'.$kind])||($kind==='OT'&&empty($ot[$id])))throw new RuntimeException('A selected employee is already paid, has no approved OT, or is not in this saved cutoff. Refresh the list.');
            $q=db()->prepare('INSERT INTO payroll_cutoff_export_payments(cutoff_id,employee_id,payment_kind,payment_date,reference_no,recorded_by) VALUES(?,?,?,?,?,?)');
            foreach($ids as $id)$q->execute([$cutoffId,$id,$kind,$date,$reference,(int)Auth::user()['id']]);
            audit('Payroll','RECORD_PAYMENT','payroll_cutoff',$cutoffId,['employees'=>$ids,'kind'=>$kind,'payment_date'=>$date,'reference'=>$reference]);db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    }
}
