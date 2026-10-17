<?php

namespace Tests\Feature;

use App\Filament\School\Pages\MyTimetable;
use App\Filament\School\Pages\Planning;
use App\Filament\School\Pages\SessionChanges;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * Replacements (FR-PLN-06): a session's teacher replaced by one qualified, available and free, with
 * the reason (AC1), or given back; the change in the history of the group's sessions (AC2); the
 * teachers told; the teacher replaced still sees the session, their agenda drops it.
 */
final class ReplacementTest extends TestCase
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

    /** A session on the next Sunday at eight, a teacher qualified to replace its teacher, one who is not. */
    private function planned(array $s): array
    {
        $day = $this->next(Carbon::SUNDAY);
        $session = $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'room_id' => $s['room'],
            'day' => $day, 'start_time' => '08:00', 'end_time' => '09:30'])->assertCreated()->json('data');
        $substitute = $this->postJson('/api/staff', ['last_name_lat' => 'Haddad', 'first_name_lat' => 'Samira', 'last_name_ar' => 'حداد', 'first_name_ar' => 'سميرة',
            'staff_type' => 'teacher_external'])->json('data.id');
        $this->postJson("/api/staff/$substitute/qualifications", ['subject_id' => $s['maths'], 'node_id' => $s['node']])->assertCreated();
        $unqualified = $this->postJson('/api/staff', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Rachid', 'staff_type' => 'teacher_internal'])->json('data.id');
        return compact('day', 'session', 'substitute', 'unqualified');
    }

    public function test_a_session_is_replaced_by_a_qualified_teacher_with_its_reason_given_back_and_kept_in_the_group_history(): void
    {
        $s = $this->setting();
        $p = $this->planned($s);
        $id = $p['session']['id'];
        // The substitutes: qualified, available and free; neither its teacher nor one not qualified.
        $candidates = array_column($this->getJson("/api/class-sessions/$id/substitutes")->assertOk()->json('data'), 'id');
        $this->assertContains($p['substitute'], $candidates);
        $this->assertNotContains($p['unqualified'], $candidates);
        $this->assertNotContains($s['teacher'], $candidates);
        // AC1: one not qualified is refused, with the rule said.
        $this->postJson("/api/class-sessions/$id/replace", ['teacher_id' => $p['unqualified'], 'reason' => 'Absence', 'version' => 1])
            ->assertConflict()->assertJsonPath('code', 'session_teacher_not_qualified')->assertJsonPath('message', __('errors.session_teacher_not_qualified'));
        $this->postJson("/api/class-sessions/$id/replace", ['teacher_id' => $p['substitute'], 'version' => 1])->assertStatus(422);
        $replaced = $this->postJson("/api/class-sessions/$id/replace", ['teacher_id' => $p['substitute'], 'reason' => 'Formation académique', 'version' => 1])
            ->assertOk()->assertJsonPath('data.status', 'replaced')->assertJsonPath('data.teacher_id', $p['substitute'])
            ->assertJsonPath('data.replaced_teacher_id', $s['teacher'])->assertJsonPath('data.replace_reason', 'Formation académique')->json('data');
        // Its hours wait for its return; it is given back to the teacher planned.
        $this->putJson("/api/class-sessions/$id", ['teacher_id' => $p['substitute'], 'room_id' => $s['room'], 'day' => $p['day'], 'start_time' => '10:00',
            'end_time' => '11:00', 'version' => $replaced['version']])->assertConflict()->assertJsonPath('code', 'session_replaced');
        $back = $this->postJson("/api/class-sessions/$id/replace", ['teacher_id' => $s['teacher'], 'reason' => 'Formation reportée', 'version' => $replaced['version']])
            ->assertOk()->assertJsonPath('data.status', 'planned')->assertJsonPath('data.replaced_teacher_id', null)->json('data');
        $this->postJson("/api/class-sessions/$id/cancel", ['reason' => 'Sortie pédagogique', 'version' => $back['version']])->assertOk();
        // AC2: the group's history, with who and why.
        $history = $this->getJson("/api/session-changes?group_id={$s['group']}&from={$p['day']}&to={$p['day']}")->assertOk()->json('data');
        $this->assertSame(['replaced', 'restored', 'cancelled'], array_column($history, 'kind'));
        $this->assertSame(['Formation académique', 'Formation reportée', 'Sortie pédagogique'], array_column($history, 'reason'));
        $this->assertSame([$p['substitute'], $s['teacher'], null], array_column($history, 'new_teacher_id'));
        $this->assertSame('Haddad', $history[0]['new_last_name']);
        $this->getJson("/api/session-changes?from={$p['day']}&to=".Carbon::parse($p['day'])->addDays(70)->toDateString())->assertConflict()
            ->assertJsonPath('code', 'session_range_too_long');
    }

    public function test_the_teachers_are_told_and_the_teacher_replaced_sees_the_session_their_agenda_drops_it(): void
    {
        $s = $this->setting();
        $p = $this->planned($s);
        $account = $this->account('karim');
        $this->member($s['school'], $account, 'active');
        $this->grant($s['school'], $account, 'teacher', 'personal');
        $record = $this->getJson("/api/staff/{$s['teacher']}")->json('data');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $account['id'], 'version' => $record['version']])->assertOk();
        $this->postJson("/api/class-sessions/{$p['session']['id']}/replace", ['teacher_id' => $p['substitute'], 'reason' => 'Formation académique', 'version' => 1])->assertOk();
        $this->assertSame(1, DB::connection('owner')->table('school.notification')->where('user_id', $account['id'])->where('event', 'schedule.session_replaced')->count());
        // The teacher replaced: the session among theirs, said held by another (whose record they may not read); their agenda cancels it.
        $this->signIn($account)->assertOk();
        $to = Carbon::parse($p['day'])->addDays(6)->toDateString();
        $mine = $this->getJson("/api/timetable/mine?from={$p['day']}&to=$to")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('replaced', $mine['status']);
        $this->assertTrue($mine['replaced_mine']);
        $this->assertNull($mine['teacher_name']);
        $ics = $this->get("/api/timetable/calendar.ics?mine=1&from={$p['day']}&to=$to")->assertOk()->getContent();
        $this->assertStringContainsString('STATUS:CANCELLED', $ics);
        $this->within($s['school'], $account, function () use ($p) {
            Livewire::test(MyTimetable::class)->filterTable('from', ['from' => $p['day']])->assertSee(__('planning.session_states.replaced'));
        });
        // The group's agenda shows the substitute.
        $this->signIn($s['manager'])->assertOk();
        $ics = $this->get("/api/timetable/calendar.ics?group_id={$s['group']}&from={$p['day']}&to=$to")->assertOk()->getContent();
        $this->assertStringContainsString('STATUS:CONFIRMED', $ics);
        $this->assertStringContainsString('Haddad Samira (remplaçant)', str_replace("\r\n ", '', $ics));
    }

    public function test_the_planning_page_replaces_a_session_and_the_history_page_lists_it(): void
    {
        $s = $this->setting();
        $p = $this->planned($s);
        $this->get('/school/session-changes')->assertOk();
        $this->within($s['school'], $s['manager'], function () use ($s, $p) {
            Livewire::test(Planning::class)->filterTable('week', ['week' => $p['day']])->assertTableActionVisible('replace', $p['session']['id'])
                ->callTableAction('replace', $p['session']['id'], data: ['teacher_id' => $p['unqualified'], 'reason' => 'Absence'])
                ->assertHasTableActionErrors(['teacher_id']);
            Livewire::test(Planning::class)->filterTable('week', ['week' => $p['day']])
                ->callTableAction('replace', $p['session']['id'], data: ['teacher_id' => $p['substitute'], 'reason' => 'Formation académique'])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $this->assertSame('replaced', DB::table('school.class_session')->where('id', $p['session']['id'])->value('status'));
            Livewire::test(Planning::class)->filterTable('week', ['week' => $p['day']])->assertSee(__('planning.replaces', ['name' => "\u{2068}Meziane Karim\u{2069}"]), false)
                ->assertTableActionHidden('change', $p['session']['id']);
            Livewire::test(SessionChanges::class)->filterTable('from', ['from' => $p['day']])->assertSee('Formation académique')
                ->assertSee(__('planning.change_kinds.replaced'));
        });
    }
}
