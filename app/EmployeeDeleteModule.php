<?php
declare(strict_types=1);
require_once __DIR__.'/EmployeeDeleteService.php';
require_once __DIR__.'/EmployeeDeleteViews.php';

function employee_delete_handle(string $page): void
{
    $action=(string)($_POST['action'] ?? '');
    $pages=['hr-employee-delete','hr-employee-delete-files'];
    $actions=['employee_delete','employee_delete_retry_files'];
    if(!in_array($page,$pages,true) && !in_array($action,$actions,true)) return;
    need_db(); EmployeeDeleteService::requireAccess();
    $id=(int)($_SERVER['REQUEST_METHOD']==='POST' ? ($_POST['id'] ?? 0) : ($_GET['id'] ?? 0));
    if($_SERVER['REQUEST_METHOD']==='POST') {
        verify_csrf();
        try {
            if($page==='hr-employee-delete' && $action==='employee_delete' && $id>0) {
                $_SESSION['employee_delete_form'][$id]=[
                    'reason'=>mb_substr((string)($_POST['reason'] ?? ''),0,500),
                    'confirmation'=>mb_substr((string)($_POST['confirmation'] ?? ''),0,500),
                ];
                $result=EmployeeDeleteService::delete($id,$_POST);
                flash('success',$result['name'].' was deleted from employee records. User accounts were kept.');
                if($result['files_pending']) {
                    flash('error','The employee database records were deleted. Some uploaded files still need cleanup. You can retry below.');
                    redirect('hr-employee-delete-files');
                }
                redirect('hr-employees',['group'=>'ALL']);
            }
            if($page==='hr-employee-delete-files' && $action==='employee_delete_retry_files') {
                $count=EmployeeDeleteService::cleanFiles();
                audit('Employees','RETRY_DELETED_EMPLOYEE_FILES','employee_file_cleanup',null,['files_removed'=>$count]);
                flash('success',number_format($count).' uploaded files cleaned up.');
                redirect('hr-employee-delete-files');
            }
            throw new RuntimeException('Open the employee review page before deleting.');
        } catch(Throwable $error) {
            error_log('Employee deletion action failed: '.get_class($error));
            $message=$error instanceof RuntimeException && !($error instanceof PDOException)
                ? $error->getMessage() : 'The operation could not finish. Check the employee record before trying again.';
            flash('error',$message);
            if($page==='hr-employee-delete' && $id>0) redirect('hr-employee-delete',['id'=>$id]);
            redirect('hr-employee-delete-files');
        }
    }
    if($page==='hr-employee-delete-files') {
        employee_delete_files_page(); exit;
    }
    if($id<1 || !EmployeeRepository::find($id)) {
        flash('error','Employee record not found.'); redirect('hr-employees',['group'=>'ALL']);
    }
    if(!EmployeeDeleteService::ready()) { employee_delete_setup_page($id); exit; }
    try { $data=EmployeeDeleteService::preview($id); }
    catch(Throwable $error) {
        error_log('Employee deletion preview failed: '.get_class($error));
        flash('error',$error instanceof RuntimeException && !($error instanceof PDOException) ? $error->getMessage() : 'The employee links could not be checked. No employee was deleted.');
        redirect('hr-employee',['id'=>$id]);
    }
    employee_delete_review_page($data); exit;
}
