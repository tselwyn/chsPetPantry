-- Migration 0001: PFPMS schema v2.0.1 baseline.
-- Generated from docs/PFPMS_schema_v2.sql with its DROP TABLE lines removed; bin/schema-check.php
-- asserts the two stay identical. Never edit an applied migration: add a new one.

-- PFPMS database schema v2.0.1 (MariaDB 10.4+ and MySQL 8.0+)
-- Generated from the PFPMS ERD model. Derived from chsPetPantry foodpantrydb.sql,
-- PFPMS Use Case Specifications v1.0 and User Stories Backlog v1.0.
-- Collation utf8mb4_unicode_520_ci makes name search accent- and case-insensitive (US-06)
-- and exists on both MariaDB and MySQL.
-- v2.0.1 changes from v2: every table now uses utf8mb4_unicode_520_ci instead of the
-- MySQL-8-only "0900 AI CI" collation, which MariaDB 10.4 lacks; pet.status moved above the
-- generated pet.active_microchip column that depends on it. No other change. Later additive
-- changes are in migrations/ and docs/PFPMS_schema_v2_1_changes.md.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci;
SET FOREIGN_KEY_CHECKS = 0;


-- ======================================================================
-- Security & Access
-- ======================================================================

CREATE TABLE `site` (
  `site_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `street_address` VARCHAR(100) NULL,
  `city` VARCHAR(50) NULL,
  `state` CHAR(2) NULL,
  `postal_code` VARCHAR(10) NULL,
  `time_zone` VARCHAR(40) NOT NULL DEFAULT 'America/New_York',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`site_id`),
  UNIQUE KEY `uk_site_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `user_account` (
  `user_id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `first_name` VARCHAR(50) NOT NULL,
  `last_name` VARCHAR(50) NOT NULL,
  `phone` VARCHAR(15) NULL,
  `role` ENUM('Volunteer','Coordinator','Administrator','Board') NOT NULL DEFAULT 'Volunteer',
  `status` ENUM('Pending','Active','Inactive','Locked') NOT NULL DEFAULT 'Pending',
  `password_hash` VARCHAR(255) NOT NULL,
  `pin_hash` VARCHAR(255) NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 1,
  `password_changed_at` DATETIME NULL,
  `failed_login_count` TINYINT NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL,
  `start_date` DATE NOT NULL,
  `expiry_date` DATE NULL,
  `onboarding_completed_at` DATETIME NULL,
  `practice_completed_at` DATETIME NULL,
  `can_extract_identifiable` TINYINT(1) NOT NULL DEFAULT 0,
  `notification_prefs` JSON NULL,
  `last_login_at` DATETIME NULL,
  `deactivated_reason` VARCHAR(255) NULL,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uk_user_account_username` (`username`),
  UNIQUE KEY `uk_user_account_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `user_site_access` (
  `access_id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `site_id` INT NOT NULL,
  `starts_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ends_at` DATETIME NULL,
  `grant_reason` VARCHAR(255) NULL,
  `granted_by` INT NOT NULL,
  PRIMARY KEY (`access_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `device` (
  `device_id` INT NOT NULL AUTO_INCREMENT,
  `site_id` INT NULL,
  `label` VARCHAR(50) NOT NULL,
  `is_site_registered` TINYINT(1) NOT NULL DEFAULT 0,
  `registered_by` INT NULL,
  `registered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `user_session` (
  `session_id` CHAR(64) NOT NULL,
  `user_id` INT NOT NULL,
  `device_id` INT NULL,
  `site_id` INT NULL,
  `auth_method` ENUM('Password','PIN','Offline') NOT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` DATETIME NULL,
  `end_reason` ENUM('Logout','Timeout','Remote Sign-out','Permission Change') NULL,
  `ended_by` INT NULL,
  PRIMARY KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `auth_token` (
  `token_id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `purpose` ENUM('Password Reset','Temporary Credential','Trusted Device') NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `device_id` INT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uk_auth_token_token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `policy_document` (
  `document_id` INT NOT NULL AUTO_INCREMENT,
  `doc_type` ENUM('Confidentiality Agreement','Programme Consent','Retention Notice','SNV Explanation') NOT NULL,
  `version` VARCHAR(10) NOT NULL,
  `language_code` VARCHAR(10) NOT NULL,
  `body` TEXT NOT NULL,
  `effective_from` DATE NOT NULL,
  PRIMARY KEY (`document_id`),
  UNIQUE KEY `uk_policy_document_1` (`doc_type`, `version`, `language_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `policy_acknowledgement` (
  `ack_id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `document_id` INT NOT NULL,
  `acknowledged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `due_again_on` DATE NULL,
  PRIMARY KEY (`ack_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ======================================================================
-- Participants
-- ======================================================================

CREATE TABLE `language` (
  `language_code` VARCHAR(10) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`language_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `participant` (
  `participant_id` INT NOT NULL AUTO_INCREMENT,
  `participant_code` VARCHAR(20) NOT NULL,
  `legal_first_name` VARCHAR(50) NOT NULL,
  `legal_last_name` VARCHAR(50) NOT NULL,
  `preferred_name` VARCHAR(50) NULL,
  `surname_phonetic` VARCHAR(20) NULL,
  `date_of_birth` DATE NULL,
  `street_address` VARCHAR(100) NULL,
  `city` VARCHAR(50) NULL,
  `state` CHAR(2) NULL,
  `postal_code` VARCHAR(10) NOT NULL,
  `phone` VARCHAR(15) NULL,
  `email` VARCHAR(100) NULL,
  `preferred_contact_method` ENUM('Phone','SMS','Email','None') NOT NULL DEFAULT 'Phone',
  `consent_to_contact` TINYINT(1) NOT NULL DEFAULT 0,
  `preferred_language_code` VARCHAR(10) NOT NULL DEFAULT 'en',
  `household_size` TINYINT NOT NULL,
  `referral_source` VARCHAR(60) NULL,
  `proof_of_residence_type` VARCHAR(40) NULL,
  `declined_fields` SET('date_of_birth','email','phone','referral_source') NULL,
  `status` ENUM('Active','Inactive','Merged','Deleted') NOT NULL DEFAULT 'Active',
  `status_reason` VARCHAR(255) NULL,
  `home_site_id` INT NOT NULL,
  `registration_site_id` INT NOT NULL,
  `registration_device_id` INT NULL,
  `registered_by` INT NOT NULL,
  `registered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `area_override_reason` VARCHAR(255) NULL,
  `area_override_by` INT NULL,
  `contact_confirmed_on` DATE NULL,
  `last_distribution_date` DATE NULL,
  `next_eligible_date` DATE NULL,
  `ytd_lbs_issued` DECIMAL(8,2) NOT NULL DEFAULT 0,
  `current_allotment_lbs` DECIMAL(6,2) NOT NULL DEFAULT 0,
  `merged_into_id` INT NULL,
  `deleted_at` DATETIME NULL,
  `deleted_by` INT NULL,
  `delete_reason` VARCHAR(255) NULL,
  `restorable_until` DATE NULL,
  `is_anonymized` TINYINT(1) NOT NULL DEFAULT 0,
  `record_source` ENUM('System','Legacy','Offline') NOT NULL DEFAULT 'System',
  `import_batch_id` INT NULL,
  `row_version` INT NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`participant_id`),
  UNIQUE KEY `uk_participant_participant_code` (`participant_code`),
  KEY `ix_participant_1` (`legal_last_name`, `legal_first_name`),
  KEY `ix_participant_2` (`preferred_name`),
  KEY `ix_participant_3` (`surname_phonetic`),
  KEY `ix_participant_4` (`phone`),
  KEY `ix_participant_5` (`postal_code`),
  KEY `ix_participant_6` (`status`, `home_site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `participant_site` (
  `participant_id` INT NOT NULL,
  `site_id` INT NOT NULL,
  `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `added_by` INT NOT NULL,
  `is_transfer` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`participant_id`, `site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `participant_consent` (
  `consent_id` INT NOT NULL AUTO_INCREMENT,
  `participant_id` INT NOT NULL,
  `document_id` INT NOT NULL,
  `consented_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `consenting_person` VARCHAR(100) NOT NULL,
  `recorded_by` INT NOT NULL,
  PRIMARY KEY (`consent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `participant_proxy` (
  `proxy_id` INT NOT NULL AUTO_INCREMENT,
  `participant_id` INT NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(15) NULL,
  `authorized_via` ENUM('In Person','Phone') NOT NULL,
  `authorized_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `recorded_by` INT NOT NULL,
  PRIMARY KEY (`proxy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `service_note` (
  `note_id` INT NOT NULL AUTO_INCREMENT,
  `participant_id` INT NOT NULL,
  `note_text` VARCHAR(280) NOT NULL,
  `author_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `retired_at` DATETIME NULL,
  `retired_by` INT NULL,
  PRIMARY KEY (`note_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `participant_alert` (
  `alert_id` INT NOT NULL AUTO_INCREMENT,
  `participant_id` INT NOT NULL,
  `alert_type` ENUM('Duplicate Candidate','Distribution Restriction','Eligibility Flag','Stale Contact','Vaccination Due','Size Band Review','Pet Presence Check','General') NOT NULL,
  `pet_id` INT NULL,
  `matched_participant_id` INT NULL,
  `blocks_distribution` TINYINT(1) NOT NULL DEFAULT 0,
  `reason` VARCHAR(255) NOT NULL,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `review_due_on` DATE NULL,
  `resolved_at` DATETIME NULL,
  `resolved_by` INT NULL,
  `resolution` VARCHAR(255) NULL,
  PRIMARY KEY (`alert_id`),
  KEY `ix_participant_alert_1` (`participant_id`, `resolved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `registration_draft` (
  `draft_id` INT NOT NULL AUTO_INCREMENT,
  `source` ENUM('Volunteer','Self-Service') NOT NULL,
  `site_id` INT NOT NULL,
  `applicant_name` VARCHAR(100) NOT NULL,
  `form_data` JSON NOT NULL,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `claimed_by` INT NULL,
  `participant_id` INT NULL,
  PRIMARY KEY (`draft_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `intake_question` (
  `question_id` INT NOT NULL AUTO_INCREMENT,
  `prompt` VARCHAR(255) NOT NULL,
  `answer_type` ENUM('Text','Number','Yes/No','Choice','Date') NOT NULL,
  `options` JSON NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `display_order` SMALLINT NOT NULL DEFAULT 0,
  `retired_at` DATETIME NULL,
  `created_by` INT NOT NULL,
  PRIMARY KEY (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `intake_answer` (
  `participant_id` INT NOT NULL,
  `question_id` INT NOT NULL,
  `answer_value` VARCHAR(255) NOT NULL,
  `answered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`participant_id`, `question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `service_area_postal_code` (
  `postal_code` VARCHAR(10) NOT NULL,
  `site_id` INT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`postal_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `referred_out_applicant` (
  `referral_out_id` INT NOT NULL AUTO_INCREMENT,
  `postal_code` VARCHAR(10) NOT NULL,
  `referred_to_pantry` VARCHAR(100) NULL,
  `site_id` INT NOT NULL,
  `recorded_by` INT NOT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`referral_out_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ======================================================================
-- Pets
-- ======================================================================

CREATE TABLE `species` (
  `species_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(30) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`species_id`),
  UNIQUE KEY `uk_species_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `breed` (
  `breed_id` INT NOT NULL AUTO_INCREMENT,
  `species_id` INT NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`breed_id`),
  UNIQUE KEY `uk_breed_1` (`species_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `size_band` (
  `size_band_id` INT NOT NULL AUTO_INCREMENT,
  `species_id` INT NOT NULL,
  `name` VARCHAR(20) NOT NULL,
  `min_weight_lbs` DECIMAL(5,1) NOT NULL,
  `max_weight_lbs` DECIMAL(5,1) NULL,
  `picture_path` VARCHAR(255) NULL,
  PRIMARY KEY (`size_band_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `allotment_rule` (
  `rule_id` INT NOT NULL AUTO_INCREMENT,
  `rule_version` SMALLINT NOT NULL,
  `species_id` INT NOT NULL,
  `size_band_id` INT NOT NULL,
  `food_form` ENUM('Dry','Wet','Any') NOT NULL DEFAULT 'Any',
  `lbs_per_distribution` DECIMAL(5,2) NOT NULL,
  `effective_from` DATE NOT NULL,
  `effective_to` DATE NULL,
  `created_by` INT NOT NULL,
  PRIMARY KEY (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `pet` (
  `pet_id` INT NOT NULL AUTO_INCREMENT,
  `participant_id` INT NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `species_id` INT NOT NULL,
  `breed_id` INT NULL,
  `breed_text` VARCHAR(60) NULL,
  `body_type` VARCHAR(30) NULL,
  `sex` ENUM('Male','Female','Unknown') NOT NULL DEFAULT 'Unknown',
  `colour_markings` VARCHAR(60) NULL,
  `date_of_birth` DATE NULL,
  `dob_is_estimate` TINYINT(1) NOT NULL DEFAULT 1,
  `weight_lbs` DECIMAL(5,1) NULL,
  `weight_confirmed_on` DATE NULL,
  `size_band_id` INT NOT NULL,
  `is_altered` TINYINT(1) NOT NULL DEFAULT 0,
  `altered_date` DATE NULL,
  `altered_clinic_id` INT NULL,
  `altered_elsewhere_text` VARCHAR(100) NULL,
  `snv_status` ENUM('Unaltered','Referred','Altered','Deferred') NOT NULL DEFAULT 'Unaltered',
  `rabies_vaccinated_on` DATE NULL,
  `rabies_expires_on` DATE NULL,
  `microchip_number` VARCHAR(15) NULL,
  `status` ENUM('Active','Inactive','Deleted') NOT NULL DEFAULT 'Active',
  `active_microchip` VARCHAR(15) GENERATED ALWAYS AS (IF(status = 'Active', microchip_number, NULL)) STORED,
  `feeding_restriction` VARCHAR(255) NULL,
  `photo_path` VARCHAR(255) NULL,
  `inactive_reason` ENUM('Deceased','Rehomed','Lost','Surrendered') NULL,
  `inactive_date` DATE NULL,
  `limit_override_reason` VARCHAR(255) NULL,
  `last_confirmed_present` DATE NULL,
  `deleted_at` DATETIME NULL,
  `deleted_by` INT NULL,
  `delete_reason` VARCHAR(255) NULL,
  `import_batch_id` INT NULL,
  `created_by` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `row_version` INT NOT NULL DEFAULT 1,
  PRIMARY KEY (`pet_id`),
  UNIQUE KEY `uk_pet_active_microchip` (`active_microchip`),
  KEY `ix_pet_1` (`participant_id`, `status`),
  KEY `ix_pet_2` (`microchip_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `pet_household_history` (
  `history_id` INT NOT NULL AUTO_INCREMENT,
  `pet_id` INT NOT NULL,
  `participant_id` INT NOT NULL,
  `from_date` DATE NOT NULL,
  `to_date` DATE NULL,
  `change_reason` VARCHAR(100) NULL,
  `changed_by` INT NOT NULL,
  PRIMARY KEY (`history_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ======================================================================
-- Distribution & Inventory
-- ======================================================================

CREATE TABLE `distribution_event` (
  `event_id` INT NOT NULL AUTO_INCREMENT,
  `site_id` INT NOT NULL,
  `event_date` DATE NOT NULL,
  `starts_at` TIME NOT NULL,
  `ends_at` TIME NOT NULL,
  `status` ENUM('Scheduled','Open','Closed') NOT NULL DEFAULT 'Scheduled',
  `opened_by` INT NULL,
  `notes` VARCHAR(500) NULL,
  PRIMARY KEY (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `event_check_in` (
  `check_in_id` INT NOT NULL AUTO_INCREMENT,
  `event_id` INT NOT NULL,
  `participant_id` INT NOT NULL,
  `checked_in_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `checked_in_by` INT NOT NULL,
  `outcome` ENUM('Waiting','Served','Left Unserved','No Stock','Reserve List') NOT NULL DEFAULT 'Waiting',
  `outcome_at` DATETIME NULL,
  PRIMARY KEY (`check_in_id`),
  UNIQUE KEY `uk_event_check_in_1` (`event_id`, `participant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `distribution` (
  `distribution_id` INT NOT NULL AUTO_INCREMENT,
  `client_uuid` CHAR(36) NOT NULL,
  `event_id` INT NOT NULL,
  `participant_id` INT NOT NULL,
  `check_in_id` INT NULL,
  `proxy_id` INT NULL,
  `recorded_by` INT NOT NULL,
  `device_id` INT NULL,
  `distributed_at` DATETIME NOT NULL,
  `local_date` DATE NOT NULL,
  `pets_served` TINYINT NOT NULL,
  `entitled_lbs` DECIMAL(6,2) NOT NULL,
  `allotment_rule_version` SMALLINT NOT NULL,
  `frequency_days_applied` SMALLINT NOT NULL,
  `is_emergency` TINYINT(1) NOT NULL DEFAULT 0,
  `emergency_reason` VARCHAR(255) NULL,
  `is_over_allotment` TINYINT(1) NOT NULL DEFAULT 0,
  `override_reason` VARCHAR(255) NULL,
  `authorized_by` INT NULL,
  `authorization_ref` VARCHAR(40) NULL,
  `second_issue_reason` VARCHAR(255) NULL,
  `reverses_distribution_id` INT NULL,
  `reversal_reason` VARCHAR(255) NULL,
  `receipt_sent_via` ENUM('Print','SMS','Email','None') NOT NULL DEFAULT 'Print',
  `sync_status` ENUM('Synced','Queued') NOT NULL DEFAULT 'Synced',
  `synced_at` DATETIME NULL,
  `import_batch_id` INT NULL,
  PRIMARY KEY (`distribution_id`),
  UNIQUE KEY `uk_distribution_client_uuid` (`client_uuid`),
  KEY `ix_distribution_1` (`participant_id`, `local_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `distribution_pet` (
  `distribution_id` INT NOT NULL,
  `pet_id` INT NOT NULL,
  PRIMARY KEY (`distribution_id`, `pet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `distribution_line` (
  `line_id` INT NOT NULL AUTO_INCREMENT,
  `distribution_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `line_type` ENUM('Issued','Declined','Shortfall') NOT NULL DEFAULT 'Issued',
  `quantity_units` DECIMAL(6,2) NOT NULL,
  `unit_weight_lbs` DECIMAL(6,2) NULL,
  `decline_reason` VARCHAR(100) NULL,
  `substitutes_line_id` INT NULL,
  PRIMARY KEY (`line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `item_category` (
  `category_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `is_banana_box` TINYINT(1) NOT NULL DEFAULT 0,
  `units_per_case` INT NOT NULL DEFAULT 1,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uk_item_category_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `product` (
  `product_id` INT NOT NULL AUTO_INCREMENT,
  `category_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `brand` VARCHAR(60) NULL,
  `species_id` INT NOT NULL,
  `food_form` ENUM('Dry','Wet','Treat','Other') NOT NULL,
  `unit_weight_lbs` DECIMAL(6,2) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `product_barcode` (
  `barcode` VARCHAR(32) NOT NULL,
  `product_id` INT NOT NULL,
  `linked_by` INT NOT NULL,
  `linked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `site_stock` (
  `site_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `quantity_on_hand` DECIMAL(8,2) NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`site_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `stock_receipt` (
  `receipt_id` INT NOT NULL AUTO_INCREMENT,
  `site_id` INT NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `received_on` DATE NOT NULL,
  `received_by` INT NOT NULL,
  `notes` TEXT NULL,
  PRIMARY KEY (`receipt_id`),
  UNIQUE KEY `uk_stock_receipt_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `stock_receipt_line` (
  `receipt_line_id` INT NOT NULL AUTO_INCREMENT,
  `receipt_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `quantity` INT NOT NULL,
  `expiration` DATE NULL,
  PRIMARY KEY (`receipt_line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `inventory_count` (
  `count_id` INT NOT NULL AUTO_INCREMENT,
  `site_id` INT NOT NULL,
  `location` VARCHAR(50) NOT NULL DEFAULT 'Pantry',
  `count_date` DATE NOT NULL,
  `counted_by` INT NOT NULL,
  PRIMARY KEY (`count_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `inventory_count_line` (
  `count_line_id` INT NOT NULL AUTO_INCREMENT,
  `count_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `quantity` INT NOT NULL,
  PRIMARY KEY (`count_line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `inventory_transaction` (
  `txn_id` INT NOT NULL AUTO_INCREMENT,
  `site_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `qty_change` DECIMAL(8,2) NOT NULL,
  `txn_type` ENUM('Receipt','Distribution','Reversal','Count Adjustment','Transfer') NOT NULL,
  `distribution_line_id` INT NULL,
  `receipt_line_id` INT NULL,
  `count_line_id` INT NULL,
  `recorded_by` INT NOT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`txn_id`),
  KEY `ix_inventory_transaction_1` (`site_id`, `product_id`, `recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ======================================================================
-- Spay / Neuter
-- ======================================================================

CREATE TABLE `clinic` (
  `clinic_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `is_partner` TINYINT(1) NOT NULL DEFAULT 1,
  `street_address` VARCHAR(100) NULL,
  `city` VARCHAR(50) NULL,
  `state` CHAR(2) NULL,
  `postal_code` VARCHAR(10) NULL,
  `phone` VARCHAR(15) NULL,
  `hours_text` VARCHAR(255) NULL,
  `directions_url` VARCHAR(255) NULL,
  `voucher_rate` DECIMAL(7,2) NULL,
  `period_capacity` SMALLINT NULL,
  `current_wait_days` SMALLINT NULL,
  `offers_low_cost_vaccination` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('Active','Suspended') NOT NULL DEFAULT 'Active',
  `portal_access_hash` VARCHAR(255) NULL,
  PRIMARY KEY (`clinic_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `clinic_species_rule` (
  `clinic_id` INT NOT NULL,
  `species_id` INT NOT NULL,
  `min_age_months` SMALLINT NOT NULL DEFAULT 0,
  `max_age_months` SMALLINT NULL,
  `min_weight_lbs` DECIMAL(5,1) NOT NULL DEFAULT 0,
  `max_weight_lbs` DECIMAL(5,1) NULL,
  PRIMARY KEY (`clinic_id`, `species_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `voucher_budget` (
  `budget_id` INT NOT NULL AUTO_INCREMENT,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `set_by` INT NOT NULL,
  `set_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`budget_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `snv_referral` (
  `referral_id` INT NOT NULL AUTO_INCREMENT,
  `voucher_number` VARCHAR(20) NOT NULL,
  `pet_id` INT NOT NULL,
  `participant_id` INT NOT NULL,
  `site_id` INT NOT NULL,
  `referral_type` ENUM('Clinic Voucher','Reimbursement') NOT NULL DEFAULT 'Clinic Voucher',
  `clinic_id` INT NULL,
  `other_provider_name` VARCHAR(100) NULL,
  `other_provider_contact` VARCHAR(100) NULL,
  `status` ENUM('Pending','Scheduled','Completed','Expired','Declined','Void') NOT NULL DEFAULT 'Pending',
  `issued_by` INT NOT NULL,
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_on` DATE NOT NULL,
  `preferred_window_start` DATE NULL,
  `preferred_window_end` DATE NULL,
  `transport_requested` TINYINT(1) NOT NULL DEFAULT 0,
  `budget_id` INT NULL,
  `reserved_amount` DECIMAL(7,2) NOT NULL DEFAULT 0,
  `redeemed_amount` DECIMAL(7,2) NULL,
  `decline_reason` VARCHAR(255) NULL,
  `scheduled_date` DATE NULL,
  `redeemed_at` DATETIME NULL,
  `surgery_date` DATE NULL,
  `outcome` ENUM('Surgery Completed','Completed Elsewhere','Not Performed') NULL,
  `outcome_source` ENUM('Clinic Link','Staff') NULL,
  `outcome_reported_at` DATETIME NULL,
  `material_language_code` VARCHAR(10) NOT NULL DEFAULT 'en',
  `needs_reconciliation` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`referral_id`),
  UNIQUE KEY `uk_snv_referral_voucher_number` (`voucher_number`),
  KEY `ix_snv_referral_1` (`pet_id`, `status`),
  KEY `ix_snv_referral_2` (`status`, `expires_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `snv_referral_status_log` (
  `log_id` INT NOT NULL AUTO_INCREMENT,
  `referral_id` INT NOT NULL,
  `from_status` VARCHAR(12) NULL,
  `to_status` VARCHAR(12) NOT NULL,
  `changed_by` INT NULL,
  `changed_by_clinic_id` INT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason` VARCHAR(255) NULL,
  PRIMARY KEY (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `snv_followup` (
  `followup_id` INT NOT NULL AUTO_INCREMENT,
  `pet_id` INT NOT NULL,
  `referral_id` INT NULL,
  `followup_type` ENUM('Reassess','Waiting List','Re-offer','Offer Deferred','Expiry Reminder') NOT NULL,
  `due_on` DATE NOT NULL,
  `reason` VARCHAR(255) NULL,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME NULL,
  PRIMARY KEY (`followup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ======================================================================
-- Governance & Reporting
-- ======================================================================

CREATE TABLE `system_setting` (
  `setting_key` VARCHAR(60) NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL,
  `description` VARCHAR(255) NULL,
  `updated_by` INT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `audit_log` (
  `audit_id` BIGINT NOT NULL AUTO_INCREMENT,
  `occurred_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `user_id` INT NULL,
  `session_id` CHAR(64) NULL,
  `device_id` INT NULL,
  `site_id` INT NULL,
  `action` VARCHAR(40) NOT NULL,
  `entity_type` VARCHAR(30) NULL,
  `entity_id` BIGINT NULL,
  `outcome` ENUM('Success','Denied','Failed') NOT NULL DEFAULT 'Success',
  `reason` VARCHAR(255) NULL,
  `snapshot` JSON NULL,
  `details` JSON NULL,
  PRIMARY KEY (`audit_id`),
  KEY `ix_audit_log_1` (`entity_type`, `entity_id`),
  KEY `ix_audit_log_2` (`user_id`, `occurred_at`),
  KEY `ix_audit_log_3` (`action`, `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `audit_field_change` (
  `change_id` BIGINT NOT NULL AUTO_INCREMENT,
  `audit_id` BIGINT NOT NULL,
  `field_name` VARCHAR(60) NOT NULL,
  `old_value` TEXT NULL,
  `new_value` TEXT NULL,
  PRIMARY KEY (`change_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `erasure_request` (
  `request_id` INT NOT NULL AUTO_INCREMENT,
  `participant_id` INT NULL,
  `request_reference` VARCHAR(40) NOT NULL,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `requested_by` INT NOT NULL,
  `status` ENUM('Received','Approved','Rejected','Completed') NOT NULL DEFAULT 'Received',
  `approved_by` INT NULL,
  `decided_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `anonymous_ref` VARCHAR(20) NULL,
  PRIMARY KEY (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `import_mapping` (
  `mapping_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `record_type` ENUM('Participant','Pet','Distribution') NOT NULL,
  `column_map` JSON NOT NULL,
  `created_by` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`mapping_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `import_batch` (
  `batch_id` INT NOT NULL AUTO_INCREMENT,
  `record_type` ENUM('Participant','Pet','Distribution') NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `stored_file_path` VARCHAR(255) NULL,
  `mapping_id` INT NULL,
  `continues_batch_id` INT NULL,
  `is_dry_run` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('Validated','Committed','Rolled Back','Failed') NOT NULL,
  `rows_written` INT NOT NULL DEFAULT 0,
  `rows_updated` INT NOT NULL DEFAULT 0,
  `rows_skipped` INT NOT NULL DEFAULT 0,
  `rows_rejected` INT NOT NULL DEFAULT 0,
  `backup_confirmed` TINYINT(1) NOT NULL DEFAULT 0,
  `run_by` INT NOT NULL,
  `run_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `purge_files_after` DATE NULL,
  PRIMARY KEY (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `import_rejected_row` (
  `batch_id` INT NOT NULL,
  `row_number` INT NOT NULL,
  `raw_row` TEXT NOT NULL,
  `reject_reason` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`batch_id`, `row_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `saved_report` (
  `saved_report_id` INT NOT NULL AUTO_INCREMENT,
  `owner_id` INT NOT NULL,
  `report_key` VARCHAR(60) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `parameters` JSON NOT NULL,
  `schedule_frequency` ENUM('None','Weekly','Monthly','Quarterly') NOT NULL DEFAULT 'None',
  `schedule_format` ENUM('PDF','CSV','XLSX') NULL,
  `next_run_at` DATETIME NULL,
  PRIMARY KEY (`saved_report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `report_recipient` (
  `saved_report_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  PRIMARY KEY (`saved_report_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `report_run` (
  `run_id` INT NOT NULL AUTO_INCREMENT,
  `saved_report_id` INT NULL,
  `report_key` VARCHAR(60) NOT NULL,
  `parameters` JSON NOT NULL,
  `run_by` INT NULL,
  `run_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_current_as_of` DATETIME NOT NULL,
  `status` ENUM('Queued','Running','Complete','Failed') NOT NULL DEFAULT 'Queued',
  `output_path` VARCHAR(255) NULL,
  `retain_until` DATE NULL,
  PRIMARY KEY (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `report_narrative` (
  `narrative_id` INT NOT NULL AUTO_INCREMENT,
  `run_id` INT NOT NULL,
  `version` SMALLINT NOT NULL DEFAULT 1,
  `body` TEXT NOT NULL,
  `author_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `circulated_at` DATETIME NULL,
  PRIMARY KEY (`narrative_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `metric_threshold` (
  `threshold_id` INT NOT NULL AUTO_INCREMENT,
  `metric_key` VARCHAR(60) NOT NULL,
  `rule_type` ENUM('Above','Below','Pct Change') NOT NULL,
  `threshold_value` DECIMAL(10,2) NOT NULL,
  `min_alert_interval_days` SMALLINT NOT NULL DEFAULT 7,
  `last_alerted_at` DATETIME NULL,
  `created_by` INT NOT NULL,
  PRIMARY KEY (`threshold_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `grant_commitment` (
  `grant_id` INT NOT NULL AUTO_INCREMENT,
  `funder_name` VARCHAR(100) NOT NULL,
  `metric_key` VARCHAR(60) NOT NULL,
  `target_value` DECIMAL(10,2) NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  PRIMARY KEY (`grant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ======================================================================
-- Foreign keys (added after all tables so creation order does not matter)
-- ======================================================================
ALTER TABLE `user_account`
  ADD CONSTRAINT `fk_user_account_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `user_site_access`
  ADD CONSTRAINT `fk_user_site_access_user_id` FOREIGN KEY (`user_id`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_user_site_access_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_user_site_access_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `device`
  ADD CONSTRAINT `fk_device_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_device_registered_by` FOREIGN KEY (`registered_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `user_session`
  ADD CONSTRAINT `fk_user_session_user_id` FOREIGN KEY (`user_id`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_user_session_device_id` FOREIGN KEY (`device_id`) REFERENCES `device` (`device_id`),
  ADD CONSTRAINT `fk_user_session_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_user_session_ended_by` FOREIGN KEY (`ended_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `auth_token`
  ADD CONSTRAINT `fk_auth_token_user_id` FOREIGN KEY (`user_id`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_auth_token_device_id` FOREIGN KEY (`device_id`) REFERENCES `device` (`device_id`);
ALTER TABLE `policy_document`
  ADD CONSTRAINT `fk_policy_document_language_code` FOREIGN KEY (`language_code`) REFERENCES `language` (`language_code`);
ALTER TABLE `policy_acknowledgement`
  ADD CONSTRAINT `fk_policy_acknowledgement_user_id` FOREIGN KEY (`user_id`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_policy_acknowledgement_document_id` FOREIGN KEY (`document_id`) REFERENCES `policy_document` (`document_id`);
ALTER TABLE `participant`
  ADD CONSTRAINT `fk_participant_preferred_language_code` FOREIGN KEY (`preferred_language_code`) REFERENCES `language` (`language_code`),
  ADD CONSTRAINT `fk_participant_home_site_id` FOREIGN KEY (`home_site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_participant_registration_site_id` FOREIGN KEY (`registration_site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_participant_registration_device_id` FOREIGN KEY (`registration_device_id`) REFERENCES `device` (`device_id`),
  ADD CONSTRAINT `fk_participant_registered_by` FOREIGN KEY (`registered_by`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_participant_area_override_by` FOREIGN KEY (`area_override_by`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_participant_merged_into_id` FOREIGN KEY (`merged_into_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_participant_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_participant_import_batch_id` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batch` (`batch_id`);
ALTER TABLE `participant_site`
  ADD CONSTRAINT `fk_participant_site_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_participant_site_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_participant_site_added_by` FOREIGN KEY (`added_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `participant_consent`
  ADD CONSTRAINT `fk_participant_consent_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_participant_consent_document_id` FOREIGN KEY (`document_id`) REFERENCES `policy_document` (`document_id`),
  ADD CONSTRAINT `fk_participant_consent_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `participant_proxy`
  ADD CONSTRAINT `fk_participant_proxy_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_participant_proxy_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `service_note`
  ADD CONSTRAINT `fk_service_note_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_service_note_author_id` FOREIGN KEY (`author_id`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_service_note_retired_by` FOREIGN KEY (`retired_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `participant_alert`
  ADD CONSTRAINT `fk_participant_alert_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_participant_alert_pet_id` FOREIGN KEY (`pet_id`) REFERENCES `pet` (`pet_id`),
  ADD CONSTRAINT `fk_participant_alert_matched_participant_id` FOREIGN KEY (`matched_participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_participant_alert_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_participant_alert_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `registration_draft`
  ADD CONSTRAINT `fk_registration_draft_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_registration_draft_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_registration_draft_claimed_by` FOREIGN KEY (`claimed_by`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_registration_draft_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`);
ALTER TABLE `intake_question`
  ADD CONSTRAINT `fk_intake_question_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `intake_answer`
  ADD CONSTRAINT `fk_intake_answer_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_intake_answer_question_id` FOREIGN KEY (`question_id`) REFERENCES `intake_question` (`question_id`);
ALTER TABLE `service_area_postal_code`
  ADD CONSTRAINT `fk_service_area_postal_code_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`);
ALTER TABLE `referred_out_applicant`
  ADD CONSTRAINT `fk_referred_out_applicant_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_referred_out_applicant_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `breed`
  ADD CONSTRAINT `fk_breed_species_id` FOREIGN KEY (`species_id`) REFERENCES `species` (`species_id`);
ALTER TABLE `size_band`
  ADD CONSTRAINT `fk_size_band_species_id` FOREIGN KEY (`species_id`) REFERENCES `species` (`species_id`);
ALTER TABLE `allotment_rule`
  ADD CONSTRAINT `fk_allotment_rule_species_id` FOREIGN KEY (`species_id`) REFERENCES `species` (`species_id`),
  ADD CONSTRAINT `fk_allotment_rule_size_band_id` FOREIGN KEY (`size_band_id`) REFERENCES `size_band` (`size_band_id`),
  ADD CONSTRAINT `fk_allotment_rule_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `pet`
  ADD CONSTRAINT `fk_pet_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_pet_species_id` FOREIGN KEY (`species_id`) REFERENCES `species` (`species_id`),
  ADD CONSTRAINT `fk_pet_breed_id` FOREIGN KEY (`breed_id`) REFERENCES `breed` (`breed_id`),
  ADD CONSTRAINT `fk_pet_size_band_id` FOREIGN KEY (`size_band_id`) REFERENCES `size_band` (`size_band_id`),
  ADD CONSTRAINT `fk_pet_altered_clinic_id` FOREIGN KEY (`altered_clinic_id`) REFERENCES `clinic` (`clinic_id`),
  ADD CONSTRAINT `fk_pet_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_pet_import_batch_id` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batch` (`batch_id`),
  ADD CONSTRAINT `fk_pet_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `pet_household_history`
  ADD CONSTRAINT `fk_pet_household_history_pet_id` FOREIGN KEY (`pet_id`) REFERENCES `pet` (`pet_id`),
  ADD CONSTRAINT `fk_pet_household_history_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_pet_household_history_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `distribution_event`
  ADD CONSTRAINT `fk_distribution_event_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_distribution_event_opened_by` FOREIGN KEY (`opened_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `event_check_in`
  ADD CONSTRAINT `fk_event_check_in_event_id` FOREIGN KEY (`event_id`) REFERENCES `distribution_event` (`event_id`),
  ADD CONSTRAINT `fk_event_check_in_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_event_check_in_checked_in_by` FOREIGN KEY (`checked_in_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `distribution`
  ADD CONSTRAINT `fk_distribution_event_id` FOREIGN KEY (`event_id`) REFERENCES `distribution_event` (`event_id`),
  ADD CONSTRAINT `fk_distribution_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_distribution_check_in_id` FOREIGN KEY (`check_in_id`) REFERENCES `event_check_in` (`check_in_id`),
  ADD CONSTRAINT `fk_distribution_proxy_id` FOREIGN KEY (`proxy_id`) REFERENCES `participant_proxy` (`proxy_id`),
  ADD CONSTRAINT `fk_distribution_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_distribution_device_id` FOREIGN KEY (`device_id`) REFERENCES `device` (`device_id`),
  ADD CONSTRAINT `fk_distribution_authorized_by` FOREIGN KEY (`authorized_by`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_distribution_reverses_distribution_id` FOREIGN KEY (`reverses_distribution_id`) REFERENCES `distribution` (`distribution_id`),
  ADD CONSTRAINT `fk_distribution_import_batch_id` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batch` (`batch_id`);
ALTER TABLE `distribution_pet`
  ADD CONSTRAINT `fk_distribution_pet_distribution_id` FOREIGN KEY (`distribution_id`) REFERENCES `distribution` (`distribution_id`),
  ADD CONSTRAINT `fk_distribution_pet_pet_id` FOREIGN KEY (`pet_id`) REFERENCES `pet` (`pet_id`);
ALTER TABLE `distribution_line`
  ADD CONSTRAINT `fk_distribution_line_distribution_id` FOREIGN KEY (`distribution_id`) REFERENCES `distribution` (`distribution_id`),
  ADD CONSTRAINT `fk_distribution_line_product_id` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`),
  ADD CONSTRAINT `fk_distribution_line_substitutes_line_id` FOREIGN KEY (`substitutes_line_id`) REFERENCES `distribution_line` (`line_id`);
ALTER TABLE `product`
  ADD CONSTRAINT `fk_product_category_id` FOREIGN KEY (`category_id`) REFERENCES `item_category` (`category_id`),
  ADD CONSTRAINT `fk_product_species_id` FOREIGN KEY (`species_id`) REFERENCES `species` (`species_id`);
ALTER TABLE `product_barcode`
  ADD CONSTRAINT `fk_product_barcode_product_id` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`),
  ADD CONSTRAINT `fk_product_barcode_linked_by` FOREIGN KEY (`linked_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `site_stock`
  ADD CONSTRAINT `fk_site_stock_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_site_stock_product_id` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`);
ALTER TABLE `stock_receipt`
  ADD CONSTRAINT `fk_stock_receipt_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_stock_receipt_received_by` FOREIGN KEY (`received_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `stock_receipt_line`
  ADD CONSTRAINT `fk_stock_receipt_line_receipt_id` FOREIGN KEY (`receipt_id`) REFERENCES `stock_receipt` (`receipt_id`),
  ADD CONSTRAINT `fk_stock_receipt_line_product_id` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`);
ALTER TABLE `inventory_count`
  ADD CONSTRAINT `fk_inventory_count_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_inventory_count_counted_by` FOREIGN KEY (`counted_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `inventory_count_line`
  ADD CONSTRAINT `fk_inventory_count_line_count_id` FOREIGN KEY (`count_id`) REFERENCES `inventory_count` (`count_id`),
  ADD CONSTRAINT `fk_inventory_count_line_product_id` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`);
ALTER TABLE `inventory_transaction`
  ADD CONSTRAINT `fk_inventory_transaction_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_inventory_transaction_product_id` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`),
  ADD CONSTRAINT `fk_inventory_transaction_distribution_line_id` FOREIGN KEY (`distribution_line_id`) REFERENCES `distribution_line` (`line_id`),
  ADD CONSTRAINT `fk_inventory_transaction_receipt_line_id` FOREIGN KEY (`receipt_line_id`) REFERENCES `stock_receipt_line` (`receipt_line_id`),
  ADD CONSTRAINT `fk_inventory_transaction_count_line_id` FOREIGN KEY (`count_line_id`) REFERENCES `inventory_count_line` (`count_line_id`),
  ADD CONSTRAINT `fk_inventory_transaction_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `clinic_species_rule`
  ADD CONSTRAINT `fk_clinic_species_rule_clinic_id` FOREIGN KEY (`clinic_id`) REFERENCES `clinic` (`clinic_id`),
  ADD CONSTRAINT `fk_clinic_species_rule_species_id` FOREIGN KEY (`species_id`) REFERENCES `species` (`species_id`);
ALTER TABLE `voucher_budget`
  ADD CONSTRAINT `fk_voucher_budget_set_by` FOREIGN KEY (`set_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `snv_referral`
  ADD CONSTRAINT `fk_snv_referral_pet_id` FOREIGN KEY (`pet_id`) REFERENCES `pet` (`pet_id`),
  ADD CONSTRAINT `fk_snv_referral_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_snv_referral_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`),
  ADD CONSTRAINT `fk_snv_referral_clinic_id` FOREIGN KEY (`clinic_id`) REFERENCES `clinic` (`clinic_id`),
  ADD CONSTRAINT `fk_snv_referral_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_snv_referral_budget_id` FOREIGN KEY (`budget_id`) REFERENCES `voucher_budget` (`budget_id`),
  ADD CONSTRAINT `fk_snv_referral_material_language_code` FOREIGN KEY (`material_language_code`) REFERENCES `language` (`language_code`);
ALTER TABLE `snv_referral_status_log`
  ADD CONSTRAINT `fk_snv_referral_status_log_referral_id` FOREIGN KEY (`referral_id`) REFERENCES `snv_referral` (`referral_id`),
  ADD CONSTRAINT `fk_snv_referral_status_log_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_snv_referral_status_log_changed_by_clinic_id` FOREIGN KEY (`changed_by_clinic_id`) REFERENCES `clinic` (`clinic_id`);
ALTER TABLE `snv_followup`
  ADD CONSTRAINT `fk_snv_followup_pet_id` FOREIGN KEY (`pet_id`) REFERENCES `pet` (`pet_id`),
  ADD CONSTRAINT `fk_snv_followup_referral_id` FOREIGN KEY (`referral_id`) REFERENCES `snv_referral` (`referral_id`),
  ADD CONSTRAINT `fk_snv_followup_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `system_setting`
  ADD CONSTRAINT `fk_system_setting_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `audit_log`
  ADD CONSTRAINT `fk_audit_log_user_id` FOREIGN KEY (`user_id`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_audit_log_session_id` FOREIGN KEY (`session_id`) REFERENCES `user_session` (`session_id`),
  ADD CONSTRAINT `fk_audit_log_device_id` FOREIGN KEY (`device_id`) REFERENCES `device` (`device_id`),
  ADD CONSTRAINT `fk_audit_log_site_id` FOREIGN KEY (`site_id`) REFERENCES `site` (`site_id`);
ALTER TABLE `audit_field_change`
  ADD CONSTRAINT `fk_audit_field_change_audit_id` FOREIGN KEY (`audit_id`) REFERENCES `audit_log` (`audit_id`);
ALTER TABLE `erasure_request`
  ADD CONSTRAINT `fk_erasure_request_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  ADD CONSTRAINT `fk_erasure_request_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `user_account` (`user_id`),
  ADD CONSTRAINT `fk_erasure_request_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `import_mapping`
  ADD CONSTRAINT `fk_import_mapping_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `import_batch`
  ADD CONSTRAINT `fk_import_batch_mapping_id` FOREIGN KEY (`mapping_id`) REFERENCES `import_mapping` (`mapping_id`),
  ADD CONSTRAINT `fk_import_batch_continues_batch_id` FOREIGN KEY (`continues_batch_id`) REFERENCES `import_batch` (`batch_id`),
  ADD CONSTRAINT `fk_import_batch_run_by` FOREIGN KEY (`run_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `import_rejected_row`
  ADD CONSTRAINT `fk_import_rejected_row_batch_id` FOREIGN KEY (`batch_id`) REFERENCES `import_batch` (`batch_id`);
ALTER TABLE `saved_report`
  ADD CONSTRAINT `fk_saved_report_owner_id` FOREIGN KEY (`owner_id`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `report_recipient`
  ADD CONSTRAINT `fk_report_recipient_saved_report_id` FOREIGN KEY (`saved_report_id`) REFERENCES `saved_report` (`saved_report_id`),
  ADD CONSTRAINT `fk_report_recipient_user_id` FOREIGN KEY (`user_id`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `report_run`
  ADD CONSTRAINT `fk_report_run_saved_report_id` FOREIGN KEY (`saved_report_id`) REFERENCES `saved_report` (`saved_report_id`),
  ADD CONSTRAINT `fk_report_run_run_by` FOREIGN KEY (`run_by`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `report_narrative`
  ADD CONSTRAINT `fk_report_narrative_run_id` FOREIGN KEY (`run_id`) REFERENCES `report_run` (`run_id`),
  ADD CONSTRAINT `fk_report_narrative_author_id` FOREIGN KEY (`author_id`) REFERENCES `user_account` (`user_id`);
ALTER TABLE `metric_threshold`
  ADD CONSTRAINT `fk_metric_threshold_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_account` (`user_id`);

SET FOREIGN_KEY_CHECKS = 1;

-- Seed values the business rules depend on (edit to suit the pantry)
INSERT INTO `language` (`language_code`,`name`) VALUES ('en','English'),('es','Spanish');
INSERT INTO `species` (`name`) VALUES ('Dog'),('Cat');
INSERT INTO `system_setting` (`setting_key`,`setting_value`,`description`) VALUES
  ('frequency_rule_days','30','Minimum days between distributions (UC-06)'),
  ('household_pet_limit','6','Max pets per household before Admin override (UC-05)'),
  ('voucher_expiry_days','60','SNV voucher validity (UC-08)'),
  ('recovery_window_days','30','Soft-delete restore window (UC-09)'),
  ('lapse_threshold_days','90','Days without distribution before a participant counts as lapsed (UC-15)'),
  ('small_cell_minimum','5','Suppress report cells below this count (UC-15)'),
  ('contact_reconfirm_days','365','Prompt to reconfirm contact details (US-11)'),
  ('vaccination_warning_days','30','Warn when rabies vaccination expires within (US-14)'),
  ('session_idle_minutes','30','Session inactivity timeout (UC-01)'),
  ('max_failed_logins','5','Lockout threshold (UC-01)'),
  ('litters_prevented_multiplier','','Multiplier and citation for US-40 estimate - set by Administrator'),
  ('import_file_retention_days','90','Purge uploaded import files after (UC-12)');
