// What a restricted scope costs (SECURITY-REVIEW.md, FR-IAM-04, NFR-PERF-01/03), on native PostgreSQL:
//   DATABASE_URL=postgres://user:password@host/cost_test node tests/sql/scope-cost.mjs [platform folder] [pupils]
// One school of 2 000 pupils by default (NFR-PERF-03's reference school), each with a guardian, an
// enrolment and a class, on two sites. The same lists are timed for readers of the whole school,
// of one site, of two groups, of one level, and for a pupil reading their own record. Each line
// is the median of its runs, round trips included; the visible rows tell that each reader sees
// what their scope opens.
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createHmac, randomBytes, randomUUID as uuid } from 'node:crypto';
import assert from 'node:assert/strict';
import pg from 'pg';

assert.ok(new URL(process.env.DATABASE_URL ?? 'x:').pathname.endsWith('_test'), 'Use a new disposable database ending in _test');
const root = process.argv[2] ? pathToFileURL(resolve(process.argv[2]) + '/') : new URL('../../', import.meta.url);
const pupils = Number(process.argv[3] ?? 2000);
const client = new pg.Client({ connectionString: process.env.DATABASE_URL });
await client.connect();
const sql = (s, p = []) => client.query(s, p);
const one = async (s, p = []) => (await sql(s, p)).rows[0];

// Laravel's users table, created by the application's own migration in production.
await sql(`CREATE TABLE public.users(id bigserial PRIMARY KEY, name varchar(255) NOT NULL, email varchar(255) NOT NULL UNIQUE, email_verified_at timestamptz,
  password varchar(255) NOT NULL, remember_token text, created_at timestamptz, updated_at timestamptz);
  INSERT INTO public.users(id, name, email, password) SELECT g, 'Person ' || g, 'user' || g || '@example.test', 'hash' FROM generate_series(1, 5) g;
  SELECT setval('public.users_id_seq', 5);`);
const schema = new URL('database/schema/', root);
for (const file of readdirSync(schema).filter(f => /^\d{3}_.+\.sql$/.test(f)).sort()) await sql(readFileSync(new URL(file, schema), 'utf8'));
await sql(readFileSync(new URL('deploy/roles.sql', root), 'utf8'));
const key = randomBytes(32);
await sql("CALL secret.set_context_key(decode($1, 'hex'))", [key.toString('hex')]);
const pid = (await one('SELECT pg_backend_pid() AS pid')).pid;

// The school, written by its owner: two sites, a year, a programme per grade, classes of 30.
const today = new Date().toISOString().slice(0, 10);
const a = (await one('SELECT platform.create_tenant($1, $2, $3, $4, $5, $6::date, $7::date, $8, $9, $10, $11, $12, $13) AS id',
  [uuid(), 'scope-a', 'scope-a', 'fr', 'essential', today, null, pupils * 2, 1024, 'MAIN', 'Site', 1, 'private_school_dz'])).id;
await sql(`INSERT INTO school.membership (tenant_id, user_id, status) SELECT $1, g, 'active' FROM generate_series(1, 5) g
  ON CONFLICT (tenant_id, user_id) DO UPDATE SET status = 'active'`, [a]);
const main = (await one("SELECT id FROM school.site WHERE tenant_id = $1 AND code = 'MAIN'", [a])).id;
const annex = (await one(`INSERT INTO school.site (tenant_id, code, labels) VALUES ($1, 'ANNEXE', '{"fr": "Annexe"}') RETURNING id`, [a])).id;
const year = (await one(`INSERT INTO school.academic_year (tenant_id, code, start_date, end_date, status)
  VALUES ($1, 'Y', current_date - 30, current_date + 300, 'current') RETURNING id`, [a])).id;
await sql(`INSERT INTO school.program (tenant_id, year_id, node_id, code, labels, base_price, status)
  SELECT $1, $2, n.id, 'P_' || n.code, jsonb_build_object('fr', 'Programme ' || n.code), 100000, 'active'
  FROM school.ref_node n WHERE n.tenant_id = $1 AND n.type_code = 'grade'`, [a, year]);
const programs = (await sql('SELECT id FROM school.program WHERE tenant_id = $1 ORDER BY code', [a])).rows.map(r => r.id);
const perProgram = Math.ceil(pupils / programs.length);
const classes = Math.ceil(perProgram / 30);
await sql(`INSERT INTO school.study_group (tenant_id, year_id, code, labels, group_type, program_id, site_id, capacity, valid_from, status)
  SELECT $1, $2, 'G' || p.n || '_' || c, jsonb_build_object('fr', 'Classe ' || p.n || '-' || c), 'class', p.id,
         CASE WHEN (p.n + c) % 2 = 0 THEN $3::uuid ELSE $4::uuid END, 30, current_date - 30, 'active'
  FROM (SELECT id, row_number() OVER (ORDER BY code) AS n FROM school.program WHERE tenant_id = $1) p, generate_series(1, $5) c`,
  [a, year, main, annex, classes]);

// The pupils, minors with a guardian who is their primary contact; one enrolment and one class each.
// Imported matricules: the generator serves a school's own requests, not its owner's bulk load.
await sql(`INSERT INTO school.student (tenant_id, matricule, last_name_lat, first_name_lat, birth_date)
  SELECT $1, 'IMP' || lpad(g::text, 6, '0'), 'Eleve', 'Numero ' || g, date '2012-01-01' + (g % 300) FROM generate_series(1, $2) g`, [a, pupils]);
await sql(`INSERT INTO school.guardian (tenant_id, last_name_lat, first_name_lat, phone)
  SELECT $1, 'Parent', 'Numero ' || g, '0550' || lpad(g::text, 6, '0') FROM generate_series(1, $2) g`, [a, pupils]);
await sql(`INSERT INTO school.student_guardian (tenant_id, student_id, guardian_id, relationship, is_primary_contact)
  SELECT $1, s.id, g.id, 'father', true
  FROM (SELECT id, row_number() OVER (ORDER BY first_name_lat) AS n FROM school.student WHERE tenant_id = $1) s
  JOIN (SELECT id, row_number() OVER (ORDER BY first_name_lat) AS n FROM school.guardian WHERE tenant_id = $1) g USING (n)`, [a]);
await sql(`INSERT INTO school.enrollment (tenant_id, student_id, program_id, idempotency_key)
  SELECT $1, s.id, ($2::uuid[])[1 + (s.n - 1) % cardinality($2::uuid[])], gen_random_uuid()
  FROM (SELECT id, row_number() OVER (ORDER BY first_name_lat) AS n FROM school.student WHERE tenant_id = $1) s`, [a, programs]);
await sql(`INSERT INTO school.group_membership (tenant_id, enrollment_id, group_id)
  SELECT $1, e.id, g.id FROM (SELECT id, program_id, row_number() OVER (PARTITION BY program_id ORDER BY id) AS n FROM school.enrollment WHERE tenant_id = $1) e
  JOIN (SELECT id, program_id, row_number() OVER (PARTITION BY program_id ORDER BY code) AS c FROM school.study_group WHERE tenant_id = $1) g
    ON g.program_id = e.program_id AND g.c = 1 + (e.n - 1) / 30`, [a]);

// The readers. 1: the whole school (academic_hr). 2: one site (front_office). 3: two classes
// (supervisor). 4: the secondary cycle (supervisor). 5: a pupil reading their own record.
const role = async code => (await one('SELECT id FROM school.role WHERE tenant_id = $1 AND code = $2', [a, code])).id;
const assign = (user, roleId, scope, ids = null) => sql(`INSERT INTO school.role_assignment (tenant_id, user_id, role_id, scope_type, scope_ids)
  VALUES ($1, $2, $3, $4, $5::uuid[])`, [a, user, roleId, scope, ids && `{${ids.join(',')}}`]);
await assign(1, await role('academic_hr'), 'tenant');
await assign(2, await role('front_office'), 'site', [annex]);
const twoClasses = (await sql("SELECT id FROM school.study_group WHERE tenant_id = $1 ORDER BY code LIMIT 2", [a])).rows.map(r => r.id);
await assign(3, await role('supervisor'), 'group', twoClasses);
const secondary = (await one("SELECT id FROM school.ref_node WHERE tenant_id = $1 AND type_code = 'cycle' AND code = 'SECONDAIRE'", [a])).id;
await assign(4, await role('supervisor'), 'level', [secondary]);
const pupilRole = (await one(`INSERT INTO school.role (tenant_id, code, kind, labels) VALUES ($1, 'pupil_reader', 'student', '{"fr": "Élève lecteur"}') RETURNING id`, [a])).id;
await sql(`INSERT INTO school.role_permission (tenant_id, role_id, permission_code)
  SELECT $1, $2, unnest(ARRAY['student.record.read', 'enrollment.enrollment.read', 'group.group.read'])`, [a, pupilRole]);
await sql("UPDATE school.student SET user_id = 5 WHERE id = (SELECT id FROM school.student WHERE tenant_id = $1 ORDER BY first_name_lat LIMIT 1)", [a]);
await assign(5, pupilRole, 'personal');
await sql('VACUUM ANALYZE');
await sql('SET ROLE school_runtime');

const enter = user => {
  const payload = JSON.stringify({ tenant: a, user, support: null, request: 'scope-cost', ip: null, pid, at: Date.now() * 1000 });
  return sql("SELECT set_config('app.context', $1, true)", [payload + '.' + createHmac('sha256', key).update(payload).digest('hex')]);
};
const time = async (runs, work) => {
  const times = [];
  for (let i = 0; i < runs && !(i >= 3 && times[0] > 1000); i++) {
    const start = process.hrtime.bigint();
    await work();
    times.push(Number(process.hrtime.bigint() - start) / 1e6);
  }
  times.sort((x, y) => x - y);
  return times[Math.floor(times.length / 2)];
};
// As the API lists (PupilRecords, AcademicRecords, Paging): the count, then the first page.
const PUPILS = "SELECT id, matricule, last_name_lat, first_name_lat FROM school.student WHERE tenant_id = $1 AND archived_at IS NULL";
const ENROLMENTS = "SELECT id, student_id, program_id, status FROM school.enrollment WHERE tenant_id = $1 AND archived_at IS NULL";
const GROUPS = `SELECT id, code, capacity,
  (SELECT p.code FROM school.program p WHERE p.tenant_id = study_group.tenant_id AND p.id = study_group.program_id) AS program_code,
  (SELECT count(*) FROM school.group_membership m WHERE m.tenant_id = study_group.tenant_id AND m.group_id = study_group.id AND m.valid_to IS NULL) AS members
  FROM school.study_group WHERE tenant_id = $1 AND archived_at IS NULL`;
const measures = {
  'Pupils: count and first page': async () => {
    const n = Number((await one(`SELECT count(*) AS n FROM (${PUPILS}) listed`, [a])).n);
    await sql(`${PUPILS} ORDER BY name_key, id LIMIT 25`, [a]);
    return n;
  },
  'One pupil': async () => (await sql(`${PUPILS} AND id = $2`, [a, lastSeen])).rows.length,
  'Enrolments: count and first page': async () => {
    const n = Number((await one(`SELECT count(*) AS n FROM (${ENROLMENTS}) listed`, [a])).n);
    await sql(`${ENROLMENTS} ORDER BY created_at DESC, id LIMIT 25`, [a]);
    return n;
  },
  // With the programme's code and the members of each group, as StudyGroupController lists them.
  'Groups: count and first page': async () => {
    const n = Number((await one(`SELECT count(*) AS n FROM (${GROUPS}) listed`, [a])).n);
    await sql(`${GROUPS} ORDER BY code, id LIMIT 25`, [a]);
    return n;
  },
  'A class roster': async () => (await sql(`SELECT m.id, s.last_name_lat FROM school.group_membership m
    JOIN school.enrollment e ON e.id = m.enrollment_id JOIN school.student s ON s.id = e.student_id
    WHERE m.tenant_id = $1 AND m.group_id = $2 AND m.valid_to IS NULL`, [a, twoClasses[0]])).rows.length,
};
let lastSeen;
const readers = { 'Whole school': 1, 'One site': 2, 'Two classes': 3, 'One level': 4, 'Own record': 5 };
console.log(`Scoped reading, ${pupils} pupils, PostgreSQL ${(await one('SHOW server_version')).server_version}\n`);
console.log(`| Measure | ${Object.keys(readers).join(' | ')} |\n|---|${Object.keys(readers).map(() => '---|').join('')}`);
const rows = {};
for (const [reader, user] of Object.entries(readers)) {
  await sql('BEGIN');
  await enter(user);
  lastSeen = (await one(`${PUPILS} ORDER BY name_key DESC LIMIT 1`, [a]))?.id ?? uuid();
  for (const [label, work] of Object.entries(measures)) {
    let seen;
    const ms = await time(20, async () => { seen = await work(); });
    (rows[label] ??= []).push(`${ms.toFixed(1)} ms (${seen})`);
  }
  await sql('COMMIT');
}
for (const [label, cells] of Object.entries(rows)) console.log(`| ${label} | ${cells.join(' | ')} |`);
await client.end();
