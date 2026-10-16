const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

(async () => {
    const url = process.argv[2];
    const filterSubstr = process.argv[3] || '';
    if (!url) {
        process.stdout.write("[]");
        process.exit(0);
    }

    const browser = await puppeteer.launch({
        headless: "new",
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-blink-features=AutomationControlled',
            '--window-size=1440,900'
        ]
    });

    const page = await browser.newPage();
    try {
        await page.setViewport({ width: 1440, height: 900 });
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
        await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });

        await new Promise(r => setTimeout(r, 6000));

        await page.evaluate(async () => {
            const labels = ['Accept all', 'Súhlasím', 'Prijať všetky', 'Rozumiem', 'Souhlasím', 'OK', 'Accept'];
            const btns = Array.from(document.querySelectorAll('button, a'));
            for (const lbl of labels) {
                const b = btns.find(el => el.innerText && el.innerText.includes(lbl));
                if (b) { try { b.click(); } catch (e) {} }
            }
            for (let i = 0; i < 6; i++) {
                window.scrollBy(0, 500);
                await new Promise(r => setTimeout(r, 300));
            }
        });

        await new Promise(r => setTimeout(r, 2500));

        const links = await page.evaluate((substr) => {
            const allHref = Array.from(document.querySelectorAll('a[href]')).map(a => a.href);
            if (!substr) return allHref;
            return allHref.filter(h => {
                if (!h || !h.includes(substr)) return false;
                if (substr === '/pracovne-ponuky/' && (h.includes('?pageNr=') || h.endsWith('/pracovne-ponuky') || h.endsWith('/pracovne-ponuky/'))) {
                    return false;
                }
                return true;
            });
        }, filterSubstr);

        const unique = Array.from(new Set(links));
        process.stdout.write(JSON.stringify(unique));
    } catch (err) {
        process.stdout.write("[]");
    } finally {
        await browser.close();
    }
})();
