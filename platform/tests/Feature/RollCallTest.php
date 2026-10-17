<?php

namespace Tests\Feature;

use App\Filament\School\Pages\RollCall;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * The pupils' roll call (FR-PRE-01): a teacher calls the pupils of their own session's group;
 * a roll not called is no absence; a change keeps the former value (AC2).
 */
final class RollCallTest extends TestCase
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

    public function test_a_teacher_calls_their_session_and_a_change_keeps_the_former_value(): void
    {
        $s = $this->setting();
        $pupils = [];
        foreach (['Amine', 'Lina'] as $name) {
            $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => $name, 'birth_date' => '1990-01-01'])->assertCreated()->json('data.id');
            $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])
                ->assertCreated()->json('data.id');
            $this->postJson("/api/groups/{$s['group']}/memberships", ['enrollment_id' => $enrolment])->assertCreated();
            $pupils[] = $pupil;
        }
        $session = $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'room_id' => $s['room'],
            'day' => $this->next(Carbon::SUNDAY), 'start_time' => '08:00', 'end_time' => '08:50'])->assertCreated()->json('data.id');
        $owner = DB::connection('owner');
        $owner->unprepared('ALTER TABLE school.class_session DISABLE TRIGGER USER');
        $owner->table('school.class_session')->where('id', $session)->update(['starts_at' => now()->subMinutes(60), 'ends_at' => now()->subMinutes(10)]);
        $owner->unprepared('ALTER TABLE school.class_session ENABLE TRIGGER USER');
        $day = $this->getJson("/api/class-sessions/$session")->json('data.day');
        $karim = $this->account('karim');
        $this->member($s['school'], $karim, 'active');
        $this->grant($s['school'], $karim, 'teacher', 'personal');
        $record = $this->getJson("/api/staff/{$s['teacher']}")->json('data');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $karim['id'], 'version' => $record['version']])->assertOk();

        $this->signIn($karim)->assertOk();
        $this->getJson("/api/roll-calls?day=$day")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.pupils', 2)->assertJsonPath('data.0.marked', 0);
        // The pupils of the group, none marked: no absence.
        $this->getJson("/api/class-sessions/$session/roll-call")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', null);
        $this->postJson("/api/class-sessions/$session/roll-call", ['entries' => [['student_id' => $pupils[0], 'status' => 'absent']]])->assertOk()
            ->assertJsonPath('data.0.status', 'absent')->assertJsonPath('data.1.status', null);
        // AC2: changed, the former value kept.
        $this->postJson("/api/class-sessions/$session/roll-call", ['entries' => [['student_id' => $pupils[0], 'status' => 'late', 'late_minutes' => 15],
            ['student_id' => $pupils[1], 'status' => 'present']]])->assertOk()->assertJsonPath('data.0.status', 'late')->assertJsonPath('data.1.status', 'present');
        $this->getJson("/api/class-sessions/$session/roll-call/history")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.previous_status', 'absent')->assertJsonPath('data.0.status', 'late')->assertJsonPath('data.0.changed_by_name', $this->person($karim)->name);
        // The screen calls the roll.
        $this->get('/school/roll-call')->assertOk();
        $this->within($s['school'], $karim, function () use ($session, $pupils, $day) {
            Livewire::test(RollCall::class)->filterTable('day', ['day' => $day])->assertSee('2 / 2')
                ->callTableAction('call', $session, data: ['p_'.$pupils[0] => 'excused', 'p_'.$pupils[1] => 'present'])->assertHasNoTableActionErrors()
                ->assertNotified(__('school.saved'));
        });
        $this->assertSame('excused', $owner->table('school.student_attendance')->where('session_id', $session)->where('student_id', $pupils[0])->value('status'));
        // The manager, who calls no roll, has no such screen.
        $this->signIn($s['manager'])->assertOk();
        $this->get('/school/roll-call')->assertForbidden();
    }
}
