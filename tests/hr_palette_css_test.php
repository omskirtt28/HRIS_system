<?php
declare(strict_types=1);
// HR appearance palettes (SPEC-hr-appearance-palettes.md, Task 3): the palette stylesheet and its WCAG contrast. Pure test, no database.
$bad=0;
function t(string $label,bool $ok): void { global $bad; echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL; if(!$ok)$bad++; }
function lum(string $h): float { $h=ltrim($h,'#'); $c=array_map(fn($i)=>hexdec(substr($h,$i,2))/255,[0,2,4]); $c=array_map(fn($v)=>$v<=0.03928?$v/12.92:(($v+0.055)/1.055)**2.4,$c); return 0.2126*$c[0]+0.7152*$c[1]+0.0722*$c[2]; }
function ratio(string $a,string $b): float { $x=lum($a); $y=lum($b); if($x<$y)[$x,$y]=[$y,$x]; return ($x+0.05)/($y+0.05); }

$file=dirname(__DIR__).'/public/assets/palettes.css';
$css=is_file($file)?(string)file_get_contents($file):'';
t('palettes.css exists',$css!=='');
preg_match_all('/([^{}]+)\{([^{}]*)\}/',preg_replace('~/\*.*?\*/~s','',$css),$blocks,PREG_SET_ORDER);
$tokens=[]; $selectors=[];
foreach($blocks as $b){ $selectors[]=trim($b[1]); if(preg_match('/^:root\[data-palette="(\w+)"\]$/',trim($b[1]),$m)){ preg_match_all('/(--[\w-]+)\s*:\s*(#[0-9a-fA-F]{6})\b/',$b[2],$d,PREG_SET_ORDER); foreach($d as $x)$tokens[$m[1]][$x[1]]=$x[2]; } }
$palettes=['beige','earth','pastel','pink'];
t('exactly the four palettes define tokens',count($tokens)===4&&!array_diff($palettes,array_keys($tokens)));
t('every selector is scoped to a data-palette, so Light and Dark are untouched',$selectors!==[]&&count(array_filter(array_merge(...array_map(fn($s)=>array_map('trim',explode(',',$s)),$selectors)),fn($s)=>!str_starts_with($s,':root[data-palette')))===0);
$need=['--hris-bg','--hris-surface','--hris-surface-2','--hris-line','--hris-text','--hris-muted','--hris-brand-soft','--hris-sidebar','--hris-sidebar-soft','--pal-side-nav','--pal-side-label'];
foreach($palettes as $p){
    $k=$tokens[$p]??[];
    t("$p defines every required token",!array_diff($need,array_keys($k)));
    t("$p never changes the brand or status colours",!array_intersect(array_keys($k),['--hris-brand','--hris-brand-strong','--hris-success','--hris-info','--hris-danger','--hris-warning']));
    $min=99.0; $worst='';
    $pairs=[];
    foreach(['--hris-text','--hris-muted'] as $fg) foreach(['--hris-bg','--hris-surface','--hris-surface-2','--hris-brand-soft'] as $bg) $pairs[]=[$k[$fg]??'#000000',$k[$bg]??'#000000',"$fg on $bg"];
    foreach(['#137a50','#2560d6','#c2364a','#8f5a00'] as $status) foreach(['--hris-bg','--hris-surface'] as $bg) $pairs[]=[$status,$k[$bg]??'#000000',"status $status on $bg"];
    foreach(['#ffffff',$k['--pal-side-nav']??'#000000',$k['--pal-side-label']??'#000000'] as $fg) foreach(['--hris-sidebar','--hris-sidebar-soft'] as $bg) $pairs[]=[$fg,$k[$bg]??'#ffffff',"sidebar $fg on $bg"];
    foreach($pairs as [$fg,$bg,$name]) { $r=ratio($fg,$bg); if($r<$min){$min=$r;$worst=$name;} }
    t(sprintf('%s: every text pair is at least 4.5:1 (lowest %.2f, %s)',$p,$min,$worst),$min>=4.5);
}
foreach(['.nav-group .gl','.nav-item.active','.side .brand .sub','.side .prof .muted','.nav-item .soon'] as $sel)
    t("palettes recolour the sidebar rule $sel",str_contains($css,$sel));
t('legacy app.css tokens follow the palette (--bg, --panel, --ink)',str_contains($css,'--bg:var(--hris-bg)')&&str_contains($css,'--panel:var(--hris-surface)')&&str_contains($css,'--ink:var(--hris-text)'));
exit($bad?1:0);
