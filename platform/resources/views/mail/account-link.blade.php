{{ __('mail.greeting', ['name' => $name]) }}

@if ($purpose === 'activation')
{{ $school ? __('mail.activation.opened_school', ['school' => $school]) : __('mail.activation.opened') }}
{{ __('mail.activation.action') }}
@else
{{ __('mail.recovery.asked') }}
{{ __('mail.recovery.action') }}
@endif

{{ $url }}

{{ __('mail.once') }}
