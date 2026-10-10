const {execFileSync}=require('node:child_process');
const {chromium}=require('../.test-tools/node_modules/playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const suffix=Date.now().toString(36);
const source='madrassa-integration-backup-source-'+suffix;
const target='madrassa-integration-backup-target-'+suffix;
const directory=path.resolve('backups','test-'+suffix);
fs.mkdirSync(directory,{recursive:true});
const environment=(project,port)=>({...process.env,COMPOSE_PROJECT_NAME:project,COMPOSE_FILE:['compose.yaml','tests/compose.test.yaml'].join(path.delimiter),APP_PORT:String(port),APP_URL:'http://localhost:'+port,DB_DATABASE:'backup_test',DB_PASSWORD:'isolated-test-only',BACKUP_DIR:directory,BACKUP_KEEP:'2',BACKUP_PASSPHRASE:'isolated-test-passphrase'});
const run=(project,port,args)=>execFileSync('docker-compose',args,{env:environment(project,port),encoding:'utf8',stdio:['ignore','pipe','pipe']});
const php=(project,port,file,action)=>JSON.parse(run(project,port,['run','--rm','--no-deps','-T','--entrypoint','php','-v','./tests:/integration:ro','app','/integration/'+file,action]));
const backup=(args)=>run(source,18192,['run','--rm','--no-deps','-T','-e','BACKUP_DRAIN_SECONDS=1','backup',...args]);
const restore=(args)=>run(target,18193,['run','--rm','--no-deps','-T','backup',...args]);
async function ready(port){for(let i=0;i<100;i++){try{if((await fetch('http://localhost:'+port+'/admin/login')).ok)return;}catch{}await new Promise(r=>setTimeout(r,500));}throw Error('App did not become ready');}
function refused(fn,reason){assert.throws(fn,e=>String(e.stderr).includes(reason),reason);}
(async()=>{
 let browser;
 try{
  run(source,18192,['up','-d','--no-build','app']);await ready(18192);
  const user=php(source,18192,'backend-fixture.php','setup');php(source,18192,'backup-fixture.php','create');
  run(source,18192,['up','-d','--no-deps','--force-recreate','app']);await ready(18192);
  php(source,18192,'backup-fixture.php','verify');
  const first=JSON.parse(backup(['backup'])).backup;
  backup(['health']);backup(['verify',first]);
  for(const plain of ['database.dump','files.tar.gz'])assert.ok(!fs.existsSync(path.join(directory,first,plain)),'Backups are stored encrypted: '+plain);
  const original=fs.readFileSync(path.join(directory,first,'files.tar.gz.gpg'));
  fs.appendFileSync(path.join(directory,first,'files.tar.gz.gpg'),'corrupt');
  run(target,18193,['up','-d','--no-build','postgres','redis']);
  refused(()=>restore(['restore',first]),'checksum failed');
  fs.writeFileSync(path.join(directory,first,'files.tar.gz.gpg'),original);
  refused(()=>run(target,18193,['run','--rm','--no-deps','-T','-e','BACKUP_PASSPHRASE=a-wrong-passphrase','backup','restore',first]),'decryption failed');
  const result=JSON.parse(restore(['restore',first]));assert.ok(result.tables_verified>30);
  refused(()=>restore(['restore',first]),'database is not empty');
  run(target,18193,['up','-d','--no-build','app']);await ready(18193);
  php(target,18193,'backup-fixture.php','verify');
  browser=await chromium.launch({channel:'msedge',headless:true});const page=await browser.newPage();
  await page.goto('http://localhost:18193/admin/login');await page.locator('input[type=email]').fill(user.email);await page.locator('input[type=password]').fill(user.password);await page.locator('button[type=submit]').click();await page.waitForURL('**/admin/dashboard');
  backup(['backup']);backup(['backup']);
  assert.equal(fs.readdirSync(directory).filter(n=>n.startsWith('backup-')).length,2,'Retention removes only completed older backups');
  run(source,18192,['up','-d','--no-build','backup']);
  const status=JSON.parse(fs.readFileSync(path.join(directory,'status.json')));status.last_success=0;fs.writeFileSync(path.join(directory,'status.json'),JSON.stringify(status));
  let caughtUp=false;for(let i=0;i<90;i++){await new Promise(r=>setTimeout(r,500));if(JSON.parse(fs.readFileSync(path.join(directory,'status.json'))).last_success>0){caughtUp=true;break;}}
  assert.ok(caughtUp,'Automatic scheduler catches up a missed backup');backup(['health']);
  console.log('PASS: encrypted archives, wrong passphrase refused, persistent key, database/files restore, original account login, corruption refusal, occupied destination refusal, retention, automatic catch-up and health.');
 }catch(error){
  for(const [project,port] of [[source,18192],[target,18193]]){try{console.error(run(project,port,['logs','--tail','35','app']));}catch{}}
  throw error;
 }finally{
  if(browser)await browser.close();
  for(const [project,port] of [[source,18192],[target,18193]]){if(!project.startsWith('madrassa-integration-backup-'))throw Error('Unsafe cleanup');run(project,port,['--profile','backup','down','-v','--remove-orphans']);}
 }
})().catch(e=>{console.error(e.message);process.exitCode=1;});
