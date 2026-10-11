<table width="100%" style="border-top: 0.5pt solid #555; font-size: 8pt; color: #444;" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    <tr>
        <td>{{ __('pdf.reference', [], $locale) }} : <span dir="ltr">{{ $reference }}</span> · {{ __('pdf.generated', [], $locale) }} <span dir="ltr">{{ $generated }}</span></td>
        <td style="text-align: {{ $rtl ? 'left' : 'right' }};">{{ __('pdf.page', [], $locale) }} <span dir="ltr">{PAGENO}/{nbpg}</span></td>
    </tr>
</table>
