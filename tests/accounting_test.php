<?php
declare(strict_types=1);
// Accounting money parsing and screen contracts, no database:  php tests/accounting_test.php
require dirname(__DIR__).'/app/Accounting.php';
require dirname(__DIR__).'/app/AccountsPayable.php';
$fail=0;
function check(string $label,bool $ok): void { global $fail; echo ($ok?'PASS ':'FAIL ').$label."\n"; if(!$ok) $fail++; }
$throws=function(string $v): bool { try { Accounting::cents($v); return false; } catch(RuntimeException) { return true; } };

check('cents: whole number',Accounting::cents('1500')===150000);
check('cents: commas and one decimal',Accounting::cents('1,234.5')===123450);
check('cents: two decimals',Accounting::cents('0.07')===7);
check('cents: blank is zero',Accounting::cents('  ')===0);
check('cents: refuses negative',$throws('-5'));
check('cents: refuses 3 decimals',$throws('1.005'));
check('cents: refuses text and exponent',$throws('1e5') && $throws('abc'));
check('cents: refuses more than 13 digits',$throws('12345678901234'));
check('money: format and negative in brackets',Accounting::money(123456)==='1,234.56' && Accounting::money(-500)==='(5.00)');
check('debit-normal types are assets and expenses',Accounting::debitNormal('A') && Accounting::debitNormal('X') && !Accounting::debitNormal('L') && !Accounting::debitNormal('I'));

$views=file_get_contents(dirname(__DIR__).'/app/AccountingViews.php').file_get_contents(dirname(__DIR__).'/app/AccountsPayableViews.php').file_get_contents(dirname(__DIR__).'/app/AccountsReceivableViews.php').file_get_contents(dirname(__DIR__).'/app/CashBankViews.php').file_get_contents(dirname(__DIR__).'/app/RemittancesViews.php');
$module=file_get_contents(dirname(__DIR__).'/app/AccountingModule.php');
$css=file_get_contents(dirname(__DIR__).'/public/assets/accounting.css');
$nav=file_get_contents(dirname(__DIR__).'/app/View.php');
check('every POST form carries a CSRF field',substr_count($views,'<form method="post"')===substr_count($views,'csrf_field()'));
check('handler verifies CSRF before any action',strpos($module,'verify_csrf()')<strpos($module,'switch($action)'));
check('write actions check entry/approve permissions',substr_count($module,"requirePermission('accounting.approve')")>=6 && substr_count($module,"requirePermission('accounting.entry')")===10); // journal save, submit, void; vendor, bill, customer, invoice save; payment save; remittance save; recon finish (approve also: bank save, recon undo)
check('screens require accounting.view',str_contains($module,"Auth::requirePermission('accounting.view')"));
check('CSV neutralises formula cells',str_contains($views,"preg_match('/^[=+\\-@\\t\\r]/'"));
check('sidebar shows only built screens',str_contains($nav,"'accounting.view'") && !str_contains($nav,"'accounting-'.\$x[1],\$x[2],false,'reports.view'") && !preg_match("/\\['Accounts Payable','ap'/",$nav));
check('no sample-data demo left',!str_contains(file_get_contents(dirname(__DIR__).'/public/assets/accounting.js'),'Maria Santos') && !is_file(dirname(__DIR__).'/public/accounting-preview.html'));
check('CSS uses tokens, focus-visible, 40px targets',!preg_match('/#[0-9a-f]{3,6}\b/i',$css) && str_contains($css,':focus-visible') && str_contains($css,'min-height:40px'));
// AP aging buckets (slice A1): days past the due date.
check('aging: due today or not yet due is current',AccountsPayable::bucket(0)==='current' && AccountsPayable::bucket(-10)==='current');
check('aging: 1 and 30 days are 1-30',AccountsPayable::bucket(1)==='d30' && AccountsPayable::bucket(30)==='d30');
check('aging: 31/60/61/90 edges',AccountsPayable::bucket(31)==='d60' && AccountsPayable::bucket(60)==='d60' && AccountsPayable::bucket(61)==='d90' && AccountsPayable::bucket(90)==='d90');
check('aging: 91 days is over 90',AccountsPayable::bucket(91)==='over90');
check('sidebar shows Bills and Vendors',str_contains($nav,"['Bills','bills','receipt'],['Vendors','vendors','users']"));
check('sidebar shows Remittances',str_contains($nav,"['Remittances','remittances','wallet']"));
echo $fail?"\n$fail FAIL\n":"\nALL PASS\n"; exit($fail?1:0);
