<?php
namespace Dazz\Legacy;

use HeadlessChromium\BrowserFactory;

class Scraper {
    protected $browser;

    public function __construct($path) {
        $factory = new BrowserFactory($path);
        $this->browser = $factory->createBrowser([
            'headless'    => false, 
            'windowSize'  => [1400, 900],
            'customFlags' => ['--disable-blink-features=AutomationControlled', '--no-sandbox']
        ]);
    }

    public function scrapeLinks($url, $selector, $wait = 35) {
        try {
            $page = $this->browser->createPage();
            $page->navigate($url)->waitForNavigation('networkIdle', 60000); 
            
            echo "Waiting for Profesia to settle...\n";
            sleep(7); // Increased wait for the modal

            // MODAL KILLER: Click the button OR hide the overlay via CSS
            $page->evaluate("
                (function() {
                    const btn = Array.from(document.querySelectorAll('button, a')).find(el => 
                        el.innerText.includes('Prijať všetky') || el.innerText.includes('Accept all')
                    );
                    if (btn) { btn.click(); }
                    
                    // Force hide any remaining overlays that might block the selector
                    const overlay = document.querySelector('.qc-cmp2-container, #ncmp-consent-container, [class*=\"modal\"]');
                    if (overlay) { overlay.style.display = 'none'; }
                })()
            ");
            
            sleep(3); 

            $evaluation = $page->evaluate($selector);
            $results = $evaluation->getReturnValue();

            if (empty($results)) {
                $page->screenshot()->saveToFile('debug_screenshot.png');
                echo "[DEBUG] Captured screenshot because 0 links were found.\n";
            }

            $page->close();
            return is_array($results) ? array_unique($results) : [];
        } catch (\Exception $e) {
            echo "Scrape Error: " . $e->getMessage() . "\n";
            return [];
        }
    }

    public function close() {
        if ($this->browser) $this->browser->close();
    }
}