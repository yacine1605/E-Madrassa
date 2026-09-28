const { execFileSync } = require('node:child_process');
const { chromium } = require('../.test-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const suffix = Date.now().toString(36);
const envFor = (name, port) => ({...process.env,
    COMPOSE_PROJECT_NAME:`madrassa-integration-${name}-${suffix}`,
    COMPOSE_FILE:['compose.yaml','tests/compose.test.yaml'].join(path.delimiter),
    APP_PORT:String(port), APP_URL:`http://localhost:${port}`, DB_DATABASE:`school_${name}`,
    DB_PASSWORD:`integration-${name}-${suffix}`,
});
const a = envFor('a',18181), b = envFor('b',18182);
const compose = (env,args) => execFileSync('docker-compose',args,{env,encoding:'utf8',stdio:['ignore','pipe','inherit']});
const fixture = (env,action) => JSON.parse(compose(env,['run','--rm','--no-deps','-T','--entrypoint','php','-v','./tests:/integration:ro','app','/integration/backend-fixture.php',action]));
async function ready(env) {
    for(let i=0;i<60;i++) {
        try { if ((await fetch(env.APP_URL+'/school-api/csrf')).ok) return; } catch {}
        await new Promise(resolve=>setTimeout(resolve,500));
    }
    throw new Error('Application did not start: '+env.APP_URL);
}
async function login(context, env, account) {
    const csrf = await context.request.get(env.APP_URL+'/school-api/csrf');
    return context.request.post(env.APP_URL+'/school-api/login',{
        headers:{Accept:'application/json','X-CSRF-TOKEN':(await csrf.json()).csrf_token},
        data:{email:account.email,password:account.password},
    });
}
(async()=>{
    let browser;
    try {
        for(const env of [a,b]) { compose(env,['up','-d','--no-build']); await ready(env); }
        execFileSync(process.execPath,['tests/backend-browser.cjs'],{env:a,stdio:'inherit'});
        const accountA = fixture(a,'setup'), accountB = fixture(b,'setup');
        assert.equal(fixture(a,'verify-binding').verified,true);
        browser = await chromium.launch({channel:'msedge',headless:true});
        const ca = await browser.newContext(), cb = await browser.newContext();
        assert.equal((await login(ca,a,accountA)).status(),200);
        assert.equal((await login(cb,b,accountA)).status(),401,'School A credentials must fail on B');
        assert.equal((await login(cb,b,accountB)).status(),200);
        const profileA = await (await ca.request.get(a.APP_URL+'/school-api/school')).json();
        const profileB = await (await cb.request.get(b.APP_URL+'/school-api/school')).json();
        assert.equal(profileA.data.id,accountA.tenant);
        assert.equal(profileB.data.id,accountB.tenant);
        assert.notEqual(accountA.tenant,accountB.tenant);
        const csrf = await (await ca.request.get(a.APP_URL+'/school-api/csrf')).json();
        const created = await ca.request.post(a.APP_URL+'/school-api/v1/tenants/'+accountA.tenant+'/students',{
            headers:{Accept:'application/json','X-CSRF-TOKEN':csrf.csrf_token},
            data:{matricule:'A-ONLY',first_name:'Only',last_name:'School A'},
        });
        assert.equal(created.status(),201);
        const studentsB = await (await cb.request.get(b.APP_URL+'/school-api/v1/tenants/'+accountB.tenant+'/students')).json();
        assert.equal(studentsB.data.length,0,'B must have its own empty database');
        assert.equal((await cb.request.get(b.APP_URL+'/school-api/v1/tenants/'+accountA.tenant+'/students',{headers:{Accept:'application/json'}})).status(),403);
        const stolenSession = await browser.newContext();
        await stolenSession.addCookies(await ca.cookies());
        assert.equal((await stolenSession.request.get(b.APP_URL+'/school-api/school',{headers:{Accept:'application/json'}})).status(),401);
        // Stale browser selection cannot select a school on this installation.
        const page = await ca.newPage();
        await page.goto(a.APP_URL+'/admin/students');
        await page.evaluate(id=>sessionStorage.setItem('school.tenant',id),accountB.tenant);
        await page.reload();
        await page.getByRole('cell',{name:'A-ONLY',exact:true}).waitFor();
        assert.equal(await page.locator('select[x-model="tenant"]').count(),0);
        console.log('PASS dedicated databases, distinct login credentials, session isolation, rejected foreign school URL, no school selector, stale selection ignored');
    } finally {
        if(browser) await browser.close();
        // Names are generated exclusively for this run; user volumes are never addressed.
        for(const env of [a,b]) compose(env,['down','-v']);
    }
})().catch(e=>{console.error(e);process.exitCode=1;});
