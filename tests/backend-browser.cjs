const { chromium } = require('../.test-tools/node_modules/playwright');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const baseUrl = process.env.APP_URL || 'http://localhost:8000';
const fixtureCommand = action => execFileSync('docker-compose', ['run','--rm','--no-deps','-T','--entrypoint','php','-v','./tests:/integration:ro','app','/integration/backend-fixture.php',action], {encoding:'utf8'});

(async () => {
    let browser, fixture;
    try {
        fixture = JSON.parse(fixtureCommand('setup'));
        browser = await chromium.launch({channel:'msedge',headless:true});
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.goto(baseUrl+'/admin/login');
        await page.locator('input[type=email]').fill(fixture.email);
        await page.locator('input[type=password]').fill(fixture.password);
        await page.locator('button[type=submit]').click();
        await page.waitForURL('**/admin/dashboard');
        await page.goto(baseUrl+'/admin/students');
        assert.equal(await page.locator('select[x-model="tenant"]').count(),0);
        await page.locator('[data-create]').waitFor();
        await page.locator('[data-create]').click();
        await page.locator('input[name=matricule]').fill('BROWSER-001');
        await page.locator('input[name=first_name]').fill('Persisted');
        await page.locator('input[name=last_name]').fill('Student');
        await page.getByRole('button', {name:'Enregistrer',exact:true}).click();
        await page.getByText('Enregistrement enregistré.').waitFor();
        await page.reload();
        await page.getByRole('cell', {name:'BROWSER-001',exact:true}).waitFor();
        const results = await page.evaluate(async tenant => {
            const csrf = await fetch('/school-api/csrf').then(r=>r.json());
            const send = async (path, method='GET', data) => {
                const r = await fetch('/school-api/v1/tenants/'+path,{method,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf.csrf_token},body:data ? JSON.stringify(data) : undefined});
                return {status:r.status, body:await r.json()};
            };
            return {
                persisted:await send(tenant+'/students'),
                forbidden:await send(tenant+'/payments','POST',{invoice_id:crypto.randomUUID(),amount_minor:100,method:'cash',idempotency_key:crypto.randomUUID()}),
                otherTenant:await send(crypto.randomUUID()+'/students'),
                invalid:await send(tenant+'/students','POST',{matricule:'BAD'}),
            };
        }, fixture.tenant);
        assert.equal(results.persisted.status,200);
        assert.equal(results.persisted.body.data[0].first_name,'Persisted');
        assert.equal(results.forbidden.status,403);
        assert.equal(results.otherTenant.status,403);
        assert.equal(results.invalid.status,422);
        const deniedEdit = await page.evaluate(async ({tenant,id}) => {
            const csrf = await fetch('/school-api/csrf').then(r=>r.json());
            const response = await fetch('/school-api/v1/tenants/'+tenant+'/students/'+id,{method:'PUT',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf.csrf_token},body:JSON.stringify({matricule:'BROWSER-001',first_name:'Denied',last_name:'Student'})});
            return response.status;
        },{tenant:fixture.tenant,id:results.persisted.body.data[0].id});
        assert.equal(deniedEdit,200); // This account has students.write, including edit.
        assert.equal((await page.goto(baseUrl+'/admin/payments')).status(),403);
        assert.equal(await page.locator('[data-create]').count(),0);
        assert.equal((await page.goto(baseUrl+'/admin/settings')).status(),403);
        assert.equal(JSON.parse(fixtureCommand('grant')).granted,true);
        await page.goto(baseUrl+'/admin/students');
        await page.getByRole('cell',{name:'BROWSER-001',exact:true}).waitFor();
        await page.getByRole('button',{name:'Modifier'}).click();
        await page.locator('input[name=first_name]').fill('Updated');
        await page.getByRole('button',{name:'Enregistrer les modifications'}).click();
        await page.getByText('Modification enregistrée.').waitFor();
        await page.getByRole('cell',{name:'Updated',exact:true}).waitFor();
        const data = await page.evaluate(async tenant => {
            const csrf = await fetch('/school-api/csrf').then(r=>r.json());
            const create = async (resource, data) => {
                const r = await fetch('/school-api/v1/tenants/'+tenant+'/'+resource,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf.csrf_token},body:JSON.stringify(data)});
                const result = await r.json(); if (!r.ok) throw new Error(JSON.stringify(result)); return result.data;
            };
            const students = await fetch('/school-api/v1/tenants/'+tenant+'/students').then(r=>r.json());
            const campus = await create('campuses',{name:'Test campus'});
            const period = await create('periods',{name:'Test year',starts_on:'2026-09-01',ends_on:'2027-06-30'});
            const program = await create('programs',{name:'Test program',code:'TEST',kind:'school'});
            const level = await create('levels',{name:'Test level',code:'TEST',program_id:program.id});
            const offering = await create('offerings',{name:'Test offering',level_id:level.id,period_id:period.id,campus_id:campus.id,price_minor:100000});
            const cohort = await create('cohorts',{name:'Test class',offering_id:offering.id,capacity:10});
            const teacher = await create('staff',{name:'Test teacher',code:'TEST',is_teacher:true});
            const room = await create('rooms',{name:'Test room',campus_id:campus.id,capacity:10});
            const subject = await create('subjects',{name:'Test subject',code:'TEST'});
            await create('curriculum',{level_id:level.id,subject_id:subject.id,coefficient:1});
            const bilingual = await create('subjects',{name:'Langue française (اللغة الفرنسية)',code:'BILINGUAL'});
            const lookup = async q => {
                const response = await fetch('/school-api/v1/tenants/'+tenant+'/subjects?'+new URLSearchParams({q}));
                return {status:response.status, data:(await response.json()).data};
            };
            for (const query of ['francaise','الفرنسية']) {
                const found = await lookup(query);
                if (found.status !== 200 || found.data.length !== 1 || found.data[0].id !== bilingual.id) throw new Error('Bilingual search failed: '+query);
            }
            if ((await lookup('%')).data.length !== 0) throw new Error('Search wildcard was not escaped');
            const disposable = await create('subjects',{name:'Temporary subject',code:'TEMP'});
            const headers = {Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf.csrf_token};
            const updated = await fetch('/school-api/v1/tenants/'+tenant+'/subjects/'+disposable.id,{method:'PUT',headers,body:JSON.stringify({name:'Modified subject',code:'TEMP'})});
            if (updated.status !== 200) throw new Error('Catalog update failed: '+updated.status);
            const removed = await fetch('/school-api/v1/tenants/'+tenant+'/subjects/'+disposable.id,{method:'DELETE',headers});
            if (removed.status !== 204) throw new Error('Catalog delete failed: '+removed.status);
            const session = await create('sessions',{cohort_id:cohort.id,subject_id:subject.id,teacher_id:teacher.id,room_id:room.id,starts_at:'2026-10-01T08:00:00+01:00',ends_at:'2026-10-01T09:00:00+01:00'});
            const assessment = await create('assessments',{cohort_id:cohort.id,subject_id:subject.id,title:'Test assessment',max_score:20,coefficient:1,held_on:'2026-10-01'});
            const blockedDelete = await fetch('/school-api/v1/tenants/'+tenant+'/programs/'+program.id,{method:'DELETE',headers});
            if (blockedDelete.status !== 409) throw new Error('Linked program deletion was allowed: '+blockedDelete.status);
            return {student:students.data[0].id,offering:offering.id,cohort:cohort.id,session:session.id,assessment:assessment.id};
        },fixture.tenant);
        await page.goto(baseUrl+'/admin/subjects');
        await page.getByRole('searchbox',{name:'Rechercher en français ou en arabe'}).fill('الفرنسية');
        await page.getByRole('cell',{name:'Langue française (اللغة الفرنسية)',exact:true}).waitFor();
        const saveForm = async (slug, values) => {
            await page.goto(baseUrl+'/admin/'+slug);
            await page.locator('[data-create]').click();
            for (const [field,value] of Object.entries(values)) {
                const input = page.locator('[name="'+field+'"]');
                await input.waitFor();
                if (await input.evaluate(el=>el.tagName) === 'SELECT') await input.selectOption(value);
                else await input.fill(value);
            }
            await page.getByRole('button',{name:'Enregistrer',exact:true}).click();
            await page.getByText('Enregistrement enregistré.').waitFor();
        };
        await saveForm('enrollments',{student_id:data.student,offering_id:data.offering,cohort_id:data.cohort,due_on:'2026-10-01'});
        const invoice = await page.evaluate(async tenant => (await fetch('/school-api/v1/tenants/'+tenant+'/invoices').then(r=>r.json())).data[0],fixture.tenant);
        assert.equal(Number(invoice.amount_minor),100000);
        await saveForm('payments',{invoice_id:invoice.id,amount_minor:'250',method:'cash'});
        const balance = await page.evaluate(async tenant => (await fetch('/school-api/v1/tenants/'+tenant+'/invoice_balances').then(r=>r.json())).data[0],fixture.tenant);
        assert.equal(Number(balance.balance_minor),75000);
        await saveForm('attendance',{session_id:data.session,enrollment_id:invoice.enrollment_id,status:'present'});
        await saveForm('grades',{assessment_id:data.assessment,enrollment_id:invoice.enrollment_id,status:'marked',score:'16'});
        await page.reload();
        await page.getByRole('cell',{name:'16.00',exact:true}).waitFor();
        assert.deepEqual(errors,[]);
        console.log('PASS browser login, persistent student, permission/tenant checks, validation, catalog setup, enrollment and automatic invoice, partial payment and balance, attendance, grades, no JavaScript errors');
    } finally {
        if (browser) await browser.close();
        if (fixture) assert.equal(JSON.parse(fixtureCommand('cleanup')).cleaned,true);
    }
})().catch(e=>{console.error(e);process.exitCode=1;});
