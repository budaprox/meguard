# MEGuard - daily backup ("MEGuard - backup", daily at 01:20, SYSTEM): database snapshot + settings in a ZIP
$ErrorActionPreference = 'Stop'
$me = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Write-Host 'ERROR: run this as administrator.' -ForegroundColor Red; exit 1 }
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$php = Join-Path $root 'php\php.exe'
if (-not (Test-Path $php)) { $php = 'C:\Xampp\php\php.exe' }
if (-not (Test-Path $php)) { $c = Get-Command php.exe -ErrorAction SilentlyContinue; if ($c) { $php = $c.Source } }
if (-not (Test-Path $php)) { Write-Host "ERROR: php.exe not found ($php). Install MEGuard's own PHP first: tools\install-php.cmd" -ForegroundColor Red; exit 1 }
$script = Join-Path $root 'tools\backup.php'
$task = 'MEGuard - backup'
$a = New-ScheduledTaskAction -Execute $php -Argument "-f `"$script`"" -WorkingDirectory $root
$t = New-ScheduledTaskTrigger -Daily -At '01:20'
$p = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$s = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -StartWhenAvailable -MultipleInstances IgnoreNew -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName $task -Action $a -Trigger $t -Principal $p -Settings $s `
    -Description 'MEGuard: daily backup (database, config.php, IPBan, SpamAssassin, Bayes) to the backup folder' -Force | Out-Null
Write-Host "Task created: $task (daily at 01:20)" -ForegroundColor Green
Write-Host 'Test run now:' -ForegroundColor Gray
& $php -f $script
