@include('pdf.style')
{{-- A receipt (FR-FIN-03, lot 3): lines with their amounts in centimes, a total; amounts written by App\Support\Money. --}}
<h1>{{ $title }}</h1>
<p>{{ __('pdf.receipt_number', [], $locale) }} : <b dir="ltr">{{ $reference }}</b></p>
<p>{{ __('pdf.payer', [], $locale) }} : {{ $payer }}</p>
<p>{{ __('pdf.date', [], $locale) }} : <span dir="ltr">{{ $date }}</span></p>
<table class="grid">
    <tr><th>{{ __('pdf.label', [], $locale) }}</th><th>{{ __('pdf.amount', [], $locale) }}</th></tr>
    @foreach ($lines as $line)
        <tr><td>{{ $line['label'] }}</td><td class="number">{{ \App\Support\Money::format($line['amount'], $locale) }}</td></tr>
    @endforeach
    <tr><th>{{ __('pdf.total', [], $locale) }}</th>
        <th class="number">{{ \App\Support\Money::format(array_sum(array_column($lines, 'amount')), $locale) }}</th></tr>
</table>
