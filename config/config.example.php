<?php
/*
 * PFPMS configuration. Copy to config/config.php (never committed) and fill in.
 * On SiteGround, keep the file outside public_html and chmod it 600.
 */
return [
    // dev | test | staging | prod. Outside dev/test, cookies are Secure and errors are never shown.
    'env' => 'dev',

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'pfpms_dev',
        // Application account: SELECT, INSERT, UPDATE, DELETE only.
        'user' => 'pfpms_app',
        'pass' => '',
        // Owner account used by bin/migrate.php for DDL. Leave empty to use 'user'.
        'migrate_user' => 'pfpms_owner',
        'migrate_pass' => '',
        // MySQL 8.4 accounts using caching_sha2_password over TCP need TLS:
        // 'ssl_ca' => '/path/to/ca.pem', 'ssl_verify' => true,
    ],

    'app' => [
        // Absolute URL of public/, used in emailed links (password reset, invitations). Required outside dev/test.
        'base_url' => 'http://pfpms.localhost',
        // Only if the automatic detection is wrong: URL path of public/, e.g. '/' or '/chsPetPantry/public/'.
        // 'base_path' => '/',
        // Direct peers whose X-Forwarded-For header is trusted (e.g. a front-end proxy). Empty: trust none.
        'trusted_proxies' => [],
        // Defaults to true outside dev/test; must not be false in prod.
        // 'secure_cookies' => true,
    ],

    // AES-256-GCM keys for data encrypted at rest (mail outbox, import files, pet photos).
    // Generate with: php bin/generate-key.php
    'crypto' => [
        'active' => 'k1',
        'keys' => [
            'k1' => 'REPLACE_WITH_OUTPUT_OF_bin/generate-key.php',
        ],
    ],

    'mail' => [
        // smtp: real delivery. log: writes .eml files to storage/mail (dev). array: kept in memory (tests).
        'transport' => 'log',
        'from_email' => 'no-reply@example.org',
        'from_name' => 'CHS Pet Pantry',
        'smtp' => ['host' => 'smtp.example.org', 'port' => 587, 'user' => '', 'pass' => '', 'secure' => 'tls'],
    ],
];
