<x-filament-panels::page>
    @php($school = $this->school())
    <x-filament::section>
        <x-slot name="heading">{{ $school['name'] }}</x-slot>
        <p>{{ __('school.welcome') }}</p>
        @if ($school['mode'] !== 'active')
            <p role="alert">{{ __('errors.tenant_read_only') }}</p>
        @endif
        @if ($school['support'])
            <p role="alert">{{ __('school.support_banner', ['until' => \App\Support\Formats::dateTime($school['support']['expires_at'])]) }}</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
