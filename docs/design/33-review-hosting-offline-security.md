<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

# Skeptic review: hosting, offline PWA and security feasibility

The plan's Release 1 does not fit the team, and several hosting assumptions break on SiteGround or XAMPP. The most serious: the per-minute cron breaks SiteGround's fair-use rule, the plan's cache header would not stop SiteGround caching API responses, `.mjs` modules have no MIME type on XAMPP, and the E2E origin has an expired certificate. The offline design also has a data-loss path on failed unlocks and a push that can't authenticate after a long offline event.

Repo root is `C:/Users/maryw/Documents/Pelican/chsPetPantry`. Verdicts are WRONG, RISKY or UNVERIFIABLE; findings are ordered by severity.

## Findings

**1. Cron cadence** (§P1 Deploy: "`mail:send` every minute; `all` every 5 minutes"). **RISKY (policy breach)**
- SiteGround's Fair Use page says "at least 30 minutes difference between scheduled script executions". CPU limits are 1000/h and 10000/day on StartUp, 2000/20000 on GrowBig, with 768 MB per process.
- `cron_debug.log` and `test.log` show per-minute runs on jenniferp217 since 2025-12-10. So it is technically possible there, but it breaks policy.
- **Fix:**
  - Run `bin/cron.php all` every 30 minutes or more.
  - Send reset, invitation and voucher mail inline after commit, with the queue only as retry.
  - Drive P7 background imports from the admin's browser in chunks (a step endpoint polled by the page), or accept up to 30 minutes of queue delay.
  - State GrowBig as the minimum plan (see also finding 21).

**2. SiteGround Dynamic Cache** (R13; §7 "Response headers present: … `no-store`"). **RISKY**
- SiteGround's KB honours only `cache-control: no-cache`. A third-party write-up (dodov.dev) reports that for non-WordPress sites it caches everything, AJAX included, and only bypasses for WordPress/Drupal cookies, so `PHPSESSID` does not bypass it.
- `README.md:106,129`: the old site needed a manual Dynamic Cache clear.
- A re-enabled toggle could serve a cached `api/sync/pack.php` or participant page to another user.
- **Fix:**
  - `bootstrap.php` sends `Cache-Control: no-store, no-cache, private` on every PHP response.
  - §7 asserts `x-proxy-cache: BYPASS` or `MISS` on every `/api/*` endpoint and page on staging.
  - Keep the Site Tools toggle off as a second layer.

**3. `.mjs` modules** (§3.1 `station/js/*.mjs`, `station/vendor/idb.mjs`). **WRONG on XAMPP; UNVERIFIABLE on SiteGround**
- `grep mjs C:/xampp/apache/conf/mime.types` returns nothing (rc=1). Only `application/javascript js` exists (L145), and `webmanifest` is not mapped either.
- nginx `mime.types` only gained `mjs` in 2024 (freenginx). SiteGround NGINX serves static files itself and may ignore `.htaccess`.
- With `nosniff` set, module scripts sent with a missing or wrong type are blocked, so the Station would not load.
- **Fix:**
  - Use `.js` for all modules (`type="module"` works with it).
  - Name the manifest `manifest.json`, or add `AddType application/manifest+json .webmanifest`.
  - Add a staging check of the Content-Type on every precached asset.

**4. E2E against `https://pfpms.localhost`** (§7 E2E). **WRONG**
- XAMPP's `ssl.crt/server.crt` is self-signed, CN=localhost, expired 2019-11-09 (`openssl_x509_parse`). `httpd-ssl.conf:125` has `ServerName www.example.com`.
- I expect Chromium to refuse service-worker registration on an origin with certificate errors.
- **Fix:**
  - Run dev and Playwright on `http://pfpms.localhost`. The spec treats hosts ending in `.localhost` as potentially trustworthy, and Chromium and Firefox resolve them to loopback. Alternatively use mkcert.
  - The HTTPS-redirect exception in `public/.htaccess` must match `\.localhost$`, not just `localhost`.
  - Real tablets and iPads can only be tested on staging HTTPS.
  - Add a P1 exit criterion: "service worker registers on the staging hostname". HTTPS on an `sg-host.com` temporary domain is unverified.

**5. PIN switch versus a vault held only in memory** (R8, US-01 "switching … under five seconds").
- Verdict: **RISKY**.
- A PIN only works while the vault key (DVK) is in JS memory. Low-RAM Android tablets often kill the tab when switching apps (print dialog, camera). After a reload the device is hard-locked, so the next user needs a password plus PBKDF2 at 600k.
- Measured here: 600k PBKDF2-SHA256 takes 0.65 s (Python/OpenSSL) and 1.62 s (PHP) on this desktop. A low-end ARM tablet is plausibly 3–5× slower, before anyone types a 12-character password.
- The plan does not say how the PIN verifier is hashed. If it also uses the 600k KDF, every switch costs seconds.
- **Fix:**
  - PIN verifier = HMAC-SHA256(key derived from the DVK, user_id‖PIN), which is fast; the vault encryption is what protects it. Keep the failed-attempt counter inside the vault.
  - Optional setting: a non-extractable WebCrypto key kept in IndexedDB wraps the DVK for `pin_shift_hours`, so PIN switching survives a reload. It is deleted on hard lock.
  - Calibrate `offline_pbkdf2_iterations` per device at registration.
  - P2B exit: PIN switch under 5 s and password unlock at most 3 s on the slowest target tablet.

**6. Offline attribution strength** (R8 "Security first"; §3.4 "Every outbox payload carries an HMAC from the user's grant key"). **RISKY**
- PIN switching needs every user's PIN verifier and grant key to be reachable through the shared DVK. So any volunteer who can open the vault can brute-force a colleague's 10⁴–10⁶ PIN offline and sign items as them.
- This is the same weakness R8 uses to reject the Features plan.
- **Fix:** document the threat model. The HMAC proves device and grant; it is not non-repudiable between co-volunteers. Add server-side anomaly flags: the same user active on two devices, or PIN-session items outside that user's password-login window.

**7. Push authentication** (§3.4 sync pipeline; §3.5 allowlist excludes `api/sync/push.php`). **RISKY**
- `Api::start` needs a live session plus CSRF. After a 4-hour offline event the 30-minute idle session is gone, so push is blocked until someone logs in online.
- "Grant not revoked" is ambiguous: a tablet synced after 72 hours may have all its items Held.
- **Fix:**
  - `push.php` authenticates with the device credential (`device.token_hash` in an `Authorization` header, not a cookie, so CSRF does not apply) plus per-item HMAC, and is explicitly allowlisted.
  - Judge the grant's validity at the clamped `recorded_at_client`.
  - Revocation invalidates only items recorded after `revoked_at`.

**8. Wipe after failed unlocks** (§3.4 Wipe: "after too many failed unlocks"; the outbox is kept only for the TTL case). **WRONG against UC-06 §4.3 "without loss or duplication"**
- Ten wrong passwords would destroy distributions that have not synced.
- **Fix:**
  - This wipe deletes the pack, drafts and `vault_users`, but keeps the outbox ciphertext.
  - Add a device-authenticated "rescue push" that the server decrypts with its own DVK copy (R8 already keeps one).
  - Only Wipe-Now may discard the outbox.

**9. R6 Held paths contradict R6's own rationale** ("the ledger and eligibility must reflect food that physically left"). **RISKY**
- A Held distribution posts no ledger or eligibility change. That covers provisional-duplicate dependants, validation failures and erased references. This is exactly the double-serve window R6 is meant to close.
- Holding an erased participant's payload in `sync_item.payload_ciphertext` for 90 days brings erased personal data back into the database.
- **Fix:**
  - Commit provisional participants immediately as new participants with a 'Duplicate Candidate' alert, and resolve by merge. That means pulling a minimal UC-04 §3.2.4 merge into R1. Otherwise, Held lines must at least count in the local stock view and the eligibility check.
  - Erased references: commit against `anonymous_ref` with `sync_exception='Erased Participant'` and drop the personal data from the payload.

**10. R6 versus the spec** (Q5). **RISKY, adequate only if reworded**
- The spec (docx paragraph 690, UC-06 §3.2.5 step 3) says the system "re-applies the frequency and allotment checks, and reports any record that could not be applied". That implies failing records are not applied.
- Q5 only asks "commit-and-flag … can stock go negative".
- **Fix:**
  - Q5 quotes step 3, calls R6 a spec deviation, and asks for the spec text to be amended.
  - It explicitly covers households with blocking alerts that were served offline, and committed over-allotment.

**11. `GET_LOCK` naming** (§3.2 Cron; sync step 1). **RISKY**
- Lock names are global to the MySQL server. On shared SiteGround MySQL, a generic name can collide with another tenant's or with staging on the same server. MySQL also limits names to 64 characters.
- **Fix:** name = `'pfpms:'+DATABASE()+':'+key`, SHA-1 it if over 64 characters. Use timeout 0 and return 409 "sync in progress".

**12. Optional immutability triggers** (`optional/9001`). **RISKY for testing**
- On MySQL 8.4 with binary logging on, `CREATE TRIGGER` without SUPER fails with ERROR 1419 unless `log_bin_trust_function_creators=1`, so SiteGround is likely to refuse.
- CI in Docker runs as root, so "UPDATE fails with 9001 loaded" passes in CI while prod has no triggers.
- **Fix:**
  - CI migrates as a non-SUPER user granted only `ALL ON db.*`.
  - Immutability tests must pass with 9001 Skipped, relying on the app-layer guard and the CI grep.
  - Treat 9001 as a development safety net only.

**13. Session fixation** (§3.2 "hardened session"). **RISKY (gap)**
- The plan never mentions regenerating the session id.
- `C:/xampp/php/php.ini:1402` has `session.use_strict_mode=0`, and the legacy code never regenerates the id.
- **Fix:**
  - `SessionManager` sets `use_strict_mode=1`.
  - Call `session_regenerate_id(true)` on login, PIN switch, site switch, role change and policy acknowledgement.
  - Rotate the CSRF token on login, and add an integration test that the session id changes.

**14. CSRF details** (§3.2 `Csrf`; §7 PageContractTest). **RISKY**
- Safari before 16.4 does not send `Sec-Fetch-Site`, so the check needs an Origin fallback.
- Allowlisted public POST pages (login, forgot_password, clinic/portal, self_register) skip `Page::start`, but they still need `Csrf::verify()` (login CSRF).
- "An unauthenticated POST to every `public/*.php` writes nothing" is false for those pages, which write rate-limit and audit rows.
- **Fix:** state the rule for public POST pages explicitly and test them separately.

**15. Clinic portal brute force** (P5 "portal code … stored as a hash"; §3.2 "Tokens are stored as SHA-256 hashes"). **RISKY**
- Neither the portal code's nor the voucher number's length is specified. A short code hashed with SHA-256 can be cracked offline from a database leak.
- IP rate limits collapse into one bucket if `REMOTE_ADDR` is SiteGround's proxy address.
- **Fix:**
  - Portal login = clinic id + a code of at least 16 Crockford characters (80 bits), stored with `password_hash` (Argon2id).
  - Voucher numbers of at least 10 characters plus the check character.
  - Rate-limit per clinic and per IP, and notify an Admin on lockout.
  - P1 exit: on staging, `REMOTE_ADDR` is the real client IP, and `X-Forwarded-For` is ignored unless it comes from the proxy.

**16. Offline pack privacy** (P3: "phone as last 4 digits plus a hash"). **RISKY**
- The key for the 10-digit phone hash must be on the device for the JS/PHP parity fixtures to work. So anyone who can open the vault can recover the full phone number in seconds.
- **Fix:** drop the hash and search by last 4 digits plus name or code, or disclose this in the Q6/DPIA note. Ship alert type codes, not free-text alert content.

**17. iPad and Android storage** (P5 go-live "installed as PWAs with `storage.persist()`"). **RISKY**
- WebKit gives Home Screen apps storage isolated from Safari. The 7-day storage cap applies to Safari tabs; installed apps are exempt.
- **Fix:**
  - Device registration, login and "Prepare device" must happen inside the installed app.
  - On iOS, the Station refuses offline mode unless `display-mode: standalone`.
  - Require `navigator.storage.persisted()===true` before `offline_enabled`, and report it in the heartbeat and `admin_devices`.
  - Do the wipe in JS (`indexedDB.deleteDatabase`, `caches.delete`, unregister the service worker), with `Clear-Site-Data` as an extra layer only.

**18. BarcodeDetector** (P4 "`BarcodeDetector` or a keyboard wedge"). **RISKY**
- Available on Android, macOS and ChromeOS only. Not on Windows desktop Chrome, iOS Safari or Firefox. On Android it depends on Google Play Services (Amazon Fire tablets don't have it).
- The P3 participant-card QR scan has no fallback at all.
- **Fix:** feature-detect with `getSupportedFormats()`. Precache a WASM decoder such as zxing-wasm, or require a USB/Bluetooth scanner plus manual code entry. Add this to Q9.

**19. Service worker scope and headers** (R13, §3.4). **RISKY**
- "Precaches the shell only … never caches PHP pages", yet the shell is `station/index.php`.
- Root scope puts every admin page, `file.php` download and the clinic portal under the service worker.
- **Fix:**
  - Either use `/station/sw.php` with scope `/station/` (API fetches from controlled pages are still intercepted), or keep root scope with an explicit allowlist.
  - `sw.php` must send `Content-Type: text/javascript` explicitly; PHP's default text/html makes registration fail.
  - Precache with revisioned URLs or `cache:'reload'`, because SiteGround's static cache headers are unknown.

**20. Offline printing** (§7 walkthrough step 2 "Cut the network at the router"). **RISKY**
- Cutting the router leaves the Wi-Fi LAN up, so network printers still work in the test. A site with no Wi-Fi at all can't print from the browser to a network printer.
- **Fix:** also rehearse in airplane mode. Make receipt printing optional (on-screen receipt, reprint later) or use USB/Bluetooth. Add this to Q9.

**21. "SiteGround staging"** (P1, P2–P4). **RISKY**
- SiteGround's Staging tool is WordPress-only and needs GrowBig or higher.
- **Fix:** staging is a separate site or subdomain (`staging.<domain>`) with its own database, config, cron and certificate. On a one-site StartUp plan that is not possible, so name GrowBig as the minimum.

**22. P7 import scale** ("25 MB / 50k rows in ≤10 min" with PhpSpreadsheet). **RISKY**
- PhpSpreadsheet loads the whole workbook. At roughly 1 KB per cell (my estimate), 50k rows × 20 columns is about 1 GB, over SiteGround's 768 MB per process and XAMPP's `memory_limit=512M` (`php.ini:433`).
- A 10-minute run could use up to 600 CPU-seconds, 60% of StartUp's hourly budget.
- **Fix:** use read-filter chunking or streaming XLSX (OpenSpout), stream CSV, and assert peak memory in the staging test.

**23. Effort** (§4: "divide by team size"). **WRONG as a plan for one release**
- R1 (P0–P5) is 203–261 dev-days by the plan's own table.
- `git log` shows 4–5 authors, starting 2026-09-21. At about 10 h per student per week, the remaining ~11 weeks of the semester give roughly 55–75 dev-days, so R1 is 3–4 semesters. My assumption: 6 productive hours per dev-day.
- "Divide by team size" ignores that P1 → P2B → P3 Station → P4 is mostly serial work.
- **Cut line** (keeps all 6 Musts — US-01, 03, 18, 22, 26, 32 — and offline distribution in R1):

| Cut from R1 | Saving (dev-days) |
|---|---|
| **Offline scope:** R1 offline = UC-01 §3.3.3 as written (distribution only) plus offline check-in and cached search. Offline registration and provisional ids, offline pet edits and the sync_review link/dependant chains move to R1.1 (paper slip, then a late entry). | 15–20 |
| **Offline overrides:** `authorization_ref` plus a flag only; no co-sign on the tablet. | 3 |
| **Config screens:** languages, species, breeds, lookups, service area, policies, intake questions and the clinic directory become client-reviewed seed files loaded by `bin/seed.php`. Keep sites, devices, users, settings and allotment publishing. | 15–20 |
| **P4:** defer US-17 substitutes and US-16 camera scanning/linking; use a wedge scanner or manual pick. | 5 |
| **P5:** keep only the minimal UC-08 that US-22 needs (referral, voucher, budget lock, English print, portal). Defer US-23 Spanish and its fallback, reimbursement, transport list, next-nearest clinic, reminders and the emailed PDF. US-32 keeps template, dry run and sync import up to 5k rows. | 6–8 |
| **P1:** CI on PHP 8.3 × {MariaDB 10.4, MySQL 8.4}; drop the Percona leg and 9001. | 5–8 |

- That brings R1 to about 155–195 dev-days, still more than two semesters.
- The plan must state the team's capacity and a calendar, and define R1 as a pilot at one site.

## Claims tried and confirmed
- R9: XAMPP PHP 8.2.4 reports `sodium=0`, `aes-256-gcm=1`, `argon2id=1` (checked with `php -r`).
- R1 layout: the SiteGround path `/home/customer/www/<domain>/public_html` appears in `php_errorlog` (jenniferp130, 160, 217, 231), so `public/` as document root with `src/` beside it is feasible.
- SSH is on all SiteGround plans (SSH Keys Manager). SiteGround staff said in 2018 that rsync is pre-installed, so the SSH/rsync deploy is feasible; the current state should be checked on the account.
- R13: `sw.php` at the root needs no `Service-Worker-Allowed`, and SiteGround honours `Cache-Control: no-cache`.
- Q10: a change of production origin wipes every device's storage, so the domain must be fixed before rollout. Correct.

Sources:
- [SiteGround Fair Use](https://www.siteground.com/kb/fair-use-siteground-hosting)
- [SiteGround Dynamic Caching](https://www.siteground.com/kb/siteground-dynamic-caching-configuration)
- [dodov: SiteGround cache for non-WordPress sites](https://dodov.dev/blog/fixing-siteground-dynamic-cache-issues)
- [SiteGround SSH](https://www.siteground.com/blog/siteground-launches-advanced-ssh-feature)
- [SiteGround Staging tutorial](https://www.siteground.com/tutorials/staging)
- [freenginx: mjs added to mime.types](https://freenginx.org/pipermail/nginx-devel/2024-August/000496.html)
- [MySQL error 1419](https://support.ispirer.com/knowledge-base/database-migration/setup-and-troubleshooting/mysql/error-1419)
- [MDN Secure Contexts](https://developer.mozilla.org/en-US/docs/Web/Security/Secure_Contexts)
- [WebKit storage policy](https://webkit.org/blog/14403/updates-to-storage-policy/)
- [BarcodeDetector platform support](https://github.com/mdn/browser-compat-data/issues/9030)
- [caniuse BarcodeDetector](https://caniuse.com/mdn-api_barcodedetector)
