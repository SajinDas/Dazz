const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

(async () => {
    const jobId = process.argv[2];
    const browser = await puppeteer.launch({ headless: "new" });
    const page = await browser.newPage();
    
    try {
        // Go to the job page and wait for it to actually load the data
        await page.goto(`${jobId}`, { waitUntil: 'networkidle2' });
        
        // Extract the email from the page content
        const email = await page.evaluate(() => {
            const body = document.body.innerText;
            const match = body.match(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,4}/);
            return match ? match[0] : null;
        });

        console.log(email || "No email found");
    } catch (e) {
        console.log("Error");
    } finally {
        await browser.close();
    }
})();