const {execFileSync} = require('node:child_process');
const {chromium} = require('../.test-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const {randomUUID} = require('node:crypto');
const run = Date.now().toString(36);
const deployment = (suffix, port, profile, modules = '') => ({
    ...process.env, COMPOSE_PROJECT_NAME:`madrassa-integration-modules-${run}-${suffix}`,
    COMPOSE_FILE:['compose.yaml','tests/compose.test.yaml'].join(path.delimiter),
    APP_PORT:String(port), APP_URL:`http://localhost:${port}`, DB_DATABASE:`modules_${suffix}`,
    DB_PASSWORD:'local-module-test-only', SCHOOL_PROFILE:profile, SCHOOL_MODULES:modules,
});
const a = deployment('a', 18196, 'full');
const b = deployment('b', 18197, 'administration', 'students,lifecycle');
const compose = (env, args) => execFileSync('docker-compose', args, {env, encoding:'utf8', stdio:['ignore','pipe','pipe']});
const fixture = (env, action) => {
    const output = compose(env, ['run','--rm','--no-deps','-T','--entrypoint','php','-v','./tests:/integration:ro','app','/integration/backend-fixture.php',action]);
    try { return JSON.parse(output); } catch { throw new Error('Fixture '+action+' failed: '+output); }
};
const ready = async env => {
    for (let i=0; i<90; i++) {
        try { if ((await fetch(env.APP_URL+'/school-api/csrf')).ok) return; } catch {}
        await new Promise(resolve => setTimeout(resolve, 500));
    }
    throw new Error('School app did not become ready: '+env.COMPOSE_PROJECT_NAME);
};
const login = async (context, env, user) => {
    const token = (await (await context.request.get(env.APP_URL+'/school-api/csrf')).json()).csrf_token;
    const response = await context.request.post(env.APP_URL+'/school-api/login', {
        headers:{Accept:'application/json','X-CSRF-TOKEN':token}, data:{email:user.email,password:user.password},
    });
    assert.equal(response.status(),200,await response.text());
};
const request = (context, url) => context.request.get(url, {headers:{Accept:'application/json'},maxRedirects:0});
const school = async (context, env) => (await (await request(context, env.APP_URL+'/school-api/school')).json()).data;
const createStudent = async (context, env, tenant, code) => {
    const token = (await (await context.request.get(env.APP_URL+'/school-api/csrf')).json()).csrf_token;
    const response = await context.request.post(`${env.APP_URL}/school-api/v1/tenants/${tenant}/students`, {
        headers:{Accept:'application/json','X-CSRF-TOKEN':token},data:{matricule:code,first_name:'Test',last_name:code},
    });
    assert.equal(response.status(),201,await response.text()); return (await response.json()).data;
};

(async () => {
    let browser;
    try {
        for (const env of [a,b]) { compose(env,['up','-d','--no-build']); await ready(env); }
        const userA = fixture(a,'setup'), userB = fixture(b,'setup');
        fixture(a,'grant'); fixture(b,'grant'); // Grants cannot override deployment features.
        browser = await chromium.launch({channel:'msedge',headless:true});
        const ca = await browser.newContext(), cb = await browser.newContext();
        await login(ca,a,userA); await login(cb,b,userB);
        const apiA = `${a.APP_URL}/school-api/v1/tenants/${userA.tenant}`;
        const apiB = `${b.APP_URL}/school-api/v1/tenants/${userB.tenant}`;
        const packageA = await school(ca,a), packageB = await school(cb,b);
        assert.ok(packageA.modules.includes('report_cards') && packageA.permissions.includes('finance.read'));
        assert.deepEqual([...packageB.modules].sort(), ['catalog','core','lifecycle','students']);
        assert.ok(!packageB.permissions.includes('finance.read') && !packageB.permissions.includes('hr.read'));
        const errors = [];
        const page = await cb.newPage(); page.on('pageerror', error=>errors.push(error.message));
        await page.goto(b.APP_URL+'/admin/dashboard');
        assert.equal(await page.locator('nav a[href$="/students"]').count(),1);
        for (const slug of ['payments','finance','teachers','schedule','bulletins','teacher-payments','settings','annual-progression']) {
            assert.equal(await page.locator(`nav a[href$="/${slug}"]`).count(),0,'Menu hides '+slug);
            assert.equal((await request(cb,b.APP_URL+'/admin/'+slug)).status(),403,'Page denies '+slug);
        }
        assert.equal((await request(cb,b.APP_URL+'/scolarite/'+userB.tenant+'/admissions')).status(),200);
        assert.equal((await request(cb,b.APP_URL+'/scolarite/'+userB.tenant+'/passage-annuel')).status(),404);
        for (const endpoint of ['payments','finance/summary','sessions','payroll_runs','announcements']) {
            assert.equal((await request(cb,apiB+'/'+endpoint)).status(),404,'API hides '+endpoint);
        }
        assert.equal((await request(cb,b.APP_URL+'/documents/'+userB.tenant)).status(),404);
        assert.equal((await request(cb,b.APP_URL+'/remunerations/'+userB.tenant)).status(),404);
        assert.equal((await request(cb,b.APP_URL+'/enseignant/login')).status(),404);
        const token = (await (await cb.request.get(b.APP_URL+'/school-api/csrf')).json()).csrf_token;
        const denied = await cb.request.post(apiB+'/payments', {
            headers:{Accept:'application/json','X-CSRF-TOKEN':token},
            data:{invoice_id:randomUUID(),amount_minor:100,method:'cash',idempotency_key:randomUUID()},
        });
        assert.equal(denied.status(),404,'Disabled dedicated write endpoint is blocked');
        await createStudent(ca,a,userA.tenant,'SCHOOL-A-ONLY');
        const saved = await createStudent(cb,b,userB.tenant,'SCHOOL-B-ONLY');
        const roster = (await (await request(cb,apiB+'/students')).json()).data;
        assert.equal(roster.length,1); assert.equal(roster[0].id,saved.id);
        assert.equal((await request(cb,`${b.APP_URL}/school-api/v1/tenants/${userA.tenant}/students`)).status(),403);
        const stolen = await browser.newContext(); await stolen.addCookies(await ca.cookies());
        assert.equal((await request(stolen,b.APP_URL+'/school-api/school')).status(),401,'Separate school sessions');
        const preview = compose(a,['exec','-T','app','php','artisan','school:modules','--profile=language_center']);
        assert.match(preview,/language_center/);
        assert.equal((await request(ca,apiA+'/payments')).status(),200,'Preview does not change running school');
        compose(b,['exec','-T','app','php','artisan','route:cache']);
        compose(b,['exec','-T','app','php','artisan','config:cache']);
        assert.equal((await request(cb,apiB+'/payments')).status(),404,'Caches preserve API feature boundary');
        assert.equal((await request(cb,b.APP_URL+'/documents/'+userB.tenant)).status(),404,'Caches preserve module route boundary');
        // Reconfigure one existing school, keeping its project, database and storage.
        b.SCHOOL_MODULES = 'students,lifecycle,finance';
        compose(b,['up','-d','--no-build','--force-recreate','app']); await ready(b);
        await login(cb,b,userB);
        assert.equal((await request(cb,apiB+'/payments')).status(),200,'Re-enabled feature works');
        assert.equal((await (await request(cb,apiB+'/students')).json()).data[0].id,saved.id,'School data is retained');
        await page.goto(b.APP_URL+'/admin/payments');
        assert.equal(await page.locator('nav a[href$="/payments"]').count(),1,'Re-enabled menu entry');
        // The language-center preset keeps pedagogy but excludes school bulletins/lifecycle.
        a.SCHOOL_PROFILE = 'language_center';
        compose(a,['up','-d','--no-build','--force-recreate','app']); await ready(a);
        await login(ca,a,userA);
        assert.equal((await request(ca,apiA+'/sessions')).status(),200);
        assert.equal((await request(ca,a.APP_URL+'/documents/'+userA.tenant)).status(),404);
        assert.equal((await request(ca,a.APP_URL+'/scolarite/'+userA.tenant+'/admissions')).status(),404);
        assert.equal((await request(ca,a.APP_URL+'/enseignant/login')).status(),200);
        assert.equal((await request(ca,a.APP_URL+'/admin/bulletins')).status(),403);
        assert.equal((await (await request(ca,apiA+'/students')).json()).data[0].matricule,'SCHOOL-A-ONLY');
        assert.deepEqual(errors,[],'School browser has no JavaScript errors');
        console.log('PASS full defaults, custom school package, profile preview, menu/page/API boundaries, write denial, route/config caches, profile changes, retained data and school/session isolation');
    } finally {
        if (browser) await browser.close();
        // These projects were generated for this test run only.
        for (const env of [a,b]) compose(env,['down','-v']);
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
