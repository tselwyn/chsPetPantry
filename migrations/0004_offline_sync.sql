-- Migration 0004 (v2.1): offline Station sync.
-- UC-03 3.2.3 (provisional registration), UC-05 3.3.4 (queued pet saves), UC-06 3.2.5 and 4.3
-- (queued distributions synchronised without loss or duplication), UC-06 4.2 (one reversal per distribution).

ALTER TABLE `participant`
  ADD COLUMN `client_uuid` CHAR(36) NULL AFTER `participant_code`,
  ADD COLUMN `provisional_code` VARCHAR(20) NULL AFTER `client_uuid`,
  ADD UNIQUE KEY `uk_participant_client_uuid` (`client_uuid`),
  ADD UNIQUE KEY `uk_participant_provisional_code` (`provisional_code`);

ALTER TABLE `pet`
  ADD COLUMN `client_uuid` CHAR(36) NULL AFTER `pet_id`,
  ADD UNIQUE KEY `uk_pet_client_uuid` (`client_uuid`);

ALTER TABLE `event_check_in`
  ADD COLUMN `client_uuid` CHAR(36) NULL AFTER `check_in_id`,
  ADD UNIQUE KEY `uk_event_check_in_client_uuid` (`client_uuid`);

-- sync_exception lists every check a replayed offline distribution failed. The food had already
-- left, so the row is committed and flagged for Coordinator review (plan decision D10).
ALTER TABLE `distribution`
  ADD COLUMN `sync_exception` SET('Frequency','Same Day','Allotment','Stock','Flagged','Authorization','Event Closed','Clock Skew','Erased Participant','Double Serve','Participant Status') NULL AFTER `sync_status`,
  ADD UNIQUE KEY `uk_distribution_reverses` (`reverses_distribution_id`);

-- sync_item must be able to record a Held item whose authenticity check failed, so the values the
-- client claims (kind, user) are stored without constraints; recorded_by is set only once verified.
-- participant_id lets erasure (UC-09 3.2.2) find and purge payloads without decrypting them.
CREATE TABLE `sync_item` (
  `client_uuid` CHAR(36) NOT NULL,
  `device_id` INT NOT NULL,
  `client_seq` INT NOT NULL,
  `kind` VARCHAR(30) NOT NULL,
  `origin` ENUM('Online','Offline') NOT NULL DEFAULT 'Offline',
  `claimed_recorded_by` INT NULL,
  `recorded_by` INT NULL,
  `recorded_at_client` DATETIME(3) NOT NULL,
  `received_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `depends_on_uuid` CHAR(36) NULL,
  `payload_sha256` CHAR(64) NOT NULL,
  `payload_ciphertext` MEDIUMTEXT NULL,
  `status` ENUM('Accepted','Accepted With Exception','Held','Resolved','Discarded') NOT NULL,
  `reason_code` VARCHAR(40) NULL,
  `entity_type` VARCHAR(30) NULL,
  `entity_id` BIGINT NULL,
  `participant_id` INT NULL,
  `resolved_by` INT NULL,
  `resolved_at` DATETIME NULL,
  `resolution_note` VARCHAR(255) NULL,
  PRIMARY KEY (`client_uuid`),
  KEY `ix_sync_item_1` (`device_id`, `client_seq`),
  KEY `ix_sync_item_2` (`status`),
  KEY `ix_sync_item_3` (`entity_type`, `entity_id`),
  CONSTRAINT `fk_sync_item_device_id` FOREIGN KEY (`device_id`) REFERENCES `device` (`device_id`),
  CONSTRAINT `fk_sync_item_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `user_account` (`user_id`),
  CONSTRAINT `fk_sync_item_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  CONSTRAINT `fk_sync_item_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `user_account` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
