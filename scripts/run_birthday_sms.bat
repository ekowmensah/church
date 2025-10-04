@echo off
REM Birthday SMS Sender - Windows Task Scheduler Script
REM This script runs the birthday SMS sender via PHP CLI
REM 
REM To schedule this script:
REM 1. Open Task Scheduler (taskschd.msc)
REM 2. Create Basic Task
REM 3. Set trigger to Daily at 8:00 AM
REM 4. Set action to start this batch file
REM 5. Set start in directory to the scripts folder

REM Change to the script directory
cd /d "%~dp0"

REM Set PHP path (adjust this to your PHP installation)
set PHP_PATH=C:\xampp\php\php.exe

REM Check if PHP exists
if not exist "%PHP_PATH%" (
    echo ERROR: PHP not found at %PHP_PATH%
    echo Please update the PHP_PATH in this script
    pause
    exit /b 1
)

REM Run the birthday SMS script
echo Starting Birthday SMS Sender at %date% %time%
"%PHP_PATH%" birthday_sms_sender.php

REM Log the execution
echo Birthday SMS Sender completed at %date% %time% >> birthday_sms_execution.log

REM Uncomment the line below if you want to see the output (for testing)
REM pause
