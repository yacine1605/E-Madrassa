<x-filament-panels::page>
    @include('filament.school.banner', ['school' => $this->schoolState()])
    {{ $this->leadInfolist }}
    {{ $this->table }}
    {{ $this->historyInfolist }}
</x-filament-panels::page>
