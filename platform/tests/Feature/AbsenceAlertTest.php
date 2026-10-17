<?php

namespace Tests\Feature;

use App\Filament\School\Pages\AbsenceAlerts;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use App\Mail\GuardianAbsenceMail;
use App\Support\Formats;
use App\Support\WhatsApp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PlansSessions;
use Tests\TestCase;

/**
 * The absence thresholds (FR-PRE-01, SQL 073): a pupil absent without excuse as often as the
 * school's threshold within its window raises one alert, its group's followers notified; late
 * arrivals and excused absences do not count; no threshold, no alert. A minor's guardian is emailed
 * when the school turned it on (SQL 074), and sent its WhatsApp template (SQL 075); an adult's never.
 */
final class AbsenceAlertTest extends TestCase
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

    /** The school's setting, as the school would set it. */
    private function set(object $school, string $key, int|bool $value): void
    {
        $owner = DB::connection('owner');
        $owner->unprepared('ALTER TABLE school.setting DISABLE TRIGGER USER');
        $owner->delete('DELETE FROM school.setting WHERE tenant_id = ? AND key = ?', [$school->id, $key]);
        $owner->insert('INSERT INTO school.setting (tenant_id, key, value, created_at, updated_at) VALUES (?, ?, ?::jsonb, now(), now())', [$school->id, $key, json_encode($value)]);
        $owner->unprepared('ALTER TABLE school.setting ENABLE TRIGGER USER');
    }

    private function notified(array $account): int
    {
        return DB::connection('owner')->table('school.notification')->where('user_id', $account['id'])->where('event', 'attendance.absence_threshold')->count();
    }

    /**
     * Four sessions of the group, nine, six, three and one day ago, its pupils in it since before;
     * its teacher, who leads it, with an account, and a supervisor for the whole school.
     */
    private function sessions(array $s): array
    {
        $sessions = [];
        foreach ([9, 6, 3, 1] as $i => $ago) {
            $sessions[] = $this->postJson('/api/class-sessions', ['group_id' => $s['group'], 'subject_id' => $s['maths'], 'teacher_id' => $s['teacher'],
                'room_id' => $s['room'], 'day' => $this->next(Carbon::SUNDAY), 'start_time' => sprintf('%02d:00', 8 + $i), 'end_time' => sprintf('%02d:50', 8 + $i)])
                ->assertCreated()->json('data.id');
        }
        $owner = DB::connection('owner');
        $owner->unprepared('ALTER TABLE school.class_session DISABLE TRIGGER USER; ALTER TABLE school.group_membership DISABLE TRIGGER USER; ALTER TABLE school.study_group DISABLE TRIGGER USER');
        foreach ([9, 6, 3, 1] as $i => $ago) {
            $owner->table('school.class_session')->where('id', $sessions[$i])->update(['starts_at' => now()->subDays($ago)->subMinutes(60), 'ends_at' => now()->subDays($ago)->subMinutes(10)]);
        }
        $owner->table('school.group_membership')->where('group_id', $s['group'])->update(['valid_from' => now()->subDays(20)->toDateString()]);
        $owner->table('school.study_group')->where('id', $s['group'])->update(['lead_teacher_id' => $s['teacher']]);
        $owner->unprepared('ALTER TABLE school.class_session ENABLE TRIGGER USER; ALTER TABLE school.group_membership ENABLE TRIGGER USER; ALTER TABLE school.study_group ENABLE TRIGGER USER');
        $karim = $this->account('karim');
        $this->member($s['school'], $karim, 'active');
        $this->grant($s['school'], $karim, 'teacher', 'personal');
        $record = $this->getJson("/api/staff/{$s['teacher']}")->json('data');
        $this->postJson("/api/staff/{$s['teacher']}/account", ['user_id' => $karim['id'], 'version' => $record['version']])->assertOk();
        $nadia = $this->account('nadia');
        $this->member($s['school'], $nadia, 'active');
        $this->grant($s['school'], $nadia, 'supervisor');
        return [$sessions, $karim, $nadia];
    }

    public function test_a_pupil_reaching_the_threshold_raises_one_alert_and_its_followers_are_told(): void
    {
        $s = $this->setting();
        $pupils = [];
        foreach (['Amine', 'Lina'] as $name) {
            $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => $name, 'birth_date' => '1990-01-01'])->assertCreated()->json('data.id');
            $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])
                ->assertCreated()->json('data.id');
            $this->postJson("/api/groups/{$s['group']}/memberships", ['enrollment_id' => $enrolment])->assertCreated();
            $pupils[] = $pupil;
        }
        [$sessions, $karim, $nadia] = $this->sessions($s);
        $owner = DB::connection('owner');

        $this->signIn($karim)->assertOk();
        $call = fn (int $session, string $amine, string $lina) => $this->postJson("/api/class-sessions/{$sessions[$session]}/roll-call",
            ['entries' => [['student_id' => $pupils[0], 'status' => $amine], ['student_id' => $pupils[1], 'status' => $lina]]])->assertOk();
        // Three absences of Amine in 30 days, the default: one alert, when the third is saved. Lina late, then excused: none.
        $call(0, 'absent', 'late');
        $call(1, 'absent', 'excused');
        $this->assertSame(0, $owner->table('school.absence_alert')->count());
        $call(2, 'absent', 'excused');
        $alert = $owner->table('school.absence_alert')->sole();
        // Over the last 30 days, today's included.
        $today = Carbon::now('Africa/Algiers')->toDateString();
        $this->assertSame([$pupils[0], $s['group'], 3, 3, Carbon::parse($today)->subDays(29)->toDateString(), $today],
            [$alert->student_id, $alert->group_id, $alert->absences, $alert->threshold, Carbon::parse($alert->from_day)->toDateString(), Carbon::parse($alert->to_day)->toDateString()]);
        // Its followers told, the group's lead teacher and the supervisor; not the manager, who corrects no roll.
        $this->assertSame([1, 1, 0], [$this->notified($karim), $this->notified($nadia), $this->notified($s['manager'])]);
        $this->assertSame($alert->id, json_decode($owner->table('school.notification')->where('user_id', $nadia['id'])->where('event', 'attendance.absence_threshold')->value('data'), true)['alert']);
        // A fourth absence within the window: no second alert.
        $call(3, 'absent', 'present');
        $this->assertSame(1, $owner->table('school.absence_alert')->count());

        // No threshold: none, whatever the absences.
        $this->set($s['school'], 'attendance.absence_threshold', 0);
        foreach ([0, 1, 2] as $session) $call($session, 'absent', 'absent');
        $this->assertSame(1, $owner->table('school.absence_alert')->count());
        // Two in 30 days: the next mark changed to absent raises Lina's.
        $this->set($s['school'], 'attendance.absence_threshold', 2);
        $call(3, 'absent', 'absent');
        $this->assertSame(4, $owner->table('school.absence_alert')->where('student_id', $pupils[1])->value('absences'));

        // The lead teacher reads the alerts, the latest first, with the rule.
        $this->getJson('/api/absence-alerts')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.student_id', $pupils[1])
            ->assertJsonPath('data.0.readable', true)->assertJsonPath('data.1.first_name_lat', 'Amine');
        $this->getJson('/api/absence-alerts/rule')->assertOk()->assertJsonPath('data.threshold', 2)->assertJsonPath('data.window_days', 30);
        $this->within($s['school'], $karim, function () {
            Livewire::test(AbsenceAlerts::class)->assertSee('Benali Amine')->assertSee('3 absences (seuil 3)')->assertSee('2 fois au cours des 30 derniers jours', false);
        });
        // No longer leading the group: none of its alerts.
        $owner->unprepared('ALTER TABLE school.study_group DISABLE TRIGGER USER');
        $owner->table('school.study_group')->where('id', $s['group'])->update(['lead_teacher_id' => null]);
        $owner->unprepared('ALTER TABLE school.study_group ENABLE TRIGGER USER');
        $this->getJson('/api/absence-alerts')->assertOk()->assertJsonCount(0, 'data');
        // The supervisor reads them all; the manager has no such screen.
        $this->signIn($nadia)->assertOk();
        $this->getJson('/api/absence-alerts')->assertOk()->assertJsonCount(2, 'data');
        $this->get('/school/absence-alerts')->assertOk();
        $this->signIn($s['manager'])->assertOk();
        $this->getJson('/api/absence-alerts')->assertForbidden();
        $this->get('/school/absence-alerts')->assertForbidden();
    }

    public function test_a_minors_guardian_is_emailed_when_the_school_turned_it_on_an_adults_never(): void
    {
        Mail::fake();
        $s = $this->setting();
        DB::connection('owner')->insert("INSERT INTO school.setting (tenant_id, key, value, created_at, updated_at) VALUES (?, 'identity.phone', '\"023 45 67 89\"', now(), now())",
            [$s['school']->id]);
        // Yasmine, a minor, her mother her primary contact with an address; Amine, an adult, his father too.
        $pupils = [];
        foreach ([['Yasmine', now()->subYears(12)->toDateString(), 'mere.yasmine@example.test'], ['Amine', '1990-01-01', 'pere.amine@example.test']] as [$name, $born, $email]) {
            $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => $name, 'first_name_ar' => $name === 'Yasmine' ? 'ياسمين' : 'أمين',
                'last_name_ar' => 'بن علي', 'birth_date' => $born])->assertCreated()->json('data.id');
            $guardian = $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Parent '.$name, 'phone' => '0550123456', 'email' => $email])
                ->assertCreated()->json('data.id');
            $this->postJson("/api/students/$pupil/guardians", ['guardian_id' => $guardian, 'relationship' => 'mother', 'is_primary_contact' => true])->assertCreated();
            $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])
                ->assertCreated()->json('data.id');
            $this->postJson("/api/groups/{$s['group']}/memberships", ['enrollment_id' => $enrolment])->assertCreated();
            $pupils[] = $pupil;
        }
        [$sessions, $karim, $nadia] = $this->sessions($s);
        $this->set($s['school'], 'attendance.notify_guardians_email', true);
        $this->signIn($karim)->assertOk();
        foreach ([0, 1, 2] as $session) {
            $this->postJson("/api/class-sessions/{$sessions[$session]}/roll-call", ['entries' => [['student_id' => $pupils[0], 'status' => 'absent'],
                ['student_id' => $pupils[1], 'status' => 'absent']]])->assertOk();
        }
        // Two alerts; a message for the minor's guardian only, queued with it, by email.
        $owner = DB::connection('owner');
        $this->assertSame(2, $owner->table('school.absence_alert')->where('group_id', $s['group'])->count());
        $alerts = $owner->table('school.absence_alert')->where('group_id', $s['group'])->pluck('id');
        $message = $owner->table('school.guardian_message')->whereIn('alert_id', $alerts)->sole();
        $this->assertSame(['email', 'pending'], [$message->channel, $message->status]);
        $this->assertSame($pupils[0], $owner->table('school.absence_alert')->where('id', $message->alert_id)->value('student_id'));
        // The worker sends it: the first name, the absences, the window, the school to call; in its language, then Arabic.
        $this->artisan('platform:guardian-messages')->assertSuccessful();
        Mail::assertSent(GuardianAbsenceMail::class, function (GuardianAbsenceMail $mail) {
            $text = $mail->render();
            return $mail->hasTo('mere.yasmine@example.test') && str_contains($text, 'Yasmine a manqué 3 séances sans justification')
                && str_contains($text, 'سُجّل غياب ياسمين عن 3 حصص دون تبرير') && str_contains($text, '023 45 67 89') && !str_contains($text, 'Benali');
        });
        Mail::assertSentCount(1);
        $this->assertSame('sent', $owner->table('school.guardian_message')->where('id', $message->id)->value('status'));
        $this->artisan('platform:guardian-messages')->assertSuccessful();
        Mail::assertSentCount(1);
        // The alerts say who was told, and when.
        $this->within($s['school'], $karim, function () {
            Livewire::test(AbsenceAlerts::class)->assertSee(__('attendance.guardians_told'))->assertSee('E-mail envoyé le')->assertSee(__('attendance.no_message'));
        });
    }

    public function test_a_minors_guardian_gets_the_schools_whatsapp_template_once_its_account_is_set_up(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['messages' => [['id' => 'wamid.test']]])
            ->push(['error' => ['message' => 'Recipient 213550123456 is not on the allowed list']], 400)]);
        config(['services.whatsapp.token' => 'test-token', 'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.template' => 'absence_alert', 'services.whatsapp.version' => 'v21.0']);
        $s = $this->setting();
        // Two minors: Yasmine's mother on a mobile, Rania's father on a landline only.
        $pupils = $guardians = [];
        foreach ([['Yasmine', '0550 12 34 56'], ['Rania', '023 45 67 89']] as [$name, $phone]) {
            $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => $name, 'birth_date' => now()->subYears(12)->toDateString()])
                ->assertCreated()->json('data.id');
            $guardian = $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Parent '.$name, 'phone' => $phone])->assertCreated()->json('data.id');
            $this->postJson("/api/students/$pupil/guardians", ['guardian_id' => $guardian, 'relationship' => 'mother', 'is_primary_contact' => true])->assertCreated();
            $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $s['program'], 'idempotency_key' => (string) Str::uuid()])
                ->assertCreated()->json('data.id');
            $this->postJson("/api/groups/{$s['group']}/memberships", ['enrollment_id' => $enrolment])->assertCreated();
            [$pupils[], $guardians[]] = [$pupil, $guardian];
        }
        [$sessions, $karim] = $this->sessions($s);
        $this->set($s['school'], 'attendance.notify_guardians_whatsapp', true);
        $this->signIn($karim)->assertOk();
        foreach ([0, 1, 2] as $session) {
            $this->postJson("/api/class-sessions/{$sessions[$session]}/roll-call", ['entries' => [['student_id' => $pupils[0], 'status' => 'absent'],
                ['student_id' => $pupils[1], 'status' => 'absent']]])->assertOk();
        }
        // By WhatsApp only, as the school chose; the template sent once, to the mobile in international form.
        $owner = DB::connection('owner');
        $messages = $owner->table('school.guardian_message')->whereIn('guardian_id', $guardians)->orderBy('guardian_id')->get()->keyBy('guardian_id');
        $this->assertSame(['whatsapp', 'whatsapp'], $messages->pluck('channel')->values()->all());
        $this->artisan('platform:guardian-messages')->assertSuccessful();
        $alert = $owner->table('school.absence_alert')->where('student_id', $pupils[0])->first();
        $school = $owner->table('platform.tenant')->where('id', $s['school']->id)->value('name');
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v21.0/1234567890/messages' && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['to'] === '213550123456' && $request['type'] === 'template' && $request['template']['name'] === 'absence_alert'
            && $request['template']['language']['code'] === 'fr' && array_column($request['template']['components'][0]['parameters'], 'text')
                === ['Yasmine', '3', Formats::date($alert->from_day, 'fr'), Formats::date($alert->to_day, 'fr'), $school, '—']);
        Http::assertSentCount(1);
        // The landline: failed at once, never sent; and so would every message without an account.
        $this->assertSame([['sent', null], ['failed', 'guardian_phone_unusable']], [
            [$owner->table('school.guardian_message')->where('guardian_id', $guardians[0])->value('status'), $owner->table('school.guardian_message')->where('guardian_id', $guardians[0])->value('last_error')],
            [$owner->table('school.guardian_message')->where('guardian_id', $guardians[1])->value('status'), $owner->table('school.guardian_message')->where('guardian_id', $guardians[1])->value('last_error')]]);
        $this->assertSame(['213550123456', '213661223344', '33612345678', null, null],
            array_map([WhatsApp::class, 'number'], ['0550 12 34 56', '+213 661 22 33 44', '0033 6 12 34 56 78', '023 45 67 89', 'abc']));
        // Refused by the API: tried again later, its reason kept without the number.
        $owner->table('school.guardian_message')->where('guardian_id', $guardians[0])->update(['status' => 'pending', 'next_attempt_at' => now()->subMinute()]);
        $this->artisan('platform:guardian-messages')->assertSuccessful();
        $refused = $owner->table('school.guardian_message')->where('guardian_id', $guardians[0])->first();
        $this->assertSame('pending', $refused->status);
        $this->assertStringContainsString('RequestException', $refused->last_error);
        $this->assertStringNotContainsString('213550123456', $refused->last_error);
        $owner->table('school.guardian_message')->where('guardian_id', $guardians[0])->update(['status' => 'sent', 'last_error' => null]);
        $this->within($s['school'], $karim, function () {
            Livewire::test(AbsenceAlerts::class)->assertSee('WhatsApp envoyé le')->assertSee('WhatsApp en échec');
        });
    }
}
