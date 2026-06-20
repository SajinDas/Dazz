<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Scraper.php';
require 'src/Mailersk.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Scraper;
use Dazz\Legacy\Mailersk;

// --- 1. HELPER FUNCTION: DATA EXTRACTION FROM EURES JSON ---

/**
 * Fetches and parses the specific EURES JSON structure you provided
 */
function getJobDetails($jobId) {
    $apiUrl = "https://europa.eu/eures/api/jv-searchengine/public/jv/id/" . $jobId;
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
    
    // Check if jvProfiles exists (as per your array output)
    if (!$data || !isset($data['jvProfiles'])) return null;

    // Get the first available language profile (sk, en, etc.)
    $profile = reset($data['jvProfiles']); 

    // Extract Occupation (Title)
    $occupation = $profile['title'] ?? 'Skilled Personnel';

    // Extract Company Name
    $companyName = $profile['employer']['name'] ?? 'Hiring Company';

    // Extract Contact Person from applicationInstructions
    $contactPerson = 'Hiring Manager';
    if (!empty($profile['applicationInstructions'][0])) {
        // Strip HTML tags and try to find the name after the last comma
        $cleanInstruction = strip_tags($profile['applicationInstructions'][0]);
        $parts = explode(',', $cleanInstruction);
        $potentialName = trim(end($parts));
        // Simple check to ensure we didn't just grab an email address
        if (strpos($potentialName, '@') === false && strlen($potentialName) > 2) {
            $contactPerson = $potentialName;
        }
    }

    // Extract Emails from the entire raw response string
    preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,4}/i', $response, $matches);
    $emails = array_unique($matches[0] ?? []);

    return [
        'occupation'     => $occupation,
        'company_name'   => $companyName,
        'contact_person' => $contactPerson,
        'emails'         => $emails
    ];
}

// --- 2. MAIN EXECUTION ---

echo "=== EURES JOB LEAD SCRAPER STARTED ===\n";
set_time_limit(0);

try {
    $db = Config::getDB();
    echo "=== DB CONNECTION ESTABLISHED ===\n";
    
    $scraper = new Scraper(Config::CHROME_PATH);
    $mailer = new MailerSk($db);
    $checkpointFile = __DIR__ . '/checkpoint2.txt';
    $senderIds = [1,2,3,4,5,6]; // Priority Sender List

    $archive_folder = 'Datas/Mailing/Tosent/';
    if (!is_dir($archive_folder)) mkdir($archive_folder, 0777, true);
    $archive_file = $archive_folder . date('Y-m-d_H-i') . '_leads.txt';
    $archive_handle = fopen($archive_file, 'a');

    $startPage = file_exists($checkpointFile) ? (int)file_get_contents($checkpointFile) : 1;
    $endPage = 204; 

    for ($p = $startPage; $p <= $endPage; $p++) {
        file_put_contents($checkpointFile, $p);
        echo "\n[PAGINATION] Page $p...\n";
        
        $targetUrl = "https://europa.eu/eures/portal/jv-se/search?page=$p&resultsPerPage=50&orderBy=MOST_RECENT&locationCodes=sk&positionScheduleCodes=NS,flextime,fulltime,parttime&publicationPeriod=LAST_THREE_DAYS&escoIsco=C35,C6,C7,C8,C9&minNumberPost=4&lang=en";

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
                    echo "LIMIT REACHED: All senders exhausted.\n";
                    break 3;
                }
                //

                

                // Call trigger with ALL collected metadata
               $status = $mailer->triggerEmailSending($email, $sender['id'],  $job['company_name'], $job['contact_person'], $job['occupation']);

               if (strpos($status, 'Success') !== false) {
                        sleep(rand(20, 30));
                    }

                echo "Lead: $email | Co: {$job['company_name']} |  | Contact Person : {$job['contact_person']} | Occupation: {$job['occupation']} |  \n status: $status \n";
                echo "---------------------------------------------\n\n";
                // Log detailed info to file
                $log_entry = date('H:i:s') . " | {$email} | {$job['company_name']} | {$job['contact_person'] } | Status: $status   \n";
                fwrite($archive_handle, $log_entry);
            }
        }
    }

    if (file_exists($checkpointFile)) unlink($checkpointFile);
    fclose($archive_handle);
    $scraper->close();
    $db->close();
    echo "\n--- SUCCESS: ALL PAGES PROCESSED ---\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
}