import { PGlite } from '@electric-sql/pglite';
import { readFileSync, readdirSync } from 'node:fs';
import { randomUUID as uuid } from 'node:crypto';
import assert from 'node:assert/strict';
let db;
let PgClient;
if (process.env.DATABASE_URL) {
  const url = new URL(process.env.DATABASE_URL);
  assert.ok(url.pathname.endsWith('_test'), 'Use a new disposable database ending in _test');
  ({Client: PgClient} = await import('pg'));
  const client = new PgClient({connectionString:process.env.DATABASE_URL});
  await client.connect();
  db = {query:(s,p)=>client.query(s,p), exec:s=>client.query(s), close:()=>client.end()};
} else {
  assert.ok(!process.env.REQUIRE_NATIVE, 'DATABASE_URL is required for concurrency tests');
  db = new PGlite();
}
let passed = 0;
const sql = (s, p=[]) => db.query(s,p);
const first = async (s,p=[]) => (await sql(s,p)).rows[0];
async function check(name, f) { await f(); console.log('PASS '+name); passed++; }
async function rejects(s,p,code,rule) {
  let rejected = false;
  try { await sql(s,p); } catch(e) { rejected=true; if(code) assert.equal(e.code,code,e.message); if(rule) assert.equal(e.message,rule); }
  assert.ok(rejected,'Expected SQL rejection: '+s);
}
await db.exec('CREATE TABLE public.users(id bigserial PRIMARY KEY, name text, email text, password text, remember_token text, updated_at timestamptz); INSERT INTO public.users(id) VALUES(1),(2),(3),(4);');
// Every numbered schema file, in order: the same SQL the Laravel migrations run.
const schemaDir = new URL('../database/schema/',import.meta.url);
for (const file of readdirSync(schemaDir).filter(f => /^\d{3}_.+\.sql$/.test(f)).sort())
  await db.exec(readFileSync(new URL(file,schemaDir),'utf8'));
await db.exec(readFileSync(new URL('../deploy/runtime-grants.sql',import.meta.url),'utf8'));
const a=uuid(), b=uuid();
await sql("INSERT INTO school.institutions(id,name,kind) VALUES($1,'École A','school'),($2,'Centre B','training')",[a,b]);
// User 4 is a second member of school A, for segregation-of-duties checks.
await sql('INSERT INTO school.memberships(tenant_id,user_id) VALUES($1,1),($2,2),($1,4)',[a,b]);
await db.exec('SET ROLE school_runtime');
const context = async (tenant,user=1) => { await sql("select set_config('app.tenant_id',$1,false),set_config('app.user_id',$2,false)",[tenant,String(user)]); };
await context(a);
async function insert(table,data) {
 const id=uuid(); const d={id,tenant_id:a,...data};
 await sql(`INSERT INTO school.${table}(${Object.keys(d).join(',')}) VALUES(${Object.keys(d).map((_,i)=>'$'+(i+1)).join(',')})`,Object.values(d)); return id;
}
const campus=await insert('campuses',{name:'Alger'});
const period=await insert('periods',{name:'2026/2027',starts_on:'2026-09-01',ends_on:'2027-07-01'});
const program=await insert('programs',{code:'PRIMARY',name:'Primaire',kind:'school'});
const level=await insert('levels',{program_id:program,code:'1AP',name:'1AP'});
const offering=await insert('offerings',{level_id:level,period_id:period,campus_id:campus,name:'Classe annuelle',price_minor:10000});
const cohort=await insert('cohorts',{offering_id:offering,name:'A',capacity:1});
const student=await insert('students',{matricule:'S1',first_name:'Amine',last_name:'Test'});
const student2=await insert('students',{matricule:'S2',first_name:'Lina',last_name:'Test'});
const enrollment=await insert('enrollments',{student_id:student,offering_id:offering,cohort_id:cohort,price_minor:10000,idempotency_key:uuid()});
const invoice=await insert('invoices',{enrollment_id:enrollment,number:'INV1',amount_minor:10000,due_on:'2026-10-01'});
await check('capacity enforced',()=>rejects('INSERT INTO school.enrollments(tenant_id,student_id,offering_id,cohort_id,price_minor,idempotency_key) VALUES($1,$2,$3,$4,10000,$5)',[a,student2,offering,cohort,uuid()],'23514'));
await check('price snapshot frozen',()=>rejects('UPDATE school.enrollments SET price_minor=1 WHERE id=$1',[enrollment],'42501'));
const payment=await insert('payments',{invoice_id:invoice,amount_minor:6000,method:'cash',idempotency_key:uuid()});
await check('overpayment rejected',()=>rejects("INSERT INTO school.payments(tenant_id,invoice_id,amount_minor,method,idempotency_key) VALUES($1,$2,5000,'cash',$3)",[a,invoice,uuid()],'23514'));
await check('payments immutable',()=>rejects('UPDATE school.payments SET id=id WHERE id=$1',[payment],'23514'));
await insert('refunds',{payment_id:payment,amount_minor:2000,reason:'Erreur de caisse',idempotency_key:uuid()});
await check('over-refund rejected',()=>rejects("INSERT INTO school.refunds(tenant_id,payment_id,amount_minor,reason,idempotency_key) VALUES($1,$2,5000,'Erreur',$3)",[a,payment,uuid()],'23514'));
await check('net balance includes refunds',async()=>assert.equal(Number((await first('SELECT balance_minor FROM school.invoice_balances WHERE id=$1',[invoice])).balance_minor),6000));
await sql("UPDATE school.enrollments SET status='suspended' WHERE id=$1",[enrollment]);
await insert('payments',{invoice_id:invoice,amount_minor:1000,method:'ccp',idempotency_key:uuid()});
await check('payment never reactivates suspended enrollment',async()=>assert.equal((await first('SELECT status FROM school.enrollments WHERE id=$1',[enrollment])).status,'suspended'));
const subject=await insert('subjects',{code:'MATH',name:'Maths'});
const teacher=await insert('staff',{code:'T1',name:'Prof',is_teacher:true});
const room=await insert('rooms',{campus_id:campus,name:'Salle 1',capacity:10});
const session=await insert('sessions',{cohort_id:cohort,subject_id:subject,teacher_id:teacher,room_id:room,starts_at:'2026-10-01T08:00:00+01:00',ends_at:'2026-10-01T09:00:00+01:00'});
await check('schedule overlap rejected',()=>rejects("INSERT INTO school.sessions(tenant_id,cohort_id,subject_id,teacher_id,room_id,starts_at,ends_at) VALUES($1,$2,$3,$4,$5,'2026-10-01T08:30:00+01:00','2026-10-01T09:30:00+01:00')",[a,cohort,subject,teacher,room],'23514'));
await check('adjacent sessions allowed',()=>insert('sessions',{cohort_id:cohort,subject_id:subject,teacher_id:teacher,room_id:room,starts_at:'2026-10-01T09:00:00+01:00',ends_at:'2026-10-01T10:00:00+01:00'}));
const assessment=await insert('assessments',{cohort_id:cohort,subject_id:subject,title:'Test',held_on:'2026-10-01',max_score:20});
await check('score cannot exceed maximum',()=>rejects("INSERT INTO school.grades(tenant_id,assessment_id,enrollment_id,status,score) VALUES($1,$2,$3,'marked',21)",[a,assessment,enrollment],'23514'));
const grade=await insert('grades',{assessment_id:assessment,enrollment_id:enrollment,status:'missing',score:null});
await check('missing is not zero',async()=>assert.equal((await first('SELECT score FROM school.grades WHERE id=$1',[grade])).score,null));
await check('correction needs reason',()=>rejects("UPDATE school.grades SET status='marked',score=12 WHERE id=$1",[grade],'23514'));
await sql("UPDATE school.grades SET status='marked',score=12,reason='Saisie corrigée' WHERE id=$1",[grade]);
await sql('UPDATE school.assessments SET published_at=now() WHERE id=$1',[assessment]);
await check('published grades frozen',()=>rejects("UPDATE school.grades SET score=14,reason='Correction' WHERE id=$1",[grade],'23514'));
const attendance=await insert('attendance',{session_id:session,enrollment_id:enrollment,status:'present'});
await check('attendance uniqueness',()=>rejects("INSERT INTO school.attendance(tenant_id,session_id,enrollment_id,status) VALUES($1,$2,$3,'absent')",[a,session,enrollment],'23505'));
const expense=await insert('expenses',{label:'Loyer',amount_minor:1000,incurred_on:'2026-10-01'});
await insert('disbursements',{expense_id:expense,amount_minor:800,paid_on:'2026-10-01',idempotency_key:uuid()});
await check('expense payment capped',()=>rejects('INSERT INTO school.disbursements(tenant_id,expense_id,amount_minor,paid_on,idempotency_key) VALUES($1,$2,300,CURRENT_DATE,$3)',[a,expense,uuid()],'23514'));
const contract=await insert('contracts',{staff_id:teacher,starts_on:'2026-09-01',basis:'hourly',rate_minor:200000});
await check('contracts cannot overlap',()=>rejects("INSERT INTO school.contracts(tenant_id,staff_id,starts_on,basis,rate_minor) VALUES($1,$2,'2026-10-01','monthly',1000)",[a,teacher],'23514'));
const hours=await insert('work_logs',{contract_id:contract,worked_on:'2026-10-01',minutes:60});
await check('author cannot validate own hours',()=>rejects('UPDATE school.work_logs SET validated_at=now(),reviewed_by=1 WHERE id=$1',[hours],'23514','segregation_of_duties'));
await context(a,4);
await check('reviewer must be the acting user',()=>rejects('UPDATE school.work_logs SET validated_at=now(),reviewed_by=1 WHERE id=$1',[hours],'23514','reviewer_must_be_actor'));
await sql('UPDATE school.work_logs SET validated_at=now(),reviewed_by=4 WHERE id=$1',[hours]);
await check('hours record author and reviewer',async()=>assert.deepEqual(await first('SELECT entered_by::int e,reviewed_by::int r FROM school.work_logs WHERE id=$1',[hours]),{e:1,r:4}));
await context(a);
await check('validated hours frozen',()=>rejects('UPDATE school.work_logs SET validated_at=now() WHERE id=$1',[hours],'23514'));
await check('pay run starts as draft',()=>rejects("INSERT INTO school.payroll_runs(tenant_id,staff_id,month,amount_minor,calculation,status,validated_at) VALUES($1,$2,'2026-10-01',1,'{}','validated',now())",[a,teacher],'23514','payroll_starts_as_draft'));
const run=await insert('payroll_runs',{staff_id:teacher,month:'2026-10-01',amount_minor:200000,calculation:'{}'});
await check('closed payroll rejects late hours',()=>rejects("INSERT INTO school.work_logs(tenant_id,contract_id,worked_on,minutes) VALUES($1,$2,'2026-10-02',60)",[a,contract],'23514','payroll_already_closed'));
await check('draft pay run creates no salary expense',()=>rejects("INSERT INTO school.expenses(tenant_id,label,amount_minor,incurred_on,payroll_id) VALUES($1,'Salaire',200000,'2026-10-31',$2)",[a,run],'23514','payroll_not_validated'));
await check('author cannot validate own pay run',()=>rejects("UPDATE school.payroll_runs SET status='validated',validated_at=now(),validated_by=1 WHERE id=$1",[run],'23514','segregation_of_duties'));
await context(a,4);
await sql("UPDATE school.payroll_runs SET status='validated',validated_at=now(),validated_by=4 WHERE id=$1",[run]);
await context(a);
await check('validated pay run frozen',()=>rejects('DELETE FROM school.payroll_runs WHERE id=$1',[run],'23514','immutable_record'));
await check('salary expense matches validated run',()=>rejects("INSERT INTO school.expenses(tenant_id,label,amount_minor,incurred_on,payroll_id) VALUES($1,'Salaire',1,'2026-10-31',$2)",[a,run],'23514','payroll_not_validated'));
await insert('expenses',{label:'Salaire',amount_minor:200000,incurred_on:'2026-10-31',payroll_id:run});
const draft=await insert('payroll_runs',{staff_id:teacher,month:'2026-11-01',amount_minor:0,calculation:'{}'});
await check('draft pay run can be discarded',()=>sql('DELETE FROM school.payroll_runs WHERE id=$1',[draft]));
await check('audit recorded without full PII snapshots',async()=>assert.ok(Number((await first('SELECT count(*) n FROM school.audit_events')).n)>20));
await context(b,2);
const foreignCampus=await insert('campuses',{tenant_id:b,name:'Oran'});
await check('tenant B cannot read tenant A',async()=>assert.equal(Number((await first('SELECT count(*) n FROM school.students')).n),0));
await check('RLS applies to balance view',async()=>assert.equal(Number((await first('SELECT count(*) n FROM school.invoice_balances')).n),0));
await check('tenant B cannot write tenant A',()=>rejects("INSERT INTO school.students(tenant_id,matricule,first_name,last_name) VALUES($1,'HACK','A','B')",[a],'42501'));
await context(a);
await check('cross-tenant foreign key rejected',()=>rejects("INSERT INTO school.rooms(tenant_id,campus_id,name,capacity) VALUES($1,$2,'Leak',10)",[a,foreignCampus],'23503'));
await check('runtime cannot grant memberships',()=>rejects('INSERT INTO school.memberships(tenant_id,user_id) VALUES($1,3)',[a],'42501'));
await context(b,1);
await check('tenant context alone does not grant membership',async()=>assert.equal(Number((await first('SELECT count(*) n FROM school.campuses')).n),0));
await context('',1);
await check('missing tenant fails closed',async()=>assert.equal(Number((await first('SELECT count(*) n FROM school.campuses')).n),0));
await context(a,3);
await check('non-member fails closed',async()=>assert.equal(Number((await first('SELECT count(*) n FROM school.students')).n),0));
await context(a);
await sql('BEGIN');
await sql("select set_config('app.tenant_id',$1,true)",[b]);
await sql('ROLLBACK');
await check('transaction context resets on rollback',async()=>assert.equal((await first('SELECT school.tenant() t')).t,a));
await check('cancelled enrollment rejects payment',async()=>{
 await sql("UPDATE school.enrollments SET status='cancelled' WHERE id=$1",[enrollment]);
 await rejects("INSERT INTO school.payments(tenant_id,invoice_id,amount_minor,method,idempotency_key) VALUES($1,$2,1,'cash',$3)",[a,invoice,uuid()],'23514');
});
// Capacity is free after cancellation above. A new enrollment keeps its own invoice.
const enrollment2=await insert('enrollments',{student_id:student2,offering_id:offering,cohort_id:cohort,price_minor:10000,idempotency_key:uuid()});
await check('invoice cannot override enrollment price',()=>rejects("INSERT INTO school.invoices(tenant_id,enrollment_id,number,amount_minor,due_on) VALUES($1,$2,'BAD',1,CURRENT_DATE)",[a,enrollment2],'23514'));
const invoice2=await insert('invoices',{enrollment_id:enrollment2,number:'INV2',amount_minor:10000,due_on:'2026-10-01'});
const offering2=await insert('offerings',{level_id:level,period_id:period,campus_id:campus,name:'Cours du soir',price_minor:10000});
const cohort2=await insert('cohorts',{offering_id:offering2,name:'B',capacity:10});
await check('student can enroll in multiple offerings',()=>insert('enrollments',{student_id:student2,offering_id:offering2,cohort_id:cohort2,price_minor:10000,idempotency_key:uuid()}));
const teacher2=await insert('staff',{code:'T2',name:'Prof 2',is_teacher:true});
const room2=await insert('rooms',{campus_id:campus,name:'Salle 2',capacity:10});
await check('cross-program student timetable collision rejected',()=>rejects("INSERT INTO school.sessions(tenant_id,cohort_id,subject_id,teacher_id,room_id,starts_at,ends_at) VALUES($1,$2,$3,$4,$5,'2026-10-01T08:30:00+01:00','2026-10-01T09:00:00+01:00')",[a,cohort2,subject,teacher2,room2],'23514'));
await check('wrong group attendance rejected',()=>rejects("INSERT INTO school.attendance(tenant_id,session_id,enrollment_id,status) SELECT $1,$2,id,'present' FROM school.enrollments WHERE tenant_id=$1 AND cohort_id=$3",[a,session,cohort2],'23514'));
await check('RLS survives inactive membership',async()=>{
 await db.exec('RESET ROLE');
 await sql('UPDATE school.memberships SET active=false WHERE tenant_id=$1 AND user_id=1',[a]);
 await db.exec('SET ROLE school_runtime');
 assert.equal(Number((await first('SELECT count(*) n FROM school.students')).n),0);
 await db.exec('RESET ROLE');
 await sql('UPDATE school.memberships SET active=true WHERE tenant_id=$1 AND user_id=1',[a]);
 await db.exec('SET ROLE school_runtime');
});
await check('records are archived, not deleted',async()=>{
 await sql('UPDATE school.rooms SET archived_at=now() WHERE id=$1',[room2]);
 await rejects('DELETE FROM school.rooms WHERE id=$1',[room2],'42501');
});
await check('runtime cannot change a registration number',()=>rejects("UPDATE school.students SET matricule='S9' WHERE id=$1",[student],'42501'));
await check('codes stay permanent for the owner too',async()=>{
 await db.exec('RESET ROLE');
 try { await rejects("UPDATE school.subjects SET code='OTHER' WHERE id=$1",[subject],'23514','code_immutable'); }
 finally { await db.exec('SET ROLE school_runtime'); }
});
await check('audit keeps old and new values, masking personal data',async()=>{
 await sql("select set_config('app.reason','Correction de capacité',false)");
 await sql('UPDATE school.cohorts SET capacity=20 WHERE id=$1',[cohort2]);
 await sql('UPDATE school.students SET first_name=$2 WHERE id=$1',[student2,'Lina-Maria']);
 await sql("select set_config('app.reason','',false)");
 const capacity=await first("SELECT before,after,reason FROM school.audit_events WHERE entity='cohorts' AND entity_id=$1 AND action='UPDATE'",[cohort2]);
 assert.deepEqual([capacity.before,capacity.after,capacity.reason],[{capacity:10},{capacity:20},'Correction de capacité']);
 const name=await first("SELECT before,after FROM school.audit_events WHERE entity='students' AND entity_id=$1 AND action='UPDATE'",[student2]);
 assert.deepEqual([name.before,name.after],[{first_name:'[masqué]'},{first_name:'[masqué]'}]);
});
const offering4=await insert('offerings',{level_id:level,period_id:period,campus_id:campus,name:'Corrections',price_minor:10000});
const cohort4=await insert('cohorts',{offering_id:offering4,name:'C',capacity:5});
const enrollment3=await insert('enrollments',{student_id:student,offering_id:offering4,cohort_id:cohort4,price_minor:10000,idempotency_key:uuid()});
await check('new enrollment starts pending',async()=>assert.equal((await first('SELECT status FROM school.enrollments WHERE id=$1',[enrollment3])).status,'pending'));
const invoice3=await insert('invoices',{enrollment_id:enrollment3,number:'INV3',amount_minor:10000,due_on:'2026-10-01'});
const wrongPayment=await insert('payments',{invoice_id:invoice3,amount_minor:9000,method:'bank',reference:'VIR-001',idempotency_key:uuid()});
await check('payment reference cannot be reused',()=>rejects("INSERT INTO school.payments(tenant_id,invoice_id,amount_minor,method,reference,idempotency_key) VALUES($1,$2,100,'bank',' vir-001 ',$3)",[a,invoice3,uuid()],'23514','duplicate_payment_reference'));
await insert('payment_reversals',{payment_id:wrongPayment,reason:'Montant saisi par erreur'});
await check('reversal cancels the payment on the balance',async()=>assert.equal(Number((await first('SELECT balance_minor FROM school.invoice_balances WHERE id=$1',[invoice3])).balance_minor),10000));
await check('reversed amount can be paid again',()=>insert('payments',{invoice_id:invoice3,amount_minor:10000,method:'bank',reference:'VIR-002',idempotency_key:uuid()}));
await check('payment cannot be reversed twice',()=>rejects('INSERT INTO school.payment_reversals(tenant_id,payment_id,reason) VALUES($1,$2,$3)',[a,wrongPayment,'Encore une fois'],'23505'));
await check('reversed payment cannot be refunded',()=>rejects("INSERT INTO school.refunds(tenant_id,payment_id,amount_minor,reason,idempotency_key) VALUES($1,$2,100,'Erreur',$3)",[a,wrongPayment,uuid()],'23514','payment_reversed'));
await check('refunded payment cannot be reversed',()=>rejects('INSERT INTO school.payment_reversals(tenant_id,payment_id,reason) VALUES($1,$2,$3)',[a,payment,'Erreur de caisse'],'23514','reversal_after_refund'));
if (PgClient) {
 async function race(commands) {
   const clients=await Promise.all(commands.map(async()=>{
     const c=new PgClient({connectionString:process.env.DATABASE_URL}); await c.connect();
     await c.query('SET ROLE school_runtime');
     await c.query("SELECT set_config('app.tenant_id',$1,false),set_config('app.user_id','1',false)",[a]);
     return c;
   }));
   const results=await Promise.allSettled(commands.map(async ([s,p],i)=>{
     await clients[i].query('BEGIN');
     try { await clients[i].query(s,p); await clients[i].query('SELECT pg_sleep(0.15)'); await clients[i].query('COMMIT'); }
     catch(e) { await clients[i].query('ROLLBACK'); throw e; }
   }));
   await Promise.all(clients.map(c=>c.end()));
   assert.equal(results.filter(r=>r.status==='fulfilled').length,1);
   assert.equal(results.find(r=>r.status==='rejected').reason.code,'23514');
 }
 await check('concurrent payments cannot overpay',()=>race([0,1].map(()=>[
   "INSERT INTO school.payments(tenant_id,invoice_id,amount_minor,method,idempotency_key) VALUES($1,$2,7000,'cash',$3)",[a,invoice2,uuid()]
 ])));
 await check('concurrent payments cannot share a reference',()=>race([0,1].map(()=>[
   "INSERT INTO school.payments(tenant_id,invoice_id,amount_minor,method,reference,idempotency_key) VALUES($1,$2,100,'bank','RACE-REF',$3)",[a,invoice2,uuid()]
 ])));
 const offering3=await insert('offerings',{level_id:level,period_id:period,campus_id:campus,name:'Last seat',price_minor:10000});
 const cohort3=await insert('cohorts',{offering_id:offering3,name:'Last seat',capacity:1});
 await check('concurrent enrollment cannot consume same last seat',()=>race([student,student2].map(s=>[
  'INSERT INTO school.enrollments(tenant_id,student_id,offering_id,cohort_id,price_minor,idempotency_key) VALUES($1,$2,$3,$4,10000,$5)',[a,s,offering3,cohort3,uuid()]
 ])));
}
console.log(`${passed} checks passed. Engine: ${PgClient ? 'native PostgreSQL with concurrency checks' : 'embedded PostgreSQL; concurrency not measured'}.`);
await db.close();
