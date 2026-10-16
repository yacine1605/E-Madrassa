<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Groups;
use App\Filament\School\Pages\RoomClosures;
use App\Filament\School\Pages\RoomOptions;
use App\Filament\School\Pages\Rooms;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rooms (FR-SLL-02, FR-SLL-01, FR-GRP-01, SQL 037) through their real routes and screens: a
 * site's rooms with their type and equipment among the school's lists, dated closures, a group's
 * main room never smaller than the group.
 */
final class RoomTest extends TestCase
{
    /** @return array{0: object, 1: array, 2: string, 3: string} a school with an annex, its pedagogy and HR manager signed in, its two sites */
    private function setting(): array
    {
        $manager = $this->account('rooms');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $main = DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        $annex = $this->postJson('/api/sites', ['code' => 'ANNEXE', 'labels' => ['fr' => 'Annexe']])->assertCreated()->json('data.id');
        return [$school, $manager, $main, $annex];
    }

    private function room(string $site, string $code, int $capacity = 30, array $extra = []): array
    {
        return ['site_id' => $site, 'code' => $code, 'labels' => ['fr' => 'Salle '.$code, 'ar' => 'قاعة '.$code], 'capacity' => $capacity,
            'room_type' => 'CLASSROOM', ...$extra];
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

    public function test_a_room_is_described_with_the_schools_lists_closed_on_dated_days_and_archived_never_deleted(): void
    {
        [$school, , $main, $annex] = $this->setting();
        // The publisher's lists come with the module.
        $this->getJson('/api/room-options?kind=room_type')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.code', 'CLASSROOM');
        $this->getJson('/api/room-options?kind=equipment')->assertOk()->assertJsonCount(6, 'data');
        $room = $this->postJson('/api/rooms', $this->room($main, 'A101', 30, ['equipment' => ['WHITEBOARD', 'PROJECTOR']]))->assertCreated()
            ->assertJsonPath('data.equipment', ['PROJECTOR', 'WHITEBOARD'])->assertJsonPath('data.status', 'active')->json('data');
        $this->postJson('/api/rooms', $this->room($main, 'A101'))->assertConflict();
        $this->postJson('/api/rooms', $this->room($annex, 'A101'))->assertCreated();
        $this->postJson('/api/rooms', $this->room($main, 'A102', 0))->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->postJson('/api/rooms', $this->room($main, 'A102', 30, ['room_type' => 'POOL']))->assertConflict()->assertJsonPath('code', 'room_option_unknown');
        $this->postJson('/api/rooms', $this->room($main, 'LAB-1', 20, ['room_type' => 'LAB', 'equipment' => ['LAB_BENCHES']]))->assertCreated();
        // Found by site, type, places and equipment.
        $this->getJson("/api/rooms?site_id=$main&min_capacity=25")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'A101');
        $this->getJson('/api/rooms?equipment=LAB_BENCHES')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'LAB-1');
        $this->putJson("/api/rooms/{$room['id']}", ['capacity' => 32, 'status' => 'unavailable', 'code' => 'B1', 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $room = $this->putJson("/api/rooms/{$room['id']}", ['capacity' => 32, 'status' => 'unavailable', 'version' => 1])->assertOk()
            ->assertJsonPath('data.capacity', 32)->assertJsonPath('data.status', 'unavailable')->json('data');
        $this->putJson("/api/rooms/{$room['id']}", ['capacity' => 33, 'version' => 1])->assertConflict()->assertJsonPath('code', 'stale_version');

        // Dated closures: never two at a time, cancelled until their last day.
        $day = now()->addDays(3)->toDateString();
        $closure = $this->postJson("/api/rooms/{$room['id']}/closures", ['date_from' => $day, 'date_to' => now()->addDays(5)->toDateString(),
            'reason' => 'Travaux de peinture'])->assertCreated()->assertJsonPath('data.days', 3)->json('data');
        $this->postJson("/api/rooms/{$room['id']}/closures", ['date_from' => now()->addDays(5)->toDateString(), 'date_to' => now()->addDays(6)->toDateString(),
            'reason' => 'Autres travaux'])->assertConflict()->assertJsonPath('code', 'room_closure_overlap');
        $this->postJson("/api/rooms/{$room['id']}/closures", ['date_from' => now()->subDay()->toDateString(), 'date_to' => $day, 'reason' => 'Hier'])
            ->assertConflict()->assertJsonPath('code', 'room_closure_past');
        $this->getJson("/api/rooms/{$room['id']}")->assertOk()->assertJsonPath('data.next_closure.date_from', $day);
        $this->postJson("/api/room-closures/{$closure['id']}/cancel", ['version' => 1])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson("/api/rooms/{$room['id']}/closures")->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/rooms/{$room['id']}/closures?include_cancelled=1")->assertOk()->assertJsonPath('meta.total', 1);

        // The school extends its lists, and archives an entry: no longer offered, kept by the rooms that have it.
        $music = $this->postJson('/api/room-options', ['kind' => 'room_type', 'code' => 'MUSIC', 'labels' => ['fr' => 'Salle de musique', 'ar' => 'قاعة موسيقى']])
            ->assertCreated()->json('data');
        $studio = $this->postJson('/api/rooms', $this->room($annex, 'MUS-1', 15, ['room_type' => 'MUSIC']))->assertCreated()->json('data');
        $this->postJson("/api/room-options/{$music['id']}/archive", ['reason' => 'Plus de cours de musique'])->assertOk();
        $this->postJson('/api/rooms', $this->room($annex, 'MUS-2', 15, ['room_type' => 'MUSIC']))->assertConflict()->assertJsonPath('code', 'room_option_unknown');
        $this->putJson("/api/rooms/{$studio['id']}", ['capacity' => 16, 'version' => 1])->assertOk()->assertJsonPath('data.room_type', 'MUSIC');
        $this->getJson('/api/room-options?kind=room_type&include_archived=1')->assertOk()->assertJsonPath('data.0.code', 'MUSIC')->assertJsonPath('data.0.rooms', 1);

        // FR-SLL-01: a site with rooms in use stays; archived, a room stays as it is.
        $this->postJson("/api/sites/$annex/archive", ['reason' => 'Fermeture de l’annexe'])->assertConflict()->assertJsonPath('code', 'site_has_rooms');
        foreach (DB::connection('owner')->table('school.room')->where('site_id', $annex)->get() as $kept) {
            $this->postJson("/api/rooms/{$kept->id}/archive", ['reason' => 'Annexe fermée', 'version' => $kept->version])->assertOk();
        }
        $this->postJson("/api/sites/$annex/archive", ['reason' => 'Fermeture de l’annexe'])->assertOk();
        $this->putJson("/api/rooms/{$studio['id']}", ['capacity' => 17, 'version' => 3])->assertConflict()->assertJsonPath('code', 'room_archived');
        $this->getJson('/api/rooms')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/rooms?include_archived=1')->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_a_school_not_in_use_receives_the_module_without_the_lists_and_takes_them_back_once_in_use(): void
    {
        [$school] = $this->setting();
        // As a school suspended when it received the module: no list.
        DB::connection('owner')->statement('ALTER TABLE school.room_option DISABLE TRIGGER USER');
        DB::connection('owner')->table('school.room_option')->where('tenant_id', $school->id)->whereIn('code', ['LAB', 'PROJECTOR', 'SMARTBOARD'])->delete();
        DB::connection('owner')->statement('ALTER TABLE school.room_option ENABLE TRIGGER USER');
        $whiteboard = DB::connection('owner')->table('school.room_option')->where('tenant_id', $school->id)->where('code', 'WHITEBOARD')->value('id');
        $this->postJson("/api/room-options/$whiteboard/archive", ['reason' => 'Plus de tableaux blancs'])->assertOk();
        $this->postJson('/api/room-options/defaults')->assertOk()->assertJsonPath('data.added', 3);
        $this->postJson('/api/room-options/defaults')->assertOk()->assertJsonPath('data.added', 0);
        $this->getJson('/api/room-options?kind=equipment')->assertOk()->assertJsonCount(5, 'data');
        // A school that is not in use writes nothing: the console still gives it the module.
        DB::connection('console')->select('SELECT platform.set_tenant_status(?, ?, ?)', [$school->id, 'suspended', 'Impayé']);
        DB::connection('console')->select('SELECT platform.disable_module(?, ?)', [$school->id, 'sites_rooms']);
        DB::connection('console')->select('SELECT platform.enable_module(?, ?)', [$school->id, 'sites_rooms']);
        $this->assertTrue(DB::connection('owner')->table('platform.tenant_module')->where('tenant_id', $school->id)->where('module_key', 'sites_rooms')->exists());
    }

    public function test_rooms_are_read_with_the_referential_and_written_on_the_sites_of_the_grant(): void
    {
        [$school, , $main, $annex] = $this->setting();
        $room = $this->postJson('/api/rooms', $this->room($main, 'A101'))->assertCreated()->json('data');
        // The direction reads them and changes none.
        $direction = $this->account('direction');
        $this->member($school, $direction, 'active', ['direction']);
        $this->signIn($direction)->assertOk();
        $this->getJson('/api/rooms')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/rooms', $this->room($main, 'A102'))->assertForbidden();
        // A grant limited to the annex manages the annex's rooms, not the lists.
        $local = $this->account('annex');
        $this->member($school, $local);
        $this->grant($school, $local, 'academic_hr', 'site', [$annex]);
        $this->signIn($local)->assertOk();
        $this->postJson('/api/rooms', $this->room($annex, 'B1'))->assertCreated();
        $this->postJson('/api/rooms', $this->room($main, 'B2'))->assertForbidden();
        $this->putJson("/api/rooms/{$room['id']}", ['capacity' => 10, 'version' => 1])->assertForbidden();
        $this->postJson("/api/rooms/{$room['id']}/closures", ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString(), 'reason' => 'Travaux'])
            ->assertForbidden();
        $this->postJson('/api/room-options', ['kind' => 'equipment', 'code' => 'PIANO', 'labels' => ['fr' => 'Piano']])->assertForbidden();
    }

    public function test_a_groups_main_room_is_on_its_site_and_never_smaller_than_the_group(): void
    {
        [, , $main, $annex] = $this->setting();
        $small = $this->postJson('/api/rooms', $this->room($main, 'PETITE', 20))->assertCreated()->json('data.id');
        $big = $this->postJson('/api/rooms', $this->room($main, 'GRANDE', 30))->assertCreated()->json('data.id');
        $far = $this->postJson('/api/rooms', $this->room($annex, 'LOIN', 40))->assertCreated()->json('data.id');
        $year = $this->postJson('/api/years', ['code' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'current'])
            ->assertCreated()->json('data.id');
        $group = fn (array $extra) => $this->postJson('/api/groups', ['year_id' => $year, 'site_id' => $main, 'code' => 'SOUTIEN-'.count($extra),
            'labels' => ['fr' => 'Soutien'], 'group_type' => 'support', 'capacity' => 25, 'valid_from' => '2026-09-01', 'status' => 'active', ...$extra]);
        // AC1: a room of 20 places for a group of 25 is a capacity conflict.
        $group(['main_room_id' => $small])->assertConflict()->assertJsonPath('code', 'room_too_small');
        $group(['main_room_id' => $far])->assertConflict()->assertJsonPath('code', 'room_other_site');
        $group(['main_room_id' => '00000000-0000-4000-8000-000000000000'])->assertUnprocessable()->assertJsonValidationErrors('main_room_id');
        $created = $group(['main_room_id' => $big])->assertCreated()->assertJsonPath('data.main_room.code', 'GRANDE')->assertJsonPath('data.main_room.capacity', 30)->json('data');
        $this->putJson("/api/groups/{$created['id']}", ['capacity' => 31, 'version' => 1])->assertConflict()->assertJsonPath('code', 'room_too_small');
        $this->putJson("/api/rooms/$big", ['capacity' => 24, 'version' => 1])->assertConflict()->assertJsonPath('code', 'room_too_small');
        $this->postJson("/api/rooms/$big/archive", ['reason' => 'Démolition', 'version' => 1])->assertConflict()->assertJsonPath('code', 'room_in_use');
        $this->getJson("/api/groups?main_room_id=$big")->assertOk()->assertJsonPath('meta.total', 1);
        $this->putJson("/api/groups/{$created['id']}", ['main_room_id' => null, 'version' => 1])->assertOk()->assertJsonPath('data.main_room', null);
    }

    public function test_the_pages_record_rooms_their_closures_and_the_lists_and_a_group_names_its_main_room(): void
    {
        [$school, $manager, $main] = $this->setting();
        $this->get('/school/rooms')->assertOk()->assertSee(__('reference.create_room'));
        $this->get('/school/room-options')->assertOk()->assertSee('CLASSROOM');
        $this->within($school, $manager, function () use ($main) {
            Livewire::test(Rooms::class)->callTableAction('create', data: ['site_id' => $main, 'code' => 'A101', 'labels' => ['fr' => 'Salle 101', 'ar' => 'قاعة 101'],
                'capacity' => '28', 'room_type' => 'COMPUTER', 'equipment' => ['COMPUTERS', 'PROJECTOR'], 'status' => 'active'])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $room = DB::table('school.room')->where('code', 'A101')->first();
            $this->assertEquals([28, 'COMPUTER', '{COMPUTERS,PROJECTOR}'], [$room->capacity, $room->room_type, $room->equipment]);
            Livewire::test(Rooms::class)->assertCanSeeTableRecords([$room->id])->assertSee('Salle informatique')
                ->callTableAction('edit', $room->id, data: ['capacity' => '30', 'equipment' => ['COMPUTERS']])->assertHasNoTableActionErrors();
            $this->assertSame(30, DB::table('school.room')->where('id', $room->id)->value('capacity'));
            $closures = fn () => Livewire::withQueryParams(['room' => $room->id])->test(RoomClosures::class);
            $closures()->assertSee(__('reference.no_closure'))->callTableAction('add', data: ['date_from' => now()->addDay()->toDateString(),
                'date_to' => now()->addDays(2)->toDateString(), 'reason' => 'Désinfection'])->assertNotified(__('school.saved'));
            $closure = DB::table('school.room_closure')->where('room_id', $room->id)->value('id');
            $closures()->assertSee('Désinfection')->callTableAction('cancel', $closure)->assertNotified(__('school.saved'));
            $this->assertSame('cancelled', DB::table('school.room_closure')->where('id', $closure)->value('status'));
            Livewire::test(RoomOptions::class)->callTableAction('create', data: ['kind' => 'equipment', 'code' => 'PIANO',
                'labels' => ['fr' => 'Piano', 'ar' => 'بيانو'], 'sort_order' => '7'])->assertNotified(__('school.saved'));
            $this->assertTrue(DB::table('school.room_option')->where('code', 'PIANO')->exists());
        });
        // A group names it as its main room, among its site's rooms.
        $room = DB::connection('owner')->table('school.room')->where('tenant_id', $school->id)->where('code', 'A101')->value('id');
        $year = $this->postJson('/api/years', ['code' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'current'])
            ->assertCreated()->json('data.id');
        $group = $this->postJson('/api/groups', ['year_id' => $year, 'site_id' => $main, 'code' => 'SOUTIEN', 'labels' => ['fr' => 'Soutien'],
            'group_type' => 'support', 'capacity' => 25, 'valid_from' => '2026-09-01', 'status' => 'active'])->assertCreated()->json('data.id');
        $this->within($school, $manager, function () use ($group, $room) {
            Livewire::test(Groups::class)->callTableAction('edit', $group, data: ['main_room_id' => $room])->assertHasNoTableActionErrors()
                ->assertNotified(__('school.saved'));
            $this->assertSame($room, DB::table('school.study_group')->where('id', $group)->value('main_room_id'));
            Livewire::test(Groups::class)->assertSee('A101 · Salle 101');
        });
    }
}
