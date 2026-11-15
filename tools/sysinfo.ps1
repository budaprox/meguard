# MEGuard - system status as JSON for the Tools page of the web interface
# (CPU, memory, disks, processes, services, MEGuard scheduled tasks)
# Language-independent: uses no localized performance-counter names.
$ErrorActionPreference = 'SilentlyContinue'
$os = Get-CimInstance Win32_OperatingSystem
$cs = Get-CimInstance Win32_ComputerSystem
$ncpu = [int]$cs.NumberOfLogicalProcessors
if ($ncpu -lt 1) { $ncpu = 1 }

function Snap {
    $h = @{}
    foreach ($p in (Get-CimInstance Win32_Process)) {
        $h[[int]$p.ProcessId] = @{ n = [string]$p.Name; t = [double]$p.KernelModeTime + [double]$p.UserModeTime; ws = [double]$p.WorkingSetSize }
    }
    return $h
}
$s1 = Snap; $t1 = Get-Date
Start-Sleep -Milliseconds 1500
$s2 = Snap; $t2 = Get-Date
$span = ($t2 - $t1).TotalSeconds * 10000000 * $ncpu   # in 100 ns units, across all cores

$idle = 0; $procs = @(); $groups = @{}
foreach ($id in $s2.Keys) {
    $p = $s2[$id]
    $d = 0
    if ($s1.ContainsKey($id) -and $s1[$id].n -eq $p.n) { $d = $p.t - $s1[$id].t }
    if ($d -lt 0) { $d = 0 }
    $cpu = [math]::Round(100 * $d / $span, 1)
    if ($id -eq 0) { $idle = $cpu; continue }
    $name = $p.n -replace '\.exe$', ''
    $g = switch -regex ($name) {
        '^ME[A-Z]+$'             { 'mailenable'; break }
        '^spamd'                 { 'spamd'; break }
        'IPBan'                  { 'ipban'; break }
        '^httpd$'                { 'apache'; break }
        '^php'                   { 'php'; break }
        '^(mysqld|mariadbd)$'    { 'mysql'; break }
        default                  { '' }
    }
    if ($g -ne '') {
        if (-not $groups.ContainsKey($g)) { $groups[$g] = @{ cpu = 0.0; mem = 0.0; count = 0 } }
        $groups[$g].cpu += $cpu; $groups[$g].mem += $p.ws; $groups[$g].count++
    }
    if ($g -eq 'mailenable' -or $g -eq 'spamd' -or $g -eq 'ipban') {
        $procs += [ordered]@{ name = $name; group = $g; cpu = $cpu; mem = $p.ws }
    }
}
foreach ($k in @($groups.Keys)) { $groups[$k].cpu = [math]::Round($groups[$k].cpu, 1) }

$svc = @(Get-Service | Where-Object { $_.Name -match '^ME[A-Z]+$' -or $_.Name -eq 'IPBan' -or $_.Name -like 'MxScan*' -or $_.Name -like 'Apache*' } | ForEach-Object {
    [ordered]@{ name = $_.Name; display = $_.DisplayName; status = "$($_.Status)"; start = "$($_.StartType)" }
})

$tasks = @(Get-ScheduledTask -TaskName 'MEGuard*' | ForEach-Object {
    $i = $_ | Get-ScheduledTaskInfo
    $last = ''
    if ($i.LastRunTime -and $i.LastRunTime.Year -gt 2000) { $last = $i.LastRunTime.ToString('yyyy-MM-dd HH:mm:ss') }
    [ordered]@{ name = $_.TaskName; state = "$($_.State)"; last = $last; result = [int64]$i.LastTaskResult }
})

$disks = @(Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' | ForEach-Object {
    [ordered]@{ drive = $_.DeviceID; size = [double]$_.Size; free = [double]$_.FreeSpace }
})

$out = [ordered]@{
    ok      = $true
    host    = $env:COMPUTERNAME
    cpus    = $ncpu
    cpu     = [math]::Max(0, [math]::Min(100, [math]::Round(100 - $idle, 1)))
    memTotal = [double]$os.TotalVisibleMemorySize * 1024
    memFree  = [double]$os.FreePhysicalMemory * 1024
    boot    = $os.LastBootUpTime.ToString('yyyy-MM-dd HH:mm:ss')
    os      = $os.Caption
    groups  = $groups
    procs   = $procs
    services = $svc
    tasks   = $tasks
    disks   = $disks
}
$out | ConvertTo-Json -Depth 5 -Compress
