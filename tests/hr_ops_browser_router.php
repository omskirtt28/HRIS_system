<?php
declare(strict_types=1);
// Test server entrypoint only (php -S). Serves the REAL HR dashboard and ticket handlers against the isolated database.
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
$GLOBALS['pdo']=Database::connect($connection);$GLOBALS['config']['db']['database']=$name;$GLOBALS['config']['mail']=[];
require_once $root.'/app/View.php';
require_once $root.'/app/NotificationService.php';
require_once $root.'/app/TicketModule.php';
require_once $root.'/app/HrDashboardViews.php';
function need_db():void{db();}
$page=(string)($_GET['page']??'hr-dashboard');
if($page==='login'){
    if($_SERVER['REQUEST_METHOD']==='POST'){verify_csrf();if(Auth::login((string)($_POST['email']??''),(string)($_POST['password']??'')))redirect('hr-dashboard');http_response_code(401);}
    echo '<!doctype html><title>Isolated test login</title><form method="post">'.csrf_field().'<label>Email<input name="email" type="email"></label><label>Password<input name="password" type="password"></label><button>Sign in</button></form>';exit;
}
if(!Auth::check())redirect('login');
if($page==='hr-dashboard'){Auth::requirePortal('hr');Auth::requirePermission('dashboard.hr.view');hr_dashboard_page();exit;}
ticket_handle($page);
http_response_code(404);
