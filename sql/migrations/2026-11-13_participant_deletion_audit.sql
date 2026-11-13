-- CHS Pet Pantry
-- Participant deletion audit (SRS UC-09, NFR-SEC-007)
--
-- One row each time an Administrator deletes a participant: which participant,
-- their status just before, the account used (logins are shared, so this is
-- the shared account, not a person) and when. Deleting a participant only sets
-- dbparticipants.status to 'Deleted'; the participant row itself is kept.
--
-- Optional: the app creates this table itself the first time a participant is
-- deleted (ensure_participant_audit_table in database/dbParticipants.php).
-- Running it in phpMyAdmin first is also fine. Safe to run again: it does
-- nothing if the table already exists.

CREATE TABLE IF NOT EXISTS `dbparticipantaudit` (
  `id` int NOT NULL AUTO_INCREMENT,
  `participant_id` int NOT NULL,
  `action` varchar(30) NOT NULL,
  `previous_status` varchar(20) DEFAULT NULL,
  `account_id` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_participant` (`participant_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
