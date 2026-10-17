@php($dir = fn (?string $text) => \App\Support\Formats::direction($text))
{{-- Name and address as the school typed them, each in its own direction: « 12, rue … » stays whole in Arabic. --}}
<table width="100%" style="border-bottom: 0.5pt solid #555; font-size: 9pt;" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    <tr>
        <td style="font-size: 12pt; font-weight: bold;"><span dir="{{ $dir($identity['name']) }}">{{ $identity['name'] }}</span></td>
        <td style="text-align: {{ $rtl ? 'left' : 'right' }}; color: #444;">
            @if ($identity['address'])<span dir="{{ $dir($identity['address']) }}">{{ $identity['address'] }}</span>@endif
            @if ($identity['phone'])<br><span dir="ltr">{{ $identity['phone'] }}</span>@endif
            @if ($identity['email'])<br><span dir="ltr">{{ $identity['email'] }}</span>@endif
        </td>
    </tr>
</table>
