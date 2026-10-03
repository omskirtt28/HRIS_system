<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__).'/app/bootstrap.php';
if(!($GLOBALS['pdo']??null) instanceof PDO) { fwrite(STDERR,"Database connection unavailable.\n"); exit(1); }
if(!PayrollAttendanceService::ready()) { fwrite(STDERR,"Install the payroll biometric migration first.\n"); exit(1); }
try {
    $total=0;
    do { $count=PayrollAttendanceService::purgeExpired(); $total+=$count; } while($count===200);
    echo "Expired biometric files removed: ".$total."\n";
} catch(Throwable $e) { fwrite(STDERR,"Payroll cleanup failed; see server diagnostics.\n"); exit(1); }
