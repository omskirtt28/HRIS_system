<?php
// Copy to config.local.php and edit for your local/live server.
return [
    'app' => ['debug' => false, 'base_url' => ''],
    // Email for reminders (Gmail needs an App Password: Google Account > Security > 2-Step Verification > App passwords).
    'mail' => [
        'host' => 'smtp.gmail.com',
        'port' => 465,
        'username' => 'your.hr.account@gmail.com',
        'password' => 'xxxx xxxx xxxx xxxx',
        'from_name' => 'PMBSI HRIS',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'pmbsi_hris',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
];
