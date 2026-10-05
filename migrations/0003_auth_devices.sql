-- Migration 0003 (v2.1): accounts, sessions, devices and rate limiting.
-- UC-01 (lockout, offline sign-in), UC-11 (concurrency, deactivation date), US-01 (PIN switching
-- on site-registered devices), US-28 (per-request grant check), US-22 and US-07 (public-page rate limits).

ALTER TABLE `auth_token`
  MODIFY `purpose` ENUM('Password Reset','Temporary Credential','Trusted Device','Offline Grant') NOT NULL,
  ADD COLUMN `secret_ciphertext` VARCHAR(255) NULL AFTER `token_hash`,
  ADD COLUMN `revoked_at` DATETIME NULL AFTER `used_at`;

ALTER TABLE `user_session`
  MODIFY `end_reason` ENUM('Logout','Timeout','Remote Sign-out','Permission Change','Password Reset','Deactivated','PIN Switch','Device Lock') NULL;

ALTER TABLE `device`
  ADD COLUMN `token_hash` CHAR(64) NULL AFTER `registered_at`,
  ADD COLUMN `vault_key_ciphertext` VARCHAR(255) NULL AFTER `token_hash`,
  ADD COLUMN `offline_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `vault_key_ciphertext`,
  ADD COLUMN `storage_persisted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `offline_enabled`,
  ADD COLUMN `last_seen_at` DATETIME NULL AFTER `storage_persisted`,
  ADD COLUMN `last_sync_at` DATETIME NULL AFTER `last_seen_at`,
  ADD COLUMN `pending_count` INT NOT NULL DEFAULT 0 AFTER `last_sync_at`,
  ADD COLUMN `app_build` VARCHAR(40) NULL AFTER `pending_count`,
  ADD COLUMN `revoked_at` DATETIME NULL AFTER `app_build`,
  ADD COLUMN `revoked_by` INT NULL AFTER `revoked_at`,
  ADD COLUMN `wipe_mode` ENUM('None','Push Then Wipe','Wipe Now') NOT NULL DEFAULT 'None' AFTER `revoked_by`,
  ADD UNIQUE KEY `uk_device_token_hash` (`token_hash`),
  ADD CONSTRAINT `fk_device_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `user_account` (`user_id`);

ALTER TABLE `user_account`
  ADD COLUMN `display_name` VARCHAR(100) NULL AFTER `last_name`,
  ADD COLUMN `pin_failed_count` TINYINT NOT NULL DEFAULT 0 AFTER `pin_hash`,
  ADD COLUMN `deactivation_effective_date` DATE NULL AFTER `deactivated_reason`,
  ADD COLUMN `row_version` INT NOT NULL DEFAULT 1 AFTER `created_at`;

ALTER TABLE `user_site_access`
  ADD KEY `ix_user_site_access_1` (`user_id`, `site_id`, `ends_at`);

CREATE TABLE `rate_limit_bucket` (
  `bucket` VARCHAR(128) NOT NULL,
  `window_start` DATETIME NOT NULL,
  `hits` INT NOT NULL DEFAULT 0,
  `blocked_until` DATETIME NULL,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
