<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * FR-LMS-02 through the API: the pupil hands in a homework's links, again after a cut without a
 * second submission; the teacher flags a link that does not open; the pupil replaces it.
 */
final class HomeworkSubmissionTest extends TestCase
{
    use PlansSessions;

    public function test_a_pupil_hands_in_links_once_per_key_and_replaces_one_the_teacher_flags(): void
    {
        $s = $this->setting('complete');
        $karim = $this->account('karim');
        $this->member($s['school'], $karim, 'active');
        $this->grant($s['school'], $karim, 'teacher', 'personal');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $karim['id'], 'version' => $this->getJson("/api/staff/{$s['teacher']}")->json('data.version')])->assertOk();
        $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'staff_id' => $s['teacher']])->assertCreated();
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Elève', 'first_name_lat' => 'Inès', 'birth_date' => '1996-02-01'])->assertCreated()->json('data');
        $ines = $this->account('ines', false);
        $this->member($s['school'], $ines, 'active');
        $this->grant($s['school'], $ines, 'student', 'personal');
        $this->postJson("/api/students/{$pupil['id']}/account", ['user_id' => $ines['id'], 'version' => $pupil['version']])->assertOk();
        $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil['id'], 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])->json('data');
        $this->postJson("/api/groups/{$s['group']}/memberships", ['enrollment_id' => $enrolment['id']])->assertCreated();
        $this->postJson("/api/enrollments/{$enrolment['id']}/validate", ['version' => $this->getJson("/api/enrollments/{$enrolment['id']}")->json('data.version'),
            'reason' => 'Dossier complet'])->assertOk();

        $this->signIn($karim)->assertOk();
        $homework = $this->postJson('/api/activities', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'kind' => 'homework', 'title' => 'Exposé',
            'deadline_at' => now('Africa/Algiers')->addDays(3)->format('Y-m-d H:i')])->assertCreated()->json('data');
        $this->postJson("/api/activities/{$homework['id']}/publish")->assertCreated();

        // Handed in; after a cut, the same key gives the same submission, no second one.
        $this->signIn($ines)->assertOk();
        $key = (string) Str::uuid();
        $links = ['https://drive.google.com/file/d/expose'];
        $this->postJson("/api/my-activities/{$homework['id']}/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://dropbox.com/x']])
            ->assertStatus(422)->assertJsonPath('code', 'lms_link_domain_blocked');
        $this->postJson("/api/my-activities/{$homework['id']}/submissions", ['idempotency_key' => $key, 'links' => $links])->assertCreated()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.late', false)->assertJsonPath('data.0.links', $links);
        $this->postJson("/api/my-activities/{$homework['id']}/submissions", ['idempotency_key' => $key, 'links' => $links])->assertCreated()->assertJsonCount(1, 'data');
        $this->getJson("/api/activities/{$homework['id']}/submissions")->assertForbidden();

        // The teacher flags it; the pupil hands in another, the first kept.
        $this->signIn($karim)->assertOk();
        $submission = $this->getJson("/api/activities/{$homework['id']}/submissions")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.matricule', $pupil['matricule'])->json('data.0');
        $this->postJson("/api/homework-submissions/{$submission['id']}/flag", ['reason' => 'Le lien demande une autorisation'])->assertOk();
        $this->signIn($ines)->assertOk();
        $this->getJson("/api/my-activities/{$homework['id']}/submissions")->assertJsonPath('data.0.status', 'inaccessible')
            ->assertJsonPath('data.0.flag_reason', 'Le lien demande une autorisation');
        $this->postJson("/api/my-activities/{$homework['id']}/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://github.com/ines/expose']])
            ->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'current')->assertJsonPath('data.0.number', 2);
    }
}
