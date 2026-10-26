<?php
declare(strict_types=1);
/**
 * Attendance export review: biometric rematch, current-report rebuild, day labels, source gaps + Source Summary sheet.
 *
 * Isolated scratch DB only (ticket_test_support.php refuses anything that is not hris_slice15_test_YYYYMMDD_HHMMSS):
 *   php scripts/ticket_stageb_database.php prepare <unused number>     # prints TEST_DATABASE=...
 *   HRIS_TEST_DB=<that name> php tests/attendance_export_review_test.php
 * Synthetic data only: every employee/department/cutoff is tagged T163 and is wiped at the start of each run.
 * Cutoffs sit in 2019 (past) and 2031 (future) so they never overlap a real cutoff.
 */
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/PayrollCutoffExportService.php';
require_once dirname(__DIR__).'/app/PayrollCutoffDocuments.php';

const TAG='T163';
const NO_LOGS='No biometric logs received for this employee';

// ---------- fixtures ----------
function one(string $sql,array $args=[]){$q=db()->prepare($sql);$q->execute($args);$v=$q->fetchColumn();return $v===false?null:$v;}
function wipe(): void {
    $emp='SELECT id FROM employees WHERE employee_no LIKE "'.TAG.'\_%"';
    $cut='SELECT id FROM payroll_cutoffs WHERE code LIKE "'.TAG.'-%"';
    db()->exec('DELETE FROM attendance_punches WHERE employee_id IN ('.$emp.') OR import_row_id IN (SELECT r.id FROM attendance_import_rows r JOIN attendance_imports i ON i.id=r.import_id WHERE i.cutoff_id IN ('.$cut.'))');
    db()->exec('DELETE FROM attendance_import_rows WHERE import_id IN (SELECT id FROM attendance_imports WHERE cutoff_id IN ('.$cut.'))');
    db()->exec('DELETE FROM attendance_imports WHERE cutoff_id IN ('.$cut.')');
    db()->exec('DELETE FROM attendance_daily WHERE employee_id IN ('.$emp.') OR cutoff_id IN ('.$cut.')');
    db()->exec('DELETE FROM payroll_biometric_mappings WHERE employee_id IN ('.$emp.')');
    db()->exec('DELETE FROM employees WHERE employee_no LIKE "'.TAG.'\_%"');
    db()->exec('DELETE FROM departments WHERE code LIKE "'.TAG.'%"');
}
function cutoff(string $key,string $start,string $end,string $state='DRAFT'): int {
    $code=TAG.'-'.$key;$id=(int)(one('SELECT id FROM payroll_cutoffs WHERE code=?',[$code])?:0);
    if(!$id)$id=insert('INSERT INTO payroll_cutoffs(code,period_start,period_end,status) VALUES(?,?,?,"OPEN")',[$code,$start,$end]);
    db()->prepare('INSERT INTO payroll_cutoff_runs(cutoff_id,state) VALUES(?,?) ON DUPLICATE KEY UPDATE state=VALUES(state),covered_through=NULL,finalized_at=NULL')->execute([$id,$state]);
    return $id;
}
function department(string $key): int {return insert('INSERT INTO departments(code,name) VALUES(?,?)',[TAG.'_'.$key,'T163 '.$key.' Section']);}
function employee(string $no,string $last,?int $dept=null): int {
    return insert('INSERT INTO employees(employee_no,first_name,last_name,department_id,status,hire_date) VALUES(?,?,?,?,"ACTIVE","2018-01-01")',[$no,TAG,$last,$dept]);
}
function importOf(int $cutoff,string $name,array $rows): array {
    $imp=insert('INSERT INTO attendance_imports(cutoff_id,branch_id,original_name,file_hash,state,row_count,created_by,imported_at,expires_at) VALUES(?,NULL,?,?,"IMPORTED",?,?,NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY))',[$cutoff,$name,hash('sha256',$name.microtime()),count($rows),(int)$_SESSION['user']['id']]);
    $ids=[];$n=0;
    foreach($rows as [$bio,$at,$status,$employeeId,$state]){
        $n++;$ids[]=insert('INSERT INTO attendance_import_rows(import_id,row_no,biometric_id,punched_at,punch_status,employee_id,row_state) VALUES(?,?,?,?,?,?,?)',[$imp,$n,$bio,$at,$status,$employeeId,$state]);
    }
    return $ids;
}
/** A punch already saved by a normal import: mapped row + attendance_punches, but NO attendance_daily rebuild. */
function savedPunches(int $cutoff,int $emp,string $bio,array $times): void {
    $rows=importOf($cutoff,'T163-saved-'.$emp,array_map(static fn($t)=>[$bio,$t[0],$t[1],$emp,'IMPORTED'],$times));
    foreach($times as $i=>$t)db()->prepare('INSERT INTO attendance_punches(employee_id,punched_at,punch_status,import_row_id) VALUES(?,?,?,?)')->execute([$emp,$t[0],$t[1],$rows[$i]]);
}
function fullDay(string $date): array {return [[$date.' 09:00:00','IN'],[$date.' 12:00:00','OUT'],[$date.' 13:00:00','IN'],[$date.' 18:00:00','OUT']];}
function punchCount(int $emp): int {return (int)one('SELECT COUNT(*) FROM attendance_punches WHERE employee_id=?',[$emp]);}
function dailyState(int $emp,string $date): ?string {return one('SELECT state FROM attendance_daily WHERE employee_id=? AND work_date=?',[$emp,$date]);}
function auditCount(int $cutoff): int {return (int)one('SELECT COUNT(*) FROM audit_logs WHERE module="Payroll" AND action="BIOMETRIC_REMATCH" AND record_type="payroll_cutoff" AND record_id=?',[$cutoff]);}
function rowsOf(array $model,int $emp): array {return array_values(array_filter($model['rows'],static fn($r)=>(int)$r['employee_id']===$emp));}

wipe();
// Payroll user: only the permissions the services demand.
$role=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,"hr")',[TAG.'_PAYROLL_'.bin2hex(random_bytes(3)),'T163 payroll']);
foreach(['payroll.process','payroll.export','payroll.biometric_import'] as $code){$p=row('SELECT id FROM permissions WHERE code=?',[$code]);if($p)db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);}
$uid=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$role,'T163 Payroll',strtolower(TAG).'_'.bin2hex(random_bytes(3)).'@example.test',password_hash('T163-Test-Only!9',PASSWORD_DEFAULT)]);
$user=['id'=>$uid,'role_id'=>$role,'role_code'=>'T163_PAYROLL','role_portal'=>'hr','name'=>'T163 Payroll','role_name'=>'T163 payroll','email'=>'t163@example.test','client_id'=>null];
act($user);
$isolated=one('SELECT DATABASE()')===$dbName && $dbName!=='pmbsi_hris' && str_starts_with($dbName,'hris_slice15_test_');
check('runs on the isolated scratch database, not the configured one',$isolated);
if(!$isolated)finish();

// =====================================================================
// 1. rematchImportedRows
// =====================================================================
$R=cutoff('REMATCH','2019-05-04','2019-05-18');
$codeX=TAG.'_RX'.strtoupper(bin2hex(random_bytes(2)));     // exact id; employee is created AFTER the import
$codeY=TAG.'_RY'.strtoupper(bin2hex(random_bytes(2)));     // only a case / space variant of this id is in the file
$codeZ=TAG.'_RZ'.strtoupper(bin2hex(random_bytes(2)));     // id that matches no employee at all
$d='2019-05-06 ';
$rows=importOf($R,'T163-unmapped',[
    [$codeX,$d.'09:00:00','IN',null,'UNMAPPED'],[$codeX,$d.'12:00:00','OUT',null,'UNMAPPED'],
    [strtolower($codeY),$d.'09:05:00','IN',null,'UNMAPPED'],        // different case
    [$codeY.' ',$d.'09:06:00','IN',null,'UNMAPPED'],                // trailing space
    [$codeZ,$d.'09:07:00','IN',null,'UNMAPPED'],                    // no such employee
]);
[$rx1,$rx2,$ry1,$ry2,$rz]=$rows;
$before=auditCount($R);
check('1 no employee has the code yet: rematch matches nothing',PayrollAttendanceService::rematchImportedRows($R)===0 && (int)one('SELECT COUNT(*) FROM attendance_import_rows WHERE import_id=(SELECT import_id FROM attendance_import_rows WHERE id=?) AND row_state="UNMAPPED"',[$rx1])===5);
$ex=employee($codeX,'RematchX');$ey=employee($codeY,'RematchY');   // created later than the import
check('1 fixtures: no mapping, no punch, no daily row yet',!one('SELECT 1 FROM payroll_biometric_mappings WHERE employee_id IN (?,?)',[$ex,$ey]) && punchCount($ex)===0 && dailyState($ex,'2019-05-06')===null);
$matched=PayrollAttendanceService::rematchImportedRows($R);
check('1 exact employee_no match: both rows of that id are matched',$matched===2,(string)$matched);
check('1 mapping created for the exact id only',one('SELECT biometric_id FROM payroll_biometric_mappings WHERE employee_id=?',[$ex])===$codeX && !one('SELECT 1 FROM payroll_biometric_mappings WHERE employee_id=?',[$ey]));
check('1 punches inserted for the mapped employee',punchCount($ex)===2 && one('SELECT COUNT(*) FROM attendance_punches WHERE employee_id=? AND punched_at IN (?,?)',[$ex,$d.'09:00:00',$d.'12:00:00'])==2);
check('1 import rows flipped to IMPORTED with the employee id',(int)one('SELECT COUNT(*) FROM attendance_import_rows WHERE id IN (?,?) AND row_state="IMPORTED" AND employee_id=?',[$rx1,$rx2,$ex])===2);
check('1 attendance_daily rebuilt for the matched employee',dailyState($ex,'2019-05-06')!==null && dailyState($ex,'2019-05-06')!=='AWAITING_LOGS');
check('1 different-case id stays unmapped',one('SELECT row_state FROM attendance_import_rows WHERE id=?',[$ry1])==='UNMAPPED' && one('SELECT employee_id FROM attendance_import_rows WHERE id=?',[$ry1])===null);
check('1 trailing-space id stays unmapped',one('SELECT row_state FROM attendance_import_rows WHERE id=?',[$ry2])==='UNMAPPED' && one('SELECT employee_id FROM attendance_import_rows WHERE id=?',[$ry2])===null);
check('1 id with no employee stays unmapped',one('SELECT row_state FROM attendance_import_rows WHERE id=?',[$rz])==='UNMAPPED');
check('1 non-matching employee got no punch and no mapping',punchCount($ey)===0 && !one('SELECT 1 FROM payroll_biometric_mappings WHERE employee_id=?',[$ey]));
check('1 BIOMETRIC_REMATCH audit written once with the counts',auditCount($R)===$before+1 && (int)(json_decode((string)one('SELECT details_json FROM audit_logs WHERE module="Payroll" AND action="BIOMETRIC_REMATCH" AND record_id=? ORDER BY id DESC LIMIT 1',[$R]),true)['rows']??-1)===2);
$audit=auditCount($R);$maps=(int)one('SELECT COUNT(*) FROM payroll_biometric_mappings WHERE employee_id IN (?,?)',[$ex,$ey]);
check('1 re-run is idempotent: returns 0',PayrollAttendanceService::rematchImportedRows($R)===0);
check('1 re-run adds no punches, mappings or audit rows',punchCount($ex)===2 && (int)one('SELECT COUNT(*) FROM payroll_biometric_mappings WHERE employee_id IN (?,?)',[$ex,$ey])===$maps && auditCount($R)===$audit);
check('1 unrelated variants still unmapped after the re-run',(int)one('SELECT COUNT(*) FROM attendance_import_rows WHERE id IN (?,?,?) AND row_state="UNMAPPED"',[$ry1,$ry2,$rz])===3);
// A finalized cutoff must refuse (lockOpen) rather than silently match.
$RF=cutoff('REMATCH-FINAL','2019-07-04','2019-07-18','FINALIZED');
$codeF=TAG.'_RF'.strtoupper(bin2hex(random_bytes(2)));
$rf=importOf($RF,'T163-final-unmapped',[[$codeF,'2019-07-06 09:00:00','IN',null,'UNMAPPED']]);$ef=employee($codeF,'RematchFinal');
check('1 finalized cutoff: rematch refuses and maps nothing',rejects(fn()=>PayrollAttendanceService::rematchImportedRows($RF)) && one('SELECT row_state FROM attendance_import_rows WHERE id=?',[$rf[0]])==='UNMAPPED' && punchCount($ef)===0);

// =====================================================================
// 2. currentReport(): rebuilds non-FINALIZED cutoffs, leaves run_state alone, never touches FINALIZED
// =====================================================================
$S=cutoff('STALE','2019-06-04','2019-06-18','DRAFT');
$es=employee(TAG.'_ST'.strtoupper(bin2hex(random_bytes(2))),'Stale');
savedPunches($S,$es,$es.'bio',fullDay('2019-06-05'));
db()->prepare('INSERT INTO attendance_daily(cutoff_id,employee_id,work_date,state,issues_json,sources_json,rate_snapshot_json) VALUES(?,?,?,"AWAITING_LOGS","[]","[]","{}")')->execute([$S,$es,'2019-06-05']);
check('2 fixture: daily row is stale (AWAITING_LOGS although punches exist)',dailyState($es,'2019-06-05')==='AWAITING_LOGS' && punchCount($es)===4);
$model=PayrollCutoffExportService::currentReport($S);
$day=array_values(array_filter(rowsOf($model,$es),static fn($r)=>$r['work_date']==='2019-06-05'))[0]??[];
check('2 stale saved row refreshed in the database',dailyState($es,'2019-06-05')!=='AWAITING_LOGS' && one('SELECT time_in FROM attendance_daily WHERE employee_id=? AND work_date=?',[$es,'2019-06-05'])==='2019-06-05 09:00:00' && one('SELECT time_out FROM attendance_daily WHERE employee_id=? AND work_date=?',[$es,'2019-06-05'])==='2019-06-05 18:00:00');
check('2 report shows the refreshed day (times, not awaiting logs)',($day['state']??'AWAITING_LOGS')!=='AWAITING_LOGS' && ($day['time_in']??null)==='2019-06-05 09:00:00' && !isset($day['export_day_label']));
check('2 currentReport does not change run_state (stays DRAFT, never FINALIZED)',one('SELECT state FROM payroll_cutoff_runs WHERE cutoff_id=?',[$S])==='DRAFT' && $model['run']['run_state']==='DRAFT' && !empty($model['report_meta']['for_review']));
check('2 report ran rematch too (no stray rows, still not finalized)',one('SELECT finalized_at FROM payroll_cutoff_runs WHERE cutoff_id=?',[$S])===null);
// Rematch is part of the report: an unmapped row whose code now exists is picked up by the report itself.
$codeS=TAG.'_SM'.strtoupper(bin2hex(random_bytes(2)));
$rs=importOf($S,'T163-report-unmapped',[[$codeS,'2019-06-10 09:00:00','IN',null,'UNMAPPED']]);$esm=employee($codeS,'ReportMatch');
PayrollCutoffExportService::currentReport($S);
check('2 currentReport applies the rematch (row mapped, punch present, daily rebuilt)',one('SELECT row_state FROM attendance_import_rows WHERE id=?',[$rs[0]])==='IMPORTED' && punchCount($esm)===1 && !in_array(dailyState($esm,'2019-06-10'),[null,'AWAITING_LOGS'],true));

$FZ=cutoff('FINAL','2019-04-04','2019-04-18','FINALIZED');
$ef2=employee(TAG.'_FZ'.strtoupper(bin2hex(random_bytes(2))),'Frozen');
savedPunches($FZ,$ef2,$ef2.'bio',fullDay('2019-04-05'));
db()->prepare('INSERT INTO attendance_daily(cutoff_id,employee_id,work_date,state,issues_json,sources_json,rate_snapshot_json) VALUES(?,?,?,"AWAITING_LOGS","[]","[]","{}")')->execute([$FZ,$ef2,'2019-04-05']);
$frozen=row('SELECT * FROM attendance_daily WHERE employee_id=? AND work_date=?',[$ef2,'2019-04-05']);
$daily=(int)one('SELECT COUNT(*) FROM attendance_daily WHERE cutoff_id=?',[$FZ]);
$model=PayrollCutoffExportService::currentReport($FZ);
check('2 FINALIZED cutoff: saved daily row is not rebuilt',row('SELECT * FROM attendance_daily WHERE employee_id=? AND work_date=?',[$ef2,'2019-04-05'])===$frozen);
check('2 FINALIZED cutoff: no daily rows added for anyone, run_state stays FINALIZED',(int)one('SELECT COUNT(*) FROM attendance_daily WHERE cutoff_id=?',[$FZ])===$daily && one('SELECT state FROM payroll_cutoff_runs WHERE cutoff_id=?',[$FZ])==='FINALIZED');
$frozenDay=array_values(array_filter(rowsOf($model,$ef2),static fn($r)=>$r['work_date']==='2019-04-05'))[0]??[];
check('2 FINALIZED report still reflects the frozen (stale) snapshot',($frozenDay['state']??'')==='AWAITING_LOGS');

// =====================================================================
// 3 + 4. day labels, sourceGaps, Source Summary
// =====================================================================
$P=cutoff('PAST','2019-03-04','2019-03-18','DRAFT');
$dNone=department('NOLOGS');$dPart=department('PARTIAL');
$n1=employee(TAG.'_N1'.strtoupper(bin2hex(random_bytes(2))),'NoLogsA',$dNone);
$n2=employee(TAG.'_N2'.strtoupper(bin2hex(random_bytes(2))),'NoLogsB',$dNone);
$w1=employee(TAG.'_W1'.strtoupper(bin2hex(random_bytes(2))),'HasLogs',$dPart);
$n3=employee(TAG.'_N3'.strtoupper(bin2hex(random_bytes(2))),'NoLogsC',$dPart);
savedPunches($P,$w1,$w1.'bio',fullDay('2019-03-06'));
$model=PayrollCutoffExportService::currentReport($P);
$noLog=rowsOf($model,$n1);$hasLog=rowsOf($model,$w1);
$awaitN=array_filter($noLog,static fn($r)=>$r['state']==='AWAITING_LOGS');
check('3 no-punch employee has past awaiting days to label',count($awaitN)>=1,(string)count($awaitN));
check('3 every awaiting day of a no-punch employee says no biometric logs received',$awaitN!==[] && count(array_filter($awaitN,static fn($r)=>($r['export_day_label']??'')===NO_LOGS))===count($awaitN));
check("3 'Waiting for files' is never used for an employee with no logs at all",!array_filter($noLog,static fn($r)=>($r['export_day_label']??'')==='Waiting for files'));
$awaitW=array_filter($hasLog,static fn($r)=>$r['state']==='AWAITING_LOGS');
check("3 employee with logs: remaining past awaiting days get 'Waiting for files'",$awaitW!==[] && count(array_filter($awaitW,static fn($r)=>($r['export_day_label']??'')==='Waiting for files'))===count($awaitW));
$logDay=array_values(array_filter($hasLog,static fn($r)=>$r['work_date']==='2019-03-06'))[0]??[];
check('3 day that has logs keeps no awaiting label and shows the punches',($logDay['state']??'')!=='AWAITING_LOGS' && !isset($logDay['export_day_label']) && ($logDay['time_in']??null)==='2019-03-06 09:00:00');
$labelled=array_filter($model['rows'],static fn($r)=>isset($r['export_day_label']));
check('3 labels only appear on awaiting/issue days (no label on COMPLETE days)',!array_filter($labelled,static fn($r)=>in_array($r['state'],['COMPLETE','CORRECTED'],true)));

$U=cutoff('FUTURE','2031-03-04','2031-03-18','DRAFT');
$fm=PayrollCutoffExportService::currentReport($U);
$fN=array_filter(rowsOf($fm,$n1),static fn($r)=>$r['state']==='AWAITING_LOGS');$fW=array_filter(rowsOf($fm,$w1),static fn($r)=>$r['state']==='AWAITING_LOGS');
check("3 future days are 'In progress' even for an employee with no logs",$fN!==[] && count(array_filter($fN,static fn($r)=>($r['export_day_label']??'')==='In progress'))===count($fN));
check("3 future days are 'In progress' for an employee with logs elsewhere too",$fW!==[] && count(array_filter($fW,static fn($r)=>($r['export_day_label']??'')==='In progress'))===count($fW));
check("3 'Waiting for files' and the no-logs text never appear for a future cutoff",!array_filter($fm['rows'],static fn($r)=>in_array($r['export_day_label']??'',['Waiting for files',NO_LOGS],true)));

// 4a. sourceGaps
$gaps=$model['source_gaps']??[];$secN=$model['employees'][$n1]['export_section']??'';$secW=$model['employees'][$w1]['export_section']??'';
check('4 fixture: departments give two distinct export sections',$secN!=='' && $secW!=='' && $secN!==$secW && $secN===($model['employees'][$n2]['export_section']??'') && $secW===($model['employees'][$n3]['export_section']??''),$secN.' | '.$secW);
check('4 sourceGaps lists the no-log employees',in_array($n1,$gaps['no_log_employees'],true) && in_array($n2,$gaps['no_log_employees'],true) && in_array($n3,$gaps['no_log_employees'],true));
check('4 sourceGaps does not list the employee who has logs',!in_array($w1,$gaps['no_log_employees'],true));
check('4 all-no-logs department: total 2, with_logs 0',($gaps['sections'][$secN]['total']??-1)===2 && ($gaps['sections'][$secN]['with_logs']??-1)===0,json_encode($gaps['sections'][$secN]??null));
check('4 partial department: total 2, with_logs 1',($gaps['sections'][$secW]['total']??-1)===2 && ($gaps['sections'][$secW]['with_logs']??-1)===1,json_encode($gaps['sections'][$secW]??null));
$zero=count(array_filter($gaps['sections'],static fn($s)=>$s['with_logs']===0));
check('4 no_log_sections counts the groups with none',$gaps['no_log_sections']===$zero && $zero>=1);
$expectedNoLogs=array_values(array_filter(array_keys($model['employees']),static fn($id)=>!(int)one('SELECT COUNT(*) FROM attendance_punches WHERE employee_id=? AND punched_at>=? AND punched_at<?',[$id,'2019-03-04 00:00:00','2019-03-20 00:00:00'])));
check('4 no_log_employees equals the roster minus employees with a punch in the cutoff window (independent SQL)',array_map('intval',$gaps['no_log_employees'])===array_map('intval',$expectedNoLogs));
check('4 report notice mentions employees with no biometric logs',str_contains($model['report_meta']['notice'],count($gaps['no_log_employees']).' employees have no biometric logs'));

// 4b. Source Summary sheet (assert on the XML it builds, not on a zipped xlsx)
$summary=new ReflectionMethod(PayrollCutoffDocuments::class,'summarySheet');
$xml=$summary->invoke(null,$gaps,$model['employees'],$model['run'],['generated_at'=>'2026-10-26 08:00:00']);
$rowsXml=preg_split('/(?=<row )/',$xml);
$sectionRow=static fn(string $section)=>implode('',array_filter($rowsXml,static fn($r)=>str_contains($r,htmlspecialchars($section,ENT_XML1))));
check('4 summary sheet is well-formed XML',@simplexml_load_string($xml)!==false);
check('4 summary: all-no-logs department row says NO LOGS RECEIVED with 2 / 0 / 2',str_contains($sectionRow($secN),'NO LOGS RECEIVED'));
check('4 summary: partial department row says PARTIAL - 1 employees without logs',str_contains($sectionRow($secW),'PARTIAL - 1 employees without logs') && !str_contains($sectionRow($secW),'NO LOGS RECEIVED'));
$listAt=strpos($xml,'Employees without biometric logs');$list=$listAt===false?'':substr($xml,$listAt);
check('4 summary lists each no-log employee by name and code',$list!=='' && str_contains($list,'NoLogsA') && str_contains($list,'NoLogsB') && str_contains($list,'NoLogsC') && str_contains($list,(string)$model['employees'][$n1]['employee_no']));
check('4 summary does not list the employee who has logs',!str_contains($list,'HasLogs'));
check('4 summary shows the cutoff period header',str_contains($xml,'2019-03-04 to 2019-03-18'));

wipe();
db()->exec('DELETE FROM payroll_cutoff_runs WHERE cutoff_id IN (SELECT id FROM payroll_cutoffs WHERE code LIKE "'.TAG.'-%")');
db()->exec('DELETE FROM payroll_cutoffs WHERE code LIKE "'.TAG.'-%"');
finish();
