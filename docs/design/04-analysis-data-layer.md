<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

# Legacy data layer → PFPMS v2 schema mapping

**Summary:** the legacy database holds no participant, pet or pet-food data. The only things worth migrating are about 4 named staff accounts. The rest of the legacy inventory data is human food. 52 of the 59 PFPMS tables have no legacy predecessor. Every finding below comes from read-only analysis; no files were changed.

## 1. database/*.php and domain/*.php

**Connection pattern (`database/dbinfo.php`)**
- `connect()` returns a new mysqli connection on every call. Every data function opens its own connection and calls `mysqli_close` before each return.
- There are **no transactions anywhere** (0 uses of `begin_transaction` or `autocommit`). Because each function has its own connection, calls cannot be combined into one transaction. PFPMS needs a single transaction for `distribution` + `distribution_line` + `inventory_transaction` + `site_stock`, so the layer needs a shared connection (PDO or a singleton).
- **Security:** `dbinfo.php` contains hard-coded production DB credentials for the SiteGround host, and `emailEncryption.php` contains a hard-coded `ENCRYPTION_KEY`. Both should be rotated and moved out of the repo.
- **Bug:** the failure path calls `mysqli_error($con)` when `$con` is false, which is a TypeError on PHP 8.
- **Leaks:** some functions never close their connection: `retrieve_ItemCategoryStatus`, `retrieve_ItemID`, all of `dbAttendance.php`, and the error path in `add_person`, which calls `die()` and prints the MySQL error.

**Domain-object pattern**
- `domain/*.php` are plain classes: private fields, a positional constructor, getters. Getter naming is mixed (`getId()` vs `get_personId()`).
- Only some files have a `make_a_xxx($row)` mapper: `make_a_person`, `make_an_inventoryEvent`, `make_an_palletEvent`, `make_a_palletCount`, `make_an_shoppingEvent`, `make_an_event`, `make_an_app`, `make_an_training`. The others repeat `new X($row[..])` inline.
- `domain/ShoppingEvent` drops the `notes` column.

**Query style.** Counts include only live code; commented-out code is excluded.

| File | Table(s) | Main functions | Style | PFPMS fate |
|---|---|---|---|---|
| dbPersons.php | dbpersons | add_person, create_person, retrieve_person(username), retrieve_person_by_personId, retrieve_person_by_email, change_password, reset_password, getall_persons/type/status, update_type(_by_personId), update_status(_by_personId), update_person_by_personId, activate/deactivate/delete_person, make_a_person | Concatenation (0 live prepared). Only create_person and retrieve_person_by_email use real_escape. 59 of 78 functions are commented out | Rewrite as dbUserAccount |
| dbItemCategory.php | dbitemcategory | add/update/activate/deactivate/delete_itemCategory, retrieve_ItemCategory(_by_name), retrieve_ItemID, retrieve_ItemCategoryStatus, get_all(_active)_ItemCategory | Concatenation. Its 8 prepared statements are all in commented-out group helpers | Rewrite as item_category + product |
| dbInventoryEvent.php | dbinventoryevent | add/remove/retrieve_inventoryEvent, update_inventoryEvent_date, make_an_inventoryEvent, get_all_inventoryEvents(_by_date), get_matching_inventoryEvent, get_previous_inventoryEvent_pair | Concatenation | Rewrite as inventory_count |
| dbItemCounts.php | dbitemcounts (+ joins) | add/delete_itemCount, get_itemCount_by_id, get_itemCounts_by_inventoryEvent/_by_itemCategory, update_quantity, get_most_recent_counts_up_to_event, get_current/previous_counts_by_event, get_monthly_inventory_totals | Concatenation | Rewrite as inventory_count_line |
| dbPalletEvent.php | dbpalletevent | add/remove/retrieve_palletEvent, update_palletEvent_date/name/notes, make_an_palletEvent, get_all_palletEvents, pallet_name_unique | Concatenation | Rewrite as stock_receipt |
| dbPalletCounts.php | dbpalletcounts | add/delete_palletCount(_by_palletEvent), get_palletCount(s)_by_*, update_pallet_quantity/expiration, make_a_palletCount, get_pallet_names_with_category | Concatenation; 1 prepared (get_pallet_names_with_category) | Rewrite as stock_receipt_line |
| dbShoppingEvent.php | dbshoppingevent | add/remove/retrieve_shoppingEvent, update_shoppingEvent_date, make_an_shoppingEvent, get_all_shoppingEvents(_by_date) | Concatenation | Drop (see §3) |
| dbShoppingCount.php | dbshoppingcounts, dbshoppingcountgroup | add/delete/get/update_shoppingCount_*, get_most_recent_shoppingCounts_up_to_event, group helpers, get_shoppingList_families_with_category | Concatenation; 1 prepared | Drop |
| dbShoppingCountGroup.php | dbshoppingcountgroup | add/delete/get/rename group | Concatenation | Drop |
| dbConsumption.php | dbcomsumption (+ dbclient, dbdistribution) | add/delete/get/update consumption, compute_current_consumption_rates_by_category/_by_shoppingEvent | Concatenation. Bug: `$rates` is never initialised | Drop (reports come from the ledger) |
| dbClient.php | dbclient | add/delete_client, get_client_by_id, get_clients_by_shoppingEvent/_by_person, get_newest_client_by_shoppingEvent, update_client_numClients | Concatenation. `get_newest_*` uses an **unquoted** id, so it is injectable | Drop |
| dbDistribution.php | dbdistribution | add/delete/get_distribution_by_id, get_distributions_by_person/_by_date, update_distribution_days | Concatenation | Drop |
| dbLog.php | dbLog (not in the dump) | create_dbLog, add_log_entry, get_full_log, get_last_log_entries | Concatenation. **Broken:** it inserts a `venue` column that create_dbLog never creates, and uses `$_SESSION['venue']`. Nothing includes this file | Drop; replace with audit_log |
| dbEditLog.php | dbeditlog/dbeditlogs (not in the dump) | newLogEntryfromObject, newLogEntry | **Won't parse:** missing `;` after `$query = "INSERT INTO dbeditlog "`. The require path is missing a `/`. Nothing includes this file | Drop |
| dbGroups.php, dbShifts.php | dbgroups, user_groups, dbshifts | group membership; shift clock-in/out | **Prepared statements** (8 and 13). The only clean files, useful as a style reference | Drop (feature) |
| dbEvents.php, dbtraining.php, dbAppointments.php, dbApplications.php, dbAttendance.php, dbEventMedia.php, dbMessages.php, dbDiscussions.php, dbDiscussionReplies.php, dbSuggestions.php | Homebase volunteer tables. Several are missing from the dump: dbanimals, dblocations, dbservices, dbtrainings, dbappointments | events, sign-ups, training, messages, forums | Concatenation, some real_escape | Drop |
| database/InventoryEvent.php | dbevents etc. | a stray copy of dbEvents.php that redefines add_inventoryEvent and make_an_inventoryEvent | Nothing includes it; it would cause redeclare errors if it were included | Delete |
| dbTrainingPersons.php | — | empty (3 lines) | — | Delete |

**Checking the claims in `docs/PFPMS_useful_functions.md`**

| Claim | Verdict |
|---|---|
| dbPersons, dbItemCategory, InventoryEvent, ItemCounts, Pallet*, Shopping*, Consumption function lists | All exist and are live, with these exceptions: `archive_volunteer`, `get_tot_vol_hours`, `remove_all_users_in_group` and `get_groups_from_user` are commented out (the doc says to skip them anyway). `delete_consumption*` matches 4 functions |
| Table name "dbconsumption" | Wrong: the table is `dbcomsumption` (typo in the legacy schema) |
| "Adapt: drop `$personId`" in consumption | Out of date: v2 has no consumption table at all |
| Templates (`add_visit`, `get_visits_by_*`, `update_participant_status`, `make_a_participant`, `add_alert`, `get_active_alerts_by_participant`, `resolve_alert`) | Proposed names only; none exist. They also target **v1** tables (distribution_visit, distribution_item, participant_status_log, alert). In v2 these are distribution/distribution_line, audit_log and participant_alert |
| "Shared helpers (keep as-is)" | **Inaccurate:** see §2. `sanitize` is unsafe, dbLog/dbEditLog are broken, `connect()` holds secrets |
| `export_data` "CSV/Excel via PhpSpreadsheet" | False. It writes with `fputcsv` to a fixed `dataexport.csv` in the webroot and calls an undefined function `get_all_peoples_histories()`. PhpSpreadsheet is used only in `processInventoryReport.php`. `export_report` also writes to a fixed `export.csv` |
| `calculate_age` for participant age | Unsuitable: it parses a 2-digit-year `yy-mm-dd` string and compares the day against a year field. Replace it with `DateTime::diff` |

## 2. include/*.php helpers

| Function | Safe to reuse? | Note |
|---|---|---|
| `_sanitize`, `sanitize`, `sql_safe_input`, `sql_safe_associative_array` | **No** | They apply `real_escape` + `htmlspecialchars` on input. That stores HTML entities in the data (the dump has `&#039;`) and double-escapes when used with prepared statements. Array values are only trimmed, not escaped, which leaves an injection path. Each call opens its own DB connection. Replace with trim + prepared statements + `hsc()` when outputting |
| `trainingLevelMet` | No | Empty stub |
| `validateDate` | Yes | |
| `validate24hTime` / `validate24hTimeRange` | Fix first | The regex has no anchors, and the range function checks `$start` twice (never `$end`) |
| `validate12hTimeAndConvertTo24h` / `…Range…` | Yes | |
| `validateAndFilterPhoneNumber` | Yes (US 10-digit only) | |
| `validateEmail` | Yes | |
| `wereRequiredFieldsSubmitted` | Yes | `$blankOkay` defaults to true, so empty strings pass |
| `validateZipcode` | Adapt | Rejects ZIP+4; `postal_code` is VARCHAR(10) |
| `valueConstrainedTo` | Adapt | Use strict `in_array(...,true)` |
| `validateURL` | Adapt | Accepts `javascript:` URLs; restrict to http(s) before using it for `clinic.directions_url` |
| `isSecurePassword` | Yes | |
| `convertYouTubeURLToEmbedLink` | No | Feature dropped; not every path returns a value |
| output.php `hsc` | Yes | The right XSS guard |
| `time24hTo12h`, `floatPrecision` | Yes | |
| `formatPhoneNumber` | Yes | Assumes 10 digits |
| `unpackMessageTimestamp`, `prepareMessageBody` | No | Only for dbmessages; `prepareMessageBody` injects raw HTML |
| api.php `redirect` | Yes | Never pass it a user-supplied URL (open redirect) |
| time.php `calculateHourDuration` | No | Volunteer hours only |

## 3. Legacy tables → PFPMS successors

The dump is `sql/foodpantrydb.sql` (phpMyAdmin, MySQL 8.4). It has **no foreign keys**, and `dbpersons` and `dbarchived_volunteers` default to latin1.

### Row counts and fate

| Legacy table | Rows | Successor | Action |
|---|---|---|---|
| dbpersons | 13 | user_account | **Migrate selectively** |
| dbitemcategory | 39 | item_category (+ product) | Structure only; the data is human food |
| dbinventoryevent | 72 | inventory_count | Optional; human food |
| dbitemcounts | 1867 (777 are qty 0) | inventory_count_line | Optional; human food |
| dbpalletevent | 10 | stock_receipt | Optional; human food |
| dbpalletcounts | 23 (4 point to missing pallets) | stock_receipt_line | Optional; human food |
| dbshoppingevent | 6 | *none* | Drop (see below) |
| dbshoppingcounts | 158 | *none* (the idea maps to allotment_rule) | Drop |
| dbshoppingcountgroup | 27 | — | Drop |
| dbclient | 44 | *none* | Drop |
| dbdistribution | 14 | *none* | Drop |
| dbcomsumption | 1249 | *none*; replaced by inventory_transaction + site_stock | Drop |
| dbmessages | 304 | — | Drop |
| dbscheduledemails | 51 | — (the idea maps to saved_report scheduling) | Drop |
| dbevents | 38 | — (these are volunteer events, not distribution events) | Drop |
| dbeventpersons | 29 | — | Drop |
| dbshifts | 21 | — | Drop |
| dbpendingsignups | 13 | — | Drop |
| monthly_hours_snapshot | 10 | — | Drop |
| dbsuggestions | 10 | — | Drop |
| dbapplications | 7 | — | Drop |
| user_verified_ids | 6 | — | Drop |
| discussion_replies | 5 | — | Drop |
| dbpersonhours | 4 | — | Drop |
| user_groups | 3 | — | Drop |
| dbgroups | 2 | — | Drop |
| dbarchived_volunteers | 1 | — | Drop (contains personal data; purge) |
| dbdiscussions | 1 | — | Drop |
| dbapplication_comments, dbattendance, dbdrafts, dbeventmedia | 0 | — | Drop |
| Referenced in code but not in the dump: dbLog, dbeditlog(s), dbanimals, dblocations, dbservices, dbeventsservices, dbtrainings, dbtrainingpersons, dbtrainingmedia, dbappointments, dbeventvolunteers | — | audit_log is the conceptual successor of dbLog/dbeditlog | Drop |

**What `dbshoppingevent` actually holds.** Its rows are **shopping-list templates for family-size groups** ("1-3 Curbside", "4+ In-House", "8+ Add-On"). `dbshoppingcounts` holds the items per cart for each template. `dbclient` holds aggregate client counts per template (float, not individuals). `dbdistribution` holds the number of distribution days in a period. `dbcomsumption` is a cached items-per-day rate calculated from those.

The notes doc says `distribution_event` is the legacy `dbshoppingevent`. **That is wrong in meaning:** there is no site, no time and no session in the legacy table. The nearest idea is `allotment_rule`, and there is no data to carry across.

**No pet-food data exists.** All 39 categories are human food or household goods (Beans, Cereal, Tuna, Masa, Toilet Paper…). There is no species or weight data. The only "pet" row in the whole dump is a Homebase dbevents entry called "Pet Adoption". There are **no participants, households or pets** in the legacy data: dbclient is aggregate counts only.

**The `personId` problem.** `dbpersons.personId` is already an INT. The problem is the child `personId VARCHAR(11)` columns, which hold mixed values:
- dbinventoryevent and dbpalletevent hold the numeric personId (`'2'`, `'6'`, `'13'`).
- dbclient, dbcomsumption and dbdistribution hold the **username** (`vmsroot`, `ccda_admin`, `admin`), because they read `$_SESSION['_id']`.
- viewShoppingList.php does `(int)$_SESSION['_id']`, which turns a username into `0`. dbshoppingevent row 1006 has `personId='0'`.

To transform: resolve digits via `dbpersons.personId` and anything else via `dbpersons.id` (username). Map unresolvable or `0` values to a migration system user.

### Column mapping for the surviving tables

**dbpersons → user_account**

| Legacy | PFPMS | Transform |
|---|---|---|
| personId INT | user_id | Keep the value (a remap table is still needed for the VARCHAR child references) |
| id (username) | username VARCHAR(50) | Max length is 25, so it fits. login.php lowercases input; the collation is case-insensitive |
| first_name / last_name TEXT | VARCHAR(50) NOT NULL | 3 empty last names; `''` is allowed |
| email TEXT | email NOT NULL **UNIQUE** | 4 are empty (vmsroot, vmsroot2, admin, inventory), which breaks UNIQUE. Exclude these system/shared accounts or give them placeholders |
| type (`admin`/`Admin`/`superadmin`/`Superadmin`/`inventory_counter`/`Inventory_counter`) | role ENUM | Lowercase first. Proposed mapping: superadmin→Administrator, admin→Coordinator (or Administrator), inventory_counter→Volunteer. Needs a client decision (notes §4.1) |
| status (Active 8 / Inactive 1 / Deleted 4) | status ENUM(Pending, Active, Inactive, Locked) | Deleted→Inactive with `deactivated_reason='Legacy deleted'`, or don't migrate those rows |
| password | password_hash | **Compatible as-is:** all 13 are `$2y$` bcrypt (60 chars) and verify with `password_verify` |
| force_password_change | must_change_password | Copy the value, or force 1 |
| — | start_date NOT NULL | No source. Use the earliest activity date or the migration date |
| — | user_site_access | Insert one row for each migrated user × the default site |

**Worth migrating?** Only about 4 named, active staff accounts. Don't migrate vmsroot/vmsroot2 (insertAdmin.php seeds vmsroot with a default password) or the shared admin/inventory logins, which break per-person audit. Also confirm that CCDA food-pantry staff are actually pet-pantry staff.

**dbitemcategory → item_category (+ product)**

| Legacy | PFPMS | Transform |
|---|---|---|
| id | category_id | |
| name | name (UNIQUE) | `html_entity_decode` |
| bananaBox | is_banana_box | |
| itemsPerBox | units_per_case | |
| status Active/Inactive/Deleted | ENUM(Active, Inactive) | Deleted→Inactive |
| shopOnly | — | Drop |
| — | product.species_id NOT NULL, food_form ENUM(Dry, Wet, Treat, Other), unit_weight_lbs | **No source.** Human food cannot be given a species |

Recommendation: do not migrate these rows. Seed pet-food categories and products instead (for example Dog/Cat × Dry/Wet).

**dbpalletevent → stock_receipt**

| Legacy | PFPMS | Transform |
|---|---|---|
| id | receipt_id | |
| name (UNIQUE) | name (UNIQUE) | |
| personId | received_by INT | Resolve via dbpersons |
| date | received_on NOT NULL | Row id 1 is `0000-00-00`; fix it |
| notes | notes | |
| — | site_id | Default site |

**dbpalletcounts → stock_receipt_line**

| Legacy | PFPMS | Transform |
|---|---|---|
| id | receipt_line_id | |
| palletEventId | receipt_id | Drop the 4 orphan rows (pallet ids 3, 4, 5) |
| itemCategoryId | product_id | Via a category→product map |
| quantity | quantity | |
| expiration (14 set) | expiration | |

Note on meaning: legacy pallet counts are *current* balances that get edited (the notes say things like "pulled 8 boxes"). PFPMS receipts are immutable and backed by ledger rows, so an opening-balance `inventory_transaction` would be needed.

**dbinventoryevent → inventory_count**

| Legacy | PFPMS | Transform |
|---|---|---|
| id | count_id | |
| personId | counted_by | Resolve via dbpersons |
| location (Warehouse/Pantry/Pallet, 24 each) | location | The legacy app writes 3 events per count |
| date | count_date | |
| — | site_id | Default site |

**dbitemcounts → inventory_count_line**

| Legacy | PFPMS | Transform |
|---|---|---|
| id | count_line_id | |
| inventoryEventId | count_id | No orphans |
| itemCategoryId | product_id | Via the category→product map |
| quantity | quantity | 777 rows are 0; they can be skipped |

**Data-wide transforms**
- Decode HTML entities left by `sanitize()`.
- Convert to utf8mb4_0900_ai_ci.
- Add the FKs the legacy DB never enforced.
- Set `record_source='Legacy'` and `import_batch_id` where those columns exist. They exist only on participant, pet and distribution, which receive no legacy rows.

**Verdict:** migrate user_account only, and selectively. The inventory history is CCDA human-food data, and the PFPMS product model can't represent it. Archive the dump rather than migrating it; use it at most as test fixtures. Real participant or pet history has to come through the UC-12 import tables from outside spreadsheets, not from this DB.

## 4. PFPMS tables with no legacy predecessor (52 of 59)

These have a predecessor: user_account, item_category, product (partial), stock_receipt, stock_receipt_line, inventory_count, inventory_count_line.

| Area | Greenfield tables |
|---|---|
| Security & Access (7) | site, user_site_access, device, user_session, auth_token, policy_document, policy_acknowledgement |
| Participants (12) | language, participant, participant_site, participant_consent, participant_proxy, service_note, participant_alert, registration_draft, intake_question, intake_answer, service_area_postal_code, referred_out_applicant |
| Pets (6) | species, breed, size_band, allotment_rule, pet, pet_household_history |
| Distribution & Inventory (8) | distribution_event (named after dbshoppingevent but different in meaning), event_check_in, distribution, distribution_pet, distribution_line, product_barcode, site_stock, inventory_transaction |
| Spay/Neuter (6) | clinic, clinic_species_rule, voucher_budget, snv_referral, snv_referral_status_log, snv_followup |
| Governance, Import & Reporting (13) | system_setting, audit_log (the idea replaces the broken dbLog/dbeditlog), audit_field_change, erasure_request, import_mapping, import_batch, import_rejected_row, saved_report, report_recipient, report_run, report_narrative, metric_threshold, grant_commitment |

The v2 seed rows cover only `language` (en, es), `species` (Dog, Cat) and 12 `system_setting` keys. `site`, `size_band`, `allotment_rule`, `item_category` and `product` get no seed data and will need it before first use.

## Relevant files
- C:/Users/maryw/Documents/Pelican/chsPetPantry/docs/PFPMS_schema_v2.sql
- C:/Users/maryw/Documents/Pelican/chsPetPantry/docs/PFPMS_schema_v2_notes.md
- C:/Users/maryw/Documents/Pelican/chsPetPantry/docs/PFPMS_useful_functions.md
- C:/Users/maryw/Documents/Pelican/chsPetPantry/sql/foodpantrydb.sql
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbinfo.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbPersons.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbItemCategory.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbInventoryEvent.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbItemCounts.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbPalletEvent.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbPalletCounts.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbShoppingEvent.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbShoppingCount.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbConsumption.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbClient.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbDistribution.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbLog.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/dbEditLog.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/database/InventoryEvent.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/include/input-validation.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/include/output.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/include/api.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/include/time.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/login.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/viewConsumptionRates.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/viewShoppingList.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/viewUpdateInventory.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/reportsExport.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/reportsCompute.php
- C:/Users/maryw/Documents/Pelican/chsPetPantry/emailEncryption.php
