<?php
declare(strict_types=1);

/**
 * Back pay / final pay (migration 051) against a SCRATCH copy of the database:
 *   HRIS_TEST_DB=hris_slice15_test_... C:\xampp\php\php.exe tests\payroll_final_pay_db_test.php
 * Made-up archived employee with released runs in 2095: separation date from history, pro-rated 13th month, SL leave
 * without a schedule is an issue, manual items, two-person release with clearance, JE, lock, void. Every row it creates is removed.
 */
$dbName=getenv('HRIS_TEST_DB') ?: '';
if($dbName==='' || in_array($dbName,['pmbsi_hris','hris_clean'],true)) { fwrite(STDERR,"Set HRIS_TEST_DB to a scratch copy.\n"); exit(2); }
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/Accounting.php';
require_once dirname(__DIR__).'/app/PayrollFinalPay.php';
$GLOBALS['pdo']=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(db()->query('SELECT DATABASE()')->fetchColumn()!==$dbName) exit(2);
if(!PayrollFinalPay::ready() || !Accounting::ready()) { fwrite(STDERR,"Run migrations 039 to 051 on the scratch copy first.\n"); exit(2); }

$fail=0;
function check(string $label,bool $ok,string $why=''): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label.($ok||$why===''?'':'  ['.$why.']')."\n"; if(!$ok) $fail++; }
function err(callable $f): string { try { $f(); return ''; } catch(RuntimeException $e) { return $e->getMessage(); } }
$near=fn($a,$b)=>abs((float)$a-(float)$b)<0.00005;

$pdo=db(); $sfx=bin2hex(random_bytes(3));
$a=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare('INSERT INTO users(role_id,full_name,email,password_hash) SELECT role_id,?,?,? FROM users WHERE id=?')->execute(['Back Pay Releaser',"fp-test-$sfx@example.invalid",'x',$a]);
$b=(int)$pdo->lastInsertId();
$_SESSION['user']=['id'=>$a,'role_code'=>'SUPER_ADMIN','role_portal'=>'admin'];
$startAudit=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetchColumn();
$startEntry=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM acct_journal_entries')->fetchColumn();
$sl=(int)$pdo->query("SELECT id FROM leave_types WHERE code='SL'")->fetchColumn();
$emps=[]; $cuts=[];
try {
    foreach(['R'=>'RESIGNED','A'=>'ACTIVE','N'=>'TERMINATED'] as $k=>$st) { $pdo->prepare('INSERT INTO employees(employee_no,first_name,last_name,status,hire_date) VALUES(?,?,?,?,"2020-01-01")')->execute(["ZZFP-$k-$sfx",'FP',"Test $k",$st]); $emps[$k]=(int)$pdo->lastInsertId(); }
    $hist=$pdo->prepare('INSERT INTO employee_employment_history(employee_id,event_type,effective_date,from_status,to_status,created_by) VALUES(?,?,?,?,?,?)');
    $hist->execute([$emps['R'],'RESIGNATION','2095-06-30','ACTIVE','RESIGNED',$a]);
    $pdo->prepare('INSERT INTO payroll_employee_rates(employee_id,effective_from,salary_basis,salary_amount,hourly_divisor) VALUES(?,?,?,?,?)')->execute([$emps['R'],'2090-01-01','DAILY',800,8]);
    $pdo->prepare('INSERT INTO employee_leave_balances(employee_id,leave_type_id,balance) VALUES(?,?,?)')->execute([$emps['R'],$sl,3]);
    $run=function(string $s,string $e,string $status,array $lines) use($pdo,$a,$b,$sfx,&$cuts,$emps) {
        $pdo->prepare('INSERT INTO payroll_cutoffs(code,period_start,period_end,status) VALUES(?,?,?,"OPEN")')->execute(["ZZFP-".count($cuts)."-$sfx",$s,$e]); $c=$cuts[]=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_runs(cutoff_id,pay_date,status,computed_by,released_by) VALUES(?,?,?,?,?)')->execute([$c,$e,$status,$a,$status==='RELEASED'?$b:null]); $r=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_run_employees(run_id,employee_id,issues_json) VALUES(?,?,"[]")')->execute([$r,$emps['R']]); $re=(int)$pdo->lastInsertId();
        foreach($lines as [$code,$amt]) $pdo->prepare('INSERT INTO payroll_run_lines(run_employee_id,kind,code,label,amount) VALUES(?,"EARNING",?,?,?)')->execute([$re,$code,$code,$amt]);
        return $r;
    };
    $run('2095-01-01','2095-03-31','RELEASED',[['BASIC',30000],['OT',4000]]);
    $run('2095-04-01','2095-06-30','RELEASED',[['REGULAR',30000],['ABSENT',-1200]]);
    $run('2094-12-01','2094-12-31','RELEASED',[['BASIC',99999]]); // previous year: ignored
    $open=$run('2095-06-16','2095-06-30','COMPUTED',[['BASIC',1]]);

    check('separation date read from the employment history',PayrollFinalPay::separationDate($emps['R'])==='2095-06-30');
    check('active employee refused; archived without a history date refused',str_contains(err(fn()=>PayrollFinalPay::compute($emps['A'],'2095-07-15','90000',$a)),'Employee Archive') && str_contains(err(fn()=>PayrollFinalPay::compute($emps['N'],'2095-07-15','90000',$a)),'No separation date'));
    check('pay date before separation refused',str_contains(err(fn()=>PayrollFinalPay::compute($emps['R'],'2095-06-01','90000',$a)),'before the separation'));
    check('the employee\'s computed (unreleased) payroll blocks back pay',str_contains(err(fn()=>PayrollFinalPay::compute($emps['R'],'2095-07-15','90000',$a)),'Release or void'));
    $pdo->prepare('UPDATE payroll_runs SET status="VOIDED",voided_by=? WHERE id=?')->execute([$a,$open]);

    $id=PayrollFinalPay::compute($emps['R'],'2095-07-15','90000',$a);
    $f=PayrollFinalPay::get($id); $iss=json_decode($f['issues_json'],true);
    check('13th month = (30000 + 30000 - 1200) / 12 = 4900; OT and other years ignored',$near($f['basic_ytd'],58800) && $near($f['thirteenth'],4900),json_encode([$f['basic_ytd'],$f['thirteenth']]));
    check('SL balance without a site schedule is an issue and release is blocked',$near($f['leave_days'],3) && $near($f['leave_amount'],0) && count($iss)===1 && str_contains(err(fn()=>PayrollFinalPay::release($id,$b,true)),'Resolve'));
    $pdo->prepare('UPDATE employee_leave_balances SET balance=0 WHERE employee_id=?')->execute([$emps['R']]);

    PayrollFinalPay::addItem($id,'DEDUCTION','Unreturned laptop','1500',$a);
    PayrollFinalPay::addItem($id,'TAX','Tax adjustment','100',$a);
    PayrollFinalPay::addItem($id,'EARNING','Unpaid allowance','600',$a);
    $f=PayrollFinalPay::get($id);
    check('items recompute: 4900 + 600 - 1500 - 100 = 3900, no issues',$near($f['net'],3900) && $near($f['earnings_total'],5500) && $near($f['deductions_total'],1600) && json_decode($f['issues_json'],true)===[] && count($f['items'])===3,json_encode([$f['net'],$f['issues_json']]));
    check('bad item input refused',err(fn()=>PayrollFinalPay::addItem($id,'BONUS','x','1',$a))!=='' && err(fn()=>PayrollFinalPay::addItem($id,'EARNING','x','-5',$a))!=='' && err(fn()=>PayrollFinalPay::addItem($id,'EARNING','','5',$a))!=='');
    PayrollFinalPay::addItem($id,'DEDUCTION','Cash advance','9000',$a);
    check('deductions above the back pay are an issue',str_contains(PayrollFinalPay::get($id)['issues_json'],'more than the back pay'));
    $ca=(int)$pdo->query("SELECT id FROM payroll_final_pay_items WHERE final_pay_id=$id AND label='Cash advance'")->fetchColumn();
    PayrollFinalPay::removeItem($id,$ca,$a);
    check('removing the item recomputes back to 3900',$near(PayrollFinalPay::get($id)['net'],3900));

    check('release needs the clearance confirmation',str_contains(err(fn()=>PayrollFinalPay::release($id,$b,false)),'clearance'));
    check('the person who computed cannot release',str_contains(err(fn()=>PayrollFinalPay::release($id,$a,true)),'different person'));
    PayrollFinalPay::release($id,$b,true);
    $f=PayrollFinalPay::get($id);
    $q=$pdo->prepare('SELECT a.code,l.debit,l.credit FROM acct_journal_lines l JOIN acct_accounts a ON a.id=l.account_id WHERE l.entry_id=? ORDER BY l.line_no'); $q->execute([(int)$f['entry_id']]); $j=[]; foreach($q->fetchAll() as $l) $j[$l['code']]=$l;
    check('release: clearance time kept, draft JE Dr 5030 4900 + 5010 600 / Cr 1200 1500 + 2130 100 + 1030 3900',$f['status']==='RELEASED' && $f['clearance_confirmed_at']!==null && $near($j['5030']['debit']??0,4900) && $near($j['5010']['debit']??0,600) && $near($j['1200']['credit']??0,1500) && $near($j['2130']['credit']??0,100) && $near($j['1030']['credit']??0,3900),json_encode($j));
    check('released back pay is locked',err(fn()=>PayrollFinalPay::compute($emps['R'],'2095-07-15','90000',$a))!=='' && err(fn()=>PayrollFinalPay::addItem($id,'EARNING','x','1',$a))!=='' && err(fn()=>PayrollFinalPay::void($id,$a))!=='');
    $c=array_column(PayrollFinalPay::candidates(),null,'id');
    check('candidates list archived employees only, with the back pay status',isset($c[$emps['R']]) && $c[$emps['R']]['final_status']==='RELEASED' && !isset($c[$emps['A']]) && isset($c[$emps['N']]));
    check('compute, items, release are audited',(int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE id>$startAudit AND action IN ('final_pay_computed','final_pay_item_added','final_pay_item_removed','final_pay_released')")->fetchColumn()>=8);

    // Void path: terminated employee with a history date and no pay.
    $hist->execute([$emps['N'],'TERMINATION','2095-08-31','ACTIVE','TERMINATED',$a]);
    $v=PayrollFinalPay::compute($emps['N'],'2095-09-15','90000',$a);
    check('no released pay = zero back pay, release refused',str_contains(err(fn()=>PayrollFinalPay::release($v,$b,true)),'no back pay'));
    PayrollFinalPay::void($v,$a);
    check('voided back pay frees the employee for a new compute',PayrollFinalPay::compute($emps['N'],'2095-09-15','90000',$a)!==$v);
} finally {
    $in=$emps?implode(',',$emps):'0';
    $pdo->exec("UPDATE payroll_final_pays SET entry_id=NULL WHERE employee_id IN ($in)");
    $pdo->exec("DELETE FROM acct_journal_lines WHERE entry_id>$startEntry"); $pdo->exec("DELETE FROM acct_journal_entries WHERE id>$startEntry");
    $pdo->exec("DELETE FROM payroll_final_pays WHERE employee_id IN ($in)");
    $ci=$cuts?implode(',',$cuts):'0';
    $pdo->exec("DELETE FROM payroll_runs WHERE cutoff_id IN ($ci)"); $pdo->exec("DELETE FROM payroll_cutoffs WHERE id IN ($ci)");
    $pdo->exec("DELETE FROM employee_leave_balances WHERE employee_id IN ($in)"); $pdo->exec("DELETE FROM employee_employment_history WHERE employee_id IN ($in)");
    $pdo->exec("DELETE FROM payroll_employee_rates WHERE employee_id IN ($in)"); $pdo->exec("DELETE FROM employees WHERE id IN ($in)");
    $pdo->exec("DELETE FROM audit_logs WHERE id>$startAudit");
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$b]);
}
check('cleanup left no back pay or test employees',(int)$pdo->query('SELECT COUNT(*) FROM payroll_final_pays')->fetchColumn()===0 && (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE employee_no LIKE 'ZZFP-%'")->fetchColumn()===0);
echo $fail?"\n$fail FAILED\n":"\nALL PASSED\n";
exit($fail?1:0);
