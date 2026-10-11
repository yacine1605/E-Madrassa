<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Dates and numbers written for reading (FR-I18-01): Gregorian dates, day first, in Algiers
 * time; Western digits, or Arabic-Indic ones in Arabic when the school asks for them
 * (`locale.arabic_digits`).
 */
final class Formats
{
    private const ARABIC_INDIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    public static function date(DateTimeInterface|string|null $value, ?string $locale = null, bool $arabicDigits = false): string
    {
        return self::written($value, 'formats.date', $locale, $arabicDigits);
    }

    public static function dateTime(DateTimeInterface|string|null $value, ?string $locale = null, bool $arabicDigits = false): string
    {
        return self::written($value, 'formats.datetime', $locale, $arabicDigits);
    }

    /** Digit groups joined by no-break spaces, which the bidirectional algorithm keeps together. */
    public static function number(int|float $value, int $decimals = 0, ?string $locale = null, bool $arabicDigits = false): string
    {
        return self::digits(number_format($value, $decimals, ',', "\u{00A0}"), $locale, $arabicDigits);
    }

    /** Items one after the other, with the language's comma: « ، » in Arabic. */
    public static function list(array $items): string
    {
        return implode(__('formats.list_separator'), $items);
    }

    public static function digits(string $text, ?string $locale = null, bool $arabicDigits = false): string
    {
        return $arabicDigits && ($locale ?? app()->getLocale()) === 'ar' ? strtr($text, array_combine(range(0, 9), self::ARABIC_INDIC)) : $text;
    }

    private static function written(DateTimeInterface|string|null $value, string $format, ?string $locale, bool $arabicDigits): string
    {
        if ($value === null || $value === '') return '';
        $locale ??= app()->getLocale();
        $date = Carbon::parse($value)->timezone('Africa/Algiers');
        return self::digits($date->format(__($format, [], $locale)), $locale, $arabicDigits);
    }
}
