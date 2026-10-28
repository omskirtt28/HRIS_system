<?php
// Copy to config.local.php and edit for your local/live server.
return [
    'app' => ['debug' => false, 'base_url' => ''],
    // Self-hosted optional Incident Report PDF preview. Explicit absolute LibreOffice console executable path only.
    // Example Windows: C:/Program Files/LibreOffice/program/soffice.com; Linux: /usr/bin/libreoffice.
    // Leave blank for DOCX-only reports. Each conversion uses an isolated private profile; no external upload.
    'incident_documents' => [
        // Persistent, writable ABSOLUTE directory OUTSIDE the project, DOCUMENT_ROOT and XAMPP htdocs.
        // Defaults here to C:/xampp/hris-private/incident_documents; configure an external private path in production.
        // Never rely on .htaccess for confidentiality: php.exe -S ignores it. Do not publish this directory or add a web alias.
        'storage_root' => dirname(__DIR__, 3) . '/hris-private/incident_documents',
        'libreoffice_path' => '',
        'conversion_timeout_seconds' => 25, // bounded to 5-60 seconds
    ],
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
