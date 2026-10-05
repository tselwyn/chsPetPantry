-- Migration 0007 (v2.1): a declined spay/neuter offer (UC-08 3.2.3) is recorded without using up a
-- voucher number (UC-08 4.1: numbers are unique for life and never reissued) and without an expiry.
-- The unique key on voucher_number stays; both engines allow many NULLs in a unique key.

ALTER TABLE `snv_referral`
  MODIFY `voucher_number` VARCHAR(20) NULL,
  MODIFY `expires_on` DATE NULL;
