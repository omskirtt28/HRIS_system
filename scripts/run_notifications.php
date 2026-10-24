<?php
// Run the probation reminders now. Schedule this daily with Windows Task Scheduler so emails go out even when nobody opens HRIS:
//   C:\xampp\php\php.exe C:\xampp\htdocs\HRIS_system\scripts\run_notifications.php
// Check your Gmail settings once with:  php scripts\run_notifications.php --test-email=you@example.com
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'app/bootstrap.php';
require_once 'app/ProbationService.php';
foreach(array_slice($argv,1) as $arg) {
    if(str_starts_with($arg,'--test-email=')) {
        try { Mailer::send(substr($arg,13),'[PMBSI HRIS] Test email','If you can read this, HRIS email is working.'); echo "Test email sent.\n"; }
        catch(Throwable $e) { echo 'Test email failed: '.$e->getMessage()."\n"; exit(1); }
        exit(0);
    }
}
// Reuse the existing scheduled notification job for durable HR-request retries.
require_once 'app/TicketNotifications.php';
if(FoundationRepository::tableExists('ticket_notification_outbox')) {
    try { $tickets=TicketNotifications::flush(500); echo 'HR request notifications: '.$tickets['delivered'].' delivered, '.$tickets['pending']." deferred.\n"; }
    catch(Throwable) { error_log('HR request notification retry deferred.'); }
}
// OIC Authorization expiry (same scheduled task; idempotent).
require_once 'app/TicketOicAuthorization.php';
$oic=TicketOicAuthorization::runDue();
if($oic)echo 'OIC authorizations expired: '.$oic['expired'].', roles restored: '.$oic['restored']."
";
$result=ProbationService::run();
echo date('Y-m-d H:i').' checked '.$result['checked'].', new reminders '.$result['created'].', emailed '.$result['emailed'].', email failed '.$result['email_failed'].(Mailer::configured()?'':' (email not set up)')."\n";
