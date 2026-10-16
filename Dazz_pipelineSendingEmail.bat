@echo off
title Dazz Legacy - RECRUITMENT PIPELINE
color 0E
mode con: cols=100 lines=100
cd /d D:\Xampp\htdocs\Dazz

:START_PIPELINE

:: Extract the current hour (0-23)
set "HOUR=%TIME:~0,2%"
:: Strip leading spaces for single-digit hours (e.g., " 8" -> "8")
set "HOUR=%HOUR: =%"

:: Check if current hour is between 8 (8 AM) and 19 (7:59 PM)
if %HOUR% GEQ 8 if %HOUR% LSS 22 (
    goto RUN_TASKS
)

:: If outside operating hours, execution drops through to here
echo ====================================================================
echo Outside operating hours (08:00 to 20:00).
echo Current Time: %date% %time%
echo Waiting 10 minutes before checking again...
echo ====================================================================
timeout /t 600
goto START_PIPELINE


:RUN_TASKS
echo ====================================================================
echo                 DAZZ LEGACY ABROAD SERVICES
echo                     Emailing (LOOP)
echo ====================================================================
echo Cycle Started at: %date% %time%
echo.

:: --- PRE-STEP: CLEAN DATABASE ---
echo Cleaning Database (Unsubscribes)...
php 1blockEmail.php
echo.

:: --- DAILY PRIORITY ROTATION ---
:: Get the current day number (strip leading zeros to prevent octal errors)
set "DAY_NUM=%DATE:~7,2%"
if "%DAY_NUM:~0,1%"=="0" set "DAY_NUM=%DAY_NUM:~1,1%"

:: Calculate Day % 2 (Returns 0 for Even days, 1 for Odd days)
set /a "ROTATION=%DAY_NUM% %% 2"

if %ROTATION%==0 (
    echo [PRIORITY MODE A] Executing: Slovakia Email FIRST, then Bulk Mailer...
    echo ====================================================================
    call :TASK_SLOVAKIA
    call :TASK_BULK
) else (
    echo [PRIORITY MODE B] Executing: Bulk Mailer FIRST, then Slovakia Email...
    echo ====================================================================
    call :TASK_BULK
    call :TASK_SLOVAKIA
)

echo ====================================================================
echo Cycle Finished at: %time%
echo --------------------------------------------------------------------
echo Waiting 10 minutes before starting the next cycle...
echo (Press Ctrl+C to stop the loop)
echo --------------------------------------------------------------------

timeout /t 600
goto START_PIPELINE


:: ====================================================================
:: SUBROUTINES FOR TASKS
:: ====================================================================

:TASK_SLOVAKIA
echo [TASK] Slovakia Email Sending...
echo Time: %date% %time%
php 2_excel_to_mailer.php
echo.
exit /b

:TASK_BULK
echo [TASK] SENDING QUEUED EMAILS (Bulk Mailer)...
echo Time: %date% %time%
php 5SendEmail.php
echo.
exit /b