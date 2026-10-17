// Browser acceptance of every screen (FR-I18-01 AC1, NFR-UX-02): in French and Arabic, on a desktop
// and a phone, each page loads, runs right to left in Arabic, overflows nothing sideways, shows
// no raw catalog key, raises no script error and nothing the Content Security Policy blocks
// (NFR-SEC-03), the sign-in's typing and clicks included; a screenshot of each goes to SHOTS for a
// person to look at. In each browser of BROWSERS (chromium, firefox, webkit; chromium by default,
// BROWSER_CHANNEL for an installed Chrome or Edge). Run by deploy/acceptance-test.sh against a
// disposable installation.
import { chromium, firefox, webkit } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { signInSchool, totp } from './session.mjs';

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
const schoolPages = ['home', 'students', 'guardians', 'programs', 'groups', 'enrollments', 'staff', 'accounts', 'roles', 'assignments', 'credentials', 'reference-types',
  'reference-nodes', 'subjects', 'years', 'periods', 'custom-fields', 'sites', 'rooms', 'room-options', 'leads', 'lead-options', 'settings', 'deliveries', 'support-access', 'journal', 'tasks', 'notifications', 'imports', 'exports', 'adjustment-approvals', 'payment-validations', 'refund-approvals', 'arrears', 'finance-periods', 'expenses', 'expense-categories', 'expense-payments', 'expense-corrections',
  'payroll', 'my-pay', 'school-calendar', 'time-slots', 'planning', 'session-changes', 'timetable-versions', 'teacher-attendance', 'delivered-hours', 'roll-call'];
const consolePages = ['supervision', 'tenants', 'tenants/create'];
const publicPages = ['school/login', 'school/recovery', 'school/activation', 'console/login'];
// A catalog key shown as it is: a translation missing from lang/fr or lang/ar.
const rawKey = /\b(?:school|admin|schooling|staff|services|reference|finance|account|console|errors|validation|messages|formats|exports|pdf|mail|payroll)\.[a-z_]+(?:\.[a-z_]+)*\b|\bfilament[a-z-]*::/;

const failures = [];
const report = [];
mkdirSync(shots, { recursive: true });

// What the Content Security Policy blocks on this page, through every navigation it makes.
async function watchPolicy(page) {
  const refused = [];
  await page.exposeFunction('reportBlocked', text => refused.push(text));
  await page.addInitScript(() => document.addEventListener('securitypolicyviolation', event =>
    window.reportBlocked(`${event.effectiveDirective} ${event.blockedURI || 'inline'}`)));
  return refused;
}

async function check(context, path, locale, name) {
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const refused = await watchPolicy(page);
  const response = await page.goto(base + '/' + path, { waitUntil: 'networkidle' });
  await page.waitForTimeout(300);
  const state = await page.evaluate(() => ({
    dir: document.documentElement.getAttribute('dir'), lang: document.documentElement.getAttribute('lang'),
    overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, text: document.body.innerText,
    broken: Array.from(document.images).filter(image => image.complete && image.naturalWidth === 0).map(image => image.currentSrc || image.src),
  }));
  const problems = [];
  if (response.status() !== 200) problems.push(`HTTP ${response.status()}`);
  if (!page.url().endsWith('/' + path)) problems.push(`sent to ${page.url()}`);
  if (state.lang !== locale) problems.push(`lang="${state.lang}"`);
  if (state.dir !== (locale === 'ar' ? 'rtl' : 'ltr')) problems.push(`dir="${state.dir}"`);
  if (state.overflow > 1) problems.push(`${state.overflow}px wider than the screen`);
  if (state.broken.length) problems.push(`image not shown: ${state.broken[0]}`);
  const key = state.text.match(rawKey);
  if (key) problems.push(`raw key "${key[0]}"`);
  if (errors.length) problems.push('script error: ' + errors[0]);
  if (refused.length) problems.push('blocked by the Content Security Policy: ' + refused[0]);
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
  const refused = await watchPolicy(page);
  try {
    await signInSteps(page, kind, account);
    if (refused.length) failures.push(`${shotsOf.split('/').pop()} sign-in of ${kind}: blocked by the Content Security Policy: ${refused[0]}`);
  } catch (error) {
    // What the person would have seen, for the failure's report.
    await page.screenshot({ path: `${shotsOf}/sign-in-${kind}.png`, fullPage: true });
    writeFileSync(`${shotsOf}/sign-in-${kind}.txt`, `${page.url()}\n\n${await page.evaluate(() => document.body.innerText)}`
      + (refused.length ? `\n\nBlocked by the Content Security Policy:\n${refused.join('\n')}` : ''));
    throw error;
  }
  await page.close();
  return context.storageState();
}

async function signInSteps(page, kind, account) {
  if (kind === 'admin') {
    await signInSchool(page, base, account);
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

// Lot 2's records of the acceptance school, made once through the API by its administrator: a
// year, a programme with a subject, a room with a closure, a qualified teacher with their week, a
// day off, a contract and a leave, leading a class in that room, a pupil with a guardian in it. The
// lists then have rows, and the pupil's sheet, the class's members, the programme's subjects, the
// room's closures and the teacher's qualifications, availability, days off, contracts, leave, pay rule
// and pay are screens too.
async function seed(browser, session) {
  const context = await browser.newContext({ ...viewports.desktop, ignoreHTTPSErrors: true, storageState: session });
  const page = await context.newPage();
  await page.goto(base + '/school/home', { waitUntil: 'networkidle' });
  const records = await page.evaluate(async () => {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const call = async (path, body) => {
      const response = await fetch('/api/' + path, body === undefined ? { headers: { Accept: 'application/json' } } : { method: 'POST',
        headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      if (!response.ok) throw new Error(`${path}: HTTP ${response.status} ${await response.text()}`);
      return (await response.json()).data;
    };
    const day = offset => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10);
    const year = await call('years', { code: 'ACCEPTANCE', start_date: day(-30), end_date: day(300), status: 'current' });
    const grade = (await call('referential/nodes?type=grade'))[0];
    const site = (await call('sites')).find(s => s.is_default);
    const program = await call('programs', { year_id: year.id, node_id: grade.id, code: 'ACC-1', status: 'active', base_price: '120000',
      labels: { fr: 'Programme de recette', ar: 'برنامج الاختبار' }, duration_value: 9, duration_unit: 'months' });
    // A subject of the catalogue in the programme, with its volume (FR-PRG-02).
    const subject = (await call('subjects'))[0];
    await call(`programs/${program.id}/subjects`, { subject_id: subject.id, total_hours: '40', session_count: 20 });
    // A teacher's record, without an account (FR-STF-01), qualified for the programme's subject at its level (FR-STF-02).
    const teacher = await call('staff', { last_name_lat: 'Meziane', first_name_lat: 'Karim', last_name_ar: 'مزيان', first_name_ar: 'كريم',
      staff_type: 'teacher_internal', phone: '0550 98 76 54', hire_date: '2020-09-01' });
    await call(`staff/${teacher.id}/qualifications`, { subject_id: subject.id, node_id: grade.id });
    // Their week and a day off (FR-STF-03).
    await call(`staff/${teacher.id}/availabilities`, { slots: [{ weekday: 0, start_time: '08:00', end_time: '12:00' },
      { weekday: 2, start_time: '14:00', end_time: '17:00' }], max_daily_hours: '6', max_weekly_hours: '18' });
    await call(`staff/${teacher.id}/unavailabilities`, { date_from: day(7), date_to: day(8), reason: 'Formation des enseignants' });
    // Their contract and a leave (FR-STF-04).
    await call(`staff/${teacher.id}/contracts`, { contract_type: 'permanent', reference: 'CDI-2020-014', valid_from: '2020-09-01', weekly_hours: '18' });
    await call(`staff/${teacher.id}/leaves`, { leave_type: 'annual', date_from: day(20), date_to: day(24), observation: 'Congé de printemps' });
    // A room of the site, closed for a few days (FR-SLL-02).
    const room = await call('rooms', { site_id: site.id, code: 'A101', labels: { fr: 'Salle 101', ar: 'القاعة 101' }, capacity: 30,
      room_type: 'CLASSROOM', equipment: ['PROJECTOR', 'WHITEBOARD'] });
    await call(`rooms/${room.id}/closures`, { date_from: day(10), date_to: day(12), reason: 'Travaux de peinture' });
    // FR-PLN-01: a public holiday for the whole school, a make-up day on the site, the day's slots.
    await call('calendar-exceptions', { kind: 'holiday', date_from: day(20), date_to: day(20), reason: 'Fête de l’indépendance' });
    await call('calendar-exceptions', { site_id: site.id, kind: 'opening', date_from: day(23), date_to: day(23), reason: 'Rattrapage du jour férié' });
    for (const [code, start_time, end_time] of [['M1', '08:00', '09:30'], ['M2', '09:45', '11:15'], ['S1', '13:30', '15:00']])
      await call('time-slots', { code, start_time, end_time });
    // The class they lead, in that room (FR-GRP-01).
    const group = await call('groups', { year_id: year.id, program_id: program.id, site_id: site.id, code: 'ACC-A', group_type: 'class',
      labels: { fr: 'Classe de recette', ar: 'قسم الاختبار' }, capacity: 25, valid_from: day(-30), status: 'active', lead_teacher_id: teacher.id,
      main_room_id: room.id });
    const pupil = await call('students', { last_name_lat: 'Benali', first_name_lat: 'Amine', last_name_ar: 'بن علي', first_name_ar: 'أمين',
      birth_date: '2012-05-01', birth_place: 'Alger', gender: 'm', phone: '0550 12 34 56', address: '12 rue des Oliviers, Alger' });
    const guardian = await call('guardians', { last_name_lat: 'Benali', first_name_lat: 'Karim', last_name_ar: 'بن علي', first_name_ar: 'كريم',
      phone: '0550 65 43 21', profession: 'Médecin' });
    await call(`students/${pupil.id}/guardians`, { guardian_id: guardian.id, relationship: 'father', is_primary_contact: true, is_payer: true });
    // Their photo (FR-ELV-01), drawn here and sent as a form would: the sheet shows it through its own route.
    const canvas = Object.assign(document.createElement('canvas'), { width: 480, height: 360 });
    const draw = canvas.getContext('2d');
    draw.fillStyle = '#2f6f4f';
    draw.fillRect(0, 0, 480, 360);
    draw.fillStyle = '#f2d16b';
    draw.beginPath();
    draw.arc(240, 150, 90, 0, Math.PI * 2);
    draw.fill();
    const photo = new FormData();
    photo.append('photo', await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9)), 'amine.jpg');
    photo.append('version', String(pupil.version));
    photo.append('square', '1');
    const sent = await fetch(`/api/students/${pupil.id}/photo`, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, body: photo });
    if (!sent.ok) throw new Error(`photo: HTTP ${sent.status} ${await sent.text()}`);
    const enrollment = await call('enrollments', { student_id: pupil.id, program_id: program.id, idempotency_key: crypto.randomUUID() });
    await call(`groups/${group.id}/memberships`, { enrollment_id: enrollment.id });
    // FR-PLN-02: the class's weekly course on Tuesdays, clear of the teacher's days off and the room's works, and a session this week.
    await call('session-series', { group_id: group.id, subject_id: subject.id, teacher_id: teacher.id, room_id: room.id, weekday: 2, start_time: '14:00',
      end_time: '15:30', date_from: day(30), date_to: day(60), idempotency_key: crypto.randomUUID() });
    const soon = [1, 2, 3, 4, 5, 6].find(n => new Date(Date.now() + n * 86400000).getUTCDay() === 0);
    if (soon !== undefined) await call('class-sessions', { group_id: group.id, subject_id: subject.id, teacher_id: teacher.id, room_id: room.id, day: day(soon),
      start_time: '08:00', end_time: '09:30' });
    // FR-PLN-06: a session cancelled with its reason, in the history of the sessions.
    if (soon !== undefined) {
      const outing = await call('class-sessions', { group_id: group.id, subject_id: subject.id, teacher_id: teacher.id, room_id: room.id, day: day(soon),
        start_time: '10:00', end_time: '11:00' });
      await call(`class-sessions/${outing.id}/cancel`, { reason: 'Sortie pédagogique au musée', version: outing.version });
    }
    // FR-PLN-05: the week's timetable published, and the next term's prepared.
    const week = await call('timetable-versions', { date_from: day(0), date_to: day(6), reason: 'Emploi du temps de la semaine' });
    await call(`timetable-versions/${week.id}/validate`, { version: week.version });
    await call(`timetable-versions/${week.id}/publish`, { version: week.version + 1 });
    await call('timetable-versions', { date_from: day(30), date_to: day(60), reason: 'Emploi du temps du trimestre' });
    // A family who called, with their child, a visit and a reminder (FR-CRM-01); a brother converted into a pupil (FR-CRM-02).
    const lead = await call('leads', { last_name: 'Haddad', first_name: 'Samia', phone: '0661 22 33 44', relationship: 'mother', source: 'social_media',
      children: [{ first_name: 'Yanis', last_name: 'Haddad', birth_date: '2014-02-10', level_id: grade.id }, { first_name: 'Lina', birth_date: '2016-09-01' }] });
    await call(`leads/${lead.id}/interactions`, { kind: 'call', body: 'Demande de renseignements sur la classe de recette.' });
    await call(`leads/${lead.id}/interactions`, { kind: 'visit', body: 'Visite de l’école avec les deux enfants.' });
    await call(`leads/${lead.id}/reminders`, { due_at: day(3) + 'T09:00:00Z', body: 'Rappeler pour les pièces du dossier' });
    await call(`lead-children/${lead.children[0].id}/convert`, { idempotency_key: crypto.randomUUID(), program_id: program.id,
      student: { last_name_lat: 'Haddad', first_name_lat: 'Yanis', birth_date: '2014-02-10' },
      guardian: { last_name_lat: 'Haddad', first_name_lat: 'Samia', phone: '0661 22 33 44' }, relationship: 'mother' });
    // What the enrolment owes and when (FR-FIN-01): its schedule as proposed, a discount waiting for the direction.
    const proposal = await (await fetch(`/api/enrollments/${enrollment.id}/schedule/proposal`, { headers: { Accept: 'application/json' } })).json();
    await call(`enrollments/${enrollment.id}/schedule`, { installments: proposal.data.installments, idempotency_key: crypto.randomUUID() });
    await call(`enrollments/${enrollment.id}/adjustments`, { kind: 'discount', amount: '15000', reason: 'Bourse au mérite', idempotency_key: crypto.randomUUID() });
    // A payment by cheque waiting for its validation (FR-FIN-02).
    await call(`enrollments/${enrollment.id}/payments`, { amount: '40000', paid_on: day(0), method_code: 'cheque', external_ref: 'CHQ 0012345',
      idempotency_key: crypto.randomUUID() });
    // How the teacher is paid (FR-REM-01), and this month's pay as a draft with its hours of the first day (FR-REM-02).
    const month = day(0).slice(0, 8) + '01';
    await call(`staff/${teacher.id}/pay-rules`, { kind: 'hourly', valid_from: month, rates: [{ unit_amount: '1500' }, { node_id: grade.id, unit_amount: '1800' }],
      bonuses: [{ label: 'Prime de transport', amount: '3000' }], idempotency_key: crypto.randomUUID() });
    await call('payroll-runs/generate', { period: month, staff_id: teacher.id });
    const pay = (await call(`payroll-runs?period=${month}`))[0];
    await call(`payroll-runs/${pay.id}/units`, { version: pay.version, units: [{ node_id: grade.id, worked_on: month, quantity: '4' }, { worked_on: month, quantity: '2.5' }] });
    // FR-REM-05: the school lets its members read their own pays; the administrator, no member of staff, reads none.
    const put = await fetch('/api/settings/payroll.self_view', { method: 'PUT', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ value: true }) });
    if (!put.ok) throw new Error(`payroll.self_view: HTTP ${put.status} ${await put.text()}`);
    return { pupil: pupil.id, group: group.id, program: program.id, teacher: teacher.id, room: room.id, lead: lead.id, enrollment: enrollment.id, pay: pay.id };
  });
  await context.close();
  return records;
}

let records;
async function run(browser) {
  const sessions = { admin: await signIn(browser, 'admin'), operator: await signIn(browser, 'operator') };
  records ??= await seed(browser, sessions.admin);
  for (const locale of ['fr', 'ar']) {
    for (const [kind, prefix, pages] of [['admin', 'school', [...schoolPages, `student-sheet?student=${records.pupil}`, `group-members?group=${records.group}`,
      `program-subjects?program=${records.program}`, `staff-qualifications?staff=${records.teacher}`,
      `staff-availability?staff=${records.teacher}`, `staff-unavailability?staff=${records.teacher}`, `staff-contracts?staff=${records.teacher}`, `staff-pay-rules?staff=${records.teacher}`,
      `staff-leaves?staff=${records.teacher}`, `room-closures?room=${records.room}`, `lead-sheet?lead=${records.lead}`,
      `enrollment-finance?enrollment=${records.enrollment}`, `payroll-run?run=${records.pay}`]], ['operator', 'console', consolePages]]) {
      const setup = await browser.newContext({ ...viewports.desktop, ignoreHTTPSErrors: true, storageState: sessions[kind] });
      await setLocale(setup, prefix + '/' + pages[0], locale);
      sessions[kind] = await setup.storageState();
      await setup.close();
      for (const [screen, options] of Object.entries(viewports)) {
        const context = await browser.newContext({ ...options, ignoreHTTPSErrors: true, storageState: sessions[kind] });
        for (const page of pages) await check(context, prefix + '/' + page, locale, `${locale}-${screen}-${prefix}-${page.replace(/[/?=].*$/, '').replace('/', '-')}`);
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
