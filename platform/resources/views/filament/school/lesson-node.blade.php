{{-- A node of a lesson's document (SQL 078), drawn with escaped text: never HTML from data. --}}
@php($type = $node['type'] ?? null)
@php($children = is_array($node['content'] ?? null) ? $node['content'] : [])
@switch($type)
    @case('doc')
        @foreach ($children as $child) @include('filament.school.lesson-node', ['node' => $child]) @endforeach
        @break
    @case('paragraph')
        <p dir="auto">@foreach ($children as $child)@include('filament.school.lesson-node', ['node' => $child])@endforeach</p>
        @break
    @case('heading')
        @php($level = \App\Support\LessonDocument::headingLevel($node))
        <h{{ $level }} dir="auto">@foreach ($children as $child)@include('filament.school.lesson-node', ['node' => $child])@endforeach</h{{ $level }}>
        @break
    @case('blockquote')
        <blockquote dir="auto">@foreach ($children as $child) @include('filament.school.lesson-node', ['node' => $child]) @endforeach</blockquote>
        @break
    @case('bulletList')
        <ul>@foreach ($children as $child) @include('filament.school.lesson-node', ['node' => $child]) @endforeach</ul>
        @break
    @case('orderedList')
        <ol start="{{ (int) ($node['attrs']['start'] ?? 1) }}">@foreach ($children as $child) @include('filament.school.lesson-node', ['node' => $child]) @endforeach</ol>
        @break
    @case('listItem')
        <li dir="auto">@foreach ($children as $child) @include('filament.school.lesson-node', ['node' => $child]) @endforeach</li>
        @break
    @case('codeBlock')
        <pre dir="ltr"><code>@foreach ($children as $child){{ $child['text'] ?? '' }}@endforeach</code></pre>
        @break
    @case('horizontalRule')
        <hr>
        @break
    @case('hardBreak')
        <br>
        @break
    @case('image')
        {{-- An image of another site is opened there: the page asks nothing of a third party. --}}
        @php($href = \App\Support\LessonDocument::href($node['attrs']['src'] ?? null))
        @if ($href)
            <p><a href="{{ $href }}" target="_blank" rel="noopener noreferrer">{{ __('pedagogy.image', ['name' => $node['attrs']['alt'] ?? $node['attrs']['title'] ?? __('pedagogy.image_untitled')]) }} ↗</a></p>
        @endif
        @break
    @case('text')
        @include('filament.school.lesson-text', ['text' => (string) ($node['text'] ?? ''), 'marks' => is_array($node['marks'] ?? null) ? array_values($node['marks']) : []])
        @break
@endswitch
