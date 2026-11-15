@echo off
REM MEGuard - update the country filter (as administrator). First time: geo-update.cmd -Install
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0geo-update.ps1" %*
echo.
pause
