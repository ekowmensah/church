@echo off
echo ============================================
echo Sunday School SRN Migration Tool
echo ============================================
echo.
echo This script will help you migrate Sunday School SRNs
echo to the new year-based format: FMC-SYYNN-KM
echo.
echo Choose an option:
echo 1. Dry Run (Preview changes without applying)
echo 2. Execute Migration (Apply changes with backup)
echo 3. Rollback (Restore from backup)
echo 4. Exit
echo.
set /p choice="Enter your choice (1-4): "

if "%choice%"=="1" (
    echo.
    echo Running DRY RUN mode...
    echo.
    php fix_sunday_school_srn_safe.php --dry-run
    pause
) else if "%choice%"=="2" (
    echo.
    echo WARNING: This will modify the database!
    echo A backup will be created automatically.
    echo.
    set /p confirm="Are you sure you want to continue? (yes/no): "
    if /i "%confirm%"=="yes" (
        echo.
        echo Running EXECUTE mode...
        echo.
        php fix_sunday_school_srn_safe.php --execute
    ) else (
        echo Operation cancelled.
    )
    pause
) else if "%choice%"=="3" (
    echo.
    echo Running ROLLBACK mode...
    echo This will restore SRNs from the latest backup.
    echo.
    php fix_sunday_school_srn_safe.php --rollback
    pause
) else if "%choice%"=="4" (
    echo Exiting...
    exit
) else (
    echo Invalid choice. Please run the script again.
    pause
)
