<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** Files and attachments through the API (FR-FIC-01). */
final class FileTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    /** @return array{0: object, 1: array, 2: array} a school, its accountant (finance files) and its administrator (no finance permission) */
    private function school_with_accountant(): array
    {
        $school = $this->school();
        [$accountant, $admin] = [$this->account('accountant'), $this->account('admin')];
        $this->member($school, $accountant, 'active', ['accountant']);
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->signIn($accountant);
        return [$school, $accountant, $admin];
    }

    private function upload(string $name, string $contents, string $category = 'finance_attachment')
    {
        return $this->post('/api/files', ['category' => $category, 'file' => UploadedFile::fake()->createWithContent($name, $contents)],
            ['Accept' => 'application/json']);
    }

    private function docx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->close();
        return file_get_contents($path);
    }

    public function test_a_file_is_stored_under_its_school_and_comes_back_as_it_was(): void
    {
        [$school] = $this->school_with_accountant();
        $file = $this->upload('../../Facture <mars> 2026.pdf', self::PDF)->assertCreated()
            ->assertJsonPath('data.file_type', 'pdf')->assertJsonPath('data.original_name', 'Facture_mars_2026.pdf')
            ->assertJsonPath('data.size_bytes', strlen(self::PDF))->assertJsonPath('data.sha256', hash('sha256', self::PDF))->json('data.id');
        $key = DB::connection('owner')->table('school.file_object')->where('id', $file)->value('storage_key');
        $this->assertStringStartsWith("tenant/{$school->id}/finance_attachment/", $key);
        Storage::disk('files')->assertExists($key);
        $response = $this->get("/api/files/$file/content")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(self::PDF, $response->streamedContent());
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_the_content_decides_the_type_never_the_name_alone(): void
    {
        $this->school_with_accountant();
        // FR-FIC-01 AC1: an executable renamed .pdf, an image named .pdf.
        $this->upload('facture.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff")->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload('facture.pdf', "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR")->assertUnprocessable();
        $this->upload('contrat.docx', $this->docx())->assertCreated()->assertJsonPath('data.file_type', 'docx');
        $this->upload('liste.csv', "nom;prénom\nBenali;Ahmed\n")->assertStatus(409)->assertJsonPath('code', 'file_type_refused');
        // A category the accountant may not add to.
        $this->upload('photo.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00", 'student_photo')->assertForbidden();
    }

    public function test_a_financial_attachment_stays_out_of_reach_without_a_finance_permission(): void
    {
        [, , $admin] = $this->school_with_accountant();
        $file = $this->upload('recu.pdf', self::PDF)->json('data.id');
        // FR-FIC-01 AC2: the administrator, without finance permission, gets nothing, even knowing the address.
        $this->signIn($admin);
        $this->getJson("/api/files/$file")->assertNotFound();
        $this->get("/api/files/$file/content")->assertNotFound();
        $this->postJson("/api/files/$file/link")->assertNotFound();
    }

    public function test_a_signed_link_opens_the_file_without_a_session_for_fifteen_minutes(): void
    {
        $this->school_with_accountant();
        $file = $this->upload('recu.pdf', self::PDF)->json('data.id');
        $url = $this->postJson("/api/files/$file/link")->assertOk()->assertJsonPath('data.expires_in', 900)->json('data.url');
        $this->deleteJson('/api/session');
        $this->assertSame(self::PDF, $this->get($url)->assertOk()->streamedContent());
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature=0000', $url))->assertForbidden();
        // FR-FIC-01 AC3.
        $this->travel(16)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_types_size_and_quota_are_the_schools(): void
    {
        [$school, $accountant, $admin] = $this->school_with_accountant();
        $this->signIn($admin);
        $this->putJson('/api/settings/files.types', ['value' => ['pdf', 'jpeg']])->assertOk();
        $this->putJson('/api/settings/files.types', ['value' => ['pdf', 'exe']])->assertStatus(409)->assertJsonPath('code', 'setting_invalid');
        $this->signIn($accountant);
        $this->upload('contrat.docx', $this->docx())->assertStatus(409)->assertJsonPath('code', 'file_type_refused');
        // FR-PLT-02: a school of 1 MB fits one file of 700 KB, not two.
        $this->console('SELECT platform.set_entitlement(?, current_date, NULL, 15, 500, 1, 0)', [$school->id]);
        $big = self::PDF.str_repeat('0', 700 * 1024);
        $this->upload('a.pdf', $big)->assertCreated();
        $this->upload('b.pdf', $big)->assertStatus(409)->assertJsonPath('code', 'storage_quota_exceeded');
    }

    public function test_expired_temporary_files_are_purged_from_storage(): void
    {
        $this->school_with_accountant();
        $file = $this->upload('recu.pdf', self::PDF)->json('data.id');
        $key = DB::connection('owner')->table('school.file_object')->where('id', $file)->value('storage_key');
        DB::connection('owner')->update("UPDATE school.file_object SET expires_at = now() - interval '1 hour' WHERE id = ?", [$file]);
        $this->artisan('platform:purge-files')->assertSuccessful();
        Storage::disk('files')->assertMissing($key);
        $this->getJson("/api/files/$file")->assertNotFound();
        $this->assertNotNull(DB::connection('owner')->table('school.file_object')->where('id', $file)->value('purged_at'));
    }
}
