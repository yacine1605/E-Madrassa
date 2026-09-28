// Read-only smoke check against the local deployment; never prints credentials.
const {chromium} = require('../.test-tools/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async()=>{
    const rows = fs.readFileSync('ecole-filament/storage/app/private/teacher-credentials-2026.csv','utf8').trim().split(/\r?\n/);
    const [,,email,password] = rows[1].split(';').map(value=>value.replace(/^"|"$/g,''));
    const browser = await chromium.launch({channel:'msedge',headless:true});
    try {
        const page = await browser.newPage();
        await page.goto('http://localhost:8000/enseignant/login');
        await page.locator('input[name=email]').fill(email);
        await page.locator('input[name=password]').fill(password);
        await page.getByRole('button',{name:'Se connecter',exact:true}).click();
        await page.waitForURL('http://localhost:8000/enseignant');
        await page.getByRole('link',{name:'Notes',exact:true}).click();
        await page.getByRole('heading',{name:'Devoirs et examens',exact:true}).waitFor();
        assert.equal(await page.locator('select[name=kind] option').count(),2);
        assert.ok(await page.locator('select[name=assignment_id] option').count()>0);
        await page.getByRole('link',{name:'Mes classes',exact:true}).click();
        await page.getByRole('link',{name:'Voir les élèves',exact:false}).first().waitFor();
        await page.goto('http://localhost:8000/admin/dashboard');
        await page.waitForURL('http://localhost:8000/enseignant');
        await page.getByRole('link',{name:'Mon planning',exact:true}).click();
        await page.getByRole('heading',{name:'Mon planning',exact:true}).waitFor();
        await page.getByRole('button',{name:'Déconnexion'}).click();
        await page.waitForURL('**/enseignant/login');
        console.log('PASS existing teacher account, assigned classes, devoir/examen form, admin redirect, planning and logout on localhost:8000');
    } finally {await browser.close();}
})().catch(()=>{console.error('Live teacher smoke check failed; credentials were not logged.');process.exitCode=1;});
