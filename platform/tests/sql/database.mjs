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
// The database's days, in its own time zone: the embedded engine takes the computer's, and near
// midnight that day is not UTC's.
const { today, yesterday, tomorrow, lastYear } = await first("SELECT current_date::text AS today, (current_date - 1)::text AS yesterday, (current_date + 1)::text AS tomorrow, (current_date - 365)::text AS \"lastYear\"");
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
const thisYear = today.slice(0, 4);

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

// Retention per category (SQL 030, decision of 13 October 2026; NFR-DATA-03, FR-ELV-03, BR-17).
const RETAIN = 'SELECT archived, anonymised, guardians, journal, kept FROM platform.apply_retention(100) WHERE tenant_id = $1';
// Dates in the past, as years of school life would leave them: written around the rules, by the owner.
const backdate = async (table, id, values) => {
  await as(null);
  await sql(`ALTER TABLE school.${table} DISABLE TRIGGER USER`);
  for (const [column, value] of Object.entries(values)) await sql(`UPDATE school.${table} SET ${column} = $2 WHERE id = $1`, [id, value]);
  await sql(`ALTER TABLE school.${table} ENABLE TRIGGER USER`);
};
await check('a pupil who leaves has a departure day; neither the application nor the owner passes for the retention run (FR-ELV-03, NFR-DATA-03)', async () => {
  await as('school_runtime', a, 1);
  const p = (await newAdult('Depart', 'Date')).id;
  await sql("UPDATE school.student SET status = 'former' WHERE id = $1", [p]);
  assert.equal((await first('SELECT left_on::text AS d FROM school.student WHERE id = $1', [p])).d, today);
  await rejects('UPDATE school.student SET left_on = NULL WHERE id = $1', [p], '42501');
  await rejects('UPDATE school.student SET anonymised_at = now() WHERE id = $1', [p], '42501');
  await sql("SELECT set_config('app.retention', 'on', false)");
  await rejects("UPDATE school.student SET phone = '0550' WHERE id = $1", [p], '23514', 'student_former');
  await rejects('DELETE FROM school.audit_log WHERE object_id = $1', [p], '42501');
  await as(null);
  await sql("SELECT set_config('app.retention', 'on', false)");
  await rejects('DELETE FROM school.audit_log WHERE object_id = $1', [p], '23514', 'immutable_record');
  await sql("SELECT set_config('app.retention', '', false)");
  // Each school sets its durations within the catalogue's bounds: never a journal kept less than five years.
  await as('school_runtime', a, 1);
  await rejects("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'retention.journal_years', '2')", [a], '23514', 'setting_invalid');
  await rejects("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'retention.finance_years', '5')", [a], '23514', 'setting_invalid');
});
await check('the run archives who left, anonymises a pupil after their category\'s years and a guardian after their last pupil, and purges the old journal (NFR-DATA-03)', async () => {
  await as('school_runtime', a, 1);
  const p = await programme('RETENTION');
  const adult = (await newAdult('Retenu', 'Adulte')).id;
  const minor = (await newPupil('Retenu', 'Mineur', '2015-06-20')).id;
  const sibling = (await newPupil('Retenu', 'Frere', '2014-03-02')).id;
  const father = await contact(minor, '0550 99 88 77');
  await sql("INSERT INTO school.student_guardian (tenant_id, student_id, guardian_id, relationship, is_primary_contact) VALUES ($1, $2, $3, 'father', true)",
    [a, sibling, father]);
  const adultEnrolment = await enroll(adult, p);
  const minorEnrolment = await enroll(minor, p);
  for (const id of [adult, minor]) await sql("UPDATE school.student SET status = 'former' WHERE id = $1", [id]);
  // FR-ELV-03: archived twelve months after leaving, by default.
  assert.equal((await first(RETAIN, [a])), undefined, 'nothing due on the day they leave');
  for (const id of [adult, minor]) await backdate('student', id, { left_on: '2020-06-30' });
  assert.deepEqual(await first(RETAIN, [a]), { archived: 2, anonymised: 0, guardians: 0, journal: 0, kept: 0 });
  // Archived four years ago: the minor's three years are over, the adult's ten are not.
  await as('school_runtime', a, 1);
  await sql("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'retention.minor_years', '3')", [a]);
  assert.deepEqual((await sql('SELECT category, due FROM school.retention_due() ORDER BY 1')).rows,
    [{ category: 'archive', due: 0 }, { category: 'guardian', due: 0 }, { category: 'journal', due: 0 }, { category: 'lead', due: 0 },
     { category: 'pupil', due: 0 }, { category: 'staff', due: 0 }]);
  for (const id of [adult, minor]) await backdate('student', id, { archived_at: new Date(Date.now() - 4 * 365.25 * 864e5).toISOString() });
  await as('school_runtime', a, 1);
  assert.equal((await first("SELECT due FROM school.retention_due() WHERE category = 'pupil'")).due, 1);
  await as('school_runtime', a, 9);
  await rejects('SELECT * FROM school.retention_due()', [], '42501', 'permission_denied');
  await as(null);
  assert.deepEqual(await first(RETAIN, [a]), { archived: 0, anonymised: 1, guardians: 0, journal: 0, kept: 0 });
  const anonymous = await first(`SELECT last_name_lat, first_name_lat = matricule AS by_matricule, last_name_ar, birth_date::text AS born, phone, custom,
    external_ref, site_id IS NOT NULL AS site, anonymised_at IS NOT NULL AS done FROM school.student WHERE id = $1`, [minor]);
  assert.deepEqual(anonymous, { last_name_lat: 'ANONYME', by_matricule: true, last_name_ar: null, born: '2015-01-01', phone: null, custom: {},
    external_ref: null, site: true, done: true });
  assert.equal(await count('SELECT count(*) n FROM school.enrollment WHERE id = ANY ($1)', [[adultEnrolment, minorEnrolment]]), 2, 'enrolments stay');
  assert.equal((await first("SELECT reason FROM school.audit_log WHERE object_type = 'student' AND object_id = $1 ORDER BY occurred_at DESC LIMIT 1", [minor])).reason,
    'retention');
  // The brother leaves and is anonymised too; their father stays while the minor still has an enrolment in an open year.
  await backdate('student', sibling, { status: 'former', left_on: '2020-06-30', archived_at: new Date(Date.now() - 4 * 365.25 * 864e5).toISOString() });
  assert.deepEqual(await first(RETAIN, [a]), { archived: 0, anonymised: 1, guardians: 0, journal: 0, kept: 1 });
  assert.equal((await first('SELECT anonymised_at FROM school.guardian WHERE id = $1', [father])).anonymised_at, null);
  await as('school_runtime', a, 1);
  await sql("UPDATE school.enrollment SET status = 'cancelled', status_reason = 'Départ de la famille' WHERE id = $1", [minorEnrolment]);
  await as(null);
  assert.deepEqual(await first(RETAIN, [a]), { archived: 0, anonymised: 0, guardians: 1, journal: 0, kept: 0 });
  assert.deepEqual(await first('SELECT last_name_lat, phone, archived_at IS NOT NULL AS archived FROM school.guardian WHERE id = $1', [father]),
    { last_name_lat: 'ANONYME', phone: null, archived: true });
  // The journal: what is older than the school's years goes; the run leaves its counts.
  const old = (await first("INSERT INTO school.audit_log (tenant_id, occurred_at, action, object_type, object_id) VALUES ($1, now() - interval '11 years', 'UPDATE', 'site', $2) RETURNING id",
    [a, siteA])).id;
  const recent = (await first("INSERT INTO school.audit_log (tenant_id, occurred_at, action, object_type, object_id) VALUES ($1, now() - interval '9 years', 'UPDATE', 'site', $2) RETURNING id",
    [a, siteA])).id;
  assert.deepEqual(await first(RETAIN, [a]), { archived: 0, anonymised: 0, guardians: 0, journal: 1, kept: 0 });
  assert.deepEqual((await sql('SELECT id FROM school.audit_log WHERE id IN ($1, $2)', [old, recent])).rows.map(r => r.id), [recent]);
  assert.deepEqual((await first("SELECT after FROM school.audit_log WHERE tenant_id = $1 AND action = 'RETENTION' ORDER BY occurred_at DESC LIMIT 1", [a])).after,
    { archived: 0, anonymised: 0, guardians: 0, journal: 1, kept: 0 });
  assert.equal(await first(RETAIN, [a]), undefined, 'a run with nothing to do leaves no entry');
  await as('school_runtime', a, 1);
});

// The staff (SQL 031, FR-STF-01): a record with or without an account (P7), on a site, never deleted.
await as('platform_admin', null, 6);
await sql("SELECT platform.enable_module($1, 'staff')", [a]);
await as('school_runtime', a, 1);
const STAFF = 'INSERT INTO school.staff (tenant_id, last_name_lat, first_name_lat, staff_type) VALUES ($1, $2, $3, $4) RETURNING id, staff_number, site_id';
const newStaff = async (last, given = 'Karim', type = 'teacher_internal') => first(STAFF, [a, last, given, type]);
const staffSiteB = (await first("INSERT INTO school.site (tenant_id, code, labels) VALUES ($1, 'STAFF-B', '{\"fr\": \"Annexe du personnel\"}') RETURNING id", [a])).id;
await check('a member of staff has a record without an account, a number given once, a name in either alphabet (FR-STF-01)', async () => {
  const first1 = await newStaff('Meziane');
  const second = await newStaff('Ouali', 'Nadia', 'administrative');
  assert.deepEqual([first1.staff_number, second.staff_number, first1.site_id], ['P0001', 'P0002', siteA]);
  assert.equal((await first('SELECT user_id, status FROM school.staff WHERE id = $1', [first1.id])).user_id, null);
  // A number of the school's own is kept; the next one made follows the highest made.
  assert.equal((await first("INSERT INTO school.staff (tenant_id, staff_number, last_name_lat, first_name_lat, staff_type) VALUES ($1, 'RH-12', 'Haddad', 'Sami', 'other') RETURNING staff_number", [a])).staff_number, 'RH-12');
  assert.equal((await first("INSERT INTO school.staff (tenant_id, staff_number, last_name_lat, first_name_lat, staff_type) VALUES ($1, 'P0100', 'Brahimi', 'Leila', 'teacher_external') RETURNING staff_number", [a])).staff_number, 'P0100');
  assert.equal((await newStaff('Kaci')).staff_number, 'P0101');
  await rejects("INSERT INTO school.staff (tenant_id, staff_number, last_name_lat, first_name_lat, staff_type) VALUES ($1, 'RH-12', 'Autre', 'Personne', 'other')", [a], '23505');
  assert.ok((await first("INSERT INTO school.staff (tenant_id, last_name_ar, first_name_ar, staff_type) VALUES ($1, 'مزيان', 'كريم', 'teacher_internal') RETURNING id", [a])).id);
  await rejects("INSERT INTO school.staff (tenant_id, last_name_lat, staff_type) VALUES ($1, 'Incomplet', 'other')", [a], '23514');
  await rejects("INSERT INTO school.staff (tenant_id, last_name_lat, first_name_lat, staff_type) VALUES ($1, 'Sans', 'Type', 'cook')", [a], '23514');
  await rejects("UPDATE school.staff SET staff_number = 'X' WHERE id = $1", [first1.id], '42501');
  await rejects('DELETE FROM school.staff WHERE id = $1', [first1.id], '42501');
  await as(null);
  await rejects("UPDATE school.staff SET staff_number = 'X' WHERE id = $1", [first1.id], '23514', 'code_immutable');
  await as('school_runtime', a, 1);
  // The school's own fields on staff records (FR-REF-04).
  const field = (await first("INSERT INTO school.custom_field (tenant_id, entity, key, field_type, required, labels) VALUES ($1, 'staff', 'diploma', 'text', true, '{\"fr\": \"Diplôme\"}') RETURNING id", [a])).id;
  await rejects(STAFF, [a, 'Sans', 'Diplome', 'teacher_internal'], '23514', 'custom_value_required');
  assert.ok((await first("INSERT INTO school.staff (tenant_id, last_name_lat, first_name_lat, staff_type, custom) VALUES ($1, 'Avec', 'Diplome', 'teacher_internal', '{\"diploma\": \"Licence\"}') RETURNING id", [a])).id);
  await sql('UPDATE school.custom_field SET archived_at = now() WHERE id = $1', [field]);
});
await check('a departure keeps the record with its day; only a member who left is archived, then it no longer changes (FR-STF-01, BR-02)', async () => {
  const member = await newStaff('Depart');
  await sql("UPDATE school.staff SET hire_date = '2020-09-01' WHERE id = $1", [member.id]);
  await sql("UPDATE school.staff SET status = 'on_leave' WHERE id = $1", [member.id]);
  await rejects("UPDATE school.staff SET status = 'left', left_on = current_date + 1 WHERE id = $1", [member.id], '23514', 'left_on_future');
  await rejects("UPDATE school.staff SET status = 'left', left_on = '2019-06-30' WHERE id = $1", [member.id], '23514', 'left_before_hire');
  await rejects('UPDATE school.staff SET archived_at = now() WHERE id = $1', [member.id], '23514', 'staff_archive_left');
  await sql("UPDATE school.staff SET status = 'left' WHERE id = $1", [member.id]);
  assert.deepEqual(await first('SELECT status, left_on::text AS left_on FROM school.staff WHERE id = $1', [member.id]), { status: 'left', left_on: today });
  await rejects("UPDATE school.staff SET status = 'on_leave' WHERE id = $1", [member.id], '23514', 'status_transition_refused');
  // Back again: the record is the same, without the day of the departure.
  await sql("UPDATE school.staff SET status = 'active' WHERE id = $1", [member.id]);
  assert.equal((await first('SELECT left_on FROM school.staff WHERE id = $1', [member.id])).left_on, null);
  await sql("UPDATE school.staff SET status = 'left', left_on = '2026-01-31' WHERE id = $1", [member.id]);
  await sql('UPDATE school.staff SET archived_at = now() WHERE id = $1', [member.id]);
  await rejects("UPDATE school.staff SET phone = '0550' WHERE id = $1", [member.id], '23514', 'staff_archived');
  await rejects("UPDATE school.staff SET archived_at = NULL WHERE id = $1", [member.id], '23514', 'staff_archived');
  assert.deepEqual((await sql("SELECT action, after ->> 'status' AS status FROM school.audit_log WHERE object_type = 'staff' AND object_id = $1 AND after ? 'status' ORDER BY occurred_at, id",
    [member.id])).rows.map(r => r.status), ['on_leave', 'left', 'active', 'left']);
});
await check('an account is one member of staff\'s, never linked by its own holder (FR-STF-01, FR-IAM-05)', async () => {
  const teacher = await newStaff('Compte');
  const other = await newStaff('Autre');
  await sql('UPDATE school.staff SET user_id = 4 WHERE id = $1', [teacher.id]);
  await rejects('UPDATE school.staff SET user_id = 4 WHERE id = $1', [other.id], '23514', 'staff_account_linked');
  await rejects('UPDATE school.staff SET user_id = 1 WHERE id = $1', [other.id], '23514', 'self_account');
  await as(null);
  const gone = (await first("INSERT INTO public.users (name, email, password) VALUES ('Parti', $1, 'hash') RETURNING id", [uuid() + '@example.test'])).id;
  await sql("INSERT INTO school.membership (tenant_id, user_id, status) VALUES ($1, $2, 'revoked')", [a, gone]);
  const stranger = (await first("INSERT INTO public.users (name, email, password) VALUES ('Ailleurs', $1, 'hash') RETURNING id", [uuid() + '@example.test'])).id;
  await sql("INSERT INTO school.membership (tenant_id, user_id, status) VALUES ($1, $2, 'active')", [b, stranger]);
  await as('school_runtime', a, 1);
  await rejects('UPDATE school.staff SET user_id = $2 WHERE id = $1', [other.id, gone], '23514', 'membership_revoked');
  await rejects('UPDATE school.staff SET user_id = $2 WHERE id = $1', [other.id, stranger], '23503');
  // Unlinked, the account may serve another record.
  await sql('UPDATE school.staff SET user_id = NULL WHERE id = $1', [teacher.id]);
  await sql('UPDATE school.staff SET user_id = 4 WHERE id = $1', [other.id]);
});
await check('a site\'s grant records and follows the staff of its sites; a personal grant reads one\'s own record and its journal (FR-IAM-04, FR-AUD-01)', async () => {
  const main = await newStaff('Principal');
  const desk = await scopedAccount('internal', 'site', [staffSiteB], ['staff.profile.read', 'staff.profile.create', 'staff.profile.update']);
  await as('school_runtime', a, desk.user);
  // Their only site, without saying it; read back at once.
  const theirs = await newStaff('Annexe');
  assert.equal(theirs.site_id, staffSiteB);
  assert.deepEqual((await sql('SELECT id FROM school.staff')).rows.map(r => r.id), [theirs.id]);
  await rejects(STAFF.replace('staff_type)', 'staff_type, site_id)').replace('$4)', '$4, $5)'), [a, 'Ailleurs', 'Karim', 'other', siteA], '42501', 'permission_denied');
  assert.equal((await sql("UPDATE school.staff SET phone = 'intrusion' WHERE id = $1", [main.id])).rowCount ?? 0, 0);
  await sql("UPDATE school.staff SET phone = '0661 00 00 00' WHERE id = $1", [theirs.id]);
  await rejects('UPDATE school.staff SET site_id = $2 WHERE id = $1', [theirs.id, siteA], '42501', 'permission_denied');
  await rejects('SELECT school.require_staff($1, $2)', ['staff.profile.update', main.id], '42501', 'permission_denied');
  // A teacher reads their own record, linked by someone else, and its journal; nobody else's.
  const self = await scopedAccount('internal', 'personal', null, ['staff.profile.read', 'audit.log.read']);
  await as('school_runtime', a, 1);
  await sql("UPDATE school.staff SET user_id = $2, phone = '0770 11 22 33' WHERE id = $1", [main.id, self.user]);
  assert.deepEqual(await first("SELECT before ->> 'phone' AS before, after ->> 'phone' AS after FROM school.audit_log WHERE object_type = 'staff' AND object_id = $1 AND action = 'UPDATE' ORDER BY occurred_at DESC LIMIT 1",
    [main.id]), { before: '[masqué]', after: '[masqué]' });
  await as('school_runtime', a, self.user);
  assert.deepEqual((await sql('SELECT id FROM school.staff')).rows.map(r => r.id), [main.id]);
  assert.ok(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'staff' AND object_id = $1", [main.id]) > 0);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'staff' AND object_id <> $1", [main.id]), 0);
  assert.equal((await sql("UPDATE school.staff SET phone = 'moi' WHERE id = $1", [main.id])).rowCount ?? 0, 0);
  // The journal's technical reader, without the staff's records, reads none of their entries.
  const technical = await scopedAccount('internal', 'tenant', null, ['audit.log.read']);
  await as('school_runtime', a, technical.user);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'staff'"), 0);
  await as('school_runtime', a, 1);
});
await check('without the staff module, nobody reads or writes a staff record (TST-04)', async () => {
  await as('platform_admin', null, 6);
  await sql("SELECT platform.disable_module($1, 'staff')", [a]);
  await as('school_runtime', a, 1);
  assert.equal(await count('SELECT count(*) n FROM school.staff'), 0);
  await rejects(STAFF, [a, 'Hors', 'Module', 'other'], '42501', 'permission_denied');
  await as('platform_admin', null, 6);
  await sql("SELECT platform.enable_module($1, 'staff')", [a]);
  await as('school_runtime', a, 1);
  assert.ok(await count('SELECT count(*) n FROM school.staff') > 0);
});
// A teacher's qualifications (SQL 032, FR-STF-02): a subject at a node of the referential, its descendants covered.
const QUALIFY = 'INSERT INTO school.qualification (tenant_id, staff_id, subject_id, node_id, valid_from, valid_to) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id, valid_from::text';
const nodeOf = async code => (await first('SELECT id FROM school.ref_node WHERE tenant_id = $1 AND code = $2', [a, code])).id;
const [maths, physics] = (await sql('SELECT id FROM school.subject WHERE tenant_id = $1 AND archived_at IS NULL ORDER BY code LIMIT 2', [a])).rows.map(r => r.id);
const QUALIFIED = 'SELECT school.qualified($1, $2, $3, $4::date) AS ok';
await check('a qualification at a cycle covers its levels, on its dates, computed rather than copied (FR-STF-02 AC2)', async () => {
  const teacher = await newStaff('Habilite');
  const [primary, third, middle] = [await nodeOf('PRIMAIRE'), await nodeOf('3AP'), await nodeOf('MOYEN')];
  const line = await first(QUALIFY, [a, teacher.id, maths, primary, null, null]);
  assert.equal(line.valid_from, today);
  for (const [subject, node, day, ok] of [[maths, third, today, true], [maths, primary, today, true], [maths, middle, today, false],
    [physics, third, today, false], [maths, third, yesterday, false]]) {
    assert.equal((await first(QUALIFIED, [teacher.id, subject, node, day])).ok, ok, `${node} ${day}`);
  }
  assert.equal(await count('SELECT count(*) n FROM school.qualification WHERE staff_id = $1', [teacher.id]), 1);
  // A past qualification, ended: covered on its days only.
  await first(QUALIFY, [a, teacher.id, physics, middle, '2020-09-01', '2024-06-30']);
  assert.equal((await first(QUALIFIED, [teacher.id, physics, middle, '2023-01-10'])).ok, true);
  assert.equal((await first(QUALIFIED, [teacher.id, physics, middle, today])).ok, false);
  await rejects(QUALIFY, [a, teacher.id, maths, primary, '2030-01-01', null], '23514', 'qualification_overlap');
  await rejects(QUALIFY, [a, teacher.id, physics, middle, '2024-06-30', null], '23514', 'qualification_overlap');
  assert.ok(await first(QUALIFY, [a, teacher.id, physics, middle, '2024-07-01', null]));
  await rejects(QUALIFY, [a, teacher.id, maths, middle, '2026-01-10', '2026-01-01'], '23514');
});
await check('a qualification keeps its teacher, subject, node and first day; its end is final; one nothing uses is deleted, journaled (FR-STF-02, BR-02)', async () => {
  const teacher = await newStaff('Fin');
  const node = await nodeOf('3AP');
  const line = (await first(QUALIFY, [a, teacher.id, maths, node, '2025-09-01', null])).id;
  await rejects('UPDATE school.qualification SET node_id = $2 WHERE id = $1', [line, await nodeOf('MOYEN')], '42501');
  await as(null);
  await rejects('UPDATE school.qualification SET valid_from = $2 WHERE id = $1', [line, '2025-01-01'], '23514', 'identity_immutable');
  await as('school_runtime', a, 1);
  await rejects("UPDATE school.qualification SET valid_to = '2025-08-01' WHERE id = $1", [line], '23514', 'qualification_end_before_start');
  await sql("UPDATE school.qualification SET valid_to = '2026-06-30' WHERE id = $1", [line]);
  await rejects("UPDATE school.qualification SET valid_to = '2026-07-31' WHERE id = $1", [line], '23514', 'qualification_ended');
  await sql('DELETE FROM school.qualification WHERE id = $1', [line]);
  assert.deepEqual(await first("SELECT before ->> 'subject_id' AS subject, before ->> 'valid_to' AS until FROM school.audit_log WHERE object_type = 'qualification' AND object_id = $1 AND action = 'DELETE'",
    [line]), { subject: maths, until: '2026-06-30' });
});
await check('only a teacher still with the school is qualified, for a subject and a node in use (FR-STF-02, FR-STF-01)', async () => {
  const node = await nodeOf('3AP');
  const clerk = await newStaff('Bureau', 'Amel', 'administrative');
  await rejects(QUALIFY, [a, clerk.id, maths, node, null, null], '23514', 'qualification_not_teacher');
  const gone = await newStaff('Parti');
  const kept = (await first(QUALIFY, [a, gone.id, maths, node, '2024-09-01', null])).id;
  await sql("UPDATE school.staff SET status = 'left' WHERE id = $1", [gone.id]);
  await rejects(QUALIFY, [a, gone.id, physics, node, null, null], '23514', 'staff_left');
  // Who left keeps their qualifications as history; ended, then frozen with the archived record.
  await sql('UPDATE school.qualification SET valid_to = current_date WHERE id = $1', [kept]);
  await sql('UPDATE school.staff SET archived_at = now() WHERE id = $1', [gone.id]);
  await rejects('DELETE FROM school.qualification WHERE id = $1', [kept], '23514', 'staff_archived');
  const teacher = await newStaff('Catalogue');
  const subject = async code => (await first("INSERT INTO school.subject (tenant_id, code, kind, labels) VALUES ($1, $2, 'subject', $3) RETURNING id",
    [a, code, JSON.stringify({ fr: 'Matière ' + code })])).id;
  const old = await subject('ANCIENNE_032');
  await sql('UPDATE school.subject SET archived_at = now() WHERE id = $1', [old]);
  await rejects(QUALIFY, [a, teacher.id, old, node, null, null], '23514', 'subject_archived');
  // A subject a qualification names stays in the catalogue.
  const taught = await subject('QUALIF_032');
  await first(QUALIFY, [a, teacher.id, taught, node, null, null]);
  await rejects('DELETE FROM school.subject WHERE id = $1', [taught], '23503');
});
await check('a site\'s grant qualifies the teachers of its site only; a teacher reads their own qualifications (FR-IAM-04)', async () => {
  const node = await nodeOf('3AP');
  const elsewhere = await newStaff('Siege');
  const office = await scopedAccount('internal', 'site', [staffSiteB], ['staff.profile.read', 'staff.profile.create', 'staff.qualification.manage']);
  await as('school_runtime', a, office.user);
  const theirs = await newStaff('Annexe', 'Lina');
  const line = (await first(QUALIFY, [a, theirs.id, maths, node, null, null])).id;
  await rejects(QUALIFY, [a, elsewhere.id, maths, node, null, null], '42501');
  assert.deepEqual((await sql('SELECT id FROM school.qualification')).rows.map(r => r.id), [line]);
  assert.equal((await first(QUALIFIED, [theirs.id, maths, node, today])).ok, true);
  assert.equal((await first(QUALIFIED, [elsewhere.id, maths, node, today])).ok, false, 'what the reader cannot see');
  const self = await scopedAccount('internal', 'personal', null, ['staff.profile.read']);
  await as('school_runtime', a, 1);
  await sql('UPDATE school.staff SET user_id = $2 WHERE id = $1', [theirs.id, self.user]);
  await as('school_runtime', a, self.user);
  assert.deepEqual((await sql('SELECT id FROM school.qualification')).rows.map(r => r.id), [line]);
  await rejects(QUALIFY, [a, theirs.id, physics, node, null, null], '42501');
  await as('school_runtime', a, 1);
});
// A group's lead teacher (SQL 033, FR-GRP-01) and the groups it opens to them (FR-IAM-04 AC1, TST-03).
const LEAD = 'UPDATE school.study_group SET lead_teacher_id = $2 WHERE id = $1';
const leadProgram = await programme('LEAD-033');
await check('a group\'s lead teacher is a teacher still with the school, whom the writer reads (FR-GRP-01)', async () => {
  const lead = await group('LEAD-A', leadProgram, 10);
  const teacher = await newStaff('Titulaire');
  await sql(LEAD, [lead, teacher.id]);
  assert.equal((await first("SELECT after ->> 'lead_teacher_id' AS lead FROM school.audit_log WHERE object_type = 'study_group' AND object_id = $1 AND action = 'UPDATE' ORDER BY occurred_at DESC LIMIT 1",
    [lead])).lead, teacher.id);
  await sql(LEAD, [lead, null]);
  await rejects(LEAD, [lead, (await newStaff('Bureau', 'Amel', 'administrative')).id], '23514', 'lead_teacher_not_teacher');
  const gone = await newStaff('Parti');
  await sql("UPDATE school.staff SET status = 'left' WHERE id = $1", [gone.id]);
  await rejects(LEAD, [lead, gone.id], '23514', 'staff_left');
  await sql('UPDATE school.staff SET archived_at = now() WHERE id = $1', [gone.id]);
  await rejects(LEAD, [lead, gone.id], '23514', 'staff_archived');
  await rejects(LEAD, [lead, uuid()], '23503');
  // Who leaves later stays the lead teacher in the group's history.
  const leaving = await newStaff('Sortant');
  await sql(LEAD, [lead, leaving.id]);
  await sql("UPDATE school.staff SET status = 'left' WHERE id = $1", [leaving.id]);
  assert.equal((await first('SELECT lead_teacher_id FROM school.study_group WHERE id = $1', [lead])).lead_teacher_id, leaving.id);
  // A site's grant names the teachers of its site only; it is told nothing of the others.
  const annex = await group('LEAD-B', leadProgram, 10, 'support', staffSiteB);
  const elsewhere = await newStaff('Siege', 'Rania');
  const desk = await scopedAccount('internal', 'site', [staffSiteB], ['group.group.read', 'group.group.manage', 'staff.profile.read', 'staff.profile.create']);
  await as('school_runtime', a, desk.user);
  await rejects(LEAD, [annex, elsewhere.id], '42501', 'permission_denied');
  const theirs = await newStaff('Annexe', 'Yacine');
  await sql(LEAD, [annex, theirs.id]);
  await as('school_runtime', a, 1);
});
await check('a teacher\'s personal grant reads the groups they lead, their pupils and programmes, nothing else, until they leave (FR-IAM-04 AC1, TST-03)', async () => {
  const [mine, other] = [await group('LEAD-MINE', leadProgram, 10), await group('LEAD-OTHER', leadProgram, 10)];
  const [pupil, classmate] = [(await newAdult('Eleve', 'Ines')).id, (await newAdult('Autre', 'Samir')).id];
  const [enrolment, elsewhere] = [await enroll(pupil, leadProgram), await enroll(classmate, leadProgram)];
  const place = await joinGroup(enrolment, mine);
  await joinGroup(elsewhere, other);
  const [guardian] = [await contact(pupil), await contact(classmate)];
  const record = await newStaff('Enseignant', 'Nadir');
  const teacher = await scopedAccount('internal', 'personal', null, [...coreReads, 'staff.profile.read', 'group.group.manage']);
  await as('school_runtime', a, 1);
  await sql('UPDATE school.staff SET user_id = $2 WHERE id = $1', [record.id, teacher.user]);
  await as('school_runtime', a, teacher.user);
  assert.deepEqual(await visibleIds('study_group'), [], 'no group before leading one');
  assert.deepEqual(await visibleIds('student'), []);
  await as('school_runtime', a, 1);
  await sql(LEAD, [mine, record.id]);
  await as('school_runtime', a, teacher.user);
  assert.deepEqual(await visibleIds('study_group'), [mine]);
  assert.deepEqual(await visibleIds('student'), [pupil]);
  assert.deepEqual(await visibleIds('guardian'), [guardian]);
  assert.deepEqual(await visibleIds('enrollment'), [enrolment]);
  assert.deepEqual(await visibleIds('group_membership'), [place]);
  assert.deepEqual(await visibleIds('program'), [leadProgram]);
  assert.deepEqual(await visibleIds('staff'), [record.id]);
  assert.ok(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'study_group' AND object_id = $1", [mine]) > 0);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE object_type IN ('study_group', 'student') AND object_id IN ($1, $2)", [other, classmate]), 0);
  // What the grant holds acts on these groups only; it never names itself lead teacher of another.
  await sql("UPDATE school.study_group SET labels = '{\"fr\": \"Ma classe\"}' WHERE id = $1", [mine]);
  assert.equal((await sql('UPDATE school.study_group SET capacity = 5 WHERE id = $1', [other])).rowCount ?? 0, 0);
  await rejects("INSERT INTO school.study_group (tenant_id, year_id, code, labels, group_type, program_id, site_id, capacity, valid_from) VALUES ($1, $2, 'LEAD-NEW', '{\"fr\": \"Nouveau\"}', 'class', $3, $4, 10, current_date)",
    [a, lotYear, leadProgram, siteA], '42501');
  // Another lead teacher, and the group is no longer theirs.
  await as('school_runtime', a, 1);
  await sql(LEAD, [mine, (await newStaff('Remplacant')).id]);
  await as('school_runtime', a, teacher.user);
  assert.deepEqual(await visibleIds('study_group'), []);
  assert.deepEqual(await visibleIds('student'), []);
  // Back as its lead teacher, then gone from the school: nothing more.
  await as('school_runtime', a, 1);
  await sql(LEAD, [mine, record.id]);
  await sql("UPDATE school.staff SET status = 'left' WHERE id = $1", [record.id]);
  await as('school_runtime', a, teacher.user);
  assert.deepEqual(await visibleIds('study_group'), []);
  await as('school_runtime', a, 1);
  await sql("UPDATE school.staff SET status = 'active' WHERE id = $1", [record.id]);
  await as('school_runtime', a, teacher.user);
  assert.deepEqual(await visibleIds('study_group'), [mine]);
  await as('school_runtime', a, 1);
});
await check('the teacher\'s role reads their pupils, these pupils\' guardians and their own record, within its personal scope (section 3.3)', async () => {
  for (const permission of ['student.record.read', 'guardian.record.read', 'staff.profile.read', 'group.group.read', 'program.program.read']) {
    assert.equal(await count('SELECT count(*) n FROM school.role_permission WHERE tenant_id = $1 AND role_id = $2 AND permission_code = $3',
      [a, roles.teacher, permission]), 1, permission);
  }
  const lead = await group('LEAD-ROLE', leadProgram, 10);
  const pupil = (await newAdult('Classe', 'Lilia')).id;
  await joinGroup(await enroll(pupil, leadProgram), lead);
  const record = await newStaff('Role', 'Walid');
  await as(null);
  const user = (await first("INSERT INTO public.users (name, email, password) VALUES ('Teacher', $1, 'hash') RETURNING id", [uuid() + '@example.test'])).id;
  await sql("INSERT INTO school.membership (tenant_id, user_id, status) VALUES ($1, $2, 'active')", [a, user]);
  await assign(user, 'teacher', 'personal');
  await as('school_runtime', a, 1);
  await sql('UPDATE school.staff SET user_id = $2 WHERE id = $1', [record.id, user]);
  await sql(LEAD, [lead, record.id]);
  await as('school_runtime', a, user);
  assert.deepEqual(await visibleIds('study_group'), [lead]);
  assert.deepEqual(await visibleIds('student'), [pupil]);
  assert.deepEqual(await visibleIds('staff'), [record.id]);
  assert.deepEqual(await visibleIds('enrollment'), [], 'enrolments are not the teacher\'s');
  await as('school_runtime', a, 1);
});
// A teacher's availability (SQL 034, FR-STF-03): weekly slots from a day on, days off that prevail, bounds, an approval when asked.
const DECLARE = 'INSERT INTO school.availability (tenant_id, staff_id, valid_from, slots, min_weekly_hours, max_daily_hours, max_weekly_hours) VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id, status, valid_from::text, slots, decided_by';
const DAY_OFF = 'INSERT INTO school.unavailability (tenant_id, staff_id, date_from, date_to, reason) VALUES ($1, $2, $3, $4, $5) RETURNING id, status';
const AVAILABLE = 'SELECT school.available($1, $2::date, $3::time, $4::time) AS ok';
const slot = (weekday, start_time, end_time) => ({ weekday, start_time, end_time });
// The next Sunday from today (today if it is one), and the days after it.
const sunday = (await first("SELECT (current_date + (7 - extract(dow FROM current_date)::integer) % 7)::text AS d")).d;
const dayAfter = async (day, n) => (await first('SELECT ($1::date + $2::integer)::text AS d', [day, n])).d;
await check('a weekly availability is in force from its first day, adjacent slots joined, until a later one; a day off prevails (FR-STF-03)', async () => {
  const teacher = await newStaff('Dispo', 'Rym');
  const [monday, tuesday, nextWeek] = [await dayAfter(sunday, 1), await dayAfter(sunday, 2), await dayAfter(sunday, 7)];
  assert.equal((await first(AVAILABLE, [teacher.id, sunday, '07:00', '20:00'])).ok, true, 'nothing declared: availability unknown, not empty');
  const week = await first(DECLARE, [a, teacher.id, null, JSON.stringify([slot(2, '14:00', '17:00'), slot(0, '10:00', '12:00'), slot(0, '08:00', '10:00')]), 4, 6, 10]);
  // In force at once (no approval asked), its slots in the week's order.
  assert.deepEqual([week.status, week.valid_from, Number(week.decided_by)], ['approved', today, 1]);
  assert.deepEqual(week.slots, [slot(0, '08:00', '10:00'), slot(0, '10:00', '12:00'), slot(2, '14:00', '17:00')]);
  assert.equal((await first('SELECT id FROM school.availability_on($1)', [teacher.id])).id, week.id);
  assert.equal((await first('SELECT school.availability_hours(slots)::text AS h FROM school.availability WHERE id = $1', [week.id])).h, '7.00');
  for (const [day, from, until, ok] of [[sunday, '09:00', '11:00', true], [sunday, '11:00', '13:00', false], [tuesday, '14:00', '17:00', true],
    [monday, '08:00', '09:00', false]]) {
    assert.equal((await first(AVAILABLE, [teacher.id, day, from, until])).ok, ok, `${day} ${from}-${until}`);
  }
  // A later declaration, here with no slot: available at no time from its first day; the first one holds until then.
  await first(DECLARE, [a, teacher.id, nextWeek, '[]', null, null, null]);
  assert.equal((await first(AVAILABLE, [teacher.id, nextWeek, '09:00', '10:00'])).ok, false);
  assert.equal((await first(AVAILABLE, [teacher.id, sunday, '09:00', '10:00'])).ok, true);
  // A day off prevails over the week (AC1 with lot 4's sessions); cancelled until its last day, the week holds again.
  const off = await first(DAY_OFF, [a, teacher.id, sunday, sunday, 'Formation des enseignants']);
  assert.equal(off.status, 'approved');
  assert.equal((await first(AVAILABLE, [teacher.id, sunday, '09:00', '10:00'])).ok, false);
  await rejects(DAY_OFF, [a, teacher.id, sunday, monday, 'Autre absence'], '23514', 'unavailability_overlap');
  await rejects(DAY_OFF, [a, teacher.id, yesterday, yesterday, 'Hier'], '23514', 'availability_past');
  await rejects(DAY_OFF, [a, teacher.id, monday, sunday, 'A l\'envers'], '23514', 'unavailability_end_before_start');
  await rejects(DAY_OFF, [a, teacher.id, monday, monday, '  '], '23514', 'reason_required');
  await rejects('DELETE FROM school.unavailability WHERE id = $1', [off.id], '23514', 'availability_decided');
  await rejects("UPDATE school.unavailability SET date_to = date_to + 1 WHERE id = $1", [off.id], '42501');
  await sql("UPDATE school.unavailability SET status = 'cancelled' WHERE id = $1", [off.id]);
  assert.ok((await first('SELECT cancelled_at FROM school.unavailability WHERE id = $1', [off.id])).cancelled_at);
  assert.equal((await first(AVAILABLE, [teacher.id, sunday, '09:00', '10:00'])).ok, true);
  await rejects("UPDATE school.unavailability SET status = 'approved' WHERE id = $1", [off.id], '23514', 'availability_decided');
  // What a declaration says, checked; one in force never changes, nor goes.
  for (const [slots, rule] of [[[slot(0, '08:00', '10:00'), slot(0, '09:30', '11:00')], 'availability_slots_overlap'],
    [[slot(0, '8:00', '10:00')], 'availability_slots_invalid'], [[slot(0, '10:00', '10:00')], 'availability_slots_invalid'],
    [[slot(7, '08:00', '10:00')], 'availability_slots_invalid'], [[{ ...slot(1, '08:00', '10:00'), room: 'A1' }], 'availability_slots_invalid'],
    [{ weekday: 1 }, 'availability_slots_invalid']]) {
    await rejects(DECLARE, [a, teacher.id, tomorrow, JSON.stringify(slots), null, null, null], '23514', rule);
  }
  assert.deepEqual((await first(DECLARE, [a, teacher.id, await dayAfter(nextWeek, 1), JSON.stringify([slot(6, '20:00', '24:00')]), null, 4, 4])).slots,
    [slot(6, '20:00', '24:00')]);
  await rejects(DECLARE, [a, teacher.id, yesterday, '[]', null, null, null], '23514', 'availability_past');
  await rejects(DECLARE, [a, teacher.id, nextWeek, '[]', null, null, null], '23514', 'availability_same_day');
  await rejects(DECLARE, [a, teacher.id, tomorrow, JSON.stringify([slot(1, '08:00', '10:00')]), 3, null, null], '23514', 'availability_below_minimum');
  await rejects(DECLARE, [a, teacher.id, tomorrow, '[]', null, 8, 6], '23514');
  await rejects("UPDATE school.availability SET slots = '[]' WHERE id = $1", [week.id], '42501');
  await rejects("UPDATE school.availability SET status = 'rejected', decision_reason = 'Trop tard' WHERE id = $1", [week.id], '23514', 'availability_decided');
  await rejects('DELETE FROM school.availability WHERE id = $1', [week.id], '23514', 'availability_decided');
  // A teacher's only: an administrative member, one who left, an archived record declare nothing.
  await rejects(DECLARE, [a, (await newStaff('Bureau', 'Amel', 'administrative')).id, null, '[]', null, null, null], '23514', 'availability_not_teacher');
  const gone = await newStaff('Parti', 'Omar');
  await sql("UPDATE school.staff SET status = 'left' WHERE id = $1", [gone.id]);
  await rejects(DECLARE, [a, gone.id, null, '[]', null, null, null], '23514', 'staff_left');
  await rejects(DAY_OFF, [a, gone.id, tomorrow, tomorrow, 'Absent'], '23514', 'staff_left');
});
await check('when the school asks for it, a teacher\'s change waits for an approver, never approved by its own teacher (FR-STF-03)', async () => {
  await sql("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'staff.availability.require_approval', 'true')", [a]);
  const record = await newStaff('Validation', 'Selma');
  const self = await scopedAccount('internal', 'personal', null, ['staff.profile.read', 'staff.availability.manage']);
  await as('school_runtime', a, 1);
  await sql('UPDATE school.staff SET user_id = $2 WHERE id = $1', [record.id, self.user]);
  // The manager's change is in force at once; the teacher's waits.
  const off = await first(DAY_OFF, [a, record.id, sunday, sunday, 'Jury d\'examen']);
  assert.equal(off.status, 'approved');
  await as('school_runtime', a, self.user);
  const week = await first(DECLARE, [a, record.id, null, JSON.stringify([slot(0, '08:00', '12:00')]), null, null, null]);
  const other = await first(DECLARE, [a, record.id, tomorrow, '[]', null, null, null]);
  const dayOff = await first(DAY_OFF, [a, record.id, await dayAfter(sunday, 3), await dayAfter(sunday, 4), 'Rendez-vous']);
  assert.deepEqual([week.status, week.decided_by, other.status, dayOff.status], ['pending', null, 'pending', 'pending']);
  assert.equal((await first(AVAILABLE, [record.id, await dayAfter(sunday, 1), '15:00', '16:00'])).ok, true, 'pending: not in force');
  assert.equal((await sql("UPDATE school.availability SET status = 'approved' WHERE id = $1", [week.id])).rowCount ?? 0, 0);
  await rejects("UPDATE school.unavailability SET status = 'cancelled' WHERE id = $1", [off.id], '23514', 'approval_required');
  // Withdrawn while pending; the journal keeps it.
  await sql('DELETE FROM school.unavailability WHERE id = $1', [dayOff.id]);
  await as('school_runtime', a, 1);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'unavailability' AND object_id = $1 AND action = 'DELETE'", [dayOff.id]), 1);
  await rejects("UPDATE school.availability SET status = 'rejected' WHERE id = $1", [other.id], '23514', 'reason_required');
  await sql("UPDATE school.availability SET status = 'rejected', decision_reason = 'Créneaux à revoir' WHERE id = $1", [other.id]);
  await sql("UPDATE school.availability SET status = 'approved' WHERE id = $1", [week.id]);
  assert.deepEqual(await first('SELECT status, decided_by::int AS by, valid_from::text AS since FROM school.availability WHERE id = $1', [week.id]),
    { status: 'approved', by: 1, since: today });
  assert.equal((await first(AVAILABLE, [record.id, await dayAfter(sunday, 1), '15:00', '16:00'])).ok, false);
  // The day refused is free again.
  assert.equal((await first(DECLARE, [a, record.id, tomorrow, '[]', null, null, null])).status, 'approved');
  // An approver's own record: their change waits for another approver; another teacher's is in force at once.
  const hr = await scopedAccount('internal', 'tenant', null, ['staff.profile.read', 'staff.availability.manage', 'staff.availability.approve']);
  await as('school_runtime', a, 1);
  const theirs = await newStaff('Responsable', 'Nadia');
  await sql('UPDATE school.staff SET user_id = $2 WHERE id = $1', [theirs.id, hr.user]);
  await as('school_runtime', a, hr.user);
  const own = await first(DECLARE, [a, theirs.id, null, '[]', null, null, null]);
  assert.equal(own.status, 'pending');
  await rejects("UPDATE school.availability SET status = 'approved' WHERE id = $1", [own.id], '23514', 'self_approval');
  const stage = await dayAfter(sunday, 10);
  assert.equal((await first(DAY_OFF, [a, record.id, stage, stage, 'Stage'])).status, 'approved');
  // Approved after its first day, a declaration takes effect from the day it is approved, once a day.
  await as(null);
  await sql('ALTER TABLE school.availability DISABLE TRIGGER USER');
  const late = (await first("INSERT INTO school.availability (tenant_id, staff_id, valid_from, slots, created_at, updated_at) VALUES ($1, $2, current_date - 3, '[]', now(), now()) RETURNING id",
    [a, theirs.id])).id;
  await sql('ALTER TABLE school.availability ENABLE TRIGGER USER');
  await as('school_runtime', a, 1);
  await rejects("UPDATE school.availability SET status = 'approved' WHERE id = $1", [late], '23514', 'availability_same_day');
  await sql("UPDATE school.availability SET status = 'rejected', decision_reason = 'Remplacée' WHERE id = $1", [own.id]);
  await sql("UPDATE school.availability SET status = 'approved' WHERE id = $1", [late]);
  assert.equal((await first('SELECT valid_from::text AS since FROM school.availability WHERE id = $1', [late])).since, today);
  await sql("UPDATE school.setting SET value = 'false' WHERE tenant_id = $1 AND key = 'staff.availability.require_approval'", [a]);
});
await check('a site\'s grant keeps the availability of its site\'s teachers; a teacher reads their own and its journal (FR-IAM-04)', async () => {
  const elsewhere = await newStaff('Siege', 'Hind');
  const office = await scopedAccount('internal', 'site', [staffSiteB], ['staff.profile.read', 'staff.profile.create', 'staff.availability.manage']);
  await as('school_runtime', a, office.user);
  const theirs = await newStaff('Annexe', 'Walid');
  const week = (await first(DECLARE, [a, theirs.id, null, '[]', null, null, null])).id;
  await rejects(DECLARE, [a, elsewhere.id, null, '[]', null, null, null], '42501');
  await rejects(DAY_OFF, [a, elsewhere.id, tomorrow, tomorrow, 'Ailleurs'], '42501');
  assert.deepEqual((await sql('SELECT id FROM school.availability')).rows.map(r => r.id), [week]);
  assert.equal((await first(AVAILABLE, [elsewhere.id, sunday, '08:00', '09:00'])).ok, true, 'what the reader cannot see');
  const self = await scopedAccount('internal', 'personal', null, ['staff.profile.read', 'audit.log.read']);
  await as('school_runtime', a, 1);
  await sql('UPDATE school.staff SET user_id = $2 WHERE id = $1', [theirs.id, self.user]);
  await as('school_runtime', a, self.user);
  assert.deepEqual((await sql('SELECT id FROM school.availability')).rows.map(r => r.id), [week]);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'availability' AND object_id = $1", [week]), 1);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE object_type IN ('availability', 'unavailability') AND object_id <> $1", [week]), 0);
  await rejects(DECLARE, [a, theirs.id, tomorrow, '[]', null, null, null], '42501');
  await as('school_runtime', a, 1);
});
// A member's contracts and leave (SQL 035, FR-STF-04): dated versions without any amount, planned absences that make a teacher unavailable.
const CONTRACT = 'INSERT INTO school.staff_contract (tenant_id, staff_id, contract_type, valid_from, valid_to, weekly_hours, observation) VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id, valid_from::text, valid_to::text';
const LEAVE = 'INSERT INTO school.staff_leave (tenant_id, staff_id, leave_type, date_from, date_to, observation) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id, status';
await check('a contract is versioned with its dates and carries no amount; its end only comes earlier (FR-STF-04 AC1)', async () => {
  // AC1: nothing of a contract is an amount, whoever reads it.
  const columns = (await sql("SELECT column_name FROM information_schema.columns WHERE table_schema = 'school' AND table_name = 'staff_contract'")).rows.map(r => r.column_name);
  assert.deepEqual(columns.filter(c => /amount|salary|rate|pay|wage|price|minor/.test(c)), []);
  const teacher = await newStaff('Contrat', 'Yanis');
  const [started, renewal, later] = [await dayAfter(today, -30), await dayAfter(today, 10), await dayAfter(today, 300)];
  const trial = await first(CONTRACT, [a, teacher.id, 'fixed_term', started, later, 18, null]);
  assert.deepEqual([trial.valid_from, trial.valid_to], [started, later]);
  await rejects(CONTRACT, [a, teacher.id, 'permanent', today, null, null, null], '23514', 'contract_overlap');
  await rejects(CONTRACT, [a, (await newStaff('Contrat', 'Ines')).id, 'fixed_term', today, null, null, null], '23514', 'contract_end_required');
  await rejects(CONTRACT, [a, teacher.id, 'permanent', later, started, null, null], '23514', 'contract_end_before_start');
  await rejects(CONTRACT, [a, teacher.id, 'permanent', await dayAfter(later, 1), null, 0, null], '23514');
  // An amendment: the version in force ends the day before the next one's first day, which follows it.
  await sql('UPDATE school.staff_contract SET valid_to = $2 WHERE id = $1', [trial.id, await dayAfter(renewal, -1)]);
  const next = await first(CONTRACT, [a, teacher.id, 'permanent', renewal, null, 20, 'Avenant n°1']);
  await rejects('UPDATE school.staff_contract SET valid_to = $2 WHERE id = $1', [trial.id, later], '23514', 'contract_end_later');
  await rejects('UPDATE school.staff_contract SET valid_to = NULL WHERE id = $1', [trial.id], '23514', 'contract_end_later');
  await rejects('UPDATE school.staff_contract SET valid_to = $2 WHERE id = $1', [trial.id, await dayAfter(started, -1)], '23514', 'contract_end_before_start');
  await rejects("UPDATE school.staff_contract SET end_reason = 'Erreur' WHERE id = $1", [trial.id], '23514', 'identity_immutable');
  await rejects('UPDATE school.staff_contract SET weekly_hours = 30 WHERE id = $1', [trial.id], '42501');
  // Ended earlier with its reason; a version begun is never deleted, one to come is, the journal keeping it masked.
  await sql("UPDATE school.staff_contract SET valid_to = $2, end_reason = 'Fin de période d''essai' WHERE id = $1", [trial.id, today]);
  await rejects('DELETE FROM school.staff_contract WHERE id = $1', [trial.id], '23514', 'contract_started');
  await sql('DELETE FROM school.staff_contract WHERE id = $1', [next.id]);
  const entry = await first("SELECT before FROM school.audit_log WHERE object_type = 'staff_contract' AND object_id = $1 AND action = 'DELETE'", [next.id]);
  assert.deepEqual([entry.before.observation, entry.before.weekly_hours, entry.before.contract_type], ['[masqué]', 20, 'permanent']);
  // Any member still with the school; an archived record's no longer change.
  assert.ok((await first(CONTRACT, [a, (await newStaff('Bureau', 'Amel', 'administrative')).id, 'permanent', today, null, 40, null])).id);
  const gone = await newStaff('Parti', 'Contrat');
  const kept = await first(CONTRACT, [a, gone.id, 'hourly', started, null, null, null]);
  await sql("UPDATE school.staff SET status = 'left' WHERE id = $1", [gone.id]);
  await rejects(CONTRACT, [a, gone.id, 'hourly', later, null, null, null], '23514', 'staff_left');
  await sql('UPDATE school.staff_contract SET valid_to = $2 WHERE id = $1', [kept.id, today]);
  await sql('UPDATE school.staff SET archived_at = now() WHERE id = $1', [gone.id]);
  await rejects('UPDATE school.staff_contract SET valid_to = $2 WHERE id = $1', [kept.id, yesterday], '23514', 'staff_archived');
});
await check('a teacher\'s leave makes them unavailable, never saying why to the record\'s readers, and is cancelled with it (FR-STF-04, FR-STF-03)', async () => {
  const teacher = await newStaff('Conge', 'Salima');
  const leave = await first(LEAVE, [a, teacher.id, 'sick', yesterday, await dayAfter(today, 2), 'Certificat médical']);
  assert.equal(leave.status, 'planned');
  const off = await first('SELECT status, reason, date_from::text AS since FROM school.unavailability WHERE leave_id = $1', [leave.id]);
  assert.deepEqual(off, { status: 'approved', reason: null, since: yesterday });
  assert.equal((await first(AVAILABLE, [teacher.id, today, '08:00', '09:00'])).ok, false);
  await rejects(LEAVE, [a, teacher.id, 'annual', await dayAfter(today, 2), await dayAfter(today, 5), null], '23514', 'leave_overlap');
  await rejects(LEAVE, [a, teacher.id, 'annual', await dayAfter(today, 5), await dayAfter(today, 4), null], '23514', 'leave_end_before_start');
  // Days the teacher declared may meet a leave's.
  assert.equal((await first(DAY_OFF, [a, teacher.id, tomorrow, tomorrow, 'Rendez-vous'])).status, 'approved');
  // A leave's unavailability follows its leave only, and never is forged.
  await rejects("UPDATE school.unavailability SET status = 'cancelled' WHERE leave_id = $1", [leave.id], '23514', 'unavailability_from_leave');
  await rejects('DELETE FROM school.unavailability WHERE leave_id = $1', [leave.id], '23514', 'availability_decided');
  await rejects('INSERT INTO school.unavailability (tenant_id, staff_id, date_from, date_to, leave_id) VALUES ($1, $2, $3, $3, $4)',
    [a, teacher.id, today, leave.id], '23514', 'unavailability_from_leave');
  await rejects('UPDATE school.staff_leave SET date_to = $2 WHERE id = $1', [leave.id, today], '42501');
  await rejects('DELETE FROM school.staff_leave WHERE id = $1', [leave.id], '42501');
  await sql("UPDATE school.staff_leave SET status = 'cancelled' WHERE id = $1", [leave.id]);
  assert.equal((await first('SELECT status FROM school.unavailability WHERE leave_id = $1', [leave.id])).status, 'cancelled');
  assert.equal((await first(AVAILABLE, [teacher.id, today, '08:00', '09:00'])).ok, true);
  await rejects("UPDATE school.staff_leave SET status = 'planned' WHERE id = $1", [leave.id], '23514', 'status_transition_refused');
  // Once its days are gone, a leave stays as it is; a member who does not teach has no timetable to free.
  const past = await first(LEAVE, [a, teacher.id, 'training', await dayAfter(today, -10), await dayAfter(today, -8), null]);
  await rejects("UPDATE school.staff_leave SET status = 'cancelled' WHERE id = $1", [past.id], '23514', 'leave_ended');
  const clerk = await newStaff('Conge', 'Bureau', 'administrative');
  assert.equal((await first(LEAVE, [a, clerk.id, 'annual', tomorrow, await dayAfter(today, 10), null])).status, 'planned');
  assert.equal(await count('SELECT count(*) n FROM school.unavailability WHERE staff_id = $1', [clerk.id]), 0);
  const gone = await newStaff('Parti', 'Conge');
  await sql("UPDATE school.staff SET status = 'left' WHERE id = $1", [gone.id]);
  await rejects(LEAVE, [a, gone.id, 'annual', tomorrow, tomorrow, null], '23514', 'staff_left');
});
await check('contracts and leave are read with their own permission; a site\'s grant keeps its site\'s, a teacher reads their own (FR-IAM-04, section 3.3)', async () => {
  const elsewhere = await newStaff('Siege', 'Terms');
  const office = await scopedAccount('internal', 'site', [staffSiteB], ['staff.profile.read', 'staff.profile.create', 'staff.contract.read',
    'staff.contract.manage', 'staff.leave.read', 'staff.leave.manage']);
  await as('school_runtime', a, office.user);
  const theirs = await newStaff('Annexe', 'Terms');
  const contract = (await first(CONTRACT, [a, theirs.id, 'permanent', today, null, 12, null])).id;
  // Their leave makes the teacher unavailable, though the grant declares no availability.
  const leave = (await first(LEAVE, [a, theirs.id, 'annual', tomorrow, tomorrow, null])).id;
  assert.equal(await count('SELECT count(*) n FROM school.unavailability WHERE leave_id = $1', [leave]), 1);
  await rejects(CONTRACT, [a, elsewhere.id, 'permanent', today, null, null, null], '42501');
  await rejects(LEAVE, [a, elsewhere.id, 'annual', tomorrow, tomorrow, null], '42501');
  assert.deepEqual((await sql('SELECT id FROM school.staff_contract')).rows.map(r => r.id), [contract]);
  // Whoever reads the records only reads that the teacher is away, not the leave nor the contract.
  const reader = await scopedAccount('internal', 'tenant', null, ['staff.profile.read']);
  await as('school_runtime', a, reader.user);
  assert.equal(await count('SELECT count(*) n FROM school.staff_contract'), 0);
  assert.equal(await count('SELECT count(*) n FROM school.staff_leave'), 0);
  assert.deepEqual(await first('SELECT reason, status FROM school.unavailability WHERE leave_id = $1', [leave]), { reason: null, status: 'approved' });
  // A teacher with their own scope reads their contract and leave and their journal; changes none.
  const self = await scopedAccount('internal', 'personal', null, ['staff.profile.read', 'staff.contract.read', 'staff.leave.read', 'audit.log.read']);
  await as('school_runtime', a, 1);
  await sql('UPDATE school.staff SET user_id = $2 WHERE id = $1', [theirs.id, self.user]);
  await as('school_runtime', a, self.user);
  assert.deepEqual((await sql('SELECT id FROM school.staff_contract')).rows.map(r => r.id), [contract]);
  assert.deepEqual((await sql('SELECT id FROM school.staff_leave')).rows.map(r => r.id), [leave]);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE object_type IN ('staff_contract', 'staff_leave')"), 2);
  await rejects(CONTRACT, [a, theirs.id, 'permanent', await dayAfter(today, 400), null, null, null], '42501');
  assert.equal((await sql("UPDATE school.staff_leave SET status = 'cancelled' WHERE id = $1", [leave])).rowCount ?? 0, 0);
  await as('school_runtime', a, 1);
  // Section 3.3: the school's teacher role reads them, as its template does.
  assert.equal(await count(`SELECT count(*) n FROM school.role_permission p JOIN school.role r ON r.tenant_id = p.tenant_id AND r.id = p.role_id
    WHERE r.tenant_id = $1 AND r.template_code = 'teacher' AND p.permission_code IN ('staff.contract.read', 'staff.leave.read')`, [a]), 2);
});
// The staff's import (SQL 036, FR-IMP-01): a member keeps the reference of their file; one an import created is undone while nothing depends on them.
await check('a member of staff\'s reference from a file is theirs alone and never changes; an import undone removes them while nothing depends on them (FR-IMP-01, BR-05)', async () => {
  const STAFF_REF = "INSERT INTO school.staff (tenant_id, last_name_lat, first_name_lat, staff_type, external_ref) VALUES ($1, $2, 'Import', 'teacher_internal', $3) RETURNING id";
  const member = (await first(STAFF_REF, [a, 'Reference', 'PERS-1'])).id;
  await rejects(STAFF_REF, [a, 'Double', 'PERS-1'], '23505');
  await rejects("UPDATE school.staff SET external_ref = 'PERS-2' WHERE id = $1", [member], '42501');
  await as(null);
  await rejects("UPDATE school.staff SET external_ref = 'PERS-2' WHERE id = $1", [member], '23514', 'code_immutable');
  await as('school_runtime', a, 1);
  // Only what an import still in place created goes.
  await rejects('SELECT school.discard_staff($1)', [member], '23514', 'dependent_operation');
  const batch = await importBatch();
  await reported(batch, 'staff', member);
  // A qualification, a day off, a contract, a leave, a group they lead, an account: built on them since, the member stays.
  const subject = (await first("INSERT INTO school.subject (tenant_id, code, kind, labels) VALUES ($1, 'IMP_STAFF', 'subject', '{\"fr\": \"Import\"}') RETURNING id", [a])).id;
  const node = (await first("SELECT id FROM school.ref_node WHERE tenant_id = $1 AND archived_at IS NULL ORDER BY code LIMIT 1", [a])).id;
  const line = (await first('INSERT INTO school.qualification (tenant_id, staff_id, subject_id, node_id) VALUES ($1, $2, $3, $4) RETURNING id', [a, member, subject, node])).id;
  await rejects('SELECT school.discard_staff($1)', [member], '23514', 'dependent_operation');
  await sql('DELETE FROM school.qualification WHERE id = $1', [line]);
  const contract = (await first(CONTRACT, [a, member, 'permanent', await dayAfter(today, 5), null, null, null])).id;
  await rejects('SELECT school.discard_staff($1)', [member], '23514', 'dependent_operation');
  await sql('DELETE FROM school.staff_contract WHERE id = $1', [contract]);
  await sql("UPDATE school.staff SET status = 'on_leave' WHERE id = $1", [member]);
  await rejects('SELECT school.discard_staff($1)', [member], '23514', 'dependent_operation');
  await sql("UPDATE school.staff SET status = 'active' WHERE id = $1", [member]);
  // Whoever may not create staff for the whole school undoes nothing.
  await as('school_runtime', a, roleless);
  await rejects('SELECT school.discard_staff($1)', [member], '42501');
  await as('school_runtime', a, 1);
  await sql('SELECT school.discard_staff($1)', [member]);
  assert.equal(await count('SELECT count(*) n FROM school.staff WHERE id = $1', [member]), 0);
  assert.deepEqual((await first("SELECT reason FROM school.audit_log WHERE object_type = 'staff' AND object_id = $1 AND action = 'DELETE'", [member])).reason, 'import_rollback');
});
// Rooms (SQL 037, FR-SLL-02): a site's rooms with their type, equipment and state among the school's lists, dated closures, a group's main room.
const ROOM = 'INSERT INTO school.room (tenant_id, site_id, code, labels, capacity, room_type, equipment) VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id, equipment';
const room = async (site, code, capacity = 30, type = 'CLASSROOM', equipment = []) =>
  first(ROOM, [a, site, code, JSON.stringify({ fr: 'Salle ' + code, ar: 'قاعة ' + code }), capacity, type, equipment]);
const CLOSURE = 'INSERT INTO school.room_closure (tenant_id, room_id, date_from, date_to, reason) VALUES ($1, $2, $3, $4, $5) RETURNING id';
await check('a room has its type and equipment among the school\'s lists, given with the module, and a code unique on its site (FR-SLL-02)', async () => {
  // The publisher's lists, given to the school with its sites and rooms module.
  assert.deepEqual((await sql("SELECT kind, count(*)::int AS n FROM school.room_option WHERE tenant_id = $1 GROUP BY kind ORDER BY kind", [a])).rows,
    [{ kind: 'equipment', n: 6 }, { kind: 'room_type', n: 5 }]);
  const lab = await room(siteA, 'LABO-1', 24, 'LAB', ['PROJECTOR', 'LAB_BENCHES', 'PROJECTOR']);
  assert.deepEqual(lab.equipment, ['LAB_BENCHES', 'PROJECTOR']);
  await rejects(ROOM, [a, siteA, 'LABO-1', '{"fr": "Autre"}', 10, 'LAB', []], '23505');
  assert.ok((await room(staffSiteB, 'LABO-1')).id, 'the same code on another site');
  await rejects(ROOM, [a, siteA, 'S-0', '{"fr": "Vide"}', 0, 'CLASSROOM', []], '23514');
  await rejects(ROOM, [a, siteA, 'S-1', '{"fr": "Piscine"}', 10, 'POOL', []], '23514', 'room_option_unknown');
  await rejects(ROOM, [a, siteA, 'S-1', '{"fr": "Salle"}', 10, 'CLASSROOM', ['TELESCOPE']], '23514', 'room_option_unknown');
  await rejects("UPDATE school.room SET code = 'LABO-2' WHERE id = $1", [lab.id], '42501');
  // The school adds to its lists and archives an entry: new rooms no longer take it, those that have it keep it.
  const music = (await first("INSERT INTO school.room_option (tenant_id, kind, code, labels) VALUES ($1, 'room_type', 'MUSIC', '{\"fr\": \"Salle de musique\", \"ar\": \"قاعة موسيقى\"}') RETURNING id", [a])).id;
  const studio = await room(siteA, 'MUS-1', 15, 'MUSIC');
  await sql('UPDATE school.room_option SET archived_at = now() WHERE id = $1', [music]);
  await rejects(ROOM, [a, siteA, 'MUS-2', '{"fr": "Musique"}', 15, 'MUSIC', []], '23514', 'room_option_unknown');
  await sql('UPDATE school.room SET capacity = 18 WHERE id = $1', [studio.id]);
  await rejects("UPDATE school.room_option SET labels = '{\"fr\": \"Musique\"}' WHERE id = $1", [music], '23514', 'room_option_archived');
  await rejects("UPDATE school.room_option SET code = 'SONG' WHERE id = $1", [music], '42501');
  // An archived room stays as it is; the lists are the whole school's to change.
  await sql('UPDATE school.room SET archived_at = now() WHERE id = $1', [studio.id]);
  await rejects('UPDATE school.room SET capacity = 20 WHERE id = $1', [studio.id], '23514', 'room_archived');
  const local = await scopedAccount('internal', 'site', [staffSiteB], ['referential.node.read', 'room.room.manage']);
  await as('school_runtime', a, local.user);
  assert.ok((await room(staffSiteB, 'ANNEXE-1')).id);
  await rejects(ROOM, [a, siteA, 'ANNEXE-1', '{"fr": "Ailleurs"}', 10, 'CLASSROOM', []], '42501');
  assert.equal((await sql('UPDATE school.room SET capacity = 99 WHERE id = $1', [lab.id])).rowCount ?? 0, 0, 'another site\'s room');
  await rejects("INSERT INTO school.room_option (tenant_id, kind, code, labels) VALUES ($1, 'equipment', 'PIANO', '{\"fr\": \"Piano\"}')", [a], '42501');
  await as('school_runtime', a, 1);
});
await check('a room closed or unavailable takes no session on those days; a closure is cancelled until its last day (FR-SLL-02 AC2)', async () => {
  const hall = (await room(siteA, 'AMPHI-1', 120, 'AMPHI')).id;
  const [day, later] = [await dayAfter(today, 3), await dayAfter(today, 5)];
  const available = async on => (await first('SELECT school.room_available($1, $2::date) AS ok', [hall, on])).ok;
  assert.equal(await available(day), true);
  const works = (await first(CLOSURE, [a, hall, day, later, 'Travaux de peinture'])).id;
  assert.deepEqual([await available(day), await available(later), await available(await dayAfter(later, 1))], [false, false, true]);
  await rejects(CLOSURE, [a, hall, later, await dayAfter(later, 2), 'Autres travaux'], '23514', 'room_closure_overlap');
  await rejects(CLOSURE, [a, hall, yesterday, today, 'Hier'], '23514', 'room_closure_past');
  await rejects(CLOSURE, [a, hall, later, day, 'A l\'envers'], '23514', 'room_closure_end_before_start');
  await rejects("UPDATE school.room_closure SET date_to = date_to + 1 WHERE id = $1", [works], '42501');
  await sql("UPDATE school.room_closure SET status = 'cancelled' WHERE id = $1", [works]);
  assert.equal(await available(day), true);
  // Unavailable for repairs: no new session, any day.
  await sql("UPDATE school.room SET status = 'unavailable' WHERE id = $1", [hall]);
  assert.equal(await available(day), false);
  await sql("UPDATE school.room SET status = 'active' WHERE id = $1", [hall]);
  // A closure whose days are gone stays as it is.
  await as(null);
  await sql('ALTER TABLE school.room_closure DISABLE TRIGGER USER');
  const past = (await first("INSERT INTO school.room_closure (tenant_id, room_id, date_from, date_to, reason, created_at, updated_at) VALUES ($1, $2, current_date - 5, current_date - 3, 'Passée', now(), now()) RETURNING id", [a, hall])).id;
  await sql('ALTER TABLE school.room_closure ENABLE TRIGGER USER');
  await as('school_runtime', a, 1);
  await rejects("UPDATE school.room_closure SET status = 'cancelled' WHERE id = $1", [past], '23514', 'room_closure_ended');
});
await check('a group\'s main room is on its site, available when named and never smaller than the group; a site with rooms in use stays (FR-GRP-01, FR-SLL-02 AC1, FR-SLL-01)', async () => {
  const program = await programme('SALLES');
  const small = (await room(siteA, 'PETITE', 20)).id;
  const big = (await room(siteA, 'GRANDE', 30)).id;
  const elsewhere = (await room(staffSiteB, 'LOIN', 40)).id;
  const twentyFive = await group('SALLE-25', program, 25);
  const named = room => sql('UPDATE school.study_group SET main_room_id = $2 WHERE id = $1', [twentyFive, room]);
  // AC1: a room of 20 places for a group of 25 is a capacity conflict.
  await rejects('UPDATE school.study_group SET main_room_id = $2 WHERE id = $1', [twentyFive, small], '23514', 'room_too_small');
  await rejects('UPDATE school.study_group SET main_room_id = $2 WHERE id = $1', [twentyFive, elsewhere], '23514', 'room_other_site');
  await named(big);
  // Whichever changes: the group grows past its room, the room shrinks below its group.
  await rejects('UPDATE school.study_group SET capacity = 31 WHERE id = $1', [twentyFive], '23514', 'room_too_small');
  await sql('UPDATE school.study_group SET capacity = 30 WHERE id = $1', [twentyFive]);
  await rejects('UPDATE school.room SET capacity = 29 WHERE id = $1', [big], '23514', 'room_too_small');
  await rejects('UPDATE school.room SET archived_at = now() WHERE id = $1', [big], '23514', 'room_in_use');
  // An unavailable room is named no more; the group that has it keeps it.
  await sql("UPDATE school.room SET status = 'unavailable' WHERE id = $1", [big]);
  const other = await group('SALLE-10', program, 10);
  await rejects('UPDATE school.study_group SET main_room_id = $2 WHERE id = $1', [other, big], '23514', 'room_unavailable');
  await sql('UPDATE school.study_group SET labels = $2 WHERE id = $1', [twentyFive, JSON.stringify({ fr: 'Groupe renommé' })]);
  await sql("UPDATE school.room SET status = 'active' WHERE id = $1", [big]);
  // FR-SLL-01: a site with a room in use is not archived.
  const annex = (await first("INSERT INTO school.site (tenant_id, code, labels) VALUES ($1, 'ROOMS-C', '{\"fr\": \"Annexe des salles\"}') RETURNING id", [a])).id;
  const last = (await room(annex, 'C-1')).id;
  await rejects('UPDATE school.site SET archived_at = now() WHERE id = $1', [annex], '23514', 'site_has_rooms');
  await sql('UPDATE school.room SET archived_at = now() WHERE id = $1', [last]);
  await sql('UPDATE school.site SET archived_at = now() WHERE id = $1', [annex]);
  await rejects(ROOM, [a, annex, 'C-2', '{"fr": "Salle"}', 10, 'CLASSROOM', []], '23514', 'site_archived');
});
// Access to courses (SQL 039, FR-INS-04): computed when asked from the enrolment's state, its payments and the school's setting.
await check('a validated enrolment opens its courses; with a payment required, one without a validated payment does not (FR-INS-04 AC1)', async () => {
  const p = await programme('ACCES');
  const free = await programme('ACCES-LIBRE', 0);
  const g = await group('ACCES-1', p, 5);
  const gFree = await group('ACCES-2', free, 5);
  const e = await enroll((await newAdult('Acces', 'Nadia')).id, p);
  const eFree = await enroll((await newAdult('Acces', 'Sofiane')).id, free);
  const access = async id => (await first('SELECT school.access_granted($1) AS ok', [id])).ok;
  const policy = async value => {
    await as(null);
    await sql(`INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'enrollment.access.require_payment', $2::jsonb)
      ON CONFLICT (tenant_id, key) DO UPDATE SET value = EXCLUDED.value`, [a, JSON.stringify(value)]);
    await as('school_runtime', a, 1);
  };
  assert.equal(await access(e), false, 'pending');
  await joinGroup(e, g);
  await joinGroup(eFree, gFree);
  await sql("UPDATE school.enrollment SET status = 'validated' WHERE id = ANY ($1)", [[e, eFree]]);
  // By default (`none`), validated is enough.
  assert.deepEqual([await access(e), await access(eFree)], [true, true]);
  // AC1: the first instalment asked for, and none paid (lot 3 records payments); a free programme owes nothing.
  await policy('first_installment');
  assert.deepEqual([await access(e), await access(eFree)], [false, true]);
  await policy('full');
  assert.deepEqual([await access(e), await access(eFree)], [false, true]);
  await as(null);
  await rejects("UPDATE school.setting SET value = '\"half\"' WHERE tenant_id = $1 AND key = 'enrollment.access.require_payment'", [a], '23514', 'setting_invalid');
  await rejects("UPDATE school.setting SET value = '[\"none\"]' WHERE tenant_id = $1 AND key = 'enrollment.access.require_payment'", [a], '23514', 'setting_invalid');
  await policy('none');
  // Suspended, nothing; an enrolment the reader does not see, nothing either.
  await sql("UPDATE school.enrollment SET status = 'suspended', status_reason = 'Impayé' WHERE id = $1", [eFree]);
  assert.equal(await access(eFree), false);
  await as('school_runtime', a, 4);
  assert.equal(await access(e), false, 'out of the reader\'s reach');
  await as('school_runtime', a, 1);
});
// The annual progression (SQL 040, FR-INS-05): one decision per validated enrolment; a promotion or a repetition enrols in a later year's class.
await check('a promotion enrols in a later year\'s class at the next level, a repetition at the same level, once; the source stays as it was (FR-INS-05 AC2 to AC4)', async () => {
  await as(null);
  const later = (await first("INSERT INTO school.academic_year (tenant_id, code, start_date, end_date, status) VALUES ($1, 'PASSAGE-N1', '2028-09-01', '2029-06-30', 'planned') RETURNING id", [a])).id;
  const nextGrade = (await first("SELECT id FROM school.ref_node WHERE tenant_id = $1 AND type_code = 'grade' AND archived_at IS NULL AND id <> $2 ORDER BY code LIMIT 1", [a, grade])).id;
  await as('school_runtime', a, 1);
  const source = await programme('PASSAGE-A');
  const sourceClass = await group('PASSAGE-A1', source, 10);
  const program = async (code, node) => (await first(PROGRAM, [a, later, node, code, JSON.stringify({ fr: 'Programme ' + code }), 100000, 'active'])).id;
  const laterClass = async (code, programId, capacity = 10) => (await first(GROUP, [a, later, code, JSON.stringify({ fr: 'Classe ' + code }), 'class', programId, siteA, capacity])).id;
  const up = await laterClass('PASSAGE-B1', await program('PASSAGE-B', nextGrade));
  const again = await laterClass('PASSAGE-R1', await program('PASSAGE-R', grade));
  const sameYear = await group('PASSAGE-A2', await programme('PASSAGE-A-BIS'), 10);
  const validated = async given => {
    const e = await enroll((await newAdult('Passage', given)).id, source);
    await joinGroup(e, sourceClass);
    await sql("UPDATE school.enrollment SET status = 'validated' WHERE id = $1", [e]);
    return e;
  };
  const DECIDE = 'SELECT school.decide_progression($1, $2, $3, $4, $5::date) AS id';
  const e = await validated('Amine');
  const pending = await enroll((await newAdult('Passage', 'Attente')).id, source);
  await rejects(DECIDE, [pending, 'promoted', 'Admis', up, '2028-09-15'], '23514', 'progression_not_validated');
  // AC4: a repetition that changes the level, a promotion that keeps it or stays in the same year, refused.
  await rejects(DECIDE, [e, 'repeated', 'Résultats insuffisants', up, '2028-09-15'], '23514', 'progression_level_changed');
  await rejects(DECIDE, [e, 'promoted', 'Admis', again, '2028-09-15'], '23514', 'progression_level_same');
  await rejects(DECIDE, [e, 'promoted', 'Admis', sameYear, '2028-09-15'], '23514', 'progression_same_year');
  await rejects(DECIDE, [e, 'promoted', 'Admis', null, null], '23514', 'progression_target_required');
  await rejects(DECIDE, [e, 'promoted', 'Admis', up, null], '23514', 'progression_target_required');
  await rejects(DECIDE, [e, 'left', 'Déménagement', up, '2028-09-15'], '23514', 'progression_target_invalid');
  await rejects(DECIDE, [e, 'promoted', '  ', up, '2028-09-15'], '23514', 'reason_required');
  const before = await first('SELECT status, version, updated_at::text AS at FROM school.enrollment WHERE id = $1', [e]);
  const decided = (await first(DECIDE, [e, 'promoted', 'Admis en classe supérieure', up, '2028-09-15'])).id;
  const made = await first('SELECT p.target_enrollment_id AS t, t.status, t.year_id, m.group_id, m.is_main FROM school.progression p JOIN school.enrollment t ON t.id = p.target_enrollment_id JOIN school.group_membership m ON m.enrollment_id = t.id WHERE p.id = $1', [decided]);
  assert.deepEqual([made.status, made.year_id, made.group_id, made.is_main], ['pending', later, up, true]);
  // AC2: the same decision again makes nothing; another one is refused.
  assert.equal((await first(DECIDE, [e, 'promoted', 'Admis en classe supérieure', up, '2028-09-15'])).id, decided);
  await rejects(DECIDE, [e, 'repeated', 'Finalement non', again, '2028-09-15'], '23514', 'progression_decided');
  assert.equal(await count('SELECT count(*) n FROM school.enrollment WHERE student_id = (SELECT student_id FROM school.enrollment WHERE id = $1)', [e]), 2);
  // AC3: the source enrolment, its groups and history unchanged.
  assert.deepEqual(await first('SELECT status, version, updated_at::text AS at FROM school.enrollment WHERE id = $1', [e]), before);
  assert.equal(await count('SELECT count(*) n FROM school.group_membership WHERE enrollment_id = $1 AND valid_to IS NULL', [e]), 1);
  await rejects("UPDATE school.progression SET reason = 'Autre' WHERE id = $1", [decided], '42501');
  // A repetition at the same level; a departure without a target.
  assert.ok((await first(DECIDE, [await validated('Lina'), 'repeated', 'Moyenne insuffisante', again, '2028-09-15'])).id);
  const gone = (await first(DECIDE, [await validated('Yanis'), 'left', 'Déménagement de la famille', null, null])).id;
  assert.equal((await first('SELECT target_enrollment_id FROM school.progression WHERE id = $1', [gone])).target_enrollment_id, null);
  // A pupil already enrolled in the target programme is not enrolled again.
  const twice = await validated('Sami');
  await enroll((await first('SELECT student_id FROM school.enrollment WHERE id = $1', [twice])).student_id, (await first('SELECT program_id FROM school.study_group WHERE id = $1', [up])).program_id);
  await rejects(DECIDE, [twice, 'promoted', 'Admis', up, '2028-09-15'], '23514', 'progression_target_enrolled');

  // AC1: a batch whose target is full creates nothing.
  const small = await laterClass('PASSAGE-B2', (await first('SELECT program_id FROM school.study_group WHERE id = $1', [up])).program_id, 1);
  const [x, y] = [await validated('Rania'), await validated('Karim')];
  await sql('BEGIN');
  let refused = null;
  try {
    for (const one of [x, y]) await sql(DECIDE, [one, 'promoted', 'Admis', small, '2028-09-15']);
    await sql('COMMIT');
  } catch (err) { refused = err.message; await sql('ROLLBACK'); }
  assert.equal(refused, 'group_full');
  assert.equal(await count('SELECT count(*) n FROM school.progression WHERE enrollment_id = ANY ($1)', [[x, y]]), 0);
  assert.equal(await count('SELECT count(*) n FROM school.group_membership WHERE group_id = $1', [small]), 0);
});
// A group's composition (SQL 041, FR-GRP-02): a pupil joining or leaving notifies the group's lead teacher.
await check('a pupil joining or leaving a group notifies its lead teacher, by the group only; nobody for their own change (FR-GRP-02)', async () => {
  const program = await programme('COMPO-041');
  const [led, unled] = [await group('COMPO-A', program, 10), await group('COMPO-B', program, 10)];
  const record = await newStaff('Compo', 'Titulaire');
  const teacher = await scopedAccount('internal', 'tenant', null, ['group.group.read', 'group.membership.manage', 'enrollment.enrollment.read']);
  await as('school_runtime', a, 1);
  await sql('UPDATE school.staff SET user_id = $2 WHERE id = $1', [record.id, teacher.user]);
  await sql(LEAD, [led, record.id]);
  const notes = async () => { await as(null); const rows = (await sql("SELECT data FROM school.notification WHERE user_id = $1 AND event = 'group.membership_changed' ORDER BY created_at, id", [teacher.user])).rows.map(r => r.data); await as('school_runtime', a, 1); return rows; };
  const place = await joinGroup(await enroll((await newAdult('Compo', 'Ines')).id, program), led);
  await joinGroup(await enroll((await newAdult('Compo', 'Samir')).id, program), unled);
  assert.deepEqual(await notes(), [{ group_id: led, change: 'joined' }]);
  await sql("SELECT set_config('app.reason', 'Changement de classe', false)");
  await sql('SELECT school.move_to_group($1, $2)', [place, unled]);
  await sql("SELECT set_config('app.reason', '', false)");
  assert.deepEqual(await notes(), [{ group_id: led, change: 'joined' }, { group_id: led, change: 'left' }]);
  // The lead teacher's own change tells them nothing.
  const enrolment = await enroll((await newAdult('Compo', 'Lina')).id, program);
  await as('school_runtime', a, teacher.user);
  await sql(JOIN_GROUP, [a, enrolment, led]);
  assert.equal((await notes()).length, 2);
});
// A programme copied to a later year (SQL 044, FR-PRG-02): a draft with its subjects and volumes in force at the source year's end.
await check('a programme copied to a later year is a draft with its subjects and volumes, once; never to an earlier year (FR-PRG-02)', async () => {
  await as(null);
  const next = (await first("INSERT INTO school.academic_year (tenant_id, code, start_date, end_date, status) VALUES ($1, 'COPIE-N1', '2030-09-01', '2031-06-30', 'planned') RETURNING id", [a])).id;
  const subjects = (await sql('SELECT id FROM school.subject WHERE tenant_id = $1 AND archived_at IS NULL ORDER BY code LIMIT 2', [a])).rows.map(r => r.id);
  await as('school_runtime', a, 1);
  const source = await programme('COPIE-A', 150000, 'active');
  const LINE = 'INSERT INTO school.program_subject (tenant_id, program_id, subject_id, total_hours, session_count, session_duration_minutes) VALUES ($1, $2, $3, $4, $5, 60) RETURNING id';
  await sql(LINE, [a, source, subjects[0], 40, 20]);
  await sql(LINE, [a, source, subjects[1], 30, 15]);
  const copy = (await first('SELECT school.copy_program($1, $2) AS id', [source, next])).id;
  assert.deepEqual(await first('SELECT code, status, base_price::text AS price, copied_from, year_id FROM school.program WHERE id = $1', [copy]),
    { code: 'COPIE-A', status: 'draft', price: '150000.00', copied_from: source, year_id: next });
  assert.deepEqual((await sql('SELECT total_hours::text AS h, session_count AS n, valid_from::text AS f FROM school.program_subject WHERE program_id = $1 ORDER BY total_hours', [copy])).rows,
    [{ h: '30.00', n: 15, f: '2030-09-01' }, { h: '40.00', n: 20, f: '2030-09-01' }]);
  // Asked again, the same copy; modifiable as any programme of its year; the source as it was.
  assert.equal((await first('SELECT school.copy_program($1, $2) AS id', [source, next])).id, copy);
  await sql("UPDATE school.program_subject SET total_hours = 36 WHERE program_id = $1 AND total_hours = 40", [copy]);
  assert.equal(await count('SELECT count(*) n FROM school.program_subject WHERE program_id = $1 AND total_hours = 40', [source]), 1);
  await rejects('SELECT school.copy_program($1, $2)', [copy, lotYear], '23514', 'program_copy_year');
  const taken = await programme('COPIE-B');
  await sql(PROGRAM, [a, next, grade, 'COPIE-B', '{"fr": "Déjà là"}', 1000, 'draft']);
  await rejects('SELECT school.copy_program($1, $2)', [taken, next], '23514', 'program_code_taken');
});
// Admissions (SQL 038, FR-CRM-01, FR-CRM-02): prospects on a site, their children, interactions and reminders; the conversion, once.
const PROSPECT = 'INSERT INTO school.lead (tenant_id, site_id, last_name, first_name, phone, source, assigned_to) VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id, status';
const prospect = async (site = siteA, last = 'Prospect', source = 'word_of_mouth', assigned = null) =>
  first(PROSPECT, [a, site, last, 'Famille', '0550 12 34 56', source, assigned]);
const CHILD = 'INSERT INTO school.lead_child (tenant_id, lead_id, first_name, last_name, level_id) VALUES ($1, $2, $3, $4, $5) RETURNING id';
const REMINDER = 'INSERT INTO school.lead_reminder (tenant_id, lead_id, due_at, body) VALUES ($1, $2, $3, $4) RETURNING id';
const reason = text => sql("SELECT set_config('app.reason', $1, false)", [text]);
// User 1 also receives the families (the front office's permissions, section 3.2; this school's front office lost one above).
await as(null);
const admissions = (await first("INSERT INTO school.role (tenant_id, code, kind, labels) VALUES ($1, 'admissions', 'internal', '{\"fr\": \"Admissions\"}') RETURNING id", [a])).id;
for (const permission of ['crm.lead.read', 'crm.lead.create', 'crm.lead.update', 'crm.lead.convert'])
  await sql('INSERT INTO school.role_permission (tenant_id, role_id, permission_code) VALUES ($1, $2, $3)', [a, admissions, permission]);
await sql("INSERT INTO school.role_assignment (tenant_id, user_id, role_id, scope_type) VALUES ($1, 1, $2, 'tenant')", [a, admissions]);
await as('school_runtime', a, 1);
await check('a prospect has a source and status of the school\'s lists; lost with a reason, registered by conversion only, reopened with a reason (FR-CRM-01 AC2)', async () => {
  assert.deepEqual((await sql("SELECT kind, count(*)::int AS n FROM school.lead_option WHERE tenant_id = $1 GROUP BY kind ORDER BY kind", [a])).rows,
    [{ kind: 'source', n: 4 }, { kind: 'status', n: 6 }]);
  const family = await prospect();
  assert.equal(family.status, 'new');
  await rejects(PROSPECT, [a, siteA, 'Inconnu', 'X', '0550', 'radio', null], '23514', 'lead_option_unknown');
  await rejects("INSERT INTO school.lead (tenant_id, site_id, last_name, phone, source, status) VALUES ($1, $2, 'Ferme', '0550', 'other', 'registered')",
    [a, siteA], '23514', 'status_transition_refused');
  await rejects("INSERT INTO school.lead (tenant_id, site_id, last_name, source) VALUES ($1, $2, 'Sans contact', 'other')", [a, siteA], '23514');
  // The school's own step, between two of the publisher's; the pipeline's own statuses stay.
  const callBack = (await first("INSERT INTO school.lead_option (tenant_id, kind, code, labels, sort_order) VALUES ($1, 'status', 'call_back', '{\"fr\": \"À rappeler\"}', 2) RETURNING id", [a])).id;
  await sql("UPDATE school.lead SET status = 'call_back' WHERE id = $1", [family.id]);
  await sql('UPDATE school.lead_option SET archived_at = now() WHERE id = $1', [callBack]);
  await rejects("UPDATE school.lead SET status = 'call_back' WHERE id = $1", [(await prospect()).id], '23514', 'lead_option_unknown');
  await rejects("UPDATE school.lead_option SET archived_at = now() WHERE tenant_id = $1 AND kind = 'status' AND code = 'lost'", [a], '23514', 'lead_status_reserved');
  // AC2: closed without its reason, refused.
  await rejects("UPDATE school.lead SET status = 'lost' WHERE id = $1", [family.id], '23514', 'lost_reason_required');
  await rejects("UPDATE school.lead SET status = 'lost', lost_reason = '  ' WHERE id = $1", [family.id], '23514', 'lost_reason_required');
  await sql("UPDATE school.lead SET status = 'lost', lost_reason = 'Trop loin du domicile' WHERE id = $1", [family.id]);
  assert.ok((await first('SELECT closed_at FROM school.lead WHERE id = $1', [family.id])).closed_at);
  await rejects("UPDATE school.lead SET phone = '0660' WHERE id = $1", [family.id], '23514', 'lead_closed');
  await rejects("UPDATE school.lead SET status = 'contacted' WHERE id = $1", [family.id], '23514', 'reopen_reason_required');
  await reason('La famille a rappelé');
  await sql("UPDATE school.lead SET status = 'contacted' WHERE id = $1", [family.id]);
  await reason('');
  assert.deepEqual(await first('SELECT lost_reason, closed_at FROM school.lead WHERE id = $1', [family.id]), { lost_reason: null, closed_at: null });
  await rejects("UPDATE school.lead SET status = 'registered' WHERE id = $1", [family.id], '23514', 'lead_not_converted');
});
await check('a prospect is assigned to a member who reads its site; its interactions never change; its reminders are for later, cancelled when it closes', async () => {
  const office = await scopedAccount('internal', 'site', [staffSiteB], ['crm.lead.read', 'crm.lead.create', 'crm.lead.update']);
  await as('school_runtime', a, 1);
  await rejects(PROSPECT, [a, siteA, 'Ailleurs', 'X', '0550', 'other', office.user], '23514', 'lead_assignee_invalid');
  await rejects(PROSPECT, [a, siteA, 'Suspendu', 'X', '0550', 'other', 5], '23514', 'lead_assignee_invalid');
  const annex = await prospect(staffSiteB, 'Annexe', 'poster', office.user);
  assert.ok((await sql('SELECT school.lead_assignees($1) AS u', [staffSiteB])).rows.some(r => Number(r.u) === Number(office.user)));
  assert.ok(!(await sql('SELECT school.lead_assignees($1) AS u', [siteA])).rows.some(r => Number(r.u) === Number(office.user)));
  const said = (await first("INSERT INTO school.lead_interaction (tenant_id, lead_id, kind, body) VALUES ($1, $2, 'call', 'Premier appel') RETURNING id", [a, annex.id])).id;
  await rejects("UPDATE school.lead_interaction SET body = 'Autre chose' WHERE id = $1", [said], '42501');
  await rejects("INSERT INTO school.lead_interaction (tenant_id, lead_id, kind, occurred_at, body) VALUES ($1, $2, 'visit', now() + interval '1 day', 'Demain')",
    [a, annex.id], '23514', 'interaction_future');
  await rejects(REMINDER, [a, annex.id, '2020-01-01T10:00:00Z', 'Trop tard'], '23514', 'reminder_past');
  const later = (await first(REMINDER, [a, annex.id, `${tomorrow}T10:00:00Z`, 'Rappeler pour la visite'])).id;
  const done = (await first(REMINDER, [a, annex.id, `${tomorrow}T11:00:00Z`, 'Envoyer la brochure'])).id;
  await sql("UPDATE school.lead_reminder SET status = 'done' WHERE id = $1", [done]);
  await rejects("UPDATE school.lead_reminder SET status = 'planned' WHERE id = $1", [done], '23514', 'status_transition_refused');
  await rejects("UPDATE school.lead_reminder SET due_at = due_at + interval '1 hour' WHERE id = $1", [later], '42501');
  await sql("UPDATE school.lead SET status = 'lost', lost_reason = 'Inscrit ailleurs' WHERE id = $1", [annex.id]);
  assert.deepEqual((await sql('SELECT status FROM school.lead_reminder WHERE lead_id = $1 ORDER BY due_at', [annex.id])).rows.map(r => r.status), ['cancelled', 'done']);
  await rejects(REMINDER, [a, annex.id, `${tomorrow}T12:00:00Z`, 'Encore'], '23514', 'lead_closed');
  // The site's grant: its own prospects, read, recorded and followed; none elsewhere.
  await as('school_runtime', a, office.user);
  assert.ok((await sql('SELECT id, site_id FROM school.lead')).rows.every(r => r.site_id === staffSiteB));
  assert.ok((await first(PROSPECT, [a, staffSiteB, 'Local', 'X', '0770', 'other', null])).id);
  await rejects(PROSPECT, [a, siteA, 'Siege', 'X', '0770', 'other', null], '42501');
  await as('school_runtime', a, 1);
});
await check('a due reminder notifies its prospect\'s assignee once, never someone who no longer reads it (FR-CRM-01 AC1)', async () => {
  const follower = await scopedAccount('internal', 'tenant', null, ['crm.lead.read', 'crm.lead.update']);
  const gone = await scopedAccount('internal', 'tenant', null, ['crm.lead.read']);
  await as('school_runtime', a, 1);
  const followed = await prospect(siteA, 'Suivi', 'other', follower.user);
  const due = (await first(REMINDER, [a, followed.id, new Date().toISOString(), 'Rappeler la famille'])).id;
  const orphan = await prospect(siteA, 'Orphelin', 'other', gone.user);
  await sql(REMINDER, [a, orphan.id, new Date().toISOString(), 'Personne pour lire']);
  await as(null);
  await sql("UPDATE school.membership SET status = 'suspended' WHERE tenant_id = $1 AND user_id = $2", [a, gone.user]);
  // The run, as the scheduler starts it: outside any school.
  await as('school_runtime', null, null);
  assert.ok(Number((await first('SELECT platform.notify_due_reminders(100) AS n')).n) >= 1);
  assert.equal(Number((await first('SELECT platform.notify_due_reminders(100) AS n')).n), 0, 'once');
  await as('school_runtime', a, follower.user);
  assert.deepEqual((await sql("SELECT event, data FROM school.notification WHERE event = 'crm.reminder_due'")).rows,
    [{ event: 'crm.reminder_due', data: { lead_id: followed.id, reminder_id: due } }]);
  await as(null);
  assert.equal(await count('SELECT count(*) n FROM school.notification WHERE user_id = $1', [gone.user]), 0);
  assert.equal(await count('SELECT count(*) n FROM school.lead_reminder_notice WHERE tenant_id = $1 AND user_id IS NULL', [a]), 1);
  await as('school_runtime', a, 1);
});
await check('a prospect\'s child is converted once into its pupil and enrolment, unless the prospect was lost (FR-CRM-02 AC1)', async () => {
  const program = await programme('CRM-CONV');
  const family = await prospect(siteA, 'Conversion');
  const child = (await first(CHILD, [a, family.id, 'Yasmine', 'Conversion', grade])).id;
  const sibling = (await first(CHILD, [a, family.id, 'Rayan', 'Conversion', grade])).id;
  const pupil = (await newAdult('Conversion', 'Yasmine')).id;
  const other = (await newAdult('Conversion', 'Autre')).id;
  const enrolment = await enroll(pupil, program);
  const CONVERT = 'UPDATE school.lead_child SET student_id = $2, enrollment_id = $3, conversion_key = $4 WHERE id = $1';
  await rejects(CONVERT, [child, other, enrolment, uuid()], '23514', 'lead_conversion_mismatch');
  await sql(CONVERT, [child, pupil, enrolment, uuid()]);
  await sql("UPDATE school.lead SET status = 'registered' WHERE id = $1", [family.id]);
  assert.ok((await first('SELECT converted_at FROM school.lead_child WHERE id = $1', [child])).converted_at);
  await rejects(CONVERT, [child, other, await enroll(other, program), uuid()], '23514', 'lead_already_converted');
  await rejects("UPDATE school.lead_child SET first_name = 'Autre' WHERE id = $1", [child], '23514', 'lead_already_converted');
  // A registered prospect's other child is still converted; a lost one's is not.
  const second = (await newAdult('Conversion', 'Rayan')).id;
  await sql(CONVERT, [sibling, second, await enroll(second, program), uuid()]);
  const lost = await prospect(siteA, 'Perdue');
  const lostChild = (await first(CHILD, [a, lost.id, 'Lina', null, null])).id;
  await sql("UPDATE school.lead SET status = 'lost', lost_reason = 'Plus de nouvelles' WHERE id = $1", [lost.id]);
  const third = (await newAdult('Perdue', 'Lina')).id;
  await rejects(CONVERT, [lostChild, third, await enroll(third, program), uuid()], '23514', 'lead_closed');
  await rejects(CHILD, [a, lost.id, 'Nouveau', null, null], '23514', 'lead_closed');
  // AC3: a prospect is no pupil, in no group's headcount: only its converted children are enrolled.
  assert.equal(await count('SELECT count(*) n FROM school.enrollment WHERE student_id IN (SELECT student_id FROM school.lead_child WHERE lead_id = $1)', [family.id]), 2);
});
await check('a prospect lost for longer than the school\'s duration is anonymised by the retention run, with its children and what was said (NFR-DATA-03)', async () => {
  const lost = await prospect(siteA, 'Retention');
  const child = (await first(CHILD, [a, lost.id, 'Yanis', 'Retention', grade])).id;
  await sql("INSERT INTO school.lead_interaction (tenant_id, lead_id, kind, body) VALUES ($1, $2, 'call', 'Le père travaille à Oran')", [a, lost.id]);
  await sql(REMINDER, [a, lost.id, `${tomorrow}T09:00:00Z`, 'Rappeler le père']);
  await sql("UPDATE school.lead SET status = 'lost', lost_reason = 'Déménagement à Oran' WHERE id = $1", [lost.id]);
  const recent = await prospect(siteA, 'Recent');
  await sql("UPDATE school.lead SET status = 'lost', lost_reason = 'Trop cher' WHERE id = $1", [recent.id]);
  const open = await prospect(siteA, 'Ouvert');
  await as(null);
  await sql('ALTER TABLE school.lead DISABLE TRIGGER USER');
  await sql("UPDATE school.lead SET closed_at = now() - interval '25 months' WHERE id = $1", [lost.id]);
  await sql('ALTER TABLE school.lead ENABLE TRIGGER USER');
  await as('school_runtime', a, 1);
  assert.equal((await sql('SELECT due FROM school.retention_due() WHERE category = $1', ['lead'])).rows[0].due, 1);
  await as('school_runtime', null, null);
  const runs = (await sql('SELECT tenant_id, leads FROM platform.apply_lead_retention(100)')).rows;
  assert.deepEqual(runs.find(r => r.tenant_id === a), { tenant_id: a, leads: 1 });
  assert.equal((await sql('SELECT * FROM platform.apply_lead_retention(100)')).rows.length, 0, 'once');
  await as(null);
  assert.deepEqual(await first('SELECT last_name, first_name, phone, email, lost_reason, anonymised_at IS NOT NULL AS done, status, source FROM school.lead WHERE id = $1', [lost.id]),
    { last_name: 'ANONYME', first_name: null, phone: null, email: null, lost_reason: 'ANONYME', done: true, status: 'lost', source: 'word_of_mouth' });
  assert.deepEqual(await first('SELECT first_name, last_name, birth_date, level_id FROM school.lead_child WHERE id = $1', [child]),
    { first_name: 'ANONYME', last_name: null, birth_date: null, level_id: grade });
  assert.deepEqual((await sql('SELECT body FROM school.lead_interaction WHERE lead_id = $1 UNION ALL SELECT body FROM school.lead_reminder WHERE lead_id = $1', [lost.id])).rows.map(r => r.body),
    ['ANONYME', 'ANONYME']);
  // A prospect lost lately, or still open, is kept as it is; the journal keeps the run's counts.
  assert.equal(await count('SELECT count(*) n FROM school.lead WHERE id = ANY ($1) AND anonymised_at IS NULL', [[recent.id, open.id]]), 2);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE tenant_id = $1 AND action = 'RETENTION' AND after ? 'leads'", [a]), 1);
  await as('school_runtime', a, 1);
  await reason('La famille revient');
  await rejects("UPDATE school.lead SET status = 'contacted' WHERE id = $1", [lost.id], '23514', 'lead_anonymised');
  await reason('');
});
await check('an imported pupil and guardian are anonymised in their turn, their file\'s reference with them (NFR-DATA-03, SQL 043)', async () => {
  await as('school_runtime', a, 1);
  const pupil = (await first(STUDENT_REF, [a, 'Importe', 'Retenu', null, null, '1990-01-01', pupilFields, 'RET-IMPORT-1'])).id;
  await sql("UPDATE school.student SET status = 'former' WHERE id = $1", [pupil]);
  await sql('UPDATE school.student SET archived_at = now() WHERE id = $1', [pupil]);
  await backdate('student', pupil, { archived_at: new Date(Date.now() - 30 * 365.25 * 864e5).toISOString() });
  await as('school_runtime', null, null);
  await sql(RETAIN, [a]);
  await as(null);
  assert.deepEqual(await first('SELECT external_ref, last_name_lat, anonymised_at IS NOT NULL AS done FROM school.student WHERE id = $1', [pupil]),
    { external_ref: null, last_name_lat: 'ANONYME', done: true });
  // Outside the run, a reference still never changes.
  await as('school_runtime', a, 1);
  const kept = (await first(STUDENT_REF, [a, 'Importe', 'Garde', null, null, '1990-01-01', pupilFields, 'RET-IMPORT-2'])).id;
  await rejects("UPDATE school.student SET external_ref = 'AUTRE' WHERE id = $1", [kept]);
});
await check('a member of staff archived for longer than the school\'s duration is anonymised with their contracts, leaves and days off (FR-STF-01, NFR-DATA-03)', async () => {
  await as('school_runtime', a, 1);
  const member = await newStaff('Retenu', 'Karim');
  await sql(CONTRACT, [a, member.id, 'permanent', '2015-09-01', '2016-06-30', 18, 'Ancien enseignant de Blida']);
  await sql(LEAVE, [a, member.id, 'sick', '2016-01-10', '2016-01-12', 'Opération du genou']);
  await sql("UPDATE school.staff SET status = 'left', left_on = '2016-06-30' WHERE id = $1", [member.id]);
  await sql('UPDATE school.staff SET archived_at = now() WHERE id = $1', [member.id]);
  await backdate('staff', member.id, { archived_at: new Date(Date.now() - 11 * 365.25 * 864e5).toISOString() });
  await as('school_runtime', a, 1);
  assert.equal((await first("SELECT due FROM school.retention_due() WHERE category = 'staff'")).due, 1);
  await as('school_runtime', null, null);
  const runs = (await sql('SELECT tenant_id, staff FROM platform.apply_staff_retention(100)')).rows;
  assert.deepEqual(runs.find(r => r.tenant_id === a), { tenant_id: a, staff: 1 });
  assert.equal((await sql('SELECT * FROM platform.apply_staff_retention(100)')).rows.length, 0, 'once');
  await as(null);
  assert.deepEqual(await first('SELECT last_name_lat, first_name_lat = staff_number AS numbered, phone, email, user_id, anonymised_at IS NOT NULL AS done, status FROM school.staff WHERE id = $1', [member.id]),
    { last_name_lat: 'ANONYME', numbered: true, phone: null, email: null, user_id: null, done: true, status: 'left' });
  assert.deepEqual((await sql('SELECT observation, weekly_hours::text AS h FROM school.staff_contract WHERE staff_id = $1', [member.id])).rows, [{ observation: null, h: '18.00' }]);
  assert.deepEqual((await sql('SELECT observation, leave_type FROM school.staff_leave WHERE staff_id = $1', [member.id])).rows, [{ observation: null, leave_type: 'sick' }]);
  assert.equal(await count("SELECT count(*) n FROM school.audit_log WHERE tenant_id = $1 AND action = 'RETENTION' AND after ? 'staff'", [a]), 1);
  await as('school_runtime', a, 1);
});
// A pupil's history (SQL 047, FR-ELV-01): their record's own journal entries, for whoever reads the pupil, without values.
await check('a pupil\'s history gives their record\'s changes, states and reasons to whoever reads the pupil, never the values (FR-ELV-01, BR-17)', async () => {
  await as('school_runtime', a, 1);
  const pupil = (await newAdult('Historique', 'Amel')).id;
  await sql("UPDATE school.student SET phone = '0550 77 88 99' WHERE id = $1", [pupil]);
  await sql("SELECT set_config('app.reason', 'Impayés répétés', false)");
  await sql("UPDATE school.student SET status = 'blocked', status_reason = 'Impayés répétés' WHERE id = $1", [pupil]);
  await sql("SELECT set_config('app.reason', '', false)");
  const history = (await sql('SELECT action, fields, status, reason, actor_id::int AS actor FROM school.student_history($1)', [pupil])).rows;
  assert.deepEqual(history.map(h => [h.action, h.status, h.reason]), [['UPDATE', 'blocked', 'Impayés répétés'], ['UPDATE', null, null], ['INSERT', 'active', null]]);
  assert.deepEqual(history[1].fields, ['phone']);
  assert.ok(!JSON.stringify(history).includes('0550 77 88 99'), 'no value');
  // A reader of another site's pupils only, or without the pupils, reads nothing of it.
  const elsewhere = await scopedAccount('internal', 'site', [staffSiteB], ['student.record.read']);
  await as('school_runtime', a, elsewhere.user);
  await rejects('SELECT * FROM school.student_history($1)', [pupil], '42501', 'permission_denied');
  await as('school_runtime', a, 1);
});
// What an enrolment owes (SQL 048, FR-FIN-01): adjustments with their reason, approved beyond the threshold by someone else; schedules whose sum is what is owed.
const ADJUST = 'INSERT INTO school.adjustment (tenant_id, enrollment_id, kind, amount, reason, idempotency_key) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id, status';
await check('an adjustment needs its reason; beyond the threshold the direction approves it, never its author (FR-FIN-01 AC2, BR-08)', async () => {
  const accountant = await scopedAccount('internal', 'tenant', null, ['finance.discount.grant', 'finance.schedule.manage', 'finance.payment.read', 'enrollment.enrollment.read']);
  const director = await scopedAccount('internal', 'tenant', null, ['finance.discount.approve', 'finance.payment.read', 'enrollment.enrollment.read']);
  await as('school_runtime', a, 1);
  const e = await enroll((await newAdult('Remise', 'Nadia')).id, await programme('REMISE', 120000));
  await as('school_runtime', a, accountant.user);
  const due = async () => (await first('SELECT school.enrollment_due($1)::text AS d', [e])).d;
  assert.equal(await due(), '120000.00');
  await rejects(ADJUST, [a, e, 'discount', 10000, '  ', uuid()], '23514', 'reason_required');
  assert.equal((await first(ADJUST, [a, e, 'discount', 10000, 'Fratrie', uuid()])).status, 'approved', 'within 10 %');
  assert.equal(await due(), '110000.00');
  const beyond = await first(ADJUST, [a, e, 'discount', 5000, 'Situation sociale', uuid()]);
  assert.equal(beyond.status, 'pending', 'the two together beyond 10 %');
  assert.equal(await due(), '110000.00');
  assert.equal((await sql("UPDATE school.adjustment SET status = 'approved' WHERE id = $1", [beyond.id])).affectedRows ?? 0, 0, 'not for the accountant to approve');
  await rejects(ADJUST, [a, e, 'debt_waiver', 106000, 'Tout', uuid()], '23514', 'adjustment_exceeds_due');
  assert.equal((await first(ADJUST, [a, e, 'penalty', 2000, 'Retard', uuid()])).status, 'approved');
  // The direction decides; never the author, even with both permissions.
  const both = await scopedAccount('internal', 'tenant', null, ['finance.discount.grant', 'finance.discount.approve', 'finance.payment.read', 'enrollment.enrollment.read']);
  await as('school_runtime', a, both.user);
  const own = await first(ADJUST, [a, e, 'discount', 3000, 'Bourse', uuid()]);
  await rejects("UPDATE school.adjustment SET status = 'approved' WHERE id = $1", [own.id], '23514', 'segregation_of_duties');
  await as('school_runtime', a, director.user);
  await sql("UPDATE school.adjustment SET status = 'approved' WHERE id = $1", [beyond.id]);
  await rejects("UPDATE school.adjustment SET status = 'rejected' WHERE id = $1", [own.id], '23514', 'reason_required');
  await sql("UPDATE school.adjustment SET status = 'rejected', decision_reason = 'Dossier incomplet' WHERE id = $1", [own.id]);
  await rejects("UPDATE school.adjustment SET status = 'rejected', decision_reason = 'Finalement non' WHERE id = $1", [beyond.id], '23514', 'adjustment_decided');
  await rejects(ADJUST, [a, e, 'discount', 100, 'Pas le droit', uuid()], '42501');
  assert.equal(await due(), '107000.00', '120000 - 10000 - 5000 + 2000');
  // Whoever reads no finance reads no amount.
  await as('school_runtime', a, 4);
  await rejects('SELECT school.enrollment_due($1)', [e], '42501', 'permission_denied');
  await as('school_runtime', a, 1);
});
await check('a schedule\'s instalments add up to what is owed, in order; a new version says why; neither is ever changed (FR-FIN-01 AC1, BR-04, BR-05)', async () => {
  const accountant = await scopedAccount('internal', 'tenant', null, ['finance.discount.grant', 'finance.schedule.manage', 'finance.payment.read', 'enrollment.enrollment.read']);
  await as('school_runtime', a, 1);
  const e = await enroll((await newAdult('Echeance', 'Rayan')).id, await programme('ECHEANCE', 100000.5));
  await as('school_runtime', a, accountant.user);
  const SET = 'SELECT school.set_schedule($1, $2::jsonb, $3, $4) AS id';
  const lines = (...items) => JSON.stringify(items.map(([due_date, amount]) => ({ due_date, amount })));
  await rejects(SET, [e, lines(['2026-09-15', '50000.00'], ['2026-12-15', '50000.00']), null, uuid()], '23514', 'schedule_sum_mismatch');
  await rejects(SET, [e, lines(['2026-12-15', '50000.25'], ['2026-09-15', '50000.25']), null, uuid()], '23514', 'schedule_invalid');
  await rejects(SET, [e, lines(['2026-09-15', '100000.505']), null, uuid()], '23514', 'schedule_invalid');
  const key = uuid();
  const first1 = (await first(SET, [e, lines(['2026-09-15', '50000.25'], ['2026-12-15', '50000.25']), null, key])).id;
  assert.equal((await first(SET, [e, lines(['2026-09-15', '50000.25'], ['2026-12-15', '50000.25']), null, key])).id, first1, 'the same key, the same version');
  await rejects(SET, [e, lines(['2026-09-15', '100000.50']), null, uuid()], '23514', 'reason_required');
  // A discount changes what is owed: the schedule follows, in a new version.
  await sql(ADJUST, [a, e, 'discount', 500.5, 'Parrainage', uuid()]);
  await rejects(SET, [e, lines(['2026-09-15', '50000.25'], ['2026-12-15', '50000.25']), 'Remise', uuid()], '23514', 'schedule_sum_mismatch');
  const second = (await first(SET, [e, lines(['2026-09-15', '33166.66'], ['2026-12-15', '33166.66'], ['2027-03-15', '33166.68']), 'Remise de parrainage', uuid()])).id;
  assert.equal((await first('SELECT school.current_schedule($1) AS id', [e])).id, second);
  assert.deepEqual((await sql('SELECT version, total::text AS t FROM school.payment_schedule WHERE enrollment_id = $1 ORDER BY version', [e])).rows,
    [{ version: 1, t: '100000.50' }, { version: 2, t: '99500.00' }]);
  await rejects('UPDATE school.installment SET amount = 1 WHERE schedule_id = $1', [second], '42501');
  await rejects('DELETE FROM school.payment_schedule WHERE id = $1', [first1], '42501');
  await as('school_runtime', a, 1);
});
// Payments (SQL 049, FR-FIN-02, FR-FIN-03): recorded once per key, validated by someone else, never changed after; reversed by a linked reversal.
const RECORD = 'SELECT school.record_payment($1, $2, $3::date, $4, $5, $6, $7) AS id';
const BALANCE = 'SELECT due::text AS due, paid::text AS paid, pending::text AS pending, balance::text AS balance, state FROM school.payment_balance($1)';
const payment = async id => first('SELECT status, reference, delegated, overpayment, self_validated, amount::text AS amount FROM school.payment WHERE id = $1', [id]);
await check('a payment enters the balance once validated by someone else, never beyond what is left, the pedagogical state untouched (FR-FIN-02, FR-FIN-03 AC3, TST-09, BR-08)', async () => {
  const accountant = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.create', 'finance.payment.validate', 'finance.payment.reverse', 'enrollment.enrollment.read']);
  const cashier = await scopedAccount('internal', 'tenant', null, ['finance.payment.create.delegated', 'enrollment.enrollment.read']);
  const validator = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.validate', 'enrollment.enrollment.read']);
  await as('school_runtime', a, 1);
  const e = await enroll((await newAdult('Paiement', 'Sofiane')).id, await programme('PAIEMENT', 100000));
  // The front office's cash desk: recorded, waiting for the finance.
  await as('school_runtime', a, cashier.user);
  const key = uuid();
  const cash = (await first(RECORD, [e, '30000', 'today', 'cash', 'Guichet', false, key])).id;
  assert.equal((await first(RECORD, [e, '30000', 'today', 'cash', 'Guichet', false, key])).id, cash, 'the same key, the same payment');
  await rejects(RECORD, [e, '30001', 'today', 'cash', null, false, key], '23514', 'idempotency_key_reused');
  const recorded = await payment(cash);
  assert.equal(recorded.status, 'pending_validation');
  assert.ok(recorded.delegated);
  assert.match(recorded.reference, /^PAY-\d{4}-\d{6}$/);
  await rejects(RECORD, [e, '100', 'tomorrow', 'cash', null, false, uuid()], '23514', 'payment_future_date');
  await rejects(RECORD, [e, '100', 'today', 'bitcoin', null, false, uuid()], '23514', 'payment_method_unknown');
  await rejects(RECORD, [e, '0', 'today', 'cash', null, false, uuid()], '23514');
  // Never beyond what is left, waiting payments counted, but an overpayment entered as such.
  await rejects(RECORD, [e, '70000.01', 'today', 'cheque', 'CHQ 1', false, uuid()], '23514', 'payment_exceeds_balance');
  const over = (await first(RECORD, [e, '70000.01', 'today', 'cheque', 'CHQ 1', true, uuid()])).id;
  assert.ok((await payment(over)).overpayment);
  assert.deepEqual(await first(BALANCE, [e]), { due: '100000.00', paid: '0.00', pending: '100000.01', balance: '100000.00', state: 'unpaid' });
  // Its author withdraws it, saying why, before its validation; nobody else does.
  await rejects("UPDATE school.payment SET status = 'cancelled' WHERE id = $1", [over], '23514', 'reason_required');
  await sql("UPDATE school.payment SET status = 'cancelled', cancel_reason = 'Chèque rendu' WHERE id = $1", [over]);
  await rejects("UPDATE school.payment SET status = 'validated' WHERE id = $1", [cash], '42501', 'permission_denied');
  await as('school_runtime', a, accountant.user);
  await rejects("UPDATE school.payment SET amount = 25000 WHERE id = $1", [cash], '23514', 'payment_not_author');
  await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [cash]);
  assert.deepEqual(await first(BALANCE, [e]), { due: '100000.00', paid: '30000.00', pending: '0.00', balance: '70000.00', state: 'partially_paid' });
  assert.equal((await first('SELECT status FROM school.enrollment WHERE id = $1', [e])).status, 'pending', 'TST-09: the pedagogical state stays');
  // TST-11: validated, it never changes, neither by its validator nor by anyone; nothing is deleted.
  await rejects("UPDATE school.payment SET amount = 1 WHERE id = $1", [cash], '23514', 'payment_immutable');
  await rejects("UPDATE school.payment SET status = 'cancelled', cancel_reason = 'Trop tard' WHERE id = $1", [cash], '23514', 'payment_immutable');
  await rejects('DELETE FROM school.payment WHERE id = $1', [cash], '42501');
  await rejects("UPDATE school.payment SET status = 'pending_validation' WHERE id = $1", [over], '23514', 'payment_immutable');
  // AC3: its own payment, its author does not validate, unless the school allows it, which is marked.
  const own = (await first(RECORD, [e, '20000', 'today', 'transfer', 'VIR 12', false, uuid()])).id;
  await rejects("UPDATE school.payment SET status = 'validated' WHERE id = $1", [own], '23514', 'segregation_of_duties');
  await as('school_runtime', a, 1);
  await sql("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'finance.allow_self_validation', 'true')", [a]);
  await as('school_runtime', a, accountant.user);
  await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [own]);
  assert.ok((await payment(own)).self_validated);
  await as('school_runtime', a, 1);
  await sql("UPDATE school.setting SET value = 'false' WHERE tenant_id = $1 AND key = 'finance.allow_self_validation'", [a]);
  // AC2: a reversal, with its reason, validated by someone else even then, takes the payment back; both stay.
  await as('school_runtime', a, accountant.user);
  const REVERSE = 'SELECT school.reverse_payment($1, $2, $3) AS id';
  await rejects(REVERSE, [cash, ' ', uuid()], '23514', 'reason_required');
  const reverseKey = uuid();
  const reversal = (await first(REVERSE, [cash, 'Erreur de saisie', reverseKey])).id;
  assert.equal((await first(REVERSE, [cash, 'Erreur de saisie', reverseKey])).id, reversal);
  await rejects(REVERSE, [cash, 'Encore', uuid()], '23514', 'payment_not_reversible');
  assert.equal((await first(BALANCE, [e])).paid, '50000.00', 'a waiting reversal takes nothing back');
  await rejects("UPDATE school.payment SET status = 'validated' WHERE id = $1", [reversal], '23514', 'segregation_of_duties');
  await rejects("UPDATE school.payment SET status = 'reversed' WHERE id = $1", [cash], '23514', 'payment_immutable');
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [reversal]);
  assert.equal((await payment(cash)).status, 'reversed');
  assert.deepEqual(await first(BALANCE, [e]), { due: '100000.00', paid: '20000.00', pending: '0.00', balance: '80000.00', state: 'partially_paid' });
  assert.equal(await count('SELECT count(*) n FROM school.payment WHERE enrollment_id = $1', [e]), 4, 'nothing deleted');
  await as('school_runtime', a, accountant.user);
  await rejects(REVERSE, [cash, 'Deux fois', uuid()], '23514', 'payment_not_reversible');
  // FR-INS-04: what is paid opens the courses as far as the school asks.
  assert.equal((await first("SELECT school.enrollment_paid($1, 'first_installment') AS p", [e])).p, true);
  assert.equal((await first("SELECT school.enrollment_paid($1, 'full') AS p", [e])).p, false);
  // A suspended enrolment is paid, and stays suspended (FR-INS-02 AC2); a cancelled one is not paid any more.
  await as('school_runtime', a, 1);
  await sql("UPDATE school.enrollment SET status = 'validated' WHERE id = $1", [e]);
  await sql("UPDATE school.enrollment SET status = 'suspended', status_reason = 'Absence prolongée' WHERE id = $1", [e]);
  await as('school_runtime', a, accountant.user);
  const later = (await first(RECORD, [e, '80000', 'today', 'cash', null, false, uuid()])).id;
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [later]);
  assert.equal((await first(BALANCE, [e])).state, 'paid');
  assert.equal((await first("SELECT school.enrollment_paid($1, 'full') AS p", [e])).p, true);
  assert.equal((await first('SELECT status FROM school.enrollment WHERE id = $1', [e])).status, 'suspended');
  await as('school_runtime', a, 1);
  await sql("UPDATE school.enrollment SET status = 'cancelled', status_reason = 'Départ' WHERE id = $1", [e]);
  await as('school_runtime', a, accountant.user);
  await rejects(RECORD, [e, '10', 'today', 'cash', null, true, uuid()], '23514', 'enrollment_cancelled');
  // Whoever reads no finance reads no payment, nor what is paid.
  await as('school_runtime', a, 4);
  assert.equal(await count('SELECT count(*) n FROM school.payment WHERE enrollment_id = $1', [e]), 0);
  await rejects(BALANCE, [e], '42501', 'permission_denied');
  await as('school_runtime', a, 1);
});
await check('a waiting payment is corrected by its author within what is left; its reference is never given again (FR-FIN-02 AC3, FR-FIN-03)', async () => {
  const cashier = await scopedAccount('internal', 'tenant', null, ['finance.payment.create.delegated', 'enrollment.enrollment.read']);
  await as('school_runtime', a, 1);
  const e = await enroll((await newAdult('Correction', 'Lina')).id, await programme('CORRIGE', 50000));
  await as('school_runtime', a, cashier.user);
  const id = (await first(RECORD, [e, '15000', 'today', 'cash', null, false, uuid()])).id;
  const reference = (await payment(id)).reference;
  await sql("UPDATE school.payment SET amount = 25000, method_code = 'ccp', external_ref = 'CCP 0099' WHERE id = $1", [id]);
  await rejects('UPDATE school.payment SET amount = 50000.01 WHERE id = $1', [id], '23514', 'payment_exceeds_balance');
  await rejects("UPDATE school.payment SET reference = 'PAY-2000-000001' WHERE id = $1", [id], '42501');
  await sql("UPDATE school.payment SET status = 'cancelled', cancel_reason = 'Erreur d''élève' WHERE id = $1", [id]);
  const next = (await first(RECORD, [e, '25000', 'today', 'ccp', 'CCP 0099', false, uuid()])).id;
  assert.notEqual((await payment(next)).reference, reference);
  assert.equal(await count('SELECT count(*) n FROM school.payment WHERE reference = $1', [reference]), 1);
  await as('school_runtime', a, 1);
});
// Refunds and overpayments (SQL 050, FR-FIN-04): asked with their reason, approved by someone else, executed once approved; a credit decided explicitly.
await check('a refund is asked, approved by someone else, then executed within what is paid; a credit is refunded, carried over or kept (FR-FIN-04 AC1, AC2, BR-08)', async () => {
  const finance = ['finance.payment.read', 'finance.payment.create', 'finance.refund.request', 'finance.discount.grant', 'enrollment.enrollment.read'];
  const accountant = await scopedAccount('internal', 'tenant', null, finance);
  const validator = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.validate', 'enrollment.enrollment.read']);
  const director = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.refund.approve', 'enrollment.enrollment.read']);
  const both = await scopedAccount('internal', 'tenant', null, [...finance, 'finance.refund.approve']);
  await as('school_runtime', a, 1);
  const pupil = (await newAdult('Rembourse', 'Walid')).id;
  const e = await enroll(pupil, await programme('REMB-A', 50000));
  const other = await enroll(pupil, await programme('REMB-B', 30000));
  const stranger = await enroll((await newAdult('Autre', 'Eleve')).id, await programme('REMB-C', 30000));
  const validate = async id => { await as('school_runtime', a, validator.user); await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [id]); };
  await as('school_runtime', a, accountant.user);
  await validate((await first(RECORD, [e, '60000', 'today', 'cash', null, true, uuid()])).id);
  await as('school_runtime', a, accountant.user);
  const BAL = 'SELECT paid::text AS paid, refunded::text AS refunded, refunds_pending::text AS asked, credit::text AS credit, state FROM school.payment_balance($1)';
  assert.deepEqual(await first(BAL, [e]), { paid: '60000.00', refunded: '0.00', asked: '0.00', credit: '10000.00', state: 'paid' });
  const REQUEST = 'SELECT school.request_refund($1, $2, $3, $4, $5, $6) AS id';
  await rejects(REQUEST, [e, 'refund', null, '60000.01', 'Trop', uuid()], '23514', 'refund_exceeds_paid');
  await rejects(REQUEST, [e, 'refund', null, '100', '  ', uuid()], '23514', 'reason_required');
  const key = uuid();
  const refund = (await first(REQUEST, [e, 'refund', null, '4000', 'Trop-perçu', key])).id;
  assert.equal((await first(REQUEST, [e, 'refund', null, '4000', 'Trop-perçu', key])).id, refund, 'the same key, the same refund');
  await rejects(REQUEST, [e, 'refund', null, '56000.01', 'Au-delà', uuid()], '23514', 'refund_exceeds_paid');
  // AC2: never executed without its approval; approved by the direction only, never by the one who asked.
  const EXECUTE = "UPDATE school.refund SET status = 'executed', method_code = 'cash', paid_on = current_date WHERE id = $1";
  await rejects(EXECUTE, [refund], '23514', 'refund_not_approved');
  await rejects("UPDATE school.refund SET status = 'approved' WHERE id = $1", [refund], '42501', 'permission_denied');
  await as('school_runtime', a, both.user);
  const own = (await first(REQUEST, [e, 'refund', null, '100', 'Le sien', uuid()])).id;
  await rejects("UPDATE school.refund SET status = 'approved' WHERE id = $1", [own], '23514', 'segregation_of_duties');
  await as('school_runtime', a, director.user);
  await rejects("UPDATE school.refund SET status = 'rejected' WHERE id = $1", [own], '23514', 'reason_required');
  await sql("UPDATE school.refund SET status = 'rejected', decision_reason = 'Pas justifié' WHERE id = $1", [own]);
  await sql("UPDATE school.refund SET status = 'approved' WHERE id = $1", [refund]);
  await rejects(EXECUTE, [own], '23514', 'refund_not_approved');
  await as('school_runtime', a, accountant.user);
  await sql(EXECUTE, [refund]);
  const executed = await first('SELECT reference, executed_at IS NOT NULL AS done FROM school.refund WHERE id = $1', [refund]);
  assert.match(executed.reference, /^RMB-\d{4}-\d{6}$/);
  assert.deepEqual(await first(BAL, [e]), { paid: '56000.00', refunded: '4000.00', asked: '0.00', credit: '6000.00', state: 'paid' });
  await rejects("UPDATE school.refund SET external_ref = 'Plus tard' WHERE id = $1", [refund], '23514', 'refund_decided');
  await rejects('DELETE FROM school.refund WHERE id = $1', [refund], '42501');
  // A carry-over, to another enrolment of the same pupil only, pays it there once validated by the finance.
  await rejects(REQUEST, [e, 'carry_over', stranger, '1000', 'Report', uuid()], '23514', 'refund_target_invalid');
  const carry = (await first(REQUEST, [e, 'carry_over', other, '3000', 'Report sur la seconde inscription', uuid()])).id;
  await as('school_runtime', a, director.user);
  await sql("UPDATE school.refund SET status = 'approved' WHERE id = $1", [carry]);
  await as('school_runtime', a, accountant.user);
  await sql("UPDATE school.refund SET status = 'executed' WHERE id = $1", [carry]);
  const carried = await first("SELECT id, status, method_code, amount::text AS amount FROM school.payment WHERE carried_from_refund_id = $1", [carry]);
  assert.deepEqual([carried.status, carried.method_code, carried.amount], ['pending_validation', 'carry_over', '3000.00']);
  await rejects("UPDATE school.payment SET status = 'cancelled', cancel_reason = 'Non' WHERE id = $1", [carried.id], '23514', 'payment_carried');
  await rejects(RECORD, [other, '10', 'today', 'carry_over', null, false, uuid()], '23514', 'payment_method_unknown');
  await validate(carried.id);
  await as('school_runtime', a, accountant.user);
  await rejects('SELECT school.reverse_payment($1, $2, $3)', [carried.id, 'Annuler le report', uuid()], '23514', 'payment_carried');
  assert.equal((await first(BAL, [other])).paid, '3000.00');
  assert.deepEqual(await first(BAL, [e]), { paid: '53000.00', refunded: '7000.00', asked: '0.00', credit: '3000.00', state: 'paid' });
  // The rest kept by the school, with its reason, within the credit.
  await rejects(ADJUST, [a, e, 'credit_retained', 3000.01, 'Conservé', uuid()], '23514', 'credit_exceeded');
  assert.equal((await first(ADJUST, [a, e, 'credit_retained', 3000, 'Frais de dossier conservés', uuid()])).status, 'approved');
  assert.deepEqual(await first(BAL, [e]), { paid: '53000.00', refunded: '7000.00', asked: '0.00', credit: '0.00', state: 'paid' });
  // AC1: cancelling a paid enrolment moves no money.
  const moves = 'SELECT (SELECT count(*) FROM school.payment WHERE enrollment_id = $1) + (SELECT count(*) FROM school.refund WHERE enrollment_id = $1) AS n';
  const before = (await first(moves, [other])).n;
  await as('school_runtime', a, 1);
  await sql("UPDATE school.enrollment SET status = 'cancelled', status_reason = 'Abandon' WHERE id = $1", [other]);
  await as('school_runtime', a, accountant.user);
  assert.equal((await first(moves, [other])).n, before);
  assert.equal((await first(BAL, [other])).paid, '3000.00');
  // Whoever reads no finance reads no refund.
  await as('school_runtime', a, 4);
  assert.equal(await count('SELECT count(*) n FROM school.refund WHERE enrollment_id = $1', [e]), 0);
  await as('school_runtime', a, 1);
});
// Receipts (SQL 051, FR-FIN-05): one per validated payment or executed refund, numbered once, content frozen.
const ISSUE = 'SELECT id, issued FROM school.issue_receipt($1, $2)';
await check('a validated payment and an executed refund each get one receipt, numbered once, its content frozen (FR-FIN-05)', async () => {
  const accountant = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.create', 'finance.refund.request', 'enrollment.enrollment.read']);
  const validator = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.validate', 'finance.refund.approve', 'enrollment.enrollment.read']);
  await as('school_runtime', a, 1);
  const e = await enroll((await newAdult('Recu', 'Nour')).id, await programme('RECU', 80000));
  await as('school_runtime', a, accountant.user);
  const id = (await first(RECORD, [e, '30000', 'today', 'cheque', 'CHQ 77', false, uuid()])).id;
  await rejects(ISSUE, [id, null], '23514', 'receipt_not_validated');
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [id]);
  await as('school_runtime', a, accountant.user);
  const issued = await first(ISSUE, [id, null]);
  assert.equal(issued.issued, true);
  const again = await first(ISSUE, [id, null]);
  assert.deepEqual([again.id, again.issued], [issued.id, false], 'printed again, the same receipt');
  const receipt = await first('SELECT number, locale, content FROM school.receipt WHERE id = $1', [issued.id]);
  assert.match(receipt.number, /^REC-\d{4}-\d{6}$/);
  assert.equal(receipt.locale, 'fr');
  assert.deepEqual([receipt.content.amount, receipt.content.balance_after, receipt.content.method_code, receipt.content.external_ref, receipt.content.program.code],
    ['30000.00', '50000.00', 'cheque', 'CHQ 77', 'RECU']);
  // Frozen: a later payment changes nothing in it; nothing changes it.
  const later = (await first(RECORD, [e, '10000', 'today', 'cash', null, false, uuid()])).id;
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [later]);
  await as('school_runtime', a, accountant.user);
  const second = await first(ISSUE, [later, null]);
  assert.notEqual((await first('SELECT number FROM school.receipt WHERE id = $1', [second.id])).number, receipt.number);
  assert.equal((await first('SELECT content FROM school.receipt WHERE id = $1', [second.id])).content.balance_after, '40000.00');
  assert.equal((await first('SELECT content FROM school.receipt WHERE id = $1', [issued.id])).content.balance_after, '50000.00');
  await rejects("UPDATE school.receipt SET locale = 'ar' WHERE id = $1", [issued.id], '42501');
  // A refund's receipt, once executed.
  const refund = (await first('SELECT school.request_refund($1, $2, $3, $4, $5, $6) AS id', [e, 'refund', null, '5000', 'Désistement partiel', uuid()])).id;
  await rejects(ISSUE, [null, refund], '23514', 'receipt_not_validated');
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.refund SET status = 'approved' WHERE id = $1", [refund]);
  await as('school_runtime', a, accountant.user);
  await sql("UPDATE school.refund SET status = 'executed', method_code = 'cash', paid_on = current_date WHERE id = $1", [refund]);
  const refundReceipt = await first(ISSUE, [null, refund]);
  assert.equal((await first('SELECT content FROM school.receipt WHERE id = $1', [refundReceipt.id])).content.kind, 'refund');
  // Whoever reads no payment issues and reads no receipt.
  await as('school_runtime', a, 4);
  await rejects(ISSUE, [id, null], '42501', 'permission_denied');
  assert.equal(await count('SELECT count(*) n FROM school.receipt WHERE id = $1', [issued.id]), 0);
  await as('school_runtime', a, 1);
});
// Arrears (SQL 052, FR-FIN-06): open balances and overdue instalments as at a date, for the reader's enrolments; the finance's daily reminder.
await check('the arrears add up to what is owed less what is paid; the overdue instalments come from the schedule; a teacher reads none (FR-FIN-06 AC1, AC2)', async () => {
  const accountant = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.create', 'finance.schedule.manage', 'enrollment.enrollment.read']);
  const validator = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.validate', 'enrollment.enrollment.read']);
  const elsewhere = await scopedAccount('internal', 'site', [scopeSiteB], ['finance.payment.read', 'enrollment.enrollment.read']);
  await as('school_runtime', a, 1);
  const scheduled = await enroll((await newAdult('Retard', 'Amel')).id, await programme('RETARD-A', 90000));
  const unscheduled = await enroll((await newAdult('Retard', 'Bilal')).id, await programme('RETARD-B', 50000));
  await as('school_runtime', a, accountant.user);
  await sql('SELECT school.set_schedule($1, $2::jsonb, NULL, $3)', [scheduled, JSON.stringify([{ due_date: '2026-01-15', amount: '30000' },
    { due_date: '2026-02-15', amount: '30000' }, { due_date: '2099-01-15', amount: '30000' }]), uuid()]);
  const paid = (await first(RECORD, [scheduled, '40000', 'today', 'cash', null, false, uuid()])).id;
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [paid]);
  await as('school_runtime', a, accountant.user);
  const ARREARS = `SELECT enrollment_id, due::text AS due, paid::text AS paid, balance::text AS balance, overdue::text AS overdue,
    overdue_since::text AS since, next_due_date::text AS next FROM school.arrears($1::date, $2)`;
  assert.deepEqual(await first(ARREARS, ['2026-03-01', scheduled]),
    { enrollment_id: scheduled, due: '90000.00', paid: '40000.00', balance: '50000.00', overdue: '20000.00', since: '2026-02-15', next: '2099-01-15' });
  assert.deepEqual(await first(ARREARS, ['2026-02-15', scheduled]).then(r => [r.overdue, r.since, r.next]), ['0.00', null, '2026-02-15'], 'due today is not late yet');
  assert.deepEqual(await first(ARREARS, ['2026-03-01', unscheduled]).then(r => [r.balance, r.overdue, r.since]), ['50000.00', '0.00', null]);
  // AC1: the open balances listed add up to what they owe less what they paid.
  const totals = await first(`SELECT sum(balance)::text AS open, (sum(due) - sum(paid))::text AS owed, count(*) AS n FROM school.arrears('2026-03-01'::date)`);
  assert.equal(totals.open, totals.owed);
  assert.ok(Number(totals.n) >= 2);
  // A reader of one site's payments reads that site's enrolments only; AC2: a teacher reads none.
  await as('school_runtime', a, elsewhere.user);
  assert.equal(await count(`SELECT count(*) n FROM school.arrears('2026-03-01'::date) WHERE enrollment_id = ANY ($1::uuid[])`, [[scheduled, unscheduled]]), 0);
  await as('school_runtime', a, 4);
  await rejects(`SELECT * FROM school.arrears('2026-03-01'::date)`, [], '42501', 'permission_denied');
  // The daily reminder: the instalment of 15 February, still not covered, seven days after; once a day.
  await as('school_runtime', a, 1);
  await sql("SELECT platform.notify_installments('2026-02-22'::date)");
  assert.equal((await first("SELECT platform.notify_installments('2026-02-22'::date) AS n")).n, 0, 'once a day');
  await as('school_runtime', a, accountant.user);
  const notice = await first("SELECT data FROM school.notification WHERE event = 'finance.installments_due' ORDER BY created_at DESC LIMIT 1");
  assert.deepEqual([notice.data.day, notice.data.counts], ['2026-02-22', { after_7: 1 }]);
  await as('school_runtime', a, 1);
});
// The financial period's close (SQL 053, FR-FIN-07, BR-15): a past month closed with its reconciliation; nothing dated in it afterwards; reopened by someone else, with a reason.
const CLOSE = 'SELECT school.close_period($1::date) AS id';
await check('a closed month refuses what is dated in it, keeps its reconciliation and is reopened by someone else with a reason (FR-FIN-07 AC1, AC2)', async () => {
  const accountant = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.create', 'finance.payment.reverse',
    'finance.refund.request', 'finance.period.close', 'enrollment.enrollment.read']);
  const validator = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.validate', 'finance.refund.approve', 'enrollment.enrollment.read']);
  const director = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.period.close', 'finance.period.reopen']);
  const sited = await scopedAccount('internal', 'site', [scopeSiteB], ['finance.payment.read', 'finance.period.close', 'finance.period.reopen']);
  await as('school_runtime', a, 1);
  const e = await enroll((await newAdult('Cloture', 'Imane')).id, await programme('CLOTURE', 100000));
  await as('school_runtime', a, accountant.user);
  const cash = (await first(RECORD, [e, '30000', '2025-03-10', 'cash', null, false, uuid()])).id;
  const cheque = (await first(RECORD, [e, '20000', '2025-03-20', 'cheque', 'CHQ 9', false, uuid()])).id;
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [cash]);
  await as('school_runtime', a, accountant.user);
  const refund = (await first('SELECT school.request_refund($1, $2, $3, $4, $5, $6) AS id', [e, 'refund', null, '5000', 'Désistement partiel', uuid()])).id;
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.refund SET status = 'approved' WHERE id = $1", [refund]);
  await as('school_runtime', a, accountant.user);
  await sql("UPDATE school.refund SET status = 'executed', method_code = 'cash', paid_on = '2025-03-25' WHERE id = $1", [refund]);
  // Read before its closure: what it holds and what still waits in it.
  const preview = (await first("SELECT school.reconciliation('2025-03-05') AS r")).r;
  assert.deepEqual([preview.payments_total, preview.refunds_total, preview.net, preview.pending, preview.closed], ['30000.00', '5000.00', '25000.00', 1, false]);
  // Closed once ended, for the whole school, once nothing dated in it waits for its validation.
  await rejects(CLOSE, ['today'], '23514', 'period_not_ended');
  await rejects(CLOSE, ['2025-03-01'], '23514', 'period_has_pending');
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [cheque]);
  await rejects(CLOSE, ['2025-03-01'], '42501', 'permission_denied');
  await as('school_runtime', a, sited.user);
  await rejects(CLOSE, ['2025-03-01'], '42501', 'permission_denied');
  await as('school_runtime', a, accountant.user);
  const closed = (await first(CLOSE, ['2025-03-17'])).id;
  await rejects(CLOSE, ['2025-03-01'], '23514', 'period_closed');
  const period = await first('SELECT period_start::text AS start, period_end::text AS end, status, report FROM school.finance_period WHERE id = $1', [closed]);
  assert.deepEqual([period.start, period.end, period.status], ['2025-03-01', '2025-03-31', 'closed']);
  // The reconciliation: the month's validated payments by means, its refunds, what came in net.
  assert.deepEqual([period.report.payments, period.report.payments_total, period.report.refunds, period.report.refunds_total, period.report.net],
    [{ cash: { count: 1, amount: '30000.00' }, cheque: { count: 1, amount: '20000.00' } }, '50000.00', { cash: { count: 1, amount: '5000.00' } }, '5000.00', '45000.00']);
  // AC1: nothing recorded, moved or validated with a date in it; a correction goes through a reversal dated today.
  await rejects(RECORD, [e, '1000', '2025-03-15', 'cash', null, false, uuid()], '23514', 'period_closed');
  const pending = (await first(RECORD, [e, '1000', 'today', 'cash', null, false, uuid()])).id;
  await rejects("UPDATE school.payment SET paid_on = '2025-03-15' WHERE id = $1", [pending], '23514', 'period_closed');
  await sql("UPDATE school.payment SET paid_on = '2025-04-15' WHERE id = $1", [pending]);
  const second = (await first('SELECT school.request_refund($1, $2, $3, $4, $5, $6) AS id', [e, 'refund', null, '1000', 'Erreur de caisse', uuid()])).id;
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.refund SET status = 'approved' WHERE id = $1", [second]);
  await as('school_runtime', a, accountant.user);
  await rejects("UPDATE school.refund SET status = 'executed', method_code = 'cash', paid_on = '2025-03-31' WHERE id = $1", [second], '23514', 'period_closed');
  const reversal = (await first('SELECT school.reverse_payment($1, $2, $3) AS id', [cheque, 'Chèque rejeté', uuid()])).id;
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [reversal]);
  assert.equal((await first('SELECT status FROM school.payment WHERE id = $1', [cheque])).status, 'reversed', 'the payment of the closed month reversed, its date kept');
  // A closure never changes, nor goes; whoever reads no payment reads none.
  await as('school_runtime', a, director.user);
  await rejects("UPDATE school.finance_period SET report = '{}' WHERE id = $1", [closed], '42501');
  await rejects('DELETE FROM school.finance_period WHERE id = $1', [closed], '42501');
  await as('school_runtime', a, 4);
  assert.equal(await count('SELECT count(*) n FROM school.finance_period WHERE id = $1', [closed]), 0);
  await rejects("SELECT school.reconciliation('2025-03-01')", [], '42501', 'permission_denied');
  // AC2: reopened by `finance.period.reopen` only, with its reason, journalled; never by whoever closed it.
  await as('school_runtime', a, accountant.user);
  assert.equal((await sql("UPDATE school.finance_period SET status = 'reopened', reopen_reason = 'Erreur' WHERE id = $1", [closed])).rowCount ?? 0, 0);
  await as('school_runtime', a, director.user);
  assert.equal((await first('SELECT status FROM school.finance_period WHERE id = $1', [closed])).status, 'closed');
  await as('school_runtime', a, director.user);
  await rejects("UPDATE school.finance_period SET status = 'reopened', reopen_reason = '   ' WHERE id = $1", [closed], '23514', 'reason_required');
  await sql("UPDATE school.finance_period SET status = 'reopened', reopen_reason = 'Chèque du 20 mars mal daté' WHERE id = $1", [closed]);
  const reopened = await first('SELECT status, reopened_by::int AS by, reopened_at IS NOT NULL AS dated FROM school.finance_period WHERE id = $1', [closed]);
  assert.deepEqual([reopened.status, reopened.by, reopened.dated], ['reopened', Number(director.user), true]);
  await as(null);
  const entry = await first("SELECT actor_id::int AS actor, after FROM school.audit_log WHERE object_type = 'finance_period' AND object_id = $1 AND action = 'UPDATE'", [closed]);
  assert.deepEqual([entry.actor, entry.after.status, entry.after.reopen_reason], [Number(director.user), 'reopened', 'Chèque du 20 mars mal daté']);
  await as('school_runtime', a, director.user);
  await rejects("UPDATE school.finance_period SET status = 'closed' WHERE id = $1", [closed], '23514', 'immutable_record');
  // Reopened, the month takes its dates again; closed again, the former closure kept.
  await as('school_runtime', a, accountant.user);
  const late = (await first(RECORD, [e, '1000', '2025-03-28', 'cash', null, false, uuid()])).id;
  await sql("UPDATE school.payment SET status = 'cancelled', cancel_reason = 'Doublon' WHERE id = $1", [late]);
  await as('school_runtime', a, director.user);
  const again = (await first(CLOSE, ['2025-03-01'])).id;
  assert.equal(await count("SELECT count(*) n FROM school.finance_period WHERE tenant_id = $1 AND period_start = '2025-03-01'", [a]), 2);
  await rejects("UPDATE school.finance_period SET status = 'reopened', reopen_reason = 'Encore' WHERE id = $1", [again], '23514', 'segregation_of_duties');
  await as('school_runtime', a, 1);
});
// A payment's supporting document (SQL 054, FR-FIN-02, FR-FIC-01 AC2): added by its author while it waits, read with the payment only.
await check("a payment's document is its author's while it waits, frozen once validated, read only by whoever reads the payment (FR-FIN-02, FR-FIC-01 AC2)", async () => {
  const cashier = await scopedAccount('internal', 'tenant', null, ['finance.payment.create.delegated', 'enrollment.enrollment.read']);
  const accountant = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.create', 'enrollment.enrollment.read']);
  const validator = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.validate', 'enrollment.enrollment.read']);
  const elsewhere = await scopedAccount('internal', 'site', [scopeSiteB], ['finance.payment.read', 'enrollment.enrollment.read']);
  await as('school_runtime', a, 1);
  const e = await enroll((await newAdult('Justif', 'Lina')).id, await programme('JUSTIF', 60000));
  const FILE = `INSERT INTO school.file_object (tenant_id, category, storage_key, original_name, file_type, media_type, size_bytes, sha256, owner_type, owner_id)
    VALUES ($1, 'finance_attachment', $2, $3, 'pdf', 'application/pdf', 1000, repeat('a', 64), 'payment', $4) RETURNING id`;
  const file = async (payment, name) => (await first(FILE, [a, `tenant/${a}/finance_attachment/${uuid()}`, name, payment])).id;
  await as('school_runtime', a, accountant.user);
  const others = (await first(RECORD, [e, '1000', 'today', 'cash', null, false, uuid()])).id;
  await as('school_runtime', a, cashier.user);
  const paid = (await first(RECORD, [e, '20000', 'today', 'cheque', 'CHQ 12', false, uuid()])).id;
  // A cashier on delegation adds the document of their own payment, never of another's.
  await rejects(FILE, [a, `tenant/${a}/finance_attachment/${uuid()}`, 'autre.pdf', others], '42501');
  const scan = await file(paid, 'cheque.pdf');
  await rejects('UPDATE school.payment SET attachment_file_id = $2 WHERE id = $1', [paid, uuid()], '23514', 'payment_attachment_invalid');
  await sql('UPDATE school.payment SET attachment_file_id = $2 WHERE id = $1', [paid, scan]);
  assert.equal(await count('SELECT count(*) n FROM school.file_object WHERE id = $1', [scan]), 1, 'its author reads it without reading the finance');
  // Replaced while it waits: the former one archived, kept.
  const better = await file(paid, 'cheque-net.pdf');
  await sql('UPDATE school.payment SET attachment_file_id = $2 WHERE id = $1', [paid, better]);
  await as(null);
  assert.deepEqual(await first('SELECT archived_at IS NOT NULL AS archived, purged_at IS NULL AS kept FROM school.file_object WHERE id = $1', [scan]), { archived: true, kept: true });
  // Only a file of this payment: not another's, not one archived.
  await as('school_runtime', a, accountant.user);
  const stray = await file(others, 'autre.pdf');
  await sql('UPDATE school.payment SET attachment_file_id = $2 WHERE id = $1', [others, stray]);
  await as('school_runtime', a, cashier.user);
  await rejects('UPDATE school.payment SET attachment_file_id = $2 WHERE id = $1', [paid, scan], '23514', 'payment_attachment_invalid');
  await as('school_runtime', a, validator.user);
  await rejects('UPDATE school.payment SET attachment_file_id = NULL WHERE id = $1', [paid], '23514', 'payment_not_author');
  await as('school_runtime', a, accountant.user);
  await rejects('UPDATE school.payment SET attachment_file_id = $2 WHERE id = $1', [others, better], '23514', 'payment_attachment_invalid');
  // TST-11: once validated, it never changes.
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [paid]);
  assert.equal(await count('SELECT count(*) n FROM school.file_object WHERE id = $1', [better]), 1);
  await as('school_runtime', a, cashier.user);
  await rejects('UPDATE school.payment SET attachment_file_id = NULL WHERE id = $1', [paid], '23514', 'payment_immutable');
  // AC2: a reader of another site's payments, a teacher, read none of it.
  await as('school_runtime', a, elsewhere.user);
  assert.equal(await count('SELECT count(*) n FROM school.file_object WHERE id = ANY ($1::uuid[])', [[scan, better, stray]]), 0);
  await as('school_runtime', a, 4);
  assert.equal(await count('SELECT count(*) n FROM school.file_object WHERE id = ANY ($1::uuid[])', [[scan, better, stray]]), 0);
  await as('school_runtime', a, 1);
});
// Expenses (SQL 055, FR-DEP-01, FR-DEP-02): the school's categories, an expense entered, submitted, decided by someone else; its documents.
const EXPENSE = 'SELECT school.record_expense($1::date, $2::numeric, $3, $4, $5, $6, $7, $8) AS id';
await check('an expense is its author\'s draft, submitted, then decided by someone else, the direction beyond the threshold; never changed after (FR-DEP-01 AC1, FR-DEP-02 AC1–AC3)', async () => {
  const clerk = await scopedAccount('internal', 'tenant', null, ['expense.expense.create', 'expense.expense.read', 'expense.attachment.read']);
  const validator = await scopedAccount('internal', 'tenant', null, ['expense.expense.create', 'expense.expense.validate', 'expense.expense.read', 'expense.attachment.read']);
  const director = await scopedAccount('internal', 'tenant', null, ['expense.expense.validate', 'expense.expense.approve', 'expense.expense.read']);
  const cashier = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.create']);
  await as('school_runtime', a, clerk.user);
  // FR-DEP-01: the publisher's six categories, `salaries` the payroll's; named by whoever validates for the whole school.
  const category = async code => (await first('SELECT id FROM school.expense_category WHERE tenant_id = $1 AND code = $2', [a, code])).id;
  assert.deepEqual((await sql('SELECT code FROM school.expense_category WHERE tenant_id = $1 ORDER BY sort_order', [a])).rows.map(r => r.code),
    ['salaries', 'advertising', 'missions', 'rent', 'equipment', 'other']);
  const CATEGORY = `INSERT INTO school.expense_category (tenant_id, code, labels) VALUES ($1, $2, '{"fr": "Transport"}') RETURNING id`;
  await rejects(CATEGORY, [a, 'transport'], '42501', 'permission_denied');
  await as('school_runtime', a, validator.user);
  const transport = (await first(CATEGORY, [a, 'transport'])).id;
  await rejects('UPDATE school.expense_category SET archived_at = now() WHERE id = $1', [await category('salaries')], '23514', 'expense_category_system');
  await rejects("UPDATE school.expense_category SET code = 'bus' WHERE id = $1", [transport], '42501');
  await sql('UPDATE school.expense_category SET archived_at = now() WHERE id = $1', [transport]);
  // AC1 of FR-DEP-01: never `salaries` by hand; `other` names what it is; no day to come, none in a closed month, no archived category.
  await as('school_runtime', a, clerk.user);
  const rent = await category('rent');
  await rejects(EXPENSE, ['today', '1000', await category('salaries'), null, 'Prof. Amrani', 'Salaire de mars', null, uuid()], '23514', 'expense_category_system');
  await rejects(EXPENSE, ['today', '1000', await category('other'), null, 'Quincaillerie', 'Divers', null, uuid()], '23514', 'expense_label_required');
  await rejects(EXPENSE, ['2099-01-01', '1000', rent, null, 'SCI Les Pins', 'Loyer', null, uuid()], '23514', 'expense_future_date');
  await rejects(EXPENSE, ['2025-03-10', '1000', rent, null, 'SCI Les Pins', 'Loyer de mars', null, uuid()], '23514', 'period_closed');
  await rejects(EXPENSE, ['today', '1000', transport, null, 'Bus', 'Sortie', null, uuid()], '23514', 'expense_category_archived');
  // Once per key (BR-05).
  const key = uuid();
  const lease = (await first(EXPENSE, ['today', '30000', rent, null, 'SCI Les Pins', 'Loyer du mois', null, key])).id;
  assert.equal((await first(EXPENSE, ['today', '30000', rent, null, 'SCI Les Pins', 'Loyer du mois', null, key])).id, lease);
  await rejects(EXPENSE, ['today', '31000', rent, null, 'SCI Les Pins', 'Loyer du mois', null, key], '23514', 'idempotency_key_reused');
  await sql("UPDATE school.expense SET amount = 32000, label = '  ' WHERE id = $1", [lease]);
  // Its documents: added by its author while a draft, read only with `expense.attachment.read` (AC3).
  const FILE = `INSERT INTO school.file_object (tenant_id, category, storage_key, original_name, file_type, media_type, size_bytes, sha256, owner_type, owner_id)
    VALUES ($1, 'finance_attachment', $2, 'quittance.pdf', 'pdf', 'application/pdf', 1000, repeat('b', 64), 'expense', $3) RETURNING id`;
  const receipt = (await first(FILE, [a, `tenant/${a}/finance_attachment/${uuid()}`, lease])).id;
  await as('school_runtime', a, validator.user);
  await rejects("UPDATE school.expense SET amount = 1 WHERE id = $1", [lease], '23514', 'expense_not_author');
  await rejects("UPDATE school.expense SET status = 'validated' WHERE id = $1", [lease], '23514', 'expense_immutable');
  await rejects(FILE, [a, `tenant/${a}/finance_attachment/${uuid()}`, lease], '23514', 'expense_not_author');
  assert.equal(await count('SELECT count(*) n FROM school.file_object WHERE id = $1', [receipt]), 1);
  for (const reader of [cashier.user, director.user, 4]) {
    await as('school_runtime', a, reader);
    assert.equal(await count('SELECT count(*) n FROM school.file_object WHERE id = $1', [receipt]), 0, 'AC3: no document without expense.attachment.read');
  }
  // Submitted by its author: it waits as it is.
  await as('school_runtime', a, clerk.user);
  await sql("UPDATE school.expense SET status = 'submitted' WHERE id = $1", [lease]);
  assert.equal((await first('SELECT needs_approval FROM school.expense WHERE id = $1', [lease])).needs_approval, false);
  await rejects('UPDATE school.expense SET amount = 1 WHERE id = $1', [lease], '23514', 'expense_immutable');
  await rejects(FILE, [a, `tenant/${a}/finance_attachment/${uuid()}`, lease], '23514', 'expense_immutable');
  await rejects("UPDATE school.expense SET status = 'validated' WHERE id = $1", [lease], '42501', 'permission_denied');
  // AC1: never validated by its author.
  await as('school_runtime', a, validator.user);
  const own = (await first(EXPENSE, ['today', '2000', await category('missions'), null, 'Hôtel', 'Mission à Oran', null, uuid()])).id;
  await sql("UPDATE school.expense SET status = 'submitted' WHERE id = $1", [own]);
  await rejects("UPDATE school.expense SET status = 'validated' WHERE id = $1", [own], '23514', 'segregation_of_duties');
  await sql("UPDATE school.expense SET status = 'validated' WHERE id = $1", [lease]);
  const decided = await first('SELECT status, decided_by::int AS by, amount::text AS amount FROM school.expense WHERE id = $1', [lease]);
  assert.deepEqual([decided.status, decided.by, decided.amount], ['validated', Number(validator.user), '32000.00']);
  // AC2: validated, it never changes, nor goes.
  await rejects('UPDATE school.expense SET amount = 1 WHERE id = $1', [lease], '23514', 'expense_immutable');
  await rejects("UPDATE school.expense SET status = 'rejected', decision_reason = 'Erreur' WHERE id = $1", [lease], '23514', 'expense_immutable');
  await rejects('DELETE FROM school.expense WHERE id = $1', [lease], '42501');
  // Beyond the threshold (50,000 DA by default), the direction's.
  await as('school_runtime', a, clerk.user);
  const laptops = (await first(EXPENSE, ['today', '80000', await category('equipment'), null, 'Informatique Plus', 'Deux ordinateurs', null, uuid()])).id;
  await sql("UPDATE school.expense SET status = 'submitted' WHERE id = $1", [laptops]);
  assert.equal((await first('SELECT needs_approval FROM school.expense WHERE id = $1', [laptops])).needs_approval, true);
  await as('school_runtime', a, validator.user);
  await rejects("UPDATE school.expense SET status = 'validated' WHERE id = $1", [laptops], '42501', 'permission_denied');
  await as('school_runtime', a, director.user);
  await rejects("UPDATE school.expense SET status = 'rejected' WHERE id = $1", [laptops], '23514', 'reason_required');
  await sql("UPDATE school.expense SET status = 'rejected', decision_reason = 'Un seul ordinateur suffit' WHERE id = $1", [laptops]);
  await rejects("UPDATE school.expense SET status = 'validated' WHERE id = $1", [laptops], '23514', 'expense_immutable');
  // Whoever reads no expense reads none.
  await as('school_runtime', a, cashier.user);
  assert.equal(await count('SELECT count(*) n FROM school.expense WHERE id = ANY ($1::uuid[])', [[lease, laptops]]), 0);
  await as('school_runtime', a, 1);
});
// An expense's payments (SQL 056, FR-DEP-02): a validated expense paid in parts, never beyond; a payment taken back by its reversal, decided by someone else.
const PAY_EXPENSE = 'SELECT school.record_expense_payment($1, $2::numeric, $3::date, $4, $5, $6) AS id';
const REVERSE_EXPENSE_PAYMENT = 'SELECT school.reverse_expense_payment($1, $2, $3) AS id';
await check('a validated expense is paid in parts, never beyond, once per key; a payment is taken back by its reversal, validated by someone else (FR-DEP-02, BR-08, TST-10, TST-11)', async () => {
  const clerk = await scopedAccount('internal', 'tenant', null, ['expense.expense.create', 'expense.expense.read']);
  const validator = await scopedAccount('internal', 'tenant', null, ['expense.expense.validate', 'expense.expense.read']);
  const payer = await scopedAccount('internal', 'tenant', null, ['expense.expense.pay', 'expense.expense.reverse', 'expense.expense.validate']);
  const cashier = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.create']);
  await as('school_runtime', a, clerk.user);
  const rent = (await first("SELECT id FROM school.expense_category WHERE tenant_id = $1 AND code = 'rent'", [a])).id;
  const lease = (await first(EXPENSE, ['today', '30000', rent, null, 'SCI Les Pins', 'Loyer du trimestre', null, uuid()])).id;
  // Not before its validation.
  await as('school_runtime', a, payer.user);
  await rejects(PAY_EXPENSE, [lease, '10000', 'today', 'cash', null, uuid()], '23514', 'expense_not_payable');
  await as('school_runtime', a, clerk.user);
  await sql("UPDATE school.expense SET status = 'submitted' WHERE id = $1", [lease]);
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.expense SET status = 'validated' WHERE id = $1", [lease]);
  const paid = async () => (await first("SELECT school.expense_paid($1, $2)::text AS paid, school.expense_payment_state('validated', 30000, school.expense_paid($1, $2)) AS state",
    [a, lease]));
  // Paid by `expense.expense.pay` alone, by a means the school accepts, on a day not to come, never beyond what is left.
  for (const who of [clerk.user, validator.user, cashier.user]) {
    await as('school_runtime', a, who);
    await rejects(PAY_EXPENSE, [lease, '10000', 'today', 'cash', null, uuid()], '42501');
  }
  await as('school_runtime', a, payer.user);
  assert.deepEqual(Object.values(await paid()), ['0.00', 'unpaid']);
  await rejects(PAY_EXPENSE, [lease, '10000', 'tomorrow', 'cash', null, uuid()], '23514', 'payment_future_date');
  await rejects(PAY_EXPENSE, [lease, '10000', 'today', 'bitcoin', null, uuid()], '23514', 'payment_method_unknown');
  await rejects(PAY_EXPENSE, [lease, '30000.01', 'today', 'cash', null, uuid()], '23514', 'payment_exceeds_balance');
  const key = uuid();
  const first12 = (await first(PAY_EXPENSE, [lease, '12000', 'today', 'cash', null, key])).id;
  assert.equal((await first(PAY_EXPENSE, [lease, '12000', 'today', 'cash', null, key])).id, first12, 'BR-05: the same key answers the first payment');
  await rejects(PAY_EXPENSE, [lease, '13000', 'today', 'cash', null, key], '23514', 'idempotency_key_reused');
  assert.deepEqual(Object.values(await paid()), ['12000.00', 'partially_paid']);
  const cheque = (await first(PAY_EXPENSE, [lease, '18000', 'today', 'cheque', '  CHQ 77 ', uuid()])).id;
  assert.deepEqual(Object.values(await paid()), ['30000.00', 'paid']);
  assert.equal((await first('SELECT external_ref FROM school.expense_payment WHERE id = $1', [cheque])).external_ref, 'CHQ 77');
  await rejects(PAY_EXPENSE, [lease, '0.01', 'today', 'cash', null, uuid()], '23514', 'payment_exceeds_balance');
  // TST-11: a payment never changes, nor goes.
  await rejects('UPDATE school.expense_payment SET amount = 1 WHERE id = $1', [cheque], '42501');
  await rejects("UPDATE school.expense_payment SET status = 'reversed' WHERE id = $1", [cheque], '23514', 'expense_payment_immutable');
  await rejects('DELETE FROM school.expense_payment WHERE id = $1', [cheque], '42501');
  // Taken back by its reversal, its whole amount, with its reason, once at a time.
  await rejects(REVERSE_EXPENSE_PAYMENT, [cheque, '  ', uuid()], '23514', 'reason_required');
  const reversal = (await first(REVERSE_EXPENSE_PAYMENT, [cheque, 'Chèque rejeté par la banque', uuid()])).id;
  await rejects(REVERSE_EXPENSE_PAYMENT, [cheque, 'Encore', uuid()], '23514', 'payment_not_reversible');
  const asked = await first('SELECT amount::text AS amount, method_code, status, paid_on::text AS day FROM school.expense_payment WHERE id = $1', [reversal]);
  assert.deepEqual(Object.values(asked), ['18000.00', 'cheque', 'pending_validation', today]);
  assert.deepEqual(Object.values(await paid()), ['30000.00', 'paid'], 'a reversal waiting takes nothing back');
  // BR-08: never validated by whoever asked for it; validated by someone else, the original kept, `reversed`.
  await rejects("UPDATE school.expense_payment SET status = 'validated' WHERE id = $1", [reversal], '23514', 'segregation_of_duties');
  await as('school_runtime', a, clerk.user);
  assert.equal((await sql("UPDATE school.expense_payment SET status = 'validated' WHERE id = $1", [reversal])).rowCount ?? 0, 0);
  await as('school_runtime', a, validator.user);
  await rejects("UPDATE school.expense_payment SET status = 'rejected' WHERE id = $1", [reversal], '23514', 'reason_required');
  await sql("UPDATE school.expense_payment SET status = 'validated' WHERE id = $1", [reversal]);
  assert.deepEqual(Object.values(await paid()), ['12000.00', 'partially_paid']);
  assert.equal((await first('SELECT status FROM school.expense_payment WHERE id = $1', [cheque])).status, 'reversed');
  await rejects("UPDATE school.expense_payment SET status = 'rejected', decision_reason = 'Erreur' WHERE id = $1", [reversal], '23514', 'expense_payment_immutable');
  await as('school_runtime', a, payer.user);
  await rejects(REVERSE_EXPENSE_PAYMENT, [cheque, 'Encore', uuid()], '23514', 'payment_not_reversible');
  // A rejected reversal leaves room for the next; what is left is paid again.
  const mistaken = (await first(REVERSE_EXPENSE_PAYMENT, [first12, 'Erreur de saisie', uuid()])).id;
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.expense_payment SET status = 'rejected', decision_reason = 'Le paiement en espèces est juste' WHERE id = $1", [mistaken]);
  await as('school_runtime', a, payer.user);
  assert.ok((await first(REVERSE_EXPENSE_PAYMENT, [first12, 'Erreur de saisie, vérifiée', uuid()])).id);
  await first(PAY_EXPENSE, [lease, '18000', 'today', 'transfer', 'VIR 12', uuid()]);
  assert.deepEqual(Object.values(await paid()), ['30000.00', 'paid']);
  // Whoever reads no expense reads none of its payments; the journal keeps them.
  await as('school_runtime', a, cashier.user);
  assert.equal(await count('SELECT count(*) n FROM school.expense_payment WHERE expense_id = $1', [lease]), 0);
  await as(null);
  assert.ok(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'expense_payment' AND object_id = $1", [reversal]) >= 2);
  await as('school_runtime', a, 1);
});
await check('a month closes once the expenses dated in it are decided; its reconciliation reports them; nothing paid in it afterwards (FR-FIN-07, BR-15)', async () => {
  const clerk = await scopedAccount('internal', 'tenant', null, ['expense.expense.create', 'expense.expense.read']);
  const accountant = await scopedAccount('internal', 'tenant', null, ['expense.expense.validate', 'expense.expense.pay', 'expense.expense.reverse', 'expense.expense.read',
    'finance.payment.read', 'finance.period.close']);
  const other = await scopedAccount('internal', 'tenant', null, ['expense.expense.validate', 'expense.expense.read']);
  await as('school_runtime', a, clerk.user);
  const category = async code => (await first('SELECT id FROM school.expense_category WHERE tenant_id = $1 AND code = $2', [a, code])).id;
  const flyers = (await first(EXPENSE, ['2025-07-08', '4500.50', await category('advertising'), null, 'Imprimerie', 'Affiches', null, uuid()])).id;
  const desks = (await first(EXPENSE, ['2025-07-15', '20000', await category('equipment'), null, 'Mobilier Plus', 'Tables', null, uuid()])).id;
  await sql("UPDATE school.expense SET status = 'submitted' WHERE id = ANY ($1::uuid[])", [[flyers, desks]]);
  await as('school_runtime', a, accountant.user);
  await rejects(CLOSE, ['2025-07-01'], '23514', 'period_has_pending');
  assert.equal((await first("SELECT school.reconciliation('2025-07-01') AS r")).r.pending, 2);
  await sql("UPDATE school.expense SET status = 'validated' WHERE id = ANY ($1::uuid[])", [[flyers, desks]]);
  const cash = (await first(PAY_EXPENSE, [flyers, '4500.50', '2025-07-09', 'cash', null, uuid()])).id;
  await first(PAY_EXPENSE, [desks, '15000', '2025-07-20', 'transfer', 'VIR 1', uuid()]);
  // A reversal asked for, dated today, waits in the open month; one waiting in the month itself would hold its closure.
  await first(REVERSE_EXPENSE_PAYMENT, [cash, 'Payé deux fois', uuid()]);
  const closed = (await first(CLOSE, ['2025-07-01'])).id;
  const report = (await first('SELECT report FROM school.finance_period WHERE id = $1', [closed])).report;
  assert.deepEqual([report.expenses, report.expenses_total, report.expense_payments, report.expense_reversals, report.expenses_paid],
    [{ advertising: { count: 1, amount: '4500.50' }, equipment: { count: 1, amount: '20000.00' } }, '24500.50',
      { cash: { count: 1, amount: '4500.50' }, transfer: { count: 1, amount: '15000.00' } }, { count: 0, amount: '0.00' }, '19500.50']);
  assert.equal(report.cash_balance, (Number(report.net) - 19500.5).toFixed(2));
  // BR-15: nothing paid, entered or moved with a day in it; the reversal of a payment of the month is dated today.
  await rejects(PAY_EXPENSE, [desks, '5000', '2025-07-25', 'cash', null, uuid()], '23514', 'period_closed');
  await first(PAY_EXPENSE, [desks, '5000', 'today', 'cash', null, uuid()]);
  await as('school_runtime', a, clerk.user);
  await rejects(EXPENSE, ['2025-07-30', '100', await category('missions'), null, 'Taxi', 'Course', null, uuid()], '23514', 'period_closed');
  await as('school_runtime', a, other.user);
  await sql("UPDATE school.expense_payment SET status = 'validated' WHERE reverses_payment_id = $1", [cash]);
  assert.equal((await first('SELECT status FROM school.expense_payment WHERE id = $1', [cash])).status, 'reversed', 'the payment of the closed month reversed, its date kept');
  await as('school_runtime', a, 1);
});
// An expense's corrections (SQL 057, FR-DEP-03): a linked negative entry, decided by someone else, never below what is paid, the original kept.
const CORRECT_EXPENSE = 'SELECT school.correct_expense($1, $2, $3::numeric, $4, $5) AS id';
await check('a validated expense is corrected or cancelled by its linked negative entry, validated by someone else, never below what is paid, the original kept (FR-DEP-03, BR-08, TST-11)', async () => {
  const clerk = await scopedAccount('internal', 'tenant', null, ['expense.expense.create', 'expense.expense.read']);
  const validator = await scopedAccount('internal', 'tenant', null, ['expense.expense.validate', 'expense.expense.read', 'finance.payment.read']);
  const corrector = await scopedAccount('internal', 'tenant', null, ['expense.expense.reverse', 'expense.expense.pay', 'expense.expense.validate']);
  const cashier = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.create']);
  await as('school_runtime', a, clerk.user);
  const equipment = (await first("SELECT id FROM school.expense_category WHERE tenant_id = $1 AND code = 'equipment'", [a])).id;
  const boards = (await first(EXPENSE, ['today', '10000', equipment, null, 'Bureau Pro', 'Tableaux blancs', null, uuid()])).id;
  const state = async () => (await first(`SELECT x.amount::text AS amount, x.status, school.expense_committed($1, $2)::text AS committed,
    school.expense_payment_state(x.status, school.expense_committed($1, $2), school.expense_paid($1, $2)) AS payment FROM school.expense x WHERE x.id = $2`, [a, boards]));
  // Not before its validation, by `expense.expense.reverse` alone.
  await as('school_runtime', a, corrector.user);
  await rejects(CORRECT_EXPENSE, [boards, 'correction', '1000', 'Remise', uuid()], '23514', 'expense_not_correctable');
  await as('school_runtime', a, clerk.user);
  await sql("UPDATE school.expense SET status = 'submitted' WHERE id = $1", [boards]);
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.expense SET status = 'validated' WHERE id = $1", [boards]);
  for (const who of [clerk.user, validator.user, cashier.user]) {
    await as('school_runtime', a, who);
    await rejects(CORRECT_EXPENSE, [boards, 'correction', '1000', 'Remise', uuid()], '42501');
  }
  // With its reason, part of it, once per key, one waiting at a time.
  await as('school_runtime', a, corrector.user);
  await rejects(CORRECT_EXPENSE, [boards, 'correction', '1000', '  ', uuid()], '23514', 'reason_required');
  await rejects(CORRECT_EXPENSE, [boards, 'correction', '10000', 'Tout', uuid()], '23514', 'correction_exceeds_amount');
  const key = uuid();
  const discount = (await first(CORRECT_EXPENSE, [boards, 'correction', '2500', '  Remise obtenue ', key])).id;
  assert.equal((await first(CORRECT_EXPENSE, [boards, 'correction', '2500', 'Remise obtenue', key])).id, discount, 'BR-05: the same key answers the first correction');
  await rejects(CORRECT_EXPENSE, [boards, 'correction', '3000', 'Remise obtenue', key], '23514', 'idempotency_key_reused');
  await rejects(CORRECT_EXPENSE, [boards, 'correction', '500', 'Autre remise', uuid()], '23514', 'expense_correction_pending');
  const asked = await first('SELECT amount::text AS amount, reason, status, corrected_on::text AS day FROM school.expense_correction WHERE id = $1', [discount]);
  assert.deepEqual(Object.values(asked), ['-2500.00', 'Remise obtenue', 'pending_validation', today]);
  assert.deepEqual(Object.values(await state()), ['10000.00', 'validated', '10000.00', 'unpaid'], 'a correction waiting changes nothing');
  // BR-08: never validated by whoever asked for it; validated by someone else, the original kept.
  await rejects("UPDATE school.expense_correction SET status = 'validated' WHERE id = $1", [discount], '23514', 'segregation_of_duties');
  await as('school_runtime', a, clerk.user);
  assert.equal((await sql("UPDATE school.expense_correction SET status = 'validated' WHERE id = $1", [discount])).rowCount ?? 0, 0);
  await as('school_runtime', a, validator.user);
  await rejects("UPDATE school.expense_correction SET status = 'rejected' WHERE id = $1", [discount], '23514', 'reason_required');
  await sql("UPDATE school.expense_correction SET status = 'validated' WHERE id = $1", [discount]);
  assert.deepEqual(Object.values(await state()), ['10000.00', 'validated', '7500.00', 'unpaid']);
  // TST-11: decided, it never changes nor goes.
  await rejects("UPDATE school.expense_correction SET status = 'rejected', decision_reason = 'Erreur' WHERE id = $1", [discount], '23514', 'expense_correction_immutable');
  await rejects('UPDATE school.expense_correction SET amount = -1 WHERE id = $1', [discount], '42501');
  await rejects('DELETE FROM school.expense_correction WHERE id = $1', [discount], '42501');
  // Paid against what is committed; never corrected below what is paid.
  await as('school_runtime', a, corrector.user);
  const cash = (await first(PAY_EXPENSE, [boards, '5000', 'today', 'cash', null, uuid()])).id;
  await rejects(PAY_EXPENSE, [boards, '2500.01', 'today', 'cash', null, uuid()], '23514', 'payment_exceeds_balance');
  await rejects(CORRECT_EXPENSE, [boards, 'correction', '3000', 'Seconde remise', uuid()], '23514', 'correction_below_paid');
  await rejects(CORRECT_EXPENSE, [boards, 'cancellation', null, 'Commande annulée', uuid()], '23514', 'correction_below_paid');
  // Paid, it is cancelled once its payments are taken back: the cancellation of all that is committed.
  const back = (await first(REVERSE_EXPENSE_PAYMENT, [cash, 'Commande annulée', uuid()])).id;
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.expense_payment SET status = 'validated' WHERE id = $1", [back]);
  await as('school_runtime', a, corrector.user);
  const cancelled = (await first(CORRECT_EXPENSE, [boards, 'cancellation', null, 'Commande annulée', uuid()])).id;
  assert.equal((await first('SELECT amount::text AS amount FROM school.expense_correction WHERE id = $1', [cancelled])).amount, '-7500.00');
  // A payment while the cancellation waits keeps it from being validated.
  const late = (await first(PAY_EXPENSE, [boards, '100', 'today', 'cash', null, uuid()])).id;
  await as('school_runtime', a, validator.user);
  await rejects("UPDATE school.expense_correction SET status = 'validated' WHERE id = $1", [cancelled], '23514', 'correction_below_paid');
  await as('school_runtime', a, corrector.user);
  const lateBack = (await first(REVERSE_EXPENSE_PAYMENT, [late, 'Payé par erreur', uuid()])).id;
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.expense_payment SET status = 'validated' WHERE id = $1", [lateBack]);
  await sql("UPDATE school.expense_correction SET status = 'validated' WHERE id = $1", [cancelled]);
  assert.deepEqual(Object.values(await state()), ['10000.00', 'validated', '0.00', 'cancelled'], 'the original kept, nothing committed');
  await as('school_runtime', a, corrector.user);
  await rejects(PAY_EXPENSE, [boards, '0.01', 'today', 'cash', null, uuid()], '23514', 'payment_exceeds_balance');
  await rejects(CORRECT_EXPENSE, [boards, 'cancellation', null, 'Encore', uuid()], '23514', 'expense_not_correctable');
  // FR-FIN-07: the month's reconciliation tells its corrections and what is committed once they are counted.
  await as('school_runtime', a, validator.user);
  const month = (await first("SELECT school.reconciliation(current_date) AS r")).r;
  assert.ok(month.expense_corrections.count >= 2 && Number(month.expense_corrections.amount) <= -10000);
  assert.equal(month.expenses_committed, (Number(month.expenses_total) + Number(month.expense_corrections.amount)).toFixed(2));
  // Whoever reads no expense reads none of its corrections; the journal keeps them.
  await as('school_runtime', a, cashier.user);
  assert.equal(await count('SELECT count(*) n FROM school.expense_correction WHERE expense_id = $1', [boards]), 0);
  await as(null);
  assert.ok(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'expense_correction' AND object_id = $1", [discount]) >= 2);
  await as('school_runtime', a, 1);
});
await check('a correction waiting holds its month\'s closure; validated, it is reported (FR-FIN-07, BR-15)', async () => {
  const clerk = await scopedAccount('internal', 'tenant', null, ['expense.expense.create', 'expense.expense.read']);
  const accountant = await scopedAccount('internal', 'tenant', null, ['expense.expense.validate', 'expense.expense.reverse', 'expense.expense.read',
    'finance.payment.read', 'finance.period.close']);
  const other = await scopedAccount('internal', 'tenant', null, ['expense.expense.validate', 'expense.expense.read']);
  await as('school_runtime', a, clerk.user);
  const missions = (await first("SELECT id FROM school.expense_category WHERE tenant_id = $1 AND code = 'missions'", [a])).id;
  const trip = (await first(EXPENSE, ['today', '3000', missions, null, 'Agence Sahara', 'Sortie pédagogique', null, uuid()])).id;
  await sql("UPDATE school.expense SET status = 'submitted' WHERE id = $1", [trip]);
  await as('school_runtime', a, other.user);
  await sql("UPDATE school.expense SET status = 'validated' WHERE id = $1", [trip]);
  await as('school_runtime', a, accountant.user);
  const before = (await first("SELECT school.reconciliation(current_date) AS r")).r;
  const asked = (await first(CORRECT_EXPENSE, [trip, 'correction', '500', 'Un car de moins', uuid()])).id;
  const waiting = (await first("SELECT school.reconciliation(current_date) AS r")).r;
  assert.equal(waiting.pending, before.pending + 1, 'a correction waiting counts in its month');
  assert.deepEqual(waiting.expense_corrections, before.expense_corrections);
  await as('school_runtime', a, other.user);
  await sql("UPDATE school.expense_correction SET status = 'validated' WHERE id = $1", [asked]);
  await as('school_runtime', a, accountant.user);
  const after = (await first("SELECT school.reconciliation(current_date) AS r")).r;
  assert.equal(after.pending, before.pending);
  assert.deepEqual([after.expense_corrections.count, after.expense_corrections.amount],
    [before.expense_corrections.count + 1, (Number(before.expense_corrections.amount) - 500).toFixed(2)]);
  assert.equal(after.expenses_committed, (Number(before.expenses_committed) - 500).toFixed(2));
  await as('school_runtime', a, 1);
});
// The remuneration rules (SQL 058, FR-REM-01): versions from the first day of a month, rates by node, the most specific winning.
const SAVE_PAY_RULE = 'SELECT school.save_pay_rule($1, $2, $3::numeric, $4, $5::numeric, $6::date, $7::jsonb, $8::jsonb, $9) AS id';
const payMonth = async k => (await first("SELECT (date_trunc('month', current_date) + $1 * interval '1 month')::date::text AS d", [k])).d;
await check('a remuneration rule is a version from the first day of a month, of a kind the member\'s type allows, its rates by node; a change is a new version, never retroactive (FR-REM-01 AC1 to AC8)', async () => {
  await as(null);
  await sql("SELECT platform.enable_module($1, 'payroll')", [a]);
  const payroll =await scopedAccount('internal', 'tenant', null, ['payroll.rule.manage', 'staff.profile.read']);
  const director = await scopedAccount('internal', 'tenant', null, ['payroll.amount.read']);
  const hr = await scopedAccount('internal', 'tenant', null, ['staff.profile.read', 'staff.contract.manage', 'staff.contract.read']);
  await as('school_runtime', a, 1);
  const teacher = (await first(STAFF, [a, 'Mansouri', 'Nadia', 'teacher_internal'])).id;
  const clerk = (await first(STAFF, [a, 'Belkacem', 'Omar', 'administrative'])).id;
  const [m3, m2, m1, m0, n1, n2, n3] = await Promise.all([-3, -2, -1, 0, 1, 2, 3].map(payMonth));
  const day = async (k, d) => (await first('SELECT ($1::date + $2::int)::text AS d', [k, d])).d;
  const [g2as, g4am] = await Promise.all(['2AS', '4AM'].map(nodeOf));
  const rates = list => JSON.stringify(list.map(([node_id, unit_amount]) => ({ node_id, unit_amount })));
  await as('school_runtime', a, payroll.user);
  // AC3: the administrative staff on a fixed monthly salary only, with its recurring bonuses.
  await rejects(SAVE_PAY_RULE, [clerk, 'hourly', null, null, null, m2, rates([[null, '1500']]), '[]', uuid()], '23514', 'pay_rule_kind_refused');
  const salary = (await first(SAVE_PAY_RULE, [clerk, 'fixed_monthly', '45000', null, null, m2, '[]', JSON.stringify([{ label: ' Prime de transport ', amount: '3000' }]), uuid()])).id;
  assert.deepEqual((await sql('SELECT label, amount::text AS amount FROM school.pay_rule_bonus WHERE pay_rule_id = $1', [salary])).rows, [{ label: 'Prime de transport', amount: '3000.00' }]);
  // AC6, AC7: what each kind asks; AC8: one rate per node of the school's referential; from the first day of a month.
  await rejects(SAVE_PAY_RULE, [teacher, 'mixed', '40000', null, '20', m3, rates([[null, '1500']]), '[]', uuid()], '23514', 'pay_rule_incomplete');
  await rejects(SAVE_PAY_RULE, [teacher, 'mixed', null, 'hourly', '20', m3, rates([[null, '1500']]), '[]', uuid()], '23514', 'pay_rule_incomplete');
  await rejects(SAVE_PAY_RULE, [teacher, 'fixed_monthly', '60000', null, null, m3, rates([[null, '1500']]), '[]', uuid()], '23514', 'pay_rule_rate_refused');
  await rejects(SAVE_PAY_RULE, [teacher, 'hourly', null, null, null, m3, rates([[secondaire, '2000']]), '[]', uuid()], '23514', 'pay_rule_default_rate_required');
  await rejects(SAVE_PAY_RULE, [teacher, 'hourly', null, null, null, m3, rates([[null, '1500'], [secondaire, '2000'], [secondaire, '2100']]), '[]', uuid()], '23514', 'pay_rule_rate_duplicate');
  await rejects(SAVE_PAY_RULE, [teacher, 'hourly', null, null, null, m3, rates([[null, '1500'], [uuid(), '2000']]), '[]', uuid()], '23514', 'pay_rule_node_unknown');
  await rejects(SAVE_PAY_RULE, [teacher, 'hourly', null, null, null, await day(m3, 4), rates([[null, '1500']]), '[]', uuid()], '23514', 'pay_rule_month');
  await rejects(SAVE_PAY_RULE, [teacher, 'hourly', null, null, null, m3, rates([[null, '0']]), '[]', uuid()], '23514');
  const key = uuid();
  const hourly = (await first(SAVE_PAY_RULE, [teacher, 'hourly', null, null, null, m3, rates([[null, '1500'], [secondaire, '2000']]), '[]', key])).id;
  assert.equal((await first(SAVE_PAY_RULE, [teacher, 'hourly', null, null, null, m3, rates([[null, '1500'], [secondaire, '2000']]), '[]', key])).id, hourly, 'BR-05');
  await rejects(SAVE_PAY_RULE, [teacher, 'per_session', null, null, null, m3, rates([[null, '3000']]), '[]', key], '23514', 'idempotency_key_reused');
  // AC5: a session of 2AS takes the Secondary rate, one of 4AM the default.
  const rate = async (rule, nodeId) => (await first('SELECT school.pay_rate($1, $2, $3)::text AS r', [a, rule, nodeId])).r;
  assert.deepEqual([await rate(hourly, g2as), await rate(hourly, g4am), await rate(hourly, secondaire)], ['2000.00', '1500.00', '2000.00']);
  // AC1: a version in force keeps its rates; a change is a new version, from this month or one to come, never before.
  await rejects('UPDATE school.pay_rule_rate SET unit_amount = 1600 WHERE pay_rule_id = $1 AND node_id IS NULL', [hourly], '23514', 'pay_rule_immutable');
  await rejects('INSERT INTO school.pay_rule_rate (tenant_id, pay_rule_id, node_id, unit_amount) VALUES ($1, $2, $3, 1800)', [a, hourly, g4am], '23514', 'pay_rule_immutable');
  await rejects('DELETE FROM school.pay_rule WHERE id = $1', [hourly], '23514', 'pay_rule_started');
  await rejects(SAVE_PAY_RULE, [teacher, 'hourly', null, null, null, m1, rates([[null, '1600']]), '[]', uuid()], '23514', 'pay_rule_retroactive');
  const june = (await first(SAVE_PAY_RULE, [teacher, 'hourly', null, null, null, n1, rates([[null, '1600'], [secondaire, '2200']]), '[]', uuid()])).id;
  const ruleOn = async d => (await first('SELECT school.pay_rule_on($1, $2, $3::date) AS id', [a, teacher, d])).id;
  assert.deepEqual([await ruleOn(m3), await ruleOn(m0), await ruleOn(n1), await ruleOn(n3)], [hourly, hourly, june, june]);
  assert.equal((await first('SELECT valid_to::text AS d FROM school.pay_rule WHERE id = $1', [hourly])).d, await day(n1, -1));
  assert.equal(await rate(await ruleOn(m0), g2as), '2000.00', 'AC1: the rate of the month before unchanged');
  // Not begun, its rates still change; one version from a month at a time.
  await sql('UPDATE school.pay_rule_rate SET unit_amount = 2300 WHERE pay_rule_id = $1 AND node_id = $2', [june, secondaire]);
  await rejects(SAVE_PAY_RULE, [teacher, 'per_session', null, null, null, n1, rates([[null, '3000']]), '[]', uuid()], '23514', 'pay_rule_overlap');
  // AC4: from hourly to a fixed salary from the first day of a month to come, the months before kept.
  const fixed = (await first(SAVE_PAY_RULE, [teacher, 'fixed_monthly', '60000', null, null, n2, '[]', '[]', uuid()])).id;
  assert.deepEqual([await ruleOn(m0), await ruleOn(n1), await ruleOn(n2)], [hourly, june, fixed]);
  // Deleted before it begins, the version it ended is in force again.
  await sql('DELETE FROM school.pay_rule WHERE id = $1', [fixed]);
  assert.equal((await first('SELECT valid_to FROM school.pay_rule WHERE id = $1', [june])).valid_to, null);
  assert.equal(await count('SELECT count(*) n FROM school.pay_rule_rate WHERE pay_rule_id = $1', [fixed]), 0);
  // Ended earlier, at the end of a month not gone by, with its reason; never later, never in the past.
  await rejects('UPDATE school.pay_rule SET valid_to = $2::date WHERE id = $1', [june, await day(n3, 4)], '23514', 'pay_rule_month');
  await sql("UPDATE school.pay_rule SET valid_to = $2::date, end_reason = ' Fin de contrat ' WHERE id = $1", [june, await day(n3, -1)]);
  await rejects('UPDATE school.pay_rule SET valid_to = NULL WHERE id = $1', [june], '23514', 'pay_rule_end_later');
  await rejects('UPDATE school.pay_rule SET valid_to = $2::date WHERE id = $1', [hourly, await day(m1, -1)], '23514', 'pay_rule_retroactive');
  await rejects('UPDATE school.pay_rule SET monthly_amount = 1 WHERE id = $1', [salary], '42501');
  // A mix: its fixed part, its basis, what it includes (none by default).
  await as('school_runtime', a, 1);
  const other = (await first(STAFF, [a, 'Saidi', 'Yacine', 'teacher_external'])).id;
  await as('school_runtime', a, payroll.user);
  const mixed = (await first(SAVE_PAY_RULE, [other, 'mixed', '40000', 'hourly', null, m0, rates([[null, '1500']]), '[]', uuid()])).id;
  assert.equal((await first('SELECT included_quantity::text AS q FROM school.pay_rule WHERE id = $1', [mixed])).q, '0.00');
  // AC2: read with `payroll.amount.read`, written by `payroll.rule.manage` only; academic_hr sees none.
  await as('school_runtime', a, director.user);
  assert.equal(await count('SELECT count(*) n FROM school.pay_rule WHERE staff_id = $1', [teacher]), 2);
  await rejects(SAVE_PAY_RULE, [clerk, 'fixed_monthly', '50000', null, null, n1, '[]', '[]', uuid()], '42501');
  await as('school_runtime', a, hr.user);
  assert.equal(await count('SELECT count(*) n FROM school.pay_rule WHERE staff_id = ANY ($1::uuid[])', [[teacher, clerk, other]]), 0);
  assert.equal(await count('SELECT count(*) n FROM school.pay_rule_rate WHERE pay_rule_id = $1', [hourly]), 0);
  await rejects(SAVE_PAY_RULE, [clerk, 'fixed_monthly', '50000', null, null, n1, '[]', '[]', uuid()], '42501');
  await as(null);
  assert.ok(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'pay_rule' AND object_id = $1", [hourly]) >= 2);
  await as('school_runtime', a, 1);
});
// The monthly pays (SQL 059, FR-REM-02, FR-REM-03): a draft a member and month, the four kinds to the cent, validated by someone else, frozen.
const GENERATE_PAYROLL = 'SELECT school.generate_payroll($1::date, $2::uuid) AS n';
const PAYROLL_UNITS = 'SELECT school.save_payroll_units($1, $2::jsonb)';
const ADJUST_PAYROLL = 'SELECT school.adjust_payroll($1, $2, $3::numeric, $4, $5::uuid, $6) AS id';
const units = list => JSON.stringify(list.map(([node_id, worked_on, quantity]) => ({ node_id, worked_on, quantity })));
await check('a member\'s pay for a month: one however often generated, the four kinds and the rate by level to the cent, a missing rate blocking it, validated by someone else and frozen (FR-REM-02 AC1 to AC12, FR-REM-03, TST-12, TST-19)', async () => {
  const rules = await scopedAccount('internal', 'tenant', null, ['payroll.rule.manage', 'staff.profile.read']);
  const gen = await scopedAccount('internal', 'tenant', null, ['payroll.run.generate']);
  const validator = await scopedAccount('internal', 'tenant', null, ['payroll.run.validate']);
  const both = await scopedAccount('internal', 'tenant', null, ['payroll.run.generate', 'payroll.run.validate']);
  const hr = await scopedAccount('internal', 'tenant', null, ['staff.profile.read', 'staff.contract.read']);
  await as('school_runtime', a, 1);
  const member = async (last, type) => (await first(STAFF, [a, last, 'Test', type])).id;
  const [tA, tB, tC, tD, tE, tF, tG] = [await member('Hamdi', 'teacher_internal'), await member('Kaci', 'teacher_external'), await member('Lounis', 'teacher_internal'),
    await member('Meziane', 'teacher_internal'), await member('Nait', 'teacher_internal'), await member('Ouali', 'teacher_external'), await member('Rahmani', 'teacher_internal')];
  const [m3, m2, m1, m0, n1] = await Promise.all([-3, -2, -1, 0, 1].map(payMonth));
  const day = async (k, d) => (await first('SELECT ($1::date + $2::int)::text AS d', [k, d])).d;
  const [g2as, g4am] = await Promise.all(['2AS', '4AM'].map(nodeOf));
  const rates = list => JSON.stringify(list.map(([node_id, unit_amount]) => ({ node_id, unit_amount })));
  // AC9: one joined in the course of last month.
  await sql('UPDATE school.staff SET hire_date = $2::date WHERE id = $1', [tF, await day(m1, 10)]);
  await as('school_runtime', a, rules.user);
  const ruleA = (await first(SAVE_PAY_RULE, [tA, 'hourly', null, null, null, m3, rates([[null, '1500'], [secondaire, '2000']]), '[]', uuid()])).id;
  await first(SAVE_PAY_RULE, [tB, 'per_session', null, null, null, m3, rates([[null, '3000'], [secondaire, '3500']]), '[]', uuid()]);
  await first(SAVE_PAY_RULE, [tC, 'mixed', '40000', 'hourly', '20', m3, rates([[null, '1500']]), '[]', uuid()]);
  await first(SAVE_PAY_RULE, [tD, 'mixed', '40000', 'per_session', '10', m3, rates([[null, '3000'], [secondaire, '3500']]), '[]', uuid()]);
  await first(SAVE_PAY_RULE, [tE, 'fixed_monthly', '60000', null, null, m3, '[]', JSON.stringify([{ label: 'Prime de transport', amount: '3000' }]), uuid()]);
  await first(SAVE_PAY_RULE, [tF, 'mixed', '40000', 'hourly', '0', m3, rates([[null, '1500']]), '[]', uuid()]);
  const ruleG = (await first(SAVE_PAY_RULE, [tG, 'hourly', null, null, null, m3, rates([[null, '1500'], [secondaire, '2000']]), '[]', uuid()])).id;
  // Generated by `payroll.run.generate`, for a month begun, from its first day.
  await as('school_runtime', a, validator.user);
  await rejects(GENERATE_PAYROLL, [m2, null], '42501');
  await as('school_runtime', a, gen.user);
  await rejects(GENERATE_PAYROLL, [n1, null], '23514', 'payroll_period_future');
  await rejects(GENERATE_PAYROLL, [await day(m2, 3), null], '23514', 'pay_rule_month');
  // AC1, TST-12: generated again, never twice.
  const generated = Number((await first(GENERATE_PAYROLL, [m2, null])).n);
  assert.ok(generated >= 6);
  assert.equal(Number((await first(GENERATE_PAYROLL, [m2, null])).n), generated);
  assert.equal(await count('SELECT count(*) n FROM school.payroll_run WHERE staff_id = ANY ($1::uuid[]) AND period = $2', [[tA, tB, tC, tD, tE, tF, tG], m2]), 6);
  await first(GENERATE_PAYROLL, [m1, null]);
  const run = async (staff, period) => first('SELECT id, status, net_amount::text AS net, fixed_amount::text AS fixed, variable_amount::text AS variable, bonus_amount::text AS bonus, adjustment_amount::text AS adjustment, missing_rates, days_paid, days_in_month, version FROM school.payroll_run WHERE staff_id = $1 AND period = $2', [staff, period]);
  const net = async (staff, period) => (await run(staff, period)).net;
  // AC3: 42.5 h at the default 1 500 DA; AC5: 10 h of 2AS at the Secondary 2 000 DA and 8 h of 4AM at the default.
  await sql(PAYROLL_UNITS, [(await run(tA, m2)).id, units([[null, await day(m2, 2), '42.5']])]);
  assert.equal(await net(tA, m2), '63750.00');
  await sql(PAYROLL_UNITS, [(await run(tA, m1)).id, units([[g2as, await day(m1, 3), '10'], [g4am, await day(m1, 4), '8']])]);
  assert.equal(await net(tA, m1), '32000.00');
  assert.deepEqual((await sql('SELECT node_code, quantity::text AS q, unit_amount::text AS rate, amount::text AS amount FROM school.payroll_line WHERE run_id = $1 ORDER BY line_no', [(await run(tA, m1)).id])).rows,
    [{ node_code: '2AS', q: '10.00', rate: '2000.00', amount: '20000.00' }, { node_code: '4AM', q: '8.00', rate: '1500.00', amount: '12000.00' }]);
  // AC6: 12 sessions at 3 000 DA whatever their length; AC10: 5 of 2AS at 3 500 DA and 7 of 4AM at 3 000 DA; whole sessions only.
  await rejects(PAYROLL_UNITS, [(await run(tB, m2)).id, units([[null, await day(m2, 2), '1.5']])], '23514', 'payroll_unit_sessions');
  await sql(PAYROLL_UNITS, [(await run(tB, m2)).id, units([[null, await day(m2, 2), '12']])]);
  assert.equal(await net(tB, m2), '36000.00');
  await sql(PAYROLL_UNITS, [(await run(tB, m1)).id, units([[g2as, await day(m1, 2), '5'], [g4am, await day(m1, 5), '7']])]);
  assert.equal(await net(tB, m1), '38500.00');
  // AC7: 40 000 DA, 20 h included, 26 h done: 40 000 + 6 × 1 500; AC12: 18 h, the fixed part only, never below.
  await sql(PAYROLL_UNITS, [(await run(tC, m2)).id, units([[null, await day(m2, 2), '15'], [null, await day(m2, 9), '11']])]);
  assert.deepEqual([await net(tC, m2), (await run(tC, m2)).variable], ['49000.00', '9000.00']);
  await sql(PAYROLL_UNITS, [(await run(tC, m1)).id, units([[null, await day(m1, 2), '18']])]);
  assert.deepEqual([await net(tC, m1), (await run(tC, m1)).variable], ['40000.00', '0.00']);
  // AC11: by the session, 10 included: 10 of 2AS then 4 of 4AM, the 10 earliest included: 40 000 + 4 × 3 000.
  await sql(PAYROLL_UNITS, [(await run(tD, m2)).id, units([[g4am, await day(m2, 8), '4'], [g2as, await day(m2, 1), '10']])]);
  assert.equal(await net(tD, m2), '52000.00');
  assert.deepEqual((await sql('SELECT node_code, included, quantity::text AS q, amount::text AS amount FROM school.payroll_line WHERE run_id = $1 AND kind = $2 ORDER BY line_no', [(await run(tD, m2)).id, 'unit'])).rows,
    [{ node_code: '2AS', included: true, q: '10.00', amount: '0.00' }, { node_code: '4AM', included: false, q: '4.00', amount: '12000.00' }]);
  // AC4: a fixed salary whatever is done, its recurring bonus added; no units on it.
  await rejects(PAYROLL_UNITS, [(await run(tE, m2)).id, units([[null, await day(m2, 2), '3']])], '23514', 'payroll_units_refused');
  assert.deepEqual([await net(tE, m2), (await run(tE, m2)).bonus], ['63000.00', '3000.00']);
  // AC9: joined on the 11th of last month: the fixed part over its days, the variable part whole; unless the school does not prorate.
  await sql(PAYROLL_UNITS, [(await run(tF, m1)).id, units([[null, await day(m1, 12), '4']])]);
  const f = await run(tF, m1);
  assert.equal(f.days_paid, f.days_in_month - 10);
  assert.equal(f.fixed, (await first('SELECT round(40000::numeric * $1 / $2, 2)::text AS v', [f.days_paid, f.days_in_month])).v);
  assert.equal(f.variable, '6000.00');
  assert.equal(await count('SELECT count(*) n FROM school.payroll_run WHERE staff_id = $1 AND period = $2', [tF, m2]), 0, 'not with the school yet');
  await as('school_runtime', a, 1);
  await sql("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'payroll.prorate_fixed', 'false')", [a]);
  await as('school_runtime', a, gen.user);
  await first(GENERATE_PAYROLL, [m1, tF]);
  assert.equal(await net(tF, m1), '46000.00');
  await as('school_runtime', a, 1);
  await sql("UPDATE school.setting SET value = 'true' WHERE tenant_id = $1 AND key = 'payroll.prorate_fixed'", [a]);
  await as('school_runtime', a, gen.user);
  await first(GENERATE_PAYROLL, [m1, tF]);
  assert.equal((await run(tF, m1)).fixed, f.fixed);
  // Units of the month and done, on a level in use.
  await rejects(PAYROLL_UNITS, [(await run(tA, m2)).id, units([[null, await day(m1, 2), '1']])], '23514', 'payroll_unit_date');
  await rejects(PAYROLL_UNITS, [(await run(tA, m2)).id, units([[uuid(), await day(m2, 2), '1']])], '23514');
  // AC8, BR-16: a unit no rate resolves is marked, never counted at zero, and blocks the validation.
  await as(null);
  await sql('ALTER TABLE school.pay_rule_rate DISABLE TRIGGER USER');
  await sql('DELETE FROM school.pay_rule_rate WHERE pay_rule_id = $1 AND node_id IS NULL', [ruleG]);
  await sql('ALTER TABLE school.pay_rule_rate ENABLE TRIGGER USER');
  await as('school_runtime', a, gen.user);
  await sql(PAYROLL_UNITS, [(await run(tG, m1)).id, units([[g2as, await day(m1, 2), '2'], [g4am, await day(m1, 3), '3']])]);
  const g = await run(tG, m1);
  assert.deepEqual([g.missing_rates, g.net], [1, '4000.00']);
  assert.deepEqual((await sql("SELECT node_code, amount FROM school.payroll_line WHERE run_id = $1 AND kind = 'unit' AND amount IS NULL", [g.id])).rows, [{ node_code: '4AM', amount: null }]);
  await as('school_runtime', a, validator.user);
  await rejects("UPDATE school.payroll_run SET status = 'validated' WHERE id = $1", [g.id], '23514', 'payroll_rate_missing');
  // BR-08: never validated by whoever computed it.
  await as('school_runtime', a, both.user);
  await first(GENERATE_PAYROLL, [m0, tE]);
  await rejects("UPDATE school.payroll_run SET status = 'validated' WHERE id = $1", [(await run(tE, m0)).id], '23514', 'segregation_of_duties');
  // A total written by hand, its lines left: validated on what holds only.
  await as('school_runtime', a, gen.user);
  await sql('UPDATE school.payroll_run SET net_amount = 1 WHERE id = $1', [(await run(tB, m2)).id]);
  await as('school_runtime', a, validator.user);
  await rejects("UPDATE school.payroll_run SET status = 'validated' WHERE id = $1", [(await run(tB, m2)).id], '23514', 'payroll_run_stale');
  await as('school_runtime', a, gen.user);
  await first(GENERATE_PAYROLL, [m2, tB]);
  assert.equal(await net(tB, m2), '36000.00');
  // Validated by someone else, then frozen (AC2): never recomputed, its units and lines kept.
  await as('school_runtime', a, validator.user);
  assert.equal((await sql("UPDATE school.payroll_run SET status = 'validated' WHERE id = $1 RETURNING id", [(await run(tA, m2)).id])).rows.length, 1);
  const validatedA = await first('SELECT id, validated_by FROM school.payroll_run WHERE staff_id = $1 AND period = $2', [tA, m2]);
  assert.equal(String(validatedA.validated_by), String(validator.user));
  await rejects('UPDATE school.payroll_run SET status = $2 WHERE id = $1', [validatedA.id, 'draft'], '23514', 'payroll_run_validated');
  await as('school_runtime', a, gen.user);
  await rejects(GENERATE_PAYROLL, [m2, tA], '23514', 'payroll_run_validated');
  assert.equal(Number((await first(GENERATE_PAYROLL, [m2, null])).n), generated - 1);
  await rejects(PAYROLL_UNITS, [validatedA.id, units([[null, await day(m2, 2), '50']])], '23514', 'payroll_run_validated');
  await rejects(ADJUST_PAYROLL, [validatedA.id, 'adjustment', '-500', 'Retenue', null, uuid()], '23514', 'payroll_run_validated');
  await rejects('DELETE FROM school.payroll_line WHERE run_id = $1', [validatedA.id], '23514', 'payroll_run_validated');
  await rejects('DELETE FROM school.payroll_run WHERE id = $1', [validatedA.id], '42501');
  // FR-REM-03 AC2: a rate changed after the validation changes neither the detail nor the total; a draft computed before is stale.
  await as(null);
  await sql('ALTER TABLE school.pay_rule_rate DISABLE TRIGGER USER');
  await sql('UPDATE school.pay_rule_rate SET unit_amount = 1700 WHERE pay_rule_id = $1 AND node_id IS NULL', [ruleA]);
  await sql('ALTER TABLE school.pay_rule_rate ENABLE TRIGGER USER');
  await as('school_runtime', a, validator.user);
  assert.equal(await net(tA, m2), '63750.00');
  assert.equal((await first("SELECT unit_amount::text AS r FROM school.payroll_line WHERE run_id = $1 AND kind = 'unit'", [validatedA.id])).r, '1500.00');
  await rejects("UPDATE school.payroll_run SET status = 'validated' WHERE id = $1", [(await run(tA, m1)).id], '23514', 'payroll_run_stale');
  await as('school_runtime', a, gen.user);
  await first(GENERATE_PAYROLL, [m1, tA]);
  assert.equal(await net(tA, m1), '33600.00');
  await as(null);
  await sql('ALTER TABLE school.pay_rule_rate DISABLE TRIGGER USER');
  await sql('UPDATE school.pay_rule_rate SET unit_amount = 1500 WHERE pay_rule_id = $1 AND node_id IS NULL', [ruleA]);
  await sql('ALTER TABLE school.pay_rule_rate ENABLE TRIGGER USER');
  await as('school_runtime', a, gen.user);
  await first(GENERATE_PAYROLL, [m1, tA]);
  assert.equal(await net(tA, m1), '32000.00');
  // FR-REM-03 AC1: a correction of a validated pay is a traced adjustment of a later month, linked to it, once per key.
  const draftA = (await run(tA, m1)).id;
  await rejects(ADJUST_PAYROLL, [draftA, 'adjustment', '-500', 'Retenue', (await run(tB, m1)).id, uuid()], '23514', 'payroll_correction_invalid');
  await as('school_runtime', a, gen.user);
  await first(GENERATE_PAYROLL, [m0, tA]);
  const currentA = (await run(tA, m0)).id;
  const key = uuid();
  const adjusted = (await first(ADJUST_PAYROLL, [currentA, 'adjustment', '-2000', ' Heures de mars comptées deux fois ', validatedA.id, key])).id;
  assert.equal((await first(ADJUST_PAYROLL, [currentA, 'adjustment', '-2000', 'Heures de mars comptées deux fois', validatedA.id, key])).id, adjusted, 'BR-05');
  await rejects(ADJUST_PAYROLL, [currentA, 'adjustment', '-2500', 'Autre', null, key], '23514', 'idempotency_key_reused');
  await first(ADJUST_PAYROLL, [currentA, 'bonus', '1500', 'Prime exceptionnelle', null, uuid()]);
  await rejects(ADJUST_PAYROLL, [currentA, 'bonus', '-100', 'Prime négative', null, uuid()], '23514');
  await rejects(ADJUST_PAYROLL, [currentA, 'adjustment', '100', '  ', null, uuid()], '23514');
  const c = await run(tA, m0);
  assert.deepEqual([c.adjustment, c.bonus, c.net], ['-2000.00', '1500.00', '-500.00']);
  assert.equal((await first('SELECT reason FROM school.payroll_adjustment WHERE id = $1', [adjusted])).reason, 'Heures de mars comptées deux fois');
  await rejects('UPDATE school.payroll_adjustment SET amount = -1 WHERE id = $1', [adjusted], '42501');
  await as('school_runtime', a, validator.user);
  await rejects("UPDATE school.payroll_run SET status = 'validated' WHERE id = $1", [currentA], '23514', 'payroll_net_negative');
  // Read with what computes or validates them, never by academic_hr.
  assert.ok(await count('SELECT count(*) n FROM school.payroll_line WHERE run_id = $1', [validatedA.id]) > 0);
  await as('school_runtime', a, hr.user);
  assert.equal(await count('SELECT count(*) n FROM school.payroll_run WHERE staff_id = ANY ($1::uuid[])', [[tA, tB, tC]]), 0);
  assert.equal(await count('SELECT count(*) n FROM school.payroll_line WHERE run_id = $1', [validatedA.id]), 0);
  await rejects(GENERATE_PAYROLL, [m2, null], '42501');
  await as(null);
  assert.ok(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'payroll_adjustment' AND object_id = $1", [adjusted]) >= 1);
  await as('school_runtime', a, 1);
});
const PAY_OUT = 'SELECT school.record_payroll_payout($1, $2::numeric, $3::date, $4, $5, $6) AS id';
const REVERSE_PAYOUT = 'SELECT school.reverse_payroll_payout($1, $2, $3) AS id';
await check('a validated pay paid in parts, each payout its `salaries` expense in the same transaction, never beyond its net, once per key; taken back by a reversal decided by someone else; a member reads their own (FR-REM-04 AC1, AC2, FR-REM-05, BR-06, TST-13)', async () => {
  const rules = await scopedAccount('internal', 'tenant', null, ['payroll.rule.manage', 'staff.profile.read']);
  const gen = await scopedAccount('internal', 'tenant', null, ['payroll.run.generate']);
  const validator = await scopedAccount('internal', 'tenant', null, ['payroll.run.validate']);
  const payer = await scopedAccount('internal', 'tenant', null, ['payroll.payout.record', 'payroll.amount.read', 'expense.expense.read', 'expense.expense.pay',
    'expense.expense.reverse', 'finance.payment.read']);
  const both = await scopedAccount('internal', 'tenant', null, ['payroll.payout.record', 'payroll.run.validate']);
  const clerk = await scopedAccount('internal', 'tenant', null, ['expense.expense.read', 'expense.expense.validate', 'expense.expense.pay']);
  const teacher = await scopedAccount('internal', 'tenant', null, ['payroll.self_view']);
  await as('school_runtime', a, 1);
  const member = await first(STAFF, [a, 'Saadi', 'Paie', 'teacher_internal']);
  const colleague = (await first(STAFF, [a, 'Taleb', 'Paie', 'administrative'])).id;
  await sql('UPDATE school.staff SET user_id = $2 WHERE id = $1', [member.id, teacher.user]);
  const [m1, m0] = await Promise.all([-1, 0].map(payMonth));
  const day = async (k, d) => (await first('SELECT ($1::date + $2::int)::text AS d', [k, d])).d;
  const today = (await first('SELECT current_date::text AS d')).d;
  await as('school_runtime', a, rules.user);
  await first(SAVE_PAY_RULE, [member.id, 'fixed_monthly', '60000', null, null, m1, '[]', '[]', uuid()]);
  await first(SAVE_PAY_RULE, [colleague, 'fixed_monthly', '45000', null, null, m1, '[]', '[]', uuid()]);
  await as('school_runtime', a, gen.user);
  for (const staff of [member.id, colleague]) for (const month of [m1, m0]) await first(GENERATE_PAYROLL, [month, staff]);
  const run = async (staff, period) => first(`SELECT r.id, r.status, r.net_amount::text AS net, school.payroll_paid(r.tenant_id, r.id)::text AS paid,
    school.payroll_state(r.status, r.net_amount, school.payroll_paid(r.tenant_id, r.id)) AS state FROM school.payroll_run r WHERE r.staff_id = $1 AND r.period = $2`, [staff, period]);
  const pay = (await run(member.id, m1)).id;
  const draft = (await run(member.id, m0)).id;
  await as('school_runtime', a, validator.user);
  await sql("UPDATE school.payroll_run SET status = 'validated' WHERE id = ANY ($1::uuid[])", [[pay, (await run(colleague, m1)).id]]);
  // Paid by `payroll.payout.record` only, once validated.
  await rejects(PAY_OUT, [pay, '1000', 'today', 'cash', null, uuid()], '42501');
  await as('school_runtime', a, payer.user);
  await rejects(PAY_OUT, [draft, '1000', 'today', 'cash', null, uuid()], '23514', 'payroll_run_not_payable');
  assert.equal((await run(member.id, m1)).state, 'validated');
  // AC1: half of it, `partially_paid`, and the expense of that amount, `salaries`, validated and paid, in the same transaction (BR-06).
  const key = uuid();
  const half = (await first(PAY_OUT, [pay, '30000', await day(m1, 27), 'transfer', 'VIR 77', key])).id;
  assert.deepEqual(Object.values(await run(member.id, m1)).slice(1), ['validated', '60000.00', '30000.00', 'partially_paid']);
  const expense = await first(`SELECT x.id, x.amount::text AS amount, x.expense_date::text AS day, x.status, x.beneficiary, c.code, school.expense_paid(x.tenant_id, x.id)::text AS paid
    FROM school.expense x JOIN school.expense_category c ON c.id = x.category_id WHERE x.payroll_payout_id = $1`, [half]);
  assert.deepEqual([expense.amount, expense.day, expense.status, expense.code, expense.paid], ['30000.00', await day(m1, 27), 'validated', 'salaries', '30000.00']);
  assert.ok(expense.beneficiary.includes('Saadi') && expense.beneficiary.includes(member.staff_number));
  // BR-05: the same key answers the first payout; another payout with it is refused.
  assert.equal((await first(PAY_OUT, [pay, '30000', await day(m1, 27), 'transfer', 'VIR 77', key])).id, half);
  await rejects(PAY_OUT, [pay, '20000', 'today', 'cash', null, key], '23514', 'idempotency_key_reused');
  assert.equal(await count('SELECT count(*) n FROM school.expense WHERE payroll_payout_id = $1', [half]), 1);
  // Never beyond what is left of the net, nor on a day to come, before its month, or by a means the school refuses.
  await rejects(PAY_OUT, [pay, '30000.01', 'today', 'cash', null, uuid()], '23514', 'payment_exceeds_balance');
  await rejects(PAY_OUT, [pay, '100', await day(m0, 40), 'cash', null, uuid()], '23514', 'payment_future_date');
  await rejects(PAY_OUT, [pay, '100', await day(m1, -1), 'cash', null, uuid()], '23514', 'payroll_payout_date');
  await rejects(PAY_OUT, [pay, '100', 'today', 'bitcoin', null, uuid()], '23514', 'payment_method_unknown');
  const rest = (await first(PAY_OUT, [pay, '30000', 'today', 'cash', null, uuid()])).id;
  assert.equal((await run(member.id, m1)).state, 'paid');
  // FR-DEP-01 AC1: the payroll's expense is never entered, paid, corrected nor taken back by hand.
  const linked = (await first('SELECT id FROM school.expense WHERE payroll_payout_id = $1', [rest])).id;
  await rejects(PAY_EXPENSE, [linked, '1', 'today', 'cash', null, uuid()], '23514', 'expense_payroll');
  const linkedPayment = (await first('SELECT id FROM school.expense_payment WHERE expense_id = $1', [linked])).id;
  await rejects(REVERSE_EXPENSE_PAYMENT, [linkedPayment, 'Par la main', uuid()], '23514', 'expense_payroll');
  await rejects(CORRECT_EXPENSE, [linked, 'cancellation', null, 'Par la main', uuid()], '23514', 'expense_payroll');
  const salaries = (await first("SELECT id FROM school.expense_category WHERE tenant_id = $1 AND code = 'salaries'", [a])).id;
  await rejects('INSERT INTO school.expense (tenant_id, expense_date, amount, category_id, beneficiary, reason, payroll_payout_id, idempotency_key) VALUES ($1, current_date, 1, $2, $3, $4, $5, $6)',
    [a, salaries, 'Quelqu\'un', 'À la main', half, uuid()], '42501');
  await rejects('UPDATE school.payroll_payout SET amount = 1 WHERE id = $1', [half], '42501');
  // What a member is paid is read with the pays' rights only, even as an expense.
  await as('school_runtime', a, clerk.user);
  assert.equal(await count('SELECT count(*) n FROM school.expense WHERE id = $1', [linked]), 0);
  await rejects(PAY_EXPENSE, [linked, '1', 'today', 'cash', null, uuid()], '42501');
  // A payout taken back by its reversal, asked for with its reason, decided by someone else (BR-08).
  await as('school_runtime', a, payer.user);
  await rejects(REVERSE_PAYOUT, [half, '  ', uuid()], '23514', 'reason_required');
  const back = (await first(REVERSE_PAYOUT, [half, 'Virement rejeté par la banque', uuid()])).id;
  await rejects(REVERSE_PAYOUT, [half, 'Encore', uuid()], '23514', 'payment_not_reversible');
  assert.ok((await first('SELECT school.reconciliation(current_date) AS r')).r.pending >= 1, 'a reversal waiting holds its month');
  assert.equal((await sql("UPDATE school.payroll_payout SET status = 'validated' WHERE id = $1", [back])).rowCount ?? 0, 0);
  await as('school_runtime', a, both.user);
  const own = (await first(REVERSE_PAYOUT, [rest, 'Payé deux fois', uuid()])).id;
  await rejects("UPDATE school.payroll_payout SET status = 'validated' WHERE id = $1", [own], '23514', 'segregation_of_duties');
  await as('school_runtime', a, validator.user);
  await rejects("UPDATE school.payroll_payout SET status = 'rejected' WHERE id = $1", [own], '23514', 'reason_required');
  await sql("UPDATE school.payroll_payout SET status = 'rejected', decision_reason = 'Le paiement en espèces est juste' WHERE id = $1", [own]);
  await sql("UPDATE school.payroll_payout SET status = 'validated' WHERE id = $1", [back]);
  await rejects("UPDATE school.payroll_payout SET status = 'rejected', decision_reason = 'Erreur' WHERE id = $1", [back], '23514', 'payroll_payout_immutable');
  // The payout reversed, the original kept; its expense's payment taken back and the expense cancelled, both on the reversal's day.
  await as('school_runtime', a, payer.user);
  assert.equal((await first('SELECT status FROM school.payroll_payout WHERE id = $1', [half])).status, 'reversed');
  assert.deepEqual(Object.values(await run(member.id, m1)).slice(3), ['30000.00', 'partially_paid']);
  const cancelled = await first(`SELECT x.amount::text AS amount, school.expense_committed(x.tenant_id, x.id)::text AS committed, school.expense_paid(x.tenant_id, x.id)::text AS paid,
    (SELECT corrected_on::text FROM school.expense_correction k WHERE k.expense_id = x.id AND k.status = 'validated') AS day FROM school.expense x WHERE x.payroll_payout_id = $1`, [half]);
  assert.deepEqual(Object.values(cancelled), ['30000.00', '0.00', '0.00', today]);
  await first(PAY_OUT, [pay, '30000', 'today', 'transfer', 'VIR 78', uuid()]);
  assert.equal((await run(member.id, m1)).state, 'paid');
  // TST-13: each month, the `salaries` expenses committed equal what is paid of the pays, never counted twice.
  for (const month of [m1, m0]) {
    const report = (await first('SELECT school.reconciliation($1::date) AS r', [month])).r;
    assert.equal(report.salaries_committed, report.payroll_paid, month);
    assert.ok(Number(report.payroll_paid) > 0, month);
  }
  const current = (await first('SELECT school.reconciliation(current_date) AS r')).r;
  assert.ok(current.payroll_reversals.count >= 1 && Number(current.expenses_paid) >= Number(current.payroll_paid));
  // FR-REM-05: a member reads their own validated pays, their lines and payouts, once the school says so; never anyone else's.
  await as('school_runtime', a, teacher.user);
  assert.equal(await count('SELECT count(*) n FROM school.payroll_run'), 0, 'not before the school says so');
  await as('school_runtime', a, 1);
  await sql("INSERT INTO school.setting (tenant_id, key, value) VALUES ($1, 'payroll.self_view', 'true')", [a]);
  await as('school_runtime', a, teacher.user);
  assert.deepEqual((await sql('SELECT id FROM school.payroll_run')).rows.map(r => r.id), [pay], 'the validated one, not the draft nor a colleague\'s');
  assert.ok(await count('SELECT count(*) n FROM school.payroll_line WHERE run_id = $1', [pay]) > 0);
  assert.equal(await count('SELECT count(*) n FROM school.payroll_payout WHERE run_id = $1', [pay]), 5);
  assert.equal(await count('SELECT count(*) n FROM school.payroll_unit'), 0);
  assert.equal(await count('SELECT count(*) n FROM school.expense WHERE payroll_payout_id IS NOT NULL'), 0);
  await rejects(PAY_OUT, [pay, '1', 'today', 'cash', null, uuid()], '42501');
  await as('school_runtime', a, 1);
  await sql("UPDATE school.setting SET value = 'false' WHERE tenant_id = $1 AND key = 'payroll.self_view'", [a]);
  await as(null);
  assert.ok(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'payroll_payout' AND object_id = $1", [back]) >= 2);
  assert.ok(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'expense' AND object_id = $1", [linked]) >= 1);
  await as('school_runtime', a, 1);
});
// The calendar (SQL 061, FR-PLN-01): the week's open days, dated closures and openings for the whole school or a site, the grid of time slots.
await as('platform_admin', null, 6);
await sql("SELECT platform.enable_module($1, 'scheduling_manual')", [a]);
await as('school_runtime', a, 1);
const EXCEPTION = 'INSERT INTO school.calendar_exception (tenant_id, site_id, kind, date_from, date_to, reason) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id';
const TIME_SLOT = 'INSERT INTO school.time_slot (tenant_id, code, start_time, end_time) VALUES ($1, $2, $3, $4) RETURNING id';
await check('the school is open on the week\'s days; a dated closure or opening prevails, a site\'s over the school\'s (FR-PLN-01)', async () => {
  const open = async (day, site = null) => (await first('SELECT school.day_open($1::date, $2::uuid) AS ok', [day, site])).ok;
  // Two weeks ahead, clear of the days other checks use.
  const [sun, mon, tue, wed, thu, fri] = await Promise.all([14, 15, 16, 17, 18, 19].map(n => dayAfter(sunday, n)));
  // Sunday to Thursday by default; Friday closed.
  assert.deepEqual([await open(sun), await open(thu), await open(fri), await open(fri, siteA)], [true, true, false, false]);
  // A public holiday for the whole school closes every site.
  const holiday = (await first(EXCEPTION, [a, null, 'holiday', mon, mon, 'Fête de l\'indépendance'])).id;
  assert.deepEqual([await open(mon), await open(mon, siteA), await open(mon, staffSiteB)], [false, false, false]);
  await rejects(EXCEPTION, [a, null, 'closure', sun, mon, 'Chevauche la fête'], '23514', 'calendar_exception_overlap');
  // A make-up Friday on one site only.
  await first(EXCEPTION, [a, siteA, 'opening', fri, fri, 'Rattrapage du lundi férié']);
  assert.deepEqual([await open(fri, siteA), await open(fri, staffSiteB), await open(fri)], [true, false, false]);
  // The site's exception prevails over the school's: a vacation for all, one site kept open on its first day.
  await first(EXCEPTION, [a, null, 'vacation', wed, thu, 'Vacances de printemps']);
  await first(EXCEPTION, [a, siteA, 'opening', wed, wed, 'Stage intensif']);
  assert.deepEqual([await open(wed, siteA), await open(thu, siteA), await open(wed, staffSiteB), await open(tue)], [true, false, false, true]);
  await rejects(EXCEPTION, [a, null, 'closure', yesterday, today, 'Hier'], '23514', 'calendar_exception_past');
  await rejects(EXCEPTION, [a, siteA, 'closure', thu, wed, 'A l\'envers'], '23514');
  await rejects(EXCEPTION, [a, siteA, 'closure', tue, tue, '   '], '23514');
  await rejects(EXCEPTION, [a, siteA, 'party', tue, tue, 'Inconnu'], '23514');
  // It keeps its days; cancelled, the week's rule is back.
  await rejects('UPDATE school.calendar_exception SET date_to = date_to + 1 WHERE id = $1', [holiday], '42501');
  await sql("UPDATE school.calendar_exception SET status = 'cancelled' WHERE id = $1", [holiday]);
  assert.equal(await open(mon, siteA), true);
  await rejects("UPDATE school.calendar_exception SET status = 'planned' WHERE id = $1", [holiday], '23514', 'status_transition_refused');
  // An exception whose days are gone stays as it is.
  await as(null);
  await sql('ALTER TABLE school.calendar_exception DISABLE TRIGGER USER');
  const past = (await first("INSERT INTO school.calendar_exception (tenant_id, kind, date_from, date_to, reason, created_at, updated_at) VALUES ($1, 'closure', current_date - 5, current_date - 3, 'Passée', now(), now()) RETURNING id", [a])).id;
  await sql('ALTER TABLE school.calendar_exception ENABLE TRIGGER USER');
  await as('school_runtime', a, 1);
  await rejects("UPDATE school.calendar_exception SET status = 'cancelled' WHERE id = $1", [past], '23514', 'calendar_exception_ended');
  // A site's grant keeps its site's calendar, never another's nor the whole school's; a reader keeps none.
  const local = await scopedAccount('internal', 'site', [staffSiteB], ['schedule.calendar.manage']);
  await as('school_runtime', a, local.user);
  assert.ok((await first(EXCEPTION, [a, staffSiteB, 'closure', tue, tue, 'Coupure d\'électricité'])).id);
  await rejects(EXCEPTION, [a, siteA, 'closure', tue, tue, 'Ailleurs'], '42501');
  await rejects(EXCEPTION, [a, null, 'closure', tue, tue, 'Partout'], '42501');
  const reader = await scopedAccount('internal', 'tenant', null, ['schedule.session.read']);
  await as('school_runtime', a, reader.user);
  assert.equal(await open(tue, staffSiteB), false, 'every member reads the calendar');
  await rejects(EXCEPTION, [a, null, 'closure', tue, tue, 'Sans droit'], '42501', 'permission_denied');
  await as('school_runtime', a, 1);
});
await check('the grid of time slots: codes unique, to the minute, never changed, archived and never deleted (FR-PLN-01)', async () => {
  const early = (await first(TIME_SLOT, [a, 'M1', '08:00', '09:30'])).id;
  await first(TIME_SLOT, [a, 'M2', '09:30', '11:00']);
  // Slots may overlap one another (a long one over two short ones), never twice the same times.
  await first(TIME_SLOT, [a, 'M12', '08:00', '11:00']);
  await rejects(TIME_SLOT, [a, 'M1', '14:00', '15:00'], '23505');
  await rejects(TIME_SLOT, [a, 'M1-BIS', '08:00', '09:30'], '23505');
  await rejects(TIME_SLOT, [a, 'X1', '10:00', '09:00'], '23514');
  await rejects(TIME_SLOT, [a, 'X2', '10:00:30', '11:00'], '23514');
  await rejects(TIME_SLOT, [a, 'X 3', '10:00', '11:00'], '23514');
  assert.ok((await first(TIME_SLOT, [a, 'SOIR', '20:00', '24:00'])).id, 'until midnight');
  await rejects("UPDATE school.time_slot SET start_time = '08:15' WHERE id = $1", [early], '42501');
  await sql('UPDATE school.time_slot SET archived_at = now() WHERE id = $1', [early]);
  await rejects('UPDATE school.time_slot SET archived_at = NULL WHERE id = $1', [early], '23514', 'time_slot_archived');
  assert.ok((await first(TIME_SLOT, [a, 'M1-N', '08:00', '09:30'])).id, 'the same times once the first is archived');
  await rejects('DELETE FROM school.time_slot WHERE id = $1', [early], '42501');
  // The whole school's grid: a site's grant changes none of it.
  const local = await scopedAccount('internal', 'site', [siteA], ['schedule.calendar.manage']);
  await as('school_runtime', a, local.user);
  await rejects(TIME_SLOT, [a, 'LOCAL', '12:00', '13:00'], '42501');
  await as(null);
  assert.ok(await count("SELECT count(*) n FROM school.audit_log WHERE object_type = 'time_slot' AND object_id = $1", [early]) >= 2);
  await as('school_runtime', a, 1);
});
// The rooms above leave the school's sites free to be archived (FR-SLL-01).
await sql('UPDATE school.study_group SET main_room_id = NULL WHERE tenant_id = $1 AND main_room_id IS NOT NULL', [a]);
await sql('UPDATE school.room SET archived_at = now() WHERE tenant_id = $1 AND archived_at IS NULL', [a]);
// The last-site concurrency test below expects the school's two sites of before.
await as(null);
await sql('UPDATE school.site SET archived_at = now() WHERE id = $1', [staffSiteB]);
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
  await check('two members of staff recorded at once receive two numbers (FR-STF-01)', async () => {
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
    const numbers = await Promise.all(clients.map(async (c, i) => {
      await c.query('BEGIN');
      try {
        const made = (await c.query(STAFF, [a, 'Course', 'Personne ' + i, 'other'])).rows[0].staff_number;
        await c.query('SELECT pg_sleep(0.15)');
        await c.query('COMMIT');
        return made;
      } catch (e) { await c.query('ROLLBACK'); throw e; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.notEqual(numbers[0], numbers[1]);
    assert.deepEqual(numbers.map(n => /^P\d{4,}$/.test(n)), [true, true]);
  });
  await check('the same qualification given twice at once is recorded once (FR-STF-02)', async () => {
    await as('school_runtime', a, 1);
    const teacher = await newStaff('Course', 'Habilitation');
    const node = await nodeOf('4AP');
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
    const results = await Promise.allSettled(clients.map(async c => {
      await c.query('BEGIN');
      try {
        await c.query(QUALIFY, [a, teacher.id, maths, node, null, null]);
        await c.query('SELECT pg_sleep(0.15)');
        await c.query('COMMIT');
      } catch (e) { await c.query('ROLLBACK'); throw e; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(results.find(r => r.status === 'rejected').reason.message, 'qualification_overlap');
    assert.equal(await count('SELECT count(*) n FROM school.qualification WHERE staff_id = $1', [teacher.id]), 1);
  });
  await check('two declarations of the same day, or two overlapping days off, made at once are recorded once (FR-STF-03)', async () => {
    await as('school_runtime', a, 1);
    const teacher = await newStaff('Course', 'Disponibilite');
    for (const [statement, values, rule] of [[DECLARE, [a, teacher.id, tomorrow, '[]', null, null, null], 'availability_same_day'],
      [DAY_OFF, [a, teacher.id, tomorrow, tomorrow, 'Absence annoncée'], 'unavailability_overlap']]) {
      const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
      const results = await Promise.allSettled(clients.map(async c => {
        await c.query('BEGIN');
        try {
          await c.query(statement, values);
          await c.query('SELECT pg_sleep(0.15)');
          await c.query('COMMIT');
        } catch (e) { await c.query('ROLLBACK'); throw e; }
      }));
      await Promise.all(clients.map(c => c.end()));
      assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
      assert.equal(results.find(r => r.status === 'rejected').reason.message, rule);
    }
    assert.equal(await count('SELECT count(*) n FROM school.availability WHERE staff_id = $1', [teacher.id]), 1);
    assert.equal(await count('SELECT count(*) n FROM school.unavailability WHERE staff_id = $1', [teacher.id]), 1);
  });
  await check('two overlapping contracts, or two overlapping leaves, recorded at once are recorded once (FR-STF-04)', async () => {
    await as('school_runtime', a, 1);
    const member = await newStaff('Course', 'Contrat');
    for (const [statement, values, rule] of [[CONTRACT, [a, member.id, 'permanent', tomorrow, null, null, null], 'contract_overlap'],
      [LEAVE, [a, member.id, 'annual', tomorrow, tomorrow, null], 'leave_overlap']]) {
      const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
      const results = await Promise.allSettled(clients.map(async c => {
        await c.query('BEGIN');
        try {
          await c.query(statement, values);
          await c.query('SELECT pg_sleep(0.15)');
          await c.query('COMMIT');
        } catch (e) { await c.query('ROLLBACK'); throw e; }
      }));
      await Promise.all(clients.map(c => c.end()));
      assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
      assert.equal(results.find(r => r.status === 'rejected').reason.message, rule);
    }
    assert.equal(await count('SELECT count(*) n FROM school.staff_contract WHERE staff_id = $1', [member.id]), 1);
    assert.equal(await count('SELECT count(*) n FROM school.staff_leave WHERE staff_id = $1', [member.id]), 1);
    assert.equal(await count('SELECT count(*) n FROM school.unavailability WHERE staff_id = $1', [member.id]), 1);
  });
  await check('two overlapping closures of a room made at once are recorded once (FR-SLL-02)', async () => {
    await as('school_runtime', a, 1);
    const hall = (await first(ROOM, [a, siteA, 'COURSE-1', '{"fr": "Course"}', 30, 'CLASSROOM', []])).id;
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
    const results = await Promise.allSettled(clients.map(async c => {
      await c.query('BEGIN');
      try {
        await c.query(CLOSURE, [a, hall, tomorrow, tomorrow, 'Travaux']);
        await c.query('SELECT pg_sleep(0.15)');
        await c.query('COMMIT');
      } catch (e) { await c.query('ROLLBACK'); throw e; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(results.find(r => r.status === 'rejected').reason.message, 'room_closure_overlap');
    assert.equal(await count('SELECT count(*) n FROM school.room_closure WHERE room_id = $1', [hall]), 1);
    // Its site free to be archived again (FR-SLL-01).
    await sql('UPDATE school.room SET archived_at = now() WHERE id = $1', [hall]);
  });
  await check('two overlapping closures of the school made at once are recorded once (FR-PLN-01)', async () => {
    await as('school_runtime', a, 1);
    // Three weeks ahead, clear of the calendar's other checks.
    const day = await dayAfter(sunday, 22);
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
    const results = await Promise.allSettled(clients.map(async (c, i) => {
      await c.query('BEGIN');
      try {
        await c.query(EXCEPTION, [a, null, 'closure', day, day, 'Grève ' + i]);
        await c.query('SELECT pg_sleep(0.15)');
        await c.query('COMMIT');
      } catch (e) { await c.query('ROLLBACK'); throw e; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(results.find(r => r.status === 'rejected').reason.message, 'calendar_exception_overlap');
    assert.equal(await count("SELECT count(*) n FROM school.calendar_exception WHERE date_from = $1 AND site_id IS NULL AND status = 'planned'", [day]), 1);
  });
  await check('two conversions of a prospect\'s child at once make one pupil; the other\'s pupil is undone with it (FR-CRM-02 AC1)', async () => {
    await as('school_runtime', a, 1);
    const program = await programme('CRM-RACE');
    const family = await prospect(siteA, 'Course');
    const child = (await first(CHILD, [a, family.id, 'Double', 'Clic', null])).id;
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
    const results = await Promise.allSettled(clients.map(async (c, i) => {
      await c.query('BEGIN');
      try {
        const pupil = (await c.query(STUDENT, [a, 'Clic', 'Double' + i, null, null, '1995-03-14', pupilFields])).rows[0].id;
        const enrolment = (await c.query(ENROLL, [pupil, program, uuid()])).rows[0].id;
        await c.query('UPDATE school.lead_child SET student_id = $2, enrollment_id = $3, conversion_key = $4 WHERE id = $1', [child, pupil, enrolment, uuid()]);
        await c.query('SELECT pg_sleep(0.15)');
        await c.query('COMMIT');
      } catch (e) { await c.query('ROLLBACK'); throw e; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(results.find(r => r.status === 'rejected').reason.message, 'lead_already_converted');
    assert.equal(await count("SELECT count(*) n FROM school.student WHERE last_name_lat = 'Clic'"), 1);
  });
  await check('the same annual decision sent twice at once enrols the pupil once (FR-INS-05 AC2)', async () => {
    await as(null);
    const later = (await first("INSERT INTO school.academic_year (tenant_id, code, start_date, end_date, status) VALUES ($1, 'COURSE-N1', '2029-09-01', '2030-06-30', 'planned') RETURNING id", [a])).id;
    const nextGrade = (await first("SELECT id FROM school.ref_node WHERE tenant_id = $1 AND type_code = 'grade' AND archived_at IS NULL AND id <> $2 ORDER BY code LIMIT 1", [a, grade])).id;
    await as('school_runtime', a, 1);
    const source = await programme('COURSE-PA');
    const sourceClass = await group('COURSE-PA1', source, 5);
    const target = (await first(PROGRAM, [a, later, nextGrade, 'COURSE-PB', '{"fr": "Suivant"}', 100000, 'active'])).id;
    const up = (await first(GROUP, [a, later, 'COURSE-PB1', '{"fr": "Classe suivante"}', 'class', target, siteA, 5])).id;
    const e = await enroll((await newAdult('Course', 'Passage')).id, source);
    await joinGroup(e, sourceClass);
    await sql("UPDATE school.enrollment SET status = 'validated' WHERE id = $1", [e]);
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', a, 1)));
    const results = await Promise.allSettled(clients.map(async c => {
      await c.query('BEGIN');
      try {
        const id = (await c.query('SELECT school.decide_progression($1, $2, $3, $4, $5::date) AS id', [e, 'promoted', 'Admis', up, '2029-09-15'])).rows[0].id;
        await c.query('SELECT pg_sleep(0.15)');
        await c.query('COMMIT');
        return id;
      } catch (err) { await c.query('ROLLBACK'); throw err; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.deepEqual(results.map(r => r.status), ['fulfilled', 'fulfilled']);
    assert.equal(results[0].value, results[1].value);
    assert.equal(await count('SELECT count(*) n FROM school.enrollment WHERE program_id = $1', [target]), 1);
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
  await check('five sendings of the same payment at once record one; two payments at once never pass what is left (TST-10, FR-FIN-02 AC1)', async () => {
    const cashier = await scopedAccount('internal', 'tenant', null, ['finance.payment.create', 'finance.payment.read', 'enrollment.enrollment.read']);
    await as('school_runtime', a, 1);
    const e = await enroll((await newAdult('Course', 'Paiement')).id, await programme('COURSE-PAY', 100000));
    const key = uuid();
    const clients = await Promise.all([0, 1, 2, 3, 4].map(() => connect('school_runtime', a, cashier.user)));
    const results = await Promise.allSettled(clients.map(async c => {
      await c.query('BEGIN');
      try {
        const id = (await c.query(RECORD, [e, '40000', 'today', 'cash', null, false, key])).rows[0].id;
        await c.query('SELECT pg_sleep(0.1)');
        await c.query('COMMIT');
        return id;
      } catch (err) { await c.query('ROLLBACK'); throw err; }
    }));
    assert.deepEqual(results.map(r => r.status), ['fulfilled', 'fulfilled', 'fulfilled', 'fulfilled', 'fulfilled']);
    assert.equal(new Set(results.map(r => r.value)).size, 1);
    const both = await Promise.allSettled(clients.slice(0, 2).map(async c => {
      await c.query('BEGIN');
      try {
        await c.query(RECORD, [e, '35000', 'today', 'cash', null, false, uuid()]);
        await c.query('SELECT pg_sleep(0.15)');
        await c.query('COMMIT');
      } catch (err) { await c.query('ROLLBACK'); throw err; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(both.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(both.find(r => r.status === 'rejected').reason.message, 'payment_exceeds_balance');
    await as('school_runtime', a, cashier.user);
    assert.equal(await count('SELECT count(*) n FROM school.payment WHERE enrollment_id = $1', [e]), 2);
    assert.equal(await count('SELECT count(DISTINCT reference) n FROM school.payment WHERE enrollment_id = $1', [e]), 2);
    await as('school_runtime', a, 1);
  });
  await check('receipts issued at the same moment never share a number; one payment printed twice at once has one receipt (FR-FIN-05 AC1)', async () => {
    const accountant = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.create', 'finance.payment.validate', 'enrollment.enrollment.read']);
    const validator = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.validate', 'enrollment.enrollment.read']);
    await as('school_runtime', a, 1);
    const e = await enroll((await newAdult('Course', 'Recu')).id, await programme('COURSE-REC', 100000));
    const ids = [];
    for (const amount of ['10000', '20000']) {
      await as('school_runtime', a, accountant.user);
      const id = (await first(RECORD, [e, amount, 'today', 'cash', null, false, uuid()])).id;
      await as('school_runtime', a, validator.user);
      await sql("UPDATE school.payment SET status = 'validated' WHERE id = $1", [id]);
      ids.push(id);
    }
    const clients = await Promise.all([0, 1, 2, 3].map(() => connect('school_runtime', a, accountant.user)));
    const results = await Promise.allSettled(clients.map(async (c, i) => {
      await c.query('BEGIN');
      try {
        const row = (await c.query(ISSUE, [ids[i % 2], null])).rows[0];
        await c.query('SELECT pg_sleep(0.1)');
        await c.query('COMMIT');
        return row.id;
      } catch (err) { await c.query('ROLLBACK'); throw err; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.deepEqual(results.map(r => r.status), ['fulfilled', 'fulfilled', 'fulfilled', 'fulfilled']);
    assert.equal(results[0].value, results[2].value);
    assert.equal(results[1].value, results[3].value);
    await as('school_runtime', a, accountant.user);
    assert.equal(await count('SELECT count(DISTINCT number) n FROM school.receipt WHERE payment_id = ANY ($1::uuid[])', [ids]), 2);
    await as('school_runtime', a, 1);
  });
  await check('a month closed while a payment dated in it is recorded: never both (FR-FIN-07 AC1)', async () => {
    const closer = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.period.close']);
    const cashier = await scopedAccount('internal', 'tenant', null, ['finance.payment.read', 'finance.payment.create', 'enrollment.enrollment.read']);
    await as('school_runtime', a, 1);
    const e = await enroll((await newAdult('Course', 'Cloture')).id, await programme('COURSE-CLO', 100000));
    const clients = [await connect('school_runtime', a, closer.user), await connect('school_runtime', a, cashier.user)];
    const results = await Promise.allSettled([[CLOSE, ['2025-05-01']], [RECORD, [e, '1000', '2025-05-10', 'cash', null, false, uuid()]]].map(async ([s, p], i) => {
      await clients[i].query('BEGIN');
      try {
        await clients[i].query(s, p);
        await clients[i].query('SELECT pg_sleep(0.15)');
        await clients[i].query('COMMIT');
      } catch (err) { await clients[i].query('ROLLBACK'); throw err; }
    }));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.ok(['period_closed', 'period_has_pending'].includes(results.find(r => r.status === 'rejected').reason.message));
    await as('school_runtime', a, 1);
  });
  await check('five sendings of the same expense payment at once pay once; two payments at once never pass what is left; a month closed while an expense is paid in it: never both (FR-DEP-02, TST-10, BR-15)', async () => {
    const clerk = await scopedAccount('internal', 'tenant', null, ['expense.expense.create', 'expense.expense.read']);
    const payer = await scopedAccount('internal', 'tenant', null, ['expense.expense.validate', 'expense.expense.pay', 'expense.expense.read', 'finance.period.close']);
    await as('school_runtime', a, clerk.user);
    const rent = (await first("SELECT id FROM school.expense_category WHERE tenant_id = $1 AND code = 'rent'", [a])).id;
    const lease = (await first(EXPENSE, ['2025-09-05', '50000', rent, null, 'SCI Les Pins', 'Loyer de septembre', null, uuid()])).id;
    await sql("UPDATE school.expense SET status = 'submitted' WHERE id = $1", [lease]);
    await as('school_runtime', a, payer.user);
    await sql("UPDATE school.expense SET status = 'validated' WHERE id = $1", [lease]);
    const key = uuid();
    const clients = await Promise.all([0, 1, 2, 3, 4].map(() => connect('school_runtime', a, payer.user)));
    const at = (c, s, p, pause = 0.1) => (async () => {
      await c.query('BEGIN');
      try {
        const row = (await c.query(s, p)).rows[0];
        await c.query(`SELECT pg_sleep(${pause})`);
        await c.query('COMMIT');
        return row?.id;
      } catch (err) { await c.query('ROLLBACK'); throw err; }
    })();
    const results = await Promise.allSettled(clients.map(c => at(c, PAY_EXPENSE, [lease, '20000', 'today', 'cash', null, key])));
    assert.deepEqual(results.map(r => r.status), ['fulfilled', 'fulfilled', 'fulfilled', 'fulfilled', 'fulfilled']);
    assert.equal(new Set(results.map(r => r.value)).size, 1);
    const both = await Promise.allSettled(clients.slice(0, 2).map(c => at(c, PAY_EXPENSE, [lease, '20000', 'today', 'cash', null, uuid()], 0.15)));
    assert.equal(both.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(both.find(r => r.status === 'rejected').reason.message, 'payment_exceeds_balance');
    // What is left, paid with a day in September while September is closed.
    const race = await Promise.allSettled([at(clients[0], CLOSE, ['2025-09-01'], 0.15), at(clients[1], PAY_EXPENSE, [lease, '10000', '2025-09-20', 'cash', null, uuid()], 0.15)]);
    await Promise.all(clients.map(c => c.end()));
    // Closed first, the payment dated in it is refused; paid first, the closure waits for it and reports it.
    assert.equal(race[0].status, 'fulfilled');
    await as('school_runtime', a, payer.user);
    const report = (await first("SELECT report FROM school.finance_period WHERE tenant_id = $1 AND period_start = '2025-09-01' AND status = 'closed'", [a])).report;
    if (race[1].status === 'rejected') {
      assert.equal(race[1].reason.message, 'period_closed');
      assert.deepEqual(report.expense_payments, {});
    } else assert.deepEqual(report.expense_payments, { cash: { count: 1, amount: '10000.00' } });
    assert.equal(report.expenses_total, '50000.00');
    await as('school_runtime', a, 1);
  });
  await check('two corrections of an expense at once leave one waiting; five sendings of one ask once; a cancellation validated while the expense is paid: never committed below paid (FR-DEP-03, BR-05, BR-06)', async () => {
    const clerk = await scopedAccount('internal', 'tenant', null, ['expense.expense.create', 'expense.expense.read']);
    const corrector = await scopedAccount('internal', 'tenant', null, ['expense.expense.reverse', 'expense.expense.pay', 'expense.expense.read']);
    const validator = await scopedAccount('internal', 'tenant', null, ['expense.expense.validate', 'expense.expense.read']);
    await as('school_runtime', a, clerk.user);
    const equipment = (await first("SELECT id FROM school.expense_category WHERE tenant_id = $1 AND code = 'equipment'", [a])).id;
    const chairs = (await first(EXPENSE, ['today', '8000', equipment, null, 'Mobilier Plus', 'Chaises', null, uuid()])).id;
    await sql("UPDATE school.expense SET status = 'submitted' WHERE id = $1", [chairs]);
    await as('school_runtime', a, validator.user);
    await sql("UPDATE school.expense SET status = 'validated' WHERE id = $1", [chairs]);
    const clients = await Promise.all([0, 1, 2, 3, 4].map(() => connect('school_runtime', a, corrector.user)));
    const checker = await connect('school_runtime', a, validator.user);
    const at = (c, s, p, pause = 0.1) => (async () => {
      await c.query('BEGIN');
      try {
        const row = (await c.query(s, p)).rows[0];
        await c.query(`SELECT pg_sleep(${pause})`);
        await c.query('COMMIT');
        return row?.id;
      } catch (err) { await c.query('ROLLBACK'); throw err; }
    })();
    const two = await Promise.allSettled(clients.slice(0, 2).map((c, i) => at(c, CORRECT_EXPENSE, [chairs, 'correction', String(1000 + i), 'Remise', uuid()], 0.15)));
    assert.equal(two.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(two.find(r => r.status === 'rejected').reason.message, 'expense_correction_pending');
    await as('school_runtime', a, validator.user);
    await sql("UPDATE school.expense_correction SET status = 'rejected', decision_reason = 'Remise refusée' WHERE expense_id = $1", [chairs]);
    const key = uuid();
    const five = await Promise.allSettled(clients.map(c => at(c, CORRECT_EXPENSE, [chairs, 'cancellation', null, 'Commande annulée', key])));
    assert.deepEqual(five.map(r => r.status), ['fulfilled', 'fulfilled', 'fulfilled', 'fulfilled', 'fulfilled']);
    assert.equal(new Set(five.map(r => r.value)).size, 1);
    // Validated while what is committed is paid: one of them only.
    const race = await Promise.allSettled([
      at(checker, "UPDATE school.expense_correction SET status = 'validated' WHERE id = $1 RETURNING id", [five[0].value], 0.15),
      at(clients[0], PAY_EXPENSE, [chairs, '8000', 'today', 'cash', null, uuid()], 0.15)]);
    await Promise.all([...clients, checker].map(c => c.end()));
    assert.equal(race.filter(r => r.status === 'fulfilled').length, 1);
    assert.ok(['correction_below_paid', 'payment_exceeds_balance'].includes(race.find(r => r.status === 'rejected').reason.message));
    await as('school_runtime', a, validator.user);
    const after = await first('SELECT school.expense_committed($1, $2) AS committed, school.expense_paid($1, $2) AS paid', [a, chairs]);
    assert.ok(Number(after.committed) >= Number(after.paid));
    await as('school_runtime', a, 1);
  });
  await check('two versions of a member\'s rule from the same month at once: one; five sendings of one: once (FR-REM-01, BR-05, BR-10)', async () => {
    const payroll = await scopedAccount('internal', 'tenant', null, ['payroll.rule.manage', 'staff.profile.read']);
    await as('school_runtime', a, 1);
    const teacher = (await first(STAFF, [a, 'Ziani', 'Rym', 'teacher_internal'])).id;
    const from = await payMonth(1);
    const later = await payMonth(2);
    const clients = await Promise.all([0, 1, 2, 3, 4].map(() => connect('school_runtime', a, payroll.user)));
    const at = (c, s, p, pause = 0.1) => (async () => {
      await c.query('BEGIN');
      try {
        const row = (await c.query(s, p)).rows[0];
        await c.query(`SELECT pg_sleep(${pause})`);
        await c.query('COMMIT');
        return row?.id;
      } catch (err) { await c.query('ROLLBACK'); throw err; }
    })();
    const rates = JSON.stringify([{ node_id: null, unit_amount: '1500' }]);
    const two = await Promise.allSettled(clients.slice(0, 2).map(c => at(c, SAVE_PAY_RULE, [teacher, 'hourly', null, null, null, from, rates, '[]', uuid()], 0.15)));
    assert.equal(two.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(two.find(r => r.status === 'rejected').reason.message, 'pay_rule_overlap');
    const key = uuid();
    const five = await Promise.allSettled(clients.map(c => at(c, SAVE_PAY_RULE, [teacher, 'fixed_monthly', '50000', null, null, later, '[]', '[]', key])));
    await Promise.all(clients.map(c => c.end()));
    assert.deepEqual(five.map(r => r.status), ['fulfilled', 'fulfilled', 'fulfilled', 'fulfilled', 'fulfilled']);
    assert.equal(new Set(five.map(r => r.value)).size, 1);
    await as('school_runtime', a, payroll.user);
    assert.equal(await count('SELECT count(*) n FROM school.pay_rule WHERE staff_id = $1', [teacher]), 2);
    await as('school_runtime', a, 1);
  });
  await check('five generations of a month at once: one pay a member; five sendings of an adjustment: once (FR-REM-02 AC1, TST-12, BR-05)', async () => {
    const rules = await scopedAccount('internal', 'tenant', null, ['payroll.rule.manage', 'staff.profile.read']);
    const gen = await scopedAccount('internal', 'tenant', null, ['payroll.run.generate']);
    await as('school_runtime', a, 1);
    const teacher = (await first(STAFF, [a, 'Bouzid', 'Lina', 'teacher_internal'])).id;
    const from = await payMonth(-2);
    const period = await payMonth(-1);
    await as('school_runtime', a, rules.user);
    await first(SAVE_PAY_RULE, [teacher, 'fixed_monthly', '50000', null, null, from, '[]', '[]', uuid()]);
    const clients = await Promise.all([0, 1, 2, 3, 4].map(() => connect('school_runtime', a, gen.user)));
    const at = (c, s, p, pause = 0.1) => (async () => {
      await c.query('BEGIN');
      try {
        const row = (await c.query(s, p)).rows[0];
        await c.query(`SELECT pg_sleep(${pause})`);
        await c.query('COMMIT');
        return row;
      } catch (err) { await c.query('ROLLBACK'); throw err; }
    })();
    const five = await Promise.allSettled(clients.map(c => at(c, 'SELECT school.generate_payroll($1::date, $2::uuid) AS n', [period, teacher])));
    assert.deepEqual(five.map(r => r.status), ['fulfilled', 'fulfilled', 'fulfilled', 'fulfilled', 'fulfilled']);
    await as('school_runtime', a, gen.user);
    const run = await first('SELECT id FROM school.payroll_run WHERE staff_id = $1 AND period = $2', [teacher, period]);
    assert.equal(await count('SELECT count(*) n FROM school.payroll_run WHERE staff_id = $1', [teacher]), 1);
    const key = uuid();
    const sent = await Promise.allSettled(clients.map(c => at(c, 'SELECT school.adjust_payroll($1, $2, $3::numeric, $4, NULL, $5) AS id', [run.id, 'bonus', '2000', 'Prime de fin d’année', key])));
    await Promise.all(clients.map(c => c.end()));
    assert.deepEqual(sent.map(r => r.status), ['fulfilled', 'fulfilled', 'fulfilled', 'fulfilled', 'fulfilled']);
    assert.equal(new Set(sent.map(r => r.value.id)).size, 1);
    assert.equal((await first('SELECT net_amount::text AS n FROM school.payroll_run WHERE id = $1', [run.id])).n, '52000.00');
    await as('school_runtime', a, 1);
  });
  await check('five sendings of a payout at once pay once with one expense; two payouts at once never pass the net (FR-REM-04, BR-05, BR-06, TST-13)', async () => {
    const rules = await scopedAccount('internal', 'tenant', null, ['payroll.rule.manage', 'staff.profile.read']);
    const gen = await scopedAccount('internal', 'tenant', null, ['payroll.run.generate']);
    const validator = await scopedAccount('internal', 'tenant', null, ['payroll.run.validate']);
    const payer = await scopedAccount('internal', 'tenant', null, ['payroll.payout.record', 'payroll.amount.read']);
    await as('school_runtime', a, 1);
    const teacher = (await first(STAFF, [a, 'Hadj', 'Samir', 'teacher_internal'])).id;
    const period = await payMonth(-1);
    await as('school_runtime', a, rules.user);
    await first(SAVE_PAY_RULE, [teacher, 'fixed_monthly', '50000', null, null, period, '[]', '[]', uuid()]);
    await as('school_runtime', a, gen.user);
    await first(GENERATE_PAYROLL, [period, teacher]);
    const run = (await first('SELECT id FROM school.payroll_run WHERE staff_id = $1 AND period = $2', [teacher, period])).id;
    await as('school_runtime', a, validator.user);
    await sql("UPDATE school.payroll_run SET status = 'validated' WHERE id = $1", [run]);
    const clients = await Promise.all([0, 1, 2, 3, 4].map(() => connect('school_runtime', a, payer.user)));
    const at = (c, s, p, pause = 0.1) => (async () => {
      await c.query('BEGIN');
      try {
        const row = (await c.query(s, p)).rows[0];
        await c.query(`SELECT pg_sleep(${pause})`);
        await c.query('COMMIT');
        return row?.id;
      } catch (err) { await c.query('ROLLBACK'); throw err; }
    })();
    const key = uuid();
    const five = await Promise.allSettled(clients.map(c => at(c, PAY_OUT, [run, '20000', 'today', 'cash', null, key])));
    assert.deepEqual(five.map(r => r.status), ['fulfilled', 'fulfilled', 'fulfilled', 'fulfilled', 'fulfilled']);
    assert.equal(new Set(five.map(r => r.value)).size, 1);
    const two = await Promise.allSettled(clients.slice(0, 2).map(c => at(c, PAY_OUT, [run, '20000', 'today', 'transfer', null, uuid()], 0.15)));
    await Promise.all(clients.map(c => c.end()));
    assert.equal(two.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(two.find(r => r.status === 'rejected').reason.message, 'payment_exceeds_balance');
    await as('school_runtime', a, payer.user);
    assert.equal((await first('SELECT school.payroll_paid($1, $2)::text AS p', [a, run])).p, '40000.00');
    // TST-13: one expense a payout, of the same amount.
    assert.deepEqual((await sql(`SELECT count(x.id)::int AS n, coalesce(sum(x.amount), 0)::text AS amount FROM school.payroll_payout o
      JOIN school.expense x ON x.tenant_id = o.tenant_id AND x.payroll_payout_id = o.id WHERE o.run_id = $1`, [run])).rows, [{ n: 2, amount: '40000.00' }]);
    await as('school_runtime', a, 1);
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
