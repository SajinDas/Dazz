<?php
require 'vendor/autoload.php';
require 'src/Config.php';

use Dazz\Legacy\Config;
use Playwright\Playwright;

// --- INITIALIZATION ---
set_time_limit(0);

// Define folder paths
$finalFolder = __DIR__ . '/Datas/Mailing/textfile/';
$tempFolder  = $finalFolder . 'temp/';

if (!is_dir($tempFolder)) {
    mkdir($tempFolder, 0777, true);
}

// Dynamically naming file with timestamp and microtime suffix
$microtime = microtime(true);
$microSeconds = sprintf("%06d", ($microtime - floor($microtime)) * 1000000);
$dynamicFileName = date('dmyHis') . $microSeconds . '_leads.txt';

$tempTextPath  = $tempFolder . $dynamicFileName;
$finalTextPath = $finalFolder . $dynamicFileName;

echo "=== NVA PORTAL SCRAPER (TEXT QUEUE) STARTED ===\n";
echo "Temp target configured: " . $tempTextPath . "\n";

$archive_handle = fopen($tempTextPath, 'a');
$leadsSavedCount = 0;

try {
    $browserContext = Playwright::chromium(['headless' => true]); 
    $page = $browserContext->newPage();
} catch (Exception $e) {
    fclose($archive_handle);
    if (file_exists($tempTextPath)) unlink($tempTextPath);
    die("Fatal Error: Playwright failed to start.\n");
}

$targetUrl = "https://cvvp.nva.gov.lv/#/pub/vakances/saraksts#eyJvZmZzZXQiOjc1LCJsaW1pdCI6MjUsInBhZ2VZIjo0MDB9";

try {
    echo "--- Opening NVA Portal ---\n";
    $page->goto($targetUrl, ['waitUntil' => 'networkidle', 'timeout' => 90000]);
    
    echo "--- Scrolling to load leads ---\n";
    $page->evaluate("async () => {
        await new Promise((resolve) => {
            let totalHeight = 0;
            let distance = 400; 
            let timer = setInterval(() => {
                let scrollHeight = document.body.scrollHeight;
                window.scrollBy(0, distance);
                totalHeight += distance;
                if(totalHeight >= scrollHeight){
                    clearInterval(timer);
                    resolve();
                }
            }, 200); 
        });
    }");

    sleep(5); // Wait for the list to fully render

    $links = $page->evaluate("() => {
        return Array.from(document.querySelectorAll('a[href*=\"/pub/vakances/\"]'))
                    .map(a => a.href)
                    .filter(href => href.includes('/pub/vakances/') && !href.endsWith('/saraksts'));
    }");

    $uniqueLinks = array_unique($links);
    echo "Found " . count($uniqueLinks) . " total vacancies. Starting scan...\n\n";

    foreach ($uniqueLinks as $index => $subUrl) {
        echo "[" . ($index + 1) . "/" . count($uniqueLinks) . "] Scanning: $subUrl\n";
        
        try {
            $page->goto($subUrl, ['waitUntil' => 'networkidle', 'timeout' => 30000]);
            
            try {
                $page->waitForSelector('.form-group .value', ['timeout' => 5000]);
            } catch (Exception $e) {
                echo "    [!] Timeout: Page elements didn't load in time.\n";
            }

            // --- DATE EXTRACTION ---
            $dateInfo = $page->evaluate("() => {
                const result = { found: false, dateText: 'Not Found', diffDays: 999 };
                const groups = Array.from(document.querySelectorAll('.form-group'));
                const dateGroup = groups.find(g => g.innerText.includes('Publicēšanas datums'));
                
                if (dateGroup) {
                    const valueDiv = dateGroup.querySelector('.value');
                    if (valueDiv && valueDiv.innerText.trim() !== '') {
                        const match = valueDiv.innerText.match(/(\d{2}\.\d{2}\.\d{4})/);
                        if (match) {
                            result.found = true;
                            result.dateText = match[1];
                            
                            const [d, m, y] = match[1].split('.').map(Number);
                            const pubDate = new Date(y, m - 1, d);
                            const today = new Date();
                            today.setHours(0,0,0,0);
                            pubDate.setHours(0,0,0,0);

                            const diffTime = Math.abs(today - pubDate);
                            result.diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                        }
                    }
                }
                return result;
            }");

            echo "    [*] Page Date: " . $dateInfo['dateText'] . " (" . $dateInfo['diffDays'] . " days old)\n";

            if (!$dateInfo['found'] || $dateInfo['diffDays'] > 3) {
                echo "    [-] Skip: Out of range or Not Found.\n";
                continue;
            }

            // --- REVEAL EMAILS ---
            $page->evaluate("() => {
                const btn = Array.from(document.querySelectorAll('button, a, span'))
                                 .find(b => b.innerText.includes('Pieteikties') || b.innerText.includes('Rādīt'));
                if(btn) btn.click();
            }");
            usleep(1000000); 

            $foundEmails = $page->evaluate("() => {
                const emails = new Set();
                const regex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
                
                document.querySelectorAll('a[href^=\"mailto:\"]').forEach(a => {
                    let e = a.href.replace('mailto:', '').split('?')[0].trim();
                    if (e.includes('@')) emails.add(e.toLowerCase());
                });
                
                const textMatches = document.body.innerText.match(regex);
                if (textMatches) textMatches.forEach(e => emails.add(e.toLowerCase().trim()));
                
                return Array.from(emails);
            }");

            if (!empty($foundEmails)) {
                foreach ($foundEmails as $email) {
                    fwrite($archive_handle, $email . PHP_EOL);
                    $leadsSavedCount++;
                    echo "    [+] Saved to text queue: $email\n";
                }
            }
        } catch (Exception $subE) {
            echo "    [!] Error: " . $subE->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
} finally {
    // 1. Close file handle to ensure data is flushed
    if (is_resource($archive_handle)) {
        fclose($archive_handle);
    }
    
    // 2. Close Playwright context
    if (isset($browserContext)) {
        $browserContext->close();
    }

    // 3. File relocation logic
    if ($leadsSavedCount > 0 && file_exists($tempTextPath)) {
        if (rename($tempTextPath, $finalTextPath)) {
            echo "\n=== SUCCESS: $leadsSavedCount LEADS RECORDED. MOVED TO: $finalTextPath ===\n";
        } else {
            echo "\n=== WARNING: COULD NOT MOVE FILE FROM TEMP TO FINAL DESTINATION. ===\n";
        }
    } else {
        if (file_exists($tempTextPath)) {
            unlink($tempTextPath); // Clean up empty temp file
        }
        echo "\n--- NOTICE: NO LEADS CAPTURED. TEMP FILE CLEANED UP. ---\n";
    }
}