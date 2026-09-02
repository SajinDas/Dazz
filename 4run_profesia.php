<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Scraper.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Scraper;

/**
 * Uses the Headless Chromium instance to render the inner page
 * and extract emails via mailto links and regex matching.
 */
function extractEmailsViaBrowser($scraper, $jobUrl) {
    $selector = "(() => {
        let emails = [];
        
        // Strategy A: Clickable mailto links
        const links = document.querySelectorAll('a[href^=\"mailto:\"]');
        links.forEach(l => emails.push(l.href.replace('mailto:', '').split('?')[0].trim()));
        
        // Strategy B: Full-text page regex scan
        const bodyText = document.body.innerText;
        const matches = bodyText.match(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,8}/g);
        if (matches) {
            emails.push(...matches);
        }
        
        return emails;
    })()";

    $rawEmails = $scraper->scrapeLinks($jobUrl, $selector, 4);
    
    if (!empty($rawEmails)) {
        $validEmails = array_filter($rawEmails, function($email) {
            $email = strtolower(trim($email));
            return !preg_match('/\.(png|jpg|jpeg|gif|svg|webp|js|css|json)$/i', $email) 
                   && filter_var($email, FILTER_VALIDATE_EMAIL);
        });
        
        return array_values(array_unique($validEmails));
    }
    
    return [];
}

echo "=== STARTING SLUZBY GOV PURE SCRAPER ===\n";

set_time_limit(0);

// Setup Temp and Final Directory Paths
$finalFolder = __DIR__ . '/Datas/Mailing/textfile/';
$tempFolder  = $finalFolder . 'temp/';

if (!is_dir($tempFolder)) {
    mkdir($tempFolder, 0777, true);
}

// Generate dynamic timestamp filename
$microtime = microtime(true);
$microSeconds = sprintf("%06d", ($microtime - floor($microtime)) * 1000000);
$dynamicFileName = date('dmyHis') . $microSeconds . '_mail.txt';

$tempTextPath  = $tempFolder . $dynamicFileName;
$finalTextPath = $finalFolder . $dynamicFileName;

echo "Output target configured: " . $tempTextPath . "\n";

$archive_handle = fopen($tempTextPath, 'a');
$leadsSavedCount = 0;
$scraper = null;

try {
    $scraper = new Scraper(Config::CHROME_PATH);

    $pagecount = 10;

    for ($p = 1; $p <= $pagecount; $p++) {
        
        $targetUrl = "https://www.sluzbyzamestnanosti.gov.sk/pracovne-ponuky?pageNr=$p&pageSize=30&zdrojPonuky=VPM&pozadovanaZnalostSk=false";
        echo "\n[PAGINATION] Scraping Page $p: " . $targetUrl . "\n";

        $selector = "(() => {
            window.scrollTo(0, document.body.scrollHeight);
            
            const allLinks = Array.from(document.querySelectorAll('a'));
            
            const jobLinks = allLinks.filter(a => {
                const pattern = new RegExp('/pracovne-ponuky/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', 'i');
                return a.href && pattern.test(a.href);
            });
            
            return jobLinks.map(a => a.href);
        })()";

        $links = $scraper->scrapeLinks($targetUrl, $selector, 10);

        if (empty($links)) {
            echo "[!] No links found on page $p.\n";
            continue; 
        }

        echo "Found " . count($links) . " jobs. Extracting emails via Headless Browser...\n";

        foreach ($links as $link) {
            echo "Processing: " . basename($link) . "\n";
            
            $extractedEmails = extractEmailsViaBrowser($scraper, $link);
            
            if (!empty($extractedEmails)) {
                foreach ($extractedEmails as $email) {
                    
                    // Filter generic administrative domains
                    if (preg_match('/@upsvr\.gov\.sk|@sluzbyzamestnanosti\.sk/i', $email)) {
                        echo "   -> Skipped: Government Admin Email ($email)\n";
                        continue;
                    }

                    fwrite($archive_handle, $email . PHP_EOL);
                    $leadsSavedCount++;
                    echo "   -> Saved Lead: $email\n";
                }
            } else {
                echo "   -> No valid email found on detail page.\n";
            }
        }
    }
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
} finally {
    // 1. Flush and close text file handle
    if (is_resource($archive_handle)) {
        fclose($archive_handle);
    }

    // 2. Close scraper handle
    if ($scraper) {
        $scraper->close();
    }

    // 3. Move file from /temp/ to /textfile/ directory
    if ($leadsSavedCount > 0 && file_exists($tempTextPath)) {
        if (rename($tempTextPath, $finalTextPath)) {
            echo "\n=== SUCCESS: $leadsSavedCount LEADS SAVED. FILE MOVED TO: $finalTextPath ===\n";
        } else {
            echo "\n=== WARNING: COULD NOT MOVE FILE FROM TEMP TO FINAL LOCATION. ===\n";
        }
    } else {
        if (file_exists($tempTextPath)) {
            unlink($tempTextPath); // Clean up empty temp file
        }
        echo "\n--- NOTICE: NO LEADS CAPTURED. TEMP FILE CLEANED UP. ---\n";
    }
}

echo "\n--- SESSION COMPLETE ---\n";