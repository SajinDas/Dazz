@echo off
title Dazz Legacy - INFINITE RECRUITMENT PIPELINE
color 0E
mode con: cols=100 lines=40
cd /d D:\Xampp\htdocs\Dazz

:START_PIPELINE

echo ====================================================================
echo                   DAZZ LEGACY ABROAD SERVICES
echo                INFINITE AUTOMATION PIPELINE (LOOP)
echo ====================================================================
echo Cycle Started at: %date% %time%
echo.



:: STEP 1: Process Blocked Emails
echo [1/6] STEP 1: CLEANING DATABASE (Unsubscribes)...
php 1blockEmail.php
echo.

:: STEP 2: Process Blocked Emails
echo [2/6] STEP 2: Slovakia EuroJobs Scraping...
php run_euro.php
echo.

:: STEP 3: Scrape NVA Latvia
echo [3/6] STEP 3: SCRAPING NVA LATVIA...
php 2run_latvia.php
echo.

:: STEP 4: Scrape EURES
echo [4/6] STEP 4: SCRAPING EURES EUROPE...
php 3run_eures.php
echo.

:: STEP 5: Scrape Profesia
echo [5/6] STEP 5: SCRAPING PROFESIA...
php 4run_profesia.php
echo.

:: STEP 6: Run Bulk Mailer
echo [6/6] STEP 6: SENDING QUEUED EMAILS...
::php 5SendEmail.php
echo.


:: STEP 6: Run Slovakia Mailer
echo [6/5] STEP 6: SENDING QUEUED EMAILS...
php 6run_Slovak_Euros.php
echo.

echo ====================================================================
echo Cycle Finished at: %time%
echo --------------------------------------------------------------------
echo Waiting 10 minutes before starting the next cycle...
echo (Press Ctrl+C to stop the loop)
echo --------------------------------------------------------------------

:: Wait for 600 seconds (10 minutes)
timeout /t 600

:: Go back to the top and run everything again
goto START_PIPELINE