<?php

namespace Tests\Feature;

use App\Filament\School\Pages\LeadOptions;
use App\Filament\School\Pages\Leads;
use App\Filament\School\Pages\LeadSheet;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admissions (FR-CRM-01, FR-CRM-02, SQL 038) through their real routes and screens: a prospect
 * from its first call to its closure, its reminders notifying its assignee, its child converted
 * once, in one go, into a pupil with their guardian and enrolment.
 */
final class LeadTest extends TestCase
{
    /** @return array{0: object, 1: array, 2: string} a school, its front office signed in, its default site */
    private function setting(): array
    {
        $desk = $this->account('admissions');
        $school = $this->school('essential', $desk, 'private_school_dz');
        $this->member($school, $desk, 'active', ['front_office']);
        $this->signIn($desk)->assertOk();
        $site = DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        return [$school, $desk, $site];
    }

    private function lead(array $extra = []): array
    {
        return ['last_name' => 'Haddad', 'first_name' => 'Samia', 'phone' => '0661 22 33 44', 'relationship' => 'mother', 'source' => 'social_media',
            'children' => [['first_name' => 'Yanis', 'last_name' => 'Haddad', 'birth_date' => '2014-02-10']], ...$extra];
    }

    /** An active programme of the school, made by its head of studies. */
    private function program(object $school): string
    {
        $head = $this->account('studies');
        $this->member($school, $head, 'active', ['tenant_admin', 'academic_hr']);
        $this->signIn($head)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        return $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => Str::upper(Str::random(8)), 'labels' => ['fr' => 'Sciences'],
            'base_price' => '120000', 'status' => 'active'])->assertCreated()->json('data.id');
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

    public function test_a_prospect_moves_along_the_schools_steps_and_closes_with_a_reason(): void
    {
        [$school, $desk] = $this->setting();
        // The publisher's lists come with the module.
        $this->getJson('/api/lead-options?kind=source')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.code', 'word_of_mouth');
        $this->getJson('/api/lead-options?kind=status')->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('data.0.reserved', true);
        $lead = $this->postJson('/api/leads', $this->lead(['assigned_to' => $desk['id']]))->assertCreated()->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.children.0.first_name', 'Yanis')->assertJsonPath('data.assigned_name', 'admissions')->json('data');
        $this->postJson('/api/leads', $this->lead(['source' => 'radio', 'phone' => '0770 10 20 30']))->assertConflict()->assertJsonPath('code', 'lead_option_unknown');
        $this->postJson('/api/leads', ['last_name' => 'Sans', 'source' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        // The same phone again: the family is known; recorded anyway only with a reason, which the journal keeps.
        $this->postJson('/api/leads', $this->lead(['phone' => '0661223344']))->assertConflict()->assertJsonPath('code', 'lead_duplicate')
            ->assertJsonPath('duplicates.0.kind', 'lead')->assertJsonPath('duplicates.0.id', $lead['id']);
        $this->postJson('/api/leads', $this->lead(['duplicate_reason' => 'Le père appelle pour un autre enfant']))->assertCreated();
        $this->postJson('/api/leads/duplicates', ['phone' => '0661 22 33 44'])->assertOk()->assertJsonCount(2, 'data');
        // AC3: a prospect is no pupil, in no headcount.
        $this->getJson('/api/students')->assertOk()->assertJsonPath('meta.total', 0);

        // Assigned to a member who reads the site's prospects; the head of studies does, the accountant does not.
        $head = $this->account('head');
        $this->member($school, $head, 'active', ['academic_hr']);
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $ids = array_column($this->getJson('/api/lead-assignees')->assertOk()->json('data'), 'id');
        $this->assertContains($head['id'], $ids);
        $this->assertNotContains($accountant['id'], $ids);
        $this->putJson("/api/leads/{$lead['id']}", ['assigned_to' => $accountant['id'], 'version' => $lead['version']])->assertConflict()
            ->assertJsonPath('code', 'lead_assignee_invalid');
        $lead = $this->putJson("/api/leads/{$lead['id']}", ['assigned_to' => $head['id'], 'version' => 1])->assertOk()->json('data');
        $lead = $this->postJson("/api/leads/{$lead['id']}/status", ['status' => 'visit_scheduled', 'version' => $lead['version']])->assertOk()
            ->assertJsonPath('data.status', 'visit_scheduled')->json('data');
        $this->postJson("/api/leads/{$lead['id']}/status", ['status' => 'registered', 'version' => $lead['version']])->assertConflict()
            ->assertJsonPath('code', 'lead_not_converted');

        // Interactions, never changed; reminders, done or cancelled.
        $this->postJson("/api/leads/{$lead['id']}/interactions", ['kind' => 'visit', 'body' => 'Visite avec Yanis'])->assertCreated()
            ->assertJsonPath('data.author', 'admissions');
        $this->postJson("/api/leads/{$lead['id']}/interactions", ['kind' => 'call', 'occurred_at' => now()->addDay()->toIso8601String(), 'body' => 'Demain'])
            ->assertConflict()->assertJsonPath('code', 'interaction_future');
        $this->getJson("/api/leads/{$lead['id']}/interactions")->assertOk()->assertJsonPath('meta.total', 1);
        $reminder = $this->postJson("/api/leads/{$lead['id']}/reminders", ['due_at' => now()->addDays(2)->toIso8601String(), 'body' => 'Rappeler pour le dossier'])
            ->assertCreated()->assertJsonPath('data.status', 'planned')->json('data');
        $this->postJson("/api/leads/{$lead['id']}/reminders", ['due_at' => now()->subDay()->toIso8601String(), 'body' => 'Hier'])->assertConflict()
            ->assertJsonPath('code', 'reminder_past');
        $this->getJson("/api/leads/{$lead['id']}")->assertOk()->assertJsonPath('data.next_reminder', fn ($due) => $due !== null);

        // AC2: lost only with its reason; closed, it changes no more, and its reminders are cancelled; reopened with a reason.
        $this->postJson("/api/leads/{$lead['id']}/status", ['status' => 'lost', 'version' => $lead['version']])->assertConflict()
            ->assertJsonPath('code', 'lost_reason_required');
        $lead = $this->postJson("/api/leads/{$lead['id']}/status", ['status' => 'lost', 'lost_reason' => 'Inscrit dans une autre école', 'version' => $lead['version']])
            ->assertOk()->assertJsonPath('data.lost_reason', 'Inscrit dans une autre école')->json('data');
        $this->assertNotNull($lead['closed_at']);
        $this->getJson("/api/leads/{$lead['id']}/reminders")->assertOk()->assertJsonPath('data.0.id', $reminder['id'])->assertJsonPath('data.0.status', 'cancelled');
        $this->putJson("/api/leads/{$lead['id']}", ['phone' => '0770 00 00 00', 'version' => $lead['version']])->assertConflict()->assertJsonPath('code', 'lead_closed');
        $this->postJson("/api/leads/{$lead['id']}/children", ['first_name' => 'Lina'])->assertConflict()->assertJsonPath('code', 'lead_closed');
        $this->postJson("/api/leads/{$lead['id']}/status", ['status' => 'contacted', 'version' => $lead['version']])->assertConflict()
            ->assertJsonPath('code', 'reopen_reason_required');
        $this->postJson("/api/leads/{$lead['id']}/status", ['status' => 'contacted', 'reason' => 'La famille a rappelé', 'version' => $lead['version']])->assertOk()
            ->assertJsonPath('data.closed_at', null)->assertJsonPath('data.lost_reason', null);
        $this->assertSame('La famille a rappelé', DB::connection('owner')->table('school.audit_log')->where('object_type', 'lead')
            ->where('object_id', $lead['id'])->orderByDesc('occurred_at')->value('reason'));
        $this->getJson('/api/leads?open=1&mine=0')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/leads?q=yanis')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_a_due_reminder_notifies_the_prospects_assignee_once(): void
    {
        [$school] = $this->setting();
        $head = $this->account('head');
        $this->member($school, $head, 'active', ['academic_hr']);
        $lead = $this->postJson('/api/leads', $this->lead(['assigned_to' => $head['id']]))->assertCreated()->json('data');
        $this->postJson("/api/leads/{$lead['id']}/reminders", ['due_at' => now()->toIso8601String(), 'body' => 'Rappeler la famille'])->assertCreated();
        $this->artisan('platform:lead-reminders')->assertSuccessful();
        $this->artisan('platform:lead-reminders')->expectsOutput('Reminders notified: 0.')->assertSuccessful();
        // AC1: in the assignee's notifications, pointing to the prospect, without its name.
        $this->signIn($head)->assertOk();
        $notes = collect($this->getJson('/api/notifications')->assertOk()->json('data'))->where('event', 'crm.reminder_due')->values();
        $this->assertCount(1, $notes);
        $this->assertSame($lead['id'], $notes[0]['data']['lead_id']);
        $this->assertArrayNotHasKey('name', $notes[0]['data']);
    }

    public function test_a_child_is_converted_once_into_a_pupil_their_guardian_and_enrolment_or_nothing(): void
    {
        [$school, $desk] = $this->setting();
        $program = $this->program($school);
        $this->signIn($desk)->assertOk();
        $lead = $this->postJson('/api/leads', $this->lead())->assertCreated()->json('data');
        $this->postJson("/api/leads/{$lead['id']}/interactions", ['kind' => 'call', 'body' => 'Premier appel de la mère'])->assertCreated();
        $child = $lead['children'][0]['id'];
        $conversion = fn (string $key, array $extra = []) => $this->postJson("/api/lead-children/$child/convert", ['idempotency_key' => $key, 'program_id' => $program,
            'student' => ['last_name_lat' => 'Haddad', 'first_name_lat' => 'Yanis', 'birth_date' => '2014-02-10'],
            'guardian' => ['last_name_lat' => 'Haddad', 'first_name_lat' => 'Samia', 'phone' => '0661 22 33 44'], 'relationship' => 'mother', ...$extra]);
        // Nothing or all: a minor without their primary contact is no enrolment, and no pupil nor guardian stays.
        $conversion((string) Str::uuid(), ['guardian' => ['last_name_lat' => 'Haddad', 'first_name_lat' => 'Samia'], 'is_primary_contact' => true])
            ->assertConflict()->assertJsonPath('code', 'primary_contact_required');
        $this->assertSame(0, DB::connection('owner')->table('school.student')->where('tenant_id', $school->id)->count());
        $this->assertSame(0, DB::connection('owner')->table('school.guardian')->where('tenant_id', $school->id)->count());
        // AC1: the same conversion twice (a double click) makes one pupil; another one is refused.
        $key = (string) Str::uuid();
        $first = $conversion($key)->assertCreated()->assertJsonPath('data.lead.status', 'registered')->json('data');
        $again = $conversion($key)->assertOk()->json('data');
        $this->assertSame([$first['student_id'], $first['enrollment_id']], [$again['student_id'], $again['enrollment_id']]);
        $conversion((string) Str::uuid())->assertConflict()->assertJsonPath('code', 'lead_already_converted');
        $this->assertSame(1, DB::connection('owner')->table('school.student')->where('tenant_id', $school->id)->count());
        $this->getJson("/api/enrollments/{$first['enrollment_id']}")->assertOk()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.student_id', $first['student_id']);
        $this->getJson("/api/students/{$first['student_id']}/guardians")->assertOk()->assertJsonPath('data.0.relationship', 'mother')
            ->assertJsonPath('data.0.is_primary_contact', true);
        $this->getJson("/api/leads/{$lead['id']}")->assertOk()->assertJsonPath('data.children.0.student_id', $first['student_id']);
        // AC2: the interactions stay readable from the pupil's sheet.
        $this->getJson("/api/students/{$first['student_id']}/lead")->assertOk()->assertJsonPath('data.id', $lead['id'])
            ->assertJsonPath('interactions.0.body', 'Premier appel de la mère');
        // A sibling of a registered prospect is converted later, with the guardian already recorded.
        $this->postJson("/api/leads/{$lead['id']}/children", ['first_name' => 'Lina'])->assertConflict()->assertJsonPath('code', 'lead_closed');
        $guardian = $this->getJson("/api/students/{$first['student_id']}/guardians")->json('data.0.guardian_id');
        $this->postJson('/api/leads', $this->lead(['children' => [['first_name' => 'Lina', 'last_name' => 'Haddad']], 'duplicate_reason' => 'Deuxième enfant']))
            ->assertCreated();
        $other = DB::connection('owner')->table('school.lead_child')->where('tenant_id', $school->id)->where('first_name', 'Lina')->value('id');
        $this->postJson("/api/lead-children/$other/convert", ['idempotency_key' => (string) Str::uuid(), 'program_id' => $program, 'guardian_id' => $guardian,
            'relationship' => 'mother', 'student' => ['last_name_lat' => 'Haddad', 'first_name_lat' => 'Lina', 'birth_date' => '2016-09-01']])->assertCreated();
        $this->assertSame(1, DB::connection('owner')->table('school.guardian')->where('tenant_id', $school->id)->count());
    }

    /** NFR-DATA-03 (SQL 042): a prospect lost for longer than the school's duration keeps nothing personal. */
    public function test_a_prospect_lost_long_enough_is_anonymised_by_the_retention_run(): void
    {
        [$school] = $this->setting();
        $lead = $this->postJson('/api/leads', $this->lead())->assertCreated()->json('data');
        $this->postJson("/api/leads/{$lead['id']}/interactions", ['kind' => 'call', 'body' => 'La mère travaille à Oran'])->assertCreated();
        $lead = $this->postJson("/api/leads/{$lead['id']}/status", ['status' => 'lost', 'lost_reason' => 'Déménagement', 'version' => $lead['version']])->assertOk()->json('data');
        $this->getJson('/api/retention')->assertOk()->assertJsonPath('data.due.lead', 0)->assertJsonPath('data.durations.lead_months', 24);
        $owner = DB::connection('owner');
        $owner->unprepared('ALTER TABLE school.lead DISABLE TRIGGER USER');
        $owner->table('school.lead')->where('id', $lead['id'])->update(['closed_at' => now()->subMonths(25)]);
        $owner->unprepared('ALTER TABLE school.lead ENABLE TRIGGER USER');
        $this->getJson('/api/retention')->assertOk()->assertJsonPath('data.due.lead', 1);
        $this->artisan('platform:retention')->assertSuccessful()->expectsOutputToContain('1 lost prospects anonymised');
        $this->getJson("/api/leads/{$lead['id']}")->assertOk()->assertJsonPath('data.last_name', 'ANONYME')->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.lost_reason', 'ANONYME')->assertJsonPath('data.children.0.first_name', 'ANONYME')->assertJsonPath('data.children.0.birth_date', null);
        $this->getJson("/api/leads/{$lead['id']}/interactions")->assertOk()->assertJsonPath('data.0.body', 'ANONYME');
        $this->postJson("/api/leads/{$lead['id']}/status", ['status' => 'contacted', 'reason' => 'La famille revient', 'version' => $lead['version'] + 1])
            ->assertConflict()->assertJsonPath('code', 'lead_anonymised');
    }

    public function test_the_pages_record_a_prospect_follow_it_and_name_the_lists(): void
    {
        [$school, $desk, $site] = $this->setting();
        $this->get('/school/leads')->assertOk()->assertSee(__('admission.create_lead'));
        $this->get('/school/lead-options')->assertOk()->assertSee('word_of_mouth');
        $this->within($school, $desk, function () use ($site) {
            Livewire::test(Leads::class)->callTableAction('create', data: ['site_id' => $site, 'last_name' => 'Kaci', 'first_name' => 'Nadia',
                'phone' => '0550 44 55 66', 'relationship' => 'mother', 'source' => 'poster', 'children' => [['first_name' => 'Rayan', 'birth_date' => '2015-03-04']]])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $lead = DB::table('school.lead')->where('last_name', 'Kaci')->first();
            $this->assertSame(['poster', 'new'], [$lead->source, $lead->status]);
            $this->assertSame('Rayan', DB::table('school.lead_child')->where('lead_id', $lead->id)->value('first_name'));
            Livewire::test(Leads::class)->assertCanSeeTableRecords([$lead->id])->assertSee('Affichage');
            $sheet = fn () => Livewire::withQueryParams(['lead' => $lead->id])->test(LeadSheet::class);
            $sheet()->assertSee('Rayan')->callAction('interaction', data: ['kind' => 'visit', 'body' => 'Visite de l’école'])->assertNotified(__('school.saved'));
            $sheet()->assertSee('Visite de l’école')->callAction('status', data: ['status' => 'lost', 'lost_reason' => 'Trop loin'])->assertNotified(__('school.saved'));
            $this->assertSame(['lost', 'Trop loin'], [DB::table('school.lead')->where('id', $lead->id)->value('status'),
                DB::table('school.lead')->where('id', $lead->id)->value('lost_reason')]);
            Livewire::test(LeadOptions::class)->callTableAction('create', data: ['kind' => 'source', 'code' => 'salon',
                'labels' => ['fr' => 'Salon de l’étudiant', 'ar' => 'صالون الطالب'], 'sort_order' => '5'])->assertNotified(__('school.saved'));
            $this->assertTrue(DB::table('school.lead_option')->where('code', 'salon')->exists());
        });
    }
}
