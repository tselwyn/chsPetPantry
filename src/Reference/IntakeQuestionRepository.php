<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Db;

/** SQL for intake_question (US-09). Prepared statements only; returns plain arrays. options is JSON text, never queried with JSON operators. */
final class IntakeQuestionRepository
{
    public const COLUMNS = 'q.question_id, q.prompt, q.answer_type, q.options, q.is_required, q.display_order, q.retired_at, q.created_by';

    private const ANSWER_COUNT = '(SELECT COUNT(*) FROM intake_answer a WHERE a.question_id = q.question_id) AS answer_count';

    /** @return list<array> questions in use, in the order they are asked */
    public static function active(): array
    {
        return Db::pdo()->query('SELECT ' . self::COLUMNS . ', ' . self::ANSWER_COUNT . ' FROM intake_question q
                                 WHERE q.retired_at IS NULL ORDER BY q.display_order, q.question_id')->fetchAll();
    }

    /** @return list<array> retired questions, most recently retired first */
    public static function retired(): array
    {
        return Db::pdo()->query('SELECT ' . self::COLUMNS . ', ' . self::ANSWER_COUNT . ' FROM intake_question q
                                 WHERE q.retired_at IS NOT NULL ORDER BY q.retired_at DESC, q.question_id')->fetchAll();
    }

    public static function find(int $questionId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ', ' . self::ANSWER_COUNT . ' FROM intake_question q WHERE q.question_id = ?');
        $st->execute([$questionId]);
        return $st->fetch() ?: null;
    }

    public static function hasAnswers(int $questionId): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM intake_answer WHERE question_id = ?');
        $st->execute([$questionId]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function nextOrder(): int
    {
        return (int) Db::pdo()->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM intake_question WHERE retired_at IS NULL')->fetchColumn();
    }

    /** @param array<string, mixed> $values */
    public static function insert(array $values): int
    {
        Db::pdo()->prepare('INSERT INTO intake_question (prompt, answer_type, options, is_required, display_order, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$values['prompt'], $values['answer_type'], $values['options'], $values['is_required'], $values['display_order'], $values['created_by']]);
        return (int) Db::pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $values */
    public static function update(int $questionId, array $values): void
    {
        Db::pdo()->prepare('UPDATE intake_question SET prompt = ?, answer_type = ?, options = ?, is_required = ? WHERE question_id = ?')
            ->execute([$values['prompt'], $values['answer_type'], $values['options'], $values['is_required'], $questionId]);
    }

    public static function setRequired(int $questionId, bool $required): void
    {
        Db::pdo()->prepare('UPDATE intake_question SET is_required = ? WHERE question_id = ?')->execute([$required ? 1 : 0, $questionId]);
    }

    public static function setOrder(int $questionId, int $displayOrder): void
    {
        Db::pdo()->prepare('UPDATE intake_question SET display_order = ? WHERE question_id = ?')->execute([$displayOrder, $questionId]);
    }

    public static function setRetiredAt(int $questionId, ?string $retiredAt): void
    {
        Db::pdo()->prepare('UPDATE intake_question SET retired_at = ? WHERE question_id = ?')->execute([$retiredAt, $questionId]);
    }
}
