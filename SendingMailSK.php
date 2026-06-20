<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Mailersk.php';

// Import PhpSpreadsheet classes
use PhpOffice\PhpSpreadsheet\IOFactory;
use Dazz\Legacy\Config;
use Dazz\Legacy\Mailersk;

echo "=== BULK MAILING FROM EXCEL (.XLSX) STARTED ===\n";
set_time_limit(0);

try {
    $db = Config::getDB();
    $mailer = new MailerSk($db);
    
    // Path to your uploaded file
    $excelFile = 'Datas/Mailing/Tosent/Eures_Leads_2026-04-07_09-38.xlsx';
    $senderIds = [5, 4, 3, 1, 6, 2]; 
    $emailsSent = 0;

    if (!file_exists($excelFile)) {
        throw new Exception("Excel file not found at: " . $excelFile);
    }

    // Load the Excel file
    $spreadsheet = IOFactory::load($excelFile);
    $worksheet = $spreadsheet->getActiveSheet();
    $rows = $worksheet->toArray();

    // Remove the header row
    $headers = array_shift($rows);

    foreach ($rows as $row) {
        // Mapping based on your provided file structure:
        // [0] Email, [1] Company Name, [2] Contact Person, [3] Occupation/Title
        $email   = isset($row[0]) ? trim($row[0]) : '';
        $company = isset($row[1]) ? trim($row[1]) : 'Hiring Company';
        $contact = isset($row[2]) ? trim($row[2]) : 'Hiring Manager';
        $job     = isset($row[3]) ? trim($row[3]) : 'Skilled Position';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue; 
        }

        // Get an available sender (max 400 emails per sender)
        $sender = $mailer->getValidSender($senderIds, 400);
        if (!$sender) {
            echo "LIMIT REACHED: All senders exhausted.\n";
            break;
        }

        echo "Sending to: $email ($company)... ";

        // Trigger the email
        $status = $mailer->triggerEmailSending($email, $sender['id'], $company, $contact, $job);

        echo "Status: $status\n";

        if (strpos($status, 'SUCCESS') !== false) {
            $emailsSent++;
            // Random delay (20-30 seconds) to protect your sender reputation
            sleep(rand(20, 30));
        }
    }

    $db->close();
    echo "\n=== COMPLETED: $emailsSent emails sent successfully. ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}