<?php

namespace App\Support;

/**
 * Amounts in Algerian dinars, held as whole centimes (ARC-11, BR-04), written for reading. Digit
 * groups are joined by no-break spaces, which the bidirectional algorithm keeps inside the
 * number: in Arabic text "12 500,50" never turns into "500,50 12" (FR-PDF-01 AC1, TST-17).
 */
final class Money
{
    public static function format(int $centimes, string $locale, bool $arabicDigits = false): string
    {
        $sign = $centimes < 0 ? '-' : '';
        $centimes = abs($centimes);
        $number = Formats::digits($sign.number_format(intdiv($centimes, 100), 0, '', "\u{00A0}").','.str_pad((string) ($centimes % 100), 2, '0', STR_PAD_LEFT),
            $locale, $arabicDigits);
        $currency = __('formats.currency', [], $locale);
        // In Arabic the number is isolated left-to-right, the currency follows it in reading order.
        return $locale === 'ar' ? "\u{2066}".$number."\u{2069}\u{00A0}".$currency : $number."\u{00A0}".$currency;
    }
}
