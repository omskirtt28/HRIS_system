<?php
declare(strict_types=1);
require_once __DIR__.'/TicketViewerService.php';
require_once __DIR__.'/TicketViewerViews.php';

function ticket_viewers_handle(string $page): void
{
    $action=(string)($_POST['action']??'');
    if($page!=='hr-ticket-viewers' && !str_starts_with($action,'ticket_viewers_'))return;
    need_db();
    try{EmployeeApproverAssignmentService::requireHr();}catch(RuntimeException){http_response_code(403);exit('HR approver-assignment access is required.');}
    if(!TicketViewerService::ready()){http_response_code(503);exit('Ticket viewers require Slice-15 migration 012.');}
    header('Cache-Control: no-store');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        try{
            if($action==='ticket_viewers_assign'){
                [$added,$skipped]=TicketViewerService::assignLeads($_POST);
                flash('success',$added.' branch lead assignment(s) saved'.($skipped?'; '.$skipped.' already had this lead.':'.').' Leads see their branch requests read-only.');
            }elseif($action==='ticket_viewers_end_lead'){
                TicketViewerService::endLead((int)($_POST['lead_id']??0));flash('success','Branch lead ended.');
            }elseif($action==='ticket_viewers_add_override'){
                TicketViewerService::addOverride($_POST);flash('success','Extra viewer saved.');
            }elseif($action==='ticket_viewers_end_override'){
                TicketViewerService::endOverride((int)($_POST['override_id']??0));flash('success','Extra viewer ended.');
            }else throw new RuntimeException('Unknown viewer action.');
        }catch(Throwable $error){
            if($error instanceof PDOException)error_log('Ticket viewer database operation failed.');
            flash('error',$error instanceof PDOException?'The change could not be saved. Refresh and try again.':$error->getMessage());
        }
        redirect('hr-ticket-viewers',array_filter(['area_id'=>$_POST['return_area_id']??'','branch_id'=>$_POST['return_branch_id']??''],static fn($v)=>$v!==''&&$v!=='0'));
    }
    $filter=['area_id'=>(int)($_GET['area_id']??0),'branch_id'=>max(0,(int)($_GET['branch_id']??0)),'p'=>max(1,(int)($_GET['p']??1))];
    ticket_viewers_page($filter,TicketViewerService::branchRows($filter['area_id'],$filter['branch_id']),TicketViewerService::report($filter['p']),TicketViewerService::overrides(),TicketViewerService::leadCandidates());
    exit;
}
