<?php

namespace Tests\Feature;

use App\Filament\School\Pages\SchoolCalendar;
use App\Filament\School\Pages\TimeSlots;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The school's calendar (FR-PLN-01, SQL 061) through its real routes and screens: the week's open
 * days, dated closures and exceptional openings for the whole school or a site, the grid of time
 * slots; kept by `schedule.calendar.manage`, read with the planning.
 */
final class CalendarTest extends TestCase
{
    /** @return array{0: object, 1: array, 2: string, 3: string} a school with an annex, its pedagogy and HR manager signed in, its two sites */
    private function setting(): array
    {
        $manager = $this->account('planning');
        $school = $this->school('pro', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $main = DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        $annex = $this->postJson('/api/sites', ['code' => 'ANNEXE', 'labels' => ['fr' => 'Annexe']])->assertCreated()->json('data.id');
        return [$school, $manager, $main, $annex];
    }

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

    /** The next day of the week (0 Sunday … 6 Saturday) at least `$after` days from today. */
    private function next(int $weekday, int $after = 7): string
    {
        $day = now()->addDays($after);
        while ($day->dayOfWeek !== $weekday) $day = $day->addDay();
        return $day->toDateString();
    }

    public function test_dated_closures_and_openings_prevail_over_the_week_a_sites_over_the_schools(): void
    {
        [, , $main, $annex] = $this->setting();
        [$sunday, $friday] = [$this->next(Carbon::SUNDAY), $this->next(Carbon::FRIDAY)];
        $monday = Carbon::parse($sunday)->addDay()->toDateString();
        $open = fn (string $day, ?string $site = null) => $this->getJson("/api/calendar/days?from=$day&to=$day".($site ? "&site_id=$site" : ''))
            ->assertOk()->json('data.0.open');
        // Sunday to Thursday by default.
        $this->assertSame([true, false], [$open($sunday), $open($friday, $main)]);
        $holiday = $this->postJson('/api/calendar-exceptions', ['kind' => 'holiday', 'date_from' => $monday, 'date_to' => $monday, 'reason' => 'Fête de l’indépendance'])
            ->assertCreated()->assertJsonPath('data.site_id', null)->assertJsonPath('data.days', 1)->json('data');
        $this->postJson('/api/calendar-exceptions', ['site_id' => $main, 'kind' => 'opening', 'date_from' => $friday, 'date_to' => $friday, 'reason' => 'Rattrapage'])
            ->assertCreated();
        $this->assertSame([false, false, true, false], [$open($monday, $main), $open($monday, $annex), $open($friday, $main), $open($friday, $annex)]);
        $this->getJson("/api/calendar/days?from=$friday&to=$friday&site_id=$main")->assertOk()->assertJsonPath('data.0.kind', 'opening')
            ->assertJsonPath('data.0.weekday', 5)->assertJsonPath('data.0.reason', 'Rattrapage');
        $this->postJson('/api/calendar-exceptions', ['kind' => 'closure', 'date_from' => $sunday, 'date_to' => $monday, 'reason' => 'Chevauche'])
            ->assertConflict()->assertJsonPath('code', 'calendar_exception_overlap');
        $this->postJson('/api/calendar-exceptions', ['kind' => 'closure', 'date_from' => now()->subDay()->toDateString(), 'date_to' => $sunday, 'reason' => 'Hier'])
            ->assertConflict()->assertJsonPath('code', 'calendar_exception_past');
        $this->postJson('/api/calendar-exceptions', ['kind' => 'party', 'date_from' => $sunday, 'date_to' => $sunday, 'reason' => 'Fête'])
            ->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->getJson('/api/calendar/days?from='.now()->toDateString().'&to='.now()->addDays(100)->toDateString())
            ->assertConflict()->assertJsonPath('code', 'calendar_range_too_long');
        // Listed for a site with the whole school's; cancelled, the week's rule is back.
        $this->getJson("/api/calendar-exceptions?site_id=$annex")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/calendar-exceptions')->assertOk()->assertJsonPath('meta.total', 2);
        $this->postJson("/api/calendar-exceptions/{$holiday['id']}/cancel", ['version' => 2])->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->postJson("/api/calendar-exceptions/{$holiday['id']}/cancel", ['version' => 1])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertTrue($open($monday, $annex));
        $this->getJson('/api/calendar-exceptions?include_cancelled=1')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_the_grid_of_time_slots_keeps_its_codes_and_times(): void
    {
        $this->setting();
        $first = $this->postJson('/api/time-slots', ['code' => 'M1', 'start_time' => '08:00', 'end_time' => '09:30'])->assertCreated()
            ->assertJsonPath('data.minutes', 90)->json('data');
        $this->postJson('/api/time-slots', ['code' => 'SOIR', 'start_time' => '20:00', 'end_time' => '24:00'])->assertCreated()->assertJsonPath('data.end_time', '24:00');
        $this->postJson('/api/time-slots', ['code' => 'M1', 'start_time' => '10:00', 'end_time' => '11:00'])->assertConflict()->assertJsonPath('code', 'duplicate');
        $this->postJson('/api/time-slots', ['code' => 'X', 'start_time' => '11:00', 'end_time' => '10:00'])->assertConflict();
        $this->postJson('/api/time-slots', ['code' => 'Y', 'start_time' => '8h', 'end_time' => '10:00'])->assertUnprocessable()->assertJsonValidationErrors('start_time');
        $this->getJson('/api/time-slots')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.code', 'M1');
        $this->postJson("/api/time-slots/{$first['id']}/archive", ['reason' => 'Nouvel horaire'])->assertOk();
        $this->postJson("/api/time-slots/{$first['id']}/archive", ['reason' => 'Encore'])->assertNotFound();
        $this->getJson('/api/time-slots')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/time-slots?include_archived=1')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_calendar_is_read_with_the_planning_and_kept_on_the_sites_of_the_grant(): void
    {
        [$school, , $main, $annex] = $this->setting();
        $day = $this->next(Carbon::SUNDAY);
        // A teacher reads it and changes nothing.
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher)->assertOk();
        $this->getJson("/api/calendar/days?from=$day&to=$day")->assertOk()->assertJsonPath('data.0.open', true);
        $this->getJson('/api/time-slots')->assertOk();
        $this->postJson('/api/calendar-exceptions', ['kind' => 'closure', 'date_from' => $day, 'date_to' => $day, 'reason' => 'Grève'])->assertForbidden();
        // A grant on the annex keeps the annex's calendar only, and no slot.
        $local = $this->account('annex');
        $this->member($school, $local);
        $this->grant($school, $local, 'academic_hr', 'site', [$annex]);
        $this->signIn($local)->assertOk();
        $this->postJson('/api/calendar-exceptions', ['site_id' => $annex, 'kind' => 'closure', 'date_from' => $day, 'date_to' => $day, 'reason' => 'Coupure d’eau'])
            ->assertCreated();
        $this->postJson('/api/calendar-exceptions', ['site_id' => $main, 'kind' => 'closure', 'date_from' => $day, 'date_to' => $day, 'reason' => 'Ailleurs'])
            ->assertForbidden();
        $this->postJson('/api/calendar-exceptions', ['kind' => 'closure', 'date_from' => $day, 'date_to' => $day, 'reason' => 'Partout'])->assertForbidden();
        $this->postJson('/api/time-slots', ['code' => 'LOCAL', 'start_time' => '12:00', 'end_time' => '13:00'])->assertForbidden();
        // Without the planning module (and the attendance that needs it), none of it.
        DB::connection('console')->select('SELECT platform.disable_module(?, ?)', [$school->id, 'attendance']);
        DB::connection('console')->select('SELECT platform.disable_module(?, ?)', [$school->id, 'scheduling_manual']);
        $this->getJson('/api/time-slots')->assertForbidden();
    }

    public function test_the_pages_record_closures_openings_and_slots(): void
    {
        [$school, $manager, $main] = $this->setting();
        $this->get('/school/school-calendar')->assertOk()->assertSee(__('planning.add_exception'))->assertSee('dimanche, lundi, mardi, mercredi, jeudi');
        $this->get('/school/time-slots')->assertOk()->assertSee(__('planning.add_slot'));
        $day = $this->next(Carbon::SATURDAY);
        $this->within($school, $manager, function () use ($main, $day) {
            Livewire::test(SchoolCalendar::class)->assertSee(__('planning.no_exception'))->callTableAction('add', data: ['site_id' => $main, 'kind' => 'opening',
                'date_from' => $day, 'date_to' => $day, 'reason' => 'Journée portes ouvertes'])->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $exception = DB::table('school.calendar_exception')->where('reason', 'Journée portes ouvertes')->first();
            $this->assertSame([$main, 'opening'], [$exception->site_id, $exception->kind]);
            Livewire::test(SchoolCalendar::class)->callTableAction('add', data: ['site_id' => 'school', 'kind' => 'vacation', 'date_from' => $day,
                'date_to' => Carbon::parse($day)->addDays(3)->toDateString(), 'reason' => 'Vacances'])->assertNotified(__('school.saved'));
            $this->assertNull(DB::table('school.calendar_exception')->where('reason', 'Vacances')->value('site_id'));
            Livewire::test(SchoolCalendar::class)->assertSee('Journée portes ouvertes')->assertSee(__('planning.whole_school'))
                ->callTableAction('cancel', $exception->id)->assertNotified(__('school.saved'));
            $this->assertSame('cancelled', DB::table('school.calendar_exception')->where('id', $exception->id)->value('status'));
            Livewire::test(TimeSlots::class)->assertSee(__('planning.no_slot'))->callTableAction('create', data: ['code' => 'M1', 'start_time' => '08:00',
                'end_time' => '09:30'])->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $slot = DB::table('school.time_slot')->where('code', 'M1')->value('id');
            Livewire::test(TimeSlots::class)->assertSee('09:30')->callTableAction('archive', $slot, data: ['reason' => 'Nouvel horaire'])
                ->assertNotified(__('school.saved'));
            $this->assertNotNull(DB::table('school.time_slot')->where('id', $slot)->value('archived_at'));
        });
    }
}
