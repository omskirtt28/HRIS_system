<?php
return [
    'app' => [
        'name' => 'HRIS',
        'base_url' => '',
        'timezone' => 'Asia/Manila',
        'debug' => true,
    ],
    // White-label identity. Override per client in config.local.php; logo = optional image path (empty = built-in mark).
    'brand' => [
        'product' => 'HRIS',
        'company' => 'Demo Company',
        'tagline' => 'Human Resources Information System',
        'logo' => '',
        'color' => '#2563eb',
        'color_strong' => '#1d4ed8',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'hris_demo',
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
