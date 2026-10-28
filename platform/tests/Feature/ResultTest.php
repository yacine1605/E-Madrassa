<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * FR-LMS-03, FR-LMS-04 through the API: the teacher corrects an open answer and the quiz's result is
 * published at once; a homework's mark waits until the teacher publishes it; the pupil reads their
 * own results, is told without the mark, and reads their attempt back with its key.
 */
final class ResultTest extends TestCase
{
    use PlansSessions;

    public function test_results_are_corrected_published_as_the_activity_says_and_read_by_their_pupil_only(): void
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
        $deadline = now('Africa/Algiers')->addDay()->format('Y-m-d H:i');

        $this->signIn($karim)->assertOk();
        $quiz = $this->postJson('/api/activities', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'kind' => 'quiz', 'title' => 'Fractions', 'deadline_at' => $deadline,
            'duration_minutes' => 20, 'max_points' => '10', 'results_visibility' => 'immediate', 'key_visibility' => 'immediate'])->assertCreated()->json('data');
        $single = $this->postJson("/api/activities/{$quiz['id']}/questions", ['kind' => 'single', 'prompt' => 'Un demi ?', 'points' => '6',
            'choices' => [['id' => 'a', 'text' => 'Une moitié', 'correct' => true], ['id' => 'b', 'text' => 'Un tiers', 'correct' => false]]])->json('data');
        $open = $this->postJson("/api/activities/{$quiz['id']}/questions", ['kind' => 'open', 'prompt' => 'Expliquez', 'points' => '4'])->json('data');
        $this->postJson("/api/activities/{$quiz['id']}/publish")->assertCreated();
        $homework = $this->postJson('/api/activities', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'kind' => 'homework', 'title' => 'Exposé',
            'deadline_at' => $deadline, 'max_points' => '20'])->assertCreated()->json('data');
        $this->postJson("/api/activities/{$homework['id']}/publish")->assertCreated();

        $this->signIn($malek)->assertOk();
        $attempt = $this->postJson("/api/my-activities/{$quiz['id']}/attempts", ['idempotency_key' => (string) Str::uuid()])->json('data');
        $this->putJson("/api/attempts/{$attempt['id']}/answers/{$single['id']}", ['choices' => ['a']])->assertOk();
        $this->putJson("/api/attempts/{$attempt['id']}/answers/{$open['id']}", ['answer_text' => 'Deux quarts font un demi.'])->assertOk();
        $this->postJson("/api/attempts/{$attempt['id']}/submit", ['idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->postJson("/api/my-activities/{$homework['id']}/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://drive.google.com/file/d/x']])
            ->assertCreated();
        $this->getJson('/api/my-results')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/activities/{$quiz['id']}/results")->assertForbidden();

        // The open answer corrected: the quiz's result complete, published at once.
        $this->signIn($karim)->assertOk();
        $this->getJson("/api/activities/{$quiz['id']}/results")->assertOk()->assertJsonPath('data.0.status', 'to_grade');
        $this->getJson("/api/attempts/{$attempt['id']}/correction")->assertOk()->assertJsonPath('data.questions.1.answer.answer_text', 'Deux quarts font un demi.');
        $this->postJson("/api/attempts/{$attempt['id']}/correction", ['question_id' => $open['id'], 'points' => '5'])->assertStatus(422)
            ->assertJsonPath('code', 'lms_points_out_of_range');
        $this->postJson("/api/attempts/{$attempt['id']}/correction", ['question_id' => $open['id'], 'points' => '3.5', 'comment' => 'Précisez'])->assertOk()
            ->assertJsonPath('data.pending_questions', 0);
        $this->getJson("/api/activities/{$quiz['id']}/results")->assertJsonPath('data.0.status', 'published')->assertJsonPath('data.0.published_points', '9.50');
        // The homework's mark waits for the teacher; a zero for a missing work only once the deadline passed.
        $this->postJson("/api/activities/{$homework['id']}/grades", ['student_id' => $pupil['id'], 'points' => '14', 'feedback' => 'Clair'])->assertOk()
            ->assertJsonPath('data.0.status', 'graded');
        $this->postJson("/api/activities/{$homework['id']}/decisions", ['student_id' => $pupil['id'], 'decision' => 'zero_missing', 'reason' => 'Non rendu'])
            ->assertStatus(409)->assertJsonPath('code', 'lms_deadline_not_passed');
        $this->signIn($malek)->assertOk();
        $this->getJson('/api/my-results')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Fractions')->assertJsonPath('data.0.points', '9.50');
        $this->signIn($karim)->assertOk();
        $this->postJson("/api/activities/{$homework['id']}/results/publish")->assertOk()->assertJsonPath('data.published', 1);

        // The pupil reads both, is told without the mark, and reads the quiz back with its key and the comment.
        $this->signIn($malek)->assertOk();
        $this->getJson('/api/my-results')->assertJsonCount(2, 'data');
        $notifications = $this->getJson('/api/notifications')->assertOk()->json('data');
        $told = array_values(array_filter($notifications, fn (array $n) => $n['event'] === 'lms.result_published'));
        $this->assertCount(2, $told);
        $this->assertSame(['activity_id'], array_keys($told[0]['data']));
        $this->getJson("/api/attempts/{$attempt['id']}/review")->assertOk()->assertJsonPath('data.key_visible', true)
            ->assertJsonPath('data.questions.0.choices.0.correct', true)->assertJsonPath('data.questions.1.answer.comment', 'Précisez');
        $this->getJson('/api/attempts/'.Str::uuid().'/review')->assertForbidden();
    }
}
