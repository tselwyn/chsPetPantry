<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

# Infrastructure and risk audit of the inherited chsPetPantry repo

Repo root: `C:/Users/maryw/Documents/Pelican/chsPetPantry`. The repo has 7 commits and 213 non-vendor PHP files, and there is no `.gitignore`. I masked secret values below; they are in full in the repo and in git history from commit 1.

## (a) Auth and session

**Login flow** (`login.php`)
- `login.php:33` looks the user up with `retrieve_person($username)`. `login.php:44` checks the password with `password_verify`.
- On success (`login.php:45-60`) it sets these session keys: `logged_in`, `access_level`, `f_name`, `l_name`, `type`, `_id`, `_personId`.
- `login.php:63-65` hardcodes the `vmsroot` account: it forces `access_level=3` and sets `$_SESSION['locked']=false`.
- There is no `session_regenerate_id`, so sessions can be fixed by an attacker. There is no failed-login counter, lockout or rate limit.
- `login.php:10` sets `display_errors=1`. `login.php:85` loads the Tailwind Play CDN, which is meant for development only.

**Access levels** (`domain/Person.php:73-82`)
- Levels come from the free-text `dbpersons.type`, compared case-insensitively: `superadmin`=3, `admin`=2, anything else (`inventory_counter`, `''`, typos)=1. 0 means not logged in (comment at `resetPassword.php:11`).
- Pages compare the level with hardcoded numbers: `<2` ×36, `==1` ×12, `<1` ×9, `>=2` ×9, `<3` ×7, `>2` ×6, `>1` ×5, `>=3` ×5, and a few others.
- This does not map onto PFPMS roles. Board is a read-only reporting role, not a step on a ladder. Plan to replace the numbers with capability checks keyed on `user_account.role` plus `user_site_access`.

**Session keys in use** (count of references)
- `_id` 239, `access_level` 169, `type` 6, `locked` 6, `_personId` 5, `logged_in` 4, `change-password` 4, `f_name`/`l_name` 2 each.
- Feature state: `edit_event`, `selected_people`, `returned_people`, `results`.
- `provider`, `clientId`, `clientSecret`, `tenantId` come only from `email/PHPMailer/PHPMailer/get_oauth_token.php`. That is a sample OAuth script, reachable from the web, that should be deleted.

**Page gating**
- Each page copies an `isset($_SESSION['_id'])` block, for example `resetPassword.php:9-21`.
- `header.php:822-826` also checks a `$permission_array` of 116 entries. But that check runs only when `header.php` is included, which is after the page's own POST handling. It is skipped entirely when the user is not logged in (`header.php:649`).
- Pages that change data with no login check at all:
  - `viewUpdateInventory.php:1-75`: an anonymous POST writes inventory.
  - `deleteBulk.php`: anonymous discussion delete.
  - `clockOut.php`
  - `insertAdmin.php`: anyone can re-create `vmsroot`.
  - `scheduledSend.php`
  - `email/sendEmail.php` and `email/send_email.php`
- `createUser.php:7` reads `$_SESSION['access_level']` without an `isset` check.

**Password hashing**
- Uses `password_hash` with BCRYPT or DEFAULT (`changePassword.php:37,69`, `changeForgottenPassword.php:41`, `database/dbPersons.php:166`, `VolunteerRegister.php:173`, `resetPassword.php:50`). No md5 or sha1.
- The strength rule (`include/input-validation.php:211`) is 8+ characters with upper, lower and a digit. It is not applied when a forced change happens (`changePassword.php:31-37`).

**Default account**
- `insertAdmin.php:36` creates `vmsroot`/`vmsroot`, and `README.md:38,99,108` documents it.
- The committed prod dump `sql/foodpantrydb.sql:4199,4202` has `vmsroot` and `vmsroot2` with the same hash. I checked with XAMPP's `php.exe`: `password_verify('vmsroot', …)` returns **true**.

**Forced password change does not work**
- `force_password_change` is written (`dbPersons.php:184,192`, `insertAdmin.php:39`) but nothing ever reads it.
- `$_SESSION['change-password']` is never set anywhere, so the forced branch in `changePassword.php:21` can never run.

**Password reset (critical)**
- `forgotPassword.php:10` emails a link built as `changeForgottenPassword.php?email=` plus `encryptEmail($email)`. That is AES-256-CBC with a key hardcoded at `emailEncryption.php:3` (`[redacted]`).
- The token has no expiry, no single-use flag, no MAC, and nothing about it is stored.
- `changeForgottenPassword.php:16-42` decrypts the token and changes the password with no other check. Anyone holding the repo can forge a link for any email, including a superadmin's, and take over the account.
- The link is plain `http://` on a hardcoded host.
- `resetPassword.php:47-49` generates the temporary PIN with `mt_rand`, which is not cryptographically random, and shows it on screen.

**CSRF:** none. There are no tokens anywhere.

**Session timeout:** none.
- `session_cache_expire(30)` appears in about 120 files. It only sets HTTP cache headers; it does not end idle sessions.
- No `gc_maxlifetime`, no last-activity check, no secure/httponly/samesite cookie flags.
- The target design expects `system_setting.session_idle_minutes=30` and a `user_session` table (`docs/PFPMS_schema_v2.sql:1105`).

## (b) Security issues

**Database credentials** (`database/dbinfo.php`)
- Lines 22-25: local `foodpantrydb`/`foodpantrydb`/`foodpantrydb`.
- Lines 26-29: prod, switched on `SERVER_NAME=='jenniferp231.sg-host.com'`, with user `[redacted-db-user]`, database `[redacted-db-name]`, and a plaintext password (`[redacted]`).
- Line 35 echoes the connection error.
- No `mysqli_set_charset` call anywhere in the codebase.
- From the command line `SERVER_NAME` is unset, so cron jobs fall back to the local credentials (see `scheduledSend_errors.log`).

**Other secrets**
- `forgotPassword.php:16-23`: Gmail SMTP account `[redacted-gmail-account]` with its app password (`[redacted]`) in plaintext.
- `emailEncryption.php:3`: hardcoded `ENCRYPTION_KEY`.
- `security_config.php:4-9` defines the same constant name from `getenv('ENCRYPTION_KEY')` and calls `die()` if it is missing, so the two files clash if both are included. `security_config.php:14-18` runs `mkdir` on `../secure_uploads/` while serving requests.
- `docs/PFPMS_useful_functions.md:61` recommends reusing `emailEncryption.php`. Don't: CBC with no MAC and a key in the repo is not safe to reuse.

**Committed logs**

| File | Size | Leaks |
|---|---|---|
| `php_errorlog` | 1.7 MB, 11,915 lines | Server paths for `jenniferp130`, `jenniferp231` |
| `cron_debug.log` | 15 KB | Cron activity |
| `scheduledSend_errors.log` | 24 KB | DB user `whiskeydb`, host `jenniferp217` |
| `test.log` | 14 KB | Cron activity |
| `email_debug.log`, `email_errors.log` | small | Relay 403 errors |
| `email/email_errors.log` | 37 KB | Many real email addresses |
| `testmsg.txt` | 0 bytes | — |

- `sql/foodpantrydb.sql:4198-4211` has real CCDA staff names, emails and bcrypt hashes. The SQL dumps contain 36 distinct email addresses in total.

**`public_html.zip`** (23 MB compressed, 33.5 MB unpacked, 2,604 entries; listed with `zipfile`, not extracted)
- It is a full site snapshot, dated 2026-04-02 to 2026-07-23.
- Contents: all PHP, `vendor/`, `sql/` including the SiteGround dump, `database/dbinfo.php`, every log, `.DS_Store`, and a nested `.git/` (1,164 entries; remote `git@github.com:r-brt/FoodPantry.git`, branches `siteground` and `dev`).
- No `.env` file inside.

**Other repo hygiene**
- `.DS_Store` is committed (18 KB).
- There is no `.htaccess` anywhere. `email/.env`, which several scripts expect to find in the web root, would be served to anyone.

**`display_errors` turned on**
- `universal.inc:3` sets `display_errors=1`, and `universal.inc` is included by 82 pages.
- 51 files set `display_errors` to 1 in total, overriding `php.ini:2`'s `display_errors = off`.

**SQL injection**
- 31 files build SQL by concatenating or interpolating variables (about 206 lines).
- Worst offenders: `database/dbPersons.php` 45 hits (e.g. `:184`, `:192` concatenate `$id`), `viewShoppingList.php` 22, `dbItemCounts.php` 17, `dbPalletCounts.php` 12, `dbEvents.php` 11, `database/InventoryEvent.php` 11.
- Only 19 files use `prepare()`. There are 502 `mysqli_query`/`->query` calls and 99 `real_escape_string` uses.
- `sanitize()` (`include/input-validation.php:52`) applies `htmlspecialchars`, which escapes HTML, not SQL.

**Old front-end libraries:** `lib/jquery-1.9.1.js` (known XSS CVEs) and Chart.js loaded from a CDN without integrity hashes.

## (c) Email

The code has four separate, inconsistent ways of sending mail.

1. **Password reset** (`forgotPassword.php:2-30`): the composer copy of PHPMailer (`vendor/autoload.php`, v7.0.2) with the hardcoded Gmail credentials above.
2. **Bundled PHPMailer copy** (`email/PHPMailer/PHPMailer`, v7.0.1, 72 files, a duplicate of `vendor/`):
   - Used by `createEmail.php:53-71`, `sendDraft.php:38-56` and `scheduledSend.php:36-104`.
   - Settings come from `email/.env` (not committed) with keys `SMTP_HOST`, `SMTP_USER`, `SMTP_PASS`, `SMTP_PORT`, `SMTP_FROM_NAME`.
3. **Remote relay**:
   - `email.php:63` `sendEmails()` POSTs with cURL to the hardcoded `https://jenniferp217.sg-host.com/email/send_email.php`, which is another project's host.
   - `email/send_email.php` has no auth and hands the request to `send_email.py` through `proc_open`.
   - `send_email.py` is broken: no imports and no indentation, so it fails with `IndentationError` at line 12.
   - `email/sendEmail.php` has no auth either and works as an open SMTP relay. It hardcodes `/home/customer/www/jenniferp217…/email/.env` at line 10, uses a different key name (`SMTP_SERVER`), and sets `display_errors=1`.
   - The logs show only 403 responses.
4. **Cron** (`scheduledSend.php`):
   - Reads `dbscheduledemails`, which `scheduleEventEmails.php` fills with legacy event reminders.
   - The cron entry is not in the repo; it was set up in SiteGround Site Tools. Logs show it running every minute from 2025-12-10 and failing with "Access denied" because of the `SERVER_NAME` fallback in `dbinfo.php:26`.
   - There is no command-line-only guard, so anyone can trigger it over the web. It writes `cron_debug.log` and `test.log` into the web root on every run (`:6,18,74-88`).

**Recommendation:** keep one PHPMailer, the composer one, configured from settings outside the web root. Delete the bundled `email/` folder, the Python relay and `get_oauth_token.php`. Rebuild password reset on `auth_token`.

## (d) Tooling

**Composer**
- `composer.json` requires `phpmailer ^7.0` and `phpspreadsheet ^5.6`; the lock file pins 7.0.2 and 5.6.0.
- **`vendor/` is committed** (806 files: phpspreadsheet 570, zipstream 90, phpmailer 72, and others).
- `vendor/composer/platform_check.php:7` requires **PHP ≥ 8.2.0**.
- Only two files use it: `forgotPassword.php` (PHPMailer) and `processInventoryReport.php` (PhpSpreadsheet).

**Tailwind**
- `package.json` lists only `@tailwindcss/cli` and `tailwindcss` ^4.1.4, with no scripts. `node_modules` is absent and there is no input CSS.
- `css/normal_tw.css` (37.6 KB) and `css/management_tw.css` (34.4 KB) are pre-built v4.1.4 output. Only 19 legacy volunteer, group and discussion pages link them, and PFPMS drops those modules (`docs/PFPMS_schema_v2_notes.md:124`).
- `login.php`, `discussionMain.php` and `viewProfile.php` use the Play CDN instead.
- Everything else uses the hand-written `css/base.css`. So Tailwind is effectively unused.

**Tests, CI, linting:** none. No PHPUnit, no `.github`, no linters, no `.editorconfig`, no Docker files.

**PHP version**
- Prod runs PHP 8.2.33 (`sql/foodpantrydb.sql:8`).
- The local XAMPP install at `C:/xampp` has PHP 8.2.4 (and MariaDB 10.4.28).
- The code relies on `??`, typed functions and array destructuring, so it needs PHP 7.1 or later; `vendor/` sets the real floor at 8.2.

## (e) Database platform risk

**What each environment actually runs**
- **Prod (SiteGround):** Percona Server 8.4.6-6, i.e. MySQL 8.4 LTS, with phpMyAdmin 5.2.2 (`sql/foodpantrydb.sql:2,7`; the SiteGround dump in `sql/Old Versions/` header matches).
- **Dev (XAMPP):** MariaDB 10.4.28 / 10.4.32 with phpMyAdmin 5.2.1. This is the header on 20 of the 23 dumps in `sql/Old Versions/`.
- This machine also has MySQL Server 8.0 and 9.4 installed under `C:/Program Files/MySQL`, plus Docker.
- The legacy dump only uses the `utf8mb4_general_ci`, `unicode_ci` and `unicode_520_ci` collations, which is why it moved cleanly between the two.
- `docs/PFPMS_schema_v2_notes.md:7` says the new schema was tested on MySQL 8.0 only.

**How `PFPMS_schema_v2.sql` would behave on MariaDB 10.x**

| Feature | Where | MariaDB 10.4 behaviour | Minimal substitution |
|---|---|---|---|
| `COLLATE=utf8mb4_0900_ai_ci` | all 59 tables (L26…L889) | **Hard failure:** ERROR 1273 "Unknown collation". My understanding is that only MariaDB 11.4.5 and later accept it as an alias — please verify. | `utf8mb4_unicode_520_ci` works on both MySQL 8 and MariaDB 10.x, and is accent- and case-insensitive, which still satisfies US-06. Use `utf8mb4_uca1400_ai_ci` on MariaDB 10.10+. |
| `JSON` columns (8) | `user_account` L49, `registration_draft` L271, `intake_question` L286, `audit_log` L747-748, `import_mapping` L785, `saved_report` L827, `report_run` L846 | Loads, but stored as `LONGTEXT` with an automatic `CHECK(JSON_VALID())`. The `->`/`->>` operators are not supported, and the JSON type is lost if dumped and reloaded into MySQL. | Declare `LONGTEXT CHECK (JSON_VALID(col))` explicitly, and use `JSON_EXTRACT`/`JSON_UNQUOTE` in PHP instead of `->>`. |
| `pet.active_microchip … GENERATED ALWAYS AS (IF(status='Active',…)) STORED` with `UNIQUE` | L394, L410 | Supported (STORED means PERSISTENT, and unique indexes on it work since 10.2). The expression refers to `status`, which is defined later at L397; MySQL allows this and MariaDB should too — verify. | If it fails, move `status` above the generated column. Last resort: a plain column the app maintains. |
| `DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)` | L737 | Works | — |
| `DATETIME … ON UPDATE CURRENT_TIMESTAMP` | L189, L274, L549, L730 | Works | — |
| Expression defaults, CHECK constraints, triggers, views, DESC or invisible indexes | none in the file | — | Nothing to substitute. The task mentioned "functional defaults", but there aren't any. |

**Related risks**
- **Connection charset:** `dbinfo.php:34` never calls `mysqli_set_charset`. Mismatched connection and column collations between MariaDB dev and MySQL prod can cause "Illegal mix of collations" errors. Add `mysqli_set_charset($con,'utf8mb4')`.
- **Append-only tables:** `docs/PFPMS_schema_v2_notes.md:113` relies on MySQL grants to stop UPDATE/DELETE on those tables. On SiteGround shared hosting, database users normally get all privileges on their database, and table-level GRANT/REVOKE is likely not available — verify. You may need to enforce this in the app instead.

**Recommendation:** develop on MySQL 8.4 (the local MySQL Server install or a Docker `mysql:8.4` image) to match prod, and retire XAMPP's MariaDB 10.4, which is past end of life. Alternatively, switch the schema to `utf8mb4_unicode_520_ci` so it loads on both.

## (f) Branding remnants

- **Summary counts** (excluding vendor, docs, sql and logs):

  | Term | Files | Lines |
  |---|---|---|
  | CCDA | 71 | 82 |
  | vms / VMS | 44 | 64 |
  | Whiskey Valor | 17 | 22 |
  | Homebase | 16 | 26 |
  | Food Pantry | 4 | 21 |
  | Step VA | 4 | 6 |
  | SPCA | 3 | 4 |
  | Catholic Charities | 1 | 3 |
  | Fredericksburg | 1 | 1 |

- **CCDA:**
  - Page titles: 44 end in `| CCDA` and 2 in "CCDA Foundation". The title varies per file, e.g. `index.php:37`, `login.php:109`.
  - Favicon at `universal.inc:15` (shows on 82 pages).
  - Logos at `header.php:654`, `header.php:848`, `login.php:116`, `login.php:131`.
  - "Food Pantry Navigation" at `header.php:852`.
  - CCDA colour palette at `css/base.css:70-74`, plus `--wv-accent-color` at `:77`.
- **Whiskey Valor:**
  - Titles: `deleteInventoryEvent.php:142`, `editDrafts.php:83`, `logAttendees.php:56`, `viewAllApplications.php:30`, `viewAllEvents.php:28`, `viewData.php:23`, `viewEditDeleteInventory.php:135`.
  - Page text: `deletePerson.php:71`, `deleteUser.php:53`, `createEmail.php:148,298`, `profileEditForm.php:247`, `registrationForm.php:148,290` (links to their privacy policy), `volunteerReport.php:130`.
  - Footer links to whiskeyvalor.org: `index.php:804-809`.
  - Comments: `database/dbApplications.php:8`, `login.php:170`.
- **FredSPCA:** `event.php:412,414`, `registrationForm.php:219`, `profileEditForm.php:256`. Images: `images/FredSPCAlogo.png`, `images/Cropped-Logo-FredSPCA.png`, `images/WV logo.webp`.
- **Step VA:** `editTimes.php:262,281`, `volunteerViewGroup.php:77`, `database/dbPersons.php:985`.
- **README.md:** `:9,23-27,157` (Catholic Charities / Step VA / Fredericksburg history), plus the XAMPP and SiteGround install sections.
- **Hardcoded hosts:** `dbinfo.php:26`, `forgotPassword.php:10`, `changeForgottenPassword.php:180`, `email.php:63`, `email/sendEmail.php:10`, `event.php:412`.
- **VMS:** the `vmsroot` user (about 25 references, e.g. `database/dbMessages.php:125-196`, `database/dbPersons.php:204`), the `VMS_NON_INCLUDE` constant at `universal.inc:6`, and `id="vms-logo"`.
- **Keep:** the Homebase copyright headers. `LICENSE.txt` is GPL v3, so that attribution has to stay.
- **Already rebranded:** `login.php:137,145` ("CHS Pet Pantry") and `index.php:460`.

## Before or at the start of the transformation
1. **Rotate secrets:** the SiteGround DB password, the Gmail app password and the email encryption key.
2. **Clean the repo:** remove the logs, `public_html.zip`, `.DS_Store` and the SQL dumps with personal data; add a `.gitignore`; purge git history if the GitHub repo is or was public.
3. **Lock down prod:** delete `insertAdmin.php` and the `vmsroot` accounts.
4. **Close the unauthenticated endpoints:** the email relays, `viewUpdateInventory.php` POST, `deleteBulk.php` and `scheduledSend.php` over the web.
