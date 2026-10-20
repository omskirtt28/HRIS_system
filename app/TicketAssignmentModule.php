<?php
declare(strict_types=1);
require_once __DIR__.'/EmployeeApproverAssignmentService.php';
require_once __DIR__.'/TicketAssignmentViews.php';

function ticket_assignment_handle(string $page): void
{
    $action=(string)($_POST['action']??'');
    if($page!=='hr-ticket-approvers' && !str_starts_with($action,'ticket_approvers_'))return;
    need_db();
    try{EmployeeApproverAssignmentService::requireHr();}catch(RuntimeException){http_response_code(403);exit('HR approver-assignment access is required.');}
    if(!EmployeeApproverAssignmentService::ready()){http_response_code(503);exit('Ticket approver setup requires Slice-15 migrations 001 and 002.');}
    header('Cache-Control: no-store');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        try{
            if($action==='ticket_approvers_preview'){
                $_SESSION['ticket_approvers_old']=$_POST;
                $preview=EmployeeApproverAssignmentService::preview($_POST);
                $preview['token']=bin2hex(random_bytes(24));$preview['expires']=time()+900;$preview['actor_user_id']=(int)Auth::user()['id'];
                $_SESSION['ticket_approvers_preview']=$preview;
            }elseif($action==='ticket_approvers_save'){
                $preview=$_SESSION['ticket_approvers_preview']??null;
                if(!$preview || $preview['expires']<time() || $preview['actor_user_id']!==(int)Auth::user()['id'] || !is_string($_POST['preview_token']??null) || !hash_equals($preview['token'],$_POST['preview_token']))throw new RuntimeException('Preview expired or already saved. Preview your selection again.');
                $count=EmployeeApproverAssignmentService::save($preview);
                unset($_SESSION['ticket_approvers_preview'],$_SESSION['ticket_approvers_old']);
                flash('success',$count.' ticket approver assignment(s) saved. Existing payroll reporting lines were preserved.');
            }elseif($action==='ticket_approvers_cancel'){
                unset($_SESSION['ticket_approvers_preview']);
            }else{throw new RuntimeException('Unknown assignment action.');}
        }catch(Throwable $error){
            if($error instanceof PDOException)error_log('Ticket approver database operation failed.');
            flash('error',$error instanceof PDOException?'The assignment could not be saved. Refresh and try again.':$error->getMessage());
            unset($_SESSION['ticket_approvers_preview']);
        }
        redirect('hr-ticket-approvers');
    }
    $filter=[
        'branch_id'=>max(0,(int)($_GET['branch_id']??0)),
        'business_unit_id'=>max(0,(int)($_GET['business_unit_id']??0)),
        'company_group'=>in_array($_GET['company_group']??'',['SW','OC','UF'],true)?$_GET['company_group']:'',
        'kind'=>in_array($_GET['kind']??'',['MANAGER','ADL'],true)?$_GET['kind']:'',
        'unresolved'=>!empty($_GET['unresolved']), 'p'=>max(1,(int)($_GET['p']??1)),
    ];
    $preview=$_SESSION['ticket_approvers_preview']??null;
    if($preview && ($preview['expires']<time() || $preview['actor_user_id']!==(int)Auth::user()['id'])){unset($_SESSION['ticket_approvers_preview']);$preview=null;}
    ticket_assignment_page(EmployeeApproverAssignmentService::report($filter),$filter,$preview);
    exit;
}
