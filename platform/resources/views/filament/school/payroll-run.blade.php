<x-filament-panels::page>
    @include('filament.school.banner', ['school' => $this->schoolState()])
    {{ $this->summaryInfolist }}
    {{ $this->payoutsInfolist }}
    {{ $this->table }}
    {{ $this->adjustmentsInfolist }}
</x-filament-panels::page>
