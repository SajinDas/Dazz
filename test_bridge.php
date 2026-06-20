<?php
$testUrl = "https://www.sluzbyzamestnanosti.gov.sk/pracovne-ponuky/detail/207faacc-7a43-4a21-a0a0-a41046956a23";

echo "Verifying bridge to js/get_email.js...\n";

$command = 'node js/get_email.js ' . escapeshellarg($testUrl);
$output = shell_exec($command);

echo "Output from Node: " . $output . "\n";

if (filter_var(trim($output), FILTER_VALIDATE_EMAIL)) {
    echo "RESULT: SUCCESS! Bridge is active.\n";
} else {
    echo "RESULT: FAILED. Check if 'node_modules' is in the root folder.\n";
}