@include('pdf.style')
{{-- A list (FR-IMP-02 in PDF): column titles and rows of text, already filtered by the caller's permissions and scopes. --}}
<h1>{{ $title }}</h1>
@if (!empty($filters))<p class="muted">{{ $filters }}</p>@endif
<table class="grid" repeat_header="1">
    <thead><tr>@foreach ($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach ($rows as $row)
        <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
    @endforeach
    </tbody>
</table>
<p class="muted">{{ __('pdf.rows', ['count' => count($rows)], $locale) }}</p>
