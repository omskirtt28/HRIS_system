<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server' || !preg_match('/^hris_slice15_test_\d{8}_\d{6}$/',getenv('HRIS_TEST_DB')?:'')){http_response_code(404);exit;}
$root=dirname(__DIR__);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(is_string($path) && str_starts_with($path,'/public/assets/') && !str_contains($path,'..')){
    $file=$root.$path;if(is_file($file)){header('Content-Type: '.match(pathinfo($file,PATHINFO_EXTENSION)){'css'=>'text/css','js'=>'application/javascript','png'=>'image/png','svg'=>'image/svg+xml',default=>'application/octet-stream'});readfile($file);exit;}
}
if(!in_array($path,['/','/index.php'],true)){http_response_code(404);exit;}
session_save_path($root.'/storage/slice15-private/sessions');
require $root.'/storage/slice15-private/test-index.php';
