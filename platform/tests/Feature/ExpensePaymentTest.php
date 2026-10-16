<?php

namespace Tests\Feature;

use App\Filament\School\Pages\ExpensePayments;
use App\Filament\School\Pages\Expenses;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An expense's payments (FR-DEP-02, SQL 056) through the real routes and screens: a validated
 * expense paid in parts, never beyond, once per key (BR-05), its payment state apart from its
 * commitment; a payment never changed (TST-11), taken back by its reversal, validated by someone
 * else than whoever asked for it (BR-08); the expenses filtered and exported; none of it for a teacher.
 */
final class ExpensePaymentTest extends TestCase
{
    public function test_a_validated_expense_is_paid_in_parts_and_a_payment_taken_back_by_someone_else(): void
    {
        Storage::fake('files');
        $owner = $this->account('expense-payments-owner');
        $school = $this->school('pro', $owner, 'private_school_dz');
        $clerk = $this->account('clerk');
        $this->member($school, $clerk, 'active', ['accountant']);
        $validator = $this->account('validator');
        $this->member($school, $validator, 'active', ['accountant']);
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);

        $this->signIn($clerk)->assertOk();
        $rent = collect($this->getJson('/api/expense-categories')->assertOk()->json('data'))->firstWhere('code', 'rent')['id'];
        $lease = $this->postJson('/api/expenses', ['expense_date' => now()->toDateString(), 'amount' => '30000', 'category_id' => $rent, 'beneficiary' => 'SCI Les Oliviers',
            'reason' => 'Loyer du trimestre', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $pay = fn (string $amount, string $method = 'cash', ?string $key = null) => $this->postJson("/api/expenses/{$lease['id']}/payments", ['amount' => $amount,
            'paid_on' => now()->toDateString(), 'method_code' => $method, 'external_ref' => $method === 'cheque' ? 'CHQ 77' : null, 'idempotency_key' => $key ?? (string) Str::uuid()]);
        // Not before its validation.
        $pay('1000')->assertConflict()->assertJsonPath('code', 'expense_not_payable');
        $this->postJson("/api/expenses/{$lease['id']}/submit", ['version' => $lease['version']])->assertOk();
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/expenses/{$lease['id']}/validate", ['version' => $lease['version'] + 1])->assertOk()->assertJsonPath('data.payment_state', 'unpaid');

        // Paid in parts, never beyond what is left; the same key answers the first payment (BR-05).
        $this->signIn($clerk)->assertOk();
        $key = (string) Str::uuid();
        $cash = $pay('12000', 'cash', $key)->assertCreated()->assertJsonPath('expense.payment_state', 'partially_paid')->assertJsonPath('expense.left_to_pay', '18000.00')->json('data');
        $pay('12000', 'cash', $key)->assertCreated()->assertJsonPath('data.id', $cash['id']);
        $pay('18000.01')->assertConflict()->assertJsonPath('code', 'payment_exceeds_balance');
        $cheque = $pay('18000', 'cheque')->assertCreated()->assertJsonPath('expense.payment_state', 'paid')->json('data');
        $this->getJson('/api/expenses?payment=paid')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/expenses/{$lease['id']}/payments")->assertOk()->assertJsonCount(2, 'data');

        // Taken back by its reversal, validated by someone else than whoever asked for it (BR-08).
        $this->postJson("/api/expense-payments/{$cheque['id']}/reverse", ['reason' => 'x', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $reversal = $this->postJson("/api/expense-payments/{$cheque['id']}/reverse", ['reason' => 'Chèque rejeté par la banque', 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.status', 'pending_validation')->assertJsonPath('data.amount', '18000.00')->json('data');
        $this->postJson("/api/expense-payments/{$reversal['id']}/validate", ['version' => $reversal['version']])->assertConflict()->assertJsonPath('code', 'segregation_of_duties');
        $this->signIn($validator)->assertOk();
        $this->getJson('/api/expense-payments?status=pending_validation')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson("/api/expense-payments/{$reversal['id']}/validate", ['version' => $reversal['version']])->assertOk()->assertJsonPath('data.status', 'validated');
        $this->getJson("/api/expenses/{$lease['id']}")->assertOk()->assertJsonPath('data.payment_state', 'partially_paid')->assertJsonPath('data.paid', '12000.00');
        $this->postJson("/api/expense-payments/{$cheque['id']}/reverse", ['reason' => 'Encore une fois', 'idempotency_key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'payment_not_reversible');

        // Filtered and exported, logged like any export.
        $this->postJson('/api/exports', ['kind' => 'expenses', 'format' => 'csv', 'filters' => ['payment' => 'partially_paid']])->assertCreated()->assertJsonPath('data.rows', 1);
        $this->assertSame(1, DB::connection('owner')->table('school.export_log')->where('tenant_id', $school->id)->where('kind', 'expenses')->value('row_count'));

        // None of it for a teacher.
        $this->signIn($teacher)->assertOk();
        $this->getJson('/api/expense-payments')->assertForbidden();
        $this->getJson("/api/expenses/{$lease['id']}/payments")->assertForbidden();
        $this->postJson('/api/exports', ['kind' => 'expenses', 'format' => 'csv'])->assertForbidden();
        $this->get('/school/expense-payments')->assertForbidden();

        // On the screens: what is left paid from the expenses, taken back, then validated by someone else.
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
        $within($clerk, function () use ($lease) {
            Livewire::test(Expenses::class)->filterTable('payment', 'partially_paid')->assertCanSeeTableRecords([$lease['id']])
                ->callTableAction('pay', $lease['id'], data: ['amount' => '18 000,00', 'method_code' => 'transfer', 'external_ref' => 'VIR 12'])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
        });
        $transfer = DB::connection('owner')->table('school.expense_payment')->where('expense_id', $lease['id'])->where('method_code', 'transfer')->first();
        $this->assertSame('18000.00', $transfer->amount);
        $within($clerk, function () use ($transfer) {
            Livewire::test(ExpensePayments::class)->callTableAction('reverse', $transfer->id, data: ['reason' => 'Virement en double'])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
        });
        $asked = DB::connection('owner')->table('school.expense_payment')->where('reverses_payment_id', $transfer->id)->first();
        $within($clerk, function () use ($asked) {
            Livewire::test(ExpensePayments::class)->assertTableActionHidden('validate', $asked->id);
        });
        $within($validator, function () use ($asked) {
            Livewire::test(ExpensePayments::class)->filterTable('status', 'pending_validation')->assertCanSeeTableRecords([$asked->id])
                ->callTableAction('validate', $asked->id)->assertNotified(__('school.saved'));
        });
        $this->assertSame('reversed', DB::connection('owner')->table('school.expense_payment')->where('id', $transfer->id)->value('status'));
    }
}
