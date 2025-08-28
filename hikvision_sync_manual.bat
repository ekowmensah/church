@echo off
REM Manual Hikvision Sync Agent Runner
REM Double-click this file to manually sync attendance from Hikvision device

echo Running Hikvision Attendance Sync...
echo.

cd /d "C:\xampp\htdocs\myfreemanchurchgit\church"
php hikvision_sync_agent.php

echo.
echo Sync completed. Press any key to close...
pause
