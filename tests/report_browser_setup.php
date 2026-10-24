<?php
declare(strict_types=1);
// Seeds the HR-queue fixtures plus report access for the HR fixture user (isolated database only).
require __DIR__.'/hr_ops_browser_setup.php';
$p=row('SELECT id FROM permissions WHERE code="ticket.report.view"');
db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$f['hr']['role_id'],$p['id']]);
echo "PASS report access granted to the HR fixture role\n";
