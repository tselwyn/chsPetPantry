-- CHS Pet Pantry
-- Pet age counts up over time (SRS FR-077, risk 6.2)
--
-- CHS enters a pet's age in years, not a date of birth. This stores the date
-- the age was entered, and the app shows age + time since then, so a pet
-- entered as 2.0 is shown as 3.0 a year later.
--
-- Optional: the app adds this column itself the first time a pet is saved
-- (ensure_pet_age_date_column in database/dbPets.php). Running it in
-- phpMyAdmin first is also fine. Run it only once: MySQL has no
-- "ADD COLUMN IF NOT EXISTS".

ALTER TABLE `dbpets`
  ADD COLUMN `age_recorded_date` date DEFAULT NULL AFTER `age_years`;

-- Existing ages are dated from when the pet was added
UPDATE `dbpets`
  SET `age_recorded_date` = COALESCE(DATE(`created_at`), CURDATE())
  WHERE `age_years` IS NOT NULL AND `age_recorded_date` IS NULL;
