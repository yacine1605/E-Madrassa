@include('pdf.style')
{{-- FR-FIN-05: a receipt of a payment or a refund, printed from its content frozen when it was issued; a copy says « duplicata ». --}}
<h1>{{ $title }}</h1>
@if ($duplicate)
    <p><b>{{ __('pdf.duplicate', [], $locale) }}</b></p>
@endif
<p>{{ __('pdf.receipt_number', [], $locale) }} : <b dir="ltr">{{ $reference }}</b></p>
<p>{{ __('pdf.date', [], $locale) }} : <span dir="ltr">{{ $date }}</span></p>
<p>{{ __('pdf.pupil', [], $locale) }} : {{ $pupil }}@if ($matricule) (<span dir="ltr">{{ $matricule }}</span>)@endif</p>
<p>{{ __('pdf.program', [], $locale) }} : {{ $program }}</p>
<table class="grid">
    <tr><th>{{ __('pdf.label', [], $locale) }}</th><th>{{ __('pdf.amount', [], $locale) }}</th></tr>
    <tr>
        <td>{{ $line }} <span dir="ltr">{{ $operation }}</span>
            @if ($means !== '')<br><span class="muted">{{ $means }}</span>@endif
            @if ($reason)<br><span class="muted">{{ $reason }}</span>@endif
        </td>
        <td class="number">{{ \App\Support\Money::format($amount, $locale, $arabicDigits) }}</td>
    </tr>
    <tr>
        <th>{{ $after }}</th>
        <th class="number">{{ \App\Support\Money::format($left, $locale, $arabicDigits) }}</th>
    </tr>
</table>
