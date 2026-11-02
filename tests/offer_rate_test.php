<?php
declare(strict_types=1);
// offer-rate-type (SPEC-offer-rate-type.md). Pure unit test, no database.
require __DIR__.'/../app/OfferRate.php';
$bad=0;
function t(string $label,bool $ok): void { global $bad; echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL; if(!$ok)$bad++; }
function throws(callable $fn): bool { try{$fn();}catch(RuntimeException){return true;} return false; }

$m=OfferRate::convert('MONTHLY',20800.0);
t('monthly 20,800 gives daily 800 and hourly 100',$m==['monthly'=>20800.0,'daily'=>800.0,'hourly'=>100.0]);
$d=OfferRate::convert('DAILY',800.0);
t('daily 800 gives monthly 20,800 (26 days) and hourly 100',$d==['monthly'=>20800.0,'daily'=>800.0,'hourly'=>100.0]);
$h=OfferRate::convert('HOURLY',100.0);
t('hourly 100 gives daily 800 and monthly 20,800',$h==['monthly'=>20800.0,'daily'=>800.0,'hourly'=>100.0]);
t('amounts are rounded to 2 decimals',OfferRate::convert('DAILY',533.33)['monthly']===13866.58);
t('an unknown type is refused',throws(fn()=>OfferRate::convert('WEEKLY',100.0)));
t('zero is refused',throws(fn()=>OfferRate::convert('DAILY',0.0)));
t('a negative rate is refused',throws(fn()=>OfferRate::convert('MONTHLY',-5.0)));
t('NaN is refused',throws(fn()=>OfferRate::convert('MONTHLY',NAN)));
t('an absurd amount is refused',throws(fn()=>OfferRate::convert('HOURLY',1e12)));
t('the dropdown lists Monthly, Daily, Hourly in that order',array_values(OfferRate::TYPES)===['Monthly rate','Daily rate','Hourly rate']);
t('Daily 800 is 17,600 a month on 22 days and 20,800 on 26',OfferRate::convert('DAILY',800.0,22)['monthly']===17600.0&&OfferRate::convert('DAILY',800.0,26)['monthly']===20800.0);
t('Hourly 100 on 22 days is Daily 800 and Monthly 17,600',(function(){$r=OfferRate::convert('HOURLY',100.0,22);return $r['daily']===800.0&&$r['monthly']===17600.0;})());
t('Monthly 26,000 gives Daily 1,181.82 on 22 days and 1,000 on 26',OfferRate::convert('MONTHLY',26000.0,22)['daily']===1181.82&&OfferRate::convert('MONTHLY',26000.0)['daily']===1000.0);
t('the default is 26 days',OfferRate::DEFAULT_DAYS===26&&OfferRate::convert('DAILY',800.0)['monthly']===20800.0);
t('21, 0 and 30 days are refused',throws(fn()=>OfferRate::convert('DAILY',800.0,21))&&throws(fn()=>OfferRate::convert('DAILY',800.0,0))&&throws(fn()=>OfferRate::convert('DAILY',800.0,30)));
exit($bad?1:0);
