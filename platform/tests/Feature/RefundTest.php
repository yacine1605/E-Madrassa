<?php

namespace Tests\Feature;

use App\Filament\School\Pages\EnrollmentFinance;
use App\Filament\School\Pages\RefundApprovals;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Refunds and overpayments (FR-FIN-04, SQL 050) through the real routes and screens: the finance
 * asks, the direction approves, never the one who asked, the finance executes; a credit is
 * refunded, carried over to the pupil's other enrolment, or kept with its reason.
 */
final class RefundTest extends TestCase
{
    /** @return array{0: object, 1: array, 2: array, 3: array, 4: string, 5: string} a school, two accountants, its direction, two enrolments of one pupil */
    private function setting(): array
    {
        $manager = $this->account('refunds-manager');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $program = fn (string $code, string $price) => $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => $code, 'labels' => ['fr' => $code],
            'base_price' => $price, 'status' => 'active'])->assertCreated()->json('data.id');
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Mansouri', 'first_name_lat' => 'Ilyes', 'birth_date' => '1997-04-01'])->assertCreated()->json('data.id');
        $enrol = fn (string $program) => $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        $main = $enrol($program('RMB-1', '100000'));
        $other = $enrol($program('RMB-2', '50000'));
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $validator = $this->account('validator');
        $this->member($school, $validator, 'active', ['accountant']);
        $director = $this->account('director');
        $this->member($school, $director, 'active', ['direction']);
        // 110,000 paid for 100,000 owed: a credit of 10,000.
        $this->signIn($accountant)->assertOk();
        $paid = $this->postJson("/api/enrollments/$main/payments", ['amount' => '110000', 'paid_on' => now()->toDateString(), 'method_code' => 'transfer',
            'overpayment' => true, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/payments/{$paid['id']}/validate", ['version' => $paid['version']])->assertOk();
        return [$school, $accountant, $validator, $director, $main, $other];
    }

    private function ask(string $enrollment, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/enrollments/$enrollment/refunds", ['kind' => 'refund', 'reason' => 'Trop-perçu', 'idempotency_key' => (string) Str::uuid(), ...$data]);
    }

    public function test_a_refund_is_approved_by_the_direction_then_executed_and_a_credit_is_decided(): void
    {
        [$school, $accountant, $validator, $director, $main, $other] = $this->setting();
        $this->signIn($accountant)->assertOk();
        $this->getJson("/api/enrollments/$main/finance")->assertOk()->assertJsonPath('data.credit', '10000.00')->assertJsonPath('data.state', 'paid')
            ->assertJsonPath('data.siblings.0.id', $other);
        $this->ask($main, ['amount' => '110000.01'])->assertConflict()->assertJsonPath('code', 'refund_exceeds_paid');
        $this->ask($main, ['amount' => '4000', 'reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $refund = $this->ask($main, ['amount' => '4000'])->assertCreated()->assertJsonPath('data.status', 'requested')->json('data');
        // AC2: never executed before its approval; approved by the direction, not by the accountant.
        $this->postJson("/api/refunds/{$refund['id']}/execute", ['version' => $refund['version'], 'method_code' => 'cash', 'paid_on' => now()->toDateString()])
            ->assertConflict()->assertJsonPath('code', 'refund_not_approved');
        $this->postJson("/api/refunds/{$refund['id']}/approve", ['version' => $refund['version']])->assertForbidden();
        $this->signIn($director)->assertOk();
        $this->getJson('/api/refunds?status=requested')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.enrollment.program', 'RMB-1');
        $refund = $this->postJson("/api/refunds/{$refund['id']}/approve", ['version' => $refund['version']])->assertOk()->assertJsonPath('data.decider', 'director')->json('data');
        $this->signIn($accountant)->assertOk();
        $this->postJson("/api/refunds/{$refund['id']}/execute", ['version' => $refund['version']])->assertUnprocessable()->assertJsonValidationErrors(['method_code', 'paid_on']);
        $executed = $this->postJson("/api/refunds/{$refund['id']}/execute", ['version' => $refund['version'], 'method_code' => 'cash', 'paid_on' => now()->toDateString()])
            ->assertOk()->assertJsonPath('data.status', 'executed')->json('data');
        $this->assertMatchesRegularExpression('/^RMB-\d{4}-\d{6}$/', $executed['reference']);
        $this->getJson("/api/enrollments/$main/finance")->assertOk()->assertJsonPath('data.paid', '106000.00')->assertJsonPath('data.refunded', '4000.00')
            ->assertJsonPath('data.credit', '6000.00');

        // A carry-over pays the pupil's other enrolment, once the finance validates it there.
        $carry = $this->ask($main, ['kind' => 'carry_over', 'target_enrollment_id' => $other, 'amount' => '3000', 'reason' => 'Report sur RMB-2'])->assertCreated()->json('data');
        $this->signIn($director)->assertOk();
        $carry = $this->postJson("/api/refunds/{$carry['id']}/approve", ['version' => $carry['version']])->assertOk()->json('data');
        $this->signIn($accountant)->assertOk();
        $this->postJson("/api/refunds/{$carry['id']}/execute", ['version' => $carry['version']])->assertOk()->assertJsonPath('data.status', 'executed');
        $carried = $this->getJson("/api/enrollments/$other/finance")->assertOk()->assertJsonPath('data.payments.0.method_code', 'carry_over')
            ->assertJsonPath('data.payments.0.status', 'pending_validation')->json('data.payments.0');
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/payments/{$carried['id']}/validate", ['version' => $carried['version']])->assertOk();
        $this->getJson("/api/enrollments/$other/finance")->assertOk()->assertJsonPath('data.paid', '3000.00')->assertJsonPath('data.state', 'partially_paid');
        // The rest kept by the school, with its reason.
        $this->signIn($accountant)->assertOk();
        $this->postJson("/api/enrollments/$main/adjustments", ['kind' => 'credit_retained', 'amount' => '3000.01', 'reason' => 'Conservé',
            'idempotency_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'credit_exceeded');
        $this->postJson("/api/enrollments/$main/adjustments", ['kind' => 'credit_retained', 'amount' => '3000', 'reason' => 'Frais de dossier conservés',
            'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.status', 'approved');
        $this->getJson("/api/enrollments/$main/finance")->assertOk()->assertJsonPath('data.credit', '0.00')->assertJsonPath('data.due', '103000.00');
        // Whoever reads no finance reads no refund.
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher)->assertOk();
        $this->getJson('/api/refunds')->assertForbidden();
    }

    public function test_the_finance_asks_on_screen_and_the_direction_approves(): void
    {
        [$school, $accountant, , $director, $main] = $this->setting();
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
        $sheet = fn () => Livewire::withQueryParams(['enrollment' => $main])->test(EnrollmentFinance::class);
        $within($accountant, function () use ($sheet) {
            // The credit comes as the amount to refund.
            $sheet()->assertSee(__('finance.credit'))->mountAction('refund')->assertActionDataSet(['amount' => '10 000,00'])
                ->setActionData(['reason' => 'Trop-perçu du virement'])->callMountedAction()->assertHasNoFormErrors()->assertNotified(__('school.saved'));
        });
        $refund = DB::connection('owner')->table('school.refund')->where('enrollment_id', $main)->value('id');
        $within($director, function () use ($refund) {
            Livewire::test(RefundApprovals::class)->assertCanSeeTableRecords([$refund])->assertSee('Trop-perçu du virement')
                ->callTableAction('approve', $refund)->assertNotified(__('school.saved'));
        });
        $within($accountant, function () use ($sheet, $refund) {
            $sheet()->callAction('execute_refund', data: ['refund' => $refund, 'method_code' => 'transfer', 'external_ref' => 'VIR RET 1'])->assertHasNoFormErrors()
                ->assertNotified(__('school.saved'));
        });
        $this->assertSame('executed', DB::connection('owner')->table('school.refund')->where('id', $refund)->value('status'));
    }
}
