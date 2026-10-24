<?php
declare(strict_types=1);
require_once __DIR__.'/TicketViewerService.php';
require_once __DIR__.'/TicketViewerViews.php';

/** Ticket Viewers is a read-only problem report. Viewers are derived automatically; there is nothing to assign here. */
function ticket_viewers_handle(string $page): void
{
    $action=(string)($_POST['action']??'');
    if($page!=='hr-ticket-viewers' && !str_starts_with($action,'ticket_viewers_'))return;
    need_db();
    try{EmployeeApproverAssignmentService::requireHr();}catch(RuntimeException){http_response_code(403);exit('HR approver-assignment access is required.');}
    if(!TicketViewerService::ready()){http_response_code(503);exit('Ticket viewers require Slice-15 migration 012.');}
    header('Cache-Control: no-store');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        // The old assign-lead / extra-viewer actions are gone on purpose; refuse them rather than ignore them.
        verify_csrf();http_response_code(405);exit('Ticket viewers are set automatically from branch, position and area. They cannot be assigned here.');
    }
    $filter=['area_id'=>(int)($_GET['area_id']??0),'branch_id'=>max(0,(int)($_GET['branch_id']??0)),'problems'=>($_GET['all']??'')!=='1','p'=>max(1,(int)($_GET['p']??1))];
    ticket_viewers_page($filter,TicketViewerService::branchRows($filter['area_id'],$filter['branch_id'],$filter['problems']),TicketViewerService::report($filter['p']));
    exit;
}
