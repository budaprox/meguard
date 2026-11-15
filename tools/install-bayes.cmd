@echo off
REM MEGuard - set up Bayes training (run as administrator)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-bayes.ps1"
pause
