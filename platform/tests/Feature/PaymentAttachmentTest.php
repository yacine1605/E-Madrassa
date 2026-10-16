<?php

namespace Tests\Feature;

use App\Filament\School\Pages\EnrollmentFinance;
use App\Filament\School\Pages\PaymentValidations;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A payment's supporting document (FR-FIN-02, FR-FIC-01, SQL 054) through the real routes and
 * screens: sent with the payment or added by its author while it waits, replaced with the former
 * one archived, frozen once validated (TST-11), read with the payment and never by a teacher (AC2).
 */
final class PaymentAttachmentTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";

    public function test_a_payment_carries_its_document_while_it_waits_and_keeps_it_once_validated(): void
    {
        Storage::fake('files');
        $manager = $this->account('documents-manager');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addYear()->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => 'JUS-1', 'labels' => ['fr' => 'Justificatifs'], 'base_price' => '90000',
            'status' => 'active'])->assertCreated()->json('data.id');
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Kaci', 'first_name_lat' => 'Nadir', 'birth_date' => '1996-06-01'])->assertCreated()->json('data.id');
        $enrollment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $validator = $this->account('validator');
        $this->member($school, $validator, 'active', ['accountant']);
        $director = $this->account('director');
        $this->member($school, $director, 'active', ['direction']);
        $document = fn (string $name, string $contents = self::PDF) => UploadedFile::fake()->createWithContent($name, $contents);
        $json = ['Accept' => 'application/json'];

        // Sent with the payment; the same key again keeps the first document.
        $this->signIn($accountant)->assertOk();
        $key = (string) Str::uuid();
        $fields = ['amount' => '30000', 'paid_on' => now()->toDateString(), 'method_code' => 'cheque', 'external_ref' => 'CHQ 4410', 'idempotency_key' => $key];
        $paid = $this->post("/api/enrollments/$enrollment/payments", [...$fields, 'attachment' => $document('cheque.pdf')], $json)->assertCreated()->json('data');
        $this->assertNotNull($paid['attachment_file_id']);
        $this->post("/api/enrollments/$enrollment/payments", [...$fields, 'attachment' => $document('autre.pdf')], $json)->assertCreated()
            ->assertJsonPath('data.attachment_file_id', $paid['attachment_file_id']);
        $this->get("/api/payments/{$paid['id']}/attachment")->assertOk()->assertDownload('cheque.pdf');
        // FR-FIC-01 AC1: an executable named .pdf is refused.
        $this->post("/api/payments/{$paid['id']}/attachment", ['version' => $paid['version'], 'attachment' => $document('cheque.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00")], $json)
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        // Replaced while it waits: the former one archived, kept.
        $replaced = $this->post("/api/payments/{$paid['id']}/attachment", ['version' => $paid['version'], 'attachment' => $document('cheque-recto.pdf')], $json)
            ->assertOk()->json('data');
        $this->assertNotSame($paid['attachment_file_id'], $replaced['attachment_file_id']);
        $former = DB::connection('owner')->selectOne('SELECT archived_at, purged_at FROM school.file_object WHERE id = ?', [$paid['attachment_file_id']]);
        $this->assertNotNull($former->archived_at);
        $this->assertNull($former->purged_at);

        // Someone else than its author adds none; once validated, it never changes (TST-11).
        $this->signIn($validator)->assertOk();
        $this->post("/api/payments/{$paid['id']}/attachment", ['version' => $replaced['version'], 'attachment' => $document('x.pdf')], $json)
            ->assertConflict()->assertJsonPath('code', 'payment_not_author');
        $this->get("/api/payments/{$paid['id']}/attachment")->assertOk()->assertDownload('cheque-recto.pdf');
        $this->postJson("/api/payments/{$paid['id']}/validate", ['version' => $replaced['version']])->assertOk();
        $this->signIn($accountant)->assertOk();
        $this->post("/api/payments/{$paid['id']}/attachment", ['version' => $replaced['version'] + 1, 'attachment' => $document('y.pdf')], $json)
            ->assertConflict()->assertJsonPath('code', 'payment_immutable');
        $this->signIn($director)->assertOk();
        $this->get("/api/payments/{$paid['id']}/attachment")->assertOk();

        // AC2: a teacher reads none of it, neither by the payment nor by the file.
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher)->assertOk();
        $this->get("/api/payments/{$paid['id']}/attachment")->assertForbidden();
        $this->get("/api/files/{$replaced['attachment_file_id']}/content")->assertNotFound();

        // On the screens: recorded with its document, added afterwards, opened by the validator.
        $within = function (array $account, callable $test) use ($school) {
            $this->actingAs($this->person($account));
            $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
            Filament::setCurrentPanel(Filament::getPanel('school'));
            DB::transaction(function () use ($school, $account, $test) {
                app(CurrentSchool::class)->enter($school->id, $account['id'], CurrentSchool::requestId(null));
                app(CurrentSchool::class)->setMode('active');
                $test();
            });
        };
        $sheet = fn () => Livewire::withQueryParams(['enrollment' => $enrollment])->test(EnrollmentFinance::class);
        $within($accountant, function () use ($sheet, $document) {
            $sheet()->callAction('pay', data: ['amount' => '10 000', 'method_code' => 'transfer', 'attachment' => $document('virement.pdf')])
                ->assertHasNoFormErrors()->assertNotified(__('school.saved'));
            $sheet()->callAction('pay', data: ['amount' => '5 000', 'method_code' => 'cash'])->assertHasNoFormErrors()->assertNotified(__('school.saved'));
        });
        $plain = DB::connection('owner')->table('school.payment')->where('enrollment_id', $enrollment)->where('amount', '5000')->first();
        $this->assertNull($plain->attachment_file_id);
        $this->assertNotNull(DB::connection('owner')->table('school.payment')->where('enrollment_id', $enrollment)->where('amount', '10000')->value('attachment_file_id'));
        $within($accountant, function () use ($sheet, $document, $plain) {
            $sheet()->callAction('attach_document', data: ['payment' => $plain->id, 'attachment' => $document('versement.pdf')])
                ->assertHasNoFormErrors()->assertNotified(__('school.saved'))->assertSee(__('finance.open_attachment'));
        });
        $this->assertNotNull(DB::connection('owner')->table('school.payment')->where('id', $plain->id)->value('attachment_file_id'));
        $within($validator, function () use ($plain) {
            Livewire::test(PaymentValidations::class)->assertCanSeeTableRecords([$plain->id])->assertTableActionVisible('document', $plain->id);
        });
    }
}
