<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\PayrollExport;
use Tests\TestCase;
use ZipArchive;

/** Exports in CSV, Excel and PDF (FR-IMP-02). */
final class ExportTest extends TestCase
{
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

    private function content(string $file): string
    {
        return $this->get("/api/files/$file/content")->assertOk()->streamedContent();
    }

    private function xlsxRows(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $contents);
        $reader = new Reader();
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) $rows[] = $row->toArray();
            break;
        }
        $reader->close();
        return $rows;
    }

    public function test_a_csv_export_neutralises_formulas_is_logged_and_kept_seven_days(): void
    {
        $this->administered();
        $this->postJson('/api/accounts', ['name' => '=CMD()|calc', 'username' => 'formule', 'method' => 'password'])->assertCreated();
        $export = $this->postJson('/api/exports', ['kind' => 'accounts', 'format' => 'csv', 'locale' => 'fr'])->assertCreated()
            ->assertJsonPath('data.rows', 2)->assertJsonPath('data.columns', ['name', 'email', 'username', 'external_ref', 'status', 'activated_at'])->json('data');
        $csv = $this->content($export['file']['id']);
        $this->assertStringStartsWith("\xEF\xBB\xBFNom;E-mail;Identifiant;Référence;État;\"Activé le\"\r\n", $csv);
        // FR-IMP-02 AC2: the formula arrives as text.
        $this->assertStringContainsString("'=CMD()|calc;", $csv);
        $this->assertStringNotContainsString("\n=CMD()", $csv);
        $this->getJson('/api/exports')->assertOk()->assertJsonPath('data.0.kind', 'accounts')->assertJsonPath('data.0.row_count', 2)
            ->assertJsonPath('data.0.format', 'csv');
        $expires = DB::connection('owner')->table('school.file_object')->where('id', $export['file']['id'])->value('expires_at');
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), strtotime($expires), 60);
    }

    public function test_an_excel_export_in_arabic_reads_right_to_left_and_its_formulas_are_text(): void
    {
        $this->administered();
        $this->postJson('/api/accounts', ['name' => '=1+1', 'username' => 'formule', 'method' => 'password'])->assertCreated();
        $file = $this->postJson('/api/exports', ['kind' => 'referential', 'format' => 'xlsx', 'locale' => 'ar'])->assertCreated()
            ->assertJsonPath('data.rows', 33)->json('data.file.id');
        $contents = $this->content($file);
        $rows = $this->xlsxRows($contents);
        $this->assertSame(['النوع', 'الرمز', 'التسمية (بالفرنسية)', 'التسمية (بالعربية)', 'الأصل', 'مؤرشف'], $rows[0]);
        $this->assertSame(['الطور', 'PRESCOLAIRE', 'Préscolaire', 'التعليم التحضيري', '', 'لا'], array_map(fn ($cell) => $cell ?? '', $rows[1]));
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $contents);
        $zip = new ZipArchive();
        $zip->open($path);
        $this->assertMatchesRegularExpression('/rightToLeft="(1|true)"/', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $accounts = $this->xlsxRows($this->content($this->postJson('/api/exports', ['kind' => 'accounts', 'format' => 'xlsx'])->json('data.file.id')));
        $this->assertContains("'=1+1", array_column($accounts, 0));
    }

    public function test_a_pdf_export_lists_the_referential_in_arabic(): void
    {
        $this->administered();
        $file = $this->postJson('/api/exports', ['kind' => 'referential', 'format' => 'pdf', 'locale' => 'ar', 'filters' => ['type' => 'cycle']])
            ->assertCreated()->assertJsonPath('data.rows', 4)->json('data.file.id');
        $pdf = $this->content($file);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $text = (string) shell_exec('pdftotext -enc UTF-8 '.escapeshellarg($path).' -');
        $this->assertStringContainsString('SECONDAIRE', $text);
        $this->assertMatchesRegularExpression('/[\x{FE70}-\x{FEFC}]/u', $text);
    }

    public function test_a_column_follows_its_own_permission(): void
    {
        [$school, $admin] = $this->administered();
        config(['platform.exports' => config('platform.exports') + ['tests.payroll' => PayrollExport::class]]);
        // FR-IMP-02 AC1: the secretary's export carries no salary column, nor the email they cannot read.
        $clerk = $this->account('clerk');
        $this->member($school, $clerk, 'active', ['front_office']);
        $this->signIn($clerk);
        $this->postJson('/api/exports', ['kind' => 'tests.payroll', 'format' => 'csv'])->assertCreated()->assertJsonPath('data.columns', ['name']);
        // The administrator reads accounts, not salaries.
        $this->signIn($admin);
        $this->postJson('/api/exports', ['kind' => 'tests.payroll', 'format' => 'csv'])->assertCreated()->assertJsonPath('data.columns', ['name', 'email']);
    }

    public function test_an_export_needs_the_permission_of_its_data(): void
    {
        [$school] = $this->administered();
        [$clerk, $teacher] = [$this->account('clerk'), $this->account('teacher')];
        $this->member($school, $clerk, 'active', ['front_office']);
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($clerk);
        $this->postJson('/api/exports', ['kind' => 'accounts', 'format' => 'csv'])->assertForbidden()->assertJsonPath('code', 'permission_denied');
        $this->signIn($teacher);
        $this->postJson('/api/exports', ['kind' => 'referential', 'format' => 'csv'])->assertForbidden();
    }

    public function test_a_large_export_runs_in_the_background_and_its_author_is_told(): void
    {
        $this->administered();
        config(['platform.export_background_rows' => 10]);
        $job = $this->postJson('/api/exports', ['kind' => 'referential', 'format' => 'csv', 'idempotency_key' => 'export-1'])
            ->assertStatus(202)->assertJsonPath('data.job.kind', 'exports.generate')->json('data.job.id');
        $result = $this->getJson("/api/jobs/$job")->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.rows', 33)->json('data.result');
        $this->assertStringContainsString('PRESCOLAIRE', $this->content($result['file_id']));
        $this->getJson('/api/notifications')->assertJsonPath('data.0.event', 'job.succeeded');
    }

    public function test_an_expired_school_still_exports(): void
    {
        [$school] = $this->administered();
        $this->console('SELECT platform.set_entitlement(?, ?::date, ?::date, 15, 500, 1024, 0)', [$school->id, '2025-01-01', '2025-06-30']);
        $this->postJson('/api/exports', ['kind' => 'referential', 'format' => 'csv'])->assertCreated();
    }
}
