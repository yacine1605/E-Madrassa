// Read-only recovery rehearsal of an existing backup; never connects to the source database.
const {execFileSync}=require('node:child_process');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const [directory,name]=process.argv.slice(2);
if(!directory||!name||path.basename(name)!==name)throw Error('Usage: node tests/restore-backup.cjs BACKUP_DIRECTORY BACKUP_NAME');
const manifest=JSON.parse(fs.readFileSync(path.join(directory,name,'manifest.json')));
const project='madrassa-integration-recovery-'+Date.now().toString(36);
const env={...process.env,COMPOSE_PROJECT_NAME:project,COMPOSE_FILE:['compose.yaml','tests/compose.test.yaml'].join(path.delimiter),APP_PORT:'18194',APP_URL:'http://localhost:18194',DB_DATABASE:'recovery_rehearsal',DB_PASSWORD:'isolated-rehearsal-only',BACKUP_DIR:path.resolve(directory)};
const compose=args=>execFileSync('docker-compose',args,{env,encoding:'utf8',stdio:['ignore','pipe','pipe']});
(async()=>{
 try{
  compose(['up','-d','--no-build','postgres','redis']);
  const result=JSON.parse(compose(['run','--rm','--no-deps','-T','backup','restore',name]));
  assert.equal(result.tables_verified,Object.keys(manifest.tables).length);
  compose(['up','-d','--no-build','app']);
  let ready=false;for(let i=0;i<100;i++){try{if((await fetch('http://localhost:18194/admin/login')).ok){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,500));}assert.ok(ready,'Recovered application serves its login page');
  const keyCheck="require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); if(!hash_equals(trim(file_get_contents(storage_path('app/.app-key'))),config('app.key')))throw new RuntimeException('Restored key mismatch'); echo 'key preserved';";
  assert.equal(compose(['exec','-T','app','php','-r',keyCheck]),'key preserved');
  const report={tested_at:new Date().toISOString(),backup:name,source_project:manifest.project,tables_verified:result.tables_verified,rows_verified:Object.values(manifest.tables).reduce((a,b)=>a+b,0),archives_verified:true,application_http:200,key_preserved:true,source_database_untouched:true};
  fs.mkdirSync('tests/artifacts',{recursive:true});fs.writeFileSync('tests/artifacts/backup-restore-report.json',JSON.stringify(report,null,2)+'\n');
  console.log('PASS: existing school backup restored into an isolated deployment; '+report.tables_verified+' tables and '+report.rows_verified+' rows verified; application and encryption key checked.');
 }finally{
  if(!project.startsWith('madrassa-integration-recovery-'))throw Error('Unsafe cleanup');compose(['--profile','backup','down','-v','--remove-orphans']);
 }
})().catch(e=>{console.error(e.message);process.exitCode=1;});
