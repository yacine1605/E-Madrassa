<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * Lot 5's exit: the seven paths of SPECIFICATION_LOT5_PEDAGOGIE.md §8 end to end through the API, as
 * the pedagogy manager, an assigned teacher and three pupils (one without access), with the refusals
 * of §7 and the journal's trace. The screens' own walk is ActivityScreensTest and PupilScreensTest.
 */
final class LotFiveWalkthroughTest extends TestCase
{
    use PlansSessions;

    public function test_the_seven_paths_of_lot_five(): void
    {
        $s = $this->setting('complete');
        $owner = DB::connection('owner');
        $other = $this->postJson('/api/groups', ['year_id' => $s['year'], 'program_id' => $s['program'], 'site_id' => $s['site'], 'code' => '4AM-2', 'labels' => ['fr' => '4AM 2'],
            'group_type' => 'class', 'capacity' => 25, 'valid_from' => now()->subMonth()->toDateString(), 'status' => 'active'])->assertCreated()->json('data.id');
        $karim = $this->account('karim');
        $this->member($s['school'], $karim, 'active');
        $this->grant($s['school'], $karim, 'teacher', 'personal');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $karim['id'], 'version' => $this->getJson("/api/staff/{$s['teacher']}")->json('data.version')])->assertOk();
        $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'staff_id' => $s['teacher']])->assertCreated();
        // Three pupils of the group: Malek and Sami with access, Nadia whose enrolment is not validated.
        $pupils = [];
        foreach (['malek' => true, 'sami' => true, 'nadia' => false] as $name => $validated) {
            $record = $this->postJson('/api/students', ['last_name_lat' => 'Elève', 'first_name_lat' => Str::ucfirst($name), 'birth_date' => '1996-02-01'])->assertCreated()->json('data');
            $account = $this->account($name, false);
            $this->member($s['school'], $account, 'active');
            $this->grant($s['school'], $account, 'student', 'personal');
            $this->postJson("/api/students/{$record['id']}/account", ['user_id' => $account['id'], 'version' => $record['version']])->assertOk();
            $enrolment = $this->postJson('/api/enrollments', ['student_id' => $record['id'], 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])->json('data');
            $this->postJson("/api/groups/{$s['group']}/memberships", ['enrollment_id' => $enrolment['id']])->assertCreated();
            if ($validated) {
                $enrolment = $this->postJson("/api/enrollments/{$enrolment['id']}/validate", ['version' => $this->getJson("/api/enrollments/{$enrolment['id']}")->json('data.version'),
                    'reason' => 'Dossier complet'])->assertOk()->assertJsonPath('data.access_granted', true)->json('data');
            }
            $pupils[$name] = ['record' => $record['id'], 'account' => $account, 'enrolment' => $enrolment];
        }
        [$malek, $sami, $nadia] = [$pupils['malek'], $pupils['sami'], $pupils['nadia']];
        // The programme's rule: marks on 10, to the half point (§6).
        $rule = $this->postJson('/api/grading-rules', ['program_id' => $s['program'], 'scale_max' => '10', 'step' => '0.5'])->assertCreated()->json('data');

        // 1. A course: chapters ordered, a structured lesson previewed then published; a teacher's resource; HTTP and HTML refused.
        $course = $this->postJson('/api/courses', ['program_id' => $s['program'], 'subject_id' => $s['maths']])->assertCreated()->json('data');
        $one = $this->postJson("/api/courses/{$course['id']}/chapters", ['title' => 'Les fractions'])->assertCreated()->json('data');
        $two = $this->postJson("/api/courses/{$course['id']}/chapters", ['title' => 'Les décimaux'])->assertCreated()->json('data');
        $this->postJson("/api/courses/{$course['id']}/chapters/order", ['chapters' => [$two['id'], $one['id']]])->assertOk();
        $document = fn (string $href) => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Exercices',
            'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]]]]];
        $this->postJson("/api/chapters/{$one['id']}/lessons", ['title' => 'Lien', 'content' => $document('http://github.com/ecole')])->assertStatus(422)->assertJsonPath('code', 'lms_link_invalid');
        $this->postJson("/api/chapters/{$one['id']}/lessons", ['title' => 'HTML', 'content' => ['type' => 'doc', 'content' => [['type' => 'html', 'attrs' => ['value' => '<script>']]]]])
            ->assertStatus(422)->assertJsonPath('code', 'lms_content_invalid');
        $lesson = $this->postJson("/api/chapters/{$one['id']}/lessons", ['title' => 'Partager une tarte', 'objectives' => 'Lire une fraction',
            'content' => $document('https://docs.google.com/document/d/1')])->assertCreated()->json('data');
        $this->postJson('/api/publications/preview', ['scope' => 'chapter', 'id' => $one['id']])->assertOk()->assertJsonPath('data.0.publishes', true);
        $this->postJson('/api/publications', ['scope' => 'chapter', 'id' => $one['id']])->assertCreated();
        $this->signIn($karim)->assertOk();
        $this->postJson("/api/lessons/{$lesson['id']}/resources", ['title' => 'Corrigé', 'url' => 'https://github.com/ecole/corrige'])->assertCreated();
        $this->postJson("/api/lessons/{$lesson['id']}/resources", ['title' => 'Corrigé', 'url' => 'http://github.com/ecole/corrige'])->assertStatus(422)
            ->assertJsonPath('code', 'lms_link_invalid');
        $this->putJson("/api/lessons/{$lesson['id']}", ['title' => 'Autre', 'version' => $lesson['version']])->assertForbidden();

        // 2. The assigned teacher publishes a quiz and two homeworks; another group refused, even by a direct request; the manager administers them.
        $deadline = now('Africa/Algiers')->addDay()->setTime(18, 0)->format('Y-m-d H:i');
        $this->postJson('/api/activities', ['group_id' => $other, 'subject_id' => $s['maths'], 'kind' => 'homework', 'title' => 'Ailleurs', 'deadline_at' => $deadline])
            ->assertForbidden()->assertJsonPath('code', 'lms_group_forbidden');
        $quiz = $this->postJson('/api/activities', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'kind' => 'quiz', 'title' => 'Fractions', 'deadline_at' => $deadline,
            'duration_minutes' => 20, 'max_points' => '10'])->assertCreated()->json('data');
        $single = $this->postJson("/api/activities/{$quiz['id']}/questions", ['kind' => 'single', 'prompt' => 'Un demi ?', 'points' => '6',
            'choices' => [['id' => 'a', 'text' => 'Une moitié', 'correct' => true], ['id' => 'b', 'text' => 'Un tiers', 'correct' => false]]])->assertCreated()->json('data');
        $open = $this->postJson("/api/activities/{$quiz['id']}/questions", ['kind' => 'open', 'prompt' => 'Expliquez', 'points' => '4'])->assertCreated()->json('data');
        $this->postJson("/api/activities/{$quiz['id']}/publish")->assertCreated();
        $works = [];
        foreach (['Exposé' => ['late_policy' => 'accept_until', 'late_until' => now('Africa/Algiers')->addDays(3)->format('Y-m-d H:i')], 'Projet' => []] as $title => $policy) {
            $works[$title] = $this->postJson('/api/activities', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'kind' => 'homework', 'title' => $title,
                'deadline_at' => $deadline, 'max_points' => '20', ...$policy])->assertCreated()->json('data');
            $this->postJson("/api/activities/{$works[$title]['id']}/publish")->assertCreated();
        }
        [$lenient, $strict] = [$works['Exposé'], $works['Projet']];
        $this->signIn($s['manager'])->assertOk();
        $this->putJson("/api/activities/{$lenient['id']}", ['coefficient' => '2', 'version' => $this->getJson("/api/activities/{$lenient['id']}")->json('data.version')])->assertOk();
        $this->postJson("/api/activities/{$lenient['id']}/publish")->assertCreated()->assertJsonPath('data.published_version', 2);
        $this->assertSame($rule['id'], $owner->table('school.activity_version')->where('activity_id', $quiz['id'])->value('grading_rule_id'), 'the rule of its publication');

        // 3. The pupils with access see their audience's activities and the course; Nadia, without access, nothing, even by a direct address.
        $this->signIn($malek['account'])->assertOk();
        $this->getJson('/api/my-activities')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/courses/{$course['id']}/lessons")->assertOk()->assertJsonCount(1, 'data.lessons');
        $this->assertSame(3, $owner->table('school.notification')->where('user_id', $malek['account']['id'])->where('event', 'lms.activity_published')->count());
        $this->signIn($nadia['account'])->assertOk();
        $this->getJson('/api/my-activities')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/my-activities/{$quiz['id']}")->assertForbidden()->assertJsonPath('code', 'lms_access_denied');
        $this->postJson("/api/my-activities/{$quiz['id']}/attempts", ['idempotency_key' => (string) Str::uuid()])->assertForbidden()->assertJsonPath('code', 'lms_access_denied');
        $this->getJson("/api/courses/{$course['id']}/lessons")->assertForbidden()->assertJsonPath('code', 'lms_access_denied');

        // 4. Malek starts the quiz, is cut off, resumes the same attempt with the time left, submits; a resend creates nothing; the end is min(start + duration, deadline).
        $this->signIn($malek['account'])->assertOk();
        $attempt = $this->postJson("/api/my-activities/{$quiz['id']}/attempts", ['idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->putJson("/api/attempts/{$attempt['id']}/answers/{$single['id']}", ['choices' => ['a']])->assertOk();
        $resumed = $this->postJson("/api/my-activities/{$quiz['id']}/attempts", ['idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertSame($attempt['id'], $resumed['id']);
        $this->assertLessThanOrEqual(1200, $resumed['seconds_left']);
        $this->assertSame([['question_id' => $single['id'], 'choices' => ['a']]], array_map(fn ($a) => ['question_id' => $a['question_id'], 'choices' => $a['choices']], $resumed['answers']));
        $this->putJson("/api/attempts/{$attempt['id']}/answers/{$open['id']}", ['answer_text' => 'Deux quarts font un demi.'])->assertOk();
        $key = (string) Str::uuid();
        $this->postJson("/api/attempts/{$attempt['id']}/submit", ['idempotency_key' => $key])->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson("/api/attempts/{$attempt['id']}/submit", ['idempotency_key' => $key])->assertOk();
        $this->postJson("/api/my-activities/{$quiz['id']}/attempts", ['idempotency_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'lms_attempts_exhausted');
        $this->assertSame(1, $owner->table('school.quiz_attempt')->where('activity_id', $quiz['id'])->count());
        $times = $owner->selectOne('SELECT x.started_at, x.ends_at, v.deadline_at FROM school.quiz_attempt x JOIN school.activity_version v ON v.id = x.version_id WHERE x.id = ?', [$attempt['id']]);
        $this->assertSame(min(strtotime($times->started_at) + 1200, strtotime($times->deadline_at)), strtotime($times->ends_at));

        // 5. Homework: an acknowledged submission, its history, a resend creating nothing, a link refused; a flagged link to replace with its date; the policy on lateness.
        $this->postJson("/api/my-activities/{$lenient['id']}/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://wetransfer.com/x']])
            ->assertStatus(422)->assertJsonPath('code', 'lms_link_domain_blocked');
        $key = (string) Str::uuid();
        $this->postJson("/api/my-activities/{$lenient['id']}/submissions", ['idempotency_key' => $key, 'links' => ['https://drive.google.com/file/d/v1']])
            ->assertCreated()->assertJsonPath('data.0.number', 1)->assertJsonPath('data.0.late', false);
        $this->postJson("/api/my-activities/{$lenient['id']}/submissions", ['idempotency_key' => $key, 'links' => ['https://drive.google.com/file/d/v1']])->assertCreated();
        $this->postJson("/api/my-activities/{$lenient['id']}/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://github.com/eleve/v2']])
            ->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('data.1.status', 'replaced');
        $this->signIn($karim)->assertOk();
        $current = $owner->table('school.homework_submission')->where('activity_id', $lenient['id'])->where('status', 'current')->value('id');
        $until = $this->postJson("/api/homework-submissions/$current/flag", ['reason' => 'Lien privé'])->assertOk()->json('data.replace_until');
        $flagged = json_decode($owner->table('school.notification')->where('user_id', $malek['account']['id'])->where('event', 'lms.submission_flagged')->value('data'), true);
        $this->assertSame([$lenient['id'], strtotime($until)], [$flagged['activity_id'], strtotime($flagged['replace_until'])]);
        // An activity not yet open: listed as scheduled, nothing handed in before its opening (§7).
        $later = $this->postJson('/api/activities', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'kind' => 'homework', 'title' => 'Plus tard',
            'opens_at' => now('Africa/Algiers')->addDay()->setTime(8, 0)->format('Y-m-d H:i'), 'deadline_at' => $deadline])->assertCreated()->json('data');
        $this->postJson("/api/activities/{$later['id']}/publish")->assertCreated();
        $this->signIn($malek['account'])->assertOk();
        $this->getJson("/api/my-activities/{$later['id']}")->assertOk()->assertJsonPath('data.state', 'scheduled');
        $this->postJson("/api/my-activities/{$later['id']}/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://github.com/eleve/tot']])
            ->assertStatus(409)->assertJsonPath('code', 'lms_activity_not_open');
        $this->postJson("/api/my-activities/{$lenient['id']}/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://docs.google.com/document/d/v3']])
            ->assertCreated()->assertJsonPath('data.0.number', 3)->assertJsonPath('data.0.late', false);
        // Both deadlines passed: the strict homework refuses Sami, the lenient one takes him late, marked so.
        $owner->statement('ALTER TABLE school.activity_version DISABLE TRIGGER USER');
        $owner->update("UPDATE school.activity_version SET deadline_at = now() - interval '1 hour',
            closes_at = CASE WHEN late_policy = 'refuse' THEN now() - interval '1 hour' ELSE closes_at END WHERE activity_id IN (?, ?)", [$lenient['id'], $strict['id']]);
        $owner->statement('ALTER TABLE school.activity_version ENABLE TRIGGER USER');
        $this->signIn($sami['account'])->assertOk();
        $this->postJson("/api/my-activities/{$strict['id']}/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://github.com/sami/projet']])
            ->assertStatus(409)->assertJsonPath('code', 'lms_deadline_passed');
        $this->postJson("/api/my-activities/{$lenient['id']}/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://github.com/sami/expose']])
            ->assertCreated()->assertJsonPath('data.0.late', true);

        // 6. The choice answered corrected automatically, the open one to correct; nothing shown before publication; a mark only in its pupil's account.
        $this->signIn($karim)->assertOk();
        $results = collect($this->getJson("/api/activities/{$quiz['id']}/results")->assertOk()->json('data'))->keyBy('student_id');
        $this->assertSame(['to_grade', 'not_submitted'], [$results[$malek['record']]['status'], $results[$sami['record']]['status']]);
        $this->assertSame([['6.00', 1]], array_values(array_map(fn ($r) => [$r->auto_points, $r->pending_questions], $owner->select('SELECT auto_points, pending_questions FROM school.quiz_attempt WHERE id = ?', [$attempt['id']]))));
        $this->postJson("/api/attempts/{$attempt['id']}/correction", ['question_id' => $open['id'], 'points' => '3', 'comment' => 'Précisez'])->assertOk();
        $this->postJson("/api/activities/{$lenient['id']}/grades", ['student_id' => $malek['record'], 'points' => '14', 'feedback' => 'Clair'])->assertOk();
        $this->postJson("/api/activities/{$lenient['id']}/grades", ['student_id' => $sami['record'], 'points' => '10'])->assertOk();
        // A question changed once attempted: a new version; the attempt keeps the one it used.
        $this->putJson("/api/activity-questions/{$single['id']}", ['prompt' => 'Une moitié ?', 'version' => $single['version']])->assertOk();
        $this->postJson("/api/activities/{$quiz['id']}/publish")->assertCreated()->assertJsonPath('data.published_version', 2);
        $this->signIn($malek['account'])->assertOk();
        $this->getJson('/api/my-results')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/my-averages?from='.now()->subMonth()->toDateString().'&to='.now()->addMonth()->toDateString())->assertOk()->assertJsonCount(0, 'data.averages');
        $this->signIn($karim)->assertOk();
        $this->postJson("/api/activities/{$quiz['id']}/results/publish")->assertOk()->assertJsonPath('data.published', 1);
        $this->signIn($malek['account'])->assertOk();
        $this->getJson('/api/my-results')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.points', '9.00');
        $this->getJson("/api/attempts/{$attempt['id']}/review")->assertOk()->assertJsonPath('data.questions.0.prompt', 'Un demi ?')
            ->assertJsonPath('data.questions.1.answer.comment', 'Précisez');
        $told = json_decode($owner->table('school.notification')->where('user_id', $malek['account']['id'])->where('event', 'lms.result_published')->value('data'), true);
        $this->assertSame(['activity_id' => $quiz['id']], $told);
        $this->signIn($sami['account'])->assertOk();
        $this->getJson('/api/my-results')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/attempts/{$attempt['id']}/review")->assertForbidden();

        // 7. Averages of published marks only: a missing work missing until a zero is decided; an excused absence and a mark not published left out;
        // a rule for a later period changes nothing past.
        $average = function (array $pupil, string $to): ?string {
            $this->signIn($pupil['account'])->assertOk();
            return $this->getJson('/api/my-averages?from='.now()->subMonth()->toDateString().'&to='.$to)->assertOk()->json('data.averages.0.average');
        };
        $soon = now('Africa/Algiers')->addDay()->toDateString();
        $this->assertSame('9.00', $average($malek, $soon), 'the quiz alone: 9/10; the homework graded but not published');
        $this->signIn($karim)->assertOk();
        $this->postJson("/api/activities/{$lenient['id']}/results/publish")->assertOk()->assertJsonPath('data.published', 2);
        $this->postJson("/api/activities/{$strict['id']}/decisions", ['student_id' => $malek['record'], 'decision' => 'excused', 'reason' => 'Certificat médical'])->assertOk();
        $this->postJson("/api/activities/{$strict['id']}/results/publish")->assertOk();
        // (0.9 × 1 + 0.7 × 2) / 3 = 0.766…: 7.5 on 10, the excused work left out.
        $this->assertSame('7.50', $average($malek, $soon));
        // Sami: his missing quiz and project are no zeros; 10/20 alone gives 5.
        $this->assertSame('5.00', $average($sami, $soon));
        $this->signIn($karim)->assertOk();
        $this->postJson("/api/activities/{$strict['id']}/decisions", ['student_id' => $sami['record'], 'decision' => 'zero_missing'])->assertStatus(409)->assertJsonPath('code', 'reason_required');
        $this->postJson("/api/activities/{$strict['id']}/decisions", ['student_id' => $sami['record'], 'decision' => 'zero_missing', 'reason' => 'Rien remis'])->assertOk();
        $this->postJson("/api/activities/{$strict['id']}/results/publish")->assertOk();
        // (0 × 1 + 0.5 × 2) / 3 = 0.333…: 3.5 on 10.
        $this->assertSame('3.50', $average($sami, $soon));
        $this->signIn($s['manager'])->assertOk();
        $this->postJson("/api/grading-rules/{$rule['id']}/end", ['valid_to' => $soon, 'version' => $rule['version']])->assertOk();
        $this->postJson('/api/grading-rules', ['program_id' => $s['program'], 'scale_max' => '20', 'step' => '1', 'valid_from' => now('Africa/Algiers')->addDays(2)->toDateString()])->assertCreated();
        $this->assertSame('7.50', $average($malek, $soon), 'the past unchanged');
        $this->assertSame('15.00', $average($malek, now()->addMonth()->toDateString()), 'a later period on the new scale');

        // §1: access withdrawn closes the activities and the course at once, the published results kept to read.
        $this->signIn($s['manager'])->assertOk();
        $this->postJson("/api/enrollments/{$malek['enrolment']['id']}/suspend", ['version' => $this->getJson("/api/enrollments/{$malek['enrolment']['id']}")->json('data.version'),
            'reason' => 'Absence prolongée'])->assertOk()->assertJsonPath('data.access_granted', false);
        $this->signIn($malek['account'])->assertOk();
        $this->getJson('/api/my-activities')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/my-activities/{$lenient['id']}/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://github.com/eleve/v4']])
            ->assertForbidden()->assertJsonPath('code', 'lms_access_denied');
        $this->getJson("/api/courses/{$course['id']}/lessons")->assertForbidden();
        $this->getJson('/api/my-results')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame('7.50', $average($malek, $soon));

        // BR-09: the journal keeps who, when, from where, the old and new value and the reason of a decision (SQL 087).
        $entries = $owner->select("SELECT action, actor_id, ip, before->>'status' AS was, after->>'status' AS is FROM school.audit_log
            WHERE tenant_id = ? AND object_type = 'activity_mark' AND reason = 'Rien remis'", [$s['school']->id]);
        $this->assertNotEmpty($entries);
        $this->assertSame([$karim['id']], array_values(array_unique(array_map(fn ($e) => (int) $e->actor_id, $entries))));
        $this->assertNotContains(null, array_column($entries, 'ip'));
        $this->assertContains(['not_submitted', 'graded'], array_map(fn ($e) => [$e->was, $e->is], $entries));
    }
}
