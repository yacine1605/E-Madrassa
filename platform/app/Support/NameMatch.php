<?php

namespace App\Support;

/**
 * Whether two people's names are probably one name (FR-ELV-04, NFR-I18N-03). Written in the same
 * alphabet, they are the same once normalized as the search normalizes them (case, accents,
 * Arabic diacritics and letter forms, spaces): « HÉLÈNE » is « Helene », « Amine » is not
 * « Imane ». Written in Arabic for one and Latin letters for the other, both come down to their
 * consonants by sound, since Arabic writes no short vowel and Latin spellings vary: « Mohamed
 * Amine » and « محمد أمين » are M H M D, M N; then a simple score tolerates the rest (an article,
 * a doubled letter). A suggestion to look at, never a merge.
 */
final class NameMatch
{
    /** Consonants in common needed across alphabets: « Abdelkader » and « عبد القادر » (B D L K D R, B D K D R). */
    public const OTHER_ALPHABET = 0.75;

    /**
     * As school.search_key (016_i18n.sql) translates, by code point (the code holds no text for
     * people, PseudoLocalizationTest): Arabic letter forms (أ إ آ ٱ → ا, ؤ → و, ئ ى ی → ي, ة → ه,
     * ک → ك), accented Latin letters, Arabic-Indic digits.
     */
    private const SAME = [0x0627 => [0x0623, 0x0625, 0x0622, 0x0671], 0x0648 => [0x0624], 0x064A => [0x0626, 0x0649, 0x06CC], 0x0647 => [0x0629],
        0x0643 => [0x06A9], 0x61 => [0xC0, 0xC1, 0xC2, 0xC3, 0xC4, 0xC5, 0xE0, 0xE1, 0xE2, 0xE3, 0xE4, 0xE5], 0x63 => [0xC7, 0xE7],
        0x65 => [0xC8, 0xC9, 0xCA, 0xCB, 0xE8, 0xE9, 0xEA, 0xEB], 0x69 => [0xCC, 0xCD, 0xCE, 0xCF, 0xEC, 0xED, 0xEE, 0xEF], 0x6E => [0xD1, 0xF1],
        0x6F => [0xD2, 0xD3, 0xD4, 0xD5, 0xD6, 0xF2, 0xF3, 0xF4, 0xF5, 0xF6], 0x75 => [0xD9, 0xDA, 0xDB, 0xDC, 0xF9, 0xFA, 0xFB, 0xFC],
        0x79 => [0xDD, 0xFD, 0xFF]];

    /**
     * Arabic letters by the sound Latin spellings give them, by code point: ب پ B, ت ط ث T, ج J, ح ه H,
     * خ X, د ض ذ D, ر R, ز ظ Z, س ص S, ش C, غ G, ف ڤ F, ق ك گ K, ل L, م M, ن N. Vowels, hamza, ʿayn and
     * tāʾ marbūṭa are not written in Latin letters: they have no sound here.
     */
    private const ARABIC = [0x0628 => 'B', 0x067E => 'B', 0x062A => 'T', 0x0637 => 'T', 0x062B => 'T', 0x062C => 'J', 0x062D => 'H', 0x0647 => 'H',
        0x062E => 'X', 0x062F => 'D', 0x0636 => 'D', 0x0630 => 'D', 0x0631 => 'R', 0x0632 => 'Z', 0x0638 => 'Z', 0x0633 => 'S', 0x0635 => 'S',
        0x0634 => 'C', 0x063A => 'G', 0x0641 => 'F', 0x06A4 => 'F', 0x0642 => 'K', 0x0643 => 'K', 0x06AF => 'K', 0x0644 => 'L', 0x0645 => 'M',
        0x0646 => 'N'];
    private const ALEF_LAM = "\u{0627}\u{0644}";
    private const TA_MARBUTA = "\u{0629}";
    /** Latin spellings of those sounds, the longest first: « dj » is ج, « ch » ش, « ou » a vowel. */
    private const LATIN = ['sch' => 'C', 'ch' => 'C', 'sh' => 'C', 'kh' => 'X', 'gh' => 'G', 'dj' => 'J', 'dz' => 'J', 'th' => 'T', 'dh' => 'D',
        'ph' => 'F', 'qu' => 'K', 'ck' => 'K', 'ou' => '', 'b' => 'B', 'p' => 'B', 't' => 'T', 'j' => 'J', 'g' => 'K', 'h' => 'H', 'x' => 'KS',
        'd' => 'D', 'r' => 'R', 'z' => 'Z', 's' => 'S', 'f' => 'F', 'v' => 'F', 'k' => 'K', 'q' => 'K', 'l' => 'L', 'm' => 'M', 'n' => 'N',
        'a' => '', 'e' => '', 'i' => '', 'o' => '', 'u' => '', 'y' => '', 'w' => ''];

    /** The search key of a text, as school.search_key gives it. */
    public static function key(?string $text): string
    {
        static $letters = null;
        if ($letters === null) {
            // œ Œ → oe, æ Æ → ae; then letter for letter; the two kinds of Arabic digits.
            $letters = [mb_chr(0x0153) => 'oe', mb_chr(0x0152) => 'oe', mb_chr(0xE6) => 'ae', mb_chr(0xC6) => 'ae'];
            foreach (self::SAME as $to => $from) foreach ($from as $code) $letters[mb_chr($code)] = mb_chr($to);
            for ($digit = 0; $digit < 10; $digit++) $letters[mb_chr(0x0660 + $digit)] = $letters[mb_chr(0x06F0 + $digit)] = (string) $digit;
        }
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}\x{06D6}-\x{06ED}]/u', '', (string) $text);
        $text = mb_strtolower(strtr($text, $letters));
        return trim((string) preg_replace("/[\\s'\\x{2019}\\x{02BC}.-]+/u", ' ', $text));
    }

    /**
     * @param array<string, ?string> $a a person's `last_name_lat`, `first_name_lat`, `last_name_ar`, `first_name_ar`
     * @return ?string `name` (the same name in the same alphabet), `other_alphabet`, or null when the names differ
     */
    public static function compare(array $a, array $b): ?string
    {
        $shared = array_filter(['lat', 'ar'], fn (string $alphabet) => self::written($a, $alphabet) && self::written($b, $alphabet));
        foreach ($shared as $alphabet) {
            $one = [self::compact($a['last_name_'.$alphabet]), self::compact($a['first_name_'.$alphabet])];
            $other = [self::compact($b['last_name_'.$alphabet]), self::compact($b['first_name_'.$alphabet])];
            // The fields swapped too: a family name typed as the first name.
            if ($one === $other || $one === array_reverse($other)) return 'name';
        }
        // Across alphabets only when the two have none in common: written in both, the same alphabet says more.
        if ($shared !== []) return null;
        foreach ([['lat', 'ar'], ['ar', 'lat']] as [$x, $y]) {
            if (!self::written($a, $x) || !self::written($b, $y)) continue;
            $one = [self::sounds($a['last_name_'.$x], $x), self::sounds($a['first_name_'.$x], $x)];
            $other = [self::sounds($b['last_name_'.$y], $y), self::sounds($b['first_name_'.$y], $y)];
            foreach ([$other, array_reverse($other)] as $candidate) {
                if (min(self::part($one[0], $candidate[0]), self::part($one[1], $candidate[1])) >= self::OTHER_ALPHABET) return 'other_alphabet';
            }
        }
        return null;
    }

    /**
     * A name's consonants by sound, word by word, in the same symbols for both alphabets.
     *
     * @return list<string>
     */
    public static function sounds(?string $name, string $alphabet): array
    {
        $words = [];
        foreach (explode(' ', self::key(str_replace(self::TA_MARBUTA, '', (string) $name))) as $word) {
            if ($alphabet === 'ar') {
                // The article: « القادر » is « قادر », as « Kader » writes it; « عبدالقادر » (ʿabd al-) too.
                $word = (string) preg_replace('/^(\x{0639}\x{0628}\x{062F})\x{0627}\x{0644}/u', '$1', $word);
                if (mb_strlen($word) > 3 && str_starts_with($word, self::ALEF_LAM)) $word = mb_substr($word, 2);
                $sound = implode('', array_map(fn (string $letter) => self::ARABIC[mb_ord($letter)] ?? (ctype_digit($letter) ? $letter : ''), mb_str_split($word)));
            } else {
                // « El Hadi » is « Hadi », as « الهادي » comes to it.
                if (in_array($word, ['el', 'al', 'l'], true)) continue;
                $sound = self::latin($word);
            }
            // A letter written twice sounds once: « Mohammed », « محمّد ».
            $sound = (string) preg_replace('/(.)\1+/', '$1', $sound);
            if ($word !== '') $words[] = $sound;
        }
        return $words;
    }

    private static function latin(string $word): string
    {
        $sound = '';
        for ($i = 0, $length = strlen($word); $i < $length;) {
            // « c » is « s » before e, i, y (« Hocine »), « k » elsewhere (« Camel »).
            if ($word[$i] === 'c' && !str_starts_with(substr($word, $i), 'ch') && !str_starts_with(substr($word, $i), 'ck')) {
                $sound .= in_array($word[$i + 1] ?? '', ['e', 'i', 'y'], true) ? 'S' : 'K';
                $i++;
                continue;
            }
            foreach ([3, 2, 1] as $size) {
                $piece = substr($word, $i, $size);
                if (strlen($piece) === $size && array_key_exists($piece, self::LATIN)) {
                    $sound .= self::LATIN[$piece];
                    $i += $size;
                    continue 2;
                }
            }
            $sound .= ctype_digit($word[$i]) ? $word[$i] : '';
            $i++;
        }
        return $sound;
    }

    /** One part of two names, by the share of their consonants in common; or one compound name holding the other (« Amine », « محمد أمين »). */
    private static function part(array $one, array $other): float
    {
        if ($one === [] || $other === []) return 0.0;
        $whole = self::similarity(implode('', $one), implode('', $other));
        [$short, $long] = count($one) <= count($other) ? [$one, $other] : [$other, $one];
        $held = 1.0;
        foreach ($short as $word) {
            $best = null;
            foreach ($long as $index => $candidate) {
                if ($best === null || self::similarity($word, $candidate) > $best[1]) $best = [$index, self::similarity($word, $candidate)];
            }
            unset($long[$best[0]]);
            $held = min($held, $best[1]);
        }
        return max($whole, $held);
    }

    private static function similarity(string $one, string $other): float
    {
        if ($one === '' || $other === '') return $one === $other ? 1.0 : 0.0;
        return 1 - levenshtein($one, $other) / max(strlen($one), strlen($other));
    }

    private static function written(array $person, string $alphabet): bool
    {
        return self::key($person['last_name_'.$alphabet] ?? null) !== '' && self::key($person['first_name_'.$alphabet] ?? null) !== '';
    }

    /** A part of a name as the search reads it, its spaces aside: « Ben Ali » is « Benali ». */
    private static function compact(?string $name): string
    {
        return str_replace(' ', '', self::key($name));
    }
}
