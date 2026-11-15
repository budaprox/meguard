@echo off
REM MEGuard - install/update IPBan (run as administrator!)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-ipban.ps1" %*
echo.
pause
