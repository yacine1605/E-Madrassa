@foreach ($locales as $locale)
{{ __('mail.guardian.greeting', [], $locale) }}

{{ __('mail.guardian.absences', ['pupil' => $pupil[$locale], 'absences' => $absences, 'from' => $since[$locale], 'to' => $until[$locale]], $locale) }}

{{ $phone ? __('mail.guardian.contact_phone', ['school' => $school, 'phone' => $phone], $locale) : __('mail.guardian.contact', ['school' => $school], $locale) }}

{{ __('mail.guardian.automatic', [], $locale) }}
@if (!$loop->last)

————

@endif
@endforeach
