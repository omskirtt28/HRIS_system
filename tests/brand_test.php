<?php
declare(strict_types=1);
// White-label brand helpers (2026-11-17 rebrand). Pure unit test, no database.
require __DIR__.'/../app/functions.php';
$bad=0;
function t(string $label,bool $ok): void { global $bad; echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL; if(!$ok)$bad++; }

$GLOBALS['config']=[];
t('defaults: product HRIS, neutral demo company',brand('product')==='HRIS'&&brand('company')==='Demo Company');
t('default lockup is the built-in mark with the product name',str_contains(brand_lockup(),'brand-glyph')&&str_contains(brand_lockup(),'<b>HRIS</b>'));
t('mark-only lockup has no wordmark',!str_contains(brand_lockup(false),'<b>'));
$GLOBALS['config']=['brand'=>['company'=>'Acme <b>Co</b>','logo'=>'x.png','color'=>'#112233','color_strong'=>'#000000']];
t('config overrides and escapes the company in the image alt',str_contains(brand_lockup(),'alt="Acme &lt;b&gt;Co&lt;/b&gt;"'));
t('valid hex colours are emitted',brand_style()==='<style>:root{--hris-brand:#112233;--hris-brand-strong:#000000}</style>');
$GLOBALS['config']['brand']['color']='red;}</style><script>';
t('invalid colour emits nothing (no CSS/HTML injection)',brand_style()==='');
exit($bad?1:0);
