<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Programmes and groups from the school's files (FR-IMP-01, FR-PRG-01, FR-GRP-01, SQL 045), through
 * the import engine's preview, confirmation and undo, under the rules the screens apply.
 */
final class ProgramGroupImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    /** @param list<array<string, ?string>> $rows */
    private function import(string $kind, array $rows): array
    {
        $lines = [implode(';', array_keys($rows[0])), ...array_map(fn (array $row) => implode(';', array_map(fn ($value) => (string) $value, $row)), $rows)];
        $batch = $this->post('/api/imports', ['kind' => $kind, 'file' => UploadedFile::fake()->createWithContent($kind.'.csv', "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n")],
            ['Accept' => 'application/json'])->assertStatus(202)->json('data.batch.id');
        return $this->getJson("/api/imports/$batch")->assertOk()->assertJsonPath('data.status', 'previewed')->json('data');
    }

    private function confirmed(array $batch): array
    {
        $this->postJson('/api/imports/'.$batch['id'].'/confirm')->assertStatus(202);
        return $this->getJson('/api/imports/'.$batch['id'])->assertOk()->assertJsonPath('data.status', 'imported')->json('data');
    }

    private function refused(array $batch): array
    {
        return array_map(fn (array $row) => [$row['line'], $row['error_code']], $this->getJson('/api/imports/'.$batch['id'].'/rows?outcome=rejected')->json('data'));
    }

    public function test_programmes_then_their_groups_come_from_files_once_and_go_back_last_first(): void
    {
        $manager = $this->account('catalogue');
        $school = $this->school('pro', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $owner = DB::connection('owner');
        $year = 'Y'.Str::upper(Str::random(6));
        $this->postJson('/api/years', ['code' => $year, 'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'current'])->assertCreated();
        $level = $owner->selectOne("SELECT code FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' ORDER BY code LIMIT 1", [$school->id])->code;
        $teacher = $this->postJson('/api/staff', ['last_name_lat' => 'Meziane', 'first_name_lat' => 'Karim', 'staff_type' => 'teacher_internal'])->assertCreated()->json('data');
        $site = $owner->table('school.site')->where('tenant_id', $school->id)->where('is_default', true)->value('id');
        $this->postJson('/api/rooms', ['site_id' => $site, 'code' => 'A101', 'labels' => ['fr' => 'Salle 101'], 'capacity' => 30, 'room_type' => 'CLASSROOM'])->assertCreated();

        $programs = fn (string $price = '120000,50') => [
            ['year' => $year, 'code' => 'sci-1', 'level' => mb_strtolower($level), 'label_fr' => 'Sciences', 'label_ar' => 'علوم', 'description' => '',
                'duration_value' => '9', 'duration_unit' => 'months', 'base_price' => $price, 'status' => 'active'],
            ['year' => $year, 'code' => 'LANG-1', 'level' => $level, 'label_fr' => 'Langues', 'label_ar' => '', 'description' => 'Anglais et français',
                'duration_value' => '', 'duration_unit' => '', 'base_price' => '', 'status' => ''],
            ['year' => $year, 'code' => 'X-1', 'level' => 'INCONNU', 'label_fr' => 'Inconnu', 'label_ar' => '', 'description' => '', 'duration_value' => '',
                'duration_unit' => '', 'base_price' => '', 'status' => ''],
        ];
        $preview = $this->import('programs', $programs());
        $this->assertSame([[4, 'reference_unknown']], $this->refused($preview));
        $this->assertSame(2, $this->confirmed($preview)['counts']['created']);
        $sciences = $owner->table('school.program')->where('tenant_id', $school->id)->where('code', 'SCI-1')->first();
        $this->assertEquals(['120000.50', 'active', 9, 'months'], [$sciences->base_price, $sciences->status, $sciences->duration_value, $sciences->duration_unit]);
        $this->assertSame('draft', $owner->table('school.program')->where('tenant_id', $school->id)->where('code', 'LANG-1')->value('status'));
        // The same file again changes nothing; a new price makes a new version.
        $this->assertSame(2, $this->import('programs', $programs())['counts']['unchanged']);
        $this->assertSame(1, $this->confirmed($this->import('programs', $programs('125000')))['counts']['updated']);
        $this->assertSame(2, (int) $owner->table('school.program')->where('id', $sciences->id)->value('price_version'));

        $groups = [
            ['year' => $year, 'code' => 'SCI-A', 'group_type' => 'class', 'program' => 'SCI-1', 'site' => '', 'label_fr' => 'Sciences A', 'label_ar' => 'علوم أ',
                'capacity' => '25', 'valid_from' => '', 'valid_to' => '', 'status' => 'active', 'lead_teacher' => $teacher['staff_number'], 'main_room' => 'A101'],
            ['year' => $year, 'code' => 'SOUTIEN', 'group_type' => 'support', 'program' => '', 'site' => '', 'label_fr' => 'Soutien', 'label_ar' => '',
                'capacity' => '10', 'valid_from' => '', 'valid_to' => '', 'status' => '', 'lead_teacher' => '', 'main_room' => ''],
            // Refused: a class without its programme; a room smaller than the class; an unknown programme.
            ['year' => $year, 'code' => 'SANS', 'group_type' => 'class', 'program' => '', 'site' => '', 'label_fr' => 'Sans programme', 'label_ar' => '',
                'capacity' => '20', 'valid_from' => '', 'valid_to' => '', 'status' => '', 'lead_teacher' => '', 'main_room' => ''],
            ['year' => $year, 'code' => 'GRANDE', 'group_type' => 'class', 'program' => 'SCI-1', 'site' => '', 'label_fr' => 'Grande classe', 'label_ar' => '',
                'capacity' => '40', 'valid_from' => '', 'valid_to' => '', 'status' => '', 'lead_teacher' => '', 'main_room' => 'A101'],
            ['year' => $year, 'code' => 'AUTRE', 'group_type' => 'class', 'program' => 'NOPE', 'site' => '', 'label_fr' => 'Autre', 'label_ar' => '',
                'capacity' => '20', 'valid_from' => '', 'valid_to' => '', 'status' => '', 'lead_teacher' => '', 'main_room' => ''],
        ];
        $preview = $this->import('groups', $groups);
        $this->assertEqualsCanonicalizing([[4, 'domain_conflict'], [5, 'room_too_small'], [6, 'reference_unknown']], $this->refused($preview));
        $this->assertSame(2, $this->confirmed($preview)['counts']['created']);
        $class = $owner->table('school.study_group')->where('tenant_id', $school->id)->where('code', 'SCI-A')->first();
        $this->assertEquals([$sciences->id, $teacher['id'], 25, 'active'], [$class->program_id, $class->lead_teacher_id, $class->capacity, $class->status]);
        $this->assertNotNull($class->main_room_id);
        $this->assertSame(2, $this->import('groups', $groups)['counts']['unchanged']);

        // Undone while nothing depends on them: the price first, then the programmes once their groups are gone.
        $batches = collect($this->getJson('/api/imports')->json('data'))->where('status', 'imported')->values();
        [$price, $created] = $batches->where('kind', 'programs')->values()->all();
        $this->postJson('/api/imports/'.$price['id'].'/rollback')->assertOk()->assertJsonPath('data.counts.undone', 1);
        $this->assertSame('120000.50', $owner->table('school.program')->where('id', $sciences->id)->value('base_price'));
        $this->postJson('/api/imports/'.$created['id'].'/rollback')->assertConflict()->assertJsonPath('code', 'dependent_operation');
        $this->postJson('/api/imports/'.$batches->firstWhere('kind', 'groups')['id'].'/rollback')->assertOk()->assertJsonPath('data.counts.undone', 2);
        $this->assertSame(0, $owner->table('school.study_group')->where('tenant_id', $school->id)->whereIn('code', ['SCI-A', 'SOUTIEN'])->count());
        $this->postJson('/api/imports/'.$created['id'].'/rollback')->assertOk()->assertJsonPath('data.counts.undone', 2);
        $this->assertSame(0, $owner->table('school.program')->where('tenant_id', $school->id)->whereIn('code', ['SCI-1', 'LANG-1'])->count());
        $this->assertSame(0, $owner->table('school.program_price')->where('program_id', $sciences->id)->count());
    }
}
