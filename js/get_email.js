const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

const BLOCKED_DOMAINS = [
    'upsvr.gov.sk', 'sluzbyzamestnanosti.sk', 'nva.gov.lv', 'uradprace.cz',
    'mpsv.cz', 'mpsv.gov.cz', 'up.gov.cz', 'ess.gov.si', 'hzz.hr', 'uzt.lt',
    'tootukassa.ee', 'eures.ee', 'praca.gov.pl', 'anofm.ro', 'munka.hu',
    'gov.mt', 'arbeitsagentur.de', 'europa.eu'
];

(async () => {
    const url = process.argv[2];
    if (!url) { process.stdout.write("No URL"); process.exit(1); }

    const browser = await puppeteer.launch({
        headless: "new",
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const page = await browser.newPage();

    try {
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
        await page.goto(url, { waitUntil: 'networkidle0', timeout: 60000 });
        await new Promise(r => setTimeout(r, 2000));

        const email = await page.evaluate((blockedList) => {
            const isBlocked = (addr) => blockedList.some(d => addr.toLowerCase().endsWith('@' + d) || addr.toLowerCase().includes(d));

            const mailtos = Array.from(document.querySelectorAll('a[href^="mailto:"]'));
            for (const m of mailtos) {
                const candidate = m.href.replace('mailto:', '').split('?')[0].trim().toLowerCase();
                if (candidate.includes('@') && !isBlocked(candidate)) {
                    return candidate;
                }
            }

            const bodyText = document.body.innerText;
            const matches = bodyText.match(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,10}/gi) || [];
            for (const raw of matches) {
                const found = raw.toLowerCase().trim();
                if (!isBlocked(found)) {
                    return found;
                }
            }
            return null;
        }, BLOCKED_DOMAINS);

        process.stdout.write(email || "No email found");
    } catch (e) {
        process.stdout.write("Error");
    } finally {
        await browser.close();
    }
})();
