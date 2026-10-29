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
