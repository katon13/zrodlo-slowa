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

  const homepageResponse = await page.goto('http://localhost:8080/pl', { waitUntil: 'networkidle' });
  if (!homepageResponse || !homepageResponse.ok()) throw new Error('Homepage did not return HTTP 200.');
  await page.locator('.zs-home-value-strip').waitFor();
  const homepageFlowSegmentCount = await page.locator('.zs-home-value-bar > span').count();
  if (homepageFlowSegmentCount !== 3) throw new Error(`Homepage value bar has ${homepageFlowSegmentCount} segments instead of 3.`);
  const homepageFlowBeforeFooter = await page.evaluate(() => {
    const strip = document.querySelector('.zs-home-value-strip');
    const main = document.querySelector('main');
    return Boolean(strip && main && strip === main.lastElementChild);
  });
  if (!homepageFlowBeforeFooter) throw new Error('Homepage value strip is not the final section before the footer.');
  const homepageHasHorizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  if (homepageHasHorizontalOverflow) throw new Error('Homepage has horizontal overflow.');
  await page.screenshot({ path: path.join(outputDirectory, 'publiczne-www-strona-glowna.png'), fullPage: true });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('http://localhost:8080/pl', { waitUntil: 'networkidle' });
  await page.locator('.zs-home-value-strip').waitFor();
  const mobileHomepageHasHorizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  if (mobileHomepageHasHorizontalOverflow) throw new Error('Mobile homepage has horizontal overflow.');
  await page.screenshot({ path: path.join(outputDirectory, 'publiczne-www-strona-glowna-mobile.png'), fullPage: true });
  await page.setViewportSize({ width: 1600, height: 1000 });

  const publicResponse = await page.goto('http://localhost:8080/pl/jak-zarabiac', { waitUntil: 'networkidle' });
  if (!publicResponse || !publicResponse.ok()) throw new Error('Public earning page did not return HTTP 200.');
  await page.screenshot({ path: path.join(outputDirectory, 'publiczne-www-jak-zarabiac.png'), fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('http://localhost:8080/pl/jak-zarabiac', { waitUntil: 'networkidle' });
  const mobileEarningHasHorizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  if (mobileEarningHasHorizontalOverflow) throw new Error('Mobile earning page has horizontal overflow.');
  await page.screenshot({ path: path.join(outputDirectory, 'publiczne-www-jak-zarabiac-mobile.png'), fullPage: true });
  await page.setViewportSize({ width: 1600, height: 1000 });

  await page.goto('http://localhost:8080/pl/login', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="login"]').fill('katon');
  await page.locator('input[name="password"]').fill('1234');
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.locator('form button[type="submit"]').click(),
  ]);
  const adminResponse = await page.goto('http://localhost:8080/admin/settings', { waitUntil: 'domcontentloaded' });
  if (!adminResponse || !adminResponse.ok() || page.url().includes('/login')) {
    throw new Error(`Admin page is unavailable after login: ${page.url()}`);
  }
  await page.locator('h1').waitFor();
  await page.screenshot({ path: path.join(outputDirectory, 'panel-admina-ustawienia-talent.png'), fullPage: true });

  const adminEnglishResponse = await page.goto('http://localhost:8080/en/admin/settings', { waitUntil: 'domcontentloaded' });
  if (!adminEnglishResponse || !adminEnglishResponse.ok()) throw new Error('English admin page did not return HTTP 200.');
  const adminEnglishLanguage = await page.locator('html').getAttribute('lang');
  if (adminEnglishLanguage !== 'en') throw new Error(`English admin language mismatch: ${adminEnglishLanguage}`);
  const adminEnglishBody = await page.locator('body').innerText();
  const expectedEnglishTalentCopy = [
    'Settings and Talent',
    'Getting started and active presence',
    'Reading and community',
    'Surveys and campaigns',
    'Account creation',
    'Active daily visit',
    'Article read',
    'Published opinion or response',
    'Bug report',
    'Survey participation',
    'Advertisement view',
    'Advertisement click',
  ];
  const missingEnglishTalentCopy = expectedEnglishTalentCopy.filter((copy) => !adminEnglishBody.includes(copy));
  const leakedPolishTalentCopy = [
    'Start i aktywna obecność',
    'Czytanie i społeczność',
    'Ankiety i kampanie',
    'Założenie konta',
    'Aktywna wizyta dzienna',
  ].filter((copy) => adminEnglishBody.includes(copy));
  await page.screenshot({ path: path.join(outputDirectory, 'panel-admina-ustawienia-talent-en.png'), fullPage: true });
  if (missingEnglishTalentCopy.length > 0 || leakedPolishTalentCopy.length > 0) {
    const talentStart = adminEnglishBody.indexOf('Talent Program');
    const talentExcerpt = adminEnglishBody.slice(Math.max(0, talentStart), Math.max(0, talentStart) + 2200);
    throw new Error(`English Talent localization mismatch. Missing: ${missingEnglishTalentCopy.join(', ')}; Polish: ${leakedPolishTalentCopy.join(', ')}; excerpt: ${talentExcerpt}`);
  }

  const persistedAdminResponse = await page.goto('http://localhost:8080/admin/settings', { waitUntil: 'domcontentloaded' });
  const persistedAdminLanguage = await page.locator('html').getAttribute('lang');
  if (!persistedAdminResponse || !persistedAdminResponse.ok() || persistedAdminLanguage !== 'en') {
    throw new Error(`Admin language was not retained: ${persistedAdminLanguage}`);
  }

  const body = await page.locator('body').innerText();
  const leakedKeys = body.match(/\b(?:admin|ui|controller)\.[a-z0-9_.]+\b/g) || [];
  await browser.close();
  process.stdout.write(JSON.stringify({
    homepageStatus: homepageResponse.status(),
    homepageFlowSegmentCount,
    homepageFlowBeforeFooter,
    homepageHasHorizontalOverflow,
    mobileHomepageHasHorizontalOverflow,
    mobileEarningHasHorizontalOverflow,
    publicStatus: publicResponse.status(),
    adminStatus: adminResponse.status(),
    adminEnglishStatus: adminEnglishResponse.status(),
    adminEnglishLanguage,
    persistedAdminLanguage,
    missingEnglishTalentCopy,
    leakedPolishTalentCopy,
    leakedKeys: [...new Set(leakedKeys)],
    consoleErrors,
    failedResponses: [...new Set(failedResponses)],
  }, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
