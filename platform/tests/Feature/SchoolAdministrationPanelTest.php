<?php

namespace Tests\Feature;

use App\Filament\School\Pages\CustomFields;
use App\Filament\School\Pages\Journal;
use App\Filament\School\Pages\Settings;
use App\Filament\School\Pages\SupportAccess;
use App\Models\User;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** W1.7: the school's administration screens (FR-REF-04, FR-REF-06, FR-PLT-04, FR-AUD-01), under school_runtime. */
final class SchoolAdministrationPanelTest extends TestCase
{
    private function setting(): array
    {
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin, 'private_school_dz');
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

    private function value(object $school, string $key): mixed
    {
        $value = DB::connection('owner')->table('school.setting')->where('tenant_id', $school->id)->where('key', $key)->value('value');
        return $value === null ? null : json_decode($value, true);
    }

    public function test_settings_change_within_their_bounds_and_only_for_whoever_manages_them(): void
    {
        [$school, $admin] = $this->setting();
        $this->get('/school/settings')->assertOk()->assertSee('Déconnexion après inactivité (minutes)')->assertSee('dimanche, lundi, mardi, mercredi, jeudi');
        $this->within($school, $admin, function () {
            // A refused form stays open: the next change starts from a fresh page.
            Livewire::test(Settings::class)->callTableAction('edit', 'session.idle_minutes', data: ['value' => 200])->assertHasTableActionErrors(['value']);
            Livewire::test(Settings::class)
                ->callTableAction('edit', 'session.idle_minutes', data: ['value' => 45])->assertNotified(__('school.saved'))
                ->callTableAction('edit', 'calendar.open_days', data: ['value' => ['0', '1', '2', '3', '6']])->assertNotified(__('school.saved'))
                ->callTableAction('edit', 'locale.arabic_digits', data: ['value' => true])->assertNotified(__('school.saved'))
                ->callTableAction('edit', 'files.types', data: ['value' => ['pdf', 'png']])->assertNotified(__('school.saved'))
                ->callTableAction('edit', 'identity.phone', data: ['value' => '+213 21 00 00 00'])->assertNotified(__('school.saved'));
        });
        $this->assertSame(45, $this->value($school, 'session.idle_minutes'));
        $this->assertSame([0, 1, 2, 3, 6], $this->value($school, 'calendar.open_days'));
        $this->assertTrue($this->value($school, 'locale.arabic_digits'));
        $this->assertSame(['pdf', 'png'], $this->value($school, 'files.types'));
        $this->assertSame('+213 21 00 00 00', $this->value($school, 'identity.phone'));

        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher);
        $this->get('/school/settings')->assertForbidden();
    }

    public function test_custom_fields_keep_their_key_type_and_list_values(): void
    {
        [$school, $admin] = $this->setting();
        $option = fn (string $code, string $label) => ['code' => $code, 'labels' => ['fr' => $label, 'ar' => null]];
        $this->within($school, $admin, function () use ($option) {
            Livewire::test(CustomFields::class)->callTableAction('create', data: ['entity' => 'student', 'key' => 'blood_group', 'field_type' => 'select',
                'labels' => ['fr' => 'Groupe sanguin', 'ar' => 'فصيلة الدم'], 'options' => [$option('A_POS', 'A+')], 'required' => false,
                'is_sensitive' => true, 'sort_order' => 1])->assertNotified(__('school.saved'));
        });
        $field = DB::connection('owner')->table('school.custom_field')->where('tenant_id', $school->id)->where('key', 'blood_group')->first();
        $this->assertSame([['code' => 'A_POS', 'labels' => ['fr' => 'A+']]], json_decode($field->options, true));
        $this->assertTrue($field->is_sensitive);

        $this->within($school, $admin, function () use ($field, $option) {
            $page = fn () => Livewire::test(CustomFields::class);
            // Values are added; one removed would orphan the records that hold it.
            $page()->callTableAction('edit', $field->id, data: ['options' => [$option('A_POS', 'A+'), $option('O_NEG', 'O-')], 'version' => 1])
                ->assertNotified(__('school.saved'));
            $page()->callTableAction('edit', $field->id, data: ['options' => [$option('O_NEG', 'O-')], 'version' => 2])
                ->assertNotified(__('errors.option_removed'));
            $page()->callTableAction('edit', $field->id, data: ['labels' => ['fr' => 'Groupe', 'ar' => null], 'version' => 1])
                ->assertNotified(__('errors.stale_version'));
            $page()->callTableAction('archive', $field->id, data: ['reason' => 'Plus collecté'])->assertNotified(__('school.saved'));
        });
        $field = DB::connection('owner')->table('school.custom_field')->where('id', $field->id)->first();
        $this->assertSame(['A_POS', 'O_NEG'], array_column(json_decode($field->options, true), 'code'));
        $this->assertNotNull($field->archived_at);
        $this->assertSame('Plus collecté', DB::connection('owner')->table('school.audit_log')
            ->where('object_id', $field->id)->whereNotNull('reason')->value('reason'));
    }

    public function test_support_consents_are_given_withdrawn_and_listed_with_their_consultations(): void
    {
        [$school, $admin] = $this->setting();
        $this->get('/school/support-access')->assertOk()->assertSee(__('admin.support_help'));
        $this->within($school, $admin, function () {
            Livewire::test(SupportAccess::class)->callTableAction('grant', data: ['hours' => 73, 'reason' => 'Trop long'])->assertHasTableActionErrors(['hours']);
            Livewire::test(SupportAccess::class)
                ->callTableAction('grant', data: ['hours' => 24, 'include_sensitive' => false, 'reason' => 'Ticket 3001 : import'])->assertNotified(__('school.saved'));
        });
        $grant = DB::connection('owner')->table('school.support_grant')->where('tenant_id', $school->id)->first();
        $this->assertFalse($grant->include_sensitive);
        $this->within($school, $admin, function () use ($grant) {
            Livewire::test(SupportAccess::class)->assertTableActionVisible('revoke', $grant->id)
                ->assertTableActionHasUrl('consultations', Journal::getUrl(['tableFilters' => ['object' => ['object_id' => $grant->id]]]), $grant->id)
                ->callTableAction('revoke', $grant->id)->assertNotified(__('school.saved'));
            Livewire::test(SupportAccess::class)->assertTableActionHidden('revoke', $grant->id);
        });
        $this->assertNotNull(DB::connection('owner')->table('school.support_grant')->where('id', $grant->id)->value('revoked_at'));

        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher);
        $this->get('/school/support-access')->assertForbidden();
    }

    public function test_an_operator_under_consent_reads_the_journal_and_the_consents_but_changes_nothing(): void
    {
        [$school, $admin] = $this->setting();
        $operator = $this->account('operator');
        DB::connection('owner')->insert('INSERT INTO platform.operator (user_id) VALUES (?)', [$operator['id']]);
        $grant = $this->postJson('/api/support-grants', ['hours' => 2, 'reason' => 'Assistance paramètres'])->assertCreated()->json('data.id');
        $this->deleteJson('/api/session');
        $this->actingAs(User::findOrFail($operator['id']));
        $this->withSession(['auth.started_at' => time(), 'auth.seen_at' => time()]);
        $this->postJson('/api/support/session', ['tenant_id' => $school->id, 'grant_id' => $grant])->assertNoContent();
        $this->get('/school/support-access')->assertOk()->assertSee('Assistance paramètres')->assertDontSee(__('admin.grant'))->assertDontSee(__('admin.revoke'));
        $this->get('/school/journal')->assertOk()->assertSee(__('admin.actions.SUPPORT_READ'));
        $this->get('/school/settings')->assertForbidden();
    }

    public function test_the_journal_lists_filters_and_details_the_schools_changes(): void
    {
        [$school, $admin] = $this->setting();
        $this->within($school, $admin, fn () => Livewire::test(Settings::class)
            ->callTableAction('edit', 'session.idle_minutes', data: ['value' => 60])->assertNotified(__('school.saved')));
        $entry = DB::connection('owner')->table('school.audit_log')->where('tenant_id', $school->id)->where('object_type', 'setting')->first();
        $this->get('/school/journal')->assertOk()->assertSee(__('admin.objects.setting'))->assertSee(__('admin.actions.INSERT'));
        $this->within($school, $admin, function () use ($entry) {
            // Numbered pages without a total: the records are read from the page itself.
            $shown = fn ($page) => collect($page->instance()->getTableRecords()->items())->keys()->all();
            $page = Livewire::test(Journal::class)->filterTable('object_type', 'setting');
            $this->assertContains($entry->id, $shown($page));
            $this->assertNotContains($entry->id, $shown($page->filterTable('object_type', 'subject')));
            $this->assertSame([$entry->id], $shown($page->resetTableFilters()->filterTable('object', ['object_id' => $entry->object_id])));
            $page->mountTableAction('details', $entry->id)->assertMountedActionModalSee($entry->object_id);
        });
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher);
        $this->get('/school/journal')->assertForbidden();
    }

    public function test_the_menu_groups_the_screens_and_the_catalogs_speak_arabic(): void
    {
        [$school, $admin] = $this->setting();
        $this->get('/school/home')->assertOk()->assertSee(__('admin.sections.people'))->assertSee(__('admin.sections.referential'))
            ->assertSee(__('admin.sections.administration'));
        $this->putJson('/api/account/locale', ['locale' => 'ar'])->assertOk();
        // BR-18: the platform's catalogs carry their Arabic labels to the school's screens.
        $this->get('/school/roles')->assertOk()->assertSee('مسؤول المؤسسة');
        $this->get('/school/settings')->assertOk()->assertSee('قطع الاتصال بعد الخمول (بالدقائق)')->assertSee('الأحد');
        $this->assertSame([], DB::connection('owner')->select("SELECT code FROM school.role WHERE tenant_id = ? AND NOT jsonb_exists(labels, 'ar')", [$school->id]));
    }
}
