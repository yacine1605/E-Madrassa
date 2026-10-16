<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Arrears;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Arrears (FR-FIN-06, SQL 052) through the real routes and screens: open balances and overdue
 * instalments as at a date, their totals what is owed less what is paid (AC1), an export of the
 * list, the finance's daily reminder; nothing for a teacher (AC2).
 */
final class ArrearsTest extends TestCase
{
    public function test_open_balances_and_overdue_instalments_add_up_and_remind_the_finance(): void
    {
        $manager = $this->account('arrears-manager');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addYear()->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $first = now()->subMonths(2)->toDateString();
        $second = now()->subMonth()->toDateString();
        $third = now()->addMonths(3)->toDateString();
        $termed = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => 'IMP-1', 'labels' => ['fr' => 'Impayés'], 'base_price' => '80000',
            'payment_terms' => [['code' => 'T1', 'percent' => 25, 'due_date' => $first], ['code' => 'T2', 'percent' => 25, 'due_date' => $second],
                ['code' => 'T3', 'percent' => 50, 'due_date' => $third]], 'status' => 'active'])->assertCreated()->json('data.id');
        $plain = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => 'IMP-2', 'labels' => ['fr' => 'Sans échéancier'], 'base_price' => '50000',
            'status' => 'active'])->assertCreated()->json('data.id');
        $enrol = function (string $last, string $program) {
            $pupil = $this->postJson('/api/students', ['last_name_lat' => $last, 'first_name_lat' => 'Rania', 'birth_date' => '1999-05-01'])->assertCreated()->json('data.id');
            return $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        };
        $late = $enrol('Taleb', $termed);
        $open = $enrol('Ouali', $plain);
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $validator = $this->account('validator');
        $this->member($school, $validator, 'active', ['accountant']);

        // Instalments of 20,000, 20,000 and 40,000, two fallen due; 30,000 paid: 10,000 late since the second.
        $this->signIn($accountant)->assertOk();
        $proposal = $this->getJson("/api/enrollments/$late/schedule/proposal")->assertOk()->json('data.installments');
        $this->postJson("/api/enrollments/$late/schedule", ['installments' => $proposal, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $paid = $this->postJson("/api/enrollments/$late/payments", ['amount' => '30000', 'paid_on' => now()->toDateString(), 'method_code' => 'cash',
            'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/payments/{$paid['id']}/validate", ['version' => $paid['version']])->assertOk();

        $list = $this->getJson('/api/arrears')->assertOk()->assertJsonPath('meta.total', 2)->json();
        $row = collect($list['data'])->firstWhere('enrollment_id', $late);
        $this->assertSame(['50000.00', '10000.00', $second, $third], [$row['balance'], $row['overdue'], $row['overdue_since'], $row['next_due_date']]);
        // AC1: the open balances listed are what they owe less what they paid.
        $this->assertSame(['due' => '130000.00', 'paid' => '30000.00', 'balance' => '100000.00', 'overdue' => '10000.00'], $list['meta']['totals']);
        $this->getJson('/api/arrears?overdue_only=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.enrollment_id', $late);
        $this->getJson('/api/arrears?program='.$plain)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.overdue', '0.00');
        $this->getJson('/api/arrears?as_of='.now()->subMonths(3)->toDateString())->assertOk()->assertJsonPath('meta.totals.overdue', '0.00');
        $this->getJson("/api/enrollments/$late/finance")->assertOk()->assertJsonPath('data.overdue', '10000.00')->assertJsonPath('data.overdue_since', $second);

        // The finance hears of the late instalment seven days after it, once.
        $day = now()->subMonth()->addDays(7)->toDateString();
        $this->artisan('platform:finance-reminders', ['--day' => $day])->assertSuccessful();
        $this->artisan('platform:finance-reminders', ['--day' => $day])->expectsOutput('Finance reminders notified: 0.')->assertSuccessful();
        $notes = collect($this->getJson('/api/notifications')->assertOk()->json('data'))->where('event', 'finance.installments_due')->values();
        $this->assertCount(1, $notes);
        $this->assertSame(['after_7' => 1], $notes[0]['data']['counts']);

        // The screen lists them with their totals and exports them.
        $this->get('/school/arrears')->assertOk()->assertSee('Taleb Rania')->assertSee("100\u{00A0}000,00");
        $this->actingAs($this->person($validator));
        $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($school, $validator, $late) {
            app(CurrentSchool::class)->enter($school->id, $validator['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            Livewire::test(Arrears::class)->filterTable('overdue_only', true)->assertCanSeeTableRecords([$late])->assertCountTableRecords(1)
                ->callTableAction('export', data: ['format' => 'csv'])->assertNotified();
        });
        $this->assertSame(1, DB::connection('owner')->table('school.export_log')->where('tenant_id', $school->id)->where('kind', 'arrears')->value('row_count'));

        // AC2: a teacher reads none of it.
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher)->assertOk();
        $this->getJson('/api/arrears')->assertForbidden();
        $this->get('/school/arrears')->assertForbidden();
    }
}
