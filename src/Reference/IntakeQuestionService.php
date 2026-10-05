<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Audit\Audit;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * Intake questions (US-09): a coordinator adds, retires, restores and reorders the extra
 * questions asked at registration, marks them required or optional, and gives the choices
 * for Choice questions, with no release.
 *
 * Once a question has answers, its wording, answer type and choices are fixed, so every
 * stored answer keeps meaning what it meant when it was given; only the required flag and
 * the order can change. Retiring hides a question from new registrations and keeps its answers.
 */
final class IntakeQuestionService
{
    public const TYPES = ['Text', 'Number', 'Yes/No', 'Choice', 'Date'];
    public const FIELDS = ['prompt', 'answer_type', 'options', 'is_required'];
    /** Fields that may not change once the question has answers. */
    public const FIXED_ONCE_ANSWERED = ['prompt', 'answer_type', 'options'];

    /**
     * The questions to ask on the registration form, in order.
     * @return list<array{question_id:int,prompt:string,answer_type:string,options:list<string>,is_required:bool}>
     */
    public static function active(): array
    {
        return array_map(static fn(array $q): array => [
            'question_id' => (int) $q['question_id'],
            'prompt' => (string) $q['prompt'],
            'answer_type' => (string) $q['answer_type'],
            'options' => self::optionList($q['options']),
            'is_required' => (bool) $q['is_required'],
        ], IntakeQuestionRepository::active());
    }

    /** @throws ValidationException */
    public static function create(array $input, int $createdBy): int
    {
        $values = self::validate($input);
        return Db::transaction(function () use ($values, $createdBy): int {
            $id = IntakeQuestionRepository::insert($values + ['display_order' => IntakeQuestionRepository::nextOrder(), 'created_by' => $createdBy]);
            Audit::record('intake_question_create', 'intake_question', $id, details: ['prompt' => $values['prompt'],
                'answer_type' => $values['answer_type'], 'options' => self::optionList($values['options']), 'is_required' => $values['is_required']]);
            return $id;
        });
    }

    /** @throws ValidationException */
    public static function update(int $questionId, array $input): void
    {
        $before = self::existing($questionId);
        $values = self::validate($input);
        $before['options'] = self::encode(self::optionList($before['options'])); // MySQL re-formats stored JSON text
        $changes = Audit::diff($before, $values, self::FIELDS);
        if (!$changes) {
            return;
        }
        if (array_intersect_key($changes, array_flip(self::FIXED_ONCE_ANSWERED)) && IntakeQuestionRepository::hasAnswers($questionId)) {
            throw ValidationException::one('_form', 'This question has already been answered, so its wording, answer type and choices can no longer change. '
                . 'Only "required" can be changed. To ask something different, retire this question and add a new one.');
        }
        Db::transaction(function () use ($questionId, $values, $changes): void {
            IntakeQuestionRepository::update($questionId, $values);
            Audit::record('intake_question_update', 'intake_question', $questionId, changes: $changes);
        });
    }

    /** Allowed at any time, answered or not. @throws ValidationException */
    public static function setRequired(int $questionId, bool $required): void
    {
        $question = self::existing($questionId);
        if ((bool) $question['is_required'] === $required) {
            return;
        }
        Db::transaction(function () use ($questionId, $required): void {
            IntakeQuestionRepository::setRequired($questionId, $required);
            Audit::record('intake_question_update', 'intake_question', $questionId, changes: ['is_required' => [(int) !$required, (int) $required]]);
        });
    }

    /** Move an active question one place up or down. @throws ValidationException */
    public static function move(int $questionId, string $direction): void
    {
        $question = self::existing($questionId);
        if ($question['retired_at'] !== null) {
            throw ValidationException::one('_form', 'Restore this question before changing its place.');
        }
        [$neighbour, $newOrders] = LookupService::swap(IntakeQuestionRepository::active(), 'question_id', $questionId, $direction);
        if ($neighbour === null) {
            return;
        }
        Db::transaction(function () use ($questionId, $question, $direction, $neighbour, $newOrders): void {
            foreach ($newOrders as $id => $order) {
                IntakeQuestionRepository::setOrder($id, $order);
            }
            $changes = isset($newOrders[$questionId]) ? ['display_order' => [(int) $question['display_order'], $newOrders[$questionId]]] : [];
            Audit::record('intake_question_reorder', 'intake_question', $questionId,
                details: ['direction' => $direction, 'swapped_with' => $neighbour], changes: $changes);
        });
    }

    /** Stop asking the question. Its answers are kept. @throws ValidationException */
    public static function retire(int $questionId): void
    {
        $question = self::existing($questionId);
        if ($question['retired_at'] !== null) {
            return;
        }
        $now = Clock::db();
        Db::transaction(function () use ($questionId, $now): void {
            IntakeQuestionRepository::setRetiredAt($questionId, $now);
            Audit::record('intake_question_retire', 'intake_question', $questionId, changes: ['retired_at' => [null, $now]]);
        });
    }

    /** Ask a retired question again; it goes to the end of the list. @throws ValidationException */
    public static function restore(int $questionId): void
    {
        $question = self::existing($questionId);
        if ($question['retired_at'] === null) {
            return;
        }
        Db::transaction(function () use ($questionId, $question): void {
            $order = IntakeQuestionRepository::nextOrder();
            IntakeQuestionRepository::setRetiredAt($questionId, null);
            IntakeQuestionRepository::setOrder($questionId, $order);
            Audit::record('intake_question_restore', 'intake_question', $questionId,
                changes: ['retired_at' => [$question['retired_at'], null], 'display_order' => [(int) $question['display_order'], $order]]);
        });
    }

    /** @return list<string> the choices stored for a question (empty unless it is a Choice question) */
    public static function optionList(?string $json): array
    {
        $decoded = $json === null || $json === '' ? null : json_decode($json, true);
        return is_array($decoded) ? array_values(array_map('strval', array_filter($decoded, 'is_scalar'))) : [];
    }

    /** @return array{prompt:string,answer_type:string,options:?string,is_required:int} normalised values */
    private static function validate(array $input): array
    {
        $errors = [];
        $prompt = Validator::text(self::str($input['prompt'] ?? null), 255);
        if ($prompt === null) {
            $errors['prompt'] = 'Enter the question (up to 255 characters).';
        }
        $type = Validator::oneOf(self::str($input['answer_type'] ?? null), self::TYPES);
        if ($type === null) {
            $errors['answer_type'] = 'Choose the kind of answer this question takes.';
        }
        $options = null;
        if ($type === 'Choice') {
            $options = self::parseOptions($input['options'] ?? null, $errors);
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return ['prompt' => (string) $prompt, 'answer_type' => (string) $type, 'options' => $options, 'is_required' => empty($input['is_required']) ? 0 : 1];
    }

    /**
     * Choices typed one per line (or given as a list). Blank lines are ignored; each choice
     * is at most 100 characters, appears once, and there must be at least two.
     * @param array<string, string> $errors
     */
    private static function parseOptions(mixed $raw, array &$errors): ?string
    {
        $lines = is_array($raw) ? $raw : (preg_split('/\R/u', (string) (is_scalar($raw) ? $raw : '')) ?: []);
        $options = [];
        $seen = [];
        foreach ($lines as $line) {
            $line = is_scalar($line) ? (string) $line : '';
            if (trim($line) === '') {
                continue;
            }
            $text = Validator::text($line, 100);
            if ($text === null) {
                $errors['options'] = 'Each choice can be at most 100 characters.';
                return null;
            }
            $key = mb_strtolower($text);
            if (isset($seen[$key])) {
                $errors['options'] = "\"$text\" is listed more than once. Give each choice only once.";
                return null;
            }
            $seen[$key] = true;
            $options[] = $text;
        }
        if (count($options) < 2) {
            $errors['options'] = 'Give at least two choices, one per line.';
            return null;
        }
        return self::encode($options);
    }

    /** @param list<string> $options */
    private static function encode(array $options): ?string
    {
        return $options ? json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null;
    }

    private static function existing(int $questionId): array
    {
        return IntakeQuestionRepository::find($questionId) ?? throw ValidationException::one('_form', 'That question no longer exists.');
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
