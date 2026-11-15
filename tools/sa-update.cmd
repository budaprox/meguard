@echo off
REM MEGuard - SpamAssassin rule update. If new rules arrived, spamd is stopped and its
REM watchdog (spamd-run.cmd) restarts it with the new rules within 10 seconds.
REM If Gpg4win is installed (gpg.exe on the PATH), updates are signature-checked.
cd /d "%~dp0..\spamassassin"
if not exist "%~dp0..\data\logs" mkdir "%~dp0..\data\logs"
set GPGOPT=--no-gpg
where gpg.exe >nul 2>&1 && set GPGOPT=
echo [%date% %time%] sa-update >> "%~dp0..\data\logs\sa-update.log"
sa-update.exe %GPGOPT% >> "%~dp0..\data\logs\sa-update.log" 2>&1
if %errorlevel%==0 (
    echo [%date% %time%] new rules - restarting spamd >> "%~dp0..\data\logs\sa-update.log"
    taskkill /F /IM spamd.exe >nul 2>&1
)
exit /b 0
