<?php

namespace Tests\Feature;

use App\Filament\School\Pages\CourseOutline;
use App\Filament\School\Pages\CourseReader;
use App\Filament\School\Pages\Courses;
use App\Support\CurrentSchool;
use App\Support\LessonDocument;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * FR-LMS-01 on screen: the manager writes and publishes a course from its outline; its pupil reads it,
 * drawn from its document without any HTML of its own; the assigned teacher adds a resource and
 * withdraws it.
 */
final class CourseScreensTest extends TestCase
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

    public function test_the_editor_s_extra_attributes_are_dropped_and_the_rest_left_to_the_database(): void
    {
        $this->assertSame(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Voir', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://github.com/a', 'target' => '_blank']]]]]],
            ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => 'Titre']]],
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'x']]],
        ]], LessonDocument::clean(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['textAlign' => 'start', 'class' => 'x'], 'content' => [['type' => 'text', 'text' => ''],
                ['type' => 'text', 'text' => 'Voir', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://github.com/a', 'target' => '_blank', 'rel' => 'noopener', 'class' => null]]]]]],
            ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'center', 'id' => 'h'], 'content' => [['type' => 'text', 'text' => 'Titre']]],
            ['type' => 'script', 'attrs' => ['onload' => 'x'], 'content' => [['type' => 'text', 'text' => 'x']]],
        ]]));
        $this->assertSame(LessonDocument::EMPTY, LessonDocument::clean(null));
        $this->assertNull(LessonDocument::href('javascript:alert(1)'));
    }

    public function test_a_course_is_written_and_published_from_its_outline_read_by_its_pupil_and_enriched_by_its_teacher(): void
    {
        $s = $this->setting('complete');
        $karim = $this->account('karim');
        $this->member($s['school'], $karim, 'active');
        $this->grant($s['school'], $karim, 'teacher', 'personal');
        $record = $this->getJson("/api/staff/{$s['teacher']}")->json('data');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $karim['id'], 'version' => $record['version']])->assertOk();
        $this->postJson('/api/teaching-assignments', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'staff_id' => $s['teacher']])->assertCreated();
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Elève', 'first_name_lat' => 'Sami', 'birth_date' => '1996-02-01'])->assertCreated()->json('data');
        $sami = $this->account('sami', false);
        $this->member($s['school'], $sami, 'active');
        $this->grant($s['school'], $sami, 'student', 'personal');
        $this->postJson("/api/students/{$pupil['id']}/account", ['user_id' => $sami['id'], 'version' => $pupil['version']])->assertOk();
        $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil['id'], 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])->json('data');
        $this->postJson("/api/groups/{$s['group']}/memberships", ['enrollment_id' => $enrolment['id']])->assertCreated();
        $this->postJson("/api/enrollments/{$enrolment['id']}/validate", ['version' => $this->getJson("/api/enrollments/{$enrolment['id']}")->json('data.version'),
            'reason' => 'Dossier complet'])->assertOk();

        // The manager creates the course and writes it from its outline.
        $this->get('/school/courses')->assertOk()->assertSee(__('pedagogy.create_course'));
        $this->within($s['school'], $s['manager'], function () use ($s) {
            Livewire::test(Courses::class)->callTableAction('create', data: ['program_id' => $s['program'], 'subject_id' => $s['maths']])->assertHasNoTableActionErrors();
        });
        $course = DB::connection('owner')->table('school.course')->where('program_id', $s['program'])->value('id');
        $this->get('/school/course-outline?course='.$course)->assertOk()->assertSee(__('pedagogy.no_chapter'));
        $this->within($s['school'], $s['manager'], function () use ($course) {
            Livewire::withQueryParams(['course' => $course])->test(CourseOutline::class)
                ->callTableAction('addChapter', data: ['title' => 'Les fractions'])->assertHasNoTableActionErrors();
            Livewire::withQueryParams(['course' => $course])->test(CourseOutline::class)
                ->callTableAction('addChapter', data: ['title' => 'Les décimaux'])->assertHasNoTableActionErrors();
        });
        [$fractions, $decimals] = DB::connection('owner')->table('school.course_chapter')->where('course_id', $course)->orderBy('position')->pluck('id')->all();
        $this->within($s['school'], $s['manager'], function () use ($course, $fractions, $decimals) {
            Livewire::withQueryParams(['course' => $course])->test(CourseOutline::class)
                ->callTableAction('addLesson', data: ['chapter_id' => $fractions, 'title' => 'Partager une tarte'])->assertHasNoTableActionErrors()
                ->callTableAction('down', $fractions)->assertHasNoTableActionErrors();
            $this->assertSame([$decimals, $fractions], DB::table('school.course_chapter')->where('course_id', $course)->orderBy('position')->pluck('id')->all());
        });
        $lesson = DB::connection('owner')->table('school.lesson')->where('course_id', $course)->first();
        $content = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => 'start'], 'content' => [
            ['type' => 'text', 'text' => 'Une tarte en '], ['type' => 'text', 'text' => 'deux', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' <script>alert(1)</script> parts. '],
            ['type' => 'text', 'text' => 'Exercices', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://docs.google.com/d/1', 'target' => '_blank', 'rel' => 'noopener', 'class' => null]]]]]]]];
        $this->within($s['school'], $s['manager'], function () use ($course, $lesson, $content) {
            Livewire::withQueryParams(['course' => $course])->test(CourseOutline::class)
                ->callTableAction('edit', $lesson->id, data: ['title' => 'Partager une tarte', 'objectives' => 'Lire une fraction', 'duration_minutes' => 45, 'content' => $content])
                ->assertHasNoTableActionErrors()
                ->assertTableActionVisible('preview', $lesson->id)
                ->callTableAction('publishLesson', $lesson->id, data: [])->assertHasNoTableActionErrors();
        });
        $this->assertSame(['published', 'Lire une fraction'], array_values((array) DB::connection('owner')->table('school.lesson')->where('id', $lesson->id)->first(['status', 'objectives'])));
        // The preview draws the working copy as the pupil will read it.
        $this->assertStringContainsString('<strong>deux</strong>', view('filament.school.lesson-preview', ['lesson' => ['objectives' => 'Lire une fraction', 'prerequisites' => null, 'content' => LessonDocument::clean($content)]])->render());

        // The teacher adds a resource; the pupil reads the lesson drawn from its document, the script as text.
        $this->signIn($karim)->assertOk();
        $resource = $this->postJson("/api/lessons/{$lesson->id}/resources", ['title' => 'Corrigé', 'url' => 'https://github.com/ecole/corrige'])->assertCreated()->json('data');
        $this->postJson("/api/lessons/{$lesson->id}/resources", ['title' => 'Corrigé', 'url' => 'https://evil.dz/x'])->assertStatus(422)->assertJsonPath('code', 'lms_link_domain_blocked');
        $this->signIn($sami)->assertOk();
        $this->get('/school/courses')->assertOk()->assertSee(__('pedagogy.my_courses'));
        $page = $this->get('/school/course-reader?course='.$course)->assertOk()->assertSee('Partager une tarte')->assertSee('Corrigé')
            ->assertSee('href="https://docs.google.com/d/1" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('<strong>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
        $this->postJson("/api/lessons/{$lesson->id}/resources", ['title' => 'x', 'url' => 'https://github.com/x'])->assertForbidden();
        $this->get('/school/course-outline?course='.$course)->assertForbidden();

        // The teacher withdraws their own resource from the course's screen.
        $this->signIn($karim)->assertOk();
        $this->within($s['school'], $karim, function () use ($course, $resource) {
            Livewire::withQueryParams(['course' => $course])->test(CourseReader::class)->assertSee('Corrigé')
                ->callAction('withdrawResource', arguments: ['resource' => $resource['id'], 'version' => $resource['version']])->assertHasNoActionErrors()
                ->assertDontSee('Corrigé');
        });
        $this->assertNotNull(DB::connection('owner')->table('school.lesson_resource')->where('id', $resource['id'])->value('archived_at'));
    }
}
