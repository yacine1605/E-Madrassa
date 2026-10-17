<?php

namespace Tests\Feature;

use App\Filament\School\Pages\TeacherAttendance;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * The teachers' attendance (FR-PTG-01, FR-PTG-02): a session begun entered by the supervisor, a
 * session to come refused (AC2), a session not entered never an absence (AC1); validated or
 * rejected by another person only, one who holds both rights validating the others' entries and
 * never their own, with the rule said (TST-05, FR-IAM-05 AC1, AC2).
 */
final class TeacherAttendanceTest extends TestCase
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

    /** Sessions planned next Sunday, then moved to the hours just gone: sessions begun today. */
    private function begun(array $s, int $count): array
    {
        $day = $this->next(Carbon::SUNDAY);
        $ids = [];
        foreach (array_slice(['08:00', '09:00', '10:00', '11:00'], 0, $count) as $i => $start) {
            $ids[] = $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'room_id' => $s['room'],
                'day' => $day, 'start_time' => $start, 'end_time' => substr($start, 0, 3).'50'])->assertCreated()->json('data.id');
        }
        $owner = DB::connection('owner');
        $owner->unprepared('ALTER TABLE school.class_session DISABLE TRIGGER USER');
        foreach ($ids as $i => $id) {
            $owner->table('school.class_session')->where('id', $id)->update(['starts_at' => now()->subMinutes(240 - 50 * $i), 'ends_at' => now()->subMinutes(200 - 50 * $i)]);
        }
        $owner->unprepared('ALTER TABLE school.class_session ENABLE TRIGGER USER');
        return $ids;
    }

    public function test_the_supervisor_enters_and_another_person_validates_never_their_own(): void
    {
        $s = $this->setting();
        [$first, $second, $third] = $this->begun($s, 3);
        $coming = $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'room_id' => $s['room'],
            'day' => $this->next(Carbon::MONDAY), 'start_time' => '08:00', 'end_time' => '09:00'])->assertCreated()->json('data.id');
        $supervisor = $this->account('surveillant');
        $this->member($s['school'], $supervisor, 'active', ['supervisor']);
        $both = $this->account('cumul');
        $this->member($s['school'], $both, 'active', ['supervisor', 'academic_hr']);
        $today = now()->toDateString();
        // AC1: listed « not entered », no absence.
        $this->getJson("/api/teacher-attendance?from=$today&to=$today")->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.attendance_id', null)
            ->assertJsonPath('data.0.status', null);
        $this->signIn($supervisor)->assertOk();
        // AC2: a session to come is refused.
        $this->postJson('/api/teacher-attendance', ['session_id' => $coming, 'status' => 'present'])->assertConflict()->assertJsonPath('code', 'attendance_future');
        $mine = $this->postJson('/api/teacher-attendance', ['session_id' => $first, 'status' => 'late', 'late_minutes' => 10, 'note' => 'Bus en retard'])
            ->assertCreated()->assertJsonPath('data.validation_status', 'submitted')->assertJsonPath('data.status', 'late')->json('data');
        $this->postJson('/api/teacher-attendance', ['session_id' => $first, 'status' => 'present'])->assertConflict()->assertJsonPath('code', 'attendance_exists');
        $this->postJson('/api/teacher-attendance', ['session_id' => $second, 'status' => 'late'])->assertConflict()->assertJsonPath('code', 'attendance_late_minutes');
        // The supervisor validates nothing.
        $this->postJson("/api/teacher-attendance/{$mine['attendance_id']}/validate", ['version' => 1])->assertForbidden();
        // TST-05: one holding both roles enters, then validates the others' entries only, the rule said.
        $this->signIn($both)->assertOk();
        $theirs = $this->postJson('/api/teacher-attendance', ['session_id' => $second, 'status' => 'present'])->assertCreated()->json('data');
        $this->postJson("/api/teacher-attendance/{$theirs['attendance_id']}/validate", ['version' => 1])->assertConflict()
            ->assertJsonPath('code', 'segregation_of_duties')->assertJsonPath('message', __('errors.segregation_of_duties'));
        $this->putJson("/api/teacher-attendance/{$mine['attendance_id']}", ['status' => 'present', 'version' => 1])->assertConflict()
            ->assertJsonPath('code', 'attendance_not_author');
        $this->postJson("/api/teacher-attendance/{$mine['attendance_id']}/validate", ['version' => 1])->assertOk()->assertJsonPath('data.validation_status', 'validated')
            ->assertJsonPath('data.session_status', 'delivered');
        // The manager rejects with its reason; its author is told, corrects, the manager validates.
        $this->signIn($s['manager'])->assertOk();
        $this->postJson("/api/teacher-attendance/{$theirs['attendance_id']}/reject", ['reason' => 'Registre signé à 8 h 20', 'version' => 1])->assertOk()
            ->assertJsonPath('data.validation_status', 'rejected');
        $this->assertSame(1, DB::connection('owner')->table('school.notification')->where('user_id', $both['id'])->where('event', 'attendance.teacher_rejected')->count());
        $this->signIn($both)->assertOk();
        $this->putJson("/api/teacher-attendance/{$theirs['attendance_id']}", ['status' => 'late', 'late_minutes' => 20, 'version' => 2])->assertOk()
            ->assertJsonPath('data.validation_status', 'submitted');
        // The period validated at once by the manager: the submitted entries of others.
        $this->signIn($s['manager'])->assertOk();
        $this->postJson('/api/teacher-attendance/validate-period', ['from' => $today, 'to' => $today])->assertOk()->assertJsonPath('data.validated', 1)
            ->assertJsonPath('data.own_left', 0);
        $this->getJson("/api/teacher-attendance?from=$today&to=$today&state=validated")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/teacher-attendance?from=$today&to=$today&state=not_entered")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.session_id', $third);
        $this->putJson("/api/teacher-attendance/{$mine['attendance_id']}", ['status' => 'present', 'version' => 2])->assertForbidden();
    }

    public function test_the_attendance_page_enters_and_validates(): void
    {
        $s = $this->setting();
        [$first] = $this->begun($s, 1);
        $supervisor = $this->account('surveillant');
        $this->member($s['school'], $supervisor, 'active', ['supervisor']);
        $this->signIn($supervisor)->assertOk();
        $this->get('/school/teacher-attendance')->assertOk()->assertSee(__('attendance.teacher_attendance'));
        $this->within($s['school'], $supervisor, function () use ($first) {
            Livewire::test(TeacherAttendance::class)->assertSee(__('attendance.states.not_entered'))->assertTableActionHidden('validate', $first)
                ->callTableAction('enter', $first, data: ['status' => 'late', 'late_minutes' => 5, 'note' => 'Arrivée à 8 h 05'])->assertHasNoTableActionErrors()
                ->assertNotified(__('school.saved'));
        });
        $this->assertSame('submitted', DB::connection('owner')->table('school.teacher_attendance')->where('session_id', $first)->value('validation_status'));
        $this->signIn($s['manager'])->assertOk();
        $this->within($s['school'], $s['manager'], function () use ($first) {
            Livewire::test(TeacherAttendance::class)->assertSee(__('attendance.late_by', ['minutes' => '5']))
                ->callTableAction('validate', $first)->assertNotified(__('school.saved'));
        });
        $this->assertSame('validated', DB::connection('owner')->table('school.teacher_attendance')->where('session_id', $first)->value('validation_status'));
    }
}
