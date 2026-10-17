// Signing in to a school as a person does, MFA included: shared by the browser acceptance
// (acceptance.mjs) and the finance's review pack (review-lot3.mjs).
import { createHmac } from 'node:crypto';

// A code is accepted once: the same account signing in again waits for the next one.
const usedSteps = new Map();
export async function totp(secret) {
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

// The school's sign-in page in French: e-mail, password, then the code.
export async function signInSchool(page, base, account) {
  await page.goto(base + '/school/login');
  await page.getByLabel('E-mail ou identifiant').fill(account.email);
  await page.locator('input[type="password"]').fill(account.password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  // The page comes again with the code's field: typed only once its scripts are running, or the code is lost.
  await page.locator('input[autocomplete="one-time-code"]').waitFor();
  await page.waitForLoadState('networkidle');
  await page.locator('input[autocomplete="one-time-code"]').fill(await totp(account.secret));
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await page.waitForURL('**/school/home');
}
