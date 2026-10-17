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
        });

        // An accountant reads no operational dashboard.
        $accountant = $this->account('accountant');
        $this->member($s['school'], $accountant, 'active', ['accountant']);
        $this->signIn($accountant)->assertOk();
        $this->getJson("/api/dashboards/operations?from=$day&to=$day")->assertForbidden();
        $this->get('/school/operations-dashboard')->assertForbidden();
    }
}
