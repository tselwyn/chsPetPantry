<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Context;
use Pfpms\Http\ErrorHandler;
use Pfpms\Http\HttpException;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Reference\SizeBandRepository;
use Pfpms\Storage\SecureFileStore;

// Sends one encrypted stored file (?kind=&id=) to a signed-in user who may see it (plan §4, SecureFileStore).
$ctx = Page::start(['capability' => 'home.view']);

/**
 * One resolver per kind of file. Each gets the signed-in context and the record id, checks
 * that this user may see the file, and returns its stored path and the types it may be,
 * or null (sent as 404, so nobody can probe which records exist).
 * Add a kind here when a module stores files, e.g. pet photos, which must check the pet's site.
 * @var array<string, callable(Context, int): (array{path: string, allowed_mimes: list<string>}|null)> $resolvers
 */
$resolvers = [
    // Size band pictures are reference pictures: every signed-in user may see them (US-13).
    'size_band' => static function (Context $ctx, int $id): ?array {
        $band = SizeBandRepository::find($id);
        return $band === null || $band['picture_path'] === null ? null
            : ['path' => (string) $band['picture_path'], 'allowed_mimes' => ['image/jpeg', 'image/png']];
    },
];

$resolver = $resolvers[Request::query('kind') ?? ''] ?? null;
$id = Request::int('id', fromQuery: true);
$file = $resolver !== null && $id !== null && $id > 0 ? $resolver($ctx, $id) : null;
if ($file === null) {
    throw new HttpException(404);
}
try {
    $bytes = SecureFileStore::get($file['path']);
} catch (RuntimeException $e) {
    // The record points at a file that is missing or does not decrypt (e.g. a key is not configured): log it for the admins.
    ErrorHandler::log(strtoupper(bin2hex(random_bytes(4))), $e);
    throw new HttpException(404);
}
$mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
if (!in_array($mime, $file['allowed_mimes'], true)) {
    throw new HttpException(404);
}

// Release the session lock so a page showing several pictures loads them in parallel.
session_write_close();
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: inline; filename="picture"');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
echo $bytes;
