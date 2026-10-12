<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Enrollments;
use App\Filament\School\Pages\GroupMembers;
use App\Filament\School\Pages\Groups;
use App\Filament\School\Pages\Programs;
use App\Filament\School\Pages\Students;
use App\Filament\School\Pages\StudentSheet;
use App\Filament\School\Pages\Years;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lot 2's walkthrough through its screens only (FR-INS-01's flow): a year, a programme and its
 * classes; a minor pupil and their father; the enrolment at the day's price, in a class at once;
 * its validation, a transfer, its history, its cancellation giving the place back.
 */
final class LotTwoWalkthroughTest extends TestCase
{
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

    private function snapshot(string $path): string
    {
        $html = $this->get($path)->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $raw) {
            $snapshot = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
            if (array_key_exists('tableSearch', json_decode($snapshot, true)['data'])) return $snapshot;
        }
        $this->fail('Missing page snapshot: '.$path);
    }

    private function update(string $snapshot, array $calls, array $updates = []): TestResponse
    {
        app('livewire')->flushState();
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => $updates,
            'calls' => array_map(fn ($call) => ['path' => '', 'method' => $call[0], 'params' => $call[1] ?? []], $calls)]]], ['X-Livewire' => 'true']);
    }

    private function manager(): array
    {
        $manager = $this->account('secretariat');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        return [$school, $manager];
    }

    public function test_a_minor_is_enrolled_placed_validated_transferred_and_cancelled_through_the_screens(): void
    {
        [$school, $manager] = $this->manager();
        $this->within($school, $manager, function () {
            Livewire::test(Years::class)->callTableAction('create', data: ['code' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30',
                'status' => 'current'])->assertNotified(__('school.saved'));
            $year = DB::table('school.academic_year')->value('id');
            Livewire::test(Programs::class)->callTableAction('create', data: ['year_id' => $year, 'node_id' => DB::table('school.ref_node')->where('code', '1AS')->value('id'),
                'code' => '1AS-SCI', 'labels' => ['fr' => '1AS Sciences'], 'base_price' => '120000', 'status' => 'active'])->assertNotified(__('school.saved'));
            $program = DB::table('school.program')->value('id');
            foreach (['1AS-A', '1AS-B'] as $code) {
                Livewire::test(Groups::class)->callTableAction('create', data: ['year_id' => $year, 'group_type' => 'class', 'program_id' => $program,
                    'site_id' => DB::table('school.site')->value('id'), 'code' => $code, 'labels' => ['fr' => $code], 'capacity' => 25, 'valid_from' => '2026-09-01',
                    'status' => 'active'])->assertNotified(__('school.saved'));
            }
            [$classA, $classB] = DB::table('school.study_group')->orderBy('code')->pluck('id')->all();

            Livewire::test(Students::class)->callTableAction('create', data: ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '2012-05-01'])
                ->assertRedirect();
            $pupil = DB::table('school.student')->value('id');
            // FR-ELV-02 AC2: a minor without a primary contact is not enrolled.
            Livewire::withQueryParams(['student' => $pupil])->test(StudentSheet::class)
                ->callAction('enrol', data: ['program_id' => $program, 'group_id' => $classA])->assertNotified(__('errors.primary_contact_required'));
            $this->assertSame(0, DB::table('school.enrollment')->count());
            Livewire::withQueryParams(['student' => $pupil])->test(StudentSheet::class)
                ->callTableAction('createGuardian', data: ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550123456',
                    'relationship' => 'father', 'is_primary_contact' => true])->assertNotified(__('school.saved'));
            // Enrolled at the day's price, in class A at once; the sheet shows it.
            Livewire::withQueryParams(['student' => $pupil])->test(StudentSheet::class)
                ->callAction('enrol', data: ['program_id' => $program, 'group_id' => $classA])->assertNotified(__('schooling.enrolled'))
                ->assertSeeText('1AS-SCI')->assertSeeText('1AS-A');
            $enrollment = DB::table('school.enrollment')->first();
            $this->assertSame(['pending', '120000.00'], [$enrollment->status, $enrollment->agreed_price]);
            $membership = DB::table('school.group_membership')->value('id');

            Livewire::test(Enrollments::class)->assertCanSeeTableRecords([$enrollment->id])->assertSeeText('Benali Amine')
                ->callTableAction('validate', $enrollment->id, data: ['reason' => 'Dossier complet'])->assertNotified(__('school.saved'));
            $this->assertSame('validated', DB::table('school.enrollment')->where('id', $enrollment->id)->value('status'));
            // FR-GRP-02 AC3: a transfer ends one membership and opens the next; the history shows both.
            Livewire::withQueryParams(['group' => $classA])->test(GroupMembers::class)->assertCanSeeTableRecords([$membership])->assertSeeText('Benali Amine')
                ->callTableAction('transfer', $membership, data: ['group_id' => $classB, 'reason' => 'Équilibrage des classes'])->assertNotified(__('school.saved'))
                ->assertCanNotSeeTableRecords([$membership])->filterTable('history', true)->assertCanSeeTableRecords([$membership]);
            Livewire::test(Enrollments::class)->mountTableAction('history', $enrollment->id)->assertMountedActionModalSee('1AS-A')
                ->assertMountedActionModalSee('1AS-B')->assertMountedActionModalSee('Dossier complet');
            // FR-INS-03: cancelled, it is frozen and gives its place back.
            Livewire::test(Enrollments::class)->callTableAction('cancel', $enrollment->id, data: ['reason' => 'Départ de la famille'])->assertNotified(__('school.saved'));
            Livewire::test(Enrollments::class)->assertTableActionHidden('validate', $enrollment->id)->assertTableActionHidden('assign', $enrollment->id)
                ->assertTableActionHidden('cancel', $enrollment->id);
            Livewire::test(Groups::class)->assertSeeText('0 / 25')->assertDontSeeText('1 / 25');
        });
    }

    public function test_a_minor_keeps_their_primary_contact_and_changes_programme_through_the_screens(): void
    {
        [$school, $manager] = $this->manager();
        $year = $this->postJson('/api/years', ['code' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'current'])->json('data.id');
        $node = DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)->where('code', '1AS')->value('id');
        $site = DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->value('id');
        [$sciences, $letters] = array_map(fn (string $code) => $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => $code,
            'labels' => ['fr' => $code], 'base_price' => '100000', 'status' => 'active'])->assertCreated()->json('data.id'), ['1AS-SCI', '1AS-LET']);
        [$classS, $classL] = array_map(fn (array $class) => $this->postJson('/api/groups', ['year_id' => $year, 'group_type' => 'class', 'program_id' => $class[0],
            'site_id' => $site, 'code' => $class[1], 'labels' => ['fr' => $class[1]], 'capacity' => 25, 'valid_from' => '2026-09-01', 'status' => 'active'])
            ->assertCreated()->json('data.id'), [[$sciences, 'SCI-A'], [$letters, 'LET-A']]);
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '2012-05-01'])->json('data.id');
        $father = $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550123456'])->json('data.id');
        $link = $this->postJson('/api/students/'.$pupil.'/guardians', ['guardian_id' => $father, 'relationship' => 'father', 'is_primary_contact' => true])->json('data.id');
        $source = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $sciences, 'idempotency_key' => (string) Str::uuid()])->json('data.id');
        $this->postJson('/api/groups/'.$classS.'/memberships', ['enrollment_id' => $source])->assertCreated();
        $this->within($school, $manager, function () use ($pupil, $link, $source, $letters, $classL) {
            // FR-ELV-02: the enrolled minor's only primary contact stays.
            Livewire::withQueryParams(['student' => $pupil])->test(StudentSheet::class)
                ->callTableAction('unlink', $link, data: ['reason' => 'Erreur de saisie'])->assertNotified(__('errors.primary_contact_kept'));
            $this->assertNull(DB::table('school.student_guardian')->where('id', $link)->value('archived_at'));
            // FR-INS-03: to the letters programme and its class, in one go.
            Livewire::test(Enrollments::class)->callTableAction('transfer', $source, data: ['program_id' => $letters, 'group_id' => $classL,
                'reason' => 'Réorientation en lettres'])->assertNotified(__('schooling.transferred'))
                ->assertTableActionHidden('transfer', $source)->assertTableActionHidden('cancel', $source);
            $target = DB::table('school.enrollment')->where('transferred_from', $source)->first();
            $this->assertSame(['cancelled', 'pending'], [DB::table('school.enrollment')->where('id', $source)->value('status'), $target->status]);
            $this->assertSame($classL, DB::table('school.group_membership')->where('enrollment_id', $target->id)->whereNull('valid_to')->value('group_id'));
            Livewire::test(Enrollments::class)->mountTableAction('history', $target->id)->assertMountedActionModalSee(__('schooling.transferred_from'))
                ->assertMountedActionModalSee('1AS-SCI');
            Livewire::test(Enrollments::class)->mountTableAction('history', $source)->assertMountedActionModalSee(__('schooling.transferred_to'))
                ->assertMountedActionModalSee('1AS-LET')->assertMountedActionModalSee('Réorientation en lettres');
        });
    }

    public function test_the_enrolment_form_offers_active_programmes_only_and_the_pages_follow_their_permissions(): void
    {
        [$school, $manager] = $this->manager();
        $year = $this->postJson('/api/years', ['code' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'current'])->json('data.id');
        $node = DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)->where('code', '1AS')->value('id');
        $programs = [];
        foreach (['active' => 'OUVERT', 'draft' => 'PROJET', 'inactive' => 'FERME'] as $status => $code) {
            $programs[$status] = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => $code, 'labels' => ['fr' => $code],
                'base_price' => '0', 'status' => $status])->assertCreated()->json('data.id');
        }
        $group = $this->postJson('/api/groups', ['year_id' => $year, 'group_type' => 'class', 'program_id' => $programs['active'], 'site_id' => DB::connection('owner')
            ->table('school.site')->where('tenant_id', $school->id)->value('id'), 'code' => 'A', 'labels' => ['fr' => 'A'], 'capacity' => 5, 'valid_from' => '2026-09-01',
            'status' => 'active'])->assertCreated()->json('data.id');
        $this->get('/school/enrollments')->assertOk()->assertSee(__('schooling.enrol'));
        $this->get('/school/group-members?group='.$group)->assertOk()->assertSee(__('schooling.add_member'));
        $this->get('/school/group-members?group='.Str::uuid())->assertNotFound();
        $this->within($school, $manager, function () use ($programs) {
            // FR-PRG-01 AC2: an inactive programme is not offered.
            $page = Livewire::test(Enrollments::class)->instance();
            $this->assertSame([$programs['active']], array_keys((fn () => $this->activePrograms(''))->call($page)));
        });
        // The school's administrator reads programmes, not enrolments nor groups (section 3.2).
        $admin = $this->account('administrator');
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->signIn($admin);
        foreach (['enrollments', 'group-members?group='.$group] as $page) $this->get('/school/'.$page)->assertForbidden();
    }

    public function test_a_double_click_on_enrol_makes_one_enrolment(): void
    {
        [$school] = $this->manager();
        $year = $this->postJson('/api/years', ['code' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'current'])->json('data.id');
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)
            ->where('code', '1AS')->value('id'), 'code' => 'P', 'labels' => ['fr' => 'P'], 'base_price' => '0', 'status' => 'active'])->assertCreated()->json('data.id');
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Kaci', 'first_name_lat' => 'Lina', 'birth_date' => '2000-01-01'])->assertCreated()->json('data.id');
        $mounted = $this->update($this->snapshot('/school/enrollments'), [['mountAction', ['enrol', [], ['table' => true]]]])->assertOk();
        $form = $mounted->json('components.0.snapshot');
        // FR-INS-01 AC1, TST-08: the same form sent twice, as a double click sends it; the second answer is the first enrolment, not a refusal.
        foreach ([1, 2] as $click) {
            session()->forget(['filament.notifications', 'filament.claimed_notifications']);
            $this->update($form, [['callMountedAction']], ['mountedActions.0.data.student_id' => $pupil, 'mountedActions.0.data.program_id' => $program])->assertOk();
            $this->assertSame([__('schooling.enrolled')], array_column(session('filament.claimed_notifications', []), 'title'));
        }
        $this->assertSame(1, DB::connection('owner')->table('school.enrollment')->where('tenant_id', $school->id)->where('student_id', $pupil)->count());
    }
}
