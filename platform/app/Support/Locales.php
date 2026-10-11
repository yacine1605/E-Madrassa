<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The interface's languages (FR-I18-01, NFR-I18N-01): each person's own if the school offers
 * it, else the school's, else French; the database resolves it (`platform.locale_for`). French
 * and Arabic have their catalogs; English falls back to French until it has its own.
 */
final class Locales
{
    /** The languages a person may choose: those whose catalogs exist. */
    public const AVAILABLE = ['fr', 'ar'];

    /** Each language by its own name, the same in every interface. */
    public const NAMES = ['fr' => 'Français', 'ar' => 'العربية', 'en' => 'English'];

    public static function apply(string $locale): void
    {
        app()->setLocale($locale);
        Carbon::setLocale($locale);
    }

    /** Before sign-in, or for a person without a choice outside any school: the browser's preference. */
    public static function fromRequest(Request $request): string
    {
        return $request->getPreferredLanguage(self::AVAILABLE) ?? 'fr';
    }

    /** A person's language in a school, or outside any, as the database resolves it. */
    public static function of(int $user, ?string $tenant, ?string $connection = null): string
    {
        return self::usable(DB::connection($connection)->selectOne('SELECT platform.locale_for(?, ?::uuid) AS l', [$user, $tenant])->l);
    }

    /** A language without its catalog yet is shown in French. */
    public static function usable(?string $locale): string
    {
        return in_array($locale, self::AVAILABLE, true) ? $locale : 'fr';
    }

    public static function direction(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? 'rtl' : 'ltr';
    }

    /** A label of the catalog, the referential or a role (BR-18): in the current language, else the first there is. */
    public static function label(array|string|null $labels): ?string
    {
        if (is_string($labels)) $labels = json_decode($labels, true, 4, JSON_THROW_ON_ERROR);
        if (!$labels) return null;
        return $labels[app()->getLocale()] ?? $labels['fr'] ?? $labels['ar'] ?? $labels['en'] ?? null;
    }
}
