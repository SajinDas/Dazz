<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Scraper.php';
require 'src/Mailer.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Scraper;
use Dazz\Legacy\Mailer;

$archive_folder = 'Datas/Mailing/Tosent/';
$archive_file = $archive_folder . date('hmi-Y-m-d') . 'mail.txt';

/**
 * THE BRIDGE: Calls the Node.js script in the /js folder
 */
function getEmailViaPuppeteer($jobUrl) {
    $command = 'node js/get_email.js ' . escapeshellarg($jobUrl);
    var_dump($command);
    $output = shell_exec($command);
    
    $email = trim($output);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }
    return null;
}

$pagecount = 10;
for ($p = 1; $p <= $pagecount; $p++) {
// --- INITIALIZATION ---
set_time_limit(0);
$db = Config::getDB();
$scraper = new Scraper(Config::CHROME_PATH);
$mailer = new Mailer($db);
$senderIds = [1, 2, 3, 4, 5, 6];

echo "--- STARTING SLUZBY GOV BRIDGE SCRAPE ---\n";

    // Search URL targeting Indian recruitment leads
    $targetUrl = "https://www.sluzbyzamestnanosti.gov.sk/pracovne-ponuky?pageNr=$p&pageSize=30&zdrojPonuky=VPM&pozadovanaZnalostSk=false";

    echo $targetUrl . "\n";

/**
 * NEW SELECTOR: Based on your HTML snippet
 * Targets 'govuk-link' classes that contain '/pracovne-ponuky/'
 */
$selector = "Array.from(document.querySelectorAll('a.govuk-link'))
              .filter(a => a.href.includes('/pracovne-ponuky/'))
              .map(a => a.href)";

// Increased wait to 45s for pre-maintenance stability
$links = $scraper->scrapeLinks($targetUrl, $selector, 45);

if (empty($links)) {
    echo "[!] No links found. The portal API might be slow today.\n";
    if ($p === $pagecount) {
       exit;
    }   
    
}

if (!is_dir($archive_folder)) mkdir($archive_folder, 0777, true);
$archive_handle = fopen($archive_file, 'a');

echo "Found " . count($links) . " jobs. Starting Node.js Bridge...\n";

foreach ($links as $link) {
    echo "Processing: " . basename($link) . "\n";
    
    $email = getEmailViaPuppeteer($link);
    
    if ($email) {
        // Skip administrative government emails
        if (preg_match('/@upsvr\.gov\.sk|@sluzbyzamestnanosti\.sk/i', $email)) continue;

        $sender = $mailer->getValidSender($senderIds, 400);
        if (!$sender) die("Daily limit reached.\n");


         $log_entry = $email . PHP_EOL;
    fwrite($archive_handle, $log_entry);

        $status = $mailer->triggerEmailSending($email, $sender['id'], 'sk');
        echo "   -> Found: $email | Mailer: $status | Sender ID: {$sender['id']} | Sender Count: {$sender['count']}\n";

        if (strpos($status, 'Success') !== false) {
             
        }
    } else {
        echo "   -> No email found on detail page.\n";
    }
}

$db->close();   
$scraper->close();
echo "\n--- BRIDGE SESSION COMPLETE ---\n";
}
