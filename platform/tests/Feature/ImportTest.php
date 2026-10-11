<?php

namespace Tests\Feature;

use App\Mail\AccountLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** Imports from CSV and Excel files (FR-IMP-01, TST-14). */
final class ImportTest extends TestCase
{
    private const REFERENTIAL = "type;code;parent_code;label_fr;label_ar\r\n"
        ."grade;CP;PRIMAIRE;Cours préparatoire;القسم التحضيري للابتدائي\r\n"
        ."track;1AS_SPORT;1AS;Sport-études;رياضة ودراسة\r\n"
        ."track;2AS_X;9AS;Inconnu;مجهول\r\n"
        ."grade;CP;PRIMAIRE;Doublon;مكرر\r\n"
        ."grade;bad code;PRIMAIRE;Code invalide;رمز خاطئ\r\n"
        ."grade;1AP;PRIMAIRE;1re année primaire;السنة الأولى ابتدائي\r\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    /** @return array{0: object, 1: array} a school with the private school profile, its administrator signed in */
    private function administered(): array
    {
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin, 'private_school_dz');
        $this->member($school, $admin);
        $this->signIn($admin)->assertOk();
        return [$school, $admin];
    }

    private function upload(string $kind, string $name, string $contents, array $options = [])
    {
        return $this->post('/api/imports', ['kind' => $kind, 'file' => UploadedFile::fake()->createWithContent($name, $contents), 'options' => $options],
            ['Accept' => 'application/json']);
    }

    private function nodes(object $school): int
    {
        return DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)->whereNull('archived_at')->count();
    }

    public function test_the_template_names_the_columns(): void
    {
        $this->administered();
        $this->assertSame("\xEF\xBB\xBFexternal_ref;name;email;username\r\n", $this->get('/api/imports/templates/accounts')->assertOk()->getContent());
        $this->get('/api/imports/templates/referential?format=xlsx')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_the_preview_writes_nothing_and_names_each_refused_line_with_its_cause(): void
    {
        [$school] = $this->administered();
        $before = $this->nodes($school);
        $batch = $this->upload('referential', 'referentiel.csv', self::REFERENTIAL)->assertStatus(202)->json('data.batch.id');
        // FR-IMP-01 AC3: the preview applied every row, then rolled them back.
        $this->getJson("/api/imports/$batch")->assertOk()->assertJsonPath('data.status', 'previewed')
            ->assertJsonPath('data.counts.total', 6)->assertJsonPath('data.counts.valid', 3)->assertJsonPath('data.counts.rejected', 3)
            ->assertJsonPath('data.counts.create', 2)->assertJsonPath('data.counts.unchanged', 1);
        $this->assertSame($before, $this->nodes($school));
        // AC2: the line and the cause.
        $rejected = $this->getJson("/api/imports/$batch/rows?outcome=rejected")->assertOk()->json('data');
        $this->assertSame([[4, 'reference_unknown'], [5, 'duplicate_in_file'], [6, 'invalid']], array_map(fn ($row) => [$row['line'], $row['error_code']], $rejected));
        $this->assertSame('Parent inconnu dans le référentiel : 9AS.', $rejected[0]['error_message']);
        $report = $this->get("/api/imports/$batch/report")->assertOk()->getContent();
        $this->assertStringContainsString("4;track:2AS_X;reference_unknown;\"Parent inconnu dans le référentiel : 9AS.\"", $report);
    }

    public function test_a_confirmed_import_applies_its_valid_rows_once_and_imported_again_duplicates_nothing(): void
    {
        [$school] = $this->administered();
        $before = $this->nodes($school);
        $batch = $this->upload('referential', 'referentiel.csv', self::REFERENTIAL)->json('data.batch.id');
        $this->postJson("/api/imports/$batch/confirm")->assertStatus(202)->assertJsonPath('data.job.kind', 'imports.run');
        $this->getJson("/api/imports/$batch")->assertJsonPath('data.status', 'imported')->assertJsonPath('data.counts.created', 2)
            ->assertJsonPath('data.counts.unchanged', 1)->assertJsonPath('data.counts.rejected', 3);
        $this->assertSame($before + 2, $this->nodes($school));
        $this->postJson("/api/imports/$batch/confirm")->assertStatus(409)->assertJsonPath('code', 'not_previewed');

        // TST-14: the same file again changes nothing.
        $again = $this->upload('referential', 'referentiel.csv', self::REFERENTIAL)->json('data.batch.id');
        $this->getJson("/api/imports/$again")->assertJsonPath('data.counts.create', 0)->assertJsonPath('data.counts.unchanged', 3);
        $this->postJson("/api/imports/$again/confirm")->assertStatus(202);
        $this->assertSame($before + 2, $this->nodes($school));
    }

    public function test_an_import_is_undone_whole_or_not_at_all(): void
    {
        [$school] = $this->administered();
        $before = $this->nodes($school);
        $batch = $this->upload('referential', 'referentiel.csv', self::REFERENTIAL)->json('data.batch.id');
        $this->postJson("/api/imports/$batch/confirm")->assertStatus(202);
        // Something now depends on an imported level: the import stays.
        $cp = DB::connection('owner')->table('school.ref_node')->where('tenant_id', $school->id)->where('code', 'CP')->value('id');
        $child = $this->postJson('/api/referential/nodes', ['type' => 'track', 'parent_id' => $cp, 'code' => 'CP_BIL', 'labels' => ['fr' => 'Bilingue']])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/imports/$batch/rollback")->assertStatus(409)->assertJsonPath('code', 'dependent_operation')->assertJsonPath('line', 2);
        $this->assertSame($before + 3, $this->nodes($school));
        $this->getJson("/api/imports/$batch")->assertJsonPath('data.status', 'imported');
        // Once that is gone, the import is undone.
        $this->postJson("/api/referential/nodes/$child/archive", ['reason' => 'Erreur'])->assertOk();
        $this->postJson("/api/imports/$batch/rollback")->assertOk()->assertJsonPath('data.status', 'rolled_back')->assertJsonPath('data.counts.undone', 2);
        $this->assertSame($before, $this->nodes($school));
    }

    public function test_accounts_from_an_excel_file_are_invited_and_undone_while_unused(): void
    {
        Mail::fake();
        [$school] = $this->administered();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $writer = new Writer();
        $writer->openToFile($path);
        foreach ([['external_ref', 'name', 'email', 'username'], ['P-1', 'Parent Un', 'parent.un@example.test', null],
                  ['P-2', 'Parent Deux', null, 'parent.deux'], ['P-3', 'Parent Trois', 'pas-un-email', null]] as $values) {
            $writer->addRow(Row::fromValues($values));
        }
        $writer->close();
        $batch = $this->upload('accounts', 'parents.xlsx', file_get_contents($path), ['invite' => true])->assertStatus(202)->json('data.batch.id');
        $this->getJson("/api/imports/$batch")->assertJsonPath('data.counts.valid', 2)->assertJsonPath('data.counts.rejected', 1);
        Mail::assertNothingSent();
        $this->postJson("/api/imports/$batch/confirm")->assertStatus(202);
        $this->getJson("/api/imports/$batch")->assertJsonPath('data.counts.created', 2);
        Mail::assertSent(AccountLink::class, fn (AccountLink $mail) => $mail->hasTo('parent.un@example.test'));
        $this->assertSame(1, DB::connection('owner')->table('school.membership')->where('tenant_id', $school->id)->where('external_ref', 'P-2')->count());
        $this->postJson("/api/imports/$batch/rollback")->assertOk();
        $this->assertSame(0, DB::connection('owner')->table('school.membership')->where('tenant_id', $school->id)->whereIn('external_ref', ['P-1', 'P-2'])->count());
        $this->assertSame(0, DB::connection('owner')->table('users')->where('email', 'parent.un@example.test')->count());
    }

    public function test_a_file_without_its_columns_fails_with_a_clear_message(): void
    {
        $this->administered();
        $batch = $this->upload('referential', 'referentiel.csv', "type;label_fr\r\ngrade;Niveau\r\n")->json('data.batch.id');
        $this->getJson("/api/imports/$batch")->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_message', 'Colonnes manquantes : code. Partez du modèle de l’import.');
    }

    public function test_an_import_needs_the_permission_that_creates_its_records(): void
    {
        [$school] = $this->administered();
        [$clerk, $teacher] = [$this->account('clerk'), $this->account('teacher')];
        $this->member($school, $clerk, 'active', ['front_office']);
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($clerk);
        $this->upload('referential', 'referentiel.csv', self::REFERENTIAL)->assertForbidden()->assertJsonPath('code', 'permission_denied');
        $this->signIn($teacher);
        $this->upload('referential', 'referentiel.csv', self::REFERENTIAL)->assertForbidden();
    }
}
