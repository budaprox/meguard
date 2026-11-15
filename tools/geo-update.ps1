# MEGuard - country filter (geo allow-list) on the Windows Firewall
#
# Driven by data\geo.json (written by the "Country filter" page of the web interface):
#   - downloads the official delegation files of the five Regional Internet Registries (RIR)
#   - computes the address ranges OUTSIDE the allowed countries (+ private ranges + exceptions)
#   - blocks them on the chosen ports: "MEGuard_Geo_N" firewall rules (IPv4 + IPv6)
#   - when switched off, removes the rules
#
# Run:  tools\geo-update.cmd            (by hand, as administrator)
#       tools\geo-update.cmd -Install   (creates the weekly scheduled task + runs now)
#       tools\geo-update.cmd -Force     (re-downloads the lists even if they are fresh)
param([switch]$Install, [switch]$Force)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # in PS 5.1 the progress bar slows downloads a lot
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$root    = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$dataDir = Join-Path $root 'data'
$geoDir  = Join-Path $dataDir 'geo'
$cfgFile = Join-Path $dataDir 'geo.json'
$stFile  = Join-Path $dataDir 'geo-status.json'
$logFile = Join-Path $dataDir 'logs\geo.log'
New-Item -ItemType Directory -Force -Path $geoDir, (Join-Path $dataDir 'logs') | Out-Null
$prefix  = 'MEGuard_Geo_'
$task    = 'MEGuard - geo'

function Log($t, $c = 'Gray') {
    Write-Host $t -ForegroundColor $c
    Add-Content -Path $logFile -Value ("{0}`t{1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $t)
}
function Save-Status($h) {
    $h['time'] = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    ($h | ConvertTo-Json -Depth 4) | Set-Content -Path $stFile -Encoding UTF8
}

$mutex = New-Object System.Threading.Mutex($false, 'Global\MEGuardGeoUpdate')
$me = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Write-Host 'ERROR: run this as administrator.' -ForegroundColor Red; exit 1 }

# ---- scheduled task (weekly update) ---------------------------------------------
if ($Install) {
    $a  = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$($MyInvocation.MyCommand.Path)`""
    $t  = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Sunday -At '03:40'
    $p  = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $st = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -StartWhenAvailable -MultipleInstances IgnoreNew
    Register-ScheduledTask -TaskName $task -Action $a -Trigger $t -Principal $p -Settings $st `
        -Description 'MEGuard: weekly update of the country-filter firewall rules and the IP-to-country index' -Force | Out-Null
    Log "Scheduled task created: $task (Sundays at 03:40)" Green
}

# only one instance at a time (e.g. a manual run + the web "Apply now" button)
if (-not $mutex.WaitOne(0)) { Log 'Another country-filter update is running - exiting.' Yellow; exit 0 }

# ---- 1. download the RIR lists --------------------------------------------------------
$sources = [ordered]@{
    ripencc = 'https://ftp.ripe.net/pub/stats/ripencc/delegated-ripencc-extended-latest'
    arin    = 'https://ftp.arin.net/pub/stats/arin/delegated-arin-extended-latest'
    apnic   = 'https://ftp.apnic.net/stats/apnic/delegated-apnic-extended-latest'
    lacnic  = 'https://ftp.lacnic.net/pub/stats/lacnic/delegated-lacnic-extended-latest'
    afrinic = 'https://ftp.afrinic.net/pub/stats/afrinic/delegated-afrinic-extended-latest'
}
foreach ($k in $sources.Keys) {
    $f = Join-Path $geoDir "$k.txt"
    $fresh = (Test-Path $f) -and ((Get-Item $f).LastWriteTime -gt (Get-Date).AddDays(-6)) -and -not $Force
    if ($fresh) { continue }
    try {
        $tmp = "$f.download"
        Invoke-WebRequest -Uri $sources[$k] -OutFile $tmp -UseBasicParsing -TimeoutSec 180
        if ((Get-Item $tmp).Length -lt 100000) { throw 'file too small' }
        Move-Item -Force $tmp $f
        Log "Downloaded: $k ($([math]::Round((Get-Item $f).Length / 1MB, 1)) MB)"
    } catch {
        Remove-Item "$f.download" -ErrorAction SilentlyContinue
        if (Test-Path $f) { Log "WARNING: downloading $k failed ($($_.Exception.Message)) - using the previous copy." Yellow }
        else {
            Log "ERROR: downloading $k failed and there is no previous copy: $($_.Exception.Message). Existing rules are unchanged." Red
            Save-Status @{ ok = $false; enabled = $true; message = "downloading $k failed" }
            exit 1
        }
    }
}

# ---- 1/b. IP -> country index for the filter's country score (built even when the firewall filter is off) ----
$php = Join-Path $root 'php\php.exe'
if (-not (Test-Path $php)) { $php = 'C:\Xampp\php\php.exe' }
if (-not (Test-Path $php)) { $c = Get-Command php.exe -ErrorAction SilentlyContinue; if ($c) { $php = $c.Source } }
if (Test-Path $php) {
    $ErrorActionPreference = 'Continue'   # PS 5.1: with 'Stop', a stderr line from 2>&1 would abort the script
    $out = & $php -f (Join-Path $root 'tools\geo-ipdb.php') 2>&1 | ForEach-Object { "$_" }
    $ErrorActionPreference = 'Stop'
    if ($LASTEXITCODE -eq 0) { Log ($out -join ' ') } else { Log ("WARNING: the IP-to-country index was not built: " + ($out -join ' ')) Yellow }
} else {
    Log "WARNING: php.exe not found ($php) - the IP-to-country index was not built." Yellow
}

# ---- settings (firewall country filter) ------------------------------------------------------------------
if (-not (Test-Path $cfgFile)) {
    Log 'No data\geo.json - the country filter is not configured (use the Country filter page of the web interface).' Yellow
    Save-Status @{ ok = $true; enabled = $false; message = 'not configured' }
    exit 0
}
$cfg = Get-Content -Raw -Encoding UTF8 $cfgFile | ConvertFrom-Json

function Remove-GeoRules {
    $old = @(Get-NetFirewallRule -DisplayName "$prefix*" -ErrorAction SilentlyContinue)
    if ($old.Count) {
        Remove-NetFirewallRule -DisplayName "$prefix*" -ErrorAction SilentlyContinue
        Log ("{0} old country-filter rule(s) removed." -f $old.Count)
    }
}

if (-not $cfg.enabled) {
    Remove-GeoRules
    Save-Status @{ ok = $true; enabled = $false; message = 'off' }
    Log 'The country filter is off.' Green
    exit 0
}

$allow = @($cfg.countries | ForEach-Object { "$_".ToUpper() } | Where-Object { $_ -match '^[A-Z]{2}$' })
$ports = @($cfg.ports | ForEach-Object { [int]$_ } | Where-Object { $_ -gt 0 -and $_ -lt 65536 } | Select-Object -Unique)
if ($allow.Count -lt 1 -or $ports.Count -lt 1) {
    Log 'ERROR: no allowed country or port is set.' Red
    Save-Status @{ ok = $false; enabled = $true; message = 'no country or port set' }
    exit 1
}

# ---- 2. computation (C# for speed) -------------------------------------------------------
Add-Type -Language CSharp -ReferencedAssemblies System.Numerics -TypeDefinition @'
using System;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Numerics;

public static class MegGeo
{
    static BigInteger ToBig(IPAddress ip) {
        byte[] b = ip.GetAddressBytes();
        byte[] le = new byte[b.Length + 1];
        for (int i = 0; i < b.Length; i++) le[i] = b[b.Length - 1 - i];
        return new BigInteger(le);
    }
    static string FromBig(BigInteger v, int bits) {
        int n = bits / 8;
        byte[] le = v.ToByteArray();
        byte[] be = new byte[n];
        for (int i = 0; i < n && i < le.Length; i++) be[n - 1 - i] = le[i];
        return new IPAddress(be).ToString();
    }
    public static void AddCidr(List<BigInteger[]> list, string cidr) {
        string[] p = cidr.Trim().Split('/');
        IPAddress ip;
        if (!IPAddress.TryParse(p[0], out ip)) return;
        int bits = ip.AddressFamily == System.Net.Sockets.AddressFamily.InterNetworkV6 ? 128 : 32;
        int len = p.Length > 1 ? int.Parse(p[1]) : bits;
        BigInteger s = ToBig(ip);
        BigInteger size = BigInteger.One << (bits - len);
        s = s - (s % size);
        list.Add(new BigInteger[] { s, s + size - 1 });
    }
    // read the RIR files: ranges of the allowed countries (v4, v6) + per-country statistics
    public static int Load(string[] files, HashSet<string> allow, List<BigInteger[]> v4, List<BigInteger[]> v6, Dictionary<string,int> stats) {
        int n = 0;
        foreach (string f in files) {
            foreach (string line in File.ReadLines(f)) {
                if (line.Length == 0 || line[0] == '#') continue;
                string[] c = line.Split('|');
                if (c.Length < 7) continue;
                string cc = c[1], type = c[2], st = c[6];
                if (cc.Length != 2 || cc == "ZZ") continue;
                if (st != "allocated" && st != "assigned") continue;
                if (type != "ipv4" && type != "ipv6") continue;
                int cnt; stats.TryGetValue(cc, out cnt); stats[cc] = cnt + 1;
                if (!allow.Contains(cc)) continue;
                IPAddress ip;
                if (!IPAddress.TryParse(c[3], out ip)) continue;
                BigInteger s = ToBig(ip);
                if (type == "ipv4") v4.Add(new BigInteger[] { s, s + BigInteger.Parse(c[4]) - 1 });
                else v6.Add(new BigInteger[] { s, s + (BigInteger.One << (128 - int.Parse(c[4]))) - 1 });
                n++;
            }
        }
        return n;
    }
    // complement of the allowed ranges, as "start-end" ranges
    // (the Windows Firewall accepts them directly, no need to split into CIDR blocks)
    public static List<string> Complement(List<BigInteger[]> ranges, int bits) {
        ranges.Sort((a, b) => a[0].CompareTo(b[0]));
        List<string> outp = new List<string>();
        BigInteger max = (BigInteger.One << bits) - 1;
        BigInteger next = 0;
        foreach (BigInteger[] r in ranges) {
            if (r[0] > next) outp.Add(Range(next, r[0] - 1, bits));
            if (r[1] + 1 > next) next = r[1] + 1;
            if (next > max) break;
        }
        if (next <= max) outp.Add(Range(next, max, bits));
        return outp;
    }
    static string Range(BigInteger s, BigInteger e, int bits) {
        return s == e ? FromBig(s, bits) : FromBig(s, bits) + "-" + FromBig(e, bits);
    }
}
'@

$allowSet = New-Object 'System.Collections.Generic.HashSet[string]'
$allow | ForEach-Object { [void]$allowSet.Add($_) }
$v4 = New-Object 'System.Collections.Generic.List[System.Numerics.BigInteger[]]'
$v6 = New-Object 'System.Collections.Generic.List[System.Numerics.BigInteger[]]'
$stats = New-Object 'System.Collections.Generic.Dictionary[string,int]'
$files = @(Get-ChildItem (Join-Path $geoDir '*.txt') | ForEach-Object { $_.FullName })
$n = [MegGeo]::Load($files, $allowSet, $v4, $v6, $stats)

# country list for the web interface
($stats.Keys | Sort-Object) -join ',' | Set-Content -Path (Join-Path $geoDir 'countries.txt') -Encoding ASCII

if ($n -lt 500) {
    Log "ERROR: too few allowed ranges ($n) - something is wrong with the lists. Existing rules are unchanged." Red
    Save-Status @{ ok = $false; enabled = $true; message = "too few ranges ($n)" }
    exit 1
}

# always allowed: local and private ranges + the exceptions
$always = @('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '169.254.0.0/16', '100.64.0.0/10',
            '::1/128', 'fc00::/7', 'fe80::/10')
foreach ($c in ($always + @($cfg.exceptions))) {
    if (-not $c) { continue }
    if ("$c" -match ':') { [MegGeo]::AddCidr($v6, "$c") } else { [MegGeo]::AddCidr($v4, "$c") }
}

# IPv6 rules are only needed if the server has a public IPv6 address (otherwise it is unreachable over IPv6)
$hasV6 = @(Get-NetIPAddress -AddressFamily IPv6 -ErrorAction SilentlyContinue | Where-Object {
    $_.IPAddress -match '^[23][0-9a-f]{0,3}:' -and $_.IPAddress -notmatch '^2001:0?:' -and $_.AddressState -eq 'Preferred' }).Count -gt 0
$block = @([MegGeo]::Complement($v4, 32))
$b4 = $block.Count
if ($hasV6) { $block += @([MegGeo]::Complement($v6, 128)) }
Log ("{0} allowed ranges ({1} countries) -> {2} ranges to block (IPv4: {3}, IPv6: {4})" -f $n, $allow.Count, $block.Count, $b4, $(if ($hasV6) { $block.Count - $b4 } else { 'no public IPv6 address, skipped' }))

# ---- 3. replace the firewall rules ------------------------------------------------------
$sw = [Diagnostics.Stopwatch]::StartNew()
Remove-GeoRules
$chunk = 1000; $i = 0; $made = 0
for ($o = 0; $o -lt $block.Count; $o += $chunk) {
    $part = $block[$o..([math]::Min($o + $chunk, $block.Count) - 1)]
    New-NetFirewallRule -DisplayName ("{0}{1}" -f $prefix, $i) -Group 'MEGuard' -Direction Inbound -Action Block `
        -Protocol TCP -LocalPort $ports -RemoteAddress $part -Profile Any -Enabled True `
        -Description "MEGuard country filter: addresses outside the allowed countries ($($allow -join ','))" | Out-Null
    $i++; $made++
}
$sw.Stop()
Log ("Done: {0} firewall rules, ports: {1} ({2} s)" -f $made, ($ports -join ','), [int]$sw.Elapsed.TotalSeconds) Green
Save-Status @{ ok = $true; enabled = $true; message = 'ok'; rules = $made; blocks = $block.Count; ranges = $n; ipv6 = $hasV6; countries = @($allow); ports = @($ports) }
