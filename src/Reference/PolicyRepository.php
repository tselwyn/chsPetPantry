<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Db;

/** SQL for the policy_document table. Prepared statements only; returns plain arrays. */
final class PolicyRepository
{
    public const COLUMNS = 'd.document_id, d.doc_type, d.version, d.language_code, l.name AS language_name, d.effective_from';

    /** 1 when a staff acknowledgement or a participant consent refers to the document. */
    private const USED = '(EXISTS (SELECT 1 FROM policy_acknowledgement a WHERE a.document_id = d.document_id)
                          OR EXISTS (SELECT 1 FROM participant_consent c WHERE c.document_id = d.document_id))';

    /** Every document without its text, newest first within each type. @return list<array> */
    public static function all(): array
    {
        return Db::pdo()->query(
            'SELECT ' . self::COLUMNS . ', ' . self::USED . ' AS is_used
               FROM policy_document d JOIN `language` l ON l.language_code = d.language_code
              ORDER BY d.doc_type, d.effective_from DESC, d.version DESC, d.language_code'
        )->fetchAll();
    }

    public static function find(int $documentId): ?array
    {
        $st = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ', d.body FROM policy_document d JOIN `language` l ON l.language_code = d.language_code
              WHERE d.document_id = ?'
        );
        $st->execute([$documentId]);
        return $st->fetch() ?: null;
    }

    /**
     * Read the document and lock its row until the transaction ends. Recording an
     * acknowledgement or consent checks this row (foreign key), so it waits until then.
     */
    public static function lock(int $documentId): ?array
    {
        $st = Db::pdo()->prepare('SELECT document_id, body, effective_from FROM policy_document WHERE document_id = ? FOR UPDATE');
        $st->execute([$documentId]);
        return $st->fetch() ?: null;
    }

    /** @return array{acknowledgements: int, consents: int} */
    public static function usage(int $documentId): array
    {
        $st = Db::pdo()->prepare(
            'SELECT (SELECT COUNT(*) FROM policy_acknowledgement WHERE document_id = ?) AS acknowledgements,
                    (SELECT COUNT(*) FROM participant_consent WHERE document_id = ?) AS consents'
        );
        $st->execute([$documentId, $documentId]);
        $row = $st->fetch();
        return ['acknowledgements' => (int) $row['acknowledgements'], 'consents' => (int) $row['consents']];
    }

    public static function isUsed(int $documentId): bool
    {
        $usage = self::usage($documentId);
        return $usage['acknowledgements'] + $usage['consents'] > 0;
    }

    public static function exists(string $docType, string $version, string $languageCode): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM policy_document WHERE doc_type = ? AND version = ? AND language_code = ?');
        $st->execute([$docType, $version, $languageCode]);
        return (int) $st->fetchColumn() > 0;
    }

    /** @param array<string, string> $values */
    public static function insert(array $values): int
    {
        Db::pdo()->prepare('INSERT INTO policy_document (doc_type, version, language_code, body, effective_from) VALUES (?, ?, ?, ?, ?)')
            ->execute([$values['doc_type'], $values['version'], $values['language_code'], $values['body'], $values['effective_from']]);
        return (int) Db::pdo()->lastInsertId();
    }

    public static function update(int $documentId, string $body, string $effectiveFrom): void
    {
        Db::pdo()->prepare('UPDATE policy_document SET body = ?, effective_from = ? WHERE document_id = ?')
            ->execute([$body, $effectiveFrom, $documentId]);
    }
}
