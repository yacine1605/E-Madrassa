const {execFileSync} = require('node:child_process');
const {chromium} = require('../.test-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {randomUUID} = require('node:crypto');
const project = 'madrassa-integration-bulletins-' + Date.now().toString(36);
const base = 'http://localhost:18189';
const env = {...process.env,COMPOSE_PROJECT_NAME:project,COMPOSE_FILE:['compose.yaml','tests/compose.test.yaml'].join(path.delimiter),APP_PORT:'18189',APP_URL:base,DB_DATABASE:'bulletins_test',DB_PASSWORD:'local-test-only'};
const compose = args => execFileSync('docker-compose',args,{env,encoding:'utf8',stdio:['ignore','pipe','pipe']});
const fixture = action => JSON.parse(compose(['run','--rm','--no-deps','-T','--entrypoint','php','-v','./tests:/integration:ro','app','/integration/backend-fixture.php',action]));
(async()=>{
    let browser;
    try {
        compose(['up','-d','--no-build']);
        let ready = false;
        for(let i=0;i<60;i++) {
            try { if((await fetch(base+'/school-api/csrf')).ok) {ready=true;break;} } catch {}
            await new Promise(r=>setTimeout(r,500));
        }
        assert.ok(ready,'Application starts after report card migrations');
        const user = fixture('setup');
        browser = await chromium.launch({channel:'msedge',headless:true});
        const page = await browser.newPage();
        await page.goto(base+'/admin/login');
        await page.locator('input[type=email]').fill(user.email);
        await page.locator('input[type=password]').fill(user.password);
        await page.locator('button[type=submit]').click();
        await page.waitForURL('**/admin/dashboard');
        const docs = base+'/documents/'+user.tenant;
        assert.equal((await page.request.get(docs,{headers:{Accept:'application/json'}})).status(),403,'Pedagogy permission required');
        fixture('grant');
        const csrf = (await (await page.request.get(base+'/school-api/csrf')).json()).csrf_token;
        const api = base+'/school-api/v1/tenants/'+user.tenant;
        const send = (url,data,method='post')=>page.request[method](url,{headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},data,maxRedirects:0});
        const create = async(resource,data)=>{
            const response = await send(api+'/'+resource,data);
            assert.equal(response.status(),201,resource+': '+await response.text());
            return (await response.json()).data;
        };
        const campus = await create('campuses',{name:'School'});
        const period = await create('periods',{name:'2026/2027',starts_on:'2026-09-01',ends_on:'2027-06-30'});
        fs.mkdirSync('tests/artifacts',{recursive:true});
        for (const [cycle,scale] of [['PRIMAIRE',10],['MOYEN',20],['SECONDAIRE',20]]) {
            // Reset only this disposable installation's cache between fixture batches.
            compose(['exec','-T','app','php','artisan','cache:clear']);
            const program = await create('programs',{code:cycle,name:cycle,kind:'school'});
            const level = await create('levels',{program_id:program.id,code:'TEST-'+cycle,name:cycle+' niveau'});
            const subject = await create('subjects',{code:'TEST-'+cycle,name:'الرياضيات — '+cycle});
            await create('curriculum',{level_id:level.id,subject_id:subject.id,coefficient:2});
            let qualitative;
            if(cycle === 'PRIMAIRE') {
                qualitative = await create('subjects',{code:'TEST-QUALITATIVE',name:'Observation qualitative'});
                await create('curriculum',{level_id:level.id,subject_id:qualitative.id,coefficient:1});
            }
            const offering = await create('offerings',{level_id:level.id,period_id:period.id,campus_id:campus.id,name:cycle,price_minor:100000});
            const cohort = await create('cohorts',{offering_id:offering.id,name:'Classe '+cycle,capacity:30});
            const student = await create('students',{matricule:cycle+'-001',first_name:'أمين',last_name:'بن علي',birth_date:'2015-01-01'});
            const enrollment = await create('enrollments',{student_id:student.id,cohort_id:cohort.id,offering_id:offering.id,due_on:'2026-10-01',idempotency_key:randomUUID()});
            for(const [kind,score] of [['continu',14],['devoir',16],['examen',18]]) {
                const assessment = await create('assessments',{cohort_id:cohort.id,subject_id:subject.id,kind,title:kind,max_score:20,coefficient:1,held_on:'2026-10-01'});
                const response = await send(api+'/grades',{assessment_id:assessment.id,enrollment_id:enrollment.id,status:'marked',score},'put');
                assert.equal(response.status(),200,await response.text());
                assert.equal((await send(api+'/assessments/'+assessment.id+'/transition',{reason:'Publication test'})).status(),200);
            }
            await page.goto(docs+'?cohort='+cohort.id);
            await page.getByText('Configurer un trimestre',{exact:true}).click();
            await page.locator('[name=starts_on]').fill('2026-09-01');
            await page.locator('[name=ends_on]').fill('2026-12-31');
            await page.locator('[name=scale]').selectOption(String(scale));
            await page.locator('[name=reference]').fill('Test: règle explicitement configurée, pas une circulaire officielle');
            for(const [key,value] of [['coefficient','2'],['continu','1'],['devoir','1'],['examen','4']])
                await page.locator('[name="rules['+subject.id+']['+key+']"]').fill(value);
            if(qualitative) {
                await page.locator('[name="rules['+qualitative.id+'][coefficient]"]').fill('1');
                await page.locator('[name="rules['+qualitative.id+'][qualitative]"]').check();
            }
            await page.getByRole('button',{name:'Enregistrer le trimestre',exact:true}).click();
            await page.getByText('Trimestre configuré pour ce niveau et cette année scolaire.',{exact:true}).waitFor();
            await page.getByRole('link',{name:'Imprimer les bulletins du trimestre 1',exact:true}).click();
            await page.locator('.sheet').waitFor();
            assert.ok((await page.locator('.result').innerText()).includes(scale===10?'8,50 / 10':'17,00 / 20'));
            const printUrl = page.url();
            assert.equal((await page.request.get(printUrl.replace(user.tenant,randomUUID()),{headers:{Accept:'application/json'}})).status(),403,'Foreign tenant denied');
            assert.equal((await page.request.get(printUrl+'?enrollment='+randomUUID(),{headers:{Accept:'application/json'}})).status(),404,'Foreign enrollment denied');
            await page.locator('summary').click();
            for(const textarea of await page.locator('textarea').all()) await textarea.fill('عمل جيد — Bon travail');
            await page.getByRole('button',{name:'Enregistrer les observations',exact:true}).click();
            await page.getByText('Observations enregistrées.',{exact:true}).waitFor();
            assert.ok((await page.locator('.sheet').innerText()).includes('عمل جيد'));
            await page.emulateMedia({media:'print'});
            await page.pdf({path:'tests/artifacts/bulletin-'+cycle.toLowerCase()+'.pdf',format:'A4',preferCSSPageSize:true});
            await page.screenshot({path:'tests/artifacts/bulletin-'+cycle.toLowerCase()+'.png',fullPage:true});
            await page.emulateMedia({media:'screen'});
            if(cycle === 'SECONDAIRE') {
                const missing = await create('assessments',{cohort_id:cohort.id,subject_id:subject.id,kind:'examen',title:'Missing mark',max_score:20,coefficient:1,held_on:'2026-11-01'});
                assert.equal((await send(api+'/assessments/'+missing.id+'/transition',{reason:'Publication test'})).status(),200);
                await page.reload();
                assert.ok((await page.locator('.result').innerText()).includes('Non calculable'),'Missing published mark blocks overall');
                await page.getByText('Bulletin incomplet',{exact:false}).waitFor();
            }
            if(cycle === 'MOYEN') {
                compose(['exec','-T','app','php','artisan','cache:clear']);
                // Independent transcription of the scanned annex, including the art alternative.
                const expected = {
                    'MOY-AR':[2,3,3,5], 'MOY-MATH':[2,3,3,4], 'MOY-FR':[1,2,2,3], 'MOY-EN':[1,2,2,3],
                    'MOY-SVT':[1,2,2,2], 'MOY-PHYS':[1,2,2,2], 'MOY-HG':[2,2,2,3], 'MOY-ISLAM':[1,1,1,2],
                    'MOY-CIV':[1,1,1,1], 'MOY-SPORT':[1,1,1,1], 'MOY-AMAZ':[2,2,2,2], 'MOY-IT':[1,1,1,1],
                    'MOY-ART':[1,1,1,1], 'MOY-MUSIC':[1,1,1,1],
                };
                const middleSubjects = {};
                for(const code of Object.keys(expected)) middleSubjects[code] = await create('subjects',{code,name:code});
                let fourth;
                for(let grade=1; grade<=4; grade++) {
                    const middleLevel = await create('levels',{program_id:program.id,code:grade%2?String(grade):grade+'AM',name:grade+'AM'});
                    for(const item of Object.values(middleSubjects))
                        await create('curriculum',{level_id:middleLevel.id,subject_id:item.id,coefficient:99});
                    const middleOffer = await create('offerings',{level_id:middleLevel.id,period_id:period.id,campus_id:campus.id,name:grade+'AM',price_minor:100000});
                    const middleClass = await create('cohorts',{offering_id:middleOffer.id,name:grade+'AM',capacity:30});
                    await page.goto(docs+'?cohort='+middleClass.id);
                    await page.getByText('Configurer un trimestre',{exact:true}).click();
                    for(const [code,values] of Object.entries(expected))
                        assert.equal(await page.locator('[name="rules['+middleSubjects[code].id+'][coefficient]"]').inputValue(),String(values[grade-1]),code+' '+grade+'AM');
                    assert.equal(await page.locator('[name=scale]').inputValue(),'20');
                    assert.match(await page.locator('[name=reference]').inputValue(),/470.*19\/09\/2026/);
                    assert.ok(await page.locator('[name="rules['+middleSubjects['MOY-MUSIC'].id+'][qualitative]"]').isChecked());
                    assert.ok(!(await page.locator('[name="rules['+middleSubjects['MOY-ART'].id+'][qualitative]"]').isChecked()));
                    assert.equal(await page.locator('[name="rules['+middleSubjects['MOY-AR'].id+'][examen]"]').inputValue(),'0','No assumed assessment weights');
                    if(grade===4) fourth={middleLevel,middleClass,middleOffer};
                }
                const nextYear = await create('periods',{name:'2027/2028',starts_on:'2027-09-01',ends_on:'2028-06-30'});
                const futureOffer = await create('offerings',{level_id:fourth.middleLevel.id,period_id:nextYear.id,campus_id:campus.id,name:'Future 4AM',price_minor:100000});
                const futureClass = await create('cohorts',{offering_id:futureOffer.id,name:'Future 4AM',capacity:30});
                await page.goto(docs+'?cohort='+futureClass.id);
                await page.getByText('Configurer un trimestre',{exact:true}).click();
                assert.equal(await page.locator('[name="rules['+middleSubjects['MOY-AR'].id+'][coefficient]"]').inputValue(),'','2026 profile is not applied to another year');

                const pupil = await create('students',{matricule:'MIDDLE-PRESET',first_name:'Test',last_name:'Coefficients'});
                const registered = await create('enrollments',{student_id:pupil.id,cohort_id:fourth.middleClass.id,offering_id:fourth.middleOffer.id,due_on:'2026-10-01',idempotency_key:randomUUID()});
                for(const [code,score] of [['MOY-AR',14],['MOY-MATH',20]]) {
                    const exam = await create('assessments',{cohort_id:fourth.middleClass.id,subject_id:middleSubjects[code].id,kind:'examen',title:code,max_score:20,coefficient:1,held_on:'2026-10-01'});
                    assert.equal((await send(api+'/grades',{assessment_id:exam.id,enrollment_id:registered.id,status:'marked',score},'put')).status(),200);
                    assert.equal((await send(api+'/assessments/'+exam.id+'/transition',{reason:'Publication test'})).status(),200);
                }
                await page.goto(docs+'?cohort='+fourth.middleClass.id);
                await page.getByText('Configurer un trimestre',{exact:true}).click();
                await page.locator('[name=starts_on]').fill('2026-09-01');
                await page.locator('[name=ends_on]').fill('2026-12-31');
                await page.locator('[name=reference]').fill('MEN 470 : coefficients ; calcul de test : composition seule');
                for(const [code,item] of Object.entries(middleSubjects)) {
                    await page.locator('[name="rules['+item.id+'][examen]"]').fill('1');
                    await page.locator('[name="rules['+item.id+'][qualitative]"]').setChecked(!['MOY-AR','MOY-MATH','MOY-ART','MOY-MUSIC'].includes(code));
                }
                // A custom edit and both unchecked art alternatives must survive a validation failure.
                await page.locator('[name="rules['+middleSubjects['MOY-CIV'].id+'][coefficient]"]').fill('1.5');
                await page.getByRole('button',{name:'Enregistrer le trimestre',exact:true}).click();
                await page.getByRole('alert').getByText('Arts plastiques ou musique :',{exact:false}).waitFor();
                await page.getByText('Configurer un trimestre',{exact:true}).click();
                assert.equal(await page.locator('[name="rules['+middleSubjects['MOY-CIV'].id+'][coefficient]"]').inputValue(),'1.5','Custom coefficients survive validation');
                assert.ok(!(await page.locator('[name="rules['+middleSubjects['MOY-MUSIC'].id+'][qualitative]"]').isChecked()),'Failed submission does not restore preset checkbox');
                for(const code of ['MOY-ART','MOY-MUSIC']) await page.locator('[name="rules['+middleSubjects[code].id+'][qualitative]"]').check();
                await page.getByRole('button',{name:'Enregistrer le trimestre',exact:true}).click();
                await page.getByText('Trimestre configuré pour ce niveau et cette année scolaire.',{exact:true}).waitFor();
                await page.getByRole('link',{name:'Imprimer les bulletins du trimestre 1',exact:true}).click();
                assert.ok((await page.locator('.result').innerText()).includes('16,67 / 20'),'4AM subject coefficients weight the actual bulletin: (14*5+20*4)/9');
                // GET suggestions and saving a term leave the generic curriculum untouched.
                const catalog = await (await page.request.get(api+'/curriculum?limit=100&level_id='+fourth.middleLevel.id)).json();
                assert.equal(catalog.data.length,14);
                assert.ok(catalog.data.every(row=>Number(row.coefficient)===99));
            }
        }
        console.log('PASS bulletins: primary /10, middle and secondary /20, published grades, term configuration, observations, access, A4 PDF; 1AM–4AM coefficient presets, year scope, art alternatives, validation retries, unchanged curriculum and weighted bulletin.');
    } finally {
        if(browser) await browser.close();
        compose(['down','-v','--remove-orphans']);
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
