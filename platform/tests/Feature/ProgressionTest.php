<?php

namespace Tests\Feature;

use App\Filament\School\Pages\GroupMembers;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The annual progression (FR-INS-05, SQL 040) through the real routes and a class's screen: a
 * batch of decisions all or nothing, once, into a later year's class at the right level.
 */
final class ProgressionTest extends TestCase
{
    /** @return array{0: object, 1: array, 2: array<string, string>, 3: callable} a school, its head of studies signed in, its classes, how to validate a pupil in the first */
    private function setting(): array
    {
        $manager = $this->account('progression');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $owner = DB::connection('owner');
        [$first, $second] = array_column($owner->select("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' AND archived_at IS NULL
            ORDER BY code LIMIT 2", [$school->id]), 'id');
        $site = $owner->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        $year = fn (string $code, string $start, string $end, string $status) => $this->postJson('/api/years', ['code' => $code, 'start_date' => $start,
            'end_date' => $end, 'status' => $status])->assertCreated()->json('data.id');
        $now = $year('Y'.Str::upper(Str::random(6)), now()->subMonths(8)->toDateString(), now()->addMonths(2)->toDateString(), 'current');
        $next = $year('N'.Str::upper(Str::random(6)), now()->addMonths(3)->toDateString(), now()->addMonths(13)->toDateString(), 'planned');
        $class = function (string $year, string $node, int $capacity = 10) use ($site) {
            $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => Str::upper(Str::random(8)), 'labels' => ['fr' => 'Programme'],
                'base_price' => '100000', 'status' => 'active'])->assertCreated()->json('data.id');
            $start = DB::connection('owner')->table('school.academic_year')->where('id', $year)->value('start_date');
            return $this->postJson('/api/groups', ['year_id' => $year, 'program_id' => $program, 'site_id' => $site, 'code' => Str::upper(Str::random(8)),
                'labels' => ['fr' => 'Classe'], 'group_type' => 'class', 'capacity' => $capacity, 'valid_from' => $start, 'status' => 'active'])->assertCreated()->json('data');
        };
        $classes = ['source' => $class($now, $first), 'small' => $class($next, $second, 1), 'up' => $class($next, $second), 'same' => $class($next, $first)];
        $validated = function () use ($classes) {
            $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Passage', 'first_name_lat' => Str::random(8), 'birth_date' => '1995-01-01'])->assertCreated()->json('data.id');
            $enrollment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $classes['source']['program_id'], 'idempotency_key' => (string) Str::uuid()])
                ->assertCreated()->json('data');
            $this->postJson("/api/groups/{$classes['source']['id']}/memberships", ['enrollment_id' => $enrollment['id']])->assertCreated();
            return $this->postJson("/api/enrollments/{$enrollment['id']}/validate", ['version' => $enrollment['version'], 'reason' => 'Dossier complet'])->assertOk()->json('data');
        };
        return [$school, $manager, array_map(fn (array $group) => $group['id'], $classes), $validated];
    }

    private function decision(array $enrollment, string $decision, ?string $group, string $due = null): array
    {
        return ['enrollment_id' => $enrollment['id'], 'decision' => $decision, 'reason' => 'Conseil de classe', 'target_group_id' => $group,
            'due_date' => $group ? ($due ?? now()->addMonths(3)->toDateString()) : null];
    }

    public function test_a_batch_of_decisions_enrols_once_at_the_right_level_or_not_at_all(): void
    {
        [$school, , $classes, $validated] = $this->setting();
        [$a, $b] = [$validated(), $validated()];
        $enrolments = fn () => DB::connection('owner')->table('school.enrollment')->where('tenant_id', $school->id)->count();
        $count = $enrolments();
        // AC1: a batch whose target is full creates nothing.
        $this->postJson('/api/progressions', ['decisions' => [$this->decision($a, 'promoted', $classes['small']), $this->decision($b, 'promoted', $classes['small'])]])
            ->assertConflict()->assertJsonPath('code', 'group_full');
        $this->assertSame($count, $enrolments());
        $this->getJson('/api/progressions')->assertOk()->assertJsonPath('meta.total', 0);
        // AC4: a repetition that changes the level, a promotion that keeps it, refused.
        $this->postJson('/api/progressions', ['decisions' => [$this->decision($a, 'repeated', $classes['up'])]])->assertConflict()
            ->assertJsonPath('code', 'progression_level_changed');
        $this->postJson('/api/progressions', ['decisions' => [$this->decision($a, 'promoted', $classes['same'])]])->assertConflict()
            ->assertJsonPath('code', 'progression_level_same');
        $this->postJson('/api/progressions', ['decisions' => [$this->decision($a, 'promoted', null)]])->assertConflict()
            ->assertJsonPath('code', 'progression_target_required');
        $batch = ['decisions' => [$this->decision($a, 'promoted', $classes['up']), $this->decision($b, 'repeated', $classes['same'])]];
        $made = $this->postJson('/api/progressions', $batch)->assertCreated()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame($count + 2, $enrolments());
        $target = collect($made)->firstWhere('enrollment_id', $a['id']);
        $this->getJson("/api/enrollments/{$target['target_enrollment_id']}")->assertOk()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.main_group.id', $classes['up']);
        // AC2: the same batch again makes nothing; another decision is refused.
        $again = $this->postJson('/api/progressions', $batch)->assertCreated()->json('data');
        $this->assertEqualsCanonicalizing(array_column($made, 'id'), array_column($again, 'id'));
        $this->assertSame($count + 2, $enrolments());
        $this->postJson('/api/progressions', ['decisions' => [$this->decision($a, 'left', null)]])->assertConflict()->assertJsonPath('code', 'progression_decided');
        // AC3: the source enrolment as it was, with its decision.
        $this->getJson("/api/enrollments/{$a['id']}")->assertOk()->assertJsonPath('data.status', 'validated')->assertJsonPath('data.version', $a['version'])
            ->assertJsonPath('data.progression.decision', 'promoted')->assertJsonPath('data.main_group.id', $classes['source']);
        $this->getJson('/api/progressions?decision=repeated')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.enrollment_id', $b['id']);
        // A pending enrolment is not decided about.
        $pending = $this->postJson('/api/enrollments', ['student_id' => $this->postJson('/api/students', ['last_name_lat' => 'Attente', 'first_name_lat' => 'Nour',
            'birth_date' => '1995-01-01'])->json('data.id'), 'program_id' => DB::connection('owner')->table('school.study_group')->where('id', $classes['source'])->value('program_id'),
            'idempotency_key' => (string) Str::uuid()])->json('data');
        $this->postJson('/api/progressions', ['decisions' => [$this->decision($pending, 'left', null)]])->assertConflict()->assertJsonPath('code', 'progression_not_validated');
    }

    public function test_a_class_decides_for_its_validated_pupils_from_its_screen(): void
    {
        [$school, $manager, $classes, $validated] = $this->setting();
        [$a, $b] = [$validated(), $validated()];
        $this->get('/school/group-members?group='.$classes['source'])->assertOk()->assertSee(__('schooling.progression_action'));
        $this->actingAs($this->person($manager));
        $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($school, $manager, $classes, $a, $b) {
            app(CurrentSchool::class)->enter($school->id, $manager['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            Livewire::withQueryParams(['group' => $classes['source']])->test(GroupMembers::class)
                ->callTableAction('progression', data: ['enrollments' => [$a['id'], $b['id']], 'decision' => 'promoted', 'target_group_id' => $classes['up'],
                    'due_date' => now()->addMonths(3)->toDateString(), 'reason' => 'Admis au conseil de classe'])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $this->assertSame(2, DB::table('school.progression')->where('target_group_id', $classes['up'])->count());
            Livewire::withQueryParams(['group' => $classes['source']])->test(GroupMembers::class)->assertSee('passage');
        });
    }
}
