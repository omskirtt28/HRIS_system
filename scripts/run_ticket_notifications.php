<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/TicketNotifications.php';
echo json_encode(TicketNotifications::flush(500),JSON_THROW_ON_ERROR).PHP_EOL;
