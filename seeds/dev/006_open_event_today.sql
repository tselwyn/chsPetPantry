-- Development seed data: an Open distribution event today at Dev Site North with five households checked in, so the
-- empty participant search shows today's check-ins first (US-04) before the events and check-in pages exist. Run
-- `php bin/seed.php --dev` (or bin/setup.php) on the day of a demo: a dev-seed event left Open from an earlier day is
-- closed, and today's is opened, if the site has no other Open event (at most one per site).
-- "Today" is the site's date in America/New_York, taken as UTC-5 here because XAMPP's MySQL has no time zone
-- tables; during daylight saving time that is an hour behind, so between midnight and 1 a.m. the event is
-- yesterday's. Needs seeds/dev/005_participants.sql. Safe to run repeatedly.

UPDATE `distribution_event`
   SET `status` = 'Closed'
 WHERE `notes` = 'Dev seed event, open today' AND `status` = 'Open' AND `event_date` < DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR);

INSERT INTO `distribution_event` (`site_id`, `event_date`, `starts_at`, `ends_at`, `status`, `opened_by`, `notes`)
SELECT s.`site_id`, DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR), '09:00:00', '17:00:00', 'Open', u.`user_id`, 'Dev seed event, open today'
  FROM `site` s
  JOIN `user_account` u ON u.`username` = 'system'
 WHERE s.`name` = 'Dev Site North'
   AND NOT EXISTS (SELECT 1 FROM `distribution_event` x WHERE x.`site_id` = s.`site_id` AND x.`status` = 'Open');

INSERT INTO `event_check_in` (`event_id`, `participant_id`, `checked_in_at`, `checked_in_by`, `outcome`)
SELECT e.`event_id`, p.`participant_id`, UTC_TIMESTAMP() - INTERVAL t.`minutes` MINUTE, u.`user_id`, 'Waiting'
  FROM (SELECT 'P2' AS `code`, 48 AS `minutes`
        UNION ALL SELECT 'P7', 36
        UNION ALL SELECT 'P18', 25
        UNION ALL SELECT 'P9', 14
        UNION ALL SELECT 'P13', 4) t
  JOIN `participant` p ON p.`participant_code` = t.`code`
  JOIN `distribution_event` e ON e.`site_id` = p.`home_site_id` AND e.`status` = 'Open' AND e.`notes` = 'Dev seed event, open today'
  JOIN `user_account` u ON u.`username` = 'system'
 WHERE NOT EXISTS (SELECT 1 FROM `event_check_in` x WHERE x.`event_id` = e.`event_id` AND x.`participant_id` = p.`participant_id`);
