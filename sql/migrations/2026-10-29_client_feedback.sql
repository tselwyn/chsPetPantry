-- Client feedback from the October 29 demo
--
-- Updates an existing local foodpantrydb to match sql/foodpantrydb.sql,
-- without re-importing the whole database.
-- In phpMyAdmin: select foodpantrydb, open the SQL tab, paste this file, Go.
-- Run it once, after sql/migrations/uc08_snv_fields.sql. Export a backup
-- first (Export tab, Quick, SQL); section 6 drops two dbpets columns.
--
-- Optional check before running: every status should be one of Active,
-- Inactive, Expired, No Service or Deleted.
--   SELECT `status`, COUNT(*) FROM `dbparticipants` GROUP BY `status`;

-- 1. Participant status "No Service" becomes "DND" (Do Not Distribute).
--    The enum is widened first so existing rows can be moved over, then the
--    old value is removed. Deleted is in the enum because delete_participant
--    sets it and the enum in the SQL file never had it.
ALTER TABLE `dbparticipants`
  MODIFY `status` enum('Active','Inactive','Expired','No Service','DND','Deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active';

UPDATE `dbparticipants`
  SET `status` = 'DND'
  WHERE `status` = 'No Service';

ALTER TABLE `dbparticipants`
  MODIFY `status` enum('Active','Inactive','Expired','DND','Deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active';

-- 2. The participant agreement is no longer collected at registration.
--    No change: dbparticipants.consent stays, and new rows get its default 0.

-- 3. "Save and Add Pets" goes straight to Register Pet. No database change.

-- 4. Pets shown inline in participant search results. No database change.

-- 5. Edit and Delete on the participant page's pets table. No database
--    change; a deleted pet keeps its row with status Deleted.

-- 6. Pet age in years replaces date of birth and "is this estimated".
--    Existing pets get their age from the date of birth: years since then,
--    rounded to one decimal place and kept within the form's 0.1 to 40.
--    Pets with no date of birth get no age; Edit Pet asks for one the next
--    time they are edited.
ALTER TABLE `dbpets`
  ADD COLUMN `age_years` decimal(4,1) DEFAULT NULL AFTER `sex`;

UPDATE `dbpets`
  SET `age_years` = LEAST(40.0, GREATEST(0.1, ROUND(DATEDIFF(CURDATE(), `date_of_birth`) / 365.25, 1)))
  WHERE `date_of_birth` IS NOT NULL;

ALTER TABLE `dbpets`
  DROP COLUMN `date_of_birth`,
  DROP COLUMN `dob_is_estimate`;

-- 7. Last distribution date on the Remove Participant list. No database
--    change; it is MAX(distribution_date) from dbparticipantdistributions.

-- 8a. Shared Administrator and Volunteer logins. The password is a
--     placeholder that is not a hash, so neither account can log in until a
--     password is set (README, "Shared Administrator and Volunteer logins").
--     INSERT IGNORE skips an account whose username already exists.
INSERT IGNORE INTO `dbpersons` (`id`, `first_name`, `last_name`, `email`, `type`, `status`, `password`, `force_password_change`) VALUES
('chs_admin', 'Administrator', '', '', 'admin', 'Active', 'SET-A-PASSWORD', 0),
('chs_volunteer', 'Volunteer', '', '', 'volunteer', 'Active', 'SET-A-PASSWORD', 0);

-- 8c. Alert / Flag reads Flagged / No and only an administrator can change
--     it. No database change: dbparticipants.alert stays 1 (Flagged) / 0 (No).

-- 8d. "Processed By" on Record Distribution: the volunteer's name, typed in
--     because logins are shared. processed_by keeps the login's id.
--     Distributions recorded before this get the name of the login that
--     recorded them, or its id if that login has no name.
ALTER TABLE `dbparticipantdistributions`
  ADD COLUMN `processed_by_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' AFTER `processed_by`;

UPDATE `dbparticipantdistributions` d
  LEFT JOIN `dbpersons` p ON p.`id` = d.`processed_by`
  SET d.`processed_by_name` = LEFT(COALESCE(NULLIF(TRIM(CONCAT_WS(' ', p.`first_name`, p.`last_name`)), ''), d.`processed_by`), 100)
  WHERE d.`processed_by_name` = '';
