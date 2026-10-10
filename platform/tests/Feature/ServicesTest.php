<?php

namespace Tests\Feature;

use App\Jobs\RunTask;
use App\Mail\NotificationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CrashingImport;
use Tests\TestCase;

/** The journal, background jobs and notifications through the API (FR-AUD-01, FR-JOB-01, FR-NOT-01). */
final class ServicesTest extends TestCase
{
    private function rows(int $count, string $prefix = 'EL'): array
    {
        return array_map(fn ($i) => ['external_ref' => sprintf('%s-%04d', $prefix, $i), 'name' => "Élève $i", 'username' => sprintf('%s%04d', strtolower($prefix), $i)],
            range(1, $count));
    }

    public function test_the_journal_shows_each_reader_the_domains_they_may_read(): void
    {
        $school = $this->school();
        [$admin, $accountant, $teacher] = [$this->account('admin'), $this->account('accountant'), $this->account('teacher')];
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->member($school, $accountant, 'active', ['accountant']);
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($admin);
        $this->postJson('/api/sites', ['code' => 'ANNEXE', 'labels' => ['fr' => 'Annexe']])->assertCreated();
        $this->getJson('/api/audit?object_type=role_assignment')->assertOk()->assertJsonCount(3, 'data');
        $first = $this->getJson('/api/audit?limit=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/audit?limit=1&before='.urlencode($first->json('next')))->assertOk()
            ->assertJsonPath('data.0.id', fn ($id) => $id !== $first->json('data.0.id'));

        // FR-AUD-01: an accountant reads the referential's journal, not the accounts' and rights'.
        $this->signIn($accountant);
        $this->getJson('/api/audit?object_type=role_assignment')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/audit?domain=referential')->assertOk()->assertJsonPath('data.0.object_type', 'site');
        $this->signIn($teacher);
        $this->getJson('/api/audit')->assertForbidden();
        // AC2: no route changes the journal.
        $this->assertSame([], collect(Route::getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'audit')
            && array_diff($route->methods(), ['GET', 'HEAD']))->values()->all());
    }

    public function test_a_background_import_runs_once_per_key_and_reports(): void
    {
        $school = $this->school();
        $admin = $this->account('admin');
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->signIn($admin);
        $key = (string) Str::uuid();
        $job = $this->postJson('/api/accounts/import', ['background' => true, 'idempotency_key' => $key, 'rows' => $this->rows(250)])
            ->assertStatus(202)->assertJsonPath('data.kind', 'accounts.import')->json('data.id');
        $this->getJson("/api/jobs/$job")->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.result.created', 250)->assertJsonPath('data.result.rejected', []);
        // The same key gives the same job, which does not run again.
        $this->postJson('/api/accounts/import', ['background' => true, 'idempotency_key' => $key, 'rows' => $this->rows(250)])
            ->assertStatus(202)->assertJsonPath('data.id', $job);
        $this->assertSame(251, DB::connection('owner')->table('school.membership')->where('tenant_id', $school->id)->count());
        $this->getJson('/api/jobs')->assertOk()->assertJsonCount(1, 'data');
        // Its author is told it is done.
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('data.0.event', 'job.succeeded')->assertJsonPath('data.0.data.job_id', $job);
    }

    public function test_a_job_resumed_after_a_crash_does_not_duplicate_its_effects(): void
    {
        $school = $this->school();
        $admin = $this->account('admin');
        $this->member($school, $admin, 'active', ['tenant_admin']);
        config(['platform.tasks' => config('platform.tasks') + ['tests.crash' => CrashingImport::class]]);
        CrashingImport::$crashed = false;
        $job = DB::connection('owner')->selectOne("INSERT INTO school.job (tenant_id, user_id, kind, idempotency_key, input)
            VALUES (?, ?, 'tests.crash', 'crash-1', ?::jsonb) RETURNING id", [$school->id, $admin['id'], json_encode(['rows' => $this->rows(250)])])->id;

        // FR-JOB-01 AC2: the first batch is committed, then the worker dies; the job goes back to the queue.
        (new RunTask($school->id, $admin['id'], $job))->handle();
        $state = DB::connection('owner')->selectOne('SELECT status, attempts FROM school.job WHERE id = ?', [$job]);
        $this->assertSame(['queued', 1], [$state->status, $state->attempts]);
        $this->assertSame(101, DB::connection('owner')->table('school.membership')->where('tenant_id', $school->id)->count());
        // Delivered again, it resumes; then a duplicate delivery finds it finished and does nothing.
        (new RunTask($school->id, $admin['id'], $job))->handle();
        (new RunTask($school->id, $admin['id'], $job))->handle();
        $state = DB::connection('owner')->selectOne('SELECT status, attempts, result FROM school.job WHERE id = ?', [$job]);
        $this->assertSame(['succeeded', 2], [$state->status, $state->attempts]);
        $this->assertSame(['created' => 150, 'unchanged' => 100], array_intersect_key(json_decode($state->result, true), ['created' => 1, 'unchanged' => 1]));
        $this->assertSame(251, DB::connection('owner')->table('school.membership')->where('tenant_id', $school->id)->count());
    }

    public function test_jobs_are_cancelled_retried_and_seen_by_their_author_only(): void
    {
        $school = $this->school();
        [$admin, $other] = [$this->account('admin'), $this->account('other')];
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->member($school, $other, 'active', ['tenant_admin']);
        $insert = fn (string $key, string $status = 'queued') => DB::connection('owner')->selectOne("INSERT INTO school.job (tenant_id, user_id, kind, idempotency_key, input)
            VALUES (?, ?, 'accounts.import', ?, '{\"rows\": []}') RETURNING id", [$school->id, $admin['id'], $key])->id;
        [$queued, $failed] = [$insert('a'), $insert('b')];
        DB::connection('owner')->update("UPDATE school.job SET status = 'running' WHERE id = ?", [$failed]);
        DB::connection('owner')->update("UPDATE school.job SET status = 'failed', error_code = 'technical_error' WHERE id = ?", [$failed]);
        $this->signIn($admin);
        $this->postJson("/api/jobs/$queued/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/jobs/$queued/cancel")->assertStatus(409)->assertJsonPath('code', 'job_finished');
        $this->postJson("/api/jobs/$failed/retry")->assertStatus(202);
        $this->getJson("/api/jobs/$failed")->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.error_code', null);
        $this->signIn($other);
        $this->getJson("/api/jobs/$queued")->assertNotFound();
        $this->getJson('/api/jobs')->assertJsonCount(0, 'data');
    }

    public function test_a_sensitive_assignment_notifies_the_journal_watchers_who_get_an_email(): void
    {
        Mail::fake();
        $school = $this->school();
        [$admin, $watcher, $clerk] = [$this->account('admin'), $this->account('watcher'), $this->account('clerk')];
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->member($school, $watcher, 'active', ['tenant_admin']);
        $this->member($school, $clerk);
        $this->signIn($admin);
        $this->postJson('/api/role-assignments', ['user_id' => $clerk['id'], 'role_id' => $this->roleId($school, 'accountant'), 'scope_type' => 'tenant'])
            ->assertCreated();
        $this->getJson('/api/notifications')->assertJsonCount(0, 'data');

        // FR-IAM-05: the watcher is told; FR-NOT-01 AC2: nobody else sees it.
        $this->signIn($watcher);
        $notification = $this->getJson('/api/notifications?unread=1')->assertOk()->assertJsonPath('unread', 1)
            ->assertJsonPath('data.0.event', 'access.sensitive_permission')->assertJsonPath('data.0.labels.fr', 'Une permission sensible a été attribuée')
            ->json('data.0.id');
        $this->signIn($clerk);
        $this->getJson("/api/notifications")->assertJsonMissing(['id' => $notification]);
        $this->postJson("/api/notifications/$notification/read")->assertNotFound();
        $this->signIn($watcher);
        $this->postJson("/api/notifications/$notification/read")->assertNoContent();
        $this->getJson('/api/notifications?unread=1')->assertJsonPath('unread', 0);

        // The email leaves through the outbox: title and school only.
        $this->artisan('platform:outbox')->assertSuccessful();
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($watcher['email'])
            && $mail->title === 'Une permission sensible a été attribuée' && str_starts_with($mail->school, 'École'));
        $this->assertSame(0, DB::connection('owner')->table('school.outbox')->where('tenant_id', $school->id)->count());
        $this->putJson('/api/notifications/preferences', ['email' => ['access.sensitive_permission' => false]])->assertOk()
            ->assertJsonFragment(['event' => 'access.sensitive_permission', 'email' => false]);
    }

    public function test_a_failed_email_never_blocks_the_operation_and_is_sent_again_on_request(): void
    {
        $school = $this->school();
        [$admin, $watcher, $clerk] = [$this->account('admin'), $this->account('watcher'), $this->account('clerk')];
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->member($school, $watcher, 'active', ['tenant_admin']);
        $this->member($school, $clerk);
        $this->signIn($admin);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP 451 temporary failure'));
        // FR-NOT-01 AC1: the assignment is made whatever becomes of the email.
        $this->postJson('/api/role-assignments', ['user_id' => $clerk['id'], 'role_id' => $this->roleId($school, 'accountant'), 'scope_type' => 'tenant'])
            ->assertCreated();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            DB::connection('owner')->update('UPDATE school.outbox SET next_attempt_at = now() WHERE tenant_id = ?', [$school->id]);
            $this->artisan('platform:outbox')->assertSuccessful();
        }
        // The watcher and the new accountant, who now reads the journal too.
        $failure = $this->getJson('/api/deliveries/failed')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event', 'access.sensitive_permission')->assertJsonPath('data.0.attempts', 5)
            ->assertJsonPath('data.0.last_error', fn ($error) => str_contains($error, 'SMTP 451'))->json('data.0.id');
        $this->postJson("/api/deliveries/$failure/retry")->assertNoContent();
        $this->getJson('/api/deliveries/failed')->assertJsonCount(1, 'data');
        $this->postJson("/api/deliveries/$failure/retry")->assertStatus(409)->assertJsonPath('code', 'not_failed');
        $this->signIn($clerk);
        $this->getJson('/api/deliveries/failed')->assertForbidden();
    }
}
