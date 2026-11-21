# SRS v2.3 (draft): changes since v2.2

Paste into Confluence after the v2.2 changes (`docs/SRS_v2.2_changes.md`). This draft covers Sprint 6 work done so far. **Before final:** add the legacy import result, CHS's answers to the open questions, and CHS testing results, then mark built vs. dropped in 8.4.

---

## Revision History (add above the v2.2 rows)

| Version | Date | Change | Source | Sections |
|---|---|---|---|---|
| 2.3 | Nov 2026 | Pet age counts up from the date it was entered; no date of birth is collected | Team (resolves risk 6.2 and Open Question "Pet age") | FR-077, 5.2, 6.2 |
| 2.3 | Nov 2026 | Manage Users is on the admin dashboard; Administrators can reset an account's password from it | Team | UC-11, FR-044 |
| 2.3 | Nov 2026 | Pages left over from the starter application can no longer be opened | Team | NFR-SEC-002, NFR-SEC-003 |
| 2.3 | Nov 2026 | Requirement status (8.4) re-audited against the code | Team | 8.4 |

---

## 4.1: replace

| ID | Requirement |
|---|---|
| FR-044 | The system shall allow Administrators to manage user accounts, assigned roles, and passwords, including resetting an account's password to a new random temporary password, from a Manage Users option on the dashboard. `[v2.3: reset and dashboard access]` |
| FR-077 | The system shall accept pet age as a number of years from 0.1 to 40 with at most one decimal place, and shall not collect a date of birth. The system shall store the date the age was entered and display the pet's current age (the entered age plus the time since). Saving a pet without changing its displayed age shall keep the original age and date. `[v2.3: age counts up]` |

## 4.2: replace

| ID | Requirement |
|---|---|
| NFR-SEC-003 | The system shall protect participant personal information from access by unauthenticated users. Pages that are not part of the Pet Pantry shall not be reachable. `[v2.3: retired pages]` |

## 5.2 Pet Fields: replace the Age row

| Field | Required | Values / rules |
|---|---|---|
| Age | Yes | Years, 0.1 to 40, one decimal place. Shown as today's age; the date it was entered is stored with it |

## 6.2 Risks: replace the "Pet age stored as a number" row

| Risk | Impact | Mitigation / Requirement Response |
|---|---|---|
| Pet age stored as a number | An age entered once would go stale | **Resolved in v2.3:** the age is stored with the date it was entered and shown counted up (FR-077) |

## Open Questions for CHS: update

- **Pet age:** answered by the v2.3 change. Remove from the list.

---

## 8.4 Requirement Status for Sprint Planning (as of Nov 21, 2026)

| Requirements | Status | Notes |
|---|---|---|
| FR-045 to FR-047 | Not started | Legacy import; waiting on CHS spreadsheet (Rachelle) |
| FR-075 | Not started | Address lookup; optional, waiting on CHS. Recommend drop |
| FR-076 | Not started | Automatic status; optional, waiting on CHS rules. Recommend drop |
| FR-022 | Partial | Admin-only on the participant record; registration still lets Volunteers set the alert (Abdulaziz) |
| FR-078 | Partial | Language values done; phone shows as (540) 555-0101, not 540-555-0101 (Abdulaziz) |
| FR-013 | Partial | App makes email optional; SRS says required. Waiting on CHS |
| FR-027 | Partial | Breed and weight required; sex and rabies allow Unknown (open question) |
| NFR-SEC-009 | Partial | Done in code; SiteGround database password still to be changed |
| FR-044, FR-077, NFR-SEC-003 | Done | v2.3 changes above |
| FR-018, FR-021, FR-024, FR-030, FR-049 to FR-054, FR-056, FR-057, FR-059, FR-060, FR-072 to FR-074 | Done | Built in Sprints 4 and 5 |
| NFR-SEC-005, NFR-SEC-007, NFR-REL-003 | Done | 30-minute sign-out, deletion audit, lost-connection warning |
| FR-001 to FR-012, FR-014 to FR-017, FR-019, FR-020, FR-023, FR-025, FR-026, FR-028, FR-029, FR-031 to FR-043, FR-048, FR-055, FR-058, FR-061 to FR-071 | Done | |
| NFR-PERF-001 to 003 | Not tested | Test after the real records are loaded |
| NFR-ACC-001, NFR-ACC-005 | Not tested | Keyboard-only and 200% zoom (Abdulaziz) |
| NFR-USA-006 | Not tested | 2-minute distribution; test with CHS volunteers |

## 7.2 Traceability: add rows

| Test | Requirements |
|---|---|
| `tests/Unit/PetAgeTest.php`, `tests/Integration/PetAgeStorageTest.php` | UC-05, FR-077 |
| `tests/Unit/RetiredPagesTest.php`, `tests/Integration/RetiredPagesHttpTest.php` | NFR-SEC-002, NFR-SEC-003 |
