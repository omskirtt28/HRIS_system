<?php
declare(strict_types=1);
// Child process for ticket_employee_portal_gate_test.php: Auth::deny() exits, so each request runs on its own.
// Usage: php ticket_employee_portal_gate_run.php <user_id> gate|page <page>
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketModule.php';
function need_db():void{db();}
[,$userId,$mode,$page]=$argv;
$u=row('SELECT u.id,u.role_id,u.full_name name,u.email,r.code role_code,r.name role_name,r.portal role_portal FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[(int)$userId]);
$u['id']=(int)$u['id'];$u['role_id']=(int)$u['role_id'];$u['client_id']=null;
act($u);
$_SERVER['REQUEST_METHOD']='GET';$_GET['page']=$page;
register_shutdown_function(static function(){echo PHP_EOL.'HTTP='.(http_response_code()?:200).PHP_EOL;});
if($mode==='gate'){
    // Run the real gate that guards every employee-* page, read straight from index.php.
    $source=file_get_contents(dirname(__DIR__).'/index.php');
    if(!preg_match('/if \(str_starts_with\(\$page,\'employee-\'\)\) \{\s*(.*?)\s*\n\}/s',$source,$m)){echo 'GATE_NOT_FOUND'.PHP_EOL;exit(3);}
    eval($m[1]);
    echo 'GATE_PASSED'.PHP_EOL;exit;
}
ticket_handle($page);
echo 'NOT_A_TICKET_PAGE'.PHP_EOL;
