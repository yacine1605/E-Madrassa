<?php

namespace Tests\Feature;

use App\Filament\School\Pages\FinanceDashboard;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The finance dashboard (FR-TDB-01, TST-16): a month's net receipts the sum of its payments validated
 * less their reversals; each figure's operations listed with the reader's rights; read by
 * `dashboard.finance.read` only.
 */
final class FinanceDashboardTest extends TestCase
{
    public function test_the_net_receipts_are_the_payments_validated_less_their_reversals_and_their_sources_are_listed(): void
    {
        $manager = $this->account('dashboard-manager');
        $school = $this->school('complete', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => 'TDB-1', 'labels' => ['fr' => 'Tableau'], 'base_price' => '100000',
            'status' => 'active'])->assertCreated()->json('data.id');
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Kaci', 'first_name_lat' => 'Yasmine', 'birth_date' => '1996-03-01'])->assertCreated()->json('data.id');
        $enrollment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $validator = $this->account('validator');
        $this->member($school, $validator, 'active', ['accountant']);
        $month = now()->startOfMonth()->toDateString();

        // The manager reads no finance dashboard.
        $this->getJson("/api/dashboards/finance?from=$month&to=$month")->assertForbidden();
        $this->get('/school/finance-dashboard')->assertForbidden();
        // Nor exports it, though they export other lists.
        $this->postJson('/api/exports', ['kind' => 'finance_dashboard', 'format' => 'csv', 'filters' => ['from' => $month, 'to' => $month]])->assertForbidden();
        // Two payments, one validated, then reversed in part by a validated reversal; one waiting.
        $this->signIn($accountant)->assertOk();
        $pay = fn (string $amount) => $this->postJson("/api/enrollments/$enrollment/payments", ['amount' => $amount, 'paid_on' => now()->toDateString(),
            'method_code' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $first = $pay('30000');
        $pending = $pay('5000');
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/payments/{$first['id']}/validate", ['version' => $first['version']])->assertOk();
        // TST-16: the month's net receipts = payments validated − reversals − refunds; the one waiting not counted.
        $result = $this->getJson("/api/dashboards/finance?from=$month&to=$month")->assertOk()->json();
        $this->assertSame('30000.00', $result['data'][0]['payments']);
        $this->assertSame('30000.00', $result['data'][0]['net_receipts']);
        $this->assertSame(1, $result['data'][0]['payments_count']);
        $this->assertSame(['student_finance', 'expenses', 'payroll'], $result['meta']['modules']);
        $sources = $this->getJson("/api/dashboards/finance/sources?indicator=payments&month=$month")->assertOk()->json('data');
        $this->assertSame([$first['reference']], array_column($sources, 'reference'));
        $this->assertNotContains($pending['reference'], array_column($sources, 'reference'));
        $this->getJson("/api/dashboards/finance/programs?from=$month&to=$month")->assertOk()->assertJsonPath('data.0.code', 'TDB-1')
            ->assertJsonPath('data.0.received', '30000.00')->assertJsonPath('data.0.direct_costs', null);
        $this->getJson("/api/dashboards/finance?from=$month&to=".now()->addYears(2)->toDateString())->assertConflict()->assertJsonPath('code', 'dashboard_range_invalid');
        // FR-TDB-03: exported as it shows, the month's figures in their columns.
        $export = $this->postJson('/api/exports', ['kind' => 'finance_dashboard', 'format' => 'csv', 'filters' => ['from' => $month, 'to' => $month]])
            ->assertCreated()->json('data');
        $this->assertSame(1, $export['rows']);
        $this->assertSame(['month', 'billed', 'payments', 'reversals', 'refunds', 'net_receipts', 'expenses_committed', 'expenses_paid', 'salaries_committed',
            'salaries_paid', 'net_result'], $export['columns']);
        $this->assertStringContainsString('30000.00', $this->get("/api/files/{$export['file']['id']}/content")->assertOk()->streamedContent());
        $this->postJson('/api/exports', ['kind' => 'finance_dashboard', 'format' => 'pdf', 'filters' => ['from' => $month, 'to' => $month]])->assertCreated();
        // The screen.
        $this->get('/school/finance-dashboard')->assertOk()->assertSee(__('dashboard.indicators.net_receipts'));
        $this->actingAs($this->person($validator));
        $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($school, $validator, $month, $first) {
            app(CurrentSchool::class)->enter($school->id, $validator['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            Livewire::test(FinanceDashboard::class)->assertSee("30\u{00A0}000,00")->mountTableAction('sources', $month)->setTableActionData(['indicator' => 'payments'])
                ->assertMountedActionModalSee($first['reference']);
            Livewire::test(FinanceDashboard::class)->callTableAction('export', data: ['format' => 'xlsx'])->assertNotified();
        });
        // The screen's own period: this month and the five before it.
        $this->assertSame(6, DB::connection('owner')->table('school.export_log')->where('tenant_id', $school->id)->where('kind', 'finance_dashboard')
            ->where('format', 'xlsx')->value('row_count'));
    }
}
