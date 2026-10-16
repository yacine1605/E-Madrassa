<?php

namespace Tests\Feature;

use App\Filament\School\Pages\FinancePeriods;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The financial period's close (FR-FIN-07, BR-15, SQL 053) through the real routes and screens: a
 * past month read, then closed by the finance once nothing dated in it waits; nothing dated in it
 * afterwards (AC1); reopened by the direction with its reason, never by whoever closed it,
 * journalled (AC2).
 */
final class FinancePeriodTest extends TestCase
{
    public function test_a_closed_month_refuses_its_dates_and_is_reopened_by_someone_else_with_a_reason(): void
    {
        $manager = $this->account('periods-manager');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addYear()->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => 'CLO-1', 'labels' => ['fr' => 'Clôture'], 'base_price' => '100000',
            'status' => 'active'])->assertCreated()->json('data.id');
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Hamidi', 'first_name_lat' => 'Sara', 'birth_date' => '1998-02-01'])->assertCreated()->json('data.id');
        $enrollment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $validator = $this->account('validator');
        $this->member($school, $validator, 'active', ['accountant']);
        $director = $this->account('director');
        $this->member($school, $director, 'active', ['direction']);
        $last = now()->timezone('Africa/Algiers')->startOfMonth()->subMonthNoOverflow();
        $month = $last->format('Y-m');
        $inMonth = $last->copy()->addDays(9)->toDateString();
        $pay = fn (string $amount, string $day) => $this->postJson("/api/enrollments/$enrollment/payments", ['amount' => $amount, 'paid_on' => $day, 'method_code' => 'cash',
            'idempotency_key' => (string) Str::uuid()]);

        // 30,000 paid last month and validated; 10,000 dated in it still waits.
        $this->signIn($accountant)->assertOk();
        $first = $pay('30000', $inMonth)->assertCreated()->json('data');
        $waiting = $pay('10000', $inMonth)->assertCreated()->json('data');
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/payments/{$first['id']}/validate", ['version' => $first['version']])->assertOk();
        $this->signIn($accountant)->assertOk();
        $this->getJson("/api/finance-periods/reconciliation?month=$month")->assertOk()->assertJsonPath('data.payments_total', '30000.00')
            ->assertJsonPath('data.pending', 1)->assertJsonPath('data.closed', false);
        $this->postJson('/api/finance-periods', ['month' => $month])->assertConflict()->assertJsonPath('code', 'period_has_pending');
        $this->postJson('/api/finance-periods', ['month' => now()->format('Y-m')])->assertConflict()->assertJsonPath('code', 'period_not_ended');
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/payments/{$waiting['id']}/validate", ['version' => $waiting['version']])->assertOk();

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
        // The finance reads the month's reconciliation on the screen and closes it.
        $within($accountant, function () use ($month) {
            Livewire::test(FinancePeriods::class)->mountTableAction('close')->setTableActionData(['month' => $month])
                ->assertMountedActionModalSee("Paiements en espèces : 2 pour 40\u{00A0}000,00")->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
        });
        $period = DB::connection('owner')->table('school.finance_period')->where('tenant_id', $school->id)->value('id');

        // AC1: nothing dated in it afterwards; a payment of today still goes.
        $this->signIn($accountant)->assertOk();
        $pay('5000', $inMonth)->assertConflict()->assertJsonPath('code', 'period_closed');
        $pay('5000', now()->toDateString())->assertCreated();
        $this->getJson('/api/finance-periods')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'closed')
            ->assertJsonPath('data.0.report.net', '40000.00')->assertJsonPath('data.0.report.payments.cash.count', 2);
        $this->postJson("/api/finance-periods/$period/reopen", ['version' => 1, 'reason' => 'Erreur'])->assertForbidden();
        $this->get('/school/finance-periods')->assertOk()->assertSee(__('finance.months.'.$last->month).' '.$last->year);

        // AC2: the direction reopens it, with its reason, journalled.
        $this->signIn($director)->assertOk();
        $this->postJson("/api/finance-periods/$period/reopen", ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $within($director, function () use ($period) {
            Livewire::test(FinancePeriods::class)->assertCanSeeTableRecords([$period])
                ->callTableAction('reopen', $period, data: ['reason' => 'Chèque mal daté'])->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
        });
        $this->assertSame(['reopened', 'Chèque mal daté', $director['id']], (array) array_values((array) DB::connection('owner')
            ->selectOne('SELECT status, reopen_reason, reopened_by FROM school.finance_period WHERE id = ?', [$period])));
        $this->assertSame(1, DB::connection('owner')->table('school.audit_log')->where('object_type', 'finance_period')->where('object_id', $period)
            ->where('action', 'UPDATE')->where('actor_id', $director['id'])->count());

        // Reopened, the month takes its dates again; closed again by the direction, the direction does not reopen its own closure.
        $this->signIn($accountant)->assertOk();
        $late = $pay('5000', $inMonth)->assertCreated()->json('data');
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/payments/{$late['id']}/validate", ['version' => $late['version']])->assertOk();
        $this->signIn($director)->assertOk();
        $again = $this->postJson('/api/finance-periods', ['month' => $month])->assertCreated()->assertJsonPath('data.report.net', '45000.00')->json('data');
        $this->postJson("/api/finance-periods/{$again['id']}/reopen", ['version' => $again['version'], 'reason' => 'Encore'])
            ->assertConflict()->assertJsonPath('code', 'segregation_of_duties');
        $this->getJson('/api/finance-periods')->assertOk()->assertJsonPath('meta.total', 2);

        // A teacher reads none of it.
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher)->assertOk();
        $this->getJson('/api/finance-periods')->assertForbidden();
        $this->get('/school/finance-periods')->assertForbidden();
    }
}
