@echo off
REM ========================================================
REM StockSense Daily Summary Scheduled Task Runner
REM ========================================================
C:\xampp\php\php.exe C:\xampp\htdocs\StockSense_backend\cron\daily_summary.php >> C:\xampp\htdocs\StockSense_backend\cron\daily_summary.log 2>&1

