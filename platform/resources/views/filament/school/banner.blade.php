@if ($school['support'])
    <x-filament::section>
        <p role="alert">{{ __('school.support_banner', ['until' => \App\Support\Formats::dateTime($school['support']['expires_at'])]) }}</p>
    </x-filament::section>
@elseif ($school['mode'] !== 'active')
    <x-filament::section>
        <p role="alert">{{ __('errors.tenant_read_only') }}</p>
    </x-filament::section>
@endif
