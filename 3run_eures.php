<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Scraper.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Scraper;

// --- 1. DIRECTORY & FILE SETUP ---
$finalFolder = __DIR__ . '/Datas/Mailing/textfile/';
$tempFolder  = $finalFolder . 'temp/';

if (!is_dir($tempFolder)) {
    mkdir($tempFolder, 0777, true);
}

// Generate dynamic filename with timestamp and microsecond precision
$microtime = microtime(true);
$microSeconds = sprintf("%06d", ($microtime - floor($microtime)) * 1000000);
$dynamicFileName = date('dmyHis') . $microSeconds . '_mail.txt';

$tempTextPath  = $tempFolder . $dynamicFileName;
$finalTextPath = $finalFolder . $dynamicFileName;

// --- 2. API LOGIC ---
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

// --- 3. MAIN EXECUTION ---
echo "=== EURES JOB LEAD SCRAPER STARTED ===\n";
set_time_limit(0);

$scraper = null;
$archive_handle = fopen($tempTextPath, 'a');
$leadsSavedCount = 0;

try {
    $scraper = new Scraper(Config::CHROME_PATH);
    $checkpointFile = __DIR__ . '/checkpoint.txt';

    echo "Temp text queue file target: " . $tempTextPath . "\n";

    // Checkpoint Logic
    $startPage = file_exists($checkpointFile) ? (int)file_get_contents($checkpointFile) : 1;
    $endPage = 202; 

    echo "--- SESSION START: PAGE $startPage ---\n";

    for ($p = $startPage; $p <= $endPage; $p++) {
        file_put_contents($checkpointFile, $p);
        echo "\n[PAGINATION] Accessing Page $p...\n";
        
        $targetUrl = "https://europa.eu/eures/portal/jv-se/search?page=$p&resultsPerPage=50&orderBy=MOST_RECENT&locationCodes=at,cy,cz,ee,el,es,fr,hr,lt,lv,mt,nl,pl,se,si,bg,it,hu,ro,pt&positionScheduleCodes=NS,flextime,fulltime,parttime&publicationPeriod=LAST_THREE_DAYS&escoIsco=C35,C6,C7,C8,C9,C54,C53,C51,C32&previousPageType=findJob&lang=en";
        
        $selector = "Array.from(document.links).map(l=>l.href).filter(h=>h&&h.includes('jv-details'))";
        $links = $scraper->scrapeLinks($targetUrl, $selector, 35);

        if (empty($links)) {
            echo "[END] No links found on Page $p.\n";
            break;
        }

        foreach ($links as $link) {
            $jobId = explode('?', str_replace("https://europa.eu/eures/portal/jv-se/jv-details/", "", $link))[0];

            $emails = getEmailsForJob($jobId);
            
            foreach ($emails as $email) {
                // Write lead directly to temp batch file
                $log_entry = $email . PHP_EOL;
                fwrite($archive_handle, $log_entry);
                $leadsSavedCount++;

                echo "Saved lead: $email\n";
            }
        }
    }

    if (file_exists($checkpointFile)) {
        unlink($checkpointFile);
    }

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
} finally {
    // 1. Flush and close text file handle
    if (is_resource($archive_handle)) {
        fclose($archive_handle);
    }

    // 2. Close Scraper instance
    if ($scraper) $scraper->close();

    // 3. Move file from /temp/ to final /textfile/ directory
    if ($leadsSavedCount > 0 && file_exists($tempTextPath)) {
        if (rename($tempTextPath, $finalTextPath)) {
            echo "\n=== SUCCESS: $leadsSavedCount LEADS LOGGED. FILE MOVED TO: $finalTextPath ===\n";
        } else {
            echo "\n=== WARNING: COULD NOT MOVE FILE FROM TEMP TO FINAL LOCATION. ===\n";
        }
    } else {
        if (file_exists($tempTextPath)) {
            unlink($tempTextPath); // Delete empty temp file
        }
        echo "\n--- NOTICE: NO LEADS SAVED. TEMP FILE REMOVED. ---\n";
    }
}

echo "\n--- ALL TASKS COMPLETED ---\n";