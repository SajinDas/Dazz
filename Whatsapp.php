<?php
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// --- COMPANY & LOCATION DETAILS ---
$companyName = "Dazz Legacy Abroad Services";
$officeAddress = "2nd Floor, Sajj Complex, Kumarapuram, Trivandrum, Kerala 695011 \n\n\n https://www.instagram.com/dazzlegacy/ \n\n\n https://www.facebook.com/dazzlegacy/ \n\n\n ";
// Professional Google Maps link for your office location
$locationUrl = "https://maps.app.goo.gl/rD4EHdATzSmqvzHb6"; 

$companySignature = "\n\nRegards,\n"
                . "*$companyName*\n"
                . "$officeAddress\n"
                . "📍 Location: $locationUrl";

// --- THE JOB MESSAGES ---
$slovakiaMsg = "SLOVAKIA [ Warehouse Production ] \n\n"
             . "* Salary - 900€ + OT\n"
             . "* Processing time 4 - 6 months\n"
             . "* NO Interview, Basic English\n\n"
             . "DOCUMENTS REQUIRED:\n"
             . "* Passport Copy ( Full page)\n"
             . "* Euro pass CV in Word format\n"
             . "* EU size Digital Photo\n\n"
             . "* Service charge - 7L + Ticket\n"   
             . "* Advance - 65k";

$germanyMsg = "\n\n\n\nGERMANY [ WAREHOUSE Job ] \n\n"
            . "* Salary - 1700€ (AFTER TAX)\n"
            . "* Processing time 5- 6 months\n\n" 
            . "DOCUMENTS REQUIRED:\n"
            . "* Passport Copy ( front & back )\n"
            . "* Euro pass CV in Word format\n\n"
            . "* Service charge - 8.5L + Ticket\n"
             . "* Advance - 75k + Ticket\n"
            . "* After VISA - Balance Payment";



$AlbaniaMsg = "\n\n\n\nALBANIA [ WAREHOUSE Job ] \n\n"
            . "* Salary - 50000 (AFTER TAX)\n"
            . "* Free Food and accommodation\n"
            . "* Processing time 2 months\n\n" 
            . "DOCUMENTS REQUIRED:\n"
            . "* Passport Copy ( front & back )\n"
            . "* Euro pass CV in Word format\n\n"
            . "* Service charge - 3.75L + Ticket\n"
            . "* Advance - 25k + Ticket\n"
            . "* After VISA - Balance Payment";

// --- MAIN AUTOMATION LOOP ---
$excelFile = 'candidates.xlsx';
if (!file_exists($excelFile)) die("Excel file not found.");

$spreadsheet = IOFactory::load($excelFile);
$rows = $spreadsheet->getActiveSheet()->toArray();

echo "Initializing bulk recruitment outreach...\n";

foreach (array_slice($rows, 1) as $row) {
    $name  = $row[0]; 
    $phone = preg_replace('/[^0-9]/', '', $row[1]); 
    
    if (empty($phone)) continue;

    // Toggle between $slovakiaMsg or $germanyMsg here
    $fullMessage = "Hi,\n\n" . $slovakiaMsg . $germanyMsg . $AlbaniaMsg . $companySignature;
    
    $encodedMsg = rawurlencode($fullMessage);
    $whatsappUrl = "https://web.whatsapp.com/send?phone=$phone&text=$encodedMsg";

    echo "Opening thread for $name...\n";
    shell_exec("start \"\" \"$whatsappUrl\"");
    
    // Safety delay to allow page rendering
    sleep(100); 
}