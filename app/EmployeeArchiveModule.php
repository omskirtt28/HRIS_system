<?php
declare(strict_types=1);
require_once __DIR__.'/EmployeeArchiveService.php';
require_once __DIR__.'/EmployeeArchiveViews.php';

function employee_archive_handle(string $page): void
{
    $action=(string)($_POST['action'] ?? '');
    $actions=['employee_set_inactive','employee_terminate','employee_restore'];
    if($page!=='hr-employee-archive' && !in_array($action,$actions,true)) return;
    need_db();
    if($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,$actions,true)) {
        verify_csrf(); Auth::requirePermission('employees.manage');
        $id=(int)($_POST['employee_id'] ?? 0);
        try {
            if($id<1) throw new RuntimeException('Employee record not found.');
            if($action==='employee_restore') {
                $result=EmployeeArchiveService::restore($id,(string)($_POST['effective_date'] ?? ''),(string)($_POST['remarks'] ?? ''),!empty($_POST['reactivate_account']));
                flash('success',$result['name'].' was restored to the active employee list.');
                redirect('hr-employee',['id'=>$id,'tab'=>'employment']);
            }
            if($action==='employee_terminate' && empty($_POST['confirm'])) throw new RuntimeException('Tick the confirmation box to mark this employee as Terminated.');
            $result=EmployeeArchiveService::archive($id,$action==='employee_terminate'?'TERMINATED':'INACTIVE',(string)($_POST['effective_date'] ?? ''),(string)($_POST['remarks'] ?? ''));
            flash('success',$result['name'].' was '.($result['status']==='TERMINATED'?'terminated':'set to inactive').' and moved to the Employee Archive.'.($result['account_deactivated']?' Their login account was deactivated.':''));
            redirect('hr-employee-archive');
        } catch(Throwable $error) {
            if($error instanceof PDOException) error_log('Employee archive action failed: '.$error->getMessage());
            flash('error',$error instanceof RuntimeException ? $error->getMessage() : 'The change could not be saved. Check the employee record and try again.');
            redirect($id>0?'hr-employee':'hr-employee-archive',$id>0?['id'=>$id,'tab'=>'employment']:[]);
        }
    }
    Auth::requirePermission('employees.view_all');
    employee_archive_page([
        'rows'=>EmployeeArchiveService::archived($_GET),'counts'=>EmployeeArchiveService::counts(),
        'status'=>strtoupper(trim((string)($_GET['status'] ?? ''))),'q'=>trim((string)($_GET['q'] ?? '')),
    ]);
    exit;
}
