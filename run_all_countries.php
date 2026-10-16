<?php
/**
 * DAZZ LEGACY ABROAD SERVICES - UNIFIED MULTI-COUNTRY B2B PIPELINE ORCHESTRATOR
 * Usage:
 *   php run_all_countries.php               (Runs bounce scan + all 12 European countries sequentially)
 *   php run_all_countries.php cz,si,hr,lt   (Runs bounce scan + specified country codes)
 */
require 'vendor/autoload.php';
require_once 'src/Config.php';
require_once 'src/BounceScanner.php';
require_once 'src/Scraper.php';
require_once 'src/Mailer.php';
require_once 'src/MultiCountryScraper.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\BounceScanner;
use Dazz\Legacy\Mailer;
use Dazz\Legacy\MultiCountryScraper;

set_time_limit(0);

$requestedCodes = isset($argv[1]) && !empty($argv[1])
    ? array_map('trim', explode(',', strtolower($argv[1])))
    : array_keys(Config::COUNTRIES);

$db = Config::getDB();

// Step 0: Always sync Thunderbird & IMAP "Delivery Status Notification (Failure)" bounces first
$bounceScanner = new BounceScanner($db);
$bounceScanner->runFullBounceAndBlockSync(true, 7);

$mailer = new Mailer($db);
$runner = new MultiCountryScraper($db, $mailer, Config::DEFAULT_SENDERS);

foreach ($requestedCodes as $code) {
    if (!isset(Config::COUNTRIES[$code])) {
        echo "[!] Skipping unknown country code: {$code}\n";
        continue;
    }
    try {
        $runner->runCountryPipeline($code, 25, 2);
    } catch (Exception $e) {
        echo "[!] Error in country {$code}: " . $e->getMessage() . "\n";
    }
}

$db->close();
echo "\n=== ALL SELECTED EUROPEAN COUNTRY PIPELINES COMPLETED ===\n";
