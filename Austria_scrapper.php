<?php
$apiKey = "AQ.Ab8RN6I7CCQ_lly6uow9EYIvj1HwznsQXu2o0ZxmfFiFOxEKuw";
$url = "https://www.stepstone.at/jobs/in-austria";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
$html = curl_exec($ch);
curl_close($ch);

$dom = new DOMDocument();
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
$jobNodes = $xpath->query("//div[contains(@class, 'job-element')]");

foreach ($jobNodes as $node) {
    $titleNode = $xpath->query(".//a[contains(@class, 'job-element__title')]", $node)->item(0);
    $companyNode = $xpath->query(".//div[contains(@class, 'job-element__company')]", $node)->item(0);
    
    if ($titleNode) {
        $jobTitle = trim($titleNode->nodeValue);
        $companyName = $companyNode ? trim($companyNode->nodeValue) : 'N/A';
        
        $prompt = "Act as a recruitment agency. Based on the job title '{$jobTitle}' and the company '{$companyName}', write a professional email to the company offering our workforce solutions.";
        $data = ["contents" => [["parts" => [["text" => $prompt]]]]];
        
        $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
        $gc = curl_init($geminiUrl);
        curl_setopt($gc, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($gc, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($gc, CURLOPT_POSTFIELDS, json_encode($data));
        $geminiResponse = curl_exec($gc);
        curl_close($gc);
        
        $result = json_decode($geminiResponse, true);
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $emailDraft = $result['candidates'][0]['content']['parts'][0]['text'];
            
            echo "Processing Job: " . $jobTitle . "\n";
            echo "Generated Email Draft:\n" . $emailDraft . "\n\n";
            
        }
    }
}
?>