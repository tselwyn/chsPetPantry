-- Development seed data: sample tablets at Dev Site North in the states only Phase 2B can reach
-- (registered, reporting, retiring), so admin_devices has something to show before the Station
-- exists. The pages show the flags exactly as seeded. DEVELOPMENT ONLY: each credential hash is the
-- SHA-256 of a known string, so on a dev or test database anyone could present it; these rows have no
-- vault key, so a P2B tablet in service without one gets no key and works online only.
-- Safe to run repeatedly: a tablet is added only when neither its credential hash nor its name among
-- the site's current tablets is there yet.

INSERT INTO `device` (`site_id`, `label`, `is_site_registered`, `registered_by`, `registered_at`, `token_hash`, `offline_enabled`, `storage_persisted`,
                      `last_seen_at`, `last_sync_at`, `pending_count`, `app_build`, `revoked_at`, `revoked_by`, `wipe_mode`)
SELECT s.`site_id`, t.`label`, t.`registered`, u.`user_id`, UTC_TIMESTAMP() - INTERVAL 30 DAY, SHA2(CONCAT('pfpms-dev-device:', t.`label`), 256),
       t.`offline`, t.`persisted`, CASE WHEN t.`seen_min` IS NULL THEN NULL ELSE UTC_TIMESTAMP() - INTERVAL t.`seen_min` MINUTE END,
       CASE WHEN t.`seen_min` IS NULL THEN NULL ELSE UTC_TIMESTAMP() - INTERVAL t.`seen_min` MINUTE END,
       t.`pending`, t.`build`, CASE WHEN t.`retired_min` IS NULL THEN NULL ELSE UTC_TIMESTAMP() - INTERVAL t.`retired_min` MINUTE END,
       CASE WHEN t.`retired_min` IS NULL THEN NULL ELSE u.`user_id` END, t.`wipe`
  FROM (SELECT 'Front desk 1' AS `label`, 1 AS `registered`, 1 AS `offline`, 1 AS `persisted`, 5 AS `seen_min`, 3 AS `pending`, '0.1.0-dev' AS `build`,
               NULL AS `retired_min`, 'None' AS `wipe`
        UNION ALL SELECT 'Front desk 2', 1, 0, 0, NULL, 0, NULL, NULL, 'None'
        UNION ALL SELECT 'Intake table', 1, 0, 0, 2880, 12, '0.0.9', NULL, 'None'
        UNION ALL SELECT 'Spare tablet', 0, 0, 1, 1500, 2, '0.1.0-dev', 1440, 'Push Then Wipe') t
  JOIN `site` s ON s.`name` = 'Dev Site North'
  JOIN `user_account` u ON u.`username` = 'system'
 WHERE NOT EXISTS (SELECT 1 FROM `device` d WHERE d.`token_hash` = SHA2(CONCAT('pfpms-dev-device:', t.`label`), 256))
   AND NOT EXISTS (SELECT 1 FROM `device` x WHERE x.`site_id` = s.`site_id` AND x.`label` = t.`label` AND x.`revoked_at` IS NULL AND x.`wiped_at` IS NULL);
