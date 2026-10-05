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
    /**
     * The name to show first: a one-word preferred name with the legal surname ("Bobby" → "Bobby Smith"); a preferred
     * name of more than one word as it is, since it already carries a surname ("Alex Rivera"); else "First Last".
     */
    public static function display(array $p): string
    {
        $preferred = trim((string) preg_replace('/\s+/u', ' ', (string) ($p['preferred_name'] ?? '')));
        if ($preferred === '') {
            return self::legal($p);
        }
        return str_contains($preferred, ' ') ? $preferred : trim($preferred . ' ' . $p['legal_last_name']);
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
