<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Staff;
use App\Filament\School\Pages\StaffContracts;
use App\Filament\School\Pages\StaffLeaves;
use App\Filament\School\Pages\StaffUnavailability;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A member's contracts and leave (FR-STF-04, SQL 035) through their real routes and screens:
 * dated versions without any amount, planned absences that make a teacher unavailable.
 */
final class ContractLeaveTest extends TestCase
{
    /** A school whose plan has the staff module, its administrator also its pedagogy and HR manager (academic_hr), signed in. */
    private function setting(): array
    {
        $manager = $this->account('terms');
        $school = $this->school('pro', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        return [$school, $manager];
    }

    private function newMember(array $extra = []): array
    {
        return $this->postJson('/api/staff', ['last_name_lat' => 'Meziane', 'first_name_lat' => 'Karim', 'staff_type' => 'teacher_internal', ...$extra])
            ->assertCreated()->json('data');
    }

    private function day(int $offset): string { return now()->addDays($offset)->toDateString(); }

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

    public function test_a_contract_is_versioned_with_its_dates_and_academic_hr_reads_it_without_any_amount(): void
    {
        [$school] = $this->setting();
        $teacher = $this->newMember()['id'];
        $contract = $this->postJson("/api/staff/$teacher/contracts", ['contract_type' => 'fixed_term', 'reference' => 'CDD-2026-01',
            'valid_from' => $this->day(-30), 'valid_to' => $this->day(300), 'weekly_hours' => 18])->assertCreated()
            ->assertJsonPath('data.state', 'in_force')->assertJsonPath('data.weekly_hours', '18.00')->json('data');
        // AC1: nothing a contract says is an amount.
        $this->assertSame([], preg_grep('/amount|salary|rate|pay|wage|price/', array_keys($contract)));
        $add = fn (array $body) => $this->postJson("/api/staff/$teacher/contracts", $body);
        $add(['valid_from' => $this->day(400)])->assertUnprocessable()->assertJsonValidationErrors('contract_type');
        $add(['contract_type' => 'fixed_term', 'valid_from' => $this->day(400), 'valid_to' => $this->day(399)])->assertUnprocessable()
            ->assertJsonValidationErrors('valid_to');
        $add(['contract_type' => 'fixed_term', 'valid_from' => $this->day(400)])->assertConflict()->assertJsonPath('code', 'contract_end_required');
        $add(['contract_type' => 'permanent'])->assertConflict()->assertJsonPath('code', 'contract_overlap');

        // An amendment: a new version from its day, the one in force ending the day before; what is not sent is kept.
        $amend = fn (array $body) => $this->postJson("/api/contracts/{$contract['id']}/amend", ['version' => 1, ...$body]);
        $amend(['valid_from' => $this->day(-30)])->assertUnprocessable()->assertJsonValidationErrors('valid_from');
        $amend(['valid_from' => $this->day(301)])->assertUnprocessable()->assertJsonValidationErrors('valid_from');
        $next = $amend(['valid_from' => $this->day(10), 'weekly_hours' => '20.5'])->assertCreated()
            ->assertJsonPath('data.contract_type', 'fixed_term')->assertJsonPath('data.reference', 'CDD-2026-01')
            ->assertJsonPath('data.valid_to', $this->day(300))->assertJsonPath('data.weekly_hours', '20.50')->assertJsonPath('data.state', 'upcoming')->json('data');
        $this->getJson("/api/staff/$teacher/contracts")->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $next['id'])->assertJsonPath('data.1.valid_to', $this->day(9));
        $amend(['valid_from' => $this->day(5)])->assertConflict()->assertJsonPath('code', 'stale_version');

        // An end only comes earlier; a version begun stays, one to come is deleted.
        $end = fn (string $day) => $this->postJson("/api/contracts/{$next['id']}/end", ['valid_to' => $day, 'end_reason' => 'Démission', 'version' => 1]);
        $end($this->day(301))->assertConflict()->assertJsonPath('code', 'contract_end_later');
        $end($this->day(100))->assertOk()->assertJsonPath('data.valid_to', $this->day(100))->assertJsonPath('data.end_reason', 'Démission');
        $this->deleteJson("/api/contracts/{$contract['id']}", ['version' => 2])->assertConflict()->assertJsonPath('code', 'contract_started');
        $this->deleteJson("/api/contracts/{$next['id']}", ['version' => 2])->assertNoContent();
        $this->getJson("/api/staff/$teacher/contracts")->assertOk()->assertJsonPath('meta.total', 1);

        // Any member's, read with its own permission: the direction reads, the accountant (who reads the records) does not.
        $this->postJson('/api/staff/'.$this->newMember(['staff_type' => 'administrative', 'first_name_lat' => 'Amel'])['id'].'/contracts',
            ['contract_type' => 'permanent', 'weekly_hours' => 40])->assertCreated();
        $direction = $this->account('direction');
        $this->member($school, $direction, 'active', ['direction']);
        $this->signIn($direction)->assertOk();
        $this->getJson("/api/staff/$teacher/contracts")->assertOk()->assertJsonPath('meta.total', 1);
        $add(['contract_type' => 'permanent', 'valid_from' => $this->day(500)])->assertForbidden();
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $this->signIn($accountant)->assertOk();
        $this->getJson("/api/staff/$teacher")->assertOk();
        $this->getJson("/api/staff/$teacher/contracts")->assertForbidden();
    }

    public function test_a_teachers_leave_makes_them_unavailable_without_saying_why_and_is_cancelled_with_it(): void
    {
        [$school, $manager] = $this->setting();
        $teacher = $this->newMember()['id'];
        $leave = $this->postJson("/api/staff/$teacher/leaves", ['leave_type' => 'sick', 'date_from' => $this->day(0), 'date_to' => $this->day(2),
            'observation' => 'Certificat reçu'])->assertCreated()->assertJsonPath('data.state', 'under_way')->assertJsonPath('data.days', 3)
            ->assertJsonPath('data.recorded_by_name', 'terms')->json('data');
        $off = $this->getJson("/api/staff/$teacher/unavailabilities")->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.leave_id', $leave['id'])->assertJsonPath('data.0.reason', null)->assertJsonPath('data.0.status', 'approved')->json('data.0');
        $this->postJson("/api/unavailabilities/{$off['id']}/cancel", ['version' => 1])->assertConflict()->assertJsonPath('code', 'unavailability_from_leave');
        $addLeave = fn (array $body) => $this->postJson("/api/staff/$teacher/leaves", ['leave_type' => 'annual', ...$body]);
        $addLeave(['date_from' => $this->day(2), 'date_to' => $this->day(4)])->assertConflict()->assertJsonPath('code', 'leave_overlap');
        $addLeave(['date_from' => $this->day(5), 'date_to' => $this->day(4)])->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $addLeave(['leave_type' => 'holiday', 'date_from' => $this->day(5), 'date_to' => $this->day(5)])->assertUnprocessable()->assertJsonValidationErrors('leave_type');
        // A sickness recorded afterwards: its days are gone, it stays as it is.
        $past = $addLeave(['leave_type' => 'sick', 'date_from' => $this->day(-10), 'date_to' => $this->day(-9)])->assertCreated()->json('data');
        $this->postJson("/api/leaves/{$past['id']}/cancel", ['version' => 1])->assertConflict()->assertJsonPath('code', 'leave_ended');
        $this->getJson("/api/staff/$teacher/leaves")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/staff/$teacher/leaves?include_past=1")->assertOk()->assertJsonPath('meta.total', 2);
        // A member who does not teach has no timetable to free.
        $clerk = $this->newMember(['staff_type' => 'administrative', 'first_name_lat' => 'Amel'])['id'];
        $this->postJson("/api/staff/$clerk/leaves", ['leave_type' => 'annual', 'date_from' => $this->day(1), 'date_to' => $this->day(10)])->assertCreated();
        $this->getJson("/api/staff/$clerk/unavailabilities")->assertOk()->assertJsonPath('meta.total', 0);

        // Whoever reads the record only reads that the teacher is away, never why.
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $this->signIn($accountant)->assertOk();
        $this->getJson("/api/staff/$teacher/unavailabilities")->assertOk()->assertJsonPath('data.0.reason', null);
        $this->getJson("/api/staff/$teacher/leaves")->assertForbidden();

        // The teacher reads their own leave and contract with the school's teacher role, and records none.
        $account = $this->account('teacher');
        $this->member($school, $account, 'active');
        $this->grant($school, $account, 'teacher', 'personal');
        DB::connection('owner')->table('school.staff')->where('id', $teacher)->update(['user_id' => $account['id']]);
        $this->signIn($account)->assertOk();
        $this->getJson("/api/staff/$teacher/leaves")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.observation', 'Certificat reçu');
        $this->getJson("/api/staff/$clerk/leaves")->assertNotFound();
        $this->getJson("/api/staff/$teacher/contracts")->assertOk()->assertJsonPath('meta.total', 0);
        $addLeave(['date_from' => $this->day(20), 'date_to' => $this->day(20)])->assertForbidden();

        // Cancelled, the teacher is available again; the journal keeps it.
        $this->signIn($manager)->assertOk();
        $this->postJson("/api/leaves/{$leave['id']}/cancel", ['version' => 1])->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->getJson("/api/staff/$teacher/unavailabilities?status=approved")->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/staff/$teacher/leaves")->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/staff/$teacher/leaves?include_cancelled=1")->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_the_pages_record_amend_end_and_cancel_and_the_days_off_show_a_leave_without_its_reason(): void
    {
        [$school, $manager] = $this->setting();
        $record = $this->newMember();
        $clerk = $this->newMember(['staff_type' => 'administrative', 'first_name_lat' => 'Amel'])['id'];
        $this->get('/school/staff-contracts?staff='.$record['id'])->assertOk()->assertSee(__('staff.add_contract'));
        $this->get('/school/staff-leaves?staff='.$record['id'])->assertOk()->assertSee(__('staff.add_leave'));
        $this->within($school, $manager, function () use ($record, $clerk) {
            Livewire::test(Staff::class)->assertTableActionVisible('contracts', $clerk)->assertTableActionVisible('leaves', $record['id']);
            $contracts = fn () => Livewire::withQueryParams(['staff' => $record['id']])->test(StaffContracts::class);
            $contracts()->callTableAction('add', data: ['contract_type' => 'permanent', 'valid_from' => now()->toDateString(), 'weekly_hours' => '18'])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $first = DB::table('school.staff_contract')->where('staff_id', $record['id'])->value('id');
            $contracts()->assertCanSeeTableRecords([$first])->assertSee(__('staff.contract_states.in_force'))->assertTableActionHidden('delete', $first)
                ->callTableAction('amend', $first, data: ['valid_from' => now()->addDays(30)->toDateString(), 'contract_type' => 'permanent', 'weekly_hours' => '24'])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $next = DB::table('school.staff_contract')->where('staff_id', $record['id'])->where('id', '<>', $first)->first();
            $this->assertEquals(['24.00', now()->addDays(29)->toDateString()],
                [$next->weekly_hours, DB::table('school.staff_contract')->where('id', $first)->value('valid_to')]);
            $contracts()->callTableAction('delete', $next->id)->assertNotified(__('school.saved'));
            $contracts()->callTableAction('end', $first, data: ['valid_to' => now()->addDays(10)->toDateString(), 'end_reason' => 'Fin de mission'])
                ->assertNotified(__('school.saved'));
            $this->assertSame('Fin de mission', DB::table('school.staff_contract')->where('id', $first)->value('end_reason'));

            $leaves = fn () => Livewire::withQueryParams(['staff' => $record['id']])->test(StaffLeaves::class);
            $leaves()->assertSee(__('staff.no_leave'))->callTableAction('add', data: ['leave_type' => 'training', 'date_from' => now()->addDay()->toDateString(),
                'date_to' => now()->addDays(2)->toDateString(), 'observation' => 'Stage académique'])->assertNotified(__('school.saved'));
            $leave = DB::table('school.staff_leave')->where('staff_id', $record['id'])->value('id');
            $off = DB::table('school.unavailability')->where('leave_id', $leave)->value('id');
            Livewire::withQueryParams(['staff' => $record['id']])->test(StaffUnavailability::class)->assertCanSeeTableRecords([$off])
                ->assertSee(__('staff.leave_unavailability'))->assertDontSee('Stage académique')->assertTableActionHidden('cancel', $off);
            $leaves()->assertSee('Stage académique')->callTableAction('cancel', $leave)->assertNotified(__('school.saved'));
            $this->assertSame(['cancelled', 'cancelled'], [DB::table('school.staff_leave')->where('id', $leave)->value('status'),
                DB::table('school.unavailability')->where('id', $off)->value('status')]);
        });
    }
}
