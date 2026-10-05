<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

# Requirements coverage review: skeptic findings

Sources. The plan is the `# PFPMS implementation plan` result in `C:/Users/maryw/.claude/projects/C--Users-maryw-Documents-Pelican-chsPetPantry/000a1e0f-45b4-4d2a-b002-70c26ee45869/subagents/workflows/wf_affff694-843/journal.jsonl`, cited as "L###" (line in that result). I re-extracted `C:/Users/maryw/Documents/Pelican/chsPetPantry/docs/PFPMS_User_Stories_Backlog.docx` and `docs/PFPMS_Use_Case_Specifications.docx`. I also parsed the edges of `docs/PFPMS_Use_Case_Diagram.drawio`, and read `docs/PFPMS_schema_v2.sql` and `docs/PFPMS_schema_v2_notes.md`.

## Findings (most severe first)

**1. US-32 (Must) is only partly in Release 1**
- **Claim:** "All 6 Must stories are in Release 1 (P1–P5)" (§9, L730).
- **Verdict:** WRONG.
- **Evidence:** US-32 AC1 says "A template file is downloadable for each record type". P5 only offers `import_template.php?type=Participant|Pet` (L397). The schema record types are `ENUM('Participant','Pet','Distribution')` (schema L784/794), and 0009 adds 'User'.
- **Fix:** Either define the Distribution template (and the User template) in the P5 `FieldRegistry`, or mark US-32 as split P5/P7 in the matrix and drop the "all Musts in R1" claim.

**2. US-03 (Must) is not enforced on the Station login path**
- **Claim:** P1 "US-03 (policy acknowledgement gate…)" (L189). The ack step appears only in the `Page::start` comment (L115).
- **Verdict:** RISKY.
- **Evidence:**
  - `Api::start` is described only as returning "401/403/409/422/503 JSON" (L127). It says nothing about forced change or policy ack.
  - `api/auth/login` is allowlisted as public (L127).
  - The Station views have no ack view (L96).
  - A volunteer whose first login is on a tablet gets a vault, a grant and a pack download (L279, L310) without acknowledging. US-03 AC1 and AC2 say "before I see any participant data".
- **Fix:**
  - `Api::start` and `api/auth/login` return 403 `policy_ack_required` / `password_change_required` before issuing a grant or pack.
  - The Station login routes to the ack.
  - Add a test to the P2B exit criteria.

**3. US-05 appears in P3 by ID only**
- **Claim:** P3 heading "…US-05…" (L287).
- **Verdict:** WRONG (the behaviour is missing).
- **Evidence:**
  - AC2 "matches on either name and displays the preferred name first": the search ranking at L289 has no preferred name.
  - AC3 "Receipts and voucher paperwork use the preferred name": the receipt (L352) and `voucher_print` (L390) do not mention it.
  - The schema has `preferred_name` plus index `ix_participant_2` (schema L150/193).
- **Fix:** Add preferred-name matching and display-first to `participant_search`, the Station search and the pack. Use the preferred name on the receipt (P4) and voucher (P5). Add exit tests for both.

**4. Flag/Unflag Participant and Distribution Restriction Alert have no creation path, and are placed in the wrong phase**
- **Claim:** "Flag/Unflag, Create Alert, … Distribution Restriction Alert: P3" (L709).
- **Verdict:** WRONG.
- **Evidence:**
  - P3 only has `participant_alert.php` for UC-18 view and UC-19 resolve (L294).
  - The `alert.create` capability exists (L132) but no page or service uses it.
  - In the diagram edges, Distribution Restriction Alert «extend» UC-06, and Update Participant Status «include» Flag/Unflag.
  - P4 `ParticipantStatus` (L342) only recalculates dates.
  - UC-06 §3.3.1 needs flags ("repeated no-shows or a documented incident"), and UC-15 §3.2.4 reports flags by reason, date and setter.
- **Fix:**
  - P3: add an alert-create page for types 'Eligibility Flag' and 'Distribution Restriction' with `blocks_distribution`.
  - P4: `DistributionService`/`ParticipantStatus` raise or clear restriction alerts on defined triggers.
  - Add "flag triggers and who may set them" to §8.

**5. UC-11 base step 8 (record onboarding completion) is not implemented**
- **Claim:** "UC-11 core" in P2A (L247).
- **Verdict:** WRONG.
- **Evidence:**
  - UC-11 step 8 requires recording that onboarding is complete, and the pre-condition says onboarding has been completed.
  - `user_account.onboarding_completed_at` exists (schema L46).
  - The plan never mentions onboarding.
- **Fix:** `admin_user_create`/`admin_user_edit` capture `onboarding_completed_at`. Decide whether activation or first login is blocked until it is set (open question).

**6. The Release-1 import lacks UC-12's safety flows, and the matrix contradicts the P5 exit criteria**
- **Claim:** "UC-12 … P5 base + §3.2.1; P7 §3.2.2–3.2.4, §3.3.x" (L697).
- **Verdict:** RISKY.
- **Evidence:**
  - The P5 exit criteria include §3.3.5 ("mid-commit failure leaves 0 rows", L406ff), which contradicts "§3.3.x in P7".
  - These are all deferred to P7 while P5 can already commit imports:
    - §3.3.1 unreadable file
    - §3.3.2 required columns absent
    - §3.3.3 rejection threshold (`import_max_reject_pct`)
    - §4.6 "only outside distribution hours" ("distribution-hours rule", L434ff)
    - §3.2.4/§4.3 rollback
  - UC-12 §1.2 calls import "controlled, reversible".
- **Fix:** Move §3.3.1–3.3.5, the distribution-hours or Open-event refusal, and `ImportRollbackService` (for unused rows only) into P5. Otherwise restrict the P5 import to before go-live and treat a restore from backup as the rollback.

**7. The UC-12 §4.7 90-day purge has no home in Release 1**
- **Verdict:** RISKY.
- **Evidence:**
  - P5 stores encrypted rejected rows (L398).
  - The "file purge" is only in P7 (L434ff).
  - P6 plus P7 add up to 70–87 dev-days after R1.
  - The setting `import_file_retention_days 90` exists (schema L1108).
- **Fix:** Add a cron job `imports:purge` in P5.

**8. The paper fallback and late-entry path is undefined, and it conflicts with the distribution guard**
- **Claim:** "Coordinators can enter late entries against a closed event within `late_sync_grace_days`" (L403). R5 says the Station is the only write path (L29).
- **Verdict:** RISKY.
- **Evidence:** `DistributionService::record()` checks "event Open at the device's site" (L339). No page or Station mode for backdated entry is listed in P4.
- **Fix:**
  - P4: add a Station "late entry" mode (a Coordinator capability; choose a Closed event within the grace period; `local_date` = event date; audited) that is exempt from the Open check.
  - Add it to the P4 exit criteria and to walkthrough step 12.

**9. US-28 AC2 and account expiry are not applied to offline grants**
- **Claim:** "lapse is enforced on every request" (L424).
- **Verdict:** RISKY.
- **Evidence:**
  - US-28 AC2 says "sessions lose that site's data at once".
  - Offline grant lifetime is `offline_grant_hours 72` (L486). The tablet's vault and pack let the user unlock offline after `user_site_access.ends_at` or account expiry (UC-01 step 7).
- **Fix:** Grant expiry = min(`offline_grant_hours`, `ends_at`, account `expires_at`), enforced in the vault unlock. Add a test in P2B/P6.

**10. The US-22 (Must) clinic portal shows more than AC2 allows**
- **Claim:** The portal "sees only that clinic's vouchers" (L393).
- **Verdict:** RISKY.
- **Evidence:** AC1 "look up a voucher number"; AC2 "sees only the pet and voucher concerned". A list of every voucher at the clinic exposes pets from many households.
- **Fix:** Lookup by voucher number only (the random Crockford number at L34 supports this), with no list. Add "may the clinic see the owner's name?" to §8.

**11. US-04 AC1 and AC3 are not specified**
- **Claim:** P3 "Events and check-in: US-04" (L306–308).
- **Verdict:** RISKY (ID only).
- **Evidence:** AC1 says checked-in households appear first in search with their check-in time; AC3 says typing reverts to a normal search. The plan's empty search shows "participants served here recently" (L289), which is UC-02 §3.2.2, not US-04. The same pattern applies to US-08 AC2 (drafts listed on the home screen), which is not stated.
- **Fix:** During an Open event, an empty search in the Station and in `participant_search` lists today's check-ins with their time above the recent list, and any criterion switches to normal search. List drafts on the UC-17 home screen.

**12. US-17 AC3 is missing**
- **Verdict:** WRONG.
- **Evidence:** AC3 says "Repeat declines of the same product are visible on the household record". P4 only stores Declined lines (L341).
- **Fix:** In P4, add a repeat-declines summary to `participant_view` and the Station participant view, and put the summary in the pack.

**13. US-01 AC2 cannot be verified in its matrix phase**
- **Claim:** US-01 is placed in P2B only (matrix; L266).
- **Verdict:** RISKY.
- **Evidence:** There is no open entry to preserve until P3 drafts and P4 distribution exist. The P2B exit criteria (L279–283) do not test entry preservation; only P4 walkthrough step 4 does.
- **Fix:** Matrix US-01 → P2B + P4. Add "PIN switch preserves the open registration/distribution draft" to the P3 and P4 exit criteria.

**14. The Release-1 go-live has no volunteer practice or training step**
- **Claim:** US-29 is deferred to P8 (L452).
- **Verdict:** RISKY.
- **Evidence:** US-29's story is practice "before my shift". Every volunteer is new at go-live, and the go-live checklist (L400–404) has no training item.
- **Fix:**
  - Add to the P5 checklist: supervised practice on staging with `seeds/dev` synthetic data (a separate origin, so separate PWA installs), and record `practice_completed_at`/`onboarding_completed_at`.
  - Add "is training required before go-live?" to §8.

**15. Release 1 has no way to extract reporting figures**
- **Verdict:** RISKY.
- **Evidence:**
  - UC-13 to UC-16 are all in P7, which is R2.
  - UC-14 §1.2 calls these "the figures on which grant claims … depend".
  - The only R1 view is the per-event US-18 dashboard.
- **Fix:** Pull UC-14 "totals by period/site" with the shared `Metrics` definitions and a CSV export into P5 or P6.

**16. Deferring UC-09 and UC-10 to P6 is acceptable only with mitigations**
- **Verdict:** RISKY.
- **Evidence:**
  - In R1, a pet entered in error can only be set Inactive, and the reasons are Deceased/Rehomed/Lost/Surrendered (schema L398). That distorts UC-16 reports and US-26.
  - UC-05 §3.3.2 offers "transfer the pet" and UC-10 §3.2.2 offers reassignment, but `pet_transfer` is in P6 (L422) while the matrix claims UC-05 §3.3.x in P3.
  - Duplicate participants cannot be merged until P6, so they can evade the frequency rule.
- **Fix:**
  - Pull the UC-10 base case (a pet with no `distribution_pet` rows) and `pet_transfer` into P5.
  - Document deactivation as the interim for UC-09, and a manual procedure for erasure requests during R1.
  - Correct the UC-05 matrix row.

**17. Two numeric requirements have no home**
- **Verdict:** UNVERIFIABLE.
- **Evidence:**
  - UC-01 §4.3 (authentication ≤3 s p95) and UC-03 §4.5 (two-pet registration ≤5 min) appear in no exit criterion. The performance list at L632 omits both.
  - The UC-01 §4.3 risk is real: server-side Argon2id plus client-side PBKDF2 at 600k iterations (R8) on a low-end tablet.
- **Fix:** Add both targets to the P2B, P3 and §7 performance checks, measured on the target tablet.

**18. UC-01 §4.1 ("block common and breached passwords") is off by default**
- **Verdict:** RISKY.
- **Evidence:** 0002 sets `password_hibp_check 0` (L486), and no common-password list is mentioned anywhere.
- **Fix:** Bundle an offline common and breached password list in `PasswordPolicy` (P1), keep the HIBP check optional, and state this in §8.

**19. US-21 AC1 and AC3 are not in `voucher_print`**
- **Verdict:** RISKY.
- **Evidence:** AC1 wants a link or QR code on the voucher, and AC3 wants the address, hours and phone printed in text. L390 has neither; only `clinic/info.php` exists (L392).
- **Fix:** Add a QR code (chillerlan) plus text clinic details to `voucher_print` and to the P5 exit criteria.

**20. Minor flow gaps that are nonetheless claimed as covered**
- **Verdict:** RISKY.
- **Evidence:**
  - UC-04 §3.2.1 "proposes the nearer distribution site": only "re-checks service area" (L290ff).
  - UC-08 step 6 clinic "current waiting time" and §3.3.4 "capacity … full": `clinic.period_capacity` exists (schema L627) but no check is described (L387).
  - UC-06 step 12 receipt "sent by preferred contact method": R14 lists it, but P4 has print only.
- **Fix:** Add these to P3, P5 and P4 respectively, or mark them deferred in the matrix.

**21. Print/Export traceability is wrong**
- **Claim:** "Print, Export PDF/CSV/Excel: P4 (history print) and P7" (L711).
- **Verdict:** WRONG (mapping only).
- **Evidence:** In the diagram, all four «extend» UC-13. The history print is UC-07 §3.2.2.
- **Fix:** Map these to P7 only, and list UC-07 §3.2.2 separately under P4.

**22. The open questions (§8) are missing important requirement ambiguities**
- **Verdict:** RISKY.
- **Evidence:** The notes §4 items are covered: 1, 2 and 4 are OQ1–3, and 3, 5 and 6 are adopted as decisions (P8 training DB, US-20 never stored, legacy tables deleted). These are missing:
  - (a) UC-13 §4.1/§3.3.5 require a reporting replica, but the plan runs on the same database ("read-only DB user if the host allows", L445). This deviation needs client acceptance.
  - (b) The UC-13 §4.7 funder grant formats are unspecified.
  - (c) Cross-site duplicate visibility. UC-03 §3.2.4/§3.3.1 need a candidate at another site, but UC-02 §4.5 and the P3 exit criterion "Volunteers never see another site's households" (L325) forbid opening it. Decide what minimal fields are shown and how "add site" works.
  - (d) Flag triggers and who may set them (#4).
  - (e) Whether the clinic portal may see the owner's name (#10).
  - (f) Whether training is required before go-live (#14).
  - (g) Whether onboarding blocks activation (#5).
- **Fix:** Add (a) to (g) to §8.

## Claims tested and confirmed
- The backlog counts match: 6 Must, 23 Should, 12 Could. The R1 Should split holds: 11 in R1, and 12 after R1 (US-02, 11, 12, 24, 25, 28, 29, 33, 36, 38, 39, 40).
- **US-06:** all 3 ACs are handled (two-way accent folding via collation and pack fold fixtures, the `utf8mb4_bin` accent-only warning, and sorting).
- **US-23:** all 3 ACs are handled (preferred-language voucher and explanation, Coordinator-maintained `admin_languages`, and fallback with a notice), in P2A/P5.
- **UC-06 and UC-07:** every UC-06 alternate and exception flow §3.2.1–3.3.5 has a concrete P4 bullet, and the numbers have homes: §4.4 60 s and UC-07 §4.2/§4.3 (25 per page, 2 s at 200 rows) in the P4 exit criteria.
- **Numeric requirements with homes:** UC-01 §4.2 30 min / 12 h (P1 session check plus P4 draft restore); UC-01 §4.5 12 months (`auth_audit_retention_months`, no purge, so compliant); UC-02 §4.1 2 s over 20k (P3 exit); UC-07 §4.4 7 years (`distribution_retention_years`); UC-12 §4.4 25 MB / 50k rows / 10 min and UC-13 §4.2 30 s (both P7). UC-17/18/19 and Detect Duplicate / Generate Duplicate Alert are placed with real behaviour in P1/P3.
