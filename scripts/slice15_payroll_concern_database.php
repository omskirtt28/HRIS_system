<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/slice15_database.php';
$folder='storage/slice15-private';$manifestFile=$folder.'/step2-manifest.json';$live=$config['db']['database'];$mode=$argv[1]??'';
function concern_migration(string $db,string $direction):void{
    slice15_cli('mysql',$db,'database/migrations/20261020_003_payroll_concern'.($direction==='down'?'.down':'').'.sql');
    echo 'PASS migration 003 '.$direction.PHP_EOL;
}
function concern_backup(string $live,string $label):array{
    global $folder;$p=slice15_connect($live);$before=slice15_snapshot($p);$stamp=date('Ymd_His');
    $file=$folder.'/'.$label.'_'.$stamp.'.sql';if(is_file($file))throw new RuntimeException('Backup filename already exists.');
    slice15_cli('mysqldump',$live,null,['--single-transaction','--routines','--triggers','--hex-blob','--skip-comments','--result-file='.realpath($folder).'/'.basename($file)]);
    if(filesize($file)<1024)throw new RuntimeException('Backup is incomplete.');
    $test='hris_slice15_test_'.$stamp;$p->exec('CREATE DATABASE `'.$test.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');slice15_cli('mysql',$test,$file);
    if(slice15_snapshot(slice15_connect($test))!==$before || slice15_snapshot($p)!==$before)throw new RuntimeException('Restored backup differs or source changed during verification.');
    echo 'PASS verified mysqldump restore: '.count($before).' table definitions and row hashes'.PHP_EOL;
    return ['backup'=>$file,'sha256'=>hash_file('sha256',$file),'test_database'=>$test,'baseline'=>$before,'created_at'=>date(DATE_ATOM)];
}
try{
    if($mode==='prepare'){
        if(is_file($manifestFile))throw new RuntimeException('Step-2 manifest exists; keep backup evidence.');
        $p=slice15_connect($live);
        if((int)$p->query('SELECT COUNT(*) FROM ticket_types WHERE code="PAYROLL_CONCERN"')->fetchColumn())throw new RuntimeException('Payroll Concern is already configured. Review before preparing.');
        $m=concern_backup($live,'before_step2');file_put_contents($manifestFile,json_encode($m,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'TEST_DATABASE='.$m['test_database'].PHP_EOL;
    }else{
        $m=json_decode(file_get_contents($manifestFile),true,512,JSON_THROW_ON_ERROR);
        if(!hash_equals($m['sha256'],hash_file('sha256',$m['backup'])))throw new RuntimeException('Backup hash changed.');
        $test=$m['test_database'];if(!preg_match('/^hris_slice15_test_\d{8}_\d{6}$/',$test) || $test===$live)throw new RuntimeException('Unsafe test database.');
        if($mode==='cycle'){
            $p=slice15_connect($test);$defs=[];foreach(array_keys($m['baseline']) as $table)$defs[$table]=preg_replace('/ AUTO_INCREMENT=\d+/','',$p->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1]);
            concern_migration($test,'up');concern_migration($test,'up');concern_migration($test,'down');
            $after=slice15_snapshot($p);if(array_keys($after)!==array_keys($m['baseline']))throw new RuntimeException('Table inventory differs after reversal.');
            foreach($after as $table=>$s){$ddl=preg_replace('/ AUTO_INCREMENT=\d+/','',$p->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1]);if($s['data']!==$m['baseline'][$table]['data'] || $s['rows']!==$m['baseline'][$table]['rows'] || $ddl!==$defs[$table])throw new RuntimeException('Reversal differs: '.$table);}
            echo 'PASS migration 003 up/rerun/down restores all definitions and row hashes (identity counters may advance)'.PHP_EOL;concern_migration($test,'up');
        }elseif($mode==='guard'){
            $p=slice15_connect($test);$before=slice15_snapshot($p);$log=$folder.'/command.log';$offset=is_file($log)?filesize($log):0;$blocked=false;
            try{concern_migration($test,'down');}catch(RuntimeException){$error=substr(file_get_contents($log),$offset);$blocked=str_contains($error,'Payroll Concern is in use');}
            $p->exec('DROP PROCEDURE IF EXISTS slice15_003_check_rollback');
            if(!$blocked || slice15_snapshot($p)!==$before)throw new RuntimeException('Used-data reversal guard did not preserve the database.');
            echo 'PASS used-data reversal refused; all table definitions and row hashes unchanged'.PHP_EOL;
        }elseif($mode==='certify'){
            $files=array_merge(glob('app/Ticket*.php'),glob('database/migrations/20261020_003*.sql'),['index.php','app/View.php','public/assets/tickets.css','scripts/run_notifications.php','scripts/run_ticket_notifications.php','tests/payroll_concern_test.php','tests/payroll_concern_browser.cjs']);
            $m['tested_hashes']=[];foreach($files as $file)$m['tested_hashes'][$file]=hash_file('sha256',$file);
            $m['tested_at']=date(DATE_ATOM);file_put_contents($manifestFile,json_encode($m,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'PASS step-2 artifact hashes recorded'.PHP_EOL;
        }elseif($mode==='apply'){
            if(empty($m['tested_hashes']))throw new RuntimeException('Missing step-2 test certification.');
            foreach($m['tested_hashes'] as $file=>$hash)if(!hash_equals($hash,hash_file('sha256',$file)))throw new RuntimeException('Artifact changed after tests: '.$file);
            $p=slice15_connect($live);if((int)$p->query('SELECT COUNT(*) FROM ticket_types WHERE code="PAYROLL_CONCERN"')->fetchColumn())throw new RuntimeException('Migration 003 already applied.');
            $fresh=concern_backup($live,'before_step2_apply');$m['apply_backup']=$fresh['backup'];$m['apply_backup_sha256']=$fresh['sha256'];$m['apply_baseline']=$fresh['baseline'];
            file_put_contents($manifestFile,json_encode($m,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));concern_migration($live,'up');
            $tables=array_values(array_diff(array_keys($fresh['baseline']),['ticket_types']));$after=slice15_snapshot($p,$tables);
            foreach($tables as $table)if($after[$table]!==$fresh['baseline'][$table])throw new RuntimeException('Unexpected existing table change: '.$table);
            echo 'PASS existing '.count($tables).' table schema/data hashes unchanged; only Payroll Concern type seeded'.PHP_EOL;
            $m['applied_at']=date(DATE_ATOM);file_put_contents($manifestFile,json_encode($m,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        }else throw new RuntimeException('Use prepare, cycle, certify or apply.');
    }
}catch(Throwable $error){fwrite(STDERR,'FAIL '.$error->getMessage().PHP_EOL);exit(1);}
