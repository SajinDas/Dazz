<?php
namespace Dazz\Legacy;

use HeadlessChromium\BrowserFactory;

class Scraper {
    protected $browser;

    public function __construct($path) {
        $factory = new BrowserFactory($path);
        $this->browser = $factory->createBrowser([
            'headless'    => true, 
            'windowSize'  => [1400, 900],
            'customFlags' => ['--disable-blink-features=AutomationControlled', '--no-sandbox']
        ]);
    }

    public function scrapeLinks($url, $selector, $wait = 35) {
        try {
            $page = $this->browser->createPage();
            $page->navigate($url)->waitForNavigation('networkIdle', 60000); 
            sleep($wait);
            $page->evaluate("(function(){const b=Array.from(document.querySelectorAll('button,a')).find(x=>x.innerText.includes('Accept all')||x.innerText.includes('OK'));if(b)b.click();})()");
            $evaluation = $page->evaluate($selector);
            $results = $evaluation->getReturnValue();
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