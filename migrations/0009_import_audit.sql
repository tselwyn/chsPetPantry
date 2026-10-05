-- Migration 0009 (v2.1): imports.
-- UC-11 3.2.4 roster import and US-32 templates per record type ('User'); background validation
-- for large files (UC-12 4.4: 'Queued', 'Running'); batch rollback (UC-12 3.2.4) needs to find the
-- audit entries a batch wrote.

ALTER TABLE `import_batch`
  MODIFY `record_type` ENUM('Participant','Pet','Distribution','User') NOT NULL,
  MODIFY `status` ENUM('Validated','Committed','Rolled Back','Failed','Queued','Running') NOT NULL;

ALTER TABLE `import_mapping`
  MODIFY `record_type` ENUM('Participant','Pet','Distribution','User') NOT NULL;

ALTER TABLE `audit_log`
  ADD COLUMN `import_batch_id` INT NULL AFTER `entity_id`,
  ADD KEY `ix_audit_log_4` (`import_batch_id`),
  ADD CONSTRAINT `fk_audit_log_import_batch_id` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batch` (`batch_id`);
