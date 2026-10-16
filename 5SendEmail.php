<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Mailer.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Mailer;

// --- CONFIGURATION ---
$source_folder = 'Datas/Mailing/Tosent/';
$archive_folder = 'Datas/Mailing/Archive/';
$archive_file = $archive_folder . date('hmi-Y-m-d') . 'Master_Archive_Sent.txt';

$mysql_conn = Config::getDB();
$mailer = new Mailer($mysql_conn);
$sender_ids = [ 2, 3, 4, 5, 6, 1];

if (!is_dir($archive_folder)) mkdir($archive_folder, 0777, true);
$archive_handle = fopen($archive_file, 'a');

// 1. GET ALL .TXT FILES FROM THE FOLDER
$files = glob($source_folder . "*.txt");

if (empty($files)) {
    die("No .txt files found in $source_folder.\n");
}

echo "--- Starting Bulk Send & File Cleanup ---\n";

foreach ($files as $current_file) {
    echo "\nProcessing File: " . basename($current_file) . "\n";
    
    // Read the current file
    $emails = file($current_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    if (empty($emails)) {
        echo "File is empty, skipping...\n";
        unlink($current_file); // Delete empty file
        continue;
    }

    foreach ($emails as $recipient) {
        $recipient = trim($recipient);
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) continue;

        // Fetch valid sender (Limit 400/day)
        $sender = $mailer->getValidSender($sender_ids, 400);
        if (!$sender) {
            echo "[!] Daily limits reached. Stopping script.\n";
            fclose($archive_handle);
            $mysql_conn->close();
            exit; 
        }

        // Trigger Send
        $status = $mailer->triggerEmailSending($recipient, $sender['id'], 'en');
        echo "Sending to: $recipient | Status: $status\n";

        // LOG TO MASTER ARCHIVE
        $log_entry = date('Y-m-d H:i:s') . " | " . $recipient . " | File: " . basename($current_file) . " | SenderID: {$sender['id']} | Status: $status" . PHP_EOL;
        fwrite($archive_handle, $log_entry);

         
    }

    // 2. DELETE THE FILE ONCE ALL EMAILS INSIDE ARE PROCESSED
    if (unlink($current_file)) {
        echo "COMPLETED: " . basename($current_file) . " has been deleted.\n";
    } else {
        echo "ERROR: Could not delete " . basename($current_file) . "\n";
    }
}

fclose($archive_handle);
echo "\nALL FILES PROCESSED SUCCESSFULLY.\n";
$mysql_conn->close();