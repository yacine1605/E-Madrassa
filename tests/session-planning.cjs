const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = {window:{}};
vm.runInNewContext(fs.readFileSync('ecole-filament/public/js/school-backend.js','utf8'),context);
const state = context.window.schoolBackend({catalog:{subjects:{permission:'catalog'}}});
state.resource = 'sessions';
state.choices.room_id = ['valid','small','foreign'].map(value=>({value}));
state.references = {
    cohorts:{group:{offering_id:'offer',capacity:25}},
    offerings:{offer:{campus_id:'campus'}},
    rooms:{valid:{campus_id:'campus',capacity:25},small:{campus_id:'campus',capacity:24},foreign:{campus_id:'other',capacity:40}},
};
assert.equal(state.relationOptions('room_id').length,0);
state.form.cohort_id = 'group';
assert.deepEqual(Array.from(state.relationOptions('room_id'),o=>o.value),['valid']);
state.form.room_id = 'valid';
state.formFieldChanged('cohort_id');
assert.equal(state.form.room_id,'');
state.resource = 'rooms';
assert.equal(state.relationOptions('room_id').length,3);
console.log('PASS: room filtering and group change');
