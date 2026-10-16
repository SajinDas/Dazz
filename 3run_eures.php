<?php
require 'vendor/autoload.php';
require_once 'src/Config.php';
require_once 'src/Scraper.php';
require_once 'src/Mailer.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Scraper;
use Dazz\Legacy\Mailer;

$archive_folder = 'Datas/Mailing/Tosent/';
$archive_file = $archive_folder . date('hmi-Y-m-d') . 'mail.txt';

function getEmailsForJob($jobId) {
    $initialUrl = "https://europa.eu/eures/portal/jv-se/jv-details/" . $jobId;
    $apiUrl = "https://europa.eu/eures/api/jv-searchengine/public/jv/id/" . $jobId;
    $cookieFile = __DIR__ . '/cookies.txt';
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    $ch = curl_init($apiUrl);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json, text/plain, */*',
        'Referer: ' . $initialUrl,
        'X-Requested-With: XMLHttpRequest'
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    curl_close($ch);
    if (file_exists($cookieFile)) unlink($cookieFile);

    if (!empty($response)) {
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,10}/i', $response, $matches);
        return array_unique($matches[0] ?? []);
    }
    return [];
}

echo "=== EURES PAN-EUROPEAN JOB LEAD SCRAPER STARTED ===\n";
set_time_limit(0);
$db = Config::getDB();
$scraper = new Scraper(Config::CHROME_PATH);
$mailer = new Mailer($db);
$checkpointFile = __DIR__ . '/checkpoint.txt';
$senderIds = [5, 4, 3, 1, 6];

if (!is_dir($archive_folder)) mkdir($archive_folder, 0777, true);
$archive_handle = fopen($archive_file, 'a');

$startPage = file_exists($checkpointFile) ? (int)trim(file_get_contents($checkpointFile)) : 1;
$endPage = 202;

if ($startPage < 1 || $startPage > $endPage) {
    $startPage = 1;
}

echo "--- SESSION START: PAGE $startPage ---\n";

for ($p = $startPage; $p <= $endPage; $p++) {
    file_put_contents($checkpointFile, $p);
    echo "\n[PAGINATION] Accessing Page $p of $endPage...\n";

    $targetUrl = "https://europa.eu/eures/portal/jv-se/search?page=$p&resultsPerPage=50&orderBy=MOST_RECENT&locationCodes=at,cy,cz,de,ee,el,es,fr,hr,lt,lv,mt,nl,pl,se,si,bg,it,hu,ro,pt&positionScheduleCodes=NS,flextime,fulltime,parttime&publicationPeriod=LAST_THREE_DAYS&escoIsco=C35,C6,C7,C8,C9,C54,C53,C51,C32&minNumberPost=4&previousPageType=findJob&lang=en";

    $selector = "Array.from(document.links).map(l=>l.href).filter(h=>h&&h.includes('jv-details'))";
    $links = $scraper->scrapeLinks($targetUrl, $selector, 20, 'jv-details');

    if (empty($links)) {
        echo "[END] No links found on Page $p.\n";
        break;
    }

    foreach ($links as $link) {
        $jobId = explode('?', str_replace("https://europa.eu/eures/portal/jv-se/jv-details/", "", $link))[0];
        parse_str(parse_url($link, PHP_URL_QUERY) ?? '', $params);
        $lang = $params['jvDisplayLanguage'] ?? 'en';

        $emails = getEmailsForJob($jobId);

        foreach ($emails as $email) {
            if ($mailer->isBlockedOrGovEmail($email)) continue;

            $sender = $mailer->getValidSender($senderIds, 400);
            if (!$sender) {
                echo "CRITICAL: All senders reached daily limit (400).\n";
                break 3;
            }

            $status = $mailer->triggerEmailSending($email, $sender['id'], $lang);
            echo "Lead: $email | Sender: {$sender['id']} count: {$sender['count']} | Result: $status\n";
            fwrite($archive_handle, $email . PHP_EOL);
        }
    }
}

if (file_exists($checkpointFile)) unlink($checkpointFile);
fclose($archive_handle);
$db->close();
$scraper->close();
echo "\n--- ALL TASKS COMPLETED ---\n";
