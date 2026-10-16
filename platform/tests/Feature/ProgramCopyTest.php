<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Programs;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A programme copied to a later year (FR-PRG-02, SQL 044) through the real route and the
 * Programmes screen: a draft with its subjects and volumes, once, never to an earlier year.
 */
final class ProgramCopyTest extends TestCase
{
    public function test_a_programme_is_copied_to_a_later_year_with_its_subjects_once(): void
    {
        $manager = $this->account('copy');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = fn (string $start, string $end, string $status) => $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => $start,
            'end_date' => $end, 'status' => $status])->assertCreated()->json('data.id');
        $now = $year(now()->subMonths(8)->toDateString(), now()->addMonths(2)->toDateString(), 'current');
        $next = $year(now()->addMonths(3)->toDateString(), now()->addMonths(13)->toDateString(), 'planned');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $subject = DB::connection('owner')->selectOne('SELECT id FROM school.subject WHERE tenant_id = ? AND archived_at IS NULL ORDER BY code LIMIT 1', [$school->id])->id;
        $source = $this->postJson('/api/programs', ['year_id' => $now, 'node_id' => $node, 'code' => 'SCI-1', 'labels' => ['fr' => 'Sciences', 'ar' => 'علوم'],
            'base_price' => '120000.50', 'status' => 'active'])->assertCreated()->json('data');
        $this->postJson("/api/programs/{$source['id']}/subjects", ['subject_id' => $subject, 'total_hours' => '40', 'session_count' => 20])->assertCreated();

        $copy = $this->postJson("/api/programs/{$source['id']}/copy", ['year_id' => $next])->assertCreated()->assertJsonPath('data.code', 'SCI-1')
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.base_price', '120000.50')->assertJsonPath('data.copied_from', $source['id'])
            ->assertJsonPath('data.year_id', $next)->json('data');
        $this->getJson("/api/programs/{$copy['id']}/subjects")->assertOk()->assertJsonPath('data.0.subject_id', $subject)->assertJsonPath('data.0.total_hours', '40.00');
        // The same copy asked again; never to the same or an earlier year.
        $this->postJson("/api/programs/{$source['id']}/copy", ['year_id' => $next])->assertCreated()->assertJsonPath('data.id', $copy['id']);
        $this->postJson("/api/programs/{$copy['id']}/copy", ['year_id' => $now])->assertConflict()->assertJsonPath('code', 'program_copy_year');
        $this->postJson("/api/programs/{$source['id']}/copy", ['year_id' => $now])->assertConflict()->assertJsonPath('code', 'program_copy_year');

        // From the Programmes screen, into another later year.
        $later = $year(now()->addMonths(15)->toDateString(), now()->addMonths(25)->toDateString(), 'planned');
        $this->actingAs($this->person($manager));
        $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($school, $manager, $source, $later) {
            app(CurrentSchool::class)->enter($school->id, $manager['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            Livewire::test(Programs::class)->callTableAction('copy', $source['id'], data: ['year_id' => $later])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $this->assertTrue(DB::table('school.program')->where('year_id', $later)->where('copied_from', $source['id'])->exists());
        });
    }
}
