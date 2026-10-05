-- Migration 0011 (v2.1): allotment rule versions with drafts (UC-05 §4.1, UC-06 §4.5, plan P2A).
-- A version is every row sharing one rule_version. published_at IS NULL marks the one draft,
-- whose empty cells are NULL pounds until it is published (publishing refuses empty cells).
-- The version in force on a site-local date is the published version with the latest
-- effective_from on or before it; effective_to is kept but not used. rule_version 0 is reserved
-- for imported legacy distributions (no rules), so real versions start at 1.

ALTER TABLE `allotment_rule`
  MODIFY COLUMN `lbs_per_distribution` DECIMAL(5,2) NULL,
  ADD COLUMN `published_at` DATETIME NULL AFTER `effective_to`,
  ADD COLUMN `published_by` INT NULL AFTER `published_at`,
  ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_by`,
  ADD UNIQUE KEY `uk_allotment_rule_1` (`rule_version`, `species_id`, `size_band_id`, `food_form`),
  ADD KEY `ix_allotment_rule_1` (`effective_from`),
  ADD CONSTRAINT `fk_allotment_rule_published_by` FOREIGN KEY (`published_by`) REFERENCES `user_account` (`user_id`);

-- Version numbers come from a counter that only goes up, so a number that was ever handed out
-- (including one taken back or discarded) is never used again for different rules.
INSERT INTO `id_sequence` (`seq_name`, `prefix`, `next_value`)
SELECT 'allotment_version', '', COALESCE(MAX(`rule_version`), 0) + 1 FROM `allotment_rule`;
