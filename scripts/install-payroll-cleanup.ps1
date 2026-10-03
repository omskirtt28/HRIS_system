param(
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$TaskName = 'PMBSI HRIS Biometric Upload Cleanup'
)
$ErrorActionPreference = 'Stop'
$hrisWorkspace = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$hrisCleanupScript = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot 'payroll_cleanup.php')).Path
$hrisPhpExecutable = (Resolve-Path -LiteralPath $PhpPath).Path
$hrisAction = New-ScheduledTaskAction -Execute $hrisPhpExecutable -Argument ('"' + $hrisCleanupScript + '"') -WorkingDirectory $hrisWorkspace
$hrisTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(5) -RepetitionInterval (New-TimeSpan -Hours 1)
$hrisSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
Register-ScheduledTask -TaskName $TaskName -Action $hrisAction -Trigger $hrisTrigger -Settings $hrisSettings -Description 'Deletes expired biometric upload files. Attendance and payroll records are retained.' -Force | Out-Null
Write-Output ('Hourly biometric file cleanup registered for ' + $hrisWorkspace)
