<?php
declare(strict_types=1);

require_once __DIR__.'/EmployeeRosterParser.php';
require_once __DIR__.'/EmployeeRosterImportService.php';
require_once __DIR__.'/EmployeeRosterImportViews.php';

function employee_roster_handle(string $page): void
{
    $action = (string)($_POST['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'hr-employee-import' && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        need_db(); EmployeeRosterImportService::authorize();
        flash('error','The file is larger than the server upload limit. Choose a smaller file or ask your administrator to raise the PHP upload limit.');
        redirect('hr-employee-import');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with($action,'employee_roster_')) {
        need_db(); EmployeeRosterImportService::authorize(); verify_csrf();
        $id = (int)($_POST['import_id'] ?? 0); $version = (int)($_POST['version'] ?? 0);
        $params = $id > 0 ? ['id'=>$id] : [];
        try {
            switch ($action) {
                case 'employee_roster_upload':
                    $id = EmployeeRosterImportService::stage($_FILES['roster_file'] ?? []);
                    flash('success','Excel details filled in. Review the employees, then import. Missing details can be finished in Employee 201.');
                    redirect('hr-employee-import',['id'=>$id]);
                case 'employee_roster_matches':
                    EmployeeRosterImportService::saveMapping($id,$version,(array)($_POST['matches'] ?? []));
                    flash('success','Matches saved. Review the employees below.');
                    break;
                case 'employee_roster_row':
                    EmployeeRosterImportService::saveRow($id,(int)($_POST['row_id'] ?? 0),$version,$_POST);
                    flash('success','Row saved. Review and import; missing details can be finished in Employee 201.');
                    break;
                case 'employee_roster_commit':
                    $result = EmployeeRosterImportService::commit($id,$version,(string)($_POST['review_token'] ?? ''),!empty($_POST['review_confirmed']),!empty($_POST['include_updates']));
                    flash('success',$result['created'].' employees added, '.$result['updated'].' updated. '.$result['needs_details'].' marked Needs Details for Employee 201. '.$result['remaining'].' rows remain in the preview.');
                    $params['tab'] = 'IMPORTED';
                    break;
                default: throw new RuntimeException('This roster action is not available.');
            }
        } catch (Throwable $e) {
            if ($e instanceof PDOException || $e instanceof JsonException) {
                error_log('Employee roster action failed: '.$e->getCode());
                flash('error','The roster could not be saved. Check that the SQL patch was imported, then reload and try again.');
            } else flash('error',$e->getMessage());
            if ($action === 'employee_roster_matches') {
                $params['step'] = 'matches';
                $_SESSION['roster_failed_form'] = ['import_id'=>$id,'matches'=>(array)($_POST['matches'] ?? [])];
            }
            if ($action === 'employee_roster_row') {
                $params['row_id'] = (int)($_POST['row_id'] ?? 0);
                $kept = ['import_id'=>$id,'row_id'=>$params['row_id']];
                foreach (['employee_no','first_name','middle_name','last_name','suffix','hire_date','row_action','override_department','override_position','override_site','override_brand','override_employer','assignment_checked','formula_checked','same_person'] as $field) $kept[$field] = (string)($_POST[$field] ?? '');
                $_SESSION['roster_failed_form'] = $kept;
            }
        }
        redirect('hr-employee-import',$params);
    }
    if ($page !== 'hr-employee-import') return;
    need_db(); EmployeeRosterImportService::authorize();
    employee_roster_page();
    exit;
}
