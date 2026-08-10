'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

async function main() {
  const root = path.resolve(__dirname, '..');
  const outputDirectory = path.join(root, 'docs', 'screenshots');
  fs.mkdirSync(outputDirectory, { recursive: true });

  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
  const page = await context.newPage();

  try {
    await page.goto('http://localhost:8080/article?id=42&lang=pl', { waitUntil: 'networkidle' });
    const responseSection = page.locator('.zs-response-publications');
    await responseSection.waitFor({ state: 'visible' });
    await responseSection.screenshot({ path: path.join(outputDirectory, 'odpowiedz-publikacja-pod-artykulem.png') });

    await page.goto('http://localhost:8080/jak-zarabiac?lang=pl', { waitUntil: 'networkidle' });
    const economySection = page.locator('.economy-response-principle');
    await economySection.waitFor({ state: 'visible' });
    await economySection.screenshot({ path: path.join(outputDirectory, 'odpowiedz-publikacja-jak-zarabiac.png') });

    process.stdout.write('Response publication public screenshots: PASS\n');
  } finally {
    await context.close();
    await browser.close();
  }
}

main().catch((error) => {
  process.stderr.write(`Screenshot capture failed: ${error.message}\n`);
  process.exitCode = 1;
});
