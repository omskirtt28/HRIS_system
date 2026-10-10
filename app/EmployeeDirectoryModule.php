<?php
declare(strict_types=1);
require_once __DIR__.'/EmployeeDirectoryHierarchy.php';
require_once __DIR__.'/EmployeeDirectoryService.php';
require_once __DIR__.'/EmployeeDirectoryViews.php';

function employee_directory_render(): never
{
    Auth::requirePermission('employees.view_all');
    employee_directory_grouped(EmployeeDirectoryService::page($_GET));
    exit;
}
