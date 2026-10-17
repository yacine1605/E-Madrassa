<?php

namespace Tests\Feature;

use App\Filament\School\Pages\OperationsDashboard;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * The operational dashboard (FR-TDB-02): a group's fill = its composition list over its capacity
 * (AC1); its sessions' attendance counted from the rows; a teacher sees their own groups only, and
 * none of the school's figures.
 */
final class OperationsDashboardTest extends TestCase
{
    use PlansSessions;

    public function test_a_groups_fill_is_its_composition_list_over_its_capacity_and_a_teacher_sees_their_groups_only(): void
    {
        $s = $this->setting();
        $other = $this->postJson('/api/groups', ['year_id' => $s['year'], 'program_id' => $s['program'], 'site_id' => $s['site'], 'code' => '4AM-2',
            'labels' => ['fr' => '4AM 2'], 'group_type' => 'class', 'capacity' => 10, 'valid_from' => now()->subMonth()->toDateString(), 'status' => 'active'])
            ->assertCreated()->json('data.id');
        $pupils = [];
        foreach (['Amine' => $s['group'], 'Lina' => $s['group'], 'Sofiane' => $other] as $name => $group) {
            $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => $name, 'birth_date' => '1990-01-01'])->assertCreated()->json('data.id');
            $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])
                ->assertCreated()->json('data.id');
            $this->postJson("/api/groups/$group/memberships", ['enrollment_id' => $enrolment])->assertCreated();
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
        $members = count($this->getJson("/api/groups/{$s['group']}/memberships")->assertOk()->json('data'));

        // The teacher calls the roll: one absent, one present; their attendance entered by no one.
        $this->signIn($karim)->assertOk();
        $this->postJson("/api/class-sessions/$session/roll-call", ['entries' => [['student_id' => $pupils[0], 'status' => 'absent'],
            ['student_id' => $pupils[1], 'status' => 'present']]])->assertOk();
        // A teacher: their own group only, none of the school's figures.
        $mine = $this->getJson("/api/dashboards/operations?from=$day&to=$day")->assertOk()->json();
        $this->assertSame([$s['group']], array_column($mine['data'], 'group_id'));
        $this->assertNull($mine['meta']['school']);
        $this->getJson("/api/dashboards/operations/sessions?group=$other&from=$day&to=$day")->assertForbidden();
        // A teacher exports nothing.
        $this->postJson('/api/exports', ['kind' => 'operations_dashboard', 'format' => 'csv', 'filters' => ['from' => $day, 'to' => $day]])->assertForbidden();
        $this->getJson("/api/dashboards/operations/sessions?group={$s['group']}&from=$day&to=$day")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.teacher_status', 'not_entered')->assertJsonPath('data.0.roll_marks', 2)->assertJsonPath('data.0.absent', 1);

        // The pedagogy head, for the whole school: AC1, the fill as the composition list counts it.
        $this->signIn($s['manager'])->assertOk();
        $result = $this->getJson("/api/dashboards/operations?from=$day&to=$day")->assertOk()->json();
        $rows = collect($result['data'])->keyBy('group_id');
        $this->assertSame([$s['group'], $other], array_column($result['data'], 'group_id'));
        $this->assertSame(2, $members);
        $this->assertSame([$members, 25, '8.0'], [$rows[$s['group']]['members'], $rows[$s['group']]['capacity'], $rows[$s['group']]['fill_rate']]);
        $this->assertSame([1, 0, 1, 1, 0, 2, 1, '50.0'], [$rows[$s['group']]['sessions_held'], $rows[$s['group']]['teacher_entered'], $rows[$s['group']]['teacher_missing'],
            $rows[$s['group']]['roll_taken'], $rows[$s['group']]['roll_missing'], $rows[$s['group']]['marks'], $rows[$s['group']]['absent'], $rows[$s['group']]['absence_rate']]);
        $this->assertSame([1, 10, null], [$rows[$other]['members'], $rows[$other]['capacity'], $rows[$other]['sessions_held']]);
        $this->assertSame(3, $result['meta']['school']['enrollments_pending']);
        $this->assertSame([0, 0, null], [$result['meta']['school']['leads_created'], $result['meta']['school']['leads_registered'], $result['meta']['school']['conversion_rate']]);
        $this->getJson("/api/dashboards/operations?from=$day&to=".now()->addYears(2)->toDateString())->assertConflict()->assertJsonPath('code', 'dashboard_range_invalid');
        // FR-TDB-03: exported as it shows, a row per group, the group and its programme named.
        $export = $this->postJson('/api/exports', ['kind' => 'operations_dashboard', 'format' => 'csv', 'filters' => ['from' => $day, 'to' => $day]])
            ->assertCreated()->json('data');
        $this->assertSame(2, $export['rows']);
        $this->assertSame(['group', 'program', 'capacity', 'members', 'fill_rate', 'sessions_held', 'teacher_validated', 'teacher_validated_rate', 'teacher_missing',
            'roll_missing', 'marks', 'absent', 'absence_rate', 'late'], $export['columns']);
        $csv = $this->get("/api/files/{$export['file']['id']}/content")->assertOk()->streamedContent();
        $this->assertStringContainsString('4AM-1 · 4AM 1', $csv);
        $this->assertStringContainsString('50.0', $csv);

        // The screen and a group's sessions.
        $this->get('/school/operations-dashboard')->assertOk()->assertSee(__('dashboard.fill'));
        $this->actingAs($this->person($s['manager']));
        $this->withSession(['school.tenant' => $s['school']->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($s, $day) {
            app(CurrentSchool::class)->enter($s['school']->id, $s['manager']['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            Livewire::test(OperationsDashboard::class)->filterTable('period', ['from' => $day, 'to' => $day])->assertSee('2 / 25')->assertSee('1 / 2')
                ->mountTableAction('sessions', $s['group'])->assertMountedActionModalSee('Meziane Karim')
                ->assertMountedActionModalSee(__('attendance.states.not_entered'));
            Livewire::test(OperationsDashboard::class)->filterTable('period', ['from' => $day, 'to' => $day])->callTableAction('export', data: ['format' => 'pdf'])
                ->assertNotified();
        });
        $this->assertSame(2, DB::connection('owner')->table('school.export_log')->where('tenant_id', $s['school']->id)->where('kind', 'operations_dashboard')
            ->where('format', 'pdf')->value('row_count'));

        // An accountant reads no operational dashboard.
        $accountant = $this->account('accountant');
        $this->member($s['school'], $accountant, 'active', ['accountant']);
        $this->signIn($accountant)->assertOk();
        $this->getJson("/api/dashboards/operations?from=$day&to=$day")->assertForbidden();
        $this->get('/school/operations-dashboard')->assertForbidden();
    }

    public function test_a_groups_absences_per_pupil_and_their_weekly_trend_are_counted_from_the_roll_calls(): void
    {
        $s = $this->setting();
        $other = $this->postJson('/api/groups', ['year_id' => $s['year'], 'program_id' => $s['program'], 'site_id' => $s['site'], 'code' => '4AM-2',
            'labels' => ['fr' => '4AM 2'], 'group_type' => 'class', 'capacity' => 10, 'valid_from' => now()->subMonth()->toDateString(), 'status' => 'active'])
            ->assertCreated()->json('data.id');
        $pupils = [];
        foreach (['Amine', 'Lina'] as $name) {
            $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => $name, 'birth_date' => '1990-01-01'])->assertCreated()->json('data.id');
            $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])
                ->assertCreated()->json('data.id');
            $this->postJson("/api/groups/{$s['group']}/memberships", ['enrollment_id' => $enrolment])->assertCreated();
            $pupils[] = $pupil;
        }
        // Two sessions a week apart, the pupils in the group since before the first.
        $owner = DB::connection('owner');
        $sessions = [];
        foreach ([7 => Carbon::SUNDAY, 0 => Carbon::MONDAY] as $ago => $weekday) {
            $session = $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'],
                'room_id' => $s['room'], 'day' => $this->next($weekday), 'start_time' => '08:00', 'end_time' => '08:50'])->assertCreated()->json('data.id');
            $owner->unprepared('ALTER TABLE school.class_session DISABLE TRIGGER USER');
            $owner->table('school.class_session')->where('id', $session)->update(['starts_at' => now()->subDays($ago)->subMinutes(60),
                'ends_at' => now()->subDays($ago)->subMinutes(10)]);
            $owner->unprepared('ALTER TABLE school.class_session ENABLE TRIGGER USER');
            $sessions[] = $session;
        }
        $owner->unprepared('ALTER TABLE school.group_membership DISABLE TRIGGER USER');
        $owner->table('school.group_membership')->where('tenant_id', $s['school']->id)->update(['valid_from' => now()->subDays(20)->toDateString()]);
        $owner->unprepared('ALTER TABLE school.group_membership ENABLE TRIGGER USER');
        $days = array_map(fn (string $session) => $this->getJson("/api/class-sessions/$session")->json('data.day'), $sessions);
        $karim = $this->account('karim');
        $this->member($s['school'], $karim, 'active');
        $this->grant($s['school'], $karim, 'teacher', 'personal');
        $record = $this->getJson("/api/staff/{$s['teacher']}")->json('data');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $karim['id'], 'version' => $record['version']])->assertOk();

        // The teacher calls both rolls: everyone present a week ago; today Amine absent, Lina late.
        $this->signIn($karim)->assertOk();
        $this->postJson("/api/class-sessions/{$sessions[0]}/roll-call", ['entries' => [['student_id' => $pupils[0], 'status' => 'present'],
            ['student_id' => $pupils[1], 'status' => 'present']]])->assertOk();
        $this->postJson("/api/class-sessions/{$sessions[1]}/roll-call", ['entries' => [['student_id' => $pupils[0], 'status' => 'absent'],
            ['student_id' => $pupils[1], 'status' => 'late', 'late_minutes' => 10]]])->assertOk();

        // The pedagogy head: the most absent first, the trend week by week, a week without a session included.
        $this->signIn($s['manager'])->assertOk();
        $period = "from={$days[0]}&to={$days[1]}";
        $rows = $this->getJson("/api/dashboards/operations/pupils?group={$s['group']}&$period")->assertOk()->json('data');
        $this->assertSame([[$pupils[0], true, 'Amine', 2, 1, 0, 0, '50.0', $days[1]], [$pupils[1], true, 'Lina', 2, 0, 1, 0, '0.0', null]],
            array_map(fn (array $row) => [$row['id'], $row['readable'], $row['first_name_lat'], $row['marks'], $row['absent'], $row['late'], $row['excused'],
                $row['absence_rate'], $row['last_absence']], $rows));
        $week = Carbon::parse($days[1])->startOfWeek(Carbon::SUNDAY)->toDateString();
        $earlier = Carbon::parse($days[0])->subDays(7)->toDateString();
        $weeks = $this->getJson("/api/dashboards/operations/weeks?from=$earlier&to={$days[1]}")->assertOk()->json('data');
        $this->assertSame([[$earlier, 0, 0, 0, null, null], [Carbon::parse($days[0])->startOfWeek(Carbon::SUNDAY)->toDateString(), 1, 2, 0, '0.0', null],
            [$week, 1, 2, 1, '50.0', '50.0']], array_map(fn (array $row) => [$row['week'], $row['sessions_held'], $row['marks'], $row['absent'],
                $row['absence_rate'], $row['change']], $weeks));
        $this->assertSame([[$days[0], 1, 2, 0, '0.0', null], [$week, 1, 2, 1, '50.0', '50.0']],
            array_map(fn (array $row) => [$row['week'], $row['sessions_held'], $row['marks'], $row['absent'], $row['absence_rate'], $row['change']],
                $this->getJson("/api/dashboards/operations/weeks?group={$s['group']}&$period")->assertOk()->json('data')));
        $this->getJson("/api/dashboards/operations/weeks?from={$days[0]}&to=".now()->addYears(2)->toDateString())->assertConflict()
            ->assertJsonPath('code', 'dashboard_range_invalid');
        $this->assertSame([], $this->getJson("/api/dashboards/operations/pupils?group=$other&$period")->assertOk()->json('data'));

        // Lina leaves the group: the teacher still counts her marks, without her name; another group stays closed to them.
        $owner->unprepared('ALTER TABLE school.group_membership DISABLE TRIGGER USER');
        $owner->table('school.group_membership')->where('tenant_id', $s['school']->id)
            ->whereIn('enrollment_id', $owner->table('school.enrollment')->where('student_id', $pupils[1])->pluck('id'))->update(['valid_to' => $days[1]]);
        $owner->unprepared('ALTER TABLE school.group_membership ENABLE TRIGGER USER');
        $this->signIn($karim)->assertOk();
        $this->assertSame([[$pupils[0], true, 'Amine'], [$pupils[1], false, null]], array_map(fn (array $row) => [$row['id'], $row['readable'], $row['first_name_lat']],
            $this->getJson("/api/dashboards/operations/pupils?group={$s['group']}&$period")->assertOk()->json('data')));
        $this->getJson("/api/dashboards/operations/pupils?group=$other&$period")->assertForbidden();
        $this->getJson("/api/dashboards/operations/weeks?group=$other&$period")->assertForbidden();
        $this->assertSame([1, 1], array_column($this->getJson("/api/dashboards/operations/weeks?$period")->assertOk()->json('data'), 'sessions_held'));

        // The screen: a group's pupils and its trend, and the trend of every group read.
        $this->actingAs($this->person($s['manager']));
        $this->withSession(['school.tenant' => $s['school']->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($s, $days) {
            app(CurrentSchool::class)->enter($s['school']->id, $s['manager']['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            Livewire::test(OperationsDashboard::class)->filterTable('period', ['from' => $days[0], 'to' => $days[1]])
                ->mountTableAction('pupils', $s['group'])->assertMountedActionModalSee('Benali Amine')->assertMountedActionModalSee('+50,0');
            Livewire::test(OperationsDashboard::class)->filterTable('period', ['from' => $days[0], 'to' => $days[1]])
                ->mountTableAction('trend')->assertMountedActionModalSee('50,0');
        });
    }
}
