<?php
require 'vendor/autoload.php';
require 'src/Config.php';
require 'src/Mailer.php';

// --- CONFIGURATION ---
use Dazz\Legacy\Config;

$mysql_conn = Config::getDB();

// --- FILE PATHS ---
$txt_file = 'Datas/Blocked/BlockEmail.txt'; 
$archive_folder = 'Datas/Blocked/Archive/';
$archive_file = $archive_folder . date('hmi-Y-m-d') . '_Master_Archive_Blocks.txt'; // Single file for all history

// --- PROCESS TEXT FILE ---
if (!file_exists($txt_file)) {
    die("Text file not found at: $txt_file");
}

// Open for reading
if (($handle = fopen($txt_file, "r")) !== FALSE) {
    echo "--- STARTING BLOCK PROCESS ---\n";

    // Prepare DB update
    $stmt = $mysql_conn->prepare("UPDATE AgentsEmailTable SET Statuss = '2' where EmailId = ?");
   
    if (!is_dir($archive_folder)) mkdir($archive_folder, 0777, true);
    $archive_handle = fopen($archive_file, 'a');

    while (($line = fgets($handle)) !== FALSE) {
        $email = trim($line);

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // 1. Update Database
            $stmt->bind_param("s", $email);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                echo "[OK] $email blocked.\n";
            }

            // 2. Move content to Archive (Add timestamp for reference)
            $log_entry = date('Y-m-d H:i:s') . " | " . $email . PHP_EOL;
            fwrite($archive_handle, $log_entry);
        }
    }
    
    $stmt->close();
    fclose($handle);
    fclose($archive_handle);

    // --- CLEAR THE ORIGINAL FILE (DO NOT DELETE) ---
    // Opening with 'w' and immediately closing empties the file
    $clear_handle = fopen($txt_file, 'w');
    fclose($clear_handle);

    echo "\nSUCCESS: Database updated. Content moved to Master Archive. 'BlockEmail.txt' is now empty.\n";

} else {
    echo "Could not open the file.";
}

$mysql_conn->close();
?>