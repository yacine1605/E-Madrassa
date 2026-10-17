<?php

namespace Tests\Feature;

use App\Filament\School\Pages\AbsenceAlerts;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * The absence thresholds (FR-PRE-01, SQL 073): a pupil absent without excuse as often as the
 * school's threshold within its window raises one alert, its group's followers notified; late
 * arrivals and excused absences do not count; no threshold, no alert.
 */
final class AbsenceAlertTest extends TestCase
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

    /** The school's setting, as the school would set it. */
    private function set(object $school, string $key, int $value): void
    {
        $owner = DB::connection('owner');
        $owner->unprepared('ALTER TABLE school.setting DISABLE TRIGGER USER');
        $owner->delete('DELETE FROM school.setting WHERE tenant_id = ? AND key = ?', [$school->id, $key]);
        $owner->insert('INSERT INTO school.setting (tenant_id, key, value, created_at, updated_at) VALUES (?, ?, ?::jsonb, now(), now())', [$school->id, $key, json_encode($value)]);
        $owner->unprepared('ALTER TABLE school.setting ENABLE TRIGGER USER');
    }

    private function notified(array $account): int
    {
        return DB::connection('owner')->table('school.notification')->where('user_id', $account['id'])->where('event', 'attendance.absence_threshold')->count();
    }

    public function test_a_pupil_reaching_the_threshold_raises_one_alert_and_its_followers_are_told(): void
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
        // Four sessions, nine, six, three and one day ago.
        $sessions = [];
        foreach ([9, 6, 3, 1] as $i => $ago) {
            $sessions[] = $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'],
                'room_id' => $s['room'], 'day' => $this->next(Carbon::SUNDAY), 'start_time' => sprintf('%02d:00', 8 + $i), 'end_time' => sprintf('%02d:50', 8 + $i)])
                ->assertCreated()->json('data.id');
        }
        $owner = DB::connection('owner');
        $owner->unprepared('ALTER TABLE school.class_session DISABLE TRIGGER USER; ALTER TABLE school.group_membership DISABLE TRIGGER USER; ALTER TABLE school.study_group DISABLE TRIGGER USER');
        foreach ([9, 6, 3, 1] as $i => $ago) {
            $owner->table('school.class_session')->where('id', $sessions[$i])->update(['starts_at' => now()->subDays($ago)->subMinutes(60), 'ends_at' => now()->subDays($ago)->subMinutes(10)]);
        }
        $owner->table('school.group_membership')->where('group_id', $s['group'])->update(['valid_from' => now()->subDays(20)->toDateString()]);
        $owner->table('school.study_group')->where('id', $s['group'])->update(['lead_teacher_id' => $s['teacher']]);
        $owner->unprepared('ALTER TABLE school.class_session ENABLE TRIGGER USER; ALTER TABLE school.group_membership ENABLE TRIGGER USER; ALTER TABLE school.study_group ENABLE TRIGGER USER');
        // The group's teacher, who leads it, and a supervisor for the whole school.
        $karim = $this->account('karim');
        $this->member($s['school'], $karim, 'active');
        $this->grant($s['school'], $karim, 'teacher', 'personal');
        $record = $this->getJson("/api/staff/{$s['teacher']}")->json('data');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $karim['id'], 'version' => $record['version']])->assertOk();
        $nadia = $this->account('nadia');
        $this->member($s['school'], $nadia, 'active');
        $this->grant($s['school'], $nadia, 'supervisor');

        $this->signIn($karim)->assertOk();
        $call = fn (int $session, string $amine, string $lina) => $this->postJson("/api/class-sessions/{$sessions[$session]}/roll-call",
            ['entries' => [['student_id' => $pupils[0], 'status' => $amine], ['student_id' => $pupils[1], 'status' => $lina]]])->assertOk();
        // Three absences of Amine in 30 days, the default: one alert, when the third is saved. Lina late, then excused: none.
        $call(0, 'absent', 'late');
        $call(1, 'absent', 'excused');
        $this->assertSame(0, $owner->table('school.absence_alert')->count());
        $call(2, 'absent', 'excused');
        $alert = $owner->table('school.absence_alert')->sole();
        // Over the last 30 days, today's included.
        $today = Carbon::now('Africa/Algiers')->toDateString();
        $this->assertSame([$pupils[0], $s['group'], 3, 3, Carbon::parse($today)->subDays(29)->toDateString(), $today],
            [$alert->student_id, $alert->group_id, $alert->absences, $alert->threshold, Carbon::parse($alert->from_day)->toDateString(), Carbon::parse($alert->to_day)->toDateString()]);
        // Its followers told, the group's lead teacher and the supervisor; not the manager, who corrects no roll.
        $this->assertSame([1, 1, 0], [$this->notified($karim), $this->notified($nadia), $this->notified($s['manager'])]);
        $this->assertSame($alert->id, json_decode($owner->table('school.notification')->where('user_id', $nadia['id'])->where('event', 'attendance.absence_threshold')->value('data'), true)['alert']);
        // A fourth absence within the window: no second alert.
        $call(3, 'absent', 'present');
        $this->assertSame(1, $owner->table('school.absence_alert')->count());

        // No threshold: none, whatever the absences.
        $this->set($s['school'], 'attendance.absence_threshold', 0);
        foreach ([0, 1, 2] as $session) $call($session, 'absent', 'absent');
        $this->assertSame(1, $owner->table('school.absence_alert')->count());
        // Two in 30 days: the next mark changed to absent raises Lina's.
        $this->set($s['school'], 'attendance.absence_threshold', 2);
        $call(3, 'absent', 'absent');
        $this->assertSame(4, $owner->table('school.absence_alert')->where('student_id', $pupils[1])->value('absences'));

        // The lead teacher reads the alerts, the latest first, with the rule.
        $this->getJson('/api/absence-alerts')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.student_id', $pupils[1])
            ->assertJsonPath('data.0.readable', true)->assertJsonPath('data.1.first_name_lat', 'Amine');
        $this->getJson('/api/absence-alerts/rule')->assertOk()->assertJsonPath('data.threshold', 2)->assertJsonPath('data.window_days', 30);
        $this->within($s['school'], $karim, function () {
            Livewire::test(AbsenceAlerts::class)->assertSee('Benali Amine')->assertSee('3 absences (seuil 3)')->assertSee('2 fois au cours des 30 derniers jours', false);
        });
        // No longer leading the group: none of its alerts.
        $owner->unprepared('ALTER TABLE school.study_group DISABLE TRIGGER USER');
        $owner->table('school.study_group')->where('id', $s['group'])->update(['lead_teacher_id' => null]);
        $owner->unprepared('ALTER TABLE school.study_group ENABLE TRIGGER USER');
        $this->getJson('/api/absence-alerts')->assertOk()->assertJsonCount(0, 'data');
        // The supervisor reads them all; the manager has no such screen.
        $this->signIn($nadia)->assertOk();
        $this->getJson('/api/absence-alerts')->assertOk()->assertJsonCount(2, 'data');
        $this->get('/school/absence-alerts')->assertOk();
        $this->signIn($s['manager'])->assertOk();
        $this->getJson('/api/absence-alerts')->assertForbidden();
        $this->get('/school/absence-alerts')->assertForbidden();
    }
}
