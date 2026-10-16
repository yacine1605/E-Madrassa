<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The staff's retention (FR-STF-01, NFR-DATA-03, SQL 046): a member of staff who left, archived for
 * longer than the school's duration, keeps nothing personal; their number and their history stay.
 */
final class StaffRetentionTest extends TestCase
{
    public function test_a_member_archived_long_enough_is_anonymised_by_the_retention_run(): void
    {
        $manager = $this->account('staff-retention');
        $school = $this->school('pro', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $member = $this->postJson('/api/staff', ['last_name_lat' => 'Meziane', 'first_name_lat' => 'Karim', 'staff_type' => 'teacher_internal',
            'phone' => '0550 98 76 54', 'email' => 'k.meziane@example.test', 'hire_date' => '2015-09-01'])->assertCreated()->json('data');
        $this->postJson("/api/staff/{$member['id']}/contracts", ['contract_type' => 'permanent', 'reference' => 'CDI-2015-02', 'valid_from' => '2015-09-01',
            'weekly_hours' => '18', 'observation' => 'Ancien enseignant de Blida'])->assertCreated();
        $member = $this->postJson("/api/staff/{$member['id']}/status", ['status' => 'left', 'reason' => 'Départ à la retraite', 'version' => $member['version']])
            ->assertOk()->json('data');
        $member = $this->postJson("/api/staff/{$member['id']}/archive", ['reason' => 'Dossier clos', 'version' => $member['version']])->assertOk()->json('data');
        $this->getJson('/api/retention')->assertOk()->assertJsonPath('data.due.staff', 0)->assertJsonPath('data.durations.staff_years', 10);

        $owner = DB::connection('owner');
        $owner->unprepared('ALTER TABLE school.staff DISABLE TRIGGER USER');
        $owner->table('school.staff')->where('id', $member['id'])->update(['archived_at' => now()->subYears(11)]);
        $owner->unprepared('ALTER TABLE school.staff ENABLE TRIGGER USER');
        $this->getJson('/api/retention')->assertOk()->assertJsonPath('data.due.staff', 1);
        $this->artisan('platform:retention')->assertSuccessful()->expectsOutputToContain('1 members of staff anonymised');
        $this->getJson("/api/staff/{$member['id']}")->assertOk()->assertJsonPath('data.last_name_lat', 'ANONYME')
            ->assertJsonPath('data.first_name_lat', $member['staff_number'])->assertJsonPath('data.phone', null)->assertJsonPath('data.email', null)
            ->assertJsonPath('data.staff_number', $member['staff_number'])->assertJsonPath('data.hire_date', '2015-09-01');
        $this->getJson("/api/staff/{$member['id']}/contracts")->assertOk()->assertJsonPath('data.0.reference', null)->assertJsonPath('data.0.observation', null)
            ->assertJsonPath('data.0.weekly_hours', '18.00');
        $this->artisan('platform:retention')->assertSuccessful()->doesntExpectOutputToContain('members of staff anonymised');
    }
}
