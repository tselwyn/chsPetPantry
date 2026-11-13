# SRS v2.2: changes to paste into Confluence

**Draft for review.** This lists what changes from SRS v2.1 (Oct 30, 2026) to v2.2. Each section below says where it goes in the SRS. Requirement IDs from v2.1 are kept. Changed text is marked `[v2.2: ...]`, as v2.1 did.

> **Before publishing:** the Sprint 5 plan also mentions "today's review decisions." Only the duplicate rule and the three open questions were written down where I could see them. Add anything else decided at that review to the Revision History and the matching section.

---

## Revision History (add above the v2.1 rows)

| Version | Date | Change | Source | Sections |
|---|---|---|---|---|
| 2.2 | Nov 2026 | Added UC-17 Record Food Received (it was only referenced from FR-072) | Team | 3.1, 3.3, 4.1, 8.4 |
| 2.2 | Nov 2026 | Duplicate participant rule defined: any match on phone, email, full name, or last name + street address. Registration and legacy import use the same check | Sprint 5 decision | UC-03, UC-12, FR-018, FR-047 |
| 2.2 | Nov 2026 | UC-08 counseling is now recorded on the Edit Pet page; the separate SNV page was removed | Sprint 5 UI cleanup | UC-08, FR-043 |
| 2.2 | Nov 2026 | Participant ID search accepts the PP00012 form | Team | UC-02, FR-008, FR-074 |
| 2.2 | Nov 2026 | Inactivity sign-out warns 2 minutes ahead and offers "Stay signed in" | Team | UC-01, NFR-SEC-005 |
| 2.2 | Nov 2026 | Database password kept out of the source code and deploy | Team | NFR-SEC-009, 2.7 |
| 2.2 | Nov 2026 | Participant deletion audit is visible to Administrators on the removal screen | Team | UC-09, NFR-SEC-007 |
| 2.2 | Nov 2026 | Lost-connection behavior defined | Team | NFR-REL-003 |
| 2.2 | Nov 2026 | Added three open questions for CHS (legacy import, required email, separate dog/cat cost) | Sprint 5 plan | Open Questions |

---

## 3.1 Table 3.1: add a row

| Use case | Volunteer | Administrator |
|---|---|---|
| UC-17 Record Food Received | No | Yes |

---

## 3.3: add UC-17 after UC-16

### UC-17 Record Food Received

**Primary Actor:** Administrator

**Preconditions:** Administrator is authenticated.

**Main Flow:**

1. Administrator opens Record Food Received.
2. System displays the food-received form and the most recent food-received records.
3. Administrator enters the date received, food type (Dog food or Cat food), pounds, total cost, and optional notes (for example supplier, invoice number, or donor).
4. System validates the entry.
5. System saves the record and shows it in the recent records list.

**Alternate / Exception Flows:**

- Donated food: Administrator enters a total cost of 0. The pounds still count toward cost per pound.
- Validation failure: a missing field, a future date, pounds of 0 or less, or a negative cost. System names the field and keeps the entered values.
- Entry made in error: Administrator deletes the record from the recent records list after confirming.
- Volunteer attempts to open the page: system refuses on the server and returns to the dashboard.

**Postconditions:** The food received is available to the cost per pound report (UC-14, FR-073).

---

## 3.3: replace these flows

**UC-02 Search for Participants, Main Flow step 2** `[v2.2: PP form]`
> User enters all or part of a participant name, address, phone number, or Participant ID. The ID may be typed as shown (PP00012) or as a number (12).

**UC-03 Register New Participant, Main Flow step 5** `[v2.2: rule defined]`
> System checks for possible duplicate participants: an existing participant with the same phone number, the same email, the same first and last name, or the same last name and street address. Case, spacing, and phone formatting are ignored, and a blank email never counts as a match.

**UC-08 Record SNV / Counseling Information** `[v2.2: on Edit Pet]`

Main Flow:
1. From the participant record, the user selects Edit on the pet.
2. The user reviews and updates the pet's spay/neuter and rabies vaccination status.
3. If counseling about CHS's free spay/neuter and vaccination services was given today, the user checks "Counseling ... was provided today."
4. System saves the pet and, if checked, records counseling as Yes with today's date and the account used.

Alternate flow, no counseling given: the checkbox stays unchecked, and any counseling already recorded stays on file.

**UC-09 Delete Participant, Postconditions** `[v2.2: audit visible]`
> The participant is marked Deleted and kept for history. An audit record stores the participant, their previous status, the account used, and the time. Administrators see "Deleted <date/time> by the <account> account" on the participant's removal screen.

**UC-12 Import Legacy Records, Main Flow step 4** `[v2.2: same rule as registration]`
> System identifies records that cannot be imported, and possible duplicates using the same rule as registration (UC-03 step 5), checked against existing participants and against other rows in the file.

---

## 4.1 Functional Requirements: replace or add

| ID | Requirement |
|---|---|
| FR-008 | The system shall allow authorized users to search for participants using Participant ID, typed either in the displayed form (PP00012) or as a number. `[v2.2: PP form]` |
| FR-013 | *Unchanged until CHS answers Open Question 2.* Note: the app currently treats email as optional at registration and on update. If CHS confirms email is optional, move Email to the optional list here and in 5.1. |
| FR-018 | The system shall flag a new registration as a possible duplicate when an existing participant has the same phone number, the same email, the same first and last name, or the same last name and street address, ignoring case, spacing, and phone formatting. A blank email shall not count as a match. `[v2.2: rule defined, Sprint 5]` |
| FR-043 | The system shall allow authorized users to record, when editing a pet, that spay/neuter and vaccination counseling was provided, saving the date and the account used. Previously recorded counseling shall not be cleared by an ordinary edit. `[v2.2: on Edit Pet]` |
| FR-047 | The system shall identify possible duplicate participants during legacy-data import using the rule in FR-018, against both existing participants and other rows in the import. `[v2.2: same rule]` |
| FR-072 | The system shall allow Administrators to record food received (date, Dog or Cat food, pounds, total cost, notes) as described in UC-17, with a cost of 0 for donated food. `[v2.2: refers to UC-17]` |
| FR-079 | The system shall allow Administrators to delete a food-received record entered in error, after confirmation. `[v2.2: new, UC-17]` |
| FR-080 | Food received shall be restricted to Administrators, enforced on the server. `[v2.2: new, UC-17]` |

---

## 4.2 to 4.5 Non-functional: replace

| ID | Requirement |
|---|---|
| NFR-SEC-005 | The system shall end an authenticated session after 30 minutes without a request and show a sign-in message explaining why. Two minutes before that, the system shall warn the user and offer to stay signed in. A user who has typed or clicked on the page in the last 5 minutes shall be kept signed in without interruption. `[v2.2: warning added]` |
| NFR-SEC-007 | The system shall record each participant deletion in an audit record containing the participant, their previous status, the account used, and a timestamp, saved together with the deletion. Administrators shall be able to view it. `[v2.2: clarified]` |
| NFR-SEC-009 | The system shall store passwords only as one-way hashes. No real password shall be kept in the source code, setup scripts, or deployed files; database credentials shall come from a configuration file kept only on each server. `[v2.2: clarified]` |
| NFR-REL-003 | When connectivity is lost, the system shall tell the user immediately, keep their entries on the page, prevent a form from being submitted until the connection returns, and ask for confirmation before the user leaves a partly filled form. It shall tell the user when it is safe to save again. `[v2.2: behavior defined]` |

---

## 2.7 Dependencies: add

- The live server keeps its own database connection file with the live login. It is not stored in GitHub and the deploy never overwrites it. Developer laptops use the team's local defaults. `[v2.2]`

---

## Open Questions for CHS: add

1. **Legacy records (UC-12).** Do you need the ~800 records in PetPantryMaster.xlsx imported? If yes, please send the spreadsheet. If no, UC-12 and FR-045 to FR-047 move to out of scope.
2. **Email at registration (UC-03, FR-013).** Is email required, or optional? Many participants may not have one.
3. **Food cost (UC-17, FR-072).** Do you track the cost of dog food and cat food separately? The app records them separately today and also reports a combined cost per pound.

---

## 7.1 Representative Acceptance Criteria: add rows

| Area | Acceptance Criterion |
|---|---|
| Food Received | Given the Administrator login, when a valid food-received entry is saved, then it appears in the recent records and its pounds and cost are included in the cost per pound report for its date. A Volunteer sending the form directly is refused. |
| Duplicate Rule | Given an existing participant, when a new registration shares only the phone, only the email, only the full name, or only the last name and street address, then a possible-duplicate warning is shown. A registration sharing only the last name is not flagged. |
| Inactivity | Given a signed-in user who makes no request for more than 30 minutes, then the next page request returns to the login page with the inactivity message. |
| Lost Connection | Given a partly filled distribution form, when the connection drops, then a warning appears, Save does not submit, and the typed values remain. When the connection returns, the warning says it is safe to save. |
| Deletion Audit | Given an Administrator deletes a participant, then one audit record with the account and time is saved and shown on the participant's removal screen. |

---

## 7.2 Traceability Approach: add

Automated tests live in `tests/` and run on every pull request into `dev` and `main`. Each test class names its use case and requirement.

| Test | Requirements |
|---|---|
| `tests/Unit/ParticipantIdTest.php` | UC-03, FR-074, FR-008 |
| `tests/Unit/SessionTimeoutTest.php` | UC-01, NFR-SEC-005 |
| `tests/Integration/ParticipantReportsTest.php` | UC-15, FR-049 |
| `tests/Integration/ParticipantDeletionAuditTest.php` | UC-09, NFR-SEC-007 |
| `tests/Integration/ParticipantSearchAndDuplicatesTest.php` | UC-02, UC-03, FR-008, FR-018 |

---

## 8.4 Requirement Status for Sprint Planning: Sprint 5 updates

| Requirement | Status | Notes |
|---|---|---|
| FR-049 DND in status report | Done | |
| FR-074 PP00001 IDs | Done | Shown on search, participant, pet, distribution, removal, and report pages; search and the history report accept PP IDs |
| NFR-SEC-005 30-minute sign-out | Done | Server-side check on every page, plus the 2-minute warning |
| NFR-SEC-007 Deletion audit | Done | Table is created automatically on first delete |
| NFR-SEC-009 No passwords in code | Done in code | SiteGround database password still has to be changed (it is in old Git history) |
| NFR-REL-003 Lost connection | Done | |
| UC-17 Record Food Received | Done | Specified in v2.2 |
| FR-022 / FR-023 Alerts admin-only | In progress | Abdulaziz |
| FR-027 Breed and weight required | In progress | Abdulaziz |
| FR-045 to FR-047 Legacy import | In progress | Rachelle; waiting on CHS (Open Question 1) |
| FR-013 Email required | Blocked | Waiting on CHS (Open Question 2) |
