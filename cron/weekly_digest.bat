@echo off
REM ========================================================
REM StockSense Weekly Email Digest Scheduled Task Runner
REM Triggered by Windows Task Scheduler: Sundays at 8:00 PM IST
REM ========================================================
C:\xampp\php\php.exe C:\xampp\htdocs\StockSense_backend\cron\weekly_digest.php >> C:\xampp\htdocs\StockSense_backend\cron\weekly_digest.log 2>&1

