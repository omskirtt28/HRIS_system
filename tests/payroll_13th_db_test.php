<?php
declare(strict_types=1);

/**
 * 13th month pay (migration 050) against a SCRATCH copy of the database:
 *   HRIS_TEST_DB=hris_slice15_test_... C:\xampp\php\php.exe tests\payroll_13th_db_test.php
 * Builds made-up released payroll runs in 2093 and checks: base codes only, resigned excluded, unreleased run blocks,
 * two-person release, journal Dr 5030 / Cr 1030, lock after release, void, employee view. Every row it creates is removed.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if($dbName==='' || in_array($dbName,['pmbsi_hris','hris_clean'],true)) { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy.\n"); exit(2); }
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/Accounting.php';
require_once dirname(__DIR__).'/app/PayrollThirteenth.php';
$GLOBALS['pdo']=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(db()->query('SELECT DATABASE()')->fetchColumn()!==$dbName) exit(2);
if(!PayrollThirteenth::ready() || !Accounting::ready()) { fwrite(STDERR,"Run migrations 039 to 050 on the scratch copy first.\n"); exit(2); }

$fail=0;
function check(string $label,bool $ok,string $why=''): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label.($ok||$why===''?'':'  ['.$why.']')."\n"; if(!$ok) $fail++; }
function err(callable $f): string { try { $f(); return ''; } catch(RuntimeException $e) { return $e->getMessage(); } }
$near=fn($a,$b)=>abs((float)$a-(float)$b)<0.00005;

$pdo=db(); $sfx=bin2hex(random_bytes(3));
$a=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT role_id,?,?,? FROM users WHERE id=?')->execute(['13th Test Releaser',"p13-test-$sfx@example.invalid",'x',$a]);
$b=(int)$pdo->lastInsertId();
$_SESSION['user']=['id'=>$a,'role_code'=>'SUPER_ADMIN','role_portal'=>'admin'];
$startAudit=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetchColumn();
$startEntry=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM acct_journal_entries')->fetchColumn();
$emps=[]; $cuts=[]; $p13=[];
try {
    foreach(['A'=>'ACTIVE','L'=>'ON_LEAVE','R'=>'RESIGNED','I'=>'INACTIVE','T'=>'TERMINATED'] as $k=>$st) { $pdo->prepare('INSERT INTO employees(employee_no,first_name,last_name,status,hire_date) VALUES(?,?,?,?,"2020-01-01")')->execute(["ZZP13-$k-$sfx",'P13',"Test $k",$st]); $emps[$k]=(int)$pdo->lastInsertId(); }
    $cut=function(string $s,string $e,int $i) use($pdo,$sfx,&$cuts) { $pdo->prepare('INSERT INTO payroll_cutoffs(code,period_start,period_end,status) VALUES(?,?,?,"OPEN")')->execute(["ZZP13-$i-$sfx",$s,$e]); return $cuts[]=(int)$pdo->lastInsertId(); };
    $run=function(int $cutoff,string $status,array $byEmp) use($pdo,$a,$b) {
        $pdo->prepare('INSERT INTO payroll_runs(cutoff_id,pay_date,status,computed_by,released_by) VALUES(?,?,?,?,?)')->execute([$cutoff,'2093-12-31',$status,$a,$status==='RELEASED'?$b:null]); $r=(int)$pdo->lastInsertId();
        foreach($byEmp as $emp=>$lines) {
            $pdo->prepare('INSERT INTO payroll_run_employees(run_id,employee_id,issues_json) VALUES(?,?,"[]")')->execute([$r,$emp]); $re=(int)$pdo->lastInsertId();
            foreach($lines as [$kind,$code,$amt]) $pdo->prepare('INSERT INTO payroll_run_lines(run_employee_id,kind,code,label,amount) VALUES(?,?,?,?,?)')->execute([$re,$kind,$code,$code,$amt]);
        }
        return $r;
    };
    // A: basic 120000 + regular 60000 + leave 6000 - absent 3000 - late 600 = 182400 base; OT, premium, night, adjustments ignored.
    $run($cut('2093-01-01','2093-06-30',0),'RELEASED',[
        $emps['A']=>[['EARNING','BASIC',120000],['EARNING','OT',5000],['EARNING','PREMIUM',2000],['EARNING','ABSENT',-3000],['DEDUCTION','SSS',900]],
        $emps['L']=>[['EARNING','BASIC',60000]],
        $emps['R']=>[['EARNING','BASIC',50000]],$emps['I']=>[['EARNING','BASIC',50000]],$emps['T']=>[['EARNING','BASIC',50000]]]);
    $run($cut('2093-07-01','2093-12-31',1),'RELEASED',[
        $emps['A']=>[['EARNING','REGULAR',60000],['EARNING','LEAVE',6000],['EARNING','LATE',-600],['EARNING','NIGHT',700],['EARNING','ADJ',1500]]]);
    $run($cut('2092-12-01','2092-12-31',2),'RELEASED',[$emps['A']=>[['EARNING','BASIC',99999]]]); // other year: ignored
    $open=$run($cut('2093-12-01','2093-12-15',3),'COMPUTED',[$emps['A']=>[['EARNING','BASIC',1]]]);

    check('invalid inputs refused',str_contains(err(fn()=>PayrollThirteenth::compute('93','2093-12-20','90000',$a)),'year') && str_contains(err(fn()=>PayrollThirteenth::compute('2093','2093-12-20','',$a)),'ceiling') && str_contains(err(fn()=>PayrollThirteenth::compute('2093','2092-12-20','90000',$a)),'before the year'));
    check('an unreleased payroll run of the year blocks the compute',str_contains(err(fn()=>PayrollThirteenth::compute('2093','2093-12-20','90000',$a)),'Release or void'));
    $pdo->prepare('UPDATE payroll_runs SET status="VOIDED",voided_by=? WHERE id=?')->execute([$a,$open]);

    $id=PayrollThirteenth::compute('2093','2093-12-20','90000',$a); $p13[]=$id;
    $r=PayrollThirteenth::run($id); $by=array_column($r['employees'],null,'employee_id');
    check('base = basic/regular/leave less absences and late only: 182400 / 12 = 15200',isset($by[$emps['A']]) && $near($by[$emps['A']]['basic_total'],182400) && $near($by[$emps['A']]['amount'],15200),json_encode($by[$emps['A']]??null));
    check('on-leave employee included (60000 / 12 = 5000); resigned, inactive and terminated excluded',$near($by[$emps['L']]['amount']??0,5000) && !isset($by[$emps['R']]) && !isset($by[$emps['I']]) && !isset($by[$emps['T']]));
    check('totals and nothing taxable under the ceiling',(int)$r['employee_count']===2 && $near($r['total_amount'],20200) && $near($r['total_taxable'],0));

    $id2=PayrollThirteenth::compute('2093','2093-12-21','10000',$a);
    $r=PayrollThirteenth::run($id2); $by=array_column($r['employees'],null,'employee_id');
    check('recompute replaces the same run; excess over a 10000 ceiling is taxable (5200)',$id2===$id && count($r['employees'])===2 && $near($by[$emps['A']]['taxable'],5200) && $near($r['total_taxable'],5200) && $r['pay_date']==='2093-12-21');

    check('the person who computed cannot release',str_contains(err(fn()=>PayrollThirteenth::release($id,$a)),'different person'));
    PayrollThirteenth::release($id,$b);
    $r=PayrollThirteenth::run($id);
    $lines=$pdo->prepare('SELECT a.code,l.debit,l.credit FROM acct_journal_lines l JOIN acct_accounts a ON a.id=l.account_id WHERE l.entry_id=? ORDER BY l.line_no'); $lines->execute([(int)$r['entry_id']]); $j=$lines->fetchAll();
    check('release posts one draft entry Dr 5030 / Cr 1030 for the total',$r['status']==='RELEASED' && count($j)===2 && $j[0]['code']==='5030' && $near($j[0]['debit'],20200) && $j[1]['code']==='1030' && $near($j[1]['credit'],20200),json_encode($j));
    check('released run is locked: no recompute, release or void',str_contains(err(fn()=>PayrollThirteenth::compute('2093','2093-12-20','90000',$a)),'already released') && err(fn()=>PayrollThirteenth::release($id,$b))!=='' && err(fn()=>PayrollThirteenth::void($id,$a))!=='');
    $mine=PayrollThirteenth::forEmployee($emps['A']);
    check('employee sees only their own released 13th month',count($mine)===1 && $near($mine[0]['amount'],15200) && PayrollThirteenth::forEmployee($emps['R'])===[]);

    // Void path on another year.
    $run($cut('2094-01-01','2094-01-15',4),'RELEASED',[$emps['A']=>[['EARNING','BASIC',12000]]]);
    $v=PayrollThirteenth::compute('2094','2094-12-20','90000',$a); $p13[]=$v;
    PayrollThirteenth::void($v,$a);
    $v2=PayrollThirteenth::compute('2094','2094-12-20','90000',$a); $p13[]=$v2;
    check('a voided run frees the year for a new compute',$v2!==$v && PayrollThirteenth::run($v)['status']==='VOIDED' && $near(PayrollThirteenth::run($v2)['total_amount'],1000));
    check('compute, release and void are audited',(int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE id>$startAudit AND action IN ('13th_computed','13th_released','13th_voided')")->fetchColumn()>=5);
} finally {
    $pdo->exec('UPDATE payroll_13th_runs SET entry_id=NULL WHERE id IN ('.($p13?implode(',',$p13):'0').')');
    $pdo->exec("DELETE FROM acct_journal_lines WHERE entry_id>$startEntry"); $pdo->exec("DELETE FROM acct_journal_entries WHERE id>$startEntry");
    $pdo->exec('DELETE FROM payroll_13th_runs WHERE id IN ('.($p13?implode(',',$p13):'0').')');
    $in=$cuts?implode(',',$cuts):'0';
    $pdo->exec("DELETE FROM payroll_runs WHERE cutoff_id IN ($in)"); $pdo->exec("DELETE FROM payroll_cutoffs WHERE id IN ($in)");
    if($emps) $pdo->exec('DELETE FROM employees WHERE id IN ('.implode(',',$emps).')');
    $pdo->exec("DELETE FROM audit_logs WHERE id>$startAudit");
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$b]);
}
check('cleanup left no 13th month runs or test employees',(int)$pdo->query('SELECT COUNT(*) FROM payroll_13th_runs')->fetchColumn()===0 && (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE employee_no LIKE 'ZZP13-%'")->fetchColumn()===0);
echo $fail?"\n$fail FAILED\n":"\nALL PASSED\n";
exit($fail?1:0);
