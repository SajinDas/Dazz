<?php
namespace Dazz\Legacy;

use Playwright\Playwright;

class MultiCountryScraper {
    private $db;
    private $mailer;
    private $senderIds;

    public function __construct(\mysqli $db, Mailer $mailer, array $senderIds = [5, 4, 3, 2, 1, 6]) {
        $this->db        = $db;
        $this->mailer    = $mailer;
        $this->senderIds = $senderIds;
    }

    public function getEuresJobDetails($jobId) {
        $apiUrl = "https://europa.eu/eures/api/jv-searchengine/public/jv/id/" . urlencode($jobId);
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $response = curl_exec($ch);
        curl_close($ch);

        if (empty($response)) {
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['jvProfiles'])) {
            return null;
        }

        $profile = reset($data['jvProfiles']);
        $occupation  = $profile['title'] ?? 'Skilled Personnel';
        $companyName = $profile['employer']['name'] ?? 'Hiring Company';
        $phone       = $profile['employer']['phone'] ?? 'N/A';

        $contactPerson = 'Hiring Manager';
        if (!empty($profile['applicationInstructions'][0])) {
            $cleanInstruction = strip_tags($profile['applicationInstructions'][0]);
            $parts = explode(',', $cleanInstruction);
            $potentialName = trim(end($parts));
            if (strpos($potentialName, '@') === false && strlen($potentialName) > 2 && strlen($potentialName) < 60) {
                $contactPerson = $potentialName;
            }
        }

        preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10}/i', $response, $matches);
        $rawEmails = array_unique(array_map('strtolower', $matches[0] ?? []));
        $validEmails = array_values(array_filter($rawEmails, function ($e) {
            return !$this->mailer->isBlockedOrGovEmail($e);
        }));

        return [
            'job_id'         => $jobId,
            'occupation'     => $occupation,
            'company_name'   => $companyName,
            'contact_person' => $contactPerson,
            'emails'         => $validEmails,
            'phone'          => $phone
        ];
    }

    public function runCountryPipeline($countryCode, $maxEuresPages = 35, $minPosts = 2) {
        $countryCode = strtolower(trim($countryCode));
        $countries = Config::COUNTRIES;
        if (!isset($countries[$countryCode])) {
            throw new \Exception("Unsupported country code: $countryCode");
        }

        $meta = $countries[$countryCode];
        $lang = $meta['lang'];
        $countryName = $meta['name'];

        echo "====================================================================\n";
        echo " STARTING PIPELINE: {$countryName} ({$meta['pes_name']}) | Lang: {$lang}\n";
        echo "====================================================================\n";

        $reportDir  = 'Datas/Mailing/Reports/';
        $archiveDir = 'Datas/Mailing/Tosent/';
        if (!is_dir($reportDir)) mkdir($reportDir, 0777, true);
        if (!is_dir($archiveDir)) mkdir($archiveDir, 0777, true);

        $csvFile = $reportDir . ucfirst($countryCode) . '_Leads_' . date('Y-m-d_H-i') . '.csv';
        $csvHandle = fopen($csvFile, 'w');
        fprintf($csvHandle, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($csvHandle, [
            'Timestamp',
            'Country',
            'Source Portal',
            'Email Address',
            'Company Name',
            'Contact Person',
            'Occupation/Title',
            'Phone Number',
            'Language',
            'Mailing Status'
        ]);

        $txtFile = $archiveDir . date('Y-m-d_H-i') . "_{$countryCode}_leads.txt";
        $txtHandle = fopen($txtFile, 'a');

        $this->scrapeNationalPesPortal($countryCode, $meta, $csvHandle, $txtHandle);

        $scraper = new Scraper(Config::CHROME_PATH);
        $checkpointFile = __DIR__ . "/../checkpoint_{$countryCode}.txt";
        $startPage = file_exists($checkpointFile) ? (int)trim(file_get_contents($checkpointFile)) : 1;

        if ($startPage < 1 || $startPage > $maxEuresPages) {
            echo "[!] Resetting stale checkpoint ({$startPage}) to Page 1...\n";
            $startPage = 1;
        }

        for ($p = $startPage; $p <= $maxEuresPages; $p++) {
            file_put_contents($checkpointFile, $p);
            echo "\n[{$countryName} - EURES Page {$p}/{$maxEuresPages}] Fetching vacancies...\n";

            $euresUrl = "https://europa.eu/eures/portal/jv-se/search?page={$p}&resultsPerPage=50&orderBy=MOST_RECENT"
                      . "&locationCodes={$meta['eures_code']}"
                      . "&positionScheduleCodes=NS,flextime,fulltime,parttime"
                      . "&publicationPeriod=LAST_WEEK"
                      . "&escoIsco=C35,C6,C7,C8,C9,C54,C53,C51,C32"
                      . "&minNumberPost={$minPosts}&lang=en";

            $selector = "Array.from(document.links).map(l=>l.href).filter(h=>h&&h.includes('jv-details'))";
            $links = $scraper->scrapeLinks($euresUrl, $selector, 20, 'jv-details');

            if (empty($links)) {
                echo "[{$countryName}] No more EURES links found on page {$p}.\n";
                break;
            }

            echo "[{$countryName}] Found " . count($links) . " EURES vacancies on page {$p}.\n";

            foreach ($links as $link) {
                $jobId = explode('?', str_replace("https://europa.eu/eures/portal/jv-se/jv-details/", "", $link))[0];
                $job = $this->getEuresJobDetails($jobId);

                if (!$job || empty($job['emails'])) {
                    continue;
                }

                foreach ($job['emails'] as $email) {
                    $sender = $this->mailer->getValidSender($this->senderIds, Config::DAILY_SENDER_LIMIT);
                    if (!$sender) {
                        echo "CRITICAL: All senders reached daily limit (" . Config::DAILY_SENDER_LIMIT . ").\n";
                        break 3;
                    }

                    $status = $this->mailer->triggerEmailSending(
                        $email,
                        $sender['id'],
                        $lang,
                        $job['company_name'],
                        $job['contact_person'],
                        $job['occupation']
                    );

                    fputcsv($csvHandle, [
                        date('Y-m-d H:i:s'),
                        $countryName,
                        'EURES (' . strtoupper($countryCode) . ')',
                        $email,
                        $job['company_name'],
                        $job['contact_person'],
                        $job['occupation'],
                        $job['phone'],
                        $lang,
                        $status
                    ]);

                    fwrite($txtHandle, "{$email} | {$job['company_name']} | {$job['occupation']} | {$status}" . PHP_EOL);
                    echo "   >>> [{$countryName}] {$email} ({$job['company_name']} - {$job['occupation']}) -> {$status}\n";

                    if (strpos($status, 'Success') !== false) {
                        sleep(rand(20, 35));
                    }
                }
            }
        }

        if (file_exists($checkpointFile)) {
            unlink($checkpointFile);
        }
        $scraper->close();
        fclose($csvHandle);
        fclose($txtHandle);

        echo "\n--- COMPLETED {$countryName} PIPELINE. Report saved to: {$csvFile} ---\n";
    }

    private function scrapeNationalPesPortal($countryCode, array $meta, $csvHandle, $txtHandle) {
        $pesUrl = $meta['pes_url'];
        $lang = $meta['lang'];
        $countryName = $meta['name'];
        $linkFilter = $meta['link_filter'];

        echo "--- Opening National Portal: {$meta['pes_name']} ({$pesUrl}) ---\n";

        try {
            $browserContext = Playwright::chromium(['headless' => true]);
            $page = $browserContext->newPage();
            $page->goto($pesUrl, ['waitUntil' => 'networkidle', 'timeout' => 60000]);

            $page->evaluate("async () => {
                await new Promise((resolve) => {
                    let totalHeight = 0;
                    let distance = 400;
                    let steps = 0;
                    let timer = setInterval(() => {
                        window.scrollBy(0, distance);
                        totalHeight += distance;
                        steps++;
                        if (totalHeight >= document.body.scrollHeight || steps > 25) {
                            clearInterval(timer);
                            resolve();
                        }
                    }, 200);
                });
            }");

            sleep(3);

            $links = $page->evaluate("(filterStr) => {
                return Array.from(document.querySelectorAll('a[href]'))
                    .map(a => a.href)
                    .filter(href => href && href.includes(filterStr));
            }", [$linkFilter]);

            $uniqueLinks = array_values(array_unique(is_array($links) ? $links : []));
            echo "Found " . count($uniqueLinks) . " listings on {$meta['pes_name']}. Scanning detail pages...\n";

            foreach ($uniqueLinks as $idx => $subUrl) {
                echo "   [" . ($idx + 1) . "/" . count($uniqueLinks) . "] Scanning: {$subUrl}\n";
                try {
                    $page->goto($subUrl, ['waitUntil' => 'networkidle', 'timeout' => 30000]);

                    $page->evaluate("(btnLabels) => {
                        const elements = Array.from(document.querySelectorAll('button, a, span'));
                        for (const label of btnLabels) {
                            const btn = elements.find(el => el.innerText && el.innerText.includes(label));
                            if (btn) { btn.click(); }
                        }
                    }", [$meta['reveal_btns']]);
                    usleep(800000);

                    $pageData = $page->evaluate("() => {
                        const emails = new Set();
                        const regex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,10}/g;

                        // Escaped double quotes inside PHP string
                        document.querySelectorAll('a[href^=\"mailto:\"]').forEach(a => {
                            let e = a.href.replace('mailto:', '').split('?')[0].trim();
                            if (e.includes('@')) emails.add(e.toLowerCase());
                        });

                        const textMatches = document.body.innerText.match(regex);
                        if (textMatches) {
                            textMatches.forEach(e => emails.add(e.toLowerCase().trim()));
                        }

                        const h1 = document.querySelector('h1, h2, .job-title, .title');
                        const title = h1 ? h1.innerText.trim() : 'Skilled Personnel';

                        return {
                            title: title,
                            emails: Array.from(emails)
                        };
                    }");

                    $foundEmails = $pageData['emails'] ?? [];
                    $occupation  = !empty($pageData['title']) ? $pageData['title'] : 'Skilled Personnel';

                    foreach ($foundEmails as $email) {
                        if ($this->mailer->isBlockedOrGovEmail($email)) {
                            continue;
                        }

                        $sender = $this->mailer->getValidSender($this->senderIds, Config::DAILY_SENDER_LIMIT);
                        if (!$sender) {
                            echo "CRITICAL: Daily sender limits reached.\n";
                            break 2;
                        }

                        $status = $this->mailer->triggerEmailSending(
                            $email,
                            $sender['id'],
                            $lang,
                            'Hiring Company',
                            'Hiring Manager',
                            $occupation
                        );

                        fputcsv($csvHandle, [
                            date('Y-m-d H:i:s'),
                            $countryName,
                            $meta['pes_name'],
                            $email,
                            'Hiring Company',
                            'Hiring Manager',
                            $occupation,
                            'N/A',
                            $lang,
                            $status
                        ]);

                        fwrite($txtHandle, "{$email} | {$meta['pes_name']} | {$occupation} | {$status}" . PHP_EOL);
                        echo "      >>> Lead: {$email} | Status: {$status}\n";

                        if (strpos($status, 'Success') !== false) {
                            sleep(rand(20, 30));
                        }
                    }
                } catch (\Exception $subErr) {
                    echo "      [!] Detail Page Error: " . $subErr->getMessage() . "\n";
                }
            }

            $browserContext->close();
        } catch (\Exception $e) {
            echo "   [!] Direct PES Playwright scan skipped/fallback to EURES (" . $e->getMessage() . ")\n";
        }
    }
}
