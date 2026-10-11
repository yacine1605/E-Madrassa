<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ __('console.supervision.count', ['count' => count($schools)]) }}</x-slot>
        <x-slot name="description">{{ __('console.supervision.description') }}</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-start">
                <thead>
                    <tr class="border-b">
                        @foreach (['school', 'status', 'plan'] as $column)
                            <th class="p-2 text-start">{{ __('console.supervision.columns.'.$column) }}</th>
                        @endforeach
                        @foreach (['modules', 'members', 'storage', 'jobs_active', 'jobs_failed', 'emails_failed'] as $column)
                            <th class="p-2 text-end">{{ __('console.supervision.columns.'.$column) }}</th>
                        @endforeach
                        <th class="p-2 text-start">{{ __('console.supervision.columns.last_activity') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($schools as $school)
                        <tr class="border-b">
                            <td class="p-2">{{ $school->name }} <span class="text-gray-500">({{ $school->slug }})</span></td>
                            <td class="p-2">{{ __('console.tenant.status.'.$school->mode) }}</td>
                            <td class="p-2">{{ $school->plan }}@if ($school->valid_until) · {{ \App\Support\Formats::date($school->valid_until) }}@endif</td>
                            <td class="p-2 text-end">{{ \App\Support\Formats::number($school->modules) }}</td>
                            <td class="p-2 text-end">{{ \App\Support\Formats::number($school->members) }}</td>
                            <td class="p-2 text-end">{{ __('formats.megabytes', ['value' => \App\Support\Formats::number($school->storage_used / 1048576, 1).' / '.\App\Support\Formats::number($school->storage_quota / 1048576)]) }}</td>
                            <td class="p-2 text-end">{{ \App\Support\Formats::number($school->jobs_active) }}</td>
                            <td class="p-2 text-end">{{ \App\Support\Formats::number($school->jobs_failed) }}</td>
                            <td class="p-2 text-end">{{ \App\Support\Formats::number($school->emails_failed) }}</td>
                            <td class="p-2">{{ $school->last_activity ? \App\Support\Formats::dateTime($school->last_activity) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
