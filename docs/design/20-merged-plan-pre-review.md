<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

# PFPMS implementation plan

## 1. Context

The repository is the Homebase-lineage "CCDA Food Pantry" PHP app. It has 156 root pages. 110 of them are dead volunteer-management features, and the rest run string-built mysqli SQL against human-food tables. It also has live secrets, a forgeable password reset, no CSRF protection, SQL injection in 31 files, and a committed 23 MB site snapshot. PFPMS needs a completely different data model: `docs/PFPMS_schema_v2.sql`, which has 59 tables and 143 FKs and has no legacy data to migrate. It also needs 16 use cases plus UC-17 to UC-19 and 41 stories, including a distribution day that works with no network. The plan below does five things, in order:
- Neutralise the security exposure first.
- Put a small shared plain-PHP core on a single DDL that loads on both MariaDB and MySQL, with additive v2.1 migrations.
- Build the modules on the critical path to the first distribution day. Offline Record Distribution is built alongside the server features, not after them.
- Deliver governance, reporting and the Could stories in later phases.

The goal is a Release 1 (end of Phase 5) that a student team can run on SiteGround shared hosting and on a XAMPP dev machine, where every page is guarded, audited and transactional.

## 2. Key decisions

| # | Decision | Basis |
|---|---|---|
| D1 | In-place plain PHP, one file per page. A new shared core (PDO transactions, role and site guard, CSRF, audit writer, settings loader). Dead legacy pages are deleted and modules are rebuilt on v2 one at a time. | User decision 1 |
| D2 | One DDL loads on XAMPP MariaDB 10.4 and on SiteGround Percona/MySQL 8.4. `utf8mb4_unicode_520_ci` replaces `0900_ai_ci` in 59 places; it is still accent- and case-insensitive, so US-06 holds. JSON is read only through `JSON_EXTRACT`/`JSON_UNQUOTE`. `pet.status` moves above the generated `active_microchip`. No MySQL-8-only syntax. The connection uses `charset=utf8mb4` plus `SET NAMES … COLLATE utf8mb4_unicode_520_ci`, with time zone +00:00 and a strict sql_mode. | User decision 2 |
| D3 | `docs/PFPMS_schema_v2.sql` stays the baseline (becoming v2.0.1). Gaps are fixed with small additive migrations, `migrations/0002–0009` (the v2.1 set). Each one is logged in `docs/PFPMS_schema_v2_1_changes.md` for the ERD team. | User decision 3 |
| D4 | The offline PWA ships in Release 1 and is on the critical path. It uses a service worker, encrypted IndexedDB, and a sync API that is idempotent on `client_uuid`. It covers UC-06 §3.2.5/§4.3, UC-02 §3.3.3, UC-03 §3.2.3, UC-05 §3.3.4 and UC-01 §3.3.3. | User decision 4 |
| D5 | All 4 schema roles are supported through one capability matrix, `src/Auth/capabilities.php`, so notes §4.1 is settled by editing config. | Settled |
| D6 | No legacy inventory migration. Seed a fresh Administrator (`bin/create-admin.php`) and re-create the ~4 real staff through UC-11. **Legacy bcrypt hashes are never carried over**; they are exposed in committed dumps. | Settled; Foundation over Features (security first) |
| D7 | Security remediation comes first (Phase 0). Git-history purge is a recommendation only and needs the user's explicit OK. | Settled |
| **Where the planners disagreed** | | |
| R1 | **Layout:** `public/` is the document root (SiteGround `~/www/<domain>/public_html`, a path confirmed in the repo's logs). `src/`, `vendor/`, `config/`, `storage/` and `migrations/` sit beside it, not inside it. The alternative was the Features plan's flat root. | Security first. This does not depend on `.htaccess` being honoured by SiteGround's NGINX layer. Churn is low because 116 pages are deleted and the other 40 are rebuilt anyway. |
| R2 | **Data layer:** PSR-4 `src/<Module>/{X}Repository.php` (prepared statements only, returns arrays), `{X}Service.php` (rules, transaction, audit) and `{X}Validator.php`. This merges the Foundation plan's namespaces with the Features plan's repository/service split. Not a framework, not an ORM. | |
| R3 | **Quarantine** goes to `legacy/` at the repo root. It is outside `public/`, never deployed, and CI forbids including it. The Features plan's `_legacy/` inside the web root was rejected. | Security first |
| R4 | **Deleted in Phase 0** rather than quarantined: `scheduledSend.php` (cron that can be triggered from the web), `forgotPassword.php` and `changeForgottenPassword.php` (Gmail secret, forgeable reset) and `emailEncryption.php`. This overrides the UI analyst's ADAPT/REWRITE labels. | Security first |
| R5 | **One write path.** Record Distribution exists only as the client-rendered Station. Every write goes through the encrypted outbox and is pushed at once when the device is online. On the server, one `DistributionService::record()` serves both `api/sync/push.php` and any backdated entry. | All three plans agree; this makes it concrete |
| R6 | **Sync replay policy:** a distribution that fails frequency, same-day, allotment, stock, flag or authorisation checks on replay is **committed and flagged** (`distribution.sync_exception`) and a Coordinator is notified, because the food has already left. Only authenticity failures (HMAC, grant, device), references to erased or unknown data, validation failures, and provisional registrations with duplicate candidates (plus their dependants) are **Held** for review. Nothing is ever dropped. The Offline plan wanted to hold everything; the Features plan wanted to commit everything. | The ledger and eligibility must reflect food that physically left, or the household can be served twice. Reversal is available for corrections. |
| R7 | **Sync schema:** one `sync_item` table replaces `sync_submission`, `sync_command`, `sync_batch` and `offline_grant`. Offline grants reuse `auth_token` (purpose 'Offline Grant'). `inventory_count.posted_at` and `inventory_transaction.occurred_at` are not added because both can be derived from existing ledger links. | Smallest additive change |
| R8 | **Offline keys:** the server holds a per-device vault key (DVK), stored encrypted and released only on an online login by a user with access to that site. On the device, each user's PBKDF2(password, 600k) key wraps the DVK. The PIN verifier sits *inside* the vault, so a PIN only unlocks an idle-locked session; a hard lock needs the password. The Features plan's PIN-wrapped key was rejected because a 10⁶ PIN space can be brute-forced offline. | Security first |
| R9 | **Server crypto:** openssl AES-256-GCM with a key id for rotation. libsodium is not loaded in XAMPP (verified: `sodium=0`, `aes-256-gcm=1`, `argon2id=1`). | Verified |
| R10 | **Codes:** participant codes come from `id_sequence` inside the business transaction, so a rollback uses up no number (UC-03). Voucher numbers are random Crockford base-32 plus a check character: unguessable and unique for life. The Foundation plan wanted a voucher sequence. | Security |
| R11 | **Migration timing:** the whole known v2.1 set is applied in Phase 1, giving one ERD fold-in. The Features plan staggered them. Only the US-10/19/31 tables wait, as v2.2 in Phase 9, and only if the client keeps those stories. | Minimal churn for the ERD team |
| R12 | **Setting names:** one vocabulary (§5 row 0002): `pin_max_failed`, `programme_year_start` (MM-DD), `*_auth_level`, `station_poll_seconds`. | |
| R13 | **Service worker:** served by PHP as `public/sw.php`, root scope, `no-cache`, build hash in its bytes. The station shell and API are excluded from SiteGround Dynamic Cache. | Avoids the unverified NGINX static-cache behaviour |
| R14 | **Mail:** `outbound_message` outbox sent by cron. Reset mail is sent immediately, falling back to the queue. One composer PHPMailer, a SiteGround mailbox, no Gmail. | Keeps SMTP delay out of the 60 s distribution target |
| R15 | **Clinic directory** admin moves into Phase 2 because US-14 lists low-cost vaccination clinics. Species rules, portal and budget stay with SNV in Phase 5. | Dependency |
| R16 | **Distribution reversal** is Coordinator and above, because it is needed on event day. Over-allotment and emergency authorisation follow the settings (default Administrator, per UC-06 §4.5). | |
| R17 | **Printing:** print CSS (works offline) for receipts, cards and provisional slips; dompdf for server PDFs (emailed vouchers, scheduled reports). jQuery 1.9.1 is dropped. jsPDF is dropped unless a report needs it. | |
| R18 | **Unmet requests** get a reportable `unmet_request` table, not the `event_check_in.unmet_detail` text column the Features plan proposed. | UC-14 needs shortfall and unmet reporting |
| R19 | **File naming:** new pages use flat snake_case with a module prefix (`participant_search.php`). APIs live under `public/api/<area>/<action>.php`. | |

## 3. Target architecture

### 3.1 Layout
```
repo/                         SiteGround: ~/www/<domain>/     XAMPP: htdocs/chsPetPantry (vhost pfpms.localhost → public/)
├─ public/                    → public_html/  (the only web-reachable tree)
│  ├─ index.php login.php logout.php forgot_password.php reset_password.php activate.php change_password.php
│  │  policy_ack.php select_site.php profile.php notifications.php file.php healthz.php offline.html
│  ├─ <module>_*.php          admin_*, inventory_*, participant_*, pet_*, event*, snv_*, import_*, report_* …
│  ├─ clinic/{info,portal}.php            (public, rate-limited: US-21/22)
│  ├─ station/index.php station/js/*.mjs station/css/station.css station/vendor/idb.mjs
│  ├─ api/{ping,session}.php api/auth/* api/device/* api/sync/* api/station/* api/event/*
│  ├─ sw.php manifest.webmanifest .htaccess
│  └─ assets/{css/app.css,css/print.css,js,img,fonts,vendor}
├─ src/ (namespace Pfpms\, PSR-4)   templates/{layout,pages,print}/   bin/   migrations/   seeds/{reference,dev}/
├─ config/config.example.php (committed)  config/config.php (ignored; 600 on server)
├─ storage/{logs,sessions,mail,cache,secure/{pets,imports,rejected,reports}}  (0700; never in public_html)
├─ tests/{Unit,Integration,js,e2e,fixtures}  tools/docker-compose.db.yml  .github/workflows/{ci,deploy}.yml
├─ docs/  legacy/ (quarantine, never deployed; empty by end of Phase 7)
└─ .htaccess  (Require all denied: protects the tree when the whole repo sits in htdocs)
```

### 3.2 Core (`src/`, Phase 1)

| File | Role |
|---|---|
| `bootstrap.php` | Defines `APP_ROOT`. Loads the autoloader and Config. Sets UTC, error and log settings (`display_errors` only when `env=dev`), a hardened session in `storage/sessions`, security headers plus CSP, and a maintenance flag (JSON 503 on `/api/`). Global handler shows a generic 500 with an incident id. |
| `Config.php` | Looks for `PFPMS_CONFIG`, then `config/config.php`, otherwise fatal. Never uses `SERVER_NAME`. Refuses to run in prod with debug on or insecure cookies. |
| `Db.php`, `Db/ConcurrencyException.php` | Lazy PDO with `EMULATE_PREPARES=false`. `transaction()` supports nesting via savepoints and retries 1213/1205. Helpers: `updateVersioned()` (`row_version`), `isDuplicateKey()`, `durable()` (second autocommit connection for audit rows that must survive a rollback). |
| `Clock.php` | `now()` in UTC, injectable `FrozenClock` for tests, `siteToday($site)`. |
| `Http/{Request,Response,Flash,Page,Api}.php` | Typed input. Redirects only to app-relative paths. `Page::start()` and `Api::start()` run the guard pipeline. |
| `Security/{Csrf,Crypto,RateLimit,Headers}.php` | CSRF via `_csrf` field or `X-CSRF-Token` header, plus an Origin/Sec-Fetch-Site check. AES-256-GCM strings and files with a key ring. Tokens are stored as SHA-256 hashes. `rate_limit_bucket`. |
| `Auth/{Auth,SessionManager,PasswordPolicy,Tokens,Devices,Pin,OfflineGrant,Rbac,SiteAccess}.php`, `Auth/capabilities.php` | Every request checks the `user_session` row (idle 30 min / absolute 12 h / still Active). Argon2id with bcrypt fallback. Tokens for Password Reset, Temporary Credential, Trusted Device and Offline Grant. Time-boxed site grants are checked on every request. |
| `Audit/{Audit,Snapshot}.php` | Writes inside the caller's transaction by default, or durable for Denied/Failed. `audit_field_change` diffs. Secrets are redacted. |
| `Settings/{Settings,registry.php}` | Typed registry (type, default, min/max). Every change is audited. |
| `Validation/Validator.php` | Ported legacy validators (§6) plus rule-map validation. The same rules are exported as JSON to the Station. |
| `View/{View,helpers,Menu}.php`, `View/nav.php` | `e()` escaping. `asset()` with a build hash. The menu is built from `{file, label, capability}`, and an entry renders only if its file exists and `can()` passes. Site switcher. Notification badge. |
| `Mail/{Mailer,Outbox}.php` | Transports: smtp, log (dev, writes `.eml`), array (tests). Bodies are encrypted until sent. |
| `Storage/{SecureFileStore,ImageProcessor}.php` | Random file names under `storage/secure`. finfo MIME check. GD re-encode strips EXIF. Files are encrypted. `public/file.php` authorises by entity and site. |
| `Notify/Notifications.php` | Work queue on the `notification` table. |
| `Cron/Jobs/*.php` via `bin/cron.php <job>\|all` | CLI-only guard. `GET_LOCK` per job. |
| `Sync/{SyncService,Canonical,Hmac}.php`, `Sync/Handlers/*.php` | Phase 2B onward. |

**CLI tools** (all CLI-only): `bin/migrate.php`, `seed.php`, `create-admin.php`, `generate-key.php`, `maintenance.php`, `db-reset.php` (only for `_dev`/`_test` databases), `schema-dump.php` (writes a consolidated DDL for the ERD team), and `ci/guard.php`.

### 3.3 Domain modules
Every module follows the same pattern: Repository, Service, Validator, and a sync handler where the Station writes that data. Pages and handlers call the same Service, so UC-12 §4.5's "same rules everywhere" holds.

Modules: `Reference/` (site, device, language, species, breed, size_band, lookup_value, service area, policy, clinic directory), `Account/`, `Inventory/` (`Ledger::post()` locks rows in product order, then an INSERT…ON DUPLICATE upsert, then an `inventory_transaction`), `Participant/` (with `NameKey`, `DuplicateDetector`, `ServiceArea`, `AlertService`, `ParticipantStatus`), `Pet/` (`AllotmentCalculator`, `SnvEligibility`), `Event/`, `Distribution/` (`DistributionService`, `EligibilityService`, `ReversalService`), `Snv/`, `Import/`, `Reporting/`, `Governance/`.

### 3.4 Station (PWA)
- **Shell:** `public/station/index.php` contains no user data. JS modules: `api, db, vault, session, outbox, sync, pack, search, rules, calc, router`, plus views: `login, home, search, participant, register, pet, checkin, distribute, receipt, dashboard, sync-status, device`. Rendering uses `textContent` only. Strict CSP with no inline script.
- **Service worker (`sw.php`):** precaches the shell only. It never caches PHP pages or `/api/`. Non-Station pages fall back to `offline.html`. Updates apply only when no draft is open. A config flag works as a kill switch.
- **IndexedDB `pfpms`:** `meta` and `keyring` are plaintext; everything else is AES-GCM ciphertext. Other stores: `vault_users, pack, outbox, drafts, sessions, audit_queue, notifications`.
- **Keys:** as R8. Every outbox payload carries an HMAC from the user's grant key; an Admin override adds an Admin co-signature.
- **Wipe:** on deregistration (`Clear-Site-Data`), on the `device.wipe_mode` directives Push-Then-Wipe or Wipe-Now, when the pack is older than its TTL (the outbox is kept), and after too many failed unlocks.
- **Sync pipeline (`api/sync/push.php`, ≤50 items):**
  1. Per-device `GET_LOCK`.
  2. Upsert offline sessions as `user_session` rows (`auth_method='Offline'`), then ingest queued audit rows.
  3. Process items in `seq` order, one transaction per item. For each: check idempotency on `sync_item.client_uuid` (a different payload hash returns 409), check authenticity (HMAC, grant not revoked, device active), resolve `{client_uuid}` references, then call the shared Service.
  4. Store the outcome as Accepted, Accepted With Exception or Held, and notify.

  Offline `local_date` is the event's date. The frequency check looks both sides of the window, because devices can sync out of order.

### 3.5 Page skeleton (every page in `public/`)
```php
<?php declare(strict_types=1);                         // SPDX-License-Identifier: GPL-3.0-or-later
require __DIR__ . '/../src/bootstrap.php';
use Pfpms\Http\{Page, Request, Response, Flash}; use Pfpms\Security\Csrf; use Pfpms\View\View;
use Pfpms\Participant\ParticipantService; use Pfpms\Db\ConcurrencyException;
$ctx = Page::start(['capability' => 'participant.update', 'site' => true]); // session→forced change→policy ack→capability→site, before any input
$p = ParticipantService::findVisible(Request::int('id'), $ctx) ?? Response::notFound();
$errors = [];
if (Request::isPost()) {
    Csrf::verify();
    try { ParticipantService::update($p, Request::only([...]), $ctx);       // validates; one Db::transaction; audit inside
          Flash::success('Saved.'); Response::redirect('participant_view.php?id=' . $p['participant_id']); }
    catch (ValidationException $e) { $errors = $e->errors; }
    catch (ConcurrencyException $e) { $conflict = $e->current; }           // UC-04 §3.3.2 field-by-field
}
View::render('participant/edit', compact('ctx', 'p', 'errors') + ['conflict' => $conflict ?? null]);
```
API endpoints follow the same shape: `Api::start([...])` (401/403/409/422/503 JSON, CSRF header on anything other than GET) and `Response::json()`. There is no SQL in pages. A CI page-contract test checks that the first statement after the bootstrap require is `Page::start(` or `Api::start(`. The only exceptions are the allowlisted public files: login, forgot_password, reset_password, activate, healthz, offline.html, sw.php, station/index.php, clinic/*, api/ping, api/auth/login, and later self_register and my_pantry.

**Default capability matrix** (`src/Auth/capabilities.php`; Coordinator inherits Volunteer, Administrator inherits Coordinator, Board inherits nothing):
- **Volunteer:** profile.self, auth.pin_switch, participant.search/view/register/update, pet.edit, pet.delete_request, event.checkin, distribution.record, history.view, snv.refer, inventory.view, offline.distribute/checkin/register/pet_edit.
- **Coordinator adds:** event.manage/dashboard, inventory.receive/count, catalog.barcode_link, intake_question.manage, language.manage, device.register, session.roster/remote_signout, user.site_grant_temporary, distribution.reverse, sync.review, snv.manage, report.aggregate.view.
- **Administrator adds:** site.all, site/settings/lookup/policy/service_area/catalog/clinic/budget.manage, user.manage, participant.update_restricted/deactivate/merge/delete/restore/erasure/area_override/search_include_deleted, alert.create/resolve, pet.delete/limit_override/microchip_resolve, distribution.authorize_override/emergency (level from settings), import.run/during_distribution_hours, report.run/schedule/identifiable (which also needs `can_extract_identifiable`), audit.view.
- **Board:** profile.self, dashboard.board, report.aggregate.view, read-only site.all. No writes and no offline grant.

## 4. Phased roadmap

Sizes are rough dev-days for one developer; divide by team size. Tracks marked ∥ can run in parallel.

| Phase | Goal | Size | Release |
|---|---|---|---|
| P0 | Security remediation and repo hygiene | 3–5 | — |
| P1 | Foundation: schema v2.0.1 + v2.1, core, auth, CI/deploy | 45–55 | staging |
| P2 | Configuration, accounts, stock (2A) ∥ offline platform (2B) | 50–65 | staging |
| P3 | Participants, pets, events, online and offline intake | 50–65 | staging |
| P4 | Station Record Distribution + history + offline dress rehearsal (**critical gate**) | 35–45 | staging |
| P5 | SNV and clinic portal, import template, go-live (can run ∥ P4 once P3 exits) | 20–26 | **Release 1** |
| P6 | Governance and operations | 29–37 | R1.1 |
| P7 | Full import and reporting | 41–50 | R2 |
| P8 | Could stories + training mode | 29–38 | R3 |
| P9 | Coulds that depend on client decisions (self-service, OCR) | 0–28 | if kept |

Critical path: P0 → P1 (core, auth) → P2A (sites, size bands, allotment v1) with P2B (vault, station auth, outbox, push) alongside → P3 (participants, pets, pack) → P4 (distribution, dress rehearsal) → go-live.

### Phase 0: Security and hygiene
- **Goal:** neutralise exposed secrets and unauthenticated endpoints, and strip the repo down to what will be rebuilt.
- **Rotate or revoke** (the account owners do this; values are never copied anywhere):
  - SiteGround prod DB user and password (`database/dbinfo.php:26-29`). The DB is never reused for PFPMS.
  - Local `foodpantrydb` password.
  - Gmail app password (`forgotPassword.php:16-23`).
  - Retire `ENCRYPTION_KEY` (`emailEncryption.php:3`) and the server environment key used by `security_config.php`.
  - Purge `secure_uploads/*.enc` on the old host (CCDA verified-ID images).
  - SMTP credentials in the server's `email/.env`.
  - Delete the `vmsroot`/`vmsroot2` rows.
  - Treat the staff bcrypt hashes in `sql/foodpantrydb.sql:4198-4211` as compromised.
  - Tell the owners of `r-brt/FoodPantry` and `jenniferp217` about the exposure.
- **Old site (`jenniferp231.sg-host.com`):** preferred is to decommission it after CCDA signs off on an export. Otherwise:
  - delete `insertAdmin.php`, `email/`, `*.log`, `sql/` and the zip;
  - disable the `scheduledSend` cron in Site Tools;
  - upload an interim deny `.htaccess` (Foundation plan §a2 rules).
- **Repo** (branch `pfpms/phase-0`; commits need the user's OK):
  - delete the 116 pages and the non-page junk listed in §6;
  - move the 40 remaining pages and their reference `database/` and `domain/` files, `include/`, `universal.inc`, `lib/` and `css/js/images/fonts` to `legacy/` (outside `public/`).
- **Archive:** put the dumps and `public_html.zip` into one AES-256 7-Zip or `age` archive on a restricted drive with a named custodian. CCDA owns the data; default is to delete it 90 days after the staff accounts are re-created. Logs are deleted, not archived.
- **Add** `.gitignore` (config/*.php except the example, `.env*`, `storage/*`, `vendor/`, `node_modules/`, `*.log`, `*.sql` except migrations/seeds/docs schema, `*.zip`, `*.bak`, `*.enc`, OS and editor files), `.gitattributes` (`eol=lf`) and `.editorconfig`.
- **Recommend, but do not do without explicit OK:**
  - Run `git filter-repo --invert-paths` on a mirror clone.
  - Force-push `main`, `dev`, `Heji`, `Rachelle` and `Selwyn` (remote `tselwyn/chsPetPantry`; visibility is unknown).
  - Everyone re-clones.
  - Rotation alone neutralises the credentials; the purge deals with the personal data.
- **Exit criteria:**
  - Owners confirm the rotations.
  - No `*.log`, `*.zip` or `*.sql` outside `docs/`.
  - gitleaks is clean on the working tree (history stays flagged until a purge).
  - On the old host, the dangerous endpoints return 403/404 or the site is gone.
  - 116 pages deleted, 40 in `legacy/`.

### Phase 1: Foundation
- **Goal:** a guarded, audited, migratable skeleton that is deployable to SiteGround staging.
- **Modules:** everything in §3.2 core; UC-01 online (base flow, §3.2.1 reset, §3.2.2 forced change, §3.3.1 lockout with Admin notification, §3.3.2, Manage User Profile); **US-03** (policy acknowledgement gate; declining ends the session); **UC-17** shell (role home: current site, alerts and notifications count, nav from the registry; Board sees a placeholder).
- **Create:**
  - All `src/` core files.
  - `templates/layout/*`.
  - Pages: `public/{index,login,logout,forgot_password,reset_password,activate,change_password,policy_ack,select_site,profile,notifications,file,healthz}.php`.
  - `public/.htaccess` (HTTPS redirect except localhost, dotfile deny, sensitive-extension deny, nosniff/Referrer/X-Frame/Permissions headers), root `.htaccess` (deny all), `assets/css/app.css` (rebranded `css/base.css` plus the `.report-table`/`.modify-*` classes currently inlined in 18 pages), `assets/css/print.css`.
  - `bin/*`, `config/config.example.php`, `migrations/0001–0009` + `optional/9001_immutability_triggers.php`, `seeds/reference/*` (placeholders for the client to review) and `seeds/dev/*` (synthetic data; refuses to run in prod).
  - `phpunit.xml.dist`, `tests/Integration/{SchemaContractTest,PageContractTest}`, `tools/docker-compose.db.yml` (mariadb:10.4 on 3310, mysql:8.4 on 3384), `.github/workflows/{ci,deploy}.yml`.
  - `docs/PFPMS_schema_v2_1_changes.md`; rewrite `README.md`.
- **Composer:**
  - require: php ≥8.2, ext-pdo_mysql/openssl/mbstring/json/gd/zip, phpmailer ^7, phpspreadsheet ^5.6, dompdf, chillerlan/php-qrcode;
  - require-dev: phpunit ^11, phpstan ^2;
  - `vendor/` is no longer committed; CI builds it, or a release zip is used as fallback.
  - Enable gd/zip/intl in `C:/xampp/php/php.ini`.
  - Run prod on PHP 8.3 or later: 8.2 security support ends 31 Dec 2026.
- **Adapt** (source → target):
  - `legacy/login.php` → `login.php` (only the `password_verify` flow carries over);
  - `header.php` → `View/Menu.php` + `app.css` (dropdown CSS/JS, minus the accessibility-button TypeError);
  - `index.php` → UC-17 `index.php`;
  - `changePassword.php` → `change_password.php`;
  - `viewProfile.php` + `editProfile.php` + `profileEditForm.php` → `profile.php` (display name, phone, notification prefs, PIN set/change; no role, site or status);
  - `security_config.php` + `serve_image.php` → `SecureFileStore` + `file.php` (the ideas only);
  - `template.php` → `templates/pages/_example.php`;
  - `include/*` helpers → `Validator` and `View/helpers` (§6).
- **Docs:** edit `docs/PFPMS_schema_v2.sql` in place to v2.0.1 (§5 row 0001). `migrations/0001` is the same file minus the 59 `DROP TABLE` lines; CI asserts the two match. Correct `docs/PFPMS_useful_functions.md`, which recommends `emailEncryption.php`, calls the sanitize helpers safe, and describes `export_data` wrongly. Update notes §3: immutability is enforced in the app layer plus optional triggers, because the host probably doesn't offer table grants.
- **Delete from `legacy/`** once replaced: header, index, template, login, logout, changePassword, security_config, viewProfile, editProfile, profileEditForm, serve_image, universal.inc, lib/jquery*, and CSS no longer referenced.
- **Migrations:** 0001–0009 applied (§5). 9001 is attempted and recorded as Skipped if the host refuses triggers.
- **Deploy:**
  - New SiteGround site and database, with users `pfpms_app` (DML) and `pfpms_owner` (DDL).
  - SSH deploy: maintenance on → mysqldump backup → rsync `public/` to `public_html/` (keep `.well-known/`) → rsync `src/templates/bin/migrations/seeds/reference/vendor` → `migrate --confirm-backup` → maintenance off → `curl healthz`.
  - Let's Encrypt with HTTPS enforced; Dynamic Cache off.
  - Cron: `mail:send` every minute; `all` every 5 minutes.
  - Never use SiteGround's Git tool.
- **Exit criteria:**
  - CI green on MariaDB 10.4, MySQL 8.4 and Percona 8.4 (migrate, Integration suite, second migrate reports nothing to do).
  - SchemaContractTest passes (§7).
  - Login, lockout, reset (single use), forced change, policy ack and timeouts all pass their tests.
  - The first Admin is created by CLI.
  - The page-contract and CSRF tests pass.
  - Staging over HTTPS returns 403/404 for `/vendor /config /storage /src /legacy /.git composer.json *.sql`.

### Phase 2A: Configuration, accounts, stock
- **Goal:** an Admin or Coordinator can configure everything a distribution needs.
- **Reference and config admin** (the unspecified admin screens from UC-cross-cutting item 14):
  - `admin_sites.php`/`admin_site_edit.php` (IANA time zone).
  - `admin_devices.php`: register or deregister, and see last seen/sync, pending count, build, revoke/wipe.
  - `admin_languages.php`: US-23 list; `en` cannot be deactivated.
  - `admin_policies.php`/`admin_policy_edit.php`: versioned per type × language; a version that has been used is immutable.
  - `admin_settings.php`: registry-typed; changes audited.
  - `admin_service_area.php`: bulk ZIP paste, ZIP+4 normalised, optional site.
  - `admin_species.php`, `admin_breeds.php`.
  - `admin_size_bands.php`: US-13 pictures; weight ranges may not overlap.
  - `admin_allotment_rules.php` (**source `legacy/viewShoppingList.php`**): draft → publish versions; one version in force per date; a rule for every active species × band is required.
  - `admin_intake_questions.php`: US-09 add, retire, reorder, required flag, Choice options.
  - `admin_lookups.php`: `lookup_value` lists, including body types per species with pictures (US-13).
  - `admin_clinics.php` (directory: address, hours, phone, low-cost vaccination flag).
  - All in `src/Reference/*`.
- **UC-11 core:**
  - `admin_users.php` (source `viewAuditUsers.php`), `admin_user_create.php` (source `createUser.php`; invitation only, no typed password), `admin_user_edit.php` (source `viewModifyUser.php` + `resetPassword.php`: role, sites, expiry, reset/unlock, deactivate with effective date), `admin_user_credential.php` (§3.3.5 printable).
  - Rules:
    - email must be unused (§3.3.1); `volunteer_max_sites` (§3.3.2);
    - at least one active Admin (§3.3.3); no editing your own role or sites (§3.3.4);
    - temporary credential valid 72 h, single use;
    - any role, site or status change ends the user's sessions (§4.4).
  - Code in `src/Account/*`.
- **Catalogue and stock:**
  - `inventory_catalogue.php` (source `viewItemCategories.php`), `inventory_product_edit.php`/`inventory_category_edit.php` (source `viewAddItemCategory.php` + `viewModifyItemCategory.php`: species, form, `unit_weight_lbs`, barcodes; **US-16** linking, barcodes are global).
  - `inventory_receipts.php`/`inventory_receipt_edit.php` (source `viewManagePallets.php`, `viewAddPallet.php`, `viewModifyPallet.php`): header plus lines, 'Receipt' ledger rows, auto-generated names, void by 'Reversal'.
  - `inventory_count.php` (source `viewUpdateInventory.php` + `editInventoryEvent.php` + `deleteInventoryEvent.php`): refused while an event is Open at the site; locks rows `FOR UPDATE`; writes 'Count Adjustment' rows.
  - `inventory_stock.php` (source `inventory.php`): on hand plus a ledger drill-down.
  - Code: `src/Inventory/{CatalogueRepository,InventoryRepository,Ledger,InventoryService}`.
- **Delete from `legacy/`:** the 15 sources named above.
- **Exit criteria:** seeds loaded and editable; an allotment version published; an opening receipt posted; for every site and product, Σ ledger = `quantity_on_hand` after any sequence of receipt, count and void; users invited and activated; the last-Admin and self-edit refusals are tested.

### Phase 2B (∥ 2A): Offline platform, on the critical path
- **Goal:** a registered tablet can sign in online or offline, PIN-switch, and push idempotent outbox items.
- **Modules:**
  - **US-01** PIN fast-switch: online `api/auth/pin.php` and an offline verifier. It needs a registered device, access to the site, and a password login on this device within `pin_shift_hours`. The switch takes under 5 s and the open entry stays with the Station draft.
  - UC-01 §3.3.3 offline restricted session.
  - Device registration, credential and heartbeat.
  - Grant issuance and revocation.
  - Outbox and sync engine.
- **Create:**
  - `public/{sw.php,manifest.webmanifest,offline.html}`, `station/index.php`, `station/assets.php`, `station/js/{app,router,api,db,vault,session,outbox,sync,rules,calc}.mjs`, `station/views/{login,home,device,sync-status}.mjs`, `station/vendor/idb.mjs`, `station/css/station.css` (reuses `lib/bootstrap/css/bootstrap.min.css` and the base.css tokens; 44 pt targets).
  - `api/{ping,session}.php`, `api/auth/{login,pin,logout}.php`, `api/device/{register,heartbeat}.php`, `api/sync/{push,status}.php`.
  - `src/Auth/{Devices,Pin,OfflineGrant}.php`, `src/Sync/{SyncService,Canonical,Hmac}.php`.
  - JS/PHP parity fixtures in `tests/fixtures/*.json`: canonical JSON, HMAC, accent fold, phonetic key, phone hash.
  - `tests/js` using `node --test` + fake-indexeddb.
- **Sync:** item kinds are stubbed here; real handlers arrive in P3/P4.
- **Exit criteria:**
  - Register a tablet; log in online (vault and grant written); reload offline and unlock with the password.
  - PIN switch under 5 s; refused on an unregistered device; falls back to password after `pin_max_failed`.
  - A duplicate push is a no-op; a reused uuid with a different payload returns 409; a bad HMAC is Held.
  - Remote wipe and grant revocation take effect at the next contact.
  - The privacy grep of IndexedDB and Cache Storage finds no seeded names.

### Phase 3: Participants, pets, events (online and offline intake)
- **Goal:** register, find, edit and check in households and pets, on the server and in the Station.
- **Participants: UC-02, UC-03, UC-04 (base, §3.2.1–3.2.3, §3.3.1–3.3.4), UC-18, UC-19, US-05, US-06, US-08, US-09 (form)**
  - Pages:
    - `participant_search.php` (source `personSearch.php`; keeps the GET-filter pattern, drops Tailwind): site-scoped; ranking is code > exact name > prefix > `surname_phonetic` > partial; capped at `search_max_results` with a truncation warning; an empty query shows participants served here recently; filters; Admin "include deleted"; minimal fields only, no export; card QR opens the record.
    - `participant_register.php` (source `registrationForm.php`): consent row with the policy version; intake answers; `declined_fields`; `id_sequence` code allocated in the transaction; registration stamps cannot be edited; §3.2.4 adds the site to an existing record.
    - `participant_view.php`, `participant_edit.php` (optimistic lock with a conflict screen; restricted fields Admin-only, and refusals are audited and notified; address change re-checks the service area; §3.2.3 deactivation with a lookup reason).
    - `participant_card.php` (QR `PFPMS:P:<code>:<card_version>`).
    - `registration_drafts.php` (US-08: saved without validation, not searchable, cannot receive a distribution).
    - `participant_alert.php` (UC-18 view, UC-19 resolve by Admin with a resolution).
  - US-06: collation-equal matches, plus a warning when names differ only by accents (`COLLATE utf8mb4_bin`).
  - UC-03 §3.3.1: `DuplicateDetector` (phone / postal+street / phonetic+initial or DOB / collation-equal); open the existing record, or confirm "distinct" with a justification, which is stored as a resolved 'Duplicate Candidate' alert.
  - UC-03 §3.3.3: out of area writes `referred_out_applicant` only, or an Admin overrides on the spot.
- **Pets: UC-05 (base, §3.2.1, §3.2.2, §3.3.1–3.3.4), US-13, US-14, US-26**
  - `pet_edit.php` with the size-band and body-type picture picker (`templates/partials/size_picker.php`).
  - `AllotmentCalculator`: the rule version in force on the site-local date; a missing rule is a hard error; written into `current_allotment_lbs` in the same transaction.
  - Pet limit, with Admin override (`limit_override_by`, `is_limit_exception`).
  - Microchip: 9, 10 or 15 digits; a conflict shows only the other pet's name and species; the unique key is the final guard.
  - US-14: prompt only when the expiry date is within `vaccination_warning_days`; no date shows "unknown"; lists low-cost clinics.
  - US-26: an inactive pet raises no prompts and the allotment is recalculated quietly.
  - UC-05 §3.2.1/§3.2.2 referral hooks (close as Completed Elsewhere, or void) are wired in P5, when referrals exist.
- **Events and check-in: US-04**
  - `events.php` (source `calendar.php`), `event_edit.php` (source `addEvent.php` + `editEvent.php`): one-off or weekly; opens only on the event's date (Coordinator can override); at most one Open event per site; closing sets Waiting to Left Unserved.
  - `check_in.php` (source `viewCheckInOut.php`), `api/event/check_in.php` (source `processCheckIn.php`), `api/event/queue.php` (polled every `station_poll_seconds`).
- **Station:**
  - `api/sync/pack.php`: minimised and site-scoped. No address, email, DOB or full phone; phone as last 4 digits plus a hash. Audited as a bulk extract. Fully replaced on each refresh.
  - Views `search`, `participant`, `checkin`, `register`, `pet`.
  - Online reads: `api/station/{search,participant,duplicate_check}.php`.
  - Offline flows:
    - UC-02 §3.3.3 search the cache with a "may be stale" banner, and manual identification (always Held);
    - UC-03 §3.2.3/§3.3.4 provisional registration with code `T<site>-<device>-<seq>` and a printed slip;
    - UC-05 §3.3.4 queued pet saves (`base_row_version`);
    - offline check-in.
  - Handlers: `src/Sync/Handlers/{Participant,Pet,CheckIn,ReferredOut}Handler.php`. A provisional registration with duplicate candidates is Held together with its dependants.
  - `sync_review.php` (Coordinator and above): link to an existing participant, create as distinct, commit with override, or discard with a reason. Dependants are reprocessed.
- **Print:** `templates/print/card.php` and `slip.php`, via print CSS.
- **Delete from `legacy/`:** personSearch, registrationForm, calendar, addEvent, editEvent, viewCheckInOut, processCheckIn.
- **Exit criteria:**
  - "Jose" finds "José" and the reverse; phonetic variants are found.
  - Search takes ≤2 s over 20k seeded participants.
  - Volunteers never see another site's households.
  - A failed save uses up no code.
  - The conflict screen appears on a concurrent edit.
  - The 7th pet is refused without an override; a duplicate active chip is refused.
  - A provisional participant syncs to a permanent code, or is Held and then linked.
  - The check-in queue refreshes within 15 s on a second tablet.

### Phase 4: Record Distribution station and history (critical gate)
- **Goal:** a full event can be run with the network off.
- **UC-06, in full (base, §3.2.1–3.2.5, §3.3.1–3.3.5, §4):**
  - Station views `distribute`, `receipt`, `dashboard`.
  - `api/station/product_by_barcode.php`: **US-16** scanning via `BarcodeDetector` or a keyboard wedge; an unknown code triggers linking if the user has `catalog.barcode_link`.
  - `src/Distribution/DistributionService::record()`, in one transaction:
    1. Lock the participant `FOR UPDATE`; follow `merged_into_id`.
    2. Check: event Open at the device's site; status and blocking alerts; frequency; same-day second issue; allotment.
    3. Update stock with a guarded decrement.
    4. Insert `distribution`, `_pet` and `_line` rows (Issued, Declined with reason, Shortfall; `substitutes_line_id` for **US-17**) and one ledger row per Issued line.
    5. `ParticipantStatus` recalculates last date, next eligible date, programme-year YTD and `last_confirmed_present`.
    6. Check-in outcome set to Served.
    7. §3.2.4 writes an 'Offer Deferred' followup.
    8. Audit.
  - Overrides (§3.2.2, §3.3.1, §3.3.2): the authoriser co-signs on the tablet with PIN or password, or enters an `authorization_ref`.
  - §3.2.1: proxy picked or added (`authorized_via='Phone'`).
  - §3.3.3: No Stock or Reserve List plus an `unmet_request`; no distribution row is written.
  - §3.3.5: full rollback.
  - `ReversalService`, `distribution_reverse.php` (Coordinator and above; one reversal per distribution; stock and eligibility restored).
  - `distribution_view.php` (the "View Distribution Details" include).
  - `receipt.php`: server reprint. The receipt shows no address, is printed in the participant's language and is rendered client-side.
- **Offline, UC-06 §3.2.5/§4.3:**
  - Local stock view = pack snapshot − outbox lines.
  - Local rule checks.
  - `DistributionHandler` commits and flags `sync_exception` according to R6.
  - Two-sided frequency check.
  - Negative stock is allowed for offline-origin items and triggers a notification.
  - Count-race offset: if a count was posted after `distributed_at` (derived from the count's ledger rows), add an offsetting Count Adjustment.
  - Clock-skew clamp.
  - UC-01 §4.2: the encrypted draft is kept through idle timeout and PIN switches, and restored after re-authentication.
- **US-18:**
  - `api/event/dashboard.php` (source `legacy/event.php`): served/waiting, pets, lbs and units by product, on hand; warns when waiting demand exceeds stock; per-device pending counts.
  - The Station dashboard view shows "this device only since HH:MM" while offline.
- **UC-07:**
  - `participant_history.php`, `history_print.php`: read-only; 25 per page; filters labelled "filtered"; out-of-scope sites count in totals but show no detail; reversals are linked and excluded; no-history message; print has no flags or notes; views and prints audited.
  - The referral timeline (§3.2.3) switches on in P5.
- **Cron:** `ytd:reset` (programme year), `sessions:expire`.
- **Delete from `legacy/`:** event.php.
- **Exit criteria (release gate):**
  - Dress rehearsal: at least 60 households on 3 tablets with the network cut throughout. On reconnect: 0 lost, 0 duplicated; a second replay is a no-op; killing the tab mid-sync and resuming is safe.
  - For every product, Σ Issued lines = |Σ Distribution transactions|.
  - A two-pet household takes ≤60 s; each step takes ≤2 s online.
  - Over-allotment is refused without a co-sign; a second same-day issue needs a reason.
  - A double-serve across two devices is flagged.
  - A reversal restores stock.
  - History page 1 in ≤2 s at 200 rows.

### Phase 5: SNV, clinic portal, import template → Release 1
- **UC-08 in full, plus US-21, US-22, US-23:**
  - `snv_refer.php`:
    - eligibility and `clinic_species_rule`; outside the rules gives a 'Reassess' followup and `snv_status='Deferred'`;
    - budget row locked `FOR UPDATE`; available = amount − reserved (open) − redeemed; exhausted gives a Waiting List followup and an Admin notification;
    - several pets means one voucher each and one combined sheet;
    - §3.2.3 a declined offer gets no number (0007), no reservation, and a Re-offer followup;
    - §3.2.4 Reimbursement with `needs_reconciliation`;
    - §3.3.4 next nearest clinic by city or postal prefix.
  - `snv_referral_view.php`: six-status state machine, `snv_referral_status_log`, and `pet.snv_status` kept in step.
  - `snv_followups.php`, `snv_transport.php` (§3.2.2 list).
  - `voucher_print.php`: in the preferred language from the 'SNV Explanation' policy_document; `material_language_code`; falls back to the default language and tells the volunteer (US-23); emailed copy via dompdf.
  - `admin_clinic_edit.php` (species rules; portal code generated or rotated, shown once, stored as a hash), `admin_voucher_budgets.php`.
  - Public `clinic/info.php` (US-21: no JavaScript, light page).
  - `clinic/portal.php` (US-22): code plus rate limit; 15-minute clinic-scoped session; sees only that clinic's vouchers and only pet and voucher fields; records schedule, redemption, surgery date and outcome with `outcome_source='Clinic Link'`; audit `details` names the clinic.
  - Pet hooks from P3 activated.
  - Cron `vouchers:expire` (releases the reservation, resets the pet's status) and `vouchers:remind`.
- **UC-12 minimal and US-32:**
  - `import_template.php?type=Participant|Pet`: XLSX/CSV headings, example row, notes, `Q:` columns for active intake questions.
  - `import_wizard.php`, `import_batches.php`: synchronous up to `import_max_rows_sync`; dry run (§3.2.1); same validators and DuplicateDetector; one-transaction commit tagged `record_source='Legacy'` and `import_batch_id`; rejected rows encrypted in `raw_row`; backup confirmation required.
  - Code: `src/Import/{FieldRegistry,ImportService,ImportRepository}`.
- **Go-live checklist:**
  - Production data seeded and approved by the client: sites, bands and pictures, allotment v1, products and barcodes, opening receipts, ZIP list, policies (Confidentiality EN; Consent, SNV Explanation and Retention EN/ES), clinics, rules, budget, intake questions.
  - Users and PINs set; tablets registered and installed as PWAs with `storage.persist()`.
  - Paper fallback sheet printed per event. Coordinators can enter late entries against a closed event within `late_sync_grace_days`.
  - Backup/restore drill.
  - All R1-replaced files deleted from `legacy/`.
- **Exit (Release 1):**
  - Concurrent issues cannot overspend a budget.
  - A declined offer uses no voucher number.
  - The portal cannot see another clinic's data or any participant contact data.
  - Spanish voucher correct, with the fallback notice.
  - Import template round trip maps automatically; a dry run writes nothing; a mid-commit failure leaves 0 rows.
  - Prod deploy healthy.

### Phase 6: Governance and operations (first 30–60 days live)
- **UC-09:**
  - `participant_delete.php`: dependency review, deactivate recommended, lookup reason, refused while a Pending or Scheduled referral exists, `status_before_delete`, pets inactivated with a flag, snapshot, recovery window.
  - `participant_restore.php` (§3.2.4).
  - `erasure_requests.php`: a second, different Admin approves. Anonymise in place (placeholders, `postal_code='00000'`, `is_anonymized`, `anonymous_ref`). Purge proxy, consent, notes, answers, alerts, drafts, rejected rows, pet names/chips/photos and `sync_item` payloads. Distribution FKs are left untouched. The allowlisted `ErasureService` redacts audit personal data; this is a documented exception to audit immutability and is subject to the client's OK.
- **US-25:** `retention_notice.php`, in the preferred language.
- **UC-04 §3.2.4 merge and §4.4 versions:** `participant_merge.php` moves pets, proxies, consents, notes, answers and open referrals. Distributions are never updated; queries resolve `merged_into_id`. `participant_versions.php` restores field by field from `audit_field_change`.
- **UC-10:** `pet_delete.php` (refused if `distribution_pet` rows or an open referral exist; allotment recalculated in the same transaction; a Volunteer's attempt raises an Admin notification), `pet_merge.php`.
- **UC-05 §3.2.3/§3.2.4:** `pet_transfer.php` (writes `pet_household_history`, recalculates both households), `pet_photo.php` (source `legacy/upload_encrypted_image.php`; compressed to `photo_max_mb`, encrypted, served by `file.php`).
- **US-02:** `session_roster.php` (source `checkedInVolunteers.php`; remote sign-out).
- **US-28:** time-boxed grants in `admin_user_edit.php`. The end defaults to the end of the day's event, and lapse is enforced on every request.
- **UC-11 access review:** `access_review.php`.
- **UC-01 §3.2.3:** trusted device (pre-fills the username only).
- **Audit and notifications:** `audit_log.php` (filters, snapshot and diff viewer; its own exports are audited); richer `notifications.php`.
- **US-11:** stale-contact prompt via `AlertService`; defer once per visit. **US-12:** service-note panel in `participant_view.php` (280 characters, guidance text) and added to the pack.
- **Delete from `legacy/`:** checkedInVolunteers, upload_encrypted_image.
- **Exit criteria:** restore after the window is refused; an erasure approved by its own requester is refused; totals are unchanged after erasure; the merged history is correct; a lapsed grant is denied on the next request.

### Phase 7: Full import and reporting
- **UC-12 full and UC-11 §3.2.4:**
  - Background validation with cron `imports:run` (Queued/Running; 25 MB / 50k rows in ≤10 min).
  - Continuation batches; updates to matched rows with field audit.
  - Rollback (`ImportRollbackService`) only for rows with that `import_batch_id` that have never been used. This is the only allowlisted delete path for distributions.
  - Distribution import creates synthetic legacy events.
  - Record type 'User' for roster import.
  - Rejection threshold; distribution-hours rule; file purge.
- **UC-13:**
  - `report_hub.php` (source `legacy/generateReport.php`), `report_view.php` (parameters, Chart.js self-hosted, definitions, data currency, drill-down, period compare).
  - `report_export.php` (source `legacy/processInventoryReport.php` L9-10/L127+: streamed CSV with BOM, PhpSpreadsheet XLSX, dompdf PDF; footer; Export audit).
  - `saved_reports.php`/`saved_report_edit.php` (recipients must be named accounts), `report_runs.php`, cron `reports:run`.
  - Code: `src/Reporting/{Metrics,SmallCell,Periods,Definitions/*}`.
  - A read-only DB user if the host allows one. Heavy queries run in the background. A partial aggregate is never shown. Periods use `local_date`. Reversals are excluded. Legacy batches are a separate series. Small cells are suppressed, with secondary suppression. Identifiable output needs the capability, the user flag and a recorded purpose.
- **UC-14:** includes the weekly site report (source `legacy/viewWeeklyReport.php`), actual vs target via `admin_grant_commitments.php`, and inventory reconciliation.
- **UC-15 and UC-16:** all variants listed in the analyst report.
- **Stories:** **US-24** (monthly deletion digest as a scheduled report), **US-36** (demand forecast; source `legacy/viewConsumptionRates.php`; seasonal factors in `saved_report.parameters`), **US-38** (wait times; volunteer counts estimated from `user_session` and labelled as estimates), **US-39**, **US-40** (multiplier plus the separate citation setting; surgery count always shown), **US-33** (`board_dashboard.php`, phone layout, reads nightly `report_run` snapshots, Board users redirected to it).
- **Delete from `legacy/`:** generateReport, processInventoryReport, viewWeeklyReport, viewConsumptionRates. After this `legacy/` is empty and is removed.
- **Exit criteria:** metric definitions are shared across reports; suppression holds in every output that leaves the organisation; an identifiable export without a purpose is refused and audited; the 50k-row import validates within 10 min on staging.

### Phase 8: Could stories and training mode
- **US-07:** public `self_register.php?site=` reached from the site QR: honeypot plus rate limit; the draft expires after `self_service_draft_hours`; a volunteer claims it and records consent and proof of residence.
- **US-15:** 'Size Band Review' prompt driven by `size_review_juvenile_months`. The age-to-size rule needs client input.
- **US-20:** welfare prompt calculated from `local_date` intervals × `welfare_prompt_multiplier`. Never stored; can be dismissed.
- **US-27:** `pet_presence_review.php`.
- **US-29 (a Should placed late because it needs the finished app):**
  - A separate `pfpms_training` database with the same migrations and a sample seed (`bin/reset-training-db.php`).
  - A session flag switches the DSN, and a red banner is shown.
  - Cron, reports and the offline pack are disabled in training.
  - `practice_completed_at` is written on the live connection.
- **US-30:** `due_again_on` plus a grace period, then read-only capabilities.
- **US-34:** narratives on `report_view.php`, included in exports and schedules.
- **US-35:** `admin_metric_thresholds.php` plus cron. Rate-limited, with the notification rows serving as history.
- **US-37:** decline rate. **US-41:** SNV uptake by site; low-count sites marked, not ranked.
- **Exit criteria:** each story's acceptance criteria pass; nothing entered in training mode reaches live tables (integration test).

### Phase 9: Coulds that depend on client decisions
- **US-10, US-19:** public `my_pantry.php`, with a link sent to the recorded contact method. v2.2 tables `participant_access_token` and `participant_change_request` avoid changing `auth_token.user_id NOT NULL`. A phone change applies immediately; an address change waits for confirmation.
- **US-31:** needs an OCR vendor plus a privacy review, and v2.2 `intake_scan` with `registration_draft.source` += 'Scan'. **Recommend descoping.**
- **Exit criteria:** the client decides; if kept, the stories' acceptance criteria pass.

## 5. v2.1 schema migration list

All files are additive and portable:
- collation `utf8mb4_unicode_520_ci`;
- no `ADD COLUMN IF NOT EXISTS`;
- new ENUM values are appended at the end only;
- every new table has `ENGINE=InnoDB` and a primary key.

Migrations are applied in P1; the phase column says where each change is first used. `schema_version` is a tooling table created by `bin/migrate.php`, not part of the ERD.

| File | Change | Reason (UC/US) | Phase |
|---|---|---|---|
| 0001 (v2.0.1) | `COLLATE=utf8mb4_0900_ai_ci` → `utf8mb4_unicode_520_ci` on 59 tables; `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci`; `pet.status` moved above `active_microchip`; header comment updated | D2; US-06 accent-insensitive | P1 |
| 0002 | New `system_setting` rows. **Auth:** organisation_name, default_language, lockout_minutes 15, session_absolute_hours 12, password_min_length 12, password_max_age_days 0, password_hibp_check 0, reset_link_minutes 60, temp_credential_hours 72, trusted_device_days 30, pin_min_digits 4, pin_max_digits 6, pin_max_failed 3, pin_shift_hours 12, volunteer_max_sites 2, account_review_days 90, policy_reack_days 365, policy_reack_grace_days 30. **Offline:** offline_mode_enabled 1, offline_grant_hours 72, offline_pack_ttl_hours 72, offline_cache_days 90, offline_max_participants 5000, offline_max_failed_unlocks 10, offline_pbkdf2_iterations 600000, sync_clock_skew_minutes 10, late_sync_grace_days 7, sync_payload_retention_days 90, station_poll_seconds 15. **Operations:** search_max_results 100, recent_participants_days 30, history_page_size 25, photo_max_mb 5, over_allotment_auth_level / emergency_auth_level Administrator, programme_year_start 01-01, volunteer_draft_days 14, self_service_draft_hours 24, voucher_reminder_days 14, flag_review_days 180, welfare_prompt_multiplier 2.0, pet_presence_review_days 365, size_review_juvenile_months 12. **Import, reports, retention:** import_max_file_mb 25, import_max_rows 50000, import_max_rows_sync 5000, import_max_reject_pct 20, report_max_span_months 36, report_interactive_seconds 30, report_result_retention_days 30, distribution_retention_years 7, auth_audit_retention_months 12, audit_statutory_retention_years 7, litters_prevented_citation. `litters_prevented_multiplier` is re-described as numeric only. | Gap 9; UC-01/02/05/06/07/11/12/13; US-01/07/15/20/27/30/40; D4 | P1 (read P1–P8) |
| 0003 | `auth_token.purpose` += 'Offline Grant'; + `secret_ciphertext` VARCHAR(255) NULL, `revoked_at` DATETIME NULL | UC-01 §3.3.3; UC-06 §4.3 attribution | P2 |
| 0003 | `user_session.end_reason` += 'Password Reset', 'Deactivated', 'PIN Switch', 'Device Lock' | UC-01 §3.2.1; UC-11 §3.2.3; US-01 | P1/P2 |
| 0003 | `device` += `token_hash` CHAR(64) NULL UNIQUE, `vault_key_ciphertext`, `offline_enabled`, `last_seen_at`, `last_sync_at`, `pending_count`, `app_build`, `revoked_at`, `revoked_by` (FK), `wipe_mode` ENUM('None','Push Then Wipe','Wipe Now') | US-01 "site-registered devices"; offline wipe and monitoring | P2 |
| 0003 | `user_account` += `row_version`, `display_name`, `deactivation_effective_date`, `pin_failed_count` | UC-11 concurrency and §3.2.3; UC-01 Manage User Profile; US-01 | P1/P2 |
| 0003 | Index `user_site_access (user_id, site_id, ends_at)` | Per-request guard; US-28 | P1 |
| 0003 | New table `rate_limit_bucket (bucket PK, window_start, hits, blocked_until)` | UC-01 §3.3.1; US-22; US-07 | P1 |
| 0004 | `participant.client_uuid` CHAR(36) NULL UNIQUE; `participant.provisional_code` VARCHAR(20) NULL UNIQUE | UC-03 §3.2.3; UC-02 §3.3.3 (gap 4) | P3 |
| 0004 | `pet.client_uuid` NULL UNIQUE; `event_check_in.client_uuid` NULL UNIQUE | UC-05 §3.3.4; offline US-04 | P3 |
| 0004 | `distribution.sync_exception` VARCHAR(40) NULL | UC-06 §3.2.5 "report failures" (R6) | P4 |
| 0004 | New table `sync_item` (client_uuid PK, device_id FK, client_seq, kind ENUM('Participant','Pet','Check In','Check In Outcome','Distribution','Referred Out','SNV Deferral','Barcode Link'), origin, recorded_by FK, recorded_at_client DATETIME(3), received_at, depends_on_uuid, payload_sha256, payload_ciphertext MEDIUMTEXT NULL, status ENUM('Accepted','Accepted With Exception','Held','Resolved','Discarded'), reason_code, entity_type, entity_id, resolved_by FK, resolved_at, resolution_note; indexes (device_id, client_seq), (status)) | UC-06 §4.3 no loss / no duplicates; UC-03 §3.2.3 | P2 |
| 0005 | New table `notification` (recipient user / role / site, kind, entity, message, created_by, read / resolved fields) | Gap 6: UC-01 §3.3.1, UC-03 §3.2.3, UC-04 §3.3.3, UC-05 §3.3.2, UC-08 §3.3.3, UC-10 §3.3.3, UC-13 §3.2.4, sync | P1 |
| 0005 | New table `outbound_message` (channel, recipient, template_key, subject, body_ciphertext, status, attempts, not_before, sent_at) | UC-01 reset; UC-06 receipt; UC-08 voucher and reminders; UC-11 invitation, §3.3.5 | P1 |
| 0006 | `participant` += `declared_pet_count`, `card_version` DEFAULT 1, `status_before_delete` | UC-03 step 6; card invalidation (UC-02 §3.2.1); UC-09 §3.2.4 | P3/P6 |
| 0006 | `pet` += `limit_override_by` (FK), `is_limit_exception`, `inactivated_by_owner_delete` | UC-05 §3.3.3 (gap 14); UC-09 §3.2.4 (gap 5) | P3/P6 |
| 0006 | New table `unmet_request` (check_in_id FK, species_id, food_form, product_id NULL, quantity_units NULL, reserve_for_event_id NULL, recorded_by, recorded_at) | UC-06 §3.3.3 (gap 7); UC-14 unmet requests | P4 |
| 0007 | `snv_referral.voucher_number` becomes NULL; the unique key is kept | UC-08 §3.2.3/§4.1 (gap 3); US-41 | P5 |
| 0008 | New table `id_sequence (seq_name PK, prefix, next_value)`, seeded with 'participant' | UC-03 "no ID used up on failure" (gap 16) | P3 |
| 0008 | New table `lookup_value (lookup_id PK, list_key, value_code, label, species_id NULL, picture_path NULL, display_order, is_active)`, seeded with: pet_colour, body_type, participant/pet delete reasons, deactivation, emergency, decline, referral_source, proof_of_residence | Gap 10; UC-05 §4.5; UC-09/10 step 5; UC-15; US-13; US-17 | P2/P3 |
| 0008 | Reserved `system` user_account row (Inactive, unusable hash) | NOT NULL `created_by`/`granted_by` for seeds and cron | P1 |
| 0009 | `import_batch.record_type` and `import_mapping.record_type` += 'User'; `import_batch.status` += 'Queued', 'Running'; `audit_log.import_batch_id` NULL + FK + index | UC-11 §3.2.4; US-32 template per type; UC-12 §4.4 background and §3.2.4 rollback (gap 8) | P5/P7 |
| optional/9001 | BEFORE UPDATE/DELETE triggers on distribution, distribution_line, distribution_pet, audit_log, audit_field_change, snv_referral_status_log, inventory_transaction; bypassed only by allowlisted services through `@pfpms_allow_mutation` | Notes §3 immutability, since grants are unlikely on shared hosting | P1 (Skipped if the host refuses) |
| conditional | `override_authorization` (code_hash, kind, event_id, issued_by, expires_at, used_by_distribution_id), only if the client wants pre-approved references that can be verified offline | UC-06 §3.2.2 | P4 |
| v2.2 (P9, only if kept) | `participant_access_token`, `participant_change_request` (US-10/19); `intake_scan` + `registration_draft.source` += 'Scan' (US-31) | Notes §4.4 | P9 |
| no DDL change | `import_rejected_row.raw_row` holds GCM ciphertext (UC-12 §4.7). Seasonal factors go in `saved_report.parameters` (US-36). Volunteer counts are estimated from `user_session` (US-38). | | P5/P7 |
| documented for the ERD team only | clinic↔site link (UC-08 §3.3.4); participant/pet status history (UC-15/16); non-rabies vaccinations; shift roster; aligning `allotment_rule.food_form` with `product.food_form` | Gaps 11–14 | — |

After v2.1 the schema has 66 ERD tables: 59 plus rate_limit_bucket, sync_item, notification, outbound_message, unmet_request, id_sequence and lookup_value. `bin/schema-dump.php` writes a consolidated `docs/PFPMS_schema_v2_1.sql` for the design team.

## 6. Legacy disposition summary

**Counts (156 root pages):**
- The UI analyst classed them as 110 DELETE, 31 ADAPT, 15 REWRITE.
- Phase 0 deletes **116**: the 110, plus `insertAdmin`, `toggleLock`, `scheduledSend` (REWRITE) and `emailEncryption`, `forgotPassword`, `changeForgottenPassword` (ADAPT), for security.
- **40** are quarantined to `legacy/` (28 ADAPT + 12 REWRITE). Each is deleted when its successor lands: P1 11, P2 15, P3 7, P4 1, P6 2, P7 4.

**Phase 0 deletions (the 110 DELETE pages):**
- **Shell:** infoBox.
- **Users (11):** modifyUserRole, VolunteerRegister, deleteUserSearch, deleteUser, deletePerson, volunteerManagement, getVolunteers, milestonePoints, viewVolunteerProfile, accountEditHistory, viewArchived.
- **Events, sign-ups, hours (49):**
  - calendar-view, calendar-view_daily, calendar-view_weekly, cancelEvent, completeEvent, date, deleteEvent, eventSearch
  - approveSignup, rejectSignup, event-list, eventList, logAttendees, setTimes, viewSignUpList, noShows
  - adminViewingEvents, eventApproved, viewAllEvents, viewMyUpcomingEvents
  - denyApplication, eventManagement, eventSignUp, fromPendingApproveSignup, fromPendingFlagSignup, fromPendingRejectSignup, process_application, viewAllApplications, viewApplication, viewEventSignUps, viewPendingApps
  - eventsOpenForSignUpReview, viewEventsForSignUp
  - autoCheckOut, clockOut, clockOutBulk, editTimes, deleteTimes, editHours, volunteerReport
  - applicationSuccess, eventFailure, eventFailureBadDepartureTime, eventSuccess, requestFailed, signupPending, signupSuccess, processAttendees, viewRetreatApplications
- **Training (2):** addTraining, eventTrainingManagement.
- **Communications (29):**
  - createDiscussion, deleteBulk, deleteDiscussion, deleteReply, discussionContent, discussionMain, viewDiscussions
  - createSuggestion, viewSuggestions
  - createGroup, deleteGroup, showGroups, manageMembers, groupManagement, volunteerViewGroup, volunteerViewGroupMembers
  - inbox, viewNotification, deleteNotification
  - createEmail, editDrafts, viewDrafts, emailDraft, sendDraft, email, generateEmailList, scheduleEventEmails, emailDraftView, emailSingleDraftView
- **Inventory (1):** viewEditDeleteInventory.
- **Reports (5):** report, reportsPage, reports, reportsCompute, reportsExport.
- **Uploads (7):** view_encrypted_gallery, approve_encrypted_image, deny_encrypted_image, resources, uploadResources, deleteResources, viewResources.
- **Misc (5):** create_dummy_dbpersonhours, viewData, viewLocation, deleteLocation, deleteService.

**Also deleted in Phase 0:**
- `public_html.zip`; the logs `php_errorlog`, `cron_debug.log`, `scheduledSend_errors.log`, `test.log`, `email_debug.log`, `email_errors.log`; `testmsg.txt`, `.DS_Store`, `readme (2).md`, `php.ini`.
- `sql/` (after archiving).
- `email/` (bundled PHPMailer, `get_oauth_token.php`, the open relays, `send_email.py`).
- `database/dbinfo.php`, and the 22 dead `database/*.php` files (all except dbPersons, dbItemCategory, dbInventoryEvent, dbItemCounts, dbPalletEvent, dbPalletCounts) with their `domain/` classes, including `InventoryEvent.php`.
- Root `event.css` and `event.js`; `js/{calendar,event,view-switcher,messages}.js`; `css/{event,roster,hours-report,messages}.css`, `css/normal_tw.css`, `css/management_tw.css`, `package*.json`; `include/time.php`.
- Other organisations' brand images (whiskey*, WV, FredSPCA*, rmh, odhs), plus the CCDA logos once the CHS logo exists.

**Quarantined in Phase 0, deleted in the phase shown:**
- **P1:** header, index, template, login, logout, changePassword, security_config, viewProfile, editProfile, profileEditForm, serve_image.
- **P2:** createUser, viewAuditUsers, viewModifyUser, resetPassword, viewShoppingList, viewItemCategories, viewAddItemCategory, viewModifyItemCategory, viewManagePallets, viewAddPallet, viewModifyPallet, viewUpdateInventory, editInventoryEvent, deleteInventoryEvent, inventory.
- **P3:** personSearch, registrationForm, calendar, addEvent, editEvent, viewCheckInOut, processCheckIn.
- **P4:** event.
- **P6:** checkedInVolunteers, upload_encrypted_image.
- **P7:** generateReport, processInventoryReport, viewWeeklyReport, viewConsumptionRates.

The 6 reference `database/` files and their `domain/` classes, `include/`, `universal.inc` and `lib/` are removed by the end of P2/P3.

**Legacy code reused (port with tests, into `src/Validation/Validator.php` / `src/View/helpers.php`):**
- `include/input-validation.php`, ported as is: `validateDate` L109, `validate12hTimeRangeAndConvertTo24h` L135, `validate12hTimeAndConvertTo24h` L147, `validateAndFilterPhoneNumber` L155, `validateEmail` L163 (plus a 100-character cap).
- Fixed, then ported:
  - `validate24hTimeRange` L117: checks `$start` twice, never `$end`.
  - `validate24hTime` L127: needs anchors.
  - `wereRequiredFieldsSubmitted` L167: default should be `$blankOkay=false`.
  - `validateZipcode` L176: accept ZIP+4.
  - `valueConstrainedTo` L184: strict `in_array`.
  - `validateURL` L207: http(s) only.
- Replaced: `isSecurePassword` L211 → `PasswordPolicy`.
- Never reused: `_sanitize` L16, `sanitize` L52, `sql_safe_input` L77, `sql_safe_associative_array` L86, `trainingLevelMet` L12, `convertYouTubeURLToEmbedLink` L188. The file itself is never included: its lines 3-4 load `dbinfo.php`.
- `include/output.php`: `hsc` L3 becomes `e()` (`ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5`, recursive); `time24hTo12h` L14, `formatPhoneNumber` L32, `floatPrecision` L37 kept. `unpackMessageTimestamp` L41 and `prepareMessageBody` L51 dropped.
- `include/api.php:redirect` L3 → `Response::redirect` (relative paths only).
- Replaced: `reportsCompute.php` `pretty_date` L115 and `calculate_age` L295 (2-digit years) → `DateTimeImmutable::diff`; `export_report` L447 and `reportsExport.php:export_data` L152 → streamed Export service.
- Patterns reused:
  - `processInventoryReport.php` L9-10/L127+ (PhpSpreadsheet XLSX);
  - `upload_encrypted_image.php` (GD re-encode then encrypt) and `security_config.php` (storage outside the web root), rewritten with GCM and entity-level authorisation;
  - `header.php` dropdown CSS and `css/header.js`;
  - `css/base.css` structure (rebrand the CCDA palette at L70-77);
  - `lib/bootstrap/css/bootstrap.min.css`;
  - `fonts/` (Montserrat, OpenDyslexic);
  - the GET-filter pattern in `personSearch.php`;
  - the header-plus-lines form in `viewAddPallet.php`;
  - `dbShifts.php`/`dbGroups.php` prepared-statement style, as reference only.
- Kept: the GPL-3.0 `LICENSE.txt` and the Homebase copyright headers.

## 7. Verification

**Schema on both engines**
- MariaDB 10.4 (XAMPP): `C:/xampp/mysql/bin/mysql.exe -u root -e "CREATE DATABASE pfpms_verify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"`, then `php bin/migrate.php --with-optional --config=config/verify-mariadb.php`.
- MySQL 8.4 and Percona 8.4: `docker compose -f tools/docker-compose.db.yml up` (or `percona/percona-server:8.4`), then the same migrate command.
- If `caching_sha2_password` over plain TCP fails, connect over TLS or start the CI server with `--mysql-native-password=ON`.
- `SchemaContractTest`, run on each engine:
  - table and FK counts from `information_schema` (59/143 at 0001, then the exact expected totals after 0009);
  - every table's collation is `utf8mb4_unicode_520_ci` (MariaDB JSON columns report `utf8mb4_bin`, which is allowed);
  - `SHOW WARNINGS` is empty.
- Smoke tests:
  - 'Muñoz' is found by `munoz`, and 'José' = 'JOSE';
  - two Active pets with the same chip give 1062; making one Inactive frees the chip; reactivating it gives 1062 again;
  - `JSON_EXTRACT` round trip;
  - `id_sequence` leaves no gap after a rollback;
  - two nullable voucher numbers can coexist;
  - `migrate` twice reports nothing to do;
  - `mysqldump --no-data` diff shows only JSON vs LONGTEXT.

**PHPUnit** (`phpunit.xml.dist`, Unit + Integration; DB name must end in `_test`; `FrozenClock` throughout; CI matrix PHP 8.2/8.3/8.4 × mariadb:10.4 / mysql:8.4 / percona 8.4):
- **Unit:**
  - validators, including the fixed legacy bugs; PasswordPolicy;
  - Crypto round trip, tampered tag or authenticated data fails, key rotation;
  - settings registry;
  - capability invariants: Board has no write capability; Volunteer has no `*.delete`, `import.*` or `user.manage`; every capability used in code exists in the matrix;
  - Menu hides admin entries;
  - AllotmentCalculator; frequency window; `local_date`.
- **Integration:**
  - savepoints, deadlock retry, `updateVersioned` conflict;
  - an audit row rolls back with its business write, while a durable audit row survives;
  - login, lockout, auto-unlock, reset single-use and expiry, 72 h temporary credential, idle and absolute timeouts, remote sign-out and permission change;
  - a grant lapses mid-session;
  - an unauthenticated POST to every `public/*.php` writes nothing (PageContractTest); missing CSRF returns 400 and writes nothing;
  - distribution commit writes all 5 tables together; a failure injected after the ledger post leaves nothing; stock updates from two concurrent connections serialise;
  - sync: same batch twice, uuid reuse, out-of-order seq, double-serve across devices, provisional → pet → distribution chain, Held → link → dependants reprocessed, late sync after close, count-race offset, negative stock, revoked device or grant, bad HMAC, deactivated actor, parallel pushes (`GET_LOCK`);
  - reversal restores stock and eligibility;
  - with 9001 loaded, UPDATE or DELETE on an immutable table fails;
  - budget cannot be overspent under concurrency;
  - clinic portal isolation;
  - import dry run writes nothing; mid-commit failure leaves 0 rows;
  - erasure leaves totals unchanged.
- **JS:** `node --test` with fake-indexeddb: vault wrap/unwrap, wrong password, tampered data, PIN lockout, outbox state machine, draft restore. Shared PHP/JS parity fixtures.
- **E2E:** Playwright (Chromium, `context.setOffline`, `page.clock`) against `https://pfpms.localhost`: install and precache, keyring, offline unlock, PIN switch, provisional registration, 30 distributions, idle-expiry restore, reconnect and sync, remote wipe. Privacy assertion: dump IndexedDB and Cache Storage, grep for seeded names, expect 0 hits.
- **Performance:** 20k seeded participants; search ≤2 s; station step ≤2 s; history ≤2 s at 200 rows.

**Manual distribution-day walkthrough** (staging over HTTPS, 3 Android tablets plus one iPad as best effort):
1. Coordinator posts a receipt, opens the event and registers the tablets. Each volunteer logs in online, then chooses "Prepare device for event" (pack downloaded).
2. Cut the network at the router.
3. Unlock offline. Check in 5 households. Search "munoz". Serve a two-pet household in ≤60 s and print the receipt; the next eligible date is shown.
4. PIN-switch mid-entry; the draft is preserved.
5. Provisional registration plus a pet plus a distribution against it; the slip prints.
6. Over-allotment without a co-sign is refused, then succeeds with an Admin co-sign.
7. Let the session idle out; the draft is restored after re-authentication.
8. Serve the same household on tablet 2.
9. The dashboard shows device-only counts "as of".
10. Reconnect:
    - 0 lost and 0 duplicated;
    - the provisional participant gets a permanent code;
    - the double-serve is flagged and a notification is sent;
    - replaying the batch in DevTools is a no-op;
    - for every product, Σ Issued = −Σ Distribution txns;
    - `site_stock` = Σ ledger.
11. The Coordinator resolves items in `sync_review.php` and reverses one distribution; stock and eligibility are restored.
12. Close the event; Waiting becomes Left Unserved; post a count.
13. `audit_log` shows the Offline sessions, pack download and sync batches.

**Security checks:**
- gitleaks over the full history (expected to flag until any purge) and on every PR.
- `curl -I` on staging for `/vendor/`, `/config/config.php`, `/composer.json`, `/storage/`, `/src/`, `/legacy/`, `/.git/`, `*.sql` and `*.log`: expect 403/404.
- Response headers present: HSTS, CSP, nosniff, X-Frame, `no-store`.
- Session cookie is HttpOnly, Secure, SameSite.
- CI guard fails on:
  - `0900_ai_ci`, `->>`, `VALUES(` upserts, `SKIP LOCKED`;
  - UPDATE or DELETE on immutable tables outside the allowlist;
  - includes of `legacy/`;
  - `*.log`, `*.zip` or stray `*.sql`.
- PHPStan level 5.
- Optional OWASP ZAP baseline scan against staging before go-live.
- Rate-limit tests on login, forgot password and the clinic portal.

## 8. Open questions for the client

1. **Roles** (notes §4.1): are Coordinator and Board real roles? Is the default capability matrix right (who authorises overrides, links barcodes, resolves held sync items, reverses distributions)?
2. **Delete Pet Administrator-only** (notes §4.2; the UC-10 diagram note)?
3. **Keep US-10/19 (participant self-service) and US-31 (OCR intake)?** Recommendation: descope US-31, which would send participant data to a third party.
4. **Offline scope:** UC-01 §3.3.3 says "distribution only", but UC-03 §3.2.3 and UC-05 §3.3.4 allow intake offline. Confirm the four offline capabilities.
5. **Replay policy (R6):** commit-and-flag with Coordinator review. Can stock go negative for offline records?
6. **Offline limits:** maximum offline window (72 h grant and pack TTL), `offline_cache_days` (90), a pack of up to 5,000 households on each tablet, and whether a DPIA-style note is needed.
7. **UC-06 §3.2.2 "pre-approved reference":** how are these issued? This decides whether the conditional `override_authorization` table is built.
8. **Is a US-18 dashboard that sees only its own device acceptable while offline?**
9. **Station hardware:** Android or Chromebook as primary, iPad best-effort as an installed PWA? Receipt printer: browser print or thermal?
10. **Final production domain** before tablets are rolled out: a change of origin wipes every device.
11. **The old CCDA site** (`jenniferp231.sg-host.com`): decommission or lock down? Who approves the CCDA data export and archive retention?
12. **GitHub:** is `tselwyn/chsPetPantry` public, or was it ever? OK to purge history and force-push all five branches?
13. **Seed values** (all placeholders now): size bands and weights, allotment lbs per species × band × form, products and unit weights, the service-area ZIP list, `programme_year_start`, policy texts EN/ES, clinics, rates and budget, intake questions.
14. **How Treat/Other products count** against the Dry/Wet/Any allotment forms.
15. **Clinic outcome "Not Performed":** which of the six statuses does it map to? Probably Void, with a reason.
16. **Erasure (UC-09 §3.2.2):** may personal data be redacted from `audit_log.snapshot` and `audit_field_change` as a documented exception, or should crypto-shredding be used?
17. **Legacy staff:** which ~4 accounts? Role mapping (superadmin→Administrator, admin→Coordinator or Administrator, inventory_counter→Volunteer)? Are they pet-pantry staff at all?
18. **Other defaults to confirm:** email provider or mailbox on the PFPMS domain (SMS is out of scope because it needs a paid gateway); the audit retention purge policy; the US-15 age→size rule; nearest site/clinic by postal prefix; whether vaccinations other than rabies are needed.

## 9. Coverage matrix

**Use cases**

| UC | Phase(s) | UC | Phase(s) |
|---|---|---|---|
| UC-01 Login | P1 online (base, §3.2.1, §3.2.2, §3.3.1, §3.3.2, profile); P2B §3.3.3 offline + US-01; P4 §4.2 draft restore; P6 §3.2.3 trusted device | UC-11 Manage Users | P2A core (§3.2.1–3.2.3, §3.3.1–3.3.5); P6 access review; P7 §3.2.4 roster import |
| UC-02 Search | P3 (incl. §3.2.1 scan, §3.3.3 offline) | UC-12 Import | P5 base + §3.2.1 (Participant/Pet); P7 §3.2.2–3.2.4, §3.3.x, distributions, users |
| UC-03 Register | P3 (incl. §3.2.3 / §3.3.4 offline, §3.2.4, §3.3.3) | UC-13 Generate Reports | P7 |
| UC-04 Update | P3 base, §3.2.1–3.2.3, §3.3.x; P6 §3.2.4 merge, §4.4 versions | UC-14 Distribution Reports | P7 |
| UC-05 Pets | P3 base, §3.2.1–3.2.2, §3.3.x (incl. §3.3.4 offline; referral hooks P5); P6 §3.2.3 transfer, §3.2.4 photo | UC-15 Participant Reports | P7 |
| UC-06 Record Distribution | P4 (all) | UC-16 Pet/SNV Reports | P7 |
| UC-07 History | P4 (§3.2.3 referral timeline P5) | UC-17 View Dashboard | P1 shell; grows each phase; Board view P7 |
| UC-08 SNV Referral | P5 (all, incl. §3.2.2 transport list) | UC-18 View Participant Alerts | P3 |
| UC-09 Delete Participant | P6 | UC-19 Remove Participant Alert | P3 |
| UC-10 Delete Pet | P6 | | |

Included and extending use cases:
- Manage User Profile: P1.
- Detect Duplicate, Validate Participant and Pet, Select Participant, Flag/Unflag, Create Alert, Generate Duplicate Alert, Distribution Restriction Alert: P3.
- Select Food Type and Quantity, Capture Date and Location, Update and Calculate Participant Status, View Distribution Details: P4.
- Print, Export PDF, Export CSV, Export Excel: P4 (history print) and P7.

**User stories** (M = Must, S = Should, C = Could)

| US | Ph | US | Ph | US | Ph | US | Ph |
|---|---|---|---|---|---|---|---|
| 01 M | P2B | 12 S | P6 | 23 S | P2A list, P5 | 34 C | P8 |
| 02 S | P6 | 13 S | P2A, P3 | 24 S | P7 | 35 C | P8 |
| 03 M | P1 | 14 S | P3 | 25 S | P6 | 36 S | P7 |
| 04 S | P3 | 15 C | P8 | 26 M | P3 (referral void P5) | 37 C | P8 |
| 05 S | P3 | 16 S | P2A link, P4 scan | 27 C | P8 | 38 S | P7 |
| 06 S | P1 collation, P3 | 17 S | P4 | 28 S | P6 | 39 S | P7 |
| 07 C | P8 | 18 M | P4 | 29 S | P8 | 40 S | P7 |
| 08 S | P3 | 19 C | P9 | 30 C | P8 | 41 C | P8 |
| 09 S | P2A config, P3 form | 20 C | P8 | 31 C | P9 (recommend descope) | | |
| 10 C | P9 | 21 S | P5 | 32 M | P5 | | |
| 11 S | P6 | 22 M | P5 | 33 S | P7 | | |

- All 41 stories and all 19 use cases are placed; none is uncovered.
- All 6 Must stories are in Release 1 (P1–P5).
- Should stories after R1: US-02, 11, 12, 24, 25, 28, 29, 33, 36, 38, 39, 40. US-02, US-12 and US-28 are cheap R1 stretch candidates.
- The three Musts that depend on Should work are sequenced correctly: US-04 → US-18, US-09 → US-32, and the alert framework → US-26.

**Schema tables: phase of first use (all 59 used)**
- **P1:** user_account, user_session, auth_token, policy_document, policy_acknowledgement, system_setting, audit_log, audit_field_change.
- **P2:**
  - user_site_access, site, device, language, species, breed, size_band, allotment_rule, intake_question, service_area_postal_code;
  - clinic (directory);
  - item_category, product, product_barcode, site_stock, stock_receipt, stock_receipt_line, inventory_count, inventory_count_line, inventory_transaction.
- **P3:** participant, participant_site, participant_consent, participant_alert, registration_draft, intake_answer, referred_out_applicant, pet, pet_household_history (at creation), distribution_event, event_check_in.
- **P4:** distribution, distribution_pet, distribution_line, participant_proxy, snv_followup (Offer Deferred).
- **P5:** clinic_species_rule, voucher_budget, snv_referral, snv_referral_status_log, import_mapping, import_batch, import_rejected_row.
- **P6:** service_note, erasure_request.
- **P7:** saved_report, report_recipient, report_run, grant_commitment.
- **P8:** report_narrative (US-34), metric_threshold (US-35).

Count: 8 + 12 + 6 + 14 + 6 + 13 = 59. **Unused tables: none.** All 7 v2.1 tables are used from P1 (notification, outbound_message, rate_limit_bucket, lookup_value), P2 (sync_item), P3 (id_sequence) and P4 (unmet_request).

### Critical Files for Implementation
- C:/Users/maryw/Documents/Pelican/chsPetPantry/docs/PFPMS_schema_v2.sql (the baseline, edited to v2.0.1 and copied to `migrations/0001_pfpms_schema_v2.sql`; v2.1 migrations 0002–0009 build on it)
- C:/Users/maryw/Documents/Pelican/chsPetPantry/src/bootstrap.php, src/Db.php, src/Http/Page.php, src/Auth/capabilities.php (new: the core every page and API depends on)
- C:/Users/maryw/Documents/Pelican/chsPetPantry/src/Distribution/DistributionService.php and src/Sync/SyncService.php (new: the single commit path used online and by offline sync)
- C:/Users/maryw/Documents/Pelican/chsPetPantry/public/sw.php and public/station/js/vault.mjs (new: the PWA shell and encrypted offline store)
- C:/Users/maryw/Documents/Pelican/chsPetPantry/include/input-validation.php (legacy validators to port, fixing L117/L127/L167/L176/L184/L207 first; never include it)
