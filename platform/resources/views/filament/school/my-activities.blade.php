<x-filament-panels::page>
    @include('filament.school.banner', ['school' => $this->schoolState()])
    {{ $this->averagesInfolist }}
    {{ $this->table }}
</x-filament-panels::page>
