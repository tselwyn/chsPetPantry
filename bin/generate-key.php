<?php
declare(strict_types=1);

/*
 * Print a new random encryption key for config 'crypto' => ['keys' => ['<id>' => '<key>']].
 * To rotate: add the new key under a new id, set 'active' to it, and keep the old key
 * listed until everything encrypted with it has been re-encrypted or purged.
 *
 *   php bin/generate-key.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

echo base64_encode(random_bytes(32)), "\n";
