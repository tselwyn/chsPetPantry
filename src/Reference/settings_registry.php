<?php
declare(strict_types=1);

/*
 * Typed registry of every system_setting row (seeded by migrations 0001 and 0002), used by
 * the settings screen to pick the right input and to check each value (plan P2A, admin_settings).
 *
 *   group    heading on the settings screen (SettingsService::GROUPS gives the order)
 *   label    plain-language name shown next to the input
 *   type     int | float | bool | string | enum | mmdd (a month and day, "MM-DD")
 *   min/max  bounds for int and float; the length limits for string
 *   options  allowed values for enum; 'source' => 'language' means any active language code
 *   unit     shown after the label (minutes, hours, days, MB, ...)
 *   optional true when an empty value is allowed (it means "not set")
 *
 * A new setting needs a migration that seeds its row AND an entry here;
 * SettingsServiceTest fails when the two disagree.
 *
 * @return array<string, array{group: string, label: string, type: string, min?: int|float, max?: int|float,
 *   options?: list<string>, source?: string, unit?: string, optional?: bool}>
 */

$roles = ['Coordinator', 'Administrator'];

return [
    // Sign-in and accounts (UC-01, UC-11, US-01, US-30)
    'organisation_name' => ['group' => 'Sign-in and accounts', 'label' => 'Organisation name', 'type' => 'string', 'min' => 1, 'max' => 100],
    'default_language' => ['group' => 'Sign-in and accounts', 'label' => 'Default language', 'type' => 'enum', 'source' => 'language'],
    'organisation_time_zone' => ['group' => 'Sign-in and accounts', 'label' => 'Time zone for organisation dates (accounts, policy start dates, re-acceptance)', 'type' => 'enum',
        'options' => DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'US')],
    'session_idle_minutes' => ['group' => 'Sign-in and accounts', 'label' => 'Sign out after no activity for', 'type' => 'int', 'min' => 5, 'max' => 240, 'unit' => 'minutes'],
    'session_absolute_hours' => ['group' => 'Sign-in and accounts', 'label' => 'Longest time signed in', 'type' => 'int', 'min' => 1, 'max' => 24, 'unit' => 'hours'],
    'max_failed_logins' => ['group' => 'Sign-in and accounts', 'label' => 'Wrong passwords before an account is locked', 'type' => 'int', 'min' => 3, 'max' => 20],
    'lockout_minutes' => ['group' => 'Sign-in and accounts', 'label' => 'Account stays locked for', 'type' => 'int', 'min' => 5, 'max' => 1440, 'unit' => 'minutes'],
    'password_min_length' => ['group' => 'Sign-in and accounts', 'label' => 'Shortest allowed password', 'type' => 'int', 'min' => 8, 'max' => 64, 'unit' => 'characters'],
    'password_max_age_days' => ['group' => 'Sign-in and accounts', 'label' => 'Passwords must be changed every', 'type' => 'int', 'min' => 0, 'max' => 730, 'unit' => 'days; 0 = never'],
    'password_hibp_check' => ['group' => 'Sign-in and accounts', 'label' => 'Check new passwords against the online list of breached passwords', 'type' => 'bool'],
    'reset_link_minutes' => ['group' => 'Sign-in and accounts', 'label' => 'Password-reset links work for', 'type' => 'int', 'min' => 10, 'max' => 1440, 'unit' => 'minutes'],
    'temp_credential_hours' => ['group' => 'Sign-in and accounts', 'label' => 'Temporary passwords work for', 'type' => 'int', 'min' => 1, 'max' => 168, 'unit' => 'hours'],
    'trusted_device_days' => ['group' => 'Sign-in and accounts', 'label' => 'Remember a trusted device for', 'type' => 'int', 'min' => 1, 'max' => 90, 'unit' => 'days'],
    'pin_min_digits' => ['group' => 'Sign-in and accounts', 'label' => 'Shortest allowed PIN', 'type' => 'int', 'min' => 4, 'max' => 8, 'unit' => 'digits'],
    'pin_max_digits' => ['group' => 'Sign-in and accounts', 'label' => 'Longest allowed PIN', 'type' => 'int', 'min' => 4, 'max' => 8, 'unit' => 'digits'],
    'pin_max_failed' => ['group' => 'Sign-in and accounts', 'label' => 'Wrong PINs before a password is needed', 'type' => 'int', 'min' => 1, 'max' => 10],
    'pin_shift_hours' => ['group' => 'Sign-in and accounts', 'label' => 'PIN switching works for', 'type' => 'int', 'min' => 1, 'max' => 24, 'unit' => 'hours'],
    'volunteer_max_sites' => ['group' => 'Sign-in and accounts', 'label' => 'Most sites one Volunteer can be assigned', 'type' => 'int', 'min' => 1, 'max' => 20, 'unit' => 'sites'],
    'account_review_days' => ['group' => 'Sign-in and accounts', 'label' => 'List accounts for review after no sign-in for', 'type' => 'int', 'min' => 30, 'max' => 730, 'unit' => 'days'],
    'policy_reack_days' => ['group' => 'Sign-in and accounts', 'label' => 'Accept the confidentiality agreement again every', 'type' => 'int', 'min' => 0, 'max' => 1095, 'unit' => 'days; 0 = never'],
    'policy_reack_grace_days' => ['group' => 'Sign-in and accounts', 'label' => 'Grace period for an overdue agreement', 'type' => 'int', 'min' => 0, 'max' => 180, 'unit' => 'days'],

    // Offline station (UC-01 §3.3.3, UC-06)
    'offline_mode_enabled' => ['group' => 'Offline station', 'label' => 'Registered tablets may work offline', 'type' => 'bool'],
    'offline_grant_hours' => ['group' => 'Offline station', 'label' => 'Longest offline working time after an online sign-in', 'type' => 'int', 'min' => 1, 'max' => 168, 'unit' => 'hours'],
    'offline_pack_ttl_hours' => ['group' => 'Offline station', 'label' => 'Wipe offline participant data after', 'type' => 'int', 'min' => 1, 'max' => 168, 'unit' => 'hours'],
    'offline_cache_days' => ['group' => 'Offline station', 'label' => 'Include households served within the last', 'type' => 'int', 'min' => 7, 'max' => 730, 'unit' => 'days'],
    'offline_max_participants' => ['group' => 'Offline station', 'label' => 'Most households on one tablet', 'type' => 'int', 'min' => 100, 'max' => 50000, 'unit' => 'households'],
    'offline_max_failed_unlocks' => ['group' => 'Offline station', 'label' => 'Wrong unlocks before a tablet wipes its data', 'type' => 'int', 'min' => 3, 'max' => 50],
    'offline_pbkdf2_iterations' => ['group' => 'Offline station', 'label' => 'Offline encryption strength (key-derivation rounds)', 'type' => 'int', 'min' => 100000, 'max' => 2000000, 'unit' => 'rounds'],
    'sync_clock_skew_minutes' => ['group' => 'Offline station', 'label' => 'Allowed difference between tablet and server clocks', 'type' => 'int', 'min' => 1, 'max' => 120, 'unit' => 'minutes'],
    'late_sync_grace_days' => ['group' => 'Offline station', 'label' => 'Accept late or paper-slip entries for', 'type' => 'int', 'min' => 0, 'max' => 60, 'unit' => 'days'],
    'station_poll_seconds' => ['group' => 'Offline station', 'label' => 'Tablets refresh the queue and dashboard every', 'type' => 'int', 'min' => 5, 'max' => 120, 'unit' => 'seconds'],

    // Participants and distribution (UC-02 to UC-10, UC-15)
    'frequency_rule_days' => ['group' => 'Participants and distribution', 'label' => 'Days between distributions', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'days'],
    'household_pet_limit' => ['group' => 'Participants and distribution', 'label' => 'Most pets per household', 'type' => 'int', 'min' => 1, 'max' => 50, 'unit' => 'pets'],
    'over_allotment_auth_level' => ['group' => 'Participants and distribution', 'label' => 'Who can approve giving more than the allotment', 'type' => 'enum', 'options' => $roles],
    'emergency_auth_level' => ['group' => 'Participants and distribution', 'label' => 'Who can approve an emergency distribution', 'type' => 'enum', 'options' => $roles],
    'search_max_results' => ['group' => 'Participants and distribution', 'label' => 'Most participants shown for one search', 'type' => 'int', 'min' => 10, 'max' => 1000, 'unit' => 'participants'],
    'recent_participants_days' => ['group' => 'Participants and distribution', 'label' => 'An empty search lists households served within', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'days'],
    'history_page_size' => ['group' => 'Participants and distribution', 'label' => 'Distributions per page in the history', 'type' => 'int', 'min' => 5, 'max' => 200],
    'photo_max_mb' => ['group' => 'Participants and distribution', 'label' => 'Largest pet photo', 'type' => 'int', 'min' => 1, 'max' => 20, 'unit' => 'MB'],
    'lapse_threshold_days' => ['group' => 'Participants and distribution', 'label' => 'A participant counts as lapsed after no distribution for', 'type' => 'int', 'min' => 30, 'max' => 730, 'unit' => 'days'],
    'contact_reconfirm_days' => ['group' => 'Participants and distribution', 'label' => 'Ask to confirm contact details every', 'type' => 'int', 'min' => 30, 'max' => 1095, 'unit' => 'days'],
    'vaccination_warning_days' => ['group' => 'Participants and distribution', 'label' => 'Warn when a rabies vaccination expires within', 'type' => 'int', 'min' => 0, 'max' => 365, 'unit' => 'days'],
    'volunteer_draft_days' => ['group' => 'Participants and distribution', 'label' => 'Discard unfinished registration drafts after', 'type' => 'int', 'min' => 1, 'max' => 90, 'unit' => 'days'],
    'self_service_draft_hours' => ['group' => 'Participants and distribution', 'label' => 'Discard unclaimed self-service pre-registrations after', 'type' => 'int', 'min' => 1, 'max' => 168, 'unit' => 'hours'],
    'flag_review_days' => ['group' => 'Participants and distribution', 'label' => 'Highlight flags for review after', 'type' => 'int', 'min' => 30, 'max' => 730, 'unit' => 'days'],
    'welfare_prompt_multiplier' => ['group' => 'Participants and distribution', 'label' => 'Welfare check when the gap since the last visit is more than this many times the usual gap', 'type' => 'float', 'min' => 1, 'max' => 10],
    'pet_presence_review_days' => ['group' => 'Participants and distribution', 'label' => 'List pets not confirmed present for', 'type' => 'int', 'min' => 30, 'max' => 1095, 'unit' => 'days'],
    'size_review_juvenile_months' => ['group' => 'Participants and distribution', 'label' => 'Check the size band of pets registered younger than', 'type' => 'int', 'min' => 1, 'max' => 36, 'unit' => 'months'],

    // Spay/neuter (UC-08, US-40)
    'voucher_expiry_days' => ['group' => 'Spay/neuter', 'label' => 'Vouchers are valid for', 'type' => 'int', 'min' => 7, 'max' => 365, 'unit' => 'days'],
    'voucher_reminder_days' => ['group' => 'Spay/neuter', 'label' => 'Send the expiry reminder this long before a voucher expires', 'type' => 'int', 'min' => 1, 'max' => 90, 'unit' => 'days'],
    'litters_prevented_multiplier' => ['group' => 'Spay/neuter', 'label' => 'Litters prevented per completed surgery', 'type' => 'float', 'min' => 0, 'max' => 100, 'optional' => true],
    'litters_prevented_citation' => ['group' => 'Spay/neuter', 'label' => 'Source for the litters-prevented figure', 'type' => 'string', 'min' => 0, 'max' => 255, 'optional' => true],

    // Import and reports (UC-12, UC-13, UC-14, UC-15)
    'import_max_file_mb' => ['group' => 'Import and reports', 'label' => 'Largest import file', 'type' => 'int', 'min' => 1, 'max' => 100, 'unit' => 'MB'],
    'import_max_rows' => ['group' => 'Import and reports', 'label' => 'Most rows in one import file', 'type' => 'int', 'min' => 100, 'max' => 200000, 'unit' => 'rows'],
    'import_max_rows_sync' => ['group' => 'Import and reports', 'label' => 'Check imports straight away up to', 'type' => 'int', 'min' => 100, 'max' => 50000, 'unit' => 'rows'],
    'import_max_reject_pct' => ['group' => 'Import and reports', 'label' => 'Largest share of rejected rows before an import is refused', 'type' => 'int', 'min' => 0, 'max' => 100, 'unit' => '%'],
    'report_max_span_months' => ['group' => 'Import and reports', 'label' => 'Longest date range for one report', 'type' => 'int', 'min' => 1, 'max' => 120, 'unit' => 'months'],
    'report_interactive_seconds' => ['group' => 'Import and reports', 'label' => 'Run reports in the background when they take longer than', 'type' => 'int', 'min' => 5, 'max' => 300, 'unit' => 'seconds'],
    'small_cell_minimum' => ['group' => 'Import and reports', 'label' => 'Hide report counts smaller than', 'type' => 'int', 'min' => 2, 'max' => 50],
    'programme_year_start' => ['group' => 'Import and reports', 'label' => 'First day of the programme year', 'type' => 'mmdd'],

    // Retention (UC-04, UC-07, UC-09, UC-12, UC-13)
    'recovery_window_days' => ['group' => 'Retention', 'label' => 'Deleted records can be restored for', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'days'],
    'import_file_retention_days' => ['group' => 'Retention', 'label' => 'Delete uploaded import files after', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'days'],
    'sync_payload_retention_days' => ['group' => 'Retention', 'label' => 'Delete encrypted tablet uploads after', 'type' => 'int', 'min' => 7, 'max' => 365, 'unit' => 'days'],
    'report_result_retention_days' => ['group' => 'Retention', 'label' => 'Keep background report results for', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'days'],
    'distribution_retention_years' => ['group' => 'Retention', 'label' => 'Keep distribution history for at least', 'type' => 'int', 'min' => 1, 'max' => 30, 'unit' => 'years'],
    'auth_audit_retention_months' => ['group' => 'Retention', 'label' => 'Keep sign-in records for at least', 'type' => 'int', 'min' => 1, 'max' => 120, 'unit' => 'months'],
    'audit_statutory_retention_years' => ['group' => 'Retention', 'label' => 'Keep the change history for this long after a record ends', 'type' => 'int', 'min' => 1, 'max' => 30, 'unit' => 'years'],
];
