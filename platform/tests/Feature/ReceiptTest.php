<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Receipts (FR-FIN-05, SQL 051) through the real routes, read back with poppler's pdftotext: one
 * number per receipt, its content frozen, a duplicate printed afterwards, in the school's receipt
 * language, Arabic letters joined and amounts in order (TST-17).
 */
final class ReceiptTest extends TestCase
{
    private function text(string $pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, $pdf);
        $text = (string) shell_exec('pdftotext -enc UTF-8 '.escapeshellarg($file).' - 2>&1');
        $this->assertNotSame('', trim($text), 'pdftotext (poppler-utils) is required to read the documents back.');
        return str_replace("\u{00A0}", ' ', preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text));
    }

    public function test_a_validated_payment_has_one_receipt_frozen_and_its_duplicates(): void
    {
        $manager = $this->account('receipts-manager');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        $year = $this->postJson('/api/years', ['code' => Str::upper(Str::random(8)), 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'current'])->assertCreated()->json('data.id');
        $node = DB::connection('owner')->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $program = $this->postJson('/api/programs', ['year_id' => $year, 'node_id' => $node, 'code' => 'REC-1', 'labels' => ['fr' => 'Recette', 'ar' => 'وصولات'],
            'base_price' => '100000', 'status' => 'active'])->assertCreated()->json('data.id');
        $pupil = $this->postJson('/api/students', ['last_name_lat' => 'Hamidi', 'first_name_lat' => 'Sara', 'last_name_ar' => 'حميدي', 'first_name_ar' => 'سارة',
            'birth_date' => '1998-02-01'])->assertCreated()->json('data.id');
        $enrollment = $this->postJson('/api/enrollments', ['student_id' => $pupil, 'program_id' => $program, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $accountant = $this->account('accountant');
        $this->member($school, $accountant, 'active', ['accountant']);
        $validator = $this->account('validator');
        $this->member($school, $validator, 'active', ['accountant']);
        $pay = function (string $amount, string $method, ?string $ref = null) use ($accountant, $validator, $enrollment) {
            $this->signIn($accountant)->assertOk();
            $payment = $this->postJson("/api/enrollments/$enrollment/payments", ['amount' => $amount, 'paid_on' => now()->toDateString(), 'method_code' => $method,
                'external_ref' => $ref, 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
            $this->getJson("/api/payments/{$payment['id']}/receipt")->assertConflict()->assertJsonPath('code', 'receipt_not_validated');
            $this->signIn($validator)->assertOk();
            $this->postJson("/api/payments/{$payment['id']}/validate", ['version' => $payment['version']])->assertOk();
            return $payment['id'];
        };

        $cheque = $pay('30000', 'cheque', 'CHQ 0012345');
        $this->signIn($accountant)->assertOk();
        // The enrolment's Finance screen links it.
        $this->get('/school/enrollment-finance?enrollment='.$enrollment)->assertOk()->assertSee(__('finance.open_receipt'))
            ->assertSee('/api/payments/'.$cheque.'/receipt', false);
        $original = $this->get("/api/payments/$cheque/receipt")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $text = $this->text($original->getContent());
        preg_match('/REC-\d{4}-\d{6}/', $text, $number);
        $this->assertNotEmpty($number);
        foreach (['Reçu de paiement', 'Hamidi Sara', 'REC-1 · Recette', 'CHQ 0012345', 'chèque', '30 000,00 DA', 'Reste à payer après cette opération', '70 000,00 DA'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertStringNotContainsString('DUPLICATA', $text);
        // Printed again: the same receipt, its content of then, marked as a copy.
        $pay('20000', 'cash');
        $this->signIn($accountant)->assertOk();
        $copy = $this->text($this->get("/api/payments/$cheque/receipt")->assertOk()->getContent());
        $this->assertStringContainsString('DUPLICATA', $copy);
        $this->assertStringContainsString($number[0], $copy);
        $this->assertStringContainsString('70 000,00 DA', $copy);

        // In Arabic when the school says so: letters joined, amounts left to right (TST-17).
        DB::connection('owner')->insert("INSERT INTO school.setting (tenant_id, key, value) VALUES (?, 'finance.receipt.locale', '\"ar\"'),
            (?, 'identity.address', '\"12, rue des Oliviers, Alger\"')", [$school->id, $school->id]);
        $cash = $pay('10000', 'cash');
        $this->signIn($accountant)->assertOk();
        $arabic = $this->text($this->get("/api/payments/$cash/receipt")->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/[\x{FE70}-\x{FEFC}]/u', $arabic);
        foreach (['10 000,00', '40 000,00', 'REC-'] as $expected) $this->assertStringContainsString($expected, $arabic);
        $this->assertStringNotContainsString('000,00 10', $arabic);
        // A Latin address, name and matricule, each whole within the Arabic lines.
        foreach (['12, rue des Oliviers, Alger', 'Hamidi Sara'] as $expected) $this->assertStringContainsString($expected, $arabic);
        // The Latin name, then its matricule (pdftotext mirrors the bracket that opens the run).
        $this->assertMatchesRegularExpression('/Hamidi Sara \(\d{6,}/', $arabic);
        // The receipt of the payment of 20,000, never opened, is not issued.
        $this->assertSame(2, DB::connection('owner')->table('school.receipt')->where('tenant_id', $school->id)->distinct()->count('number'));
        // Whoever reads no payment prints no receipt.
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $this->signIn($teacher)->assertOk();
        $this->get("/api/payments/$cheque/receipt")->assertForbidden();
    }
}
