-- Migration 0010 (v2.1): the organisation's own time zone (UC-11 review).
-- Organisation-wide dates belong to no single site, so "today" for them is taken in this zone:
-- account start, end and deactivation dates, policy start dates and re-acceptance due dates.
-- Site-level dates keep using site.time_zone.

INSERT INTO `system_setting` (`setting_key`, `setting_value`, `description`) VALUES
  ('organisation_time_zone', 'America/New_York', 'IANA time zone for organisation-wide dates: account start, end and deactivation dates, the day a policy text takes effect, and when staff must accept a policy again (UC-11, US-03, US-30)');
