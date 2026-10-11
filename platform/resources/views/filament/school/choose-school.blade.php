<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2">
        @forelse ($this->schools() as $school)
            <x-filament::section>
                <x-slot name="heading">{{ $school->name }}</x-slot>
                <x-filament::button wire:click="choose(@js($school->tenant_id))" wire:loading.attr="disabled">{{ __('school.open_school') }}</x-filament::button>
            </x-filament::section>
        @empty
            <p>{{ __('errors.no_school') }}</p>
        @endforelse
    </div>
</x-filament-panels::page>
