<?php

namespace Tests\Feature;

use App\Filament\School\Pages\EnrollmentFinance;
use App\Filament\School\Pages\PaymentValidations;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An enrolment's payments (FR-FIN-02, FR-FIN-03, SQL 049) through the real routes and screens: the
 * front office's cash desk records, the finance validates, never its own entry; a validated
 * payment never changes and is taken back by a reversal validated in its turn (TST-09, TST-11).
 */
final class PaymentTest extends TestCase
{
    /** @return array{0: object, 1: array, 2: array, 3: array, 4: string} a school, its cash desk, two accountants, an enrolment of 100,000 */
    private function setting(): array
    {
        $manager = $this->account('payments-manager');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => 'PAY-1', 'labels' => ['fr' => 'Paiements'], 'base_price' => '100000',
            'status' => 'active'])->assertCreated()->json('data.id');
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Kaci', 'first_name_lat' => 'Yasmine', 'birth_date' => '1996-03-01'])->assertCreated()->json('data.id');
        $enrollment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        // The front office's cash desk: the delegation the school gives it (FR-FIN-02).
        $owner = DB::connection('owner');
        $owner->statement("INSERT INTO school.role (tenant_id, code, kind, labels) VALUES (?, 'cash_desk', 'internal', '{\"fr\": \"Caisse\"}')", [$school->id]);
        $owner->statement("INSERT INTO school.role_permission (tenant_id, role_id, permission_code) SELECT tenant_id, id, 'finance.payment.create.delegated' FROM school.role
            WHERE tenant_id = ? AND code = 'cash_desk'", [$school->id]);
        $cashier = $this->account('cashier');
        $this->member($school, $cashier, 'active', ['front_office', 'cash_desk']);
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $validator = $this->account('validator');
        $this->member($school, $validator, 'active', ['accountant']);
        return [$school, $cashier, $accountant, $validator, $enrollment];
    }

    private function pay(string $enrollment, string $amount, array $more = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/enrollments/$enrollment/payments", ['amount' => $amount, 'paid_on' => now()->toDateString(), 'method_code' => 'cash',
            'idempotency_key' => (string) Str::uuid(), ...$more]);
    }

    public function test_a_payment_is_validated_by_someone_else_and_reversed_never_changed(): void
    {
        [$school, $cashier, $accountant, $validator, $enrollment] = $this->setting();
        $this->signIn($cashier)->assertOk();
        $key = (string) Str::uuid();
        $cash = $this->pay($enrollment, '30000', ['external_ref' => 'Guichet', 'idempotency_key' => $key])->assertCreated()
            ->assertJsonPath('data.status', 'pending_validation')->assertJsonPath('data.delegated', true)->json('data');
        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{6}$/', $cash['reference']);
        $this->pay($enrollment, '30000', ['idempotency_key' => $key])->assertCreated()->assertJsonPath('data.id', $cash['id']);
        $this->pay($enrollment, '100', ['paid_on' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $this->pay($enrollment, '0.00')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->pay($enrollment, '70000.01')->assertConflict()->assertJsonPath('code', 'payment_exceeds_balance');
        $this->pay($enrollment, '100', ['method_code' => 'bitcoin'])->assertConflict()->assertJsonPath('code', 'payment_method_unknown');
        $this->postJson("/api/payments/{$cash['id']}/validate", ['version' => $cash['version']])->assertForbidden();
        $this->getJson("/api/enrollments/$enrollment/finance")->assertOk()->assertJsonPath('data.paid', '0.00')->assertJsonPath('data.payments_pending', '30000.00')
            ->assertJsonPath('data.state', 'unpaid')->assertJsonPath('data.payments.0.reference', $cash['reference']);

        // The finance validates; the pedagogical state stays (TST-09).
        $this->signIn($accountant)->assertOk();
        $this->getJson('/api/payments?status=pending_validation')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.enrollment.program', 'PAY-1');
        $this->postJson("/api/payments/{$cash['id']}/validate", ['version' => $cash['version']])->assertOk()->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.validator', 'accountant');
        $this->getJson("/api/enrollments/$enrollment/finance")->assertOk()->assertJsonPath('data.paid', '30000.00')->assertJsonPath('data.balance', '70000.00')
            ->assertJsonPath('data.state', 'partially_paid');
        $this->assertSame('pending', DB::connection('owner')->table('school.enrollment')->where('id', $enrollment)->value('status'));
        // TST-11: validated, it never changes.
        $validated = $this->getJson('/api/payments?status=validated')->json('data.0');
        $this->patchJson("/api/payments/{$cash['id']}", ['version' => $validated['version'], 'amount' => '1', 'paid_on' => now()->toDateString(), 'method_code' => 'cash'])
            ->assertConflict()->assertJsonPath('code', 'payment_immutable');
        // AC3: its own payment, the accountant does not validate it; their colleague does.
        $own = $this->pay($enrollment, '20000', ['method_code' => 'transfer', 'external_ref' => 'VIR 12'])->assertCreated()->assertJsonPath('data.delegated', false)->json('data');
        $this->postJson("/api/payments/{$own['id']}/validate", ['version' => $own['version']])->assertConflict()->assertJsonPath('code', 'segregation_of_duties');
        // A waiting payment, corrected then withdrawn by its author.
        $slip = $this->pay($enrollment, '5000')->assertCreated()->json('data');
        $slip = $this->patchJson("/api/payments/{$slip['id']}", ['version' => $slip['version'], 'amount' => '6000', 'paid_on' => now()->toDateString(), 'method_code' => 'cheque',
            'external_ref' => 'CHQ 7'])->assertOk()->assertJsonPath('data.amount', '6000.00')->json('data');
        $this->postJson("/api/payments/{$slip['id']}/cancel", ['version' => $slip['version']])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/payments/{$slip['id']}/cancel", ['version' => $slip['version'], 'reason' => 'Chèque sans provision'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/payments/{$own['id']}/validate", ['version' => $own['version']])->assertOk()->assertJsonPath('data.self_validated', false);

        // AC2: a reversal, with its reason, validated by someone else, takes the payment back; the payment stays.
        $this->signIn($accountant)->assertOk();
        $reversal = $this->postJson("/api/payments/{$cash['id']}/reverse", ['reason' => 'Erreur de saisie', 'idempotency_key' => (string) Str::uuid()])->assertCreated()
            ->assertJsonPath('data.kind', 'reversal')->assertJsonPath('data.amount', '30000.00')->assertJsonPath('data.reverses_reference', $cash['reference'])->json('data');
        $this->postJson("/api/payments/{$cash['id']}/reverse", ['reason' => 'Encore', 'idempotency_key' => (string) Str::uuid()])->assertConflict()
            ->assertJsonPath('code', 'payment_not_reversible');
        $this->postJson("/api/payments/{$reversal['id']}/validate", ['version' => $reversal['version']])->assertConflict()->assertJsonPath('code', 'segregation_of_duties');
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/payments/{$reversal['id']}/validate", ['version' => $reversal['version']])->assertOk();
        $finance = $this->getJson("/api/enrollments/$enrollment/finance")->assertOk()->assertJsonPath('data.paid', '20000.00')->json('data');
        $this->assertSame(['reversed', 'validated'], collect($finance['payments'])->where('kind', 'payment')->whereIn('id', [$cash['id'], $own['id']])
            ->sortBy('created_at')->pluck('status')->values()->all());
        // Whoever reads no finance reads no payment.
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher)->assertOk();
        $this->getJson('/api/payments')->assertForbidden();
        $this->getJson("/api/enrollments/$enrollment/finance")->assertForbidden();
    }

    public function test_the_cash_desk_records_and_the_finance_validates_and_reverses_on_screen(): void
    {
        [$school, $cashier, $accountant, $validator, $enrollment] = $this->setting();
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
        $sheet = fn () => Livewire::withQueryParams(['enrollment' => $enrollment])->test(EnrollmentFinance::class);
        $within($cashier, function () use ($sheet) {
            $sheet()->assertActionVisible('pay')->assertActionHidden('validate_payment')
                ->callAction('pay', data: ['amount' => '30 000,00', 'method_code' => 'cash', 'external_ref' => 'Guichet'])->assertHasNoFormErrors()->assertNotified(__('school.saved'));
        });
        $payment = DB::connection('owner')->table('school.payment')->where('enrollment_id', $enrollment)->value('id');
        $within($accountant, function () use ($payment, $sheet) {
            Livewire::test(PaymentValidations::class)->assertCanSeeTableRecords([$payment])->assertSee('Guichet')
                ->callTableAction('validate', $payment)->assertNotified(__('school.saved'));
            $sheet()->assertSee(__('finance.states.partially_paid'))
                ->callAction('reverse_payment', data: ['payment' => $payment, 'reason' => 'Mauvais élève'])->assertHasNoFormErrors()->assertNotified(__('school.saved'));
        });
        $within($validator, function () use ($sheet) {
            $reversal = DB::table('school.payment')->where('kind', 'reversal')->value('id');
            $sheet()->callAction('validate_payment', data: ['payment' => $reversal])->assertHasNoFormErrors()->assertNotified(__('school.saved'));
        });
        $this->assertSame('reversed', DB::connection('owner')->table('school.payment')->where('id', $payment)->value('status'));
    }
}
