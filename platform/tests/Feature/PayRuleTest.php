<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Staff;
use App\Filament\School\Pages\StaffPayRules;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The remuneration rules (FR-REM-01, SQL 058) through the real routes and screens: versions from the
 * first day of a month, of a kind the member's type allows (AC3), complete (AC6, AC7), one rate per
 * node of the referential (AC8), the most specific winning (AC5); a change is a new version from
 * this month or one to come, the months before kept (AC1, AC4); written by `payroll.rule.manage`,
 * read with `payroll.amount.read`, never by academic_hr (AC2).
 */
final class PayRuleTest extends TestCase
{
    public function test_a_member_is_paid_by_versions_from_the_first_day_of_a_month_and_academic_hr_never_reads_them(): void
    {
        $owner = $this->account('pay-rules-owner');
        $school = $this->school('complete', $owner, 'private_school_dz');
        $hr = $this->account('hr');
        $this->member($school, $hr, 'active', ['academic_hr']);
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $director = $this->account('director');
        $this->member($school, $director, 'active', ['direction']);

        $this->signIn($hr)->assertOk();
        $teacher = $this->postJson('/api/staff', ['last_name_lat' => 'Mansouri', 'first_name_lat' => 'Nadia', 'staff_type' => 'teacher_internal'])->assertCreated()->json('data.id');
        $clerk = $this->postJson('/api/staff', ['last_name_lat' => 'Belkacem', 'first_name_lat' => 'Omar', 'staff_type' => 'administrative'])->assertCreated()->json('data.id');
        // AC2: academic_hr never reads them.
        $this->getJson("/api/staff/$teacher/pay-rules")->assertForbidden();
        $this->get('/school/staff-pay-rules?staff='.$teacher)->assertForbidden();

        $node = fn (string $code) => DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)->where('code', $code)->value('id');
        $month = fn (int $k) => now()->startOfMonth()->addMonths($k)->toDateString();
        $save = fn (string $staff, array $rule) => $this->postJson("/api/staff/$staff/pay-rules", ['idempotency_key' => (string) Str::uuid(), ...$rule]);
        $this->signIn($accountant)->assertOk();
        // AC3: the administrative staff on a fixed salary only.
        $save($clerk, ['kind' => 'hourly', 'valid_from' => $month(-2), 'rates' => [['unit_amount' => '1500']]])->assertConflict()->assertJsonPath('code', 'pay_rule_kind_refused');
        $save($clerk, ['kind' => 'fixed_monthly', 'monthly_amount' => '45000', 'valid_from' => $month(-2), 'bonuses' => [['label' => 'Prime de transport', 'amount' => '3000']]])
            ->assertCreated()->assertJsonPath('data.monthly_amount', '45000.00')->assertJsonPath('data.bonuses.0.amount', '3000.00');
        // AC6, AC7, AC8: what each kind asks, one rate per node.
        $save($teacher, ['kind' => 'mixed', 'monthly_amount' => '40000', 'valid_from' => $month(-3), 'rates' => [['unit_amount' => '1500']]])
            ->assertConflict()->assertJsonPath('code', 'pay_rule_incomplete');
        $save($teacher, ['kind' => 'fixed_monthly', 'monthly_amount' => '60000', 'valid_from' => $month(-3), 'rates' => [['unit_amount' => '1500']]])
            ->assertConflict()->assertJsonPath('code', 'pay_rule_rate_refused');
        $save($teacher, ['kind' => 'hourly', 'valid_from' => $month(-3), 'rates' => [['unit_amount' => '1500'], ['node_id' => $node('SECONDAIRE'), 'unit_amount' => '2000'],
            ['node_id' => $node('SECONDAIRE'), 'unit_amount' => '2100']]])->assertConflict()->assertJsonPath('code', 'pay_rule_rate_duplicate');
        $hourly = $save($teacher, ['kind' => 'hourly', 'valid_from' => $month(-3), 'rates' => [['unit_amount' => '1500'], ['node_id' => $node('SECONDAIRE'), 'unit_amount' => '2000']]])
            ->assertCreated()->assertJsonPath('data.state', 'in_force')->assertJsonPath('data.rates.0.node_id', null)->json('data');
        // AC5: 2AS takes the Secondary rate, 4AM the default.
        $this->getJson("/api/pay-rules/{$hourly['id']}/rate?node=".$node('2AS'))->assertOk()->assertJsonPath('data.unit_amount', '2000.00');
        $this->getJson("/api/pay-rules/{$hourly['id']}/rate?node=".$node('4AM'))->assertOk()->assertJsonPath('data.unit_amount', '1500.00');
        // AC1, AC4: a change from this month or one to come, never before; the months before kept.
        $save($teacher, ['kind' => 'hourly', 'valid_from' => $month(-1), 'rates' => [['unit_amount' => '1600']]])->assertConflict()->assertJsonPath('code', 'pay_rule_retroactive');
        $save($teacher, ['kind' => 'fixed_monthly', 'monthly_amount' => '60000', 'valid_from' => $month(1)])->assertCreated()->assertJsonPath('data.state', 'upcoming');
        $this->getJson("/api/staff/$teacher/pay-rules")->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.1.valid_to', now()->endOfMonth()->toDateString());
        $this->getJson("/api/pay-rules/{$hourly['id']}/rate?node=".$node('2AS'))->assertOk()->assertJsonPath('data.unit_amount', '2000.00');
        $version = $this->getJson("/api/staff/$teacher/pay-rules")->json('data.1.version');
        $this->deleteJson("/api/pay-rules/{$hourly['id']}", ['version' => $version])->assertConflict()->assertJsonPath('code', 'pay_rule_started');

        // AC2: the direction reads them, never writes them.
        $this->signIn($director)->assertOk();
        $this->getJson("/api/staff/$teacher/pay-rules")->assertOk()->assertJsonPath('meta.total', 2);
        $save($teacher, ['kind' => 'fixed_monthly', 'monthly_amount' => '70000', 'valid_from' => $month(2)])->assertForbidden();

        // On the screen: a version to come added, then deleted, the one before in force again.
        $this->actingAs($this->person($accountant));
        $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($school, $accountant, $teacher, $month) {
            app(CurrentSchool::class)->enter($school->id, $accountant['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            Livewire::test(Staff::class)->assertTableActionVisible('pay_rules', $teacher);
            $rules = fn () => Livewire::withQueryParams(['staff' => $teacher])->test(StaffPayRules::class);
            $rules()->callTableAction('add', data: ['kind' => 'per_session', 'valid_from' => $month(2), 'rates' => [['node_id' => null, 'unit_amount' => '3 000,00']],
                'bonuses' => []])->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $next = DB::table('school.pay_rule')->where('staff_id', $teacher)->where('kind', 'per_session')->first();
            $this->assertSame('3000.00', DB::table('school.pay_rule_rate')->where('pay_rule_id', $next->id)->value('unit_amount'));
            $rules()->assertCanSeeTableRecords([$next->id])->callTableAction('delete', $next->id)->assertNotified(__('school.saved'));
            $this->assertNull(DB::table('school.pay_rule')->where('staff_id', $teacher)->where('kind', 'fixed_monthly')->value('valid_to'));
        });
    }
}
