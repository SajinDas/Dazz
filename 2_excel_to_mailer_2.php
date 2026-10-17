<?php
// 2_excel_to_mailer.php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Mailersk.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Mailersk;
use PhpOffice\PhpSpreadsheet\IOFactory;

echo "=== EXCEL COLD EMAIL ENGINE STARTED ===\n";
set_time_limit(0);

$excelFolder = __DIR__ . '/Datas/Mailing/excel/130826191357817753_leads.xlsx';

if (!is_file($excelFolder)) {
    die("ERROR: The target excel file does not exist ($excelFolder). Run the scraper first.\n");
}

$files = [$excelFolder];

if (empty($files)) {
    die("ERROR: No Excel lead files found in $excelFolder. Please populate data using the scraper first.\n");
}

// Sort files by modification time (pick the latest generated file)
usort($files, function($a, $b) {
    return filemtime($a) <=> filemtime($b);
});

$excelPath = end($files);
echo "Targeting latest lead file: " . basename($excelPath) . "\n";

$db = null;
$processSuccessful = false; 

try {
    $db = Config::getDB();
    echo "=== DB CONNECTION ESTABLISHED ===\n";
    
    $mailer = new MailerSk($db);

    // --- DAILY SEQUENTIAL SENDER ROTATION ---
    $baseSenderIds = [1,2, 3, 4, 5, 6]; 
    
    // Seed PRNG with today's date (YYYYMMDD) for daily starting offset
    srand((int)date('Ymd'));
    $startOffset = rand(0, count($baseSenderIds) - 1);
    srand(); // Reset PRNG state for sleep delays

    // Rotate starting position for today while maintaining sequence order
    $senderIds = array_merge(
        array_slice($baseSenderIds, $startOffset),
        array_slice($baseSenderIds, 0, $startOffset)
    );

    echo "Sender ID priority order for today (" . date('Y-m-d') . "): " . implode(', ', $senderIds) . "\n";

    $spreadsheet = IOFactory::load($excelPath);
    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestRow();

    $emailsSentThisSession = 0;
    $allSendersExhausted = false;

    for ($row = 2; $row <= $highestRow; $row++) {

        $status = trim($sheet->getCell('F' . $row)->getValue());

        if (strcasecmp($status, 'Pending') !== 0) {
            continue;
        }

        $companyName   = $sheet->getCell('B' . $row)->getValue();
        $contactPerson = $sheet->getCell('C' . $row)->getValue();
        $occupation    = $sheet->getCell('D' . $row)->getValue();
        $email         = $sheet->getCell('E' . $row)->getValue();

        if (empty($email)) continue;

        $emailSentSuccessfully = false;
        $attemptedSendersCount = 0;
        $maxSenderAttempts = count($senderIds);
        $lastMailStatus = '';

        // --- RETRY LOOP ACROSS SENDER IDs FOR THE SAME LEAD ---
        while (!$emailSentSuccessfully && $attemptedSendersCount < $maxSenderAttempts) {

            // Capped at 200/day to stay within Gmail's strict SMTP threshold
            $sender = $mailer->getValidSender($senderIds, 400);
            if (!$sender) {
                echo "LIMIT REACHED: All senders exhausted daily limit (300).\n";
                $allSendersExhausted = true;
                break 2; // Break out of retry loop AND row processing loop
            }

            $attemptedSendersCount++;

            $mailStatus = $mailer->triggerEmailSending($email, $sender['id'], $companyName, $contactPerson, $occupation);
            $lastMailStatus = $mailStatus;

            echo $mailStatus . " | Email: " . $email . " | Sender ID: " . $sender['id'] . " | Company: " . $companyName . " | Contact: " . $contactPerson . " | Occupation: " . $occupation . "\n";
            
            if (preg_match('/(Success|Skipped: Cooldown\/Blocked)/i', $mailStatus)) {
                $emailSentSuccessfully = true;
                $sheet->setCellValue('F' . $row, 'Success');
                echo "Row $row: Lead: $email | Status: Sent Success (Sender ID: {$sender['id']})\n\n\n";
                $emailsSentThisSession++;
                
                // Rotate sender queue so the NEXT email uses the next Sender ID in sequence (1 -> 2 -> 3 -> 4...)
                $firstSender = array_shift($senderIds);
                $senderIds[] = $firstSender;

                // Save progress every 5 sends
                if ($emailsSentThisSession > 0 && $emailsSentThisSession % 5 === 0) {
                    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                    $writer->save($excelPath);
                }


                echo $mailStatus."\n\n\n\n\n";

                // Anti-Spam Human Delay (30-50s)
                if (strpos($mailStatus, 'Success') !== false) {
                sleep(rand(20, 40));
                }
            } else {
                echo "   [!] Sender ID {$sender['id']} failed for $email. Retrying lead with next sender...\n\n\n";
                
                // Move the failed sender to the end of the queue and try the next one on the same lead
                $failedSender = array_shift($senderIds);
                $senderIds[]  = $failedSender;
            }
        }

        // If after trying all available senders it still fails, mark row as Failed
        if (!$emailSentSuccessfully && !$allSendersExhausted) {
            $sheet->setCellValue('F' . $row, 'Failed (' . substr($lastMailStatus, 0, 30) . ')');
            echo "Row $row: Lead: $email | Status: Dispatch Failed after trying all available senders.\n";
        }
    }

    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save($excelPath);

    // Scan for any remaining pending rows
    $hasPendingRows = false;
    for ($checkRow = 2; $checkRow <= $highestRow; $checkRow++) {
        if (strcasecmp(trim($sheet->getCell('F' . $checkRow)->getValue()), 'Pending') === 0) {
            $hasPendingRows = true;
            break;
        }
    }

    if (!$hasPendingRows && !$allSendersExhausted) {
        $processSuccessful = true;
        echo "\n--- SUCCESS: RUN COMPLETED ($emailsSentThisSession successfully dispatched, all rows processed) ---\n";
    } else {
        echo "\n--- NOTICE: RUN PAUSED ($emailsSentThisSession dispatched, pending rows remaining for next cycle) ---\n";
    }

} catch (Exception $e) {
    echo "FATAL EMAIL PROCESSING ERROR: " . $e->getMessage() . "\n";
} finally {
    if ($db) {
        $db->close();
    }
    
    if ($processSuccessful && file_exists($excelPath)) {
        unset($sheet);
        unset($spreadsheet);
        gc_collect_cycles(); 
        
        if (unlink($excelPath)) {
            echo "=== CLEANUP: File " . basename($excelPath) . " successfully deleted from the folder. ===\n\n\n\n\n\n\n\n\n\n\n";
        } else {
            echo "=== WARNING: Could not delete file " . basename($excelPath) . ". Check file permissions. ===\n";
        }
    } else {
        echo "=== NOTICE: File retained for next cycle. ===\n";
    }
}