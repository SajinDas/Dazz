<?php
namespace Dazz\Legacy;

use HeadlessChromium\BrowserFactory;

class Scraper {
    protected $browser;
    protected $chromePath;

    public function __construct($path) {
        $this->chromePath = $path;
        try {
            $factory = new BrowserFactory($path);
            $this->browser = $factory->createBrowser([
                'headless'    => true,
                'windowSize'  => [1440, 900],
                'userAgent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'customFlags' => [
                    '--disable-blink-features=AutomationControlled',
                    '--no-sandbox',
                    '--disable-dev-shm-usage',
                    '--disable-gpu',
                    '--lang=en-US,en'
                ]
            ]);
        } catch (\Exception $e) {
            echo "[!] HeadlessChromium init warning: " . $e->getMessage() . " (Will use Node Stealth Bridge fallback)\n";
            $this->browser = null;
        }
    }

    public function scrapeLinks($url, $selector, $wait = 20, $substringFilter = '') {
        $cleanSelector = trim(preg_replace('/\s+/', ' ', $selector));

        if ($this->browser) {
            try {
                $page = $this->browser->createPage();
                $page->navigate($url)->waitForNavigation('networkIdle', 60000);
                sleep(max(5, (int)$wait));

                $page->evaluate("(function(){
                    const labels = ['Accept all', 'Accept', 'OK', 'Súhlasím', 'Prijať všetky', 'Rozumiem', 'Souhlasím', 'Piekrītu', 'Sutinku', 'Nõustun', 'Akceptuję', 'Acceptă', 'Elfogadom', 'Alle akzeptieren'];
                    const btns = Array.from(document.querySelectorAll('button, a, span'));
                    for (const lbl of labels) {
                        const b = btns.find(x => x.innerText && x.innerText.trim().includes(lbl));
                        if (b) { try { b.click(); } catch(e){} }
                    }
                    window.scrollBy(0, 800);
                })()");
                sleep(3);

                $evaluation = $page->evaluate($cleanSelector);
                $results = $evaluation->getReturnValue();
                $page->close();

                if (is_array($results) && !empty($results)) {
                    return array_values(array_unique($results));
                }
            } catch (\Exception $e) {
                echo "[!] HeadlessChromium Scrape Warning: " . $e->getMessage() . "\n";
            }
        }

        $bridgeScript = __DIR__ . '/../js/get_links.js';
        if (file_exists($bridgeScript)) {
            $filterArg = $substringFilter ?: (strpos($url, 'sluzbyzamestnanosti') !== false ? '/pracovne-ponuky/' : 'jv-details');
            $cmd = 'node ' . escapeshellarg($bridgeScript) . ' ' . escapeshellarg($url) . ' ' . escapeshellarg($filterArg) . ' 2>&1';
            $rawOut = shell_exec($cmd);
            if (!empty($rawOut)) {
                $decoded = json_decode(trim($rawOut), true);
                if (is_array($decoded) && !empty($decoded)) {
                    return array_values(array_unique($decoded));
                }
            }
        }

        return [];
    }

    public function close() {
        if ($this->browser) {
            try {
                $this->browser->close();
            } catch (\Exception $e) {}
        }
    }
}
