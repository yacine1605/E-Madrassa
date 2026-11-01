<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Activities;
use App\Filament\School\Pages\CourseReader;
use App\Filament\School\Pages\Progress;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * FR-LMS-05 through the API and the screens: the pupil unfolds a lesson, which notes it opened, and
 * declares another complete; neither verifies a mastery, which only a published result of an
 * activity on the lesson does; the teacher links the activity to the lesson and reads the group's
 * progression. A pupil reaches no other's; a member of staff opens nothing.
 */
final class ProgressTest extends TestCase
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

    public function test_opened_declared_and_verified_are_kept_apart_and_read_by_the_pupil_and_the_teacher(): void
    {
        $s = $this->setting('complete');
        $owner = DB::connection('owner');
        $karim = $this->account('karim');
        $this->member($s['school'], $karim, 'active');
        $this->grant($s['school'], $karim, 'teacher', 'personal');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $karim['id'], 'version' => $this->getJson("/api/staff/{$s['teacher']}")->json('data.version')])->assertOk();
        $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'staff_id' => $s['teacher']])->assertCreated();
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Elève', 'first_name_lat' => 'Malek', 'birth_date' => '1996-02-01'])->assertCreated()->json('data');
        $malek = $this->account('malek', false);
        $this->member($s['school'], $malek, 'active');
        $this->grant($s['school'], $malek, 'student', 'personal');
        $this->postJson("/api/students/{$pupil['id']}/account", ['user_id' => $malek['id'], 'version' => $pupil['version']])->assertOk();
        $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil['id'], 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])->json('data');
        $this->postJson("/api/groups/{$s['group']}/memberships", ['enrollment_id' => $enrolment['id']])->assertCreated();
        $this->postJson("/api/enrollments/{$enrolment['id']}/validate", ['version' => $this->getJson("/api/enrollments/{$enrolment['id']}")->json('data.version'),
            'reason' => 'Dossier complet'])->assertOk();
        $course = $this->postJson('/api/courses', ['program_id' => $s['program'], 'subject_id' => $s['maths']])->assertCreated()->json('data');
        $chapter = $this->postJson("/api/courses/{$course['id']}/chapters", ['title' => 'Nombres'])->assertCreated()->json('data');
        $fractions = $this->postJson("/api/chapters/{$chapter['id']}/lessons", ['title' => 'Fractions'])->assertCreated()->json('data');
        $decimals = $this->postJson("/api/chapters/{$chapter['id']}/lessons", ['title' => 'Décimaux'])->assertCreated()->json('data');
        $this->postJson('/api/publications', ['scope' => 'chapter', 'id' => $chapter['id']])->assertCreated();

        // The teacher writes a homework on the lesson from the screen; opening a lesson is no teacher's.
        $this->signIn($karim)->assertOk();
        $deadline = now('Africa/Algiers')->addDay()->setTime(18, 0)->format('Y-m-d H:i:s');
        $this->within($s['school'], $karim, function () use ($s, $deadline, $fractions) {
            Livewire::test(Activities::class)->callTableAction('create', data: ['target' => $s['group'].':'.$s['maths'], 'kind' => 'homework', 'title' => 'Exercices',
                'lesson_id' => $fractions['id'], 'deadline_at' => $deadline, 'max_points' => '20', 'coefficient' => '1', 'late_policy' => 'refuse',
                'replacement_hours' => 48, 'results_visibility' => 'on_teacher_publish'])->assertHasNoTableActionErrors();
        });
        $work = $owner->table('school.activity')->where('title', 'Exercices')->first();
        $this->assertSame($fractions['id'], $work->lesson_id);
        $this->postJson("/api/activities/{$work->id}/publish")->assertCreated();
        $this->postJson("/api/lessons/{$fractions['id']}/opened")->assertForbidden()->assertJsonPath('code', 'lms_access_denied');

        // The pupil's lessons start folded; unfolding one notes it opened, the other is declared complete; no mastery for either.
        $this->signIn($malek)->assertOk();
        $this->get('/school/course-reader?course='.$course['id'])->assertOk()->assertSee(__('pedagogy.declare_done'))->assertSee(__('pedagogy.mastery_states.none'))
            ->assertSee('x-intersect.once', false);
        $this->within($s['school'], $malek, function () use ($course, $fractions, $decimals) {
            Livewire::withQueryParams(['course' => $course['id']])->test(CourseReader::class)
                ->call('opened', $fractions['id'])
                ->callAction('declare', arguments: ['lesson' => $decimals['id'], 'done' => true])->assertHasNoActionErrors();
        });
        $mine = collect($this->getJson("/api/courses/{$course['id']}/my-progress")->assertOk()->json('data'))->keyBy('lesson_id');
        $this->assertNotNull($mine[$fractions['id']]['first_opened_at']);
        $this->assertNull($mine[$fractions['id']]['completed_at']);
        $this->assertNotNull($mine[$decimals['id']]['completed_at']);
        $this->assertSame([null, null], [$mine[$fractions['id']]['mastery'], $mine[$decimals['id']]['mastery']]);
        $this->postJson("/api/lessons/{$decimals['id']}/completion", ['done' => false])->assertOk()->assertJsonPath('data.completed_at', null);
        $this->get('/school/progress')->assertForbidden();
        $this->getJson("/api/groups/{$s['group']}/progress?subject_id={$s['maths']}")->assertForbidden();

        // A published result on the lesson below the pass mark: not verified; reaching it: verified.
        $this->postJson("/api/my-activities/{$work->id}/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://drive.google.com/file/d/x']])
            ->assertCreated();
        $this->signIn($karim)->assertOk();
        $this->postJson("/api/activities/{$work->id}/grades", ['student_id' => $pupil['id'], 'points' => '15'])->assertOk();
        $this->postJson("/api/activities/{$work->id}/results/publish")->assertOk();
        $this->signIn($malek)->assertOk();
        $this->getJson("/api/courses/{$course['id']}/my-progress")->assertOk()->assertJsonPath('data.0.mastery', 'verified');
        $this->get('/school/course-reader?course='.$course['id'])->assertOk()->assertSee(__('pedagogy.mastery_states.verified'));

        // The teacher reads the group's progression: one pupil, two lessons.
        $this->signIn($karim)->assertOk();
        $this->getJson("/api/groups/{$s['group']}/progress?subject_id={$s['maths']}")->assertOk()->assertJsonCount(2, 'data.lessons')->assertJsonCount(1, 'data.pupils')
            ->assertJsonPath("data.pupils.0.lessons.{$fractions['id']}.mastery", 'verified');
        $this->get('/school/progress')->assertOk()->assertSee('Fractions')->assertSee(__('pedagogy.progress_states.verified'))->assertSee('1 / 1');
        $this->within($s['school'], $karim, function () use ($fractions) {
            Livewire::test(Progress::class)->mountTableAction('pupils', $fractions['id'])->assertHasNoTableActionErrors();
        });
    }
}
