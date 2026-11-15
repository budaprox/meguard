# Exports the MxScan Access databases to CSV (read-only, changes nothing)
# Run: tools\mdb_export.cmd  (starts 32-bit PowerShell, because the Jet 4.0 driver is 32-bit only)
# Finds the MxScan folder by itself; otherwise: mdb_export.cmd -MxScanDir "D:\MxScan"
param([string]$MxScanDir = '')

$ErrorActionPreference = 'Stop'
if ([IntPtr]::Size -ne 4) {
    Write-Host 'ERROR: this must run in 32-bit PowerShell (mdb_export.cmd starts it that way).' -ForegroundColor Red
    exit 1
}

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$cands = @($MxScanDir, $here, (Split-Path -Parent $here),
    'C:\Program Files (x86)\MxScan', 'C:\Program Files (x86)\MxUptime\MxScanME', 'C:\Program Files (x86)\MxUptime\MxScan',
    'C:\Program Files\MxScan', 'C:\MxScan') | Where-Object { $_ }
$src = $cands | Where-Object { Test-Path (Join-Path $_ 'data\mxscan-config.mdb') } | Select-Object -First 1
if (-not $src) { Write-Host 'ERROR: MxScan folder not found (data\mxscan-config.mdb). Pass it: -MxScanDir "..."' -ForegroundColor Red; exit 1 }
Write-Host "MxScan: $src"

# output: under MEGuard\import when run from the MEGuard tools folder, otherwise into the MxScan folder
if ((Split-Path -Leaf $here) -eq 'tools' -and (Test-Path (Join-Path (Split-Path -Parent $here) 'filter.php'))) {
    $outDir = Join-Path (Split-Path -Parent $here) ('import\' + $env:COMPUTERNAME.ToLower() + '-export')
} else {
    $outDir = Join-Path $src 'export'
}
New-Item -ItemType Directory -Force -Path $outDir | Out-Null

foreach ($db in Get-ChildItem (Join-Path $src 'data\*.mdb')) {
    $dbName = [IO.Path]::GetFileNameWithoutExtension($db.Name)
    Write-Host "== $($db.Name)"
    $conn = New-Object System.Data.OleDb.OleDbConnection("Provider=Microsoft.Jet.OLEDB.4.0;Data Source=$($db.FullName);Mode=Read;")
    try {
        $conn.Open()
        $tables = $conn.GetSchema('Tables') | Where-Object { $_.TABLE_TYPE -eq 'TABLE' } | ForEach-Object { $_.TABLE_NAME }
        foreach ($t in $tables) {
            $cmd = $conn.CreateCommand()
            $cmd.CommandText = "SELECT * FROM [$t]"
            $da = New-Object System.Data.OleDb.OleDbDataAdapter($cmd)
            $dt = New-Object System.Data.DataTable
            [void]$da.Fill($dt)
            $file = Join-Path $outDir ("{0}__{1}.csv" -f $dbName, $t)
            if ($dt.Rows.Count -gt 0) {
                $dt | Select-Object * -ExcludeProperty RowError, RowState, Table, ItemArray, HasErrors |
                    Export-Csv -Path $file -NoTypeInformation -Encoding UTF8
            } else {
                ($dt.Columns | ForEach-Object { '"' + $_.ColumnName + '"' }) -join ',' | Out-File $file -Encoding UTF8
            }
            Write-Host ("   {0,-35} {1,6} rows" -f $t, $dt.Rows.Count)
        }
    } finally { $conn.Close() }
}
Write-Host ''
Write-Host "Done. The CSV files are in: $outDir" -ForegroundColor Green
