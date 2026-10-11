<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Mpdf\Mpdf;

/**
 * The common PDF service (FR-PDF-01): receipts, lists, timetables, records. Fonts embedded for
 * Arabic and Latin, Arabic letters joined and laid out right to left; the school's identity in
 * the header; the document's reference and generation time, given by the caller and printed
 * as they are, in the footer with the page numbers. Runs in the school's context.
 */
final class Pdf
{
    public function __construct(private CurrentSchool $school) {}

    /**
     * @param string $view a view under resources/views/pdf
     * @param string $reference the document's number, fixed by the caller when it is issued
     * @return string the PDF itself
     */
    public function render(string $view, array $data, string $locale, string $reference, string $title, string $format = 'A4'): string
    {
        $rtl = $locale === 'ar';
        if (!is_dir(storage_path('app/mpdf'))) mkdir(storage_path('app/mpdf'), 0700, true);
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => $format, 'default_font' => 'dejavusans', 'tempDir' => storage_path('app/mpdf'),
            'autoScriptToLang' => true, 'autoLangToFont' => true, 'margin_top' => 30, 'margin_bottom' => 18, 'margin_header' => 8, 'margin_footer' => 8]);
        $mpdf->SetDirectionality($rtl ? 'rtl' : 'ltr');
        $identity = $this->identity();
        $generated = now()->timezone('Africa/Algiers');
        $mpdf->SetTitle($title.' '.$reference);
        $mpdf->SetAuthor($identity['name']);
        $mpdf->SetCreator('Madrassa');
        $mpdf->SetSubject($reference);
        $mpdf->SetHTMLHeader(view('pdf.header', ['identity' => $identity, 'rtl' => $rtl])->render());
        $mpdf->SetHTMLFooter(view('pdf.footer', ['reference' => $reference, 'generated' => $generated, 'locale' => $locale, 'rtl' => $rtl])->render());
        $mpdf->WriteHTML(view('pdf.'.$view, [...$data, 'locale' => $locale, 'rtl' => $rtl, 'title' => $title, 'reference' => $reference])->render());
        return $mpdf->Output('', 'S');
    }

    /** @return array{name: string, address: string, phone: string, email: string} */
    private function identity(): array
    {
        $row = DB::selectOne("SELECT t.name, school.setting_value('identity.address') #>> '{}' AS address,
            school.setting_value('identity.phone') #>> '{}' AS phone, school.setting_value('identity.email') #>> '{}' AS email
            FROM platform.tenant t WHERE t.id = ?", [$this->school->id()]);
        return ['name' => $row->name, 'address' => (string) $row->address, 'phone' => (string) $row->phone, 'email' => (string) $row->email];
    }
}
