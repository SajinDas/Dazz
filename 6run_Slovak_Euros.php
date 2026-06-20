<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Scraper.php';
require 'src/Mailersk.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Scraper;
use Dazz\Legacy\Mailersk;

// --- 1. HELPER FUNCTION: DATA EXTRACTION ---
function getJobDetails($jobId) {
    $apiUrl = "zxczxczxchttps://europa.eu/eures/api/jv-searchengine/public/jv/id/" . $jobId;
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch); 
    curl_close($ch);

    if (empty($response)) return null;
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['jvProfiles'])) return null;

    $profile = reset($data['jvProfiles']); 
    $occupation = $profile['title'] ?? 'Skilled Personnel';
    $companyName = $profile['employer']['name'] ?? 'Hiring Company';
    $Phone = $profile['employer']['phone'] ?? 'N/A';

    $contactPerson = 'Hiring Manager';
    if (!empty($profile['applicationInstructions'][0])) {
        $cleanInstruction = strip_tags($profile['applicationInstructions'][0]);
        $parts = explode(',', $cleanInstruction);
        $potentialName = trim(end($parts));
        if (strpos($potentialName, '@') === false && strlen($potentialName) > 2) {
            $contactPerson = $potentialName;
        }
    }

    preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,4}/i', $response, $matches);
    $emails = array_unique($matches[0] ?? []);

    return [
        'occupation'     => $occupation,
        'company_name'   => $companyName,
        'contact_person' => $contactPerson,
        'emails'         => $emails,
        'phone'          => $Phone
    ];
}

// --- 2. MAIN EXECUTION ---

echo "=== EURES JOB LEAD SCRAPER STARTED ===\n";
set_time_limit(0);

try {
    $db = Config::getDB();
    $scraper = new Scraper(Config::CHROME_PATH);
    $mailer = new MailerSk($db);
    $checkpointFile = __DIR__ . '/checkpoint2.txt';
    $senderIds = [5, 4, 3, 1, 6, 2]; 

    // --- EXCEL/CSV FILE SETUP ---
    $report_folder = 'Datas/Mailing/Reports/';
    if (!is_dir($report_folder)) mkdir($report_folder, 0777, true);
    
    // Create a new file for this specific run
    $excel_file = $report_folder . 'Eures_Leads_' . date('Y-m-d_H-i') . '.csv';
    $excel_handle = fopen($excel_file, 'w');

    // Add Byte Order Mark (BOM) to ensure Excel opens with correct UTF-8 encoding
    fprintf($excel_handle, chr(0xEF).chr(0xBB).chr(0xBF));

    // Define Excel Headers
    fputcsv($excel_handle, [
        'Time', 
        'Email Address', 
        'Company Name', 
        'Contact Person', 
        'Occupation/Title', 
        'Phone Number',
        'Mailing Status'
    ]);

    $startPage = file_exists($checkpointFile) ? (int)file_get_contents($checkpointFile) : 1;
    $endPage = 130; 

    for ($p = $startPage; $p <= $endPage; $p++) {
        file_put_contents($checkpointFile, $p);
        echo "\n[PAGINATION] Page $p...\n";
        
        $targetUrl = "https://europa.eu/eures/portal/jv-se/search?page=$p&resultsPerPage=50&orderBy=MOST_RECENT&locationCodes=sk&positionScheduleCodes=NS,flextime,fulltime,parttime&publicationPeriod=LAST_WEEK&escoIsco=C35,C6,C7,C8,C9&minNumberPost=4&lang=en";
        $selector = "Array.from(document.links).map(l=>l.href).filter(h=>h&&h.includes('jv-details'))";
        $links = $scraper->scrapeLinks($targetUrl, $selector, 30);

        if (empty($links)) break;

        foreach ($links as $link) {
            $jobId = explode('?', str_replace("https://europa.eu/eures/portal/jv-se/jv-details/", "", $link))[0];
            $job = getJobDetails($jobId);

            if (!$job || empty($job['emails'])) continue;

            foreach ($job['emails'] as $email) {
                $sender = $mailer->getValidSender($senderIds, 400);
                if (!$sender) {
                    echo "LIMIT REACHED: Senders exhausted.\n";
                    break 3;
                }

                $status = $mailer->triggerEmailSendasdading($email, $sender['id'], $job['company_name'], $job['contact_person'], $job['occupation']);
                //$status = "Test Status"; // Placeholder for actual mailing statuss

                // --- WRITE DATA TO EXCEL/CSV ---
                fputcsv($excel_handle, [
                    date('H:i:s'),
                    $email,
                    $job['company_name'],
                    $job['contact_person'],
                    $job['occupation'],
                    $job['phone'],
                    $status
                ]);

                if (strpos($status, 'Success') !== false) {
                    sleep(rand(20, 30));
                }

                echo "Saved: $email | Co: {$job['company_name']} | Status: $status\n";
            }
        }
    }

    fclose($excel_handle);
    if (file_exists($checkpointFile)) unlink($checkpointFile);
    $scraper->close();
    $db->close();
    echo "\n--- SUCCESS: DATA EXPORTED TO $excel_file ---\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
}