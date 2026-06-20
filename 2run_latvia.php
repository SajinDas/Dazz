<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Mailer.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Mailer;
use Playwright\Playwright;

// --- INITIALIZATION ---
set_time_limit(0);
$db = Config::getDB();
$mailer = new Mailer($db);
$senderIds = [5, 4, 3, 2, 1, 6]; 
$lang = 'lv'; 

$archive_folder = 'Datas/Mailing/Tosent/';
$archive_file = $archive_folder . date('hmi-Y-m-d') . 'mail.txt';
if (!is_dir($archive_folder)) mkdir($archive_folder, 0777, true);
$archive_handle = fopen($archive_file, 'a');

try {
    $browserContext = Playwright::chromium(['headless' => true]); 
    $page = $browserContext->newPage();
} catch (Exception $e) {
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
            
            // --- NEW: WAIT FOR THE DATA TO APPEAR ---
            // This waits specifically for the '.value' class from your screenshot
            try {
                $page->waitForSelector('.form-group .value', ['timeout' => 5000]);
            } catch (Exception $e) {
                echo "    [!] Timeout: Page elements didn't load in time.\n";
            }

            // --- DATE EXTRACTION ---
            $dateInfo = $page->evaluate("() => {
                const result = { found: false, dateText: 'Not Found', diffDays: 999 };
                
                // Find all groups
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
                    $sender = $mailer->getValidSender($senderIds, 400);
                    if (!$sender) {
                        echo "CRITICAL: Daily limits reached.\n";
                        break 2;
                    }

                    $status = $mailer->triggerEmailSending($email, $sender['id'], $lang);
                    echo "    >>> Lead: $email | Result: $status | Sender: " . $sender['id'] . " | Count: " . $sender['count'] . "\n";
                    
                    fwrite($archive_handle, $email. PHP_EOL);

                    
                }
            }
        } catch (Exception $subE) {
            echo "    [!] Error: " . $subE->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
} finally {
    fclose($archive_handle);
    $db->close();
    $browserContext->close();
}