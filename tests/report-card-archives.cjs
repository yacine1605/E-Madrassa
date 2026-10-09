const {execFileSync} = require('node:child_process');
const {chromium} = require('../.test-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {randomUUID} = require('node:crypto');
const project = 'madrassa-integration-archives-' + Date.now().toString(36);
const base = 'http://localhost:18192';
const env = {...process.env,COMPOSE_PROJECT_NAME:project,COMPOSE_FILE:['compose.yaml','tests/compose.test.yaml'].join(path.delimiter),APP_PORT:'18192',APP_URL:base,DB_DATABASE:'archives_test',DB_PASSWORD:'local-test-only'};
const compose = args => execFileSync('docker-compose',args,{env,encoding:'utf8',stdio:['ignore','pipe','pipe']});
const fixture = (file,action,...args) => {
    const output = compose(['run','--rm','--no-deps','-T','--entrypoint','php','-v','./tests:/integration:ro','app','/integration/'+file,action,...args]);
    try { return JSON.parse(output); } catch { throw new Error('Fixture '+action+': '+output); }
};
(async()=>{
    let browser;
    try {
        compose(['up','-d','--no-build']);
        let ready = false;
        for(let i=0;i<60;i++) {
            try { if((await fetch(base+'/school-api/csrf')).ok) {ready=true;break;} } catch {}
            await new Promise(r=>setTimeout(r,500));
        }
        assert.ok(ready,'Archive migration starts cleanly');
        const user = fixture('backend-fixture.php','setup');
        fixture('backend-fixture.php','grant');
        const data = fixture('report-card-archive-fixture.php','seed');
        browser = await chromium.launch({channel:'msedge',headless:true});
        const page = await browser.newPage();
        await page.goto(base+'/admin/login');
        await page.locator('input[type=email]').fill(user.email);
        await page.locator('input[type=password]').fill(user.password);
        await page.locator('button[type=submit]').click();
        await page.waitForURL('**/admin/dashboard');
        const docs = base+'/documents/'+user.tenant;
        const api = base+'/school-api/v1/tenants/'+user.tenant;
        const csrf = (await (await page.request.get(base+'/school-api/csrf')).json()).csrf_token;
        const send = (url,body,method='post') => page.request[method](url,{headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},data:body,maxRedirects:0});
        const termUrl = number => docs+'/bulletins/'+data.terms[number]+'/'+data.cohort;
        const version = async url => { await page.goto(url); return page.locator('[name=version]').first().inputValue(); };
        const post = async(url,body,expected) => {
            const r = await send(url,body); assert.equal(r.status(),expected,await r.text()); return r;
        };
        const first = data.pupils[0]; const last = data.pupils[51];
        let v = await version(termUrl(1));
        assert.equal(await page.locator('.sheet').count(),50,'Preview is paginated');
        await post(termUrl(1)+'/close',{version:v},422);
        let error = await post(termUrl(1)+'/validate',{version:v},422);
        assert.match(await error.text(),/Archive 052/,'Incomplete pupil on page 2 prevents validation of the entire class');
        const emptyUrl = docs+'/bulletins/'+data.terms[1]+'/'+data.empty;
        await post(emptyUrl+'/validate',{version:await version(emptyUrl)},422);
        assert.equal((await page.request.get(docs+'/annual/'+data.cohort,{headers:{Accept:'application/json'}})).status(),422,'Annual requires all three closed terms');
        const mark = async(assessment,enrollment,score) => {
            fixture('report-card-archive-fixture.php','unpublish',assessment);
            const r = await send(api+'/grades',{assessment_id:assessment,enrollment_id:enrollment,status:'marked',score,reason:'Correction de test'},'put');
            assert.equal(r.status(),200,await r.text());
            assert.equal((await send(api+'/assessments/'+assessment+'/transition',{reason:'Publication de test'})).status(),200);
        };
        await mark(data.exams[1][0],last.enrollment,14);
        await post(termUrl(1)+'/validate',{version:v},422); // stale preview
        v = await version(termUrl(1));
        await post(termUrl(1)+'/validate',{version:v},302);
        await post(termUrl(1)+'/validate',{version:v},302); // safe retry
        await page.goto(termUrl(1));
        assert.equal(await page.locator('[name=version]').first().inputValue(),v,'JSONB preserves the signed snapshot');
        assert.equal(await page.locator('textarea').count(),0,'Validated observations are read-only');
        await post(termUrl(1)+'/observations',{enrollment:first.enrollment,observations:{[data.art]:'Changed'}},422);
        // Any later change to an assessed mark requires another validation before closure.
        await mark(data.exams[1][0],first.enrollment,15);
        await post(termUrl(1)+'/close',{version:v},422);
        await post(termUrl(1)+'/draft',{version:v},302);
        await mark(data.exams[1][0],first.enrollment,14);
        v = await version(termUrl(1));
        await post(termUrl(1)+'/validate',{version:v},302);
        fixture('report-card-archive-fixture.php','read-only');
        await post(termUrl(1)+'/close',{version:v},403);
        fixture('backend-fixture.php','grant');
        // Simultaneous retries produce one immutable archive.
        const duplicates = await Promise.all([send(termUrl(1)+'/close',{version:v}),send(termUrl(1)+'/close',{version:v})]);
        for(const r of duplicates) assert.equal(r.status(),302,await r.text());
        await page.goto(termUrl(1));
        assert.equal(await page.locator('[name=version]').count(),0,'Closed archive offers no mutation controls');
        const frozenTerm = await page.locator('.sheet').first().evaluate(node=>node.outerHTML);
        const frozenStyles = await page.locator('style').textContent();
        const archiveLink = await page.getByRole('link',{name:'Télécharger l’archive HTML'}).getAttribute('href');
        const firstArchiveUrl = archiveLink.replace(/[?&]download=1/,'');
        const download = await page.request.get(archiveLink);
        assert.equal(download.status(),200);
        assert.match(download.headers()['content-disposition'],/attachment; filename="bulletins-/);
        assert.ok((await download.text()).includes(frozenTerm),'Download uses the archived document');
        await page.goto(termUrl(1)+'?page=2');
        assert.equal(await page.locator('.sheet').count(),2,'Archive keeps all 52 students');
        await post(termUrl(1)+'/draft',{version:v},422,'Closed lot cannot reopen');
        for(const number of [2,3]) {
            const signature = await version(termUrl(number));
            await post(termUrl(number)+'/validate',{version:signature},302);
            await post(termUrl(number)+'/close',{version:signature},302);
        }
        await page.goto(docs+'?cohort='+data.cohort);
        await page.getByRole('link',{name:'Bulletins annuels — moyenne des trois trimestres'}).click();
        const annualUrl = page.url();
        const annualVersion = await page.locator('[name=version]').inputValue();
        assert.ok((await page.locator('.result').first().innerText()).includes('16,01 / 20'),'Annual uses unrounded quarterly averages, not displayed values');
        await post(annualUrl+'/validate',{version:annualVersion},302);
        await post(annualUrl+'/close',{version:annualVersion},302);
        await page.goto(annualUrl);
        const frozenAnnual = await page.locator('.sheet').first().evaluate(node=>node.outerHTML);
        const annualLink = await page.getByRole('link',{name:'Télécharger l’archive HTML'}).getAttribute('href');
        const annualArchiveUrl = annualLink.replace(/[?&]download=1/,'');
        const checks = fixture('report-card-archive-fixture.php','verify-immutability');
        assert.equal(checks.blocked,2,'Database rejects edits and deletion of a closed archive');
        assert.equal(checks.closed,4,'Three quarterly archives and one annual archive');
        assert.equal(checks.invalid_dates,0,'Validation precedes closure with consistent time zones');
        assert.ok(checks.events>=8,'Validation and closure are audited');
        // Change live marks, identity, enrollment, school, subject, coefficients, templates and CSS.
        await mark(data.exams[1][0],first.enrollment,0);
        fixture('report-card-archive-fixture.php','mutate-sources');
        await page.goto(firstArchiveUrl+'?enrollment='+first.enrollment);
        assert.equal(await page.locator('.sheet').count(),1,'Canceled enrollment retains its archived report');
        assert.equal(await page.locator('.sheet').evaluate(node=>node.outerHTML),frozenTerm);
        assert.equal(await page.locator('style').textContent(),frozenStyles,'Archived print styles survive deployment/template changes');
        await page.goto(annualArchiveUrl+'?enrollment='+first.enrollment);
        assert.equal(await page.locator('.sheet').evaluate(node=>node.outerHTML),frozenAnnual,'Annual archive remains unchanged');
        assert.equal((await page.request.get(annualArchiveUrl+'?enrollment='+randomUUID(),{headers:{Accept:'application/json'}})).status(),404);
        assert.equal((await page.request.get(annualArchiveUrl.replace(user.tenant,randomUUID()),{headers:{Accept:'application/json'}})).status(),403);
        fixture('report-card-archive-fixture.php','no-students');
        assert.equal((await page.request.get(annualArchiveUrl,{headers:{Accept:'application/json'}})).status(),403,'Archived identities require students.read');
        fixture('backend-fixture.php','grant');
        await page.goto(annualArchiveUrl+'?enrollment='+first.enrollment);
        fs.mkdirSync('tests/artifacts',{recursive:true});
        await page.emulateMedia({media:'print'});
        await page.pdf({path:'tests/artifacts/bulletin-annuel-definitif.pdf',format:'A4',preferCSSPageSize:true});
        await page.emulateMedia({media:'screen'});
        await page.screenshot({path:'tests/artifacts/bulletin-annuel-definitif.png',fullPage:true});
        await page.setViewportSize({width:390,height:844});
        await page.screenshot({path:'tests/artifacts/bulletin-annuel-definitif-mobile.png',fullPage:true});
        console.log('PASS definitive reports: full-class completeness, stale preview/validation, draft/validate/close, retries, permissions, immutable database, frozen identities/results/templates/styles, 52-student archives, downloads, unrounded annual mean, A4 PDF and mobile.');
    } finally {
        if(browser) await browser.close();
        compose(['down','-v','--remove-orphans']);
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
