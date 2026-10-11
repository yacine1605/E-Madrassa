<table width="100%" style="border-bottom: 0.5pt solid #555; font-size: 9pt;" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    <tr>
        <td style="font-size: 12pt; font-weight: bold;">{{ $identity['name'] }}</td>
        <td style="text-align: {{ $rtl ? 'left' : 'right' }}; color: #444;">
            {{ $identity['address'] }}@if ($identity['phone'])<br><span dir="ltr">{{ $identity['phone'] }}</span>@endif
            @if ($identity['email'])<br><span dir="ltr">{{ $identity['email'] }}</span>@endif
        </td>
    </tr>
</table>
