<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

# PFPMS use cases (UC-01 to UC-16): extracted requirements, schema mapping and gaps

Sources: `C:/Users/maryw/Documents/Pelican/chsPetPantry/docs/PFPMS_Use_Case_Specifications.docx` (140,222 characters, all read), `docs/PFPMS_schema_v2.sql` (59 tables, all read), `docs/PFPMS_schema_v2_notes.md`, `docs/PFPMS_useful_functions.md`. Every table named below exists in the SQL.

**Actors in the use cases.** Volunteer and Administrator only. The schema's `user_account.role` also has Coordinator and Board; these come from the backlog and appear in no use case (open question 1 in the notes).

**Included use cases** (not started by an actor; they live inside the base flows):
- Manage User Profile (in UC-01)
- Validate Participant Information (UC-03, UC-04, UC-12)
- Validate Pet Information (UC-05, UC-12)
- Select Participant (UC-06, UC-07)
- Select Food Type and Quantity (UC-06)
- Capture Distribution Date and Location, which includes Update Participant Status, which includes Flag/Unflag Participant (UC-06)
- View Distribution Details (UC-07, UC-08)
- UC-14, UC-15 and UC-16 are included by UC-13 and cannot be opened from the menu.

---

## UC-01 Login
**Actor:** Volunteer, Admin. Supporting: Authentication Service.

**Base flow**
- Login screen shows username or email, password, organisation name and client version.
- Checks the password hash, then that the account is active, not locked and not past its expiry date.
- Creates a session and audits the login (user, timestamp, device, site).
- Loads the role menu (Volunteer: search/register/distribute/refer; Admin adds accounts/delete/import/reports) and the assigned sites.
- Manage User Profile: display name, contact details, notification preferences, change password.
- Home screen shows the current site and any outstanding alerts.

**Alternate flows**
- 3.2.1 Forgot password: prompt for email; send a single-use, time-limited link without revealing whether the email is registered. On reset, store the new hash, invalidate the link and all sessions, audit.
- 3.2.2 Temporary or expired password: force a change before any menu; status goes Pending to Active.
- 3.2.3 Remember this device: device token bound to account and site for a set period; later launches pre-fill the username and ask only for the password.

**Exception flows**
- 3.3.1 Invalid credentials: generic message; increment the failure counter; audit. At the threshold, lock for the set period and notify an Admin.
- 3.3.2 Account inactive, expired or locked: deny; tell the user to contact an Admin; audit with the reason.
- 3.3.3 Authentication service unavailable: offer Offline Mode if the device holds a cached credential. This is a restricted session that can only record queued distributions, all marked pending sync.

**Special requirements**
- Salted adaptive hash (Argon2id or bcrypt); never store or log plain text.
- Configurable minimum length; block common and breached passwords; configurable maximum age.
- Session ends after 30 minutes idle or 12 hours absolute. Expiry must keep any in-progress distribution entry and restore it after sign-in.
- Authentication in 3 s or less (95th percentile).
- Works on tablet and phone: 44×44 pt touch targets, readable contrast outdoors.
- Every attempt audited (user, time, device, outcome) and kept at least 12 months.

**Tables**
- Read: `user_account`, `user_site_access`, `site`, `device`, `auth_token`, `system_setting` (`session_idle_minutes`, `max_failed_logins`)
- Write: `user_session` (auth_method Password/PIN/Offline), `audit_log`, `auth_token` (reset / trusted device, used_at), `device`
- Write `user_account` columns: failed_login_count, locked_until, status, last_login_at, password_hash, password_changed_at, must_change_password, notification_prefs, first_name, last_name, phone

## UC-02 Search for Participants
**Actor:** Volunteer, Admin.

**Base flow**
- One free-text box plus optional advanced fields: surname, given name, phone, Participant ID, address, pet name, status.
- Query is limited to the sites the user is authorised for.
- Each result shows name, ID, status, number of pets and last distribution date. Order: closeness of match, then surname.
- Selecting a result sets the current participant context and audits the view.

**Alternate flows**
- 3.2.1 Scan the card barcode or QR code: decode the ID and open the record directly.
- 3.2.2 Empty search: show participants served at this site in the last 30 days, newest first.
- 3.2.3 Filter results by status (Active, Inactive, Flagged), site, or last-distribution date range.
- Admin-only "include deleted" option (needed by UC-09 3.2.4).

**Exception flows**
- 3.3.1 No match: show the criteria, suggest widening them, offer Register New Participant.
- 3.3.2 More than 100 matches (configured maximum): show the first page, warn it is truncated, ask for more criteria.
- 3.3.3 Connection lost: search the local cache of recently served participants, marked as possibly stale. If there is no match, allow a distribution against a manually identified participant for later reconciliation.

**Special requirements**
- First page in 2 s or less (95th percentile) over 20,000 participants.
- Partial matching on name and address fields; phonetic matching on surname.
- Results show only the minimum needed to identify someone. Full address, email and ID data appear only on the detail screen, and only to authorised users.
- Results cannot be exported.
- Volunteers see only their sites; Admins see all sites.

**Tables**
- Read: `participant` (surname_phonetic and indexes), `participant_site`, `pet` (name, count), `participant_alert` (Flagged means an unresolved alert with blocks_distribution=1), `distribution` + `distribution_event` (recent at site), `user_site_access`
- Write: `audit_log`

## UC-03 Register New Participant
**Actor:** Volunteer. Off-stage: Applicant.

**Base flow**
- Capture given name, surname and date of birth.
- Address with postal code.
- At least one of phone or email, plus preferred contact method.
- Household size and number of pets.
- Referral source.
- Type of proof of residence seen (no copy kept).
- Read the programme rules; record consent, date and the consenting person's name.
- Validate Participant Information.
- Duplicate check on surname + phone + address.
- Create the record: unique Participant ID, status Active; registration date, Volunteer, site and device stamped.
- Optionally run UC-05 for each pet.
- Confirmation screen and a participant card with name and ID as a barcode.

**Alternate flows**
- 3.2.1 Register pets in the same visit: run UC-05 per pet, then recalculate the allotment.
- 3.2.2 Optional item declined: record "Declined" instead of leaving it blank (date of birth, email, referral source, etc.).
- 3.2.3 Offline registration: store locally with a provisional unsynced ID and allow distribution against it. On reconnect, validate, run the duplicate check, swap in the permanent ID, and notify the Volunteer of any sync failures.
- 3.2.4 Already registered at another site: add this site to the existing record instead of creating a new one; audit the transfer.

**Exception flows**
- 3.3.1 Possible duplicate: show candidates with the matching fields and last distribution. The Volunteer either opens the existing record or confirms a distinct person with a justification.
- 3.3.2 Validation fails: mark each failing field with the reason; keep everything entered.
- 3.3.3 Postal code outside the service area: warn and refuse Active status. Record as Referred Out (to a named nearby pantry), or ask an Admin to override with a documented reason.
- 3.3.4 Save fails: keep the data; offer retry or local storage for later sync.

**Special requirements**
- Collect only the data needed; record the ID document type but never store images.
- Consent text version, date and consenting person stored and reproducible.
- Service-area postal codes configurable by an Admin without code changes.
- Registering Volunteer, site, device and timestamp cannot be edited afterwards.
- A registration with two pets takes 5 minutes or less, including card.
- On failure, no Participant ID is used up.

**Tables**
- Read: `service_area_postal_code`, `participant`, `policy_document` (Programme Consent), `language`, `site`, `device`, `system_setting`
- Write: `participant`, `participant_site` (is_transfer), `participant_consent`, `participant_alert` (Duplicate Candidate; justification in `resolution`), `referred_out_applicant` (postal code and pantry only, no personal data), `registration_draft` (interrupted intake), `audit_log`, plus UC-05 tables
- Optional (from backlog US-09, not the use case): `intake_question`, `intake_answer`

## UC-04 Update Participant Information
**Actor:** Volunteer; Admin for restricted fields and merges.

**Base flow**
- Editable form; restricted fields (status, eligibility flags, site assignment) are read-only unless Admin.
- Record the version as read.
- Edit, then validate.
- Optimistic lock check.
- Write, with one field-level audit entry per changed field.
- Recalculate derived values (service-area eligibility).
- Confirmation lists the changed fields.

**Alternate flows**
- 3.2.1 Address change inside the service area: check the postal code; if sites are assigned by geography, propose the nearer site; user accepts or declines.
- 3.2.2 Household change: edit household size or add/remove pets via UC-05; recalculate the allotment.
- 3.2.3 Deactivate at participant's request (Admin): status Inactive with a reason (moved, no pets, asked to be removed). History kept; removed from active distribution lists.
- 3.2.4 Merge (Admin): field-by-field comparison and choice of surviving values. Pets, distributions and referrals move to the survivor. Absorbed record becomes Merged with a pointer to the survivor; audit.

**Exception flows**
- 3.3.1 Validation fails: mark fields; original values available to restore.
- 3.3.2 Someone else changed the record: refuse the save; show the competing changes field by field; offer reload, overwrite selected fields, or abandon.
- 3.3.3 Restricted field by a non-Admin: reject that change, apply the permitted ones, advise referral to an Admin, audit the refusal.
- 3.3.4 Save fails: keep values; offer retry.

**Special requirements**
- Field audit is immutable and kept for the record's life plus the statutory retention period.
- Optimistic locking: never silently overwrite another user's change.
- Only Admins change status, flags and site assignment.
- Admins can view any previous version and restore individual fields.

**Tables**
- Read/write: `participant` (row_version, status, status_reason, merged_into_id), `audit_log`, `audit_field_change`, `participant_site`, `service_area_postal_code` (site_id for nearer site), `participant_alert` (Eligibility Flag)
- Merge also writes: `pet`, `pet_household_history`, `distribution`, `snv_referral`, `participant_proxy`
- Recalculation reads `allotment_rule`, `size_band`

## UC-05 Register/Update Pet Information
**Actor:** Volunteer; Admin for corrections and microchip conflicts.

**Base flow**
- List the household's pets (species, name, age, altered status); add a new pet or pick one to edit.
- Name, species, breed or type, sex, colour/markings.
- Age or date of birth, and weight; together these give the size band used for the allotment.
- Altered or not, with date and clinic.
- Vaccination status and last rabies date.
- Microchip number.
- Feeding restriction.
- Validate Pet Information.
- Save and link to the household; audit.
- Recalculate the household allotment.
- If not altered, check spay/neuter eligibility and show it.

**Alternate flows**
- 3.2.1 Already altered elsewhere: set altered with date and clinic; close any open referral as Completed Elsewhere.
- 3.2.2 Deceased, rehomed, lost or surrendered: set Inactive with reason and date; keep history; void any open referral; recalculate.
- 3.2.3 Transfer to another household: find the receiving participant (UC-02); link from the effective date; keep the old link; recalculate both allotments.
- 3.2.4 Photo: capture or select; compress to the configured maximum.

**Exception flows**
- 3.3.1 Validation fails: missing species, implausible age or weight, wrong microchip length.
- 3.3.2 Microchip is on a pet in another household: show the conflict without the other household's contact details; offer transfer, correct the number, or refer to an Admin.
- 3.3.3 Household pet limit exceeded: refuse. An Admin can override with a reason; the pet is flagged as an exception.
- 3.3.4 Save fails: keep data; offer retry or queue for sync.

**Special requirements**
- Allotment formula configurable by species and size band without code changes; the version in force is stamped on each distribution.
- A microchip is on at most one active pet.
- Household limit configurable, with Admin override and a recorded reason.
- Photo 5 MB or less, visible only to users allowed to see the participant.
- Admin maintains the species, breed and colour lists.

**Tables**
- Read: `species`, `breed`, `size_band`, `allotment_rule`, `clinic`, `clinic_species_rule`, `system_setting` (`household_pet_limit`)
- Write: `pet` (generated `active_microchip` has a unique key; snv_status, inactive_reason, limit_override_reason, photo_path, row_version), `pet_household_history`, `participant` (current_allotment_lbs), `snv_referral` + `snv_referral_status_log` (Completed Elsewhere / Void), `snv_followup`, `participant_alert` (Size Band Review, Vaccination Due), `audit_log`, `audit_field_change`

## UC-06 Record Food Distribution
**Actor:** Volunteer; Admin authorises overrides. Supporting: Inventory.

**Base flow**
- Start from an open distribution event.
- Select Participant.
- Show name, ID, status, flags, pets, last distribution and calculated allotment.
- Apply the frequency rule and confirm the household is due.
- Select Food Type and Quantity: dry or wet, species, product/brand, units or weight.
- Check the quantity against the allotment, then against site stock.
- Volunteer confirms with the participant and submits.
- Capture Distribution Date and Location: time, site, Volunteer, device.
- Update Participant Status: last date, year-to-date totals, next eligible date.
- Write the distribution and decrement stock.
- Receipt: printed, or sent by the participant's preferred method.
- Return to ready for the next participant.

**Alternate flows**
- 3.2.1 Proxy collects: pick an authorised proxy, or add one with the participant's phone authorisation; proxy name stored on the distribution.
- 3.2.2 Emergency (not yet due): record the hardship reason. An Admin authorises in the session, or the Volunteer enters a pre-approved reference. Flagged as emergency for reporting.
- 3.2.3 Partial fulfilment: propose the available quantity and substitutes of the same species and form; record the shortfall.
- 3.2.4 Unaltered pet in the household: offer UC-08 before the participant leaves. A deferral is recorded so the offer is repeated next visit.
- 3.2.5 Offline: write to a local queue, decrement a local stock view, mark pending. On reconnect, submit in the order recorded, re-run the frequency and allotment checks, and report any failures.

**Exception flows**
- 3.3.1 Participant Inactive, Deleted or flagged (no-shows, incident): show the flag and reason; refuse. An Admin can lift or override via Update Participant Status with a reason.
- 3.3.2 Request exceeds the allotment: refuse the excess. An Admin can authorise over-allotment with a reason; flagged for reporting.
- 3.3.3 No suitable stock: record the unmet request against participant and event; offer the next event's reserve list; no distribution written.
- 3.3.4 Already served at this site today: refuse unless the Volunteer confirms a genuine second issue and gives a reason.
- 3.3.5 Save fails: roll back the stock decrement; keep the selection; offer retry or queue.

**Special requirements**
- Distribution and stock decrement succeed or fail together.
- A committed distribution is never edited; corrections are reversing entries that reference the original and carry a reason.
- Offline for at least one full event, then sync with no loss or duplicates.
- 60 s or less of Volunteer time for a two-pet household; 2 s or less per step.
- Frequency rule, allotment formula and over-allotment authorisation level configurable by an Admin, and the values in force stamped on each distribution.
- Receipt shows date, site, products, quantities and next eligible date, and never the address.

**Tables**
- Read: `distribution_event` (status Open), `event_check_in`, `participant`, `participant_alert`, `pet`, `participant_proxy`, `allotment_rule`, `size_band`, `system_setting` (`frequency_rule_days`), `product`, `product_barcode`, `item_category`, `species`, `site_stock`, `distribution` (duplicate check via `ix_distribution_1`)
- Write: `distribution`, `distribution_line`, `distribution_pet`, `inventory_transaction`, `site_stock`, `participant`, `participant_proxy`, `event_check_in` (Served / No Stock / Reserve List), `participant_alert` (resolve/override), `snv_followup` (Offer Deferred), `audit_log`
- `distribution` columns used: client_uuid, is_emergency, emergency_reason, is_over_allotment, override_reason, authorized_by, authorization_ref, second_issue_reason, reverses_distribution_id, reversal_reason, frequency_days_applied, allotment_rule_version, entitled_lbs, local_date, receipt_sent_via, sync_status
- `distribution_line` columns used: line_type Issued/Declined/Shortfall, substitutes_line_id, unit_weight_lbs

## UC-07 View Participant Distribution History
**Actor:** Volunteer, Admin. Read-only.

**Base flow**
- Select Participant.
- Summary: number of distributions, year-to-date total, last date, next eligible date.
- Page 1: date, site, Volunteer, products/quantities, pets served.
- View Distribution Details: emergency or over-allotment authorisation, shortfall, proxy.
- Audit the view.

**Alternate flows**
- 3.2.1 Filter by date range, site or food type; totals recalculated and labelled "filtered".
- 3.2.2 Print summary: one page with dates, sites and quantities, no internal notes or flags; audited.
- 3.2.3 Include referrals: spay/neuter referrals with status and outcome, merged into the timeline.

**Exception flows**
- 3.3.1 No history: show the registration date and "eligible immediately".
- 3.3.2 Some distributions are at sites the user cannot see: count them in totals but hide their site and detail.
- 3.3.3 Timeout: show the latest 25 and load more on demand.

**Special requirements**
- No create, edit or delete of any kind.
- 25 per page; totals cover the whole history unless filtered.
- First page in 2 s or less for up to 200 distributions.
- Keep history at least 7 years.
- Printed summary contains no other household's data and no internal flags or notes.

**Tables**
- Read: `participant`, `distribution`, `distribution_line`, `distribution_pet`, `distribution_event` (site; distribution has no site_id), `site`, `product`, `user_account`, `participant_proxy`, `snv_referral`, `snv_referral_status_log`, `clinic`, `user_site_access`
- Write: `audit_log` only

## UC-08 Refer Pet to Spay/Neuter Services
**Actor:** Volunteer; Admin maintains clinics and budget. Off-stage: Partner Clinic.

**Base flow**
- List pets; mark altered pets and pets with an open referral as unavailable.
- Select a pet.
- View Distribution Details for the latest distribution to confirm the household is active.
- Criteria: not altered; age and weight within clinic limits; no open referral; participant active.
- List clinics with location, species accepted and current wait.
- Agree a clinic and a date window.
- Reserve the voucher amount against the budget.
- Create the referral: unique voucher number, status Pending; pet snv_status becomes Referred.
- Expiry set at the configured interval.
- Produce the voucher and clinic instructions; send a copy by preferred method if the participant consented.
- Schedule a reminder before expiry.

**Alternate flows**
- 3.2.1 Several pets: check and reserve per pet; one voucher number each; one combined instruction sheet.
- 3.2.2 Transport needed: record it; add to the site's transport coordination list.
- 3.2.3 Participant declines: status Declined with reason; no budget reserved; set a re-offer date.
- 3.2.4 Own vet (non-partner): record practice and contact; issue a reimbursement-form referral flagged for Admin reconciliation.

**Exception flows**
- 3.3.1 Already altered or open referral: show the existing record and refuse. If the old referral has expired, the Volunteer may void it and continue.
- 3.3.2 Outside age or weight range: refuse, state the criterion, propose a reassessment date, record a deferral (appears on the follow-up list).
- 3.3.3 Budget exhausted: refuse; put the pet on the waiting list in request order; give the next allocation date; notify an Admin.
- 3.3.4 Clinic full or suspended: propose the next nearest clinic accepting the species.
- 3.3.5 Save fails after reservation: release the reservation; offer retry.

**Special requirements**
- Voucher number unique for the life of the programme and redeemable once; redeemed, expired or void numbers are never reissued.
- Reserve at issue; release on expiry, decline or void; committed and available always reconcile.
- Expiry configurable (normally 60 days); reminder before expiry; status Expired afterwards.
- Clinic directory (species, capacity, voucher rate) maintained by an Admin without code changes.
- Record the clinic's outcome, including surgery date.
- Issue, redemption, expiry, decline and void each audited with actor, time and reason.

**Tables**
- Read: `participant`, `pet`, `distribution` + `distribution_line` + `distribution_event`, `clinic` (status, period_capacity, current_wait_days, voucher_rate), `clinic_species_rule`, `voucher_budget`, `snv_referral`, `system_setting` (`voucher_expiry_days`), `policy_document` (SNV Explanation), `language`
- Write: `snv_referral`, `snv_referral_status_log`, `pet` (snv_status Referred/Deferred), `snv_followup` (Expiry Reminder / Re-offer / Reassess / Waiting List), `audit_log`
- `snv_referral` columns used: referral_type Clinic Voucher/Reimbursement, other_provider_*, needs_reconciliation, transport_requested, reserved_amount, budget_id, expires_on, preferred_window_*, decline_reason, outcome, surgery_date, outcome_source, material_language_code

## UC-09 Delete Participant
**Actor:** Admin. Supporting: Second Admin (for purge).

**Base flow**
- Show dependencies: number of pets, number of distributions and the latest date, open and closed referrals.
- Recommend deactivation instead and explain the difference.
- Confirm.
- Reason from a configured list: duplicate, created in error, erasure request, other (free text).
- Retention check allows a soft delete.
- Final confirmation says exactly what will happen.
- Status Deleted; pets set Inactive; removed from operational lists and Volunteer search.
- Immutable audit entry with a snapshot.
- Recovery window starts; confirmation shows its end date.

**Alternate flows**
- 3.2.1 Deactivate instead: UC-04 sets Inactive with a reason; still visible to Admins and can be reactivated.
- 3.2.2 Erasure request: record the request reference; a second Admin approves. Remove identifying data; replace the participant on historical distributions and referrals with an anonymous ID; keep only the audit record of the erasure. Irreversible.
- 3.2.3 Already merged: skip the dependency review; go to the final confirmation.
- 3.2.4 Restore within the window: find via include-deleted search; give a reason; restore the previous status and the pets; audit.

**Exception flows**
- 3.3.1 Distributions within the retention period: refuse permanent removal; offer a soft delete.
- 3.3.2 Pending or Scheduled referral: refuse until it is completed, declined or voided.
- 3.3.3 Not an Admin: refuse; show the required role; audit.
- 3.3.4 Save fails: nothing changes; offer retry.

**Special requirements**
- Soft delete by default; purge needs a reason and a second Admin.
- Totals already reported to funders never change; purged records are anonymised, not removed.
- Deletion audit (reason and snapshot) immutable and kept beyond the record's life.
- Recovery window configurable (normally 30 days).
- Not shown in the Volunteer menu.

**Tables**
- Read: `participant`, `pet`, `distribution`, `snv_referral`, `system_setting` (`recovery_window_days`)
- Write: `participant` (status, deleted_at, deleted_by, delete_reason, restorable_until, is_anonymized, personal-data columns), `pet`, `erasure_request` (approved_by, anonymous_ref), `audit_log` (snapshot)
- Purge must also touch: `participant_proxy`, `participant_consent`, `service_note`, `intake_answer`, `participant_alert`, `registration_draft`

## UC-10 Delete Pet
**Actor:** Admin. The Volunteer can only request it.

**Base flow**
- Show dependencies: distributions that counted the pet, and any referral with its status.
- Offer Set Inactive instead and explain when each applies.
- Confirm.
- Reason: duplicate, entered in error, wrong household, other.
- Dependency check passes.
- Warn that the allotment will be recalculated; confirm.
- Mark deleted and remove from the active list.
- Recalculate the allotment and next entitlement.
- Immutable audit with a snapshot.
- Show the new allotment.

**Alternate flows**
- 3.2.1 Set Inactive instead: via UC-05; history stays in reports.
- 3.2.2 Wrong household: transfer instead (UC-02 lookup); relink; recalculate both; audit.
- 3.2.3 Duplicate pet (same microchip, or same name + species + date of birth within the household): pick the survivor; move referral history to it; then delete.

**Exception flows**
- 3.3.1 Pending or Scheduled referral: refuse until voided (releases budget).
- 3.3.2 Pet counted in committed distributions: refuse deletion; offer Set Inactive.
- 3.3.3 Volunteer tries it: refuse; offer to raise a request to an Admin; audit.
- 3.3.4 Save fails: nothing changes; offer retry.

**Special requirements**
- Never change previously reported totals.
- Allotment recalculated in the same transaction.
- Immutable audit snapshot kept for the statutory period.
- Not in the Volunteer menu.

**Tables**
- Read: `pet`, `distribution_pet` (blocks deletion if rows exist), `snv_referral`, `participant`, `allotment_rule`, `size_band`
- Write: `pet` (status Deleted, deleted_*, participant_id on relink), `pet_household_history`, `snv_referral` (pet_id moved to survivor), `participant` (current_allotment_lbs), `audit_log`

## UC-11 Manage User Accounts
**Actor:** Admin. Off-stage: Prospective User.

**Base flow**
- Account list: name, role, sites, status, last login.
- Create: name, organisational email, role, sites, start date, optional expiry, onboarding completed (programme rules and data-handling training).
- Check the email is unused and the role/site combination is valid.
- Status Pending; temporary credential; email invitation.
- Audit: who created it, role, sites.
- Shows Pending until the first login.

**Alternate flows**
- 3.2.1 Change role, sites or expiry with a reason: invalidate the user's sessions immediately; audit.
- 3.2.2 Reset password or unlock: clear the lockout counter; temporary credential; force a change at next login; notify the user; audit.
- 3.2.3 Deactivate with reason and effective date: end sessions; keep all historical attribution.
- 3.2.4 Bulk import of a volunteer roster file: validate every row; preview accepted and rejected; create only confirmed rows; one invitation and one audit entry each.

**Exception flows**
- 3.3.1 Email already used: show the existing account; offer reactivate or edit.
- 3.3.2 Role/site combination not allowed (e.g. a Volunteer assigned to every site): refuse and state the rule.
- 3.3.3 Would leave no active Admin: refuse.
- 3.3.4 Admin edits their own role or sites: refuse; a second Admin must do it; audit.
- 3.3.5 Invitation bounces: stay Pending; offer resend to a corrected address or print the credential.

**Special requirements**
- Least privilege by role and assigned sites.
- Temporary credential lasts 72 hours and works once.
- No self-elevation; always at least one active Admin.
- Role, site or status changes kill active sessions immediately.
- Deactivation never removes or anonymises historical attribution.
- Report of accounts with no login within a configurable period, for access review.
- Manage User Profile cannot change role, sites or status.

**Tables**
- Read/write: `user_account`, `user_site_access` (starts_at, ends_at, granted_by, grant_reason), `site`, `auth_token` (Temporary Credential), `user_session` (end_reason Permission Change / Remote Sign-out), `policy_acknowledgement` + `policy_document` (Confidentiality Agreement / onboarding), `audit_log`

## UC-12 Import Legacy Records
**Actor:** Admin. Off-stage: Data Steward.

**Base flow**
- List previous batches.
- Choose a type: participants, pets or distributions.
- Upload CSV or XLSX; detect encoding and delimiter; preview columns and rows.
- Map columns (or apply a saved mapping; ignore some columns); save the mapping.
- Validate every row with the same rules as UC-03 and UC-05.
- Duplicate check against the database and within the file.
- Report: accepted, accepted with warnings, rejected with reasons, duplicate candidates.
- Resolve each duplicate: create, update, or skip.
- Commit in one transaction with a Batch ID; every record tagged with the batch and as legacy.
- Import report (written, updated, skipped, rejected) kept with the rejected rows.

**Alternate flows**
- 3.2.1 Dry run: all checks, no writes.
- 3.2.2 Re-import corrected rejected rows: saved mapping applied automatically; linked as a continuation of the earlier batch.
- 3.2.3 Update matched rows: show the fields that would change; field-level audit per change.
- 3.2.4 Roll back a batch: allowed only if none of its records has been used in a distribution or referral; remove the created rows and reverse the updates; audit.

**Exception flows**
- 3.3.1 Unreadable or unsupported file: reject and list the supported formats.
- 3.3.2 Required fields not mapped: name them; refuse.
- 3.3.3 Rejection rate above the threshold: no commit offered; show the top reasons.
- 3.3.4 Over the size or row limit: reject; suggest splitting.
- 3.3.5 Commit fails partway: roll back completely; keep the mapping and validated file; offer retry.

**Special requirements**
- All or nothing.
- Batch ID and legacy source on every record, and visible in every report.
- Reversible while unused.
- Up to 25 MB or 50,000 rows; validation within 10 minutes.
- Exactly the same validation rules as interactive entry.
- Confirm a backup was taken; only outside distribution hours, unless the Admin has authority to import during them.
- Uploaded and rejected-row files stored encrypted and purged after a configurable period (normally 90 days).
- A failed or abandoned import is audited with the file name and reason.

**Tables**
- Read/write: `import_batch` (is_dry_run, continues_batch_id, backup_confirmed, status, counts, stored_file_path, purge_files_after), `import_mapping`, `import_rejected_row`
- Record tables written: `participant` (record_source Legacy, import_batch_id), `participant_site`, `pet` (import_batch_id), `distribution` (import_batch_id), `distribution_line`, `distribution_pet`, `distribution_event`
- Also: `audit_log`, `audit_field_change`, `system_setting` (`import_file_retention_days`)
- Validation reads: `service_area_postal_code`, `species`, `breed`, `size_band`, `product`

## UC-13 Generate Reports
**Actor:** Admin. Off-stage: Funder/Board. Supporting: Reporting Replica.

**Base flow**
- Catalogue in 3 groups, plus the user's saved and scheduled reports.
- Selecting a report runs UC-14, UC-15 or UC-16.
- Parameters default to the current programme year and all authorised sites.
- Validate: date range well formed and within the allowed span; sites within the Admin's authority.
- Run against the reporting replica.
- Show a chart and a table with the parameters, when the data was current, and metric definitions.
- Drill down to the underlying records.
- Export PDF, CSV or spreadsheet; the export is audited with its parameters.

**Alternate flows**
- 3.2.1 Saved report: stored parameters; relative ranges (e.g. last complete month) move forward.
- 3.2.2 Schedule: frequency, format, recipients. Recipients must be named accounts if the report has participant data. Confirm the next run; audit.
- 3.2.3 Compare two periods: side by side, with absolute and percentage change.
- 3.2.4 Too big for interactive: queue as a background job; notify when done; keep the result for a configurable period.

**Exception flows**
- 3.3.1 No data: show an empty result with the parameters; offer to widen.
- 3.3.2 Out of bounds: refuse; state the limit; suggest an allowed alternative.
- 3.3.3 Fails or times out: never show a partial aggregate; offer retry or background.
- 3.3.4 Export fails: keep the on-screen result; offer another format.
- 3.3.5 Replica down: say when it was last current; never fall back to the live database.

**Special requirements**
- Reports never slow down UC-06.
- Interactive result in 30 s or less; otherwise background.
- One definition per metric (households, pets, pounds, referrals completed), shown with each result.
- Every report and export states when its data was current.
- Aggregate by default; identifiable data needs the elevated permission and a logged reason.
- Every export audited (actor, parameters, format, destination) with a footer naming the report, parameters and date.
- Funders' required formats produced without re-keying.

**Tables**
- Read/write: `saved_report` (parameters JSON, schedule_frequency None/Weekly/Monthly/Quarterly, schedule_format PDF/CSV/XLSX, next_run_at), `report_recipient`, `report_run` (Queued/Running/Complete/Failed, data_current_as_of, output_path, retain_until), `audit_log`
- Read: `user_account` (can_extract_identifiable), `user_site_access`, `site`, `grant_commitment`
- Backlog only, not the use case: `report_narrative` (US-34), `metric_threshold` (US-35)

## UC-14 Distribution Reports (included by UC-13)
**Actor:** Admin. Off-stage: Funder.

**Base flow**
- Variants: totals by period, site, food type/product, and volunteer; households and pets served; visits per household; shortfalls and unmet requests.
- Parameters: dates, sites, food form, species, product, emergency distributions (include, exclude or separate).
- Check the range is within the retention period.
- Aggregate committed distributions, excluding reversed ones.
- Chart, table, definitions, data currency.
- Drill down, subject to the participant-data disclosure rules.

**Alternate flows**
- 3.2.1 Group by month, quarter or programme year, using the site's local time zone.
- 3.2.2 Actual vs target, with target entered or read from grant commitments; show variance and % of period elapsed.
- 3.2.3 Volunteer summary: visits and shift dates, with no ranking or performance framing.
- 3.2.4 Range includes legacy batches: legacy and system figures shown as separate series, labelled as captured differently.

**Exception flows**
- 3.3.1 None in period: a zero result is reported as a fact.
- 3.3.2 Product with no unit weight: report units, leave it out of pounds, and state the exclusion prominently.
- 3.3.3 Site outside authority: exclude it and say so on the report.
- 3.3.4 Range goes back past retention: truncate and say so.

**Special requirements**
- One shared definition for pounds, households, pets and visits.
- Period assigned by the site's local date.
- Reversals excluded; the count of reversals available separately.
- Pre-computed aggregates state when they were computed and are never shown as live.
- Distribution totals reconcile with inventory decrements, and discrepancies can be reported.

**Tables**
- Read: `distribution` (local_date, is_emergency, reverses_distribution_id, import_batch_id), `distribution_line` (unit_weight_lbs, line_type), `distribution_pet`, `distribution_event`, `site` (time_zone), `product`, `item_category`, `species`, `user_account`, `event_check_in` (unmet / reserve list), `inventory_transaction`, `site_stock`, `grant_commitment`, `import_batch`, `pet`
- Write (via UC-13): `report_run`, `audit_log`

## UC-15 Participant Reports (included by UC-13)
**Actor:** Admin. Off-stage: Funder/Board.

**Base flow**
- Variants: population by status; new registrations by period; by postal code or service area; household size and pet ownership; lapsed; flagged; referral source.
- Parameters: dates, sites, status, postal code, lapse threshold in days; aggregate (default) or identifiable.
- Check site authority.
- Aggregate, excluding records deleted before the period starts; each household counted once.
- Small-cell suppression.
- Chart, table, definitions, suppression rule, data currency.

**Alternate flows**
- 3.2.1 Outreach list of lapsed participants: purpose recorded; only people who consented to contact. Fields limited to name, ID, preferred contact method and last distribution date. Extraction, purpose and recipient audited.
- 3.2.2 Cohort retention: by registration month, share still receiving at 3, 6 and 12 months.
- 3.2.3 Geographic: participants and distributions by postal code, marked in or out of the service area, suppressed where sparse.
- 3.2.4 Flag review: flags by reason, date set and who set them; mark flags older than the review interval.

**Exception flows**
- 3.3.1 Empty population: zero result; offer to relax the parameters.
- 3.3.2 Identifiable result without the elevated permission or a purpose: refuse; offer aggregate; audit the refusal.
- 3.3.3 Every cell suppressed: say so; suggest a coarser grouping or wider period.
- 3.3.4 Too large: hand back to UC-13 to run in the background.

**Special requirements**
- Aggregate by default.
- Suppress figures below the configured minimum (normally 5) in anything leaving the organisation.
- Purpose limitation: minimum fields; purpose, recipient and field list audited.
- No outreach to anyone without contact consent.
- No ID-document or special-category data (the system collects none).
- Status definitions identical to UC-03 and Update Participant Status.

**Tables**
- Read: `participant` (status, registered_at, deleted_at, postal_code, household_size, referral_source, consent_to_contact, last_distribution_date), `participant_site`, `participant_alert` (created_by, created_at, review_due_on), `pet`, `distribution`, `distribution_event`, `service_area_postal_code`, `system_setting` (`lapse_threshold_days`, `small_cell_minimum`), `user_account` (can_extract_identifiable)
- Write: `report_run`, `audit_log`

## UC-16 Pet/SNV Status Reports (included by UC-13)
**Actor:** Admin. Off-stage: Partner Clinic, Funder.

**Base flow**
- Variants: pet population by species and status; unaltered pets; referrals issued, completed, expired and declined; completion rate by cohort; time to surgery; voucher spend vs budget; clinic activity; vaccination status.
- Parameters: dates, sites, species, clinic, referral status, cohort by issue date or surgery date.
- Check site authority.
- Aggregate, excluding pets deleted before the period; each pet counted once.
- Reconcile with clinic outcomes; referrals without a reported outcome count as Pending, not failures.
- Show cohort basis, definitions, and the currency of both system and clinic data.
- Offer follow-up lists: vouchers near expiry, referrals awaiting outcome, pets deferred for reassessment.

**Alternate flows**
- 3.2.1 Voucher spend: reserved, redeemed, released and remaining balance, reconciled to budget; commitments kept separate from spend.
- 3.2.2 Clinic activity: issued, redeemed, median time to surgery, outstanding; incomplete clinic reporting called out so it is not read as poor performance.
- 3.2.3 Unaltered pets list: households and next expected distribution; UC-15 extraction controls apply to any export.
- 3.2.4 Vaccination: by rabies status and currency; state the share with no status recorded.

**Exception flows**
- 3.3.1 No referrals: zero result, plus population figures if the variant includes them.
- 3.3.2 Many outcomes outstanding: report them as Pending, state the share, and hold back a misleadingly low completion rate.
- 3.3.3 Part of the cohort could not have completed yet (within the expiry period): mark it immature and report its rate separately.
- 3.3.4 No budget recorded: report issued and redeemed values without the comparison; advise setting a budget.

**Special requirements**
- Exactly six mutually exclusive referral statuses (Pending, Scheduled, Completed, Expired, Declined, Void), used the same way in UC-08 and here.
- Completion rate measured on the issue cohort, with the basis stated.
- Reserved value never reported as spend.
- Missing outcomes reported as outstanding, with the share stated.
- Lists of identifiable pets or households follow the UC-15 controls.
- Currency of system data and clinic data stated separately.

**Tables**
- Read: `pet` (species_id, status, is_altered, snv_status, rabies_*, deleted_at), `species`, `snv_referral` (issued_at, surgery_date, outcome_reported_at, reserved_amount, redeemed_amount, expires_on, status), `snv_referral_status_log`, `clinic`, `voucher_budget`, `snv_followup`, `participant` (next_eligible_date), `distribution`, `system_setting` (`voucher_expiry_days`)
- Write: `report_run`, `audit_log`

---

## Requirements that recur across use cases

1. **Audit** (UC-01 to UC-13, UC-15, UC-16)
   - One immutable `audit_log` plus `audit_field_change` covering logins, denied attempts, record views, history views and prints, field changes, refused restricted edits, deletes with snapshot, restores, merges, transfers, voucher lifecycle (`snv_referral_status_log`), imports and rollbacks, exports, schedules and identifiable extractions (purpose, recipient, field list).
   - Immutability: the notes (§3) require MySQL grants that allow only INSERT and SELECT for the app user.
   - Retention periods differ: authentication 12 months or more; distributions 7 years or more; field audit for the record's life plus the statutory period; deletion audit beyond the record's life.
2. **Site scoping** (UC-01, 02, 03 §3.2.4, 04, 07 §3.3.2, 11, 13 to 16)
   - `user_site_access` (supports time-limited grants), `participant_site`, `participant.home_site_id`; distribution site comes via `distribution_event.site_id`.
   - Volunteers see their sites; Admins see all.
   - Other-site history counts in totals but its detail is hidden.
   - Reports exclude sites outside the user's authority and say so.
3. **Role enforcement and separation of duties**
   - Admin-only: restricted fields, deletes, import, reports, overrides (area, pet limit, emergency, over-allotment, flag lift).
   - Second Admin required for purge and for any change to one's own permissions.
   - At least one active Admin at all times.
   - Admin functions hidden from the Volunteer menu.
   - Elevated extraction via `can_extract_identifiable`.
4. **Optimistic locking** (UC-04 §4.2)
   - `row_version` exists on `participant` and `pet` only.
5. **Offline and sync** (UC-01 §3.3.3, UC-02 §3.3.3, UC-03 §3.2.3/§3.3.4, UC-05 §3.3.4, UC-06 §3.2.5/§3.3.5/§4.3, UC-01 §4.2 keep entry on timeout)
   - Schema support: `distribution.client_uuid` / `sync_status` / `synced_at`, `participant.record_source='Offline'`, `user_session.auth_method='Offline'`.
6. **Settings configurable without code changes**
   - In `system_setting` seed: `frequency_rule_days`, `household_pet_limit`, `voucher_expiry_days`, `recovery_window_days`, `lapse_threshold_days`, `small_cell_minimum`, `session_idle_minutes`, `max_failed_logins`, `import_file_retention_days` (plus backlog keys).
   - Versioned rules: `allotment_rule`.
   - Vocabularies: `species`, `breed`, `size_band`, `service_area_postal_code`, `policy_document`, `language`, `clinic`, `clinic_species_rule`, `voucher_budget`.
   - Values in force stamped on each distribution: `frequency_days_applied`, `allotment_rule_version`.
7. **Cards, receipts, barcodes, printed documents**
   - Participant card with barcode or QR of `participant_code` (UC-03), scanned in UC-02.
   - Product barcode scan (`product_barcode`) in UC-06.
   - Distribution receipt with no address (UC-06 §4.6).
   - Participant history summary (UC-07 §3.2.2).
   - Voucher and clinic instruction sheet, one per visit, in the participant's language (UC-08; `material_language_code`, `policy_document` SNV Explanation).
   - Printable temporary credential (UC-11 §3.3.5).
8. **Exports**
   - PDF, CSV and XLSX (UC-13 §11, `saved_report.schedule_format`), with footer and audit.
   - Search results must never be exportable (UC-02 §4.4).
9. **Email and SMS**
   - Password reset link (UC-01)
   - Lockout alert to Admin (UC-01)
   - Invitation and temporary credential, reset/unlock notice (UC-11)
   - Receipt by preferred method (UC-06)
   - Voucher copy (UC-08)
   - Expiry reminders (UC-08)
   - Budget-exhausted alert to Admin (UC-08)
   - Scheduled report delivery to named accounts (UC-13)
   - Background-job-done notice (UC-13)
   - Sync-failure notice (UC-03)
   - All subject to `consent_to_contact` and `preferred_contact_method` (Phone/SMS/Email/None) and `user_account.notification_prefs`.
10. **Scheduled jobs (cron)**
    - Expire vouchers and release budget; expiry reminders; follow-up lists.
    - Scheduled and queued reports; retention cleanup (`report_run.retain_until`).
    - Import file purge (`purge_files_after`).
    - Idle and absolute session expiry; temporary credential and reset token expiry; lockout expiry.
    - Audit and history retention purge; year-to-date reset at programme-year boundary.
11. **Response-time targets**
    - Authentication 3 s (95th percentile); search 2 s over 20k participants; each distribution step 2 s and 60 s per household.
    - History 2 s up to 200 distributions; import 10 minutes for 50k rows / 25 MB; interactive reports 30 s.
    - Intake 5 minutes (two pets); tablet UI with 44 pt targets.
12. **Privacy**
    - Data minimisation; no ID-document images.
    - Minimal fields in search results; photos visible only to authorised users.
    - Receipt and summary contain no address, flags or other households.
    - Small-cell suppression; purpose limitation; aggregate by default.
    - Encrypted import files; anonymise on erasure instead of deleting.
13. **Immutability and correction by reversal**
    - `distribution` (reversing rows), `audit_*`, `snv_referral_status_log`.
    - Stock ledger `inventory_transaction` with `site_stock` in the same transaction.
14. **Admin screens the requirements need but no use case describes**
    - Service-area postal codes; allotment rules and size bands; species, breed and colour lists; `system_setting` editor; policy/consent text versions; deletion reason lists.
    - Sites; device registration.
    - Distribution event scheduling and open/close (UC-06 needs an open event).
    - Stock receiving and counting (UC-06 needs stock loaded; `stock_receipt`, `stock_receipt_line`, `inventory_count`, `inventory_count_line`); product catalogue and barcodes.
    - Clinic directory and species rules; voucher budgets; recording clinic outcomes (UC-08 §4.5, `clinic.portal_access_hash`).
    - Transport coordination list; SNV follow-up lists; grant commitments.
    - Erasure approval queue; audit log viewer; access-review report.

---

## Gaps: things in the use cases the schema does not support (or contradicts)

**Contradictions to resolve**
1. **Rule "never UPDATE or DELETE `distribution`" conflicts with three flows.**
   - Merge moves distributions to the survivor (UC-04 §3.2.4).
   - Import rollback removes legacy distributions (UC-12 §3.2.4).
   - Erasure replaces the participant on historical distributions (UC-09 §3.2.2).
   - Options: follow `participant.merged_into_id` at query time instead of updating; make rollback a controlled procedure limited to `import_batch_id` rows; anonymise the `participant` row in place (FK unchanged).
   - The same question applies to UC-10 §3.2.3, which moves `snv_referral.pet_id`.
2. **Erasure vs immutable audit.**
   - `audit_log.snapshot` / `details` and `audit_field_change.old_value` / `new_value` hold personal data. UC-09 §3.2.2 says to keep only the erasure's own audit record.
   - Personal data also sits in `participant_consent.consenting_person`, `participant_proxy`, `service_note`, `intake_answer`, `registration_draft.form_data`, `import_rejected_row.raw_row` and `participant_alert.reason`.
   - `participant.legal_first_name`, `legal_last_name` and `postal_code` are NOT NULL, so anonymising needs placeholder values.
3. **`snv_referral.voucher_number` is NOT NULL and UNIQUE.**
   - A Declined referral (UC-08 §3.2.3) would use up a voucher number, against UC-08 §4.1 and the failure post-condition.
   - Fix: make it nullable (a MySQL unique key allows many NULLs) and assign it only when issued.

**Missing columns or tables**

4. **Offline registration has no provisional ID.**
   - `participant` has no `client_uuid` or provisional code, so a provisional ID cannot be swapped for the permanent one or de-duplicated on sync (UC-03 §3.2.3).
   - `pet` has no `client_uuid` for queued saves (UC-05 §3.3.4).
   - No table holds an in-progress distribution to restore after session timeout (UC-01 §4.2).
5. **Soft-delete restore** (UC-09 §3.2.4): the previous status is not stored, and nothing marks which pets the deletion set Inactive (`pet.inactive_reason` enum has no such value). The only source would be the audit snapshot.
6. **No in-app notification or work-queue table.** Needed for:
   - Lockout alert to Admin (UC-01 §3.3.1); home-screen alerts (UC-01 step 11)
   - Sync failures (UC-03 §3.2.3)
   - Restricted change referred to Admin (UC-04 §3.3.3); microchip conflict referred (UC-05 §3.3.2)
   - Budget exhausted (UC-08 §3.3.3)
   - Volunteer asks Admin to delete a pet (UC-10 §3.3.3)
   - Background report finished (UC-13 §3.2.4)
   - Legacy `dbmessages` / `inbox.php` could be adapted.
7. **Unmet request with no distribution** (UC-06 §3.3.3): `event_check_in.outcome='No Stock'` keeps no product or quantity. The next event's reserve list needs a check-in row on a future event that may not exist yet.
8. **Import gaps.**
   - `import_batch.record_type` has no 'User' value for roster import (UC-11 §3.2.4).
   - `import_batch.status` has no Queued/Running for background validation.
   - Nowhere to keep "accepted with warnings" rows or per-row duplicate decisions.
   - `audit_log` has no `import_batch_id`, so rollback of updates depends on JSON `details`.
   - `import_rejected_row.raw_row` is plain TEXT, but UC-12 §4.7 requires encryption.
   - A legacy distribution needs NOT NULL `event_id`, `client_uuid`, `entitled_lbs`, `allotment_rule_version`, `frequency_days_applied` and `pets_served`, so synthetic legacy events and placeholder values are required.
   - No permission for "may import during distribution hours" (UC-12 §4.6).
9. **Settings missing from `system_setting`:**
   - Login: `lockout_minutes`, `session_absolute_hours` (12), `password_min_length`, `password_max_age_days`, `trusted_device_days`, `temp_credential_hours` (72), `reset_link_minutes`
   - Search and history: `search_max_results` (100), `recent_participants_days` (30), `history_page_size` (25)
   - Pets and distribution: `photo_max_mb` (5), `over_allotment_auth_level` (UC-06 §4.5)
   - Year and retention: `programme_year_start` (UC-07, UC-14), `distribution_retention_years` (7), `auth_audit_retention_months` (12)
   - Import: `import_max_file_mb` (25), `import_max_rows` (50000), `import_max_reject_pct`
   - Reports: `report_max_span`, `report_interactive_seconds` (30), `report_result_retention_days`
   - SNV, flags and accounts: `voucher_reminder_days`, `flag_review_days`, `account_review_days`
   - Rule for a Volunteer's maximum number of sites (UC-11 §3.3.2)
10. **Missing lists.**
    - Colour list (UC-05 §4.5; `colour_markings` is free text).
    - Deletion reasons for participants and pets (UC-09 step 5 and UC-10 step 5 say "configured list"; `delete_reason` is free VARCHAR).
    - Referral-source and proof-of-residence lists (free text; this weakens UC-15 grouping).
    - Deactivation reasons.
11. **Nearest site or clinic** (UC-04 §3.2.1, UC-08 §3.3.4): no coordinates on `site`, `clinic` or `participant`, and no link saying which clinics serve which sites ("clinics available to the participant"). Nearest site can be approximated from `service_area_postal_code.site_id`.
12. **Status over time for reports** (UC-15 population by status, UC-16 pets by status): with `participant_status_log` dropped, past status must be rebuilt from `audit_field_change`, which is heavy.
13. **Vaccination** covers rabies only (`rabies_vaccinated_on`, `rabies_expires_on`). The "V" in SNV, i.e. vaccination referrals, has no model; `referral_type` is Clinic Voucher or Reimbursement only.
14. **Smaller missing columns.**
    - `pet` has no `limit_override_by` and no explicit exception flag (only `limit_override_reason`).
    - The number of pets declared at registration (UC-03 step 6) is not stored.
    - `user_account` has no `row_version`, no deactivation effective date, and no display name (Manage User Profile).
    - Participant cards have no version or reissue field, so a lost card cannot be invalidated.
    - `participant.ytd_lbs_issued` needs a reset at the programme year boundary, but no programme year is defined.
    - `allotment_rule.food_form` (Dry/Wet/Any) does not match `product.food_form` (Dry/Wet/Treat/Other).
15. **Intentional differences from the use cases.**
    - "Referred Out" is not a participant status; it is `referred_out_applicant`, with no personal data (UC-03 §3.3.3 says "record the applicant as Referred Out").
    - "Flagged" is derived from `participant_alert` rather than being a status.
    - The reporting replica is not modelled; `report_run.data_current_as_of` only states data currency.
    - Coordinator and Board roles exist in the schema but in no use case.
16. **"No Participant ID consumed on failure"** (UC-03 post-condition): InnoDB AUTO_INCREMENT leaves gaps after a rollback. If `participant_code` comes from `participant_id`, allocate it inside the transaction from a separate counter.

---

## Probably out of scope for a plain PHP web app (or needs new infrastructure)

The legacy app already has PHPMailer, PhpSpreadsheet, client-side jsPDF with autotable (`js/`), Chart.js, Bootstrap, jQuery, and cron scripts (`scheduledSend.php`, `autoCheckOut.php`).

- **True offline mode** (UC-01 §3.3.3, UC-02 §3.3.3, UC-03 §3.2.3, UC-05 §3.3.4, UC-06 §3.2.5 and §4.3).
  - Needs a PWA: service worker, IndexedDB (encrypted, since it would hold participant data on devices), a cached credential or offline PIN check, a local stock view, a sync API that is idempotent on `client_uuid`, and conflict and failure reporting.
  - No service worker exists today.
  - Suggestion: online-only first release, with a paper fallback sheet and later entry of backdated distributions (`distributed_at` and `local_date` set to the event date). Offline as a separate later phase.
- **Reporting replica** (UC-13 §4.1, §3.3.5): depends on hosting. First release: same database through a read-only MySQL user, background jobs in `report_run` run by cron, and optional nightly summary tables whose currency is stated.
- **Immutability through MySQL grants or triggers**: needs database admin rights; shared hosting may block grants, or triggers when binary logging is on. Fallback is enforcement in the PHP data layer only.
- **SMS** (receipts, vouchers, reminders): needs a paid gateway (e.g. Twilio). Email (PHPMailer) and print are feasible.
- **Bounce detection** (UC-11 §3.3.5): PHPMailer only sees synchronous SMTP failures; later bounces need provider webhooks.
- **Breached-password check** (UC-01 §4.1): needs a bundled list or an outbound call to the HIBP range API.
- **Server-side PDF** for scheduled or emailed reports, vouchers and receipts: no server PDF library in `vendor/`. Add dompdf, mPDF or TCPDF through composer; jsPDF only works for interactive downloads.
- **Barcodes.**
  - Generating card barcodes or QR needs a new library (e.g. picqer/php-barcode-generator or JsBarcode / qrcode.js).
  - USB or Bluetooth scanners act as keyboards and are simple.
  - Camera scanning on tablets needs a JS library (html5-qrcode or BarcodeDetector).
  - Thermal receipt printers depend on hardware; browser print CSS is the realistic baseline.
- **Large imports** (50k rows / 25 MB within 10 minutes): need php.ini changes (`upload_max_filesize`, `post_max_size`, `max_execution_time`, `memory_limit`) and chunked or background validation run by cron. Current `import_batch.status` has no queued state.
- **Photo compression and encrypted file storage**: GD or Imagick extension; encryption keys managed outside the database. `emailEncryption.php`, `upload_encrypted_image.php` and `serve_image.php` can be reused as the pattern.
- **Immediate session invalidation** (UC-11 §4.4) and the 12-hour absolute timeout: feasible, but every request must check `user_session`; PHP's native sessions alone cannot do it.
- **Response-time targets at the 95th percentile**: realistic with the indexes provided. Measuring them needs monitoring that is not in scope.
- **Funders' grant report formats** (UC-13 §4.7): the formats are not specified; the client must supply them.
- **Clinic-facing outcome portal** (`clinic.portal_access_hash`): comes from backlog US-22, not a use case. Outcomes could be staff-entered in the first release.
