<?php
require 'vendor/autoload.php';
require_once 'src/Config.php';
require_once 'src/Scraper.php';
require_once 'src/Mailer.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Scraper;
use Dazz\Legacy\Mailer;

set_time_limit(0);

$archive_folder = 'Datas/Mailing/Tosent/';
if (!is_dir($archive_folder)) mkdir($archive_folder, 0777, true);
$archive_file = $archive_folder . date('hmi-Y-m-d') . 'mail.txt';
$archive_handle = fopen($archive_file, 'a');

function getEmailViaPuppeteer($jobUrl) {
    $command = 'node js/get_email.js ' . escapeshellarg($jobUrl);
    $output = shell_exec($command);
    $email = trim((string)$output);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return strtolower($email);
    }
    return null;
}

$db = Config::getDB();
$scraper = new Scraper(Config::CHROME_PATH);
$mailer = new Mailer($db);
$senderIds = [1, 2, 3, 4, 5, 6];
$pagecount = 10;

echo "--- STARTING SLOVAKIA SLUZBY ZAMESTNANOSTI (ISTP) SCRAPE ---\n";

for ($p = 1; $p <= $pagecount; $p++) {
    $targetUrl = "https://www.sluzbyzamestnanosti.gov.sk/pracovne-ponuky?pageNr={$p}&pageSize=30&zdrojPonuky=VPM&pozadovanaZnalostSk=false";
    echo "\n[Page {$p}/{$pagecount}] Scanning: {$targetUrl}\n";

    $selector = "Array.from(document.querySelectorAll('a[href*="/pracovne-ponuky/"]')).map(a => a.href).filter(h => h && !h.includes('?pageNr=') && !h.endsWith('/pracovne-ponuky') && !h.endsWith('/pracovne-ponuky/'))";
    $links = $scraper->scrapeLinks($targetUrl, $selector, 20, '/pracovne-ponuky/');

    if (empty($links)) {
        echo "[!] No job detail links found on Page {$p}. Moving to next step...\n";
        break;
    }

    echo "Found " . count($links) . " jobs on Page {$p}. Extracting employer emails via Node.js Stealth Bridge...\n";

    foreach ($links as $link) {
        echo "   [*] Processing: " . basename($link) . "\n";
        $email = getEmailViaPuppeteer($link);

        if ($email) {
            if ($mailer->isBlockedOrGovEmail($email)) {
                echo "       [-] Skipped government/blocked address: {$email}\n";
                continue;
            }

            $sender = $mailer->getValidSender($senderIds, Config::DAILY_SENDER_LIMIT);
            if (!$sender) {
                echo "CRITICAL: Daily limit reached across all senders.\n";
                break 2;
            }

            fwrite($archive_handle, $email . PHP_EOL);
            $status = $mailer->triggerEmailSending($email, $sender['id'], 'sk');
            echo "       -> Lead: {$email} | Mailer: {$status} | Sender ID: {$sender['id']} (Count: {$sender['count']})\n";

            if (strpos($status, 'Success') !== false) {
                sleep(rand(20, 30));
            }
        } else {
            echo "       [-] No direct employer email on detail page.\n";
        }
    }
}

fclose($archive_handle);
$scraper->close();
$db->close();
echo "\n--- SLOVAKIA SLUZBY BRIDGE SESSION COMPLETE ---\n";
