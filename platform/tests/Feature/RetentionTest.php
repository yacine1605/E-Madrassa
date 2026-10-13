<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Settings;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** NFR-DATA-03, FR-ELV-03: the school's retention policy per category, shown, then applied by the daily run (SQL 030). */
final class RetentionTest extends TestCase
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

    /** Years of school life, as they would have left the record: written around the rules, by the owner. */
    private function backdate(string $table, string $id, array $values): void
    {
        $owner = DB::connection('owner');
        $owner->unprepared("ALTER TABLE school.$table DISABLE TRIGGER USER");
        $owner->table("school.$table")->where('id', $id)->update($values);
        $owner->unprepared("ALTER TABLE school.$table ENABLE TRIGGER USER");
    }

    public function test_the_policy_shows_what_is_due_and_the_daily_run_archives_then_anonymises_a_pupil_and_their_guardian(): void
    {
        $admin = $this->account('retention');
        $school = $this->school('essential', $admin, 'private_school_dz');
        $this->member($school, $admin, 'active', ['tenant_admin', 'academic_hr']);
        $this->signIn($admin)->assertOk();
        $this->getJson('/api/retention')->assertOk()->assertJsonPath('data.durations.former_archive_months', 12)
            ->assertJsonPath('data.durations.minor_years', 10)->assertJsonPath('data.durations.finance_years', 10)->assertJsonPath('data.due.archive', 0);
        // Never a journal kept less than five years, nor finance less than ten.
        $this->putJson('/api/settings/retention.journal_years', ['value' => 2])->assertConflict()->assertJsonPath('code', 'setting_invalid');
        $this->putJson('/api/settings/retention.pupil_years', ['value' => 5])->assertOk();
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Ancien', 'first_name_lat' => 'Eleve', 'birth_date' => '1999-05-05',
            'phone' => '0550 00 11 22'])->assertCreated()->json('data');
        $guardian = $this->postJson('/api/guardians', ['last_name_lat' => 'Ancien', 'first_name_lat' => 'Pere', 'phone' => '0661 00 11 22'])->json('data');
        $this->postJson('/api/students/'.$pupil['id'].'/guardians', ['guardian_id' => $guardian['id'], 'relationship' => 'father'])->assertCreated();
        $this->postJson('/api/students/'.$pupil['id'].'/status', ['status' => 'former', 'reason' => 'Fin de parcours', 'version' => 1])->assertOk()
            ->assertJsonPath('data.left_on', now()->toDateString());
        // FR-ELV-03: archived a year after leaving, by the next night's run.
        $this->backdate('student', $pupil['id'], ['left_on' => now()->subYears(2)->toDateString()]);
        $this->getJson('/api/retention')->assertJsonPath('data.due.archive', 1)->assertJsonPath('data.due.pupil', 0);
        $this->artisan('platform:retention')->assertSuccessful()->expectsOutputToContain('1 archived');
        $this->assertNotNull($this->getJson('/api/students/'.$pupil['id'])->json('data.archived_at'));
        // Anonymised five years after its archiving (the school's duration), their guardian with them.
        $this->backdate('student', $pupil['id'], ['archived_at' => now()->subYears(6)]);
        $this->within($school, $admin, fn () => Livewire::test(Settings::class)->mountTableAction('retention')
            ->assertMountedActionModalSee(__('admin.retention_due.pupil')));
        $this->getJson('/api/retention')->assertJsonPath('data.due.pupil', 1);
        $this->artisan('platform:retention')->assertSuccessful()->expectsOutputToContain('1 pupils and 1 guardians anonymised');
        $this->getJson('/api/students/'.$pupil['id'])->assertOk()->assertJsonPath('data.last_name_lat', 'ANONYME')->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.birth_date', '1999-01-01');
        $this->assertNotNull($this->getJson('/api/students/'.$pupil['id'])->json('data.anonymised_at'));
        $this->getJson('/api/guardians/'.$guardian['id'])->assertOk()->assertJsonPath('data.last_name_lat', 'ANONYME')->assertJsonPath('data.phone', null);
        $summary = DB::connection('owner')->table('school.audit_log')->where('tenant_id', $school->id)->where('action', 'RETENTION')->orderByDesc('occurred_at')->first();
        $this->assertEquals(['archived' => 0, 'anonymised' => 1, 'guardians' => 1, 'journal' => 0, 'kept' => 0], json_decode($summary->after, true));
        // The preview is the settings' manager's: a secretary has none.
        $secretary = $this->account('front-desk');
        $this->member($school, $secretary, 'active', ['front_office']);
        $this->signIn($secretary)->assertOk();
        $this->getJson('/api/retention')->assertForbidden();
    }
}
