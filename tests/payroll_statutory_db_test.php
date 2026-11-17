<?php
declare(strict_types=1);

/**
 * Payroll engine P1 (migration 045: statutory tables) against a SCRATCH copy of the database:
 *   HRIS_TEST_DB=hris_slice15_test_... C:\xampp\php\php.exe tests\payroll_statutory_db_test.php
 * Refuses the live database. Every row this test creates is deleted at the end. All rates are made up.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if($dbName==='' || in_array($dbName,['pmbsi_hris','hris_clean'],true)) { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy.\n"); exit(2); }
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/PayrollStatutory.php';
$GLOBALS['pdo']=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(db()->query('SELECT DATABASE()')->fetchColumn()!==$dbName) exit(2);
if(!PayrollStatutory::ready()) { fwrite(STDERR,"Run migration 045 on the scratch copy first.\n"); exit(2); }

$fail=0;
function check(string $label,bool $ok,string $why=''): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label.($ok||$why===''?'':'  ['.$why.']')."\n"; if(!$ok) $fail++; }
function err(callable $f): string { try { $f(); return ''; } catch(RuntimeException $e) { return $e->getMessage(); } }

$a=(int)db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
db()->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT role_id,?,?,? FROM users WHERE id=?')->execute(['Stat Test Verifier','stat-test-verifier@example.invalid','x',$a]);
$b=(int)db()->lastInsertId();
$_SESSION['user']=['id'=>$a,'role_code'=>'SUPER_ADMIN','role_portal'=>'admin'];
$startAudit=(int)db()->query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetchColumn();
$draft=fn(array $o=[])=>PayrollStatutory::saveDraft($o['id']??0,['table_code'=>$o['code']??'SSS','pay_frequency'=>$o['freq']??'SEMI_MONTHLY','version_label'=>$o['label']??'ZZ test','effective_from'=>$o['from']??'2091-01-01','source_reference'=>$o['src']??'ZZ circular','rows'=>$o['rows']??"0 999.99 10 20\n1000 - 30 60"],$o['by']??$a);

try {
    // ---- Draft ----
    check('label required',str_contains(err(fn()=>$draft(['label'=>' '])),'version label'));
    check('source required',str_contains(err(fn()=>$draft(['src'=>''])),'source reference'));
    check('bad date refused',str_contains(err(fn()=>$draft(['from'=>'2091-02-30'])),'valid effective date'));
    check('BIR needs a frequency',str_contains(err(fn()=>$draft(['code'=>'BIR_WITHHOLDING','freq'=>'YEARLY','rows'=>'0 - 0 10'])),'frequency'));
    $v1=$draft();
    $t=PayrollStatutory::version($v1);
    check('draft saved with 2 rows and MONTHLY for non-BIR',count($t['rows'])===2 && $t['pay_frequency']==='MONTHLY' && $t['verified_at']===null);
    check('unverified draft is not used by payroll (BR-514)',PayrollStatutory::forDate('SSS','MONTHLY','2091-06-01')===null);
    $draft(['id'=>$v1,'rows'=>"0 999.99 11 21\n1000 1999.99 31 61\n2000 - 50 90"]);
    check('editing a draft replaces its rows',count(PayrollStatutory::version($v1)['rows'])===3);
    check('duplicate start date refused',str_contains(err(fn()=>$draft()),'already starts'));

    // ---- Verify ----
    check('enterer cannot verify own table',str_contains(err(fn()=>PayrollStatutory::verify($v1,$a)),'different person'));
    PayrollStatutory::verify($v1,$b);
    $f=PayrollStatutory::forDate('SSS','MONTHLY','2091-06-01');
    check('verified version found for a date',$f!==null && (int)$f['id']===$v1 && $f['effective_to']===null);
    check('not found before effective date',PayrollStatutory::forDate('SSS','MONTHLY','2090-12-31')===null);
    check('verify twice refused',str_contains(err(fn()=>PayrollStatutory::verify($v1,$b)),'already verified'));
    check('verified table is locked for edits',str_contains(err(fn()=>$draft(['id'=>$v1,'from'=>'2091-01-01'])),'locked'));
    check('verified table cannot be discarded',str_contains(err(fn()=>PayrollStatutory::discard($v1)),'unverified draft'));
    check('DB refuses same person as enterer and verifier',(function() use($v1,$a){ try { db()->prepare('UPDATE payroll_stat_tables SET verified_by=entered_by WHERE id=?')->execute([$v1]); return false; } catch(PDOException) { return true; } })());

    // ---- New version closes the old one ----
    $old=$draft(['from'=>'2090-06-01','by'=>$b]);
    check('a version older than the verified one cannot be verified',str_contains(err(fn()=>PayrollStatutory::verify($old,$a)),'must start later'));
    PayrollStatutory::discard($old);
    check('draft discarded with its rows',(int)db()->query("SELECT COUNT(*) FROM payroll_stat_table_rows WHERE table_id=$old")->fetchColumn()===0);
    $v2=$draft(['from'=>'2092-01-01','rows'=>'0 - 99 199']);
    PayrollStatutory::verify($v2,$b);
    check('previous version closed the day before',PayrollStatutory::version($v1)['effective_to']==='2091-12-31');
    check('old date still uses the old version (BR-503)',(int)PayrollStatutory::forDate('SSS','MONTHLY','2091-12-31')['id']===$v1);
    $now=PayrollStatutory::forDate('SSS','MONTHLY','2092-01-01');
    check('new date uses the new version',(int)$now['id']===$v2);
    check('contribution from the stored rows',PayrollStatutory::contribution('SSS',$now['rows'],5000)===['employee'=>99.0,'employer'=>199.0]);

    // ---- BIR per frequency ----
    $bs=$draft(['code'=>'BIR_WITHHOLDING','freq'=>'SEMI_MONTHLY','rows'=>"0 1000 0 0\n1000.01 - 0 10"]); PayrollStatutory::verify($bs,$b);
    check('BIR found only for its frequency',PayrollStatutory::forDate('BIR_WITHHOLDING','SEMI_MONTHLY','2091-03-01')!==null && PayrollStatutory::forDate('BIR_WITHHOLDING','MONTHLY','2091-03-01')===null);
    check('saves and verifies are audited',(int)db()->query("SELECT COUNT(*) FROM audit_logs WHERE id>$startAudit AND action IN ('stat_table_saved','stat_table_verified','stat_table_discarded')")->fetchColumn()>=8);
} finally {
    db()->exec("DELETE FROM payroll_stat_tables WHERE version_label='ZZ test'");
    db()->exec("DELETE FROM audit_logs WHERE id>$startAudit");
    db()->prepare('DELETE FROM users WHERE id=?')->execute([$b]);
}
check('cleanup left no rows',(int)db()->query('SELECT COUNT(*) FROM payroll_stat_tables')->fetchColumn()===0);
echo $fail?"\n$fail FAILED\n":"\nALL PASSED\n";
exit($fail?1:0);
