<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Activities;
use App\Filament\School\Pages\ActivityQuestions;
use App\Filament\School\Pages\ActivitySheet;
use App\Filament\School\Pages\Gradebook;
use App\Filament\School\Pages\GradingRules;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * FR-LMS-02 to FR-LMS-04 on screen: the manager sets the programme's grading rule; the teacher writes
 * a quiz and a homework, their questions, publishes them, corrects the open answer and marks the
 * homework from the activity's sheet, publishes the results and reads the gradebook; the manager
 * exports it. A pupil reaches none of these screens.
 */
final class ActivityScreensTest extends TestCase
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

    public function test_the_teacher_writes_publishes_corrects_and_reads_the_gradebook_from_the_screens(): void
    {
        $s = $this->setting('complete');
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
        $owner = DB::connection('owner');

        // The manager's rule: marks on 10, to the half point.
        $this->get('/school/grading-rules')->assertOk()->assertSee(__('pedagogy.create_grading_rule'));
        $this->within($s['school'], $s['manager'], function () use ($s) {
            Livewire::test(GradingRules::class)->callTableAction('create', data: ['applies_to' => 'program', 'program_id' => $s['program'], 'scale_max' => '10',
                'step' => '0.5', 'rounding' => 'half_up', 'pass_percent' => '50'])->assertHasNoTableActionErrors();
        });
        $this->assertSame(['10.00', '0.500'], array_values((array) $owner->table('school.grading_rule')->where('program_id', $s['program'])->first(['scale_max', 'pass_ratio'])));

        // The teacher writes a quiz and its questions, then a homework, and publishes both.
        $this->signIn($karim)->assertOk();
        $this->get('/school/activities')->assertOk()->assertSee(__('pedagogy.create_activity'));
        $this->get('/school/grading-rules')->assertOk()->assertDontSee(__('pedagogy.create_grading_rule'));
        $target = $s['group'].':'.$s['maths'];
        $deadline = now('Africa/Algiers')->addDay()->setTime(18, 0)->format('Y-m-d H:i:s');
        $this->within($s['school'], $karim, function () use ($target, $deadline) {
            Livewire::test(Activities::class)->callTableAction('create', data: ['target' => $target, 'kind' => 'quiz', 'title' => 'Fractions', 'deadline_at' => $deadline,
                'duration_minutes' => 20, 'max_points' => '10', 'coefficient' => '1', 'max_attempts' => 1, 'selection' => 'latest',
                'results_visibility' => 'on_teacher_publish', 'key_visibility' => 'after_deadline'])->assertHasNoTableActionErrors()->assertRedirect();
            Livewire::test(Activities::class)->callTableAction('create', data: ['target' => $target, 'kind' => 'homework', 'title' => 'Exposé', 'deadline_at' => $deadline,
                'max_points' => '20', 'coefficient' => '2', 'late_policy' => 'refuse', 'replacement_hours' => 48, 'results_visibility' => 'on_teacher_publish'])
                ->assertHasNoTableActionErrors();
        });
        $quiz = $owner->table('school.activity')->where('title', 'Fractions')->value('id');
        $homework = $owner->table('school.activity')->where('title', 'Exposé')->first();
        $this->assertSame(['2.00', 'homework'], [$homework->coefficient, $homework->kind]);
        $this->get('/school/activity-sheet?activity='.$quiz)->assertOk()->assertSee(__('errors.lms_quiz_empty'));
        $this->within($s['school'], $karim, function () use ($quiz) {
            Livewire::withQueryParams(['activity' => $quiz])->test(ActivityQuestions::class)
                ->callTableAction('add', data: ['kind' => 'open', 'prompt' => 'Expliquez', 'points' => '4'])->assertHasNoTableActionErrors()
                ->callTableAction('add', data: ['kind' => 'single', 'prompt' => 'Un demi ?', 'points' => '6',
                    'choices' => [['text' => 'Une moitié', 'correct' => true], ['text' => 'Un tiers', 'correct' => false]]])->assertHasNoTableActionErrors();
        });
        [$open, $single] = $owner->table('school.activity_question')->where('activity_id', $quiz)->orderBy('position')->pluck('id')->all();
        $this->within($s['school'], $karim, function () use ($quiz, $single, $homework) {
            Livewire::withQueryParams(['activity' => $quiz])->test(ActivityQuestions::class)->callTableAction('up', $single)->assertHasNoTableActionErrors();
            Livewire::withQueryParams(['activity' => $quiz])->test(ActivitySheet::class)->assertSee('Une moitié')->callAction('publish')->assertHasNoActionErrors();
            Livewire::withQueryParams(['activity' => $homework->id])->test(ActivitySheet::class)->callAction('publish')->assertHasNoActionErrors();
        });
        $this->assertSame([$single, $open], $owner->table('school.activity_question')->where('activity_id', $quiz)->orderBy('position')->pluck('id')->all());
        $this->assertSame('a', json_decode($owner->table('school.activity_question')->where('id', $single)->value('choices'), true)[0]['id']);

        // The pupil answers the quiz and hands the homework in; they reach none of the teacher's screens.
        $this->signIn($malek)->assertOk();
        $attempt = $this->postJson("/api/my-activities/$quiz/attempts", ['idempotency_key' => (string) Str::uuid()])->json('data');
        $this->putJson("/api/attempts/{$attempt['id']}/answers/$single", ['choices' => ['a']])->assertOk();
        $this->putJson("/api/attempts/{$attempt['id']}/answers/$open", ['answer_text' => 'Deux quarts font un demi.'])->assertOk();
        $this->postJson("/api/attempts/{$attempt['id']}/submit", ['idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->postJson("/api/my-activities/{$homework->id}/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://drive.google.com/file/d/x']])
            ->assertCreated();
        foreach (['activities', 'activity-sheet?activity='.$quiz, 'gradebook'] as $page) $this->get('/school/'.$page)->assertForbidden();

        // The teacher corrects the open answer, marks the homework and publishes both.
        $this->signIn($karim)->assertOk();
        $this->get('/school/activity-sheet?activity='.$quiz)->assertOk()->assertSee('Elève Malek')->assertSee(__('pedagogy.mark_states.to_grade'));
        $this->within($s['school'], $karim, function () use ($quiz, $homework, $pupil, $attempt, $open) {
            Livewire::withQueryParams(['activity' => $quiz])->test(ActivitySheet::class)
                ->assertTableActionVisible('correct', $pupil['id'])
                ->callTableAction('correct', $pupil['id'], data: ['answers' => [$attempt['id'] => [$open => ['points' => '3,5', 'comment' => 'Précisez']]]])
                ->assertHasNoTableActionErrors()
                ->callTableAction('publishOne', $pupil['id'])->assertHasNoTableActionErrors();
            Livewire::withQueryParams(['activity' => $homework->id])->test(ActivitySheet::class)
                ->assertTableActionVisible('grade', $pupil['id'])
                ->callTableAction('grade', $pupil['id'], data: ['points' => '14', 'feedback' => 'Clair'])->assertHasNoTableActionErrors()
                ->callAction('publishResults', data: [])->assertHasNoActionErrors()->assertNotified();
        });
        $this->assertSame(['9.50', '14.00'], $owner->table('school.activity_mark')->where('student_id', $pupil['id'])->orderBy('points')->pluck('published_points')->all());

        // The gradebook: 9.5/10 → 9.5 and 14/20 → 7 on 10, averaged (0.95 × 1 + 0.7 × 2) / 3 → 7.83… → 8 on 10 to the half point.
        $this->get('/school/gradebook')->assertOk()->assertSee('Elève Malek')->assertSee('Fractions : 9,50 / 10,00', false)->assertSee('8,00 / 10,00', false);
        $this->within($s['school'], $s['manager'], function () use ($target) {
            Livewire::test(Gradebook::class)->filterTable('book', ['target' => $target, 'from' => now()->subMonth()->toDateString(), 'to' => now()->addMonth()->toDateString()])
                ->assertSee('8,00 / 10,00', false)
                ->callTableAction('export', data: ['format' => 'csv'])->assertHasNoTableActionErrors()->assertNotified();
        });
        $this->assertSame(2, $owner->table('school.export_log')->where('tenant_id', $s['school']->id)->where('kind', 'gradebook')->value('row_count'));
    }
}
