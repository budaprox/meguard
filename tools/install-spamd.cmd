@echo off
REM MEGuard - create the SpamAssassin tasks (run as administrator!)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-spamd.ps1" %*
echo.
pause
