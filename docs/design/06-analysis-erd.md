<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

# ERD vs SQL check: `docs/PFPMS_schema_v2.sql`

## SQL counts
- **59** `CREATE TABLE` statements and **532** columns.
- **143** foreign keys (`ADD CONSTRAINT … FOREIGN KEY … REFERENCES`), all single-column. They sit in `ALTER TABLE` blocks starting at line 892.
- A plain grep for "FOREIGN KEY" finds 144 because the comment on line 892 contains the phrase.
- This confirms the notes' "59 tables and 143 foreign keys" (`PFPMS_schema_v2_notes.md` line 6).

## ERD files (all stored uncompressed)
| File | Pages | Tables | Lines drawn | Match to SQL |
|---|---|---|---|---|
| `PFPMS_ERD.drawio` (v1 draft) | 1: "PFPMS ERD" | 15 | 23 | Legacy draft, doesn't match |
| `PFPMS_ERD_v02.drawio` | 1: "PFPMS ERD v2" | 59 | 143 | Exact |
| `PFPMS_ERD_v3.drawio` | 1: "PFPMS ERD v3" | 59 | 143 | Exact |
| `PFPMS_ERD_v2.drawio` (newest, 16:18) | 7: Table Map, Security & Access, Participants, Pets, Distribution & Inventory, Spay / Neuter Referrals, Governance, Import & Reporting | 59 distinct (subject areas 8/12/6/14/6/13) | 87 | Exact |

**v02 vs v3:** same size, different SHA-256. They differ in only 3 bytes: the diagram id, the page name and the title text ("v2" vs "v3"). The content is otherwise identical. The v02 file is titled "ERD v2" but is not the 7-page v2 file, so the naming is confusing.

**Which file matches the SQL:** `PFPMS_ERD_v2.drawio`, `PFPMS_ERD_v3.drawio` and v02 all match it exactly:
- same 59 table names;
- all 532 columns with the same types;
- the "`?` = nullable" markers agree with `NULL`/`NOT NULL`;
- every "→ table" FK target agrees with the SQL.

Nothing is in the ERD but missing from the SQL, or the other way round.
- **v2** is the file the notes name (line 5) and the newest one.
  - It leaves out 56 lines, following the rule stated on each page: 42 attribution FKs to `user_account` (created_by and similar, including the self-reference) and 14 FKs whose target is on another page. Examples are `*.site_id → site`, `*.device_id → device`, `*.import_batch_id → import_batch` and `product.species_id → species`.
  - Tables detailed elsewhere appear as dashed stubs.
- **v3** is the only file that draws all 143 FKs, one line per constraint, with none extra.

**v1 draft (15 tables):** `dbpersons`, `participant`, `pet`, `participant_status_log`, `alert`, `distribution_visit`, `distribution_item`, `dbinventoryevent`, `dbpalletevent`, `dbshoppingevent`, `dbitemcounts`, `dbpalletcounts`, `dbshoppingcounts`, `dbconsumption`, `dbitemcategory`.

Mapping to v2, per the notes' "What changed" table and the ERD's "(was …)" labels:
- `dbpersons` → `user_account`
- `alert` → `participant_alert`
- `distribution_visit` + `distribution_item` → `distribution` + `distribution_line` + `distribution_pet`
- `dbshoppingevent` → `distribution_event`
- `dbpalletevent`/`dbpalletcounts` → `stock_receipt`/`stock_receipt_line`
- `dbinventoryevent`/`dbitemcounts` → `inventory_count`/`inventory_count_line`
- `dbitemcategory` → `item_category`
- `dbshoppingcounts` and `dbconsumption` → `inventory_transaction` + `site_stock`
- `participant_status_log` is dropped (replaced by `audit_log` + `audit_field_change`)
- `audit_log` is also labelled "replaces dbLog"; dbLog doesn't appear in the v1 draft.

## Use case diagram (`PFPMS_Use_Case_Diagram.drawio`, 1 page)
- **Actors:** Volunteer and Administrator only. Administrator inherits every Volunteer use case (generalization).
- **Coordinator and Board do not appear** (grep finds 0 matches). The spec docx has no "Coordinator" either; "Board" appears only as an off-stage "Funder or Board Member". The notes (lines 33–34 and 119) say both roles come from the backlog (US-02, US-09, US-33) and are still an open question for the client.

**Use cases linked to an actor (16 lines):**
- Volunteer (10): UC-01 Login, UC-17 View Dashboard, UC-02 Search for Participants, UC-03 Register New Participant, UC-04 Update Participant Information, UC-05 Register/Update Pet Information, UC-06 Record Food Distribution, UC-07 View Participant Distribution History, UC-18 View Participant Alerts, UC-08 Refer Pet to Spay/Neuter Services.
- Administrator (6): UC-09 Delete Participant, UC-10 Delete Pet, UC-11 Manage User Accounts, UC-12 Import Legacy Records, UC-19 Remove Participant Alert, UC-13 Generate Reports.

**Numbered use cases:** the diagram has 19 (UC-01 to UC-19). The spec docx defines only UC-01 to UC-16. UC-17, UC-18 and UC-19 exist only in the diagram and are not mentioned in the SQL or notes. `participant_alert` does support UC-18 and UC-19.
- UC-14 Distribution Reports, UC-15 Participant Reports and UC-16 Pet/SNV Status Reports are not linked to an actor; UC-13 includes them.
- A note on UC-10 says it is Administrator-only and that the Volunteer wording conflicts; it asks to confirm with the client.

**Included use cases:** 21 include lines pointing to 16 distinct use cases.
- 12 unnumbered: Manage User Profile, Detect Duplicate Participant, Validate Participant Information, Validate Pet Information, Select Food Type and Quantity, Capture Distribution Date and Location, Select Participant, View Distribution Details, Update Participant Status, Calculate Participant Status, Flag/Unflag Participant, Create Alert.
- Plus UC-17 (included by UC-01) and UC-14, UC-15, UC-16 (included by UC-13).

**Extending use cases:** 7 extend lines from 6 use cases: Generate Duplicate Alert, Distribution Restriction Alert, Print Report, Export Report to PDF, Export Report to CSV, Export Report to Excel.

In total there are 37 use-case ellipses (19 numbered, 18 unnumbered).

**Checking "16 UCs + 9 included":**
- **"16 UCs" holds only as the count of use cases linked to an actor (and the spec's 16).** It is not the same set as UC-01 to UC-16: UC-17, 18 and 19 are linked to actors, while UC-14, 15 and 16 are included by UC-13.
- **"9 included" does not match** anything in the diagram. The counts are 16 distinct included use cases, 12 of them unnumbered, plus 6 extending ones.
