<div class="space-y-2 text-sm">
    <p>{{ __('schooling.prices_help') }}</p>
    <table class="w-full text-start">
        <thead>
            <tr class="border-b">
                <th class="p-2 text-start">{{ __('schooling.price_version') }}</th>
                <th class="p-2 text-start">{{ __('schooling.price') }}</th>
                <th class="p-2 text-start">{{ __('schooling.since') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($prices as $price)
                <tr class="border-b">
                    <td class="p-2">{{ $price['version'] }}</td>
                    <td class="p-2">{{ $price['price'] }}</td>
                    <td class="p-2">{{ $price['since'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
