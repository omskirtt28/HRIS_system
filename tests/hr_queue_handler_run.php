<?php
declare(strict_types=1);
// Child process for hr_queue_bulk_test.php: runs the REAL ticket_handle() for one request (it redirects and exits).
// Usage: php hr_queue_handler_run.php <user_id> <GET|POST> <page> <json $_GET> <json $_POST> [no-csrf]
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketModule.php';
function need_db():void{db();}
[,$userId,$method,$page,$get,$post]=$argv;
$u=row('SELECT u.id,u.role_id,u.full_name name,u.email,r.code role_code,r.name role_name,r.portal role_portal FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[(int)$userId]);
$u['id']=(int)$u['id'];$u['role_id']=(int)$u['role_id'];$u['client_id']=null;
act($u);
$_SERVER['REQUEST_METHOD']=$method;$_GET=array_merge(json_decode($get,true)?:[],['page'=>$page]);$_POST=json_decode($post,true)?:[];
$_SESSION['csrf']='test-csrf-token';if(!in_array('no-csrf',$argv,true))$_POST['csrf']='test-csrf-token';
register_shutdown_function(static function(){
    $headers=headers_list();// read before any output flushes them
    echo PHP_EOL.'HTTP='.(http_response_code()?:200).PHP_EOL;
    foreach($headers as $h)if(stripos($h,'Location:')===0)echo 'LOCATION='.trim(substr($h,9)).PHP_EOL;
    echo 'RESULT='.json_encode($_SESSION['hq_result']??null).PHP_EOL;
});
ticket_handle($page);
echo 'NOT_A_TICKET_PAGE'.PHP_EOL;
