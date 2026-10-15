<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Groups;
use App\Filament\School\Pages\Students;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A group's lead teacher (FR-GRP-01, SQL 033) and what the teacher's role, on their own data,
 * reads through it (FR-IAM-04 AC1, TST-03): their groups, their pupils, their own record.
 */
final class LeadTeacherTest extends TestCase
{
    /** A school with the staff module, its pedagogy and HR manager signed in, a programme of an open year. */
    private function setting(): array
    {
        $manager = $this->account('lead');
        $school = $this->school('pro', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'planned'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $site = DB::connection('owner')->selectOne('SELECT id FROM school.site WHERE tenant_id = ? AND is_default', [$school->id])->id;
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => Str::upper(Str::random(8)),
            'labels' => ['fr' => 'Sciences'], 'base_price' => '120000', 'payment_terms' => [['code' => 'INITIAL']], 'status' => 'active'])
            ->assertCreated()->json('data');
        return [$school, $manager, $program, $site];
    }

    private function group(array $program, string $site): array
    {
        return $this->postJson('/api/groups', ['year_id' => $program['year_id'], 'program_id' => $program['id'], 'site_id' => $site,
            'code' => Str::upper(Str::random(8)), 'labels' => ['fr' => 'Classe'], 'group_type' => 'class', 'capacity' => 25,
            'valid_from' => now()->toDateString(), 'status' => 'active'])->assertCreated()->json('data');
    }

    /** A pupil enrolled in the programme and placed in the group. */
    private function pupil(array $program, array $group): string
    {
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => Str::random(12), 'birth_date' => '1990-01-01'])
            ->assertCreated()->json('data.id');
        $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $program['id'], 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/groups/'.$group['id'].'/memberships', ['enrollment_id' => $enrolment])->assertCreated();
        return $pupil;
    }

    private function staff(string $type = 'teacher_internal', string $given = 'Karim'): array
    {
        return $this->postJson('/api/staff', ['last_name_lat' => 'Meziane', 'first_name_lat' => $given, 'staff_type' => $type])->assertCreated()->json('data');
    }

    /** The teacher's own account, linked to their record, with the teacher's role on their own data. */
    private function teacherAccount(object $school, array $record): array
    {
        $account = $this->account('teacher');
        $this->member($school, $account, 'active');
        $this->grant($school, $account, 'teacher', 'personal');
        $this->postJson("/api/staff/{$record['id']}/account", ['user_id' => $account['id'], 'version' => $record['version']])->assertOk();
        return $account;
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

    public function test_a_group_s_lead_teacher_reads_it_with_its_pupils_and_no_other_group(): void
    {
        [$school, , $program, $site] = $this->setting();
        [$mine, $other] = [$this->group($program, $site), $this->group($program, $site)];
        [$pupil, $classmate] = [$this->pupil($program, $mine), $this->pupil($program, $other)];
        $teacher = $this->staff();
        $this->putJson("/api/groups/{$mine['id']}", ['lead_teacher_id' => $teacher['id'], 'version' => 1])->assertOk()
            ->assertJsonPath('data.lead_teacher_id', $teacher['id'])->assertJsonPath('data.lead_teacher.staff_number', $teacher['staff_number']);
        $this->getJson('/api/groups?lead_teacher_id='.$teacher['id'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine['id']);
        // A teacher still with the school, whose record the writer reads.
        $clerk = $this->staff('administrative', 'Amel');
        $this->putJson("/api/groups/{$other['id']}", ['lead_teacher_id' => $clerk['id'], 'version' => 1])
            ->assertConflict()->assertJsonPath('code', 'lead_teacher_not_teacher');
        $this->putJson("/api/groups/{$other['id']}", ['lead_teacher_id' => (string) Str::uuid(), 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('lead_teacher_id');
        $gone = $this->staff(given: 'Sami');
        $this->postJson("/api/staff/{$gone['id']}/status", ['status' => 'left', 'reason' => 'Fin de contrat', 'version' => 1])->assertOk();
        $this->putJson("/api/groups/{$other['id']}", ['lead_teacher_id' => $gone['id'], 'version' => 1])->assertConflict()->assertJsonPath('code', 'staff_left');
        $this->postJson('/api/groups', ['year_id' => $program['year_id'], 'program_id' => $program['id'], 'site_id' => $site, 'code' => 'LEAD-NEW',
            'labels' => ['fr' => 'Classe'], 'group_type' => 'class', 'capacity' => 25, 'valid_from' => now()->toDateString(), 'lead_teacher_id' => $teacher['id']])
            ->assertCreated()->assertJsonPath('data.lead_teacher_id', $teacher['id']);

        // FR-IAM-04 AC1: the teacher's role on their own data reads the groups they lead, their pupils, their record.
        $account = $this->teacherAccount($school, $teacher);
        $this->signIn($account)->assertOk();
        $this->getJson('/api/groups')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/groups/{$mine['id']}")->assertOk()->assertJsonPath('data.lead_teacher.last_name_lat', 'Meziane');
        $this->getJson("/api/groups/{$other['id']}")->assertNotFound();
        $this->getJson("/api/groups/{$mine['id']}/memberships")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/students')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $pupil);
        $this->getJson("/api/students/$classmate")->assertNotFound();
        $this->getJson('/api/staff')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $teacher['id']);
        // Reading, not managing; enrolments are not the teacher's (section 3.3).
        $this->putJson("/api/groups/{$mine['id']}", ['capacity' => 5, 'version' => 2])->assertForbidden();
        $this->getJson('/api/enrollments')->assertForbidden();
    }

    public function test_the_groups_page_names_the_lead_teacher_and_shows_a_teacher_their_groups_only(): void
    {
        [$school, $manager, $program, $site] = $this->setting();
        [$mine, $other] = [$this->group($program, $site), $this->group($program, $site)];
        $pupil = $this->pupil($program, $mine);
        $this->pupil($program, $other);
        $teacher = $this->staff();
        $this->get('/school/groups')->assertOk()->assertSee(__('schooling.lead_teacher'));
        $this->within($school, $manager, function () use ($mine, $teacher) {
            Livewire::test(Groups::class)->callTableAction('edit', $mine['id'], data: ['lead_teacher_id' => $teacher['id']])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'))
                ->assertTableColumnStateSet('lead_teacher', 'Meziane Karim', $mine['id']);
        });
        $this->assertSame($teacher['id'], DB::connection('owner')->table('school.study_group')->where('id', $mine['id'])->value('lead_teacher_id'));
        $account = $this->teacherAccount($school, $teacher);
        $this->within($school, $account, function () use ($mine, $other, $pupil) {
            Livewire::test(Groups::class)->assertCanSeeTableRecords([$mine['id']])->assertCanNotSeeTableRecords([$other['id']])
                ->assertTableActionHidden('edit', $mine['id'])->assertTableActionHidden('create');
            Livewire::test(Students::class)->assertCanSeeTableRecords([$pupil])->assertCountTableRecords(1);
        });
    }
}
