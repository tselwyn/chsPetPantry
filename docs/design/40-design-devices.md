# Device admin slice (`admin_devices`): final design

This is the last Phase 2A item. Plan line (`docs/PFPMS_Implementation_Plan.md:202`): "`admin_devices` (register, revoke or wipe; shows last seen, pending count, build, persisted flag)".

- Written before the build, against `pfpms/phase-0` at `7e42e28`; the code is the record of what was built. Small differences in the build: `RegistrationCode::fromBytes()` is public (the fixture uses it) and `normalise()` refuses input over 100 characters; renaming a tablet with no site takes no site lock; `DeviceRepository::recentUsers()` breaks ties by name; a null revision skips the check (scripts), while pages always send one. All paths are relative to the repo root.
- In the copy people see, the word is **tablet**. The code, schema and nav keep **device**; the nav label stays "Devices".

---

## 0. How this design was put together

**Base.** The base is the **"mvp"** design. Judge 1 picked it. It also has the highest combined score (mvp 78, operations 75, security 69), and it keeps closest to the plan line. Judge 2 preferred "operations" for how it works on a distribution day, so the usability features the judges named are grafted on.

**Grafted from "security":**
- The reprint revision includes the id of the live code.
- `Tokens::consume` checks expiry.
- `SessionStore::validate` refuses sessions on a revoked device.
- A `DeviceScope` value object.
- Audit rows are attributed to the device's own site.
- A `seen_pending` guard on Erase now.
- A named lock `device:<id>` that P2B also takes.
- A person's live codes are cancelled when their access changes or they are deactivated.
- A per-site cap on tablets waiting to be registered.
- `pairingCheck()` and its fixture, reserved for later.
- An explicit `Csrf::verify()` in the P2B register endpoint.
- The `revoked_max_seq` snapshot, applied to suspect devices only.

**Grafted from "operations":**
- The corrected Luhn fixtures.
- Honest confirmation copy for Retire and Erase now.
- "Tablet no. {device_id}".
- An "Add another tablet" button.
- A constant for the Station app name.
- `wiped_at`.
- Display-only warnings.
- The "People who signed in on this tablet" list.

**Fixed.** Every factual error the judges found is corrected. The table below lists them, plus three I found while re-checking the code.

| # | Error or gap | Where | Fix in this design |
|---|---|---|---|
| E1 | The example code `K7QM-2XRD-9VHP-C4TN-8` fails its own Luhn mod 32 check. The correct check symbol is `S`. | mvp D3; security reader | Example is `K7QM-2XRD-9VHP-C4TN-S`. I recomputed the fixtures with `C:/xampp/php/php.exe` (§7.2). |
| E2 | `Audit::REDACT = '/pass(word)?\|token\|secret\|pin\|hash\|cipher/i'` (`src/Audit/Audit.php:20`) turns any detail or snapshot key that matches into `[redacted]`. `tokens_revoked` and `token_id` would be lost. | security, operations | Keys are neutral (§11.1). `token_id` never goes in details. |
| E3 | For an anonymous caller, `Api::start(['public' => true])` returns before `Csrf::verify()` (`src/Http/Api.php:24-27`). | mvp and operations P2B contracts | `api/device/register.php` calls `Csrf::verify()` explicitly (§14.3). |
| E4 | `RbacTest` globs `public/*.php` and `public/*/*.php` (`tests/Unit/RbacTest.php:48`), not only `public/*/*.php`. The conclusion still holds: `public/api/device/*.php` is not scanned. | mvp §13 | P2B widens the glob (§14.2). |
| E5 | There is a lock cycle: redemption locks `auth_token` then `device`, while reissue and retire lock `device` then `auth_token`. | operations §11 | One order everywhere: named lock, then the device row, then `auth_token` / `user_session` (§12). Redemption finds the token without a lock. |
| E6 | `GROUP BY u.user_id` with bare name columns fails under `ONLY_FULL_GROUP_BY` (`Db::SQL_MODE`) on MariaDB 10.4. | security `signedInUsers` | Group by every selected column (§7.4 `recentUsers`). |
| E7 | A device-history panel read from `audit_log` would show audit data to Coordinators, but `audit.view` is Administrator-only. | security | No audit panel. The device page shows facts from `device` columns only. |
| E8 | A sequence cut-off on every revocation would put every unsynced record from an ordinary Retire into Held. It is also meaningless in P2A, because `MAX(sync_item.client_seq)` is 0 there. | security D7 | Ordinary Retire follows plan:118 exactly. The cut-off applies only to **suspect** devices (reported lost, or Wipe Now). It uses the higher of the tablet-reported sequence and the server's (§14.6). |
| E9 | The count warning keeps counting Wipe Now tablets until `wiped_at`, but their records will never arrive. | operations D8 | Rule: `token_hash IS NOT NULL AND wiped_at IS NULL AND (revoked_at IS NULL OR wipe_mode = 'Push Then Wipe')` (§8). |
| E10 | The nav label was renamed to "Tablets". | operations | Stays "Devices" (`src/View/nav.php:32` is unchanged). |
| E11 | Reprinting silently cancels a sheet that was just printed (reload, or Back and resubmit). | mvp | The revision includes `live_code_id`, so a stale reprint is refused (§9.3). |
| E12 | `Tokens::consume()` does not check expiry (`src/Auth/Tokens.php:46`). | mvp deferred it to P2B | Fixed in P2A (§8). |
| E13 *(new)* | MVP's `SiteRepository::lock()` (`SELECT … FOR UPDATE` on the site row), used for label uniqueness. Every insert whose FK points at that site takes a shared lock on the site row: `audit_log.site_id` and the 16 other FKs to `site`. Those inserts would queue behind it. A durable audit row that names that site inside the transaction would wait on our own lock until `innodb_lock_wait_timeout`, because the durable connection is separate (`src/Db.php:68-76`). | mvp §5, §11 | Label uniqueness uses a **named lock** `devices:site:<id>`. No site row is ever locked (§12). |
| E14 *(new)* | P2B device endpoints will set `Audit::setActor(…, deviceId)`, and `audit_log.device_id` has an FK to `device`. An `Audit::durable()` call while the open transaction holds the device row `FOR UPDATE` then waits on our own lock. | all P2B contracts | Rule for P2B: write durable rows only after the device transaction has rolled back (§14.9). In P2A, web pages set no device (`src/Http/Page.php:39`), so the durable writes inside P2A transactions are safe. §12 gives the argument. |
| E15 *(new)* | MVP's `markRevoked` leaves `is_site_registered = 1`. A naive P2B check of that flag would trust a revoked tablet. | mvp | Revocation sets `is_site_registered = 0`. P2B must still use the one predicate (§3.2). |

---

## 0a. Changes after the independent review (as built; these override the sections below)

An independent five-lens review with adversarial verification confirmed these points; the code and tests follow them.

- **Retire, Erase now and Report lost take only the device row lock**, which waits its turn. Only adding codes, cancelling and renaming take the named lock `device:<id>` (P2B registration takes it too). Whoever holds a tablet can therefore never keep it in service by keeping its lock busy. P2B uploads and the wipe confirmation lock the device row per item and re-check `revoked_at` under it, instead of holding the named lock (§12, §14.6). No transaction inserts an `auth_token` row after it has updated `user_session` rows (taking a tablet out of service revokes tokens first, then ends sessions; a credential reset makes its activation token before ending sessions), so the account and device orders agree.
- **Upload cut-off.** A plain Retire uses the higher of what the server received and what the tablet reported. A tablet retired as lost, erased, escalated to Erase now or reported lost later gets **only what the server received** (`revoked_max_seq` can only come down), so a thief cannot widen it by reporting a huge `max_seq`. The bound parameters are cast to integers inside `LEAST()`, which otherwise compares them as strings. **For such a suspect tablet, P2B holds every upload for review once it is suspect**, whatever its sequence or recorded time (a thief with the unlocked tablet could otherwise fill gaps below the cut-off); `revoked_max_seq` then only records how far the server had received (§14.6).
- **Report lost later.** `DeviceService::reportLost()` (page action `report_lost`, audit `device_report_lost`) marks a retiring tablet lost, tightens the cut-off and alerts Administrators. Its refusals say why (already erasing, already erased, or still waiting, so cancel it instead). Ticking "lost or stolen" on a Retire form for a tablet someone else has just retired still reports it lost (`already_retired` in the result) instead of changing nothing.
- **Erase now's reported-count check** refuses once (error key `seen_pending`); the re-shown form carries `pending_ack`, and posting again goes ahead, so the tablet's own figure cannot hold the erase off. The audit records `seen_pending` and `count_acknowledged`.
- **Who chose Erase now:** migration 0012 adds `erase_requested_at` and `erase_requested_by` (FK; FKs 167). Escalating a Retire keeps `revoked_at`/`revoked_by` and records the erase separately; labels and the page show both.
- **Alerts** for a lost tablet go to Administrators with no site (`site_id` NULL), so a deactivated site's tablets still reach someone. The Erase notice goes to the site's Coordinators only when the site is active; flashes say only what was actually sent.
- **Codes and access:** besides access changes and deactivation, a credential reset (`sendReset`, printed sheets) and any password change (`Auth::setPassword`) cancel the person's live registration codes. `issueCode` re-reads the creator with `AccountRepository::lockShared()` and refuses if they can no longer hold a session, must change their password, or no longer have `device.register`, and also if their `row_version` differs from the one the page was opened with (`DeviceScope::$rowVersion`), so a site taken away meanwhile never gets a code.
- **Revision:** it includes the newest registration code of the tablet **in any state** (`code_id`: used, cancelled or expired alike), so an expiry, or an account event that cancels an expired code, never makes an open form stale, while a new code always does; it also includes `revoked_lost`. Rename checks for "no change" before the revision, so a repeated post is harmless.
- **Sessions:** `SessionStore::validate` returns `ended = 'device'` for a session ended as 'Device Revoked' (so the sign-in page says why), and ends a session on a tablet that erased itself (`wiped_at`), not only a revoked one. P2B must accept `wiped: true` only from a revoked tablet.
- **Status:** `DeviceStatus::code()` throws on a row without `code_expires_at` (a locked row); P2B uses `DeviceStatus::waitingForRegistration()`. The in-service badge is short ("Ready to work offline", "Works online only", "Not heard from yet") with the reason apart (`detail`). The site-deactivated warning says "Retire this tablet" only in service, "Cancel this registration" while waiting, and nothing once out of service. `DeviceStatus::currentBuild()` is the one source of the current build.
- **Registration code:** normalising is ASCII-only (removes space, tab, CR, LF and hyphen; upper-cases A–Z only; refuses everything else), with fixture cases for NBSP, NUL, a tab, the lower-case prefix, the dotless i and an en dash. The Station should send the canonical 17 symbols, and the endpoint must pass only a string to `normalise()`. Parameters holding a code or raw token are `#[\SensitiveParameter]`, so stack traces never show them.
- **Audit field changes** for Retire and Erase now record only fields that actually changed (`Audit::diff`).
- **Adding a tablet** at a site outside the person's scope never reveals whether that site exists or is active: for anyone without `site.all` the scope is checked before any site lookup, and a site that does not exist gets the same answer and Denied row as a real one outside the scope.
- **List and page copy:** the templates are the source of the page wording; where the tables in §9, §10 and §11 differ, the templates win. Changed after review: the in-service badge is short and its reason apart; the heading shows the detail only when the tablet is not in service; the registration sheet section appears only while waiting at an active site; the empty list says "no tablets yet" only when none exist at all (`hasHidden`); the Retire flash lists what stopped working and how many Station sessions ended; the Erase flash mentions Coordinators only when they were told.
- **Dev seed:** also skips a name already used by a current tablet; its credential hashes are of known development strings, so it is for dev and test databases only. A P2B tablet in service with a NULL `vault_key_ciphertext` gets no vault key and works online only.

## 1. Decisions

| # | Decision | Why |
|---|---|---|
| D1 | **"Register" on this page means:** add a tablet (site and label), then print a **single-use registration code** (QR plus a typed code). The installed Station redeems the code in P2B through `api/device/register.php`. Nobody signs in on the tablet to register it. | Registration must happen inside the installed PWA (33-review:134, plan:371), and an admin page cannot reach that storage. A Coordinator signing in on a shared tablet would leave their keyring entry, grant key and PIN verifier in its vault (33-review:58-61). Supersedes 12-design:136-137 and 11-design:232. |
| D2 | **Code format and storage:**<br>• 16 Crockford Base32 symbols (80 bits from `random_bytes(10)`) plus 1 Luhn mod 32 check symbol.<br>• Printed as `K7QM-2XRD-9VHP-C4TN-S`.<br>• Single use. Default life 60 minutes (new setting `device_code_minutes`, 10–1440).<br>• Stored only as SHA-256, in `auth_token` with the new purpose `'Device Registration'`.<br>• `user_id` is the person who created it, and `device_id` is the waiting device row.<br>• The QR holds `PFPMS-DEVICE:1:<17 symbols>`, which is **not a URL**. | 80 bits follows the portal-code precedent (plan:350) and allows an indexed hash lookup. A non-URL QR cannot open a browser tab: that would be the wrong iOS storage, and the code would land in access logs. `auth_token.user_id NOT NULL` fits binding the code to its creator. `device.token_hash` stays the lasting credential only. |
| D3 | **"Revoke or wipe" becomes two actions, and there is no revoke without a wipe.**<br>• **Retire** = revoke + `Push Then Wipe`. A "Lost or stolen" box alerts Administrators.<br>• **Erase now** = revoke + `Wipe Now`.<br>Revocation is final. To use the tablet again, add it as a new device row. | A JS wipe destroys the credential anyway. A revoked but unwiped tablet is personal data that nobody manages. Un-revoking would re-trust a credential that may be stolen. A new row keeps the provisional codes `T<site>-<device>-<seq>` unambiguous. |
| D4 | **Erase now is Administrator-only**, through the new capability `device.erase`. Coordinators hold `device.register`, which covers everything else, at their own sites. | plan:119: "Only an Admin Wipe-Now discards the outbox". A Coordinator's Retire already removes all trust on the server at once; the only difference is whether the tablet throws away unsynced records. |
| D5 | **Revocation takes effect on the server at once, in P2A code:**<br>• ends every `user_session` on the device (new end reason `'Device Revoked'`), and `SessionStore::validate` refuses them from then on;<br>• revokes every live `auth_token` bound to the device (codes now; grants and trusted-device tokens once they exist);<br>• sets `is_site_registered = 0` and `offline_enabled = 0`;<br>• snapshots `revoked_max_seq`.<br>It **keeps** `token_hash` and `vault_key_ciphertext`. | P2B's heartbeat must still recognise a revoked tablet to give it the wipe directive, and a rescue push needs the server's copy of the vault key. Everything that waits for the tablet's next contact is stated on the confirmation screen. |
| D6 | **Scope comes from `device.site_id`, never from the session's current site.**<br>• Administrators (`site.all`) cover every device, including inactive sites and `site_id` NULL.<br>• Coordinators cover the active sites they hold now.<br>• A device outside the scope gives 404 plus a durable `access_denied` row. The service checks again under the row lock. | Closes cross-site IDOR and "site switched in another tab". A deactivated site's tablets can still be retired. |
| D7 | **Heartbeat columns are shown, never written, in P2A.**<br>• `last_seen_at IS NULL` is shown as "Not heard from yet".<br>• Pending count, build and "storage kept" are shown as "Not reported".<br>• The pending count always appears with its age. | Nothing writes those columns before P2B. A default 0 must not read as "0 unsynced" or "not kept". |
| D8 | **Migration 0012** adds:<br>• two ENUM values;<br>• four `device` columns that P2A writes or reads and P2B relies on (`reported_max_seq`, `revoked_lost`, `revoked_max_seq`, `wiped_at`);<br>• one setting.<br>No FK and no table. Heartbeat-only extras (oldest pending, storage estimate, PBKDF2 rounds) go to P2B's migration **0013**. | Keeps P2A lean. Reserving the numbers stops the two parallel streams colliding. |
| D9 | **Stock-count warning:** counts tablets in service and tablets retiring until they confirm the wipe. Erase-now, erased, waiting and cancelled rows are left out. | A retiring tablet will still upload its records. An erasing one will discard them. |
| D10 | **Secrets never reach templates.** `DeviceRepository` never selects `token_hash` or `vault_key_ciphertext`; it exposes `has_credential`. The code exists only in the one POST response. | "Never shown" holds by construction. |

---

## 2. Scope

**In P2A (this slice)**
- **List:** `admin_devices`, across the actor's scope, with site and show filters.
- **Add and register:**
  - add a tablet (site, label);
  - create and print its registration sheet (code and QR), shown once;
  - reprint, which cancels the earlier code;
  - cancel a registration that no tablet has used yet.
- **Rename:** tablets waiting or in service.
- **Take out of service:**
  - **Retire**: reason required; "Lost or stolen" notifies Administrators;
  - **Erase now**: Administrator only; reason, typed name and the `seen_pending` guard; also escalates a retiring tablet.
- **Display:**
  - last heard from, unsynced count (with its age), build, storage kept, offline readiness, last upload;
  - registered or added (by and at); code expiry (and who created it); out of service (by, at, mode, lost); erased;
  - "Tablet no.";
  - display-only warnings;
  - "People who signed in on this tablet".
- **Server-side effects of revocation:**
  - `SessionStore::endAllForDevice`;
  - the revoked-device check in `SessionStore::validate`;
  - `Tokens::revokeForDevice`;
  - the `revoked_max_seq` snapshot;
  - notifications.
- **Hardening of existing code:**
  - `Tokens::consume` checks expiry;
  - access changes and deactivation cancel that person's live registration codes;
  - the new count-warning rule.
- **Built now for P2B:**
  - `RegistrationCode`, including `pairingCheck()`, reserved;
  - PHP/JS parity fixture;
  - `DeviceScope::forUser`;
  - `DeviceRepository::IN_SERVICE_SQL` / `DeviceStatus::inService`;
  - `DeviceLocks::device`;
  - the ENUM values.
- **Housekeeping:** migration 0012, the setting, the capability, a dev seed, docs, tests.

**Deferred to P2B** (contract in §14)
- **Endpoints:** `api/device/register.php` (redemption) and `api/device/heartbeat.php` (status, directives, wipe confirmation).
- **Device guard and transport:**
  - the guard, `Api::start(['device' => …])`;
  - `Authorization` pass-through in `.htaccess`, plus a `REDIRECT_HTTP_AUTHORIZATION` fallback;
  - a JSON body helper.
- **Registration internals:** the credential and DVK, PBKDF2 calibration, and the standalone and persisted gates.
- **Grants, pack, PIN and sync:**
  - offline grants, with revocation on account changes;
  - the pack and PIN;
  - push and rescue push under the cut-off rules.
- **Stored in 0013:** oldest pending item, storage estimate, calibrated rounds. Sequence gaps are also P2B.
- **Monitoring:** "seen after revocation" alerts, and notifying the code's creator on redemption.

**Not planned in this slice**
- Moving a tablet between sites in place. Copy says: retire it, then add it at the new site.
- A per-tablet "offline off" switch.
- Stale-device cron and settings (P6 operations).
- Trusted-device tokens (P6).
- A pairing-confirmation gate (Q-C, §18).

---

## 3. Lifecycle

### 3.1 States: `DeviceStatus::code(array $d)`, computed and never stored

"Has credential" means `token_hash IS NOT NULL`, read as the computed column `has_credential`. Conditions are evaluated in this order:

| Code | Condition | Label (badge) | Badge class |
|---|---|---|---|
| `erased` | `wiped_at` set | Erased {date} | `badge badge-inactive` |
| `cancelled` | `revoked_at` set, no credential | Cancelled before any tablet used it | `badge badge-inactive` |
| `erasing` | `revoked_at` set, `wipe_mode = 'Wipe Now'` | Erase requested {date} | `badge badge-problem` |
| `retiring` | `revoked_at` set (therefore `Push Then Wipe`) | Retired {date}; "reported lost or stolen" when `revoked_lost = 1` | `badge` |
| `awaiting` | no credential, not revoked, live code (`code_expires_at` not null) | Waiting for the tablet. Code works until {time} | `badge` |
| `no_code` | no credential, not revoked, no live code | Waiting for the tablet. No working code | `badge` |
| `in_service` | credential, not revoked | Sub-label: "Not heard from yet" / "Ready to work offline" / "Works online only" | `badge` |

**`in_service` sub-labels:**
- **Not heard from yet:** `last_seen_at IS NULL`.
- **Ready to work offline:** `offline_enabled = 1` AND `storage_persisted = 1` AND `Settings::bool('offline_mode_enabled', true)`.
- **Works online only:** otherwise, with the reason:
  - "offline working is switched off for all tablets in Settings"; or
  - "its storage is not kept"; or
  - "offline is not switched on yet".

**Warnings** (`DeviceStatus::describe()`, display only, never a security control):

| Key | When | Text |
|---|---|---|
| `stale_pending` | `in_service` or `retiring`, `pending_count > 0`, `last_seen_at` older than `DeviceStatus::STALE_PENDING_HOURS` (24, a constant, not a setting) | Holds {n} unsynced records, reported {ago}. Connect it to the internet so they upload. |
| `storage_not_kept` | `in_service`, heard from, `storage_persisted = 0` | It is not keeping its data, so it cannot work offline. Open the installed app, not a browser tab, and allow storage when asked. |
| `old_build` | `in_service`, `app_build` not null and ≠ the current build | Runs an older version of the Station ({build}). It updates itself the next time it is opened online. |
| `site_inactive` | not `erased` or `cancelled`, and the site is inactive | Its site, {site}, is deactivated. Retire this tablet. |
| `seen_after_revocation` | `revoked_at` set and `last_seen_at > revoked_at` (P2B writes `last_seen_at`) | Connected after it was taken out of service ({time}). |

### 3.2 The one "registered device" predicate for P2B

```php
DeviceRepository::IN_SERVICE_SQL = 'd.token_hash IS NOT NULL AND d.is_site_registered = 1 AND d.revoked_at IS NULL AND d.wiped_at IS NULL';
DeviceStatus::inService(array $d): bool   // PHP mirror; an integration test checks the two agree on every state
```

P2B uses this for PIN, DVK release, grants and the pack, and additionally requires `site.is_active = 1`. **No P2B endpoint may test `token_hash` or `is_site_registered` on its own.**

### 3.3 Transitions

| From → to | Action | Who | Method |
|---|---|---|---|
| (none) → `awaiting`/`no_code` | Add a tablet | `device.register`; site active and in the actor's sites | `DeviceService::add` |
| `awaiting`/`no_code` → `awaiting` | Create a registration sheet (replaces any live code) | same, device in scope | `issueCode` |
| `awaiting`/`no_code` → `cancelled` | Cancel this registration | same | `cancel` |
| `awaiting` → `in_service` | The tablet redeems the code | **P2B** | `redeem` (P2B) |
| `in_service` → `retiring` | Retire (reason; lost optional) | `device.register` | `retire` |
| `in_service` / `retiring` → `erasing` | Erase now (from `retiring` it is an escalation) | `device.erase` | `erase` |
| `retiring`/`erasing` → `erased` | The tablet confirms its wipe | **P2B** heartbeat | P2B |
| rename | label only; allowed in `awaiting`, `no_code`, `in_service` | `device.register` | `rename` |

Every other transition is refused. Rows are never deleted: six FKs point at `device`.

---

## 4. Files

**New**

| File | Purpose |
|---|---|
| `migrations/0012_device_registration.sql` | §5 |
| `seeds/dev/004_devices.sql` | Sample tablets in each P2B-only state (§8.9) |
| `src/Device/DeviceScope.php` | Who may act on which device (§7.1) |
| `src/Device/RegistrationCode.php` | Pure code format, check symbol, normalisation, pairing check (§7.2) |
| `src/Device/RegistrationSheet.php` | `APP_NAME` constant and the QR data URI (§7.3) |
| `src/Device/DeviceRepository.php` | SQL only (§7.4) |
| `src/Device/DeviceLocks.php` | Named locks `device:<id>`, `devices:site:<id>` (§7.5) |
| `src/Device/DeviceService.php` | Rules, transactions, audit, notifications (§7.6) |
| `src/Device/DeviceStatus.php` | States, labels, warnings, `inService` (§7.7) |
| `src/Device/StaleDeviceException.php` | Stale form (§7.8) |
| `public/admin_devices.php` + `templates/pages/admin/devices.php` | List (§9.1). Creating the page turns on the existing nav entry (`src/View/nav.php:32`, `src/View/Menu.php:15`). |
| `public/admin_device_edit.php` + `templates/pages/admin/device_add.php` + `templates/pages/admin/device_edit.php` | Add (no id); detail and actions (`?id=`) (§9.2) |
| `public/admin_device_credential.php` + `templates/pages/admin/device_credential.php` | Confirm step and the one-time sheet (§9.3) |
| `tests/Unit/RegistrationCodeTest.php`, `tests/Unit/DeviceStatusTest.php`, `tests/fixtures/registration_code.json` | §15 |
| `tests/Integration/Device/DeviceServiceTest.php` | §15 |

**Changed**

| File | Change (§8 has the detail) |
|---|---|
| `src/Auth/Tokens.php` | Constants; `issueValue`; public `hash`; `consume` checks expiry; `revokeForDevice` |
| `src/Auth/SessionStore.php` | `endAllForDevice`; `validate` refuses sessions on revoked devices |
| `src/Http/Page.php` | Flash for `ended = 'device'` |
| `src/Auth/capabilities.php` | Administrator gains `device.erase` |
| `src/Inventory/CountRepository.php` | `pendingDeviceItems` rule (D9) |
| `src/Account/AccountService.php`, `src/Cron/Jobs/DeactivateDueAccounts.php` | Cancel the person's live registration codes on an access change or deactivation |
| `src/Reference/settings_registry.php` | `device_code_minutes` |
| `bin/schema-check.php` | Settings 67 → 68; three 0012 checks |
| `public/assets/css/app.css`, `public/assets/css/print.css` | `.button-danger`, `.badge-problem`, `.sheet-code`, `.sheet-site` |
| `tests/TestCase.php` | `makeDevice()` helper |
| `tests/Unit/RbacTest.php`, `tests/Integration/SessionTest.php`, `tests/Integration/TokensPolicyTest.php`, `tests/Integration/Account/AccountServiceTest.php` | New cases (§15) |
| `docs/PFPMS_schema_v2_1_changes.md`, `docs/PFPMS_Implementation_Plan.md` | §17 |

**Unchanged on purpose:**
- `src/View/nav.php` (the entry, label and capability already exist).
- `src/Http/Api.php`, `public/.htaccess` and `src/Http/Request.php` (P2B).

---

## 5. Migration `migrations/0012_device_registration.sql`

```sql
-- Migration 0012 (v2.1): tablet registration codes and taking tablets out of service
-- (plan P2A admin_devices; US-01 PIN switching only on site-registered devices; UC-01 §3.3.3
-- offline sign-in; UC-06 §4.3 no loss of records when a tablet is revoked).
-- A tablet is added on admin_devices as a device row waiting for registration (token_hash NULL).
-- Its single-use registration code is an auth_token row, purpose 'Device Registration', bound to
-- that row (device_id) and to the person who created it (user_id); only its SHA-256 is stored.
-- The installed Station redeems it in Phase 2B (api/device/register.php) for the device credential.
-- Retiring or erasing a tablet ends its open sessions with end_reason 'Device Revoked'.
-- reported_max_seq: the highest client_seq the tablet has reported (written by the P2B heartbeat).
-- revoked_lost, revoked_max_seq: set when the tablet is taken out of service. For a tablet reported
-- lost or stolen, or erased, P2B holds pushed items above revoked_max_seq for review.
-- wiped_at: when the tablet confirmed that it erased itself (written by P2B).
-- New ENUM values are appended; the earlier values are copied unchanged from 0003.

ALTER TABLE `auth_token`
  MODIFY `purpose` ENUM('Password Reset','Temporary Credential','Trusted Device','Offline Grant','Device Registration') NOT NULL;

ALTER TABLE `user_session`
  MODIFY `end_reason` ENUM('Logout','Timeout','Remote Sign-out','Permission Change','Password Reset','Deactivated','PIN Switch','Device Lock','Device Revoked') NULL;

ALTER TABLE `device`
  ADD COLUMN `reported_max_seq` INT NULL AFTER `pending_count`,
  ADD COLUMN `revoked_lost` TINYINT(1) NOT NULL DEFAULT 0 AFTER `revoked_by`,
  ADD COLUMN `revoked_max_seq` INT NULL AFTER `revoked_lost`,
  ADD COLUMN `wiped_at` DATETIME NULL AFTER `wipe_mode`;

INSERT INTO `system_setting` (`setting_key`, `setting_value`, `description`) VALUES
  ('device_code_minutes', '60', 'A tablet registration code created on admin_devices works once, for this many minutes (plan P2A admin_devices)');
```

**Portability**
- The `MODIFY … ENUM` and `ADD COLUMN … AFTER` forms are those of 0003. 0003 also adds a column after another column added in the same statement.
- The earlier ENUM lists are copied from 0003:6 and 0003:11 verbatim, with the NULL/NOT NULL attributes unchanged.
- There is no display width except `TINYINT(1)`, which ci-guard allows. There is no engine-specific syntax.
- Appending ENUM members is a metadata change on both engines. `device` is tiny.

**Counts and checks**
- Tables stay at **66** and FKs at **166** (no new FK).
- Settings go from **67** to **68**.
- `bin/schema-check.php`:
  - `check('system settings seeded (12 in v2 + 54 in 0002 + 1 in 0010 + 1 in 0012)', $settings === 68, …)`.
  - Add, after the settings check:

```php
$columnType = static fn(string $table, string $column): string => (string) col($pdo,
    'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
check("auth_token.purpose includes 'Device Registration' (0012)", str_contains($columnType('auth_token', 'purpose'), "'Device Registration'"));
check("user_session.end_reason includes 'Device Revoked' (0012)", str_contains($columnType('user_session', 'end_reason'), "'Device Revoked'"));
check('device has the 0012 columns', (int) col($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device'
    AND COLUMN_NAME IN ('reported_max_seq', 'revoked_lost', 'revoked_max_seq', 'wiped_at')") === 4);
```

- `EXPECTED_TABLES` and `EXPECTED_FOREIGN_KEYS` do not change.
- Migrations 0013 and later are reserved for P2B. If P2B lands a migration first, renumber this file before merging and keep the pinned counts in step.

---

## 6. Capabilities and settings

**Capabilities**
- **`device.register`** (Coordinator, inherited by Administrator, `src/Auth/capabilities.php:28`): list, add, rename, create and cancel codes, and retire.
- **`device.erase`** (new, **Administrator only**): Erase now, including escalating a Retire.
  - It is checked in the service through `DeviceScope::can()`. The page only hides the form, and refuses a forged post (§9.2).
- `Rbac::READ_ONLY` is unchanged. Board holds neither capability, and `RbacTest`'s Board rule still passes.

**Settings**
- **`device_code_minutes`**: 60, range 10–1440.
- Registry entry (group "Offline station", after `station_poll_seconds`):

```php
'device_code_minutes' => ['group' => 'Offline station', 'label' => 'Tablet registration codes work for', 'type' => 'int', 'min' => 10, 'max' => 1440, 'unit' => 'minutes'],
```

- `SettingsServiceTest` then covers the registry and the seeded row agreeing.
- Existing settings the pages read: `offline_mode_enabled` (offline-ready label), `offline_grant_hours` (Retire copy) and `organisation_time_zone` (the fallback zone for a device with no site). They are read, never written.

---

## 7. Domain code: `src/Device/` (namespace `Pfpms\Device`)

The shape follows the `SiteService` reference: pages → Service (validate, lock, transaction, audit) → Repository (prepared SQL, upper-case keywords). Every write passes `Clock::db()` explicitly.

### 7.1 `DeviceScope`

```php
final class DeviceScope
{
    /** @param list<int> $siteIds active sites the actor can use now (SiteAccess::sitesFor) */
    public function __construct(
        public readonly int $actorId,
        public readonly string $role,
        public readonly bool $allSites,   // Rbac::can($role, 'site.all')
        public readonly array $siteIds,
    ) {}

    public static function fromContext(\Pfpms\Http\Context $ctx): self; // ($ctx->userId(), $ctx->role(), $ctx->can('site.all'), $ctx->siteIds())
    public static function forUser(array $user): self;                   // P2B issuer re-check: role from the row, sites from SiteAccess::sitesFor($user)
    public function can(string $capability): bool;                      // Rbac::can($this->role, $capability)
    /** Existing devices: site.all covers every site, inactive and NULL included; others need the site now. */
    public function covers(?int $siteId): bool;                         // $this->allSites || ($siteId !== null && in_array($siteId, $this->siteIds, true))
    /** New tablets and redemption: only an active site the actor can use now. */
    public function canAddAt(int $siteId): bool;                        // in_array($siteId, $this->siteIds, true)
}
```

- `SiteAccess::sitesFor` returns active sites only, even for `site.all` (`src/Auth/SiteAccess.php:21`). So `covers()` uses `allSites` for Administrators, and `canAddAt()` uses `siteIds` for everyone.
- `Page::resolve` recomputes sites on every request, so a lapsed US-28 grant drops out at once.

### 7.2 `RegistrationCode` (pure; unit-tested; the Station JS must match it)

```php
final class RegistrationCode
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford Base32: no I, L, O, U
    public const DATA_SYMBOLS = 16;                               // 80 bits
    public const QR_PREFIX = 'PFPMS-DEVICE:1:';

    /** 17 canonical symbols: random_bytes(10) read 5 bits at a time, most significant first, then checkSymbol(). */
    public static function generate(): string;
    /** Luhn mod 32 over ALPHABET. From the rightmost data symbol leftwards the factor is 2, 1, 2, 1 …;
     *  addend = factor × value; sum += intdiv(addend, 32) + addend % 32; check = ALPHABET[(32 − sum % 32) % 32]. */
    public static function checkSymbol(string $data16): string;
    /** trim; upper-case; strip a leading QR_PREFIX; remove spaces and hyphens; map O→0 and I, L→1;
     *  require /^[0-9A-HJKMNP-TV-Z]{17}$/ and a matching check symbol. Returns the canonical 17 symbols, or null. */
    public static function normalise(string $typed): ?string;
    public static function format(string $canonical): string;     // XXXX-XXXX-XXXX-XXXX-C
    public static function qrPayload(string $canonical): string;  // QR_PREFIX . $canonical
    /** RESERVED for a possible pairing step (Q-C): the first 30 bits of hash('sha256', 'pfpms-pair:' . $tokenHashHex, true)
     *  as 6 symbols. The tablet computes the same from sha256_hex(credential). Not used by any P2A page. */
    public static function pairingCheck(string $tokenHashHex): string;
}
```

**What is hashed and stored:** the **canonical 17 symbols**. `Tokens::issueValue(..., $code, ...)` stores `hash('sha256', $code)`. At redemption, `Tokens::find(RegistrationCode::normalise($typed), …)` finds it. `Tokens::find` caps input at 100 characters (`src/Auth/Tokens.php:32`), so normalise first.

**Why SHA-256 is enough.** SHA-256 rather than Argon2id is acceptable only because the code has 80 bits, is single use and short-lived, and is looked up by its hash. **Do not shorten the code.**

**Fixture `tests/fixtures/registration_code.json`.** I verified every vector with `C:/xampp/php/php.exe`.

```json
{
  "check": [
    {"data": "0000000000000000", "check": "0"},
    {"data": "K7QM2XRD9VHPC4TN", "check": "S"},
    {"data": "ZZZZZZZZZZZZZZZZ", "check": "G"},
    {"data": "0123456789ABCDEF", "check": "8"}
  ],
  "bytes": [
    {"hex": "00010203040506070809", "code": "000G40R40M30E209B"},
    {"hex": "ffffffffffffffffffff", "code": "ZZZZZZZZZZZZZZZZG"}
  ],
  "normalise": [
    {"in": "k7qm-2xrd-9vhp-c4tn-s", "out": "K7QM2XRD9VHPC4TNS"},
    {"in": " K7QM 2XRD 9VHP C4TN S ", "out": "K7QM2XRD9VHPC4TNS"},
    {"in": "PFPMS-DEVICE:1:K7QM2XRD9VHPC4TNS", "out": "K7QM2XRD9VHPC4TNS"},
    {"in": "oL23-4567-89AB-CDEF-8", "out": "0123456789ABCDEF8"},
    {"in": "K7QM-2XRD-9VHP-C4TN-8", "out": null},
    {"in": "K7QM-2XRD-9VHP-C4TU-S", "out": null},
    {"in": "K7QM-2XRD-9VHP-C4TN", "out": null},
    {"in": "K7QM-2XRD-9VHP-C4TN-SS", "out": null}
  ],
  "format": [{"in": "K7QM2XRD9VHPC4TNS", "out": "K7QM-2XRD-9VHP-C4TN-S"}],
  "pairing": [{"token_hash": "fce09e1ed4b4929ad287aa5bfb05b8addec26e0a6582b3b5953c35d23fbde977", "check": "AJA875"}]
}
```

- The pairing `token_hash` is `sha256('pfd1_test')`.
- Over 2,000 random codes the check symbol caught every single-symbol substitution (1,054,000 cases). It missed about 0.2% of adjacent swaps (72 of 31,042).

### 7.3 `RegistrationSheet`

```php
final class RegistrationSheet
{
    /** Home-screen name of the installed Station. P2B's manifest.json "name" MUST equal this (Q-D). */
    public const APP_NAME = 'Pet Pantry Station';
    /** SVG QR as a data: URI (the CSP allows img-src data:), over RegistrationCode::qrPayload(). */
    public static function qr(string $canonical): string;
    // (new QRCode(new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'outputBase64' => true, 'addQuietzone' => true])))
    //     ->render(RegistrationCode::qrPayload($canonical))
}
```

### 7.4 `DeviceRepository` (prepared SQL only, keywords in capitals)

```php
final class DeviceRepository
{
    /** Never token_hash or vault_key_ciphertext: pages, templates and audit snapshots only ever see these. */
    public const COLUMNS = 'd.device_id, d.site_id, d.label, d.is_site_registered, d.registered_by, d.registered_at,
        (d.token_hash IS NOT NULL) AS has_credential, d.offline_enabled, d.storage_persisted, d.last_seen_at, d.last_sync_at,
        d.pending_count, d.reported_max_seq, d.app_build, d.revoked_at, d.revoked_by, d.revoked_lost, d.revoked_max_seq,
        d.wipe_mode, d.wiped_at';
    public const IN_SERVICE_SQL = 'd.token_hash IS NOT NULL AND d.is_site_registered = 1 AND d.revoked_at IS NULL AND d.wiped_at IS NULL';

    /** COLUMNS + site_name, time_zone, site_active, registered_by_name, revoked_by_name, live_code_id, code_expires_at */
    public static function find(int $deviceId): ?array;
    /** Same shape. [] without a query when !$scope->allSites and $scope->siteIds === []. */
    public static function list(DeviceScope $scope, ?int $siteId, bool $includeClosed): array;
    /** COLUMNS only; FROM device d WHERE d.device_id = ? FOR UPDATE. No joins, so no other row is locked. */
    public static function lock(int $deviceId): ?array;
    /** @return ?array{token_id:int, expires_at:string, issued_by_name:string} */
    public static function liveCode(int $deviceId): ?array;
    public static function labelTaken(int $siteId, string $label, ?int $exceptDeviceId): bool;
    public static function pendingCount(int $siteId): int;
    public static function insertPending(int $siteId, string $label, int $addedBy, string $at): int;
    public static function rename(int $deviceId, string $label): void;
    public static function cancel(int $deviceId, int $actorId, string $at): bool;
    public static function revoke(int $deviceId, int $actorId, string $at, string $wipeMode, bool $lost, int $maxSeq): bool;
    public static function escalateToWipeNow(int $deviceId): bool;
    /** Highest client_seq the server has received from the device (sync_item). */
    public static function receivedMaxSeq(int $deviceId): int;
    /** @return list<array{user_id:int, name:string, last_signed_in:string}> */
    public static function recentUsers(int $deviceId): array;
}
```

**SQL**

`find` / `list`:

```sql
SELECT <COLUMNS>, s.name AS site_name, s.time_zone, s.is_active AS site_active,
       COALESCE(NULLIF(rb.display_name, ''), CONCAT(rb.first_name, ' ', rb.last_name)) AS registered_by_name,
       COALESCE(NULLIF(vb.display_name, ''), CONCAT(vb.first_name, ' ', vb.last_name)) AS revoked_by_name,
       (SELECT MAX(t.token_id) FROM auth_token t WHERE t.device_id = d.device_id AND t.purpose = 'Device Registration'
           AND t.used_at IS NULL AND t.revoked_at IS NULL AND t.expires_at > ?) AS live_code_id,
       (SELECT MAX(t.expires_at) FROM auth_token t WHERE t.device_id = d.device_id AND t.purpose = 'Device Registration'
           AND t.used_at IS NULL AND t.revoked_at IS NULL AND t.expires_at > ?) AS code_expires_at
  FROM device d
  LEFT JOIN site s ON s.site_id = d.site_id
  LEFT JOIN user_account rb ON rb.user_id = d.registered_by
  LEFT JOIN user_account vb ON vb.user_id = d.revoked_by
 WHERE d.device_id = ?                                       -- find
-- list instead:
 WHERE d.site_id IN (?, ?, …)                                -- omitted when $scope->allSites
   AND d.site_id = ?                                         -- only with a site filter
   AND d.wiped_at IS NULL AND NOT (d.revoked_at IS NOT NULL AND d.token_hash IS NULL)   -- unless $includeClosed
 ORDER BY s.name, d.label, d.device_id
```

Bind `Clock::db()` twice. The invariant "at most one live code per device" is kept by `issueCode`, under the device row lock, so `MAX()` is exact.

Other queries:

```sql
-- liveCode
SELECT t.token_id, t.expires_at, COALESCE(NULLIF(u.display_name, ''), CONCAT(u.first_name, ' ', u.last_name)) AS issued_by_name
  FROM auth_token t JOIN user_account u ON u.user_id = t.user_id
 WHERE t.device_id = ? AND t.purpose = 'Device Registration' AND t.used_at IS NULL AND t.revoked_at IS NULL AND t.expires_at > ?
 ORDER BY t.token_id DESC LIMIT 1

-- labelTaken (520 collation: case- and accent-insensitive; Validator::text already trimmed and collapsed spaces)
SELECT COUNT(*) FROM device WHERE site_id = ? AND label = ? AND revoked_at IS NULL AND device_id <> ?        -- except ?? 0

-- pendingCount
SELECT COUNT(*) FROM device WHERE site_id = ? AND token_hash IS NULL AND revoked_at IS NULL

-- insertPending (registered_by/at = the person who added it, and when; P2B overwrites both at redemption)
INSERT INTO device (site_id, label, is_site_registered, registered_by, registered_at) VALUES (?, ?, 0, ?, ?)

-- rename
UPDATE device SET label = ? WHERE device_id = ?

-- cancel (true when rowCount() = 1)
UPDATE device SET revoked_at = ?, revoked_by = ? WHERE device_id = ? AND token_hash IS NULL AND revoked_at IS NULL

-- revoke (true when rowCount() = 1). token_hash and vault_key_ciphertext are KEPT.
UPDATE device SET revoked_at = ?, revoked_by = ?, revoked_lost = ?, revoked_max_seq = ?, wipe_mode = ?,
                  is_site_registered = 0, offline_enabled = 0
 WHERE device_id = ? AND token_hash IS NOT NULL AND revoked_at IS NULL

-- escalateToWipeNow (keeps revoked_at, revoked_by, revoked_lost, revoked_max_seq: the cut-off stays at first revocation)
UPDATE device SET wipe_mode = 'Wipe Now'
 WHERE device_id = ? AND revoked_at IS NOT NULL AND wipe_mode = 'Push Then Wipe' AND wiped_at IS NULL

-- receivedMaxSeq
SELECT COALESCE(MAX(client_seq), 0) FROM sync_item WHERE device_id = ?

-- recentUsers (ONLY_FULL_GROUP_BY-safe on MariaDB 10.4: every selected column is grouped or aggregated)
SELECT u.user_id, COALESCE(NULLIF(u.display_name, ''), CONCAT(u.first_name, ' ', u.last_name)) AS name,
       MAX(s.started_at) AS last_signed_in
  FROM user_session s JOIN user_account u ON u.user_id = s.user_id
 WHERE s.device_id = ?
 GROUP BY u.user_id, u.display_name, u.first_name, u.last_name
 ORDER BY last_signed_in DESC
 LIMIT 50
```

- `LIMIT 50` is a literal, the same way `Notifications::listFor` interpolates a bounded int.
- Indexes: all these queries use existing indexes (the FK indexes on `auth_token.device_id`, `device.site_id`, `user_session.device_id`, and `ix_sync_item_1`). No new index is needed.

### 7.5 `DeviceLocks`

These mirror `Inventory\Locks::with` (`src/Inventory/Locks.php:48-62`): `GET_LOCK(Db::lockName(…), 0)`, taken outside any transaction and released in `finally`. When busy they throw `ValidationException::one('_form', …)`.

```php
public static function device(int $deviceId, callable $fn): mixed;
// key "device:$deviceId". The per-device sync lock of plan:92. P2B push, rescue push, redemption and wipe
// confirmation MUST take it.
// busy: 'This tablet is in contact with the server right now (registering or uploading). Wait a few seconds and try again.'
public static function site(int $siteId, callable $fn): mixed;
// key "devices:site:$siteId". Used by add and rename, so labels stay unique and the pending cap holds.
// busy: 'Someone else is adding or renaming a tablet at this site right now. Wait a moment and try again.'
```

The keys do not collide with `stock:site:<id>` or `catalogue`. Lock order is always site, then device (§12).

### 7.6 `DeviceService`

```php
final class DeviceService
{
    public const LABEL_MAX = 50;
    public const REASON_MAX = 255;
    public const PENDING_LIMIT = 10;          // tablets waiting to be registered, per site
    public const RETIRE = 'Push Then Wipe';
    public const ERASE = 'Wipe Now';

    /** @param array{site_id?:?string, label?:?string} $input  @return int device_id  @throws ValidationException */
    public static function add(array $input, DeviceScope $scope): int;
    /** @return bool false when the name did not change  @throws ValidationException|StaleDeviceException */
    public static function rename(int $deviceId, ?string $label, ?string $revision, DeviceScope $scope): bool;
    /** Cancels any earlier live code. @return array{code:string, expires_at:string} (canonical code, shown once) */
    public static function issueCode(int $deviceId, ?string $revision, DeviceScope $scope): array;
    /** @return bool false when it was already cancelled */
    public static function cancel(int $deviceId, ?string $revision, DeviceScope $scope): bool;
    /** @return ?array{sessions_ended:int, grants_and_codes_revoked:int} null when already out of service (nothing changed) */
    public static function retire(int $deviceId, ?string $reason, bool $lost, ?string $revision, DeviceScope $scope): ?array;
    /** device.erase only. @return ?array{sessions_ended:int, grants_and_codes_revoked:int, escalated:bool} null when an erase was already requested */
    public static function erase(int $deviceId, ?string $reason, ?string $typedLabel, ?int $seenPending, ?string $revision, DeviceScope $scope): ?array;

    /** sha1(json_encode([(int) device_id, site_id === null ? null : (int) site_id, (string) label, (int) is_site_registered,
     *  (int) has_credential, revoked_at, (string) wipe_mode, wiped_at, live_code_id === null ? null : (int) live_code_id])).
     *  Heartbeat columns (last_seen_at, last_sync_at, pending_count, reported_max_seq, app_build, storage_persisted,
     *  offline_enabled) are deliberately EXCLUDED, so a heartbeat never makes a form stale. */
    public static function revision(array $device): string;
    public static function codeMinutes(): int;          // max(10, min(1440, Settings::int('device_code_minutes', 60)))
    public static function redemptionAvailable(): bool; // is_file(APP_ROOT . '/public/api/device/register.php')
}
```

**Shape of every action on an existing device**

```php
DeviceLocks::device($id, fn() => Db::transaction(function () use (…) {
    $d = DeviceRepository::lock($id) ?? throw ValidationException::one('_form', 'That tablet no longer exists.');
    self::guardScope($d, $scope, $action);      // durable access_denied + ValidationException
    $d['live_code_id'] = DeviceRepository::liveCode($id)['token_id'] ?? null;
    // 1. no-op check (retire, erase, cancel), 2. state check, 3. revision (hash_equals, else StaleDeviceException),
    // 4. input validation, 5. writes, 6. Audit::record(…, actor: ['site_id' => $d['site_id']]), 7. notifications
}));
```

- `rename` takes `DeviceLocks::site($siteId)` around this block. It reads `site_id` from `find()` first; the site never changes for a device.
- **All refusals happen before any write**, so a durable row never has to reference something this transaction changed.
- `guardScope($d, $scope, $action)`:
  - if `!$scope->covers($d['site_id'])`:
    - `Audit::durable('access_denied', 'device', $id, 'Denied', 'Device at a site not available to this user', ['site_id' => $d['site_id'], 'action' => $action])`;
    - then `ValidationException::one('_form', 'That tablet is not at one of your sites.')`.
  - The page has already given 404 in the normal case. This covers the race where access was lost between page load and post.
- **Durable rows keep the request's actor and never pass an `actor` override.** Only the Success rows written with `Audit::record` carry `actor: ['site_id' => device.site_id]`. A durable row that names a site or user row locked by, or created in, the open transaction would wait on that lock. This matters in tests, where every row is created inside the test transaction.

**`add($input, $scope)`**
1. **Validate, reporting every field at once:**
   - `label = Validator::text($input['label'] ?? null, 50)`.
   - `siteId = Validator::wholeNumber($input['site_id'] ?? null, 1, 999999999)`, then `$site = SiteRepository::find($siteId)`.
   - Missing or unknown site gives a `site_id` error.
   - An inactive site gives the "not active" error.
   - `!$scope->canAddAt($siteId)` gives `Audit::durable('access_denied', 'site', $siteId, 'Denied', 'Add a tablet at a site not available to this user')` plus the "one of your sites" error.
   - Pre-check `labelTaken`, so a duplicate is reported with the other errors.
2. `DeviceLocks::site($siteId)` plus `Db::transaction`:
   - re-check `labelTaken`;
   - `pendingCount($siteId) >= PENDING_LIMIT` gives a `_form` error;
   - `insertPending($siteId, $label, $scope->actorId, Clock::db())`;
   - `Audit::record('device_add', 'device', $id, details: ['site_id' => $siteId, 'label' => $label], actor: ['site_id' => $siteId])`.
3. Return the id.

**`issueCode($id, $revision, $scope)`**, inside the shape above:
1. **State:**
   - a device with a credential gives "already registered";
   - a revoked device gives "taken out of service";
   - an inactive site (`SiteRepository::find`) gives "site is not active".
2. **Revision.** A mismatch throws `StaleDeviceException`. This includes a code created since the page was opened, so a printed sheet is never silently cancelled.
3. `$replaced = Tokens::revokeForDevice($id, Tokens::DEVICE_REGISTRATION)`.
4. `$code = RegistrationCode::generate()`, then `$expires = Tokens::issueValue($scope->actorId, Tokens::DEVICE_REGISTRATION, $code, self::codeMinutes(), $id)`.
5. `Audit::record('device_code_issue', 'device', $id, details: ['expires_at' => $expires, 'minutes' => self::codeMinutes(), 'replaced_codes' => $replaced], actor: …)`.
6. Return `['code' => $code, 'expires_at' => $expires]`. The raw code exists only in this return value and in the rendered response.

**`cancel($id, $revision, $scope)`**
1. If already cancelled (`revoked_at` set and no credential), return false.
2. A device with a credential gives "A tablet has already used this registration. Retire it instead."
3. Revision.
4. `DeviceRepository::cancel()`, then `$n = Tokens::revokeForDevice($id, Tokens::DEVICE_REGISTRATION)`.
5. `Audit::record('device_cancel', 'device', $id, changes: ['revoked_at' => [null, $now]], details: ['codes_cancelled' => $n], actor: …)`.
6. Return true.

**`retire($id, $reason, $lost, $revision, $scope)`**
1. If `revoked_at` is set, return null (idempotent; checked before the revision, so a double tap is harmless).
2. No credential gives "No tablet has used this registration yet. Cancel it instead."
3. Revision.
4. `reason = Validator::text($reason, 255)`; null gives a `reason` error.
5. `takeOutOfService($d, self::RETIRE, $lost, $reason, $scope)`.
6. If `$lost`: `Notifications::toRoleOnce('Administrator', $siteId, 'device_lost', …, 'device', $id)`.

**`erase($id, $reason, $typedLabel, $seenPending, $revision, $scope)`**
1. Scope (`guardScope`).
2. **Capability.** If `!$scope->can('device.erase')`: `Audit::durable('device_erase', 'device', $id, 'Denied', 'Erase now needs device.erase')` plus a `_form` error.
3. If `wipe_mode === 'Wipe Now'`, return null.
4. **State:**
   - `wiped_at` set gives "already erased itself";
   - no credential gives "Cancel it instead".
5. Revision.
6. **Errors, all at once:**
   - `reason` (text, 255);
   - `confirm_label`: `mb_strtolower((string) Validator::text($typedLabel, 50)) !== mb_strtolower($d['label'])`;
   - `(int) $d['pending_count'] > ($seenPending ?? 0)` gives a `_form` error naming the new count.
7. **Take out of service:**
   - if `revoked_at` is null: `takeOutOfService($d, self::ERASE, false, $reason, $scope)`;
   - otherwise **escalate**: `escalateToWipeNow($id)` (false throws `StaleDeviceException`), then `endAccess($id)` again (idempotent, usually 0 and 0), then `Audit::record('device_erase', 'device', $id, reason: $reason, changes: ['wipe_mode' => ['Push Then Wipe', 'Wipe Now']], details: ['escalated_from' => 'Push Then Wipe', 'sessions_ended' => …, 'grants_and_codes_revoked' => …, 'reported_pending_count' => …, 'last_seen_at' => …], actor: …)`.
8. `Notifications::toRoleOnce('Coordinator', $siteId, 'device_erase', …, 'device', $id)`.

**`takeOutOfService($d, $mode, $lost, $reason, $scope)`** (private; inside the lock and transaction):
1. `$now = Clock::db()`.
2. `$maxSeq = max(DeviceRepository::receivedMaxSeq($id), (int) ($d['reported_max_seq'] ?? 0))`. The push that could race this holds the same named lock.
3. `DeviceRepository::revoke($id, $scope->actorId, $now, $mode, $lost, $maxSeq)`. False throws `StaleDeviceException`.
4. `$sessions = SessionStore::endAllForDevice($id, 'Device Revoked', $scope->actorId)`.
5. `$tokens = Tokens::revokeForDevice($id)`.
6. `Audit::record($mode === self::RETIRE ? 'device_retire' : 'device_erase', 'device', $id, …)` with:
   - `reason: $reason`;
   - `snapshot: $d`: the `COLUMNS` row, which holds no secret column;
   - `changes`:
     - `revoked_at` [null, $now];
     - `wipe_mode` [$d['wipe_mode'], $mode];
     - `is_site_registered` [$d['is_site_registered'], 0];
     - `offline_enabled` [$d['offline_enabled'], 0];
     - `revoked_lost` [0, (int) $lost];
   - `details`:
     - `lost` => $lost;
     - `sessions_ended` => $sessions;
     - `grants_and_codes_revoked` => $tokens;
     - `revoked_max_seq` => $maxSeq;
     - `reported_pending_count` => (int) $d['pending_count'];
     - `last_seen_at` => $d['last_seen_at'];
     - `last_sync_at` => $d['last_sync_at'];
   - `actor: ['site_id' => $d['site_id']]`.
7. Return `['sessions_ended' => $sessions, 'grants_and_codes_revoked' => $tokens]`.

**`rename($id, $label, $revision, $scope)`**
1. Validate the label.
2. Inside `DeviceLocks::site` → `DeviceLocks::device` → transaction:
   - `lock`;
   - scope;
   - state: revoked or wiped gives "taken out of service";
   - revision;
   - an unchanged label returns false;
   - `labelTaken($site, $label, $id)`;
   - `rename`;
   - `Audit::record('device_rename', 'device', $id, changes: ['label' => [$old, $new]], actor: …)`.

**Services and capabilities.** Services assume the page has checked `device.register`, like the other admin services. The one rule specific to a capability, `device.erase`, is re-checked in the service.

### 7.7 `DeviceStatus` (pure: no database access)

```php
final class DeviceStatus
{
    public const AWAITING = 'awaiting'; public const NO_CODE = 'no_code'; public const IN_SERVICE = 'in_service';
    public const RETIRING = 'retiring'; public const ERASING = 'erasing'; public const ERASED = 'erased'; public const CANCELLED = 'cancelled';
    public const STALE_PENDING_HOURS = 24;

    public static function code(array $d): string;           // §3.1 order
    public static function inService(array $d): bool;        // has_credential && is_site_registered == 1 && revoked_at === null && wiped_at === null
    /** @return array{code:string, label:string, badge:string, warnings:list<string>} */
    public static function describe(array $d, \DateTimeImmutable $now, string $timeZone, bool $offlineAllowed, string $currentBuild): array;
    public static function ago(?string $utc, \DateTimeImmutable $now): string; // "never", "just now", "12 minutes ago", "3 hours ago", "2 days ago"
}
```

- Times are formatted with `Clock::fromDb($utc)->setTimezone(new DateTimeZone($timeZone))->format('M j, g:i A')`.
- Pages pass `Clock::now()`, the device's site zone (or `organisation_time_zone` when there is no site), `Settings::bool('offline_mode_enabled', true)` and `APP_VERSION`.

### 7.8 `StaleDeviceException extends \RuntimeException`

Default message: "This tablet changed since you opened the page (someone else may have acted on it). Its current state is shown: check it before trying again."

---

## 8. Changes to existing code

1. **`src/Auth/Tokens.php`**
   - Add `public const DEVICE_REGISTRATION = 'Device Registration'; public const OFFLINE_GRANT = 'Offline Grant'; public const TRUSTED_DEVICE = 'Trusted Device';`.
   - Add `public static function issueValue(int $userId, string $purpose, string $raw, int $ttlMinutes, ?int $deviceId = null): string`. It inserts `hash('sha256', $raw)` and returns `expires_at` (`Clock::db(...)`). `issue()` becomes `$raw = Crypto::b64url(random_bytes(32)); self::issueValue(...); return $raw;`.
   - `hash()` becomes **public** (docblock: "SHA-256 hex: the stored form of every token and of device credentials"). P2B uses it for `device.token_hash`.
   - **`consume()`**: `UPDATE auth_token SET used_at = ? WHERE token_id = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > ?`, binding `Clock::db()` twice. A token that expires between `find()` and `consume()` is now refused, and the existing reset and activation flows are hardened too.
   - Add `public static function revokeForDevice(int $deviceId, ?string $purpose = null): int`:

     ```sql
     UPDATE auth_token SET revoked_at = ? WHERE device_id = ? AND revoked_at IS NULL AND expires_at > ?
        AND (used_at IS NULL OR purpose <> 'Device Registration') [AND purpose = ?]
     ```

     It returns `rowCount()`. It revokes live grants whatever P2B does with `used_at`, and leaves an already redeemed code, and expired rows, untouched.
2. **`src/Auth/SessionStore.php`**
   - Add `public static function endAllForDevice(int $deviceId, string $reason, ?int $endedBy = null): int`: `UPDATE user_session SET ended_at = ?, end_reason = ?, ended_by = ? WHERE device_id = ? AND ended_at IS NULL`, returning `rowCount()`.
   - Change **`validate()`**:
     - Add `LEFT JOIN device d ON d.device_id = s.device_id` and select `d.revoked_at AS device_revoked_at`.
     - Right after the `ended_at` check: `if ($row['device_id'] !== null && $row['device_revoked_at'] !== null) { self::end($sessionId, 'Device Revoked'); return ['ended' => 'device']; }`.
     - Exclude the new key from the user array: `array_diff_key($row, $session + ['ended_at' => null, 'device_revoked_at' => null])`.
     - Add `'device'` to the docblock's list of `ended` values.
   - Browser sessions have `device_id` NULL (`SessionStore::create` default; `Auth::attempt` passes none), so this costs nothing in P2A. It closes the race where a P2B Station login inserts a session while a revocation commits.
3. **`src/Http/Page.php` `resolve()`**: add `elseif ($reason === 'device') { Flash::info('This tablet was taken out of service, so you were signed out. Ask a Coordinator for another tablet.'); }`.
4. **`src/Auth/capabilities.php`**: Administrator's list gains `'device.erase'`.
5. **`src/Inventory/CountRepository::pendingDeviceItems`**:

   ```sql
   SELECT COALESCE(SUM(pending_count), 0) FROM device
    WHERE site_id = ? AND token_hash IS NOT NULL AND wiped_at IS NULL AND (revoked_at IS NULL OR wipe_mode = 'Push Then Wipe')
   ```

   Docblock: "Tablets in service, and retiring tablets that will still upload; not erased tablets or ones told to erase without uploading." The template copy at `templates/pages/inventory/count.php:62-63` is unchanged.
6. **`src/Account/AccountService.php`**
   - In `update()`, inside `if ($accessChanged) { … }` (after `SessionStore::endAllForUser`), add `Tokens::revokeAll($userId, Tokens::DEVICE_REGISTRATION);`.
   - In `deactivate()`, inside `if ($now) { … }`, add the same.
   - `src/Cron/Jobs/DeactivateDueAccounts.php`: add the same after its two `revokeAll` calls.
   - The pending tablets then show "No working code". The code's creator is also re-checked at redemption in P2B.
7. **`src/Reference/settings_registry.php`**: the entry in §6.
8. **CSS**
   - `app.css`:
     - `.button-danger { color: var(--error-text); border-color: var(--error-text); }`
     - `.badge-problem { color: var(--error-text); border-color: var(--error-text); }`
     - `.sheet-site { font-size: 1.5rem; font-weight: 700; }`
     - `.sheet-code { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 1.6rem; letter-spacing: .08em; overflow-wrap: anywhere; }`
   - `print.css`: `.print-sheet .sheet-code { font-size: 20pt; }`.
   - No JavaScript: templates have none, and the CSP is `script-src 'self'`.
9. **`seeds/dev/004_devices.sql`**. Idempotent: `INSERT … SELECT … WHERE NOT EXISTS` on the unique `token_hash`.
   - Tablets at Dev Site North, all with `registered_by` = the `system` user and `token_hash = SHA2(CONCAT('pfpms-dev-device:', label), 256)`:
     - "Front desk 1": in service, seen 5 minutes ago, 3 pending, persisted, `offline_enabled` 1, build `0.1.0-dev`.
     - "Front desk 2": in service, never heard from.
     - "Intake table": in service, seen 2 days ago, 12 pending, storage not kept, build `0.0.9`. This shows the stale, storage and old-build warnings.
     - "Spare tablet": retiring for 1 day (`revoked_at`, `revoked_by` system, `wipe_mode` 'Push Then Wipe', `is_site_registered` 0), 2 pending.
   - Times are `UTC_TIMESTAMP() - INTERVAL n MINUTE`.
   - Header comment: the hashes are made up and no tablet holds them; the rows have no vault key, so P2B treats them as unable to work offline.
10. **`tests/TestCase.php`**: add `protected function makeDevice(int $siteId, array $overrides = []): int`. It inserts `site_id`, a unique `label`, `is_site_registered` 0, `registered_at` = NOW, plus any overrides (`token_hash`, `is_site_registered`, `pending_count`, `last_seen_at`, `reported_max_seq`, `revoked_at`, `wipe_mode`, `wiped_at` …).

---

## 9. Pages, flows and templates

All three pages:
- start with `$ctx = Page::start(['capability' => 'device.register'])`, **without** `'site' => true`, then `$scope = DeviceScope::fromContext($ctx)`;
- call `Csrf::verify()` on every POST;
- contain no SQL and no JavaScript.

Loading an existing device is the same in both device pages (the pattern of `public/inventory_receipt_edit.php:28-31`):

```php
$device = DeviceRepository::find($id) ?? Response::notFound();
if (!$scope->covers($device['site_id'] === null ? null : (int) $device['site_id'])) {
    Audit::durable('access_denied', 'device', $id, 'Denied', 'Device at a site not available to this user', ['site_id' => $device['site_id']]);
    Response::notFound();
}
```

### 9.1 `public/admin_devices.php` → `templates/pages/admin/devices.php` (GET only)

- **Filters:**
  - `?site=<id>`: ignored unless `$scope->covers(id)`.
  - `?show=all`: includes cancelled and erased rows.
  - The site select lists `SiteRepository::all()` for `site.all` holders, with inactive sites marked "(inactive)", and `$ctx->sites` for everyone else. It is hidden when there is only one site.
- **Data:**
  - `DeviceRepository::list($scope, $siteFilter, $showAll)`;
  - `DeviceService::redemptionAvailable()`;
  - `Settings::bool('offline_mode_enabled', true)`;
  - `APP_VERSION`.
- **Head:** "Devices", with the lead "Tablets and Chromebooks that run the Station at your sites." Also a **Register a tablet** button (`admin_device_edit.php`), shown when `$ctx->sites` is not empty.
- **Notice** while `!redemptionAvailable()`: "Tablets cannot be registered on this server yet: the Station app is not installed here. Registration sheets can be printed, but they will expire unused, and tablets have nothing to report."
- **Table** (`.table-wrap` / `.table`), one row per device:
  - **Tablet**: a link with the label and "Tablet no. {device_id}".
  - **Site**: the name, or "No site", plus a "site inactive" badge.
  - **Status**: the badge and the first warning.
  - **Last heard from**: `ago()` or "Never".
  - **Unsynced (reported)**: "{n}, as of {ago}", or "Not reported".
  - **App build**: the value or "—", plus an "older" badge.
  - **Storage kept**: Yes / No / Not reported.
  - Cancelled and erased rows get `status-inactive`.
- **Empty states:**
  - "No tablets yet. Register the first tablet for a site."
  - For a Coordinator with no current sites: "You have no sites at the moment, so there are no tablets to show."

### 9.2 `public/admin_device_edit.php`

**No `id`: the Add form** (`templates/pages/admin/device_add.php`, `.form-narrow`)
- **GET:**
  - `form_key = FormOnce::issue()`.
  - The site `<select>` comes from `$ctx->sites`. It is preselected from `?site=` when that site is in `$ctx->siteIds()`, otherwise from `$ctx->siteId`, otherwise from the only site; otherwise it starts on "Choose a site".
  - The label field has `maxlength="50"`.
  - Hint: "Name it by where it is used, for example Front desk 1. Do not use a person's name."
  - Buttons: **Add tablet** and Cancel.
- **POST:**
  1. `Csrf::verify()`.
  2. `if ($to = FormOnce::done(Request::string('form_key'))) Response::redirect($to);`.
  3. `$id = DeviceService::add(['site_id' => Request::string('site_id'), 'label' => Request::string('label')], $scope)`.
  4. `FormOnce::remember($key, "admin_device_credential.php?id=$id")`.
  5. `Flash::success('Tablet added. Now create its registration sheet.')`.
  6. Redirect to `admin_device_credential.php?id=$id`.
  7. A `ValidationException` re-renders with `$errors` and the typed values.

**With `?id=`: detail and actions** (`templates/pages/admin/device_edit.php`)

Load and scope-check as above. Every form carries `csrf_field()`, a hidden `revision = DeviceService::revision($device)`, and `name="action" value="…"`.

- **Heading:** "{label} at {site}", then "Tablet no. {id}", the status badge and label, and the warnings.
- **Facts:**
  - Site (and inactive).
  - "Added by {name} on {date}" for waiting rows, or "Registered {date}" (with `registered_by_name`).
  - "Registration code works until {time} (created by {issued_by_name})", from `liveCode`.
  - "Out of service since {date}, by {name}: retire (upload, then erase) | erase now{, reported lost or stolen}".
  - "Erased {date}".
- **"What the tablet last reported"** (`<dl>`):
  - Last heard from (relative and absolute);
  - Unsynced records ("{n}, reported by the tablet at {time}");
  - Last upload (`last_sync_at`);
  - Station build (and "current is {APP_VERSION}" when it differs);
  - Storage kept;
  - Offline (ready, or online only and why).
  - While `last_seen_at` is NULL, one line replaces the list: "Not reported yet. A tablet reports these once the Station app is running on it."
- **"People who signed in on this tablet"**, from `DeviceRepository::recentUsers($id)`:
  - names and the last sign-in time; a name links to `admin_user_edit.php` only if `$ctx->can('user.manage')`;
  - hidden when the list is empty, which is always the case in P2A;
  - for a retired lost tablet, followed by: "If it was lost or stolen, reset their passwords: data on the tablet is protected by them."
- **Action sections by state:**

| State | Sections |
|---|---|
| `awaiting`, `no_code` | **Name** (rename); **Create a registration sheet** (a link to `admin_device_credential.php?id=`); **Cancel this registration** (POST `cancel`) |
| `in_service` | **Name**; **Retire** (`reason`, `lost` checkbox, effect text); **Erase now** only if `$ctx->can('device.erase')` (`reason`, `confirm_label`, hidden `seen_pending = pending_count`, `.button-danger`) |
| `retiring` | **Erase now instead** (Administrator only, same fields) |
| `erasing`, `erased`, `cancelled` | none |

**Copy printed under the actions:** "To move a tablet to another site, retire it here, then add it at the new site and register it again with a new sheet."

**Retire effect text** (shown before the button, and repeated in the success flash):

> At once: everyone signed in on this tablet is signed out, and its registration, PIN switching and offline permissions stop working. Records made on it after now are held for review, not added automatically.
> Next time it connects: it uploads any records it has not sent, then erases itself.
> If it is offline now: nothing changes on the tablet until it connects. It can still be used offline for up to {offline_grant_hours} hours after someone last signed in on it, and its data is protected only by the passwords of the people who signed in on it.

- The **lost** checkbox label: "It is lost or stolen (Administrators are told at once)".
- **Erase now text:**

> Erase now is for a tablet that is lost, stolen or may have been tampered with. The next time it connects it erases everything, without uploading. It last reported {n} unsynced records ({ago}) | It has not reported how many records it holds. Those are distributions that already happened: if they are lost, stock and eligibility will not include them. If the tablet is simply no longer needed, use Retire instead.

- The same "If it is offline now" paragraph follows.
- The field label: "Type the tablet's name to confirm: {label}".

**POST dispatch**

`switch (Request::string('action'))`:
- `rename` → `DeviceService::rename($id, Request::string('label'), $rev, $scope)`.
- `cancel` → `cancel($id, $rev, $scope)`.
- `retire` → `retire($id, Request::string('reason'), Request::bool('lost'), $rev, $scope)`.
- `erase`:
  - first, `if (!$ctx->can('device.erase')) { Audit::durable('access_denied', 'device', $id, 'Denied', 'Missing capability device.erase'); throw new HttpException(403); }`;
  - then `erase($id, Request::string('reason'), Request::string('confirm_label'), Request::int('seen_pending'), $rev, $scope)`.
- Anything else → `Response::badRequest()`.

**Outcomes**
- **Success:** `Flash::success/info(...)` (§10), then `Response::redirect('admin_device_edit.php?id=' . $id)`.
- **`StaleDeviceException`:** `Flash::error($e->getMessage())` and a redirect to the same page, which shows the current state.
- **`ValidationException`:** re-render with `$errors`, keeping the typed values open in the form that failed.

### 9.3 `public/admin_device_credential.php` → `templates/pages/admin/device_credential.php`

Modelled on `public/admin_user_credential.php`: the code is created only on POST, shown once, with no redirect and no FormOnce.

- **Load and scope-check.** `$live = DeviceRepository::liveCode($id)`.
- **GET: confirm step** (only in `awaiting` / `no_code` with an active site):
  - "This creates a registration code for **{label}** at **{site}**. It works once, until about {now + codeMinutes, in the device's site time zone}, and is shown only once, so have the printer or the tablet ready."
  - When a code is live: "It cancels the code {issued_by_name} created, which works until {time}."
  - The not-installed notice while `!redemptionAvailable()`.
  - Hidden `revision`. Buttons: **Create and show the sheet**, and Cancel (back to the device page).
  - In any other state, no button:
    - registered: "This tablet is already registered. To register another tablet, add it as a new tablet.";
    - out of service: "This tablet was taken out of service, so it cannot be registered again. Add it as a new tablet.";
    - site inactive: "This tablet's site is not active. Reactivate the site first, or cancel this registration."
- **POST:**
  1. `Csrf::verify()`.
  2. `$issued = DeviceService::issueCode($id, Request::string('revision'), $scope)`.
  3. `$sheet = ['formatted' => RegistrationCode::format($issued['code']), 'qr' => RegistrationSheet::qr($issued['code']), 'expires_at' => $issued['expires_at'], 'station_url' => absolute_url('station/'), 'time_zone' => $device['time_zone'] ?? Settings::string('organisation_time_zone', 'America/New_York'), 'created_by' => $ctx->displayName()]`.
  4. Render.
  - **`StaleDeviceException`:** reload the device and `liveCode`, then show the confirm step again with a fresh revision and this message: "A registration code was created for this tablet after you opened this page (it works until {time}), so no new code was made. If that sheet is lost, create a new one: the earlier code will stop working." A refresh or Back-and-resubmit of the sheet lands here, so a printed sheet is never silently cancelled.
  - **`ValidationException`:** the confirm step with the error.
- **The sheet:**
  - A `.no-print` bar: "Print this page now, or let the tablet scan the code from this screen. Once you leave this page the code cannot be shown again." Buttons: **Done** (the device page) and **Add another tablet** (`admin_device_edit.php?site={site_id}`).
  - Then `<article class="print-sheet">`:
    - `h1` "{organisation_name}: register a tablet";
    - `.sheet-site` "For {site name}";
    - "Tablet name: **{label}** · Tablet no. {device_id}";
    - the steps (an ordered list):
      1. On the tablet, connect to the internet and open **{station_url}** in Chrome (Android tablet or Chromebook) or Safari (iPad).
      2. Install it: in Chrome choose *Install app* or *Add to Home screen*; on an iPad tap *Share*, then *Add to Home Screen*.
      3. Close the browser and open **{RegistrationSheet::APP_NAME}** from the home screen.
      4. Choose **Register this tablet**, then scan the square code below or type the code.
      5. Check that the tablet says *Registered to {site} as {label}*.
    - the QR (`<img class="qr" width="220" height="220" alt="Registration code for this tablet">`; 6 cm in print, existing `print.css`);
    - the code in `.sheet-code`;
    - "Scan it from inside the app, not with the camera app.";
    - "This code works once, until **{expires_at in the device's site zone}**.";
    - "Created by {created_by} on {date}.";
    - "Anyone with this sheet can register a tablet for {site} until then. Keep it with you and destroy it once the tablet is registered."
  - `<title>`: always "Tablet registration sheet". It never contains the code, because browsers print the title.
  - The sheet never shows participant data, usernames or emails.

---

## 10. Validation rules and user-facing messages

Field keys match the form inputs. `_form` is the page-level message. All field errors of one form are raised together.

| Rule | Key | Message |
|---|---|---|
| `Validator::text($label, 50)` is null | `label` | Enter a name for the tablet (up to 50 characters), for example "Front desk 1". |
| Label used by another current tablet at the site | `label` | Another tablet at {site} is already called "{label}". Choose a different name. |
| Site missing or unknown | `site_id` | Choose the site this tablet will be used at. |
| Site inactive | `site_id` | {site} is not active, so no tablet can be added there. |
| Site not in the actor's sites (durable `access_denied`, entity site) | `site_id` | Choose one of your sites. |
| Pending cap | `_form` | {site} already has 10 tablets waiting to be registered. Register or cancel some of them first. |
| Service scope re-check (durable `access_denied`) | `_form` | That tablet is not at one of your sites. |
| Issue on a registered tablet | `_form` | This tablet is already registered. To register another tablet, add it as a new tablet. |
| Issue on a revoked or cancelled tablet | `_form` | This tablet was taken out of service, so it cannot be registered again. Add it as a new tablet. |
| Issue at an inactive site | `_form` | This tablet's site is not active. Reactivate the site first, or cancel this registration. |
| Cancel on a registered tablet | `_form` | A tablet has already used this registration. Retire it instead. |
| Rename a revoked tablet | `_form` | This tablet has been taken out of service, so its name can no longer change. |
| Retire or erase a waiting tablet | `_form` | No tablet has used this registration yet. Cancel it instead. |
| Retire: no reason | `reason` | Say why the tablet is being retired. It is kept in the audit log. |
| Erase: no reason | `reason` | Say why the tablet must be erased at once. It is kept in the audit log. |
| Erase: typed name wrong | `confirm_label` | Type the tablet's name exactly as shown ({label}) to confirm. |
| Erase: more records reported since the page opened | `_form` | The tablet has reported more unsynced records since you opened this page (now {n}). Check again before erasing. |
| Erase: already wiped | `_form` | This tablet has already erased itself. |
| Erase without `device.erase` (service; durable Denied) | `_form` | Only an Administrator can erase a tablet without uploading its records. Retire it instead: it uploads its records first. |
| Stale revision | flash | `StaleDeviceException` default (§7.8), or the reprint text (§9.3) |
| Lock busy | `_form` | `DeviceLocks` texts (§7.5) |

**Flashes**

| After | Flash |
|---|---|
| Add | Tablet added. Now create its registration sheet. |
| Rename | Name saved. (unchanged: "Nothing changed.") |
| Cancel | Registration cancelled. Its code no longer works. (already cancelled: "This registration was already cancelled.") |
| Retire | Tablet retired. {n} session(s) on it were signed out and it can no longer be used. The next time it connects it will upload any records it still holds, then erase itself. If it was lost, add: " Administrators have been told it may be lost or stolen." |
| Retire (null) | This tablet had already been taken out of service. Nothing was changed. |
| Erase | Erase requested. The next time the tablet connects it will erase itself, including records it has not uploaded. The site's Coordinators have been asked to check stock. |
| Erase (null) | An erase had already been requested for this tablet. Nothing was changed. |
| Session ended by revocation (`Page::resolve`) | This tablet was taken out of service, so you were signed out. Ask a Coordinator for another tablet. |

---

## 11. Audit and notifications

### 11.1 Audit actions

All action names are snake_case, with `entity_type` `'device'`.
- Success rows (`Audit::record`) carry `actor: ['site_id' => device.site_id]`.
- Durable Denied rows keep the request's actor (§7.6).

**Detail and snapshot keys never contain `pass`, `token`, `secret`, `pin`, `hash` or `cipher`**, because `Audit::REDACT` would blank them. Keys used here: `site_id`, `label`, `expires_at`, `minutes`, `replaced_codes`, `codes_cancelled`, `lost`, `sessions_ended`, `grants_and_codes_revoked`, `revoked_max_seq`, `reported_pending_count`, `last_seen_at`, `last_sync_at`, `escalated_from`, `action`.

**No code, hash, ciphertext or `token_id` is ever recorded.** `code` is not covered by the redaction pattern, so the code must never be put under any key.

| Action | Outcome | Write | Contents |
|---|---|---|---|
| `device_add` | Success | record | `{site_id, label}` |
| `device_rename` | Success | record | changes `label` |
| `device_code_issue` | Success | record | `{expires_at, minutes, replaced_codes}` |
| `device_cancel` | Success | record | changes `revoked_at`; `{codes_cancelled}` |
| `device_retire` | Success | record | reason; snapshot (`COLUMNS` row); changes `revoked_at`, `wipe_mode`, `is_site_registered`, `offline_enabled`, `revoked_lost`; `{lost, sessions_ended, grants_and_codes_revoked, revoked_max_seq, reported_pending_count, last_seen_at, last_sync_at}` |
| `device_erase` | Success | record | as `device_retire`; on escalation, changes `wipe_mode` only plus `{escalated_from: 'Push Then Wipe', …}` |
| `device_erase` | Denied | **durable** | the service was called without `device.erase` |
| `access_denied` | Denied | **durable** | a device outside the scope (page or service; `{site_id, action?}`); adding at a site outside the scope (entity `site`); a forged `action=erase` from a page without `device.erase` |

- **P2B adds:** `device_register` (Success / Denied), `device_wiped` and `device_revoked_contact`. The contract is in §14.
- **Heartbeats** write no audit row.

### 11.2 Notifications

These use the existing `Notifications::toRoleOnce`, with entity `device` and id. They are written inside the same transaction as the change.

- **`device_lost`** → `'Administrator'` at the device's site, on Retire with the lost box ticked:
  > {label} at {site} was reported lost or stolen by {name} and retired. If it may be in the wrong hands, choose Erase now on its page, and consider resetting the passwords of the people who signed in on it.
- **`device_erase`** → `'Coordinator'` at the site, on Erase now:
  > {label} at {site} will erase itself without uploading the next time it connects. It last reported {n} unsynced records ({when}) | It has not reported how many records it holds. Those distributions will be lost: check stock and any paper slips.

---

## 12. Concurrency and locking rules

- **Lock order everywhere:**
  1. Named `devices:site:<id>` (add and rename only).
  2. Named `device:<id>`.
  3. `device` row `FOR UPDATE`.
  4. `auth_token` and `user_session` updates.

  Named locks are taken outside transactions with timeout 0 and give the busy messages. **No site row is ever locked** (E13). P2B redemption follows the same order: `Tokens::find` with no lock, then the named device lock, then the device row, then `consume()` (E5). Account changes lock `user_account`, then `auth_token`, then `user_session`; code creation and P2B redemption read the creator with a shared lock after the device row, and taking a tablet out of service revokes tokens before ending sessions, so the orders agree.
- **Durable audit rows inside P2A transactions are safe:**
  - refusals come before writes;
  - web pages set no device in `Audit::setActor` (`src/Http/Page.php:39`);
  - `entity_id` has no FK;
  - durable rows pass no actor override;
  - these transactions never lock a site or user row.

  P2B must follow §14.9.
- **Stale forms.** The revision (§7.6) excludes heartbeat columns and includes `live_code_id`. Erase now also checks `seen_pending`.
- **Double posts:**
  - Add uses FormOnce.
  - Reprinting is caught by the revision.
  - Retire, erase and cancel are idempotent: the no-op check runs before the revision, so a double tap never gives a second revocation or a stale-form error.
- **Label uniqueness** (among `revoked_at IS NULL` rows at a site) and the pending cap hold under `devices:site:<id>`. They are not database constraints. Seeds and any future insert path must go through `DeviceService` or respect them.
- **P2B push** locks the device row (`SELECT … FOR UPDATE`) at the start of each item transaction, re-checks `revoked_at` and `revoked_max_seq`, and only then records the item. It does **not** hold `device:<id>` for the batch: Retire and Erase now take only the row lock, which queues, so a tablet that keeps uploading can never hold them off, and `receivedMaxSeq` read under that row lock is exact.

---

## 13. Never shown, never sent to the browser

- `token_hash`, `vault_key_ciphertext`, any `auth_token.token_hash` or `secret_ciphertext`. `DeviceRepository` does not select them. The one exception is a future pairing step, which would compute `pairingCheck()` in the service.
- A registration code after its one-time response: not in a flash, `$_SESSION`, a URL, the `<title>`, a log, audit or notification.
- Participant data, sync payloads, IPs and user agents. Recent users are names only.
- Devices outside the scope: 404 plus a durable row. Their existence, label and counts are never shown.

---

## 14. Contract for Phase 2B

### 14.1 The credential
- **Value:** `'pfd1_' . Crypto::b64url(random_bytes(32))`.
- **Server storage:** `device.token_hash = Tokens::hash($credential)` (hex, UNIQUE). It is kept after revocation, so a revoked tablet is still recognised and told to erase.
- **Transport:** only `Authorization: PFPMS-Device <credential>`. Never a cookie, URL or form field.
- **Tablet storage:** the plaintext IndexedDB `meta` store, readable while the vault is locked. It is never in `localStorage` or Cache Storage.
- **DVK:** `random_bytes(32)`, stored as `Crypto::encrypt($dvk, "device:$id:dvk")` in `vault_key_ciphertext`.
- **Server-side logging:** keep the `Authorization` header out of error logs and incident dumps.

### 14.2 The guard (P2B builds it)
- **Signature:** `Api::start(['device' => 'in_service' | 'known'])`. `PageContractTest` needs no change.
- **Header plumbing:**
  - Read `Authorization`, falling back to `$_SERVER['REDIRECT_HTTP_AUTHORIZATION']` in `Request::header`.
  - `public/.htaccess` gets `CGIPassAuth On` or `RewriteCond %{HTTP:Authorization} .` + `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`. **Verify this on SiteGround staging.**
- **Lookup:**
  - Rate-limit unknown credentials per IP.
  - Look the device up by `Tokens::hash()`.
  - Then `Audit::setActor(null, null, (int) site_id, deviceId)`. No CSRF.
- **`'in_service'`:**
  - requires `IN_SERVICE_SQL` plus an active site;
  - otherwise 403 `{error: 'device_revoked' | 'device_not_registered', directive}`.
- **`'known'`:**
  - accepts a revoked but not wiped device;
  - a wiped device gets 410 `{status: 'wiped'}` with `Clear-Site-Data: "storage", "cache"`.
- **Tests:** `RbacTest` must widen its glob to `public/*/*/*.php` (E4).
- **JSON bodies:** add `Request::json(int $maxBytes = 65536): array` (400 on bad JSON, 413 when too large).

### 14.3 `POST api/device/register.php` (redemption)
1. **Guard:** `Api::start(['public' => true])` **then `Csrf::verify()` explicitly** (E3). The anonymous Station gets its token from `api/session.php` and sends it as `X-CSRF-Token`.
2. **Rate limits**, before any transaction: `RateLimit::hit('device_register:ip:' . Request::ip(), 10, 900)` and `RateLimit::hit('device_register:all', 100, 3600)`. **After review:** count the global bucket only for codes that pass `RegistrationCode::normalise()`, and use it only to alert Administrators, never to refuse (otherwise a few addresses can block every registration).
   - Tripping the global bucket alerts Administrators, once per open alert.
   - `Notifications::toRoleOnce` needs an entity, so use `toRoleOnce('Administrator', null, 'device_register_limit', …, 'device_register', 0)`.
3. **Body:** `{code, display_mode, storage_persisted, app_build, pbkdf2_iterations?}`.
   - `$code = RegistrationCode::normalise($body['code'])`. Null gives 422 `code_mistyped`: "Check the code: one of the characters looks wrong." The Station checks this first, so typing mistakes rarely spend an attempt.
   - `display_mode !== 'standalone'` gives 409 `not_installed`: "Open the installed app from the home screen, then scan the code again." This is a reliability gate, not a security control.
4. **Find:** `$t = Tokens::find($code, Tokens::DEVICE_REGISTRATION)`, with no lock.
5. **Redeem:** `DeviceLocks::device((int) $t['device_id'])` → `Db::transaction`:
   1. `DeviceRepository::lock()`; `DeviceStatus::waitingForRegistration()` must be true (`DeviceStatus::code()` refuses a locked row: it has no live-code columns). Then read the creator with `AccountRepository::lockShared()` (device, then user, then token: the order account changes use).
   2. Re-check the creator on the `lockShared()` row from step 1:
      - `$issuer = AccountRepository::lockShared((int) $t['user_id'])`;
      - `$s = DeviceScope::forUser($issuer)`;
      - require `$issuer !== null && AccountRules::canHoldSession($issuer) && !AccountRules::mustChangePassword($issuer) && $s->can('device.register') && $s->canAddAt((int) $d['site_id'])`.
   3. `Tokens::consume($t['token_id']) === true`. It now checks expiry.
   4. Make the credential and DVK. Then `DeviceRepository::register(...)` (a P2B method):

      ```sql
      UPDATE device SET token_hash = ?, vault_key_ciphertext = ?, is_site_registered = 1, registered_by = ?, registered_at = ?,
             offline_enabled = 0, storage_persisted = ?, app_build = ?, last_seen_at = ?
       WHERE device_id = ? AND token_hash IS NULL AND revoked_at IS NULL
      ```

   5. `Audit::record('device_register', 'device', $id, details: {issued_by, app_build, display_mode, storage_persisted, pbkdf2_iterations})`, with no `token_id` in the details.
   6. `Notifications::toUser($issuerId, 'device_registered', '{label} at {site} was registered with the code you created. If that was not your tablet, retire it at once.', 'device', $id)`.
6. **Response 200:** `{device_id, site: {site_id, name}, label, credential}`. **Never** the DVK, a grant or a pack.
7. **Refusals:** every refusal gives one generic 422 `code_invalid`: "This code is not valid. It may have expired or already been used. Ask a Coordinator for a new registration sheet." It also writes a durable `device_register` Denied row with `{reason_code, ip}`, **after** the transaction has rolled back. The submitted code is never recorded.

### 14.4 `POST api/device/heartbeat.php`
- **Authentication:** the guard's `'known'` mode, with the device credential **alone** (no user session), so directives reach a locked tablet (this changes 12-design:183). The Station sends a heartbeat at start-up and on `online`, **before** any unlock screen.
- **Input:** `{app_build, storage_persisted, display_mode, pending_count, max_seq, oldest_pending_at?, storage_estimate_kb?, failed_unlock_wipe?, wiped?, items_pushed?}`.
- **Writes:**
  - Always: `last_seen_at`, `app_build`, `storage_persisted`, `pending_count`.
  - `reported_max_seq`: only while the device is in service.
  - `offline_enabled` = in service AND `storage_persisted` AND (not iOS, or standalone) AND `offline_mode_enabled`.
  - **Never:** `label`, `site_id`, `registered_*`, `revoked_*`, `wipe_mode`. The revision tokens depend on this. `wiped_at` is written only on the confirmation path below.
- **Response:** `{status: 'ok'|'revoked'|'wiped', offline_enabled, label, site, directive: null|{wipe: 'Push Then Wipe'|'Wipe Now'}, revoked_grants: [...], server_time, build}`.
- **Revoked device calling in:**
  - at most one durable `device_revoked_contact` row per device per hour;
  - `Notifications::toRoleOnce('Administrator', null, 'device_revoked_contact', …, 'device', $deviceId)`: no site, so a deactivated site's tablet still reaches someone (as `device_lost` does).
- **`wiped: true`** (sent with the in-memory credential after the tablet has deleted its storage), **accepted only from a revoked tablet** (`revoked_at IS NOT NULL`, checked under the device row lock); from a tablet in service it is ignored and never sets `wiped_at`:
  - set `wiped_at = now`, `pending_count = 0`, `vault_key_ciphertext = NULL`;
  - `Audit::record('device_wiped', 'device', $id, details: {wipe_mode, items_pushed, reported_pending_count})`, with no user and the device set;
  - reply with `Clear-Site-Data`.
- **Audit:** no row for routine heartbeats; only for changes of state (persisted flag, build, a failed-unlock wipe reported).
- **Never-confirmed wipes:** a P2B or P6 cron job clears `vault_key_ciphertext` on tablets whose wipe is still unconfirmed after `sync_payload_retention_days`.

### 14.5 What the tablet does on a directive
1. Delete the pack, the keyring, `vault_users` and drafts.
2. Push Then Wipe: push the outbox (rescue form if the vault is locked) until every item is Accepted or Held. Wipe Now: delete the outbox.
3. Delete the database and caches, and unregister the service worker.
4. Send `wiped`.

### 14.6 Push rules for a revoked device
- **Endpoint:** `api/sync/push.php` (rescue push is `rescue: true` on the same endpoint), each item under the device row lock (see §12), not the named lock.
- **Retire (`Push Then Wipe`, `revoked_lost = 0`): plan:118 unchanged.**
  - Items whose clamped `recorded_at_client` ≤ `revoked_at` are processed normally.
  - Later items are **Held**, `reason_code 'DEVICE_REVOKED'`.
- **Suspect device** (`revoked_lost = 1` or `wipe_mode = 'Wipe Now'`):
  - **Every item received once the tablet is suspect is Held**, `reason_code 'DEVICE_SUSPECT'`, whatever its `recorded_at_client` or `client_seq`. Someone holding the unlocked tablet could sign items with sequences below the cut-off that never arrived, so neither test can clear them. A Coordinator reviews them in `sync_review`.
  - `revoked_max_seq` is kept as a record for that review (how far the server had received at the revocation); it no longer decides anything.
  - Items accepted before the tablet became suspect (for example under a plain Retire that was reported lost later) stay accepted.
- Nothing is ever discarded by the server.

### 14.7 Trust gate, sessions and grants
- **Trust gate:** PIN (`api/auth/pin.php`), DVK release on login, grant issue and the pack require `IN_SERVICE_SQL`, an active site, and the user's current access to **`device.site_id`**. The Station's working site is always the device's site, never the web session's site.
- **Sessions:** Station logins call `SessionStore::create($uid, (int) $device['site_id'], $method, $deviceId)`. `validate()` already refuses them once the device is revoked.
- **Grants:**
  - Offline grants are `auth_token` rows with `device_id` and a real `expires_at`, so `Tokens::revokeForDevice` already revokes them on Retire and Erase.
  - P2B adds grant revocation to `Auth::setPassword`, `AccountService::update` / `deactivate` / `resetCredentials` and `DeactivateDueAccounts` (10-design:376).
- **Build string:** `app_build` must equal the string `DeviceStatus` is given as the current build (today `APP_VERSION`), which is also what `api/ping.php` returns. P2B may change the one call site to include the asset hash.

### 14.8 Parity and naming
- The Station's JS `RegistrationCode` passes `tests/fixtures/registration_code.json`.
- The `manifest.json` `name` equals `RegistrationSheet::APP_NAME`.

### 14.9 Durable rows on device endpoints (E14)
- `audit_log.device_id` has an FK to `device`. Once `Audit::setActor(…, deviceId)` is set, **never call `Audit::durable()` while the open transaction holds that device row**. The durable connection would wait on our own lock until `innodb_lock_wait_timeout`.
- Throw inside the transaction, let it roll back, then write the durable row in the endpoint's `catch`.

### 14.10 P2B tests to add
- Redemption:
  - two racing redemptions consume the code once;
  - a code that expires between `find` and `consume` is refused;
  - a creator who was demoted, deactivated or lost the site before redemption is refused;
  - the rate-limit hit survives a failed redemption;
  - the response carries no DVK, and `offline_enabled = 0`;
  - no CSRF token gives 400.
- Trust gate:
  - a revoked device whose `token_hash` is kept gets only the directive from every endpoint;
  - PIN on a revoked or waiting device is refused.
- Push:
  - Retire pushes before the cut-off are accepted and later ones Held;
  - a suspect device's items above `revoked_max_seq` are Held.
- Wipe:
  - wipe confirmation clears `vault_key_ciphertext` and sets `wiped_at`;
  - the count warning then drops the tablet.
- JS parity with the fixture.
- E2E:
  - register in standalone mode, refused in a browser tab;
  - Retire uploads, then erases;
  - Erase now erases before the unlock screen.

---

## 15. Tests (P2A)

**Unit**
- `tests/Unit/RegistrationCodeTest.php`:
  - `generate()` gives 17 symbols from `ALPHABET`, and `normalise(generate()) === generate()`, over 2,000 samples;
  - every symbol appears across those samples;
  - every single-symbol substitution of 50 samples is rejected;
  - `format` / `qrPayload` round-trip through `normalise`;
  - every `check`, `bytes`, `normalise`, `format` and `pairing` vector in `tests/fixtures/registration_code.json`.
- `tests/Unit/DeviceStatusTest.php`:
  - every state in §3.1 from crafted rows, including the evaluation order (a wiped row is `erased` even though it is revoked);
  - `inService` is true only for a registered, unrevoked, unwiped row;
  - every warning, including the stale threshold at exactly 24 h;
  - the "works online only" reasons (setting off, storage not kept, not switched on);
  - an unreported row never shows "0" or "No";
  - the `ago()` boundaries.
- `tests/Unit/RbacTest.php`: `testOnlyAdministratorsEraseDevices` (Administrator yes; Coordinator, Volunteer and Board no; `device.register` unchanged).

**Integration: `tests/Integration/Device/DeviceServiceTest.php`**

**Setup**
- Scopes are built with `DeviceScope::forUser($this->makeUser([...]))`, with `user_site_access` rows as `SessionTest` does.
- Durable rows are written on `Db::durable()`, outside the test transaction, so count them on that connection before and after. `AccountServiceTest::durableCount` is the pattern.
- Leave `Audit::setActor(null)` as `TestCase::setUp` sets it: a durable row that names a user or site created in the test transaction would wait on it.

*Adding tablets*
- `testAddCreatesAWaitingTabletAndAuditsItAgainstItsSite`: `is_site_registered` 0, `has_credential` 0, `registered_by` = actor, `registered_at` = NOW; `device_add` with `audit_log.site_id` = the device's site.
- `testAddReportsEveryFieldErrorAtOnce`: missing label and missing site in one exception.
- `testAddRefusesASiteOutsideTheScopeWithADurableDenial`: one more `access_denied` row (entity site), and no device row.
- `testAddRefusesAnInactiveSite`
- `testLabelsAreUniqueAmongCurrentTabletsAtASite`:
  - "front desk 1" vs "Front Desk 1" refused, and "Front dèsk 1" too (the 520 collation);
  - allowed at another site;
  - allowed again after a cancel or a retire.
- `testPendingCapPerSite`: the 11th waiting tablet is refused.

*Registration codes*
- `testIssueCodeStoresOnlyAHashBoundToTheCreatorAndDevice`:
  - exactly one live `'Device Registration'` token;
  - `token_hash === hash('sha256', code)`, `user_id` = creator, `device_id` = the device, `expires_at` = NOW + 60 min;
  - `Tokens::find(RegistrationCode::normalise(RegistrationCode::format($code)), Tokens::DEVICE_REGISTRATION)` finds it.
- `testTheCodeNeverAppearsInAuditFieldChangesOrNotifications`:
  - after add, issue, reissue, cancel, retire and erase;
  - neither the canonical nor the formatted code appears in any `audit_log.reason`, `details` or `snapshot`, any `audit_field_change` value, or any `notification.message`;
  - every audit detail key is absent from `Audit::REDACT` (the stored details contain no `[redacted]`).
- `testANewCodeCancelsTheEarlierOne`: `replaced_codes` 1; the first code no longer found.
- `testIssueCodeRefusesAStaleRevisionSoAPrintedSheetIsNeverSilentlyReplaced`: issue with revision R, then issue again with R → `StaleDeviceException`; the first code is still live.
- `testCodeLifetimeFollowsTheSetting`: set 30; `Clock::advance('+31 minutes')`; `find` gives null.
- `testNoCodeForRegisteredRetiredCancelledOrClosedSiteTablets`

*Rename and cancel*
- `testRenameChecksUniquenessAndIsAudited`: same name returns false with no audit; a stale revision throws.
- `testCancelRevokesTheRowAndItsCodeAndIsRefusedOnceRegistered`: cancelling twice returns false.

*Retire and erase*
- `testRetireRevokesEndsSessionsAndGrantsAndKeepsTheCredential`
  - Fixture:
    - `makeDevice(['token_hash' => hash('sha256', 'pfd1_test'), 'is_site_registered' => 1, 'offline_enabled' => 1, 'pending_count' => 5])`;
    - two sessions via `SessionStore::create(…, deviceId)`, one on another device, one browser session;
    - an `'Offline Grant'` and a `'Trusted Device'` token on the device;
    - a password-reset token for the same user.
  - Afterwards:
    - `revoked_at` / `revoked_by` are set; `wipe_mode` is Push Then Wipe; `is_site_registered` and `offline_enabled` are 0;
    - `token_hash` and `vault_key_ciphertext` are unchanged;
    - exactly two sessions ended with `'Device Revoked'` and `ended_by` = actor;
    - the other two sessions and the reset token are untouched;
    - both device tokens are revoked;
    - the audit has the counts, and the snapshot has no `token_hash` or `vault_key_ciphertext` key.
- `testRetireSnapshotsTheSequenceCutOff`: `revoked_max_seq` = max(`sync_item` fixture rows, `reported_max_seq`); 0 when neither exists.
- `testRetireNeedsAReasonAndRefusesAStaleRevision`
- `testRetireTwiceReturnsNullAndWritesOneAuditRow`
- `testLostOrStolenNotifiesAdministratorsOnce`: sets `revoked_lost` 1; one `device_lost` notification.
- `testOnlyAnAdministratorCanErase`: a Coordinator gets a `ValidationException` and one more durable `device_erase` Denied row; the device is unchanged.
- `testEraseNeedsTheTypedNameAndRefusesWhenMoreRecordsWereReported`:
  - the name must be exact, but case and extra spaces are accepted;
  - `seenPending` 3 while `pending_count` is 5 is refused.
- `testEscalatingARetirementToEraseKeepsTheCutOff`: `revoked_at`, `revoked_by`, `revoked_max_seq` and `revoked_lost` are unchanged; `escalated_from` is recorded; a Coordinator notification is sent.
- `testAnEraseCannotBecomeARetirement`: retire on an erasing tablet returns null; `wipe_mode` stays Wipe Now.
- `testRetireAndEraseRefuseAWaitingTablet`

*Scope, locks and listing*
- `testCoordinatorsCannotActOnAnotherSitesTablet`: rename, issue, cancel, retire and erase are each refused, with one durable `access_denied` each; the row is unchanged.
- `testAdministratorsManageTabletsAtAnInactiveSite`:
  - an Administrator lists and retires one;
  - a Coordinator who holds that (now inactive) site does not see it;
  - a Coordinator with sites A and B sees both and never C;
  - a Coordinator with no sites gets `[]`.
- `testABusyTabletLockRefusesTheAction`: `GET_LOCK(Db::lockName(Db::durable(), 'device:' . $id), 0)` held on `Db::durable()` makes `retire` throw the busy `_form` error, and nothing changes. Release the lock in `finally`.
- `testTheRevisionIgnoresHeartbeatColumns`: update `last_seen_at`, `pending_count`, `app_build`, `storage_persisted`, `offline_enabled` and `reported_max_seq`; the old revision is still accepted.
- `testListAndFindNeverCarrySecrets`: no `token_hash` or `vault_key_ciphertext` key in any row.
- `testInServiceMatchesTheSqlPredicate`: for rows in every state, `DeviceStatus::inService(find())` equals `SELECT COUNT(*) FROM device d WHERE d.device_id = ? AND <IN_SERVICE_SQL>`.
- `testCountWarningCountsInServiceAndRetiringTabletsOnly`: `CountRepository::pendingDeviceItems` counts in-service and retiring rows; it excludes erasing, wiped, waiting and cancelled rows.
- `testRecentUsersListsPeopleWhoSignedInOnTheTablet`: runs on both engines, which proves the GROUP BY.

**Additions to existing test files**
- `tests/Integration/SessionTest.php`:
  - `testEndAllForDeviceEndsOnlyThatDevicesSessions`;
  - `testASessionOnARevokedDeviceEnds`: `validate()` gives `['ended' => 'device']` and `end_reason` 'Device Revoked'; a browser session and a session on an unrevoked device are unaffected.
- `tests/Integration/TokensPolicyTest.php`:
  - `testConsumeRefusesATokenThatExpiredAfterFind`;
  - `testIssueValueStoresOnlyTheHashAndReturnsTheExpiry`;
  - `testRevokeForDevice`: that device only; purpose filter; a used registration code and expired rows are untouched; returns the count.
- `tests/Integration/Account/AccountServiceTest.php`:
  - `testAnAccessChangeCancelsThePersonsRegistrationCodes`;
  - `testDeactivationCancelsThePersonsRegistrationCodes`: now, and through `DeactivateDueAccounts`.

**Covered automatically**
- `PageContractTest`: the three pages start with `Page::start(`, and `Csrf::verify()` is present wherever `Request::isPost()` is.
- `SettingsServiceTest`: the registry and the seed agree.
- `RbacTest::testEveryCapabilityUsedInCodeExists`: `'capability' => 'device.register'` in the pages.

---

## 16. Verification

1. **Tests:**
   - `C:/xampp/php/php.exe vendor/bin/phpunit` (or `php tests/run.php`) is all green. The test bootstrap rebuilds the test database from `migrations/`, so it applies 0012.
   - `composer stan` (PHPStan) and `php bin/ci-guard.php` pass: SQL in capitals, portability, the seed file, no writes to append-only tables.
2. **Migrations and seeds**, on **MariaDB 10.4** (XAMPP) and **MySQL 8.0**:
   - `php bin/migrate.php` applies 0012; a second run is a no-op.
   - `php bin/schema-check.php` passes: 66 tables, 166 FKs, 68 settings, and the three 0012 checks.
   - `php bin/seed.php --dev` loads `004_devices.sql`; a second run adds nothing.
3. **Browser** on `http://pfpms.localhost`. There is no dev Coordinator in the seeds: as an Administrator (`bin/create-admin.php`), invite a Coordinator for Dev Site North only and activate them with the printed sheet.
   - **As that Coordinator:**
     - "Devices" appears under Administration.
     - The seeded tablets show the right statuses and warnings; "Not reported" and "Never" appear, never a misleading 0 or No.
     - The stock-count review at North mentions the seeded tablets' pending items (3 + 12 + 2 = 17).
     - **Add "Front desk 3".** A double-click on Add creates one row, and a duplicate name gives the label error.
     - **On the confirm step,** the "cannot be registered on this server yet" notice shows.
     - **The sheet:**
       - The QR decodes to `PFPMS-DEVICE:1:…` (a phone's QR reader shows text, not a link).
       - The page title and URL contain no code, and view-source contains no hash.
       - Print preview hides the navigation and the bar, with a 6 cm QR.
       - A browser refresh re-posts and shows the "no new code was made" message. SQL confirms the first code is still live.
     - **After the sheet:**
       - "Create a new one" then cancels the old code.
       - Cancel the registration.
       - Open a South device id in the URL: 404, and an `access_denied` row in `audit_log`.
     - **Erase now** is absent. A hand-made POST with `action=erase` gives 403 plus `access_denied`.
     - **Retire "Front desk 2" with "Lost or stolen" ticked.** Then check in SQL: its row, the sessions and the `revoked_*` columns. An Administrator notification appears.
   - **As Administrator:**
     - Use "Erase now instead" on the retired tablet: the wrong name is refused, the right name is accepted, and North Coordinators get a notification.
     - Erase "Intake table" directly.
     - Deactivate Dev Site South after adding a tablet there: the tablet is still listed for the Administrator, with the "site deactivated" warning, and can be retired.
   - **At 375 px width,** the table scrolls inside `.table-wrap` with no horizontal page scroll, and the forms stack.
4. **Leak checks:**
   - After issuing codes, search in PHP (as the test does) or by `SELECT` over `audit_log.details`, `snapshot` and `reason`, `audit_field_change`, `notification.message` and the PHP error log: no code appears.
   - `grep -rn "token_hash\|vault_key" templates/pages/admin/device*` finds nothing.
5. **Docs:** record the verified engines in `docs/PFPMS_schema_v2_1_changes.md`.

---

## 17. Plan deviations, notes and doc edits to record

**`docs/PFPMS_Implementation_Plan.md`**

- **P2A line 202.** Replace the `admin_devices` item with:
  > `admin_devices`: register = add a tablet (site, label) and print a single-use registration code (16+1 Crockford symbols, 80 bits, QR `PFPMS-DEVICE:1:…` that is not a URL, `device_code_minutes`) that the installed Station redeems in P2B; revoke or wipe = **Retire** (revoke + Push Then Wipe; "lost or stolen" alerts Administrators) or **Erase now** (revoke + Wipe Now, Administrator only, `device.erase`). Every revocation ends the tablet's sessions ('Device Revoked') and revokes its codes and grants at once, is final, and keeps the credential hash so the wipe directive can still be delivered. Shows last seen, pending count, build, persisted flag (as "not reported" until P2B heartbeats).
- **§4 capability matrix.** Administrator adds: "erase a tablet together with the records it has not uploaded (Wipe Now, `device.erase`)".
- **§6 schema table.** Add this row:
  > `| 0012 | auth_token.purpose += 'Device Registration'; user_session.end_reason += 'Device Revoked'; device += reported_max_seq, revoked_lost, revoked_max_seq, wiped_at; setting device_code_minutes (68 settings; tables 66 and FKs 166 unchanged) | US-01, UC-01 §3.3.3, UC-06 §4.3, P2A admin_devices |`
  - Also update line 639 ("`migrations/0002–0011`") to 0012.
- **P2B section: "Device registration, credential and heartbeat".** Add:
  - redeems the P2A code;
  - `offline_enabled` starts at 0 and only the heartbeat sets it;
  - the credential travels as `Authorization: PFPMS-Device`;
  - the heartbeat is device-authenticated only;
  - rescue push is `api/sync/push.php` with `rescue: true`, and wipe confirmation goes on the heartbeat;
  - add the device guard, the `.htaccess` `Authorization` pass-through and `Request::json` to the file list;
  - the push cut-off is tightened only for lost or erased tablets (items above `revoked_max_seq` are Held);
  - its migration is 0013 (oldest pending, storage estimate, calibrated rounds).
- **Deviations to list:**
  1. Registration is by code from `admin_devices`, with no Coordinator sign-in on the tablet. Supersedes 12-design:136-137 and 11-design:232 (a cookie set by the admin page).
  2. Revocation always carries a wipe mode. There is no revoke without a wipe and no un-revoke; registering again creates a new device row. "De-registration" (12-design:130) is not a separate state.
  3. Wipe Now needs `device.erase` (Administrator), following plan:119.
  4. New `end_reason` 'Device Revoked'. `SessionStore::validate` refuses sessions bound to a revoked device.
  5. The stock-count warning counts retiring tablets until they confirm their wipe, and leaves out Wipe Now tablets. The previous rule left out every revoked tablet.
  6. `Tokens::consume` now checks expiry, for every purpose.
  7. An access change or deactivation cancels the person's live registration codes.
  8. Tablet labels are unique per site among current tablets.
  9. Audit names are snake_case (`device_add`, `device_code_issue`, `device_cancel`, `device_rename`, `device_retire`, `device_erase`; P2B `device_register`, `device_wiped`, `device_revoked_contact`) instead of 12-design:275's "Device Registered/Revoked/Wiped".
  10. Page names are `admin_devices`, `admin_device_edit` and `admin_device_credential` (not 12-design's `deviceManagement.php` or 11-design's `devices.php`).
  11. The credential transport is `Authorization: PFPMS-Device` (plan:118). 10-design:374 (cookie), 11-design:211 and 12-design:138 (`X-PFPMS-Device`) are superseded.
  12. `offline_enabled` is never set at registration (12-design:137 superseded by 33-review:136).
  13. The heartbeat needs the device credential only (12-design:183 changed).
  14. Oldest pending item, sequence gaps, storage estimate and calibrated PBKDF2 rounds (12-design:139, 183, 219) move to P2B with migration 0013.
- **Runbook (O20) notes:**
  - Let a tablet sync before retiring it.
  - For a lost tablet: Retire with "Lost or stolen" ticked, reset the passwords of the people listed, and an Administrator decides on Erase now.
  - Print sheets close to when the tablets are set up; codes last 60 minutes by default.

**`docs/PFPMS_schema_v2_1_changes.md`**

- Add after 0011:
  > **0012 Device registration** (plan P2A `admin_devices`; US-01, UC-01 §3.3.3, UC-06 §4.3)
  > - `auth_token.purpose` gains 'Device Registration': the single-use code printed on `admin_devices`, bound to the waiting device row and to the person who created it; only its SHA-256 is stored.
  > - `user_session.end_reason` gains 'Device Revoked'.
  > - `device` gains `reported_max_seq` (the tablet's reported highest sequence, written by the P2B heartbeat), `revoked_lost` and `revoked_max_seq` (set when a tablet is taken out of service; the cut-off for a lost or erased tablet's uploads) and `wiped_at` (the tablet confirmed its erase, written by P2B). No FK.
  > - One setting, `device_code_minutes` (default 60), bringing the total to 68. Tables 66 and FKs 166 are unchanged.
- Update the "Where it has been verified" line to "0010, 0011 and 0012 were verified the same way on MariaDB 10.4 and MySQL 8.0".

---

## 18. Open questions

Questions marked **[CLIENT]** need the client. Each has the default this build uses.

| # | Question | Default used |
|---|---|---|
| Q-A **[CLIENT]** (extends plan Q1, roles) | May Coordinators use Erase now at their own sites, or is it Administrator-only? | **Administrator-only** (`device.erase`, plan:119). Coordinators Retire with "Lost or stolen", which revokes all trust on the server at once and alerts Administrators. Changing this means editing `capabilities.php` only. |
| Q-B **[CLIENT]** | What should happen to the tablets at a site that is deactivated? | **Nothing automatic.** Administrators still see them, with a "site deactivated" warning, and retire them. Coordinators stop seeing them. Codes cannot be created there. |
| Q-C **[CLIENT]** | Should a Coordinator confirm a six-character pairing check (shown on the tablet and on its page) before a new tablet is trusted? It adds a step per tablet and closes the "photographed sheet redeemed first" risk. | **No in R1.** The protections are an 80-bit single-use code, a 60-minute life, the creator re-checked and notified at redemption, the real tablet's "already used" error, and a credential that alone gives no personal data. `RegistrationCode::pairingCheck()` and its fixture exist, so the step can be added later without reprinting sheets. |
| Q-D **[CLIENT]** (branding, low priority) | What name should the Station have on a tablet's home screen? | **"Pet Pantry Station"** (`RegistrationSheet::APP_NAME`; P2B's manifest must match). |

**Existing client questions this slice depends on:**
- **Q10 (final production domain)** must be settled before real tablets are registered: a change of origin wipes every tablet.
- **Q9 (hardware)** decides which install steps the sheet shows.
- **Q6 (offline limits)** sets `offline_grant_hours`, which the Retire copy quotes.

**Team notes, not client questions:**
- Migration 0012 is reserved for this slice and 0013 onward for P2B.
- The P2B guard must use `DeviceRepository::IN_SERVICE_SQL`; registration takes `DeviceLocks::device`, while uploads and the wipe confirmation take only the device row lock per item (§12); all follow §14.9.

---

## 19. Risks

- **Codes before P2B.** Codes can be printed in P2A but not redeemed until P2B. The page and the confirm step say so. Codes expire harmlessly.
- **Unconfirmed wipes stay visible.** Until P2B writes `wiped_at`, retired and erased tablets show "requested" indefinitely, and a retiring tablet's reported pending count stays in the count warning. Only the dev seed has non-zero counts in P2A.
- **Kept `token_hash`.** A revoked tablet keeps `token_hash` on purpose. A P2B endpoint that checks the hash or `is_site_registered` alone, instead of `IN_SERVICE_SQL`, would re-open access. Revocation clears `is_site_registered`, and the P2B guard tests cover this.
- **Erase now needs an Administrator.** On a distribution day with no Administrator reachable, discarding a lost tablet's outbox waits. Server-side trust is still cut at once by a Coordinator's Retire (Q-A).
- **Self-reported figures.** Pending count, persisted flag and build are reported by the tablet and can be stale or spoofed. They are labelled as reported values with their age, and no decision relies on them.
- **Changes to shared paths.** This slice changes `Tokens::consume`, `SessionStore::validate` (a PK join on the per-request path), `CountRepository` and `AccountService`. Each change has its own tests.
- **Rules outside the database.** Label uniqueness and the pending cap live in the application, under a named lock, not in the database.
- **ENUM lists.** `MODIFY … ENUM` restates the full lists. A typo would drop or refuse a value in use. The schema-check ENUM checks and running the migration on both engines guard against it.
- **Header stripping.** The `Authorization` header can be stripped under SiteGround's Apache CGI/FastCGI. P2B must verify the pass-through on staging.
