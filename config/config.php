<?php
return [
    'app' => [
        'name' => 'PMBSI HRIS - Recruitment',
        'base_url' => '',
        'timezone' => 'Asia/Manila',
        'debug' => true,
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'pmbsi_hris',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'uploads' => [
        'resume_dir' => dirname(__DIR__) . '/storage/resumes',
        'max_bytes' => 5 * 1024 * 1024,
        'allowed_mime' => [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ],
    ],
];
