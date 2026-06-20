<?php

/**
 * DAZZ LEGACY - RECRUITMENT PIPELINE
 * Profesia.sk Scraper for Warehouse & Production Roles
 */

$i = 1;
for ($page = 1; $page <= 50; $page++) {
     
  

$url = "https://www.profesia.sk/praca/plny-uvazok/?count_days=2&page_num=$page";

// 1. Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// Use a realistic User-Agent to avoid bot detection
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// Set a timeout for deep pagination pages
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$html = curl_exec($ch);

if (curl_errno($ch)) {
    die('Error: ' . curl_error($ch));
}

curl_close($ch);

// 2. Parse the HTML using DOMDocument and XPath
$dom = new DOMDocument();
// Use @ to suppress warnings from malformed HTML common in scrapers
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);

// 3. Target the job rows and extract links/titles
// We look for <a> tags inside <h2> which are inside the .list-row class
$jobs = [];
$nodes = $xpath->query("//li[contains(@class, 'list-row')]//h2/a");



foreach ($nodes as $node) {
 
    $title = trim($node->nodeValue);
    $link = $node->getAttribute('href');

    // Ensure link is absolute
    if (strpos($link, 'http') !== 0) {
        $link = "https://www.profesia.sk" . $link;
    }

    $jobs[] = [
        'title' => $title,
        'link' => $link
    ];
}

// 4. Output results for your pipeline
if (empty($jobs)) {
    echo "No jobs found. Check if the page structure or cookie banner has changed.";
} else {
    //echo "Successfully scraped " . count($jobs) . " jobs from Page 50:\n\n";
     
    foreach ($jobs as $job) {
        echo "<a href='" . $job['link'] . "' target='_blank'>" .$i." ". $job['title'] . "</a><br>";  
        $i++;
    }
}

} 