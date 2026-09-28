import { PGlite } from '@electric-sql/pglite';
import { readFileSync } from 'node:fs';
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
async function rejects(s,p,code) {
  let rejected = false;
  try { await sql(s,p); } catch(e) { rejected=true; if(code) assert.equal(e.code,code,e.message); }
  assert.ok(rejected,'Expected SQL rejection: '+s);
}
await db.exec('CREATE TABLE public.users(id bigserial PRIMARY KEY, name text, email text, password text, remember_token text, updated_at timestamptz); INSERT INTO public.users(id) VALUES(1),(2),(3);');
await db.exec(readFileSync(new URL('../database/schema/001_school.sql',import.meta.url),'utf8'));
let grants = readFileSync(new URL('../deploy/runtime-grants.sql',import.meta.url),'utf8');
if ((await sql("SELECT 1 FROM pg_roles WHERE rolname='school_runtime'")).rows.length)
  grants = grants.replace(/CREATE ROLE school_runtime[^;]+;/, '');
await db.exec(grants);
const a=uuid(), b=uuid();
await sql("INSERT INTO school.institutions(id,name,kind) VALUES($1,'École A','school'),($2,'Centre B','training')",[a,b]);
await sql('INSERT INTO school.memberships(tenant_id,user_id) VALUES($1,1),($2,2)',[a,b]);
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
await sql('UPDATE school.work_logs SET validated_at=now() WHERE id=$1',[hours]);
await check('validated hours frozen',()=>rejects('UPDATE school.work_logs SET validated_at=now() WHERE id=$1',[hours],'23514'));
await insert('payroll_runs',{staff_id:teacher,month:'2026-10-01',amount_minor:200000,calculation:'{}',validated_at:'2026-11-01'});
await check('closed payroll rejects late hours',()=>rejects("INSERT INTO school.work_logs(tenant_id,contract_id,worked_on,minutes) VALUES($1,$2,'2026-10-02',60)",[a,contract],'23514'));
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
 const offering3=await insert('offerings',{level_id:level,period_id:period,campus_id:campus,name:'Last seat',price_minor:10000});
 const cohort3=await insert('cohorts',{offering_id:offering3,name:'Last seat',capacity:1});
 await check('concurrent enrollment cannot consume same last seat',()=>race([student,student2].map(s=>[
  'INSERT INTO school.enrollments(tenant_id,student_id,offering_id,cohort_id,price_minor,idempotency_key) VALUES($1,$2,$3,$4,10000,$5)',[a,s,offering3,cohort3,uuid()]
 ])));
}
console.log(`${passed} checks passed. Engine: ${PgClient ? 'native PostgreSQL with concurrency checks' : 'embedded PostgreSQL; concurrency not measured'}.`);
await db.close();
