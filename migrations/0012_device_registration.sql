-- Migration 0012 (v2.1): tablet registration codes and taking tablets out of service
-- (plan P2A admin_devices; US-01 PIN switching only on site-registered devices; UC-01 §3.3.3
-- offline sign-in; UC-06 §4.3 no loss of records when a tablet is revoked).
-- A tablet is added on admin_devices as a device row waiting for registration (token_hash NULL).
-- Its single-use registration code is an auth_token row, purpose 'Device Registration', bound to
-- that row (device_id) and to the person who created it (user_id); only its SHA-256 is stored.
-- The installed Station redeems it in Phase 2B (api/device/register.php) for the device credential.
-- Retiring or erasing a tablet ends its open sessions with end_reason 'Device Revoked'.
-- reported_max_seq: the highest client_seq the tablet has reported (written by the P2B heartbeat).
-- revoked_lost, revoked_max_seq: set when the tablet is taken out of service (revoked_lost also when
-- a retired tablet is later reported lost). For a tablet reported lost or stolen, or erased, the
-- cut-off counts only what the server received, never what the tablet reports; P2B holds every item
-- such a tablet uploads for review, and revoked_max_seq records how far the server had received.
-- erase_requested_at, erase_requested_by: when and by whom Erase now was chosen (it may come after
-- the tablet was retired, which keeps revoked_at and revoked_by).
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
  ADD COLUMN `wiped_at` DATETIME NULL AFTER `wipe_mode`,
  ADD COLUMN `erase_requested_at` DATETIME NULL AFTER `wipe_mode`,
  ADD COLUMN `erase_requested_by` INT NULL AFTER `erase_requested_at`,
  ADD CONSTRAINT `fk_device_erase_requested_by` FOREIGN KEY (`erase_requested_by`) REFERENCES `user_account` (`user_id`);

INSERT INTO `system_setting` (`setting_key`, `setting_value`, `description`) VALUES
  ('device_code_minutes', '60', 'A tablet registration code created on admin_devices works once, for this many minutes (plan P2A admin_devices)');
