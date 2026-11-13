# Automated tests (SRS Section 7)

PHPUnit tests that run the app's real code. They don't use copies of it.

| Suite | What it covers | Needs MySQL? |
|---|---|---|
| `Unit` | Participant ID format PP00001 (FR-074), 30-minute session timeout (NFR-SEC-005) | No |
| `Integration` | DND in the status report (FR-049), deletion audit (NFR-SEC-007), search by PP ID (FR-008), the duplicate rule (FR-018) | Yes |

GitHub runs both suites on every pull request into `dev` or `main` (`.github/workflows/tests.yml`). A red X on a PR means a test failed.

## Run them on your laptop

You need PHP 8.2 or newer and Composer. From the repository root:

```bash
composer --working-dir=tests install
```

```bash
tests/vendor/bin/phpunit -c tests/phpunit.xml
```

The Integration suite **drops and rebuilds** a database called `foodpantrydb_test` from `sql/foodpantrydb.sql` on every run. It refuses to run against any database whose name doesn't end in `_test`, so your real `foodpantrydb` is never touched. By default it connects to `127.0.0.1` as `root` with no password, which is the XAMPP default. To use something else:

```bash
PFPMS_DB_USER=me PFPMS_DB_PASS=secret tests/vendor/bin/phpunit -c tests/phpunit.xml
```

If MySQL isn't running, the Integration tests are skipped and the Unit tests still run.

To run only one suite, add `--testsuite Unit` or `--testsuite Integration`.

## Adding a test

- Put it in `tests/Unit` (no database) or `tests/Integration` (extends `DatabaseTestCase`).
- Name the file and class `SomethingTest`, and give the use case and requirement ID in the class comment so it traces back to the SRS (Section 7.2).
- `DatabaseTestCase::participant([...])` inserts a participant with sensible defaults and returns its ID.
- The tests build their database from `sql/foodpantrydb.sql`. When you add a migration, add the same change to that file too, as the team already does.
