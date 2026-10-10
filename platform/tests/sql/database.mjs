import { PGlite } from '@electric-sql/pglite';
import { readFileSync, readdirSync } from 'node:fs';
import { randomBytes, randomUUID as uuid } from 'node:crypto';
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
await db.exec(`CREATE TABLE public.users(id bigserial PRIMARY KEY, name varchar(255) NOT NULL, email varchar(255) NOT NULL UNIQUE, email_verified_at timestamptz,
  password varchar(255) NOT NULL, remember_token text, created_at timestamptz, updated_at timestamptz);
  INSERT INTO public.users(id, name, email, password) SELECT g, 'Person ' || g, 'user' || g || '@example.test', 'hash' FROM generate_series(1, 9) g;
  SELECT setval('public.users_id_seq', 9);`);
const schemaDir = new URL('../../database/schema/', import.meta.url);
for (const file of readdirSync(schemaDir).filter(f => /^\d{3}_.+\.sql$/.test(f)).sort())
  await db.exec(readFileSync(new URL(file, schemaDir), 'utf8'));
await db.exec(readFileSync(new URL('../../deploy/roles.sql', import.meta.url), 'utf8'));

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
  await check('a link used twice at once opens the account once (FR-IAM-02 AC3)', async () => {
    await as('school_runtime', a, 1);
    const twice = await first(CREATE_ACCOUNT, ['Course', 'course@example.test', null, null, null]);
    const link = tokenHash();
    await sql('SELECT school.issue_activation($1, $2)', [twice.id, link]);
    const clients = await Promise.all([0, 1].map(() => connect('school_runtime', null, '')));
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
