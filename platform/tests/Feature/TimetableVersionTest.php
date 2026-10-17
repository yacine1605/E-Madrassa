<?php

namespace Tests\Feature;

use App\Filament\School\Pages\TimetableVersions;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * Versions and publication (FR-PLN-05): the school's timetable over a period prepared, validated
 * and published by `schedule.timetable.publish`; refused with a blocking conflict (AC1) or a copy
 * out of date; nothing told before its publication (AC2); two versions compared.
 */
final class TimetableVersionTest extends TestCase
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

    private function notices(array $account, string $event): int
    {
        return DB::connection('owner')->table('school.notification')->where('user_id', $account['id'])->where('event', $event)->count();
    }

    public function test_a_timetable_is_published_without_a_blocking_conflict_and_told_only_once_published(): void
    {
        $s = $this->setting();
        $day = $this->next(Carbon::SUNDAY);
        $session = $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'room_id' => $s['room'],
            'day' => $day, 'start_time' => '08:00', 'end_time' => '09:30'])->assertCreated()->json('data');
        $karim = $this->account('karim');
        $this->member($s['school'], $karim, 'active');
        $this->grant($s['school'], $karim, 'teacher', 'personal');
        $record = $this->getJson("/api/staff/{$s['teacher']}")->json('data');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $karim['id'], 'version' => $record['version']])->assertOk();
        $version = $this->postJson('/api/timetable-versions', ['date_from' => $day, 'date_to' => Carbon::parse($day)->addDays(6)->toDateString(),
            'reason' => 'Emploi du temps de la rentrée'])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.sessions', 1)->json('data');
        $this->postJson('/api/timetable-versions', ['date_from' => $day, 'date_to' => $day, 'reason' => 'Autre'])->assertConflict()
            ->assertJsonPath('code', 'timetable_version_open');
        // AC1: a room too small since, a blocking conflict, said with its reason.
        $room = $this->getJson("/api/rooms/{$s['room']}")->json('data');
        $this->putJson("/api/rooms/{$s['room']}", ['capacity' => 10, 'version' => $room['version']])->assertOk();
        $this->getJson("/api/timetable-versions/{$version['id']}/conflicts")->assertOk()->assertJsonPath('data.0.conflict', 'session_room_too_small')
            ->assertJsonPath('data.0.message', __('errors.session_room_too_small'));
        $this->postJson("/api/timetable-versions/{$version['id']}/validate", ['version' => 1])->assertConflict()->assertJsonPath('code', 'timetable_conflicts')
            ->assertJsonPath('message', __('errors.timetable_conflicts'));
        $this->putJson("/api/rooms/{$s['room']}", ['capacity' => 30, 'version' => $room['version'] + 1])->assertOk();
        // AC2: a change before publication, told to nobody; the copy out of date, validated once copied again.
        $cancelled = $this->postJson("/api/class-sessions/{$session['id']}/cancel", ['reason' => 'Réunion des parents', 'version' => 1])->assertOk()->json('data');
        $this->assertSame(0, $this->notices($karim, 'schedule.session_cancelled'));
        $this->getJson("/api/timetable-versions/{$version['id']}/compare")->assertOk()->assertJsonPath('data.0.change', 'removed')
            ->assertJsonPath('data.0.before.group_code', '4AM-1')->assertJsonPath('data.0.before.start_time', '08:00')->assertJsonPath('data.0.after', null);
        $this->postJson("/api/timetable-versions/{$version['id']}/validate", ['version' => 1])->assertConflict()->assertJsonPath('code', 'timetable_version_stale');
        $again = $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'room_id' => $s['room'],
            'day' => $day, 'start_time' => '10:00', 'end_time' => '11:30'])->assertCreated()->json('data');
        $this->postJson("/api/timetable-versions/{$version['id']}/refresh", ['version' => 1])->assertOk()->assertJsonPath('data.sessions', 1);
        $this->postJson("/api/timetable-versions/{$version['id']}/publish", ['version' => 2])->assertConflict()->assertJsonPath('code', 'timetable_version_not_validated');
        $this->postJson("/api/timetable-versions/{$version['id']}/validate", ['version' => 2])->assertOk()->assertJsonPath('data.status', 'validated');
        $this->assertSame(0, $this->notices($karim, 'schedule.timetable_published'));
        $this->postJson("/api/timetable-versions/{$version['id']}/publish", ['version' => 3])->assertOk()->assertJsonPath('data.status', 'published')
            ->assertJsonPath('told', 1);
        $this->assertSame(1, $this->notices($karim, 'schedule.timetable_published'));
        // Once published, a change is told.
        $this->postJson("/api/class-sessions/{$again['id']}/cancel", ['reason' => 'Examen blanc', 'version' => 1])->assertOk();
        $this->assertSame(1, $this->notices($karim, 'schedule.session_cancelled'));
        // A teacher neither prepares nor publishes, nor reads the versions.
        $this->signIn($karim)->assertOk();
        $this->getJson('/api/timetable-versions')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/timetable-versions/{$version['id']}/publish", ['version' => 4])->assertForbidden();
        $this->get('/school/timetable-versions')->assertForbidden();
        $this->assertNotNull($cancelled);
    }

    public function test_the_publications_page_prepares_validates_publishes_and_compares(): void
    {
        $s = $this->setting();
        $day = $this->next(Carbon::MONDAY);
        $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'room_id' => $s['room'],
            'day' => $day, 'start_time' => '08:00', 'end_time' => '09:30'])->assertCreated();
        $this->get('/school/timetable-versions')->assertOk()->assertSee(__('planning.prepare_version'));
        $this->within($s['school'], $s['manager'], function () use ($s, $day) {
            Livewire::test(TimetableVersions::class)->callTableAction('prepare', data: ['date_from' => $day, 'date_to' => $day, 'reason' => 'Semaine de rentrée'])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $id = DB::table('school.timetable_version')->where('tenant_id', $s['school']->id)->value('id');
            Livewire::test(TimetableVersions::class)->assertSee('Semaine de rentrée')->assertSee(__('planning.version_states.draft'))
                ->mountTableAction('conflicts', $id)->assertMountedActionModalSee(__('planning.no_conflict'));
            Livewire::test(TimetableVersions::class)->callTableAction('validate', $id)->assertNotified(__('school.saved'));
            Livewire::test(TimetableVersions::class)->callTableAction('publish', $id)->assertNotified(__('school.saved'));
            $this->assertSame('published', DB::table('school.timetable_version')->where('id', $id)->value('status'));
            // Compared with the sessions as they are: one added since.
            $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'room_id' => $s['room'],
                'day' => $day, 'start_time' => '14:00', 'end_time' => '15:00'])->assertCreated();
            Livewire::test(TimetableVersions::class)->mountTableAction('compare', $id)->assertMountedActionModalSee(__('planning.change_list.added'))->assertMountedActionModalSee('14:00–15:00');
        });
    }
}
