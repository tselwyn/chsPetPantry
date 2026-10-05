<?php
declare(strict_types=1);

namespace Pfpms\Text;

use Normalizer;

/**
 * Accent fold v1 and phonetic key v1 (docs/design/50-design-station.md §11.3), the twins of the Station's
 * js/fold.js: tests/fixtures/accent_fold.json and phonetic_key.json hold the cases both must give.
 * Lower-casing is per code point and ς is then mapped to σ, so PHP 8.2, PHP 8.3 (final-sigma casing of whole
 * strings) and JavaScript agree.
 *
 * That parity holds within the Unicode version both engines share, and only there: \p{L}, \p{N}, \p{Mn}, NFD and
 * lower-casing come from each engine's tables (PHP 8.2.4: PCRE2 10.40 and ICU 71, Unicode 14; a later PHP or the
 * tablets' browsers: Unicode 15-17). A letter added later (U+A7CC) is a space here and a letter there, and a
 * character whose category changed (U+1171E: Mn in Unicode 14, Mc in 17) is removed here and splits the word there.
 * The fixtures stay within Unicode 14 (their unicode_version); P3 must not compare keys made on different engines for
 * other text.
 *
 * The fold, exactly: (1) NFC then NFD; (2) delete \p{Mn}; (3) the 22-entry MAP; (4) lower-case per code point;
 * (5) ς → σ; (6) delete ' U+2019 U+02BC ` U+00B4; (7) runs of [^\p{L}\p{N}] → one space, trimmed. Input that is
 * not valid UTF-8 folds to ''. The phonetic key is American Soundex of each folded word's a-z letters.
 */
final class Fold
{
    private const MAP = [
        'ß' => 'ss', 'ẞ' => 'ss', 'æ' => 'ae', 'Æ' => 'ae', 'ø' => 'o', 'Ø' => 'o', 'œ' => 'oe', 'Œ' => 'oe',
        'ł' => 'l', 'Ł' => 'l', 'đ' => 'd', 'Đ' => 'd', 'ð' => 'd', 'Ð' => 'd', 'þ' => 'th', 'Þ' => 'th',
        'ı' => 'i', 'ħ' => 'h', 'Ħ' => 'h', 'ŋ' => 'n', 'Ŋ' => 'n', 'ſ' => 's',
    ];
    private const CODES = ['b' => 1, 'f' => 1, 'p' => 1, 'v' => 1, 'c' => 2, 'g' => 2, 'j' => 2, 'k' => 2, 'q' => 2, 's' => 2,
        'x' => 2, 'z' => 2, 'd' => 3, 't' => 3, 'l' => 4, 'm' => 5, 'n' => 5, 'r' => 6,
        'a' => 0, 'e' => 0, 'i' => 0, 'o' => 0, 'u' => 0, 'y' => 0]; // h and w are absent: they do not separate equal codes

    /** Accent fold v1 of $text (e.g. "Muñoz" → "munoz", "D'Angelo-Smith" → "dangelo smith"); '' when it is not valid UTF-8. */
    public static function fold(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return '';
        }
        $s = Normalizer::normalize((string) Normalizer::normalize($text, Normalizer::FORM_C), Normalizer::FORM_D);
        if ($s === false) {
            return '';
        }
        $s = (string) preg_replace('/\p{Mn}/u', '', $s);
        $s = strtr($s, self::MAP);
        $s = implode('', array_map(static fn(string $c): string => mb_strtolower($c, 'UTF-8'), mb_str_split($s, 1, 'UTF-8')));
        $s = str_replace("\u{03C2}", "\u{03C3}", $s);
        $s = str_replace(["'", "\u{2019}", "\u{02BC}", '`', "\u{00B4}"], '', $s);
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s), ' ');
    }

    /** Phonetic key v1: the Soundex code of each folded word, joined by one space; words with no a-z letter are dropped. */
    public static function phonetic(string $text): string
    {
        $words = [];
        foreach (explode(' ', self::fold($text)) as $word) {
            $code = self::soundex($word);
            if ($code !== '') {
                $words[] = $code;
            }
        }
        return implode(' ', $words);
    }

    private static function soundex(string $word): string
    {
        $w = (string) preg_replace('/[^a-z]/', '', $word);
        if ($w === '') {
            return '';
        }
        $out = strtoupper($w[0]);
        $last = self::CODES[$w[0]] ?? 0;
        for ($i = 1, $n = strlen($w); $i < $n && strlen($out) < 4; $i++) {
            $code = self::CODES[$w[$i]] ?? null;
            if ($code === null) {
                continue; // h, w
            }
            if ($code !== 0 && $code !== $last) {
                $out .= $code;
            }
            $last = $code;
        }
        return str_pad($out, 4, '0');
    }
}
