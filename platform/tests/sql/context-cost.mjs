// What the request's context costs (SECURITY-REVIEW.md, F7), on native PostgreSQL:
//   DATABASE_URL=postgres://user:password@host/cost_test node tests/sql/context-cost.mjs [platform folder]
// The platform folder, this one by default, gives the schema and the roles, so that an earlier
// version can be measured on the same data: two schools of 25 000 subjects each, journaled.
// Each line is the median of its runs, round trips to the database included.
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createHmac, randomBytes, randomUUID as uuid } from 'node:crypto';
import assert from 'node:assert/strict';
import pg from 'pg';

assert.ok(new URL(process.env.DATABASE_URL ?? 'x:').pathname.endsWith('_test'), 'Use a new disposable database ending in _test');
const root = process.argv[2] ? pathToFileURL(resolve(process.argv[2]) + '/') : new URL('../../', import.meta.url);
const client = new pg.Client({ connectionString: process.env.DATABASE_URL });
await client.connect();
const sql = (s, p = []) => client.query(s, p);

// Laravel's users table, created by the application's own migration in production.
await sql(`CREATE TABLE public.users(id bigserial PRIMARY KEY, name varchar(255) NOT NULL, email varchar(255) NOT NULL UNIQUE, email_verified_at timestamptz,
  password varchar(255) NOT NULL, remember_token text, created_at timestamptz, updated_at timestamptz);
  INSERT INTO public.users(id, name, email, password) SELECT g, 'Person ' || g, 'user' || g || '@example.test', 'hash' FROM generate_series(1, 2) g;
  SELECT setval('public.users_id_seq', 2);`);
const schema = new URL('database/schema/', root);
for (const file of readdirSync(schema).filter(f => /^\d{3}_.+\.sql$/.test(f)).sort()) await sql(readFileSync(new URL(file, schema), 'utf8'));
await sql(readFileSync(new URL('deploy/roles.sql', root), 'utf8'));

// As the application enters a school: a signed context, or before it the two settings.
const signed = (await sql("SELECT to_regnamespace('secret') IS NOT NULL AS s")).rows[0].s;
const key = randomBytes(32);
if (signed) await sql("CALL secret.set_context_key(decode($1, 'hex'))", [key.toString('hex')]);
const pid = (await sql('SELECT pg_backend_pid() AS pid')).rows[0].pid;
const enter = (tenant, user) => {
  if (!signed) return sql("SELECT set_config('app.tenant_id', $1, true), set_config('app.user_id', $2, true)", [tenant, String(user)]);
  const payload = JSON.stringify({ tenant, user, support: null, request: 'cost', ip: null, pid, at: Date.now() * 1000 });
  return sql("SELECT set_config('app.context', $1, true)", [payload + '.' + createHmac('sha256', key).update(payload).digest('hex')]);
};

const today = new Date().toISOString().slice(0, 10);
const school = async (slug, admin) => (await sql('SELECT platform.create_tenant($1, $2, $3, $4, $5, $6::date, $7::date, $8, $9, $10, $11, $12, $13) AS id',
  [uuid(), slug, slug, 'fr', 'essential', today, null, 500, 1024, 'MAIN', 'Site', admin, 'private_school_dz'])).rows[0].id;
const a = await school('cost-a', 1);
const b = await school('cost-b', 2);
await sql("UPDATE school.membership SET status = 'active'");
for (const tenant of [a, b]) {
  await sql(`INSERT INTO school.subject (tenant_id, code, kind, labels)
    SELECT $1, 'S' || g, 'subject', jsonb_build_object('fr', 'Matière ' || g) FROM generate_series(1, 25000) g`, [tenant]);
}
await sql('VACUUM ANALYZE');
await sql('SET ROLE school_runtime');

// A measure slower than a second stops after three runs.
const time = async (label, runs, work) => {
  const times = [];
  for (let i = 0; i < runs && !(i >= 3 && times[0] > 1000); i++) {
    const start = process.hrtime.bigint();
    await work();
    times.push(Number(process.hrtime.bigint() - start) / 1e6);
  }
  times.sort((x, y) => x - y);
  console.log(`| ${label} | ${times[Math.floor(times.length / 2)].toFixed(2)} ms |`);
};
// One request of school A's administrator: its transaction, its context, its work.
const request = (work, end = 'COMMIT') => async () => {
  await sql('BEGIN');
  try { await enter(a, 1); await work(); } finally { await sql(end); }
};

console.log(`${signed ? 'Signed context' : 'Unsigned settings'}, PostgreSQL ${(await sql('SHOW server_version')).rows[0].server_version}\n`);
console.log('| Measure | Median |\n|---|---|');
await time('A request that only enters its school', 200, request(async () => {}));
await time('Its start, as SchoolContext checks the school and the person', 200, request(async () => {
  await sql('SELECT platform.tenant_mode($1::uuid) AS mode, school.allowed($1::uuid) AS allowed, platform.locale_for($2, $1::uuid) AS locale', [a, 1]);
  await sql("SELECT school.mfa_required() AS mfa, (school.setting_value('session.idle_minutes'))::int AS idle");
}));
// Compared with each row, the call is made for each row: what a query must avoid.
await time('school.actor() called for each of 10 000 rows', 20, request(() => sql('SELECT count(*) FROM generate_series(1, 10000) g WHERE school.actor() <> g')));
await time('A page of 25 subjects among 25 000, and their count', 50, request(async () => {
  await sql('SELECT id, code, labels FROM school.subject WHERE archived_at IS NULL ORDER BY code LIMIT 25 OFFSET 12000');
  await sql('SELECT count(*) FROM school.subject WHERE archived_at IS NULL');
}));
await time('The journal\'s first page, and its count', 50, request(async () => {
  await sql('SELECT id, action, object_type, occurred_at FROM school.audit_log ORDER BY occurred_at DESC, id LIMIT 25');
  await sql('SELECT count(*) FROM school.audit_log');
}));
await time('500 subjects written, stamped and journaled', 20, request(() => sql(`INSERT INTO school.subject (tenant_id, code, kind, labels)
  SELECT $1, 'N' || g, 'subject', jsonb_build_object('fr', 'Nouvelle ' || g) FROM generate_series(1, 500) g`, [a]), 'ROLLBACK'));
await client.end();
