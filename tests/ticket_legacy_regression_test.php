<?php
declare(strict_types=1);
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/PayrollTicketViews.php';
$f=fixtures();
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$f['manager_employee'],$f['requester']]);
$today=date('Y-m-d');
$hrRole=$f['hr']['role_id'];
db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code="leave.approve_hr"')->execute([$hrRole]);
foreach(['TA'=>['time_in'=>'08:00'],'OB'=>['ob_time_out'=>'17:00','purpose'=>'Synthetic regression'],'OT'=>['ot_start'=>'18:00','ot_end'=>'20:00']] as $code=>$fields){
    act($f['employee']);
    $type=row('SELECT * FROM payroll_request_types WHERE code=?',[$code]);
    // Scope this service regression to routing/approval; do not fabricate uploaded evidence.
    // Attachment requirements are restored immediately; legacy upload code is unchanged.
    db()->prepare('UPDATE payroll_request_types SET requires_attachment=0 WHERE id=?')->execute([$type['id']]);
    try{
        $id=PayrollRepository::createPayrollRequest($f['requester'],array_merge(['request_type_id'=>$type['id'],'affected_date'=>$today,'reason'=>'Synthetic Slice15 regression'], $fields));
    }finally{db()->prepare('UPDATE payroll_request_types SET requires_attachment=? WHERE id=?')->execute([$type['requires_attachment'],$type['id']]);}
    $r=PayrollRepository::payrollRequest($id);
    check('legacy '.$code.' starts with HR verification',$r['status']==='FOR_HR_TIMEKEEPING_APPROVAL' && $r['approvals'][0]['approver_type']==='HR_TIMEKEEPING');
    act($f['hr']);$queue=PayrollRepository::hrPayrollQueue('HR_TIMEKEEPING');
    check('Payroll Ticket Review includes '.$code,in_array($id,array_map('intval',array_column($queue,'id')),true));
    PayrollRepository::decidePayroll($id,'APPROVE','Synthetic HR verification',(int)$r['current_step']);
    $r=PayrollRepository::payrollRequest($id);
    check('legacy '.$code.' HR forwards to Manager',$r['status']==='FOR_MANAGER_APPROVAL');
    act($f['manager']);PayrollRepository::decidePayroll($id,'APPROVE','Synthetic manager approval');
    check('legacy '.$code.' final approval completes',PayrollRepository::payrollRequest($id)['status']==='APPROVED');
}
$leaveType=insert('INSERT INTO leave_types(code,name,requires_credit,active) VALUES(?,"Slice15 Synthetic Leave",0,1)',[$f['tag']]);
act($f['employee']);$leave=PayrollRepository::createLeaveRequest($f['requester'],['leave_type_id'=>$leaveType,'date_from'=>$today,'date_to'=>$today,'reason'=>'Synthetic Slice15 leave']);
check('legacy leave starts with Manager',row('SELECT status FROM leave_requests WHERE id=?',[$leave])['status']==='FOR_MANAGER_APPROVAL');
act($f['manager']);PayrollRepository::decideLeave($leave,'APPROVE','Synthetic manager approval');
check('legacy leave forwards to HR',row('SELECT status FROM leave_requests WHERE id=?',[$leave])['status']==='FOR_HR_LEAVE_APPROVAL');
act($f['hr']);PayrollRepository::decideLeave($leave,'APPROVE','Synthetic HR leave approval');
check('legacy leave completes',row('SELECT status FROM leave_requests WHERE id=?',[$leave])['status']==='APPROVED');
// New generic assignments must not alter the old request's frozen Manager/ADL chain.
$before=row('SELECT approver_user_id FROM payroll_request_approvals WHERE request_id=? AND approver_type="MANAGER"',[$id]);
EmployeeApproverAssignmentService::save(EmployeeApproverAssignmentService::preview(['employee_ids'=>[$f['requester']],'approver_type'=>'MANAGER','approver_user_id'=>$f['manager']['id'],'effective_from'=>$today]));
check('generic assignments preserve legacy frozen chain',row('SELECT approver_user_id FROM payroll_request_approvals WHERE request_id=? AND approver_type="MANAGER"',[$id])===$before);
finish();
