<?php

namespace Tests\Feature;

use App\Filament\School\Pages\ExpenseCorrections;
use App\Filament\School\Pages\Expenses;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An expense's corrections (FR-DEP-03, SQL 057) through the real routes and screens: a validated
 * expense corrected by its linked negative entry with its reason, once per key (BR-05), validated
 * by someone else than whoever asked for it (BR-08), the original kept (AC1); never below what is
 * paid, cancelled once its payments are taken back; exported with what is committed of it; none
 * of it for a teacher.
 */
final class ExpenseCorrectionTest extends TestCase
{
    public function test_a_validated_expense_is_corrected_then_cancelled_by_linked_negative_entries_validated_by_someone_else(): void
    {
        Storage::fake('files');
        $owner = $this->account('expense-corrections-owner');
        $school = $this->school('pro', $owner, 'private_school_dz');
        $clerk = $this->account('clerk');
        $this->member($school, $clerk, 'active', ['accountant']);
        $validator = $this->account('validator');
        $this->member($school, $validator, 'active', ['accountant']);
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);

        $this->signIn($clerk)->assertOk();
        $equipment = collect($this->getJson('/api/expense-categories')->assertOk()->json('data'))->firstWhere('code', 'equipment')['id'];
        $boards = $this->postJson('/api/expenses', ['expense_date' => now()->toDateString(), 'amount' => '10000', 'category_id' => $equipment, 'beneficiary' => 'Bureau Pro',
            'reason' => 'Tableaux blancs', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $correct = fn (string $kind, ?string $amount, string $reason = 'Remise obtenue', ?string $key = null) => $this->postJson("/api/expenses/{$boards['id']}/corrections",
            ['kind' => $kind, 'amount' => $amount, 'reason' => $reason, 'idempotency_key' => $key ?? (string) Str::uuid()]);
        // Not before its validation.
        $correct('correction', '1000')->assertConflict()->assertJsonPath('code', 'expense_not_correctable');
        $this->postJson("/api/expenses/{$boards['id']}/submit", ['version' => $boards['version']])->assertOk();
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/expenses/{$boards['id']}/validate", ['version' => $boards['version'] + 1])->assertOk();

        // Paid in part; never corrected below what is paid; once per key (BR-05).
        $this->signIn($clerk)->assertOk();
        $cash = $this->postJson("/api/expenses/{$boards['id']}/payments", ['amount' => '4000', 'paid_on' => now()->toDateString(), 'method_code' => 'cash',
            'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $correct('correction', '6000.01')->assertConflict()->assertJsonPath('code', 'correction_below_paid');
        $correct('cancellation', '100')->assertUnprocessable();
        $key = (string) Str::uuid();
        $discount = $correct('correction', '2000', 'Remise obtenue', $key)->assertCreated()->assertJsonPath('data.amount', '-2000.00')
            ->assertJsonPath('data.status', 'pending_validation')->assertJsonPath('expense.committed', '10000.00')->json('data');
        $correct('correction', '2000', 'Remise obtenue', $key)->assertCreated()->assertJsonPath('data.id', $discount['id']);
        $correct('correction', '500')->assertConflict()->assertJsonPath('code', 'expense_correction_pending');

        // BR-08: validated by someone else than whoever asked for it; the original kept (AC1).
        $this->postJson("/api/expense-corrections/{$discount['id']}/validate", ['version' => $discount['version']])->assertConflict()->assertJsonPath('code', 'segregation_of_duties');
        $this->signIn($validator)->assertOk();
        $this->getJson('/api/expense-corrections?status=pending_validation')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson("/api/expense-corrections/{$discount['id']}/validate", ['version' => $discount['version']])->assertOk()->assertJsonPath('data.status', 'validated');
        $this->getJson("/api/expenses/{$boards['id']}")->assertOk()->assertJsonPath('data.amount', '10000.00')->assertJsonPath('data.committed', '8000.00')
            ->assertJsonPath('data.left_to_pay', '4000.00')->assertJsonPath('data.payment_state', 'partially_paid');
        $this->postJson("/api/expense-corrections/{$discount['id']}/reject", ['version' => $discount['version'] + 1, 'reason' => 'Trop tard'])
            ->assertConflict()->assertJsonPath('code', 'expense_correction_immutable');

        // Paid, it is cancelled once its payment is taken back.
        $this->signIn($clerk)->assertOk();
        $correct('cancellation', null, 'Commande annulée')->assertConflict()->assertJsonPath('code', 'correction_below_paid');
        $back = $this->postJson("/api/expense-payments/{$cash['id']}/reverse", ['reason' => 'Commande annulée', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/expense-payments/{$back['id']}/validate", ['version' => $back['version']])->assertOk();
        $this->getJson("/api/expenses/{$boards['id']}/corrections")->assertOk()->assertJsonCount(1, 'data');

        // None of it for a teacher.
        $this->signIn($teacher)->assertOk();
        $this->getJson('/api/expense-corrections')->assertForbidden();
        $this->getJson("/api/expenses/{$boards['id']}/corrections")->assertForbidden();
        $correct('cancellation', null, 'Commande annulée')->assertForbidden();
        $this->get('/school/expense-corrections')->assertForbidden();

        // On the screens: cancelled from the expenses, then validated by someone else, never by whoever asked for it.
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
        $within($clerk, function () use ($boards) {
            Livewire::test(Expenses::class)->callTableAction('correct', $boards['id'], data: ['kind' => 'cancellation', 'reason' => 'Commande annulée'])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
        });
        $asked = DB::connection('owner')->table('school.expense_correction')->where('expense_id', $boards['id'])->where('kind', 'cancellation')->first();
        $this->assertSame('-8000.00', $asked->amount);
        $within($clerk, function () use ($asked) {
            Livewire::test(ExpenseCorrections::class)->assertTableActionHidden('validate', $asked->id);
        });
        $within($validator, function () use ($asked) {
            Livewire::test(ExpenseCorrections::class)->filterTable('status', 'pending_validation')->assertCanSeeTableRecords([$asked->id])
                ->callTableAction('validate', $asked->id)->assertNotified(__('school.saved'));
        });
        $this->signIn($validator)->assertOk();
        $this->getJson("/api/expenses/{$boards['id']}")->assertOk()->assertJsonPath('data.status', 'validated')->assertJsonPath('data.amount', '10000.00')
            ->assertJsonPath('data.committed', '0.00')->assertJsonPath('data.payment_state', 'cancelled');
        $this->getJson('/api/expenses?payment=cancelled')->assertOk()->assertJsonPath('meta.total', 1);

        // Exported with what is committed of it, logged like any export.
        $this->postJson('/api/exports', ['kind' => 'expenses', 'format' => 'csv', 'filters' => ['payment' => 'cancelled']])->assertCreated()->assertJsonPath('data.rows', 1);
    }
}
