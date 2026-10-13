<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Groups;
use App\Filament\School\Pages\Guardians;
use App\Filament\School\Pages\Programs;
use App\Filament\School\Pages\ProgramSubjects;
use App\Filament\School\Pages\Students;
use App\Filament\School\Pages\StudentSheet;
use App\Support\CurrentSchool;
use App\Support\InitialsAvatar;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Lot 2's screens: what the API allows, through the panel, under the restricted roles and the production triggers. */
final class SchoolingPanelTest extends TestCase
{
    /** A school with an open year, its manager (academic_hr) signed in. */
    private function setting(): array
    {
        $manager = $this->account('schooling');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'current'])
            ->assertCreated()->json('data.id');
        return [$school, $manager, $year];
    }

    private function within(object $school, array $account, callable $test): void
    {
        $this->actingAs($this->person($account));
        $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($school, $account, $test) {
            app(CurrentSchool::class)->enter($school->id, $account['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            $test();
        });
    }

    private function node(object $school, string $code = '1AS'): string
    {
        return DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)->where('code', $code)->value('id');
    }

    private function site(object $school): string
    {
        return DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
    }

    public function test_the_pages_follow_the_read_permissions_and_the_module(): void
    {
        [$school] = $this->setting();
        $this->get('/school/programs')->assertOk()->assertSee(__('schooling.create_program'));
        $this->get('/school/groups')->assertOk()->assertSee(__('schooling.create_group'))->assertSee(__('admin.sections.schooling'));
        // The school's administrator reads the programmes, not the groups (section 3.2).
        $admin = $this->account('administrator');
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->signIn($admin);
        $this->get('/school/programs')->assertOk()->assertDontSee(__('schooling.create_program'));
        $this->get('/school/groups')->assertForbidden();
        $stranger = $this->account('without-rights');
        $this->member($school, $stranger);
        $this->signIn($stranger);
        foreach (['programs', 'groups'] as $page) $this->get('/school/'.$page)->assertForbidden();
        // Without the module, neither page exists for anyone.
        [$other, $manager] = $this->setting();
        foreach (['student_finance', 'enrollment_groups'] as $module) {
            DB::connection('owner')->table('platform.tenant_module')->where('tenant_id', $other->id)->where('module_key', $module)->delete();
        }
        foreach (['programs', 'groups'] as $page) $this->get('/school/'.$page)->assertForbidden();
    }

    public function test_a_programme_is_created_priced_and_versioned_and_a_closed_year_freezes_it(): void
    {
        [$school, $manager, $year] = $this->setting();
        $this->within($school, $manager, function () use ($school, $year) {
            Livewire::test(Programs::class)->callTableAction('create', data: ['year_id' => $year, 'node_id' => $this->node($school), 'code' => '1AS-SCI',
                'labels' => ['fr' => '1AS Sciences', 'ar' => 'السنة الأولى علوم'], 'duration_value' => 9, 'duration_unit' => 'months',
                'base_price' => '120000', 'status' => 'active'])->assertNotified(__('school.saved'));
            $program = DB::table('school.program')->where('code', '1AS-SCI')->first();
            $this->assertSame(['120000.00', 1, 'months'], [$program->base_price, $program->price_version, $program->duration_unit]);
            Livewire::test(Programs::class)->searchTable('علوم')->assertCanSeeTableRecords([$program->id])
                ->assertSeeText("120\u{00A0}000,00 DA")->assertSeeText('9 '.__('schooling.duration_units.months'));
            // A duration needs its unit, a price two decimal places at most.
            Livewire::test(Programs::class)->callTableAction('create', data: ['year_id' => $year, 'node_id' => $this->node($school), 'code' => 'BAD',
                'labels' => ['fr' => 'Refus'], 'duration_value' => 3, 'base_price' => '10.555', 'status' => 'draft'])
                ->assertHasTableActionErrors(['duration_unit', 'base_price']);
            Livewire::test(Programs::class)->callTableAction('edit', $program->id, data: ['labels' => ['fr' => '1AS Sciences'],
                'base_price' => '130000', 'status' => 'active', 'version' => 1])->assertNotified(__('school.saved'));
            $this->assertSame(['130000.00', 2], [DB::table('school.program')->where('id', $program->id)->value('base_price'),
                DB::table('school.program')->where('id', $program->id)->value('price_version')]);
            Livewire::test(Programs::class)->mountTableAction('prices', $program->id)
                ->assertMountedActionModalSee("120\u{00A0}000,00 DA")->assertMountedActionModalSee("130\u{00A0}000,00 DA");
            Livewire::test(Programs::class)->callTableAction('edit', $program->id, data: ['labels' => ['fr' => 'Ancienne version'],
                'base_price' => '1', 'status' => 'active', 'version' => 1])->assertNotified(__('errors.stale_version'));
        });
        $this->postJson('/api/years/'.$year.'/status', ['status' => 'closed', 'reason' => 'Année terminée'])->assertOk();
        $this->within($school, $manager, function () {
            $program = DB::table('school.program')->where('code', '1AS-SCI')->value('id');
            Livewire::test(Programs::class)->assertTableActionHidden('edit', $program)->assertTableActionVisible('prices', $program)
                ->assertTableActionHidden('create');
        });
    }

    public function test_a_programme_s_subjects_are_added_versioned_ended_and_removed_on_its_page(): void
    {
        [$school, $manager, $year] = $this->setting();
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $this->node($school), 'code' => 'FS-OCT',
            'labels' => ['fr' => 'Full Stack, session d’octobre'], 'base_price' => '1000', 'status' => 'active'])->assertCreated()->json('data');
        $subject = fn (string $code, array $labels) => $this->postJson('/api/subjects', ['code' => $code, 'labels' => $labels])->assertCreated()->json('data.id');
        [$python, $algo] = [$subject('PYTHON', ['fr' => 'Python', 'ar' => 'بايثون']), $subject('ALGO', ['fr' => 'Algorithmique'])];
        $this->get('/school/program-subjects?program='.$program['id'])->assertOk()->assertSee(__('schooling.add_program_subject'))
            ->assertSee(__('schooling.no_program_subject'));
        $this->get('/school/program-subjects?program='.Str::uuid())->assertNotFound();
        $this->within($school, $manager, function () use ($program, $python, $algo) {
            $page = fn () => Livewire::withQueryParams(['program' => $program['id']])->test(ProgramSubjects::class);
            // AC2: at least one of the total hours, the weekly hours, the sessions.
            $page()->callTableAction('add', data: ['subject_id' => $python, 'session_duration_minutes' => 90])->assertHasTableActionErrors(['total_hours']);
            $page()->callTableAction('add', data: ['subject_id' => $python, 'total_hours' => '40', 'session_count' => 20])->assertNotified(__('school.saved'));
            $line = DB::table('school.program_subject')->where('program_id', $program['id'])->first();
            $this->assertSame(['40.00', 20, 60, 1], [$line->total_hours, $line->session_count, $line->session_duration_minutes, $line->volume_version]);
            // 40 h in 20 sessions: 120 min each, pointed out against the school's 60.
            $page()->assertCanSeeTableRecords([$line->id])->assertSeeText('40 h')->assertSeeText('120 min')->assertSeeText('Python');
            // AC1: 36 h is a second version; the first stays.
            $page()->callTableAction('volume', $line->id, data: ['total_hours' => '36', 'session_count' => 20, 'session_duration_minutes' => 110, 'version' => 1])
                ->assertNotified(__('school.saved'));
            $page()->mountTableAction('volumes', $line->id)->assertMountedActionModalSee('40 h')->assertMountedActionModalSee('36 h');
            $page()->callTableAction('add', data: ['subject_id' => $python, 'weekly_hours' => '2'])->assertNotified(__('errors.program_subject_overlap'));
            // AC3: ended, final; still read, then removed.
            $page()->callTableAction('end', $line->id, data: ['valid_to' => now()->toDateString()])->assertNotified(__('school.saved'));
            $page()->assertTableActionHidden('volume', $line->id)->assertTableActionHidden('end', $line->id)->assertTableActionVisible('volumes', $line->id);
            $page()->callTableAction('remove', $line->id)->assertNotified(__('school.saved'));
            $this->assertSame(0, DB::table('school.program_subject')->where('id', $line->id)->count());
            $page()->callTableAction('add', data: ['subject_id' => $algo, 'weekly_hours' => '1.5'])->assertNotified(__('school.saved'));
        });
        // The school's administrator manages a programme's subjects without managing the programme (section 3.2).
        $admin = $this->account('administrator');
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->within($school, $admin, function () use ($program) {
            Livewire::test(Programs::class)->assertTableActionHidden('edit', $program['id'])->assertTableActionVisible('subjects', $program['id']);
            Livewire::withQueryParams(['program' => $program['id']])->test(ProgramSubjects::class)->assertTableActionVisible('add')->assertSeeText('1,5 h');
        });
        // A closed year freezes them.
        $this->postJson('/api/years/'.$year.'/status', ['status' => 'closed', 'reason' => 'Année terminée'])->assertOk();
        $this->within($school, $manager, function () use ($program) {
            $line = DB::table('school.program_subject')->where('program_id', $program['id'])->value('id');
            Livewire::withQueryParams(['program' => $program['id']])->test(ProgramSubjects::class)->assertTableActionHidden('add')
                ->assertTableActionHidden('volume', $line)->assertTableActionHidden('remove', $line)->assertTableActionVisible('volumes', $line);
        });
    }

    public function test_groups_show_their_places_and_refuse_fewer_places_than_members(): void
    {
        [$school, $manager, $year] = $this->setting();
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $this->node($school), 'code' => '1AS-SCI',
            'labels' => ['fr' => '1AS Sciences'], 'base_price' => '1000', 'status' => 'active'])->assertCreated()->json('data');
        $this->within($school, $manager, function () use ($school, $year, $program) {
            $class = ['year_id' => $year, 'group_type' => 'class', 'site_id' => $this->site($school), 'code' => '1AS-A',
                'labels' => ['fr' => '1AS A'], 'capacity' => 2, 'valid_from' => '2026-09-01', 'status' => 'active'];
            // A class is the main group of a programme's pupils: it names it.
            Livewire::test(Groups::class)->callTableAction('create', data: $class)->assertHasTableActionErrors(['program_id']);
            Livewire::test(Groups::class)->callTableAction('create', data: [...$class, 'program_id' => $program['id']])->assertNotified(__('school.saved'));
            Livewire::test(Groups::class)->callTableAction('create', data: [...$class, 'group_type' => 'support', 'code' => 'SOUTIEN', 'labels' => ['ar' => 'دعم']])
                ->assertNotified(__('school.saved'));
            $this->assertNull(DB::table('school.study_group')->where('code', 'SOUTIEN')->value('program_id'));
        });
        $group = DB::connection('owner')->table('school.study_group')->where('tenant_id', $school->id)->where('code', '1AS-A')->value('id');
        $this->assertSame(['1AS-SCI', 0], [$this->getJson('/api/groups/'.$group)->json('data.program_code'), $this->getJson('/api/groups/'.$group)->json('data.members')]);
        foreach (['Amine', 'Yasmine'] as $name) {
            $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => $name, 'birth_date' => '2000-01-01'])->assertCreated()->json('data.id');
            $enrollment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $program['id'], 'idempotency_key' => (string) Str::uuid()])
                ->assertCreated()->json('data.id');
            $this->postJson('/api/groups/'.$group.'/memberships', ['enrollment_id' => $enrollment])->assertCreated();
        }
        $this->within($school, $manager, function () use ($group) {
            Livewire::test(Groups::class)->filterTable('group_type', 'class')->assertCanSeeTableRecords([$group])->assertCountTableRecords(1)->assertSeeText('2 / 2');
            Livewire::test(Groups::class)->callTableAction('edit', $group, data: ['labels' => ['fr' => '1AS A'], 'capacity' => 1, 'status' => 'active', 'version' => 1])
                ->assertNotified(__('errors.capacity_below_members'));
            Livewire::test(Groups::class)->callTableAction('edit', $group, data: ['labels' => ['fr' => '1AS A'], 'capacity' => 30, 'status' => 'closed', 'version' => 1])
                ->assertNotified(__('school.saved'));
            // A closed group keeps its members and is no longer changed.
            Livewire::test(Groups::class)->assertTableActionHidden('edit', $group)->assertSeeText('2 / 30');
        });
    }

    public function test_a_site_scoped_manager_opens_and_edits_the_groups_of_their_site_only(): void
    {
        [$school, , $year] = $this->setting();
        $annex = $this->postJson('/api/sites', ['code' => 'ANNEXE', 'labels' => ['fr' => 'Annexe']])->assertCreated()->json('data.id');
        $groups = [];
        foreach (['MAIN' => $this->site($school), 'ANNEX' => $annex] as $code => $site) {
            $groups[$code] = $this->postJson('/api/groups', ['year_id' => $year, 'group_type' => 'support', 'site_id' => $site, 'code' => $code,
                'labels' => ['fr' => $code], 'capacity' => 10, 'valid_from' => '2026-09-01', 'status' => 'active'])->assertCreated()->json('data.id');
        }
        $local = $this->account('annex-manager');
        $this->member($school, $local);
        $this->grant($school, $local, 'academic_hr', 'site', [$annex]);
        $this->signIn($local)->assertOk();
        // SQL 029: a group on their site, never on another.
        $this->postJson('/api/groups', ['year_id' => $year, 'group_type' => 'support', 'site_id' => $this->site($school), 'code' => 'SIEGE',
            'labels' => ['fr' => 'Siège'], 'capacity' => 10, 'valid_from' => '2026-09-01', 'status' => 'active'])->assertForbidden();
        $this->within($school, $local, function () use ($groups, $year, $annex) {
            Livewire::test(Groups::class)->assertCanSeeTableRecords([$groups['ANNEX']])->assertCanNotSeeTableRecords([$groups['MAIN']])
                ->assertTableActionVisible('edit', $groups['ANNEX'])->assertTableActionVisible('create');
            Livewire::test(Groups::class)->callTableAction('edit', $groups['ANNEX'], data: ['labels' => ['fr' => 'Annexe, soutien'], 'capacity' => 12,
                'status' => 'active', 'version' => 1])->assertNotified(__('school.saved'));
            $this->assertSame(12, DB::table('school.study_group')->where('id', $groups['ANNEX'])->value('capacity'));
            Livewire::test(Groups::class)->callTableAction('create', data: ['year_id' => $year, 'group_type' => 'support', 'code' => 'ANNEXE-2',
                'labels' => ['fr' => 'Annexe 2'], 'capacity' => 10, 'valid_from' => '2026-09-01', 'status' => 'active'])->assertNotified(__('school.saved'));
            $this->assertSame($annex, DB::table('school.study_group')->where('code', 'ANNEXE-2')->value('site_id'));
        });
    }

    public function test_the_pupils_pages_follow_their_permissions_and_a_sheet_shows_only_a_reachable_pupil(): void
    {
        [$school] = $this->setting();
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '2012-05-01'])->assertCreated()->json('data');
        $this->get('/school/students')->assertOk()->assertSee($pupil['matricule'])->assertSee(__('schooling.create_student'));
        $this->get('/school/guardians')->assertOk()->assertSee(__('schooling.create_guardian'));
        $this->get('/school/student-sheet?student='.$pupil['id'])->assertOk()->assertSee('Benali Amine')->assertSee(__('schooling.link_guardian'));
        $this->get('/school/student-sheet?student='.Str::uuid())->assertNotFound();
        $this->get('/school/student-sheet?student=nothing')->assertNotFound();
        // The school's administrator reads no pupil (section 3.2); a pupil of another school is not found.
        $admin = $this->account('administrator');
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->signIn($admin);
        foreach (['students', 'guardians', 'student-sheet?student='.$pupil['id']] as $page) $this->get('/school/'.$page)->assertForbidden();
        $this->setting();
        $this->get('/school/student-sheet?student='.$pupil['id'])->assertNotFound();
    }

    public function test_a_new_pupil_gets_a_matricule_and_a_probable_duplicate_needs_a_reason(): void
    {
        [$school, $manager] = $this->setting();
        $second = null;
        $this->within($school, $manager, function () use (&$second) {
            Livewire::test(Students::class)->callTableAction('create', data: ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine',
                'birth_date' => '2012-05-01', 'phone' => '0550 12 34 56'])->assertHasNoTableActionErrors()->assertRedirect();
            $first = DB::table('school.student')->where('first_name_lat', 'Amine')->first();
            $this->assertMatchesRegularExpression('/^'.now()->year.'\d{5}$/', $first->matricule);
            // The same child written in Arabic, born the same day: a probable duplicate (FR-ELV-04 AC1). Nothing is
            // created until a reason is given; the reason goes to the journal.
            $arabic = ['last_name_ar' => 'بن علي', 'first_name_ar' => 'محمد أمين', 'birth_date' => '2012-05-01'];
            $page = Livewire::test(Students::class)->mountTableAction('create')->setTableActionData($arabic)->callMountedTableAction()
                ->assertNotified(__('schooling.duplicates_found'))->assertSet('duplicates.0.id', $first->id)->assertSee($first->matricule);
            $this->assertSame(1, DB::table('school.student')->count());
            $page->setTableActionData([...$arabic, 'duplicate_reason' => 'Homonyme d’une autre famille'])->callMountedTableAction()->assertRedirect();
            $second = DB::table('school.student')->where('first_name_ar', 'محمد أمين')->value('id');
            // A matricule taken from another register stays as it is; the same one twice is refused.
            Livewire::test(Students::class)->callTableAction('create', data: ['last_name_lat' => 'Kaci', 'first_name_lat' => 'Lina',
                'birth_date' => '2011-02-03', 'matricule' => 'ANC-0042'])->assertRedirect();
            Livewire::test(Students::class)->callTableAction('create', data: ['last_name_lat' => 'Kaci', 'first_name_lat' => 'Sara',
                'birth_date' => '2010-02-03', 'matricule' => 'ANC-0042'])->assertNotified(__('errors.duplicate'));
            Livewire::test(Students::class)->searchTable('ANC-0042')->assertCountTableRecords(1)->searchTable('امين')->assertCanSeeTableRecords([$second]);
            // Without any full name, or born tomorrow: refused before anything is written.
            Livewire::test(Students::class)->callTableAction('create', data: ['last_name_lat' => 'Seul', 'birth_date' => now()->addDay()->toDateString()])
                ->assertHasTableActionErrors(['first_name_lat', 'birth_date']);
        });
        $this->assertSame('Homonyme d’une autre famille', DB::connection('owner')->table('school.audit_log')->where('tenant_id', $school->id)
            ->where('object_id', $second)->where('action', 'INSERT')->value('reason'));
    }

    public function test_the_sheet_links_one_guardian_to_brothers_and_sisters_and_a_block_leaves_enrolments_as_they_are(): void
    {
        [$school, $manager, $year] = $this->setting();
        $pupils = [];
        foreach (['Amine', 'Yasmine'] as $name) {
            $pupils[] = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => $name, 'birth_date' => '2012-05-01'])->assertCreated()->json('data.id');
        }
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $this->node($school), 'code' => '1AS-SCI',
            'labels' => ['fr' => '1AS Sciences'], 'base_price' => '1000', 'status' => 'active'])->assertCreated()->json('data.id');
        $this->within($school, $manager, function () use ($pupils) {
            Livewire::withQueryParams(['student' => $pupils[0]])->test(StudentSheet::class)->assertSeeText(__('schooling.no_guardian'))
                ->callTableAction('createGuardian', data: ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550123456',
                    'profession' => 'Médecin', 'relationship' => 'father', 'is_primary_contact' => true])->assertNotified(__('school.saved'));
            $guardian = DB::table('school.guardian')->where('first_name_lat', 'Karim')->value('id');
            // FR-ELV-02 AC1: the second child is linked to the same record.
            $sheet = Livewire::withQueryParams(['student' => $pupils[1]])->test(StudentSheet::class)
                ->callTableAction('link', data: ['guardian_id' => $guardian, 'relationship' => 'father', 'is_primary_contact' => true])->assertNotified(__('school.saved'));
            $link = DB::table('school.student_guardian')->where('student_id', $pupils[1])->value('id');
            $sheet->assertCanSeeTableRecords([$link])->assertSeeText('Benali Karim')->assertSeeText('0550123456');
            // A refused action keeps its form open: the next one starts from the page again.
            Livewire::withQueryParams(['student' => $pupils[1]])->test(StudentSheet::class)
                ->callTableAction('link', data: ['guardian_id' => $guardian, 'relationship' => 'father'])->assertNotified(__('errors.duplicate'));
            $sheet->callTableAction('editLink', $link, data: ['relationship' => 'guardian', 'is_primary_contact' => true, 'is_payer' => true,
                    'emergency_contact' => false, 'version' => 1])->assertNotified(__('school.saved'))
                ->callTableAction('editGuardian', $link, data: ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0661000000', 'version' => 1])
                ->assertNotified(__('school.saved'));
            $this->assertSame(1, DB::table('school.guardian')->count());
            $this->assertSame(['guardian', true, '0661000000'], [DB::table('school.student_guardian')->where('id', $link)->value('relationship'),
                (bool) DB::table('school.student_guardian')->where('id', $link)->value('is_payer'), DB::table('school.guardian')->where('id', $guardian)->value('phone')]);
            $sheet->callTableAction('unlink', $link, data: ['reason' => 'Erreur de saisie'])->assertNotified(__('school.saved'))->assertCanNotSeeTableRecords([$link]);
        });
        $enrollment = $this->postJson('/api/enrollments', ['student_id' => $pupils[0], 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        $this->within($school, $manager, function () use ($pupils, $enrollment) {
            // FR-ELV-03 AC1: blocking warns of the enrolments and leaves them as they are.
            Livewire::withQueryParams(['student' => $pupils[0]])->test(StudentSheet::class)->mountAction('status')->setActionData(['status' => 'blocked'])
                ->assertMountedActionModalSee(__('schooling.block_enrollments_help', ['count' => '1']))
                ->setActionData(['status' => 'blocked', 'reason' => 'Dossier incomplet'])->callMountedAction()->assertNotified(__('school.saved'))
                ->assertSeeText('Dossier incomplet')->assertActionHidden('archive');
            $this->assertSame(['blocked', 'pending'], [DB::table('school.student')->where('id', $pupils[0])->value('status'),
                DB::table('school.enrollment')->where('id', $enrollment)->value('status')]);
            // A former pupil's record stays as it is, then leaves the lists once archived.
            Livewire::withQueryParams(['student' => $pupils[1]])->test(StudentSheet::class)
                ->callAction('status', data: ['status' => 'former', 'reason' => 'Parti en fin d’année'])->assertNotified(__('school.saved'))
                ->assertActionHidden('edit')->assertActionHidden('status')->assertTableActionHidden('link')
                ->callAction('archive', data: ['reason' => 'Dossier clos'])->assertNotified(__('school.saved'));
            Livewire::test(Students::class)->assertCanNotSeeTableRecords([$pupils[1]])->filterTable('archived', true)->assertCanSeeTableRecords([$pupils[1]]);
        });
    }

    public function test_a_guardian_typed_again_is_found_and_linked_rather_than_recorded_twice(): void
    {
        [$school, $manager] = $this->setting();
        $pupils = [];
        foreach (['Amine', 'Yasmine'] as $name) {
            $pupils[] = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => $name, 'birth_date' => '2012-05-01'])->assertCreated()->json('data.id');
        }
        $this->postJson('/api/students/'.$pupils[0].'/guardians', ['relationship' => 'father', 'is_primary_contact' => true, 'guardian_id' =>
            $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550 12 34 56'])->assertCreated()->json('data.id')])
            ->assertCreated();
        $karim = DB::connection('owner')->table('school.guardian')->where('tenant_id', $school->id)->value('id');
        $this->within($school, $manager, function () use ($pupils, $karim) {
            // FR-ELV-02 AC1: the second child's father, typed in Arabic letters, is the first one's.
            $father = ['last_name_ar' => 'بن علي', 'first_name_ar' => 'كريم', 'phone' => '0550123456', 'relationship' => 'father', 'is_primary_contact' => true];
            $sheet = Livewire::withQueryParams(['student' => $pupils[1]])->test(StudentSheet::class)->mountTableAction('createGuardian')
                ->setTableActionData($father)->callMountedTableAction()->assertNotified(__('schooling.guardian_duplicates_found'))
                ->assertSet('sameGuardians.0.id', $karim)->assertMountedActionModalSee(__('schooling.duplicate_matches.phone'));
            $this->assertSame(1, DB::table('school.guardian')->count());
            $sheet->setTableActionData([...$father, 'existing' => $karim])->callMountedTableAction()->assertNotified(__('school.saved'));
            $this->assertSame(1, DB::table('school.guardian')->count());
            $this->assertSame($karim, DB::table('school.student_guardian')->where('student_id', $pupils[1])->value('guardian_id'));
            // The mother on the household's phone is another person, recorded at once.
            Livewire::withQueryParams(['student' => $pupils[1]])->test(StudentSheet::class)->callTableAction('createGuardian', data: ['last_name_lat' => 'Benali',
                'first_name_lat' => 'Mina', 'phone' => '0550123456', 'relationship' => 'mother'])->assertNotified(__('school.saved'));
            $this->assertSame(2, DB::table('school.guardian')->count());
            // From the guardians' list, a second record of the same person needs a reason, kept in the journal.
            $page = Livewire::test(Guardians::class)->mountTableAction('create')->setTableActionData(['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim',
                'phone' => '0550123456'])->callMountedTableAction()->assertNotified(__('schooling.guardian_duplicates_found'))->assertSet('duplicates.0.id', $karim);
            $this->assertSame(2, DB::table('school.guardian')->count());
            $page->setTableActionData(['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550123456', 'duplicate_reason' => 'Homonyme : le grand-père'])
                ->callMountedTableAction()->assertNotified(__('school.saved'));
            $this->assertSame(3, DB::table('school.guardian')->count());
        });
    }

    public function test_the_sheet_shows_the_photo_or_the_initials_and_adds_replaces_and_removes_the_photo(): void
    {
        Storage::fake('files');
        [$school, $manager] = $this->setting();
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '2012-05-01'])->assertCreated()->json('data.id');
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 60));
        $stream = fopen('php://memory', 'w+b');
        imagejpeg($image, $stream);
        rewind($stream);
        $jpeg = stream_get_contents($stream);
        $this->within($school, $manager, function () use ($pupil, $jpeg) {
            // FR-ELV-01: without a photo, the pupil's initials, drawn here.
            Livewire::withQueryParams(['student' => $pupil])->test(StudentSheet::class)->assertSee(InitialsAvatar::of('Benali Amine'), false)
                ->assertActionVisible('photo')->assertActionHidden('removePhoto')
                ->callAction('photo', data: ['photo' => UploadedFile::fake()->createWithContent('amine.jpg', $jpeg), 'square' => true])
                ->assertNotified(__('school.saved'))->assertSee('/api/students/'.$pupil.'/photo?size=thumb&amp;v=2', false)
                ->assertSeeText(__('schooling.change_photo'))->assertActionVisible('removePhoto')
                ->callAction('photo', data: ['photo' => UploadedFile::fake()->createWithContent('amine.pdf', "%PDF-1.4\n%%EOF\n"), 'square' => true])
                ->assertHasActionErrors(['photo']);
        });
        $this->assertSame(2, DB::connection('owner')->table('school.student')->where('id', $pupil)->value('version'));
        $this->assertCount(2, Storage::disk('files')->allFiles());
        $this->within($school, $manager, function () use ($pupil) {
            Livewire::withQueryParams(['student' => $pupil])->test(StudentSheet::class)->callAction('removePhoto')->assertNotified(__('school.saved'))
                ->assertSee(InitialsAvatar::of('Benali Amine'), false)->assertActionHidden('removePhoto');
        });
        // The image is gone for good once the change is committed (BR-17).
        $this->assertSame([], Storage::disk('files')->allFiles());
        // A school that turned photos off offers none, and a reader who may not change the record gets no photo action.
        DB::connection('owner')->table('school.setting')->insert(['tenant_id' => $school->id, 'key' => 'student.photo.enabled', 'value' => 'false',
            'created_at' => now(), 'updated_at' => now()]);
        $this->within($school, $manager, function () use ($pupil) {
            Livewire::withQueryParams(['student' => $pupil])->test(StudentSheet::class)->assertActionHidden('photo')->assertActionHidden('removePhoto');
        });
    }

    public function test_a_site_scoped_secretary_records_and_follows_the_pupils_of_their_site_only(): void
    {
        [$school, , $year] = $this->setting();
        $annex = $this->postJson('/api/sites', ['code' => 'ANNEXE', 'labels' => ['fr' => 'Annexe']])->assertCreated()->json('data.id');
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $this->node($school), 'code' => 'P',
            'labels' => ['fr' => 'P'], 'base_price' => '0', 'status' => 'active'])->assertCreated()->json('data.id');
        $pupils = $groups = [];
        foreach (['MAIN' => $this->site($school), 'ANNEX' => $annex] as $code => $site) {
            $groups[$code] = $this->postJson('/api/groups', ['year_id' => $year, 'group_type' => 'class', 'program_id' => $program, 'site_id' => $site, 'code' => $code,
                'labels' => ['fr' => $code], 'capacity' => 10, 'valid_from' => '2026-09-01', 'status' => 'active'])->assertCreated()->json('data.id');
            $pupils[$code] = $this->postJson('/api/students', ['last_name_lat' => 'Eleve', 'first_name_lat' => $code, 'birth_date' => '2000-01-01'])->json('data.id');
            $enrollment = $this->postJson('/api/enrollments', ['student_id' => $pupils[$code], 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])->json('data.id');
            $this->postJson('/api/groups/'.$groups[$code].'/memberships', ['enrollment_id' => $enrollment])->assertCreated();
        }
        $secretary = $this->account('annex-secretary');
        $this->member($school, $secretary);
        $this->grant($school, $secretary, 'front_office', 'site', [$annex]);
        $this->signIn($secretary)->assertOk();
        $this->get('/school/student-sheet?student='.$pupils['MAIN'])->assertNotFound();
        // Recorded on another site: refused; on theirs, by default.
        $this->postJson('/api/students', ['last_name_lat' => 'Siege', 'first_name_lat' => 'Refus', 'birth_date' => '2000-03-03', 'site_id' => $this->site($school)])
            ->assertForbidden()->assertJsonPath('code', 'permission_denied');
        $this->within($school, $secretary, function () use ($pupils, $annex, $groups, $program) {
            Livewire::test(Students::class)->assertCanSeeTableRecords([$pupils['ANNEX']])->assertCanNotSeeTableRecords([$pupils['MAIN']])
                ->assertTableActionVisible('create');
            Livewire::withQueryParams(['student' => $pupils['ANNEX']])->test(StudentSheet::class)->assertActionVisible('edit')
                ->callAction('edit', data: ['last_name_lat' => 'Eleve', 'first_name_lat' => 'Annexe', 'birth_date' => '2000-01-01', 'version' => 1])
                ->assertNotified(__('school.saved'))->assertSee('Eleve Annexe')->assertTableActionVisible('link');
            // SQL 029: their new pupil belongs to their site from the start, then is enrolled and placed there.
            Livewire::test(Students::class)->callTableAction('create', data: ['last_name_lat' => 'Nouveau', 'first_name_lat' => 'Annexe', 'birth_date' => '2000-02-02'])
                ->assertNotified();
            $new = DB::table('school.student')->where('first_name_lat', 'Annexe')->where('last_name_lat', 'Nouveau')->first();
            $this->assertSame($annex, $new->site_id);
            Livewire::withQueryParams(['student' => $new->id])->test(StudentSheet::class)->assertSeeText('Annexe')
                ->callAction('enrol', data: ['program_id' => $program, 'group_id' => $groups['ANNEX']])->assertNotified(__('schooling.enrolled'));
            $this->assertSame(1, DB::table('school.group_membership')->join('school.enrollment', 'school.enrollment.id', '=', 'school.group_membership.enrollment_id')
                ->where('school.enrollment.student_id', $new->id)->where('school.group_membership.group_id', $groups['ANNEX'])->count());
            Livewire::test(Students::class)->filterTable('site_id', $annex)->assertCanSeeTableRecords([$new->id]);
        });
    }
}
