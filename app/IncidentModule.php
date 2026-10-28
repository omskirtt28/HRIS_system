<?php
declare(strict_types=1);
require_once __DIR__.'/TicketService.php';
require_once __DIR__.'/TicketViews.php';
require_once __DIR__.'/IncidentViews.php';

/**
 * Incident Reports, employee side (plan 2026-10-25-incident-report-module, I2): My Incidents, File an Incident, the people search and the filing action.
 * The case page and HR handling arrive in I3 and I4. Filing always goes through TicketService::create(); the type is set here on the server.
 */
/** POST actions owned by this module. Case actions (I3) are posted to the pseudo-page incident-act and always return to the case. */
const INCIDENT_ACTIONS=['core_incident_create','core_incident_status','core_incident_participant_add','core_incident_participant_remove','core_incident_note','core_incident_message','core_incident_pdf_retry'];

function incident_handle(string $page):void
{
    $action=(string)($_POST['action']??'');
    if(!in_array($page,['incident-mine','incident-new','incident-people','incident-act','incident-document'],true)&&!in_array($action,INCIDENT_ACTIONS,true))return;
    need_db();if(!Auth::check())redirect('login');
    // Client-portal accounts have no incident screens.
    if(!in_array(Auth::user()['role_portal']??'',['employee','hr','admin'],true)){http_response_code(403);exit('Incident Reports are not available for this account.');}
    if(!TicketRepository::ready()||!TicketIncident::ready()){http_response_code(503);exit('Incident Reports need the latest database update.');}
    header('Cache-Control: no-store');
    if($page==='incident-document'){
        if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET');http_response_code(405);exit('Document access requires GET.');}
        incident_document_response();
    }
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        if(!in_array($action,INCIDENT_ACTIONS,true)){http_response_code(405);exit('That action is not available.');}
        $action==='core_incident_create'?incident_create():incident_case_action($action);
    }
    if($page==='incident-act')redirect('incident-mine');// case actions are POST only
    try{
        if($page==='incident-people'){
            // Read-only JSON for the picker. Needs the filing permission, or, with case=, being the HR reviewer handling that case (BR-780).
            // Returns names and branches of active accounts only (BR-778); on a case, people who already read it are left out.
            $case=is_string($_GET['case']??null)&&ctype_digit($_GET['case'])?(int)$_GET['case']:0;$exclude=[];
            if($case>0){
                $t=TicketRepository::find($case);
                if($t['type_code']!=='INCIDENT_REPORT'||!TicketIncident::isHandler($t))throw new RuntimeException('Only the HR reviewer handling this case can search for people to add.');
                $exclude=TicketIncident::peopleOnCase($case);
            }else TicketTypes::assertCanFile('INCIDENT_REPORT');
            header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');
            echo json_encode(['people'=>TicketIncident::searchPeople((string)($_GET['q']??''),$exclude)],JSON_THROW_ON_ERROR);exit;
        }
        if($page==='incident-mine'){
            $result=TicketRepository::listing('incidents_mine',$_GET,25);
            incident_mine_page($result,TicketIncident::summaries(array_column($result['rows'],'id')));exit;
        }
        // incident-new
        TicketTypes::assertCanFile('INCIDENT_REPORT');
        $employee=null;
        try{$employee=TicketRepository::ownEmployee();}catch(PDOException $error){throw $error;}catch(RuntimeException){$employee=null;}// the view explains a missing link; database errors use the outer safe handler
        if($employee!==null)$employee['incident_filer']=IncidentDocument::filerContext($employee);
        $old=$_SESSION['core_incident_old']??[];unset($_SESSION['core_incident_old']);
        $key=bin2hex(random_bytes(32));
        $_SESSION['core_ticket_keys']=array_filter($_SESSION['core_ticket_keys']??[],fn($expires)=>$expires>=time());
        $_SESSION['core_ticket_keys']=array_slice($_SESSION['core_ticket_keys'],-19,null,true);$_SESSION['core_ticket_keys'][$key]=time()+1800;
        $ids=[];foreach((array)($old['participants']??[]) as $p)if(is_array($p))$ids[]=$p['user_id']??0;
        incident_new_page($employee,$key,is_array($old)?$old:[],TicketIncident::namesFor($ids));
    }catch(PDOException){error_log('Incident page database error.');http_response_code(500);ticket_error_page('This page could not be loaded. Please try again.');
    }catch(RuntimeException $error){http_response_code(403);ticket_error_page($error->getMessage());}
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
        $old=['title'=>$str('title'),'explanation'=>$str('explanation'),'incident_at'=>$str('incident_at'),'location'=>$str('location'),'branch_id'=>$str('branch_id'),'participants'=>$people];
        foreach(['nature','nature_other','location_type','incident_end_time','immediate_head','contact_no','involved_names','witness_names','initial_action','customer_name','customer_contact','supporting_other'] as $field)$old[$field]=$str($field);
        $old['supporting']=is_array($_POST['supporting']??null)?array_values(array_filter($_POST['supporting'],'is_string')):[];
        $typeId=(int)db()->query('SELECT id FROM ticket_types WHERE code="INCIDENT_REPORT" AND active=1')->fetchColumn();
        if(!$typeId)throw new RuntimeException('Incident Reports are not available.');
        $_SESSION['core_incident_old']=$old;
        $data=$old;$data['supporting']=$_POST['supporting']??[];// validate the original shape, not the safe values retained for redraw
        $id=TicketService::create($data+['ticket_type_id'=>$typeId,'submission_key'=>$key],true,$_FILES['attachments']??[]);
        unset($_SESSION['core_incident_old']);
        $q=db()->prepare('SELECT ticket_no FROM tickets WHERE id=?');$q->execute([$id]);$no=(string)$q->fetchColumn();
        flash('success','Incident report '.$no.' submitted. HR has been notified.');
        redirect('hr-request',['id'=>$id]);
    }catch(PDOException){
        error_log('Incident filing failed: PDOException');
        flash('error','The report could not be saved. Please try again.');redirect('incident-new');
    }catch(RuntimeException $error){
        // Rule and validation messages are written for the reporter. Anything else is logged and replaced by a generic line.
        flash('error',$error->getMessage());redirect('incident-new');
    }catch(Throwable $error){
        error_log('Incident filing failed: '.get_class($error));
        flash('error','The report could not be saved. Please try again.');redirect('incident-new');
    }
    exit;
}

/**
 * POST actions on the case page (I3). Each one goes straight to the TicketService method that enforces the rule (reader, handler, version);
 * nothing is decided from the form beyond the ids and text the method asks for. Always returns to the case.
 */
function incident_case_action(string $action):never
{
    $id=is_string($_POST['ticket_id']??null)&&ctype_digit($_POST['ticket_id'])?(int)$_POST['ticket_id']:0;
    $str=fn(string $k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';
    $int=fn(string $k)=>is_string($_POST[$k]??null)&&ctype_digit($_POST[$k])?(int)$_POST[$k]:0;
    try{
        if($id<=0)throw new RuntimeException('Ticket not found or access denied.');
        switch($action){
            case 'core_incident_pdf_retry':
                $ready=TicketService::incidentRetryPdf($id,$int('version'));
                flash($ready?'success':'error',$ready?'PDF preview is ready.':'The PDF preview is still unavailable. Your report and DOCX are saved. Ask your administrator to check the converter.');break;
            case 'core_incident_status':
                $_SESSION['core_incident_status'][$id]=['to'=>$str('to'),'remarks'=>$str('remarks'),'internal_note'=>$str('internal_note')];
                $to=$str('to');TicketService::incidentStatus($id,$int('version'),$to,$str('remarks'),$str('internal_note'));
                unset($_SESSION['core_incident_status'][$id]);
                flash('success',$to==='CANCELLED'?'The report was withdrawn.':'Status updated. Everyone on the case can see your remarks.');break;
            case 'core_incident_participant_add':
                TicketService::incidentAddParticipant($id,$int('user_id'),$str('role'));flash('success','Person included. They can now read this case.');break;
            case 'core_incident_participant_remove':
                TicketService::incidentRemoveParticipant($id,$int('user_id'));flash('success','Person removed. They can no longer read this case.');break;
            case 'core_incident_note':
                $_SESSION['core_incident_note'][$id]=$str('body');TicketService::comment($id,$str('body'),'STAFF_ONLY');unset($_SESSION['core_incident_note'][$id]);flash('success','Internal note saved. Only you can see it.');break;
            case 'core_incident_message':
                $_SESSION['core_ticket_comment'][$id]=$str('body');TicketService::comment($id,$str('body'),'ALL');unset($_SESSION['core_ticket_comment'][$id]);flash('success','Message sent.');break;
        }
    }catch(PDOException $error){// a PDOException is a RuntimeException: never show database text
        error_log('Incident case action failed: PDOException');
        flash('error','That could not be saved. Refresh the case and try again.');
    }catch(RuntimeException $error){
        flash('error',$error->getMessage());
    }catch(Throwable $error){
        error_log('Incident case action failed: '.get_class($error));
        flash('error','That could not be saved. Refresh the case and try again.');
    }
    redirect('hr-request',['id'=>$id]);
}

/** Authenticated, renewed per-case authorization on every private document response. */
function incident_document_response():never
{
    try{
        $id=is_string($_GET['id']??null)&&ctype_digit($_GET['id'])&&strlen($_GET['id'])<=10?(int)$_GET['id']:0;
        $format=is_string($_GET['format']??null)?$_GET['format']:'';
        if($id<1)throw new RuntimeException('Ticket not found or access denied.');
        $file=IncidentDocument::download($id,$format);$stream=@fopen($file['path'],'rb');
        if($stream===false)throw new RuntimeException('The incident document is unavailable. Please try again.');
        header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');
        header('Content-Type: '.($format==='pdf'?'application/pdf':'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: '.($format==='pdf'?'inline':'attachment').'; filename="'.$file['name'].'"');
        header('Content-Length: '.filesize($file['path']));fpassthru($stream);fclose($stream);exit;
    }catch(PDOException){error_log('Incident document access failed: PDOException');http_response_code(500);ticket_error_page('The document could not be opened. Please try again.');
    }catch(RuntimeException $error){http_response_code(403);ticket_error_page($error->getMessage());
    }catch(Throwable){error_log('Incident document access failed.');http_response_code(500);ticket_error_page('The document could not be opened. Please try again.');}
    exit;
}
