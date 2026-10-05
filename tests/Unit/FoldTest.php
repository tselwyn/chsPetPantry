<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Text\Fold;

/** Accent fold v1 and phonetic key v1 (50-design §11.3): the rules behind the shared fixtures. */
final class FoldTest extends TestCase
{
    public function testFinalSigmaFoldsTheSameWayOnEveryPhp(): void
    {
        $this->assertSame("\u{03C3}\u{03B1}\u{03C3}", Fold::fold("\u{03A3}\u{0391}\u{03A3}"), 'ΣΑΣ');
        $this->assertSame("\u{03C3}\u{03B1}\u{03C3}", Fold::fold("\u{03C3}\u{03B1}\u{03C2}"), 'σας: a typed final sigma');
        $this->assertSame("\u{03C3}\u{03B1}\u{03C3} \u{03C3}\u{03B1}\u{03C3}", Fold::fold("\u{03A3}\u{0391}\u{03A3} \u{03A3}\u{0391}\u{03A3}"), 'at the end of every word');
        // The per-code-point step: a lone Σ has no context, so it lowers to σ on PHP 8.2 and on PHP 8.3, where
        // mb_strtolower('ΣΑΣ') of the whole string gives σας.
        foreach (mb_str_split("\u{03A3}\u{0391}\u{03A3}", 1, 'UTF-8') as $c) {
            $this->assertNotSame("\u{03C2}", mb_strtolower($c, 'UTF-8'));
        }
        $this->assertSame("\u{03C3}", mb_strtolower("\u{03A3}", 'UTF-8'));
    }

    public function testFixturesStayInTheUnicodeVersionBothEnginesShare(): void
    {
        // \p{L}, \p{N}, \p{Mn}, NFD and lower-casing come from each engine's own Unicode tables, so the fold is the same
        // in PHP and in the browsers only for characters both know with the same category: the fixtures pin that
        // repertoire and no case may leave it (a later letter such as U+A7CC folds differently on each side).
        $this->assertGreaterThanOrEqual(14, \IntlChar::getUnicodeVersion()[0], "this PHP's ICU knows Unicode 14");
        foreach (['accent_fold.json', 'phonetic_key.json'] as $name) {
            $fixture = json_decode((string) file_get_contents(__DIR__ . "/../fixtures/$name"), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('14.0', $fixture['unicode_version'] ?? null, "$name declares the repertoire its cases stay within");
            $checked = 0;
            foreach ($fixture['cases'] as $case) {
                foreach (mb_str_split($case['in'] . $case['out'], 1, 'UTF-8') as $c) {
                    $cp = (int) mb_ord($c, 'UTF-8');
                    $this->assertTrue(self::inUnicode14($cp), sprintf('%s: U+%04X in %s is not in Unicode 14.0', $name, $cp, json_encode($case['in'], JSON_THROW_ON_ERROR)));
                    $checked++;
                }
            }
            $this->assertGreaterThan(100, $checked, "$name has characters to check");
        }
        $this->assertTrue(self::inUnicode14(0x00F1) && self::inUnicode14(0x1E9E) && self::inUnicode14(0x0130), 'ñ, ẞ and İ are');
        $this->assertFalse(self::inUnicode14(0xA7CC), 'U+A7CC (a later letter) is not, on this ICU and on any later one');
        $this->assertFalse(self::inUnicode14(0x0378), 'nor is a code point still unassigned');
    }

    /** Assigned, and first assigned in Unicode 14.0 or earlier (unassigned code points have age 0.0). */
    private static function inUnicode14(int $cp): bool
    {
        $age = \IntlChar::charAge($cp);
        return \IntlChar::charType($cp) !== \IntlChar::CHAR_CATEGORY_UNASSIGNED && $age[0] >= 1 && ($age[0] < 14 || ($age[0] === 14 && $age[1] === 0));
    }

    public function testApostrophesAreRemovedNotSpaced(): void
    {
        foreach (["'", "\u{2019}", "\u{02BC}", '`', "\u{00B4}"] as $apostrophe) {
            $this->assertSame('obrien', Fold::fold("O{$apostrophe}Brien"), json_encode($apostrophe, JSON_THROW_ON_ERROR));
        }
        $this->assertSame('dangelo smith', Fold::fold("D'Angelo-Smith"), 'a hyphen is a space; an apostrophe is nothing');
        $this->assertSame('O165', Fold::phonetic("O'Brien"));
    }

    public function testInvalidUtf8FoldsToEmpty(): void
    {
        foreach (["\xFF", "Mu\xC3", "abc\xC0\xAF", "\xED\xA0\x80"] as $bytes) {
            $this->assertSame('', Fold::fold($bytes), bin2hex($bytes));
            $this->assertSame('', Fold::phonetic($bytes), bin2hex($bytes));
        }
        $this->assertSame('', Fold::fold(''));
    }

    public function testPhoneticDropsWordsWithoutLetters(): void
    {
        $this->assertSame('', Fold::phonetic('123'));
        $this->assertSame('A500 L120', Fold::phonetic('Ana 123 Lopez'));
        $this->assertSame('A500 L120', Fold::phonetic("Ana \u{1F600} Lopez"));
        $this->assertSame('L000', Fold::phonetic("\u{03A3}\u{0391}\u{03A3} Lee"), 'a word with no a-z letter after the fold');
        $this->assertSame('', Fold::phonetic(''));
    }

    public function testHAndWDoNotSeparate(): void
    {
        $this->assertSame('A261', Fold::phonetic('Ashcraft'));
        $this->assertSame('B000', Fold::phonetic('Bhb'));
        $this->assertSame('B000', Fold::phonetic('Bwb'));
    }

    public function testVowelsSeparate(): void
    {
        $this->assertSame('T522', Fold::phonetic('Tymczak'));
        $this->assertSame('B100', Fold::phonetic('Bab'));
    }

    public function testTheFirstLetterSuppressesItsCode(): void
    {
        $this->assertSame('P236', Fold::phonetic('Pfister'));
        $this->assertSame('B000', Fold::phonetic('Bb'));
    }
}
