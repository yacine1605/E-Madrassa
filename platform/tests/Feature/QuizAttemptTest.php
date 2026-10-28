<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * FR-LMS-03 through the API: the pupil starts a quiz, loses the connection, resumes the same attempt
 * with its time left, answers and submits it once; the teacher reads it graded; the scheduler's run
 * closes the attempts past their end.
 */
final class QuizAttemptTest extends TestCase
{
    use PlansSessions;

    public function test_a_pupil_takes_a_quiz_timed_by_the_server_and_submits_it_once(): void
    {
        $s = $this->setting('complete');
        $karim = $this->account('karim');
        $this->member($s['school'], $karim, 'active');
        $this->grant($s['school'], $karim, 'teacher', 'personal');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $karim['id'], 'version' => $this->getJson("/api/staff/{$s['teacher']}")->json('data.version')])->assertOk();
        $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'staff_id' => $s['teacher']])->assertCreated();
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Elève', 'first_name_lat' => 'Rayan', 'birth_date' => '1996-02-01'])->assertCreated()->json('data');
        $rayan = $this->account('rayan', false);
        $this->member($s['school'], $rayan, 'active');
        $this->grant($s['school'], $rayan, 'student', 'personal');
        $this->postJson("/api/students/{$pupil['id']}/account", ['user_id' => $rayan['id'], 'version' => $pupil['version']])->assertOk();
        $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil['id'], 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])->json('data');
        $this->postJson("/api/groups/{$s['group']}/memberships", ['enrollment_id' => $enrolment['id']])->assertCreated();
        $this->postJson("/api/enrollments/{$enrolment['id']}/validate", ['version' => $this->getJson("/api/enrollments/{$enrolment['id']}")->json('data.version'),
            'reason' => 'Dossier complet'])->assertOk();

        $this->signIn($karim)->assertOk();
        $quiz = $this->postJson('/api/activities', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'kind' => 'quiz', 'title' => 'Fractions',
            'deadline_at' => now('Africa/Algiers')->addDay()->format('Y-m-d H:i'), 'duration_minutes' => 20, 'max_points' => '6'])->assertCreated()->json('data');
        $question = $this->postJson("/api/activities/{$quiz['id']}/questions", ['kind' => 'single', 'prompt' => 'Un demi ?', 'points' => '6',
            'choices' => [['id' => 'a', 'text' => 'Une moitié', 'correct' => true], ['id' => 'b', 'text' => 'Un tiers', 'correct' => false]]])->assertCreated()->json('data');
        $this->postJson("/api/activities/{$quiz['id']}/publish")->assertCreated();

        // Started; after a cut, the same attempt with its time left, its questions without their answers.
        $this->signIn($rayan)->assertOk();
        $key = (string) Str::uuid();
        $attempt = $this->postJson("/api/my-activities/{$quiz['id']}/attempts", ['idempotency_key' => $key])->assertCreated()
            ->assertJsonPath('data.number', 1)->assertJsonPath('data.status', 'in_progress')->assertJsonCount(1, 'data.questions')->json('data');
        $this->assertGreaterThan(1150, $attempt['seconds_left']);
        $this->assertLessThanOrEqual(1200, $attempt['seconds_left']);
        $this->postJson("/api/my-activities/{$quiz['id']}/attempts", ['idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.id', $attempt['id']);
        $taking = $this->getJson("/api/attempts/{$attempt['id']}")->assertOk();
        $this->assertStringNotContainsString('correct', $taking->getContent());
        $this->putJson("/api/attempts/{$attempt['id']}/answers/{$question['id']}", ['choices' => ['a', 'b']])->assertStatus(422)->assertJsonPath('code', 'lms_answer_invalid');
        $this->putJson("/api/attempts/{$attempt['id']}/answers/{$question['id']}", ['choices' => ['a']])->assertOk();
        $this->getJson("/api/attempts/{$attempt['id']}")->assertJsonPath('data.answers.0.choices', ['a']);
        // Submitted once; the same key gives it back, another is refused; no more answers.
        $submit = (string) Str::uuid();
        $this->postJson("/api/attempts/{$attempt['id']}/submit", ['idempotency_key' => $submit])->assertOk()->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.questions', null);
        $this->postJson("/api/attempts/{$attempt['id']}/submit", ['idempotency_key' => $submit])->assertOk();
        $this->postJson("/api/attempts/{$attempt['id']}/submit", ['idempotency_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
        $this->putJson("/api/attempts/{$attempt['id']}/answers/{$question['id']}", ['choices' => ['b']])->assertStatus(409)->assertJsonPath('code', 'lms_attempt_submitted');
        $this->postJson("/api/my-activities/{$quiz['id']}/attempts", ['idempotency_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'lms_attempts_exhausted');
        $this->getJson("/api/my-activities/{$quiz['id']}/attempts")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.closed_by', 'pupil');
        $this->getJson('/api/attempts/'.Str::uuid())->assertForbidden()->assertJsonPath('code', 'lms_access_denied');
        $this->getJson("/api/activities/{$quiz['id']}/attempts")->assertForbidden();

        // The teacher reads it graded, all or nothing.
        $this->signIn($karim)->assertOk();
        $this->getJson("/api/activities/{$quiz['id']}/attempts")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.auto_points', '6.00')->assertJsonPath('data.0.pending_questions', 0)->assertJsonPath('data.0.matricule', $pupil['matricule']);

        // The scheduler's run closes what ran out, without a school.
        $this->assertSame(0, Artisan::call('platform:close-attempts'));
        $this->assertSame('submitted', DB::connection('owner')->table('school.quiz_attempt')->where('id', $attempt['id'])->value('status'));
    }
}
