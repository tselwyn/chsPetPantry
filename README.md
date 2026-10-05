# CHS Pet Pantry: Pet Food Pantry Management System (PFPMS)

A web application for running a pet food pantry. It keeps records of participants (pet-owning households) and their pets. It records food distributions against allotments and a frequency rule, keeps an inventory ledger, and manages spay/neuter referrals with partner clinics. It also covers auditing, imports and reports. Registered tablets can record distributions offline.

**Status:** being rebuilt in phases from an inherited codebase. Where things stand, and what comes next:

- The plan: [`docs/PFPMS_Implementation_Plan.md`](docs/PFPMS_Implementation_Plan.md).
- The analysis behind it: [`docs/design/`](docs/design/).
- The database design:
  - [`docs/PFPMS_schema_v2.sql`](docs/PFPMS_schema_v2.sql) (v2.0.1);
  - [`docs/PFPMS_schema_v2_notes.md`](docs/PFPMS_schema_v2_notes.md);
  - [`docs/PFPMS_schema_v2_1_changes.md`](docs/PFPMS_schema_v2_1_changes.md).

## XAMPP quick start (Windows)

1. **Clone into htdocs:** `cd C:\xampp\htdocs` then `git clone https://github.com/tselwyn/chsPetPantry.git`. Any folder name works; use one without spaces.
2. **Start Apache and MySQL** in the XAMPP Control Panel.
3. **Enable the PHP extensions:** in `C:\xampp\php\php.ini`, remove the `;` from `extension=gd`, `extension=intl` and `extension=zip`, then restart Apache. The setup script names any line still missing.
4. **Run the setup** from the repo folder: `C:\xampp\php\php.exe bin/setup.php`. It:
   - installs Composer's packages;
   - writes `config/config.php` (MySQL user `root`, no password) with a new encryption key;
   - creates the `pfpms_dev` database, runs the migrations and loads the development seeds;
   - creates an Administrator `admin` and prints a one-time temporary password.

   It is safe to run again; finished steps are skipped.
5. **Open http://localhost/&lt;folder-name&gt;/** (e.g. http://localhost/chsPetPantry/) and sign in as `admin` with the temporary password. You then choose your own.

The root `.htaccess` sends every request into `public/`, so nothing else in the repo (config, `.git`, `storage`, `src`, `vendor`) can be opened from the browser. If Apache's `mod_rewrite` is off, the whole folder answers 403. "Local setup" below is the manual equivalent, and covers other setups.

## Repository layout

| Path | What it is |
|---|---|
| `public/` | The only web-reachable folder; SiteGround's `public_html`. One PHP file per page, plus `assets/` and the tablets' Station app in `station/`. |
| `src/` | Application code (`Pfpms\` namespace): database, auth, security, audit, views. |
| `templates/` | Page templates and layouts. |
| `migrations/` | Versioned schema changes, applied by `bin/migrate.php`. |
| `seeds/` | `reference/` holds client-reviewed data; `dev/` holds synthetic data for development only. |
| `bin/` | Command-line tools: migrate, schema check, seed, create an admin, generate a key. |
| `config/` | `config.example.php` and `config.test.example.php`. Your real `config.php` is never committed. |
| `storage/` | Runtime files: sessions, logs, dev mail, encrypted uploads. Never web-reachable. |
| `tests/` | PHPUnit tests (unit, integration, contract), the Station's JavaScript tests (`js/`) and the fixtures both share. |
| `legacy/` | Reference-only code from the inherited app. Not runnable, never deployed, removed by Phase 7. |

## Requirements

- PHP 8.2 or later, with `pdo_mysql`, `openssl`, `mbstring` and `json`. Also `gd`, `zip` and `intl` (Composer needs these).
- MariaDB 10.4 or later, or MySQL 8.0 or later. Production runs on SiteGround's MySQL 8.4. The schema is written to load unchanged on both engines.
- Composer 2, for PHPMailer, PhpSpreadsheet and PHPUnit.

## Local setup (XAMPP or any PHP + MySQL)

1. **Create a database** (for example `pfpms_dev`) and an account that has all privileges on it.
2. **Create your config:** `cp config/config.example.php config/config.php`. Fill in the database details, then generate an encryption key with `php bin/generate-key.php` and put it under `crypto.keys`.
3. **Install dependencies:** `composer install`.
4. **Create the schema:** `php bin/migrate.php`. Add `--with-optional` to install the development-only immutability triggers where the server allows them.
5. **Load seeds:** `php bin/seed.php --dev` loads the reference data plus two development sites and a draft confidentiality agreement.
6. **Create the first Administrator:** `php bin/create-admin.php --username=you --email=you@example.org --first=First --last=Last`. It prints a one-time temporary password, which you replace at first sign-in.
7. **Serve `public/`:**
   - Quickest: `php -S localhost:8088 -t public`, then open http://localhost:8088.
   - With XAMPP Apache: clone into `htdocs` and open http://localhost/<folder>/. The root `.htaccess` rewrites into `public/`. A virtual host whose DocumentRoot is `<repo>/public` also works.

   A `*.localhost` host name counts as a secure context, which the offline Station needs later. Set `app.base_url` to match the address you use.
8. **Check the schema:** `php bin/schema-check.php` checks table and key counts, collations and core behaviours on your engine.

In `dev`, emailed links such as password resets are written to `storage/mail/*.eml` rather than sent.

## Scheduled jobs

`php bin/cron.php all` runs every job; `php bin/cron.php list` shows them. The jobs are:
- retry queued email;
- time out abandoned sessions;
- purge old rate-limit counters.

Schedule it every 30 minutes, the most often SiteGround's fair-use policy allows. Time-critical mail such as password resets is sent immediately, so cron only retries and tidies up.

## Tests

1. Copy `config/config.test.example.php` to `config/config.test.php` and point it at a database whose name ends in `_test`. The suite empties that database and rebuilds it on every run.
2. Run the tests with `composer test` (PHPUnit). On a machine without Composer, `php tests/run.php [filter]` runs the same tests.
3. Run static analysis with `composer stan` (PHPStan level 5), and the SQL guard with `composer guard`.
4. Test on every engine: `docker compose -f tools/docker-compose.db.yml up -d` starts MariaDB 10.4, MySQL 8.4 and Percona 8.4 locally, on ports 3310, 3384 and 3385.

CI (`.github/workflows/ci.yml`) runs on every push and pull request:
- `bin/ci-guard.php`, the SQL portability, immutability and hygiene guard from plan §8;
- PHP lint, PHPStan and a scan of the working tree for secrets;
- then, for PHP 8.2 and 8.3 against each of MariaDB 10.4, MySQL 8.4 and Percona 8.4: migrate twice, check the schema, load the seeds twice, and run the suite;
- and, on its own, the Station's JavaScript tests on Node 24 (`npm run test:js`, nothing to install).

## Station development

The Station is the offline app the pantry's tablets run, in `public/station/` (design: [`docs/design/50-design-station.md`](docs/design/50-design-station.md)).

- **Dev server:** `php -S 127.0.0.1:8088 -t public` (or the `pfpms-dev` launch config), then open http://localhost:8088/station/. Always use `localhost`, never `127.0.0.1`: the browser treats them as different origins. `app.base_url` must be exactly `http://localhost:8088`.
- **Trying it in a normal browser tab:** set `station.dev_relax_install = true` (dev and test only; the bootstrap refuses it elsewhere). A browser tab can then register, and the Station shows the red "Development mode: install checks relaxed." banner.
- **JavaScript tests:** `node --test --test-timeout=30000 "tests/js/**/*.test.js"` or `npm run test:js`. They run on plain Node 24 with `node:test`; there is nothing to install. Keep the timeout: without it a test that never settles hangs the run instead of failing.
- **After changing a Station file** nothing else is needed: the build name (`DeviceStatus::currentBuild()`) is a hash of the Station's files, its shell, headers and worker, so it changes, and tablets update at the next quiet lock screen.
- **Icons:** `php bin/station-icons.php` draws the four PNGs in `public/station/icons/` from the colour tokens in `app.css`. Run it only when those tokens change, and commit the PNGs.
- **Hosting checks:** `php bin/station-smoke.php <base URL>` against Apache (a local XAMPP alias, staging, production). `php -S` ignores `.htaccess`, so the caching and `nosniff` checks of its Station step fail or warn there by design.
- **Service workers** do not run in the desktop app's browser pane. Installing, offline reloads, Repair, updates and the kill switch (`station.sw_kill`) are checked in a real Chrome profile (design §11.4, Part B).

## Security notes

- Never commit `config/config.php`, database dumps, logs or anything in `storage/`; the `.gitignore` covers these.
- Every page starts with the bootstrap and then `Page::start()`, which checks the session, forced password change, policy acceptance, capability and site before any input is read. A contract test enforces this.
- Roles and their capabilities are defined in one file, `src/Auth/capabilities.php`.
- Report security problems privately to the project lead. Do not open a public issue.

## History and authors

The authorship history below comes from the inherited codebase and is kept as the GPL requires.

In autumn 2026 the CCDA Food Pantry application was rebuilt, following the PFPMS design documents, into the Pet Food Pantry Management System. The dead volunteer, event and messaging features were removed. The CCDA food-inventory pages were quarantined as reference in `legacy/`. The application was then rebuilt on a new database schema with a new shared core.

The ODHS Medicine Tracker is based on an old open source project named "Homebase". [Homebase](https://a.link.will.go.here/) was originally developed for the Ronald McDonald Houses in Maine and Rhode Island by Oliver Radwan, Maxwell Palmer, Nolan McNair, Taylor Talmage, and Allen Tucker.

Modifications to the original Homebase code were made by the Fall 2022 semester's group of students. That team consisted of Jeremy Buechler, Rebecca Daniel, Luke Gentry, Christopher Herriott, Ryan Persinger, and Jennifer Wells.

A major overhaul to the existing system took place during the Spring 2023 semester, throwing out and restructuring many of the existing database tables. Very little original Homebase code remains. This team consisted of Lauren Knight, Zack Burnley, Matt Nguyen, Rishi Shankar, Alip Yalikun, and Tamra Arant. Every page and feature of the app was changed by this team.

The Gwyneth's Gifts VMS code was modified in the Fall of 2023, revamping the code into the present ODHS Medicine Tracker code. Many of the existing database tables were reused, and many other tables were added. Some portions of the software's functionality were reused from the Gwyneth's Gifts VMS code. Other functions were created to fill the needs of the ODHS Medicine Tracker. The team that made these modifications and changes consisted of Garrett Moore, Artis Hart, Riley Tugeau, Julia Barnes, Ryan Warren, and Collin Rugless.

The ODHS Medicine Tracker code was modified in the Fall of 2024, changing the code to the present Step VA Volunteer Management System code. Many existing database tables were reused or renamed, and some others were added. Some files and portions of the software's functionality were reused from ODHS Medicine Tracker, while other functions were created to fill the needs of Step VA Volunteer Management. The team which made changes and new addtions consisted of Ava Donley, Thomas Held, Madison McCarty, Noah Stafford, Jayden Wynes, Gary Young, and Imaad Qureshi.

In Spring 2025, the Step VA Volunteer Management code was adapted to develop the Fredericksburg SCPA Volunteer Management Web Application. Numerous existing database tables were retained with modifications or renamed, while new tables were introduced as needed. Certain files and functionalities from the original system were integrated, while additional features were designed specifically for the Fredericksburg SCPA Volunteer Management system. The team responsible for these updates and enhancements included Yalda Alemy, Luke Blair, Madison Van Buren, Sean Foley, Luke Gibson, Aiden Meyer, and Israel Ortiz.

During the spring of 2026, the Whiskey Valor Event Management System served as the foundation for the new Catholic Charities Diocese of Arlington (CCDA) Food Pantry Inventory Management Web Application. The transition involved repurposing and renaming several original database tables while introducing new ones to meet specific inventory management requirements. While the development team integrated various core files and functions from the initial system, they also built custom features tailored specifically to the needs of the CCDA food pantry. The team who contributed to this consisted of Meredith Alty, Aiden Thompson, Robert Burton, Allison Consuegra, Eron Hardin, and Ayoub Oulmi.

Spring 2026 CCDA Food Pantry team: Meredith Alty, Aiden Thompson, Robert Burton, Allison Consuegra, Eron Hardin, Ayoub Oulmi.

## License

GPL-3.0; see [`LICENSE.txt`](LICENSE.txt). The Homebase copyright notices in source files are kept.
