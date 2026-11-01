<x-filament-panels::page>
    @include('filament.school.banner', ['school' => $this->schoolState()])
    @forelse ($this->chapters() as $chapter)
        <x-filament::section :heading="$chapter['title']">
            @foreach ($chapter['lessons'] as $lesson)
                @php($learns = $this->learns())
                @php($description = $this->lessonLine($lesson))
                @php($opening = $this->opening($lesson))
                {{-- A pupil opens a lesson by unfolding it: the server notes it then, never a proof of understanding (FR-LMS-05). --}}
                <x-filament::section :heading="$lesson['title']" :description="$description" collapsible :collapsed="$learns">
                    <div class="fi-prose" @if ($opening) x-intersect.once="{{ $opening }}" @endif>
                        @if ($learns)
                            <p>{{ ($this->declareAction)(['lesson' => $lesson['lesson_id'], 'done' => !$this->declared($lesson)]) }}</p>
                        @endif
                        @if ($lesson['objectives'])
                            <p><strong>{{ __('pedagogy.objectives') }}</strong> : {{ $lesson['objectives'] }}</p>
                        @endif
                        @if ($lesson['prerequisites'])
                            <p><strong>{{ __('pedagogy.prerequisites') }}</strong> : {{ $lesson['prerequisites'] }}</p>
                        @endif
                        @include('filament.school.lesson-node', ['node' => $lesson['content']])
                        @if ($lesson['resources'] !== [])
                            <p><strong>{{ __('pedagogy.resources') }}</strong></p>
                            <ul>
                                @foreach ($lesson['resources'] as $resource)
                                    @php($href = \App\Support\LessonDocument::href($resource['url']))
                                    <li dir="auto">
                                        @if ($href)<a href="{{ $href }}" target="_blank" rel="noopener noreferrer">{{ $resource['title'] }} ↗</a>@else{{ $resource['title'] }}@endif
                                        <small>· {{ $resource['author'] }}</small>
                                        @if ($resource['withdrawable'] && $resource['version'] !== null)
                                            {{ ($this->withdrawResourceAction)(['resource' => $resource['id'], 'version' => $resource['version']]) }}
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <p><small>{{ __('pedagogy.external_links_help') }}</small></p>
                    </div>
                </x-filament::section>
            @endforeach
        </x-filament::section>
    @empty
        <x-filament::section :heading="__('pedagogy.no_lesson')" :description="__('pedagogy.no_lesson_help')" />
    @endforelse
</x-filament-panels::page>
