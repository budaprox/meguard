@echo off
REM MEGuard - SpamAssassin spamd watchdog: whenever spamd stops, it is restarted after 10 seconds.
REM spamd output and every stop are logged to data\logs\spamd.log.
REM Task Scheduler starts this file (set up by install-spamd.cmd).
setlocal
set ROOT=%~dp0..
set PORT=%1
if "%PORT%"=="" set PORT=784
set CHILDREN=%2
if "%CHILDREN%"=="" set CHILDREN=3
if not exist "%ROOT%\data\logs" mkdir "%ROOT%\data\logs"
cd /d "%ROOT%\spamassassin"
:loop
echo [%date% %time%] spamd starting (port %PORT%) >> "%ROOT%\data\logs\spamd.log"
spamd.exe -i 127.0.0.1 -p %PORT% -A 127.0.0.1 --max-children=%CHILDREN% >> "%ROOT%\data\logs\spamd.log" 2>&1
echo [%date% %time%] spamd stopped, exit code: %errorlevel% - restarting in 10 s >> "%ROOT%\data\logs\spamd.log"
timeout /t 10 /nobreak >nul
goto loop
