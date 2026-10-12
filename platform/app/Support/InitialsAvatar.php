<?php

namespace App\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * A person's initials on the panels' dark circle, drawn here. Filament's default asks an outside
 * service, ui-avatars.com, giving it the initials of whoever is signed in on every page: no
 * personal data leaves the platform, and the Content Security Policy loads no image from
 * elsewhere (SECURITY-REVIEW.md, F4).
 */
final class InitialsAvatar implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        return self::of(Filament::getNameForDefaultAvatar($record));
    }

    /** The same circle for anyone named, a pupil without a photo too (FR-ELV-01). */
    public static function of(string $name): string
    {
        $initials = collect(preg_split('/\s+/u', trim($name)))
            ->map(fn (string $word) => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word), 0, 1))
            ->filter()->take(2)->join('');
        $background = Color::convertToHex(FilamentColor::getColor('gray')[950] ?? Color::Gray[950]);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="'.e($background).'"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="system-ui, sans-serif" font-size="26" fill="#fff">'
            .e(mb_strtoupper($initials)).'</text></svg>';
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
