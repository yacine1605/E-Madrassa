<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The prospects' import (FR-IMP-01, FR-CRM-01, SQL 043): prospects from the school's file, through
 * the import engine's preview, confirmation and undo, under the rules the screens apply.
 */
final class LeadImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    /** @param list<array<string, ?string>> $rows a CSV file as a spreadsheet saves it */
    private function import(array $rows): array
    {
        $lines = [implode(';', array_keys($rows[0])), ...array_map(fn (array $row) => implode(';', array_map(fn ($value) => (string) $value, $row)), $rows)];
        $batch = $this->post('/api/imports', ['kind' => 'leads', 'file' => UploadedFile::fake()->createWithContent('leads.csv', "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n")],
            ['Accept' => 'application/json'])->assertStatus(202)->json('data.batch.id');
        return $this->getJson("/api/imports/$batch")->assertOk()->assertJsonPath('data.status', 'previewed')->json('data');
    }

    private function confirmed(array $batch): array
    {
        $this->postJson('/api/imports/'.$batch['id'].'/confirm')->assertStatus(202);
        return $this->getJson('/api/imports/'.$batch['id'])->assertOk()->assertJsonPath('data.status', 'imported')->json('data');
    }

    private function file(string $level): array
    {
        $row = fn (array $values) => [...['external_ref' => '', 'last_name' => '', 'first_name' => '', 'phone' => '', 'email' => '', 'relationship' => '',
            'source' => '', 'site' => '', 'child_first_name' => '', 'child_last_name' => '', 'child_birth_date' => '', 'child_level' => '', 'duplicate_reason' => ''], ...$values];
        return [
            $row(['external_ref' => 'SALON-001', 'last_name' => 'Haddad', 'first_name' => 'Samia', 'phone' => '0661 22 33 44', 'relationship' => 'mother',
                'source' => 'Social_Media', 'site' => 'ANNEXE', 'child_first_name' => 'Yanis', 'child_birth_date' => '10/02/2014', 'child_level' => mb_strtolower($level)]),
            $row(['external_ref' => 'SALON-002', 'last_name' => 'Kaci', 'email' => 'Kaci@Example.test', 'source' => 'poster']),
            // Refused: the same phone as a pupil's, without a reason; an unknown source; nobody to call back.
            $row(['external_ref' => 'SALON-003', 'last_name' => 'Benali', 'phone' => '0550 12 34 56', 'source' => 'other']),
            $row(['external_ref' => 'SALON-004', 'last_name' => 'Radio', 'phone' => '0770 00 00 01', 'source' => 'radio']),
            $row(['external_ref' => 'SALON-005', 'last_name' => 'Sans', 'source' => 'other']),
        ];
    }

    public function test_prospects_come_from_their_file_once_and_go_back_while_nothing_depends_on_them(): void
    {
        $desk = $this->account('lead-import');
        $school = $this->school('essential', $desk, 'private_school_dz');
        $this->member($school, $desk, 'active', ['front_office']);
        $this->signIn($desk)->assertOk();
        $this->postJson('/api/sites', ['code' => 'ANNEXE', 'labels' => ['fr' => 'Annexe']])->assertCreated();
        $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '1995-01-01', 'phone' => '0550 12 34 56'])->assertCreated();
        $level = DB::connection('owner')->selectOne("SELECT code FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' ORDER BY code LIMIT 1", [$school->id])->code;
        $this->assertStringStartsWith("\xEF\xBB\xBFexternal_ref;last_name;first_name;phone;email;relationship;source;site;child_first_name",
            $this->get('/api/imports/templates/leads')->assertOk()->getContent());
        $preview = $this->import($this->file($level));
        $this->assertSame(2, $preview['counts']['create']);
        $refused = array_map(fn (array $row) => [$row['line'], $row['error_code']], $this->getJson('/api/imports/'.$preview['id'].'/rows?outcome=rejected')->json('data'));
        $this->assertEqualsCanonicalizing([[4, 'lead_duplicate'], [5, 'lead_option_unknown'], [6, 'invalid']], $refused);
        $this->assertSame(2, $this->confirmed($preview)['counts']['created']);
        $owner = DB::connection('owner');
        $first = $owner->table('school.lead')->where('tenant_id', $school->id)->where('external_ref', 'SALON-001')->first();
        $this->assertEquals(['social_media', 'new', $owner->table('school.site')->where('tenant_id', $school->id)->where('code', 'ANNEXE')->value('id')],
            [$first->source, $first->status, $first->site_id]);
        $this->assertEquals(['Yanis', '2014-02-10'], [$owner->table('school.lead_child')->where('lead_id', $first->id)->value('first_name'),
            $owner->table('school.lead_child')->where('lead_id', $first->id)->value('birth_date')]);
        $this->assertSame('kaci@example.test', $owner->table('school.lead')->where('tenant_id', $school->id)->where('external_ref', 'SALON-002')->value('email'));
        // The same file again changes nothing (BR-05); a changed cell updates that prospect only.
        $this->assertSame(2, $this->import($this->file($level))['counts']['unchanged']);
        $file = $this->file($level);
        $file[1]['phone'] = '0662 00 11 22';
        $this->assertSame(1, $this->confirmed($this->import($file))['counts']['updated']);
        // Undone last first, while nothing has been built on them: an interaction keeps its prospect.
        $batches = collect($this->getJson('/api/imports')->json('data'))->where('status', 'imported')->values();
        $this->postJson('/api/imports/'.$batches[0]['id'].'/rollback')->assertOk()->assertJsonPath('data.counts.undone', 1);
        $this->postJson("/api/leads/{$first->id}/interactions", ['kind' => 'call', 'body' => 'Rappel après le salon'])->assertCreated();
        $this->postJson('/api/imports/'.$batches[1]['id'].'/rollback')->assertConflict()->assertJsonPath('code', 'dependent_operation');
        $this->assertSame(2, $owner->table('school.lead')->where('tenant_id', $school->id)->count());
    }
}
