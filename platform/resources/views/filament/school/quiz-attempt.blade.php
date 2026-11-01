<x-filament-panels::page>
    @include('filament.school.banner', ['school' => $this->schoolState()])
    @if ($this->taking())
        {{-- The time left, counted down here from the server's count, read again every 30 seconds and at zero. --}}
        <x-filament::section>
            <div wire:poll.30s="refresh" role="timer" aria-live="off"
                x-data="{ remaining: $wire.$entangle('secondsLeft'), asked: false }"
                x-init="setInterval(function () { remaining = Math.max(0, remaining - 1); if (remaining == 0 && !asked) { asked = true; $wire.refresh() } }, 1000)">
                <strong>{{ __('pedagogy.time_left') }}</strong>
                <span dir="ltr" x-text="Math.floor(remaining / 60) + ':' + String(remaining % 60).padStart(2, '0')">{{ intdiv($secondsLeft, 60) }}:{{ str_pad((string) ($secondsLeft % 60), 2, '0', STR_PAD_LEFT) }}</span>
                <p><small>{{ __('pedagogy.time_left_help') }}</small></p>
            </div>
        </x-filament::section>
        {{ $this->form }}
        <div>{{ $this->submitAction }}</div>
    @else
        {{ $this->reviewInfolist }}
    @endif
    <div>{{ $this->backAction }}</div>
</x-filament-panels::page>
