<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

// Using the exact URL you provided
$url = "https://www.eures.sk/pracovne-ponuky/?profesie=&vzdelanie=&odvetvie=&druh_pp=pln%C3%BD%20%C3%BAv%C3%A4zok&absolvent=&zp=&pary=&ubytovanie=&zamestnavatel=&hladaj=&type=pracovne-ponuky&offset=0&page=1";

$client = new Client([
    'timeout'  => 15.0,
    'headers' => [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.5',
    ]
]);

try {
    echo "--- Fetching EURES Slovakia Leads ---\n";
    $response = $client->request('GET', $url);
    $html = (string) $response->getBody();
    
    $crawler = new Crawler($html);

    // TARGETING THE SPECIFIC LISTING SELECTORS
    // Most EURES layouts use 'article' or specific result classes like .box-ponuka or .job-card
    $jobs = $crawler->filter('.ponuka-item, .box-ponuka, article, .list-item')->each(function (Crawler $node) {
        
        $titleNode = $node->filter('h2, h3, .title, a[href*="detail"]');
        if ($titleNode->count() == 0) return null;

        $title = $titleNode->first()->text('');
        $link  = $node->filter('a')->first()->attr('href');
        
        // Extracting location/company if available
        $meta = $node->filter('.info, .sub-title, .location')->text('');
        
        return [
            'title' => trim($title),
            'url'   => strpos($link, 'http') === 0 ? $link : 'https://www.eures.sk' . $link,
            'info'  => trim(preg_replace('/\s+/', ' ', $meta))
        ];
    });

    // Remove null values
    $jobs = array_filter($jobs);

    if (empty($jobs)) {
        echo "[!] No jobs found. Saving debug_eures.html for inspection...\n";
        file_put_contents('debug_eures.html', $html);
        echo "Check debug_eures.html to see if the site is blocking us.\n";
    } else {
        $output_file = 'Datas/Mailing/Eures_Leads_' . date('Ymd_His') . '.txt';
        $content = "";

        foreach ($jobs as $job) {
            echo "Found: {$job['title']}\n";
            $content .= "TITLE: {$job['title']} | URL: {$job['url']} | META: {$job['info']}" . PHP_EOL;
        }

        if (!is_dir('Datas/Mailing/')) mkdir('Datas/Mailing/', 0777, true);
        file_put_contents($output_file, $content);
        echo "\nSUCCESS: Saved " . count($jobs) . " leads to $output_file\n";
    }

} catch (\Exception $e) {
    echo "CONNECTION ERROR: " . $e->getMessage() . "\n";
}