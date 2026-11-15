# MEGuard - installs MEGuard's own PHP 8 into the MEGuard\php folder
#
# The filter, the tools and (with IIS or a reverse proxy) the web interface then use this PHP,
# independent of any PHP the server already has (e.g. an old PHP 7 for legacy websites).
#
#   1. finds a PHP 8 zip (MEGuard folder or Downloads) - or downloads the latest PHP 8.3 x64
#      Thread Safe build from windows.php.net (checksum verified)
#   2. extracts it to <MEGuard>\php and writes php.ini with the needed extensions
#   3. checks that it starts (Visual C++ runtime)
#   4. unless -NoWebService: creates and starts the "MEGuard - web" task, which serves the web
#      interface on 127.0.0.1:<Port> for an Apache mod_proxy / IIS ARR reverse proxy
#      (tools\install-iis.cmd uses -NoWebService: IIS runs PHP itself via FastCGI)
#
# Run: tools\install-php.cmd [-ZipPath C:\Downloads\php-8.3.x-Win32-vs16-x64.zip] [-Port 8099] [-NoWebService] [-NoDownload]

param([string]$ZipPath = '', [int]$Port = 8099, [switch]$NoWebService, [switch]$NoDownload)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
function Say($t, $c = 'Gray') { Write-Host $t -ForegroundColor $c }

$me = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Say 'ERROR: run this as administrator.' Red; exit 1 }

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$dir  = Join-Path $root 'php'

# ---- 1. find (or download) the zip ---------------------------------------------------
if ($ZipPath -eq '') {
    $cands = @()
    foreach ($d in @($root, (Join-Path $env:USERPROFILE 'Downloads'), 'C:\Users\*\Downloads')) {
        $cands += Get-ChildItem -Path (Join-Path $d 'php-8*-Win32-*-x64.zip') -ErrorAction SilentlyContinue
    }
    $ZipPath = ($cands | Where-Object { $_.Name -notmatch 'debug|devel|test' } | Sort-Object Name -Descending | Select-Object -First 1).FullName
}
if ((-not $ZipPath) -and (-not $NoDownload)) {
    try {
        Say 'No PHP zip found locally - downloading the latest PHP 8.3 (x64, Thread Safe) from windows.php.net...'
        $rel = Invoke-RestMethod -Uri 'https://windows.php.net/downloads/releases/releases.json' -UseBasicParsing
        $b = $rel.'8.3'
        $key = @($b.PSObject.Properties.Name | Where-Object { $_ -match '^ts-vs1[67]-x64$' } | Sort-Object -Descending)[0]
        if (-not $key) { throw 'no x64 Thread Safe build listed' }
        $zipInfo = $b.$key.zip
        $ZipPath = Join-Path $root $zipInfo.path
        Invoke-WebRequest -Uri ('https://windows.php.net/downloads/releases/' + $zipInfo.path) -OutFile $ZipPath -UseBasicParsing
        if ($zipInfo.sha256) {
            $h = (Get-FileHash -Algorithm SHA256 $ZipPath).Hash
            if ($h -ne $zipInfo.sha256.ToUpper()) { Remove-Item $ZipPath -Force; throw 'checksum mismatch' }
        }
        Say "Downloaded: $($zipInfo.path) (PHP $($b.version))" Green
    } catch {
        Say "Download failed: $($_.Exception.Message)" Yellow
        $ZipPath = ''
    }
}
if (-not $ZipPath -or -not (Test-Path $ZipPath)) {
    Say 'ERROR: no PHP 8 zip.' Red
    Say 'Download it from https://windows.php.net/download ("VS16/VS17 x64 Thread Safe" zip), put it in the MEGuard folder and run this again.'
    exit 1
}
Say "PHP package: $ZipPath"

# ---- 2. extract and write php.ini ------------------------------------------------------
if (Test-Path $dir) {
    Stop-ScheduledTask -TaskName 'MEGuard - web' -ErrorAction SilentlyContinue
    Get-Process php, php-cgi -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$dir*" } | Stop-Process -Force
    Start-Sleep 1
}
New-Item -ItemType Directory -Force -Path $dir | Out-Null
Expand-Archive -Path $ZipPath -DestinationPath $dir -Force
$php = Join-Path $dir 'php.exe'
if (-not (Test-Path $php)) { Say 'ERROR: the zip contains no php.exe (you need the "Win32 ... x64" build, not the source code).' Red; exit 1 }

$ini = Get-Content -Raw (Join-Path $dir 'php.ini-production')
$ini = $ini -replace '(?m)^;\s*extension_dir\s*=\s*"ext"', 'extension_dir = "ext"'
foreach ($ext in @('pdo_sqlite', 'sqlite3', 'mbstring', 'intl', 'fileinfo')) {
    $ini = $ini -replace "(?m)^;\s*extension=$ext\s*$", "extension=$ext"
}
# MEGuard sets its own time zone from config.php; this only silences PHP's warning
$ini = $ini -replace '(?m)^;\s*date\.timezone\s*=.*$', 'date.timezone = UTC'
$ini = $ini -replace '(?m)^memory_limit\s*=.*$', 'memory_limit = 256M'
# IIS FastCGI: run as the application pool identity, no impersonation of the anonymous user
$ini += "`r`n; MEGuard (IIS FastCGI)`r`ncgi.force_redirect = 0`r`ncgi.fix_pathinfo = 1`r`nfastcgi.impersonate = 0`r`nmax_execution_time = 300`r`n"
Set-Content -Path (Join-Path $dir 'php.ini') -Value $ini -Encoding ASCII
Say 'php.ini written (pdo_sqlite, sqlite3, mbstring, intl).'

# ---- 3. start test ---------------------------------------------------------------------
$v = & $php -v 2>&1 | Out-String
if ($LASTEXITCODE -ne 0 -or $v -notmatch 'PHP 8') {
    Say 'ERROR: PHP does not start. The Visual C++ 2015-2022 runtime is probably missing.' Red
    Say 'Install https://aka.ms/vs/17/release/vc_redist.x64.exe and run this installer again.'
    Say $v
    exit 1
}
Say (($v -split "`n")[0]) Green
$mods = & $php -m | Out-String
foreach ($m in @('pdo_sqlite', 'mbstring')) { if ($mods -notmatch "(?m)^$m") { Say "ERROR: the $m extension is missing." Red; exit 1 } }

if ($NoWebService) { Say 'Done (no web service: the web server runs PHP itself).' Green; exit 0 }

# ---- 4. web interface service (for a reverse proxy) ------------------------------------------
$runner = Join-Path $root 'tools\web-run.cmd'
$a  = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument "/c `"$runner`" $Port" -WorkingDirectory $root
$t  = New-ScheduledTaskTrigger -AtStartup
$p  = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$st = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -StartWhenAvailable -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName 'MEGuard - web' -Action $a -Trigger $t -Principal $p -Settings $st `
    -Description "MEGuard web interface (own PHP) on 127.0.0.1:$Port" -Force | Out-Null
Start-ScheduledTask -TaskName 'MEGuard - web'
$ok = $false
for ($i = 0; $i -lt 15; $i++) { Start-Sleep 1; if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) { $ok = $true; break } }
if ($ok) { Say "The web interface runs on http://127.0.0.1:$Port/login.php (local to the server)" Green }
else { Say "The web interface did not start - see $root\data\logs\web.log" Red }

Say ''
Say 'Last step: let your web server forward /meguard/ to it. See INSTALL.md, "Web interface".' Yellow
