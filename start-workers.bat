@echo off
REM StayVoyage local background workers: queue (emails, notifications, invoices) + scheduler (hold expiry, reminders).
cd /d "%~dp0backend"
start "StayVoyage queue worker" cmd /k php artisan queue:work redis --sleep=2 --tries=3
start "StayVoyage scheduler" cmd /k php artisan schedule:work
echo Workers started in two new windows. Close those windows to stop them.
