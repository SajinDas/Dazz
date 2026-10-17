<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Scraper.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Scraper;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

// --- 1. DATA EXTRACTION LOGIC ---
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
    if (!$data || !isset($data['jvProfiles'])) return null;

    $profile = reset($data['jvProfiles']); 

    $occupation = $profile['title'] ?? 'Skilled Personnel';
    $companyName = $profile['employer']['name'] ?? 'Hiring Company';

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
        'emails'         => $emails
    ];
}

// --- 2. EXCEL WRITER EXECUTION ---
echo "=== EURES JOB LEAD SCRAPER (EXCEL STORAGE) STARTED ===\n";
set_time_limit(0);

// Define folder paths
$finalFolder = __DIR__ . '/Datas/Mailing/excel/';
$tempFolder  = $finalFolder . 'temp/';

if (!is_dir($tempFolder)) {
    mkdir($tempFolder, 0777, true);
}

// Dynamic filename
$microtime = microtime(true);
$microSeconds = sprintf("%06d", ($microtime - floor($microtime)) * 1000000);
$dynamicFileName = date('dmyHis') . $microSeconds . '_leads.xlsx';

$tempExcelPath  = $tempFolder . $dynamicFileName;
$finalExcelPath = $finalFolder . $dynamicFileName;

echo "Temp Path: " . $tempExcelPath . "\n";

$checkpointFile = __DIR__ . '/checkpoint2.txt';
$scraper = null;

try {
    $scraper = new Scraper(Config::CHROME_PATH);
    
    $spreadsheet = null;
    $sheet = null;
    $highestRow = 1;

    $startPage = file_exists($checkpointFile) ? (int)file_get_contents($checkpointFile) : 1;
    $endPage = 204; 
    $leadsSaved = 0;

    for ($p = $startPage; $p <= $endPage; $p++) {
        file_put_contents($checkpointFile, $p);
        echo "\n[PAGINATION] Page $p...\n";
        
        $targetUrl = "https://europa.eu/eures/portal/jv-se/search?page=$p&resultsPerPage=50&orderBy=MOST_RECENT&locationCodes=sk&positionScheduleCodes=NS,flextime,fulltime,parttime&publicationPeriod=LAST_THREE_DAYS&escoIsco=C35,C6,C7,C8,C9&lang=en";
        $selector = "Array.from(document.links).map(l=>l.href).filter(h=>h&&h.includes('jv-details'))";
        $links = $scraper->scrapeLinks($targetUrl, $selector, 30);

        if (empty($links)) break;

        foreach ($links as $link) {
            $jobId = explode('?', str_replace("https://europa.eu/eures/portal/jv-se/jv-details/", "", $link))[0];
            $job = getJobDetails($jobId);

            if (!$job || empty($job['emails'])) continue;

            if ($spreadsheet === null) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('Leads Queue');
                
                $headers = ['Job ID', 'Company Name', 'Contact Person', 'Occupation Title', 'Target Email', 'Status', 'Date Scraped'];
                $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
                
                foreach ($headers as $index => $headerTitle) {
                    $sheet->setCellValue($columns[$index] . '1', $headerTitle);
                }
            }

            foreach ($job['emails'] as $email) {
                $highestRow++;
                $leadsSaved++;
                
                $sheet->setCellValue('A' . $highestRow, $jobId);
                $sheet->setCellValue('B' . $highestRow, $job['company_name']);
                $sheet->setCellValue('C' . $highestRow, $job['contact_person']);
                $sheet->setCellValue('D' . $highestRow, $job['occupation']);
                $sheet->setCellValue('E' . $highestRow, $email);
                $sheet->setCellValue('F' . $highestRow, 'Pending'); 
                $sheet->setCellValue('G' . $highestRow, date('Y-m-d H:i:s'));

                echo "Saved to Excel Row $highestRow: $email | Co: {$job['company_name']}\n";
            }
        }
        
        // Save incremental progress inside temp/ folder every 5 pages
        if ($spreadsheet !== null && $p % 5 === 0) {
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($tempExcelPath);
        }
    }

    // Final save & move execution
    if ($spreadsheet !== null) {
        // Save final file state to temp folder first
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempExcelPath);
        
        // Free memory handles to avoid file locks
        unset($sheet);
        unset($spreadsheet);
        gc_collect_cycles();

        // Move file from /temp/ to main /excel/ directory
        if (rename($tempExcelPath, $finalExcelPath)) {
            echo "\n=== SUCCESS: $leadsSaved LEADS SAVED. FILE MOVED TO: $finalExcelPath ===\n";
        } else {
            echo "\n=== WARNING: SCRAPING COMPLETED BUT COULD NOT MOVE FILE FROM TEMP. ===\n";
        }
    } else {
        echo "\n--- NOTICE: NO NEW LEADS FOUND. NO FILE CREATED. ---\n";
    }

    if (file_exists($checkpointFile)) {
        unlink($checkpointFile);
    }

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
} finally {
    if ($scraper) {
        $scraper->close();
    }
}