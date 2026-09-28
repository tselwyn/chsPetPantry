<?php
/*
 * PFPMS configuration. Copy to config/config.php (never committed) and fill in.
 * On SiteGround, keep the file outside public_html and chmod it 600.
 */
return [
    // dev | test | staging | prod. prod refuses debug output and insecure cookies.
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
        'name' => 'CHS Pet Pantry',
        'base_url' => 'http://pfpms.localhost',
    ],
];
