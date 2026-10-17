<?php

namespace Tests\Feature;

use App\Filament\School\Pages\MyTimetable;
use App\Filament\School\Pages\Planning;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * The planning as it is read (FR-PLN-04, FR-PLN-02): the week's screen that plans, changes and
 * cancels; a teacher's own timetable; a week's timetable in PDF and an agenda file whose hours an
 * agenda shows in Algiers' time (AC1).
 */
final class TimetableTest extends TestCase
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

    private function text(string $pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, $pdf);
        return (string) shell_exec('pdftotext -enc UTF-8 '.escapeshellarg($file).' - 2>&1');
    }

    /** A weekly course on Sundays at eight for four weeks. */
    private function planned(array $s): array
    {
        $first = $this->next(Carbon::SUNDAY);
        return $this->postJson('/api/session-series', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'], 'room_id' => $s['room'],
            'weekday' => 0, 'start_time' => '08:00', 'end_time' => '09:30', 'date_from' => $first, 'date_to' => Carbon::parse($first)->addWeeks(3)->toDateString(),
            'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data') + ['first' => $first];
    }

    public function test_the_agenda_file_shows_the_sessions_at_algiers_hours_and_the_week_prints(): void
    {
        $s = $this->setting();
        $series = $this->planned($s);
        $sessions = $this->getJson("/api/class-sessions?from={$series['first']}&to=".Carbon::parse($series['first'])->addWeeks(4)->toDateString())->json('data');
        $this->postJson("/api/class-sessions/{$sessions[1]['id']}/cancel", ['reason' => 'Sortie', 'version' => $sessions[1]['version']])->assertOk();
        $ics = $this->get("/api/timetable/calendar.ics?group_id={$s['group']}&from={$series['first']}&to=".Carbon::parse($series['first'])->addWeeks(4)->toDateString())
            ->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
        // AC1: each start written in Algiers' time with its zone, described as UTC+1 all year.
        $this->assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("TZID:Africa/Algiers\r\n", $ics);
        $this->assertStringContainsString("TZOFFSETTO:+0100\r\n", $ics);
        $this->assertSame(4, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('DTSTART;TZID=Africa/Algiers:'.Carbon::parse($series['first'])->format('Ymd')."T080000\r\n", $ics);
        $this->assertStringContainsString('DTEND;TZID=Africa/Algiers:'.Carbon::parse($series['first'])->format('Ymd')."T093000\r\n", $ics);
        // The cancelled one too, so that an agenda drops it.
        $this->assertSame(1, substr_count($ics, 'STATUS:CANCELLED'));
        $this->assertStringContainsString("LOCATION:A101\r\n", $ics);
        foreach (explode("\r\n", $ics) as $line) $this->assertLessThanOrEqual(75, strlen($line));
        $this->getJson("/api/timetable/calendar.ics?from={$series['first']}&to={$series['first']}")->assertStatus(422)->assertJsonPath('code', 'timetable_scope_required');
        // The week's timetable of the group and of the teacher.
        $pdf = $this->get("/api/timetable/week.pdf?group_id={$s['group']}&week={$series['first']}")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $text = str_replace("\u{00A0}", ' ', $this->text($pdf));
        foreach (['Emploi du temps', '4AM-1', '08:00–09:30', 'Meziane Karim', 'A101', 'dimanche'] as $expected) $this->assertStringContainsString($expected, $text);
        $text = $this->text($this->get("/api/timetable/week.pdf?teacher_id={$s['teacher']}&week={$series['first']}")->assertOk()->getContent());
        $this->assertStringContainsString('4AM-1', $text);
    }

    public function test_the_planning_page_plans_a_weekly_course_changes_and_cancels_a_session(): void
    {
        $s = $this->setting();
        $this->get('/school/planning')->assertOk()->assertSee(__('planning.plan_series'));
        $first = $this->next(Carbon::MONDAY);
        $this->within($s['school'], $s['manager'], function () use ($s, $first) {
            Livewire::test(Planning::class)->callTableAction('series', data: ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'],
                'room_id' => $s['room'], 'weekday' => 1, 'start_time' => '10:00', 'end_time' => '12:00', 'date_from' => $first,
                'date_to' => Carbon::parse($first)->addWeeks(1)->toDateString()])->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $sessions = DB::table('school.class_session')->where('group_id', $s['group'])->orderBy('starts_at')->get();
            $this->assertCount(2, $sessions);
            $page = Livewire::test(Planning::class)->filterTable('week', ['week' => $first])->assertCanSeeTableRecords([$sessions[0]->id])->assertSee('10:00–12:00');
            $page->callTableAction('change', $sessions[0]->id, data: ['start_time' => '13:00', 'end_time' => '14:00'])->assertHasNoTableActionErrors()
                ->assertNotified(__('school.saved'));
            $this->assertTrue((bool) DB::table('school.class_session')->where('id', $sessions[0]->id)->value('detached'));
            // A conflict is refused with its reason: the same group at the same time.
            Livewire::test(Planning::class)->callTableAction('session', data: ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'],
                'day' => $first, 'start_time' => '13:30', 'end_time' => '14:30'])->assertNotified(__('errors.session_teacher_busy'));
            Livewire::test(Planning::class)->filterTable('week', ['week' => $first])->callTableAction('cancel', $sessions[0]->id, data: ['reason' => 'Examen blanc'])
                ->assertNotified(__('school.saved'));
            $this->assertSame('cancelled', DB::table('school.class_session')->where('id', $sessions[0]->id)->value('status'));
        });
    }

    public function test_a_teacher_reads_their_own_timetable_and_its_agenda(): void
    {
        $s = $this->setting();
        $series = $this->planned($s);
        $other = $this->postJson('/api/staff', ['last_name_lat' => 'Haddad', 'first_name_lat' => 'Samira', 'staff_type' => 'teacher_external'])->json('data.id');
        $this->postJson("/api/staff/$other/qualifications", ['subject_id' => $s['maths'], 'node_id' => $s['node']])->assertCreated();
        $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $other, 'day' => $series['first'],
            'start_time' => '10:00', 'end_time' => '11:00'])->assertCreated();
        $account = $this->account('karim');
        $this->member($s['school'], $account, 'active');
        $this->grant($s['school'], $account, 'teacher', 'personal');
        $record = $this->getJson("/api/staff/{$s['teacher']}")->json('data');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $account['id'], 'version' => $record['version']])->assertOk();
        $this->signIn($account)->assertOk();
        $to = Carbon::parse($series['first'])->addWeeks(4)->toDateString();
        $this->getJson("/api/timetable/mine?from={$series['first']}&to=$to")->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.group_code', '4AM-1');
        $ics = $this->get("/api/timetable/calendar.ics?mine=1&from={$series['first']}&to=$to")->assertOk()->getContent();
        $this->assertSame(4, substr_count($ics, 'BEGIN:VEVENT'));
        $this->get('/school/my-timetable')->assertOk();
        $this->within($s['school'], $account, function () use ($series) {
            Livewire::test(MyTimetable::class)->filterTable('from', ['from' => $series['first']])->assertSee('08:00–09:30')->assertDontSee('10:00–11:00');
        });
        // The manager, who teaches nothing, has no such page.
        $this->signIn($s['manager'])->assertOk();
        $this->get('/school/my-timetable')->assertForbidden();
    }
}
