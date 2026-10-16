<?php
/**
 * DAZZ LEGACY ABROAD SERVICES - B2B RECRUITMENT PIPELINE
 * Country Module: Poland (ePraca / Centralna Baza Ofert Pracy)
 */
require 'vendor/autoload.php';
require_once 'src/Config.php';
require_once 'src/Scraper.php';
require_once 'src/Mailer.php';
require_once 'src/MultiCountryScraper.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Mailer;
use Dazz\Legacy\MultiCountryScraper;

set_time_limit(0);

try {
    $db = Config::getDB();
    $mailer = new Mailer($db);
    $runner = new MultiCountryScraper($db, $mailer, Config::DEFAULT_SENDERS);

    $runner->runCountryPipeline('pl', 35, 2);

    $db->close();
} catch (Exception $e) {
    echo "FATAL ERROR [PL]: " . $e->getMessage() . "\n";
}
