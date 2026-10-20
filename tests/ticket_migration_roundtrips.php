<?php
require dirname(__DIR__).'/scripts/slice15_database.php';
function normalized_definitions(PDO $p):array {
 $out=[];foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
  if(!preg_match('/^[a-zA-Z0-9_]+$/',$table))throw new RuntimeException('Invalid table.');
  $ddl=$p->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl);
  $lines=array_map(fn($line)=>rtrim(trim($line),','),explode("\n",$ddl));sort($lines);$out[$table]=implode("\n",$lines);
 }ksort($out);return $out;
}
for($n=4;$n<=9;$n++){
 $m=json_decode(file_get_contents('storage/slice15-private/stageb-'.$n.'.json'),true,512,JSON_THROW_ON_ERROR);
 if(!hash_equals($m['sha256'],hash_file('sha256',$m['backup'])))throw new RuntimeException('Backup hash mismatch.');
 $scratch='hris_slice15_cycle_'.$n.'_'.date('Ymd_His');$p=slice15_connect($config['db']['database']);$p->exec('CREATE DATABASE `'.$scratch.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');slice15_cli('mysql',$scratch,$m['backup']);$p=slice15_connect($scratch);
 if(slice15_snapshot($p)!==$m['baseline'])throw new RuntimeException('Restored baseline mismatch.');
 $defs=normalized_definitions($p);$up=array_values(array_filter(glob('database/migrations/20261020_'.sprintf('%03d',$n).'_*.sql'),fn($f)=>!str_ends_with($f,'.down.sql')))[0];
 slice15_cli('mysql',$scratch,$up);slice15_cli('mysql',$scratch,$up);slice15_cli('mysql',$scratch,str_replace('.sql','.down.sql',$up));
 if($defs!==normalized_definitions($p)){
  foreach($defs as $t=>$definition)if((normalized_definitions($p)[$t]??null)!==$definition)echo 'Definition differs: '.$t.PHP_EOL;
  throw new RuntimeException('Down schema differs for '.$n);
 }
 $after=slice15_snapshot($p);foreach($m['baseline'] as $table=>$before)if($before['rows']!==$after[$table]['rows']||$before['data']!==$after[$table]['data'])throw new RuntimeException('Down data differs: '.$table);
 echo 'PASS migration '.$n.' up/rerun/down restores normalized definitions and all original row hashes'.PHP_EOL;
}
