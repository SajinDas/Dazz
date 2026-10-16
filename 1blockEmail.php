<?php
/**
 * DAZZ LEGACY - STEP 1: THUNDERBIRD & IMAP BOUNCE SCANNER + BLOCKED EMAIL SYNC
 * Automatically finds "Delivery Status Notification (Failure)" messages in:
 *   1. Local Mozilla Thunderbird Profiles (%APPDATA%\Thunderbird\Profiles\*)
 *   2. Live Gmail/IMAP Inboxes for all sender accounts in `EmailIds`
 *   3. Manual entries in `Datas/Blocked/BlockEmail.txt`
 * Appends discovered bounces to `Datas/Blocked/BlockEmail.txt`, updates `AgentsEmailTable SET Statuss = '2'`,
 * and archives all blocked emails to `Datas/Blocked/Archive/` and `Datas/Blocked/Bounce_Report_<date>.csv`.
 */
require 'vendor/autoload.php';
require_once 'src/Config.php';
require_once 'src/BounceScanner.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\BounceScanner;

set_time_limit(0);

try {
    $db = Config::getDB();
    $scanner = new BounceScanner($db);

    // Scans Thunderbird local mailboxes + last 7 days of IMAP "Delivery Status Notification (Failure)" bounces
    $scanner->runFullBounceAndBlockSync(true, 7);

    $db->close();
} catch (Exception $e) {
    echo "ERROR in 1blockEmail.php: " . $e->getMessage() . "\n";
}
