-- UC-08 Record SNV / Counseling Information (FR-027, FR-029, FR-043)
--
-- Updates an existing local foodpantrydb to the dbpets columns in
-- sql/foodpantrydb.sql, without re-importing the whole database.
-- In phpMyAdmin: select foodpantrydb, open the SQL tab, paste this file, Go.
-- Run it once. Running it again fails because is_altered is already gone.

-- 1. Spay/neuter becomes Yes / No / Unknown. Existing pets keep their answer:
--    is_altered 1 becomes Yes and 0 becomes No.
ALTER TABLE `dbpets`
  ADD COLUMN `spay_neuter_status` enum('Yes','No','Unknown') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unknown' AFTER `weight_lbs`;

UPDATE `dbpets`
  SET `spay_neuter_status` = IF(`is_altered` = 1, 'Yes', 'No');

ALTER TABLE `dbpets`
  DROP COLUMN `is_altered`;

-- 2. Rabies vaccination and counseling. counseled_by is the dbpersons id of
--    the volunteer or admin who recorded the counseling.
ALTER TABLE `dbpets`
  ADD COLUMN `rabies_status` enum('Yes','No','Unknown') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unknown' AFTER `spay_neuter_status`,
  ADD COLUMN `counseling_status` enum('Yes','No') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'No' AFTER `rabies_status`,
  ADD COLUMN `counseling_date` date DEFAULT NULL AFTER `counseling_status`,
  ADD COLUMN `counseled_by` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `counseling_date`;

-- 3. Food and notes columns that registerPet.php already saves but that were
--    never added to the SQL file. Skip this statement if your dbpets already
--    has food_type, food_restriction and notes.
ALTER TABLE `dbpets`
  ADD COLUMN `food_type` enum('dry','wet','either') COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `counseled_by`,
  ADD COLUMN `food_restriction` text COLLATE utf8mb4_unicode_ci AFTER `food_type`,
  ADD COLUMN `notes` text COLLATE utf8mb4_unicode_ci AFTER `food_restriction`;
