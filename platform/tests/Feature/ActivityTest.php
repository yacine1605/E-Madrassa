<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * FR-LMS-02, FR-LMS-03 through the API: the teacher of a group's subject writes and publishes a quiz
 * for the group's pupils with access; a colleague is refused; the pupil reads their own activity
 * without its questions; an extension is given to one pupil with its reason.
 */
final class ActivityTest extends TestCase
{
    use PlansSessions;

    public function test_the_teacher_of_the_group_s_subject_publishes_a_quiz_its_pupil_reads_without_its_answers(): void
    {
        $s = $this->setting('complete');
        $staff = ['karim' => $s['teacher'], 'lina' => $this->postJson('/api/staff', ['last_name_lat' => 'Haddad', 'first_name_lat' => 'Lina',
            'staff_type' => 'teacher_internal'])->assertCreated()->json('data.id')];
        $this->postJson("/api/staff/{$staff['lina']}/qualifications", ['subject_id' => $s['maths'], 'node_id' => $s['node']])->assertCreated();
        $accounts = [];
        foreach ($staff as $name => $id) {
            $accounts[$name] = $this->account($name);
            $this->member($s['school'], $accounts[$name], 'active');
            $this->grant($s['school'], $accounts[$name], 'teacher', 'personal');
            $this->postJson("/api/staff/$id/account", ['user_id' => $accounts[$name]['id'], 'version' => $this->getJson("/api/staff/$id")->json('data.version')])->assertOk();
        }
        $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'staff_id' => $s['teacher']])->assertCreated();
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Elève', 'first_name_lat' => 'Nour', 'birth_date' => '1996-02-01'])->assertCreated()->json('data');
        $nour = $this->account('nour', false);
        $this->member($s['school'], $nour, 'active');
        $this->grant($s['school'], $nour, 'student', 'personal');
        $this->postJson("/api/students/{$pupil['id']}/account", ['user_id' => $nour['id'], 'version' => $pupil['version']])->assertOk();
        $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil['id'], 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])->json('data');
        $this->postJson("/api/groups/{$s['group']}/memberships", ['enrollment_id' => $enrolment['id']])->assertCreated();
        $this->postJson("/api/enrollments/{$enrolment['id']}/validate", ['version' => $this->getJson("/api/enrollments/{$enrolment['id']}")->json('data.version'),
            'reason' => 'Dossier complet'])->assertOk();
        $deadline = now('Africa/Algiers')->addDays(2)->setTime(18, 0);

        // A colleague who does not teach the group's subject writes nothing for it (lot 5 §7).
        $quiz = ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'kind' => 'quiz', 'title' => 'Les fractions', 'deadline_at' => $deadline->format('Y-m-d H:i'),
            'duration_minutes' => 30, 'max_points' => '10'];
        $this->signIn($accounts['lina'])->assertOk();
        $this->postJson('/api/activities', $quiz)->assertForbidden()->assertJsonPath('code', 'lms_group_forbidden');
        // The teacher writes it; the deadline is Algiers' time.
        $this->signIn($accounts['karim'])->assertOk();
        $activity = $this->postJson('/api/activities', $quiz)->assertCreated()->assertJsonPath('data.state', 'draft')
            ->assertJsonPath('data.problems.0.problem', 'lms_quiz_empty')->json('data');
        $this->assertTrue(Carbon::parse($activity['deadline_at'])->equalTo($deadline));
        $this->postJson("/api/activities/{$activity['id']}/questions", ['kind' => 'single', 'prompt' => 'Un demi ?', 'points' => '6',
            'choices' => [['id' => 'a', 'text' => '1/2', 'correct' => true], ['id' => 'b', 'text' => '1/3', 'correct' => true]]])
            ->assertStatus(422)->assertJsonPath('code', 'lms_question_correct_count');
        $this->postJson("/api/activities/{$activity['id']}/questions", ['kind' => 'single', 'prompt' => 'Un demi ?', 'points' => '6',
            'choices' => [['id' => 'a', 'text' => '1/2', 'correct' => true], ['id' => 'b', 'text' => '1/3', 'correct' => false]]])->assertCreated();
        $this->postJson("/api/activities/{$activity['id']}/publish")->assertStatus(409)->assertJsonPath('code', 'lms_points_mismatch');
        $this->postJson("/api/activities/{$activity['id']}/questions", ['kind' => 'open', 'prompt' => 'Expliquez', 'points' => '4'])->assertCreated();
        $published = $this->postJson("/api/activities/{$activity['id']}/publish")->assertCreated()->assertJsonPath('data.published_version', 1)
            ->assertJsonPath('data.state', 'open')->assertJsonPath('data.audience', 1)->json('data');
        $this->deleteJson("/api/activities/{$activity['id']}", ['version' => $published['version']])->assertStatus(409)->assertJsonPath('code', 'lms_activity_published');
        $this->signIn($accounts['lina'])->assertOk();
        $this->getJson("/api/activities/{$activity['id']}")->assertNotFound();
        $this->getJson('/api/activities')->assertOk()->assertJsonCount(0, 'data');

        // The pupil reads their activity, never its questions nor its answers; another is refused alike whether it exists or not.
        $this->signIn($nour)->assertOk();
        $mine = $this->getJson('/api/my-activities')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Les fractions')
            ->assertJsonPath('data.0.state', 'open')->assertJsonPath('data.0.extended', false)->json('data.0');
        $this->assertArrayNotHasKey('questions', $mine);
        $this->assertStringNotContainsString('1/3', $this->getJson("/api/my-activities/{$activity['id']}")->assertOk()->getContent());
        $this->getJson('/api/my-activities/'.Str::uuid())->assertForbidden()->assertJsonPath('code', 'lms_access_denied');
        $this->getJson('/api/activities')->assertForbidden();

        // One pupil's deadline extended, with its reason.
        $this->signIn($accounts['karim'])->assertOk();
        $entry = $this->getJson("/api/activities/{$activity['id']}/audience")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->postJson("/api/activity-audience/{$entry['id']}/extend", ['deadline_at' => $deadline->copy()->addDays(3)->format('Y-m-d H:i'), 'version' => $entry['version']])
            ->assertUnprocessable();
        $this->postJson("/api/activity-audience/{$entry['id']}/extend", ['deadline_at' => $deadline->copy()->addDays(3)->format('Y-m-d H:i'), 'reason' => 'Certificat médical',
            'version' => $entry['version']])->assertOk()->assertJsonPath('data.0.extension_reason', 'Certificat médical');
        $this->signIn($nour)->assertOk();
        $this->getJson('/api/my-activities')->assertJsonPath('data.0.extended', true);
        $this->assertSame(2, DB::connection('owner')->table('school.activity_question')->where('activity_id', $activity['id'])->count());
    }
}
