# CHS Pet Pantry: handoff guide

For Culpeper Humane Society and whoever maintains the app after the UMW CPSC 430 team (Fall 2026).

> **Draft.** Fill in the `TODO` items before handoff on Dec 9.

---

## 1. The basics

| | |
|---|---|
| Live site | `https://jenniferp248.sg-host.com` (TODO: update if CHS moves to its own domain) |
| Hosting | SiteGround. TODO: who owns and pays for the account after Dec 9 |
| Code | GitHub, `tselwyn/chsPetPantry`. TODO: transfer to a CHS-owned GitHub account, or add a CHS admin |
| Logins | One shared **Administrator** login and one shared **Volunteer** login (SRS 2.6) |
| CHS contacts | Angie Mills and Debbie Siday |
| Built by | Rachelle Keister, Tyler Selwyn, Abdulaziz Heji |

How to use the app day to day is in the Volunteer and Admin guides. This document covers keeping it running.

---

## 2. Passwords

**When someone who knows a password leaves CHS, change it.** Because logins are shared, this is the only way to cut off their access (SRS 6.2).

**Change the Volunteer password (Admin):**
1. Log in as the Administrator.
2. Open **Manage Users** on the dashboard.
3. Click **Modify** next to the Volunteer account, then **Reset Password**, then **Reset Password** again.
4. The page shows a new 10-character password. Give it to the volunteers who still need it.

**Change the Administrator password:** log in as the Administrator, open the account menu (top right), and choose **Change Password**.

**Forgot the Administrator password:** **Forgot password?** on the login page emails a reset link, if email sending is set up on the server (TODO: confirm before handoff). Otherwise whoever manages the SiteGround account can reset it in phpMyAdmin.

**Database password:** the password the app uses to reach its database lives only on the server, in `database/dbinfo.php`. It is not in GitHub. To change it:
1. SiteGround **Site Tools > Site > MySQL**: change the database user's password.
2. **Site Tools > Site > File Manager**: open `public_html/database/dbinfo.php` and put the new password in.
3. Open the site and log in to check it works. If you see "Unable to connect to the database," the two don't match.

---

## 3. Backups

SiteGround makes automatic backups of the site and database. TODO: confirm how many days the CHS plan keeps.

- **Site Tools > Security > Backups** lists them and can restore files, the database, or both.
- **Make a manual backup there before anything big:** a deploy, the legacy import, or a database change.
- To undo a bad change, restore the most recent backup from before it. Anything entered after that backup is lost, so check what's at stake first.

---

## 4. Deploying changes

Code changes go from GitHub to SiteGround automatically.

1. Changes are made on a branch and merged into `dev` through a pull request. Automated tests run on every pull request; don't merge one with a red X.
2. When `dev` is ready, merge it into `main`. **Every push to `main` deploys to the live site within a couple of minutes.** You can also redeploy any time from GitHub **Actions > Deploy to SiteGround > Run workflow**.
3. The deploy never touches `database/dbinfo.php` on the server, and doesn't upload SQL dumps, docs, tests or logs.

The deploy uses these GitHub repository secrets: `SG_SSH_KEY`, `SG_KNOWN_HOSTS`, `SG_USER`, `SG_HOST`, `SG_PATH`. If SiteGround's SSH key changes, update them under GitHub **Settings > Secrets and variables > Actions**.

### Database changes

The app creates the newer tables and columns itself the first time they're needed, so a deploy normally needs nothing else:

| Change | Created automatically when |
|---|---|
| `dbparticipantaudit` (deletion audit) | An admin deletes a participant |
| `dbpets.age_recorded_date` (ages count up) | A pet is added or edited |

The matching scripts are in `sql/migrations/` if you'd rather run them in phpMyAdmin first. `sql/foodpantrydb.sql` is the full schema for a fresh install.

---

## 5. For developers

**Run it locally:** XAMPP (PHP 8.2+ and MariaDB). Import `sql/foodpantrydb.sql` in phpMyAdmin and open `http://localhost/chsPetPantry/`. On localhost the app uses database, user and password `foodpantrydb`. If yours differ, copy `database/dbconfig.example.php` to `database/dbconfig.php`.

**Tests:** see `tests/README.md`.

```bash
composer --working-dir=tests install
```

```bash
tests/vendor/bin/phpunit -c tests/phpunit.xml
```

**Where things are:**

| What | Files |
|---|---|
| Participants | `participantSearch.php`, `participantRegister.php`, `participantEdit.php`, `database/dbParticipants.php` |
| Pets | `registerPet.php`, `petEdit.php`, `petDelete.php`, `petFormFields.php`, `include/pet-form.php`, `database/dbPets.php`, `domain/Pet.php` |
| Distributions | `distributionRecord.php`, `distributionHistory.php`, `database/dbParticipantDistributions.php` |
| Removal | `deleteParticipantPet.php` |
| Reports and food cost | `reports.php`, `foodReceived.php`, `database/dbFoodReceived.php` |
| Users | `viewAuditUsers.php` (Manage Users), `viewModifyUser.php`, `createUser.php`, `resetPassword.php` |
| Login, sign-out after 30 min | `login.php`, `include/sessionTimeout.php`, `sessionKeepAlive.php`, `js/pantryGuards.js` |
| Menu and dashboard | `header.php`, `index.php` |

**Old CCDA pages:** the app started as a food-pantry inventory and volunteer system for another client. About 145 of those pages are still in the repository, but each starts with a line that makes it show "Page not found" when opened (`include/retiredPage.php`). Files that live pages still include as code keep working. To bring one back, delete its first line and add it to `LIVE_PAGES` in `tests/Unit/RetiredPagesTest.php`.

---

## 6. Known limitations

TODO: finalize after CHS testing (Dec 1–5).

- **Shared logins.** The app can't tell which person made a change, only which login. Distributions record a typed "Processed By" name to make up for it.
- **Pet age** is entered in years and counts up automatically from the date it was entered. There is no date of birth (SRS FR-077).
- **Not built:** address lookup (FR-075) and automatic participant status (FR-076), pending CHS decisions.
- TODO: legacy import (FR-045 to FR-047): built / not built.
- TODO: email at registration: optional or required, per CHS.
