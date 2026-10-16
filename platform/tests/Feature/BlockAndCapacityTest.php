<?php

namespace Tests\Feature;

use App\Filament\School\Pages\GroupMembers;
use App\Filament\School\Pages\StudentSheet;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FR-ELV-03 AC1: blocking a pupil names the enrolments it concerns, as the reader may see them,
 * and changes none. BR-07, FR-GRP-02: a full class refuses the next pupil on its screen too.
 */
final class BlockAndCapacityTest extends TestCase
{
    public function test_a_block_names_its_enrolments_and_a_full_class_refuses_the_next_pupil(): void
    {
        $manager = $this->account('block');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $site = DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        $program = fn (string $code) => $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => $code, 'labels' => ['fr' => 'Programme '.$code],
            'base_price' => '1000', 'status' => 'active'])->assertCreated()->json('data.id');
        [$sciences, $languages] = [$program('SCI-1'), $program('LANG-1')];
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '1995-01-01'])->assertCreated()->json('data');
        $enrol = fn (string $student, string $program) => $this->postJson('/api/enrollments', ['student_id' => $student, 'program_id' => $program,
            'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $enrol($pupil['id'], $sciences);
        $enrol($pupil['id'], $languages);
        // AC1: the refusal names both, the pupil and the enrolments unchanged.
        $this->postJson("/api/students/{$pupil['id']}/status", ['status' => 'blocked', 'reason' => 'Impayés', 'version' => $pupil['version']])->assertConflict()
            ->assertJsonPath('code', 'student_active_enrollments')->assertJsonPath('active_enrollments', 2)
            ->assertJsonPath('enrollments.0.program', 'SCI-1')->assertJsonPath('enrollments.1.program', 'LANG-1');
        $this->getJson("/api/students/{$pupil['id']}")->assertOk()->assertJsonPath('data.status', 'active');

        $group = $this->postJson('/api/groups', ['year_id' => $year, 'program_id' => $sciences, 'site_id' => $site, 'code' => 'SCI-A', 'labels' => ['fr' => 'Classe'],
            'group_type' => 'class', 'capacity' => 1, 'valid_from' => now()->toDateString(), 'status' => 'active'])->assertCreated()->json('data.id');
        $this->postJson("/api/groups/$group/memberships", ['enrollment_id' => DB::connection('owner')->table('school.enrollment')
            ->where('student_id', $pupil['id'])->where('program_id', $sciences)->value('id')])->assertCreated();
        $other = $this->postJson('/api/students', ['last_name_lat' => 'Kaci', 'first_name_lat' => 'Nadia', 'birth_date' => '1995-01-01'])->assertCreated()->json('data.id');
        $next = $enrol($other, $sciences);

        $this->actingAs($this->person($manager));
        $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($school, $manager, $pupil, $group, $next) {
            app(CurrentSchool::class)->enter($school->id, $manager['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            // The sheet's warning before the block: the enrolments by name.
            $sheet = Livewire::withQueryParams(['student' => $pupil['id']])->test(StudentSheet::class)->instance();
            $warning = (new \ReflectionMethod($sheet, 'blockWarning'))->invoke($sheet);
            foreach (['Inscriptions non annulées : 2', 'SCI-1 · Programme SCI-1', 'LANG-1 · Programme LANG-1'] as $text) $this->assertStringContainsString($text, $warning);
            // BR-07: the class full, its screen refuses the next pupil with the reason.
            Livewire::withQueryParams(['group' => $group])->test(GroupMembers::class)->callTableAction('add', data: ['enrollment_id' => $next])
                ->assertNotified(__('errors.group_full'));
            $this->assertSame(1, DB::table('school.group_membership')->where('group_id', $group)->count());
        });
    }
}
