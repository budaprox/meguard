# MEGuard - publishes the web interface on IIS (e.g. next to MailEnable's own web mail)
#
# Uses only built-in Windows components (IIS + the CGI/FastCGI role service) - no URL Rewrite, no ARR.
#   1. makes sure MEGuard's own PHP is installed (tools\install-php.ps1 -NoWebService)
#   2. enables the IIS "CGI" role service (FastCGI) if it is missing
#   3. registers MEGuard\php\php-cgi.exe as a FastCGI application (PHPRC = MEGuard\php)
#   4. creates the "MEGuard" application pool (no managed code, identity LocalSystem - see below)
#   5. creates the /meguard application in the chosen site, with a *.php handler that only
#      applies to this application, index.php as default document and anonymous access
#      running as the pool identity
#   6. requests the login page to check that it works
#
# Why LocalSystem: the Tools page restarts MailEnable services and SpamAssassin, and the web
# interface reads the MailEnable queues and logs. That is the same account XAMPP's Apache uses
# when it runs as a service. Use -Identity ApplicationPoolIdentity if you prefer a restricted pool:
# then grant it Modify on MEGuard\data and read access to the MailEnable folder; the restart
# buttons will report "access denied".
#
# Run (as administrator):
#   tools\install-iis.cmd                       the site is chosen automatically (the one with an https binding)
#   tools\install-iis.cmd -Site "Default Web Site"
#   tools\install-iis.cmd -Path meguard -Identity ApplicationPoolIdentity
#   tools\install-iis.cmd -Uninstall            removes the application, the pool and the handler

param([string]$Site = '', [string]$Path = 'meguard', [string]$Identity = 'LocalSystem', [switch]$Uninstall)
$ErrorActionPreference = 'Stop'
function Say($t, $c = 'Gray') { Write-Host $t -ForegroundColor $c }

$me = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Say 'ERROR: run this as administrator.' Red; exit 1 }

$root   = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$web    = Join-Path $root 'web'
$phpDir = Join-Path $root 'php'
$cgi    = Join-Path $phpDir 'php-cgi.exe'
$pool   = 'MEGuard'
$hName  = 'MEGuard-PHP'
$apphost = 'MACHINE/WEBROOT/APPHOST'

if (-not (Get-Service W3SVC -ErrorAction SilentlyContinue)) {
    Say 'ERROR: IIS (World Wide Web Publishing Service) is not installed on this server.' Red
    Say 'This script is for servers that already use IIS. With Apache, follow INSTALL.md instead.'
    exit 1
}

# ---- IIS "CGI" role service (contains the FastCGI module) -------------------------------
function Enable-Cgi {
    if (Get-Command Get-WindowsFeature -ErrorAction SilentlyContinue) {          # Windows Server
        $f = Get-WindowsFeature Web-CGI
        if (-not $f.Installed) { Say 'Enabling the IIS CGI role service...'; Install-WindowsFeature Web-CGI | Out-Null }
    } else {                                                                     # Windows client
        $f = Get-WindowsOptionalFeature -Online -FeatureName IIS-CGI
        if ($f.State -ne 'Enabled') { Say 'Enabling the IIS CGI feature...'; Enable-WindowsOptionalFeature -Online -FeatureName IIS-CGI -All -NoRestart | Out-Null }
    }
}

Import-Module WebAdministration

# ---- the site ---------------------------------------------------------------------------
if ($Site -eq '') {
    $sites = @(Get-Website)
    $https = @($sites | Where-Object { $_.bindings.Collection | Where-Object { $_.protocol -eq 'https' } })
    if ($https.Count -eq 1)     { $Site = $https[0].Name }
    elseif ($sites.Count -eq 1) { $Site = $sites[0].Name }
    else {
        Say 'Several IIS sites - choose one with -Site "<name>":' Yellow
        $sites | ForEach-Object { Say ("  {0,-30} {1}" -f $_.Name, (($_.bindings.Collection | ForEach-Object { $_.protocol + ' ' + $_.bindingInformation }) -join ', ')) }
        exit 1
    }
}
if (-not (Get-Website -Name $Site)) { Say "ERROR: no IIS site called `"$Site`"." Red; exit 1 }
$loc = "$Site/$Path"
Say "IIS site: $Site  ->  /$Path"

# ---- uninstall ---------------------------------------------------------------------------
if ($Uninstall) {
    if (Get-WebApplication -Site $Site -Name $Path) { Remove-WebApplication -Site $Site -Name $Path; Say "Application /$Path removed." }
    Clear-WebConfiguration -PSPath $apphost -Location $loc -Filter 'system.webServer/handlers' -ErrorAction SilentlyContinue
    if (Test-Path "IIS:\AppPools\$pool") { Remove-WebAppPool -Name $pool; Say "Application pool $pool removed." }
    $fc = Get-WebConfiguration -PSPath $apphost -Filter "system.webServer/fastCgi/application[@fullPath='$cgi']"
    if ($fc) { Clear-WebConfiguration -PSPath $apphost -Filter "system.webServer/fastCgi/application[@fullPath='$cgi']"; Say 'FastCGI registration removed.' }
    Say 'Done. MEGuard itself (filter, tasks, data) is untouched.' Green
    exit 0
}

# ---- 1. PHP --------------------------------------------------------------------------------
if (-not (Test-Path $cgi)) {
    Say 'MEGuard has no own PHP yet - installing it (tools\install-php.ps1 -NoWebService)...'
    & (Join-Path $root 'tools\install-php.ps1') -NoWebService
    if (-not (Test-Path $cgi)) { Say "ERROR: $cgi is still missing." Red; exit 1 }
}

# ---- 2. CGI role service -----------------------------------------------------------------------
Enable-Cgi

# ---- 3. FastCGI application ----------------------------------------------------------------------
$fcFilter = "system.webServer/fastCgi/application[@fullPath='$cgi']"
if (-not (Get-WebConfiguration -PSPath $apphost -Filter $fcFilter)) {
    Add-WebConfiguration -PSPath $apphost -Filter 'system.webServer/fastCgi' -Value @{
        fullPath = $cgi; maxInstances = 4; instanceMaxRequests = 10000; activityTimeout = 600; requestTimeout = 600 }
    Add-WebConfiguration -PSPath $apphost -Filter "$fcFilter/environmentVariables" -Value @{ name = 'PHPRC'; value = $phpDir }
    Add-WebConfiguration -PSPath $apphost -Filter "$fcFilter/environmentVariables" -Value @{ name = 'PHP_FCGI_MAX_REQUESTS'; value = '10000' }
    Say "FastCGI application registered: $cgi"
} else { Say 'FastCGI application already registered.' }

# ---- 4. application pool ------------------------------------------------------------------------
if (-not (Test-Path "IIS:\AppPools\$pool")) { New-WebAppPool -Name $pool | Out-Null; Say "Application pool created: $pool" }
Set-ItemProperty "IIS:\AppPools\$pool" -Name managedRuntimeVersion -Value ''
Set-ItemProperty "IIS:\AppPools\$pool" -Name processModel.identityType -Value $Identity
Set-ItemProperty "IIS:\AppPools\$pool" -Name processModel.idleTimeout -Value ([TimeSpan]::FromMinutes(60))
Say "Pool identity: $Identity"

# ---- 5. application, handler, default document, anonymous access ------------------------------
if (Get-WebApplication -Site $Site -Name $Path) {
    Set-ItemProperty "IIS:\Sites\$Site\$Path" -Name physicalPath -Value $web
    Set-ItemProperty "IIS:\Sites\$Site\$Path" -Name applicationPool -Value $pool
    Say "Application /$Path updated."
} else {
    New-WebApplication -Site $Site -Name $Path -PhysicalPath $web -ApplicationPool $pool | Out-Null
    Say "Application /$Path created -> $web"
}
Remove-WebConfigurationProperty -PSPath $apphost -Location $loc -Filter 'system.webServer/handlers' -Name '.' -AtElement @{ name = $hName } -ErrorAction SilentlyContinue
Add-WebConfigurationProperty -PSPath $apphost -Location $loc -Filter 'system.webServer/handlers' -Name '.' -Value @{
    name = $hName; path = '*.php'; verb = 'GET,HEAD,POST'; modules = 'FastCgiModule'; scriptProcessor = $cgi; resourceType = 'Either' }
$docs = @(Get-WebConfigurationProperty -PSPath $apphost -Location $loc -Filter 'system.webServer/defaultDocument/files' -Name 'Collection' | ForEach-Object { $_.value })
if ($docs -notcontains 'index.php') {
    Add-WebConfigurationProperty -PSPath $apphost -Location $loc -Filter 'system.webServer/defaultDocument/files' -Name '.' -AtIndex 0 -Value @{ value = 'index.php' }
}
Set-WebConfigurationProperty -PSPath $apphost -Location $loc -Filter 'system.webServer/security/authentication/anonymousAuthentication' -Name enabled -Value $true
Set-WebConfigurationProperty -PSPath $apphost -Location $loc -Filter 'system.webServer/security/authentication/anonymousAuthentication' -Name userName -Value ''
Say 'Handler, default document and anonymous access set (this application only).'

# ---- 6. test ----------------------------------------------------------------------------------------
Restart-WebAppPool -Name $pool -ErrorAction SilentlyContinue
$b = (Get-Website -Name $Site).bindings.Collection
$h = $b | Where-Object { $_.protocol -eq 'http' } | Select-Object -First 1
$s = $b | Where-Object { $_.protocol -eq 'https' } | Select-Object -First 1
$pick = if ($h) { $h } else { $s }
$port = ($pick.bindingInformation -split ':')[1]
$url = "{0}://localhost:{1}/{2}/login.php" -f $pick.protocol, $port, $Path
try {
    if ($pick.protocol -eq 'https') { [Net.ServicePointManager]::ServerCertificateValidationCallback = { $true } }
    $r = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 60
    if ($r.Content -match 'MEGuard') { Say "Test OK: $url" Green } else { Say "The page answered but does not look like MEGuard: $url" Yellow }
} catch {
    Say "Test request failed ($url): $($_.Exception.Message)" Yellow
    Say 'Common causes: the Visual C++ runtime is missing (run php\php-cgi.exe -v), or another *.php handler is locked at server level.' Yellow
}

$pub = if ($s) { 'https://<your-mail-host>' + $(if (($s.bindingInformation -split ':')[1] -ne '443') { ':' + ($s.bindingInformation -split ':')[1] } else { '' }) } else { 'http://<your-mail-host>' }
Say ''
Say "MEGuard web interface: $pub/$Path/login.php" Green
Say 'If you have not set a password yet: tools\setpass.cmd' Gray
