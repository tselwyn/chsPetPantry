# PFPMS Transformation Plan: chsPetPantry → Pet Food Pantry Management System

## 1. Context

The repo is an inherited plain-PHP "Homebase"-lineage app, most recently the CCDA **human**-food pantry inventory system.
- **Pages:** it has 156 root pages. 110 are dead volunteer, event and messaging features. The rest run string-built mysqli SQL against human-food tables.
- **Security:** live secrets, a forgeable password reset, no CSRF protection, SQL injection in 31 files, and a committed 23 MB site snapshot.

The client product is the **Pet Food Pantry Management System (PFPMS)**, specified in `docs/`:
- schema: `PFPMS_schema_v2.sql` (59 tables, 143 FKs; the source of truth) and its notes;
- 16 use cases in the specs, plus UC-17 to UC-19 from the use-case diagram;
- 41 user stories (6 Must, 23 Should, 12 Could).

The legacy database has **no participant, pet or pet-food data**, so this is a rebuild on the new schema inside the existing repo, not a data migration.

**Outcome:** Release 1 is a guarded, audited, transactional PHP app on SiteGround. It can run a full distribution day **offline** on tablets. Governance, full import, reporting and the Could stories follow in later phases.

> **Where the detail lives.** This plan condenses about 400 KB of analysis produced in this session: six analyst reports, three design perspectives, a merged plan and four adversarial reviews. **The first execution step copies those documents into `docs/design/`** so the team keeps the full reasoning, line references and per-module detail.

## 2. ⚠ Urgent, before anything else (needs the owners' action and your OK)

The GitHub repo **`tselwyn/chsPetPantry` is PUBLIC** (verified through the GitHub API). So is `r-brt/FoodPantry`, whose `.git` is nested inside `public_html.zip`. Anyone can currently read:
- the SiteGround production DB password (`database/dbinfo.php:26-29`);
- a Gmail app password (`forgotPassword.php:16-23`);
- a hard-coded encryption key (`emailEncryption.php:3`);
- real CCDA staff names, emails and bcrypt hashes (`sql/foodpantrydb.sql:4198-4211`), and `vmsroot`/`vmsroot` default admin accounts.

Needed actions:
1. **Rotate or revoke the secrets now.** Account owners must do this; I can't.
2. **Make the repo private.** Needs your OK.
3. **Lock down or decommission all four old SiteGround sites:** `jenniferp130`, `160`, `217` and `231`. Disable the `scheduledSend` cron on 217 and 231, and block `/.git` on each.
4. **History purge is strongly recommended** (`git filter-repo`, force-push all 5 branches, plus a GitHub Support ticket to drop the `refs/pull/1..6` PR refs). It needs your explicit OK.

## 3. Decisions

| # | Decision | Source |
|---|---|---|
| D1 | In-place plain PHP, one file per page, with a new shared core: PDO transactions, role and site guard, CSRF, audit writer, settings loader. No framework or ORM. | You |
| D2 | One DDL loads on **XAMPP MariaDB 10.4** and **SiteGround Percona/MySQL 8.4**: `utf8mb4_unicode_520_ci` (still accent- and case-insensitive, so US-06 holds), no MySQL-8-only syntax | You |
| D3 | `docs/PFPMS_schema_v2.sql` is the baseline (becoming v2.0.1). Gaps are fixed by **additive v2.1 migrations**, each logged in `docs/PFPMS_schema_v2_1_changes.md` for the ERD team. | You |
| D4 | **Offline PWA in Release 1**, on the critical path. It covers queued distributions, cached search, provisional registration, queued pet saves and offline sign-in. | You |
| D5 | **Full Release 1 scope as designed**, not the pilot cut | You |
| D6 | All 4 schema roles are supported through a capability matrix (`src/Auth/capabilities.php`), so notes §4.1 is answered by editing config | Default |
| D7 | No legacy data migration. Seed a fresh Administrator by CLI and re-invite the real staff. Legacy hashes are never carried over. | Default |
| D8 | `public/` is the document root (SiteGround `public_html/`). `src/`, `config/`, `storage/`, `vendor/` and `migrations/` live outside it. | Security |
| D9 | One write path for distributions: the Station PWA outbox, then `DistributionService::record()`. Offline items are pushed as soon as there is a connection. | Design |
| D10 | Sync replay **commits and flags** food that already left (`distribution.sync_exception`) and notifies a Coordinator. Only authenticity failures are Held. **This deviates from UC-06 §3.2.5 step 3 and needs client sign-off** (see Q5). | Design |
| D11 | Server-side encryption uses openssl AES-256-GCM with key ids. Sodium ships with XAMPP but is disabled; GCM was chosen for portability. `emailEncryption.php` (CBC, no MAC) is never reused. | Verified |

## 4. Target architecture

```
repo/
├─ public/            → SiteGround public_html/ (the only web-reachable tree)
│  ├─ index.php login.php … <module>_*.php        flat snake_case pages (participant_search.php, admin_sites.php …)
│  ├─ api/<area>/<action>.php                      JSON endpoints (auth, device, sync, station, event)
│  ├─ station/ {index.php, sw.php, js/*.js, css/, vendor/idb.js}   PWA, SW scope /station/
│  ├─ clinic/{info,portal}.php                     public, rate-limited (US-21/22)
│  └─ assets/{css/app.css, css/print.css, vendor/bootstrap (copied v5.2.2), fonts (copied + licences)}
├─ src/ (PSR-4 Pfpms\)  core + <Module>/{X}Repository, {X}Service, {X}Validator
├─ templates/  bin/ (CLI only)  migrations/  seeds/{reference,dev}  config/config.example.php
├─ storage/{logs,sessions,secure/…} (0700)  tests/{Unit,Integration,js,e2e,fixtures}  tools/  .github/workflows/
├─ legacy/  (reference-only quarantine, never deployed, NOT runnable; emptied by P7)
└─ docs/ (+ docs/design/ analysis)
```

**Core (`src/`, Phase 1)**
- **`bootstrap.php`:** config; UTC; `display_errors` only in dev; hardened session (`use_strict_mode=1`, HttpOnly/Secure/SameSite); security headers and CSP; **`Cache-Control: no-store, no-cache, private` on every PHP response**, because SiteGround Dynamic Cache ignores `PHPSESSID`.
- **`Config.php`:** reads `PFPMS_CONFIG` or `config/config.php`; never branches on `SERVER_NAME`.
- **`Db.php`:** lazy PDO (`EMULATE_PREPARES=false`, utf8mb4, `SET NAMES … COLLATE utf8mb4_unicode_520_ci`, `time_zone='+00:00'`). `sql_mode` is pinned to `STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`.
  - `transaction()` nests with savepoints, but **retries 1213/1205 only at the outermost level, after a full ROLLBACK**.
  - Helpers: `updateVersioned()` (`row_version` optimistic locking) and `durable()`, a second autocommit connection for Denied/Failed audit rows. **Durable rows must never reference a parent created in the open transaction**; `session_id` or `import_batch_id` is written as NULL.
- **`Http/{Request,Response,Page,Api}`:** `Page::start([capability, site])` runs session → forced password change → policy acknowledgement → capability → site checks **before any input is handled**. `Api::start()` does the same and returns JSON 401/403 (`policy_ack_required`, `password_change_required`), 409, 422 or 503.
- **`Security/{Csrf,Crypto,RateLimit,Headers}`:** CSRF comes from a form field or the `X-CSRF-Token` header, with an Origin check (Safari < 16.4 has no `Sec-Fetch-Site`).
  - **Public POST pages** (login, forgot, reset, clinic portal) also call `Csrf::verify()`.
  - Rate limits key on the real client IP (verify `REMOTE_ADDR` on SiteGround).
- **`Auth/*`:** `user_session` checked on every request (30 min idle, 12 h absolute, still Active, grants not lapsed).
  - Argon2id hashing. `PasswordPolicy` bundles an offline common and breached password list; HIBP is optional.
  - Single-use hashed `auth_token`s. Lockout. PIN; devices; offline grants.
  - **`session_regenerate_id(true)` on login, PIN switch, site switch, role change and policy acknowledgement.**
  - `user_session.session_id` is a random server-generated id stored in `$_SESSION`, **not** PHP's session id. `audit_log` rows reference it, so it can never change; PHP's session id is regenerated freely underneath it.
- **Other core:**
  - `Audit/{Audit,Snapshot}`: `audit_log` and `audit_field_change` written inside the caller's transaction.
  - `Settings` (typed registry with defaults).
  - `Validation/Validator` (ported legacy validators; the same rules are exported as JSON for the Station).
  - `View/{View,Menu}` (`e()` escaping; menu built from `{file, label, capability}`; site switcher).
  - `Mail/{Mailer,Outbox}` (the composer PHPMailer only; **sent inline after commit**, with the outbox used for retries).
  - `Storage/SecureFileStore` (GCM-encrypted files; `file.php` authorises by entity and site).
  - `Notify/Notifications` (work queue).
  - `Cron/Jobs/*` run through `bin/cron.php` (CLI-only guard).
  - `Sync/*`.
- **Locks:** `GET_LOCK` names are `pfpms:<DATABASE()>:<key>`, SHA-1'd if over 64 characters, with timeout 0 (returns 409 if already held).

**Page skeleton** (enforced by a CI page-contract test):
```php
<?php declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$ctx = Page::start(['capability' => 'participant.update', 'site' => true]);
$p = ParticipantService::findVisible(Request::int('id'), $ctx) ?? Response::notFound();
if (Request::isPost()) { Csrf::verify();
  try { ParticipantService::update($p, Request::only([...]), $ctx); Flash::success('Saved.'); Response::redirect(...); }
  catch (ValidationException $e) { $errors = $e->errors; } catch (ConcurrencyException $e) { $conflict = $e->current; } }
View::render('participant/edit', compact('ctx','p','errors','conflict'));
```
Pages contain no SQL. Services own the rules, transactions and audit, and the web pages, the sync handlers and the importer all call the **same Service**, so UC-12 §4.5's "same rules everywhere" holds.

**Default capability matrix** (Coordinator inherits Volunteer; Administrator inherits Coordinator):
- **Volunteer:** search, register and update participants; pets; check-in; record distribution; history; SNV refer; request pet delete; offline.
- **Coordinator adds:** event management and dashboard; stock receive and count; barcode linking; intake questions; languages; viewing the allotment rules; device registration; session roster and remote sign-out; temporary site grants; distribution reversal; sync review; SNV management; aggregate reports.
- **Administrator adds:** all sites; settings, lookups, policies, clinics and budgets; allotment rules; users; restricted participant fields; merge, delete, restore and erasure; alerts; pet delete; overrides (level set in settings); import; identifiable reports; audit viewer; erasing a tablet together with the records it has not uploaded (Wipe Now, `device.erase`).
- **Board:** read-only aggregate dashboard; no writes and no offline grant.

**Station (offline PWA)**
- **Shell and service worker:** `public/station/index.php` is the shell and holds no user data. `station/sw.php` has scope `/station/`, is sent as `Content-Type: text/javascript` with `no-cache`, and carries the build hash in its bytes. It precaches revisioned URLs of shell assets only and never caches `/api/` responses. Modules use **`.js`**: `.mjs` has no MIME type on XAMPP, and SiteGround NGINX is unverified. The manifest is `manifest.json`.
- **IndexedDB:** stores are encrypted with AES-GCM.
  - A per-device vault key (DVK) is released by the server only on an online login. Each user's PBKDF2 key wraps it, with iterations **calibrated per device at registration**.
  - **PIN verifier = HMAC(DVK-derived key, user_id‖PIN)**, which is fast. The failed-attempt counter lives inside the vault. Optionally, a non-extractable WebCrypto key wraps the DVK for `pin_shift_hours`, so a PIN switch still works after the tab reloads.
- **Push authentication:** `api/sync/push.php` authenticates with the **device credential in an `Authorization` header** (so CSRF does not apply) plus a per-item HMAC. Grant validity is judged at the clamped `recorded_at_client`, and a revocation only affects items recorded after `revoked_at`. Push is idempotent on `client_uuid`, processes items in `seq` order with one transaction per item, and calls the shared Services.
- **Wipes:** a failed-unlock wipe **keeps the outbox ciphertext**, which the device can still upload through a "rescue push". Only an Admin **Wipe-Now** discards the outbox. Wipes run in JS (`deleteDatabase`, `caches.delete`, unregister); `Clear-Site-Data` is an extra layer only.
- **Offline data pack:** minimised and site-scoped. No address, email or DOB, and **no phone hash**: search uses the last 4 digits plus name or code. Alerts are shipped as type codes only. It includes the day's Scheduled events, so offline replays have an `event_id`.
- **iOS/iPadOS:** offline mode only in the installed app (`display-mode: standalone`). Offline is enabled only if `navigator.storage.persisted()===true`, and that state is reported in the device heartbeat.
- **Threat model (documented):** the item HMAC proves the device and grant, not which of several co-volunteers on the device acted. Server-side anomaly flags: the same user active on two devices, and PIN-session items outside that user's password-login window.

## 5. Phased roadmap

Sizes are dev-days for one developer. R1 = P0–P5, about **215–280 dev-days** including the review additions. At about 55–75 dev-days per semester for a part-time student team, and with P1 → P2B → P3 → P4 mostly serial, **R1 takes about 4 semesters**:

| Semester | Phases |
|---|---|
| Fall 2026 | P0 + P1 |
| Spring 2027 | P2A ∥ P2B |
| Fall 2027 | P3 + start of P4 |
| Spring 2028 | P4 + P5, then go-live |

Each semester ends with a demonstrable staging milestone.

### P0: Security and repo hygiene (3–5)
- **Secrets and old sites:** the §2 actions. Also remove `insertAdmin.php`, the `vmsroot`/`vmsroot2` rows and `email/.env`, and purge `secure_uploads/*.enc` on the old hosts.
- **Tag first:** create `legacy-baseline` before deleting anything. Put a pre-purge mirror, the SQL dumps and `public_html.zip` in one AES-encrypted archive with a named custodian (CCDA data; delete after 90 days by default). Logs are deleted, not archived.
- **Delete 116 root pages:**
  - the 110 the UI analyst classed as DELETE;
  - plus, for security, `insertAdmin`, `toggleLock`, `scheduledSend`, `emailEncryption`, `forgotPassword` and `changeForgottenPassword`.
  - Also delete: logs, `public_html.zip`, `.DS_Store`, `readme (2).md`, `php.ini`, `sql/`, `email/` (the bundled PHPMailer, `get_oauth_token.php`, the open relays, `send_email.py`) and `database/dbinfo.php`.
- **Quarantine the other 40 pages** to `legacy/`, **with their full include closure** (the 6 kept db files, and also `dbConsumption`, `dbShoppingCount`, `dbShoppingEvent`, `dbShoppingCountGroup`, `dbClient`, `dbDistribution`, `dbEvents`, `dbShifts`, `dbMessages`, `dbApplications` with their `domain/` classes, plus `include/`, `universal.inc`, `lib/`, `css/`, `js/`, `images/`, `fonts/`).
  - **From P0 on, the old app is intentionally dead.** `legacy/` is reference-only and nothing runs until P1 staging.
- **Add:**
  - `.gitignore` (`config/*.php` except the example, `.env*`, `storage/*`, `vendor/`, `node_modules/`, `*.log`, stray `*.sql`, `*.zip`, `*.enc`, OS and editor files), then **`git rm -r --cached vendor`**;
  - `.gitattributes`, `.editorconfig`;
  - keep `composer.json`/`.lock`, `README.md` and `LICENSE.txt` (GPL-3; keep the Homebase headers).
- **Save the analysis documents** (§1 note) into `docs/design/`.
- **Exit:** owners confirm the rotations; no `*.log`, `*.zip` or `*.sql` outside `docs/`; gitleaks clean on the working tree; old hosts return 403/404 for dangerous endpoints and `/.git/config`, or are gone.

### P1: Foundation (45–55)
- **Dev setup:**
  - Install Composer 2 and Node LTS (neither is on this machine).
  - Enable `gd`, `zip` and `intl` in `C:/xampp/php/php.ini`; `composer install` fails without gd and zip.
  - XAMPP vhost `http://pfpms.localhost` pointing at `<repo>/public`. `*.localhost` counts as a secure context, so the service worker works; mkcert is optional, and XAMPP's bundled certificate expired in 2019.
  - Add a dev-only `package.json` (`node --test`, fake-indexeddb, Playwright).
- **Schema v2.0.1 + v2.1** (§6):
  - `bin/migrate.php` (done: `src/Db/Migrator.php`) runs **one statement at a time** and records progress right after each one, together with a hash of the applied prefix.
    - **Resume:** it replays earlier session `SET` statements. On the first resumed statement only, an "already exists" error counts as already applied.
    - **Edits:** only the unapplied tail of a Failed migration may be edited.
    - **Optional migrations** are Skipped only on privilege errors.
    - It creates `schema_version` with an explicit COLLATE.
  - **Database default collation:** it sets the default to 520 (`ALTER DATABASE`) only when the database is empty, and **refuses a non-empty database** with another default. A SiteGround 8.4 database defaults to 0900.
- **Core:** everything in §4.
- **Tooling:** `composer.json` requires php ≥8.2 and ext-pdo_mysql/openssl/mbstring/json/gd/zip, plus phpmailer ^7, phpspreadsheet ^5.6, **dompdf ^3.1** and **chillerlan/php-qrcode ^5.0**. Dev tools: phpunit ^11 and phpstan ^2. PHPUnit 12 needs PHP 8.3, and XAMPP ships 8.2.4. Prod should run PHP 8.3 or later, since 8.2 security support ends 31 Dec 2026.
- **UC-01 online:**
  - base flow;
  - §3.2.1 reset through `auth_token` (single use, time-limited, no disclosure of whether the account exists);
  - §3.2.2 forced change;
  - §3.3.1 lockout with Admin notification; §3.3.2;
  - Manage User Profile.
- **US-03:** policy acknowledgement gate on pages **and** on `Api::start`/`api/auth/login`; declining ends the session.
- **UC-17:** role home shell.
- **Pages:** `public/{index,login,logout,forgot_password,reset_password,activate,change_password,policy_ack,select_site,profile,notifications,file,healthz}.php`, plus `public/.htaccess` (HTTPS redirect except `\.localhost$`, dotfile and extension denies, headers).
- **Adapt from legacy** (logic only):
  - `login.php` (the `password_verify` flow);
  - `header.php` dropdown CSS and JS become `View/Menu` and `app.css`;
  - `css/base.css`, rebranded (the CCDA palette at L70-77);
  - the `.report-table`/`.modify-*` classes inlined in 20 pages go into `app.css`;
  - `viewProfile`/`editProfile`/`profileEditForm` become `profile.php`;
  - `security_config` and `serve_image` ideas become `SecureFileStore` and `file.php`.
- **Deploy:**
  - New SiteGround site and DB on the **GrowBig plan or higher**, which staging needs. Staging is a separate subdomain site with its own DB, config, cron and certificate.
  - DB users: `pfpms_app` (DML) and `pfpms_owner` (DDL).
  - Deploy over SSH with rsync: maintenance on → `mysqldump --no-tablespaces --single-transaction` → rsync → `migrate --confirm-backup` → maintenance off → `curl healthz`.
  - Let's Encrypt; Dynamic Cache off.
  - **Cron every 30 minutes or more** (SiteGround fair use).
- **CI:** GitHub Actions on PHP 8.2/8.3 × {mariadb:10.4, mysql:8.4, percona 8.4}. It migrates as a **non-SUPER user**, runs twice (the second run must be a no-op), with and without optional triggers, then runs PHPUnit, the PHPStan level 5 check, the page-contract and CSRF tests, the portability guard (§8), and gitleaks.
- **Exit:**
  - CI green.
  - Login, lockout, single-use reset, forced change, policy acknowledgement, timeouts and session-id regeneration all tested.
  - Authentication ≤3 s p95 (UC-01 §4.3).
  - The first Admin is created by CLI.
  - Staging over HTTPS returns 403/404 for `/vendor /config /storage /src /legacy /.git composer.json *.sql`.
  - `x-proxy-cache` shows BYPASS or MISS on pages and APIs.
  - A service worker registers on the staging hostname.

### P2A: Configuration, accounts, stock (∥ P2B; 30–40)
- **Admin and config screens** (`src/Reference/*`):
  - `admin_sites` (IANA time zone), `admin_languages` (US-23 list);
  - `admin_devices`, `admin_device_edit`, `admin_device_credential` (`src/Device/*`, Coordinators at their own sites, Administrators everywhere):
    - **Register** = add a tablet (site, name) and print a single-use registration code: 16 Crockford Base32 symbols (80 bits) plus a check symbol, and a QR holding `PFPMS-DEVICE:1:<code>`, which is not a web address. It works for `device_code_minutes` (default 60), only its SHA-256 is stored, and it is bound to the person who created it. The installed Station redeems it in P2B; nobody signs in on the tablet to register it.
    - **Revoke or wipe** = **Retire** (revoke, then upload and erase; "lost or stolen" alerts Administrators) or **Erase now** (revoke and erase without uploading; Administrator only, `device.erase`). Every revocation is final, ends the tablet's sessions ('Device Revoked') and revokes its codes and grants at once, and keeps the credential hash so the wipe directive can still reach the tablet. Adding it again makes a new device row.
    - Shows last seen, pending count, build and persisted flag as "not reported" until the P2B heartbeat writes them; warnings (stale unsynced records, storage not kept, older build, inactive site) are display only.
    - **Deviations:** registration by printed code, superseding the Coordinator sign-in on the tablet (12-design:136-137, 11-design:232); no revoke without a wipe and no un-revoke (12-design:130); Wipe Now is Administrator only (plan:119); audit names `device_add`, `device_code_issue`, `device_cancel`, `device_rename`, `device_retire`, `device_erase`, `device_report_lost`; the stock-count warning counts retiring tablets until they confirm their wipe and leaves out erasing ones; `Tokens::consume` now checks expiry for every purpose; an access change, deactivation, credential reset or password change cancels the person's live registration codes; tablet names are unique per site among current tablets; `SessionStore::validate` refuses a session bound to a revoked or erased tablet; page names `admin_devices`, `admin_device_edit`, `admin_device_credential` (not 12-design's `deviceManagement.php`); the credential travels as `Authorization: PFPMS-Device` (superseding 10-design:374, 11-design:211, 12-design:138); `offline_enabled` is never set at registration (12-design:137); the heartbeat needs the device credential only (12-design:183); oldest pending item, storage estimate and calibrated PBKDF2 rounds move to P2B's migration 0013 (12-design:139, 183, 219). After review: a retired tablet can be reported lost later; Retire and Erase now never wait on the tablet's named lock; for a tablet reported lost or erased the upload cut-off counts only what the server received; Erase now's check of the reported unsynced count refuses once and can then be confirmed; creating a code re-checks the creator's committed access (role, forced password change, and no change since the page opened); adding a tablet never reveals whether a site outside the person's scope exists.
    - **Contract for P2B** (`docs/design/40-design-devices.md` §0a and §14): the only trusted test is `DeviceRepository::IN_SERVICE_SQL` plus an active site; P2B's registration takes `DeviceLocks::device`; uploads and the wipe confirmation lock the device row per item and re-check `revoked_at` under it, never holding `device:<id>`; no `auth_token` row is inserted after `user_session` rows are updated in the same transaction; durable audit rows on device endpoints are written after the device transaction rolls back.
  - `admin_policies` (versioned per type × language; a version that has been used is immutable), `admin_settings` (typed, audited);
  - `admin_service_area` (bulk ZIP entry, ZIP+4 accepted), `admin_species`, `admin_breeds`, `admin_size_bands` (US-13 pictures, no overlapping ranges);
  - **`admin_allotment_rules`** (logic source `legacy/viewShoppingList.php`; draft → publish; one version in force per date; a rule for every species × band);
  - `admin_intake_questions` (US-09), `admin_lookups` (`lookup_value`, including body types with pictures), `admin_clinics` (directory, needed by US-14).
- **UC-11 core:**
  - Pages: `admin_users` (source `viewAuditUsers`), `admin_user_create` (source `createUser`; invitation only; **captures `onboarding_completed_at`**, UC-11 step 8), `admin_user_edit` (source `viewModifyUser` + `resetPassword`: role, sites, expiry, reset/unlock, deactivate with effective date), `admin_user_credential` (printable).
  - Rules: email must be unused; `volunteer_max_sites`; at least one active Admin; nobody edits their own role or sites; temporary credential lasts 72 h and works once; any role, site or status change ends the user's sessions.
- **Catalogue and stock** (`src/Inventory/*`):
  - `inventory_catalogue`, `inventory_category_edit`, `inventory_product_edit` and `inventory_barcode_link` (sources `viewItemCategories`, `viewAddItemCategory`, `viewModifyItemCategory`): species, form, unit weight (lb, or oz converted). A duplicate is the same name, brand, species, form and weight. Species and form are fixed once a product has been used.
    - **US-16:** barcodes are global and stored as GTIN-14. Every possible reading of a scan is checked before linking. In-store codes are allowed and flagged.
    - A deactivated product takes no new receipts or barcode links; any stock left can still be given out and counted.
  - `inventory_receipts` and `inventory_receipt_edit` (sources `viewManagePallets`, `viewAddPallet`, `viewModifyPallet`):
    - Entered, reviewed, then posted in one step as 'Receipt' rows. What is posted is what was reviewed: a count posted, a case size changed or a site switch in between sends the person back to the review. A one-time key stops a double tap or a retry from posting twice.
    - The name, date and notes can be corrected; lines never change. The date cannot be moved back before a count that was never asked about. A line is voided by a 'Reversal' with a reason, and missed lines can be added.
    - If a count dated on or after the delivery may already include a line, the person says whether the goods were on the shelves then (left out, noted on the receipt) or arrived later (added).
    - Names are unique across sites; a blank name, also when correcting, becomes `RCPT-<id>`.
  - `inventory_count` (sources `viewUpdateInventory`, `editInventoryEvent`, `deleteInventoryEvent`):
    - One whole-site figure per product, optionally one category at a time. Blank means not counted; 0 means none left.
    - Reviewed, then posted in one step under the site's stock lock: 'Count Adjustment' rows (a zero change included) and `inventory_count.posted_at`. If the stock, a case size or the products on the sheet changed since the review, the review is shown again with the new figures; stock that changed after the sheet was opened is pointed out at the review. The same one-time key and site check as receipts.
    - Refused while an event is Open at the site (durable Denied audit).
    - **Deviation:** voiding a count and keeping an unposted count (legacy `editInventoryEvent`/`deleteInventoryEvent`) are not in R1. A wrong count is corrected by counting again.
  - `inventory_stock` (source `inventory.php`): stock, last counted date and the nearest best-before date of the stock (estimated first in, first out from the newest deliveries); per-product history with the stock after each change; a ledger-mismatch banner for `catalog.manage`.
  - `Ledger` is the only code that writes `site_stock` (ci-guard enforces it):
    - **`site_stock` rows are pre-created at 0** when a product or site is created (this avoids gap-lock deadlocks); a missing row is created at 0 on first use.
    - Only a move that lowers stock below zero is refused, with every shortage reported at once, unless the caller passes `allowNegative` because the food has already left.
    - **Count offset:** a movement that a later count already includes is cancelled against that count (`offset_after_txn`, `offset_after_time` or `offset_count_line_id`), so the stock stays at what was counted.
  - **For P3:** opening an event must take `Locks::site`, so an opening can never overlap a count.
  - **For P4:**
    - `DistributionService` posts `Ledger::post('Distribution', …)` with `allowNegative` for offline replay and late entry, and `offset_after_time` set to the clamped `recorded_at_client`.
    - The offline pack and the Station pick list use "active, or on hand > 0" (11-design:470, 12-design:245).
  - **Opening stock** is entered as ordinary receipts. A bulk opening-stock import is left to P5 (UC-12).
- **Exit:** for every site × product, Σ ledger = `quantity_on_hand`; last-Admin and self-edit refusals tested; an allotment version published.

### P2B: Offline platform (∥ P2A, critical path; 25–30)
- **US-01** PIN fast-switch (online `api/auth/pin.php` and an offline verifier; site-registered devices only).
- **UC-01 §3.3.3** offline restricted session.
- Device registration, credential and heartbeat:
  - `api/device/register.php` redeems the P2A code (`RegistrationCode::normalise`, `Tokens::find`, then under `DeviceLocks::device`: the device still waiting, the creator still allowed at its site, `Tokens::consume`); it sets `offline_enabled` 0, which only the heartbeat turns on. It calls `Csrf::verify()` explicitly.
  - The credential (`pfd1_` + 32 random bytes, stored as `Tokens::hash`) travels only as `Authorization: PFPMS-Device <credential>`: add the device guard (`Api::start(['device' => …])`), the `.htaccess` `Authorization` pass-through (verify on SiteGround) and `Request::json`.
  - The heartbeat needs the device credential only, so wipe directives reach a locked tablet; the wipe is confirmed on the heartbeat (`wiped_at`), accepted only from a revoked tablet. A revoked tablet calling in alerts Administrators with no site (`toRoleOnce('Administrator', null, …)`). Rescue push is `api/sync/push.php` with `rescue: true`.
  - Pushes from a retired tablet follow plan §4 (items recorded after `revoked_at` are Held). A suspect tablet (reported lost or stolen, or erased) has **every** upload Held for review once it is suspect, whatever its sequence or recorded time; `revoked_max_seq` stays as a record of how far the server had received.
  - Its migration is 0013 (oldest pending item, storage estimate, calibrated PBKDF2 rounds). The Station's `manifest.json` name is `RegistrationSheet::APP_NAME`, and its JS passes `tests/fixtures/registration_code.json`.
  - **Design:** `docs/design/50-design-station.md` (three designs, two judges, a merge and a four-lens fact-check). It is built in five slices: S1 server platform and HTTP core; S2 Station shell, service worker and registration client; S3 online sign-in, grants and PIN; S4 vault and offline session; S5 sync.
  - **S1 as built:** migration 0013 (the only P2B migration); `Api::start()` with `method`, `device` (`in_service`/`known`), `proof`, `session => false` and `touch` options, one JSON error envelope `{error, message, …}`, CSRF for every session non-GET; `Request::json()`/`authorization()`/`scriptPath()` and `Json`; the separate Station cookie `PFPMSST`; no-touch session validation for polling; `api/ping.php`, `api/session.php`, `api/device/register.php`, `api/device/heartbeat.php`; the `devices:clear-unconfirmed-wipes` cron; `bin/station-smoke.php`.
  - **S1 deviations** (50-design §1, §13.1): registration also takes a `registration_nonce` (a lost answer can be asked for again for 15 minutes and gets the same server-made credential) and the tablet's **proof key**; sign-in, PIN and the wipe confirmation must be signed with that key, and every other device call is checked and flags a copied credential to Administrators; the heartbeat also takes `client_now`, `attention_count`, `locked_out_since`, `auth_failures` and `clock_rollback` and answers with `config`; `offline_enabled` needs the installed app (standalone) on every platform and an active site.
  - **Go-live gate:** `php bin/station-smoke.php https://<staging>/` must pass on staging before S2 goes there: if SiteGround strips the `Authorization` header, tablets cannot work.
- Offline grants: expiry = **min(`offline_grant_hours`, the user's `user_site_access.ends_at`, the account expiry)**, enforced when the vault unlocks (US-28 AC2).
- Outbox and sync engine (item handlers stubbed).
- **Files:** `station/{index.php,sw.php,manifest.json}`, `station/js/{app,router,api,db,vault,session,outbox,sync,rules,calc}.js`, views `{login,home,device,sync-status}`, `api/{ping,session}.php`, `api/auth/{login,pin,logout}.php`, `api/device/{register,heartbeat}.php`, `api/sync/{push,status}.php`.
- **PHP/JS parity fixtures:** canonical JSON, HMAC, accent fold, phonetic key, datetime formatting at column precision with no offset.
- **Exit:**
  - Offline unlock after reload.
  - **PIN switch under 5 s** and **password unlock ≤3 s on the slowest target tablet**.
  - PIN refused on an unregistered device.
  - A duplicate push is a no-op; a reused uuid with a different payload gives 409; a bad HMAC is Held.
  - A first-login tablet cannot receive a grant or pack before policy acknowledgement.
  - Remote wipe works, and a failed-unlock wipe keeps the outbox.
  - The IndexedDB and Cache Storage privacy grep finds no seeded names.

### P3: Participants, pets, events, online and offline intake (50–65)
- **Participants** (UC-02, UC-03, UC-04 base and §3.2.1–3.2.3, §3.3.x, UC-18, UC-19, US-05, US-06, US-08, US-09):
  - **`participant_search`** (source `personSearch.php` GET-filter pattern):
    - site-scoped; ranking: code > exact legal **or preferred** name > prefix > `surname_phonetic` > partial;
    - **preferred name shown first** (US-05);
    - capped at `search_max_results` with a warning;
    - **during an Open event, an empty search lists today's check-ins with their time first**, and typing reverts to normal search (US-04);
    - otherwise it shows participants served here recently;
    - Admin "include deleted"; no export; card QR scan.
  - `participant_register` (source `registrationForm.php`):
    - consent row with the policy version; intake answers; `declined_fields`;
    - the `id_sequence` code is allocated **as the last statement before INSERT** (`UPDATE id_sequence SET next_value = LAST_INSERT_ID(next_value) + 1 …`, then `SELECT LAST_INSERT_ID()`, so P1 is the first code);
    - registration stamps cannot be edited; §3.2.4 adds the site to an existing record;
    - §3.3.3 out of area writes `referred_out_applicant` only, or an Admin overrides.
  - `participant_view`, `participant_edit`:
    - optimistic lock with a field-by-field conflict screen;
    - restricted fields Admin-only; refused changes audited and notified;
    - an address change re-checks the service area **and proposes the nearer site**.
  - `participant_card` (QR `PFPMS:P:<code>:<card_version>`).
  - `registration_drafts` (US-08: listed on the home screen with age; drafts require only an applicant name).
  - `participant_alert`: UC-18 view; UC-19 resolve; **create 'Eligibility Flag' / 'Distribution Restriction' with `blocks_distribution`** (Flag/Unflag include).
  - `DuplicateDetector`: phone / postal+street / phonetic+initial or DOB / collation-equal; accent-only differences warned via `COLLATE utf8mb4_bin`.
- **Pets** (UC-05 base, §3.2.1, §3.2.2, §3.3.x; US-13, US-14, US-26):
  - `pet_edit` with the size-band and body-type picture picker.
  - `AllotmentCalculator`: rule version in force on the site-local date; a missing rule is a hard error; `current_allotment_lbs` updated in the same transaction.
  - Pet limit with Admin override (`limit_override_by`, `is_limit_exception`).
  - Microchip: 9, 10 or 15 digits; a conflict shows only the other pet's name and species.
  - US-14: expiry within `vaccination_warning_days`; "unknown" when there is no date; low-cost clinics listed.
  - US-26: an inactive or deceased pet raises no prompts, and the allotment is recalculated quietly.
- **Events and check-in** (US-04):
  - `events` and `event_edit` (logic sources `calendar`, `addEvent`, `editEvent`): at most one Open event per site; closing sets Waiting to Left Unserved.
  - `check_in`, `api/event/{check_in,queue}.php` (polled every `station_poll_seconds`).
- **Station:**
  - `api/sync/pack.php` (bulk extract, audited). Views `search`, `participant`, `checkin`, `register`, `pet`.
  - Offline flows: cached search with a "may be stale" banner, and manual identification; provisional registration (`T<site>-<device>-<seq>` plus a printed slip); queued pet saves (`base_row_version`; `size_band_id` and `household_size` required); offline check-in.
  - **Replay:** a provisional participant is **committed immediately as a new participant with a 'Duplicate Candidate' alert**, so its distributions post to the ledger and eligibility at once; duplicates are resolved by merge (minimal merge, P4).
  - An offline check-in that collides on `(event_id, participant_id)` with 1062 is merged: the item's uuid is mapped to the existing `check_in_id`.
  - `sync_review.php` (Coordinator) for Held items.
- **Exit:**
  - "Jose" matches "José" and the reverse; phonetic variants are found.
  - Search ≤2 s over 20k participants; volunteers see no other site's households.
  - A failed save uses up no code; a concurrent edit shows the conflict screen.
  - The 7th pet is refused without an override; a duplicate active chip is refused.
  - A two-pet registration is doable in ≤5 min (UC-03 §4.5).
  - A PIN switch keeps the open registration draft.
  - The queue refreshes on a second tablet within 15 s.

### P4: Record Distribution station + history (critical gate; 40–50)
- **UC-06 in full.** `DistributionService::record()` runs in one transaction:
  1. Lock the participant `FOR UPDATE`; follow `merged_into_id`.
  2. Check: event Open (except late-entry mode); status and blocking alerts; frequency; same-day second issue; allotment.
  3. Guarded stock decrement.
  4. Insert `distribution`, `_pet` and `_line` rows (Issued / Declined with reason / Shortfall; `substitutes_line_id` for US-17) and one ledger row per Issued line.
  5. `ParticipantStatus` recalculates last date, next eligible date, programme-year YTD and `last_confirmed_present`, and **raises or clears restriction alerts on defined triggers**.
  6. Check-in outcome = Served.
  7. §3.2.4 writes an 'Offer Deferred' followup.
  8. Audit.

  Around it:
  - **Overrides** (§3.2.2, §3.3.1, §3.3.2): an authoriser co-signs on the tablet, or an `authorization_ref` is entered.
  - **Proxy** (§3.2.1).
  - **§3.3.3:** No Stock or Reserve List plus an `unmet_request` row.
  - **§3.3.5:** full rollback.
  - **US-16** scanning: `BarcodeDetector` when `getSupportedFormats()` supports it, otherwise a precached zxing-wasm decoder, a wedge scanner or manual pick.
  - **US-17 AC3:** repeat declines of the same product are shown on the household record and in the pack.
- **Reversal:** `ReversalService` and `distribution_reverse.php` (Coordinator; DB-unique, so only one reversal per distribution; restores stock and eligibility). Also `distribution_view.php`.
- **Receipt:** print CSS, **preferred name**, no address, participant's language; optional email when the participant consented.
- **Late-entry mode** (Coordinator): enter paper-slip distributions against a Closed event within `late_sync_grace_days`; `local_date` = the event date; audited.
- **Minimal UC-04 §3.2.4 merge** for resolving provisional duplicates. Distributions are never updated; queries resolve `merged_into_id`.
- **Offline (UC-06 §3.2.5/§4.3):**
  - Local stock view = pack snapshot − outbox.
  - Local rule checks.
  - Replay commits and flags `sync_exception` (a SET of failed checks). A double-serve across devices is flagged; negative stock triggers a notification.
  - Count-race offset uses `inventory_count.posted_at`.
  - Clock-skew clamp.
  - UC-01 §4.2: the encrypted draft survives idle timeout and PIN switches.
- **US-18:** `api/event/dashboard.php` (logic source `legacy/event.php`) plus the Station view; demand > stock warning; shows "this device only since HH:MM" while offline.
- **UC-07:**
  - `participant_history` and `history_print` (§3.2.2): read-only; 25 per page; filters labelled "filtered"; out-of-scope sites count in totals but show no detail; reversals excluded; audited.
- **Exit (release gate):**
  - Dress rehearsal with ≥60 households on 3 tablets, **network cut and also airplane mode**. On reconnect: 0 lost, 0 duplicated; a second replay is a no-op; killing the tab mid-sync is safe.
  - Σ Issued = |Σ Distribution ledger|.
  - A two-pet household takes ≤60 s; each step ≤2 s.
  - An override without a co-sign is refused.
  - A reversal restores stock.
  - History ≤2 s at 200 rows.

### P5: SNV, clinic portal, import, go-live → Release 1 (30–40)
- **UC-08 in full + US-21/22/23** (`src/Snv/*`):
  - **`snv_refer`:**
    - eligibility and `clinic_species_rule`, **clinic capacity and current wait**; outside the rules gives a Reassess followup;
    - budget row locked `FOR UPDATE`; exhausted gives a Waiting List followup and an Admin notification;
    - one voucher per pet with one combined sheet;
    - §3.2.3 a declined offer gets **no voucher number and no expiry**;
    - §3.2.4 reimbursement; §3.3.4 next nearest clinic.
  - `snv_referral_view` (six-status state machine plus status log), `snv_followups`, `snv_transport`.
  - **`voucher_print`:** preferred language with fallback notice; preferred name; **QR code and clinic address, hours and phone as text** (US-21); emailed as PDF via dompdf.
  - `admin_clinic_edit` (species rules; portal code of **≥16 Crockford characters (80 bits), shown once, stored with Argon2id**), `admin_voucher_budgets`.
  - Voucher numbers: ≥10 random Crockford characters plus a check character.
  - `clinic/info.php`: no JavaScript.
  - **`clinic/portal.php`:** clinic id plus code; rate-limited per clinic and IP; 15-minute session; **look up by voucher number only, never a list**; sees pet and voucher fields only; records schedule, redemption, surgery date and outcome.
  - Cron: `vouchers:expire` and `vouchers:remind`.
  - The UC-05 §3.2.1/§3.2.2 referral hooks and the UC-07 §3.2.3 referral timeline are switched on.
- **UC-12 + US-32 (Must):**
  - `import_template.php` for **Participant, Pet, Distribution and User** (intake questions as `Q:` columns).
  - `import_wizard` and `import_batches`: dry run; same validators; one-transaction commit tagged Legacy and `import_batch_id`; rejected rows encrypted.
  - **§3.3.1–3.3.5** refusals and the rejection threshold.
  - **Refused while any event is Open**, unless the user has the during-hours permission.
  - **Rollback of unused rows** (`ImportRollbackService`, the only allowlisted delete path).
  - Cron `imports:purge` (90 days).
- **Pulled into R1 at review:**
  - **UC-10 base case:** delete a pet with no `distribution_pet` rows.
  - **UC-05 §3.2.3 `pet_transfer`**, with `pet_household_history` (also used for the microchip-conflict path).
  - **UC-14 basic** totals by period and site, using the shared `Metrics` definitions, with a CSV export and Export audit.
  - UC-09 interim: deactivation plus a documented manual erasure procedure.
- **Go-live checklist:**
  - Client-approved seeds: sites, bands and pictures, allotment v1, products and barcodes, opening receipts, ZIP list, policies EN/ES, clinics, rules, budget, intake questions.
  - Users invited; onboarding recorded; **supervised practice on staging with synthetic data** (`practice_completed_at`).
  - Tablets registered **inside the installed PWA** with storage persisted.
  - Paper fallback sheet printed for each event.
  - Backup/restore drill.
  - All R1-replaced `legacy/` files deleted.
- **Exit (Release 1):**
  - Concurrent issues cannot overspend a budget.
  - A declined offer uses no voucher number.
  - The portal cannot see another clinic's data or any contact data.
  - Spanish voucher correct, with fallback.
  - Template round trip maps automatically; a dry run writes nothing; a mid-commit failure leaves 0 rows.
  - Prod deploy healthy.

### P6: Governance and operations (R1.1; 29–37)
- **UC-09 in full:**
  - `participant_delete` (dependency review; recommends deactivation; lookup reason; refused while a Pending or Scheduled referral exists; `status_before_delete`; pets flagged `inactivated_by_owner_delete`; snapshot; recovery window) and `participant_restore`.
  - `erasure_requests`: a second, different Admin approves. Data is anonymised in place; proxy, consent, notes, answers, alerts, drafts, rejected rows, pet names/chips/photos, `sync_item` payloads, **`outbound_message`** and **`notification`** are purged. Distribution FKs are untouched. Audit personal data is redacted by the allowlisted `ErasureService`, subject to client OK (Q16).
- **UC-04 and UC-05 completion:** full merge UI and `participant_versions` (§4.4).
- **UC-10 full:** `pet_merge`, and a Volunteer's delete request raises an Admin notification.
- **UC-05 §3.2.4:** `pet_photo` (source `legacy/upload_encrypted_image.php`; GD re-encode, ≤5 MB, encrypted).
- **Stories:** US-02 `session_roster` (source `checkedInVolunteers`), US-25 retention notice, US-28 time-boxed grants (default end = end of the event), US-11 stale-contact prompt, US-12 service notes (and in the pack).
- **UC-11 and UC-01 extras:** UC-11 `access_review`; UC-01 §3.2.3 trusted device; `audit_log.php` viewer.

### P7: Full import and reporting (R2; 41–50)
- **UC-12 full and UC-11 §3.2.4 roster import:**
  - Background validation driven by **browser-polled chunks** (not frequent cron).
  - **Streaming readers** (OpenSpout, or a PhpSpreadsheet read filter): peak memory is asserted, under SiteGround's 768 MB per process.
  - Continuation batches; updates to matched rows with field audit; synthetic legacy events.
- **UC-13:**
  - `report_hub` (source `legacy/generateReport.php`), `report_view` (Chart.js self-hosted; data currency; definitions; drill-down; period compare).
  - `report_export` (source `legacy/processInventoryReport.php`: CSV with BOM, XLSX, dompdf PDF; footer; audit). This is where the Print and Export «extend» use cases live.
  - `saved_reports` (recipients must be named accounts), `report_runs`.
  - Heavy reports run in the background. Small cells are suppressed. Identifiable output needs the capability, `can_extract_identifiable` and a recorded purpose.
- **UC-14/15/16:** all variants, including the weekly site report (source `viewWeeklyReport.php`) and `admin_grant_commitments`.
- **Stories:** US-24 deletion digest, US-33 `board_dashboard`, US-36 forecast (source logic `dbConsumption.php` L164/L191), US-38 (volunteer counts are estimates), US-39, US-40 (separate citation setting).
- `legacy/` is emptied and removed.

### P8: Could stories + training mode (R3; 29–38)
- **US-07:** self-registration via the site QR code (honeypot plus rate limit).
- **US-15, US-20, US-27, US-30, US-34, US-35, US-37, US-41.**
- **US-29 training mode:**
  - a separate `pfpms_training` DB with the same migrations and a sample seed;
  - a session flag switches the DSN, and a red banner is shown;
  - cron, reports and the offline pack are disabled in training;
  - GET_LOCK names are already per-DB.

### P9: Coulds that depend on client decisions (0–28)
- **US-10 and US-19:** participant self-service, with v2.2 tables `participant_access_token` and `participant_change_request`.
- **US-31 OCR intake:** recommend descoping (it sends participant data to a third party).

## 6. Schema changes

All changes are additive and portable: new ENUM values are appended at the end, new tables use InnoDB with a primary key and the 520 collation, and each file runs one statement at a time.

| Migration | Change | Why |
|---|---|---|
| 0001 (v2.0.1) | `0900_ai_ci` → `utf8mb4_unicode_520_ci` on all 59 tables plus the header; `ALTER DATABASE … COLLATE 520`; `pet.status` moved above `active_microchip`; DROP lines removed (CI asserts it matches `docs/PFPMS_schema_v2.sql`) | D2, US-06 |
| 0002 | About 60 new `system_setting` rows: auth (lockout, 12 h absolute, password policy, 72 h temporary credential, PIN, `volunteer_max_sites`, re-acknowledgement), offline (grant/pack TTL, PBKDF2, skew, `late_sync_grace_days`, poll), operations (`search_max_results` 100, `history_page_size` 25, `photo_max_mb` 5, override levels, `programme_year_start`, draft TTLs, `voucher_reminder_days`, prompt windows), import, report and retention limits; separate `litters_prevented_citation` | Gap list across UC-01 to UC-13 and US-01/07/15/20/27/30/40 |
| 0003 | `auth_token.purpose` += 'Offline Grant' (+ `secret_ciphertext`, `revoked_at`); `user_session.end_reason` += Password Reset / Deactivated / PIN Switch / Device Lock; `device` += token hash, vault key ciphertext, offline flags, last seen/sync, pending, build, persisted, revoked, `wipe_mode`; `user_account` += `row_version`, `display_name`, `deactivation_effective_date`, `pin_failed_count`; index `user_site_access(user_id, site_id, ends_at)`; table **`rate_limit_bucket`** | UC-01, UC-11, US-01, US-28, US-22 |
| 0004 | `client_uuid` NULL UNIQUE on participant, pet and event_check_in; `participant.provisional_code`; `distribution.sync_exception` **SET(...)**; **`UNIQUE uk_distribution_reverses (reverses_distribution_id)`**; table **`sync_item`** (idempotency, status Accepted / Accepted With Exception / Held / Resolved / Discarded) | UC-03 §3.2.3, UC-06 §4.2/§4.3 |
| 0005 | Tables **`notification`** (work queue) and **`outbound_message`** (mail outbox, body encrypted) | Gap 6: lockout alert, sync failures, referrals to Admin, budget exhausted |
| 0006 | participant += `declared_pet_count`, `card_version`, `status_before_delete`; pet += `limit_override_by`, `is_limit_exception`, `inactivated_by_owner_delete`; **`inventory_count.posted_at`**; table **`unmet_request`** | UC-03 step 6, UC-09 §3.2.4, UC-05 §3.3.3, UC-06 §3.3.3, count race |
| 0007 | `snv_referral.voucher_number` **and `expires_on`** become NULL (unique key kept) | UC-08 §3.2.3/§4.1 |
| 0008 | Tables **`id_sequence`** (gapless participant codes) and **`lookup_value`** (colour, body type, delete/deactivation/emergency/decline reasons, referral source, proof of residence); reserved `system` user row | UC-03 post-condition, UC-05 §4.5, UC-09/10 step 5, US-13 |
| 0009 | `import_batch`/`import_mapping.record_type` += 'User'; `import_batch.status` += Queued, Running; `audit_log.import_batch_id` | UC-11 §3.2.4, US-32, UC-12 |
| 0010 | `system_setting` += `organisation_time_zone`: organisation-wide dates (accounts, policy start and re-acceptance) use the organisation's local date, not UTC | UC-11 review |
| 0011 | `allotment_rule` += `published_at`, `published_by`, `created_at`; `lbs_per_distribution` NULL-able for draft cells; UNIQUE (version, species, band, form); index on `effective_from`. In force = latest published `effective_from` ≤ site-local date; `effective_to` unused; version 0 reserved for legacy | UC-05 §4.1, UC-06 §4.5, P2A allotment rules |
| 0012 | `auth_token.purpose` += 'Device Registration'; `user_session.end_reason` += 'Device Revoked'; `device` += `reported_max_seq`, `revoked_lost`, `revoked_max_seq`, `erase_requested_at`, `erase_requested_by` (FK), `wiped_at`; setting `device_code_minutes` (68 settings; tables 66; FKs 167) | US-01, UC-01 §3.3.3, UC-06 §4.3, P2A admin_devices |
| 0013 | `device` += `pbkdf2_iterations`, `proof_key_ciphertext`, `display_mode`, `storage_estimate_kb`, `clock_skew_seconds`, `oldest_pending_at`, `attention_count`, `locked_out_since`, `shift_ended_at`; `auth_token.created_at`; `sync_item.recorded_at_raw` and index `ix_sync_item_4`; `user_session.auth_method` += 'Offline PIN', `end_reason` += 'User Switch' (no table, FK or setting added) | P2B Station platform (50-design §4) |
| optional/9001 | Immutability triggers on distribution*, audit*, `snv_referral_status_log`, `inventory_transaction`. **A dev safety net only**: SiteGround will likely refuse them (ERROR 1419 without SUPER). The app-layer guard and the CI grep are the real enforcement. Dumps use `--skip-triggers`; `@pfpms_allow_mutation` is reset in `finally`; retention purges are allowlisted. | Notes §3 |
| v2.2 (P9 only) | `participant_access_token`, `participant_change_request`, `intake_scan` | US-10/19/31 if kept |

- **Counts:** after v2.1 there are 66 ERD tables, plus the tooling table `schema_version`, and **167 FKs**. Both are pinned in `bin/schema-check.php`. The set is verified on MariaDB 10.4, MySQL 8.0 and MySQL 9.4.
- **For the ERD team only:** a clinic↔site link, status history, non-rabies vaccinations, a shift roster, and aligning `allotment_rule.food_form` with `product.food_form`.
- **Also note:** the 520 collation is PAD SPACE, so the Validator trims every unique field (username, email, barcode, codes).

## 7. Reuse from legacy (port with tests; never include `legacy/`)

**Validators** (`include/input-validation.php` → `src/Validation/Validator.php`):
- Ported as is: `validateDate` L109, `validate12hTime*` L135/L147, `validateEmail` L163 (plus a 100-character cap).
- Fixed first:
  - `validate24hTimeRange` L117 (checks `$start` twice);
  - `validate24hTime` L127 (regex needs anchors);
  - `validateAndFilterPhoneNumber` L155 (strip a leading 1);
  - `wereRequiredFieldsSubmitted` L167 (default `$blankOkay=false`);
  - `validateZipcode` L176 (accept ZIP+4);
  - `valueConstrainedTo` L184 (strict `in_array`);
  - `validateURL` L207 (http/https only).
- **Never reused:** `sanitize`, `_sanitize`, `sql_safe_*` (they store HTML entities and don't protect against SQL injection), and `emailEncryption.php`.

**Output and navigation helpers:**
- `include/output.php`: `hsc` becomes `e()`. `time24hTo12h`, `formatPhoneNumber` and `floatPrecision` are ported **without their internal escaping**.
- `include/api.php` `redirect` becomes `Response::redirect` (relative paths only).

**Patterns and assets:**
- Patterns: `personSearch.php` (GET filter), `viewAddPallet.php` (header plus lines), `processInventoryReport.php` L9-10/L127 (PhpSpreadsheet XLSX), `upload_encrypted_image.php` (GD re-encode), and the `dbShifts`/`dbGroups` prepared-statement style.
- Assets: `css/base.css` structure, the `header.php` dropdown, `lib/bootstrap/css/bootstrap.min.css` v5.2.2 and `fonts/` (copied into `public/assets/` in P1).

**`docs/PFPMS_useful_functions.md` needs correcting** in P1:
- it recommends `emailEncryption.php`;
- it calls `sanitize` safe;
- it gets `export_data` and `calculate_age` wrong;
- it spells the table `dbconsumption` (the real table is `dbcomsumption`).

## 8. Engineering rules (from the adversarial review)

**DB portability.** A CI guard scans SQL only: `migrations/**/*.sql`, `seeds/**/*.sql`, and SQL string literals in `src/`, with comments stripped. It never scans PHP code, whose `->` is the object operator. It fails the build on:
- `0900_ai_ci`, `->`/`->>`, `JSON_TABLE`, `JSON_ARRAYAGG`/`JSON_OBJECTAGG`, `RENAME COLUMN`, `ANY_VALUE`, `LATERAL`, functional indexes, `CAST(… AS JSON)`, `REGEXP_LIKE`, `SKIP LOCKED`;
- `ON DUPLICATE KEY UPDATE … VALUES(`, and the `AS alias ON DUPLICATE` form;
- `IF [NOT] EXISTS` on columns, `RETURNING`, sequences.

Read JSON through `JSON_EXTRACT`/`JSON_UNQUOTE` only.

**Other rules:**
- **Immutability:** distribution*, audit*, `snv_referral_status_log` and `inventory_transaction` are never UPDATEd or DELETEd outside the allowlisted services (import rollback, erasure, retention purge). A CI grep enforces this.
- **Hosting:**
  - cron every 30 minutes or more;
  - mail sent inline with queue retry;
  - `Cache-Control: no-store` everywhere;
  - `.js` modules only;
  - the service worker served with an explicit JS content type;
  - revisioned precache URLs.
- **Transactions:** retry at the outermost level only, and durable audit rows never reference uncommitted parents.

## 9. Verification

**Schema on both engines**
- Local: XAMPP MariaDB 10.4, plus `tools/docker-compose.db.yml` running mysql:8.4 and Percona 8.4. Run `php bin/migrate.php` twice and confirm the second run does nothing.
- **`SchemaContractTest`** compares normalised `information_schema` data (columns, nullability, defaults, statistics, key usage), not dump text. It checks:
  - table and FK counts;
  - every table plus the DB default is on the 520 collation;
  - no warnings after any statement.
- Smoke tests:
  - `munoz` finds Muñoz, and José matches JOSE;
  - a duplicate active microchip gives 1062, which goes away once the pet is made inactive;
  - `JSON_EXTRACT` round-trip works;
  - `id_sequence` leaves no gap after a rollback;
  - several NULL voucher numbers can coexist;
  - a second reversal of the same distribution gives 1062.

**PHPUnit** (Unit + Integration; DB name must end `_test`; `FrozenClock`; CI matrix PHP 8.2/8.3 × three engines)
- **Unit:**
  - validators, including the fixed legacy bugs;
  - crypto (tampering detected, key rotation);
  - settings;
  - capability invariants (Board has no writes; Volunteer has no delete, import or user management);
  - AllotmentCalculator and the frequency window.
- **Integration:**
  - transactions: savepoints, outer-level retry, the optimistic-lock conflict;
  - audit rows roll back with their transaction;
  - auth: login, lockout, single-use reset, timeouts, session-id regeneration;
  - page and API contract: an unauthenticated POST writes nothing; missing CSRF gives 400; public POST pages are tested separately;
  - the 5-table distribution commit, a failure injected mid-commit, stock serialisation;
  - sync: duplicate batch, reused uuid, out-of-order seq, double-serve, provisional chain, check-in 1062 merge, late sync, count race, revoked grant or device, bad HMAC, parallel pushes;
  - reversal; budget concurrency; clinic portal isolation;
  - import dry run, rollback, and a mid-commit failure;
  - erasure leaves totals unchanged;
  - immutability tests pass **with 9001 skipped**.
- **JS and E2E:**
  - JS: `node --test` with fake-indexeddb (vault, PIN lockout, outbox state machine, draft restore), plus PHP/JS parity fixtures.
  - E2E: Playwright on `http://pfpms.localhost` (`setOffline`, `page.clock`): install, offline unlock, PIN switch, provisional registration, 30 distributions, idle restore, sync, remote wipe. The privacy grep of IndexedDB and Cache Storage must find 0 seeded names.
- **Performance:**
  - 20k participants: search ≤2 s; station step ≤2 s; history ≤2 s at 200 rows;
  - authentication ≤3 s;
  - unlock and PIN on the target tablet;
  - intake ≤5 min;
  - distribution ≤60 s.

**Manual distribution-day rehearsal** (staging, HTTPS, 3 Android tablets plus an iPad as best effort):
1. Receipt posted, event opened, devices prepared.
2. Network cut **and** airplane mode.
3. Unlock offline, check in, search "munoz".
4. Serve a two-pet household in ≤60 s and print the receipt.
5. PIN-switch mid-entry.
6. Provisional registration, pet and distribution.
7. Override with a co-sign.
8. Idle timeout, then restore.
9. Serve the same household on tablet 2.
10. Reconnect: 0 lost and 0 duplicated; the double-serve is flagged; the ledger reconciles.
11. Resolve items in `sync_review`, then reverse one distribution.
12. Enter a late paper slip, close the event, post a count.
13. Check the audit trail.

**Security**
- gitleaks across the full history.
- `curl` denial checks on staging for protected paths, and a check for `x-proxy-cache` BYPASS.
- Headers: HSTS, CSP, nosniff, `no-store`; cookie flags.
- Rate limits on login, forgot password and the portal.
- An OWASP ZAP baseline scan before go-live.

## 10. Open questions for the client

1. **Roles** (notes §4.1): are Coordinator and Board real? Is the capability matrix right, including who authorises overrides, links barcodes, resolves Held items and reverses distributions?
2. **Delete Pet** Administrator-only (notes §4.2)?
3. **Keep US-10/19 self-service? Descope US-31 OCR?**
4. **Offline scope:** UC-01 §3.3.3 says "distribution only", while UC-03 and UC-05 allow intake offline. Confirm the four offline flows.
5. **The replay commit-and-flag rule deviates from UC-06 §3.2.5 step 3** ("re-applies the frequency and allotment checks, and reports any record that could not be applied"). Amend the spec? This also covers flagged households served offline, over-allotment committed offline, and whether stock may go negative.
6. **Offline limits:** 72 h grant and pack lifetime; up to 5,000 households per tablet; is a DPIA-style note needed?
7. **"Pre-approved authorisation references"** (UC-06 §3.2.2): how are they issued?
8. **Is an offline US-18 dashboard that sees only its own device acceptable?**
9. **Hardware:** Android or Chromebook as primary? iPad best-effort? Receipt printer (browser, USB/BT or thermal) for sites with no Wi-Fi? Barcode scanner?
10. **Final production domain:** a change of origin wipes every tablet.
11. **Old CCDA sites** (four hosts): decommission? Who approves the data export and archive retention?
12. **OK to make the repo private and purge its history** (§2)?
13. **Seed values:** size bands, allotment lbs, products and weights, service-area ZIPs, programme year, policy texts EN/ES, clinics, rates and budget, intake questions.
14. **Allotment accounting:** how do Treat/Other products count against the Dry/Wet/Any allotments?
15. **SNV "Not Performed" outcome:** which status does it map to (Void with a reason)?
16. **Erasure vs audit:** may audit personal data be redacted as a documented exception, or should crypto-shredding be used?
17. **Legacy staff:** which accounts, and which roles?
18. **Reporting replica:** UC-13 requires one, but the plan uses the same DB with background jobs. Is that acceptable?
19. **Funder grant report formats** (UC-13 §4.7).
20. **Cross-site duplicates** (UC-03 §3.2.4): what minimal fields may a Volunteer see for a candidate at another site?
21. **Flags:** what triggers a flag or distribution restriction, and who may set one?
22. **Clinic portal:** may it show the owner's name?
23. **Onboarding:** must training or onboarding be completed before an account can be activated?
24. **Hosting:** SiteGround **GrowBig or higher** for staging, and email via a mailbox on the PFPMS domain. SMS is out of scope because it needs a paid gateway.

## 11. Coverage

**Use cases**

| Use case | Phase |
|---|---|
| UC-01 | P1; P2B §3.3.3; P4 §4.2; P6 §3.2.3 |
| UC-02, UC-03 | P3 |
| UC-04 | P3; P4 minimal merge; P6 full merge and versions |
| UC-05 | P3; P5 §3.2.3 transfer; P6 §3.2.4 photo |
| UC-06 | P4 |
| UC-07 | P4; P5 §3.2.3 |
| UC-08 | P5 |
| UC-09 | P5 interim deactivation; P6 full |
| UC-10 | P5 base; P6 full |
| UC-11 | P2A; P6 access review; P7 roster import |
| UC-12 | P5 (incl. safety, rollback, purge); P7 full |
| UC-13 | P7 |
| UC-14 | P5 basic; P7 full |
| UC-15, UC-16 | P7 |
| UC-17 | P1, growing each phase |
| UC-18, UC-19 | P3 |

**Included and extending use cases**

| Use case | Phase |
|---|---|
| Duplicate detection, validation, Flag/Unflag, Create Alert | P3 |
| Select Food, Capture Date/Location, Participant Status, Distribution Details | P4 |
| Print and Export (extend UC-13) | P7 |
| UC-07 §3.2.2 print | P4 |

**Stories**

| Phase | Stories |
|---|---|
| P1 | US-03, US-06 (collation) |
| P2A | US-09 (config), US-13, US-16 (linking), US-23 (list) |
| P2B | US-01, verified again in P3 and P4 |
| P3 | US-04, 05, 06, 08, 09, 13, 14, 26 |
| P4 | US-16 (scan), 17, 18 |
| P5 | US-21, 22, 23, 32 |
| P6 | US-02, 11, 12, 25, 28 |
| P7 | US-24, 33, 36, 38, 39, 40 |
| P8 | US-07, 15, 20, 27, 29, 30, 34, 35, 37, 41 |
| P9 | US-10, 19, 31 |

- **All 6 Musts are delivered by P5** (US-01, 03, 18, 22, 26, 32). The Musts that depend on Should work are sequenced correctly: US-04 → US-18, US-09 → US-32, and the alert framework → US-26.
- **All 59 baseline tables are used**, and all 7 v2.1 tables are used.

## 12. Critical files

| File | Role |
|---|---|
| `docs/PFPMS_schema_v2.sql` | Becomes v2.0.1, then `migrations/0001`; `migrations/0002–0013` build on it |
| `src/bootstrap.php`, `src/Db.php`, `src/Http/Page.php`, `src/Http/Api.php`, `src/Auth/capabilities.php` | New core that every page depends on |
| `src/Distribution/DistributionService.php`, `src/Sync/SyncService.php` | Single commit path for online and offline |
| `public/station/sw.php`, `public/station/js/vault.js` | PWA shell and encrypted store |
| `include/input-validation.php` | Legacy validators to port, with the fixes listed in §7 |
| `legacy/viewShoppingList.php`, `legacy/viewAddPallet.php`, `legacy/personSearch.php`, `legacy/generateReport.php`, `legacy/processInventoryReport.php` | Logic sources for the rebuilt modules |
