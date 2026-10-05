-- Migration 0002 (v2.1): system settings the use cases require but v2 did not seed.
-- Values are defaults for review by the client; Administrators change them in the settings screen.

INSERT INTO `system_setting` (`setting_key`, `setting_value`, `description`) VALUES
  -- Organisation and login (UC-01, UC-11)
  ('organisation_name', 'CHS Pet Pantry', 'Name shown on the login screen, receipts and vouchers (UC-01)'),
  ('default_language', 'en', 'Fallback language for policy text, receipts and vouchers (US-23)'),
  ('lockout_minutes', '15', 'How long an account stays locked after max_failed_logins (UC-01 3.3.1)'),
  ('session_absolute_hours', '12', 'Absolute session lifetime regardless of activity (UC-01 4.2)'),
  ('password_min_length', '12', 'Minimum password length (UC-01 4.1)'),
  ('password_max_age_days', '0', 'Force a password change after this many days; 0 = never (UC-01 4.1)'),
  ('password_hibp_check', '0', '1 = also check new passwords against the online breached-password API (UC-01 4.1)'),
  ('reset_link_minutes', '60', 'Lifetime of a password-reset link (UC-01 3.2.1)'),
  ('temp_credential_hours', '72', 'Lifetime of a temporary credential issued by an Administrator (UC-11 4.2)'),
  ('trusted_device_days', '30', 'Remember-this-device lifetime (UC-01 3.2.3)'),
  ('pin_min_digits', '4', 'Shortest allowed PIN for fast switching (US-01)'),
  ('pin_max_digits', '6', 'Longest allowed PIN for fast switching (US-01)'),
  ('pin_max_failed', '3', 'Wrong PINs before a password is required (US-01)'),
  ('pin_shift_hours', '12', 'PIN switching works only within this many hours of a password login on the device (US-01)'),
  ('volunteer_max_sites', '2', 'Most sites a Volunteer may be assigned (UC-11 3.3.2)'),
  ('account_review_days', '90', 'Accounts with no login for this long appear on the access review (UC-11 4)'),
  ('policy_reack_days', '365', 'Re-acknowledge the confidentiality agreement after this many days (US-30)'),
  ('policy_reack_grace_days', '30', 'Grace period before an overdue re-acknowledgement restricts the account (US-30)'),
  -- Offline Station (UC-01 3.3.3, UC-06 3.2.5 and 4.3)
  ('offline_mode_enabled', '1', '1 = registered tablets may work offline'),
  ('offline_grant_hours', '72', 'Longest a user may work offline after an online login'),
  ('offline_pack_ttl_hours', '72', 'Offline participant data on a tablet is wiped after this age (unsynced records are kept)'),
  ('offline_cache_days', '90', 'Households served within this many days are included in the offline pack (UC-02 3.3.3)'),
  ('offline_max_participants', '5000', 'Upper limit on households in one offline pack'),
  ('offline_max_failed_unlocks', '10', 'Failed offline unlocks before the tablet wipes its data (unsynced records are kept)'),
  ('offline_pbkdf2_iterations', '600000', 'Default key-derivation cost; calibrated per device at registration'),
  ('sync_clock_skew_minutes', '10', 'Tolerated difference between tablet and server clocks'),
  ('late_sync_grace_days', '7', 'Days after an event closes during which late or paper-slip entries are accepted'),
  ('sync_payload_retention_days', '90', 'Encrypted sync payloads are purged after this many days'),
  ('station_poll_seconds', '10', 'How often tablets refresh the check-in queue and dashboard (US-04, US-18)'),
  -- Operations (UC-02 to UC-10)
  ('search_max_results', '100', 'Most participants shown for one search (UC-02 3.3.2)'),
  ('recent_participants_days', '30', 'An empty search lists households served here within this many days (UC-02 3.2.2)'),
  ('history_page_size', '25', 'Distributions per page in the history view (UC-07 4.2)'),
  ('photo_max_mb', '5', 'Largest stored pet photo in megabytes (UC-05 4.4)'),
  ('over_allotment_auth_level', 'Administrator', 'Lowest role that may authorise an over-allotment issue (UC-06 4.5)'),
  ('emergency_auth_level', 'Administrator', 'Lowest role that may authorise an emergency distribution (UC-06 3.2.2)'),
  ('programme_year_start', '01-01', 'First day (MM-DD) of the programme year for year-to-date totals (UC-07, UC-14)'),
  ('volunteer_draft_days', '14', 'Unfinished registration drafts are discarded after this many days (US-08)'),
  ('self_service_draft_hours', '24', 'Unclaimed self-service pre-registrations are discarded after this many hours (US-07)'),
  ('voucher_reminder_days', '14', 'Send the voucher expiry reminder this many days before expiry (UC-08)'),
  ('flag_review_days', '180', 'Flags older than this are highlighted for review (UC-15 3.2.4)'),
  ('welfare_prompt_multiplier', '2.0', 'Prompt when the gap since the last visit exceeds the household''s usual gap times this (US-20)'),
  ('pet_presence_review_days', '365', 'Pets not confirmed present for this long appear on the review list (US-27)'),
  ('size_review_juvenile_months', '12', 'Pets younger than this at registration are checked for a size-band change (US-15)'),
  -- Import, reports and retention (UC-07, UC-12, UC-13)
  ('import_max_file_mb', '25', 'Largest import file in megabytes (UC-12 4.4)'),
  ('import_max_rows', '50000', 'Most rows in one import file (UC-12 4.4)'),
  ('import_max_rows_sync', '5000', 'Imports up to this many rows are validated in the browser request; larger ones run in the background'),
  ('import_max_reject_pct', '20', 'No commit is offered when more than this percentage of rows is rejected (UC-12 3.3.3)'),
  ('report_max_span_months', '36', 'Longest date range for one report (UC-13 3.3.2)'),
  ('report_interactive_seconds', '30', 'Reports expected to run longer are queued in the background (UC-13 4.2)'),
  ('report_result_retention_days', '30', 'Background report results are kept this long (UC-13 3.2.4)'),
  ('distribution_retention_years', '7', 'Distribution history is kept at least this long (UC-07 4.4)'),
  ('auth_audit_retention_months', '12', 'Login audit entries are kept at least this long (UC-01 4.5)'),
  ('audit_statutory_retention_years', '7', 'Field-level audit is kept this long beyond the life of the record (UC-04 4.1)'),
  ('litters_prevented_citation', '', 'Source cited for the litters-prevented multiplier (US-40)');

UPDATE `system_setting`
   SET `description` = 'Number of litters prevented per completed surgery, used by the US-40 estimate. Numeric; the citation is in litters_prevented_citation.'
 WHERE `setting_key` = 'litters_prevented_multiplier';
