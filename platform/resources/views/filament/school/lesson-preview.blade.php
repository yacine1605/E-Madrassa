{{-- A lesson as a pupil will read it: its working copy, before it is published. --}}
<div class="fi-prose">
    @if ($lesson['objectives'] ?? null)
        <p><strong>{{ __('pedagogy.objectives') }}</strong> : {{ $lesson['objectives'] }}</p>
    @endif
    @if ($lesson['prerequisites'] ?? null)
        <p><strong>{{ __('pedagogy.prerequisites') }}</strong> : {{ $lesson['prerequisites'] }}</p>
    @endif
    @include('filament.school.lesson-node', ['node' => $lesson['content']])
    <p><small>{{ __('pedagogy.external_links_help') }}</small></p>
</div>
