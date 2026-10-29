<?php
declare(strict_types=1);
require_once __DIR__.'/../app/NotificationService.php';

// Pure/static regression checks: no bootstrap, app requests or database connection.
function expectSidebar(mixed $actual,mixed $expected): void {
    if($actual!==$expected)throw new RuntimeException(var_export([$actual,$expected],true));
}
$access=['incidents'=>true,'self'=>true,'hr'=>true,'approve'=>true,'accounting'=>true,'reminders'=>true];
$request=['kind'=>'HR_REQUEST','type_code'=>'PAYROLL_CONCERN','requester_user_id'=>7,'subject_user_id'=>7,
    'ticket_status'=>'PENDING_HR','assigned_approval'=>0,'incident_readable'=>1,'incident_mine'=>0];
$page=static fn(array $changes=[],array $permissions=[])=>NotificationService::sidebarPage(array_replace($request,$changes),array_replace($access,$permissions),7);

// 1. Request ownership, assignment, Accounting and HR review precedence.
expectSidebar($page(),'hr-requests-my');
expectSidebar($page([],['self'=>false]),null);
expectSidebar($page(['requester_user_id'=>8]),'hr-payroll-concerns');
expectSidebar($page(['requester_user_id'=>8,'type_code'=>'ID_REQUEST']),'hr-service-requests');
expectSidebar($page(['requester_user_id'=>8,'subject_user_id'=>8,'assigned_approval'=>1]),'hr-request-inbox');
expectSidebar($page(['requester_user_id'=>8,'subject_user_id'=>8,'assigned_approval'=>1],['approve'=>false]),'hr-payroll-concerns');
expectSidebar($page(['requester_user_id'=>8,'type_code'=>'NETSUITE_CONCERN','ticket_status'=>'COMPLETED','assigned_approval'=>1]),'hr-accounting-requests');
// 2. Incident ownership, reviewer, participant and revoked access.
expectSidebar($page(['type_code'=>'INCIDENT_REPORT']),'incident-mine');
expectSidebar($page(['type_code'=>'INCIDENT_REPORT','requester_user_id'=>8]),'hr-incidents');
expectSidebar($page(['type_code'=>'INCIDENT_REPORT','requester_user_id'=>8,'incident_mine'=>1],['hr'=>false]),'incident-mine');
expectSidebar($page(['type_code'=>'INCIDENT_REPORT','incident_readable'=>0]),null);
expectSidebar($page(['type_code'=>'INCIDENT_REPORT'],['incidents'=>false]),null);
expectSidebar($page(['type_code'=>'INCIDENT_REPORT','requester_user_id'=>8],['hr'=>false]),null);

// 3. Existing reminders require an accessible destination; unknown kinds are not guessed.
foreach(['PROBATION_5M','PROBATION_END','SEASONAL_2M','SEASONAL_END','MATERNITY_14D','MATERNITY_RETURN'] as $kind){
    expectSidebar($page(['kind'=>$kind]),'hr-probation');
    expectSidebar($page(['kind'=>$kind],['reminders'=>false]),null);
}
expectSidebar($page(['kind'=>'UNKNOWN']),null);
expectSidebar($page(['type_code'=>null]),null);

echo "PASS: three sidebar notification mapping scenarios\n";
