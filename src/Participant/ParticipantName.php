<?php
declare(strict_types=1);

namespace Pfpms\Participant;

/**
 * How a participant is named on screen (US-05): the preferred name first wherever one is recorded, so nobody is asked
 * to answer to a name they do not use; the legal name stays available beside it. Takes a row with legal_first_name,
 * legal_last_name and preferred_name.
 */
final class ParticipantName
{
    /** The name to show first: the preferred name, else "First Last". */
    public static function display(array $p): string
    {
        $preferred = trim((string) ($p['preferred_name'] ?? ''));
        return $preferred !== '' ? $preferred : self::legal($p);
    }

    /** "First Last" from the legal names. */
    public static function legal(array $p): string
    {
        return trim($p['legal_first_name'] . ' ' . $p['legal_last_name']);
    }

    /** The legal name when it is not what display() shows (so it can be shown beside it), else null. */
    public static function legalIfDifferent(array $p): ?string
    {
        $legal = self::legal($p);
        return self::display($p) !== $legal ? $legal : null;
    }
}
