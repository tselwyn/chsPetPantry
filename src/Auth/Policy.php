<?php
declare(strict_types=1);

namespace Pfpms\Auth;

use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Settings;

/**
 * Versioned policy texts (policy_document) and staff acknowledgements.
 * US-03: the confidentiality agreement is shown before the home screen on first login,
 * and the version and date accepted are stored. US-30 (yearly re-acknowledgement) uses
 * policy_acknowledgement.due_again_on.
 *
 * Start dates and due dates are organisation dates (Clock::orgToday), not UTC dates: in the
 * evening in the Americas the UTC date is already tomorrow.
 */
final class Policy
{
    public const CONFIDENTIALITY = 'Confidentiality Agreement';

    private const COLUMNS = 'document_id, doc_type, version, language_code, body, effective_from';

    /**
     * The text in force today, in the preferred language when a translation of that version exists.
     *
     * The default-language text decides which VERSION is in force (the latest one whose
     * effective_from has passed); translations follow it. So a stray translation of an old
     * version, however it is dated, can never replace a newer version. If no default-language
     * text is in force yet, the latest text in the preferred language is used.
     */
    public static function current(string $docType, ?string $language = null): ?array
    {
        $default = Settings::string('default_language', 'en');
        $language ??= $default;
        $today = Clock::orgToday();
        $pdo = Db::pdo();

        $st = $pdo->prepare('SELECT version FROM policy_document WHERE doc_type = ? AND language_code = ? AND effective_from <= ?
                              ORDER BY effective_from DESC, document_id DESC LIMIT 1');
        $st->execute([$docType, $default, $today]);
        $version = $st->fetchColumn();

        if ($version === false) {
            $st = $pdo->prepare('SELECT ' . self::COLUMNS . ' FROM policy_document WHERE doc_type = ? AND language_code = ? AND effective_from <= ?
                                  ORDER BY effective_from DESC, document_id DESC LIMIT 1');
            $st->execute([$docType, $language, $today]);
            return $st->fetch() ?: null;
        }
        $st = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM policy_document
              WHERE doc_type = ? AND version = ? AND (language_code = ? OR (language_code = ? AND effective_from <= ?))
              ORDER BY (language_code = ?) DESC LIMIT 1'
        );
        $st->execute([$docType, $version, $default, $language, $today, $language]);
        return $st->fetch() ?: null;
    }

    /** Fingerprint of the exact wording shown, so an acceptance can be matched to what was read. */
    public static function fingerprint(array $doc): string
    {
        return hash('sha256', $doc['document_id'] . "\n" . $doc['body']);
    }

    /**
     * True when the confidentiality agreement in force has not been accepted by this user
     * (in any language), or the acceptance is due again. With no agreement configured there is
     * nothing to accept, and the home screen tells Administrators to add one.
     */
    public static function acknowledgementRequired(array $user): bool
    {
        $doc = self::current(self::CONFIDENTIALITY);
        if ($doc === null) {
            return false;
        }
        $due = self::latestDue((int) $user['user_id'], $doc);
        return $due === null || $due <= Clock::orgToday();
    }

    /** The organisation date from which the current agreement must be accepted again (D-19 cap), or null: no agreement, not
     *  accepted (the gate applies first), or accepted with no due date (policy_reack_days = 0). */
    public static function dueAgainOn(array $user): ?string
    {
        $doc = self::current(self::CONFIDENTIALITY);
        $due = $doc === null ? null : self::latestDue((int) $user['user_id'], $doc);
        return $due === null || $due === '9999-12-31' ? null : $due;
    }

    /** The organisation date on which a later version of the agreement takes effect (D-19 cap: from that day the person must
     *  accept it before the server lets them work), or null. Versions are decided by the default-language texts, as in current(). */
    public static function nextVersionFrom(): ?string
    {
        $current = self::current(self::CONFIDENTIALITY);
        $st = Db::pdo()->prepare(
            'SELECT MIN(effective_from) FROM policy_document WHERE doc_type = ? AND language_code = ? AND effective_from > ? AND version <> ?'
        );
        $st->execute([self::CONFIDENTIALITY, Settings::string('default_language', 'en'), Clock::orgToday(), $current === null ? '' : (string) $current['version']]);
        $from = $st->fetchColumn();
        return $from === false || $from === null ? null : (string) $from;
    }

    /** MAX(due_again_on) over this person's acceptances of the current version ('9999-12-31' for "never due"), or null. */
    private static function latestDue(int $userId, array $doc): ?string
    {
        $st = Db::pdo()->prepare(
            "SELECT MAX(COALESCE(a.due_again_on, '9999-12-31')) FROM policy_acknowledgement a
               JOIN policy_document d ON d.document_id = a.document_id
              WHERE a.user_id = ? AND d.doc_type = ? AND d.version = ?"
        );
        $st->execute([$userId, $doc['doc_type'], $doc['version']]);
        $due = $st->fetchColumn();
        return $due === false || $due === null ? null : (string) $due;
    }

    public static function acknowledge(int $userId, int $documentId): void
    {
        $days = Settings::int('policy_reack_days', 365);
        Db::pdo()->prepare('INSERT INTO policy_acknowledgement (user_id, document_id, acknowledged_at, due_again_on) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $documentId, Clock::db(), $days > 0 ? Clock::orgDate("+$days days") : null]);
    }
}
