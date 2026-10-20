<?php
declare(strict_types=1);
require __DIR__.'/slice15_database.php';
// Private evidence only. Never print credentials or employee records.
$number=(int)($argv[2]??4);$mode=$argv[1]??'inspect';
$live=$config['db']['database'];$folder='storage/slice15-private';
$manifest=$folder.'/stageb-'.$number.'.json';
function stage_backup(string $source,string $label):array {
    global $folder;
    $p=slice15_connect($source);$before=slice15_snapshot($p);$stamp=date('Ymd_His');
    $file=$folder.'/'.$label.'_'.$stamp.'.sql';if(file_exists($file))throw new RuntimeException('Backup already exists.');
    slice15_cli('mysqldump',$source,null,['--single-transaction','--routines','--triggers','--hex-blob','--skip-comments','--result-file='.realpath($folder).'/'.basename($file)]);
    if(!is_file($file)||filesize($file)<1024)throw new RuntimeException('Incomplete backup.');
    $test='hris_slice15_test_'.$stamp;
    $p->exec('CREATE DATABASE `'.$test.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    slice15_cli('mysql',$test,$file);
    if(slice15_snapshot(slice15_connect($test))!==$before || slice15_snapshot($p)!==$before)throw new RuntimeException('Backup restore mismatch or source changed.');
    echo 'PASS verified mysqldump restore: '.count($before).' schema/data fingerprints'.PHP_EOL;
    return ['backup'=>$file,'sha256'=>hash_file('sha256',$file),'test_database'=>$test,'baseline'=>$before];
}
function stage_migrate(string $name,int $number,bool $down=false):void {
    $files=glob('database/migrations/20261020_'.sprintf('%03d',$number).'_*.'.($down?'down.sql':'sql'));
    $files=array_values(array_filter($files,fn($f)=>$down||!str_ends_with($f,'.down.sql')));
    if(count($files)!==1)throw new RuntimeException('Expected one migration.');
    slice15_cli('mysql',$name,$files[0]);echo 'PASS '.basename($files[0]).PHP_EOL;
}
try {
    if($mode==='inspect'){
        $p=slice15_connect($live);
        foreach(['tickets','ticket_types','employee_placements'] as $table){$q=$p->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);echo $table.' exists='.(int)$q->fetchColumn().PHP_EOL;}
        echo 'ticket types: '.implode(',',$p->query('SELECT code FROM ticket_types')->fetchAll(PDO::FETCH_COLUMN)).PHP_EOL;exit;
    }
    if($mode==='prepare'){
        if(file_exists($manifest))throw new RuntimeException('Keep existing manifest.');
        $m=stage_backup($live,'before_stageb_'.$number);file_put_contents($manifest,json_encode($m,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    }else{
        $m=json_decode(file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
        if(!hash_equals($m['sha256'],hash_file('sha256',$m['backup'])))throw new RuntimeException('Backup hash mismatch.');
        if(!preg_match('/^hris_slice15_test_\d{8}_\d{6}$/',$m['test_database'])||$m['test_database']===$live)throw new RuntimeException('Unsafe scratch database.');
        if($mode==='cycle'){
            $p=slice15_connect($m['test_database']);
            stage_migrate($m['test_database'],$number);stage_migrate($m['test_database'],$number);stage_migrate($m['test_database'],$number,true);
            $after=slice15_snapshot($p);
            foreach($m['baseline'] as $table=>$before){
                if(!isset($after[$table])||$before['data']!==$after[$table]['data'])throw new RuntimeException('Down changed original rows: '.$table);
            }
            if(array_keys($after)!==array_keys($m['baseline']))throw new RuntimeException('Down table inventory differs.');
            echo 'PASS up/rerun/down restores original rows and inventory'.PHP_EOL;
            stage_migrate($m['test_database'],$number);
            $m['cycled_at']=date(DATE_ATOM);
        }elseif($mode==='guard'){
            $p=slice15_connect($m['test_database']);$before=slice15_snapshot($p);$blocked=false;
            try{stage_migrate($m['test_database'],$number,true);}catch(RuntimeException){$blocked=true;}
            $p->exec('DROP PROCEDURE IF EXISTS stageb_'.sprintf('%03d',$number).'_down');
            if(!$blocked||slice15_snapshot($p)!==$before)throw new RuntimeException('Used-data rollback guard failed.');
            echo 'PASS used-data down refused; all schema/data fingerprints unchanged'.PHP_EOL;
        }elseif($mode==='apply'){
            if(empty($m['cycled_at']))throw new RuntimeException('Cycle required.');
            $fresh=stage_backup($live,'before_stageb_apply_'.$number);
            $m['apply_backup']=$fresh['backup'];$m['apply_backup_sha256']=$fresh['sha256'];
            $m['restore_verified_database']=$fresh['test_database'];
            file_put_contents($manifest,json_encode($m,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            stage_migrate($live,$number);
            $p=slice15_connect($live);
            // No existing business records may change. Migration seed/config additions are explicit.
            $excluded=['ticket_types','permissions','role_permissions','ticket_permission_seeds','ticket_accounting_memberships','ticket_accounting_seed_results'];
            foreach($fresh['baseline'] as $table=>$before){if(in_array($table,$excluded,true))continue;
                $after=slice15_snapshot($p,[$table])[$table];
                if($after['rows']!==$before['rows']||$after['data']!==$before['data'])throw new RuntimeException('Existing records changed: '.$table);
            }
            $m['applied_at']=date(DATE_ATOM);echo 'PASS existing business rows unchanged'.PHP_EOL;
        }else throw new RuntimeException('Unknown mode.');
        file_put_contents($manifest,json_encode($m,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    }
    echo 'TEST_DATABASE='.$m['test_database'].PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);exit(1);}
