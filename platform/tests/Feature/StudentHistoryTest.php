<?php

namespace Tests\Feature;

use App\Filament\School\Pages\StudentSheet;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A pupil's history (FR-ELV-01, SQL 047) through the real route and the pupil's sheet: their
 * record's changes and states, their enrolments, groups and guardians, newest first, as the
 * reader may see them, never the values the journal masks.
 */
final class StudentHistoryTest extends TestCase
{
    public function test_the_sheet_tells_what_happened_to_the_pupil_as_the_reader_may_see_it(): void
    {
        $manager = $this->account('history');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $site = DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => 'SCI-1', 'labels' => ['fr' => 'Sciences'], 'base_price' => '1000',
            'status' => 'active'])->assertCreated()->json('data.id');
        $group = $this->postJson('/api/groups', ['year_id' => $year, 'program_id' => $program, 'site_id' => $site, 'code' => 'SCI-A', 'labels' => ['fr' => 'Classe'],
            'group_type' => 'class', 'capacity' => 25, 'valid_from' => now()->toDateString(), 'status' => 'active'])->assertCreated()->json('data.id');
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '1995-01-01'])->assertCreated()->json('data');
        $pupil = $this->putJson("/api/students/{$pupil['id']}", ['phone' => '0550 77 88 99', 'version' => $pupil['version']])->assertOk()->json('data');
        $guardian = $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550 65 43 21'])->assertCreated()->json('data.id');
        $this->postJson("/api/students/{$pupil['id']}/guardians", ['guardian_id' => $guardian, 'relationship' => 'father'])->assertCreated();
        $enrollment = $this->postJson('/api/enrollments', ['student_id' => $pupil['id'], 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->json('data');
        $this->postJson("/api/groups/$group/memberships", ['enrollment_id' => $enrollment['id']])->assertCreated();
        $this->postJson("/api/students/{$pupil['id']}/status", ['status' => 'blocked', 'reason' => 'Impayés répétés', 'version' => $pupil['version'],
            'confirm_enrollments' => true])->assertOk();

        $history = $this->getJson("/api/students/{$pupil['id']}/history")->assertOk()->json('data');
        $kinds = array_column($history, 'kind');
        foreach (['created', 'updated', 'status', 'enrollment', 'joined', 'guardian_linked'] as $kind) $this->assertContains($kind, $kinds);
        $this->assertSame('status', $history[0]['kind']);
        $this->assertEquals(['blocked', 'Impayés répétés', 'history'], [$history[0]['status'], $history[0]['reason'], $history[0]['actor']]);
        $this->assertSame(['phone'], collect($history)->firstWhere('kind', 'updated')['fields']);
        $this->assertStringNotContainsString('0550 77 88 99', json_encode($history));

        // A reader of the pupils only reads their record's history, not their enrolments' nor their groups'.
        $reader = $this->account('reader');
        $this->member($school, $reader, 'active', ['supervisor']);
        $this->signIn($reader)->assertOk();
        $kinds = array_column($this->getJson("/api/students/{$pupil['id']}/history")->assertOk()->json('data'), 'kind');
        $this->assertContains('status', $kinds);
        $this->assertNotContains('enrollment', $kinds);

        $this->actingAs($this->person($manager));
        $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($school, $manager, $pupil) {
            app(CurrentSchool::class)->enter($school->id, $manager['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            Livewire::withQueryParams(['student' => $pupil['id']])->test(StudentSheet::class)->assertSee(__('schooling.history'))
                ->assertSee('Statut : bloqué')->assertSee('Entrée dans le groupe SCI-A')->assertSee('Impayés répétés');
        });
    }
}
