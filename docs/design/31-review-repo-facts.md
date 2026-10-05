<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

Checks for items 1 to 8 are done. The biggest problems are in Phase 0: the repo is public, the history purge would miss GitHub's pull-request refs, and the old-host cleanup covers only one of four SiteGround sites.

## Findings

**F1. §2 D7, P0 "Recommend" and §8 Q12: "remote `tselwyn/chsPetPantry`; visibility is unknown"**
- **Verdict:** WRONG.
- **Evidence:** An unauthenticated call to `https://api.github.com/repos/tselwyn/chsPetPantry` returns `private=False, visibility=public, forks_count=0, created_at=2026-09-21`. `git -c credential.helper= ls-remote https://github.com/tselwyn/chsPetPantry.git` also works with no credentials. The site snapshot's `.git/config` (inside `public_html.zip`) points at `git@github.com:r-brt/FoodPantry.git`, and that repo is also public (API: `private=False`).
- **Correction:**
  - State that the repo is public and the secrets and staff PII have been public for 7 or more days.
  - Rotation becomes the day-1 P0 blocker.
  - Add "owner makes the repo private now (needs the user's OK)".
  - Raise the history purge from optional to strongly recommended, still needing an explicit OK.
  - Note that `r-brt/FoodPantry` is a separate public exposure that purging this repo cannot fix.

**F2. P0 purge: "Force-push main, dev, Heji, Rachelle and Selwyn … Everyone re-clones"**
- **Verdict:** RISKY (incomplete).
- **Evidence:** `git ls-remote origin` lists `refs/pull/1..6/head` pointing at ae308e4, 8f49b50, 8c0eccd, a543f11, 24d26f3 and aa4df7b. These PR refs are read-only on GitHub, so a force-push leaves the old commits reachable.
- **Correction:** Add a GitHub Support request to remove the PR refs and cached views after the force-push. Re-check the fork count at purge time (it is 0 today).

**F3. P0 "Old site (`jenniferp231.sg-host.com`)"**
- **Verdict:** RISKY (incomplete).
- **Evidence:** `php_errorlog` shows four SiteGround sites:

| Site | Path hits | Dates |
|---|---|---|
| jenniferp130 | 6,430 | Dec 2024 |
| jenniferp160 | 113 | Apr 2025 |
| jenniferp217 | 2,950 | Dec 2025 – Feb 2026 |
| jenniferp231 | 197 | Apr 2026 |

  `scheduledSend_errors.log` (154 hits) and `email/email_errors.log` only mention jenniferp217, so the scheduledSend cron ran there.
- **Correction:** List all four sites. Disable the cron on 217 and on 231. Confirm each site is decommissioned or locked down, and add this to the P0 exit criteria.

**F4. P0 interim deny `.htaccess` ("Foundation plan §a2 rules")**
- **Verdict:** RISKY.
- **Evidence:** `public_html.zip` contains `.git/` (1,164 entries, with branch `siteground`), which means the live host's `public_html` probably has a web-reachable `.git`.
- **Correction:** Explicitly delete or deny `/.git` on every old host. Add an exit check that `curl /.git/config` returns 403/404.

**F5. P0 "move the 40 remaining pages and their reference `database/` and `domain/` files" (only 6 db files kept); deletes 22 db files, `include/time.php` and `infoBox.php`**
- **Verdict:** RISKY. From Phase 0 the old app is simply dead: none of the 40 quarantined pages can run.
- **Evidence (includes that point at files deleted in P0):**
  - `header.php:12` → `dbShifts`.
  - The kept `dbPersons.php:17` → `dbinfo`; `dbPersons.php:1289/1415/1438` → `include/time.php`.
  - All 5 other kept db files → `dbinfo` (line 3 or 23).
  - `viewShoppingList` (P2 allotment source) → `dbShoppingCount`, `dbConsumption`, `dbShoppingEvent`, `dbShoppingCountGroup`.
  - `viewConsumptionRates` (US-36 source) → `dbConsumption`, `dbClient`, `dbDistribution`. The forecast logic itself lives in `dbConsumption.php` L164 `compute_current_consumption_rates_by_category` and L191 `…_by_shoppingEvent`.
  - `viewWeeklyReport` → `dbShoppingEvent`, `dbShoppingCount`, `dbConsumption`.
  - `event.php` (US-18 source) → `dbEvents`, `dbMessages`, `include/time.php:269`.
  - `calendar`, `addEvent` and `editEvent` → `dbEvents`, which itself includes `email.php` (deleted).
  - `index` → `dbApplications`, `dbMessages`.
  - `viewCheckInOut:28` → `infoBox.php`.
  - `processCheckIn` and `checkedInVolunteers` → `dbShifts`.
- **Why it matters:** This is acceptable only because `legacy/` is never deployed and there is no CHS production site. But the plan never says so, and the source logic for two stories is deleted.
- **Correction:**
  - State: "after P0 `legacy/` is read-only reference and not runnable; nothing runs until P1 staging."
  - Quarantine the full include closure: add `dbConsumption`, `dbShoppingCount`, `dbShoppingEvent`, `dbShoppingCountGroup`, `dbClient`, `dbDistribution`, `dbEvents`, `dbShifts`, `dbMessages`, `dbApplications` and their `domain/` classes.
  - Or tag a `legacy-baseline` before P0. `filter-repo` rewrites that tag, so keep a pre-purge mirror in the encrypted archive.

**F6. §6 "The 6 reference `database/` files and their `domain/` classes, `include/`, `universal.inc` and `lib/` are removed by the end of P2/P3"**
- **Verdict:** WRONG (the plan contradicts itself).
- **Evidence:**
  - The P7 sources (`generateReport`, `processInventoryReport`, `viewWeeklyReport`, `viewConsumptionRates`) stay in `legacy/` until P7 and need `dbInventoryEvent`, `dbItemCounts` and `dbItemCategory`.
  - The P1 "Delete from legacy/" list already removes `universal.inc`, while §6 says P2/P3.
- **Correction:** Delete each reference file when the last source that depends on it is deleted (P7 for the inventory db files), and use one phase for `universal.inc`.

**F7. P2B `station.css` "reuses `lib/bootstrap/css/bootstrap.min.css`"; §6 reuses `fonts/`**
- **Verdict:** RISKY.
- **Evidence:** P0 moves `lib/` and `fonts/` into `legacy/`, which is never deployed and which CI forbids including. §6 also removes `lib/` by P2/P3.
- **Correction:** In P1, copy `bootstrap.min.css` (header says v5.2.2) to `public/assets/vendor/bootstrap/`, and the fonts plus their licences to `public/assets/fonts/`. Drop `lib/bootstrap/img/glyphicons-*.png`, which are Bootstrap 2 leftovers.

**F8. R9 "libsodium is not loaded in XAMPP (verified: sodium=0…)"**
- **Verdict:** RISKY (the reason given is misleading).
- **Evidence:** It is true that sodium is not loaded. But `C:/xampp/php/ext/php_sodium.dll` and `C:/xampp/php/libsodium.dll` are both present, and `php.ini:958` is `;extension=sodium`. That is the same one-line enable as gd (931), intl (934) and zip (962).
- **Correction:** Reword to "sodium ships with XAMPP but is disabled; openssl GCM chosen for portability across hosts." The decision itself can stay.

**F9. P1 Composer section and dev tooling**
- **Verdict:** RISKY.
- **Evidence:**
  - `composer`, `node` and `gh` are all "command not found" on this machine.
  - The `composer.lock` entry for phpspreadsheet 5.6.0 requires ext-gd and ext-zip, and `php -m` shows neither is loaded, so `composer install` fails its platform check.
  - P0 deletes `package*.json` (Tailwind only), but P2B needs `node --test` with fake-indexeddb and §7 needs Playwright.
- **Correction:** Add a P1 dev-setup task:
  - install Composer 2 and Node LTS;
  - enable gd, zip and intl (and sodium if wanted) before running `composer install`;
  - create a new dev-only `package.json` with its lockfile.

**F10. P0 `.gitignore` gets `vendor/`; P1 "`vendor/` is no longer committed"**
- **Verdict:** RISKY.
- **Evidence:** `git ls-files vendor` shows 806 tracked files. Adding a path to `.gitignore` does not untrack files already in the repo.
- **Correction:** Add `git rm -r --cached vendor` to the step that adds the ignore rule. Also state that `vendor/`, `composer.json`, `composer.lock`, `README.md` and `LICENSE.txt` stay at the root during P0; neither list mentions them.

**F11. §3.1 "XAMPP: htdocs/chsPetPantry (vhost pfpms.localhost → public/)"**
- **Verdict:** WRONG for this machine.
- **Evidence:** The repo is at `C:/Users/maryw/Documents/Pelican/chsPetPantry`. `C:/xampp/htdocs` has no `chsPetPantry`, and `httpd-vhosts.conf` contains only the commented-out examples. mod_ssl, mod_rewrite and mod_headers are loaded (`httpd.conf` lines 120, 163, 177) and `AllowOverride All` is set on htdocs.
- **Correction:**
  - Point the vhost's DocumentRoot at `<repo>/public`, with a `<Directory>` block (`Require all granted`, `AllowOverride All`).
  - Add a TLS vhost with its own certificate for `pfpms.localhost`; §7's E2E tests use https.
  - The root `.htaccess` only matters if the repo is moved into htdocs.

**F12. P1 Composer versions: "phpunit ^11", "dompdf, chillerlan/php-qrcode" (not pinned)**
- **Verdict:** UNVERIFIABLE offline; reasoning from knowledge.
- **Evidence:**
  - PHPUnit 11 (PHP ≥8.2) should have left bug-fix support around Feb 2026. PHPUnit 12 needs PHP ≥8.3, but XAMPP is 8.2.4.
  - dompdf 3.x (PHP 7.1–8.4) and php-qrcode 5.x (PHP ^7.4 || ^8.0) are both 8.2-compatible.
  - None of dompdf, qrcode or phpunit is in `vendor/` today.
- **Correction:** Pin `dompdf/dompdf ^3.1` and `chillerlan/php-qrcode ^5.0`. Either keep `phpunit ^11` while the 8.2 floor stays, or raise the dev/CI floor to 8.3 (the plan already recommends 8.3+ for prod) and use `^12`.

**F13. §6 "`validateAndFilterPhoneNumber` L155 … ported as is"; `output.php` helpers "kept"**
- **Verdict:** RISKY.
- **Evidence:**
  - L156-158 strip all non-digits and then require exactly 10 digits, so an entry like "+1 843 …" (11 digits) is rejected.
  - `time24hTo12h` (L25, L28) and `formatPhoneNumber` (L34) return strings that `hsc()` has already escaped, so passing them through the new `e()` double-escapes.
- **Correction:** Move the phone validator to "fixed, then ported" (strip a leading 1). Port the output helpers without their internal escaping.

**F14. P1 "`.report-table`/`.modify-*` classes currently inlined in 18 pages"**
- **Verdict:** WRONG (minor).
- **Evidence:** grep finds 20 pages. 17 of them are quarantined; the other three (`forgotPassword`, `changeForgottenPassword`, `viewEditDeleteInventory`) are deleted in P0.
- **Correction:** Say 20, of which 17 survive P0.

## Confirmed
1. **Page counts:** there are 156 root `.php` files. The 110 + 6 = 116 delete list and the 40 quarantine list do not overlap, together cover every file, and name nothing that is missing. The 11/15/7/1/2/4 phase split adds up to 40, and 28 ADAPT + 12 REWRITE matches the analyst's 31/15. The 22 + `dbinfo` + 6 kept account for all 29 `database/*.php`.
2. **XAMPP:**
   - PHP 8.2.4 ZTS x64 and MariaDB 10.4.28.
   - `aes-256-gcm` and `PASSWORD_ARGON2ID` are both true.
   - openssl (3.0.8), pdo_mysql and mbstring are loaded; sodium, gd, zip and intl are not.
3. **SiteGround:**
   - The document root is `/home/customer/www/<domain>/public_html` (4 sites in `php_errorlog`).
   - The database is Percona 8.4.6-6 / 8.4.5-5, and PHP 8.2.x shows in the SQL dump headers and in `/usr/local/php82`.
   - The committed `vendor/` holds phpmailer v7.0.2 and phpspreadsheet 5.6.0 (`platform_check.php` requires PHP ≥8.2 on 64-bit).
4. **Git:**
   - Only local `main` exists; the remote has main, dev, Heji, Rachelle and Selwyn, the same 5 the plan names.
   - `public_html.zip` (23.3 MB), `sql/**/*.sql`, `database/dbinfo.php`, all 6 root logs, `php.ini` and `.DS_Store` are all in history.
   - The current tree has no `.gitignore`, `.gitattributes` or `.editorconfig`.
5. **Assets and legacy line numbers:**
   - `lib/bootstrap/css/bootstrap.min.css` (v5.2.2) and `fonts/` (Montserrat ×2, OpenDyslexic) exist.
   - These line references all match:
     - `input-validation.php` L12/16/52/77/86/109/117/127/135/147/155/163/167/176/184/188/207/211. L117 checks `$start` twice (at L118), L127's regex has no anchors, L167 defaults `$blankOkay=true`, and L176 rejects ZIP+4.
     - `output.php` L3/14/32/37/41/51 and `api.php` L3.
     - `reportsCompute.php` L115/295/447 and `reportsExport.php` L152.
     - `processInventoryReport.php` L9-10 (the PhpSpreadsheet `use` lines) and the XLSX block at L127.
     - `base.css` L70/72/74 (the CCDA palette).
     - `dbinfo.php:26-29` (the SiteGround branch), `forgotPassword.php:16-23`, `emailEncryption.php:3`, `foodpantrydb.sql:4198-4211`.
