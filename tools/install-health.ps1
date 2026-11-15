# MEGuard - creates the health watchdog task ("MEGuard - health")
# Every 3 minutes it checks SpamAssassin and the mail services and restarts them if needed.
$ErrorActionPreference = 'Stop'
$me = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Write-Host 'ERROR: run this as administrator.' -ForegroundColor Red; exit 1 }
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$php = Join-Path $root 'php\php.exe'
if (-not (Test-Path $php)) { $php = 'C:\Xampp\php\php.exe' }
if (-not (Test-Path $php)) { $c = Get-Command php.exe -ErrorAction SilentlyContinue; if ($c) { $php = $c.Source } }
if (-not (Test-Path $php)) { Write-Host "ERROR: php.exe not found ($php). Install MEGuard's own PHP first: tools\install-php.cmd" -ForegroundColor Red; exit 1 }
$script = Join-Path $root 'tools\health.php'
$task = 'MEGuard - health'
$a = New-ScheduledTaskAction -Execute $php -Argument "-f `"$script`"" -WorkingDirectory $root
$t = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 3) -RepetitionDuration ([TimeSpan]::FromDays(3650))
$t2 = New-ScheduledTaskTrigger -AtStartup
$p = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$s = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -StartWhenAvailable -MultipleInstances IgnoreNew -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName $task -Action $a -Trigger @($t, $t2) -Principal $p -Settings $s `
    -Description 'MEGuard: health watchdog (SpamAssassin, MailEnable, IPBan) every 3 minutes' -Force | Out-Null
Write-Host "Task created: $task (every 3 minutes)" -ForegroundColor Green
Write-Host 'Test run:' -ForegroundColor Gray
& $php -f $script
Write-Host 'Done. Log: data\logs\health.log' -ForegroundColor Green
