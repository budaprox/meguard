@echo off
REM MEGuard - publish the web interface on IIS (run as administrator!)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-iis.ps1" %*
echo.
pause
