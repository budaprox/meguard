@echo off
REM MEGuard - install MEGuard's own PHP 8 (run as administrator!)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-php.ps1" %*
echo.
pause
