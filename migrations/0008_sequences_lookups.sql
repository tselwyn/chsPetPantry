-- Migration 0008 (v2.1): gapless participant codes, configurable lists and the system account.
-- id_sequence: UC-03 post-condition "no Participant ID has been consumed" on failure. next_value is the
-- next code to hand out. It is taken inside the registration transaction as the last step before INSERT:
--   UPDATE id_sequence SET next_value = LAST_INSERT_ID(next_value) + 1 WHERE seq_name = 'participant';
--   SELECT LAST_INSERT_ID();   -- the value handed out (P1 first)
-- so a rollback returns it and the row lock is held only briefly.
-- lookup_value: lists the specs call "configured" (UC-05 4.5 colours, UC-09 and UC-10 step 5 delete
-- reasons, UC-04 3.2.3 deactivation reasons, US-13 body types with pictures, US-17 decline reasons,
-- UC-15 referral source and proof-of-residence groupings). Only lists the specs define are seeded here;
-- the rest are client-reviewed data in seeds/reference.

CREATE TABLE `id_sequence` (
  `seq_name` VARCHAR(40) NOT NULL,
  `prefix` VARCHAR(10) NOT NULL DEFAULT '',
  `next_value` INT NOT NULL DEFAULT 1,
  PRIMARY KEY (`seq_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

INSERT INTO `id_sequence` (`seq_name`, `prefix`, `next_value`) VALUES ('participant', 'P', 1);

CREATE TABLE `lookup_value` (
  `lookup_id` INT NOT NULL AUTO_INCREMENT,
  `list_key` VARCHAR(40) NOT NULL,
  `value_code` VARCHAR(40) NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `species_id` INT NULL,
  `picture_path` VARCHAR(255) NULL,
  `display_order` SMALLINT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`lookup_id`),
  UNIQUE KEY `uk_lookup_value_1` (`list_key`, `value_code`),
  CONSTRAINT `fk_lookup_value_species_id` FOREIGN KEY (`species_id`) REFERENCES `species` (`species_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

INSERT INTO `lookup_value` (`list_key`, `value_code`, `label`, `display_order`) VALUES
  ('participant_delete_reason', 'duplicate', 'Duplicate record', 1),
  ('participant_delete_reason', 'created_in_error', 'Created in error', 2),
  ('participant_delete_reason', 'erasure_request', 'Erasure request', 3),
  ('participant_delete_reason', 'other', 'Other (give details)', 9),
  ('pet_delete_reason', 'duplicate', 'Duplicate pet record', 1),
  ('pet_delete_reason', 'entered_in_error', 'Entered in error', 2),
  ('pet_delete_reason', 'wrong_household', 'Recorded against the wrong household', 3),
  ('pet_delete_reason', 'other', 'Other (give details)', 9),
  ('participant_deactivation_reason', 'moved_away', 'Moved away', 1),
  ('participant_deactivation_reason', 'no_pets', 'No longer owns pets', 2),
  ('participant_deactivation_reason', 'requested_removal', 'Requested removal', 3),
  ('participant_deactivation_reason', 'other', 'Other (give details)', 9);

-- Attribution for rows written by seeds and cron jobs (created_by, granted_by and similar are NOT NULL).
-- Inactive, with a password hash that can never verify, so it can never sign in.
INSERT INTO `user_account`
  (`username`, `email`, `first_name`, `last_name`, `display_name`, `role`, `status`, `password_hash`, `must_change_password`, `start_date`)
VALUES
  ('system', 'system@pfpms.invalid', 'System', 'Account', 'System', 'Volunteer', 'Inactive', '!', 0, CURRENT_DATE);
