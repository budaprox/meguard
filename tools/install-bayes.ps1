# MEGuard - SpamAssassin Bayes training: scheduled task ("MEGuard - Bayes", daily at 02:50, SYSTEM)
# and a lower scoring threshold in local.cf (Bayes scores after 100 spam / 100 ham instead of 200/200).
$ErrorActionPreference = 'Stop'
$me = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Write-Host 'ERROR: run this as administrator.' -ForegroundColor Red; exit 1 }
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$php = Join-Path $root 'php\php.exe'
if (-not (Test-Path $php)) { $php = 'C:\Xampp\php\php.exe' }
if (-not (Test-Path $php)) { $c = Get-Command php.exe -ErrorAction SilentlyContinue; if ($c) { $php = $c.Source } }
if (-not (Test-Path $php)) { Write-Host "ERROR: php.exe not found ($php). Install MEGuard's own PHP first: tools\install-php.cmd" -ForegroundColor Red; exit 1 }

# 1. local.cf: Bayes scoring after 100/100 learned messages (default 200/200)
$cf = Join-Path $root 'spamassassin\etc\spamassassin\local.cf'
if (Test-Path $cf) {
    $txt = Get-Content $cf -Raw
    $add = @()
    if ($txt -notmatch '(?m)^\s*bayes_min_spam_num') { $add += 'bayes_min_spam_num 100' }
    if ($txt -notmatch '(?m)^\s*bayes_min_ham_num')  { $add += 'bayes_min_ham_num 100' }
    if ($add.Count) {
        Add-Content -Path $cf -Value ("`r`n# MEGuard: Bayes scoring after 100 spam / 100 ham`r`n" + ($add -join "`r`n"))
        Write-Host "local.cf updated: $($add -join ', ')" -ForegroundColor Green
        Write-Host 'IMPORTANT: restart SpamAssassin (MEGuard -> Tools -> Resources) to apply it.' -ForegroundColor Yellow
    } else { Write-Host 'local.cf: the Bayes settings are already there.' }
} else { Write-Host "WARNING: not found: $cf" -ForegroundColor Yellow }

# 2. scheduled task
$script = Join-Path $root 'tools\bayes-learn.php'
$task = 'MEGuard - Bayes'
$a = New-ScheduledTaskAction -Execute $php -Argument "-f `"$script`"" -WorkingDirectory $root
$t = New-ScheduledTaskTrigger -Daily -At '02:50'
$p = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$s = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Hours 2) -StartWhenAvailable -MultipleInstances IgnoreNew -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName $task -Action $a -Trigger $t -Principal $p -Settings $s `
    -Description 'MEGuard: SpamAssassin Bayes training (quarantine, Junk folders, released messages)' -Force | Out-Null
Write-Host "Task created: $task (daily at 02:50)" -ForegroundColor Green

# 3. first training run now, in the background (as SYSTEM, into the same database spamd uses)
Start-ScheduledTask -TaskName $task
Write-Host 'The first training run has started in the background (a few minutes, depending on the Junk folders).' -ForegroundColor Green
Write-Host "Log: $root\data\logs\bayes.log" -ForegroundColor Gray
