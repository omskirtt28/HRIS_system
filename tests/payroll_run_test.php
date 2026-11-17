<?php
declare(strict_types=1);
// Payroll engine P2-P5 formulas, no database:  php tests/payroll_run_test.php
// Every rate and table below is MADE UP; expected values are hand-computed.
require dirname(__DIR__).'/app/PayrollRun.php';
$fail=0;
function check(string $label,bool $ok): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label."\n"; if(!$ok) $fail++; }
$near=fn(float $a,float $b)=>abs($a-$b)<0.00005;

// ---- dayPay: hourly = 800/8 = 100 ----
$rate=['salary_amount'=>'800','hourly_divisor'=>'8'];
$rules=['REGULAR'=>['regular_multiplier'=>'1','ot_multiplier'=>'1.25','night_percent'=>'10'],'REST_DAY'=>['regular_multiplier'=>'1.3','ot_multiplier'=>'1.69','night_percent'=>null],'SPECIAL_HOLIDAY'=>['regular_multiplier'=>null,'ot_multiplier'=>null,'night_percent'=>null]];
$day=fn(array $o)=>$o+['work_date'=>'2091-01-05','day_type'=>'REGULAR','regular_minutes'=>0,'regular_ot_minutes'=>0,'night_ot_minutes'=>0,'holiday_ot_minutes'=>0,'holiday_night_ot_minutes'=>0];
$p=PayrollRun::dayPay($day(['regular_minutes'=>480]),$rate,$rules);
check('regular 8h x 100 x 1 = 800',$near($p['REGULAR'],800) && !$p['issues']);
$p=PayrollRun::dayPay($day(['regular_minutes'=>480,'regular_ot_minutes'=>60,'night_ot_minutes'=>60]),$rate,$rules);
check('OT 2h x 100 x 1.25 = 250',$near($p['OT'],250));
check('night = 10% of the night OT hour pay (125) = 12.5',$near($p['NIGHT'],12.5));
$p=PayrollRun::dayPay($day(['day_type'=>'REST_DAY','regular_minutes'=>240]),$rate,$rules);
check('rest day multiplier 1.3 on 4h = 520',$near($p['REGULAR'],520));
$p=PayrollRun::dayPay($day(['day_type'=>'REST_DAY','night_ot_minutes'=>30]),$rate,$rules);
check('missing night % flagged, OT still paid',$near($p['OT'],84.5) && $p['NIGHT']===0.0 && str_contains(implode(' ',$p['issues']),'Night premium for Rest Day'));
$p=PayrollRun::dayPay($day(['day_type'=>'SPECIAL_HOLIDAY','regular_minutes'=>60]),$rate,$rules);
check('missing multiplier flagged and unpaid',$p['REGULAR']===0.0 && str_contains($p['issues'][0],'Special Holiday'));
check('no rate flagged only when minutes exist',PayrollRun::dayPay($day(['regular_minutes'=>60]),null,$rules)['issues']!==[] && PayrollRun::dayPay($day([]),null,$rules)['issues']===[]);
check('unknown day type flagged',PayrollRun::dayPay($day(['day_type'=>'DOUBLE_HOLIDAY','regular_minutes'=>60]),$rate,$rules)['issues']!==[]);

// ---- settle ----
$t=fn(string $code,string $rows,int $id)=>['id'=>$id,'rows'=>PayrollStatutory::parseRows($code,$rows)];
$tables=['SSS'=>$t('SSS',"0 9999.99 100 200\n10000 - 300 600",1),'PHILHEALTH'=>$t('PHILHEALTH',"1000 50000 2 2",2),'PAGIBIG'=>$t('PAGIBIG',"0 1500 1 2 -\n1500.01 - 2 2 5000",3),'BIR'=>$t('BIR_WITHHOLDING',"0 5000 0 0\n5000 - 0 10",4)];
$s=PayrollRun::settle(['earnings'=>['REGULAR'=>10000,'OT'=>0,'NIGHT'=>0],'adjustments'=>[],'basis'=>'MONTHLY','salary'=>20000,'tables'=>$tables,'last'=>false,'prev'=>[]]);
// monthly base 20000: SSS 300/600, PH 400/400, Pag-IBIG base capped 5000 -> 100/100; half each.
$ln=fn(array $s,string $code)=>array_values(array_filter($s['lines'],fn($l)=>$l[1]===$code))[0]??null;
check('first cutoff: monthly-paid base is the monthly salary',$near($s['base'],20000));
check('first cutoff: half of SSS',$near($ln($s,'SSS')[3],150) && $near($ln($s,'SSS')[4],300));
check('first cutoff: half of PhilHealth and Pag-IBIG',$near($ln($s,'PHILHEALTH')[3],200) && $near($ln($s,'PAGIBIG')[3],50));
check('lines cite the statutory version (BR-503)',$ln($s,'SSS')[5]===1 && $ln($s,'WHT')[5]===4);
// taxable = 10000 - (150+200+50) = 9600; tax = 10% of (9600-5000) = 460
check('taxable excludes employee shares',$near($s['taxable'],9600));
check('withholding on the semi-monthly table',$near($ln($s,'WHT')[3],460));
check('net = gross - deductions',$near($s['net'],10000-400-460) && $near($s['employer'],300+200+50) && !$s['issues']);
$s2=PayrollRun::settle(['earnings'=>['REGULAR'=>10000],'adjustments'=>[],'basis'=>'MONTHLY','salary'=>20000,'tables'=>$tables,'last'=>true,'prev'=>['gross'=>10000,'SSS'=>['employee'=>150,'employer'=>300],'PHILHEALTH'=>['employee'=>200,'employer'=>200],'PAGIBIG'=>['employee'=>50,'employer'=>50]]]);
check('last cutoff deducts the month balance: halves sum to the monthly (BR-509)',$near($ln($s2,'SSS')[3]+150,300) && $near($ln($s2,'PHILHEALTH')[4]+200,400) && $near($ln($s2,'PAGIBIG')[3]+50,100));
$odd=['SSS'=>$t('SSS',"0 - 100.01 200.03",1)]+$tables;
$a=PayrollRun::settle(['earnings'=>['REGULAR'=>5000],'adjustments'=>[],'basis'=>'MONTHLY','salary'=>9000,'tables'=>$odd,'last'=>false,'prev'=>[]]);
$b=PayrollRun::settle(['earnings'=>['REGULAR'=>5000],'adjustments'=>[],'basis'=>'MONTHLY','salary'=>9000,'tables'=>$odd,'last'=>true,'prev'=>['SSS'=>['employee'=>$ln($a,'SSS')[3],'employer'=>$ln($a,'SSS')[4]]]]);
check('odd centavos still reconcile to the monthly total',$near($ln($a,'SSS')[3]+$ln($b,'SSS')[3],100.01) && $near($ln($a,'SSS')[4]+$ln($b,'SSS')[4],200.03));
$d=PayrollRun::settle(['earnings'=>['REGULAR'=>4000],'adjustments'=>[],'basis'=>'DAILY','salary'=>500,'tables'=>$tables,'last'=>false,'prev'=>[]]);
check('daily-paid first cutoff projects base = gross x 2',$near($d['base'],8000));
$d2=PayrollRun::settle(['earnings'=>['REGULAR'=>7000],'adjustments'=>[],'basis'=>'DAILY','salary'=>500,'tables'=>$tables,'last'=>true,'prev'=>['gross'=>4000]]);
check('daily-paid last cutoff uses the actual month (4000+7000), no earlier run = whole month',$near($d2['base'],11000) && $near($ln($d2,'SSS')[3],300));
$m=PayrollRun::settle(['earnings'=>['REGULAR'=>10000],'adjustments'=>[['EARNING','Allowance','1000'],['DEDUCTION','Cash advance','500']],'basis'=>'MONTHLY','salary'=>20000,'tables'=>$tables,'last'=>false,'prev'=>[]]);
check('manual earning is taxable and in gross',$near($m['gross'],11000) && $near($m['taxable'],10600) && $near($ln($m,'WHT')[3],560));
check('manual deduction after tax',$near($m['net'],11000-400-560-500));
$neg=PayrollRun::settle(['earnings'=>['REGULAR'=>100],'adjustments'=>[],'basis'=>'MONTHLY','salary'=>20000,'tables'=>$tables,'last'=>false,'prev'=>[]]);
// BR-507 (John 2026-11-17, option A): ee 150+200+50=400 on gross 100 -> net 0, 300 carried to the next payroll.
check('negative net carries forward instead of a negative payslip',$neg['net']===0.0 && $near($neg['carry_out'],300) && $neg['issues']===[]);
$ci=PayrollRun::settle(['earnings'=>['REGULAR'=>10000],'adjustments'=>[['EARNING','Allowance','1000'],['DEDUCTION','Cash advance','500']],'basis'=>'MONTHLY','salary'=>20000,'tables'=>$tables,'last'=>false,'prev'=>[],'carry_in'=>300]);
check('carried balance deducted after tax as its own line',$near($ci['net'],11000-400-560-500-300) && $near($ln($ci,'CARRY')[3],300) && $near($ci['taxable'],10600));

// ---- earnings: Mon 2091-01-01..Sat 01-06, workdays Mon-Fri, 8h schedule, hourly 100, Thu = special holiday ----
$er=['REGULAR'=>['regular_multiplier'=>'1','ot_multiplier'=>'1.25','night_percent'=>'10'],'REST_DAY'=>['regular_multiplier'=>'1.3','ot_multiplier'=>'1.69','night_percent'=>'10'],'SPECIAL_HOLIDAY'=>['regular_multiplier'=>'1.3','ot_multiplier'=>'1.69','night_percent'=>'10']];
$snap=fn(string $d,array $o)=>$o+['work_date'=>$d,'day_type'=>'ATTENDANCE_REVIEW','regular_minutes'=>0,'late_minutes'=>0,'undertime_minutes'=>0,'regular_ot_minutes'=>0,'night_ot_minutes'=>0,'holiday_ot_minutes'=>0,'holiday_night_ot_minutes'=>0,'state'=>'REVIEW'];
$days=[$snap('2091-01-01',['regular_minutes'=>480,'late_minutes'=>30]),$snap('2091-01-02',[]),$snap('2091-01-03',[]),$snap('2091-01-04',['regular_minutes'=>480]),$snap('2091-01-05',['regular_minutes'=>480,'regular_ot_minutes'=>60])];
$ctx=fn(array $rate,?int $sched=480)=>['period_start'=>'2091-01-01','period_end'=>'2091-01-06','rate'=>$rate,'rateOn'=>fn($d)=>$rate,'rules'=>$er,'workdays'=>'1,2,3,4,5','holidays'=>['2091-01-04'=>'SPECIAL_HOLIDAY'],'schedule_minutes'=>$sched,'leave'=>['2091-01-02'=>0.5]];
$lines=fn(array $e)=>array_column($e['lines'],2,0);
check('dayType: holiday on a rest day uses the _REST_DAY rule when set',PayrollRun::dayType('2091-01-06','1,2,3,4,5','SPECIAL_HOLIDAY',['SPECIAL_HOLIDAY_REST_DAY'=>[]])==='SPECIAL_HOLIDAY_REST_DAY' && PayrollRun::dayType('2091-01-06','1,2,3,4,5',null,[])==='REST_DAY' && PayrollRun::dayType('2091-01-06',null,null,[])==='REGULAR');
$f=PayrollRun::earnings($days,$ctx(['pay_method'=>'FIXED','salary_basis'=>'MONTHLY','salary_amount'=>'20000','hourly_divisor'=>'200']));
$l=$lines($f);
check('FIXED: half month 10000, holiday premium 8h x 100 x 0.3 = 240, OT 125',$near($l['BASIC'],10000) && $near($l['PREMIUM'],240) && $near($l['OT'],125) && !isset($l['REGULAR']));
check('FIXED: absences = half leave day 400 + full day 800, late 30m = 50',$near($l['ABSENT'],-1200) && $near($l['LATE'],-50) && $f['issues']===[]);
$h=PayrollRun::earnings($days,$ctx(['pay_method'=>'PER_HOUR','salary_basis'=>'DAILY','salary_amount'=>'800','hourly_divisor'=>'8']));
$l=$lines($h);
check('PER_HOUR: 8h+8h x1 + holiday 8h x1.3 = 2640, OT 125, paid half leave 400',$near($l['REGULAR'],2640) && $near($l['OT'],125) && $near($l['LEAVE'],400) && !isset($l['ABSENT']) && $h['issues']===[]);
$n=PayrollRun::earnings($days,$ctx(['pay_method'=>'FIXED','salary_basis'=>'MONTHLY','salary_amount'=>'20000','hourly_divisor'=>'200'],null));
check('FIXED without a site schedule is an issue (no absence deduction guessed)',str_contains(implode(' ',$n['issues']),'Work schedule') && !isset($lines($n)['ABSENT']));
$none=PayrollRun::settle(['earnings'=>['REGULAR'=>100],'adjustments'=>[],'basis'=>'MONTHLY','salary'=>100,'tables'=>['SSS'=>null,'PHILHEALTH'=>null,'PAGIBIG'=>null,'BIR'=>null],'last'=>false,'prev'=>[]]);
check('missing verified tables are issues',count($none['issues'])===4 && str_contains($none['issues'][0],'No verified SSS'));

// ---- journal lines (cents) ----
$sum=['earnings'=>10000.005,'employer'=>550,'WHT'=>460,'adj_deductions'=>0,'SSS'=>['employee'=>150,'employer'=>300],'PHILHEALTH'=>['employee'=>200,'employer'=>200],'PAGIBIG'=>['employee'=>50,'employer'=>50]];
$j=PayrollRun::journalLines($sum,'Payroll X');
$dr=array_sum(array_column($j,2)); $cr=array_sum(array_column($j,3));
check('journal balances',$dr===$cr && $dr===1000001+55000);
$by=[]; foreach($j as $l) $by[$l[0]]=$l;
check('Dr salaries and employer contributions',$by['5010'][2]===1000001 && $by['5020'][2]===55000);
check('Cr payables EE+ER and tax',$by['2100'][3]===45000 && $by['2110'][3]===40000 && $by['2120'][3]===10000 && $by['2130'][3]===46000);
check('Cr bank = net pay absorbs rounding',$by['1030'][3]===1000001+55000-45000-40000-10000-46000);
check('zero lines left out',!isset($by['1200']));
$sum['SSS']=['employee'=>-10,'employer'=>-20]; $sum['employer']=230;
$j=PayrollRun::journalLines($sum,'X'); $by=[]; foreach($j as $l) $by[$l[0]]=$l;
check('a negative month balance flips to a debit',$by['2100'][2]===3000 && $by['2100'][3]===0 && array_sum(array_column($j,2))===array_sum(array_column($j,3)));

$sum=['earnings'=>1000,'employer'=>0,'WHT'=>0,'adj_deductions'=>1300,'carry_out'=>300,'SSS'=>['employee'=>0,'employer'=>0],'PHILHEALTH'=>['employee'=>0,'employer'=>0],'PAGIBIG'=>['employee'=>0,'employer'=>0]];
$j=PayrollRun::journalLines($sum,'X'); $c12=array_values(array_filter($j,fn($l)=>$l[0]==='1200'));
check('carried balance is a Dr 1200 receivable, deductions Cr 1200, bank 0',count($c12)===2 && $c12[0][2]===30000 && $c12[1][3]===130000 && !in_array('1030',array_column($j,0),true) && array_sum(array_column($j,2))===array_sum(array_column($j,3)));

echo $fail?"\n$fail FAILED\n":"\nALL PASSED\n";
exit($fail?1:0);
