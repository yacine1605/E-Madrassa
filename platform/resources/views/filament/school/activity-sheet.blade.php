<x-filament-panels::page>
    @include('filament.school.banner', ['school' => $this->schoolState()])
    {{ $this->detailsInfolist }}
    {{ $this->questionsInfolist }}
    {{ $this->table }}
</x-filament-panels::page>
