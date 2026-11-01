<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * FR-LMS-04 through the API: the pedagogy manager keeps a programme's or a level's grading rule,
 * never two over the same days, ended but never rewritten; the teacher reads the group's gradebook
 * on the rule's scale and exports it; the average takes the published marks only, and the pupil
 * reads their own.
 */
final class GradingTest extends TestCase
{
    use PlansSessions;

    public function test_grading_rules_scale_the_marks_and_averages_take_the_published_ones_only(): void
    {
        $s = $this->setting('complete');
        $today = now('Africa/Algiers')->toDateString();
        $tomorrow = now('Africa/Algiers')->addDay()->toDateString();
        // Rules: the manager's, today or later, never two over the same days.
        $rule = $this->postJson('/api/grading-rules', ['program_id' => $s['program'], 'scale_max' => '10', 'step' => '0.5'])->assertCreated()
            ->assertJsonPath('data.valid_from', $today)->assertJsonPath('data.rounding', 'half_up')->json('data');
        $this->postJson('/api/grading-rules', ['program_id' => $s['program'], 'scale_max' => '20', 'valid_from' => $tomorrow])->assertConflict()
            ->assertJsonPath('code', 'grading_rule_overlap');
        $this->postJson('/api/grading-rules', ['program_id' => $s['program'], 'scale_max' => '20', 'valid_from' => now()->subDay()->toDateString()])
            ->assertConflict()->assertJsonPath('code', 'grading_rule_past');
        $level = $this->postJson('/api/grading-rules', ['node_id' => $s['node'], 'scale_max' => '100', 'step' => '1', 'pass_ratio' => '0.6'])->assertCreated()->json('data');
        $this->getJson('/api/grading-rules')->assertOk()->assertJsonCount(2, 'data');

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

        // Three homeworks: 15/20 and 6/10 (coefficient 2) published, 2/20 graded but not published.
        $this->signIn($karim)->assertOk();
        $this->postJson('/api/grading-rules', ['program_id' => $s['program'], 'scale_max' => '20', 'valid_from' => $tomorrow])->assertForbidden();
        $this->getJson('/api/grading-rules')->assertOk()->assertJsonCount(2, 'data');
        $deadline = now('Africa/Algiers')->addDay()->format('Y-m-d H:i');
        $works = [];
        foreach ([['H1', '20', '1'], ['H2', '10', '2'], ['H3', '20', '1']] as [$title, $max, $coefficient]) {
            $id = $this->postJson('/api/activities', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'kind' => 'homework', 'title' => $title,
                'deadline_at' => $deadline, 'max_points' => $max, 'coefficient' => $coefficient])->assertCreated()->json('data.id');
            $this->postJson("/api/activities/$id/publish")->assertCreated();
            $works[] = $id;
        }
        $this->signIn($malek)->assertOk();
        foreach ($works as $id) {
            $this->postJson("/api/my-activities/$id/submissions", ['idempotency_key' => (string) Str::uuid(), 'links' => ['https://drive.google.com/file/d/'.$id]])
                ->assertCreated();
        }
        $this->signIn($karim)->assertOk();
        foreach ([[$works[0], '15'], [$works[1], '6'], [$works[2], '2']] as [$id, $points]) {
            $this->postJson("/api/activities/$id/grades", ['student_id' => $pupil['id'], 'points' => $points])->assertOk();
        }
        foreach (array_slice($works, 0, 2) as $id) $this->postJson("/api/activities/$id/results/publish")->assertOk();

        // The gradebook on the rule's scale, rounded to the half point; the average (0.75 × 1 + 0.6 × 2) / 3 = 0.65, 6.5 on 10.
        $period = ['subject_id' => $s['maths'], 'from' => now()->subMonth()->toDateString(), 'to' => $tomorrow];
        $book = $this->getJson("/api/groups/{$s['group']}/gradebook?".http_build_query($period))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student.first_name_lat', 'Malek')->assertJsonPath('data.0.average.average', '6.50')
            ->assertJsonPath('data.0.average.passed', true)->json('data.0.marks');
        usort($book, fn (array $a, array $b) => $a['title'] <=> $b['title']);
        $this->assertSame([['H1', 'published', '7.50', '7.50'], ['H2', 'published', '6.00', '6.00'], ['H3', 'graded', '1.00', null]],
            array_map(fn (array $m) => [$m['title'], $m['status'], $m['mark'], $m['published_mark']], $book));
        // Exported by whoever may launch exports: the pedagogy manager here, a teacher once the school grants it.
        $exportOf = ['kind' => 'gradebook', 'format' => 'csv', 'filters' => ['group_id' => $s['group']] + $period];
        $this->postJson('/api/exports', $exportOf)->assertForbidden();
        $this->signIn($s['manager'])->assertOk();
        $export = $this->postJson('/api/exports', $exportOf)->assertCreated()->json('data');
        $this->assertSame(3, $export['rows']);
        $this->assertSame(['matricule', 'pupil', 'activity', 'deadline', 'status', 'points', 'max_points', 'coefficient', 'mark', 'average'], $export['columns']);
        $csv = $this->get("/api/files/{$export['file']['id']}/content")->assertOk()->streamedContent();
        $this->assertStringContainsString('Elève Malek', $csv);
        $this->assertStringContainsString('6.50', $csv);

        // The pupil reads their own averages and marks, never the gradebook.
        $this->signIn($malek)->assertOk();
        $this->getJson("/api/groups/{$s['group']}/gradebook?".http_build_query($period))->assertForbidden();
        $this->getJson('/api/my-averages?'.http_build_query(['from' => $period['from'], 'to' => $tomorrow]))->assertOk()
            ->assertJsonCount(1, 'data.averages')->assertJsonPath('data.averages.0.average', '6.50')->assertJsonPath('data.averages.0.scale_max', '10.00')
            ->assertJsonCount(2, 'data.marks');

        // A rule in force is ended, never removed; its end is final.
        $this->signIn($s['manager'])->assertOk();
        $this->deleteJson("/api/grading-rules/{$level['id']}", ['version' => $level['version']])->assertConflict()->assertJsonPath('code', 'grading_rule_begun');
        $this->postJson("/api/grading-rules/{$rule['id']}/end", ['valid_to' => $tomorrow, 'version' => $rule['version']])->assertOk()
            ->assertJsonPath('data.valid_to', $tomorrow);
        $this->postJson("/api/grading-rules/{$rule['id']}/end", ['valid_to' => now()->addDays(9)->toDateString(), 'version' => $rule['version'] + 1])
            ->assertConflict()->assertJsonPath('code', 'grading_rule_ended');
        $later = $this->postJson('/api/grading-rules', ['program_id' => $s['program'], 'scale_max' => '20', 'valid_from' => now()->addDays(2)->toDateString()])
            ->assertCreated()->json('data');
        $this->deleteJson("/api/grading-rules/{$later['id']}", ['version' => $later['version']])->assertNoContent();
    }
}
