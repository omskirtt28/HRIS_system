<?php
declare(strict_types=1);
require_once __DIR__.'/TicketService.php';
require_once __DIR__.'/TicketViews.php';
require_once __DIR__.'/TicketReportViews.php';

/** Ticketing Report: the page, its CSV export and the one-page printable summary. Read-only; HR roles with ticket.report.view. */
function ticket_report_handle(string $page): void
{
    if(!in_array($page,['hr-ticket-report','hr-ticket-report-export','hr-ticket-report-print'],true))return;
    need_db();if(!Auth::check())redirect('login');
    if(!TicketRepository::ready()){http_response_code(503);exit('The Ticketing Report requires Slice-15 migration 003.');}
    header('Cache-Control: no-store');
    try{
        TicketReport::require();$p=TicketReport::params($_GET);
        if($page==='hr-ticket-report-export'){
            $r=TicketReport::csvRows($p);audit('Tickets','EXPORT_REPORT','ticket',null,['rows'=>count($r['rows']),'truncated'=>$r['truncated'],'filters'=>TicketReport::linkParams($p)]);
            header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="ticketing-report-'.date('Ymd').'.csv"');header('X-Content-Type-Options: nosniff');
            $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,TicketReport::CSV_HEADER);foreach($r['rows'] as $row)fputcsv($out,array_map([TicketQueue::class,'csvCell'],$row));
            if($r['truncated'])fputcsv($out,['Export stopped at '.TicketReport::CSV_CAP.' rows. Narrow the filters to see the rest.']);
            fclose($out);exit;
        }
        $d=TicketReport::data($p);
        if($page==='hr-ticket-report-print'){header('Content-Type: text/html; charset=utf-8');ticket_report_print_page($p,$d);}
        else ticket_report_page($p,$d);
    }catch(RuntimeException $error){http_response_code(403);ticket_error_page($error->getMessage());}
    exit;
}
