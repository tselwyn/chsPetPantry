-- Development seed data: 30 fake participants across the two dev sites, for participant search (UC-02) and the
-- participant pages. All names, phones and addresses are invented. Includes accented names and their unaccented
-- twins (José Muñoz / Jose Munoz, María García / Maria Garcia: US-06), preferred names (US-05), sound-alike surnames
-- (Smith / Smyth), a household registered at both sites, an Inactive and a Deleted participant, pets, closed past
-- events and distributions. Dates are relative to the day the seed is loaded.
-- surname_phonetic is Fold::phonetic(legal_last_name) (src/Text/Fold.php, the twin of the Station's js/fold.js);
-- tests/Integration/Participant/SeedParticipantsTest.php checks every row, so edit names and keys together.
-- Codes P1-P30 are taken here, so id_sequence is moved past them and registration starts at P31.
-- Safe to run repeatedly: rows are added only when missing (participants by code, pets by owner and name, events
-- and distributions by their seed markers), so nothing changed through the app is touched.

INSERT INTO `participant` (`participant_code`, `legal_first_name`, `legal_last_name`, `preferred_name`, `surname_phonetic`, `street_address`, `city`,
                           `state`, `postal_code`, `phone`, `preferred_language_code`, `household_size`, `declared_pet_count`, `consent_to_contact`,
                           `status`, `status_reason`, `status_before_delete`, `home_site_id`, `registration_site_id`, `registered_by`, `registered_at`,
                           `last_distribution_date`, `next_eligible_date`, `deleted_at`, `deleted_by`, `delete_reason`, `restorable_until`)
SELECT p.`code`, p.`first`, p.`last`, p.`preferred`, p.`phonetic`, p.`street`, 'Charleston', 'SC', p.`zip`, p.`phone`, p.`lang`, p.`household`, NULL, 1,
       p.`status`, CASE p.`status` WHEN 'Inactive' THEN 'Moved out of the area (dev seed)' WHEN 'Deleted' THEN 'Duplicate record (dev seed)' END,
       CASE WHEN p.`status` = 'Deleted' THEN 'Active' END, s.`site_id`, s.`site_id`, u.`user_id`, UTC_TIMESTAMP() - INTERVAL p.`reg_days` DAY,
       CASE WHEN p.`last_days` IS NOT NULL THEN UTC_DATE() - INTERVAL p.`last_days` DAY END,
       CASE WHEN p.`last_days` IS NOT NULL THEN UTC_DATE() - INTERVAL p.`last_days` DAY + INTERVAL 14 DAY END,
       CASE WHEN p.`status` = 'Deleted' THEN UTC_TIMESTAMP() - INTERVAL 5 DAY END, CASE WHEN p.`status` = 'Deleted' THEN u.`user_id` END,
       CASE WHEN p.`status` = 'Deleted' THEN 'Duplicate record (dev seed)' END, CASE WHEN p.`status` = 'Deleted' THEN UTC_DATE() + INTERVAL 85 DAY END
  FROM (
        SELECT 'P1' AS `code`, 'José' AS `first`, 'Muñoz' AS `last`, NULL AS `preferred`, 'M520' AS `phonetic`, '12 King Street' AS `street`, '29401' AS `zip`, '8435550101' AS `phone`, 2 AS `household`, 'es' AS `lang`, 'Active' AS `status`, 'Dev Site North' AS `site`, 400 AS `reg_days`, 3 AS `last_days`
        UNION ALL SELECT 'P2', 'Maria', 'Garcia', NULL, 'G620', '48 Meeting Street', '29401', '8435550102', 3, 'en', 'Active', 'Dev Site North', 391, 3
        UNION ALL SELECT 'P3', 'María', 'García', 'Mari', 'G620', '7 Calhoun Street', '29403', '8435550103', 1, 'es', 'Active', 'Dev Site North', 382, 17
        UNION ALL SELECT 'P4', 'Jose', 'Munoz', NULL, 'M520', '301 Rutledge Avenue', '29403', '8435550104', 2, 'en', 'Active', 'Dev Site North', 373, 31
        UNION ALL SELECT 'P5', 'Robert', 'Smith', 'Bobby', 'S530', '15 Spring Street', '29403', '8435550105', 2, 'en', 'Active', 'Dev Site North', 364, 3
        UNION ALL SELECT 'P6', 'Elizabeth', 'Johnson', 'Liz', 'J525', '22 Line Street', '29403', '8435550106', 1, 'en', 'Active', 'Dev Site North', 355, 17
        UNION ALL SELECT 'P7', 'William', 'Brown', 'Bill', 'B650', '9 Cannon Street', '29403', '8435550107', 4, 'en', 'Active', 'Dev Site North', 346, NULL
        UNION ALL SELECT 'P8', 'Katherine', 'O''Brien', 'Kate', 'O165', '60 Bull Street', '29401', '8435550108', 2, 'en', 'Active', 'Dev Site North', 337, 3
        UNION ALL SELECT 'P9', 'Zoë', 'Peña', NULL, 'P500', '5 Vanderhorst Street', '29401', '8435550109', 1, 'es', 'Active', 'Dev Site North', 328, 45
        UNION ALL SELECT 'P10', 'Chloé', 'Lefèvre', NULL, 'L116', '18 Wentworth Street', '29401', '8435550110', 2, 'en', 'Active', 'Dev Site North', 319, 17
        UNION ALL SELECT 'P11', 'Thanh', 'Nguyễn', 'Tom', 'N250', '140 Ashley Avenue', '29403', '8435550111', 5, 'en', 'Active', 'Dev Site North', 310, 3
        UNION ALL SELECT 'P12', 'Michael', 'Smyth', NULL, 'S530', '33 Smith Street', '29401', '8435550112', 1, 'en', 'Active', 'Dev Site North', 301, 31
        UNION ALL SELECT 'P13', 'Sarah', 'Johnston', NULL, 'J523', '2 Pitt Street', '29401', '8435550113', 3, 'en', 'Active', 'Dev Site North', 292, NULL
        UNION ALL SELECT 'P14', 'Dorothy', 'Washington', 'Dot', 'W252', '77 America Street', '29403', '8435550114', 2, 'en', 'Active', 'Dev Site North', 283, 3
        UNION ALL SELECT 'P15', 'James', 'Williams', 'Jim', 'W452', '11 Coming Street', '29401', '8435550115', 1, 'en', 'Inactive', 'Dev Site North', 274, NULL
        UNION ALL SELECT 'P16', 'Patricia', 'Davis', 'Pat', 'D120', '90 Morris Street', '29403', '8435550116', 2, 'en', 'Deleted', 'Dev Site North', 265, NULL
        UNION ALL SELECT 'P17', 'Linda', 'Martínez', NULL, 'M635', '4 Radcliffe Street', '29403', '8435550117', 3, 'es', 'Active', 'Dev Site North', 256, 17
        UNION ALL SELECT 'P18', 'Ángel', 'Hernández', NULL, 'H655', '210 Huger Street', '29403', '8435550118', 4, 'es', 'Active', 'Dev Site North', 247, 3
        UNION ALL SELECT 'P19', 'Mary', 'Smith', NULL, 'S530', '1500 Savannah Highway', '29407', '8435550119', 2, 'en', 'Active', 'Dev Site South', 238, 10
        UNION ALL SELECT 'P20', 'Daniel', 'Rodríguez', 'Danny', 'R362', '45 Folly Road', '29407', '8435550120', 4, 'es', 'Active', 'Dev Site South', 229, 10
        UNION ALL SELECT 'P21', 'Susan', 'Miller', 'Sue', 'M460', '820 Wappoo Road', '29407', '8435550121', 1, 'en', 'Active', 'Dev Site South', 220, 24
        UNION ALL SELECT 'P22', 'Joseph', 'Wilson', 'Joe', 'W425', '6 Sam Rittenberg Boulevard', '29407', '8435550122', 2, 'en', 'Active', 'Dev Site South', 211, 10
        UNION ALL SELECT 'P23', 'Nancy', 'Moore', NULL, 'M600', '19 Orleans Road', '29407', '8435550123', 1, 'en', 'Active', 'Dev Site South', 202, NULL
        UNION ALL SELECT 'P24', 'Christopher', 'Taylor', 'Chris', 'T460', '300 Ashley River Road', '29407', '8435550124', 3, 'en', 'Active', 'Dev Site South', 193, 10
        UNION ALL SELECT 'P25', 'Karen', 'Anderson', NULL, 'A536', '72 Magnolia Road', '29407', '8435550125', 2, 'en', 'Active', 'Dev Site South', 184, 38
        UNION ALL SELECT 'P26', 'Matthew', 'Thomas', 'Matt', 'T520', '14 Riverland Drive', '29412', '8435550126', 1, 'en', 'Active', 'Dev Site South', 175, 24
        UNION ALL SELECT 'P27', 'Lisa', 'Jackson', NULL, 'J250', '501 Maybank Highway', '29412', '8435550127', 2, 'en', 'Active', 'Dev Site South', 166, 10
        UNION ALL SELECT 'P28', 'Steven', 'White', 'Steve', 'W300', '88 Camp Road', '29412', '8435550128', 1, 'en', 'Active', 'Dev Site South', 157, NULL
        UNION ALL SELECT 'P29', 'Betty', 'Harris', NULL, 'H620', '3 Fort Johnson Road', '29412', '8435550129', 2, 'en', 'Active', 'Dev Site South', 148, 38
        UNION ALL SELECT 'P30', 'François', 'Dubois', NULL, 'D120', '27 Central Park Road', '29412', '8435550130', 1, 'en', 'Active', 'Dev Site South', 139, 10) p
  JOIN `site` s ON s.`name` = p.`site`
  JOIN `user_account` u ON u.`username` = 'system'
 WHERE NOT EXISTS (SELECT 1 FROM `participant` x WHERE x.`participant_code` = p.`code`);

UPDATE `id_sequence` SET `next_value` = GREATEST(`next_value`, 31) WHERE `seq_name` = 'participant';

INSERT INTO `participant_site` (`participant_id`, `site_id`, `added_at`, `added_by`)
SELECT p.`participant_id`, s.`site_id`, p.`registered_at`, u.`user_id`
  FROM (
        SELECT 'P1' AS `code`, 'Dev Site North' AS `site`
        UNION ALL SELECT 'P2', 'Dev Site North'
        UNION ALL SELECT 'P3', 'Dev Site North'
        UNION ALL SELECT 'P4', 'Dev Site North'
        UNION ALL SELECT 'P5', 'Dev Site North'
        UNION ALL SELECT 'P6', 'Dev Site North'
        UNION ALL SELECT 'P7', 'Dev Site North'
        UNION ALL SELECT 'P8', 'Dev Site North'
        UNION ALL SELECT 'P9', 'Dev Site North'
        UNION ALL SELECT 'P10', 'Dev Site North'
        UNION ALL SELECT 'P11', 'Dev Site North'
        UNION ALL SELECT 'P12', 'Dev Site North'
        UNION ALL SELECT 'P13', 'Dev Site North'
        UNION ALL SELECT 'P14', 'Dev Site North'
        UNION ALL SELECT 'P15', 'Dev Site North'
        UNION ALL SELECT 'P16', 'Dev Site North'
        UNION ALL SELECT 'P17', 'Dev Site North'
        UNION ALL SELECT 'P18', 'Dev Site North'
        UNION ALL SELECT 'P19', 'Dev Site South'
        UNION ALL SELECT 'P20', 'Dev Site South'
        UNION ALL SELECT 'P21', 'Dev Site South'
        UNION ALL SELECT 'P22', 'Dev Site South'
        UNION ALL SELECT 'P23', 'Dev Site South'
        UNION ALL SELECT 'P24', 'Dev Site South'
        UNION ALL SELECT 'P25', 'Dev Site South'
        UNION ALL SELECT 'P26', 'Dev Site South'
        UNION ALL SELECT 'P27', 'Dev Site South'
        UNION ALL SELECT 'P28', 'Dev Site South'
        UNION ALL SELECT 'P29', 'Dev Site South'
        UNION ALL SELECT 'P30', 'Dev Site South'
        UNION ALL SELECT 'P18', 'Dev Site South') t
  JOIN `participant` p ON p.`participant_code` = t.`code`
  JOIN `site` s ON s.`name` = t.`site`
  JOIN `user_account` u ON u.`username` = 'system'
 WHERE NOT EXISTS (SELECT 1 FROM `participant_site` x WHERE x.`participant_id` = p.`participant_id` AND x.`site_id` = s.`site_id`);

INSERT INTO `pet` (`participant_id`, `name`, `species_id`, `size_band_id`, `sex`, `status`, `inactivated_by_owner_delete`, `created_by`, `created_at`)
SELECT p.`participant_id`, t.`name`, sp.`species_id`, sb.`size_band_id`, t.`sex`, t.`status`, t.`by_delete`, u.`user_id`, p.`registered_at`
  FROM (
        SELECT 'P1' AS `code`, 'Rocky' AS `name`, 'Dog' AS `species`, 'Medium' AS `band`, 'Male' AS `sex`, 'Active' AS `status`, 0 AS `by_delete`
        UNION ALL SELECT 'P1', 'Luna', 'Cat', 'Standard', 'Female', 'Active', 0
        UNION ALL SELECT 'P2', 'Bella', 'Dog', 'Small', 'Female', 'Active', 0
        UNION ALL SELECT 'P3', 'Misu', 'Cat', 'Small', 'Female', 'Active', 0
        UNION ALL SELECT 'P4', 'Max', 'Dog', 'Large', 'Male', 'Active', 0
        UNION ALL SELECT 'P5', 'Duke', 'Dog', 'Giant', 'Male', 'Active', 0
        UNION ALL SELECT 'P5', 'Daisy', 'Dog', 'Toy', 'Female', 'Active', 0
        UNION ALL SELECT 'P6', 'Oliver', 'Cat', 'Standard', 'Male', 'Active', 0
        UNION ALL SELECT 'P7', 'Buddy', 'Dog', 'Medium', 'Male', 'Active', 0
        UNION ALL SELECT 'P8', 'Smokey', 'Cat', 'Standard', 'Male', 'Active', 0
        UNION ALL SELECT 'P8', 'Tiger', 'Cat', 'Large', 'Male', 'Active', 0
        UNION ALL SELECT 'P9', 'Coco', 'Dog', 'Toy', 'Female', 'Active', 0
        UNION ALL SELECT 'P10', 'Minou', 'Cat', 'Small', 'Female', 'Active', 0
        UNION ALL SELECT 'P11', 'Lucky', 'Dog', 'Medium', 'Male', 'Active', 0
        UNION ALL SELECT 'P11', 'Mimi', 'Cat', 'Standard', 'Female', 'Active', 0
        UNION ALL SELECT 'P11', 'Kiki', 'Cat', 'Small', 'Female', 'Active', 0
        UNION ALL SELECT 'P12', 'Rex', 'Dog', 'Large', 'Male', 'Active', 0
        UNION ALL SELECT 'P13', 'Molly', 'Dog', 'Small', 'Female', 'Active', 0
        UNION ALL SELECT 'P14', 'Shadow', 'Cat', 'Large', 'Male', 'Active', 0
        UNION ALL SELECT 'P15', 'Sam', 'Dog', 'Medium', 'Male', 'Active', 0
        UNION ALL SELECT 'P16', 'Ginger', 'Cat', 'Standard', 'Female', 'Inactive', 1
        UNION ALL SELECT 'P17', 'Canela', 'Dog', 'Small', 'Female', 'Active', 0
        UNION ALL SELECT 'P17', 'Nieve', 'Cat', 'Standard', 'Female', 'Active', 0
        UNION ALL SELECT 'P18', 'Toby', 'Dog', 'Medium', 'Male', 'Active', 0
        UNION ALL SELECT 'P19', 'Pepper', 'Dog', 'Small', 'Female', 'Active', 0
        UNION ALL SELECT 'P20', 'Bruno', 'Dog', 'Large', 'Male', 'Active', 0
        UNION ALL SELECT 'P20', 'Lola', 'Dog', 'Small', 'Female', 'Active', 0
        UNION ALL SELECT 'P21', 'Whiskers', 'Cat', 'Standard', 'Male', 'Active', 0
        UNION ALL SELECT 'P22', 'Bear', 'Dog', 'Giant', 'Male', 'Active', 0
        UNION ALL SELECT 'P23', 'Patches', 'Cat', 'Small', 'Female', 'Active', 0
        UNION ALL SELECT 'P24', 'Zeus', 'Dog', 'Large', 'Male', 'Active', 0
        UNION ALL SELECT 'P24', 'Athena', 'Cat', 'Standard', 'Female', 'Active', 0
        UNION ALL SELECT 'P25', 'Biscuit', 'Dog', 'Toy', 'Male', 'Active', 0
        UNION ALL SELECT 'P26', 'Jasper', 'Cat', 'Large', 'Male', 'Active', 0
        UNION ALL SELECT 'P27', 'Rosie', 'Dog', 'Medium', 'Female', 'Active', 0
        UNION ALL SELECT 'P28', 'Oreo', 'Cat', 'Standard', 'Male', 'Active', 0
        UNION ALL SELECT 'P29', 'Penny', 'Dog', 'Small', 'Female', 'Active', 0
        UNION ALL SELECT 'P29', 'Sox', 'Cat', 'Small', 'Male', 'Active', 0
        UNION ALL SELECT 'P30', 'Filou', 'Cat', 'Standard', 'Male', 'Active', 0) t
  JOIN `participant` p ON p.`participant_code` = t.`code`
  JOIN `species` sp ON sp.`name` = t.`species`
  JOIN `size_band` sb ON sb.`species_id` = sp.`species_id` AND sb.`name` = t.`band`
  JOIN `user_account` u ON u.`username` = 'system'
 WHERE NOT EXISTS (SELECT 1 FROM `pet` x WHERE x.`participant_id` = p.`participant_id` AND x.`name` = t.`name`);

INSERT INTO `distribution_event` (`site_id`, `event_date`, `starts_at`, `ends_at`, `status`, `opened_by`, `notes`)
SELECT s.`site_id`, UTC_DATE() - INTERVAL t.`days` DAY, '09:00:00', '12:00:00', 'Closed', u.`user_id`, CONCAT('Dev seed event, ', t.`days`, ' days before seeding')
  FROM (
        SELECT 'Dev Site North' AS `site`, 3 AS `days`
        UNION ALL SELECT 'Dev Site North', 17
        UNION ALL SELECT 'Dev Site North', 31
        UNION ALL SELECT 'Dev Site North', 45
        UNION ALL SELECT 'Dev Site South', 10
        UNION ALL SELECT 'Dev Site South', 24
        UNION ALL SELECT 'Dev Site South', 38) t
  JOIN `site` s ON s.`name` = t.`site`
  JOIN `user_account` u ON u.`username` = 'system'
 WHERE NOT EXISTS (SELECT 1 FROM `distribution_event` x WHERE x.`site_id` = s.`site_id` AND x.`notes` = CONCAT('Dev seed event, ', t.`days`, ' days before seeding'));

INSERT INTO `distribution` (`client_uuid`, `event_id`, `participant_id`, `recorded_by`, `distributed_at`, `local_date`, `pets_served`, `entitled_lbs`,
                            `allotment_rule_version`, `frequency_days_applied`, `receipt_sent_via`)
SELECT t.`uuid`, e.`event_id`, p.`participant_id`, u.`user_id`, TIMESTAMP(e.`event_date`, '14:30:00'), e.`event_date`, t.`pets`, t.`lbs`, 1, 14, 'None'
  FROM (
        SELECT '5eed0005-0000-4000-8000-000000000001' AS `uuid`, 'P1' AS `code`, 3 AS `days`, 2 AS `pets`, 10.50 AS `lbs`
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000002', 'P1', 17, 2, 10.50
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000003', 'P1', 31, 2, 10.50
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000004', 'P2', 3, 1, 4.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000005', 'P2', 17, 1, 4.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000006', 'P3', 17, 1, 1.50
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000007', 'P4', 31, 1, 12.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000008', 'P5', 3, 2, 18.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000009', 'P6', 17, 1, 2.50
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000010', 'P6', 45, 1, 2.50
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000011', 'P8', 3, 2, 6.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000012', 'P8', 31, 2, 6.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000013', 'P9', 45, 1, 2.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000014', 'P10', 17, 1, 1.50
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000015', 'P11', 3, 3, 12.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000016', 'P11', 17, 3, 12.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000017', 'P11', 31, 3, 12.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000018', 'P11', 45, 3, 12.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000019', 'P12', 31, 1, 12.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000020', 'P14', 3, 1, 3.50
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000021', 'P17', 17, 2, 6.50
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000022', 'P18', 3, 1, 8.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000023', 'P18', 31, 1, 8.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000024', 'P19', 10, 1, 4.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000025', 'P20', 10, 2, 16.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000026', 'P20', 24, 2, 16.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000027', 'P21', 24, 1, 2.50
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000028', 'P22', 10, 1, 16.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000029', 'P22', 38, 1, 16.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000030', 'P24', 10, 2, 14.50
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000031', 'P25', 38, 1, 2.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000032', 'P26', 24, 1, 3.50
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000033', 'P27', 10, 1, 8.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000034', 'P27', 24, 1, 8.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000035', 'P27', 38, 1, 8.00
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000036', 'P29', 38, 2, 5.50
        UNION ALL SELECT '5eed0005-0000-4000-8000-000000000037', 'P30', 10, 1, 2.50) t
  JOIN `participant` p ON p.`participant_code` = t.`code`
  JOIN `distribution_event` e ON e.`site_id` = p.`home_site_id` AND e.`notes` = CONCAT('Dev seed event, ', t.`days`, ' days before seeding')
  JOIN `user_account` u ON u.`username` = 'system'
 WHERE NOT EXISTS (SELECT 1 FROM `distribution` x WHERE x.`client_uuid` = t.`uuid`);
