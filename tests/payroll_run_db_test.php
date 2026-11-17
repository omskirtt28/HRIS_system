<?php
declare(strict_types=1);

/**
 * Payroll engine P2-P5 (migration 046) against a SCRATCH copy of the database:
 *   HRIS_TEST_DB=hris_slice15_test_... C:\xampp\php\php.exe tests\payroll_run_db_test.php
 * Builds its own employees, two finalized cutoffs in one month, rates, rules and verified tables (all MADE UP),
 * then checks compute -> month balance -> release -> journal entry -> payslip. Every row it creates is removed at the end.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if($dbName==='' || in_array($dbName,['pmbsi_hris','hris_clean'],true)) { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy.\n"); exit(2); }
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/Accounting.php';
require_once dirname(__DIR__).'/app/PayrollRun.php';
$GLOBALS['pdo']=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(db()->query('SELECT DATABASE()')->fetchColumn()!==$dbName) exit(2);
if(!PayrollRun::ready() || !Accounting::ready()) { fwrite(STDERR,"Run migrations 039 to 046 on the scratch copy first.\n"); exit(2); }

$fail=0;
function check(string $label,bool $ok,string $why=''): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label.($ok||$why===''?'':'  ['.$why.']')."\n"; if(!$ok) $fail++; }
function err(callable $f): string { try { $f(); return ''; } catch(RuntimeException $e) { return $e->getMessage(); } }
$near=fn($a,$b)=>abs((float)$a-(float)$b)<0.00005;

$pdo=db(); $sfx=bin2hex(random_bytes(3));
$a=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT role_id,?,?,? FROM users WHERE id=?')->execute(['Run Test Releaser',"run-test-$sfx@example.invalid",'x',$a]);
$b=(int)$pdo->lastInsertId();
$_SESSION['user']=['id'=>$a,'role_code'=>'SUPER_ADMIN','role_portal'=>'admin'];
$startAudit=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetchColumn();
$startEntry=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM acct_journal_entries')->fetchColumn();
$rulesBefore=$pdo->query('SELECT day_type,regular_multiplier,ot_multiplier,night_percent FROM payroll_rate_rules')->fetchAll();
$emps=[]; $cuts=[];
try {
    foreach(['M','D'] as $k) { $pdo->prepare('INSERT INTO employees(employee_no,first_name,last_name,status,hire_date) VALUES(?,?,?,"ACTIVE","2020-01-01")')->execute(["ZZRUN-$k-$sfx",'Run',"Test $k"]); $emps[$k]=(int)$pdo->lastInsertId(); }
    // M: monthly 20000 / 160 h = 125/h. D: daily 800 / 8 h = 100/h.
    $pdo->prepare('INSERT INTO payroll_employee_rates(employee_id,effective_from,salary_basis,salary_amount,hourly_divisor) VALUES(?,?,?,?,?),(?,?,?,?,?)')->execute([$emps['M'],'2090-01-01','MONTHLY',20000,160,$emps['D'],'2090-01-01','DAILY',800,8]);
    $pdo->exec("UPDATE payroll_rate_rules SET regular_multiplier=1,ot_multiplier=1.25,night_percent=10 WHERE day_type='REGULAR'");
    foreach([['2091-03-01','2091-03-15'],['2091-03-16','2091-03-31']] as $i=>[$s,$e]) {
        $pdo->prepare('INSERT INTO payroll_cutoffs(code,period_start,period_end,status) VALUES(?,?,?,"OPEN")')->execute(["ZZRUN-$i-$sfx",$s,$e]); $cuts[$i]=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_cutoff_runs(cutoff_id,state) VALUES(?,?)')->execute([$cuts[$i],$i===0?'FINALIZED':'DRAFT']);
    }
    $snap=$pdo->prepare('INSERT INTO payroll_cutoff_snapshots(cutoff_id,employee_id,work_date,snapshot_json) VALUES(?,?,?,?)');
    $day=fn(string $d,int $reg,int $ot=0)=>json_encode(['work_date'=>$d,'day_type'=>'REGULAR','regular_minutes'=>$reg,'regular_ot_minutes'=>$ot,'night_ot_minutes'=>0,'holiday_ot_minutes'=>0,'holiday_night_ot_minutes'=>0]);
    // Cutoff 1: M works 10 x 8h (10000) + 2h OT (312.5); D works 5 x 8h (4000).
    for($i=1;$i<=10;$i++) $snap->execute([$cuts[0],$emps['M'],sprintf('2091-03-%02d',$i),$day(sprintf('2091-03-%02d',$i),480,$i===1?120:0)]);
    for($i=1;$i<=5;$i++) $snap->execute([$cuts[0],$emps['D'],sprintf('2091-03-%02d',$i),$day(sprintf('2091-03-%02d',$i),480)]);
    for($i=16;$i<=25;$i++) { $snap->execute([$cuts[1],$emps['M'],"2091-03-$i",$day("2091-03-$i",480)]); $snap->execute([$cuts[1],$emps['D'],"2091-03-$i",$day("2091-03-$i",480)]); }

    // ---- Guards before tables exist ----
    check('cutoff must be finalized',str_contains(err(fn()=>PayrollRun::compute($cuts[1],'2091-04-05',$a)),'Finalize'));
    check('pay date not before cutoff end',str_contains(err(fn()=>PayrollRun::compute($cuts[0],'2091-03-10',$a)),'before the cutoff'));
    $r1=PayrollRun::compute($cuts[0],'2091-03-20',$a);
    $run=PayrollRun::run($r1);
    check('without verified tables every employee has issues',(int)$run['issue_count']===2);
    check('release blocked while issues remain',str_contains(err(fn()=>PayrollRun::release($r1,$b)),'Resolve'));

    // ---- Verified tables (made up) ----
    $tab=function(string $code,string $freq,string $rows) use($a,$b) { $id=PayrollStatutory::saveDraft(0,['table_code'=>$code,'pay_frequency'=>$freq,'version_label'=>'ZZ run test','effective_from'=>'2091-01-01','source_reference'=>'ZZ','rows'=>$rows],$a); PayrollStatutory::verify($id,$b); return $id; };
    $tab('SSS','MONTHLY',"0 9999.99 100 200\n10000 - 300 600");
    $tab('PHILHEALTH','MONTHLY',"1000 50000 2 2");
    $tab('PAGIBIG','MONTHLY',"0 1500 1 2 -\n1500 - 2 2 5000");
    $tab('BIR_WITHHOLDING','SEMI_MONTHLY',"0 5000 0 0\n5000 - 0 10");
    $r1b=PayrollRun::compute($cuts[0],'2091-03-20',$a);
    check('recompute keeps the same run',$r1b===$r1);
    $run=PayrollRun::run($r1); $by=[]; foreach($run['employees'] as $e) $by[(int)$e['employee_id']]=$e;
    $m=$by[$emps['M']]; $d=$by[$emps['D']];
    check('no issues after tables are verified',(int)$run['issue_count']===0,json_encode(array_column($run['employees'],'issues_json')));
    check('monthly-paid gross: 80h x 125 + 2h OT x 125 x 1.25',$near($m['gross'],10312.5));
    $line=fn(array $e,string $code)=>array_values(array_filter($e['lines'],fn($l)=>$l['code']===$code))[0]??[];
    check('first cutoff deducts half of SSS on the monthly salary base',$near($line($m,'SSS')['amount'],150) && $near($line($m,'SSS')['employer_amount'],300));
    check('daily-paid base projected as gross x 2 (8000)',$near($d['monthly_base'],8000) && $near($line($d,'SSS')['amount'],50));
    check('lines cite verified tables',(int)$line($m,'SSS')['stat_table_id']>0);
    check('run totals equal the employee sum',$near($run['total_net'],(float)$m['net']+(float)$d['net']));

    // ---- Adjustments ----
    check('adjustment amount validated',str_contains(err(fn()=>PayrollRun::addAdjustment($r1,$emps['M'],'DEDUCTION','x','0',$a)),'above zero'));
    check('adjustment employee must be in the run',str_contains(err(fn()=>PayrollRun::addAdjustment($r1,$a+999999,'DEDUCTION','x','5',$a)),'employee in this run'));
    PayrollRun::addAdjustment($r1,$emps['M'],'DEDUCTION','Cash advance','500.00',$a);
    $m2=array_values(array_filter(PayrollRun::run($r1)['employees'],fn($e)=>(int)$e['employee_id']===$emps['M']))[0];
    check('deduction adjustment lowers net by 500',$near((float)$m['net']-500,$m2['net']));

    // ---- Second cutoff needs the first released ----
    $pdo->prepare('UPDATE payroll_cutoff_runs SET state="FINALIZED" WHERE cutoff_id=?')->execute([$cuts[1]]);
    check('month balance waits for the earlier run to be released',str_contains(err(fn()=>PayrollRun::compute($cuts[1],'2091-04-05',$a)),'Release the earlier'));

    // ---- Release + journal ----
    check('the person who computed cannot release',str_contains(err(fn()=>PayrollRun::release($r1,$a)),'different person'));
    PayrollRun::release($r1,$b);
    $run=PayrollRun::run($r1);
    check('release locks the run and links a journal entry',$run['status']==='RELEASED' && (int)$run['entry_id']>$startEntry);
    $je=Accounting::entry((int)$run['entry_id']);
    $dr=array_sum(array_map(fn($l)=>(float)$l['debit'],$je['lines'])); $cr=array_sum(array_map(fn($l)=>(float)$l['credit'],$je['lines']));
    check('journal entry is a balanced DRAFT',$je['status']==='DRAFT' && abs($dr-$cr)<0.001 && $dr>0);
    $acc=fn(string $code)=>array_sum(array_map(fn($l)=>(float)$l['credit']-(float)$l['debit'],array_filter($je['lines'],fn($l)=>$l['code']===$code)));
    check('JE credits net pay to the payroll bank account',abs($acc('1030')-round((float)$run['total_net'],2))<0.011);
    check('JE credits Cash advance to Advances to Employees',abs($acc('1200')-500)<0.001);
    check('released run cannot be recomputed, adjusted or voided',str_contains(err(fn()=>PayrollRun::compute($cuts[0],'2091-03-20',$a)),'locked') && str_contains(err(fn()=>PayrollRun::addAdjustment($r1,$emps['M'],'EARNING','x','1',$a)),'unreleased') && str_contains(err(fn()=>PayrollRun::void($r1,$a)),'unreleased'));
    check('DB refuses releaser = computer',(function() use($pdo,$r1){ try { $pdo->prepare('UPDATE payroll_runs SET released_by=computed_by WHERE id=?')->execute([$r1]); return false; } catch(PDOException) { return true; } })());

    // ---- Month balance (BR-509) ----
    $r2=PayrollRun::compute($cuts[1],'2091-04-05',$a);
    $run2=PayrollRun::run($r2); $by2=[]; foreach($run2['employees'] as $e) $by2[(int)$e['employee_id']]=$e;
    $m3=$by2[$emps['M']]; $d3=$by2[$emps['D']];
    check('monthly-paid SSS halves sum to the monthly contribution',$near((float)$line($m3,'SSS')['amount']+150,300) && $near((float)$line($m3,'SSS')['employer_amount']+300,600));
    check('daily-paid last cutoff uses the actual month (4000 + 8000)',$near($d3['monthly_base'],12000));
    check('daily-paid SSS month total uses the actual base bracket',$near((float)$line($d3,'SSS')['amount']+50,300));
    check('only one active run per cutoff',(function() use($pdo,$cuts,$a){ try { $pdo->prepare('INSERT INTO payroll_runs(cutoff_id,pay_date,computed_by) VALUES(?,?,?)')->execute([$cuts[1],'2091-04-05',$a]); return false; } catch(PDOException) { return true; } })());
    PayrollRun::void($r2,$a);
    check('void frees the cutoff for a new run',($r3=PayrollRun::compute($cuts[1],'2091-04-05',$a))!==$r2);

    // ---- Pay method (migration 047) ----
    $rateIn=['employee_id'=>$emps['D'],'effective_from'=>'2095-01-01','salary_basis'=>'DAILY','salary_amount'=>'800','hourly_divisor'=>'8','pay_method'=>'FIXED'];
    check('fixed pay refused on a non-monthly basis',str_contains(err(fn()=>PayrollAttendanceService::saveRate($rateIn)),'monthly salary basis'));
    check('unknown pay method refused',str_contains(err(fn()=>PayrollAttendanceService::saveRate(['pay_method'=>'X']+$rateIn)),'pay method'));
    check('DB refuses FIXED on a daily rate',(function() use($pdo,$emps){ try { $pdo->prepare('UPDATE payroll_employee_rates SET pay_method="FIXED" WHERE employee_id=?')->execute([$emps['D']]); return false; } catch(PDOException) { return true; } })());
    $pdo->prepare('UPDATE payroll_employee_rates SET effective_to="2094-12-31" WHERE employee_id=?')->execute([$emps['M']]);
    PayrollAttendanceService::saveRate(['employee_id'=>$emps['M'],'effective_from'=>'2095-01-01','salary_basis'=>'MONTHLY','salary_amount'=>'20000','hourly_divisor'=>'160','pay_method'=>'FIXED']);
    check('fixed monthly rate saved',$pdo->query("SELECT pay_method FROM payroll_employee_rates WHERE employee_id={$emps['M']} AND effective_from='2095-01-01'")->fetchColumn()==='FIXED');

    // ---- Carry-forward of a negative net (BR-507, migration 047) ----
    $pdo->prepare('INSERT INTO payroll_cutoffs(code,period_start,period_end,status) VALUES(?,?,?,"OPEN")')->execute(["ZZRUN-2-$sfx",'2091-04-01','2091-04-15']); $cuts[2]=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO payroll_cutoff_runs(cutoff_id,state) VALUES(?,"FINALIZED")')->execute([$cuts[2]]);
    check('a later cutoff waits for the earlier computed run',str_contains(err(fn()=>PayrollRun::compute($cuts[2],'2091-04-20',$a)),'earlier computed payroll'));
    $dNet=(float)array_values(array_filter(PayrollRun::run($r3)['employees'],fn($e)=>(int)$e['employee_id']===$emps['D']))[0]['net'];
    PayrollRun::addAdjustment($r3,$emps['D'],'DEDUCTION','Loan balance',number_format($dNet+250,2,'.',''),$a);
    $d4=array_values(array_filter(PayrollRun::run($r3)['employees'],fn($e)=>(int)$e['employee_id']===$emps['D']))[0];
    check('a deduction above pay leaves net 0 and carries the excess',$near($d4['net'],0) && $near($d4['carry_out'],250) && (PayrollRun::run($r3)['issue_count']==0));
    PayrollRun::release($r3,$b);
    $je=Accounting::entry((int)PayrollRun::run($r3)['entry_id']);
    $dr12=array_sum(array_map(fn($l)=>(float)$l['debit'],array_filter($je['lines'],fn($l)=>$l['code']==='1200')));
    check('the carried balance is a Dr 1200 receivable in a balanced JE',abs($dr12-250)<0.001 && abs(array_sum(array_column($je['lines'],'debit'))-array_sum(array_column($je['lines'],'credit')))<0.001);
    $r4=PayrollRun::compute($cuts[2],'2091-04-20',$a);
    $d5=array_values(array_filter(PayrollRun::run($r4)['employees'],fn($e)=>(int)$e['employee_id']===$emps['D']))[0]??null;
    check('next payroll includes the employee and deducts the carried balance',$d5!==null && $near($line($d5,'CARRY')['amount']??0,250) && $near($d5['carry_out'],250),json_encode($d5));
    check('no contributions when the month has no pay (only a carried balance)',!array_filter($d5['lines'],fn($l)=>in_array($l['code'],PayrollRun::STAT,true)));

    // ---- Payslips ----
    $slips=PayrollRun::payslips($emps['M']);
    check('released payslips listed for the employee, unreleased not',count($slips)===2 && $near(end($slips)['net'],$m2['net']) && !in_array('2091-04-20',array_column($slips,'pay_date'),true));
    check('payslip opens only for its own employee',PayrollRun::payslip((int)$slips[0]['id'],$emps['M'])!==null && PayrollRun::payslip((int)$slips[0]['id'],$emps['D'])===null);
    check('compute, adjust, release and void are audited',(int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE id>$startAudit AND action IN ('run_computed','run_adjustment_added','run_released','run_voided')")->fetchColumn()>=6);
} finally {
    $in=$cuts?implode(',',$cuts):'0';
    $pdo->exec("UPDATE payroll_runs SET entry_id=NULL WHERE cutoff_id IN ($in)");
    $pdo->exec("DELETE FROM acct_journal_lines WHERE entry_id>$startEntry"); $pdo->exec("DELETE FROM acct_journal_entries WHERE id>$startEntry");
    $pdo->exec("DELETE FROM payroll_runs WHERE cutoff_id IN ($in)");
    $pdo->exec("DELETE FROM payroll_cutoff_snapshots WHERE cutoff_id IN ($in)"); $pdo->exec("DELETE FROM payroll_cutoff_runs WHERE cutoff_id IN ($in)"); $pdo->exec("DELETE FROM payroll_cutoffs WHERE id IN ($in)");
    $pdo->exec("DELETE FROM payroll_stat_tables WHERE version_label='ZZ run test'");
    if($emps) { $e=implode(',',$emps); $pdo->exec("DELETE FROM payroll_employee_rates WHERE employee_id IN ($e)"); $pdo->exec("DELETE FROM employees WHERE id IN ($e)"); }
    $q=$pdo->prepare('UPDATE payroll_rate_rules SET regular_multiplier=?,ot_multiplier=?,night_percent=? WHERE day_type=?'); foreach($rulesBefore as $r) $q->execute([$r['regular_multiplier'],$r['ot_multiplier'],$r['night_percent'],$r['day_type']]);
    $pdo->exec("DELETE FROM audit_logs WHERE id>$startAudit");
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$b]);
}
check('cleanup left no runs or employees',(int)$pdo->query('SELECT COUNT(*) FROM payroll_runs')->fetchColumn()===0 && (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE employee_no LIKE 'ZZRUN-%'")->fetchColumn()===0);
echo $fail?"\n$fail FAILED\n":"\nALL PASSED\n";
exit($fail?1:0);
