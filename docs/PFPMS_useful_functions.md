# PFPMS – Reusable Functions from chsPetPantry

> **Corrections (Sept 2026, after code review; see `docs/design/04-analysis-data-layer.md`).** The legacy files now live in `legacy/` (reference only). Port logic from them into `src/`; never include them.
> - **Do not reuse `sanitize`, `_sanitize`, `sql_safe_input` or `sql_safe_associative_array`.** They store HTML entities in the data and do not protect against SQL injection. Use prepared statements, and escape on output with `e()`.
> - **Do not reuse `emailEncryption.php`.** It is AES-CBC with no MAC and its key was committed to the repo. It was deleted in Phase 0. Use `Security/Crypto` (AES-256-GCM).
> - `database/dbinfo.php` was deleted (it held production credentials); `src/Db.php` replaces it. `dbLog.php` and `dbEditLog.php` are broken, and `audit_log` replaces them.
> - `export_data` does not use PhpSpreadsheet: it writes a fixed CSV into the web root and calls an undefined function. `calculate_age` parses 2-digit years. Replace both.
> - Several validators need fixes before porting. The list is in `docs/PFPMS_Implementation_Plan.md` §7.
> - The consumption table is spelled `dbcomsumption` in the legacy DB. The "Templates" rows below target the v1 draft tables (`distribution_visit`, `alert`, `participant_status_log`), which v2 replaced.

Functions in the existing chsPetPantry code that are worth keeping or adapting for PFPMS, grouped by the ERD entity they serve. "Adapt" means the pattern carries over but the table or columns change.

## Users / Authentication (`dbpersons`) — `database/dbPersons.php`
| Function | Use in PFPMS |
|---|---|
| `add_person`, `create_person` | Create Admin / Volunteer accounts |
| `retrieve_person`, `retrieve_person_by_personId`, `retrieve_person_by_email` | Login lookup, profile views |
| `change_password`, `reset_password` | Change / forgotten password |
| `getall_persons`, `getall_type`, `getall_status` | Manage users list, filter by role |
| `update_type` / `update_type_by_personId` | Modify user role (Admin ↔ Volunteer) |
| `update_status` / `update_status_by_personId`, `activate_person`, `deactivate_person` | Activate / deactivate accounts |
| `update_person_by_personId`, `delete_person` | Edit / delete user |
| `make_a_person` | Row → object mapper (pattern for all new domain classes) |
| `archive_volunteer`, `get_tot_vol_hours` | Skip (volunteer-hours features dropped) |

## Pet Food Categories (`dbitemcategory`) — `database/dbItemCategory.php`
| Function | Use in PFPMS |
|---|---|
| `add_itemCategory`, `update_itemCategory` | Add / edit food category (add `petType` param) |
| `activate_itemCategory`, `deactivate_itemCategory`, `delete_itemCategory` | Category lifecycle |
| `retrieve_ItemCategory`, `retrieve_ItemCategory_by_name`, `retrieve_ItemID`, `retrieve_ItemCategoryStatus` | Lookups |
| `get_all_ItemCategory`, `get_all_active_ItemCategory` | Dropdowns on count / distribution forms |
| `remove_all_users_in_group`, `get_groups_from_user` | Skip (misplaced group helpers) |

## Inventory Counts (`dbinventoryevent`, `dbitemcounts`)
`database/dbInventoryEvent.php`: `add_inventoryEvent`, `remove_inventoryEvent`, `retrieve_inventoryEvent`, `update_inventoryEvent_date`, `make_an_inventoryEvent`, `get_all_inventoryEvents`, `get_all_inventoryEvents_by_date`, `get_matching_inventoryEvent`, `get_previous_inventoryEvent_pair`

`database/dbItemCounts.php`: `add_itemCount`, `delete_itemCount`, `get_itemCount_by_id`, `get_itemCounts_by_inventoryEvent`, `get_itemCounts_by_itemCategory`, `update_quantity`, `get_most_recent_counts_up_to_event`, `get_current_counts_by_event`, `get_previous_counts_by_event`, `get_monthly_inventory_totals` (dashboard / inventory report)

## Pallets Received (`dbpalletevent`, `dbpalletcounts`)
`database/dbPalletEvent.php`: `add_palletEvent`, `remove_palletEvent`, `retrieve_palletEvent`, `update_palletEvent_date`, `update_palletEvent_name`, `update_palletEvent_notes`, `make_an_palletEvent`, `get_all_palletEvents`, `pallet_name_unique`

`database/dbPalletCounts.php`: `add_palletCount`, `delete_palletCount`, `delete_palletCount_by_palletEvent`, `get_palletCount_by_id`, `get_palletCounts_by_palletEvent`, `get_palletCounts_by_itemCategory`, `get_palletCount_by_palletEvent_and_itemCategory`, `update_pallet_quantity`, `update_pallet_expiration` (expiration tracking), `make_a_palletCount`, `get_pallet_names_with_category`

## Distribution Events (`dbshoppingevent`, `dbshoppingcounts`, `dbconsumption`)
`database/dbShoppingEvent.php`: `add_shoppingEvent`, `remove_shoppingEvent`, `retrieve_shoppingEvent`, `update_shoppingEvent_date`, `make_an_shoppingEvent`, `get_all_shoppingEvents`, `get_all_shoppingEvents_by_date`

`database/dbShoppingCount.php`: `add_shoppingCount`, `delete_shoppingCount`, `get_shoppingCount_by_id`, `get_shoppingCounts_by_shoppingEvent`, `get_shoppingCounts_by_itemCategory`, `update_shoppingCount_quantity`, `update_shoppingCount_notes`, `update_shoppingCount_exclude`, `get_most_recent_shoppingCounts_up_to_event`, `get_shoppingList_families_with_category`. Skip: `create_shoppingCount_group`, `assign_to_group`, `remove_from_group` (dbshoppingcountgroup dropped).

`database/dbConsumption.php`: `add_consumption`, `update_consumption_itemsConsumed`, `delete_consumption*`, `get_consumption_by_id`, `get_consumptions_by_shoppingEvent`, `get_consumptions_by_itemCategory`, `compute_current_consumption_rates_by_category`, `compute_current_consumption_rates_by_shoppingEvent` (dashboard / reports). Adapt: drop `$personId` param (column removed); drop `get_consumptions_by_person`.

## Templates for the new PFPMS entities
| Existing code | Adapt into |
|---|---|
| `dbClient.php` (`add_client`, `get_clients_by_shoppingEvent`, `get_clients_by_person`, …) | `dbDistributionVisit.php` – `add_visit`, `get_visits_by_shoppingEvent`, `get_visits_by_participant` |
| `dbDistribution.php` (`get_distributions_by_person`, `get_distributions_by_date`) | Visit history / last-visit lookups used for Distribution Restriction Alert |
| `dbItemCounts.php` (child-row CRUD) | `dbDistributionItem.php`, `dbPet.php` |
| `dbPersons.php` (`update_status_by_personId`, `make_a_person`) | `dbParticipant.php` – `update_participant_status` (+ write `participant_status_log`), `make_a_participant` |
| `dbPersons.php` (`retrieve_person_by_email`) | Duplicate-participant detection (match on name + DOB / phone / email) |
| `dbLog.php` (`add_log_entry`, `get_last_log_entries`) | Pattern for `dbAlert.php` – `add_alert`, `get_active_alerts_by_participant`, `resolve_alert` |

## Shared helpers (keep as-is)
- **DB connection** – `database/dbinfo.php`: `connect()`
- **Input validation** – `include/input-validation.php`: `sanitize`, `_sanitize`, `sql_safe_input`, `sql_safe_associative_array`, `wereRequiredFieldsSubmitted`, `validateDate`, `validateEmail`, `validateAndFilterPhoneNumber`, `validateZipcode`, `valueConstrainedTo` (enum checks), `isSecurePassword`
- **Output formatting** – `include/output.php`: `hsc` (XSS-safe echo), `formatPhoneNumber`, `floatPrecision`, `time24hTo12h`
- **Navigation** – `include/api.php`: `redirect`
- **Reports / export** – `reportsCompute.php`: `export_report`, `pretty_date`, `calculate_age` (participant age); `reportsExport.php`: `export_data` (CSV/Excel via PhpSpreadsheet in `vendor/`)
- **Audit log** – `database/dbLog.php`: `add_log_entry`, `get_full_log`, `get_last_log_entries`; `database/dbEditLog.php`: `newLogEntry` (note: the log table isn't in the current SQL dump — `create_dbLog()` builds it)
- **Email encryption** – `emailEncryption.php`: `encryptEmail`, `decryptEmail` (reuse if participant emails must be stored encrypted)
