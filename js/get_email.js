const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

(async () => {
    const url = process.argv[2];
    if (!url) { process.stdout.write("No URL"); process.exit(1); }

    const browser = await puppeteer.launch({ 
        headless: "new",
        args: ['--no-sandbox', '--disable-setuid-sandbox'] 
    });
    
    const page = await browser.newPage();
    
    try {
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');

        // Wait for 'networkidle0' to ensure the contact API responds
        await page.goto(url, { waitUntil: 'networkidle0', timeout: 60000 });
        
        // Final buffer for the portal's dynamic content
        await new Promise(r => setTimeout(r, 2000));

        const email = await page.evaluate(() => {
            // Strategy 1: Check mailto links (highest accuracy)
            const mailto = document.querySelector('a[href^="mailto:"]');
            if (mailto) return mailto.href.replace('mailto:', '').split('?')[0];

            // Strategy 2: Regex scan of the text
            const bodyText = document.body.innerText;
            const match = bodyText.match(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,4}/i);
            
            if (match) {
                const found = match[0].toLowerCase();
                // Avoid portal system addresses
                if (!found.includes('upsvr.gov.sk')) return found;
            }
            return null;
        });

        process.stdout.write(email || "No email found");
    } catch (e) {
        process.stdout.write("Error");
    } finally {
        await browser.close();
    }
})();