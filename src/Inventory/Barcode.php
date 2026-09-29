<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

/**
 * Product barcodes (US-16), in one canonical form so a code linked at one site is recognised at
 * every site whatever the scanner sends. No database access: the offline Station ports this
 * exactly (tests/Unit/BarcodeTest.php holds the cases both must agree on).
 *
 * - GS1 retail codes (UPC-A 12, EAN-13 13, GTIN-14 14 digits, EAN-8 and UPC-E 8 digits) must have
 *   a correct check digit and are stored as the 14-digit GTIN, zero-padded; a UPC-E code is
 *   expanded to its UPC-A first. The same bag scanned as UPC-A or as EAN-13 gives the same code.
 * - Refused: case codes (GTIN-14 with an indicator digit 1-9, which stand for a box of units),
 *   restricted-circulation and variable-measure codes (prefix 02 and 2, often carrying a price or
 *   weight that changes from bag to bag) and coupons (05): they do not identify one product.
 *   In-store codes (04, and EAN-8 codes starting 0 or 2, the EAN-8 restricted-circulation range)
 *   are accepted but may mean a different product elsewhere (storeSpecific). An all-zero code is
 *   refused.
 * - The pantry's own labels: letters, digits and . _ / - (at least one letter), up to 32
 *   characters, stored in capitals. An all-digit code that is not a valid GS1 code is refused, so
 *   a mis-scan with a digit missing is caught instead of being linked.
 * - A GS1 element string with the GTIN application identifier, (01) followed by 14 digits, is read
 *   as that GTIN. The scanner's AIM prefix ]E4 means EAN-8 and settles an 8-digit code.
 * - Never a participant card (PFPMS:... or the P<number> code) or a provisional registration slip
 *   (T<site>-<device>-<seq>): a person's identifier must never be linked to food.
 * - An 8-digit code valid both as UPC-E and as EAN-8 has two readings: linking checks both, so one
 *   printed code can never belong to two products, and a lookup tries both.
 */
final class Barcode
{
    /**
     * @param ?string $formatHint the decoder's format when known ('ean_8', 'upc_e', ...), which
     *   settles whether an 8-digit code is EAN-8 or UPC-E; a keyboard-wedge scanner gives none
     * @return ?string the canonical code, or null when it is not a product barcode we can use
     */
    public static function normalise(?string $raw, ?string $formatHint = null): ?string
    {
        return self::candidates($raw, $formatHint)[0] ?? null;
    }

    /**
     * Every canonical reading of a scan, best first. Only an 8-digit code that is valid both as
     * EAN-8 and as UPC-E has two; a lookup tries both.
     * @return list<string>
     */
    public static function candidates(?string $raw, ?string $formatHint = null): array
    {
        if ($raw === null) {
            return [];
        }
        $s = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $raw)); // wedge scanners add CR/LF/Tab
        if (preg_match('/^\]([A-Za-z])([0-9A-Za-z])/', $s, $aim)) {        // AIM symbology prefix, e.g. ]E0
            $formatHint ??= strtoupper($aim[1] . $aim[2]) === 'E4' ? 'ean_8' : null;
            $s = substr($s, 3);
        }
        if (stripos($s, 'PFPMS:') === 0 || preg_match('/^T\d+-D?\d+-\d+$/i', $s) || preg_match('/^P\d+$/i', $s)) {
            return []; // a participant card or a provisional registration slip
        }
        $digits = str_replace([' ', '-', '(', ')'], '', $s);
        if (strlen($digits) === 16 && str_starts_with($digits, '01') && ctype_digit($digits)) {
            $digits = substr($digits, 2); // GS1 element string: (01) followed by the GTIN-14
        }
        if (preg_match('/^\d+$/', $digits)) {
            return array_values(array_unique(array_filter(array_map([self::class, 'retail'], self::readings($digits, $formatHint)))));
        }
        $code = strtoupper($s);
        return preg_match('/^[A-Z0-9._\/-]{1,32}$/', $code) && preg_match('/[A-Z]/', $code) ? [$code] : [];
    }

    /**
     * An in-store code: accepted, but it may stand for a different product at another shop. UPC/EAN
     * prefix 04, or an EAN-8 (stored as 000000 + 8 digits) whose first digit is 0 or 2.
     */
    public static function storeSpecific(string $canonical): bool
    {
        if (strlen($canonical) !== 14 || !ctype_digit($canonical)) {
            return false;
        }
        return str_starts_with($canonical, '004') || (str_starts_with($canonical, '000000') && in_array($canonical[6], ['0', '2'], true));
    }

    /** The code as printed under the bars: 8 digits for EAN-8, 12 for UPC-A, 13 for EAN-13. */
    public static function display(string $canonical): string
    {
        if (strlen($canonical) !== 14 || !ctype_digit($canonical)) {
            return $canonical;
        }
        return match (true) {
            str_starts_with($canonical, '000000') => substr($canonical, 6),
            str_starts_with($canonical, '00') => substr($canonical, 2),
            str_starts_with($canonical, '0') => substr($canonical, 1),
            default => $canonical,
        };
    }

    /** GS1 mod-10 over all digits, including the check digit (the last one). */
    public static function checkDigitOk(string $digits): bool
    {
        if (strlen($digits) < 2 || !ctype_digit($digits)) {
            return false;
        }
        $body = substr($digits, 0, -1);
        $sum = 0;
        $weight = 3;
        for ($i = strlen($body) - 1; $i >= 0; $i--, $weight = 4 - $weight) {
            $sum += (int) $body[$i] * $weight;
        }
        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }

    /** UPC-E (number system, 6 digits, check digit) → the 12-digit UPC-A it stands for. */
    public static function expandUpcE(string $upcE): string
    {
        [$ns, $x, $check] = [$upcE[0], substr($upcE, 1, 6), $upcE[7]];
        $last = $x[5];
        $body = match (true) {
            $last <= '2' => $x[0] . $x[1] . $last . '0000' . $x[2] . $x[3] . $x[4],
            $last === '3' => $x[0] . $x[1] . $x[2] . '00000' . $x[3] . $x[4],
            $last === '4' => $x[0] . $x[1] . $x[2] . $x[3] . '00000' . $x[4],
            default => $x[0] . $x[1] . $x[2] . $x[3] . $x[4] . '0000' . $last,
        };
        return $ns . $body . $check;
    }

    /** @return list<string> the digit strings a scan could be, before the GS1 checks */
    private static function readings(string $digits, ?string $formatHint): array
    {
        if (strlen($digits) !== 8) {
            return in_array(strlen($digits), [12, 13, 14], true) ? [$digits] : [];
        }
        $upcA = ($digits[0] === '0' || $digits[0] === '1') ? self::expandUpcE($digits) : null;
        $asUpcE = $upcA !== null && self::checkDigitOk($upcA) ? $upcA : null;
        $asEan8 = self::checkDigitOk($digits) ? $digits : null;
        return match ($formatHint) {
            'upc_e' => array_filter([$asUpcE]),
            'ean_8' => array_filter([$asEan8]),
            default => array_values(array_filter([$asUpcE, $asEan8])), // UPC-E first: US products
        };
    }

    /** A digit string with a correct check digit → the 14-digit GTIN, or null when refused. */
    private static function retail(string $digits): ?string
    {
        if (!self::checkDigitOk($digits)) {
            return null;
        }
        $gtin = str_pad($digits, 14, '0', STR_PAD_LEFT);
        if (trim($gtin, '0') === '') {
            return null; // all zeros: a blank or test code, never a product
        }
        if ($gtin[0] !== '0') {
            return null; // a case of units (GTIN-14 indicator 1-9)
        }
        $ean13 = substr($gtin, 1);
        if (str_starts_with($ean13, '02') || str_starts_with($ean13, '05') || str_starts_with($ean13, '2')) {
            return null; // restricted circulation, variable measure or coupon
        }
        return $gtin;
    }
}
