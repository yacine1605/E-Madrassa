<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Periods;
use App\Filament\School\Pages\ReferenceNodes;
use App\Filament\School\Pages\ReferenceTypes;
use App\Filament\School\Pages\Subjects;
use App\Filament\School\Pages\Years;
use App\Models\User;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/** FR-REF-01/02/03/05: actions run under school_runtime, not the migration owner's privileges. */
final class SchoolReferencePanelTest extends TestCase
{
    private function setting(string $profile = 'private_school_dz'): array
    {
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin, $profile);
        $this->member($school, $admin);
        $this->signIn($admin)->assertOk();
        return [$school, $admin];
    }

    private function within(object $school, array $account, callable $test): void
    {
        $this->actingAs(User::findOrFail($account['id']));
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
            $data = json_decode($snapshot, true)['data'];
            if (isset($data['schoolId']) && array_key_exists('tableSearch', $data)) return $snapshot;
        }
        $this->fail('Missing school page snapshot: '.$path);
    }

    private function update(string $snapshot, array $calls, array $updates = []): TestResponse
    {
        app('livewire')->flushState();
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => $updates,
            'calls' => array_map(fn ($call) => ['path' => '', 'method' => $call[0], 'params' => $call[1] ?? []], $calls)]]], ['X-Livewire' => 'true']);
    }

    public function test_the_profile_names_the_catalog_and_reference_types_and_all_pages_require_read_access(): void
    {
        [$school, $admin] = $this->setting();
        foreach (['reference-types', 'reference-nodes', 'subjects', 'years', 'periods'] as $page) $this->get('/school/'.$page)->assertOk();
        $this->get('/school/reference-types')->assertSee('Niveau');
        $this->get('/school/subjects')->assertSee('Matières')->assertSee('Mathématiques');
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher);
        $this->within($school, $teacher, function () {
            foreach ([ReferenceNodes::class, Subjects::class, Years::class] as $page) Livewire::test($page)->assertTableActionHidden('create');
        });
        $stranger = $this->account('without-rights');
        $this->member($school, $stranger);
        $this->signIn($stranger);
        foreach (['reference-types', 'reference-nodes', 'subjects', 'years', 'periods'] as $page) $this->get('/school/'.$page)->assertForbidden();

        [$center, $centerAdmin] = $this->setting('training_center');
        $this->get('/school/reference-types')->assertOk()->assertSee('Spécialité')->assertDontSee('Niveau');
        $this->get('/school/subjects')->assertOk()->assertSee('Modules de formation');
        // Searching a new, empty catalog must remain a valid page.
        $this->within($center, $centerAdmin, fn () => Livewire::test(Subjects::class)->searchTable('inexistant')->assertCountTableRecords(0));
    }

    public function test_types_keep_their_code_and_refuse_stale_or_in_use_changes(): void
    {
        [$school, $admin] = $this->setting();
        $this->within($school, $admin, function () {
            Livewire::test(ReferenceTypes::class)->callTableAction('edit', 'grade', data: ['labels' => ['ar' => 'المستوى الدراسي'], 'active' => true, 'version' => 1])
                ->assertNotified(__('school.saved'));
            $this->assertSame('grade', DB::table('school.node_type')->where('code', 'grade')->value('code'));
            Livewire::test(ReferenceTypes::class)->callTableAction('edit', 'grade', data: ['labels' => ['fr' => 'Ancienne version'], 'active' => true, 'version' => 1])
                ->assertNotified(__('errors.stale_version'));
            Livewire::test(ReferenceTypes::class)->callTableAction('edit', 'track', data: ['labels' => ['fr' => 'Filière'], 'active' => false, 'version' => 1])
                ->assertNotified(__('errors.type_in_use'));
            $this->assertTrue((bool) DB::table('school.node_type')->where('code', 'track')->value('active'));
        });
    }

    public function test_nodes_accept_arabic_labels_and_duplicate_refusals_leave_the_form_and_transaction_usable(): void
    {
        [$school, $admin] = $this->setting();
        $this->within($school, $admin, function () {
            $cycle = DB::table('school.ref_node')->where('code', 'SECONDAIRE')->value('id');
            $page = Livewire::test(ReferenceNodes::class)->callTableAction('create', data: ['type' => 'grade', 'parent_id' => $cycle,
                'code' => 'AR_NEW', 'labels' => ['ar' => 'أحمد'], 'sort_order' => 0])->assertNotified(__('school.saved'));
            $id = DB::table('school.ref_node')->where('code', 'AR_NEW')->value('id');
            $page->filterTable('type', 'grade')->searchTable('احمد')->assertCanSeeTableRecords([$id])->assertCountTableRecords(1);
            Livewire::test(ReferenceNodes::class)->callTableAction('create', data: ['type' => 'grade', 'parent_id' => $cycle,
                'code' => 'AR_DUP', 'labels' => ['ar' => 'أَحْمد'], 'sort_order' => 0])->assertNotified(__('errors.duplicate'));
            $this->assertSame(0, DB::table('school.ref_node')->where('code', 'AR_DUP')->count());
            $this->assertTrue((bool) DB::selectOne("SELECT school.can('referential.node.manage') AS ok")->ok);
        });
    }

    public function test_parent_choices_refuse_wrong_types_and_other_schools_and_archiving_proceeds_from_children(): void
    {
        [$other] = $this->setting();
        $foreign = DB::connection('owner')->table('school.ref_node')->where('tenant_id', $other->id)->where('type_code', 'cycle')->value('id');
        [$school, $admin] = $this->setting();
        $this->within($school, $admin, function () use ($foreign) {
            $wrong = DB::table('school.ref_node')->where('type_code', 'grade')->value('id');
            foreach ([$foreign, $wrong] as $parent) Livewire::test(ReferenceNodes::class)->callTableAction('create', data: [
                'type' => 'grade', 'parent_id' => $parent, 'code' => 'INVALID', 'labels' => ['fr' => 'Refus'], 'sort_order' => 0])->assertHasTableActionErrors(['parent_id']);
            $this->assertSame(0, DB::table('school.ref_node')->where('code', 'INVALID')->count());
            Livewire::test(ReferenceNodes::class)->callTableAction('create', data: ['type' => 'cycle', 'code' => 'EXTRA', 'labels' => ['fr' => 'Cycle supplémentaire'], 'sort_order' => 0]);
            $parent = DB::table('school.ref_node')->where('code', 'EXTRA')->value('id');
            Livewire::test(ReferenceNodes::class)->callTableAction('create', data: ['type' => 'grade', 'parent_id' => $parent,
                'code' => 'CHILD', 'labels' => ['fr' => 'Enfant supplémentaire'], 'sort_order' => 0]);
            $child = DB::table('school.ref_node')->where('code', 'CHILD')->value('id');
            Livewire::test(ReferenceNodes::class)->callTableAction('archive', $parent, data: ['reason' => 'Fin du cycle'])
                ->assertNotified(__('errors.node_has_children'));
            Livewire::test(ReferenceNodes::class)->callTableAction('archive', $child, data: ['reason' => 'Fin du niveau'])->assertNotified(__('school.saved'));
            Livewire::test(ReferenceNodes::class)->callTableAction('archive', $parent, data: ['reason' => 'Fin du cycle'])->assertNotified(__('school.saved'));
            Livewire::test(ReferenceNodes::class)->filterTable('archive_status', 'archived')->assertCanSeeTableRecords([$parent, $child]);
            Livewire::test(ReferenceNodes::class)->assertCanNotSeeTableRecords([$parent, $child]);
        });
    }

    public function test_subject_deletion_refuses_usage_and_archiving_keeps_history(): void
    {
        [$school, $admin] = $this->setting();
        $teacher = $this->account('teacher');
        $this->member($school, $teacher);
        $this->within($school, $admin, function () use ($school, $teacher) {
            Livewire::test(Subjects::class)->callTableAction('create', data: ['code' => 'ROBOT', 'labels' => ['fr' => 'Robotique'], 'sort_order' => 0])
                ->assertNotified(__('school.saved'));
            $unused = DB::table('school.subject')->where('code', 'ROBOT')->value('id');
            Livewire::test(Subjects::class)->callTableAction('delete', $unused)->assertNotified(__('school.saved'));
            $this->assertSame(0, DB::table('school.subject')->where('id', $unused)->count());
            $math = DB::table('school.subject')->where('code', 'MATH')->value('id');
            $this->grant($school, $teacher, 'teacher', 'subject', [$math]);
            Livewire::test(Subjects::class)->callTableAction('delete', $math)->assertNotified(__('errors.subject_in_use'));
            Livewire::test(Subjects::class)->callTableAction('archive', $math, data: ['reason' => 'Matière retirée'])->assertNotified(__('school.saved'));
            Livewire::test(Subjects::class)->filterTable('archive_status', 'archived')->assertCanSeeTableRecords([$math])->assertTableActionHidden('edit', $math);
            $this->assertNotNull(DB::table('school.subject')->where('id', $math)->value('archived_at'));
        });
    }

    public function test_years_and_periods_refuse_overlaps_outside_dates_and_freeze_after_closure(): void
    {
        [$school, $admin] = $this->setting();
        $this->within($school, $admin, function () {
            $dates = ['start_date' => '2026-09-01', 'end_date' => '2027-06-30'];
            Livewire::test(Years::class)->callTableAction('create', data: ['code' => '2026-2027', ...$dates, 'status' => 'current'])->assertNotified(__('school.saved'));
            $year = DB::table('school.academic_year')->where('code', '2026-2027')->value('id');
            Livewire::test(Years::class)->callTableAction('create', data: ['code' => 'OTHER', ...$dates, 'status' => 'current'])->assertNotified(__('errors.duplicate'));
            $data = ['year_id' => $year, 'code' => 'T1', 'labels' => ['fr' => 'Premier trimestre'], 'sort_order' => 0,
                'start_date' => '2026-09-01', 'end_date' => '2026-12-31'];
            Livewire::test(Periods::class)->callTableAction('create', data: $data)->assertNotified(__('school.saved'));
            $first = DB::table('school.period')->where('code', 'T1')->value('id');
            Livewire::test(Periods::class)->callTableAction('create', data: [...$data, 'code' => 'OVERLAP', 'start_date' => '2026-12-31', 'end_date' => '2027-02-28'])
                ->assertNotified(__('errors.overlap'));
            Livewire::test(Periods::class)->callTableAction('create', data: [...$data, 'code' => 'OUTSIDE', 'start_date' => '2026-08-01'])
                ->assertNotified(__('errors.period_outside_year'));
            Livewire::test(Periods::class)->callTableAction('create', data: [...$data, 'code' => 'T2', 'start_date' => '2027-01-01', 'end_date' => '2027-03-31'])
                ->assertNotified(__('school.saved'));
            Livewire::test(Periods::class)->callTableAction('archive', $first, data: ['reason' => 'Période remplacée'])->assertNotified(__('school.saved'));
            Livewire::test(Years::class)->callTableAction('close', $year, data: ['reason' => 'Année terminée'])->assertNotified(__('school.saved'));
            Livewire::test(Years::class)->assertTableActionHidden('edit', $year)->assertTableActionHidden('close', $year);
            Livewire::test(Periods::class)->filterTable('archive_status', 'archived')->assertCanSeeTableRecords([$first])->assertTableActionHidden('edit', $first);
            Livewire::test(Periods::class)->assertTableActionHidden('create');
            $this->assertSame(2, DB::table('school.period')->count());
        });
        $this->getJson('/api/years')->assertJsonCount(1, 'data.0.periods');
        $this->getJson('/api/years?include_archived=1')->assertJsonCount(2, 'data.0.periods');
    }

    public function test_a_stale_node_edit_does_not_overwrite_the_first_change(): void
    {
        [$school, $admin] = $this->setting();
        $this->within($school, $admin, function () {
            $id = DB::table('school.ref_node')->where('code', 'SECONDAIRE')->value('id');
            $first = Livewire::test(ReferenceNodes::class)->mountTableAction('edit', $id);
            $stale = Livewire::test(ReferenceNodes::class)->mountTableAction('edit', $id);
            $first->setTableActionData(['labels' => ['fr' => 'Nouvelle appellation'], 'sort_order' => 1])->callMountedTableAction()->assertNotified(__('school.saved'));
            $stale->setTableActionData(['labels' => ['fr' => 'Écrasement refusé'], 'sort_order' => 2])->callMountedTableAction()->assertNotified(__('errors.stale_version'));
            $this->assertSame('Nouvelle appellation', json_decode(DB::table('school.ref_node')->where('id', $id)->value('labels'), true)['fr']);
        });
    }

    public function test_http_livewire_creation_commits_and_a_removed_manage_permission_blocks_a_mounted_action(): void
    {
        [$school] = $this->setting();
        $manager = $this->account('reference-manager');
        $this->member($school, $manager);
        $role = $this->postJson('/api/roles', ['code' => 'ref_manager', 'labels' => ['fr' => 'Gestion du référentiel'],
            'permissions' => ['referential.node.read', 'referential.node.manage']])->assertCreated()->json('data.id');
        $this->grant($school, $manager, 'ref_manager');
        $this->signIn($manager);
        $snapshot = $this->snapshot('/school/reference-nodes');
        $mounted = $this->update($snapshot, [['mountAction', ['create', [], ['table' => true]]]])->assertOk();
        $result = $this->update($mounted->json('components.0.snapshot'), [['callMountedAction']], [
            'mountedActions.0.data.type' => 'cycle', 'mountedActions.0.data.code' => 'HTTP',
            'mountedActions.0.data.labels.ar' => 'دورة إضافية', 'mountedActions.0.data.sort_order' => 0,
        ])->assertOk();
        $this->assertSame(1, DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)->where('code', 'HTTP')->count());
        $this->assertSame(0, DB::table('school.ref_node')->count());
        $mounted = $this->update($result->json('components.0.snapshot'), [['mountAction', ['create', [], ['table' => true]]]])->assertOk();
        DB::connection('owner')->table('school.role_permission')->where('tenant_id', $school->id)->where('role_id', $role)->where('permission_code', 'referential.node.manage')->delete();
        $this->update($mounted->json('components.0.snapshot'), [['callMountedAction']], [
            'mountedActions.0.data.type' => 'cycle', 'mountedActions.0.data.code' => 'REFUSED',
            'mountedActions.0.data.labels.fr' => 'Permission retirée', 'mountedActions.0.data.sort_order' => 0,
        ])->assertOk();
        $this->assertSame(0, DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)->where('code', 'REFUSED')->count());
    }

    public function test_foreign_record_ids_and_stale_school_tabs_are_refused(): void
    {
        [$other] = $this->setting();
        $foreign = DB::connection('owner')->table('school.ref_node')->where('tenant_id', $other->id)->where('code', 'SECONDAIRE')->first();
        [$school, $admin] = $this->setting();
        $this->member($other, $admin, 'active', ['tenant_admin']);
        $snapshot = $this->snapshot('/school/reference-nodes');
        $mounted = $this->update($snapshot, [['mountAction', ['edit', [], ['table' => true, 'recordKey' => $foreign->id]]]])->assertOk();
        $this->assertEmpty(json_decode($mounted->json('components.0.snapshot'), true)['data']['mountedActions'][0]);
        $this->assertSame($foreign->labels, DB::connection('owner')->table('school.ref_node')->where('id', $foreign->id)->value('labels'));
        $this->putJson('/api/session/school', ['tenant_id' => $other->id])->assertNoContent();
        $this->update($snapshot, [])->assertForbidden();
    }

    public function test_expired_schools_show_reference_history_but_cannot_mount_create_actions(): void
    {
        [$school] = $this->setting();
        DB::connection('console')->select('SELECT platform.set_entitlement(?, ?::date, ?::date, ?, ?, ?, ?)', [$school->id, '2020-01-01', '2020-02-01', 0, 500, 1024, 0]);
        foreach (['reference-types', 'reference-nodes', 'subjects', 'years', 'periods'] as $page) $this->get('/school/'.$page)->assertOk()->assertSee(__('errors.tenant_read_only'));
        $snapshot = $this->snapshot('/school/reference-nodes');
        $mounted = $this->update($snapshot, [['mountAction', ['create', [], ['table' => true]]]])->assertOk();
        $this->assertEmpty(json_decode($mounted->json('components.0.snapshot'), true)['data']['mountedActions'][0]);
    }
}
