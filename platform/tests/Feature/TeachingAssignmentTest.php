<?php

namespace Tests\Feature;

use App\Filament\School\Pages\StudentSheet;
use App\Filament\School\Pages\TeachingAssignments;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * Lot 5 §1: a group's subject taught by one qualified teacher at a time, the teacher reading their
 * own assignments; a pupil's record linked to the account they sign in with.
 */
final class TeachingAssignmentTest extends TestCase
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

    /** The plan's teacher, signing in with their own account (role `teacher`, their own data). */
    private function teacherAccount(array $s): array
    {
        $karim = $this->account('karim');
        $this->member($s['school'], $karim, 'active');
        $this->grant($s['school'], $karim, 'teacher', 'personal');
        $record = $this->getJson("/api/staff/{$s['teacher']}")->json('data');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $karim['id'], 'version' => $record['version']])->assertOk();
        return $karim;
    }

    public function test_a_group_subject_has_one_qualified_teacher_at_a_time_who_reads_their_own_assignments(): void
    {
        $s = $this->setting('complete');
        $karim = $this->teacherAccount($s);
        $other = $this->postJson('/api/staff', ['last_name_lat' => 'Haddad', 'first_name_lat' => 'Lina', 'staff_type' => 'teacher_internal'])->assertCreated()->json('data.id');
        $this->postJson("/api/staff/$other/qualifications", ['subject_id' => $s['maths'], 'node_id' => $s['node']])->assertCreated();

        // A subject the programme does not teach, a teacher not qualified: refused with their reason.
        $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['physics'], 'staff_id' => $s['teacher']])
            ->assertStatus(409)->assertJsonPath('code', 'assignment_subject_outside_program');
        $unqualified = $this->postJson('/api/staff', ['last_name_lat' => 'Sans', 'first_name_lat' => 'Titre', 'staff_type' => 'teacher_internal'])->json('data.id');
        $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'staff_id' => $unqualified])
            ->assertStatus(409)->assertJsonPath('code', 'assignment_teacher_not_qualified');
        $assigned = $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'staff_id' => $s['teacher']])
            ->assertCreated()->assertJsonPath('data.valid_from', now()->toDateString())->assertJsonPath('data.group_code', '4AM-1')->json('data');
        // One teacher at a time: the next one from the day after the first ends.
        $handover = now()->addDays(10)->toDateString();
        $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'staff_id' => $other, 'valid_from' => $handover])
            ->assertStatus(409)->assertJsonPath('code', 'assignment_overlap');
        $this->postJson("/api/teaching-assignments/{$assigned['id']}/end", ['valid_to' => now()->addDays(9)->toDateString(), 'version' => $assigned['version']])->assertOk();
        $next = $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'staff_id' => $other, 'valid_from' => $handover])
            ->assertCreated()->json('data');
        $this->deleteJson("/api/teaching-assignments/{$assigned['id']}", ['version' => $assigned['version'] + 1])->assertStatus(409)->assertJsonPath('code', 'assignment_begun');
        $this->getJson("/api/teaching-assignments?group_id={$s['group']}")->assertOk()->assertJsonCount(2, 'data');

        // The teacher reads their own, and teaches the group's subject today only.
        $this->signIn($karim)->assertOk();
        $this->getJson('/api/teaching-assignments?mine=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.mine', true)
            ->assertJsonPath('data.0.subject_code', DB::connection('owner')->table('school.subject')->where('id', $s['maths'])->value('code'));
        $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'staff_id' => $s['teacher']])->assertForbidden();
        $this->get('/school/teaching-assignments')->assertOk()->assertSee('4AM-1');
        $this->within($s['school'], $karim, function () {
            Livewire::test(TeachingAssignments::class)->assertTableActionHidden('assign')->assertSee('Meziane Karim');
        });

        // The manager assigns from the screen, and removes an assignment to come.
        $this->signIn($s['manager'])->assertOk();
        $this->within($s['school'], $s['manager'], function () use ($s, $next) {
            Livewire::test(TeachingAssignments::class)->callTableAction('remove', $next['id'])->assertHasNoTableActionErrors();
            Livewire::test(TeachingAssignments::class)->callTableAction('assign', data: ['group_id' => $s['group'], 'subject_id' => $s['maths'],
                'staff_id' => $s['teacher'], 'valid_from' => now()->addDays(10)->toDateString()])->assertHasNoTableActionErrors();
        });
        $this->assertSame(2, DB::connection('owner')->table('school.teaching_assignment')->where('group_id', $s['group'])->count());
    }

    public function test_a_pupil_record_is_linked_to_their_own_account_and_never_to_a_member_of_staff(): void
    {
        $s = $this->setting('complete');
        $karim = $this->teacherAccount($s);
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Yasmine', 'birth_date' => '2010-02-03'])->assertCreated()->json('data');
        $yasmine = $this->account('yasmine', false);
        $this->member($s['school'], $yasmine, 'active');
        $this->grant($s['school'], $yasmine, 'student', 'personal');

        // The accounts free for a pupil: the teacher's is not one.
        $free = array_column($this->getJson('/api/students/accounts?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($yasmine['id'], $free);
        $this->assertNotContains($karim['id'], $free);
        $this->postJson("/api/students/{$pupil['id']}/account", ['user_id' => $karim['id'], 'version' => $pupil['version']])
            ->assertStatus(409)->assertJsonPath('code', 'pupil_account_staff');
        $this->postJson("/api/students/{$pupil['id']}/account", ['user_id' => $s['manager']['id'], 'version' => $pupil['version']])
            ->assertStatus(409)->assertJsonPath('code', 'self_account');
        $linked = $this->postJson("/api/students/{$pupil['id']}/account", ['user_id' => $yasmine['id'], 'version' => $pupil['version']])->assertOk()
            ->assertJsonPath('data.has_account', true)->assertJsonPath('data.account_name', 'yasmine')->json('data');
        $this->assertNotContains($yasmine['id'], array_column($this->getJson('/api/students/accounts?per_page=100')->json('data'), 'id'));
        $other = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Walid', 'birth_date' => '2012-02-03', 'duplicate_reason' => 'Frère de Yasmine'])
            ->assertCreated()->json('data');
        $this->postJson("/api/students/{$other['id']}/account", ['user_id' => $yasmine['id'], 'version' => $other['version']])
            ->assertStatus(409)->assertJsonPath('code', 'pupil_account_linked');

        // The sheet names the account, and unlinks it.
        $this->get('/school/student-sheet?student='.$pupil['id'])->assertOk()->assertSee(__('pedagogy.pupil_account'));
        $this->within($s['school'], $s['manager'], function () use ($pupil) {
            Livewire::withQueryParams(['student' => $pupil['id']])->test(StudentSheet::class)->callAction('unlinkAccount')->assertHasNoActionErrors();
        });
        $this->assertNull(DB::connection('owner')->table('school.student')->where('id', $linked['id'])->value('user_id'));
    }
}
