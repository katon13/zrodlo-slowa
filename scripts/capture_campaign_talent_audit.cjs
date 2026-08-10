'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');

function localEnvironment() {
  const values = {};
  const file = path.join(root, '.env');
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
  const email = process.env.SCREENSHOT_ADMIN_EMAIL || environment.ADMIN_EMAIL;
  const password = process.env.SCREENSHOT_ADMIN_PASSWORD || environment.ADMIN_PASSWORD;
  if (!email || !password) throw new Error('Brak lokalnych danych administratora do kontroli wizualnej.');

  const output = path.join(root, 'docs', 'screenshots', 'kampanie-talent-final');
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  });
  const context = await browser.newContext({ viewport: { width: 1600, height: 1100 }, deviceScaleFactor: 1 });
  const page = await context.newPage();

  try {
    await page.goto('http://localhost:8080/pl/login', { waitUntil: 'networkidle' });
    await page.locator('input[name="login"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await Promise.all([
      page.waitForLoadState('networkidle'),
      page.locator('button[type="submit"]').click(),
    ]);
    if (page.url().includes('/login') || page.url().includes('/two-factor')) {
      throw new Error('Logowanie administratora nie zakończyło się wejściem do panelu.');
    }

    const avatar = page.locator('.header-avatar-img, .header-avatar-fallback').first();
    await avatar.waitFor({ state: 'visible' });
    const avatarStyle = await avatar.evaluate((element) => {
      const style = getComputedStyle(element);
      return { radius: style.borderRadius, width: style.width, height: style.height };
    });
    if (avatarStyle.radius !== '50%' || avatarStyle.width !== avatarStyle.height) {
      throw new Error(`Awatar nie jest okrągły: ${JSON.stringify(avatarStyle)}`);
    }

    await page.goto('http://localhost:8080/admin/settings#program-talent', { waitUntil: 'domcontentloaded' });
    const talent = page.locator('#program-talent');
    await talent.waitFor({ state: 'visible' });
    await talent.screenshot({ path: path.join(output, '01-program-talent.png') });

    await page.goto('http://localhost:8080/admin/campaigns', { waitUntil: 'domcontentloaded' });
    await page.locator('.zs-campaign-admin-head').waitFor({ state: 'visible' });
    await page.screenshot({ path: path.join(output, '02-kampanie-i-zaangazowanie.png'), fullPage: true });

    await page.goto('http://localhost:8080/admin/bug-reports', { waitUntil: 'domcontentloaded' });
    await page.locator('.zs-operator-page-head').waitFor({ state: 'visible' });
    await page.screenshot({ path: path.join(output, '03-zgloszenia-bledow.png'), fullPage: true });

    await page.goto('http://localhost:8080/pl/report-bug', { waitUntil: 'domcontentloaded' });
    await page.locator('form[action$="/report-bug"]').waitFor({ state: 'visible' });
    const menu = await page.locator('.main-nav').innerText();
    if (/Opinie i polemiki/i.test(menu)) throw new Error('Usunięta pozycja nadal znajduje się w menu.');
    await page.screenshot({ path: path.join(output, '04-zglos-blad.png'), fullPage: true });

    await page.goto('http://localhost:8080/pl/jak-zarabiac', { waitUntil: 'domcontentloaded' });
    if (await page.locator('#opinie-i-polemiki, .economy-response-principle').count()) {
      throw new Error('Informacja o polemikach nadal jest powielona na stronie Jak zarabiać.');
    }

    process.stdout.write(`Campaign/Talent visual audit: PASS\n${output}\n`);
  } finally {
    await context.close();
    await browser.close();
  }
}

main().catch((error) => {
  process.stderr.write(`Visual audit failed: ${error.message}\n`);
  process.exitCode = 1;
});
