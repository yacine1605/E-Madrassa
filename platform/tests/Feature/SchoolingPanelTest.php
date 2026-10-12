<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Groups;
use App\Filament\School\Pages\Programs;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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

    public function test_a_site_scoped_manager_edits_the_groups_of_their_site_only(): void
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
        $this->within($school, $local, function () use ($groups) {
            Livewire::test(Groups::class)->assertCanSeeTableRecords([$groups['ANNEX']])->assertCanNotSeeTableRecords([$groups['MAIN']])
                ->assertTableActionVisible('edit', $groups['ANNEX'])->assertTableActionHidden('create');
            Livewire::test(Groups::class)->callTableAction('edit', $groups['ANNEX'], data: ['labels' => ['fr' => 'Annexe, soutien'], 'capacity' => 12,
                'status' => 'active', 'version' => 1])->assertNotified(__('school.saved'));
            $this->assertSame(12, DB::table('school.study_group')->where('id', $groups['ANNEX'])->value('capacity'));
        });
    }
}
