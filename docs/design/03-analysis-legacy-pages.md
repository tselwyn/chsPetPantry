<!-- Generated 2026-09-28 during PFPMS planning (Claude Code analysis). Secrets redacted. Line references are to the legacy-baseline tag. -->

# PFPMS UI-layer inventory (legacy root `*.php`)

Repo root: `C:/Users/maryw/Documents/Pelican/chsPetPantry/`. There are **156 root `.php` files** (not ~190). Every one is classified below. Table names drop the `db` prefix (so `persons` = `dbpersons`).

## 1. How pages are wired today

**Includes.** Each page is a standalone script with no front controller or router.
- `session_cache_expire(30); session_start();` comes first.
- `require_once('database/dbinfo.php')` provides `connect()`, a mysqli connection to `foodpantrydb`.
- `database/dbX.php` holds the data-access functions and returns `domain/X.php` objects. Most of these build SQL by string concatenation. Only dbPersons, dbShifts, dbGroups and dbItemCategory use `prepare()` much.
- `include/input-validation.php` provides `sanitize`, `wereRequiredFieldsSubmitted`, `valueConstrainedTo` and the `validate*` helpers. `include/output.php` provides `hsc` and `formatPhoneNumber`.
- `universal.inc` goes inside `<head>`. It sets `display_errors` and the timezone, defines `VMS_NON_INCLUDE`, and loads jQuery 1.9.1, `css/base.css`, `css/header.js` and the favicon.
- `header.php` goes right after `<body>`. It outputs its own `<head>` styles and scripts, the `<header>` nav, and the permission check.
- Tailwind pages (`personSearch.php`, about 16 pages use `css/normal_tw.css`) set `$tailwind_mode = true` before `require_once('header.php')` so the header skips its CSS reset.

**Common skeleton.** This is the pattern in the CCDA-era pages (`viewAddItemCategory.php`, `viewModifyUser.php` and so on):
```php
<?php
session_cache_expire(30); session_start();
$loggedIn=false; $accessLevel=0; $userID=null;
if (isset($_SESSION['_id'])) { $loggedIn=true; $accessLevel=$_SESSION['access_level']; $userID=$_SESSION['_id']; }
if ($accessLevel < 2) { header('Location: index.php'); die(); }      // page-level guard
require_once('database/dbinfo.php'); require_once('database/dbItemCategory.php'); $con = connect();
$errors = [];
if (isset($_POST['add_category'])) { /* validate -> $errors[] */ if (empty($errors)) { add_...(); header('Location: viewItemCategories.php'); exit(); } }
?>
<!DOCTYPE html><html><head><?php require_once('universal.inc') ?><title>X | CCDA</title><style>/* pageheader, .report-container, .report-section, .report-table, .updateInv-*, .modify-* */</style></head>
<pageheader><div class="title">…</div></pageheader>
<body><?php require_once('header.php') ?><main><div class="report-container"> errors <ul>…</ul> <form method="POST">…</form></div></main></body></html>
```
- The shared classes (`.report-table`, `.updateInv-row`, `.modify-save-btn` and others) are copied into the inline `<style>` of 18 pages. None of them is in a CSS file. They should be moved into one stylesheet before new pages copy them.
- `template.php` is the older Homebase stub (universal.inc, then header, then `<main>`). It has no guard.

## 2. header.php: navigation and role gating

**Session variables.** `login.php` sets `logged_in`, `access_level`, `type`, `f_name`, `l_name`, `_id` (the username) and `_personId`.
- `access_level` comes from `Person::get_access_level()` in `domain/Person.php:73`: `type` `superadmin` = 3, `admin` = 2, anything else (for example `inventory_counter`) = 1.
- The username `vmsroot` is hard-coded to level 3.
- `$_SESSION['type']` is set but never used for gating. Gating is always by the number in `access_level`.

**Page gate.** Lines 698–827 build `$permission_array['lowercase basename'] = minimum level (0/1/2)`. If `$permission_array[$page] > $_SESSION['access_level']`, it runs a JavaScript redirect to `index.php` and then `die()`. The gaps:
- **Pages not in the array are open to every logged-in user.**
- The check only runs when `$_SESSION['logged_in']` is set. For anonymous visitors the header does nothing and does not redirect.
- Pages handle their POST before including the header, so the gate never protects writes.
- The keys `deleteGroup.php`, `clockOut.php` and `pendingApp.php` are camelCase, so they never match.
- Level 3 is only checked inside individual pages: `createUser`, `viewAuditUsers`, `viewItemCategories` and `modifyUserRole`.

**Menus.**
- **Level 1 and above:** a "Food Pantry Navigation" dropdown.
  - Level 2 and above also get Audit Users, Add User, Manage Item Categories and Consumption Rates.
  - All users get Update Inventory, View Inventory, Weekly Inventory Report, Shopping List and Inventory Analytics.
  - A second dropdown with no label holds Create Group, View Groups and No Shows.
  - On the right, a user icon opens Change Password and Log Out.
- **Level 0 and below:** an Events dropdown (My Upcoming, Sign-Up, Edit Hours), My Groups, a calendar icon, a date box, and a profile menu. In practice nobody sees this, because login always gives level 1 or higher.

**JavaScript bug.** The accessibility script calls `btn.addEventListener`, but the `#accessibilityBtn` markup is commented out. So a TypeError is thrown on every page that includes the header.

## 3. index.php dashboard

- It checks `access_level < 1` itself. It sends the user to `changePassword.php` if `$_SESSION['change-password']` is set, otherwise to `login.php`. It then calls `retrieve_person()`.
- It has a second full copy of the header CSS. It includes the header *before* `<body>`, and there are two `<body>` tags.
- **"Admin Dashboard" (level 2 and above):** a row of `.content-box-test` tiles, each with an `onclick=location` handler: Audit Users, Add User, Manage Item Categories, Consumption Rates.
  - A "Delete Pet" tile is nested inside the Add User tile. It links to `deletePet.php`, which doesn't exist.
- **"Inventory Management Dashboard" (everyone):** Update Inventory, View Inventory, Weekly Inventory Report, Shopping List, Inventory Analytics. Then a footer.
- **Hidden dependency:** some PHP sits inside `<!-- -->` HTML comments, but PHP still runs it.
  - `all_pending_names()` queries `dbapplications` and `dbevents` on every admin page load.
  - `get_user_unread_count()` queries `dbmessages`.
  - **So index.php will break as soon as those dropped tables are removed.**

## 4. Classification of all 156 files

ADAPT means keep the page and rewire it to the new tables. REWRITE means a new page replaces it. DELETE means the feature is dropped under notes §4 item 6, or the file is dead.

### Shell (4)
| File | Legacy tables / includes | Disp. | PFPMS target |
|---|---|---|---|
| header.php | shifts include (commented out) | REWRITE | Menu per role (Volunteer, Coordinator, Administrator, Board), a site switcher, and one shared `require_role()` check. Keep the dropdown CSS and JS. |
| index.php | persons; messages and applications+events (inside comments, still runs) | REWRITE | Dashboards per role: volunteer search and check-in (UC-02, US-04); coordinator live event view (US-18); admin tiles; read-only board view (US-33) |
| template.php | — | ADAPT | Rebuild on the new skeleton (auth helper, site context) |
| infoBox.php | — (static) | DELETE | |

### Auth / login / password (8)
| File | Tables | Disp. | Target |
|---|---|---|---|
| login.php | persons | ADAPT | UC-01: `user_account` (status, `failed_login_count`, `locked_until`, `must_change_password`, `expiry_date`), `user_session`, `user_site_access`; US-03 `policy_acknowledgement`; remove the vmsroot hard-code |
| logout.php | — | ADAPT | UC-01: close `user_session`, write an `audit_log` row |
| changePassword.php | persons | ADAPT | UC-01 (`password_changed_at`) |
| forgotPassword.php | raw SQL via dbinfo, emailEncryption, PHPMailer | ADAPT | UC-01 §3.2: `auth_token` with purpose 'Password Reset' |
| changeForgottenPassword.php | persons | ADAPT | UC-01: use up the `auth_token` |
| resetPassword.php | persons | ADAPT | UC-11: 72-hour 'Temporary Credential' token |
| emailEncryption.php | — (key hard-coded in the file) | ADAPT | Keep only as a helper, with the key moved to an environment variable |
| toggleLock.php | session only, no auth, nothing links to it | REWRITE | US-01 PIN fast-switch or lock screen (`pin_hash`, `device`) |

### User / volunteer management (19)
| File | Tables | Disp. | Target |
|---|---|---|---|
| createUser.php | persons | ADAPT | UC-11 (role list, start/expiry dates, `user_site_access`). Its password field is currently `type="text"`. |
| viewAuditUsers.php | persons | ADAPT | UC-11 user list by status, plus US-02 "signed in now" flag. It has no page guard today. |
| viewModifyUser.php | persons | ADAPT | UC-11 edit, activate and deactivate; US-28 time-limited site access |
| personSearch.php | persons | ADAPT | UC-02 participant search (participant, pet, microchip; US-04/05/06) |
| viewProfile.php | persons, user_verified_ids UI | ADAPT | Own-account profile, with verified IDs and hours removed |
| editProfile.php, profileEditForm.php | persons | ADAPT | Self-edit of `user_account` (phone, `notification_prefs`) |
| registrationForm.php | — (included by VolunteerRegister) | REWRITE | UC-03 participant intake form (`registration_draft` US-08, `intake_question` US-09, consent) |
| modifyUserRole.php | persons, messages | DELETE | Merge into viewModifyUser |
| VolunteerRegister.php | persons, messages | DELETE | Staff accounts are created only by an admin (UC-11) |
| deleteUserSearch.php, deleteUser.php, deletePerson.php | persons | DELETE | Accounts are deactivated, never hard-deleted |
| volunteerManagement.php | — (page of links) | DELETE | |
| getVolunteers.php | persons (JSON output, **no auth check**) | DELETE | |
| milestonePoints.php | persons | DELETE | |
| viewVolunteerProfile.php, accountEditHistory.php | — (empty stubs) | DELETE | Build a new audit viewer on `audit_log` instead |
| viewArchived.php | animals (`dbAnimals.php` missing, page is broken) | DELETE | |

### Volunteer events / sign-ups / calendar / check-in / hours (56)
| File | Tables | Disp. | Target |
|---|---|---|---|
| calendar.php | events | ADAPT (optional) | Monthly `distribution_event` calendar for a site |
| addEvent.php | events | REWRITE | Create a `distribution_event` (site, date, start and end times) |
| editEvent.php | events, persons | REWRITE | Edit a `distribution_event`; status Scheduled → Open → Closed |
| event.php | events, messages, persons | REWRITE | US-18 live event dashboard |
| viewCheckInOut.php | persons, shifts | REWRITE | US-04 participant check-in, writing `event_check_in` |
| processCheckIn.php | shifts | REWRITE | AJAX endpoint for `event_check_in` outcomes (US-38) |
| checkedInVolunteers.php | persons, shifts | REWRITE | US-02 live list of signed-in volunteers (`user_session`, remote sign-out) |
| calendar-view.php, calendar-view_daily.php, calendar-view_weekly.php, cancelEvent.php, completeEvent.php, date.php, deleteEvent.php, eventSearch.php | events | DELETE | |
| approveSignup.php, rejectSignup.php | events, messages | DELETE | |
| event-list.php, eventList.php, logAttendees.php, setTimes.php, viewSignUpList.php, noShows.php | events, persons | DELETE | |
| adminViewingEvents.php, eventApproved.php, viewAllEvents.php, viewMyUpcomingEvents.php (also applications, eventpersons) | events, messages, persons | DELETE | |
| denyApplication.php, eventManagement.php, eventSignUp.php, fromPendingApproveSignup.php, fromPendingFlagSignup.php, fromPendingRejectSignup.php, process_application.php, viewAllApplications.php, viewApplication.php, viewEventSignUps.php, viewPendingApps.php | applications, plus events / persons / messages | DELETE | |
| eventsOpenForSignUpReview.php, viewEventsForSignUp.php | messages, persons | DELETE | |
| autoCheckOut.php (cron), clockOut.php (no auth), clockOutBulk.php | shifts | DELETE | |
| editTimes.php, deleteTimes.php, editHours.php | personhours, persons | DELETE | |
| volunteerReport.php | persons | DELETE | |
| applicationSuccess, eventFailure, eventFailureBadDepartureTime, eventSuccess, requestFailed, signupPending, signupSuccess, processAttendees, viewRetreatApplications (.php) | — (static result pages) | DELETE | |

### Training (2)
| File | Tables | Disp. |
|---|---|---|
| addTraining.php | trainings (`dbTrainings.php` missing, broken), messages | DELETE |
| eventTrainingManagement.php | events, messages, persons | DELETE |

US-29 training mode is meant to run against a separate sample database, so nothing here carries over.

### Discussions / suggestions / groups / messages / email (30)
| File | Tables | Disp. | Target |
|---|---|---|---|
| createDiscussion, deleteBulk, deleteDiscussion, deleteReply, discussionContent, discussionMain, viewDiscussions | discussions, discussion_replies, messages, persons | DELETE | |
| createSuggestion, viewSuggestions | suggestions | DELETE | |
| createGroup, deleteGroup, showGroups, manageMembers, groupManagement, volunteerViewGroup, volunteerViewGroupMembers | groups / user_groups, messages, persons | DELETE | |
| inbox, viewNotification, deleteNotification | messages, persons | DELETE | |
| createEmail, editDrafts, viewDrafts, emailDraft, sendDraft, email, generateEmailList, scheduleEventEmails, emailDraftView, emailSingleDraftView (the last two include `dbConnect.php`, which is missing) | drafts, scheduledemails, persons, events, groups | DELETE | Keep the `email/` PHPMailer wrapper |
| scheduledSend.php | scheduledemails, persons (cron + PHPMailer) | REWRITE | Cron job for UC-13 scheduled reports (`saved_report`, `report_recipient`, `report_run`) and US-35 threshold alerts |

### Inventory counts (5)
| File | Tables | Disp. | Target |
|---|---|---|---|
| viewUpdateInventory.php | inventoryevent, itemcounts, itemcategory, palletevent, persons | ADAPT | `inventory_count` and `inventory_count_line` per site and product. Posting writes `inventory_transaction` ('Count Adjustment') and updates `site_stock` (UC-14 §4.5). No page guard today. |
| inventory.php | inventoryevent, itemcounts, itemcategory | ADAPT | Stock on hand plus the ledger (`site_stock`, `inventory_transaction`) |
| editInventoryEvent.php | same | ADAPT | Edit a count that hasn't been posted |
| deleteInventoryEvent.php | same | ADAPT | Void a count with a 'Reversal' transaction and an `audit_log` row |
| viewEditDeleteInventory.php | inventoryevent, itemcounts | DELETE | Merge into inventory.php |

### Pallets (3)
| File | Tables | Disp. | Target |
|---|---|---|---|
| viewManagePallets.php | palletevent, palletcounts, itemcategory, persons | ADAPT | List of `stock_receipt` (goods received) |
| viewAddPallet.php | same | ADAPT | Header plus lines: `stock_receipt` and `stock_receipt_line` (with expiration), a 'Receipt' transaction and a `site_stock` increase |
| viewModifyPallet.php | same | ADAPT | Edit or void a `stock_receipt` |

### Item categories (3)
| File | Tables | Disp. | Target |
|---|---|---|---|
| viewItemCategories.php | itemcategory, inventoryevent, itemcounts, persons | ADAPT | `item_category` and `product` catalogue (species, `food_form`, `unit_weight_lbs`). The new status list is only Active/Inactive, so the legacy 'Deleted' state goes. |
| viewAddItemCategory.php | itemcategory, persons | ADAPT | Add a category or product, plus `product_barcode` (US-16) |
| viewModifyItemCategory.php | itemcategory, palletcounts, shoppingcount | ADAPT | Edit a product and change its status |

### Shopping list / consumption (2)
| File | Tables | Disp. | Target |
|---|---|---|---|
| viewShoppingList.php (93 KB) | shoppingcount(s), shoppingcountgroup, consumption/comsumption, shoppingevent, itemcategory | REWRITE | `allotment_rule` maintenance by species and size band (UC-05 §4.1), plus a pick list for the UC-06 distribution screen. Reuse its jsPDF code. |
| viewConsumptionRates.php | client, consumption, distribution, shoppingcount, shoppingevent, itemcategory | REWRITE | US-36 demand forecast, calculated from `distribution_line` instead of entered by hand |

### Reports (8)
| File | Tables | Disp. | Target |
|---|---|---|---|
| generateReport.php | inventoryevent, itemcounts, itemcategory (uses `bind_param`) | ADAPT | UC-14 distribution and inventory analytics; hub for UC-13 |
| processInventoryReport.php | inventoryevent, itemcounts, itemcategory, events, persons | ADAPT | Server-side CSV/XLSX export endpoint that also writes an `audit_log` Export row (UC-13, UC-15) |
| viewWeeklyReport.php | inventoryevent, itemcounts, shoppingcount, consumption | ADAPT | UC-14 weekly report per site (pounds distributed vs. stock) |
| report.php, reportsPage.php | animals (table doesn't exist, pages are broken), persons, events | DELETE | |
| reports.php, reportsCompute.php, reportsExport.php | persons, shifts, events | DELETE | First move `pretty_date`, `calculate_age` and `export_data` into `include/` |

### Images / encryption / uploads / documents (10)
| File | Tables | Disp. | Target |
|---|---|---|---|
| security_config.php | — (key from environment, `secure_uploads/`) | ADAPT | Encrypted storage for import files (UC-12 `stored_file_path`) and pet photos |
| serve_image.php | — | ADAPT | Serve `pet.photo_path` and `size_band.picture_path` behind login |
| upload_encrypted_image.php | — | REWRITE | UC-12 import file upload and UC-05 pet photo upload |
| view_encrypted_gallery.php, approve_encrypted_image.php, deny_encrypted_image.php | — (verified IDs, dropped) | DELETE | |
| resources.php, uploadResources.php, deleteResources.php, viewResources.php | — (PDF files on disk) | DELETE | `policy_document` is versioned text, so it needs a new admin page |

### Misc / one-off / debug (6)
| File | Tables | Disp. | Note |
|---|---|---|---|
| insertAdmin.php | persons | REWRITE | Anyone can open it in a browser, and it creates `vmsroot` with a hard-coded password. Replace it with an Administrator seed in SQL or a command-line script outside the web root. |
| create_dummy_dbpersonhours.php | personhours, persons | DELETE | |
| viewData.php | — | DELETE | |
| viewLocation.php, deleteLocation.php, deleteService.php | services, animals, messages (`dbServices.php` missing, broken) | DELETE | |

## 5. Counts

| Disposition | Count |
|---|---|
| **DELETE** | **110** |
| **ADAPT** | **31** |
| **REWRITE / REPLACE** | **15** |
| Total | 156 |

By area: Shell 4, Auth 8, Users 19, Events/check-in/hours 56, Training 2, Communications 30, Inventory counts 5, Pallets 3, Categories 3, Shopping/consumption 2, Reports 8, Uploads 10, Misc 6.

## 6. Pages to copy as templates

**(a) List / search**
- `C:/Users/maryw/Documents/Pelican/chsPetPantry/personSearch.php`: GET filter form with remembered values, `sanitize()` and `valueConstrainedTo()` checks on enum values, a results table and a "no results" message. It uses the Tailwind variant (`$tailwind_mode`). This is the best starting point for UC-02.
- `C:/Users/maryw/Documents/Pelican/chsPetPantry/viewItemCategories.php` (and `viewAuditUsers.php`): a page guard, a closure that draws one `.report-table` per status, and a Modify button on each row. Use it for admin and lookup lists (species, breed, size band, clinic, site).
- `inventory.php`: in-page jQuery filtering over a table.

**(b) Create / edit form**
- `C:/Users/maryw/Documents/Pelican/chsPetPantry/viewAddItemCategory.php`: form posts to itself, collects `$errors[]`, validates, reactivates a soft-deleted record, then redirects.
- `C:/Users/maryw/Documents/Pelican/chsPetPantry/viewModifyItemCategory.php` and `viewModifyUser.php`: load by `?id=`, with save, activate, deactivate and delete buttons. `createUser.php` shows uniqueness checks and a role dropdown limited by access level.
- `viewAddPallet.php`: a header record with repeating child lines. Use it for `stock_receipt` and lines, and for UC-06 `distribution` with `distribution_line`.

**(c) Report / export**
- `C:/Users/maryw/Documents/Pelican/chsPetPantry/generateReport.php`: filters, Chart.js, client-side XLSX (SheetJS) and jsPDF.
- Paired with `C:/Users/maryw/Documents/Pelican/chsPetPantry/processInventoryReport.php`: POST handler that runs a prepared SELECT and returns CSV (`fputcsv`) or XLSX (PhpSpreadsheet from `vendor/`) as a download. Exports should go through this server-side path so an `audit_log` Export row can be written.
- `viewWeeklyReport.php`: week picker plus a jsPDF autotable PDF.

## 7. Pitfalls to plan for

- PHP inside HTML comments still runs. index.php currently depends on `dbapplications`, `dbevents` and `dbmessages` this way.
- The header's page gate allows any page it doesn't list, and does nothing for visitors who aren't logged in. These pages have no page-level guard of their own: `viewManagePallets`, `viewAddPallet`, `viewUpdateInventory`, `viewWeeklyReport`, `viewAuditUsers`, `getVolunteers` and `clockOut`. New pages need an explicit role check before any POST handling.
- Includes point at files that don't exist: `dbTrainings`, `dbServices`, `dbAnimals`, `dbConnect`. `report.php` queries `dbanimals`, which isn't in the database. All of these pages are already broken.
- Secrets are hard-coded in files: production database credentials in `database/dbinfo.php`, the encryption key in `emailEncryption.php`, and the root password in `insertAdmin.php`.
- The legacy dump has typos and odd names: `dbcomsumption` (code uses both spellings), and `discussion_replies` (code uses `dbdiscussionreplies`).
- Some PFPMS features have no legacy page at all and must be built new:
  - participant register, edit and view (UC-03/04)
  - pet register and edit (UC-05)
  - Record Distribution (UC-06)
  - distribution history (UC-07)
  - SNV referral (UC-08) and the clinic-facing page (US-22)
  - participant erasure (UC-09)
  - Delete Pet (UC-10)
  - imports (UC-12)
  - participant and pet/SNV reports (UC-15/16)
  - admin pages for the lookup and settings tables (site, species, breed, size_band, clinic, system_setting, intake_question, language, policy_document)
  - an `audit_log` viewer
