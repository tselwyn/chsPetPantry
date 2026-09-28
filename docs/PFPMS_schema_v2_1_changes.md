# PFPMS schema v2.0.1 and v2.1: changes for the ERD

`docs/PFPMS_schema_v2.sql` is the baseline, now at **v2.0.1**. Later changes are additive migration files in `migrations/`, applied by `php bin/migrate.php`. This page lists every change so the design team can fold them into `PFPMS_ERD_v2.drawio`.

**Where it has been verified:** the full set (0001–0009) loads, re-runs as a no-op, and passes `php bin/schema-check.php` on:
- MariaDB 10.4.28 (XAMPP);
- MySQL 8.0.43;
- MySQL 9.4.0.

Production runs Percona/MySQL 8.4, which sits between the two MySQL versions tested. Totals after v2.1: **66 tables** (plus the tooling table `schema_version`) and **165 foreign keys**.

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
