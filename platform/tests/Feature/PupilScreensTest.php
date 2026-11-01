<?php

namespace Tests\Feature;

use App\Filament\School\Pages\MyActivity;
use App\Filament\School\Pages\QuizAttempt;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * FR-LMS-02 to FR-LMS-04 on the pupil's screens: told of their activities, they hand a homework in
 * by links and take a quiz, each answer saved as it is given, then submit it; told of a flagged link
 * and of their results without the mark, they read their result, their average and their attempt
 * back with the teacher's comment. Nothing before publication; no other pupil's screen.
 */
final class PupilScreensTest extends TestCase
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

    public function test_the_pupil_takes_a_quiz_hands_a_homework_in_and_reads_their_results_from_the_screens(): void
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
        $deadline = now('Africa/Algiers')->addDay()->format('Y-m-d H:i');

        $this->signIn($karim)->assertOk();
        $quiz = $this->postJson('/api/activities', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'kind' => 'quiz', 'title' => 'Fractions', 'deadline_at' => $deadline,
            'duration_minutes' => 20, 'max_points' => '10', 'key_visibility' => 'immediate'])->assertCreated()->json('data');
        $single = $this->postJson("/api/activities/{$quiz['id']}/questions", ['kind' => 'single', 'prompt' => 'Un demi ?', 'points' => '6',
            'choices' => [['id' => 'a', 'text' => 'Une moitié', 'correct' => true], ['id' => 'b', 'text' => 'Un tiers', 'correct' => false]]])->json('data');
        $open = $this->postJson("/api/activities/{$quiz['id']}/questions", ['kind' => 'open', 'prompt' => 'Expliquez', 'points' => '4'])->json('data');
        $this->postJson("/api/activities/{$quiz['id']}/publish")->assertCreated();
        $homework = $this->postJson('/api/activities', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'kind' => 'homework', 'title' => 'Exposé',
            'deadline_at' => $deadline, 'max_points' => '20'])->assertCreated()->json('data');
        $this->postJson("/api/activities/{$homework['id']}/publish")->assertCreated();
        foreach (['my-activities', 'my-activity?activity='.$quiz['id']] as $page) $this->get('/school/'.$page)->assertForbidden();

        // The pupil is told of both, reads them, and hands the homework in by a link.
        $this->signIn($malek)->assertOk();
        $this->assertSame(2, $owner->table('school.notification')->where('user_id', $malek['id'])->where('event', 'lms.activity_published')->count());
        $this->get('/school/notifications')->assertOk()->assertSee(__('pedagogy.see_activity'));
        $this->get('/school/my-activities')->assertOk()->assertSee('Fractions')->assertSee('Exposé')->assertSee(__('pedagogy.no_average'));
        $this->get('/school/my-activity?activity='.$homework['id'])->assertOk()->assertSee(__('pedagogy.hand_in'));
        $this->within($s['school'], $malek, function () use ($homework) {
            Livewire::withQueryParams(['activity' => $homework['id']])->test(MyActivity::class)
                ->callAction('handIn', data: ['links' => 'https://wetransfer.com/x'])->assertNotified(__('errors.lms_link_domain_blocked'));
            Livewire::withQueryParams(['activity' => $homework['id']])->test(MyActivity::class)
                ->callAction('handIn', data: ['links' => "https://drive.google.com/file/d/expose\n\n"])->assertHasNoActionErrors()->assertNotified(__('pedagogy.handed_in'))
                ->assertSee('https://drive.google.com/file/d/expose');
        });
        $this->assertSame(1, $owner->table('school.homework_submission')->where('activity_id', $homework['id'])->count());

        // The quiz started from its page, each answer saved as given, then submitted.
        $this->within($s['school'], $malek, function () use ($quiz) {
            Livewire::withQueryParams(['activity' => $quiz['id']])->test(MyActivity::class)->callAction('start')->assertHasNoActionErrors()->assertRedirect();
        });
        $attempt = $owner->table('school.quiz_attempt')->where('activity_id', $quiz['id'])->value('id');
        $this->get('/school/quiz-attempt?attempt='.$attempt)->assertOk()->assertSee('Un demi ?')->assertSee(__('pedagogy.time_left'))->assertDontSee('correct');
        $this->within($s['school'], $malek, function () use ($attempt, $single, $open) {
            Livewire::withQueryParams(['attempt' => $attempt])->test(QuizAttempt::class)
                ->set('answers.'.$single['id'], 'a')
                ->set('answers.'.$open['id'], 'Deux quarts font un demi.')
                ->assertNotified()
                ->callAction('submit')->assertHasNoActionErrors()
                ->assertSee(__('pedagogy.attempt_closed'))->assertSee(__('pedagogy.review_waiting'));
        });
        $this->assertSame(['submitted', '6.00', 1], array_values((array) $owner->table('school.quiz_attempt')->where('id', $attempt)->first(['status', 'auto_points', 'pending_questions'])));
        $this->assertSame('Deux quarts font un demi.', $owner->table('school.quiz_answer')->where('attempt_id', $attempt)->where('question_id', $open['id'])->value('answer_text'));

        // The teacher corrects and publishes the quiz, flags the homework's link.
        $this->signIn($karim)->assertOk();
        $this->postJson("/api/attempts/$attempt/correction", ['question_id' => $open['id'], 'points' => '3.5', 'comment' => 'Précisez'])->assertOk();
        $this->postJson("/api/activities/{$quiz['id']}/results/publish")->assertOk()->assertJsonPath('data.published', 1);
        $submission = $owner->table('school.homework_submission')->where('activity_id', $homework['id'])->value('id');
        $this->postJson("/api/homework-submissions/$submission/flag", ['reason' => 'Lien privé'])->assertOk();
        $this->get('/school/quiz-attempt?attempt='.$attempt)->assertForbidden();

        // The pupil, told without the mark, reads their result on the default scale, their average, the key and the comment.
        $this->signIn($malek)->assertOk();
        $told = $owner->table('school.notification')->where('user_id', $malek['id'])->whereIn('event', ['lms.result_published', 'lms.submission_flagged'])->pluck('data', 'event')
            ->map(fn ($data) => array_keys(json_decode($data, true)))->all();
        $this->assertEqualsCanonicalizing(['lms.result_published' => ['activity_id'], 'lms.submission_flagged' => ['activity_id', 'replace_until']], $told);
        $this->get('/school/my-activities')->assertOk()->assertSee('19,00 / 20,00', false)->assertSee(__('pedagogy.passed'));
        $this->get('/school/my-activity?activity='.$homework['id'])->assertOk()->assertSee(__('pedagogy.replace_link'))->assertSee('Lien privé')
            ->assertSee(__('pedagogy.hand_in_again'));
        $this->get('/school/quiz-attempt?attempt='.$attempt)->assertOk()->assertSee(__('pedagogy.review_published'))->assertSee('Précisez')
            ->assertSee('3,50 / 4,00', false)->assertSee(__('pedagogy.key'));
    }
}
