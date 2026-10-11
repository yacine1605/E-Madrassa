<x-filament-panels::page.simple>
    <form wire:submit="authenticate" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('school.sign_in') }}</x-filament::button>
        @if ($this->pending())
            <x-filament::button color="gray" wire:click="restart">{{ __('school.restart_login') }}</x-filament::button>
        @endif
    </form>
</x-filament-panels::page.simple>
