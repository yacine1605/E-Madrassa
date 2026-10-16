<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Settings;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Access to courses (FR-INS-04, OD-05, SQL 039) through the real routes and the settings' screen:
 * computed when an enrolment is read, from its state, its payments and the school's setting.
 */
final class AccessPolicyTest extends TestCase
{
    /** @return array{0: object, 1: array, 2: callable} a school, its administrator and head of studies signed in, and how to enrol a validated adult */
    private function setting(): array
    {
        $manager = $this->account('access');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $site = DB::connection('owner')->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        $validated = function (string $price) use ($year, $node, $site) {
            $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => Str::upper(Str::random(8)), 'labels' => ['fr' => 'Cours'],
                'base_price' => $price, 'status' => 'active'])->assertCreated()->json('data.id');
            $group = $this->postJson('/api/groups', ['year_id' => $year, 'program_id' => $program, 'site_id' => $site, 'code' => Str::upper(Str::random(8)),
                'labels' => ['fr' => 'Classe'], 'group_type' => 'class', 'capacity' => 10, 'valid_from' => now()->toDateString(), 'status' => 'active'])
                ->assertCreated()->json('data.id');
            $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Acces', 'first_name_lat' => Str::random(8), 'birth_date' => '1995-01-01'])
                ->assertCreated()->json('data.id');
            $enrollment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])
                ->assertCreated()->assertJsonPath('data.access_granted', false)->json('data');
            $this->postJson("/api/groups/$group/memberships", ['enrollment_id' => $enrollment['id']])->assertCreated();
            return $this->postJson("/api/enrollments/{$enrollment['id']}/validate", ['version' => $enrollment['version'], 'reason' => 'Dossier complet'])
                ->assertOk()->assertJsonPath('data.access_granted', true)->json('data');
        };
        return [$school, $manager, $validated];
    }

    public function test_a_validated_enrolment_opens_its_courses_and_a_required_payment_closes_them_until_paid(): void
    {
        [, , $validated] = $this->setting();
        $paying = $validated('120000');
        $free = $validated('0');
        $this->getJson('/api/settings')->assertOk()->assertJsonFragment(['key' => 'enrollment.access.require_payment', 'value_type' => 'code',
            'allowed_values' => ['none', 'first_installment', 'full'], 'value' => 'none']);
        // AC1: the first instalment asked for, none validated (payments come with lot 3); a free programme owes nothing.
        $this->putJson('/api/settings/enrollment.access.require_payment', ['value' => 'first_installment'])->assertOk()->assertJsonPath('data.value', 'first_installment');
        $this->getJson("/api/enrollments/{$paying['id']}")->assertOk()->assertJsonPath('data.access_granted', false);
        $this->getJson("/api/enrollments/{$free['id']}")->assertOk()->assertJsonPath('data.access_granted', true);
        $this->putJson('/api/settings/enrollment.access.require_payment', ['value' => 'half'])->assertConflict()->assertJsonPath('code', 'setting_invalid');
        $this->putJson('/api/settings/enrollment.access.require_payment', ['value' => 'none'])->assertOk();
        $this->getJson("/api/enrollments/{$paying['id']}")->assertOk()->assertJsonPath('data.access_granted', true);
        // A suspended enrolment opens nothing (FR-INS-03).
        $this->postJson("/api/enrollments/{$paying['id']}/suspend", ['version' => $paying['version'], 'reason' => 'Absence prolongée'])->assertOk()
            ->assertJsonPath('data.access_granted', false);
    }

    public function test_the_settings_page_offers_the_policys_choices(): void
    {
        [$school, $manager] = $this->setting();
        $this->get('/school/settings')->assertOk()->assertSee('Paiement exigé pour l’accès aux cours')->assertSee('Aucun : l’inscription validée suffit');
        $this->actingAs($this->person($manager));
        $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($school, $manager) {
            app(CurrentSchool::class)->enter($school->id, $manager['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            Livewire::test(Settings::class)->callTableAction('edit', 'enrollment.access.require_payment', data: ['value' => 'full'])
                ->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
            $this->assertSame('"full"', DB::table('school.setting')->where('key', 'enrollment.access.require_payment')->value('value'));
            Livewire::test(Settings::class)->assertSee('La totalité');
        });
    }
}
