<?php
declare(strict_types=1);
require __DIR__.'/ticket_test_support.php';
$f=fixtures();
$role=row('SELECT id,name FROM roles WHERE code="EMPLOYEE"');
foreach(['employee','outsider'] as $key){db()->prepare('UPDATE users SET role_id=? WHERE id=?')->execute([$role['id'],$f[$key]['id']]);$f[$key]['role_id']=(int)$role['id'];$f[$key]['role_code']='EMPLOYEE';$f[$key]['role_name']=$role['name'];}
db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code="dashboard.hr.view"')->execute([$f['hr']['role_id']]);
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$f['manager_employee'],$f['requester']]);
db()->prepare('UPDATE payroll_site_settings SET approver_user_id=? WHERE branch_id=?')->execute([$f['adl']['id'],$f['branch']]);
foreach(['open'=>['2021-01-04','2021-01-18','OPEN'],'target'=>['2021-01-19','2021-02-03','OPEN'],'late'=>['2020-12-19','2021-01-03','CLOSED']] as $key=>[$start,$end,$state]){
    $existing=row('SELECT id FROM payroll_cutoffs WHERE period_start=? AND period_end=?',[$start,$end]);
    $f[$key]=$existing?(int)$existing['id']:insert('INSERT INTO payroll_cutoffs(code,period_start,period_end,status) VALUES(?,?,?,?)',['BROWSER-'.$start,$start,$end,$state]);
    db()->prepare('UPDATE payroll_cutoffs SET status=? WHERE id=?')->execute([$state,$f[$key]]);
    db()->prepare('INSERT INTO payroll_cutoff_runs(cutoff_id,state) VALUES(?,?) ON DUPLICATE KEY UPDATE state=VALUES(state),ticket_deadline=NULL')->execute([$f[$key],$key==='late'?'FINALIZED':'OPEN']);
}
$f['type']=(int)row('SELECT id FROM ticket_types WHERE code="PAYROLL_CONCERN"')['id'];
file_put_contents(dirname(__DIR__).'/storage/slice15-private/concern-browser-fixtures.json',json_encode($f,JSON_THROW_ON_ERROR));
// Copy the actual front controller for full Home/legacy/new-route testing, substituting only its DB connection.
// All app modules, views, assets, and policies remain the actual source files.
$root=dirname(__DIR__);$source=file_get_contents($root.'/index.php');
$needle="require __DIR__ . '/app/bootstrap.php';";
if(substr_count($source,$needle)!==1)throw new RuntimeException('Unexpected front-controller bootstrap.');
$inject=$needle."\n\$testConfig=cfg('db');\$testConfig['database']=getenv('HRIS_TEST_DB');\$GLOBALS['pdo']=Database::connect(\$testConfig);\$GLOBALS['config']['db']=\$testConfig;\$GLOBALS['config']['mail']=[];\n";
$source=str_replace($needle,$inject,$source);$source=str_replace('__DIR__',var_export($root,true),$source);
file_put_contents($root.'/storage/slice15-private/test-index.php',$source);
echo "PASS synthetic fixtures and isolated full-front-controller test copy prepared\n";
