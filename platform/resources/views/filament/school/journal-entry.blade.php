<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-3 gap-2">
        <dt class="font-medium">{{ __('admin.object_id') }}</dt><dd class="col-span-2" dir="ltr">{{ $entry['object_id'] ?? '—' }}</dd>
        <dt class="font-medium">{{ __('admin.reason') }}</dt><dd class="col-span-2">{{ $entry['reason'] ?? '—' }}</dd>
        <dt class="font-medium">{{ __('admin.request') }}</dt><dd class="col-span-2" dir="ltr">{{ $entry['request_id'] ?? '—' }}</dd>
        <dt class="font-medium">{{ __('admin.ip') }}</dt><dd class="col-span-2" dir="ltr">{{ $entry['ip'] ?? '—' }}</dd>
    </dl>
    @if ($rows)
        <table class="w-full text-start">
            <thead>
                <tr class="border-b">
                    <th class="p-2 text-start">{{ __('admin.field') }}</th>
                    <th class="p-2 text-start">{{ __('admin.before') }}</th>
                    <th class="p-2 text-start">{{ __('admin.after') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b">
                        <td class="p-2">{{ $row['field'] }}</td>
                        <td class="p-2 break-all">{{ $row['before'] }}</td>
                        <td class="p-2 break-all">{{ $row['after'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
