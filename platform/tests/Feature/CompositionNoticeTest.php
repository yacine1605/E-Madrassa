<?php

namespace Tests\Feature;

use App\Filament\School\Pages\Notifications;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A group's composition (FR-GRP-02, SQL 041): a pupil joining or leaving a group notifies its lead
 * teacher, by the group only, in their bell, from which they open the group.
 */
final class CompositionNoticeTest extends TestCase
{
    public function test_a_pupil_joining_or_leaving_notifies_the_groups_lead_teacher(): void
    {
        $manager = $this->account('composition');
        $school = $this->school('pro', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'planned'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $site = DB::connection('owner')->selectOne('SELECT id FROM school.site WHERE tenant_id = ? AND is_default', [$school->id])->id;
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => Str::upper(Str::random(8)), 'labels' => ['fr' => 'Sciences'],
            'base_price' => '120000', 'status' => 'active'])->assertCreated()->json('data.id');
        $record = $this->postJson('/api/staff', ['last_name_lat' => 'Meziane', 'first_name_lat' => 'Karim', 'staff_type' => 'teacher_internal'])->assertCreated()->json('data');
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active');
        $this->grant($school, $teacher, 'teacher', 'personal');
        $this->postJson("/api/staff/{$record['id']}/account", ['user_id' => $teacher['id'], 'version' => $record['version']])->assertOk();
        $group = $this->postJson('/api/groups', ['year_id' => $year, 'program_id' => $program, 'site_id' => $site, 'code' => Str::upper(Str::random(8)),
            'labels' => ['fr' => 'Classe'], 'group_type' => 'class', 'capacity' => 25, 'valid_from' => now()->toDateString(), 'status' => 'active',
            'lead_teacher_id' => $record['id']])->assertCreated()->json('data.id');
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '1990-01-01'])->assertCreated()->json('data.id');
        $enrolment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        $membership = $this->postJson("/api/groups/$group/memberships", ['enrollment_id' => $enrolment])->assertCreated()->json('data');
        $this->postJson("/api/group-memberships/{$membership['id']}/end", ['version' => $membership['version'], 'reason' => 'Départ de la famille'])->assertOk();

        // In the teacher's bell: the group, what changed, no pupil's name (BR-19); nothing in the manager's, who made the changes.
        $this->assertSame(0, DB::connection('owner')->table('school.notification')->where('user_id', $manager['id'])->where('event', 'group.membership_changed')->count());
        $this->signIn($teacher)->assertOk();
        $notes = collect($this->getJson('/api/notifications')->assertOk()->json('data'))->where('event', 'group.membership_changed')->values();
        $this->assertEqualsCanonicalizing([['group_id' => $group, 'change' => 'joined'], ['group_id' => $group, 'change' => 'left']], $notes->pluck('data')->all());
        $this->actingAs($this->person($teacher));
        $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
        Filament::setCurrentPanel(Filament::getPanel('school'));
        DB::transaction(function () use ($school, $teacher) {
            app(CurrentSchool::class)->enter($school->id, $teacher['id'], CurrentSchool::requestId(null));
            app(CurrentSchool::class)->setMode('active');
            Livewire::test(Notifications::class)->assertSee('La composition d’un de vos groupes a changé')->assertSee(__('services.see_group'));
        });
    }
}
