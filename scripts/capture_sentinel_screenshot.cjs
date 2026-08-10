'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');

function localEnvironment() {
  const file = path.join(root, '.env');
  if (!fs.existsSync(file)) {
    throw new Error('Local .env is required only for authenticated local screenshot capture.');
  }
  const values = {};
  for (const rawLine of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const line = rawLine.trim();
    if (line === '' || line.startsWith('#')) continue;
    const match = /^([A-Z][A-Z0-9_]*)=(.*)$/.exec(line);
    if (!match) continue;
    let value = match[2].trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    values[match[1]] = value;
  }
  return values;
}

async function main() {
  const environment = localEnvironment();
  const adminEmail = process.env.SCREENSHOT_ADMIN_EMAIL || environment.ADMIN_EMAIL;
  const adminPassword = process.env.SCREENSHOT_ADMIN_PASSWORD || environment.ADMIN_PASSWORD;
  if (!adminEmail || !adminPassword) {
    throw new Error('ADMIN_EMAIL and ADMIN_PASSWORD must be set in the local environment.');
  }

  const outputDirectory = path.join(root, 'docs', 'screenshots');
  fs.mkdirSync(outputDirectory, { recursive: true });
  const executablePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
  const browser = await chromium.launch({ headless: true, executablePath });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1050 }, deviceScaleFactor: 1 });
  const page = await context.newPage();
  try {
    await page.goto('http://localhost:8080/pl/login', { waitUntil: 'networkidle' });
    await page.locator('input[name="login"]').fill(adminEmail);
    await page.locator('input[name="password"]').fill(adminPassword);
    await Promise.all([
      page.waitForLoadState('networkidle'),
      page.locator('button[type="submit"]').click(),
    ]);
    if (page.url().includes('/login') || page.url().includes('/two-factor')) {
      throw new Error('Local administrator authentication did not reach the administration panel.');
    }

    for (const language of ['pl', 'en']) {
      await page.goto(`http://localhost:8080/admin/security/sentinel?lang=${language}`, { waitUntil: 'networkidle' });
      await page.locator('.zs-sentinel-page').waitFor({ state: 'visible' });
      await page.screenshot({
        path: path.join(outputDirectory, `3dors-wartownik-${language}.png`),
        fullPage: true,
      });
    }
    process.stdout.write('3DORS Wartownik screenshots: PASS\n');
  } finally {
    await context.close();
    await browser.close();
  }
}

main().catch((error) => {
  process.stderr.write(`Screenshot capture failed: ${error.message}\n`);
  process.exitCode = 1;
});
