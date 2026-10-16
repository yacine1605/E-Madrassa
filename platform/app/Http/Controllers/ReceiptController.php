<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use App\Support\Formats;
use App\Support\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-FIN-05: the receipt of a validated payment or an executed refund, as a PDF (FR-PDF-01). The
 * first download issues it: its number and content are frozen by SQL 051; every later one prints
 * the same receipt, with the school's identity and time of then, marked « duplicata ». In the
 * school's receipt language, fixed when issued.
 */
final class ReceiptController
{
    public function __construct(private CurrentSchool $school, private Pdf $pdf) {}

    public function payment(string $payment)
    {
        return $this->receipt($payment, null);
    }

    public function refund(string $refund)
    {
        return $this->receipt(null, $refund);
    }

    private function receipt(?string $payment, ?string $refund)
    {
        abort_unless(Str::isUuid((string) ($payment ?? $refund)), 404);
        $issued = DB::selectOne('SELECT id, issued FROM school.issue_receipt(?, ?)', [$payment, $refund]);
        $row = DB::selectOne('SELECT number, locale, content, issued_at FROM school.receipt WHERE tenant_id = ? AND id = ?', [$this->school->id(), $issued->id]);
        abort_unless($row, 404);
        $content = json_decode($row->content, true);
        $locale = $row->locale;
        $duplicate = !$issued->issued;
        $pdf = $this->pdf->render('finance-receipt', [...$this->lines($content, $locale), 'duplicate' => $duplicate], $locale, $row->number,
            __($content['kind'] === 'refund' ? 'pdf.refund_receipt' : 'pdf.payment_receipt', [], $locale), 'A5', [
                'identity' => array_map(fn ($value) => (string) $value, $content['school']),
                'generated' => Carbon::parse($row->issued_at),
                ...($duplicate ? ['watermark' => __('pdf.duplicate', [], $locale)] : []),
            ]);
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$row->number.'.pdf"',
            'Cache-Control' => 'private, no-store']);
    }

    /** What the receipt prints, from its frozen content, in its language: amounts in centimes for App\Support\Money. */
    private function lines(array $content, string $locale): array
    {
        $centimes = fn (string $amount) => (int) str_replace('.', '', $amount);
        $pupil = $content['pupil'];
        $latin = trim(($pupil['last_name_lat'] ?? '').' '.($pupil['first_name_lat'] ?? ''));
        $arabic = trim(($pupil['last_name_ar'] ?? '').' '.($pupil['first_name_ar'] ?? ''));
        $labels = $content['program']['labels'] ?? [];
        $left = $centimes($content['balance_after']);
        $digits = (bool) DB::selectOne("SELECT (school.setting_value('locale.arabic_digits'))::boolean AS d")->d;
        return [
            'pupil' => implode(' · ', array_filter($locale === 'ar' ? [$arabic, $latin] : [$latin, $arabic])),
            'matricule' => $pupil['matricule'] ?? null,
            'program' => $content['program']['code'].' · '.($labels[$locale] ?? $labels['fr'] ?? $labels['ar'] ?? ''),
            'date' => Formats::date($content['date'], $locale, $digits),
            'line' => __($content['kind'] === 'refund' ? 'pdf.refund_line' : 'pdf.payment_line', [], $locale),
            'operation' => $content['reference'],
            'means' => implode(', ', array_filter([$content['method_code'] ? __('finance.methods.'.$content['method_code'], [], $locale) : null, $content['external_ref']])),
            'reason' => $content['reason'],
            'amount' => $centimes($content['amount']),
            // What is left to pay just after it; beyond what is owed, a credit.
            'after' => __($left < 0 ? 'pdf.credit_after' : 'pdf.balance_after', [], $locale),
            'left' => abs($left),
        ];
    }
}
