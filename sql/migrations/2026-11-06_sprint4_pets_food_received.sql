-- Sprint 4: pet statuses, special medical needs, and food received
--
-- Updates an existing local foodpantrydb to match sql/foodpantrydb.sql,
-- without re-importing the whole database.
-- In phpMyAdmin: select foodpantrydb, open the SQL tab, paste this file, Go.
-- Run it once, after sql/migrations/2026-10-29_client_feedback.sql.

-- 1. Pet status adds Rehomed and Deceased (UC-05, FR-030). Inactive stays in
--    the list so pets already marked Inactive keep their status; Edit Pet
--    lets the user move them to Rehomed or Deceased.
ALTER TABLE `dbpets`
  MODIFY `status` enum('Active','Inactive','Rehomed','Deceased','Deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active';

-- 2. Special medical needs on each pet (UC-05, FR-027). Optional.
ALTER TABLE `dbpets`
  ADD COLUMN `special_medical_needs` text COLLATE utf8mb4_unicode_ci AFTER `food_restriction`;

-- 3. Food received, for the cost per pound report (UC-17, FR-072, FR-073).
CREATE TABLE IF NOT EXISTS `dbfoodreceived` (
  `id` int NOT NULL AUTO_INCREMENT,
  `received_date` date NOT NULL,
  `food_type` enum('Dog','Cat') COLLATE utf8mb4_unicode_ci NOT NULL,
  `pounds` decimal(8,2) NOT NULL,
  `cost` decimal(10,2) NOT NULL DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `recorded_by` varchar(256) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `received_date` (`received_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
