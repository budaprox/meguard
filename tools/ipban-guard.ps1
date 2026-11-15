# MEGuard - guard for the IPBan firewall rules
# If an IPBan rule is disabled or does not apply to every profile, it is fixed.
# (IPBan rules silently left disabled for years have been seen in practice - this prevents it.)
$log = Join-Path (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)) 'data\logs\ipban-guard.log'
$bad = Get-NetFirewallRule -DisplayName 'IPBan_*' -ErrorAction SilentlyContinue | Where-Object { $_.Enabled -ne 'True' -or $_.Profile -ne 'Any' }
foreach ($r in $bad) {
    Set-NetFirewallRule -InputObject $r -Enabled True -Profile Any
    Add-Content -Path $log -Value ("{0}`tfixed: {1} (Enabled={2}, Profile={3})" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $r.DisplayName, $r.Enabled, $r.Profile)
}
