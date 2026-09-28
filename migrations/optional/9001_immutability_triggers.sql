-- Optional migration 9001: database-level immutability guard (docs/PFPMS_schema_v2_notes.md §3).
--
-- This is a development safety net, not the real control. The real controls are the app-layer
-- guard (only allowlisted services may UPDATE or DELETE these tables) and the CI grep.
-- On hosts with binary logging on and no SUPER privilege (SiteGround), CREATE TRIGGER fails with
-- ERROR 1419, and bin/migrate.php records this migration as Skipped.
--
-- Allowlisted services (import rollback, erasure, retention purge) set @pfpms_allow_mutation = 1
-- for their statement and reset it in a finally block.
-- Dumps: use mysqldump --skip-triggers and re-run this migration, because triggers carry a DEFINER.

DELIMITER //

CREATE TRIGGER `trg_distribution_no_update` BEFORE UPDATE ON `distribution` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'distribution rows are immutable; record a reversal instead';
  END IF;
END//

CREATE TRIGGER `trg_distribution_no_delete` BEFORE DELETE ON `distribution` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'distribution rows are immutable';
  END IF;
END//

CREATE TRIGGER `trg_distribution_line_no_update` BEFORE UPDATE ON `distribution_line` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'distribution_line rows are immutable';
  END IF;
END//

CREATE TRIGGER `trg_distribution_line_no_delete` BEFORE DELETE ON `distribution_line` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'distribution_line rows are immutable';
  END IF;
END//

CREATE TRIGGER `trg_distribution_pet_no_update` BEFORE UPDATE ON `distribution_pet` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'distribution_pet rows are immutable';
  END IF;
END//

CREATE TRIGGER `trg_distribution_pet_no_delete` BEFORE DELETE ON `distribution_pet` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'distribution_pet rows are immutable';
  END IF;
END//

CREATE TRIGGER `trg_audit_log_no_update` BEFORE UPDATE ON `audit_log` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log rows are immutable';
  END IF;
END//

CREATE TRIGGER `trg_audit_log_no_delete` BEFORE DELETE ON `audit_log` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log rows are immutable';
  END IF;
END//

CREATE TRIGGER `trg_audit_field_change_no_update` BEFORE UPDATE ON `audit_field_change` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_field_change rows are immutable';
  END IF;
END//

CREATE TRIGGER `trg_audit_field_change_no_delete` BEFORE DELETE ON `audit_field_change` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_field_change rows are immutable';
  END IF;
END//

CREATE TRIGGER `trg_snv_status_log_no_update` BEFORE UPDATE ON `snv_referral_status_log` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'snv_referral_status_log rows are immutable';
  END IF;
END//

CREATE TRIGGER `trg_snv_status_log_no_delete` BEFORE DELETE ON `snv_referral_status_log` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'snv_referral_status_log rows are immutable';
  END IF;
END//

CREATE TRIGGER `trg_inventory_transaction_no_update` BEFORE UPDATE ON `inventory_transaction` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory_transaction rows are immutable';
  END IF;
END//

CREATE TRIGGER `trg_inventory_transaction_no_delete` BEFORE DELETE ON `inventory_transaction` FOR EACH ROW
BEGIN
  IF COALESCE(@pfpms_allow_mutation, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory_transaction rows are immutable';
  END IF;
END//

DELIMITER ;
