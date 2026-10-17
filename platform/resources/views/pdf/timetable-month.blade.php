@include('pdf.style')
{{-- FR-PLN-04: a month's timetable of a group, a teacher, a room or one's own: its weeks in rows, the open days in columns, each day its sessions in order. --}}
<style>
    table.month td { height: 52pt; font-size: 8pt; }
    table.month td.out { background-color: #f4f4f4; }
    .day { font-weight: bold; font-size: 10pt; }
    .cancelled { color: #888; text-decoration: line-through; }
</style>
<h1>{{ $title }}</h1>
<p>{{ $subject }} · {{ $month }}</p>
<table class="grid month" repeat_header="1">
    <thead><tr>@foreach ($columns as $column)<th width="{{ round(100 / count($columns), 2) }}%">{{ $column }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach ($weeks as $week)
        <tr>
            @foreach ($week as $cell)
                <td class="{{ $cell['in'] ? '' : 'out' }}">@if ($cell['in'])<div class="day">{{ $cell['day'] }}</div>@foreach ($cell['sessions'] as $session)<div class="{{ $session['cancelled'] ? 'cancelled' : '' }}"><span dir="ltr">{{ $session['hours'] }}</span> {{ $session['subject'] }}@if ($session['who']) · {{ $session['who'] }}@endif @if ($session['room'])· <span dir="ltr">{{ $session['room'] }}</span>@endif</div>@endforeach @endif</td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
@if ($empty)<p class="muted">{{ __('pdf.no_session_month', [], $locale) }}</p>@endif
