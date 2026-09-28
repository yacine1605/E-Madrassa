const assert = require('node:assert/strict');

module.exports = async function testGrades(page, base, data, fixture) {
    const index = base+'/enseignant/notes';
    const headers={Accept:'application/json'};
    await page.goto(index);
    await page.getByRole('heading',{name:'Devoirs et examens',exact:true}).waitFor();
    assert.ok(!(await page.content()).includes('Private French exam'));
    assert.ok(!(await page.content()).includes('Assessment 2'));
    const csrf = await page.locator('input[name=_token]').first().inputValue();
    const send = (url,payload) => page.request.post(url,{headers:{...headers,'X-CSRF-TOKEN':csrf},data:payload,maxRedirects:0});
    const badCreate = await send(index,{assignment_id:data.teachers[1].assignment,kind:'devoir',title:'Forbidden',held_on:'2026-10-01',max_score:20});
    assert.equal(badCreate.status(),404,'Cannot create evaluation for another teacher');
    for(const id of [data.otherSubjectAssessment,data.teachers[1].assessment]) {
        assert.equal((await page.request.get(index+'/'+id,{headers})).status(),404,'Cannot read unassigned class/subject');
        assert.equal((await send(index+'/'+id,{grades:[]})).status(),404,'Cannot write unassigned class/subject');
    }
    await page.locator('select[name=kind]').selectOption('devoir');
    await page.locator('input[name=title]').fill('Devoir 1');
    await page.getByRole('button',{name:'Créer et saisir les notes'}).click();
    await page.getByRole('heading',{name:'Devoir 1',exact:true}).waitFor();
    const devoir = page.url();
    assert.equal(await page.getByText('CANCELLED',{exact:true}).count(),0);
    const payload = async () => page.locator('[data-grade-row]').evaluateAll(rows=>rows.map(row=>({
        enrollment_id:row.querySelector('input[name$="[enrollment_id]"]').value,
        version:row.querySelector('input[name$="[version]"]').value,
        status:row.querySelector('select').value,
        score:row.querySelector('[data-grade-score]').value || null,
    })));
    const original = await payload();
    assert.equal(original.length,2);
    let changed = structuredClone(original);
    changed[0].score=12.5;changed[1].score=21;
    assert.equal((await send(devoir,{grades:changed})).status(),422,'Entire batch rejects score above maximum');
    assert.equal(fixture('grades').length,0,'Invalid batch writes nothing');
    changed[1].score=-1;
    assert.equal((await send(devoir,{grades:changed})).status(),422);
    changed[1].score=10.123;
    assert.equal((await send(devoir,{grades:changed})).status(),422,'Reject excess precision');
    changed=structuredClone(original);changed[0].score=12.5;changed[1].enrollment_id=data.teachers[1].enrollment;
    assert.equal((await send(devoir,{grades:changed})).status(),404,'Foreign enrollment rejects entire batch');
    changed[1].enrollment_id=data.cancelledEnrollment;
    assert.equal((await send(devoir,{grades:changed})).status(),404,'Cancelled enrollment rejected');
    assert.equal(fixture('grades').length,0);
    await page.getByRole('spinbutton',{name:'Note de STUDENT-1',exact:true}).fill('0');
    await page.getByRole('spinbutton',{name:'Note de STUDENT-1B',exact:true}).fill('12.5');
    await page.getByRole('button',{name:'Enregistrer les notes',exact:true}).click();
    await page.getByRole('status').waitFor();
    await page.reload();
    assert.equal(await page.getByRole('spinbutton',{name:'Note de STUDENT-1',exact:true}).inputValue(),'0.00');
    assert.equal(await page.getByRole('spinbutton',{name:'Note de STUDENT-1B',exact:true}).inputValue(),'12.50');
    const stale = await payload();
    changed=structuredClone(stale);changed[0].score=14;
    assert.equal((await send(devoir,{grades:changed})).status(),422,'Corrections need a reason');
    assert.equal((await send(devoir,{grades:changed,reason:'Copie revérifiée'})).status(),302);
    assert.equal((await send(devoir,{grades:stale,reason:'Stale overwrite'})).status(),422,'Outdated page cannot overwrite new results');
    const snapshot=fixture('grades');
    assert.equal(Number(snapshot.find(row=>row.matricule==='STUDENT-1').score),14);
    assert.equal(snapshot.find(row=>row.matricule==='STUDENT-1').reason,'Copie revérifiée');
    await page.goto(index);
    await page.locator('select[name=kind]').selectOption('examen');
    await page.locator('input[name=title]').fill('Examen 1');
    await page.getByRole('button',{name:'Créer et saisir les notes'}).click();
    await page.getByRole('heading',{name:'Examen 1',exact:true}).waitFor();
    const exam=page.url();
    await page.getByRole('combobox',{name:'Statut de STUDENT-1',exact:true}).selectOption('absent');
    assert.equal(await page.getByRole('spinbutton',{name:'Note de STUDENT-1',exact:true}).isDisabled(),true);
    await page.getByRole('button',{name:'Enregistrer les notes',exact:true}).click();
    await page.getByRole('status').waitFor();
    const examRows=fixture('grades').filter(row=>row.title==='Examen 1');
    assert.equal(examRows.length,1,'Blank stays ungraded');
    assert.equal(examRows[0].kind,'examen');assert.equal(examRows[0].status,'absent');assert.equal(examRows[0].score,null);
    const examPayload=await payload();
    assert.equal((await page.request.post(exam,{headers,data:{grades:examPayload}})).status(),419);
    const contradiction=structuredClone(examPayload);contradiction[0].score=10;
    assert.equal((await send(exam,{grades:contradiction,reason:'Test'})).status(),422,'Absence cannot carry a score');
    fixture('publish-exam');
    assert.equal((await send(exam,{grades:examPayload,reason:'Attempt published edit'})).status(),422);
    await page.reload();
    await page.getByText('Notes publiées : consultation uniquement.',{exact:true}).waitFor();
    assert.equal(await page.getByRole('button',{name:'Enregistrer les notes',exact:true}).count(),0);
    await page.goto(devoir);
    await page.screenshot({path:'tests/artifacts/teacher-grades-desktop.png',fullPage:true});
    await page.setViewportSize({width:390,height:844});
    await page.screenshot({path:'tests/artifacts/teacher-grades-mobile.png',fullPage:true});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
    assert.equal(await page.locator('.grades-table').evaluate(table=>table.scrollWidth>table.clientWidth),false,'Grade fields fit on mobile without horizontal scrolling');
    await page.setViewportSize({width:1280,height:720});
    console.log('PASS devoir/examen creation, class/subject/enrollment authorization, marks and decimals, batch atomicity, absence/blank/zero, correction reason, stale-page protection, publication lock, CSRF, mobile');
};
