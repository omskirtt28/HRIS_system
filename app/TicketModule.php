<?php
declare(strict_types=1);
require_once __DIR__.'/TicketService.php';
require_once __DIR__.'/TicketViews.php';
require_once __DIR__.'/TicketAccountingViews.php';
require_once __DIR__.'/TicketPrintForms.php';

function ticket_handle(string $page):void
{
    $pages=['hr-requests-my','hr-request-new','hr-request','hr-request-inbox','hr-payroll-concerns','hr-request-attachment','hr-service-requests','hr-accounting-requests','hr-ticket-accounting-members','hr-incidents','hr-print-forms','hr-request-team'];
    $action=(string)($_POST['action']??'');
    if(!in_array($page,$pages,true) && !str_starts_with($action,'core_ticket_'))return;
    need_db();if(!Auth::check())redirect('login');
    if(!TicketRepository::ready()){http_response_code(503);exit('HR Concerns requires Slice-15 migration 003.');}
    header('Cache-Control: no-store');
    $id=(int)($_GET['id']??$_POST['ticket_id']??0);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        try{
            if($action==='core_ticket_accounting_member'){
                TicketAccounting::manage((int)($_POST['user_id']??0),($_POST['active']??'')==='1');flash('success','Accounting membership updated.');redirect('hr-ticket-accounting-members');
            }elseif($action==='core_ticket_netsuite_remark'){
                TicketAccounting::remark($id,(string)($_POST['lane']??''),(string)($_POST['body']??''));flash('success','Remarks recorded.');
            }elseif($action==='core_ticket_create'){
                $_SESSION['core_ticket_old']=$_POST;
                $key=is_string($_POST['submission_key']??null)?$_POST['submission_key']:'';
                if(($_SESSION['core_ticket_keys'][$key]??0)<time())throw new RuntimeException('This filing form expired. Open a new request form.');
                $id=TicketService::create($_POST,($_POST['intent']??'')==='SUBMIT',$_FILES['attachments']??[]);
                unset($_SESSION['core_ticket_old']);flash('success',($_POST['intent']??'')==='SUBMIT'?'HR request submitted.':'Draft saved.');
            }elseif($action==='core_ticket_edit'){
                $_SESSION['core_ticket_edit'][$id]=$_POST;
                TicketService::edit($id,(int)($_POST['version']??0),$_POST,($_POST['intent']??'')==='SUBMIT',$_FILES['attachments']??[]);
                unset($_SESSION['core_ticket_edit'][$id]);flash('success',($_POST['intent']??'')==='SUBMIT'?'HR request submitted.':'Changes saved.');
            }elseif($action==='core_ticket_act'){
                $_SESSION['core_ticket_action'][$id]=$_POST;
                TicketService::act($id,(int)($_POST['version']??0),(string)($_POST['decision']??''),(string)($_POST['remarks']??''),(string)($_POST['outcome']??''),(int)($_POST['target_period_id']??0),['new_approver_user_id'=>(int)($_POST['new_approver_user_id']??0)]);unset($_SESSION['core_ticket_action'][$id]);flash('success','Ticket action recorded.');
            }elseif($action==='core_ticket_comment'){
                $_SESSION['core_ticket_comment'][$id]=(string)($_POST['body']??'');
                TicketService::comment($id,(string)($_POST['body']??''),(string)($_POST['visibility']??'ALL'));unset($_SESSION['core_ticket_comment'][$id]);flash('success','Comment added.');
            }elseif($action==='core_ticket_attach'){
                TicketService::attach($id,$_FILES['attachments']??[]);flash('success','Attachment(s) added.');
            }else throw new RuntimeException('Unknown ticket action.');
            redirect('hr-request',['id'=>$id]);
        }catch(Throwable $error){
            if($error instanceof PDOException)error_log('HR request database action failed.');
            flash('error',$error instanceof PDOException?'The request could not be saved. Refresh and try again.':$error->getMessage());
            redirect($action==='core_ticket_create'?'hr-request-new':'hr-request',$action==='core_ticket_create'?[]:['id'=>$id]);
        }
    }
    try{
        if($page==='hr-request-attachment'){
            $file=TicketService::attachment($id);header('X-Content-Type-Options: nosniff');header('Content-Type: '.$file['mime_type']);
            header('Content-Disposition: attachment; filename="attachment.'.pathinfo($file['stored_name'],PATHINFO_EXTENSION).'"; filename*=UTF-8\'\''.rawurlencode($file['original_name']));
            header('Content-Length: '.filesize($file['path']));readfile($file['path']);exit;
        }
        if($page==='hr-print-forms'){ticket_print_forms();}
        elseif($page==='hr-ticket-accounting-members'){ticket_accounting_members_page();}
        elseif($page==='hr-request-new'){
            if(!Auth::can('tickets.request_self')&&!Auth::can('ticket.file_for_others'))throw new RuntimeException('Filing permission is required.');
            try{$employee=TicketRepository::ownEmployee();}catch(RuntimeException $e){if(!Auth::can('ticket.file_for_others'))throw $e;$employee=['branch_name'=>'Select the subject employee below'];
                if(empty($_GET['type'])&&empty($_SESSION['core_ticket_old']['ticket_type_id']))$_GET['type']=(int)db()->query('SELECT id FROM ticket_types WHERE code="BRANCH_TRANSFER" AND active=1')->fetchColumn();} 
            $old=$_SESSION['core_ticket_old']??[];$key=bin2hex(random_bytes(32));
            $_SESSION['core_ticket_keys']=array_filter($_SESSION['core_ticket_keys']??[],fn($expires)=>$expires>=time());
            $_SESSION['core_ticket_keys']=array_slice($_SESSION['core_ticket_keys'],-19,null,true);$_SESSION['core_ticket_keys'][$key]=time()+1800;
            ticket_new_page($employee,TicketRepository::types(),$key,$old);
        }elseif($page==='hr-request')ticket_detail_page(TicketRepository::detail($id));
        else{
            $mode=match($page){'hr-request-inbox'=>'inbox','hr-payroll-concerns'=>'hr','hr-service-requests'=>'services','hr-accounting-requests'=>'accounting','hr-incidents'=>'feed','hr-request-team'=>'team',default=>'my'};
            if($mode==='my'&&!Auth::can('tickets.request_self')&&!Auth::can('ticket.file_for_others'))throw new RuntimeException('Filing permission is required.');
            ticket_list_page($mode,TicketRepository::listing($mode,$_GET),$_GET);
        }
    }catch(RuntimeException $error){http_response_code(403);ticket_error_page($error->getMessage());}
    exit;
}
