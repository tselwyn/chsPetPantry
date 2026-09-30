# PFPMS schema v2.0.1 and v2.1: changes for the ERD

`docs/PFPMS_schema_v2.sql` is the baseline, now at **v2.0.1**. Later changes are additive migration files in `migrations/`, applied by `php bin/migrate.php`. This page lists every change so the design team can fold them into `PFPMS_ERD_v2.drawio`.

**Where it has been verified:** the full set (0001–0009) loads, re-runs as a no-op, and passes `php bin/schema-check.php` on the engines below; 0010, 0011 and 0012 were verified the same way on MariaDB 10.4 and MySQL 8.0 (0012 also on MySQL 9.4):
- MariaDB 10.4.28 (XAMPP);
- MySQL 8.0.43;
- MySQL 9.4.0.

Production runs Percona/MySQL 8.4, which sits between the two MySQL versions tested. Totals after v2.1: **66 tables** (plus the tooling table `schema_version`) and **167 foreign keys**.

## v2.0.1 (edited in place in `docs/PFPMS_schema_v2.sql`; identical to `migrations/0001`)

| Change | Why |
|---|---|
| Collation `utf8mb4_0900_ai_ci` → `utf8mb4_unicode_520_ci` on all 59 tables, and `SET NAMES … COLLATE utf8mb4_unicode_520_ci` | `0900_ai_ci` does not exist on MariaDB 10.4 (the XAMPP dev database). 520 is still accent- and case-insensitive, so US-06 holds. It is also PAD SPACE: `'abc' = 'abc '`, so the application trims unique fields. |
| `pet.status` moved above the generated column `pet.active_microchip` | The generated column's expression depends on `status` |

## v2.1 migrations

**0002 `system_setting` rows.** 54 new settings, so there are 66 in total. They cover auth, PIN, offline, operations, import, reports and retention; the file has the full list with descriptions. `litters_prevented_multiplier` is now numeric only, and its citation moves to `litters_prevented_citation`. Driven by gaps in UC-01, 02, 05, 06, 07, 11, 12 and 13, and US-01, 07, 15, 20, 27, 30 and 40.

**0003 Accounts, sessions, devices** (UC-01, UC-11, US-01, US-28, US-22)
- `auth_token.purpose` gains 'Offline Grant'; new `secret_ciphertext` and `revoked_at`.
- `user_session.end_reason` gains 'Password Reset', 'Deactivated', 'PIN Switch' and 'Device Lock'.
- `device` gains:
  - `token_hash` (UNIQUE), `vault_key_ciphertext`;
  - `offline_enabled`, `storage_persisted`;
  - `last_seen_at`, `last_sync_at`, `pending_count`, `app_build`;
  - `revoked_at`, `revoked_by` (FK → `user_account`), `wipe_mode`.
- `user_account` gains `display_name`, `pin_failed_count`, `deactivation_effective_date` and `row_version`.
- `user_site_access` gets index `(user_id, site_id, ends_at)`.
- New table `rate_limit_bucket`.

**0004 Offline sync** (UC-03 §3.2.3, UC-05 §3.3.4, UC-06 §3.2.5/§4.2/§4.3)
- New `client_uuid` (UNIQUE, nullable) on `participant`, `pet` and `event_check_in`.
- `participant.provisional_code` (UNIQUE).
- `distribution.sync_exception`, a `SET(...)` of the checks an offline replay failed (including 'Participant Status' for an Inactive or Deleted household).
- `UNIQUE (distribution.reverses_distribution_id)`: at most one reversal per distribution.
- New table `sync_item`, the idempotency record for each synced item, with FKs → `device`, `user_account` ×2 and `participant`.
  - It must be able to hold a Held item whose authenticity check failed. So `kind` is VARCHAR, the user the client claims goes in `claimed_recorded_by` (no FK), and `recorded_by` is filled only once verified.
  - `participant_id` lets erasure find and purge payloads without decrypting them.

**0005 Notifications and outbox** (gap 6 in the use-case analysis)
- New table `notification`, the in-app work queue, with FKs → `user_account` ×3, `site` and `participant`.
- New table `outbound_message`, the mail outbox with an encrypted body, with FKs → `user_account` and `participant`.
- Both are covered by participant erasure.

**0006 Participant, pet and inventory**
- `participant` gains:
  - `declared_pet_count` (UC-03 step 6);
  - `card_version` (lost cards can be invalidated);
  - `status_before_delete` (UC-09 §3.2.4 restore).
- `pet` gains `is_limit_exception`, `limit_override_by` (FK) and `inactivated_by_owner_delete` (UC-05 §3.3.3, UC-09 §3.2.4).
- `inventory_count.posted_at`, used to detect when an offline distribution raced a count.
- New table `unmet_request` (UC-06 §3.3.3, UC-14), recorded against the participant and the event. The check-in is optional, because late-entry paper slips have none. FKs → `participant`, `distribution_event` ×2, `event_check_in`, `species`, `product` and `user_account`.

**0007 SNV referral**
- `snv_referral.voucher_number` and `expires_on` become NULL, so a declined offer uses up no voucher number (UC-08 §3.2.3/§4.1). The UNIQUE key stays; several NULLs are allowed.

**0008 Sequences, lookups, system account**
- New table `id_sequence`, which gives gapless participant codes (UC-03: no ID is consumed on failure).
- New table `lookup_value`, for the configurable lists (UC-05 §4.5, UC-09/10 step 5, UC-04 §3.2.3, US-13, US-17, UC-15), with an FK → `species`. It is seeded with the spec-defined delete and deactivation reasons.
- A reserved, inactive `system` user row, used as attribution for seeds and cron.

**0009 Imports and audit**
- `import_batch.record_type` and `import_mapping.record_type` gain 'User' (UC-11 §3.2.4, US-32).
- `import_batch.status` gains 'Queued' and 'Running'.
- `audit_log.import_batch_id` (FK plus index), used by batch rollback (UC-12 §3.2.4).

**0010 Organisation time zone** (UC-11 review)
- One `system_setting` row, `organisation_time_zone` (default `America/New_York`), bringing the total to 67 (verified on MariaDB 10.4 and MySQL 8.0). Organisation-wide dates belong to no single site, so "today" for them is taken in this zone rather than in UTC: account start, end and deactivation dates, policy start dates (`policy_document.effective_from`) and re-acceptance due dates (`policy_acknowledgement.due_again_on`). Site-level dates keep using `site.time_zone`. No schema change.

**0011 Allotment rule versions** (UC-05 §4.1, UC-06 §4.5, plan P2A allotment rules)
- `allotment_rule` += `published_at`, `published_by` (FK to user_account) and `created_at`; `lbs_per_distribution` becomes NULL-able; UNIQUE (`rule_version`, `species_id`, `size_band_id`, `food_form`); index on `effective_from`. FKs 165 → 166.
- A version is every row sharing one `rule_version`. `published_at IS NULL` marks the one draft; its empty cells are NULL pounds, and publishing refuses any empty cell, so published rows always have pounds.
- The version in force on a site-local date is the published version with the latest `effective_from` on or before it, as for policy texts. **`effective_to` is kept but not used** (it stays NULL): a version ends the day before the next one starts. This departs from design M2 (11-design-features.md, "Publish ... closes the old version (effective_to)"), so that a published version that has started is never updated, and a scheduled one can be taken back without touching the version in force. Code must never read `effective_to IS NULL` as "current"; use `AllotmentRuleRepository::versionInForce()`.
- `rule_version` 0 is reserved for imported legacy distributions (no rules); real versions start at 1. A version taken back before it starts is renumbered, so a published number never stands for two sets of rules.

**0012 Device registration** (plan P2A `admin_devices`; US-01, UC-01 §3.3.3, UC-06 §4.3)
- `auth_token.purpose` gains 'Device Registration': the single-use code printed on `admin_devices`, bound to the waiting device row (`device_id`) and to the person who created it (`user_id`); only its SHA-256 is stored. The installed Station redeems it in Phase 2B.
- `user_session.end_reason` gains 'Device Revoked'.
- `device` gains `reported_max_seq` (the tablet's reported highest sequence, written by the P2B heartbeat), `revoked_lost` and `revoked_max_seq` (set when a tablet is taken out of service, `revoked_lost` also when a retired tablet is reported lost later; for a lost or erased tablet the cut-off counts only what the server received, and P2B holds every item it uploads for review, so `revoked_max_seq` is a record), `erase_requested_at` and `erase_requested_by` (FK to user_account: when and by whom Erase now was chosen, which may be after the tablet was retired) and `wiped_at` (the tablet confirmed its erase, written by P2B). FKs 166 → 167.
- One setting, `device_code_minutes` (default 60), bringing the total to 68. Tables stay at 66.
- A tablet waiting for registration is a device row with `token_hash` NULL. Taking a tablet out of service keeps `token_hash` and `vault_key_ciphertext`, so P2B can still recognise it and tell it to erase itself; code must test `DeviceRepository::IN_SERVICE_SQL`, never `token_hash` or `is_site_registered` alone.

**optional/9001 Immutability triggers**
- BEFORE UPDATE and BEFORE DELETE triggers on `distribution`, `distribution_line`, `distribution_pet`, `audit_log`, `audit_field_change`, `snv_referral_status_log` and `inventory_transaction`.
- These are a development safety net only. On binlogged MySQL without SUPER (SiteGround), `CREATE TRIGGER` fails with error 1419 and the runner records the migration as Skipped. That behaviour was verified on MySQL 8.0 and 9.4.
- The real control is the app-layer allowlist.

## Not changed: recorded for the ERD team to decide

- A clinic↔site link, for "clinics available to the participant" (UC-08 §3.3.4).
- Participant and pet status history for reports over time (UC-15/16); for now it is rebuilt from `audit_field_change`.
- Vaccinations other than rabies (the "V" in SNV).
- A volunteer shift roster (US-38 estimates staffing from `user_session`).
- Aligning `allotment_rule.food_form` (Dry/Wet/Any) with `product.food_form` (Dry/Wet/Treat/Other).
- v2.2, only if the client keeps those stories: `participant_access_token` and `participant_change_request` (US-10/19), and `intake_scan` (US-31).
