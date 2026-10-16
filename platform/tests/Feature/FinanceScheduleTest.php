<?php

namespace Tests\Feature;

use App\Filament\School\Pages\AdjustmentApprovals;
use App\Filament\School\Pages\EnrollmentFinance;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What an enrolment owes and when (FR-FIN-01, SQL 048) through the real routes and screens: a
 * schedule from the programme's terms whose sum is what is owed, adjustments with their reason
 * decided by the direction beyond the threshold, never by their author.
 */
final class FinanceScheduleTest extends TestCase
{
    /** @return array{0: object, 1: array, 2: array, 3: string} a school, its accountant and direction, an enrolment of 120,000 */
    private function setting(): array
    {
        $manager = $this->account('finance-manager');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => 'SCI-1', 'labels' => ['fr' => 'Sciences'], 'base_price' => '120000',
            'payment_terms' => [['code' => 'T1', 'percent' => 30, 'due_date' => '2026-09-15'], ['code' => 'T2', 'percent' => 70, 'due_date' => '2027-01-15']],
            'status' => 'active'])->assertCreated()->json('data.id');
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '1995-01-01'])->assertCreated()->json('data.id');
        $enrollment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $director = $this->account('director');
        $this->member($school, $director, 'active', ['direction']);
        return [$school, $accountant, $director, $enrollment];
    }

    public function test_a_schedule_adds_up_to_what_is_owed_and_adjustments_are_decided_by_the_direction(): void
    {
        [$school, $accountant, $director, $enrollment] = $this->setting();
        $this->signIn($accountant)->assertOk();
        $this->getJson("/api/enrollments/$enrollment/finance")->assertOk()->assertJsonPath('data.due', '120000.00')->assertJsonPath('data.schedule', null);
        // The programme's terms: 30 % then 70 %, on their days.
        $proposal = $this->getJson("/api/enrollments/$enrollment/schedule/proposal")->assertOk()->json('data.installments');
        $this->assertSame([['due_date' => '2026-09-15', 'amount' => '36000.00'], ['due_date' => '2027-01-15', 'amount' => '84000.00']], $proposal);
        // AC1: a schedule whose sum differs from what is owed is refused.
        $this->postJson("/api/enrollments/$enrollment/schedule", ['installments' => [['due_date' => '2026-09-15', 'amount' => '100000']],
            'idempotency_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'schedule_sum_mismatch');
        $key = (string) Str::uuid();
        $this->postJson("/api/enrollments/$enrollment/schedule", ['installments' => $proposal, 'idempotency_key' => $key])->assertCreated()
            ->assertJsonPath('data.schedule.version', 1)->assertJsonPath('data.schedule_matches', true);
        $this->postJson("/api/enrollments/$enrollment/schedule", ['installments' => $proposal, 'idempotency_key' => $key])->assertCreated()
            ->assertJsonPath('data.schedule.version', 1);

        // AC2: an adjustment without its reason is refused; within 10 %, approved at once; beyond, the direction decides.
        $this->postJson("/api/enrollments/$enrollment/adjustments", ['kind' => 'discount', 'amount' => '5000', 'reason' => '', 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/enrollments/$enrollment/adjustments", ['kind' => 'discount', 'amount' => '12000', 'reason' => 'Fratrie',
            'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.status', 'approved');
        $pending = $this->postJson("/api/enrollments/$enrollment/adjustments", ['kind' => 'debt_waiver', 'amount' => '18000', 'reason' => 'Situation sociale',
            'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->getJson("/api/enrollments/$enrollment/finance")->assertOk()->assertJsonPath('data.due', '108000.00')->assertJsonPath('data.pending', '18000.00')
            ->assertJsonPath('data.schedule_matches', false);
        $this->postJson("/api/adjustments/{$pending['id']}/approve", ['version' => $pending['version']])->assertForbidden();
        $this->signIn($director)->assertOk();
        $this->getJson('/api/adjustments?status=pending')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.enrollment.program', 'SCI-1');
        $this->postJson("/api/adjustments/{$pending['id']}/reject", ['version' => $pending['version']])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/adjustments/{$pending['id']}/approve", ['version' => $pending['version']])->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decider', 'director');
        $this->postJson("/api/adjustments/{$pending['id']}/reject", ['version' => $pending['version'] + 1, 'reason' => 'Trop tard'])->assertConflict()
            ->assertJsonPath('code', 'adjustment_decided');
        $this->postJson("/api/enrollments/$enrollment/adjustments", ['kind' => 'discount', 'amount' => '1', 'reason' => 'Pas son rôle',
            'idempotency_key' => (string) Str::uuid()])->assertForbidden();

        // The schedule follows in a new version, with its reason.
        $this->signIn($accountant)->assertOk();
        $this->postJson("/api/enrollments/$enrollment/schedule", ['installments' => [['due_date' => '2026-09-15', 'amount' => '90000']],
            'idempotency_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'reason_required');
        $this->postJson("/api/enrollments/$enrollment/schedule", ['installments' => [['due_date' => '2026-09-15', 'amount' => '45000'], ['due_date' => '2027-01-15', 'amount' => '45000']],
            'reason' => 'Remises accordées', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.schedule.version', 2)
            ->assertJsonPath('data.due', '90000.00')->assertJsonPath('data.schedule_matches', true);
        // Whoever reads no finance reads none of it.
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher)->assertOk();
        $this->getJson("/api/enrollments/$enrollment/finance")->assertForbidden();
    }

    public function test_the_screens_record_an_adjustment_and_the_direction_decides_it(): void
    {
        [$school, $accountant, $director, $enrollment] = $this->setting();
        $this->signIn($accountant)->assertOk();
        $this->get('/school/enrollment-finance?enrollment='.$enrollment)->assertOk()->assertSee("120\u{00A0}000,00");
        $within = function (array $account, callable $test) use ($school) {
            $this->actingAs($this->person($account));
            $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
            Filament::setCurrentPanel(Filament::getPanel('school'));
            DB::transaction(function () use ($school, $account, $test) {
                app(CurrentSchool::class)->enter($school->id, $account['id'], CurrentSchool::requestId(null));
                app(CurrentSchool::class)->setMode('active');
                $test();
            });
        };
        $within($accountant, function () use ($enrollment) {
            Livewire::withQueryParams(['enrollment' => $enrollment])->test(EnrollmentFinance::class)
                ->assertActionVisible('adjust')->callAction('adjust', data: ['kind' => 'discount', 'amount' => '30 000,00', 'reason' => 'Bourse au mérite'])->assertHasNoFormErrors()->assertNotified(__('school.saved'));
            $this->assertSame('pending', DB::table('school.adjustment')->where('enrollment_id', $enrollment)->value('status'));
            // The first schedule, as proposed from the programme's terms.
            Livewire::withQueryParams(['enrollment' => $enrollment])->test(EnrollmentFinance::class)->callAction('schedule')->assertHasNoFormErrors()
                ->assertNotified(__('school.saved'));
            $this->assertSame(['36000.00', '84000.00'], DB::table('school.installment')->orderBy('seq')->pluck('amount')->map(fn ($a) => (string) $a)->all());
        });
        $within($director, function () use ($enrollment) {
            $adjustment = DB::table('school.adjustment')->where('enrollment_id', $enrollment)->value('id');
            Livewire::test(AdjustmentApprovals::class)->assertCanSeeTableRecords([$adjustment])->assertSee('Bourse au mérite')
                ->callTableAction('approve', $adjustment)->assertNotified(__('school.saved'));
            $this->assertSame('approved', DB::table('school.adjustment')->where('id', $adjustment)->value('status'));
        });
    }
}
