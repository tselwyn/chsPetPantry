<?php
declare(strict_types=1);

namespace Pfpms\Http;

use InvalidArgumentException;

final class Response
{
    /** Redirect to an app-relative path (e.g. "login.php?next=index.php") and stop. */
    public static function redirect(string $path, int $status = 303): never
    {
        $safe = Request::safeAppPath($path);
        if ($safe === null) {
            throw new InvalidArgumentException("Refusing to redirect to '$path': only app-relative .php paths are allowed");
        }
        header('Location: ' . Request::basePath() . $safe, true, $status);
        exit;
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function notFound(string $message = ''): never
    {
        throw new HttpException(404, $message);
    }

    public static function forbidden(string $message = ''): never
    {
        throw new HttpException(403, $message);
    }

    public static function badRequest(string $message = ''): never
    {
        throw new HttpException(400, $message);
    }
}
