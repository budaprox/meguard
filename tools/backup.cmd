@echo off
REM MEGuard - run the backup by hand (as administrator)
cd /d "%~dp0.."
set "MEGPHP=%~dp0..\php\php.exe"
if not exist "%MEGPHP%" set "MEGPHP=C:\Xampp\php\php.exe"
if not exist "%MEGPHP%" for %%P in (php.exe) do if not "%%~$PATH:P"=="" set "MEGPHP=%%~$PATH:P"
"%MEGPHP%" -f tools\backup.php
echo.
pause
