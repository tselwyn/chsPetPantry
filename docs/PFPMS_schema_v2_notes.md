# PFPMS Database Schema v2: Design Notes

This version replaces the first-draft ERD (15 tables). It covers the 16 use cases in *PFPMS Use Case Specifications v1.0* and the 41 stories in *PFPMS User Stories Backlog v1.0*.

- **Files:** `PFPMS_ERD_v2.drawio` (one table-map page plus one page per subject area), `PFPMS_schema_v2.sql` (MySQL 8 DDL and seed settings).
- **Size:** 59 tables and 143 foreign keys.
- **Tested:** the SQL was loaded into MySQL 8.0 with no errors. Accent-insensitive search and the active-microchip unique key were both tested.

## 1. What changed from v1, and why

| v1 | v2 | Requirement driving it |
|---|---|---|
| `dbpersons` with free-text `type` | `user_account` with fixed roles, account status, lockout, expiry, PIN, onboarding and practice dates. Adds `user_site_access` (standing or time-limited), `user_session`, `device`, `auth_token` | UC-01, UC-11, US-01, US-02, US-28, US-29 |
| No sites | `site`. Almost every operational row is now scoped to a site | Site scoping in UC-02 §4.5, UC-11 |
| `participant` (15 columns) | Legal name plus preferred name, phonetic surname, contact method and consent, preferred language, declined fields, merge pointer, soft-delete and recovery columns, legacy/offline source, `row_version` for optimistic locking | UC-03, UC-04, UC-09, US-05, US-06, US-11, US-23 |
| `alert` | `participant_alert`, one table for duplicate candidates, distribution restrictions, eligibility flags, stale-contact, vaccination and size-band prompts | Jennifer Polack review, UC-03 §3.3.1, UC-06 §3.3.1, US-11, US-14, US-15 |
| `participant_status_log` | Dropped. Every change, status included, is now recorded in `audit_log` + `audit_field_change` | UC-04 field-level audit |
| `pet` (10 columns) | Adds sex, colour, body type, size band, altered status and clinic, SNV status, rabies dates, microchip, feeding restriction, photo, inactive reason, deletion columns. Adds `pet_household_history` for transfers | UC-05, UC-10, US-13, US-14, US-26 |
| `dbitemcategory` only | `item_category` → `product` (species, dry/wet, unit weight) and `product_barcode` | UC-06 Select Food Type, UC-14 pounds distributed, US-16 |
| `distribution_visit` + `distribution_item` | `distribution` (emergency, override, proxy, reversal, stamped rules, offline sync) + `distribution_line` (Issued / Declined / Shortfall) + `distribution_pet` | UC-06 §4.1–4.5, UC-10 §3.3.2, US-17, US-37 |
| `dbshoppingcounts`, `dbconsumption` | Replaced by `inventory_transaction` (stock ledger) and `site_stock` (live quantity on hand) | UC-06 §4.1 transactional integrity, UC-14 §4.5 reconciliation, US-18 |
| — | New: `event_check_in` | US-04 check-in queue, US-38 wait time, UC-06 reserve list |
| — | New spay/neuter tables: `clinic`, `clinic_species_rule`, `voucher_budget`, `snv_referral`, `snv_referral_status_log`, `snv_followup` | UC-08, UC-16, US-21, US-22 |
| — | New governance tables: `audit_log`, `audit_field_change`, `erasure_request`, `import_batch`, `import_mapping`, `import_rejected_row` | UC-09, UC-12, US-24, US-25 |
| — | New reporting tables: `saved_report`, `report_recipient`, `report_run`, `report_narrative`, `metric_threshold`, `grant_commitment` | UC-13, UC-14 §3.2.2, US-34, US-35 |
| Hard-coded rules | Configurable rules: `system_setting`, `allotment_rule` (versioned), `service_area_postal_code`, `intake_question`, `policy_document`, `language`, `species`, `breed`, `size_band` | "configurable without a code change" appears throughout the specs |
| Mixed naming (`dbpersons`, `personId` as VARCHAR(11)) | snake_case, integer FKs with real constraints, utf8mb4 | Consistency. The legacy DB had no enforced FKs |

## 2. Tables by area, with the requirements they serve

### Security & Access
- **site:** distribution locations and their local time zone (UC-14 §4.2).
- **user_account:** roles are Volunteer, Coordinator, Administrator and Board.
  - Coordinator and Board come from the backlog (US-02, US-09, US-33); the use cases only name Volunteer and Administrator.
  - `can_extract_identifiable` is the elevated permission in UC-15.
- **user_site_access:** a row with `ends_at` set is a time-limited single-shift grant (US-28).
- **device:** only site-registered devices allow PIN switching (US-01).
- **user_session:** live list of who is signed in, remote sign-out, 30-minute timeout (US-02, UC-01 §4.2).
- **auth_token:** password reset, 72-hour temporary credentials, trusted devices (UC-01 §3.2, UC-11 §4.2).
- **policy_document / policy_acknowledgement:** versioned confidentiality agreement, consent text, retention notice and SNV explanation, each per language (US-03, US-30, US-25, US-23, UC-03 §4.2).

### Participants
- **participant:** one row per household.
  - `next_eligible_date`, `last_distribution_date`, `ytd_lbs_issued` and `current_allotment_lbs` are the cached results of *Calculate/Update Participant Status*.
  - "Flagged" is not a status. A participant counts as flagged when they have an unresolved `participant_alert` with `blocks_distribution = 1`. This keeps the status values the same as UC-15 §4.6.
- **participant_site:** sites the household belongs to, including transfers (UC-03 §3.2.4).
- **participant_consent:** which consent text version was agreed, when, and by whom (UC-03 §4.2).
- **participant_proxy:** authorised collectors (UC-06 §3.2.1).
- **service_note:** 280-character service preferences, with author and retirement (US-12).
- **participant_alert:** see §1. `matched_participant_id` records the duplicate candidate. `resolution` holds the "confirmed distinct" justification.
- **registration_draft:** interrupted and self-service registrations. A draft is not a participant until it is claimed (US-07, US-08).
- **intake_question / intake_answer:** questions a coordinator can configure. Retired questions keep their answers (US-09).
- **service_area_postal_code:** the configurable service area (UC-03 §4.3).
- **referred_out_applicant:** stores only the postal code and the pantry referred to, so we keep no personal data about people we could not serve (US-39, UC-03 §3.3.3).
- **language:** languages a coordinator can maintain (US-23).

### Pets
- **pet:**
  - `active_microchip` is a generated column with a unique key, so a chip can be on only one *active* pet (UC-05 §4.2).
  - `inactive_reason = 'Deceased'` suppresses prompts about the pet (US-26).
  - `last_confirmed_present` feeds the no-recent-activity review (US-27).
- **pet_household_history:** transfers keep the historical link to the old household (UC-05 §3.2.3).
- **species / breed / size_band:** vocabularies an Administrator maintains. `size_band.picture_path` drives the picture picker (US-13).
- **allotment_rule:** versioned formula by species and size band. The version in force is stamped on each distribution (UC-05 §4.1).

### Distribution & Inventory
- **distribution_event:** a scheduled session at a site (legacy `dbshoppingevent`).
- **event_check_in:** check-in time and outcome (Served, Left Unserved, No Stock, Reserve List).
- **distribution:** immutable once committed.
  - Corrections are reversing rows via `reverses_distribution_id` (UC-06 §4.2).
  - `client_uuid` prevents duplicates when offline records sync (§4.3).
  - The frequency rule and allotment version in force are stamped on each row (§4.5).
- **distribution_pet:** which pets were counted. Stops a pet with history being hard-deleted (UC-10 §3.3.2) and gives the "pets served" figure.
- **distribution_line:**
  - Issued, Declined and Shortfall are kept separate (US-17, US-37).
  - `unit_weight_lbs` is copied at issue so pounds-distributed figures don't change when a product's weight is edited later.
- **item_category, product, product_barcode:** food catalogue and barcode scanning (US-16).
- **site_stock, inventory_transaction:** every stock movement is a ledger row, so distribution totals reconcile with inventory (UC-14 §4.5).
- **stock_receipt(+_line), inventory_count(+_line):** the legacy pallet and inventory-count features, now tied to `product` and `site`.

### Spay / Neuter
- **clinic:** partner clinic directory with hours, directions URL, capacity, voucher rate, and a portal code for the clinic-facing link (UC-08 §4.4, US-21, US-22).
- **clinic_species_rule:** species and age/weight limits each clinic accepts (UC-08 step 5).
- **voucher_budget:** funds reserved at issue and released on expiry, decline or void (UC-08 §4.2, UC-16 §3.2.1).
- **snv_referral:**
  - Uses exactly the six statuses in UC-16 §4.1.
  - `voucher_number` is unique for the life of the programme.
  - Tracks reserved vs. redeemed amounts, with the outcome reported by the clinic or by staff.
- **snv_referral_status_log:** every status change, with the actor (a user or a clinic) and a reason (UC-08 §4.6).
- **snv_followup:** reassess dates, waiting list, re-offers and expiry reminders.
  - These are kept out of `snv_referral` so that "Deferred" never becomes a seventh referral status.

### Governance, Import & Reporting
- **audit_log + audit_field_change:** one immutable log for logins, record views, changes, deletions, exports and imports. It holds before-snapshots and field-level diffs. The monthly deletion digest (US-24) is a query over this table.
- **erasure_request:** needs a second Administrator's approval before a purge. `anonymous_ref` replaces the participant on historical rows (UC-09 §3.2.2).
- **import_batch / import_mapping / import_rejected_row:**
  - Supports dry runs, continuation batches, rollback and file-purge dates (UC-12).
  - Imported rows carry `import_batch_id` and `record_source = 'Legacy'` so reports can show them separately.
- **saved_report / report_recipient / report_run / report_narrative:**
  - Scheduled reports go to named accounts only (UC-13 §3.2.2).
  - Each run records how current its data was (§4.4).
  - Narratives are versioned (US-34).
- **metric_threshold:** rate-limited alerts on key figures (US-35). **grant_commitment:** targets for actual-vs-target reports (UC-14 §3.2.2).
- **system_setting:** frequency rule, pet limit, voucher expiry, recovery window, lapse threshold, small-cell minimum, and the US-40 litters-prevented multiplier with its citation.

## 3. Rules the application must enforce

These can't be expressed as plain constraints, so they belong in the PHP data-access layer:

- Record the distribution and decrement stock in **one transaction** (`distribution` + `distribution_line` + `inventory_transaction` + `site_stock`).
- Optimistic locking: `UPDATE … WHERE row_version = ?`, then increment `row_version` (participant, pet).
- Participants are deleted by setting `status = 'Deleted'` (soft delete). Hard purge happens only through an approved `erasure_request`.
- Never allow UPDATE or DELETE on `distribution`, `audit_log`, `audit_field_change` or `snv_referral_status_log`. Enforce this with MySQL grants for the app user.
- Duplicate-distribution check: same participant, event and day, unless `second_issue_reason` is set.
- The frequency rule, household pet limit and allotment come from `system_setting` / `allotment_rule`, never hard-coded.

## 4. Scope decisions and open questions for the client

1. **Roles:** the use cases name Volunteer and Administrator only. The backlog adds Coordinator and Board. Confirm with the client whether these are separate roles.
2. **Delete Pet:** limited to Administrators, as in Polack's review. Still to be confirmed with the client.
3. **Training mode (US-29):** intended to run against a separate sample database, so it has no table here. Only `practice_completed_at` is stored.
4. **Participant self-service (US-10, US-19) and photo intake (US-31):** both are *Could* stories and have no tables yet. `auth_token` can be extended with participant-link purposes if they are kept.
5. **Welfare prompt (US-20):** calculated when the history is viewed and never stored, since the story says it must not become a flag.
6. **Legacy tables left out:** events/attendance/applications, shifts and hours, messages, drafts, discussions, groups, suggestions, archived volunteers, verified IDs, `dbshoppingcountgroup`, `monthly_hours_snapshot`. None is needed by a PFPMS use case.
