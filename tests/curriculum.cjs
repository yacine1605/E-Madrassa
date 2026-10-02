const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = {window:{},URLSearchParams};
vm.runInNewContext(fs.readFileSync('ecole-filament/public/js/school-backend.js','utf8'),context);
(async()=>{
    const state = context.window.schoolBackend({catalog:{subjects:{permission:'catalog'}}});
    state.resource = 'sessions'; state.school = {permissions:['catalog.read']};
    let pending = {};
    state.fetchChoices = async (resource,params) => new Promise(resolve=>pending[params.cohort_id]=resolve);
    state.form = {cohort_id:'first',subject_id:'old',room_id:'old-room'};
    const first = state.formFieldChanged('cohort_id');
    assert.equal(state.form.subject_id,''); assert.equal(state.form.room_id,'');
    assert.equal(state.choices.subject_id.length,0); assert.equal(state.subjectsLoading,true);
    state.form.cohort_id = 'second';
    const second = state.formFieldChanged('cohort_id');
    pending.second([{id:'math',name:'الرياضيات'}]); await second;
    pending.first([{id:'stale',name:'اللغة الفرنسية'}]); await first;
    assert.equal(state.choices.subject_id[0].value,'math');
    assert.equal(state.subjectsLoading,false);
    state.form.cohort_id = ''; await state.formFieldChanged('cohort_id');
    assert.equal(state.choices.subject_id.length,0);
    for (const resource of ['assessments','teacher_assignments']) { state.resource=resource; assert.equal(state.scopedSubjects(),true); }
    state.resource='curriculum'; assert.equal(state.scopedSubjects(),false);
    console.log('PASS: dependent subjects, stale responses, reset, empty class, affected forms');
})().catch(error=>{console.error(error);process.exit(1);});
