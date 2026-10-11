<x-filament-panels::page.simple>
    <form wire:submit="authenticate" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('school.sign_in') }}</x-filament::button>
        @if ($this->pending())
            <x-filament::button color="gray" wire:click="restart">{{ __('school.restart_login') }}</x-filament::button>
        @endif
    </form>
    @unless ($this->pending())
        <div class="mt-6 text-center">
            <x-filament::link href="/school/recovery">{{ __('account.forgot') }}</x-filament::link>
        </div>
    @endunless
</x-filament-panels::page.simple>
