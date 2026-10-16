<?php
require 'vendor/autoload.php';
require_once 'src/Config.php';
require_once 'src/Scraper.php';
require_once 'src/MailerSk.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Scraper;
use Dazz\Legacy\MailerSk;

function getJobDetails($jobId) {
    $apiUrl = "https://europa.eu/eures/api/jv-searchengine/public/jv/id/" . urlencode($jobId);
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

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
    $occupation  = $profile['title'] ?? 'Skilled Personnel';
    $companyName = $profile['employer']['name'] ?? 'Hiring Company';
    $phone       = $profile['employer']['phone'] ?? 'N/A';

    $contactPerson = 'Hiring Manager';
    if (!empty($profile['applicationInstructions'][0])) {
        $cleanInstruction = strip_tags($profile['applicationInstructions'][0]);
        $parts = explode(',', $cleanInstruction);
        $potentialName = trim(end($parts));
        if (strpos($potentialName, '@') === false && strlen($potentialName) > 2) {
            $contactPerson = $potentialName;
        }
    }

    preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,10}/i', $response, $matches);
    $emails = array_unique($matches[0] ?? []);

    return [
        'occupation'     => $occupation,
        'company_name'   => $companyName,
        'contact_person' => $contactPerson,
        'emails'         => $emails,
        'phone'          => $phone
    ];
}

echo "=== EURES SLOVAKIA JOB LEAD SCRAPER STARTED ===\n";
set_time_limit(0);

try {
    $db = Config::getDB();
    $scraper = new Scraper(Config::CHROME_PATH);
    $mailer = new MailerSk($db);
    $checkpointFile = __DIR__ . '/checkpoint2.txt';
    $senderIds = [5, 4, 3, 1, 6, 2];

    $report_folder = 'Datas/Mailing/Reports/';
    if (!is_dir($report_folder)) mkdir($report_folder, 0777, true);

    $excel_file = $report_folder . 'Eures_Leads_' . date('Y-m-d_H-i') . '.csv';
    $excel_handle = fopen($excel_file, 'w');
    fprintf($excel_handle, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($excel_handle, [
        'Time',
        'Email Address',
        'Company Name',
        'Contact Person',
        'Occupation/Title',
        'Phone Number',
        'Mailing Status'
    ]);

    $startPage = file_exists($checkpointFile) ? (int)trim(file_get_contents($checkpointFile)) : 1;
    $endPage = 130;

    if ($startPage < 1 || $startPage > $endPage) {
        echo "[!] Resetting stale checkpoint2.txt value ($startPage) back to Page 1...\n";
        $startPage = 1;
    }

    for ($p = $startPage; $p <= $endPage; $p++) {
        file_put_contents($checkpointFile, $p);
        echo "\n[PAGINATION] Page $p of $endPage...\n";

        $targetUrl = "https://europa.eu/eures/portal/jv-se/search?page=$p&resultsPerPage=50&orderBy=MOST_RECENT&locationCodes=sk&positionScheduleCodes=NS,flextime,fulltime,parttime&publicationPeriod=LAST_WEEK&escoIsco=C35,C6,C7,C8,C9&minNumberPost=4&lang=en";
        $selector = "Array.from(document.links).map(l=>l.href).filter(h=>h&&h.includes('jv-details'))";
        $links = $scraper->scrapeLinks($targetUrl, $selector, 20, 'jv-details');

        if (empty($links)) {
            echo "[!] No more EURES Slovakia links found on Page $p.\n";
            break;
        }

        echo "Found " . count($links) . " vacancies on Page $p. Extracting employer contacts...\n";

        foreach ($links as $link) {
            $jobId = explode('?', str_replace("https://europa.eu/eures/portal/jv-se/jv-details/", "", $link))[0];
            $job = getJobDetails($jobId);

            if (!$job || empty($job['emails'])) continue;

            foreach ($job['emails'] as $email) {
                if ($mailer->isBlockedOrGovEmail($email)) continue;

                $sender = $mailer->getValidSender($senderIds, 400);
                if (!$sender) {
                    echo "LIMIT REACHED: Senders exhausted.\n";
                    break 3;
                }

                $status = $mailer->triggerEmailSending($email, $sender['id'], $job['company_name'], $job['contact_person'], $job['occupation'], 'sk');

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
