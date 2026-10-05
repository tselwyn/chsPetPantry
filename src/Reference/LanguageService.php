<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use PDOException;
use Pfpms\Audit\Audit;
use Pfpms\Db;
use Pfpms\Settings;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * The language list a coordinator maintains (US-23): languages participants can prefer and
 * that policy texts, receipts and vouchers are written in. Languages are never deleted,
 * because participants and documents refer to them; they are deactivated instead.
 *
 * The code is the key, so audit entries carry it in details (audit_log.entity_id is a number).
 */
final class LanguageService
{
    public const CODE_PATTERN = '/^[a-z]{2,3}(-[A-Z]{2})?$/';

    /** @throws ValidationException */
    public static function create(array $input): string
    {
        $errors = [];
        $code = self::normaliseCode($input['language_code'] ?? null);
        if ($code === null) {
            $errors['language_code'] = 'Enter a language code such as en, es or pt-BR.';
        } elseif (($existing = LanguageRepository::find($code)) !== null) {
            $errors['language_code'] = $existing['is_active'] ? 'That language is already in the list.'
                : 'That language is already in the list but is inactive. Use Activate next to it instead.';
        }
        $name = self::validName($input['name'] ?? null, null, $errors);
        if ($errors) {
            throw new ValidationException($errors);
        }
        try {
            Db::transaction(function () use ($code, $name): void {
                LanguageRepository::insert($code, $name);
                Audit::record('language_create', 'language', null, details: ['language_code' => $code, 'name' => $name]);
            });
        } catch (PDOException $e) {
            if (Db::isDuplicateKey($e)) { // added by someone else a moment ago
                throw ValidationException::one('language_code', 'That language is already in the list.');
            }
            throw $e;
        }
        return $code;
    }

    /** @throws ValidationException */
    public static function rename(string $code, ?string $name): void
    {
        $before = LanguageRepository::find($code) ?? throw ValidationException::one('_form', 'That language is no longer in the list.');
        $errors = [];
        $name = self::validName($name, $before['language_code'], $errors);
        if ($errors) {
            throw new ValidationException($errors);
        }
        $changes = Audit::diff($before, ['name' => $name], ['name']);
        if (!$changes) {
            return;
        }
        Db::transaction(function () use ($before, $name, $changes): void {
            LanguageRepository::rename($before['language_code'], $name);
            Audit::record('language_rename', 'language', null, details: ['language_code' => $before['language_code']], changes: $changes);
        });
    }

    /** @throws ValidationException when deactivating the default language */
    public static function setActive(string $code, bool $active): void
    {
        $language = LanguageRepository::find($code) ?? throw ValidationException::one('_form', 'That language is no longer in the list.');
        if ((bool) $language['is_active'] === $active) {
            return;
        }
        if (!$active && self::isDefault($language['language_code'])) {
            throw ValidationException::one('_form', $language['name'] . ' is the default language, so it cannot be deactivated. '
                . 'Choose a different default language in Settings first.');
        }
        Db::transaction(function () use ($language, $active): void {
            LanguageRepository::setActive($language['language_code'], $active);
            Audit::record($active ? 'language_activate' : 'language_deactivate', 'language', null,
                details: ['language_code' => $language['language_code']], changes: ['is_active' => [(int) !$active, (int) $active]]);
        });
    }

    /** The language used when a text is not available in someone's own language. */
    public static function isDefault(string $code): bool
    {
        return strcasecmp($code, Settings::string('default_language', 'en')) === 0;
    }

    /**
     * "PT_br", "pt-br" and "pt-BR" all become "pt-BR": a 2- or 3-letter language code in
     * lower case, optionally followed by a 2-letter country code in upper case.
     * Returns null when the input is not in that form.
     */
    public static function normaliseCode(?string $raw): ?string
    {
        if ($raw === null || !preg_match('/^\s*([A-Za-z]{2,3})(?:[-_]([A-Za-z]{2}))?\s*$/', $raw, $m)) {
            return null;
        }
        $code = strtolower($m[1]) . (isset($m[2]) ? '-' . strtoupper($m[2]) : ''); // an unmatched trailing group is absent
        return preg_match(self::CODE_PATTERN, $code) ? $code : null;
    }

    /** @param array<string, string> $errors */
    private static function validName(?string $raw, ?string $exceptCode, array &$errors): string
    {
        $name = Validator::text($raw, 50);
        if ($name === null) {
            $errors['name'] = 'Enter the language name (up to 50 characters).';
            return '';
        }
        if (LanguageRepository::nameTaken($name, $exceptCode)) {
            $errors['name'] = 'Another language already has this name.';
        }
        return $name;
    }
}
