<?php
declare(strict_types=1);

namespace Pfpms\Validation;

use RuntimeException;

/**
 * Thrown by a Service when input breaks a rule. Carries one plain-language message per
 * field (or '_form' for the whole form), so the page can redisplay the form with each
 * problem next to its field and everything the user typed kept (UC-03 §3.3.2).
 */
final class ValidationException extends RuntimeException
{
    /** @param array<string, string> $errors field => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    public static function one(string $field, string $message): self
    {
        return new self([$field => $message]);
    }
}
