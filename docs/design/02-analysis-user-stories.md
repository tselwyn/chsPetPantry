<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

# PFPMS backlog extraction: 41 stories mapped to the schema

Sources, all under `C:/Users/maryw/Documents/Pelican/chsPetPantry/docs/`:
- `PFPMS_User_Stories_Backlog.docx`: read in full, 45,988 characters, v1.0, dated 2 Sep 2026.
- `PFPMS_Jira_Backlog_Import.csv`: 550 physical lines.
- `PFPMS_schema_v2.sql`: 59 `CREATE TABLE` statements confirmed.
- `PFPMS_schema_v2_notes.md` and `PFPMS_useful_functions.md`.

## 1. Jira CSV structure, and how it differs from the docx

- **Columns (9):** Summary, Issue Type, Description, Priority, Story Points, Labels, Epic Name, Epic Link, Assignee.
- **Records:** 57, not 550. The 550 lines come from multi-line Description fields.
  - 16 Epics, one per use case UC-01..UC-16. Priority is "Medium" on all of them, with no points and no Epic Link.
  - 41 Stories, US-01..US-41. Epic Link holds the epic name, for example "UC-01 Login".
  - There are no sub-tasks, tasks or bugs.
- **Priority:** Jira High/Medium/Low stands for MoSCoW Must/Should/Could.

  | MoSCoW | Stories | Points |
  |---|---|---|
  | Must | 6 | 28 |
  | Should | 23 | 108 |
  | Could | 12 | 73 |
  | Total | 41 | 209 |

  These match the docx §1.3 exactly.
- **Labels:** each story has three lowercase labels, `uc-NN <actor> <moscow>`.
  - Actor label counts: admin 16, volunteer 12, participant 9, external-stakeholder 3, partner-clinic 1.
- **Assignee:** empty on every row. The docx ownership table is also blank.
- **Description:** I checked it line by line against the docx and it matches word for word (story, acceptance criteria, "Adds beyond the use case"). The CSV adds one extra line per story that the docx does not have: `*Traceability* Epic UC-NN · Story US-NN · MoSCoW: X`. Summaries in the CSV start with "US-NN".
- **Differences from the docx.** None in the story content. Label differences only:
  - There is no "coordinator" or "board" label. Stories whose "As a…" role is a coordinator are labelled `admin`: US-02, 09, 15, 18, 28, 31, 36, 37, 38, 41.
  - US-33 (board member) and US-39/US-40 (director) are labelled `external-stakeholder`. The schema, by contrast, has real Coordinator and Board roles (`user_account.role`).
  - US-05 and US-26 are written "As a volunteer" but labelled `participant`. The label names who benefits, not the role in the story.

## 2. All 41 stories

In the Pts column, **bold** means Must, plain means Should and *italic* means Could. "OK" means the schema already has what the story needs. Every table and column named below was checked against `PFPMS_schema_v2.sql`.

| ID | UC | Summary | Pts | Key acceptance criteria | Schema support | Gap note |
|---|---|---|---|---|---|---|
| US-01 | 01 | PIN fast-switch on shared tablet | **5** | 4–6 digit PIN for a volunteer on shift at this site; switch in under 5 s; don't lose the open entry; only on site-registered devices | `user_account.pin_hash`; `device.site_id`, `device.is_site_registered`; `user_session.auth_method='PIN'`, `user_session.device_id`; `user_site_access` | Keeping the "open entry" is client-side state. Drafts only cover registration (`registration_draft`). |
| US-02 | 01 | Live roster of signed-in volunteers | 3 | Lists active sessions at the site with sign-in time; remote sign-out; Coordinator/Admin only | `user_session` (site_id, started_at, ended_at, end_reason='Remote Sign-out', ended_by) | OK |
| US-03 | 01 | Confidentiality acknowledgement on first login | **2** | Shown before home screen; declining ends the session; version and date stored | `policy_document` (doc_type='Confidentiality Agreement', version, language_code); `policy_acknowledgement`; `user_account.onboarding_completed_at`; `audit_log` | OK |
| US-04 | 02 | Today's checked-in households at top of search | 5 | Checked-in first, with check-in time; refreshes live; typing reverts to normal search | `event_check_in` (event_id, checked_in_at, outcome='Waiting'); `distribution_event.status='Open'` | OK. Live refresh (polling) is app work. |
| US-05 | 02 | Search and serve by preferred name | 3 | Preferred name stored; search matches either name, shows preferred first; receipts and vouchers use it | `participant.preferred_name` (ix_participant_2), legal_first_name/legal_last_name | OK |
| US-06 | 02 | Accent-insensitive search | 3 | Accented = unaccented both ways; warn at registration on accent-only difference; sort order unaffected | Table collation `utf8mb4_0900_ai_ci`; `participant_alert.alert_type='Duplicate Candidate'` with matched_participant_id | OK (notes say it was tested) |
| US-07 | 03 | Applicant self-service pre-registration | *8* | Site QR code opens self-entry form; volunteer verifies, records consent and proof of residence; drafts unclaimed after 24 h are discarded | `registration_draft` (source='Self-Service', created_by NULL, expires_at, claimed_by, participant_id) | Mostly OK. No system_setting for the 24 h expiry; public QR endpoint is new app work. |
| US-08 | 03 | Save interrupted registration as draft | 5 | Save without validation; drafts listed on home screen with age; a draft cannot receive a distribution and isn't searchable | `registration_draft` (source='Volunteer', form_data JSON, created_by, updated_at) | OK |
| US-09 | 03 | Coordinator-configurable intake questions | 8 | Add, retire, reorder; required or optional; retiring keeps answers; no release needed | `intake_question` (answer_type, options, is_required, display_order, retired_at); `intake_answer` | OK. Draft answers live in `registration_draft.form_data`. |
| US-10 | 04 | Participant self-service contact update | *8* | Link sent to recorded contact method; address change held for confirmation, phone applies at once; participant told which is which | Only `participant` contact columns exist | **No tables** (notes §4.4). `auth_token.user_id` is NOT NULL with an FK to `user_account`, so participant tokens need schema changes (nullable user_id plus participant_id, new purpose). No table for pending changes. |
| US-11 | 04 | Prompt to reconfirm stale contact details | 3 | Prompt after a configurable period; "confirm unchanged" records the date; defer once per visit; last-confirmed date shown | `participant.contact_confirmed_on`; setting `contact_reconfirm_days`; `participant_alert` 'Stale Contact' | OK. Defer-once is kept in the session. |
| US-12 | 04 | Service-preference notes | 3 | Short note visible at the table; guidance against health or financial content; author and date shown; can retire | `service_note` (note_text VARCHAR(280), author_id, created_at, retired_at, retired_by) | OK |
| US-13 | 05 | Picture-based size/type picker for mixed breeds | 5 | Visual picker of size bands and body types; selection sets the allotment size band; free-text breed still allowed | `size_band.picture_path`; `pet.size_band_id`, `pet.body_type`, `pet.breed_id`, `pet.breed_text` | Partial. `body_type` is free VARCHAR(30) with no lookup table or images; body-type pictures would be static assets or a new table. |
| US-14 | 05 | Vaccination expiry prompt at the table | 3 | Flag expiry within a configurable window; give low-cost vaccination clinics; no date shows "unknown", not expired | `pet.rabies_vaccinated_on`, `pet.rabies_expires_on`; setting `vaccination_warning_days`; `participant_alert` 'Vaccination Due'; `clinic.offers_low_cost_vaccination` | OK |
| US-15 | 05 | Flag pets that outgrew their size band | *5* | List pets whose age implies a different band; volunteer confirms weight at next visit; nothing changes automatically | `pet.date_of_birth`, `dob_is_estimate`, `weight_lbs`, `weight_confirmed_on`; `size_band` min/max weight; `participant_alert` 'Size Band Review' | Partial. Nothing links age to expected size (size_band has weights only; breed has no adult-weight field). The rule would be hard-coded or need a new setting or table. |
| US-16 | 06 | Record distribution by barcode scan | 5 | Scan selects product and unit weight; unknown barcode linked once and recognised at every site; manual pick still available | `product_barcode` (barcode PK, product_id, linked_by; global, no site_id); `product.unit_weight_lbs`; `distribution_line.unit_weight_lbs` | OK |
| US-17 | 06 | Decline a product and substitute within allotment | 3 | Record the decline and reason, substitute same species and allotment; declines kept separate from shortfalls; repeat declines visible | `distribution_line.line_type` (Issued/Declined/Shortfall), decline_reason, substitutes_line_id; `product.species_id` | OK |
| US-18 | 06 | Live event dashboard for coordinator | **8** | Live households, pets and stock by product; warn when checked-in demand exceeds stock; works offline from queued data | `distribution` (event_id, pets_served, client_uuid, sync_status='Queued'); `distribution_pet`; `distribution_line`; `site_stock.quantity_on_hand`; `inventory_transaction`; `event_check_in` 'Waiting' plus `participant.current_allotment_lbs` | OK on the server side. The offline queue (service worker or IndexedDB) is new client work. |
| US-19 | 07 | Participant self-service history view | *8* | Authenticated self-service link; dates, sites and quantities only, no flags, notes or staff; access revoked when they leave | `distribution` and `distribution_line` data; `participant.status` for revocation | **No tables** (notes §4.4). Same auth_token blocker as US-10. |
| US-20 | 07 | Irregular-visit welfare prompt | *5* | Mark a lengthened visit interval against the household's own pattern; worded as a prompt, not a flag; dismiss without recording anything | Calculated from `distribution.local_date` (ix_distribution_1) | OK. Deliberately never stored (notes §4.5). No setting for the threshold. |
| US-21 | 08 | Clinic directions and hours from voucher | 3 | Link or QR with location, hours and phone; no account needed, works on a low-end phone; printed text too | `clinic` (street_address, city, phone, hours_text, directions_url); `snv_referral.clinic_id`, `voucher_number` | OK. The public page shows clinic data only. |
| US-22 | 08 | Clinic-facing voucher confirmation and outcome | **8** | Clinic looks up a voucher and records redemption, surgery date and outcome without an account; sees only pet and voucher; feeds the referral and SNV reports | `clinic.portal_access_hash`; `snv_referral` (voucher_number UNIQUE, redeemed_at, surgery_date, outcome, outcome_source='Clinic Link', outcome_reported_at, redeemed_amount); `snv_referral_status_log.changed_by_clinic_id`; `pet.snv_status`, `is_altered`, `altered_date`, `altered_clinic_id` | OK. `audit_log` has no clinic_id column, so clinic actions go in `details` JSON. |
| US-23 | 08 | Referral material in preferred language | 5 | Voucher and explanation print in the participant's language; coordinator maintains the language list; fall back to default and tell the volunteer | `language`; `participant.preferred_language_code`; `policy_document` 'SNV Explanation' per language_code; `snv_referral.material_language_code` | OK |
| US-24 | 09 | Monthly deletion digest | 3 | Deletions and purges with actor, reason, type; highlight ones that could have been deactivations; sent to Admins and retained | `audit_log` (action, entity_type, user_id, reason, snapshot); `participant.deleted_at`, `deleted_by`, `delete_reason`; `pet.deleted_*`; `erasure_request`; `saved_report` (schedule_frequency='Monthly'); `report_recipient`; `report_run.retain_until` | OK. "Could have deactivated" is worked out by a query. |
| US-25 | 09 | Plain-language retention notice on leaving | 3 | What is kept, how long, why, how to erase; in preferred language at the point of leaving; erasure is a tracked request | `policy_document` 'Retention Notice' per language; `erasure_request` (request_reference, status, approved_by) | OK |
| US-26 | 10 | Suppress prompts about deceased pet | **3** | Deceased suppresses referral and vaccination prompts; allotment recalculated quietly; pet stays in history, visible to Admin | `pet.status='Inactive'`, `inactive_reason='Deceased'`, `inactive_date`; `participant_alert.pet_id`; `snv_followup.pet_id`; `participant.current_allotment_lbs`; `distribution_pet` | OK |
| US-27 | 10 | Review pets with no recent activity | *5* | List pets not confirmed present over repeated visits; queue a confirmation for next visit; nothing auto-inactivated | `pet.last_confirmed_present`; `distribution_pet`; `participant_alert` 'Pet Presence Check' | OK. No setting for the "1 year" window. |
| US-28 | 11 | Time-boxed site access for a shift | 5 | Grant has an end time, defaulting to end of shift; lapses automatically and ends access to that site's data at once; in audit history | `user_site_access` (starts_at, ends_at, grant_reason, granted_by); `user_session.end_reason='Permission Change'`; `audit_log` | OK. There is no shift table, so "end of shift" defaults from `distribution_event.ends_at`. |
| US-29 | 11 | Training mode with sample data | 8 | Clearly marked practice data; nothing reaches live data, reports or inventory; coordinator sees who has practised | `user_account.practice_completed_at` only | **No tables** (notes §4.3). Planned as a separate sample database, which needs a DB switch in `connect()`. |
| US-30 | 11 | Annual re-acknowledgement of confidentiality | *5* | Prompted at login, grace period, then restricted; dates and versions reportable; account and history never removed | `policy_acknowledgement.due_again_on`; `policy_document.version`, `effective_from` | Partial. No system_setting for the re-acknowledgement interval or grace period, and no 'Restricted' account status (would be derived). |
| US-31 | 12 | Pre-fill drafts from photographed intake sheets | *13* | Batch of images becomes draft records; every field confirmed by a person; unreadable sheets listed | `registration_draft` and `import_batch` only | **No tables** (notes §4.4). No image, OCR or field-confidence storage; `registration_draft.source` has no scan value. Needs an external OCR service. |
| US-32 | 12 | Downloadable import template per record type | **2** | Template has headings, example row and notes; maps automatically; includes current intake questions | `import_mapping` (record_type, column_map); `import_batch`; `intake_question` where retired_at IS NULL | OK. `record_type` enum covers only Participant, Pet and Distribution. |
| US-33 | 13 | Read-only mobile board dashboard | 8 | Named Board accounts, phone layout; aggregates only; shows data currency | `user_account.role='Board'`; `report_run.data_current_as_of`; setting `small_cell_minimum` | OK |
| US-34 | 13 | Narrative attached to a report | *3* | Note travels with exports and scheduled deliveries; author and date, visually distinct; editable, then versioned | `report_narrative` (run_id, version, body, author_id, circulated_at) | OK |
| US-35 | 13 | Threshold alerts on key figures | *5* | Threshold or % change on a few figures; alert gives figure, period, movement and report link; rate-limited | `metric_threshold` (metric_key, rule_type, threshold_value, min_alert_interval_days, last_alerted_at); `user_account.notification_prefs` | OK (minor). No alert-history table; metric_key-to-report mapping is in code; needs cron. |
| US-36 | 14 | Forward demand forecast | 8 | Demand by product type from enrolled households, pets and frequency rule; assumptions shown, seasonal adjustment; gap against stock | `participant` (status, current_allotment_lbs, next_eligible_date); `pet`; `allotment_rule` (lbs_per_distribution, food_form); setting `frequency_rule_days`; `site_stock`; `product` | Partial. No table for seasonal factors; would go in `saved_report.parameters` or `report_run.parameters` JSON. |
| US-37 | 14 | Most-declined products report | *3* | Decline rate against times offered; separate from shortfalls and out-of-stock; filter by species and site | `distribution_line.line_type`; `product.species_id`; `distribution_event.site_id`; `event_check_in.outcome='No Stock'` | OK |
| US-38 | 15 | Wait time by site and shift | 8 | Check-in to distribution by site, day and hour; volunteers on shift alongside; left-unserved counted separately | `event_check_in` (checked_in_at, outcome='Left Unserved', outcome_at); `distribution.check_in_id`, `distributed_at`; `site.time_zone` | Partial. No shift or roster table (legacy `dbshifts` dropped); volunteer counts would be estimated from `user_session` or distinct `distribution.recorded_by`. |
| US-39 | 15 | Households referred out of service area | 3 | By period and postal code; pantry referred to; small-cell suppression | `referred_out_applicant` (postal_code, referred_to_pantry, site_id, recorded_at); setting `small_cell_minimum` | OK |
| US-40 | 16 | Estimate of litters prevented | 5 | Multiplier with citation, editable by Admin; states it is an estimate; surgery count always shown | `snv_referral` (status='Completed', outcome, surgery_date); setting `litters_prevented_multiplier` (seeded blank) | OK. Multiplier and citation share one VARCHAR(255) setting; a separate citation key is advisable. |
| US-41 | 16 | Compare SNV uptake between sites | *5* | Offer, acceptance and completion rates per site; low-count sites marked, not ranked; by species and volunteer | `snv_referral` (site_id, status, issued_by); `pet.species_id`; `snv_followup` 'Offer Deferred'; setting `small_cell_minimum` | OK. `voucher_number` is NOT NULL, so a declined offer either uses up a voucher number or isn't counted as an offer. Check against UC-08. |

**Stories without the tables they need:** US-10, US-19, US-29 and US-31, as the notes say. The notes' four also include US-20, but it needs no table by design. My checks found partial gaps the notes don't mention:
- US-13: no body-type vocabulary or pictures.
- US-15: nothing to derive size band from age.
- US-30: missing re-acknowledgement settings.
- US-36: nowhere to store seasonal factors.
- US-38: no shift or roster data.
- Smaller: settings missing for US-07, US-20 and US-27, and US-40 has a single setting for multiplier and citation.

## 3. By MoSCoW

- **Must (6 stories, 28 pts):** US-01, US-03, US-18, US-22, US-26, US-32
- **Should (23 stories, 108 pts):** US-02, 04, 05, 06, 08, 09, 11, 12, 13, 14, 16, 17, 21, 23, 24, 25, 28, 29, 33, 36, 38, 39, 40
- **Could (12 stories, 73 pts):** US-07, 10, 15, 19, 20, 27, 30, 31, 34, 35, 37, 41

## 4. Suggested dependency order

Infrastructure first (F0–F9), with the stories that depend on each:

| Step | Infrastructure | Stories |
|---|---|---|
| F0 | Install schema v2 and seed data (sites, language, species, size_band, allotment_rule, system_setting, policy_document). PDO data-access layer: transactions, `row_version` locking, audit writer (`audit_log` + `audit_field_change`), settings loader. | Prerequisite for everything |
| F1 | Auth and access: `user_account` (4 roles), `user_session`, `user_site_access`, `device`, role and site guards. | US-03 (M), US-01 (M), US-02, US-28, US-33 (Board role). US-30 after US-03. US-29 last, once the app is complete. |
| F2 | Participant core, UC-02/03/04: search, register, update, service-area check, `participant_alert` prompt framework. | US-05, US-06, US-08, US-09, US-11, US-12. US-07 after US-08. US-39 needs referred-out capture. |
| F3 | Pet core, UC-05: species, size_band, allotment calculation. | US-13, US-15. US-14 also needs the `clinic` table from F6. US-26 (M) needs F2 alerts and the allotment calculation. |
| F4 | Catalogue and inventory ledger: item_category, product, product_barcode, site_stock, inventory_transaction, receipts, counts. Adapts legacy pallet and count code. | US-16 |
| F5 | Distribution, UC-06/07: distribution_event, event_check_in, one-transaction distribution, history view. | US-04, US-17, US-20. **US-18 (M) needs the check-in queue from US-04 (a Should), plus F4 stock and an offline client queue.** US-27 needs `distribution_pet`. |
| F6 | SNV, UC-08: clinic, clinic_species_rule, voucher_budget, snv_referral, status log, followups. | US-21, US-23. US-22 (M) also needs the separate clinic-portal auth via `portal_access_hash`. |
| F7 | Governance, UC-09/10: soft delete and restore, erasure workflow. | US-25. US-24 also needs F9 scheduling. |
| F8 | Import, UC-12: import_mapping, import_batch, rejected rows. | **US-32 (M) needs F8 and US-09 (a Should)**, because the template must include current intake questions. US-31 after US-08, plus an OCR service. |
| F9 | Reporting framework, UC-13: report_run, saved_report, recipients, cron, CSV/XLSX export, small-cell suppression. Adapts `reportsExport.php`. | US-33, US-34, US-35. US-36 needs F3 + F4. US-37 needs US-17. US-38 needs US-04. US-39 needs F2. US-40 and US-41 need F6 with US-22 outcomes. |
| Late | Participant self-service. | US-10 and US-19 need an auth_token or participant-token schema change first. |

Three Must stories depend on Should-level work, so that work has to be scheduled early whatever its MoSCoW label:
- US-18 needs US-04's check-in queue (`event_check_in`).
- US-32 needs US-09's configurable intake questions.
- US-26 needs the alert-prompt framework that US-11, US-14 and US-15 also use.

**Delivery order:** F0 → F1 (US-03, US-01) → F2 → F3 (US-26) → F4 → F5 (US-04, then US-18) → F6 (US-22) → F8 (US-09, then US-32) → F7 → F9 reports → Could stories and the stories without tables (US-07, 10, 19, 29, 30, 31).
