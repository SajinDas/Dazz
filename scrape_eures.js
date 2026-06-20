const { chromium } = require('playwright-stealth'); // Using stealth to bypass bot detection

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  
  // Set a realistic User Agent
  await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');

  console.log("Navigating to EURES Slovakia...");
  await page.goto('https://www.eures.sk/pracovne-ponuky/?profesie=&vzdelanie=&odvetvie=&druh_pp=pln%C3%BD%20%C3%BAv%C3%A4zok&absolvent=&zp=&pary=&ubytovanie=&zamestnavatel=&hladaj=india&type=pracovne-ponuky', { waitUntil: 'networkidle' });

  // Wait for the job boxes to appear
  await page.waitForSelector('.box.ponuka');

  const jobs = await page.evaluate(() => {
    const results = [];
    document.querySelectorAll('.box.ponuka').forEach(node => {
      results.push({
        title: node.querySelector('h2')?.innerText.trim(),
        link: node.querySelector('h2 a')?.href,
        company: node.querySelector('h3')?.innerText.trim(),
        salary: node.querySelector('.uv-mzda')?.innerText.trim() || 'Not specified'
      });
    });
    var_dump(results);
    return results;
  });

  console.log(`Found ${jobs.length} jobs:`);
  console.log(jobs);

  // Save to your Datas/Mailing folder for your PHP mailer
  const fs = require('fs');
  const content = jobs.map(j => `TITLE: ${j.title} | COMPANY: ${j.company} | SALARY: ${j.salary} | URL: ${j.link}`).join('\n');
  fs.writeFileSync('Datas/Mailing/Eures_India_Leads.txt', content);

  await browser.close();
})();   