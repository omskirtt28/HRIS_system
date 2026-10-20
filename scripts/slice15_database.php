<?php
declare(strict_types=1);

// CLI-only, explicit database names. Backups and manifests never leave ignored private storage.
if(PHP_SAPI!=='cli')exit(1);
chdir(dirname(__DIR__));
require_once 'app/Database.php';require_once 'app/functions.php';require_once 'app/FoundationRepository.php';
require_once 'app/EmployeeApproverAssignmentService.php';
$config=require 'config/config.php';
if(is_file('config/config.local.php'))$config=array_replace_recursive($config,require 'config/config.local.php');
$GLOBALS['config']=$config;date_default_timezone_set('Asia/Manila');

function slice15_connect(string $name): PDO {
    global $config;$c=$config['db'];$c['database']=$name;$p=Database::connect($c);$p->exec("SET time_zone='+08:00'");return $p;
}
function slice15_snapshot(PDO $p,array $only=[]): array {
    $out=[];$tables=$only?:$p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables);
    foreach($tables as $table){
        if(!preg_match('/^[a-zA-Z0-9_]+$/',$table))throw new RuntimeException('Unexpected table identifier.');
        $ddl=$p->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $keys=$p->query('SHOW KEYS FROM `'.$table.'` WHERE Key_name="PRIMARY"')->fetchAll();
        usort($keys,fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        $order=$keys?' ORDER BY '.implode(',',array_map(fn($r)=>'`'.$r['Column_name'].'`',$keys)):'';
        $hash=hash_init('sha256');$n=0;
        foreach($p->query('SELECT * FROM `'.$table.'`'.$order) as $row){hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n");$n++;}
        $out[$table]=['rows'=>$n,'schema'=>hash('sha256',$ddl),'data'=>hash_final($hash)];
    }return $out;
}
function slice15_cli(string $program,string $db,?string $input=null,array $extra=[]): void {
    global $config;$c=$config['db'];
    $args=array_merge(['C:/xampp/mysql/bin/'.$program.'.exe','--host='.$c['host'],'--port='.(string)$c['port'],'--user='.$c['username'],'--default-character-set=utf8mb4'],$extra,[$db]);
    $log='storage/slice15-private/command.log';
    $descriptors=[0=>$input?['file',$input,'r']:['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']];
    $env=getenv();$env['MYSQL_PWD']=$c['password'];
    $process=proc_open($args,$descriptors,$pipes,null,$env);
    if(!is_resource($process))throw new RuntimeException('Unable to launch database tool.');
    if(!$input)fclose($pipes[0]);
    if(proc_close($process)!==0)throw new RuntimeException('Database tool failed; inspect the private command log.');
}
function slice15_migrate(string $db,string $direction): void {
    $files=['database/migrations/20261020_001_ticket_core','database/migrations/20261020_002_ticket_approvers'];
    if($direction==='down')$files=array_reverse($files);
    foreach($files as $base){$file=$base.($direction==='down'?'.down':'').'.sql';slice15_cli('mysql',$db,$file);echo 'PASS '.$direction.' '.basename($file).PHP_EOL;}
    if($direction==='up'){
        $GLOBALS['pdo']=slice15_connect($db);
        echo 'BACKFILL '.json_encode(EmployeeApproverAssignmentService::backfill()).PHP_EOL;
    }
}
function slice15_unchanged(PDO $p,array $baseline): void {
    // New permission grants and append-only migration audit entries are expected.
    $tables=array_values(array_diff(array_keys($baseline),['permissions','role_permissions','audit_logs']));
    $current=slice15_snapshot($p,$tables);
    foreach($tables as $table)if($current[$table]!==$baseline[$table])throw new RuntimeException('Unexpected legacy table change: '.$table);
    echo 'PASS legacy schema/data hashes unchanged ('.count($tables).' tables; permission/audit additions excluded)'.PHP_EOL;
}

// The step-2 runner reuses only the connection, verified dump, and fingerprint helpers.
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return;
try{
    $mode=$argv[1]??'';$live=$config['db']['database'];$folder='storage/slice15-private';
    if(!is_dir($folder))throw new RuntimeException('Private backup folder is missing.');
    $manifestPath=$folder.'/manifest.json';
    if($mode==='prepare'){
        if(is_file($manifestPath))throw new RuntimeException('A manifest already exists; refusing to overwrite backup evidence.');
        $p=slice15_connect($live);
        if($p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='tickets'")->fetchColumn())throw new RuntimeException('Ticket tables already exist; review before preparing.');
        $before=slice15_snapshot($p);$stamp=date('Ymd_His');$dump=$folder.'/before_step1_'.$stamp.'.sql';
        slice15_cli('mysqldump',$live,null,['--single-transaction','--routines','--triggers','--hex-blob','--skip-comments','--result-file='.realpath($folder).'/'.basename($dump)]);
        if(!is_file($dump)||filesize($dump)<1024)throw new RuntimeException('Backup file is empty or incomplete.');
        $scratch='hris_slice15_test_'.$stamp;
        $p->exec('CREATE DATABASE `'.$scratch.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        slice15_cli('mysql',$scratch,$dump);$restored=slice15_snapshot(slice15_connect($scratch));
        if($before!==$restored)throw new RuntimeException('Restored backup schema/data hashes do not match.');
        $manifest=['live_database'=>$live,'test_database'=>$scratch,'backup'=>$dump,'sha256'=>hash_file('sha256',$dump),'baseline'=>$before,'created_at'=>date(DATE_ATOM)];
        file_put_contents($manifestPath,json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        echo 'PASS mysqldump restored and verified: '.count($before).' tables, schema + row hashes'.PHP_EOL;
        echo 'TEST_DATABASE='.$scratch.PHP_EOL.'BACKUP='.$dump.PHP_EOL;
    }else{
        $m=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
        if(!hash_equals($m['sha256'],hash_file('sha256',$m['backup'])))throw new RuntimeException('Backup hash mismatch.');
        $scratch=$m['test_database'];if(!preg_match('/^hris_slice15_test_\d{8}_\d{6}$/',$scratch)||$scratch===$live)throw new RuntimeException('Unsafe test database.');
        if($mode==='cycle'){
            $scratch='hris_slice15_test_'.date('Ymd_His');$p=slice15_connect($live);
            $p->exec('CREATE DATABASE `'.$scratch.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            slice15_cli('mysql',$scratch,$m['backup']);
            if(slice15_snapshot(slice15_connect($scratch))!==$m['baseline'])throw new RuntimeException('Cycle restore verification failed.');
            $checkDb=slice15_connect($scratch);$definitions=[];
            foreach(array_keys($m['baseline']) as $table){$ddl=$checkDb->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$definitions[$table]=preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl);}
            slice15_migrate($scratch,'up');slice15_migrate($scratch,'up');slice15_migrate($scratch,'down');
            $restored=slice15_snapshot(slice15_connect($scratch));
            if(array_keys($restored)!==array_keys($m['baseline']))throw new RuntimeException('Table inventory differs after reversal.');
            foreach($restored as $table=>$state){
                $ddl=$checkDb->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
                if($state['rows']!==$m['baseline'][$table]['rows'] || $state['data']!==$m['baseline'][$table]['data'] || preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl)!==$definitions[$table])throw new RuntimeException('Schema/rows differ after reversal: '.$table);
            }
            echo 'PASS definitions + row hashes restored after up/rerun/down ('.count($restored).' legacy tables; identity counters may advance)'.PHP_EOL;
            slice15_migrate($scratch,'up');slice15_unchanged(slice15_connect($scratch),$m['baseline']);
            $m['test_database']=$scratch;unset($m['tested_hashes'],$m['tested_at']);file_put_contents($manifestPath,json_encode($m,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            echo 'TEST_DATABASE='.$scratch.PHP_EOL;
        }
        elseif($mode==='test-up'){slice15_migrate($scratch,'up');slice15_unchanged(slice15_connect($scratch),$m['baseline']);}
        elseif($mode==='test-down'){
            slice15_migrate($scratch,'down');$p=slice15_connect($scratch);slice15_unchanged($p,$m['baseline']);
            foreach(['permissions','role_permissions'] as $table){if(slice15_snapshot($p,[$table])[$table]['data']!==$m['baseline'][$table]['data'])throw new RuntimeException('Permission rollback differs: '.$table);}
            if((int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND (table_name LIKE 'ticket%' OR table_name='employee_approver_assignments')")->fetchColumn())throw new RuntimeException('Slice tables remain after reversal.');
            echo 'PASS reversal removes only Slice-15 tables/grants; audit history retained'.PHP_EOL;
        }elseif($mode==='certify'){
            // Invoke only after the separate test commands pass; binds approval to exact artifacts.
            $files=array_merge(glob('database/migrations/20261020_00[12]*.sql'),glob('app/Ticket*.php'),['app/EmployeeApproverAssignmentService.php','tests/ticket_core_test.php','tests/ticket_assignments_test.php','tests/ticket_legacy_regression_test.php']);
            $m['tested_hashes']=[];foreach($files as $f)$m['tested_hashes'][$f]=hash_file('sha256',$f);
            $m['tested_at']=date(DATE_ATOM);file_put_contents($manifestPath,json_encode($m,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'PASS tested artifact hashes recorded'.PHP_EOL;
        }elseif($mode==='apply'){
            if(empty($m['tested_hashes']))throw new RuntimeException('Test certification is missing.');
            foreach($m['tested_hashes'] as $f=>$hash)if(!hash_equals($hash,hash_file('sha256',$f)))throw new RuntimeException('Artifact changed after tests: '.$f);
            $p=slice15_connect($live);
            if((int)$p->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='tickets'")->fetchColumn())throw new RuntimeException('Already applied; refusing automatic re-application.');
            // Fresh backup immediately before live mutations, independent of the test-preparation backup.
            $applyStamp=date('Ymd_His');$fresh=$folder.'/before_apply_'.$applyStamp.'.sql';
            if(is_file($fresh))throw new RuntimeException('Fresh backup filename already exists.');
            $applyBaseline=slice15_snapshot($p);
            slice15_cli('mysqldump',$live,null,['--single-transaction','--routines','--triggers','--hex-blob','--skip-comments','--result-file='.realpath($folder).'/'.basename($fresh)]);
            if(filesize($fresh)<1024)throw new RuntimeException('Fresh backup verification failed.');
            $verifyDb='hris_slice15_restore_'.$applyStamp;
            $p->exec('CREATE DATABASE `'.$verifyDb.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            slice15_cli('mysql',$verifyDb,$fresh);
            if(slice15_snapshot(slice15_connect($verifyDb))!==$applyBaseline || slice15_snapshot($p)!==$applyBaseline)throw new RuntimeException('Live data changed during backup or restored backup differs. No migration applied; retry with a new backup.');
            echo 'PASS fresh pre-apply mysqldump restored; all schema/data hashes match'.PHP_EOL;
            $m['apply_baseline']=$applyBaseline;$m['apply_backup']=$fresh;$m['apply_backup_sha256']=hash_file('sha256',$fresh);$m['restore_verified_database']=$verifyDb;
            file_put_contents($manifestPath,json_encode($m,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            slice15_migrate($live,'up');slice15_unchanged($p,$m['apply_baseline']);
            $m['applied_at']=date(DATE_ATOM);file_put_contents($manifestPath,json_encode($m,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo 'PASS local migrations 001 + 002 applied'.PHP_EOL;
        }else throw new RuntimeException('Use prepare, test-up, test-down, certify or apply.');
    }
}catch(Throwable $error){fwrite(STDERR,'FAIL '.$error->getMessage().PHP_EOL);exit(1);}
