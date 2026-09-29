<?php
declare(strict_types=1);

namespace Pfpms\Http;

use RuntimeException;

/** An error with an HTTP status that is safe to show to the user. */
final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
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
            422 => 'Some of the information needs correcting.',
            429 => 'Too many attempts. Please wait a few minutes and try again.',
            503 => 'The system is temporarily unavailable.',
            default => 'Something went wrong.',
        };
    }
}
