<?php
declare(strict_types=1);
require_once __DIR__.'/TicketService.php';
require_once __DIR__.'/TicketViews.php';
require_once __DIR__.'/TicketCoeViews.php';

/** COE pages: HR preview before Served, private download, and the HR-managed template. The Served action itself is the normal ticket action. */
function ticket_coe_handle(string $page): void
{
    $pages=['hr-coe-preview','hr-coe-download','hr-coe-template','hr-coe-template-file'];
    $action=(string)($_POST['action']??'');
    if(!in_array($page,$pages,true)&&!str_starts_with($action,'coe_'))return;
    need_db();if(!Auth::check())redirect('login');
    if(!TicketRepository::ready()||!TicketCoe::ready()){http_response_code(503);exit('Certificate of Employment requires migration 014.');}
    header('Cache-Control: no-store');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        try{
            if($action==='coe_template_upload'){
                $f=$_FILES['template']??null;
                if(!is_array($f)||($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name']))throw new RuntimeException('Choose a .docx file to upload.');
                TicketCoe::addTemplate($f['tmp_name'],(string)$f['name'],(string)($_POST['note']??''));flash('success','Template uploaded and set as the active version.');
            }elseif($action==='coe_template_activate'){
                TicketCoe::activate((int)($_POST['template_id']??0));flash('success','Template version activated.');
            }elseif($action==='coe_settings_save'){
                TicketCoe::saveSettings($_POST);flash('success','Signatory and company settings saved.');
            }else throw new RuntimeException('Unknown action.');
        }catch(Throwable $error){
            if($error instanceof PDOException)error_log('COE action database failure.');
            flash('error',$error instanceof PDOException?'The change could not be saved. Refresh and try again.':$error->getMessage());
        }
        redirect('hr-coe-template');
    }
    try{
        $id=(int)($_GET['id']??0);
        if($page==='hr-coe-download'||$page==='hr-coe-template-file'){
            $file=$page==='hr-coe-download'?TicketCoe::download($id):TicketCoe::templateFile($id);
            header('X-Content-Type-Options: nosniff');header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="'.$file['name'].'"');header('Content-Length: '.filesize($file['path']));readfile($file['path']);exit;
        }
        if($page==='hr-coe-preview')ticket_coe_preview_page(TicketCoe::preview($id,($_GET['include_salary']??'')==='1'));
        else{
            if(!TicketCoe::canManageTemplate())throw new RuntimeException('Only HR with COE template access can manage the template.');
            ticket_coe_template_page(TicketCoe::templates(),TicketCoe::settings(),TicketCoe::activeTemplate());
        }
    }catch(RuntimeException $error){http_response_code(403);ticket_error_page($error->getMessage());}
    exit;
}
