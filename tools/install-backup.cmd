@echo off
REM MEGuard - create the daily backup task (as administrator)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-backup.ps1"
pause
