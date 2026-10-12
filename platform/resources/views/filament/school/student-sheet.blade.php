<x-filament-panels::page>
    @include('filament.school.banner', ['school' => $this->schoolState()])
    {{ $this->identityInfolist }}
    {{ $this->table }}
</x-filament-panels::page>
