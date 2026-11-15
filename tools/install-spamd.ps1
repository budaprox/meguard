# MEGuard - sets up SpamAssassin (spamd) in Task Scheduler
# Creates two tasks:
#   "MEGuard - SpamAssassin spamd"   starts at boot, restarted every minute on failure
#   "MEGuard - SpamAssassin update"  updates the rules daily at 04:10 and restarts spamd
# Run: tools\install-spamd.cmd (as administrator)

param([int]$Port = 784, [int]$Children = 3)
$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'ERROR: run this as administrator (right-click -> Run as administrator).' -ForegroundColor Red; exit 1
}

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$sa   = Join-Path $root 'spamassassin'
$spamd = Join-Path $sa 'spamd.exe'
# ---- no SpamAssassin yet: find the downloaded JAM zip and extract it ----
if (-not (Test-Path $spamd)) {
    $zips = @()
    foreach ($d in @($root, (Join-Path $env:USERPROFILE 'Downloads'), 'C:\Users\*\Downloads')) {
        $zips += Get-ChildItem -Path (Join-Path $d 'SpamAssassinForWindows*.zip') -ErrorAction SilentlyContinue
    }
    $zip = $zips | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if (-not $zip) {
        Write-Host 'The SpamAssassin for Windows zip was not found.' -ForegroundColor Red
        Write-Host 'Download it (free, the "zip" edition), put it in the MEGuard folder or in Downloads, and run this again:'
        Write-Host '   https://www.jam-software.com/spamassassin'
        Start-Process 'https://www.jam-software.com/spamassassin' -ErrorAction SilentlyContinue
        exit 1
    }
    Write-Host "SpamAssassin package: $($zip.FullName)"
    $tmpx = Join-Path $env:TEMP ('meguard-sa-' + [guid]::NewGuid().ToString('N'))
    Expand-Archive -Path $zip.FullName -DestinationPath $tmpx -Force
    $found = Get-ChildItem -Path $tmpx -Recurse -Filter 'spamd.exe' | Select-Object -First 1
    if (-not $found) { Write-Host 'ERROR: the zip contains no spamd.exe.' -ForegroundColor Red; exit 1 }
    New-Item -ItemType Directory -Force -Path $sa | Out-Null
    # the contents of the spamd.exe folder go straight into the spamassassin folder (no subfolder)
    Copy-Item -Path (Join-Path $found.DirectoryName '*') -Destination $sa -Recurse -Force
    Remove-Item -Recurse -Force $tmpx -ErrorAction SilentlyContinue
    Write-Host "Extracted to: $sa" -ForegroundColor Green
}
if (-not (Test-Path $spamd)) { Write-Host "ERROR: not found: $spamd" -ForegroundColor Red; exit 1 }

# ---- rules: download them now if they are not there yet ----
$hasRules = Get-ChildItem -Path (Join-Path $sa 'share') -Recurse -Directory -Filter 'updates_spamassassin_org' -ErrorAction SilentlyContinue
if (-not $hasRules) {
    Write-Host 'Downloading SpamAssassin rules (sa-update)...'
    $gpg = Get-Command gpg.exe -ErrorAction SilentlyContinue
    $upArgs = if ($gpg) { @('-v') } else { @('-v', '--no-gpg') }
    Push-Location $sa
    & (Join-Path $sa 'sa-update.exe') @upArgs
    $rc = $LASTEXITCODE
    Pop-Location
    if ($rc -gt 1) { Write-Host "WARNING: sa-update returned an error ($rc). spamd will start with the built-in rules." -ForegroundColor Yellow }
    else { Write-Host 'Rules OK.' -ForegroundColor Green }
}

$taskRun = 'MEGuard - SpamAssassin spamd'
$taskUpd = 'MEGuard - SpamAssassin update'

# a spamd started by hand must be stopped (otherwise the port is taken)
$busy = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if ($busy) {
    $p = Get-Process -Id $busy[0].OwningProcess -ErrorAction SilentlyContinue
    Write-Host "Port $Port is already used by $($p.ProcessName) (PID $($p.Id))." -ForegroundColor Yellow
    if ($p.ProcessName -like 'spamd*') {
        Write-Host 'Stopping it (Task Scheduler will restart it).'
        Stop-ScheduledTask -TaskName 'MEGuard - SpamAssassin spamd' -ErrorAction SilentlyContinue
        Get-Process spamd -ErrorAction SilentlyContinue | Stop-Process -Force
        Start-Sleep 2
    } else {
        Write-Host 'Choose another port: install-spamd.cmd -Port 785' -ForegroundColor Red; exit 1
    }
}

# 1) spamd
# spamd is started by the watchdog script: if it stops, it is restarted after 10 s and the reason is logged
$runner = Join-Path $root 'tools\spamd-run.cmd'
$action   = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument "/c `"$runner`" $Port $Children" -WorkingDirectory $sa
$trigger  = New-ScheduledTaskTrigger -AtStartup
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 `
            -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
            -MultipleInstances IgnoreNew
$runAs    = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
Register-ScheduledTask -TaskName $taskRun -Action $action -Trigger $trigger -Settings $settings -Principal $runAs `
    -Description "MEGuard: SpamAssassin daemon on 127.0.0.1:$Port" -Force | Out-Null
Write-Host "Task created: $taskRun"

# 2) daily rule update
$upd = Join-Path $root 'tools\sa-update.cmd'
$action2  = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument "/c `"$upd`"" -WorkingDirectory $sa
$trigger2 = New-ScheduledTaskTrigger -Daily -At '04:10'
$settings2 = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -StartWhenAvailable
Register-ScheduledTask -TaskName $taskUpd -Action $action2 -Trigger $trigger2 -Settings $settings2 -Principal $runAs `
    -Description 'MEGuard: daily SpamAssassin rule update' -Force | Out-Null
Write-Host "Task created: $taskUpd"

# start now
Start-ScheduledTask -TaskName $taskRun
Write-Host 'Starting spamd...'
$ok = $false
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep 1
    if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) { $ok = $true; break }
}
if ($ok) { Write-Host "Done: spamd is running on 127.0.0.1:$Port." -ForegroundColor Green }
else { Write-Host 'spamd did not start within 30 seconds. Check the task history in Task Scheduler and data\logs\spamd.log.' -ForegroundColor Red }
