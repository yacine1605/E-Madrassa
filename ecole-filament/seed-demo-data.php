<?php
/**
 * Demo Mock Data Seeder – E-Madrassa
 * Branch: quiz
 *
 * Run via:
 *   docker cp ecole-filament/seed-demo-data.php madrassa-app-1:/var/www/html/seed-demo-data.php
 *   docker exec madrassa-app-1 php /var/www/html/seed-demo-data.php
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ─── Constants ────────────────────────────────────────────────────────────────
const TENANT  = 'b654c14d-eb7d-4e5f-92df-c9285c87f57e';
const ADMIN   = 1;                                       // users.id for admin@madrassa.local
const TEACHER = '549ae696-196a-4bcf-8c27-6087d70565f7'; // school.staff.id for ENS-01 / Nadia Benali

$NOW = now()->toDateTimeString();

function uuid(): string { return (string) Str::uuid(); }

function ctx(): void {
    DB::select("SELECT set_config('app.tenant_id', ?, true)", [TENANT]);
    DB::select("SELECT set_config('app.user_id',   ?, true)", [(string) ADMIN]);
}

function ok(string $msg): void  { echo "[OK]   $msg\n"; }
function skip(string $msg): void { echo "[SKIP] $msg\n"; }

// ─── Guard: abort if already seeded ──────────────────────────────────────────
if (DB::table('school.programs')->where('tenant_id', TENANT)->exists()) {
    skip("Data already seeded – nothing to do.");
    exit(0);
}

echo "Starting mock data seed...\n\n";

DB::transaction(function () use ($NOW) {

    ctx();

    // =========================================================================
    // 1. CAMPUS
    // =========================================================================
    $campusId = uuid();
    DB::table('school.campuses')->insert([
        'id'          => $campusId,
        'tenant_id'   => TENANT,
        'name'        => 'Campus El Fath',
        'wilaya_code' => '09',
        'address'     => "Rue Ben M'hidi, Blida",
        'created_at'  => $NOW,
    ]);
    ok("Campus: Campus El Fath");

    // =========================================================================
    // 2. PERIOD
    // =========================================================================
    $periodId = uuid();
    DB::table('school.periods')->insert([
        'id'         => $periodId,
        'tenant_id'  => TENANT,
        'name'       => '2026/2027',
        'starts_on'  => '2026-09-01',
        'ends_on'    => '2027-06-30',
        'created_at' => $NOW,
    ]);
    ok("Period: 2026/2027");

    // =========================================================================
    // 3. PROGRAM
    // =========================================================================
    $programId = uuid();
    DB::table('school.programs')->insert([
        'id'         => $programId,
        'tenant_id'  => TENANT,
        'code'       => 'PRI',
        'name'       => 'Primaire',
        'kind'       => 'school',
        'active'     => true,
        'created_at' => $NOW,
    ]);
    ok("Program: Primaire");

    // =========================================================================
    // 4. LEVELS
    // =========================================================================
    $levelDefs = [
        ['code' => '1AP', 'name' => '1ere Annee Primaire',  'pos' => 1],
        ['code' => '2AP', 'name' => '2eme Annee Primaire',  'pos' => 2],
        ['code' => '3AP', 'name' => '3eme Annee Primaire',  'pos' => 3],
        ['code' => '4AP', 'name' => '4eme Annee Primaire',  'pos' => 4],
        ['code' => '5AP', 'name' => '5eme Annee Primaire',  'pos' => 5],
    ];
    $levelIds = [];
    foreach ($levelDefs as $l) {
        $lid = uuid();
        DB::table('school.levels')->insert([
            'id'         => $lid,
            'tenant_id'  => TENANT,
            'program_id' => $programId,
            'code'       => $l['code'],
            'name'       => $l['name'],
            'position'   => $l['pos'],
            'created_at' => $NOW,
        ]);
        $levelIds[$l['code']] = $lid;
        ok("Level: {$l['name']}");
    }

    // =========================================================================
    // 5. SUBJECTS
    // =========================================================================
    $subjectDefs = [
        ['code' => 'AR',   'name' => 'Langue Arabe'],
        ['code' => 'FR',   'name' => 'Langue Francaise'],
        ['code' => 'MATH', 'name' => 'Mathematiques'],
        ['code' => 'SC',   'name' => 'Sciences Naturelles'],
        ['code' => 'ISL',  'name' => 'Education Islamique'],
        ['code' => 'HIST', 'name' => 'Histoire et Geographie'],
    ];
    $subjectIds = [];
    foreach ($subjectDefs as $s) {
        $sid = uuid();
        DB::table('school.subjects')->insert([
            'id'         => $sid,
            'tenant_id'  => TENANT,
            'code'       => $s['code'],
            'name'       => $s['name'],
            'created_at' => $NOW,
        ]);
        $subjectIds[$s['code']] = $sid;
        ok("Subject: {$s['name']}");
    }

    // =========================================================================
    // 6. OFFERINGS  (one per level)
    // =========================================================================
    $offeringIds = [];
    foreach ($levelDefs as $l) {
        $oid = uuid();
        DB::table('school.offerings')->insert([
            'id'          => $oid,
            'tenant_id'   => TENANT,
            'level_id'    => $levelIds[$l['code']],
            'period_id'   => $periodId,
            'campus_id'   => $campusId,
            'name'        => "Primaire {$l['code']} 2026/2027",
            'price_minor' => 3500000,
            'active'      => true,
            'created_at'  => $NOW,
        ]);
        $offeringIds[$l['code']] = $oid;
        ok("Offering: Primaire {$l['code']} 2026/2027");
    }

    // =========================================================================
    // 7. ROOMS
    // =========================================================================
    $roomIds = [];
    for ($r = 1; $r <= 5; $r++) {
        $rid = uuid();
        DB::table('school.rooms')->insert([
            'id'         => $rid,
            'tenant_id'  => TENANT,
            'campus_id'  => $campusId,
            'name'       => "Salle $r",
            'capacity'   => 30,
            'created_at' => $NOW,
        ]);
        $roomIds[$r] = $rid;
        ok("Room: Salle $r");
    }

    // =========================================================================
    // 8. COHORTS
    //    4AP + 5AP  -> 2 cohorts (A, B)   – teacher's main classes
    //    Others     -> 1 cohort (A)
    // =========================================================================
    $cohortIds = [];
    foreach ($levelDefs as $l) {
        $numCohorts = in_array($l['code'], ['4AP', '5AP']) ? 2 : 1;
        for ($c = 0; $c < $numCohorts; $c++) {
            $letter   = chr(65 + $c); // A or B
            $cid      = uuid();
            $cohName  = "{$l['code']}-$letter";
            DB::table('school.cohorts')->insert([
                'id'          => $cid,
                'tenant_id'   => TENANT,
                'offering_id' => $offeringIds[$l['code']],
                'name'        => $cohName,
                'capacity'    => 30,
                'created_at'  => $NOW,
            ]);
            $cohortIds[$cohName] = $cid;
            ok("Cohort: $cohName");
        }
    }

    // =========================================================================
    // 9. STUDENTS  (15 per demo cohort: 4AP-A, 4AP-B, 5AP-A)
    // =========================================================================
    $pupils = [
        // ── 4AP-A ─────────────────────────────────────────────────────────────
        ['first' => 'Yassine',    'last' => 'Boudjema',   'birth' => '2016-03-12', 'cohort' => '4AP-A'],
        ['first' => 'Amira',      'last' => 'Hadjadj',    'birth' => '2016-07-22', 'cohort' => '4AP-A'],
        ['first' => 'Rayan',      'last' => 'Bensalem',   'birth' => '2016-01-05', 'cohort' => '4AP-A'],
        ['first' => 'Lina',       'last' => 'Zerrouki',   'birth' => '2016-09-18', 'cohort' => '4AP-A'],
        ['first' => 'Bilal',      'last' => 'Mansouri',   'birth' => '2016-11-30', 'cohort' => '4AP-A'],
        ['first' => 'Nour',       'last' => 'Boukhalfa',  'birth' => '2016-04-14', 'cohort' => '4AP-A'],
        ['first' => 'Adam',       'last' => 'Chebouti',   'birth' => '2016-06-02', 'cohort' => '4AP-A'],
        ['first' => 'Meriem',     'last' => 'Benali',     'birth' => '2016-02-28', 'cohort' => '4AP-A'],
        ['first' => 'Ilyes',      'last' => 'Ouarab',     'birth' => '2016-08-09', 'cohort' => '4AP-A'],
        ['first' => 'Sara',       'last' => 'Achouri',    'birth' => '2016-12-20', 'cohort' => '4AP-A'],
        ['first' => 'Hamza',      'last' => 'Fellag',     'birth' => '2016-05-17', 'cohort' => '4AP-A'],
        ['first' => 'Chaima',     'last' => 'Madi',       'birth' => '2016-10-03', 'cohort' => '4AP-A'],
        ['first' => 'Zakaria',    'last' => 'Bouzidi',    'birth' => '2016-03-26', 'cohort' => '4AP-A'],
        ['first' => 'Ryma',       'last' => 'Dahmane',    'birth' => '2016-07-11', 'cohort' => '4AP-A'],
        ['first' => 'Anis',       'last' => 'Tebbal',     'birth' => '2016-01-19', 'cohort' => '4AP-A'],
        // ── 4AP-B ─────────────────────────────────────────────────────────────
        ['first' => 'Manal',      'last' => 'Brahimi',    'birth' => '2016-04-07', 'cohort' => '4AP-B'],
        ['first' => 'Hicham',     'last' => 'Khelifi',    'birth' => '2016-09-25', 'cohort' => '4AP-B'],
        ['first' => 'Douaa',      'last' => 'Mebarki',    'birth' => '2016-02-13', 'cohort' => '4AP-B'],
        ['first' => 'Sami',       'last' => 'Lounici',    'birth' => '2016-06-30', 'cohort' => '4AP-B'],
        ['first' => 'Arwa',       'last' => 'Benkhalil',  'birth' => '2016-11-08', 'cohort' => '4AP-B'],
        ['first' => 'Tarek',      'last' => 'Hannachi',   'birth' => '2016-03-21', 'cohort' => '4AP-B'],
        ['first' => 'Meryem',     'last' => 'Guenifi',    'birth' => '2016-08-14', 'cohort' => '4AP-B'],
        ['first' => 'Sofiane',    'last' => 'Aoues',      'birth' => '2016-01-27', 'cohort' => '4AP-B'],
        ['first' => 'Djamila',    'last' => 'Benbrahim',  'birth' => '2016-05-04', 'cohort' => '4AP-B'],
        ['first' => 'Rayane',     'last' => 'Messaoud',   'birth' => '2016-10-16', 'cohort' => '4AP-B'],
        ['first' => 'Hajer',      'last' => 'Zidane',     'birth' => '2016-07-03', 'cohort' => '4AP-B'],
        ['first' => 'Abderrahim', 'last' => 'Touati',     'birth' => '2016-12-12', 'cohort' => '4AP-B'],
        ['first' => 'Yasmine',    'last' => 'Chikhi',     'birth' => '2016-04-22', 'cohort' => '4AP-B'],
        ['first' => 'Khaled',     'last' => 'Barkat',     'birth' => '2016-09-01', 'cohort' => '4AP-B'],
        ['first' => 'Hadjer',     'last' => 'Ferdi',      'birth' => '2016-02-07', 'cohort' => '4AP-B'],
        // ── 5AP-A ─────────────────────────────────────────────────────────────
        ['first' => 'Amine',      'last' => 'Sahel',      'birth' => '2015-03-15', 'cohort' => '5AP-A'],
        ['first' => 'Fatima',     'last' => 'Larbi',      'birth' => '2015-08-22', 'cohort' => '5AP-A'],
        ['first' => 'Walid',      'last' => 'Benadda',    'birth' => '2015-01-10', 'cohort' => '5AP-A'],
        ['first' => 'Nadia',      'last' => 'Hamidi',     'birth' => '2015-06-05', 'cohort' => '5AP-A'],
        ['first' => 'Younes',     'last' => 'Boughazi',   'birth' => '2015-11-19', 'cohort' => '5AP-A'],
        ['first' => 'Asma',       'last' => 'Kadi',       'birth' => '2015-04-28', 'cohort' => '5AP-A'],
        ['first' => 'Mehdi',      'last' => 'Abbou',      'birth' => '2015-09-13', 'cohort' => '5AP-A'],
        ['first' => 'Imane',      'last' => 'Chabane',    'birth' => '2015-02-01', 'cohort' => '5AP-A'],
        ['first' => 'Lotfi',      'last' => 'Bouras',     'birth' => '2015-07-17', 'cohort' => '5AP-A'],
        ['first' => 'Rim',        'last' => 'Meziane',    'birth' => '2015-12-25', 'cohort' => '5AP-A'],
        ['first' => 'Oussama',    'last' => 'Djenane',    'birth' => '2015-05-09', 'cohort' => '5AP-A'],
        ['first' => 'Khaoula',    'last' => 'Boussouf',   'birth' => '2015-10-21', 'cohort' => '5AP-A'],
        ['first' => 'Farouk',     'last' => 'Meklat',     'birth' => '2015-03-30', 'cohort' => '5AP-A'],
        ['first' => 'Sabrina',    'last' => 'Benaissa',   'birth' => '2015-08-06', 'cohort' => '5AP-A'],
        ['first' => 'Nadir',      'last' => 'Zeroual',    'birth' => '2015-01-23', 'cohort' => '5AP-A'],
    ];

    $studentIds    = [];   // [cohortName => [stId, ...]]
    $enrollmentIds = [];   // [cohortName => [enrId, ...]]
    $matCounter    = 1;

    foreach ($pupils as $p) {
        $cohortName = $p['cohort'];
        $level      = substr($cohortName, 0, 3);
        $stId       = uuid();
        $matric     = '2026-' . str_pad($matCounter++, 3, '0', STR_PAD_LEFT);

        DB::table('school.students')->insert([
            'id'         => $stId,
            'tenant_id'  => TENANT,
            'matricule'  => $matric,
            'first_name' => $p['first'],
            'last_name'  => $p['last'],
            'birth_date' => $p['birth'],
            'active'     => true,
            'created_at' => $NOW,
        ]);

        $eid = uuid();
        DB::table('school.enrollments')->insert([
            'id'              => $eid,
            'tenant_id'       => TENANT,
            'student_id'      => $stId,
            'offering_id'     => $offeringIds[$level],
            'cohort_id'       => $cohortIds[$cohortName],
            'status'          => 'validated',
            'price_minor'     => 3500000,
            'enrolled_on'     => '2026-09-01',
            'idempotency_key' => uuid(),
            'created_at'      => $NOW,
        ]);

        $studentIds[$cohortName][]    = $stId;
        $enrollmentIds[$cohortName][] = $eid;
    }
    ok("45 students inserted and enrolled (4AP-A x15, 4AP-B x15, 5AP-A x15)");

    // =========================================================================
    // 10. TEACHER ASSIGNMENTS  (Nadia -> MATH for 4AP-A, 4AP-B, 5AP-A)
    // =========================================================================
    $teacherCohorts = ['4AP-A', '4AP-B', '5AP-A'];
    foreach ($teacherCohorts as $cohortName) {
        DB::table('school.teacher_assignments')->insert([
            'id'         => uuid(),
            'tenant_id'  => TENANT,
            'teacher_id' => TEACHER,
            'cohort_id'  => $cohortIds[$cohortName],
            'subject_id' => $subjectIds['MATH'],
            'created_at' => $NOW,
        ]);
        ok("Teacher assignment: Nadia Benali -> MATH / $cohortName");
    }

    // =========================================================================
    // 11. SESSIONS (timetable – week of 2 Nov 2026)
    //     4AP-A : Mon 08:00, Wed 10:00  -> Salle 1
    //     4AP-B : Tue 08:00, Thu 10:00  -> Salle 2
    //     5AP-A : Mon 10:00, Thu 08:00  -> Salle 3
    // =========================================================================
    $sessionDefs = [
        ['cohort' => '4AP-A', 'room' => 1, 'starts' => '2026-11-02 08:00:00', 'ends' => '2026-11-02 09:30:00'],
        ['cohort' => '4AP-A', 'room' => 1, 'starts' => '2026-11-04 10:00:00', 'ends' => '2026-11-04 11:30:00'],
        ['cohort' => '4AP-B', 'room' => 2, 'starts' => '2026-11-03 08:00:00', 'ends' => '2026-11-03 09:30:00'],
        ['cohort' => '4AP-B', 'room' => 2, 'starts' => '2026-11-05 10:00:00', 'ends' => '2026-11-05 11:30:00'],
        ['cohort' => '5AP-A', 'room' => 3, 'starts' => '2026-11-02 10:00:00', 'ends' => '2026-11-02 11:30:00'],
        ['cohort' => '5AP-A', 'room' => 3, 'starts' => '2026-11-05 08:00:00', 'ends' => '2026-11-05 09:30:00'],
    ];
    foreach ($sessionDefs as $sd) {
        DB::table('school.sessions')->insert([
            'id'         => uuid(),
            'tenant_id'  => TENANT,
            'cohort_id'  => $cohortIds[$sd['cohort']],
            'subject_id' => $subjectIds['MATH'],
            'teacher_id' => TEACHER,
            'room_id'    => $roomIds[$sd['room']],
            'starts_at'  => $sd['starts'],
            'ends_at'    => $sd['ends'],
            'status'     => 'published',
            'created_at' => $NOW,
        ]);
        ok("Session: {$sd['cohort']} MATH {$sd['starts']}");
    }

    // =========================================================================
    // 12. ASSESSMENTS  (3 per demo cohort: devoir, examen, continu)
    // Note: Inserted with published_at = NULL so guard_result() allows grade entry
    // =========================================================================
    $assessmentDefs = [
        ['kind' => 'devoir',  'title' => 'Devoir Surveille N1',      'held' => '2026-10-10', 'coeff' => 2, 'pub' => '2026-10-12 10:00:00'],
        ['kind' => 'examen',  'title' => 'Composition Trimestrielle', 'held' => '2026-10-25', 'coeff' => 3, 'pub' => '2026-10-27 10:00:00'],
        ['kind' => 'continu', 'title' => 'Note de Participation',     'held' => '2026-10-05', 'coeff' => 1, 'pub' => '2026-10-06 10:00:00'],
    ];
    $assessmentIds = [];
    $assessmentPubTimes = [];

    foreach ($teacherCohorts as $cohortName) {
        $assessmentIds[$cohortName] = [];
        foreach ($assessmentDefs as $ad) {
            $aid = uuid();
            DB::table('school.assessments')->insert([
                'id'           => $aid,
                'tenant_id'    => TENANT,
                'cohort_id'    => $cohortIds[$cohortName],
                'subject_id'   => $subjectIds['MATH'],
                'title'        => $ad['title'],
                'max_score'    => 20,
                'coefficient'  => $ad['coeff'],
                'held_on'      => $ad['held'],
                'published_at' => null, // Left NULL so grades can be entered first
                'kind'         => $ad['kind'],
                'created_at'   => $NOW,
            ]);
            $assessmentIds[$cohortName][] = $aid;
            $assessmentPubTimes[$aid]     = $ad['pub'];
        }
        ok("3 assessments created for $cohortName");
    }

    // =========================================================================
    // 13. GRADES  (realistic scores 10–19/20 for all enrollments)
    // =========================================================================
    $scorePool = [12, 13, 14, 15, 15, 16, 16, 17, 17, 18, 18, 19, 11, 10, 14];
    $poolIdx   = 0;

    foreach ($teacherCohorts as $cohortName) {
        foreach ($assessmentIds[$cohortName] as $asmId) {
            foreach ($enrollmentIds[$cohortName] as $enrId) {
                DB::table('school.grades')->insert([
                    'id'            => uuid(),
                    'tenant_id'     => TENANT,
                    'assessment_id' => $asmId,
                    'enrollment_id' => $enrId,
                    'score'         => $scorePool[$poolIdx % count($scorePool)],
                    'status'        => 'marked',
                    'created_at'    => $NOW,
                ]);
                $poolIdx++;
            }
        }
        ok("Grades inserted for all assessments in $cohortName");
    }

    // Now publish the assessments
    foreach ($assessmentPubTimes as $aid => $pubTime) {
        DB::table('school.assessments')
            ->where('tenant_id', TENANT)
            ->where('id', $aid)
            ->update(['published_at' => $pubTime]);
    }
    ok("Assessments published after grade entry");

    // =========================================================================
    // 14. ANNOUNCEMENTS
    // =========================================================================
    $announcements = [
        [
            'title' => "Bienvenue a l'annee scolaire 2026/2027",
            'body'  => "L'equipe pedagogique souhaite la bienvenue a tous les eleves et parents. Les cours debutent le 1er septembre 2026.",
            'pub'   => '2026-09-01 08:00:00',
        ],
        [
            'title' => "Reunion des parents d'eleves – 1er trimestre",
            'body'  => "Une reunion est prevue le 15 octobre 2026 a 17h00 pour discuter des resultats du premier trimestre. Votre presence est vivement souhaitee.",
            'pub'   => '2026-10-01 10:00:00',
        ],
    ];
    foreach ($announcements as $ann) {
        DB::table('school.announcements')->insert([
            'id'           => uuid(),
            'tenant_id'    => TENANT,
            'title'        => $ann['title'],
            'body'         => $ann['body'],
            'published_at' => $ann['pub'],
            'created_at'   => $NOW,
        ]);
        ok("Announcement: {$ann['title']}");
    }

    echo "\n================================================\n";
    echo "[DONE] All mock data seeded successfully!\n";
    echo "================================================\n\n";
    echo "Summary:\n";
    echo "  - 1 Campus, 1 Period, 1 Program\n";
    echo "  - 5 Levels, 6 Subjects, 5 Offerings, 7 Cohorts, 5 Rooms\n";
    echo "  - 45 Students enrolled (4AP-A: 15, 4AP-B: 15, 5AP-A: 15)\n";
    echo "  - 3 Teacher assignments (Nadia -> MATH)\n";
    echo "  - 6 Published sessions (timetable)\n";
    echo "  - 9 Assessments (3 per cohort)\n";
    echo "  - 135 Grade records\n";
    echo "  - 2 Announcements\n";
});
