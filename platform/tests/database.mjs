import { PGlite } from '@electric-sql/pglite';
import { readFileSync, readdirSync } from 'node:fs';
import { randomUUID as uuid } from 'node:crypto';
import assert from 'node:assert/strict';

let db;
let PgClient;
if (process.env.DATABASE_URL) {
  const url = new URL(process.env.DATABASE_URL);
  assert.ok(url.pathname.endsWith('_test'), 'Use a new disposable database ending in _test');
  ({ Client: PgClient } = await import('pg'));
  const client = new PgClient({ connectionString: process.env.DATABASE_URL });
  await client.connect();
  db = { query: (s, p) => client.query(s, p), exec: s => client.query(s), close: () => client.end() };
} else {
  assert.ok(!process.env.REQUIRE_NATIVE, 'DATABASE_URL is required for concurrency tests');
  db = new PGlite();
}
let passed = 0;
const sql = (s, p = []) => db.query(s, p);
const first = async (s, p = []) => (await sql(s, p)).rows[0];
const count = async (s, p = []) => Number((await first(s, p)).n);
async function check(name, f) { await f(); console.log('PASS ' + name); passed++; }
async function rejects(s, p, code, rule) {
  let rejected = false;
  try { await sql(s, p); } catch (e) { rejected = true; if (code) assert.equal(e.code, code, e.message); if (rule) assert.equal(e.message, rule); }
  assert.ok(rejected, 'Expected SQL rejection: ' + s);
}

// Laravel's users table, created by the application's own migration in production.
await db.exec('CREATE TABLE public.users(id bigserial PRIMARY KEY, name text, email text, password text, remember_token text, updated_at timestamptz); INSERT INTO public.users(id) VALUES (1),(2),(3),(4),(5),(6);');
const schemaDir = new URL('../database/schema/', import.meta.url);
for (const file of readdirSync(schemaDir).filter(f => /^\d{3}_.+\.sql$/.test(f)).sort())
  await db.exec(readFileSync(new URL(file, schemaDir), 'utf8'));
await db.exec(readFileSync(new URL('../deploy/roles.sql', import.meta.url), 'utf8'));

// Users 1 and 4 belong to school A, 2 to school B, 5 is suspended in A, 3 belongs nowhere;
// 6 is a publisher operator on the console.
const as = async (role, tenant, user) => {
  await db.exec(role ? `SET ROLE ${role}` : 'RESET ROLE');
  await sql("SELECT set_config('app.tenant_id', $1, false), set_config('app.user_id', $2, false)", [tenant ?? '', user == null ? '' : String(user)]);
};
const today = new Date().toISOString().slice(0, 10);
const yesterday = new Date(Date.now() - 864e5).toISOString().slice(0, 10);
const CREATE = 'SELECT platform.create_tenant($1, $2, $3, $4, $5, $6::date, $7::date, $8, $9, $10, $11, $12) AS id';
const create = async (key, slug, name, plan, admin) =>
  (await first(CREATE, [key, slug, name, 'fr', plan, today, null, 500, 1024, 'MAIN', 'Site principal', admin])).id;
const modules = async t => (await sql('SELECT module_key FROM platform.tenant_module WHERE tenant_id = $1 ORDER BY 1', [t])).rows.map(r => r.module_key);
const sites = () => count('SELECT count(*) n FROM school.site');

// Schools and entitlements (FR-PLT-01, FR-PLT-02)
await as('platform_admin', null, 6);
const keyA = uuid();
const a = await create(keyA, 'ecole-a', 'École A', 'essential', 1);
const b = await create(uuid(), 'centre-b', 'Centre B', 'custom', 2);
await check('a new school has exactly one default site and one invited administrator', async () => {
  await as(null);
  assert.equal(await count('SELECT count(*) n FROM school.site WHERE tenant_id = $1', [a]), 1);
  assert.equal(await count('SELECT count(*) n FROM school.site WHERE tenant_id = $1 AND is_default', [a]), 1);
  assert.deepEqual((await sql('SELECT user_id::int AS u, status FROM school.membership WHERE tenant_id = $1', [a])).rows, [{ u: 1, status: 'invited' }]);
});
await as('platform_admin', null, 6);
await check('a repeated creation returns the same school (FR-PLT-01 AC3)', async () => {
  assert.equal(await create(keyA, 'ecole-a', 'École A', 'essential', 1), a);
  assert.equal(await count("SELECT count(*) n FROM platform.tenant WHERE slug = 'ecole-a'"), 1);
});
await check('a creation key reused with other content is refused', () =>
  rejects(CREATE, [keyA, 'ecole-x', 'École X', 'fr', 'essential', today, null, 1, 1, 'MAIN', 'Site', null], '23514', 'creation_key_reused'));
await check('a slug is 3 to 40 lowercase letters, digits or hyphens', () =>
  rejects(CREATE, [uuid(), 'École A!', 'X', 'fr', 'essential', today, null, 1, 1, 'MAIN', 'Site', null], '23514'));
await check('a slug is unique', () =>
  rejects(CREATE, [uuid(), 'ecole-a', 'Autre', 'fr', 'essential', today, null, 1, 1, 'MAIN', 'Site', null], '23505'));
await check('the plan enables its modules and their dependencies', async () => {
  assert.deepEqual(await modules(a), ['core', 'enrollment_groups', 'sites_rooms', 'student_finance', 'students_crm']);
  assert.deepEqual(await modules(b), ['core']);
});
await check('enabling payroll enables staff and expenses (FR-PLT-02 AC1)', async () => {
  await sql("SELECT platform.enable_module($1, 'payroll')", [a]);
  for (const m of ['payroll', 'staff', 'expenses']) assert.ok((await modules(a)).includes(m), m);
});
await check('staff cannot be disabled while payroll is enabled (FR-PLT-02 AC1)', () =>
  rejects("SELECT platform.disable_module($1, 'staff')", [a], '23514', 'module_required'));
await check('core can never be disabled', () => rejects("SELECT platform.disable_module($1, 'core')", [b], '23514', 'module_required'));
await check('payroll, then staff, can be disabled', async () => {
  await sql("SELECT platform.disable_module($1, 'payroll')", [a]);
  await sql("SELECT platform.disable_module($1, 'staff')", [a]);
  const now = await modules(a);
  assert.ok(!now.includes('payroll') && !now.includes('staff') && now.includes('expenses'));
});
await check('an unknown module is refused', () => rejects("SELECT platform.enable_module($1, 'nothing')", [a], '23514', 'unknown_module'));
await check('the console cannot write schools or modules directly', async () => {
  await rejects("INSERT INTO platform.tenant(slug, name, creation_key) VALUES ('direct', 'Direct', $1)", [uuid()], '42501');
  await rejects("DELETE FROM platform.tenant_module WHERE tenant_id = $1 AND module_key = 'student_finance'", [a], '42501');
});
await check('the console lists every school', async () => assert.equal(await count('SELECT count(*) n FROM platform.tenant'), 2));
await check('the console cannot read any school data (FR-PLT-04 AC1)', () => rejects('SELECT * FROM school.site', [], '42501'));
await as(null);
await check('a module is never enabled without its dependencies', () =>
  rejects("INSERT INTO platform.tenant_module(tenant_id, module_key) VALUES ($1, 'payroll')", [b], '23514', 'module_dependency_missing'));
await check('a slug never changes', () => rejects("UPDATE platform.tenant SET slug = 'autre' WHERE id = $1", [a], '23514', 'code_immutable'));
await sql("UPDATE school.membership SET status = 'active' WHERE user_id IN (1, 2)");
await sql("INSERT INTO school.membership(tenant_id, user_id, status) VALUES ($1, 4, 'active'), ($1, 5, 'suspended')", [a]);

// Isolation between schools (TST-01, BR-01, NFR-TEN-01)
await as('school_runtime', a, 1);
const siteA = (await first("SELECT id FROM school.site WHERE code = 'MAIN'")).id;
await check('a member reads their own school', async () => {
  assert.equal(await sites(), 1);
  assert.deepEqual((await sql('SELECT id FROM platform.tenant')).rows.map(r => r.id), [a]);
});
await as('school_runtime', b, 2);
await check('school B cannot read school A', async () => {
  assert.equal(await count('SELECT count(*) n FROM school.site WHERE tenant_id = $1', [a]), 0);
  assert.equal(await sites(), 1);
  assert.deepEqual((await sql('SELECT id FROM platform.tenant')).rows.map(r => r.id), [b]);
});
await check('school B cannot write into school A', () =>
  rejects("INSERT INTO school.site(tenant_id, code, labels) VALUES ($1, 'HACK', '{\"fr\": \"x\"}')", [a], '42501'));
await check('school B cannot read school A audit', async () => assert.equal(await count('SELECT count(*) n FROM school.audit_log WHERE tenant_id = $1', [a]), 0));
await as('school_runtime', a, 2);
await check('a school context without membership grants nothing', async () => assert.equal(await sites(), 0));
await as('school_runtime', null, 1);
await check('a missing school context fails closed', async () => assert.equal(await sites(), 0));
await as('school_runtime', a, 3);
await check('a non-member sees nothing', async () => assert.equal(await sites(), 0));
await as('school_runtime', a, 5);
await check('a suspended member sees nothing', async () => assert.equal(await sites(), 0));
await as('school_runtime', a, 1);
await check('the request context resets on rollback', async () => {
  await sql('BEGIN');
  await sql("SELECT set_config('app.tenant_id', $1, true)", [b]);
  await sql('ROLLBACK');
  assert.equal((await first('SELECT school.tenant() AS t')).t, a);
});
await check('the application cannot grant memberships', () =>
  rejects("INSERT INTO school.membership(tenant_id, user_id, status) VALUES ($1, 3, 'active')", [a], '42501'));
await check('the application cannot write audit entries', () =>
  rejects("INSERT INTO school.audit_log(tenant_id, action, object_type, object_id) VALUES ($1, 'UPDATE', 'site', $2)", [a, siteA], '42501'));
await check('the application cannot delete a site (BR-02)', () => rejects('DELETE FROM school.site WHERE id = $1', [siteA], '42501'));
await check('the application knows which modules its school has (ARC-04)', async () => {
  assert.equal((await first("SELECT school.module_enabled('student_finance') AS e")).e, true);
  assert.equal((await first("SELECT school.module_enabled('payroll') AS e")).e, false);
});

// Common columns (§7.1), codes (BR-18), sites (ARC-03)
const annex = (await first("INSERT INTO school.site(tenant_id, code, labels) VALUES ($1, 'ANNEXE', '{\"fr\": \"Annexe\"}') RETURNING id", [a])).id;
await check('a new row records its author and starts at version 1', async () =>
  assert.deepEqual(await first('SELECT created_by::int c, updated_by::int u, version FROM school.site WHERE id = $1', [annex]), { c: 1, u: 1, version: 1 }));
await as('school_runtime', a, 4);
await check('an update records its author and increments the version', async () => {
  await sql('UPDATE school.site SET labels = $2 WHERE id = $1', [annex, JSON.stringify({ fr: 'Annexe nord' })]);
  assert.deepEqual(await first('SELECT created_by::int c, updated_by::int u, version FROM school.site WHERE id = $1', [annex]), { c: 1, u: 4, version: 2 });
});
await check('a writer holding an older version is refused', () =>
  rejects('UPDATE school.site SET labels = $2, version = 1 WHERE id = $1', [annex, JSON.stringify({ fr: 'Ancienne' })], '40001', 'stale_version'));
await check('the application cannot change a site code', () => rejects("UPDATE school.site SET code = 'X' WHERE id = $1", [annex], '42501'));
await check('a school keeps at least one site in use (ARC-03)', async () => {
  await sql('UPDATE school.site SET archived_at = now() WHERE id = $1', [annex]);
  await rejects('UPDATE school.site SET archived_at = now() WHERE id = $1', [siteA], '23514', 'last_site');
});

// Audit (BR-09, FR-AUD-01)
await as('school_runtime', a, 1);
await check('an update is audited with old and new values, author, reason and request', async () => {
  await sql("SELECT set_config('app.reason', 'Renommage du site', false), set_config('app.request_id', 'req-test-0001', false)");
  await sql('UPDATE school.site SET labels = $2 WHERE id = $1', [siteA, JSON.stringify({ fr: 'Site central', ar: 'المقر' })]);
  await sql("SELECT set_config('app.reason', '', false), set_config('app.request_id', '', false)");
  const entry = await first("SELECT actor_id::int AS actor, before, after, reason, request_id FROM school.audit_log WHERE object_type = 'site' AND object_id = $1 AND action = 'UPDATE'", [siteA]);
  assert.deepEqual(entry, { actor: 1, before: { labels: { fr: 'Site principal' } }, after: { labels: { fr: 'Site central', ar: 'المقر' } },
    reason: 'Renommage du site', request_id: 'req-test-0001' });
});
await check('the school journal records its own creation (FR-PLT-01)', async () =>
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'tenant' AND action = 'INSERT'"), 1));
await as(null);
await check('audit entries cannot change, even for the database owner', async () => {
  await rejects("UPDATE school.audit_log SET reason = 'x'", [], '23514', 'immutable_record');
  await rejects('DELETE FROM school.audit_log', [], '23514', 'immutable_record');
});
await check('a site code never changes, even for the database owner', () =>
  rejects("UPDATE school.site SET code = 'X' WHERE id = $1", [siteA], '23514', 'code_immutable'));

// School modes (FR-PLT-01, FR-PLT-02 AC3)
await as('platform_admin', null, 6);
await check('a suspension needs a reason', () => rejects("SELECT platform.set_tenant_status($1, 'suspended', '')", [b], '23514', 'reason_required'));
await check('a suspended school is closed to its members (FR-PLT-01 AC2)', async () => {
  await sql("SELECT platform.set_tenant_status($1, 'suspended', 'Abonnement impayé')", [b]);
  await as('school_runtime', b, 2);
  assert.equal(await sites(), 0);
  assert.equal((await first("SELECT school.module_enabled('core') AS e")).e, false);
  await as('platform_admin', null, 6);
  await sql("SELECT platform.set_tenant_status($1, 'active', 'Abonnement régularisé')", [b]);
  await as('school_runtime', b, 2);
  assert.equal(await sites(), 1);
});
await check('suspension and reactivation appear in the school journal with their reasons', async () =>
  assert.deepEqual((await sql("SELECT reason FROM school.audit_log WHERE object_type = 'tenant' AND action = 'UPDATE' ORDER BY occurred_at, reason")).rows.map(r => r.reason).sort(),
    ['Abonnement impayé', 'Abonnement régularisé']));
await as('platform_admin', null, 6);
await sql('SELECT platform.set_entitlement($1, $2::date, $3::date, 15, 500, 1024, 0)', [b, '2025-01-01', '2025-06-30']);
await as('school_runtime', b, 2);
await check('a school expired beyond its grace period reads but cannot write (FR-PLT-02 AC3)', async () => {
  assert.equal(await sites(), 1);
  await rejects("INSERT INTO school.site(tenant_id, code, labels) VALUES ($1, 'LATE', '{\"fr\": \"x\"}')", [b], '25006', 'tenant_read_only');
});
await as('platform_admin', null, 6);
await sql('SELECT platform.set_entitlement($1, $2::date, $3::date, 15, 500, 1024, 0)', [b, '2025-01-01', yesterday]);
await as('school_runtime', b, 2);
await check('within its grace period a school can still write', () =>
  sql("INSERT INTO school.site(tenant_id, code, labels) VALUES ($1, 'GRACE', '{\"fr\": \"Sous délai\"}')", [b]));
await as('platform_admin', null, 6);
await sql("SELECT platform.set_tenant_status($1, 'archived', 'Fin de contrat')", [b]);
await as('school_runtime', b, 2);
await check('an archived school reads but cannot write', async () => {
  assert.equal(await sites(), 2);
  await rejects("UPDATE school.site SET labels = '{\"fr\": \"x\"}' WHERE tenant_id = $1", [b], '25006', 'tenant_read_only');
});
await as('platform_admin', null, 6);
await check('an archived school stays archived', () =>
  rejects("SELECT platform.set_tenant_status($1, 'active', 'Réouverture')", [b], '23514', 'tenant_archived'));

if (PgClient) {
  const connect = async (role, tenant, user) => {
    const c = new PgClient({ connectionString: process.env.DATABASE_URL });
    await c.connect();
    await c.query(`SET ROLE ${role}`);
    await c.query("SELECT set_config('app.tenant_id', $1, false), set_config('app.user_id', $2, false)", [tenant ?? '', String(user)]);
    return c;
  };
  await check('concurrent creations with the same key create one school', async () => {
    const key = uuid();
    const clients = await Promise.all([0, 1].map(() => connect('platform_admin', null, 6)));
    const ids = await Promise.all(clients.map(c =>
      c.query(CREATE, [key, 'race-school', 'Race', 'fr', 'essential', today, null, 10, 10, 'MAIN', 'Site', null]).then(r => r.rows[0].id)));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(ids[0], ids[1]);
    await as(null);
    assert.equal(await count("SELECT count(*) n FROM platform.tenant WHERE slug = 'race-school'"), 1);
  });
  await as('school_runtime', a, 1);
  const spare = (await first("INSERT INTO school.site(tenant_id, code, labels) VALUES ($1, 'SPARE', '{\"fr\": \"Réserve\"}') RETURNING id", [a])).id;
  await check('concurrent archiving cannot leave a school without a site', async () => {
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
    const results = await Promise.allSettled([siteA, spare].map(async (id, i) => {
      await clients[i].query('BEGIN');
      try {
        await clients[i].query('UPDATE school.site SET archived_at = now() WHERE id = $1', [id]);
        await clients[i].query('SELECT pg_sleep(0.15)');
        await clients[i].query('COMMIT');
      } catch (e) { await clients[i].query('ROLLBACK'); throw e; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(results.find(r => r.status === 'rejected').reason.message, 'last_site');
  });
}
console.log(`${passed} checks passed. Engine: ${PgClient ? 'native PostgreSQL with concurrency checks' : 'embedded PostgreSQL; concurrency not measured'}.`);
await db.close();
