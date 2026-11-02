<?php
declare(strict_types=1);
/**
 * Remove the five seeded demo clients (rec-manpower-intake T5, approved by John as R1).
 *   php scripts/remove_demo_clients.php <scratch-db|live> dry-run|apply
 * - A demo client is matched by code AND name AND contact email, so a renamed or real client is never touched.
 * - Refuses while recruitment data (requests, jobs, applications, endorsements, client reviews) points at a demo client.
 * - Branches and users linked to a demo client are unlinked. An ACTIVE client-portal user is also set INACTIVE: with no client
 *   the portal would not be scoped to anyone. Nothing is deleted except the demo client rows themselves.
 * - "live" needs a restore-verified dump first: php scripts/ticket_stageb_database.php prepare 23
 * Idempotent: a second run finds nothing to do. Rollback = restore that dump.
 */
require __DIR__.'/slice15_database.php';
const DEMO=[['ABC','ABC Retail Corporation','hr@abcretail.com'],['METROFOOD','Metro Food Services','hr@metrofood.com'],['PRIMELOG','Prime Logistics Solutions','ops@primelogistics.com'],['SILVERPEAK','Silver Peak Holdings','hr@silverpeak.com'],['APEX','Apex Manufacturing Corporation','hr@apexmfg.com']];
$target=$argv[1]??'';$mode=$argv[2]??'dry-run';
try{
    if(!in_array($mode,['dry-run','apply'],true))throw new RuntimeException('Mode must be dry-run or apply.');
    if($target==='live'){
        $target=$config['db']['database'];$manifest='storage/slice15-private/stageb-23.json';
        if($mode==='apply'){
            $m=is_file($manifest)?json_decode(file_get_contents($manifest),true):null;
            if(!$m||empty($m['sha256'])||!is_file($m['backup'])||!hash_equals($m['sha256'],hash_file('sha256',$m['backup']))||filemtime($m["backup"])<time()-6*3600)throw new RuntimeException('Run "php scripts/ticket_stageb_database.php prepare 23" first: a verified dump from the last 6 hours is required.');
        }
    }elseif(!preg_match('/^hris_slice15_test_\d{8}_\d{6}$/',$target))throw new RuntimeException('Target must be a scratch database or "live".');
    $p=slice15_connect($target);
    $ids=[];foreach(DEMO as [$code,$name,$email]){$q=$p->prepare('SELECT id FROM clients WHERE code=? AND name=? AND contact_email=?');$q->execute([$code,$name,$email]);if($id=$q->fetchColumn())$ids[]=(int)$id;}
    if(!$ids){echo "Nothing to do: no demo clients found in $target.".PHP_EOL;exit(0);}
    $in=implode(',',$ids);
    foreach(['manpower_requests','job_openings','applications','endorsements','client_reviews'] as $t){
        $n=(int)$p->query("SELECT COUNT(*) FROM `$t` WHERE client_id IN ($in)")->fetchColumn();if($n)throw new RuntimeException("$t still has $n row(s) for a demo client; refusing.");
    }
    $branches=(int)$p->query("SELECT COUNT(*) FROM branches WHERE client_id IN ($in)")->fetchColumn();
    $users=(int)$p->query("SELECT COUNT(*) FROM users WHERE client_id IN ($in)")->fetchColumn();
    $active=(int)$p->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE u.client_id IN ($in) AND u.status='ACTIVE' AND r.portal='client'")->fetchColumn();
    echo "$target: ".count($ids)." demo clients; $branches branch link(s) and $users user link(s) to clear; $active active client-portal user(s) to deactivate.".PHP_EOL;
    if($mode==='dry-run'){echo 'Dry run only; nothing changed.'.PHP_EOL;exit(0);}
    $rowsOf=fn(string $t)=>$p->query("SELECT * FROM `$t` ORDER BY id")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    $before=slice15_snapshot($p);$usersBefore=$rowsOf('users');$branchesBefore=$rowsOf('branches');
    $p->beginTransaction();
    $p->exec("UPDATE users u JOIN roles r ON r.id=u.role_id SET u.status='INACTIVE' WHERE u.client_id IN ($in) AND u.status='ACTIVE' AND r.portal='client'");
    $p->exec("UPDATE users SET client_id=NULL WHERE client_id IN ($in)");
    $p->exec("UPDATE branches SET client_id=NULL WHERE client_id IN ($in)");
    $p->exec("DELETE FROM clients WHERE id IN ($in)");
    // Standing workflow: no other business row may change. Checked before commit, so a surprise rolls everything back.
    $after=slice15_snapshot($p);
    foreach($before as $table=>$b){if(in_array($table,['clients','branches','users'],true))continue;if(!isset($after[$table])||$after[$table]['data']!==$b['data'])throw new RuntimeException("Unexpected change in $table; rolled back.");}
    foreach([['users',$usersBefore,['client_id','status','updated_at']],['branches',$branchesBefore,['client_id','updated_at']]] as [$table,$old,$allowed]){
        $new=$rowsOf($table);if(array_keys($new)!==array_keys($old))throw new RuntimeException("Rows added or removed in $table; rolled back.");
        foreach($old as $id=>$row){$changed=array_keys(array_diff_assoc($row,$new[$id]));if($changed&&(!in_array((int)$row['client_id'],$ids,true)||array_diff($changed,$allowed)))throw new RuntimeException("Unexpected change to $table #$id; rolled back.");}
    }
    $p->commit();
    echo 'Applied: '.count($ids).' demo clients removed.'.PHP_EOL;
}catch(Throwable $e){if(isset($p)&&$p->inTransaction())$p->rollBack();fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);exit(1);}
