<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Staff;
use App\Filament\School\Pages\StaffQualifications;
use App\Support\CurrentSchool;
use App\Support\Formats;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** The staff's records (FR-STF-01) through their real routes and screen, with restricted SQL roles. */
final class StaffTest extends TestCase
{
    /** A school whose plan has the staff module, its pedagogy and HR manager (academic_hr) signed in. */
    private function setting(): array
    {
        $manager = $this->account('staff');
        $school = $this->school('pro', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        return [$school, $manager];
    }

    private function teacher(array $extra = []): array
    {
        return ['last_name_lat' => 'Meziane', 'first_name_lat' => 'Karim', 'staff_type' => 'teacher_internal', ...$extra];
    }

    private function site(object $school): string
    {
        return DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
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

    public function test_a_record_needs_no_account_and_receives_its_number_once(): void
    {
        [$school] = $this->setting();
        $first = $this->postJson('/api/staff', $this->teacher(['hire_date' => '2020-09-01', 'phone' => '0550 12 34 56']))->assertCreated()->json('data');
        $this->assertSame(['P0001', null, null, 'active', $this->site($school)],
            [$first['staff_number'], $first['user_id'], $first['account'], $first['status'], $first['site_id']]);
        // A number of the school's own stays as it is, once.
        $this->postJson('/api/staff', $this->teacher(['staff_number' => 'RH-7', 'first_name_lat' => 'Nadia', 'staff_type' => 'administrative']))
            ->assertCreated()->assertJsonPath('data.staff_number', 'RH-7');
        $this->postJson('/api/staff', $this->teacher(['staff_number' => 'RH-7']))->assertConflict()->assertJsonPath('code', 'duplicate');
        $this->postJson('/api/staff', $this->teacher(['first_name_ar' => 'كريم', 'last_name_ar' => 'مزيان', 'last_name_lat' => null, 'first_name_lat' => null]))
            ->assertCreated()->assertJsonPath('data.staff_number', 'P0002');
        $this->postJson('/api/staff', ['last_name_lat' => 'Seul', 'staff_type' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('last_name_lat');
        foreach (['staff_type' => 'cook', 'user_id' => 1, 'status' => 'left', 'left_on' => '2026-01-01', 'hire_date' => '2026-02-31'] as $field => $value) {
            $this->postJson('/api/staff', $this->teacher([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->putJson('/api/staff/'.$first['id'], ['email' => ' K.Meziane@Ecole.dz ', 'version' => 1])->assertOk()
            ->assertJsonPath('data.email', 'k.meziane@ecole.dz')->assertJsonPath('data.version', 2)->assertJsonPath('data.phone', '0550 12 34 56');
        $this->putJson('/api/staff/'.$first['id'], ['phone' => '0661', 'version' => 1])->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->putJson('/api/staff/'.$first['id'], ['staff_number' => 'X', 'version' => 2])->assertUnprocessable();
        $this->deleteJson('/api/staff/'.$first['id'])->assertStatus(405);
        // Lists by pages, searched by name in either alphabet or number, filtered by function.
        $this->getJson('/api/staff?paginate=0')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/staff?q=MEZIANE')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/staff?q=مزيان')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/staff?q=rh-7')->assertOk()->assertJsonPath('data.0.first_name_lat', 'Nadia');
        $this->getJson('/api/staff?staff_type=administrative')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/staff/'.Str::uuid())->assertNotFound();
        $this->getJson('/api/staff/nothing')->assertNotFound();
    }

    public function test_a_departure_keeps_the_record_with_its_day_and_only_a_member_who_left_is_archived(): void
    {
        [$school] = $this->setting();
        $id = $this->postJson('/api/staff', $this->teacher(['hire_date' => '2020-09-01']))->json('data.id');
        $status = fn (array $data) => $this->postJson("/api/staff/$id/status", ['reason' => 'Démission acceptée', ...$data]);
        $this->postJson("/api/staff/$id/status", ['status' => 'on_leave', 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $status(['status' => 'on_leave', 'version' => 1])->assertOk()->assertJsonPath('data.status', 'on_leave')->assertJsonPath('data.left_on', null);
        $this->postJson("/api/staff/$id/archive", ['reason' => 'Dossier clos', 'version' => 2])->assertConflict()->assertJsonPath('code', 'staff_archive_left');
        $status(['status' => 'left', 'left_on' => now()->addDay()->toDateString(), 'version' => 2])->assertConflict()->assertJsonPath('code', 'left_on_future');
        $status(['status' => 'left', 'left_on' => '2019-06-30', 'version' => 2])->assertConflict()->assertJsonPath('code', 'left_before_hire');
        $status(['status' => 'left', 'left_on' => '2026-01-31', 'version' => 2])->assertOk()->assertJsonPath('data.left_on', '2026-01-31');
        $this->assertSame('Démission acceptée', DB::connection('owner')->table('school.audit_log')->where('tenant_id', $school->id)
            ->where('object_type', 'staff')->where('object_id', $id)->whereRaw("after ->> 'status' = 'left'")->value('reason'));
        $status(['status' => 'on_leave', 'version' => 3])->assertConflict()->assertJsonPath('code', 'status_transition_refused');
        // Still gone: the day they left stays without a new one.
        $status(['status' => 'left', 'version' => 3])->assertOk()->assertJsonPath('data.left_on', '2026-01-31');
        $this->postJson("/api/staff/$id/archive", ['reason' => 'Dossier clos', 'version' => 4])->assertOk()
            ->assertJsonPath('data.archived_at', fn ($value) => $value !== null);
        $this->putJson("/api/staff/$id", ['phone' => '0550', 'version' => 5])->assertConflict()->assertJsonPath('code', 'staff_archived');
        $this->getJson('/api/staff')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/staff?include_archived=1&status=left')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_an_account_is_linked_to_one_record_never_by_its_holder_and_reads_its_own(): void
    {
        [$school, $manager] = $this->setting();
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active');
        $record = $this->postJson('/api/staff', $this->teacher())->json('data.id');
        $other = $this->postJson('/api/staff', $this->teacher(['first_name_lat' => 'Sami']))->json('data.id');
        $this->getJson('/api/staff/accounts?q='.$teacher['email'])->assertOk()->assertJsonPath('data.0.id', $teacher['id']);
        $this->postJson("/api/staff/$record/account", ['user_id' => $teacher['id'], 'version' => 1])->assertOk()
            ->assertJsonPath('data.user_id', $teacher['id'])->assertJsonPath('data.account.email', $teacher['email']);
        $this->getJson('/api/staff/accounts?q='.$teacher['email'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson("/api/staff/$other/account", ['user_id' => $teacher['id'], 'version' => 1])->assertConflict()->assertJsonPath('code', 'staff_account_linked');
        $this->postJson("/api/staff/$other/account", ['user_id' => $manager['id'], 'version' => 1])->assertConflict()->assertJsonPath('code', 'self_account');
        $this->postJson("/api/staff/$other/account", ['user_id' => $this->account('elsewhere')['id'], 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson("/api/staff/$other/account", ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        // A teacher's grant limited to their own record: theirs, nobody else's (FR-IAM-04, P5).
        $this->grant($school, $teacher, 'academic_hr', 'personal');
        $this->signIn($teacher)->assertOk();
        $this->getJson('/api/staff')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $record);
        $this->getJson("/api/staff/$other")->assertNotFound();
        $this->postJson('/api/staff', $this->teacher())->assertForbidden();
        // Unlinked, the record stays; the account reads it no more.
        $this->signIn($manager)->assertOk();
        $this->postJson("/api/staff/$record/account", ['user_id' => null, 'version' => 2])->assertOk()->assertJsonPath('data.account', null);
        $this->signIn($teacher)->assertOk();
        $this->getJson('/api/staff')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_the_school_s_sensitive_fields_of_a_record_are_read_with_its_contracts_only(): void
    {
        [$school] = $this->setting();
        DB::connection('owner')->table('school.custom_field')->insert(['tenant_id' => $school->id, 'entity' => 'staff', 'key' => 'health',
            'field_type' => 'text', 'is_sensitive' => true, 'labels' => '{"fr":"Santé"}']);
        $id = $this->postJson('/api/staff', $this->teacher(['custom' => ['health' => 'Asthme']]))->assertCreated()
            ->assertJsonPath('data.custom.health', 'Asthme')->json('data.id');
        // The accountant reads the record, not what only its human resources read (section 3.3: identity).
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $this->signIn($accountant)->assertOk();
        $this->getJson("/api/staff/$id")->assertOk()->assertJsonPath('data.last_name_lat', 'Meziane')->assertJsonMissingPath('data.custom.health');
        $this->putJson("/api/staff/$id", ['phone' => '0550', 'version' => 1])->assertForbidden();
    }

    public function test_the_page_and_the_api_follow_the_module_and_the_permissions(): void
    {
        [$school] = $this->setting();
        $this->get('/school/staff')->assertOk()->assertSee(__('staff.create'))->assertSee(__('admin.sections.staff'));
        // The school's administrator reads no staff record (section 3.3).
        $admin = $this->account('administrator');
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->signIn($admin);
        $this->get('/school/staff')->assertForbidden();
        $this->getJson('/api/staff')->assertForbidden();
        // Without the module, neither the page nor the API exists, even for the manager.
        $manager = $this->account('essential-hr');
        $other = $this->school('essential', $manager, 'private_school_dz');
        $this->member($other, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $this->get('/school/staff')->assertForbidden();
        $this->getJson('/api/staff')->assertForbidden();
        $this->postJson('/api/staff', $this->teacher())->assertForbidden();
    }

    public function test_a_site_scoped_manager_records_and_follows_the_staff_of_their_site_only(): void
    {
        [$school] = $this->setting();
        $annex = $this->postJson('/api/sites', ['code' => 'ANNEXE', 'labels' => ['fr' => 'Annexe']])->assertCreated()->json('data.id');
        $main = $this->postJson('/api/staff', $this->teacher())->json('data.id');
        $local = $this->account('annex-hr');
        $this->member($school, $local);
        $this->grant($school, $local, 'academic_hr', 'site', [$annex]);
        $this->signIn($local)->assertOk();
        $this->postJson('/api/staff', $this->teacher(['site_id' => $this->site($school)]))->assertForbidden()->assertJsonPath('code', 'permission_denied');
        $theirs = $this->postJson('/api/staff', $this->teacher(['first_name_lat' => 'Annexe']))->assertCreated()->assertJsonPath('data.site_id', $annex)->json('data.id');
        $this->getJson('/api/staff')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $theirs);
        $this->getJson("/api/staff/$main")->assertNotFound();
        $this->putJson("/api/staff/$main", ['phone' => '0550', 'version' => 1])->assertNotFound();
        $this->putJson("/api/staff/$theirs", ['site_id' => $this->site($school), 'version' => 1])->assertForbidden();
        $this->within($school, $local, function () use ($theirs, $main, $annex) {
            Livewire::test(Staff::class)->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$main])
                ->assertTableActionVisible('create')->assertTableActionVisible('edit', $theirs);
            Livewire::test(Staff::class)->callTableAction('create', data: ['last_name_lat' => 'Ecran', 'first_name_lat' => 'Annexe', 'staff_type' => 'administrative'])
                ->assertHasNoTableActionErrors()->assertNotified(__('staff.created', ['number' => 'P0003']));
            $this->assertSame($annex, DB::table('school.staff')->where('last_name_lat', 'Ecran')->value('site_id'));
        });
    }

    private function node(object $school, string $code): string
    {
        return DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)->where('code', $code)->value('id');
    }

    private function subject(object $school): string
    {
        return DB::connection('owner')->table('school.subject')->where('tenant_id', $school->id)->whereNull('archived_at')->orderBy('code')->value('id');
    }

    public function test_a_teacher_qualified_for_a_cycle_teaches_its_levels_until_the_qualification_ends(): void
    {
        [$school] = $this->setting();
        $subject = $this->subject($school);
        $teacher = $this->postJson('/api/staff', $this->teacher())->json('data.id');
        $line = $this->postJson("/api/staff/$teacher/qualifications", ['subject_id' => $subject, 'node_id' => $this->node($school, 'PRIMAIRE')])->assertCreated()
            ->assertJsonPath('data.valid_from', now()->toDateString())->assertJsonPath('data.node.code', 'PRIMAIRE')->json('data');
        // FR-STF-02 AC2: Primaire covers 3AP; not Moyen, nor yesterday.
        $qualified = fn (string $node, array $extra = []) => $this->getJson('/api/staff?'.http_build_query(['subject_id' => $subject, 'node_id' => $this->node($school, $node), ...$extra]));
        $qualified('3AP')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $teacher);
        $qualified('MOYEN')->assertOk()->assertJsonPath('meta.total', 0);
        $qualified('3AP', ['on' => now()->subDay()->toDateString()])->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/staff?subject_id='.$subject)->assertUnprocessable()->assertJsonValidationErrors('node_id');
        $this->postJson("/api/staff/$teacher/qualifications", ['subject_id' => $subject, 'node_id' => $this->node($school, 'PRIMAIRE')])
            ->assertConflict()->assertJsonPath('code', 'qualification_overlap');
        $this->postJson("/api/staff/$teacher/qualifications", ['subject_id' => (string) Str::uuid(), 'node_id' => $this->node($school, '3AP')])->assertNotFound();
        $clerk = $this->postJson('/api/staff', $this->teacher(['staff_type' => 'administrative', 'first_name_lat' => 'Amel']))->json('data.id');
        $this->postJson("/api/staff/$clerk/qualifications", ['subject_id' => $subject, 'node_id' => $this->node($school, '3AP')])
            ->assertConflict()->assertJsonPath('code', 'qualification_not_teacher');
        // Ended: final, and history once its last day has passed.
        $this->postJson("/api/qualifications/{$line['id']}/end", ['valid_to' => now()->toDateString(), 'version' => 1])->assertOk()
            ->assertJsonPath('data.valid_to', now()->toDateString());
        $this->postJson("/api/qualifications/{$line['id']}/end", ['valid_to' => now()->addMonth()->toDateString(), 'version' => 2])
            ->assertConflict()->assertJsonPath('code', 'qualification_ended');
        $past = $this->postJson("/api/staff/$teacher/qualifications", ['subject_id' => $subject, 'node_id' => $this->node($school, 'MOYEN'),
            'valid_from' => now()->subYear()->toDateString(), 'valid_to' => now()->subDay()->toDateString()])->assertCreated()->json('data.id');
        $this->getJson("/api/staff/$teacher/qualifications")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/staff/$teacher/qualifications?include_ended=1")->assertOk()->assertJsonPath('meta.total', 2);
        $this->deleteJson("/api/qualifications/$past", ['version' => 2])->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->deleteJson("/api/qualifications/$past", ['version' => 1])->assertNoContent();
        $this->assertSame(1, DB::connection('owner')->table('school.audit_log')->where('object_type', 'qualification')->where('object_id', $past)
            ->where('action', 'DELETE')->count());
        // Read with the record; given and ended by human resources only.
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $this->signIn($accountant)->assertOk();
        $this->getJson("/api/staff/$teacher/qualifications")->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson("/api/staff/$teacher/qualifications", ['subject_id' => $subject, 'node_id' => $this->node($school, '1AS')])->assertForbidden();
        $this->deleteJson("/api/qualifications/{$line['id']}", ['version' => 2])->assertForbidden();
    }

    public function test_the_qualifications_page_gives_ends_and_removes_them_and_the_list_finds_who_is_qualified(): void
    {
        [$school, $manager] = $this->setting();
        $subject = $this->subject($school);
        [$primary, $third] = [$this->node($school, 'PRIMAIRE'), $this->node($school, '3AP')];
        $teacher = $this->postJson('/api/staff', $this->teacher())->json('data.id');
        $clerk = $this->postJson('/api/staff', $this->teacher(['staff_type' => 'administrative', 'first_name_lat' => 'Amel']))->json('data.id');
        $this->get('/school/staff-qualifications?staff='.$teacher)->assertOk()->assertSee(__('staff.add_qualification'));
        $this->get('/school/staff-qualifications?staff='.Str::uuid())->assertNotFound();
        $this->within($school, $manager, function () use ($teacher, $clerk, $subject, $primary, $third) {
            Livewire::test(Staff::class)->assertTableActionVisible('qualifications', $teacher)->assertTableActionHidden('qualifications', $clerk);
            Livewire::withQueryParams(['staff' => $teacher])->test(StaffQualifications::class)
                ->callTableAction('add', data: ['subject_id' => $subject, 'node_id' => $primary])->assertNotified(__('school.saved'));
            $line = DB::table('school.qualification')->where('staff_id', $teacher)->value('id');
            Livewire::test(Staff::class)->filterTable('qualified', ['subject_id' => $subject, 'node_id' => $third])
                ->assertCanSeeTableRecords([$teacher])->assertCanNotSeeTableRecords([$clerk]);
            Livewire::withQueryParams(['staff' => $teacher])->test(StaffQualifications::class)->assertCanSeeTableRecords([$line])
                ->callTableAction('end', $line, data: ['valid_to' => now()->toDateString()])->assertNotified(__('school.saved'))
                ->assertTableActionHidden('end', $line)->callTableAction('remove', $line)->assertNotified(__('school.saved'));
            $this->assertSame(0, DB::table('school.qualification')->where('staff_id', $teacher)->count());
            // An administrative member receives none.
            Livewire::withQueryParams(['staff' => $clerk])->test(StaffQualifications::class)->assertTableActionHidden('add');
        });
    }

    public function test_the_page_records_changes_links_and_archives_a_member(): void
    {
        [$school, $manager] = $this->setting();
        $teacher = $this->account('teacher-account');
        $this->member($school, $teacher, 'active');
        $this->within($school, $manager, function () use ($teacher) {
            Livewire::test(Staff::class)->callTableAction('create', data: ['last_name_lat' => 'Meziane', 'first_name_lat' => 'Karim',
                'last_name_ar' => 'مزيان', 'first_name_ar' => 'كريم', 'staff_type' => 'teacher_internal', 'hire_date' => '2020-09-01', 'phone' => '0550 12 34 56'])
                ->assertHasNoTableActionErrors()->assertNotified(__('staff.created', ['number' => 'P0001']));
            $id = DB::table('school.staff')->where('staff_number', 'P0001')->value('id');
            Livewire::test(Staff::class)->assertCanSeeTableRecords([$id])->assertSee('Meziane Karim')->assertSee(__('staff.no_account'))
                ->callTableAction('edit', $id, data: ['staff_type' => 'teacher_external', 'email' => 'karim@ecole.dz'])->assertNotified(__('school.saved'));
            $this->assertSame(['teacher_external', 'karim@ecole.dz'], array_values((array) DB::table('school.staff')->where('id', $id)->first(['staff_type', 'email'])));
            Livewire::test(Staff::class)->callTableAction('account', $id, data: ['user_id' => $teacher['id']])->assertNotified(__('school.saved'));
            $this->assertSame($teacher['id'], (int) DB::table('school.staff')->where('id', $id)->value('user_id'));
            Livewire::test(Staff::class)->assertTableActionVisible('unlink', $id)->callTableAction('unlink', $id)->assertNotified(__('school.saved'));
            $this->assertNull(DB::table('school.staff')->where('id', $id)->value('user_id'));
            Livewire::test(Staff::class)->assertTableActionHidden('archive', $id)
                ->callTableAction('status', $id, data: ['status' => 'left', 'left_on' => '2026-01-31', 'reason' => 'Démission'])->assertNotified(__('school.saved'));
            Livewire::test(Staff::class)->assertSee(__('staff.left_since', ['date' => Formats::date('2026-01-31')]))
                ->callTableAction('archive', $id, data: ['reason' => 'Dossier clos'])->assertNotified(__('school.saved'));
            Livewire::test(Staff::class)->assertCanNotSeeTableRecords([$id])->filterTable('archived', true)->assertCanSeeTableRecords([$id])
                ->assertTableActionHidden('edit', $id)->assertTableActionHidden('status', $id);
        });
    }
}
