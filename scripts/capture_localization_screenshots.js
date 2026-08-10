const { chromium } = require('playwright');
const path = require('path');

async function main() {
  const outputDirectory = process.argv[2] || path.resolve('artifacts', 'screenshots');
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  });
  const context = await browser.newContext({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 1 });
  const page = await context.newPage();
  const consoleErrors = [];
  const failedResponses = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('pageerror', (error) => consoleErrors.push(error.message));
  page.on('response', (response) => {
    if (response.status() >= 400) failedResponses.push(`${response.status()} ${response.url()}`);
  });

  const publicResponse = await page.goto('http://localhost:8080/pl/jak-zarabiac', { waitUntil: 'networkidle' });
  if (!publicResponse || !publicResponse.ok()) throw new Error('Public page did not return HTTP 200.');
  await page.screenshot({ path: path.join(outputDirectory, 'publiczne-www-jak-zarabiac.png'), fullPage: true });

  await page.goto('http://localhost:8080/pl/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="login"]').fill('katon');
  await page.locator('input[name="password"]').fill('1234');
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.locator('form button[type="submit"]').click(),
  ]);
  const adminResponse = await page.goto('http://localhost:8080/admin/settings', { waitUntil: 'networkidle' });
  if (!adminResponse || !adminResponse.ok() || page.url().includes('/login')) {
    throw new Error(`Admin page is unavailable after login: ${page.url()}`);
  }
  await page.screenshot({ path: path.join(outputDirectory, 'panel-admina-ustawienia-talent.png'), fullPage: true });

  const body = await page.locator('body').innerText();
  const leakedKeys = body.match(/\b(?:admin|ui|controller)\.[a-z0-9_.]+\b/g) || [];
  await browser.close();
  process.stdout.write(JSON.stringify({
    publicStatus: publicResponse.status(),
    adminStatus: adminResponse.status(),
    leakedKeys: [...new Set(leakedKeys)],
    consoleErrors,
    failedResponses: [...new Set(failedResponses)],
  }, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
