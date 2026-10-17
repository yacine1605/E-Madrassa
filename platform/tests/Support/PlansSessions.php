<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A school ready to plan sessions (SQL 061, 062), for the planning's tests. */
trait PlansSessions
{
    /** @return array<string, mixed> a school whose pedagogy and HR manager is signed in, with a class, its programme's subject, a qualified teacher and a room */
    private function setting(string $plan = 'pro'): array
    {
        $manager = $this->account('planning');
        $school = $this->school($plan, $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $owner = DB::connection('owner');
        $site = $owner->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        $node = $owner->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' ORDER BY code LIMIT 1", [$school->id])->id;
        [$maths, $physics] = $owner->table('school.subject')->where('tenant_id', $school->id)->whereNull('archived_at')->orderBy('code')->limit(2)->pluck('id')->all();
        $year = $this->postJson('/api/years', ['code' => 'PLAN-'.Str::upper(Str::random(4)), 'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(9)->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => '4AM', 'labels' => ['fr' => '4e année moyenne'],
            'base_price' => '120000', 'status' => 'active'])->assertCreated()->json('data.id');
        $this->postJson("/api/programs/$program/subjects", ['subject_id' => $maths, 'weekly_hours' => '3', 'total_hours' => '96'])->assertCreated();
        $group = $this->postJson('/api/groups', ['year_id' => $year, 'program_id' => $program, 'site_id' => $site, 'code' => '4AM-1', 'labels' => ['fr' => '4AM 1'],
            'group_type' => 'class', 'capacity' => 25, 'valid_from' => now()->subMonth()->toDateString(), 'status' => 'active'])->assertCreated()->json('data.id');
        $teacher = $this->postJson('/api/staff', ['last_name_lat' => 'Meziane', 'first_name_lat' => 'Karim', 'staff_type' => 'teacher_internal'])->assertCreated()->json('data.id');
        $this->postJson("/api/staff/$teacher/qualifications", ['subject_id' => $maths, 'node_id' => $node])->assertCreated();
        $room = $this->postJson('/api/rooms', ['site_id' => $site, 'code' => 'A101', 'labels' => ['fr' => 'Salle 101'], 'capacity' => 30, 'room_type' => 'CLASSROOM'])
            ->assertCreated()->json('data.id');
        return compact('school', 'manager', 'site', 'node', 'maths', 'physics', 'year', 'program', 'group', 'teacher', 'room');
    }

    /** The next day of the week (0 Sunday … 6 Saturday) at least `$after` days from today. */
    private function next(int $weekday, int $after = 7): string
    {
        $day = now()->addDays($after);
        while ($day->dayOfWeek !== $weekday) $day = $day->addDay();
        return $day->toDateString();
    }
}
