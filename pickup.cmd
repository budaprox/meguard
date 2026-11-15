@echo off
REM MEGuard - enter this file in the MailEnable MTA "Program to execute on mail file" (pickup event) field.
REM PHP: MEGuard\php\php.exe if present, else C:\Xampp\php\php.exe, else php.exe on the PATH.
set "MEGPHP=%~dp0php\php.exe"
if not exist "%MEGPHP%" set "MEGPHP=C:\Xampp\php\php.exe"
if not exist "%MEGPHP%" for %%P in (php.exe) do if not "%%~$PATH:P"=="" set "MEGPHP=%%~$PATH:P"
"%MEGPHP%" -f "%~dp0filter.php" -- %1 %2
exit /b 0
