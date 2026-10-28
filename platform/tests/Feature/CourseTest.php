<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * FR-LMS-01: a course's chapters and lessons kept by the pedagogy manager, a structured document
 * and links of the school's allow-list, published in versions; read by the pupils of its programme
 * and the teacher assigned to its subject, refused to anyone else.
 */
final class CourseTest extends TestCase
{
    use PlansSessions;

    private function document(string $text, ?string $href = null): array
    {
        $marks = $href === null ? [] : [['type' => 'link', 'attrs' => ['href' => $href, 'target' => '_blank']]];
        return ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Les fractions']]],
            // An editor's empty attributes reach PHP as an empty array.
            ['type' => 'paragraph', 'attrs' => [], 'content' => [['type' => 'text', 'text' => $text, 'marks' => $marks]]],
        ]];
    }

    /** A pupil of the group signing in with their own account, enrolled and validated. */
    private function pupil(array $s, string $name, bool $enrolled = true): array
    {
        $record = $this->postJson('/api/students', ['last_name_lat' => 'Elève', 'first_name_lat' => $name, 'birth_date' => '1995-04-05'])->assertCreated()->json('data');
        $account = $this->account(Str::lower($name), false);
        $this->member($s['school'], $account, 'active');
        $this->grant($s['school'], $account, 'student', 'personal');
        $this->postJson("/api/students/{$record['id']}/account", ['user_id' => $account['id'], 'version' => $record['version']])->assertOk();
        if ($enrolled) {
            $enrolment = $this->postJson('/api/enrollments', ['student_id' => $record['id'], 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])
                ->assertCreated()->json('data');
            $this->postJson("/api/groups/{$s['group']}/memberships", ['enrollment_id' => $enrolment['id']])->assertCreated();
            $version = $this->getJson("/api/enrollments/{$enrolment['id']}")->json('data.version');
            $this->postJson("/api/enrollments/{$enrolment['id']}/validate", ['version' => $version, 'reason' => 'Dossier complet'])->assertOk();
        }
        return $account;
    }

    public function test_the_manager_publishes_a_course_in_versions_that_only_its_pupils_and_teacher_read(): void
    {
        $s = $this->setting('complete');
        $karim = $this->account('karim');
        $this->member($s['school'], $karim, 'active');
        $this->grant($s['school'], $karim, 'teacher', 'personal');
        $record = $this->getJson("/api/staff/{$s['teacher']}")->json('data');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $karim['id'], 'version' => $record['version']])->assertOk();
        $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'staff_id' => $s['teacher']])->assertCreated();
        $amine = $this->pupil($s, 'Amine');
        $lina = $this->pupil($s, 'Lina', false);

        // The course: a subject the programme teaches.
        $this->postJson('/api/courses', ['program_id' => $s['program'], 'subject_id' => $s['physics']])->assertStatus(409)->assertJsonPath('code', 'lms_subject_outside_program');
        $course = $this->postJson('/api/courses', ['program_id' => $s['program'], 'subject_id' => $s['maths']])->assertCreated()->json('data');
        $one = $this->postJson("/api/courses/{$course['id']}/chapters", ['title' => 'Les fractions'])->assertCreated()->assertJsonPath('data.position', 1)->json('data');
        $two = $this->postJson("/api/courses/{$course['id']}/chapters", ['title' => 'Les décimaux'])->assertCreated()->assertJsonPath('data.position', 2)->json('data');
        $this->postJson("/api/courses/{$course['id']}/chapters/order", ['chapters' => [$two['id']]])->assertStatus(422)->assertJsonPath('code', 'lms_order_invalid');
        $this->postJson("/api/courses/{$course['id']}/chapters/order", ['chapters' => [$two['id'], $one['id']]])->assertOk()
            ->assertJsonPath('data.chapters.0.id', $two['id'])->assertJsonPath('data.chapters.1.position', 2);
        $this->postJson("/api/courses/{$course['id']}/chapters/order", ['chapters' => [$one['id'], $two['id']]])->assertOk();

        // A document of the schema, its links on the allow-list; HTML and other sites refused (lot 5 §7).
        $this->postJson("/api/chapters/{$one['id']}/lessons", ['title' => 'Lien', 'content' => $this->document('Voir', 'http://github.com/ecole')])
            ->assertStatus(422)->assertJsonPath('code', 'lms_link_invalid');
        $this->postJson("/api/chapters/{$one['id']}/lessons", ['title' => 'Lien', 'content' => $this->document('Voir', 'https://drive.google.com.evil.dz/x')])
            ->assertStatus(422)->assertJsonPath('code', 'lms_link_domain_blocked');
        $this->postJson("/api/chapters/{$one['id']}/lessons", ['title' => 'HTML', 'content' => ['type' => 'doc', 'content' => [['type' => 'html', 'attrs' => ['value' => '<script>']]]]])
            ->assertStatus(422)->assertJsonPath('code', 'lms_content_invalid');
        $lesson = $this->postJson("/api/chapters/{$one['id']}/lessons", ['title' => 'Partager une tarte', 'objectives' => 'Lire une fraction',
            'duration_minutes' => 45, 'content' => $this->document('Une tarte en deux.', 'https://docs.google.com/document/d/1')])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.position', 1)->json('data');
        $this->postJson('/api/publications/preview', ['scope' => 'chapter', 'id' => $one['id']])->assertOk()
            ->assertJsonPath('data.0.publishes', true)->assertJsonPath('data.0.problem', null);
        $this->postJson('/api/publications', ['scope' => 'chapter', 'id' => $one['id']])->assertCreated()->assertJsonPath('data.0.version', 1);

        // The pupil of the programme and the assigned teacher read it; nobody else, the same refusal whether the course exists or not.
        $this->signIn($amine)->assertOk();
        $this->getJson('/api/courses')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject_code', $course['subject_code']);
        $this->getJson("/api/courses/{$course['id']}/lessons")->assertOk()->assertJsonCount(1, 'data.lessons')
            ->assertJsonPath('data.lessons.0.title', 'Partager une tarte')->assertJsonPath('data.lessons.0.chapter_title', 'Les fractions')
            ->assertJsonPath('data.lessons.0.content.content.1.content.0.text', 'Une tarte en deux.');
        $this->getJson("/api/courses/{$course['id']}/outline")->assertForbidden();
        $this->postJson('/api/courses', ['program_id' => $s['program'], 'subject_id' => $s['maths']])->assertForbidden();
        $this->signIn($karim)->assertOk();
        $this->getJson("/api/courses/{$course['id']}/lessons")->assertOk()->assertJsonCount(1, 'data.lessons');
        $this->signIn($lina)->assertOk();
        $this->getJson('/api/courses')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/courses/{$course['id']}/lessons")->assertForbidden()->assertJsonPath('code', 'lms_access_denied');
        $this->getJson('/api/courses/'.Str::uuid().'/lessons')->assertForbidden()->assertJsonPath('code', 'lms_access_denied');

        // A change is a draft until published again; the pupil reads the version published meanwhile.
        $this->signIn($s['manager'])->assertOk();
        $this->putJson("/api/lessons/{$lesson['id']}", ['title' => 'Partager une tarte en parts', 'version' => $lesson['version'] + 1])
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.published_version', 1)->assertJsonCount(1, 'data.versions');
        $this->signIn($amine)->assertOk();
        $this->getJson("/api/courses/{$course['id']}/lessons")->assertJsonPath('data.lessons.0.title', 'Partager une tarte');
        $this->signIn($s['manager'])->assertOk();
        $this->postJson('/api/publications', ['scope' => 'lesson', 'id' => $lesson['id'], 'visible_from' => now('Africa/Algiers')->addDay()->format('Y-m-d H:i')])
            ->assertCreated()->assertJsonPath('data.0.version', 2);
        $this->getJson("/api/courses/{$course['id']}/outline")->assertOk()->assertJsonPath('data.chapters.0.lessons.0.scheduled', true);
        $this->postJson('/api/publications', ['scope' => 'course', 'id' => $course['id'], 'visible_from' => '2020-01-01 08:00'])
            ->assertStatus(409)->assertJsonPath('code', 'lms_publish_past');
        $this->signIn($amine)->assertOk();
        $this->getJson("/api/courses/{$course['id']}/lessons")->assertJsonPath('data.lessons.0.title', 'Partager une tarte')->assertJsonPath('data.lessons.0.version', 1);
        $this->assertSame(2, DB::connection('owner')->table('school.lesson_version')->where('lesson_id', $lesson['id'])->count());
    }
}
