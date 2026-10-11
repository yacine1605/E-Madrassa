// Browser acceptance of every screen (FR-I18-01 AC1, NFR-UX-02): in French and Arabic, on a desktop
// and a phone, each page loads, runs right to left in Arabic, overflows nothing sideways, shows
// no raw catalog key and raises no script error; a screenshot of each goes to SHOTS for a
// person to look at. In each browser of BROWSERS (chromium, firefox, webkit; chromium by default,
// BROWSER_CHANNEL for an installed Chrome or Edge). Run by deploy/acceptance-test.sh against a
// disposable installation.
import { chromium, firefox, webkit } from 'playwright';
import { createHmac } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';

const base = process.env.BASE_URL;
const shots = process.env.SHOTS ?? '.acceptance';
const accounts = JSON.parse(process.env.ACCEPTANCE_ACCOUNTS);
const engines = { chromium, firefox, webkit };
const browsers = (process.env.BROWSERS || 'chromium').split(',').map(name => name.trim()).filter(Boolean);
for (const name of browsers) if (!engines[name]) throw new Error(`unknown browser ${name}: chromium, firefox or webkit`);
// Firefox has no mobile mode: its phone is the same screen, touch and density, without it.
const screens = name => ({
  desktop: { viewport: { width: 1280, height: 800 } },
  phone: { viewport: { width: 390, height: 844 }, ...(name === 'firefox' ? {} : { isMobile: true }), hasTouch: true, deviceScaleFactor: 2 },
});
let viewports;
let shotsOf;
const schoolPages = ['home', 'accounts', 'roles', 'assignments', 'credentials', 'reference-types', 'reference-nodes', 'subjects', 'years',
  'periods', 'custom-fields', 'sites', 'settings', 'deliveries', 'support-access', 'journal', 'tasks', 'notifications', 'imports', 'exports'];
const consolePages = ['supervision', 'tenants', 'tenants/create'];
const publicPages = ['school/login', 'school/recovery', 'school/activation', 'console/login'];
// A catalog key shown as it is: a translation missing from lang/fr or lang/ar.
const rawKey = /\b(?:school|admin|services|reference|account|console|errors|validation|messages|formats|exports|pdf|mail)\.[a-z_]+(?:\.[a-z_]+)*\b|\bfilament[a-z-]*::/;

const failures = [];
const report = [];
mkdirSync(shots, { recursive: true });

// A code is accepted once: the same account signing in again, in the next browser, waits for the next one.
const usedSteps = new Map();
async function totp(secret) {
  while (usedSteps.get(secret) === Math.floor(Date.now() / 30000)) await new Promise(resolve => setTimeout(resolve, 1000));
  const step = Math.floor(Date.now() / 30000);
  usedSteps.set(secret, step);
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of secret.replace(/=+$/, '')) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map(byte => parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const hash = createHmac('sha1', key).update(counter).digest();
  const offset = hash[hash.length - 1] & 15;
  return String((hash.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
}

async function check(context, path, locale, name) {
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const response = await page.goto(base + '/' + path, { waitUntil: 'networkidle' });
  await page.waitForTimeout(300);
  const state = await page.evaluate(() => ({
    dir: document.documentElement.getAttribute('dir'), lang: document.documentElement.getAttribute('lang'),
    overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, text: document.body.innerText,
  }));
  const problems = [];
  if (response.status() !== 200) problems.push(`HTTP ${response.status()}`);
  if (!page.url().endsWith('/' + path)) problems.push(`sent to ${page.url()}`);
  if (state.lang !== locale) problems.push(`lang="${state.lang}"`);
  if (state.dir !== (locale === 'ar' ? 'rtl' : 'ltr')) problems.push(`dir="${state.dir}"`);
  if (state.overflow > 1) problems.push(`${state.overflow}px wider than the screen`);
  const key = state.text.match(rawKey);
  if (key) problems.push(`raw key "${key[0]}"`);
  if (errors.length) problems.push('script error: ' + errors[0]);
  await page.screenshot({ path: `${shotsOf}/${name}.png`, fullPage: true });
  report.push({ browser: shotsOf.split('/').pop(), page: path, screen: name, problems });
  if (problems.length) failures.push(`${shotsOf.split('/').pop()} ${name} (/${path}): ${problems.join('; ')}`);
  await page.close();
}

async function setLocale(context, path, locale) {
  const page = await context.newPage();
  await page.goto(base + '/' + path, { waitUntil: 'networkidle' });
  const status = await page.evaluate(async ({ locale, path }) => {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const target = path.startsWith('console') ? '/console/locale/' + locale : '/api/account/locale';
    const response = await fetch(target, { method: path.startsWith('console') ? 'POST' : 'PUT', redirect: 'manual',
      headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ locale }) });
    return response.status;
  }, { locale, path });
  await page.close();
  if (![200, 302, 0].includes(status)) throw new Error(`language not changed on ${path}: HTTP ${status}`);
}

async function signIn(browser, kind) {
  const context = await browser.newContext({ ...viewports.desktop, ignoreHTTPSErrors: true, locale: 'fr-FR' });
  const page = await context.newPage();
  const account = accounts[kind];
  try {
    await signInSteps(page, kind, account);
  } catch (error) {
    // What the person would have seen, for the failure's report.
    await page.screenshot({ path: `${shotsOf}/sign-in-${kind}.png`, fullPage: true });
    writeFileSync(`${shotsOf}/sign-in-${kind}.txt`, `${page.url()}\n\n${await page.evaluate(() => document.body.innerText)}`);
    throw error;
  }
  await page.close();
  return context.storageState();
}

async function signInSteps(page, kind, account) {
  if (kind === 'admin') {
    await page.goto(base + '/school/login');
    await page.getByLabel('E-mail ou identifiant').fill(account.email);
    await page.locator('input[type="password"]').fill(account.password);
    await page.getByRole('button', { name: 'Se connecter' }).click();
    await page.locator('input[autocomplete="one-time-code"]').fill(await totp(account.secret));
    await page.getByRole('button', { name: 'Se connecter' }).click();
    await page.waitForURL('**/school/home');
  } else {
    await page.goto(base + '/console/login');
    await page.locator('input[type="email"]').fill(account.email);
    await page.locator('input[type="password"]').fill(account.password);
    await page.locator('form button[type="submit"]').click();
    await page.locator('input[autocomplete="one-time-code"]').fill(await totp(account.secret));
    // Filament sends the form by itself once the code is complete; otherwise, the button.
    const signedIn = url => !url.pathname.endsWith('/login');
    await page.waitForURL(signedIn, { timeout: 5000 }).catch(async () => {
      await page.locator('form button[type="submit"]').click();
      await page.waitForURL(signedIn);
    });
  }
}

async function run(browser) {
  const sessions = { admin: await signIn(browser, 'admin'), operator: await signIn(browser, 'operator') };
  for (const locale of ['fr', 'ar']) {
    for (const [kind, prefix, pages] of [['admin', 'school', schoolPages], ['operator', 'console', consolePages]]) {
      const setup = await browser.newContext({ ...viewports.desktop, ignoreHTTPSErrors: true, storageState: sessions[kind] });
      await setLocale(setup, prefix + '/' + pages[0], locale);
      sessions[kind] = await setup.storageState();
      await setup.close();
      for (const [screen, options] of Object.entries(viewports)) {
        const context = await browser.newContext({ ...options, ignoreHTTPSErrors: true, storageState: sessions[kind] });
        for (const page of pages) await check(context, prefix + '/' + page, locale, `${locale}-${screen}-${prefix}-${page.replace('/', '-')}`);
        await context.close();
      }
    }
    for (const [screen, options] of Object.entries(viewports)) {
      const context = await browser.newContext({ ...options, ignoreHTTPSErrors: true, locale: locale === 'ar' ? 'ar-DZ' : 'fr-FR' });
      for (const page of publicPages) await check(context, page, locale, `${locale}-${screen}-${page.replace('/', '-')}`);
      await context.close();
    }
  }
}

for (const name of browsers) {
  viewports = screens(name);
  shotsOf = `${shots}/${name}`;
  mkdirSync(shotsOf, { recursive: true });
  const browser = await engines[name].launch({ channel: name === 'chromium' ? process.env.BROWSER_CHANNEL || undefined : undefined, headless: true });
  try {
    await run(browser);
  } finally {
    await browser.close();
  }
  console.log(`${name}: ${report.filter(entry => entry.browser === name).length} screens checked.`);
}
writeFileSync(`${shots}/report.json`, JSON.stringify(report, null, 2));
console.log(`${report.length} screens checked in ${browsers.join(', ')}, ${failures.length} with problems; screenshots in ${shots}.`);
if (failures.length) {
  for (const failure of failures) console.log('FAIL ' + failure);
  process.exit(1);
}
