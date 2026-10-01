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
        // With the dev server (php -S 127.0.0.1:8088 -t public) this must be exactly http://localhost:8088, or every
        // Station POST fails the Origin check.
        'base_url' => 'http://pfpms.localhost',
        // URL path of public/, e.g. '/' or '/chsPetPantry/public/'. Worked out automatically when public/ (or public_html/, the
        // SiteGround layout) sits beside src/; otherwise set it, or the site refuses to run outside dev/test. On SiteGround: '/'.
        // 'base_path' => '/',
        // Direct peers whose X-Forwarded-For header is trusted (e.g. a front-end proxy). Empty: trust none.
        'trusted_proxies' => [],
        // Defaults to true outside dev/test; must not be false in prod.
        // 'secure_cookies' => true,
        // true while deploying: every API answers 503 "maintenance", so tablets keep their records queued.
        'maintenance' => false,
    ],

    'station' => [
        // Emergency only: sw.php serves a worker that removes the Station's cached files (tablets keep their data).
        'sw_kill' => false,
        // dev/test only (refused elsewhere): treat a browser tab as installed with kept storage, for testing in a normal tab.
        'dev_relax_install' => false,
    ],

    // AES-256-GCM keys for data encrypted at rest (mail outbox, import files, pet photos), and the Station's tablet
    // vault keys, tablet proof keys, offline grant secrets, sync payloads, PIN hashes and tablet credentials (derived
    // at registration). Keep every retired key listed while data made with it may remain (docs/design/50-design-station.md §3.7).
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
