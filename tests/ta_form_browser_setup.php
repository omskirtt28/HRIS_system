<?php
declare(strict_types=1);
/** Scratch-DB only: gives the seeded employee a test password and writes the front-controller copy used by ta_form_browser_router.php. */
$dbName=getenv('HRIS_TEST_DB')?:'';
if(!preg_match('/^hris_slice15_test_\d{8}_\d{6}$/',$dbName)){fwrite(STDERR,"Set HRIS_TEST_DB to the isolated Slice-15 database.\n");exit(2);}
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require dirname(__DIR__).'/app/bootstrap.php';
$GLOBALS['pdo']=new PDO('mysql:host=127.0.0.1;dbname='.$dbName.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$e=db()->query('SELECT e.id,e.user_id,u.email FROM employees e JOIN users u ON u.id=e.user_id JOIN payroll_biometric_mappings m ON m.employee_id=e.id WHERE e.employee_no=\'17845\'')->fetch();
if(!$e)throw new RuntimeException('Seeded employee missing in the scratch copy.');
db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash('Slice15-Test-Only!9',PASSWORD_DEFAULT),$e['user_id']]);
$root=dirname(__DIR__);$source=file_get_contents($root.'/index.php');
$needle="require __DIR__ . '/app/bootstrap.php';";
if(substr_count($source,$needle)!==1)throw new RuntimeException('Unexpected front-controller bootstrap.');
$inject=$needle."\n\$testConfig=cfg('db');\$testConfig['database']=getenv('HRIS_TEST_DB');\$GLOBALS['pdo']=Database::connect(\$testConfig);\$GLOBALS['config']['db']=\$testConfig;";
$source=str_replace('__DIR__',var_export($root,true),str_replace($needle,$inject,$source));
file_put_contents($root.'/storage/slice15-private/test-index.php',$source);
file_put_contents($root.'/storage/slice15-private/ta-form-fixtures.json',json_encode(['email'=>$e['email']],JSON_THROW_ON_ERROR));
echo "PASS ta form browser fixtures prepared\n";
