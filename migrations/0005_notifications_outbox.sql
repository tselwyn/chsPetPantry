-- Migration 0005 (v2.1): in-app work queue and outbound mail.
-- notification: lockout alerts (UC-01 3.3.1), sync failures (UC-03 3.2.3), restricted changes and
-- microchip conflicts referred to an Administrator (UC-04 3.3.3, UC-05 3.3.2), budget exhausted
-- (UC-08 3.3.3), pet-delete requests (UC-10 3.3.3), background report done (UC-13 3.2.4).
-- outbound_message: reset links, invitations, receipts, vouchers, reminders, scheduled reports.
-- Both hold personal data and are covered by participant erasure (UC-09 3.2.2).

CREATE TABLE `notification` (
  `notification_id` BIGINT NOT NULL AUTO_INCREMENT,
  `recipient_user_id` INT NULL,
  `recipient_role` ENUM('Volunteer','Coordinator','Administrator','Board') NULL,
  `site_id` INT NULL,
  `kind` VARCHAR(40) NOT NULL,
  `entity_type` VARCHAR(30) NULL,
  `entity_id` BIGINT NULL,
  `participant_id` INT NULL,
  `message` VARCHAR(500) NOT NULL,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` DATETIME NULL,
  `resolved_at` DATETIME NULL,
  `resolved_by` INT NULL,
  PRIMARY KEY (`notification_id`),
  KEY `ix_notification_1` (`recipient_user_id`, `read_at`),
  KEY `ix_notification_2` (`recipient_role`, `site_id`, `resolved_at`),
  CONSTRAINT `fk_notification_recipient_user_id` FOREIGN KEY (`recipient_user_id`) REFERENCES `user_account` (`user_id`),
  CONSTRAINT `fk_notification_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  CONSTRAINT `fk_notification_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  CONSTRAINT `fk_notification_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_account` (`user_id`),
  CONSTRAINT `fk_notification_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `user_account` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `outbound_message` (
  `message_id` BIGINT NOT NULL AUTO_INCREMENT,
  `channel` ENUM('Email','SMS') NOT NULL DEFAULT 'Email',
  `recipient` VARCHAR(255) NOT NULL,
  `user_id` INT NULL,
  `participant_id` INT NULL,
  `template_key` VARCHAR(60) NOT NULL,
  `subject` VARCHAR(255) NULL,
  `body_ciphertext` MEDIUMTEXT NOT NULL,
  `status` ENUM('Queued','Sending','Sent','Failed','Cancelled') NOT NULL DEFAULT 'Queued',
  `attempts` TINYINT NOT NULL DEFAULT 0,
  `last_error` VARCHAR(255) NULL,
  `not_before` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME NULL,
  PRIMARY KEY (`message_id`),
  KEY `ix_outbound_message_1` (`status`, `not_before`),
  CONSTRAINT `fk_outbound_message_user_id` FOREIGN KEY (`user_id`) REFERENCES `user_account` (`user_id`),
  CONSTRAINT `fk_outbound_message_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
