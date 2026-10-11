<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ count($schools) }} établissement(s)</x-slot>
        <x-slot name="description">Comptages agrégés : aucune donnée d’établissement n’apparaît ici.</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-start">
                <thead>
                    <tr class="border-b">
                        <th class="p-2 text-start">Établissement</th>
                        <th class="p-2 text-start">État</th>
                        <th class="p-2 text-start">Plan</th>
                        <th class="p-2 text-end">Modules</th>
                        <th class="p-2 text-end">Comptes actifs</th>
                        <th class="p-2 text-end">Stockage</th>
                        <th class="p-2 text-end">Tâches en cours</th>
                        <th class="p-2 text-end">Tâches en échec (7 j)</th>
                        <th class="p-2 text-end">E-mails en échec</th>
                        <th class="p-2 text-start">Dernière modification</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($schools as $school)
                        <tr class="border-b">
                            <td class="p-2">{{ $school->name }} <span class="text-gray-500">({{ $school->slug }})</span></td>
                            <td class="p-2">{{ ['active' => 'actif', 'read_only' => 'lecture seule', 'suspended' => 'suspendu', 'archived' => 'archivé'][$school->mode] ?? $school->mode }}</td>
                            <td class="p-2">{{ $school->plan }}@if ($school->valid_until) · {{ \Illuminate\Support\Carbon::parse($school->valid_until)->format('d/m/Y') }}@endif</td>
                            <td class="p-2 text-end">{{ $school->modules }}</td>
                            <td class="p-2 text-end">{{ $school->members }}</td>
                            <td class="p-2 text-end">{{ number_format($school->storage_used / 1048576, 1, ',', ' ') }} / {{ number_format($school->storage_quota / 1048576, 0, ',', ' ') }} Mo</td>
                            <td class="p-2 text-end">{{ $school->jobs_active }}</td>
                            <td class="p-2 text-end">{{ $school->jobs_failed }}</td>
                            <td class="p-2 text-end">{{ $school->emails_failed }}</td>
                            <td class="p-2">{{ $school->last_activity ? \Illuminate\Support\Carbon::parse($school->last_activity)->timezone('Africa/Algiers')->format('d/m/Y H:i') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
