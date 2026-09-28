<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

# PFPMS feature delivery plan: phases, modules, dependencies

Evidence base: the six analyst reports in the workflow journal, plus spot checks of `docs/PFPMS_schema_v2.sql`, `docs/PFPMS_schema_v2_notes.md`, `index.php`, `header.php`, the root page list (156 files) and `composer.json`. Nothing was modified.

---

## 0. Conventions every module follows

**Assumed shared core.** Other plans design it. These names are placeholders to align with those plans:
- `include/bootstrap.php` provides `db()` (a PDO singleton with utf8mb4, `ERRMODE_EXCEPTION` and real prepares), `tx(callable)`, `current_user()`, `current_site()`, `require_login()`, `require_capability($cap, $siteId=null)`, `can($cap)`, `csrf_field()`/`csrf_verify()`, `audit($action,$entity,$id,$outcome,$reason,$snapshot,$details)`, `audit_fields($auditId,$old,$new)`, `setting($key)`, `render_header($title)`/`render_footer()`, `hsc()`, `site_today($siteId)`, `mailer()`, `crypto_seal()`/`crypto_open()` (libsodium; this is where `security_config.php` goes), `notify()`, and `require_public_page()` for pages that need no login.
- **Page skeleton**, used in every new file: `require bootstrap → require_capability() → csrf_verify() on POST → validate → call a domain service → redirect, or render`. There is no SQL in pages.

**Data-access naming decision: `database/<Aggregate>Repository.php` classes plus `domain/<Name>Service.php`, not `dbX.php`.** Reasons:
1. Through Phases 0–3 the parked legacy `db*.php` files still exist. Today `db*.php` means mysqli with string-built SQL. A distinct suffix makes new-schema, prepared-only code easy to grep and gate: CI can require that every `database/*Repository.php` contains `prepare(`.
2. Several legacy names mean something different in v2: `dbDistribution.php` and `dbItemCategory.php` are human-food tables, and `dbPersons.php` is not `user_account`.
3. A distribution spans participant + distribution + line + pet + inventory_transaction + site_stock in one transaction. That needs one PDO handle passed explicitly to several repositories. Objects make this natural; free functions that each call `connect()` do not.
4. Pages, `api/sync.php` and `cron/` call the same services. Composer `classmap` autoloading of `database/` and `domain/` avoids fragile require chains.

This is still not a framework: there is no container and no ORM, and repositories return arrays. Shared validators live in `domain/validation/` and are used by the forms, the importer and the sync API, because UC-12 §4.5 requires the same rules everywhere.

**Other folders**
- `api/*.php`: JSON endpoints, still one file per endpoint.
- `cron/run.php`: CLI-only dispatcher, run every 15 minutes, with jobs in `cron/jobs/`.
- `tools/`: CLI-only scripts (seed, migrate, staff migration).
- `js/app/`: vanilla ES modules with no build step.
- `js/vendor/`: self-hosted qrcode, html5-qrcode and Chart.js. Offline mode cannot rely on the current CDN loads (Chart.js, jsPDF, SheetJS, Google Fonts).
- `lang/en.php`, `lang/es.php`: strings for participant-facing printouts.
- `config/capabilities.php` and `config/nav.php`.
- `sql/migrations/V2_1_NNN__*.sql`.
- `_legacy/`: the parking lot, with an `.htaccess` of `Require all denied`.

**Capability matrix.** The default below lives in `config/capabilities.php`. Changing it is a config edit, which answers notes §4.1.

| Capability group | Vol | Coord | Admin | Board |
|---|---|---|---|---|
| participant.search/view/register/edit, pet.edit, checkin.manage, distribution.record, history.view, snv.refer | ✓ | ✓ | ✓ | |
| event.manage/open_close, dashboard.event, inventory.receive/count, barcode.link, distribution.reverse, distribution.override (emergency, over-allotment, second issue, flag lift), users.roster_view, intake.manage, language.manage, snv.manage (void, outcome, schedule) | | ✓ | ✓ | |
| participant.edit_restricted/flag/delete/merge/erase.*, alert.resolve, pet.delete/limit_override, users.manage, config.manage, settings.manage, policy.manage, clinic.manage, budget.manage, import.run, reports.view/schedule, audit.view | | | ✓ | |
| reports.identifiable (also needs `user_account.can_extract_identifiable=1`) | | | ✓ (flagged) | |
| dashboard.board | | | ✓ | ✓ |

**Nav registry (`config/nav.php`).** Each entry is `{file, label, group, capability}`. `index.php` and `header.php` show an entry only if `file_exists($file) && can($cap)`. Modules "light up" by adding their file plus one registry line, and nav can never link to a page that doesn't exist.

---

## 1. Phase overview

| Phase | Goal | Modules | Stories (pts) | Use cases | Size (dev-days) |
|---|---|---|---|---|---|
| **P0** | Security deletes, legacy removal, shell cutover to the v2 database | P0-A…P0-C | — | UC-17 skeleton | 3–5 (plus core, assumed) |
| **P1 = Release 1** (first distribution day) | Everything needed to run a real event online or offline | M1–M12 | Must: US-01, 03, 18, 22, 26, 32 (28). Should: US-04, 05, 06, 08, 09, 13, 14, 16, 17, 21, 23 (48) | UC-01…UC-08 core, UC-12 minimal, UC-17, UC-18, UC-19 | 100–125 |
| **P2** Governance and ops hardening (first 30–60 days live) | Delete, merge, erasure, access control, audit viewer | M13–M17 | US-02, 11, 12, 25, 28 (17) | UC-04 §3.2.4 and §4.4, UC-05 §3.2.3–3.2.4, UC-09, UC-10, UC-11 rest | 29–37 |
| **P3** Full import and reporting | Batch import, report framework, exports, scheduler, Board view | M18–M21 | US-24, 33, 36, 38, 39, 40 (35) | UC-12 full, UC-11 §3.2.4, UC-13–UC-16 | 41–50 |
| **P4** Could stories and training mode | Enhancements needing data history or settings | M22 | US-07, 15, 20, 27, 29, 30, 34, 35, 37, 41 (52) | — | 29–38 |
| **P5** Blocked on schema or external decisions | Participant self-service, OCR intake | M23 | US-10, 19, 31 (29) | — | 20–28 |

Totals: 41 stories and 209 points (6 Must + 23 Should + 12 Could), all placed. R1 stretch candidates, if capacity allows: US-02, US-12, US-28 (11 points, all cheap on R1 tables).

---

## 2. Dependency graph (summary)

```
P0 security deletes ─► P0 legacy removal ─► CORE (assumed) ─► P0 cutover (new index/header/login on v2 DB)
                                                        │
      ┌──────────────────────── M1 Auth/sessions (US-01, US-03) ──► M3 User accounts (UC-11 core)
      │
      └─► M2 Reference & config admin (sites, devices, species/breed/size band, allotment rules,
             service area, settings, policies, languages, intake Qs US-09, lookup lists)
             ├─► M4 Catalogue & stock (products, barcodes US-16, receipts, counts) ─────────┐
             ├─► M5 Participants (UC-02/03/04/18/19, US-05/06/08) ─► M6 Pets (UC-05, US-13/14/26) ─┤
             └─► M7 Events & check-in (US-04) ─────────────────────────────────────────────┤
 M12 Print/PDF/QR libs ─► (M5 card, M8 receipt, M10 voucher)                                  ▼
                                   M8 Station + Record Distribution + OFFLINE (UC-06, US-17/18) ─► M9 History (UC-07)
                     M6 + M2 ─► M10 SNV + clinic portal (UC-08, US-21/22/23)
       M2 (US-09) + M5/M6 validators ─► M11 Import template + minimal import (US-32, UC-12 base)
R1 ─► P2 (M13–M17) ─► P3 (M18–M21; needs P2 audit viewer and cron) ─► P4 ─► P5
```

**Critical path:** core → M2 (sites, species, size bands, allotment v1, settings) → M5 → M6 → M8 (station and offline) → offline dress rehearsal.
- M4, M7, M10 and M11 run in parallel off M2, M5 and M6.
- Start an M8 client spike (service worker, encrypted IndexedDB, sync contract) in the same sprint as the core, against a stubbed `api/sync.php`.

---

## 3. Phase 0: cleanup and shell cutover (without breaking the site)

**Invariant on every deployed state:**
- login works;
- every nav link resolves;
- no web-root page includes a legacy `db*.php` file or queries a table that the connected database doesn't have;
- no page runs PHP inside an HTML comment.

Ordered commits, deployable as one release. D1 can be deployed on its own as an immediate hotfix.

**D0: defuse `index.php`**
- `index.php` requires `dbEvents`, `dbApplications` and `dbMessages` from inside `<!-- -->` blocks, around lines 524–605. The PHP still runs: it calls `all_pending_names()` and `get_user_unread_count()`.
- Strip those blocks and the `deletePet.php` tile before any `db*.php` file is deleted. Otherwise deleting `dbEvents.php` or `dbMessages.php` is a fatal error on the home page.
- Also remove the header's commented `dbShifts` include (lines 11–14 are inside a block comment, so harmless, but delete them anyway).

**D1: security and unauthenticated deletes (day 1)**
- Delete these pages: `getVolunteers.php`, `clockOut.php`, `clockOutBulk.php`, `autoCheckOut.php`, `deleteBulk.php`, `VolunteerRegister.php`, `create_dummy_dbpersonhours.php`, `viewData.php`, `email.php`, `createEmail.php`, `sendDraft.php`.
- Also delete these three REWRITE-class files now, for security. They are replaced later.
  - `insertAdmin.php`, replaced by `tools/seed_admin.php` (CLI).
  - `toggleLock.php`, replaced by `pinSwitch.php` in M1.
  - `scheduledSend.php`: disable the SiteGround cron first. It is replaced by a P3 cron job.
- Delete non-page files: the `email/` folder (the bundled PHPMailer, `sendEmail.php`, `send_email.php`, `send_email.py`, `get_oauth_token.php`), and `emailEncryption.php` (the forgeable reset-token scheme; this overrides the UI report's ADAPT).
- Disable `forgotPassword.php` until M1. Admins reset passwords meanwhile.
- Remove the links to these pages from `login.php` (`VolunteerRegister.php`, and `register.php`, which doesn't exist).

**D2: already broken (missing includes or tables)**
- `viewArchived.php`, `addTraining.php`, `viewLocation.php`, `deleteLocation.php`, `deleteService.php`, `emailDraftView.php`, `emailSingleDraftView.php`, `report.php`, `reportsPage.php`.

**D3: volunteer events, sign-ups, hours and training (47 pages)**
- Events and calendar views: `calendar-view.php`, `calendar-view_daily.php`, `calendar-view_weekly.php`, `cancelEvent.php`, `completeEvent.php`, `date.php`, `deleteEvent.php`, `eventSearch.php`.
- Sign-ups: `approveSignup.php`, `rejectSignup.php`.
- Event lists and attendance: `event-list.php`, `eventList.php`, `logAttendees.php`, `setTimes.php`, `viewSignUpList.php`, `noShows.php`, `adminViewingEvents.php`, `eventApproved.php`, `viewAllEvents.php`, `viewMyUpcomingEvents.php`.
- Applications: `denyApplication.php`, `eventManagement.php`, `eventSignUp.php`, `fromPendingApproveSignup.php`, `fromPendingFlagSignup.php`, `fromPendingRejectSignup.php`, `process_application.php`, `viewAllApplications.php`, `viewApplication.php`, `viewEventSignUps.php`, `viewPendingApps.php`, `eventsOpenForSignUpReview.php`, `viewEventsForSignUp.php`.
- Hours: `editTimes.php`, `deleteTimes.php`, `editHours.php`, `volunteerReport.php`.
- Static result pages: `applicationSuccess.php`, `eventFailure.php`, `eventFailureBadDepartureTime.php`, `eventSuccess.php`, `requestFailed.php`, `signupPending.php`, `signupSuccess.php`, `processAttendees.php`, `viewRetreatApplications.php`.
- Training: `eventTrainingManagement.php`.
- Supporting files:
  - `database/`: `dbEvents.php`, `dbApplications.php`, `dbAttendance.php`, `dbShifts.php`, `dbtraining.php`, `dbTrainingPersons.php`, `dbAppointments.php`, `dbEventMedia.php`, `InventoryEvent.php` (a stray copy).
  - `domain/`: `Event.php`, `Application.php`, `Shift.php`, `Training.php`, `EventMedia.php`.
  - `js/`: `calendar.js`, `event.js`, `view-switcher.js`.
  - Root `event.css` and `event.js`.
  - `css/`: `event.css`, `roster.css`, `hours-report.css`.
  - `include/time.php`.

**D4: communications (27 pages)**
- Discussions: `createDiscussion.php`, `deleteDiscussion.php`, `deleteReply.php`, `discussionContent.php`, `discussionMain.php`, `viewDiscussions.php`.
- Suggestions: `createSuggestion.php`, `viewSuggestions.php`.
- Groups: `createGroup.php`, `deleteGroup.php`, `showGroups.php`, `manageMembers.php`, `groupManagement.php`, `volunteerViewGroup.php`, `volunteerViewGroupMembers.php`.
- Messages: `inbox.php`, `viewNotification.php`, `deleteNotification.php`.
- Email drafts and lists: `editDrafts.php`, `viewDrafts.php`, `emailDraft.php`, `generateEmailList.php`, `scheduleEventEmails.php`.
- Supporting files:
  - `database/`: `dbMessages.php`, `dbDiscussions.php`, `dbDiscussionReplies.php`, `dbSuggestions.php`, `dbGroups.php`.
  - The matching `domain/` classes.
  - `js/messages.js`, `css/messages.css`.
  - Remove `unpackMessageTimestamp` and `prepareMessageBody` from `include/output.php`.

**D5: users, uploads, reports, misc (16 pages)**
- Users: `modifyUserRole.php`, `deleteUserSearch.php`, `deleteUser.php`, `deletePerson.php`, `volunteerManagement.php`, `milestonePoints.php`, `viewVolunteerProfile.php`, `accountEditHistory.php`, `infoBox.php`.
- Uploads: `view_encrypted_gallery.php`, `approve_encrypted_image.php`, `deny_encrypted_image.php`.
- Resources: `resources.php`, `uploadResources.php`, `deleteResources.php`, `viewResources.php`.
- Inventory: `viewEditDeleteInventory.php`.
- Reports: `reports.php`, `reportsCompute.php`, `reportsExport.php`. Don't port `calculate_age` or `export_data`: both are broken. Use `DateTime::diff` instead.
- Also delete `database/dbLog.php`, `database/dbEditLog.php` and `domain/logEntry.php`.

Check: D1 (11 of the 110) + D2 (9) + D3 (47) + D4 (27) + D5 (16) = **110 DELETE pages, all removed in P0**. They all read tables that don't exist in v2, so none can survive the cutover.

**P0-B: park the remaining legacy files**
- Use `git mv` to move the 31 ADAPT pages and the 7 unconverted REWRITE pages (`registrationForm`, `addEvent`, `editEvent`, `event`, `viewCheckInOut`, `processCheckIn`, `checkedInVolunteers`, `viewShoppingList`, `viewConsumptionRates`, `upload_encrypted_image`) into `_legacy/`.
- Move the remaining `database/db*.php` and `domain/*.php` files they use into `_legacy/` too.
- Each module later `git mv`s its starting file back out under the new name, so rename detection keeps the history.
- `_legacy/` must be empty and deleted by the end of P3.

**P0-C: cutover, one PR**
- Load the portable v2 DDL plus the P0/R1 v2.1 migrations into a new database. The legacy `foodpantrydb` is archived, not migrated.
- Delete `database/dbinfo.php` (it holds production credentials) and `universal.inc` (`display_errors=1`, jQuery 1.9.1).
- `index.php` is rewritten as the UC-17 role dashboard, built from `config/nav.php`. It shows the current site, outstanding alerts and notification counts, and has no legacy includes. The Board role sees a "reports coming" placeholder until P3.
- `header.php` is rewritten in place. It builds role menus from the same registry and adds a site switcher, the signed-in user, an offline and sync indicator, and self-hosted assets. The `$permission_array` goes, because gating moves into each page's `require_capability()`.
- `login.php` and `logout.php` are adapted to the core, minimally; M1 finishes them.
- `template.php` is rewritten as the new-page skeleton for developers.
- The shared inline classes (`.report-table`, `.updateInv-*`, `.modify-*`) are consolidated into `css/app.css`.
- Delete `lib/jquery-1.9.1.js` and the Tailwind CSS files once nothing references them.
- Seed a fresh Administrator (`tools/seed_admin.php`). Optionally run `tools/migrate_staff.php` for the ~4 real staff accounts. Their bcrypt hashes are compatible but appear in committed dumps, so force `must_change_password=1`.
- Add a local check, `tools/check.sh`, that fails the build if:
  - any `<!--…<?php` pattern exists;
  - any web-root file includes `_legacy/` or `database/db*.php`;
  - `_legacy/.htaccess` is missing;
  - a `*Repository.php` has no `prepare(`.

---

## 4. Phase 1 = Release 1: modules

### M1 Authentication, sessions, PIN switch, confidentiality acknowledgement (M, 7–9 d)
- **Covers:**
  - UC-01 base flow, §3.2.1 and §3.2.2, §3.3.1 and §3.3.2, §4.
  - Offline restricted session §3.3.3 is delivered in M8.
  - "Manage User Profile".
  - US-01, US-03 (both Must).
- **Pages:**
  - ADAPT in place: `login.php`, `logout.php`, `changePassword.php`, `forgotPassword.php`.
  - ADAPT and rename: `_legacy/changeForgottenPassword.php` → `passwordReset.php`.
  - ADAPT and merge: `viewProfile.php`, `editProfile.php` and `profileEditForm.php` → `myProfile.php` (display name, phone, notification_prefs, set or change PIN; role, sites and status cannot be changed here).
  - REWRITE: `toggleLock.php` (deleted in P0) → `pinSwitch.php`.
  - NEW: `acknowledgePolicy.php`, `siteSelect.php` (for users with more than one site).
- **Data:** `database/UserRepository.php` (user_account, user_site_access), `SessionRepository.php` (user_session), `TokenRepository.php` (auth_token), `PolicyRepository.php` (policy_document, policy_acknowledgement). `domain/AuthService.php`, `PasswordPolicy.php` (min length plus a bundled common-password list).
- **Tables:** user_account, user_site_access, user_session, auth_token, device, policy_document, policy_acknowledgement, audit_log, system_setting.
- **Rules:**
  - Login:
    - Log in by username or email with a generic failure message.
    - Increment `failed_login_count`; at `max_failed_logins`, set `locked_until = now + lockout_minutes` and send a `notify()` to Admins.
    - Deny when the status isn't Active or Pending, or `expiry_date` has passed.
    - Call `session_regenerate_id`.
    - Write a user_session row (Password, device_id, site_id). The core enforces idle (`session_idle_minutes`) and absolute (`session_absolute_hours`) limits on every request.
    - Audit every attempt.
  - Forced change: `must_change_password` → `changePassword.php` before any menu; Pending becomes Active.
  - Password reset:
    - The link carries a random 32-byte token and only its SHA-256 is stored in auth_token 'Password Reset', expiring after `reset_link_minutes`.
    - The token is single-use and the flow doesn't reveal whether an email is registered.
    - On success, end all of that user's sessions.
  - US-03: after login, if there is no acknowledgement of the current Confidentiality Agreement version (in the user's language, falling back to the default), route to `acknowledgePolicy.php`.
    - Accept writes policy_acknowledgement, sets `onboarding_completed_at` the first time, and audits.
    - Decline ends the session (audited).
  - US-01:
    - Allowed only when the `device` cookie validates (HMAC of device_id) and `device.is_site_registered=1`.
    - The target user must have active user_site_access at the device's site and have signed in with a password on that device today (that is the "on shift" definition, since there is no shift table).
    - The 4–6 digit PIN is checked against `pin_hash`, with attempts throttled; after `pin_max_attempts` a password is required.
    - End the previous session ('Logout'), then start a new one with auth_method 'PIN'.
    - The open entry is stashed per user in the station's IndexedDB (M8), so it is kept.
- **Acceptance:**
  - A locked account is refused; the lockout expires on time.
  - A reset link works once, and not after it expires.
  - A reset kills all of the user's sessions.
  - Declining the acknowledgement lands on the logout page.
  - A new policy version forces re-acknowledgement.
  - A PIN switch completes in under 5 s on a registered tablet and is refused on an unregistered one.
  - The station draft survives the switch.
  - Every attempt appears in audit_log with device and site.

### M2 Reference data and configuration admin (L, 9–12 d)
This covers the admin screens no use case describes; all are needed before day one.

| Screen | File (NEW unless noted) | Tables | Key rules |
|---|---|---|---|
| Sites | `sites.php`, `siteEdit.php` | site | Time zone validated against `DateTimeZone::listIdentifiers()` |
| Devices | `devices.php` | device | "Register this device for site X" sets the signed device cookie; deregistering makes the station wipe its IndexedDB at next contact |
| Languages (US-23) | `languages.php` | language | Coordinator can add or deactivate; `en` cannot be deactivated |
| Policy documents | `policyDocuments.php`, `policyDocumentEdit.php` | policy_document | Versioned per `doc_type` × language; a new version is a new row; edits to a used version are refused |
| System settings | `settings.php` | system_setting | Typed registry in `config/settings.php` (type, min, max, unit, owning UC); every change audited old→new |
| Service area | `serviceArea.php` | service_area_postal_code | Bulk paste; normalise ZIP+4 to 5 digits; optional site mapping (UC-04 §3.2.1) |
| Species and breeds | `species.php`, `breeds.php` | species, breed | Deactivate only, never delete |
| Size bands (US-13) | `sizeBands.php`, `sizeBandEdit.php` | size_band | Picture upload to `images/size_bands/` (public-safe); weight ranges must not overlap within a species |
| Allotment rules | REWRITE `_legacy/viewShoppingList.php` → `allotmentRules.php` | allotment_rule | See below |
| Intake questions (US-09) | `intakeQuestions.php` | intake_question | Add, retire (sets `retired_at`, answers kept), reorder, required flag, Choice options as JSON; Coordinator allowed |
| Lookup lists | `lookupLists.php` | v2.1 `lookup_value` | Deletion, deactivation, decline and emergency reasons; referral sources; proof-of-residence types; colours; body types (per species, with picture) |

- **Allotment rules:**
  - Versioned rule sets: "Draft new version" copies the version in force; "Publish" sets `effective_from` and closes the old version (`effective_to`). Only one version is in force per date.
  - There must be a rule for every active species × size band, or publishing is blocked.
  - Show worked examples.
  - The legacy jsPDF pick-list code is reused only as reference.
- **Data:** `database/ReferenceRepository.php` (site, device, language, species, breed, size_band, lookup_value, service_area_postal_code), `AllotmentRuleRepository.php`, `IntakeRepository.php`, and the core SettingsRepository. `domain/AllotmentRuleService.php`.
- **Seed files:** `sql/seed/` holds R1 data (sites, size bands, allotment v1, lookups, policy texts EN/ES), loaded by `tools/seed.php`.
- **Acceptance:**
  - Publishing a new allotment version changes new distributions' `allotment_rule_version` without altering past rows.
  - A retired question disappears from the form but its answers remain.
  - A settings edit shows up in audit_field_change.
  - A deregistered device loses PIN switching.

### M3 User accounts, UC-11 core (M, 5–6 d)
- **Covers:** UC-11 base flow, §3.2.1–3.2.3, §3.3.1–3.3.5. Roster import is in P3, access review in P2.
- **Pages:**
  - ADAPT: `_legacy/viewAuditUsers.php` → `userList.php`.
  - ADAPT: `_legacy/createUser.php` → `userCreate.php` (the password field becomes invite-only; there is no typed password).
  - ADAPT: `_legacy/viewModifyUser.php` → `userEdit.php`, absorbing `_legacy/resetPassword.php` (reset, unlock, temporary credential) and standing site-access grants.
  - NEW: `userCredentialPrint.php` (UC-11 §3.3.5).
- **Data:** UserRepository, TokenRepository, SessionRepository. `domain/UserAdminService.php`.
- **Rules:**
  - Email must be unused; if it exists, offer to reactivate or edit.
  - The role/site combination is checked: `volunteer_max_sites`.
  - An Admin cannot change their own role or sites.
  - At least one active Administrator must always remain.
  - A temporary credential is an auth_token 'Temporary Credential', valid 72 h (`temp_credential_hours`) and single-use, and sets `must_change_password=1`. It is emailed via `mailer()` or printed.
  - A role, site or status change ends the user's sessions (end_reason 'Permission Change').
  - Deactivation keeps all historical attribution.
  - Every action is audited.
- **Acceptance:**
  - Demoting the last Admin is refused.
  - A role change logs the user out on their next request.
  - An invite works once within 72 h.
  - A duplicate email shows the existing account.

### M4 Catalogue, barcodes, stock receipts, counts (M, 7–9 d)
- **Covers:** US-16 (barcode linking; scanning itself is in M8) and the admin screens for stock receiving and counting that UC-06 and UC-14 §4.5 need.
- **Pages:**
  - `_legacy/viewItemCategories.php` → `catalogue.php` (categories and products by status).
  - `_legacy/viewAddItemCategory.php` + `viewModifyItemCategory.php` → `productEdit.php` (species, food_form, `unit_weight_lbs` required for Dry and Wet, brand, barcodes) and `categoryEdit.php`.
  - `_legacy/viewManagePallets.php` → `stockReceipts.php`.
  - `_legacy/viewAddPallet.php` + `viewModifyPallet.php` → `stockReceiptEdit.php`.
  - `_legacy/viewUpdateInventory.php` + `editInventoryEvent.php` → `inventoryCount.php`.
  - `_legacy/deleteInventoryEvent.php` → `inventoryCountVoid.php`.
  - `_legacy/inventory.php` → `stockOnHand.php` (site_stock plus a ledger drill-down).
- **Data:** `database/CatalogueRepository.php` (item_category, product, product_barcode), `InventoryRepository.php` (site_stock, inventory_transaction, stock_receipt(+_line), inventory_count(+_line)). `domain/InventoryService.php`.
- **Rules:**
  - Every stock movement is one inventory_transaction plus a site_stock upsert in the same `tx()`.
  - Receipt: txn_type 'Receipt', +qty, `receipt_line_id`. The name is auto-generated (`RCPT-<site>-<date>-<n>`) because it is globally unique.
  - Receipts and counts have no status column. "Posted" is derived from whether ledger rows exist. A void is a 'Reversal' transaction referencing the line, never a delete.
  - Count posting:
    - Refused while an event is Open at the site.
    - Lock site_stock rows `FOR UPDATE`.
    - Write 'Count Adjustment' = counted − on_hand for each line, then set on_hand.
  - Barcodes are global (no site). Linking an unknown barcode needs `barcode.link`.
  - The opening balance is an 'Opening stock' receipt; no legacy data is loaded.
- **Acceptance:**
  - Σ ledger = on_hand for every site and product after any sequence of receipt, count, void and distribution.
  - Voiding a posted receipt restores the prior on_hand.
  - A barcode linked at site A is recognised at site B.

### M5 Participants: search, register, update, alerts (XL, 14–17 d)
- **Covers:**
  - UC-02, except §3.3.3 (offline, in M8).
  - UC-03, except §3.2.3 (offline, in M8).
  - UC-04 base flow, §3.2.1–3.2.3, §3.3.1–3.3.4. Merge and version restore are in P2.
  - UC-18 View Participant Alerts, UC-19 Remove Participant Alert, and the "Create Alert", "Flag/Unflag" and "Detect Duplicate" includes.
  - US-05, US-06, US-08, and the form side of US-09.
- **Pages:**
  - ADAPT: `_legacy/personSearch.php` → `participantSearch.php`. Keep the GET-filter-with-remembered-values pattern; drop Tailwind.
  - REWRITE: `_legacy/registrationForm.php` → `participantRegister.php`.
  - NEW: `participantView.php`, `participantEdit.php`, `participantCard.php`, `registrationDrafts.php`, `participantAlert.php` (POST: flag or resolve), `api/participantSearch.php`.
- **Data:**
  - `database/ParticipantRepository.php` (participant, participant_site, participant_consent, participant_proxy, intake_answer), `AlertRepository.php`, `DraftRepository.php`.
  - `domain/ParticipantService.php`, `DuplicateDetector.php`, `NameKey.php` (accent fold via intl Transliterator or iconv, plus `metaphone()` → `surname_phonetic`), `ServiceArea.php`, `AlertService.php`, `ParticipantStatus.php` (recalculate last and next eligible dates, YTD, current allotment), `validation/ParticipantValidator.php`.
- **Tables:** participant, participant_site, participant_consent, participant_alert, registration_draft, intake_question, intake_answer, service_area_postal_code, referred_out_applicant, policy_document, language, audit_log, audit_field_change, v2.1 code_sequence.
- **Rules:**
  - **Search:**
    - Scoped to the user's current sites through participant_site (Admins see all sites).
    - Ranking: exact code > exact name (legal or preferred) > prefix > `surname_phonetic` match > partial name, address or phone.
    - Capped at `search_max_results`, with a truncation warning.
    - Empty query: households served at this site in the last `recent_participants_days`.
    - Filters: status, site, last-distribution range. Admins also get "include deleted".
    - Results show the minimum fields: preferred then legal name, code, status, pet count, last distribution, flagged badge. No address, no export.
    - A card QR scan (keyboard wedge or camera) opens the record directly.
    - With an event Open, checked-in households are listed first (US-04 view; data from M7).
  - **US-06:** the portable collation `utf8mb4_unicode_520_ci` gives accent- and case-insensitive equality. At registration, warn when a candidate name is collation-equal but differs in binary, i.e. only by accents.
  - **Duplicate detection (UC-03 §3.3.1).** The same detector is used by import and sync. Candidates are scored on:
    - same normalised phone;
    - same postal_code plus a similar street;
    - same `surname_phonetic` plus the same first initial or DOB;
    - collation-equal legal names.

    Show matched fields and the last distribution date. The volunteer either opens the existing record or confirms a distinct person with a justification. The latter stores a 'Duplicate Candidate' alert, resolved with that justification. The search is global, so §3.2.4 "registered at another site" becomes "add this site": a participant_site row with `is_transfer=1`, audited.
  - **Service area:** if the normalised postal code isn't active, refuse Active status. Either record a referred-out entry (referred_out_applicant: postal code and pantry only, no participant row) or have an Admin override on the spot with a step-up PIN (`area_override_reason` and `area_override_by`).
  - **Create:**
    - `participant_code` comes from v2.1 code_sequence inside the transaction, so no code is used up if the save fails. Format `CHS-000123` plus a mod-10 check digit.
    - participant_site row; consent row with `document_id` of the Programme Consent in the preferred language and `consenting_person`.
    - Intake answers (US-09).
    - `declined_fields` for optional items the participant declined.
    - Stamp the registration fields (who, site, device, time); they can never be edited afterwards.
    - Hand over to M6 for each pet, then recalculate the allotment, then print the card.
  - **US-08 drafts:** save without validation into `registration_draft.form_data` (source 'Volunteer', `expires_at = now + volunteer_draft_expiry_days`). Drafts are listed with their age on `registrationDrafts.php` and on the home page. Drafts are never searchable and can never be distributed to.
  - **US-05:** `preferred_name` is shown first everywhere and used on receipts, vouchers and the card.
  - **UC-04 update:**
    - Optimistic lock: `UPDATE … WHERE participant_id=? AND row_version=?` with +1. On conflict, show the competing changes field by field and offer reload, overwrite selected fields, or abandon.
    - Status, flags and site assignment need `participant.edit_restricted`. A non-Admin's change to those is rejected, the permitted changes are applied, and the refusal is audited.
    - One audit_field_change row per changed field.
    - An address change re-runs the service-area check and proposes the site mapped to the postal code.
    - Deactivation (Admin) takes a reason from the lookup list.
  - **Alerts (UC-18 and UC-19):**
    - "Flagged" = an unresolved alert with `blocks_distribution=1`.
    - Admins create 'Distribution Restriction' or 'Eligibility Flag' alerts.
    - Resolving needs `alert.resolve` and a resolution text, and is audited.
    - Prompt alerts ('Vaccination Due' and others) are raised idempotently by `AlertService::refreshPrompts($pid)` at view and check-in, and nightly by cron.
- **Acceptance:**
  - "Jose" finds "José" and vice versa.
  - A phonetic variant surname is found.
  - Search returns in ≤ 2 s over 20k seeded participants.
  - A volunteer never sees another site's participants.
  - A failed save uses up no code.
  - An out-of-area postcode creates no participant row, only a referred-out row.
  - A concurrent edit shows the conflict screen.
  - A card scan opens the record.

### M6 Pets, allotment calculation, prompts (M-L, 7–9 d)
- **Covers:**
  - UC-05 base flow, §3.2.1, §3.2.2, §3.3.1–3.3.4. Transfer and photo are in P2.
  - US-13, US-14, US-26 (Must).
- **Pages:**
  - NEW `petEdit.php`: in participant context; size-band and body-type picture picker; weight suggests a band; breed from the list or free text.
  - NEW partial `include/partials/sizePicker.php`.
- **Data:** `database/PetRepository.php` (pet, pet_household_history). `domain/PetService.php`, `AllotmentCalculator.php`, `SnvEligibility.php`, `validation/PetValidator.php`.
- **Tables:** pet, pet_household_history, species, breed, size_band, allotment_rule, lookup_value (body_type, colour), clinic (for the altered clinic and low-cost vaccination list), participant (`current_allotment_lbs`), participant_alert, snv_referral, snv_referral_status_log, snv_followup, audit_log, audit_field_change.
- **Rules:**
  - **Allotment:**
    - `entitled = Σ lbs_per_distribution` over the household's active, non-deleted pets, using the rule for (species, size_band) in the version in force on the site-local date.
    - If one species and band has both Dry and Wet rows, the entitlement is split per form. 'Any' means the forms are combined.
    - Treat and Other products don't count against the allotment (open question; see §9).
    - A missing rule is a hard configuration error shown to the Coordinator; it never silently under-allots.
    - Written to `current_allotment_lbs` in the same transaction as any pet change.
  - **Household pet limit:** active pets ≥ `household_pet_limit` blocks the save. An Admin (`pet.limit_override`) can override with a step-up and a reason (`limit_override_reason`); a non-null reason is the exception flag.
  - **Microchip:**
    - Digits only; length 9, 10 or 15.
    - Pre-check `active_microchip`. On conflict, show only the pet name and species of the other household, no contact details. Offer: correct the number, refer to an Admin (`notify()`), or transfer (P2).
    - The database unique key is the final guard against races.
  - **Altered:** marking a pet altered with date and clinic, or "elsewhere" text, closes any open referral as status Completed with outcome 'Completed Elsewhere' (UC-05 §3.2.1). The status change is logged in snv_referral_status_log.
  - **Inactive** (Deceased, Rehomed, Lost, Surrendered), with a date:
    - Void any open referral and release its budget.
    - Resolve the pet's open prompts and followups.
    - Quietly recalculate the allotment.
    - For US-26, `AlertService` never raises Vaccination, SNV-offer or Size-band prompts for inactive pets.
    - Inactive pets stay in history. Admins see them in full; volunteers see a collapsed "inactive pets (n)" line.
  - **US-14:**
    - `rabies_expires_on` ≤ today + `vaccination_warning_days` raises a 'Vaccination Due' prompt (non-blocking) that lists clinics with `offers_low_cost_vaccination=1`.
    - A NULL date shows "unknown", not "expired".
  - **Other:**
    - Optimistic lock on `pet.row_version`.
    - Audit field changes.
    - After saving an unaltered pet, show the SNV eligibility hint (UC-05 step 13).
- **Acceptance:**
  - Adding a large dog changes the allotment by exactly that rule's pounds.
  - The seventh pet is refused without an override.
  - A duplicate active chip is refused.
  - Marking a pet deceased removes its prompts, voids its referral (budget released) and recalculates the allotment without any on-screen notice beyond the saved confirmation.

### M7 Distribution events and check-in queue (M, 5–6 d)
- **Covers:** event scheduling and open/close (no use case describes these), US-04, and the event_check_in outcomes used by UC-06 §3.3.3 and US-38 later.
- **Pages:**
  - ADAPT: `_legacy/calendar.php` → `events.php` (month and list view per site).
  - REWRITE: `_legacy/addEvent.php` + `editEvent.php` → `eventEdit.php` (single or weekly-recurring creation; Open/Close actions).
  - REWRITE: `_legacy/viewCheckInOut.php` → `checkIn.php`.
  - REWRITE: `_legacy/processCheckIn.php` → `api/checkIn.php`.
  - NEW: `api/checkInQueue.php` (polled every 15 s).
- **Data:** `database/EventRepository.php` (distribution_event, event_check_in). `domain/EventService.php`.
- **Rules:**
  - Open only on `event_date` (site-local), unless a Coordinator overrides. At most one Open event per site.
  - On Close:
    - Warn if devices report unsynced commands (M8 heartbeat).
    - Set Waiting check-ins to 'Left Unserved' with `outcome_at`.
    - Audit.
  - Check-in:
    - Unique per event and participant.
    - Preview eligibility (due or not due, flagged).
    - Outcomes: Served (set by M8), Left Unserved, No Stock (with the v2.1 `unmet_detail`), Reserve List (only if a next event exists; otherwise No Stock).
  - US-04: the queue sits at the top of search and the station, ordered by `checked_in_at`, showing wait time. Typing in the search box reverts to normal search.
- **Acceptance:**
  - A second check-in of the same household is refused.
  - Closing an event leaves no Waiting rows.
  - The queue refreshes within 15 s on a second tablet.

### M8 Distribution station, Record Distribution, offline mode (XXL, 22–28 d; highest risk, on the critical path)
- **Covers:**
  - UC-06 in full: base flow, §3.2.1–3.2.5, §3.3.1–3.3.5, §4.
  - US-16 (scan), US-17, US-18 (Must).
  - Offline: UC-01 §3.3.3, UC-02 §3.3.3, UC-03 §3.2.3 and §3.3.4, UC-05 §3.3.4, UC-06 §3.2.5 and §4.3, and "keep in-progress entry" (UC-01 §4.2).
- **Design decision: one write path.** Record Distribution exists only as the station. It is a JS app shell in which every write becomes a queued command, and the queue is flushed immediately when online. This means:
  - the online and offline code paths are the same;
  - offline is exercised at every event;
  - there is no second, server-rendered distribution form to keep in step.
- **Pages and files:**
  - NEW `station.php`: the shell, with views for offline sign-in, check-in queue, search, participant panel, distribute, provisional registration, quick pet add or edit, local event view, and sync status.
  - `js/app/station/{app,store,crypto,queue,sync,scanner,receipt,offlineAuth,search}.js`.
  - `sw.js` and `manifest.webmanifest` at the **web root**, so the service worker's scope covers the root-level pages. It still works under the XAMPP subdirectory because registration uses a relative path.
  - `offline.html`.
  - `api/stationPack.php`, `api/sync.php`, `api/heartbeat.php`, `api/productByBarcode.php`, `api/eventDashboard.php`.
  - `receipt.php` (server reprint).
  - `distributionView.php` (the "View Distribution Details" include, shared with M9).
  - `distributionReverse.php` (Coordinator or Admin).
  - `notifications.php`: minimal work queue on the v2.1 notification table; enriched in P2.
  - REWRITE: `_legacy/event.php` → `eventDashboard.php` (US-18).
- **Data:**
  - `database/DistributionRepository.php` (distribution, distribution_line, distribution_pet), the InventoryRepository from M4, `SyncCommandRepository.php` (v2.1 sync_command), `NotificationRepository.php`.
  - `domain/DistributionService.php`, `EligibilityService.php`, `SyncService.php` (dispatches command types to the M5, M6 and M7 services).
- **Tables:**
  - distribution, distribution_line, distribution_pet, inventory_transaction, site_stock, participant, participant_proxy, participant_alert, event_check_in, snv_followup, product, product_barcode.
  - v2.1: sync_command, notification, and new columns on participant, pet and distribution.
- **`DistributionService::record(cmd)`: one `tx()`**
  1. `SELECT … FROM participant WHERE participant_id=? FOR UPDATE`. This serialises concurrent issues to the same household. A Merged participant is re-pointed to `merged_into_id`.
  2. Checks:
     - The event is Open and its site is the device's site.
     - Participant status is Active and there is no blocking alert.
     - **Frequency rule:** `site_today() ≥ next_eligible_date`, or NULL.
     - **Duplicate-distribution check:** an existing non-reversed row for the same participant and `local_date` at this site requires `second_issue_reason`.
     - **Allotment:** Σ over Issued lines of `qty × unit_weight_lbs` ≤ `AllotmentCalculator` entitlement.

     Each failure can be overridden only with `distribution.override`. On the shared tablet the authoriser gives a step-up PIN in a modal, or the volunteer enters a pre-approved `authorization_ref`. The override is stored in `is_emergency` plus `emergency_reason`, or `is_over_allotment` plus `override_reason`, with `authorized_by`.
  3. For each Issued line: the product is active and its species matches a served pet. Then `UPDATE site_stock SET quantity_on_hand=quantity_on_hand-? WHERE site_id=? AND product_id=? AND quantity_on_hand>=?`. Zero rows updated means there isn't enough stock (the rule for offline replay is below).
  4. Inserts:
     - The distribution row, stamping `local_date` (site time zone), `entitled_lbs`, `allotment_rule_version`, `frequency_days_applied`, `pets_served`, `proxy_id`, `check_in_id`, `device_id`, `client_uuid`, `receipt_sent_via`, `sync_status`.
     - distribution_pet rows for the pets present.
     - distribution_line rows: Issued, Declined with `decline_reason`, Shortfall, and substitutes with `substitutes_line_id` (US-17). Each copies `unit_weight_lbs` at issue.
     - One inventory_transaction ('Distribution', −qty, `distribution_line_id`) per Issued line.
  5. `ParticipantStatus::recalculate()`: last date, `next_eligible_date = local_date + frequency_days_applied`, programme-year YTD, `row_version + 1`. Set `pet.last_confirmed_present` for the pets present.
  6. Set the check-in outcome to Served. For an SNV offer the participant defers (UC-06 §3.2.4), write an 'Offer Deferred' followup; referral itself is online-only in M10.
  7. Audit, then commit. Any exception rolls back everything, including the stock decrement (§3.3.5).
- **Proxy (§3.2.1):** pick an active proxy, or add one inline with `authorized_via='Phone'`.
- **No stock (§3.3.3):** no distribution row is written. The check-in outcome is No Stock with `unmet_detail`, or Reserve List on the next event.
- **Reversal instead of edit:** `distributionReverse.php` inserts a new row with `reverses_distribution_id` and `reversal_reason`, negative mirror lines and 'Reversal' inventory transactions (stock restored), then recalculates the participant's status.
  - A row can be reversed once; a reversal cannot itself be reversed.
  - distribution rows are never UPDATEd or DELETEd.
  - Reports exclude both the original and its reversal.
- **US-16:** a scan calls `api/productByBarcode.php`, or the pack when offline, and picks the product and unit weight. An unknown code opens a "link to product" step (`barcode.link`; queued as a command when offline). A manual pick list is always available.
- **US-17:** declines and substitutes must be the same species and within the allotment. The station shows products this household has declined at least twice (the pack carries recent declines).
- **Receipt:**
  - Shows date, site, preferred name, participant code, products and quantities, and next eligible date. Never the address.
  - Printed in the participant's language, rendered client-side so it prints offline.
  - Print and None in R1. Email only with `consent_to_contact`, as an R1 stretch. SMS is out of scope (it needs a paid gateway).
- **US-18 dashboard:**
  - Polls `api/eventDashboard.php` every 10–15 s: households served and waiting, pets served, pounds and units by product, on_hand by product.
  - Warns when the sum of `current_allotment_lbs` of Waiting households (by species and form) exceeds stock pounds.
  - Shows per-device pending-sync counts from heartbeats.
  - Offline, the station's local event view computes from the pack, the local queue and the last server snapshot, marked "as of hh:mm". Aggregating across devices while offline is impossible; say so on screen.
- **Offline design:**
  - **Pack** (`api/stationPack.php?event_id`, with ETag and delta refresh every 5 minutes while online):
    - event, site and settings in force; the active allotment version, size bands, species, lookups, and postal codes;
    - products, barcodes and a stock snapshot;
    - participants linked to the site who were served within `offline_cache_days` or checked in today, with minimal fields only: code, legal and preferred names, postal code, phone last 4, status, blocking alerts, next eligible date, allotment, pets (id, name, species, band, status, altered and SNV flags), proxies, recent declines.
    - No address, email or DOB.
  - **Encryption:**
    - A random AES-GCM data key (DEK) per device and site.
    - At each online sign-in on a registered device, the DEK is wrapped with a key-encryption key derived by PBKDF2 (≥ 310k iterations) from that user's password and, separately, PIN.
    - Offline sign-in means unwrapping the DEK: success proves the credential, and no password hash is stored on the device.
    - Call `navigator.storage.persist()` and install the PWA to the home screen; iOS evicts storage for non-installed sites.
    - Wipe on: device deregistration, pack age > `offline_cache_days`, or explicit logout of the last user.
  - **Offline restricted session (UC-01 §3.3.3):**
    - At online sign-in the server issues an HMAC-signed offline grant (user, device, site, capabilities `{distribute, checkin, register_provisional, pet_save, search}`, expiry `offline_grant_hours`), stored inside the encrypted store.
    - Every queued command carries it. On sync the server verifies the HMAC and that the account was not deactivated before the command's recorded time, then records a user_session with `auth_method='Offline'`.
    - Scope conflict: UC-01 limits offline sessions to distributions, but UC-03 and UC-05 allow provisional registration and pet saves. This plan allows all four. Confirm with the client.
  - **Commands:** `{client_uuid, type, payload, recorded_at_device, seq, device_id, user_id, grant}`.
    - Types: `distribution.record`, `participant.provisional`, `pet.save`, `checkin.create`, `checkin.outcome`, `barcode.link`, `snv.defer`.
    - Replayed strictly in `seq` order.
  - **`api/sync.php` is idempotent:**
    - Look up sync_command by `client_uuid`. If it exists, return the stored result; otherwise dispatch to the domain service in its own `tx()` and store the result.
    - The unique keys on `distribution.client_uuid`, `participant.client_uuid` and `pet.client_uuid` are a second guard.
    - Results are Applied, Applied With Exception, or Rejected.
  - **Replay policy:**
    - The food has physically left, so frequency, allotment and duplicate-day failures found on replay are **committed and flagged**, not refused: `distribution.sync_exception` plus a notification to the Coordinator, who reverses or authorises afterwards.
    - Offline-synced distributions may take site_stock negative. That is flagged and resolved by a count.
    - Rejected only for integrity failures: an unknown product, or a participant who has been erased. Rejected commands go to the work queue and are never dropped.
    - Clock skew: if `recorded_at_device` falls outside `event_date` ± 1 day, use `event_date` and flag.
  - **Provisional registration (UC-03 §3.2.3; also covers UC-02 §3.3.3 "manually identified participant"):**
    - Minimal fields; the service-area check runs against the cached postcodes.
    - Gets a provisional code `T-<device>-<seq>`, and the offline card shows it.
    - On sync: validate, run `DuplicateDetector`, allocate the permanent code, and store `provisional_code`. Candidates raise a 'Duplicate Candidate' alert plus a notification.
    - The card is reprinted at the next visit.
    - Later commands referencing the provisional participant resolve it by `client_uuid`.
  - **Offline pet saves:** minimal fields. A microchip conflict found on sync saves the pet without the chip and raises an alert and a notification.
  - **Session timeout or PIN switch:** the in-progress distribution draft is kept encrypted per user and restored after re-authentication.
- **Acceptance (release gate):**
  - A full simulated event (≥ 60 households, 3 tablets) runs with the network cut throughout. On reconnect: zero lost, zero duplicated, replay twice is a no-op, and killing the tab mid-sync then resuming is safe.
  - Σ Issued `distribution_line` = |Σ 'Distribution' transactions| for every product.
  - A two-pet household takes ≤ 60 s of volunteer time, and each step takes ≤ 2 s online.
  - Over-allotment is refused without a step-up.
  - A second issue on the same day needs a reason.
  - A reversal restores stock and the participant's eligibility.
  - Two devices serving the same household offline results in the second being flagged.
  - The dashboard warns when demand exceeds stock.

### M9 Distribution history, UC-07 (S, 3–4 d)
- **Pages:** NEW `participantHistory.php`, `historyPrint.php`. `distributionView.php` is shared with M8.
- **Rules:**
  - Read-only.
  - Summary: count, programme-year YTD, last date, next eligible date. Page size is `history_page_size` (25), with "load more".
  - Filters (date, site, food type) recompute totals, labelled "filtered".
  - Rows at sites outside the user's scope count in the totals, but their site and detail are hidden.
  - Reversals are shown linked to the original and excluded from totals.
  - Spay/neuter referrals are merged into the timeline (UC-07 §3.2.3).
  - No history: show the registration date and "eligible immediately".
  - The print has dates, sites and quantities only: no flags, no notes, no other household.
  - Views and prints are audited.
- **Acceptance:** first page in ≤ 2 s at 200 distributions; the print contains no alert text.

### M10 Spay/neuter referral, vouchers, clinic portal (L, 11–13 d)
- **Covers:**
  - UC-08 base flow, §3.2.1, §3.2.3, §3.2.4, §3.3.1–3.3.5. The transport list (§3.2.2) is in P2.
  - US-21, US-22 (Must), US-23.
- **Pages:**
  - NEW `snvRefer.php` (in participant context; several pets at once, one voucher each, one combined instruction sheet).
  - NEW `voucherPrint.php` (HTML print; dompdf PDF for the emailed copy).
  - NEW `snvReferralView.php` (status log; staff actions Schedule, Record outcome (`outcome_source='Staff'`), Void, reconcile Reimbursement).
  - NEW `snvFollowups.php` (expiry reminders, reassess due, waiting list, re-offer, awaiting outcome).
  - NEW `clinics.php`, `clinicEdit.php` (species-rule grid; generate or rotate the portal code, shown once and stored as `password_hash`).
  - NEW `voucherBudgets.php`.
  - NEW, public: `clinicInfo.php?c=<id>` (US-21: no JavaScript, small CSS, works on a low-end phone).
  - NEW, public: `clinicPortal.php?c=<id>` (US-22).
  - NEW: `cron/run.php` plus jobs `expireVouchers`, `voucherReminders`, `expireDrafts`, `endLapsedSessions`, `purgeTokens`, `refreshPrompts`, `ytdRollover`. These replace the web-triggerable `scheduledSend.php` pattern and are CLI-only.
- **Data:** `database/SnvRepository.php` (snv_referral, snv_referral_status_log, snv_followup), `ClinicRepository.php` (clinic, clinic_species_rule), `BudgetRepository.php`. `domain/SnvService.php` (state machine), `VoucherNumber.php`.
- **Rules:**
  - **Eligibility:**
    - Pet not altered, no open (Pending or Scheduled) referral, participant Active and served recently.
    - Age and weight within the clinic's species rule. If outside, refuse with the criterion, record a 'Reassess' followup, and set `pet.snv_status='Deferred'`.
    - An expired open referral can be voided and the flow continued.
  - **Clinic list:** Active clinics accepting the species, with `current_wait_days` and capacity. A full or suspended clinic leads to proposing the next nearest; "nearest" means same city or postal prefix, because there are no coordinates.
  - **Budget:**
    - Inside `tx()`: `SELECT … FROM voucher_budget WHERE period covers today FOR UPDATE`.
    - available = amount − Σ `reserved_amount` (Pending, Scheduled) − Σ `redeemed_amount` (Completed).
    - Reserve the clinic's `voucher_rate`. Release happens by status change (Expired, Declined, Void); `reserved_amount` is kept for history but excluded.
    - Reserved value is never reported as spend.
    - Budget exhausted: refuse, add a 'Waiting List' followup in request order, and notify Admins.
  - **Voucher number:** allocated only when a voucher is issued (status Pending). 10 random Crockford base-32 characters plus a check character, so it cannot be guessed; it doubles as a portal lookup secret.
    - It is unique for the life of the programme and never reissued.
    - A declined offer (§3.2.3) has no number (v2.1 nullable), no reservation, and gets a 'Re-offer' followup.
  - **Six statuses, exactly:**
    - Created as Pending, or as Declined for an offer that is declined.
    - Pending → Scheduled.
    - Pending or Scheduled → Completed, Expired (cron, `expires_on < today`) or Void (with a reason).
    - Completed, Expired, Declined and Void are terminal.
    - Every transition writes snv_referral_status_log and audit_log.
    - `pet.snv_status` follows: Referred while open, Altered on Completed, Unaltered on Expired, Void or Declined, and Deferred while a 'Reassess' followup is open.
  - **Own vet (§3.2.4):** `referral_type='Reimbursement'` with the other provider's details and `needs_reconciliation=1`.
  - **US-23:** vouchers and the SNV Explanation print in `participant.preferred_language_code`, from the policy_document for that language. If none exists, fall back to the default language and tell the volunteer. The language used is stored in `material_language_code`.
  - **US-22 clinic portal:**
    - The clinic enters its portal code; attempts are throttled using `audit_log` Denied counts per clinic.
    - The clinic session is short (15 minutes) and scoped to that `clinic_id`.
    - A voucher lookup only matches that clinic's vouchers.
    - The clinic sees only the pet (name, species, sex, approximate age and weight) and the voucher (number, status, expiry).
    - It can record a scheduled date, redemption (`redeemed_at`, `redeemed_amount`, defaulting to the rate), `surgery_date` and outcome. These write the status log (`changed_by_clinic_id`), set the pet to altered with date and clinic, set `outcome_source='Clinic Link'`, and write `audit_log` with `user_id` NULL and the clinic in `details`.
- **Acceptance:**
  - Concurrent issues cannot overspend the budget.
  - Every referral is in exactly one of the six statuses.
  - A declined offer uses no voucher number.
  - The expiry cron releases the reservation and resets the pet's status.
  - The portal cannot see another clinic's voucher or any participant contact data.
  - A Spanish voucher prints in Spanish, and the volunteer is told when it falls back.

### M11 Import template and minimal import (M, 6–8 d)
- **Covers:**
  - US-32 (Must).
  - UC-12 base flow and §3.2.1 (dry run) for Participant and Pet only. This is also practically needed to load the existing client list before day one.
- **Pages:** NEW `importTemplate.php?type=Participant|Pet`, `importWizard.php`, `importBatches.php`.
- **Data:** `database/ImportRepository.php` (import_batch, import_mapping, import_rejected_row). `domain/ImportService.php`, `domain/import/FieldRegistry.php`. The registry is the single definition behind both the template and the mapping, and reuses ParticipantValidator, PetValidator and DuplicateDetector.
- **Rules:**
  - **Template (PhpSpreadsheet XLSX, plus CSV):** registry headings, an example row, a notes sheet (allowed values, date formats), and one `Q: <prompt>` column per non-retired intake question. An uploaded template maps automatically.
  - **Wizard:**
    - Detect encoding and delimiter, preview, map (auto or from a saved mapping), validate with the same rules as interactive entry, and check duplicates against the database and within the file.
    - Per-row decision: create or skip.
    - Commit everything in one `tx()`, stamping `record_source='Legacy'` and `import_batch_id`.
    - Rejected rows are stored encrypted with `crypto_seal()` (UC-12 §4.7). Downloading them is audited as an Export.
    - A `backup_confirmed` checkbox is required.
  - **R1 limits:** synchronous, at most `import_max_rows_sync` (5,000) rows. Rollback, continuation, updates, distributions and users come in P3.
- **Acceptance:**
  - Round trip: download the template, fill it, upload, and it maps with zero manual steps.
  - A dry run writes nothing.
  - A failure partway through leaves zero rows.
  - An imported participant is found by search and gets an allotment.

### M12 Print and document infrastructure (S, 3–4 d)
- **Composer additions:**
  - `dompdf/dompdf`: pure PHP, needs only ext-dom and ext-mbstring (both available on SiteGround PHP 8.2), renders the same HTML and print CSS, and ships DejaVu fonts for Spanish accents. Chosen over mPDF (heavier) and TCPDF (non-HTML API).
  - `chillerlan/php-qrcode` (SVG output, no GD needed).
  - `picqer/php-barcode-generator` (Code128 for cards).
- **Vendored JS:** qrcode-generator (provisional cards printed offline), html5-qrcode with native `BarcodeDetector` preferred (camera scanning), Chart.js (P3).
- **Templates:** `print/card.php` (CR80 card; the QR payload is `PFPMS:P:<code>:<card_version>`), `print/receipt.php` (80 mm thermal and A4), `print/voucher.php`, `print/historySummary.php`. `css/print.css`.
- **Acceptance:** the card QR scans into search; a reissued card version shows a warning; a voucher PDF renders ñ and é correctly.

### R1 go-live gate
1. **Seeded:**
   - sites with time zones; size bands with pictures; allotment v1 published;
   - products, barcodes and a posted opening receipt;
   - service-area postcodes; policy documents (Confidentiality EN; Consent EN and ES; SNV Explanation EN and ES);
   - clinics, species rules and portal codes; the budget; intake questions;
   - users with site access and PINs; registered tablets.
2. The M8 offline dress rehearsal passes.
3. Security:
   - every page guards before handling POST, and every POST checks CSRF;
   - `tools/check.sh` passes;
   - secrets are rotated and out of the repo.
4. A paper fallback sheet is printed per event, and Admins can enter backdated station entries (`distributed_at` = event date).

---

## 5. Phase 2: governance and operations (29–37 d)

### M13 Participant governance (L, 10–12 d)
- **Covers:** UC-09 (all), UC-04 §3.2.4 merge, UC-04 §4.4 version view and restore, US-25.
- **Pages:** NEW `participantDelete.php`, `participantRestore.php`, `participantMerge.php`, `participantVersions.php`, `erasureRequest.php`, `erasureQueue.php`, `retentionNotice.php` (printable, in the participant's language).
- **Rules:**
  - **Soft delete:**
    - Show dependencies and recommend deactivation instead.
    - Reason from the lookup list.
    - Refuse while a Pending or Scheduled referral exists.
    - Set status Deleted, `deleted_*` and `restorable_until = today + recovery_window_days`, and store `pre_delete_status` (v2.1).
    - Set the participant's active pets Inactive with `inactivated_by_delete=1` (v2.1).
    - Write an audit snapshot.
  - **Restore:** within the window only, via the include-deleted search. Restore the previous status and only the pets that the deletion set Inactive.
  - **Erasure:**
    - Status flow: Received → Approved by a **different** Admin than `requested_by` → Completed.
    - Anonymise in place in one `tx()`: names become "Erased", contact and address fields are cleared, `postal_code` becomes '00000', `is_anonymized=1`, and `anonymous_ref` is set.
    - Purge participant_proxy, participant_consent, service_note, intake_answer, participant_alert, registration_draft and import_rejected_row rows for that person, plus pet names, chips and photos.
    - Distribution and referral foreign keys stay unchanged, so reported totals don't change.
    - Open decision: redacting personal data inside `audit_log.snapshot` and `audit_field_change` needs a documented exception to immutability.
  - **Merge:**
    - Field-by-field choice of surviving values.
    - Move pets (writing pet_household_history), proxies, consents, notes, answers and open referrals to the survivor.
    - **Distributions are never updated.** History and reports resolve `merged_into_id` at query time.
    - The absorbed record becomes Merged, with an audit entry.
  - **US-25:** at deactivation, deletion or erasure, show and print the Retention Notice in the participant's preferred language; erasure is a tracked request.
- **Acceptance:**
  - Restore after the window is refused.
  - An erasure approved by its own requester is refused.
  - After erasure, reported totals are unchanged and the name appears nowhere in operational tables.

### M14 Pet governance (M, 6–8 d)
- **Covers:** UC-10 (all), UC-05 §3.2.3 transfer, UC-05 §3.2.4 photo.
- **Pages:** NEW `petDelete.php`, `petMerge.php`, `petTransfer.php`.
  - REWRITE: `_legacy/upload_encrypted_image.php` → `petPhoto.php` (compress with GD to `photo_max_mb`; encrypted at rest).
  - ADAPT: `_legacy/serve_image.php` → `serveFile.php` (serves only if the user can view that participant).
- **Rules:**
  - Delete is refused if any distribution_pet rows exist; offer Set Inactive instead.
  - Delete is refused while a Pending or Scheduled referral exists.
  - The allotment is recalculated in the same transaction, and a snapshot is audited.
  - A duplicate pet (same chip, or same name + species + DOB) is merged: its referrals move to the survivor, then it is deleted.
  - Transfer: close the old pet_household_history row, open a new one, recalculate both households.
  - A Volunteer's delete attempt creates a notification to Admins (UC-10 §3.3.3).
  - Add `limit_override_by` (v2.1).

### M15 Accounts and access (M, 5–7 d)
- **Covers:** US-02, US-28, UC-11 access-review report, UC-01 §3.2.3 trusted device.
- **Pages:**
  - REWRITE: `_legacy/checkedInVolunteers.php` → `signedInRoster.php` (active sessions at the site with sign-in time; remote sign-out writes end_reason 'Remote Sign-out' and `ended_by`).
  - NEW: `accessReview.php` (no login within `account_review_days`).
  - Time-boxed grants added to `userEdit.php`.
- **Rules (US-28):** `ends_at` defaults to the `ends_at` of today's event at that site. Lapse is enforced on every request plus by the cron job, which ends sessions with 'Permission Change'. Grants are audited.

### M16 Audit and notification work queue (M, 5–6 d)
- **Pages:** NEW `auditLog.php` (filter by user, entity, action, date; snapshot and field-diff viewer; its own export is audited). `notifications.php` gains assignment, resolution and filters.
- **Tables:** audit_log, audit_field_change, notification.

### M17 Service notes and stale-contact prompt (S, 3–4 d)
- US-12: a `participantView.php` panel with the 280-character limit, guidance against health or financial content, author and date, and retire.
- US-11: `AlertService` rule on `contact_confirmed_on` + `contact_reconfirm_days`. "Confirm unchanged" sets today's date; "defer" is allowed once per visit and kept in the session.
- Placed in P2 because contacts are all freshly confirmed at go-live, so the prompt cannot fire before 365 days.

---

## 6. Phase 3: full import and reporting (41–50 d)

### M18 Import, complete (L, 10–12 d)
- UC-12 §3.2.2–3.2.4 and §3.3.1–3.3.5, plus UC-11 §3.2.4 roster import.
- **Background validation:** a cron job `importWorker` handles up to 25 MB / 50k rows.
- **Continuation batches** re-import corrected rejected rows.
- **Update matched rows** with field-level audit.
- **Rollback**: allowed only if no imported record has been used in a distribution or referral. It is a controlled procedure limited to rows carrying that `import_batch_id`, and it is the only documented delete path for distribution rows.
- **Distribution import:** synthetic legacy events per site and date, with placeholder stamped rule values.
- **User import:** record_type 'User' (v2.1).
- **Other rules:** stored files encrypted and purged per `import_file_retention_days`; a rejection threshold; refuse during distribution hours unless the Admin has that permission.

### M19 Reporting framework and exports (L, 10–12 d)
- **Pages:**
  - ADAPT: `_legacy/generateReport.php` → `reportHub.php` (catalogue in the three UC groups, plus saved and scheduled reports).
  - NEW: `reportView.php?key=` (parameters, validation, Chart.js plus table, metric definitions, data-currency line, drill-down subject to disclosure rules, two-period compare).
  - ADAPT: `_legacy/processInventoryReport.php` → `reportExport.php` (server-side CSV via `fputcsv` to `php://output`, XLSX via PhpSpreadsheet, PDF via dompdf; footer with report, parameters and date; audit Export with actor, parameters, format and destination).
  - NEW: `savedReports.php`, `savedReportEdit.php` (schedule; recipients must be named accounts), `reportRuns.php`, `grantCommitments.php`.
  - NEW: `cron/jobs/scheduledReports.php`, rewriting the `scheduledSend.php` idea: runs due `saved_report` rows, writes `report_run` (Queued → Running → Complete or Failed, `data_current_as_of`, `retain_until`), emails via `mailer()`, and purges expired output.
- **Code:** `reports/definitions/*.php`, one class per report (key, group, parameters, query, metrics, `leavesOrg` flag). `domain/reporting/Metrics.php` holds the single definition of households, pets, pounds, visits and referrals completed. `SmallCell.php`. `Periods.php`.
- **Rules:**
  - Queries use a read-only database user (there is no replica).
  - Anything over `report_interactive_seconds` runs in the background.
  - A partial aggregate is never shown.
  - Period grouping always uses `distribution.local_date` (site-local), by month, quarter or programme year (`programme_year_start_month`).
  - Reversed distributions and their reversals are excluded; the reversal count is available separately.
  - Legacy batches are shown as a separate series.
  - Products with no unit weight are counted in units and excluded from pounds, with a prominent note.
  - **Small-cell suppression:** in anything leaving the organisation (exports, schedules, Board), counts below `small_cell_minimum` show as "<5". Apply secondary suppression so a suppressed cell cannot be back-calculated from row or column totals.
  - Identifiable output needs `reports.identifiable`, `can_extract_identifiable` and a recorded purpose. The outreach list includes only participants with `consent_to_contact=1`.

### M20 Report catalogue (XL, 16–20 d)
- **UC-14 distribution reports:**
  - totals by period, site, product and volunteer;
  - households and pets served; visits per household;
  - shortfalls and unmet requests;
  - actual vs target against grant_commitment;
  - inventory reconciliation;
  - ADAPT `_legacy/viewWeeklyReport.php` → `reportWeeklySite.php`.
- **UC-15 participant reports:** population by status, new registrations, postal code and service area, household size and pets, lapsed (`lapse_threshold_days`), flagged plus flag review, referral source, outreach list, cohort retention, geographic.
- **UC-16 pet and SNV reports:**
  - pet population, unaltered pets, referrals by status;
  - completion rate by issue cohort, with immature cohorts reported separately;
  - time to surgery; voucher spend vs budget;
  - clinic activity, with incomplete clinic reporting called out;
  - vaccination, with the share having no status stated;
  - follow-up lists.
- **US-24:** monthly deletion digest (audit_log plus `deleted_*`), highlighting deletions that could have been deactivations; delivered as a scheduled saved_report to Admins.
- **US-36:** REWRITE `_legacy/viewConsumptionRates.php` → `demandForecast.php`. Uses enrolled pets, allotment rules and the frequency rule; seasonal factors are stored in `saved_report.parameters` JSON; shows the gap against site_stock.
- **US-38:** wait time from `checked_in_at` to `distributed_at` by site, day and hour. Left-unserved is counted separately. Volunteer counts are **estimated** from overlapping user_session rows and labelled as estimates, since there is no shift table.
- **US-39:** referred-out applicants by period and postal code, with suppression.
- **US-40:** litters prevented = completed surgeries × multiplier, stating the citation (v2.1 setting) and that it is an estimate; the surgery count is always shown.

### M21 Board dashboard, US-33 (M, 4–5 d)
- NEW `boardDashboard.php`: phone layout, Board role only (`dashboard.board`), aggregates only, always suppressed.
- Reads nightly `report_run` snapshots, never live heavy queries, and shows "data current as of".
- `index.php` redirects Board users here.

---

## 7. Phase 4: Could stories and training mode (29–38 d)

| Story | Delivery | Needs |
|---|---|---|
| US-07 self-service pre-registration | Public `selfRegister.php?site=` reached by the site QR. Creates a draft with source 'Self-Service', `expires_at = now + self_service_draft_expiry_hours`. Honeypot plus rate limit. The volunteer claims the draft and records consent and proof of residence. | V2_1_011 setting |
| US-15 outgrown size band | `AlertService` rule: juvenile at the time of `weight_confirmed_on` and now older than `size_review_juvenile_months`, raising 'Size Band Review' for the next visit. Nothing changes automatically. | V2_1_011 |
| US-20 welfare prompt | Computed in the station and history from `distribution.local_date` intervals against the household's own median × `welfare_interval_factor`. Never stored; can be dismissed. | V2_1_011 |
| US-27 pets with no recent activity | `petPresenceReview.php` lists pets whose `last_confirmed_present` is older than `pet_presence_review_days`; queues a 'Pet Presence Check' alert. | V2_1_011 |
| US-29 training mode (Should) | A separate database `pfpms_training` with the same DDL plus a sample seed (`tools/reset_training_db.php`). A session flag makes core `db()` switch DSN. Red banner. Cron, reports and offline pack are disabled in training. `practice_completed_at` is written through the live connection. | Config only; no schema change |
| US-30 annual re-acknowledgement | `due_again_on = ack + reack_interval_days`. Prompted at login, then a grace period (`reack_grace_days`), then a derived "restricted" state (read-only capabilities). | V2_1_011 |
| US-34 report narrative | Versioned `report_narrative` on `reportView.php`; included in exports and scheduled mail. | — |
| US-35 threshold alerts | `metricThresholds.php` plus cron job; rate-limited by `min_alert_interval_days`. The notification row doubles as alert history. | notification (R1) |
| US-37 most-declined products | Decline rate vs times offered; separate from Shortfall and No Stock. | — |
| US-41 SNV uptake by site | Offer, acceptance and completion rates; low-count sites marked, not ranked. Declined offers are countable because `voucher_number` is nullable. | V2_1_004 (R1) |

## 8. Phase 5: blocked on schema or external decisions (20–28 d)
- **US-10 and US-19:** participant self-service via `myPantry.php` (public).
  - Uses the **new** V2_1_012 tables `participant_access_token` and `participant_change_request`. These are additive and avoid altering `auth_token.user_id NOT NULL`.
  - The link is sent to the recorded contact method.
  - A phone change applies immediately; an address change is held for volunteer confirmation.
  - The history view shows dates, sites and quantities only.
  - Access is revoked when the participant status is not Active.
- **US-31:** photographed intake sheets become drafts.
  - Needs an external OCR vendor and a privacy review (participant data goes to a third party), plus V2_1_013 (`intake_scan` table; `registration_draft.source` gains 'Scan').
  - Recommendation: descope unless the client insists.

---

## 9. v2.1 additive migrations (portable DDL: `utf8mb4_unicode_520_ci`, JSON via `JSON_EXTRACT`/`JSON_UNQUOTE`, ENUM changes as `MODIFY` with values appended and full definitions restated)

| File | Phase | Content | Unblocks |
|---|---|---|---|
| V2_1_000__schema_migration | P0 | `schema_migration(version PK, applied_at, checksum)`, used by `tools/migrate.php` | Migration tracking |
| V2_1_001__settings_r1 | R1 | `lockout_minutes`, `session_absolute_hours`, `password_min_length`, `password_max_age_days`, `temp_credential_hours`, `reset_link_minutes`, `pin_min_length`, `pin_max_attempts`, `search_max_results`, `recent_participants_days`, `history_page_size`, `photo_max_mb`, `over_allotment_auth_role`, `emergency_auth_role`, `programme_year_start_month`, `volunteer_max_sites`, `volunteer_draft_expiry_days`, `offline_grant_hours`, `offline_cache_days`, `voucher_reminder_days`, `import_max_rows_sync`, `default_language`, `org_name` | UC-01/02/05/06/07/11, offline |
| V2_1_002__offline_sync | R1 | participant: `client_uuid` UNIQUE NULL, `is_provisional`, `provisional_code` UNIQUE NULL. pet: `client_uuid` UNIQUE NULL. distribution: `sync_exception` NULL. device: `last_seen_at`, `pending_commands`. New table `sync_command(client_uuid PK, command_type, device_id, user_id, site_id, recorded_at_device, received_at, status, entity_type, entity_id, result)` | Offline (decision 4) |
| V2_1_003__code_sequence | R1 | `code_sequence(name PK, next_value)`, seeded with 'participant_code' | UC-03 "no ID used up on failure" |
| V2_1_004__voucher_nullable | R1 | `snv_referral.voucher_number` becomes NULL (the UNIQUE key allows many NULLs on both engines) | UC-08 §3.2.3, US-41 |
| V2_1_005__notification | R1 | `notification(id, recipient_user_id NULL, recipient_role NULL, site_id NULL, type, entity_type, entity_id, message, created_by, created_at, read_at, resolved_at, resolved_by)` | Sync failures, lockout alert, chip conflict, budget exhausted, pet-delete requests, US-35 history |
| V2_1_006__lookup_value | R1 | `lookup_value(id, list_key, value, species_id NULL, picture_path NULL, display_order, is_active)`, seeded | Configured reason lists (UC-09/10), colours, US-13 body types, US-17 decline reasons |
| V2_1_007__participant_extras | R1 | participant: `pets_declared` NULL, `card_version` DEFAULT 1. event_check_in: `unmet_detail` NULL | UC-03 step 6, card reissue, UC-06 §3.3.3 |
| V2_1_008__restore_support | P2 | participant: `pre_delete_status`. pet: `inactivated_by_delete`, `limit_override_by`. user_account: `row_version` | UC-09 §3.2.4, UC-05 §3.3.3, UC-11 |
| V2_1_009__import_ext | P3 | `record_type` gains 'User' (batch and mapping); `import_batch.status` gains 'Queued' and 'Running'; new `import_staged_row`; `audit_log.import_batch_id` NULL plus index | UC-11 §3.2.4, UC-12 background and rollback |
| V2_1_010__settings_p2p3 | P2/P3 | `account_review_days`, `flag_review_days`, `trusted_device_days`, `report_max_span_months`, `report_interactive_seconds`, `report_result_retention_days`, `import_max_file_mb`, `import_max_rows`, `import_max_reject_pct`, `distribution_retention_years`, `auth_audit_retention_months`, `litters_prevented_citation` | UC-11/13/15, US-40 |
| V2_1_011__settings_p4 | P4 | `self_service_draft_expiry_hours`, `welfare_interval_factor`, `pet_presence_review_days`, `size_review_juvenile_months`, `reack_interval_days`, `reack_grace_days` | US-07/15/20/27/30 |
| V2_1_012__participant_self_service | P5 | `participant_access_token`, `participant_change_request` | US-10, US-19 |
| V2_1_013__intake_scan | P5 | `intake_scan`; `registration_draft.source` gains 'Scan' | US-31 |

**Stories blocked or partly gapped, and how they are handled:**
- **Fully blocked by missing tables:**
  - US-10 and US-19: V2_1_012 → P5.
  - US-31: V2_1_013 plus a vendor decision → P5.
- **Not a schema gap:**
  - US-29: needs a separate database, not a table → P4.
  - US-36 seasonal factors: stored in `saved_report.parameters` JSON.
  - US-38: volunteer counts estimated from user_session.
- **Partial gaps:**
  - US-13 body types: V2_1_006 → R1.
  - US-15, 20, 27, 30 and 07: settings only, V2_1_011 → P4.
  - US-40 citation: V2_1_010.
  - US-41: V2_1_004, delivered in R1.

---

## 10. Traceability

**Use cases**

| UC | Module | Phase |
|---|---|---|
| UC-01 | M1 (online), M8 (offline session) | R1 |
| UC-02 | M5 (§3.3.3 offline in M8) | R1 |
| UC-03 | M5 (§3.2.3 offline in M8) | R1 |
| UC-04 | M5 (merge and version restore in M13) | R1 / P2 |
| UC-05 | M6 (transfer and photo in M14) | R1 / P2 |
| UC-06 | M8 | R1 |
| UC-07 | M9 | R1 |
| UC-08 | M10 (transport list in P2) | R1 |
| UC-09 | M13 | P2 |
| UC-10 | M14 | P2 |
| UC-11 | M3 (roster, access review and time-boxed grants in M15/M18) | R1 / P2 / P3 |
| UC-12 | M11 minimal, M18 full | R1 / P3 |
| UC-13 to UC-16 | M19, M20 | P3 |
| UC-17 View Dashboard | `index.php` | P0, grows per module |
| UC-18 View Participant Alerts | M5 | R1 |
| UC-19 Remove Participant Alert | M5 | R1 |

**Stories**
- **R1**
  - M1: 01, 03
  - M5: 05, 06, 08
  - M2: 09 (configuration screen)
  - M6: 13, 14, 26
  - M7: 04
  - M4 and M8: 16
  - M8: 17, 18
  - M10: 21, 22, 23
  - M11: 32
- **P2**
  - M15: 02, 28
  - M17: 11, 12
  - M13: 25
- **P3**
  - M20: 24, 36, 38, 39, 40
  - M21: 33
- **P4:** 07, 15, 20, 27, 29, 30, 34, 35, 37, 41
- **P5:** 10, 19, 31

**Sizes:** R1 ≈ 100–125 dev-days (M8 22–28, M5 14–17, M10 11–13, M2 9–12, M1 7–9, M4 7–9, M6 7–9, M11 6–8, M3 5–6, M7 5–6, M9 3–4, M12 3–4). P2 ≈ 29–37, P3 ≈ 41–50, P4 ≈ 29–38, P5 ≈ 20–28. The core and the P0 cleanup are extra.

**Open decisions to raise with the client:**
- Default capability matrix: who authorises overrides, and who links barcodes.
- Offline session scope: UC-01 vs UC-03/05.
- Offline replay policy: commit and flag vs refuse, and whether stock may go negative.
- How Treat/Other products relate to the Dry/Wet/Any allotment forms.
- How a clinic "Not Performed" outcome maps onto the six statuses.
- Redacting personal data from the audit log on erasure.
- Delete Pet limited to Admins.
- Whether US-31 stays in scope.

### Critical Files for Implementation
- C:/Users/maryw/Documents/Pelican/chsPetPantry/docs/PFPMS_schema_v2.sql
- C:/Users/maryw/Documents/Pelican/chsPetPantry/index.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/header.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/personSearch.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/viewAddPallet.php
