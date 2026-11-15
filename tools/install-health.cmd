@echo off
REM MEGuard - install the health watchdog (as administrator)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-health.ps1"
echo.
pause
