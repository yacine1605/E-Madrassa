import { PGlite } from '@electric-sql/pglite';
import { btree_gist } from '@electric-sql/pglite/contrib/btree_gist';
import { readFileSync, readdirSync } from 'node:fs';
import { createHmac, randomBytes, randomUUID as uuid } from 'node:crypto';
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
  db = new PGlite({ extensions: { btree_gist } });
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
await db.exec(`CREATE TABLE public.users(id bigserial PRIMARY KEY, name varchar(255) NOT NULL, email varchar(255) NOT NULL UNIQUE, email_verified_at timestamptz,
  password varchar(255) NOT NULL, remember_token text, created_at timestamptz, updated_at timestamptz);
  INSERT INTO public.users(id, name, email, password) SELECT g, 'Person ' || g, 'user' || g || '@example.test', 'hash' FROM generate_series(1, 9) g;
  SELECT setval('public.users_id_seq', 9);`);
const schemaDir = new URL('../../database/schema/', import.meta.url);
for (const file of readdirSync(schemaDir).filter(f => /^\d{3}_.+\.sql$/.test(f)).sort())
  await db.exec(readFileSync(new URL(file, schemaDir), 'utf8'));
await db.exec(readFileSync(new URL('../../deploy/roles.sql', import.meta.url), 'utf8'));

// The request's context, signed as the application signs it (App\Support\DatabaseContext), with
// this suite's own key. The suite keeps one context over many transactions, which the
// application never does: it dates its contexts an hour ahead.
const contextKey = randomBytes(32);
await sql("CALL secret.set_context_key(decode($1, 'hex'))", [contextKey.toString('hex')]);
const backend = (await first('SELECT pg_backend_pid() AS pid')).pid;
const sign = (claims, key = contextKey) => {
  const payload = JSON.stringify(claims);
  return payload + '.' + createHmac('sha256', key).update(payload).digest('hex');
};
const context = (tenant, user, claims = {}) => sign({ tenant: tenant ?? null, user: user ?? null, support: null, request: null, ip: null,
  pid: backend, at: (Date.now() + 3600e3) * 1000, ...claims });

// Users 1 and 4 belong to school A, 2 to school B, 5 is suspended in A, 3 belongs nowhere;
// 6 is a publisher operator on the console.
const as = async (role, tenant, user, claims = {}) => {
  await db.exec(role ? `SET ROLE ${role}` : 'RESET ROLE');
  // A new identity starts outside any support session (FR-PLT-04).
  await sql("SELECT set_config('app.context', $1, false)", [tenant == null && user == null ? '' : context(tenant, user, claims)]);
};
const today = new Date().toISOString().slice(0, 10);
const yesterday = new Date(Date.now() - 864e5).toISOString().slice(0, 10);
const CREATE = 'SELECT platform.create_tenant($1, $2, $3, $4, $5, $6::date, $7::date, $8, $9, $10, $11, $12, $13) AS id';
const create = async (key, slug, name, plan, admin, profile = null) =>
  (await first(CREATE, [key, slug, name, 'fr', plan, today, null, 500, 1024, 'MAIN', 'Site principal', admin, profile])).id;
const modules = async t => (await sql('SELECT module_key FROM platform.tenant_module WHERE tenant_id = $1 ORDER BY 1', [t])).rows.map(r => r.module_key);
const sites = () => count('SELECT count(*) n FROM school.site');

// Schools and entitlements (FR-PLT-01, FR-PLT-02)
await as('platform_admin', null, 6);
const keyA = uuid();
const a = await create(keyA, 'ecole-a', 'École A', 'essential', 1, 'private_school_dz');
const b = await create(uuid(), 'centre-b', 'Centre B', 'custom', 2);
await check('a new school has exactly one default site and one invited administrator', async () => {
  await as(null);
  assert.equal(await count('SELECT count(*) n FROM school.site WHERE tenant_id = $1', [a]), 1);
  assert.equal(await count('SELECT count(*) n FROM school.site WHERE tenant_id = $1 AND is_default', [a]), 1);
  assert.deepEqual((await sql('SELECT user_id::int AS u, status FROM school.membership WHERE tenant_id = $1', [a])).rows, [{ u: 1, status: 'invited' }]);
});
await as('platform_admin', null, 6);
await check('a repeated creation returns the same school (FR-PLT-01 AC3)', async () => {
  assert.equal(await create(keyA, 'ecole-a', 'École A', 'essential', 1, 'private_school_dz'), a);
  assert.equal(await count("SELECT count(*) n FROM platform.tenant WHERE slug = 'ecole-a'"), 1);
});
await check('a creation key reused with other content is refused', () =>
  rejects(CREATE, [keyA, 'ecole-x', 'École X', 'fr', 'essential', today, null, 1, 1, 'MAIN', 'Site', null, null], '23514', 'creation_key_reused'));
await check('a slug is 3 to 40 lowercase letters, digits or hyphens', () =>
  rejects(CREATE, [uuid(), 'École A!', 'X', 'fr', 'essential', today, null, 1, 1, 'MAIN', 'Site', null, null], '23514'));
await check('a slug is unique', () =>
  rejects(CREATE, [uuid(), 'ecole-a', 'Autre', 'fr', 'essential', today, null, 1, 1, 'MAIN', 'Site', null, null], '23505'));
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
  await sql("SELECT set_config('app.context', $1, true)", [context(b, 1)]);
  await sql('ROLLBACK');
  assert.equal((await first('SELECT school.tenant() AS t')).t, a);
});
await check('the application cannot grant memberships', () =>
  rejects("INSERT INTO school.membership(tenant_id, user_id, status) VALUES ($1, 3, 'active')", [a], '42501'));
await check('the application cannot write audit entries', () =>
  rejects("INSERT INTO school.audit_log(tenant_id, action, object_type, object_id) VALUES ($1, 'UPDATE', 'site', $2)", [a, siteA], '42501'));
await check('the application cannot delete a site (BR-02)', () => rejects('DELETE FROM school.site WHERE id = $1', [siteA], '42501'));
await check('a member lists the schools they may open', async () =>
  assert.deepEqual((await sql('SELECT tenant_id, slug, mode FROM school.my_schools()')).rows, [{ tenant_id: a, slug: 'ecole-a', mode: 'active' }]));
await check('the application lists its school modules', async () =>
  assert.deepEqual((await sql('SELECT school.my_modules() AS m')).rows.map(r => r.m), ['core', 'enrollment_groups', 'expenses', 'sites_rooms', 'student_finance', 'students_crm']));
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
  rejects('UPDATE school.site SET labels = $2, version = 1 WHERE id = $1', [annex, JSON.stringify({ fr: 'Ancienne' })], '23514', 'stale_version'));
await check('the application cannot change a site code', () => rejects("UPDATE school.site SET code = 'X' WHERE id = $1", [annex], '42501'));
await check('a school keeps at least one site in use (ARC-03)', async () => {
  await sql('UPDATE school.site SET archived_at = now() WHERE id = $1', [annex]);
  await rejects('UPDATE school.site SET archived_at = now() WHERE id = $1', [siteA], '23514', 'last_site');
});

// Audit (BR-09, FR-AUD-01)
await as('school_runtime', a, 1);
await check('an update is audited with old and new values, author, reason, request and address', async () => {
  await as('school_runtime', a, 1, { request: 'req-test-0001', ip: '192.0.2.10' });
  await sql("SELECT set_config('app.reason', 'Renommage du site', false)");
  await sql('UPDATE school.site SET labels = $2 WHERE id = $1', [siteA, JSON.stringify({ fr: 'Site central', ar: 'المقر' })]);
  await sql("SELECT set_config('app.reason', '', false)");
  await as('school_runtime', a, 1);
  const entry = await first("SELECT actor_id::int AS actor, before, after, reason, request_id, ip FROM school.audit_log WHERE object_type = 'site' AND object_id = $1 AND action = 'UPDATE'", [siteA]);
  assert.deepEqual(entry, { actor: 1, before: { labels: { fr: 'Site principal' } }, after: { labels: { fr: 'Site central', ar: 'المقر' } },
    reason: 'Renommage du site', request_id: 'req-test-0001', ip: '192.0.2.10' });
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

// Roles, permissions, scopes, segregation of duties (FR-IAM-01, FR-IAM-03, FR-IAM-04, FR-IAM-05)
// User 7 is front office and accountant in A, 8 a supervisor on one site, 9 a student.
await as(null);
await sql("INSERT INTO school.membership(tenant_id, user_id, status) VALUES ($1, 7, 'active'), ($1, 8, 'active'), ($1, 9, 'active')", [a]);
const roles = Object.fromEntries((await sql('SELECT code, id FROM school.role WHERE tenant_id = $1', [a])).rows.map(r => [r.code, r.id]));
const siteB = (await first("SELECT id FROM school.site WHERE tenant_id = $1 AND code = 'MAIN'", [b])).id;
const ASSIGN = `INSERT INTO school.role_assignment(tenant_id, user_id, role_id, scope_type, scope_ids, valid_from, valid_until)
  VALUES ($1, $2, $3, $4, $5::uuid[], coalesce($6::date, current_date), $7::date) RETURNING id`;
const assign = async (user, code, scope = 'tenant', ids = null, from = null, until = null) =>
  (await first(ASSIGN, [a, user, roles[code], scope, ids && `{${ids.join(',')}}`, from, until])).id;
const can = async p => (await first('SELECT school.can($1) AS c', [p])).c;
const everywhere = async p => (await first('SELECT school.can_everywhere($1) AS c', [p])).c;
const permissions = async () => (await sql('SELECT DISTINCT permission FROM school.my_permissions()')).rows.map(r => r.permission);
const tomorrow = new Date(Date.now() + 864e5).toISOString().slice(0, 10);
const lastYear = new Date(Date.now() - 365 * 864e5).toISOString().slice(0, 10);
await check('a new school receives its own copy of the school roles and their permissions (FR-IAM-03)', async () => {
  assert.deepEqual(Object.keys(roles).sort(), ['academic_hr', 'accountant', 'direction', 'front_office', 'parent', 'stock_manager',
    'student', 'supervisor', 'teacher', 'tenant_admin']);
  assert.equal(await count('SELECT count(*) n FROM school.role_permission WHERE tenant_id = $1', [a]),
    await count('SELECT count(*) n FROM platform.role_template_permission'));
  assert.equal(await count('SELECT count(*) n FROM school.role WHERE tenant_id = $1', [b]), 10);
});
await check('the invited administrator holds the administrator role for the whole school', async () =>
  assert.deepEqual((await sql('SELECT user_id::int AS u, role_id, scope_type FROM school.role_assignment WHERE tenant_id = $1', [a])).rows,
    [{ u: 1, role_id: roles.tenant_admin, scope_type: 'tenant' }]));
await check('sensitive permissions are flagged as in annex A', async () =>
  assert.deepEqual((await sql(`SELECT code FROM platform.permission WHERE sensitive AND code IN ('role.assignment.manage', 'audit.log.read',
    'finance.payment.validate', 'payroll.amount.read', 'student.record.read', 'site.site.manage', 'payroll.self_view') ORDER BY 1`)).rows.map(r => r.code),
    ['audit.log.read', 'finance.payment.validate', 'payroll.amount.read', 'role.assignment.manage']));
await as('school_runtime', a, 1);
await check('the administrator may manage roles and sites for the whole school', async () => {
  assert.ok(await everywhere('role.assignment.manage'));
  assert.ok(await everywhere('site.site.manage'));
});
await as('school_runtime', a, 4);
await check('a member without a role may do nothing', async () => {
  assert.deepEqual(await permissions(), []);
  assert.equal(await can('site.site.manage'), false);
});
await check('a member sees only their own membership; an administrator sees the school\'s members', async () => {
  assert.deepEqual((await sql('SELECT user_id::int AS u FROM school.membership')).rows, [{ u: 4 }]);
  await as('school_runtime', a, 1);
  assert.deepEqual((await sql('SELECT user_id::int AS u FROM school.membership ORDER BY 1')).rows.map(r => r.u), [1, 4, 5, 7, 8, 9]);
  await as('school_runtime', b, 2);
  assert.equal(await count('SELECT count(*) n FROM school.membership WHERE tenant_id = $1', [a]), 0);
});
await as('school_runtime', a, 1);
await assign(7, 'front_office');
await assign(7, 'accountant');
await as('school_runtime', a, 7);
await check('front office plus accountant receive the union of both roles (FR-IAM-03 AC2)', async () => {
  assert.ok(await can('crm.lead.create'));
  assert.ok(await can('finance.payment.validate'));
  assert.equal(await can('role.assignment.manage'), false);
});
await check('a permission of a module the school does not have is not effective', async () =>
  assert.equal(await can('payroll.amount.read'), false));
await check('only a holder of the permission may change roles or assignments', async () => {
  await rejects("INSERT INTO school.role(tenant_id, code, kind, labels) VALUES ($1, 'custom', 'internal', '{\"fr\": \"x\"}')", [a], '42501', 'permission_denied');
  await rejects(ASSIGN, [a, 8, roles.teacher, 'tenant', null, null, null], '42501', 'permission_denied');
  await rejects('DELETE FROM school.role_permission WHERE role_id = $1', [roles.teacher], '42501', 'permission_denied');
});
await as('school_runtime', a, 1);
await check('changing a role in one school leaves the other schools unchanged (FR-IAM-03 AC1)', async () => {
  await sql("DELETE FROM school.role_permission WHERE role_id = $1 AND permission_code = 'crm.lead.create'", [roles.front_office]);
  await as(null);
  assert.equal(await count(`SELECT count(*) n FROM school.role_permission p JOIN school.role r ON r.tenant_id = p.tenant_id AND r.id = p.role_id
    WHERE r.tenant_id = $1 AND r.code = 'front_office' AND p.permission_code = 'crm.lead.create'`, [b]), 1);
  await as('school_runtime', a, 7);
  assert.equal(await can('crm.lead.create'), false);
  assert.ok(await can('crm.lead.read'));
});
await as('school_runtime', a, 1);
await check('a custom role takes permissions of the modules the school has only (FR-IAM-03)', async () => {
  const cashier = (await first("INSERT INTO school.role(tenant_id, code, kind, labels) VALUES ($1, 'cashier', 'internal', '{\"fr\": \"Caisse\"}') RETURNING id", [a])).id;
  await sql("INSERT INTO school.role_permission(tenant_id, role_id, permission_code) VALUES ($1, $2, 'finance.payment.create')", [a, cashier]);
  await rejects("INSERT INTO school.role_permission(tenant_id, role_id, permission_code) VALUES ($1, $2, 'payroll.amount.read')", [a, cashier], '23514', 'permission_module_disabled');
});
await check('nobody assigns a role to themselves or changes a role they hold (FR-IAM-05)', async () => {
  await rejects(ASSIGN, [a, 1, roles.accountant, 'tenant', null, null, null], '23514', 'self_assignment');
  await rejects("INSERT INTO school.role_permission(tenant_id, role_id, permission_code) VALUES ($1, $2, 'student.record.read')", [a, roles.tenant_admin], '23514', 'self_elevation');
});
await check('a role is never deleted, nor archived while assigned (FR-IAM-03)', async () => {
  await rejects('DELETE FROM school.role WHERE id = $1', [roles.front_office], '42501');
  await rejects('UPDATE school.role SET archived_at = now() WHERE id = $1', [roles.front_office], '23514', 'role_in_use');
});
await check('a role code never changes', () => rejects("UPDATE school.role SET code = 'x' WHERE id = $1", [roles.front_office], '42501'));
await check('student and parent roles only ever cover their own data (FR-IAM-04)', async () => {
  await rejects(ASSIGN, [a, 9, roles.student, 'tenant', null, null, null], '23514', 'scope_personal_required');
  await assign(9, 'student', 'personal');
});
await check('a site scope names sites of this school and limits the role to them (FR-IAM-04)', async () => {
  await rejects(ASSIGN, [a, 8, roles.supervisor, 'site', `{${siteB}}`, null, null], '23514', 'scope_unknown');
  await assign(8, 'supervisor', 'site', [siteA]);
  await as('school_runtime', a, 8);
  assert.ok(await can('student.record.read'));
  assert.equal(await everywhere('student.record.read'), false);
  assert.deepEqual(await first("SELECT scope_type, scope_ids::text AS ids FROM school.my_permissions() WHERE permission = 'student.record.read'"),
    { scope_type: 'site', ids: `{${siteA}}` });
});
await as('school_runtime', a, 1);
const future = await assign(9, 'teacher', 'tenant', null, tomorrow);
await assign(9, 'accountant', 'tenant', null, lastYear, yesterday);
await check('an assignment applies between its dates only (BR-10)', async () => {
  await as('school_runtime', a, 9);
  assert.equal(await can('program.program.read'), false);
  assert.equal(await can('finance.payment.read'), false);
  assert.ok(await can('referential.node.read'));
});
await check('an assignment keeps its person, role and start date', async () => {
  await as('school_runtime', a, 1);
  await rejects('UPDATE school.role_assignment SET role_id = $2 WHERE id = $1', [future, roles.direction], '42501');
  await as(null);
  await rejects('UPDATE school.role_assignment SET role_id = $2 WHERE id = $1', [future, roles.direction], '23514', 'identity_immutable');
});
await check('a suspended member loses every permission at once (FR-IAM-01)', async () => {
  await as(null);
  await sql("UPDATE school.membership SET status = 'suspended' WHERE tenant_id = $1 AND user_id = 7", [a]);
  await as('school_runtime', a, 7);
  assert.equal(await can('finance.payment.validate'), false);
  await as(null);
  await sql("UPDATE school.membership SET status = 'active' WHERE tenant_id = $1 AND user_id = 7", [a]);
});
await check('the last administrator can be neither suspended nor deprived of the role (FR-IAM-01 AC2)', async () => {
  await as(null);
  await rejects("UPDATE school.membership SET status = 'suspended' WHERE tenant_id = $1 AND user_id = 1", [a], '23514', 'last_admin');
  await rejects('UPDATE school.role_assignment SET archived_at = now() WHERE tenant_id = $1 AND user_id = 1', [a], '23514', 'last_admin');
  await rejects("DELETE FROM school.role_permission WHERE role_id = $1 AND permission_code = 'role.assignment.manage'", [roles.tenant_admin], '23514', 'last_admin');
});
await check('an administrator hands over to another, who may then end the first one\'s role', async () => {
  await as('school_runtime', a, 1);
  await assign(8, 'tenant_admin');
  await as('school_runtime', a, 8);
  await sql('UPDATE school.role_assignment SET archived_at = now() WHERE user_id = 1 AND role_id = $1', [roles.tenant_admin]);
  await rejects('UPDATE school.role_assignment SET archived_at = now() WHERE user_id = 8 AND role_id = $1', [roles.tenant_admin], '23514', 'self_assignment');
  await as(null);
  await rejects("UPDATE school.membership SET status = 'suspended' WHERE tenant_id = $1 AND user_id = 8", [a], '23514', 'last_admin');
  await as('school_runtime', a, 8);
  await assign(1, 'tenant_admin');
});
await check('role changes are audited with their author', async () => {
  await as(null);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE tenant_id = $1 AND object_type = 'role_assignment' AND action = 'INSERT' AND actor_id = 8", [a]), 1);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE tenant_id = $1 AND object_type = 'role_permission' AND action = 'DELETE' AND actor_id = 1", [a]), 1);
});
await as('school_runtime', a, 7);
await check('who entered an operation cannot approve it, and the message names the rule (FR-IAM-05 AC1, AC2)', async () => {
  let error;
  try { await sql("SELECT school.check_segregation('teacher_attendance', 7)"); } catch (e) { error = e; }
  assert.equal(error?.message, 'segregation_of_duties');
  assert.match(error.hint, /attendance\.teacher\.entry\.create/);
  assert.equal((await first("SELECT school.check_segregation('teacher_attendance', 8) AS s")).s, false);
});
await check('only payments may be self-validated, when the school allows it, and are then flagged (FR-IAM-05)', async () => {
  assert.equal((await first("SELECT school.check_segregation('payment', 7, true) AS s")).s, true);
  await rejects("SELECT school.check_segregation('payment', 7, false)", [], '23514', 'segregation_of_duties');
  await rejects("SELECT school.check_segregation('expense', 7, true)", [], '23514', 'segregation_of_duties');
});
await as('school_runtime', b, 2);
await check('a school can neither see nor assign the roles of another school', async () => {
  assert.equal(await count('SELECT count(*) n FROM school.role WHERE tenant_id = $1', [a]), 0);
  assert.equal(await count('SELECT count(*) n FROM school.role_assignment'), 1);
  await rejects(ASSIGN, [a, 1, roles.teacher, 'tenant', null, null, null], '42501');
});
await as('platform_admin', null, 6);
await check('the console reads the permission catalog but no school role (FR-PLT-04)', async () => {
  assert.ok(await count('SELECT count(*) n FROM platform.permission') > 90);
  await rejects('SELECT * FROM school.role', [], '42501');
});

// Accounts, links, MFA requirement, settings (FR-IAM-01, FR-IAM-02, FR-REF-06)
const tokenHash = () => randomBytes(32).toString('hex');
const CREATE_ACCOUNT = 'SELECT user_id::int AS id, linked, status FROM school.create_account($1, $2, $3, $4, $5)';
const REDEEM = 'SELECT user_id::int AS u, tenant_id AS t FROM platform.redeem_token($1, $2, $3)';
const membership = async (user, tenant = a) => {
  await as(null);
  return first('SELECT status, username, external_ref FROM school.membership WHERE tenant_id = $1 AND user_id = $2', [tenant, user]);
};
// User 3 joins school B here, so that school A later meets a person of several schools.
await as(null);
await sql("INSERT INTO school.membership(tenant_id, user_id, status) VALUES ($1, 3, 'active')", [b]);
await as('school_runtime', a, 1);
const nadia = await first(CREATE_ACCOUNT, ['Nadia B.', ' Nadia@Example.test ', null, 'EMP-001', null]);
const firstLink = tokenHash();
await sql('SELECT school.issue_activation($1, $2)', [nadia.id, firstLink]);
await check('a new account is invited, without password, under its normalized email (FR-IAM-01)', async () => {
  assert.deepEqual([nadia.linked, nadia.status], [false, 'invited']);
  await as(null);
  assert.deepEqual(await first('SELECT email, password FROM public.users WHERE id = $1', [nadia.id]), { email: 'nadia@example.test', password: null });
  assert.deepEqual(await membership(nadia.id), { status: 'invited', username: null, external_ref: 'EMP-001' });
});
await check('a new link replaces the previous one, which no longer works', async () => {
  const secondLink = tokenHash();
  await as('school_runtime', a, 1);
  const expiry = (await first('SELECT school.issue_activation($1, $2) AS e', [nadia.id, secondLink])).e;
  assert.ok(Math.abs(new Date(expiry) - Date.now() - 72 * 3600e3) < 60e3, 'valid 72 hours');
  await as('school_runtime', null, null);
  await rejects(REDEEM, ['activation', firstLink, 'argon-hash'], '23514', 'token_invalid');
  assert.deepEqual(await first(REDEEM, ['activation', secondLink, 'argon-hash']), { u: nadia.id, t: a });
  assert.equal((await membership(nadia.id)).status, 'active');
  await as('school_runtime', null, null);
  await rejects(REDEEM, ['activation', secondLink, 'argon-hash'], '23514', 'token_invalid');
});
await check('an expired link is refused like an unknown one', async () => {
  await as('school_runtime', a, 1);
  const late = await first(CREATE_ACCOUNT, ['Karim', 'karim@example.test', null, null, null]);
  const link = tokenHash();
  await sql('SELECT school.issue_activation($1, $2)', [late.id, link]);
  await as(null);
  await sql("UPDATE public.account_token SET expires_at = now() - interval '1 second' WHERE token_hash = $1", [link]);
  await as('school_runtime', null, null);
  await rejects(REDEEM, ['activation', link, 'argon-hash'], '23514', 'token_invalid');
  await rejects(REDEEM, ['activation', tokenHash(), 'argon-hash'], '23514', 'token_invalid');
});
await as('school_runtime', a, 1);
const known = await first(CREATE_ACCOUNT, ['Autre nom', 'user3@example.test', null, null, null]);
await check('a person already known by email joins as they are, keeping name and password', async () => {
  assert.deepEqual([known.id, known.linked, known.status], [3, true, 'invited']);
  const link = tokenHash();
  await as('school_runtime', a, 1);
  await sql('SELECT school.issue_activation($1, $2)', [3, link]);
  await as('school_runtime', null, null);
  await rejects(REDEEM, ['activation', link, 'new-hash'], '23514', 'password_already_set');
  await first(REDEEM, ['activation', link, null]);
  await as(null);
  assert.deepEqual(await first('SELECT name, password FROM public.users WHERE id = 3'), { name: 'Person 3', password: 'hash' });
});
await as('school_runtime', a, 1);
await check('an email is used once per school', () => rejects(CREATE_ACCOUNT, ['X', 'USER3@example.test', null, null, null], '23505', 'account_exists'));
await check('nobody changes the identity or password of a person of several schools', async () => {
  await rejects('SELECT school.update_account(3, $1, $2, null, null)', ['Nouveau nom', 'user3@example.test'], '23514', 'account_shared');
  await rejects("SELECT school.reset_password(3, 'hash')", [], '23514', 'account_shared');
});
const pupil = await first(CREATE_ACCOUNT, ['Élève Un', null, 'Eleve.1', 'EL-1', 'provisional-hash']);
await check('an account without email has a username and a provisional password, changed at first sign-in', async () => {
  assert.equal(pupil.status, 'active');
  await as(null);
  assert.deepEqual(await first('SELECT must_change_password AS m FROM public.users WHERE id = $1', [pupil.id]), { m: true });
  assert.equal((await membership(pupil.id)).username, 'eleve.1');
  await as('school_runtime', null, null);
  assert.equal((await first("SELECT platform.find_login('ELEVE.1', 'ecole-a')::int AS u")).u, pupil.id);
  assert.equal((await first("SELECT platform.find_login('eleve.1', 'centre-b')::int AS u")).u, null);
  assert.equal((await first("SELECT platform.find_login('Nadia@example.test', null)::int AS u")).u, nadia.id);
});
await as('school_runtime', a, 1);
await check('usernames and import references are unique in the school', async () => {
  await rejects(CREATE_ACCOUNT, ['Élève Deux', null, 'eleve.1', 'EL-2', 'h'], '23505');
  await rejects(CREATE_ACCOUNT, ['Élève Deux', null, 'eleve.2', 'EL-1', 'h'], '23505');
  await rejects(CREATE_ACCOUNT, ['Sans identifiant', null, null, null, 'h'], '23514', 'login_required');
});
await check('a privileged role needs an email, and keeps it (FR-IAM-01)', async () => {
  await rejects(ASSIGN, [a, pupil.id, roles.accountant, 'tenant', null, null, null], '23514', 'email_required');
  await rejects('SELECT school.update_account(7, $1, null, null, null)', ['Person 7'], '23514', 'email_required');
});
await check('suspension needs a reason, never targets oneself, and closes access at once (FR-IAM-01 AC1)', async () => {
  await rejects("SELECT school.set_account_status(1, 'suspended', 'Test')", [], '23514', 'self_account');
  await rejects("SELECT school.set_account_status($1, 'suspended', '')", [pupil.id], '23514', 'reason_required');
  await sql("SELECT school.set_account_status($1, 'suspended', 'Absences répétées')", [pupil.id]);
  await as('school_runtime', a, pupil.id);
  assert.equal(await sites(), 0);
  await as('school_runtime', a, 1);
  await sql("SELECT school.set_account_status($1, 'active', 'Retour')", [pupil.id]);
  assert.equal((await membership(pupil.id)).status, 'active');
});
await check('a revoked account is final and no longer signs in (FR-IAM-01)', async () => {
  await as('school_runtime', a, 1);
  await sql("SELECT school.set_account_status($1, 'revoked', 'Départ définitif')", [pupil.id]);
  await rejects("SELECT school.set_account_status($1, 'active', 'Erreur')", [pupil.id], '23514', 'membership_revoked');
  await as('school_runtime', null, null);
  assert.equal((await first("SELECT platform.find_login('eleve.1', 'ecole-a')::int AS u")).u, null);
});
await check('an invited account opens through its link, not by an administrator', async () => {
  await as('school_runtime', a, 1);
  const invited = await first(CREATE_ACCOUNT, ['Invité', 'invite@example.test', null, null, null]);
  await rejects("SELECT school.set_account_status($1, 'active', 'Ouverture')", [invited.id], '23514', 'activation_required');
});
await check('account changes are in the school journal, with author and reason', async () => {
  await as(null);
  assert.deepEqual((await sql(`SELECT action, actor_id::int AS actor, reason, after ->> 'status' AS status FROM school.audit_log
    WHERE tenant_id = $1 AND object_type = 'membership' AND object_id = (SELECT id FROM school.membership WHERE tenant_id = $1 AND user_id = $2)
    ORDER BY occurred_at, action`, [a, pupil.id])).rows.map(r => [r.action, r.actor, r.reason, r.status]),
    [['INSERT', 1, null, null], ['UPDATE', 1, 'Absences répétées', 'suspended'], ['UPDATE', 1, 'Retour', 'active'], ['UPDATE', 1, 'Départ définitif', 'revoked']]);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE tenant_id = $1 AND action = 'INVITATION'", [a]), 4);
});
await check('only a holder of the permission manages accounts, and only through these functions', async () => {
  await as('school_runtime', a, 7);
  await rejects(CREATE_ACCOUNT, ['X', 'x@example.test', null, null, null], '42501', 'permission_denied');
  await rejects("SELECT school.set_account_status(9, 'suspended', 'Test')", [], '42501', 'permission_denied');
  await rejects("UPDATE public.users SET email = 'pirate@example.test' WHERE id = 1", [], '42501');
  await rejects("UPDATE school.membership SET status = 'active' WHERE user_id = 7", [], '42501');
  await rejects('SELECT * FROM public.account_token', [], '42501');
});
await check('a school cannot invite or change the accounts of another school', async () => {
  await as('school_runtime', b, 2);
  await rejects('SELECT school.issue_activation($1, $2)', [nadia.id, tokenHash()], '23514', 'not_invited');
  await rejects('SELECT school.update_account($1, $2, $3, null, null)', [nadia.id, 'Nadia', 'nadia@example.test'], '23514', 'account_unknown');
});
await check('a recovery link exists for a known email only, works once and for one hour (FR-IAM-02 AC3)', async () => {
  await as('school_runtime', null, null);
  const link = tokenHash();
  assert.deepEqual((await sql('SELECT user_id::int AS u FROM platform.issue_recovery($1, $2)', ['USER7@example.test', link])).rows, [{ u: 7 }]);
  assert.equal((await sql('SELECT * FROM platform.issue_recovery($1, $2)', ['nobody@example.test', tokenHash()])).rows.length, 0);
  await as(null);
  const expiry = (await first('SELECT expires_at AS e FROM public.account_token WHERE token_hash = $1', [link])).e;
  assert.ok(Math.abs(new Date(expiry) - Date.now() - 3600e3) < 60e3, 'valid one hour');
  await as('school_runtime', null, null);
  await rejects(REDEEM, ['recovery', link, null], '23514', 'password_required');
  assert.deepEqual(await first(REDEEM, ['recovery', link, 'recovered-hash']), { u: 7, t: null });
  await rejects(REDEEM, ['recovery', link, 'again-hash'], '23514', 'token_invalid');
  await as(null);
  assert.equal((await first('SELECT password FROM public.users WHERE id = 7')).password, 'recovered-hash');
});
await check('MFA is compulsory for whoever holds a sensitive permission here (FR-IAM-02)', async () => {
  await as('school_runtime', a, 1);
  assert.equal((await first('SELECT school.mfa_required() AS r')).r, true);
  await as('school_runtime', a, 9);
  assert.equal((await first('SELECT school.mfa_required() AS r')).r, false);
});
await check('a school setting defaults, stays within its bounds and needs its permission (FR-REF-06)', async () => {
  await as('school_runtime', a, 1);
  assert.equal((await first("SELECT school.setting_value('session.idle_minutes') AS v")).v, 30);
  await sql("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'session.idle_minutes', '45')", [a]);
  assert.equal((await first("SELECT school.setting_value('session.idle_minutes') AS v")).v, 45);
  await rejects("UPDATE school.setting SET value = '200' WHERE key = 'session.idle_minutes'", [], '23514', 'setting_invalid');
  await rejects("UPDATE school.setting SET value = '\"x\"' WHERE key = 'session.idle_minutes'", [], '23514', 'setting_invalid');
  await rejects("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'finance.allow_self_validation', '1')", [a], '23514', 'setting_invalid');
  await as('school_runtime', a, 7);
  await rejects("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'finance.allow_self_validation', 'true')", [a], '42501', 'permission_denied');
  await as('school_runtime', b, 2);
  assert.equal((await first("SELECT school.setting_value('session.idle_minutes') AS v")).v, 30);
});
await as('platform_admin', null, 6);
await check('the console invites the administrator named at creation (FR-PLT-01)', async () => {
  const c = await create(uuid(), 'ecole-c', 'École C', 'essential', 5);
  const link = tokenHash();
  assert.deepEqual((await sql('SELECT user_id::int AS u, email FROM platform.invite_admin($1, $2)', [c, link])).rows, [{ u: 5, email: 'user5@example.test' }]);
  await as('school_runtime', null, null);
  assert.deepEqual(await first('SELECT name, school, needs_password FROM platform.peek_token($1, $2)', ['activation', link]),
    { name: 'Person 5', school: 'École C', needs_password: false });
  assert.equal((await sql('SELECT * FROM platform.peek_token($1, $2)', ['recovery', link])).rows.length, 0);
  await as('platform_admin', null, 6);
  await rejects('SELECT * FROM platform.invite_admin($1, $2)', [a, tokenHash()], '23514', 'not_invited');
});

// Referential (FR-PLT-03, FR-REF-01 to 06)
const node = async (type, code, tenant = a) => {
  await as(null);
  return (await first('SELECT id FROM school.ref_node WHERE tenant_id = $1 AND type_code = $2 AND code = $3', [tenant, type, code]))?.id;
};
const NODE = 'INSERT INTO school.ref_node (tenant_id, type_code, parent_id, code, labels) VALUES ($1, $2, $3, $4, $5) RETURNING id';
const secondaire = await node('cycle', 'SECONDAIRE');
const primaire = await node('cycle', 'PRIMAIRE');
await check('the private school profile gives four cycles and their levels, in French and Arabic (FR-PLT-03 AC1)', async () => {
  await as(null);
  assert.deepEqual((await sql(`SELECT type_code, count(*)::int AS n FROM school.ref_node WHERE tenant_id = $1 GROUP BY 1 ORDER BY 1`, [a])).rows,
    [{ type_code: 'cycle', n: 4 }, { type_code: 'grade', n: 15 }, { type_code: 'track', n: 14 }]);
  assert.equal(await count("SELECT count(*) n FROM school.ref_node WHERE tenant_id = $1 AND NOT (labels ? 'fr' AND labels ? 'ar')", [a]), 0);
  assert.deepEqual((await sql("SELECT code FROM school.ref_node WHERE tenant_id = $1 AND type_code = 'cycle' ORDER BY sort_order", [a])).rows.map(r => r.code),
    ['PRESCOLAIRE', 'PRIMAIRE', 'MOYEN', 'SECONDAIRE']);
  assert.equal(await count("SELECT count(*) n FROM school.subject WHERE tenant_id = $1 AND kind = 'subject'", [a]), 28);
  assert.deepEqual(await first('SELECT profile_code, profile_version FROM platform.tenant WHERE id = $1', [a]), { profile_code: 'private_school_dz', profile_version: 1 });
  assert.deepEqual((await first("SELECT after FROM school.audit_log WHERE tenant_id = $1 AND object_type = 'tenant' AND action = 'UPDATE' AND changed_fields ? 'profile_code'", [a])).after,
    { profile_code: 'private_school_dz', profile_version: 1 });
  assert.equal(await count('SELECT count(*) n FROM school.ref_node WHERE tenant_id = $1', [b]), 0);
});
await as('school_runtime', a, 1);
await check('a school renames a type, keeps its code, and turns off only an unused optional type (FR-REF-01)', async () => {
  await sql(`UPDATE school.node_type SET labels = '{"fr": "Classe", "ar": "القسم"}' WHERE code = 'grade'`);
  assert.equal((await first("SELECT labels ->> 'fr' AS l FROM school.node_type WHERE code = 'grade'")).l, 'Classe');
  await rejects("UPDATE school.node_type SET code = 'level' WHERE code = 'grade'", [], '42501');
  await rejects("UPDATE school.node_type SET active = false WHERE code = 'grade'", [], '23514', 'type_required');
  await rejects("UPDATE school.node_type SET active = false WHERE code = 'track'", [], '23514', 'type_in_use');
  await rejects("INSERT INTO school.node_type (tenant_id, code, labels, level_order, is_required) VALUES ($1, 'free', '{\"fr\": \"Libre\"}', 9, false)", [a], '42501');
});
await check('two levels named alike in one cycle are refused, whatever the case, spaces or Arabic diacritics (FR-REF-02 AC1)', async () => {
  await rejects(NODE, [a, 'grade', secondaire, '1AS_BIS', JSON.stringify({ fr: '  1re   année SECONDAIRE ' })], '23505');
  await rejects(NODE, [a, 'grade', secondaire, '1AS_TER', JSON.stringify({ ar: 'السَّنَةُ الأولى ثانوي' })], '23505');
  await rejects(NODE, [a, 'grade', secondaire, '1AS', JSON.stringify({ fr: 'Autre' })], '23505');
  // The same label under another parent is a different level.
  await sql(NODE, [a, 'grade', primaire, 'PREPA', JSON.stringify({ fr: 'Classe préparatoire' })]);
  await sql(NODE, [a, 'grade', secondaire, 'PREPA_SEC', JSON.stringify({ fr: 'Classe préparatoire' })]);
});
await check('labels are given in French or Arabic, never empty', async () => {
  await rejects(NODE, [a, 'grade', primaire, 'EN_ONLY', JSON.stringify({ en: 'Grade six' })], '23514');
  await rejects(NODE, [a, 'grade', primaire, 'BLANK', JSON.stringify({ fr: '   ' })], '23514');
  await rejects(NODE, [a, 'grade', primaire, 'bad-code', JSON.stringify({ fr: 'Code invalide' })], '23514');
});
await check('a node hangs from a node of the parent type, of this school, still in use', async () => {
  const cinqAP = await node('grade', '5AP');
  await as('school_runtime', a, 1);
  await rejects(NODE, [a, 'grade', cinqAP, 'X1', JSON.stringify({ fr: 'Sous un niveau' })], '23514', 'parent_invalid');
  await rejects(NODE, [a, 'cycle', primaire, 'X2', JSON.stringify({ fr: 'Cycle enfant' })], '23514', 'parent_invalid');
  await rejects(NODE, [a, 'grade', null, 'X3', JSON.stringify({ fr: 'Sans cycle' })], '23514', 'parent_invalid');
  await rejects(NODE, [a, 'track', uuid(), 'X4', JSON.stringify({ fr: 'Parent inconnu' })], '23514', 'parent_invalid');
});
await check('a parent is archived only once its children are; an archived node stays archived (FR-REF-02)', async () => {
  const prepa = await node('grade', 'PREPA');
  await as('school_runtime', a, 1);
  await rejects('UPDATE school.ref_node SET archived_at = now() WHERE id = $1', [primaire], '23514', 'node_has_children');
  await sql('UPDATE school.ref_node SET archived_at = now() WHERE id = $1', [prepa]);
  await rejects(NODE, [a, 'track', prepa, 'UNDER', JSON.stringify({ fr: 'Sous un archivé' })], '23514', 'parent_invalid');
  await rejects('UPDATE school.ref_node SET archived_at = NULL WHERE id = $1', [prepa], '23514', 'immutable_record');
  await rejects('DELETE FROM school.ref_node WHERE id = $1', [prepa], '42501');
  await sql(NODE, [a, 'grade', primaire, 'PREPA2', JSON.stringify({ fr: 'Classe préparatoire' })]);
});
await check('a node keeps its code, type and parent', async () => {
  await rejects("UPDATE school.ref_node SET code = 'Z' WHERE id = $1", [secondaire], '42501');
  await as(null);
  await rejects("UPDATE school.ref_node SET parent_id = $2 WHERE code = '1AS' AND tenant_id = $1", [a, primaire], '23514', 'identity_immutable');
});
await check('a cycle covers its levels and tracks', async () => {
  await as('school_runtime', a, 1);
  assert.equal(await count('SELECT count(*) n FROM school.node_subtree(ARRAY[$1]::uuid[])', [secondaire]), 1 + 3 + 14 + 1);
});
await check('a role scoped to levels or subjects names those of the school; groups wait for lot 2 (FR-IAM-04)', async () => {
  const math = (await first("SELECT id FROM school.subject WHERE code = 'MATH'")).id;
  await assign(9, 'teacher', 'level', [secondaire]);
  await assign(9, 'teacher', 'subject', [math]);
  await rejects(ASSIGN, [a, 9, roles.teacher, 'level', `{${uuid()}}`, null, null], '23514', 'scope_unknown');
  await rejects(ASSIGN, [a, 9, roles.teacher, 'subject', `{${siteA}}`, null, null], '23514', 'scope_unknown');
  await rejects(ASSIGN, [a, 9, roles.teacher, 'group', `{${uuid()}}`, null, null], '23514', 'scope_unknown');
  await rejects("DELETE FROM school.subject WHERE code = 'MATH'", [], '23514', 'subject_in_use');
});
const SUBJECT = 'INSERT INTO school.subject (tenant_id, code, kind, labels) VALUES ($1, $2, $3, $4) RETURNING id';
await check('a subject never used is deleted, its values kept in the journal (FR-REF-03 AC1)', async () => {
  const robot = (await first(SUBJECT, [a, 'ROBOT', 'subject', JSON.stringify({ fr: 'Robotique', ar: 'الروبوتيك' })])).id;
  await sql('DELETE FROM school.subject WHERE id = $1', [robot]);
  await as(null);
  assert.deepEqual(await first("SELECT before -> 'labels' ->> 'fr' AS l, before ->> 'code' AS c FROM school.audit_log WHERE object_id = $1 AND action = 'DELETE'", [robot]),
    { l: 'Robotique', c: 'ROBOT' });
});
await check('a subject label is unique in the catalog, and its code permanent (FR-REF-03)', async () => {
  await as('school_runtime', a, 1);
  await rejects(SUBJECT, [a, 'MATHS2', 'subject', JSON.stringify({ fr: '  MATHéMATIQUES ' })], '23505');
  await rejects(SUBJECT, [a, 'MATH', 'subject', JSON.stringify({ fr: 'Maths bis' })], '23505');
  await rejects("UPDATE school.subject SET code = 'M' WHERE code = 'MATH'", [], '42501');
  await sql("UPDATE school.subject SET archived_at = now() WHERE code = 'REST'");
  await sql(SUBJECT, [a, 'REST2', 'subject', JSON.stringify({ fr: 'Repos et activités calmes' })]);
});
await check('a training centre gets its types and modules, where a school has levels and subjects (FR-REF-01 AC1, FR-REF-03 AC3)', async () => {
  await as('platform_admin', null, 6);
  assert.equal((await first("SELECT platform.apply_profile($1, 'training_center') AS v", [b])).v, 1);
  await as(null);
  assert.deepEqual((await sql('SELECT code, labels ->> \'fr\' AS fr, parent_code FROM school.node_type WHERE tenant_id = $1 ORDER BY level_order', [b])).rows,
    [{ code: 'domain', fr: 'Domaine', parent_code: null }, { code: 'specialty', fr: 'Spécialité', parent_code: 'domain' },
     { code: 'pathway', fr: 'Parcours', parent_code: 'specialty' }]);
  assert.equal((await first("SELECT subject_kind FROM platform.profile WHERE code = 'training_center'")).subject_kind, 'module');
  await as('platform_admin', null, 6);
  await rejects("SELECT platform.apply_profile($1, 'private_school_dz')", [b], '23514', 'profile_mismatch');
  await rejects("SELECT platform.apply_profile($1, 'nothing')", [b], '23514', 'profile_unknown');
});
await check('a published profile never changes; its next version reaches a school only when applied, adding without overwriting (FR-PLT-03 AC2)', async () => {
  await as(null);
  await rejects(`UPDATE platform.profile_node SET labels = '{"fr": "Autre"}' WHERE profile_code = 'private_school_dz' AND code = '1AS'`, [], '23514', 'profile_published');
  await sql(`INSERT INTO platform.profile SELECT code, 2, labels, subject_kind, NULL FROM platform.profile WHERE code = 'private_school_dz' AND version = 1`);
  await sql(`INSERT INTO platform.profile_node_type SELECT profile_code, 2, code, parent_code, labels, level_order, is_required FROM platform.profile_node_type WHERE profile_code = 'private_school_dz' AND version = 1`);
  await sql(`INSERT INTO platform.profile_node SELECT profile_code, 2, type_code, code, parent_code, labels, sort_order, platform_code FROM platform.profile_node WHERE profile_code = 'private_school_dz' AND version = 1`);
  await sql(`INSERT INTO platform.profile_node (profile_code, version, type_code, code, parent_code, labels, sort_order) VALUES ('private_school_dz', 2, 'track', '1AS_TCLE', '1AS', '{"fr": "Tronc commun langues", "ar": "جذع مشترك لغات"}', 3)`);
  await sql("UPDATE platform.profile SET published_at = now() WHERE code = 'private_school_dz' AND version = 2");
  const before = await count('SELECT count(*) n FROM school.ref_node WHERE tenant_id = $1', [a]);
  assert.equal((await first('SELECT profile_version FROM platform.tenant WHERE id = $1', [a])).profile_version, 1);
  await as('platform_admin', null, 6);
  assert.equal((await first("SELECT platform.apply_profile($1, 'private_school_dz') AS v", [a])).v, 2);
  await as(null);
  assert.equal(await count('SELECT count(*) n FROM school.ref_node WHERE tenant_id = $1', [a]), before + 1);
  assert.equal((await first("SELECT labels ->> 'fr' AS l FROM school.node_type WHERE tenant_id = $1 AND code = 'grade'", [a])).l, 'Classe');
  assert.equal(await count("SELECT count(*) n FROM school.ref_node WHERE tenant_id = $1 AND code = 'PREPA' AND archived_at IS NOT NULL", [a]), 1);
});
const YEAR = 'INSERT INTO school.academic_year (tenant_id, code, start_date, end_date, status) VALUES ($1, $2, $3, $4, $5) RETURNING id';
const PERIOD = 'INSERT INTO school.period (tenant_id, year_id, code, labels, start_date, end_date) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id';
await as('school_runtime', a, 1);
const year = (await first(YEAR, [a, '2026-2027', '2026-09-01', '2027-06-30', 'current'])).id;
await check('one current year at most (FR-REF-05)', async () => {
  await rejects(YEAR, [a, '2027-2028', '2027-09-01', '2028-06-30', 'current'], '23505');
  await sql(YEAR, [a, '2027-2028', '2027-09-01', '2028-06-30', 'planned']);
  await rejects(YEAR, [a, '2025-2026', '2025-09-01', '2026-06-30', 'closed'], '23514', 'status_invalid');
  await as('school_runtime', b, 2);
  await sql(YEAR, [b, '2026-2027', '2026-09-01', '2027-06-30', 'current']);
});
await check('periods stay inside their year and never overlap (FR-REF-05 AC1)', async () => {
  await as('school_runtime', a, 1);
  await sql(PERIOD, [a, year, 'T1', JSON.stringify({ fr: '1er trimestre', ar: 'الفصل الأول' }), '2026-09-01', '2026-11-30']);
  const t2 = (await first(PERIOD, [a, year, 'T2', JSON.stringify({ fr: '2e trimestre' }), '2026-12-01', '2027-03-15'])).id;
  await rejects(PERIOD, [a, year, 'T3', JSON.stringify({ fr: '3e trimestre' }), '2027-03-01', '2027-06-30'], '23P01');
  await rejects(PERIOD, [a, year, 'ETE', JSON.stringify({ fr: 'Été' }), '2027-06-01', '2027-07-31'], '23514', 'period_outside_year');
  await sql("UPDATE school.period SET end_date = '2027-02-28' WHERE id = $1", [t2]);
  await sql(PERIOD, [a, year, 'T3', JSON.stringify({ fr: '3e trimestre' }), '2027-03-01', '2027-06-30']);
  await rejects("UPDATE school.academic_year SET end_date = '2027-05-31' WHERE id = $1", [year], '23514', 'period_outside_year');
});
await check('a closed year is final, with its periods (FR-REF-05)', async () => {
  assert.equal((await first('SELECT school.year_open($1) AS o', [year])).o, true);
  await sql("UPDATE school.academic_year SET status = 'closed' WHERE id = $1", [year]);
  assert.equal((await first('SELECT school.year_open($1) AS o', [year])).o, false);
  await rejects("UPDATE school.academic_year SET status = 'current' WHERE id = $1", [year], '23514', 'year_closed');
  await rejects("UPDATE school.period SET labels = '{\"fr\": \"Autre\"}' WHERE year_id = $1", [year], '23514', 'year_closed');
  await rejects(PERIOD, [a, year, 'T4', JSON.stringify({ fr: 'Rattrapage' }), '2027-06-01', '2027-06-30'], '23514', 'year_closed');
  await sql("UPDATE school.academic_year SET status = 'current' WHERE code = '2027-2028'");
});
const FIELD = 'INSERT INTO school.custom_field (tenant_id, entity, key, field_type, options, required, labels) VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id';
const CHECK_VALUES = 'SELECT school.check_custom_values($1, $2)';
await check('a custom select field takes only its values; a required one cannot stay empty (FR-REF-04 AC1, AC2)', async () => {
  const options = [{ code: 'A_POS', labels: { fr: 'A+' } }, { code: 'O_NEG', labels: { fr: 'O−' } }];
  await sql(FIELD, [a, 'student', 'blood_group', 'select', JSON.stringify(options), false, JSON.stringify({ fr: 'Groupe sanguin' })]);
  await sql(FIELD, [a, 'student', 'emergency_phone', 'text', null, true, JSON.stringify({ fr: 'Téléphone d’urgence' })]);
  await sql(CHECK_VALUES, ['student', JSON.stringify({ blood_group: 'A_POS', emergency_phone: '0550 00 00 00' })]);
  let error;
  try { await sql(CHECK_VALUES, ['student', JSON.stringify({ blood_group: 'B_POS', emergency_phone: '0550' })]); } catch (e) { error = e; }
  assert.deepEqual([error?.message, error?.hint], ['custom_value_invalid', 'blood_group']);
  await rejects(CHECK_VALUES, ['student', JSON.stringify({ blood_group: 'A_POS' })], '23514', 'custom_value_required');
  await rejects(CHECK_VALUES, ['student', JSON.stringify({ emergency_phone: '0550', shoe_size: 42 })], '23514', 'custom_field_unknown');
  await sql(CHECK_VALUES, ['guardian', JSON.stringify({})]);
});
await check('a field keeps its key and type; options are added, never removed; archived values stay valid (FR-REF-04)', async () => {
  await rejects("UPDATE school.custom_field SET field_type = 'text' WHERE key = 'blood_group'", [], '42501');
  await rejects(`UPDATE school.custom_field SET options = '[{"code": "A_POS", "labels": {"fr": "A+"}}]' WHERE key = 'blood_group'`, [], '23514', 'option_removed');
  await sql("UPDATE school.custom_field SET archived_at = now() WHERE key = 'blood_group'");
  await sql(CHECK_VALUES, ['student', JSON.stringify({ blood_group: 'A_POS', emergency_phone: '0550' })]);
  await rejects(FIELD, [a, 'student', 'size', 'select', JSON.stringify([{ code: 'small', labels: { fr: 'S' } }]), false, JSON.stringify({ fr: 'Taille' })], '23514', 'options_invalid');
});
await check('twenty active custom fields per entity at most (FR-REF-04)', async () => {
  for (let i = 1; i <= 19; i++) await sql(FIELD, [a, 'student', `extra_${i}`, 'boolean', null, false, JSON.stringify({ fr: `Champ ${i}` })]);
  await rejects(FIELD, [a, 'student', 'extra_20', 'boolean', null, false, JSON.stringify({ fr: 'Champ 20' })], '23514', 'custom_field_limit');
  await sql(FIELD, [a, 'staff', 'extra_1', 'number', null, false, JSON.stringify({ fr: 'Champ' })]);
});
await check('a school is open Sunday to Thursday unless it says otherwise (FR-REF-06)', async () => {
  assert.equal((await first("SELECT school.open_day('2026-10-11') AS o")).o, true);
  assert.equal((await first("SELECT school.open_day('2026-10-09') AS o")).o, false);
  await rejects("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'calendar.open_days', '[0, 7]')", [a], '23514', 'setting_invalid');
  await rejects("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'calendar.open_days', '[1, 1]')", [a], '23514', 'setting_invalid');
  await rejects("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'locale.languages', '[\"fr\", \"de\"]')", [a], '23514', 'setting_invalid');
  await sql("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'calendar.open_days', '[0, 1, 2, 3, 4, 6]')", [a]);
  assert.equal((await first("SELECT school.open_day('2026-10-10') AS o")).o, true);
});
await check('a school cannot see or write the referential of another school', async () => {
  await as('school_runtime', b, 2);
  assert.equal(await count('SELECT count(*) n FROM school.ref_node WHERE tenant_id = $1', [a]), 0);
  assert.equal(await count('SELECT count(*) n FROM school.subject WHERE tenant_id = $1', [a]), 0);
  await rejects(NODE, [a, 'cycle', null, 'HACK', JSON.stringify({ fr: 'Intrus' })], '42501');
});

// Shared services: the journal by domain, jobs, notifications and their outbox (FR-AUD-01, FR-JOB-01, FR-NOT-01)
await check('the journal opens by domain: administrators read access and settings, an accountant does not (FR-AUD-01)', async () => {
  const read = async (user, type) => {
    await as('school_runtime', a, user);
    return count('SELECT count(*) n FROM school.audit_log WHERE object_type = $1', [type]);
  };
  assert.ok(await read(1, 'role_assignment') > 0);
  assert.ok(await read(1, 'setting') > 0);
  assert.equal(await read(7, 'role_assignment'), 0);
  assert.equal(await read(7, 'tenant'), 0);
  assert.ok(await read(7, 'site') > 0);
  await as('school_runtime', a, 4);
  assert.equal(await count('SELECT count(*) n FROM school.audit_log'), 0);
  await as('school_runtime', a, 1);
  await rejects("UPDATE school.audit_log SET reason = 'x'", [], '42501');
});
await check('every audited table belongs to a journal domain', async () => {
  await as(null);
  assert.deepEqual((await sql(`SELECT c.relname FROM pg_trigger tg JOIN pg_class c ON c.oid = tg.tgrelid JOIN pg_namespace ns ON ns.oid = c.relnamespace
    WHERE tg.tgname = 'audit' AND ns.nspname = 'school' AND c.relname NOT IN (SELECT object_type FROM platform.audit_domain)`)).rows, []);
});
const JOB = "INSERT INTO school.job (tenant_id, user_id, kind, idempotency_key, input) VALUES ($1, $2, 'accounts.import', $3, '{}') RETURNING id";
await as('school_runtime', a, 1);
const job = (await first(JOB, [a, 1, 'import-1'])).id;
await check('a job is started once per key and seen by its author only (FR-JOB-01)', async () => {
  await rejects(JOB, [a, 1, 'import-1'], '23505');
  await rejects(JOB, [a, 8, 'import-2'], '42501');
  await as('school_runtime', a, 8);
  assert.equal(await count('SELECT count(*) n FROM school.job'), 0);
  await as('school_runtime', b, 2);
  assert.equal(await count('SELECT count(*) n FROM school.job'), 0);
});
await check('a job moves queued, running, then finished, and a finished job never changes (FR-JOB-01)', async () => {
  await as('school_runtime', a, 1);
  await rejects("UPDATE school.job SET status = 'succeeded' WHERE id = $1", [job], '23514', 'status_invalid');
  await sql("UPDATE school.job SET status = 'running' WHERE id = $1", [job]);
  assert.deepEqual(await first('SELECT attempts, started_at IS NOT NULL AS started FROM school.job WHERE id = $1', [job]), { attempts: 1, started: true });
  await rejects(`UPDATE school.job SET input = '{"rows": []}' WHERE id = $1`, [job], '42501');
  await sql("UPDATE school.job SET status = 'succeeded', result = '{\"created\": 3}' WHERE id = $1", [job]);
  assert.deepEqual(await first('SELECT progress, finished_at IS NOT NULL AS finished FROM school.job WHERE id = $1', [job]), { progress: 100, finished: true });
  await rejects("UPDATE school.job SET status = 'queued' WHERE id = $1", [job], '23514', 'job_finished');
});
await check('assigning a sensitive permission notifies the journal\'s watchers, not the author (FR-IAM-05)', async () => {
  await as('school_runtime', a, 1);
  const before = await count("SELECT count(*) n FROM school.notification");
  await assign(9, 'accountant');
  assert.equal(await count("SELECT count(*) n FROM school.notification"), before);
  await as('school_runtime', a, 8);
  assert.equal(await count("SELECT count(*) n FROM school.notification WHERE event = 'access.sensitive_permission' AND data ->> 'user_id' = '9'"), 1);
  await as(null);
  assert.equal(await count(`SELECT count(*) n FROM school.outbox o JOIN school.notification n ON n.tenant_id = o.tenant_id AND n.id = o.notification_id
    WHERE o.tenant_id = $1 AND o.user_id = 8 AND n.data ->> 'user_id' = '9'`, [a]), 1);
});
await check('a person sees only their own notifications and chooses which ones come by email (FR-NOT-01 AC2)', async () => {
  await as('school_runtime', a, 9);
  assert.equal(await count('SELECT count(*) n FROM school.notification WHERE user_id <> 9'), 0);
  await as('school_runtime', a, 8);
  await sql("INSERT INTO school.notification_preference (tenant_id, user_id, event, email) VALUES ($1, 8, 'access.sensitive_permission', false)", [a]);
  await rejects("INSERT INTO school.notification_preference (tenant_id, user_id, event, email) VALUES ($1, 7, 'job.failed', false)", [a], '42501');
  await rejects("INSERT INTO school.notification (tenant_id, user_id, event) VALUES ($1, 9, 'job.failed')", [a], '42501');
  await as(null);
  const emails = async user => count('SELECT count(*) n FROM school.outbox WHERE tenant_id = $1 AND user_id = $2', [a, user]);
  const [before8, before9] = [await emails(8), await emails(9)];
  await as('school_runtime', a, 1);
  assert.equal((await first("SELECT school.notify('access.sensitive_permission', ARRAY[8, 9, 5]::bigint[]) AS n")).n, 2, 'user 5 is suspended in school A');
  await as(null);
  assert.deepEqual([await emails(8), await emails(9)], [before8, before9 + 1]);
});
await check('the worker claims each email once, removes it when sent, and keeps it as a failure after five attempts (FR-NOT-01 AC1)', async () => {
  await as('school_runtime', null, null);
  const claimed = (await sql('SELECT id, tenant_id, email, event, school FROM platform.claim_outbox(50, 60)')).rows;
  assert.ok(claimed.length >= 2);
  assert.ok(claimed.some(r => r.email === 'user8@example.test' && r.event === 'access.sensitive_permission' && r.school === 'École A'));
  assert.equal((await sql('SELECT * FROM platform.claim_outbox(50, 60)')).rows.length, 0);
  const sent = claimed[0];
  const failing = claimed.find(r => r.tenant_id === a && r.id !== sent.id);
  await sql('SELECT platform.finish_outbox($1, NULL)', [sent.id]);
  for (let attempt = 1; attempt <= 5; attempt++) {
    if (attempt > 1) {
      await as(null);
      await sql("UPDATE school.outbox SET next_attempt_at = now() WHERE id = $1", [failing.id]);
      await as('school_runtime', null, null);
      assert.ok((await sql('SELECT id FROM platform.claim_outbox(50, 60)')).rows.some(r => r.id === failing.id));
    }
    await sql("SELECT platform.finish_outbox($1, 'SMTP 451 temporary failure')", [failing.id]);
  }
  await as(null);
  assert.equal(await count('SELECT count(*) n FROM school.outbox WHERE id = $1', [sent.id]), 0);
  assert.deepEqual(await first('SELECT status, attempts FROM school.outbox WHERE id = $1', [failing.id]), { status: 'failed', attempts: 5 });
  // A crashed worker's lease runs out and the email comes back.
  await sql("UPDATE school.outbox SET locked_until = now() - interval '1 second', next_attempt_at = now() WHERE status = 'sending'");
  await as('school_runtime', null, null);
  assert.ok((await sql('SELECT * FROM platform.claim_outbox(50, 60)')).rows.length >= 1);
  await as('school_runtime', a, 7);
  assert.equal(await count("SELECT count(*) n FROM school.outbox WHERE status = 'failed'"), 0);
  await rejects('SELECT school.retry_delivery($1)', [failing.id], '42501', 'permission_denied');
  await as('school_runtime', a, 1);
  assert.equal(await count("SELECT count(*) n FROM school.outbox WHERE status = 'failed'"), 1);
  await sql('SELECT school.retry_delivery($1)', [failing.id]);
  assert.equal((await first('SELECT status FROM school.outbox WHERE id = $1', [failing.id])).status, 'pending');
  await rejects('SELECT school.retry_delivery($1)', [failing.id], '23514', 'not_failed');
});
await check('a notification is only for active members of the school', async () => {
  await as('school_runtime', a, 1);
  assert.equal((await first("SELECT school.notify('job.succeeded', ARRAY[1, 5]::bigint[], '{\"job_id\": \"x\"}') AS n")).n, 1);
  await as('school_runtime', null, 1);
  await rejects("SELECT school.notify('job.succeeded', ARRAY[1]::bigint[])", [], '23514', 'tenant_required');
});

// Files (FR-FIC-01)
const FILE = `INSERT INTO school.file_object (tenant_id, category, storage_key, original_name, file_type, media_type, size_bytes, sha256)
  VALUES ($1::uuid, $2, 'tenant/' || $1::text || '/' || $2 || '/' || gen_random_uuid(), $3, $4, 'application/octet-stream', $5, repeat('a', 64)) RETURNING id`;
const file = async (category, type = 'pdf', size = 1000, tenant = a) => (await first(FILE, [tenant, category, `piece.${type}`, type, size])).id;
await as('school_runtime', a, 7);
const invoice = await file('finance_attachment');
await check('a financial attachment is seen with a finance permission, not by the administrator (FR-FIC-01 AC2)', async () => {
  assert.equal(await count('SELECT count(*) n FROM school.file_object WHERE id = $1', [invoice]), 1);
  for (const user of [8, 4]) {
    await as('school_runtime', a, user);
    assert.equal(await count('SELECT count(*) n FROM school.file_object WHERE id = $1', [invoice]), 0);
  }
  await as('school_runtime', b, 2);
  assert.equal(await count('SELECT count(*) n FROM school.file_object'), 0);
});
await check('adding a file needs its category\'s permission; exports belong to their author', async () => {
  await as('school_runtime', a, 8);
  await rejects(FILE, [a, 'finance_attachment', 'x.pdf', 'pdf', 10], '42501');
  const exported = await file('export', 'csv');
  assert.equal(await count('SELECT count(*) n FROM school.file_object WHERE id = $1', [exported]), 1);
  await as('school_runtime', a, 1);
  assert.equal(await count('SELECT count(*) n FROM school.file_object WHERE id = $1', [exported]), 0);
});
await check('a file is stored under its school\'s prefix and keeps its identity', async () => {
  await as('school_runtime', a, 7);
  await rejects(`INSERT INTO school.file_object (tenant_id, category, storage_key, original_name, file_type, media_type, size_bytes, sha256)
    VALUES ($1::uuid, 'finance_attachment', 'tenant/' || $2::text || '/finance_attachment/x', 'x.pdf', 'pdf', 'application/pdf', 10, repeat('a', 64))`, [a, b], '23514');
  await rejects("UPDATE school.file_object SET sha256 = repeat('b', 64) WHERE id = $1", [invoice], '42501');
  await as(null);
  await rejects("UPDATE school.file_object SET sha256 = repeat('b', 64) WHERE id = $1", [invoice], '23514', 'identity_immutable');
});
await check('types and size follow the school\'s settings; a photo is an image, of a pupil (FR-FIC-01, FR-ELV-01)', async () => {
  await as('school_runtime', a, 7);
  await rejects(FILE, [a, 'finance_attachment', 'x.csv', 'csv', 10], '23514', 'file_type_refused');
  await rejects(FILE, [a, 'finance_attachment', 'x.pdf', 'pdf', 11 * 1024 * 1024], '23514', 'file_too_large');
  await rejects(FILE, [a, 'student_photo', 'x.pdf', 'pdf', 10], '23514', 'file_type_refused');
  await rejects(FILE, [a, 'student_photo', 'x.jpeg', 'jpeg', 10], '42501');
  await as('school_runtime', a, 1);
  await rejects("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'files.types', '[\"pdf\", \"exe\"]')", [a], '23514', 'setting_invalid');
  await sql("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'files.types', '[\"pdf\", \"csv\"]')", [a]);
  await as('school_runtime', a, 7);
  await file('finance_attachment', 'csv');
  await rejects(FILE, [a, 'finance_attachment', 'x.png', 'png', 10], '23514', 'file_type_refused');
});
await check('the storage quota is never exceeded (FR-PLT-02)', async () => {
  await as('school_runtime', a, 7);
  await rejects(FILE, [a, 'export', 'big.csv', 'csv', 1024 * 1024 * 1024], '23514', 'storage_quota_exceeded');
});
await check('an expired school still exports, and adds no other file (FR-PLT-02 AC3)', async () => {
  await as('platform_admin', null, 6);
  const d = await create(uuid(), 'ecole-d', 'École D', 'essential', null);
  await sql('SELECT platform.set_entitlement($1, $2::date, $3::date, 15, 500, 1024, 0)', [d, '2025-01-01', '2025-06-30']);
  await as(null);
  await sql(FILE, [d, 'export', 'liste.csv', 'csv', 10]);
  await rejects(FILE, [d, 'student_document', 'acte.pdf', 'pdf', 10], '25006', 'tenant_read_only');
});
await check('a signed link reaches a file still in storage; expired exports are purged and stop counting', async () => {
  await as('school_runtime', null, null);
  assert.equal((await sql('SELECT * FROM school.file_for_link($1, $2)', [a, invoice])).rows.length, 1);
  assert.equal((await sql('SELECT * FROM school.file_for_link($1, $2)', [b, invoice])).rows.length, 0);
  await as(null);
  const { id: exported, size_bytes: size } = await first("SELECT id, size_bytes::int AS size_bytes FROM school.file_object WHERE tenant_id = $1 AND category = 'export' AND purged_at IS NULL LIMIT 1", [a]);
  const used = async () => Number((await first('SELECT used FROM school.storage_use($1)', [a])).used);
  const before = await used();
  await sql("UPDATE school.file_object SET expires_at = now() - interval '1 day' WHERE id = $1", [exported]);
  await as('school_runtime', null, null);
  assert.ok((await sql('SELECT id FROM platform.expired_files(10)')).rows.some(r => r.id === exported));
  await sql('SELECT platform.mark_purged($1)', [exported]);
  await as(null);
  assert.equal(await used(), before - size);
  await as('school_runtime', null, null);
  assert.equal((await sql('SELECT * FROM school.file_for_link($1, $2)', [a, exported])).rows.length, 0);
});

// Export log (FR-IMP-02)
await check('every export is logged by its author, read by the journal\'s readers, and never changed (FR-IMP-02)', async () => {
  await as('school_runtime', a, 8);
  const exported = (await first(FILE, [a, 'export', 'comptes.csv', 'csv', 10])).id;
  const LOG = `INSERT INTO school.export_log (tenant_id, user_id, kind, format, filters, columns, row_count, file_id)
    VALUES ($1, $2, 'accounts', 'csv', '{"status": "active"}', '{name,email}', 12, $3) RETURNING id`;
  const log = (await first(LOG, [a, 8, exported])).id;
  await rejects(LOG, [a, 1, exported], '42501');
  await rejects("UPDATE school.export_log SET row_count = 0 WHERE id = $1", [log], '42501');
  await as('school_runtime', a, 1);
  assert.equal(await count('SELECT count(*) n FROM school.export_log WHERE id = $1', [log]), 1, 'an administrator reads the journal');
  await as('school_runtime', a, 4);
  assert.equal(await count('SELECT count(*) n FROM school.export_log'), 0);
  await as(null);
  await rejects('DELETE FROM school.export_log WHERE id = $1', [log], '23514', 'immutable_record');
});

// Imports (FR-IMP-01)
await check('an import batch is its author\'s, moves forward only, and its rows follow it (FR-IMP-01)', async () => {
  await as('school_runtime', a, 1);
  const original = (await first(FILE, [a, 'import', 'comptes.csv', 'csv', 10])).id;
  const batch = (await first("INSERT INTO school.import_batch (tenant_id, user_id, kind, file_id) VALUES ($1, 1, 'accounts', $2) RETURNING id", [a, original])).id;
  await sql("INSERT INTO school.import_row (tenant_id, batch_id, line, external_ref, data, outcome, action) VALUES ($1, $2, 1, 'EL-1', '{}', 'valid', 'create')", [a, batch]);
  await rejects("INSERT INTO school.import_row (tenant_id, batch_id, line, data, outcome) VALUES ($1, $2, 2, '{}', 'rejected')", [a, batch], '23514');
  await rejects("UPDATE school.import_batch SET status = 'imported' WHERE id = $1", [batch], '23514', 'status_invalid');
  await sql("UPDATE school.import_batch SET status = 'previewed' WHERE id = $1", [batch]);
  assert.equal((await first('SELECT previewed_at IS NOT NULL AS p FROM school.import_batch WHERE id = $1', [batch])).p, true);
  await as('school_runtime', a, 8);
  assert.equal(await count('SELECT count(*) n FROM school.import_batch WHERE id = $1', [batch]), 1, 'import.job.read follows every import');
  await as('school_runtime', a, 4);
  assert.equal(await count('SELECT count(*) n FROM school.import_row'), 0);
});
await check('undoing an imported account removes only an account nobody has used (FR-IMP-01 rollback)', async () => {
  await as('school_runtime', a, 1);
  const fresh = await first(CREATE_ACCOUNT, ['Importé', null, 'importe.1', 'IMP-1', null]);
  const used = await first(CREATE_ACCOUNT, ['Utilisé', 'utilise@example.test', null, 'IMP-2', null]);
  await assign(used.id, 'teacher');
  await rejects('SELECT school.discard_account($1)', [used.id], '23514', 'dependent_operation');
  await rejects('SELECT school.discard_account(3)', [], '23514', 'dependent_operation');
  await sql('SELECT school.discard_account($1)', [fresh.id]);
  await as(null);
  assert.equal(await count('SELECT count(*) n FROM public.users WHERE id = $1', [fresh.id]), 0);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE tenant_id = $1 AND action = 'DISCARD'", [a]), 1);
  await rejects('DELETE FROM school.membership WHERE tenant_id = $1 AND user_id = $2', [a, used.id], '23514', 'immutable_record');
  await as('school_runtime', a, 7);
  await rejects('SELECT school.discard_account($1)', [used.id], '42501', 'permission_denied');
});

// Console (W1.2, FR-PLT-01, FR-PLT-05)
await check('the console names a first administrator by email, creating the person once (FR-PLT-01)', async () => {
  await as('platform_admin', null, 6);
  const id = (await first("SELECT platform.ensure_user(' Direction@Ecole-E.dz ', 'Direction E') AS u")).u;
  assert.equal((await first("SELECT platform.ensure_user('direction@ecole-e.dz', 'Autre nom') AS u")).u, id);
  assert.equal(Number((await first("SELECT platform.ensure_user('user1@example.test', 'Ignored') AS u")).u), 1);
  await rejects("SELECT platform.ensure_user('nouveau@ecole.dz', '')", [], '23514', 'name_required');
  await rejects("INSERT INTO public.users (name, email) VALUES ('x', 'x@x.dz')", [], '42501');
  await as(null);
  assert.deepEqual(await first('SELECT name, password FROM public.users WHERE id = $1', [id]), { name: 'Direction E', password: null });
});
await check('supervision counts each school\'s activity without showing any of its data (FR-PLT-05)', async () => {
  await as('platform_admin', null, 6);
  const row = await first("SELECT * FROM platform.supervision() WHERE slug = 'ecole-a'");
  assert.equal(row.status, 'active');
  assert.ok(row.members >= 5 && Number(row.storage_used) > 0 && Number(row.storage_quota) === 1024 * 1048576 && row.modules >= 5);
  assert.deepEqual(Object.keys(row).sort(), ['emails_failed', 'jobs_active', 'jobs_failed', 'last_activity', 'members', 'mode', 'modules', 'name',
    'plan', 'slug', 'status', 'storage_quota', 'storage_used', 'tenant_id', 'valid_until']);
  await rejects('SELECT count(*) FROM school.membership', [], '42501');
});

// Support access (FR-PLT-04)
const GRANT = "INSERT INTO school.support_grant (tenant_id, reason, expires_at, include_sensitive) VALUES ($1, $2, now() + ($3 || ' hours')::interval, $4) RETURNING id";
const support = grant => as('school_runtime', a, 6, { support: grant ?? null });
await as(null);
await sql('INSERT INTO platform.operator (user_id) VALUES (6) ON CONFLICT DO NOTHING');
await as('school_runtime', a, 1);
const consent = (await first(GRANT, [a, 'Ticket 1042 : import bloqué', 48, false])).id;
await check('a school consents for 72 hours at most, by the permission meant for it (FR-PLT-04)', async () => {
  await rejects(GRANT, [a, 'Trop long', 73, false], '23514');
  await as('school_runtime', a, 7);
  await rejects(GRANT, [a, 'Sans droit', 24, false], '42501', 'permission_denied');
});
await check('without consent the operator reads nothing of the school (FR-PLT-04 AC1)', async () => {
  await support(null);
  assert.equal(await sites(), 0);
  await support(uuid());
  assert.equal(await sites(), 0);
});
await check('under consent the operator reads, read-only, without sensitive data, and each consultation is journaled', async () => {
  await support(consent);
  assert.ok(await sites() > 0);
  const granted = (await sql('SELECT DISTINCT permission FROM school.my_permissions()')).rows.map(r => r.permission);
  assert.ok(granted.includes('referential.node.read') && granted.includes('finance.payment.read') && granted.includes('audit.log.read'));
  assert.ok(!granted.includes('student.sensitive.read') && !granted.some(p => !p.endsWith('.read')));
  await rejects("INSERT INTO school.site(tenant_id, code, labels) VALUES ($1, 'SUPPORT', '{\"fr\": \"x\"}')", [a], '25006', 'support_read_only');
  assert.equal(await count("SELECT count(*) n FROM school.file_object WHERE category = 'finance_attachment'"), 0);
  await sql("SELECT school.log_support_access('GET /api/sites')");
  await support(null);
  await as('school_runtime', a, 1);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE action = 'SUPPORT_READ' AND object_id = $1 AND actor_id = 6", [consent]), 1);
});
await check('a distinct consent opens sensitive data and financial attachments', async () => {
  await as('school_runtime', a, 1);
  const wide = (await first(GRANT, [a, 'Ticket 1043 : écart de paie', 2, true])).id;
  await support(wide);
  assert.ok((await sql('SELECT DISTINCT permission FROM school.my_permissions()')).rows.some(r => r.permission === 'student.sensitive.read'));
  assert.ok(await count("SELECT count(*) n FROM school.file_object WHERE category = 'finance_attachment'") > 0);
  await support(null);
});
await check('revoked or expired, a consent opens nothing more (FR-PLT-04 AC2)', async () => {
  await as('school_runtime', a, 1);
  const short = (await first(GRANT, [a, 'Ticket 1044', 1, false])).id;
  await sql('UPDATE school.support_grant SET revoked_at = now() WHERE id = $1', [short]);
  await rejects('UPDATE school.support_grant SET revoked_at = NULL WHERE id = $1', [short], '23514', 'immutable_record');
  await support(short);
  assert.equal(await sites(), 0);
  await as(null);
  const expired = (await first(`INSERT INTO school.support_grant (tenant_id, reason, starts_at, expires_at) VALUES ($1, 'Ticket 1040', now() - interval '3 hours',
    now() - interval '1 hour') RETURNING id`, [a])).id;
  await support(expired);
  assert.equal(await sites(), 0);
  await rejects("SELECT school.log_support_access('GET /api/sites')", [], '42501', 'support_not_granted');
  await support(null);
});
await check('the school sees every consent, the console their dates and use, never the school\'s data (FR-PLT-04 AC3)', async () => {
  await as('school_runtime', a, 1);
  assert.ok(await count('SELECT count(*) n FROM school.support_grant') >= 3);
  await as('platform_admin', null, 6);
  const listed = (await sql('SELECT reason, consultations::int AS c FROM platform.support_grants($1) ORDER BY reason', [a])).rows;
  assert.deepEqual(listed.find(r => r.reason === 'Ticket 1042 : import bloqué'), { reason: 'Ticket 1042 : import bloqué', c: 1 });
});

// Lot 2: pupils and guardians, programmes, groups, enrolments (FR-ELV, FR-PRG, FR-GRP, FR-INS)
await as(null);
const lotYear = (await first("SELECT id FROM school.academic_year WHERE tenant_id = $1 AND code = '2027-2028'", [a])).id;
const grade = (await first("SELECT id FROM school.ref_node WHERE tenant_id = $1 AND type_code = 'grade' AND archived_at IS NULL ORDER BY code LIMIT 1", [a])).id;
// The school's own required fields on pupils, filled.
const pupilFields = JSON.stringify(Object.fromEntries((await sql(
  "SELECT key FROM school.custom_field WHERE tenant_id = $1 AND entity = 'student' AND required AND archived_at IS NULL", [a])).rows.map(r => [r.key, '0550'])));
const STUDENT = `INSERT INTO school.student (tenant_id, last_name_lat, first_name_lat, last_name_ar, first_name_ar, birth_date, custom)
  VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id, matricule`;
const newPupil = async (last, given, birth = '2012-05-01') => first(STUDENT, [a, last, given, null, null, birth, pupilFields]);
const newAdult = async (last, given) => newPupil(last, given, '1995-03-14');
const PROGRAM = 'INSERT INTO school.program (tenant_id, year_id, node_id, code, labels, base_price, status) VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id';
const programme = async (code, price = 120000, status = 'active', yearId = lotYear) =>
  (await first(PROGRAM, [a, yearId, grade, code, JSON.stringify({ fr: 'Programme ' + code, ar: 'برنامج ' + code }), price, status])).id;
const GROUP = `INSERT INTO school.study_group (tenant_id, year_id, code, labels, group_type, program_id, site_id, capacity, valid_from, status)
  VALUES ($1, $2, $3, $4, $5, $6, $7, $8, '2026-01-01', 'active') RETURNING id`;
const group = async (code, program, capacity, type = 'class', site = siteA) =>
  (await first(GROUP, [a, lotYear, code, JSON.stringify({ fr: 'Groupe ' + code }), type, program, site, capacity])).id;
const ENROLL = 'SELECT school.enroll($1, $2, $3) AS id';
const enroll = async (student, program, key = uuid()) => (await first(ENROLL, [student, program, key])).id;
const JOIN_GROUP = 'INSERT INTO school.group_membership (tenant_id, enrollment_id, group_id) VALUES ($1, $2, $3) RETURNING id';
const joinGroup = async (enrollment, groupId) => (await first(JOIN_GROUP, [a, enrollment, groupId])).id;
const contact = async (student, phone = '0550 12 34 56', primary = true) => {
  const g = (await first("INSERT INTO school.guardian (tenant_id, last_name_lat, first_name_lat, phone) VALUES ($1, 'Benali', 'Karim', $2) RETURNING id",
    [a, phone])).id;
  await sql("INSERT INTO school.student_guardian (tenant_id, student_id, guardian_id, relationship, is_primary_contact) VALUES ($1, $2, $3, 'father', $4)",
    [a, student, g, primary]);
  return g;
};
const members = groupId => count('SELECT count(*) n FROM school.group_membership WHERE group_id = $1 AND valid_to IS NULL', [groupId]);
// User 1 also runs the pupils and enrolments (academic_hr, section 3.2): nobody grants themselves a role.
await sql("INSERT INTO school.role_assignment (tenant_id, user_id, role_id, scope_type) VALUES ($1, 1, $2, 'tenant')", [a, roles.academic_hr]);
await as('school_runtime', a, 1);
const thisYear = String(new Date().getFullYear());

await check('a pupil receives a matricule of the year, never changed; a name in Arabic or Latin letters is needed (FR-ELV-01 AC1)', async () => {
  const first1 = await newPupil('Benali', 'Amine');
  const second = await newPupil('Saidi', 'Lina');
  assert.match(first1.matricule, new RegExp(`^${thisYear}\\d{5}$`));
  assert.equal(Number(second.matricule), Number(first1.matricule) + 1);
  assert.equal((await first(STUDENT.replace('custom)', 'custom, matricule)').replace('$7)', '$7, $8)'),
    [a, 'Haddad', 'Yasmine', null, null, '2011-02-02', pupilFields, 'EXT-001'])).matricule, 'EXT-001');
  await rejects(STUDENT, [a, 'Benali', null, 'بن علي', null, '2012-05-01', pupilFields], '23514');
  assert.ok((await first(STUDENT, [a, null, null, 'بن علي', 'أمين', '2012-05-01', pupilFields])).matricule);
  await rejects(STUDENT, [a, 'Futur', 'Bébé', null, null, '2999-01-01', pupilFields], '23514', 'birth_date_future');
  await rejects("UPDATE school.student SET matricule = 'X' WHERE id = $1", [first1.id], '42501');
  await as(null);
  await rejects("UPDATE school.student SET matricule = 'X' WHERE id = $1", [first1.id], '23514', 'code_immutable');
  await as('school_runtime', a, 1);
});
await check('a blocked pupil says why; a former pupil\'s record stays as it is (FR-ELV-03)', async () => {
  const p = (await newPupil('Mansouri', 'Rania')).id;
  await rejects("UPDATE school.student SET status = 'blocked' WHERE id = $1", [p], '23514');
  await sql("UPDATE school.student SET status = 'blocked', status_reason = 'Dossier incomplet' WHERE id = $1", [p]);
  await sql("UPDATE school.student SET status = 'active' WHERE id = $1", [p]);
  assert.equal((await first('SELECT status_reason FROM school.student WHERE id = $1', [p])).status_reason, null);
  await sql("UPDATE school.student SET status = 'former' WHERE id = $1", [p]);
  await rejects("UPDATE school.student SET phone = '0551' WHERE id = $1", [p], '23514', 'student_former');
  await rejects("UPDATE school.student SET status = 'active' WHERE id = $1", [p], '23514', 'student_former');
  await sql('UPDATE school.student SET archived_at = now() WHERE id = $1', [p]);
  await rejects('DELETE FROM school.student WHERE id = $1', [p], '42501');
});
await check('one guardian for brothers and sisters, linked to each (FR-ELV-02 AC1)', async () => {
  const [brother, sister] = [(await newPupil('Kaci', 'Walid')).id, (await newPupil('Kaci', 'Meriem')).id];
  const parent = await contact(brother);
  await sql("INSERT INTO school.student_guardian (tenant_id, student_id, guardian_id, relationship, is_primary_contact) VALUES ($1, $2, $3, 'father', true)",
    [a, sister, parent]);
  assert.equal(await count('SELECT count(*) n FROM school.student_guardian WHERE guardian_id = $1', [parent]), 2);
  await rejects("INSERT INTO school.student_guardian (tenant_id, student_id, guardian_id, relationship) VALUES ($1, $2, $3, 'mother')",
    [a, sister, parent], '23505');
});
await check('programmes take their level from the school\'s referential, never one of another school or archived (TST-18, BR-13)', async () => {
  await as(null);
  await sql(`INSERT INTO school.node_type (tenant_id, code, labels, level_order, is_required) VALUES ($1, 'grade', '{"fr": "Niveau"}', 1, false)
    ON CONFLICT DO NOTHING`, [b]);
  const otherNode = (await first(`INSERT INTO school.ref_node (tenant_id, type_code, code, labels) VALUES ($1, 'grade', 'B_LEVEL', '{"fr": "Niveau de B"}')
    RETURNING id`, [b])).id;
  const archived = (await first("SELECT id FROM school.ref_node WHERE tenant_id = $1 AND archived_at IS NOT NULL LIMIT 1", [a])).id;
  await as('school_runtime', a, 1);
  await rejects(PROGRAM, [a, lotYear, otherNode, 'AILLEURS', JSON.stringify({ fr: 'Ailleurs' }), 0, 'draft'], '23503');
  await rejects(PROGRAM, [a, lotYear, archived, 'ARCHIVE', JSON.stringify({ fr: 'Archivé' }), 0, 'draft'], '23514', 'node_archived');
  const p = await programme('TST18');
  await rejects("UPDATE school.program SET node_id = $2 WHERE id = $1", [p, archived], '42501');
});
await check('a new price is a new version; an enrolment keeps the price it was made with (FR-PRG-01 AC1, BR-11)', async () => {
  const p = await programme('PRIX', 120000);
  const e = await enroll((await newAdult('Amrani', 'Sofiane')).id, p);
  await sql('UPDATE school.program SET base_price = 130000 WHERE id = $1', [p]);
  assert.deepEqual((await sql('SELECT version, base_price::text AS price FROM school.program_price WHERE program_id = $1 ORDER BY version', [p])).rows,
    [{ version: 1, price: '120000.00' }, { version: 2, price: '130000.00' }]);
  assert.deepEqual(await first("SELECT agreed_price::text AS price, price_version, terms_snapshot ->> 'base_price' AS terms FROM school.enrollment WHERE id = $1", [e]),
    { price: '120000.00', price_version: 1, terms: '120000.00' });
  await rejects("UPDATE school.enrollment SET agreed_price = 1 WHERE id = $1", [e], '42501');
  await sql("UPDATE school.program SET labels = '{\"fr\": \"Prix\"}' WHERE id = $1", [p]);
  assert.equal((await first('SELECT price_version FROM school.program WHERE id = $1', [p])).price_version, 2);
});
await check('no enrolment in an inactive programme, nor for a blocked pupil, nor for a minor without a primary contact\'s phone (FR-PRG-01 AC2, FR-INS-01 AC2, FR-ELV-02 AC2)', async () => {
  const closed = await programme('FERME', 1000, 'inactive');
  const open = await programme('OUVERT', 1000);
  const grown = (await newAdult('Bouzid', 'Nadia')).id;
  await rejects(ENROLL, [grown, closed, uuid()], '23514', 'program_not_active');
  const blocked = (await newAdult('Rahmani', 'Ilyes')).id;
  await sql("UPDATE school.student SET status = 'blocked', status_reason = 'Impayés' WHERE id = $1", [blocked]);
  await rejects(ENROLL, [blocked, open, uuid()], '23514', 'student_blocked');
  const minor = (await newPupil('Ziani', 'Adam')).id;
  await rejects(ENROLL, [minor, open, uuid()], '23514', 'primary_contact_required');
  await contact(minor, null);
  await rejects(ENROLL, [minor, open, uuid()], '23514', 'primary_contact_required');
  await contact(minor, '   ');
  await rejects(ENROLL, [minor, open, uuid()], '23514', 'primary_contact_required');
  await contact(minor, '0661 00 11 22', false);
  await rejects(ENROLL, [minor, open, uuid()], '23514', 'primary_contact_required');
  await contact(minor);
  assert.ok(await enroll(minor, open));
});
await check('an enrolment asked for twice with the same key is made once (TST-08, BR-05)', async () => {
  const p = await programme('DOUBLE');
  const s = (await newAdult('Cherif', 'Imane')).id;
  const key = uuid();
  const firstTime = await enroll(s, p, key);
  assert.equal(await enroll(s, p, key), firstTime);
  assert.equal(await count('SELECT count(*) n FROM school.enrollment WHERE student_id = $1 AND program_id = $2', [s, p]), 1);
  assert.equal(await count('SELECT count(*) n FROM school.enrollment_status_history WHERE enrollment_id = $1', [firstTime]), 1);
  await rejects(ENROLL, [s, await programme('AUTRE'), key], '23514', 'idempotency_key_reused');
  await rejects(ENROLL, [s, p, uuid()], '23505');
});
await check('a group never goes over its capacity, nor below its members (BR-07, FR-GRP-01 AC2)', async () => {
  const p = await programme('CAPA');
  const g = await group('CAPA-1', p, 2);
  for (const name of ['Un', 'Deux']) await joinGroup(await enroll((await newAdult('Capacite', name)).id, p), g);
  await rejects(JOIN_GROUP, [a, await enroll((await newAdult('Capacite', 'Trois')).id, p), g], '23514', 'group_full');
  await rejects('UPDATE school.study_group SET capacity = 1 WHERE id = $1', [g], '23514', 'capacity_below_members');
  await sql('UPDATE school.study_group SET capacity = 3 WHERE id = $1', [g]);
  assert.equal(await members(g), 2);
});
await check('one main group per enrolment; a transfer keeps both groups with their dates (FR-GRP-02 AC2, AC3)', async () => {
  const p = await programme('TRANSF');
  const [g1, g2] = [await group('TRANSF-1', p, 10), await group('TRANSF-2', p, 10)];
  const support = await group('SOUTIEN-1', null, 10, 'support');
  const e = await enroll((await newAdult('Transfert', 'Aya')).id, p);
  const m = await joinGroup(e, g1);
  await rejects(JOIN_GROUP, [a, e, g2], '23505');
  await joinGroup(e, support);
  const moved = (await first('SELECT school.move_to_group($1, $2) AS id', [m, g2])).id;
  assert.deepEqual((await sql(`SELECT g.code, m.is_main, m.valid_to IS NULL AS current FROM school.group_membership m
    JOIN school.study_group g ON g.id = m.group_id WHERE m.enrollment_id = $1 ORDER BY g.code`, [e])).rows,
    [{ code: 'SOUTIEN-1', is_main: false, current: true }, { code: 'TRANSF-1', is_main: true, current: false },
     { code: 'TRANSF-2', is_main: true, current: true }]);
  assert.equal((await first('SELECT valid_from::text AS d FROM school.group_membership WHERE id = $1', [moved])).d,
    (await first('SELECT valid_to::text AS d FROM school.group_membership WHERE id = $1', [m])).d);
  await rejects("UPDATE school.group_membership SET valid_to = NULL WHERE id = $1", [m], '23514', 'membership_ended');
  await rejects(JOIN_GROUP, [a, e, await group('AUTRE-PRG', await programme('AUTREPRG'), 5)], '23514', 'group_other_program');
});
await check('validation needs the main group; a stop needs a reason; a cancelled enrolment is frozen and frees its place (FR-INS-01, FR-INS-02, FR-INS-03 AC1, BR-12)', async () => {
  const p = await programme('VALID');
  const g = await group('VALID-1', p, 5);
  const e = await enroll((await newAdult('Validation', 'Omar')).id, p);
  await rejects("UPDATE school.enrollment SET status = 'validated' WHERE id = $1", [e], '23514', 'main_group_required');
  await joinGroup(e, g);
  // The reason the request gives goes to the history, a validation's too (SQL 024).
  await sql("SELECT set_config('app.reason', 'Dossier complet', false)");
  await sql("UPDATE school.enrollment SET status = 'validated' WHERE id = $1", [e]);
  await sql("SELECT set_config('app.reason', '', false)");
  assert.equal((await first('SELECT validated_by::int AS u FROM school.enrollment WHERE id = $1', [e])).u, 1);
  await rejects("UPDATE school.enrollment SET status = 'suspended' WHERE id = $1", [e], '23514', 'reason_required');
  await sql("UPDATE school.enrollment SET status = 'suspended', status_reason = 'Absence prolongée' WHERE id = $1", [e]);
  await sql("UPDATE school.enrollment SET status = 'validated' WHERE id = $1", [e]);
  assert.equal(await members(g), 1);
  await sql("UPDATE school.enrollment SET status = 'cancelled', status_reason = 'Départ de la famille' WHERE id = $1", [e]);
  assert.equal(await members(g), 0);
  await rejects("UPDATE school.enrollment SET status = 'validated' WHERE id = $1", [e], '23514', 'enrollment_cancelled');
  await rejects(JOIN_GROUP, [a, e, g], '23514', 'enrollment_not_assignable');
  assert.deepEqual((await sql('SELECT from_status, to_status FROM school.enrollment_status_history WHERE enrollment_id = $1 ORDER BY changed_at, to_status', [e])).rows
    .map(r => `${r.from_status ?? '-'}>${r.to_status}`).sort(),
    ['-' + '>pending', 'pending>validated', 'suspended>validated', 'validated>cancelled', 'validated>suspended'].sort());
  assert.deepEqual((await sql("SELECT to_status || ':' || coalesce(reason, '-') AS r FROM school.enrollment_status_history WHERE enrollment_id = $1 AND from_status IS NOT NULL",
    [e])).rows.map(r => r.r).sort(), ['cancelled:Départ de la famille', 'suspended:Absence prolongée', 'validated:-', 'validated:Dossier complet']);
  const other = await enroll((await newAdult('Validation', 'Lyna')).id, p);
  await rejects("UPDATE school.enrollment SET status = 'suspended', status_reason = 'Essai' WHERE id = $1", [other], '23514', 'status_transition_refused');
});
await check('the plan\'s quota of active pupils holds (FR-PLT-02)', async () => {
  const p = await programme('QUOTA');
  const q = await programme('QUOTA2');
  const already = (await newAdult('Quota', 'Deja')).id;
  await enroll(already, p);
  await as(null);
  const active = await count(`SELECT count(DISTINCT e.student_id) n FROM school.enrollment e JOIN school.academic_year y ON y.id = e.year_id
    WHERE e.tenant_id = $1 AND e.status <> 'cancelled' AND y.status <> 'closed'`, [a]);
  const quota = (await first('SELECT quota_active_students AS q FROM platform.tenant_entitlement WHERE tenant_id = $1', [a])).q;
  await sql('UPDATE platform.tenant_entitlement SET quota_active_students = $2 WHERE tenant_id = $1', [a, active]);
  await as('school_runtime', a, 1);
  await rejects(ENROLL, [(await newAdult('Quota', 'Nouveau')).id, p, uuid()], '23514', 'student_quota_exceeded');
  assert.ok(await enroll(already, q), 'a pupil already counted enrols again');
  await as(null);
  await sql('UPDATE platform.tenant_entitlement SET quota_active_students = $2 WHERE tenant_id = $1', [a, quota]);
  await as('school_runtime', a, 1);
});
await check('a closed year freezes its programmes, groups and enrolments (FR-PRG-01, FR-REF-05)', async () => {
  await as(null);
  const old = (await first(YEAR, [a, 'LOT2-OLD', '2020-09-01', '2021-06-30', 'planned'])).id;
  await as('school_runtime', a, 1);
  const p = await programme('ANCIEN', 1000, 'active', old);
  const g = (await first(GROUP, [a, old, 'ANCIEN-1', JSON.stringify({ fr: 'Ancien' }), 'class', p, siteA, 5])).id;
  const e = await enroll((await newAdult('Ancien', 'Eleve')).id, p);
  await as(null);
  await sql("UPDATE school.academic_year SET status = 'closed' WHERE id = $1", [old]);
  await as('school_runtime', a, 1);
  await rejects('UPDATE school.program SET base_price = 2000 WHERE id = $1', [p], '23514', 'year_closed');
  await rejects('UPDATE school.study_group SET capacity = 6 WHERE id = $1', [g], '23514', 'year_closed');
  await rejects(ENROLL, [(await newAdult('Ancien', 'Autre')).id, p, uuid()], '23514', 'year_closed');
  await rejects("UPDATE school.enrollment SET status = 'cancelled', status_reason = 'Trop tard' WHERE id = $1", [e], '23514', 'year_closed');
});
await check('writing pupils, groups and enrolments needs the permission (TST-02)', async () => {
  const p = await programme('DROITS');
  const s = (await newAdult('Droits', 'Sami')).id;
  // A teacher: neither pupils, nor groups, nor enrolments to write.
  await as('school_runtime', a, 9);
  assert.equal((await first("SELECT school.can('student.record.create') AS c")).c, false);
  await rejects(STUDENT, [a, 'Sans', 'Droit', null, null, '2010-01-01', pupilFields], '42501', 'permission_denied');
  await rejects(ENROLL, [s, p, uuid()], '42501', 'permission_denied');
  await rejects(GROUP, [a, lotYear, 'SANS-DROIT', JSON.stringify({ fr: 'x' }), 'support', null, siteA, 5], '42501', 'permission_denied');
  await as('school_runtime', a, 1);
});
await check('the journal masks pupils\' and guardians\' personal data (BR-17)', async () => {
  const p = (await newPupil('Masque', 'Ines')).id;
  await sql("UPDATE school.student SET last_name_lat = 'Masquee', phone = '0770' WHERE id = $1", [p]);
  const entry = await first("SELECT before, after FROM school.audit_log WHERE object_type = 'student' AND object_id = $1 AND action = 'UPDATE'", [p]);
  assert.deepEqual(entry, { before: { last_name_lat: '[masqué]', phone: '[masqué]' }, after: { last_name_lat: '[masqué]', phone: '[masqué]' } });
  const created = await first("SELECT changed_fields FROM school.audit_log WHERE object_type = 'student' AND object_id = $1 AND action = 'INSERT'", [p]);
  assert.ok(!created.changed_fields.includes('name_key'));
});

await check('generated matricules skip imported numbers; a full five-digit sequence fails explicitly', async () => {
  await as(null);
  const last = (await first('SELECT last FROM school.matricule_counter WHERE tenant_id = $1 AND year = $2', [a, Number(thisYear)])).last;
  await as('school_runtime', a, 1);
  const imported = thisYear + String(last + 1).padStart(5, '0');
  await first(STUDENT.replace('custom)', 'custom, matricule)').replace('$7)', '$7, $8)'),
    [a, 'Importe', 'Numero', null, null, '1990-01-01', pupilFields, imported]);
  assert.equal((await newAdult('Genere', 'Suivant')).matricule, thisYear + String(last + 2).padStart(5, '0'));
  await rejects('UPDATE school.matricule_counter SET last = 99999 WHERE tenant_id = $1', [a], '42501');
  await as(null);
  await sql('UPDATE school.matricule_counter SET last = 99999 WHERE tenant_id = $1 AND year = $2', [a, Number(thisYear)]);
  await as('school_runtime', a, 1);
  await rejects(STUDENT, [a, 'Sans', 'Numero', null, null, '1990-01-01', pupilFields], '23514', 'matricule_exhausted');
  await as(null);
  await sql('UPDATE school.matricule_counter SET last = $3 WHERE tenant_id = $1 AND year = $2', [a, Number(thisYear), last + 2]);
  await as('school_runtime', a, 1);
});

await check('a failed group transfer preserves the source assignment and its place (BR-06)', async () => {
  const p = await programme('ATOMIQUE');
  const source = await group('ATOMIQUE-A', p, 1);
  const target = await group('ATOMIQUE-B', p, 1);
  const membership = await joinGroup(await enroll((await newAdult('Transfert', 'Source')).id, p), source);
  await joinGroup(await enroll((await newAdult('Transfert', 'Occupe')).id, p), target);
  await rejects('SELECT school.move_to_group($1, $2)', [membership, target], '23514', 'group_full');
  assert.equal(await members(source), 1);
  assert.equal((await first('SELECT valid_to FROM school.group_membership WHERE id = $1', [membership])).valid_to, null);
});

// An enrolment's continuity (SQL 027): an enrolled minor's primary contact kept, a programme left for another.
const linkOf = async (student, guardian) => (await first('SELECT id FROM school.student_guardian WHERE student_id = $1 AND guardian_id = $2', [student, guardian])).id;
const UNSET_PRIMARY = 'UPDATE school.student_guardian SET is_primary_contact = false WHERE id = $1';
await check('an enrolled minor keeps a primary contact with a phone; another one is named first (FR-ELV-02)', async () => {
  await as('school_runtime', a, 1);
  const p = await programme('CONTACT');
  const minor = (await newPupil('Contact', 'Rayan')).id;
  const father = await contact(minor);
  // Not enrolled yet: the contact changes freely.
  await sql(UNSET_PRIMARY, [await linkOf(minor, father)]);
  await sql('UPDATE school.student_guardian SET is_primary_contact = true WHERE id = $1', [await linkOf(minor, father)]);
  const enrolment = await enroll(minor, p);
  await rejects(UNSET_PRIMARY, [await linkOf(minor, father)], '23514', 'primary_contact_kept');
  await rejects('UPDATE school.student_guardian SET archived_at = now() WHERE id = $1', [await linkOf(minor, father)], '23514', 'primary_contact_kept');
  await rejects('UPDATE school.guardian SET phone = NULL WHERE id = $1', [father], '23514', 'primary_contact_kept');
  await rejects("UPDATE school.guardian SET phone = '  ' WHERE id = $1", [father], '23514', 'primary_contact_kept');
  await rejects('UPDATE school.guardian SET archived_at = now() WHERE id = $1', [father], '23514', 'primary_contact_kept');
  await sql("UPDATE school.guardian SET phone = '0661 99 88 77' WHERE id = $1", [father]);
  // The mother named first, the father then steps back.
  const mother = await contact(minor, '0770 11 22 33');
  await sql(UNSET_PRIMARY, [await linkOf(minor, father)]);
  await rejects(UNSET_PRIMARY, [await linkOf(minor, mother)], '23514', 'primary_contact_kept');
  // Once the enrolment is cancelled, nothing is kept.
  await sql("UPDATE school.enrollment SET status = 'cancelled', status_reason = 'Départ de la famille' WHERE id = $1", [enrolment]);
  await sql(UNSET_PRIMARY, [await linkOf(minor, mother)]);
  // An enrolled pupil whose corrected birth date makes them a minor needs a contact.
  const grown = (await newAdult('Majeur', 'Karim')).id;
  await enroll(grown, p);
  await rejects("UPDATE school.student SET birth_date = '2013-01-01' WHERE id = $1", [grown], '23514', 'primary_contact_kept');
  await sql("UPDATE school.student SET birth_date = '1996-01-01' WHERE id = $1", [grown]);
});
const TRANSFER = 'SELECT school.transfer_enrollment($1, $2, $3, $4, $5) AS id';
const versionOf = async enrolment => (await first('SELECT version FROM school.enrollment WHERE id = $1', [enrolment])).version;
await check('a transfer cancels the enrolment it leaves and makes the one it goes to, linked, once (FR-INS-03 AC2)', async () => {
  await as('school_runtime', a, 1);
  const from = await programme('TRANSFERT-A');
  const to = await programme('TRANSFERT-B', 95000);
  const classA = await group('TRANSFERT-A1', from, 5);
  const pupil = (await newAdult('Transfert', 'Ines')).id;
  const source = await enroll(pupil, from);
  await joinGroup(source, classA);
  await rejects(TRANSFER, [source, from, uuid(), 'Même programme', await versionOf(source)], '23514', 'transfer_same_program');
  await rejects(TRANSFER, [source, to, uuid(), 'Version ancienne', 99], '23514', 'stale_version');
  const key = uuid();
  const target = (await first(TRANSFER, [source, to, key, 'Changement de filière', await versionOf(source)])).id;
  const rows = Object.fromEntries((await sql(`SELECT id, status, status_reason, transferred_from, agreed_price::text AS price
    FROM school.enrollment WHERE id IN ($1, $2)`, [source, target])).rows.map(r => [r.id, r]));
  assert.deepEqual([rows[source].status, rows[source].status_reason, rows[source].transferred_from], ['cancelled', 'Changement de filière', null]);
  assert.deepEqual([rows[target].status, rows[target].transferred_from, rows[target].price], ['pending', source, '95000.00']);
  assert.equal(await members(classA), 0, 'the class place is given back');
  assert.deepEqual((await sql('SELECT from_status, to_status, reason FROM school.enrollment_status_history WHERE enrollment_id = $1 ORDER BY changed_at, from_status NULLS FIRST', [source])).rows
    .map(r => [r.from_status, r.to_status, r.reason]), [[null, 'pending', null], ['pending', 'cancelled', 'Changement de filière']]);
  // The same request again: the same transfer, nothing more; another one from a cancelled enrolment: refused.
  assert.equal((await first(TRANSFER, [source, to, key, 'Changement de filière', 1])).id, target);
  await rejects(TRANSFER, [source, to, uuid(), 'Encore', await versionOf(source)], '23514', 'enrollment_cancelled');
  // The link is the database's: the application can neither change it nor claim it for an enrolment it did not cancel just now.
  await rejects('UPDATE school.enrollment SET transferred_from = NULL WHERE id = $1', [target], '42501');
  const other = await enroll((await newAdult('Transfert', 'Sami')).id, from);
  await sql('BEGIN');
  try {
    await sql("SELECT set_config('app.transfer_from', $1, true)", [other]);
    await rejects(ENROLL, [pupil, from, uuid()], '23514', 'transfer_invalid');
  } finally {
    await sql('ROLLBACK');
  }
});

// A programme's subjects (FR-PRG-02, SQL 028): a volume for each subject of the catalogue, every
// change a new version, a subject once in a programme at any date.
const LINE = `INSERT INTO school.program_subject (tenant_id, program_id, subject_id, total_hours, weekly_hours, session_count, session_duration_minutes,
  valid_from, valid_to) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9) RETURNING id, valid_from::text AS valid_from, session_duration_minutes, volume_version`;
const line = (program, subject, { total = null, weekly = null, sessions = null, minutes = null, from = null, to = null } = {}) =>
  first(LINE, [a, program, subject, total, weekly, sessions, minutes, from, to]);
const volumes = async id => (await sql(`SELECT version, total_hours::text AS total, session_count AS sessions FROM school.program_subject_volume
  WHERE program_subject_id = $1 ORDER BY version`, [id])).rows;
await as('school_runtime', a, 1);
const python = (await first(SUBJECT, [a, 'PYTHON', 'module', JSON.stringify({ fr: 'Python', ar: 'بايثون' })])).id;
const algo = (await first(SUBJECT, [a, 'ALGO', 'module', JSON.stringify({ fr: 'Algorithmique', ar: 'الخوارزميات' })])).id;
await check('a programme\'s subject has a volume and appears once at a time; a programme may have none (FR-PRG-02 AC2, AC4)', async () => {
  await as('school_runtime', a, 1);
  const p = await programme('MATIERES');
  // A programme without subjects (a preschool working by activities) takes its pupils.
  assert.ok(await enroll((await newAdult('Matieres', 'Aucune')).id, p));
  await rejects(LINE, [a, p, python, null, null, null, 90, null, null], '23514', 'program_subject_volume_required');
  // Its first day is the year's when the year has not begun; a session lasts the school's length.
  const made = await line(p, python, { total: 40, sessions: 20 });
  assert.deepEqual([made.valid_from, made.session_duration_minutes, made.volume_version], ['2027-09-01', 60, 1]);
  await rejects(LINE, [a, p, python, null, 2, null, null, '2028-01-01', null], '23514', 'program_subject_overlap');
  assert.ok(await line(p, algo, { weekly: 1.5 }));
  for (const [total, weekly, sessions, minutes] of [[0, null, null, null], [null, -1, null, null], [null, 169, null, null], [null, null, 0, null], [10, null, null, 10]])
    await rejects(LINE, [a, p, uuid(), total, weekly, sessions, minutes, null, null], '23514');
  await rejects(LINE, [a, p, algo, 4, null, null, null, '2027-08-31', null], '23514', 'program_subject_outside_year');
  await rejects(LINE, [a, await programme('MATIERES-2'), algo, 4, null, null, null, null, '2028-07-01'], '23514', 'program_subject_outside_year');
  const archived = (await first(SUBJECT, [a, 'ANCIENNE', 'module', JSON.stringify({ fr: 'Ancienne matière' })])).id;
  await sql('UPDATE school.subject SET archived_at = now() WHERE id = $1', [archived]);
  await rejects(LINE, [a, p, archived, 4, null, null, null, null, null], '23514', 'subject_archived');
  await as(null);
  const foreign = (await first(SUBJECT, [b, 'AILLEURS', 'module', JSON.stringify({ fr: 'Matière de B' })])).id;
  await as('school_runtime', a, 1);
  await rejects(LINE, [a, p, foreign, 4, null, null, null, null, null], '23503');
});
await check('a changed volume is a new version, the previous one kept; an ended line is final, its subject added again later (FR-PRG-02 AC1, AC3)', async () => {
  const p = await programme('PYTHON-40');
  const id = (await line(p, python, { total: 40, sessions: 20, minutes: 120 })).id;
  await sql('UPDATE school.program_subject SET total_hours = 36 WHERE id = $1', [id]);
  assert.deepEqual(await volumes(id), [{ version: 1, total: '40.00', sessions: 20 }, { version: 2, total: '36.00', sessions: 20 }]);
  // An end of validity is no new volume.
  await rejects("UPDATE school.program_subject SET valid_to = '2027-08-31' WHERE id = $1", [id], '23514', 'program_subject_end_before_start');
  await sql("UPDATE school.program_subject SET valid_to = '2028-01-31' WHERE id = $1", [id]);
  assert.equal((await first('SELECT volume_version FROM school.program_subject WHERE id = $1', [id])).volume_version, 2);
  await rejects('UPDATE school.program_subject SET total_hours = 30 WHERE id = $1', [id], '23514', 'program_subject_ended');
  await rejects('UPDATE school.program_subject SET valid_to = NULL WHERE id = $1', [id], '23514', 'program_subject_ended');
  await rejects(LINE, [a, p, python, 10, null, null, null, '2028-01-31', null], '23514', 'program_subject_overlap');
  const again = await line(p, python, { total: 10, from: '2028-02-01' });
  assert.equal(again.volume_version, 1);
  // What the database alone writes.
  await rejects('UPDATE school.program_subject SET volume_version = 9 WHERE id = $1', [again.id], '42501');
  await rejects('UPDATE school.program_subject SET subject_id = $2 WHERE id = $1', [again.id, algo], '42501');
  await rejects('UPDATE school.program_subject_volume SET total_hours = 1 WHERE program_subject_id = $1', [id], '42501');
  await rejects('DELETE FROM school.program_subject_volume WHERE program_subject_id = $1', [id], '42501');
  await as(null);
  await rejects('UPDATE school.program_subject_volume SET total_hours = 1 WHERE program_subject_id = $1', [id], '23514', 'immutable_record');
  await rejects('UPDATE school.program_subject SET subject_id = $2 WHERE id = $1', [again.id, algo], '23514', 'identity_immutable');
  await as('school_runtime', a, 1);
});
await check('a line never used is deleted with its volumes, its values kept in the journal; a subject in a programme stays (BR-02, FR-REF-03)', async () => {
  const p = await programme('RETRAIT');
  const id = (await line(p, algo, { sessions: 12 })).id;
  await sql('UPDATE school.program_subject SET session_count = 10 WHERE id = $1', [id]);
  await rejects('DELETE FROM school.subject WHERE id = $1', [algo], '23503');
  await sql('DELETE FROM school.program_subject WHERE id = $1', [id]);
  await as(null);
  assert.equal(await count('SELECT count(*) n FROM school.program_subject_volume WHERE program_subject_id = $1', [id]), 0);
  assert.deepEqual(await first(`SELECT before ->> 'session_count' AS n FROM school.audit_log
    WHERE object_type = 'program_subject' AND object_id = $1 AND action = 'DELETE'`, [id]), { n: '10' });
  await as('school_runtime', a, 1);
});
await check('a closed year freezes its programmes\' subjects; the subjects need their permission (FR-PRG-02, FR-REF-05, TST-02)', async () => {
  await as(null);
  const old = (await first(YEAR, [a, 'LOT2-MAT', '2021-09-01', '2022-06-30', 'planned'])).id;
  await as('school_runtime', a, 1);
  const p = await programme('ANCIEN-MAT', 1000, 'active', old);
  // A year already over: its last day.
  const l = await line(p, python, { total: 30 });
  assert.equal(l.valid_from, '2022-06-30');
  await as(null);
  await sql("UPDATE school.academic_year SET status = 'closed' WHERE id = $1", [old]);
  await as('school_runtime', a, 1);
  await rejects('UPDATE school.program_subject SET total_hours = 31 WHERE id = $1', [l.id], '23514', 'year_closed');
  await rejects('DELETE FROM school.program_subject WHERE id = $1', [l.id], '23514', 'year_closed');
  await rejects(LINE, [a, p, algo, 5, null, null, null, null, null], '23514', 'year_closed');
  // A teacher changes no programme.
  const open = await programme('SANS-DROIT-MAT');
  await as('school_runtime', a, 9);
  await rejects(LINE, [a, open, algo, 5, null, null, null, null, null], '42501', 'permission_denied');
  await as('school_runtime', a, 1);
});

// The reference import (FR-IMP-01, lot 2 exit; SQL 025): pupils and guardians keep the reference of
// the school's file; an import is undone while nothing depends on what it created.
const STUDENT_REF = STUDENT.replace('custom)', 'custom, external_ref)').replace('$7)', '$7, $8)');
const importBatch = async () => {
  const batch = (await first("INSERT INTO school.import_batch (tenant_id, user_id, kind, file_id) VALUES ($1, 1, 'students', $2) RETURNING id",
    [a, await file('import', 'csv', 10)])).id;
  for (const status of ['previewed', 'importing', 'imported']) await sql('UPDATE school.import_batch SET status = $2 WHERE id = $1', [batch, status]);
  return batch;
};
let importLine = 0;
// A member of the school without any role.
await as(null);
const roleless = Number((await first("INSERT INTO public.users (name, email, password) VALUES ('Sans rôle', 'sans.role@example.test', 'hash') RETURNING id")).id);
await sql("INSERT INTO school.membership (tenant_id, user_id, status) VALUES ($1, $2, 'active')", [a, roleless]);
await as('school_runtime', a, 1);
// As the import's run reports a row: valid at the preview, then created, with what it created.
const reported = async (batch, type, id) => {
  const row = (await first("INSERT INTO school.import_row (tenant_id, batch_id, line, data, outcome, action) VALUES ($1, $2, $3, '{}', 'valid', 'create') RETURNING id",
    [a, batch, ++importLine])).id;
  await sql("UPDATE school.import_row SET outcome = 'created', target_type = $2, target_id = $3, previous = $4 WHERE id = $1",
    [row, type, id, JSON.stringify({ created: { [type]: id } })]);
};

await check("a pupil's or a guardian's reference from a file is theirs alone and never changes (FR-IMP-01, BR-05)", async () => {
  const pupil = (await first(STUDENT_REF, [a, 'Reference', 'Unique', null, null, '1990-01-01', pupilFields, 'REF-1'])).id;
  await rejects(STUDENT_REF, [a, 'Reference', 'Double', null, null, '1990-01-01', pupilFields, 'REF-1'], '23505');
  const guardian = (await first("INSERT INTO school.guardian (tenant_id, last_name_lat, first_name_lat, external_ref) VALUES ($1, 'Reference', 'Parent', 'REF-1') RETURNING id", [a])).id;
  await rejects("INSERT INTO school.guardian (tenant_id, last_name_lat, first_name_lat, external_ref) VALUES ($1, 'Reference', 'Autre', 'REF-1')", [a], '23505');
  // The application cannot write it once given; the database refuses it to anyone.
  await rejects("UPDATE school.student SET external_ref = 'REF-2' WHERE id = $1", [pupil], '42501');
  await rejects('UPDATE school.guardian SET external_ref = NULL WHERE id = $1', [guardian], '42501');
  await as(null);
  await rejects("UPDATE school.student SET external_ref = 'REF-2' WHERE id = $1", [pupil], '23514', 'code_immutable');
  await rejects('UPDATE school.guardian SET external_ref = NULL WHERE id = $1', [guardian], '23514', 'code_immutable');
  await as('school_runtime', a, 1);
});

await check('undoing an import removes the pupils and guardians it created, while nothing depends on them (FR-IMP-01, BR-02)', async () => {
  const batch = await importBatch();
  const pupil = (await first(STUDENT_REF, [a, 'Importe', 'Defait', null, null, '1990-01-01', pupilFields, 'IMP-DEFAIT'])).id;
  await rejects('SELECT school.discard_student($1)', [pupil], '23514', 'dependent_operation');
  await reported(batch, 'student', pupil);
  await sql('SELECT school.discard_student($1)', [pupil]);
  assert.equal(await count('SELECT count(*) n FROM school.student WHERE id = $1', [pupil]), 0);
  // The journal keeps the removal and its reason, the person's data masked (BR-17).
  await as(null);
  assert.deepEqual(await first("SELECT reason, before ->> 'last_name_lat' AS name, before ->> 'external_ref' AS ref FROM school.audit_log WHERE object_id = $1 AND action = 'DELETE'",
    [pupil]), { reason: 'import_rollback', name: '[masqué]', ref: '[masqué]' });
  await as('school_runtime', a, 1);
  // Enrolled since: the pupil stays.
  const p = await programme('DEFAIRE');
  const enrolled = await newAdult('Importe', 'Inscrit');
  await reported(batch, 'student', enrolled.id);
  await enroll(enrolled.id, p);
  await rejects('SELECT school.discard_student($1)', [enrolled.id], '23514', 'dependent_operation');
  // A guardian goes once no pupil is linked to them; a primary contact's link, not while an
  // enrolment relies on it (FR-ELV-02 AC2).
  const minor = await newPupil('Importe', 'Mineur');
  await reported(batch, 'student', minor.id);
  const guardian = await contact(minor.id);
  const link = (await first('SELECT id FROM school.student_guardian WHERE guardian_id = $1', [guardian])).id;
  await reported(batch, 'guardian', guardian);
  await reported(batch, 'student_guardian', link);
  await rejects('SELECT school.discard_student($1)', [minor.id], '23514', 'dependent_operation');
  await rejects('SELECT school.discard_guardian($1)', [guardian], '23514', 'dependent_operation');
  const e = await enroll(minor.id, p);
  await rejects('SELECT school.discard_guardian_link($1)', [link], '23514', 'dependent_operation');
  await sql("UPDATE school.enrollment SET status = 'cancelled', status_reason = 'Erreur de saisie' WHERE id = $1", [e]);
  await sql('SELECT school.discard_guardian_link($1)', [link]);
  await sql('SELECT school.discard_guardian($1)', [guardian]);
  assert.equal(await count('SELECT count(*) n FROM school.guardian WHERE id = $1', [guardian]), 0);
  // The cancelled enrolment is the pupil's history (BR-03): the pupil stays.
  await rejects('SELECT school.discard_student($1)', [minor.id], '23514', 'dependent_operation');
  // Nobody deletes these rows otherwise; without the permission to create pupils, not even so.
  await rejects('DELETE FROM school.student WHERE id = $1', [minor.id], '42501');
  await as('school_runtime', a, roleless);
  await rejects('SELECT school.discard_student($1)', [minor.id], '42501', 'permission_denied');
  await as('school_runtime', a, 1);
});

await check('an imported enrolment is undone with its class and history, unless its state moved on since (FR-IMP-01)', async () => {
  const batch = await importBatch();
  const p = await programme('DEFAIRE-INS');
  const g = await group('DEFAIRE-A', p, 1);
  const pending = await enroll((await newAdult('Defaire', 'Attente')).id, p);
  await joinGroup(pending, g);
  await rejects('SELECT school.discard_enrollment($1)', [pending], '23514', 'dependent_operation');
  await reported(batch, 'enrollment', pending);
  await sql('SELECT school.discard_enrollment($1)', [pending]);
  assert.equal(await count('SELECT count(*) n FROM school.enrollment WHERE id = $1', [pending]), 0);
  assert.equal(await members(g), 0, 'its place is free again');
  // Validated by the import: its two states. Suspended since, even validated again: it stays.
  const validated = await enroll((await newAdult('Defaire', 'Valide')).id, p);
  await joinGroup(validated, g);
  await sql("UPDATE school.enrollment SET status = 'validated' WHERE id = $1", [validated]);
  await reported(batch, 'enrollment', validated);
  await sql("UPDATE school.enrollment SET status = 'suspended', status_reason = 'Absence prolongée' WHERE id = $1", [validated]);
  await rejects('SELECT school.discard_enrollment($1)', [validated], '23514', 'dependent_operation');
  await sql("UPDATE school.enrollment SET status = 'validated' WHERE id = $1", [validated]);
  await rejects('SELECT school.discard_enrollment($1)', [validated], '23514', 'dependent_operation');
  // An enrolment's history is never changed, and deleted by that function alone.
  await as(null);
  await rejects('DELETE FROM school.enrollment_status_history WHERE enrollment_id = $1', [validated], '23514', 'immutable_record');
  await rejects("UPDATE school.enrollment_status_history SET reason = 'Autre' WHERE enrollment_id = $1", [validated], '23514', 'immutable_record');
  await as('school_runtime', a, 1);
  await rejects('DELETE FROM school.enrollment WHERE id = $1', [validated], '42501');
  // Once its import is undone, nothing it reported goes any more.
  const later = await enroll((await newAdult('Defaire', 'Tard')).id, p);
  await reported(batch, 'enrollment', later);
  await sql("UPDATE school.import_batch SET status = 'rolled_back' WHERE id = $1", [batch]);
  await rejects('SELECT school.discard_enrollment($1)', [later], '23514', 'dependent_operation');
  await as('school_runtime', a, roleless);
  await rejects('SELECT school.discard_enrollment($1)', [later], '42501', 'permission_denied');
  await as('school_runtime', a, 1);
});

// Scoped identities are separate from the suite's administrators and other broad grants.
const scopedAccount = async (kind, scope, ids, permissions) => {
  await as(null);
  const user = (await first("INSERT INTO public.users (name, email, password) VALUES ('Scoped', $1, 'hash') RETURNING id", [uuid() + '@example.test'])).id;
  await sql("INSERT INTO school.membership (tenant_id, user_id, status) VALUES ($1, $2, 'active')", [a, user]);
  const role = (await first("INSERT INTO school.role (tenant_id, code, kind, labels) VALUES ($1, $2, $3, '{\"fr\": \"Portée de test\"}') RETURNING id",
    [a, 'scope_' + uuid().replaceAll('-', ''), kind])).id;
  for (const permission of permissions) await sql('INSERT INTO school.role_permission (tenant_id, role_id, permission_code) VALUES ($1, $2, $3)', [a, role, permission]);
  const assignment = (await first('INSERT INTO school.role_assignment (tenant_id, user_id, role_id, scope_type, scope_ids, valid_from) VALUES ($1, $2, $3, $4, $5, current_date - 2) RETURNING id',
    [a, user, role, scope, ids])).id;
  return { user, role, assignment };
};
await as('school_runtime', a, 1);
const scopeProgram = await programme('SCOPES');
const scopeSiteB = (await first("INSERT INTO school.site (tenant_id, code, labels) VALUES ($1, 'SCOPE-B', '{\"fr\": \"Site B\"}') RETURNING id", [a])).id;
const scopeGroupA = await group('SCOPES-A', scopeProgram, 10);
const scopeGroupB = await group('SCOPES-B', scopeProgram, 10, 'class', scopeSiteB);
const scopeStudentA = (await newAdult('Visible', 'Amine')).id;
const scopeStudentB = (await newAdult('Prive', 'Lina')).id;
const scopeEnrollmentA = await enroll(scopeStudentA, scopeProgram);
const scopeEnrollmentB = await enroll(scopeStudentB, scopeProgram);
await joinGroup(scopeEnrollmentA, scopeGroupA);
await joinGroup(scopeEnrollmentB, scopeGroupB);
const scopeGuardianA = await contact(scopeStudentA);
const scopeGuardianB = await contact(scopeStudentB);
// The second pupil and their guardian are recorded on site B, where they are taught (SQL 029).
await sql('UPDATE school.student SET site_id = $2 WHERE id = $1', [scopeStudentB, scopeSiteB]);
await sql('UPDATE school.guardian SET site_id = $2 WHERE id = $1', [scopeGuardianB, scopeSiteB]);
await as(null);
const coreReads = ['student.record.read', 'guardian.record.read', 'program.program.read', 'group.group.read', 'enrollment.enrollment.read', 'audit.log.read'];
const pupilIdentity = await scopedAccount('student', 'personal', null, coreReads);
const parentIdentity = await scopedAccount('parent', 'personal', null, coreReads);
const groupIdentity = await scopedAccount('internal', 'group', [scopeGroupA], [...coreReads, 'student.record.update']);
const siteIdentity = await scopedAccount('internal', 'site', [siteA], coreReads);
await sql('UPDATE school.student SET user_id = $2 WHERE id = $1', [scopeStudentA, pupilIdentity.user]);
await sql('UPDATE school.guardian SET user_id = $2 WHERE id = $1', [scopeGuardianA, parentIdentity.user]);
const visibleIds = async table => (await sql(`SELECT id FROM school.${table}`)).rows.map(r => r.id).sort();
await check('a pupil reads their own records, never a classmate\'s, including group history and the journal (TST-03)', async () => {
  await as('school_runtime', a, pupilIdentity.user);
  assert.deepEqual(await visibleIds('student'), [scopeStudentA]);
  assert.deepEqual(await visibleIds('enrollment'), [scopeEnrollmentA]);
  assert.deepEqual(await visibleIds('guardian'), [scopeGuardianA]);
  assert.deepEqual(await visibleIds('study_group'), [scopeGroupA]);
  assert.equal(await count('SELECT count(*) n FROM school.enrollment_status_history WHERE enrollment_id = $1', [scopeEnrollmentB]), 0);
  assert.equal(await count('SELECT count(*) n FROM school.group_membership WHERE enrollment_id = $1', [scopeEnrollmentB]), 0);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'student' AND object_id = $1", [scopeStudentB]), 0);
  assert.ok(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'student' AND object_id = $1", [scopeStudentA]) > 0);
  assert.equal((await sql("UPDATE school.student SET phone = 'intrusion' WHERE id = $1", [scopeStudentA])).rowCount ?? 0, 0);
});
await check('a parent reads only linked children; unlinking removes access immediately (TST-03)', async () => {
  await as('school_runtime', a, parentIdentity.user);
  assert.deepEqual(await visibleIds('student'), [scopeStudentA]);
  assert.deepEqual(await visibleIds('enrollment'), [scopeEnrollmentA]);
  await as(null);
  await sql('UPDATE school.student_guardian SET archived_at = now() WHERE student_id = $1 AND guardian_id = $2', [scopeStudentA, scopeGuardianA]);
  await as('school_runtime', a, parentIdentity.user);
  assert.deepEqual(await visibleIds('student'), []);
  await as(null);
  await sql('UPDATE school.student_guardian SET archived_at = NULL WHERE student_id = $1 AND guardian_id = $2', [scopeStudentA, scopeGuardianA]);
});
await check('a group scope filters direct reads, searches, exports and writes to that group (TST-03)', async () => {
  await as('school_runtime', a, groupIdentity.user);
  assert.deepEqual(await visibleIds('study_group'), [scopeGroupA]);
  assert.deepEqual(await visibleIds('student'), [scopeStudentA]);
  assert.deepEqual(await visibleIds('enrollment'), [scopeEnrollmentA]);
  assert.equal((await first('SELECT school.student_enrollment_count($1) AS n', [scopeStudentA])).n, 1);
  await rejects('SELECT school.student_enrollment_count($1)', [scopeStudentB], '42501', 'permission_denied');
  assert.equal(await count("SELECT count(*) n FROM school.student WHERE name_key LIKE '%prive%'"), 0);
  assert.deepEqual((await sql('SELECT matricule FROM school.student ORDER BY matricule')).rows.length, 1, 'same query used by an export');
  await sql("UPDATE school.student SET phone = '0550' WHERE id = $1", [scopeStudentA]);
  assert.equal((await sql("UPDATE school.student SET phone = 'intrusion' WHERE id = $1", [scopeStudentB])).rowCount ?? 0, 0);
  await rejects(STUDENT, [a, 'New', 'Unlinked', null, null, '1990-01-01', pupilFields], '42501');
});
await check('a site scope returns only its pupils and enrolments (FR-IAM-04 AC3)', async () => {
  await as('school_runtime', a, siteIdentity.user);
  assert.ok((await visibleIds('student')).includes(scopeStudentA));
  assert.ok(!(await visibleIds('student')).includes(scopeStudentB));
  assert.ok(!(await visibleIds('enrollment')).includes(scopeEnrollmentB));
  assert.ok(!(await visibleIds('study_group')).includes(scopeGroupB));
});
// A pupil's site (SQL 029, decision of 13 October 2026): recorded on a site from their creation.
await check('a pupil and a guardian are recorded on a site: the school\'s default one, or the writer\'s own for a site\'s grant (FR-IAM-04)', async () => {
  await as('school_runtime', a, 1);
  const school = await newAdult('Site', 'Defaut');
  assert.equal((await first('SELECT site_id FROM school.student WHERE id = $1', [school.id])).site_id, siteA);
  const desk = await scopedAccount('internal', 'site', [scopeSiteB], ['student.record.read', 'student.record.create', 'student.record.update',
    'guardian.record.read', 'guardian.record.create', 'guardian.record.update']);
  await as('school_runtime', a, desk.user);
  // Their only site, without saying it; their pupil read back at once, with a matricule of the year.
  const pupil = await newAdult('Site', 'Annexe');
  assert.match(pupil.matricule, new RegExp(`^${thisYear}\\d{5}$`));
  assert.equal((await first('SELECT site_id FROM school.student WHERE id = $1', [pupil.id])).site_id, scopeSiteB);
  await rejects(STUDENT.replace('custom)', 'custom, site_id)').replace('$7)', '$7, $8)'), [a, 'Autre', 'Site', null, null, '1990-01-01', pupilFields, siteA],
    '42501', 'permission_denied');
  const parent = (await first("INSERT INTO school.guardian (tenant_id, last_name_lat, first_name_lat, phone) VALUES ($1, 'Annexe', 'Parent', '0661 22 33 44') RETURNING id, site_id",
    [a])).id;
  await sql("INSERT INTO school.student_guardian (tenant_id, student_id, guardian_id, relationship, is_primary_contact) VALUES ($1, $2, $3, 'father', true)", [a, pupil.id, parent]);
  await rejects("INSERT INTO school.student_guardian (tenant_id, student_id, guardian_id, relationship) VALUES ($1, $2, $3, 'mother')",
    [a, scopeStudentA, parent], '42501', 'permission_denied');
  assert.ok((await visibleIds('student')).includes(pupil.id));
  assert.ok(!(await visibleIds('student')).includes(scopeStudentA));
  assert.ok(!(await visibleIds('student')).includes(school.id));
  // Moved to a site out of their grant: refused; by a grant of the whole school: done, journaled.
  await rejects('UPDATE school.student SET site_id = $2 WHERE id = $1', [pupil.id, siteA], '42501', 'permission_denied');
  await as('school_runtime', a, 1);
  await sql('UPDATE school.student SET site_id = $2 WHERE id = $1', [school.id, scopeSiteB]);
  assert.deepEqual(await first("SELECT after ->> 'site_id' AS site FROM school.audit_log WHERE object_type = 'student' AND object_id = $1 AND action = 'UPDATE'",
    [school.id]), { site: scopeSiteB });
  const closed = (await first("INSERT INTO school.site (tenant_id, code, labels) VALUES ($1, 'FERME-029', '{\"fr\": \"Site fermé\"}') RETURNING id", [a])).id;
  await sql('UPDATE school.site SET archived_at = now() WHERE id = $1', [closed]);
  await rejects(STUDENT.replace('custom)', 'custom, site_id)').replace('$7)', '$7, $8)'), [a, 'Ferme', 'Site', null, null, '1990-01-01', pupilFields, closed],
    '23514', 'site_archived');
});
await check('a site\'s grant enrols the pupils of its site, places them in its groups and opens groups there, nowhere else (FR-IAM-04)', async () => {
  await as('school_runtime', a, 1);
  const theirs = await newAdult('Inscription', 'Annexe');
  await sql('UPDATE school.student SET site_id = $2 WHERE id = $1', [theirs.id, scopeSiteB]);
  const office = await scopedAccount('internal', 'site', [scopeSiteB], ['student.record.read', 'program.program.read', 'enrollment.enrollment.read',
    'enrollment.enrollment.create', 'group.group.read', 'group.membership.manage', 'group.group.manage']);
  await as('school_runtime', a, office.user);
  const enrolment = await enroll(theirs.id, scopeProgram);
  assert.ok((await visibleIds('enrollment')).includes(enrolment));
  await rejects(ENROLL, [scopeStudentA, scopeProgram, uuid()], '42501', 'permission_denied');
  await rejects(JOIN_GROUP, [a, enrolment, scopeGroupA], '42501', 'permission_denied');
  assert.ok(await joinGroup(enrolment, scopeGroupB));
  assert.ok(await group('ANNEXE-SOUTIEN', null, 8, 'support', scopeSiteB));
  await rejects(GROUP, [a, lotYear, 'SIEGE-SOUTIEN', JSON.stringify({ fr: 'Soutien' }), 'support', null, siteA, 8], '42501', 'permission_denied');
  await as('school_runtime', a, 1);
});
await check('a role manager assigns group scopes without permission to read the group roster', async () => {
  const manager = await scopedAccount('internal', 'tenant', null, ['role.assignment.manage']);
  await as('school_runtime', a, manager.user);
  assert.deepEqual(await visibleIds('study_group'), []);
  const assignment = 'INSERT INTO school.role_assignment (tenant_id, user_id, role_id, scope_type, scope_ids) VALUES ($1, $2, $3, \'group\', $4)';
  await sql(assignment, [a, groupIdentity.user, groupIdentity.role, [scopeGroupA]]);
  await rejects(assignment, [a, groupIdentity.user, groupIdentity.role, [uuid()]], '23514', 'scope_unknown');
  await rejects(assignment, [a, groupIdentity.user, groupIdentity.role, [null]], '23514', 'scope_unknown');
  // Remove this additional grant so the expiration check still tests all of this user's grants.
  await as(null);
  await sql('UPDATE school.role_assignment SET archived_at = now() WHERE tenant_id = $1 AND user_id = $2 AND id <> $3',
    [a, groupIdentity.user, groupIdentity.assignment]);
  await sql('UPDATE school.role_assignment SET archived_at = now() WHERE id = $1', [manager.assignment]);
});
await check('a level scope includes descendants of the referential, and excludes other levels (TST-18)', async () => {
  await as(null);
  const parentNode = (await first('SELECT parent_id FROM school.ref_node WHERE id = $1', [grade])).parent_id;
  assert.ok(parentNode);
  const identity = await scopedAccount('internal', 'level', [parentNode], coreReads);
  await as('school_runtime', a, identity.user);
  assert.ok((await visibleIds('student')).includes(scopeStudentA));
  assert.ok((await visibleIds('program')).includes(scopeProgram));
  await as(null);
  await rejects('UPDATE school.role_assignment SET scope_ids = $2 WHERE id = $1', [identity.assignment, [uuid()]], '23514', 'scope_unknown');
});
await check('each action uses its own scope, even with several roles (FR-IAM-04)', async () => {
  const identity = await scopedAccount('internal', 'group', [scopeGroupA], [...coreReads, 'enrollment.enrollment.cancel']);
  await as(null);
  const otherRole = (await first("INSERT INTO school.role (tenant_id, code, kind, labels) VALUES ($1, 'scope_other_action', 'internal', '{\"fr\": \"Autre action\"}') RETURNING id", [a])).id;
  await sql("INSERT INTO school.role_permission (tenant_id, role_id, permission_code) VALUES ($1, $2, 'enrollment.enrollment.update'), ($1, $2, 'enrollment.enrollment.read')", [a, otherRole]);
  await sql("INSERT INTO school.role_assignment (tenant_id, user_id, role_id, scope_type, scope_ids) VALUES ($1, $2, $3, 'group', $4)", [a, identity.user, otherRole, [scopeGroupB]]);
  await as('school_runtime', a, identity.user);
  await rejects("UPDATE school.enrollment SET status = 'cancelled', status_reason = 'Mauvaise portée' WHERE id = $1", [scopeEnrollmentB], '42501', 'permission_denied');
});
await check('a programme\'s subjects are read with the programme, journal included, and changed within the scope of their permission (FR-PRG-02, FR-IAM-04)', async () => {
  await as('school_runtime', a, 1);
  const outside = await programme('HORS-PORTEE');
  const seen = (await line(scopeProgram, python, { total: 20 })).id;
  const hidden = (await line(outside, python, { total: 20 })).id;
  await as('school_runtime', a, siteIdentity.user);
  assert.deepEqual(await visibleIds('program_subject'), [seen]);
  assert.deepEqual((await sql('SELECT DISTINCT program_subject_id AS id FROM school.program_subject_volume')).rows.map(r => r.id), [seen]);
  assert.ok(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'program_subject' AND object_id = $1", [seen]) > 0);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'program_subject' AND object_id = $1", [hidden]), 0);
  // The subjects of the programmes taught on their site, and no other.
  const manager = await scopedAccount('internal', 'site', [siteA], ['program.program.read', 'program.subject.manage']);
  await as('school_runtime', a, manager.user);
  await sql('UPDATE school.program_subject SET weekly_hours = 2 WHERE id = $1', [seen]);
  assert.ok(await line(scopeProgram, algo, { sessions: 4 }));
  await rejects(LINE, [a, outside, algo, 5, null, null, null, null, null], '42501', 'permission_denied');
  assert.equal((await sql('UPDATE school.program_subject SET weekly_hours = 2 WHERE id = $1', [hidden])).rowCount ?? 0, 0);
  assert.equal((await sql('DELETE FROM school.program_subject WHERE id = $1', [hidden])).rowCount ?? 0, 0);
  await as('school_runtime', a, 1);
});
await check('unknown group scopes are refused; expired grants, suspended members and disabled modules expose no records', async () => {
  await as(null);
  await rejects('UPDATE school.role_assignment SET scope_ids = $2 WHERE id = $1', [groupIdentity.assignment, [uuid()]], '23514', 'scope_unknown');
  await sql("UPDATE school.role_assignment SET valid_until = current_date - 1 WHERE id = $1", [groupIdentity.assignment]);
  await as('school_runtime', a, groupIdentity.user);
  assert.deepEqual(await visibleIds('student'), []);
  await as(null);
  await sql("UPDATE school.membership SET status = 'suspended' WHERE tenant_id = $1 AND user_id = $2", [a, pupilIdentity.user]);
  await as('school_runtime', a, pupilIdentity.user);
  assert.deepEqual(await visibleIds('student'), []);
  await as(null);
  await sql("UPDATE school.membership SET status = 'active' WHERE tenant_id = $1 AND user_id = $2", [a, pupilIdentity.user]);
  await sql("SELECT platform.disable_module($1, 'student_finance')", [a]);
  await sql("SELECT platform.disable_module($1, 'enrollment_groups')", [a]);
  await sql("SELECT platform.disable_module($1, 'students_crm')", [a]);
  await as('school_runtime', a, pupilIdentity.user);
  assert.deepEqual(await visibleIds('student'), []);
  await as(null);
  await sql("SELECT platform.enable_module($1, 'student_finance')", [a]);
});
await check('a school member without a domain permission reads none of the core tables', async () => {
  const identity = await scopedAccount('internal', 'tenant', null, []);
  await as('school_runtime', a, identity.user);
  for (const table of ['student', 'guardian', 'student_guardian', 'program', 'program_price', 'program_subject', 'program_subject_volume', 'study_group',
    'enrollment', 'enrollment_status_history', 'group_membership'])
    assert.equal(await count(`SELECT count(*) n FROM school.${table}`), 0, table);
  await as('school_runtime', b, 2);
  assert.equal(await count('SELECT count(*) n FROM school.student'), 0, 'another school sees none');
  await as('school_runtime', a, 1);
});

// The pupil's photo (FR-ELV-01, SQL 026): two images made for the pupil, the previous ones
// retired as soon as they are replaced, read with the pupil only.
const PHOTO = `INSERT INTO school.file_object (tenant_id, category, storage_key, original_name, file_type, media_type, size_bytes, sha256, owner_type, owner_id)
  VALUES ($1::uuid, 'student_photo', 'tenant/' || $1::text || '/student_photo/' || gen_random_uuid(), 'photo.jpg', 'jpeg', 'image/jpeg', 1000, repeat('a', 64), 'student', $2)
  RETURNING id`;
const photoPair = async student => [(await first(PHOTO, [a, student])).id, (await first(PHOTO, [a, student])).id];
const SET_PHOTO = 'UPDATE school.student SET photo_file_id = $2, photo_thumb_file_id = $3 WHERE id = $1';
const photoFiles = ids => sql('SELECT id, archived_at IS NOT NULL AS archived, expires_at <= now() AS due, purged_at FROM school.file_object WHERE id = ANY ($1) ORDER BY id', [ids]);
await check('a pupil\'s photo is two images made for them, both or neither; any other file is refused (FR-ELV-01)', async () => {
  await as('school_runtime', a, 1);
  const pupil = (await newAdult('Photo', 'Amel')).id;
  const other = (await newAdult('Photo', 'Yanis')).id;
  const [photo, thumb] = await photoPair(pupil);
  await rejects('UPDATE school.student SET photo_file_id = $2 WHERE id = $1', [pupil, photo], '23514');
  await rejects(SET_PHOTO, [pupil, photo, photo], '23514', 'student_photo_invalid');
  await rejects(SET_PHOTO, [pupil, photo, invoice], '23514', 'student_photo_invalid');
  const [othersPhoto, othersThumb] = await photoPair(other);
  await rejects(SET_PHOTO, [pupil, othersPhoto, othersThumb], '23514', 'student_photo_invalid');
  await rejects(PHOTO.replace("'student', $2", "'guardian', $2"), [a, pupil], '42501');
  await sql(SET_PHOTO, [pupil, photo, thumb]);
  assert.deepEqual(await first('SELECT photo_file_id, photo_thumb_file_id FROM school.student WHERE id = $1', [pupil]),
    { photo_file_id: photo, photo_thumb_file_id: thumb });
});
await check('a replaced or removed photo is retired at once, due for deletion; the journal never names an image (FR-ELV-01, BR-17)', async () => {
  await as('school_runtime', a, 1);
  const pupil = (await newAdult('Photo', 'Ines')).id;
  const first_ = await photoPair(pupil);
  await sql(SET_PHOTO, [pupil, ...first_]);
  const second = await photoPair(pupil);
  await sql(SET_PHOTO, [pupil, ...second]);
  await as(null);
  assert.deepEqual((await photoFiles(first_)).rows.map(r => [r.archived, r.due, r.purged_at]), [[true, true, null], [true, true, null]]);
  assert.deepEqual((await photoFiles(second)).rows.map(r => r.archived), [false, false]);
  await as('school_runtime', null, null);
  const due = (await sql('SELECT id FROM platform.expired_files(1000)')).rows.map(r => r.id);
  assert.ok(first_.every(id => due.includes(id)) && !second.some(id => due.includes(id)));
  await as('school_runtime', a, 1);
  await rejects(SET_PHOTO, [pupil, ...first_], '23514', 'student_photo_invalid');
  await sql(SET_PHOTO, [pupil, null, null]);
  await as(null);
  assert.deepEqual((await photoFiles(second)).rows.map(r => [r.archived, r.due]), [[true, true], [true, true]]);
  const entries = (await sql("SELECT before, after FROM school.audit_log WHERE object_type = 'student' AND object_id = $1 AND action = 'UPDATE'", [pupil])).rows;
  assert.equal(entries.length, 3);
  for (const entry of entries) {
    assert.deepEqual([entry.before.photo_file_id, entry.after.photo_thumb_file_id], ['[masqué]', '[masqué]']);
    for (const id of [...first_, ...second]) assert.ok(!JSON.stringify(entry).includes(id));
  }
});
await check('a school that turns photos off keeps them hidden, takes no new one, and lets one be removed (FR-ELV-01)', async () => {
  await as('school_runtime', a, 1);
  const pupil = (await newAdult('Photo', 'Rayan')).id;
  const kept = await photoPair(pupil);
  await sql(SET_PHOTO, [pupil, ...kept]);
  await sql("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'student.photo.enabled', 'false')", [a]);
  assert.equal((await first('SELECT photo_file_id FROM school.student WHERE id = $1', [pupil])).photo_file_id, kept[0]);
  await rejects(SET_PHOTO, [pupil, ...(await photoPair(pupil))], '23514', 'student_photo_disabled');
  await sql(SET_PHOTO, [pupil, null, null]);
  await sql("UPDATE school.setting SET value = 'true' WHERE tenant_id = $1 AND key = 'student.photo.enabled'", [a]);
  await sql(SET_PHOTO, [pupil, ...(await photoPair(pupil))]);
});
await check('a photo is read and written with the pupil it shows, within the reader\'s scope, never through a link (FR-ELV-01, TST-03)', async () => {
  await as('school_runtime', a, 1);
  const photoA = await photoPair(scopeStudentA);
  await sql(SET_PHOTO, [scopeStudentA, ...photoA]);
  const photoB = await photoPair(scopeStudentB);
  await sql(SET_PHOTO, [scopeStudentB, ...photoB]);
  const both = [...photoA, ...photoB];
  for (const reader of [siteIdentity.user, pupilIdentity.user]) {
    await as('school_runtime', a, reader);
    assert.deepEqual(await visibleIds('file_object').then(ids => ids.filter(id => both.includes(id))), [...photoA].sort());
  }
  await as('school_runtime', b, 2);
  assert.equal(await count('SELECT count(*) n FROM school.file_object WHERE id = ANY ($1)', [both]), 0);
  await as('school_runtime', null, null);
  assert.equal((await sql('SELECT * FROM school.file_for_link($1, $2)', [a, photoA[0]])).rows.length, 0);
  const updater = await scopedAccount('internal', 'group', [scopeGroupB], [...coreReads, 'student.record.update']);
  await as('school_runtime', a, updater.user);
  await rejects(PHOTO, [a, scopeStudentA], '42501');
  assert.equal((await sql("UPDATE school.file_object SET archived_at = now() WHERE id = $1", [photoA[0]])).rowCount ?? 0, 0);
  await sql(SET_PHOTO, [scopeStudentB, ...(await photoPair(scopeStudentB))]);
  await as('school_runtime', a, 1);
});
await as(null);
// Restore the active-site fixture used by the earlier last-site concurrency test.
await sql('UPDATE school.site SET archived_at = now() WHERE id = $1', [scopeSiteB]);
await as('school_runtime', a, 1);

// Security review (SECURITY-REVIEW.md): what holds for every table and function, present and future
await check('every table of a school forces row-level security, even on its owner (BR-01, NFR-TEN-01)', async () => {
  await as(null);
  assert.deepEqual((await sql(`SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'school' AND c.relkind = 'r' AND NOT (c.relrowsecurity AND c.relforcerowsecurity) ORDER BY 1`)).rows, []);
});
await check('every function running with its owner\'s rights belongs to the provisioner, never to the migrations\' owner', async () => {
  await as(null);
  assert.deepEqual((await sql(`SELECT n.nspname || '.' || p.proname AS f, pg_get_userbyid(p.proowner) AS owner FROM pg_proc p
    JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname IN ('school', 'platform') AND p.prosecdef
      AND pg_get_userbyid(p.proowner) <> 'platform_provisioner' ORDER BY 1`)).rows, []);
});
await check('no function of the schemas is executable by everyone; the restricted roles own nothing and bypass nothing', async () => {
  await as(null);
  assert.deepEqual((await sql(`SELECT n.nspname || '.' || p.proname AS f FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
    WHERE n.nspname IN ('school', 'platform', 'secret') AND has_function_privilege('public', p.oid, 'EXECUTE') ORDER BY 1`)).rows, []);
  assert.deepEqual((await sql(`SELECT rolname, rolsuper, rolbypassrls, rolcreaterole, rolcreatedb FROM pg_roles
    WHERE rolname IN ('school_runtime', 'platform_admin', 'platform_auth') ORDER BY 1`)).rows.map(r => [r.rolname, r.rolsuper, r.rolbypassrls, r.rolcreaterole, r.rolcreatedb]),
    [['platform_admin', false, false, false, false], ['platform_auth', false, false, false, false], ['school_runtime', false, false, false, false]]);
  assert.equal(await count(`SELECT count(*) n FROM pg_class c JOIN pg_namespace s ON s.oid = c.relnamespace
    WHERE pg_get_userbyid(c.relowner) IN ('school_runtime', 'platform_admin', 'platform_auth')`), 0);
  assert.equal(await count(`SELECT count(*) n FROM pg_proc p WHERE pg_get_userbyid(p.proowner) IN ('school_runtime', 'platform_admin', 'platform_auth')`), 0);
});
await check("a school's application reads no password, MFA secret or recovery code, and changes none (F2, F3)", async () => {
  await as('school_runtime', a, 1);
  for (const column of ['password', 'mfa_secret', 'mfa_last_step', 'remember_token'])
    await rejects(`SELECT ${column} FROM public.users WHERE id = 1`, [], '42501');
  await rejects('SELECT * FROM public.users WHERE id = 1', [], '42501');
  await rejects('SELECT * FROM public.mfa_recovery_code', [], '42501');
  await rejects("UPDATE public.users SET password = 'pirate' WHERE id = 3", [], '42501');
  await rejects('UPDATE public.users SET mfa_secret = NULL, mfa_enabled_at = NULL WHERE id = 1', [], '42501');
  await rejects('DELETE FROM public.mfa_recovery_code WHERE user_id = 1', [], '42501');
  // What it needs: who the person is, whether they have chosen a password, their language.
  assert.deepEqual(await first('SELECT name, has_password FROM public.users WHERE id = 4'), { name: 'Person 4', has_password: true });
});
await check("a school's application reads the person, and in its school its people and the publisher's operators; nobody else (F2)", async () => {
  const seen = async () => (await sql('SELECT id::int AS id FROM public.users ORDER BY id')).rows.map(r => r.id);
  await as(null);
  const school = (await sql(`SELECT id::int AS id FROM public.users WHERE id IN (SELECT user_id FROM school.membership WHERE tenant_id = $1)
    OR id IN (SELECT user_id FROM platform.operator) ORDER BY id`, [a])).rows.map(r => r.id);
  const elsewhere = (await sql(`SELECT id::int AS id FROM public.users WHERE id IN (SELECT user_id FROM school.membership WHERE tenant_id = $1)
    AND NOT id = ANY ($2::bigint[])`, [b, school])).rows.map(r => r.id);
  assert.ok(school.includes(5) && school.includes(6) && elsewhere.length > 0, 'a suspended member and an operator in, people of B only out');
  await as('school_runtime', a, 1);
  assert.deepEqual(await seen(), school);
  assert.deepEqual((await sql('SELECT user_id::int AS id FROM school.people ORDER BY 1')).rows.map(r => r.id), school);
  // Before a school, the person alone; without a context, nobody.
  await as('school_runtime', null, 3);
  assert.deepEqual(await seen(), [3]);
  assert.equal(await count('SELECT count(*) n FROM school.people'), 0);
  await as('school_runtime', null, null);
  assert.deepEqual(await seen(), []);
  // A person changes their own language, nobody else's.
  await as('school_runtime', a, 1);
  assert.equal((await sql("UPDATE public.users SET locale = 'ar' WHERE id = 4 RETURNING id")).rows.length, 0);
  assert.equal((await sql("UPDATE public.users SET locale = locale WHERE id = 1 RETURNING id")).rows.length, 1);
});
await check("the sign-in's role reads and changes the credentials, and nothing else", async () => {
  await as('platform_auth', null, null);
  assert.equal((await first('SELECT password FROM public.users WHERE id = 3')).password, 'hash');
  assert.equal((await sql('UPDATE public.users SET mfa_last_step = 7 WHERE id = 3 AND (mfa_last_step IS NULL OR mfa_last_step < 7) RETURNING id')).rows.length, 1);
  assert.equal(await count('SELECT count(*) n FROM public.mfa_recovery_code WHERE user_id = 3'), 0);
  await rejects('SELECT name FROM public.users WHERE id = 3', [], '42501');
  await rejects("UPDATE public.users SET email = 'pirate@example.test' WHERE id = 3", [], '42501');
  await rejects('SELECT * FROM school.membership', [], '42501');
  await rejects('SELECT * FROM platform.tenant', [], '42501');
  await rejects("SELECT platform.ensure_user('intrus@example.test', 'Intrus')", [], '42501');
  await as(null);
  await sql('UPDATE public.users SET mfa_last_step = NULL WHERE id = 3');
});
await check('the request context is believed only signed with the key, on its connection, since its transaction began (F7)', async () => {
  await as('school_runtime', a, 1);
  const seen = await sites();
  assert.ok(seen > 0);
  const refused = async token => {
    await sql("SELECT set_config('app.context', $1, false)", [token]);
    await rejects('SELECT count(*) FROM school.site', [], '42501', 'context_invalid');
    await rejects('SELECT school.actor()', [], '42501', 'context_invalid');
  };
  const claims = { tenant: a, user: 1, support: null, request: null, ip: null, pid: backend, at: Date.now() * 1000 };
  await refused(sign(claims, randomBytes(32)));
  const valid = sign(claims);
  await refused(valid.replace(a, b));
  await refused(valid.replace('"user":1', '"user":4'));
  await refused(valid.slice(0, -1) + (valid.endsWith('0') ? '1' : '0'));
  await refused(JSON.stringify(claims));
  await refused(sign({ ...claims, pid: backend + 1 }));
  await refused(sign({ ...claims, at: (Date.now() - 60e3) * 1000 }));
  // Signed during the transaction, it holds until the transaction ends.
  await sql('BEGIN');
  await sql("SELECT set_config('app.context', $1, true)", [sign({ ...claims, at: Date.now() * 1000 })]);
  assert.equal(await sites(), seen);
  await sql('COMMIT');
  // The settings of old, unsigned, open nothing.
  await as('school_runtime', null, null);
  await sql("SELECT set_config('app.tenant_id', $1, false), set_config('app.user_id', '1', false)", [a]);
  assert.equal(await sites(), 0);
  assert.equal((await first('SELECT school.actor() AS u')).u, null);
});
await check('no role of the application reads or sets the context\'s key (F7)', async () => {
  for (const [role, tenant, user] of [['school_runtime', a, 1], ['platform_admin', null, 6], ['platform_auth', null, null]]) {
    await as(role, tenant, user);
    await rejects('SELECT * FROM secret.context_key', [], '42501');
    await rejects("CALL secret.set_context_key(decode(repeat('ab', 32), 'hex'))", [], '42501');
  }
});
await check('every policy reads the context once per query, never once per row (F7, NFR-PERF-03)', async () => {
  await as(null);
  const policies = (await sql(`SELECT schemaname || '.' || tablename || ' ' || policyname AS p, concat_ws(' ', qual, with_check) AS e
    FROM pg_policies WHERE schemaname IN ('school', 'platform')`)).rows;
  assert.ok(policies.length > 20);
  assert.deepEqual(policies.filter(r => /(?<!SELECT )school\.(context|tenant|actor|allowed|allowed_tenant)\(/.test(r.e)).map(r => r.p), []);
});
await check('the start of a request and a page check the signature a few times, not once per row or permission (F7)', async () => {
  await as(null);
  await sql("SET track_functions = 'all'");
  await as('school_runtime', a, 1);
  await sql('BEGIN');
  // As SchoolContext opens a request, then a page of the subjects.
  await sql('SELECT platform.tenant_mode($1::uuid) AS mode, school.allowed($1::uuid) AS allowed, platform.locale_for($2, $1::uuid) AS locale', [a, 1]);
  await sql("SELECT school.mfa_required() AS mfa, (school.setting_value('session.idle_minutes'))::int AS idle");
  await sql('SELECT id FROM school.subject ORDER BY code LIMIT 25');
  const checks = Number((await first("SELECT coalesce(sum(calls), 0) AS n FROM pg_stat_xact_user_functions WHERE schemaname = 'school' AND funcname = 'context'")).n);
  await sql('COMMIT');
  await as(null);
  await sql('RESET track_functions');
  assert.ok(checks > 0 && checks <= 20, `${checks} checks`);
});
await check('a school\'s application cannot call the publisher\'s functions: no school created, no account made, no school archived', async () => {
  await as('school_runtime', a, 1);
  await rejects(CREATE, [uuid(), 'intruse', 'Intruse', 'fr', 'essential', today, null, 1, 1, 'MAIN', null, null, null], '42501');
  await rejects("SELECT platform.ensure_user('intrus@example.test', 'Intrus')", [], '42501');
  await rejects("SELECT platform.set_tenant_status($1, 'archived', 'Intrusion')", [a], '42501');
  await rejects('SELECT * FROM platform.supervision()', [], '42501');
});

// Languages (FR-I18-01, NFR-I18N-01)
await as('school_runtime', a, 1);
const locale = async (user, tenant) => (await first('SELECT platform.locale_for($1, $2) AS l', [user, tenant])).l;
await check('a person\'s language comes first, if the school offers it; then the school\'s, then French (FR-I18-01)', async () => {
  assert.equal(await locale(1, a), 'fr');
  await sql("UPDATE public.users SET locale = 'ar' WHERE id = 1");
  assert.equal(await locale(1, a), 'ar');
  await sql("UPDATE public.users SET locale = 'en' WHERE id = 1");
  assert.equal(await locale(1, a), 'fr', 'school A offers French and Arabic only');
  assert.equal(await locale(1, null), 'en', 'outside any school, the person\'s own');
  assert.equal(await locale(3, null), 'fr');
  await sql("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'locale.languages', '[\"fr\", \"ar\", \"en\"]')", [a]);
  assert.equal(await locale(1, a), 'en');
  await sql("UPDATE school.setting SET value = '[\"fr\", \"ar\"]' WHERE tenant_id = $1 AND key = 'locale.languages'", [a]);
  await sql('UPDATE public.users SET locale = NULL WHERE id = 1');
});
await check('every catalog of the platform is labelled in French and Arabic, and so are the roles schools copied (BR-18)', async () => {
  await as(null);
  for (const table of ['module', 'plan', 'permission', 'role_template', 'setting_definition', 'notification_event', 'file_category', 'profile']) {
    assert.equal(await count(`SELECT count(*) n FROM platform.${table} WHERE NOT (labels ? 'fr' AND labels ? 'ar')`), 0, table);
  }
  assert.equal(await count("SELECT count(*) n FROM school.role WHERE template_code IS NOT NULL AND NOT labels ? 'ar'"), 0);
  await as('school_runtime', a, 1);
});
await check('a language is French, Arabic or English',() => rejects("UPDATE public.users SET locale = 'de' WHERE id = 1", [], '23514'));
await check('Arabic-Indic digits are a school\'s choice, off by default (FR-I18-01)', async () => {
  assert.equal((await first("SELECT school.setting_value('locale.arabic_digits') AS v")).v, false);
  await rejects("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'locale.arabic_digits', '\"oui\"')", [a], '23514', 'setting_invalid');
  await sql("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'locale.arabic_digits', 'true')", [a]);
  assert.equal((await first("SELECT school.setting_value('locale.arabic_digits') AS v")).v, true);
});
await check('a search finds a name however its letters are written (FR-I18-01 AC2)', async () => {
  const key = async text => (await first('SELECT school.search_key($1) AS k', [text])).k;
  for (const [written, searched] of [['أحمد', 'احمد'], ['إيمان', 'ايمان'], ['آمنة', 'امنه'], ['مُصْطَفَى', 'مصطفي'], ['عـــلي', 'علي'],
                                     ['Hélène  BEN-ALI', 'helene ben ali'], ['Zoé L’Hôte', 'zoe l hote'], ['قسم ٣', 'قسم 3']]) {
    assert.equal(await key(written), await key(searched), written);
  }
  assert.equal(await key('Hélène  BEN-ALI'), 'helene ben ali');
  assert.equal(await key(' ـ '), null);
});
await check('labels differing by an Arabic-Indic digit are two labels', async () => {
  await sql(SUBJECT, [a, 'GROUPE_1', 'subject', JSON.stringify({ ar: 'فوج ١' })]);
  await sql(SUBJECT, [a, 'GROUPE_2', 'subject', JSON.stringify({ ar: 'فوج ٢' })]);
  await rejects(SUBJECT, [a, 'GROUPE_1B', 'subject', JSON.stringify({ ar: 'فوج 1' })], '23505');
});
await check('an email goes out in its recipient\'s language (FR-I18-01)', async () => {
  // Each person chooses their own language.
  await as('school_runtime', a, 9);
  await sql("UPDATE public.users SET locale = 'ar' WHERE id = 9");
  await as('school_runtime', a, 1);
  await sql("SELECT school.notify('access.sensitive_permission', ARRAY[9]::bigint[])");
  await as('school_runtime', null, null);
  const claimed = (await sql('SELECT id, email, locale FROM platform.claim_outbox(50, 60)')).rows.find(r => r.email === 'user9@example.test');
  assert.equal(claimed.locale, 'ar');
  await sql('SELECT platform.finish_outbox($1, NULL)', [claimed.id]);
  await as('school_runtime', a, 9);
  await sql('UPDATE public.users SET locale = NULL WHERE id = 9');
  await as('school_runtime', a, 1);
});
await check('a new school\'s site is named in French and Arabic, or in its language when named (BR-18)', async () => {
  await as('platform_admin', null, 6);
  const unnamed = (await first(CREATE, [uuid(), 'ecole-ar', 'مدرسة النور', 'ar', 'essential', today, null, 10, 10, 'MAIN', null, null, null])).id;
  const named = (await first(CREATE, [uuid(), 'ecole-ar-2', 'مدرسة الأمل', 'ar', 'essential', today, null, 10, 10, 'MAIN', 'المقر', null, null])).id;
  await as(null);
  assert.deepEqual((await first('SELECT labels FROM school.site WHERE tenant_id = $1', [unnamed])).labels, { fr: 'Site principal', ar: 'الموقع الرئيسي' });
  assert.deepEqual((await first('SELECT labels FROM school.site WHERE tenant_id = $1', [named])).labels, { ar: 'المقر' });
});

// School modes (FR-PLT-01, FR-PLT-02 AC3)
await as('platform_admin', null, 6);
await check('a suspension needs a reason', () => rejects("SELECT platform.set_tenant_status($1, 'suspended', '')", [b], '23514', 'reason_required'));
await check('a suspended school is closed to its members (FR-PLT-01 AC2)', async () => {
  await sql("SELECT platform.set_tenant_status($1, 'suspended', 'Abonnement impayé')", [b]);
  await as('school_runtime', b, 2);
  assert.equal(await sites(), 0);
  assert.equal((await first("SELECT school.module_enabled('core') AS e")).e, false);
  assert.equal(await count('SELECT count(*) n FROM school.my_schools()'), 0);
  await as('platform_admin', null, 6);
  await sql("SELECT platform.set_tenant_status($1, 'active', 'Abonnement régularisé')", [b]);
  await as('school_runtime', b, 2);
  assert.equal(await sites(), 1);
});
await check('suspension and reactivation appear in the school journal with their reasons', async () =>
  assert.deepEqual((await sql("SELECT reason FROM school.audit_log WHERE object_type = 'tenant' AND action = 'UPDATE' AND changed_fields ? 'status' ORDER BY occurred_at, reason")).rows.map(r => r.reason).sort(),
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
    const pid = (await c.query('SELECT pg_backend_pid() AS pid')).rows[0].pid;
    await c.query("SELECT set_config('app.context', $1, false)", [tenant == null && user == null ? '' : context(tenant, user, { pid })]);
    return c;
  };
  await check('concurrent creations with the same key create one school', async () => {
    const key = uuid();
    const clients = await Promise.all([0, 1].map(() => connect('platform_admin', null, 6)));
    const ids = await Promise.all(clients.map(c =>
      c.query(CREATE, [key, 'race-school', 'Race', 'fr', 'essential', today, null, 10, 10, 'MAIN', 'Site', null, null]).then(r => r.rows[0].id)));
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
    // The default site back in use: the pupils recorded next go there (SQL 029).
    await as(null);
    await sql('UPDATE school.site SET archived_at = NULL WHERE id = $1', [siteA]);
  });
  await check('a link used twice at once opens the account once (FR-IAM-02 AC3)', async () => {
    await as('school_runtime', a, 1);
    const twice = await first(CREATE_ACCOUNT, ['Course', 'course@example.test', null, null, null]);
    const link = tokenHash();
    await sql('SELECT school.issue_activation($1, $2)', [twice.id, link]);
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', null, null)));
    const results = await Promise.allSettled(clients.map(async (c, i) => {
      await c.query('BEGIN');
      try {
        await c.query(REDEEM, ['activation', link, 'hash-' + i]);
        await c.query('SELECT pg_sleep(0.15)');
        await c.query('COMMIT');
      } catch (e) { await c.query('ROLLBACK'); throw e; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(results.find(r => r.status === 'rejected').reason.message, 'token_invalid');
  });
  await check('two workers claiming emails at once never take the same one (ARC-05)', async () => {
    await as(null);
    await sql("UPDATE school.outbox SET status = 'pending', next_attempt_at = now(), locked_until = NULL WHERE status <> 'failed'");
    const due = await count("SELECT count(*) n FROM school.outbox WHERE status = 'pending'");
    assert.ok(due >= 2);
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', null, null)));
    const batches = await Promise.all(clients.map(c => c.query('SELECT id FROM platform.claim_outbox(50, 60)').then(r => r.rows.map(row => row.id))));
    await Promise.all(clients.map(c => c.end()));
    const ids = batches.flat();
    assert.equal(new Set(ids).size, ids.length);
    assert.equal(ids.length, due);
  });
  await check('thirty requests for the twenty-five places of a group make twenty-five assignments (TST-07, BR-07, FR-GRP-02 AC1)', async () => {
    await as('school_runtime', a, 1);
    const p = await programme('TST07');
    const g = await group('TST07-1', p, 25);
    const enrolments = [];
    for (let i = 0; i < 30; i++) enrolments.push(await enroll((await newAdult('Course', 'Eleve' + i)).id, p));
    const clients = await Promise.all(enrolments.map(() => connect('school_runtime', a, 1)));
    const results = await Promise.allSettled(clients.map((c, i) => c.query(JOIN_GROUP, [a, enrolments[i], g])));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 25);
    assert.deepEqual([...new Set(results.filter(r => r.status === 'rejected').map(r => r.reason.message))], ['group_full']);
    assert.equal(await members(g), 25);
  });
  await check('an enrolment submitted twice at the same moment is made once (TST-08, BR-05)', async () => {
    await as('school_runtime', a, 1);
    const p = await programme('TST08');
    const s = (await newAdult('Double', 'Clic')).id;
    const key = uuid();
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
    const ids = await Promise.all(clients.map(c => c.query(ENROLL, [s, p, key]).then(r => r.rows[0].id)));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(ids[0], ids[1]);
    assert.equal(await count('SELECT count(*) n FROM school.enrollment WHERE student_id = $1', [s]), 1);
  });
  await check('pupils created at the same moment receive different matricules (FR-ELV-01 AC1)', async () => {
    const clients = await Promise.all(Array.from({ length: 10 }, () => connect('school_runtime', a, 1)));
    const made = await Promise.all(clients.map((c, i) => c.query(STUDENT, [a, 'Simultane', 'Eleve' + i, null, null, '1990-01-01', pupilFields])
      .then(r => r.rows[0].matricule)));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(new Set(made).size, 10);
  });
  await check('two enrolments cannot both take the last active-pupil quota place (FR-PLT-02)', async () => {
    await as('school_runtime', a, 1);
    const p = await programme('QUOTA-RACE');
    const pupils = [(await newAdult('QuotaRace', 'Un')).id, (await newAdult('QuotaRace', 'Deux')).id];
    await as(null);
    const quota = (await first('SELECT quota_active_students AS q FROM platform.tenant_entitlement WHERE tenant_id = $1', [a])).q;
    const active = await count(`SELECT count(DISTINCT e.student_id) n FROM school.enrollment e JOIN school.academic_year y ON y.id = e.year_id
      WHERE e.tenant_id = $1 AND e.status <> 'cancelled' AND y.status <> 'closed'`, [a]);
    await sql('UPDATE platform.tenant_entitlement SET quota_active_students = $2 WHERE tenant_id = $1', [a, active + 1]);
    const clients = await Promise.all(pupils.map(() => connect('school_runtime', a, 1)));
    try {
      const results = await Promise.allSettled(clients.map((c, i) => c.query(ENROLL, [pupils[i], p, uuid()])));
      assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
      assert.equal(results.find(r => r.status === 'rejected').reason.message, 'student_quota_exceeded');
    } finally {
      await Promise.all(clients.map(c => c.end()));
      await sql('UPDATE platform.tenant_entitlement SET quota_active_students = $2 WHERE tenant_id = $1', [a, quota]);
    }
  });
  await check('two primary contacts unset at once leave one of them to an enrolled minor (FR-ELV-02)', async () => {
    await as('school_runtime', a, 1);
    const p = await programme('CONTACT-RACE');
    const minor = (await newPupil('Course', 'Contact')).id;
    const guardians = [await contact(minor), await contact(minor, '0770 00 00 00')];
    await enroll(minor, p);
    const links = await Promise.all(guardians.map(g => linkOf(minor, g)));
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
    const results = await Promise.allSettled(clients.map(async (c, i) => {
      await c.query('BEGIN');
      try {
        await c.query(UNSET_PRIMARY, [links[i]]);
        await c.query('SELECT pg_sleep(0.15)');
        await c.query('COMMIT');
      } catch (e) { await c.query('ROLLBACK'); throw e; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(results.find(r => r.status === 'rejected').reason.message, 'primary_contact_kept');
    assert.equal(await count('SELECT count(*) n FROM school.student_guardian WHERE student_id = $1 AND is_primary_contact', [minor]), 1);
  });
  await check('a transfer asked for twice at the same moment is made once (FR-INS-03, TST-08)', async () => {
    await as('school_runtime', a, 1);
    const [from, to] = [await programme('TRANSFERT-COURSE-A'), await programme('TRANSFERT-COURSE-B')];
    const source = await enroll((await newAdult('Course', 'Transfert')).id, from);
    const key = uuid();
    const version = await versionOf(source);
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
    const ids = await Promise.all(clients.map(c => c.query(TRANSFER, [source, to, key, 'Changement de filière', version]).then(r => r.rows[0].id)));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(ids[0], ids[1]);
    assert.equal(await count('SELECT count(*) n FROM school.enrollment WHERE transferred_from = $1', [source]), 1);
  });
  await check('a subject added twice to a programme at the same moment makes one line (FR-PRG-02)', async () => {
    await as('school_runtime', a, 1);
    const p = await programme('MATIERE-COURSE');
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
    const results = await Promise.allSettled(clients.map(async (c, i) => {
      await c.query('BEGIN');
      try {
        await c.query(LINE, [a, p, python, 30 + i, null, null, null, null, null]);
        await c.query('SELECT pg_sleep(0.15)');
        await c.query('COMMIT');
      } catch (e) { await c.query('ROLLBACK'); throw e; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(results.find(r => r.status === 'rejected').reason.message, 'program_subject_overlap');
    assert.equal(await count('SELECT count(*) n FROM school.program_subject WHERE program_id = $1', [p]), 1);
  });
  await check('a concurrent cancellation prevents an assignment from leaving a place occupied (FR-INS-03)', async () => {
    await as('school_runtime', a, 1);
    const p = await programme('CANCEL-RACE');
    const g = await group('CANCEL-RACE', p, 1);
    const e = await enroll((await newAdult('Annule', 'Course')).id, p);
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
    try {
      await clients[0].query('BEGIN');
      await clients[0].query("UPDATE school.enrollment SET status = 'cancelled', status_reason = 'Annulation simultanée' WHERE id = $1", [e]);
      const joining = clients[1].query(JOIN_GROUP, [a, e, g]).then(() => null, error => error.message);
      await clients[0].query('COMMIT');
      assert.equal(await joining, 'enrollment_not_assignable');
      assert.equal(await members(g), 0);
    } finally {
      await Promise.all(clients.map(c => c.end()));
    }
  });
  await check('two uploads at once never pass the storage quota together (FR-PLT-02)', async () => {
    await as(null);
    const use = await first('SELECT used::text AS used, quota::text AS quota FROM school.storage_use($1)', [a]);
    const size = Math.floor((Number(use.quota) - Number(use.used)) * 0.6);
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 7)));
    const results = await Promise.allSettled(clients.map(async (c, i) => {
      await c.query('BEGIN');
      try {
        await c.query(FILE, [a, 'export', `part-${i}.csv`, 'csv', size]);
        await c.query('SELECT pg_sleep(0.15)');
        await c.query('COMMIT');
      } catch (e) { await c.query('ROLLBACK'); throw e; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(results.find(r => r.status === 'rejected').reason.message, 'storage_quota_exceeded');
  });
  await check('two administrators ending each other\'s role at once leave one of them (FR-IAM-01 AC2)', async () => {
    const clients = await Promise.all([1, 8].map(user => connect('school_runtime', a, user)));
    const results = await Promise.allSettled([8, 1].map(async (other, i) => {
      await clients[i].query('BEGIN');
      try {
        await clients[i].query('UPDATE school.role_assignment SET archived_at = now() WHERE user_id = $1 AND role_id = $2 AND archived_at IS NULL',
          [other, roles.tenant_admin]);
        await clients[i].query('SELECT pg_sleep(0.15)');
        await clients[i].query('COMMIT');
      } catch (e) { await clients[i].query('ROLLBACK'); throw e; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(results.find(r => r.status === 'rejected').reason.message, 'last_admin');
  });
}
console.log(`${passed} checks passed. Engine: ${PgClient ? 'native PostgreSQL with concurrency checks' : 'embedded PostgreSQL; concurrency not measured'}.`);
await db.close();
