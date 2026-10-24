<?php
declare(strict_types=1);
// Test server entrypoint only. Never deploy: normal app routes do not load this file.
if(PHP_SAPI!=='cli-server' || !preg_match('/^hris_slice15_test_\d{8}_\d{6}$/',getenv('HRIS_TEST_DB')?:'')){http_response_code(404);exit;}
$root=dirname(__DIR__);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(is_string($path) && str_starts_with($path,'/public/assets/') && !str_contains($path,'..')){
    $file=$root.$path;
    if(is_file($file)){
        $mime=match(pathinfo($file,PATHINFO_EXTENSION)){'css'=>'text/css','js'=>'application/javascript','png'=>'image/png','svg'=>'image/svg+xml','ico'=>'image/x-icon',default=>'application/octet-stream'};
        header('Content-Type: '.$mime);readfile($file);exit;
    }
}
if(!in_array($path,['/','/index.php'],true)){http_response_code(404);exit;}
session_save_path($root.'/storage/slice15-private/sessions');
require_once $root.'/app/bootstrap.php';
$connection=cfg('db');$name=getenv('HRIS_TEST_DB');if($connection['database']===$name)exit(1);$connection['database']=$name;
$GLOBALS['pdo']=Database::connect($connection);$GLOBALS['config']['db']['database']=$name;
require_once $root.'/app/View.php';
require_once $root.'/app/NotificationService.php';
require_once $root.'/app/TicketRepository.php'; // nav_config() reads TicketRepository / TicketAccounting
require_once $root.'/app/TicketAssignmentModule.php';
function need_db():void{db();}
$fixtures=json_decode(file_get_contents($root.'/storage/slice15-private/browser-fixtures.json'),true,512,JSON_THROW_ON_ERROR);
$page=(string)($_GET['page']??'hr-ticket-approvers');
if($page==='login'){
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        if(Auth::login((string)($_POST['email']??''),(string)($_POST['password']??'')))redirect('hr-ticket-approvers');
        http_response_code(401);
    }
    echo '<!doctype html><title>Slice15 isolated test login</title><form method="post">'.csrf_field().'<label>Email<input name="email" type="email"></label><label>Password<input name="password" type="password"></label><button>Sign in</button></form>';exit;
}
if(!isset($_GET['business_unit_id']))$_GET['business_unit_id']=$fixtures['company'];
ticket_assignment_handle($page);
http_response_code(404);
