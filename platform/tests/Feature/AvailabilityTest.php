<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Staff;
use App\Filament\School\Pages\StaffAvailability;
use App\Filament\School\Pages\StaffUnavailability;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A teacher's availability (FR-STF-03, SQL 034) through its real routes and screens: weekly slots
 * from a day on with their bounds, days off that prevail, and the approval a school may ask for.
 */
final class AvailabilityTest extends TestCase
{
    /** A school whose plan has the staff module, its administrator also its pedagogy and HR manager (academic_hr), signed in. */
    private function setting(): array
    {
        $manager = $this->account('availability');
        $school = $this->school('pro', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        return [$school, $manager];
    }

    private function teacher(array $extra = []): array
    {
        return ['last_name_lat' => 'Meziane', 'first_name_lat' => 'Karim', 'staff_type' => 'teacher_internal', ...$extra];
    }

    private function slot(int $weekday, string $from, string $until): array
    {
        return ['weekday' => $weekday, 'start_time' => $from, 'end_time' => $until];
    }

    /** The next Sunday, today if it is one: a day of the slots above. */
    private function sunday(int $after = 0): string
    {
        $day = now()->dayOfWeek === Carbon::SUNDAY ? now() : now()->next(Carbon::SUNDAY);
        return $day->addDays($after)->toDateString();
    }

    /** The teacher's own account, linked to their record, with the teacher's role on their own data. */
    private function teacherAccount(object $school, array $record): array
    {
        $account = $this->account('teacher');
        $this->member($school, $account, 'active');
        $this->grant($school, $account, 'teacher', 'personal');
        $this->postJson("/api/staff/{$record['id']}/account", ['user_id' => $account['id'], 'version' => $record['version']])->assertOk();
        return $account;
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

    public function test_a_week_is_in_force_from_its_first_day_until_the_next_and_a_day_off_prevails(): void
    {
        [$school] = $this->setting();
        $teacher = $this->postJson('/api/staff', $this->teacher())->json('data.id');
        $week = $this->postJson("/api/staff/$teacher/availabilities", ['slots' => [$this->slot(2, '14:00', '17:00'), $this->slot(0, '08:00', '12:00')],
            'min_weekly_hours' => 4, 'max_daily_hours' => '4.5', 'max_weekly_hours' => 10])->assertCreated()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.state', 'in_force')->assertJsonPath('data.valid_from', now()->toDateString())
            ->assertJsonPath('data.slots', [$this->slot(0, '08:00', '12:00'), $this->slot(2, '14:00', '17:00')])
            ->assertJsonPath('data.weekly_hours', '7.00')->assertJsonPath('data.max_daily_hours', '4.50')->assertJsonPath('data.declared_by_name', 'availability')
            ->json('data');
        // What a week holds is checked before anything is written, or by the database.
        $declare = fn (array $body) => $this->postJson("/api/staff/$teacher/availabilities", $body);
        foreach ([[[], 'slots'], [['slots' => [$this->slot(7, '08:00', '09:00')]], 'slots.0.weekday'],
            [['slots' => [$this->slot(1, '8:00', '09:00')]], 'slots.0.start_time'], [['slots' => [['weekday' => 1, 'start_time' => '08:00']]], 'slots.0.end_time'],
            [['slots' => [], 'max_daily_hours' => 8, 'max_weekly_hours' => 6], 'max_daily_hours'], [['slots' => [], 'max_daily_hours' => '1.255'], 'max_daily_hours'],
            [['slots' => [], 'status' => 'approved'], 'status']] as [$body, $field]) {
            $declare(['valid_from' => $this->sunday(14), ...$body])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach ([[['slots' => [$this->slot(1, '08:00', '10:00'), $this->slot(1, '09:00', '11:00')]], 'availability_slots_overlap'],
            [['slots' => [$this->slot(1, '10:00', '09:00')]], 'availability_slots_invalid'],
            [['slots' => [$this->slot(1, '08:00', '10:00')], 'min_weekly_hours' => 3], 'availability_below_minimum'],
            [['valid_from' => now()->subDay()->toDateString(), 'slots' => []], 'availability_past'],
            [['valid_from' => now()->toDateString(), 'slots' => []], 'availability_same_day']] as [$body, $code]) {
            $declare(['valid_from' => $this->sunday(14), ...$body])->assertConflict()->assertJsonPath('code', $code);
        }
        // The next week: to come, then in force from its first day; the first one holds until then, and never changes.
        $later = $declare(['valid_from' => $this->sunday(7), 'slots' => []])->assertCreated()->assertJsonPath('data.state', 'upcoming')->json('data');
        $this->getJson("/api/staff/$teacher/availabilities")->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $later['id'])->assertJsonPath('data.1.state', 'in_force');
        $this->deleteJson("/api/availabilities/{$week['id']}", ['version' => 1])->assertConflict()->assertJsonPath('code', 'availability_decided');
        $this->postJson("/api/availabilities/{$week['id']}/reject", ['version' => 1, 'decision_reason' => 'Trop tard'])
            ->assertConflict()->assertJsonPath('code', 'availability_decided');
        $clerk = $this->postJson('/api/staff', $this->teacher(['staff_type' => 'administrative', 'first_name_lat' => 'Amel']))->json('data.id');
        $this->postJson("/api/staff/$clerk/availabilities", ['slots' => []])->assertConflict()->assertJsonPath('code', 'availability_not_teacher');

        // A day off prevails; it is cancelled until its last day, never withdrawn once in force.
        $dayOff = fn (array $body) => $this->postJson("/api/staff/$teacher/unavailabilities", ['date_from' => $this->sunday(), 'date_to' => $this->sunday(),
            'reason' => 'Formation des enseignants', ...$body]);
        $off = $dayOff([])->assertCreated()->assertJsonPath('data.status', 'approved')->json('data');
        $dayOff(['date_to' => $this->sunday(1)])->assertConflict()->assertJsonPath('code', 'unavailability_overlap');
        $dayOff(['date_from' => $this->sunday(3), 'date_to' => $this->sunday(2)])->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $dayOff(['date_from' => now()->subDay()->toDateString()])->assertConflict()->assertJsonPath('code', 'availability_past');
        $dayOff(['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->deleteJson("/api/unavailabilities/{$off['id']}", ['version' => 1])->assertConflict()->assertJsonPath('code', 'availability_decided');
        $this->postJson("/api/unavailabilities/{$off['id']}/cancel", ['version' => 1])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson("/api/staff/$teacher/unavailabilities")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'cancelled');
        $this->getJson("/api/staff/$teacher/unavailabilities?status=approved")->assertOk()->assertJsonPath('meta.total', 0);

        // Read with the record; declared by whoever manages the availability.
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $this->signIn($accountant)->assertOk();
        $this->getJson("/api/staff/$teacher/availabilities")->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/staff/$teacher/unavailabilities?include_past=1")->assertOk()->assertJsonPath('meta.total', 1);
        $declare(['valid_from' => $this->sunday(21), 'slots' => []])->assertForbidden();
        $this->postJson("/api/availabilities/{$later['id']}/approve", ['version' => 1])->assertForbidden();
    }

    public function test_when_the_school_asks_for_it_a_change_waits_for_an_approver_who_is_not_its_teacher(): void
    {
        [$school, $manager] = $this->setting();
        $this->putJson('/api/settings/staff.availability.require_approval', ['value' => true])->assertOk();
        $record = $this->postJson('/api/staff', $this->teacher())->json('data');
        $colleague = $this->postJson('/api/staff', $this->teacher(['first_name_lat' => 'Sami']))->json('data.id');
        // The manager's change on a teacher's record is in force at once.
        $jury = $this->postJson("/api/staff/{$record['id']}/unavailabilities", ['date_from' => $this->sunday(), 'date_to' => $this->sunday(),
            'reason' => 'Jury d’examen'])->assertCreated()->assertJsonPath('data.status', 'approved')->json('data');
        $account = $this->teacherAccount($school, $record);

        // The teacher declares on their own record only; their changes wait.
        $this->signIn($account)->assertOk();
        $week = $this->postJson("/api/staff/{$record['id']}/availabilities", ['slots' => [$this->slot(0, '08:00', '12:00')]])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.state', 'pending')->assertJsonPath('data.decided_by', null)->json('data');
        $other = $this->postJson("/api/staff/{$record['id']}/availabilities", ['valid_from' => $this->sunday(7), 'slots' => []])->assertCreated()->json('data');
        $visit = $this->postJson("/api/staff/{$record['id']}/unavailabilities", ['date_from' => $this->sunday(3), 'date_to' => $this->sunday(3),
            'reason' => 'Rendez-vous'])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->postJson("/api/availabilities/{$week['id']}/approve", ['version' => 1])->assertForbidden();
        $this->postJson("/api/unavailabilities/{$jury['id']}/cancel", ['version' => 1])->assertConflict()->assertJsonPath('code', 'approval_required');
        $this->deleteJson("/api/unavailabilities/{$visit['id']}", ['version' => 1])->assertNoContent();
        $this->postJson("/api/staff/$colleague/availabilities", ['slots' => []])->assertNotFound();
        $this->getJson("/api/staff/$colleague/availabilities")->assertNotFound();

        // The manager finds whose availability waits, and decides.
        $this->signIn($manager)->assertOk();
        $this->getJson('/api/staff?availability_pending=1')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $record['id'])->assertJsonPath('data.0.availability_pending', true);
        $this->postJson("/api/availabilities/{$other['id']}/reject", ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('decision_reason');
        $this->postJson("/api/availabilities/{$other['id']}/reject", ['version' => 1, 'decision_reason' => 'Créneaux à revoir'])->assertOk()
            ->assertJsonPath('data.state', 'rejected')->assertJsonPath('data.decision_reason', 'Créneaux à revoir');
        $this->postJson("/api/availabilities/{$week['id']}/approve", ['version' => 2])->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->postJson("/api/availabilities/{$week['id']}/approve", ['version' => 1])->assertOk()->assertJsonPath('data.state', 'in_force')
            ->assertJsonPath('data.decided_by', $manager['id'])->assertJsonPath('data.decided_by_name', 'availability');
        $this->getJson('/api/staff?availability_pending=1')->assertOk()->assertJsonPath('meta.total', 0);

        // An approver's own record: their change waits for another approver.
        $hr = $this->account('hr');
        $this->member($school, $hr, 'active', ['academic_hr']);
        $own = $this->postJson('/api/staff', $this->teacher(['first_name_lat' => 'Nadia']))->json('data');
        $this->postJson("/api/staff/{$own['id']}/account", ['user_id' => $hr['id'], 'version' => 1])->assertOk();
        $this->signIn($hr)->assertOk();
        $declared = $this->postJson("/api/staff/{$own['id']}/availabilities", ['slots' => []])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->postJson("/api/availabilities/{$declared['id']}/approve", ['version' => 1])->assertConflict()->assertJsonPath('code', 'self_approval');
        $this->postJson("/api/staff/$colleague/availabilities", ['slots' => []])->assertCreated()->assertJsonPath('data.status', 'approved');
        $this->signIn($manager)->assertOk();
        $this->postJson("/api/availabilities/{$declared['id']}/approve", ['version' => 1])->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_the_pages_declare_decide_cancel_and_withdraw_and_the_list_shows_who_waits(): void
    {
        [$school, $manager] = $this->setting();
        $record = $this->postJson('/api/staff', $this->teacher())->json('data');
        $clerk = $this->postJson('/api/staff', $this->teacher(['staff_type' => 'administrative', 'first_name_lat' => 'Amel']))->json('data.id');
        $this->get('/school/staff-availability?staff='.$record['id'])->assertOk()->assertSee(__('staff.declare_availability'));
        $this->get('/school/staff-unavailability?staff='.$record['id'])->assertOk()->assertSee(__('staff.declare_unavailability'));
        $this->within($school, $manager, function () use ($record, $clerk) {
            Livewire::test(Staff::class)->assertTableActionVisible('availability', $record['id'])->assertTableActionHidden('availability', $clerk);
            Livewire::withQueryParams(['staff' => $record['id']])->test(StaffAvailability::class)
                ->callTableAction('declare', data: ['slots' => [$this->slot(0, '08:00', '12:00'), $this->slot(0, '14:00', '16:30')], 'max_weekly_hours' => '20'])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $row = DB::table('school.availability')->where('staff_id', $record['id'])->first();
            $this->assertEquals(['approved', [$this->slot(0, '08:00', '12:00'), $this->slot(0, '14:00', '16:30')], '20.00'],
                [$row->status, json_decode($row->slots, true), $row->max_weekly_hours]);
            Livewire::withQueryParams(['staff' => $record['id']])->test(StaffAvailability::class)->assertCanSeeTableRecords([$row->id])
                ->assertSee(__('staff.availability_states.in_force'))->assertTableActionHidden('withdraw', $row->id)->assertTableActionHidden('approve', $row->id);
            Livewire::withQueryParams(['staff' => $record['id']])->test(StaffUnavailability::class)
                ->callTableAction('declare', data: ['date_from' => $this->sunday(), 'date_to' => $this->sunday(1), 'reason' => 'Formation'])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $off = DB::table('school.unavailability')->where('staff_id', $record['id'])->value('id');
            Livewire::withQueryParams(['staff' => $record['id']])->test(StaffUnavailability::class)->assertCanSeeTableRecords([$off])
                ->callTableAction('cancel', $off)->assertNotified(__('school.saved'));
            $this->assertSame('cancelled', DB::table('school.unavailability')->where('id', $off)->value('status'));
            Livewire::withQueryParams(['staff' => $clerk])->test(StaffAvailability::class)->assertTableActionHidden('declare');
        });
        // With approval asked, the teacher's declaration waits; they withdraw one, the manager approves the other.
        $this->putJson('/api/settings/staff.availability.require_approval', ['value' => true])->assertOk();
        $account = $this->teacherAccount($school, $record);
        $this->within($school, $account, function () use ($record) {
            $page = fn () => Livewire::withQueryParams(['staff' => $record['id']])->test(StaffAvailability::class);
            $page()->assertTableActionVisible('declare')->assertSee(__('staff.availability_states.in_force'))
                ->callTableAction('declare', data: ['valid_from' => $this->sunday(7), 'slots' => []])->assertNotified(__('school.saved'))
                ->callTableAction('declare', data: ['valid_from' => $this->sunday(14), 'slots' => []])->assertNotified(__('school.saved'));
            $pending = DB::table('school.availability')->where('staff_id', $record['id'])->where('status', 'pending')->orderBy('valid_from')->pluck('id')->all();
            $page()->assertTableActionHidden('approve', $pending[0])->assertTableActionVisible('withdraw', $pending[1])
                ->callTableAction('withdraw', $pending[1])->assertNotified(__('school.saved'));
        });
        $pending = DB::connection('owner')->table('school.availability')->where('staff_id', $record['id'])->where('status', 'pending')->value('id');
        $this->within($school, $manager, function () use ($record, $pending) {
            Livewire::test(Staff::class)->filterTable('availability_pending', true)->assertCanSeeTableRecords([$record['id']])->assertCountTableRecords(1);
            Livewire::withQueryParams(['staff' => $record['id']])->test(StaffAvailability::class)
                ->callTableAction('approve', $pending, data: ['decision_reason' => 'Vu avec la direction'])->assertNotified(__('school.saved'));
        });
        $this->assertSame(['approved', 'Vu avec la direction'], array_values((array) DB::connection('owner')->table('school.availability')
            ->where('id', $pending)->first(['status', 'decision_reason'])));
    }
}
