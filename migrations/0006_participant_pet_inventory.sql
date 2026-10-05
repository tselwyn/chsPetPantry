-- Migration 0006 (v2.1): participant, pet and inventory gaps.
-- UC-03 step 6 (declared pet count), UC-02 3.2.1 (card reissue invalidates old cards),
-- UC-09 3.2.4 (restore returns the previous status and the pets the deletion inactivated),
-- UC-05 3.3.3 (pet-limit override attribution), UC-06 3.3.3 and UC-14 (unmet requests),
-- and the offline count race (a count posted after an offline distribution was recorded).

ALTER TABLE `participant`
  ADD COLUMN `declared_pet_count` TINYINT NULL AFTER `household_size`,
  ADD COLUMN `card_version` SMALLINT NOT NULL DEFAULT 1 AFTER `participant_code`,
  ADD COLUMN `status_before_delete` ENUM('Active','Inactive','Merged','Deleted') NULL AFTER `status_reason`;

ALTER TABLE `pet`
  ADD COLUMN `is_limit_exception` TINYINT(1) NOT NULL DEFAULT 0 AFTER `limit_override_reason`,
  ADD COLUMN `limit_override_by` INT NULL AFTER `is_limit_exception`,
  ADD COLUMN `inactivated_by_owner_delete` TINYINT(1) NOT NULL DEFAULT 0 AFTER `inactive_date`,
  ADD CONSTRAINT `fk_pet_limit_override_by` FOREIGN KEY (`limit_override_by`) REFERENCES `user_account` (`user_id`);

ALTER TABLE `inventory_count`
  ADD COLUMN `posted_at` DATETIME NULL AFTER `counted_by`;

-- An unmet request is recorded against the participant and the event (UC-06 3.3.3); the check-in
-- is optional because late-entry paper slips have none.
CREATE TABLE `unmet_request` (
  `unmet_id` INT NOT NULL AUTO_INCREMENT,
  `participant_id` INT NOT NULL,
  `event_id` INT NOT NULL,
  `check_in_id` INT NULL,
  `species_id` INT NOT NULL,
  `food_form` ENUM('Dry','Wet','Treat','Other','Any') NOT NULL DEFAULT 'Any',
  `product_id` INT NULL,
  `quantity_units` DECIMAL(6,2) NULL,
  `reserve_for_event_id` INT NULL,
  `recorded_by` INT NOT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`unmet_id`),
  CONSTRAINT `fk_unmet_request_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `participant` (`participant_id`),
  CONSTRAINT `fk_unmet_request_event_id` FOREIGN KEY (`event_id`) REFERENCES `distribution_event` (`event_id`),
  CONSTRAINT `fk_unmet_request_check_in_id` FOREIGN KEY (`check_in_id`) REFERENCES `event_check_in` (`check_in_id`),
  CONSTRAINT `fk_unmet_request_species_id` FOREIGN KEY (`species_id`) REFERENCES `species` (`species_id`),
  CONSTRAINT `fk_unmet_request_product_id` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`),
  CONSTRAINT `fk_unmet_request_reserve_for_event_id` FOREIGN KEY (`reserve_for_event_id`) REFERENCES `distribution_event` (`event_id`),
  CONSTRAINT `fk_unmet_request_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `user_account` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
