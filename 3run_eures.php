<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Scraper.php';
require 'src/Mailer.php';
//require 'blockEmail.php'; // Ensure this is included to handle blocked emails before scraping

use Dazz\Legacy\Config;
use Dazz\Legacy\Scraper;
use Dazz\Legacy\Mailer;

$archive_folder = 'Datas/Mailing/Tosent/';
$archive_file = $archive_folder . date('hmi-Y-m-d') . 'mail.txt';

// --- YOUR POLISHED API LOGIC (REMAINED UNCHANGED) ---
function getEmailsForJob($jobId) {
    $initialUrl = "https://europa.eu/eures/portal/jv-se/jv-details/" . $jobId;
    $apiUrl = "https://europa.eu/eures/api/jv-searchengine/public/jv/id/" . $jobId;
    $cookieFile = __DIR__ . '/cookies.txt';
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

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
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,4}/i', $response, $matches);
        return array_unique($matches[0] ?? []);
    }
    return [];
}



echo "=== EURES JOB LEAD SCRAPER STARTED ===\n";
// --- MAIN EXECUTION ---
set_time_limit(0);
$db = Config::getDB();
echo "=== EURES JOB LEAD SCRAPER STARTED ==2=\n";
$scraper = new Scraper(Config::CHROME_PATH);
$mailer = new Mailer($db);
$checkpointFile = __DIR__ . '/checkpoint.txt';
$senderIds = [5,4,3,1,6]; // YOUR SENDER IDs IN PRIORITY ORDER

if (!is_dir($archive_folder)) mkdir($archive_folder, 0777, true);
$archive_handle = fopen($archive_file, 'a');

// Checkpoint Logic
$startPage = file_exists($checkpointFile) ? (int)file_get_contents($checkpointFile) : 1;
$endPage = 202; 

echo "--- SESSION START: PAGE $startPage ---\n";

for ($p = $startPage; $p <= $endPage; $p++) {
    file_put_contents($checkpointFile, $p);
    echo "\n[PAGINATION] Accessing Page $p...\n";
    
    // YOUR SPECIFIC TARGET LINK
    $targetUrl = "https://europa.eu/eures/portal/jv-se/search?page=$p&resultsPerPage=50&orderBy=MOST_RECENT&locationCodes=at,cy,cz,de,ee,el,es,fr,hr,lt,lv,mt,nl,pl,se,si,bg,it,hu,ro,pt&positionScheduleCodes=NS,flextime,fulltime,parttime&publicationPeriod=LAST_THREE_DAYS&escoIsco=C35,C6,C7,C8,C9,C54,C53,C51,C32&minNumberPost=4&previousPageType=findJob&lang=en";

    //$targetUrl = "https://europa.eu/eures/portal/jv-se/search?page=$p&resultsPerPage=50&orderBy=MOST_RECENT&locationCodes=sk&positionScheduleCodes=NS,flextime,fulltime,parttime&publicationPeriod=LAST_MONTH&escoIsco=C35,C6,C7,C8,C9&minNumberPost=4&lang=en";
    
    $selector = "Array.from(document.links).map(l=>l.href).filter(h=>h&&h.includes('jv-details'))";
    $links = $scraper->scrapeLinks($targetUrl, $selector, 35);

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
            // Check Daily Limits Real-time before sending
            $sender = $mailer->getValidSender($senderIds, 400);
             

            if (!$sender) {
                echo "CRITICAL: All senders reached daily limit (400).\n";
                break 3;
            }

            // TRIGGER YOUR ANTI-SPAM MAILER LOGIC
            $status = $mailer->triggerEmailSending($email, $sender['id'], $lang);
            echo "Lead: $email | Sender: {$sender['id']} count: {$sender['count']} | Result: $status\n";
            $log_entry = $email . PHP_EOL;
            fwrite($archive_handle, $log_entry);

             
        }
    }

     
}

if (file_exists($checkpointFile)) unlink($checkpointFile);
$db->close();
$scraper->close();
echo "\n--- ALL TASKS COMPLETED ---\n";