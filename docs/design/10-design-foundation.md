<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

# PFPMS foundation and architecture plan: Phase 0 hygiene and the shared core

## 0. Decisions this plan takes

**Assumptions.** I read everything in read-only mode. Anything about SiteGround marked "verify" was not confirmed.

**Sizes.** S = 1 day or less. M = 2–3 days. L = 4–7 days. All estimates are for one developer.

**Layout.** Pages move into `public/` and the core code lives beside it, outside the web root.
- SiteGround fixes the document root at `~/www/<domain>/public_html` (verify).
- SiteGround's NGINX layer may serve static files without honouring `.htaccess` (verify).
- `vendor/phpmailer/phpmailer/get_oauth_token.php` can currently be reached from the web.
- So secrets, `vendor/`, `src/`, migrations and storage are kept physically outside the document root.
- The repo mirrors the server exactly (`public/` becomes `public_html/`), so `__DIR__.'/../src/bootstrap.php'` resolves the same on XAMPP, CI and SiteGround.
- Churn is low: 110 pages are deleted and the remaining 46 are rebuilt anyway.

**Database.**
- One DDL that loads on both MariaDB 10.4 and MySQL 8.4, collation `utf8mb4_unicode_520_ci`.
- Every stored time is UTC. Each connection sets its session time zone to `+00:00`, and local dates are worked out in PHP from `site.time_zone`.

**Core style.** Namespaced static-facade classes under `src/`, autoloaded with PSR-4 through the existing composer setup. Pages stay one file each.

**Offline.** The foundation supplies what offline needs:
- a JSON API layer
- `client_uuid` columns
- a `sync_submission` idempotency table
- device credentials and offline grants
- the encryption rules
- one shared "commit distribution" code path used by both the web UI and the sync API

The offline workstream builds the service worker and IndexedDB client on top of this.

---

## (a) Phase 0: security and repo hygiene

### a1. Secrets to rotate or revoke

Do this first. Values are not shown here.

| # | Secret | Where it leaks | Action | Owner |
|---|---|---|---|---|
| 1 | SiteGround production DB user and password for database `[redacted-db-name]` | `database/dbinfo.php:26-29`, `public_html.zip`, git history on every branch | Change the password in Site Tools → MySQL. Do not reuse this DB or user for PFPMS. | SiteGround account holder (Dr. Polack) |
| 2 | Local `foodpantrydb` DB password | `dbinfo.php:22-25` | Change it, and use new DB users (`pfpms_app`, `pfpms_owner`) | Each developer |
| 3 | Gmail app password for the pantry Gmail account | `forgotPassword.php:16-23` | Revoke it in the Google account's App Passwords. Stop using Gmail for app mail; use a SiteGround mailbox on the PFPMS domain. | Gmail account owner |
| 4 | `ENCRYPTION_KEY` constant | `emailEncryption.php:3` | Retire it; delete the file. It only protected the forgeable reset links. | — |
| 5 | `ENCRYPTION_KEY` environment variable | `security_config.php:4`, set on the server | Unset it on the old host. Purge `secure_uploads/*.enc` on the server: these are verified-ID images of CCDA people, and that feature is dropped. | Site owner |
| 6 | SMTP credentials in `email/.env` on the server | Served over HTTP because there is no `.htaccess` | Assume it is exposed. Rotate the mailbox password and delete the file. | Site owner |
| 7 | `vmsroot` and `vmsroot2` accounts (default password), `insertAdmin.php` | Live DB, `sql/*.sql`, `README.md:38,99,105-108` | Delete the rows from the live DB and delete the script. | Site owner |
| 8 | bcrypt hashes and emails of real CCDA staff | `sql/foodpantrydb.sql:4198-4211` and other dumps | Treat them as compromised. Never carry these hashes into PFPMS; migrated staff get new temporary credentials. | Team |
| 9 | Third-party exposure: the `r-brt/FoodPantry` history inside the zip, and the `jenniferp217` host credentials in the logs | `public_html.zip`, `scheduledSend_errors.log` | Tell those owners. | Team lead |

### a2. The old live site on `jenniferp231.sg-host.com`

Ask the user whether CCDA still uses it.
- **Preferred:** take it offline, after CCDA signs off and exports its data.
- **Otherwise, lock it down now:**
  - delete `insertAdmin.php`, `email/`, every `*.log`, `testmsg.txt`, any `*.zip` and `sql/` on the server;
  - delete the `vmsroot` and `vmsroot2` rows;
  - upload the interim deny rules below as the site-root `.htaccess`:
```apache
Options -Indexes
RewriteEngine On
RewriteRule (^|/)\. - [F,L]
RewriteRule ^(vendor|database|domain|include|email|sql|docs|config|secure_uploads|logs|storage|legacy|node_modules)(/|$) - [F,L,NC]
<FilesMatch "(?i)(\.(sql|log|zip|md|inc|ini|env|lock|py|sh|bak)$|^(composer|package(-lock)?)\.json$|^php_errorlog$)">
  Require all denied
</FilesMatch>
<FilesMatch "^(insertAdmin|getVolunteers|deleteBulk|clockOut|clockOutBulk|autoCheckOut|scheduledSend|toggleLock|viewUpdateInventory|get_oauth_token|sendEmail|send_email)\.php$">
  Require all denied
</FilesMatch>
```

### a3. Delete from the repo

Work on branch `pfpms/phase-0`. Commits need the user's OK.

**Sensitive and junk files**
- `public_html.zip`
- `php_errorlog`, `cron_debug.log`, `scheduledSend_errors.log`, `test.log`, `email_debug.log`, `email_errors.log`, `email/email_errors.log`, `testmsg.txt`
- `.DS_Store`, `readme (2).md`, `php.ini` (set these in Site Tools → PHP Manager instead)
- `sql/` entirely: `foodpantrydb.sql`, `README.txt`, and all 25 files in `Old Versions/`, including `[redacted-db-name] (Siteground DB).sql` and `dbpersons.sql`

**Security-critical code**
- `insertAdmin.php` (replaced by `bin/create-admin.php`)
- `emailEncryption.php`
- `database/dbinfo.php`
- `toggleLock.php` (no auth; replaced by the US-01 `switch_user.php`)
- `email/` entirely: the bundled PHPMailer copy with its `get_oauth_token.php`, `sendEmail.php`, `send_email.php`, `send_email.py`

**The 110 pages classified DELETE by the UI analyst**
- Shell: `infoBox`
- Users: `modifyUserRole`, `VolunteerRegister`, `deleteUserSearch`, `deleteUser`, `deletePerson`, `volunteerManagement`, `getVolunteers`, `milestonePoints`, `viewVolunteerProfile`, `accountEditHistory`, `viewArchived`
- Events and hours (49):
  - `calendar-view`, `calendar-view_daily`, `calendar-view_weekly`, `cancelEvent`, `completeEvent`, `date`, `deleteEvent`, `eventSearch`
  - `approveSignup`, `rejectSignup`, `event-list`, `eventList`, `logAttendees`, `setTimes`, `viewSignUpList`, `noShows`
  - `adminViewingEvents`, `eventApproved`, `viewAllEvents`, `viewMyUpcomingEvents`, `denyApplication`, `eventManagement`, `eventSignUp`
  - `fromPendingApproveSignup`, `fromPendingFlagSignup`, `fromPendingRejectSignup`, `process_application`, `viewAllApplications`, `viewApplication`, `viewEventSignUps`, `viewPendingApps`
  - `eventsOpenForSignUpReview`, `viewEventsForSignUp`, `autoCheckOut`, `clockOut`, `clockOutBulk`, `editTimes`, `deleteTimes`, `editHours`, `volunteerReport`
  - `applicationSuccess`, `eventFailure`, `eventFailureBadDepartureTime`, `eventSuccess`, `requestFailed`, `signupPending`, `signupSuccess`, `processAttendees`, `viewRetreatApplications`
- Training: `addTraining`, `eventTrainingManagement`
- Communications (29):
  - `createDiscussion`, `deleteBulk`, `deleteDiscussion`, `deleteReply`, `discussionContent`, `discussionMain`, `viewDiscussions`
  - `createSuggestion`, `viewSuggestions`
  - `createGroup`, `deleteGroup`, `showGroups`, `manageMembers`, `groupManagement`, `volunteerViewGroup`, `volunteerViewGroupMembers`
  - `inbox`, `viewNotification`, `deleteNotification`
  - `createEmail`, `editDrafts`, `viewDrafts`, `emailDraft`, `sendDraft`, `email`, `generateEmailList`, `scheduleEventEmails`, `emailDraftView`, `emailSingleDraftView`
- Inventory: `viewEditDeleteInventory`
- Reports: `report`, `reportsPage`, `reports`, `reportsCompute`, `reportsExport`
- Uploads: `view_encrypted_gallery`, `approve_encrypted_image`, `deny_encrypted_image`, `resources`, `uploadResources`, `deleteResources`, `viewResources`
- Misc: `create_dummy_dbpersonhours`, `viewData`, `viewLocation`, `deleteLocation`, `deleteService`

**Data layer**
- In `database/`, everything except the 6 reference files in a4: `dbApplications`, `dbAppointments`, `dbAttendance`, `dbClient`, `dbConsumption`, `dbDiscussionReplies`, `dbDiscussions`, `dbDistribution`, `dbEditLog`, `dbEventMedia`, `dbEvents`, `dbGroups`, `dbLog`, `dbMessages`, `dbShifts`, `dbShoppingCount`, `dbShoppingCountGroup`, `dbShoppingEvent`, `dbSuggestions`, `dbTrainingPersons`, `dbtraining`, `InventoryEvent.php`.
- The matching `domain/*.php` classes.

**Front end and branding**
- Root `event.css` and `event.js`.
- `package.json`, `package-lock.json`, `css/normal_tw.css`, `css/management_tw.css` (Tailwind is effectively unused).
- Other organisations' brand images: `whiskey*`, `wvGoldBar.jpg`, `WV logo.webp`, `FredSPCA*`, `Cropped-Logo-FredSPCA.png`, `odhscroppedlogo.png`, `rmhHeader.gif`, and the CCDA logos once the CHS logo exists.

### a4. Quarantine into `legacy/`

`legacy/` gets a `Require all denied` `.htaccess`. It is reference material only and is deleted before go-live. A CI guard stops anything in `public/` or `src/` from including it.

- The remaining 31 ADAPT pages and 13 REWRITE pages (`insertAdmin` and `toggleLock` are already deleted), including `header.php`, `index.php`, `universal.inc`, `template.php`, `security_config.php`, `serve_image.php`, `upload_encrypted_image.php`, `viewShoppingList.php`, `generateReport.php`, `processInventoryReport.php`.
- Data-layer reference: `database/dbPersons`, `dbItemCategory`, `dbInventoryEvent`, `dbItemCounts`, `dbPalletEvent`, `dbPalletCounts`, and their `domain/` classes.
- `include/` (ported, see (e)).
- `lib/`: jQuery 1.9.1, jquery-ui, timepicker, bootstrap.

Nothing in quarantine can run against the v2 database anyway.

### a5. SQL dumps, the zip and the logs
- **Dumps and zip.** Put them in one encrypted archive (7-Zip AES-256 or `age`) on the project's restricted drive, with a named custodian. The data belongs to CCDA; they decide how long it is kept. Default: delete 90 days after the staff accounts are re-created.
- **Never** load dumps into shared dev or test databases. Test fixtures must be synthetic.
- If the optional staff-migration script is used, restore the dump into an isolated local `legacy_foodpantry` DB on one machine, then drop it.
- **Logs.** Delete; don't archive. They have no value and contain real email addresses.

### a6. `.gitignore`
```gitignore
# secrets / env
/config/config.php
/config/*.local.php
.env
.env.*
# runtime (prod storage lives outside public_html)
/storage/*
!/storage/.gitkeep
/secure_uploads/
# deps & build
/vendor/
/node_modules/
/build/
/.phpunit.cache/
.phpunit.result.cache
/coverage/
# never commit data or logs
*.log
php_errorlog
error_log
*.sql
!/migrations/**/*.sql
!/seeds/**/*.sql
!/docs/PFPMS_schema_v2.sql
*.zip
*.tar.gz
*.bak
*.enc
# OS / editors
.DS_Store
Thumbs.db
desktop.ini
.idea/
.vscode/
*.swp
```
Also add `.gitattributes` (`* text=auto eol=lf`, `*.png binary`) and `.editorconfig`.

### a7. `.htaccess` for the target layout
- **Repo root `.htaccess`:** `Require all denied`. This protects the repo when the whole tree sits in XAMPP `htdocs`. It denies `vendor`, `src`, `config`, `migrations`, `storage`, `legacy`, `docs` and `tests` in one line.
- **`public/.htaccess`:**
```apache
Require all granted
Options -Indexes -MultiViews
DirectoryIndex index.php
AddType application/manifest+json .webmanifest
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP_HOST} !^(localhost|127\.0\.0\.1|[a-z0-9-]+\.localhost)(:\d+)?$ [NC]
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
RewriteRule (^|/)\.(?!well-known/) - [F,L]
<FilesMatch "(?i)\.(sql|log|md|inc|ini|bak|zip|env|lock|sh|py|dist)$">
  Require all denied
</FilesMatch>
<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "same-origin"
  Header always set X-Frame-Options "DENY"
  Header always set Permissions-Policy "camera=(self), microphone=(), geolocation=()"
  <FilesMatch "^(sw\.js|manifest\.webmanifest)$">
    Header set Cache-Control "no-cache"
  </FilesMatch>
</IfModule>
```
- HSTS and the CSP are sent from PHP (`Security\Headers`) once HTTPS is confirmed.
- Don't turn on Site Tools features that rewrite `public_html/.htaccess`, or merge their rules into this file.

### a8. Config outside the web root
The config is a PHP file that returns an array: no parser, and it prints nothing if it is ever requested.
- **Committed:** `config/config.example.php`. **Git-ignored:** `config/config.php`.
- **Lookup order in `src/Config.php`:**
  1. `getenv('PFPMS_CONFIG')`
  2. `APP_ROOT.'/config/config.php'`
  3. otherwise a fatal "not configured" error (logged, generic 500)
- There is no dependency on `SERVER_NAME`. That dependency is what broke the legacy cron.
- **Where the file lives:**
  - SiteGround: `~/www/<domain>/config/config.php`, which is outside `public_html`. Set permissions to 600.
  - XAMPP: `C:/xampp/htdocs/chsPetPantry/config/config.php`, protected by the root deny and `.gitignore`.
  - CLI and cron resolve the same path.
```php
<?php // config/config.example.php
return [
  'env'  => 'dev',                                   // dev|test|staging|prod
  'app'  => ['base_url' => 'http://pfpms.localhost', 'debug' => true],
  'db'   => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'pfpms_dev',
             'user' => 'pfpms_app', 'pass' => '',     // DML only
             'migrate_user' => 'pfpms_owner', 'migrate_pass' => ''], // DDL
  'mail' => ['transport' => 'log', 'host' => '', 'port' => 465, 'encryption' => 'ssl',
             'user' => '', 'pass' => '', 'from' => 'no-reply@example.org', 'from_name' => 'CHS Pet Pantry'],
  'crypto'  => ['active_key_id' => 1, 'keys' => [1 => 'base64:REPLACE_WITH_bin/generate-key.php']],
  'paths'   => ['storage' => dirname(__DIR__) . '/storage'],
  'session' => ['name' => 'PFPMSSESS', 'cookie_secure' => false],
];
```

### a9. Unauthenticated or under-protected endpoints: what happens to each

| Endpoint | Fate |
|---|---|
| `viewUpdateInventory.php` (anonymous POST writes inventory) | Quarantined, then rebuilt behind `Page::start(['capability'=>'inventory.count'])` |
| `deleteBulk.php`, `clockOut.php`, `clockOutBulk.php`, `autoCheckOut.php`, `getVolunteers.php` | Deleted |
| `insertAdmin.php` | Deleted; replaced by `bin/create-admin.php` (CLI only) |
| `scheduledSend.php` (cron triggerable from the web) | Quarantined; replaced by `bin/cron.php`, which has a CLI-only guard |
| `email/sendEmail.php`, `email/send_email.php` (open relays), `get_oauth_token.php` (both copies) | Deleted; `vendor/` moves outside the document root |
| `toggleLock.php` | Deleted |
| Pages with no page guard (`viewAuditUsers`, `viewManagePallets`, `viewAddPallet`, `viewWeeklyReport`, `createUser`) | Quarantined; every rebuilt page must call `Page::start` first (CI contract test) |
| `serve_image.php` (any logged-in user can fetch any file) | Replaced by `public/file.php`, which checks the entity and site before serving |
| New pages meant to be public (`login`, `forgot_password`, `reset_password`, `activate`, later the US-22 clinic portal and US-07 self-registration) | Allowlisted, rate-limited through `rate_limit_bucket`, CSRF-protected |

### a10. Git history purge (recommendation only; needs the user's explicit OK)
- Secrets and personal data (dumps, logs, zip, `dbinfo.php`, `forgotPassword.php`, `emailEncryption.php`, `insertAdmin.php`) are in every commit and on every remote branch: `main`, `dev`, `Heji`, `Rachelle`, `Selwyn`.
- If `tselwyn/chsPetPantry` is or was public, purge with `git filter-repo --invert-paths --paths-from-file purge.txt` on a fresh mirror clone, then force-push all branches.
- All collaborators must then re-clone. Ask GitHub Support to remove cached refs and PR views.
- Forks keep the data regardless. Rotating the secrets (a1) is what actually neutralises the credentials; the purge deals with the personal data.
- I couldn't check the repo's visibility because `gh` isn't installed here.

---

## (b) Target layout and shared core

### b1. Layout, with SiteGround and XAMPP mapping
```
repo/                      SiteGround: ~/www/<domain>/        XAMPP: htdocs/chsPetPantry/
├─ public/                 → ~/www/<domain>/public_html/       vhost DocumentRoot
│  ├─ index.php login.php logout.php forgot_password.php reset_password.php
│  │  change_password.php activate.php policy_ack.php select_site.php switch_user.php
│  │  profile.php notifications.php file.php healthz.php offline.html
│  │  (feature pages: flat snake_case, prefixed by module: participant_search.php, distribution_record.php, admin_users.php …)
│  ├─ api/                 JSON endpoints (session.php heartbeat, sync.php, offline_grant.php, …)
│  ├─ assets/css/app.css   one stylesheet (rebranded base.css + the .report-table/.modify-* rules now copied inline in 18 pages)
│  ├─ assets/js/ assets/img/ assets/fonts/ assets/vendor-js/ (jsPDF + autotable from js/, self-hosted Chart.js)
│  ├─ sw.js  manifest.webmanifest  .htaccess
├─ src/  templates/  bin/  migrations/  seeds/  vendor/(built)     → same names under ~/www/<domain>/
├─ config/ (config.example.php committed; config.php ignored)    → ~/www/<domain>/config/config.php
├─ storage/ logs/ sessions/ secure/{pets,imports,rejected,reports}/ mail/ cache/ maintenance.flag
│                          → ~/www/<domain>/storage/ (0700; never under public_html)
├─ tests/  docs/  legacy/  .github/workflows/   (never deployed)
└─ .htaccess (Require all denied)
```
- **XAMPP vhost:** `ServerName pfpms.localhost`, `DocumentRoot ".../chsPetPantry/public"`, `AllowOverride All`. Chrome resolves `*.localhost` to 127.0.0.1, which counts as a secure context, so service workers and WebCrypto work.
- **Testing on real tablets over the LAN:** use a mkcert HTTPS vhost such as `pfpms.test`, or a SiteGround staging copy.
- **Fallback if the team insists on a flat layout:** pages stay at the repo root, and deny `.htaccess` files go in every non-public directory. This is weaker; not recommended.

### b2. Core files and what each does

| File | Responsibility |
|---|---|
| `src/bootstrap.php` | Defines `APP_ROOT`, loads the composer autoloader, loads `Config`. Sets `error_reporting(E_ALL)`, `display_errors=0` (1 only when `env=dev`), `log_errors=1`, `error_log=storage/logs/php-errors.log`. Sets `date_default_timezone_set('UTC')` (XAMPP ships Europe/Berlin). Registers global exception and error handlers (generic 500 page plus an incident id; POST keys `password`, `pin`, `token` are redacted). Starts the session with hardened settings. Sends security headers and `Cache-Control: no-store, private`. Checks for maintenance mode (503; JSON 503 for `/api/` so offline clients keep queueing). |
| `src/Config.php` | Lookup order from a8. `Config::get('db.name')`. Refuses to run with `env=prod` if `app.debug` is true or `session.cookie_secure` is false. |
| `src/Db.php` | See the notes after this table. |
| `src/Clock.php` | `now()` (UTC, injectable `FrozenClock` for tests), `siteToday($site)`, `toLocal()`. |
| `src/Log.php` | Daily files in `storage/logs`, levels, PII redaction. |
| `src/Http/Request.php`, `Response.php`, `Flash.php` | Typed input (`int()`, `str()`, `date()`), `isPost()`. `redirect()` accepts only app-relative paths, which closes the open redirect in the legacy `include/api.php:redirect`. `json()`, `forbidden()`, `notFound()`. Post/Redirect/Get flash messages. |
| `src/Http/Page.php` / `Api.php` | The guard pipeline every page and endpoint calls first (b3). `Api` returns JSON codes 401/403/409/422/503 instead of redirects and requires the CSRF header on anything but GET. |
| `src/Security/Csrf.php` | 32-byte token per session. `field()`, `header()`, `verify()` compares the `_csrf` POST field or `X-CSRF-Token` header with `hash_equals`, and also checks `Origin`/`Sec-Fetch-Site`. Failure returns 400 and writes a durable `CsrfFailed` audit row. |
| `src/Security/Crypto.php` | AES-256-GCM through openssl (available on XAMPP and SiteGround; sodium isn't loaded in XAMPP). Key ring with a key-id byte for rotation. `encryptString`/`decryptString`, `encryptFile`/`decryptFile`, `randomToken()` (32 bytes, base64url), `tokenHash()` (SHA-256 hex, for `auth_token.token_hash` and `device.token_hash`, so lookups stay indexable). Format: `PFE1 | key_id(1) | nonce(12) | tag(16) | ciphertext`, with the file's purpose and path as authenticated data. Never uses `emailEncryption.php` or CBC. |
| `src/Security/RateLimit.php` | `rate_limit_bucket` table (v2.1). `hit($bucket, $max, $window)`. Buckets for login and forgot-password by IP and by username, later the clinic portal and self-registration. |
| `src/Security/Headers.php` | CSP: `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; connect-src 'self'; worker-src 'self'; manifest-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`. No CDNs and no inline script, which also keeps the service worker cache simple. Sends HSTS once `https` is confirmed. |
| `src/Auth/Auth.php` | `attemptLogin(identifier, password, device)` (b3). `logout()`. `user()`. |
| `src/Auth/SessionManager.php` | The `user_session` lifecycle (b3). |
| `src/Auth/PasswordPolicy.php` | Minimum length from settings. Blocklist `data/common-passwords.txt` (top 10k, outside the web root). Rejects passwords containing the username or email. Optional HIBP k-anonymity check behind a setting (off by default). Hashing: `PASSWORD_ARGON2ID` (confirmed available on XAMPP PHP 8.2.4), falling back to bcrypt cost 12; `password_needs_rehash` upgrades on login. |
| `src/Auth/Tokens.php` | `auth_token` issue and consume (b3). |
| `src/Auth/Devices.php`, `Pin.php`, `OfflineGrant.php` | Site-registered devices, the US-01 PIN switch, and offline grants (b3). |
| `src/Auth/capabilities.php`, `Rbac.php`, `SiteAccess.php` | The capability matrix and site checks (b3). |
| `src/Audit/Audit.php`, `Snapshot.php` | The audit writer (b3). |
| `src/Settings/Settings.php`, `registry.php` | See c6. The registry maps each key to its type, default, min/max and description. Missing rows fall back to the default. `Settings::int|bool|decimal|string|monthDay()` loads all rows once per request. `Settings::set($k,$v,$reason)` validates against the registry, updates the row and writes an audit row with a field change. |
| `src/Db/Versioned.php` (inside `Db`) | Optimistic locking. `updateVersioned(table, pk, id, expectedVersion, changes)` runs `UPDATE … SET …, row_version=row_version+1 WHERE pk=? AND row_version=?`. If no row changed it throws `ConcurrencyException` carrying the current row, so the UI can show the conflicting changes field by field (UC-04 §3.3.2). Cached fields maintained by the system (`last_distribution_date`, `next_eligible_date`, `ytd_lbs_issued`, `current_allotment_lbs`) are written without bumping `row_version`, so a distribution never blocks a volunteer's edit. |
| `src/Validation/Validator.php` | Ported legacy functions (see (e)) plus a rule-based `validate($input, $rules)` returning a field → message map. |
| `src/View/View.php`, `helpers.php` | `View::render('pages/x', $vars)` inside `templates/layout/{head,nav,foot}.php`. `e()` escaping (hardened `hsc`). `asset('app.js')` adds `?v=<build sha>` (the service worker relies on this for cache busting). |
| `src/View/Menu.php` | Builds the nav from a list of menu items, each with a required capability, so admin items never render for volunteers (UC-09/UC-10 "not in Volunteer menu"). Site switcher (a POST form with CSRF that checks access). Notification badge. Organisation name taken from settings; CHS branding only. Replaces the 116-entry `$permission_array` in `header.php`. |
| `src/Mail/Mailer.php`, `Outbox.php` | One PHPMailer, the composer copy (v7.0.2). Transports: `smtp` (SiteGround mailbox; port 465 SSL or 587 STARTTLS), `log` (dev: writes `.eml` files to `storage/mail`), `array` (tests). `Outbox::queue()` writes an encrypted body to `outbound_message`. `bin/cron.php mail:send` sends and blanks the body once sent. Password-reset mail tries to send immediately and falls back to the queue. Links are always built from `app.base_url`, never the Host header. |
| `src/Storage/SecureFileStore.php`, `ImageProcessor.php` | Files live under `storage/secure/{pets,imports,rejected,reports}/`. Names are random 32-hex strings; only a relative path is stored in the DB. Uploads are checked with `is_uploaded_file`, a size cap (`photo_max_mb`, `import_max_file_mb`) and a MIME check via `finfo`. Photos are re-encoded with GD (max 1600 px, JPEG quality 80), which strips EXIF and GPS, then encrypted. If GD is missing the upload is refused. `public/file.php?kind=pet_photo&id=` authorises through entity and site access, then streams the file with `no-store`, `nosniff` and the right content type and disposition. |
| `src/Notify/Notifications.php` | The `notification` work queue (v2.1): create, list for the current user and role, resolve. |
| `src/Inventory/Ledger.php` | `post(siteId, productId, qtyChange, txnType, refs, allowNegative)`. Rows are locked in product-id order with `SELECT … FOR UPDATE`. It upserts `site_stock` portably with `INSERT … ON DUPLICATE KEY UPDATE site_id=site_id` followed by `UPDATE … quantity_on_hand = quantity_on_hand + ?`, which avoids MySQL-only row aliases and the deprecated `VALUES()`. Then it inserts `inventory_transaction`. Shared by receipts, counts, distributions and reversals. |
| `src/Distribution/Writer.php` (skeleton) | `commit(DistributionDraft, origin: web\|offline)`: one transaction covering `distribution`, `distribution_line`, `distribution_pet`, `Ledger::post` and the cached participant status. The UI and `api/sync.php` both call it. The business rule checks are added in F5. |
| `bin/cron.php` | See b3. |
| `bin/migrate.php`, `create-admin.php`, `generate-key.php`, `seed.php`, `maintenance.php`, `db-reset.php` (dev/test only), `import-legacy-staff.php` (optional) | Command-line tools, all with the CLI-only guard. |

**Notes on `src/Db.php`**
- Lazy PDO singleton. DSN `mysql:…;charset=utf8mb4`.
- Attributes: `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`, `STRINGIFY_FETCHES=false`.
- After connecting it runs these with `exec` rather than `MYSQL_ATTR_INIT_COMMAND`, which is deprecated from PHP 8.4:
  - `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci`
  - `SET time_zone='+00:00', SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'`
  - This makes dev MariaDB behave like prod MySQL.
- Helpers: `run`, `one`, `all`, `value`, and `insert` (identifier whitelist `^[a-z_]+$`).
- `transaction(callable)`: nests using SAVEPOINTs. The outermost call retries up to 3 times on deadlock (1213) or lock-wait timeout (1205).
- `isDuplicateKey()` (error 1062), used for idempotent `client_uuid` inserts.
- `durable()`: a second autocommit connection for audit rows that must survive a rollback.
- DB errors are never echoed.

### b3. Behaviour of the pieces that need it

**Login (`Auth::attemptLogin`)**
1. Checks the rate limit.
2. Looks the user up by username or email. The `system` user is refused.
3. If there is no such user, it runs `password_verify` against a dummy hash, so timing gives nothing away.
4. Checks, in order: `locked_until`, then status (Active; Pending only if a valid temporary credential is presented), then `expiry_date`.
5. On failure: `failed_login_count++`. At `max_failed_logins` it sets `locked_until = now + lockout_minutes`, writes a notification to Administrators and a durable audit row. The user always sees the same generic message.
6. On success: `session_regenerate_id(true)`, reset the counters, set `last_login_at`, open a `user_session` row, audit `Login`.
7. It then routes, in order: forced password change → policy acknowledgement → site selection.

**`SessionManager`**
- On login it creates a random 64-hex `usid`, stored in `$_SESSION`, as `user_session.session_id`. This is separate from the PHP session id, so the cookie can be regenerated freely.
- `resume()` runs on every request. It checks:
  - the row exists and `ended_at IS NULL`;
  - `now - last_activity_at` is within `session_idle_minutes` (30);
  - `now - started_at` is within `session_absolute_hours` (12);
  - the user is still Active.
- It updates `last_activity_at` at most once a minute.
- `endAllFor(userId, reason)` is used for remote sign-out (US-02), role/site/status changes (UC-11 §4.4), password reset and deactivation.
- Session settings:
  - `session.use_strict_mode=1`, `use_only_cookies=1`
  - cookie `httponly`, `secure` (prod), `samesite=Lax`
  - `gc_maxlifetime=43200`
  - `save_path=storage/sessions` (a private path, so other SiteGround tenants' garbage collection can't delete our sessions)

**`Tokens`**
- Password Reset:
  - the response is always the same, whether or not the email exists;
  - lifetime `reset_link_minutes`, single use (`used_at`);
  - any earlier unused reset tokens are cancelled;
  - on use: set the password, end all sessions, clear the lockout, audit.
- Temporary Credential:
  - `temp_credential_hours` = 72, single use;
  - the invitation link goes to `activate.php`; the printed code is shown once;
  - the account's `password_hash` is set to an unusable value until the user chooses a password;
  - status Pending becomes Active on the first successful change.
- Trusted Device (UC-01 §3.2.3): only pre-fills the username; the password is still required.

**`Devices`, `Pin`, `OfflineGrant`**
- A Coordinator or Admin registers a device at a site. The device credential lives in a `pfpms_dev` cookie (HttpOnly, Secure, SameSite=Strict); the server keeps its hash in the new `device.token_hash`.
- `Pin` (US-01): only works on a site-registered device, and only for a user with the `auth.pin_switch` capability and current access to that site. It ends the previous session, starts one with `auth_method='PIN'`, and regenerates the PHP session. After `pin_max_failed` wrong PINs a password is required.
- `OfflineGrant`: issued after an online login on a registered device. `auth_token` purpose 'Offline Grant' (v2.1) with a server-encrypted signing secret, lifetime `offline_grant_hours`. It is revoked by remote sign-out, a permission change, deactivation or a password reset.

**Capability matrix (`capabilities.php`)**
- The only place roles map to capabilities. Coordinator inherits Volunteer, and Administrator inherits Coordinator; Board inherits nothing. So the client's open question (notes §4.1) is settled by editing this file.
```php
return [
 'inherits' => ['Coordinator' => ['Volunteer'], 'Administrator' => ['Coordinator']], // Board inherits nothing
 'roles' => [
  'Volunteer' => ['profile.self','auth.pin_switch','participant.search','participant.view','participant.register',
    'participant.update','pet.edit','pet.delete_request','event.checkin','distribution.record',
    'distribution.record_offline','distribution.history.view','snv.refer','inventory.view'],
  'Coordinator' => ['session.roster','session.remote_signout','device.register','event.manage','event.live_dashboard',
    'inventory.receive','inventory.count','catalog.barcode_link','intake_question.manage',
    'user.site_grant_temporary','report.aggregate.view','snv.outcome.record'],
  'Administrator' => ['site.all','site.manage','user.manage','settings.manage','lookup.manage','policy.manage',
    'service_area.manage','catalog.manage','participant.update_restricted','participant.merge','participant.deactivate',
    'participant.delete','participant.restore','participant.purge_approve','participant.area_override',
    'participant.search_include_deleted','pet.delete','pet.limit_override','pet.microchip_resolve',
    'distribution.authorize_override','distribution.authorize_emergency','distribution.reverse',
    'snv.clinic.manage','snv.budget.manage','import.run','import.during_distribution_hours',
    'report.run','report.schedule','report.identifiable','audit.view'],
  'Board' => ['profile.self','report.aggregate.view','dashboard.board','site.all'], // aggregates only
 ],
];
```
- `Rbac::can()` combines the capability with per-user flags: `report.identifiable` also requires `user_account.can_extract_identifiable=1`.
- The override authorisation levels (`over_allotment_auth_level`, `emergency_auth_level`) are read from settings.
- `Rbac::requireDifferentActor()` enforces separation of duties: no editing your own role or sites (UC-11 §3.3.4) and a second Admin for purges.

**`SiteAccess`**
- `currentSite()`, `can($siteId)`: either `site.all`, or a `user_site_access` row where `starts_at <= now AND (ends_at IS NULL OR ends_at > now)`, using the time passed in from PHP.
- It is checked on every request, so time-limited grants lapse the moment they end (US-28).
- `visibleSiteIds()` feeds every scoped query.

**`Page::start` pipeline**, run before any `$_POST` is read:
1. `SessionManager::resume()`; if it fails, redirect to `login.php?next=` (relative paths only). A timeout shows "your work is saved on this device".
2. Pending, locked or expired account → sign out.
3. `must_change_password` → `change_password.php`.
4. The current Confidentiality Agreement is unacknowledged, or `due_again_on` plus the grace period has passed → `policy_ack.php` (US-03, US-30).
5. Capability check. Failure gives a 403 and a durable `AccessDenied` audit row.
6. Site check when `site=>true`; otherwise `select_site.php`.
7. Returns a `Context` (user, role, site, device, usid, capabilities).

Each step has an allowlist so the change-password, policy and logout pages don't loop.

**`Audit`**
- `log(action, entityType, entityId, [outcome, reason, snapshot, details, durable])` fills in user, usid, device and site from the `Context`. By default it runs on the caller's connection, so the audit row commits or rolls back with the change. `durable:true` uses `Db::durable()` for denied or failed attempts.
- `recordUpdate(entity, id, before, after, fields, reason)` writes `audit_field_change` rows for fields that differ.
- `Snapshot::of(table,id)` captures the row before deletion.
- Redaction: `password_hash`, `pin_hash`, token and secret columns are never recorded. `details` holds ids and codes only, never free-text personal data.
- Erasure (UC-09 §3.2.2) is handled by one allowlisted, audited `ErasureService::redactAuditTrail()`. It blanks personal-data values in `audit_log.snapshot` and `audit_field_change` for that participant. This is a documented exception to immutability. Crypto-shredding is the alternative if the client prefers.

**`bin/cron.php <job>|all`**
- First line: `if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`.
- Each job takes `GET_LOCK('pfpms_cron_<job>',0)`, which works on both databases.
- Foundation jobs:
  - `mail:send`
  - `sessions:expire` (sets `ended_at`, `end_reason='Timeout'` so the roster is accurate)
  - `tokens:purge`
  - `ratelimit:purge`
  - `files:purge` (`import_file_retention_days`, `report_run.retain_until`)
- Feature jobs added later: `vouchers:expire`, `reports:run`, `imports:run`, `ytd:reset`.
- Audit retention purge stays off until the client sets a policy.

**API and PWA hooks for the offline workstream**
- `public/manifest.webmanifest`, and `public/sw.js` at the root scope.
- The service worker caches only the app shell: versioned assets and `offline.html`. It never caches authenticated HTML, and all data goes into encrypted IndexedDB.
- `api/session.php`: heartbeat, CSRF refresh, and remaining idle/absolute time.
- `api/offline_grant.php`: returns the grant secret once. The client wraps it with a key derived from the password (PBKDF2 through WebCrypto) around an AES-GCM data key, and that unlock is the offline credential check.
- `api/sync.php` contract:
  - operations are processed in the client's recorded order, participants and pets before distributions;
  - each operation carries a `client_uuid` and an HMAC made with the grant secret;
  - if `sync_submission` already holds that `client_uuid`, the stored result is replayed; a different payload hash returns 409;
  - otherwise the operation runs in its own transaction through the same Writer and services, the rules are re-checked, and the outcome (Accepted, Rejected or Needs Review) is recorded, with an encrypted payload and a notification for any that need review.

---

## (c) Database

### c1. MariaDB-safe edits to `docs/PFPMS_schema_v2.sql`

This becomes v2.0.1. The same text goes into `migrations/0001_pfpms_schema_v2.sql`, with the 59 `DROP TABLE IF EXISTS` lines removed so it fails loudly on a non-empty DB. A CI test asserts the two files match apart from those DROP lines.

| Line(s) | Change | Why |
|---|---|---|
| 1, 4 | Header: "MySQL 8.0+/8.4 and MariaDB 10.4+". Collation comment updated to `utf8mb4_unicode_520_ci`. | Documentation |
| 6 | `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci;` | Connection collation matches the columns |
| 26, 57, 69, 80, 95, 108, 120, 130, 142, 198, 208, 219, 232, 244, 263, 279, 292, 301, 309, 320, 333, 343, 354, 368, 413, 425, 442, 455, 489, 496, 509, 520, 533, 542, 551, 563, 573, 583, 592, 608, 633, 644, 655, 691, 704, 718, 732, 753, 763, 778, 789, 810, 819, 832, 839, 854, 866, 878, 889 (59 tables) | `COLLATE=utf8mb4_0900_ai_ci` → `COLLATE=utf8mb4_unicode_520_ci` | MariaDB 10.4 fails with error 1273 on the 0900 collation. `unicode_520_ci` is still accent- and case-insensitive, which US-06 needs. Side effect: it pads with spaces, so trailing spaces are ignored in comparisons; inputs are trimmed anyway. |
| 394 / 397 | Move the `status` line above `active_microchip` | MySQL allows a generated column to refer to a later base column. MariaDB probably does too; moving the line removes the doubt at no cost (the table has no data yet). The smoke tests in c3 verify it on both. |
| 49, 271, 286, 747, 748, 785, 827, 846 (`JSON`) | No change | MariaDB 10.4 maps `JSON` to `LONGTEXT` with an automatic `CHECK (JSON_VALID())`; MySQL keeps native JSON. The app rule is in c2. |
| 815/818 `row_number` | Optional rename to `source_row_no`. Default: keep it and always backtick it. | Reserved word in MySQL 8. The DDL loads because it is quoted, but unquoted queries break. |
| 1094–1108 | Keep the seed rows | Portable |

The file contains no expression defaults, CHECK constraints, triggers, views, or DESC/invisible indexes.

### c2. Portability rules for all application SQL

A CI guard enforces these by grepping `src/` and `public/`.
- **JSON:** use `JSON_EXTRACT` and `JSON_UNQUOTE` only. Never `->` or `->>`, `JSON_TABLE`, `JSON_ARRAYAGG` or `JSON_OBJECTAGG`.
- **Upserts:** no `INSERT … AS alias`, and no `VALUES()` in upserts (use the Ledger pattern).
- **Not available on MariaDB 10.4:** `SKIP LOCKED`, `NOWAIT` and `LATERAL`. Claim queued jobs with `UPDATE … SET status='Running' WHERE status='Queued' … LIMIT 1` and check the affected-row count. `REGEXP_LIKE` and `UUID_TO_BIN` are MySQL-only; generate UUIDs in PHP.
- **Time zones:** never `CONVERT_TZ` with named zones; time-zone tables may not be loaded on shared hosting or XAMPP.
- **Phonetic surname:** computed in PHP (`metaphone`) and stored in `surname_phonetic`.
- **Accent-only difference check (US-06):** compare with `COLLATE utf8mb4_bin`, which exists on both.
- **Migrations:**
  - never use `ADD COLUMN IF NOT EXISTS`, `DROP COLUMN IF EXISTS` or `CREATE INDEX IF NOT EXISTS` (MariaDB-only); `CREATE TABLE IF NOT EXISTS` is fine;
  - every `CREATE TABLE` states `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci` and has a primary key;
  - new ENUM values are appended at the end only (an in-place change on both databases).

### c3. How to verify the load on both databases
1. **MariaDB 10.4 (XAMPP):** `C:/xampp/mysql/bin/mysql.exe -u root -e "CREATE DATABASE pfpms_verify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"`, then `php bin/migrate.php --config=config/verify-mariadb.php`.
2. **MySQL 8.4 and Percona 8.4 (Docker is installed here):** `docker run -d --name pfpms-my84 -e MYSQL_ROOT_PASSWORD=dev -p 3384:3306 mysql:8.4`, and optionally `percona/percona-server:8.4` on port 3385. Run the same migrate command.
3. **Schema checks (`tests/Integration/SchemaContractTest`, run on both):**
   - table count and foreign-key count from `information_schema.TABLES` and `REFERENTIAL_CONSTRAINTS`: 59 / 143 at 0001, plus v2.1 additions;
   - no table has a collation other than `utf8mb4_unicode_520_ci` (MariaDB JSON columns report `utf8mb4_bin`, which is allowed);
   - `SHOW WARNINGS` after the load is empty apart from known deprecations.
4. **Smoke tests on both:**
   - 'Muñoz' is found by `munoz`, and 'José' = 'JOSE';
   - two Active pets with the same chip gives error 1062;
   - setting one Inactive clears `active_microchip` and allows the second;
   - reactivating the first gives 1062 again;
   - JSON round-trip through `JSON_EXTRACT`.
5. **Diff:** `mysqldump --no-data` from both databases and compare. The only expected difference is JSON vs LONGTEXT.

### c4. Migrations folder and runner
- **Folder layout:**
  - `migrations/NNNN_description.sql` or `.php`. PHP files are used for triggers and anything needing logic. PDO runs a `BEGIN…END` body without a `DELIMITER` line.
  - `migrations/optional/9NNN_*.php` for migrations allowed to fail.
- **`schema_version` table** (created by the runner):
```sql
CREATE TABLE IF NOT EXISTS schema_version (
  version VARCHAR(20) NOT NULL, name VARCHAR(120) NOT NULL, checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL, applied_by VARCHAR(100) NOT NULL, duration_ms INT NOT NULL,
  is_optional TINYINT(1) NOT NULL DEFAULT 0, status ENUM('Applied','Skipped') NOT NULL DEFAULT 'Applied',
  PRIMARY KEY (version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```
- **`bin/migrate.php`** (CLI only):
  - connects with the `migrate_user` credentials and takes `GET_LOCK('pfpms_migrate',10)`;
  - applies pending files in order, one connection;
  - its SQL splitter understands quotes and `--` or `/* */` comments;
  - it checks the SHA-256 of every applied file and refuses if one was edited after being applied;
  - flags: `--status`, `--dry-run`, `--to=NNNN`, `--baseline` (mark as applied without running), `--with-optional`;
  - with `env=prod` it also requires `--confirm-backup`.
- **Rules:**
  - Migrations only go forward.
  - DDL commits implicitly, so keep one logical change per file.
  - If one fails, stop, fix it by hand, re-run.
  - For 0001 on a fresh database, drop the database and rerun.
- **`bin/db-reset.php`** drops and recreates. It refuses unless `env` is dev or test and the DB name ends in `_dev` or `_test`.

### c5. v2.1 additive migrations

Each change is also logged in `docs/PFPMS_schema_v2_1_changes.md` so the design team can fold it into the ERD.

| File | Change | Justification |
|---|---|---|
| **0002_v2_1_settings.sql** | `INSERT IGNORE` the keys in c6. Re-describe `litters_prevented_multiplier` as numeric only. | c6 |
| **0003_v2_1_auth_devices.sql** | `auth_token.purpose` += 'Offline Grant' | UC-01 §3.3.3, offline decision |
| | `auth_token.secret_ciphertext VARCHAR(255) NULL` | Server-encrypted signing key so queued operations can be attributed and checked at sync (UC-06 §4.3) |
| | `user_session.end_reason` += 'Password Reset', 'Deactivated' | UC-01 §3.2.1 (invalidate all sessions), UC-11 §3.2.3 |
| | `device.token_hash CHAR(64) NULL UNIQUE`, `last_seen_at`, `revoked_at`, `revoked_by` (FK) | US-01 "only site-registered devices": the server needs a device credential |
| | `user_account.row_version INT NOT NULL DEFAULT 1` | Two admins editing the same account (UC-11) |
| | `user_account.display_name VARCHAR(50) NULL` | Manage User Profile (UC-01) |
| | `user_account.deactivation_effective_date DATE NULL` | UC-11 §3.2.3 |
| | `user_account.pin_failed_count TINYINT NOT NULL DEFAULT 0` | US-01 protection against PIN guessing |
| | Index `ix_user_site_access_1 (user_id, site_id, ends_at)` | The per-request guard; US-28 |
| | New table `rate_limit_bucket (bucket VARCHAR(120) PK, window_start, hits, blocked_until)` | UC-01 §3.3.1; public endpoints for US-22 and US-07 |
| **0004_v2_1_offline_sync.sql** | `participant.client_uuid CHAR(36) NULL UNIQUE`, `participant.provisional_code VARCHAR(20) NULL UNIQUE` | UC-03 §3.2.3 provisional ID swapped for the permanent one; UC-02 §3.3.3 manually identified participant |
| | `pet.client_uuid CHAR(36) NULL UNIQUE` | UC-05 §3.3.4 queued pet saves |
| | New table `sync_submission (client_uuid PK, op_type ENUM('Distribution','Participant','Pet','CheckIn'), user_id, device_id, offline_grant_id, client_recorded_at, received_at, payload_sha256, status ENUM('Accepted','Rejected','Needs Review'), entity_type, entity_id, error_code, error_detail, payload_ciphertext MEDIUMTEXT NULL, resolved_by, resolved_at)` | UC-06 §3.2.5 and §4.3 (no loss, no duplicates, report failures); UC-03 §3.2.3 |
| **0005_v2_1_notifications.sql** | New table `notification (id, kind, recipient_user_id NULL, recipient_role ENUM NULL, site_id NULL, entity_type, entity_id, message VARCHAR(255), created_by, created_at, read_at, resolved_at, resolved_by, resolution)` | Schema gap 6: UC-01 §3.3.1 and step 11, UC-03 §3.2.3, UC-04 §3.3.3, UC-05 §3.3.2, UC-08 §3.3.3, UC-10 §3.3.3, UC-13 §3.2.4 |
| | New table `outbound_message (id, channel ENUM('Email','SMS'), to_user_id, to_participant_id, to_address, template_key, subject, body_ciphertext MEDIUMTEXT, related_entity_type, related_entity_id, status ENUM('Pending','Sending','Sent','Failed','Cancelled'), attempts, last_error, not_before, created_at, sent_at)` | UC-01 reset, UC-06 receipt, UC-08 voucher and reminders, UC-11 invitation and §3.3.5 resend, UC-13 §3.2.2. Keeps SMTP delay out of the 60-second distribution target. |
| **0006_v2_1_import.sql** | `import_batch.record_type` and `import_mapping.record_type` += 'User' | UC-11 §3.2.4 roster import; US-32 per-type template |
| | `import_batch.status` += 'Queued', 'Running' | UC-12 §4.4: 50k rows validated in the background on shared hosting |
| | `audit_log.import_batch_id INT NULL` + index + FK | UC-12 §3.2.4 rollback of updated rows |
| **0007_v2_1_snv.sql** | `snv_referral.voucher_number` becomes `NULL` (the unique key stays and allows many NULLs) | UC-08 §3.2.3 and §4.1: a declined referral must not use up a number; US-41 counts declined offers |
| **0008_v2_1_participant_pet.sql** | `pet.limit_override_by INT NULL` (FK) and `pet.is_limit_exception TINYINT(1) NOT NULL DEFAULT 0` | UC-05 §3.3.3 |
| | `participant.status_before_delete ENUM('Active','Inactive') NULL` and `pet.inactivated_by_owner_delete TINYINT(1) NOT NULL DEFAULT 0` | UC-09 §3.2.4 restore |
| | `participant.declared_pet_count TINYINT NULL` | UC-03 step 6 |
| | `participant.card_version SMALLINT NOT NULL DEFAULT 1` | Invalidating lost cards (UC-03 card, UC-02 §3.2.1 scan) |
| | Optional, owned by F5: `unmet_request (id, check_in_id, species_id, food_form, product_id NULL, quantity_units NULL, reserve_for_event_id NULL, recorded_by, recorded_at)` | UC-06 §3.3.3 |
| **0009_v2_1_sequences_lookups.sql** | New table `id_sequence (seq_name PK, prefix, next_value BIGINT)` with rows `participant` and `voucher`. Allocation uses `UPDATE … SET next_value=LAST_INSERT_ID(next_value+1)` inside the business transaction: the row is locked, and a rollback leaves no gap. | UC-03 "no Participant ID used up on failure" (schema gap 16); UC-08 §4.1 |
| | New table `lookup_value (list_key, value_code, label, display_order, is_active, PK(list_key, value_code))`, seeded with: pet_colour, participant_delete_reason, pet_delete_reason, referral_source, proof_of_residence_type, deactivation_reason, emergency_reason, decline_reason | Schema gap 10: UC-05 §4.5, UC-09 and UC-10 step 5, UC-15 grouping, UC-04 §3.2.3, UC-06 §3.2.2, US-17 |
| | `system` user_account row (status Inactive, reserved username, unusable hash `!`) | Needed because `created_by` and `granted_by` are NOT NULL for seeds and cron |
| **optional/9001_immutability_triggers.php** | See c8 | — |

**Listed for the design team, not built now:**
- a clinic ↔ site link (UC-08 §3.3.4)
- participant and pet status history (UC-15, UC-16)
- vaccinations other than rabies
- a body-type vocabulary (US-13)
- seasonal factors (US-36)
- a shift roster (US-38)
- participant self-service tokens (US-10, US-19)
- aligning `allotment_rule.food_form` with `product.food_form`

### c6. System setting keys added in 0002

The 12 keys in the v2 seed stay as they are.

| Key | Default | Source |
|---|---|---|
| `organisation_name` | CHS Pet Pantry | UC-01 login screen |
| `lockout_minutes` | 15 | UC-01 §3.3.1 |
| `session_absolute_hours` | 12 | UC-01 §4 |
| `password_min_length` | 12 | UC-01 §4.1 |
| `password_max_age_days` | 0 (off; NIST 800-63B) | UC-01 §4.1 |
| `password_hibp_check` | 0 | UC-01 §4.1 |
| `reset_link_minutes` | 60 | UC-01 §3.2.1 |
| `temp_credential_hours` | 72 | UC-11 §4.2 |
| `trusted_device_days` | 30 | UC-01 §3.2.3 |
| `pin_min_digits` | 4 | US-01 |
| `pin_max_failed` | 3 | US-01 |
| `offline_grant_hours` | 72 | UC-01 §3.3.3 |
| `offline_cache_days` | 30 | UC-02 §3.3.3 |
| `volunteer_max_sites` | 2 | UC-11 §3.3.2 |
| `account_review_days` | 90 | UC-11 §4 |
| `search_max_results` | 100 | UC-02 §3.3.2 |
| `recent_participants_days` | 30 | UC-02 §3.2.2 |
| `history_page_size` | 25 | UC-07 |
| `photo_max_mb` | 5 | UC-05 §4.4 |
| `over_allotment_auth_level` | Administrator | UC-06 §4.5 |
| `emergency_auth_level` | Administrator | UC-06 §3.2.2 |
| `programme_year_start` | 01-01 (MM-DD) | UC-07, UC-14 §3.2.1, year-to-date reset |
| `distribution_retention_years` | 7 | UC-07 §4 |
| `auth_audit_retention_months` | 12 | UC-01 §4 |
| `audit_statutory_retention_years` | 7 | UC-04 §4 |
| `import_max_file_mb` | 25 | UC-12 |
| `import_max_rows` | 50000 | UC-12 |
| `import_max_reject_pct` | 20 | UC-12 §3.3.3 |
| `report_max_span_months` | 36 | UC-13 §3.3.2 |
| `report_interactive_seconds` | 30 | UC-13 |
| `report_result_retention_days` | 30 | UC-13 §3.2.4 |
| `voucher_reminder_days` | 14 | UC-08 |
| `flag_review_days` | 180 | UC-15 §3.2.4 |
| `self_service_draft_hours` | 24 | US-07 |
| `volunteer_draft_days` | 14 | US-08; `registration_draft.expires_at` is NOT NULL |
| `welfare_prompt_multiplier` | 2.0 | US-20 |
| `pet_presence_review_days` | 365 | US-27 |
| `policy_reack_days` | 365 | US-30 |
| `policy_reack_grace_days` | 30 | US-30 |
| `litters_prevented_citation` | (blank) | US-40: a separate citation key |
| `live_dashboard_refresh_seconds` | 15 | US-04, US-18 |

All defaults marked for client confirmation.

### c7. Seed data
- **In migrations (system level):** v2 language, species and settings; the v2.1 settings; lookup lists; `id_sequence` rows; the `system` user.
- **`seeds/reference/*.sql` through `bin/seed.php reference`:** idempotent `INSERT IGNORE` on natural keys. The client reviews the values before they reach prod; each item is on the go-live checklist.
  - `site`: 1 placeholder, time zone America/New_York.
  - `size_band`:
    - Dog: Toy 0–10, Small 10.1–25, Medium 25.1–50, Large 50.1–90, Giant 90.1+
    - Cat: Small 0–8, Standard 8.1–15, Large 15.1+
  - `allotment_rule` v1: one row per species × band, Dry, `created_by` = system user. **The numbers are placeholders for the client.**
  - `item_category`: Dry Dog Food, Wet Dog Food, Dry Cat Food, Wet Cat Food, Treats.
  - `product`: generic products per species × form, with `unit_weight_lbs`.
  - `policy_document` v1 in en and es for all 4 document types. Text marked DRAFT for the client to supply. The Confidentiality Agreement is required before the first login.
  - `service_area_postal_code`: loaded from a client-supplied CSV through `bin/seed.php postal-codes file.csv`. **Blocks registration until loaded.**
- **`seeds/dev/`** (refuses to run when `env=prod`): one sample user per role, synthetic participants and pets, an open event, stock. Used by developers and tests.

### c8. Keeping distribution and audit tables immutable when table grants aren't available

Protected tables: `distribution`, `distribution_line`, `distribution_pet`, `audit_log`, `audit_field_change`, `snv_referral_status_log`, `inventory_transaction`.

1. **Application layer (primary).**
   - Code for these tables has only insert and select methods.
   - A CI guard fails the build on any `UPDATE`/`DELETE`/`REPLACE`/`TRUNCATE` against these tables in `src/` or `public/`, except in three allowlisted services: `ImportRollbackService` (UC-12 §3.2.4, only rows with the batch's `import_batch_id` that were never used), `ErasureService`, and `RetentionService` (disabled for now).
   - Merges follow `participant.merged_into_id` when querying rather than rewriting distributions.
   - Corrections are reversing rows.
2. **Database users (verify in Site Tools → MySQL → Users → Manage Access).**
   - `pfpms_app` gets SELECT/INSERT/UPDATE/DELETE only; `pfpms_owner` gets DDL.
   - Privileges are per database, so the app user still can't DROP or ALTER.
   - Table-level REVOKE is almost certainly unavailable (needs GRANT OPTION).
3. **Optional triggers (`optional/9001`).**
   - `BEFORE UPDATE` and `BEFORE DELETE` on each table: `IF COALESCE(@pfpms_allow_mutation,0) <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable table'; END IF`.
   - The allowlisted services set `@pfpms_allow_mutation=1` inside their transaction, write an audit row, and reset it.
   - On SiteGround, creating triggers may be refused (binary logging without SUPER or `log_bin_trust_function_creators`). The runner then records the migration as Skipped and warns; app-layer enforcement still holds.
   - CI loads the triggers on both databases so they stay correct.

### c9. Cut-over from the legacy database
- **Start fresh; no data migration.** Legacy inventory is human food and there are no pets or participants.
  - Dev: new `pfpms_dev` and `pfpms_test` databases on XAMPP.
  - Prod: a new SiteGround site or database for PFPMS with the two users above. `[redacted-db-name]` and its user are never reused.
  - After CCDA signs off, the legacy database is exported into the encrypted archive from a5 and then dropped.
- **First Administrator:** `bin/create-admin.php` over SSH.
  - Prompts for username and email.
  - Creates the account as Pending with an Administrator role and access to all sites.
  - Prints a single-use temporary credential once (never logged).
  - Refuses if an active Administrator already exists, unless `--force` is given with a reason, which is audited.
- **About 4 real staff accounts:** recreate them through the new UC-11 screen. This is preferred because it also tests the feature.
  - Optional `bin/import-legacy-staff.php --dry-run`, run against the isolated restore. It keeps only rows that are Active, have a non-empty email, and are not `vmsroot`, `vmsroot2`, `admin` or `inventory`.
  - Role mapping: superadmin → Administrator, admin → Coordinator or Administrator (client decides), inventory_counter → Volunteer.
  - Accounts are created Pending with `must_change_password=1`, a new temporary credential and **not the old hash**, access to the default site, and an audit row `LegacyStaffImported`.

---

## (d) Testing and tooling

### d1. Composer and the `vendor/` policy
- **Recommended:** stop committing `vendor/` (`git rm -r --cached vendor`; it stays in history). CI builds it with `composer install --no-dev --classmap-authoritative`, and deploys ship it.
- **Fallback if CI can't reach SiteGround:** `bin/build-release` produces a zip containing the no-dev `vendor/` for SFTP upload (the current README practice).
- **`composer.json`:**
  - `require`:
    - `php >=8.2`, `ext-pdo_mysql`, `ext-openssl`, `ext-mbstring`, `ext-json`, `ext-gd`, `ext-zip` (XLSX)
    - `phpmailer/phpmailer ^7.0`, `phpoffice/phpspreadsheet ^5.6`
  - `require-dev`: `phpunit/phpunit ^11.5`, `phpstan/phpstan ^2`.
  - Autoload `Pfpms\\` → `src/`; `config.platform.php = 8.2.0`; scripts `test`, `lint`, `migrate`.
- **PHP version:** PHP 8.2 stops receiving security fixes on 31 Dec 2026.
  - Run prod on 8.3 or 8.4 (Site Tools PHP Manager), and keep the code compatible with 8.2–8.4.
  - CI runs a PHP matrix of 8.2, 8.3 and 8.4.
  - XAMPP stays on 8.2.4 for now.
- **Extensions on XAMPP:** enable `extension=gd`, `zip`, `intl` (optionally `sodium`) in `C:/xampp/php/php.ini`. Checked today: gd, zip, intl and sodium are all off, while argon2id and aes-256-gcm are available.
- **SiteGround:** confirm the same extensions are on. Set `upload_max_filesize=25M`, `post_max_size=30M` and `max_execution_time` in PHP Manager.

### d2. PHPUnit and the test database
- `phpunit.xml.dist` has Unit and Integration suites.
- `tests/bootstrap.php`:
  - loads `config/config.test.php` (or `PFPMS_CONFIG`);
  - refuses unless the DB name ends in `_test`;
  - runs `db-reset` and `migrate --with-optional` once per run;
  - loads `seeds/dev` minimal fixtures.
- Each test truncates in foreign-key order (with `FOREIGN_KEY_CHECKS=0`).
- Every test takes a `FrozenClock`.
- Developers run it against XAMPP MariaDB, or `docker compose -f tools/docker-compose.db.yml up`, which starts `mariadb:10.4` on port 3310 and `mysql:8.4` on port 3384.

### d3. Required tests for the foundation
- **Unit tests:**
  - validators, including the fixed legacy bugs;
  - `PasswordPolicy`;
  - `Crypto` round-trip, tamper detection (modified tag or authenticated data fails), key rotation;
  - settings registry types and ranges;
  - capability invariants:
    - Board has no write capability;
    - Volunteer has no `*.delete`, `import.*`, `user.manage` or `settings.manage`;
    - every capability used in the code exists in the matrix (found by scanning);
  - `Menu` hides admin items from volunteers.
- **Integration tests (run on MariaDB 10.4 and MySQL 8.4):**
  - **Database layer:**
    - nested transactions and savepoints; deadlock retry;
    - `updateVersioned` conflict raises `ConcurrencyException`;
    - `isDuplicateKey`.
  - **Audit:** the audit row rolls back with the business write; a `durable` audit row survives the rollback; field-change diff; redaction.
  - **Authentication:**
    - login success;
    - generic failure message;
    - lockout at `max_failed_logins` and automatic unlock after `lockout_minutes`;
    - an Admin notification is created;
    - Pending, Inactive and expired accounts are denied;
    - timeout after 30 minutes idle and after 12 hours absolute;
    - remote sign-out and permission change end the session on the next request;
    - reset token is single-use and expires, and a reset ends all sessions;
    - temporary credential 72 h and single use;
    - forced change and policy acknowledgement gates;
    - PIN switch works only on a registered device, and falls back to password after `pin_max_failed`.
  - **Access control:**
    - `Page::start` denies before any POST side effect: an unauthenticated POST changes nothing and writes an audit row;
    - a time-limited site grant lapses mid-session;
    - `site.all`.
  - **CSRF:** a missing or wrong token gets 400 and no write.
  - **Distribution and ledger:**
    - one commit writes `distribution`, lines, pets, `inventory_transaction` and `site_stock` together;
    - a failure injected after the ledger post leaves no rows and unchanged stock;
    - replaying the same `client_uuid` returns the original row and moves stock once;
    - two connections decrementing the same stock row serialise correctly;
    - the negative-stock rule holds (online refused, offline allowed with a notification);
    - a reversal restores stock;
    - with triggers loaded, an UPDATE or DELETE on an immutable table fails.
  - **Sequences:** `id_sequence` leaves no gap after a rollback.
  - **Schema:** `SchemaContractTest` from c3.
  - **Page contract:** tokenise every `public/*.php` and assert its first statement after the bootstrap require is `Page::start(` or `Api::start(`, except for allowlisted public pages.

### d4. CI (`.github/workflows/ci.yml`, on push and pull request)
- **`lint` job:**
  - setup-php on 8.2, 8.3 and 8.4 with pdo_mysql, gd, zip, mbstring, intl;
  - `composer validate` and `composer install`;
  - `php -l` on every file outside `vendor/`;
  - PHPStan level 5 on `src/`;
  - `bin/ci/guard.php`, which fails the build on:
    - any `*.sql` outside `migrations/`, `seeds/` or `docs/`, or any `*.log` or `*.zip`;
    - `0900_ai_ci` or `IF NOT EXISTS` on a column in `migrations/`;
    - `->>`, `VALUES(` or `SKIP LOCKED` in the code;
    - UPDATE or DELETE on an immutable table outside the allowlist;
    - any include of `legacy/`;
  - gitleaks.
- **`db` job:**
  - matrix `image: [mariadb:10.4, mysql:8.4, percona/percona-server:8.4]`, run as a GitHub service with a `mysqladmin ping` health check;
  - steps: `php bin/migrate.php --with-optional`, then `vendor/bin/phpunit --testsuite Integration`, then a second migrate run to prove it is idempotent (reports "nothing to do").

### d5. Deploying to SiteGround

> **As built (Phase 2B):** maintenance is the config switch `app.maintenance` (every API and the Station's `sw.php` answer 503 with `Retry-After`). It must stay on from before the first rsync until both rsync steps (`public/`, then `src/`) and the migration have finished, so no tablet installs a half-deployed Station build (50-design D-58). Then run `bin/station-smoke.php` against the site.


**One-time setup:**
- a new site and database, the two DB users, an SSH key (SiteGround SSH port 18765; verify);
- PHP 8.3 or later with the extensions above;
- **turn Dynamic Cache off** for this site (Site Tools → Speed → Caching). The app is fully authenticated and also sends `no-store`;
- SSL through Let's Encrypt with HTTPS Enforce. HTTPS is required because service workers, WebCrypto and camera barcode scanning all need a secure context;
- create `~/www/<domain>/{config,storage/...}` with permission 700;
- write `config.php`;
- cron entries in Site Tools → Devs → Cron:
  - `php ~/www/<domain>/bin/cron.php mail:send` every minute
  - `php ~/www/<domain>/bin/cron.php all` every 5 minutes
  - use the full PHP binary path that Site Tools shows.

**`.github/workflows/deploy.yml`** (`workflow_dispatch`, a protected `production` environment, deploys a tag):
1. `composer install --no-dev`.
2. SSH: `bin/maintenance.php on`.
3. SSH: `mysqldump --defaults-extra-file=~/www/<domain>/config/my.cnf … | gzip > ~/backups/pfpms-<ts>.sql.gz`.
4. rsync `public/` → `public_html/` with `--delete`, excluding `.well-known/`.
5. rsync `src templates bin migrations seeds/reference vendor data` → `~/www/<domain>/`. Never `config/` or `storage/`.
6. SSH: `php bin/migrate.php --confirm-backup`.
7. `bin/maintenance.php off`.
8. Run `curl https://<domain>/healthz.php` and expect 200 with the schema version and nothing else.

**Rollback:** redeploy the previous tag. If a migration broke, restore the backup; migrations only go forward.

**Don't use SiteGround's Git tool:** it would put `.git` inside the document root and it doesn't run composer.

**Manual fallback:** SFTP the release zip, then run `migrate.php` over SSH. Never run migrations through a web endpoint.

---

## (e) Legacy code to reuse or rewrite, and the page skeleton

### Reuse or rewrite

**Port (copy, with tests) into `src/Validation/Validator.php`:**
- As-is:
  - `include/input-validation.php:validateDate` (L109)
  - `validate12hTimeAndConvertTo24h` (L147)
  - `validate12hTimeRangeAndConvertTo24h` (L135)
  - `validateAndFilterPhoneNumber` (L155, 10-digit US)
  - `validateEmail` (L163, plus a 100-character cap)
- Fix, then port:
  - `validate24hTime` (L127): the regex needs `^…$` anchors.
  - `validate24hTimeRange` (L117-119): it checks `$start` twice and never `$end`.
  - `wereRequiredFieldsSubmitted` (L167): default `$blankOkay=false`; treat whitespace-only as blank; `"0"` counts as filled.
  - `validateZipcode` (L176): accept ZIP+4 and normalise it.
  - `valueConstrainedTo` (L184): use strict `in_array(...,true)`.
  - `validateURL` (L207): allow only http and https (for `clinic.directions_url`).

**Replace:**
- `isSecurePassword` (L211) → `PasswordPolicy`.

**Do not reuse:**
- `_sanitize` (L16), `sanitize` (L52), `sql_safe_input` (L77), `sql_safe_associative_array` (L86). They store HTML entities in the data, open a connection per call, and leave array values unescaped.
- `trainingLevelMet` (L12), `convertYouTubeURLToEmbedLink` (L188).
- **Never include this file:** lines 3-4 pull in `dbinfo.php` and `dbPersons.php`.

**`include/output.php`:**
- `hsc` (L3): keep, hardened to `htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5, 'UTF-8')`, recursive, with an `e()` alias.
- `time24hTo12h` (L14), `floatPrecision` (L37): keep.
- `formatPhoneNumber` (L32): keep, returning the raw value unless it has 10 digits.
- `unpackMessageTimestamp`, `prepareMessageBody` (which injects raw HTML): drop.

**`include/api.php:redirect`** → `Response::redirect`, relative paths only.

**Database layer:**
- `database/dbinfo.php:connect` → `Db::pdo()`.
- The prepared-statement style in `dbShifts.php` and `dbGroups.php` is a style reference only.
- The `dbPersons.php:make_a_person` mapper and the domain classes are not ported; use arrays or small DTOs.

**Reports:**
- `reportsCompute.php:pretty_date` (L115) and `calculate_age` (L295) parse 2-digit years. Replace with `DateTimeImmutable` and `diff`.
- `export_report` (L447) and `reportsExport.php:export_data` (L152) write fixed files into the web root and call an undefined function. Replace with an Export service that streams `fputcsv` output with a UTF-8 BOM, or XLSX following `processInventoryReport.php` L9-10 and L164-168. It writes an `audit_log` Export row and adds a footer.
- Keep `js/jspdf.umd.min.js` and `js/jspdf.plugin.autotable.min.js`, self-hosted, for client-side PDFs and offline receipts.

**Crypto and uploads:**
- `emailEncryption.php:encryptEmail`/`decryptEmail`: drop (CBC with no MAC and a key in the repo).
- `upload_encrypted_image.php:compressAndEncryptImage` and `serve_image.php`: keep only the ideas: re-encode with GD, then encrypt; storage outside the web root, as in `security_config.php`'s `dirname(__DIR__).'/secure_uploads/'`; serve through PHP after an auth check. Rewrite with GCM, random names and entity-level authorisation.

**UI:**
- `login.php`, `header.php`, `index.php`, `universal.inc` are rewritten; only `password_verify` usage carries over.
- The dropdown CSS and `css/header.js` can be reused, minus the accessibility-button TypeError.
- The `css/base.css` structure is kept, rebranded (CCDA palette at L70-77).
- Keep the GPL-3.0 licence and the Homebase copyright headers. New files carry `SPDX-License-Identifier: GPL-3.0-or-later`.

### Skeleton every page follows
```php
<?php // public/participant_edit.php  (SPDX-License-Identifier: GPL-3.0-or-later)
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\{Page, Request, Response, Flash};
use Pfpms\Security\Csrf;
use Pfpms\{Db, View};
use Pfpms\Participant\{ParticipantRepo, ParticipantValidator};
use Pfpms\Db\ConcurrencyException;

// 1. Guard BEFORE touching input: session, forced change, policy ack, capability, site
$ctx = Page::start(['capability' => 'participant.update', 'site' => true]);

// 2. Load, always scoped to the sites the user can see
$p = ParticipantRepo::findVisible(Request::int('id'), $ctx) ?? Response::notFound();
$errors = [];

// 3. Handle POST: CSRF, validate, one transaction (the audit is written inside it), then redirect
if (Request::isPost()) {
    Csrf::verify();
    $in = Request::only(['legal_first_name', 'legal_last_name', 'postal_code', 'row_version']);
    $errors = ParticipantValidator::update($in, $ctx);
    if (!$errors) {
        try {
            Db::transaction(fn() => ParticipantRepo::update($p, $in, (int)$in['row_version'], $ctx));
            Flash::success('Participant updated.');
            Response::redirect('participant_view.php?id=' . $p['participant_id']);
        } catch (ConcurrencyException $e) {
            $errors['_form'] = 'Someone else changed this record.';
            $conflict = $e->current;   // shown field by field (UC-04 §3.3.2)
        }
    }
}

// 4. Render: templates escape everything with e(); the layout draws the menu from capabilities
View::render('participant/edit', compact('ctx', 'p', 'errors') + ['title' => 'Edit participant', 'conflict' => $conflict ?? null]);
```
API endpoints follow the same shape with `$ctx = Api::start(['capability' => '…', 'site' => true]);` and `Response::json()`. CSRF is taken from the `X-CSRF-Token` header.

---

## Ordered task list for the foundation

| # | Task | Size | Depends on |
|---|---|---|---|
| **T0.1** | Rotate or revoke the secrets in a1 (account holders) | S | — |
| **T0.2** | Old live site: decommission, or lock down with the interim `.htaccess` and deletions | S | T0.1 |
| **T0.3** | Branch `pfpms/phase-0`; `git rm` the sensitive files; make the encrypted offline archive; notify third parties | S | — |
| **T0.4** | `.gitignore`, `.gitattributes`, `.editorconfig` | S | T0.3 |
| **T0.5** | Delete the 110 dead pages plus the dead db, domain, email, lib, image and Tailwind files | M | T0.3 |
| **T0.6** | Quarantine the remaining 44 pages and the reference db/domain/include/lib files into `legacy/` with a deny `.htaccess` | S | T0.5 |
| **T0.7** | Git history purge. **Needs the user's explicit OK;** coordinate re-clones | S | T0.3 |
| T1 | Directory skeleton, root and `public/.htaccess`, `composer.json` (autoload, requirements, platform), stop committing `vendor/` | S | T0.6 |
| T2 | `Config`, `config.example.php`, `bin/generate-key.php` | S | T1 |
| T3 | `bootstrap.php`: errors, logging, UTC, session settings, headers and CSP, maintenance mode | M | T2 |
| T4 | `Db` (PDO, session settings, savepoints, retry, helpers, versioned update, durable connection) and `Clock` | M | T2 |
| T5 | MariaDB-safe v2.0.1 baseline (c1) and `migrations/0001` | S | — |
| T6 | `bin/migrate.php` with `schema_version`, checksums, lock, optional migrations, `--baseline` | M | T4, T5 |
| T7 | v2.1 migrations 0002–0009 and `docs/PFPMS_schema_v2_1_changes.md` for the ERD team | M | T6 |
| T8 | `bin/seed.php`, reference seeds for client review, dev fixtures, `bin/db-reset.php` | M | T7 |
| T9 | `Settings` and registry | S | T4, T7 |
| T10 | `Audit` (in-transaction and durable, diffs, snapshots, redaction) | M | T4 |
| T11 | `Crypto`, `RateLimit` | M | T2 |
| T12 | Authentication: `SessionManager`, `Auth`, `PasswordPolicy` and blocklist, `Tokens`; the login, logout, forgot, reset, change, activate, policy_ack and select_site pages | L | T9–T11 |
| T13 | `capabilities.php`, `Rbac`, `SiteAccess`, the `Page::start`/`Api::start` pipelines | M | T12 |
| T14 | `Csrf` | S | T3 |
| T15 | Validation port and output helpers (e) | S | T1 |
| T16 | `View`, layout and `Menu` (capability-driven nav, site switcher, flash, notification badge); `app.css` consolidation and CHS rebrand | M | T13 |
| T17 | `Mailer`, `Outbox`, dev log transport | M | T11 |
| T18 | `SecureFileStore`, `ImageProcessor`, `public/file.php` | M | T11, T13 |
| T19 | `bin/cron.php`, job locks and the core jobs | S | T17 |
| T20 | `Notifications` and `notifications.php` | S | T13 |
| T21 | `Devices`, PIN switch (US-01, a Must), trusted device, `OfflineGrant` and `api/offline_grant.php` | M | T12 |
| T22 | PWA hooks: manifest, app-shell `sw.js`, `api/session.php`, the `api/sync.php` contract with `sync_submission` replay. Hand off to the offline workstream | M | T13, T21, T7 |
| T23 | `Inventory\Ledger` and the `Distribution\Writer` skeleton (one commit path for web and sync) | M | T4, T10 |
| T24 | `bin/create-admin.php`; optional `bin/import-legacy-staff.php` | S | T12 |
| T25 | PHPUnit setup, `tools/docker-compose.db.yml`, the d3 tests | L | T4–T23 |
| T26 | CI: lint, PHPStan, guard, gitleaks, database matrix | M | T25 |
| T27 | SiteGround setup, `deploy.yml`, backup step, `healthz.php` | M | T26 |
| T28 | Rewrite `README.md` (XAMPP vhost and extensions, Docker DBs, deploy runbook, security notes; remove the vmsroot instructions) | S | T27 |

**Critical path:** T0.1–T0.3 → T1–T4 → T5–T7 → T12–T13 → T21–T23. At that point the participant, distribution and offline workstreams can start in parallel.

### To confirm before or during implementation
**SiteGround:**
- Is the document root fixed, and does NGINX serve static files without `.htaccess`?
- Can each DB user get its own privileges?
- Are triggers allowed with binary logging on?
- Is PHP 8.3 or later available with gd, zip, intl and argon2?
- Is SSH on port 18765 with rsync available?
- What is the minimum cron interval?
- Can Dynamic Cache be turned off?
- What are the SMTP host details?

**Repo and legacy site:**
- Is the GitHub repo public?
- Does CCDA still use the old site?

**Database:**
- MariaDB's handling of the generated-column order. Moving the line makes this moot.

**Client:**
- Role matrix (notes §4.1).
- Placeholder allotment values and size bands.
- Service-area ZIP list.
- Policy texts.
- `programme_year_start`.

### Critical files for implementation
- C:/Users/maryw/Documents/Pelican/chsPetPantry/docs/PFPMS_schema_v2.sql (the baseline being edited; copied to `migrations/0001_pfpms_schema_v2.sql`)
- C:/Users/maryw/Documents/Pelican/chsPetPantry/src/bootstrap.php (new: config, errors, sessions, headers, maintenance)
- C:/Users/maryw/Documents/Pelican/chsPetPantry/src/Db.php (new: PDO setup, transactions, versioned updates, durable connection)
- C:/Users/maryw/Documents/Pelican/chsPetPantry/src/Http/Page.php with src/Auth/capabilities.php (new: the guard pipeline and role matrix every page depends on)
- C:/Users/maryw/Documents/Pelican/chsPetPantry/bin/migrate.php (new: migration runner and `schema_version`)
