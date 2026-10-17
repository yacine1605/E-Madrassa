<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sessions (FR-PLN-02, FR-PLN-03, SQL 062) through their real routes: a weekly series over a term
 * with its closed days skipped, a session changed on its own leaving its series, the conflicts
 * refused by the database and the warnings told, a group's volume follow-up.
 */
final class ClassSessionTest extends TestCase
{
    /** @return array<string, mixed> a school whose pedagogy and HR manager is signed in, with a class, its programme's subject, a qualified teacher and a room */
    private function setting(): array
    {
        $manager = $this->account('planning');
        $school = $this->school('pro', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $owner = DB::connection('owner');
        $site = $owner->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        $node = $owner->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' ORDER BY code LIMIT 1", [$school->id])->id;
        [$maths, $physics] = $owner->table('school.subject')->where('tenant_id', $school->id)->whereNull('archived_at')->orderBy('code')->limit(2)->pluck('id')->all();
        $year = $this->postJson('/api/years', ['code' => 'PLAN-'.Str::upper(Str::random(4)), 'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(9)->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => '4AM', 'labels' => ['fr' => '4e année moyenne'],
            'base_price' => '120000', 'status' => 'active'])->assertCreated()->json('data.id');
        $this->postJson("/api/programs/$program/subjects", ['subject_id' => $maths, 'weekly_hours' => '3', 'total_hours' => '96'])->assertCreated();
        $group = $this->postJson('/api/groups', ['year_id' => $year, 'program_id' => $program, 'site_id' => $site, 'code' => '4AM-1', 'labels' => ['fr' => '4AM 1'],
            'group_type' => 'class', 'capacity' => 25, 'valid_from' => now()->subMonth()->toDateString(), 'status' => 'active'])->assertCreated()->json('data.id');
        $teacher = $this->postJson('/api/staff', ['last_name_lat' => 'Meziane', 'first_name_lat' => 'Karim', 'staff_type' => 'teacher_internal'])->assertCreated()->json('data.id');
        $this->postJson("/api/staff/$teacher/qualifications", ['subject_id' => $maths, 'node_id' => $node])->assertCreated();
        $room = $this->postJson('/api/rooms', ['site_id' => $site, 'code' => 'A101', 'labels' => ['fr' => 'Salle 101'], 'capacity' => 30, 'room_type' => 'CLASSROOM'])
            ->assertCreated()->json('data.id');
        return compact('school', 'manager', 'site', 'node', 'maths', 'physics', 'year', 'program', 'group', 'teacher', 'room');
    }

    /** The next day of the week (0 Sunday … 6 Saturday) at least `$after` days from today. */
    private function next(int $weekday, int $after = 7): string
    {
        $day = now()->addDays($after);
        while ($day->dayOfWeek !== $weekday) $day = $day->addDay();
        return $day->toDateString();
    }

    private function series(array $s, array $extra = []): array
    {
        $first = $this->next(Carbon::SUNDAY);
        return ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'room_id' => $s['room'], 'weekday' => 0,
            'start_time' => '08:00', 'end_time' => '09:30', 'date_from' => $first, 'date_to' => Carbon::parse($first)->addWeeks(7)->toDateString(),
            'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    public function test_a_weekly_series_plans_one_session_a_week_on_the_open_days_and_a_session_changed_on_its_own_leaves_it(): void
    {
        $s = $this->setting();
        $first = $this->next(Carbon::SUNDAY);
        $holiday = Carbon::parse($first)->addWeeks(2)->toDateString();
        $this->postJson('/api/calendar-exceptions', ['kind' => 'holiday', 'date_from' => $holiday, 'date_to' => $holiday, 'reason' => 'Fête nationale'])->assertCreated();
        $payload = $this->series($s);
        // Read before: the holiday skipped, nothing blocking.
        $check = collect($this->postJson('/api/session-series/check', $payload)->assertOk()->json('data'));
        $this->assertSame([[$holiday, 'session_day_skipped']], $check->map(fn ($c) => [$c['day'], $c['conflict']])->all());
        $this->assertSame('Jour fermé : aucune séance ce jour-là.', $check[0]['message']);
        // AC1: eight Sundays, one a holiday: seven sessions, in Algiers' time.
        $series = $this->postJson('/api/session-series', $payload)->assertCreated()->assertJsonPath('data.sessions', 7)->assertJsonPath('data.start_time', '08:00')->json('data');
        $this->postJson('/api/session-series', $payload)->assertCreated()->assertJsonPath('data.id', $series['id']);
        $listed = $this->getJson("/api/class-sessions?from=$first&to=".Carbon::parse($first)->addWeeks(8)->toDateString()."&group_id={$s['group']}")
            ->assertOk()->assertJsonCount(7, 'data')->assertJsonPath('data.0.day', $first)->assertJsonPath('data.0.start_time', '08:00')
            ->assertJsonPath('data.0.end_time', '09:30')->assertJsonPath('data.0.teacher_last_name', 'Meziane')->assertJsonPath('data.0.room_code', 'A101')->json('data');
        // AC2: one session moved on its own leaves its series; the series changed afterwards leaves it as it is.
        $moved = $this->putJson("/api/class-sessions/{$listed[1]['id']}", ['teacher_id' => $s['teacher'], 'room_id' => $s['room'], 'day' => $listed[1]['day'],
            'start_time' => '10:00', 'end_time' => '11:30', 'version' => $listed[1]['version']])->assertOk()->assertJsonPath('data.detached', true)->json('data');
        $this->postJson("/api/session-series/{$series['id']}/change", ['from' => $first, 'teacher_id' => $s['teacher'], 'room_id' => $s['room'],
            'start_time' => '09:00', 'end_time' => '10:30', 'version' => $series['version']])->assertOk()->assertJsonPath('changed', 6);
        $this->getJson("/api/class-sessions/{$moved['id']}")->assertOk()->assertJsonPath('data.start_time', '10:00');
        $this->getJson("/api/class-sessions/{$listed[0]['id']}")->assertOk()->assertJsonPath('data.start_time', '09:00');
        // Ended after its third Sunday: the sessions after it go, the one moved on its own stays.
        $this->postJson("/api/session-series/{$series['id']}/end", ['last_day' => Carbon::parse($first)->addWeeks(3)->toDateString(), 'version' => $series['version'] + 1])
            ->assertOk()->assertJsonPath('removed', 4)->assertJsonPath('data.sessions', 3);
        // Cancelled with its reason: its hours are free again.
        $this->postJson("/api/class-sessions/{$listed[0]['id']}/cancel", ['reason' => 'Sortie pédagogique', 'version' => 3])->assertConflict()->assertJsonPath('code', 'stale_version');
        $version = $this->getJson("/api/class-sessions/{$listed[0]['id']}")->json('data.version');
        $this->postJson("/api/class-sessions/{$listed[0]['id']}/cancel", ['reason' => 'Sortie pédagogique', 'version' => $version])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->putJson("/api/class-sessions/{$listed[0]['id']}", ['teacher_id' => $s['teacher'], 'day' => $first, 'start_time' => '08:00', 'end_time' => '09:00',
            'version' => $version + 1])->assertConflict()->assertJsonPath('code', 'session_immutable');
        // The volume follow-up: planned against the programme's 96 hours.
        $this->getJson("/api/groups/{$s['group']}/volume")->assertOk()->assertJsonPath('data.0.planned_sessions', 2)->assertJsonPath('data.0.volume_hours', '96.00')
            ->assertJsonPath('data.0.planned_hours', '3.00')->assertJsonPath('data.0.hours_left', '93.00');
    }

    public function test_the_conflicts_are_refused_and_the_warnings_told(): void
    {
        $s = $this->setting();
        $day = $this->next(Carbon::MONDAY);
        $session = fn (array $extra = []) => $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'],
            'room_id' => $s['room'], 'day' => $day, 'start_time' => '10:00', 'end_time' => '11:00', ...$extra]);
        $session()->assertCreated()->assertJsonPath('warnings', []);
        // FR-PLN-03 AC1: the same teacher, room or group at the same time.
        $session()->assertConflict()->assertJsonPath('code', 'session_teacher_busy');
        $colleague = $this->postJson('/api/staff', ['last_name_lat' => 'Haddad', 'first_name_lat' => 'Samira', 'staff_type' => 'teacher_external'])->json('data.id');
        $session(['teacher_id' => $colleague, 'start_time' => '10:30', 'end_time' => '11:30'])->assertConflict()->assertJsonPath('code', 'session_teacher_not_qualified');
        $this->postJson("/api/staff/$colleague/qualifications", ['subject_id' => $s['maths'], 'node_id' => $s['node']])->assertCreated();
        $session(['teacher_id' => $colleague, 'start_time' => '10:30', 'end_time' => '11:30'])->assertConflict()->assertJsonPath('code', 'session_group_busy');
        // A closed day, a subject outside the programme, a room on no site of the group's.
        $session(['day' => $this->next(Carbon::FRIDAY)])->assertConflict()->assertJsonPath('code', 'session_day_closed');
        $session(['subject_id' => $s['physics'], 'start_time' => '14:00', 'end_time' => '15:00'])->assertConflict()->assertJsonPath('code', 'session_subject_outside_program');
        $session(['start_time' => '11:00', 'end_time' => '10:00'])->assertConflict()->assertJsonPath('code', 'session_hours_invalid');
        $session(['start_time' => '9h'])->assertUnprocessable()->assertJsonValidationErrors('start_time');
        // Read before writing: every conflict, blocking or not, with its message.
        $found = $this->postJson('/api/class-sessions/check', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $colleague, 'room_id' => $s['room'],
            'day' => $day, 'start_time' => '10:30', 'end_time' => '11:30'])->assertOk()->json('data');
        $this->assertSame(['session_group_busy', 'session_room_busy'], array_column($found, 'conflict'));
        $this->assertSame('Ce groupe a déjà une séance à ces heures.', $found[0]['message']);
        // Warnings never refuse: four hours that week for three in the programme.
        $created = $session(['start_time' => '13:00', 'end_time' => '16:00'])->assertCreated()->json('warnings');
        $this->assertSame(['volume_weekly_exceeded'], array_column($created, 'conflict'));
        $this->assertSame('Les heures de la semaine dépassent le programme : 4 h pour 3 h par semaine.', $created[0]['message']);
        $this->getJson("/api/class-sessions?from=$day&to=$day")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/class-sessions?from='.now()->toDateString().'&to='.now()->addDays(90)->toDateString())->assertConflict()->assertJsonPath('code', 'session_range_too_long');
        // FR-PLN-01 AC1: a day with sessions is closed no more.
        $this->postJson('/api/calendar-exceptions', ['kind' => 'closure', 'date_from' => $day, 'date_to' => $day, 'reason' => 'Grève'])
            ->assertConflict()->assertJsonPath('code', 'calendar_sessions_planned');
    }

    public function test_sessions_are_read_with_the_planning_and_planned_by_whoever_may(): void
    {
        $s = $this->setting();
        $day = $this->next(Carbon::TUESDAY);
        $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'room_id' => $s['room'],
            'day' => $day, 'start_time' => '08:00', 'end_time' => '09:00'])->assertCreated();
        // A teacher reads the planning and plans nothing.
        $teacher = $this->account('teacher');
        $this->member($s['school'], $teacher, 'active', ['teacher']);
        $this->signIn($teacher)->assertOk();
        $this->getJson("/api/class-sessions?from=$day&to=$day")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'day' => $day,
            'start_time' => '10:00', 'end_time' => '11:00'])->assertForbidden();
        $this->postJson('/api/session-series', $this->series($s))->assertForbidden();
        // The supervisor reads it too; without the planning module, nobody.
        DB::connection('console')->select('SELECT platform.disable_module(?, ?)', [$s['school']->id, 'attendance']);
        DB::connection('console')->select('SELECT platform.disable_module(?, ?)', [$s['school']->id, 'scheduling_manual']);
        $this->getJson("/api/class-sessions?from=$day&to=$day")->assertForbidden();
    }
}
