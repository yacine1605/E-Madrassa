<div class="text-sm">
    @if ($rows)
        <table class="w-full text-start">
            <thead>
                <tr class="border-b">
                    <th class="p-2 text-start">{{ __('services.line') }}</th>
                    <th class="p-2 text-start">{{ __('services.cause') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b">
                        <td class="p-2">{{ \App\Support\Formats::number($row['line']) }}</td>
                        <td class="p-2">{{ $row['error_message'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>{{ __('services.none_rejected') }}</p>
    @endif
</div>
