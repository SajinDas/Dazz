<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Mailer.php';

use Dazz\Legacy\Config;
use Dazz\Legacy\Mailer;

// --- CONFIGURATION ---
$source_folder  = 'Datas/Mailing/textfile/';
$archive_folder = 'Datas/Mailing/Archive/';
$archive_file   = $archive_folder . date('Y-m-d') . '_Master_Archive_Sent.txt';

$mysql_conn = Config::getDB();
$mailer     = new Mailer($mysql_conn);

// Base Senders Queue
$base_senders = [1,2,3,4,6,5];

// Seed PRNG with today's date (YYYYMMDD) for starting offset
srand((int)date('Ymd'));
$start_offset = rand(0, count($base_senders) - 1);
srand(); // Reset PRNG state for sleep delays

// Rotate array starting position for today
$sender_ids = array_merge(
    array_slice($base_senders, $start_offset),
    array_slice($base_senders, 0, $start_offset)
);

echo "Sender ID priority order for today (" . date('Y-m-d') . "): " . implode(', ', $sender_ids) . "\n";

if (!is_dir($archive_folder)) {
    mkdir($archive_folder, 0777, true);
}
$archive_handle = fopen($archive_file, 'a');

// 1. GET ALL .TXT FILES FROM SOURCE FOLDER
$files = glob($source_folder . "*.txt");

if (empty($files)) {
    fclose($archive_handle);
    $mysql_conn->close();
    die("No .txt files found in $source_folder.\n");
}

echo "--- Starting Bulk Send & File Cleanup ---\n";

foreach ($files as $current_file) {
    echo "\nProcessing File: " . basename($current_file) . "\n";
    
    $emails = file($current_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    if (empty($emails)) {
        echo "File is empty, skipping and deleting...\n";
        unlink($current_file);
        continue;
    }

    $remaining_emails = $emails;

    foreach ($emails as $recipient) {
        $recipient = trim($recipient);
        
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            array_shift($remaining_emails);
            continue;
        }

        $emailSentSuccessfully = false;
        $attemptedSendersCount = 0;
        $maxSenderAttempts = count($sender_ids);

        // --- RETRY LOOP FOR THE SAME LEAD ACROSS DIFFERENT SENDERS ---
        while (!$emailSentSuccessfully && $attemptedSendersCount < $maxSenderAttempts) {
            
            // Get valid sender (Capped at 80/day)
            $sender = $mailer->getValidSender($sender_ids, 400);

             // Debugging line to check the sender details
            echo "    [*] Selected Sender ID: {$sender['id']} with $sender[count] emails sent in the last 24 hours.\n";

            if (!$sender) {
                echo "[!] All senders exhausted daily limits (300). Saving queue state and stopping script.\n";
                file_put_contents($current_file, implode(PHP_EOL, $remaining_emails) . PHP_EOL);
                fclose($archive_handle);
                $mysql_conn->close();
                exit; 
            }

            $attemptedSendersCount++;

            // Trigger Send for this specific lead
            $status = $mailer->triggerEmailSending($recipient, $sender['id'], 'en');
            echo "Sending to: $recipient | Sender ID: {$sender['id']} | Status: $status\n";

            // Check if sending succeeded
            if (preg_match('/(Success|Skipped: Cooldown\/Blocked|Skipped: Blocked Domain)/i', $status)) {
                $emailSentSuccessfully = true;

                // LOG TO MASTER ARCHIVE
                $log_entry = date('Y-m-d H:i:s') . " | " . $recipient . " | File: " . basename($current_file) . " | SenderID: {$sender['id']} | Status: $status" . PHP_EOL;
                fwrite($archive_handle, $log_entry);

                // Rotate sender queue for NEXT lead (1 -> 2 -> 3 -> 4...)
                $first_sender = array_shift($sender_ids);
                $sender_ids[] = $first_sender;




                echo $status."\n\n\n\n";

                // Anti-Spam Sleep (30-50s)
                if (strpos($status, 'Success') !== false) {
                     $timeout = rand(10, 15);
                    echo "Sleeping for $timeout seconds to avoid spam detection...\n";
                    sleep($timeout);
                 
                }

            } else {
                // FAILURE / LIMIT REACHED ON THIS SENDER ID
                echo "   [!] Sender ID {$sender['id']} failed for $recipient. Rotating to next sender ID...\n";
                
                // Immediately rotate this failed sender to the back of the queue
                $failed_sender = array_shift($sender_ids);
                $sender_ids[] = $failed_sender;
            }
        }

        // Remove lead from queue only after success or attempting all senders
        array_shift($remaining_emails);
    }

    // 2. DELETE OR UPDATE FILE STATE
    if (empty($remaining_emails)) {
        if (unlink($current_file)) {
            echo "COMPLETED: " . basename($current_file) . " has been fully processed and deleted.\n";
        } else {
            echo "ERROR: Could not delete " . basename($current_file) . "\n";
        }
    } else {
        file_put_contents($current_file, implode(PHP_EOL, $remaining_emails) . PHP_EOL);
    }
}

fclose($archive_handle);
echo "\nALL FILES PROCESSED SUCCESSFULLY.\n";
$mysql_conn->close();