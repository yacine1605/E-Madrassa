<?php

namespace Tests\Feature;

use App\Support\CurrentSchool;
use App\Support\Money;
use App\Support\Pdf;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** FR-PDF-01, TST-17: documents in Arabic and French, read back with poppler's pdftotext and pdffonts. */
final class PdfTest extends TestCase
{
    private function receipt(string $locale, array $lines, string $payer, string $title): string
    {
        $school = $this->school();
        $admin = $this->account('admin');
        $this->member($school, $admin);
        DB::connection('owner')->insert("INSERT INTO school.setting (tenant_id, key, value) VALUES (?, 'identity.address', ?::jsonb), (?, 'identity.phone', '\"023 45 67 89\"')",
            [$school->id, json_encode('حي النصر، الجزائر'), $school->id]);
        return DB::transaction(function () use ($school, $admin, $locale, $lines, $payer, $title) {
            app(CurrentSchool::class)->enter($school->id, $admin['id'], 'pdf-test-0001');
            return app(Pdf::class)->render('receipt', ['payer' => $payer, 'date' => '11/10/2026', 'lines' => $lines], $locale, 'REC-2026-000123', $title);
        });
    }

    /** The text as a reader sees it, without the invisible direction marks and with plain spaces. */
    private function text(string $pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, $pdf);
        $text = (string) shell_exec('pdftotext -enc UTF-8 '.escapeshellarg($file).' - 2>&1');
        $this->assertNotSame('', trim($text), 'pdftotext (poppler-utils) is required to read the documents back.');
        return str_replace("\u{00A0}", ' ', preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text));
    }

    private function fonts(string $pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, $pdf);
        return (string) shell_exec('pdffonts '.escapeshellarg($file).' 2>&1');
    }

    public function test_an_arabic_receipt_joins_its_letters_and_keeps_its_amounts_in_order(): void
    {
        $pdf = $this->receipt('ar', [['label' => 'حقوق التسجيل', 'amount' => 500000], ['label' => 'الدفعة الأولى من الرسوم الدراسية', 'amount' => 1250050]],
            'أحمد بن علي', 'وصل استلام');
        $this->assertStringStartsWith('%PDF-', $pdf);
        $text = $this->text($pdf);
        // FR-PDF-01 AC1: letters drawn in their joined forms (Arabic presentation forms), numbers left to right in the Arabic line.
        $this->assertMatchesRegularExpression('/[\x{FE70}-\x{FEFC}]/u', $text);
        foreach (['5 000,00', '12 500,50', '17 500,50', 'REC-2026-000123', '023 45 67 89', '1/1'] as $expected) $this->assertStringContainsString($expected, $text);
        $this->assertStringNotContainsString('500,50 12', $text);
        // Fonts for Arabic and Latin, embedded in the document.
        $fonts = $this->fonts($pdf);
        $this->assertMatchesRegularExpression('/XBRiyaz\s+CID TrueType\s+Identity-H\s+yes/', $fonts);
        $this->assertMatchesRegularExpression('/DejaVuSans\S*\s+CID TrueType\s+Identity-H\s+yes/', $fonts);
    }

    public function test_a_french_receipt_and_the_school_header(): void
    {
        $pdf = $this->receipt('fr', [['label' => 'Frais d’inscription', 'amount' => 500000], ['label' => 'Premier versement', 'amount' => 1250050]],
            'Ahmed Benali', 'Reçu de paiement');
        $text = $this->text($pdf);
        foreach (['Reçu de paiement', 'Reçu n° : REC-2026-000123', '17 500,50 DA', 'École test-', 'Page 1/1', 'Référence : REC-2026-000123'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function test_amounts_are_exact_centimes(): void
    {
        $this->assertSame("1\u{00A0}234\u{00A0}567,05\u{00A0}DA", Money::format(123456705, 'fr'));
        $this->assertSame("-0,99\u{00A0}DA", Money::format(-99, 'fr'));
        $this->assertSame("\u{2066}12\u{00A0}500,50\u{2069}\u{00A0}د.ج", Money::format(1250050, 'ar'));
    }
}
