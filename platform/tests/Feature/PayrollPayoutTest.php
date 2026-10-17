<?php

namespace Tests\Feature;

use App\Filament\School\Pages\FinancePeriods;
use App\Filament\School\Pages\MyPay;
use App\Filament\School\Pages\PayrollRun;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The payouts of the pays (FR-REM-04, FR-REM-05, SQL 060) through the real routes and screens: a
 * validated pay paid in parts, never beyond its net, once per key; each payout its `salaries`
 * expense, already paid, which no one pays nor corrects by hand (BR-06); the month's `salaries`
 * expenses equal its payouts (TST-13); a payout taken back by its reversal, decided by someone else
 * (BR-08), which cancels its expense. A teacher reads their own validated pays once the school says
 * so, never anyone else's.
 */
final class PayrollPayoutTest extends TestCase
{
    public function test_a_validated_pay_is_paid_in_parts_each_payout_its_salaries_expense_and_a_teacher_reads_their_own(): void
    {
        $owner = $this->account('payout-owner');
        $school = $this->school('complete', $owner, 'private_school_dz');
        $this->member($school, $owner);
        $hr = $this->account('hr');
        $this->member($school, $hr, 'active', ['academic_hr']);
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $director = $this->account('director');
        $this->member($school, $director, 'active', ['direction']);
        $teacherAccount = $this->account('teacher');
        $this->member($school, $teacherAccount, 'active', ['teacher']);

        $this->signIn($hr)->assertOk();
        $clerk = $this->postJson('/api/staff', ['last_name_lat' => 'Belkacem', 'first_name_lat' => 'Omar', 'staff_type' => 'administrative'])->assertCreated()->json('data.id');
        $teacher = $this->postJson('/api/staff', ['last_name_lat' => 'Mansouri', 'first_name_lat' => 'Nadia', 'staff_type' => 'teacher_internal'])->assertCreated()->json('data.id');
        DB::connection('owner')->table('school.staff')->where('id', $teacher)->update(['user_id' => $teacherAccount['id']]);
        $month = fn (int $k) => now()->startOfMonth()->addMonths($k)->toDateString();
        $this->signIn($accountant)->assertOk();
        foreach ([$clerk => '45000', $teacher => '30000'] as $staff => $amount) {
            $this->postJson("/api/staff/$staff/pay-rules", ['idempotency_key' => (string) Str::uuid(), 'kind' => 'fixed_monthly', 'monthly_amount' => $amount,
                'valid_from' => $month(-2)])->assertCreated();
        }
        $this->postJson('/api/payroll-runs/generate', ['period' => $month(-1)])->assertOk()->assertJsonPath('data.generated', 2);
        $run = $this->getJson('/api/payroll-runs?group=administrative&period='.$month(-1))->assertOk()->json('data.0');
        $teacherRun = $this->getJson('/api/payroll-runs?group=teaching&period='.$month(-1))->assertOk()->json('data.0');
        $last = now()->timezone('Africa/Algiers')->startOfMonth()->subMonthNoOverflow();
        $payout = fn (string $amount, ?string $key = null, ?string $on = null) => ['amount' => $amount, 'paid_on' => $on ?? now()->toDateString(),
            'method_code' => 'transfer', 'external_ref' => 'VIR 12', 'idempotency_key' => $key ?? (string) Str::uuid()];
        // Paid once validated only.
        $this->postJson("/api/payroll-runs/{$run['id']}/payouts", $payout('1000'))->assertConflict()->assertJsonPath('code', 'payroll_run_not_payable');
        $this->signIn($director)->assertOk();
        foreach ([$run, $teacherRun] as $pay) $this->postJson("/api/payroll-runs/{$pay['id']}/validate", ['version' => $pay['version']])->assertOk();
        $this->postJson("/api/payroll-runs/{$run['id']}/payouts", $payout('1000'))->assertForbidden();

        // AC1: half of it, on the 10th of its month, `partially_paid`, and its `salaries` expense of that amount, already paid (BR-06); once per key.
        $this->signIn($accountant)->assertOk();
        $key = (string) Str::uuid();
        $inMonth = $last->copy()->addDays(9)->toDateString();
        $half = $this->postJson("/api/payroll-runs/{$run['id']}/payouts", $payout('22500', $key, $inMonth))->assertCreated()->assertJsonPath('data.status', 'validated')
            ->json('data.id');
        $this->postJson("/api/payroll-runs/{$run['id']}/payouts", $payout('22500', $key, $inMonth))->assertCreated()->assertJsonPath('data.id', $half);
        $this->postJson("/api/payroll-runs/{$run['id']}/payouts", $payout('20000', $key))->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');
        $this->postJson("/api/payroll-runs/{$run['id']}/payouts", $payout('22500.01'))->assertConflict()->assertJsonPath('code', 'payment_exceeds_balance');
        $this->getJson("/api/payroll-runs/{$run['id']}")->assertOk()->assertJsonPath('data.state', 'partially_paid')->assertJsonPath('data.paid', '22500.00')
            ->assertJsonPath('data.left_to_pay', '22500.00')->assertJsonPath('data.payouts.0.id', $half);
        $this->getJson('/api/payroll-runs?status=partially_paid&period='.$month(-1))->assertOk()->assertJsonPath('meta.total', 1);
        $salaries = DB::connection('owner')->table('school.expense_category')->where('tenant_id', $school->id)->where('code', 'salaries')->value('id');
        $expense = $this->getJson("/api/expenses?category=$salaries")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount', '22500.00')
            ->assertJsonPath('data.0.status', 'validated')->assertJsonPath('data.0.payment_state', 'paid')->assertJsonPath('data.0.payroll_period', $month(-1))
            ->json('data.0');
        // FR-DEP-01 AC1: never paid nor corrected by hand.
        $this->postJson("/api/expenses/{$expense['id']}/payments", ['amount' => '1', 'paid_on' => now()->toDateString(), 'method_code' => 'cash',
            'idempotency_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'expense_payroll');
        $this->postJson("/api/expenses/{$expense['id']}/corrections", ['kind' => 'cancellation', 'reason' => 'À la main', 'idempotency_key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'expense_payroll');

        // A payout taken back by its reversal, decided by someone else; its expense cancelled.
        $back = $this->postJson("/api/payroll-payouts/$half/reverse", ['reason' => 'Virement rejeté par la banque', 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.status', 'pending_validation')->json('data');
        $this->postJson("/api/payroll-payouts/{$back['id']}/validate", ['version' => $back['version']])->assertForbidden();
        $this->signIn($director)->assertOk();
        $this->postJson("/api/payroll-payouts/{$back['id']}/validate", ['version' => $back['version']])->assertOk()->assertJsonPath('data.status', 'validated');
        $this->getJson("/api/payroll-runs/{$run['id']}")->assertJsonPath('data.state', 'validated')->assertJsonPath('data.paid', '0.00');
        $this->signIn($accountant)->assertOk();
        $this->getJson("/api/expenses/{$expense['id']}")->assertOk()->assertJsonPath('data.payment_state', 'cancelled');

        // On the screen: the whole net paid; TST-13: the month's `salaries` expenses are its payouts.
        $screen = function (array $account, callable $test) use ($school) {
            $this->actingAs($this->person($account));
            $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
            Filament::setCurrentPanel(Filament::getPanel('school'));
            DB::transaction(function () use ($school, $account, $test) {
                app(CurrentSchool::class)->enter($school->id, $account['id'], CurrentSchool::requestId(null));
                app(CurrentSchool::class)->setMode('active');
                $test();
            });
        };
        $screen($accountant, function () use ($run) {
            Livewire::withQueryParams(['run' => $run['id']])->test(PayrollRun::class)->assertActionVisible('pay')->assertActionHidden('validate_reversal')
                ->callAction('pay', data: ['amount' => '45 000,00', 'paid_on' => now()->toDateString(), 'method_code' => 'cash'])
                ->assertHasNoFormErrors()->assertNotified(__('school.saved'));
            $this->assertSame('paid', DB::selectOne('SELECT school.payroll_state(status, net_amount, school.payroll_paid(tenant_id, id)) AS s FROM school.payroll_run WHERE id = ?',
                [$run['id']])->s);
        });
        $this->signIn($accountant)->assertOk();
        // This month: the whole net paid, the half of last month taken back; last month: its half.
        foreach ([now()->format('Y-m'), $last->format('Y-m')] as $paidIn) {
            $report = $this->getJson('/api/finance-periods/reconciliation?month='.$paidIn)->assertOk()->json('data');
            $this->assertSame(['22500.00', '22500.00'], [$report['salaries_committed'], $report['payroll_paid']], $paidIn);
        }
        $screen($accountant, function () use ($last) {
            Livewire::test(FinancePeriods::class)->mountTableAction('close')->setTableActionData(['month' => $last->format('Y-m')])
                ->assertMountedActionModalSee("Versements de rémunérations : 1 pour 22\u{00A0}500,00");
        });
        // On the screens: that payout taken back by the accountant, the reversal validated by the direction.
        $whole = DB::connection('owner')->table('school.payroll_payout')->where('run_id', $run['id'])->where('kind', 'payout')->where('status', 'validated')->value('id');
        $screen($accountant, function () use ($run, $whole) {
            Livewire::withQueryParams(['run' => $run['id']])->test(PayrollRun::class)->assertActionHidden('pay')
                ->callAction('reverse', data: ['payout' => $whole, 'reason' => 'Erreur de bénéficiaire'])->assertHasNoFormErrors()->assertNotified(__('school.saved'));
        });
        $reversal = DB::connection('owner')->table('school.payroll_payout')->where('reverses_payout_id', $whole)->value('id');
        $screen($director, function () use ($run, $reversal) {
            Livewire::withQueryParams(['run' => $run['id']])->test(PayrollRun::class)->assertActionHidden('reverse')
                ->callAction('validate_reversal', data: ['payout' => $reversal])->assertHasNoFormErrors()->assertNotified(__('school.saved'));
        });
        $this->assertSame('reversed', DB::connection('owner')->table('school.payroll_payout')->where('id', $whole)->value('status'));

        // FR-REM-05: a teacher reads their own validated pay once the school says so, never anyone else's.
        $this->signIn($teacherAccount)->assertOk();
        $this->getJson('/api/payroll-runs?period='.$month(-1))->assertForbidden();
        $this->getJson('/api/my-pay')->assertOk()->assertJsonPath('meta.total', 0);
        $this->get('/school/my-pay')->assertForbidden();
        $this->signIn($owner)->assertOk();
        $this->putJson('/api/settings/payroll.self_view', ['value' => true])->assertOk();
        $this->signIn($teacherAccount)->assertOk();
        $this->getJson('/api/my-pay')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $teacherRun['id'])
            ->assertJsonPath('data.0.net_amount', '30000.00')->assertJsonPath('data.0.state', 'validated');
        $this->getJson("/api/my-pay/{$teacherRun['id']}")->assertOk()->assertJsonPath('data.lines.0.kind', 'fixed')->assertJsonPath('data.payouts', []);
        $this->getJson("/api/my-pay/{$run['id']}")->assertNotFound();
        $screen($teacherAccount, function () use ($teacherRun) {
            Livewire::test(MyPay::class)->assertCanSeeTableRecords([$teacherRun['id']]);
        });
    }
}
