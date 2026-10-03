<?php
declare(strict_types=1);
// Router for php -S localhost:3000 router.php. Apache uses .htaccess instead.
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');
if(str_contains($path,"\0")||str_contains($path,'..')||preg_match('#^/(app|config|database|storage|vendor|scripts|output|tmp|\.git)(/|$)#i',$path)) { http_response_code(403); exit('Access denied'); }
if($path!=='/' && is_file(__DIR__.$path) && preg_match('/\.(css|js|png|jpg|jpeg|webp|ico|svg|woff2?)$/i',$path)) return false;
require __DIR__.'/index.php';
