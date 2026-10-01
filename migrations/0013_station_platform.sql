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
-- ix_sync_item_4 (device_id, recorded_by): the device pages count each tablet's authenticated records from the index alone.
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
  ADD COLUMN `recorded_at_raw` DATETIME(3) NULL AFTER `recorded_at_client`,
  ADD KEY `ix_sync_item_4` (`device_id`, `recorded_by`);

ALTER TABLE `user_session`
  MODIFY `auth_method` ENUM('Password','PIN','Offline','Offline PIN') NOT NULL,
  MODIFY `end_reason` ENUM('Logout','Timeout','Remote Sign-out','Permission Change','Password Reset','Deactivated','PIN Switch','Device Lock','Device Revoked','User Switch') NULL;
