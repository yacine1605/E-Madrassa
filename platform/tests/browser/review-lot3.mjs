// The finance's review pack of lot 3 (its exit criteria: the reconciliation validated by the
// finance, the Arabic receipts read). On a running installation, through the API and as the
// people who would do it, a whole month of example data: last month's payments, a refund, three
// expenses and two pays, each decided by someone else than its author; then, this month, a
// payment and a payout taken back after the fact. The receipts go to OUT/recus as PDFs, a
// screenshot of each finance screen in French and Arabic to OUT/ecrans, and OUT/fiche-revue.md
// tells the expected figures next to those the reconciliation gives. Last month stays open: the
// finance closes it during the review. Run by deploy/review-lot3.sh.
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { signInSchool } from './session.mjs';

const base = process.env.BASE_URL;
const out = process.env.OUT ?? '.review';
const accounts = JSON.parse(process.env.REVIEW_ACCOUNTS);
for (const folder of ['recus', 'ecrans']) mkdirSync(`${out}/${folder}`, { recursive: true });

// Days in Algiers: last month (M) and today.
const algiers = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Algiers', year: 'numeric', month: '2-digit', day: '2-digit' });
const today = algiers.format(new Date());
const shift = (date, months, day = 1) => {
  const [y, m] = date.split('-').map(Number);
  const at = new Date(Date.UTC(y, m - 1 + months, day));
  return at.toISOString().slice(0, 10);
};
const month = shift(today, -1);
const day = n => shift(today, -1, n);
const monthLabel = month.slice(0, 7);

const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL || undefined, headless: true });
const people = {};
const log = [];

// A person signed in, who calls the API as their screens do.
async function person(kind) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, ignoreHTTPSErrors: true, locale: 'fr-FR' });
  const page = await context.newPage();
  await signInSchool(page, base, accounts[kind]);
  const request = (method, path, body) => page.evaluate(async ({ method, path, body }) => {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const response = await fetch('/api/' + path, { method, headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json',
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }) }, body: body === undefined ? undefined : JSON.stringify(body) });
    const text = await response.text();
    return { status: response.status, text };
  }, { method, path, body });
  const call = async (method, path, body) => {
    const { status, text } = await request(method, path, body);
    if (status >= 300) throw new Error(`${kind} ${method} ${path}: HTTP ${status} ${text}`);
    return JSON.parse(text).data;
  };
  // A PDF as its bytes.
  const pdf = path => page.evaluate(async path => {
    const response = await fetch('/api/' + path, { headers: { Accept: 'application/pdf' } });
    if (!response.ok) throw new Error(`${path}: HTTP ${response.status}`);
    const bytes = new Uint8Array(await response.arrayBuffer());
    let binary = '';
    for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    return btoa(binary);
  }, path);
  return { context, page, call, get: path => call('GET', path), post: (path, body) => call('POST', path, body), put: (path, body) => call('PUT', path, body),
    pdf: async (path, file) => writeFileSync(`${out}/recus/${file}`, Buffer.from(await pdf(path), 'base64')) };
}

const key = () => randomUUID();
const note = (what, detail) => log.push([what, detail]);
// The sheet's words for the means and the decisions.
const means = { cash: 'espèces', cheque: 'chèque', transfer: 'virement' };

for (const kind of ['direction', 'comptable', 'tresorier', 'enseignant']) people[kind] = await person(kind);
const { direction, comptable, tresorier, enseignant } = people;

// The school's identity on its receipts, in Arabic; its members read their own pays.
for (const [setting, value] of [['identity.address', '12, rue des Oliviers, Hydra, Alger'], ['identity.phone', '023 45 67 89'],
  ['identity.email', 'contact@oliviers.example'], ['finance.receipt.locale', 'ar'], ['payroll.self_view', true]]) {
  await direction.put(`settings/${setting}`, { value });
}

// The school year, a programme of 120,000 DA, five pupils enrolled in it.
const grade = (await direction.get('referential/nodes?type=grade'))[0];
const year = await direction.post('years', { code: 'REVUE-' + monthLabel, start_date: shift(today, -2), end_date: shift(today, 9), status: 'current' });
const program = await direction.post('programs', { year_id: year.id, node_id: grade.id, code: '4AM', status: 'active', base_price: '120000',
  labels: { fr: '4e année moyenne', ar: 'السنة الرابعة متوسط' }, duration_value: 9, duration_unit: 'months' });
const pupils = {};
for (const [code, last, first, lastAr, firstAr, born] of [
  ['amine', 'Benali', 'Amine', 'بن علي', 'أمين', '2011-05-01'], ['yasmine', 'Kaci', 'Yasmine', 'قاسي', 'ياسمين', '2011-09-14'],
  ['ilyes', 'Mansouri', 'Ilyes', 'منصوري', 'إلياس', '2011-02-20'], ['sara', 'Hamidi', 'Sara', 'حميدي', 'سارة', '2011-11-03'],
  ['nadir', 'Ouali', 'Nadir', 'والي', 'نذير', '2011-07-22']]) {
  const pupil = await direction.post('students', { last_name_lat: last, first_name_lat: first, last_name_ar: lastAr, first_name_ar: firstAr, birth_date: born });
  // A minor's parent, who pays, reachable by phone.
  const parent = await direction.post('guardians', { last_name_lat: last, first_name_lat: 'Mourad', last_name_ar: lastAr, first_name_ar: 'مراد',
    phone: '0550 ' + String(Object.keys(pupils).length + 10).padStart(2, '0') + ' 34 56' });
  await direction.post(`students/${pupil.id}/guardians`, { guardian_id: parent.id, relationship: 'father', is_primary_contact: true, is_payer: true });
  const enrollment = await direction.post('enrollments', { student_id: pupil.id, program_id: program.id, idempotency_key: key() });
  // Its schedule as proposed.
  const proposal = await comptable.get(`enrollments/${enrollment.id}/schedule/proposal`);
  await comptable.post(`enrollments/${enrollment.id}/schedule`, { installments: proposal.installments, idempotency_key: key() });
  pupils[code] = { name: `${first} ${last} (${firstAr} ${lastAr})`, enrollment: enrollment.id };
}

// A payment recorded by the accountant, validated by the treasurer.
const pay = async (pupil, amount, on, method, ref) => {
  const payment = await comptable.post(`enrollments/${pupils[pupil].enrollment}/payments`, { amount, paid_on: on, method_code: method, external_ref: ref,
    idempotency_key: key() });
  const validated = await tresorier.post(`payments/${payment.id}/validate`, { version: payment.version });
  note(`Paiement ${payment.reference}`, `${pupils[pupil].name}, ${amount} DA, ${means[method]}${ref ? ' ' + ref : ''}, le ${on}`);
  return validated;
};
// A discount granted by the accountant; beyond the school's threshold, approved by the direction.
const discount = async (pupil, amount, reason) => {
  let adjustment = await comptable.post(`enrollments/${pupils[pupil].enrollment}/adjustments`, { kind: 'discount', amount, reason, idempotency_key: key() });
  if (adjustment.status !== 'approved') adjustment = await direction.post(`adjustments/${adjustment.id}/approve`, { version: adjustment.version });
  note('Remise', `${pupils[pupil].name}, ${amount} DA, « ${reason} », ${adjustment.status === 'approved' ? 'approuvée' : adjustment.status}`);
};

// Amine pays in two parts and still owes 50,000.
const amine1 = await pay('amine', '40000', day(3), 'cash', null);
const amine2 = await pay('amine', '30000', day(16), 'cheque', 'CHQ 4471023');
// Yasmine, with a merit grant of 15,000, pays the rest at once.
await discount('yasmine', '15000', 'Bourse au mérite');
const yasmine = await pay('yasmine', '105000', day(6), 'transfer', 'VIR 2026-118');
// Ilyes pays everything; a family discount of 20,000 afterwards leaves a credit, refunded in cash.
const ilyes = await pay('ilyes', '120000', day(5), 'transfer', 'VIR 2026-097');
await discount('ilyes', '20000', 'Réduction fratrie');
let refund = await comptable.post(`enrollments/${pupils.ilyes.enrollment}/refunds`, { kind: 'refund', amount: '20000', reason: 'Trop-perçu après la réduction fratrie',
  idempotency_key: key() });
refund = await direction.post(`refunds/${refund.id}/approve`, { version: refund.version });
refund = await comptable.post(`refunds/${refund.id}/execute`, { version: refund.version, method_code: 'cash', paid_on: day(21) });
note(`Remboursement ${refund.reference}`, `${pupils.ilyes.name}, 20000 DA, espèces, le ${day(21)}`);
// Sara pays a first part; Nadir too, a payment taken back this month (below).
const sara = await pay('sara', '30000', day(10), 'cash', null);
const nadir = await pay('nadir', '50000', day(12), 'cash', null);

// The receipts, issued in the order of the payments: in Arabic, Sara's in French; Amine's first one printed again (« duplicata »).
await comptable.pdf(`payments/${amine1.id}/receipt`, '01-amine-paiement-especes-ar.pdf');
await comptable.pdf(`payments/${ilyes.id}/receipt`, '02-ilyes-paiement-virement-ar.pdf');
await comptable.pdf(`payments/${yasmine.id}/receipt`, '03-yasmine-paiement-solde-ar.pdf');
await direction.put('settings/finance.receipt.locale', { value: 'fr' });
await comptable.pdf(`payments/${sara.id}/receipt`, '04-sara-paiement-especes-fr.pdf');
await direction.put('settings/finance.receipt.locale', { value: 'ar' });
await comptable.pdf(`payments/${nadir.id}/receipt`, '05-nadir-paiement-especes-ar.pdf');
await comptable.pdf(`payments/${amine2.id}/receipt`, '06-amine-paiement-cheque-ar.pdf');
await comptable.pdf(`refunds/${refund.id}/receipt`, '07-ilyes-remboursement-ar.pdf');
await comptable.pdf(`payments/${amine1.id}/receipt`, '08-amine-paiement-especes-ar-duplicata.pdf');

// Three expenses of last month, entered by the accountant, validated by the direction, paid by the accountant.
const categories = Object.fromEntries((await comptable.get('expense-categories')).map(category => [category.code, category.id]));
const expense = async (category, amount, on, beneficiary, reason, payments) => {
  let entry = await comptable.post('expenses', { expense_date: on, amount, category_id: categories[category], beneficiary, reason, idempotency_key: key() });
  entry = await comptable.post(`expenses/${entry.id}/submit`, { version: entry.version });
  await direction.post(`expenses/${entry.id}/validate`, { version: entry.version });
  for (const [paid, paidOn, method, ref] of payments) {
    await comptable.post(`expenses/${entry.id}/payments`, { amount: paid, paid_on: paidOn, method_code: method, external_ref: ref, idempotency_key: key() });
  }
  note(`Dépense ${category}`, `${reason}, ${beneficiary}, ${amount} DA le ${on} ; payée ${payments.map(([paid, paidOn, method]) => `${paid} (${means[method]}, ${paidOn})`).join(' + ')}`);
};
await expense('rent', '80000', day(2), 'SCI Les Oliviers', 'Loyer du mois', [['80000', day(5), 'transfer', 'VIR 2026-101']]);
await expense('equipment', '65000', day(14), 'Bureau Équipement Alger', 'Vidéoprojecteur de la salle 101', [['40000', day(18), 'cash', null]]);
await expense('advertising', '12000', day(8), 'Imprimerie du Centre', 'Affiches de rentrée', [['12000', day(9), 'cash', null]]);

// Two members of staff paid a fixed monthly amount; last month's pays generated by the accountant, validated by the direction.
const teacher = await direction.post('staff', { last_name_lat: 'Meziane', first_name_lat: 'Karim', last_name_ar: 'مزيان', first_name_ar: 'كريم',
  staff_type: 'teacher_internal', hire_date: '2020-09-01' });
const clerk = await direction.post('staff', { last_name_lat: 'Belkacem', first_name_lat: 'Omar', last_name_ar: 'بلقاسم', first_name_ar: 'عمر',
  staff_type: 'administrative', hire_date: '2019-09-01' });
// The teacher signs in with their own account and reads their pays.
const account = (await direction.get('staff/accounts?q=enseignant'))[0];
await direction.post(`staff/${teacher.id}/account`, { user_id: account.id, version: teacher.version });
for (const [staff, amount] of [[teacher, '45000'], [clerk, '38000']]) {
  await comptable.post(`staff/${staff.id}/pay-rules`, { kind: 'fixed_monthly', monthly_amount: amount, valid_from: shift(today, -2), idempotency_key: key() });
}
await comptable.post('payroll-runs/generate', { period: month });
const runs = await comptable.get(`payroll-runs?period=${month}`);
const runOf = staff => runs.find(run => run.staff_id === staff.id);
for (const run of runs) await direction.post(`payroll-runs/${run.id}/validate`, { version: run.version });
const payout = async (staff, amount, on, method, ref) => {
  const done = await comptable.post(`payroll-runs/${runOf(staff).id}/payouts`, { amount, paid_on: on, method_code: method, external_ref: ref, idempotency_key: key() });
  note('Versement', `${staff.first_name_lat} ${staff.last_name_lat}, ${amount} DA, ${means[method]}${ref ? ' ' + ref : ''}, le ${on}`);
  return done;
};
const karim = await payout(teacher, '45000', day(28), 'transfer', 'VIR 2026-140');
await payout(clerk, '20000', day(15), 'cash', null);
await payout(clerk, '18000', day(28), 'cash', null);

// What last month's reconciliation should tell, before anything is taken back.
const expected = {
  payments_total: '375000.00', 'payments.cash.amount': '120000.00', 'payments.cheque.amount': '30000.00', 'payments.transfer.amount': '225000.00',
  'reversals.amount': '0.00', refunds_total: '20000.00', net: '355000.00',
  expenses_total: '240000.00', 'expenses.salaries.amount': '83000.00', 'expenses.rent.amount': '80000.00', 'expenses.equipment.amount': '65000.00', 'expenses.advertising.amount': '12000.00',
  expenses_committed: '240000.00', expenses_paid: '215000.00', 'payroll_payouts.amount': '83000.00', 'payroll_reversals.amount': '0.00',
  payroll_paid: '83000.00', salaries_committed: '83000.00', cash_balance: '140000.00',
};
const pick = (report, path) => path.split('.').reduce((value, part) => value?.[part], report) ?? (path.includes('.') ? '0.00' : undefined);
const read = async on => comptable.get(`finance-periods/reconciliation?month=${on.slice(0, 7)}`);
const before = await read(month);

// This month, after the fact: Nadir's payment was entered for the wrong pupil; Karim's transfer came back from the bank and is paid again.
let reversal = await comptable.post(`payments/${nadir.id}/reverse`, { reason: 'Paiement saisi pour le mauvais élève', idempotency_key: key() });
reversal = await tresorier.post(`payments/${reversal.id}/validate`, { version: reversal.version });
note(`Contre-passation ${reversal.reference}`, `du paiement ${nadir.reference} de Nadir, le ${today} (mois en cours)`);
let back = await comptable.post(`payroll-payouts/${karim.id}/reverse`, { reason: 'Virement rejeté par la banque (RIB erroné)', idempotency_key: key() });
back = await direction.post(`payroll-payouts/${back.id}/validate`, { version: back.version });
note('Contre-opération de versement', `les 45000 DA de Karim Meziane, le ${today} (mois en cours)`);
await payout(teacher, '45000', today, 'transfer', 'VIR 2026-188');
const after = await read(month);
const current = await read(today);
const expectedNow = { 'payments_total': '0.00', 'reversals.amount': '50000.00', net: '-50000.00', 'payroll_payouts.amount': '45000.00',
  'payroll_reversals.amount': '45000.00', payroll_paid: '0.00', salaries_committed: '0.00' };

// The screens, in French and Arabic, as the accountant and the teacher see them.
async function shots(who, pages) {
  for (const locale of ['fr', 'ar']) {
    await who.put('account/locale', { locale }).catch(() => {});
    for (const [name, path] of pages) {
      await who.page.goto(`${base}/school/${path}`, { waitUntil: 'networkidle' });
      await who.page.waitForTimeout(300);
      await who.page.screenshot({ path: `${out}/ecrans/${locale}-${name}.png`, fullPage: true });
    }
  }
  await who.put('account/locale', { locale: 'fr' }).catch(() => {});
}
await shots(comptable, [
  ...Object.entries(pupils).map(([code, pupil]) => [`eleve-${code}`, `enrollment-finance?enrollment=${pupil.enrollment}`]),
  ['impayes', 'arrears'], ['depenses', 'expenses'], ['paiements-depenses', 'expense-payments'], ['remunerations', 'payroll'],
  ['remuneration-karim', `payroll-run?run=${runOf(teacher).id}`], ['remuneration-omar', `payroll-run?run=${runOf(clerk).id}`], ['cloture', 'finance-periods'],
]);
await shots(enseignant, [['ma-remuneration', 'my-pay']]);
await browser.close();

// The sheet the finance reads, figures beside what is expected.
const rows = (wanted, report) => Object.entries(wanted).map(([path, value]) => {
  const actual = pick(report, path);
  return { path, value, actual, ok: actual === value };
});
const checks = [...rows(expected, before), ...rows(expected, after).map(row => ({ ...row, path: row.path + ' (après les reprises)' })),
  ...rows(expectedNow, current).map(row => ({ ...row, path: row.path + ' (mois en cours)' }))];
// The lines as the closure's window names them.
const labels = {
  payments_total: 'Paiements', 'payments.cash.amount': '… en espèces', 'payments.cheque.amount': '… par chèque', 'payments.transfer.amount': '… par virement',
  'reversals.amount': 'Contre-passations', refunds_total: 'Remboursements', net: 'Net encaissé', expenses_total: 'Dépenses validées',
  'expenses.salaries.amount': '… salaires', 'expenses.rent.amount': '… loyer', 'expenses.equipment.amount': '… équipement', 'expenses.advertising.amount': '… publicité',
  expenses_committed: 'Dépenses engagées (corrections déduites)', expenses_paid: 'Dépenses réglées', 'payroll_payouts.amount': 'Versements de rémunérations',
  'payroll_reversals.amount': 'Contre-opérations de versements', payroll_paid: 'Rémunérations versées (net)', salaries_committed: 'Salaires engagés',
  cash_balance: 'Solde de trésorerie',
};
const table = list => ['| Ligne | Attendu | Calculé | |', '|---|---:|---:|---|',
  ...list.map(row => `| ${labels[row.path] ?? row.path} | ${row.value} | ${row.actual} | ${row.ok ? '✓' : '✗'} |`)].join('\n');
writeFileSync(`${out}/fiche-revue.md`, `# Revue du lot 3 : fiche de l'école « École Les Oliviers »

Générée le ${today}. Mois revu : **${monthLabel}** (ouvert, à clôturer pendant la revue). Mois en cours : ${today.slice(0, 7)}.
Installation : ${base}/school. Comptes et codes : \`comptes.txt\` à côté de cette fiche.

## Opérations saisies

| Opération | Détail |
|---|---|
${log.map(([what, detail]) => `| ${what} | ${detail} |`).join('\n')}

## Rapprochement de ${monthLabel}

Attendu calculé à la main avant la saisie, comparé à celui que donne l'application.

${table(rows(expected, before))}

### Le même mois après les reprises de ce mois-ci

Une reprise est datée du jour où elle est décidée : ${monthLabel} ne change pas.

${table(rows(expected, after))}

## Mois en cours (${today.slice(0, 7)})

${table(rows(expectedNow, current))}

## Reçus (dossier \`recus\`)

Huit PDF : sept reçus en arabe, un en français (Sara), et le premier reçu d'Amine réimprimé, marqué « نسخة طبق الأصل ».

## Écrans (dossier \`ecrans\`)

Chaque écran de la finance en français (\`fr-*\`) et en arabe (\`ar-*\`), et « Ma rémunération » de l'enseignant.
`);
writeFileSync(`${out}/rapprochement.json`, JSON.stringify({ month: before, month_after: after, current }, null, 2));
const wrong = checks.filter(row => !row.ok);
console.log(`${checks.length} figures checked, ${wrong.length} different from what is expected; the pack in ${out}.`);
if (wrong.length) {
  for (const row of wrong) console.log(`FAIL ${row.path}: expected ${row.value}, got ${row.actual}`);
  process.exit(1);
}
