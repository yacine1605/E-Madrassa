@include('pdf.style')
{{-- FR-PLN-04: a week's timetable of a group, a teacher or a room: the open days in columns, the hours in rows, each session with its subject, group or teacher, and room. --}}
<style>
    table.week td { height: 30pt; }
    table.week td.hours { white-space: nowrap; width: 12%; }
    .cancelled { color: #888; text-decoration: line-through; }
</style>
<h1>{{ $title }}</h1>
<p>{{ $subject }} · <span dir="ltr">{{ $week }}</span></p>
<table class="grid week" repeat_header="1">
    <thead><tr><th>{{ __('pdf.hours', [], $locale) }}</th>@foreach ($days as $day)<th>{{ $day['name'] }}<br><span class="muted" dir="ltr">{{ $day['date'] }}</span></th>@endforeach</tr></thead>
    <tbody>
    @forelse ($rows as $row)
        <tr>
            <td class="hours"><span dir="ltr">{{ $row['hours'] }}</span></td>
            @foreach ($days as $day)
                <td>@foreach ($row['cells'][$day['key']] ?? [] as $cell)<div class="{{ $cell['cancelled'] ? 'cancelled' : '' }}"><b>{{ $cell['subject'] }}</b><br>{{ $cell['who'] }}@if ($cell['room'])<br><span class="muted" dir="ltr">{{ $cell['room'] }}</span>@endif</div>@endforeach</td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ count($days) + 1 }}">{{ __('pdf.no_session', [], $locale) }}</td></tr>
    @endforelse
    </tbody>
</table>
