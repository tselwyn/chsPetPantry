<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use PDOException;
use Pfpms\Audit\Audit;
use Pfpms\Auth\Policy;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Settings;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * Versioned policy texts (plan P2A; US-03, US-25, US-30): one row per type × version ×
 * language. The version in force for a language is the one with the latest start date on
 * or before today (Policy::current), falling back to the default language.
 *
 * A text that someone has accepted (policy_acknowledgement) or that a participant agreed to
 * (participant_consent) is the record of what they agreed to, so it can never change after
 * that: a change of wording is a new version.
 */
final class PolicyService
{
    public const TYPES = ['Confidentiality Agreement', 'Programme Consent', 'Retention Notice', 'SNV Explanation'];
    public const FIELDS = ['doc_type', 'version', 'language_code', 'effective_from', 'body'];
    public const EDITABLE = ['effective_from', 'body'];

    private const LOCKED = 'This text has already been accepted by staff or agreed to by a participant, so it can no longer be changed. '
        . 'To change the wording, create a new version.';

    /** @throws ValidationException */
    public static function create(array $input): int
    {
        $values = self::validateNew($input);
        try {
            return Db::transaction(function () use ($values): int {
                $id = PolicyRepository::insert($values);
                Audit::record('policy_create', 'policy_document', $id, details: ['doc_type' => $values['doc_type'],
                    'version' => $values['version'], 'language_code' => $values['language_code'], 'effective_from' => $values['effective_from']]);
                return $id;
            });
        } catch (PDOException $e) {
            if (Db::isDuplicateKey($e)) { // added by someone else a moment ago
                throw ValidationException::one('version', self::duplicateMessage($values));
            }
            throw $e;
        }
    }

    /**
     * Change the text or start date of a version nobody has used yet.
     * @throws ValidationException
     */
    public static function update(int $documentId, array $input): void
    {
        $before = PolicyRepository::find($documentId) ?? throw ValidationException::one('_form', 'That policy text no longer exists.');
        if (PolicyRepository::isUsed($documentId)) {
            throw ValidationException::one('_form', self::LOCKED);
        }
        $errors = [];
        $values = self::validateEditable($input, $errors);
        if ($errors) {
            throw new ValidationException($errors);
        }
        if (!Audit::diff($before, $values, self::EDITABLE)) {
            return;
        }
        Db::transaction(function () use ($documentId, $before, $values): void {
            // Lock first, then check again: an acknowledgement recorded meanwhile must win.
            $current = PolicyRepository::lock($documentId) ?? throw ValidationException::one('_form', 'That policy text no longer exists.');
            if (PolicyRepository::isUsed($documentId)) {
                throw ValidationException::one('_form', self::LOCKED);
            }
            $changes = Audit::diff($current, $values, self::EDITABLE);
            if (!$changes) {
                return;
            }
            PolicyRepository::update($documentId, $values['body'], $values['effective_from']);
            Audit::record('policy_update', 'policy_document', $documentId, details: ['doc_type' => $before['doc_type'],
                'version' => $before['version'], 'language_code' => $before['language_code']], changes: $changes);
        });
    }

    /**
     * Every type with its documents, each marked 'in_force', 'scheduled' (starts later) or
     * 'replaced', plus what each active language is shown today.
     * @return list<array{doc_type: string, documents: list<array>, shown: list<array>}>
     */
    public static function overview(): array
    {
        $today = Clock::orgToday();
        $names = [];
        foreach (LanguageRepository::all() as $language) {
            $names[$language['language_code']] = $language['name'];
        }
        $activeCodes = array_column(LanguageRepository::active(), 'language_code');
        $documents = PolicyRepository::all();
        $overview = [];
        foreach (self::TYPES as $type) {
            $rows = array_values(array_filter($documents, fn(array $d): bool => $d['doc_type'] === $type));
            $inForce = [];
            $shown = [];
            $codes = array_values(array_unique(array_merge($activeCodes, array_column($rows, 'language_code'))));
            foreach ($codes as $code) {
                $current = Policy::current($type, $code);
                if ($current !== null && $current['language_code'] === $code) {
                    $inForce[(int) $current['document_id']] = true;
                }
                if (in_array($code, $activeCodes, true)) {
                    $shown[] = ['language_code' => $code, 'language_name' => $names[$code] ?? $code, 'document' => $current,
                        'document_language_name' => $current !== null ? ($names[$current['language_code']] ?? $current['language_code']) : null,
                        'fallback' => $current !== null && $current['language_code'] !== $code];
                }
            }
            foreach ($rows as $i => $row) {
                $rows[$i]['is_used'] = (bool) $row['is_used'];
                $rows[$i]['status'] = isset($inForce[(int) $row['document_id']]) ? 'in_force'
                    : ($row['effective_from'] > $today ? 'scheduled' : 'replaced');
            }
            $overview[] = ['doc_type' => $type, 'documents' => $rows, 'shown' => $shown];
        }
        return $overview;
    }

    /** Starting values for a brand-new text. @return array<string, string> */
    public static function blank(?string $docType): array
    {
        return ['doc_type' => Validator::oneOf($docType, self::TYPES) ?? self::TYPES[0], 'version' => '',
            'language_code' => Settings::string('default_language', 'en'), 'effective_from' => Clock::orgToday(), 'body' => ''];
    }

    /**
     * Starting values copied from an existing text: a translation keeps the type, version and
     * start date and needs a language; a new version keeps the language and suggests the
     * next version number.
     * @return array<string, string>
     */
    public static function prefill(array $source, string $as): array
    {
        if ($as === 'translation') {
            return ['doc_type' => $source['doc_type'], 'version' => $source['version'], 'language_code' => '',
                'effective_from' => $source['effective_from'], 'body' => $source['body']];
        }
        return ['doc_type' => $source['doc_type'], 'version' => self::nextVersion($source['version']),
            'language_code' => $source['language_code'], 'effective_from' => '', 'body' => $source['body']];
    }

    /** "1" → "2", "2026.1" → "2026.2", "v9" → "v10"; '' when there is no number to count on from. */
    public static function nextVersion(string $version): string
    {
        if (!preg_match('/^(.*?)(\d+)$/', $version, $m)) {
            return '';
        }
        $next = $m[1] . ((int) $m[2] + 1);
        return mb_strlen($next) <= 10 ? $next : '';
    }

    /** @return array<string, string> normalised values */
    private static function validateNew(array $input): array
    {
        $errors = [];
        $type = Validator::oneOf($input['doc_type'] ?? null, self::TYPES);
        if ($type === null) {
            $errors['doc_type'] = 'Choose which kind of text this is.';
        }
        $version = Validator::text($input['version'] ?? null, 10);
        if ($version === null) {
            $errors['version'] = 'Enter a version of up to 10 characters, such as 1, 2 or 2026.1.';
        }
        $code = trim((string) ($input['language_code'] ?? ''));
        $language = $code === '' ? null : LanguageRepository::find($code);
        if ($language === null || !$language['is_active']) {
            $errors['language_code'] = 'Choose the language this text is written in.';
            $language = null;
        }
        $values = ['doc_type' => (string) $type, 'version' => (string) $version,
            'language_code' => $language['language_code'] ?? '', 'language_name' => $language['name'] ?? '']
            + self::validateEditable($input, $errors);
        if ($type !== null && $version !== null && $language !== null && PolicyRepository::exists($type, $version, $language['language_code'])) {
            $errors['version'] = self::duplicateMessage($values);
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $values;
    }

    /**
     * @param array<string, string> $errors
     * @return array{effective_from: string, body: string}
     */
    private static function validateEditable(array $input, array &$errors): array
    {
        $date = Validator::date(trim((string) ($input['effective_from'] ?? '')));
        if ($date === null) {
            $errors['effective_from'] = 'Enter the date this text takes effect.';
        }
        $body = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input['body'] ?? '')));
        if ($body === '') {
            $errors['body'] = 'Enter the text.';
        } elseif (strlen($body) > 65535) {
            $errors['body'] = 'This text is too long to save. Please shorten it.';
        } elseif (!mb_check_encoding($body, 'UTF-8')) {
            $errors['body'] = 'Some characters in this text could not be read. Please paste the text in again.';
        }
        return ['effective_from' => (string) $date, 'body' => $body];
    }

    /** @param array<string, string> $values */
    private static function duplicateMessage(array $values): string
    {
        return "Version {$values['version']} of the {$values['doc_type']} already exists in {$values['language_name']}. "
            . 'Use a different version, or open that one from the list.';
    }
}
