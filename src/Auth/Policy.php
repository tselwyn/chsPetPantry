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
 */
final class Policy
{
    public const CONFIDENTIALITY = 'Confidentiality Agreement';

    /** The version in force today, in the preferred language if it exists, else the default language. */
    public static function current(string $docType, ?string $language = null): ?array
    {
        $default = Settings::string('default_language', 'en');
        $st = Db::pdo()->prepare(
            'SELECT document_id, doc_type, version, language_code, body, effective_from FROM policy_document
              WHERE doc_type = ? AND effective_from <= ? AND language_code IN (?, ?)
              ORDER BY effective_from DESC, (language_code = ?) DESC, document_id DESC LIMIT 1'
        );
        $st->execute([$docType, Clock::now()->format('Y-m-d'), $language ?? $default, $default, $language ?? $default]);
        return $st->fetch() ?: null;
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
        $st = Db::pdo()->prepare(
            'SELECT MAX(COALESCE(a.due_again_on, \'9999-12-31\')) FROM policy_acknowledgement a
               JOIN policy_document d ON d.document_id = a.document_id
              WHERE a.user_id = ? AND d.doc_type = ? AND d.version = ?'
        );
        $st->execute([$user['user_id'], $doc['doc_type'], $doc['version']]);
        $due = $st->fetchColumn();
        return $due === null || $due === false || $due <= Clock::now()->format('Y-m-d');
    }

    public static function acknowledge(int $userId, int $documentId): void
    {
        $days = Settings::int('policy_reack_days', 365);
        Db::pdo()->prepare('INSERT INTO policy_acknowledgement (user_id, document_id, acknowledged_at, due_again_on) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $documentId, Clock::db(), $days > 0 ? Clock::now()->modify("+$days days")->format('Y-m-d') : null]);
    }
}
