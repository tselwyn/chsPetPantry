<?php
declare(strict_types=1);

namespace Pfpms\Storage;

use Pfpms\Config;
use Pfpms\Security\Crypto;
use RuntimeException;

/**
 * Encrypted file storage outside the web root (storage/secure), for pet photos (UC-05 §4.4),
 * import files and rejected rows (UC-12 §4.7) and generated reports.
 *
 * Files get random names, so a name reveals nothing and cannot be guessed. Contents are
 * AES-256-GCM encrypted with the stored path as associated data, so a file copied to another
 * name does not decrypt. Callers keep the returned path in their own table (e.g. pet.photo_path)
 * and must authorise access themselves before calling get().
 */
final class SecureFileStore
{
    /** Store bytes and return the relative path to keep, e.g. "pets/2026/10/3f9c...e1.bin". */
    public static function put(string $area, string $contents): string
    {
        self::checkArea($area);
        $relative = $area . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(16)) . '.bin';
        $file = self::absolute($relative);
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create storage directory for area '$area'");
        }
        if (file_put_contents($file, Crypto::encrypt($contents, 'file:' . $relative), LOCK_EX) === false) {
            throw new RuntimeException('Could not write the file');
        }
        @chmod($file, 0600);
        return $relative;
    }

    public static function get(string $relative): string
    {
        $file = self::absolute($relative);
        $data = is_file($file) ? file_get_contents($file) : false;
        if ($data === false) {
            throw new RuntimeException('Stored file not found');
        }
        return Crypto::decrypt($data, 'file:' . $relative);
    }

    public static function delete(string $relative): void
    {
        $file = self::absolute($relative);
        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException('Could not delete the stored file');
        }
    }

    /**
     * Validate and store an uploaded file ($_FILES entry). The type is taken from the file's
     * contents, never from the browser's claim or the name.
     * @param list<string> $allowedMime e.g. ['image/jpeg', 'image/png']
     * @return array{path: string, mime: string, size: int, sha256: string}
     */
    public static function putUpload(array $upload, string $area, array $allowedMime, int $maxBytes): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
            throw new RuntimeException('The file did not upload. Please try again.');
        }
        $size = (int) filesize($upload['tmp_name']);
        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('The file is empty or larger than ' . round($maxBytes / 1048576, 1) . ' MB.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if (!in_array($mime, $allowedMime, true)) {
            throw new RuntimeException('That type of file is not accepted here.');
        }
        $contents = (string) file_get_contents($upload['tmp_name']);
        return ['path' => self::put($area, $contents), 'mime' => $mime, 'size' => $size, 'sha256' => hash('sha256', $contents)];
    }

    private static function root(): string
    {
        return rtrim((string) Config::get('app.secure_storage', APP_ROOT . '/storage/secure'), '/\\');
    }

    private static function absolute(string $relative): string
    {
        if (!preg_match('~^[a-z_]{1,30}/\d{4}/\d{2}/[a-f0-9]{32}\.bin$~', $relative)) {
            throw new RuntimeException('Invalid stored-file path');
        }
        return self::root() . '/' . $relative;
    }

    private static function checkArea(string $area): void
    {
        if (!preg_match('/^[a-z_]{1,30}$/', $area)) {
            throw new RuntimeException("Invalid storage area '$area'");
        }
    }
}
