<?php
declare(strict_types=1);
require_once __DIR__.'/TicketService.php';
require_once __DIR__.'/TicketViews.php';
require_once __DIR__.'/IncidentViews.php';

/**
 * Incident Reports, employee side (plan 2026-10-25-incident-report-module, I2): My Incidents, File an Incident, the people search and the filing action.
 * The case page and HR handling arrive in I3 and I4. Filing always goes through TicketService::create(); the type is set here on the server.
 */
function incident_handle(string $page):void
{
    $action=(string)($_POST['action']??'');
    if(!in_array($page,['incident-mine','incident-new','incident-people'],true)&&$action!=='core_incident_create')return;
    need_db();if(!Auth::check())redirect('login');
    // Client-portal accounts have no incident screens.
    if(!in_array(Auth::user()['role_portal']??'',['employee','hr','admin'],true)){http_response_code(403);exit('Incident Reports are not available for this account.');}
    if(!TicketRepository::ready()||!TicketIncident::ready()){http_response_code(503);exit('Incident Reports need the latest database update.');}
    header('Cache-Control: no-store');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        if($action!=='core_incident_create'){http_response_code(405);exit('That action is not available.');}
        incident_create();
    }
    try{
        if($page==='incident-people'){
            // Read-only JSON for the picker. Needs the filing permission; returns names and branches of active accounts only.
            TicketTypes::assertCanFile('INCIDENT_REPORT');
            header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');
            echo json_encode(['people'=>TicketIncident::searchPeople((string)($_GET['q']??''))],JSON_THROW_ON_ERROR);exit;
        }
        if($page==='incident-mine'){
            $result=TicketRepository::listing('incidents_mine',$_GET,25);
            incident_mine_page($result,TicketIncident::summaries(array_column($result['rows'],'id')));exit;
        }
        // incident-new
        TicketTypes::assertCanFile('INCIDENT_REPORT');
        $employee=null;
        try{$employee=TicketRepository::ownEmployee();}catch(RuntimeException){$employee=null;}// the view explains it
        $old=$_SESSION['core_incident_old']??[];unset($_SESSION['core_incident_old']);
        $key=bin2hex(random_bytes(32));
        $_SESSION['core_ticket_keys']=array_filter($_SESSION['core_ticket_keys']??[],fn($expires)=>$expires>=time());
        $_SESSION['core_ticket_keys']=array_slice($_SESSION['core_ticket_keys'],-19,null,true);$_SESSION['core_ticket_keys'][$key]=time()+1800;
        $ids=[];foreach((array)($old['participants']??[]) as $p)if(is_array($p))$ids[]=$p['user_id']??0;
        incident_new_page($employee,$key,is_array($old)?$old:[],TicketIncident::namesFor($ids));
    }catch(RuntimeException $error){http_response_code(403);ticket_error_page($error->getMessage());
    }catch(PDOException){error_log('Incident page database error.');http_response_code(500);ticket_error_page('This page could not be loaded. Please try again.');}
    exit;
}

/** POST core_incident_create. Only the listed fields are read from the form; the type, filer and status are never taken from it. */
function incident_create():never
{
    $old=[];
    try{
        TicketTypes::assertCanFile('INCIDENT_REPORT');
        $key=is_string($_POST['submission_key']??null)?$_POST['submission_key']:'';
        if(($_SESSION['core_ticket_keys'][$key]??0)<time())throw new RuntimeException('This form expired. Open File an Incident again.');
        $str=fn(string $k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';
        $people=[];
        foreach(is_array($_POST['participants']??null)?$_POST['participants']:[] as $p)$people[]=is_array($p)?['user_id'=>$p['user_id']??'','role'=>$p['role']??'']:['user_id'=>'','role'=>''];
        $old=['title'=>$str('title'),'category'=>$str('category'),'explanation'=>$str('explanation'),'incident_at'=>$str('incident_at'),'location'=>$str('location'),'branch_id'=>$str('branch_id'),'participants'=>$people];
        $typeId=(int)db()->query('SELECT id FROM ticket_types WHERE code="INCIDENT_REPORT" AND active=1')->fetchColumn();
        if(!$typeId)throw new RuntimeException('Incident Reports are not available.');
        $_SESSION['core_incident_old']=$old;
        $id=TicketService::create($old+['ticket_type_id'=>$typeId,'submission_key'=>$key],true,$_FILES['attachments']??[]);
        unset($_SESSION['core_incident_old']);
        $q=db()->prepare('SELECT ticket_no FROM tickets WHERE id=?');$q->execute([$id]);$no=(string)$q->fetchColumn();
        flash('success','Incident report '.$no.' submitted. HR has been notified.');
        redirect('incident-mine');
    }catch(RuntimeException $error){
        // Rule and validation messages are written for the reporter. Anything else is logged and replaced by a generic line.
        flash('error',$error->getMessage());redirect('incident-new');
    }catch(Throwable $error){
        error_log('Incident filing failed: '.get_class($error));
        flash('error','The report could not be saved. Please try again.');redirect('incident-new');
    }
    exit;
}
