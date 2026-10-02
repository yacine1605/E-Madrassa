const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = {window:{}};
vm.runInNewContext(fs.readFileSync('ecole-filament/public/js/school-backend.js','utf8'),context);
(async()=>{
    const state = context.window.schoolBackend({catalog:{staff:{permission:'hr'}}});
    state.permitted = ()=>true;
    state.resource = 'sessions';
    state.form = {cohort_id:'class',subject_id:'math',teacher_id:'wrong'};
    state.fetchChoices = async(resource,params)=>{
        assert.equal(resource,'staff'); assert.equal(params.cohort_id,'class'); assert.equal(params.subject_id,'math');
        return [{id:'eligible',name:'Teacher'}];
    };
    await state.refreshTeachers();
    assert.equal(state.form.teacher_id,'');
    assert.deepEqual(Array.from(state.choices.teacher_id,x=>x.value),['eligible']);
    let resolve;
    state.fetchChoices = ()=>new Promise(r=>resolve=r);
    const pending = state.refreshTeachers();
    state.form.subject_id = '';
    await state.formFieldChanged('subject_id');
    resolve([{id:'stale',name:'Stale'}]); await pending;
    assert.equal(state.choices.teacher_id.length,0);
    assert.equal(state.teachersLoading,false);
    state.resource = 'teacher_assignments'; assert.equal(state.scopedTeachers(),true);
    state.resource = 'teacher_qualifications'; assert.equal(state.scopedTeachers(),false);
    console.log('PASS: scoped teacher choices, cleared selection, stale response protection');
})().catch(error=>{console.error(error);process.exitCode=1;});
