<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

# PFPMS offline-first PWA: design and build plan (Release 1)

## 0. Key decisions

1. **Build one client-rendered "Station".** Everything a volunteer does at the distribution table lives in `/station/`: search, check-in, quick registration, pet quick-add, Record Distribution, receipt and the live dashboard. It is a static shell plus vanilla JS modules, and it talks to a JSON API. Every other page stays server-rendered PHP and works online only.
2. **One write path, online or offline.** Every Station write goes into an encrypted IndexedDB outbox. If the server is reachable, the item is pushed straight away and the answer is synchronous (UC-06 needs 2 s or less per step). Offline is therefore not a separate branch that gets tested less.
3. **The server is the authority.** The client checks frequency, allotment, stock, duplicates and flags against cached data so the volunteer gets instant feedback. The server runs every check again inside a transaction, both on an online submit and at sync.
4. **Records that fail checks at sync are held, never dropped.** Physically handed-out food cannot be "rejected". A held item goes to a Coordinator/Admin review queue and the volunteer is notified (UC-03 §3.2.3, UC-06 §3.2.5).
5. **Offline is allowed only on site-registered devices.** This matches the US-01 PIN rule. Personal devices get an online-only Station and no participant data is cached on them.
6. **No personal data ever goes into Cache Storage or the HTTP cache.** The service worker caches static assets only. All data sits in IndexedDB, encrypted with AES-GCM under a per-device key.

Nothing in the repo helps with offline. There is no service worker or IndexedDB code; the only `localStorage` use is the accessibility settings in `header.php:1150`. Assets I would reuse:
- `lib/bootstrap/css/bootstrap.min.css` (Bootstrap v5.2.2, CSS only) for touch-sized controls.
- The colour tokens from `css/base.css`.

I would not load jQuery 1.9.1 (it has known CVEs), `css/header.js` or jsPDF in the Station. Print CSS is enough for receipts.

---

## 1. Scope

| Flow | Online (Station) | Offline (registered device) | Notes |
|---|---|---|---|
| **UC-01 login** | JSON login through the shared Auth core, same lockout and audit rules as `login.php` | Restricted session unlocked with a cached credential: the password unwraps the device key. Only users who logged in online on this device within `offline_credential_days` can do this. | Offline sessions become `user_session` rows with `auth_method='Offline'` at sync |
| **US-01 PIN switch** | `api/auth/pin.php` checks `pin_hash`. It requires the device credential and a password session at this site within `pin_shift_hours`. | Checked against a local PIN verifier stored inside the encrypted vault. The user must have unlocked with a password on this device within `pin_shift_hours`. | Must take under 5 s and never discard the open entry (the draft belongs to the Station, not the user) |
| **UC-02 search** | Server search API | Searches the cached pack in memory: accent-folded, phonetic, phone hash. Results carry a "may be out of date (as of HH:MM)" banner. With no match, a *manual identification* record is allowed (§3.6). | Offline view audits are queued |
| **US-04 check-in** | `event_check_in` plus polling | Check-in queue local to this device | Other devices' check-ins cannot be seen offline |
| **UC-03 registration** | Same form. The duplicate check runs synchronously (candidates, open existing or confirm distinct). | Provisional record with provisional code `T{site}-{device}-{seq}`, a printed temporary slip, and distribution allowed against it | Consent text, intake questions (US-09) and service-area list come from the pack |
| **UC-05 pet add/edit** | Same form | Queued. Each new pet has a `client_uuid`; edits carry `base_row_version`. The allotment is recalculated locally. | Photo, transfer and microchip-conflict resolution are online only |
| **UC-06 distribution** | Full wizard, with a server pre-check when the participant is selected | Queued. Local stock view (snapshot minus queued lines), local checks, receipt printed via print CSS, next eligible date = event date + `frequency_rule_days` | Target: at least one full event (about 300 records; the footprint is tiny) |
| **UC-06 overrides** | Admin authorises in the session | An Admin present on the device co-signs with their PIN or password, or the volunteer enters an `authorization_ref`. The server re-validates at sync and holds anything it cannot verify. | |
| **UC-01 §4.2 session expiry** | Encrypted draft autosave, re-auth overlay, restore | Same | §4 |
| **US-18 dashboard** | `api/event/dashboard.php` polled every 15 s, plus this device's unsynced items | Pack snapshot plus this device's outbox, labelled "Offline: this device only since HH:MM" | Other devices cannot be seen while offline (open question 5) |
| **Referred-out** | Yes | Queued (postal code and pantry only, no personal data) | |

**Not available offline:**
- UC-04 edits and merge.
- UC-07 history. Offline shows only the last distribution date and the next eligible date.
- UC-08 SNV referrals. A deferral chosen at step 3.2.4 is queued as `snv_followup`; nothing else is.
- UC-09 to UC-13, reports, admin, imports, device management and sync review.
- Photos, password change and PIN setup.
- The Board role never gets an offline grant.

**Spec inconsistency to confirm:** UC-01 §3.3.3 says an offline session allows "queued food distribution recording only", but UC-03 §3.2.3 and UC-05 §3.3.4 allow offline intake. I propose the capabilities `offline.distribute`, `offline.register`, `offline.pet_edit` and `offline.check_in` in the capability matrix, all on by default for Volunteer, Coordinator and Administrator.

---

## 2. Client architecture

### 2.1 Layout (plain PHP, no build step)

**Station**
- `/station/index.php`: shell with no user data. It emits asset URLs with `?v=<filehash>`.
- `/station/manifest.webmanifest`: start_url `/station/`, scope `/`.
- `/station/js/*.mjs`, `/station/css/station.css`, `/station/vendor/idb.mjs`.
- `/sw.php`: service worker served from the site root so its scope is `/`.
- `/offline.html`: fallback page with no personal data, linking to the Station.

**API**
- `/api/_bootstrap.php`: shared core with a JSON guard, CSRF and device header checks.
- `/api/auth/*`, `/api/device/*`, `/api/sync/*`, `/api/station/*`, `/api/event/*`.

**Server code**
- `src/Sync/SyncService.php` and handlers for each item kind.
- The handlers reuse the same domain services as the server pages: `DistributionService::record()`, `ParticipantService::register()`, `PetService::save()`, `AllotmentCalculator`.
- **Shared rules:** `src/Rules/*.php` exports validation rules as JSON inside the pack. JS interprets that JSON, so PHP and JS enforce identical rules. UC-12 needs the same.

**Station JS modules**
- `api.mjs`: fetch wrapper with CSRF header, 5 s timeout, 401/410 handling and connectivity state.
- `vault.mjs`: crypto and keyring.
- `db.mjs`: IndexedDB schema and migrations.
- `pack.mjs`, `search.mjs`, `rules.mjs`, `calc.mjs`: allotment, frequency, stock view, and local_date from the event.
- `outbox.mjs`, `sync.mjs`, `session.mjs`: timers, lock, PIN switch.
- Views: `login`, `home`, `search`, `participant`, `register`, `pet`, `distribute`, `receipt`, `dashboard`, `sync-status`, `device`.
- Rendering uses `textContent` and DOM APIs only, never `innerHTML` with data.

### 2.2 Service worker (`/sw.php`, scope `/`)

| Resource | Strategy |
|---|---|
| Station shell and assets, `offline.html`, icons, manifest, `bootstrap.min.css` | Precached at install into `pfpms-shell-<build>`, fetched with `cache:'reload'`. Served cache-first. |
| Navigations under `/station/` | Cached shell (cache-first) |
| Other navigations (server PHP pages) | Network-only with navigation preload. On network failure, serve `offline.html`. PHP pages are never cached. |
| `/api/*` (GET and POST) | Not intercepted; goes straight to the network. Responses carry `Cache-Control: no-store`. |
| Other static legacy assets | Not intercepted |

- **Versioning.** `sw.php` computes BUILD from the hashes of the files listed in `station/assets.php` plus `IDB_SCHEMA_VERSION`. Any deploy that changes an asset therefore changes the service worker bytes.
- **Updates.** A new service worker waits. The page sends `SKIP_WAITING` only when there is no open draft and no push in flight, then reloads on `controllerchange`.
- **Kill switch.** A config flag makes `sw.php` emit a worker that deletes its caches and unregisters itself.
- **No data work in the worker.** The service worker never touches IndexedDB and never queues requests. Background Sync is not used because it would need the key while locked.

### 2.3 IndexedDB (`pfpms`, versioned upgrades in `db.mjs`)

Rule: everything except `meta` and `keyring` is AES-GCM ciphertext.

| Store | Key / indexes | Contents |
|---|---|---|
| `meta` (plaintext) | key-value | device_uuid, device_secret, site_id, site name/tz, schema_ver, pack_version, pack_fetched_at, server_time_offset_ms, next_client_seq, last_sync_at, build |
| `keyring` (plaintext wrappers) | user_id | username, salt, pbkdf2_iterations, `wrapped_dvk` (AES-GCM, AAD = user_id + device_uuid), grant_expires_at, failed_unlocks |
| `vault_users` | user_id | role, capabilities, PIN verifier {salt, iter, hash}, grant_id, grant HMAC key, last_password_unlock_at |
| `pack` | chunk id | participants (1,000 per chunk), pets, proxies, blocking alerts, service notes, and lookups (species, size_band, allotment_rule, products, barcodes, stock snapshot + `as_of_txn_id`, events, settings, postal codes, consent text, intake questions, rules) |
| `outbox` | client_uuid; idx `seq`, `status`, `kind` | Plaintext metadata (seq, kind, status pending/sending/accepted/held/rejected, attempts, created_at). The payload is ciphertext plus an HMAC. |
| `drafts` | `station:open-entry`, `register:<uuid>` | In-progress entries: step, participant, lines, started_by, updated_at |
| `sessions` | offline_session_uuid | user, factor (Password/PIN), started, last activity, ended, end reason |
| `audit_queue` | uuid | Offline logins and failures, record views, prints, PIN failures |
| `notifications` | id | Sync results for users on this device |

### 2.4 Encryption at rest (WebCrypto only)

**Key hierarchy**
- **DVK (device vault key).** A random AES-256-GCM key generated server-side when the device is registered. The server stores it in `device.vault_key_enc`, encrypted with the app master key from the environment, and returns it only on an online login by a user who has access to the device's site.
- **KEK_user.** `PBKDF2-SHA256(password, 16-byte salt, 600k iterations)`. The iteration count is stored per record so it can be tuned (floor 310k). The client wraps the DVK with it, which makes up the offline cached credential. It is written on every successful online password login, so any authorised user can open a shared tablet's vault.
- **In memory.** After unlock the DVK is imported as non-extractable. Every record gets a random 96-bit IV, and the AAD is store + key + schema version, which stops ciphertext being swapped between slots.
- **PIN verifier.** `PBKDF2(PIN, salt, 200k)`, stored inside the vault. A stolen disk cannot be used to brute-force the 10⁶ PIN space without first breaking a password-wrapped DVK. After `pin_max_failed` misses the Station drops back to password.
- **Grant HMAC key.** Per user and device, issued by the server in `offline_grant`. It signs the canonical JSON of each outbox payload. The server checks the signature at sync, proving the record was made on this device by someone unlocked as that user. An Admin override adds the Admin's co-signature.
- **Argon2id.** Optional hardening via vendored `hash-wasm`. It is not in R1 because PBKDF2 is native and needs no dependency.

**Key lifetime**
- **Idle** (`session_idle_minutes`): the UI locks and the user identity is cleared, but the DVK stays in memory so a PIN can unlock.
- **Hard lock:** the DVK is dropped. This happens after the absolute limit (12 h), on "End shift / Lock device", after too many PIN failures, or on tab close or reload. A password is then needed.

**Wipe matrix**
- **User logout:** clears identity only. Other users' queued data stays on the shared device.
- **Remote sign-out** (US-02): ends the server session and locks the Station.
- **Grant revoked** (deactivation, password change, role change): the keyring entry is deleted on the next contact.
- **`device.wipe_mode='Push Then Wipe'`:** the outbox is pushed, then the whole IndexedDB database and caches are deleted.
- **`'Wipe Now'`:** immediate deletion, for a compromised device.
- **De-registration:** full deletion plus a `Clear-Site-Data: "storage"` response.
- **Pack older than `offline_pack_ttl_hours`:** the pack is deleted automatically. The outbox is kept.
- **`offline_max_failed_unlocks`:** the pack is deleted and the encrypted outbox is kept; online login becomes mandatory.
- **Never:** the client never deletes an unsynced item unless the server has acknowledged it.

### 2.5 Device registration
- A Coordinator or Admin opens the Station online on the tablet and chooses "Register this device" with a site and label.
- `api/device/register.php` creates the `device` row (`is_site_registered=1`, `offline_enabled=1`, `device_uuid`, `secret_hash`, `vault_key_enc`) and returns device_uuid and device_secret once.
- Every device-bound call sends `X-PFPMS-Device: <uuid>.<secret>`: PIN login, pack, push, heartbeat. On its own the secret grants nothing; a user credential is always needed as well.
- `deviceManagement.php` (server page) lists devices with last seen, last sync, pending count, oldest pending, build, storage-persisted flag and revoke/wipe actions.

### 2.6 Pack contents (privacy minimisation)

**Which participants.** Participants of this site who were served in the last `offline_cache_days` (90), plus anyone checked in, registered or due here around the pack's events, capped at `offline_max_participants`.

**Fields included**
- id, code, provisional_code
- legal first/last name, preferred name, surname_phonetic plus a folded search key
- `phone_last4` plus `phone_hash` (HMAC of the normalised phone under a key derived from the DVK), postal_code
- status, household_size, last_distribution_date, next_eligible_date, current_allotment_lbs, ytd_lbs
- preferred_contact_method, consent_to_contact, preferred_language
- row_version
- Active proxies (name only); active blocking alerts (type, reason, created_at); active service notes (US-12)
- Pets: id, name, species, size_band, status, is_altered, snv_status, rabies_expires_on, row_version

**Excluded:** street address, email, full phone, DOB, consent records, SNV/referral detail, photos, history rows and other sites' households.

**Audit and refresh**
- Each download is audited as `Offline Pack Downloaded` with the participant count and event ids, because it is a bulk extract of personal data.
- The pack is fully replaced each time (about 300 KB gzip for 3,000 households); there are no deltas in R1.
- Refresh happens on "Prepare device for event", every 15 min while online, and after each sync.
- The client stores `server_time_offset` at each download.

### 2.7 Libraries
- `idb` v8 (about 1.2 KB, ESM), vendored and not loaded from a CDN, so installation and offline use never depend on a third party.
- Dexie was rejected: it is 30 KB and its encryption add-ons are third-party. Encryption here is per record at app level anyway.
- WebCrypto covers AES-GCM, PBKDF2, HMAC and `crypto.randomUUID()`.
- Barcode scanning: `BarcodeDetector` where available, otherwise a keyboard-wedge scanner.
- Temporary slip barcode: a small vendored Code128/QR SVG generator.
- Search: accent folding via `normalize('NFD')` plus diacritic stripping. The phonetic algorithm is implemented identically in PHP and JS with shared test vectors (open question 8).

---

## 3. Server sync API

### 3.1 Endpoints (JSON; `Cache-Control: no-store`)

| Endpoint | Auth | Purpose |
|---|---|---|
| `GET api/ping.php` | none | `{ok, server_time, build}`. Does not extend the idle timer. |
| `POST api/auth/login.php` | CSRF-exempt login plus rate limit | Uses the shared Auth core. On a registered device, the response includes `{dvk, grant_id, grant_hmac_key, grant_expires_at, revoked_grants[]}`. |
| `POST api/auth/pin.php` | device header | PIN session (`auth_method='PIN'`). The previous session ends with `end_reason='PIN Switch'`. |
| `POST api/auth/logout.php`, `GET api/session.php` | session | Current user, capabilities, CSRF token, idle remaining |
| `POST api/device/register.php`, `POST api/device/heartbeat.php` | session (Coord/Admin for register) + device | Registration. Heartbeat reports pending count, oldest pending, build, `storage.persisted()` and estimate, and receives directives (wipe, revoked grants, update). |
| `GET api/sync/pack.php?event_ids=` | session + device + site access | Pack plus directives |
| `POST api/sync/push.php` | session + CSRF + device | Batch upload, up to 50 items per request (shared-hosting time limits) |
| `GET api/sync/status.php?since=` | session + device | Resolutions of held items, notifications |
| `GET api/station/search.php`, `participant.php`, `POST duplicate-check.php`, `GET api/event/dashboard.php`, `checkins.php` | session | Online Station reads |

**Authentication and CSRF**
- The whole app uses one model: an httpOnly, Secure, SameSite=Lax PHP session cookie that is checked against `user_session` on every request, plus the synchronizer CSRF token from the core.
- Station fetches use `credentials:'same-origin'`, the `X-CSRF-Token` header and `Content-Type: application/json`.
- The server also rejects any `Origin` or `Sec-Fetch-Site` that is not same-origin.
- If the session expired while offline, the Station asks for a PIN or password (under 5 s) and then syncs.
- A push may carry items recorded by other users on the device. Each one is authenticated by its grant HMAC, not by the uploader's session.

### 3.2 Push envelope

```json
{"batch_uuid":"…","client_now":"2026-10-03T15:02:11.120Z","build":"…",
 "sessions":[{"offline_session_uuid":"…","user_id":7,"factor":"PIN","started_at":"…","ended_at":"…","end_reason":"Timeout"}],
 "audit":[{"uuid":"…","action":"Participant Viewed","entity_id":123,"occurred_at":"…","offline_session_uuid":"…"}],
 "items":[{"client_uuid":"…","seq":41,"kind":"distribution","origin":"offline",
   "offline_session_uuid":"…","recorded_by":7,"recorded_at":"…","depends_on":["<participant client_uuid>"],
   "payload":{…},"hmac":"…","cosign":{"user_id":2,"hmac":"…"}}]}
```

### 3.3 Processing pipeline (`SyncService`)

**1. Serialise per device.** `GET_LOCK('pfpms_sync_dev_<id>',10)` works on both MySQL 8.4 and MariaDB 10.4. Then:
- Insert `sync_batch` and record the clock skew (server_now − client_now).
- Upsert offline sessions as `user_session` rows. The session_id is `SHA-256('offline:'+uuid)`, so a retry creates the same row; `auth_method='Offline'`, `device_id`, `site_id`, and client times corrected by skew.
- Ingest audit entries: `occurred_at` is the corrected client time, and `details` holds `{origin:'offline', received_at, device_seq}`.

**2. Process items in `seq` order.** Each item gets its own DB transaction, so one failure never rolls back the rest.
- **Idempotency.** Look up `sync_item.client_uuid`.
  - Same `payload_sha256` → return the stored result (`duplicate`).
  - Different hash → `rejected: UUID_REUSE`.
  - The domain unique keys (`distribution.client_uuid`, the new `participant/pet/event_check_in.client_uuid`) are a second line of defence against races.
- **Gaps.** `device.last_client_seq` exposes missing sequence numbers, which are reported on the device page.
- **Authenticity.** The device must be active and not revoked. The HMAC must verify against the `offline_grant` of `recorded_by` for this device, and that grant must not have been revoked before `recorded_at`. For `origin:'online'`, recorded_by must equal the session user.
- **Offline capability.** Kinds outside the offline capability set are rejected.
- **References.** A participant or pet reference is either `{id}` or `{client_uuid}`, resolved through the domain tables or through `sync_item.entity_id` for linked items. If the dependency is held, the item is held with `DEPENDS_ON_HELD`.

**3. Run the kind handler** through the shared domain service (§3.4).

**4. Store the outcome** in `sync_item`: Accepted with entity refs, Held with reason code and text, or Rejected. Then write the notification.

**5. Response.** For each item:
```
{client_uuid, status, participant_id, participant_code, pet_id, distribution_id,
 next_eligible_date, reason_code, message}
```

**Online-origin items.** A failed business check returns a synchronous refusal. Nothing is held, a Denied audit row is written, and the volunteer can ask for an override on the spot. An item counts as online only if the server answered it synchronously. An item that timed out is retried with the same uuid and flips to `origin:'offline'`. Claiming "offline" gains nothing, because held items need a reviewer.

### 3.4 Checks at sync and hold policy (offline origin)

| Check | Rule at sync | Failure |
|---|---|---|
| Event | Must exist, its site must match the device site, and `event_date` must equal the payload's `local_date`. Closed events are accepted up to `late_sync_grace_days`, with a `Late Sync` audit row. | Held `EVENT_INVALID` |
| Participant status / blocking alert | Active. Only alerts with `created_at <= distributed_at` block, so a flag cannot apply backwards. | Held `FLAGGED` / `INACTIVE` |
| Frequency | Lock the participant row `FOR UPDATE`. Fail if any committed, non-reversed distribution has \|local_date − L\| < `frequency_rule_days` on **either side**, because devices sync out of order. | Held `FREQUENCY` |
| Same-day duplicate | Same participant, site and local_date, with no `second_issue_reason` | Held `DUPLICATE_SAME_DAY` |
| Allotment | Recompute entitled_lbs with the `allotment_rule` in force on L, compare with issued lbs, and verify any Admin co-sign or `authorization_ref` | Held `OVER_ALLOTMENT` / `AUTH_UNVERIFIED` |
| Pets / products | Pets belong to the household and are active; products are active. `unit_weight_lbs` comes from the client stamp, cross-checked. | Held `DATA_MISMATCH` |
| Stock | **Not a blocker for offline origin**, because the food has already left. `site_stock` may go negative, which triggers a "Negative stock" notification to the coordinator. Online origin is still refused. | Warning only |
| Actor | Deactivated before `recorded_at`, or grant expired | Held `ACTOR_INVALID` |
| Registration | Shared validation rules, service area, duplicate detection (surname + phone / address / phonetic) | Held `VALIDATION` / `OUT_OF_AREA` / `DUPLICATE_CANDIDATE` (candidate list stored) |
| Pet edit | `base_row_version` must match; the active-microchip unique key and `household_pet_limit` must hold | Held `VERSION_CONFLICT` / `MICROCHIP_CONFLICT` / `PET_LIMIT` |

**Review page.** Held items are resolved on the server page `syncReview.php` by Coordinator or Admin, according to the capability matrix. The options are:
- **Commit with override.** Needs a reason; `authorized_by` is set to the reviewer, and the relevant `second_issue_reason`, `override_reason` and `is_over_allotment` fields are stamped.
- **Link to existing participant.** For a duplicate or a manual identification; adds `participant_site` with `is_transfer` as appropriate.
- **Create as distinct.** Writes a `participant_alert` Duplicate Candidate row with the justification.
- **Discard.** Needs a reason, and prompts for a stock count adjustment.

Resolving an item automatically reprocesses the items that depend on it, and those may be held again (for example on frequency). The client learns the outcome through `status.php`.

### 3.5 Provisional to permanent
- The client creates a participant with `client_uuid` and a `provisional_code` such as `T3-D12-0042`, printed on a temporary slip. Later outbox items refer to it by `{client_uuid}`, so **the client never rewrites queued items**; the server resolves the reference.
- On acceptance, the server allocates `participant_code` inside the transaction (from the counter proposed in schema gap 16) and stores `record_source='Offline'`, `client_uuid`, `provisional_code`, `registered_by/at/device/site`, consent and intake answers.
- The client then updates its local mapping and the UI shows the permanent code. The card is printed at the next online visit.
- A temporary slip scanned later still works through `participant.provisional_code`.
- **Manual identification** (UC-02 §3.3.3) is a participant item with `mode:'manual'`: minimal name, phone and postal code. It is **always held** for reconciliation, and its distributions follow whatever the reviewer decides.

### 3.6 Column semantics and audit
- **`distribution`.**
  - Server rows are always `sync_status='Synced'`. `'Queued'` exists only on the client.
  - `synced_at` is NULL for online commits and set to the commit time for offline-origin rows. "Late-synced" means `synced_at > distributed_at`.
  - `local_date` = **event_date** from the pack, which avoids clock skew.
  - `distributed_at` = device time corrected by `server_time_offset`. The server flags it if it falls outside the event window by more than `sync_clock_skew_minutes`.
  - The new `distribution.session_id` links to the Offline `user_session`.
- **Participant status update.** `last_distribution_date = GREATEST(existing, L)`, `next_eligible_date` is recomputed from it, and `ytd_lbs_issued` is incremented only if L is in the current programme year (the `programme_year_start` gap).
- **Audit.** Every committed item writes `audit_log` with `session_id` pointing to the offline session, `device_id`, `site_id`, and `details {origin:'offline', client_uuid, device_seq, uploaded_by, batch_uuid, cosigned_by}`.
- **New audit actions:** `Device Registered/Revoked/Wiped`, `Offline Grant Issued/Revoked`, `Offline Pack Downloaded`, `Offline Login`, `Offline Login Failed`, `Sync Batch`, `Sync Held`, `Sync Resolved`, `Late Sync`.
- **Failed logins.** Offline login failures are passed to the core, which increments `failed_login_count` and notifies an Admin at the threshold (UC-01 §3.3.1).

### 3.7 Inventory ledger effects of late sync
- **Distribution transactions.** One `inventory_transaction` per Issued line: `txn_type='Distribution'`, `recorded_at` = sync time, new `occurred_at` = `distributed_at`, `site_stock` updated in the same transaction.
- **Count race.** If a count for that site and product was posted (new `inventory_count.posted_at`) after `distributed_at`, the count already reflects the food that left, and the sync would subtract it a second time. In that case also post an offsetting `+qty` Count Adjustment that references the count line, so `site_stock` stays equal to the physical count and the ledger can still be explained (UC-14 §4.5).
- **Count warning.** Posting a count warns if any device at the site reports pending items in its heartbeat.
- **Local stock view.** `pack.stock` (as_of_txn_id) minus outbox Issued lines not yet reflected in the snapshot. Accepted items drop out of the calculation after the next pack refresh.

### 3.8 Failure reporting
- A server `notification` row goes to `recorded_by`, and site coordinators see all held items. The volunteer sees it at their next login (header badge) and in the Station while they are active.
- **Station behaviour**
  - Header badge with the unsynced count.
  - Sync-status view listing held items with their reasons.
  - An end-of-event checklist that blocks "End event on this device" until everything is synced, unless the user explicitly acknowledges the risk.
  - `navigator.storage.persist()` requested at registration.

---

## 4. Session expiry (UC-01 §4.2) and PIN switching (US-01)

**Draft autosave**
- `drafts['station:open-entry']` is written encrypted, debounced 300 ms, on every change.
- It holds the step, participant ref, lines, proxy, flags and `started_by`.

**Expiry and restore**
- A client timer mirrors `session_idle_minutes` and the absolute 12 h limit. Only user actions extend it; pings do not.
- A 401 `session_expired` response or the local timer brings up a re-auth overlay (PIN or password). The draft is then restored exactly as it was.
- If a different user takes over, the Station shows "Open entry started by A at 10:41: continue or discard (reason)". The submitter becomes `recorded_by`.
- Offline sessions use the same timers and the same lock.

---

## 5. HTTPS and SiteGround

**Secure context.** Service workers, `crypto.subtle`, `randomUUID` and `storage.persist` all need HTTPS.
- **Production:** enable SiteGround Let's Encrypt with Enforce HTTPS, plus HSTS.
- **Development:** `http://localhost` counts as secure. A tablet on the LAN IP does not, so use mkcert for XAMPP Apache, or `adb reverse tcp:80 tcp:80`.

**Fix the final production domain before rollout.** Moving from `*.sg-host.com` to the real domain is a new origin, and devices lose their storage.

**SiteGround caching**
- My understanding is that NGINX may serve static files directly and ignore `.htaccess` `Header` rules; please verify. Serving `sw.php`, `station/index.php` and the manifest through PHP avoids the question.
- Exclude `/api/`, `/station/` and `/sw.php` from Dynamic Cache and the CDN.
- Purge caches on every deploy.
- Check headers with `curl -I` after each deploy.

**.htaccess (Apache paths)**
- Force HTTPS; `Strict-Transport-Security: max-age=31536000`.
- `/api/`: `Cache-Control: no-store`, `nosniff`.
- `/station/` and `/sw.php`: CSP `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; connect-src 'self'; worker-src 'self'; frame-ancestors 'none'; base-uri 'none'`.
- `Permissions-Policy: camera=(self)`.
- `AddType application/manifest+json .webmanifest` and `text/javascript .mjs`.
- `sw.php` sends `Cache-Control: no-cache` and a JS content type.
- Deny `sql/ docs/ database/ include/ domain/ src/ vendor/ tests/ *.log *.sql .env composer.* .git`.
- mod_deflate for JSON.

**PHP limits.** Pushes of 50 items or fewer, one transaction per item, and `max_execution_time` of 60 s or more for `/api/sync`.

---

## 6. Testing

- **Parity fixtures** (`tests/fixtures/*.json`), shared by PHPUnit and `node --test` (Node 20 WebCrypto plus `fake-indexeddb`):
  - canonical JSON and HMAC, phonetic key, accent folding, phone hash;
  - validation rules, allotment calculation, both-sided frequency window, local_date.
- **JS unit tests:**
  - vault wrap/unwrap, wrong password, tampered ciphertext or AAD;
  - PIN lockout; outbox ordering and state machine; stock view after a pack refresh; draft restore.
- **PHPUnit integration suite** (composer require-dev phpunit ^11), run in CI against **MySQL 8.4 and MariaDB 10.4**:
  - same batch twice; same uuid with a different payload;
  - out-of-order seq; two devices serving the same household on the same day;
  - provisional participant, pet and distribution chain; held registration, then link, then dependents reprocessed;
  - sync after event close; sync after a count was posted (offset); negative stock;
  - revoked device or grant; bad HMAC; deactivated actor;
  - parallel pushes (GET_LOCK); a failure in the middle of an item rolls back its stock movement.
- **Playwright** (Chromium, `context.setOffline`, `page.clock`) against `php -S localhost`:
  - install and precache; online login creates the keyring; reload while offline;
  - offline unlock; PIN switch; provisional registration with a pet;
  - 30 distributions with receipts; dashboard counts;
  - idle expiry and draft restore; reconnect, sync, code swap; held notification; remote wipe.
  - **Privacy assertion:** dump IndexedDB and Cache Storage and grep for seeded names; expect zero hits.
- **Soak test:** 3 devices × 150 households, offline for 4 hours, then sync. Totals must reconcile with the ledger.
- **Manual matrix:**
  - Android Chrome tablet (primary), Chromebook, and iPad installed PWA (best effort).
  - DevTools: Application → Service Workers (offline checkbox, update on reload), IndexedDB (ciphertext only), Clear storage, network throttling.

---

## 7. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Shared tablet misattribution | PIN verifier inside the vault, grant HMAC per record, idle lock, a Switch User button always visible, draft owner shown |
| Lost or stolen device | Encryption unlocked by password, minimised and site-scoped pack, pack TTL of 72 h, remote wipe modes, grant revocation, forged uploads impossible without a password. Policy: OS encryption and screen lock on stations. |
| Weak password allows offline brute force | 600k PBKDF2 iterations (tunable), minimum password length of 12 for offline-enabled roles, Argon2id as later hardening |
| Clock skew | local_date taken from event_date, server time offset from each pack, clamp and flag at sync |
| iOS Safari storage eviction (7-day cap for non-installed sites) | Require the installed PWA on iOS, `storage.persist()`, sync attempts every 30 s when online, unsynced warnings, Android/Chromebook as the primary stations. Optional task O21. |
| Quota | Footprint under 10 MB, no photos, storage estimate reported in the heartbeat |
| Double serving across devices | Both-sided frequency check, held-for-review, advice to run check-in on one device |
| US-18 blind to other devices offline | Clear labelling. Client decision (open question 5). |
| A service worker update breaks a live event | Update only when idle, versioned IndexedDB migrations, kill switch |
| Stale flags or erasure on cached data | Alerts apply as of `distributed_at` at sync; the pack refresh removes erased or deleted records; `sync_item` payloads purged on erasure (UC-09) and after `sync_payload_retention_days` |
| XSS exposes decrypted data | Strict CSP, no inline scripts or jQuery in the Station, `textContent` rendering |
| SiteGround caching or time limits | PHP-served service worker and shell, cache exclusions, small batches, per-device lock |
| Origin change wipes devices | Fix the final domain before rollout |

---

## 8. v2.1 schema additions (proposed file `sql/migrations/v2.1_0xx_offline_sync.sql`)

All changes are additive. They avoid features specific to either MySQL 8.4 or MariaDB 10.4: JSON goes in LONGTEXT with `CHECK (JSON_VALID(...))`, and nullable UNIQUE columns are fine on both.

**Columns**
1. `participant.client_uuid CHAR(36) NULL UNIQUE`
2. `participant.provisional_code VARCHAR(20) NULL UNIQUE`
3. `pet.client_uuid CHAR(36) NULL UNIQUE`
4. `event_check_in.client_uuid CHAR(36) NULL UNIQUE`
5. `distribution.session_id CHAR(64) NULL`, FK to `user_session`
6. `device`:
   - `device_uuid CHAR(36) NULL UNIQUE`, `secret_hash VARCHAR(255)`, `vault_key_enc VARCHAR(255)`
   - `offline_enabled TINYINT(1) NOT NULL DEFAULT 0`
   - `last_seen_at`, `last_sync_at`, `last_client_seq INT`, `pending_count INT`, `oldest_pending_at`
   - `app_build VARCHAR(40)`, `storage_persisted TINYINT(1)`
   - `revoked_at`, `revoked_by` (FK `user_account`)
   - `wipe_mode ENUM('None','Push Then Wipe','Wipe Now') DEFAULT 'None'`
7. `inventory_count.posted_at DATETIME NULL` (and optional `posted_by`)
8. `inventory_transaction.occurred_at DATETIME NULL`

**New tables**
- **`offline_grant`**: grant_id, user_id, device_id, hmac_key_enc, issued_at, expires_at, revoked_at, revoked_reason, last_used_at.
- **`sync_batch`**: batch_id, batch_uuid UNIQUE, device_id, uploaded_by, session_id, received_at, client_now, clock_skew_seconds, client_build, item/accepted/held/rejected/duplicate counts.
- **`sync_item`**:
  - Identity: sync_item_id BIGINT, client_uuid UNIQUE, batch_id, device_id, client_seq.
  - Type and actor: `kind ENUM('Participant','Pet','Pet Update','Check In','Distribution','Referred Out','SNV Followup')`, `origin ENUM('Online','Offline')`, recorded_by, offline_session_id, recorded_at_client DATETIME(3), depends_on_uuid.
  - Payload: `payload LONGTEXT CHECK(JSON_VALID)` (purgeable), payload_sha256, payload_purge_after.
  - Outcome: `status ENUM('Accepted','Held','Rejected','Committed After Review','Linked','Discarded')`, reason_code, reason_text, entity_type, entity_id.
  - Resolution: resolved_by, resolved_at, resolution_note.
  - Indexes: (device_id, client_seq), (status, device_id).
- **`notification`**: id, user_id NULL, site_id NULL, audience_role NULL, kind, entity_type, entity_id, message, created_at, read_at, resolved_at. This also covers schema gap 6.
- *Optional:* **`override_authorization`**: code_hash, kind, event_id, issued_by, expires_at, used_by_distribution_id. It would give pre-approved references for UC-06 §3.2.2 that can be checked offline.

**Append-only enum extensions (optional)**
- `user_session.end_reason` += `'PIN Switch','Device Lock'`
- `inventory_transaction.txn_type` += `'Late Sync Adjustment'`. Otherwise use Count Adjustment plus audit.

**`system_setting` rows**

| Key | Default |
|---|---|
| `offline_mode_enabled` | 1 |
| `offline_credential_days` | 14 |
| `offline_pack_ttl_hours` | 72 |
| `offline_cache_days` | 90 |
| `offline_max_participants` | 5000 |
| `offline_max_failed_unlocks` | 10 |
| `offline_pbkdf2_iterations` | 600000 |
| `pin_min_length` | 4 |
| `pin_max_length` | 6 |
| `pin_max_failed` | 5 |
| `pin_shift_hours` | 12 |
| `session_absolute_hours` | 12 (also in the core gap list) |
| `sync_clock_skew_minutes` | 10 |
| `late_sync_grace_days` | 7 |
| `sync_payload_retention_days` | 90 |
| `station_poll_seconds` | 15 |

**Needs no change.** `distribution.client_uuid`, `sync_status` and `synced_at` (semantics as in §3.6), `user_session.auth_method='Offline'` and `participant.record_source='Offline'` already exist.

---

## 9. Ordered build tasks

Sizes: S = 1–2 dev-days, M = 3–5, L = 6–10.

**Prerequisites from the core workstream**
- **P1** Security remediation, HTTPS and the baseline `.htaccess`.
- **P2** MariaDB-safe v2 DDL and a migration runner.
- **P3** Shared core: PDO with transactions, Auth backed by `user_session`, capability matrix, CSRF, audit writer, settings loader, `api/_bootstrap.php`.
- **P4** Domain services used by both pages and sync: Distribution (one transaction), Participant register (code counter), Pet save, AllotmentCalculator.

| # | Task | Size | Depends on |
|---|---|---|---|
| O1 | v2.1 offline migration (§8), loaded and tested on MySQL 8.4 and MariaDB 10.4 | S | P2 |
| O2 | Shared rules JSON export; canonical JSON, HMAC, phonetic, accent folding, phone hash in PHP and JS with fixtures | M | P3 |
| O3 | `sw.php`, `station/index.php` shell, `assets.php`, manifest, icons, `offline.html`, `.htaccess` headers, dev HTTPS notes | S | P1 |
| O4 | Station foundation: ES module layout, hash router, `api.mjs` (CSRF, timeouts, 401/410, connectivity), `station.css` with 44 pt targets | M | O3, P3 |
| O5 | `db.mjs` (idb, stores, migrations) and `vault.mjs` (PBKDF2, AES-GCM, wrap/unwrap, HMAC, lock lifecycle, wipe) | M | O4 |
| O6 | Device registration and heartbeat APIs, DVK server storage, `deviceManagement.php` (revoke, wipe modes, pending) | M | O1, O5 |
| O7 | Station auth: JSON login with grant issuance, keyring write, offline unlock, local lockout, online and offline PIN, idle/absolute timers, re-auth overlay, offline session records | M | O5, O6 |
| O8 | Pack API (minimised, site-scoped, audited) and client encrypted cache, in-memory index, TTL, refresh, time offset | M | O2, O7 |
| O9 | Online-first outbox and client sync engine (state machine, batching, backoff, triggers, result application, badge, notifications view). Built **before** the flows so every write uses it from day one. | M | O5 |
| O10 | Server `push.php` and `SyncService`: idempotency, GET_LOCK ordering, HMAC and co-sign, sessions and audit ingestion, holds, both-sided frequency, late sync, count offset, negative stock, GREATEST updates, notifications | L | O1, P4, O9 |
| O11 | UC-02 search and US-04 check-in in the Station: online API, offline cache, stale banner, manual identification | L | O8, O9 |
| O12 | UC-06 wizard: summary, local checks, products and barcode, shortfall/decline/substitute, proxy, emergency and over-allotment (Admin co-sign or ref), draft autosave and restore, print receipt | L | O11, O10 |
| O13 | UC-03 provisional registration: consent, intake questions, service area, local duplicate check, provisional code and slip, referred-out | M | O11, O10 |
| O14 | UC-05 queued pet add/edit: size band picker, local allotment recalculation, rules | M | O13 |
| O15 | `syncReview.php`: held list, link/create/override/discard, reprocess dependents, `status.php` feedback | M | O10 |
| O16 | US-18 dashboard: online endpoint with polling, offline from pack and outbox, demand vs stock warning | M | O12 |
| O17 | Revocation propagation: grants, Push Then Wipe / Wipe Now, `Clear-Site-Data` on de-registration | S | O6, O9 |
| O18 | PHPUnit sync suite (MySQL and MariaDB CI matrix) and node unit tests | M | O10, O2 |
| O19 | Playwright offline E2E, privacy grep, full-event soak, manual device matrix script | L | O12–O17 |
| O20 | Operations: deploy checklist (cache purge, build check, kill switch), coordinator runbook ("Prepare device", "End event sync"), DPIA note on caching personal data on devices | S | O19 |
| O21 | Optional: encrypted outbox backup export and admin upload (the server can decrypt with the DVK); Argon2id; DVK rotation | S–M | O9 |

**Critical path:** P1 → P2 → P3 → O1 → O5 → O7 → O8 → O9 → O10 → O11 → O12 → O19.

**Milestones**
- **A:** online Station with outbox (O1–O10).
- **B:** offline distribution for a full event (O11, O12, O16).
- **C:** provisional intake and pets (O13–O15).
- **D:** hardening and E2E (O17–O20).

**Estimate:** roughly 80–90 dev-days for this workstream, on top of P1–P4.

---

## 10. Open questions for the client and design team

1. Offline only on site-registered devices? Recommended: yes.
2. Offline capability set: UC-01 §3.3.3 (distribution only) vs UC-03 §3.2.3 / UC-05 §3.3.4 (intake allowed).
3. Accept "hold for review" as the policy for double-serves and checks that fail at sync?
4. Maximum offline window: `offline_credential_days` and pack TTL.
5. Is a US-18 dashboard that sees only its own device acceptable while offline?
6. Supported station hardware: Android/Chromebook primary, iPad best effort?
7. Final production domain before rollout.
8. Phonetic algorithm to implement identically in PHP and JS.
9. How are "pre-approved references" for UC-06 §3.2.2 issued? This decides whether the optional `override_authorization` table is needed.
10. Which role resolves held items: Coordinator, Admin or both? This is a capability matrix setting.

### Critical files for implementation
- `C:/Users/maryw/Documents/Pelican/chsPetPantry/docs/PFPMS_schema_v2.sql`: `distribution` L458–489, `device`/`user_session` L72–95, `participant` L145–198, `pet` L371–413, `inventory_*` L576–608, seed settings L1094+.
- `C:/Users/maryw/Documents/Pelican/chsPetPantry/docs/PFPMS_schema_v2_notes.md`: §3 app-enforced rules (one transaction, immutability, duplicate check).
- `C:/Users/maryw/Documents/Pelican/chsPetPantry/login.php` and `C:/Users/maryw/Documents/Pelican/chsPetPantry/header.php`: to be replaced by the shared Auth core that `api/auth/login.php` must reuse, and the header that gets the notification badge and the Station link.
- `C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbinfo.php`: to be replaced by the PDO core (utf8mb4, transactions) that `SyncService` depends on.
- New, to be created: `C:/Users/maryw/Documents/Pelican/chsPetPantry/sw.php`, `.../station/js/vault.mjs`, `.../api/sync/push.php`, `.../src/Sync/SyncService.php`, `.../sql/migrations/v2.1_0xx_offline_sync.sql`.
