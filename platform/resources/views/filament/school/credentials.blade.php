<x-filament-panels::page>
    @if (auth()->user()->must_change_password)
        <p role="alert">{{ __('errors.password_change_required') }}</p>
    @endif
    <div class="flex flex-wrap gap-3">
        {{ $this->passwordAction }}
        @if (!auth()->user()->must_change_password)
            {{ $this->languageAction }}
            {{ $this->startMfaAction }}
        @endif
    </div>
    @if ($mfaSecret)
        <x-filament::section>
            <x-slot name="heading">{{ __('school.mfa_setup') }}</x-slot>
            <p>{{ __('school.mfa_help') }}</p>
            <p class="break-all" dir="ltr"><code>{{ $mfaSecret }}</code></p>
            {{ $this->confirmMfaAction }}
        </x-filament::section>
    @endif
    @if ($recoveryCodes)
        <x-filament::section>
            <x-slot name="heading">{{ __('school.recovery_codes') }}</x-slot>
            <p>{{ __('school.recovery_help') }}</p>
            <div class="grid gap-2 sm:grid-cols-2" dir="ltr">
                @foreach ($recoveryCodes as $code)
                    <code>{{ $code }}</code>
                @endforeach
            </div>
        </x-filament::section>
    @endif
    <x-filament::button tag="a" href="/school/home">{{ __('school.open_school') }}</x-filament::button>
</x-filament-panels::page>
