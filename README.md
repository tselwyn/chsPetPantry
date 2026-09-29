# CHS Pet Pantry: Pet Food Pantry Management System (PFPMS)

A web application for running a pet food pantry. It keeps records of participants (pet-owning households) and their pets. It records food distributions against allotments and a frequency rule, keeps an inventory ledger, and manages spay/neuter referrals with partner clinics. It also covers auditing, imports and reports. Registered tablets can record distributions offline.

**Status:** being rebuilt in phases from an inherited codebase. Where things stand, and what comes next:

- The plan: [`docs/PFPMS_Implementation_Plan.md`](docs/PFPMS_Implementation_Plan.md).
- The analysis behind it: [`docs/design/`](docs/design/).
- The database design:
  - [`docs/PFPMS_schema_v2.sql`](docs/PFPMS_schema_v2.sql) (v2.0.1);
  - [`docs/PFPMS_schema_v2_notes.md`](docs/PFPMS_schema_v2_notes.md);
  - [`docs/PFPMS_schema_v2_1_changes.md`](docs/PFPMS_schema_v2_1_changes.md).

## Repository layout

| Path | What it is |
|---|---|
| `public/` | The only web-reachable folder; SiteGround's `public_html`. One PHP file per page, plus `assets/`. |
| `src/` | Application code (`Pfpms\` namespace): database, auth, security, audit, views. |
| `templates/` | Page templates and layouts. |
| `migrations/` | Versioned schema changes, applied by `bin/migrate.php`. |
| `seeds/` | `reference/` holds client-reviewed data; `dev/` holds synthetic data for development only. |
| `bin/` | Command-line tools: migrate, schema check, seed, create an admin, generate a key. |
| `config/` | `config.example.php` and `config.test.example.php`. Your real `config.php` is never committed. |
| `storage/` | Runtime files: sessions, logs, dev mail, encrypted uploads. Never web-reachable. |
| `tests/` | PHPUnit tests (unit, integration, contract). |
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
   - With XAMPP Apache: add a virtual host whose DocumentRoot is `<repo>/public`.

   A `*.localhost` host name counts as a secure context, which the offline Station needs later. Set `app.base_url` to match the address you use.
8. **Check the schema:** `php bin/schema-check.php` checks table and key counts, collations and core behaviours on your engine.

In `dev`, emailed links such as password resets are written to `storage/mail/*.eml` rather than sent.

## Tests

1. Copy `config/config.test.example.php` to `config/config.test.php` and point it at a database whose name ends in `_test`. The suite empties that database and rebuilds it on every run.
2. Run the tests:
   - with Composer set up: `vendor/bin/phpunit`;
   - without PHPUnit: `php tests/run.php [filter]` runs the same tests.

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
