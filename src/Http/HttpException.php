<?php
declare(strict_types=1);

namespace Pfpms\Http;

use RuntimeException;

/**
 * An error with an HTTP status that is safe to show to the user. JSON endpoints also carry a
 * machine-readable code, extra fields and headers (the error envelope, 50-design §5.2).
 */
final class HttpException extends RuntimeException
{
    /**
     * @param ?string $errorCode the envelope's "error" (null: codeFor($status))
     * @param array<string, mixed> $extra more envelope fields, e.g. directive, server_time, errors
     * @param array<string, string> $headers sent with the error, e.g. Retry-After, Allow, Clear-Site-Data
     */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly ?string $errorCode = null,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'The request could not be processed. Please go back and try again.',
            401 => 'Please sign in.',
            403 => 'You do not have permission to do that.',
            404 => 'That page or record was not found.',
            405 => 'That action is not allowed here.',
            409 => 'Someone else changed this record while you were working on it.',
            410 => 'This is no longer available.',
            413 => 'That is too much to send at once.',
            415 => 'The request was sent in the wrong format.',
            422 => 'Some of the information needs correcting.',
            429 => 'Too many attempts. Please wait a few minutes and try again.',
            503 => 'The system is temporarily unavailable.',
            default => 'Something went wrong.',
        };
    }

    /**
     * The envelope code for a status when none was given (50-design §5.2): a generic one per status. bad_json and
     * maintenance are always given explicitly where they apply.
     */
    public static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'not_signed_in',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'busy',
            410 => 'wiped',
            413 => 'too_large',
            415 => 'unsupported_media_type',
            422 => 'invalid',
            429 => 'rate_limited',
            503 => 'unavailable',
            default => 'server_error',
        };
    }

    /** The envelope code of this error. */
    public function code(): string
    {
        return $this->errorCode ?? self::codeFor($this->status);
    }
}
