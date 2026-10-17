<?php

namespace Tests\Feature;

use App\Filament\School\Pages\DeliveredHours;
use App\Filament\School\Pages\TeacherAttendance;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * The hours delivered (FR-PTG-03, FR-REM-02 lot 4): a month's hours are the sum of its validated
 * sessions, which its pay counts (AC1); a validated entry corrected by a trace, the entry itself
 * unchanged (AC2).
 */
final class DeliveredHoursTest extends TestCase
{
    use PlansSessions;

    private function within(object $school, array $account, callable $test): void
    {
        $this->actingAs($this->person($account));
        $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($school, $account, $test) {
            app(CurrentSchool::class)->enter($school->id, $account['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            $test();
        });
    }

    public function test_the_validated_sessions_make_the_hours_the_pay_counts_and_a_correction_is_traced(): void
    {
        $s = $this->setting('complete');
        $session = $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'room_id' => $s['room'],
            'day' => $this->next(Carbon::SUNDAY), 'start_time' => '08:00', 'end_time' => '08:50'])->assertCreated()->json('data.id');
        // Moved to the hours just gone: a session held.
        $owner = DB::connection('owner');
        $owner->unprepared('ALTER TABLE school.class_session DISABLE TRIGGER USER');
        $owner->table('school.class_session')->where('id', $session)->update(['starts_at' => now()->subMinutes(120), 'ends_at' => now()->subMinutes(70)]);
        $owner->unprepared('ALTER TABLE school.class_session ENABLE TRIGGER USER');
        $day = $this->getJson("/api/class-sessions/$session")->json('data.day');
        [$from, $to] = [Carbon::parse($day)->startOfMonth()->toDateString(), Carbon::parse($day)->endOfMonth()->toDateString()];
        $supervisor = $this->account('surveillant');
        $this->member($s['school'], $supervisor, 'active', ['supervisor']);
        $accountant = $this->account('comptable');
        $this->member($s['school'], $accountant, 'active', ['accountant']);
        $this->signIn($accountant)->assertOk();
        $this->postJson("/api/staff/{$s['teacher']}/pay-rules", ['idempotency_key' => (string) Str::uuid(), 'kind' => 'hourly',
            'valid_from' => Carbon::parse($from)->subMonth()->toDateString(), 'rates' => [['unit_amount' => '1500']]])->assertCreated();
        $this->signIn($supervisor)->assertOk();
        $entry = $this->postJson('/api/teacher-attendance', ['session_id' => $session, 'status' => 'late', 'late_minutes' => 10])->assertCreated()->json('data');
        $this->getJson("/api/delivered-hours?from=$from&to=$to")->assertForbidden();
        // Nothing before validation; then 40 minutes held.
        $this->signIn($s['manager'])->assertOk();
        $this->getJson("/api/delivered-hours?from=$from&to=$to")->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/teacher-attendance/{$entry['attendance_id']}/validate", ['version' => 1])->assertOk();
        $this->getJson("/api/delivered-hours?from=$from&to=$to&staff_id={$s['teacher']}")->assertOk()->assertJsonPath('data.0.sessions', 1)
            ->assertJsonPath('data.0.hours', '0.67')->assertJsonPath('sessions.0.session_id', $session);
        // AC1: the month's pay counts them.
        $this->signIn($accountant)->assertOk();
        $this->postJson('/api/payroll-runs/generate', ['period' => $from])->assertOk();
        $run = $this->getJson("/api/payroll-runs?group=teaching&period=$from")->assertOk()->json('data.0');
        $this->assertSame('1005.00', $run['net_amount']);
        // AC2: corrected by a trace, the entry itself unchanged; the pay's draft follows.
        $this->signIn($s['manager'])->assertOk();
        $this->postJson("/api/teacher-attendance/{$entry['attendance_id']}/corrections", ['status' => 'late', 'late_minutes' => 10, 'reason' => 'Rien'])
            ->assertConflict()->assertJsonPath('code', 'attendance_correction_same');
        $this->postJson("/api/teacher-attendance/{$entry['attendance_id']}/corrections", ['status' => 'present', 'reason' => 'Registre signé à l’heure'])
            ->assertCreated()->assertJsonPath('data.status', 'present')->assertJsonPath('data.validation_status', 'validated');
        $this->getJson("/api/teacher-attendance/{$entry['attendance_id']}/corrections")->assertOk()->assertJsonPath('data.0.previous.status', 'late')
            ->assertJsonPath('data.0.previous.late_minutes', 10)->assertJsonPath('data.0.reason', 'Registre signé à l’heure');
        $this->assertSame('late', $owner->table('school.teacher_attendance')->where('id', $entry['attendance_id'])->value('status'));
        $this->signIn($accountant)->assertOk();
        $this->postJson('/api/payroll-runs/generate', ['period' => $from])->assertOk();
        $this->assertSame('1245.00', $this->getJson("/api/payroll-runs?group=teaching&period=$from")->json('data.0.net_amount'));
        // The screens: the correction on the attendance, the hours by teacher.
        $this->signIn($s['manager'])->assertOk();
        $this->get('/school/delivered-hours')->assertOk();
        $this->within($s['school'], $s['manager'], function () use ($day, $session) {
            Livewire::test(DeliveredHours::class)->filterTable('month', ['month' => $day])->assertSee('Meziane Karim')->assertSee('0,83');
            Livewire::test(TeacherAttendance::class)->filterTable('day', ['day' => $day, 'span' => 'day'])->assertSee(__('attendance.corrected'))
                ->assertTableActionVisible('amend', $session);
        });
    }
}
