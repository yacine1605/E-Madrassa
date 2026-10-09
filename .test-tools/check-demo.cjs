const { chromium } = require('./node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const base = fs.readFileSync('.env', 'utf8').match(/^APP_URL=(.+)$/m)[1].trim();
(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('response', r => { if (r.status() >= 400) errors.push(`${r.status()} ${new URL(r.url()).pathname}`); });
    const response = await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
    assert.equal(response.status(), 200);
    await page.locator('input[type=email]').waitFor();
    await page.locator('input[type=password]').waitFor();
    assert.equal(await page.locator('button[type=submit]').count(), 1);
    assert.equal(await page.locator('[href*="localhost"], [src*="localhost"]').count(), 0);
    assert.equal(errors.length, 0, errors.join('\n'));
    console.log('PASS public administrator login, HTTPS assets and browser JavaScript.');

    const rows = fs.readFileSync('ecole-filament/storage/app/private/teacher-credentials-2026.csv', 'utf8').trim().split(/\r?\n/);
    const [,,email,password] = rows[1].split(';').map(value => value.replace(/^"|"$/g, ''));
    await page.goto(base + '/enseignant/login');
    await page.locator('input[name=email]').fill(email);
    await page.locator('input[name=password]').fill(password);
    await page.getByRole('button', { name: 'Se connecter', exact: true }).click();
    await page.waitForURL(base + '/enseignant');
    await page.getByRole('link', { name: 'Notes', exact: true }).click();
    await page.getByRole('heading', { name: 'Devoirs et examens', exact: true }).waitFor();
    assert.equal(errors.length, 0, errors.join('\n'));
    const cookies = await page.context().cookies(base);
    assert.ok(cookies.some(c => c.name === 'madrassa_session' && c.secure && c.httpOnly));
    console.log('PASS existing account sign-in, secure session and authenticated notes page through the public tunnel.');
  } finally { await browser.close(); }
})().catch(() => { console.error('Public browser check failed; no credentials were logged.'); process.exitCode = 1; });
