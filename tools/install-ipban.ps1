# MEGuard - install / update IPBan inside the MEGuard folder
#
# What it does:
#   1. downloads the latest IPBan release from GitHub (MIT licence, free)
#   2. if an older IPBan service runs (e.g. C:\Program Files\IPBan), stops and removes it
#      (the old folder is not deleted, only renamed to .old)
#   3. extracts to <MEGuard>\ipban  (an existing ipban.sqlite is kept)
#   4. configures: MailEnable SMTP/IMAP/POP log monitoring, whitelist, escalating ban times
#   5. starts it as a Windows service and makes sure its firewall rules are enabled
#      on ALL profiles
#
# Run: tools\install-ipban.cmd  (as administrator)
# Options: -Whitelist "1.2.3.4,10.0.0.0/8"   -MailEnableRoot "D:\Mail Enable"   -ZipPath local.zip

param(
    [string]$Whitelist = '',
    [string]$MailEnableRoot = 'C:\Program Files (x86)\Mail Enable',
    [string]$ZipPath = ''
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Say($t, $c = 'Gray') { Write-Host $t -ForegroundColor $c }

$me = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Say 'ERROR: run this as administrator.' Red; exit 1 }

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$dir  = Join-Path $root 'ipban'
$tmp  = Join-Path $env:TEMP ('meguard-ipban-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $tmp | Out-Null

# ---- 1. download -------------------------------------------------------------
if ($ZipPath -eq '') {
    Say 'Looking up the latest IPBan release...'
    $rel = Invoke-RestMethod -Uri 'https://api.github.com/repos/DigitalRuby/IPBan/releases/latest' -UseBasicParsing -Headers @{ 'User-Agent' = 'MEGuard-installer' }
    $asset = $rel.assets | Where-Object { $_.name -match '-Windows-x64' -and $_.name -like '*.zip' } | Select-Object -First 1
    if (-not $asset) { Say 'ERROR: no Windows x64 package in the release.' Red; exit 1 }
    Say ("Version: {0}  ({1})" -f $rel.tag_name, $asset.name)
    $ZipPath = Join-Path $tmp $asset.name
    Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $ZipPath -UseBasicParsing
}
if (-not (Test-Path $ZipPath)) { Say "ERROR: package not found: $ZipPath" Red; exit 1 }

# ---- 2. remove an older service ---------------------------------------------
$old = Get-CimInstance Win32_Service | Where-Object { $_.Name -ieq 'IPBan' -or $_.PathName -like '*DigitalRuby.IPBan*' }
foreach ($s in $old) {
    $exe = ($s.PathName -replace '"', '')
    $oldDir = Split-Path -Parent $exe
    Say "Older IPBan service: $($s.Name)  ($oldDir)" Yellow
    if ($s.State -eq 'Running') { Stop-Service -Name $s.Name -Force; Start-Sleep 2 }
    sc.exe delete $s.Name | Out-Null
    Start-Sleep 1
    if ($oldDir -and (Test-Path $oldDir) -and ($oldDir -ne $dir)) {
        $bak = $oldDir.TrimEnd('\') + '.old'
        if (-not (Test-Path $bak)) { Rename-Item -Path $oldDir -NewName (Split-Path -Leaf $bak) }
        Say "  old folder renamed to: $bak"
    }
}
# if it already runs under MEGuard (update), stop that too
Get-Process -Name 'DigitalRuby.IPBan' -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue

# ---- 3. extract ----------------------------------------------------------------
New-Item -ItemType Directory -Force -Path $dir | Out-Null
$keep = @('ipban.sqlite', 'ipban.override.config', 'nlog.config')
foreach ($k in $keep) { if (Test-Path (Join-Path $dir $k)) { Copy-Item (Join-Path $dir $k) $tmp -Force } }
Expand-Archive -Path $ZipPath -DestinationPath $dir -Force
foreach ($k in $keep) { if (Test-Path (Join-Path $tmp $k)) { Copy-Item (Join-Path $tmp $k) $dir -Force } }
$exePath = Join-Path $dir 'DigitalRuby.IPBan.exe'
if (-not (Test-Path $exePath)) { Say "ERROR: the package contains no DigitalRuby.IPBan.exe" Red; exit 1 }

# ---- 4. settings ----------------------------------------------------------------
$cfgPath = Join-Path $dir 'ipban.config'
if (-not (Test-Path $cfgPath)) { Say 'ERROR: no ipban.config in the package.' Red; exit 1 }
[xml]$cfg = Get-Content -Raw -Encoding UTF8 $cfgPath

function Set-Key($name, $value) {
    $n = $cfg.configuration.appSettings.add | Where-Object { $_.key -eq $name }
    if ($n) { $n.value = $value }
    else {
        $e = $cfg.CreateElement('add'); $e.SetAttribute('key', $name); $e.SetAttribute('value', $value)
        [void]$cfg.configuration.appSettings.AppendChild($e)
    }
}

# whitelist: localhost + the server's own IPv4 addresses + whatever you passed
$own = @('127.0.0.1', '::1') + (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -ne '127.0.0.1' } | ForEach-Object { $_.IPAddress })
$wl = ($own + ($Whitelist -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ })) | Select-Object -Unique
Set-Key 'Whitelist' ($wl -join ',')
Set-Key 'FailedLoginAttemptsBeforeBan' '5'
# escalating bans: 1 hour, then 1 day, then 7 days
Set-Key 'BanTime' '00:01:00:00,01:00:00:00,07:00:00:00'
Set-Key 'ResetFailedLoginCountForUnbannedIPAddresses' 'true'
Set-Key 'ExpireTime' '01:00:00:00'
Set-Key 'FirewallRulePrefix' 'IPBan_'

# MailEnable logs: replace the stock entries with our own tested patterns
$logFiles = $cfg.configuration.LogFilesToParse.LogFiles
if (-not $logFiles) { Say 'ERROR: unexpected ipban.config structure (no LogFilesToParse/LogFiles). Please open an issue and attach the file.' Red; exit 1 }
foreach ($lf in @($logFiles.LogFile)) { if ($lf.Source -like 'MailEnable*') { [void]$logFiles.RemoveChild($lf) } }

$log = (Join-Path $MailEnableRoot 'Logging') -replace '\\', '/'
$defs = @(
    @{ Source = 'MailEnable-SMTP'; Path = "$log/SMTP/SMTP-Activity-*.log";
       Regex = '(?<timestamp>\d\d/\d\d/\d\d\x20\d\d:\d\d:\d\d)\tSMTP-IN\t[^\t]*\t\d+\t(?<ipaddress>[0-9A-Fa-f:.]+)\tAUTH\t[^\t]*\t535[^\t]*\t[^\t]*\t[^\t]*\t(?<username>[^\t]*)' }
    @{ Source = 'MailEnable-IMAP'; Path = "$log/IMAP/IMAP-Activity-*.log";
       Regex = '(?<timestamp>\d\d/\d\d/\d\d\x20\d\d:\d\d:\d\d)\tIMAP-IN\t[^\t]*\t\d+\t(?<ipaddress>[0-9A-Fa-f:.]+)\t(?:LOGIN|AUTHENTICATE)\t(?:LOGIN\x20(?<username>\S+)[^\t]*|[^\t]*)\t[^\t]*\bNO\b[^\t]*[Ff]ailed' }
    @{ Source = 'MailEnable-POP'; Path = "$log/POP/POP-Activity-*.log";
       Regex = '(?<timestamp>\d\d/\d\d/\d\d\x20\d\d:\d\d:\d\d)\tPOPS?\t\d+\t(?<ipaddress>[0-9A-Fa-f:.]+)\t(?:PASS|APOP|AUTH)\t[^\t]*\t-ERR' }
)
foreach ($d in $defs) {
    $x = $cfg.CreateElement('LogFile')
    $parts = [ordered]@{
        Source = $d.Source; PathAndMask = $d.Path; Recursive = 'false';
        FailedLoginRegexTimestampFormat = 'MM/dd/yy HH:mm:ss'; PlatformRegex = 'Windows';
        PingInterval = '10000'; MaxFileSize = '33554432'; FailedLoginThreshold = '0'
    }
    foreach ($k in $parts.Keys) { $e = $cfg.CreateElement($k); $e.InnerText = $parts[$k]; [void]$x.AppendChild($e) }
    $r = $cfg.CreateElement('FailedLoginRegex'); [void]$r.AppendChild($cfg.CreateCDataSection($d.Regex))
    [void]$x.InsertAfter($r, $x.SelectSingleNode('PathAndMask'))
    [void]$logFiles.AppendChild($x)
}
$cfg.Save($cfgPath)
Say 'Settings saved (MailEnable SMTP/IMAP/POP monitoring, whitelist, escalating bans).'

# Detecting RDP attacks needs Windows logon auditing
# (set by subcategory GUID, so it works on any Windows display language)
auditpol.exe /set /subcategory:"{0CCE9215-69AE-11D9-BED3-505054503030}" /success:enable /failure:enable | Out-Null

# ---- 5. service -------------------------------------------------------------------
for ($i = 0; $i -lt 20 -and (Get-Service IPBan -ErrorAction SilentlyContinue); $i++) { Start-Sleep 1 }
New-Service -Name IPBan -BinaryPathName "`"$exePath`"" -DisplayName 'IPBan (MEGuard)' -StartupType Automatic `
    -Description 'MEGuard: blocks IP addresses that keep trying wrong passwords, on the Windows Firewall' | Out-Null
sc.exe config IPBan start= delayed-auto | Out-Null
sc.exe failure IPBan reset= 86400 actions= restart/60000/restart/60000/restart/60000 | Out-Null
Start-Service IPBan
Say 'IPBan service started. Waiting for its firewall rules...'

# the rules must be enabled and apply to every profile
$fixed = 0
for ($i = 0; $i -lt 12; $i++) {
    Start-Sleep 5
    $rules = Get-NetFirewallRule -DisplayName 'IPBan_*' -ErrorAction SilentlyContinue
    if ($rules) {
        $rules | Where-Object { $_.Enabled -ne 'True' -or $_.Profile -ne 'Any' } | ForEach-Object { Set-NetFirewallRule -InputObject $_ -Enabled True -Profile Any; $fixed++ }
        break
    }
}
# guard task: every 15 minutes it checks that the IPBan firewall rules are enabled on every profile
$guard = Join-Path $root 'tools\ipban-guard.ps1'
$a = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$guard`""
$t = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 15)
$p = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$st = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -StartWhenAvailable
Register-ScheduledTask -TaskName 'MEGuard - IPBan firewall guard' -Action $a -Trigger $t -Principal $p -Settings $st -Force | Out-Null
Say 'Guard task created: MEGuard - IPBan firewall guard (every 15 minutes).'

$svc = Get-Service IPBan
Say ''
Say ("Service: {0}" -f $svc.Status) $(if ($svc.Status -eq 'Running') { 'Green' } else { 'Red' })
Get-NetFirewallRule -DisplayName 'IPBan_*' -ErrorAction SilentlyContinue | Format-Table DisplayName, Enabled, Profile, Direction, Action -AutoSize
if ($fixed) { Say "$fixed firewall rule(s) fixed (enabled / all profiles)." Yellow }
Say "Done. Log: $dir\logfile.txt" Green
Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
