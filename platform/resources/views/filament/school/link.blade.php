<x-filament-panels::page.simple>
    {{-- The token is read from the fragment, then taken out of the address bar and history. In a
         component rather than in the markup: Alpine's CSP build evaluates no statement there (F4). --}}
    <script nonce="{{ Vite::cspNonce() }}">
        document.addEventListener('alpine:init', () => Alpine.data('accountLink', () => ({
            init() {
                const token = new URLSearchParams(window.location.hash.slice(1)).get('token');
                if (token) { history.replaceState(null, '', window.location.pathname); this.$wire.open(token) } else { this.$wire.withoutToken() }
            },
        })));
    </script>
    <div class="space-y-6" x-data="accountLink">
        @switch ($state)
            @case('reading')
                <p>{{ __('account.reading') }}</p>
                @break
            @case('invalid')
                <p role="alert">{{ __('errors.token_invalid') }}</p>
                <x-filament::link href="/school/login">{{ __('account.to_login') }}</x-filament::link>
                @break
            @case('ready')
                <form wire:submit="submit" class="space-y-6">
                    <p>{{ __('account.hello', ['name' => $link['name']]) }}</p>
                    @if ($link['school'])
                        <p>{{ __($this->purpose() === 'activation' ? 'account.invited_to' : 'account.recovery_for', ['school' => $link['school']]) }}</p>
                    @endif
                    @if ($link['username'])
                        <p>{{ __('account.username', ['username' => $link['username']]) }}</p>
                    @endif
                    @if ($link['needs_password'])
                        {{ $this->form }}
                    @else
                        <p>{{ __('account.existing_password') }}</p>
                    @endif
                    <x-filament::button type="submit" wire:loading.attr="disabled">
                        {{ __($link['needs_password'] ? 'account.save_password' : 'account.accept') }}
                    </x-filament::button>
                </form>
                @break
            @case('request')
                <form wire:submit="send" class="space-y-6">
                    <p>{{ __('account.request_help') }}</p>
                    {{ $this->form }}
                    <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('account.send_link') }}</x-filament::button>
                </form>
                <p>{{ __('account.no_email') }}</p>
                @break
            @case('requested')
                <p role="status">{{ __('messages.recovery_sent') }}</p>
                <x-filament::link href="/school/login">{{ __('account.to_login') }}</x-filament::link>
                @break
            @case('done')
                <p role="status">{{ __($this->purpose() === 'activation' ? 'account.activated' : 'account.reset_done') }}</p>
                <x-filament::link href="/school/login">{{ __('account.to_login') }}</x-filament::link>
                @break
        @endswitch
    </div>
</x-filament-panels::page.simple>
