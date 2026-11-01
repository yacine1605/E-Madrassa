<x-filament-panels::page>
    @include('filament.school.banner', ['school' => $this->schoolState()])
    {{ $this->detailsInfolist }}
    @php($instructions = $this->instructions())
    @if ($instructions)
        <x-filament::section :heading="__('pedagogy.instructions')">
            <div class="fi-prose">
                @include('filament.school.lesson-node', ['node' => $instructions])
                <p><small>{{ __('pedagogy.external_links_help') }}</small></p>
            </div>
        </x-filament::section>
    @endif
    {{ $this->workInfolist }}
</x-filament-panels::page>
