<?php
declare(strict_types=1);
// Payroll engine P1 statutory table parsing and lookups, no database:  php tests/payroll_statutory_test.php
// Every figure below is MADE UP for the test; none is a real SSS/PhilHealth/Pag-IBIG/BIR rate.
require dirname(__DIR__).'/app/PayrollStatutory.php';
$fail=0;
function check(string $label,bool $ok): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label."\n"; if(!$ok) $fail++; }
function err(callable $f): string { try { $f(); return ''; } catch(RuntimeException $e) { return $e->getMessage(); } }

// ---- Parsing ----
$sss=PayrollStatutory::parseRows('SSS',"0 999.99 10 20\n1,000 1999.99 30.5 60\n2000 - 50 100");
check('space rows parse with thousands commas',count($sss)===3 && $sss[1]['bracket_from']==='1000' && $sss[1]['employee_amount']==='30.5');
check('"-" means blank "to" on the last row',$sss[2]['bracket_to']===null);
$tab=PayrollStatutory::parseRows('PAGIBIG',"0\t1500\t1\t2\t\n1500.01\t\t2\t2\t5000");
check('tab rows: empty cell is blank optional column',$tab[0]['base_ceiling']===null && $tab[1]['bracket_to']===null && $tab[1]['base_ceiling']==='5000');
check('required cell blank refused',str_contains(err(fn()=>PayrollStatutory::parseRows('SSS',"0 100 - 5")),'Employee share is required'));
check('too many values refused',str_contains(err(fn()=>PayrollStatutory::parseRows('SSS',"0 100 1 2 3")),'more than 4'));
check('text refused',str_contains(err(fn()=>PayrollStatutory::parseRows('SSS',"0 abc 1 2")),'must be a number'));
check('negative refused',str_contains(err(fn()=>PayrollStatutory::parseRows('SSS',"-1 100 1 2")),'must be a number'));
check('5 decimals refused',str_contains(err(fn()=>PayrollStatutory::parseRows('SSS',"0 100 1.00001 2")),'4 decimals'));
check('percent above 100 refused',str_contains(err(fn()=>PayrollStatutory::parseRows('PHILHEALTH',"100 900 101 1")),'above 100'));
check('empty text refused',str_contains(err(fn()=>PayrollStatutory::parseRows('SSS',"  ")),'at least one row'));
check('unknown table refused',str_contains(err(fn()=>PayrollStatutory::parseRows('GSIS',"0 1 1 1")),'table type'));

// ---- Brackets ----
check('overlap refused',str_contains(err(fn()=>PayrollStatutory::parseRows('SSS',"0 100 1 2\n99.99 200 1 2")),'overlaps'));
check('"from" equal to the previous "to" allowed, lower row wins at the boundary',PayrollStatutory::contribution('BIR_WITHHOLDING',PayrollStatutory::parseRows('BIR_WITHHOLDING',"0 1000 0 0\n1000 - 0 10"),1000)['employee']===0.0);
check('descending refused',str_contains(err(fn()=>PayrollStatutory::parseRows('SSS',"100 200 1 2\n0 50 1 2")),'overlaps'));
check('"to" below "from" refused',str_contains(err(fn()=>PayrollStatutory::parseRows('SSS',"100 50 1 2")),'below'));
check('open-ended row must be last',str_contains(err(fn()=>PayrollStatutory::parseRows('SSS',"0 - 1 2\n100 200 1 2")),'last row'));
check('PhilHealth takes one row',str_contains(err(fn()=>PayrollStatutory::parseRows('PHILHEALTH',"100 900 1 1\n901 1000 1 1")),'one row'));

// ---- Contributions (BR-512 four decimals) ----
$c=fn(string $code,string $rows,float $base)=>PayrollStatutory::contribution($code,PayrollStatutory::parseRows($code,$rows),$base);
check('SSS: bracket amounts',$c('SSS',"0 999.99 10 20\n1000 1999.99 30.5 60\n2000 - 50 100",1500)===['employee'=>30.5,'employer'=>60.0]);
check('SSS: bracket edge is inclusive',$c('SSS',"0 999.99 10 20\n1000 - 30 60",999.99)['employee']===10.0);
check('SSS: open-ended top bracket',$c('SSS',"0 999.99 10 20\n1000 - 30 60",99999)['employer']===60.0);
check('SSS: base in a gap refused',str_contains(err(fn()=>$c('SSS',"0 999.99 10 20\n1000 - 30 60",999.995)),'no bracket'));
check('PhilHealth: below floor uses floor',$c('PHILHEALTH',"1000 5000 2 2",500)===['employee'=>20.0,'employer'=>20.0]);
check('PhilHealth: above ceiling uses ceiling',$c('PHILHEALTH',"1000 5000 2 2",9000)['employee']===100.0);
check('PhilHealth: in range uses base',$c('PHILHEALTH',"1000 5000 2.5 2.5",3000)['employee']===75.0);
check('Pag-IBIG: low bracket rates',$c('PAGIBIG',"0 1500 1 2 -\n1500.01 - 2 2 5000",1000)===['employee'=>10.0,'employer'=>20.0]);
check('Pag-IBIG: base capped at max',$c('PAGIBIG',"0 1500 1 2 -\n1500.01 - 2 2 5000",8000)===['employee'=>100.0,'employer'=>100.0]);
check('BIR: zero bracket',$c('BIR_WITHHOLDING',"0 1000 0 0\n1000.01 2000 0 10\n2000.01 - 100 20",800)['employee']===0.0);
check('BIR: fixed + rate on excess over from',$c('BIR_WITHHOLDING',"0 1000 0 0\n1000.01 2000 0 10\n2000.01 - 100 20",3000.01)===['employee'=>300.0,'employer'=>0.0]);
check('BIR: four decimals kept',$c('BIR_WITHHOLDING',"0 - 0 15",10.0003)['employee']===1.5);
check('negative base refused',str_contains(err(fn()=>$c('SSS',"0 - 1 1",-1)),'negative'));

// ---- Draft text round trip ----
$rows=[['bracket_from'=>'0.0000','bracket_to'=>'999.9900','employee_amount'=>'10.5000','employer_amount'=>'20.0000'],['bracket_from'=>'1000.0000','bracket_to'=>null,'employee_amount'=>'0.0000','employer_amount'=>'1000.0000']];
check('rowsText trims zeros and writes "-" for blank',PayrollStatutory::rowsText('SSS',$rows)==="0 999.99 10.5 20\n1000 - 0 1000");
check('rowsText parses back',PayrollStatutory::parseRows('SSS',PayrollStatutory::rowsText('SSS',$rows))[1]['employer_amount']==='1000');

echo $fail?"\n$fail FAILED\n":"\nALL PASSED\n";
exit($fail?1:0);
