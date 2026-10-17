<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Payroll;
use App\Filament\School\Pages\PayrollRun;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The monthly pays (FR-REM-02, FR-REM-03, SQL 059) through the real routes and screens: one per
 * member and month however often generated (AC1, TST-12), the rate of each unit's level (AC5), a
 * fixed salary with its bonus (AC4), one-off bonuses and adjustments with their reason; validated by
 * someone else than whoever computed it (BR-08), then frozen, corrected by an adjustment of a later
 * month linked to it (FR-REM-03 AC1); never read by academic_hr.
 */
final class PayrollRunTest extends TestCase
{
    public function test_a_month_is_paid_once_per_member_validated_by_someone_else_and_corrected_by_a_later_adjustment(): void
    {
        $owner = $this->account('payroll-owner');
        $school = $this->school('complete', $owner, 'private_school_dz');
        $hr = $this->account('hr');
        $this->member($school, $hr, 'active', ['academic_hr']);
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $director = $this->account('director');
        $this->member($school, $director, 'active', ['direction']);
        $both = $this->account('both');
        $this->member($school, $both, 'active', ['accountant', 'direction']);

        $this->signIn($hr)->assertOk();
        $teacher = $this->postJson('/api/staff', ['last_name_lat' => 'Mansouri', 'first_name_lat' => 'Nadia', 'staff_type' => 'teacher_internal'])->assertCreated()->json('data.id');
        $clerk = $this->postJson('/api/staff', ['last_name_lat' => 'Belkacem', 'first_name_lat' => 'Omar', 'staff_type' => 'administrative'])->assertCreated()->json('data.id');
        $month = fn (int $k) => now()->startOfMonth()->addMonths($k)->toDateString();
        $day = fn (int $k, int $d) => now()->startOfMonth()->addMonths($k)->addDays($d - 1)->toDateString();
        // Never read by academic_hr.
        $this->getJson('/api/payroll-runs?period='.$month(-1))->assertForbidden();
        $this->get('/school/payroll')->assertForbidden();

        $node = fn (string $code) => DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)->where('code', $code)->value('id');
        $this->signIn($accountant)->assertOk();
        $this->postJson("/api/staff/$teacher/pay-rules", ['idempotency_key' => (string) Str::uuid(), 'kind' => 'hourly', 'valid_from' => $month(-3),
            'rates' => [['unit_amount' => '1500'], ['node_id' => $node('SECONDAIRE'), 'unit_amount' => '2000']]])->assertCreated();
        $this->postJson("/api/staff/$clerk/pay-rules", ['idempotency_key' => (string) Str::uuid(), 'kind' => 'fixed_monthly', 'monthly_amount' => '45000',
            'valid_from' => $month(-3), 'bonuses' => [['label' => 'Prime de transport', 'amount' => '3000']]])->assertCreated();

        // AC1, TST-12: generated again, never twice; a month to come refused.
        $this->postJson('/api/payroll-runs/generate', ['period' => $month(1)])->assertConflict()->assertJsonPath('code', 'payroll_period_future');
        $this->postJson('/api/payroll-runs/generate', ['period' => $month(-1)])->assertOk()->assertJsonPath('data.generated', 2);
        $this->postJson('/api/payroll-runs/generate', ['period' => $month(-1)])->assertOk()->assertJsonPath('data.generated', 2);
        $this->getJson('/api/payroll-runs?period='.$month(-1))->assertOk()->assertJsonPath('meta.total', 2);
        // The teaching and the administrative staff told apart; AC4: a fixed salary with its recurring bonus.
        $clerkRun = $this->getJson('/api/payroll-runs?group=administrative&period='.$month(-1))->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.net_amount', '48000.00')->json('data.0');
        $run = $this->getJson('/api/payroll-runs?group=teaching&period='.$month(-1))->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $this->postJson("/api/payroll-runs/{$clerkRun['id']}/units", ['version' => $clerkRun['version'], 'units' => [['worked_on' => $day(-1, 2), 'quantity' => '3']]])
            ->assertConflict()->assertJsonPath('code', 'payroll_units_refused');
        // AC5: 10 h of 2AS at the Secondary rate, 8 h of 4AM at the default; each line with the copy of its level and rate.
        $detail = $this->postJson("/api/payroll-runs/{$run['id']}/units", ['version' => $run['version'], 'units' => [
            ['node_id' => $node('2AS'), 'worked_on' => $day(-1, 3), 'quantity' => '10'], ['node_id' => $node('4AM'), 'worked_on' => $day(-1, 4), 'quantity' => '8']]])
            ->assertOk()->assertJsonPath('data.net_amount', '32000.00')->assertJsonPath('data.lines.0.node_code', '2AS')
            ->assertJsonPath('data.lines.0.unit_amount', '2000.00')->assertJsonPath('data.lines.1.amount', '12000.00')->json('data');
        $this->postJson("/api/payroll-runs/{$run['id']}/units", ['version' => $detail['version'], 'units' => [['worked_on' => $day(-2, 3), 'quantity' => '1']]])
            ->assertConflict()->assertJsonPath('code', 'payroll_unit_date');
        // FR-REM-03: a one-off bonus with its reason, once per key.
        $key = (string) Str::uuid();
        $bonus = ['kind' => 'bonus', 'amount' => '1500', 'reason' => 'Examens blancs', 'idempotency_key' => $key];
        $this->postJson("/api/payroll-runs/{$run['id']}/adjustments", $bonus)->assertCreated()->assertJsonPath('data.net_amount', '33500.00');
        $this->postJson("/api/payroll-runs/{$run['id']}/adjustments", $bonus)->assertCreated()->assertJsonPath('data.net_amount', '33500.00');
        $this->postJson("/api/payroll-runs/{$run['id']}/adjustments", ['amount' => '-100'] + $bonus)->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');
        // The accountant computes, never validates.
        $current = $this->getJson("/api/payroll-runs/{$run['id']}")->assertOk()->assertJsonPath('data.adjustments.0.reason', 'Examens blancs')->json('data');
        $this->postJson("/api/payroll-runs/{$run['id']}/validate", ['version' => $current['version']])->assertForbidden();

        // BR-08: whoever computed it never validates it, even holding both rights.
        $this->signIn($both)->assertOk();
        $this->postJson('/api/payroll-runs/generate', ['period' => $month(-1), 'staff_id' => $clerk])->assertOk()->assertJsonPath('data.generated', 1);
        $this->postJson("/api/payroll-runs/{$clerkRun['id']}/adjustments", ['kind' => 'adjustment', 'amount' => '-2000', 'reason' => 'Absence non justifiée',
            'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.net_amount', '46000.00');
        $clerkVersion = $this->getJson("/api/payroll-runs/{$clerkRun['id']}")->json('data.version');
        $this->postJson("/api/payroll-runs/{$clerkRun['id']}/validate", ['version' => $clerkVersion])->assertConflict()->assertJsonPath('code', 'segregation_of_duties');

        // The direction validates it: frozen then (AC2).
        $this->signIn($director)->assertOk();
        $this->postJson("/api/payroll-runs/{$run['id']}/validate", ['version' => $current['version'] - 1])->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->postJson("/api/payroll-runs/{$run['id']}/validate", ['version' => $current['version']])->assertOk()->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.validator', $this->person($director)->name)->assertJsonPath('data.net_amount', '33500.00');
        $this->postJson('/api/payroll-runs/generate', ['period' => $month(-1)])->assertForbidden();

        // FR-REM-03 AC1: a validated pay never changes; its correction is a traced adjustment of a later month linked to it.
        $this->signIn($accountant)->assertOk();
        $validated = $this->getJson("/api/payroll-runs/{$run['id']}")->json('data');
        $this->postJson("/api/payroll-runs/{$run['id']}/units", ['version' => $validated['version'], 'units' => []])->assertConflict()->assertJsonPath('code', 'payroll_run_validated');
        $this->postJson('/api/payroll-runs/generate', ['period' => $month(-1), 'staff_id' => $teacher])->assertConflict()->assertJsonPath('code', 'payroll_run_validated');
        $this->postJson('/api/payroll-runs/generate', ['period' => $month(-1)])->assertOk()->assertJsonPath('data.generated', 1);
        $this->postJson('/api/payroll-runs/generate', ['period' => $month(0), 'staff_id' => $teacher])->assertOk()->assertJsonPath('data.generated', 1);
        $later = $this->getJson('/api/payroll-runs?group=teaching&period='.$month(0))->json('data.0');
        $this->getJson("/api/payroll-runs/{$later['id']}/corrected")->assertOk()->assertJsonPath('data.0.id', $run['id']);
        $this->postJson("/api/payroll-runs/{$later['id']}/adjustments", ['kind' => 'adjustment', 'amount' => '-1000', 'reason' => 'Heures comptées deux fois',
            'corrects_run_id' => $later['id'], 'idempotency_key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'payroll_correction_invalid');
        $this->postJson("/api/payroll-runs/{$later['id']}/adjustments", ['kind' => 'adjustment', 'amount' => '-1000', 'reason' => 'Heures comptées deux fois',
            'corrects_run_id' => $run['id'], 'idempotency_key' => (string) Str::uuid()])->assertCreated()
            ->assertJsonPath('data.adjustments.0.corrects_period', $month(-1))->assertJsonPath('data.adjustment_amount', '-1000.00');
        $this->getJson("/api/payroll-runs/{$run['id']}")->assertJsonPath('data.net_amount', '33500.00')->assertJsonPath('data.lines.0.unit_amount', '2000.00');

        // On the screens: the units of the month entered, then the pay validated by the direction from the month's list.
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
        $screen($accountant, function () use ($later, $node, $day) {
            Livewire::withQueryParams(['run' => $later['id']])->test(PayrollRun::class)->assertActionHidden('validate')
                ->callAction('units', data: ['units' => [['node_id' => $node('2AS'), 'worked_on' => $day(0, 1), 'quantity' => '2,5']]])
                ->assertHasNoFormErrors()->assertNotified(__('school.saved'));
            $this->assertSame('4000.00', DB::table('school.payroll_run')->where('id', $later['id'])->value('net_amount'));
            Livewire::withQueryParams(['run' => $later['id']])->test(PayrollRun::class)
                ->callAction('adjust', data: ['kind' => 'bonus', 'amount' => '1 000', 'reason' => 'Surveillance des examens'])->assertHasNoFormErrors()
                ->assertNotified(__('school.saved'));
            $this->assertSame('5000.00', DB::table('school.payroll_run')->where('id', $later['id'])->value('net_amount'));
        });
        $screen($director, function () use ($later, $month) {
            Livewire::withQueryParams(['period' => $month(0)])->test(Payroll::class)->assertCanSeeTableRecords([$later['id']])
                ->assertTableActionHidden('generate')->callTableAction('validate', $later['id'])->assertNotified(__('school.saved'));
            $this->assertSame('validated', DB::table('school.payroll_run')->where('id', $later['id'])->value('status'));
            Livewire::withQueryParams(['run' => $later['id']])->test(PayrollRun::class)->assertSee('Surveillance des examens')
                ->assertActionHidden('units')->assertActionHidden('validate');
        });
    }
}
