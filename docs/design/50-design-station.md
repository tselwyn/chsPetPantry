# Phase 2B design (merged): the offline platform and the Station PWA

**Repo:** `C:/Users/maryw/Documents/Pelican/chsPetPantry`, branch `pfpms/phase-0` at `5814f9b` (P0, P1, P2A built). Nothing in the repo was changed or run.
**Inputs:** `scratchpad/p2b/brief.md` (REQ-01..121, C-01..46, D-01..59), the six reader notes, the three designs (`design_mvp.md`, `design_security.md`, `design_operations.md`), the two judges' verdicts, plan §3/§4/P2A/P2B, `docs/design/40-design-devices.md` §0a/§14, and the code. Every code claim below was re-read in the source at `5814f9b`.
**Status:** adopted for Phase 2B on 2026-09-30. S1 (§12.1) is built; small differences in the build are listed in the plan's P2B bullets. The brief, reader notes and three source designs named below were working files and are not kept in the repo; everything they settled is in this document.

**Verified in the scratchpad** (throwaway scripts in `scratchpad/p2b/final/`, Node 24.21.0 WebCrypto against XAMPP PHP 8.2.4):
- HKDF-SHA256 with an empty salt, AES-256-GCM (WebCrypto `ct‖tag`, IV kept apart), PBKDF2-SHA256 and HMAC-SHA256 give identical bytes on both sides. PHP opens the tablet's outbox record, refuses a changed AAD, re-encodes the canonical item byte for byte, and gets the same item MAC, PIN verifier, keyring unwrap and device-proof MAC. Every value in §3.9 comes from these runs.
- `json_encode` equals `JSON.stringify` only with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS` (without the last flag PHP escapes U+2028). `json_decode(…, false)` keeps `{}` apart from `[]` and keeps the last duplicate key, so a re-encode refuses duplicates.
- `mb_strtolower('ΣΑΣ')` is `σασ` on PHP 8.2.4, and JS `'ΣΑΣ'.toLowerCase()` is `σας`. Lower-casing per code point, then mapping ς→σ, gives `σασ` on both. The accent fold and American Soundex below agree on all twenty fixture names in both languages (`final/sx.mjs`, `final/sx2.php`).
- `hash_hkdf` and `Normalizer` (intl) are loaded; sodium is not.
- The `Audit::REDACT` probe passes every audit detail key this design uses (§6).

People-facing copy says "tablet"; code, tables and JSON say "device".

---

## How this design was put together

### The base, and why
| Design | Judge 1 total | Judge 2 total | Sum | Chosen as base by |
|---|---|---|---|---|
| `design_mvp.md` | 44 | 42.5 | 86.5 | Judge 1 |
| `design_security.md` | 39 | 46.5 | 85.5 | Judge 2 |
| `design_operations.md` | 45 | 44 | 89 | neither |

**The base is `design_mvp.md`.** The judges disagreed (judge 1 chose MVP, judge 2 chose Security). This merge sides with judge 1, for these reasons:
- **It is buildable on the critical path.** It is the most internally consistent design. Its cross-language formats are pinned by vectors both judges and this merge reproduced. Its S1 has complete endpoint source and exact SQL.
- **It has one migration that can still be settled in full before it is applied.** A migration is immutable once applied (`Migrator.php:87-92`), so settling 0013 now avoids the trap.
- **It introduces the fewest new concepts** for a student team with 25-30 days.
- **Its defects are local.** Each has a small fix (listed below).

Judge 2's case for Security rests on five properties. This merge keeps four of them with smaller mechanisms and declines one:

| Security property (judge 2) | How this merge gets it | Cost compared with Security |
|---|---|---|
| A copied credential cannot confirm a wipe, sign in or PIN-switch | A **device proof key**: a non-extractable HMAC key made on the tablet at registration. It is required on the wipe confirmation and on every call that opens a session or releases keys (sign-in, PIN, the in-app acknowledgement and password change), and checked on every other device call to flag clones (§3.1, X-1). It stops a script-level copy (devtools); a file-level copy of the browser profile carries the key too (§9). | HMAC instead of ECDSA: no P1363→DER conversion. One column. |
| The PIN survives an Android tab kill | The plan's optional pin_shift key (plan:117), in S4 (D-26) | The plan's form. Security's PBKDF2-mixed PIN KEK is recorded as a later hardening. |
| A clock set back cannot extend a grant | High-water clock on the tablet (§7.4) plus the signed per-item offset and the seq floor at the server (§8.5). Local time can still be set back to the last time the Station ran (§9). | Same |
| Skew cannot be forged with the credential alone | `clock_offset_ms` sits inside the HMAC-covered bytes (§3.6) | Same |
| A vault opener cannot read colleagues' grant keys or PINs | **Not adopted.** Per-user sealed bundles and a tablet pepper would restructure the vault. They add a PIN prompt after every password unlock and 1 s per PIN check. The plan accepts this limit (plan:122, deviation 7), so it is put to the client (Q6). | Declined |

Operations has the highest summed score. Neither judge chose it as base:
- its scope is very large;
- it deviates from 40 §14.1/§14.3 (the tablet proposes its credential);
- its security is the weakest of the three;
- it has restart and idempotency bugs.

Its operational strengths are grafted piece by piece.

### Grafts
| From | What | Where here |
|---|---|---|
| Operations §7.8 / Security V-7 | Resumable wipe state machine (`meta.wipe`). This merge takes the V-7 form: every store except `meta` is cleared, the confirmation is sent, and only then is the database deleted. This keeps one database instead of a second scratch database. | §7.6, X-4 |
| Operations D-55 | Both crons in P2B: `devices:clear-unconfirmed-wipes` (S1) and `sync:purge-payloads` (S5) | D-55, §12 |
| Operations §8.4 step 12 | Explicit `ACTOR_INVALID` at the organisation-local date of the clamped time | §8.3 step 13 |
| Operations §8.4 step 14 | A missing dependency is Held `DEPENDS_ON_MISSING`, never retried for ever | §8.3 step 15 |
| Judge 2 (missing everywhere) | Client-side dependency gating: an item is sent only after the item it depends on | §8.1 |
| Operations §6.5 / Security D-16 | Sign-in and PIN success transactions start with `DeviceRepository::lockShared()` and a trust re-check | §6.5, §6.6 |
| Operations §3.7 | ς→σ after lower-casing, in PHP and JS | §11.3 |
| Operations D-05 | Debounced `POST api/session.php {action:'touch'}` keep-alive | D-05, §6.2 |
| Operations §6.1, §5.2 | `authorization_received` in ping, and the `device_header_missing` Administrator alert | §5.4, §6.1 |
| Security §5.5 | `bin/station-smoke.php` staging gate | §5.4, §12 |
| Operations §5.7 | `heartbeat:device:<id>` bucket. Offline failures reported grouped, in one audit row per heartbeat | §5.6, §6.4 |
| Operations D-52 (+ judges' fix) | Separate Station cookie `PFPMSST`, with `WebSession::restart()`/`destroy()` keyed on the current session name | D-52, §5.5 |
| Operations D-42 | Save locally first, then show the server's answer if it arrives within 1.5 s. The fetch is never aborted. | D-42, §8.1 |
| Operations §7.6 | Boot watchdog (15 s) and an in-app **Repair this app** button, next to the kill switch | §7.5 |
| Operations §12.1 | Dev seed re-keyed to known `pfd1_` strings | D-01, §12.1 |
| Operations §12.1 | `redemptionAvailable()` needs both `register.php` and `station/index.php` | §12.1 |
| Security §5.7 | `testNothingSendsCorsHeaders`, `testApiEndpointsNeverTestIsPost`, `header_remove('X-Powered-By')` | §5.8 |
| Security §7.8 | High-water clock on the tablet | §7.4 |
| Security §6.4 | The wipe confirmation also shreds the tablet's grant secrets | §6.4 |
| Operations §4.1 | `device.display_mode`, `clock_skew_seconds`, `attention_count`, `locked_out_since`, `sync_item.recorded_at_raw`, and `'User Switch'`, folded into the single 0013 | §4 |
| Operations D-19 | Grant also capped by `password_changed_at + password_max_age_days` | D-19 |
| Operations §7.5 | Verified precache: SHA-256 and content type per file, so a mismatch fails the install | §7.5 |
| Operations §7.8 | Push Then Wipe stops while an item is in `conflict`/`invalid` (the `stuck_conflict` banner) | §7.6 |
| Operations §8.5 | Signed per-item `clock_offset_ms`, batch skew as fallback, seq floor | §3.6, §8.5 |
| Operations §6.10 | In-app forced password change: Tx1 `Auth::setPassword`, then Tx2 the grant | §6.9 |
| Operations §5.7 | Push bucket and `too_many_bad_records` | §5.6 |
| Operations D-39 | Real `station_check` kind | §8.10 |
| Judge 2 (§6.3 variant) | Replay-safe registration that keeps the server-made credential of 40 §14.3 | §6.3 |
| Security §3.9 (variant) | Time-bound proof on the wipe confirmation, here as an HMAC | §3.1, §6.4 |
| Operations/Security D-22 | Server PIN pepper (`p1.<kid>.` prefix) | D-22 |
| Judge 2 (override D-20) | Grants are **superseded, not revoked**, when a new one is issued | D-20 |
| Judge 2 (override D-30) | Progressive delay on offline unlock failures; only real keyring entries count; drafts kept | D-30 |
| Judge 2 (missing everywhere) | Clone detection: an unproven device call, or a `max_seq` going backwards, alerts Administrators | X-5 |
| Judge 1 (missing everywhere) | Administrator fallback for notices about a tablet whose site is inactive | D-49 |
| Judge 1 (missing everywhere) | `device.shift_ended_at` closes the server-side PIN window at End shift | D-24 |
| Judge 1 (missing everywhere) | One primary Station window per tablet (Web Locks) | X-2 |
| Judge 1 (missing everywhere) | `.htaccess` rules exercised on local XAMPP Apache before staging | §11.5 |

### Factual errors the judges found, re-checked, and how they are corrected
| # | Design | Claim | Re-checked against | Correction here |
|---|---|---|---|---|
| 1 | MVP | Capping PIN digits at 6 leaves `SettingsServiceTest` unaffected | `tests/Integration/Reference/SettingsServiceTest.php:197` asserts `update(['pin_min_digits' => '8', 'pin_max_digits' => '8'])` succeeds, and `:186` refuses `update(['pin_min_digits' => '8'])` through the min ≤ max rule: **confirmed** | S3 edits `:197` to `'5'`/`'5'` (both still change: 4→5 and 6→5) and `:186` to `update(['pin_min_digits' => '6', 'pin_max_digits' => '5'])`, which stays inside the new range and still hits the min ≤ max rule (same error key) |
| 2 | MVP | `mb_strtolower` per code point gives σασ like JS | PHP 8.2.4 gives `σασ`, JS whole-string gives `σας` (**confirmed**). PHP 8.3 (CI leg, `ci.yml:40`) applies final-sigma casing per its UPGRADING notes; only 8.2.4 is installed here | PHP lower-cases per character (`mb_str_split` then `mb_strtolower`), and **both sides then map ς→σ** (§11.3) |
| 3 | MVP | `ACTOR_INVALID` is subsumed by `GRANT_NOT_VALID` | `AccountService::deactivate` revokes and ends sessions only `if ($now)`; a future date waits for `DeactivateDueAccounts`: **confirmed** | Explicit `ACTOR_INVALID` at the clamped time (§8.3). Setting a future deactivation date also revokes live grants now (§12.3). |
| 4 | MVP | D-01 "seed rows cannot authenticate" against D-46 "they can sign in and PIN" | `seeds/dev/004_devices.sql:11,23` store `SHA2('pfpms-dev-device:'+label)`, which fails `FORMAT`: **confirmed** | The seed is re-keyed to known `pfd1_` strings (§12.1). Seed tablets have no vault key and no proof key: they **sign in online only**, cannot PIN (no grant ⇒ no PIN window) and cannot record (D-46). |
| 5 | MVP | A clock **moved forward** extends offline unlock | §7.4 of MVP: `now + offset`. Moving the clock **back** extends it | §9 says "back". The high-water clock (§7.4) narrows the residual to the time the Station was closed: local time can be set back to the last time it ran, not further (§9, Q6). |
| 6 | MVP | S1 covers REQ-15; REQ-13 flips by `register.php` alone | `DeviceService.php:349-352` checks only `register.php`; `last_sync_at` is written by push: **confirmed** | REQ-15 is in S5. `redemptionAvailable()` needs `station/index.php` too, so it flips in S2. |
| 7 | Security | Proof makes sign-in and PIN require the signature | Design-internal: step 9 is after the anonymous return (step 5) | Not carried over. Here `DeviceGuard::authenticate()` verifies the proof straight after the device lookup, **before any session** (§5.1 step 4). |
| 8 | Security | The pipeline suits public Station endpoints | `Page::start` returns a public Context **before** the gates (`Page.php:41-43`): **confirmed** | `Api::start` returns public Contexts before the gates (§5.1 step 11), so a previous user's pending gate never blocks the next sign-in. |
| 9 | Security | `session.php` verifies CSRF | Design-internal | `session.php` calls `Csrf::verify()` on POST. `testPublicApiPostsVerifyCsrf` (§5.8) enforces it. |
| 10 | Security | `restart()` works with a second cookie | `WebSession::restart()` = `destroy()` + `start()`; `start()` hard-codes `session_name(self::COOKIE)` and `destroy()` clears `self::COOKIE` (`WebSession.php:37, :71, :77-82`): **confirmed** | `start(bool $station)` records the choice; `restart()` reuses it; `destroy()` clears `session_name()` (§12.1) |
| 11 | Security | Every detail key survives REDACT | `grant_secrets_cleared` matches `secret` (`Audit.php:20`): **confirmed** | This design uses `grant_keys_cleared` (§6.4, §12.5). All keys probed. |
| 12 | Security | `app_path` = `Request::appPath()` without a query | `appPath()` keeps the query and falls back to `'index.php'` (`Request.php:140-146`): **confirmed** | The proof signs `Request::scriptPath()`, a new method: the entry point's path with no query (§5.3) |
| 13 | Security | S1 covers REQ-14; S1 file list complete | Design-internal | REQ-14 is MVP's `seq_gap` warning (§12.1 `DeviceStatus`). The S1 file list here is complete. |
| 14 | Security | Seed tablets "stay online-only" | Proof needed but no key | The proof is required only when the row **has** a proof key (every P2B-registered tablet has one), so seed rows sign in (§5.1) |
| 15 | Operations | `restartKeepingCsrf` keeps the Station's token | Same as #10 | `restart()` fixed. A CSRF failure after a restart is handled by `api.js`'s one refetch-and-retry (§7.2). |
| 16 | Operations | Idempotency on SHA-256(s) before decrypting | Design-internal | MVP order: decrypt and hash **outside** the transaction, compare under the row lock (§8.3) |
| 17 | Operations | End shift closes the PIN window via a `'Device Lock'` session | `endAllForDevice` updates only `ended_at IS NULL` rows (`SessionStore.php:106-111`): **confirmed** | `device.shift_ended_at` is written at End shift whatever sessions are open, and the PIN window requires a grant newer than it (D-24) |
| 18 | Operations | One build call site | `DeviceStatus.php:31` says ping returns `currentBuild()`: **confirmed** | Every endpoint and `sw.php` calls `DeviceStatus::currentBuild()` (§5.7) |
| 19 | Operations | D-51 FK columns NULL on durable rows | Design-internal | No device-authenticated path calls `Audit::durable()`. Refusals use `Audit::record()` with no transaction open (D-51). |
| 20 | Security, Operations | After Retire, later items are Held `DEVICE_REVOKED` | Retire → `endAccess` → `Tokens::revokeForDevice` revokes the grants at the retirement instant (`DeviceService.php` `endAccess`; `Tokens.php:83-93`): **confirmed** | MVP order: suspect rule, then Retire rule, **then** grant validity (§8.3 steps 9-12) |
| 21 | Security | `session_close` sets the true end reason | `ExpireSessions` writes `'Timeout'` with `ended_by` NULL (`ExpireSessions.php:31-37`): **confirmed** | The end item overrides `(ended_at IS NULL OR (end_reason = 'Timeout' AND ended_by IS NULL))` (§8.10) |
| 22 | Security | Push needs no limit | Design-internal | `sync_push:device:<id>` bucket plus `too_many_bad_records` (§5.6) |
| 23 | Security, MVP | Push Then Wipe clears conflicts and always finishes | Design-internal (REQ-73) | `conflict`/`invalid` items stop the Retire wipe (`stuck_conflict`); only Wipe Now discards them (§7.6) |
| 24 | MVP | Server-side expiry of an active session is exceptional | `validate()` touches only on session requests (`SessionStore.php:79-82`); MVP's writes are device-only: **confirmed** | Debounced touch (D-05) |
| 25 | MVP, Operations | A credential-only insider can do no more than spoof figures | A credential alone is accepted for `wiped: true` (40 §14.4 as designed) | The wipe confirmation needs a valid time-bound proof (§6.4). A 410 still erases locally, because a clone cannot confirm. |
| 26 | Operations | The online PIN response carries keys | `Csrf::verifyOrigin` accepts requests with neither Origin nor `Sec-Fetch-Site` (`Csrf.php:44-54`): **confirmed** | An online PIN switch releases **no keys** (as MVP). It also needs the proof. |
| 27 | Operations | `session_id` attaches when "that row exists" | Design-internal | A session is attached only when its row has `user_id = recorded_by` and `device_id` = this tablet. A clashing `offline_session` start is Held `SESSION_CONFLICT` (§8.3 step 16). |

### Where the judges disagreed, and what this merge decides
- **Base:** MVP (above).
- **Proof key.** Judge 1 grafts no proof key; judge 2 wants Security's ECDSA key.
  - Decision: an HMAC proof key. It closes judge 2's worst finding (#25: a forged wipe confirmation makes a retiring tablet erase unsent records) and enables clone detection.
  - It costs one encrypted column and about 40 lines each side, with no DER handling.
- **pin_shift key.** Judge 1 makes it an S4 stretch in the plain-wrap form; judge 2 wants Security's PBKDF2-mixed form.
  - Decision: **in S4 as committed scope**, in the plan's form. Without it, US-01's "<5 s, open entry kept" fails after every Android tab kill.
  - Its "UI control" nature is the plan's own documented trade-off (REQ-63). The PBKDF2-mixed form is recorded for a later release.
- **Named push lock.** MVP has none; Security and Operations take `sync:device:<id>`. Neither judge objected either way.
  - Decision: **no named lock** (MVP D-37). With one primary window (X-2), per-item row locks and idempotency, a second batch is harmless, and there is one concept fewer.
- **`calc.js`.** Operations defers it to P4; MVP and Security port it in P2B; the judges did not object.
  - Decision: **S2** (the plan's P2B file list, and the fixture exists).
- **Phonetic key.** Operations defers it; judge 2 grafts MVP's Soundex.
  - Decision: **Soundex in S2** (plan:254 lists it for P2B).
- **Grant rotation.** Judge 2 overrides D-20 and judge 1 does not discuss it.
  - Decision: supersede. A committed sign-in whose response is lost must not turn a whole offline shift into Held items.
- **Failed-unlock wipe.** Judge 2's override (progressive delay, count real entries only, keep sealed drafts) is adopted. Keeping drafts is recorded as a deviation from plan:119, and it serves UC-01 §4.2 "an entry is never discarded".

### "Missing everywhere" items, and where this design covers them
| Item (judge) | Covered by |
|---|---|
| Notices for a tablet at an inactive site reach nobody (J1) | D-49/D-50: every per-tablet Coordinator notice also goes to Administrators (site NULL) when the tablet's site is inactive, as `DeviceService`'s `$told` pattern does |
| End shift does not close the server PIN window without open sessions (J1) | D-24: `device.shift_ended_at`. An offline End shift is replayed to the server at the next contact (§7.3 `pending_shift_end`). |
| Two windows or tabs of the Station (J1) | X-2: one primary window per tablet by Web Locks, with **Use this window here** (`steal: true`). The other window drops its keys. |
| `.htaccess` never exercised before staging (J1) | §11.5: with the user's approval, one XAMPP `Alias` to `public/`, then `bin/station-smoke.php` and the E2E subset run against local Apache (cache rule, MIME, nosniff, subdirectory base path) |
| Lost sign-in response vs grant rotation (J2) | D-20: supersede, not revoke |
| Failed-unlock wipe as a bystander DoS (J2) | D-30: progressive delay, real entries only, drafts kept |
| No detection of a cloned credential (J2) | X-5: an unproven device call and a backwards `max_seq` raise `device_clone_suspected` |
| No client-side dependency gating (J2) | §8.1: `outbox.due()` holds a dependant back until its dependency is acknowledged or earlier in the same batch |

## Changes after the S1 build review
A five-lens review of the built S1 (security, design conformance, regressions, platform, tests), with an adversarial verifier per lens, confirmed 19 findings; all are fixed in the code and recorded here:
- **Heartbeat budgets.** Proven and unproven heartbeats have separate rate-limit buckets, a stale proof counts as unproven, and a 429 for a revoked tablet still carries its directive (§5.6). Only proven heartbeats may raise `reported_max_seq` or raise the seq-went-back signal.
- **Audit of tablet reports.** `device_state`, `device_unlock_wipe`, `device_clock_rollback` and `offline_auth_failures` rows carry `proof` (the guard's verdict), so a report sent with a copied credential can be told apart. Ignored wipe claims are audited once an hour per tablet.
- **Heartbeat audit accuracy.** The "before" row of `device_state` is read under the row lock, and a lockout reported again within 2 seconds keeps its stored time (the skew correction jitters by a second), so neither a concurrent Retire nor jitter is recorded as the tablet's change.
- **Missing-header alert.** Administrators are alerted only when no tablet in service has authenticated in the last 15 minutes (an anonymous request cannot raise a false hosting alert while tablets work); the audit row is always written, once an hour.
- **Unconfirmed wipes.** The cron keeps the proof key (D-55), so a tablet found after the retention period can still confirm its erase.
- **Base path.** `Request::basePath()` also finds a `public_html/` web root (the SiteGround layout); outside dev/test a web request refuses to run when the base path is neither found nor configured; ping reports `script_path`, and the smoke script checks it, the Station cookie path and an error's `Allow` header (§5.4, §6.1).
- **Errors.** A `JsonException` from the server's own encoding is a logged 500, not a client 400 (D-03). `codeFor()` is a generic code per status (§5.2).
- **Index.** 0013 adds `ix_sync_item_4 (device_id, recorded_by)` for the device pages' `received_count`.
- **Defence in depth.** `#[\SensitiveParameter]` on the proof header and on `Crypto::encrypt()`'s plaintext.
- **Tests.** The no-touch path and the tablet-session rules through `Api::start`, `SessionInfo`, the error headers and the 500 path through `ErrorHandler::handle()`, `redemptionAvailableAt()` in all four cases, and the heartbeat tests independent of the dev-relax flag.

## Changes after the fact-check
Four lenses (repo, crypto, platform, requirements) checked this design. Every finding was re-verified against the code at `5814f9b` or by a throwaway run: PHP 8.2.4 `base64_decode(…, true)`, Node 24 WebCrypto HMAC key length and the keyring hashes, and in the desktop app's browser pane (Chromium 152, against a scratch `php -S`) service-worker registration, `Clear-Site-Data` with `credentials: 'omit'` and `'same-origin'`, and a blocked `deleteDatabase`. All 37 were real; duplicates share one fix. One line each:
- **F-01** `api.js` sends `Content-Type: application/json` on every request with a body, device-only included (§7.2, §6, D-38; `api.test.js`, `heartbeat_body.test.js`).
- **F-02** Booleans are bound as `1`/`0`; `pending_count` null keeps the stored value and `storage_persisted` defaults to false (§12.1 `DeviceRepository`, §6.4; a heartbeat test).
- **F-03** E2E A3 expects "Works online only" plus the storage warning, with `offline_enabled = 1` shown by SQL (D-12, §11.4).
- **F-04** The settings test lines are `SettingsServiceTest.php:197` (5/5) and `:186` (now a 6 > 5 min ≤ max case) everywhere (§4.3, factual error #1, §1.3, §11.1, §12.3).
- **F-05** `describe()` reads every new key with `?? null`, `DeviceStatusTest::row()` gains them, and both device pages pass `clockTolerance` (§12.1).
- **F-06** `Api::start` throws `LogicException` for `'session' => false` without `device` or `public` (§5.1 step 0), plus a contract test (§5.8).
- **F-07** Heartbeat times are parsed with `Clock::fromClient()`, corrected by the skew, capped at now and bound as `Clock::db()` (§6.4, §12.1).
- **F-08** The seq-back signal calls `noteUnproven($device, Request::scriptPath(), 'seq_went_back')` (§6.4).
- **F-09, SEC-09** A strict `Crypto::unb64urlStrict()` decodes every tablet-sent base64url value; the built `unb64url()` is documented as lenient (`Crypto.php:67-74`, not 209-216); `openRaw` keeps its fixed 16-byte tag, with a short-tag test (§3.2, §6.3, §8.3, §12.1, §11.1).
- **F-10** plan:651 becomes `0002–0013` and the changes-log verification line gains 0013 (§4.3, §12.1, §13.2).
- **SEC-01, RQ-01** The seq floor and `received_count` read only authenticated rows (`recorded_by IS NOT NULL`) (D-35, §8.3, §8.5, §12.1, §12.5; `testUnauthenticatedHeldRowsNeverRaiseTheSeqFloor`).
- **SEC-02** Each online PIN attempt is reserved by an atomic conditional increment before `Pin::verify()` (D-25, §2.3, §6.6, §12.3; two tests with a test-only hook).
- **SEC-03** `ACTOR_INVALID` uses `deactivation_effective_date < D`; the deactivation day is judged by grant validity, which knows the instant (§8.3 step 13; `testItemsRecordedBeforeASameDayDeactivationAreAccepted`). The finding's "moving `expiry_date` into the past is retroactive" part was not applied: that rule compares the item's own date, so only items made after the new expiry are held, which is intended.
- **SEC-04, RQ-12** The keyring lookup trims exactly as `Auth.php:42` does before NFC and lower-casing, and stores a third lookup for the identifier as typed (D-29, §3.2, §3.4, §3.9, §11.2, §11.3).
- **SEC-05, BH-08** The proof key is made with `length: 256` (the default is 64 bytes), and a test checks 32 bytes (X-1, §7.2, §11.2).
- **SEC-06** `policy.php` and `password.php` require the proof, like every call that releases keys (X-1, §0.1, bet 4, §2.1, §6.9, §6.10, §9, §13.1; two tests).
- **SEC-07** A genuine item supersedes a same-tablet row with its uuid that never authenticated; authenticated rows are never replaced (§8.3 step 7 and 18, §8.4, §12.5; three tests).
- **SEC-08** The wording is now "setting the clock back cannot move local time earlier than the last time the Station ran", and the residual is in §9, the threat note and Q6. The optional server-time floor was not adopted: it cannot close the gap while the app is closed.
- **BH-01** Only backward clock jumps are re-based (a forward jump looks like sleep), and idle uses the larger of the monotonic and `serverNow()` elapsed time, checked before the view is shown on wake (§7.4, §7.5, §8.5, §10; `fake-env.js`, `clock.test.js`, `timers.test.js`, a SyncServiceTest).
- **BH-02** §11.4 is split: Part A in the pane (no service worker there) and Part B in a real Chrome profile for install, reload offline (plan:256), Repair, update and kill switch; the slice verify lists, §12.6 and risk 12 follow. The reader note `map_device_tooling.md` §2.6, which recommended the pane for service-worker checks, is superseded by §11.4.
- **BH-03** The wipe confirmation (and every device call during a wipe) is sent with `credentials: 'same-origin'` so `Clear-Site-Data` applies; `device.js` treats a closed database after the reply as "already erased"; other 410s erase locally (§2.1, §2.3, §5.2, §6.4, §7.2, §7.6).
- **BH-04** Every IndexedDB connection closes itself on `versionchange`; `destroy()` closes first, then deletes, and retries on `blocked` with a message; E2E A13 runs the erase with a second window open (§7.2, §7.3, §7.6).
- **BH-05** `controllerchange` reloads only when the page had a controller and itself posted `SKIP_WAITING`, never during a registration or sign-in request (X-3, §7.7; `update_flow.test.js`).
- **BH-06** The `.htaccess` `<If>` names static extensions only; NGINX Direct Delivery is switched off by runbook and checked by the smoke script (nosniff a WARN); an uncontrolled page waits for the verified worker before loading modules; `update()` retries after a failed install are hourly (§5.4, §5.7, §7.7, §11.5, §13.2, risk 6).
- **BH-07** A "Starting…" view calls `__pfpmsStarted()` before any network call, the start-up heartbeat is bounded to 5 s, and Repair runs only when `api/ping.php` answers (§2.3, §7.2, §7.7, §7.8, §10; `boot.test.js`, E2E B4).
- **BH-09** The raw DVK bytes are kept until the last wrap: keyring, then shift key, then `openVault()`; offline unlock opens the vault with a copy (§2.3, §7.2, §11.2).
- **BH-10** A file-level copy of the profile is classed as *device-image* and carries the proof and shift keys; only a script-level export lacks the proof key (bet 4, X-1, §9, Q6).
- **RQ-02** An offline End shift is replayed with its own `ended_at`; the server keeps the later time and ends only sessions started before it (D-24, §6.8, §7.5, §12.3; `testAReplayedOfflineEndShiftNeverEndsALaterSignIn`).
- **RQ-03** `vault.js`'s crypto core, `station_crypto.json` and its PHP test move to S2; E2E steps and REQ lists are re-assigned per slice (REQ-32 and REQ-49 in S1, REQ-40 and REQ-109 in S2); S3's client half follows S2's modules (§11.1, §11.2, §12, §12.6).
- **RQ-04** Steps 8-9 of `Api::start` are the pure public `Api::contextFor()`, with a public `reasonCode()`, tested by `ApiGuardTest` (§5.1, §11.1, §12.1).
- **RQ-05** `ONLINE_WINDOW_SECONDS` is 3 s (the 1.5 s wait plus transit); a refusal outside it is stored Held `REFUSED_LATE`, and the client re-queues a late `refused` (X-6, D-42, §7.5, §8.1, §8.3, §8.6, §11.1, §11.7).
- **RQ-06** E2E fixes: "1 not uploaded" in A8, "2 records" in A12, `station_check` for the 20-item hook, Administrator A added, the decline run before the accept, and the dev hooks specified (§7.2, §11.4).
- **RQ-07** E2E steps added for idle expiry and draft restore (A17), the organisation name and build (A2), the always-visible controls (A5), the update flow (B2) and the kill switch (B6).
- **RQ-08** Grant revocations are audited as `offline_grant_revoke {count, cause}` by `OfflineGrants::revokeForUser()` at every hook; tablet revocations stay counted in the retire/erase row (§6.7, §12.1, §12.3, §11.1).
- **RQ-09** Push passes `okStatuses: [200, 409]`, so a 409 applies every result (D-36, §5.2, §7.2, §8.1; `sync_engine.test.js`).
- **RQ-10** The build hash includes the shell template (built with an empty build, to avoid a cycle), the exact header set and `sw.php` (D-07, §7.1, §12.2; `testBuildChangesWhenTheShellOrItsCspChanges`).
- **RQ-11** Every failed offline unlock counts toward the delay, so the delay no longer reveals who has a keyring entry; only real entries count toward the wipe (D-30, §2.3, §7.3, §7.5, §11.2).
- **RQ-13** The online PIN window needs an online password sign-in (a grant); this is now stated in D-24, the runbook and §13.1, with a test.
- **RQ-14** Recorded: the D-47 formula in the 40 §14.4 edit, `status.php` returning statuses only (§13.1 item 25), and copy for `SESSION_CONFLICT`, `HANDLER_ERROR` and `REFUSED_LATE` (§7.8).
- **RQ-15** `outbox.queuedItems(kind)` is added for P4's stock view, with the pack-refresh rule, and the narrower `cosign` hook is recorded (§3.6, §7.2, §8.10, §12.5, §13.1).
- **RQ-16** Overlap means intersecting activity, checked at offline session start and end; a Station sign-in or PIN switch ends the person's online sessions on other tablets instead (D-17, D-50, §4.1 comment, §4.2, §6.5, §6.6, §8.9, §8.10, §12.3, §13.1; `testAPlainTabletMoveIsNotFlagged`).
- **RQ-17** Covered with F-04 (line 197, the line-186 case) and F-10 (plan:651).

---

## 0. Summary

### 0.1 The design in 15 lines
1. **One migration, 0013**, owned by S1 and settled now. It adds:
   - nine `device` columns (`pbkdf2_iterations`, `proof_key_ciphertext`, `display_mode`, `storage_estimate_kb`, `clock_skew_seconds`, `oldest_pending_at`, `attention_count`, `locked_out_since`, `shift_ended_at`);
   - `auth_token.created_at`;
   - `sync_item.recorded_at_raw`;
   - `'Offline PIN'` and `'User Switch'` appended to their ENUMs.

   No table, FK or setting is added (66 / 167 / 68).
2. `Api::start()` gains `method`, `device` (`in_service`|`known`), `proof`, `session` (false = no PHP session) and `touch` options. Refusals are thrown `HttpException`s rendered as one JSON envelope `{error, message, …}`, and CSRF is verified centrally for every session non-GET.
3. `DeviceGuard` reads `Authorization: PFPMS-Device pfd1_…`, looks the tablet up by `Tokens::hash()`, and trusts only `IN_SERVICE_SQL` plus an active site.
4. Registration, heartbeat, directives and wipe confirmation follow 40 §14, with two additive fields at registration:
   - a random `registration_nonce`, which makes a lost response replayable (the server re-derives the same credential);
   - the tablet's **device proof key** (a 32-byte HMAC key, then kept non-extractable on the tablet).
5. Sign-in, PIN, the in-app acknowledgement and the in-app password change (every call that opens a session or releases keys) require a proof signature from that key. The wipe confirmation requires a time-bound one. Every other device call is checked and flagged, never refused, so directives always get through.
6. Station sign-in (`Auth::attemptStation` on the shared login core) uses a per-tablet bucket and the Station's own cookie `PFPMSST`. Its success transaction takes a shared lock on the device row first, then the account, the grant, then the sessions.
7. Each eligible password sign-in releases the **DVK** and a fresh **offline grant** for in-memory use. The tablet persists a keyring entry only when the server says `offline_allowed`.
   - Grants are superseded, never revoked, by the next one. They are revoked by the account hooks, a PIN change and Retire/Erase.
   - Their expiry is the earliest of every date on which the server would itself refuse the person.
8. The grant is also the **PIN window**: online PIN needs a live grant for that person on that tablet made within `pin_shift_hours` and after the tablet's last End shift (`device.shift_ended_at`).
9. The vault:
   - every store except `meta` and `keyring` holds AES-256-GCM records under `HKDF(DVK, "pfpms/v1/record")`;
   - the keyring wraps the DVK under PBKDF2-SHA256 (calibrated to about 1 s);
   - PINs have the plan's HMAC verifier;
   - a non-extractable **pin_shift key** reopens the vault after a tab kill for `pin_shift_hours`, and every hard lock deletes it.
10. Every Station write is an **outbox item**: canonical-JSON bytes (with a signed `clock_offset_ms`) signed by the recorder's grant key, sealed with the record key, and numbered by a gap-free per-tablet `seq`. Saving is local; the server's answer is shown if it comes within 1.5 s.
11. **Push always uploads the stored ciphertext.** The server opens it with its DVK copy and checks the HMAC over the exact bytes. It clamps the time with the signed offset (the batch skew as fallback), the grant's issue time, the tablet's earlier items and the receipt time. "Rescue push" is the same request.
12. Each item runs in its own transaction under the device row lock. Idempotency is on `client_uuid` (same hash = the stored result, other hash = 409). The rules run in order: suspect, Retire, grant, actor, kind, capability, dependency, handler. Authenticity and device rules give Held; nothing is discarded.
13. Offline sessions are signed `offline_session` items that become `user_session` rows (`Offline` / `Offline PIN`). Offline sign-in failures, which cannot be signed, arrive grouped on the heartbeat as audit-only claims.
14. The Station is:
   - a PHP shell plus plain ES modules;
   - a service worker whose precache is verified by hash and type, and which never touches `/api/` or IndexedDB;
   - a boot watchdog with **Repair**, a config kill switch, and one primary window per tablet.
15. Five slices:
   - **S1** server platform, HTTP core, 0013 and the unconfirmed-wipe cron;
   - **S2** shell, service worker, registration client, parity pack, JS tooling;
   - **S3** online sign-in, grants, PIN, acknowledgement and password change;
   - **S4** vault, offline unlock, offline PIN, shift key, drafts;
   - **S5** push, rescue, status, anomalies, payload purge and the full wipes.

### 0.2 The bets
1. **Always-ciphertext push.** One upload path covers online sync, sync while locked, Push Then Wipe and the failed-unlock rescue. The server's decrypt path runs on every push, so it cannot rot.
2. **Sign the bytes, not a structure.** The HMAC covers exactly the bytes the server decrypts, so a serialiser mismatch shows up as `MALFORMED`, never as silently wrong data. Canonical JSON is enforced as a round-trip check.
3. **The grant is the one "signed in with a password on this tablet" record.** It carries the PIN window, offline expiry and item authenticity. The existing credential and access call sites revoke it.
4. **A device proof key, not an ECDSA ceremony.** A non-extractable HMAC key turns "a script-level copy of `meta` (devtools)" into "the tablet's own browser profile" for the calls where that matters: sign-in, PIN, the acknowledgement and password change (the key releases), and the wipe confirmation. It also flags clones. A file-level copy of the profile (a device image or backup) carries the key's bytes too; OS disk encryption is the defence there (§9, Q6).
5. **Release in memory, persist by permission.** The DVK and grant are released on every eligible sign-in (the outbox needs them even online). `offline_allowed` decides only whether a keyring and a shift key may be kept, and it shortens the grant otherwise.
6. **Server-derived origin.** An item is `Online` when it reached the server within the answer window (`ONLINE_WINDOW_SECONDS = 3`, X-6) of its clamped recorded time. Signed items are never rewritten.
7. **No downloads.** The IndexedDB wrapper is hand-written, and the JS tests use `node:test` with injected adapters. E2E is a manual script in two parts: the browser pane, which cannot run a service worker, and a real Chrome profile for the service-worker steps (§11.4). Playwright and fake-indexeddb stay optional, with the user's approval.
8. **One migration, owned by S1, settled now.** Any later discovery is 0014, never an edit to 0013.

---

## 1. Decisions

### 1.1 Every open decision (brief §4.2)

| ID | Choice | Why | Rejected |
|---|---|---|---|
| D-01 | **Format:** strictly `^pfd1_[A-Za-z0-9_-]{43}$`, scheme `PFPMS-Device` (case-insensitive, one space).<br>**Missing header or another scheme:** 401 `device_credential_missing`. If the request carries `X-PFPMS-Client: station`, Administrators are also alerted, at most once an hour (`device_header_missing`).<br>**Malformed or unknown:** 401 `device_unknown`, counted in `device_auth:ip:<ip>` 20/900 (failures only); the 21st gives 429.<br>**Dev seed:** re-keyed to known `pfd1_` strings. The rows have no vault key and no proof key, so they are online-only test tablets. | A distinct "missing" code shows SiteGround header stripping at once, on the tablet and to Administrators. Counting only failures means healthy tablets behind one NAT address never spend the bucket. | Accepting any ≤100-character token. Leaving the seed unusable (MVP), which contradicted MVP's own D-46. |
| D-02 | `Context` gains `?int $deviceId`, `?string $authMethod` and `?array $device` (promoted, with defaults). Device-only mode (`'session' => false`) returns `null`, never starts a PHP session, and the endpoint reads the tablet with `Api::device()`. | `$ctx = Api::start(…)` / `Api::start(…);` still satisfy `PageContractTest.php:25`. A push never waits on a session file lock or sends `Set-Cookie`. | A separate `DeviceContext` return type; `read_and_close` sessions. |
| D-03 | One JSON envelope, `{error: '<code>', message: '<plain text>', …extra}`, plus `incident` on 500.<br>`HttpException` gains `errorCode`, `extra` and `headers`.<br>`ErrorHandler` maps `ValidationException` → 422 `invalid` (+`errors`) and anything else → 500 `server_error` (logged, with an incident). A client's bad JSON is 400 `bad_json`, thrown as an `HttpException` by `Request::parseJson()`; a `JsonException` from the server's own encoding is a 500 (S1 review). | No `public/api` file exists yet, so nothing depends on today's `{error:<int>}` shape. `api.js` then maps one shape. | Two shapes. |
| D-04 | 401 codes are `not_signed_in`, `session_timeout`, `session_ended`, `account_blocked` and `device_revoked`, from `SessionStore::validate()`'s ended reason. `Page::resolve()` keeps its flashes; `Api` uses the flash-free `Page::session()`. | The re-auth overlay needs the reason. | One code for all. |
| D-05 | **Signature:** `SessionStore::validate(string $sessionId, bool $touch = true)`.<br>**`api/session.php`:** GET never touches; POST `{action:'touch'}` touches, with CSRF.<br>**The Station** sends the touch at most every 5 minutes, only after real pointer or key input, and only while an online session is open.<br>**Device-only endpoints** never read a session. **P3/P4 polling endpoints** must pass `'touch' => false`. | Polling can never keep a session alive (UC-01 §4.2). Work recorded through the sessionless push still keeps the server's idle timer in step, within 5 minutes. | A separate `peek()`; no keep-alive (MVP, where active volunteers are timed out on the server). |
| D-06 | `Page::start(['public' => true, 'session' => false])` for `station/index.php` and `station/sw.php`. New contract rules: every `public/api/**` starts with `Api::start(` naming a `'method'`; public API POSTs also call `Csrf::verify()` explicitly; no `Request::isPost()` under `public/api`; no CORS header anywhere (§5.8). | No session file or cookie per service-worker fetch. The contract stays meaningful. | Plain public `Page::start`; an allowlist in the test. |
| D-07 | `DeviceStatus::currentBuild()` = `substr(APP_VERSION, 0, 29) . '+' . substr(StationAssets::hash(), 0, 10)` (≤40 characters).<br>`StationAssets::hash()` = SHA-256 over `path \0 sha256(file) \n` for every file in `StationAssets::FILES`, then the same line for three more inputs: `shell` = `StationShell::html('')` (the shell with an empty build, which avoids a cycle, since `html()` embeds the build), `headers` = `implode("\n", StationShell::headerLines(false))` and `implode("\n", StationShell::headerLines(true))` (the exact CSP and header set of the shell and the worker), and `station/sw.php` (its bytes), plus `"idb:1\n"`. It is computed per request and memoised. A change to the shell, its CSP or the worker's PHP therefore changes the build, so it reaches the tablets (a cached shell `Response` keeps its old headers otherwise).<br>`currentBuild()` is the **one** call site for ping, heartbeat, registration, `sw.php` and the admin pages. It stays `APP_VERSION` in S1 until S2 fills `FILES`. | `old_build` then detects stale shells. There is no deploy step, and content hashes survive FTP deploys that scramble mtimes. | A generated build file; a separate service-worker hash. |
| D-08 | **Kill switch:** config `station.sw_kill` (bool) makes `sw.php` emit a worker that deletes the `pfpms-shell-*` caches, unregisters, reloads its controlled windows and posts `SW_KILLED` to the others (§7.7). It **never touches IndexedDB**.<br>**Per tablet:** a boot watchdog (15 s) and an in-app **Repair this app** button with the same effect, which runs only while `api/ping.php` answers (offline it would leave the Station unable to start). | An Administrator can recover every tablet; a Coordinator can recover one, with no server access. Records are never at risk. | Kill switch only. |
| D-09 | Static `public/station/manifest.json` plus a PHP test that `name === RegistrationSheet::APP_NAME` and that every icon exists. `start_url`/`scope` are `./`.<br>PNG icons 192, 512, maskable 512 and `apple-touch-icon` 180 are drawn once by `bin/station-icons.php` (GD, which is loaded) and committed. | Chrome installability and iOS need PNGs; nothing is downloaded. | A PHP-emitted manifest; SVG only. |
| D-10 | A hand-written `station/js/db.js` (about 150 lines) behind a storage adapter, plus a `Map` + `structuredClone` adapter for tests. The API makes it impossible to await non-IndexedDB work inside a transaction. | No download. The auto-commit trap is handled in one place. | Vendored `idb` v8. |
| D-11 | `node:test` only, with injected adapters (storage, crypto, clock, transport, environment). The script is `node --test "tests/js/**/*.test.js"` with `"type": "module"`.<br>E2E runs at `http://localhost:8088`; dev `app.base_url` must equal it exactly. CI gets a Node 24 job with no install step.<br>Playwright and fake-indexeddb remain optional, and need the user's approval. | Nothing may be downloaded without approval. The adapters test the real vault, outbox, sync and wipe logic. | Installing packages now; a `pfpms.localhost` vhost. |
| D-12 | Config `station.dev_relax_install`, honoured only when `Config::env()` is `dev` or `test`. Bootstrap refuses to run with it anywhere else, like `secure_cookies`.<br>While it is on, registration accepts a browser tab and the heartbeat treats storage as persisted and the tab as standalone **for the `offline_enabled` computation only**: the stored `storage_persisted` and `display_mode` keep what the tablet reported, so the device page still says "Works online only" with the storage warning (`DeviceStatus::onlineOnlyReason()` as built). `api/session.php` reports `dev_relax: true`, the Station shows a red "Development mode: install checks relaxed" banner, and the dev hooks of §7.2 exist only then. | The browser pane can be neither standalone nor persisted. The gate is for reliability, not security (40 §14.3), and it cannot ship silently. | Client-side fakes; only a real Chrome install. |
| D-13 | New `station/css/station.css`, copying the `app.css` tokens. 48 px minimum targets, 18 px base text, contrast ≥7:1. | No Bootstrap exists. The Station must not change when admin CSS changes. | Copying Bootstrap from `legacy/`. |
| D-14 | `BarcodeDetector` (`qr_code`) when present; the typed code always. No decoder download. `script-src` gets no `'wasm-unsafe-eval'`. P4 decides US-16 (C-35). | The primary tablets have `BarcodeDetector`, and typing always works. | jsQR or zxing-wasm now. |
| D-15 | The Station needs an in-service tablet credential; there is no personal-device Station. Every `in_service` endpoint gives a revoked credential only 403 `device_revoked` + `directive`. | US-01 limits PIN to registered tablets. Web pages serve other devices. | An online-only Station for unregistered browsers. |
| D-16 | `Auth::attemptStation()` beside `Auth::attempt()`, both on one private core.<br>**Station differences:**<ul><li>bucket `login:device:<id>` instead of `login:ip:<ip>`;</li><li>an `$access` callback refuses before any session;</li><li>the success transaction runs, in this order: `DeviceRepository::lockShared($id)` with a trust re-check → `UPDATE user_account` (which also resets `pin_failed_count`) → the grant (`auth_token`) → end the tablet's other online sessions → create `(uid, device.site_id, 'Password', device_id)` → audit.</li></ul>The core never returns `password_hash`. | One audited, rate-limited, dummy-hash login. The lock order is device, account, token, session. A Retire racing the sign-in either wins (sign-in refused) or waits. | Duplicating the login code; granting in a second transaction. |
| D-17 | A Station password sign-in ends the tablet's other open **online** sessions (`Password`, `PIN`) with the new reason `'User Switch'`. A PIN switch ends them with `'PIN Switch'`. Both also end **the same person's** open online Station sessions on other tablets (`SessionStore::endOnlineElsewhere()`, reason `'User Switch'`, `ended_by` = the person): one person works at one tablet at a time, so a plain move between tablets is never an "overlap" (D-50). Web sessions (`device_id` NULL) are untouched. Offline rows end through their own items. | UC-01 pre-condition (uc:221). The US-02 roster can tell "left" from "someone took over". | `'Logout'` for both; leaving them open; flagging every move as an overlap. |
| D-18 | The gating capability is "any `offline.*`" (`StationGate::offlineCapabilities()`). Station sign-in itself requires one, so Board (none) cannot sign in on a tablet. | Every Station user is a grant holder. No new capability. | A new `offline.grant`. |
| D-19 | **Expiry** is the earliest of:<ul><li>now + H;</li><li>the end of access at `device.site_id` (none for `site.all`; none if a live row has no `ends_at`; else the latest `ends_at`);</li><li>00:00 organisation-local of the day **after** `expiry_date`;</li><li>00:00 of `deactivation_effective_date`;</li><li>00:00 of the current agreement's `due_again_on`;</li><li>when `password_max_age_days > 0`, `password_changed_at` + that many days.</li></ul>H = `offline_grant_hours` when `device.offline_enabled = 1`, else `session_absolute_hours`.<br>If the minimum is not in the future, no grant is issued (online only). The binding cause is audited as `capped_by`. | Every date on which the server itself would refuse the person holds offline too (US-28 AC2). The short H enforces online-only tablets at push. | The plan's three inputs only. |
| D-20 | **Grant row:** `purpose 'Offline Grant'`; `token_hash = Tokens::hash(random_bytes(32))` (a filler that meets NOT NULL UNIQUE and is never looked up); `secret_ciphertext = Crypto::encrypt(secret32, 'offline_grant:' . token_hash)`; `device_id`, `expires_at`, `created_at`; `used_at` never set. The client-visible `grant_id` is `token_id`.<br>**A new grant supersedes, never revokes, the person's earlier ones on the tablet.** They stay valid to their own `expires_at` unless a hook revokes them (REQ-67, a PIN change, Retire/Erase). The tablet keeps only the newest. | A sign-in the server committed but whose response was lost on hall Wi-Fi must not turn the volunteer's offline shift (signed with the older grant) into Held items. Keeping an older ≤72 h key live on the same tablet adds little risk. | One live grant per person × tablet (10/12-design, level 6); revoke-on-acknowledgement. |
| D-21 | `revoked_grants` = ids of this tablet's grants revoked in the last 168 h (the registry maximum of `offline_grant_hours`). It is sent in heartbeat, sign-in, PIN and push responses. The tablet deletes a keyring/vault entry only when its **current** grant id is listed. | With supersede, the list carries only real revocations. | User ids. |
| D-22 | **Where:** PINs are set **on the Station, online**: `POST api/auth/pin_set.php {password, pin, pin_confirm}`, with password re-authentication behind `pin_set:user:<uid>` 5/900.<br>**Rules:** digits only; `pin_min_digits`..`pin_max_digits`, with the registry capped at 4-6 (REQ-64); refused if all one digit or a straight ascending or descending run.<br>**Storage:** `pin_hash = 'p1.' . kid . '.' . PasswordPolicy::hash(b64url(HMAC(HKDF(crypto.keys[kid], 'pfpms/v1/pin-pepper'), "<uid>\|<pin>")))`.<br>**Changes:** the first PIN revokes nothing; changing a PIN revokes the person's grants on **other** tablets, and the tablet in hand refreshes its verifier.<br>**Resets:** `resetCredentials` clears `pin_hash` and `pin_failed_count`. `pin_failed_count` is also reset by any password sign-in (web or Station), a PIN success, a PIN set and an Administrator unlock.<br>**Verifier:** created at PIN set and refreshed at every online PIN switch on that tablet. | The PIN is set where it is used, and the verifier exists at once. A database-only leak (a backup) cannot brute-force 10⁴-10⁶ PINs without the config key. Stale verifiers die through the grant revocations. | Web `profile.php`; no pepper; a `pin_changed_at` column. |
| D-23 | **PIN target:** a picker of people with a live grant in `vault_users`. Names are shown only while the DVK is in memory, so after a reload they are shown only through a valid shift key. A hard-locked tablet shows only username + password. | <5 s; no names in plaintext storage. | Names in the plaintext keyring; a typed username for PIN. |
| D-24 | **Online PIN window** = the newest live grant for (user, device) with `created_at ≥ now − pin_shift_hours` **and** `created_at > device.shift_ended_at` (NULL = never).<br>**End shift** (`logout {scope:'device'}`) writes `shift_ended_at` whatever sessions are open. An End shift made offline is replayed at the next contact **with its own time** (`ended_at`, §6.8): the server stores the later of the stored value and that time (never later than now), and ends only online sessions started before it, so a replay never ends or blocks a later sign-in.<br>**Offline window:** `vault_users[u].last_password_at` within `pin_shift_hours` and after `meta.shift_ended_at`.<br>**The two windows differ on purpose.** An offline password unlock creates no grant, so it opens only the offline window: after a morning that started offline, online PIN switching works once the person has signed in online with a password on that tablet (the runbook and the `pin_unavailable` text say so). | Every credential and access event already revokes grants, and End shift ends PIN switching even with no session open. A grant is the only server-side proof of a password on this tablet. | Session queries; a `'Device Lock'` session clause (misses the no-session case); counting uploaded `Offline` sessions (unsigned by any grant the server issued for them). |
| D-25 | **Online** `pin_failed_count` (per account, all tablets) and the **in-vault** counter (per person per tablet) are independent and never reconciled. Online: at `pin_max_failed`, PIN is refused everywhere until a password sign-in. Each online attempt is **reserved before the PIN is checked** (an atomic `UPDATE … SET pin_failed_count = pin_failed_count + 1 WHERE … AND pin_failed_count < pin_max_failed`, §6.6), so parallel requests cannot pass the limit; a success resets it. Bucket `pin:device:<id>` 60/900. | Simple and safe in both directions. | Reconciling at sync; counting after the slow verify (a race). |
| D-26 | **pin_shift key is in (S4)**, in the plan's form (plan:117). A non-extractable AES-GCM key in `meta.shift` wraps the DVK.<br>**When written:** only when `offline_allowed` and the vault was opened by a password.<br>**Expiry:** the earlier of the last password open + `pin_shift_hours` and + `session_absolute_hours`.<br>**Deleted at every hard lock:** End shift, the absolute limit, `pin_max_failed` misses, a failed-unlock wipe, a directive, a clock rollback, and `offline_enabled: false`.<br>After a reload the vault reopens to the picker (keys, no person), and the PIN verifier decides. | Low-RAM Android tablets kill the tab during print and camera dialogs. Without the key, US-01's "<5 s, open entry kept" fails after every kill. While it exists the PIN is a UI control, as REQ-63 requires the threat model to state (§9). | Out (MVP); Security's PBKDF2-mixed PIN KEK, recorded as a later hardening. |
| D-27 | **Calibration at registration:** PBKDF2 of 100 000 rounds on a random password, measured with the injected clock. `rounds = clamp(floor(100 000 × 1000 / ms / 10 000) × 10 000, 100 000, 2 000 000)` (target ≈1.0 s).<br>It is sent as `pbkdf2_iterations`, stored in `device.pbkdf2_iterations`, and kept in `meta.device.iterations` and in every keyring record.<br>There is no re-calibration. `offline_pbkdf2_iterations` is the fallback when the tablet sent none. | ≤3 s unlock with 2 s to spare on the calibrated tablet. The registry floor applies (code wins, C-12). | A 310k floor; re-calibrating at each sign-in. |
| D-28 | **Sub-keys:** HKDF-SHA256 of the DVK with an empty salt, info `pfpms/v1/record` (AES-GCM) and `pfpms/v1/pin` (HMAC).<br>**Record:** `{iv: b64url(12), ct: b64url(ct‖tag)}`, AAD `pfpms/v1\|<store>\|<key>`.<br>**Wrapped DVK:** AAD `pfpms/v1\|keyring\|<device_id>\|<user_id>`.<br>Pinned by `tests/fixtures/station_crypto.json` (§3.9). | Key separation; PHP and JS verified identical. | The raw DVK for everything; a key per store. |
| D-29 | The **plaintext keyring** (keyPath `user_id`) holds no name. It holds `lookup` (multiEntry index) = `[H(username), H(email), H(typed)]` (de-duplicated; `typed` is the identifier exactly as typed at that successful online sign-in), `grant_id`, `grant_expires_at`, `salt`, `iterations`, `iv`, `wrapped_dvk` and `v`, where `H(x) = hex(SHA-256("pfpms/v1/user\|" + device_id + "\|" + lower(NFC(trim(x)))))`. `trim` strips exactly PHP `trim()`'s set (space, `\t`, `\n`, `\r`, `\0`, `\x0B`) as `Auth::attempt` does (`Auth.php:42`), and `lower` is per code point. The server matches identifiers under `utf8mb4_unicode_520_ci` (case- and accent-insensitive); offline, the third value keeps the form the person actually types working, and other accent variants work online only. Names live in `vault_users`. | Offline unlock by the typed username or email, with nothing readable while locked, and the same trimming as online. | Username and display name in plaintext; an untrimmed lookup (a trailing space from a tablet keyboard would fail offline only). |
| D-30 | **Two counters,** per tablet (`meta.unlock`). `failures` counts **every** failed offline unlock (unknown username, expired entry or wrong password) and drives the delay. `wipe_failures` counts only failures **against an existing, unexpired keyring entry** and drives the wipe. An unknown username still runs a dummy PBKDF2 and gives the same generic message. Both reset on a successful unlock.<br>**Delay:** after the 3rd consecutive failure, each further attempt waits 30 s × 2^(failures − 3), capped at 900 s, on the high-water clock ("Try again in {n} seconds"). Because unknown names count too, the delay never reveals which people have offline sign-ins on the tablet (REQ-44: the same outcome for an unknown name and a wrong password).<br>**Wipe:** at `offline_max_failed_unlocks` wipe failures it deletes `keyring`, `vault_users`, `sessions`, `pack` and the shift key. It **keeps** `outbox`, `drafts` (sealed) and `meta`, sets `meta.unlock.locked_out_since`, and reports it on the heartbeat. | A bystander at the lock screen can no longer erase every offline sign-in in seconds. PBKDF2 plus the delay is the brute-force control. Kept drafts reopen after the next online sign-in and add no exposure beyond the kept outbox (UC-01 §4.2 "an entry is never discarded"). | No delay (MVP); counting unknown usernames toward the wipe; a delay only for real entries (it leaks who has a keyring entry); deleting drafts (plan:119, recorded as a deviation). |
| D-31 | Offline sign-in failures are **audit only**. The heartbeat carries them grouped per claimed person and factor, and they never touch `failed_login_count` or `pin_failed_count`. | They cannot be signed; otherwise a credential holder could lock anyone out. | Feeding the lockout (12:280). |
| D-32 | Offline sessions are signed outbox items, `offline_session` `start` and `end` (the end depends on its start). `user_session.auth_method` gains `'Offline PIN'`. `Audit` actor overrides gain `device_id` and `occurred_at`. The session row is inserted in the start item's transaction before its own audit row. | One pipeline: signed, idempotent, Held rules apply. | `sessions[]`/`audit[]` envelope arrays; an `audit_queue` store. |
| D-33 | Canonical JSON v1 (§3.6):<ul><li>keys match `^[a-z][a-z0-9_]{0,63}$`, sorted by byte, unique;</li><li>integers within ±(2^53−1) only, no floats;</li><li>well-formed strings escaped exactly as `JSON.stringify` does;</li><li>no whitespace; depth ≤16; ≤65 536 bytes per item;</li><li>datetimes `YYYY-MM-DD HH:MM:SS.mmm` UTC.</li></ul> | ASCII keys make byte order and code-unit order identical. | Unicode keys; decimals as numbers. |
| D-34 | The HMAC covers the whole signed item S as exact bytes: every field, `clock_offset_ms` and the payload. `payload_sha256` = SHA-256 of the same bytes. | Nothing a credential holder could change is unsigned. | A payload-only HMAC. |
| D-35 | **Clamp:** `estimate = raw + (clock_offset_ms ?? batch skew ?? 0)`; `t = min(estimate, received_at)`; `t = max(t, grant.created_at, seq floor)`, where the seq floor is the latest clamped time among this tablet's **authenticated** items (`recorded_by IS NOT NULL`) with a lower `seq`. Rows Held for authenticity (steps 1-5 of §8.3) never feed it: their seq is unsigned and their time is only the receipt time.<br>**Storage:** `t` goes in `recorded_at_client`, `raw` in `recorded_at_raw`.<br>A correction larger than `sync_clock_skew_minutes` is recorded in the batch audit (`skew_seconds`); P4 applies the same function and flags `'Clock Skew'`. | The signed offset survives an NTP correction mid-event. The batch skew cannot be forged into signed items. The seq floor stops a clock set back after revocation from pulling later items before `revoked_at`. | Batch skew only (unsigned); no seq floor. |
| D-36 | Push answers 200 with per-item results. When any item reused a `client_uuid` with other content, the same body is sent with **409**, and that item's status is `conflict`. The client treats 200 and 409 alike: push calls `api.post()` with `okStatuses: [200, 409]`, so a 409 returns the parsed body and every item result is applied (§7.2). | The literal "gives 409" (plan:259), with no lost results. | 200 always; rejecting the whole batch. |
| D-37 | **No named lock for push.** Each item locks the device row, and `client_uuid` is the primary key. The client runs one push at a time (`navigator.locks` 'pfpms-sync', plus the primary-window rule, X-2). Bucket `sync_push:device:<id>` 240/900. | Retire can never be held off, and there is one concept fewer. A second concurrent batch finds its items stored (duplicate no-ops). | `sync:device:<id>`. |
| D-38 | Push uses `Request::json(1 048 576)`; `Content-Type: application/json` is required (415 otherwise), as on every JSON endpoint. `api.js` sends that header on **every request with a body**, device-only calls included (a `fetch()` with a string body and no header sends `text/plain;charset=UTF-8`). The client keeps a batch to ≤50 items and ≤512 KiB. | Rescue items carry ciphertext. | The 64 KiB default. |
| D-39 | Registry: `offline_session` (real), `station_check` (real: a Coordinator's "Check this tablet" before doors open, audited), and `test_noop` / `test_fail` (only when `Config::env() === 'test'`). Interface `ItemHandler::capability(): ?string`, `apply(ItemContext): ItemResult`, re-runnable. | Real kinds exercise the whole path on each tablet. The test kinds keep pipeline tests independent. | A test-only `noop` in production. |
| D-40 | An unknown kind is Held `UNKNOWN_KIND`. A kind needing a capability the recorder's **current** role lacks is Held `CAPABILITY`. | "Held = cannot trust or cannot apply"; business failures stay commit-and-flag (D10). | Accepted With Exception. |
| D-41 | **Persisted client states:** `queued`, `held`, `conflict`, `invalid`, `refused` (P4 hook). `sending` is kept in memory only.<br>`accepted` and `accepted_with_exception` are deleted; `resolved` and `discarded` (from `status.php`) delete held records. `conflict` and `invalid` are terminal "needs attention" states that are never deleted except by Wipe Now. | Crash-safe. "Never delete unacknowledged" is one rule, and Push Then Wipe can tell "drained" from "stuck". | Keeping accepted items; a persisted `sending`. |
| D-42 | **A save never waits on the network.** Sign, seal and write the outbox (target ≤200 ms), show "Saved on this tablet", then push at once and show the server's answer if it arrives within 1.5 s (`sync.js` `WAIT_MS = 1500`). The fetch is never aborted (30 s timeout), and late answers are applied, **except a late `refused`**: a refusal that arrives after the wait ended is re-queued and resent no sooner than `ONLINE_WINDOW_SECONDS`, so the server then stores it Held `REFUSED_LATE` (X-6). A record the volunteer did not see refused is never dropped.<br>**Session calls:** sign-in times out at 6 s and PIN at 3 s, then the offline path is offered.<br>**Origin** is server-derived (bet 6). | UC-06 §4.4's 2 s per step holds on bad Wi-Fi. P4's synchronous refusal has a UI moment, and only that moment. | Never waiting (MVP); 2 s synchronous (Security); flipping a signed `origin`. |
| D-43 | `GET api/sync/status.php?uuids=…` (≤50), with the device credential only (`known`). It returns `{client_uuid, status, reason_code, resolved_at}` for this tablet's own items only: no reason text, no names. | Works while locked, with nothing personal. | Session + device; a per-user signed request. |
| D-44 | Always send the stored vault ciphertext (bet 1). `rescue: true` only labels a push made with the vault locked. | One server path. | Plaintext for normal push. |
| D-45 | 0013 = the nine `device` columns, `auth_token.created_at`, `sync_item.recorded_at_raw`, `'Offline PIN'` and `'User Switch'` (§4). Nothing else. | Every other later need is met by existing columns. 0013 is settled before it is applied. | `sync_item` id/grant/session/detail columns, a dependency table, indexes, `pin_changed_at`, per-slice migrations. |
| D-46 | Bet 5: the DVK and grant are released on every eligible sign-in; the keyring and shift key only when `offline_allowed`; H is shortened otherwise. A tablet with NULL `vault_key_ciphertext` (dev seed rows only) signs in online but gets no grant, so it has **no PIN window and cannot record** ("test tablet" banner). | One authentication path for items. | A memory-only, session-authenticated outbox. |
| D-47 | **`offline_enabled`** = in service ∧ active site ∧ `storage_persisted` ∧ `display_mode = 'standalone'` ∧ `offline_mode_enabled`, on every platform. It is computed in the UPDATE under the row predicate.<br>**Heartbeat** at start-up (before any unlock screen), on `online`, on becoming visible, and every 5 minutes while open. Push responses carry `directive` and `revoked_grants` too.<br>**`offline_enabled: false`** makes the tablet delete its keyring and shift key. | Registration already requires standalone. An always-online tablet learns directives within 5 minutes. | UA sniffing; start-up only. |
| D-48 | `device_revoked_contact` is throttled by `RateLimit::hit('device_revoked_contact:<id>', 1, 3600)` in the guard, before any transaction. The row is written with `Audit::record()` (autocommit). | The existing mechanism; no lock waits. | Looking up the last audit row. |
| D-49 | **After each batch with Held items:**<ul><li>`toRoleOnce('Coordinator', site, 'sync_held', …, 'device', id)`;</li><li>`toRoleOnce('Administrator', null, 'sync_held', …, 'device', id)` when the tablet is suspect **or its site is inactive**;</li><li>one `toUser` per verified recorder per batch.</li></ul> | Deduplicated per tablet. Administrators do not see Coordinator rows, and Coordinators cannot see an inactive site's rows (`SiteAccess::sitesFor` lists active sites only). | One notice per item. |
| D-50 | **Computed in P2B:** at `offline_session` start and end ingestion.<ul><li>`overlap`: the person's **activity** on another tablet intersects this session's activity (`other.started_at < this.last_activity_at` and `other.last_activity_at > this.started_at`, with no idle extension; at start ingestion `this.last_activity_at = this.started_at`). A plain move between tablets is never flagged, and a Station sign-in or PIN switch ends the person's online sessions elsewhere instead (D-17);</li><li>`pin_window`: an `Offline PIN` start with no password-factor session on this tablet in the preceding `pin_shift_hours`.</li></ul>**Recorded as** `Audit::record('sync_anomaly', 'device', id, …)` + `toRoleOnce('Coordinator', site, 'sync_anomaly', …)`, with the same Administrator fallback. Items are not held. | No column; reviewable where Coordinators look. | A flag column; P4. |
| D-51 | **Device-authenticated paths never call `Audit::durable()`.** Refusal and contact rows are written with `Audit::record()` while no transaction is open (autocommit, as `Auth::attempt` does).<br>Registration (public actor, so no FK column set) keeps `Audit::durable()` after the rollback, as 40 §14.3 says.<br>The actor override accepts `device_id` (and `occurred_at`) for rows that need them. | No E14 waits, and no PHPUnit trap (`TestCase.php:21-27`). | Durable rows with FK ids. |
| D-52 | **A separate Station cookie `PFPMSST`**, path `Request::basePath() . 'api/'`, with the same flags as `PFPMSSID`. It is chosen when the guard has a `device` option or the request carries `X-PFPMS-Client: station`.<br>`WebSession::start(bool $station = false)` remembers the choice, `restart()` reuses it, and `destroy()` clears `session_name()`. | An Android PWA shares Chrome's cookie jar, so a Station PIN switch must never change a Coordinator's web tab. The fix to `restart()` is needed in any case (factual error #10). | The shared `PFPMSSID`. |
| D-53 | Station sign-in uses `login:device:<id>` 30/900 plus the shared `login:id:<lower>` 10/900; PIN uses `pin:device:<id>` 60/900. | A site's NAT address is never locked out at shift start. | Raising `login:ip`. |
| D-54 | **In-app acknowledgement:** `GET\|POST api/auth/policy.php`, with `document_id` + `Policy::fingerprint`. Accept releases the DVK and grant; decline ends the session and leaves no keyring.<br>**In-app forced password change:** `POST api/auth/password.php`. Tx1 is `Auth::setPassword(…, keepSessionId)`; Tx2 separately issues the grant. | Go-live means many first sign-ins of Pending accounts on tablets. A hand-off to web pages breaks with the separate cookie and iOS standalone navigation. | Hand-off to `policy_ack.php`/`change_password.php`. |
| D-55 | Both crons are in P2B:<ul><li>`devices:clear-unconfirmed-wipes` (S1) clears `vault_key_ciphertext` and shreds the grant secrets of tablets revoked more than `sync_payload_retention_days` ago whose wipe was never confirmed. It keeps `proof_key_ciphertext` (it opens nothing), so a tablet that turns up later can still confirm its erase with a signed heartbeat (S1 review);</li><li>`sync:purge-payloads` (S5) clears `payload_ciphertext` older than the retention for every status except Held, and the secrets of grants expired longer ago than the retention.</li></ul>Both are registered in `Runner::jobs()`. | REQ-16 and REQ-91 at almost no cost. Cron is ≥30 min, and nothing time-critical depends on it. | P6. |
| D-56 | `rules.js` (the Validator twin) moves to **P3**, with the forms that use it. | P2B has no form that validates participant data, and an exact twin of `filter_var` is costly parity work. | A twin now (MVP). |
| D-57 | `calc.js` ports `AllotmentCalculator` in **S2** against the existing fixture (the plan's P2B file list). The Barcode port waits for P4. | The fixture exists; about 100 lines. | Deferring calc. |
| D-58 | **Maintenance mode:** `app.maintenance` (bool) makes every `Api::start` answer 503 `maintenance` with `Retry-After: 120`. The Station treats it, like 502/503/504 and non-JSON answers, as offline. | Deploys near events never show 500s, and records stay queued. | None. |
| D-59 | `StationGate::release()` is the reusable trust + acknowledgement + forced-change gate, tested for the grant/DVK half. There is no `pack.php` in P2B; P3's must call the gate. | C-29. | A stub `pack.php`. |

### 1.2 Additional decisions this merge makes
| ID | Choice | Why |
|---|---|---|
| X-1 **Device proof key** | **At registration:** the tablet makes a 32-byte HMAC-SHA256 key (`generateKey({name: 'HMAC', hash: 'SHA-256', length: 256}, true, ['sign'])`; without `length` WebCrypto makes a 64-byte key, the hash block size, which the server's 43-character check refuses), sends it once as `proof_key`, re-imports it as **non-extractable** into `meta.device.proof`, and zeroes the raw bytes. The server stores `Crypto::encrypt(key, "device:<id>:proof")` in `device.proof_key_ciphertext`.<br>**Every device call** carries `PFPMS-Proof: v1 <ts_ms> <b64url HMAC>` over `pfpms/v1/proof\n<METHOD>\n<endpoint>\n<ts_ms>\n<hex SHA-256(body)>` (§3.1).<br>**Enforced** (401 when invalid or stale), **only when the row has a key** (seed rows have none), on every call that opens a session or releases keys: `api/auth/login.php`, `pin.php`, `policy.php` and `password.php`; and on the wipe confirmation.<br>**Elsewhere** it is checked and flagged (X-5), never refused. | A script-level copy of the credential (devtools, an exported `meta`) can neither confirm a Retire wipe, which would make the real tablet erase unsent records, nor open Station sessions or receive the DVK and grants by password or PIN guessing. A file-level copy of the browser profile (a device image or backup) carries the key's bytes as well: IndexedDB stores a `CryptoKey`'s material whatever its extractability, so OS disk encryption is the defence there (§9, Q6). HMAC keeps PHP and JS trivial. |
| X-2 **One primary window** | At boot, `navigator.locks.request('pfpms-primary', {ifAvailable: true}, …)` holds a lock for the page's life. A second window shows "The Station is open in another window on this tablet" with **Use this window here** (`{steal: true}`). The window that loses its lock drops its keys, stops sync and heartbeats, and shows "This Station window was replaced by another one. You can close it." Without Web Locks the tablet keeps one window by convention (runbook). | Two windows would each hold the DVK, run timers and wipes, and race on the outbox. Web Locks is in Chrome and iPadOS 16.4+. |
| X-3 **Replay-safe registration** | The tablet sends `registration_nonce` (16 random bytes, b64url). The server derives `credential = 'pfd1_' . b64url(HMAC(HKDF(active crypto key, 'pfpms/v1/device-credential'), 'pfpms/v1/device-credential\|<token_id>\|<nonce>'))`. A repeat of the same code + nonce + proof key within 15 minutes of `registered_at`, on a tablet still in service, gets the same 200 with `replayed: true`. The nonce and the raw proof key live only in memory, so the page must not reload while the request is in flight: no service-worker `controllerchange` reload runs then (§7.7). | A 200 lost on hall Wi-Fi no longer burns the sheet and leaves a registered row no tablet holds. The server still makes the credential (40 §14.1/§14.3); only the tablet knows the nonce. |
| X-4 **Wipe order** | The tablet writes `meta.wipe {mode, stage, started_at, items_pushed}` before any deletion. It resumes at start-up before any view. It clears every store except `meta`, confirms `wiped` (proof-signed), and deletes the database, caches and service worker only after a 200 or 410. | A tab killed after `deleteDatabase` but before the confirmation would otherwise strand `vault_key_ciphertext` on the server. `meta` holds no personal data. A recorded deviation from 40 §14.5 steps 3-4. |
| X-5 **Clone signals** | On a row that has a proof key, a device call whose proof is **missing or invalid** (a stale timestamp alone is not a signal), or a heartbeat whose `max_seq` is below the stored `reported_max_seq`, triggers bucket `device_unproven:<id>` 1/3600. It writes `Audit::record('device_unproven', …)` and `toRoleOnce('Administrator', null, 'device_clone_suspected', …, 'device', id)`. The request is still served. | A second profile using a copied credential is noticed, and nothing a real tablet needs is ever refused. |
| X-6 **Server-side answer window** | `ONLINE_WINDOW_SECONDS = 3`: `origin = 'Online'` iff `received_at − t ≤ 3 s`. It is the client's 1.5 s wait for the answer (`WAIT_MS`, D-42) plus 1.5 s for transit and offset error, documented as one shared constant in `SyncService` and `sync.js`. P4's `refused()` stores nothing only inside it; outside, the item is stored Held `REFUSED_LATE`. The client re-queues a `refused` that reaches it after its wait ended, and resends it no sooner than the window, so that item is stored Held too (§8.1). | Origin means "answered while the volunteer waited" (REQ-72's timed-out item becomes Offline). A refusal the volunteer did not see can never drop a real handout (UC-06 §4.3 "without loss"). |

### 1.3 Conflicts resolved differently from the brief, or made concrete
- **C-05 / 40 §14.3 step 1.** `Api::start()` verifies CSRF for every non-GET request that uses a session, anonymous included (the gap at `Api.php:24-27` is closed centrally). Public POST endpoints also keep the explicit `Csrf::verify()` 40 §14.3 asks for. The second check is harmless, and a contract test enforces it.
- **REQ-30 gate list.** `offline_mode_enabled` and `device.offline_enabled` do not withhold the DVK and grant. They decide whether the tablet may keep a keyring and shift key, and how long the grant lasts (bet 5). The other gates are unchanged, so plan:260 is untouched.
- **REQ-65 "one live grant per user × device".** Replaced by supersede (D-20). The sources are 10:376 and 12:117 (level 6); plan:251 and 40 §14.7 do not require it.
- **REQ-72 origin flip.** Replaced by server-derived origin (a signed item cannot change).
- **REQ-37 store list.** There is no `audit_queue` and no `notifications` store (bet 4 of MVP). P3 may add stores in an additive `db.js` upgrade. REQ-38's "schema version" in the AAD is the record-format version `v1`.
- **REQ-77 `ACTOR_INVALID`.** Explicit (§8.3), not subsumed.
- **REQ-87 envelope arrays.** Replaced by `offline_session` items and the grouped heartbeat `auth_failures`.
- **REQ-63, REQ-16, REQ-91.** All in P2B (D-26, D-55).
- **REQ-64.** The registry maximum of `pin_min_digits`/`pin_max_digits` becomes 6, removing deviation 11 (`SettingsServiceTest.php:186` and `:197` edited in S3, §4.3).
- **REQ-96 / plan:119.** The failed-unlock wipe keeps sealed drafts (D-30).
- **40 §14.3 body and §14.4 heartbeat.** Additive fields:
  - registration: `registration_nonce`, `proof_key`;
  - heartbeat: `client_now`, `attention_count`, `locked_out_since`, `auth_failures`;
  - heartbeat response: `config`;
  - `offline_enabled` requires standalone on every platform and an active site (D-47), where 40 §14.4 has "(not iOS, or standalone)".

  Wipe confirmation needs the proof (X-1). The wipe order is X-4.
- **40 §14.5 step 1 for an open draft.** Unchanged: a Push Then Wipe directive deletes drafts. P4 may add a "save for review" prompt (§13.3 Q-new-4).
- **C-04.** No named lock for push (D-37), as §0a/plan/code require.
- **C-29, C-30.** As the brief resolves them: the gate function is built now and the pack half of plan:260 moves to P3; P2B defines and applies the clamp.

---

## 2. Architecture

### 2.1 Components and trust boundaries

```
 TABLET (installed PWA, origin = app.base_url)                      SERVER (PHP 8.2/8.3, SiteGround)
 ┌────────────────────────────────────────────────┐   HTTPS        ┌───────────────────────────────────────────────────┐
 │ station/index.php (cached shell, Station CSP)   │               │ api/ping.php            (no session)              │
 │  boot.js  watchdog · SW registration · Repair   │  cookie PFPMSST│ api/session.php         (Station session; GET no touch)│
 │  app.js   state machine · primary-window lock   │  + CSRF        │ api/auth/{login,pin,pin_set,logout,policy,password}│
 │  session.js (who is at the tablet, timers, PIN) │  + credential  │   Api::start device + session (+ proof)           │
 │  vault.js   (KEK, DVK sub-keys, seal/open,      │  + proof       │ api/device/register.php (public + CSRF)           │
 │              verifier, shift key)               │ ─────────────► │ api/device/heartbeat.php (device only)            │
 │  outbox.js  (sign, seal, gap-free seq)          │  credential    │ api/sync/{push,status}.php (device only)          │
 │  sync.js    (push, status, backoff, gating)     │  + proof only  │                                                   │
 │  device.js  (register, heartbeat, wipes)        │ ─────────────► │ src/Device  DeviceGuard, DeviceProof,             │
 │  clock.js   (offset, high-water, jumps)         │                │             DeviceRegistration, DeviceHeartbeat   │
 │  api.js     (transport, errors, offline)        │                │ src/Station StationAuth, StationGate, OfflineGrants│
 │  db.js ── IndexedDB "pfpms"                     │                │             StationConfig, SessionInfo, Assets    │
 │    meta, keyring                 (plaintext)    │                │ src/Sync    SyncService, SyncRepository, Canonical,│
 │    vault_users, sessions, outbox*, drafts, pack │                │             Clamp, Anomalies, handlers            │
 │                                  (AES-GCM K_rec)│                │ MySQL: device, auth_token, user_session,          │
 │ sw.php → service worker: verified shell cache,  │                │        sync_item, audit_log, notification         │
 │   never /api/, never IndexedDB                  │                │ config crypto.keys (DVK, proof key, grant secrets,│
 └────────────────────────────────────────────────┘                │   payloads, PIN pepper, credential derivation)    │
   * outbox: plaintext index fields + sealed bytes + HMAC           └───────────────────────────────────────────────────┘
```

Trust boundaries:
1. **Browser ↔ server, session calls.** These are protected by:
   - the HttpOnly Station cookie `PFPMSST` (path `<base>api/`);
   - `X-CSRF-Token`, plus the Origin and `Sec-Fetch-Site` checks (existing `Csrf`);
   - the tablet credential;
   - on sign-in, PIN, acknowledgement and password change (the calls that open sessions or release keys), the proof signature.

   There are never any CORS headers.
2. **Browser ↔ server, device calls.** These carry the credential and the proof, with `credentials: 'omit'`, except during a wipe: once `meta.wipe` exists they use `credentials: 'same-origin'`, because Chromium applies `Clear-Site-Data` only to responses of requests that carry credentials (§7.6). The server never reads the cookie on these endpoints (`'session' => false`). A cross-site page cannot send `Authorization` without a preflight, so no CSRF is needed. The credential proves "a holder of this tablet's registration"; the proof proves "this tablet's browser profile"; neither proves a person.
3. **Items.** A person is proven only by an HMAC with that person's grant key, which lives in the encrypted vault. The server trusts an item's author, time and content only after the HMAC check, and bounds the time by the clamp.
4. **At rest on the tablet.**
   - Plaintext: only `meta` (credential, the non-extractable proof and shift keys, counters, config) and `keyring` (wrapped DVKs, hashed lookups, no names).
   - Everything else needs the DVK, which needs a password, a valid shift key, or the server.
5. **At rest on the server.** The DVK, proof key and grant secrets are `Crypto`-encrypted with the config key ring. The credential and codes are SHA-256 only. PINs are peppered Argon2id.

### 2.2 What lives where
| Thing | Server | Tablet |
|---|---|---|
| Credential `pfd1_…` | `device.token_hash` (SHA-256 hex) | `meta.device.credential` (plaintext) and memory |
| Proof key (32 B) | `device.proof_key_ciphertext` = `Crypto::encrypt(key, "device:<id>:proof")` | `meta.device.proof`: non-extractable HMAC `CryptoKey` |
| DVK (32 B) | `device.vault_key_ciphertext` = `Crypto::encrypt(dvk, "device:<id>:dvk")` | never raw at rest. Wrapped per person in `keyring`, wrapped by the shift key in `meta.shift`, and in memory only as non-extractable HKDF sub-keys |
| Grant secret (32 B) | `auth_token.secret_ciphertext` = `Crypto::encrypt(secret, "offline_grant:<token_hash>")` | inside `vault_users[u]` (sealed) |
| Password | Argon2id `password_hash` | never stored; the PBKDF2 input only |
| PIN | `pin_hash` = `p1.<kid>.<Argon2id(pepper)>`, `pin_failed_count` | offline verifier + in-vault counter inside `vault_users[u]` |
| Items | `sync_item` row + `payload_ciphertext` = `Crypto::encrypt(bytes, "sync_item:<uuid>")` | an `outbox` record until acknowledged |
| Sessions | `user_session` (online rows at sign-in; offline rows from items) | `sessions` store (the open offline session) |
| Settings the tablet needs | `system_setting` | `meta.config` (from heartbeat and sign-in) |

### 2.3 Data flows

**Registration** (S1 server, S2 client)
1. The installed app shows "Register this tablet". It runs:
   - a key self-test: a non-extractable `CryptoKey` must survive an IndexedDB round trip and still sign;
   - `navigator.storage.persist()`;
   - PBKDF2 calibration;
   - it then makes the proof key (extractable, for the one export) and a 16-byte `registration_nonce`.
2. `GET api/session.php` (header `X-PFPMS-Client: station`) gives an anonymous `PFPMSST` session and `csrf`.
3. The volunteer scans or types the code. JS `RegistrationCode.normalise()` refuses typos before any request.
4. `POST api/device/register.php` `{code, registration_nonce, proof_key, display_mode, storage_persisted, app_build, pbkdf2_iterations}` with `X-CSRF-Token`.
5. The server runs, in order:
   - the buckets, `normalise`, the global alert bucket and `not_installed`;
   - `Tokens::find`: if nothing is found, the replay check (X-3);
   - the named lock `device:<id>`, then a transaction: device row lock → still waiting → creator `lockShared` + re-check → `consume` → derived credential + DVK → `register()` (stores the hash, DVK ciphertext, proof key ciphertext, rounds) → audit `device_register` → notify the creator.

   It answers 200 `{device_id, site, label, credential, pbkdf2_iterations, replayed, server_time, build}`.
6. The tablet:
   - re-imports the proof key as non-extractable and zeroes the raw bytes;
   - writes `meta.device` and `meta.seq = {next: 1}`;
   - sends the first heartbeat and shows "Registered to {site} as {label}."
7. A lost response is retried with the **same** body (up to 3 tries, then on the next press within 15 minutes). The server answers the same 200.

**Sign-in (online, password)** (S3)
1. On the lock screen the person types a username and password. The tablet picks a fresh 16-byte salt and **starts PBKDF2 at once**, in parallel with the request.
2. `POST api/auth/login.php` (credential + proof + Station session + CSRF). The server runs `Auth::attemptStation`: buckets → lookup → dummy hash → password → `blockReason` → `StationGate::access` (site access, an offline capability). Its success transaction is:
   - `DeviceRepository::lockShared($id)` and a trust re-check;
   - `UPDATE user_account`;
   - `StationGate::release` (gates; the grant INSERT);
   - end the tablet's other online sessions, and the person's online sessions on other tablets, `'User Switch'` (D-17);
   - create `(uid, device.site_id, 'Password', device_id)`;
   - audit `login`.

   Then `WebSession::login` (regenerate + CSRF rotate).
3. The response carries `csrf`, `user`, `session_ref`, `gate`, `release {dvk, grant_id, grant_hmac_key, grant_issued_at, grant_expires_at, pbkdf2_iterations, offline_allowed}`, `revoked_grants` and `config`.
4. The tablet, once the KEK is ready, in this order (every wrap needs the raw DVK bytes, so they are zeroed only after the last one):
   - wraps the DVK and `keyring.put`s it, only if `offline_allowed` (lookups: username, email and the identifier as typed, D-29);
   - writes the shift key, only if `offline_allowed`;
   - opens the vault (HKDF sub-keys, non-extractable), which zeroes the raw DVK;
   - puts `vault_users[u]`;
   - starts the online session.
5. `gate = 'policy_ack'` shows the ack view (the KEK is kept in memory for 10 minutes at most); accepting calls `api/auth/policy.php`, whose response carries `release`, then step 4. `gate = 'password_change'` shows the password view; `api/auth/password.php` returns `release` after the change, and the KEK is re-derived from the **new** password.
6. With no answer within 6 s, or an offline error, the tablet offers the offline unlock when a keyring entry matches. A committed sign-in whose answer was lost does no harm: the older grant is still valid (D-20).

**Unlock (offline, password)** (S4)
1. The sign-in POST fails as offline (a network error, a timeout, 502/503/504, or a non-JSON reply).
2. The keyring lookup is by `H(typed)` (trimmed as the server trims, D-29). Two cases give the generic failure:
   - no entry: a dummy PBKDF2 runs; `unlock.failures++` (the delay), but not the wipe count;
   - `grant_expires_at` has passed on the tablet's `serverNow()`: the same (D-30 counts only unexpired entries toward the wipe).
3. PBKDF2 → AES-GCM decrypt of `wrapped_dvk`. A wrong password gives the generic failure: `unlock.failures++` and `unlock.wipe_failures++`, an `auth_failures` entry, and the progressive delay. At `offline_max_failed_unlocks` wipe failures the failed-unlock wipe runs.
4. On success: open the vault with a **copy** of the unwrapped DVK bytes → decrypt `vault_users[u]` and re-check the grant expiry (a failure here drops the keys and gives the expiry message) → write the shift key with the raw bytes, then zero them → `last_password_at = now` → a new offline session → queue `offline_session start` (factor `password`) → home, with the offline banner and only `offline.*` capabilities.

**PIN switch** (S3 online, S4 offline)
1. "Switch user" (or the idle lock, or a reload with a valid shift key) shows the picker of `vault_users` entries whose grant is live.
2. **Online first:** `POST api/auth/pin.php {user_id, pin}` (credential + proof, 3 s timeout).
   - The server checks: bucket → target → gates → `auth.pin_switch` → site access → PIN set → PIN window → **reserve the attempt** (an atomic increment, refused at `pin_max_failed`) → `Pin::verify`.
   - Its success transaction: device `lockShared` + re-check → counter reset → end this tablet's online sessions `'PIN Switch'` and the person's online sessions on other tablets `'User Switch'` → create `'PIN'` → audit.
   - Then `WebSession::login`. The response carries a new `csrf` and `session_ref` and **no keys**. The tablet refreshes the person's offline verifier.
3. **Offline** (or on timeout), the tablet checks:
   - the verifier HMAC compare;
   - the in-vault counter;
   - the offline window;
   - then it ends the previous offline session (queues `end`, 'PIN Switch'). A previous online session is left to time out on the server.
   - It starts a new offline session (queues `start`, factor `pin`).

**Push** (S5)
1. **Triggers:** after a write (the UI waits up to 1.5 s for this item's answer), on `online`, on becoming visible, after a heartbeat, and every 30 s while items are queued.
2. `outbox.due()` takes up to 50 `queued` records in `seq` order (and ≤512 KiB), holding back any whose dependency is unacknowledged. It sends `POST api/sync/push.php` `{v, batch_uuid, client_now, build, rescue, items:[{client_uuid, seq, iv, ct, mac, cosign}]}` with the credential and proof only.
3. **Server, per item:**
   - *outside any transaction:* shape → open with `HKDF(DVK)` → SHA-256 → canonical parse → grant lookup → HMAC;
   - *in the item's transaction:* device row `FOR UPDATE` → idempotency → Held from the earlier steps → suspect → clamp → Retire cut-off → grant valid at t → actor valid at t → kind, capability → dependency → session → handler → `sync_item` INSERT → `last_sync_at`.
4. **Response per item.** The tablet deletes acknowledged records, keeps held ones as metadata, and marks conflicts and invalid items for attention.

**Rescue push**: identical, with `rescue: true`. It is sent while the vault is locked or after a failed-unlock wipe. Nothing else differs.

**Heartbeat and wipe** (S1 server, S2/S5 client)
1. **When:** at start-up **before the lock screen**, on `online`, on becoming visible, and every 5 minutes. It is `POST api/device/heartbeat.php` (credential + proof) with the reported figures and `auth_failures`. The start-up heartbeat is bounded to 5 s: a "Starting…" view is mounted first (so the boot watchdog never fires on a slow network), and the lock screen stays disabled until the heartbeat answers or times out (§7.7).
2. **In service:** the figures are written and `offline_enabled` is computed under the row predicate. The response is `{status:'ok', offline_enabled, …, directive: null, revoked_grants, config}`.
3. **Revoked:**
   - the figures are written (never `reported_max_seq`);
   - the guard writes a throttled `device_revoked_contact` row and an Administrator alert;
   - the response is `{status:'revoked', directive:{wipe: mode}}` **without** `Clear-Site-Data`.
4. **The tablet on a directive** (X-4):
   1. write `meta.wipe`;
   2. delete `pack`, `keyring`, `vault_users`, `sessions`, `drafts` and the shift key;
   3. **Push Then Wipe:** push until nothing is `queued`. Items in `conflict`/`invalid` stop here (`stuck_conflict`). **Wipe Now:** clear the outbox.
   4. clear `outbox`, then heartbeat `{wiped: true, items_pushed}` with a time-bound proof and `credentials: 'same-origin'` (so the browser applies the reply's `Clear-Site-Data`), retried until 200 or 410;
   5. close the tablet's own database connection, then `indexedDB.deleteDatabase('pfpms')`, `caches.delete()` for every `pfpms-*` key, and unregister the service worker. When `Clear-Site-Data` has already erased the storage (the open connection is closed under the page), this stage finds nothing left and goes straight to the final screen.
5. **The server,** under the row lock, for a revoked, unwiped tablet with a valid proof:
   - `wiped_at`, `pending_count = 0`, `vault_key_ciphertext = NULL`, `proof_key_ciphertext = NULL`;
   - the tablet's grant secrets shredded;
   - audit `device_wiped`;
   - reply `{status:'wiped'}` + `Clear-Site-Data: "cache", "storage"`.

   From a tablet in service, `wiped` is ignored. A wiped tablet gets 410 from every endpoint.

---

## 3. Keys and crypto

### 3.1 Hierarchy
```
server config crypto.keys[kid] (AES-256-GCM, Crypto.php)
 ├─ device.vault_key_ciphertext   = Crypto::encrypt(DVK,       "device:<device_id>:dvk")
 ├─ device.proof_key_ciphertext   = Crypto::encrypt(P_d,       "device:<device_id>:proof")
 ├─ auth_token.secret_ciphertext  = Crypto::encrypt(G_u,d,     "offline_grant:<token_hash>")
 ├─ sync_item.payload_ciphertext  = Crypto::encrypt(item bytes,"sync_item:<client_uuid>")
 ├─ PIN pepper[kid]  = HKDF-SHA256(key[kid], info "pfpms/v1/pin-pepper", 32)  → pin_hash "p1.<kid>.<Argon2id(b64url(HMAC(pepper, "<uid>|<pin>")))>"
 └─ credential key   = HKDF-SHA256(key[active], info "pfpms/v1/device-credential", 32)
        C_d = "pfd1_" + b64url(HMAC(credential key, "pfpms/v1/device-credential|<token_id>|<registration_nonce>"))   → device.token_hash = SHA-256(C_d)

DVK (32 random bytes per tablet, random_bytes(32) at registration; never rotated in R1)
 ├─ K_rec = HKDF-SHA256(ikm=DVK, salt=∅, info="pfpms/v1/record", L=32)  → AES-256-GCM, every sealed record
 └─ K_pin = HKDF-SHA256(ikm=DVK, salt=∅, info="pfpms/v1/pin",    L=32)  → HMAC-SHA256, PIN verifiers

KEK_u   = PBKDF2-SHA256(UTF-8(password exactly as typed), salt_u (16 random bytes), rounds_d)  → AES-256-GCM, wraps the DVK for person u
K_shift = non-extractable AES-256-GCM key made on the tablet                                  → wraps the DVK for ≤ pin_shift_hours (D-26)
P_d     = 32 random bytes made on the tablet at registration, then non-extractable HMAC-SHA256 → the device proof (X-1)
G_u,d   = 32 random bytes per person × tablet per sign-in, made by the server                 → HMAC-SHA256 over item bytes
```
The credential derivation is deterministic only so that the replay rule (X-3) can re-derive it within 15 minutes. It is 256 bits of HMAC output under a key only the config holds, and the server keeps only its SHA-256.

### 3.2 Algorithms and parameters
| Use | Algorithm | Parameters |
|---|---|---|
| Record sealing | AES-256-GCM | 96-bit random IV per record (`crypto.getRandomValues`), 128-bit tag, AAD = UTF-8 `pfpms/v1\|<store>\|<key>` |
| DVK wrap (keyring) | AES-256-GCM under KEK_u | random IV; AAD `pfpms/v1\|keyring\|<device_id>\|<user_id>` (decimal ids) |
| DVK wrap (shift) | AES-256-GCM under K_shift | random IV; AAD `pfpms/v1\|shift\|<device_id>\|<expires_at>` (`YYYY-MM-DD HH:MM:SS.mmm`, server frame), so an edited `expires_at` fails the tag |
| KEK | PBKDF2-SHA256 | 16-byte random salt per keyring write. Rounds from calibration (D-27), 100 000..2 000 000, stored in the record. The password is taken exactly as typed (no trim, no normalisation), the same bytes the server's Argon2id checks. |
| Sub-keys | HKDF-SHA256 | empty salt, the info strings above, 32-byte output, imported non-extractable |
| PIN verifier | HMAC-SHA256 under K_pin | message UTF-8 `pfpms/v1/pin\|<user_id>\|<pin digits>`; stored as b64url (43 chars) |
| Item signature | HMAC-SHA256 under G_u,d | message = the item's canonical bytes (§3.6); b64url (43 chars) |
| Device proof | HMAC-SHA256 under P_d | message UTF-8 `pfpms/v1/proof\n<METHOD>\n<endpoint>\n<ts_ms>\n<hex SHA-256(raw body)>`. `endpoint` is the entry point's path under `public/` without a query (for example `api/device/heartbeat.php`). `ts_ms` is the tablet's `serverNow()` in ms. Header `PFPMS-Proof: v1 <ts_ms> <b64url mac>` |
| Keyring lookup | SHA-256 | UTF-8 `pfpms/v1/user\|<device_id>\|<lower(NFC(trim(x)))>`, hex; `trim` = PHP `trim()`'s set (U+0020, `\t`, `\n`, `\r`, `\0`, `\x0B`), as the server trims identifiers (`Auth.php:42`); `lower` = per-code-point `toLowerCase()` (JS only). Applied to the username, the email and the typed identifier at the keyring write (D-29), and to the typed text at lookup. |
| Server at rest | `Crypto::encrypt` | `g1.<kid>.<b64url(iv‖tag‖ct)>` as built |

**Calibration** (`vault.calibrate(env)`, at registration): derive 32 bytes with 100 000 rounds on a random password and salt, and measure `ms` with the injected clock. Then `rounds = min(2 000 000, max(100 000, floor(100 000 × 1000 / max(ms, 1) / 10 000) × 10 000))`. This desktop (about 0.3 s per 600k) calibrates to the 2M ceiling (≈1 s); a tablet 4× slower lands near 500 000.

**Base64url** everywhere is RFC 4648 §5 **without padding** (`Crypto::b64url` as built). The built `Crypto::unb64url()` is **not** strict: it is `base64_decode(…, true)`, which accepts `=` padding and whitespace, and decodes `AA` and `AB` to the same byte (checked on PHP 8.2.4). So:
- every **tablet-sent** base64url field (`registration_nonce`, `proof_key`, the proof MAC, and each item's `iv`, `ct` and `mac`) is decoded with a new `Crypto::unb64urlStrict(string $text, ?int $bytes = null): ?string`. It returns null unless `$text` matches `^[A-Za-z0-9_-]*$`, `strlen % 4 !== 1`, it decodes, `b64url(decoded) === $text` (the canonical final character), and, when `$bytes` is given, the decoded length is exactly `$bytes`;
- the per-field shape regexes (§6.3 step 4, §8.3 step 1, `DeviceProof::parse()`) stay as a first check; `ct` is `^[A-Za-z0-9_-]{1,90000}$`;
- `Crypto::unb64url()` stays for server-made values (the `g1.` format);
- JS `b64urlDecode()` in `vault.js` is strict in the same way.

### 3.3 Record format (every sealed store)
```json
{ "k": "<store key>", "iv": "<b64url 12 bytes = 16 chars>", "ct": "<b64url ciphertext‖tag>" }
```
- **Plaintext:** UTF-8 JSON (`JSON.stringify`) for `vault_users`, `sessions`, `drafts` and `pack`; the canonical item bytes for `outbox`.
- **AAD:** `pfpms/v1|vault_users|user:<id>`, `pfpms/v1|sessions|<session_uuid>`, `pfpms/v1|drafts|<draft key>`, `pfpms/v1|pack|<pack key>`, `pfpms/v1|outbox|<client_uuid>`. Moving a ciphertext to another slot or store fails the tag. Another tablet's records fail because its DVK differs.
- The `outbox` record adds plaintext index fields beside `iv`/`ct` (§7.3).

### 3.4 Keyring record (plaintext store `keyring`, keyPath `user_id`, multiEntry index `lookup`)
```json
{ "user_id": 12, "lookup": ["3100c858…", "ce049078…"], "grant_id": 345, "grant_expires_at": "2026-10-04 12:00:00.000",
  "salt": "<b64url 16 B>", "iterations": 610000, "iv": "<b64url 12 B>", "wrapped_dvk": "<b64url 48 B>", "v": 1 }
```
- **Written** on every successful online password sign-in, acknowledgement acceptance and forced password change when `offline_allowed`, replacing that person's entry. `lookup` holds `H(username)`, `H(email)` and `H(the identifier as typed at that sign-in)`, de-duplicated (D-29).
- **Deleted** when:
  - its `grant_id` is listed as revoked;
  - its grant has expired;
  - a heartbeat reports `offline_enabled: false`;
  - a failed-unlock wipe or any directive wipe runs.

### 3.5 Vault user record (sealed store `vault_users`, key `user:<id>`)
```json
{ "user_id": 12, "username": "jdoe", "display_name": "Jo Doe", "role": "Volunteer",
  "offline_caps": ["offline.checkin","offline.distribute","offline.pet_edit","offline.register"], "pin_switch": true,
  "grant_id": 345, "grant_hmac_key": "<b64url 32 B>", "grant_issued_at": "…", "grant_expires_at": "…",
  "last_password_at": "…", "pin_verifier": null, "pin_failed": 0, "v": 1 }
```

### 3.6 Canonical JSON v1 and the signed item
**Rules** (identical in `src/Sync/Canonical.php` and `station/js/canonical.js`):
1. **Values:** object, array, string, integer, `true`, `false`, `null`. A number with `.`, `e` or `E`, or outside ±9 007 199 254 740 991, is refused. Decimals travel as strings (`"12.50"`).
2. **Object keys** match `^[a-z][a-z0-9_]{0,63}$`, are unique, and are emitted sorted by byte value.
3. **Strings** must be well-formed (JS `String.prototype.isWellFormed()`; PHP valid UTF-8 from `json_decode`). They are emitted exactly as `JSON.stringify` emits them:
   - `\"`, `\\`, `\b`, `\f`, `\n`, `\r`, `\t` are escaped;
   - other U+0000-U+001F become `\u00xx` (lower-case hex);
   - everything else is literal, including `/`, U+007F, U+2028 and U+2029.

   PHP: `json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR)`.
4. **Layout:** no insignificant whitespace; arrays keep their order; depth ≤16; total ≤65 536 bytes.
5. **Datetimes** are strings `YYYY-MM-DD HH:MM:SS.mmm` in UTC with no offset: `Clock::dbMillis()` in PHP; `new Date(ms).toISOString().slice(0, 23).replace('T', ' ')` in JS.
6. **PHP check:** decode with `json_decode($bytes, false, 17, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)`, so objects stay `stdClass` and `{}` ≠ `[]`. Then `Canonical::isCanonical($bytes)` requires `Canonical::encode(decoded) === $bytes` plus the key and integer checks. Duplicate keys, floats, big integers (decoded as strings) and non-canonical order all fail the round trip.

**Signed item S v1** (the outbox plaintext; every field is required, `null` where empty):
```json
{"client_uuid":"<uuid v4, lower case>","clock_offset_ms":-1520|null,"depends_on":null,"device_id":7,"grant_id":345,
 "kind":"offline_session","payload":{…kind-specific…},"recorded_at":"2026-10-01 11:59:30.250","recorded_by":12,"seq":41,
 "session_id":"<64 hex>"|null,"v":1}
```
- `recorded_at`: the tablet's **raw** clock (`Date.now()`) at recording.
- `clock_offset_ms`: the tablet's trusted estimate of (server − tablet) in ms at recording (§7.4), or `null` when it has none (never contacted, or a rollback was detected since the last contact).
- `session_id`: the `user_session.session_id` the item was recorded under. Online, it is the `session_ref` from the sign-in or PIN response; offline, `hex(SHA-256("offline:" + session_uuid))`.
- `depends_on`: one `client_uuid` of an earlier item of this tablet, or `null` (0004's single column).
- **Derived values:** item bytes = `canonical(S)`; `mac = b64url(HMAC-SHA256(G, bytes))`; `payload_sha256 = hex(SHA-256(bytes))`.
- **Cosign (P4):** reserved as an envelope field `cosign: {user_id, grant_id, mac}` beside `mac`, with `mac = HMAC(the cosigner's grant key, the same bytes)`. P2B requires it absent or `null` (a non-null one is Held `MALFORMED`). This is narrower than the brief §5 hook ("accepted and stored"): `sync_item` has no column for it, and 0013 is settled, so P4 adds the verification and the storage together, in its own migration if it needs a column (recorded in §13.1). No P2B client sends one.

### 3.7 What the server stores and how
| Column | Content | AAD |
|---|---|---|
| `device.token_hash` | `hash('sha256', C_d)` hex | — |
| `device.vault_key_ciphertext` | `Crypto::encrypt(DVK, aad)` (≈86 chars) | `device:<id>:dvk` |
| `device.proof_key_ciphertext` | `Crypto::encrypt(P_d, aad)` (≈86 chars) | `device:<id>:proof` |
| `auth_token.token_hash` (grant) | `Tokens::hash(random_bytes(32))`, a filler | — |
| `auth_token.secret_ciphertext` (grant) | `Crypto::encrypt(G, aad)` (≈86 chars) | `offline_grant:<token_hash>` |
| `sync_item.payload_ciphertext` | `Crypto::encrypt(item bytes, aad)`. For an undecryptable item it is the wire item's canonical JSON. | `sync_item:<client_uuid>` |
| `user_account.pin_hash` | `p1.<kid>.<PasswordPolicy::hash(b64url(HMAC(pepper[kid], "<uid>\|<pin>")))>` (<140 chars) | — |

`crypto.active` encrypts new values. Every retired key id must stay in `crypto.keys` while any of these exists:
- a DVK or proof key made with it (the tablet row, until its wipe is confirmed or the cron clears it);
- a grant (≤168 h, until the purge);
- a sync payload (≤`sync_payload_retention_days`, Held kept);
- a PIN hash (until the PIN is next set).

`config/config.example.php` says so (§12.1).

### 3.8 PHP/JS agreement
- **PHP additions to `Crypto` (S1):**
  - `openRaw(string $key, string $iv, string $sealed, string $aad): string` (AES-256-GCM; `$sealed` = ciphertext‖16-byte tag);
  - `hkdf(string $ikm, string $info, int $length = 32): string` (`hash_hkdf('sha256', $ikm, $length, $info, '')`);
  - `hmac(string $key, string $data): string` (raw);
  - `derivedKey(string $info, ?string $kid = null): array{0: string, 1: string}` (kid, HKDF of that ring key; never the ring key itself).

  The server never seals client records. Tests seal through `tests/Support/StationClient.php` (openssl directly).
- **Fixtures.** Parity is pinned by fixtures read by both suites (§11.3). `station_crypto.json` is generated once by `tests/js/tools/make-station-crypto-fixture.js` with fixed IVs and keys, and committed; both suites assert every value.

### 3.9 Verified vectors (the core of `tests/fixtures/station_crypto.json`)
All values were produced by WebCrypto in Node 24.21.0 and checked by PHP 8.2.4 (`hash_hkdf`, `openssl_decrypt`, `hash_hmac`, `hash_pbkdf2`). The canonical re-encode was checked too.

| Input | Value |
|---|---|
| DVK | bytes `00 01 … 1f` |
| K_rec | `be4682d8c6aaa0a90ce19879e184634a63ef8bf911c4b17e84f855c0bd98d0c3` |
| K_pin | `c943aefe860fad33bb29317214e7ff1c5ad765844914f5083be571e29604922a` |
| S | `{v:1, client_uuid:"123e4567-e89b-42d3-a456-426614174000", device_id:7, seq:41, kind:"test_noop", recorded_by:12, grant_id:345, recorded_at:"2026-10-01 11:59:30.250", clock_offset_ms:-1520, session_id:null, depends_on:null, payload:{note:"Muñoz / ␤ test", n:3}}`, where ␤ is U+2028 (bytes E2 80 A8) |
| canonical bytes | `{"client_uuid":"123e4567-e89b-42d3-a456-426614174000","clock_offset_ms":-1520,"depends_on":null,"device_id":7,"grant_id":345,"kind":"test_noop","payload":{"n":3,"note":"Muñoz / ␤ test"},"recorded_at":"2026-10-01 11:59:30.250","recorded_by":12,"seq":41,"session_id":null,"v":1}` |
| SHA-256 (`payload_sha256`) | `16978510d0c584bb6e5069d1e30dbd72f1c3a7d7da8e4731d175736aec27b8a3` |
| IV (outbox) | bytes `a0 … ab` = `oKGio6Slpqeoqaqr` |
| AAD | `pfpms/v1\|outbox\|123e4567-e89b-42d3-a456-426614174000` |
| ct‖tag | `FHbpiwLqcSALgS0SbGtgeYnMpHLZIIN0tX6oCx_DnLbkznLEAuxTyKv1rDmjWDTFkgsXJ3kE_eApe4sAKCTxw14OgGT-Xooi5zceRgBhw7Ul3G8qnLYxt5mKlaFWP7mkvSvyYhGDz9PLSJLpqm7iHbUN7Y82D4CI7CocAB9eFAXpx74Q0JshXQMHy9d5MRIEbu-0-5jMjmP95MpKqCOF2QtwZxc-96IbEMQmQZXPldIzbIJgie5c_6ccUFS5Rizc5MeDRMJ8sLdW7jWBU9UeGu73XWM-LbhbTOT1wZ9G06Y2EP4BqWbJk2YjotqWtSJrxlj_bor5eCSFh5H6afRJ-THZvqWReWwci__VqFFen82TKqUFv2svNObBDeTa0AedzyevqbKcIg` |
| grant key | bytes `20 21 … 3f` |
| mac | `c76N-plOsFpBIWurwIvrMg5hXeo4tnXzK_MOQ1dnfRw` |
| HMAC of the empty message under the grant key | `9JJAt4qpDlP0YQbDSB43fhlpbndzKLHNbsYGGgvYaKk` |
| PIN verifier (user 12, PIN 4821) | `Q5PkRwSpm9tqSMQQAqAmdbFdkDIbQHQr-tLFcgdCh4k` |
| KEK (password `Correct-Horse-Battery-9`, salt `00 … 0f`, 1000 rounds) | `77bf4c8a9ce1e776bf3251404c04171a131afc7953086b4d2b583edc7310f280` |
| wrapped DVK (IV `b0 … bb` = `sLGys7S1tre4ubq7`, AAD `pfpms/v1\|keyring\|7\|12`) | `zPJN3qw1Sc8GZlaKgpBl1cmfv4r1WoEY0I1HnSos6ZHKdAFjHBl2ATnSYS14id5O` |
| vault_users seal of `{"user_id":12}` (IV `d0 … db` = `0NHS09TV1tfY2drb`, AAD `pfpms/v1\|vault_users\|user:12`) | `KkC1oiWYeTduOCg5e_VqQOk6u2JCzd4QmsRScFL6` |
| keyring lookup, device 7, `JDoe` / `Jo.Doe@Example.test` | `3100c8586fdce9a16f6dde9c46f76400bd7d7d7d98d2ab36c7c74d657b182514` / `ce049078bb399eae938af74e4704aebf2edc7db291988971313946612e1c06c7`. `" JDoe "`, `"JDoe\t"` and `"jdoe"` give the same first value (trim, then lower); the untrimmed `"JDoe "` would give `9bf0ccdf…`, which is why the trim is pinned. |
| proof key | bytes `40 41 … 5f` |
| proof body / SHA-256 | `{"wiped":true,"items_pushed":3}` / `ce21e1ae308295b6d34dd67226f788849300d36244845c946944d44c4cd7fb41` |
| proof message | `pfpms/v1/proof\nPOST\napi/device/heartbeat.php\n1790856000000\nce21e1ae308295b6d34dd67226f788849300d36244845c946944d44c4cd7fb41` |
| proof mac | `PrHNdFtRjYHJBKT1J-yFIQ-VNRXbmGStoEtVXIlfQV0` |
| SHA-256 of an empty body (GET proofs) | `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` |

### 3.10 Rotation, and what a wipe destroys
- **Grant:** a new one at every password sign-in on the tablet, superseding the older one (D-20). Grants are revoked by the credential and access hooks, a PIN change (other tablets), and tablet revocation.
- **Credential, DVK and proof key:** never rotated. A tablet that needs new ones is retired and added again (40 D3).
- **Config keys:** rotated by adding a key id; the old ids stay (§3.7). PIN hashes name their kid.
- **Shift key:** a new one at every password open of the vault; deleted at every hard lock.
- **Hard lock** destroys the in-memory K_rec, K_pin and grant keys and deletes the shift key.
- **Failed-unlock wipe** destroys every wrapped DVK (keyring), `vault_users`, `sessions`, `pack` and the shift key. The outbox, the sealed drafts and `meta` (with the proof key) stay, and the server can still open the outbox.
- **Directive wipe** clears every store but `meta`, confirms, then deletes the database (credential and proof key included) and the caches. On confirmation the server sets `vault_key_ciphertext = NULL` and `proof_key_ciphertext = NULL` and shreds the grant secrets, so nothing anywhere can open a copy of the old database. A tablet that never confirms loses its vault key and grant secrets to the D-55 cron after the retention period; its proof key stays for a late confirmation.

---

## 4. Server data model

### 4.1 Migration `migrations/0013_station_platform.sql` (slice S1; the only P2B migration)
```sql
-- Migration 0013 (v2.1): the offline Station platform (plan P2B; docs/design/50-design-station.md; 40-design D8 reserved 0013).
-- device.pbkdf2_iterations: key-derivation rounds calibrated on the tablet at registration (100000..2000000); NULL means the
--   offline_pbkdf2_iterations setting is used.
-- device.proof_key_ciphertext: the tablet's proof key (made on the tablet at registration), encrypted with the config key ring
--   (AAD device:<id>:proof). Sign-in, PIN and the erase confirmation must be signed with it. Cleared when the erase is confirmed.
-- device.display_mode, storage_estimate_kb, clock_skew_seconds, oldest_pending_at, attention_count, locked_out_since: what the
--   tablet last reported in its heartbeat (clock_skew_seconds = server minus tablet). Display only; NULL means not reported (40 D7).
-- device.shift_ended_at: the last "End shift / Lock device" on the tablet. PIN switching needs a password sign-in after it.
-- auth_token.created_at: when the token was issued. Offline grants are judged "valid at time t" from it, and PIN switching
--   needs a grant issued within pin_shift_hours. NULL for rows made before 0013.
-- sync_item.recorded_at_raw: the time the tablet's own clock showed; recorded_at_client holds the corrected (clamped) time.
-- user_session.auth_method 'Offline PIN': an offline session opened with a PIN ('Offline' stays the password factor).
-- user_session.end_reason 'User Switch': ended by a Station sign-in: another person's password sign-in on the same tablet,
--   or the same person's sign-in or PIN switch on another tablet.
-- New ENUM values are appended; the earlier values are copied unchanged from 0001 and 0012.
-- No table, foreign key or setting is added (66 tables, 167 foreign keys, 68 settings).

ALTER TABLE `device`
  ADD COLUMN `pbkdf2_iterations` INT NULL AFTER `vault_key_ciphertext`,
  ADD COLUMN `proof_key_ciphertext` VARCHAR(255) NULL AFTER `pbkdf2_iterations`,
  ADD COLUMN `display_mode` VARCHAR(20) NULL AFTER `storage_persisted`,
  ADD COLUMN `storage_estimate_kb` INT NULL AFTER `display_mode`,
  ADD COLUMN `clock_skew_seconds` INT NULL AFTER `last_seen_at`,
  ADD COLUMN `oldest_pending_at` DATETIME NULL AFTER `pending_count`,
  ADD COLUMN `attention_count` INT NULL AFTER `oldest_pending_at`,
  ADD COLUMN `locked_out_since` DATETIME NULL AFTER `attention_count`,
  ADD COLUMN `shift_ended_at` DATETIME NULL AFTER `locked_out_since`;

ALTER TABLE `auth_token`
  ADD COLUMN `created_at` DATETIME NULL AFTER `expires_at`;

ALTER TABLE `sync_item`
  ADD COLUMN `recorded_at_raw` DATETIME(3) NULL AFTER `recorded_at_client`;

ALTER TABLE `user_session`
  MODIFY `auth_method` ENUM('Password','PIN','Offline','Offline PIN') NOT NULL,
  MODIFY `end_reason` ENUM('Logout','Timeout','Remote Sign-out','Permission Change','Password Reset','Deactivated','PIN Switch','Device Lock','Device Revoked','User Switch') NULL;
```
Checks against the runner and the guard:
- one statement per `ALTER`;
- no `IF NOT EXISTS`, no display widths, no conditional comments;
- every new column NULL-able, so existing rows and `seeds/dev/004_devices.sql`'s explicit column list keep working;
- the `VARCHAR` columns inherit the table's `utf8mb4_unicode_520_ci`;
- no data conversion, so no warnings on either engine.

A later `ADD COLUMN … AFTER` names a column added earlier in the same statement. Both engines apply the clauses in order; the CI double run is the proof. Run twice on MariaDB 10.4 and MySQL 8.4, then `php bin/schema-check.php`.

### 4.2 Column meanings
| Column | Meaning | Written by | Read by |
|---|---|---|---|
| `device.pbkdf2_iterations` | calibrated rounds (100 000-2 000 000) | `DeviceRepository::register()` | sign-in `release.pbkdf2_iterations`; device page |
| `device.proof_key_ciphertext` | the tablet's proof key, encrypted | `register()`; NULLed by `confirmWipe()` and the cron | `DeviceProof::check()` (narrow query, never in `COLUMNS`) |
| `device.display_mode` | `standalone`, `browser`, `minimal-ui`, `fullscreen` or `other` | registration, heartbeat | the `offline_enabled` formula; device page |
| `device.storage_estimate_kb` | `navigator.storage.estimate().usage / 1024` | heartbeat | display only |
| `device.clock_skew_seconds` | server_now − the tablet's `client_now` at the last heartbeat | heartbeat | device page warning |
| `device.oldest_pending_at` | the oldest unsynced record (the tablet's raw time corrected by the heartbeat skew, ≤ now, whole seconds) | heartbeat | display only |
| `device.attention_count` | outbox items in `conflict` or `invalid` | heartbeat | device page warning |
| `device.locked_out_since` | the tablet ran its failed-unlock wipe at this time; NULL once it reports being usable again | heartbeat | device page warning |
| `device.shift_ended_at` | the last End shift (a replayed offline one at its own time; never moves backwards, §6.8) | `api/auth/logout.php` (`scope: 'device'`) | `OfflineGrants::pinWindow()` |
| `auth_token.created_at` | issue time, `Clock::db()` | `Tokens::issueValue()` (every purpose), `OfflineGrants::issue()` | `OfflineGrants::validAt()`, `pinWindow()`, the clamp |
| `sync_item.recorded_at_raw` | the tablet's raw `recorded_at` | push | review; kept after the payload purge |
| `user_session.auth_method = 'Offline PIN'` | an offline session opened by PIN | `OfflineSessionHandler` | anomalies; US-02 roster (P6) |
| `user_session.end_reason = 'User Switch'` | ended by another person's password sign-in on the tablet, or by the same person's sign-in or PIN switch on another tablet (D-17) | `SessionStore::endOnlineForDevice()`, `endOnlineElsewhere()` | roster |

Existing columns P2B starts to write:
- `device`: `token_hash`, `vault_key_ciphertext`, `is_site_registered`, `registered_by/at`, `offline_enabled`, `storage_persisted`, `last_seen_at`, `last_sync_at`, `pending_count`, `reported_max_seq`, `app_build`, `wiped_at`;
- `auth_token`: `secret_ciphertext` and `revoked_at` for grants;
- `user_account`: `pin_hash`, `pin_failed_count`;
- `sync_item`: every column except `resolved_*` (P3).

Three `sync_item` rules:
- `recorded_at_client` holds the **clamped** time;
- `received_at` is written as `Clock::dbMillis()` (the column default ignores the frozen test clock);
- `origin` is server-derived.

`DeviceRepository::COLUMNS` gains the display-safe columns `d.pbkdf2_iterations, d.display_mode, d.storage_estimate_kb, d.clock_skew_seconds, d.oldest_pending_at, d.attention_count, d.locked_out_since, d.shift_ended_at`, never `proof_key_ciphertext`. `DeviceService::revision()` is unchanged: its field list is explicit, so no heartbeat column makes a form stale.

### 4.3 Pins kept in step (S1)
- **`bin/schema-check.php`:** `EXPECTED_TABLES` 66, `EXPECTED_FOREIGN_KEYS` 167 and the settings count 68 **stay** (their comments gain "0013 adds none"). New checks after the 0012 ones:
  ```php
  check('device has the 0013 columns', (int) col($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device'
      AND COLUMN_NAME IN ('pbkdf2_iterations', 'proof_key_ciphertext', 'display_mode', 'storage_estimate_kb', 'clock_skew_seconds',
                          'oldest_pending_at', 'attention_count', 'locked_out_since', 'shift_ended_at')") === 9);
  check('auth_token has created_at (0013)', (int) col($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'auth_token' AND COLUMN_NAME = 'created_at'") === 1);
  check('sync_item has recorded_at_raw (0013)', (int) col($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sync_item' AND COLUMN_NAME = 'recorded_at_raw'") === 1);
  check("user_session.auth_method includes 'Offline PIN' (0013)", str_contains($columnType('user_session', 'auth_method'), "'Offline PIN'"));
  check("user_session.end_reason includes 'User Switch' (0013)", str_contains($columnType('user_session', 'end_reason'), "'User Switch'"));
  ```
- **`docs/PFPMS_schema_v2_1_changes.md`:** a 0013 section (every column and ENUM value above, with why). The totals line is unchanged apart from "0013: no table, FK or setting". The verification line (`:5`, "0010, 0011 and 0012 were verified the same way on MariaDB 10.4 and MySQL 8.0") gains 0013 and the engines it was double-run on (MariaDB 10.4 and MySQL 8.4).
- **Plan §6:** a 0013 row after plan:448; the counts at plan:452 are unchanged.
- **Plan §12:** plan:651 "`migrations/0002–0012` build on it" becomes `0002–0013`.
- **`src/Reference/settings_registry.php` (S3):** `pin_min_digits` and `pin_max_digits` `max` 8 → 6. There is no seed change. In `tests/Integration/Reference/SettingsServiceTest.php` (factual error #1):
  - `:197` becomes `update(['pin_min_digits' => '5', 'pin_max_digits' => '5'])` (8 would now fail the range);
  - `:186`, `update(['pin_min_digits' => '8'])`, would still be refused with the same error key, but by the range rule instead of the min ≤ max rule it is there to test. It becomes `update(['pin_min_digits' => '6', 'pin_max_digits' => '5'])`: both values are inside the range, and 6 > 5 hits `SettingsService::ORDERED`, which puts the error on `pin_min_digits`.

---

## 5. HTTP layer

### 5.1 `Api::start()` (rewritten in S1)
```php
/**
 * The guard for JSON endpoints under public/api/ (plan §4). Every refusal is thrown as an HttpException, which
 * ErrorHandler renders as the JSON error envelope. Order: maintenance → method → tablet (optional) → session → gates →
 * capability → site → CSRF. Station endpoints authenticate the tablet with 'device' (40-design §14.2) and may require
 * its proof signature with 'proof'; device-only endpoints use 'session' => false.
 *
 * @param array{
 *   method?: string|list<string>,        allowed methods; default 'GET' (405 method_not_allowed + Allow otherwise)
 *   device?: 'in_service'|'known',       read Authorization: PFPMS-Device <credential> first (DeviceGuard)
 *   proof?: bool,                        require the tablet's PFPMS-Proof signature (when the tablet has a proof key)
 *   session?: bool,                      default true; false: no PHP session at all
 *   public?: bool,                       no sign-in needed: null when anonymous, the Context (before the gates) when signed in
 *   touch?: bool,                        default true; false: validating does not extend the idle timer (polling)
 *   capability?: ?string, site?: bool, password_change?: bool, policy_ack?: bool
 * } $options
 */
public static function start(array $options = []): ?Context
/** The tablet authenticated in this request by start(['device' => …]). @throws \LogicException without one */
public static function device(): array
/** start() verifies CSRF for every non-GET request that uses a session, anonymous included. */
public static function needsCsrf(array $options, string $method): bool
/** Steps 8-9 as a pure decision, for tests: whose session this is for this request.
 *  @param ?array $checked what Page::session() returned (null = no session); $ended its reason
 *  @param callable(array $user, int $siteId): bool $canUseSite
 *  @return array{kind: 'tablet'|'web'|'absent', ended: ?string, end_session: bool} */
public static function contextFor(?array $checked, ?string $ended, ?array $device, callable $canUseSite): array
/** The 401 code for an ended reason (§5.2). */
public static function reasonCode(?string $ended): string
```
**Pipeline (exact):**
0. `'session' => false` without `'device'` or `'public' => true` → `LogicException` (a programming error, as in `Page::start`, §5.8): an endpoint with no session must authenticate a tablet or be deliberately public, never neither.
1. `Config::get('app.maintenance') === true` → 503 `maintenance`, `Retry-After: 120`.
2. `Request::method()` ∉ `(array) ($options['method'] ?? 'GET')` → 405 `method_not_allowed`, header `Allow`.
3. `Audit::setActor(null)`; `self::$device = null`.
4. With `device`: `self::$device = DeviceGuard::authenticate(Request::authorization(), $mode, Request::ip(), Request::scriptPath(), !empty($options['proof']))`. It may throw 401/403/410/429, and it sets the actor's site and device (§12.1).
5. `session === false` → return `null` (no PHP session and no CSRF: device-only calls send no cookie the server reads, and ping is a GET). Step 0 guarantees such an endpoint has a `device` guard or is `public`.
6. `WebSession::start(self::$device !== null || Request::header('X-PFPMS-Client') === 'station')`.
7. `$checked = Page::session($options['touch'] ?? true, $ended)`.
8. With a tablet, a session whose `device_id` is not this tablet's is treated as absent (`$ended = 'ended'`): a web session or another tablet's session never counts as this Station's.
9. **Context:**
   - A tablet-bound session (`session.device_id` not null) keeps `site_id = session.site_id` (= `device.site_id`). If the person can no longer use that site: `SessionStore::end($sid, 'Permission Change')`, `WebSession::restart()`, `$ended = 'ended'`, and it is treated as absent. The site is **never** re-picked.
   - A web session uses `Page::pickSite()` (today's rules).

   Steps 8-9 decide through the pure `contextFor()`; `start()` then performs its `end_session` (the `SessionStore::end` + `WebSession::restart`) and builds the Context. So the rules are unit-tested without `session_*` calls (the shim runner has no process isolation).
10. No Context: `public` → CSRF if `needsCsrf()`, return `null`; otherwise 401 with `self::reasonCode($ended)`.
11. `Audit::setActor(uid, sid, siteId, $ctx->deviceId)`. `public` → CSRF if needed, return the Context **before the gates** (as `Page::start`).
12. **Gates, unless passed through:** 403 `password_change_required`, then 403 `policy_ack_required`.
13. **Capability:** `Audit::record('access_denied', 'api', null, 'Denied', "Missing capability $cap", ['endpoint' => Request::scriptPath()])` (no transaction is open) + 403 `forbidden`.
14. **Site:** `site` without one → 409 `site_required`.
15. CSRF if needed; return the Context.

`needsCsrf($o, $m) = ($o['session'] ?? true) !== false && !in_array($m, ['GET', 'HEAD'], true)`. `reasonCode`: `null`/`missing` → `not_signed_in`, `ended` → `session_ended`, `timeout` → `session_timeout`, `account` → `account_blocked`, `device` → `device_revoked`.

The docblock at `Api.php:12-16` is replaced by the one above (C-06).

### 5.2 Error envelope and codes
The shape is `{"error": "<code>", "message": "<plain text>", …extra}`; a 500 adds `"incident": "<8 hex>"`. `ErrorHandler` renders every JSON error (the existing `wantsJson()` test: `/api/` in `SCRIPT_NAME`, or `Accept: application/json`), sends `$e->headers`, and keeps `Cache-Control: no-store`.

| HTTP | Codes | Extra |
|---|---|---|
| 400 | `bad_json`, `csrf_failed`, `bad_request` (+`field`) | — |
| 401 | `not_signed_in`, `session_timeout`, `session_ended`, `account_blocked`, `device_revoked` (session ended because the tablet was revoked), `device_credential_missing`, `device_unknown`, `device_proof_invalid`, `device_proof_stale` | `server_time` on `device_proof_stale` |
| 403 | `forbidden`, `password_change_required`, `policy_ack_required`, `device_revoked`, `device_not_registered`, `device_site_inactive`, `no_station_access` (+`reason`: `site`\|`role`) | `directive` (`null` or `{wipe}`) on the three device codes |
| 405 | `method_not_allowed` | header `Allow` |
| 409 | `busy`, `not_installed`, `policy_changed`, `site_required`, `vault_key_missing`; push `conflict` (the body is the normal push body) | — |
| 410 | `wiped` | `status: 'wiped'`; header `Clear-Site-Data: "cache", "storage"` (Chromium applies it only when the request carried credentials, so the tablet erases by itself as well, §7.6) |
| 413 | `too_large` | — |
| 415 | `unsupported_media_type` | — |
| 422 | `invalid` (+`errors`), `code_mistyped`, `code_invalid`, `login_failed`, `account_locked`, `account_unusable`, `pin_wrong` (+`tries_left`), `pin_locked`, `pin_unavailable`, `pin_rules` (+`errors`) | — |
| 429 | `rate_limited`, `too_many_bad_records` | header `Retry-After: <window seconds>` |
| 500 | `server_error` | `incident` |
| 503 | `maintenance`, `unavailable` | header `Retry-After` on `maintenance` |

**Classes (S1):**
- `HttpException::__construct(public readonly int $status, string $message = '', public readonly ?string $errorCode = null, public readonly array $extra = [], public readonly array $headers = [])`.
  - `defaultMessage()` gains 410 "This is no longer available.", 413 "That is too much to send at once." and 415 "The request was sent in the wrong format."
  - New `codeFor(int $status): string` gives a generic code per status: `bad_request` for 400, `unavailable` for 503, `not_found` for 404 (no row above), and the first code of the row for the others. `bad_json` and `maintenance` are always thrown explicitly.
- `Csrf::verify()` and `verifyOrigin()` throw with `errorCode 'csrf_failed'`.
- `ErrorHandler` gains `public static function statusFor(Throwable $e): int` (HttpException → its status; `ValidationException` → 422; else 500, a `JsonException` included) and `public static function jsonPayload(Throwable $e, string $message, ?string $incident): array`. They are used only in the JSON branch; the HTML branch is unchanged.

The Station's `api.js` maps every non-2xx to `ApiError {kind: 'http', status, code, message, extra}`, except a status the call lists in `okStatuses` (push lists 409, whose body is the normal push body, D-36): that status returns the parsed body like a 200. These become `ApiError {kind: 'offline'}`:
- a network `TypeError`;
- an abort (timeout);
- 502, 503 or 504 (including `maintenance`);
- a reply whose `Content-Type` is not `application/json` (a captive portal).

### 5.3 `Request` and `Json` (S1)
```php
// Request (additions)
/** Authorization header: HTTP_AUTHORIZATION, else REDIRECT_HTTP_AUTHORIZATION (Apache CGI/FastCGI after the rewrite), else getallheaders() (case-insensitive). */
public static function authorization(): ?string
/** Lower-case media type from $_SERVER['CONTENT_TYPE'] (never HTTP_CONTENT_TYPE, which only php -S sets), without parameters; '' if absent. */
public static function contentType(): string
/** The entry point's path under public/, with no query, e.g. "api/device/heartbeat.php": what a tablet's proof signs. */
public static function scriptPath(): string          // SCRIPT_NAME with Request::basePath() removed from its start
/** The raw body, read once from php://input (memoised, at most 1 MiB + 1 bytes); longer than $maxBytes → 413 too_large (CONTENT_LENGTH checked first). json() then applies its own, smaller limit. */
public static function rawBody(int $maxBytes = 1048576): string
/** hex SHA-256 of rawBody() ('' → e3b0…b855). */
public static function bodySha256(): string
/** The JSON object in the body. @throws HttpException 413 too_large | 415 unsupported_media_type | 400 bad_json */
public static function json(int $maxBytes = 65536): array   // parseJson(rawBody($maxBytes), contentType(), $maxBytes)
/** Pure part of json(), for tests. */
public static function parseJson(string $raw, string $contentType, int $maxBytes): array
    // 415 unless 'application/json'; 413 if strlen > max; json_decode(assoc, depth 64, THROW | BIGINT_AS_STRING);
    // 400 bad_json on JsonException, or when the top level is not an object (a non-empty list, a scalar)
/** Tests only: the body and content type json()/rawBody() will see. */
public static function useBody(?string $raw, ?string $contentType = 'application/json'): void

// src/Http/Json.php (new): typed access with no silent casts
final class Json
{
    public static function string(array $data, string $key, int $maxLength = 1000): ?string; // only a JSON string, mb_strlen ≤ max, never trimmed
    public static function int(array $data, string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int; // only an integer (not "5", 5.0, true), in range
    public static function bool(array $data, string $key): ?bool;                             // only true/false
    public static function list(array $data, string $key, int $maxItems = 1000): ?array;      // only a JSON array (array_is_list), ≤ max items
    public static function object(array $data, string $key): ?array;                          // only a JSON object (string keys, or empty)
}
```
`Response::json(mixed $data, int $status = 200, array $headers = []): never` sends each extra header before the body.

### 5.4 `Authorization` pass-through, and proving it on the host
- **`public/.htaccess`:** inside `<IfModule mod_rewrite.c>`, right after `RewriteEngine On`:
  ```apache
  # Pass the Authorization header to PHP (tablet credentials: CGI and FastCGI drop it otherwise). PHP reads
  # HTTP_AUTHORIZATION or REDIRECT_HTTP_AUTHORIZATION (Request::authorization()). Check with bin/station-smoke.php.
  RewriteCond %{HTTP:Authorization} .
  RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
  ```
  `CGIPassAuth` is not used: it needs `AllowOverride AuthConfig` and would turn the whole site into a 500 where that is not allowed.
- **`php -S` (dev)** passes the header as `HTTP_AUTHORIZATION` and ignores `.htaccess`.
- **`api/ping.php` reports `authorization_received`** (whether an `Authorization` header reached PHP; its value is never read). Every Station request carries `X-PFPMS-Client: station`. One arriving at a device endpoint without `Authorization` gets 401 `device_credential_missing` and, at most once an hour (bucket `device_header_missing:all` 1/3600), writes `Audit::record('device_header_missing', 'device', null, 'Failed', …, ['ip' => …, 'endpoint' => …])` and sends `toRoleOnce('Administrator', null, 'device_header_missing', 'Tablets are reaching the server without their key: the web host is removing the Authorization header. Tablets cannot upload until this is fixed (see the hosting notes).', 'device', 0)`.
- **`bin/station-smoke.php <base_url>`** (S1; S2 adds step 5) is a CLI script using PHP streams. It prints PASS/FAIL per check and exits 1 on any FAIL. It never sends a real credential. The checks:
  1. `GET api/ping.php` → 200 JSON with `build`; `Cache-Control` contains `no-store`; `x-proxy-cache`, if present, is `BYPASS` or `MISS`.
  2. `GET api/ping.php` with `Authorization: PFPMS-Device x` → `authorization_received: true`.
  3. `POST api/device/heartbeat.php` **without** `Authorization` → 401 `device_credential_missing`.
  4. `POST api/device/heartbeat.php` with `Authorization: PFPMS-Device pfd1_` + 43 × `A` → 401 `device_unknown` (the header arrived). `device_credential_missing` here means the host strips it: **stop, P2B cannot ship.**
  - As built (S1 review), it also checks that ping's `script_path` is exactly `api/ping.php` (the web root was found), that `api/session.php` sets `PFPMSST` with path `<base>api/`, and that a GET of the heartbeat is 405 with `Allow: POST`; the proxy check needs a real 200, and a network error is printed.
  5. (S2) `HEAD` every precached Station URL. It checks:
     - the `Content-Type` family (`text/javascript` or `application/javascript`; `text/css`; `application/json` or `application/manifest+json`; `image/png`; `text/html` for `station/`): FAIL on a mismatch;
     - on `station/js/app.js` and `station/css/station.css`, `Cache-Control` containing `no-cache`, `no-store` or `max-age=0`: FAIL otherwise. A long browser cache there means the `.htaccess` rule is not reached (SiteGround's NGINX Direct Delivery serves static files in front of Apache, §5.7);
     - `X-Content-Type-Options: nosniff` on static files: WARN, not FAIL (it depends on the same `.htaccess` path and does not affect function);
     - it prints the caching headers it saw (`x-proxy-cache`, `server`, `expires`), so the runbook records the Direct Delivery state.
- **The staging run of this script is mandatory in S1** and is a go-live gate (§11.5).
- `ErrorHandler::log()` never logs headers (as built), and `#[\SensitiveParameter]` marks every credential, proof and code parameter.

### 5.5 Sessions and CSRF for the anonymous Station
- **The Station cookie** is `WebSession::STATION_COOKIE = 'PFPMSST'`, with path `Request::basePath() . 'api/'` and the same flags as `PFPMSSID` (HttpOnly, SameSite=Lax, Secure outside dev/test, lifetime 0). `WebSession::start(bool $station = false)` remembers the choice in `private static bool $station`, `restart()` calls `start(self::$station)`, and `destroy()` clears `session_name()` with the current cookie parameters.
- **CSRF token.** `GET api/session.php` (with `X-PFPMS-Client: station`) starts or reuses the anonymous Station session and returns `Csrf::token()`.
- **Session POSTs** (`register`, `login`, `pin`, `pin_set`, `logout`, `policy`, `password`, `session` touch) send `X-CSRF-Token` and the cookie (`credentials: 'same-origin'`). `Api::start` verifies the token, the Origin and `Sec-Fetch-Site`.
- **Rotation.** `WebSession::login` rotates the token after sign-in and PIN switch, and `WebSession::restart` replaces it after logout or decline; those responses carry the new `csrf`. On 400 `csrf_failed` (for example after a server-side timeout restarted the session) the client re-reads `api/session.php` once and retries once.
- **Dev prerequisite:** `app.base_url` must be exactly `http://localhost:8088` when testing there, or every POST fails the Origin check (`Csrf.php:56-63`).

### 5.6 Rate limits (every `RateLimit::hit()` runs before any transaction)
| Bucket | Limit | Effect |
|---|---|---|
| `device_auth:ip:<ip>` | 20 / 900 s, unknown or malformed credentials only | 429 |
| `device_proof:device:<id>` | 10 / 900 s, failed enforced proofs | 429 |
| `device_unproven:<id>` | 1 / 3600 s | clone alert (X-5), never refuses |
| `device_header_missing:all` | 1 / 3600 s | Administrator alert, never refuses |
| `device_revoked_contact:<id>` | 1 / 3600 s | throttles the audit row and the alert |
| `device_register:ip:<ip>` | 10 / 900 s | 429 (+ durable Denied row) |
| `device_register:all` | 100 / 3600 s, codes that pass `normalise()` | alerts Administrators once (`toRoleOnce('Administrator', null, 'device_register_limit', …, 'device_register', 0)`), never refuses |
| `heartbeat:device:<id>` | 120 / 900 s, proven calls (a valid proof, or a seed row without a key) | 429; for a revoked tablet the 429 still carries `status` and `directive` |
| `heartbeat:device:<id>:unproven` | 120 / 900 s, calls with a missing, invalid or stale proof | 429 (as above). A copied credential, or a signed request replayed from a network log, can never use up the real tablet's heartbeats (S1 review) |
| `device_wipe_claim:<id>` | 1 / 3600 s | throttles the Denied row of an ignored wipe claim (the tablet retries) |
| `login:device:<device_id>` | 30 / 900 s | Station sign-in (replaces `login:ip:`) |
| `login:id:<lower identifier>` | 10 / 900 s | shared with the web sign-in |
| `pin:device:<device_id>` | 60 / 900 s | online PIN switch |
| `pin_set:user:<user_id>` | 5 / 900 s | PIN set re-authentication |
| `pw_change:user:<user_id>` | 5 / 900 s | Station password change |
| `sync_push:device:<id>` | 240 / 900 s | push |
| `sync_status:device:<id>` | 120 / 900 s | status |
| — | more than 200 items from this tablet Held `DECRYPT_FAILED`/`MALFORMED`/`BAD_HMAC` in the last hour | push 429 `too_many_bad_records` + `toRoleOnce('Administrator', null, 'sync_bad_records', …, 'device', id)` |

### 5.7 Cache headers, the build and CSP
- **Cache:** every PHP response keeps bootstrap's `Cache-Control: no-store, no-cache, private, max-age=0` (API, shell, `sw.php`). `public/.htaccess` adds, inside `<IfModule mod_headers.c>` after the 7-day rule:
  ```apache
  <If "%{REQUEST_URI} =~ m#/station/.*\.(js|css|json|png)$#">
    Header set Cache-Control "no-cache"
  </If>
  ```
  `<If>` sections merge after `<FilesMatch>`, so Station static files revalidate. The rule names static extensions only: under Apache, `Header set` also replaces a PHP response's header (mod_php and FastCGI put PHP's headers in the same table), and a match on `station/` alone would turn `index.php`'s and `sw.php`'s `no-store` into `no-cache`. The service worker precaches with `cache: 'reload'` and checks each file's SHA-256 anyway.
- **SiteGround's NGINX Direct Delivery** is on by default for every site. It serves images, CSS, JavaScript and plain HTML from NGINX in front of Apache, refreshes its copy every 3 hours, and is toggled in Site Tools > Speed > Caching. For those files the `.htaccess` header rules above may never apply. Three consequences are handled:
  - **Runbook decision:** Direct Delivery is switched **off** for the PFPMS site before S2 goes to staging, so the no-cache and nosniff rules apply. Smoke step 5 fails while it is on (§5.4). If it must ever be switched back on, its cache is flushed on every deploy.
  - **Mixed builds cannot happen either way:** an uncontrolled page (first start, after Repair, after the kill switch) waits for the verified worker before it imports `app.js`, so every module comes from the verified precache, never from a possibly stale HTTP cache (§7.7).
  - **A stale server copy fails the precache hash check** (safe: the running version stays). `registration.update()` retries after a failed install are throttled to once an hour (§7.7), so tablets do not re-download the precache at every 5-minute heartbeat.
- **SiteGround runbook:**
  - exclude `/api/` and `/station/` from Dynamic Cache and the CDN, and purge on deploy;
  - Direct Delivery off (above);
  - run `bin/station-smoke.php` on staging and on production after each deploy.
- **The build string** comes from `DeviceStatus::currentBuild()` only (D-07; factual error #18). Ping, heartbeat, registration, the sign-in responses, `sw.php` and the admin pages all call it.
- **CSP:** bootstrap's CSP stays for every page. `src/Station/StationShell::sendHeaders(): void` (S2) replaces it on `station/index.php` and `station/sw.php` with:
  ```
  default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self';
  worker-src 'self'; manifest-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none';
  require-trusted-types-for 'script'; trusted-types pfpms-sw
  ```
  The worker also gets `Content-Type: text/javascript; charset=utf-8`. The only Trusted Types policy is `pfpms-sw`, created by `boot.js` to turn exactly `'sw.php'` into a `TrustedScriptURL` for `serviceWorker.register`. Any HTML sink throws on Chromium.
  - **Rule for S2:** if `register()` through the policy fails on the target browsers, drop the last two directives. They are defence in depth; `script-src 'self'` and textContent-only rendering remain.
  - There is no `'wasm-unsafe-eval'` (D-14).
  - `header_remove('X-Powered-By')` is added to `src/bootstrap.php` (the ZAP finding, plan:568).

### 5.8 Page contract and RBAC tests
- **`public/station/index.php` and `public/station/sw.php`:** the first statement is `Page::start(['public' => true, 'session' => false]);`. `Page::start()` gains that option: with `session === false` it calls `Audit::setActor(null)` and returns `null` before `WebSession::start()`. The option requires `public`, and `Page::start` throws `LogicException` otherwise.
- **`public/api/**/*.php`:** the first statement is `Api::start([... 'method' => …])`, assigned to `$ctx` or not. No endpoint uses `Request::isPost()`, so `testPagesThatHandlePostVerifyCsrf` is untouched (C-07). No comment may sit on the `<?php` line, or the first regex fails.
- **New tests in `tests/Contract/PageContractTest.php` (S1):**
  - `testApiEndpointsUseApiStartWithAMethod`: every file under `public/api/` matches `~^\s*(\$ctx\s*=\s*)?Api::start\(\[[^;]*'method'\s*=>~` after the imports;
  - `testPublicApiPostsVerifyCsrf`: a `public/api/` file whose `Api::start` options contain `'public' => true` and a `'POST'` method contains `Csrf::verify()`;
  - `testApiEndpointsNeverTestIsPost`: no `Request::isPost()` under `public/api/`;
  - `testSessionlessApiEndpointsNameADeviceOrPublic`: every `public/api/` file whose `Api::start` options contain `'session' => false` also contains `'device' =>` or `'public' => true` (the static twin of §5.1 step 0);
  - `testNothingSendsCorsHeaders`: no `Access-Control-Allow` under `src/`, `public/` or `templates/`.
- **New tests (S2):**
  - `testStationShellFilesStartNoSession`: `public/station/*.php` start with `Page::start(['public' => true, 'session' => false])`;
  - `testStationShellHasNoInlineScriptOrStyle`: no `<script>` without `src`, no `style=`, no `on[a-z]+=`.
- **RbacTest:** `tests/Unit/RbacTest.php:58` adds `glob("$root/public/*/*/*.php") ?: []`.

### 5.9 No-touch validation
`SessionStore::validate(string $sessionId, bool $touch = true)` is identical except that the `last_activity_at` UPDATE (`SessionStore.php:79-82`) runs only when `$touch`. Ending a timed-out or revoked session still writes, because ending never extends.

`Page::session(bool $touch = true, ?string &$ended = null): ?array` wraps it:
- it restarts the PHP session when the server session has ended, and sets no flash;
- `$ended` is `null` when the PHP session carries no ids;
- otherwise `$ended` is the reason from `validate()` (including `'missing'`).

`Page::resolve()` keeps today's behaviour through it: a flash for every ended reason; none when there were no ids.

`Api::start(['touch' => false])` is used by GET `api/session.php` now and by every P3/P4 polling endpoint later.

---

## 6. Endpoints

**Common to all:**
- bodies are JSON, and every request with a body, device-only included, sends `Content-Type: application/json` (`Request::json()` answers 415 otherwise); responses are `application/json; charset=utf-8` with `no-store`;
- times are `YYYY-MM-DD HH:MM:SS.mmm` UTC (the server sends `Clock::dbMillis()`);
- `build` = `DeviceStatus::currentBuild()`;
- `config` = `StationConfig::client()`:
```json
{"organisation_name":"CHS Pet Pantry","session_idle_minutes":30,"session_absolute_hours":12,"pin_min_digits":4,"pin_max_digits":6,
 "pin_max_failed":3,"pin_shift_hours":12,"offline_grant_hours":72,"offline_max_failed_unlocks":10,"sync_clock_skew_minutes":10,
 "offline_mode_enabled":true}
```
Guard errors (§5.2) apply to every endpoint with that guard and are not repeated. Every audit detail key below passed the `Audit::REDACT` probe.

### 6.1 `GET api/ping.php` (S1)
- **Guard:** `Api::start(['method' => 'GET', 'public' => true, 'session' => false]);`
- **Response:** `{"ok": true, "server_time": "…", "build": "…", "authorization_received": true|false, "script_path": "api/ping.php"}`. `script_path` is what a proof signs on this host; anything else means the base path was not found (`app.base_path` must be set), which the smoke script fails on.
- No session, no database, no audit, no touch.

### 6.2 `GET|POST api/session.php` (S1)
- **Guard:** `$ctx = Api::start(['method' => ['GET', 'POST'], 'public' => true, 'touch' => Request::method() === 'POST']);`. Public, so the Context comes back before the gates. POST then calls `Csrf::verify()` explicitly and requires `{"action": "touch"}` (anything else gives 400 `bad_request`).
- **Service:** `Pfpms\Station\SessionInfo::describe(?Context $ctx): array` (one `SELECT started_at, last_activity_at FROM user_session WHERE session_id = ?`).
- **Response:**
  ```json
  {"csrf":"<64 hex>","organisation_name":"…","server_time":"…","build":"…","dev_relax":false,
   "user":null | {"user_id":12,"username":"jdoe","display_name":"Jo Doe","role":"Volunteer","capabilities":["…"]},
   "session":null | {"session_ref":"<64 hex>","device_id":7|null,"site_id":3|null,"auth_method":"Password",
                     "idle_seconds_left":1500,"absolute_seconds_left":40000},
   "gate":null|"password_change"|"policy_ack"}
  ```
- The Station treats a session whose `device_id` differs from its own as "not signed in here". GET never touches `last_activity_at`; POST touches it (at most once a minute, `TOUCH_INTERVAL`).

### 6.3 `POST api/device/register.php` (S1; 40 §14.3 + X-3)
- **Guard:** `Api::start(['method' => 'POST', 'public' => true]);`, then an explicit `Csrf::verify();` (40 §14.3 step 1; `Api::start` has already checked it).
- **Request:**
  ```json
  {"code":"K7QM2XRD9VHPC4TNS","registration_nonce":"<22 b64url>","proof_key":"<43 b64url>","display_mode":"standalone",
   "storage_persisted":true,"app_build":"0.1.0-dev+ab12cd34ef","pbkdf2_iterations":610000}
  ```
- **Service:** `DeviceRegistration::redeem(?string $code, ?string $nonce, ?string $proofKey, array $facts, string $ip): array`.
- **Order:**
  1. Bucket `device_register:ip`. Over it: a durable Denied row `{reason_code: 'rate_limited', ip}`, then 429.
  2. `RegistrationCode::normalise($code)`. Null gives 422 `code_mistyped` ("Check the code: one of the characters looks wrong."), and nothing is recorded.
  3. The global bucket (alert only).
  4. The nonce must match `^[A-Za-z0-9_-]{22}$` and `Crypto::unb64urlStrict($nonce, 16)` must succeed; the proof key must match `^[A-Za-z0-9_-]{43}$` and `Crypto::unb64urlStrict($proofKey, 32)` must succeed (§3.2); else 400 `bad_request`.
  5. `display_mode !== 'standalone'` and not relaxed gives 409 `not_installed` ("Open the installed app from the home screen, then scan the code again.").
  6. `Tokens::find($canonical, Tokens::DEVICE_REGISTRATION)` with no lock. If null: `DeviceRegistration::replay()` (below); if that fails too, a durable Denied row `not_found` and 422 `code_invalid`.
  7. `DeviceLocks::device(id)`. When busy: 409 `busy` ("Someone else is changing this tablet right now. Wait a few seconds and try again.").
  8. `Db::transaction`:
     - `DeviceRepository::lock()` → `DeviceStatus::waitingForRegistration()`;
     - `AccountRepository::lockShared(creator)` → `canHoldSession ∧ ¬mustChangePassword ∧ DeviceScope::forUser()->can('device.register') ∧ canAddAt(site)`;
     - `Tokens::consume() === true`;
     - the derived credential (§3.1), a DVK, and the proof key ciphertext;
     - `DeviceRepository::register()` (`… AND wiped_at IS NULL`);
     - audit;
     - notification.
- **Lock order:** named `device:<id>` → device row → `user_account` (shared) → `auth_token` (consume) → `device` UPDATE (the same row).
- **Response 200:** `{"device_id":7,"site":{"site_id":3,"name":"Dev Site North"},"label":"Front desk 3","credential":"pfd1_…","pbkdf2_iterations":610000,"replayed":false,"server_time":"…","build":"…"}`. Never the DVK, a grant or a pack. `offline_enabled` stays 0.
- **Replay** (`replay(string $code, string $nonce, string $proofKey, array $facts): ?array`). A 200 with `replayed: true` when all of these hold:
  - `Tokens::findAny($code, DEVICE_REGISTRATION)` is used and names a device;
  - `byCredentialHash(Tokens::hash(credentialFor(token_id, nonce)))` is that device, in service at an active site;
  - `registered_at ≥ now − 15 min`;
  - `hash_equals(decrypt(proof_key_ciphertext), decoded proof_key)`.

  It writes `Audit::record('device_register_replay', 'device', $id, details: ['issued_by' => …], actor: ['site_id' => …, 'device_id' => $id])` with no transaction open.
- **Refusals after the format checks:** one 422 `code_invalid` ("This code is not valid. It may have expired or already been used. Ask a Coordinator for a new registration sheet."). After the lock and transaction are gone it writes `Audit::durable('device_register', 'device', $deviceId|null, 'Denied', 'Tablet registration refused', ['reason_code' => 'not_found'|'device_not_waiting'|'issuer_not_allowed'|'code_used', 'ip' => $ip])`. The actor is public, so no FK column is set and there is no lock wait.
- **Audit (success, inside the transaction):** `Audit::record('device_register', 'device', $id, details: ['issued_by' => creatorId, 'app_build' => …, 'display_mode' => …, 'storage_persisted' => bool, 'pbkdf2_iterations' => int|null], actor: ['site_id' => siteId, 'device_id' => $id])`. Never the code, a `token_id`, the credential, the nonce, the proof key or a hash.
- **Notification:** `toUser($creatorId, 'device_registered', '{label} at {site} was registered with the code you created. If that was not your tablet, retire it at once.', 'device', $id)`.

### 6.4 `POST api/device/heartbeat.php` (S1; 40 §14.4)
- **Guard:** `Api::start(['method' => 'POST', 'device' => 'known', 'session' => false]);`. Bucket `heartbeat:device:<id>` 120/900.
- **Request** (every field typed by `Json`; an invalid optional field becomes null; unknown fields are ignored):
  ```json
  {"app_build":"…","client_now":"…","storage_persisted":true,"display_mode":"standalone","pending_count":3,"attention_count":0,
   "max_seq":41,"oldest_pending_at":"…"|null,"storage_estimate_kb":812|null,"locked_out_since":null|"…",
   "failed_unlock_wipe":false,"clock_rollback":false,
   "auth_failures":[{"user_id":12|null,"factor":"password"|"pin","count":2,"first_at":"…","last_at":"…"}],
   "wiped":false,"items_pushed":null}
  ```
  - **Bounds:** `app_build` `^[0-9A-Za-z.+_-]{1,40}$`; `display_mode` in {`standalone`, `browser`, `minimal-ui`, `fullscreen`}, else `other`; `pending_count` and `attention_count` 0..10 000 000; `max_seq` 0..2 147 483 647; `storage_estimate_kb` 0..2 147 483 647; `auth_failures` ≤20 groups (extras ignored), `count` 1..1000; `items_pushed` 0..10 000 000.
  - **Meanings:** `pending_count` = outbox records in `queued`, `conflict` or `invalid` (not stored on the server); `attention_count` = `conflict` + `invalid`; `max_seq` = `meta.seq.next − 1`.
  - **Defaults for NOT NULL columns:** a missing or invalid `pending_count` keeps the stored value (`COALESCE(?, d.pending_count)`), and a missing or invalid `storage_persisted` counts as `false`. `device.pending_count` and `storage_persisted` are `NOT NULL` (0003:17, :20), so a lenient null must never reach them.
  - **Times sent by the tablet** (`client_now`, `oldest_pending_at`, `locked_out_since`, and `first_at`/`last_at` in `auth_failures`) are the tablet's raw clock, formatted `YYYY-MM-DD HH:MM:SS.mmm`, and parsed with `Clock::fromClient()` (column-precision UTC text, else null). `oldest_pending_at` and `locked_out_since` then get the heartbeat skew added when it is known, are bounded to ≤ now, and are bound as `Clock::db($t)`: those columns are `DATETIME(0)`, and binding millisecond text into them would round on MySQL (possibly past now) and truncate on MariaDB (30 #7).
  - The tablet clears `auth_failures`, `failed_unlock_wipe` and `clock_rollback` only after a 200.
- **Service:** `DeviceHeartbeat::receive(array $device, array $body): array{status:int, body:array, headers:array}`.
- **Wipe confirmation** (`wiped: true`):
  - **From a revoked tablet whose proof (from the guard, `$device['proof']`) is `valid`:** `Db::transaction`:
    - `DeviceRepository::lock()` → still revoked and not wiped → `DeviceRepository::confirmWipe()`;
    - `DeviceRepository::shredGrantSecrets()`;
    - `Audit::record('device_wiped', 'device', $id, details: ['wipe_mode' => …, 'items_pushed' => int|null, 'reported_pending_count' => row pending_count, 'grant_keys_cleared' => n], actor: ['user_id' => null, 'session_id' => null, 'site_id' => siteId])`.

    Answer 200 `{"status":"wiped","server_time":"…","build":"…"}` + `Clear-Site-Data: "cache", "storage"`. The tablet sends this confirmation with `credentials: 'same-origin'`, because Chromium ignores `Clear-Site-Data` on the reply to a request sent without credentials (checked in Chromium 152: with `credentials: 'omit'` the database and caches survived; with `'same-origin'` they were removed and the page's open IndexedDB connection was closed). The header stays an extra layer; the tablet's own deletion (§7.6) does not depend on it.
  - **Proof `stale`:** 401 `device_proof_stale` + `server_time` (the tablet re-bases its offset and retries).
  - **Proof `invalid` or `missing`, or the row has no proof key (seed):** the claim is ignored. `Audit::record('device_wipe_claim', 'device', $id, 'Denied', 'An erase confirmation was not signed by the tablet', ['reason_code' => 'no_proof'|'no_proof_key'])` is written with no transaction open, and the normal path runs. A seed tablet's key is cleared by the cron.
  - **From a tablet in service:** ignored, audited the same way (`reason_code 'in_service'`), and the normal path runs.
- **Normal path:**
  1. `$eligible = site_active ∧ (storage_persisted ∨ relaxed) ∧ (display_mode = 'standalone' ∨ relaxed) ∧ Settings::bool('offline_mode_enabled', true)`.
  2. `$skew = server_now − client_now` in seconds (null if absent or |skew| > 7 days).
  3. `Db::transaction`:
     - `DeviceRepository::heartbeat($id, $report, $eligible, $skew, Clock::db())` (the UPDATE locks the row, and in service is evaluated in SQL) → `DeviceRepository::lock($id)` re-read;
     - audit rows (below).
  4. After the transaction: `max_seq` below the guard row's `reported_max_seq` → `DeviceGuard::noteUnproven($device, Request::scriptPath(), 'seq_went_back')` (X-5).
- **Audit rows on the normal path:**
  - `Audit::record('device_state', 'device', $id, changes: Audit::diff($before, $after, ['storage_persisted', 'app_build', 'display_mode', 'offline_enabled', 'locked_out_since']), redactChanges: false)` only when something changed (REQ-12);
  - `failed_unlock_wipe: true` → `Audit::record('device_unlock_wipe', 'device', $id, 'Success', 'The tablet erased its offline sign-ins after too many wrong passwords; its unsent records were kept', ['pending_count' => n])`;
  - `clock_rollback: true` → `Audit::record('device_clock_rollback', 'device', $id, 'Denied', 'The tablet clock was set back')`;
  - `auth_failures` present → **one** `Audit::record('offline_auth_failures', 'device', $id, 'Failed', 'Wrong password or PIN while offline (reported by the tablet)', ['failures' => [{user_id, factor, count, first_at, last_at}…]], actor: ['user_id' => null, 'session_id' => null, 'occurred_at' => clamp(latest last_at)])`. The clamp is `min(at + skew, server_now)`, and at least `server_now − 30 days`.

  These rows never touch `failed_login_count` or `pin_failed_count`.
- **Response 200:**
  ```json
  {"status":"ok"|"revoked","offline_enabled":true,"label":"Front desk 3","site":{"site_id":3,"name":"Dev Site North"},
   "directive":null|{"wipe":"Push Then Wipe"|"Wipe Now"},"revoked_grants":[301,299],"config":{…},"server_time":"…","build":"…"}
  ```
  Never `Clear-Site-Data` here: a Push Then Wipe tablet must still upload.
- The guard writes the revoked-contact row and alert (`DeviceGuard::revokedContact()`) for every endpoint a revoked tablet calls.
- The heartbeat never writes `label`, `site_id`, `registered_*`, `revoked_*`, `wipe_mode`, `is_site_registered`, `token_hash` or `vault_key_ciphertext` (outside the confirmation), so `DeviceService::revision()` never changes.

### 6.5 `POST api/auth/login.php` (S3)
- **Guard:** `$ctx = Api::start(['method' => 'POST', 'device' => 'in_service', 'proof' => true, 'public' => true]);`, then an explicit `Csrf::verify();`.
- **Request:** `{"identifier":"jdoe","password":"…"}` (`Json::string` ≤254 and ≤1024; an empty value gives 422 `login_failed` "Enter your username (or email) and password.").
- **Service:** `StationAuth::login(string $identifier, string $password, string $ip, array $device): array`.
- **Flow:** `Auth::attemptStation()`:
  - buckets `login:device:<id>` and `login:id:<lower>`;
  - lookup; dummy hash; password; `blockReason`;
  - `$access` = `StationGate::access()` (site access at `device.site_id`, any `offline.*`);
  - the success transaction.
- **Success transaction and lock order:**
  1. `DeviceRepository::lockShared($id)` (LOCK IN SHARE MODE, no join), then a re-check that the row is in service and `SiteRepository::find(site)` is active (a plain read). If not: throw → rollback → 403 `device_revoked` + `directive`.
  2. `UPDATE user_account` (the failed count, the lock, `last_login_at`, `pin_failed_count = 0`, rehash).
  3. `StationGate::release($user, $device)`: the gates, then `OfflineGrants::issue()` (`INSERT auth_token`) + audit `offline_grant_issue`.
  4. `SessionStore::endOnlineForDevice($deviceId, 'User Switch', $uid)`, then `SessionStore::endOnlineElsewhere($uid, $deviceId, 'User Switch', $uid)` (the person's online Station sessions on other tablets, D-17).
  5. `SessionStore::create($uid, $siteId, 'Password', $deviceId)`.
  6. `Audit::record('login', 'user_account', $uid, 'Success', null, ['ip' => …, 'station' => true, 'sessions_elsewhere_ended' => n], actor: [...])`.

  No `auth_token` write follows a `user_session` write.
- **After the commit:** the endpoint calls `WebSession::login($uid, $sid, $siteId)`. There is no overlap check at sign-in: it cannot see activity that has not happened yet, and step 4 has ended the person's sessions elsewhere (D-50).
- **Response 200:**
  ```json
  {"ok":true,"csrf":"…","user":{"user_id":12,"username":"jdoe","email":"jo.doe@example.test","display_name":"Jo Doe","role":"Volunteer",
     "capabilities":["…"],"offline_caps":["offline.checkin","…"],"pin_switch":true,"has_pin":true},
   "session_ref":"<64 hex>","gate":null|"policy_ack"|"password_change",
   "release":null|{"dvk":"<b64url 32 B>","grant_id":345,"grant_hmac_key":"<b64url 32 B>","grant_issued_at":"…",
                   "grant_expires_at":"…","pbkdf2_iterations":610000,"offline_allowed":true},
   "release_unavailable":null|"no_vault_key"|"no_grant_possible","revoked_grants":[…],"config":{…},"server_time":"…","build":"…"}
  ```
  Never `password_hash`, `pin_hash` or any token hash: the core unsets `password_hash`, and `StationAuth::publicUser()` whitelists the fields. `email` is sent so the tablet can compute the second keyring lookup; the third is the identifier the tablet itself sent (D-29).
- **Errors:**
  - 422 `login_failed` ("That username or password is not correct.");
  - 422 `account_locked` ("This account is locked. Please try again later or contact an Administrator.");
  - 422 `account_unusable` (inactive, expired or not started: "This account cannot be used at the moment. Please contact an Administrator.");
  - 403 `no_station_access` + `reason` (`site`: "You don't have access to {site}, where this tablet is used."; `role`: "Your role does not use the Station.");
  - 429 `rate_limited`.
- **Audit:**
  - the shared core's `login` rows, with the device from `setActor` (Denied "No access to this tablet's site or the Station" for `access` refusals);
  - `offline_grant_issue {grant_id, expires_at, capped_by, offline_allowed}`.

### 6.6 `POST api/auth/pin.php` (S3)
- **Guard:** `$ctx = Api::start(['method' => 'POST', 'device' => 'in_service', 'proof' => true, 'public' => true]);`, then an explicit `Csrf::verify();`. No live session is needed: the previous person's session may have timed out.
- **Request:** `{"user_id":12,"pin":"4821"}`. `pin` must match `^\d{4,6}$` within the settings, else 422 `pin_wrong` with no counter change.
- **Service:** `StationAuth::pinSwitch(int $userId, string $pin, array $device, ?Context $ctx, string $ip): array`.
- **Order:**
  1. Bucket `pin:device:<id>` (429).
  2. `Pin::target($userId)`: the account plus `pin_hash` and `pin_failed_count` (a narrow select).
  3. Refusals, as 422 `pin_unavailable` ("Quick switching is not available for this person on this tablet now. Sign in with your password."):
     - not found;
     - `!canHoldSession`, `mustChangePassword` or `acknowledgementRequired`;
     - no `auth.pin_switch`;
     - a `StationGate::access()` refusal;
     - `pin_hash` NULL;
     - no `OfflineGrants::pinWindow($uid, $deviceId, pin_shift_hours)`. The window counts only grants, so an offline password unlock (which makes none) does not open it (D-24).
  4. **Reserve the attempt** before any verification, in its own short `Db::transaction` (`Pin::reserveAttempt($uid, $max)`): `UPDATE user_account SET pin_failed_count = pin_failed_count + 1 WHERE user_id = ? AND pin_failed_count < ?` (bound `pin_max_failed`). 0 rows → 422 `pin_locked` ("Too many wrong PINs. Sign in with your password."). Otherwise `SELECT pin_failed_count FROM user_account WHERE user_id = ?` in the same transaction (the row is locked by the UPDATE) gives `tries_left = pin_max_failed − count`. The conditional UPDATE is atomic, so parallel requests cannot all read the same count and pass the limit (the Argon2id verify takes ≈0.5 s). A request that dies after its reservation leaves the attempt counted (it fails closed).
  5. `Pin::verify($uid, $pin, $pinHash)`, with no transaction open.
- **Wrong PIN:** the attempt is already counted. `Audit::record('pin_switch', 'user_account', $uid, 'Failed', 'Wrong PIN', ['reason_code' => 'wrong_pin', 'tries_left' => n])` with no transaction open → 422 `pin_wrong` + `tries_left`.
- **Success transaction:**
  1. `DeviceRepository::lockShared($id)` + re-check (as §6.5).
  2. `UPDATE user_account SET pin_failed_count = 0` (this also releases the reservation).
  3. `SessionStore::endOnlineForDevice($deviceId, 'PIN Switch', $uid)`, then `SessionStore::endOnlineElsewhere($uid, $deviceId, 'User Switch', $uid)` (D-17).
  4. `SessionStore::create($uid, $siteId, 'PIN', $deviceId)`.
  5. `Audit::record('pin_switch', 'user_account', $uid, 'Success', null, ['from_user_id' => $ctx?->userId(), 'grant_id' => window grant])`.

  There is no `auth_token` write at all. Then `WebSession::login()` (regenerate + CSRF rotate).
- **Refusal rows** (`pin_unavailable`, `pin_locked`): `Audit::record('pin_switch', …, 'Denied', …, ['reason_code' => …])`, with no transaction open.
- **Response 200:** `{"ok":true,"csrf":"…","user":{…},"session_ref":"…","revoked_grants":[…],"server_time":"…"}`. **No keys** (factual error #26).

### 6.7 `POST api/auth/pin_set.php` (S3; D-22)
- **Guard:** `$ctx = Api::start(['method' => 'POST', 'device' => 'in_service', 'capability' => 'auth.pin_switch']);` (a Station session of this tablet is required).
- **Request:** `{"password":"…","pin":"4821","pin_confirm":"4821"}`.
- **Service:** `Pin::set(Context $ctx, array $device, string $password, string $pin, string $confirm): array`.
- **Order:**
  1. Bucket `pin_set:user:<uid>` 5/900 (429).
  2. `Auth::verifyPassword()`. False → `Audit::record('pin_set', 'user_account', $uid, 'Failed', 'Wrong password')` → 422 `login_failed` ("That password is not correct.").
  3. `Pin::problems($pin, $confirm)` → 422 `pin_rules` `{errors: {pin: "Use 4 to 6 digits." | "Choose a PIN that is harder to guess than 1234 or 0000." | "The two PINs are different."}}`.
- **Transaction:**
  - `UPDATE user_account SET pin_hash = ?, pin_failed_count = 0 WHERE user_id = ?`;
  - if a PIN existed: `OfflineGrants::revokeForUser($uid, 'pin_change', $deviceId)` (other tablets only; audited `offline_grant_revoke`, §12.3);
  - `Audit::record('pin_set', 'user_account', $uid, 'Success', null, ['changed' => bool, 'digits' => n])`.
- **Lock order:** `user_account` → `auth_token`.
- **Response:** `{"ok":true,"revoked_grants":[…]}`. The tablet then stores the new verifier in `vault_users[u]` (the vault is open).

### 6.8 `POST api/auth/logout.php` (S3)
- **Guard:** `$ctx = Api::start(['method' => 'POST', 'device' => 'known', 'public' => true]);`, then an explicit `Csrf::verify();`. The `known` mode lets a retiring tablet end the shift.
- **Request:** `{"scope":"user"|"device","ended_at":null|"…"}`. `ended_at` is sent only when the tablet replays an End shift made offline (`meta.pending_shift_end.at`, its `serverNow()` at that moment, §7.5); an online End shift omits it.
- **`user`:** with a session, `SessionStore::end($sid, 'Logout', $uid)` + `Audit::record('logout', 'user_account', $uid)`; without one, nothing.
- **`device`** ("End shift / Lock device"):
  - `$at` = now, or for a replay `min(Clock::fromClient(ended_at), now)` (an unparsable `ended_at` counts as now);
  - in one `Db::transaction`:
    - `DeviceRepository::setShiftEnded($deviceId, Clock::db($at))`: `UPDATE device SET shift_ended_at = GREATEST(COALESCE(shift_ended_at, ?), ?) WHERE device_id = ?` (the row lock). It never moves backwards;
    - `SessionStore::endOnlineForDevice($deviceId, 'Device Lock', $replay ? null : $ctx?->userId(), $replay ? Clock::db($at) : null)`: a replay ends only online sessions **started before** `$at`, and names no `ended_by` (the person now at the tablet may not be the one who ended the shift);
    - `Audit::record('device_lock', 'device', $deviceId, details: ['sessions_ended' => n, 'replayed' => bool])`.

  This runs whatever sessions are open (D-24). A replay that arrives after someone else signed in online therefore neither ends that session nor closes that person's PIN window (their grant is newer than `$at`).
- Then `WebSession::restart()`. **Response:** `{"ok":true,"csrf":"…"}`.

### 6.9 `POST api/auth/password.php` (S3; D-54)
- **Guard:** `$ctx = Api::start(['method' => 'POST', 'device' => 'in_service', 'proof' => true, 'password_change' => true, 'policy_ack' => true]);`. The proof is required because the answer carries `release` (the DVK and a grant key, X-1).
- **Request:** `{"current_password":"…","new_password":"…"}`.
- **Service:** `StationAuth::changePassword(Context $ctx, array $device, string $current, string $new, string $ip): array`.
- **Checks:**
  - bucket `pw_change:user:<uid>` 5/900;
  - `Auth::verifyPassword()` false → `Audit::record('password_change', …, 'Failed', 'Wrong current password')` → 422 `login_failed`;
  - `hash_equals($current, $new)` → 422 `invalid` `{errors: {new_password: "Choose a password different from your current one."}}`;
  - `PasswordPolicy::check($new, $ctx->user)` problems → 422 `invalid` (the messages on `new_password`).
- **Tx1:** `Auth::setPassword($uid, $new, 'Password Reset', $ctx->sessionId)` (it revokes the person's grants, codes and links and ends the other sessions) + `Audit::record('password_change', 'user_account', $uid, 'Success', $forced ? 'Forced change' : null)`. Then `WebSession::regenerate()`.
- **Tx2 (separate):** a grant INSERT may not follow Tx1's `user_session` updates. It runs `DeviceRepository::lockShared` + re-check → `StationGate::release()`.
- **Response:** `{"ok":true,"gate":null|"policy_ack","release":…|null,"csrf":"…"}`. The tablet wraps the DVK with the **new** password's KEK.

### 6.10 `GET|POST api/auth/policy.php` (S3; D-54)
- **Guard:** `$ctx = Api::start(['method' => ['GET', 'POST'], 'device' => 'in_service', 'proof' => true, 'policy_ack' => true]);`. The proof is required because an acceptance answers with `release` (X-1); a GET's proof signs the empty body.
- **GET:** `{"required":true|false,"document":null|{"document_id":5,"version":"2","language":"en","body":"…","fingerprint":"<64 hex>"}}`, from `Policy::current(Policy::CONFIDENTIALITY)` and `Policy::fingerprint()`.
- **POST** `{"document_id":5,"fingerprint":"…","decision":"accept"|"decline"}` → `StationAuth::decidePolicy(Context $ctx, array $device, int $documentId, string $fingerprint, string $decision): array`.
  - **Mismatch** with the current document → 409 `policy_changed` ("The agreement was updated while you were reading it. Please read the current version.").
  - **Accept:** `Db::transaction`: `DeviceRepository::lockShared` + re-check → `Policy::acknowledge()` → `Audit::record('policy_acknowledge', 'policy_document', id, 'Success', null, ['doc_type', 'version', 'language', 'source' => 'station'])` → `StationGate::release()` (a grant if every gate now passes) → commit. Then `WebSession::regenerate()`. Response `{"ok":true,"release":…|null,"gate":null|"password_change","csrf":"…"}`.
  - **Decline:** `Audit::record('policy_decline', …, 'Denied', 'Declined the confidentiality agreement')` → `SessionStore::end($sid, 'Logout', $uid)` → `WebSession::restart()` → `{"ok":true,"signed_out":true,"csrf":"…"}`. No keys were released, so no keyring exists.

### 6.11 `POST api/sync/push.php` (S5)
- **Guard:** `Api::start(['method' => 'POST', 'device' => 'known', 'session' => false]);`, then `set_time_limit(120)`. Bucket `sync_push:device:<id>` 240/900; then the bad-record cap (§5.6).
- **Request:** `Request::json(1048576)`:
  ```json
  {"v":1,"batch_uuid":"<uuid>","client_now":"…","build":"…","rescue":false,
   "items":[{"client_uuid":"<uuid>","seq":41,"iv":"<16 chars>","ct":"<b64url ≤ 90 000 chars>","mac":"<43 chars>","cosign":null}]}
  ```
  `items` holds 1..50 (more gives 413 `too_large`); `v` must be 1 (else 400 `bad_request`).
- **Errors (whole request):** `vault_key_ciphertext` NULL (the unconfirmed-wipe cron ran) → 409 `vault_key_missing` ("This tablet's records can no longer be uploaded because its key was removed from the server. Ask an Administrator.").
- **Service:** `SyncService::push(array $device, array $body): array{status:int, body:array}` (§8).
- **Response 200** (409 when any item is `conflict`):
  ```json
  {"server_time":"…","skew_ms":-1250,"directive":null|{"wipe":"…"},"revoked_grants":[…],
   "items":[{"client_uuid":"…","status":"accepted"|"accepted_with_exception"|"held"|"resolved"|"discarded"|"conflict"|"retry"|"invalid"|"refused",
             "reason_code":null|"…","message":null|"…","participant_id":null,"participant_code":null,"pet_id":null,
             "distribution_id":null,"next_eligible_date":null}]}
  ```
- **Transactions:** one per item, each starting with `DeviceRepository::lock()` (FOR UPDATE). Never the named lock `device:<id>`.
- **Audit:**
  - handler rows inside the item transactions;
  - one `Audit::record('sync_push', 'device', $id, 'Success', null, ['items' => n, 'accepted' => n, 'held' => n, 'conflicts' => n, 'retries' => n, 'rescue' => bool, 'skew_seconds' => n, 'build' => …])` per request, after the items;
  - `Audit::record('sync_conflict', 'device', $id, 'Failed', 'A record id was sent again with different content', ['client_uuid' => …, 'payload_sha256' => …])` per conflict, after its transaction.
- **Notifications:** §8.9.

### 6.12 `GET api/sync/status.php?uuids=<uuid>,<uuid>,…` (S5)
- **Guard:** `Api::start(['method' => 'GET', 'device' => 'known', 'session' => false]);`. Bucket `sync_status:device:<id>` 120/900.
- ≤50 valid uuids (others are ignored). The proof signs `GET`, `api/sync/status.php` and the empty body.
- **Response:** `{"items":[{"client_uuid":"…","status":"held"|"resolved"|"discarded"|"accepted"|"accepted_with_exception","reason_code":"…"|null,"resolved_at":"…"|null}],"server_time":"…"}`. Only rows with `device_id` = this tablet. No audit, no names, no messages.

---

## 7. Station client

### 7.1 File layout (`public/station/`)
```
index.php            shell: Page::start public, session false; StationShell::sendHeaders(); <html data-build="…">;
                     <script type="module" src="boot.js?v=<build>">; redirects /station (no slash) to station/index.php under php -S
sw.php               worker: Page::start public, session false; StationShell::sendHeaders(true); emits
                     "const BUILD = …; const PRECACHE = [{path, sha256, type}…];" then js/sw-core.js (or the kill worker)
manifest.json        static; name = RegistrationSheet::APP_NAME; start_url/scope "./"; display standalone; icons
css/station.css
icons/icon-192.png  icons/icon-512.png  icons/icon-maskable-512.png  icons/apple-touch-icon.png   (bin/station-icons.php, GD)
boot.js              watchdog (15 s), Trusted Types policy 'pfpms-sw', SW registration, the first-start wait for the verified
                     worker (§7.7), import('./js/app.js'), Try again / Repair (online only)
js/app.js            composition root, tablet state machine, primary-window lock (X-2), user-activity listener, touch, update flow,
                     the dev-only hooks window.__pfpms.debug (§7.2)
js/router.js         hash routes → views
js/dom.js            el(tag, attrs, ...children) with textContent only; clear(node)
js/env.js            environment adapter (display mode, storage, clock, online/visible events, BarcodeDetector, locks)
js/api.js            transport, CSRF, proof header, error map, offline detection, timeouts
js/db.js             IndexedDB adapter (schema v1) + the adapter interface
js/clock.js          server offset, high-water clock, jump detection, serverNow()
js/canonical.js      canonical JSON v1, datetime format/parse                         (parity)
js/vault.js          KEK, calibration, wrap/unwrap, HKDF sub-keys, seal/open, PIN verifier, sign, shift key, lookup hashes
js/proof.js          proof-key creation at registration and the PFPMS-Proof header
js/session.js        who is at the tablet: sign-in, unlock, PIN, locks, timers, offline sessions, the unlock delay
js/drafts.js         Station-owned drafts (debounced 300 ms, sealed)
js/outbox.js         record items (sign, seal, gap-free seq), states, counts, due() with dependency gating
js/sync.js           push loop, status polling, backoff, single flight, 1.5 s wait-for-answer
js/device.js         registration, heartbeat body/response, directives, the wipe state machine, failed-unlock wipe
js/copy.js           every people-facing string, by code
js/registration_code.js                                                               (parity with RegistrationCode)
js/fold.js           accentFold(), phoneticKey()                                     (parity; used by P3 search)
js/calc.js           AllotmentCalculator twin                                         (parity; used by P4)
js/sw-core.js        classic script: pure helpers + worker event handlers (inlined by sw.php)
js/views/device.js login.js ack.js password.js pin_set.js home.js sync-status.js about.js wipe.js elsewhere.js
```
- **`StationAssets::FILES`** (S2) lists every file above except `index.php` and `sw.php`, as paths relative to `public/station/`. `index.php`'s output (`StationShell::html('')`), the shell and worker headers and `sw.php`'s bytes are hashed into the build separately (D-07). `StationAssetsTest::testEveryStationFileIsListed` fails when a file exists under `js/`, `css/` or `icons/` (or is `boot.js`/`manifest.json`) and is not listed.
- **The precache list** is the listed files except `js/sw-core.js`, plus `./`. Its SHA-256 is that of `StationShell::html()`, the deterministic shell, which holds no organisation name, user data or CSRF token.
- **Modules:** all are ES modules (`type="module"`, relative `.js` imports, no bundler, no dependency). `app.js` imports every view statically, so after start-up nothing else is fetched and a service-worker switch can never leave a view half-loaded.
- **Rendering** uses `dom.js` only (`textContent`, `setAttribute`). `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `eval`, `new Function`, inline handlers, `localStorage` and `sessionStorage` (except `boot.js`'s attempt counter) are forbidden. `tests/js/source_rules.test.js` enforces this with a grep over `public/station/**`.

### 7.2 Module responsibilities and exported API
Every module that touches the platform takes its adapters as arguments (no globals), so `node:test` drives it with fakes.

| Module | Exports |
|---|---|
| `env.js` | `browserEnv()` → `{now(): number, mono(): number, displayMode(): 'standalone'\|'browser'\|'minimal-ui'\|'fullscreen', persisted(): Promise<bool>, persist(): Promise<bool>, estimateKb(): Promise<int\|null>, onOnline(cb), onVisible(cb), barcodeDetector(): object\|null, locks: LockManager\|null, crypto, random(n): Uint8Array, uuid(): string}` |
| `clock.js` | `createClock({db, env})` → `{serverNow(): number, offsetMs(): number\|null, trusted(): bool, learn(serverTimeText, sentAt, receivedAt), tick(), rolledBack(): bool, snapshot()}` (§7.4) |
| `api.js` | `createApi({fetch, base: '../', device: () => {credential, proof}\|null, clock})` → `{get(path, {device, session, clearSite, timeoutMs, okStatuses}), post(path, body, {device, session, clearSite, timeoutMs, okStatuses}), csrf(), setCsrf(t), refreshCsrf()}`. Throws `ApiError {kind: 'offline'\|'http', status, code, message, extra}`; a status listed in `okStatuses` (default `[]`; push passes `[409]`) returns the parsed body like a 2xx. Every call sends `X-PFPMS-Client: station`, and **every call with a body sends `Content-Type: application/json`**, device-only calls included. `device: true` adds `Authorization: PFPMS-Device <c>` and `PFPMS-Proof` (over the exact body string sent). `session: true` adds `credentials: 'same-origin'` and `X-CSRF-Token` on POST. Device-only calls use `credentials: 'omit'`, except with `clearSite: true` (every call `device.js` makes while `meta.wipe` exists), which uses `'same-origin'` so the browser applies `Clear-Site-Data` (§6.4). One CSRF refetch-and-retry on `csrf_failed`, and one re-base-and-retry on `device_proof_stale`. |
| `db.js` | `openDb(indexedDB)` → adapter `{get(store, key), put(store, value), delete(store, key), all(store), byIndex(store, index, value), count(store, index?, value?), clear(store), tx(stores, mode, fn(t)), close(), closed(): bool, destroy()}`. `t.get/put/delete` issue IndexedDB requests only (no other awaits inside). **Every connection sets `onversionchange = () => { db.close(); markClosed(); }`**, so another window's delete or upgrade is never blocked by this one; a closed adapter throws `DbClosed` on use. `destroy()` closes its own connection first, then `indexedDB.deleteDatabase('pfpms')`; on `blocked` it shows "Close the other Station window on this tablet." and retries every 2 s. `openDb()` handles `blocked` on an upgrade the same way. (Checked in Chromium 152: a `deleteDatabase` with another connection open and no `versionchange` handler fires `blocked` and never completes.) `tests/js/support/memory-db.js` implements the same interface on `Map`s with `structuredClone` (which clones non-extractable `CryptoKey`s) and multiEntry indexes; it cannot reproduce blocking, so E2E step A13 checks it. |
| `canonical.js` | `canonical(value): string`, `canonicalBytes(value): Uint8Array`, `formatDb(ms): string`, `parseDb(text): number\|null`, `FORMAT = 'pfpms/v1'` |
| `vault.js` | `calibrate(env)`, `deriveKek(crypto, password, salt, rounds)`, `wrapDvk(crypto, kek, dvkBytes, deviceId, userId)`, `unwrapDvk(crypto, kek, rec, deviceId, userId)` (throws `VaultError('wrong_password')`), `openVault(crypto, dvkBytes)` → `{rec, pin}` (non-extractable; zeroes the bytes it is given, so a caller that still has a wrap to do passes a copy, or wraps first, §2.3), `seal(crypto, recKey, store, key, bytes)`, `open(crypto, recKey, store, key, rec)`, `pinVerifier(crypto, pinKey, userId, pin)`, `sign(crypto, grantKeyB64, bytes)`, `lookup(crypto, deviceId, text)` (trim as PHP, NFC, per-code-point lower, D-29), `b64urlDecode(text)` (strict, §3.2), `writeShift(crypto, db, dvkBytes, deviceId, expiresAt)`, `openShift(crypto, db, deviceId, serverNow)` → dvk sub-keys\|null, `dropShift(db)` |
| `proof.js` | `createProofKey(crypto)` → `{raw: Uint8Array(32), key: CryptoKey}` (extractable once), made with `generateKey({name: 'HMAC', hash: 'SHA-256', length: 256}, true, ['sign'])` (the default length is 512 bits); `importProofKey(crypto, raw)` → non-extractable `CryptoKey`; `proofHeader(crypto, key, method, endpoint, tsMs, bodyText)` → `'v1 <ts> <mac>'` |
| `session.js` | `createSession({db, api, env, clock, vault, outbox, drafts, config})` → `{state(), user(), mode(), capabilities(), signIn(identifier, password), acceptPolicy(doc)/declinePolicy(doc), changePassword(current, next), pinSwitch(userId, pin), setPin(password, pin, confirm), switchUser(), lock(kind: 'idle'\|'hard', reason), endShift(), touch(), tick(), onRevokedGrants(ids), onOfflineDisabled(), peopleForPicker(), unlockDelaySeconds(), on(event, cb)}`. Events: `changed`, `locked`, `gate`, `failed_unlock_wipe`. |
| `drafts.js` | `createDrafts({db, vaultKeys, clock, debounceMs = 300})` → `{save(key, data, ownerId), load(key) → {data, started_by, started_at}\|null, discard(key), flush()}` |
| `outbox.js` | `createOutbox({db, env, clock, vaultKeys, meta})` → `{record(kind, payload, {dependsOn, sessionId, userGrant}) → client_uuid, due(limit, maxBytes), apply(results), pendingCount(), attentionCount(), maxSeq(), oldestPendingAt(), held(), attention(), queuedItems(kind) → list of decrypted S (vault open; the P4 local stock view "pack − outbox", plan:334), clear()}` |
| `sync.js` | `createSync({outbox, api, env, device, clock})` → `{pushNow({rescue, waitFor, waitMs}) → Promise<result\|null>, start(), stop(), inFlight(), checkHeld()}` |
| `device.js` | `createDevice({db, api, env, vault, outbox, sync, clock})` → `{registered(), register(typedCode), heartbeat(extra), handle(response), wipe(directive), resumeWipe(), failedUnlockWipe(), repair(), check()}`. `repair()` first calls `api/ping.php` (5 s) and refuses offline (§7.7). During a wipe it treats `DbClosed` / `InvalidStateError` after a 200 or 410 as "already erased" (`Clear-Site-Data` closed the connection) and goes to the final screen. |
| `registration_code.js` | `normalise(typed)`, `format(canonical)`, `checkSymbol(data)`, `fromBytes(bytes)`, `qrPayload(code)`, `pairingCheck(hashHex)`; no `trim()`, `toUpperCase()` or `\s` |
| `fold.js` | `accentFold(text)`, `phoneticKey(text)` (§11.3) |
| `calc.js` | `versionOn(published, date)`, `entitlement(rules, pets)` |
| `sw-core.js` | `PFPMS_SW = {cacheName(build), shouldHandle(url, scopeUrl, method), verify(entry, response) → Promise<bool>}` + the `install`/`activate`/`fetch`/`message` listeners, registered only when `self.registration` exists |

**Dev-only hooks.** `app.js` creates `window.__pfpms.debug = {skipDelay(), record(kind, n), draft(text)}` only when `api/session.php` answered `dev_relax: true` (possible only in the `dev` and `test` environments, D-12); the cached static client has no other way to know its environment. `skipDelay()` clears the offline unlock delay, `record(kind, n)` records `n` items of a kind the server registers (the E2E uses `station_check`) for the signed-in person, and `draft(text)` saves a `station:open-entry` draft through `drafts.js`. `source_rules.test.js` checks that `__pfpms` appears only in `app.js`, inside the `dev_relax` branch.

### 7.3 IndexedDB schema (`db.js`, database `pfpms`, version 1)
| Store | keyPath / key | Indexes | Contents | Encrypted |
|---|---|---|---|---|
| `meta` | out-of-line string keys | — | `device` {device_id, credential, proof (non-extractable HMAC `CryptoKey`), site_id, site_name, label, iterations, registered_at}; `seq` {next} (starts 1); `config` (§6 + `offline_allowed`); `clock` {offset_ms, trusted, high_water_ms, measured_at}; `unlock` {failures (every failure: the delay), wipe_failures (against a real, unexpired entry: the wipe), last_failure_at, locked_out_since} (D-30); `auth_failures` [{user_id, factor, count, first_at, last_at}] ≤20; `failed_unlock_wipe_pending` bool; `clock_rollback_pending` bool; `shift` {key (non-extractable AES `CryptoKey`), iv, wrapped, expires_at, created_at}; `shift_ended_at` (server-frame text); `pending_shift_end` {at}\|null; `wipe` {mode, stage, started_at, items_pushed}\|absent; `last_heartbeat_at`; `pack` (reserved for P3 {version, fetched_at}) | **no** (no personal data; keys are non-extractable) |
| `keyring` | `user_id` | `lookup` (multiEntry) | §3.4 | **no** (the DVK is wrapped under the KEK) |
| `vault_users` | `k` = `user:<id>` | — | §3.5 | K_rec |
| `sessions` | `k` = session_uuid | — | the open offline session {session_uuid, user_id, factor, started_at, last_activity_at, start_uuid} | K_rec |
| `outbox` | `client_uuid` | `seq` (unique), `state`, `kind` | {client_uuid, seq, kind, state, depends_on, attempts, created_at, last_attempt_at, reason_code, iv, ct, mac}; `iv`/`ct` are dropped once `held` | `ct` under K_rec; index fields plaintext |
| `drafts` | `k` = `station:open-entry` \| `register:<uuid>` | — | {started_by, started_at, updated_at, data} | K_rec |
| `pack` | `k` | — | created empty (P3) | K_rec |

**Gap-free `seq`** (`outbox.record()`):
1. Read `meta.seq`, build S with it, compute the canonical bytes, `sign`, and `seal`.
2. In one `tx(['meta', 'outbox'], 'readwrite')`, re-read `meta.seq`. Only if it still equals the value used, write the outbox record and `meta.seq = seq + 1`; otherwise rebuild the whole record with the new number.

A crash before the transaction leaves nothing. `seq` is never reused on a device row, and it survives the failed-unlock wipe (meta and outbox are kept). **Upgrade rule:** `onupgradeneeded` is additive only (new stores and indexes); `outbox`, `sessions` and `meta` are never rewritten. An upgrade (P3's `open('pfpms', 2)`) can proceed only because every open connection, including an ELSEWHERE window's, closes itself on `versionchange` (§7.2 `db.js`).

### 7.4 The tablet's clock (`clock.js`)
- `meta.clock = {offset_ms, trusted, high_water_ms, measured_at}`; `high_water_ms` is in the tablet's own clock frame.
- **Learning:** every JSON answer carrying `server_time` calls `learn()`, which sets `offset_ms = server − (sentAt + receivedAt) / 2`, `trusted = true` and `high_water_ms = Date.now()`. The offset now accounts for the tablet's clock, so re-basing the high-water mark cannot extend anything.
- **Tick** (every 15 s):
  - `high_water_ms = max(high_water_ms, Date.now())`, persisted at most every 60 s;
  - jump detection: `J = Δwall − Δmono` since the last tick (`performance.now()`). **Only a backward jump is re-based:** if `J < −60 000` (the wall clock fell behind the monotonic clock: the clock was set back, or NTP corrected it backwards), then `offset_ms −= J` and `high_water_ms += J`, so `serverNow()` stays continuous. A forward `J` changes nothing, because it cannot be told apart from sleep: `performance.now()` does not advance while the device sleeps in Chrome on Android, ChromeOS and macOS (and WebKit on iPadOS), while `Date.now()` does. Re-basing it would freeze `serverNow()` across every sleep, keep an idle session open, and date later items before a revocation.
  - After a real forward clock change the offset stays stale until the next `learn()`: `serverNow()` runs ahead, which only shortens local expiries, and items are dated later (bounded by `received_at` at the server), which can only Hold them for review, never move them earlier.
- **At boot:** `Date.now() < high_water_ms − 300 000` means the clock went back while the app was closed. The tablet sets `trusted = false` and `clock_rollback_pending = true`, and deletes the shift key.
- `serverNow() = trusted ? max(Date.now(), high_water_ms) + offset_ms : high_water_ms + (offset_ms ?? 0)`. Grant expiry, the shift key, the absolute timer, the unlock delay and proof timestamps use it. **Setting the clock back cannot move local time earlier than the last time the Station ran** (the high-water mark advances only while the app runs). Moving the clock back to about when the app was last open still lets a grant or shift key look valid for as long as the app was closed; items recorded then are dated in that window at push. This residual is in §9 and Q6. Setting the clock forward only shortens.
- **Items** sign `recorded_at = formatDb(Date.now())` (raw) and `clock_offset_ms = trusted ? offset_ms : null`. The server corrects them (§8.5).
- **Idle** timing uses `max(env.mono() − lastInputMono, clock.serverNow() − lastInputServerNow)`: the monotonic clock covers a wall clock set back, and `serverNow()` covers sleep (§7.5).

### 7.5 State machines

**Tablet lifecycle** (`app.js` / `device.js`, persisted through `meta`)
```
UNREGISTERED ──register 200──► REGISTERED ──directive 'Push Then Wipe'──► RETIRING ─nothing queued─► CONFIRMING ─200 wiped/410─► ERASED
      ▲                          │   │                                       │ conflict/invalid left: STUCK (banner, hourly retry;
      │                          │   │                                       │   an Administrator's Erase now escalates to Wipe Now)
      │                          │   └──directive 'Wipe Now'──► CONFIRMING (outbox cleared)
      │                          ├──any 410 wiped──► ERASED (local delete)
      │                          └──failed unlocks = max──► REGISTERED + locked_out (keyring, vault_users, sessions, pack, shift key cleared)
      └──────────── "Register this tablet" (new code) ◄── ERASED
ELSEWHERE (another window holds 'pfpms-primary'; no keys, no sync) ──"Use this window here" (steal)──► the state above
```
`meta.wipe` exists ⇒ the wipe resumes at start-up **before any view**, including before the heartbeat's own result.

**Who is at the tablet** (`session.js`; `mode` online/offline is an attribute of ACTIVE)
```
LOCKED (no keys) ──password online ok──► ACTIVE(online)     ──password offline ok──► ACTIVE(offline)
LOCKED ──reload/tab kill with a valid shift key──► PICKER (keys, nobody)
ACTIVE ──no user input for session_idle_minutes──► IDLE (keys kept; offline session ended 'Timeout')
ACTIVE ──"Switch user"──► PICKER (keys kept; the session continues until someone else signs in)
IDLE | PICKER ──PIN ok (online or offline) | password ok──► ACTIVE
ACTIVE | IDLE | PICKER ──"End shift / Lock device" | session_absolute_hours since the last password open | pin_max_failed wrong PINs
                        | reload or tab kill without a valid shift key | clock rollback | offline_enabled false──► LOCKED (shift key deleted)
ACTIVE ──the active person's grant expired or revoked──► PICKER (their entries deleted; session ended 'Permission Change')
ACTIVE(online) ──network drops──► ACTIVE(online) with the "Offline" header: items queue; the server session may time out;
                                  the next session call's 401 brings the re-auth overlay
GATE (after sign-in with a pending acknowledgement or forced change; the KEK kept ≤10 min) ──accept or change + release──► ACTIVE
                                                                                           ──decline or 10 min──► LOCKED
any ──directive──► WIPING ; ELSEWHERE ──► no keys at all
```
- **Offline sessions** honour only the `offline.*` capabilities cached in `vault_users`, and mark records "waiting to upload".
- **Always visible:** "Switch user", "End shift / Lock device", the person's name, the unsynced badge, and "Open entry: started by {name}".
- **Offline End shift** sets `meta.shift_ended_at` and `pending_shift_end {at: serverNow()}`; the next successful contact sends `logout {scope: 'device', ended_at: at}` (D-24, §6.8), so the server records the End shift at its own time.

**Outbox item**
```
record() ─► queued ──accepted | accepted_with_exception──► (deleted)
queued ──held──► held (ciphertext dropped) ──status resolved | discarded──► (deleted)
queued ──conflict | invalid──► needs attention (terminal, kept with its ciphertext; blocks the Retire wipe)
queued ──refused (P4) answered within the wait──► refused (terminal, shown; deleted when dismissed)
queued ──refused answered after the wait──► queued (resent no sooner than ONLINE_WINDOW_SECONDS; the server then stores it Held REFUSED_LATE)
queued ──retry | offline | 5xx | 429──► queued (attempts + 1, backoff)           any ──Wipe Now──► (database deleted)
```

**Timers** (`session.tick()` every 15 s, and on `visibilitychange` to visible **before** the view is shown again, so a tablet that wakes from sleep locks before anyone sees the last person's screen):
- idle uses `max(env.mono() − lastInputMono, clock.serverNow() − lastInputServerNow)` since the last user input (§7.4: `performance.now()` stops during sleep);
- the absolute limit uses `serverNow()` against `last password open + session_absolute_hours`;
- grant expiry uses `serverNow()`.

Only user input (`pointerdown`, `keydown` in the app) calls `touch()`, which also schedules the server keep-alive: POST `api/session.php {action:'touch'}`, at most once every 5 minutes, only while an online session is open (D-05). Network activity never extends a timer.

**Offline unlock delay:** `unlockDelaySeconds() = failures ≥ 3 ? min(900, 30 × 2^(failures − 3)) − (serverNow − last_failure_at) : 0`, where `failures` counts every failed offline unlock, unknown names included (D-30). The password field stays disabled while it is positive.

### 7.6 Wipes (`device.js`)
**Directive wipe (X-4).** Before any deletion the tablet writes `meta.wipe = {mode, stage: 'start', started_at, items_pushed: 0}`. At boot, `resumeWipe()` runs first.

| Stage | Push Then Wipe | Wipe Now |
|---|---|---|
| `start` | Lock the UI ("This tablet has been retired. It is uploading its records, then it will erase itself. Keep the app open and online."). Delete `pack`, `keyring`, `vault_users`, `sessions`, `drafts` and `meta.shift`; drop the keys. → `pushing` | The same UI ("This tablet is being erased.") and deletions, plus `outbox`. → `confirming` |
| `pushing` | Run rescue pushes until no record is `queued`. Records in `conflict`/`invalid` stop the wipe here: the `stuck_conflict` banner ("Retired, but {n} record(s) could not be uploaded, so it has not erased itself. Ask a Coordinator."), with a heartbeat retried hourly. An Administrator's Erase now changes the directive to Wipe Now. → `confirming` | — |
| `confirming` | Clear `outbox`, then heartbeat `{wiped: true, items_pushed}` with a time-bound proof and `clearSite: true` (`credentials: 'same-origin'`). Retry with backoff; on `device_proof_stale`, re-base and retry. 200 `status:'wiped'` or 410 → `deleting`. The mode and the credential are held in memory from here on, because the reply's `Clear-Site-Data` may erase the database (and close its connection) before the page reads the answer. | the same |
| `deleting` | Close the tablet's own connection, then `indexedDB.deleteDatabase('pfpms')` (another window's connection closes itself on `versionchange`; if one is still `blocked`: "Close the other Station window on this tablet.", retried every 2 s), delete every `pfpms-*` cache, `registration.unregister()`. A `DbClosed` or `InvalidStateError` here means `Clear-Site-Data` already erased everything: skip to the screen. Show "This tablet has been erased. Its records were uploaded first." then **Register this tablet**. | "This tablet has been erased without uploading." |

A 410 at any time while not wiping means the server already holds the tablet as erased: the tablet deletes everything at once, by itself. `Clear-Site-Data` does not help there, because Chromium ignores it on replies to credential-less requests, and ordinary device calls use `credentials: 'omit'` (§2.1).

**Failed-unlock wipe (D-30).** It deletes `keyring`, `vault_users`, `sessions`, `pack` and the shift key. It keeps `outbox`, `drafts` and `meta`, and sets `failed_unlock_wipe_pending` and `unlock.locked_out_since`. The message is "Too many wrong sign-ins. This tablet has removed its offline sign-ins; its unsent records are kept and will upload when it is online. Someone must sign in with a connection to use it again." The next heartbeat reports it, and the next push uploads the outbox (rescue). An open offline session cannot write its end item without keys, so its server row is timed out by `ExpireSessions`.

### 7.7 Service worker lifecycle and update flow
- **Registration** (`boot.js`): `navigator.serviceWorker.register(policy.createScriptURL('sw.php'), {scope: './', updateViaCache: 'none'})`. It uses a plain string where `trustedTypes` is absent. The URL is relative, so it works under `/chsPetPantry/public/station/`. `registration.update()` runs at boot and whenever a heartbeat's `build` differs from `<html data-build>`, but at most once an hour while the last install failed (a stale server copy fails the hash check every time until the host's cache refreshes, §5.7).
- **First start of an uncontrolled page** (`boot.js`; a first visit, after Repair, after the kill switch is lifted). Before importing `app.js`, `boot.js` registers the worker and waits until it controls the page (`controllerchange` after its `clients.claim()`), then reloads once. Nothing of the app has run, so no registration or sign-in request can be in flight, and every module then comes from the verified precache, never from a possibly stale HTTP cache. It stops waiting and imports `app.js` from the network when:
  - registration rejects (for example in the desktop app's browser pane, which cannot run a service worker, §11.4);
  - the kill worker posts `{type: 'SW_KILLED'}`;
  - 8 s have passed (offline, or a slow first precache).

  If an active worker already exists but does not control the page (a hard reload), `boot.js` reloads once instead of waiting, guarded by `sessionStorage['pfpms-boot']` so it can never loop.
- **`install`:** open `pfpms-shell-<BUILD>`. For each `PRECACHE` entry, `fetch(new Request(path, {cache: 'reload', credentials: 'same-origin', redirect: 'error'}))` and require:
  - `res.ok`;
  - the `Content-Type` family matching `type` (`javascript`, `text/css`, `image/png`, `json`, `text/html`);
  - `hex(SHA-256(body)) === sha256`.

  Then `cache.put(path, res)`. **Any mismatch throws**, so the install fails and the running version stays. Only when no worker is active does it call `skipWaiting()`.
- **`activate`:** delete every `pfpms-shell-*` cache except the current one; `clients.claim()`.
- **`fetch`:** `shouldHandle()` is true only for same-origin GETs under the scope that are not `/api/` and not `sw.php`; otherwise there is no `respondWith`.
  - Navigations → the cached `./` (the shell, returned unchanged with its CSP), falling back to the network.
  - Precached files → `caches.match(request, {ignoreSearch: true})`, falling back to the network.

  The worker never opens IndexedDB, never queues requests and uses no Background Sync.
- **`message {type: 'SKIP_WAITING'}`** → `skipWaiting()`.
- **Update rule (never mid-transaction):** when `registration.waiting` exists, `app.js` posts `SKIP_WAITING` only when all of these hold:
  - the tablet is `LOCKED`, `PICKER` or `UNREGISTERED`;
  - no draft exists;
  - `sync.inFlight()` is false;
  - no wipe is in progress;
  - there has been no input for 60 s.

  It re-checks on every such change. **`controllerchange` → `location.reload()` only when the page had a controller at load and this page itself posted `SKIP_WAITING`** (a flag the update flow sets), and never while a registration request or any sign-in, PIN or push request is in flight (the reload then waits for it). `clients.claim()` fires `controllerchange` on a page that had no controller too (the first install, an iPad Home Screen app's first launch, after Repair), and an unguarded reload there could land mid-registration and lose the in-memory nonce and proof key (X-3). At a cold start a waiting worker is activated before any view. After the reload, the shift key (when valid) brings the tablet back to PICKER.
- **Boot watchdog** (`boot.js`): a 15 s timer is started; `app.js` mounts a "Starting…" view and calls `window.__pfpmsStarted()` **before its first network call**, so a slow or captive Wi-Fi never trips the watchdog. The start-up heartbeat is then bounded to 5 s, and the lock screen stays disabled ("Checking this tablet…") until it answers or times out, so "Erase now erases before the unlock screen" still holds online. If the timer fires, or `import('./js/app.js')` throws, `boot.js` renders (DOM only) "The Station could not start. Your records on this tablet are not affected." with **Try again** (reload) and **Repair the app**. `sessionStorage['pfpms-boot']` counts attempts, and after 3 failed boots Repair is shown first, unless `api/ping.php` fails (then Try again stays first).
- **Repair** (`boot.js`, and About → **Repair this app**): first `GET api/ping.php` (5 s). Offline, it refuses: "Repair needs a connection. Your records are safe; try again when the tablet is online." and removes nothing, because deleting the only local copy of the shell offline would leave the Station unable to start for the rest of the event. Online, it unregisters every registration in scope, deletes the `pfpms-shell-*` caches and reloads; the first-start rule above then installs and verifies the worker before the app runs. **Never IndexedDB.**
- **Kill switch** (`station.sw_kill`): `sw.php` emits `install → skipWaiting(); activate → delete pfpms-shell-*, registration.unregister(), then clients.matchAll({type: 'window', includeUncontrolled: true}): postMessage({type: 'SW_KILLED'}) to each, and navigate(c.url) each controlled one`. The page keeps running from the network, and IndexedDB is untouched. The kill worker never claims, so an uncontrolled page learns from `SW_KILLED` that it need not wait (it registers the kill worker again at each start, which unregisters itself again; there is no reload loop, because `navigate()` only reaches controlled pages).
- **Primary window (X-2):** `app.js` requests `'pfpms-primary'` before opening the vault. A window that loses it (stolen) drops its keys, stops `sync` and the heartbeat timer, and shows `elsewhere`.

### 7.8 Views and key people-facing messages (`copy.js`)
All targets are ≥48 px (≥44 pt), base text is 18 px, contrast is ≥7:1, and the layout is one column. The header always shows the site, the person, "Switch user", "End shift / Lock device", the unsynced badge, the connectivity chip and, when a draft is open, "Open entry: started by {name}". Times are shown in the site's time zone.

| View (route) | Content and messages |
|---|---|
| device (`#/device`) | **"Register this tablet"**: camera scan (when `BarcodeDetector` exists) or a typed code with a live check. The messages:<ul><li>mistyped: "Check the code: one of the characters looks wrong.";</li><li>not installed (409): "Open the installed app from the home screen, then scan the code again.";</li><li>invalid (422): "This code is not valid. It may have expired or already been used. Ask a Coordinator for a new registration sheet.";</li><li>busy (409): "Someone else is changing this tablet right now. Wait a few seconds and try again.";</li><li>no answer: "Could not reach the server. Check the Wi-Fi and press Register again.";</li><li>success: "Registered to {site} as {label}.";</li><li>storage refused: "This tablet may not keep its data. It will work online only.";</li><li>key self-test failed: "This tablet's browser cannot keep its keys safely. Update it, or use another tablet."</li></ul> |
| login (`#/login`) | The organisation name, "Pet Pantry Station" and the build, with username + password. The messages:<ul><li>online failure: the server message;</li><li>offline failure (any cause): "Sign-in failed. Offline, you can only sign in on a tablet where you signed in online in the last {offline_grant_hours} hours.";</li><li>delay: "Too many wrong sign-ins. Try again in {n} seconds.";</li><li>offline success banner: "No connection: you are working offline.";</li><li>online-only tablet offline: "No connection, and this tablet isn't set up to work offline. Try again when it is online.";</li><li>failed-unlock wipe: see §7.6.</li></ul>**PICKER mode** shows tiles of people and "Someone else", and a PIN pad. Its messages: "Wrong PIN. {n} tries left before a password is needed."; "Too many wrong PINs. Sign in with your password."; offline without a verifier: "Quick switching offline needs your PIN to have been used once on this tablet while online. Use your password." |
| ack (`#/ack`) | The agreement text (as `textContent`), with "I accept" / "I do not accept". Decline: "You need to accept the confidentiality agreement to use the Station. You have been signed out." Changed: the 409 message, then the new text. |
| password (`#/password`) | Current + new + repeat, the rules shown, and server messages next to the fields. Intro: "You need to set a new password before you continue." |
| pin_set (`#/pin`) | Password + new PIN twice, and the rule messages. Success: "Your PIN is set. Use it to switch quickly on this tablet today." |
| home (`#/home`) | The site, the person, and the online/offline state. Offline: "Working offline: only offline tasks are available. Records upload when the connection returns." No PIN yet (online): "Set a PIN to switch quickly on this tablet." Test tablet: "This is a test tablet without a vault key: people can sign in, but it cannot record." Dev relax: "Development mode: install checks relaxed." |
| sync-status (`#/sync`) | "{n} records not uploaded yet" / "Everything is uploaded ({time})". The held list shows "Held for review: {reason}", with plain reasons:<ul><li>`DEVICE_REVOKED`: "recorded after this tablet was retired";</li><li>`DEVICE_SUSPECT`: "this tablet was reported lost or erased";</li><li>`GRANT_NOT_VALID`: "the sign-in had expired";</li><li>`ACTOR_INVALID`: "the person's account was not usable then";</li><li>`BAD_HMAC`/`MALFORMED`/`DECRYPT_FAILED`: "could not be verified";</li><li>`UNKNOWN_KIND`/`CAPABILITY`: "this tablet's version or this person cannot upload this";</li><li>`DEPENDS_ON_HELD`/`DEPENDS_ON_MISSING`: "depends on another record that is held or missing";</li><li>`SESSION_CONFLICT`: "its sign-in record clashed with another one";</li><li>`HANDLER_ERROR`: "the server could not process it; a Coordinator will check it";</li><li>`REFUSED_LATE`: "the server refused it after it was saved on this tablet; a Coordinator will check it";</li><li>any other code: "held for review".</li></ul>Needs attention: "Could not upload: its id was already used / it was damaged. It is kept on this tablet. Tell a Coordinator." |
| about (`#/about`) | The label, site, build, last contact, "Server receives this tablet's key: yes/no" (ping), the unsynced count, clock offset and storage used. Buttons: **Check this tablet** (records a `station_check`; needs a person signed in), **Repair this app** (online only: "Repair needs a connection. Your records are safe; try again when the tablet is online."), and **Update now** (only when an update waits and no draft is open). |
| wipe / elsewhere | §7.6 texts, including "Close the other Station window on this tablet." while an erase waits for it; "The Station is open in another window on this tablet." with **Use this window here**; "This Station window was replaced by another one. You can close it." |
| starting | "Starting…", then "Checking this tablet…" while the start-up heartbeat runs (≤5 s, §7.7) |
| overlays | Draft restore: "Open entry started by {name} at {HH:MM}: Continue / Discard (say why)". Clock: "This tablet's clock looks wrong. Use your password to unlock." (after a rollback). Header missing: "The server isn't receiving this tablet's key, so nothing can upload. Records are kept on this tablet. Tell the Administrator." |

### 7.9 iOS and Android rules
- Offline needs the installed app (`display-mode: standalone`) and `navigator.storage.persisted() === true` on **every** platform. Both are reported on each heartbeat, and only the server sets `offline_enabled`.
- Registration, sign-in and everything else happen inside the installed app (iPad Home Screen apps have their own storage). The QR is scanned in-app; the sheet's instructions (`device_credential.php:52-62`) are the contract.
- `navigator.storage.persist()` is requested at registration and at each start while not persisted.
- A `lifetime 0` cookie may vanish when the app is killed. Every 401 is normal and leads to the re-auth overlay. After a kill the tablet is LOCKED, or PICKER with a valid shift key.
- The registration self-test refuses a platform that cannot store and reuse a non-extractable `CryptoKey`. The shift key and proof key depend on this (the manual matrix checks the iPad).
- Android Chrome tablet and Chromebook are primary; the iPad (iPadOS ≥ 16.4) is best effort (§11.6).

### 7.10 Timing budgets
| Step | Budget | How it is met |
|---|---|---|
| Password unlock (offline), slowest tablet | ≤3 s (plan:257) | PBKDF2 calibrated to ≈1.0 s on that tablet + AES-GCM + HKDF + one `vault_users` decrypt (≈20 ms) ≈ 1.1 s |
| Online sign-in | ≤3 s p95 (UC-01 §4.3) | PBKDF2 (≈1.0 s) runs in parallel with the request (server Argon2id ≈0.4-0.5 s + network); wrap + stores ≈30 ms. On a timeout (6 s) the offline unlock is offered. |
| PIN switch online | <5 s (plan:257) | one request (Argon2id PIN verify + two small writes) ≈0.5-1.0 s; a 3 s timeout, then the offline verifier (<100 ms) |
| PIN switch after a tab kill | <5 s | the shell from the SW cache (≤2 s cold) + shift-key unwrap (≈10 ms) + the PIN |
| Recording a write | UC-06 ≤2 s per step | seal + IndexedDB write (<50 ms), then at most a 1.5 s wait for the server's answer |
| Draft autosave | 300 ms debounce | `drafts.js` |

The app logs `[pfpms] unlock <ms>`, `[pfpms] sign-in <ms>` and `[pfpms] pin <ms>` to the console (no names), read in the E2E and performance runs.

---

## 8. Sync protocol

### 8.1 From outbox to envelope
- **`outbox.record(kind, payload, opts)`** requires an ACTIVE person (their grant key from `vault_users`) and the vault key. It builds S (§3.6) with:
  - `recorded_at = formatDb(env.now())`;
  - `clock_offset_ms` from `clock.js`;
  - `session_id` of the current session;
  - `depends_on` from `opts`.

  It stores `{client_uuid, seq, kind, state: 'queued', depends_on, attempts: 0, created_at, iv, ct, mac}`.
- **`sync.pushNow({waitFor, waitMs: 1500})`** is single-flight (`navigator.locks.request('pfpms-sync', {ifAvailable: true}, …)`, plus a module flag). The primary-window lock (X-2) means only one window syncs.
  - It takes `outbox.due(50, 512 KiB)`: `queued` records by `seq` ascending, **skipping any whose `depends_on` names an outbox record that is still `queued` and not already selected earlier in this batch**. A dependency in `conflict`/`invalid` does not hold its dependant back; the server then answers `DEPENDS_ON_MISSING`.
  - It sends the envelope (§6.11) with `client_now = formatDb(env.now())`, `rescue = !session.hasVaultKey()` and `build` = the shell's build, through `api.post(…, {device: true, okStatuses: [409]})`, so a batch with one conflict still returns every result (D-36). It does not need the vault: stored records are sent as they are.
  - With `waitFor`, the returned promise resolves with that item's result or `null` after `waitMs`; the fetch itself continues (30 s timeout).
- **Triggers:** after `record()` (with `waitFor`), on `online`, on becoming visible, after each heartbeat, and every 30 s while any record is `queued`.
- **Backoff** on `offline`, 5xx or 429 (`Retry-After` honoured): 5, 15, 30, 60 s, then every 300 s, with ±20 % jitter. It resets after a 200/409.
- **Result application (`outbox.apply()`), in one IndexedDB transaction:**

  | Result | Outbox action |
  |---|---|
  | `accepted`, `accepted_with_exception` | delete |
  | `held` | state `held`, keep `reason_code`, drop `iv`/`ct` |
  | `resolved`, `discarded` | delete |
  | `conflict`, `invalid` | the needs-attention state, keeping `iv`/`ct` |
  | `refused` | answered while the UI still waited for this item: state `refused` (P4). Answered after the wait ended: stay `queued`, resent no sooner than `ONLINE_WINDOW_SECONDS` after the answer, so the server stores it Held `REFUSED_LATE` (X-6) |
  | `retry`, or an unknown status | stay `queued`, `attempts++` |
  | missing from the response | stay `queued` |

  Then it acts on `directive` and `revoked_grants`, and calls `clock.learn(server_time)`. Nothing is deleted without a server answer naming it, except by Wipe Now.

### 8.2 Ordering
- Items are processed in the order sent (`seq` ascending), and each is independent.
- Out-of-order `seq` (a retry of an older item after newer ones) is accepted as it comes. It only affects the clamp's seq floor (which reads what is committed) and dependencies.
- There is no named lock. Concurrent pushes from one tablet are serialised per item by the device row lock, and `client_uuid` is the primary key, so a second batch finds its items stored (duplicate no-ops).

### 8.3 Server processing of one item (`SyncService::item()`)
**Once per batch, outside any transaction:**
- `$dvk = Crypto::decrypt(DeviceRepository::vaultKeyCiphertext($id), "device:$id:dvk")`. NULL gives 409 `vault_key_missing` for the whole request.
- `$kRec = Crypto::hkdf($dvk, 'pfpms/v1/record')`.
- `$serverNow = Clock::now()`; `$skew = serverNow − Clock::fromClient(client_now)` (null when missing, malformed or |skew| > 7 days).

**Per item, outside the transaction (pure work, no locks):**
1. **Shape:**
   - `client_uuid` must match `^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`, else result `invalid` (nothing stored: the key cannot be trusted);
   - `seq` an integer 1..2 147 483 647; `iv` 16 b64url characters decoding to 12 bytes; `ct` `^[A-Za-z0-9_-]{1,90000}$`; `mac` 43 b64url characters decoding to 32 bytes; each decoded with `Crypto::unb64urlStrict()` (§3.2); `cosign` absent or null. Otherwise Held `MALFORMED`.
2. **Open:** `bytes = Crypto::openRaw($kRec, iv, ct, "pfpms/v1|outbox|$uuid")` (a fixed 16-byte tag, `strlen($sealed) >= 16`). Failure is Held `DECRYPT_FAILED`, with `payload_sha256 = sha256("undecryptable|$iv|$ct")`.
3. `payload_sha256 = hash('sha256', $bytes)`.
4. **Parse:** `Canonical::decodeObject($bytes)` (null is Held `MALFORMED`). The S checks:
   - `v = 1`; `client_uuid` and `seq` equal the envelope's; `device_id` = this tablet;
   - `kind` matches `^[a-z][a-z0-9_]{0,29}$`; `recorded_by` and `grant_id` are positive integers;
   - `recorded_at` is a valid client datetime; `clock_offset_ms` is null or an integer with |value| ≤ 604 800 000;
   - `session_id` is null or 64 hex; `depends_on` is null or a uuid; `payload` is an object.

   A failure is Held `MALFORMED`, keeping `claimed_recorded_by` when `recorded_by` was an integer.
5. **Authenticity:** `$g = OfflineGrants::find($grantId)`. It must exist, with `$g.device_id` = this tablet and `$g.user_id` = `recorded_by`, and `hash_equals(Crypto::hmac($g.secret, $bytes), Crypto::unb64urlStrict(mac, 32))` must hold. Otherwise Held `BAD_HMAC`; a shredded secret is also `BAD_HMAC`.

**In `Db::transaction`** (the item's own; re-run on 1213/1205, so every step below is re-runnable):

6. `$d = DeviceRepository::lock($id)` (FOR UPDATE).
7. **Idempotency:** `$row = SyncRepository::find($uuid)`:
   - it exists with the same `device_id` and `payload_sha256` → return the stored result (no write);
   - it exists with the same `device_id`, status `Held`, `reason_code` in (`DECRYPT_FAILED`, `MALFORMED`, `BAD_HMAC`) and `recorded_by IS NULL` (it never authenticated), **and this item passed steps 1-5** → mark it to be **superseded** and continue: step 18 replaces that row with this item, and `Audit::record('sync_item_superseded', 'device', $id, 'Success', 'A record that failed its checks was replaced by the signed original', ['client_uuid' => …, 'previous_reason_code' => …])` is written after the transaction. So junk sent first under a genuine `client_uuid` (a client bug, or someone with a copied credential who read the plaintext outbox index) can never block the signed record, nor the Push Then Wipe wipe;
   - it exists otherwise → result `conflict` (HTTP 409 for the request). Nothing is written; `sync_conflict` is audited after the transaction. An authenticated row is never replaced.
8. **Held decided in steps 1-5** → insert the Held row and return.
9. **Suspect rule** (under the lock): `revoked_at` set and (`revoked_lost = 1` or `wipe_mode = 'Wipe Now'`) → Held `DEVICE_SUSPECT`, for **every** item whatever its seq or time (C-03).
10. **Clamp** (§8.5) → `$t`; `origin = (received_at − $t ≤ ONLINE_WINDOW_SECONDS) ? 'Online' : 'Offline'` (3 s, X-6).
11. **Retire rule:** `revoked_at` set and `$t > revoked_at` → Held `DEVICE_REVOKED`; `$t ≤ revoked_at` → continue. This runs **before** grant validity, because Retire revokes the grants at the same instant (factual error #20).
12. **Grant validity at `$t`:** `OfflineGrants::validAt($g, $t)` (`created_at ≤ t < expires_at` and (`revoked_at` NULL or `t ≤ revoked_at`)), re-read inside the transaction. Otherwise Held `GRANT_NOT_VALID`.
13. **Actor valid at `$t`:** read `AccountRepository::find($recordedBy)`. Missing or `system` → Held `ACTOR_INVALID`. With `D` = the organisation-local date of `$t` (`Clock::orgToday($t)`, as built), Held `ACTOR_INVALID` if `deactivation_effective_date < D` (**strictly before** the item's day), `expiry_date < D`, `start_date > D`, or `status = 'Inactive'` with no deactivation date.
    - The deactivation day itself is judged by grant validity (step 12), which knows the instant. An immediate deactivation stores `deactivation_effective_date` = today with status `Inactive` (`AccountService.php:244-256`) and revokes the person's grants at that instant (§12.3), so a record made at 10:00 before a 15:00 deactivation is accepted and one made at 16:00 is Held `GRANT_NOT_VALID`. A scheduled date revokes live grants when it is set, and later grants end at 00:00 of that date (D-19). A `≤` comparison would hold that morning's genuine work (REQ-77: "deactivated … at `recorded_at`").
    - "Locked" is **not** a reason: someone else's wrong guesses must not hold real work.
14. **Kind and capability:** no entry in `SyncHandlers::all()[$kind]` → Held `UNKNOWN_KIND`. `$h->capability()` not null and `!Rbac::can($recorder['role'], …)` (the current role) → Held `CAPABILITY`.
15. **Dependency:** with `depends_on` set, `SyncRepository::find()` on this tablet. Missing → Held `DEPENDS_ON_MISSING`; Held → Held `DEPENDS_ON_HELD`.
16. **Session:** a `session_id` is attached only if `user_session` has that id with `user_id = recorded_by` and `device_id` = this tablet; otherwise it is treated as `null`. An `offline_session start` whose id exists for another person or tablet is Held `SESSION_CONFLICT` by its handler.
17. **Apply:**
    - `Audit::setActor(recorded_by, sessionId, device.site_id, device_id)`;
    - `$r = $h->apply(new ItemContext(...))`;
    - restore the actor to the device actor.
18. **Store:** `SyncRepository::insert()` with the status from `$r`, or `SyncRepository::replace()` when step 7 marked a row to be superseded. `refused` inserts nothing and is allowed only when `origin = 'Online'` (inside the answer window, X-6); otherwise it becomes Held `REFUSED_LATE`. Then `DeviceRepository::touchSync($id, Clock::db())`.

**Errors:** a retryable DB error left after `Db::transaction`'s retries, or an unexpected exception, rolls back that item only.
- A retryable error gives result `retry` with `reason_code 'SERVER_ERROR'`, logged with an incident id.
- Any other handler exception rolls back, and a second transaction stores the item Held `HANDLER_ERROR`, so a poison item is visible and never retried for ever. If that second write fails too, the result is `retry`.
- The batch continues either way (plan:533 "a mid-item failure rolls back that item only").

**The Held row:**
- `status 'Held'`, `reason_code`;
- `kind` (S's, or `'unknown'`), `client_seq` (the envelope's);
- `claimed_recorded_by` (S's `recorded_by` when an integer); `recorded_by` only when step 5 passed;
- `recorded_at_client` (`$t`, or `received_at` when unknown); `recorded_at_raw` (S's `recorded_at` when parsed). A row Held in steps 1-5 has `recorded_by` NULL and a receipt time here, so the seq floor (§8.5) and the tablet page's `received_count` read only rows with `recorded_by IS NOT NULL`. (A row Held `DEVICE_SUSPECT` at step 9 also carries the receipt time; a suspect tablet stays suspect, so every later item of it is Held whatever the floor says.);
- `depends_on_uuid`, `payload_sha256`;
- `payload_ciphertext = Crypto::encrypt(bytes or the envelope item's canonical JSON, "sync_item:$uuid")`;
- `origin`, and `received_at = Clock::dbMillis()`.

### 8.4 Idempotency and 409
- **The same `client_uuid` with the same hash** returns the stored row's current status (`accepted`, `held`, later `resolved` …), with no write and no audit (plan:259 "a duplicate push is a no-op").
- **The same uuid with another hash, or a uuid already used by another tablet**, gives `conflict`, and the request answers 409 with the full body. The one exception: a stored row of **this** tablet that never authenticated (Held `DECRYPT_FAILED`, `MALFORMED` or `BAD_HMAC`, `recorded_by` NULL) is superseded by an item with the same uuid that does authenticate (§8.3 step 7).
- **Domain `UNIQUE client_uuid` keys** (distribution, participant, pet, check-in) remain the second line of defence for P3/P4 handlers.

### 8.5 Clock-skew clamp (definition; `Pfpms\Sync\Clamp::at()`)
```
raw       = S.recorded_at                               (the tablet's own clock, ms)
offset    = S.clock_offset_ms  ?? batch skew ?? 0       (signed offset first; the unsigned batch skew only as fallback)
estimate  = raw + offset
t         = min(estimate, received_at)                  (nothing is recorded after the server received it)
floor     = max(grant.created_at, seq_floor)            (not before its grant existed, not before an earlier item of this tablet)
            seq_floor = SELECT MAX(recorded_at_client) FROM sync_item WHERE device_id = ? AND client_seq < ? AND recorded_by IS NOT NULL
                        (authenticated rows only, already clamped; NULL = none)
t         = max(t, floor)
```
- **Storage:** `t` → `sync_item.recorded_at_client`; `raw` → `recorded_at_raw`. |estimate − t| > `sync_clock_skew_minutes` is counted in the batch audit (`skew_seconds` is the batch figure).
- **Why it holds:**
  - An honest tablet's offset is learned at every contact and re-based on backward clock changes inside a page's life (§7.4). Items recorded before and after a backward NTP correction therefore both land at true server time. A forward change is not re-based (it looks like sleep), so until the next contact items are dated later than they were, bounded by `received_at`: that can only Hold them for review, never move them before `revoked_at`. Sleep itself changes nothing, because the offset stays right.
  - Setting a clock back after a revocation cannot pull later items before `revoked_at`: their seqs are higher than every earlier authenticated item's, so the seq floor holds them at or after the latest earlier one.
  - Rows that failed authentication never feed the floor, so one undecryptable, malformed or badly signed item (a client bug, or someone holding only the credential) cannot push the tablet's later genuine items past `revoked_at` or out of their grant.
  - A rollback across a restart makes `clock_offset_ms` null, so the fallback is the batch skew measured at push time with the same (wrong) clock.
  - The offset is inside the HMAC, so a credential holder cannot shift honest items across the Retire cut-off. The unsigned batch skew is used only for items that carry no offset.
- **What it does not defend** (§9): a holder of a *plain-retired* (still trusted) tablet's open vault who signs items with a false offset and seqs in gaps below earlier items. That is why a lost or erased tablet has every upload Held.
- **Payload times:** P4's `distributed_at` goes through `ItemContext::time(string $clientTime)`, the same estimate, bounded by `received_at` and `grant.created_at`. P4 sets `sync_exception 'Clock Skew'` when it moved more than the tolerance.

### 8.6 Held rules (as contracted)
| Case | Rule | Reason code |
|---|---|---|
| Suspect (`revoked_lost = 1` or `wipe_mode = 'Wipe Now'`) | **every** item received once suspect is Held, including seqs below `revoked_max_seq`. Items accepted earlier stay accepted (idempotency returns them). | `DEVICE_SUSPECT` |
| Retire (`Push Then Wipe`, `revoked_lost = 0`) | clamped t ≤ `revoked_at`: processed; later: Held | `DEVICE_REVOKED` |
| Authenticity | undecryptable; not canonical or malformed; unknown grant; a grant of another person or tablet; bad HMAC | `DECRYPT_FAILED`, `MALFORMED`, `BAD_HMAC` |
| Grant not valid at the clamped time (expired, revoked before, issued after) | Held | `GRANT_NOT_VALID` |
| Actor not usable on the organisation date of t | Held | `ACTOR_INVALID` |
| Kind unknown / capability missing | Held | `UNKNOWN_KIND`, `CAPABILITY` |
| Dependency Held / absent | Held | `DEPENDS_ON_HELD`, `DEPENDS_ON_MISSING` |
| Session id of another person or tablet (`offline_session start`) | Held | `SESSION_CONFLICT` |
| Handler crash | Held, with the incident in the log | `HANDLER_ERROR` |
| `refused` (P4) outside the answer window (an Offline item, or a late answer resent by the client, X-6) | Held | `REFUSED_LATE` |

Business-rule failures are never Held (D10): P4 handlers return `acceptedWithException()`. The server never discards anything; `Discarded` is only ever set by a Coordinator's review (P3).

### 8.7 Rescue push
The same endpoint and code path; `rescue: true` is recorded in `sync_push` only. It works for three reasons:
- the tablet always stores and sends ciphertext;
- the server holds the DVK until the wipe is confirmed (or the cron clears it);
- verification uses the grant secret from the database.

A failed-unlock wipe keeps `meta` (credential, proof key) and the outbox, so the next push uploads everything with no person signed in.

### 8.8 Sync status
`sync.checkHeld()` runs at start-up and every 5 minutes while online. It calls `api/sync/status.php` for the tablet's `held` records (≤50 per call) and applies the answers as in §8.1. The header badge shows the `queued` + needs-attention count, and the sync-status view lists `held`, needs-attention and `refused` records.

### 8.9 Notifications and anomaly flags
**After the batch** (one `Db::transaction`, when the batch stored any Held item):
- `Notifications::toRoleOnce('Coordinator', device.site_id, 'sync_held', '{n} records from tablet {label} are held for review.', 'device', id)`;
- when the tablet is suspect **or `site.is_active = 0`**: also `toRoleOnce('Administrator', null, 'sync_held', …, 'device', id)`;
- for each verified recorder with Held items in the batch, one `toUser($uid, 'sync_held', 'Some records you made on tablet {label} are held for review by a Coordinator.', 'device', id)`.

**Anomalies** (`Pfpms\Sync\Anomalies`) run inside the `offline_session` handler's transaction, at start and at end ingestion (D-50). A Station online sign-in or PIN switch runs none: it ends the person's online sessions on other tablets instead (D-17).
- **`overlap($uid, $device, $start, $lastActivity)`:** `SELECT s.device_id FROM user_session s WHERE s.user_id = ? AND s.device_id IS NOT NULL AND s.device_id <> ? AND s.started_at < ? AND s.last_activity_at > ? LIMIT 1`, bound to (uid, device, lastActivity, start). At start ingestion `$lastActivity = $start` (another tablet's session still active after this one began); at end ingestion it is the end item's `last_activity_at` (the two activity intervals intersect). There is no idle extension, so walking from one tablet to another is never flagged.
- **`pinWindow($uid, $device, $start)`** for `Offline PIN` starts: flagged when `SELECT COUNT(*) FROM user_session WHERE user_id = ? AND device_id = ? AND auth_method IN ('Password', 'Offline') AND started_at <= ? AND started_at >= ?` (start, start − `pin_shift_hours`) is 0.
- **A flag writes** `Audit::record('sync_anomaly', 'device', $deviceId, 'Success', '<text>', ['anomaly' => 'overlap'|'pin_window', 'user_id' => $uid, 'other_device_id' => int|null, 'client_uuid' => …])`. It also sends `toRoleOnce('Coordinator', device.site_id, 'sync_anomaly', 'Check tablet {label}: {person} was signed in on two tablets at once' | '… used a PIN offline without a password sign-in that day', 'device', id)`, plus the Administrator copy when the site is inactive. The item is accepted.

### 8.10 Item handlers (registry, P2B kinds, hooks)
```php
namespace Pfpms\Sync;
interface ItemHandler
{
    /** Capability the recorder's current role must hold, or null. */
    public function capability(): ?string;
    /** Apply inside the item's transaction (device row locked). Re-runnable: Db::transaction may run it again. Call the same Services the web pages use. */
    public function apply(ItemContext $ctx): ItemResult;
}
final class ItemContext   // readonly: device (row), user (AccountRepository row), clientUuid, seq, kind, payload (array), recordedAt (clamped), recordedAtRaw, origin, sessionId, grant (row, no secret), receivedAt
{   public function time(string $clientTime): ?\DateTimeImmutable;   // the §8.5 estimate bounded by receivedAt and grant.created_at
    public function entityOf(string $clientUuid): ?array; }           // {entity_type, entity_id} of this tablet's earlier item
final class ItemResult
{   public static function accepted(?string $entityType = null, ?int $entityId = null, ?int $participantId = null, array $fields = []): self;
    public static function acceptedWithException(string $reasonCode, ?string $entityType = null, ?int $entityId = null, ?int $participantId = null, array $fields = []): self;
    public static function held(string $reasonCode): self;
    public static function refused(string $reasonCode, string $message): self; }   // P4; inside the answer window only (stores nothing); else Held REFUSED_LATE
final class SyncHandlers
{   /** @return array<string, ItemHandler> */ public static function all(): array;   // offline_session, station_check; test_noop, test_fail only when Config::env() === 'test'
    public static function override(?array $handlers): void; }                         // tests only
```
`$fields` may carry `participant_code`, `pet_id`, `distribution_id`, `next_eligible_date` and `message` for the response (REQ-85).

**`offline_session`** (capability null):
- **start** `{"event":"start","session_uuid":"<uuid>","factor":"password"|"pin","started_at":"…"}`:
  - `$sid = hash('sha256', 'offline:' . $uuid)`;
  - if a `user_session` row with `$sid` exists for another user or device → `held('SESSION_CONFLICT')`;
  - otherwise `INSERT INTO user_session (session_id, user_id, device_id, site_id, auth_method, started_at, last_activity_at) VALUES (?, ?, ?, ?, ?, ?, ?)`, with `auth_method = factor === 'pin' ? 'Offline PIN' : 'Offline'` and both times `ctx->time(started_at)` at seconds precision. A duplicate key from the same person and tablet is a no-op.
  - Then `Audit::record('offline_login', 'user_account', $uid, 'Success', null, ['factor' => …, 'client_uuid' => …], actor: ['user_id' => $uid, 'session_id' => $sid, 'site_id' => $site, 'occurred_at' => started])`, `Anomalies::overlap($uid, $device, started, started)`, and `pinWindow()` for PIN.
- **end** `{"event":"end","session_uuid":"<uuid>","ended_at":"…","last_activity_at":"…","end_reason":"Logout"|"Timeout"|"PIN Switch"|"Device Lock"|"Permission Change"|"User Switch"}` (with `depends_on` = the start item):
  - `UPDATE user_session SET last_activity_at = ?, ended_at = ?, end_reason = ?, ended_by = ? WHERE session_id = ? AND user_id = ? AND device_id = ? AND (ended_at IS NULL OR (end_reason = 'Timeout' AND ended_by IS NULL))`, so the true end overrides a cron timeout;
  - `Audit::record('offline_logout', …, ['end_reason' => …], actor: [… 'occurred_at' => ended])` when a row changed, then `Anomalies::overlap($uid, $device, started_at of the row, last_activity_at)`.
- Anything else → `ItemResult::held('MALFORMED')`. The result is `accepted('user_session', null)`.

**`station_check`** (capability null): payload `{"note": null|"≤100 chars"}` → `Audit::record('station_check', 'device', $deviceId, 'Success', null, ['client_uuid' => …])` → `ItemResult::accepted()`.

**`test_noop`** accepts and **`test_fail`** throws once per uuid (test env only).

**P3/P4 hooks:**
- **P3** registers `participant_register`, `pet_save` and `check_in`. They use references `{client_uuid}` through `entityOf()`, provisional codes from `meta.seq`, `base_row_version`, the 1062 check-in merge, and `sync_item.participant_id` for erasure.
- **P4** registers `distribution`. It calls `DistributionService::record()` with `allowNegative`, `offset_after_time = substr(recordedAt, 0, 19)`, commit-and-flag, `started_by` in the payload and `cosign` (P4 adds its verification and storage, §3.6), and uses `refused()` inside the answer window.
- **P4's local stock view** ("pack − outbox", plan:334) reads queued distribution lines through `outbox.queuedItems('distribution')`. Accepted items are deleted from the outbox (D-41), so between pack refreshes "pack − outbox" would count them as still in stock: P4 must refresh the pack after each accepted distribution, or keep accepted distribution items (with the `pack_version` they were recorded against) until the next pack.

Each new kind is one class, one registry line and one capability.

---

## 9. Security analysis

Access levels used below:
- *screen-only*: operates the UI;
- *script-in-origin*: runs JavaScript in the origin, for example through developer tools left enabled or an XSS foothold. It can use non-extractable keys in place but never export them;
- *device-image*: the raw storage of a tablet, or a file-level copy of its browser profile (a backup, a synced or copied profile). IndexedDB stores a `CryptoKey`'s material whatever its extractability, so this attacker also has the proof key and the shift key;
- *credential copy*: a script-level export of `meta` (devtools): the credential without the proof key's material;
- *db-reader* / *db-writer*;
- *server*: config file and code.

| Threat | What the attacker gets | Mitigations | Residual (accepted) |
|---|---|---|---|
| **Tablet stolen, hard-locked** (after End shift, the absolute limit, or a reload without a shift key) | `meta` (the credential; the proof key, usable only in place from script, but readable from the profile files by a *device-image* attacker), `keyring` (wrapped DVKs, hashed lookups, no names), sealed stores, outbox ciphertext | <ul><li>The DVK is only under PBKDF2 (≈1 s per guess on the tablet) of passwords ≥12 characters checked against the breached list.</li><li>The progressive delay and the failed-unlock wipe cap screen guessing.</li><li>Online guessing through `login.php` needs the proof (script-in-origin), and is capped by `login:device` 30/15 min, `login:id` 10/15 min and the account lockout.</li><li>Retire "lost or stolen" makes the tablet suspect (every later upload Held) and revokes its grants and sessions at once.</li><li>The runbook resets the passwords of the people listed on the tablet page.</li></ul> | Offline GPU guessing of a weak password by a *device-image* attacker; heartbeats with spoofed figures (display only) |
| **Tablet stolen within the shift window** (shift key present) | As above, plus `meta.shift` | The key is non-extractable; the window is ≤`pin_shift_hours`; lost → suspect → Held; End shift and every hard lock delete it | **While the shift key exists the PIN is a UI control** (REQ-63, plan:117). A *script-in-origin* attacker can open the vault through the shift key, and a *device-image* attacker can read the key's bytes from the browser profile. OS disk encryption and a screen lock are the named defence (runbook; Q6). |
| **Tablet stolen, idle-locked or signed in** | the vault in memory: every record, and every grant key on the tablet | <ul><li>Idle lock after `session_idle_minutes`.</li><li>Hard lock after `session_absolute_hours` since the last password.</li><li>Grants last ≤`offline_grant_hours`.</li><li>The suspect rule.</li><li>Revocation reaches the tablet at its next contact.</li></ul> | While unlocked the thief can read the vault, sign as anyone with a grant on it, and brute-force the offline PIN verifiers (10⁴-10⁶) instantly. With a recovered PIN, an online PIN switch still needs the proof (script-in-origin on this tablet) and the PIN window. |
| **Co-volunteers on a shared tablet** | a colleague's grant key while the vault is open (script-in-origin) | <ul><li>A per-person grant HMAC.</li><li>The picker and an always-visible "Switch user".</li><li>The draft owner is shown.</li><li>Anomaly flags (`overlap`, `pin_window`).</li><li>Devtools and USB debugging disabled on managed tablets (runbook).</li></ul> | The HMAC proves tablet + grant, not which person at the table acted (deviation 7, plan:122). A co-volunteer with devtools can brute-force a PIN offline. Per-user sealed bundles would close this; they are declined for P2B (§How) and put to the client (Q6). |
| **Credential copy** (a devtools export of `meta`) | the credential without the proof key | <ul><li>Sign-in, PIN, the acknowledgement and the password change need the proof, so no session and no key release.</li><li>The **wipe confirmation needs a time-bound proof**, so a copy cannot make a retiring tablet erase unsent records.</li><li>Items need a grant key, so injected items are Held `BAD_HMAC`. Such rows never feed the seq floor, and a genuine item with the same uuid supersedes them (§8.3), so the noise cannot re-date or block real records.</li><li>Unproven calls and a backwards `max_seq` alert Administrators (X-5).</li><li>Push and heartbeat buckets and the bad-record cap bound the noise.</li></ul> | Spoofed heartbeat figures and Held noise until the tablet is retired. End shift can be forced (it only removes access). `status.php` reveals this tablet's item statuses (no names). A **file-level** copy of the profile (backup, device image, synced profile) is *device-image*: it carries the proof key too, and can then confirm a Retire wipe and sign in or PIN-switch from another machine. OS disk encryption and managed-profile rules are the defence (runbook; Q6). |
| **Replay** | old requests | <ul><li>Items are idempotent on `client_uuid` (same bytes: the stored result; other bytes: 409).</li><li>An item replayed to another tablet fails that tablet's DVK and the `device_id` check.</li><li>An old unseen item is judged at its signed time against the grant as it was then.</li><li>Session endpoints need a fresh CSRF token and a live session.</li><li>Proofs bind method, endpoint, body and time (±15 min where enforced).</li><li>The registration replay needs the nonce, which only the tablet has, within 15 minutes.</li></ul> | none |
| **Downgrade** | older formats or code | <ul><li>`v1` in S, in the HKDF info strings, in every AAD and in the proof header.</li><li>The server accepts only `v: 1`.</li><li>PIN hashes name their key (`p1.`).</li><li>An old cached build is reported (`old_build`) and replaced at the next quiet lock screen.</li></ul> | a tablet running an old build until it is next opened online |
| **XSS in the shell** | script in the origin | <ul><li>CSP `script-src 'self'` with no inline script or style, no `eval`, no third-party code.</li><li>Trusted Types on the Station.</li><li>`textContent` rendering, enforced by a source test.</li><li>Keys are non-extractable; `nosniff` everywhere.</li><li>The whole web app shares the origin, so the app-wide CSP is part of this control.</li></ul> | if XSS happened while unlocked: data (not keys) could be read and used in place, and sent only to `'self'` (`connect-src 'self'`) |
| **Cross-site requests** | forged POSTs | Session POSTs need the token plus Origin/`Sec-Fetch-Site`. Device endpoints need a header a cross-site page cannot set without a preflight. No CORS header exists anywhere (a contract test). | — |
| **Server DB leak** (no config) | all tables | DVKs, proof keys, grant secrets and payloads are `Crypto`-encrypted; PINs are peppered with a config-derived key; credentials are the SHA-256 of 256-bit values; passwords are Argon2id | metadata (who, when, which tablet) |
| **Server compromise** | the database and config | none against a full compromise: the server holds every DVK and grant secret by design (rescue push) | a server attacker can open a stolen tablet's database and forge items. The config key ring lives outside the web root. |
| **Lost Wi-Fi mid-push or mid-sign-in** | — | <ul><li>One transaction per item; a lost response is resolved by the idempotent retry; the client deletes only what a response names.</li><li>A sign-in the server committed but whose answer was lost leaves the older grant valid (supersede).</li><li>A lost registration answer is replayed (X-3).</li></ul> | — |
| **Captive portal, wrong clock** | HTML 200s; skewed times | <ul><li>Non-JSON replies count as offline.</li><li>The high-water clock means setting the clock **back** cannot move local time earlier than the last time the Station ran.</li><li>Sleep never freezes local time (only backward jumps are re-based, §7.4).</li><li>At push, the signed offset, the seq floor and the grant floor bound every time.</li></ul> | Setting the clock back to about the last time the Station was open still makes a grant or shift key look valid for as long as the app was closed, and items recorded then are dated in that window at push (the high-water mark advances only while the app runs). Moving the clock **forward** only shortens local expiries, and items are dated later, so at worst Held. |
| **Revocation latency** | offline tablets keep working | Items after `revoked_at` are Held; grants are revoked server-side at once; online tablets learn within 5 minutes and on every push answer | offline tablets learn only at their next contact (deviation 6). An old password unlocks offline until then. |

**Deliberately not protected against:**
- impersonation among people sharing an unlocked tablet;
- a thief who takes a tablet while it is unlocked, or within the shift window with script access;
- a *device-image* attacker without OS disk encryption;
- server or OS compromise;
- people disclosing passwords or PINs;
- back-dating inside a grant's window by someone holding a plain-retired tablet's open vault (§8.5);
- a tablet clock set back to about the last time the Station ran: local expiries then look valid for as long as the app was closed (§7.4).

The failed-unlock counter is plaintext and can be reset by someone who copies the storage; PBKDF2 and the delay are the real controls. All of this goes into the threat-model note (plan:122) and client question Q6.

---

## 10. Failure modes and recovery on a distribution day

| Failure | What happens | What the volunteer sees |
|---|---|---|
| Network drops | Writes keep going to the outbox; pushes back off; sign-in falls back to the offline unlock; PIN switch falls back to the verifier after 3 s | Header "Offline"; "Saved on this tablet"; badge "3 not uploaded"; nothing to do |
| Network returns | `online`/visibility triggers push and heartbeat; items upload; held reasons appear | The badge counts down to "Everything is uploaded" |
| Captive portal | Non-JSON 200 → offline | "No connection", and on About: "The Wi-Fi here may need you to sign in to it first (open a web browser)." |
| Tab or app killed mid-sync | Items committed on the server stay; unanswered ones are resent (idempotent). A half-built item never reached IndexedDB (one transaction with the `seq` check). | PICKER (shift key) or the password screen after reopening; drafts restored after sign-in |
| Tab killed mid-entry (print, camera) | The draft was saved (300 ms debounce, sealed); the shift key reopens the vault | Tiles → PIN → "Open entry started by {name} at {HH:MM}: Continue / Discard" |
| Sign-in response lost | The server committed a new grant; the tablet falls back to the offline unlock with its older, still-valid grant | Nothing unusual; items upload Accepted |
| Registration response lost | The same body is retried; the server answers the same 200 (`replayed`) | "Registered to {site} as {label}." after a retry |
| Storage evicted (not persisted) | The database is gone: credential, keyring, outbox | The device view "Register this tablet". Coordinators already saw "It is not keeping its data" and the unsynced count. Offline is never enabled without persistence, so only in-flight online items can be lost. The new registration is a new tablet row. |
| Tablet clock wrong | Corrected by the signed offset and bounded at push; after a rollback the shift key is dropped and the offset is untrusted until the next contact | Nothing, or "This tablet's clock looks wrong. Use your password to unlock."; the device page says "Its clock is N minutes fast" |
| Service worker update | The new worker precaches and verifies, then waits; it is applied at a quiet lock screen with no draft, no push and no wipe | A quick reload at the lock screen, then PIN |
| Stale or broken file on the CDN | The hash or type check fails, the install fails, and the old version keeps running | Nothing; the device page shows "older version" until the CDN is purged |
| Broken release that installed | The boot watchdog fires | "The Station could not start… Try again / Repair the app"; Repair keeps records and runs only online (offline it says "Repair needs a connection…" and removes nothing); the kill switch covers every tablet |
| Tablet wakes from sleep | Idle uses `serverNow()` as well as the monotonic clock, and the check runs before the view is shown again (§7.4-§7.5) | The idle overlay if the break was long enough; never the previous person's screen |
| Two windows open | Only the primary holds keys | "The Station is open in another window on this tablet." with **Use this window here** |
| Tablet retired mid-event | Server: sessions ended and grants revoked at once. Tablet (≤5 min online, at the next push answer, or at the next contact): the directive → deletes sign-ins and drafts, uploads everything (items after the retirement are Held), erases, confirms | "This tablet has been retired. It is uploading its records, then it will erase itself." then "This tablet has been erased. Its records were uploaded first." |
| Retired, but a record conflicts | The wipe stops at `pushing` | "Retired, but {n} record(s) could not be uploaded, so it has not erased itself. Ask a Coordinator." (an Administrator may Erase now) |
| Tablet erased (Wipe Now) | The directive runs before the lock screen; the outbox is deleted | "This tablet is being erased." then "This tablet has been erased without uploading." |
| Server 500 | That item gets `retry`, or is stored Held `HANDLER_ERROR`; the incident is logged; the others continue | The badge stays; nothing to do |
| Server 502/503/504, SiteGround busy, maintenance | Treated as offline | "Offline" |
| 429 | Backoff with `Retry-After` | Nothing (sign-in: "Too many attempts. Please wait a few minutes and try again.") |
| Session expired on the server | Prevented by the keep-alive while working; after a long break the next session call gives 401 | The re-auth overlay (PIN or password); the open entry is kept |
| Too many wrong unlocks | Delay after the 3rd; failed-unlock wipe at the limit; outbox and drafts kept and uploaded later | "Try again in {n} seconds." then "This tablet has removed its offline sign-ins; its unsent records are kept…" |
| Person's access ended mid-shift | Grant revoked (online) or expired (offline timer) | Their session ends: "Your offline sign-in on this tablet has expired. Sign in with your password when online." |
| `Authorization` stripped (hosting change) | Every device call gets `device_credential_missing`; Administrators are alerted | "The server isn't receiving this tablet's key…"; records kept |
| Vault key cleared by the cron (retired ≥90 days ago, never reconnected) | Push → 409 `vault_key_missing` | "This tablet's records can no longer be uploaded… Ask an Administrator." |

---

## 11. Tests

### 11.1 PHPUnit, per slice
All tests run inside the rolled-back test transaction unless noted. Races use a second connection (`Db::durable()`), as `DeviceServiceTest.php:546` does. No test asserts a durable row that names a device created inside the test transaction (D-51).

**S1**
- **`tests/Unit/RequestTest.php`:** `testAuthorizationFallsBackToTheRedirectVariable`, `testAuthorizationPrefersHttpAuthorization`, `testContentTypeIgnoresParametersAndHttpContentType`, `testScriptPathDropsTheBasePathAndQuery`, `testParseJsonRefusesAnotherMediaType`, `testParseJsonRefusesOversizeBodies`, `testParseJsonRefusesBadJson`, `testParseJsonRefusesAListOrScalar`, `testParseJsonKeepsBigIntegersAsStrings`, `testBodySha256OfTheEmptyBody`.
- **`tests/Unit/JsonTest.php`:** `testStringNeverTrimsAndRespectsTheLength`, `testIntRefusesStringsFloatsAndBooleans`, `testIntRespectsTheRange`, `testBoolOnlyAcceptsTrueAndFalse`, `testListAndObjectAreToldApart`.
- **`tests/Unit/ErrorEnvelopeTest.php`:** `testHttpExceptionBecomesTheEnvelopeWithExtraAndHeaders`, `testValidationExceptionIs422Invalid`, `testJsonExceptionIs400BadJson`, `testOtherErrorsAre500WithAnIncident`, `testEveryStatusHasADefaultCode`.
- **`tests/Unit/CsrfTest.php`:** `testMissingTokenIsCsrfFailed400`, `testCrossSiteOriginIsRefused`.
- **`tests/Unit/ApiOptionsTest.php`:** `testPublicPostNeedsCsrf`, `testDeviceOnlyRequestsNeedNoCsrf`, `testGetNeedsNoCsrf`, `testSessionFalseWithoutDeviceOrPublicIsALogicError`.
- **`tests/Unit/ApiGuardTest.php`** (over the pure `Api::contextFor()` and `Api::reasonCode()`, plus `Api::start()` paths that throw before any session): `testAnotherTabletsSessionIsAbsent`, `testAWebSessionWithATabletIsAbsent`, `testALapsedSiteEndsTheSessionAndIsNeverRePicked`, `testATabletSessionKeepsTheTabletsSite`, `testEachEndedReasonHasIts401Code`, `testMaintenanceIs503WithRetryAfter` (`Config::override(['app' => ['maintenance' => true]])`), `testAWrongMethodIs405WithAllow`.
- **`tests/Unit/CryptoTest.php`** (added): `testOpenRawOpensTheWebCryptoLayout` (§3.9 vector), `testOpenRawRefusesAWrongAad`, `testOpenRawRefusesAShortTag` (openssl would accept a 4-byte GCM tag; `openRaw` must keep its fixed 16), `testUnb64urlStrictRefusesPaddingWhitespaceAndNonCanonicalEndings` (`AA==`, `QU JD`, `AB`), `testUnb64urlStrictChecksTheLength`, `testHkdfMatchesTheStationVector`, `testHmacIsRaw32Bytes`, `testDerivedKeyNeverReturnsTheRingKey`.
- **`tests/Unit/DeviceProofTest.php`:** `testTheFixtureMacVerifies` (§3.9 proof vector), `testAChangedBodyMethodOrEndpointIsInvalid`, `testAMissingOrMalformedHeaderIsMissing`, `testATimestampOutsideFifteenMinutesIsStale`, `testMessageLayoutIsExact`.
- **`tests/Unit/ClockTest.php`:** `testFromClientAcceptsOnlyColumnPrecisionUtc`, `testFromClientRefusesImpossibleDates`, `testOrgDayStartUtcFollowsTheOrganisationZone` (including a DST date).
- **`tests/Integration/SessionTest.php`** (added): `testValidateWithoutTouchDoesNotExtendTheIdleTimer`, `testValidateWithoutTouchStillEndsATimedOutSession`, `testPageSessionReportsWhyASessionEnded`, `testPageResolveStillFlashesAsBefore`.
- **`tests/Unit/WebSessionTest.php`:** `testTheStationCookieIsPfpmsstUnderApi`, `testTheWebCookieIsUnchanged`, over the pure `WebSession::cookieFor()`. The project's shim runner (`tests/run.php`) has no process isolation, so `session_*` calls are not run in unit tests. `restart()` and `destroy()` are covered by E2E steps A6 and A7 (a decline restarts the Station session, and a PIN switch regenerates it, without touching a web tab's cookie).
- **`tests/Integration/TokensPolicyTest.php`** (added): `testIssueRecordsCreatedAt`, `testRevokedGrantIdsListsOnlyThisTabletsRecentGrants`, `testFindAnyReturnsUsedRows`.
- **`tests/Integration/DbAuditTest.php`** (added): `testActorOverrideSetsDeviceAndOccurredAt`.
- **`tests/Integration/Device/DeviceGuardTest.php`:**
  - `testMissingHeaderIsCredentialMissing`, `testMissingHeaderFromTheStationAlertsAdministratorsOncePerHour`, `testAnotherSchemeIsCredentialMissing`;
  - `testMalformedCredentialIsUnknown`, `testUnknownCredentialsAreRateLimitedPerIp`, `testAKnownCredentialIsNeverRateLimited`;
  - `testInServiceModeAcceptsARegisteredTabletAtAnActiveSite`, `testInServiceModeRefusesARevokedTabletWithItsDirective`, `testInServiceModeRefusesATabletWhoseSiteIsInactive`, `testTheGuardNeverTrustsTokenHashAlone` (credential set, `is_site_registered = 0`, not revoked);
  - `testKnownModeAcceptsARetiringTablet`, `testAWipedTabletGets410WithClearSiteData`, `testARevokedTabletCallingInIsAuditedAndAlertedOncePerHour`, `testTheActorCarriesTheSiteAndDevice`;
  - `testARequiredProofIsEnforcedOnlyWhenTheTabletHasAKey`, `testAnInvalidRequiredProofIs401AndRateLimited`, `testAStaleRequiredProofIs401WithServerTime`;
  - `testAnUnprovenCallIsServedButAlertsOncePerHour` (X-5), `testAStaleButValidProofIsNotACloneSignal`.
- **`tests/Integration/Device/DeviceRegistrationTest.php`**, with the T40 six first:
  - `testRacingRedemptionsConsumeTheCodeOnce`: the second redeem gets `code_invalid`, and a named lock held on `Db::durable()` gives 409 `busy`;
  - `testACodeThatExpiresBetweenFindAndConsumeIsRefused`: `Tokens::find()`, then `Clock::advance('+2 hours')`, then `DeviceRegistration::redeemFound()`;
  - `testACreatorWhoWasDemotedDeactivatedOrLostTheSiteIsRefused` (three cases);
  - `testTheRateLimitHitSurvivesAFailedRedemption`;
  - `testTheResponseCarriesNoVaultKeyAndOfflineStaysOff`;
  - `testNoCsrfTokenGives400` (through `Csrf::verify()` with the anonymous session).

  Then:
  - `testAMistypedCodeIs422AndSpendsNothing`, `testABrowserTabIsNotInstalled409`, `testTheDevRelaxationOnlyWorksInDevAndTest`;
  - `testEveryRefusalIsOneGeneric422WithADurableRow`, `testTheCodeNonceAndProofKeyNeverReachAuditOrNotifications`, `testTheCreatorIsNotified`;
  - `testTheCalibratedRoundsAreStored`, `testRoundsOutsideTheRegistryAreIgnored`;
  - `testTheGlobalBucketAlertsAdministratorsButNeverRefuses`;
  - `testTheVaultKeyAndProofKeyDecryptWithTheirDeviceAad`, `testTheCredentialIsDerivedFromTheTokenAndNonce`;
  - `testASameNonceReplayWithinFifteenMinutesReturnsTheSameTablet`, `testAReplayWithAnotherNonceOrProofKeyOrAfterFifteenMinutesIsRefused`;
  - `testMalformedNonceOrProofKeyIs400`.
- **`tests/Integration/Device/DeviceHeartbeatTest.php`:**
  - `testItWritesTheReportedFieldsAndNeverChangesTheRevision` (the pattern of `DeviceServiceTest.php:576`), `testReportedMaxSeqIsWrittenOnlyInServiceAndNeverGoesDown`;
  - `testANotPersistedTabletAndAMalformedPendingCountAreStoredSafely` (`storage_persisted: false`, `pending_count: "3"`: 200, the column 0, the count unchanged), `testReportedTimesAreStoredAtSecondsPrecisionAndNeverInTheFuture` (`oldest_pending_at` with milliseconds and a future `locked_out_since`);
  - `testOfflineEnabledNeedsServicePersistenceStandaloneAndTheSetting`, `testOfflineEnabledIsComputedUnderTheRowPredicate` (retire, then an eligible heartbeat gives 0);
  - `testARetiringTabletGetsPushThenWipeWithoutClearSiteData`, `testAnErasingTabletGetsWipeNow`;
  - `testWipeConfirmationNeedsAValidProof`, `testAStaleProofOnWipeConfirmationIs401WithServerTime`, `testAnUnsignedWipeClaimIsIgnoredAndAudited`, `testWipeConfirmationIsIgnoredFromATabletInService`;
  - `testWipeConfirmationClearsTheVaultAndProofKeysShredsGrantSecretsAndSetsWipedAt`, `testTheCountWarningDropsAnErasedTablet` (`CountRepository::pendingDeviceItems`);
  - `testARoutineHeartbeatWritesNoAuditRow`, `testChangesOfStateAreAudited`, `testAFailedUnlockWipeIsAudited`, `testOfflineSignInFailuresAreOneAuditRowWithClampedTime`, `testAuthFailuresNeverTouchLockoutCounters`;
  - `testAMaxSeqGoingBackwardsRaisesTheCloneSignal`, `testTheHeartbeatBucketLimits`, `testTheResponseCarriesRevokedGrantsConfigAndBuild`.
- **`tests/Integration/Cron/ClearUnconfirmedWipesTest.php`:** `testClearsTheKeysOfTabletsRevokedLongAgoAndNeverConfirmed`, `testLeavesRecentConfirmedAndInServiceTabletsAlone`.
- **`tests/Unit/DeviceStatusTest.php`** (added): `testSequenceGapWarning`, `testNoGapWarningBeforeTheTabletReports`, `testBrowserTabWarning`, `testClockSkewWarningBeyondTheTolerance`, `testLockedOutWarning`, `testAttentionWarning`, `testRowsWithoutTheNewKeysGiveNoWarning` (a row shaped as today's `row()`: no PHP warning, which `failOnWarning="true"` in `phpunit.xml.dist` would turn into a failure). `row()` gains `display_mode`, `clock_skew_seconds`, `locked_out_since`, `attention_count` and `received_count` (all null).
- **`tests/Integration/Device/DeviceServiceTest.php`** (added): `testRedemptionAvailableNeedsTheEndpointAndTheShell`.
- **`tests/Contract/PageContractTest.php`** (added): `testApiEndpointsUseApiStartWithAMethod`, `testPublicApiPostsVerifyCsrf`, `testApiEndpointsNeverTestIsPost`, `testSessionlessApiEndpointsNameADeviceOrPublic`, `testNothingSendsCorsHeaders`. `tests/Unit/RbacTest.php` glob widened.
- **`tests/Integration/SeedDevicesTest.php`:** `testSeededCredentialsMatchTheGuardFormat` (reads `seeds/dev/004_devices.sql`, rebuilds the four strings, checks `DeviceGuard::FORMAT`).

**S2**
- **`tests/Unit/StationAssetsTest.php`:** `testBuildIsAtMost40CharactersAndStartsWithTheVersion`, `testBuildChangesWhenAnyListedFileChanges` (a hash over a temp directory via `StationAssets::hashOf(array $files, string $root)`), `testBuildChangesWhenTheShellOrItsCspChanges` (the shell template, `headerLines()` and `sw.php` are hash inputs; the build itself is not, so there is no cycle), `testEveryStationFileIsListed`, `testPrecacheListHasEveryFileWithItsSha256AndTypeAndTheShell`.
- **`tests/Unit/StationManifestTest.php`:** `testNameIsTheRegistrationSheetAppName`, `testStartUrlAndScopeAreRelative`, `testDisplayIsStandalone`, `testIconsExistWithTheirSizes`.
- **`tests/Contract/StationShellTest.php`:** `testShellHasNoInlineScriptStyleOrHandlers`, `testShellAndWorkerNeedNoSession`, `testWorkerIsServedAsJavaScript`, `testShellHtmlIsDeterministic`, `testKillWorkerNeverMentionsIndexedDb`, `testStationCspHasTrustedTypes`.
- **Parity** (reading `tests/fixtures/*.json`): `CanonicalFixtureTest`, `HmacFixtureTest`, `DatetimeFixtureTest`, `AccentFoldFixtureTest`, `PhoneticKeyFixtureTest`. The existing `RegistrationCodeTest` and `AllotmentCalculatorTest` are unchanged.
- **`tests/Unit/CanonicalTest.php`:** `testDuplicateKeysAreNotCanonical`, `testFloatsAndBigIntegersAreRefused`, `testEmptyObjectAndEmptyArrayStayApart`, `testUnicodeKeysAreRefused`, `testLineSeparatorsAreNotEscaped`.

**S3**
- **`tests/Integration/Station/StationAuthTest.php`:**
  - `testSignInPutsTheTabletOnTheLoginAuditRow`, `testNoSessionWithoutAccessToTheTabletsSite`, `testNoSessionWithoutAnOfflineCapability` (Board), `testTheResponseNeverCarriesAPasswordHash`;
  - `testTheSessionIsPinnedToTheTabletsSiteWithTheTablet`, `testATabletSignInEndsTheTabletsOtherOnlineSessionsAsUserSwitch`, `testASignInEndsThePersonsOnlineSessionsOnOtherTabletsButNotTheirWebSession` (D-17);
  - `testNoGrantOrVaultKeyBeforePolicyAcknowledgement` (plan:260), `testNoGrantOrVaultKeyWhileAPasswordChangeIsForced`, `testNoVaultKeyForASeededTabletWithoutOne`;
  - `testTheGrantIsIssuedBeforeAnySessionChanges` (a test-only hook `OfflineGrants::$beforeIssue`, honoured only when `Config::env() === 'test'`, records the tablet's open-session count at issue time; it must still equal the count before the sign-in), `testATabletRetiredAfterTheGuardReadIsRefused` (the device row is revoked after the guard row was read and before `StationAuth::login()` runs; the `lockShared` re-check gives 403 `device_revoked` and nothing is written);
  - `testTheSiteBucketNeverLocksOutASiteAtShiftStart` (40 sign-ins from one IP over 4 tablets), `testSignInResetsTheOnlinePinCounter`, `testOnlineOnlyTabletsGetAShortGrant`;
  - `testAPreviousUsersPendingGateDoesNotBlockTheNextSignIn`, `testTheProofIsRequired`.
- **`tests/Integration/Station/OfflineGrantsTest.php`:**
  - `testANewGrantSupersedesButDoesNotRevokeTheOlder`, `testTheSecretDecryptsOnlyWithItsRow`;
  - `testExpiryIsCappedBySiteAccessEnd`, `testExpiryIsCappedByAccountExpiryInTheOrganisationZone`, `testExpiryIsCappedByAScheduledDeactivation`, `testExpiryIsCappedByAgreementDueDate`, `testExpiryIsCappedByPasswordAge`, `testSiteAllRolesHaveNoAccessCap`, `testNoGrantWhenTheCapIsNotInTheFuture`;
  - `testValidAtBoundaries` (issued, expiry, and `revoked_at` inclusive), `testFindReturnsTheGrantWhateverItsState`, `testUsedAtIsNeverSet`, `testIssueAndRevocationAreAuditedWithoutSecrets` (`offline_grant_issue` and `offline_grant_revoke {count, cause}` with the REDACT probe; no row when nothing was revoked);
  - `testThePinWindowNeedsAGrantNewerThanEndShift`.
- **`tests/Integration/Account/AccountServiceTest.php`** (extended from :242-289): `testPasswordChangeRevokesOfflineGrants`, `testAccessChangeRevokesOfflineGrants`, `testDeactivationRevokesOfflineGrants`, `testAFutureDeactivationDateRevokesGrantsButKeepsSessions`, `testCredentialResetRevokesGrantsAndClearsThePin`, `testUnlockResetsThePinCounter`, `testScheduledDeactivationRevokesOfflineGrants` (the cron job), `testGrantsAreRevokedBeforeSessionsEnd`.
- **`tests/Integration/Station/StationPinTest.php`:**
  - `testPinIsRefusedOnAnUnregisteredTablet` (plan:258; the guard), `testPinIsRefusedOnAWaitingOrRetiredTablet` (T40);
  - `testPinSwitchEndsThePreviousSessionAndStartsAPinSession`, `testPinSwitchIsFollowedBySessionRegenerationAndCsrfRotation` (a contract check that `public/api/auth/pin.php` calls `WebSession::login()` after the service, plus `Csrf::rotate()` changing `$_SESSION['csrf']`; no `session_*` call runs under the shim runner);
  - `testPinNeedsAPasswordSignInOnThisTabletWithinTheWindow`, `testACredentialEventClosesThePinWindow`, `testEndShiftClosesThePinWindowWithNoSessionOpen`, `testAnOfflinePasswordUnlockDoesNotOpenTheOnlinePinWindow` (an uploaded `Offline` session and no grant: `pin_unavailable`, D-24);
  - `testPinFallsBackToPasswordAfterMaxFailures` (20:283-285), `testWrongPinsAreAuditedWithTriesLeft`, `testPinNeedsThePinSwitchCapabilityAndSiteAccess`, `testThePerTabletBucketLimitsGuessing`;
  - `testAnAttemptIsCountedBeforeThePinIsChecked` and `testParallelAttemptsCannotPassTheLimit`: a test-only hook `Pin::$afterReserve` (honoured only when `Config::env() === 'test'`) runs between the reservation and `Pin::verify()`; with `pin_failed_count` at `pin_max_failed − 1`, a second `pinSwitch()` for the same person started inside it is refused `pin_locked`, even with the right PIN;
  - `testThePinResponseCarriesNoKeys`, `testTheProofIsRequired`.
- **`tests/Integration/Auth/PinSetTest.php`:** `testPinMustBeDigitsWithinTheSettings`, `testTrivialPinsAreRefused`, `testTheCurrentPasswordIsRequiredAndRateLimited`, `testThePinIsPepperedArgon2idNeverSha256`, `testVerifyUsesTheKeyIdInTheHash`, `testChangingAPinRevokesGrantsOnOtherTabletsOnly`, `testSettingTheFirstPinRevokesNothing`, `testTheAuditRowCarriesNoPin`.
- **`tests/Integration/Station/StationPolicyTest.php`:** `testAcceptanceReleasesTheGrant`, `testOnlyTheExactWordingIsAccepted`, `testDeclineEndsTheSessionAndReleasesNothing`, `testTheProofIsRequired`.
- **`tests/Integration/Station/StationPasswordTest.php`:** `testAForcedChangeReleasesKeysInASecondTransaction`, `testTheCurrentPasswordIsRequired`, `testThePolicyRulesApply`, `testTheProofIsRequired`.
- **`tests/Integration/Station/StationLogoutTest.php`:** `testUserLogoutEndsOnlyThatSession`, `testDeviceLockEndsEveryOnlineSessionAndStampsShiftEndedAt`, `testLogoutWithoutASessionIsHarmless`, `testAReplayedOfflineEndShiftNeverEndsALaterSignIn` (End shift at T0 replayed after B's sign-in at T1: `shift_ended_at` = T0, B's session open, B's PIN window open), `testShiftEndedAtNeverMovesBackwards`.
- **`tests/Integration/Station/StationGateTest.php`:** `testGateOrder`, `testBoardHasNoOfflineCapability`.
- **`tests/Integration/Reference/SettingsServiceTest.php`:** `:197` edited to `'5'`/`'5'` and `:186` to `['pin_min_digits' => '6', 'pin_max_digits' => '5']` (§4.3), plus `testPinDigitsCannotExceedSix`.

**S2 (continued)**
- **`tests/Integration/Station/StationCryptoFixtureTest.php`** (with `station_crypto.json` and `vault.js`, moved from S4 so the crypto contract is pinned before S3 and S4 use it): `testPhpOpensTheTabletRecord`, `testPhpVerifiesTheItemMac`, `testPhpUnwrapsTheKeyringWithTheFixtureKek`, `testTheItemBytesAreCanonical`, `testPhpOpensTheVaultUsersRecord`.

**S4**
- PHP: no new suite; `tests/Support/StationClient.php` (for S5) is added.

**S5**
- **`tests/Integration/Sync/SyncServiceTest.php`:**
  - idempotency: `testADuplicatePushIsANoOp`, `testAReusedUuidWithOtherContentGives409`, `testAUuidUsedByAnotherTabletIsAConflict`;
  - authenticity: `testABadMacIsHeld`, `testAGrantOfAnotherPersonOrTabletIsHeld`, `testUndecryptableItemsAreHeldAndStored`, `testNonCanonicalBytesAreHeld`;
  - grant and Retire: `testAnItemRecordedBeforeRevocationIsAcceptedAndOneAfterIsHeld` (plan:533), `testRetireCutOffUsesTheClampedTime`, `testRetireGivesDeviceRevokedNotGrantNotValid`, `testAClockSetBackCannotPassTheCutOff`, `testTheSignedOffsetBeatsTheBatchSkew`, `testTheSeqFloorHoldsLaterItems`, `testUnauthenticatedHeldRowsNeverRaiseTheSeqFloor` (a `MALFORMED` item with seq 1 received after the retirement, then a genuine seq-2 item recorded before it: Accepted), `testAnItemRecordedAfterASleepLandsAfterRevokedAt` (an unchanged offset and a raw time after the retirement: Held `DEVICE_REVOKED`), `testAnExpiredGrantIsHeld`, `testASupersededGrantStillSignsUntilItsExpiry`;
  - suspect: `testASuspectTabletHasEveryItemHeldIncludingBelowTheCutOff` (C-03), `testItemsAcceptedBeforeSuspicionStayAccepted`;
  - actor, kind and dependency: `testADeactivatedActorsLaterItemsAreHeld`, `testItemsRecordedBeforeASameDayDeactivationAreAccepted` (10:00 item, 15:00 immediate deactivation: Accepted; a 16:00 item: Held `GRANT_NOT_VALID`), `testAScheduledDeactivationHoldsItemsFromItsDate`, `testALockedActorIsNotHeld`, `testUnknownKindIsHeld`, `testMissingCapabilityIsHeld`, `testAMissingDependencyIsHeldAndAHeldOneHolds`;
  - superseding: `testAGenuineItemSupersedesAnUnauthenticatedRowWithItsUuid` (stored as the genuine item, audited `sync_item_superseded`), `testAnAuthenticatedRowIsNeverSuperseded` (409), `testAnotherTabletsUnauthenticatedRowIsNotSuperseded`;
  - ordering and failures: `testOutOfOrderSeqIsAccepted`, `testAMidItemFailureRollsBackThatItemOnly` (a failing handler), `testAHandlerCrashIsStoredHeldHandlerError`, `testARetryableErrorGivesRetry` (a handler throwing a 1205 `PDOException`), `testAParallelPushThatStoredTheItemFirstMakesThisOneANoOp`;
  - the rest: `testRescuePushIsTheSamePath`, `testLastSyncAtIsWritten`, `testOriginIsOnlineOnlyWithinTheAnswerWindow` (3 s and 3.001 s), `testARefusalOutsideTheAnswerWindowIsStoredHeldRefusedLate` (a handler returning `refused()` through `SyncHandlers::override()`: nothing stored inside the window, Held `REFUSED_LATE` outside), `testReceivedAtUsesTheFrozenClock`, `testHeldItemsNotifyCoordinatorsOncePerTabletAndAdministratorsWhenSuspectOrSiteInactive`, `testFiftyItemsIsTheMaximum`, `testTheBatchIsAudited`, `testTooManyBadRecordsGives429AndAlerts`, `testAClearedVaultKeyGives409`, `testNothingIsEverDiscardedOrDeleted`.
- **`tests/Integration/Sync/OfflineSessionHandlerTest.php`:** `testStartCreatesAnOfflineSessionRowOnce`, `testPinFactorIsOfflinePin`, `testEndOverridesACronTimeout`, `testEndDependsOnItsStart`, `testAuditRowsUseTheCorrectedClientTime`, `testASessionIdOfAnotherPersonIsSessionConflict`, `testOverlapOnAnotherTabletIsFlagged` (activity on both tablets in the same interval, found at start and at end ingestion), `testAPlainTabletMoveIsNotFlagged` (tablet A last active 10:00, tablet B from 10:05), `testAnOfflinePinWithoutAPasswordThatDayIsFlagged`, `testMalformedPayloadIsHeld`.
- **`tests/Integration/Sync/StationCheckHandlerTest.php`:** `testACheckIsAcceptedAndAudited`.
- **`tests/Integration/Sync/SyncStatusTest.php`:** `testOnlyThisTabletsItemsAreReported`, `testResolvedItemsReportTheirResolution`.
- **`tests/Integration/Cron/PurgeSyncPayloadsTest.php`:** `testPurgesOldPayloadsExceptHeld`, `testClearsSecretsOfLongExpiredGrants`.

### 11.2 JavaScript (`node --test "tests/js/**/*.test.js"`, no dependencies)
**Support:**
- `tests/js/support/memory-db.js`: the `db.js` interface on `Map`s with `structuredClone` and multiEntry indexes;
- `fake-fetch.js`: scripted `Response`s, `TypeError` for offline, delays, an HTML 200 for a captive portal;
- `fake-env.js`: a clock with `advance()`, a monotonic clock that can diverge (a wall clock set back or forward) or **pause while the wall clock advances** (sleep, as `performance.now()` does on Android, ChromeOS and macOS), the display mode, persisted, and seeded random bytes where fixed values are needed;
- `fixtures.js`: reads `tests/fixtures/*.json` via `new URL('../../fixtures/…', import.meta.url)`.

| Slice | Suites and cases |
|---|---|
| S2 | <ul><li>`registration_code.test.js`: the fixture (check, bytes, normalise 16 cases, format, pairing).</li><li>`canonical.test.js`: the fixture (valid and invalid).</li><li>`hmac.test.js`, `datetime.test.js`, `fold.test.js`, `phonetic.test.js`, `calc.test.js`.</li><li>`proof.test.js`: the §3.9 proof vector; the header layout; the body hash of GET; `createProofKey()` exports exactly 32 bytes (43 b64url characters).</li><li>`vault.test.js` (moved from S4 with `vault.js`): the `station_crypto.json` vectors; wrap/unwrap; a wrong password fails; tampered ct/iv/AAD refused; records moved between keys refused; sub-keys non-extractable; calibration clamps; `lookup()` trims as PHP (`" JDoe "`, `"JDoe\t"`) and lower-cases (`"jdoe"`) to the fixture value; `b64urlDecode()` refuses padding and non-canonical endings.</li><li>`clock.test.js`: learn; a backward jump re-bases the offset; a forward jump and a sleep (mono paused, wall advancing) change nothing, `serverNow()` advances across the sleep, and the next boot reports no rollback; a rollback at boot distrusts it; `serverNow()` never goes back.</li><li>`sw_core.test.js`, loaded through `node:vm` with a fake `self`/`caches`/`fetch`: `shouldHandle` never for `/api/`, `sw.php`, non-GET or outside the scope; install fails on a hash or type mismatch and keeps the old cache; activate deletes only other `pfpms-shell-*`; the kill worker unregisters without IndexedDB.</li><li>`api.test.js`: the device header and proof only on device calls; CSRF only on session POSTs; `Content-Type: application/json` on **every** call with a body, device-only POSTs included; `credentials` modes (`omit` for device-only calls, `same-origin` with `clearSite`); a status in `okStatuses` (409) returns the body; the error envelope to `ApiError`; `TypeError`/timeout/503/HTML to offline; one CSRF refresh and retry; one re-base and retry on `device_proof_stale`.</li><li>`db_memory.test.js`: the adapter contract, including `tx` atomicity and multiEntry; a closed adapter throws `DbClosed`; `destroy()` closes before it deletes (a fake `indexedDB` records the order and can fire `blocked`, then the retry).</li><li>`device_register.test.js`: JS normalise before POST; the self-test; the calibration value sent; `persist()` requested; the proof key exported once (32 bytes) then non-extractable; the same body retried after a lost response; the credential stored only in `meta`; messages per error code.</li><li>`device_directive.test.js`: the X-4 stage order; resume after a simulated reload; conflicts stop Push Then Wipe; Wipe Now clears the outbox; the confirmation retried until 200/410 and sent with `clearSite` (`same-origin`); a `DbClosed` after the 200 (storage erased by `Clear-Site-Data`) goes to the final screen; a 410 at boot wipes locally; never `Clear-Site-Data` reliance.</li><li>`heartbeat_body.test.js` (the body fields and bounds; the request carries `Content-Type: application/json`; times in the tablet's raw clock), `update_flow.test.js` (SKIP_WAITING only when locked, no draft, no push, no wipe, 60 s quiet; the first claim of an uncontrolled page does not reload in `app.js`; no reload while a registration or sign-in request is in flight; `update()` at most hourly after a failed install).</li><li>`boot.test.js`: the pure recovery decision; the first-start wait ends on `controllerchange` (then one reload), on a rejected registration, on `SW_KILLED` or after 8 s; a hard reload with an active worker reloads once, never in a loop; Repair is refused when `api/ping.php` fails and removes nothing; Repair is not offered first after an offline failure; `__pfpmsStarted()` is called before the first network request.</li><li>`primary_window.test.js`: a fake LockManager; steal drops the keys.</li><li>`source_rules.test.js`: no `innerHTML`, `eval`, `new Function`, `localStorage` or inline handlers in `public/station/**`; `__pfpms` appears only in `app.js`, inside the `dev_relax` branch (§7.2 dev-only hooks).</li></ul> |
| S3 | <ul><li>`session_online.test.js`: PBKDF2 starts before the response; the keyring is written only when `offline_allowed`, with the username, email and typed-identifier lookups (de-duplicated); `keyring.put` runs before `openVault()` zeroes the raw DVK (the order of §2.3); the vault user is written; the raw DVK is zeroed; the gate → ack flow keeps the KEK ≤10 min and drops the password; decline leaves no keyring; `revoked_grants` delete only matching entries; CSRF rotated; the touch is sent at most every 5 minutes after input.</li><li>`pin_online.test.js`: the verifier is captured after a server OK; `pin_locked`/`pin_unavailable` messages; the 3 s timeout falls back to the verifier.</li><li>`password_change.test.js`: the KEK is re-derived from the new password.</li></ul> |
| S4 | <ul><li>`keyring.test.js`: no username in plaintext; lookup by username, email or the typed form, with surrounding whitespace or another case; expired entries unusable.</li><li>`unlock_offline.test.js`: the generic failure for an unknown user (dummy PBKDF2) and a wrong password; both count toward the delay, so three unknown names bring the same "Try again in {n} seconds" as three wrong passwords (D-30); only the wrong password counts toward the wipe; reset; restricted capabilities; the session start item queued; the vault is opened with a copy of the DVK bytes and the shift key is written before they are zeroed.</li><li>`pin_offline.test.js`: the verifier compare; the window (including after `shift_ended_at`); the in-vault counter; hard lock at max; the "used once online" message.</li><li>`shift_key.test.js`: written at an online sign-in after `keyring.put` and before `openVault()` (§2.3); survives a simulated reload (memory DB kept) and reopens to PICKER; expires; deleted at every hard lock, on `offline_enabled:false` and on a clock rollback; not written when offline is not allowed.</li><li>`timers.test.js`: idle keeps keys; absolute drops keys; grant expiry mid-shift; only user actions extend; a mock clock set back never extends; **after a sleep** (mono paused, wall +2 h) the idle lock fires on the next `visibilitychange` before the view is shown, and the absolute limit and grant expiry advance.</li><li>`drafts.test.js`: 300 ms debounce; exact restore; the owner prompt; survives idle lock and reload.</li><li>`failed_unlock_wipe.test.js`: outbox, drafts and meta kept; keyring, vault_users, sessions, pack and the shift key gone; the flag reported once.</li><li>`outbox_local.test.js`: gap-free seq under a simulated concurrent writer; the S format equals the fixture; the signature verifies.</li><li>`privacy.test.js`: after a full session in the memory DB, no seeded name, username or display name appears in any plaintext field.</li></ul> |
| S5 | <ul><li>`outbox_states.test.js`: every transition of §7.5; never deletes an unanswered record; conflict and invalid are terminal.</li><li>`sync_engine.test.js`: ≤50 items and ≤512 KiB; dependency gating; single flight; the 1.5 s wait resolves then the late answer applies; a `refused` answer after the wait is re-queued and resent no sooner than `ONLINE_WINDOW_SECONDS`; a 409 push applies every item result and marks only the conflict item; triggers; backoff with `Retry-After`; pushes while locked; the `rescue` flag.</li><li>`wipe_push_then_wipe.test.js`: pushes until nothing is queued, then confirms, then deletes; stops while conflicts remain.</li><li>`status.test.js`.</li></ul> |

### 11.3 Parity fixtures (`tests/fixtures/`, read by both suites)
| File | Cases |
|---|---|
| `registration_code.json` | existing (16 normalise cases, including NBSP, NUL, the dotless i, the en dash, tab/CR/LF) |
| `canonical_json.json` | `valid`: {value (as JSON, with `{"$object":{}}` marking empty objects), bytes} for nesting, key order, U+2028/2029, U+007F, controls, `/`, emoji, an empty object vs an empty array, ±(2^53−1). `invalid`: bytes with a reason (duplicate key, float, `1e2`, `2^53`, whitespace, key order, an upper-case key, a non-ASCII key, the lone surrogate `\ud800`, depth 17) |
| `hmac.json` | {key hex, message (utf8 or hex), mac b64url}: the empty message (`9JJAt4qp…`), the §3.9 item, and 1 MiB of `a` |
| `datetime.json` | `format`: {ms, text}, including 0, a leap day and 999 ms; `parse_valid`/`parse_invalid` (`Z`, `+00:00`, `T`, missing millis, 2026-02-30, 24:00); `org_day_start`: {zone, date, utc}, including a DST change |
| `accent_fold.json` | {in, out}: Muñoz, Gonçalves, Øre, Æsir, Straße→strasse, ẞ, Łódź→lodz, Đorđe, Þór→thor, İstanbul→istanbul, **ΣΑΣ → σασ** and **σας → σασ**, "O'Brien" and "O’Brien" → obrien, "Mary-Jane  Smith" → "mary jane smith", NBSP, combining marks, empty |
| `phonetic_key.json` | {in, out}: Robert/Rupert R163, Rubin R150, Ashcraft A261, Tymczak T522, Pfister P236, Honeyman H555, Muñoz M520, González/Gonzales G524, O'Brien O165, Lloyd L300, Straße S362, Łódź L320, Þór T600, İstanbul I235, "Mary-Jane Smith" "M600 J500 S530", "" "" (all verified identical in PHP and JS) |
| `station_crypto.json` | the §3.9 vectors: HKDF record/pin, record seal/open (outbox and vault_users AADs), PIN verifier, PBKDF2 KEK (1 000 rounds), DVK wrap, item canonical bytes, SHA-256, item mac, keyring lookups (including `" JDoe "`, `"JDoe\t"` and `"jdoe"` → the `JDoe` value), proof message and mac, and strict base64url refusals (`AA==`, `AB`, `QU JD`) |
| `allotment_calculator.json` | existing (`calc.js`) |

**Accent fold v1** (`Pfpms\Text\Fold::fold()`, `fold.js accentFold()`):
1. NFC → NFD (`Normalizer::FORM_D` / `normalize('NFD')`).
2. Delete `\p{Mn}`.
3. Map ß ẞ→ss, æ Æ→ae, ø Ø→o, œ Œ→oe, ł Ł→l, đ Đ ð Ð→d, þ Þ→th, ı→i, ħ Ħ→h, ŋ Ŋ→n, ſ→s.
4. Lower-case **per code point**: PHP `implode('', array_map('mb_strtolower', mb_str_split($s)))`; JS `[...s].map(c => c.toLowerCase()).join('')`.
5. **Map ς (U+03C2) → σ (U+03C3)**, so PHP 8.2 and 8.3 and JS agree (factual error #2).
6. Delete `’ ' ʼ ` ´`.
7. Replace runs of `[^\p{L}\p{N}]` by one space, and trim.

**Phonetic key v1** (`Fold::phonetic()`, `phoneticKey()`): American Soundex per word of `fold(s)`, keeping `a-z` only:
- the first letter upper-case;
- codes: b f p v = 1; c g j k q s x z = 2; d t = 3; l = 4; m n = 5; r = 6;
- the vowels a e i o u y separate equal codes; h and w do not;
- the first letter's code suppresses an equal following code;
- pad with 0 to 4.

Words are joined by one space, and words with no letter are dropped.

### 11.4 Manual E2E script (`http://localhost:8088`): the browser pane, then a real Chrome profile
**Where each part runs.** The desktop app's browser pane cannot run a service worker: `navigator.serviceWorker.register()` fails there with "An unknown error occurred when fetching the script" before any request reaches the server, for a plain `.js` worker too (checked on Chromium 152 against `php -S`, on `localhost` and `127.0.0.1`). IndexedDB, Cache Storage, Web Locks and `fetch` work there. So:
- **Part A (the pane)** covers everything that needs no service worker. `boot.js` imports the app from the network when registration fails (§7.7), so the Station runs there as an uncontrolled page.
- **Part B (a real Chrome profile, the user's hands)** covers installation, the precache, reload while offline (plan:256), Repair, the update flow and the kill switch. To take "the network" away there, stop the dev server. Playwright with `channel: 'chrome'` can drive Part B later, with the user's approval.

**Prerequisites:**
- the dev database migrated through 0013;
- the dev `config/config.php` (the user checks it; it is not read here) has `app.base_url = 'http://localhost:8088'` and `station.dev_relax_install = true` (Part A);
- an Administrator **A**, a Coordinator **C** and a Volunteer **V** at Dev Site North with known passwords; A and V have accepted the confidentiality agreement, and C's acceptance is part of step A6;
- always `localhost`, never `127.0.0.1` (a different origin);
- the dev hooks `window.__pfpms.debug` (§7.2), which exist only while `api/session.php` reports `dev_relax: true`.

**Part A: the browser pane**
1. `preview_start` `pfpms-dev` (or `navigate` to `http://localhost:8088/login.php`). Sign in as C; on `admin_devices.php` add "E2E 1" at Dev Site North, open the registration sheet, and read the code with `get_page_text`.
2. `tabs_create` → `navigate` `http://localhost:8088/station/`. With `javascript_tool`:
   - `navigator.serviceWorker.controller` is null and `await caches.keys()` is empty (the pane runs the app uncontrolled);
   - `(await (await fetch('../api/ping.php')).json()).build` equals `document.documentElement.dataset.build`;
   - the lock screen shows the organisation name, "Pet Pantry Station" and the build (REQ-25).
3. In the device view, type the code → "Registered to Dev Site North as E2E 1". A `javascript_tool` dump of IndexedDB `pfpms` shows `meta.device.credential` starting `pfd1_`, `meta.device.proof` as a `CryptoKey` with `extractable: false`, and an empty `keyring`. In the admin tab, reload `admin_device_edit.php`:
   - "Works online only", with the warning "It is not keeping its data, so it cannot work offline…": the pane reports `storage_persisted: false`, and the relaxation changes only the `offline_enabled` computation, never what is stored (D-12);
   - `SELECT offline_enabled, storage_persisted, display_mode FROM device WHERE label = 'E2E 1'` gives `1, 0, 'browser'`;
   - the build equals the ping build, the rounds are shown, and "Running as: a browser tab (browser)".
4. With the relaxation off (config) and a second tablet row, registering from the pane gives "Open the installed app from the home screen, then scan the code again." (409). Turn the relaxation back on.
5. In the Station, sign in as V → home; the console shows `[pfpms] sign-in <ms>`. Set a PIN (4821) through **Set a PIN**. The dump shows one `keyring` record whose `lookup` holds the username, email and typed-identifier hashes (de-duplicated), no `username` field anywhere in plaintext stores, `vault_users` only `{k, iv, ct}`, and (from S4) `meta.shift` present. `read_page` on every view visited shows "Switch user", "End shift / Lock device", the person's name and (from S5) the unsynced badge (REQ-54).
6. **plan:260.** With a new agreement version published, sign in as C → the ack view; `read_network_requests` shows the login response `release: null`. Choose "I do not accept" → "You need to accept the confidentiality agreement to use the Station. You have been signed out."; the dump shows no keyring entry and no `vault_users` record for C. Sign in as C again → the ack view → "I accept" → `api/auth/policy.php`'s response carries `release`.
7. **PIN online:** "Switch user" → C's tile → password (C has no PIN) → home as C. "Switch user" → V → 4821 → home as V (`[pfpms] pin <ms>` < 5000). The dump shows V's `vault_users` record changed (verifier stored).
8. **Offline:** `preview_stop`. "Switch user" → V → 4821 → home with "Working offline" (an offline PIN). The badge (S5) shows "1 not uploaded": the new offline session's start item; the earlier online session is left to time out on the server (§2.3).
9. **Reload with the shift key, then an offline password unlock** (the pane half of plan:256): `preview_start` and reload the Station tab → PICKER (the shift key reopened the vault; the pane loads the page from the network). `preview_stop` → V → 4821 → home offline (an offline PIN after a reload). Then **End shift** → the password screen → sign in as V → "No connection: you are working offline."; `[pfpms] unlock <ms>`. A reload with the server stopped needs the service worker: B3.
10. **Reconnect:** `preview_start`. Within 30 s the badge reaches "Everything is uploaded". In a terminal:
    - `SELECT auth_method, started_at, ended_at, end_reason FROM user_session WHERE device_id = <id>` shows `Offline PIN` and `Offline` rows with their true end reasons;
    - `SELECT status, recorded_at_client, recorded_at_raw FROM sync_item` shows all `Accepted`;
    - `SELECT shift_ended_at FROM device` is set, to the time of the offline End shift (earlier than the upload: it was replayed with its own time, §6.8);
    - the admin page's "Last upload" is set.
11. **Check this tablet:** About → **Check this tablet** → "Checked"; an audit row `station_check` exists.
12. **Held notification and Retire:** in the admin tab, Retire "E2E 1" (not lost), then `preview_stop` at once. On the Station, "Switch user" → V → 4821 (an offline PIN: it queues the end of step 9's open offline session and a new start, both recorded after the retirement). `preview_start`. Within 60 s:
    - the next push uploads both and answers `held` (`DEVICE_REVOKED`) with the directive;
    - the retired overlay shows, the tablet erases, then "This tablet has been erased. Its records were uploaded first.";
    - the dump shows database `pfpms` gone and `caches.keys()` empty (the confirmation went with credentials, so `Clear-Site-Data` applied, and the tablet deleted by itself as well);
    - the admin page shows "Erased {time}";
    - `notifications.php` as C shows "2 records from tablet E2E 1 are held for review.";
    - the Held rows are in `sync_item`, and `vault_key_ciphertext` and `proof_key_ciphertext` are NULL.
13. **Erase now before the unlock screen, with a second window open:** register "E2E 2", sign in, and open `station/` in a second tab (it shows "The Station is open in another window on this tablet."). Reload the first tab → the lock screen. In the admin tab, sign in as A and choose Erase now. Reload the first tab: the erasing overlay appears before any sign-in screen, and the dump shows database `pfpms` gone although the second tab is still open (its connection closed itself on `versionchange`, §7.2), and `caches.keys()` empty. (The service-worker part: B5.)
14. **Failed-unlock wipe keeps the outbox (plan:261):** register "E2E 3"; sign in as V online; `__pfpms.debug.draft('e2e draft')`; `preview_stop`; sign in offline once (items queue); End shift. Ten wrong passwords → "Try again in {n} seconds" after the 3rd (`__pfpms.debug.skipDelay()`) → the failed-unlock message. The dump shows `outbox` count > 0, the draft still in `drafts`, `keyring` and `vault_users` empty, and `meta.failed_unlock_wipe_pending = true`. (From S5:) `preview_start` → the heartbeat audit `device_unlock_wipe`, and the items upload `Accepted` (rescue).
15. **Kill mid-push:** sign in as V, `preview_stop`, `__pfpms.debug.record('station_check', 20)` (20 items queued), `preview_start`, and `tabs_close` the Station tab during the push; reopen. There is no lost or duplicated item (the admin received count equals the tablet's seq).
16. **Two windows:** open `station/` in a second tab → "The Station is open in another window on this tablet." → **Use this window here** → the first tab shows "This Station window was replaced…".
17. **Idle expiry and draft restore** (REQ-51, REQ-52; the brief §5 dress-rehearsal hook): as A, set `session_idle_minutes = 5` (the registry minimum). Sign in as V, `__pfpms.debug.draft('idle test')`, and leave the Station untouched for 5 minutes → the idle overlay: the person is hidden, the keys are kept, and the picker shows the tiles. V → 4821 → "Open entry started by {V} at {HH:MM}: Continue / Discard" → **Continue** restores 'idle test' exactly. Set the setting back to 30.
18. **Privacy grep (plan:262):** `javascript_tool` dumps every store of every IndexedDB database (as hex for binary) and the body of every Cache Storage response. It searches for every participant name in `seeds/dev` and for V's and C's usernames and names → 0 hits.
19. **Layout:** `resize_window` preset `mobile` → every Station control ≥48 px (`read_page` + `javascript_tool` `getBoundingClientRect()`), and nothing scrolls sideways. Reset to `desktop`.

**Part B: a real Chrome profile** (the user's hands, desktop Chrome at `http://localhost:8088/station/`)
1. **Install and precache** (REQ-116, REQ-18): with the relaxation **off**, install the Station. The first start reloads once before the device view appears, never after. In DevTools: `(await navigator.serviceWorker.getRegistrations()).map(r => r.scope)` → `…/station/`; `await caches.keys()` → one `pfpms-shell-<build>`, whose request URLs are the precache list (no `/api/`). Registration succeeds standalone, `storage.persisted()` is true, `offline_enabled` becomes 1, and the device page says "Ready to work offline".
2. **Update flow** (REQ-21): change one Station file (for example a comment in `css/station.css`) so the build changes. With V signed in, a new worker installs and waits (`registration.waiting`), and nothing reloads. "Switch user" → the lock screen; after 60 s without input, `SKIP_WAITING` is posted and the page reloads once, back to PICKER. (The "no draft open" half needs a form that writes a draft: P3/P4, or `update_flow.test.js` now.)
3. **Reload offline** (plan:256): sign in as V online, then stop the dev server. Reload → the shell loads from the cache → PICKER (shift key) → V → 4821. End shift, reload → the password screen → offline unlock → "No connection: you are working offline.".
4. **Repair:** with the server still stopped, About → **Repair this app** → "Repair needs a connection…", and nothing is removed (the next reload still starts offline). Start the server → Repair → the worker re-registers, and the records are intact (dump before and after).
5. **Erase now:** repeat A13 here; afterwards `getRegistrations()` is empty as well.
6. **Kill switch** (REQ-22): set `station.sw_kill = true`, then open the Station: the `pfpms-shell-*` caches are removed, the registration is gone, IndexedDB is intact (dump before and after), and the app runs from the network without the 8 s wait (`SW_KILLED`). Set it back to `false`: the next start installs and verifies the worker again.

Draft restore inside real forms is re-run at the P3/P4 exits that first have them (plan:308, plan:339); A17 covers the platform part now.

### 11.5 Hosting checks before and at go-live
1. **Local Apache (S2, with the user's approval for a one-line XAMPP change):** add `Alias /pfpms "C:/Users/maryw/Documents/Pelican/chsPetPantry/public"` with `AllowOverride All`. Set a matching `app.base_url` in a separate dev config. Then run `php bin/station-smoke.php http://localhost/pfpms/` and, in a real Chrome profile (the pane cannot register a worker), E2E step B1 and A2-A3 at `http://localhost/pfpms/station/`. This exercises the `.htaccess` cache override (`<If>` after `<FilesMatch>`, static extensions only), that `station/index.php` and `sw.php` keep bootstrap's `no-store`, `nosniff`, the MIME types of `.js`/`.json`/`.png`, the subdirectory base path, and relative SW registration. XAMPP runs `mod_php`, so the `Authorization` rule proves nothing there.
2. **Staging (S1, mandatory; again after S2):** `php bin/station-smoke.php https://<staging>/`, checking `authorization_received`, the missing and unknown codes, `x-proxy-cache` BYPASS/MISS, and (from S2) the HEAD checks, with NGINX Direct Delivery switched off (§5.7). Any FAIL blocks the next slice.
3. **Production, after every deploy:** the same script. The runbook also has these rules: never deploy during distribution hours without `app.maintenance`; purge Dynamic Cache and the CDN.

### 11.6 Manual and performance checks
- **Where:** on the slowest target tablet (Q9), over staging HTTPS.
- **What:**
  - the registration calibration value;
  - 20 offline unlocks and 20 PIN switches, with p95 from the console timings: unlock ≤3 s, PIN <5 s, including after a forced tab kill (the shift key);
  - online sign-in p95 ≤3 s;
  - DevTools: IndexedDB shows ciphertext only; the SW works offline; throttling.
- **Matrix:** Android Chrome tablet, Chromebook, and the iPad Home Screen app (best effort; it also checks that non-extractable `CryptoKey`s persist).

### 11.7 Mutation targets (a test must fail when one is changed)
- **Push rules:**
  - `>` vs `>=` at the Retire cut-off (`$t > revoked_at`);
  - the suspect `OR` (`revoked_lost = 1 OR wipe_mode = 'Wipe Now'`);
  - the rule order (Retire before grant validity);
  - the `validAt` bounds (`created_at ≤ t`, `t < expires_at`, `t ≤ revoked_at`);
  - the clamp's `min`/`max`, the seq floor, its `recorded_by IS NOT NULL` filter, and the signed offset beating the batch skew;
  - the idempotency hash comparison, and superseding only rows with `recorded_by IS NULL` of the same tablet;
  - the answer window (`received_at − t ≤ ONLINE_WINDOW_SECONDS`) and `REFUSED_LATE` outside it;
  - `Canonical::isCanonical` bypassed;
  - `hash_equals` replaced by `==` or removed;
  - `DEPENDS_ON_MISSING` turned into `retry`;
  - the `ACTOR_INVALID` date comparisons (`deactivation_effective_date < D`, not `≤`).
- **Server trust and sessions:**
  - `IN_SERVICE_SQL` replaced by `token_hash IS NOT NULL`;
  - the `in_service` guard mode on `pin.php`/`login.php`;
  - the proof enforced only when the row has a key, and the timestamp window;
  - `wiped` accepted without `revoked_at` or without a valid proof;
  - `Clear-Site-Data` on a revoked heartbeat;
  - the `offline_enabled` predicate;
  - `reported_max_seq` written when revoked, or allowed to go down;
  - grant revocation before the session end;
  - the grant INSERT before `endOnlineForDevice`;
  - superseding vs revoking at issue;
  - the PIN window's `shift_ended_at` clause, and `GREATEST` in `setShiftEnded()`;
  - the PIN attempt reserved before `Pin::verify()`, and the `pin_failed_count < ?` condition;
  - `'proof' => true` on `login.php`, `pin.php`, `policy.php` and `password.php`;
  - `Api::start` refusing `'session' => false` without `device` or `public`;
  - `touch = false` honoured.
- **Tablet:**
  - the `seq` re-check in `outbox.record()`;
  - the keyring write gated on `offline_allowed`;
  - the outbox and drafts kept at a failed-unlock wipe;
  - `shouldHandle()` excluding `/api/`;
  - the SW hash check;
  - the wipe `confirming` stage before the outbox is empty;
  - re-basing the clock on a forward jump (sleep), and idle measured on the monotonic clock alone;
  - `Content-Type` sent only on session calls;
  - the `controllerchange` reload without the "posted SKIP_WAITING" guard.

---

## 12. Slices

```
S1 (server platform, HTTP core, 0013, unconfirmed-wipe cron) ──► S2 (shell, SW, registration client, parity pack, JS tooling) ──┐
   └──────────────────────────────────────────────────────────► S3 (online sign-in, grants, PIN, ack, password change) ─────────┴─► S4 (vault, offline, shift key, drafts) ──► S5 (push, rescue, status, anomalies, purge, wipes)
```
- **Parallel work:** S3's server half (endpoints, services, PHP tests) runs in parallel with S2 after S1. S3's client half (`session.js` and its views) builds on S2's `api.js`, `db.js`, `clock.js`, `proof.js` and `vault.js`, so it starts once those are merged. `vault.js`'s crypto core and `station_crypto.json` are in S2 for that reason (they were in S4): S2's registration calibrates PBKDF2, and S3 writes keyrings, vault users and verifiers.
- **Each slice** is one commit series with green PHPUnit and (from S2) a green `npm run test:js`, and ends with its doc updates.
- **Estimates:** S1 5-6 d, S2 5-6 d, S3 5 d, S4 5-6 d, S5 5-6 d (25-29 d, plan:242).

### 12.1 S1: server device platform and HTTP core (build-ready)

**First task (mandatory):** deploy the S1 endpoints to staging and run `php bin/station-smoke.php https://<staging>/` (§5.4). If the `Authorization` header does not arrive, stop and fix the hosting before S2 starts.

**Files added**
- `migrations/0013_station_platform.sql` (§4.1).
- `public/api/ping.php`, `public/api/session.php`, `public/api/device/register.php`, `public/api/device/heartbeat.php`.
- `src/Http/Json.php`.
- `src/Device/DeviceGuard.php`, `DeviceProof.php`, `DeviceRegistration.php`, `DeviceRefusal.php`, `DeviceHeartbeat.php`.
- `src/Station/StationConfig.php`, `src/Station/SessionInfo.php`.
- `src/Cron/Jobs/ClearUnconfirmedWipes.php`.
- `bin/station-smoke.php`.
- The S1 tests of §11.1.

**Files changed**
- `src/Http/Api.php`, `Page.php`, `Context.php`, `Request.php`, `Response.php`, `HttpException.php`, `ErrorHandler.php`.
- `src/Security/Csrf.php`, `Crypto.php`; `src/Audit/Audit.php`.
- `src/Auth/SessionStore.php`, `WebSession.php`, `Tokens.php`; `src/Clock.php`.
- `src/Device/DeviceRepository.php`, `DeviceStatus.php`, `DeviceService.php`; `src/Cron/Runner.php`; `src/bootstrap.php`.
- `public/.htaccess`; `public/admin_devices.php` and `public/admin_device_edit.php` (they pass `clockTolerance`); `templates/pages/admin/devices.php` and `templates/pages/admin/device_edit.php`; `config/config.example.php`; `seeds/dev/004_devices.sql`; `bin/schema-check.php`.
- `tests/Unit/RbacTest.php`; `tests/Contract/PageContractTest.php`; `tests/Unit/DeviceStatusTest.php` (its `row()` gains the new keys).
- Docs: `docs/PFPMS_schema_v2_1_changes.md` (the 0013 section, and the verification line at `:5` gains 0013 on MariaDB 10.4 and MySQL 8.4 after the double run), plan §6 (the 0013 row), plan §12 (plan:651 `0002–0013`), and `docs/design/40-design-devices.md` (the S1 part of §13.2).

#### Endpoints (complete source)
No comment may sit on the `<?php` line, or `PageContractTest`'s first regex fails.

`public/api/ping.php`
```php
<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Pfpms\Clock;
use Pfpms\Device\DeviceStatus;
use Pfpms\Http\Api;
use Pfpms\Http\Request;
use Pfpms\Http\Response;

// P2B: is the server there, which Station build does it serve, and did the tablet's Authorization header arrive (50-design §6.1).
// No session, no database, never extends an idle timer.
Api::start(['method' => 'GET', 'public' => true, 'session' => false]);
Response::json(['ok' => true, 'server_time' => Clock::dbMillis(), 'build' => DeviceStatus::currentBuild(),
    'authorization_received' => Request::authorization() !== null]);
```
`public/api/session.php`
```php
<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Pfpms\Http\Api;
use Pfpms\Http\HttpException;
use Pfpms\Http\Json;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\Station\SessionInfo;

// P2B: the Station's CSRF token and who is signed in (REQ-04). GET never extends the idle timer; POST {"action":"touch"}
// is the Station's activity keep-alive (D-05).
$ctx = Api::start(['method' => ['GET', 'POST'], 'public' => true, 'touch' => Request::method() === 'POST']);
if (Request::method() === 'POST') {
    Csrf::verify();
    if (Json::string(Request::json(), 'action', 20) !== 'touch') {
        throw new HttpException(400, 'Unknown action.', 'bad_request', ['field' => 'action']);
    }
}
Response::json(SessionInfo::describe($ctx));
```
`public/api/device/register.php`
```php
<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use Pfpms\Device\DeviceRegistration;
use Pfpms\Http\Api;
use Pfpms\Http\Json;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;

// P2B: the installed Station redeems a registration code (40-design §14.3; 50-design §6.3). Anonymous.
Api::start(['method' => 'POST', 'public' => true]);
Csrf::verify(); // Api::start verified it already; kept explicit (40 §14.3 step 1; contract rule)
$body = Request::json();
Response::json(DeviceRegistration::redeem(Json::string($body, 'code', 100), Json::string($body, 'registration_nonce', 40),
    Json::string($body, 'proof_key', 60), [
        'display_mode' => Json::string($body, 'display_mode', 20),
        'storage_persisted' => Json::bool($body, 'storage_persisted') ?? false,
        'app_build' => Json::string($body, 'app_build', 40),
        'pbkdf2_iterations' => Json::int($body, 'pbkdf2_iterations', 100000, 2000000),
    ], Request::ip()));
```
`public/api/device/heartbeat.php`
```php
<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use Pfpms\Device\DeviceHeartbeat;
use Pfpms\Http\Api;
use Pfpms\Http\Request;
use Pfpms\Http\Response;

// P2B: the tablet reports in and learns its directive, before any sign-in (40-design §14.4). Credential (and proof) only.
Api::start(['method' => 'POST', 'device' => 'known', 'session' => false]);
$result = DeviceHeartbeat::receive(Api::device(), Request::json());
Response::json($result['body'], $result['status'], $result['headers']);
```

#### `Pfpms\Http`
- **`Api`:** §5.1 gives the signatures and the pipeline, including the public, pure `contextFor()` (steps 8-9) and `reasonCode()`, which `ApiGuardTest` drives. Private members:
  - `private static ?array $device = null;`
  - `private static function csrfIfNeeded(array $options, string $method): void`
  - step 0's `LogicException` for `'session' => false` without `device` or `public`.

  Refusals are `throw new HttpException(<status>, <message>, '<code>', <extra>, <headers>)`.
- **`Page`:**
  - `start()` gains `'session' => false` (§5.8).
  - New `public static function session(bool $touch = true, ?string &$ended = null): ?array` (§5.9).
  - New `public static function pickSite(array $user, array $session, ?array $sites = null): array{0: ?int, 1: list<array>}`. It holds the body of today's `resolve()` site lines: drop a lapsed site, auto-pick a single one, and write the change back to `$_SESSION` and `SessionStore::setSite()`.
  - `resolve()` becomes `session()` + today's flashes (for every `$ended !== null`) + `pickSite()`. Its behaviour for web pages is unchanged; `testPageResolveStillFlashesAsBefore` pins it.
- **`Context`:** `__construct(array $user, string $sessionId, ?int $siteId, array $sites, public readonly ?int $deviceId = null, public readonly ?string $authMethod = null, public readonly ?array $device = null)`, promoted with defaults, so every existing `new Context(…)` still works.
- **`Request`:** `authorization()`, `contentType()`, `scriptPath()`, `rawBody(int $maxBytes = 1048576)` (memoised; reads at most `$maxBytes + 1`), `bodySha256()`, `json(int $maxBytes = 65536)` (413 when `strlen(rawBody()) > $maxBytes`), `parseJson()` and `useBody()` (§5.3).
  - `authorization()`: `$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? the case-insensitive 'Authorization' entry : null)`, and only a string.
  - `scriptPath()`: `$script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')); $base = self::basePath(); return str_starts_with($script, $base) ? substr($script, strlen($base)) : ltrim($script, '/');`.
- **`Json`:** §5.3.
- **`Response`:** `json(mixed $data, int $status = 200, array $headers = []): never` sends each extra header with `header("$name: $value")` before the body.
- **`HttpException` and `ErrorHandler`:** §5.2.
  - The `ErrorHandler::handle()` JSON branch: `$status = self::statusFor($e)`; for an `HttpException`, send each of `$e->headers`; the body is `self::jsonPayload($e, $message, $incident)`.
  - The `ValidationException` message is its first error. A raw `JsonException` is a logged 500.

#### `Pfpms\Security`
- **`Csrf::verify()` / `verifyOrigin()`:** `new HttpException(400, '<same text>', 'csrf_failed')`.
- **`Crypto::openRaw(#[\SensitiveParameter] string $key, string $iv, string $sealed, string $aad): string`:**
  - requires `strlen($key) === 32`, `strlen($iv) === 12` and `strlen($sealed) >= 16`;
  - `openssl_decrypt(substr($sealed, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, substr($sealed, -16), $aad)`;
  - `false` → `RuntimeException('Decryption failed')`.
- **`Crypto::unb64urlStrict(string $text, ?int $bytes = null): ?string`** (§3.2): null unless `preg_match('/^[A-Za-z0-9_-]*$/', $text)`, `strlen($text) % 4 !== 1`, `base64_decode(strtr($text, '-_', '+/'), true)` succeeds, `self::b64url($decoded) === $text`, and `$bytes === null || strlen($decoded) === $bytes`. Every tablet-sent base64url value goes through it; `unb64url()` (as built, `Crypto.php:67-74`) stays for server-made values.
- **`Crypto::hkdf(#[\SensitiveParameter] string $ikm, string $info, int $length = 32): string`** → `hash_hkdf('sha256', $ikm, $length, $info, '')`.
- **`Crypto::hmac(#[\SensitiveParameter] string $key, string $data): string`** → `hash_hmac('sha256', $data, $key, true)`.
- **`Crypto::derivedKey(string $info, ?string $kid = null): array`** → `[$id, self::hkdf(self::key($id), $info)]`, with `$id = $kid ?? (string) Config::get('crypto.active')`. It never returns the ring key.

#### `Pfpms\Audit\Audit`
- **`write()`:** the `$actor` override also accepts `device_id` (`array_key_exists('device_id', $actor) ? $actor['device_id'] : self::$deviceId`) and `occurred_at` (`$actor['occurred_at'] ?? Clock::dbMillis()`, already column-precision UTC).
- **Docblocks:** `record()` and `durable()` list the keys `user_id, session_id, site_id, device_id, occurred_at`.

#### `Pfpms\Auth`
- **`SessionStore::validate(string $sessionId, bool $touch = true): array`** (§5.9).
- **`WebSession`:**
  - `public const STATION_COOKIE = 'PFPMSST'; private static bool $station = false;`
  - `public static function cookieFor(bool $station): array{name: string, path: string}` (pure): `['name' => $station ? self::STATION_COOKIE : self::COOKIE, 'path' => Request::basePath() . ($station ? 'api/' : '')]`.
  - `start(bool $station = false)`: `self::$station = $station`, then `session_name()` and the cookie path from `cookieFor($station)`. The rest is as built.
  - `restart()`: `self::destroy(); self::start(self::$station); session_regenerate_id(true);`.
  - `destroy()`: `setcookie(session_name(), '', [... 'path' => $params['path'] ...])`.
- **`Tokens`:**
  - `issueValue()`: `INSERT INTO auth_token (user_id, purpose, token_hash, device_id, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)`, with `Clock::db()`.
  - New `public static function findAny(#[\SensitiveParameter] string $raw, string $purpose): ?array`: `SELECT token_id, user_id, purpose, device_id, expires_at, used_at, revoked_at, created_at FROM auth_token WHERE token_hash = ? AND purpose = ?` (refuses `''` and over 100 characters, as `find()` does).
  - New `public static function revokedGrantIds(int $deviceId): array` (`list<int>`):
    ```sql
    SELECT token_id FROM auth_token
     WHERE device_id = ? AND purpose = 'Offline Grant' AND revoked_at IS NOT NULL AND revoked_at > ?
     ORDER BY token_id
    ```
    bound with `Clock::db(Clock::now()->modify('-168 hours'))`.

#### `Pfpms\Clock`
- **`public static function fromClient(?string $value): ?DateTimeImmutable`:** the regex `^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$`, then `createFromFormat('!Y-m-d H:i:s.v', $v, UTC)`; the round trip must equal the input, else null.
- **`public static function orgDayStartUtc(string $date): DateTimeImmutable`:** `new DateTimeImmutable("$date 00:00:00", new DateTimeZone(self::orgTimeZone()))->setTimezone(UTC)`. `orgTimeZone()` stays private.

#### `Pfpms\Device\DeviceRepository`
All SQL is prepared, with keywords upper-case. It never selects `token_hash`, `vault_key_ciphertext` or `proof_key_ciphertext` into a row.
- **`COLUMNS`** gains `d.pbkdf2_iterations, d.display_mode, d.storage_estimate_kb, d.clock_skew_seconds, d.oldest_pending_at, d.attention_count, d.locked_out_since, d.shift_ended_at`.
- **`DETAIL`** gains `(SELECT COUNT(*) FROM sync_item si WHERE si.device_id = d.device_id AND si.recorded_by IS NOT NULL) AS received_count` (authenticated rows only, so junk sent with a copied credential cannot hide a sequence gap). 0013 adds `ix_sync_item_4 (device_id, recorded_by)` so this count reads the index alone (S1 review).
- **`public static function byCredentialHash(string $hash): ?array`**
  ```sql
  SELECT <COLUMNS>, (d.vault_key_ciphertext IS NOT NULL) AS has_vault_key, (d.proof_key_ciphertext IS NOT NULL) AS has_proof_key,
         (<IN_SERVICE_SQL>) AS in_service, s.name AS site_name, s.time_zone, COALESCE(s.is_active, 0) AS site_active
    FROM device d LEFT JOIN site s ON s.site_id = d.site_id
   WHERE d.token_hash = ?
  ```
- **`public static function vaultKeyCiphertext(int $deviceId): ?string`:** `SELECT vault_key_ciphertext FROM device WHERE device_id = ?`.
- **`public static function proofKeyCiphertext(int $deviceId): ?string`:** `SELECT proof_key_ciphertext FROM device WHERE device_id = ?`.
- **`public static function register(int $deviceId, string $tokenHash, string $vaultKeyCiphertext, string $proofKeyCiphertext, int $registeredBy, string $at, bool $persisted, ?string $displayMode, ?string $appBuild, ?int $iterations): bool`**
  ```sql
  UPDATE device SET token_hash = ?, vault_key_ciphertext = ?, proof_key_ciphertext = ?, pbkdf2_iterations = ?, is_site_registered = 1,
         registered_by = ?, registered_at = ?, offline_enabled = 0, storage_persisted = ?, display_mode = ?, app_build = ?, last_seen_at = ?
   WHERE device_id = ? AND token_hash IS NULL AND revoked_at IS NULL AND wiped_at IS NULL
  ```
  It returns `rowCount() === 1`. `$persisted` is bound as `$persisted ? 1 : 0`.
- **Booleans are always bound as integers** (`$b ? 1 : 0`, the house style of `DeviceRepository.php:158` and `SiteRepository::setActive`). `execute()` binds every value as a string, and PHP turns `false` into `''`; with native prepares (`Db.php:54`) and `STRICT_ALL_TABLES` (`Db.php:21`), `''` into a `TINYINT` is error 1366 and `CAST('' AS SIGNED)` in an UPDATE is a strict-mode error, so every heartbeat from a tablet that is not persisted would answer 500.
- **`public static function heartbeat(int $deviceId, array $r, bool $offlineEligible, ?int $clockSkewSeconds, string $at): void`**
  ```sql
  UPDATE device d
     SET d.last_seen_at = ?, d.app_build = COALESCE(?, d.app_build), d.storage_persisted = ?, d.display_mode = COALESCE(?, d.display_mode),
         d.pending_count = COALESCE(?, d.pending_count), d.attention_count = ?, d.oldest_pending_at = ?, d.storage_estimate_kb = ?, d.clock_skew_seconds = ?,
         d.locked_out_since = ?,
         d.reported_max_seq = IF((<IN_SERVICE_SQL>) AND ? IS NOT NULL, GREATEST(COALESCE(d.reported_max_seq, 0), CAST(? AS SIGNED)), d.reported_max_seq),
         d.offline_enabled = IF((<IN_SERVICE_SQL>) AND CAST(? AS SIGNED) = 1, 1, 0)
   WHERE d.device_id = ? AND d.wiped_at IS NULL
  ```
  - `<IN_SERVICE_SQL>` is the constant. The row predicate is evaluated at write time, so a concurrent Retire is never overwritten with `offline_enabled = 1`.
  - `max_seq` is bound twice. The site's state comes from the guard's read, so no site row is locked.
  - Bindings: `storage_persisted` as `($r['storage_persisted'] ?? false) ? 1 : 0`; the eligibility flag as `$offlineEligible ? 1 : 0`; `pending_count` as an int or null (null keeps the stored value, the column is NOT NULL); `oldest_pending_at` and `locked_out_since` as `Clock::db()` text or null (§6.4).
  - None of the SET columns appears in `IN_SERVICE_SQL`, so MySQL's left-to-right SET evaluation cannot change the predicate.
- **`public static function confirmWipe(int $deviceId, string $at): bool`**
  ```sql
  UPDATE device SET wiped_at = ?, pending_count = 0, attention_count = NULL, vault_key_ciphertext = NULL, proof_key_ciphertext = NULL, offline_enabled = 0
   WHERE device_id = ? AND revoked_at IS NOT NULL AND wiped_at IS NULL
  ```
- **`public static function shredGrantSecrets(int $deviceId): int`**
  ```sql
  UPDATE auth_token SET secret_ciphertext = NULL WHERE device_id = ? AND purpose = 'Offline Grant' AND secret_ciphertext IS NOT NULL
  ```

#### `Pfpms\Device\DeviceGuard` (new)
```php
final class DeviceGuard
{
    public const IN_SERVICE = 'in_service';
    public const KNOWN = 'known';
    public const FORMAT = '/^pfd1_[A-Za-z0-9_-]{43}$/';
    /** @return array the tablet (DeviceRepository::byCredentialHash) + 'proof' => 'valid'|'stale'|'invalid'|'missing'|'none'
     *  @throws HttpException 401 | 403 | 410 | 429 */
    public static function authenticate(#[\SensitiveParameter] ?string $authorization, string $mode, string $ip, string $endpoint, bool $proofRequired = false): array
    /** The credential in "PFPMS-Device <credential>" (scheme case-insensitive, one space), or null for a missing header or another scheme. */
    public static function credential(#[\SensitiveParameter] ?string $authorization): ?string
    /** In service and at an active site: the only trust test (40 §3.2). */
    public static function trusted(array $device): bool      // (int) in_service === 1 && (int) site_active === 1
    /** @return ?array{wipe: string} for a revoked tablet */
    public static function directive(array $device): ?array  // revoked_at !== null ? ['wipe' => wipe_mode] : null
    /** Clone signal (X-5): one audit row and one Administrator alert per tablet per hour. Never refuses. */
    public static function noteUnproven(array $device, string $endpoint, string $signal): void
    private static function revokedContact(array $device, string $endpoint): void
    private static function noteMissingHeader(string $ip, string $endpoint): void
}
```
**`authenticate()`**, in this order:
1. `credential()` null → if `Request::header('X-PFPMS-Client') === 'station'`, `noteMissingHeader()`; then 401 `device_credential_missing` ("The server did not receive this tablet's key.").
2. Not matching `FORMAT`, or `byCredentialHash(Tokens::hash($c))` null → `RateLimit::hit('device_auth:ip:' . $ip, 20, 900)`. False gives 429 `rate_limited` (`Retry-After: 900`); otherwise 401 `device_unknown` ("The server does not recognise this tablet.").
3. `wiped_at` set → 410 `wiped` (`extra ['status' => 'wiped']`, header `Clear-Site-Data: "cache", "storage"`).
4. `Audit::setActor(null, null, site_id, device_id)`.
5. `revoked_at` set → `revokedContact()`.
6. Mode `in_service` and `!trusted()` → 403 with `extra ['directive' => self::directive($d)]`:
   - `device_revoked` ("This tablet has been taken out of service.") when revoked;
   - `device_site_inactive` ("This tablet's site is not active. Ask a Coordinator.") when the site is inactive;
   - else `device_not_registered` ("This tablet is not registered for use.").
7. `$d['proof'] = (int) $d['has_proof_key'] === 1 ? DeviceProof::check((int) $d['device_id'], Request::header(DeviceProof::HEADER), Request::method(), $endpoint, Request::bodySha256(), Clock::now()) : 'none'`.
8. `$proofRequired` and the proof is neither `'valid'` nor `'none'`:
   - `'stale'` → 401 `device_proof_stale` (`extra ['server_time' => Clock::dbMillis()]`);
   - otherwise `RateLimit::hit('device_proof:device:' . $id, 10, 900)` false → 429; else 401 `device_proof_invalid` ("This tablet needs to be registered again. Ask a Coordinator.").
9. Not `$proofRequired` and the proof is `'missing'` or `'invalid'` → `noteUnproven($d, $endpoint, 'proof_' . $proof)`.
10. Return `$d`.

**The helpers** (no transaction is open for any of them):
- **`revokedContact()`:** `if (RateLimit::hit('device_revoked_contact:' . $id, 1, 3600)) { Audit::record('device_revoked_contact', 'device', $id, 'Denied', 'A tablet taken out of service contacted the server', ['endpoint' => $endpoint, 'wipe_mode' => $d['wipe_mode']]); Notifications::toRoleOnce('Administrator', null, 'device_revoked_contact', "{$d['label']} ({$site}) contacted the server after it was taken out of service. It has been told to erase itself.", 'device', $id); }`
- **`noteMissingHeader()`:** bucket `device_header_missing:all` 1/3600; on the first hit, the §5.4 audit row and alert.
- **`noteUnproven()`:** bucket `device_unproven:<id>` 1/3600; on the first hit, `Audit::record('device_unproven', 'device', $id, 'Denied', "A request used this tablet's credential without the tablet's own key", ['endpoint' => $endpoint, 'signal' => $signal])` and `toRoleOnce('Administrator', null, 'device_clone_suspected', "{label} ({site}): a request used this tablet's credential without the tablet's own key, or reported fewer records than before. Someone may have copied its registration. If so, retire it as lost or stolen.", 'device', $id)`.

#### `Pfpms\Device\DeviceProof` (new)
```php
final class DeviceProof
{
    public const HEADER = 'PFPMS-Proof';
    public const WINDOW_SECONDS = 900;
    /** "pfpms/v1/proof\n<METHOD>\n<endpoint>\n<ts_ms>\n<hex sha256(body)>" (§3.2; fixture §3.9). */
    public static function message(string $method, string $endpoint, int $tsMs, string $bodySha256): string
    /** @return ?array{ts: int, mac: string} from "v1 <13 digits> <43 b64url>", else null */
    public static function parse(?string $header): ?array
    /** @return 'valid'|'stale'|'invalid'|'missing' */
    public static function check(int $deviceId, ?string $header, string $method, string $endpoint, string $bodySha256, DateTimeImmutable $now): string
}
```
**`check()`:**
1. `parse()` null → `'missing'`.
2. `$c = DeviceRepository::proofKeyCiphertext($deviceId)` null → `'missing'`.
3. `$key = Crypto::decrypt($c, "device:$deviceId:proof")`.
4. `hash_equals(Crypto::hmac($key, self::message(…, $p['ts'], …)), Crypto::unb64urlStrict($p['mac'], 32) ?? '')` false → `'invalid'`.
5. `abs($now_ms − $p['ts']) > WINDOW_SECONDS × 1000` → `'stale'`; else `'valid'`.

Every secret parameter is `#[\SensitiveParameter]`.

#### `Pfpms\Device\DeviceRegistration` (new)
```php
final class DeviceRegistration
{
    public const REPLAY_MINUTES = 15;
    /** @param array{display_mode: ?string, storage_persisted: bool, app_build: ?string, pbkdf2_iterations: ?int} $facts
     *  @throws HttpException 400 | 409 | 422 | 429 */
    public static function redeem(#[\SensitiveParameter] ?string $code, #[\SensitiveParameter] ?string $nonce, #[\SensitiveParameter] ?string $proofKey, array $facts, string $ip): array
    /** The locked part, for a token row found without a lock (tests find a token, advance the clock, then call this). */
    public static function redeemFound(array $token, #[\SensitiveParameter] string $nonce, #[\SensitiveParameter] string $proofKeyRaw, array $facts, string $ip): array
    /** 'pfd1_' . b64url(HMAC(HKDF(active key, 'pfpms/v1/device-credential'), 'pfpms/v1/device-credential|<token_id>|<nonce>')) */
    public static function credentialFor(int $tokenId, #[\SensitiveParameter] string $nonce): string
    private static function inTransaction(array $token, string $nonce, string $proofKeyRaw, array $facts): array
    private static function replay(string $code, string $nonce, string $proofKeyRaw, array $facts): ?array
    private static function deny(string $reasonCode, string $ip, ?int $deviceId): void   // Audit::durable('device_register', 'device', $deviceId, 'Denied', 'Tablet registration refused', ['reason_code' => $reasonCode, 'ip' => $ip])
}
final class DeviceRefusal extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode) { parent::__construct($reasonCode); }
}
```
**`redeem()`** follows §6.3 steps 1-6 exactly.
- The 422 `code_invalid` message is the §6.3 text.
- `$proofKeyRaw = Crypto::unb64urlStrict($proofKey, 32)`, which must not be null (else 400 `bad_request`, `field: proof_key`); the nonce likewise with 16 bytes.
- The relaxation is `StationConfig::relaxInstallChecks()`.

**`redeemFound()`:** `DeviceLocks::device((int) $token['device_id'], fn() => Db::transaction(fn() => self::inTransaction(...)))`.
- `ValidationException` from `DeviceLocks` → 409 `busy` (its message).
- `DeviceRefusal $e` → after the lock and transaction are gone, `self::deny($e->reasonCode, $ip, $deviceId)` and 422 `code_invalid`.

**`inTransaction()`**, in order:
1. `$d = DeviceRepository::lock($id)`; null or `!DeviceStatus::waitingForRegistration($d)` → `DeviceRefusal('device_not_waiting')`.
2. `$issuer = AccountRepository::lockShared((int) $token['user_id'])`. Unless `$issuer !== null && AccountRules::canHoldSession($issuer) && !AccountRules::mustChangePassword($issuer) && ($s = DeviceScope::forUser($issuer))->can('device.register') && $s->canAddAt((int) $d['site_id'])` → `DeviceRefusal('issuer_not_allowed')`.
3. `Tokens::consume((int) $token['token_id']) !== true` → `DeviceRefusal('code_used')`.
4. `$credential = self::credentialFor(...)`; `$dvk = random_bytes(32)`.
5. `DeviceRepository::register($id, Tokens::hash($credential), Crypto::encrypt($dvk, "device:$id:dvk"), Crypto::encrypt($proofKeyRaw, "device:$id:proof"), (int) $token['user_id'], Clock::db(), $facts…)` false → `DeviceRefusal('device_not_waiting')`.
6. The §6.3 audit and notification.
7. Return the §6.3 body.

**`replay()`** follows §6.3.

#### `Pfpms\Device\DeviceHeartbeat` (new)
```php
final class DeviceHeartbeat
{
    /** @return array{status: int, body: array, headers: array<string, string>} */
    public static function receive(array $device, array $body): array
    /** Typed, bounded, lenient parsing of §6.4 (an invalid optional field becomes null; pending_count null keeps the
     *  stored value; storage_persisted defaults to false). Tablet times go through Clock::fromClient(), get the skew,
     *  are capped at now and become Clock::db() text for the DATETIME(0) columns. */
    private static function report(array $body, ?int $skewSeconds): array
    private static function confirmWipe(array $device, array $report): ?array
    /** @return list<array{user_id: ?int, factor: string, count: int, first_at: string, last_at: string}> (≤20) */
    private static function failures(array $report): array
    private static function response(array $device, array $after): array
}
```
**`receive()`:**
1. `RateLimit::hit('heartbeat:device:' . $id, 120, 900)` false → 429.
2. The skew from `client_now` (`Clock::fromClient()`; null if absent or beyond 7 days), then the report with it.
3. `wiped: true` → the §6.4 confirmation path.
4. Otherwise the §6.4 normal path and response.

#### `Pfpms\Device\DeviceStatus`
- `currentBuild()` stays `APP_VERSION` in S1 (S2 switches it).
- `describe(array $d, DateTimeImmutable $now, string $timeZone, bool $offlineAllowed, string $currentBuild, int $clockToleranceSeconds = 600)` gains these warnings. Every new key is read with `?? null` (as `isset($d['site_active'])` is today, `DeviceStatus.php:112`), so rows without them, such as `DeviceStatusTest::row()` and any list query that does not select them, raise no PHP warning (CI's `phpunit.xml.dist` has `failOnWarning="true"`):
  - **`seq_gap`** (in service or retiring, `reported_max_seq` not null, and `received_count` present): `gap = reported_max_seq − received_count − pending_count > 0` → "{gap} records it numbered have neither reached the server nor been reported as waiting on it. They may be lost; ask who used it." (REQ-14).
  - **In service, `display_mode` not null and ≠ `standalone`:** "It is running in a browser tab, not the installed app, so it cannot work offline. Open the Station from the tablet's home screen."
  - **In service, |`clock_skew_seconds`| > tolerance:** "Its clock is {n} minutes {fast|slow}. Records are timed correctly, but set the tablet's clock to automatic."
  - **`locked_out_since` set (in service or retiring):** "It locked itself after too many wrong passwords ({when}). Its unsynced records are kept; someone must sign in on it with a connection."
  - **`attention_count` > 0:** "{n} record(s) on it could not be uploaded. They are kept on the tablet; tell the Administrator."
- **`templates/pages/admin/device_edit.php`** "What the tablet last reported" gains:
  - Oldest unsynced record ("from {time}");
  - Running as ("the installed app" / "a browser tab ({mode})" / "not reported");
  - Storage used ("{x.y} MB");
  - Clock ("right" / "{n} minutes fast|slow" / "not reported");
  - Encryption strength ("{n} rounds");
  - Records needing attention.

- **The clock tolerance is read once, by the pages:** `public/admin_devices.php` and `public/admin_device_edit.php` pass `'clockTolerance' => Settings::int('sync_clock_skew_minutes', 10) * 60` to `View::render()` (beside `offlineAllowed`, `admin_devices.php:33`, `admin_device_edit.php:133`), and both templates (`devices.php:58`, `device_edit.php:10`) pass it to `describe()`. The list and the tablet page therefore always agree, and no template reads `Settings`.

#### `Pfpms\Device\DeviceService`
`redemptionAvailable()` returns `is_file(APP_ROOT . '/public/api/device/register.php') && is_file(APP_ROOT . '/public/station/index.php')` (false until S2).

`DeviceService::endAccess()` is unchanged: a retirement's grant revocations are already counted in the `device_retire`/`device_erase` audit row's `grants_and_codes_revoked` (`DeviceService.php:396-401`), so they need no separate grant row.

#### `Pfpms\Station`
- **`StationConfig`:**
  - `public static function relaxInstallChecks(): bool` (`in_array(Config::env(), ['dev', 'test'], true) && Config::get('station.dev_relax_install') === true`);
  - `public static function swKill(): bool`;
  - `public static function client(): array` (the §6 `config` object, from `Settings`).
- **`SessionInfo`:** `public static function describe(?Context $ctx): array` (§6.2). `idle_seconds_left` and `absolute_seconds_left` come from one `SELECT started_at, last_activity_at FROM user_session WHERE session_id = ?` and the settings.

#### `Pfpms\Cron\Jobs\ClearUnconfirmedWipes` (new; name `devices:clear-unconfirmed-wipes`)
```sql
SELECT device_id FROM device
 WHERE revoked_at IS NOT NULL AND wiped_at IS NULL AND vault_key_ciphertext IS NOT NULL AND revoked_at <= ?   -- now − sync_payload_retention_days
UPDATE device SET vault_key_ciphertext = NULL, proof_key_ciphertext = NULL
 WHERE device_id = ? AND wiped_at IS NULL AND vault_key_ciphertext IS NOT NULL
```
It runs one `Db::transaction` per device, with `Audit::record('device_vault_key_cleared', 'device', $id, 'Success', 'Never confirmed its erase', actor: ['user_id' => null, 'session_id' => null])`. It is registered in `Runner::jobs()` after `DeactivateDueAccounts`.

#### `src/bootstrap.php`
- `header_remove('X-Powered-By');` in the header block.
- After the `secure_cookies` check: `if (Config::get('station.dev_relax_install') === true && !in_array(Config::env(), ['dev', 'test'], true)) { throw new RuntimeException('Refusing to run with station.dev_relax_install outside dev/test'); }`.

#### `public/.htaccess`
- The `Authorization` rule of §5.4.
- The `<If>` no-cache block of §5.7. It takes effect once `station/` exists; it is harmless before.

#### `config/config.example.php`
- The crypto comment adds: "…and the Station's tablet vault keys, tablet proof keys, offline grant secrets, sync payloads, PIN hashes and tablet credentials (derived at registration). Keep every retired key listed while data made with it may remain (docs/design/50 §3.7)."
- New keys:
```php
'app' => [
    // … existing keys …
    // true while deploying: every API answers 503 "maintenance", so tablets keep their records queued.
    'maintenance' => false,
],
'station' => [
    // Emergency only: sw.php serves a worker that removes the Station's cached files (tablets keep their data).
    'sw_kill' => false,
    // dev/test only (refused elsewhere): treat a browser tab as installed with kept storage, for testing in a normal tab.
    'dev_relax_install' => false,
],
```

#### `seeds/dev/004_devices.sql`
- The credential formula becomes `SHA2(CONCAT('pfd1_', RPAD(REPLACE(LOWER(t.label), ' ', '-'), 43, '0')), 256)`, in the INSERT and in its first `NOT EXISTS`.
- A leading statement re-keys dev databases seeded earlier:
  ```sql
  UPDATE `device` d
    JOIN (SELECT 'Front desk 1' AS `label` UNION ALL SELECT 'Front desk 2' UNION ALL SELECT 'Intake table' UNION ALL SELECT 'Spare tablet') t
      ON d.`token_hash` = SHA2(CONCAT('pfpms-dev-device:', t.`label`), 256)
     SET d.`token_hash` = SHA2(CONCAT('pfd1_', RPAD(REPLACE(LOWER(t.`label`), ' ', '-'), 43, '0')), 256);
  ```
- The comment says the known dev credentials are `pfd1_front-desk-1` followed by `0`s up to 43 characters (dev and test only). The rows have no vault key and no proof key, so they are online-only test tablets (D-46).

#### `bin/schema-check.php`
§4.3.

#### `bin/station-smoke.php`
§5.4 steps 1-4 (S2 adds step 5). It uses `stream_context_create(['http' => ['method' => …, 'header' => …, 'ignore_errors' => true, 'content' => …]])` and `$http_response_header`; there is no curl dependency.

**Tests:** all the S1 names in §11.1. CI also runs `schema-check`, and migrations twice on MariaDB 10.4, MySQL 8.4 and Percona 8.4 (the existing matrix).

**Coverage:**
- **REQs:** REQ-01…12, REQ-14, REQ-16, REQ-20 (the one call site), REQ-32 (the guard's site pinning, `ApiGuardTest`), REQ-34 (the no-touch path and the 401 reasons), REQ-41, REQ-43 (the server primitive), REQ-49 (offline sign-in failures on the heartbeat), REQ-118, REQ-119, REQ-120 (docblock, config, RbacTest, PageContract), REQ-121 (the FK count and the §14.10 edits).
- **Plan exits advanced:** the server half of "Remote wipe works" (plan:261).
- **Stubs left:** `revoked_grants` is always `[]` until S3 issues grants. `currentBuild()` is `APP_VERSION` until S2. `redemptionAvailable()` stays false until S2 adds `station/index.php`, so the P2A "cannot be registered yet" notices stay truthful (REQ-13 flips in S2). There is no Station client yet (manual `curl` and smoke checks only).

### 12.2 S2: Station shell, service worker, registration client, parity pack, JS tooling (contract level)
- **Adds:**
  - `public/station/index.php`, `sw.php`, `manifest.json`, `boot.js`, `css/station.css`, `icons/*`;
  - the modules `app.js`, `router.js`, `dom.js`, `env.js`, `api.js`, `db.js` (all seven stores created), `clock.js`, `canonical.js`, `proof.js`, `vault.js` (the crypto core: `calibrate`, `deriveKek`, `wrapDvk`/`unwrapDvk`, `openVault`, `seal`/`open`, `pinVerifier`, `sign`, `lookup`, `b64urlDecode`; the shift-key functions come in S4), `registration_code.js`, `fold.js`, `calc.js`, `copy.js`, `sw-core.js`, `device.js` (registration, heartbeat, directives, the wipe state machine with an empty outbox, Repair), `views/device.js`, `views/about.js`, `views/wipe.js`, `views/elsewhere.js`, and a minimal `views/login.js` (the organisation name and build). `session.js` and the other views arrive in S3/S4, `drafts.js` in S4, `outbox.js` in S4/S5 and `sync.js` in S5;
  - `tests/fixtures/station_crypto.json` (generated by `tests/js/tools/make-station-crypto-fixture.js`) and `tests/Integration/Station/StationCryptoFixtureTest.php`, moved from S4;
  - `src/Station/StationAssets.php` (`FILES`, `hash()`, `hashOf(array $files, string $root)`, `precache(): list<array{path, sha256, type}>`), `src/Station/StationShell.php` (`html(?string $build = null): string`, which embeds `currentBuild()` when `$build` is null; `headerLines(bool $worker): list<string>`, the exact CSP and header set; `sendHeaders(bool $worker = false): void`, which sends `headerLines()`);
  - `src/Sync/Canonical.php` (`encode`, `decodeObject`, `isCanonical`); `src/Text/Fold.php` (`fold`, `phonetic`);
  - `bin/station-icons.php`;
  - the fixtures `canonical_json.json`, `hmac.json`, `datetime.json`, `accent_fold.json`, `phonetic_key.json`;
  - `tests/js/**` (§11.2 S2, including `vault.test.js`), `tests/js/support/*`, and `tests/js/tools/make-station-crypto-fixture.js`.
- **Changes:**
  - `DeviceStatus::currentBuild()` (D-07);
  - `package.json` (`"type": "module"`, `"test:js": "node --test \"tests/js/**/*.test.js\""`);
  - `.github/workflows/ci.yml` (a job `station-js`: `actions/setup-node` with `node-version: '24'`, then `npm run test:js`, with no install step);
  - `.gitleaks.toml` (allowlist paths `tests/fixtures/station_crypto\.json`, `tests/fixtures/hmac\.json`, `tests/js/`);
  - `bin/station-smoke.php` (step 5);
  - `PageContractTest` (the two S2 methods).
- **Contracts fixed here:** the build string (including the shell, its headers and `sw.php`, D-07), the precache list, the SW behaviour, the first-start rule and the update rule (§7.7), the `api.js` error mapping and headers (§5.2, §7.2), the registration and heartbeat client bodies (§6.3, §6.4), the proof header (§3.2), the IndexedDB schema v1 (§7.3, all stores created now), the clock model (§7.4), the key hierarchy and record formats (§3, pinned by `station_crypto.json`), canonical JSON v1, datetime, fold and phonetic.
- **Verify:** the S2 JS and PHP suites; E2E steps A1-A4, A16, A19 and B1, B2, B4, B6; the local Apache check (§11.5.1); the staging smoke with the HEAD checks and Direct Delivery off.
- **REQs:** REQ-13, REQ-17…28, REQ-37 (schema), REQ-38 and REQ-39 (the record and KEK crypto), REQ-40 (calibration), REQ-42 (the Cache Storage part), REQ-95 (wipe mechanics with an empty outbox), REQ-99…101, REQ-103…108, REQ-109, REQ-110 (calc), REQ-112…114.
- **Exits advanced:** registration-code parity (plan:250); the client half of "Remote wipe works" (plan:261: Erase now and Retire erase, with nothing to upload yet); a privacy grep baseline.
- **Stubs:** no sign-in (the login view shows the lock screen only); `outbox.clear()` only.

### 12.3 S3: online sign-in, gates, grants, PIN, acknowledgement, password change (contract level)
- **Adds:**
  - `public/api/auth/{login,pin,pin_set,logout,policy,password}.php`;
  - `src/Station/StationAuth.php` (`login`, `pinSwitch`, `logout`, `policyDocument`, `decidePolicy`, `changePassword`, `publicUser`);
  - `src/Station/StationGate.php` (`access(array $user, array $device): ?string`, `release(array $user, array $device): array{release: ?array, unavailable: ?string}`, `offlineCapabilities(string $role): list<string>`, `lockDevice(int $deviceId): array`);
  - `src/Station/OfflineGrants.php` (`issue`, `expiry`, `find`, `validAt`, `pinWindow`, `revokeForUser(int $userId, string $cause, ?int $exceptDeviceId = null): int`, and a test-only `public static ?Closure $beforeIssue`, called only when `Config::env() === 'test'`);
  - `src/Auth/Pin.php` (`set`, `problems`, `target`, `reserveAttempt(int $userId, int $max): ?int` (the count after the reservation, null at the limit), `hash(int $userId, string $pin): string`, `verify(int $userId, string $pin, string $stored): bool`, and a test-only `public static ?Closure $afterReserve`);
  - the views `login.js` (online sign-in, picker, PIN pad), `ack.js`, `password.js`, `pin_set.js`, `home.js`, and the online part of `session.js` (including the touch keep-alive);
  - the tests of §11.1 S3 and §11.2 S3.
- **Changes:**
  - `Auth`: `attemptStation()` on a shared private core `core(string $identifier, string $password, array $details, list<array{string,int,int}> $buckets, ?callable $access, ?callable $beforeUpdate, callable $afterUpdate): array`. The core resets `pin_failed_count = 0` on success and unsets `password_hash` in the returned row. `attempt()` keeps its signature, behaviour and buckets.
  - **The revocation hooks**, each `OfflineGrants::revokeForUser($userId, $cause)` (the grant UPDATE below, plus, when it revoked any, `Audit::record('offline_grant_revoke', 'user_account', $userId, 'Success', null, ['count' => n, 'cause' => $cause])` in the caller's transaction; REQ-70's revocation half):
    - `Auth::setPassword` (before `endAllForUser`; cause `password`);
    - `AccountService::update` (inside `if ($accessChanged)`, before `endAllForUser`; cause `access`);
    - `AccountService::deactivate` (**always**, whether now or at a future date; sessions still end only `if ($now)`; cause `deactivate`);
    - `AccountService::resetCredentials` (with the other `revokeAll` calls; its UPDATE also sets `pin_hash = NULL, pin_failed_count = 0`; cause `reset`);
    - `DeactivateDueAccounts` (before `endAllForUser`; cause `deactivate`);
    - a PIN change (`Pin::set`, other tablets only; cause `pin_change`, §6.7).

    A tablet's retirement or erase revokes its grants through `Tokens::revokeForDevice()` as built, and its audit row already counts them (§12.1 `DeviceService`).
  - `AccountService::unlock` also zeroes `pin_failed_count`.
  - `SessionStore::endOnlineForDevice(int $deviceId, string $reason, ?int $endedBy = null, ?string $startedBefore = null): int`: `UPDATE user_session SET ended_at = ?, end_reason = ?, ended_by = ? WHERE device_id = ? AND ended_at IS NULL AND auth_method IN ('Password', 'PIN')`, plus `AND started_at < ?` when `$startedBefore` is given (an End shift replay, §6.8).
  - `SessionStore::endOnlineElsewhere(int $userId, int $deviceId, string $reason, int $endedBy): int` (D-17): `UPDATE user_session SET ended_at = ?, end_reason = ?, ended_by = ? WHERE user_id = ? AND device_id IS NOT NULL AND device_id <> ? AND ended_at IS NULL AND auth_method IN ('Password', 'PIN')`.
  - `DeviceRepository::lockShared(int $deviceId): ?array`: `SELECT <COLUMNS>, (<IN_SERVICE_SQL>) AS in_service FROM device d WHERE d.device_id = ? LOCK IN SHARE MODE`. There is no join, so no site row is locked; the site is read with `SiteRepository::find()`.
  - `DeviceRepository::setShiftEnded(int $deviceId, string $at): void`: `UPDATE device SET shift_ended_at = GREATEST(COALESCE(shift_ended_at, ?), ?) WHERE device_id = ?` (`$at` bound twice; never moves backwards).
  - `Policy::dueAgainOn(array $user): ?string` extracted from `acknowledgementRequired()`; `SiteAccess::accessEnd(array $user, int $siteId): ?string` (NULL = no end).
  - `settings_registry.php`: PIN digits max 6; `SettingsServiceTest.php:186` and `:197` (§4.3).
- **SQL (grants):**
  - **issue:** `INSERT INTO auth_token (user_id, purpose, token_hash, secret_ciphertext, device_id, expires_at, created_at) VALUES (?, 'Offline Grant', ?, ?, ?, ?, ?)` (no revocation of earlier grants: D-20).
  - **find:** `SELECT token_id, user_id, device_id, token_hash, secret_ciphertext, created_at, expires_at, revoked_at FROM auth_token WHERE token_id = ? AND purpose = 'Offline Grant'`.
  - **revoke for user:** `UPDATE auth_token SET revoked_at = ? WHERE purpose = 'Offline Grant' AND user_id = ? AND revoked_at IS NULL AND expires_at > ?`, plus `AND (device_id IS NULL OR device_id <> ?)` when a tablet is excepted; `rowCount()` is the audited `count`.
  - **PIN attempt reservation:** `UPDATE user_account SET pin_failed_count = pin_failed_count + 1 WHERE user_id = ? AND pin_failed_count < ?`, then `SELECT pin_failed_count FROM user_account WHERE user_id = ?` (§6.6).
  - **PIN window:** `SELECT t.token_id FROM auth_token t JOIN device d ON d.device_id = t.device_id WHERE t.purpose = 'Offline Grant' AND t.user_id = ? AND t.device_id = ? AND t.revoked_at IS NULL AND t.expires_at > ? AND t.created_at >= ? AND (d.shift_ended_at IS NULL OR t.created_at > d.shift_ended_at) ORDER BY t.token_id DESC LIMIT 1`.
  - **access end:** `SELECT MAX(COALESCE(ends_at, '9999-12-31 23:59:59')) FROM user_site_access WHERE user_id = ? AND site_id = ? AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?)`.
- **Contracts fixed here:** the sign-in, PIN, PIN set, logout, password and policy shapes (§6.5-§6.10); the grant (D-20) and its expiry (D-19); the `revoked_grants` semantics (D-21); the PIN hash format (D-22).
- **Verify:** the S3 suites; E2E steps A5 (without `meta.shift` and the badge), A6, A7 and A13.
- **REQs:** REQ-29…36, REQ-51 (online), REQ-54…58, REQ-64…70.
- **Exits satisfied:** "PIN refused on an unregistered device" (plan:258); "cannot receive a grant … before policy acknowledgement" (plan:260, the grant half; the pack half is in P3, through `StationGate::release()`).
- **Stubs:** the keyring, `vault_users` and verifiers are written (with S2's `vault.js`), but nothing uses them offline until S4; there is no shift key; there are no offline paths.

### 12.4 S4: vault, offline unlock, offline PIN, shift key, locks, drafts, failed-unlock wipe (contract level)
- **Adds:**
  - `vault.js`'s shift-key functions (`writeShift`, `openShift`, `dropShift`; the crypto core is S2's), `drafts.js`;
  - the offline part of `session.js`, with timers (idle on the monotonic clock and `serverNow()`, §7.4), the unlock delay and the shift key;
  - the local part of `outbox.js` (`record()` with the gap-free `seq`, used for `offline_session` items);
  - the S4 JS suites of §11.2;
  - `tests/Support/StationClient.php` (a PHP helper that seals and signs like a tablet, for the S5 tests).
- **Changes:** `views/login.js` (offline unlock, offline PIN, PICKER after a reload, the failure and delay messages), `home.js` (the offline banner and restricted capabilities), `device.js` (the failed-unlock wipe, `auth_failures`, `failed_unlock_wipe`, `clock_rollback` and `locked_out_since` in the heartbeat; the shift key dropped on `offline_enabled: false`).
- **Contracts fixed here:** the offline use of the keyring and vault records (§3.4-§3.5; their formats are pinned in S2), the signed item S (§3.6), the shift-key record (§3.2), and the `offline_session` payloads (§8.10). All are already exact above.
- **Verify:** the S4 suites; E2E steps A5 (`meta.shift`), A8 and A9 (without the badge), the local half of A14, A17, A18 (vault data) and B3; the performance runs on the target tablet.
- **REQs:** REQ-37…40 (offline use), REQ-42…53, REQ-59…63, REQ-96, REQ-97 (client), REQ-102, REQ-115.
- **Exits satisfied:** "Offline unlock after reload" (plan:256; E2E B3 in a real Chrome profile, since the pane cannot run the service worker); "PIN switch under 5 s and password unlock ≤3 s on the slowest target tablet" (plan:257, measured on the target tablet); the retention part of plan:261 (a failed-unlock wipe keeps the outbox); the privacy grep of vault data (plan:262).
- **Stubs:** items accumulate in the outbox until S5 pushes them.

### 12.5 S5: push, rescue, status, anomalies, payload purge, wipes end to end (contract level)
- **Adds:**
  - `public/api/sync/push.php`, `public/api/sync/status.php`;
  - `src/Sync/SyncService.php`, `SyncRepository.php`, `Clamp.php`, `ItemHandler.php`, `ItemContext.php`, `ItemResult.php`, `SyncHandlers.php`, `Handlers/OfflineSessionHandler.php`, `Handlers/StationCheckHandler.php`, `Handlers/TestNoopHandler.php`, `Handlers/TestFailHandler.php`;
  - `src/Sync/Anomalies.php` (`overlap`, run at `offline_session` start and end ingestion, and `pinWindow`);
  - `src/Cron/Jobs/PurgeSyncPayloads.php` (`sync:purge-payloads`), registered in `Runner::jobs()`;
  - `sync.js`, the `outbox.js` states and `due()`, `views/sync-status.js`, and the header badge;
  - the tests of §11.1 S5 and §11.2 S5.
- **Changes:** `device.js` (Push Then Wipe uploads the outbox before confirming, and `stuck_conflict`); `DeviceRepository::touchSync(int $deviceId, string $at)` (`UPDATE device SET last_sync_at = ? WHERE device_id = ?`).
- **SQL:**
  - `SyncRepository::find()`: `SELECT client_uuid, device_id, payload_sha256, status, reason_code, entity_type, entity_id, participant_id FROM sync_item WHERE client_uuid = ?`;
  - `insert()`: `INSERT INTO sync_item (client_uuid, device_id, client_seq, kind, origin, claimed_recorded_by, recorded_by, recorded_at_client, recorded_at_raw, received_at, depends_on_uuid, payload_sha256, payload_ciphertext, status, reason_code, entity_type, entity_id, participant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`;
  - the seq floor: `SELECT MAX(recorded_at_client) FROM sync_item WHERE device_id = ? AND client_seq < ? AND recorded_by IS NOT NULL` (authenticated rows only, §8.5);
  - `replace()` (supersede, §8.3 step 7): `UPDATE sync_item SET client_seq = ?, kind = ?, origin = ?, claimed_recorded_by = ?, recorded_by = ?, recorded_at_client = ?, recorded_at_raw = ?, received_at = ?, depends_on_uuid = ?, payload_sha256 = ?, payload_ciphertext = ?, status = ?, reason_code = ?, entity_type = ?, entity_id = ?, participant_id = ? WHERE client_uuid = ? AND device_id = ? AND recorded_by IS NULL AND status = 'Held'` (1 row, or the item is treated as a conflict);
  - the bad-record count: `SELECT COUNT(*) FROM sync_item WHERE device_id = ? AND status = 'Held' AND reason_code IN ('DECRYPT_FAILED', 'MALFORMED', 'BAD_HMAC') AND received_at > ?`;
  - `statuses()`: `SELECT client_uuid, status, reason_code, resolved_at FROM sync_item WHERE device_id = ? AND client_uuid IN (…)`;
  - the session check: `SELECT user_id, device_id FROM user_session WHERE session_id = ?`;
  - the purge: `UPDATE sync_item SET payload_ciphertext = NULL WHERE payload_ciphertext IS NOT NULL AND status <> 'Held' AND received_at < ? LIMIT 1000` repeated until 0 rows, and `UPDATE auth_token SET secret_ciphertext = NULL WHERE purpose = 'Offline Grant' AND secret_ciphertext IS NOT NULL AND expires_at < ? LIMIT 1000`. One audit row per run, `station_retention {payloads_purged, grant_keys_cleared}`;
  - the handler and anomaly SQL of §8.9-§8.10.
- **Verify:** the S5 suites; E2E steps A8 (the badge), A10-A12, the upload half of A14, A15, A18 (final) and B5.
- **REQs:** REQ-15, REQ-48, REQ-49, REQ-71…94, REQ-95…98 (end to end), REQ-116.
- **Exits satisfied:**
  - "A duplicate push is a no-op; a reused uuid with a different payload gives 409; a bad HMAC is Held" (plan:259);
  - "Remote wipe works, and a failed-unlock wipe keeps the outbox" (plan:261, with the rescue upload);
  - the final privacy grep (plan:262);
  - the 40 §14.10 push tests.
- **Stubs left for P3/P4:** only the `offline_session`, `station_check` and test kinds; `refused()`, `cosign` and `entityOf()` are unused hooks; `sync_review.php` (P3) reads the Held rows.
  - `outbox.queuedItems(kind)` exists for P4's local stock view. Because accepted items are deleted (D-41), P4 must refresh the pack after each accepted distribution, or keep accepted distribution items (with their `pack_version`) until the next pack; otherwise "pack − outbox" over-counts stock between pack refreshes.
  - `cosign` is refused when non-null (Held `MALFORMED`); P4 adds its verification and storage (§3.6, §13.1).

### 12.6 Requirement and exit coverage
| REQ | Designed in | Slice |
|---|---|---|
| 01 guard, 02 header, 03 `Request::json`, 04 `session.php`, 05 ping | §5.1, §5.3, §5.4, §6.1-§6.2, §12.1 | S1 |
| 06 redemption, 07 buckets | §6.3, §5.6 | S1 |
| 08 heartbeat, 09 revoked contact, 10 wipe confirmation, 11 directives, 12 state audit, 14 sequence gaps | §6.4, §12.1 (`DeviceGuard`, `DeviceStatus`) | S1 |
| 13 notices flip | §12.1 `redemptionAvailable()` | S2 |
| 15 `last_sync_at` | §8.3 step 18 | S5 |
| 16 unconfirmed-wipe cron, 91 payload purge | D-55 | S1, S5 |
| 17 shell, 18 SW, 19 manifest, 20 build, 21 update flow, 22 kill switch, 23 front-end rules | §7.1, §7.7, D-07-D-09, §5.7-§5.8 | S2 |
| 24 touch targets, 25 organisation name and build, 26 device view, 27 offline = fetch failure, 28 `api.js` | §7.8, §5.2, §7.2 | S2 |
| 29 Station sign-in, 30 release, 31 gates and acknowledgement, 33 logout, 36 Station rate limits | §6.5-§6.10, §5.6, §1.3 | S3 |
| 32 site pinned to the tablet | §5.1 steps 8-9 (`Api::contextFor()`, `ApiGuardTest`) | S1 (guard), S3 |
| 34 401 reasons, no-touch validation, keep-alive | §5.2, §5.9, D-05 | S1, S3 (touch) |
| 35 sign-in ≤3 s, 50 unlock ≤3 s, 62 PIN <5 s | §7.10, §11.6 | S3-S4 |
| 37 stores, 38 per-record encryption, 39 KEK, 40 calibration | §7.3, §3.1-§3.3, D-27 | S2 (schema, calibration, record and KEK crypto), S4 (offline use) |
| 41 server DVK, 43 server open primitive | §3.7-§3.8 | S1 |
| 42 privacy | §11.4 step A18, `privacy.test.js` | S2, S4, S5 |
| 44 offline only with a valid cached sign-in, 45 after reload, 46 restricted session, 47 grant expiry at unlock and on the timer | §2.3, §7.5, §7.8 | S4 |
| 48 offline sessions, 49 offline auth events | §8.10, §6.4 | S1 (failures), S4, S5 |
| 51 idle/hard lock, 52 re-auth overlay and owner prompt, 53 drafts, 54 controls | §7.5, §7.8, §7.2 | S3 (online), S4 |
| 55 PIN set, 56 `pin.php`, 57 online failures, 58 refused on unregistered/waiting/revoked | §6.6, §6.7, D-22-D-25 | S3 |
| 59 offline verifier, 60 in-vault counter, 61 offline window, 63 pin_shift key | §3.2, §2.3, D-24, D-26 | S4 |
| 64 PIN length | §4.3 | S3 |
| 65 grant row, 66 expiry, 67 revocation hooks, 68 revoked grants reach the tablet, 69 valid at t, 70 grant audit | D-19-D-21, §12.3, §6.5 | S3 |
| 71 outbox, 72 online-first, 73 never delete unanswered, 74 engine | §7.3, §8.1, D-41-D-42 | S4-S5 |
| 75 push, 76 idempotency, 77 authenticity Held, 78 Retire, 79 suspect, 80 nothing discarded, 81 clamp | §6.11, §8.3-§8.6 | S5 |
| 82 handlers, 83 dependencies, 84 row contents, 85 per-item response, 86 rescue, 87 sessions/audit ingestion | §8.3, §8.7, §8.10, §6.11 | S5 |
| 88 `status.php`, 89 sync-status view, 90 notifications | §6.12, §7.8, §8.9 | S5 |
| 92 overlap, 93 PIN window flag, 94 where recorded | §8.9, D-17, D-50 | S3 (sessions elsewhere ended at sign-in and PIN), S5 |
| 95 directive order, 96 failed-unlock wipe, 97 only Wipe Now discards, 98 remote wipe works | §7.6, D-30, X-4, §11.4 steps A12-A14 and B5 | S2, S4, S5 |
| 99 standalone + persisted, 100 in-app, 101 platforms, 102 tab kills | D-47, §7.9, D-26, §10 | S1-S2, S4 |
| 103 registration code, 104 canonical, 105 HMAC, 106 fold, 107 phonetic, 108 datetime, 110 calc | §11.3 | S2 |
| 109 record format | §3.9, `station_crypto.json` | S2 |
| 111 `rules.js` | P3 (D-56) | — |
| 112 `package.json`, 113 adapters, 114 CI job, 115 JS coverage, 116 E2E, 117 manual | §11.2, §11.4-§11.6, D-11 | S2-S5 |
| 118 migration 0013, 119 pins, 120 code/doc updates, 121 40-design fixes | §4, §13.2 | S1 (and each slice's docs) |

| Plan P2B exit | Proven by | Slice |
|---|---|---|
| Offline unlock after reload | `unlock_offline.test.js`, `shift_key.test.js`; E2E step B3 (a real Chrome profile), with A9 in the pane | S4 |
| PIN switch <5 s, password unlock ≤3 s on the slowest tablet | §11.6 timings on the target tablet, including after a tab kill | S4 |
| PIN refused on an unregistered device | `StationPinTest::testPinIsRefusedOnAnUnregisteredTablet`, `…WaitingOrRetiredTablet` | S3 |
| Duplicate push no-op; reused uuid 409; bad HMAC Held | `SyncServiceTest::testADuplicatePushIsANoOp`, `…Gives409`, `testABadMacIsHeld` | S5 |
| No grant or pack before policy acknowledgement | `StationAuthTest::testNoGrantOrVaultKeyBeforePolicyAcknowledgement` (the pack half: P3 `pack.php` through `StationGate::release()`) | S3 |
| Remote wipe works; a failed-unlock wipe keeps the outbox | `DeviceHeartbeatTest`, `device_directive.test.js`, `failed_unlock_wipe.test.js`; E2E steps A12-A14 and B5 | S1, S2, S4, S5 |
| Privacy grep finds no seeded names | `privacy.test.js`; E2E step A18 | S4, S5 |
| **Added (gaps REQ-46, REQ-66):** the restricted offline session exposes only `offline.*`; grant expiry is capped by `ends_at`, account expiry, a scheduled deactivation, the agreement due date and password age | `unlock_offline.test.js` (restricted capabilities); `OfflineGrantsTest` | S4 / S3 |

---

## 13. Deviations, doc edits and client questions

### 13.1 Deviations to record (plan §10 list and the client-deviation list)
Brief §4.4 items, as they stand now:
1. D10 commit-and-flag against UC-06 §3.2.5 step 3 (Q5), unchanged.
2. Four offline flows against UC-01 §3.3.3 "distribution only" (Q4), unchanged.
3. Offline alerts as type codes (P3), unchanged.
4. Offline search limits (P3), unchanged.
5. The pack as a bulk copy per tablet (Q6), unchanged.
6. Revocation reaches an offline tablet only at its next contact; an old password unlocks offline until then (C-33).
7. The item HMAC proves tablet + grant, not which of several people at an unlocked tablet acted (US-01 "recorded under my name"). Per-user sealed bundles would narrow it and are declined for R1 (Q6).
8-9. P3/P4 items, unchanged.
10. No US-30 grace period: the offline grant ends at the start of the re-acceptance due date.
11. ~~PIN up to 8 digits~~: removed. The registry now caps the PIN length at 6 (US-01 "4-6").
12. Registration by printed code (plan:207), unchanged.

New in this design:

13. Offline PIN switching on a tablet works once that person has set or used their PIN on that tablet while online (the tablet then holds their verifier). Before that, offline, they use their password.
14. While the pin_shift key exists (≤`pin_shift_hours` after a password sign-in on the tablet), the PIN is a UI control rather than cryptography (plan:117 "optionally"; REQ-63).
15. The DVK and grant are released for in-memory use on every eligible Station sign-in. `offline_mode_enabled` and the tablet's `offline_enabled` control only offline keeping and the grant length (REQ-30 wording).
16. Item origin is decided by the server from timing (the 12-design's client flip is superseded).
17. Station sign-in requires an `offline.*` capability, so Board members cannot sign in on a Station tablet.
18. Offline sign-in failures are recorded as unsigned claims on the heartbeat, grouped per person. They never count toward the account lockout (12:280 superseded).
19. Grants are superseded, not revoked, when a person signs in again on the same tablet (10:376, 12:117 "one live grant").
20. The failed-unlock wipe keeps sealed drafts (plan:119 deletes them), and offline unlock failures are delayed progressively. This serves UC-01 §4.2 "an entry is never discarded".
21. The wipe confirmation is sent before the database is deleted (40 §14.5 steps 3-4 swapped; X-4). The registration body adds `registration_nonce` and `proof_key`, and sign-in, PIN, the in-app acknowledgement, the in-app password change and the wipe confirmation need the tablet's proof signature (additive to 40 §14.3/§14.4).
22. PINs are set on the Station, not on the web profile (11:189, 20:211).
23. `rules.js` moves to P3 (plan:253 file list).
24. Extra endpoints `api/auth/{pin_set, policy, password}.php` and the in-app acknowledgement and forced-change views (plan:253 file list).
25. `api/sync/status.php` returns this tablet's item statuses only (D-43), not "notifications for users on this device" (REQ-88, 12:191): held-item notices reach the recorder and Coordinators through the web notifications page (§8.9).
26. The `cosign` envelope field is reserved but refused when non-null in P2B; brief §5 has it "accepted and stored". P4 adds its verification and storage together (§3.6).
27. Online PIN switching needs an **online** password sign-in on that tablet within `pin_shift_hours`; an offline password unlock opens only offline PIN switching (D-24). Both are US-01's "already on shift", measured by what each side can prove.
28. A Station sign-in or PIN switch ends the same person's online Station sessions on other tablets (D-17), so REQ-92's "same person on two tablets at once" is flagged from activity that really overlaps (offline sessions, D-50), never from a walk between tablets.

### 13.2 Doc edits
- **Plan:**
  - The P2B bullets gain:
    - files: `api/auth/{pin_set,policy,password}.php`; views `{device,login,ack,password,pin_set,home,sync-status,about,wipe,elsewhere}`; `js/{…,boot,clock,proof,drafts,device,canonical,registration_code,fold,env,dom,copy,sw-core}.js`; `src/{Station,Sync,Text}/…`; `rules.js` dropped (P3);
    - the parity list adds `station_crypto.json`;
    - the exit list adds "the restricted offline session exposes only `offline.*`" and "grant expiry is capped by `ends_at`, account expiry, scheduled deactivation, the agreement due date and password age" (the REQ-46 and REQ-66 gaps);
    - the pack half of plan:260 moves to P3.
  - §6 gets the 0013 row. §8 notes the no-touch rule for polling. §12 (plan:651) reads `migrations/0002–0013`.
  - The P5 runbook adds:
    - every volunteer signs in online on each tablet within `offline_grant_hours`, and sets or uses their PIN once online on each tablet. Online PIN switching needs an **online** password sign-in on that tablet within `pin_shift_hours` on the day; an offline unlock opens only offline PIN switching (D-24);
    - the SiteGround exclusions, NGINX Direct Delivery switched off for the site (§5.7), and `bin/station-smoke.php` after each deploy;
    - never deploy during distribution hours without `app.maintenance`;
    - keep retired crypto keys;
    - Repair and kill-switch instructions;
    - managed tablets disable developer tools and USB debugging, and use OS disk encryption and a screen lock.
  - P6 no longer carries the two crons.
- **Schema change log:**
  - the 0013 section;
  - `sync_item.recorded_at_client` is documented as the clamped time and `recorded_at_raw` as the tablet's;
  - `sync_item.origin` is server-derived.
- **40-design:**
  - §14.10 (40:1220) and §17 (40:1401): "above `revoked_max_seq`" → "every item received once suspect";
  - §5/§16/§17: FK count 166 → 167;
  - §7.5 (40:564-566): push, rescue and wipe confirmation take the device row lock only, never `device:<id>`;
  - §14.2: the guard signature gains `method`/`proof`/`session`/`touch`, and CSRF moves into `Api::start` (explicit calls kept);
  - §14.3: `registration_nonce`, `proof_key`, the derived credential and the replay rule;
  - §14.4: the heartbeat body gains `client_now`, `attention_count`, `locked_out_since`, `auth_failures` and `clock_rollback`; the response gains `config`; `offline_enabled` requires standalone on **every** platform and an active site (D-47), replacing "(not iOS, or standalone)"; the wipe confirmation needs the proof, is sent with credentials so `Clear-Site-Data` applies, and shreds grant secrets;
  - §14.5: the X-4 order;
  - §14.7: grants follow the D-19 expiry and are superseded, not revoked (D-20), with `grant_id = token_id`;
  - add §14.11, "the device proof key".
- **12-design:** a "superseded by 50-design" note at §2.3-§2.5, §3.1-§3.8 and §8. It covers `sessions[]`/`audit[]`, the `audit_queue` and `notifications` stores, the PBKDF2 PIN, the client origin flip, `phone_hash`, `sync_batch`, Rejected, `.mjs`, the root SW scope and one-live-grant.
- **This design** is filed as `docs/design/50-design-station.md` (the migration comment and code docblocks cite it).
- **Threat-model note (plan:122):** §9 of this design, including the shift key, the plaintext unlock counter, the old-password window, the co-volunteer limit, the server's key custody, the clock that can be set back to the last time the Station ran, and that a file-level copy of a tablet's profile carries its proof and shift keys.
- **Code docs:** the `Api.php` docblock; `Validator.php:11` ("exported to the Station in P3"); `config.example.php`; README ("Station development": `http://localhost:8088`, the relax flag, the smoke script).

### 13.3 Client questions and the defaults assumed
| Q | Question | Default assumed |
|---|---|---|
| Q4 | Offline scope: check-in, distribution, registration, pet saves (the four coded capabilities)? | Yes, as coded |
| Q5 | Commit-and-flag on replay? | Yes (D10) |
| Q6 | The offline risk sign-off. It covers:<ul><li>the 72 h offline grant;</li><li>the pack as a bulk copy;</li><li>the HMAC and co-volunteer limits (no per-user bundles in R1);</li><li>the old-password window;</li><li>the shift key making the PIN a screen lock for up to `pin_shift_hours`;</li><li>names of people who worked on a tablet shown on its lock screen while it is unlocked;</li><li>payloads kept server-encrypted `sync_payload_retention_days` (Held until resolved);</li><li>a tablet clock set back to about the last time the Station ran makes local expiries look valid for as long as the app was closed (§7.4, §9);</li><li>a file-level copy of a tablet's browser profile (a backup or device image) carries its proof and shift keys, so it can act as that tablet;</li><li>OS disk encryption and a screen lock assumed.</li></ul> | Accepted as designed |
| Q9 | The slowest target tablet | A mid-range Android tablet (≈4× slower than this desktop at PBKDF2); calibration adapts |
| Q10 | The final production domain before any tablet is registered | Must be fixed before P5 registration |
| Q23 | Does onboarding block activation? | Not blocking. The forced password change and the agreement run in-app on the tablet. |
| Q-new-1 | May a tablet stay usable for online work when offline is switched off (it still keeps signed records until they upload)? | Yes |
| Q-new-2 | Is "use your PIN once online on each tablet" acceptable for offline quick switching? | Yes |
| Q-new-3 | May Board members be unable to sign in on Station tablets? | Yes |
| Q-new-4 | When a retiring tablet has an entry open, discard it (40 §14.5) or offer "save for review"? | Discard in P2B (no forms yet); P4 decides with the distribution form |
| Q-new-5 | Will managed tablets have developer tools and USB debugging disabled, and disk encryption enforced? | Yes (runbook; it removes the script-level attacker of §9 from casual reach) |
| 40 Q-A…Q-D | Erase now Administrator-only; nothing automatic at a deactivated site; no pairing check in R1; the home-screen name "Pet Pantry Station" | As 40-design |

---

## 14. Risks (most severe first)

1. **`Authorization` stripped on SiteGround.** Every device call depends on it.
   *Mitigation:* the rewrite rule; the `REDIRECT_HTTP_AUTHORIZATION` and `getallheaders()` fallbacks; the distinct `device_credential_missing` code with an Administrator alert; `authorization_received` in ping; `bin/station-smoke.php` on staging as the first S1 task and a go-live gate.
2. **Lost or unrecoverable records.** Causes: an outbox bug; a wipe that deletes unanswered items; `Clear-Site-Data` on a Retire heartbeat; a reused `seq`; ciphertext the server cannot open; a forged wipe confirmation.
   *Mitigation:*
   - one rule, "delete only what a response names", tested per transition;
   - the gap-free `seq` transaction;
   - the always-ciphertext push exercising the server decrypt on every upload;
   - the cross-verified §3.9 vectors in both suites;
   - `conflict`/`invalid` stop the Retire wipe;
   - the proof-signed confirmation;
   - the resumable wipe;
   - the `seq_gap` warning on the tablet page;
   - storage persistence required for offline.
3. **Performance on the slowest tablet.**
   *Mitigation:* per-tablet calibration to ≈1 s; PBKDF2 in parallel with the sign-in request; PIN never touches PBKDF2; the shift key after tab kills; console timings; an early run on a real tablet in S4 (Q9).
4. **Schedule.** 25-30 days, about 120 requirements, a new client platform, and more P2B scope than MVP (proof key, shift key, crons, Repair, verified precache).
   *Mitigation:*
   - every open decision is settled here; one migration; no downloads; S2 and S3 in parallel;
   - the P2B kinds are limited to `offline_session`/`station_check`, and `rules.js` moves to P3;
   - parity fixtures come first;
   - if S4 slips, Trusted Types and the About view's extras are the first to trim, never the proof or the wipe order.
5. **Parity drift** (canonical JSON, fold, record format, proof).
   *Mitigation:* the HMAC is over bytes, so a canonical mismatch shows as a clear `MALFORMED`, not silent corruption. The fixtures pin the traps found here: line terminators, final sigma on PHP 8.3, `{}` vs `[]`, duplicate keys. `intl` must be present on staging (`php -m`, P5 runbook).
6. **Stale or broken cached Station.**
   *Mitigation:* build-named caches, with the shell, its headers and `sw.php` in the build hash; `cache: 'reload'` with the hash and type check; `no-cache` for Station static files, with SiteGround's NGINX Direct Delivery switched off so the rule applies; an uncontrolled page waits for the verified worker before it loads any module (no mixed builds); updates only at a quiet lock screen, and never a reload on a first claim; the watchdog, an online-only Repair and the kill switch; the smoke HEAD checks.
7. **iOS/WebKit lifecycle.** Storage isolation, persistence refusal, lost cookies, and `CryptoKey` persistence (the proof and shift keys).
   *Mitigation:* in-app acknowledgement and password change; every 401 handled; offline only when persisted; the registration self-test; the iPad is best effort.
8. **Shared-tablet security limits** (co-volunteers, unlocked theft, the shift key as a UI control).
   *Mitigation:* idle and absolute locks, the suspect rule, anomaly flags, devtools disabled on managed tablets, runbook password resets. All are documented and put to the client (Q6).
9. **Session plumbing.** Polling extending sessions, cookie sharing, site re-picking, the idle timer of device-only writes.
   *Mitigation:* `touch => false` and a contract note for P3/P4 polling; the separate Station cookie with the `restart()` fix; tablet sessions pinned by the pure, unit-tested `Api::contextFor()`; `Api::start` refusing a sessionless endpoint with neither `device` nor `public`; the debounced touch; per-tablet sign-in buckets.
10. **Lock and transaction traps.** Durable rows waiting on locks, the token-after-session order, retries re-running handlers, a Retire racing a sign-in.
    *Mitigation:* no `Audit::durable()` on device endpoints; the grant INSERT before the session updates, with a query-log test; the shared device lock at the start of the sign-in and PIN transactions; handlers re-runnable by contract, tested with an injected 1205.
11. **Revocation latency.**
    *Mitigation:* 5-minute heartbeats online; directives and `revoked_grants` on push responses; grants revoked server-side at once (including at a future deactivation date); items after a revocation Held; `ACTOR_INVALID` at the clamped date.
12. **Test gaps.** No real IndexedDB or SW under Node; the pane can be neither standalone nor controlled by a service worker (registration fails there, checked); it cannot sleep a device; `php -S` ignores `.htaccess`.
    *Mitigation:* the memory adapter mirrors IndexedDB's clone semantics, and the pane checks what it cannot (blocking, `Clear-Site-Data`); E2E Part A covers the pane-capable steps and Part B, in a real Chrome profile, the service-worker steps (install, reload offline, Repair, update, kill switch); `fake-env.js` models sleep for the clock; the dev relaxation is refused outside dev/test; the local Apache check (§11.5); Playwright (`channel: 'chrome'`) and fake-indexeddb remain an optional later addition, with the user's approval.
13. **Migration immutability.**
    *Mitigation:* 0013 is settled in full now; any later need is a new migration (0014).
14. **Origin change** wipes every tablet.
    *Mitigation:* Q10 before P5 registration.
15. **Self-reported figures** (pending count, build, persisted, auth failures, `max_seq`).
    *Mitigation:* they are for display and audit only. No decision uses them except `offline_enabled`, which also requires the server-side in-service state and only affects keeping offline sign-ins. A backwards `max_seq` is treated as a clone signal, never as data.
