# CLAUDE.md: Tyler's context for the CHS Pet Pantry repo

## Project
- UMW CPSC 430 (Software Engineering), Prof. Jennifer Polack. Graded team project.
- Client: Culpeper Humane Society (CHS) Pet Pantry. System name: PFPMS.
- Team: Tyler Selwyn (repo owner, github.com/tselwyn), Andrew Sanger, Rachelle, Abdulaziz.
- Local environment: Windows, XAMPP 8.2.12 at C:\xampp (PHP CLI at C:\xampp\php\php.exe, MySQL via XAMPP), Git Bash, VS Code. Production: SiteGround.
- Tracking: Jira (umw-humane-2026.atlassian.net, project KAN) and Confluence.

## Branches
- `main` = Andrew's full rebuild (`Pfpms\` namespace, migrations, tests, offline Station app; the old starter app is in `legacy/` for reference only). Stable demo/deploy version; only receives `dev` at sprint end.
- `dev` = integration branch, recreated from the new `main`. Feature branches PR into `dev`.
- Tyler's work branch: `Selwyn`, branched from `dev`. Never push directly to `dev` or `main`; open a PR into `dev`.
- All old branches built on the starter app were deleted. Don't use anything from them.

## Local setup
Follow README.md "Local setup". Notes for this machine:
- XAMPP doesn't ship with Composer. Install it if `composer` isn't found.
- Enable `gd`, `zip`, and `intl` in C:\xampp\php\php.ini if they're off.
- Dev database: `pfpms_dev`. Test database must be a separate one whose name ends in `_test` (config/config.test.php); the test run wipes and rebuilds it.
- Serve with `php -S localhost:8088 -t public`.

## Tyler's task: Sprint 1, participant search (UC-02)
- Sprint 1 (two weeks) covers participant management: search, register, view/update. Tyler owns **search**.
- Build `public/participant_search.php` to the spec under "P3: Participants" > `participant_search` in `docs/PFPMS_Implementation_Plan.md`.
- Use case: UC-02 in `docs/PFPMS_Use_Case_Specifications.docx`. Stories in `docs/PFPMS_Jira_Backlog_Import.csv`: US-04 (today's check-ins first during an open event), US-05 (preferred name), US-06 (accent-insensitive).
- Already in place; use them, don't recreate:
  - Nav entry "Find a participant" → `participant_search.php` (src/View/nav.php)
  - Capability `participant.search` (and `participant.search_include_deleted` for admins) in src/Auth/capabilities.php
  - Setting `search_max_results` (default 100) in src/Reference/settings_registry.php
  - Tables: `participant` (migrations/0001, altered in 0006), `pet`, `distribution`, `distribution_event`
  - Site handling: `Page::start([... 'site' => true])` gives the current site; results must be scoped to it.
- Order of work: core search first (free text over name, phone, and participant code; results table with name, participant code, status, pet count, last distribution date; capped at search_max_results with a warning). Then US-05 and US-06. US-04 last, since it depends on events and check-ins.
- Participant view page doesn't exist yet. Link results to `participant_view.php?id=...` anyway.
- Out of scope: offline Station cached search, register/view/edit pages, QR card scan.

## Conventions
- Follow Andrew's patterns exactly. Reference page: `public/inventory_catalogue.php` with its repository in `src/Inventory/` and template in `templates/pages/inventory/`. New code goes in `src/Participant/` and `templates/pages/participant/`.
- Every page: bootstrap first, then `Page::start()` as the first statement. tests/Contract/PageContractTest.php enforces this.
- Prepared statements only. Validate input with `Validator`.
- Add integration tests in `tests/Integration/` in the existing style.
- Before calling it done, all of these must pass: `composer guard`, `composer stan`, and the tests (`vendor/bin/phpunit`, or `php tests/run.php` without PHPUnit).
- Small, clear commits. Contribution is graded individually, so the commits need to be Tyler's.

## Open items (ask Tyler, don't assume)
- Date of the sprint meeting and what has to be shown (working on localhost vs. merged PR).
- Whether Andrew is building any participant pages in parallel. If so, coordinate before touching shared files (nav, capabilities, settings).

## Working with Tyler
- Direct and brief. Act first, explain only what matters.
- Push back if something is a bad idea, but if he says do it anyway, do it.
