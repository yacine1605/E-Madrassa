<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Deliveries;
use App\Filament\School\Pages\Exports;
use App\Filament\School\Pages\Imports;
use App\Filament\School\Pages\Notifications;
use App\Filament\School\Pages\Tasks;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** W1.7: tasks, notifications, emails not sent, imports and exports on screen (FR-JOB-01, FR-NOT-01, FR-IMP-01, FR-IMP-02). */
final class SchoolServicesPanelTest extends TestCase
{
    private const CSV = "type;code;parent_code;label_fr;label_ar\r\n"
        ."grade;1AP_BIS;PRIMAIRE;Première année bis;السنة الأولى مكرر\r\n"
        ."grade;bad code;PRIMAIRE;Code invalide;رمز خاطئ\r\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    private function setting(): array
    {
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin, 'private_school_dz');
        $this->member($school, $admin);
        $this->signIn($admin)->assertOk();
        return [$school, $admin];
    }

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

    private function batch(object $school): object
    {
        return DB::connection('owner')->table('school.import_batch')->where('tenant_id', $school->id)->latest('created_at')->first();
    }

    public function test_an_import_is_previewed_confirmed_reported_and_undone_on_screen(): void
    {
        [$school, $admin] = $this->setting();
        $this->within($school, $admin, function () {
            Livewire::test(Imports::class)->callTableAction('template', data: ['kind' => 'referential', 'format' => 'csv'])
                ->assertRedirect('/api/imports/templates/referential?format=csv');
            Livewire::test(Imports::class)->callTableAction('import', data: ['kind' => 'referential',
                'file' => UploadedFile::fake()->createWithContent('referentiel.csv', self::CSV)])->assertNotified(__('services.started'));
        });
        $batch = $this->batch($school);
        $this->assertSame('previewed', $batch->status);
        $this->within($school, $admin, function () use ($batch) {
            Livewire::test(Imports::class)->assertTableActionVisible('confirm', $batch->id)->assertTableActionVisible('report', $batch->id)
                ->assertTableActionHasUrl('report', '/api/imports/'.$batch->id.'/report', $batch->id)
                ->mountTableAction('rejected', $batch->id)
                ->assertMountedActionModalSee(__('validation.regex', ['attribute' => __('validation.attributes.code')]));
            Livewire::test(Imports::class)->callTableAction('confirm', $batch->id)->assertNotified(__('school.saved'));
        });
        $this->assertSame('imported', $this->batch($school)->status);
        $this->assertSame(1, DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)->where('code', '1AP_BIS')->whereNull('archived_at')->count());
        $this->within($school, $admin, fn () => Livewire::test(Imports::class)->callTableAction('rollback', $batch->id)->assertNotified(__('school.saved')));
        $this->assertSame('rolled_back', $this->batch($school)->status);
        $this->assertSame(0, DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)->where('code', '1AP_BIS')->whereNull('archived_at')->count());
    }

    public function test_pupils_are_imported_on_screen_by_whoever_creates_them_for_the_whole_school(): void
    {
        [$school, $admin] = $this->setting();
        $this->grant($school, $admin, 'academic_hr');
        $this->within($school, $admin, fn () => Livewire::test(Imports::class)->callTableAction('import', data: ['kind' => 'students',
            'file' => UploadedFile::fake()->createWithContent('eleves.csv', "external_ref;last_name_lat;first_name_lat;birth_date\r\nEL-1;Benali;Amine;14/03/2012\r\n")])
            ->assertNotified(__('services.started')));
        $this->assertSame(['students', 'previewed'], [$this->batch($school)->kind, $this->batch($school)->status]);
        // A manager of one site creates pupils there, never by a file: nothing to import.
        $local = $this->account('site-manager');
        $this->member($school, $local);
        $this->grant($school, $local, 'academic_hr', 'site', [DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->value('id')]);
        $this->within($school, $local, fn () => Livewire::test(Imports::class)->assertTableActionHidden('import')->assertTableActionHidden('template'));
    }

    public function test_an_export_is_made_at_once_and_downloaded_by_its_author_only(): void
    {
        [$school, $admin] = $this->setting();
        $this->within($school, $admin, fn () => Livewire::test(Exports::class)
            ->callTableAction('export', data: ['kind' => 'accounts', 'format' => 'csv', 'locale' => 'ar', 'status' => 'active'])
            ->assertNotified(__('services.export_ready', ['rows' => 1])));
        $export = DB::connection('owner')->table('school.export_log')->where('tenant_id', $school->id)->first();
        $this->assertSame('{"status": "active"}', $export->filters);
        $this->within($school, $admin, fn () => Livewire::test(Exports::class)
            ->assertTableActionHasUrl('download', '/api/files/'.$export->file_id.'/content', $export->id));
        $this->get('/api/files/'.$export->file_id.'/content')->assertOk();

        $other = $this->account('other-admin');
        $this->member($school, $other, 'active', ['tenant_admin']);
        $this->signIn($other)->assertOk();
        $this->within($school, $other, fn () => Livewire::test(Exports::class)->assertTableActionHidden('download', $export->id));
    }

    public function test_tasks_and_notifications_belong_to_their_person(): void
    {
        [$school, $admin] = $this->setting();
        // A job is born queued, then runs, then fails (guard_job).
        $job = DB::connection('owner')->table('school.job')->insertGetId(['tenant_id' => $school->id, 'user_id' => $admin['id'], 'kind' => 'exports.generate',
            'idempotency_key' => 'k1', 'input' => '{}']);
        DB::connection('owner')->table('school.job')->where('id', $job)->update(['status' => 'running']);
        DB::connection('owner')->table('school.job')->where('id', $job)->update(['status' => 'failed', 'error_code' => 'technical_error',
            'error_message' => __('errors.technical_error')]);
        DB::connection('owner')->table('school.notification')->insert(['tenant_id' => $school->id, 'user_id' => $admin['id'], 'event' => 'job.failed',
            'data' => json_encode(['job_id' => $job]), 'created_at' => now()]);
        $this->get('/school/tasks')->assertOk()->assertSee(__('services.task_kinds.exports.generate'))->assertSee(__('services.task_states.failed'));
        // The bell: one unread notification, counted in the menu.
        $this->get('/school/notifications')->assertOk()->assertSee('Une tâche de fond a échoué')->assertSeeHtml('fi-badge');
        $notification = DB::connection('owner')->table('school.notification')->where('tenant_id', $school->id)->value('id');
        $this->within($school, $admin, function () use ($notification, $job) {
            Livewire::test(Tasks::class)->assertTableActionVisible('retry', $job)->assertTableActionHidden('cancel', $job);
            Livewire::test(Notifications::class)->assertTableActionHasUrl('task', Tasks::getUrl(), $notification)
                ->callTableAction('read', $notification)->assertNotified(__('school.saved'));
            Livewire::test(Notifications::class)->callTableAction('preferences', data: ['events' => ['access.sensitive_permission']])
                ->assertNotified(__('school.saved'));
        });
        $this->assertNotNull(DB::connection('owner')->table('school.notification')->where('id', $notification)->value('read_at'));
        $this->assertFalse(DB::connection('owner')->table('school.notification_preference')->where('user_id', $admin['id'])->where('event', 'job.failed')->value('email'));

        // Another member sees neither.
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher);
        $this->get('/school/tasks')->assertOk()->assertDontSee(__('services.task_kinds.exports.generate'));
        $this->get('/school/notifications')->assertOk()->assertDontSee('Une tâche de fond a échoué');
        foreach (['imports', 'exports', 'deliveries'] as $page) $this->get('/school/'.$page)->assertForbidden();
    }

    public function test_an_email_not_sent_is_sent_again_by_whoever_manages_the_school(): void
    {
        [$school, $admin] = $this->setting();
        $notification = DB::connection('owner')->table('school.notification')->insertGetId(['tenant_id' => $school->id, 'user_id' => $admin['id'],
            'event' => 'job.failed', 'data' => '{}', 'created_at' => now()]);
        $delivery = DB::connection('owner')->table('school.outbox')->insertGetId(['tenant_id' => $school->id, 'user_id' => $admin['id'],
            'notification_id' => $notification, 'event' => 'job.failed', 'status' => 'failed', 'attempts' => 5, 'last_error' => 'SMTP 550 boîte inconnue',
            'next_attempt_at' => now(), 'created_at' => now()]);
        $this->get('/school/deliveries')->assertOk()->assertSee('SMTP 550 boîte inconnue');
        $this->within($school, $admin, fn () => Livewire::test(Deliveries::class)->callTableAction('resend', $delivery)->assertNotified(__('school.saved')));
        $this->assertSame('pending', DB::connection('owner')->table('school.outbox')->where('id', $delivery)->value('status'));
    }

    public function test_an_operator_under_consent_follows_the_imports_but_starts_nothing(): void
    {
        [$school, $admin] = $this->setting();
        $operator = $this->account('operator');
        DB::connection('owner')->insert('INSERT INTO platform.operator (user_id) VALUES (?)', [$operator['id']]);
        $grant = $this->postJson('/api/support-grants', ['hours' => 2, 'reason' => 'Assistance import'])->assertCreated()->json('data.id');
        $this->deleteJson('/api/session');
        $this->actingAs($this->person($operator));
        $this->withSession(['auth.started_at' => time(), 'auth.seen_at' => time()]);
        $this->postJson('/api/support/session', ['tenant_id' => $school->id, 'grant_id' => $grant])->assertNoContent();
        $this->get('/school/imports')->assertOk()->assertDontSee(__('services.new_import'));
        $this->get('/school/tasks')->assertOk();
        $this->get('/school/exports')->assertForbidden();
    }
}
