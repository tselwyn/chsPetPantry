<?php

define('ENCRYPTION_KEY', 'k2946sI82lsdju973kDine37s0cbe29f');
define('ENCRYPTION_METHOD', 'AES-256-CBC');

function encryptEmail($email) {
    $iv = random_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
    $encryptedMail = openssl_encrypt($email, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);

    $data = base64_encode($iv . $encryptedMail);

    return urlencode($data);
}

function decryptEmail($data) {

    $decoded = base64_decode($data);
    if (!$decoded) {
        return false;
    }
    $ivLength = openssl_cipher_iv_length(ENCRYPTION_METHOD);
    $iv = substr($decoded, 0, $ivLength);
    $encryptedMail = substr($decoded, $ivLength);

    return openssl_decrypt($encryptedMail, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);
}

?>