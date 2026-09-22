<?php
declare(strict_types=1);

$base = require dirname(__DIR__) . '/config/config.php';
$localFile = dirname(__DIR__) . '/config/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    $base = array_replace_recursive($base, $local);
}
$GLOBALS['config'] = $base;
date_default_timezone_set($base['app']['timezone'] ?? 'Asia/Manila');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/RecruitmentRepository.php';

try {
    $GLOBALS['pdo'] = Database::connect($base['db']);
} catch (Throwable $e) {
    $GLOBALS['pdo'] = null;
    $GLOBALS['db_error'] = !empty($base['app']['debug']) ? $e->getMessage() : 'Unable to connect to the database.';
}
