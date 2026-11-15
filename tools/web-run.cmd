@echo off
REM MEGuard - web interface served by MEGuard's own PHP on 127.0.0.1, with a watchdog.
REM Used when the web server proxies /meguard/ to it (Apache mod_proxy or IIS ARR).
REM Installed as the "MEGuard - web" scheduled task by tools\install-php.cmd.
setlocal
set "ROOT=%~dp0.."
set PORT=%1
if "%PORT%"=="" set PORT=8099
if not exist "%ROOT%\data\logs" mkdir "%ROOT%\data\logs"
:loop
echo [%date% %time%] web interface starting (127.0.0.1:%PORT%) >> "%ROOT%\data\logs\web.log"
"%ROOT%\php\php.exe" -d display_errors=0 -d log_errors=1 -d "error_log=%ROOT%\data\logs\php-web.log" -S 127.0.0.1:%PORT% -t "%ROOT%\web" > nul 2>&1
echo [%date% %time%] web interface stopped (%errorlevel%) - restarting in 5 s >> "%ROOT%\data\logs\web.log"
timeout /t 5 /nobreak >nul
goto loop
