const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = vm.createContext({window: {}});
for (const file of ['school-presentation.js', 'school-backend.js']) {
    vm.runInContext(fs.readFileSync('ecole-filament/public/js/' + file, 'utf8'), context);
}
const app = context.window.schoolBackend({relations: {}});
app.resource = 'payroll_runs';
for (const calculation of [
    {basis: 'hourly', rate_minor: 120000, minutes: 150},
    JSON.stringify({basis: 'hourly', rate_minor: 120000, minutes: 150}),
]) {
    const row = {staff_id: 'teacher', month: '2026-10-01', amount_minor: 300000, calculation};
    app.decoratePay(row);
    assert.equal(row.worked_hours, 2.5);
    assert.equal(row.basis, 'hourly');
    assert.equal(row.rate_minor, 120000);
    assert.equal(app.display(row.worked_hours, 'worked_hours'), '2 h 30 min');
    app.rows = [row];
    assert.ok(app.columns().includes('worked_hours'));
    assert.ok(app.columns().includes('rate_minor'));
}
const missing = {calculation: 'invalid'};
app.decoratePay(missing);
assert.equal(app.display(missing.worked_hours, 'worked_hours'), '—');
const zero = {calculation: {basis: 'monthly', rate_minor: 5000000, minutes: 0}};
app.decoratePay(zero);
assert.equal(app.display(zero.worked_hours, 'worked_hours'), '0 h 00 min');
app.resource = 'work_logs';
const log = {minutes: 95};
app.decoratePay(log);
assert.equal(app.display(log.worked_hours, 'worked_hours'), '1 h 35 min');
console.log('Hourly remuneration display checks passed.');
