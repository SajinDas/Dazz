@echo off
title Dazz Legacy - PAN-EUROPEAN INFINITE RECRUITMENT PIPELINE
color 0E
mode con: cols=110 lines=45
cd /d D:\Xampp\htdocs\Dazz

:START_PIPELINE
echo ====================================================================
echo                   DAZZ LEGACY ABROAD SERVICES
echo          PAN-EUROPEAN B2B AUTOMATION PIPELINE (12 COUNTRIES)
echo ====================================================================
echo Cycle Started at: %date% %time%
echo.

:: STEP 1: Scan Thunderbird and IMAP for Delivery Status Notification (Failure) and Block Emails
echo [1/15] SCANNING THUNDERBIRD AND IMAP BOUNCES + UPDATING BLOCKED LIST...
php 1blockEmail.php
echo.

:: STEP 2: Slovakia EURES and Sluzby Zamestnanosti
echo [2/15] SCRAPING SLOVAKIA (EURES and Sluzby Zamestnanosti)...
php run_euro.php
php 4run_profesia.php
echo.

:: STEP 3: Latvia NVA
echo [3/15] SCRAPING LATVIA (NVA Portal)...
php 2run_latvia.php
echo.

:: STEP 4: Czechia MPSV and Urad Prace
echo [4/15] SCRAPING CZECHIA (MPSV Foreigner Database)...
php 7run_czechia.php
echo.

:: STEP 5: Slovenia ZRSZ
echo [5/15] SCRAPING SLOVENIA (ZRSZ Portal)...
php 8run_slovenia.php
echo.

:: STEP 6: Croatia HZZ Burza Rada
echo [6/15] SCRAPING CROATIA (HZZ Burza Rada)...
php 9run_croatia.php
echo.

:: STEP 7: Lithuania UZT
echo [7/15] SCRAPING LITHUANIA (Uzimtumo Tarnyba)...
php 10run_lithuania.php
echo.

:: STEP 8: Estonia Tootukassa
echo [8/15] SCRAPING ESTONIA (Eesti Tootukassa)...
php 11run_estonia.php
echo.

:: STEP 9: Poland CBOP / ePraca
echo [9/15] SCRAPING POLAND (Centralna Baza Ofert Pracy)...
php 12run_poland.php
echo.

:: STEP 10: Romania ANOFM Mediere
echo [10/15] SCRAPING ROMANIA (Mediere ANOFM)...
php 13run_romania.php
echo.

:: STEP 11: Hungary VMP
echo [11/15] SCRAPING HUNGARY (Virtualis Munkaeropiac Portal)...
php 14run_hungary.php
echo.

:: STEP 12: Malta Jobsplus
echo [12/15] SCRAPING MALTA (Jobsplus Portal)...
php 15run_malta.php
echo.

:: STEP 13: Germany Bundesagentur fur Arbeit
echo [13/15] SCRAPING GERMANY (BA Jobsuche)...
php 16run_germany.php
echo.

:: STEP 14: Pan-European EURES Multi-Country Sweep
echo [14/15] SCRAPING PAN-EUROPEAN EURES PORTAL...
php 3run_eures.php
echo.

:: STEP 15: Process Queued Outbound Emails
echo [15/15] SENDING QUEUED EMAILS...
php 5SendEmail.php
echo.

echo Cycle Completed at: %date% %time%. Cooling down for 30 minutes...
timeout /t 1800 /nobreak
goto START_PIPELINE
