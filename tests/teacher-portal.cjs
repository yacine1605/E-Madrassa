const {execFileSync} = require('node:child_process');
const {chromium} = require('../.test-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const project = 'madrassa-integration-teacher-' + Date.now().toString(36);
const base = 'http://localhost:18183';
const env = {...process.env,COMPOSE_PROJECT_NAME:project,COMPOSE_FILE:['compose.yaml','tests/compose.test.yaml'].join(path.delimiter),APP_PORT:'18183',APP_URL:base,DB_DATABASE:'teacher_portal_test',DB_PASSWORD:'local-test-only'};
const compose = args => execFileSync('docker-compose',args,{env,encoding:'utf8',stdio:['ignore','pipe','pipe']});
const fixture = action => JSON.parse(compose(['run','--rm','--no-deps','-T','--entrypoint','php','-v','./tests:/integration:ro','app','/integration/teacher-portal-fixture.php',action]));
(async()=>{
    let browser;
    try {
        compose(['up','-d','--no-build']);
        let ready = false;
        for (let i=0;i<60;i++) {
            try {if ((await fetch(base+'/enseignant/login')).ok) {ready=true;break;}} catch {}
            await new Promise(resolve=>setTimeout(resolve,500));
        }
        assert.ok(ready,'Portal starts');
        const data = fixture('setup');
        browser = await chromium.launch({channel:'msedge',headless:true});
        const page = await browser.newPage();
        const errors=[];page.on('pageerror',error=>errors.push(error.message));
        await page.goto(base+'/enseignant');
        assert.ok(page.url().endsWith('/enseignant/login'));
        await page.locator('input[name=email]').fill(data.teachers[0].email);
        await page.locator('input[name=password]').fill(data.teachers[0].password);
        await page.getByRole('button',{name:'Se connecter',exact:true}).click();
        await page.waitForURL(base+'/enseignant');
        await page.getByRole('heading',{name:'Classe 1',exact:true}).waitFor();
        assert.equal(await page.getByText('Classe 2',{exact:true}).count(),0);
        await page.getByRole('link',{name:'Voir les élèves',exact:false}).click();
        await page.getByRole('cell',{name:'STUDENT-1',exact:true}).waitFor();
        assert.ok(!(await page.content()).includes('PRIVATE-PHONE'));
        assert.ok(!(await page.content()).includes('STUDENT-2'));
        assert.equal((await page.request.get(base+'/enseignant/classes/'+data.teachers[1].cohort)).status(),404);
        assert.equal((await page.request.get(base+'/enseignant/classes/not-a-uuid')).status(),404);
        const headers={Accept:'application/json'};
        for(const resource of ['students','staff','payments','teacher_assignments']) {
            assert.equal((await page.request.get(base+'/school-api/v1/tenants/'+data.tenant+'/'+resource,{headers})).status(),403);
        }
        assert.equal((await page.request.post(base+'/enseignant/compte/password',{headers,data:{}})).status(),419,'CSRF required');
        fs.mkdirSync('tests/artifacts',{recursive:true});
        await require('./teacher-grades.cjs')(page,base,data,fixture);
        await page.goto(base+'/admin/students');
        await page.waitForURL(base+'/enseignant');
        await page.getByRole('link',{name:'Mon planning',exact:true}).click();
        await page.getByText('Salle 1',{exact:false}).waitFor();
        await page.getByText('08:00',{exact:false}).waitFor();
        assert.equal(await page.getByText('Salle 2',{exact:false}).count(),0);
        await page.goto(base+'/enseignant/compte');
        await page.locator('input[name=current_password]').fill('wrong-current-password');
        await page.locator('input[name=password]').fill('Changed-password-12345');
        await page.locator('input[name=password_confirmation]').fill('Changed-password-12345');
        await page.getByRole('button',{name:'Enregistrer mon mot de passe'}).click();
        await page.getByRole('alert').waitFor();
        await page.locator('input[name=current_password]').fill(data.teachers[0].password);
        await page.locator('input[name=password]').fill('Changed-password-12345');
        await page.locator('input[name=password_confirmation]').fill('Changed-password-12345');
        await page.getByRole('button',{name:'Enregistrer mon mot de passe'}).click();
        await page.getByRole('status').waitFor();
        await page.getByRole('button',{name:'Déconnexion'}).click();
        await page.waitForURL('**/enseignant/login');
        await page.locator('input[name=email]').fill(data.teachers[0].email);
        await page.locator('input[name=password]').fill('Changed-password-12345');
        await page.getByRole('button',{name:'Se connecter',exact:true}).click();
        await page.waitForURL(base+'/enseignant');
        fs.mkdirSync('tests/artifacts',{recursive:true});
        await page.screenshot({path:'tests/artifacts/teacher-portal-desktop.png',fullPage:true});
        await page.setViewportSize({width:390,height:844});
        await page.screenshot({path:'tests/artifacts/teacher-portal-mobile.png',fullPage:true});
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
        assert.equal(fixture('disable').disabled,true);
        assert.equal((await page.request.get(base+'/enseignant')).status(),403,'Revocation takes effect for existing session');
        assert.deepEqual(errors,[]);
        console.log('PASS teacher login, assigned roster, private data filtering, cross-teacher denial, admin API denial, CSRF, timezone, password change, logout, mobile layout, immediate revocation');
    } finally {
        if(browser) await browser.close();
        if(!project.startsWith('madrassa-integration-teacher-')) throw new Error('Unexpected test project');
        compose(['down','-v']);
    }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
